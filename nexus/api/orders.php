<?php
// GET: a customer sees their own earlier purchases; the admin sees every order (with filters, cost and profit).
require __DIR__ . '/db.php';
$me = require_login();
if ($me['role'] === 'employee') fail('Orders are for customers and the admin.', 403);
$pdo = shop_db();
$isAdmin = $me['role'] === 'admin';

function valid_day(string $s): bool { $d = DateTime::createFromFormat('Y-m-d', $s); return $d && $d->format('Y-m-d') === $s; }
$where = []; $args = [];
if (!$isAdmin) { $where[] = 'o.user_id = ?'; $args[] = $me['id']; }
$from = (string)($_GET['from'] ?? ''); $to = (string)($_GET['to'] ?? '');
if ($from !== '' && valid_day($from)) { $where[] = 'o.created_at >= ?'; $args[] = $from . ' 00:00:00'; }
if ($to !== '' && valid_day($to))     { $where[] = 'o.created_at < DATE_ADD(?, INTERVAL 1 DAY)'; $args[] = $to; }
foreach (['status' => ['placed','confirmed','completed','cancelled'], 'payment_status' => ['pending','paid','refunded'], 'payment_method' => array_keys(PAY_METHODS)] as $col => $allowed) {
    $v = (string)($_GET[$col] ?? '');
    if ($v !== '' && in_array($v, $allowed, true)) { $where[] = "o.$col = ?"; $args[] = $v; }
}
$oneId = (int)($_GET['id'] ?? 0);
if ($oneId > 0) { $where[] = 'o.id = ?'; $args[] = $oneId; }
$q = trim((string)($_GET['q'] ?? ''));
if ($isAdmin && $q !== '') {
    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
    $num = preg_match('/(\d+)\s*$/', $q, $m) ? (int)$m[1] : 0;
    $where[] = '(o.customer_name LIKE ? OR o.customer_email LIKE ? OR o.customer_phone LIKE ?' . ($num ? ' OR o.id = ?' : '') . ')';
    array_push($args, $like, $like, $like); if ($num) $args[] = $num;
}
$sql = 'SELECT o.* FROM orders o' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY o.id DESC LIMIT 500';
$st = $pdo->prepare($sql); $st->execute($args);
$orders = $st->fetchAll();

$items = [];
if ($orders) {
    $ids = array_map(fn($o) => (int)$o['id'], $orders);
    $it = $pdo->prepare('SELECT * FROM order_items WHERE order_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id');
    $it->execute($ids);
    foreach ($it->fetchAll() as $r) {
        $items[(int)$r['order_id']][] = ['name' => $r['name'], 'unit' => $r['unit'], 'unit_price' => (float)$r['unit_price'], 'qty' => (int)$r['qty'],
                                         'line_total' => rupees(paise($r['unit_price']) * (int)$r['qty'])];
    }
}
$out = []; $spent = 0; $n = 0;
foreach ($orders as $o) {
    $row = ['id' => (int)$o['id'], 'order_no' => order_no((int)$o['id']), 'customer_name' => $o['customer_name'], 'customer_email' => $o['customer_email'],
            'subtotal' => (float)$o['subtotal'], 'interest' => (float)$o['interest'], 'total' => (float)$o['total'],
            'payment_method' => $o['payment_method'], 'payment_label' => PAY_METHODS[$o['payment_method']], 'payment_status' => $o['payment_status'],
            'status' => $o['status'], 'emi_months' => $o['emi_months'] === null ? null : (int)$o['emi_months'],
            'emi_monthly' => $o['emi_monthly'] === null ? null : (float)$o['emi_monthly'], 'pay_ref' => $o['pay_ref'], 'note' => $o['note'],
            'customer_phone' => $o['customer_phone'], 'address_line' => $o['address_line'], 'city' => $o['city'], 'state' => $o['state'], 'pincode' => $o['pincode'],
            'created_at' => $o['created_at'], 'items' => $items[(int)$o['id']] ?? []];
    if ($isAdmin) { $row['cost_total'] = (float)$o['cost_total']; $row['profit'] = rupees(paise($o['total']) - paise($o['cost_total'])); $row['is_demo'] = (int)$o['is_demo'] === 1; }
    if ($o['status'] !== 'cancelled' && $o['payment_status'] !== 'refunded') { $spent += paise($o['total']); $n++; }
    $out[] = $row;
}
out(['ok' => true, 'orders' => $out, 'stats' => ['orders' => $n, 'total' => rupees($spent)], 'limited' => count($out) >= 500]);
