<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('APP_VERSION', '0.1.0');
define('CONFIG_FILE', APP_ROOT . '/config/config.php');
define('LOCK_FILE', APP_ROOT . '/storage/installed.lock');
define('MIGRATIONS_DIR', APP_ROOT . '/migrations');
define('UPLOAD_DIR', APP_ROOT . '/uploads');
define('BACKUP_DIR', APP_ROOT . '/storage/backups');

// Minimum runtime requirements; install.php shows these to the user.
const REQUIRED_PHP = '8.0.0';
const REQUIRED_EXTENSIONS = ['pdo', 'pdo_mysql', 'mbstring', 'json', 'session', 'openssl', 'fileinfo', 'ctype', 'zlib'];
const SESSION_TIMEOUT = 8 * 3600;
const MIN_PASSWORD_LENGTH = 8;

date_default_timezone_set('Asia/Bangkok');
mb_internal_encoding('UTF-8');

spl_autoload_register(function (string $class): void {
    $file = APP_ROOT . '/app/' . $class . '.php';
    if (is_file($file)) require $file;
});

require __DIR__ . '/helpers.php';

function app_config(): ?array
{
    static $config = null;
    if ($config === null) {
        $config = is_file(CONFIG_FILE) ? (require CONFIG_FILE) : false;
    }
    return is_array($config) ? $config : null;
}

function is_installed(): bool
{
    return is_file(LOCK_FILE) && app_config() !== null;
}

function make_pdo(array $c, bool $withDatabase = true): PDO
{
    $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $c['host'], (int)($c['port'] ?? 3306));
    if ($withDatabase) $dsn .= ';dbname=' . $c['name'];
    $pdo = new PDO($dsn, $c['user'], $c['pass'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ]);
    $pdo->exec("SET time_zone = '+07:00', sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION,ERROR_FOR_DIVISION_BY_ZERO'");
    // READ COMMITTED: after waiting for an account row lock, balance checks must see the other
    // transaction's committed entries (REPEATABLE READ would keep a stale snapshot; BR-22).
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    return $pdo;
}

/** Shared connection; the installer and tests inject their own via db($pdo). */
function db(?PDO $use = null): PDO
{
    static $pdo = null;
    if ($use !== null) $pdo = $use;
    if ($pdo === null) {
        $config = app_config();
        if (!$config) throw new RuntimeException('ระบบยังไม่ได้ติดตั้ง');
        $pdo = make_pdo($config['db']);
    }
    return $pdo;
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_name('vecplan_sess');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => $secure]);
    ini_set('session.gc_maxlifetime', (string)SESSION_TIMEOUT);
    session_start();
    // Session timeout 8 hours of inactivity (spec §12).
    if (isset($_SESSION['last_seen']) && time() - $_SESSION['last_seen'] > SESSION_TIMEOUT) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['last_seen'] = time();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
