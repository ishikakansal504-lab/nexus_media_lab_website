<?php
// Admin only: add or delete a business expense (used in the profit and loss report).
require __DIR__ . '/db.php';
require_post();
require_admin();
$pdo = shop_db();
$b = body();
$action = str_field($b, 'action');

if ($action === 'add') {
    $date = str_field($b, 'date');
    $d = DateTime::createFromFormat('Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date) fail('Enter a valid date.');
    $cat = str_field($b, 'category');
    if (!in_array($cat, EXPENSE_CATEGORIES, true)) fail('Choose a valid category.');
    $amt = is_numeric($b['amount'] ?? null) ? (float)$b['amount'] : 0;
    if ($amt <= 0 || $amt > 100000000) fail('Enter an amount greater than 0.');
    $note = clean_line(str_field($b, 'note'), 200);
    $pdo->prepare('INSERT INTO expenses (expense_date, category, amount, note) VALUES (?,?,?,?)')->execute([$date, $cat, round($amt, 2), $note]);
    out(['ok' => true]);
}
if ($action === 'delete') {
    $pdo->prepare('DELETE FROM expenses WHERE id = ?')->execute([(int)($b['id'] ?? 0)]);
    out(['ok' => true]);
}
fail('Unknown action.');
