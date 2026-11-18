<?php
// Order confirmation messages, sent AUTOMATICALLY when an order is placed and when its payment is confirmed.
//   Email    : sent over SMTP (for example Gmail with an App password).
//   WhatsApp : sent through the WhatsApp Business Cloud API (Meta). Needs a free Meta business account and an approved template.
// The details are typed on the admin Settings page (stored in the settings table); api/config.php gives the starting values.
// Every attempt is written to the "notifications" table so the admin can see what happened. A failure here never blocks an order.

if (!defined('WA_API_BASE')) define('WA_API_BASE', getenv('NEXUS_WA_BASE') ?: 'https://graph.facebook.com/v21.0');

function msg_cfg(PDO $pdo): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $s = all_settings($pdo);
    $pick = fn(string $k, $const) => (($s[$k] ?? '') !== '') ? $s[$k] : $const;
    return $cache = [
        'smtp_host' => (string)$pick('smtp_host', SMTP_HOST), 'smtp_port' => (int)$pick('smtp_port', SMTP_PORT), 'smtp_secure' => (string)$pick('smtp_secure', SMTP_SECURE),
        'smtp_user' => (string)$pick('smtp_user', SMTP_USER), 'smtp_pass' => (string)$pick('smtp_pass', SMTP_PASS), 'smtp_from' => (string)$pick('smtp_from', SMTP_FROM),
        'wa_token' => (string)($s['wa_token'] ?? ''), 'wa_phone_id' => (string)($s['wa_phone_id'] ?? ''), 'wa_template' => (string)($s['wa_template'] ?? ''), 'wa_lang' => (string)(($s['wa_lang'] ?? '') ?: 'en'),
    ];
}
function smtp_ready(array $c): bool { return $c['smtp_host'] !== ''; }
function wa_ready(array $c): bool { return $c['wa_token'] !== '' && $c['wa_phone_id'] !== '' && $c['wa_template'] !== ''; }

function smtp_send(array $c, string $to, string $subject, string $body): void {   // throws RuntimeException on any problem
    $from = $c['smtp_from'] !== '' ? $c['smtp_from'] : $c['smtp_user'];
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('The "send from" email address is not valid.');
    $to = str_replace(["\r", "\n"], '', $to); $subject = str_replace(["\r", "\n"], ' ', $subject);
    $ctx = stream_context_create(['ssl' => ['verify_peer' => SMTP_VERIFY, 'verify_peer_name' => SMTP_VERIFY]]);
    $fp = @stream_socket_client(($c['smtp_secure'] === 'ssl' ? 'ssl://' : 'tcp://') . $c['smtp_host'] . ':' . $c['smtp_port'], $en, $es, 8, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) throw new RuntimeException('Cannot connect to the mail server (' . trim($es) . ').');
    stream_set_timeout($fp, 10);
    $read = function () use ($fp): string {
        $r = '';
        while (($l = fgets($fp, 1024)) !== false) { $r .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; }
        return $r;
    };
    $cmd = function (string $x, array $ok) use ($fp, $read): string {
        if ($x !== '') fwrite($fp, $x . "\r\n");
        $r = $read();
        if (!in_array((int)substr($r, 0, 3), $ok, true)) throw new RuntimeException('Mail server said: ' . (trim($r) ?: 'no reply'));
        return $r;
    };
    try {
        $cmd('', [220]);
        $cmd('EHLO localhost', [250]);
        if ($c['smtp_secure'] === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('Secure connection (TLS) failed.');
            $cmd('EHLO localhost', [250]);
        }
        if ($c['smtp_user'] !== '') {
            $cmd('AUTH LOGIN', [334]); $cmd(base64_encode($c['smtp_user']), [334]); $cmd(base64_encode($c['smtp_pass']), [235]);
        }
        $cmd('MAIL FROM:<' . $from . '>', [250]);
        $cmd('RCPT TO:<' . $to . '>', [250, 251]);
        $cmd('DATA', [354]);
        $head = 'From: =?UTF-8?B?' . base64_encode(BUSINESS_NAME) . '?= <' . $from . ">\r\n" .
                'To: <' . $to . ">\r\n" .
                'Reply-To: <' . BUSINESS_EMAIL . ">\r\n" .
                'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n" .
                'Date: ' . date('r') . "\r\n" .
                'Message-ID: <' . bin2hex(random_bytes(8)) . '@nexus.local>' . "\r\n" .
                "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
        fwrite($fp, $head . chunk_split(base64_encode($body), 76, "\r\n") . ".\r\n");
        $cmd('', [250]);
        @fwrite($fp, "QUIT\r\n");
    } finally { @fclose($fp); }
}

// One WhatsApp template message. $params are the template's {{1}}, {{2}}... values. Throws RuntimeException on any problem.
function wa_send(array $c, string $phone10, array $params): void {
    $url = rtrim(WA_API_BASE, '/') . '/' . rawurlencode($c['wa_phone_id']) . '/messages';
    $payload = json_encode(['messaging_product' => 'whatsapp', 'to' => '91' . $phone10, 'type' => 'template',
        'template' => ['name' => $c['wa_template'], 'language' => ['code' => $c['wa_lang']],
                       'components' => [['type' => 'body', 'parameters' => array_map(fn($p) => ['type' => 'text', 'text' => $p], $params)]]]]);
    $hdr = ['Authorization: Bearer ' . $c['wa_token'], 'Content-Type: application/json'];
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_HTTPHEADER => $hdr, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 6]);
        $res = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
        if ($res === false) throw new RuntimeException('Cannot reach WhatsApp (' . $err . ').');
    } else {
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $hdr), 'content' => $payload, 'timeout' => 12, 'ignore_errors' => true]]);
        $res = @file_get_contents($url, false, $ctx);
        if ($res === false) throw new RuntimeException('Cannot reach WhatsApp. Check the internet connection.');
        $code = 0; foreach ($http_response_header ?? [] as $h) if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $code = (int)$m[1];
    }
    if ($code < 200 || $code >= 300) {
        $j = json_decode((string)$res, true);
        throw new RuntimeException('WhatsApp said: ' . ($j['error']['message'] ?? ('HTTP ' . $code)));
    }
}

function log_notification(PDO $pdo, ?int $orderId, string $channel, string $kind, string $to, string $subject, string $status, string $err): void {
    try {
        $pdo->prepare('INSERT INTO notifications (order_id, channel, kind, to_addr, subject, status, error) VALUES (?,?,?,?,?,?,?)')
            ->execute([$orderId, $channel, $kind, mb_substr_safe($to, 190), mb_substr_safe($subject, 200), $status, mb_substr_safe($err, 250)]);
    } catch (Throwable $e) { /* logging must never break the order */ }
}
// Sends one email and records the result. Returns 'sent', 'failed' or 'not_configured'. Never throws.
function send_notification(PDO $pdo, ?int $orderId, string $kind, string $to, string $subject, string $body, ?string &$error = null): string {
    $status = 'not_configured'; $err = '';
    try {
        $c = msg_cfg($pdo);
        if (smtp_ready($c)) {
            try { smtp_send($c, $to, $subject, $body); $status = 'sent'; }
            catch (Throwable $e) { $status = 'failed'; $err = $e->getMessage(); }
        } else { $err = 'Email is not set up yet (Admin > Settings).'; }
    } catch (Throwable $e) { $status = 'failed'; $err = $e->getMessage(); }
    $error = $err;
    log_notification($pdo, $orderId, 'email', $kind, $to, $subject, $status, $err);
    return $status;
}
// Sends one WhatsApp message and records the result. Never throws.
function send_whatsapp(PDO $pdo, ?int $orderId, string $kind, string $phone10, array $params, ?string &$error = null): string {
    $status = 'not_configured'; $err = '';
    try {
        $c = msg_cfg($pdo);
        if (wa_ready($c)) {
            try { wa_send($c, $phone10, $params); $status = 'sent'; }
            catch (Throwable $e) { $status = 'failed'; $err = $e->getMessage(); }
        } else { $err = 'WhatsApp is not set up yet (Admin > Settings).'; }
    } catch (Throwable $e) { $status = 'failed'; $err = $e->getMessage(); }
    $error = $err;
    log_notification($pdo, $orderId, 'whatsapp', $kind, '+91 ' . $phone10, 'Template ' . (msg_cfg($pdo)['wa_template'] ?: '-'), $status, $err);
    return $status;
}

function money_txt(float $n): string { return '₹' . number_format($n, floor($n) == $n ? 0 : 2); }

// The text of the email. $kind is 'placed' or 'paid'.
function order_email_text(PDO $pdo, int $orderId, string $kind): ?array {
    $st = $pdo->prepare('SELECT * FROM orders WHERE id = ?'); $st->execute([$orderId]); $o = $st->fetch();
    if (!$o) return null;
    $it = $pdo->prepare('SELECT name, unit, unit_price, qty FROM order_items WHERE order_id = ? ORDER BY id'); $it->execute([$orderId]); $items = $it->fetchAll();
    $no = order_no($orderId);
    $pay = PAY_METHODS[$o['payment_method']] ?? $o['payment_method'];
    $payLine = $o['payment_status'] === 'paid' ? 'Payment received' : 'Payment pending';
    $lines = ['Hello ' . $o['customer_name'] . ',', ''];
    $lines[] = $kind === 'paid' ? 'We have received your payment for order ' . $no . '. Thank you!' : 'Thank you for your order at ' . BUSINESS_NAME . '. We have received it.';
    $lines[] = '';
    $lines[] = 'Order number : ' . $no;
    $lines[] = 'Date         : ' . date('d M Y, h:i A', strtotime($o['created_at']));
    $lines[] = 'Payment      : ' . $pay . ' (' . $payLine . ')';
    if ($o['emi_months']) $lines[] = 'EMI          : ' . money_txt((float)$o['emi_monthly']) . ' a month for ' . $o['emi_months'] . ' months';
    if ($o['pay_ref'] !== '') $lines[] = 'Reference    : ' . $o['pay_ref'];
    $lines[] = ''; $lines[] = 'Services ordered';
    foreach ($items as $i) $lines[] = ' - ' . $i['name'] . ' x ' . $i['qty'] . '  =  ' . money_txt(rupees(paise($i['unit_price']) * (int)$i['qty']));
    $lines[] = '';
    $lines[] = 'Services : ' . money_txt((float)$o['subtotal']);
    if ((float)$o['interest'] > 0) $lines[] = 'EMI interest : ' . money_txt((float)$o['interest']);
    $lines[] = 'TOTAL    : ' . money_txt((float)$o['total']);
    $lines[] = ''; $lines[] = 'Your details';
    $lines[] = ' Name    : ' . $o['customer_name'];
    $lines[] = ' Phone   : +91 ' . $o['customer_phone'];
    $lines[] = ' Email   : ' . $o['customer_email'];
    $lines[] = ' Address : ' . trim($o['address_line'] . ', ' . $o['city'] . ', ' . $o['state'] . ' - ' . $o['pincode'], ', ');
    if ($o['note'] !== '') $lines[] = ' Note    : ' . $o['note'];
    $lines[] = '';
    $lines[] = $kind === 'paid' ? 'Our team will now start work and keep you updated.'
             : ($o['payment_status'] === 'paid' ? 'Our team will start work and keep you updated.' : 'We will confirm your payment shortly and then start work.');
    $lines[] = 'You can follow this order any time under "My orders" on our website.';
    $lines[] = ''; $lines[] = 'Questions? Reply to this email or call +91 ' . substr(BUSINESS_PHONE, 2, 5) . ' ' . substr(BUSINESS_PHONE, 7) . '.';
    $lines[] = BUSINESS_NAME;
    return ['to' => $o['customer_email'], 'phone' => $o['customer_phone'], 'pay_text' => $o['payment_status'] === 'paid' ? 'Payment received. Thank you!' : $pay . ' (payment pending)', 'no' => $no, 'name' => $o['customer_name'], 'total' => (float)$o['total'],
            'subject' => ($kind === 'paid' ? 'Payment received for order ' : 'Order confirmation ') . $no . ' | ' . BUSINESS_NAME,
            'body' => implode("\n", $lines)];
}

// New order: email to the customer, a copy to the admin, and a WhatsApp message to the customer.
function notify_order_placed(PDO $pdo, int $orderId): void {
    $m = order_email_text($pdo, $orderId, 'placed'); if (!$m) return;
    send_notification($pdo, $orderId, 'placed', $m['to'], $m['subject'], $m['body']);
    send_notification($pdo, $orderId, 'admin-copy', BUSINESS_EMAIL, 'New order ' . $m['no'] . ' from ' . $m['name'] . ' (' . money_txt($m['total']) . ')', $m['body']);
    send_whatsapp($pdo, $orderId, 'placed', $m['phone'], [$m['name'], $m['no'], money_txt($m['total']), $m['pay_text']]);
}
function notify_payment_received(PDO $pdo, int $orderId): void {
    $m = order_email_text($pdo, $orderId, 'paid'); if (!$m) return;
    send_notification($pdo, $orderId, 'paid', $m['to'], $m['subject'], $m['body']);
    send_whatsapp($pdo, $orderId, 'paid', $m['phone'], [$m['name'], $m['no'], money_txt($m['total']), 'Payment received. Thank you!']);
}

// Sends the JSON answer first and then runs $after, so the customer is not kept waiting for the mail server.
function out_then(array $data, callable $after): never {
    ignore_user_abort(true);
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    http_response_code(200);
    header('Content-Length: ' . strlen($json)); header('Connection: close');
    echo $json;
    while (ob_get_level() > 0) @ob_end_flush();
    flush();
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    try { $after(); } catch (Throwable $e) {}
    exit;
}
