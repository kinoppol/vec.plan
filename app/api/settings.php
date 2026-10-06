<?php
declare(strict_types=1);

// Master data (spec §4.1) — managed by งานแผนฯ (planner). Nothing is deleted; records are deactivated.

const FUND_TYPES = ['state_budget', 'subsidy', 'institution_income', 'donation', 'other'];
const PERMIT_RULES = ['allocation_letter', 'cumulative_receipts', 'cash_available'];
const UNIT_KINDS = ['division', 'section', 'department'];
const SCOPES = ['project_unit', 'project_division', 'global'];

function settings_user(int $fyId): array
{
    return require_role('planner', $fyId);
}

/** Tables owned directly by an institution; their rows are looked up only inside the user's institution. */
const INSTITUTION_TABLES = ['org_units', 'expense_categories'];

function row_or_fail(string $table, int $id): array
{
    if (in_array($table, INSTITUTION_TABLES, true)) {
        $st = db()->prepare("SELECT * FROM {$table} WHERE id = ? AND institution_id = ?");
        $st->execute([$id, require_institution()]);
    } else {
        $st = db()->prepare("SELECT * FROM {$table} WHERE id = ?");
        $st->execute([$id]);
    }
    $r = $st->fetch();
    if (!$r) fail('ไม่พบข้อมูล', 404);
    return $r;
}

function str_in(array $b, string $k, int $max = 255, bool $required = true): ?string
{
    $v = trim((string)($b[$k] ?? ''));
    if ($v === '') {
        if ($required) fail('กรุณากรอกข้อมูลให้ครบ (' . $k . ')');
        return null;
    }
    return mb_substr($v, 0, $max);
}

/** Reject parent changes that would create a cycle in a self-referencing table. */
function assert_no_cycle(string $table, ?int $id, ?int $parentId): void
{
    if (!$id || !$parentId) return;
    $seen = [];
    $st = db()->prepare("SELECT parent_id FROM {$table} WHERE id = ?");
    for ($cur = $parentId; $cur; ) {
        if ($cur === $id || isset($seen[$cur])) fail('ไม่สามารถเลือกหน่วยย่อยของตัวเองเป็นหน่วยแม่ได้');
        $seen[$cur] = true;
        $st->execute([$cur]);
        $cur = (int)$st->fetchColumn();
    }
}

/** Copy year-scoped master data (funds, allowed categories, alignment, approval chains) between years. */
function copy_year_master(PDO $pdo, int $from, int $to): void
{
    $map = [];
    $st = $pdo->prepare('SELECT * FROM fund_sources WHERE fiscal_year_id = ? ORDER BY (parent_id IS NOT NULL), id');
    $st->execute([$from]);
    $rows = $st->fetchAll();
    $ins = $pdo->prepare('INSERT INTO fund_sources (fiscal_year_id, parent_id, code, name, color_token, fund_type, permit_rule, is_leaf, sort, active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    // Parents first: loop until every row is placed.
    while ($rows) {
        foreach ($rows as $i => $f) {
            if ($f['parent_id'] && !isset($map[(int)$f['parent_id']])) continue;
            $ins->execute([$to, $f['parent_id'] ? $map[(int)$f['parent_id']] : null, $f['code'], $f['name'], $f['color_token'], $f['fund_type'], $f['permit_rule'], $f['is_leaf'], $f['sort'], $f['active']]);
            $map[(int)$f['id']] = (int)$pdo->lastInsertId();
            unset($rows[$i]);
        }
    }
    $st = $pdo->prepare('SELECT a.* FROM fund_source_allowed_categories a JOIN fund_sources f ON f.id = a.fund_source_id WHERE f.fiscal_year_id = ?');
    $st->execute([$from]);
    $insA = $pdo->prepare('INSERT INTO fund_source_allowed_categories (fund_source_id, expense_category_id) VALUES (?, ?)');
    foreach ($st as $a) $insA->execute([$map[(int)$a['fund_source_id']], $a['expense_category_id']]);

    $st = $pdo->prepare('SELECT * FROM alignment_sets WHERE fiscal_year_id = ?');
    $st->execute([$from]);
    foreach ($st->fetchAll() as $s) {
        $pdo->prepare('INSERT INTO alignment_sets (fiscal_year_id, code, name, required, sort) VALUES (?, ?, ?, ?, ?)')->execute([$to, $s['code'], $s['name'], $s['required'], $s['sort']]);
        $newSet = (int)$pdo->lastInsertId();
        $it = $pdo->prepare('SELECT * FROM alignment_items WHERE alignment_set_id = ? ORDER BY (parent_id IS NOT NULL), id');
        $it->execute([$s['id']]);
        $imap = [];
        foreach ($it->fetchAll() as $i) {
            $pdo->prepare('INSERT INTO alignment_items (alignment_set_id, parent_id, code, label, sort, active) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$newSet, $i['parent_id'] ? ($imap[(int)$i['parent_id']] ?? null) : null, $i['code'], $i['label'], $i['sort'], $i['active']]);
            $imap[(int)$i['id']] = (int)$pdo->lastInsertId();
        }
    }
    $st = $pdo->prepare('SELECT * FROM approval_chains WHERE fiscal_year_id = ?');
    $st->execute([$from]);
    foreach ($st->fetchAll() as $c) {
        $pdo->prepare('INSERT INTO approval_chains (fiscal_year_id, request_type, name) VALUES (?, ?, ?)')->execute([$to, $c['request_type'], $c['name']]);
        $cid = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO approval_chain_steps (approval_chain_id, step_no, role, scope, can_return, can_reject)
            SELECT ?, step_no, role, scope, can_return, can_reject FROM approval_chain_steps WHERE approval_chain_id = ?')->execute([$cid, $c['id']]);
    }
}

return [
    'GET index' => function () {
        $fyId = request_fy();
        require_role(['planner', 'admin'], $fyId);
        $pdo = db();
        $inst = institution(require_institution());
        $q = function (string $sql) use ($pdo, $inst): array {
            $st = $pdo->prepare($sql);
            $st->execute([$inst['id']]);
            return $st->fetchAll();
        };
        $fys = array_map(function ($f) {
            $f['settings'] = $f['settings'] ? json_decode($f['settings'], true) : [];
            return $f;
        }, $q('SELECT * FROM fiscal_years WHERE institution_id = ? ORDER BY year_be DESC'));
        $st = $pdo->prepare('SELECT f.*, (SELECT COUNT(*) FROM ledger_accounts la WHERE la.fund_source_id = f.id) AS account_count,
            (SELECT COUNT(*) FROM budget_lines bl WHERE bl.fund_source_id = f.id) AS line_count
            FROM fund_sources f WHERE f.fiscal_year_id = ? ORDER BY f.sort, f.id');
        $st->execute([$fyId]);
        $funds = $st->fetchAll();
        $allowed = [];
        $st = $pdo->prepare('SELECT a.fund_source_id, a.expense_category_id FROM fund_source_allowed_categories a
            JOIN fund_sources f ON f.id = a.fund_source_id WHERE f.fiscal_year_id = ?');
        $st->execute([$fyId]);
        foreach ($st as $a) $allowed[(int)$a['fund_source_id']][] = (int)$a['expense_category_id'];
        foreach ($funds as &$f) $f['allowed_category_ids'] = $allowed[(int)$f['id']] ?? [];
        unset($f);
        $st = $pdo->prepare('SELECT * FROM alignment_sets WHERE fiscal_year_id = ? ORDER BY sort, id');
        $st->execute([$fyId]);
        $sets = $st->fetchAll();
        $items = [];
        if ($sets) {
            $items = $pdo->query('SELECT * FROM alignment_items WHERE alignment_set_id IN (' . implode(',', array_map(fn($s) => (int)$s['id'], $sets)) . ') ORDER BY sort, id')->fetchAll();
        }
        $st = $pdo->prepare('SELECT * FROM approval_chains WHERE fiscal_year_id = ? ORDER BY id');
        $st->execute([$fyId]);
        $chains = $st->fetchAll();
        foreach ($chains as &$c) {
            $s = $pdo->prepare('SELECT * FROM approval_chain_steps WHERE approval_chain_id = ? ORDER BY step_no');
            $s->execute([$c['id']]);
            $c['steps'] = $s->fetchAll();
        }
        unset($c);
        return [
            'general' => ['org_name' => $inst['name'], 'institution_code' => $inst['code'], 'current_fiscal_year_id' => current_fiscal_year_id()],
            'fiscal_years' => $fys,
            'units' => $q('SELECT u.*, (SELECT COUNT(*) FROM projects p WHERE p.org_unit_id = u.id) AS project_count FROM org_units u WHERE u.institution_id = ? ORDER BY sort, id'),
            'funds' => $funds,
            'categories' => $q('SELECT c.*, (SELECT COUNT(*) FROM budget_lines bl WHERE bl.expense_category_id = c.id) AS line_count FROM expense_categories c WHERE c.institution_id = ? ORDER BY sort, id'),
            'alignment_sets' => $sets,
            'alignment_items' => $items,
            'chains' => $chains,
            'role_labels' => ROLES,
        ];
    },

    'POST general' => function () {
        $fyId = request_fy();
        require_role(['planner', 'admin'], $fyId);
        $b = body();
        $inst = institution(require_institution());
        $before = ['org_name' => $inst['name'], 'current_fiscal_year_id' => setting('current_fiscal_year_id')];
        if (isset($b['org_name'])) db()->prepare('UPDATE institutions SET name = ? WHERE id = ?')->execute([str_in($b, 'org_name', 200), $inst['id']]);
        if (!empty($b['current_fiscal_year_id'])) {
            fiscal_year((int)$b['current_fiscal_year_id']);
            set_setting('current_fiscal_year_id', (string)(int)$b['current_fiscal_year_id']);
        }
        audit('settings.general', 'settings', null, $before, $b);
        return ['ok' => true];
    },

    'POST fiscal_year_save' => function () {
        $fyId = request_fy();
        settings_user($fyId);
        $b = body();
        $pdo = db();
        $statuses = ['setup', 'proposal_open', 'deliberation', 'execution', 'closing', 'closed'];
        $status = (string)($b['status'] ?? 'setup');
        if (!in_array($status, $statuses, true)) fail('สถานะปีงบประมาณไม่ถูกต้อง');
        foreach (['proposal_open_from', 'proposal_open_to'] as $k) {
            if (!empty($b[$k]) && !valid_date($b[$k])) fail('วันที่ช่วงรับข้อเสนอไม่ถูกต้อง');
        }
        $s = $b['settings'] ?? [];
        $weights = $s['weights'] ?? Seeder::DEFAULT_FY_SETTINGS['weights'];
        $weights = array_map('intval', array_intersect_key($weights, Seeder::DEFAULT_FY_SETTINGS['weights']) + Seeder::DEFAULT_FY_SETTINGS['weights']);
        if (array_sum($weights) !== 100) fail('น้ำหนักคะแนนรวมต้องเท่ากับ 100');
        $mode = in_array($s['adjustment_compare_mode'] ?? 'per_fund', ['per_fund', 'total'], true) ? $s['adjustment_compare_mode'] : 'per_fund';
        $due = max(1, (int)($s['health']['report_due_days'] ?? 15));
        $settings = json_encode(['weights' => $weights, 'health' => ['report_due_days' => $due], 'adjustment_compare_mode' => $mode], JSON_UNESCAPED_UNICODE);

        $id = (int)($b['id'] ?? 0);
        if ($id) {
            $before = fiscal_year($id);
            if ($before['status'] === 'closed' && $status !== 'closed') fail('ปีงบประมาณที่ปิดแล้วเปิดใหม่ไม่ได้');
            $pdo->prepare('UPDATE fiscal_years SET status = ?, proposal_open_from = ?, proposal_open_to = ?, settings = ? WHERE id = ?')
                ->execute([$status, $b['proposal_open_from'] ?: null, $b['proposal_open_to'] ?: null, $settings, $id]);
            audit('fiscal_year.update', 'fiscal_year', $id, $before, $b);
            return ['ok' => true, 'id' => $id];
        }
        $year = (int)($b['year_be'] ?? 0);
        if ($year < 2500 || $year > 2700) fail('ปีงบประมาณ (พ.ศ.) ไม่ถูกต้อง');
        return tx(function (PDO $pdo) use ($year, $status, $b, $settings) {
            $st = $pdo->prepare('SELECT COUNT(*) FROM fiscal_years WHERE institution_id = ? AND year_be = ?');
            $st->execute([current_institution_id(), $year]);
            if ((int)$st->fetchColumn()) fail('มีปีงบประมาณ ' . $year . ' อยู่แล้ว');
            $pdo->prepare('INSERT INTO fiscal_years (institution_id, year_be, starts_on, ends_on, status, proposal_open_from, proposal_open_to, settings) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([current_institution_id(), $year, sprintf('%04d-10-01', $year - 544), sprintf('%04d-09-30', $year - 543), $status,
                    $b['proposal_open_from'] ?: null, $b['proposal_open_to'] ?: null, $settings]);
            $id = (int)$pdo->lastInsertId();
            if (!empty($b['copy_from'])) {
                fiscal_year((int)$b['copy_from']);
                copy_year_master($pdo, (int)$b['copy_from'], $id);
            }
            audit('fiscal_year.create', 'fiscal_year', $id, null, ['year_be' => $year, 'copy_from' => $b['copy_from'] ?? null]);
            return ['ok' => true, 'id' => $id];
        });
    },

    'POST unit_save' => function () {
        settings_user(request_fy());
        $b = body();
        $kind = (string)($b['kind'] ?? '');
        if (!in_array($kind, UNIT_KINDS, true)) fail('ประเภทหน่วยงานไม่ถูกต้อง');
        $parent = !empty($b['parent_id']) ? (int)$b['parent_id'] : null;
        if ($parent) row_or_fail('org_units', $parent);
        $id = (int)($b['id'] ?? 0);
        assert_no_cycle('org_units', $id ?: null, $parent);
        $vals = [$parent, str_in($b, 'name', 200), $kind, str_in($b, 'code', 30, false),
            ($b['student_count_vc'] ?? '') === '' ? null : max(0, (int)$b['student_count_vc']),
            ($b['student_count_hvc'] ?? '') === '' ? null : max(0, (int)$b['student_count_hvc']),
            (int)($b['sort'] ?? 0), !empty($b['active']) ? 1 : 0];
        if ($id) {
            $before = row_or_fail('org_units', $id);
            db()->prepare('UPDATE org_units SET parent_id = ?, name = ?, kind = ?, code = ?, student_count_vc = ?, student_count_hvc = ?, sort = ?, active = ? WHERE id = ?')
                ->execute(array_merge($vals, [$id]));
            audit('org_unit.update', 'org_unit', $id, $before, $b);
        } else {
            db()->prepare('INSERT INTO org_units (institution_id, parent_id, name, kind, code, student_count_vc, student_count_hvc, sort, active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute(array_merge([current_institution_id()], $vals));
            $id = (int)db()->lastInsertId();
            audit('org_unit.create', 'org_unit', $id, null, $b);
        }
        return ['ok' => true, 'id' => $id];
    },

    'POST fund_save' => function () {
        $fyId = request_fy();
        settings_user($fyId);
        $b = body();
        $pdo = db();
        $type = ($b['fund_type'] ?? '') === '' ? null : (string)$b['fund_type'];
        $rule = ($b['permit_rule'] ?? '') === '' ? null : (string)$b['permit_rule'];
        if ($type !== null && !in_array($type, FUND_TYPES, true)) fail('ประเภทแหล่งเงินไม่ถูกต้อง');
        if ($rule !== null && !in_array($rule, PERMIT_RULES, true)) fail('กฎตรวจเงินไม่ถูกต้อง');
        $color = trim((string)($b['color_token'] ?? ''));
        if ($color !== '' && !preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) fail('สีต้องอยู่ในรูปแบบ #RRGGBB');
        $parent = !empty($b['parent_id']) ? (int)$b['parent_id'] : null;
        $id = (int)($b['id'] ?? 0);
        if ($parent) {
            $p = row_or_fail('fund_sources', $parent);
            if ((int)$p['fiscal_year_id'] !== $fyId) fail('แหล่งเงินแม่ต้องอยู่ในปีงบประมาณเดียวกัน');
            // A fund that already carries money/lines must stay a leaf (BR-20: accounts reference leaves only).
            $st = $pdo->prepare('SELECT (SELECT COUNT(*) FROM ledger_accounts WHERE fund_source_id = ?) + (SELECT COUNT(*) FROM budget_lines WHERE fund_source_id = ?) + (SELECT COUNT(*) FROM revenue_estimates WHERE fund_source_id = ?)');
            $st->execute([$parent, $parent, $parent]);
            if ((int)$st->fetchColumn() > 0 && $p['is_leaf']) fail('แหล่งเงิน "' . $p['name'] . '" มีรายการเงินแล้ว จึงเพิ่มแหล่งเงินย่อยใต้แหล่งนี้ไม่ได้');
        }
        assert_no_cycle('fund_sources', $id ?: null, $parent);
        $vals = [$parent, str_in($b, 'code', 30), str_in($b, 'name', 200), $color ?: null, $type, $rule, (int)($b['sort'] ?? 0), !empty($b['active']) ? 1 : 0];
        return tx(function (PDO $pdo) use ($id, $vals, $fyId, $b, $parent) {
            if ($id) {
                $before = row_or_fail('fund_sources', $id);
                if ((int)$before['fiscal_year_id'] !== $fyId) fail('ไม่พบแหล่งเงินในปีนี้');
                $pdo->prepare('UPDATE fund_sources SET parent_id = ?, code = ?, name = ?, color_token = ?, fund_type = ?, permit_rule = ?, sort = ?, active = ? WHERE id = ?')
                    ->execute(array_merge($vals, [$id]));
                audit('fund_source.update', 'fund_source', $id, $before, $b);
            } else {
                $pdo->prepare('INSERT INTO fund_sources (fiscal_year_id, parent_id, code, name, color_token, fund_type, permit_rule, sort, active, is_leaf) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)')
                    ->execute(array_merge([$fyId], $vals));
                $id = (int)$pdo->lastInsertId();
                audit('fund_source.create', 'fund_source', $id, null, $b);
            }
            // Recompute leaf flags for the year.
            $st = $pdo->prepare('SELECT DISTINCT parent_id FROM fund_sources WHERE fiscal_year_id = ? AND parent_id IS NOT NULL');
            $st->execute([$fyId]);
            $parents = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)) ?: [0];
            $pdo->prepare('UPDATE fund_sources SET is_leaf = (id NOT IN (' . implode(',', $parents) . ')) WHERE fiscal_year_id = ?')->execute([$fyId]);
            return ['ok' => true, 'id' => $id];
        });
    },

    'POST fund_categories' => function () {
        $fyId = request_fy();
        settings_user($fyId);
        $b = body();
        $fund = row_or_fail('fund_sources', (int)($b['fund_source_id'] ?? 0));
        if ((int)$fund['fiscal_year_id'] !== $fyId) fail('ไม่พบแหล่งเงินในปีนี้');
        $ids = array_values(array_unique(array_map('intval', (array)($b['category_ids'] ?? []))));
        foreach ($ids as $cid) row_or_fail('expense_categories', $cid);
        return tx(function (PDO $pdo) use ($fund, $ids) {
            $pdo->prepare('DELETE FROM fund_source_allowed_categories WHERE fund_source_id = ?')->execute([$fund['id']]);
            $ins = $pdo->prepare('INSERT INTO fund_source_allowed_categories (fund_source_id, expense_category_id) VALUES (?, ?)');
            foreach ($ids as $cid) $ins->execute([$fund['id'], $cid]);
            audit('fund_source.categories', 'fund_source', (int)$fund['id'], null, ['category_ids' => $ids]);
            return ['ok' => true];
        });
    },

    'POST category_save' => function () {
        settings_user(request_fy());
        $b = body();
        $parent = !empty($b['parent_id']) ? (int)$b['parent_id'] : null;
        $id = (int)($b['id'] ?? 0);
        if ($parent) {
            $p = row_or_fail('expense_categories', $parent);
            if ($p['parent_id']) fail('หมวดรายจ่ายมีได้ 2 ระดับ (หมวด > หมวดย่อย)');
            $st = db()->prepare('SELECT COUNT(*) FROM budget_lines WHERE expense_category_id = ?');
            $st->execute([$parent]);
            if ((int)$st->fetchColumn() > 0) {
                $st = db()->prepare('SELECT COUNT(*) FROM expense_categories WHERE parent_id = ?');
                $st->execute([$parent]);
                if (!(int)$st->fetchColumn()) fail('หมวด "' . $p['name'] . '" ถูกใช้ในรายการงบแล้ว จึงเพิ่มหมวดย่อยไม่ได้');
            }
        }
        if ($id && $parent === $id) fail('เลือกตัวเองเป็นหมวดแม่ไม่ได้');
        $vals = [$parent, str_in($b, 'code', 30), str_in($b, 'name', 200), (int)($b['sort'] ?? 0), !empty($b['active']) ? 1 : 0];
        if ($id) {
            $before = row_or_fail('expense_categories', $id);
            db()->prepare('UPDATE expense_categories SET parent_id = ?, code = ?, name = ?, sort = ?, active = ? WHERE id = ?')->execute(array_merge($vals, [$id]));
            audit('category.update', 'expense_category', $id, $before, $b);
        } else {
            db()->prepare('INSERT INTO expense_categories (institution_id, parent_id, code, name, sort, active) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute(array_merge([current_institution_id()], $vals));
            $id = (int)db()->lastInsertId();
            audit('category.create', 'expense_category', $id, null, $b);
        }
        return ['ok' => true, 'id' => $id];
    },

    'POST alignment_set_save' => function () {
        $fyId = request_fy();
        settings_user($fyId);
        $b = body();
        $id = (int)($b['id'] ?? 0);
        $code = str_in($b, 'code', 40);
        if (!preg_match('/^[a-z0-9_]+$/', $code)) fail('รหัสชุดใช้ a-z 0-9 _');
        $vals = [$code, str_in($b, 'name', 200), !empty($b['required']) ? 1 : 0, (int)($b['sort'] ?? 0)];
        if ($id) {
            $before = row_or_fail('alignment_sets', $id);
            if ((int)$before['fiscal_year_id'] !== $fyId) fail('ไม่พบข้อมูลในปีนี้');
            db()->prepare('UPDATE alignment_sets SET code = ?, name = ?, required = ?, sort = ? WHERE id = ?')->execute(array_merge($vals, [$id]));
            audit('alignment_set.update', 'alignment_set', $id, $before, $b);
        } else {
            db()->prepare('INSERT INTO alignment_sets (fiscal_year_id, code, name, required, sort) VALUES (?, ?, ?, ?, ?)')->execute(array_merge([$fyId], $vals));
            $id = (int)db()->lastInsertId();
            audit('alignment_set.create', 'alignment_set', $id, null, $b);
        }
        return ['ok' => true, 'id' => $id];
    },

    'POST alignment_item_save' => function () {
        $fyId = request_fy();
        settings_user($fyId);
        $b = body();
        $set = row_or_fail('alignment_sets', (int)($b['alignment_set_id'] ?? 0));
        if ((int)$set['fiscal_year_id'] !== $fyId) fail('ไม่พบชุดความสอดคล้องในปีนี้');
        $parent = !empty($b['parent_id']) ? (int)$b['parent_id'] : null;
        if ($parent && (int)row_or_fail('alignment_items', $parent)['alignment_set_id'] !== (int)$set['id']) fail('รายการแม่ต้องอยู่ในชุดเดียวกัน');
        $id = (int)($b['id'] ?? 0);
        assert_no_cycle('alignment_items', $id ?: null, $parent);
        $vals = [$set['id'], $parent, str_in($b, 'code', 40, false), str_in($b, 'label', 500), (int)($b['sort'] ?? 0), !empty($b['active']) ? 1 : 0];
        if ($id) {
            $before = row_or_fail('alignment_items', $id);
            if ((int)row_or_fail('alignment_sets', (int)$before['alignment_set_id'])['fiscal_year_id'] !== $fyId) fail('ไม่พบรายการในปีนี้');
            db()->prepare('UPDATE alignment_items SET alignment_set_id = ?, parent_id = ?, code = ?, label = ?, sort = ?, active = ? WHERE id = ?')->execute(array_merge($vals, [$id]));
            audit('alignment_item.update', 'alignment_item', $id, $before, $b);
        } else {
            db()->prepare('INSERT INTO alignment_items (alignment_set_id, parent_id, code, label, sort, active) VALUES (?, ?, ?, ?, ?, ?)')->execute($vals);
            $id = (int)db()->lastInsertId();
            audit('alignment_item.create', 'alignment_item', $id, null, $b);
        }
        return ['ok' => true, 'id' => $id];
    },

    'POST chain_save' => function () {
        $fyId = request_fy();
        settings_user($fyId);
        $b = body();
        $chain = row_or_fail('approval_chains', (int)($b['id'] ?? 0));
        if ((int)$chain['fiscal_year_id'] !== $fyId) fail('ไม่พบสายอนุมัติในปีนี้');
        $steps = array_values((array)($b['steps'] ?? []));
        if (!$steps) fail('สายอนุมัติต้องมีอย่างน้อย 1 ขั้น');
        foreach ($steps as $s) {
            if (!in_array($s['role'] ?? '', INSTITUTION_ROLES, true) || ($s['role'] ?? '') === 'admin') fail('บทบาทในสายอนุมัติไม่ถูกต้อง');
            if (!in_array($s['scope'] ?? '', SCOPES, true)) fail('ขอบเขตในสายอนุมัติไม่ถูกต้อง');
        }
        return tx(function (PDO $pdo) use ($chain, $steps) {
            $old = $pdo->prepare('SELECT * FROM approval_chain_steps WHERE approval_chain_id = ? ORDER BY step_no');
            $old->execute([$chain['id']]);
            $before = $old->fetchAll();
            $pdo->prepare('DELETE FROM approval_chain_steps WHERE approval_chain_id = ?')->execute([$chain['id']]);
            $ins = $pdo->prepare('INSERT INTO approval_chain_steps (approval_chain_id, step_no, role, scope, can_return, can_reject) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($steps as $i => $s) $ins->execute([$chain['id'], $i + 1, $s['role'], $s['scope'], !empty($s['can_return']) ? 1 : 0, !empty($s['can_reject']) ? 1 : 0]);
            audit('approval_chain.update', 'approval_chain', (int)$chain['id'], $before, $steps);
            return ['ok' => true];
        });
    },
];
