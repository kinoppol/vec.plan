<?php
declare(strict_types=1);

/**
 * Import an approved plan from Excel/CSV (phase 1, spec §14):
 * columns รหัส, ชื่อ, หน่วยงาน, แหล่งเงิน, หมวด, ยอดอนุมัติ, ไตรมาส.
 * Rows sharing a code become one project with several budget lines.
 * Creates projects with status "approved" and one allocate entry per project per fund.
 */
class PlanImport
{
    public const HEADERS = ['รหัส', 'ชื่อ', 'หน่วยงาน', 'แหล่งเงิน', 'หมวด', 'ยอดอนุมัติ', 'ไตรมาส'];
    private const KEYS = ['code', 'title', 'unit', 'fund', 'category', 'amount', 'quarters'];

    public static function templateRows(int $fyId): array
    {
        $st = db()->prepare('SELECT code FROM fund_sources WHERE fiscal_year_id = ? AND is_leaf = 1 AND active = 1 ORDER BY sort, id LIMIT 1');
        $st->execute([$fyId]);
        $fund = $st->fetchColumn() ?: 'BUD-VC';
        return [
            self::HEADERS,
            ['', 'ตัวอย่าง: อบรมครูด้านการสอนแบบ Active Learning', 'งานพัฒนาหลักสูตรการเรียนการสอน', $fund, 'OPS-COMP', 80000, '1'],
            ['', 'ตัวอย่าง: โครงการที่ใช้หลายแหล่งเงิน (บรรทัดที่ 1)', 'DEP-AUTO', 'SUB-TEACH', 'ค่าวัสดุ', 120000, '2,3'],
        ];
    }

    /** Read an uploaded file into rows. */
    public static function readFile(string $path, string $originalName): array
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ext === 'xlsx') return Xlsx::read($path);
        if ($ext === 'csv' || $ext === 'txt') {
            $text = file_get_contents($path);
            $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
            if (!mb_check_encoding($text, 'UTF-8')) $text = mb_convert_encoding($text, 'UTF-8', 'TIS-620');
            $rows = [];
            $fh = fopen('php://memory', 'r+');
            fwrite($fh, $text);
            rewind($fh);
            while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) $rows[] = $r;
            fclose($fh);
            return $rows;
        }
        throw new ApiError('รองรับเฉพาะไฟล์ .xlsx หรือ .csv');
    }

    /** Validate rows; returns ['projects' => [...], 'errors' => [...], 'totals' => [...]]. */
    public static function analyze(int $fyId, array $rows): array
    {
        $pdo = db();
        $errors = [];
        // Locate header row (first row that contains "ชื่อ" and "ยอดอนุมัติ").
        $start = 0;
        $map = range(0, 6);
        foreach ($rows as $i => $r) {
            $norm = array_map(fn($c) => trim((string)$c), $r);
            if (in_array('ชื่อ', $norm, true) && in_array('ยอดอนุมัติ', $norm, true)) {
                foreach (self::HEADERS as $k => $h) {
                    $pos = array_search($h, $norm, true);
                    if ($pos === false) $errors[] = ['row' => $i + 1, 'message' => 'ไม่พบคอลัมน์ "' . $h . '"'];
                    else $map[$k] = $pos;
                }
                $start = $i + 1;
                break;
            }
        }
        if ($errors) return ['projects' => [], 'errors' => $errors, 'totals' => [], 'row_count' => 0];

        $units = $pdo->query('SELECT id, code, name FROM org_units WHERE active = 1')->fetchAll();
        $st = $pdo->prepare('SELECT f.id, f.code, f.name, p.name AS parent_name FROM fund_sources f LEFT JOIN fund_sources p ON p.id = f.parent_id
            WHERE f.fiscal_year_id = ? AND f.is_leaf = 1 AND f.active = 1');
        $st->execute([$fyId]);
        $funds = $st->fetchAll();
        $cats = $pdo->query('SELECT c.id, c.code, c.name FROM expense_categories c WHERE c.active = 1
            AND NOT EXISTS (SELECT 1 FROM expense_categories k WHERE k.parent_id = c.id)')->fetchAll();
        $allowed = [];
        foreach ($pdo->query('SELECT fund_source_id, expense_category_id FROM fund_source_allowed_categories') as $a) {
            $allowed[(int)$a['fund_source_id']][(int)$a['expense_category_id']] = true;
        }
        $existing = [];
        foreach ($pdo->query('SELECT code FROM projects') as $p) $existing[mb_strtolower($p['code'])] = true;

        $find = function (array $list, string $value, array $fields) {
            $v = mb_strtolower(trim($value));
            if ($v === '') return [null, 'ว่าง'];
            $hits = array_values(array_filter($list, function ($x) use ($v, $fields) {
                foreach ($fields as $f) if (isset($x[$f]) && mb_strtolower((string)$x[$f]) === $v) return true;
                return false;
            }));
            if (count($hits) === 1) return [$hits[0], null];
            return [null, count($hits) ? 'ซ้ำกันหลายรายการ ให้ใช้รหัสแทน' : 'ไม่พบในระบบ'];
        };

        $projects = [];
        $auto = 0;
        $rowCount = 0;
        for ($i = $start; $i < count($rows); $i++) {
            $raw = $rows[$i];
            $r = [];
            foreach (self::KEYS as $k => $key) $r[$key] = trim((string)($raw[$map[$k]] ?? ''));
            if (implode('', $r) === '') continue;
            $rowCount++;
            $line = $i + 1;
            $rowErr = function (string $m) use (&$errors, $line) { $errors[] = ['row' => $line, 'message' => $m]; };

            if ($r['title'] === '') $rowErr('ไม่มีชื่อโครงการ');
            [$unit, $e] = $find($units, $r['unit'], ['code', 'name']);
            if (!$unit) $rowErr('หน่วยงาน "' . $r['unit'] . '" ' . $e);
            $fundFields = ['code', 'name'];
            $fundList = array_map(fn($f) => $f + ['full' => ($f['parent_name'] ? $f['parent_name'] . ' > ' : '') . $f['name']], $funds);
            [$fund, $e] = $find($fundList, $r['fund'], array_merge($fundFields, ['full']));
            if (!$fund) $rowErr('แหล่งเงิน "' . $r['fund'] . '" ' . $e . ' (ต้องเป็นแหล่งเงินระดับย่อยสุด)');
            [$cat, $e] = $find($cats, $r['category'], ['code', 'name']);
            if (!$cat) $rowErr('หมวด "' . $r['category'] . '" ' . $e . ' (ต้องเป็นหมวดย่อย)');
            if ($fund && $cat && isset($allowed[(int)$fund['id']]) && !isset($allowed[(int)$fund['id']][(int)$cat['id']])) {
                $rowErr('หมวด "' . $cat['name'] . '" ไม่อยู่ในหมวดที่แหล่งเงิน "' . $fund['name'] . '" อนุญาต (BR-06)');
            }
            $amount = 0;
            try {
                $amount = to_cents($r['amount']);
            } catch (ApiError $ex) {
                $rowErr('ยอดอนุมัติไม่ใช่ตัวเลข');
            }
            if ($amount <= 0 && $r['amount'] !== '') $rowErr('ยอดอนุมัติต้องมากกว่า 0');
            if ($r['amount'] === '') $rowErr('ไม่มียอดอนุมัติ');
            $quarters = [];
            if ($r['quarters'] !== '') {
                foreach (preg_split('/[\s,;\/]+/', str_replace(['ไตรมาส', '–', '-'], ['', ',', ','], $r['quarters'])) as $q) {
                    if ($q === '') continue;
                    if (!preg_match('/^[1-4]$/', $q)) { $rowErr('ไตรมาสต้องเป็น 1–4 เช่น "2" หรือ "2,3"'); break; }
                    $quarters[(int)$q] = true;
                }
            }
            $code = $r['code'];
            if ($code !== '' && !preg_match('/^[A-Za-z0-9\-\/_.]{2,30}$/', $code)) $rowErr('รหัสโครงการใช้ได้เฉพาะ A-Z 0-9 - / _ .');
            if ($code !== '' && isset($existing[mb_strtolower($code)])) $rowErr('รหัส ' . $code . ' มีอยู่แล้วในระบบ');
            $key = $code !== '' ? 'code:' . mb_strtolower($code) : 'auto:' . (++$auto);

            if (!isset($projects[$key])) {
                $projects[$key] = ['code' => $code, 'title' => $r['title'], 'unit_id' => $unit['id'] ?? null, 'unit_name' => $unit['name'] ?? $r['unit'],
                    'quarters' => [], 'lines' => [], 'total' => 0, 'rows' => []];
            } else {
                if ($projects[$key]['title'] !== $r['title']) $rowErr('รหัส ' . $code . ' ใช้ชื่อโครงการไม่ตรงกับบรรทัดก่อนหน้า');
                if ($unit && (int)$projects[$key]['unit_id'] !== (int)$unit['id']) $rowErr('รหัส ' . $code . ' ใช้หน่วยงานไม่ตรงกับบรรทัดก่อนหน้า');
            }
            $projects[$key]['rows'][] = $line;
            $projects[$key]['quarters'] += $quarters;
            if ($fund && $cat && $amount > 0) {
                $projects[$key]['lines'][] = ['fund_id' => (int)$fund['id'], 'fund_name' => $fund['name'], 'fund_code' => $fund['code'],
                    'category_id' => (int)$cat['id'], 'category_name' => $cat['name'], 'amount' => $amount];
                $projects[$key]['total'] += $amount;
            }
        }

        $totals = [];
        foreach ($projects as $p) {
            foreach ($p['lines'] as $l) {
                $totals[$l['fund_id']] = $totals[$l['fund_id']] ?? ['fund_id' => $l['fund_id'], 'fund_name' => $l['fund_name'], 'fund_code' => $l['fund_code'], 'amount' => 0, 'projects' => 0];
                $totals[$l['fund_id']]['amount'] += $l['amount'];
            }
            foreach (array_unique(array_column($p['lines'], 'fund_id')) as $fid) $totals[$fid]['projects']++;
        }
        if (!$rowCount) $errors[] = ['row' => $start + 1, 'message' => 'ไม่พบข้อมูลโครงการในไฟล์'];

        // Pool check (BR-22) per fund, so the planner sees shortfalls before confirming.
        $pos = Ledger::fundPositions($fyId)['positions'];
        foreach ($totals as $fid => &$t) {
            $t['pool_actual'] = $pos[$fid]['pool_actual'] ?? 0;
            $t['short_by'] = max(0, $t['amount'] - $t['pool_actual']);
        }
        unset($t);

        $out = array_values(array_map(function ($p) {
            $p['quarters'] = array_keys($p['quarters']);
            sort($p['quarters']);
            return $p;
        }, $projects));
        return ['projects' => $out, 'errors' => $errors, 'totals' => array_values($totals), 'row_count' => $rowCount];
    }

    /** Create projects + allocations in one transaction. */
    public static function commit(int $fyId, array $analysis, int $userId, ?string $overrideReason): array
    {
        if ($analysis['errors']) fail('ไฟล์ยังมีข้อผิดพลาด แก้ไขแล้วอัปโหลดใหม่');
        $fy = fiscal_year($fyId);
        if ($fy['baseline_locked_at']) fail('แผนตั้งต้นของปีนี้ล็อกแล้ว แก้ยอดอนุมัติได้ผ่านคำขอปรับแผนเท่านั้น (BR-15)');
        $needsOverride = array_filter($analysis['totals'], fn($t) => $t['short_by'] > 0);
        if ($needsOverride && trim((string)$overrideReason) === '') {
            fail('ยอดจัดสรรเกินเงินในกอง (ชั้นจริง) ต้องระบุเหตุผลเพื่อยืนยันข้าม BR-22', 422, ['rule' => 'BR-22', 'overridable' => true]);
        }
        return tx(function (PDO $pdo) use ($fy, $fyId, $analysis, $userId, $overrideReason) {
            $insP = $pdo->prepare('INSERT INTO projects (fiscal_year_id, code, title, org_unit_id, created_by, requested_total, minimum_viable,
                planned_quarters, status, source, approved_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'approved\', \'import\', NOW())');
            $insL = $pdo->prepare('INSERT INTO budget_lines (project_id, expense_category_id, fund_source_id, item_name, quantity, unit, unit_price, amount)
                VALUES (?, ?, ?, ?, 1, NULL, ?, ?)');
            $created = [];
            foreach ($analysis['projects'] as $p) {
                $code = $p['code'] !== '' ? $p['code'] : next_number('P', (int)$fy['year_be'], 3);
                $insP->execute([$fyId, $code, $p['title'], $p['unit_id'], $userId, cents_str($p['total']), cents_str($p['total']),
                    $p['quarters'] ? implode(',', $p['quarters']) : null]);
                $pid = (int)$pdo->lastInsertId();
                $byFund = [];
                foreach ($p['lines'] as $l) {
                    $insL->execute([$pid, $l['category_id'], $l['fund_id'], 'นำเข้าจากแผนที่อนุมัติ (' . $l['category_name'] . ')', cents_str($l['amount']), cents_str($l['amount'])]);
                    $byFund[$l['fund_id']] = ($byFund[$l['fund_id']] ?? 0) + $l['amount'];
                }
                foreach ($byFund as $fid => $amt) {
                    Ledger::post(['fiscal_year_id' => $fyId, 'entry_type' => 'allocate', 'fund_source_id' => $fid, 'project_id' => $pid,
                        'amount' => $amt, 'entry_date' => max($fy['starts_on'], min(today(), $fy['ends_on'])),
                        'reference_no' => 'นำเข้าแผนที่อนุมัติ', 'source_type' => 'plan_import', 'note' => 'จัดสรรตามแผนที่อนุมัติ (นำเข้า)'],
                        $userId, $overrideReason ? ['reason' => $overrideReason] : null);
                }
                audit('project.import', 'project', $pid, null, ['code' => $code, 'title' => $p['title'], 'total' => cents_str($p['total'])]);
                $created[] = ['id' => $pid, 'code' => $code];
            }
            return $created;
        });
    }
}
