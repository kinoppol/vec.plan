<?php
declare(strict_types=1);

const PROJECT_STATUS = [
    'draft' => 'ร่าง', 'submitted' => 'อยู่ระหว่างเสนอ', 'returned' => 'ส่งกลับแก้ไข', 'planning_review' => 'งานแผนฯ ตรวจ',
    'pending_decision' => 'รอพิจารณา', 'waitlisted' => 'รอเงินเพิ่ม', 'rejected' => 'ไม่อนุมัติ', 'approved' => 'อนุมัติในแผน',
    'in_progress' => 'กำลังดำเนินการ', 'awaiting_report' => 'รอรายงานผล', 'closed' => 'ปิดโครงการ', 'cancelled' => 'ยกเลิก',
    'transferred_out' => 'โอนออกทั้งหมด',
];

function project_rows(array $user, int $fyId, array $q): array
{
    $where = ['p.fiscal_year_id = ?'];
    $params = [$fyId];
    if ($scope = Access::projectScope($user, $fyId)) {
        $where[] = $scope[0];
        $params = array_merge($params, $scope[1]);
    }
    if (!empty($q['status'])) { $where[] = 'p.status = ?'; $params[] = (string)$q['status']; }
    if (!empty($q['unit'])) {
        $ids = Access::subtree([(int)$q['unit']]);
        $where[] = 'p.org_unit_id IN (' . implode(',', $ids) . ')';
    }
    if (!empty($q['quarter'])) { $where[] = 'FIND_IN_SET(?, p.planned_quarters)'; $params[] = (string)(int)$q['quarter']; }
    if (!empty($q['fund'])) {
        $leaves = Ledger::leafIds((int)$q['fund'], $fyId) ?: [0];
        $where[] = 'EXISTS (SELECT 1 FROM budget_lines bl WHERE bl.project_id = p.id AND bl.fund_source_id IN (' . implode(',', $leaves) . '))';
    }
    if (($s = trim((string)($q['q'] ?? ''))) !== '') {
        $where[] = '(p.code LIKE ? OR p.title LIKE ? OR ou.name LIKE ?)';
        $like = '%' . $s . '%';
        array_push($params, $like, $like, $like);
    }
    $st = db()->prepare('SELECT p.id, p.code, p.title, p.status, p.health, p.planned_quarters, p.requested_total, p.source, p.necessity,
            p.org_unit_id, ou.name AS unit_name, dv.name AS division_name
        FROM projects p JOIN org_units ou ON ou.id = p.org_unit_id
        LEFT JOIN org_units dv ON dv.id = (CASE WHEN ou.kind = \'division\' THEN ou.id ELSE ou.parent_id END)
        WHERE ' . implode(' AND ', $where) . ' ORDER BY p.code');
    $st->execute($params);
    $rows = $st->fetchAll();
    $ids = array_map(fn($r) => (int)$r['id'], $rows);
    $bal = Ledger::projectBalances($fyId, $ids);
    $funds = [];
    if ($ids) {
        $st = db()->query('SELECT DISTINCT bl.project_id, bl.fund_source_id FROM budget_lines bl WHERE bl.project_id IN (' . implode(',', $ids) . ')');
        foreach ($st as $r) $funds[(int)$r['project_id']][] = (int)$r['fund_source_id'];
    }
    return array_map(function ($r) use ($bal, $funds) {
        $b = $bal[(int)$r['id']] ?? [];
        $sum = fn($k) => cents_num(array_sum(array_column($b, $k)));
        $fundIds = array_values(array_unique(array_merge($funds[(int)$r['id']] ?? [], array_keys($b))));
        return [
            'id' => (int)$r['id'], 'code' => $r['code'], 'title' => $r['title'], 'status' => $r['status'],
            'status_label' => PROJECT_STATUS[$r['status']] ?? $r['status'], 'health' => $r['health'],
            'quarters' => $r['planned_quarters'] ? array_map('intval', explode(',', $r['planned_quarters'])) : [],
            'unit_id' => (int)$r['org_unit_id'], 'unit_name' => $r['unit_name'], 'division_name' => $r['division_name'],
            'requested' => cents_num(to_cents($r['requested_total'])), 'allocated' => $sum('allocated'), 'spent' => $sum('spent'), 'free' => $sum('free'),
            'fund_ids' => $fundIds, 'source' => $r['source'], 'necessity' => $r['necessity'],
        ];
    }, $rows);
}


return [
    'GET index' => function () {
        $fyId = request_fy();
        $u = require_login();
        if (!Access::permissions($fyId)['view_projects']) fail('ไม่มีสิทธิ์ดูรายการโครงการ', 403);
        $rows = project_rows($u, $fyId, $_GET);
        return ['rows' => $rows, 'statuses' => PROJECT_STATUS];
    },

    'GET export' => function () {
        $fyId = request_fy();
        $u = require_login();
        if (!Access::permissions($fyId)['view_projects']) fail('ไม่มีสิทธิ์ดูรายการโครงการ', 403);
        $fy = fiscal_year($fyId);
        $out = [['รหัส', 'ชื่อโครงการ', 'หน่วยงาน', 'ฝ่าย', 'ยอดขอ', 'อนุมัติ (จัดสรร)', 'จ่ายจริง', 'คงเหลือ', 'ไตรมาสที่วางแผน', 'สถานะ']];
        foreach (project_rows($u, $fyId, $_GET) as $r) {
            $out[] = [$r['code'], $r['title'], $r['unit_name'], (string)$r['division_name'], $r['requested'], $r['allocated'], $r['spent'], $r['free'],
                implode(',', $r['quarters']), $r['status_label']];
        }
        $bin = Xlsx::build($out, 'โครงการ');
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="projects-' . $fy['year_be'] . '.xlsx"');
        echo $bin;
        exit;
    },

    'GET show' => function () {
        $fyId = request_fy();
        $u = require_login();
        $id = (int)($_GET['id'] ?? 0);
        $where = 'p.id = ? AND p.fiscal_year_id = ?';
        $params = [$id, $fyId];
        if ($scope = Access::projectScope($u, $fyId)) {
            $where .= ' AND ' . $scope[0];
            $params = array_merge($params, $scope[1]);
        }
        $st = db()->prepare('SELECT p.*, ou.name AS unit_name, cu.name AS created_by_name FROM projects p
            JOIN org_units ou ON ou.id = p.org_unit_id LEFT JOIN users cu ON cu.id = p.created_by WHERE ' . $where);
        $st->execute($params);
        $p = $st->fetch();
        if (!$p) fail('ไม่พบโครงการหรือไม่มีสิทธิ์เข้าถึง', 404);
        $st = db()->prepare('SELECT bl.*, c.name AS category_name, f.name AS fund_name, f.code AS fund_code, f.color_token AS fund_color
            FROM budget_lines bl JOIN expense_categories c ON c.id = bl.expense_category_id JOIN fund_sources f ON f.id = bl.fund_source_id
            WHERE bl.project_id = ? ORDER BY bl.id');
        $st->execute([$id]);
        $lines = array_map(fn($l) => $l + ['amount_num' => cents_num(to_cents($l['amount']))], $st->fetchAll());
        $bal = Ledger::projectBalances($fyId, [$id])[$id] ?? [];
        $balances = [];
        foreach ($bal as $fid => $b) $balances[] = ['fund_source_id' => $fid, 'allocated' => cents_num($b['allocated']), 'spent' => cents_num($b['spent']),
            'committed_open' => 0, 'free' => cents_num($b['free'])];
        $st = db()->prepare("SELECT a.created_at, a.action, a.after, u.name AS user_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
            WHERE a.subject_type = 'project' AND a.subject_id = ? ORDER BY a.id DESC LIMIT 100");
        $st->execute([$id]);
        return [
            'project' => [
                'id' => (int)$p['id'], 'code' => $p['code'], 'title' => $p['title'], 'status' => $p['status'],
                'status_label' => PROJECT_STATUS[$p['status']] ?? $p['status'], 'health' => $p['health'], 'unit_name' => $p['unit_name'],
                'quarters' => $p['planned_quarters'] ? array_map('intval', explode(',', $p['planned_quarters'])) : [],
                'requested' => cents_num(to_cents($p['requested_total'])), 'source' => $p['source'], 'created_by_name' => $p['created_by_name'],
                'approved_at' => $p['approved_at'], 'created_at' => $p['created_at'],
            ],
            'lines' => $lines,
            'balances' => $balances,
            'can_view_ledger' => has_role(Access::LEDGER_VIEWERS, $fyId),
            'history' => $st->fetchAll(),
        ];
    },
];
