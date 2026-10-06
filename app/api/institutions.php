<?php
declare(strict_types=1);

// Institutions (multi-institution mode) — central admin only. The central admin creates institutions and their
// admins; each institution's admin then manages its own users and data.

function require_central(): array
{
    if (!is_multi()) fail('ระบบใช้งานแบบสถานศึกษาเดียว', 409);
    $u = require_login();
    if (!is_super_admin($u)) fail('เฉพาะผู้ดูแลระบบกลางเท่านั้น', 403);
    return $u;
}

function admin_input(array $b): array
{
    $username = trim((string)($b['username'] ?? ''));
    $name = trim((string)($b['name'] ?? ''));
    $email = trim((string)($b['email'] ?? '')) ?: null;
    if (!preg_match('/^[A-Za-z0-9._-]{3,60}$/', $username)) fail('ชื่อผู้ใช้ผู้ดูแล 3–60 ตัว ใช้ A-Z a-z 0-9 . _ -');
    if ($name === '') fail('กรุณาระบุชื่อ-สกุลผู้ดูแล');
    if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('อีเมลไม่ถูกต้อง');
    return [$username, $name, $email];
}

/** Create a new admin user of $institutionId, or reset an existing one of that institution. */
function upsert_institution_admin(PDO $pdo, int $institutionId, string $username, string $name, ?string $email, string $pass): int
{
    if (mb_strlen($pass) < MIN_PASSWORD_LENGTH) fail('รหัสผ่านต้องมีอย่างน้อย ' . MIN_PASSWORD_LENGTH . ' ตัวอักษร');
    $st = $pdo->prepare('SELECT id, institution_id FROM users WHERE username = ? OR (email IS NOT NULL AND email = ?)');
    $st->execute([$username, $email]);
    $hits = $st->fetchAll();
    foreach ($hits as $h) {
        if ((int)$h['institution_id'] !== $institutionId) fail('ชื่อผู้ใช้หรืออีเมลนี้มีผู้ใช้แล้วในระบบ');
    }
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    if (count($hits) > 1) fail('ชื่อผู้ใช้และอีเมลตรงกับผู้ใช้คนละคน');
    if ($hits) {
        $id = (int)$hits[0]['id'];
        $pdo->prepare('UPDATE users SET username = ?, name = ?, email = ?, password_hash = ?, active = 1, password_changed_at = NOW() WHERE id = ?')
            ->execute([$username, $name, $email, $hash, $id]);
    } else {
        $pdo->prepare('INSERT INTO users (institution_id, username, name, email, password_hash, position_title, password_changed_at) VALUES (?, ?, ?, ?, ?, ?, NOW())')
            ->execute([$institutionId, $username, $name, $email, $hash, 'ผู้ดูแลระบบสถานศึกษา']);
        $id = (int)$pdo->lastInsertId();
    }
    $pdo->prepare("DELETE FROM role_assignments WHERE user_id = ? AND role = 'admin'")->execute([$id]);
    $pdo->prepare("INSERT INTO role_assignments (user_id, role, org_unit_id, fiscal_year_id) VALUES (?, 'admin', NULL, NULL)")->execute([$id]);
    return $id;
}

return [
    'GET index' => function () {
        require_central();
        $pdo = db();
        $rows = $pdo->query('SELECT i.*,
                (SELECT COUNT(*) FROM users u WHERE u.institution_id = i.id) AS user_count,
                (SELECT COUNT(*) FROM users u WHERE u.institution_id = i.id AND u.active = 1) AS active_user_count,
                (SELECT COUNT(*) FROM fiscal_years f WHERE f.institution_id = i.id) AS fiscal_year_count,
                (SELECT COUNT(*) FROM projects p JOIN fiscal_years f ON f.id = p.fiscal_year_id WHERE f.institution_id = i.id) AS project_count
            FROM institutions i ORDER BY i.active DESC, i.name')->fetchAll();
        $admins = [];
        foreach ($pdo->query("SELECT u.id, u.institution_id, u.username, u.name, u.email, u.active, u.last_login_at FROM users u
                JOIN role_assignments r ON r.user_id = u.id AND r.role = 'admin' WHERE u.institution_id IS NOT NULL ORDER BY u.name") as $a) {
            $admins[(int)$a['institution_id']][] = $a;
        }
        foreach ($rows as &$r) $r['admins'] = $admins[(int)$r['id']] ?? [];
        unset($r);
        return ['institutions' => $rows, 'system_name' => system_setting('system_name', 'ระบบแผนงานและงบประมาณสถานศึกษา'), 'default_year_be' => Seeder::currentYearBe()];
    },

    // Create (with its first admin and base master data) or update an institution.
    'POST save' => function () {
        require_central();
        $b = body();
        $id = (int)($b['id'] ?? 0);
        $code = strtoupper(trim((string)($b['code'] ?? '')));
        $name = trim((string)($b['name'] ?? ''));
        $active = array_key_exists('active', $b) ? (!empty($b['active']) ? 1 : 0) : 1;
        if (!preg_match('/^[A-Z0-9_-]{2,30}$/', $code)) fail('รหัสสถานศึกษา 2–30 ตัว ใช้ A-Z 0-9 _ -');
        if ($name === '') fail('กรุณาระบุชื่อสถานศึกษา');
        $pdo = db();
        $st = $pdo->prepare('SELECT COUNT(*) FROM institutions WHERE code = ? AND id <> ?');
        $st->execute([$code, $id]);
        if ((int)$st->fetchColumn()) fail('รหัสสถานศึกษานี้มีอยู่แล้ว');
        if ($id) {
            $before = institution($id);
            $pdo->prepare('UPDATE institutions SET code = ?, name = ?, active = ? WHERE id = ?')->execute([$code, mb_substr($name, 0, 200), $active, $id]);
            audit('institution.update', 'institution', $id, $before, ['code' => $code, 'name' => $name, 'active' => $active]);
            return ['ok' => true, 'id' => $id];
        }
        [$username, $adminName, $email] = admin_input((array)($b['admin'] ?? []));
        $pass = (string)($b['admin']['password'] ?? '');
        $year = (int)($b['year_be'] ?? Seeder::currentYearBe());
        if ($year < 2500 || $year > 2700) fail('ปีงบประมาณ (พ.ศ.) ไม่ถูกต้อง');
        return tx(function (PDO $pdo) use ($code, $name, $active, $username, $adminName, $email, $pass, $year) {
            $pdo->prepare('INSERT INTO institutions (code, name, active) VALUES (?, ?, ?)')->execute([$code, mb_substr($name, 0, 200), $active]);
            $id = (int)$pdo->lastInsertId();
            Seeder::base($pdo, $id, $year);
            $adminId = upsert_institution_admin($pdo, $id, $username, $adminName, $email, $pass);
            audit('institution.create', 'institution', $id, null, ['code' => $code, 'name' => $name, 'year_be' => $year, 'admin' => $username]);
            return ['ok' => true, 'id' => $id, 'admin_id' => $adminId];
        });
    },

    // Add another admin to an institution, or reset an existing admin's password.
    'POST admin_save' => function () {
        require_central();
        $b = body();
        $inst = institution((int)($b['institution_id'] ?? 0));
        [$username, $name, $email] = admin_input($b);
        return tx(function (PDO $pdo) use ($inst, $username, $name, $email, $b) {
            $id = upsert_institution_admin($pdo, (int)$inst['id'], $username, $name, $email, (string)($b['password'] ?? ''));
            audit('institution.admin', 'institution', (int)$inst['id'], null, ['admin' => $username, 'user_id' => $id]);
            return ['ok' => true, 'id' => $id];
        });
    },

    'POST system_name' => function () {
        require_central();
        $name = trim((string)(body()['system_name'] ?? ''));
        if ($name === '') fail('กรุณาระบุชื่อระบบ');
        set_setting('system_name', mb_substr($name, 0, 200), 0);
        audit('settings.system_name', 'settings', null, null, ['system_name' => $name]);
        return ['ok' => true];
    },
];
