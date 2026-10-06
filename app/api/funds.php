<?php
declare(strict_types=1);

function require_estimate_role(int $fyId): array
{
    return require_role(['planner', 'finance'], $fyId);
}

function leaf_fund_or_fail(int $fundId, int $fyId): array
{
    $st = db()->prepare('SELECT * FROM fund_sources WHERE id = ? AND fiscal_year_id = ?');
    $st->execute([$fundId, $fyId]);
    $f = $st->fetch();
    if (!$f) fail('ไม่พบแหล่งเงินในปีงบประมาณนี้');
    if (!$f['is_leaf']) fail('ต้องเลือกแหล่งเงินระดับย่อยสุด');
    return $f;
}

return [
    'GET positions' => function () {
        $fyId = request_fy();
        require_role(Access::FUND_VIEWERS, $fyId);
        $out = FundReport::cards($fyId);
        // Reserve balances.
        $st = db()->prepare("SELECT la.id, la.reserve_name, la.fund_source_id, f.name AS fund_name, f.code AS fund_code,
                COALESCE((SELECT SUM(amount) FROM ledger_entries WHERE to_account_id = la.id), 0)
              - COALESCE((SELECT SUM(amount) FROM ledger_entries WHERE from_account_id = la.id), 0) AS balance
            FROM ledger_accounts la JOIN fund_sources f ON f.id = la.fund_source_id
            WHERE la.fiscal_year_id = ? AND la.kind = 'reserve' ORDER BY f.sort, la.reserve_name");
        $st->execute([$fyId]);
        $out['reserves'] = array_map(fn($r) => $r + ['balance_num' => cents_num(to_cents($r['balance']))], $st->fetchAll());
        $out['as_of'] = now();
        return $out;
    },

    'GET estimates' => function () {
        $fyId = request_fy();
        require_role(Access::FUND_VIEWERS, $fyId);
        $st = db()->prepare('SELECT e.*, f.code AS fund_code, f.name AS fund_name, u.name AS created_by_name
            FROM revenue_estimates e JOIN fund_sources f ON f.id = e.fund_source_id
            LEFT JOIN users u ON u.id = e.created_by
            WHERE e.fiscal_year_id = ? ORDER BY f.sort, f.id, e.version, e.installment_no, e.id');
        $st->execute([$fyId]);
        $rows = array_map(function ($r) {
            $r['basis'] = $r['basis'] ? json_decode($r['basis'], true) : null;
            $r['amount'] = cents_num(to_cents($r['amount']));
            $r['is_current'] = (bool)$r['is_current'];
            return $r;
        }, $st->fetchAll());
        // Received to date per leaf fund, for the "actual vs estimate" column.
        $pos = Ledger::fundPositions($fyId)['positions'];
        $received = [];
        foreach ($pos as $fid => $p) $received[$fid] = ['receipts' => cents_num($p['receipts']), 'estimate_to_date' => cents_num($p['estimate_to_date']), 'estimate' => cents_num($p['estimate'])];
        return ['rows' => $rows, 'received' => $received];
    },

    'POST estimate_save' => function () {
        $fyId = request_fy();
        $u = require_estimate_role($fyId);
        $b = body();
        $fund = leaf_fund_or_fail((int)($b['fund_source_id'] ?? 0), $fyId);
        $label = trim((string)($b['label'] ?? ''));
        if ($label === '') fail('กรุณาระบุรายการ');
        $month = isset($b['expected_month']) && $b['expected_month'] !== '' && $b['expected_month'] !== null ? (int)$b['expected_month'] : null;
        if ($month !== null && ($month < 1 || $month > 12)) fail('เดือนที่คาดว่าจะได้รับไม่ถูกต้อง');
        $basis = null;
        $b2 = $b['basis'] ?? null;
        if (is_array($b2) && array_filter($b2, fn($v) => $v !== '' && $v !== null)) {
            $students = (int)($b2['students'] ?? 0);
            $rate = to_cents($b2['rate'] ?? 0);
            $terms = (int)($b2['terms'] ?? 1);
            if ($students < 0 || $rate < 0 || $terms < 1) fail('ฐานคำนวณไม่ถูกต้อง');
            $basis = ['students' => $students, 'rate' => cents_num($rate), 'terms' => $terms];
            $amount = $students * $rate * $terms;
        } else {
            $amount = to_cents($b['amount'] ?? 0);
        }
        if ($amount < 0) fail('จำนวนเงินต้องไม่ติดลบ');
        $pdo = db();
        $id = (int)($b['id'] ?? 0);
        if ($id) {
            $st = $pdo->prepare('SELECT * FROM revenue_estimates WHERE id = ? AND fiscal_year_id = ?');
            $st->execute([$id, $fyId]);
            $before = $st->fetch();
            if (!$before) fail('ไม่พบรายการ', 404);
            $pdo->prepare('UPDATE revenue_estimates SET fund_source_id = ?, label = ?, installment_no = ?, expected_month = ?, basis = ?, amount = ? WHERE id = ?')
                ->execute([$fund['id'], $label, ($b['installment_no'] ?? '') === '' ? null : (int)$b['installment_no'], $month, json_col($basis), cents_str($amount), $id]);
            audit('estimate.update', 'revenue_estimate', $id, $before, ['label' => $label, 'amount' => cents_str($amount), 'basis' => $basis]);
        } else {
            // New rows go into the fund's current version (or version 1).
            $st = $pdo->prepare('SELECT MAX(version) FROM revenue_estimates WHERE fiscal_year_id = ? AND fund_source_id = ? AND is_current = 1');
            $st->execute([$fyId, $fund['id']]);
            $version = (int)($st->fetchColumn() ?: 0);
            if (!$version) {
                $st = $pdo->prepare('SELECT COALESCE(MAX(version), 0) + 1 FROM revenue_estimates WHERE fiscal_year_id = ? AND fund_source_id = ?');
                $st->execute([$fyId, $fund['id']]);
                $version = (int)$st->fetchColumn();
            }
            $pdo->prepare('INSERT INTO revenue_estimates (fiscal_year_id, fund_source_id, version, label, installment_no, expected_month, basis, amount, is_current, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)')
                ->execute([$fyId, $fund['id'], $version, $label, ($b['installment_no'] ?? '') === '' ? null : (int)$b['installment_no'], $month, json_col($basis), cents_str($amount), $u['id']]);
            $id = (int)$pdo->lastInsertId();
            audit('estimate.create', 'revenue_estimate', $id, null, ['fund' => $fund['code'], 'label' => $label, 'amount' => cents_str($amount), 'basis' => $basis]);
        }
        return ['ok' => true, 'id' => $id];
    },

    'POST estimate_delete' => function () {
        $fyId = request_fy();
        require_estimate_role($fyId);
        $id = (int)(body()['id'] ?? 0);
        $st = db()->prepare('SELECT * FROM revenue_estimates WHERE id = ? AND fiscal_year_id = ?');
        $st->execute([$id, $fyId]);
        $row = $st->fetch();
        if (!$row) fail('ไม่พบรายการ', 404);
        db()->prepare('DELETE FROM revenue_estimates WHERE id = ?')->execute([$id]);
        audit('estimate.delete', 'revenue_estimate', $id, $row, null);
        return ['ok' => true];
    },

    // Copy the fund's current version into a new version (e.g. ปรับกลางปี) and make it current.
    'POST estimate_new_version' => function () {
        $fyId = request_fy();
        $u = require_estimate_role($fyId);
        $fund = leaf_fund_or_fail((int)(body()['fund_source_id'] ?? 0), $fyId);
        return tx(function (PDO $pdo) use ($fyId, $fund, $u) {
            $st = $pdo->prepare('SELECT COALESCE(MAX(version), 0) FROM revenue_estimates WHERE fiscal_year_id = ? AND fund_source_id = ?');
            $st->execute([$fyId, $fund['id']]);
            $next = (int)$st->fetchColumn() + 1;
            $pdo->prepare('INSERT INTO revenue_estimates (fiscal_year_id, fund_source_id, version, label, installment_no, expected_month, basis, amount, is_current, created_by)
                SELECT fiscal_year_id, fund_source_id, ?, label, installment_no, expected_month, basis, amount, 0, ? FROM revenue_estimates
                WHERE fiscal_year_id = ? AND fund_source_id = ? AND is_current = 1')->execute([$next, $u['id'], $fyId, $fund['id']]);
            $pdo->prepare('UPDATE revenue_estimates SET is_current = (version = ?) WHERE fiscal_year_id = ? AND fund_source_id = ?')->execute([$next, $fyId, $fund['id']]);
            audit('estimate.new_version', 'fund_source', (int)$fund['id'], null, ['version' => $next]);
            return ['ok' => true, 'version' => $next];
        });
    },

    'POST estimate_set_current' => function () {
        $fyId = request_fy();
        require_estimate_role($fyId);
        $b = body();
        $fund = leaf_fund_or_fail((int)($b['fund_source_id'] ?? 0), $fyId);
        $version = (int)($b['version'] ?? 0);
        $st = db()->prepare('SELECT COUNT(*) FROM revenue_estimates WHERE fiscal_year_id = ? AND fund_source_id = ? AND version = ?');
        $st->execute([$fyId, $fund['id'], $version]);
        if (!(int)$st->fetchColumn()) fail('ไม่พบฉบับประมาณการนี้');
        db()->prepare('UPDATE revenue_estimates SET is_current = (version = ?) WHERE fiscal_year_id = ? AND fund_source_id = ?')->execute([$version, $fyId, $fund['id']]);
        audit('estimate.set_current', 'fund_source', (int)$fund['id'], null, ['version' => $version]);
        return ['ok' => true];
    },
];
