<?php
declare(strict_types=1);

/**
 * Plain-PHP database dump (no mysqldump needed) into storage/backups as .sql.gz
 *
 * Full backup: every table and row (DROP + CREATE, restores over the same database).
 * Fiscal-year backup: the structure of every table plus only the rows that belong to the chosen fiscal years of
 * one institution, together with the master data they reference (institution, units, categories, users, settings).
 * It has no DROP TABLE, so it can only be restored into an empty database — restoring it over the live database
 * stops at the first CREATE TABLE instead of wiping the other years.
 */
class Backup
{
    /**
     * @param int[]|null $fiscalYearIds null = full backup; otherwise fiscal years of a single institution
     */
    public static function create(PDO $pdo, string $label = 'manual', ?array $fiscalYearIds = null): array
    {
        if (!is_dir(BACKUP_DIR) && !mkdir(BACKUP_DIR, 0775, true)) throw new RuntimeException('สร้างโฟลเดอร์สำรองข้อมูลไม่ได้');
        $filter = $fiscalYearIds === null ? null : self::yearFilter($pdo, $fiscalYearIds);
        $label = preg_replace('/[^a-z0-9_-]/i', '', $filter ? $filter['label'] : $label) ?: 'manual';
        $file = 'backup_' . date('Ymd_His') . '_' . $label . '.sql.gz';
        $path = BACKUP_DIR . '/' . $file;
        $gz = gzopen($path, 'wb6');
        if (!$gz) throw new RuntimeException('เขียนไฟล์สำรองข้อมูลไม่ได้');

        $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
        $scope = $filter ? $filter['scope'] : 'ทั้งระบบ';
        gzwrite($gz, "-- vec.plan backup of `{$db}` at " . date('c') . "\n-- scope: {$scope}\n"
            . ($filter ? "-- restore into an EMPTY database only: existing tables are never dropped\n" : '')
            . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");
        $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
        foreach ($tables as [$table]) {
            $create = $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM)[1];
            gzwrite($gz, ($filter ? '' : "DROP TABLE IF EXISTS `{$table}`;\n") . "{$create};\n\n");
            // Generated columns cannot be inserted into.
            $cols = [];
            $all = [];
            foreach ($pdo->query('SHOW COLUMNS FROM `' . $table . '`') as $c) {
                $all[] = $c['Field'];
                if (stripos((string)$c['Extra'], 'GENERATED') === false && stripos((string)$c['Extra'], 'PERSISTENT') === false && stripos((string)$c['Extra'], 'STORED') === false && stripos((string)$c['Extra'], 'VIRTUAL') === false) {
                    $cols[] = $c['Field'];
                }
            }
            [$where, $params] = $filter ? self::where($table, $all, $filter) : ['1 = 1', []];
            $colList = implode(', ', array_map(fn($c) => "`{$c}`", $cols));
            $st = $pdo->prepare("SELECT {$colList} FROM `{$table}` WHERE {$where}");
            $st->execute($params);
            $rows = [];
            while ($row = $st->fetch(PDO::FETCH_NUM)) {
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
            gzwrite($gz, ($filter ? '' : "DROP TRIGGER IF EXISTS `{$name}`;\n") . "{$sql};\n\n");
        }
        gzwrite($gz, "SET FOREIGN_KEY_CHECKS = 1;\n");
        gzclose($gz);
        return ['file' => $file, 'size' => filesize($path), 'scope' => $scope];
    }

    /** Validate the chosen years (one institution) and describe them. */
    private static function yearFilter(PDO $pdo, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) throw new RuntimeException('กรุณาเลือกปีงบประมาณอย่างน้อย 1 ปี');
        $in = implode(',', $ids);
        $rows = $pdo->query("SELECT f.id, f.year_be, f.starts_on, f.ends_on, f.institution_id, i.code, i.name
            FROM fiscal_years f JOIN institutions i ON i.id = f.institution_id WHERE f.id IN ({$in}) ORDER BY f.year_be")->fetchAll();
        if (count($rows) !== count($ids)) throw new RuntimeException('ไม่พบปีงบประมาณที่เลือก');
        if (count(array_unique(array_column($rows, 'institution_id'))) > 1) throw new RuntimeException('สำรองได้ครั้งละหนึ่งสถานศึกษา');
        $years = array_map('intval', array_column($rows, 'year_be'));
        return [
            'fy' => $in,
            'inst' => (int)$rows[0]['institution_id'],
            'from' => min(array_column($rows, 'starts_on')),
            'to' => max(array_column($rows, 'ends_on')) . ' 23:59:59',
            'label' => 'fy' . implode('-', $years) . '_' . $rows[0]['code'],
            'scope' => 'ปีงบประมาณ ' . implode(', ', $years) . ' · ' . $rows[0]['name'],
        ];
    }

    /** Rows of $table that belong to the chosen years (and the master data they need). */
    private static function where(string $table, array $columns, array $f): array
    {
        $fy = $f['fy'];
        $inst = $f['inst'];
        $instUsers = 'SELECT id FROM users WHERE institution_id = ' . $inst . ' OR institution_id IS NULL';
        switch ($table) {
            case 'institutions': return ['id = ?', [$inst]];
            case 'fiscal_years': return ["id IN ({$fy})", []];
            // Central admins (no institution) come along so a multi-institution restore can still be administered.
            case 'users': return ['institution_id = ? OR institution_id IS NULL', [$inst]];
            case 'role_assignments': return ["user_id IN ({$instUsers}) AND (fiscal_year_id IS NULL OR fiscal_year_id IN ({$fy}))", []];
            // The current year may not be in the backup; without the key the newest restored year is used.
            case 'settings': return ["institution_id IN (0, ?) AND skey <> 'current_fiscal_year_id'", [$inst]];
            case 'fund_source_allowed_categories': return ["fund_source_id IN (SELECT id FROM fund_sources WHERE fiscal_year_id IN ({$fy}))", []];
            case 'alignment_items': return ["alignment_set_id IN (SELECT id FROM alignment_sets WHERE fiscal_year_id IN ({$fy}))", []];
            case 'approval_chain_steps': return ["approval_chain_id IN (SELECT id FROM approval_chains WHERE fiscal_year_id IN ({$fy}))", []];
            case 'project_owners':
            case 'budget_lines': return ["project_id IN (SELECT id FROM projects WHERE fiscal_year_id IN ({$fy}))", []];
            case 'attachments': return ["attachable_type = 'ledger_entry' AND attachable_id IN (SELECT id FROM ledger_entries WHERE fiscal_year_id IN ({$fy}))", []];
            case 'audit_logs': return ['institution_id = ? AND created_at BETWEEN ? AND ?', [$inst, $f['from'], $f['to']]];
            case 'login_attempts': return ['1 = 0', []];
            case 'migrations': return ['1 = 1', []];
        }
        // Everything else (including tables added by later migrations) by its scope column.
        if (in_array('fiscal_year_id', $columns, true)) return ["fiscal_year_id IN ({$fy})", []];
        if (in_array('institution_id', $columns, true)) return ['institution_id = ?', [$inst]];
        return ['1 = 1', []];
    }

    public static function list(): array
    {
        $out = [];
        foreach (glob(BACKUP_DIR . '/backup_*.sql.gz') ?: [] as $p) {
            $scope = 'ทั้งระบบ';
            if ($gz = @gzopen($p, 'rb')) {
                $head = (string)gzread($gz, 600);
                gzclose($gz);
                if (preg_match('/^-- scope: (.+)$/m', $head, $m)) $scope = trim($m[1]);
            }
            $out[] = ['file' => basename($p), 'size' => filesize($p), 'created_at' => date('Y-m-d H:i:s', filemtime($p)), 'scope' => $scope,
                'year_scoped' => $scope !== 'ทั้งระบบ'];
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
