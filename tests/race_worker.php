<?php
declare(strict_types=1);

// Helper for the concurrency test: allocate 60,000 from a fund pool to one project.
// Two of these run at the same time against a pool of 100,000; BR-22 must let only one through.
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/app/bootstrap.php';

[, $conf, $fyId, $fundId, $projectId, $userId] = $argv;
$_SESSION = ['uid' => (int)$userId];
db(make_pdo(json_decode($conf, true)));
$fy = fiscal_year((int)$fyId);
try {
    tx(function () use ($fyId, $fundId, $projectId, $userId, $fy) {
        $r = Ledger::post(['fiscal_year_id' => (int)$fyId, 'entry_type' => 'allocate', 'fund_source_id' => (int)$fundId,
            'project_id' => (int)$projectId, 'amount' => to_cents('60000'), 'entry_date' => $fy['starts_on']], (int)$userId);
        usleep(300000); // hold the row locks so the other worker has to wait
        return $r;
    });
    echo 'OK';
} catch (Throwable $e) {
    echo 'FAIL ' . $e->getMessage();
}
