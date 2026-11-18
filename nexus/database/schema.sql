-- Nexus Media Labs: database structure (setup.php runs this for you).
-- You only need this file if you want to create the table by hand in phpMyAdmin.
CREATE DATABASE IF NOT EXISTS nexus_media_labs CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE nexus_media_labs;

CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(80)  NOT NULL,
  email         VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,                 -- never the real password
  role          ENUM('admin','employee','customer') NOT NULL DEFAULT 'customer',
  status        ENUM('active','disabled') NOT NULL DEFAULT 'active',
  failed_logins TINYINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until  DATETIME NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login    DATETIME NULL,
  is_owner       TINYINT(1) NOT NULL DEFAULT 0,        -- 1 = the main admin; only the main admin can create or approve other admins
  requested_role ENUM('employee','admin') NULL DEFAULT NULL,   -- a sign-up request waiting for approval
  designation    VARCHAR(80) NOT NULL DEFAULT '',
  department     VARCHAR(60) NOT NULL DEFAULT '',
  admin_note     TEXT NULL,                              -- the admin's private note; never shown to the employee
  -- Equals 1 only for the main admin row, NULL for everyone else.
  -- The UNIQUE key below means MySQL itself refuses a second main admin.
  owner_slot     TINYINT AS (IF(is_owner = 1, 1, NULL)) STORED,
  UNIQUE KEY uq_email (email),
  UNIQUE KEY uq_one_owner (owner_slot)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---- Portfolio tables (created automatically the first time someone opens the portfolio pages) ----
CREATE TABLE IF NOT EXISTS profiles (
  user_id INT UNSIGNED NOT NULL PRIMARY KEY,            -- one profile per user
  headline VARCHAR(120) NOT NULL DEFAULT '', bio TEXT NULL, location VARCHAR(100) NOT NULL DEFAULT '',
  phone VARCHAR(30) NOT NULL DEFAULT '', skills VARCHAR(500) NOT NULL DEFAULT '',
  education TEXT NULL, experience TEXT NULL,
  linkedin VARCHAR(300) NOT NULL DEFAULT '', github VARCHAR(300) NOT NULL DEFAULT '', website VARCHAR(300) NOT NULL DEFAULT '',
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_profile_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS works (                       -- one row per work item a user adds
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  title VARCHAR(120) NOT NULL, category VARCHAR(30) NOT NULL DEFAULT 'Other',
  description TEXT NULL, work_date DATE NULL, link_url VARCHAR(500) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_works_user (user_id),
  CONSTRAINT fk_work_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS work_files (                  -- the files uploaded for a work item (the files live in storage/uploads)
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  work_id INT UNSIGNED NOT NULL,
  original_name VARCHAR(160) NOT NULL, stored_name VARCHAR(80) NOT NULL, mime VARCHAR(100) NOT NULL, size INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_stored (stored_name), KEY idx_files_work (work_id),
  CONSTRAINT fk_file_work FOREIGN KEY (work_id) REFERENCES works(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
