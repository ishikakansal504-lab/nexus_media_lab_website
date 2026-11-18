<?php
// Run this ONCE in the browser: http://localhost/nexus-media-labs/setup.php
// It creates the database, the users table and the main admin account.
declare(strict_types=1);
require __DIR__ . '/api/config.php';
session_name('nexus_setup'); session_start();
if (empty($_SESSION['t'])) $_SESSION['t'] = bin2hex(random_bytes(16));

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
$msg = ''; $done = false; $already = false; $err = '';

try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4', DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec('USE `' . DB_NAME . '`');
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(80) NOT NULL,
        email VARCHAR(190) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        role ENUM('admin','employee','customer') NOT NULL DEFAULT 'customer',
        status ENUM('active','disabled') NOT NULL DEFAULT 'active',
        failed_logins TINYINT UNSIGNED NOT NULL DEFAULT 0,
        locked_until DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_login DATETIME NULL,
        is_owner TINYINT(1) NOT NULL DEFAULT 0,
        requested_role ENUM('employee','admin') NULL DEFAULT NULL,
        designation VARCHAR(80) NOT NULL DEFAULT '',
        department VARCHAR(60) NOT NULL DEFAULT '',
        admin_note TEXT NULL,
        owner_slot TINYINT AS (IF(is_owner = 1, 1, NULL)) STORED,
        UNIQUE KEY uq_email (email),
        UNIQUE KEY uq_one_owner (owner_slot)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $already = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() > 0;

    if (!$already && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($_SESSION['t'], (string)($_POST['t'] ?? ''))) throw new RuntimeException('Please reload the page and try again.');
        $name = trim((string)($_POST['name'] ?? '')); $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $pass = (string)($_POST['password'] ?? '');
        $nl = function_exists('mb_strlen') ? mb_strlen($name) : (int)preg_match_all('/./us', $name);
        if ($nl < 2 || $nl > 80) throw new RuntimeException('Name must be 2 to 80 characters.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid email address.');
        if (strlen($pass) < 8 || strlen($pass) > 72 || !preg_match('/[A-Za-z]/', $pass) || !preg_match('/\d/', $pass))
            throw new RuntimeException('Password needs 8 to 72 characters with at least one letter and one number.');
        $pdo->prepare("INSERT INTO users (name, email, password_hash, role, is_owner) VALUES (?, ?, ?, 'admin', 1)")
            ->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT)]);
        $done = true; $already = true;
    }
} catch (PDOException $e) {
    $err = 'Cannot connect to MySQL (' . $e->getMessage() . '). Start MySQL in XAMPP and check api/config.php.';
} catch (RuntimeException $e) {
    $err = $e->getMessage();
}
?><!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Setup | Nexus Media Labs</title>
<link rel="stylesheet" href="assets/auth.css"></head>
<body class="auth-body"><main class="auth-card">
<a class="brand" href="index.html"><span class="mark">N</span><span>NEXUS<small>MEDIA LABS</small></span></a>
<h1>One-time setup</h1>
<?php if ($err): ?><div class="alert bad"><?= h($err) ?></div><?php endif; ?>
<?php if ($done): ?>
  <div class="alert good">Done! The database and the admin account are ready.</div>
  <p class="muted">For safety, delete <b>setup.php</b> from the folder now. Then log in as admin.</p>
  <a class="btn" href="login.html">Go to login</a>
<?php elseif ($already): ?>
  <div class="alert good">Setup is already complete. An admin account exists.</div>
  <p class="muted">You can delete <b>setup.php</b>.</p>
  <a class="btn" href="login.html">Go to login</a>
<?php elseif (!$err || isset($pdo)): ?>
  <p class="muted">This creates the MySQL database <b><?= h(DB_NAME) ?></b>, the <b>users</b> table and the main admin account.</p>
  <form method="post" autocomplete="off">
    <input type="hidden" name="t" value="<?= h($_SESSION['t']) ?>">
    <label>Admin name<input name="name" required maxlength="80" value="<?= h((string)($_POST['name'] ?? '')) ?>"></label>
    <label>Admin email<input name="email" type="email" required value="<?= h((string)($_POST['email'] ?? '')) ?>"></label>
    <label>Admin password<input name="password" type="password" required minlength="8" placeholder="8+ characters, letters and numbers"></label>
    <button class="btn" type="submit">Create database and admin</button>
  </form>
<?php endif; ?>
</main></body></html>
