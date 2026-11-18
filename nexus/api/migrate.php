<?php
// Makes an older database ready for the current account system. It runs on the first database use of every
// request but does real work only once, because it checks what is already there.
//   roles   : admin, employee, customer (old "user" rows become employees)
//   owner   : exactly one "main admin" (is_owner) who alone can create, approve or remove other admins
//   extras  : access requests (requested_role), designation, department and the admin's private note
function ensure_roles(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    try {
        $cols = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'")->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) { return; }
    if (!$cols) return;                                   // no users table yet: setup.php will create it correctly
    $done = true;

    $c = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
    if ($c && strpos((string)$c['Type'], 'customer') === false) {
        $pdo->exec("ALTER TABLE users MODIFY role ENUM('admin','user','employee','customer') NOT NULL DEFAULT 'user'");
        $pdo->exec("UPDATE users SET role = 'employee' WHERE role = 'user'");
        $pdo->exec("ALTER TABLE users MODIFY role ENUM('admin','employee','customer') NOT NULL DEFAULT 'customer'");
    }
    $add = ['is_owner'       => "TINYINT(1) NOT NULL DEFAULT 0",
            'requested_role' => "ENUM('employee','admin') NULL DEFAULT NULL",
            'designation'    => "VARCHAR(80) NOT NULL DEFAULT ''",
            'department'     => "VARCHAR(60) NOT NULL DEFAULT ''",
            'admin_note'     => "TEXT NULL"];
    foreach ($add as $col => $def) if (!in_array($col, $cols, true)) $pdo->exec("ALTER TABLE users ADD COLUMN $col $def");
    if (in_array('admin_slot', $cols, true)) {            // the old "only one admin" rule is replaced by "only one owner"
        $pdo->exec('ALTER TABLE users DROP INDEX uq_one_admin');
        $pdo->exec('ALTER TABLE users DROP COLUMN admin_slot');
    }
    if (!in_array('owner_slot', $cols, true)) {
        $pdo->exec('ALTER TABLE users ADD COLUMN owner_slot TINYINT AS (IF(is_owner = 1, 1, NULL)) STORED, ADD UNIQUE KEY uq_one_owner (owner_slot)');
    }
    if ((int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_owner = 1')->fetchColumn() === 0) {
        $pdo->exec("UPDATE users SET is_owner = 1 WHERE role = 'admin' ORDER BY id LIMIT 1");   // the existing admin becomes the main admin
    }
}
