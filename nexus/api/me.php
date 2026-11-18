<?php
require __DIR__ . '/db.php';
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
$u = null;
try { $u = current_user(); } catch (Throwable $e) { /* database not ready yet */ }
$cart = 0;
if ($u && $u['role'] === 'customer') {
    try { $st = shop_db()->prepare('SELECT COALESCE(SUM(qty),0) FROM cart_items WHERE user_id = ?'); $st->execute([$u['id']]); $cart = (int)$st->fetchColumn(); } catch (Throwable $e) {}
}
out(['ok' => true, 'csrf' => $_SESSION['csrf'], 'signup' => ALLOW_SIGNUP, 'user' => $u ? public_user($u) : null, 'cart_count' => $cart]);
