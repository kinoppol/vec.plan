<?php
declare(strict_types=1);

return [
    'GET index' => function () {
        $fyId = request_fy();
        $u = require_login();
        $fy = fiscal_year($fyId);
        $perm = Access::permissions($fyId);
        $pdo = db();

        $days = (int)((strtotime($fy['ends_on']) - strtotime($fy['starts_on'])) / 86400) + 1;
        $elapsed = max(0, min($days, (int)((strtotime(today()) - strtotime($fy['starts_on'])) / 86400) + 1));
        $out = ['as_of' => now(), 'fy_day' => $elapsed, 'fy_days' => $days, 'baseline_locked_at' => $fy['baseline_locked_at'], 'mode' => 'personal'];

        if ($perm['view_funds']) {
            $out['mode'] = 'funds';
            $cards = FundReport::cards($fyId);
            $out += $cards;

            $warnings = [];
            foreach ($cards['cards'] as $c) {
                foreach ($c['leaves'] as $l) {
                    if ($l['pool_actual'] < 0) {
                        $warnings[] = ['tone' => 'red', 'fund_id' => $l['id'], 'title' => $c['name'] . ' · ' . $l['name'] . ': คงเหลือจัดสรรได้ (จริง) ติดลบ',
                            'amount' => $l['pool_actual'], 'detail' => 'จัดสรรเกินเงินที่รับจริง ควรชะลอการขออนุญาตจนกว่าเงินเข้า'];
                    }
                }
                // Spec §9: actual receipts more than 10% below the time-proportional estimate.
                if ($c['estimate_to_date'] > 0 && $c['receipts'] < $c['estimate_to_date'] * 0.9) {
                    $warnings[] = ['tone' => 'yellow', 'fund_id' => $c['id'], 'title' => $c['name'] . ': รับจริงต่ำกว่าประมาณการถึงงวดเกิน 10%',
                        'amount' => $c['receipts'] - $c['estimate_to_date'],
                        'detail' => 'รับจริง ' . number_format($c['receipts'], 2) . ' จากประมาณการถึงงวด ' . number_format($c['estimate_to_date'], 2)];
                }
            }
            $out['warnings'] = $warnings;

            // Approved amounts by division.
            $bal = Ledger::projectBalances($fyId);
            $st = $pdo->prepare("SELECT p.id, p.status, COALESCE(dv.name, ou.name) AS division
                FROM projects p JOIN org_units ou ON ou.id = p.org_unit_id
                LEFT JOIN org_units dv ON dv.id = (CASE WHEN ou.kind = 'division' THEN ou.id ELSE ou.parent_id END)
                WHERE p.fiscal_year_id = ?");
            $st->execute([$fyId]);
            $div = [];
            $status = [];
            foreach ($st as $p) {
                $d = $p['division'];
                $div[$d] = $div[$d] ?? ['name' => $d, 'projects' => 0, 'allocated' => 0];
                $div[$d]['projects']++;
                $div[$d]['allocated'] += cents_num(array_sum(array_column($bal[(int)$p['id']] ?? [], 'allocated')));
                $status[$p['status']] = ($status[$p['status']] ?? 0) + 1;
            }
            usort($div, fn($a, $b) => $b['allocated'] <=> $a['allocated']);
            $out['divisions'] = array_values($div);
            $out['status_counts'] = $status;

            if ($perm['view_ledger']) {
                $st = $pdo->prepare("SELECT e.id, e.entry_no, e.entry_date, e.entry_type, e.amount, e.reference_no, e.note,
                        f.name AS fund_name, f.color_token AS fund_color, fa.kind AS from_kind, ta.kind AS to_kind,
                        COALESCE(tp.code, fp.code) AS project_code, fa.reserve_name AS from_reserve, ta.reserve_name AS to_reserve,
                        (SELECT r.entry_no FROM ledger_entries r WHERE r.reverses_entry_id = e.id) AS reversed_by_no
                    FROM ledger_entries e JOIN ledger_accounts fa ON fa.id = e.from_account_id JOIN ledger_accounts ta ON ta.id = e.to_account_id
                    JOIN fund_sources f ON f.id = fa.fund_source_id LEFT JOIN projects fp ON fp.id = fa.project_id LEFT JOIN projects tp ON tp.id = ta.project_id
                    WHERE e.fiscal_year_id = ? ORDER BY e.id DESC LIMIT 6");
                $st->execute([$fyId]);
                $out['recent'] = array_map(function ($r) {
                    $sign = $r['to_kind'] === 'fund_pool' ? 1 : ($r['from_kind'] === 'fund_pool' ? -1 : 0);
                    return ['id' => (int)$r['id'], 'entry_no' => $r['entry_no'], 'entry_date' => $r['entry_date'], 'entry_type' => $r['entry_type'],
                        'type_label' => Ledger::TYPE_LABELS[$r['entry_type']], 'fund_name' => $r['fund_name'], 'fund_color' => $r['fund_color'],
                        'project_code' => $r['project_code'], 'reference_no' => $r['reference_no'], 'note' => $r['note'],
                        'amount' => cents_num(to_cents($r['amount'])), 'signed' => cents_num($sign * to_cents($r['amount'])),
                        'reversed' => (bool)$r['reversed_by_no']];
                }, $st->fetchAll());
            }
        }

        if ($perm['view_projects']) {
            // "My projects": owned or created by me, or in my unit scope.
            $where = 'p.fiscal_year_id = ? AND (p.created_by = ? OR EXISTS (SELECT 1 FROM project_owners po WHERE po.project_id = p.id AND po.user_id = ?))';
            $params = [$fyId, $u['id'], $u['id']];
            $st = $pdo->prepare("SELECT p.id, p.code, p.title, p.status, ou.name AS unit_name FROM projects p JOIN org_units ou ON ou.id = p.org_unit_id
                WHERE {$where} ORDER BY p.code LIMIT 50");
            $st->execute($params);
            $mine = $st->fetchAll();
            $bal = Ledger::projectBalances($fyId, array_map(fn($p) => (int)$p['id'], $mine));
            $out['my_projects'] = array_map(fn($p) => $p + [
                'allocated' => cents_num(array_sum(array_column($bal[(int)$p['id']] ?? [], 'allocated'))),
                'spent' => cents_num(array_sum(array_column($bal[(int)$p['id']] ?? [], 'spent'))),
            ], $mine);
        }
        return $out;
    },
];
