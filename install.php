<?php
declare(strict_types=1);

/**
 * Installer — re-runnable.
 * Steps: 1 requirements → 2 database → 3 install options (fresh / upgrade, demo data) → 4 admin account → done.
 * When the system is already installed, the installer must first be unlocked with an admin login
 * (or the database password, if one is set).
 */
require __DIR__ . '/app/bootstrap.php';
start_session();

$S = &$_SESSION['installer'];
if (!is_array($S)) $S = [];
$step = $_GET['step'] ?? 'check';
$errors = [];
$notice = null;

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">';
}

function check_csrf(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('CSRF token ไม่ถูกต้อง กรุณาโหลดหน้าใหม่');
    }
}

function go(string $step): void
{
    header('Location: install.php?step=' . $step);
    exit;
}

function db_conf(): ?array
{
    return $_SESSION['installer']['db'] ?? null;
}

/** Writable-path checks; tries to create missing folders. */
function path_checks(): array
{
    $paths = [
        ['config', 'เก็บไฟล์ตั้งค่า config.php', true],
        ['storage', 'ไฟล์ล็อกการติดตั้ง', true],
        ['storage/backups', 'ไฟล์สำรองข้อมูล', true],
        ['storage/logs', 'บันทึกข้อผิดพลาด', true],
        ['uploads', 'ไฟล์แนบ (เอกสารรับเงิน ฯลฯ)', true],
        ['migrations', 'สร้างไฟล์ migration ใหม่จากหน้าเว็บ', false],
    ];
    $out = [];
    foreach ($paths as [$rel, $purpose, $required]) {
        $abs = APP_ROOT . '/' . $rel;
        if (!is_dir($abs)) @mkdir($abs, 0775, true);
        $ok = false;
        if (is_dir($abs)) {
            $probe = $abs . '/.write_test_' . bin2hex(random_bytes(4));
            $ok = @file_put_contents($probe, 'ok') === 2;
            if ($ok) {
                $ok = @file_get_contents($probe) === 'ok';
                @unlink($probe);
            }
        }
        $out[] = ['name' => $rel . '/', 'purpose' => $purpose, 'ok' => $ok, 'required' => $required,
            'detail' => $ok ? 'อ่าน/เขียนได้' : (is_dir($abs) ? 'เขียนไม่ได้ — ให้สิทธิ์เขียนแก่ผู้ใช้ของเว็บเซิร์ฟเวอร์' : 'ไม่มีโฟลเดอร์และสร้างไม่ได้')];
    }
    return $out;
}

function requirement_checks(): array
{
    $out = [];
    $out[] = ['name' => 'PHP ' . REQUIRED_PHP . ' ขึ้นไป', 'ok' => version_compare(PHP_VERSION, REQUIRED_PHP, '>='), 'required' => true, 'detail' => 'ปัจจุบัน ' . PHP_VERSION];
    $purpose = ['pdo' => 'เชื่อมต่อฐานข้อมูล', 'pdo_mysql' => 'ไดรเวอร์ MariaDB/MySQL', 'mbstring' => 'ข้อความภาษาไทย', 'json' => 'API',
        'session' => 'การเข้าสู่ระบบ', 'openssl' => 'สุ่มรหัสความปลอดภัย', 'fileinfo' => 'ตรวจชนิดไฟล์แนบ', 'ctype' => 'ตรวจรูปแบบข้อมูล',
        'zlib' => 'อ่าน/เขียน Excel และไฟล์สำรอง'];
    foreach (REQUIRED_EXTENSIONS as $ext) {
        $out[] = ['name' => 'ส่วนขยาย ' . $ext, 'ok' => extension_loaded($ext), 'required' => true, 'detail' => $purpose[$ext] ?? ''];
    }
    $out[] = ['name' => 'ส่วนขยาย intl (แนะนำ)', 'ok' => extension_loaded('intl'), 'required' => false, 'detail' => 'จัดรูปแบบภาษาไทยเพิ่มเติม'];
    $upload = ini_get('upload_max_filesize');
    $out[] = ['name' => 'upload_max_filesize ≥ 20M', 'ok' => ini_bytes($upload) >= 20 * 1024 * 1024, 'required' => false, 'detail' => 'ปัจจุบัน ' . $upload . ' (ไฟล์แนบสูงสุด 20 MB)'];
    $post = ini_get('post_max_size');
    $out[] = ['name' => 'post_max_size ≥ 20M', 'ok' => ini_bytes($post) >= 20 * 1024 * 1024, 'required' => false, 'detail' => 'ปัจจุบัน ' . $post];
    $out[] = ['name' => 'file_uploads', 'ok' => (bool)ini_get('file_uploads'), 'required' => true, 'detail' => 'อัปโหลดไฟล์นำเข้าแผน/ไฟล์แนบ'];
    return $out;
}

function ini_bytes(string $v): int
{
    $v = trim($v);
    $n = (int)$v;
    switch (strtolower(substr($v, -1))) {
        case 'g': $n *= 1024;
        case 'm': $n *= 1024;
        case 'k': $n *= 1024;
    }
    return $n;
}

/** Connect and inspect the database server. */
function inspect_db(array $c): array
{
    $info = ['ok' => false, 'errors' => []];
    try {
        $pdo = make_pdo($c, false);
    } catch (PDOException $e) {
        $info['errors'][] = 'เชื่อมต่อเซิร์ฟเวอร์ฐานข้อมูลไม่ได้: ' . $e->getMessage();
        return $info;
    }
    $version = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
    $isMaria = stripos($version, 'mariadb') !== false;
    preg_match('/^(\d+\.\d+\.\d+)/', $version, $m);
    $num = $m[1] ?? '0.0.0';
    $info['version'] = $version;
    $info['version_ok'] = $isMaria ? version_compare($num, '10.2.7', '>=') : version_compare($num, '8.0.16', '>=');
    if (!$info['version_ok']) $info['errors'][] = 'ต้องใช้ MariaDB 10.2.7 ขึ้นไป (หรือ MySQL 8.0.16 ขึ้นไป) — พบ ' . $version;

    $exists = (bool)$pdo->query('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ' . $pdo->quote($c['name']))->fetchColumn();
    if (!$exists) {
        if (!empty($c['create'])) {
            try {
                $pdo->exec('CREATE DATABASE `' . str_replace('`', '', $c['name']) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                $exists = true;
                $info['created'] = true;
            } catch (PDOException $e) {
                $info['errors'][] = 'สร้างฐานข้อมูลไม่ได้: ' . $e->getMessage();
            }
        } else {
            $info['errors'][] = 'ไม่พบฐานข้อมูล "' . $c['name'] . '" (เลือก "สร้างฐานข้อมูลให้อัตโนมัติ" หรือสร้างเองก่อน)';
        }
    }
    if (!$exists) return $info;

    $pdo = make_pdo($c, true);
    $info['tables'] = $pdo->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'')->fetchAll(PDO::FETCH_COLUMN);
    $info['has_app'] = in_array('migrations', $info['tables'], true) || in_array('ledger_entries', $info['tables'], true);

    // Privileges: create table, check constraint, trigger, drop.
    $t = '_vecplan_probe_' . bin2hex(random_bytes(3));
    try {
        $pdo->exec("CREATE TABLE `{$t}` (id INT PRIMARY KEY, v INT, CONSTRAINT c CHECK (v > 0)) ENGINE=InnoDB");
        $pdo->exec("CREATE TRIGGER `{$t}_trg` BEFORE DELETE ON `{$t}` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'probe'");
        $checkEnforced = false;
        try { $pdo->exec("INSERT INTO `{$t}` VALUES (1, -1)"); } catch (PDOException $e) { $checkEnforced = true; }
        $info['privileges_ok'] = true;
        $info['check_enforced'] = $checkEnforced;
        if (!$checkEnforced) $info['errors'][] = 'เซิร์ฟเวอร์ไม่บังคับ CHECK constraint (ต้องใช้ MariaDB 10.2 ขึ้นไป)';
    } catch (PDOException $e) {
        $info['privileges_ok'] = false;
        $info['errors'][] = 'ผู้ใช้ฐานข้อมูลไม่มีสิทธิ์ CREATE / TRIGGER ที่จำเป็น: ' . $e->getMessage();
    } finally {
        try { $pdo->exec("DROP TABLE IF EXISTS `{$t}`"); } catch (PDOException $e) { }
    }
    $info['ok'] = !$info['errors'];
    return $info;
}

function write_config(array $db, string $orgName): void
{
    $existing = app_config();
    $config = [
        'db' => ['host' => $db['host'], 'port' => (int)$db['port'], 'name' => $db['name'], 'user' => $db['user'], 'pass' => $db['pass']],
        'app' => ['org_name' => $orgName, 'key' => $existing['app']['key'] ?? bin2hex(random_bytes(32))],
        'installed_at' => date('c'),
    ];
    $php = "<?php\n// Generated by install.php — contains database credentials. Do not commit.\nreturn " . var_export($config, true) . ";\n";
    if (@file_put_contents(CONFIG_FILE, $php, LOCK_EX) === false) throw new RuntimeException('เขียนไฟล์ config/config.php ไม่ได้');
    if (function_exists('opcache_invalidate')) @opcache_invalidate(CONFIG_FILE, true);
}

/** Tenancy mode of the database being (re)installed; 'single' when it predates multi-institution support. */
function installed_mode(?PDO $pdo = null): string
{
    try {
        $pdo = $pdo ?? db();
        $v = $pdo->query("SELECT svalue FROM settings WHERE institution_id = 0 AND skey = 'tenancy_mode'")->fetchColumn();
        return $v === 'multi' ? 'multi' : 'single';
    } catch (Throwable $e) {
        return 'single';
    }
}

// ====================================================================== unlock gate

// A session that started on a not-yet-installed system stays unlocked until it reaches "done".
if (!is_installed()) $S['unlocked'] = true;
$unlocked = !empty($S['unlocked']);
if (!$unlocked) {
    // An admin who is already logged in to the app may re-run the installer.
    try {
        if (!empty($_SESSION['uid']) && is_system_admin()) $unlocked = $S['unlocked'] = true;
    } catch (Throwable $e) { }
}
if (!$unlocked) {
    $step = 'unlock';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $cfg = app_config();
        $ok = false;
        $method = $_POST['method'] ?? 'admin';
        if ($method === 'dbpass') {
            $ok = ($cfg['db']['pass'] ?? '') !== '' && hash_equals((string)$cfg['db']['pass'], (string)($_POST['dbpass'] ?? ''));
        } else {
            try {
                // Only the system admin may re-run the installer: the central admin in multi mode.
                $st = db()->prepare("SELECT u.id, u.password_hash FROM users u JOIN role_assignments r ON r.user_id = u.id AND r.role = ?
                    WHERE u.username = ? AND u.active = 1 LIMIT 1");
                $st->execute([installed_mode() === 'multi' ? 'super_admin' : 'admin', trim((string)($_POST['username'] ?? ''))]);
                $u = $st->fetch();
                $ok = $u && password_verify((string)($_POST['password'] ?? ''), $u['password_hash']);
            } catch (Throwable $e) {
                $errors[] = 'เชื่อมต่อฐานข้อมูลเดิมไม่ได้ — ใช้รหัสผ่านฐานข้อมูลแทน หรือลบไฟล์ storage/installed.lock บนเซิร์ฟเวอร์';
            }
        }
        if ($ok) {
            session_regenerate_id(true);
            $S['unlocked'] = true;
            go('check');
        }
        if (!$errors) $errors[] = 'ข้อมูลยืนยันตัวตนไม่ถูกต้อง';
        sleep(1);
    }
}

// ====================================================================== actions

if ($step === 'check' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $allOk = !array_filter(array_merge(requirement_checks(), path_checks()), fn($c) => $c['required'] && !$c['ok']);
    if ($allOk) { $S['checked'] = true; go('db'); }
    $errors[] = 'ยังมีรายการที่จำเป็นไม่ผ่าน';
}

if ($step === 'db') {
    if (empty($S['checked'])) go('check');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $c = [
            'host' => trim((string)$_POST['host']) ?: '127.0.0.1', 'port' => (int)($_POST['port'] ?: 3306),
            'name' => trim((string)$_POST['name']), 'user' => trim((string)$_POST['user']), 'pass' => (string)$_POST['pass'],
            'create' => !empty($_POST['create']),
        ];
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $c['name'])) $errors[] = 'ชื่อฐานข้อมูลใช้ได้เฉพาะ A-Z a-z 0-9 _';
        if ($c['user'] === '') $errors[] = 'กรุณาระบุชื่อผู้ใช้ฐานข้อมูล';
        if (!$errors) {
            $info = inspect_db($c);
            $S['db'] = $c;
            $S['dbinfo'] = $info;
            if ($info['ok']) go('options');
            $errors = $info['errors'];
        }
    }
}

if ($step === 'options') {
    if (empty($S['dbinfo']['ok'])) go('db');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $c = db_conf();
        $info = $S['dbinfo'];
        $mode = ($_POST['mode'] ?? 'fresh') === 'upgrade' && $info['has_app'] ? 'upgrade' : 'fresh';
        $orgName = trim((string)($_POST['org_name'] ?? '')) ?: 'วิทยาลัยการอาชีพตัวอย่าง';
        $systemName = trim((string)($_POST['system_name'] ?? '')) ?: 'ระบบแผนงานและงบประมาณสถานศึกษา';
        $demo = $mode === 'fresh' && !empty($_POST['demo']);
        // Single or multi institution. An upgrade keeps multi mode (going back would orphan the other institutions).
        $prevMode = $info['has_app'] ? installed_mode(make_pdo($c)) : 'single';
        $tenancy = ($_POST['tenancy'] ?? 'single') === 'multi' ? 'multi' : 'single';
        if ($mode === 'upgrade' && $prevMode === 'multi') $tenancy = 'multi';
        if ($mode === 'fresh' && $info['tables'] && trim((string)($_POST['confirm_db'] ?? '')) !== $c['name']) {
            $errors[] = 'พิมพ์ชื่อฐานข้อมูล "' . $c['name'] . '" เพื่อยืนยันการลบตารางเดิมทั้งหมด';
        }
        if (!$errors) {
            try {
                write_config($c, $orgName);
                $pdo = make_pdo($c);
                db($pdo);
                $log = [];
                if ($mode === 'fresh') {
                    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
                    foreach ($pdo->query('SHOW TRIGGERS')->fetchAll() as $tr) $pdo->exec('DROP TRIGGER IF EXISTS `' . $tr['Trigger'] . '`');
                    foreach ($pdo->query("SHOW FULL TABLES")->fetchAll(PDO::FETCH_NUM) as [$tname, $ttype]) {
                        $pdo->exec(($ttype === 'VIEW' ? 'DROP VIEW IF EXISTS `' : 'DROP TABLE IF EXISTS `') . $tname . '`');
                    }
                    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
                    @unlink(LOCK_FILE);
                    $log[] = 'ล้างตารางเดิมแล้ว';
                }
                $mig = new Migrator($pdo);
                $results = $mig->migrate(null);
                foreach ($results as $r) {
                    if (!$r['ok']) throw new RuntimeException('migration ' . $r['name'] . ' ล้มเหลว: ' . $r['error']);
                    $log[] = 'รัน migration ' . $r['name'];
                }
                if (!$results) $log[] = 'โครงสร้างฐานข้อมูลเป็นปัจจุบันแล้ว';

                // The migration always creates institution 1: the only one in single mode, the first one in multi mode.
                $firstInst = (int)$pdo->query('SELECT MIN(id) FROM institutions')->fetchColumn();
                use_institution($firstInst);
                set_setting('tenancy_mode', $tenancy, 0);
                if ($tenancy === 'multi') set_setting('system_name', mb_substr($systemName, 0, 200), 0);
                if (!($mode === 'upgrade' && $prevMode === 'multi')) {
                    $pdo->prepare('UPDATE institutions SET name = ? WHERE id = ?')->execute([mb_substr($orgName, 0, 200), $firstInst]);
                }
                $log[] = $tenancy === 'multi' ? 'ใช้งานแบบหลายสถานศึกษา (' . $systemName . ')' : 'ใช้งานแบบสถานศึกษาเดียว';
                $st = $pdo->prepare('SELECT COUNT(*) FROM fiscal_years WHERE institution_id = ?');
                $st->execute([$firstInst]);
                if (!(int)$st->fetchColumn()) {
                    $fyId = tx(fn() => Seeder::base($pdo, $firstInst));
                    $log[] = 'สร้างข้อมูลตั้งต้นของ' . $orgName . ': ปีงบประมาณ 2570 หน่วยงาน แหล่งเงิน หมวดรายจ่าย ความสอดคล้อง สายอนุมัติ';
                    if ($demo) {
                        tx(fn() => Seeder::demo($pdo, $fyId, 0));
                        $log[] = 'สร้างข้อมูลตัวอย่าง: ผู้ใช้ตัวอย่าง โครงการ และรายการสมุดบัญชี';
                    }
                }
                use_institution(null);
                $S['tenancy'] = $tenancy;
                $S['first_institution'] = $firstInst;
                $S['mode'] = $mode;
                $S['demo'] = $demo;
                $S['log'] = $log;
                $S['migrated'] = true;
                go('admin');
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
    }
}

if ($step === 'admin') {
    if (empty($S['migrated'])) go('options');
    $pdo = make_pdo(db_conf());
    db($pdo);
    // Single mode: the institution admin runs the system. Multi mode: a central admin without an institution.
    $multi = ($S['tenancy'] ?? 'single') === 'multi';
    $firstInst = (int)($S['first_institution'] ?? 1);
    $adminRole = $multi ? 'super_admin' : 'admin';
    $st = $pdo->prepare('SELECT u.username, u.name FROM users u JOIN role_assignments r ON r.user_id = u.id AND r.role = ? ORDER BY u.id');
    $st->execute([$adminRole]);
    $admins = $st->fetchAll();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $username = trim((string)$_POST['username']);
        $name = trim((string)$_POST['name']);
        $email = trim((string)$_POST['email']) ?: null;
        $pass = (string)$_POST['password'];
        if (!preg_match('/^[A-Za-z0-9._-]{3,60}$/', $username)) $errors[] = 'ชื่อผู้ใช้ 3–60 ตัว ใช้ A-Z a-z 0-9 . _ -';
        if ($name === '') $errors[] = 'กรุณาระบุชื่อ-สกุล';
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'อีเมลไม่ถูกต้อง';
        if (mb_strlen($pass) < MIN_PASSWORD_LENGTH) $errors[] = 'รหัสผ่านต้องมีอย่างน้อย ' . MIN_PASSWORD_LENGTH . ' ตัวอักษร';
        if ($pass !== (string)$_POST['password2']) $errors[] = 'ยืนยันรหัสผ่านไม่ตรงกัน';
        if (!$errors) {
            try {
                tx(function (PDO $pdo) use ($username, $name, $email, $pass, $multi, $firstInst, $adminRole) {
                    $hash = password_hash($pass, PASSWORD_DEFAULT);
                    $instId = $multi ? null : $firstInst;
                    $st = $pdo->prepare('SELECT id, institution_id FROM users WHERE username = ?');
                    $st->execute([$username]);
                    $row = $st->fetch();
                    $id = $row ? (int)$row['id'] : 0;
                    if ($row && $multi && $row['institution_id'] !== null) {
                        throw new RuntimeException('ชื่อผู้ใช้ "' . $username . '" เป็นผู้ใช้ของสถานศึกษา — ใช้ชื่ออื่นสำหรับผู้ดูแลระบบกลาง');
                    }
                    if ($id) {
                        $pdo->prepare('UPDATE users SET institution_id = ?, name = ?, email = ?, password_hash = ?, active = 1, password_changed_at = NOW() WHERE id = ?')
                            ->execute([$instId, $name, $email, $hash, $id]);
                        // A former central admin brought back into a single-mode institution.
                        if (!$multi) $pdo->prepare("DELETE FROM role_assignments WHERE user_id = ? AND role = 'super_admin'")->execute([$id]);
                    } else {
                        $pdo->prepare('INSERT INTO users (institution_id, username, name, email, password_hash, position_title, password_changed_at) VALUES (?, ?, ?, ?, ?, ?, NOW())')
                            ->execute([$instId, $username, $name, $email, $hash, ROLES[$adminRole]]);
                        $id = (int)$pdo->lastInsertId();
                    }
                    // NULL unit/year never collide in the UNIQUE key, so replace instead of INSERT IGNORE.
                    $pdo->prepare('DELETE FROM role_assignments WHERE user_id = ? AND role = ?')->execute([$id, $adminRole]);
                    $pdo->prepare('INSERT INTO role_assignments (user_id, role, org_unit_id, fiscal_year_id) VALUES (?, ?, NULL, NULL)')->execute([$id, $adminRole]);
                    $_SESSION['uid'] = null;
                    use_institution($instId);
                    audit('install.admin', 'user', $id, null, ['username' => $username, 'role' => $adminRole]);
                    use_institution(null);
                });
                if (@file_put_contents(LOCK_FILE, json_encode(['installed_at' => date('c'), 'version' => APP_VERSION, 'mode' => $S['mode'] ?? 'fresh']), LOCK_EX) === false) {
                    throw new RuntimeException('เขียนไฟล์ storage/installed.lock ไม่ได้');
                }
                $S['done'] = ['username' => $username, 'demo' => !empty($S['demo']), 'log' => $S['log'] ?? [], 'multi' => $multi];
                go('done');
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
    }
}

if ($step === 'done' && empty($S['done'])) go('check');

// ====================================================================== view

$steps = ['check' => 'ตรวจสอบระบบ', 'db' => 'ฐานข้อมูล', 'options' => 'ตัวเลือกการติดตั้ง', 'admin' => 'ผู้ดูแลระบบ', 'done' => 'เสร็จสิ้น'];
$stepKeys = array_keys($steps);
$cur = array_search($step, $stepKeys, true);
?><!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>ติดตั้งระบบแผนงานและงบประมาณ</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@400;500;600;700&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box}
body{margin:0;font-family:'IBM Plex Sans','IBM Plex Sans Thai',sans-serif;background:#F2F5F9;color:#18233A;-webkit-font-smoothing:antialiased}
.wrap{max-width:820px;margin:0 auto;padding:32px 16px 64px}
.brand{display:flex;align-items:center;gap:12px;margin-bottom:22px}
.logo{width:40px;height:40px;border-radius:9px;background:#0F2C5C;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700}
.brand h1{margin:0;font-size:19px;color:#0F2245}.brand div div{font-size:13px;color:#5C6880}
.steps{display:flex;gap:6px;margin-bottom:18px;flex-wrap:wrap}
.steps span{flex:1 1 120px;font-size:12.5px;padding:8px 10px;border-radius:6px;background:#fff;border:1px solid #E1E6EE;color:#6A7689}
.steps span.on{background:#0F2C5C;border-color:#0F2C5C;color:#fff;font-weight:600}
.steps span.done{color:#13693A;border-color:#A9D9BC;background:#E9F6EE}
.card{background:#fff;border:1px solid #E1E6EE;border-radius:10px;box-shadow:0 1px 2px rgba(16,34,68,.04);padding:20px 22px;margin-bottom:16px}
.card h2{margin:0 0 4px;font-size:16px;color:#0F2245}.sub{font-size:13px;color:#5C6880;margin:0 0 14px}
table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:9px 10px;border-bottom:1px solid #EEF1F5;font-size:13.5px;vertical-align:top}
th{font-size:12px;color:#5C6880;background:#F6F8FB;font-weight:600}
.ok{color:#13693A;font-weight:600}.bad{color:#B42323;font-weight:600}.warn{color:#8A6400;font-weight:600}
label{display:flex;flex-direction:column;gap:5px;font-size:13px;color:#33415A;margin-bottom:12px}
input[type=text],input[type=password],input[type=number],input[type=email]{height:38px;padding:0 10px;border:1px solid #C9D2DE;border-radius:6px;font-size:14px;font-family:inherit;background:#fff;color:#18233A}
input:focus{outline:2px solid #9FB3D1;outline-offset:0}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:0 14px}
.check{flex-direction:row;align-items:flex-start;gap:8px}
.btn{display:inline-flex;align-items:center;height:38px;padding:0 16px;border-radius:6px;border:1px solid #0F2C5C;background:#0F2C5C;color:#fff;font-size:14px;font-weight:500;cursor:pointer;font-family:inherit;text-decoration:none}
.btn:hover{background:#0A2148}.btn.ghost{background:#fff;color:#1B2B45;border-color:#C9D2DE}.btn:disabled{opacity:.5;cursor:not-allowed}
.actions{display:flex;justify-content:space-between;gap:8px;margin-top:6px;flex-wrap:wrap}
.alert{padding:12px 14px;border-radius:8px;font-size:13.5px;margin-bottom:14px;line-height:1.6}
.alert.err{background:#FDECEC;border:1px solid #F2B8B8;color:#8E1F1F}.alert.info{background:#EEF3FB;border:1px solid #C9D8F0;color:#16469A}
.alert.warn{background:#FFF3E8;border:1px solid #F5C9A0;color:#8A3A06}.alert.good{background:#E9F6EE;border:1px solid #A9D9BC;color:#13693A}
.mode{border:1.5px solid #E1E6EE;border-radius:8px;padding:12px 14px;margin-bottom:10px;cursor:pointer;display:flex;gap:10px;align-items:flex-start;font-size:13.5px}
.mode b{display:block;color:#0F2245;margin-bottom:2px}.mode:has(input:checked){border-color:#0F2C5C;background:#EEF3FB}
code,.mono{font-family:'IBM Plex Mono',ui-monospace,monospace;font-size:12.5px}
ul.log{margin:0;padding-left:20px;font-size:13px;line-height:1.8;color:#33415A}
@media (max-width:520px){.card{padding:16px}.steps span{flex-basis:45%}}
</style>
</head>
<body>
<div class="wrap">
  <div class="brand"><div class="logo">ผง</div><div><h1>ติดตั้งระบบแผนงานและงบประมาณ</h1><div>เวอร์ชัน <?= h(APP_VERSION) ?> · PHP <?= h(PHP_VERSION) ?></div></div></div>

<?php if ($step !== 'unlock'): ?>
  <div class="steps">
    <?php foreach ($steps as $k => $label): $i = array_search($k, $stepKeys, true); ?>
      <span class="<?= $i === $cur ? 'on' : ($i < $cur ? 'done' : '') ?>"><?= $i + 1 ?>. <?= h($label) ?></span>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php foreach ($errors as $e): ?><div class="alert err"><?= h($e) ?></div><?php endforeach; ?>

<?php if ($step === 'unlock'): ?>
  <div class="card">
    <h2>ระบบติดตั้งแล้ว</h2>
    <p class="sub">การติดตั้งซ้ำอาจลบข้อมูลทั้งหมด จึงต้องยืนยันตัวตนก่อน — เข้าสู่ระบบด้วยบัญชีผู้ดูแลระบบ หรือใช้รหัสผ่านฐานข้อมูลในไฟล์ config</p>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="method" value="admin">
      <div class="grid">
        <label>ชื่อผู้ใช้ผู้ดูแลระบบ<input type="text" name="username" autocomplete="username" required></label>
        <label>รหัสผ่าน<input type="password" name="password" autocomplete="current-password" required></label>
      </div>
      <button class="btn">ยืนยันและเริ่มติดตั้งซ้ำ</button>
    </form>
  </div>
  <?php if ((app_config()['db']['pass'] ?? '') !== ''): ?>
  <div class="card">
    <h2>หรือใช้รหัสผ่านฐานข้อมูล</h2>
    <p class="sub">สำหรับกรณีเข้าระบบไม่ได้หรือฐานข้อมูลเสีย</p>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="method" value="dbpass">
      <label>รหัสผ่านฐานข้อมูล<input type="password" name="dbpass" required></label>
      <button class="btn ghost">ยืนยัน</button>
    </form>
  </div>
  <?php endif; ?>
  <p class="sub"><a href="./">← กลับไปหน้าระบบ</a></p>

<?php elseif ($step === 'check'):
    $req = requirement_checks();
    $paths = path_checks();
    $blocked = (bool)array_filter(array_merge($req, $paths), fn($c) => $c['required'] && !$c['ok']);
?>
  <?php if (is_installed()): ?><div class="alert warn">ระบบติดตั้งอยู่แล้ว — คุณกำลังติดตั้งซ้ำ ขั้นตอนถัดไปเลือกได้ว่าจะ <b>คงข้อมูลเดิม (อัปเกรด)</b> หรือ <b>ติดตั้งใหม่ทั้งหมด</b></div><?php endif; ?>
  <div class="card">
    <h2>แพ็กเกจและส่วนขยายที่ต้องการ</h2>
    <p class="sub">ตรวจสอบเวอร์ชัน PHP และส่วนขยายที่ระบบใช้</p>
    <table><thead><tr><th>รายการ</th><th>รายละเอียด</th><th style="width:110px">ผล</th></tr></thead><tbody>
    <?php foreach ($req as $c): ?>
      <tr><td><?= h($c['name']) ?></td><td><?= h($c['detail']) ?></td>
      <td class="<?= $c['ok'] ? 'ok' : ($c['required'] ? 'bad' : 'warn') ?>"><?= $c['ok'] ? '✓ ผ่าน' : ($c['required'] ? '✗ ไม่ผ่าน' : '! แนะนำ') ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
  </div>
  <div class="card">
    <h2>สิทธิ์การอ่าน/เขียนไฟล์</h2>
    <p class="sub">ทดสอบสร้าง อ่าน และลบไฟล์จริงในแต่ละโฟลเดอร์</p>
    <table><thead><tr><th>โฟลเดอร์</th><th>ใช้สำหรับ</th><th>สถานะ</th><th style="width:110px">ผล</th></tr></thead><tbody>
    <?php foreach ($paths as $c): ?>
      <tr><td class="mono"><?= h($c['name']) ?></td><td><?= h($c['purpose']) ?></td><td><?= h($c['detail']) ?></td>
      <td class="<?= $c['ok'] ? 'ok' : ($c['required'] ? 'bad' : 'warn') ?>"><?= $c['ok'] ? '✓ ผ่าน' : ($c['required'] ? '✗ ไม่ผ่าน' : '! ไม่บังคับ') ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
  </div>
  <form method="post" class="actions">
    <?= csrf_field() ?>
    <a class="btn ghost" href="install.php?step=check">ตรวจสอบอีกครั้ง</a>
    <button class="btn" <?= $blocked ? 'disabled' : '' ?>>ถัดไป: ตั้งค่าฐานข้อมูล</button>
  </form>

<?php elseif ($step === 'db'):
    $c = db_conf() ?? (app_config()['db'] ?? ['host' => '127.0.0.1', 'port' => 3306, 'name' => 'vec_plan', 'user' => 'root', 'pass' => '']);
?>
  <div class="card">
    <h2>เชื่อมต่อฐานข้อมูล MariaDB</h2>
    <p class="sub">ต้องการ MariaDB 10.2.7 ขึ้นไป (หรือ MySQL 8.0.16+) ผู้ใช้ต้องมีสิทธิ์ CREATE, ALTER, DROP, INDEX, REFERENCES และ TRIGGER</p>
    <form method="post">
      <?= csrf_field() ?>
      <div class="grid">
        <label>โฮสต์<input type="text" name="host" value="<?= h($c['host']) ?>" required></label>
        <label>พอร์ต<input type="number" name="port" value="<?= h((string)$c['port']) ?>" required></label>
        <label>ชื่อฐานข้อมูล<input type="text" name="name" value="<?= h($c['name']) ?>" required></label>
        <label>ชื่อผู้ใช้<input type="text" name="user" value="<?= h($c['user']) ?>" autocomplete="off" required></label>
        <label>รหัสผ่าน<input type="password" name="pass" value="<?= h($c['pass'] ?? '') ?>" autocomplete="new-password"></label>
      </div>
      <label class="check"><input type="checkbox" name="create" value="1" <?= ($c['create'] ?? true) ? 'checked' : '' ?>> สร้างฐานข้อมูลให้อัตโนมัติถ้ายังไม่มี (utf8mb4)</label>
      <div class="actions"><a class="btn ghost" href="install.php?step=check">ย้อนกลับ</a><button class="btn">ทดสอบการเชื่อมต่อและถัดไป</button></div>
    </form>
  </div>

<?php elseif ($step === 'options'):
    $c = db_conf();
    $info = $S['dbinfo'];
    $org = app_config()['app']['org_name'] ?? 'วิทยาลัยการอาชีพตัวอย่าง';
    $migrator = null;
    $pending = [];
    $prevMode = 'single';
    $sysName = 'ระบบแผนงานและงบประมาณสถานศึกษา';
    $instCount = 0;
    if ($info['has_app']) {
        try {
            $ipdo = make_pdo($c);
            $migrator = new Migrator($ipdo);
            $pending = $migrator->pending();
            $prevMode = installed_mode($ipdo);
            $org = (string)($ipdo->query('SELECT name FROM institutions ORDER BY id LIMIT 1')->fetchColumn() ?: $org);
            $instCount = (int)$ipdo->query('SELECT COUNT(*) FROM institutions')->fetchColumn();
            $sysName = (string)($ipdo->query("SELECT svalue FROM settings WHERE institution_id = 0 AND skey = 'system_name'")->fetchColumn() ?: $sysName);
        } catch (Throwable $e) { }
    }
?>
  <div class="alert good">เชื่อมต่อสำเร็จ · <?= h($info['version']) ?> · ฐานข้อมูล <b><?= h($c['name']) ?></b><?= !empty($info['created']) ? ' (สร้างใหม่)' : '' ?> · มีตาราง <?= count($info['tables']) ?> ตาราง</div>
  <form method="post" class="card">
    <?= csrf_field() ?>
    <h2>ตัวเลือกการติดตั้ง</h2>
    <p class="sub">โครงสร้างฐานข้อมูลสร้างด้วย migrations ทั้งหมด <?= count((new Migrator(make_pdo($c)))->files()) ?> ไฟล์</p>
    <div id="tenancyBox">
      <label class="mode"><input type="radio" name="tenancy" value="single" <?= $prevMode === 'single' ? 'checked' : '' ?> onchange="toggleMode()"><span><b>สถานศึกษาเดียว</b>
        ใช้งานในสถานศึกษาเดียว ผู้ดูแลระบบของสถานศึกษาดูแลทั้งระบบ (จัดการผู้ใช้ migrations และสำรองข้อมูล) — เปิดใช้งานแบบหลายสถานศึกษาภายหลังได้จากเมนูผู้ดูแลระบบ</span></label>
      <label class="mode"><input type="radio" name="tenancy" value="multi" <?= $prevMode === 'multi' ? 'checked' : '' ?> onchange="toggleMode()"><span><b>หลายสถานศึกษา</b>
        สถานศึกษาหลายแห่งใช้ระบบเดียวกัน ข้อมูลของแต่ละแห่งแยกจากกัน แต่ละแห่งมีผู้ดูแลระบบสถานศึกษาของตัวเอง และมีผู้ดูแลระบบกลางสร้างสถานศึกษา/ผู้ดูแล รัน migrations และสำรองข้อมูล</span></label>
    </div>
    <div id="multiLocked" class="alert info" style="display:none">ระบบนี้ใช้งานแบบหลายสถานศึกษา (<?= $instCount ?> แห่ง) — การอัปเกรดคงโหมดหลายสถานศึกษาไว้</div>
    <label id="sysNameBox">ชื่อระบบ (แสดงที่หน้าเข้าสู่ระบบ)<input type="text" name="system_name" value="<?= h($sysName) ?>"></label>
    <label id="orgNameBox"><span id="orgNameLabel">ชื่อสถานศึกษา</span><input type="text" name="org_name" value="<?= h($org) ?>"></label>
    <?php if ($info['has_app']): ?>
      <label class="mode"><input type="radio" name="mode" value="upgrade" checked onchange="toggleMode()"><span><b>คงข้อมูลเดิม (อัปเกรด/ซ่อมแซม)</b>
        รันเฉพาะ migration ที่ยังค้าง (<?= count($pending) ?> รายการ) ข้อมูลเดิมทั้งหมดอยู่ครบ แล้วตั้งค่าบัญชีผู้ดูแลระบบ</span></label>
    <?php endif; ?>
    <label class="mode"><input type="radio" name="mode" value="fresh" <?= $info['has_app'] ? '' : 'checked' ?> onchange="toggleMode()"><span><b>ติดตั้งใหม่ทั้งหมด</b>
      <?= $info['tables'] ? 'ลบตารางเดิมทั้งหมด ' . count($info['tables']) . ' ตารางในฐานข้อมูลนี้ แล้วสร้างใหม่' : 'สร้างตารางทั้งหมดในฐานข้อมูลว่าง' ?> พร้อมข้อมูลตั้งต้น (ปีงบ 2570 หน่วยงาน แหล่งเงิน หมวดรายจ่าย ความสอดคล้อง สายอนุมัติ)</span></label>
    <div id="freshOpts">
      <?php if ($info['tables']): ?>
        <div class="alert err">ตารางต่อไปนี้จะถูกลบ: <span class="mono"><?= h(implode(', ', array_slice($info['tables'], 0, 40))) ?><?= count($info['tables']) > 40 ? ' …' : '' ?></span></div>
        <label>พิมพ์ชื่อฐานข้อมูล <b><?= h($c['name']) ?></b> เพื่อยืนยัน<input type="text" name="confirm_db" autocomplete="off"></label>
      <?php endif; ?>
      <label class="check"><input type="checkbox" name="demo" value="1"> ใส่ข้อมูลตัวอย่าง (ผู้ใช้ตัวอย่าง โครงการ ประมาณการ รับเงิน และสมุดบัญชี) — สำหรับทดลองใช้เท่านั้น</label>
    </div>
    <div class="actions"><a class="btn ghost" href="install.php?step=db">ย้อนกลับ</a><button class="btn">ติดตั้ง</button></div>
  </form>
  <script>
  function toggleMode(){
    var f=document.querySelector('input[name=mode]:checked'),fresh=f&&f.value==='fresh';
    var locked=!fresh&&<?= json_encode($prevMode === 'multi') ?>;
    var t=document.querySelector('input[name=tenancy]:checked'),multi=locked||(t&&t.value==='multi');
    document.getElementById('freshOpts').style.display=fresh?'':'none';
    document.getElementById('tenancyBox').style.display=locked?'none':'';
    document.getElementById('multiLocked').style.display=locked?'':'none';
    document.getElementById('sysNameBox').style.display=multi?'':'none';
    document.getElementById('orgNameBox').style.display=locked?'none':'';
    document.getElementById('orgNameLabel').textContent=multi?'ชื่อสถานศึกษาแรก (สร้างพร้อมข้อมูลตั้งต้น)':'ชื่อสถานศึกษา';
  }
  toggleMode();
  </script>

<?php elseif ($step === 'admin'): ?>
  <?php if (!empty($S['log'])): ?><div class="alert good"><ul class="log"><?php foreach ($S['log'] as $l): ?><li><?= h($l) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <form method="post" class="card">
    <?= csrf_field() ?>
    <h2>ตั้งค่าบัญชีผู้ดูแลระบบ<?= $multi ? 'กลาง' : '' ?></h2>
    <p class="sub"><?= $multi
        ? 'ผู้ดูแลระบบกลางสร้างสถานศึกษาและผู้ดูแลระบบสถานศึกษา รัน migrations และสำรองข้อมูล — ไม่สังกัดสถานศึกษาใดและไม่เห็นข้อมูลงบประมาณของสถานศึกษา'
        : 'ผู้ดูแลระบบจัดการผู้ใช้ บทบาท migrations และสำรองข้อมูล (ไม่มีสิทธิ์อนุมัติหรือบันทึกเงิน)' ?>
      <?php if ($admins): ?> · ผู้ดูแลระบบเดิม: <?= h(implode(', ', array_map(fn($a) => $a['username'], $admins))) ?> — ใช้ชื่อผู้ใช้เดิมเพื่อรีเซ็ตรหัสผ่าน<?php endif; ?></p>
    <div class="grid">
      <label>ชื่อผู้ใช้<input type="text" name="username" value="<?= h($_POST['username'] ?? ($admins[0]['username'] ?? 'admin')) ?>" autocomplete="username" required></label>
      <label>ชื่อ-สกุล<input type="text" name="name" value="<?= h($_POST['name'] ?? ($admins[0]['name'] ?? ($multi ? 'ผู้ดูแลระบบกลาง' : 'ผู้ดูแลระบบ'))) ?>" required></label>
      <label>อีเมล (ไม่บังคับ)<input type="email" name="email" value="<?= h($_POST['email'] ?? '') ?>"></label>
    </div>
    <div class="grid">
      <label>รหัสผ่าน (อย่างน้อย <?= MIN_PASSWORD_LENGTH ?> ตัว)<input type="password" name="password" minlength="<?= MIN_PASSWORD_LENGTH ?>" autocomplete="new-password" required></label>
      <label>ยืนยันรหัสผ่าน<input type="password" name="password2" minlength="<?= MIN_PASSWORD_LENGTH ?>" autocomplete="new-password" required></label>
    </div>
    <div class="actions"><span></span><button class="btn">บันทึกและเสร็จสิ้นการติดตั้ง</button></div>
  </form>

<?php elseif ($step === 'done'): $d = $S['done']; ?>
  <div class="card">
    <h2>ติดตั้งเสร็จสมบูรณ์</h2>
    <?php if (!empty($d['multi'])): ?>
    <p class="sub">เข้าสู่ระบบด้วยชื่อผู้ใช้ <b><?= h($d['username']) ?></b> (ผู้ดูแลระบบกลาง) แล้วไปที่เมนู <b>สถานศึกษา</b> เพื่อกำหนดผู้ดูแลระบบของสถานศึกษาแรก และเพิ่มสถานศึกษาอื่น ๆ</p>
    <?php else: ?>
    <p class="sub">เข้าสู่ระบบด้วยชื่อผู้ใช้ <b><?= h($d['username']) ?></b> แล้วไปที่เมนู <b>ผู้ใช้และบทบาท</b> เพื่อเพิ่มเจ้าหน้าที่งานแผนฯ และงานการเงิน</p>
    <?php endif; ?>
    <?php if ($d['demo']): ?>
      <div class="alert info">บัญชีตัวอย่างใช้รหัสผ่าน <code><?= h(Seeder::DEMO_PASSWORD) ?></code>:
        <span class="mono">planner, finance, procurement, director, plandeputy, acddeputy, autohead, teacher, board</span> — ปิดหรือลบบัญชีเหล่านี้ก่อนใช้งานจริง</div>
    <?php endif; ?>
    <div class="alert warn">เพื่อความปลอดภัย ควรจำกัดการเข้าถึง <code>install.php</code> หลังติดตั้ง (ติดตั้งซ้ำได้โดยยืนยันตัวตนผู้ดูแลระบบ)</div>
    <a class="btn" href="./">เข้าสู่ระบบ</a>
  </div>
<?php $_SESSION['installer'] = []; endif; ?>
</div>
</body>
</html>
