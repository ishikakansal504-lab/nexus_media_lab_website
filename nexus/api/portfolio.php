<?php
// GET: one person's portfolio (profile + works). Owner by default; the admin may pass ?user_id=
require __DIR__ . '/db.php';
$me = require_staff();
$pdo = portfolio_db();
$uid = (int)$me['id'];
if (isset($_GET['user_id']) && (int)$_GET['user_id'] !== $uid) {
    if ($me['role'] !== 'admin') fail('You can only see your own portfolio.', 403);
    $uid = (int)$_GET['user_id'];
}
$st = $pdo->prepare('SELECT id,name,email,role,status,created_at,designation,department,admin_note FROM users WHERE id = ?'); $st->execute([$uid]);
$owner = $st->fetch();
if (!$owner) fail('User not found.', 404);

$st = $pdo->prepare('SELECT * FROM profiles WHERE user_id = ?'); $st->execute([$uid]);
$p = $st->fetch() ?: [];
$profile = [];
foreach (['headline','bio','location','phone','skills','education','experience','linkedin','github','website'] as $k) $profile[$k] = (string)($p[$k] ?? '');
$profile['updated_at'] = $p['updated_at'] ?? null;

$st = $pdo->prepare('SELECT * FROM works WHERE user_id = ? ORDER BY COALESCE(work_date, DATE(created_at)) DESC, id DESC');
$st->execute([$uid]);
$works = attach_files($pdo, $st->fetchAll());

// The track record: what this person has done so far, worked out from their work items.
$cats = []; $files = 0; $dates = [];
foreach ($works as $w) {
    $cats[$w['category']] = ($cats[$w['category']] ?? 0) + 1; $files += count($w['files']);
    $dates[] = $w['work_date'] ?: substr($w['created_at'], 0, 10);
}
sort($dates);
$joined = new DateTime(substr($owner['created_at'], 0, 10));
$record = ['works' => count($works), 'files' => $files, 'categories' => $cats, 'first_work' => $dates[0] ?? null, 'last_work' => $dates ? end($dates) : null,
           'days_with_us' => (int)$joined->diff(new DateTime('today'))->days];
$ownerOut = ['id' => (int)$owner['id'], 'name' => $owner['name'], 'email' => $owner['email'], 'role' => $owner['role'], 'status' => $owner['status'], 'joined' => $owner['created_at'],
             'code' => emp_code($owner), 'designation' => $owner['designation'], 'department' => $owner['department']];
if ($me['role'] === 'admin') $ownerOut['admin_note'] = (string)$owner['admin_note'];   // private: never sent to the employee
out(['ok' => true, 'owner' => $ownerOut, 'record' => $record,
     'profile' => $profile, 'works' => $works,
     'editable' => $uid === (int)$me['id'], 'categories' => WORK_CATEGORIES,
     'limits' => ['max_file_mb' => MAX_FILE_MB, 'max_files' => MAX_FILES, 'types' => array_keys(FILE_TYPES)]]);
