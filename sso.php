<?php
declare(strict_types=1);

/**
 * Sign-in through RVC-ONE. .htaccess maps the public URLs onto this file:
 *   auth/login    → sso.php?step=login     start: random state, redirect to RVC-ONE
 *   auth/callback → sso.php?step=callback  RVC-ONE POSTs token_id, token_key, state here
 */
require __DIR__ . '/app/bootstrap.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const OA_STATE_COOKIE = 'vecplan_oa_state';

function sso_page(int $code, string $title, string $message): void
{
    http_response_code($code);
    $home = sso_home();
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . h($title) . '</title>'
        . '<body style="font-family:\'IBM Plex Sans Thai\',sans-serif;padding:40px;max-width:560px;margin:auto;color:#1B2433">'
        . '<h2>' . h($title) . '</h2><p>' . h($message) . '</p>'
        . '<p><a href="' . h($home) . '">กลับไปหน้าเข้าสู่ระบบ</a></p>';
    exit;
}

/** The app's own address (this file sits next to index.php). */
function sso_home(): string
{
    return rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/') . '/';
}

function sso_log(string $msg): void
{
    @file_put_contents(APP_ROOT . '/storage/logs/sso-' . date('Y-m-d') . '.log', '[' . date('c') . '] ' . client_ip() . ' ' . $msg . "\n", FILE_APPEND);
}

if (!is_installed()) sso_page(503, 'ระบบยังไม่ได้ติดตั้ง', 'กรุณาติดต่อผู้ดูแลระบบ');
start_session();
$secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$home = sso_home();

$step = (string)($_GET['step'] ?? '');
if (login_methods() === 'password') {
    sso_page(403, 'ปิดการเข้าสู่ระบบผ่าน RVC-ONE', 'ผู้ดูแลระบบกำหนดให้เข้าสู่ระบบด้วยชื่อผู้ใช้และรหัสผ่านเท่านั้น');
}

if ($step === 'login') {
    $state = bin2hex(random_bytes(24));
    $_SESSION['oa_state'] = $state;
    // RVC-ONE (http) posts back to this site (https) — a cross-site POST that does not carry the Lax
    // session cookie. This short-lived cookie travels with it so the state can still be checked.
    setcookie(OA_STATE_COOKIE, $state, ['expires' => time() + 600, 'path' => '/', 'httponly' => true,
        'secure' => $secure, 'samesite' => $secure ? 'None' : 'Lax']);
    header('Location: ' . RvcOne::authorizeUrl($state), true, 302);
    exit;
}

if ($step !== 'callback') sso_page(404, 'ไม่พบหน้านี้', 'ที่อยู่ไม่ถูกต้อง');

$expected = (string)($_SESSION['oa_state'] ?? ($_COOKIE[OA_STATE_COOKIE] ?? ''));
unset($_SESSION['oa_state']);
setcookie(OA_STATE_COOKIE, '', ['expires' => 1, 'path' => '/', 'httponly' => true, 'secure' => $secure, 'samesite' => $secure ? 'None' : 'Lax']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (($_GET['error'] ?? '') === 'access_denied') {
        sso_page(200, 'ยกเลิกการเข้าสู่ระบบ', 'คุณไม่ได้อนุญาตให้ระบบแผนงานและงบประมาณใช้ข้อมูลจาก RVC-ONE จึงยังไม่ได้เข้าสู่ระบบ');
    }
    sso_page(405, 'เข้าสู่ระบบไม่สำเร็จ', 'คำขอไม่ถูกต้อง กรุณาเริ่มเข้าสู่ระบบใหม่อีกครั้ง');
}

$tokenId = (string)($_POST['token_id'] ?? '');
$tokenKey = (string)($_POST['token_key'] ?? '');
$state = (string)($_POST['state'] ?? '');
// No stored state + empty state = opened from the RVC-ONE Workspace (IdP-initiated); the token check below still applies.
if ($expected !== '' ? !hash_equals($expected, $state) : $state !== '') {
    sso_log('callback rejected: state mismatch');
    sso_page(400, 'เข้าสู่ระบบไม่สำเร็จ', 'คำขอหมดอายุหรือไม่ได้เริ่มจากระบบนี้ (state ไม่ตรงกัน) กรุณาเริ่มเข้าสู่ระบบใหม่อีกครั้ง');
}
if ($tokenId === '' || $tokenKey === '') sso_page(400, 'เข้าสู่ระบบไม่สำเร็จ', 'ไม่ได้รับโทเคนจาก RVC-ONE');

try {
    $oaUser = RvcOne::verifyToken($tokenId, $tokenKey);
} catch (Throwable $e) {
    sso_log('verify failed: ' . $e->getMessage());
    sso_page(401, 'เข้าสู่ระบบไม่สำเร็จ', 'ตรวจสอบการเข้าสู่ระบบกับ RVC-ONE ไม่สำเร็จ: ' . $e->getMessage() . ' — กรุณาลองใหม่อีกครั้ง หรือแจ้งผู้ดูแลระบบ');
}
if ($oaUser === null) {
    sso_log('verify: invalid token');
    sso_page(401, 'เข้าสู่ระบบไม่สำเร็จ', 'โทเคนไม่ถูกต้องหรือหมดอายุ (อายุ 5 นาที) กรุณาเข้าสู่ระบบใหม่อีกครั้ง');
}

try {
    $u = RvcOne::userForLogin($oaUser);
} catch (Throwable $e) {
    sso_log('user mapping failed for oa id ' . (int)$oaUser['id'] . ': ' . $e->getMessage());
    sso_page(500, 'เข้าสู่ระบบไม่สำเร็จ', 'สร้างบัญชีผู้ใช้ไม่สำเร็จ กรุณาติดต่อผู้ดูแลระบบ');
}
if (!$u['active']) sso_page(403, 'บัญชีถูกระงับ', 'บัญชีนี้ถูกปิดการใช้งานในระบบแผนงานและงบประมาณ กรุณาติดต่อผู้ดูแลระบบ');
if ($u['institution_id'] && !$u['institution_active']) sso_page(403, 'สถานศึกษาถูกปิดการใช้งาน', 'กรุณาติดต่อผู้ดูแลระบบกลาง');

session_regenerate_id(true);
$_SESSION['uid'] = (int)$u['id'];
$_SESSION['csrf'] = bin2hex(random_bytes(32));
db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$u['id']]);
audit('auth.login_sso', 'user', (int)$u['id'], null, ['oa_user_id' => (int)$oaUser['id']]);
header('Location: ' . $home, true, 303);
