<?php
// Admin only. Rules about who may change whom:
//   - Any admin manages employees and customers.
//   - Only the main admin (owner) creates, approves, demotes, disables, resets or deletes other ADMINS.
//   - The main admin account itself can never be changed here.
require __DIR__ . '/db.php';
$actor = require_admin();
$isOwner = !empty($actor['is_owner']);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = shop_db()->query("SELECT id,name,email,role,status,created_at,last_login,is_owner,requested_role,designation,department,admin_note,
        (SELECT COUNT(*) FROM works w WHERE w.user_id = users.id) AS works_count,
        (SELECT COUNT(*) FROM orders o WHERE o.user_id = users.id AND o.status <> 'cancelled') AS orders_count,
        (SELECT COALESCE(SUM(o.total),0) FROM orders o WHERE o.user_id = users.id AND o.status <> 'cancelled' AND o.payment_status <> 'refunded') AS spent
        FROM users ORDER BY is_owner DESC, role='admin' DESC, role='employee' DESC, id")->fetchAll();
    out(['ok' => true, 'me_owner' => $isOwner, 'users' => array_map(fn($r) => public_user($r) + ['admin_note' => (string)$r['admin_note'],
        'works_count' => (int)$r['works_count'], 'orders_count' => (int)$r['orders_count'], 'spent' => (float)$r['spent']], $rows)]);
}

require_post();
$b = body();
$action = str_field($b, 'action');

function target(array $b): array {
    $st = db()->prepare('SELECT id,name,email,role,status,is_owner,requested_role FROM users WHERE id = ?');
    $st->execute([(int)($b['id'] ?? 0)]);
    $t = $st->fetch();
    if (!$t) fail('User not found.', 404);
    return $t;
}
// Changing an admin (or the owner) is for the main admin only, and nobody may change the owner or themselves this way.
function guard_target(array $t, array $actor): void {
    if (!empty($t['is_owner'])) fail('The main admin account cannot be changed this way.', 403);
    if ((int)$t['id'] === (int)$actor['id']) fail('You cannot do this to your own account.');
    if ($t['role'] === 'admin' && empty($actor['is_owner'])) fail('Only the main admin can change another admin.', 403);
}

switch ($action) {
    case 'add':
        $name = str_field($b, 'name'); $email = strtolower(str_field($b, 'email'));
        $pass = isset($b['password']) && is_string($b['password']) ? $b['password'] : '';
        if ($e = check_name($name) ?? check_email($email) ?? check_password($pass)) fail($e);
        $role = str_field($b, 'role') ?: 'employee';
        if (!in_array($role, ['employee', 'customer', 'admin'], true)) fail('Choose admin, employee or customer.');
        if ($role === 'admin' && !$isOwner) fail('Only the main admin can create another admin.', 403);
        try {
            db()->prepare("INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)")
                ->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT), $role]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') fail('An account with this email already exists.', 409);
            throw $e;
        }
        out(['ok' => true]);

    case 'toggle':
        $t = target($b); guard_target($t, $actor);
        $new = $t['status'] === 'active' ? 'disabled' : 'active';
        db()->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$new, $t['id']]);
        out(['ok' => true, 'status' => $new]);

    case 'reset':
        $t = target($b); guard_target($t, $actor);
        $pass = isset($b['password']) && is_string($b['password']) ? $b['password'] : '';
        if ($e = check_password($pass)) fail($e);
        db()->prepare('UPDATE users SET password_hash = ?, failed_logins = 0, locked_until = NULL WHERE id = ?')
            ->execute([password_hash($pass, PASSWORD_DEFAULT), $t['id']]);
        out(['ok' => true]);

    case 'set_role':                                       // promote or change the account type
        $t = target($b); guard_target($t, $actor);
        $role = str_field($b, 'role');
        if (!in_array($role, ['admin', 'employee', 'customer'], true)) fail('Choose admin, employee or customer.');
        if ($role === 'admin' && !$isOwner) fail('Only the main admin can make someone an admin.', 403);
        db()->prepare('UPDATE users SET role = ?, requested_role = NULL WHERE id = ?')->execute([$role, $t['id']]);
        out(['ok' => true]);

    case 'approve':
    case 'reject':
        $t = target($b);
        if (!$t['requested_role']) fail('This person has no pending request.');
        if ($t['requested_role'] === 'admin' && !$isOwner) fail('Only the main admin can approve an admin request.', 403);
        if ($action === 'approve') db()->prepare('UPDATE users SET role = requested_role, requested_role = NULL WHERE id = ?')->execute([$t['id']]);
        else db()->prepare('UPDATE users SET requested_role = NULL WHERE id = ?')->execute([$t['id']]);
        out(['ok' => true]);

    case 'edit':                                           // job details and the admin's private note (never shown to the employee)
        $t = target($b);
        if (!in_array($t['role'], ['employee', 'admin'], true)) fail('Only staff accounts have these details.');
        if ($t['role'] === 'admin' && !$isOwner) fail('Only the main admin can edit another admin.', 403);
        db()->prepare('UPDATE users SET designation = ?, department = ?, admin_note = ? WHERE id = ?')
            ->execute([clean_line(str_field($b, 'designation'), 80), clean_line(str_field($b, 'department'), 60), clean_block(str_field($b, 'admin_note'), 2000), $t['id']]);
        out(['ok' => true]);

    case 'delete':
        $t = target($b); guard_target($t, $actor);
        $pdo = shop_db();
        $st = $pdo->prepare('SELECT wf.stored_name FROM work_files wf JOIN works w ON w.id = wf.work_id WHERE w.user_id = ?');
        $st->execute([$t['id']]);
        $names = $st->fetchAll(PDO::FETCH_COLUMN);
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$t['id']]);   // profile, works and file rows are removed with it
        delete_stored_files($names);
        out(['ok' => true]);

    default:
        fail('Unknown action.');
}
