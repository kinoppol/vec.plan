<?php
declare(strict_types=1);

/**
 * RVC-ONE: single sign-on (authorize → callback → verify_token) and the server-to-server User-sync API.
 * Settings come from config/oa.php (+ config/oa.local.php, + env OA_CLIENT_SECRET) through config().
 *
 * Users are matched by RVC-ONE's user id, stored in users.oa_user_id. An account that existed before
 * (e.g. transferred from RMS) is linked once by username or e-mail inside the target institution.
 * Accounts created here get an unknown random password: they sign in through RVC-ONE.
 */
class RvcOne
{
    /** Tests replace HTTP: fn(string $method, string $url, array $headers, ?string $body): array [status, body]. */
    public static $transport = null;
    /** Tests replace the pause between retries. */
    public static $sleep = 'sleep';
    /** Tests override config keys here. */
    public static array $override = [];

    private const RETRIES = 3;

    public static function config(): array
    {
        static $c = null;
        if ($c === null) {
            $c = require APP_ROOT . '/config/oa.php';
            $local = APP_ROOT . '/config/oa.local.php';
            if (is_file($local)) $c = array_replace($c, (array)(require $local));
        }
        return array_replace($c, self::$override);
    }

    public static function authorizeUrl(string $state): string
    {
        $c = self::config();
        return $c['authorize_url'] . '?' . http_build_query([
            'client_id' => $c['client_id'], 'redirect_uri' => $c['redirect_uri'], 'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    // ------------------------------------------------------------------ SSO

    /** The verified user object, or null when RVC-ONE says the token is not valid. Throws when the endpoint cannot be reached. */
    public static function verifyToken(string $tokenId, string $tokenKey): ?array
    {
        // Shape check only; the endpoint is the authority. Never trust the user id inside token_id.
        if (!preg_match('/^\d+_\d+$/', $tokenId) || !preg_match('/^[0-9a-f]{64}$/i', $tokenKey)) return null;
        [$status, $raw] = self::request('POST', self::config()['verify_url'],
            ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            http_build_query(['token_id' => $tokenId, 'token_key' => $tokenKey]), 1);
        $data = json_decode($raw, true);
        if ($status === 200 && is_array($data) && ($data['valid'] ?? false) === true && isset($data['user']['id'])) return $data['user'];
        if (in_array($status, [200, 400, 401, 403], true) && is_array($data)) return null;
        throw new RuntimeException('ตรวจสอบโทเคนกับ RVC-ONE ไม่สำเร็จ (HTTP ' . $status . ')');
    }

    /** Local user for a verified RVC-ONE user (created on first sign-in). Returns the users row. */
    public static function userForLogin(array $oaUser): array
    {
        $id = self::upsert($oaUser, (int)self::config()['institution_id'])['id'];
        $st = db()->prepare('SELECT u.id, u.institution_id, u.active, i.active AS institution_active
            FROM users u LEFT JOIN institutions i ON i.id = u.institution_id WHERE u.id = ?');
        $st->execute([$id]);
        return $st->fetch();
    }

    // ------------------------------------------------------------------ user upsert

    /**
     * Insert or update one RVC-ONE user (idempotent). Every field except id is optional.
     * Returns ['id' => local id, 'action' => created|updated|suspended|skipped, 'note' => ?string].
     */
    public static function upsert(array $u, int $inst): array
    {
        $pdo = db();
        $oaId = (int)($u['id'] ?? 0);
        if ($oaId <= 0) return ['id' => 0, 'action' => 'skipped', 'note' => 'ไม่มี id'];
        $name = trim(preg_replace('/\s+/u', ' ', trim((string)($u['first_name'] ?? '')) . ' ' . trim((string)($u['last_name'] ?? ''))));
        if ($name === '') $name = 'ผู้ใช้ RVC-ONE #' . $oaId;
        $name = mb_substr($name, 0, 200);
        $dept = isset($u['department']) && trim((string)$u['department']) !== '' ? mb_substr(trim((string)$u['department']), 0, 200) : null;
        $type = isset($u['user_type']) ? mb_substr((string)$u['user_type'], 0, 20) : null;
        $status = isset($u['status']) && in_array($u['status'], ['active', 'suspended'], true) ? $u['status'] : null;
        $email = null;
        foreach (['email', 'google_email'] as $k) {
            $e = mb_strtolower(trim((string)($u[$k] ?? '')));
            if ($e !== '' && mb_strlen($e) <= 190 && filter_var($e, FILTER_VALIDATE_EMAIL)) { $email = $e; break; }
        }
        $username = trim((string)($u['username'] ?? ''));
        if ($username !== '' && (mb_strlen($username) > 60 || preg_match('/\s/u', $username))) $username = '';

        $st = $pdo->prepare('SELECT id, institution_id, active, oa_status, email FROM users WHERE oa_user_id = ?');
        $st->execute([$oaId]);
        $old = $st->fetch() ?: null;
        if (!$old) {
            // Link an account that already exists in this institution (e.g. from RMS) — once.
            foreach (['username' => $username, 'email' => $email] as $col => $val) {
                if ($val === '' || $val === null) continue;
                $st = $pdo->prepare("SELECT id, institution_id, active, oa_status, email FROM users WHERE $col = ? AND institution_id = ? AND oa_user_id IS NULL");
                $st->execute([$val, $inst]);
                if ($old = $st->fetch() ?: null) break;
            }
        }

        if ($email !== null) {
            $st = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ?');
            $st->execute([$email, $old['id'] ?? 0]);
            if ($st->fetchColumn()) $email = null; // keep e-mails unique; the account keeps whatever it had
        }

        if ($old) {
            $id = (int)$old['id'];
            // Only a change of the RVC-ONE status switches the account, so a local deactivation stays put.
            $active = (int)$old['active'];
            if ($status !== null && $status !== $old['oa_status']) $active = $status === 'active' ? 1 : 0;
            $pdo->prepare('UPDATE users SET name = ?, email = COALESCE(?, email), department = COALESCE(?, department),
                oa_user_id = ?, oa_user_type = COALESCE(?, oa_user_type), oa_status = COALESCE(?, oa_status), active = ?, synced_at = NOW() WHERE id = ?')
                ->execute([$name, $email, $dept, $oaId, $type, $status, $active, $id]);
            $action = ($active === 0 && (int)$old['active'] === 1) ? 'suspended' : 'updated';
            return ['id' => $id, 'action' => $action, 'note' => null];
        }

        if ($username === '' || self::usernameTaken($username)) {
            $username = 'oa' . $oaId;
            for ($n = 2; self::usernameTaken($username); $n++) $username = 'oa' . $oaId . '_' . $n;
        }
        $pdo->prepare('INSERT INTO users (institution_id, username, name, email, password_hash, department, source, synced_at,
                oa_user_id, oa_user_type, oa_status, active) VALUES (?, ?, ?, ?, ?, ?, \'oa\', NOW(), ?, ?, ?, ?)')
            ->execute([$inst, $username, $name, $email, password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), $dept,
                $oaId, $type, $status, $status === 'suspended' ? 0 : 1]);
        return ['id' => (int)$pdo->lastInsertId(), 'action' => $status === 'suspended' ? 'suspended' : 'created', 'note' => null];
    }

    private static function usernameTaken(string $username): bool
    {
        $st = db()->prepare('SELECT 1 FROM users WHERE username = ?');
        $st->execute([$username]);
        return (bool)$st->fetchColumn();
    }

    // ------------------------------------------------------------------ User-sync API

    /**
     * One sync run: all pages since the stored since (or everything with $full). since moves to the
     * run's server_time only after the last page; a failure leaves it where it was.
     */
    public static function sync(bool $full = false, ?callable $log = null): array
    {
        $c = self::config();
        $inst = (int)$c['institution_id'];
        if (empty($c['client_secret'])) throw new RuntimeException('ยังไม่ได้ตั้งค่า OA_CLIENT_SECRET');
        institution($inst); // must exist

        $lock = fopen(APP_ROOT . '/storage/logs/oa-sync.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('มีการซิงค์ผู้ใช้จาก RVC-ONE กำลังทำงานอยู่');
        try {
            $since = $full ? null : (setting('oa_sync_since', null, $inst) ?: null);
            $r = ['full' => $since === null, 'since' => $since, 'pages' => 0, 'received' => 0,
                'created' => 0, 'updated' => 0, 'suspended' => 0, 'skipped' => 0, 'server_time' => null];
            $query = array_filter(['type' => $c['sync_type'] ?? null, 'since' => $since, 'limit' => (int)($c['sync_page_size'] ?? 500)],
                fn($v) => $v !== null && $v !== '');
            $restarted = false;
            $cursor = null;
            while (true) {
                try {
                    $page = self::fetchPage($cursor === null ? $query : ['cursor' => $cursor]);
                } catch (OaApiError $e) {
                    // An expired cursor: start this run again from the first page (upserts are idempotent).
                    if ($e->error !== 'bad_cursor' || $restarted) throw $e;
                    $restarted = true;
                    $cursor = null;
                    $r['server_time'] = null;
                    continue;
                }
                $r['pages']++;
                $r['server_time'] = $r['server_time'] ?? (string)$page['server_time'];
                foreach ((array)($page['users'] ?? []) as $u) {
                    if (!is_array($u)) continue;
                    $r['received']++;
                    $r[self::upsert($u, $inst)['action']]++;
                }
                $cursor = !empty($page['has_more']) ? (string)($page['next_cursor'] ?? '') : null;
                if ($cursor === '') throw new RuntimeException('RVC-ONE ตอบ has_more แต่ไม่มี next_cursor');
                if ($cursor === null) break;
            }

            if (!$r['server_time']) throw new RuntimeException('RVC-ONE ไม่ได้ส่ง server_time');
            set_setting('oa_sync_since', $r['server_time'], $inst);
            set_setting('oa_sync_last', json_encode($r + ['finished_at' => now()], JSON_UNESCAPED_UNICODE), $inst);
            $log && $log(sprintf('ok pages=%d received=%d created=%d updated=%d suspended=%d skipped=%d since=%s next_since=%s',
                $r['pages'], $r['received'], $r['created'], $r['updated'], $r['suspended'], $r['skipped'], $since ?? '-', $r['server_time']));
            $prev = $GLOBALS["__institution_id"] ?? null;
            use_institution($inst);
            audit('users.oa_sync', 'institution', $inst, null, $r);
            use_institution($prev);
            return $r;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function fetchPage(array $query): array
    {
        $c = self::config();
        $auth = 'Authorization: Basic ' . base64_encode($c['client_id'] . ':' . $c['client_secret']);
        [$status, $raw] = self::request('POST', $c['users_url'],
            [$auth, 'Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'], http_build_query($query), self::RETRIES);
        $data = json_decode($raw, true);
        if ($status === 200 && is_array($data) && ($data['ok'] ?? false) === true) return $data;
        $err = is_array($data) ? (string)($data['error'] ?? '') : '';
        $msg = is_array($data) ? (string)($data['message'] ?? '') : '';
        throw new OaApiError($err, 'User-sync API ตอบ HTTP ' . $status . ($err !== '' ? ' ' . $err : '') . ($msg !== '' ? ': ' . $msg : ''));
    }

    /** HTTP with a timeout; network errors and 5xx are retried up to $attempts times. Nothing secret is logged. */
    private static function request(string $method, string $url, array $headers, ?string $body, int $attempts): array
    {
        for ($i = 1; ; $i++) {
            try {
                $res = self::$transport ? (self::$transport)($method, $url, $headers, $body) : self::curl($method, $url, $headers, $body);
                if ($res[0] < 500 || $i >= $attempts) return $res;
            } catch (RuntimeException $e) {
                if ($i >= $attempts) throw $e;
            }
            (self::$sleep)($i * 2);
        }
    }

    private static function curl(string $method, string $url, array $headers, ?string $body): array
    {
        $timeout = (int)(self::config()['timeout'] ?? 15);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => min(10, $timeout), CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $raw = curl_exec($ch);
        if ($raw === false) throw new RuntimeException('เชื่อมต่อ RVC-ONE ไม่ได้: ' . curl_error($ch));
        return [(int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), (string)$raw];
    }
}

class OaApiError extends RuntimeException
{
    public string $error;

    public function __construct(string $error, string $message)
    {
        parent::__construct($message);
        $this->error = $error;
    }
}
