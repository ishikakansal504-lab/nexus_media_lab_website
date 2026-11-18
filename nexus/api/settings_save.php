<?php
// Admin only: save the payment settings. Sent as multipart/form-data because it can carry the scanner (QR) image.
require __DIR__ . '/db.php';
require_post();
require_admin();
$pdo = shop_db();

if (!$_POST && !$_FILES && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) fail('The image is too big for the server. Use a smaller picture.', 413);

$flag = fn(string $k) => ((string)($_POST[$k] ?? '0')) === '1' ? '1' : '0';
$rate = is_numeric($_POST['emi_rate'] ?? null) ? (float)$_POST['emi_rate'] : -1;
if ($rate < 0 || $rate > 60) fail('EMI interest must be between 0 and 60 percent a year.');
$min = is_numeric($_POST['emi_min'] ?? null) ? (float)$_POST['emi_min'] : -1;
if ($min < 0 || $min > 10000000) fail('Enter the smallest order amount allowed for EMI (0 or more).');
$months = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_POST['emi_months'] ?? ''))), fn($m) => $m >= 2 && $m <= 36)));
sort($months);
if (count($months) > 8) fail('Use at most 8 EMI periods.');
if (!$months && $flag('pay_emi') === '1') fail('Type at least one EMI period in months, for example 3,6,9,12, or switch EMI off.');
$upi = trim((string)($_POST['upi_id'] ?? ''));
if ($upi !== '' && !preg_match('/^[A-Za-z0-9._\-]{2,64}@[A-Za-z0-9.\-]{2,40}$/', $upi)) fail('The UPI ID should look like name@bank.');
$note = clean_line((string)($_POST['qr_note'] ?? ''), 200);

// The scanner picture (optional). A new one replaces the old one.
$newQr = null; $removeQr = ((string)($_POST['remove_qr'] ?? '0')) === '1';
if (!empty($_FILES['qr']) && $_FILES['qr']['error'] !== UPLOAD_ERR_NO_FILE) {
    $f = $_FILES['qr'];
    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) fail('The scanner picture is too big. Use a file under 2 MB.', 413);
    if ($f['error'] !== UPLOAD_ERR_OK) fail('Upload of the scanner picture failed. Please try again.');
    $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true)) fail('The scanner picture must be a png, jpg or webp image.');
    if ($f['size'] > 2 * 1048576) fail('The scanner picture is bigger than 2 MB.');
    if (!is_uploaded_file($f['tmp_name']) || !sniff_ok($f['tmp_name'], $ext)) fail('That file does not look like a real ' . $ext . ' image.');
    if (!is_dir(UPLOAD_DIR) && !mkdir(UPLOAD_DIR, 0775, true)) fail('Cannot create the uploads folder. Check folder permissions.', 500);
    $newQr = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], UPLOAD_DIR . '/' . $newQr)) fail('Could not save the picture. Check that the storage folder is writable.', 500);
}

// Order-confirmation messages: email (SMTP) and WhatsApp Business. A blank password / token means "keep the saved one".
$mc = [];
$host = trim((string)($_POST['smtp_host'] ?? ''));
if ($host !== '' && !preg_match('/^[A-Za-z0-9.\-]{3,100}$/', $host)) fail('The mail server name looks wrong. For Gmail use smtp.gmail.com.');
$port = (int)($_POST['smtp_port'] ?? 587) ?: 587;
if ($port < 1 || $port > 65535) fail('The mail server port must be a number such as 587.');
$secure = (string)($_POST['smtp_secure'] ?? 'tls');
if (!in_array($secure, ['tls', 'ssl', 'none'], true)) fail('Choose tls, ssl or none for the mail connection.');
$suser = trim((string)($_POST['smtp_user'] ?? '')); $sfrom = trim((string)($_POST['smtp_from'] ?? ''));
foreach ([$suser, $sfrom] as $addr) if ($addr !== '' && !filter_var($addr, FILTER_VALIDATE_EMAIL)) fail('The mail username and "send from" address must be email addresses.');
$spass = (string)($_POST['smtp_pass'] ?? '');
$spass = preg_replace('/\s+/', '', $spass);          // Google shows app passwords with spaces
$mc = ['smtp_host' => $host, 'smtp_port' => (string)$port, 'smtp_secure' => $secure, 'smtp_user' => $suser, 'smtp_from' => $sfrom];
if ($spass !== '') $mc['smtp_pass'] = $spass;
$wpid = trim((string)($_POST['wa_phone_id'] ?? '')); $wtpl = trim((string)($_POST['wa_template'] ?? '')); $wlang = trim((string)($_POST['wa_lang'] ?? 'en')) ?: 'en';
if ($wpid !== '' && !preg_match('/^[0-9]{5,25}$/', $wpid)) fail('The WhatsApp Phone number ID is a long number (from Meta).');
if ($wtpl !== '' && !preg_match('/^[a-z0-9_]{1,80}$/', $wtpl)) fail('The WhatsApp template name uses small letters, numbers and underscores only.');
if (!preg_match('/^[a-z]{2,3}(_[A-Za-z]{2})?$/', $wlang)) fail('The template language looks like en or en_US.');
$wtoken = trim((string)($_POST['wa_token'] ?? ''));
$mc += ['wa_phone_id' => $wpid, 'wa_template' => $wtpl, 'wa_lang' => $wlang];
if ($wtoken !== '') $mc['wa_token'] = $wtoken;

$old = (string)(all_settings($pdo)['qr_file'] ?? '');
try {
    $pdo->beginTransaction();
    foreach (['pay_cod' => $flag('pay_cod'), 'pay_emi' => $flag('pay_emi'), 'pay_online' => $flag('pay_online'), 'online_auto' => $flag('online_auto'), 
              'emi_rate' => (string)round($rate, 2), 'emi_months' => implode(',', $months), 'emi_min' => (string)round($min, 2), 'upi_id' => $upi, 'qr_note' => $note] + $mc as $k => $v) set_setting($pdo, $k, $v);
    if ($newQr) set_setting($pdo, 'qr_file', $newQr);
    elseif ($removeQr) set_setting($pdo, 'qr_file', '');
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($newQr) delete_stored_files([$newQr]);
    fail('Could not save the settings. Please try again.', 500);
}
if (($newQr || $removeQr) && $old !== '') delete_stored_files([$old]);
out(['ok' => true]);
