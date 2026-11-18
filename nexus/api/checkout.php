<?php
// Customer only: turns the cart into an order. Prices always come from the database, never from the page.
require __DIR__ . '/db.php';
require __DIR__ . '/notify.php';
require_post();
$me = require_customer();
$pdo = shop_db();
$b = body();
$method = str_field($b, 'method');
$months = (int)($b['emi_months'] ?? 0);
$ref    = clean_line(str_field($b, 'pay_ref'), 40);
$note   = clean_line(str_field($b, 'note'), 300);

// The customer's contact and delivery details (all required).
$cName  = clean_line(str_field($b, 'name'), 80);
$cEmail = strtolower(str_field($b, 'email'));
$phone  = preg_replace('/[\s\-()]+/', '', str_field($b, 'phone'));
$addr   = clean_line(str_field($b, 'address'), 200);
$city   = clean_line(str_field($b, 'city'), 60);
$state  = clean_line(str_field($b, 'state'), 60);
$pin    = preg_replace('/\s+/', '', str_field($b, 'pincode'));
if ($e = check_name($cName)) fail('Full name: ' . $e);
if ($e = check_email($cEmail)) fail('Email: ' . $e);
if (!preg_match('/^(?:\+?91|0)?([6-9][0-9]{9})$/', $phone, $pm)) fail('Enter a valid 10-digit mobile number.');
$phone = $pm[1];
if (text_len($addr) < 5) fail('Enter your address (house / street / area).');
if (text_len($city) < 2) fail('Enter your city.');
if (text_len($state) < 2) fail('Enter your state.');
if (!preg_match('/^[1-9][0-9]{5}$/', $pin)) fail('Enter a valid 6-digit PIN code.');

$settings = all_settings($pdo);
$opt = payment_options($settings);
if (!isset(PAY_METHODS[$method]) || empty($opt[$method]['on'])) fail('Choose one of the available payment methods.');

try {
    $pdo->beginTransaction();
    $st = $pdo->prepare('SELECT c.service_id, c.qty, s.name, s.unit, s.price, s.cost, s.active
                         FROM cart_items c JOIN services s ON s.id = c.service_id WHERE c.user_id = ? FOR UPDATE');
    $st->execute([$me['id']]);
    $rows = $st->fetchAll();
    if (!$rows) { $pdo->rollBack(); fail('Your cart is empty.'); }

    $sub = 0; $cost = 0;
    foreach ($rows as $r) {
        if ((int)$r['active'] !== 1) { $pdo->rollBack(); fail('"' . $r['name'] . '" is no longer available. Remove it from the cart and try again.'); }
        $sub  += paise($r['price']) * (int)$r['qty'];
        $cost += paise($r['cost'])  * (int)$r['qty'];
    }

    $interest = 0; $emiMonthly = null; $emiMonths = null; $payStatus = 'pending';
    if ($method === 'emi') {
        if (!in_array($months, $opt['emi']['months'], true)) { $pdo->rollBack(); fail('Choose one of the EMI periods shown.'); }
        if ($sub < paise($opt['emi']['min_amount'])) { $pdo->rollBack(); fail('EMI is available for orders of ₹' . number_format($opt['emi']['min_amount']) . ' or more.'); }
        [$monthly, $totalPay] = emi_calc($sub, $months, $opt['emi']['rate']);
        $interest = $totalPay - $sub; $emiMonthly = rupees($monthly); $emiMonths = $months;
    } elseif ($method === 'online') {
        if (!preg_match('/^[A-Za-z0-9._\- ]{6,40}$/', $ref)) { $pdo->rollBack(); fail('After paying, type the transaction / UTR number (6 to 40 letters and numbers).'); }
        // Demonstration switch (Settings): treat the transaction number as proof of payment. Off by default.
        if (($settings['online_auto'] ?? '0') === '1') $payStatus = 'paid';
    }
    $total = $sub + $interest;

    $pdo->prepare('INSERT INTO orders (user_id, customer_name, customer_email, subtotal, interest, total, cost_total, payment_method, payment_status, emi_months, emi_monthly, pay_ref, note, customer_phone, address_line, city, state, pincode)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$me['id'], $cName, $cEmail, rupees($sub), rupees($interest), rupees($total), rupees($cost), $method, $payStatus, $emiMonths, $emiMonthly, $ref, $note, $phone, $addr, $city, $state, $pin]);
    $oid = (int)$pdo->lastInsertId();
    $ins = $pdo->prepare('INSERT INTO order_items (order_id, service_id, name, unit, unit_price, unit_cost, qty) VALUES (?,?,?,?,?,?,?)');
    foreach ($rows as $r) $ins->execute([$oid, $r['service_id'], $r['name'], $r['unit'], $r['price'], $r['cost'], $r['qty']]);
    $pdo->prepare('DELETE FROM cart_items WHERE user_id = ?')->execute([$me['id']]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('Could not place the order. Please try again.', 500);
}
out_then(['ok' => true, 'order_id' => $oid, 'order_no' => order_no($oid), 'total' => rupees($total), 'payment_status' => $payStatus, 'email_enabled' => smtp_ready(msg_cfg($pdo)), 'whatsapp_enabled' => wa_ready(msg_cfg($pdo))],
         function () use ($pdo, $oid) { notify_order_placed($pdo, $oid); });
