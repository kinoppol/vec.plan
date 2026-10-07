<?php
declare(strict_types=1);

/**
 * Phase-1 acceptance tests (spec §14). Runs against a throwaway database.
 *
 *   php tests/run.php [--host=127.0.0.1] [--port=3306] [--user=root] [--pass=] [--db=vec_plan_test]
 *
 * The test database is dropped and recreated on every run.
 */
if (PHP_SAPI !== 'cli') exit("CLI only\n");

require dirname(__DIR__) . '/app/bootstrap.php';

$opt = getopt('', ['host::', 'port::', 'user::', 'pass::', 'db::', 'filter::']);
$conf = [
    'host' => $opt['host'] ?? '127.0.0.1', 'port' => (int)($opt['port'] ?? 3306),
    'user' => $opt['user'] ?? 'root', 'pass' => $opt['pass'] ?? '', 'name' => $opt['db'] ?? 'vec_plan_test',
];
if (!preg_match('/_test$/', $conf['name'])) exit("Refusing to use a database whose name does not end in _test\n");

$_SESSION = [];
$server = make_pdo($conf, false);
$server->exec('DROP DATABASE IF EXISTS `' . $conf['name'] . '`');
$server->exec('CREATE DATABASE `' . $conf['name'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo = make_pdo($conf);
db($pdo);

// ------------------------------------------------------------------ tiny test harness
$passed = 0;
$failed = [];
function test(string $name, callable $fn): void
{
    global $passed, $failed, $opt;
    if (!empty($opt['filter']) && stripos($name, $opt['filter']) === false) return;
    try {
        $fn();
        $passed++;
        echo "  \033[32m✓\033[0m {$name}\n";
    } catch (Throwable $e) {
        $failed[] = $name;
        echo "  \033[31m✗ {$name}\033[0m\n    " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    }
}
function ok($cond, string $msg = 'assertion failed'): void
{
    if (!$cond) throw new RuntimeException($msg);
}
function eq($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) throw new RuntimeException(($msg ? $msg . ': ' : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}
function throws(callable $fn, string $contains = ''): Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        if ($contains !== '' && mb_stripos($e->getMessage(), $contains) === false) {
            throw new RuntimeException('exception message "' . $e->getMessage() . '" does not contain "' . $contains . '"');
        }
        return $e;
    }
    throw new RuntimeException('expected an exception' . ($contains ? ' containing "' . $contains . '"' : ''));
}
function as_user(int $id): void
{
    $_SESSION['uid'] = $id;
}

// ------------------------------------------------------------------ fixtures
echo "Setup\n";
$migrator = new Migrator($pdo);
test('all migrations apply on an empty database', function () use ($migrator) {
    $r = $migrator->migrate(null);
    ok($r && !array_filter($r, fn($x) => !$x['ok']), 'migration failed: ' . json_encode($r, JSON_UNESCAPED_UNICODE));
    eq([], $migrator->pending());
});
test('rollback of the last batch and re-apply both succeed (every @down is valid)', function () use ($migrator) {
    $back = $migrator->rollback();
    ok($back && !array_filter($back, fn($x) => !$x['ok']), 'rollback failed: ' . json_encode($back, JSON_UNESCAPED_UNICODE));
    ok(count($migrator->pending()) === count($migrator->files()), 'not everything rolled back');
    $r = $migrator->migrate(null);
    ok(!array_filter($r, fn($x) => !$x['ok']), 're-apply failed');
});

$pdo->exec("UPDATE institutions SET name = 'วิทยาลัยทดสอบ' WHERE id = 1");
$fyId = tx(fn() => Seeder::base($pdo, 1));
$fy = fiscal_year($fyId);
$mkUser = function (string $username, array $roles) use ($pdo, $fyId): int {
    $pdo->prepare('INSERT INTO users (institution_id, username, name, password_hash) VALUES (1, ?, ?, ?)')->execute([$username, $username, password_hash('x', PASSWORD_DEFAULT)]);
    $id = (int)$pdo->lastInsertId();
    foreach ($roles as $r) $pdo->prepare('INSERT INTO role_assignments (user_id, role, fiscal_year_id) VALUES (?, ?, ?)')->execute([$id, $r, $fyId]);
    return $id;
};
$finance = $mkUser('t_finance', ['finance']);
$planner = $mkUser('t_planner', ['planner']);
$fund = function (string $code) use ($pdo, $fyId): int {
    $st = $pdo->prepare('SELECT id FROM fund_sources WHERE fiscal_year_id = ? AND code = ?');
    $st->execute([$fyId, $code]);
    return (int)$st->fetchColumn();
};
$unitId = (int)$pdo->query("SELECT id FROM org_units WHERE code = 'DEP-AUTO'")->fetchColumn();
$catId = (int)$pdo->query("SELECT id FROM expense_categories WHERE code = 'OPS-MAT'")->fetchColumn();
$mkProject = function (string $code) use ($pdo, $fyId, $unitId): int {
    $pdo->prepare("INSERT INTO projects (fiscal_year_id, code, title, org_unit_id, status) VALUES (?, ?, ?, ?, 'approved')")->execute([$fyId, $code, 'โครงการ ' . $code, $unitId]);
    return (int)$pdo->lastInsertId();
};
$day = $fy['starts_on'];
$pos = fn(string $code) => Ledger::fundPositions($fyId)['positions'][$fund($code)];
$post = function (array $e, int $user, ?array $override = null) use ($fyId, $day) {
    as_user($user);
    return Ledger::post($e + ['fiscal_year_id' => $fyId, 'entry_date' => $day], $user, $override);
};

// ------------------------------------------------------------------ money helpers
echo "Money helpers (BR-01 rounding)\n";
test('to_cents rounds half-up to 2 decimals', function () {
    eq(101, to_cents('1.005'));
    eq(268, to_cents('2.675'));
    eq(123456789, to_cents('1,234,567.89'));
    eq(30, to_cents(0.1 + 0.2));
    eq(-1250, to_cents('-12.5'));
    eq('1234567.89', cents_str(123456789));
});

// ------------------------------------------------------------------ ledger rules
echo "Ledger\n";
test('BR-21 ledger_entries rejects UPDATE at the database level', function () use ($post, $finance, $fund, $pdo) {
    $e = $post(['entry_type' => 'receipt', 'fund_source_id' => $fund('INC-FEE'), 'amount' => to_cents('1000'), 'reference_no' => 'R-1', 'reference_date' => '2026-10-01'], $finance);
    throws(fn() => $pdo->exec('UPDATE ledger_entries SET amount = 1 WHERE id = ' . (int)$e['id']), 'append-only');
});
test('BR-21 ledger_entries rejects DELETE at the database level', function () use ($pdo) {
    throws(fn() => $pdo->exec('DELETE FROM ledger_entries'), 'append-only');
});
test('BR-21 reversal returns balances to their previous values', function () use ($post, $finance, $fund, $pos) {
    $before = $pos('INC-ASSET');
    $e = $post(['entry_type' => 'receipt', 'fund_source_id' => $fund('INC-ASSET'), 'amount' => to_cents('5000.50'), 'reference_no' => 'R-2', 'reference_date' => '2026-10-01'], $finance);
    eq($before['pool_actual'] + 500050, $pos('INC-ASSET')['pool_actual']);
    Ledger::reverse((int)$e['id'], 'ผิดกองเงิน', $finance);
    $after = $pos('INC-ASSET');
    eq($before['pool_actual'], $after['pool_actual'], 'pool_actual');
    eq($before['receipts'], $after['receipts'], 'receipts net of reversal');
});
test('BR-21 an entry can be reversed only once, and a reversal cannot be reversed', function () use ($post, $finance, $fund, $pdo) {
    $e = $post(['entry_type' => 'receipt', 'fund_source_id' => $fund('INC-ASSET'), 'amount' => to_cents('10'), 'reference_no' => 'R-3', 'reference_date' => '2026-10-01'], $finance);
    $r = Ledger::reverse((int)$e['id'], 'ทดสอบ', $finance);
    throws(fn() => Ledger::reverse((int)$e['id'], 'ซ้ำ', $finance), 'ถูกกลับรายการแล้ว');
    throws(fn() => Ledger::reverse((int)$r['id'], 'กลับของกลับ', $finance), 'ไม่สามารถกลับรายการของรายการกลับ');
    // and the unique key guards it even if application code is bypassed
    throws(function () use ($pdo, $e, $r) {
        $pdo->exec("INSERT INTO ledger_entries (entry_no, fiscal_year_id, entry_date, entry_type, from_account_id, to_account_id, amount, reverses_entry_id, created_by)
            SELECT 'X-1', fiscal_year_id, entry_date, 'reversal', to_account_id, from_account_id, amount, id, created_by FROM ledger_entries WHERE id = " . (int)$e['id']);
    });
});
test('BR-23 receipt requires reference number and date', function () use ($post, $finance, $fund) {
    throws(fn() => $post(['entry_type' => 'receipt', 'fund_source_id' => $fund('DON'), 'amount' => 100], $finance), 'BR-23');
    throws(fn() => $post(['entry_type' => 'receipt', 'fund_source_id' => $fund('DON'), 'amount' => 100, 'reference_no' => 'ก-1'], $finance), 'BR-23');
});
test('BR-20 entries must use a leaf fund source', function () use ($post, $finance, $fund) {
    throws(fn() => $post(['entry_type' => 'receipt', 'fund_source_id' => $fund('SUB'), 'amount' => 100, 'reference_no' => 'x', 'reference_date' => '2026-10-01'], $finance), 'leaf');
});
test('entry date must fall inside the fiscal year', function () use ($fyId, $finance, $fund) {
    as_user($finance);
    throws(fn() => Ledger::post(['fiscal_year_id' => $fyId, 'entry_type' => 'carry_in', 'fund_source_id' => $fund('DON'), 'amount' => 100, 'entry_date' => '2026-09-30'], $finance), 'ปีงบประมาณ');
});
test('BR-22 allocate beyond the actual pool is blocked and reports the shortfall', function () use ($post, $planner, $finance, $fund, $mkProject) {
    $post(['entry_type' => 'carry_in', 'fund_source_id' => $fund('SUB-BOOK'), 'amount' => to_cents('100000')], $finance);
    $p = $mkProject('T-001');
    $e = throws(fn() => $post(['entry_type' => 'allocate', 'fund_source_id' => $fund('SUB-BOOK'), 'project_id' => $p, 'amount' => to_cents('100000.01')], $planner), 'BR-22');
    ok($e instanceof ApiError && abs($e->extra['short_by'] - 0.01) < 1e-9, 'short_by should be 0.01');
    ok($e->extra['overridable'] === true, 'allocate shortfall should be overridable');
});
test('BR-22 planner override (decision 2026-10-06) allows allocate with a recorded reason', function () use ($post, $planner, $fund, $mkProject, $pos, $pdo) {
    $p = $mkProject('T-002');
    $r = $post(['entry_type' => 'allocate', 'fund_source_id' => $fund('SUB-BOOK'), 'project_id' => $p, 'amount' => to_cents('150000')], $planner, ['reason' => 'หนังสือแจ้งจัดสรรแล้ว']);
    eq('หนังสือแจ้งจัดสรรแล้ว', $r['override_reason']);
    ok($pos('SUB-BOOK')['pool_actual'] < 0, 'pool should now be negative (warning state)');
    $st = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE action = 'ledger.post' AND subject_id = ?");
    $st->execute([$r['id']]);
    eq(1, (int)$st->fetchColumn(), 'audit row');
});
test('BR-22 override by a non-planner is refused', function () use ($post, $finance, $fund, $mkProject, $fyId, $pdo) {
    // finance temporarily gets the planner-only allocate path through Ledger directly
    $p = $mkProject('T-003');
    throws(fn() => $post(['entry_type' => 'allocate', 'fund_source_id' => $fund('SUB-BOOK'), 'project_id' => $p, 'amount' => 100], $finance, ['reason' => 'x']), 'งานแผน');
});
test('BR-22 reserve beyond the pool is blocked even with a reason (not overridable)', function () use ($post, $planner, $fund) {
    throws(fn() => $post(['entry_type' => 'reserve', 'fund_source_id' => $fund('SUB-BOOK'), 'reserve_name' => 'สำรอง', 'amount' => 100], $planner, ['reason' => 'x']), 'BR-22');
});
test('BR-22 project balance cannot go negative (transfer / return)', function () use ($post, $planner, $finance, $fund, $mkProject) {
    $post(['entry_type' => 'carry_in', 'fund_source_id' => $fund('SUB-ACT'), 'amount' => to_cents('50000')], $finance);
    $a = $mkProject('T-010');
    $b = $mkProject('T-011');
    $post(['entry_type' => 'allocate', 'fund_source_id' => $fund('SUB-ACT'), 'project_id' => $a, 'amount' => to_cents('20000')], $planner);
    throws(fn() => $post(['entry_type' => 'transfer', 'fund_source_id' => $fund('SUB-ACT'), 'from_project_id' => $a, 'to_project_id' => $b, 'amount' => to_cents('20000.01')], $planner), 'BR-22');
    throws(fn() => $post(['entry_type' => 'return', 'fund_source_id' => $fund('SUB-ACT'), 'project_id' => $a, 'amount' => to_cents('20001')], $planner, ['reason' => 'x']), 'BR-22');
    $post(['entry_type' => 'transfer', 'fund_source_id' => $fund('SUB-ACT'), 'from_project_id' => $a, 'to_project_id' => $b, 'amount' => to_cents('5000')], $planner);
});
test('BR-22 reversing a receipt that the pool has already allocated is blocked', function () use ($post, $finance, $planner, $fund, $mkProject) {
    $r = $post(['entry_type' => 'receipt', 'fund_source_id' => $fund('OTH'), 'amount' => to_cents('1000'), 'reference_no' => 'R-9', 'reference_date' => '2026-10-02'], $finance);
    $p = $mkProject('T-020');
    $post(['entry_type' => 'allocate', 'fund_source_id' => $fund('OTH'), 'project_id' => $p, 'amount' => to_cents('800')], $planner);
    throws(fn() => Ledger::reverse((int)$r['id'], 'ผิด', $finance), 'BR-22');
});
test('BR-24 pool_actual formula equals the fund_pool account balance', function () use ($post, $finance, $planner, $fund, $mkProject, $pos, $fyId) {
    $f = $fund('INC-FEE');
    $post(['entry_type' => 'carry_in', 'fund_source_id' => $f, 'amount' => to_cents('1000000')], $finance);
    $post(['entry_type' => 'receipt', 'fund_source_id' => $f, 'amount' => to_cents('250000.25'), 'reference_no' => 'R-10', 'reference_date' => '2026-10-02'], $finance);
    $post(['entry_type' => 'allocation_adjust_up', 'fund_source_id' => $f, 'amount' => to_cents('10000')], $finance);
    $post(['entry_type' => 'allocation_adjust_down', 'fund_source_id' => $f, 'amount' => to_cents('2500')], $finance);
    $post(['entry_type' => 'reserve', 'fund_source_id' => $f, 'reserve_name' => 'ฉุกเฉิน', 'amount' => to_cents('30000')], $planner);
    $post(['entry_type' => 'return', 'fund_source_id' => $f, 'from_reserve' => 'ฉุกเฉิน', 'amount' => to_cents('10000')], $planner);
    $p = $mkProject('T-030');
    $post(['entry_type' => 'allocate', 'fund_source_id' => $f, 'project_id' => $p, 'amount' => to_cents('400000')], $planner);
    $post(['entry_type' => 'return', 'fund_source_id' => $f, 'project_id' => $p, 'amount' => to_cents('15000')], $planner);
    $post(['entry_type' => 'spend', 'fund_source_id' => $f, 'project_id' => $p, 'amount' => to_cents('99.99'), 'expense_category_id' => (int)db()->query("SELECT id FROM expense_categories WHERE code='OPS-MAT'")->fetchColumn()], $planner);
    $x = $pos('INC-FEE');
    $formula = $x['carry_in'] + $x['receipts'] + $x['adjust_up'] - $x['adjust_down'] - $x['reserved'] - $x['allocated'] - $x['carry_out'];
    $pool = Ledger::account($fyId, 'fund_pool', $f);
    eq(Ledger::balance((int)$pool['id']), $formula, 'formula vs account balance');
    // expected by hand: 1,000 (receipt R-1 in the BR-21 test) + 1,000,000 + 250,000.25 + 10,000 − 2,500
    //                  − (30,000 − 10,000) − (400,000 − 15,000) = 853,500.25
    eq(85350025, $x['pool_actual']);
    eq(38500000, $x['allocated']);
    $pb = Ledger::projectBalances($fyId, [$p])[$p][$f];
    eq(38500000, $pb['allocated'], 'BR-25 allocated');
    eq(9999, $pb['spent'], 'BR-25 spent');
    eq(38500000 - 9999, $pb['free'], 'BR-25 free');
});
test('BR-10 pool_estimate replaces receipts with current estimates', function () use ($pdo, $fyId, $fund, $pos, $planner) {
    $f = $fund('INC-FEE');
    $pdo->prepare('INSERT INTO revenue_estimates (fiscal_year_id, fund_source_id, version, label, amount, is_current) VALUES (?, ?, 1, ?, ?, 1), (?, ?, 2, ?, ?, 0)')
        ->execute([$fyId, $f, 'ภาค 1', '600000.00', $fyId, $f, 'ฉบับใหม่ (ยังไม่ใช้)', '999999.00']);
    $x = $pos('INC-FEE');
    eq(60000000, $x['estimate'], 'only current version counts');
    eq($x['pool_actual'] - $x['receipts'] + 60000000, $x['pool_estimate']);
});

// ------------------------------------------------------------------ concurrency
echo "Concurrency\n";
test('BR-22 two concurrent allocations competing for the same money: exactly one succeeds', function () use ($post, $finance, $fund, $mkProject, $conf, $fyId, $planner, $pos) {
    $f = $fund('SUB-UNIFORM');
    $post(['entry_type' => 'carry_in', 'fund_source_id' => $f, 'amount' => to_cents('100000')], $finance);
    $p1 = $mkProject('T-RACE-1');
    $p2 = $mkProject('T-RACE-2');
    $cmd = function (int $project) use ($conf, $fyId, $f, $planner) {
        return [PHP_BINARY, __DIR__ . '/race_worker.php', json_encode($conf), (string)$fyId, (string)$f, (string)$project, (string)$planner];
    };
    $procs = [];
    foreach ([$p1, $p2] as $p) {
        $procs[] = proc_open($cmd($p), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $outs[] = $pipes;
    }
    $results = [];
    foreach ($procs as $i => $proc) {
        $results[] = trim(stream_get_contents($outs[$i][1]) . stream_get_contents($outs[$i][2]));
        proc_close($proc);
    }
    $okCount = count(array_filter($results, fn($r) => str_starts_with($r, 'OK')));
    eq(1, $okCount, 'results: ' . implode(' | ', $results));
    eq(to_cents('40000'), $pos('SUB-UNIFORM')['pool_actual']);
});

// ------------------------------------------------------------------ import
echo "Plan import\n";
test('Excel import of 100 projects: totals per fund match the file', function () use ($fyId, $planner, $finance, $post, $fund, $pdo) {
    $funds = ['BUD-VC', 'BUD-HVC', 'SUB-TEACH', 'INC-ASSET'];
    $rows = [PlanImport::HEADERS];
    $expected = [];
    for ($i = 1; $i <= 100; $i++) {
        $fcode = $funds[$i % 4];
        $amt = 10000 + $i * 137.55;
        $rows[] = ['IMP-' . str_pad((string)$i, 3, '0', STR_PAD_LEFT), 'โครงการนำเข้า ' . $i, $i % 2 ? 'DEP-AUTO' : 'งานทะเบียน', $fcode, $i % 3 ? 'OPS-MAT' : 'ค่าใช้สอย', $amt, (string)(1 + $i % 4)];
        $expected[$fcode] = ($expected[$fcode] ?? 0) + to_cents($amt);
        if ($i % 10 === 0) { // second budget line for the same project, different fund
            $rows[] = ['IMP-' . str_pad((string)$i, 3, '0', STR_PAD_LEFT), 'โครงการนำเข้า ' . $i, $i % 2 ? 'DEP-AUTO' : 'งานทะเบียน', 'SUB-TEACH', 'OPS-SERV', 1000, (string)(1 + $i % 4)];
            $expected['SUB-TEACH'] = ($expected['SUB-TEACH'] ?? 0) + 100000;
        }
    }
    $file = tempnam(sys_get_temp_dir(), 'imp') . '.xlsx';
    file_put_contents($file, Xlsx::build($rows));
    $read = PlanImport::readFile($file, 'plan.xlsx');
    eq(count($rows), count($read), 'xlsx round trip row count');
    as_user($planner);
    $a = PlanImport::analyze($fyId, $read);
    eq([], $a['errors']);
    eq(100, count($a['projects']));
    $byCode = [];
    foreach ($a['totals'] as $t) $byCode[$t['fund_code']] = $t['amount'];
    foreach ($expected as $code => $amt) eq($amt, $byCode[$code] ?? null, 'analysis total ' . $code);
    // no cash yet → needs override
    throws(fn() => PlanImport::commit($fyId, $a, $planner, null), 'BR-22');
    $created = PlanImport::commit($fyId, $a, $planner, 'นำเข้าแผนตั้งต้นก่อนรับเงิน');
    eq(100, count($created));
    $pos = Ledger::fundPositions($fyId)['positions'];
    $st = $pdo->prepare("SELECT fa.fund_source_id, SUM(e.amount) FROM ledger_entries e JOIN ledger_accounts fa ON fa.id = e.from_account_id
        WHERE e.source_type = 'plan_import' GROUP BY fa.fund_source_id");
    $st->execute();
    $ledgerTotals = [];
    foreach ($st->fetchAll(PDO::FETCH_NUM) as [$fid, $sum]) $ledgerTotals[(int)$fid] = to_cents($sum);
    foreach ($expected as $code => $amt) eq($amt, $ledgerTotals[$fund($code)] ?? null, 'ledger allocate total ' . $code);
    @unlink($file);
});
test('import validation reports unknown unit / non-leaf fund / bad amount / duplicate code', function () use ($fyId, $planner) {
    as_user($planner);
    $a = PlanImport::analyze($fyId, [
        PlanImport::HEADERS,
        ['IMP-001', 'ซ้ำกับที่นำเข้าแล้ว', 'DEP-AUTO', 'BUD-VC', 'OPS-MAT', '100', '1'],
        ['', 'หน่วยงานผิด', 'ไม่มีหน่วยนี้', 'BUD-VC', 'OPS-MAT', '100', '1'],
        ['', 'แหล่งเงินไม่ใช่ leaf', 'DEP-AUTO', 'SUB', 'OPS-MAT', '100', '1'],
        ['', 'ยอดผิด', 'DEP-AUTO', 'BUD-VC', 'OPS-MAT', 'abc', '5'],
    ]);
    $msgs = implode("\n", array_column($a['errors'], 'message'));
    foreach (['มีอยู่แล้ว', 'หน่วยงาน', 'แหล่งเงิน', 'ยอดอนุมัติไม่ใช่ตัวเลข', 'ไตรมาส'] as $needle) ok(mb_strpos($msgs, $needle) !== false, 'missing error: ' . $needle . "\n" . $msgs);
});
test('BR-06 import rejects a category not allowed for the fund', function () use ($fyId, $planner, $fund, $pdo) {
    $cat = (int)$pdo->query("SELECT id FROM expense_categories WHERE code = 'OPS-MAT'")->fetchColumn();
    $pdo->prepare('INSERT INTO fund_source_allowed_categories (fund_source_id, expense_category_id) VALUES (?, ?)')->execute([$fund('DON'), $cat]);
    as_user($planner);
    $a = PlanImport::analyze($fyId, [PlanImport::HEADERS, ['', 'ทดสอบ', 'DEP-AUTO', 'DON', 'OPS-SERV', '100', '1']]);
    ok(mb_strpos(implode(' ', array_column($a['errors'], 'message')), 'BR-06') !== false, 'expected BR-06 error');
});
test('BR-15 import is refused after the baseline is locked', function () use ($fyId, $planner, $pdo) {
    $pdo->prepare('UPDATE fiscal_years SET baseline_locked_at = NOW() WHERE id = ?')->execute([$fyId]);
    as_user($planner);
    $a = PlanImport::analyze($fyId, [PlanImport::HEADERS, ['', 'หลังล็อก', 'DEP-AUTO', 'BUD-VC', 'OPS-MAT', '100', '1']]);
    throws(fn() => PlanImport::commit($fyId, $a, $planner, 'x'), 'BR-15');
    $pdo->prepare('UPDATE fiscal_years SET baseline_locked_at = NULL WHERE id = ?')->execute([$fyId]);
});

// ------------------------------------------------------------------ misc
echo "Infrastructure\n";
test('SQL splitter keeps semicolons inside strings and drops comments', function () {
    $parts = Migrator::splitSql("-- c1\nINSERT INTO t VALUES ('a;b'); /* x; */ SELECT \"q;\";\n# hash; comment\nSELECT 1");
    eq(3, count($parts));
    eq("INSERT INTO t VALUES ('a;b')", $parts[0]);
});
test('Access: unit-scoped roles see only their unit subtree', function () use ($pdo, $fyId, $unitId, $mkProject) {
    $pdo->prepare('INSERT INTO users (institution_id, username, name, password_hash) VALUES (1, ?, ?, ?)')->execute(['t_head', 'หัวหน้า', 'x']);
    $uid = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO role_assignments (user_id, role, org_unit_id, fiscal_year_id) VALUES (?, 'unit_head', ?, ?)")->execute([$uid, $unitId, $fyId]);
    as_user($uid);
    $u = current_user();
    [$sql, $params] = Access::projectScope($u, $fyId);
    $st = $pdo->prepare("SELECT COUNT(*) FROM projects p WHERE p.fiscal_year_id = ? AND NOT ({$sql}) AND p.org_unit_id = ?");
    $st->execute(array_merge([$fyId], $params, [$unitId]));
    eq(0, (int)$st->fetchColumn(), 'all projects of own unit visible');
    $st = $pdo->prepare("SELECT COUNT(*) FROM projects p WHERE p.fiscal_year_id = ? AND ({$sql}) AND p.org_unit_id <> ?");
    $st->execute(array_merge([$fyId], $params, [$unitId]));
    eq(0, (int)$st->fetchColumn(), 'no projects of other units');
});

// ------------------------------------------------------------------ AI assistant
echo "AI assistant\n";
// Fake provider: answers with the queued assistant messages in order and records each request payload.
$aiScript = function (array $replies): ArrayObject {
    $log = new ArrayObject();
    Assistant::$transport = function ($method, $url, $payload) use (&$replies, $log) {
        $log[] = $payload;
        return ['choices' => [['message' => array_shift($replies) ?? ['role' => 'assistant', 'content' => 'จบ']]]];
    };
    return $log;
};
$aiCall = fn(string $id, string $name, array $args) => ['role' => 'assistant', 'content' => null,
    'tool_calls' => [['id' => $id, 'type' => 'function', 'function' => ['name' => $name, 'arguments' => json_encode($args)]]]];
$aiReceipt = fn() => ['entry_type' => 'receipt', 'fund_source_id' => $fund('INC-FEE'), 'amount' => 1234.5, 'reference_no' => 'AI-1', 'reference_date' => $day];
$aiTools = fn(array $messages) => array_values(array_filter($messages, fn($m) => $m['role'] === 'tool'));
$proposer = $mkUser('t_proposer', ['proposer']);
$mkConn = function (int $inst, string $name, bool $enabled, array $models, string $key = '') use ($pdo): int {
    $pdo->prepare('INSERT INTO ai_connections (institution_id, name, provider, base_url, api_key, enabled, models, default_model) VALUES (?, ?, \'custom\', ?, ?, ?, ?, ?)')
        ->execute([$inst, $name, 'http://' . strtolower($name) . '.test/v1', Assistant::encrypt($key), $enabled ? 1 : 0, json_encode($models), $models[0] ?? null]);
    return (int)$pdo->lastInsertId();
};
$connA = $mkConn(1, 'Main', true, ['test-model', 'other-model'], 'sk-main');
$connOff = $mkConn(1, 'Spare', false, ['spare-model']);

test('API keys are stored encrypted and decrypt back', function () {
    $enc = Assistant::encrypt('sk-secret-1234');
    ok(str_starts_with($enc, 'enc1:') && !str_contains($enc, 'secret'), 'not encrypted');
    eq('sk-secret-1234', Assistant::decrypt($enc));
    eq('', Assistant::decrypt('enc1:' . base64_encode(str_repeat('x', 40))), 'tampered value');
});
test('several connections: users pick an enabled connection and one of its enabled models', function () use ($finance, $connA, $connOff) {
    as_user($finance);
    $c = Assistant::config();
    eq($connA, $c['id']);
    eq('test-model', $c['model'], 'default model');
    eq('sk-main', $c['api_key']);
    eq('other-model', Assistant::config($connA, 'other-model')['model']);
    throws(fn() => Assistant::config($connA, 'gpt-not-enabled'), 'ไม่ได้เปิดใช้งาน');
    throws(fn() => Assistant::config($connOff, 'spare-model'), 'ถูกปิด');
    $info = Assistant::publicInfo();
    ok($info['enabled']);
    eq([$connA], array_column($info['connections'], 'id'), 'disabled connection hidden');
    ok(!str_contains(json_encode($info), 'sk-main') && !str_contains(json_encode($info), 'http'), 'no key or URL sent to the browser');
});
test('tools offered follow the user\'s permissions', function () use ($fyId, $finance, $proposer) {
    as_user($finance);
    $t = Assistant::toolsFor($fyId, true);
    ok(isset($t['post_ledger_entry'], $t['get_fund_positions'], $t['list_ledger_entries']), 'finance tools');
    ok(!isset($t['list_users']), 'finance is not an admin');
    ok(!isset(Assistant::toolsFor($fyId, false)['post_ledger_entry']), 'read-only mode hides write tools');
    as_user($proposer);
    $t = Assistant::toolsFor($fyId, true);
    ok(isset($t['list_projects']) && !isset($t['post_ledger_entry']) && !isset($t['get_fund_positions']), 'proposer tools');
    ok(str_contains(json_encode(Assistant::schema($t)), '"properties":{}'), 'parameterless tools use an empty JSON object');
});
test('read tools run through the API as the user; writes wait for confirmation', function () use ($fyId, $finance, $aiScript, $aiCall, $aiReceipt, $aiTools, $pos) {
    as_user($finance);
    $_GET['fy'] = $fyId;
    $before = $pos('INC-FEE')['receipts'];
    $aiScript([$aiCall('c1', 'get_fund_positions', []), $aiCall('c2', 'post_ledger_entry', $aiReceipt())]);
    $r = Assistant::chat([['role' => 'user', 'content' => 'บันทึกรับเงิน']], [], []);
    eq(1, count($r['pending']));
    eq('c2', $r['pending'][0]['id']);
    eq($before, $pos('INC-FEE')['receipts'], 'nothing posted before confirmation');
    ok(str_contains($aiTools($r['messages'])[0]['content'], 'pool_actual'), 'fund positions returned to the model');

    $aiScript([['role' => 'assistant', 'content' => 'บันทึกแล้ว']]);
    $r2 = Assistant::chat($r['messages'], ['c2' => true], []);
    ok($r2['changed'], 'changed flag');
    eq($before + 123450, $pos('INC-FEE')['receipts']);
    eq('บันทึกแล้ว', end($r2['messages'])['content']);
});
test('a rejected action is not executed and the model is told so', function () use ($finance, $aiScript, $aiCall, $aiReceipt, $pos) {
    as_user($finance);
    $before = $pos('INC-FEE')['receipts'];
    $log = $aiScript([$aiCall('c3', 'post_ledger_entry', $aiReceipt()), ['role' => 'assistant', 'content' => 'ยกเลิกแล้ว']]);
    $r = Assistant::chat([['role' => 'user', 'content' => 'บันทึกรับเงิน']], [], []);
    $r2 = Assistant::chat($r['messages'], ['c3' => false], []);
    ok(!$r2['changed']);
    eq($before, $pos('INC-FEE')['receipts']);
    ok(str_contains(json_encode($log[count($log) - 1]['messages'], JSON_UNESCAPED_UNICODE), 'ไม่อนุญาต'));
});
test('a forged write call by a user without the permission is refused', function () use ($proposer, $aiScript, $aiCall, $aiReceipt, $aiTools, $pos) {
    as_user($proposer);
    $before = $pos('INC-FEE')['receipts'];
    $aiScript([['role' => 'assistant', 'content' => 'ok']]);
    $r = Assistant::chat([['role' => 'user', 'content' => 'x'], $aiCall('c9', 'post_ledger_entry', $aiReceipt())], ['c9' => true], []);
    ok(!$r['changed']);
    eq($before, $pos('INC-FEE')['receipts']);
    ok(str_contains($aiTools($r['messages'])[0]['content'], 'ไม่มีสิทธิ์'));
});
test('unanswered tool calls in the history are closed before a new message', function () use ($finance, $aiScript, $aiCall) {
    as_user($finance);
    $log = $aiScript([['role' => 'assistant', 'content' => 'สวัสดี']]);
    Assistant::chat([['role' => 'user', 'content' => 'a'], $aiCall('c5', 'post_ledger_entry', []), ['role' => 'user', 'content' => 'b']], [], []);
    eq(['system', 'user', 'assistant', 'tool', 'user'], array_column($log[0]['messages'], 'role'));
    Assistant::$transport = null;
    unset($_GET['fy']);
});

// ------------------------------------------------------------------ multi-institution
echo "Multi-institution\n";
// Second institution with its own master data, admin and finance user.
$pdo->exec("INSERT INTO institutions (code, name) VALUES ('B', 'วิทยาลัยบี')");
$instB = (int)$pdo->lastInsertId();
$fyB = tx(fn() => Seeder::base($pdo, $instB));
$pdo->prepare('INSERT INTO users (institution_id, username, name, password_hash) VALUES (?, ?, ?, ?)')->execute([$instB, 'b_finance', 'การเงินบี', 'x']);
$financeB = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO role_assignments (user_id, role) VALUES (?, 'finance')")->execute([$financeB]);
$pdo->prepare('INSERT INTO users (institution_id, username, name, password_hash) VALUES (?, ?, ?, ?)')->execute([$instB, 'b_admin', 'แอดมินบี', 'x']);
$adminB = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO role_assignments (user_id, role) VALUES (?, 'admin')")->execute([$adminB]);

test('each institution gets its own fiscal year, units and categories with the same codes', function () use ($pdo, $fyId, $fyB, $instB) {
    ok($fyId !== $fyB);
    $st = $pdo->prepare("SELECT COUNT(*) FROM org_units WHERE institution_id = ? AND code = 'DEP-AUTO'");
    $st->execute([$instB]);
    eq(1, (int)$st->fetchColumn());
    eq(2, (int)$pdo->query("SELECT COUNT(*) FROM expense_categories WHERE code = 'OPS-MAT'")->fetchColumn());
});
test('a user cannot open a fiscal year of another institution', function () use ($financeB, $fyId, $fyB) {
    as_user($financeB);
    eq($fyB, current_fiscal_year_id());
    throws(fn() => fiscal_year($fyId), 'ไม่พบปีงบประมาณ');
    $_GET['fy'] = (string)$fyId;
    throws(fn() => request_fy(), 'ไม่พบปีงบประมาณ');
    unset($_GET['fy']);
});
test('a user cannot post to another institution\'s fiscal year', function () use ($financeB, $fyId, $fund) {
    as_user($financeB);
    throws(fn() => Ledger::post(['fiscal_year_id' => $fyId, 'entry_type' => 'carry_in', 'fund_source_id' => $fund('SUB-TEACH'), 'amount' => 100], $financeB), 'ไม่พบปีงบประมาณ');
});
test('ledger numbers and settings are per institution', function () use ($financeB, $fyB, $pdo) {
    as_user($financeB);
    $st = $pdo->prepare("SELECT id FROM fund_sources WHERE fiscal_year_id = ? AND code = 'DON'");
    $st->execute([$fyB]);
    $e = Ledger::post(['fiscal_year_id' => $fyB, 'entry_type' => 'carry_in', 'fund_source_id' => (int)$st->fetchColumn(), 'amount' => 100,
        'entry_date' => fiscal_year($fyB)['starts_on']], $financeB);
    eq('LG70-00001', $e['entry_no'], 'institution B starts its own sequence');
    eq((string)$fyB, setting('current_fiscal_year_id'));
});
test('each institution has its own AI connections', function () use ($financeB, $finance, $instB, $connA, $mkConn) {
    as_user($financeB);
    ok(!Assistant::publicInfo()['enabled'], 'B sees none of A\'s connections');
    throws(fn() => Assistant::connection($connA), 'ไม่พบ');
    throws(fn() => Assistant::config($connA, 'test-model'), 'ยังไม่ได้เปิดใช้งาน');
    $connB = $mkConn($instB, 'BeeAI', true, ['bee-model'], 'sk-bee');
    eq('bee-model', Assistant::config()['model']);
    eq('sk-bee', Assistant::config()['api_key']);
    as_user($finance);
    ok(!in_array($connB, array_column(Assistant::publicInfo()['connections'], 'id'), true), 'A does not see B\'s connection');
});
test('Access subtree and audit stay inside the institution', function () use ($financeB, $pdo, $instB) {
    as_user($financeB);
    // A division of institution 1 has children there, but none are visible from institution B.
    $acd1 = (int)$pdo->query("SELECT id FROM org_units WHERE institution_id = 1 AND code = 'ACD'")->fetchColumn();
    eq([$acd1], Access::subtree([$acd1]));
    $st = $pdo->prepare("SELECT id FROM org_units WHERE institution_id = ? AND code = 'ACD'");
    $st->execute([$instB]);
    ok(count(Access::subtree([(int)$st->fetchColumn()])) > 1, 'own division subtree has children');
    audit('test.event');
    eq($instB, (int)$pdo->query('SELECT institution_id FROM audit_logs ORDER BY id DESC LIMIT 1')->fetchColumn());
});
test('a central admin has no institution and no fiscal year', function () use ($pdo) {
    $pdo->prepare('INSERT INTO users (institution_id, username, name, password_hash) VALUES (NULL, ?, ?, ?)')->execute(['central', 'กลาง', 'x']);
    $id = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO role_assignments (user_id, role) VALUES (?, 'super_admin')")->execute([$id]);
    as_user($id);
    ok(is_super_admin());
    eq(null, current_institution_id());
    eq(null, current_fiscal_year_id());
    throws(fn() => request_fy(), 'ไม่ได้สังกัดสถานศึกษา');
    set_setting('tenancy_mode', 'multi', 0);
    ok(is_multi() && is_system_admin());
    set_setting('tenancy_mode', 'single', 0);
    ok(!is_system_admin(), 'in single mode the system admin is the institution admin');
});

// ------------------------------------------------------------------ users from RMS
echo "RMS user transfer\n";
$rmsAdmin = $mkUser('t_rms_admin', ['admin']);
// Fake RMS server: the people list and one picture (a 1×1 PNG); records requested URLs.
$rmsPeople = [];
$rmsLog = new ArrayObject();
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
RmsSync::$transport = function (string $url) use (&$rmsPeople, $rmsLog, $png) {
    $rmsLog[] = $url;
    if (str_contains($url, RmsSync::PEOPLE_PATH)) return json_encode($rmsPeople, JSON_UNESCAPED_UNICODE);
    if (str_contains($url, '/files/')) return $png;
    throw new ApiError('unexpected ' . $url);
};
$person = fn(string $id, string $name, string $surname, array $extra = []) => $extra + ['people_id' => $id, 'people_name' => $name, 'people_surname' => $surname,
    'people_email' => '', 'people_pic' => '', 'people_exit' => '0'];
$rmsUser = function (string $username) use ($pdo) {
    $st = $pdo->prepare('SELECT * FROM users WHERE username = ?');
    $st->execute([$username]);
    return $st->fetch() ?: null;
};

test('RMS base URL is an institution setting and the path stays in code', function () use ($rmsAdmin, $financeB, $rmsLog, $person, &$rmsPeople) {
    as_user($rmsAdmin);
    throws(fn() => RmsSync::normalizeBase('rms.rvc.ac.th'), 'http');
    eq('http://rms.example.test', RmsSync::normalizeBase(' http://rms.example.test/ '));
    set_setting('rms_base_url', 'http://rms.example.test');
    as_user($financeB);
    eq('', RmsSync::baseUrl(), 'another institution has its own (empty) address');
    as_user($rmsAdmin);
    $rmsPeople = [$person('1000000000001', 'นายหนึ่ง', 'ทดสอบ')];
    RmsSync::sync(true);
    eq('http://rms.example.test/api_connection.php?app_name=nutty&data=people', $rmsLog[0]);
});
test('RMS transfer: only people_exit = 0, mapped fields, hashed password, avatar downloaded', function () use ($rmsAdmin, $person, $rmsUser, $rmsLog, &$rmsPeople) {
    as_user($rmsAdmin);
    $rmsPeople = [
        $person('1000000000001', 'นายหนึ่ง', 'ทดสอบ', ['ath_pass' => 'pass-one-123', 'people_email' => 'One@Example.test', 'people_pic' => 'p1.png']),
        $person('1000000000002', 'นางสองคน', 'ไม่มีรหัส', ['people_email' => 'not-an-email']),
        $person('1000000000003', 'นายลาออก', 'แล้ว', ['people_exit' => '1']),
    ];
    $preview = RmsSync::sync(true);
    eq([3, 1, 2, 0], [$preview['total'], $preview['exited'], $preview['created'], $preview['updated']]);
    ok(!$rmsUser('1000000000001'), 'preview writes nothing');
    $r = RmsSync::sync(false);
    eq([2, 1, 1], [$r['created'], $r['avatars'], $r['no_password']]);
    $u = $rmsUser('1000000000001');
    eq('นายหนึ่ง ทดสอบ', $u['name']);
    eq('one@example.test', $u['email']);
    eq(1, (int)$u['institution_id']);
    eq('rms', $u['source']);
    ok(password_verify('pass-one-123', $u['password_hash']) && $u['password_hash'] !== 'pass-one-123', 'password stored hashed');
    ok($u['avatar_path'] && is_file(UPLOAD_DIR . '/' . $u['avatar_path']), 'avatar saved');
    ok(in_array('http://rms.example.test/files/p1.png', (array)$rmsLog, true), 'picture URL = base + /files/ + people_pic');
    $two = $rmsUser('1000000000002');
    ok($two && $two['email'] === null && $two['avatar_path'] === null, 'bad email dropped, no picture → initials');
    ok(!$rmsUser('1000000000003'), 'people_exit = 1 not transferred');
});
test('RMS transfer again updates the same user and keeps created_at', function () use ($rmsAdmin, $person, $rmsUser, $pdo, &$rmsPeople) {
    as_user($rmsAdmin);
    $pdo->exec("UPDATE users SET created_at = '2020-01-02 03:04:05' WHERE username = '1000000000001'");
    $before = $rmsUser('1000000000001');
    $rmsPeople = [$person('1000000000001', 'นายหนึ่ง', 'เปลี่ยนนามสกุล', ['ath_pass' => 'pass-one-123', 'people_pic' => 'p1.png'])];
    $r = RmsSync::sync(false);
    eq([0, 1, 0, 0], [$r['created'], $r['updated'], $r['avatars'], $r['password_updated']], 'unchanged picture and password are left alone');
    $u = $rmsUser('1000000000001');
    eq('2020-01-02 03:04:05', $u['created_at']);
    eq('นายหนึ่ง เปลี่ยนนามสกุล', $u['name']);
    eq((int)$before['id'], (int)$u['id']);
    eq($before['avatar_path'], $u['avatar_path']);
    $rmsPeople = [$person('1000000000001', 'นายหนึ่ง', 'เปลี่ยนนามสกุล', ['ath_pass' => 'new-pass-456'])];
    $r = RmsSync::sync(false);
    eq(1, $r['password_updated']);
    $u = $rmsUser('1000000000001');
    ok(password_verify('new-pass-456', $u['password_hash']));
    ok($u['avatar_path'] === null && !is_file(UPLOAD_DIR . '/' . $before['avatar_path']), 'picture removed in RMS → back to initials');
});
test('RMS transfer never takes over a username of another institution', function () use ($rmsAdmin, $person, $pdo, $instB, &$rmsPeople) {
    as_user($rmsAdmin);
    $rmsPeople = [$person('b_finance', 'นายแฝง', 'ตัว', ['ath_pass' => 'x-pass-789'])];
    $r = RmsSync::sync(false);
    eq([0, 0, 1], [$r['created'], $r['updated'], $r['skipped']]);
    eq($instB, (int)$pdo->query("SELECT institution_id FROM users WHERE username = 'b_finance'")->fetchColumn());
    RmsSync::$transport = null;
    rrmdir_contents(UPLOAD_DIR . '/avatars/i1');
    @rmdir(UPLOAD_DIR . '/avatars/i1');
});
test('Backup dump contains tables, data and the ledger triggers', function () use ($pdo) {
    $r = Backup::create($pdo, 'test');
    $sql = gzdecode(file_get_contents(BACKUP_DIR . '/' . $r['file']));
    ok(str_contains($sql, 'CREATE TABLE `ledger_entries`'));
    ok(str_contains($sql, 'INSERT INTO `ledger_entries`'));
    ok(str_contains($sql, 'trg_ledger_entries_no_update'));
    ok(strpos($sql, 'trg_ledger_entries_no_update') > strpos($sql, 'INSERT INTO `ledger_entries`'), 'triggers after data');
    unlink(BACKUP_DIR . '/' . $r['file']);
});
test('fiscal-year backup holds only the chosen year and restores into an empty database', function () use ($pdo, $fyId, $fyB, $conf, $server) {
    // A second year of institution 1 that must stay out of the dump.
    $pdo->exec("INSERT INTO fiscal_years (institution_id, year_be, starts_on, ends_on) VALUES (1, 2571, '2027-10-01', '2028-09-30')");
    $fy2571 = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO fund_sources (fiscal_year_id, code, name) VALUES (?, 'ONLY-2571', 'แหล่งเงินปี 2571')")->execute([$fy2571]);
    throws(fn() => Backup::create($pdo, 'x', [$fyId, $fyB]), 'หนึ่งสถานศึกษา');
    $r = Backup::create($pdo, 'x', [$fyId]);
    ok(str_contains($r['file'], 'fy2570_MAIN'), 'file name carries year and institution: ' . $r['file']);
    $sql = gzdecode(file_get_contents(BACKUP_DIR . '/' . $r['file']));
    ok(!str_contains($sql, 'DROP TABLE'), 'no DROP TABLE in a year backup');
    ok(!str_contains($sql, 'ONLY-2571'), 'other year left out');
    ok(!str_contains($sql, 'วิทยาลัยบี'), 'other institution left out');
    ok(str_contains($sql, '-- scope: ปีงบประมาณ 2570'));

    $restore = $conf + ['name' => 'vec_plan_restore_test'];
    $restore['name'] = 'vec_plan_restore_test';
    $server->exec('DROP DATABASE IF EXISTS vec_plan_restore_test');
    $server->exec('CREATE DATABASE vec_plan_restore_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $rp = make_pdo($restore);
    foreach (Migrator::splitSql($sql) as $stmt) $rp->exec($stmt);
    $count = fn(PDO $p, string $q) => (int)$p->query($q)->fetchColumn();
    eq(1, $count($rp, 'SELECT COUNT(*) FROM institutions'));
    eq(1, $count($rp, 'SELECT COUNT(*) FROM fiscal_years'));
    eq($count($pdo, "SELECT COUNT(*) FROM ledger_entries WHERE fiscal_year_id = {$fyId}"), $count($rp, 'SELECT COUNT(*) FROM ledger_entries'));
    eq($count($pdo, "SELECT COUNT(*) FROM projects WHERE fiscal_year_id = {$fyId}"), $count($rp, 'SELECT COUNT(*) FROM projects'));
    eq($count($pdo, "SELECT COUNT(*) FROM budget_lines bl JOIN projects p ON p.id = bl.project_id WHERE p.fiscal_year_id = {$fyId}"), $count($rp, 'SELECT COUNT(*) FROM budget_lines'));
    eq($count($pdo, 'SELECT COUNT(*) FROM org_units WHERE institution_id = 1'), $count($rp, 'SELECT COUNT(*) FROM org_units'));
    eq(0, $count($rp, "SELECT COUNT(*) FROM settings WHERE skey = 'current_fiscal_year_id'"));
    // The restored ledger is still append-only.
    throws(fn() => $rp->exec('DELETE FROM ledger_entries'), 'append-only');
    // Restoring a year backup over a database that already has the tables stops at the first CREATE TABLE.
    throws(fn() => $rp->exec(Migrator::splitSql($sql)[2] ?? ''), 'already exists');
    $server->exec('DROP DATABASE vec_plan_restore_test');
    unlink(BACKUP_DIR . '/' . $r['file']);
});

echo "\n" . $passed . ' passed, ' . count($failed) . " failed\n";
$server->exec('DROP DATABASE IF EXISTS `' . $conf['name'] . '`');
exit($failed ? 1 : 0);
