<?php
// The staff help desk. An employee asks the admin for information or help and sees the answer here.
// An employee sees ONLY their own requests; admins see all of them and reply.
require __DIR__ . '/db.php';
$me = require_staff();
$pdo = portfolio_db();
$isAdmin = $me['role'] === 'admin';
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $sql = 'SELECT r.id, r.user_id, r.subject, r.message, r.status, r.reply, r.replied_at, r.created_at, u.name AS owner_name, u.id AS uid
            FROM staff_requests r JOIN users u ON u.id = r.user_id' . ($isAdmin ? '' : ' WHERE r.user_id = ?') . ' ORDER BY r.status = \'open\' DESC, r.id DESC LIMIT 200';
    $st = $pdo->prepare($sql); $st->execute($isAdmin ? [] : [$me['id']]);
    $rows = array_map(function ($r) { $r['code'] = 'EMP-' . str_pad((string)$r['uid'], 4, '0', STR_PAD_LEFT); unset($r['uid']); return $r; }, $st->fetchAll());
    out(['ok' => true, 'requests' => $rows]);
}
require_post();
$b = body();
$action = str_field($b, 'action');
if ($action === 'create') {
    if ($isAdmin) fail('Admins answer requests; they do not send them.');
    $subject = clean_line(str_field($b, 'subject'), 120); $msg = clean_block(str_field($b, 'message'), 2000);
    if (text_len($subject) < 3) fail('Write a short subject (at least 3 characters).');
    if (text_len($msg) < 5) fail('Write your question or request (at least 5 characters).');
    $st = $pdo->prepare("SELECT COUNT(*) FROM staff_requests WHERE user_id = ? AND status = 'open'"); $st->execute([$me['id']]);
    if ((int)$st->fetchColumn() >= 10) fail('You already have 10 open requests. Please wait for answers.');
    $pdo->prepare('INSERT INTO staff_requests (user_id, subject, message) VALUES (?,?,?)')->execute([$me['id'], $subject, $msg]);
    out(['ok' => true]);
}
if ($action === 'reply') {
    if (!$isAdmin) fail('Only admins can reply.', 403);
    $reply = clean_block(str_field($b, 'reply'), 2000);
    if (text_len($reply) < 1) fail('Write the reply.');
    $st = $pdo->prepare("UPDATE staff_requests SET reply = ?, status = 'answered', replied_by = ?, replied_at = NOW() WHERE id = ?");
    $st->execute([$reply, $me['id'], (int)($b['id'] ?? 0)]);
    out(['ok' => true]);
}
fail('Unknown action.');
