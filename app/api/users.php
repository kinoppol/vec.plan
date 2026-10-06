<?php
declare(strict_types=1);

// User and role management — admin only (spec §3: admin manages users, roles, backups).

function require_admin(): array
{
    return require_role('admin');
}

function active_admin_count(?int $excludeUser = null): int
{
    $st = db()->prepare("SELECT COUNT(DISTINCT u.id) FROM users u JOIN role_assignments r ON r.user_id = u.id AND r.role = 'admin'
        WHERE u.active = 1 AND u.id <> ?");
    $st->execute([$excludeUser ?? 0]);
    return (int)$st->fetchColumn();
}

return [
    'GET index' => function () {
        require_admin();
        $pdo = db();
        $users = $pdo->query('SELECT id, username, name, email, position_title, active, last_login_at, created_at FROM users ORDER BY active DESC, name')->fetchAll();
        $roles = [];
        foreach ($pdo->query('SELECT r.*, ou.name AS unit_name, fy.year_be FROM role_assignments r
                LEFT JOIN org_units ou ON ou.id = r.org_unit_id LEFT JOIN fiscal_years fy ON fy.id = r.fiscal_year_id ORDER BY r.id') as $r) {
            $roles[(int)$r['user_id']][] = ['role' => $r['role'], 'org_unit_id' => $r['org_unit_id'] ? (int)$r['org_unit_id'] : null,
                'fiscal_year_id' => $r['fiscal_year_id'] ? (int)$r['fiscal_year_id'] : null, 'unit_name' => $r['unit_name'], 'year_be' => $r['year_be']];
        }
        foreach ($users as &$u) $u['roles'] = $roles[(int)$u['id']] ?? [];
        unset($u);
        return ['users' => $users, 'role_labels' => ROLES, 'unit_scoped_roles' => UNIT_SCOPED_ROLES];
    },

    'POST save' => function () {
        $me = require_admin();
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
        if ($id) {
            $st = $pdo->prepare('SELECT id, username, name, email, position_title, active FROM users WHERE id = ?');
            $st->execute([$id]);
            $before = $st->fetch();
            if (!$before) fail('ไม่พบผู้ใช้', 404);
            if (!$active && $id === (int)$me['id']) fail('ปิดการใช้งานบัญชีของตัวเองไม่ได้');
            if (!$active && $before['active'] && active_admin_count($id) === 0) {
                $st = $pdo->prepare("SELECT COUNT(*) FROM role_assignments WHERE user_id = ? AND role = 'admin'");
                $st->execute([$id]);
                if ((int)$st->fetchColumn()) fail('ต้องมีผู้ดูแลระบบที่ใช้งานได้อย่างน้อย 1 คน');
            }
            $pdo->prepare('UPDATE users SET username = ?, name = ?, email = ?, position_title = ?, active = ? WHERE id = ?')
                ->execute([$username, $name, $email, trim((string)($b['position_title'] ?? '')) ?: null, $active, $id]);
            audit('user.update', 'user', $id, $before, ['username' => $username, 'name' => $name, 'email' => $email, 'active' => $active]);
        } else {
            $pass = (string)($b['password'] ?? '');
            if (mb_strlen($pass) < MIN_PASSWORD_LENGTH) fail('รหัสผ่านต้องมีอย่างน้อย ' . MIN_PASSWORD_LENGTH . ' ตัวอักษร');
            $pdo->prepare('INSERT INTO users (username, name, email, password_hash, position_title, active, password_changed_at) VALUES (?, ?, ?, ?, ?, ?, NOW())')
                ->execute([$username, $name, $email, password_hash($pass, PASSWORD_DEFAULT), trim((string)($b['position_title'] ?? '')) ?: null, $active]);
            $id = (int)$pdo->lastInsertId();
            audit('user.create', 'user', $id, null, ['username' => $username, 'name' => $name]);
        }
        return ['ok' => true, 'id' => $id];
    },

    'POST roles' => function () {
        $me = require_admin();
        $b = body();
        $userId = (int)($b['user_id'] ?? 0);
        $roles = array_values((array)($b['roles'] ?? []));
        $clean = [];
        foreach ($roles as $r) {
            $role = (string)($r['role'] ?? '');
            if (!isset(ROLES[$role])) fail('บทบาทไม่ถูกต้อง');
            $unit = !empty($r['org_unit_id']) ? (int)$r['org_unit_id'] : null;
            $fy = !empty($r['fiscal_year_id']) ? (int)$r['fiscal_year_id'] : null;
            if (in_array($role, UNIT_SCOPED_ROLES, true) && !$unit) fail('บทบาท "' . ROLES[$role] . '" ต้องระบุหน่วยงาน');
            if ($role === 'admin') { $unit = null; $fy = null; }
            $key = $role . '|' . $unit . '|' . $fy;
            $clean[$key] = [$role, $unit, $fy];
        }
        $hasAdmin = (bool)array_filter($clean, fn($r) => $r[0] === 'admin');
        if (!$hasAdmin && active_admin_count($userId) === 0) fail('ต้องมีผู้ดูแลระบบที่ใช้งานได้อย่างน้อย 1 คน');
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

    'POST reset_password' => function () {
        require_admin();
        $b = body();
        $id = (int)($b['user_id'] ?? 0);
        $pass = (string)($b['password'] ?? '');
        if (mb_strlen($pass) < MIN_PASSWORD_LENGTH) fail('รหัสผ่านต้องมีอย่างน้อย ' . MIN_PASSWORD_LENGTH . ' ตัวอักษร');
        $st = db()->prepare('UPDATE users SET password_hash = ?, password_changed_at = NOW() WHERE id = ?');
        $st->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
        if (!$st->rowCount()) fail('ไม่พบผู้ใช้', 404);
        audit('user.reset_password', 'user', $id);
        return ['ok' => true];
    },
];
