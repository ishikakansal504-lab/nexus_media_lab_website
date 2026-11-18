<?php
// Customer only: cancel your own order, but only while it is still "placed" and unpaid.
require __DIR__ . '/db.php';
require_post();
$me = require_customer();
$pdo = shop_db();
$id = (int)(body()['id'] ?? 0);
$st = $pdo->prepare('SELECT id, status, payment_status FROM orders WHERE id = ? AND user_id = ?'); $st->execute([$id, $me['id']]);
$o = $st->fetch();
if (!$o) fail('Order not found.', 404);
if ($o['status'] !== 'placed' || $o['payment_status'] !== 'pending') fail('This order can no longer be cancelled here. Please contact us.');
$pdo->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ?")->execute([$id]);
out(['ok' => true]);
