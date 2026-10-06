<?php
declare(strict_types=1);

// System tools: database migrations, backups, environment info (system admin), audit log (scoped per institution),
// and switching a single-institution system to multi-institution mode.

function sys_admin(): array
{
    return require_system_admin();
}

function migrator(): Migrator
{
    return new Migrator(db());
}

function migration_name(array $b): string
{
    $name = (string)($b['name'] ?? '');
    if (!preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]+\.(sql|php)$/', $name)) fail('ชื่อ migration ไม่ถูกต้อง');
    return $name;
}

function maybe_backup(array $b, string $label): ?array
{
    if (empty($b['backup'])) return null;
    return Backup::create(db(), $label);
}

return [
    'GET migrations' => function () {
        sys_admin();
        $m = migrator();
        $list = $m->status();
        return [
            'migrations' => $list,
            'pending' => count(array_filter($list, fn($x) => !$x['applied'])),
            'writable' => is_writable(MIGRATIONS_DIR),
            'directory' => 'migrations/',
        ];
    },

    'GET migration_source' => function () {
        sys_admin();
        $name = migration_name($_GET);
        try {
            return ['name' => $name, 'source' => migrator()->source($name)];
        } catch (RuntimeException $e) {
            fail($e->getMessage(), 404);
        }
    },

    'POST migrate' => function () {
        $u = sys_admin();
        $b = body();
        $only = !empty($b['name']) ? migration_name($b) : null;
        $backup = maybe_backup($b, 'before_migrate');
        try {
            $results = migrator()->migrate((int)$u['id'], $only);
        } catch (RuntimeException $e) {
            fail($e->getMessage());
        }
        audit('migration.run', 'migration', null, null, ['only' => $only, 'results' => $results, 'backup' => $backup['file'] ?? null]);
        $failed = array_filter($results, fn($r) => !$r['ok']);
        return ['ok' => !$failed, 'results' => $results, 'backup' => $backup];
    },

    'POST rollback' => function () {
        sys_admin();
        $b = body();
        $only = !empty($b['name']) ? migration_name($b) : null;
        $backup = maybe_backup($b, 'before_rollback');
        try {
            $results = migrator()->rollback($only);
        } catch (RuntimeException $e) {
            fail($e->getMessage());
        }
        audit('migration.rollback', 'migration', null, null, ['only' => $only, 'results' => $results, 'backup' => $backup['file'] ?? null]);
        $failed = array_filter($results, fn($r) => !$r['ok']);
        return ['ok' => !$failed, 'results' => $results, 'backup' => $backup];
    },

    'POST mark' => function () {
        $u = sys_admin();
        $b = body();
        $name = migration_name($b);
        try {
            if (!empty($b['applied'])) migrator()->markApplied($name, (int)$u['id']);
            else migrator()->markPending($name);
        } catch (RuntimeException $e) {
            fail($e->getMessage());
        }
        audit('migration.mark', 'migration', null, null, ['name' => $name, 'applied' => !empty($b['applied'])]);
        return ['ok' => true];
    },

    'POST create_migration' => function () {
        sys_admin();
        $b = body();
        try {
            $name = migrator()->create((string)($b['slug'] ?? ''), (string)($b['description'] ?? ''), (string)($b['up'] ?? ''), (string)($b['down'] ?? ''));
        } catch (RuntimeException $e) {
            fail($e->getMessage());
        }
        audit('migration.create', 'migration', null, null, ['name' => $name]);
        return ['ok' => true, 'name' => $name];
    },

    'GET backups' => function () {
        sys_admin();
        return ['backups' => Backup::list(), 'writable' => is_dir(BACKUP_DIR) && is_writable(BACKUP_DIR)];
    },

    'POST backup' => function () {
        sys_admin();
        $r = Backup::create(db(), 'manual');
        audit('backup.create', 'backup', null, null, $r);
        return ['ok' => true] + $r;
    },

    'GET backup_download' => function () {
        sys_admin();
        try {
            $path = Backup::path((string)($_GET['file'] ?? ''));
        } catch (RuntimeException $e) {
            fail($e->getMessage(), 404);
        }
        audit('backup.download', 'backup', null, null, ['file' => basename($path)]);
        header('Content-Type: application/gzip');
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        readfile($path);
        exit;
    },

    'POST backup_delete' => function () {
        sys_admin();
        try {
            $path = Backup::path((string)(body()['file'] ?? ''));
        } catch (RuntimeException $e) {
            fail($e->getMessage(), 404);
        }
        unlink($path);
        audit('backup.delete', 'backup', null, null, ['file' => basename($path)]);
        return ['ok' => true];
    },

    'GET audit' => function () {
        // The central admin sees every institution; an institution admin sees only their own.
        $u = require_login();
        $where = ['1 = 1'];
        $params = [];
        if (is_super_admin($u)) {
            if (!empty($_GET['institution_id'])) { $where[] = 'a.institution_id = ?'; $params[] = (int)$_GET['institution_id']; }
        } else {
            require_institution();
            if (!has_role('admin', null, $u)) fail('ไม่มีสิทธิ์ดำเนินการนี้', 403);
            $where[] = 'a.institution_id = ?';
            $params[] = $u['institution_id'];
        }
        if (!empty($_GET['action'])) { $where[] = 'a.action LIKE ?'; $params[] = $_GET['action'] . '%'; }
        if (!empty($_GET['user_id'])) { $where[] = 'a.user_id = ?'; $params[] = (int)$_GET['user_id']; }
        if (!empty($_GET['subject_type'])) { $where[] = 'a.subject_type = ?'; $params[] = (string)$_GET['subject_type']; }
        $page = max(1, (int)($_GET['page'] ?? 1));
        $st = db()->prepare('SELECT COUNT(*) FROM audit_logs a WHERE ' . implode(' AND ', $where));
        $st->execute($params);
        $total = (int)$st->fetchColumn();
        $st = db()->prepare('SELECT a.*, u.name AS user_name, u.username, i.name AS institution_name FROM audit_logs a
            LEFT JOIN users u ON u.id = a.user_id LEFT JOIN institutions i ON i.id = a.institution_id
            WHERE ' . implode(' AND ', $where) . ' ORDER BY a.id DESC LIMIT 100 OFFSET ' . (($page - 1) * 100));
        $st->execute($params);
        return ['rows' => $st->fetchAll(), 'total' => $total, 'page' => $page];
    },

    /**
     * Switch a single-institution system to multi-institution mode. The admin who does it becomes the
     * central admin (no institution); the existing data stays as the first institution.
     */
    'POST enable_multi' => function () {
        $u = require_system_admin();
        if (is_multi()) fail('ระบบเปิดใช้งานแบบหลายสถานศึกษาอยู่แล้ว');
        $b = body();
        $st = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
        $st->execute([$u['id']]);
        if (!password_verify((string)($b['password'] ?? ''), (string)$st->fetchColumn())) fail('รหัสผ่านไม่ถูกต้อง');
        $systemName = trim((string)($b['system_name'] ?? '')) ?: 'ระบบแผนงานและงบประมาณสถานศึกษา';
        $instId = (int)$u['institution_id'];
        return tx(function (PDO $pdo) use ($u, $systemName, $instId) {
            set_setting('tenancy_mode', 'multi', 0);
            set_setting('system_name', mb_substr($systemName, 0, 200), 0);
            audit('system.enable_multi', 'institution', $instId, null, ['system_name' => $systemName, 'central_admin' => $u['username']]);
            $pdo->prepare('DELETE FROM role_assignments WHERE user_id = ?')->execute([$u['id']]);
            $pdo->prepare('UPDATE users SET institution_id = NULL, position_title = ? WHERE id = ?')->execute(['ผู้ดูแลระบบกลาง', $u['id']]);
            $pdo->prepare("INSERT INTO role_assignments (user_id, role, org_unit_id, fiscal_year_id) VALUES (?, 'super_admin', NULL, NULL)")->execute([$u['id']]);
            $st = $pdo->prepare("SELECT COUNT(DISTINCT u.id) FROM users u JOIN role_assignments r ON r.user_id = u.id AND r.role = 'admin' WHERE u.institution_id = ? AND u.active = 1");
            $st->execute([$instId]);
            return ['ok' => true, 'institution_has_admin' => (int)$st->fetchColumn() > 0];
        });
    },

    'GET info' => function () {
        sys_admin();
        $pdo = db();
        return [
            'app_version' => APP_VERSION,
            'php_version' => PHP_VERSION,
            'db_version' => $pdo->query('SELECT VERSION()')->fetchColumn(),
            'db_name' => $pdo->query('SELECT DATABASE()')->fetchColumn(),
            'db_size_mb' => round((float)$pdo->query('SELECT SUM(data_length + index_length) / 1048576 FROM information_schema.TABLES WHERE table_schema = DATABASE()')->fetchColumn(), 2),
            'installed' => is_file(LOCK_FILE) ? json_decode((string)file_get_contents(LOCK_FILE), true) : null,
            'extensions' => array_map(fn($e) => ['name' => $e, 'ok' => extension_loaded($e)], REQUIRED_EXTENSIONS),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
        ];
    },
];
