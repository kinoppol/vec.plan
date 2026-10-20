<?php
declare(strict_types=1);

/**
 * Overview page: project tracking summary for charts.
 * Scoped like the project list (Access::projectScope), so unit-level users see their own units only.
 */

// Statuses grouped into pipeline stages (in display order).
const OVERVIEW_STAGES = [
    'proposing' => ['label' => 'ระหว่างเสนอ/พิจารณา', 'statuses' => ['draft', 'submitted', 'returned', 'planning_review', 'pending_decision', 'waitlisted']],
    'approved' => ['label' => 'อนุมัติ รอเริ่ม', 'statuses' => ['approved']],
    'in_progress' => ['label' => 'กำลังดำเนินการ', 'statuses' => ['in_progress']],
    'awaiting_report' => ['label' => 'รอรายงานผล', 'statuses' => ['awaiting_report']],
    'closed' => ['label' => 'ปิดโครงการ', 'statuses' => ['closed']],
    'dropped' => ['label' => 'ไม่ดำเนินการ', 'statuses' => ['rejected', 'cancelled', 'transferred_out']],
];
// Projects whose money is no longer expected to move.
const OVERVIEW_INACTIVE = ['draft', 'submitted', 'returned', 'planning_review', 'pending_decision', 'waitlisted', 'rejected', 'cancelled', 'transferred_out'];

/** Share of a project's money planned for each fiscal month (index 0..11), from its planned quarters. */
function overview_month_shares(array $quarters): array
{
    $qs = array_values(array_filter($quarters, fn($q) => $q >= 1 && $q <= 4));
    $shares = array_fill(0, 12, 0.0);
    if (!$qs) return array_fill(0, 12, 1 / 12);
    foreach ($qs as $q) for ($m = ($q - 1) * 3; $m < $q * 3; $m++) $shares[$m] += 1 / (3 * count($qs));
    return $shares;
}

return [
    'GET index' => function () {
        $fyId = request_fy();
        $u = require_login();
        if (!Access::permissions($fyId)['view_projects']) fail('ไม่มีสิทธิ์ดูภาพรวมโครงการ', 403);
        api_handlers('projects'); // defines project_rows()
        $fy = fiscal_year($fyId);
        $q = [];
        if (!empty($_GET['unit'])) $q['unit'] = (int)$_GET['unit'];
        if (!empty($_GET['fund'])) $q['fund'] = (int)$_GET['fund'];
        $rows = project_rows($u, $fyId, $q);
        $ids = array_map(fn($r) => $r['id'], $rows);

        // Position in the fiscal year as a fractional month index (0 = before start, 12 = after end).
        $today = today();
        if ($today < $fy['starts_on']) $now = 0.0;
        elseif ($today > $fy['ends_on']) $now = 12.0;
        else $now = fiscal_month($today) - 1 + ((int)date('j', strtotime($today)) - 1) / (int)date('t', strtotime($today));
        $expectedShare = function (array $shares) use ($now): float {
            $s = 0.0;
            foreach ($shares as $m => $v) $s += $v * max(0.0, min(1.0, $now - $m));
            return $s;
        };

        // Net spending (spend minus spend reversals) per project, fiscal month and expense category.
        $spend = [];
        $byCat = [];
        if ($ids) {
            $st = db()->prepare("SELECT la.project_id, e.entry_date, COALESCE(e.expense_category_id, o.expense_category_id) AS cat,
                    SUM(CASE WHEN e.entry_type = 'spend' THEN e.amount ELSE -e.amount END) AS amt
                FROM ledger_entries e
                LEFT JOIN ledger_entries o ON o.id = e.reverses_entry_id
                JOIN ledger_accounts la ON la.id = (CASE WHEN e.entry_type = 'spend' THEN e.from_account_id ELSE e.to_account_id END)
                WHERE e.fiscal_year_id = ? AND la.kind = 'project'
                  AND la.project_id IN (" . implode(',', $ids) . ")
                  AND (e.entry_type = 'spend' OR (e.entry_type = 'reversal' AND o.entry_type = 'spend'))
                GROUP BY la.project_id, e.entry_date, cat");
            $st->execute([$fyId]);
            foreach ($st as $r) {
                $m = max(0, min(11, fiscal_month($r['entry_date']) - 1));
                $amt = to_cents($r['amt']);
                $spend[$m] = ($spend[$m] ?? 0) + $amt;
                if ($r['cat'] !== null) $byCat[(int)$r['cat']] = ($byCat[(int)$r['cat']] ?? 0) + $amt;
            }
        }

        $stageOf = [];
        foreach (OVERVIEW_STAGES as $k => $s) foreach ($s['statuses'] as $st) $stageOf[$st] = $k;
        $stages = array_map(fn($s) => ['label' => $s['label'], 'count' => 0, 'allocated' => 0], OVERVIEW_STAGES);
        $health = ['green' => 0, 'yellow' => 0, 'red' => 0, 'none' => 0];
        $quarters = [];
        for ($i = 1; $i <= 4; $i++) $quarters[$i] = ['quarter' => $i, 'count' => 0, 'planned' => 0];
        $divisions = [];
        $plan = array_fill(0, 12, 0.0);
        $track = ['on_track' => 0, 'behind' => 0, 'critical' => 0, 'upcoming' => 0, 'done' => 0];
        $watch = [];
        $totals = ['projects' => count($rows), 'requested' => 0, 'allocated' => 0, 'spent' => 0, 'expected' => 0, 'active' => 0];

        foreach ($rows as $r) {
            $stage = $stageOf[$r['status']] ?? 'proposing';
            $stages[$stage]['count']++;
            $stages[$stage]['allocated'] += $r['allocated'];
            $health[$r['health'] ?: 'none']++;
            $totals['requested'] += $r['requested'];
            $totals['allocated'] += $r['allocated'];
            $totals['spent'] += $r['spent'];

            $d = $r['division_name'] ?: $r['unit_name'];
            $divisions[$d] = $divisions[$d] ?? ['name' => $d, 'projects' => 0, 'allocated' => 0, 'spent' => 0, 'expected' => 0];
            $divisions[$d]['projects']++;
            $divisions[$d]['allocated'] += $r['allocated'];
            $divisions[$d]['spent'] += $r['spent'];

            if (in_array($r['status'], OVERVIEW_INACTIVE, true) || $r['allocated'] <= 0) continue;
            $totals['active']++;
            $shares = overview_month_shares($r['quarters']);
            foreach ($shares as $m => $v) $plan[$m] += $r['allocated'] * $v;
            foreach ($r['quarters'] ?: [1, 2, 3, 4] as $qn) {
                if (!isset($quarters[$qn])) continue;
                $n = count($r['quarters'] ?: [1, 2, 3, 4]);
                $quarters[$qn]['count']++;
                $quarters[$qn]['planned'] += $r['allocated'] / $n;
            }
            $expected = $r['allocated'] * $expectedShare($shares);
            $totals['expected'] += $expected;
            $divisions[$d]['expected'] += $expected;

            $actualPct = $r['spent'] / $r['allocated'] * 100;
            $expectedPct = $expected / $r['allocated'] * 100;
            $lag = $expectedPct - $actualPct;
            if ($r['status'] === 'closed' || $actualPct >= 99.5) $t = 'done';
            elseif ($expectedPct < 0.5) $t = 'upcoming';
            elseif ($lag > 40) $t = 'critical';
            elseif ($lag > 15) $t = 'behind';
            else $t = 'on_track';
            $track[$t]++;
            if ($t === 'critical' || $t === 'behind' || $r['health'] === 'red' || $r['health'] === 'yellow') {
                $watch[] = ['id' => $r['id'], 'code' => $r['code'], 'title' => $r['title'], 'unit_name' => $r['unit_name'],
                    'status' => $r['status'], 'health' => $r['health'], 'track' => $t, 'quarters' => $r['quarters'],
                    'allocated' => $r['allocated'], 'spent' => $r['spent'], 'expected' => round($expected, 2),
                    'actual_pct' => round($actualPct, 1), 'expected_pct' => round($expectedPct, 1), 'gap' => round($expected - $r['spent'], 2)];
            }
        }
        usort($watch, fn($a, $b) => $b['gap'] <=> $a['gap']);

        // Cumulative plan vs actual by fiscal month; actual stops at the current month.
        $curve = [];
        $cp = 0.0;
        $ca = 0;
        $nowMonth = (int)ceil($now);
        for ($m = 0; $m < 12; $m++) {
            $cp += $plan[$m];
            $ca += $spend[$m] ?? 0;
            $curve[] = ['month' => $m, 'plan' => round($cp, 2), 'actual' => $m < max(1, $nowMonth) ? cents_num($ca) : null,
                'spent' => cents_num($spend[$m] ?? 0)];
        }

        // Spending by expense category (top-level name kept as is).
        $cats = [];
        if ($byCat) {
            $st = db()->query('SELECT id, name FROM expense_categories WHERE id IN (' . implode(',', array_keys($byCat)) . ')');
            $names = [];
            foreach ($st as $c) $names[(int)$c['id']] = $c['name'];
            foreach ($byCat as $id => $amt) if ($amt != 0) $cats[] = ['name' => $names[$id] ?? ('หมวด ' . $id), 'amount' => cents_num($amt)];
            usort($cats, fn($a, $b) => $b['amount'] <=> $a['amount']);
        }

        // Allocated / spent per leaf fund.
        $funds = [];
        if ($ids) {
            $st = db()->prepare('SELECT id, name, color_token FROM fund_sources WHERE fiscal_year_id = ?');
            $st->execute([$fyId]);
            $meta = [];
            foreach ($st as $f) $meta[(int)$f['id']] = $f;
            foreach (Ledger::projectBalances($fyId, $ids) as $byFund) {
                foreach ($byFund as $fid => $b) {
                    $funds[$fid] = $funds[$fid] ?? ['id' => $fid, 'name' => $meta[$fid]['name'] ?? '-', 'color' => $meta[$fid]['color_token'] ?? '#5C6880', 'allocated' => 0, 'spent' => 0];
                    $funds[$fid]['allocated'] += $b['allocated'];
                    $funds[$fid]['spent'] += $b['spent'];
                }
            }
            foreach ($funds as &$f) { $f['allocated'] = cents_num($f['allocated']); $f['spent'] = cents_num($f['spent']); }
            unset($f);
            $funds = array_values(array_filter($funds, fn($f) => $f['allocated'] > 0));
            usort($funds, fn($a, $b) => $b['allocated'] <=> $a['allocated']);
        }

        $divisions = array_values($divisions);
        usort($divisions, fn($a, $b) => $b['allocated'] <=> $a['allocated']);
        foreach ($divisions as &$d) $d['expected'] = round($d['expected'], 2);
        unset($d);
        $totals['expected'] = round($totals['expected'], 2);

        return [
            'as_of' => $today, 'fy_position' => round($now, 3),
            'totals' => $totals,
            'stages' => array_map(fn($k, $s) => ['key' => $k] + $s, array_keys($stages), $stages),
            'health' => $health,
            'track' => $track,
            'quarters' => array_values($quarters),
            'divisions' => $divisions,
            'curve' => $curve,
            'categories' => $cats,
            'funds' => $funds,
            'watch' => array_slice($watch, 0, 12),
            'watch_total' => count($watch),
        ];
    },
];
