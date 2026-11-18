<?php
// Admin only: add, change, hide/show or delete a service.
require __DIR__ . '/db.php';
require_post();
require_admin();
$pdo = shop_db();
$b = body();
$action = str_field($b, 'action');

switch ($action) {
    case 'save':
        $id    = (int)($b['id'] ?? 0);
        $name  = clean_line(str_field($b, 'name'), 120);
        $cat   = clean_line(str_field($b, 'category'), 40) ?: 'Other';
        $desc  = clean_line(str_field($b, 'description'), 500);
        $unit  = clean_line(str_field($b, 'unit'), 30) ?: 'one-time';
        $price = is_numeric($b['price'] ?? null) ? (float)$b['price'] : -1;
        $cost  = is_numeric($b['cost'] ?? null) ? (float)$b['cost'] : -1;
        $active = !empty($b['active']) ? 1 : 0;
        $sort  = (int)($b['sort_order'] ?? 0);
        if (text_len($name) < 2) fail('Give the service a name (at least 2 characters).');
        if ($price <= 0 || $price > 10000000) fail('Enter a price between 1 and 1,00,00,000.');
        if ($cost < 0 || $cost > 10000000) fail('Enter your cost (use 0 if there is none).');
        if ($id > 0) {
            $st = $pdo->prepare('UPDATE services SET name=?, category=?, description=?, unit=?, price=?, cost=?, active=?, sort_order=? WHERE id=?');
            $st->execute([$name, $cat, $desc, $unit, round($price, 2), round($cost, 2), $active, $sort, $id]);
            if ($st->rowCount() === 0) {
                $chk = $pdo->prepare('SELECT id FROM services WHERE id = ?'); $chk->execute([$id]);
                if (!$chk->fetch()) fail('Service not found.', 404);
            }
        } else {
            $pdo->prepare('INSERT INTO services (name, category, description, unit, price, cost, active, sort_order) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$name, $cat, $desc, $unit, round($price, 2), round($cost, 2), $active, $sort]);
        }
        out(['ok' => true]);

    case 'toggle':
        $pdo->prepare('UPDATE services SET active = 1 - active WHERE id = ?')->execute([(int)($b['id'] ?? 0)]);
        out(['ok' => true]);

    case 'delete':
        // Past orders keep their own copy of the name and price, so deleting is safe for the history.
        $pdo->prepare('DELETE FROM services WHERE id = ?')->execute([(int)($b['id'] ?? 0)]);
        out(['ok' => true]);

    default:
        fail('Unknown action.');
}
