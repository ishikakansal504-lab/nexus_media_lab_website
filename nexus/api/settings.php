<?php
// GET: payment settings. Customers need these at checkout; the admin edits them. Nothing secret is stored here.
require __DIR__ . '/db.php';
$me = require_login();
if ($me['role'] === 'employee') fail('Payment settings are for customers and the admin.', 403);
$pdo = shop_db();
$s = all_settings($pdo);
$out = ['ok' => true, 'payment' => payment_options($s)];
if ($me['role'] === 'admin') {
    // The raw values, so the settings form can be filled in.
    $out['form'] = ['pay_cod' => $s['pay_cod'] === '1', 'pay_emi' => $s['pay_emi'] === '1', 'pay_online' => $s['pay_online'] === '1', 'online_auto' => ($s['online_auto'] ?? '0') === '1',
                    'emi_rate' => (float)$s['emi_rate'], 'emi_months' => $s['emi_months'], 'emi_min' => (float)$s['emi_min'],
                    'upi_id' => $s['upi_id'], 'qr_note' => $s['qr_note'], 'has_qr' => $s['qr_file'] !== ''];
    require_once __DIR__ . '/notify.php';
    $c = msg_cfg($pdo);
    $out['email_ready'] = smtp_ready($c); $out['wa_ready'] = wa_ready($c); $out['business_email'] = BUSINESS_EMAIL; $out['business_phone'] = substr(BUSINESS_PHONE, 2);
    $out['form'] += ['smtp_host' => $c['smtp_host'], 'smtp_port' => $c['smtp_port'], 'smtp_secure' => $c['smtp_secure'], 'smtp_user' => $c['smtp_user'], 'smtp_from' => $c['smtp_from'],
                     'has_smtp_pass' => $c['smtp_pass'] !== '', 'wa_phone_id' => $c['wa_phone_id'], 'wa_template' => $c['wa_template'], 'wa_lang' => $c['wa_lang'], 'has_wa_token' => $c['wa_token'] !== ''];
    $out['messages'] = $pdo->query('SELECT id, order_id, channel, kind, to_addr, subject, status, error, created_at FROM notifications ORDER BY id DESC LIMIT 20')->fetchAll();
    $out['demo_orders'] = (int)$pdo->query('SELECT COUNT(*) FROM orders WHERE is_demo = 1')->fetchColumn();
}
out($out);
