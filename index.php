<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

if (!is_installed()) {
    header('Location: install.php');
    exit;
}
start_session();

$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/') . '/';
$loggedIn = false;
try {
    $loggedIn = current_user() !== null;
    $org = $loggedIn && current_institution_id() ? institution(current_institution_id())['name'] : public_org_name();
} catch (Throwable $e) {
    http_response_code(503);
    echo '<!doctype html><meta charset="utf-8"><title>ระบบขัดข้อง</title><body style="font-family:sans-serif;padding:40px">'
        . '<h2>เชื่อมต่อฐานข้อมูลไม่ได้</h2><p>' . h($e->getMessage()) . '</p><p>ตรวจสอบว่า MariaDB ทำงานอยู่ หรือ <a href="install.php">เรียกตัวติดตั้ง</a> เพื่อแก้ไขการตั้งค่า</p>';
    exit;
}

$boot = [
    'base' => $base,
    'csrf' => $_SESSION['csrf'],
    'logged_in' => $loggedIn,
    'org_name' => $org,
    'version' => APP_VERSION,
];
// Script order matters: each file registers components on window for the next ones.
$scripts = ['core', 'layout', 'dashboard', 'funds', 'ledger', 'projects', 'import', 'settings', 'admin', 'app'];
$v = function (string $file): string {
    return (string)@filemtime(__DIR__ . '/' . $file);
};
?><!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>แผนงานและงบประมาณ · <?= h($org) ?></title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='7' fill='%230F2C5C'/><text x='16' y='21' font-size='13' font-family='sans-serif' font-weight='700' fill='white' text-anchor='middle'>ผง</text></svg>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Sans+Thai:wght@400;500;600;700&family=IBM+Plex+Mono:wght@500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/app.css?v=<?= $v('assets/css/app.css') ?>">
<script>
window.__BOOT__ = <?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
try { var t = localStorage.getItem('vecplan_theme'); if (t) document.documentElement.setAttribute('data-theme', t); } catch (e) {}
</script>
<script src="https://unpkg.com/react@18.3.1/umd/react.production.min.js" crossorigin="anonymous"></script>
<script src="https://unpkg.com/react-dom@18.3.1/umd/react-dom.production.min.js" crossorigin="anonymous"></script>
<script src="https://unpkg.com/@babel/standalone@7.29.0/babel.min.js" crossorigin="anonymous"></script>
</head>
<body>
<div id="root"><div style="padding:40px;text-align:center;color:#5C6880;font-family:sans-serif">กำลังโหลด…</div></div>
<?php foreach ($scripts as $s): ?>
<script type="text/babel" data-presets="react" src="assets/js/<?= $s ?>.jsx?v=<?= $v('assets/js/' . $s . '.jsx') ?>"></script>
<?php endforeach; ?>
<noscript>ต้องเปิดใช้งาน JavaScript</noscript>
</body>
</html>
