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
    'admin'           => 'ผู้ดูแลระบบ',
];

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

function setting(string $key, $default = null)
{
    if (!isset($GLOBALS['__settings'])) {
        try {
            $GLOBALS['__settings'] = [];
            foreach (db()->query('SELECT skey, svalue FROM settings') as $r) $GLOBALS['__settings'][$r['skey']] = $r['svalue'];
        } catch (Throwable $e) {
            unset($GLOBALS['__settings']);
            return $default;
        }
    }
    return $GLOBALS['__settings'][$key] ?? $default;
}

function set_setting(string $key, ?string $value): void
{
    db()->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)')
        ->execute([$key, $value]);
    unset($GLOBALS['__settings']);
}

// ---------------------------------------------------------------- fiscal years

function fiscal_year(int $id): array
{
    $st = db()->prepare('SELECT * FROM fiscal_years WHERE id = ?');
    $st->execute([$id]);
    $fy = $st->fetch();
    if (!$fy) fail('ไม่พบปีงบประมาณ', 404);
    $fy['settings'] = $fy['settings'] ? json_decode($fy['settings'], true) : [];
    return $fy;
}

function current_fiscal_year_id(): ?int
{
    $id = setting('current_fiscal_year_id');
    if ($id) return (int)$id;
    $row = db()->query('SELECT id FROM fiscal_years ORDER BY year_be DESC LIMIT 1')->fetch();
    return $row ? (int)$row['id'] : null;
}

/** Fiscal year id taken from the request (?fy=) or the system default. */
function request_fy(): int
{
    $fy = (int)($_GET['fy'] ?? 0);
    if (!$fy) $fy = (int)current_fiscal_year_id();
    if (!$fy) fail('ยังไม่มีปีงบประมาณในระบบ', 409);
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
    $st = db()->prepare('SELECT id, username, name, email, position_title, active FROM users WHERE id = ?');
    $st->execute([$id]);
    $u = $st->fetch();
    if (!$u || !$u['active']) return null;
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

// ---------------------------------------------------------------- audit

function audit(string $action, ?string $subjectType = null, $subjectId = null, $before = null, $after = null): void
{
    $uid = $_SESSION['uid'] ?? null;
    db()->prepare('INSERT INTO audit_logs (user_id, action, subject_type, subject_id, `before`, `after`, ip, user_agent)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([
            $uid, $action, $subjectType, $subjectId,
            $before === null ? null : json_col($before),
            $after === null ? null : json_col($after),
            client_ip(), substr($_SERVER['HTTP_USER_AGENT'] ?? 'cli', 0, 255),
        ]);
}

/** Sequential document numbers, e.g. next_number('LG', 2570) → LG70-00001. Call inside a transaction. */
function next_number(string $prefix, int $yearBe, int $pad = 5): string
{
    $name = $prefix . $yearBe;
    $pdo = db();
    $pdo->prepare('INSERT INTO counters (name, value) VALUES (?, LAST_INSERT_ID(1))
        ON DUPLICATE KEY UPDATE value = LAST_INSERT_ID(value + 1)')->execute([$name]);
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
