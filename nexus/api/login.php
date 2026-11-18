<?php
require __DIR__ . '/db.php';
require_post();
$b = body();
// Customers and admins log in with their email. Employees may use their email or their employee ID (EMP-0007).
$ident  = strtolower(str_field($b, 'login') ?: str_field($b, 'email'));
$portal = str_field($b, 'portal');                       // which tab the person used: customer, employee or admin
if (!in_array($portal, ['customer', 'employee', 'admin'], true)) $portal = '';
$pass  = isset($b['password']) && is_string($b['password']) ? $b['password'] : '';
if ($ident === '' || $pass === '') fail('Enter your ' . ($portal === 'employee' ? 'employee ID or email' : 'email') . ' and password.');

$sel = 'SELECT *, (locked_until IS NOT NULL AND locked_until > NOW()) AS is_locked FROM users WHERE ';
if (preg_match('/^(emp|adm|cus)-?(\d{1,9})$/', $ident, $m)) {
    $st = db()->prepare($sel . 'id = ? AND role = ?');
    $st->execute([(int)$m[2], ['emp' => 'employee', 'adm' => 'admin', 'cus' => 'customer'][$m[1]]]);
} else {
    $st = db()->prepare($sel . 'email = ?');
    $st->execute([$ident]);
}
$u = $st->fetch();

if ($u && $u['is_locked']) fail('Too many wrong attempts. Try again in ' . LOCK_MINUTES . ' minutes.', 429);

// Always run password_verify so the response time does not reveal whether the email exists.
$hash = $u ? $u['password_hash'] : '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
$good = password_verify($pass, $hash);

if (!$u || !$good) {
    if ($u) {
        // Order matters in MySQL: locked_until is set first, then the counter (reset to 0 once the lock starts).
        db()->prepare('UPDATE users SET
            locked_until = IF(failed_logins + 1 >= ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), locked_until),
            failed_logins = IF(failed_logins + 1 >= ?, 0, failed_logins + 1) WHERE id = ?')
            ->execute([MAX_FAILED, LOCK_MINUTES, MAX_FAILED, $u['id']]);
    }
    fail('Invalid login details. Check your ' . ($portal === 'employee' ? 'employee ID / email' : 'email') . ' and password.', 401);
}
if ($u['status'] !== 'active') fail('Your account is disabled. Please contact the admin.', 403);

// The password is right. Now check that this is the right door: customers, employees and admins have separate logins.
if ($portal !== '' && $u['role'] !== $portal) {
    if ($u['requested_role'] === $portal)
        fail('Your ' . $portal . ' access request is still waiting for approval' . ($portal === 'admin' ? ' by the main admin' : ' by the admin') . '. Until then, log in on the Customer tab.', 403);
    fail('This is ' . ($u['role'] === 'admin' ? 'an admin' : 'a ' . $u['role']) . ' account. Please use the ' . ucfirst($u['role']) . ' tab to log in.', 403);
}

db()->prepare('UPDATE users SET failed_logins = 0, locked_until = NULL, last_login = NOW() WHERE id = ?')->execute([$u['id']]);
if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
    db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
}
session_regenerate_id(true);
$_SESSION['uid'] = (int)$u['id'];
$_SESSION['csrf'] = bin2hex(random_bytes(32));
out(['ok' => true, 'csrf' => $_SESSION['csrf'], 'user' => public_user($u), 'redirect' => ['admin' => 'admin.html', 'employee' => 'employee.html', 'customer' => 'dashboard.html'][$u['role']]]);
