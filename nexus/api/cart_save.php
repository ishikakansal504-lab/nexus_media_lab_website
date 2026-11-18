<?php
// Customer only: add to the cart, change a quantity, remove an item, or empty the cart.
require __DIR__ . '/db.php';
require_post();
$me = require_customer();
$pdo = shop_db();
$b = body();
$action = str_field($b, 'action');
$sid = (int)($b['service_id'] ?? 0);
$qty = (int)($b['qty'] ?? 1);

function cart_count(PDO $pdo, int $uid): int {
    $st = $pdo->prepare('SELECT COALESCE(SUM(qty),0) FROM cart_items WHERE user_id = ?'); $st->execute([$uid]);
    return (int)$st->fetchColumn();
}

switch ($action) {
    case 'add':
    case 'set':
        $st = $pdo->prepare('SELECT id FROM services WHERE id = ? AND active = 1'); $st->execute([$sid]);
        if (!$st->fetch()) fail('That service is not available.', 404);
        if ($qty < 1 || $qty > 99) fail('Quantity must be between 1 and 99.');
        $cur = $pdo->prepare('SELECT qty FROM cart_items WHERE user_id = ? AND service_id = ?'); $cur->execute([$me['id'], $sid]);
        $have = $cur->fetchColumn();
        if ($have === false) {
            $n = $pdo->prepare('SELECT COUNT(*) FROM cart_items WHERE user_id = ?'); $n->execute([$me['id']]);
            if ((int)$n->fetchColumn() >= 20) fail('The cart can hold at most 20 different services.');
            $pdo->prepare('INSERT INTO cart_items (user_id, service_id, qty) VALUES (?,?,?)')->execute([$me['id'], $sid, $qty]);
        } else {
            $new = $action === 'add' ? min(99, (int)$have + $qty) : $qty;
            $pdo->prepare('UPDATE cart_items SET qty = ? WHERE user_id = ? AND service_id = ?')->execute([$new, $me['id'], $sid]);
        }
        break;
    case 'remove':
        $pdo->prepare('DELETE FROM cart_items WHERE user_id = ? AND service_id = ?')->execute([$me['id'], $sid]);
        break;
    case 'clear':
        $pdo->prepare('DELETE FROM cart_items WHERE user_id = ?')->execute([$me['id']]);
        break;
    default:
        fail('Unknown action.');
}
out(['ok' => true, 'count' => cart_count($pdo, (int)$me['id'])]);
