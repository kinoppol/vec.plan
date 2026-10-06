<?php
declare(strict_types=1);

// Bootstrap data for the SPA: who am I, which fiscal year, and master data lists.
return [
    'GET index' => function () {
        $u = require_login();
        $pdo = db();
        $fyId = request_fy();
        $fy = fiscal_year($fyId);
        $roles = array_map(fn($r) => ['role' => $r['role'], 'label' => ROLES[$r['role']] ?? $r['role'], 'unit_name' => $r['unit_name']], roles_for($u, $fyId));

        $st = $pdo->prepare('SELECT id, parent_id, code, name, color_token AS color, fund_type, permit_rule, is_leaf, active, sort
            FROM fund_sources WHERE fiscal_year_id = ? ORDER BY sort, id');
        $st->execute([$fyId]);
        $funds = $st->fetchAll();

        return [
            'org_name' => setting('org_name', 'วิทยาลัย'),
            'version' => APP_VERSION,
            'today' => today(),
            'fy' => $fyId,
            'current_fy' => current_fiscal_year_id(),
            'fiscal_year' => ['id' => (int)$fy['id'], 'year_be' => (int)$fy['year_be'], 'starts_on' => $fy['starts_on'], 'ends_on' => $fy['ends_on'],
                'status' => $fy['status'], 'baseline_locked_at' => $fy['baseline_locked_at'], 'settings' => $fy['settings']],
            'fiscal_years' => $pdo->query('SELECT id, year_be, starts_on, ends_on, status, baseline_locked_at FROM fiscal_years ORDER BY year_be DESC')->fetchAll(),
            'user' => ['id' => (int)$u['id'], 'username' => $u['username'], 'name' => $u['name'], 'position_title' => $u['position_title'], 'roles' => $roles],
            'permissions' => Access::permissions($fyId),
            'role_labels' => ROLES,
            'units' => $pdo->query('SELECT id, parent_id, name, kind, code, student_count_vc, student_count_hvc, active, sort FROM org_units ORDER BY sort, id')->fetchAll(),
            'funds' => $funds,
            'categories' => $pdo->query('SELECT c.id, c.parent_id, c.code, c.name, c.active,
                NOT EXISTS (SELECT 1 FROM expense_categories k WHERE k.parent_id = c.id) AS is_leaf FROM expense_categories c ORDER BY c.sort, c.id')->fetchAll(),
            'ledger_types' => Ledger::TYPE_LABELS,
        ];
    },
];
