<?php
declare(strict_types=1);

// Bootstrap data for the SPA: who am I, which fiscal year, and master data lists.
return [
    'GET index' => function () {
        $u = require_login();
        $pdo = db();
        $tenancy = ['mode' => tenancy_mode(), 'system_admin' => is_system_admin($u), 'super_admin' => is_super_admin($u)];

        // The central admin of a multi-institution system has no institution data of their own.
        if ($u['institution_id'] === null) {
            if (!is_super_admin($u)) fail('บัญชีนี้ไม่ได้สังกัดสถานศึกษา', 403);
            return [
                'org_name' => system_setting('system_name', 'ระบบแผนงานและงบประมาณสถานศึกษา'),
                'version' => APP_VERSION,
                'today' => today(),
                'fy' => null, 'current_fy' => null, 'fiscal_year' => null, 'fiscal_years' => [],
                'user' => ['id' => (int)$u['id'], 'username' => $u['username'], 'name' => $u['name'], 'position_title' => $u['position_title'],
                    'roles' => [['role' => 'super_admin', 'label' => ROLES['super_admin'], 'unit_name' => null]]],
                'permissions' => ['super_admin' => true, 'system' => true, 'audit' => true, 'institutions' => true],
                'tenancy' => $tenancy,
                'role_labels' => ROLES,
                'units' => [], 'funds' => [], 'categories' => [],
                'ledger_types' => Ledger::TYPE_LABELS,
            ];
        }

        $inst = institution($u['institution_id']);
        $fyId = request_fy();
        $fy = fiscal_year($fyId);
        $roles = array_map(fn($r) => ['role' => $r['role'], 'label' => ROLES[$r['role']] ?? $r['role'], 'unit_name' => $r['unit_name']], roles_for($u, $fyId));

        $st = $pdo->prepare('SELECT id, parent_id, code, name, color_token AS color, fund_type, permit_rule, is_leaf, active, sort
            FROM fund_sources WHERE fiscal_year_id = ? ORDER BY sort, id');
        $st->execute([$fyId]);
        $funds = $st->fetchAll();
        $q = function (string $sql) use ($pdo, $inst): array {
            $st = $pdo->prepare($sql);
            $st->execute([$inst['id']]);
            return $st->fetchAll();
        };

        return [
            'org_name' => $inst['name'],
            'version' => APP_VERSION,
            'today' => today(),
            'fy' => $fyId,
            'current_fy' => current_fiscal_year_id(),
            'fiscal_year' => ['id' => (int)$fy['id'], 'year_be' => (int)$fy['year_be'], 'starts_on' => $fy['starts_on'], 'ends_on' => $fy['ends_on'],
                'status' => $fy['status'], 'baseline_locked_at' => $fy['baseline_locked_at'], 'settings' => $fy['settings']],
            'fiscal_years' => $q('SELECT id, year_be, starts_on, ends_on, status, baseline_locked_at FROM fiscal_years WHERE institution_id = ? ORDER BY year_be DESC'),
            'user' => ['id' => (int)$u['id'], 'username' => $u['username'], 'name' => $u['name'], 'position_title' => $u['position_title'], 'roles' => $roles],
            'permissions' => Access::permissions($fyId) + ['system' => $tenancy['system_admin'], 'audit' => has_role('admin', $fyId),
                // Single mode: the system admin sees the page that switches to multi-institution mode.
                'institutions' => !is_multi() && $tenancy['system_admin']],
            'tenancy' => $tenancy,
            'role_labels' => ROLES,
            'units' => $q('SELECT id, parent_id, name, kind, code, student_count_vc, student_count_hvc, active, sort FROM org_units WHERE institution_id = ? ORDER BY sort, id'),
            'funds' => $funds,
            'categories' => $q('SELECT c.id, c.parent_id, c.code, c.name, c.active,
                NOT EXISTS (SELECT 1 FROM expense_categories k WHERE k.parent_id = c.id) AS is_leaf FROM expense_categories c WHERE c.institution_id = ? ORDER BY c.sort, c.id'),
            'ledger_types' => Ledger::TYPE_LABELS,
        ];
    },
];
