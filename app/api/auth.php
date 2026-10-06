<?php
declare(strict_types=1);

return [
    'POST login' => function () {
        $b = body();
        $username = trim((string)($b['username'] ?? ''));
        $password = (string)($b['password'] ?? '');
        if ($username === '' || $password === '') fail('กรุณากรอกชื่อผู้ใช้และรหัสผ่าน');
        $pdo = db();

        // Rate limit (spec §12): 5 failures per username or 20 per IP in 15 minutes.
        $st = $pdo->prepare('SELECT
            SUM(username = ? AND success = 0) AS by_user, SUM(ip = ? AND success = 0) AS by_ip
            FROM login_attempts WHERE created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)');
        $st->execute([$username, client_ip()]);
        $rl = $st->fetch();
        if ((int)$rl['by_user'] >= 5 || (int)$rl['by_ip'] >= 20) fail('พยายามเข้าสู่ระบบผิดหลายครั้ง กรุณารอ 15 นาที', 429);

        $st = $pdo->prepare('SELECT u.id, u.institution_id, u.password_hash, u.active, i.active AS institution_active
            FROM users u LEFT JOIN institutions i ON i.id = u.institution_id WHERE u.username = ? OR u.email = ? LIMIT 1');
        $st->execute([$username, $username]);
        $u = $st->fetch();
        $ok = $u && $u['active'] && password_verify($password, $u['password_hash']);
        $pdo->prepare('INSERT INTO login_attempts (username, ip, success) VALUES (?, ?, ?)')->execute([$username, client_ip(), $ok ? 1 : 0]);
        if (!$ok) {
            usleep(400000);
            fail('ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง', 401);
        }
        if ($u['institution_id'] && !$u['institution_active']) fail('สถานศึกษาของบัญชีนี้ถูกปิดการใช้งาน กรุณาติดต่อผู้ดูแลระบบกลาง', 403);
        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $u['id']]);
        }
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$u['id'];
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$u['id']]);
        audit('auth.login', 'user', (int)$u['id']);
        return ['ok' => true, 'csrf' => $_SESSION['csrf']];
    },

    'POST logout' => function () {
        if (!empty($_SESSION['uid'])) audit('auth.logout', 'user', (int)$_SESSION['uid']);
        $_SESSION = [];
        session_regenerate_id(true);
        return ['ok' => true];
    },

    'POST password' => function () {
        $u = require_login();
        $b = body();
        $st = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
        $st->execute([$u['id']]);
        if (!password_verify((string)($b['current'] ?? ''), (string)$st->fetchColumn())) fail('รหัสผ่านปัจจุบันไม่ถูกต้อง');
        $new = (string)($b['new'] ?? '');
        if (mb_strlen($new) < MIN_PASSWORD_LENGTH) fail('รหัสผ่านใหม่ต้องมีอย่างน้อย ' . MIN_PASSWORD_LENGTH . ' ตัวอักษร');
        db()->prepare('UPDATE users SET password_hash = ?, password_changed_at = NOW() WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $u['id']]);
        audit('auth.password_change', 'user', (int)$u['id']);
        return ['ok' => true];
    },
];
