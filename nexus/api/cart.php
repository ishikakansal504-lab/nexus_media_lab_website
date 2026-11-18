<?php
// GET: the logged-in customer's cart, with totals and the payment options.
require __DIR__ . '/db.php';
$me = require_customer();
$pdo = shop_db();
$st = $pdo->prepare('SELECT c.service_id, c.qty, s.name, s.unit, s.price, s.active, s.category
                     FROM cart_items c JOIN services s ON s.id = c.service_id WHERE c.user_id = ? ORDER BY s.sort_order, s.id');
$st->execute([$me['id']]);
$items = []; $sub = 0; $count = 0;
foreach ($st->fetchAll() as $r) {
    $line = paise($r['price']) * (int)$r['qty'];
    $sub += $line; $count += (int)$r['qty'];
    $items[] = ['service_id' => (int)$r['service_id'], 'name' => $r['name'], 'unit' => $r['unit'], 'category' => $r['category'],
                'price' => (float)$r['price'], 'qty' => (int)$r['qty'], 'line_total' => rupees($line), 'active' => (int)$r['active'] === 1];
}
// Pre-fill the checkout form from the customer's last order (or from the account).
$lo = $pdo->prepare('SELECT customer_name, customer_email, customer_phone, address_line, city, state, pincode FROM orders WHERE user_id = ? AND is_demo = 0 ORDER BY id DESC LIMIT 1');
$lo->execute([$me['id']]); $last = $lo->fetch() ?: [];
$profile = ['name' => $last['customer_name'] ?? $me['name'], 'email' => $last['customer_email'] ?? $me['email'], 'phone' => $last['customer_phone'] ?? '',
            'address' => $last['address_line'] ?? '', 'city' => $last['city'] ?? '', 'state' => $last['state'] ?? '', 'pincode' => $last['pincode'] ?? ''];
out(['ok' => true, 'profile' => $profile, 'items' => $items, 'subtotal' => rupees($sub), 'count' => $count, 'payment' => payment_options(all_settings($pdo))]);
