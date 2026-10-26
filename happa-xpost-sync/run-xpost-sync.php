<?php
/**
 * Happa X-Post reply sync (CLI only). Run every 8 hours by Windows Task Scheduler on the web server.
 *
 * Fetches new replies to recent blog announcements into the private data\xpost\feedback.json,
 * exactly as `xpost/feedback-cli.php --sync` does, and retries transient network failures.
 * It never posts to X, never drafts or sends replies, and never reads credentials from arguments.
 *
 *   php run-xpost-sync.php            Sync once (with retries)
 *   php run-xpost-sync.php --status   Print budget and pending count only (no X request)
 *
 * Exit codes: 0 success, 1 failure (details in logs\ and last-run.json).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

define('HAPPA_XPOST_SYNC_ROOT', __DIR__);
// HAPPA_XPOST_SYNC_SITE_ROOT is only for tests; the server task does not set it.
define('HAPPA_XPOST_SITE_ROOT', getenv('HAPPA_XPOST_SYNC_SITE_ROOT') ?: 'C:/inetpub/wwwroot/geeklog/2_2_2');

require_once __DIR__ . '/xpost-sync.inc.php';
require_once HAPPA_XPOST_SITE_ROOT . '/plugins/xpost/classes/Core.php';

use Happa\XPost\Store;
use Happa\XPost\Client;
use Happa\XPost\Feedback;
use Happa\XPost\Budget;

function syncLog($message)
{
    $dir = HAPPA_XPOST_SYNC_ROOT . '/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    $line = '[' . date('Y-m-d H:i:s') . '] ' . preg_replace('/[\r\n]+/', ' ', $message) . PHP_EOL;
    @file_put_contents($dir . '/xpost-sync-' . date('Ymd') . '.log', $line, FILE_APPEND | LOCK_EX);
}

function writeLastRun(array $data)
{
    $path = HAPPA_XPOST_SYNC_ROOT . '/last-run.json';
    $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n") !== false) {
        if (is_file($path)) {
            @unlink($path);
        }
        @rename($tmp, $path);
    }
    @unlink($tmp);
}

date_default_timezone_set('Asia/Tokyo');
$args = array_slice($argv, 1);
if ($args && $args !== array('--status')) {
    fwrite(STDERR, "Usage: php run-xpost-sync.php [--status]\n");
    exit(2);
}

$exitCode = 1;
$status = array('status' => 'failed', 'started_at' => date('c'));
try {
    $directory = HAPPA_XPOST_SITE_ROOT . '/data/xpost';
    if (!is_dir($directory)) {
        throw new RuntimeException('DATA_MISSING: ' . 'data/xpost was not found under the site root.');
    }
    $store = new Store($directory);
    $feedback = new Feedback($store);
    if ($args === array('--status')) {
        echo json_encode(array('budget' => Budget::state($store), 'pending' => count($feedback->pending())),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
        exit(0);
    }
    syncLog('SYNC_STARTED');
    list($result, $attempts) = Happa\XPostSync\runWithRetry(function () use ($store, $feedback) {
        // A fresh client per attempt; the plugin keeps page cursors so a retry continues where it stopped.
        return $feedback->sync(new Client($store->configuration(), null, $store), $store->configuration());
    }, array(60, 180), function ($seconds) { sleep($seconds); }, 'syncLog');
    $status = array('status' => 'ok', 'started_at' => $status['started_at'], 'attempts' => $attempts,
        'result' => $result, 'pending' => count($feedback->pending()));
    syncLog('SYNC_FINISHED: attempts=' . $attempts . ' result=' . json_encode($result, JSON_UNESCAPED_UNICODE)
        . ' pending=' . $status['pending']);
    $exitCode = 0;
} catch (Throwable $e) {
    // Plugin messages are fixed texts without credentials; anything else is reduced to its class.
    $status['error'] = $e instanceof RuntimeException ? $e->getMessage() : get_class($e);
    syncLog('ERROR: ' . $status['error']);
    fwrite(STDERR, $status['error'] . PHP_EOL);
}
$status['finished_at'] = date('c');
writeLastRun($status);
exit($exitCode);
