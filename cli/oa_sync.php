<?php
declare(strict_types=1);

/**
 * Sync users from RVC-ONE (User-sync API). Run from cron, e.g. every 30 minutes:
 *   0,30 * * * * php /var/www/web/cli/oa_sync.php >> /var/www/web/storage/logs/oa-sync.log 2>&1
 *
 *   php cli/oa_sync.php          incremental: users changed since the last successful run
 *   php cli/oa_sync.php --full   everything again (since is reset by the run's server_time)
 *
 * Needs OA_CLIENT_SECRET in the environment or config/oa.local.php. Exit code 1 on failure.
 */
if (PHP_SAPI !== 'cli') exit("CLI only\n");

require dirname(__DIR__) . '/app/bootstrap.php';

$_SESSION = [];
$out = fn(string $m) => fwrite(STDOUT, '[' . date('c') . '] oa_sync ' . $m . "\n");
if (!is_installed()) { $out('error: system not installed'); exit(1); }
try {
    RvcOne::sync(in_array('--full', $argv, true), $out);
    exit(0);
} catch (Throwable $e) {
    $out('error: ' . $e->getMessage() . ' (since unchanged)');
    exit(1);
}
