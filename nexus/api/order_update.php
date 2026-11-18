<?php
// Admin only: change the order status and/or the payment status.
require __DIR__ . '/db.php';
require __DIR__ . '/notify.php';
require_post();
require_admin();
$pdo = shop_db();
$b = body();
$id = (int)($b['id'] ?? 0);
$st = $pdo->prepare('SELECT id, payment_status, is_demo FROM orders WHERE id = ?'); $st->execute([$id]);
$was = $st->fetch();
if (!$was) fail('Order not found.', 404);

$set = []; $args = [];
if (isset($b['status'])) {
    if (!in_array($b['status'], ['placed', 'confirmed', 'completed', 'cancelled'], true)) fail('Choose a valid order status.');
    $set[] = 'status = ?'; $args[] = $b['status'];
}
if (isset($b['payment_status'])) {
    if (!in_array($b['payment_status'], ['pending', 'paid', 'refunded'], true)) fail('Choose a valid payment status.');
    $set[] = 'payment_status = ?'; $args[] = $b['payment_status'];
}
if (!$set) fail('Nothing to change.');
$args[] = $id;
$pdo->prepare('UPDATE orders SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($args);
$nowPaid = ($b['payment_status'] ?? '') === 'paid' && $was['payment_status'] !== 'paid' && !(int)$was['is_demo'];
if ($nowPaid) out_then(['ok' => true], function () use ($pdo, $id) { notify_payment_received($pdo, $id); });
out(['ok' => true]);
