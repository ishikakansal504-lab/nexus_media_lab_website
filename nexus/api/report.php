<?php
// Admin only: sales, cost, expenses and profit or loss for any period.
// ?from=YYYY-MM-DD&to=YYYY-MM-DD&basis=all|paid&group=auto|day|week|month|year
require __DIR__ . '/db.php';
require_admin();
$pdo = shop_db();

function valid_day(string $s): bool { $d = DateTime::createFromFormat('Y-m-d', $s); return $d && $d->format('Y-m-d') === $s; }
$today = new DateTime('today');
$from = (string)($_GET['from'] ?? ''); $to = (string)($_GET['to'] ?? '');
if (!valid_day($from)) $from = $today->format('Y-m-01');
if (!valid_day($to))   $to = $today->format('Y-m-d');
if ($from > $to) { [$from, $to] = [$to, $from]; }
$days = (int)(new DateTime($from))->diff(new DateTime($to))->days + 1;
if ($days > 3660) fail('Please choose a period of 10 years or less.');
$paidOnly = (string)($_GET['basis'] ?? 'all') === 'paid';
$group = (string)($_GET['group'] ?? 'auto');
if (!in_array($group, ['day', 'week', 'month', 'year'], true)) $group = $days <= 31 ? 'day' : ($days <= 180 ? 'week' : ($days <= 1100 ? 'month' : 'year'));

// Orders that count as sales: not cancelled, not refunded (and, if asked, only the ones already paid).
$counts = "o.status <> 'cancelled' AND o.payment_status <> 'refunded'" . ($paidOnly ? " AND o.payment_status = 'paid'" : '');
function range_args(string $a, string $b): array { return [$a . ' 00:00:00', $b]; }
$inRange = 'o.created_at >= ? AND o.created_at < DATE_ADD(?, INTERVAL 1 DAY)';

function period_totals(PDO $pdo, string $from, string $to, string $counts, string $inRange): array {
    $st = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(o.total),0) rev, COALESCE(SUM(o.cost_total),0) cost, COALESCE(SUM(o.interest),0) interest
                         FROM orders o WHERE $counts AND $inRange");
    $st->execute(range_args($from, $to)); $r = $st->fetch();
    $ex = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date BETWEEN ? AND ?');
    $ex->execute([$from, $to]);
    $rev = paise($r['rev']); $cost = paise($r['cost']); $exp = paise($ex->fetchColumn());
    $gross = $rev - $cost; $net = $gross - $exp;
    return ['orders' => (int)$r['n'], 'revenue' => rupees($rev), 'cost' => rupees($cost), 'interest' => rupees(paise($r['interest'])),
            'gross_profit' => rupees($gross), 'expenses' => rupees($exp), 'net_profit' => rupees($net),
            'margin_pct' => $rev > 0 ? round($net * 100 / $rev, 1) : null,
            'avg_order' => (int)$r['n'] > 0 ? rupees((int)round($rev / (int)$r['n'])) : 0];
}
$totals = period_totals($pdo, $from, $to, $counts, $inRange);

// The period just before this one, of the same length, for comparison.
$prevTo = (new DateTime($from))->modify('-1 day')->format('Y-m-d');
$prevFrom = (new DateTime($from))->modify('-' . $days . ' days')->format('Y-m-d');
$previous = period_totals($pdo, $prevFrom, $prevTo, $counts, $inRange) + ['from' => $prevFrom, 'to' => $prevTo];

// ---- series (one bar per day, week, month or year) ----
$ob = ['day' => 'DATE(o.created_at)', 'week' => 'DATE_SUB(DATE(o.created_at), INTERVAL WEEKDAY(o.created_at) DAY)',
       'month' => "DATE_FORMAT(o.created_at, '%Y-%m-01')", 'year' => "DATE_FORMAT(o.created_at, '%Y-01-01')"][$group];
$eb = ['day' => 'expense_date', 'week' => 'DATE_SUB(expense_date, INTERVAL WEEKDAY(expense_date) DAY)',
       'month' => "DATE_FORMAT(expense_date, '%Y-%m-01')", 'year' => "DATE_FORMAT(expense_date, '%Y-01-01')"][$group];
$st = $pdo->prepare("SELECT $ob b, COUNT(*) n, SUM(o.total) rev, SUM(o.cost_total) cost FROM orders o WHERE $counts AND $inRange GROUP BY b");
$st->execute(range_args($from, $to));
$byB = [];
foreach ($st->fetchAll() as $r) $byB[$r['b']] = ['n' => (int)$r['n'], 'rev' => paise($r['rev']), 'cost' => paise($r['cost']), 'exp' => 0];
$st = $pdo->prepare("SELECT $eb b, SUM(amount) a FROM expenses WHERE expense_date BETWEEN ? AND ? GROUP BY b");
$st->execute([$from, $to]);
foreach ($st->fetchAll() as $r) { $byB[$r['b']] = ($byB[$r['b']] ?? ['n' => 0, 'rev' => 0, 'cost' => 0, 'exp' => 0]); $byB[$r['b']]['exp'] = paise($r['a']); }

$cur = new DateTime($from);
if ($group === 'week')  $cur->modify('-' . ((int)$cur->format('N') - 1) . ' days');
if ($group === 'month') $cur = new DateTime($cur->format('Y-m-01'));
if ($group === 'year')  $cur = new DateTime($cur->format('Y-01-01'));
$step = ['day' => '+1 day', 'week' => '+7 days', 'month' => '+1 month', 'year' => '+1 year'][$group];
$series = []; $guard = 0; $end = new DateTime($to);
while ($cur <= $end && $guard++ < 400) {
    $k = $cur->format('Y-m-d'); $v = $byB[$k] ?? ['n' => 0, 'rev' => 0, 'cost' => 0, 'exp' => 0];
    $label = ['day' => $cur->format('d M'), 'week' => $cur->format('d M'), 'month' => $cur->format('M Y'), 'year' => $cur->format('Y')][$group];
    $series[] = ['key' => $k, 'label' => $label, 'orders' => $v['n'], 'revenue' => rupees($v['rev']), 'cost' => rupees($v['cost']),
                 'expenses' => rupees($v['exp']), 'profit' => rupees($v['rev'] - $v['cost'] - $v['exp'])];
    $cur->modify($step);
}
if ($guard >= 400) fail('That is too many bars to show. Choose a shorter period or a larger grouping (month or year).');

// ---- breakdowns ----
$st = $pdo->prepare("SELECT oi.name, SUM(oi.qty) q, SUM(oi.unit_price * oi.qty) rev, SUM(oi.unit_cost * oi.qty) cost
                     FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE $counts AND $inRange GROUP BY oi.name ORDER BY rev DESC");
$st->execute(range_args($from, $to));
$byService = array_map(fn($r) => ['name' => $r['name'], 'qty' => (int)$r['q'], 'revenue' => (float)$r['rev'], 'cost' => (float)$r['cost'],
                                  'profit' => rupees(paise($r['rev']) - paise($r['cost']))], $st->fetchAll());

$st = $pdo->prepare("SELECT o.payment_method m, COUNT(*) n, SUM(o.total) rev FROM orders o WHERE $counts AND $inRange GROUP BY o.payment_method ORDER BY rev DESC");
$st->execute(range_args($from, $to));
$byMethod = array_map(fn($r) => ['method' => $r['m'], 'label' => PAY_METHODS[$r['m']], 'orders' => (int)$r['n'], 'revenue' => (float)$r['rev']], $st->fetchAll());

$st = $pdo->prepare("SELECT o.customer_name nm, o.customer_email em, COUNT(*) n, SUM(o.total) rev FROM orders o WHERE $counts AND $inRange GROUP BY o.customer_email, o.customer_name ORDER BY rev DESC LIMIT 5");
$st->execute(range_args($from, $to));
$topCustomers = array_map(fn($r) => ['name' => $r['nm'], 'email' => $r['em'], 'orders' => (int)$r['n'], 'revenue' => (float)$r['rev']], $st->fetchAll());

$st = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(o.total),0) t FROM orders o WHERE o.status <> 'cancelled' AND o.payment_status = 'pending' AND $inRange");
$st->execute(range_args($from, $to)); $pend = $st->fetch();
$st = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(o.total),0) t FROM orders o WHERE (o.status = 'cancelled' OR o.payment_status = 'refunded') AND $inRange");
$st->execute(range_args($from, $to)); $lost = $st->fetch();

$st = $pdo->prepare('SELECT category, SUM(amount) a, COUNT(*) n FROM expenses WHERE expense_date BETWEEN ? AND ? GROUP BY category ORDER BY a DESC');
$st->execute([$from, $to]);
$expByCat = array_map(fn($r) => ['category' => $r['category'], 'amount' => (float)$r['a'], 'count' => (int)$r['n']], $st->fetchAll());
$st = $pdo->prepare('SELECT id, expense_date, category, amount, note, is_demo FROM expenses WHERE expense_date BETWEEN ? AND ? ORDER BY expense_date DESC, id DESC LIMIT 300');
$st->execute([$from, $to]);
$expenses = array_map(fn($r) => ['id' => (int)$r['id'], 'date' => $r['expense_date'], 'category' => $r['category'], 'amount' => (float)$r['amount'], 'note' => $r['note'], 'is_demo' => (int)$r['is_demo'] === 1], $st->fetchAll());

out(['ok' => true, 'from' => $from, 'to' => $to, 'days' => $days, 'group' => $group, 'basis' => $paidOnly ? 'paid' : 'all',
     'totals' => $totals, 'previous' => $previous, 'series' => $series, 'by_service' => $byService, 'by_method' => $byMethod,
     'top_customers' => $topCustomers, 'pending' => ['orders' => (int)$pend['n'], 'amount' => (float)$pend['t']],
     'lost' => ['orders' => (int)$lost['n'], 'amount' => (float)$lost['t']],
     'expenses_by_category' => $expByCat, 'expenses' => $expenses, 'expense_categories' => EXPENSE_CATEGORIES]);
