<?php
// GET: the list of services. Anyone can read the active ones; the admin (?all=1) also sees hidden ones and the cost.
require __DIR__ . '/db.php';
$pdo = shop_db();
$me = null;
try { $me = current_user(); } catch (Throwable $e) {}
$isAdmin = $me && $me['role'] === 'admin';
$all = $isAdmin && isset($_GET['all']);
$rows = $pdo->query('SELECT * FROM services' . ($all ? '' : ' WHERE active = 1') . ' ORDER BY sort_order, id')->fetchAll();
$list = array_map(function ($r) use ($isAdmin) {
    $o = ['id' => (int)$r['id'], 'name' => $r['name'], 'category' => $r['category'], 'description' => $r['description'],
          'unit' => $r['unit'], 'price' => (float)$r['price'], 'active' => (int)$r['active'] === 1, 'sort_order' => (int)$r['sort_order']];
    if ($isAdmin) $o['cost'] = (float)$r['cost'];
    return $o;
}, $rows);
out(['ok' => true, 'services' => $list]);
