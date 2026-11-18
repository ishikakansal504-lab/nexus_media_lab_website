<?php
require __DIR__ . '/db.php';
require_post();
$me = require_staff();
$pdo = portfolio_db();
$id = (int)(body()['id'] ?? 0);
$st = $pdo->prepare('SELECT w.id FROM works w WHERE w.id = ? AND w.user_id = ?'); $st->execute([$id, $me['id']]);
if (!$st->fetch()) fail('Work not found.', 404);
$st = $pdo->prepare('SELECT stored_name FROM work_files WHERE work_id = ?'); $st->execute([$id]);
$names = $st->fetchAll(PDO::FETCH_COLUMN);
$pdo->prepare('DELETE FROM works WHERE id = ?')->execute([$id]);   // its file rows go with it (ON DELETE CASCADE)
delete_stored_files($names);
out(['ok' => true]);
