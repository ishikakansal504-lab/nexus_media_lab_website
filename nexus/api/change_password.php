<?php
require __DIR__ . '/db.php';
require_post();
$u = require_login();
$b = body();
$cur = isset($b['current']) && is_string($b['current']) ? $b['current'] : '';
$new = isset($b['new']) && is_string($b['new']) ? $b['new'] : '';
$st = db()->prepare('SELECT password_hash FROM users WHERE id = ?'); $st->execute([$u['id']]);
if (!password_verify($cur, (string)$st->fetchColumn())) fail('Current password is wrong.', 401);
if ($e = check_password($new)) fail($e);
if ($new === $cur) fail('New password must be different from the current one.');
db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $u['id']]);
out(['ok' => true]);
