<?php
// Creates the portfolio tables if they do not exist yet (safe to run on every request).
function ensure_portfolio_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS profiles (
        user_id INT UNSIGNED NOT NULL PRIMARY KEY,
        headline VARCHAR(120) NOT NULL DEFAULT '',
        bio TEXT NULL,
        location VARCHAR(100) NOT NULL DEFAULT '',
        phone VARCHAR(30) NOT NULL DEFAULT '',
        skills VARCHAR(500) NOT NULL DEFAULT '',
        education TEXT NULL,
        experience TEXT NULL,
        linkedin VARCHAR(300) NOT NULL DEFAULT '',
        github VARCHAR(300) NOT NULL DEFAULT '',
        website VARCHAR(300) NOT NULL DEFAULT '',
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_profile_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS works (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        title VARCHAR(120) NOT NULL,
        category VARCHAR(30) NOT NULL DEFAULT 'Other',
        description TEXT NULL,
        work_date DATE NULL,
        link_url VARCHAR(500) NOT NULL DEFAULT '',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_works_user (user_id),
        CONSTRAINT fk_work_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS work_files (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        work_id INT UNSIGNED NOT NULL,
        original_name VARCHAR(160) NOT NULL,
        stored_name VARCHAR(80) NOT NULL,
        mime VARCHAR(100) NOT NULL,
        size INT UNSIGNED NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_stored (stored_name),
        KEY idx_files_work (work_id),
        CONSTRAINT fk_file_work FOREIGN KEY (work_id) REFERENCES works(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Staff desk: notices from the admin to the staff, and questions / requests from employees to the admin.
    $pdo->exec("CREATE TABLE IF NOT EXISTS notices (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(120) NOT NULL,
        body TEXT NOT NULL,
        created_by INT UNSIGNED NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_notice_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS staff_requests (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        subject VARCHAR(120) NOT NULL,
        message TEXT NOT NULL,
        status ENUM('open','answered') NOT NULL DEFAULT 'open',
        reply TEXT NULL,
        replied_by INT UNSIGNED NULL,
        replied_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_req_user (user_id),
        CONSTRAINT fk_req_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
