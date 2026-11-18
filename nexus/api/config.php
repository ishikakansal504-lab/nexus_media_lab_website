<?php
// ---- Database settings. XAMPP defaults: user "root", empty password. ----
// YOUR OWN database user and password go in api/config.local.php (copy config.local.example.php to that name).
// That file is never part of an update, so unzipping a new version over this folder keeps your password.
if (is_file(__DIR__ . '/config.local.php')) require __DIR__ . '/config.local.php';
if (!defined('DB_HOST')) define('DB_HOST', getenv('NEXUS_DB_HOST') ?: '127.0.0.1');
if (!defined('DB_PORT')) define('DB_PORT', (int)(getenv('NEXUS_DB_PORT') ?: 3306));
if (!defined('DB_NAME')) define('DB_NAME', getenv('NEXUS_DB_NAME') ?: 'nexus_media_labs');
if (!defined('DB_USER')) define('DB_USER', getenv('NEXUS_DB_USER') ?: 'root');
if (!defined('DB_PASS')) define('DB_PASS', getenv('NEXUS_DB_PASS') ?: '');

// ---- Time zone (reports use this for "today", "this week" and so on) ----
define('APP_TZ', 'Asia/Kolkata');
define('DB_TZ', '+05:30');       // the same zone, written for MySQL

// ---- Site settings ----
define('ALLOW_SIGNUP', true);   // true: visitors can create their own customer account
define('MAX_FAILED', 5);        // wrong passwords allowed before the account is locked
define('LOCK_MINUTES', 5);      // how long the lock lasts

// ---- Business contact (shown in confirmation messages; the admin gets a copy of every new order) ----
define('BUSINESS_NAME', 'Nexus Media Labs');
define('BUSINESS_EMAIL', 'ishikakansal504@gmail.com');
define('BUSINESS_PHONE', '917982845849');   // with country code, no + or spaces (used for WhatsApp links)

// ---- Order confirmation EMAIL (optional, but needed to really send email) ----
// For Gmail: 1) turn on 2-step verification for the Google account, 2) create an "App password"
// (Google Account > Security > App passwords), 3) type it below. Use the normal Gmail password NEVER.
// Leave SMTP_HOST empty to switch emailing off; orders still work and the attempt is listed in Settings.
define('SMTP_HOST',   getenv('NEXUS_SMTP_HOST') ?: '');                  // Gmail: smtp.gmail.com
define('SMTP_PORT',   (int)(getenv('NEXUS_SMTP_PORT') ?: 587));          // 587 with 'tls', or 465 with 'ssl'
define('SMTP_SECURE', getenv('NEXUS_SMTP_SECURE') ?: 'tls');             // 'tls', 'ssl' or 'none'
define('SMTP_USER',   getenv('NEXUS_SMTP_USER') ?: '');                  // your full Gmail address
define('SMTP_PASS',   getenv('NEXUS_SMTP_PASS') ?: '');                  // the 16-letter app password
define('SMTP_FROM',   getenv('NEXUS_SMTP_FROM') ?: '');                  // blank = same as SMTP_USER
define('SMTP_VERIFY', getenv('NEXUS_SMTP_VERIFY') !== '0');              // keep true; false only for local tests
