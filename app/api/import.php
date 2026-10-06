<?php
declare(strict_types=1);

return [
    'GET template' => function () {
        $fyId = request_fy();
        require_role('planner', $fyId);
        $fy = fiscal_year($fyId);
        $rows = PlanImport::templateRows($fyId);
        $format = ($_GET['format'] ?? 'xlsx') === 'csv' ? 'csv' : 'xlsx';
        if ($format === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="plan-import-' . $fy['year_be'] . '.csv"');
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            foreach ($rows as $r) fputcsv($out, $r, ',', '"', '');
            fclose($out);
            exit;
        }
        $bin = Xlsx::build($rows, 'แผนที่อนุมัติ');
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="plan-import-' . $fy['year_be'] . '.xlsx"');
        echo $bin;
        exit;
    },

    'GET status' => function () {
        $fyId = request_fy();
        require_role('planner', $fyId);
        $fy = fiscal_year($fyId);
        $st = db()->prepare("SELECT source, COUNT(*) n, SUM(requested_total) total FROM projects WHERE fiscal_year_id = ? AND status = 'approved' GROUP BY source");
        $st->execute([$fyId]);
        return ['baseline_locked_at' => $fy['baseline_locked_at'], 'approved' => $st->fetchAll()];
    },

    // Upload + validate; the analysis is kept in the session until the planner confirms.
    'POST analyze' => function () {
        $fyId = request_fy();
        require_role('planner', $fyId);
        $f = $_FILES['file'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) fail('อัปโหลดไฟล์ไม่สำเร็จ');
        if ($f['size'] > 20 * 1024 * 1024) fail('ไฟล์ต้องไม่เกิน 20 MB');
        try {
            $rows = PlanImport::readFile($f['tmp_name'], (string)$f['name']);
        } catch (RuntimeException $e) {
            fail($e->getMessage());
        }
        $analysis = PlanImport::analyze($fyId, $rows);
        $token = bin2hex(random_bytes(12));
        $_SESSION['plan_import'] = ['token' => $token, 'fy' => $fyId, 'analysis' => $analysis, 'file' => (string)$f['name']];
        $view = $analysis;
        foreach ($view['projects'] as &$p) {
            $p['total'] = cents_num($p['total']);
            foreach ($p['lines'] as &$l) $l['amount'] = cents_num($l['amount']);
        }
        unset($p, $l);
        foreach ($view['totals'] as &$t) {
            foreach (['amount', 'pool_actual', 'short_by'] as $k) $t[$k] = cents_num($t[$k]);
        }
        unset($t);
        return $view + ['token' => $token, 'file' => (string)$f['name'], 'grand_total' => cents_num(array_sum(array_map(fn($p) => $p['total'], $analysis['projects'])))];
    },

    'POST commit' => function () {
        $fyId = request_fy();
        $u = require_role('planner', $fyId);
        $b = body();
        $s = $_SESSION['plan_import'] ?? null;
        if (!$s || !hash_equals($s['token'], (string)($b['token'] ?? '')) || (int)$s['fy'] !== $fyId) fail('ข้อมูลตรวจสอบหมดอายุ กรุณาอัปโหลดไฟล์ใหม่');
        // Re-check pool balances at commit time (another user may have posted meanwhile).
        $fresh = Ledger::fundPositions($fyId)['positions'];
        foreach ($s['analysis']['totals'] as &$t) {
            $t['pool_actual'] = $fresh[$t['fund_id']]['pool_actual'] ?? 0;
            $t['short_by'] = max(0, $t['amount'] - $t['pool_actual']);
        }
        unset($t);
        $created = PlanImport::commit($fyId, $s['analysis'], (int)$u['id'], isset($b['override_reason']) ? (string)$b['override_reason'] : null);
        audit('plan.import', 'fiscal_year', $fyId, null, ['file' => $s['file'], 'projects' => count($created)]);
        unset($_SESSION['plan_import']);
        return ['ok' => true, 'created' => $created];
    },

    // BR-15: after locking, approved amounts change only through adjustment requests.
    'POST lock_baseline' => function () {
        $fyId = request_fy();
        require_role('planner', $fyId);
        $fy = fiscal_year($fyId);
        if ($fy['baseline_locked_at']) fail('แผนตั้งต้นล็อกแล้ว');
        db()->prepare('UPDATE fiscal_years SET baseline_locked_at = NOW() WHERE id = ? AND baseline_locked_at IS NULL')->execute([$fyId]);
        audit('baseline.lock', 'fiscal_year', $fyId);
        return ['ok' => true];
    },
];
