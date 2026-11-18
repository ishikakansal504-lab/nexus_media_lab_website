<?php
// Sends the admin's payment scanner (QR) picture to a logged-in customer or the admin.
require __DIR__ . '/db.php';
$me = require_login();
if ($me['role'] === 'employee') fail('Not available.', 403);
$pdo = shop_db();
$name = (string)(all_settings($pdo)['qr_file'] ?? '');
if ($name === '' || !preg_match('/^[a-f0-9]{32}\.(png|jpg|jpeg|webp)$/', $name) || !is_file(UPLOAD_DIR . '/' . $name)) fail('No scanner has been added yet.', 404);
$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
$mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'][$ext];
session_write_close();
header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-cache');            // the admin may replace it at any time
header('Content-Length: ' . filesize(UPLOAD_DIR . '/' . $name));
readfile(UPLOAD_DIR . '/' . $name);
