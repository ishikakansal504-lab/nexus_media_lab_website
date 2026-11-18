<?php
require __DIR__ . '/db.php';
require_post();
if (!ALLOW_SIGNUP) fail('Sign-up is closed. Please ask the admin to create your account.', 403);
$b = body();
$name = str_field($b, 'name'); $email = strtolower(str_field($b, 'email'));
$pass = isset($b['password']) && is_string($b['password']) ? $b['password'] : '';
if ($e = check_name($name) ?? check_email($email) ?? check_password($pass)) fail($e);

try {
    // Sign-up always makes a customer. Someone who wants to be an employee or an admin only sends a REQUEST:
    // they stay a customer until the admin (for employees) or the main admin (for admins) approves it.
    $want = str_field($b, 'account_type');
    $req = in_array($want, ['employee', 'admin'], true) ? $want : null;
    db()->prepare("INSERT INTO users (name, email, password_hash, role, requested_role) VALUES (?, ?, ?, 'customer', ?)")
        ->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT), $req]);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') fail('An account with this email already exists.', 409);
    throw $e;
}
out(['ok' => true, 'requested' => $req]);
