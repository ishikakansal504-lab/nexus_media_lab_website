<?php
// Admin only: every user's work in one list.
require __DIR__ . '/db.php';
require_admin();
$pdo = portfolio_db();
$rows = $pdo->query('SELECT w.*, u.name AS owner_name, u.email AS owner_email FROM works w JOIN users u ON u.id = w.user_id
                     ORDER BY COALESCE(w.work_date, DATE(w.created_at)) DESC, w.id DESC LIMIT 1000')->fetchAll();
$works = attach_files($pdo, $rows);
foreach ($works as $i => $w) { $works[$i]['owner_name'] = $rows[$i]['owner_name']; $works[$i]['owner_email'] = $rows[$i]['owner_email']; }
out(['ok' => true, 'works' => $works, 'categories' => WORK_CATEGORIES]);
