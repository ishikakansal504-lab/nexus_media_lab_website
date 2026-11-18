<?php
// Notice board. Staff (employees and admins) read; only admins post and delete. Never put secrets here: every employee sees it.
require __DIR__ . '/db.php';
$me = require_staff();
$pdo = portfolio_db();
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = $pdo->query('SELECT n.id, n.title, n.body, n.created_at, u.name AS author FROM notices n LEFT JOIN users u ON u.id = n.created_by ORDER BY n.id DESC LIMIT 30')->fetchAll();
    out(['ok' => true, 'notices' => $rows]);
}
require_post();
if ($me['role'] !== 'admin') fail('Only admins can post notices.', 403);
$b = body();
if (str_field($b, 'action') === 'delete') {
    $pdo->prepare('DELETE FROM notices WHERE id = ?')->execute([(int)($b['id'] ?? 0)]);
    out(['ok' => true]);
}
$title = clean_line(str_field($b, 'title'), 120); $text = clean_block(str_field($b, 'body'), 3000);
if (text_len($title) < 2) fail('Give the notice a title.');
if (text_len($text) < 1) fail('Write the notice.');
$pdo->prepare('INSERT INTO notices (title, body, created_by) VALUES (?,?,?)')->execute([$title, $text, $me['id']]);
out(['ok' => true]);
