<?php
// Add or update one work item. Sent as multipart/form-data (text fields + files[]).
require __DIR__ . '/db.php';
require_post();
$me = require_staff();
$pdo = portfolio_db();

// If the whole upload is bigger than PHP allows, PHP drops everything and $_POST is empty.
if (!$_POST && !$_FILES && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    fail('The files are too big. PHP allows ' . ini_get('post_max_size') . ' per upload. Start the site with run.sh (see HOW-TO-RUN.txt) or upload smaller files.', 413);
}

$id    = (int)($_POST['id'] ?? 0);
$title = clean_line((string)($_POST['title'] ?? ''), 120);
$cat   = (string)($_POST['category'] ?? 'Other');
$desc  = clean_block((string)($_POST['description'] ?? ''), 5000);
$date  = trim((string)($_POST['work_date'] ?? ''));
$link  = trim((string)($_POST['link_url'] ?? ''));
$remove = array_filter(array_map('intval', explode(',', (string)($_POST['remove'] ?? ''))));

if (text_len($title) < 2) fail('Give your work a title (at least 2 characters).');
if (!in_array($cat, WORK_CATEGORIES, true)) fail('Choose a valid category.');
if ($date !== '') {
    $d = DateTime::createFromFormat('Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date) fail('Enter a valid date.');
} else { $date = null; }
if ($link !== '' && !url_ok($link)) fail('The link must start with http:// or https://');

// Collect the uploaded files (the field is files[])
$new = [];
if (!empty($_FILES['files']) && is_array($_FILES['files']['name'])) {
    foreach ($_FILES['files']['name'] as $i => $name) {
        $err = $_FILES['files']['error'][$i];
        if ($err === UPLOAD_ERR_NO_FILE) continue;
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) fail('"' . $name . '" is too big (limit ' . MAX_FILE_MB . ' MB, PHP allows ' . ini_get('upload_max_filesize') . ').', 413);
        if ($err !== UPLOAD_ERR_OK) fail('Upload of "' . $name . '" failed. Please try again.');
        $new[] = ['name' => (string)$name, 'tmp' => $_FILES['files']['tmp_name'][$i], 'size' => (int)$_FILES['files']['size'][$i]];
    }
}
foreach ($new as $f) {
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!isset(FILE_TYPES[$ext])) fail('"' . $f['name'] . '" is not allowed. Allowed: ' . implode(', ', array_keys(FILE_TYPES)) . '.');
    if ($f['size'] > MAX_FILE_MB * 1048576) fail('"' . $f['name'] . '" is bigger than ' . MAX_FILE_MB . ' MB.');
    if ($f['size'] === 0) fail('"' . $f['name'] . '" is empty.');
    if (!is_uploaded_file($f['tmp']) || !sniff_ok($f['tmp'], $ext)) fail('"' . $f['name'] . '" does not look like a real .' . $ext . ' file.');
}

$moved = []; $toDelete = [];
try {
    $pdo->beginTransaction();
    if ($id > 0) {
        $st = $pdo->prepare('SELECT id FROM works WHERE id = ? AND user_id = ? FOR UPDATE'); $st->execute([$id, $me['id']]);
        if (!$st->fetch()) { $pdo->rollBack(); fail('Work not found.', 404); }
        $pdo->prepare('UPDATE works SET title=?, category=?, description=?, work_date=?, link_url=? WHERE id=?')
            ->execute([$title, $cat, $desc, $date, $link, $id]);
        if ($remove) {
            $in = implode(',', array_fill(0, count($remove), '?'));
            $st = $pdo->prepare("SELECT id, stored_name FROM work_files WHERE work_id = ? AND id IN ($in)");
            $st->execute(array_merge([$id], $remove));
            $rows = $st->fetchAll();
            foreach ($rows as $r) { $toDelete[] = $r['stored_name']; }
            if ($rows) {
                $ids = array_map(fn($r) => (int)$r['id'], $rows);
                $pdo->prepare('DELETE FROM work_files WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')')->execute($ids);
            }
        }
    } else {
        $pdo->prepare('INSERT INTO works (user_id, title, category, description, work_date, link_url) VALUES (?,?,?,?,?,?)')
            ->execute([$me['id'], $title, $cat, $desc, $date, $link]);
        $id = (int)$pdo->lastInsertId();
    }

    $st = $pdo->prepare('SELECT COUNT(*) FROM work_files WHERE work_id = ?'); $st->execute([$id]);
    if ((int)$st->fetchColumn() + count($new) > MAX_FILES) {
        $pdo->rollBack(); fail('A work item can have at most ' . MAX_FILES . ' files.');
    }
    if ($new && !is_dir(UPLOAD_DIR) && !mkdir(UPLOAD_DIR, 0775, true)) { $pdo->rollBack(); fail('Cannot create the uploads folder. Check folder permissions.', 500); }
    foreach ($new as $f) {
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        $stored = bin2hex(random_bytes(16)) . '.' . $ext;
        if (!move_uploaded_file($f['tmp'], UPLOAD_DIR . '/' . $stored)) { throw new RuntimeException('Could not save "' . $f['name'] . '". Check that the storage folder is writable.'); }
        $moved[] = $stored;
        $orig = clean_line(basename(str_replace('\\', '/', $f['name'])), 150);
        $pdo->prepare('INSERT INTO work_files (work_id, original_name, stored_name, mime, size) VALUES (?,?,?,?,?)')
            ->execute([$id, $orig, $stored, FILE_TYPES[$ext][1], $f['size']]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    delete_stored_files($moved);
    fail($e instanceof RuntimeException ? $e->getMessage() : 'Could not save your work. Please try again.', 500);
}
delete_stored_files($toDelete);
out(['ok' => true, 'id' => $id]);
