<?php
declare(strict_types=1);

// Admin-only system tools: database migrations, backups, audit log, environment info.

function sys_admin(): array
{
    return require_role('admin');
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
        sys_admin();
        $where = ['1 = 1'];
        $params = [];
        if (!empty($_GET['action'])) { $where[] = 'a.action LIKE ?'; $params[] = $_GET['action'] . '%'; }
        if (!empty($_GET['user_id'])) { $where[] = 'a.user_id = ?'; $params[] = (int)$_GET['user_id']; }
        if (!empty($_GET['subject_type'])) { $where[] = 'a.subject_type = ?'; $params[] = (string)$_GET['subject_type']; }
        $page = max(1, (int)($_GET['page'] ?? 1));
        $st = db()->prepare('SELECT COUNT(*) FROM audit_logs a WHERE ' . implode(' AND ', $where));
        $st->execute($params);
        $total = (int)$st->fetchColumn();
        $st = db()->prepare('SELECT a.*, u.name AS user_name, u.username FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
            WHERE ' . implode(' AND ', $where) . ' ORDER BY a.id DESC LIMIT 100 OFFSET ' . (($page - 1) * 100));
        $st->execute($params);
        return ['rows' => $st->fetchAll(), 'total' => $total, 'page' => $page];
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
