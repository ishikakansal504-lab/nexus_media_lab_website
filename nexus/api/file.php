<?php
// Sends an uploaded file, only to its owner or the admin.
require __DIR__ . '/db.php';
$me = require_login();
$pdo = portfolio_db();
$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare('SELECT wf.*, w.user_id FROM work_files wf JOIN works w ON w.id = wf.work_id WHERE wf.id = ?');
$st->execute([$id]);
$f = $st->fetch();
if (!$f) fail('File not found.', 404);
if ((int)$f['user_id'] !== (int)$me['id'] && $me['role'] !== 'admin') fail('You cannot open this file.', 403);
$path = UPLOAD_DIR . '/' . $f['stored_name'];
if (!preg_match('/^[a-f0-9]{32}\.[a-z0-9]{2,5}$/', $f['stored_name']) || !is_file($path)) fail('File is missing on the server.', 404);

$ext = strtolower(pathinfo($f['stored_name'], PATHINFO_EXTENSION));
$kind = FILE_TYPES[$ext][0] ?? 'doc';
$inline = $kind !== 'doc' && !isset($_GET['download']);
$name = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $f['original_name']) ?: ('file.' . $ext);
$size = filesize($path); $start = 0; $end = $size - 1;

session_write_close();
header('Content-Type: ' . ($kind === 'doc' ? 'application/octet-stream' : $f['mime']));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
header('Accept-Ranges: bytes');
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"');
if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {   // lets videos seek
    if ($m[1] !== '') { $start = (int)$m[1]; if ($m[2] !== '') $end = min((int)$m[2], $end); }
    elseif ($m[2] !== '') { $start = max(0, $size - (int)$m[2]); }
    if ($start > $end || $start >= $size) { http_response_code(416); header("Content-Range: bytes */$size"); exit; }
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
}
header('Content-Length: ' . ($end - $start + 1));
$fh = fopen($path, 'rb'); fseek($fh, $start);
$left = $end - $start + 1;
while ($left > 0 && !feof($fh)) { $chunk = fread($fh, min(8192, $left)); echo $chunk; $left -= strlen($chunk); flush(); }
fclose($fh);
