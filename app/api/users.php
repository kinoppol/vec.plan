<?php
declare(strict_types=1);

// User and role management — institution admin only (spec §3: admin manages users, roles, backups).
// Every query is limited to the admin's own institution.

function require_admin(): array
{
    require_institution();
    return require_role('admin');
}

function active_admin_count(int $institutionId, ?int $excludeUser = null): int
{
    $st = db()->prepare("SELECT COUNT(DISTINCT u.id) FROM users u JOIN role_assignments r ON r.user_id = u.id AND r.role = 'admin'
        WHERE u.institution_id = ? AND u.active = 1 AND u.id <> ?");
    $st->execute([$institutionId, $excludeUser ?? 0]);
    return (int)$st->fetchColumn();
}

/** A user of the current institution, or 404. */
function institution_user(int $id): array
{
    $st = db()->prepare('SELECT id, username, name, email, position_title, active FROM users WHERE id = ? AND institution_id = ?');
    $st->execute([$id, current_institution_id()]);
    $u = $st->fetch();
    if (!$u) fail('ไม่พบผู้ใช้', 404);
    return $u;
}

return [
    'GET index' => function () {
        require_admin();
        $inst = current_institution_id();
        $pdo = db();
        $st = $pdo->prepare('SELECT * FROM users WHERE institution_id = ? ORDER BY active DESC, name');
        $st->execute([$inst]);
        $keep = array_flip(['id', 'username', 'name', 'email', 'position_title', 'active', 'last_login_at', 'created_at', 'source', 'synced_at']);
        $users = array_map(fn($u) => array_intersect_key($u, $keep) + ['avatar' => avatar_version($u)], $st->fetchAll());
        $roles = [];
        $st = $pdo->prepare('SELECT r.*, ou.name AS unit_name, fy.year_be FROM role_assignments r JOIN users u ON u.id = r.user_id
                LEFT JOIN org_units ou ON ou.id = r.org_unit_id LEFT JOIN fiscal_years fy ON fy.id = r.fiscal_year_id WHERE u.institution_id = ? ORDER BY r.id');
        $st->execute([$inst]);
        foreach ($st as $r) {
            $roles[(int)$r['user_id']][] = ['role' => $r['role'], 'org_unit_id' => $r['org_unit_id'] ? (int)$r['org_unit_id'] : null,
                'fiscal_year_id' => $r['fiscal_year_id'] ? (int)$r['fiscal_year_id'] : null, 'unit_name' => $r['unit_name'], 'year_be' => $r['year_be']];
        }
        foreach ($users as &$u) $u['roles'] = $roles[(int)$u['id']] ?? [];
        unset($u);
        return ['users' => $users, 'role_labels' => array_intersect_key(ROLES, array_flip(INSTITUTION_ROLES)), 'unit_scoped_roles' => UNIT_SCOPED_ROLES];
    },

    'POST save' => function () {
        $me = require_admin();
        $inst = current_institution_id();
        $b = body();
        $id = (int)($b['id'] ?? 0);
        $username = trim((string)($b['username'] ?? ''));
        $name = trim((string)($b['name'] ?? ''));
        $email = trim((string)($b['email'] ?? '')) ?: null;
        $active = !empty($b['active']) ? 1 : 0;
        if (!preg_match('/^[A-Za-z0-9._-]{3,60}$/', $username)) fail('ชื่อผู้ใช้ 3–60 ตัว ใช้ A-Z a-z 0-9 . _ -');
        if ($name === '') fail('กรุณาระบุชื่อ-สกุล');
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('อีเมลไม่ถูกต้อง');
        $pdo = db();
        // Usernames are unique across the whole system (login does not ask for the institution).
        $st = $pdo->prepare('SELECT COUNT(*) FROM users WHERE (username = ? OR (email IS NOT NULL AND email = ?)) AND id <> ?');
        $st->execute([$username, $email, $id]);
        if ((int)$st->fetchColumn()) fail('ชื่อผู้ใช้หรืออีเมลนี้มีผู้ใช้แล้วในระบบ');
        if ($id) {
            $before = institution_user($id);
            if (!$active && $id === (int)$me['id']) fail('ปิดการใช้งานบัญชีของตัวเองไม่ได้');
            if (!$active && $before['active'] && active_admin_count($inst, $id) === 0) {
                $st = $pdo->prepare("SELECT COUNT(*) FROM role_assignments WHERE user_id = ? AND role = 'admin'");
                $st->execute([$id]);
                if ((int)$st->fetchColumn()) fail('ต้องมีผู้ดูแลระบบสถานศึกษาที่ใช้งานได้อย่างน้อย 1 คน');
            }
            $pdo->prepare('UPDATE users SET username = ?, name = ?, email = ?, position_title = ?, active = ? WHERE id = ? AND institution_id = ?')
                ->execute([$username, $name, $email, trim((string)($b['position_title'] ?? '')) ?: null, $active, $id, $inst]);
            audit('user.update', 'user', $id, $before, ['username' => $username, 'name' => $name, 'email' => $email, 'active' => $active]);
        } else {
            $pass = (string)($b['password'] ?? '');
            if (mb_strlen($pass) < MIN_PASSWORD_LENGTH) fail('รหัสผ่านต้องมีอย่างน้อย ' . MIN_PASSWORD_LENGTH . ' ตัวอักษร');
            $pdo->prepare('INSERT INTO users (institution_id, username, name, email, password_hash, position_title, active, password_changed_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())')
                ->execute([$inst, $username, $name, $email, password_hash($pass, PASSWORD_DEFAULT), trim((string)($b['position_title'] ?? '')) ?: null, $active]);
            $id = (int)$pdo->lastInsertId();
            audit('user.create', 'user', $id, null, ['username' => $username, 'name' => $name]);
        }
        return ['ok' => true, 'id' => $id];
    },

    'POST roles' => function () {
        $me = require_admin();
        $inst = current_institution_id();
        $b = body();
        $userId = (int)($b['user_id'] ?? 0);
        institution_user($userId);
        $roles = array_values((array)($b['roles'] ?? []));
        $pdo = db();
        $owned = function (string $table, int $id) use ($pdo, $inst): bool {
            $st = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE id = ? AND institution_id = ?");
            $st->execute([$id, $inst]);
            return (bool)$st->fetchColumn();
        };
        $clean = [];
        foreach ($roles as $r) {
            $role = (string)($r['role'] ?? '');
            if (!in_array($role, INSTITUTION_ROLES, true)) fail('บทบาทไม่ถูกต้อง');
            $unit = !empty($r['org_unit_id']) ? (int)$r['org_unit_id'] : null;
            $fy = !empty($r['fiscal_year_id']) ? (int)$r['fiscal_year_id'] : null;
            if (in_array($role, UNIT_SCOPED_ROLES, true) && !$unit) fail('บทบาท "' . ROLES[$role] . '" ต้องระบุหน่วยงาน');
            if ($role === 'admin') { $unit = null; $fy = null; }
            if ($unit && !$owned('org_units', $unit)) fail('ไม่พบหน่วยงานในสถานศึกษานี้');
            if ($fy && !$owned('fiscal_years', $fy)) fail('ไม่พบปีงบประมาณในสถานศึกษานี้');
            $key = $role . '|' . $unit . '|' . $fy;
            $clean[$key] = [$role, $unit, $fy];
        }
        $hasAdmin = (bool)array_filter($clean, fn($r) => $r[0] === 'admin');
        if (!$hasAdmin && active_admin_count($inst, $userId) === 0) fail('ต้องมีผู้ดูแลระบบสถานศึกษาที่ใช้งานได้อย่างน้อย 1 คน');
        if (!$hasAdmin && $userId === (int)$me['id']) fail('ถอดบทบาทผู้ดูแลระบบของตัวเองไม่ได้');
        return tx(function (PDO $pdo) use ($userId, $clean) {
            $st = $pdo->prepare('SELECT role, org_unit_id, fiscal_year_id FROM role_assignments WHERE user_id = ?');
            $st->execute([$userId]);
            $before = $st->fetchAll();
            $pdo->prepare('DELETE FROM role_assignments WHERE user_id = ?')->execute([$userId]);
            $ins = $pdo->prepare('INSERT INTO role_assignments (user_id, role, org_unit_id, fiscal_year_id) VALUES (?, ?, ?, ?)');
            foreach ($clean as [$role, $unit, $fy]) $ins->execute([$userId, $role, $unit, $fy]);
            audit('user.roles', 'user', $userId, $before, array_values($clean));
            return ['ok' => true];
        });
    },

    // ---------------------------------------------------------- transfer users from RMS (per institution)
    'GET rms' => function () {
        require_admin();
        return ['base_url' => RmsSync::baseUrl(), 'people_path' => RmsSync::PEOPLE_PATH, 'files_path' => RmsSync::FILES_PATH];
    },

    'POST rms_save' => function () {
        require_admin();
        $base = RmsSync::normalizeBase((string)(body()['base_url'] ?? ''));
        $before = RmsSync::baseUrl();
        set_setting('rms_base_url', $base);
        audit('settings.rms_base_url', 'settings', null, ['rms_base_url' => $before], ['rms_base_url' => $base]);
        return ['ok' => true, 'base_url' => $base];
    },

    // Fetch the RMS list and count what a transfer would do, without changing anything.
    'POST rms_preview' => function () {
        require_admin();
        session_write_close();
        @set_time_limit(120);
        return RmsSync::sync(true);
    },

    // Polled by the page while a preview / transfer runs (those requests released the session lock).
    'GET rms_progress' => function () {
        require_admin();
        return ['progress' => RmsSync::progress()];
    },

    'POST rms_sync' => function () {
        require_admin();
        session_write_close();
        @set_time_limit(900);
        return RmsSync::sync(false);
    },
    'POST reset_password' => function () {
        require_admin();
        $b = body();
        $id = (int)($b['user_id'] ?? 0);
        institution_user($id);
        $pass = (string)($b['password'] ?? '');
        if (mb_strlen($pass) < MIN_PASSWORD_LENGTH) fail('รหัสผ่านต้องมีอย่างน้อย ' . MIN_PASSWORD_LENGTH . ' ตัวอักษร');
        db()->prepare('UPDATE users SET password_hash = ?, password_changed_at = NOW() WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
        audit('user.reset_password', 'user', $id);
        return ['ok' => true];
    },
];
