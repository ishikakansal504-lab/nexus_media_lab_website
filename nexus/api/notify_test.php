<?php
// Admin only: sends one test message so the admin can see at once whether email / WhatsApp is set up correctly.
require __DIR__ . '/db.php';
require __DIR__ . '/notify.php';
require_post();
require_admin();
$pdo = shop_db();
$b = body();
if (str_field($b, 'channel') === 'whatsapp') {
    $ph = preg_replace('/\D+/', '', str_field($b, 'phone'));
    if (preg_match('/^(?:91)?([6-9][0-9]{9})$/', $ph, $m)) $ph = $m[1]; else fail('Type a 10-digit mobile number to send the test to.');
    $st = send_whatsapp($pdo, null, 'test', $ph, ['Test', 'NML-TEST', '₹1', 'This is a test message'], $err);
    out(['ok' => $st === 'sent', 'status' => $st, 'error' => $st === 'sent' ? '' : $err]);
}
$st = send_notification($pdo, null, 'test', BUSINESS_EMAIL, 'Test message from ' . BUSINESS_NAME, "This is a test email from your " . BUSINESS_NAME . " website.\nIf you can read this, order confirmation emails are working.", $err);
out(['ok' => $st === 'sent', 'status' => $st, 'error' => $st === 'sent' ? '' : $err, 'to' => BUSINESS_EMAIL]);
