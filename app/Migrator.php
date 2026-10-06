<?php
declare(strict_types=1);

/**
 * Database migration runner.
 *
 * A migration is a file in migrations/ named YYYY_MM_DD_HHMMSS_name.(sql|php):
 *  - .sql: sections introduced by "-- @up" and "-- @down"; an optional "-- @description ..." line.
 *  - .php: returns ['description' => string, 'up' => callable(PDO), 'down' => callable(PDO)|null].
 * MariaDB auto-commits DDL, so each migration is recorded right after it succeeds;
 * a failed migration stops the run and leaves later ones pending.
 */
class Migrator
{
    private const NAME_PATTERN = '/^\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]+\.(sql|php)$/';

    public function __construct(private PDO $pdo, private string $dir = MIGRATIONS_DIR)
    {
    }

    public function ensureTable(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS migrations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            migration VARCHAR(190) NOT NULL,
            batch INT NOT NULL,
            checksum CHAR(40) NOT NULL,
            duration_ms INT NOT NULL DEFAULT 0,
            applied_by BIGINT UNSIGNED NULL,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_migration (migration)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    /** @return array<string,string> migration name => full path, in run order */
    public function files(): array
    {
        $out = [];
        foreach (glob($this->dir . '/*.{sql,php}', GLOB_BRACE) ?: [] as $path) {
            $name = basename($path);
            if (preg_match(self::NAME_PATTERN, $name)) $out[$name] = $path;
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /** @return array<string,array> */
    public function applied(): array
    {
        $this->ensureTable();
        $rows = $this->pdo->query('SELECT * FROM migrations ORDER BY id')->fetchAll();
        // users may not exist yet (first migration) or may have been rolled back.
        $names = [];
        $ids = array_filter(array_map(fn($r) => (int)$r['applied_by'], $rows));
        if ($ids) {
            try {
                foreach ($this->pdo->query('SELECT id, name FROM users WHERE id IN (' . implode(',', $ids) . ')') as $u) $names[(int)$u['id']] = $u['name'];
            } catch (PDOException $e) {
            }
        }
        $out = [];
        foreach ($rows as $r) $out[$r['migration']] = $r + ['applied_by_name' => $names[(int)$r['applied_by']] ?? null];
        return $out;
    }

    public function status(): array
    {
        $files = $this->files();
        $applied = $this->applied();
        $list = [];
        foreach ($files as $name => $path) {
            $def = $this->parse($path);
            $row = $applied[$name] ?? null;
            $list[] = [
                'name' => $name,
                'description' => $def['description'],
                'type' => pathinfo($name, PATHINFO_EXTENSION),
                'has_down' => $def['down'] !== null,
                'applied' => $row !== null,
                'batch' => $row ? (int)$row['batch'] : null,
                'applied_at' => $row['applied_at'] ?? null,
                'applied_by' => $row['applied_by_name'] ?? null,
                'duration_ms' => $row ? (int)$row['duration_ms'] : null,
                'modified' => $row !== null && $row['checksum'] !== sha1_file($path),
                'missing' => false,
            ];
        }
        // Applied in the database but the file is gone.
        foreach ($applied as $name => $row) {
            if (isset($files[$name])) continue;
            $list[] = [
                'name' => $name, 'description' => '(ไม่พบไฟล์)', 'type' => pathinfo($name, PATHINFO_EXTENSION),
                'has_down' => false, 'applied' => true, 'batch' => (int)$row['batch'], 'applied_at' => $row['applied_at'],
                'applied_by' => $row['applied_by_name'], 'duration_ms' => (int)$row['duration_ms'], 'modified' => false, 'missing' => true,
            ];
        }
        return $list;
    }

    public function pending(): array
    {
        $applied = $this->applied();
        return array_values(array_filter(array_keys($this->files()), fn($n) => !isset($applied[$n])));
    }

    public function parse(string $path): array
    {
        if (str_ends_with($path, '.php')) {
            $def = (static function (string $__path) { return require $__path; })($path);
            if (!is_array($def) || !isset($def['up']) || !is_callable($def['up'])) {
                throw new RuntimeException('ไฟล์ migration PHP ต้อง return [\'up\' => callable]: ' . basename($path));
            }
            return ['description' => (string)($def['description'] ?? ''), 'up' => $def['up'], 'down' => $def['down'] ?? null];
        }
        $sql = file_get_contents($path);
        $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql);
        $description = preg_match('/^--\s*@description\s+(.+)$/m', $sql, $m) ? trim($m[1]) : '';
        $up = $sql;
        $down = null;
        if (preg_match('/^--\s*@up\s*$/m', $sql, $m, PREG_OFFSET_CAPTURE)) {
            $up = substr($sql, $m[0][1] + strlen($m[0][0]));
        }
        if (preg_match('/^--\s*@down\s*$/m', $up, $m, PREG_OFFSET_CAPTURE)) {
            $down = substr($up, $m[0][1] + strlen($m[0][0]));
            $up = substr($up, 0, $m[0][1]);
            if (trim(self::stripComments($down)) === '') $down = null;
        }
        return ['description' => $description, 'up' => $up, 'down' => $down];
    }

    /** Run every pending migration (or just $only) as one new batch. */
    public function migrate(?int $userId = null, ?string $only = null): array
    {
        $this->lock();
        try {
            $pending = $this->pending();
            if ($only !== null) {
                if (!in_array($only, $pending, true)) throw new RuntimeException('migration นี้ไม่อยู่ในสถานะรอรัน: ' . $only);
                $pending = [$only];
            }
            $batch = $this->nextBatch();
            $results = [];
            $files = $this->files();
            foreach ($pending as $name) {
                $start = microtime(true);
                try {
                    $this->execute($this->parse($files[$name])['up']);
                } catch (Throwable $e) {
                    $results[] = ['name' => $name, 'ok' => false, 'error' => $e->getMessage()];
                    break;
                }
                $ms = (int)round((microtime(true) - $start) * 1000);
                $this->pdo->prepare('INSERT INTO migrations (migration, batch, checksum, duration_ms, applied_by) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$name, $batch, sha1_file($files[$name]), $ms, $userId]);
                $results[] = ['name' => $name, 'ok' => true, 'duration_ms' => $ms];
            }
            return $results;
        } finally {
            $this->unlock();
        }
    }

    /** Roll back the most recent batch (or a single named migration). */
    public function rollback(?string $only = null): array
    {
        $this->lock();
        try {
            $applied = $this->applied();
            if (!$applied) return [];
            if ($only !== null) {
                if (!isset($applied[$only])) throw new RuntimeException('migration นี้ยังไม่ได้รัน: ' . $only);
                $names = [$only];
            } else {
                $last = max(array_map(fn($r) => (int)$r['batch'], $applied));
                $names = array_keys(array_filter($applied, fn($r) => (int)$r['batch'] === $last));
                rsort($names, SORT_STRING);
            }
            $files = $this->files();
            $results = [];
            foreach ($names as $name) {
                if (!isset($files[$name])) {
                    $results[] = ['name' => $name, 'ok' => false, 'error' => 'ไม่พบไฟล์ migration'];
                    break;
                }
                $down = $this->parse($files[$name])['down'];
                if ($down === null) {
                    $results[] = ['name' => $name, 'ok' => false, 'error' => 'migration นี้ไม่มีส่วน @down'];
                    break;
                }
                try {
                    $this->execute($down);
                } catch (Throwable $e) {
                    $results[] = ['name' => $name, 'ok' => false, 'error' => $e->getMessage()];
                    break;
                }
                $this->pdo->prepare('DELETE FROM migrations WHERE migration = ?')->execute([$name]);
                $results[] = ['name' => $name, 'ok' => true];
            }
            return $results;
        } finally {
            $this->unlock();
        }
    }

    /** Record a migration as applied without running it (e.g. the change was made by hand). */
    public function markApplied(string $name, ?int $userId): void
    {
        $files = $this->files();
        if (!isset($files[$name])) throw new RuntimeException('ไม่พบไฟล์ migration: ' . $name);
        $this->pdo->prepare('INSERT IGNORE INTO migrations (migration, batch, checksum, duration_ms, applied_by) VALUES (?, ?, ?, 0, ?)')
            ->execute([$name, $this->nextBatch(), sha1_file($files[$name]), $userId]);
    }

    /** Forget that a migration ran, without executing its down section. */
    public function markPending(string $name): void
    {
        $this->pdo->prepare('DELETE FROM migrations WHERE migration = ?')->execute([$name]);
    }

    /** Create a new .sql migration file; returns its file name. */
    public function create(string $slug, string $description, string $up, string $down): string
    {
        $slug = strtolower(trim($slug));
        if (!preg_match('/^[a-z0-9_]{3,80}$/', $slug)) throw new RuntimeException('ชื่อ migration ใช้ได้เฉพาะ a-z 0-9 และ _ (3–80 ตัว)');
        if (trim(self::stripComments($up)) === '') throw new RuntimeException('ต้องมีคำสั่ง SQL ในส่วน up');
        if (!is_dir($this->dir) || !is_writable($this->dir)) throw new RuntimeException('โฟลเดอร์ migrations เขียนไม่ได้');
        $name = date('Y_m_d_His') . '_' . $slug . '.sql';
        $description = str_replace(["\r", "\n"], ' ', trim($description));
        $content = "-- @description {$description}\n-- @up\n" . rtrim($up) . "\n\n-- @down\n" . rtrim($down) . "\n";
        if (file_exists($this->dir . '/' . $name)) throw new RuntimeException('มีไฟล์ชื่อนี้อยู่แล้ว');
        file_put_contents($this->dir . '/' . $name, $content, LOCK_EX);
        return $name;
    }

    public function source(string $name): string
    {
        $files = $this->files();
        if (!isset($files[$name])) throw new RuntimeException('ไม่พบไฟล์ migration');
        return file_get_contents($files[$name]);
    }

    private function execute($step): void
    {
        if (is_callable($step)) {
            $step($this->pdo);
            return;
        }
        foreach (self::splitSql((string)$step) as $stmt) {
            $this->pdo->exec($stmt);
        }
    }

    private function nextBatch(): int
    {
        $this->ensureTable();
        return (int)$this->pdo->query('SELECT COALESCE(MAX(batch), 0) + 1 FROM migrations')->fetchColumn();
    }

    private function lock(): void
    {
        $this->ensureTable();
        $got = (int)$this->pdo->query("SELECT GET_LOCK('vecplan_migrate', 10)")->fetchColumn();
        if ($got !== 1) throw new RuntimeException('มีการรัน migration อื่นอยู่ กรุณาลองใหม่');
    }

    private function unlock(): void
    {
        $this->pdo->query("SELECT RELEASE_LOCK('vecplan_migrate')");
    }

    public static function stripComments(string $sql): string
    {
        return implode('', array_map(fn($s) => $s . ';', self::splitSql($sql)));
    }

    /**
     * Split a SQL script into statements on ";", ignoring semicolons inside quotes,
     * backticks and comments. Comments are removed from the output.
     */
    public static function splitSql(string $sql): array
    {
        $out = [];
        $buf = '';
        $len = strlen($sql);
        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];
            $n = $i + 1 < $len ? $sql[$i + 1] : '';
            if ($c === '-' && $n === '-' && ($i + 2 >= $len || ctype_space($sql[$i + 2]))) {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $len : $end;
                $buf .= "\n";
                continue;
            }
            if ($c === '#') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $len : $end;
                $buf .= "\n";
                continue;
            }
            if ($c === '/' && $n === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $len : $end + 1;
                $buf .= ' ';
                continue;
            }
            if ($c === "'" || $c === '"' || $c === '`') {
                $j = $i + 1;
                while ($j < $len) {
                    if ($sql[$j] === '\\' && $c !== '`') { $j += 2; continue; }
                    if ($sql[$j] === $c) {
                        if ($j + 1 < $len && $sql[$j + 1] === $c) { $j += 2; continue; }
                        break;
                    }
                    $j++;
                }
                $buf .= substr($sql, $i, $j - $i + 1);
                $i = $j;
                continue;
            }
            if ($c === ';') {
                if (trim($buf) !== '') $out[] = trim($buf);
                $buf = '';
                continue;
            }
            $buf .= $c;
        }
        if (trim($buf) !== '') $out[] = trim($buf);
        return $out;
    }
}
