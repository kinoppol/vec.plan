<?php
declare(strict_types=1);

const ROLES = [
    'proposer'        => 'ผู้เสนอ/ผู้รับผิดชอบโครงการ',
    'unit_head'       => 'หัวหน้างาน/หัวหน้าแผนกวิชา',
    'division_deputy' => 'รองผู้อำนวยการฝ่าย',
    'planner'         => 'งานวางแผนและงบประมาณ',
    'planning_deputy' => 'รองผู้อำนวยการฝ่ายแผนงานฯ',
    'director'        => 'ผู้อำนวยการ',
    'finance'         => 'งานการเงิน/งานบัญชี',
    'procurement'     => 'งานพัสดุ',
    'board_viewer'    => 'กรรมการวิทยาลัย',
    'admin'           => 'ผู้ดูแลระบบสถานศึกษา',
    'super_admin'     => 'ผู้ดูแลระบบกลาง',
];

// Roles an institution admin may assign (super_admin exists only in multi-institution mode).
const INSTITUTION_ROLES = ['proposer', 'unit_head', 'division_deputy', 'planner', 'planning_deputy', 'director', 'finance', 'procurement', 'board_viewer', 'admin'];

// Roles that only make sense together with an org unit (data scope = that unit subtree).
const UNIT_SCOPED_ROLES = ['proposer', 'unit_head', 'division_deputy'];

const TH_MONTHS = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];

/** Thrown by API code; the router turns it into a JSON error response. */
class ApiError extends RuntimeException
{
    public array $extra;
    public function __construct(string $message, int $code = 400, array $extra = [])
    {
        parent::__construct($message, $code);
        $this->extra = $extra;
    }
}

function fail(string $message, int $code = 400, array $extra = []): void
{
    throw new ApiError($message, $code, $extra);
}

function json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Request body: JSON or form fields (or the body of an in-process api_call). */
function body(): array
{
    if (isset($GLOBALS['__api_body'])) return $GLOBALS['__api_body'];
    static $data = null;
    if ($data !== null) return $data;
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') !== false) {
        $data = json_decode(file_get_contents('php://input') ?: '[]', true);
        if (!is_array($data)) fail('ข้อมูลที่ส่งมาไม่ใช่ JSON ที่ถูกต้อง');
    } else {
        $data = $_POST;
    }
    return $data;
}

/** Handlers of app/api/<resource>.php: ['METHOD action' => callable]. Each file is loaded once. */
function api_handlers(string $resource): array
{
    static $cache = [];
    if (!isset($cache[$resource])) {
        $file = APP_ROOT . '/app/api/' . $resource . '.php';
        if (!preg_match('/^[a-z_]+$/', $resource) || !is_file($file)) fail('ไม่พบ API', 404);
        $cache[$resource] = require $file;
    }
    return $cache[$resource];
}

/**
 * Run another API handler in-process as the current user (used by the AI assistant), so the
 * handler's own permission checks apply. The fiscal year of the outer request carries over.
 */
function api_call(string $method, string $route, array $query = [], ?array $body = null)
{
    [$resource, $action] = array_pad(explode('/', $route, 2), 2, 'index');
    $handler = api_handlers($resource)[$method . ' ' . $action] ?? null;
    if (!$handler) fail('ไม่พบ API', 404);
    $saved = [$_GET, $GLOBALS['__api_body'] ?? null];
    $_GET = array_filter($query, fn($v) => $v !== null && $v !== '') + (isset($_GET['fy']) ? ['fy' => $_GET['fy']] : []);
    $GLOBALS['__api_body'] = $body ?? [];
    try {
        return $handler();
    } finally {
        [$_GET, $GLOBALS['__api_body']] = $saved;
    }
}

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function client_ip(): string
{
    return substr($_SERVER['REMOTE_ADDR'] ?? 'cli', 0, 45);
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function today(): string
{
    return date('Y-m-d');
}

function json_col($value): ?string
{
    return $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE);
}

// ---------------------------------------------------------------- money
// Money is handled as integer satang (1/100 baht) in PHP to avoid float error,
// and as DECIMAL(14,2) in the database.

/** Parse 1234.5 / "1,234.505" / "-12" into satang, rounding half-up (BR-01). */
function to_cents($value): int
{
    if (is_int($value)) return $value * 100;
    if (is_float($value)) $value = sprintf('%.6F', $value);
    $s = str_replace([',', ' ', '฿'], '', trim((string)$value));
    if ($s === '' ) return 0;
    if (!preg_match('/^(-)?(\d*)(?:\.(\d*))?$/', $s, $m) || ($m[2] === '' && ($m[3] ?? '') === '')) {
        throw new ApiError('จำนวนเงินไม่ถูกต้อง: ' . $value);
    }
    $neg = $m[1] === '-';
    $int = $m[2] === '' ? 0 : (int)$m[2];
    $frac = str_pad($m[3] ?? '', 3, '0');
    $cents = $int * 100 + (int)substr($frac, 0, 2);
    if ((int)$frac[2] >= 5) $cents += 1;
    return $neg ? -$cents : $cents;
}

function cents_str(int $cents): string
{
    $neg = $cents < 0;
    $c = abs($cents);
    return ($neg ? '-' : '') . intdiv($c, 100) . '.' . str_pad((string)($c % 100), 2, '0', STR_PAD_LEFT);
}

function cents_num(int $cents): float
{
    return $cents / 100;
}

function fmt_money(int $cents): string
{
    $neg = $cents < 0;
    $c = abs($cents);
    return ($neg ? '−' : '') . number_format(intdiv($c, 100)) . '.' . str_pad((string)($c % 100), 2, '0', STR_PAD_LEFT);
}

// ---------------------------------------------------------------- dates

function th_date(?string $iso, bool $withTime = false): string
{
    if (!$iso) return '';
    $t = strtotime($iso);
    if ($t === false) return $iso;
    $s = (int)date('j', $t) . ' ' . TH_MONTHS[(int)date('n', $t) - 1] . ' ' . ((int)date('Y', $t) + 543);
    return $withTime ? $s . ' ' . date('H:i', $t) : $s;
}

/** Fiscal month index: October = 1 … September = 12. */
function fiscal_month(string $iso): int
{
    $m = (int)date('n', strtotime($iso));
    return $m >= 10 ? $m - 9 : $m + 3;
}

function valid_date(?string $s): bool
{
    if (!$s || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return false;
    [$y, $m, $d] = array_map('intval', explode('-', $s));
    return checkdate($m, $d, $y);
}

// ---------------------------------------------------------------- settings
// settings rows are keyed by (institution_id, skey); institution_id 0 holds system-wide values.

function setting(string $key, $default = null, ?int $institutionId = null)
{
    $inst = $institutionId ?? (current_institution_id() ?? 0);
    if (!isset($GLOBALS['__settings'][$inst])) {
        try {
            $st = db()->prepare('SELECT skey, svalue FROM settings WHERE institution_id IN (0, ?) ORDER BY institution_id');
            $st->execute([$inst]);
            $vals = [];
            foreach ($st as $r) $vals[$r['skey']] = $r['svalue'];
            $GLOBALS['__settings'][$inst] = $vals;
        } catch (Throwable $e) {
            return $default;
        }
    }
    return $GLOBALS['__settings'][$inst][$key] ?? $default;
}

function set_setting(string $key, ?string $value, ?int $institutionId = null): void
{
    $inst = $institutionId ?? (current_institution_id() ?? 0);
    db()->prepare('INSERT INTO settings (institution_id, skey, svalue) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)')
        ->execute([$inst, $key, $value]);
    unset($GLOBALS['__settings']);
}

function system_setting(string $key, $default = null)
{
    return setting($key, $default, 0);
}

// ---------------------------------------------------------------- institutions

/** 'single' (one institution, its admin runs the system) or 'multi' (central admin + many institutions). */
function tenancy_mode(): string
{
    return system_setting('tenancy_mode', 'single') === 'multi' ? 'multi' : 'single';
}

function is_multi(): bool
{
    return tenancy_mode() === 'multi';
}

/**
 * Explicit institution context for code that runs without a logged-in institution user
 * (installer, seeder, CLI tests). Pass null to clear it.
 */
function use_institution(?int $id): void
{
    $GLOBALS['__institution_id'] = $id;
    unset($GLOBALS['__settings']);
}

/** Institution whose data the request may touch: the explicit context, else the logged-in user's institution. */
function current_institution_id(): ?int
{
    if (isset($GLOBALS['__institution_id'])) return (int)$GLOBALS['__institution_id'];
    $u = current_user();
    return $u ? $u['institution_id'] : null;
}

function require_institution(): int
{
    $id = current_institution_id();
    if (!$id) fail('บัญชีนี้ไม่ได้สังกัดสถานศึกษา', 403);
    return $id;
}

function institution(int $id): array
{
    $st = db()->prepare('SELECT * FROM institutions WHERE id = ?');
    $st->execute([$id]);
    $i = $st->fetch();
    if (!$i) fail('ไม่พบสถานศึกษา', 404);
    return $i;
}

/** Name shown before login: the institution in single mode, the system name in multi mode. */
function public_org_name(): string
{
    if (is_multi()) return (string)system_setting('system_name', 'ระบบแผนงานและงบประมาณสถานศึกษา');
    $name = db()->query('SELECT name FROM institutions ORDER BY id LIMIT 1')->fetchColumn();
    return $name !== false ? (string)$name : 'วิทยาลัย';
}

// ---------------------------------------------------------------- fiscal years

function fiscal_year(int $id): array
{
    $st = db()->prepare('SELECT * FROM fiscal_years WHERE id = ?');
    $st->execute([$id]);
    $fy = $st->fetch();
    // A fiscal year of another institution does not exist as far as this request is concerned.
    $inst = current_institution_id();
    if (!$fy || ($inst !== null && (int)$fy['institution_id'] !== $inst)) fail('ไม่พบปีงบประมาณ', 404);
    $fy['settings'] = $fy['settings'] ? json_decode($fy['settings'], true) : [];
    return $fy;
}

function current_fiscal_year_id(): ?int
{
    $inst = current_institution_id();
    if (!$inst) return null;
    $id = setting('current_fiscal_year_id');
    if ($id) return (int)$id;
    $st = db()->prepare('SELECT id FROM fiscal_years WHERE institution_id = ? ORDER BY year_be DESC LIMIT 1');
    $st->execute([$inst]);
    $row = $st->fetch();
    return $row ? (int)$row['id'] : null;
}

/** Fiscal year id taken from the request (?fy=) or the institution default; always one of the user's institution. */
function request_fy(): int
{
    require_institution();
    $fy = (int)($_GET['fy'] ?? 0);
    if (!$fy) $fy = (int)current_fiscal_year_id();
    if (!$fy) fail('ยังไม่มีปีงบประมาณในระบบ', 409);
    fiscal_year($fy);
    return $fy;
}

// ---------------------------------------------------------------- auth

function current_user(): ?array
{
    // Cached per user id: the seeder switches identities within one request.
    static $cache = [];
    $id = (int)($_SESSION['uid'] ?? 0);
    if (!$id) return null;
    if (array_key_exists($id, $cache)) return $cache[$id];
    $cache[$id] = null;
    $st = db()->prepare('SELECT u.id, u.institution_id, u.username, u.name, u.email, u.position_title, u.active, i.active AS institution_active
        FROM users u LEFT JOIN institutions i ON i.id = u.institution_id WHERE u.id = ?');
    $st->execute([$id]);
    $u = $st->fetch();
    // Users of a deactivated institution are logged out.
    if (!$u || !$u['active'] || ($u['institution_id'] && !$u['institution_active'])) return null;
    $u['institution_id'] = $u['institution_id'] ? (int)$u['institution_id'] : null;
    $st = db()->prepare('SELECT ra.role, ra.org_unit_id, ra.fiscal_year_id, ou.name AS unit_name
        FROM role_assignments ra LEFT JOIN org_units ou ON ou.id = ra.org_unit_id WHERE ra.user_id = ?');
    $st->execute([$id]);
    $u['roles'] = $st->fetchAll();
    return $cache[$id] = $u;
}

/** Role assignments valid for a fiscal year (rows with NULL year apply to every year). */
function roles_for(array $user, ?int $fyId = null): array
{
    $fyId = $fyId ?? current_fiscal_year_id();
    return array_values(array_filter($user['roles'], fn($r) => $r['fiscal_year_id'] === null || (int)$r['fiscal_year_id'] === (int)$fyId));
}

function has_role($roles, ?int $fyId = null, ?array $user = null): bool
{
    $user = $user ?? current_user();
    if (!$user) return false;
    $roles = (array)$roles;
    foreach (roles_for($user, $fyId) as $r) {
        if (in_array($r['role'], $roles, true)) return true;
    }
    return false;
}

function is_super_admin(?array $user = null): bool
{
    $user = $user ?? current_user();
    if (!$user || $user['institution_id'] !== null) return false;
    foreach ($user['roles'] as $r) if ($r['role'] === 'super_admin') return true;
    return false;
}

/** Who runs migrations / backups / system info: the central admin in multi mode, the institution admin in single mode. */
function is_system_admin(?array $user = null): bool
{
    return is_multi() ? is_super_admin($user) : has_role('admin', null, $user);
}

function require_system_admin(): array
{
    $u = require_login();
    if (!is_system_admin($u)) fail('เฉพาะผู้ดูแลระบบ' . (is_multi() ? 'กลาง' : '') . 'เท่านั้น', 403);
    return $u;
}

function require_login(): array
{
    $u = current_user();
    if (!$u) fail('กรุณาเข้าสู่ระบบ', 401);
    return $u;
}

function require_role($roles, ?int $fyId = null): array
{
    $u = require_login();
    if (!has_role($roles, $fyId, $u)) fail('ไม่มีสิทธิ์ดำเนินการนี้', 403);
    return $u;
}

/** Cache-busting token for a user's avatar, or null when they have none (initials are shown). */
function avatar_version(array $u): ?string
{
    return !empty($u['avatar_path']) ? substr(md5((string)$u['avatar_path']), 0, 10) : null;
}

/** avatar_version() of a user id (null before the RMS migration ran or without a picture). */
function user_avatar(int $id): ?string
{
    try {
        $st = db()->prepare('SELECT avatar_path FROM users WHERE id = ?');
        $st->execute([$id]);
        return avatar_version(['avatar_path' => $st->fetchColumn()]);
    } catch (PDOException $e) {
        return null;
    }
}

// ---------------------------------------------------------------- audit

function audit(string $action, ?string $subjectType = null, $subjectId = null, $before = null, $after = null): void
{
    $uid = $_SESSION['uid'] ?? null;
    db()->prepare('INSERT INTO audit_logs (institution_id, user_id, action, subject_type, subject_id, `before`, `after`, ip, user_agent)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([
            current_institution_id(), $uid, $action, $subjectType, $subjectId,
            $before === null ? null : json_col($before),
            $after === null ? null : json_col($after),
            client_ip(), substr($_SERVER['HTTP_USER_AGENT'] ?? 'cli', 0, 255),
        ]);
}

/** Sequential document numbers per institution, e.g. next_number('LG', 2570) → LG70-00001. Call inside a transaction. */
function next_number(string $prefix, int $yearBe, int $pad = 5): string
{
    $name = $prefix . $yearBe;
    $pdo = db();
    $pdo->prepare('INSERT INTO counters (institution_id, name, value) VALUES (?, ?, LAST_INSERT_ID(1))
        ON DUPLICATE KEY UPDATE value = LAST_INSERT_ID(value + 1)')->execute([current_institution_id() ?? 0, $name]);
    $n = (int)$pdo->lastInsertId();
    return sprintf('%s%02d-%0' . $pad . 'd', $prefix, $yearBe % 100, $n);
}

/** Run $fn inside a transaction (re-entrant: joins an outer transaction). */
function tx(callable $fn)
{
    $pdo = db();
    if ($pdo->inTransaction()) return $fn($pdo);
    $pdo->beginTransaction();
    try {
        $result = $fn($pdo);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function rrmdir_contents(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
}
