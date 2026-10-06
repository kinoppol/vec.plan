<?php
declare(strict_types=1);

/** Plain-PHP database dump (no mysqldump needed) into storage/backups as .sql.gz */
class Backup
{
    public static function create(PDO $pdo, string $label = 'manual'): array
    {
        if (!is_dir(BACKUP_DIR) && !mkdir(BACKUP_DIR, 0775, true)) throw new RuntimeException('สร้างโฟลเดอร์สำรองข้อมูลไม่ได้');
        $label = preg_replace('/[^a-z0-9_-]/i', '', $label) ?: 'manual';
        $file = 'backup_' . date('Ymd_His') . '_' . $label . '.sql.gz';
        $path = BACKUP_DIR . '/' . $file;
        $gz = gzopen($path, 'wb6');
        if (!$gz) throw new RuntimeException('เขียนไฟล์สำรองข้อมูลไม่ได้');

        $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
        gzwrite($gz, "-- vec.plan backup of `{$db}` at " . date('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");
        $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
        foreach ($tables as [$table]) {
            $create = $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM)[1];
            gzwrite($gz, "DROP TABLE IF EXISTS `{$table}`;\n{$create};\n\n");
            // Generated columns cannot be inserted into.
            $cols = [];
            foreach ($pdo->query('SHOW COLUMNS FROM `' . $table . '`') as $c) {
                if (stripos((string)$c['Extra'], 'GENERATED') === false && stripos((string)$c['Extra'], 'PERSISTENT') === false && stripos((string)$c['Extra'], 'STORED') === false && stripos((string)$c['Extra'], 'VIRTUAL') === false) {
                    $cols[] = $c['Field'];
                }
            }
            $colList = implode(', ', array_map(fn($c) => "`{$c}`", $cols));
            $st = $pdo->query("SELECT {$colList} FROM `{$table}`", PDO::FETCH_NUM);
            $rows = [];
            foreach ($st as $row) {
                $rows[] = '(' . implode(', ', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $row)) . ')';
                if (count($rows) >= 200) {
                    gzwrite($gz, "INSERT INTO `{$table}` ({$colList}) VALUES\n" . implode(",\n", $rows) . ";\n");
                    $rows = [];
                }
            }
            if ($rows) gzwrite($gz, "INSERT INTO `{$table}` ({$colList}) VALUES\n" . implode(",\n", $rows) . ";\n");
            gzwrite($gz, "\n");
        }
        // Triggers last, so restoring data is not blocked by the append-only ledger triggers.
        foreach ($pdo->query('SHOW TRIGGERS')->fetchAll() as $t) {
            $name = $t['Trigger'];
            $stmt = $pdo->query('SHOW CREATE TRIGGER `' . $name . '`')->fetch(PDO::FETCH_ASSOC);
            $sql = $stmt['SQL Original Statement'] ?? '';
            $sql = preg_replace('/^CREATE\s+DEFINER=\S+\s+/i', 'CREATE ', $sql);
            gzwrite($gz, "DROP TRIGGER IF EXISTS `{$name}`;\n{$sql};\n\n");
        }
        gzwrite($gz, "SET FOREIGN_KEY_CHECKS = 1;\n");
        gzclose($gz);
        return ['file' => $file, 'size' => filesize($path)];
    }

    public static function list(): array
    {
        $out = [];
        foreach (glob(BACKUP_DIR . '/backup_*.sql.gz') ?: [] as $p) {
            $out[] = ['file' => basename($p), 'size' => filesize($p), 'created_at' => date('Y-m-d H:i:s', filemtime($p))];
        }
        usort($out, fn($a, $b) => strcmp($b['file'], $a['file']));
        return $out;
    }

    public static function path(string $file): string
    {
        if (!preg_match('/^backup_[0-9_]+_[a-z0-9_-]+\.sql\.gz$/i', $file)) throw new RuntimeException('ชื่อไฟล์ไม่ถูกต้อง');
        $p = BACKUP_DIR . '/' . $file;
        if (!is_file($p)) throw new RuntimeException('ไม่พบไฟล์สำรองข้อมูล');
        return $p;
    }
}
