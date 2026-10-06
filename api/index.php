<?php
declare(strict_types=1);

/**
 * JSON API front controller: api/?r=resource/action
 * Each app/api/<resource>.php returns ['METHOD action' => callable].
 */
require dirname(__DIR__) . '/app/bootstrap.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

if (!is_installed()) json_out(['error' => 'ระบบยังไม่ได้ติดตั้ง', 'install' => true], 503);
start_session();

$route = (string)($_GET['r'] ?? '');
if (!preg_match('#^([a-z_]+)(?:/([a-z_]+))?$#', $route, $m)) json_out(['error' => 'ไม่พบ API'], 404);
$resource = $m[1];
$action = $m[2] ?? 'index';
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET' && !hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
    json_out(['error' => 'เซสชันหมดอายุหรือ CSRF token ไม่ถูกต้อง กรุณาโหลดหน้าใหม่', 'csrf' => true], 419);
}

$file = APP_ROOT . '/app/api/' . $resource . '.php';
if (!is_file($file)) json_out(['error' => 'ไม่พบ API'], 404);

/** Request body: JSON or form fields. */
function body(): array
{
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

function log_error(Throwable $e): void
{
    @file_put_contents(APP_ROOT . '/storage/logs/error-' . date('Y-m-d') . '.log',
        '[' . date('c') . '] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n", FILE_APPEND);
}

try {
    $handlers = require $file;
    $key = $method . ' ' . $action;
    if (!isset($handlers[$key])) json_out(['error' => 'ไม่พบ API'], 404);
    $result = $handlers[$key]();
    json_out($result ?? ['ok' => true]);
} catch (ApiError $e) {
    $code = $e->getCode();
    json_out(['error' => $e->getMessage()] + $e->extra, $code >= 400 && $code < 600 ? $code : 400);
} catch (PDOException $e) {
    log_error($e);
    // SIGNAL from the append-only ledger trigger, or a CHECK / FK violation.
    $info = $e->errorInfo ?? [];
    if (($info[0] ?? '') === '45000') json_out(['error' => $info[2] ?? 'ฐานข้อมูลปฏิเสธการเปลี่ยนแปลง'], 409);
    if (($info[0] ?? '') === '23000') json_out(['error' => 'ข้อมูลขัดกับข้อจำกัดของฐานข้อมูล (ซ้ำหรือมีการอ้างอิงอยู่): ' . ($info[2] ?? '')], 409);
    json_out(['error' => 'ฐานข้อมูลผิดพลาด: ' . $e->getMessage()], 500);
} catch (Throwable $e) {
    log_error($e);
    json_out(['error' => 'เกิดข้อผิดพลาดภายในระบบ: ' . $e->getMessage()], 500);
}
