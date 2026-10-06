<?php
declare(strict_types=1);

const ATTACH_MIME = [
    'application/pdf' => 'pdf',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
];
const ATTACH_MAX = 20 * 1024 * 1024;

// Types that may be posted by hand from the funds pages (allocations come from the plan import).
const MANUAL_TYPES = ['carry_in', 'receipt', 'allocation_adjust_up', 'allocation_adjust_down', 'reserve', 'return'];

function ledger_query(int $fyId, array $q, bool $paginate = true): array
{
    $where = ['e.fiscal_year_id = ?'];
    $params = [$fyId];
    if (!empty($q['fund'])) {
        $leaves = Ledger::leafIds((int)$q['fund'], $fyId);
        if (!$leaves) $leaves = [0];
        $where[] = 'fa.fund_source_id IN (' . implode(',', array_map('intval', $leaves)) . ')';
    }
    if (!empty($q['type'])) {
        $where[] = 'e.entry_type = ?';
        $params[] = (string)$q['type'];
    }
    if (!empty($q['project_id'])) {
        $where[] = '(fa.project_id = ? OR ta.project_id = ?)';
        $params[] = (int)$q['project_id'];
        $params[] = (int)$q['project_id'];
    }
    if (!empty($q['from'])) { $where[] = 'e.entry_date >= ?'; $params[] = (string)$q['from']; }
    if (!empty($q['to'])) { $where[] = 'e.entry_date <= ?'; $params[] = (string)$q['to']; }
    if (($s = trim((string)($q['q'] ?? ''))) !== '') {
        $where[] = '(e.entry_no LIKE ? OR e.reference_no LIKE ? OR e.note LIKE ? OR fp.code LIKE ? OR fp.title LIKE ? OR tp.code LIKE ? OR tp.title LIKE ? OR fa.reserve_name LIKE ? OR ta.reserve_name LIKE ?)';
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $s) . '%';
        array_push($params, $like, $like, $like, $like, $like, $like, $like, $like, $like);
    }
    $from = 'FROM ledger_entries e
        JOIN ledger_accounts fa ON fa.id = e.from_account_id
        JOIN ledger_accounts ta ON ta.id = e.to_account_id
        JOIN fund_sources f ON f.id = fa.fund_source_id
        LEFT JOIN projects fp ON fp.id = fa.project_id
        LEFT JOIN projects tp ON tp.id = ta.project_id
        LEFT JOIN expense_categories c ON c.id = e.expense_category_id
        LEFT JOIN users u ON u.id = e.created_by
        LEFT JOIN ledger_entries o ON o.id = e.reverses_entry_id
        LEFT JOIN ledger_entries r ON r.reverses_entry_id = e.id
        WHERE ' . implode(' AND ', $where);
    $pdo = db();
    $st = $pdo->prepare('SELECT COUNT(*), COALESCE(SUM(e.amount), 0) ' . $from);
    $st->execute($params);
    [$count] = $st->fetch(PDO::FETCH_NUM);
    $page = max(1, (int)($q['page'] ?? 1));
    $per = min(200, max(10, (int)($q['per'] ?? 50)));
    $limit = $paginate ? ' LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per) : '';
    $st = $pdo->prepare('SELECT e.*, fa.kind AS from_kind, ta.kind AS to_kind, fa.reserve_name AS from_reserve, ta.reserve_name AS to_reserve,
            fa.fund_source_id, f.code AS fund_code, f.name AS fund_name, f.color_token AS fund_color,
            fp.id AS from_project_id, fp.code AS from_project_code, fp.title AS from_project_title,
            tp.id AS to_project_id, tp.code AS to_project_code, tp.title AS to_project_title,
            c.name AS category_name, u.name AS created_by_name,
            o.entry_no AS reverses_no, o.entry_type AS reverses_type, r.id AS reversed_by_id, r.entry_no AS reversed_by_no,
            (SELECT COUNT(*) FROM attachments a WHERE a.attachable_type = \'ledger_entry\' AND a.attachable_id = e.id) AS attachment_count
        ' . $from . ' ORDER BY e.entry_date DESC, e.id DESC' . $limit);
    $st->execute($params);
    $rows = array_map('ledger_row', $st->fetchAll());
    return ['rows' => $rows, 'total' => (int)$count, 'page' => $page, 'per' => $per];
}


function account_label(string $kind, array $r, string $side): string
{
    switch ($kind) {
        case 'fund_pool': return 'กอง' . $r['fund_name'];
        case 'project': return (string)$r[$side . '_project_code'];
        case 'reserve': return 'กันไว้: ' . $r[$side . '_reserve'];
        case 'carry_forward': return $side === 'from' ? 'ยอดยกมา' : 'ยกไปปีถัดไป';
        default: return $r['entry_type'] === 'receipt' || $r['entry_type'] === 'allocation_adjust_up' ? 'ภายนอก' : 'ภายนอก';
    }
}

function ledger_row(array $r): array
{
    $amount = to_cents($r['amount']);
    // Sign from the fund pool's point of view: money into the pool is +, out of it is −.
    $effectiveType = $r['entry_type'] === 'reversal' ? $r['reverses_type'] : $r['entry_type'];
    $sign = 0;
    if ($r['to_kind'] === 'fund_pool') $sign = 1;
    elseif ($r['from_kind'] === 'fund_pool') $sign = -1;
    elseif ($r['from_kind'] === 'project' && $r['to_kind'] === 'external') $sign = -1;
    elseif ($r['from_kind'] === 'external' && $r['to_kind'] === 'project') $sign = 1;
    return [
        'id' => (int)$r['id'], 'entry_no' => $r['entry_no'], 'entry_date' => $r['entry_date'], 'posted_at' => $r['posted_at'],
        'entry_type' => $r['entry_type'], 'type_label' => Ledger::TYPE_LABELS[$r['entry_type']] ?? $r['entry_type'],
        'effective_type' => $effectiveType,
        'from' => account_label($r['from_kind'], $r, 'from'), 'to' => account_label($r['to_kind'], $r, 'to'),
        'from_kind' => $r['from_kind'], 'to_kind' => $r['to_kind'],
        'project_id' => (int)($r['to_project_id'] ?: $r['from_project_id']) ?: null,
        'project_title' => $r['to_project_title'] ?: $r['from_project_title'],
        'fund_source_id' => (int)$r['fund_source_id'], 'fund_code' => $r['fund_code'], 'fund_name' => $r['fund_name'], 'fund_color' => $r['fund_color'],
        'category_name' => $r['category_name'],
        'amount' => cents_num($amount), 'signed' => cents_num($sign * $amount),
        'reference_no' => $r['reference_no'], 'reference_date' => $r['reference_date'], 'installment_no' => $r['installment_no'],
        'note' => $r['note'], 'override_reason' => $r['override_reason'], 'created_by_name' => $r['created_by_name'],
        'reverses_entry_id' => $r['reverses_entry_id'] ? (int)$r['reverses_entry_id'] : null, 'reverses_no' => $r['reverses_no'],
        'reversed_by_id' => $r['reversed_by_id'] ? (int)$r['reversed_by_id'] : null, 'reversed_by_no' => $r['reversed_by_no'],
        'attachment_count' => (int)$r['attachment_count'],
    ];
}

function permission_key_for_entry(array $e): string
{
    return Access::postKey($e, $e['from_kind'] ?? null);
}

return [
    'GET index' => function () {
        $fyId = request_fy();
        require_role(Access::LEDGER_VIEWERS, $fyId);
        $out = ledger_query($fyId, $_GET);
        foreach ($out['rows'] as &$r) {
            $r['can_reverse'] = $r['entry_type'] !== 'reversal' && !$r['reversed_by_id']
                && Access::canPost(permission_key_for_entry(['entry_type' => $r['entry_type'], 'from_kind' => $r['from_kind']]), $fyId);
        }
        unset($r);
        return $out;
    },

    'GET entry' => function () {
        $fyId = request_fy();
        require_role(Access::LEDGER_VIEWERS, $fyId);
        $id = (int)($_GET['id'] ?? 0);
        $st = db()->prepare('SELECT e.entry_no FROM ledger_entries e WHERE e.id = ? AND e.fiscal_year_id = ?');
        $st->execute([$id, $fyId]);
        $no = $st->fetchColumn();
        if (!$no) fail('ไม่พบรายการ', 404);
        $row = ledger_query($fyId, ['q' => $no], true)['rows'][0] ?? null;
        $st = db()->prepare("SELECT a.id, a.kind, a.original_name, a.mime, a.size_bytes, a.created_at, u.name AS uploaded_by_name
            FROM attachments a LEFT JOIN users u ON u.id = a.uploaded_by WHERE a.attachable_type = 'ledger_entry' AND a.attachable_id = ? ORDER BY a.id");
        $st->execute([$id]);
        $row['attachments'] = $st->fetchAll();
        return $row;
    },

    'GET export' => function () {
        $fyId = request_fy();
        require_role(Access::LEDGER_VIEWERS, $fyId);
        $fy = fiscal_year($fyId);
        $rows = ledger_query($fyId, $_GET, false)['rows'];
        $out = [['เลขที่รายการ', 'วันที่', 'ประเภท', 'จาก', 'ไป', 'แหล่งเงิน', 'หมวด', 'จำนวน (บาท)', 'เอกสารอ้างอิง', 'วันที่เอกสาร', 'หมายเหตุ', 'ผู้บันทึก', 'กลับรายการโดย', 'กลับรายการของ']];
        foreach ($rows as $r) {
            $out[] = [$r['entry_no'], th_date($r['entry_date']), $r['type_label'], $r['from'], $r['to'], $r['fund_name'], (string)$r['category_name'],
                $r['signed'] ?: $r['amount'], (string)$r['reference_no'], th_date($r['reference_date']), (string)$r['note'], (string)$r['created_by_name'],
                (string)$r['reversed_by_no'], (string)$r['reverses_no']];
        }
        $bin = Xlsx::build($out, 'สมุดบัญชี');
        audit('ledger.export', 'fiscal_year', $fyId, null, ['rows' => count($rows)]);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="ledger-' . $fy['year_be'] . '-' . date('Ymd') . '.xlsx"');
        header('Content-Length: ' . strlen($bin));
        echo $bin;
        exit;
    },

    'POST post' => function () {
        $fyId = request_fy();
        $u = require_login();
        $b = body();
        $type = (string)($b['entry_type'] ?? '');
        if (!in_array($type, MANUAL_TYPES, true)) fail('ประเภทรายการนี้บันทึกจากหน้านี้ไม่ได้');
        if ($type === 'return' && trim((string)($b['from_reserve'] ?? '')) === '') fail('การปล่อยเงินกันต้องระบุรายการกันเงิน');
        $key = $type === 'return' ? 'return_reserve' : $type;
        if (!Access::canPost($key, $fyId)) fail('ไม่มีสิทธิ์บันทึกรายการประเภทนี้', 403);

        $fundId = (int)($b['fund_source_id'] ?? 0);
        $before = Ledger::fundPositions($fyId)['positions'][$fundId] ?? null;
        $entry = Ledger::post([
            'fiscal_year_id' => $fyId, 'entry_type' => $type, 'fund_source_id' => $fundId,
            'amount' => to_cents($b['amount'] ?? 0), 'entry_date' => $b['entry_date'] ?? today(),
            'reference_no' => $b['reference_no'] ?? null, 'reference_date' => $b['reference_date'] ?? null,
            'installment_no' => $b['installment_no'] ?? null, 'reserve_name' => $b['reserve_name'] ?? null,
            'from_reserve' => $b['from_reserve'] ?? null, 'note' => $b['note'] ?? null, 'source_type' => 'manual',
        ], (int)$u['id'], !empty($b['override_reason']) ? ['reason' => (string)$b['override_reason']] : null);
        $after = Ledger::fundPositions($fyId)['positions'][$fundId] ?? null;
        $pick = fn($p) => $p ? ['pool_actual' => cents_num($p['pool_actual']), 'receipts' => cents_num($p['receipts']),
            'estimate' => cents_num($p['estimate']), 'estimate_to_date' => cents_num($p['estimate_to_date']), 'reserved' => cents_num($p['reserved'])] : null;
        return ['ok' => true, 'entry' => $entry, 'before' => $pick($before), 'after' => $pick($after)];
    },

    'POST reverse' => function () {
        $fyId = request_fy();
        $u = require_login();
        $b = body();
        $id = (int)($b['id'] ?? 0);
        $st = db()->prepare('SELECT e.entry_type, fa.kind AS from_kind, e.fiscal_year_id FROM ledger_entries e
            JOIN ledger_accounts fa ON fa.id = e.from_account_id WHERE e.id = ?');
        $st->execute([$id]);
        $e = $st->fetch();
        if (!$e || (int)$e['fiscal_year_id'] !== $fyId) fail('ไม่พบรายการ', 404);
        if (!Access::canPost(permission_key_for_entry($e), $fyId)) fail('ไม่มีสิทธิ์กลับรายการประเภทนี้', 403);
        $entry = Ledger::reverse($id, (string)($b['reason'] ?? ''), (int)$u['id'], $b['entry_date'] ?? null);
        return ['ok' => true, 'entry' => $entry];
    },

    'POST attach' => function () {
        $fyId = request_fy();
        $u = require_login();
        $id = (int)($_POST['entry_id'] ?? 0);
        $st = db()->prepare('SELECT e.*, fa.kind AS from_kind FROM ledger_entries e JOIN ledger_accounts fa ON fa.id = e.from_account_id WHERE e.id = ? AND e.fiscal_year_id = ?');
        $st->execute([$id, $fyId]);
        $e = $st->fetch();
        if (!$e) fail('ไม่พบรายการ', 404);
        $origType = $e['entry_type'];
        if ($origType === 'reversal') {
            $o = db()->prepare('SELECT e.entry_type, fa.kind AS from_kind FROM ledger_entries e JOIN ledger_accounts fa ON fa.id = e.from_account_id WHERE e.id = ?');
            $o->execute([$e['reverses_entry_id']]);
            $e = $o->fetch() + ['id' => $id];
        }
        if (!Access::canPost(permission_key_for_entry($e), $fyId)) fail('ไม่มีสิทธิ์แนบไฟล์ให้รายการนี้', 403);
        $f = $_FILES['file'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) fail('อัปโหลดไฟล์ไม่สำเร็จ' . ($f && $f['error'] === UPLOAD_ERR_INI_SIZE ? ' (ไฟล์ใหญ่เกินค่าที่เซิร์ฟเวอร์กำหนด)' : ''));
        if ($f['size'] > ATTACH_MAX) fail('ไฟล์ต้องไม่เกิน 20 MB');
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        if (!isset(ATTACH_MIME[$mime])) fail('รองรับเฉพาะไฟล์ PDF, DOCX, XLSX, JPG และ PNG');
        $fy = fiscal_year($fyId);
        $dir = UPLOAD_DIR . '/' . $fy['year_be'] . '/ledger';
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) fail('สร้างโฟลเดอร์เก็บไฟล์ไม่ได้', 500);
        $name = bin2hex(random_bytes(16)) . '.' . ATTACH_MIME[$mime];
        if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) fail('บันทึกไฟล์ไม่ได้', 500);
        $kind = in_array($_POST['kind'] ?? '', ['receipt', 'letter', 'other'], true) ? $_POST['kind'] : 'other';
        db()->prepare('INSERT INTO attachments (attachable_type, attachable_id, kind, original_name, path, mime, size_bytes, uploaded_by)
            VALUES (\'ledger_entry\', ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$id, $kind, mb_substr(basename((string)$f['name']), 0, 255), $fy['year_be'] . '/ledger/' . $name, $mime, $f['size'], $u['id']]);
        $aid = (int)db()->lastInsertId();
        audit('attachment.upload', 'ledger_entry', $id, null, ['attachment_id' => $aid, 'name' => $f['name']]);
        return ['ok' => true, 'id' => $aid];
    },

    'GET attachment' => function () {
        $fyId = request_fy();
        require_role(Access::LEDGER_VIEWERS, $fyId);
        $st = db()->prepare("SELECT a.* FROM attachments a JOIN ledger_entries e ON e.id = a.attachable_id
            WHERE a.id = ? AND a.attachable_type = 'ledger_entry' AND e.fiscal_year_id = ?");
        $st->execute([(int)($_GET['id'] ?? 0), $fyId]);
        $a = $st->fetch();
        if (!$a) fail('ไม่พบไฟล์', 404);
        $path = UPLOAD_DIR . '/' . $a['path'];
        if (!is_file($path) || str_contains($a['path'], '..')) fail('ไม่พบไฟล์บนเซิร์ฟเวอร์', 404);
        header('Content-Type: ' . $a['mime']);
        header('Content-Length: ' . filesize($path));
        header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($a['original_name']));
        readfile($path);
        exit;
    },
];
