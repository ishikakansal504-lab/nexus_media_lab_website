<?php
// Admin only: fills the shop with SAMPLE customers, orders and expenses so the reports have something to show
// in a demonstration, and removes them again with one click. Sample rows are marked is_demo (orders, expenses)
// or use the email ending @demo.nexus.test (customers), so real data is never touched.
require __DIR__ . '/db.php';
require_post();
require_admin();
$pdo = shop_db();
$action = str_field(body(), 'action');

if ($action === 'remove') {
    $pdo->beginTransaction();
    $o = $pdo->exec('DELETE FROM orders WHERE is_demo = 1');
    $e = $pdo->exec('DELETE FROM expenses WHERE is_demo = 1');
    $u = $pdo->exec("DELETE FROM users WHERE email LIKE '%@demo.nexus.test' AND role = 'customer'");
    $pdo->commit();
    out(['ok' => true, 'removed' => ['orders' => $o, 'expenses' => $e, 'customers' => $u]]);
}
if ($action !== 'load') fail('Unknown action.');
if ((int)$pdo->query('SELECT COUNT(*) FROM orders WHERE is_demo = 1')->fetchColumn() > 0) fail('Sample data is already loaded. Remove it first if you want a fresh set.');

mt_srand(2026);
$services = $pdo->query('SELECT * FROM services WHERE active = 1')->fetchAll();
if (!$services) fail('Add at least one service first.');
$opt = payment_options(all_settings($pdo));

$names = ['Aarav Mehta', 'Diya Sharma', 'Kabir Singh', 'Meera Nair', 'Rohan Gupta', 'Sana Khan', 'Vikram Rao', 'Ananya Das'];
$custs = [];
$pdo->beginTransaction();
$insU = $pdo->prepare("INSERT IGNORE INTO users (name, email, password_hash, role) VALUES (?, ?, ?, 'customer')");
foreach ($names as $i => $n) {
    $mail = 'demo' . ($i + 1) . '@demo.nexus.test';
    $insU->execute([$n, $mail, password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT)]);   // nobody knows these passwords
    $st = $pdo->prepare('SELECT id FROM users WHERE email = ?'); $st->execute([$mail]);
    $custs[] = ['id' => (int)$st->fetchColumn(), 'name' => $n, 'email' => $mail];
}

$insO = $pdo->prepare('INSERT INTO orders (user_id, customer_name, customer_email, subtotal, interest, total, cost_total, payment_method, payment_status, status, emi_months, emi_monthly, pay_ref, is_demo, created_at)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1,?)');
$insI = $pdo->prepare('INSERT INTO order_items (order_id, service_id, name, unit, unit_price, unit_cost, qty) VALUES (?,?,?,?,?,?,?)');
$nOrders = 0;
for ($i = 0; $i < 95; $i++) {
    $ago = $i < 6 ? mt_rand(0, 6) : (int)(pow(mt_rand() / mt_getrandmax(), 1.6) * 420);
    $when = (new DateTime('today'))->modify("-$ago days")->setTime(mt_rand(9, 20), mt_rand(0, 59), mt_rand(0, 59));
    if ($when > new DateTime()) $when = new DateTime();
    $c = $custs[mt_rand(0, count($custs) - 1)];
    $pick = []; $k = mt_rand(1, 2);
    $keys = array_rand($services, min($k, count($services))); $keys = (array)$keys;
    $sub = 0; $cost = 0;
    foreach ($keys as $key) {
        $s = $services[$key]; $q = in_array($s['unit'], ['per month', 'per video'], true) ? mt_rand(1, 2) : 1;
        $pick[] = [$s, $q]; $sub += paise($s['price']) * $q; $cost += paise($s['cost']) * $q;
    }
    $r = mt_rand(1, 100);
    $method = $r <= 50 ? 'online' : ($r <= 80 ? 'cod' : 'emi');
    if ($method === 'emi' && (!$opt['emi']['months'] || $sub < paise($opt['emi']['min_amount']))) $method = 'cod';
    $status = mt_rand(1, 100) <= 7 ? 'cancelled' : ($ago <= 6 ? ['placed', 'confirmed', 'completed'][mt_rand(0, 2)] : 'completed');
    $interest = 0; $months = null; $monthly = null;
    if ($method === 'emi') {
        $months = $opt['emi']['months'][mt_rand(0, count($opt['emi']['months']) - 1)];
        [$m, $tot] = emi_calc($sub, $months, $opt['emi']['rate']); $interest = $tot - $sub; $monthly = rupees($m);
    }
    if ($status === 'cancelled') $pay = 'pending';
    elseif ($method === 'online') $pay = mt_rand(1, 100) <= 90 ? 'paid' : 'pending';
    elseif ($method === 'cod') $pay = $status === 'completed' ? 'paid' : 'pending';
    else $pay = ($status === 'completed' && mt_rand(1, 100) <= 70) ? 'paid' : 'pending';
    $ref = $method === 'online' ? 'DEMO' . mt_rand(100000, 999999) : '';
    $insO->execute([$c['id'], $c['name'], $c['email'], rupees($sub), rupees($interest), rupees($sub + $interest), rupees($cost), $method, $pay, $status, $months, $monthly, $ref, $when->format('Y-m-d H:i:s')]);
    $oid = (int)$pdo->lastInsertId();
    foreach ($pick as [$s, $q]) $insI->execute([$oid, $s['id'], $s['name'], $s['unit'], $s['price'], $s['cost'], $q]);
    $nOrders++;
}

$insE = $pdo->prepare('INSERT INTO expenses (expense_date, category, amount, note, is_demo) VALUES (?,?,?,?,1)');
$nExp = 0;
for ($m = 0; $m < 14; $m++) {
    $first = (new DateTime('first day of this month'))->modify("-$m months");
    $day = fn(int $d) => min((new DateTime($first->format('Y-m-d')))->modify('+' . ($d - 1) . ' days'), new DateTime('today'))->format('Y-m-d');
    foreach ([['Salaries', 28000 + mt_rand(0, 9000), 'Team stipends', 28], ['Rent & utilities', 6500, 'Office rent and internet', 5], ['Software & tools', 2400, 'Design and scheduling tools', 10],
              ['Advertising', 3000 + mt_rand(0, 6000), 'Own page promotion', 15]] as [$cat, $amt, $note, $d]) {
        $insE->execute([$day($d), $cat, $amt, $note]); $nExp++;
    }
}
$pdo->commit();
out(['ok' => true, 'added' => ['customers' => count($custs), 'orders' => $nOrders, 'expenses' => $nExp]]);
