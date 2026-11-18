<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
date_default_timezone_set(APP_TZ);

session_name('nexus_sid');
session_set_cookie_params([
    'lifetime' => 0, 'path' => '/', 'httponly' => true,
    'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']),
]);
session_start();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER, DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                 PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                 PDO::ATTR_EMULATE_PREPARES => false]
            );
        } catch (PDOException $e) {
            $why = $e->getCode() === 1045 ? ' The MySQL user or password in api/config.php (or api/config.local.php) is wrong.' :
                   ($e->getCode() === 1049 ? ' The database "' . DB_NAME . '" does not exist yet: run setup.php once.' : ' Is MySQL / MariaDB running?');
            fail('Cannot connect to the database.' . $why, 500);
        }
        try { $pdo->exec("SET time_zone = '" . DB_TZ . "'"); } catch (PDOException $e) { /* old server: keep its own zone */ }
        require_once __DIR__ . '/migrate.php';
        ensure_roles($pdo);      // turns the old "user" role into "employee" and adds "customer" (runs once)
    }
    return $pdo;
}

function out(array $data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function fail(string $message, int $code = 400): never {
    out(['ok' => false, 'error' => $message], $code);
}

function require_post(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Use POST.', 405);
    $token = $_SESSION['csrf'] ?? '';
    $sent  = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if ($token === '' || !hash_equals($token, $sent)) fail('Session expired. Please refresh the page and try again.', 403);
}
function body(): array {
    $data = json_decode(file_get_contents('php://input') ?: '', true);
    return is_array($data) ? $data : [];
}
function str_field(array $b, string $key): string {
    return isset($b[$key]) && is_string($b[$key]) ? trim($b[$key]) : '';
}

// The person's ID card number: EMP-0007 for an employee, ADM-0001 for an admin, CUS-0012 for a customer.
function emp_code(array $u): string {
    return ['admin' => 'ADM', 'employee' => 'EMP', 'customer' => 'CUS'][$u['role']] . '-' . str_pad((string)$u['id'], 4, '0', STR_PAD_LEFT);
}
function public_user(array $u): array {
    return ['id' => (int)$u['id'], 'name' => $u['name'], 'email' => $u['email'], 'role' => $u['role'], 'code' => emp_code($u),
            'is_owner' => !empty($u['is_owner']), 'requested_role' => $u['requested_role'] ?? null,
            'designation' => $u['designation'] ?? '', 'department' => $u['department'] ?? '',
            'status' => $u['status'], 'created_at' => $u['created_at'], 'last_login' => $u['last_login']];
}
function current_user(): ?array {
    if (empty($_SESSION['uid'])) return null;
    $st = db()->prepare('SELECT id,name,email,role,status,created_at,last_login,is_owner,requested_role,designation,department FROM users WHERE id = ?');
    $st->execute([$_SESSION['uid']]);
    $u = $st->fetch();
    if (!$u || $u['status'] !== 'active') { unset($_SESSION['uid']); return null; }
    return $u;
}
function require_login(): array {
    $u = current_user();
    if (!$u) fail('Please log in first.', 401);
    return $u;
}
function require_admin(): array {
    $u = require_login();
    if ($u['role'] !== 'admin') fail('Admin access only.', 403);
    return $u;
}
// Only the main admin (the owner) may create, approve, demote or remove other admins.
function require_owner(): array {
    $u = require_admin();
    if (empty($u['is_owner'])) fail('Only the main admin can do this.', 403);
    return $u;
}
// Portfolio pages belong to the staff: the admin and the employees.
function require_staff(): array {
    $u = require_login();
    if ($u['role'] === 'customer') fail('Portfolios are for staff accounts only.', 403);
    return $u;
}
function require_customer(): array {
    $u = require_login();
    if ($u['role'] !== 'customer') fail('Only customer accounts can do this.', 403);
    return $u;
}

// Counts characters without needing the PHP mbstring add-on.
function text_len(string $s): int {
    return function_exists('mb_strlen') ? mb_strlen($s) : (int)preg_match_all('/./us', $s);
}
function check_name(string $name): ?string {
    $len = text_len($name);
    return ($len < 2 || $len > 80) ? 'Name must be 2 to 80 characters.' : null;
}
function check_email(string $email): ?string {
    if ($email === '' || strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) return 'Enter a valid email address.';
    return null;
}
function check_password(string $p): ?string {
    if (strlen($p) < 8) return 'Password must be at least 8 characters.';
    if (strlen($p) > 72) return 'Password can be at most 72 characters.';
    if (!preg_match('/[A-Za-z]/', $p) || !preg_match('/\d/', $p)) return 'Password needs at least one letter and one number.';
    return null;
}

// ---------------------------------------------------------------- portfolio helpers
if (!defined('UPLOAD_DIR'))   define('UPLOAD_DIR', dirname(__DIR__) . '/storage/uploads');
if (!defined('MAX_FILE_MB'))  define('MAX_FILE_MB', 15);        // per file
if (!defined('MAX_FILES'))    define('MAX_FILES', 5);           // files per work item
const WORK_CATEGORIES = ['Project', 'Design', 'Video / Reel', 'Writing / Blog', 'Certificate', 'Other'];
// extension => [kind, mime]. "inline" kinds open in the browser, "doc" kinds are always downloaded.
const FILE_TYPES = [
    'jpg' => ['image', 'image/jpeg'], 'jpeg' => ['image', 'image/jpeg'], 'png' => ['image', 'image/png'],
    'gif' => ['image', 'image/gif'], 'webp' => ['image', 'image/webp'],
    'pdf' => ['pdf', 'application/pdf'], 'mp4' => ['video', 'video/mp4'], 'webm' => ['video', 'video/webm'],
    'docx' => ['doc', 'application/octet-stream'], 'pptx' => ['doc', 'application/octet-stream'],
    'xlsx' => ['doc', 'application/octet-stream'], 'txt' => ['doc', 'application/octet-stream'],
];

function portfolio_db(): PDO {
    require_once __DIR__ . '/schema.php';
    $pdo = db();
    try { ensure_portfolio_schema($pdo); }
    catch (PDOException $e) { fail('Could not prepare the portfolio tables: ' . $e->getMessage(), 500); }
    return $pdo;
}

function url_ok(string $u): bool {
    return $u !== '' && strlen($u) <= 500 && preg_match('#^https?://#i', $u) && filter_var($u, FILTER_VALIDATE_URL) !== false;
}
function clean_line(string $s, int $max): string {
    $s = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s) ?? '');
    return text_len($s) > $max ? trim(mb_substr_safe($s, $max)) : $s;
}
function clean_block(string $s, int $max): string {
    $s = trim(str_replace("\r\n", "\n", $s));
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
    return text_len($s) > $max ? mb_substr_safe($s, $max) : $s;
}
function mb_substr_safe(string $s, int $len): string {
    if (function_exists('mb_substr')) return mb_substr($s, 0, $len);
    preg_match_all('/./us', $s, $m);
    return implode('', array_slice($m[0], 0, $len));
}

// Check the real content of an uploaded file (not just its name).
function sniff_ok(string $path, string $ext): bool {
    $kind = FILE_TYPES[$ext][0] ?? '';
    if ($kind === 'doc') return true;                       // downloaded only, never shown in the page
    $h = file_get_contents($path, false, null, 0, 16);
    if ($h === false || $h === '') return false;
    switch ($ext) {
        case 'jpg': case 'jpeg': return strncmp($h, "\xFF\xD8\xFF", 3) === 0;
        case 'png':  return strncmp($h, "\x89PNG", 4) === 0;
        case 'gif':  return strncmp($h, 'GIF8', 4) === 0;
        case 'webp': return strncmp($h, 'RIFF', 4) === 0 && substr($h, 8, 4) === 'WEBP';
        case 'pdf':  return strncmp($h, '%PDF', 4) === 0;
        case 'mp4':  return substr($h, 4, 4) === 'ftyp';
        case 'webm': return strncmp($h, "\x1A\x45\xDF\xA3", 4) === 0;
    }
    return false;
}

function file_row(array $f): array {
    $ext = strtolower(pathinfo($f['original_name'], PATHINFO_EXTENSION));
    return ['id' => (int)$f['id'], 'name' => $f['original_name'], 'size' => (int)$f['size'],
            'kind' => FILE_TYPES[$ext][0] ?? 'doc'];
}
function work_row(array $w, array $files): array {
    return ['id' => (int)$w['id'], 'user_id' => (int)$w['user_id'], 'title' => $w['title'], 'category' => $w['category'],
            'description' => (string)$w['description'], 'work_date' => $w['work_date'], 'link_url' => $w['link_url'],
            'created_at' => $w['created_at'], 'updated_at' => $w['updated_at'], 'files' => $files];
}
// Loads works (with their files) for a list of work rows.
function attach_files(PDO $pdo, array $works): array {
    if (!$works) return [];
    $ids = array_map(fn($w) => (int)$w['id'], $works);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT id, work_id, original_name, size FROM work_files WHERE work_id IN ($in) ORDER BY id");
    $st->execute($ids);
    $by = [];
    foreach ($st->fetchAll() as $f) $by[(int)$f['work_id']][] = file_row($f);
    return array_map(fn($w) => work_row($w, $by[(int)$w['id']] ?? []), $works);
}
function delete_stored_files(array $names): void {
    foreach ($names as $n) {
        if (preg_match('/^[a-f0-9]{32}\.[a-z0-9]{2,5}$/', $n)) @unlink(UPLOAD_DIR . '/' . $n);
    }
}
function ini_bytes(string $v): int {
    $v = trim($v); $n = (int)$v; $u = strtolower(substr($v, -1));
    return $u === 'g' ? $n * 1073741824 : ($u === 'm' ? $n * 1048576 : ($u === 'k' ? $n * 1024 : $n));
}


// ---------------------------------------------------------------- shop helpers
const PAY_METHODS = ['card' => 'Card', 'cod' => 'Cash on delivery', 'emi' => 'EMI', 'online' => 'Online (scan and pay)'];
const EXPENSE_CATEGORIES = ['Advertising', 'Salaries', 'Software & tools', 'Equipment', 'Rent & utilities', 'Travel', 'Other'];

function shop_db(): PDO {
    require_once __DIR__ . '/schema_shop.php';
    $pdo = portfolio_db();
    try { ensure_shop_schema($pdo); }
    catch (PDOException $e) { fail('Could not prepare the shop tables: ' . $e->getMessage(), 500); }
    return $pdo;
}
// Money is calculated in paise (whole numbers) so there are no rounding surprises.
function paise($x): int { return (int)round(((float)$x) * 100); }
function rupees(int $p): float { return round($p / 100, 2); }
function order_no(int $id): string { return 'NML-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT); }

function all_settings(PDO $pdo): array {
    $out = [];
    foreach ($pdo->query('SELECT k, v FROM settings')->fetchAll() as $r) $out[$r['k']] = (string)$r['v'];
    return $out;
}
function set_setting(PDO $pdo, string $k, string $v): void {
    $pdo->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')->execute([$k, $v]);
}
// What customers (and the admin page) may know about payments. Nothing secret is in here.
function payment_options(array $s): array {
    $months = array_values(array_filter(array_map('intval', explode(',', $s['emi_months'] ?? '')), fn($m) => $m >= 2 && $m <= 36));
    $hasQr = ($s['qr_file'] ?? '') !== '';
    $upi = $s['upi_id'] ?? '';
    return [
        'cod'    => ['on' => ($s['pay_cod'] ?? '1') === '1'],
        'emi'    => ['on' => ($s['pay_emi'] ?? '1') === '1' && $months, 'rate' => (float)($s['emi_rate'] ?? 0), 'months' => $months, 'min_amount' => (float)($s['emi_min'] ?? 0)],
        'online' => ['on' => ($s['pay_online'] ?? '1') === '1' && ($hasQr || $upi !== ''), 'upi_id' => $upi, 'has_qr' => $hasQr, 'note' => $s['qr_note'] ?? ''],
    ];
}
// EMI: returns [monthly_paise, total_paise] for an amount (in paise), months and yearly rate in percent.
function emi_calc(int $principal, int $months, float $annualRate): array {
    if ($annualRate <= 0) { $monthly = (int)round($principal / $months); }
    else {
        $r = $annualRate / 12 / 100;
        $monthly = (int)round($principal * $r * pow(1 + $r, $months) / (pow(1 + $r, $months) - 1));
    }
    return [$monthly, $monthly * $months];
}
