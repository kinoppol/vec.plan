<?php
declare(strict_types=1);

/**
 * Transfer users from an RMS server (ระบบบริหารจัดการสถานศึกษา) into the current institution.
 * The server address (scheme + host, e.g. http://rms.rvc.ac.th) is an institution setting
 * (rms_base_url) the admin edits; the endpoint paths below stay in code.
 *
 * Mapping: people_id → username, people_name + ' ' + people_surname → name, people_email → email,
 * ath_pass → password (hashed), people_pic → avatar downloaded from {base}/files/{people_pic}.
 * Only people with people_exit = 0 are transferred. A repeated transfer updates the same user
 * (matched by username) and never touches created_at.
 */
class RmsSync
{
    public const PEOPLE_PATH = '/api_connection.php?app_name=nutty&data=people';
    public const FILES_PATH = '/files/';
    public const AVATAR_MIME = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    private const AVATAR_MAX = 5 * 1024 * 1024;

    /** Tests replace the HTTP GET: fn(string $url): string (response body). */
    public static $transport = null;

    public static function baseUrl(): string
    {
        return rtrim(trim((string)setting('rms_base_url', '')), '/');
    }

    public static function normalizeBase(string $url): string
    {
        $url = rtrim(trim($url), '/');
        if (!preg_match('#^https?://[A-Za-z0-9.-]+(:\d{1,5})?(/[A-Za-z0-9._~/-]*)?$#', $url)) {
            fail('URL ของระบบ RMS ต้องอยู่ในรูป http(s)://ชื่อโดเมน เช่น http://rms.rvc.ac.th');
        }
        return $url;
    }

    /** People rows from RMS (a JSON list; a list wrapped in an object is accepted too). */
    public static function fetchPeople(string $base): array
    {
        $raw = self::get($base . self::PEOPLE_PATH, 30, 50 * 1024 * 1024);
        $data = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', $raw), true);
        if (is_array($data) && !array_is_list($data)) {
            foreach ($data as $v) if (is_array($v) && array_is_list($v)) { $data = $v; break; }
        }
        if (!is_array($data) || !array_is_list($data)) fail('ข้อมูลจาก RMS ไม่ใช่รายการผู้ใช้แบบ JSON ที่ถูกต้อง', 502);
        return array_values(array_filter($data, 'is_array'));
    }

    /** RMS row → user fields, or a reason it cannot be used. */
    public static function mapPerson(array $p): array
    {
        $username = trim((string)($p['people_id'] ?? ''));
        $name = trim(preg_replace('/\s+/u', ' ', trim((string)($p['people_name'] ?? '')) . ' ' . trim((string)($p['people_surname'] ?? ''))));
        $email = mb_strtolower(trim((string)($p['people_email'] ?? '')));
        $pic = trim((string)($p['people_pic'] ?? ''));
        $error = null;
        if ($username === '' || mb_strlen($username) > 60) $error = 'people_id ว่างหรือยาวเกิน 60 ตัวอักษร';
        elseif ($name === '') $error = 'ไม่มีชื่อ';
        // Only plain file names below /files/ (no traversal, no query strings).
        if ($pic !== '' && (str_contains($pic, '..') || !preg_match('#^[A-Za-z0-9._/-]+$#', $pic))) $pic = '';
        return [
            'username' => $username,
            'name' => mb_substr($name, 0, 200),
            'email' => $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && mb_strlen($email) <= 190 ? $email : null,
            'email_invalid' => $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL),
            'password' => (string)($p['ath_pass'] ?? ''),
            'pic' => $pic,
            'error' => $error,
        ];
    }

    /**
     * Transfer (or, with $dryRun, only count what would happen). Returns counts and per-person notes.
     * Pictures are downloaded only when people_pic changed or the stored file is missing.
     */
    public static function sync(bool $dryRun = false): array
    {
        $inst = require_institution();
        $base = self::baseUrl();
        if ($base === '') fail('ยังไม่ได้ตั้งค่า URL ของระบบ RMS', 409);
        $rows = self::fetchPeople($base);
        $pdo = db();
        $r = ['total' => count($rows), 'exited' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'no_password' => 0,
            'password_updated' => 0, 'avatars' => 0, 'avatar_errors' => 0, 'with_picture' => 0, 'notes' => []];
        $note = function (string $who, string $msg) use (&$r) { if (count($r['notes']) < 200) $r['notes'][] = ['who' => $who, 'msg' => $msg]; };
        $find = $pdo->prepare('SELECT id, institution_id, password_hash, avatar_path, avatar_source FROM users WHERE username = ?');
        $emailOwner = $pdo->prepare('SELECT id FROM users WHERE email = ? AND username <> ?');
        $seen = [];

        foreach ($rows as $p) {
            if ((string)($p['people_exit'] ?? '') !== '0') { $r['exited']++; continue; }
            $u = self::mapPerson($p);
            $who = $u['name'] !== '' ? $u['name'] : $u['username'];
            if ($u['error']) { $r['skipped']++; $note($who, $u['error']); continue; }
            if (isset($seen[$u['username']])) { $r['skipped']++; $note($who, 'people_id ซ้ำในข้อมูล RMS'); continue; }
            $seen[$u['username']] = true;
            $find->execute([$u['username']]);
            $old = $find->fetch() ?: null;
            if ($old && (int)$old['institution_id'] !== $inst) {
                $r['skipped']++;
                $note($who, 'ชื่อผู้ใช้ ' . $u['username'] . ' เป็นของสถานศึกษาอื่นหรือผู้ดูแลระบบกลาง');
                continue;
            }
            if ($u['email_invalid']) $note($who, 'อีเมลไม่ถูกต้อง — ไม่ได้บันทึกอีเมล');
            if ($u['email'] !== null) {
                $emailOwner->execute([$u['email'], $u['username']]);
                if ($emailOwner->fetchColumn()) { $note($who, 'อีเมล ' . $u['email'] . ' ซ้ำกับผู้ใช้อื่น — ไม่ได้บันทึกอีเมล'); $u['email'] = null; }
            }
            if ($u['pic'] !== '') $r['with_picture']++;
            $hasPassword = $u['password'] !== '';
            if (!$hasPassword && !$old) $r['no_password']++;

            if ($dryRun) {
                $old ? $r['updated']++ : $r['created']++;
                continue;
            }

            if ($old) {
                $id = (int)$old['id'];
                $setPass = $hasPassword && !password_verify($u['password'], (string)$old['password_hash']);
                $pdo->prepare('UPDATE users SET name = ?, email = ?, source = \'rms\', synced_at = NOW()'
                    . ($setPass ? ', password_hash = ?, password_changed_at = NOW()' : '') . ' WHERE id = ?')
                    ->execute(array_merge([$u['name'], $u['email']], $setPass ? [password_hash($u['password'], PASSWORD_DEFAULT)] : [], [$id]));
                if ($setPass) $r['password_updated']++;
                $r['updated']++;
            } else {
                // Without ath_pass the account gets an unknown random password: the admin resets it before first use.
                $hash = password_hash($hasPassword ? $u['password'] : bin2hex(random_bytes(24)), PASSWORD_DEFAULT);
                $pdo->prepare('INSERT INTO users (institution_id, username, name, email, password_hash, password_changed_at, source, synced_at, active)
                    VALUES (?, ?, ?, ?, ?, ?, \'rms\', NOW(), 1)')
                    ->execute([$inst, $u['username'], $u['name'], $u['email'], $hash, $hasPassword ? now() : null]);
                $id = (int)$pdo->lastInsertId();
                $old = ['avatar_path' => null, 'avatar_source' => null];
                $r['created']++;
            }

            try {
                $changed = self::syncAvatar($id, $inst, $base, $u['pic'], $old['avatar_path'], $old['avatar_source']);
                if ($changed) $r['avatars']++;
            } catch (Throwable $e) {
                $r['avatar_errors']++;
                $note($who, 'ดาวน์โหลดรูปไม่สำเร็จ: ' . $e->getMessage());
            }
        }
        if (!$dryRun) {
            audit('users.rms_sync', 'institution', $inst, null, array_diff_key($r, ['notes' => 1]) + ['base_url' => $base]);
        }
        return $r;
    }

    /** Download / replace / remove a user's avatar. Returns true when something changed. */
    private static function syncAvatar(int $userId, int $inst, string $base, string $pic, ?string $path, ?string $source): bool
    {
        $file = $path ? UPLOAD_DIR . '/' . $path : null;
        if ($pic === '') {
            // No picture in RMS any more: drop the one RMS gave us earlier (initials are shown instead).
            if (!$source) return false;
            if ($file && is_file($file)) @unlink($file);
            db()->prepare('UPDATE users SET avatar_path = NULL, avatar_source = NULL WHERE id = ?')->execute([$userId]);
            return true;
        }
        if ($pic === $source && $file && is_file($file)) return false;

        $url = $base . self::FILES_PATH . implode('/', array_map('rawurlencode', explode('/', $pic)));
        $bin = self::get($url, 20, self::AVATAR_MAX);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bin);
        if (!isset(self::AVATAR_MIME[$mime]) || @getimagesizefromstring($bin) === false) fail('ไฟล์รูปไม่ใช่ JPG/PNG/GIF/WEBP');
        $rel = 'avatars/i' . $inst;
        if (!is_dir(UPLOAD_DIR . '/' . $rel) && !mkdir(UPLOAD_DIR . '/' . $rel, 0775, true)) fail('สร้างโฟลเดอร์เก็บรูปไม่ได้');
        $rel .= '/' . $userId . '_' . bin2hex(random_bytes(4)) . '.' . self::AVATAR_MIME[$mime];
        if (file_put_contents(UPLOAD_DIR . '/' . $rel, $bin) === false) fail('บันทึกรูปไม่ได้');
        db()->prepare('UPDATE users SET avatar_path = ?, avatar_source = ? WHERE id = ?')->execute([$rel, $pic, $userId]);
        if ($file && is_file($file)) @unlink($file);
        return true;
    }

    private static function get(string $url, int $timeout, int $maxBytes): string
    {
        if (self::$transport) return (self::$transport)($url);
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $buf = '';
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => false, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => $timeout, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HTTPHEADER => ['Accept: application/json, image/*'],
                CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$buf, $maxBytes) {
                    $buf .= $chunk;
                    return strlen($buf) > $maxBytes ? 0 : strlen($chunk); // abort oversized downloads
                },
            ]);
            $ok = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if ($ok === false) fail(strlen($buf) > $maxBytes ? 'ไฟล์ใหญ่เกินกำหนด' : 'เชื่อมต่อระบบ RMS ไม่ได้: ' . curl_error($ch), 502);
            if ($status !== 200) fail('ระบบ RMS ตอบกลับ HTTP ' . $status, 502);
            return $buf;
        }
        $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'follow_location' => 0, 'ignore_errors' => true]]);
        $res = @file_get_contents($url, false, $ctx, 0, $maxBytes + 1);
        if ($res === false) fail('เชื่อมต่อระบบ RMS ไม่ได้', 502);
        $status = preg_match('#^HTTP/\S+\s+(\d{3})#', $http_response_header[0] ?? '', $m) ? (int)$m[1] : 0;
        if ($status !== 200) fail('ระบบ RMS ตอบกลับ HTTP ' . $status, 502);
        if (strlen($res) > $maxBytes) fail('ไฟล์ใหญ่เกินกำหนด');
        return $res;
    }
}
