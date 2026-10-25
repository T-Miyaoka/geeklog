<?php
/**
 * Happa GA4 article report (CLI only). Run daily at 06:00 by Windows Task Scheduler.
 *
 *   php run-ga4-report.php                  Writes data/latest.json and data/history/<report date>.json
 *   php run-ga4-report.php --check          Verifies config, key and property access; writes nothing under data/
 *   php run-ga4-report.php --date=YYYY-MM-DD Treats that date as "today" (the report ends the day before)
 *
 * Exit codes: 0 success, 1 failure (data/latest.json is left as it was; data/last-run.json records the failure).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once __DIR__ . '/ga4-report.inc.php';

use Happa\Ga4Report\Client;
use Happa\Ga4Report\ReportException;

define('HAPPA_GA4_ROOT', __DIR__);

function ga4Log($message)
{
    $dir = HAPPA_GA4_ROOT . '/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    $line = '[' . date('Y-m-d H:i:s') . '] ' . preg_replace('/[\r\n]+/', ' ', $message) . PHP_EOL;
    @file_put_contents($dir . '/ga4-' . date('Ymd') . '.log', $line, FILE_APPEND | LOCK_EX);
}

$check = false;
$today = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--check') {
        $check = true;
    } elseif (preg_match('/^--date=(\d{4}-\d{2}-\d{2})$/', $arg, $m) && strtotime($m[1]) !== false) {
        $today = $m[1];
    } else {
        fwrite(STDERR, "Usage: php run-ga4-report.php [--check] [--date=YYYY-MM-DD]\n");
        exit(2);
    }
}

$status = array('status' => 'failed', 'mode' => $check ? 'check' : 'report');
$exitCode = 1;
try {
    $config = Happa\Ga4Report\readConfig(HAPPA_GA4_ROOT);
    date_default_timezone_set($config['timezone']);
    if ($today === null) {
        $today = date('Y-m-d');
    }
    ga4Log('RUN_STARTED: mode=' . $status['mode'] . ' today=' . $today);
    $account = Happa\Ga4Report\readServiceAccount($config['key_file']);
    $client = new Client($account, $config['property_id'],
        Happa\Ga4Report\curlTransport($config['ca_bundle']), function () { return time(); });

    if ($check) {
        $ranges = Happa\Ga4Report\dateRanges($today);
        $request = Happa\Ga4Report\reportRequest($ranges['yesterday'], 0);
        $request['limit'] = 1;
        $client->runReport($request);
        ga4Log('CHECK_SUCCEEDED: token issued and runReport accepted for the configured property.');
        echo "OK: happa-ga4-reader can read the configured GA4 property.\n";
        exit(0);
    }

    $report = null;
    $delays = array(30, 90);
    for ($attempt = 1; ; $attempt++) {
        try {
            $report = Happa\Ga4Report\buildReport($client, $today, date('c'));
            break;
        } catch (ReportException $e) {
            if (!$e->transient || $attempt > count($delays)) {
                throw $e;
            }
            ga4Log('RETRY: attempt ' . $attempt . ' failed with ' . $e->getMessage() . '; retrying in ' . $delays[$attempt - 1] . 's.');
            sleep($delays[$attempt - 1]);
        }
    }

    Happa\Ga4Report\writeJsonAtomic(HAPPA_GA4_ROOT . '/data/history/' . $report['report_date'] . '.json', $report);
    Happa\Ga4Report\writeJsonAtomic(HAPPA_GA4_ROOT . '/data/latest.json', $report);
    $removed = Happa\Ga4Report\pruneHistory(HAPPA_GA4_ROOT . '/data/history', $today, $config['history_days']);
    $status = array('status' => 'ok', 'mode' => 'report', 'report_date' => $report['report_date'],
        'articles' => count($report['articles']), 'views_last_7_days' => $report['totals']['last_7_days']['views']);
    ga4Log('REPORT_WRITTEN: report_date=' . $report['report_date'] . ' articles=' . count($report['articles'])
        . ' history_removed=' . $removed);
    $exitCode = 0;
} catch (ReportException $e) {
    $status['error_code'] = $e->codeName;
    $status['error'] = $e->getMessage();
    ga4Log('ERROR: ' . $e->getMessage());
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
} catch (Throwable $e) {
    // The message can contain configuration details, so only the class and location are recorded.
    $status['error_code'] = 'UNEXPECTED';
    $status['error'] = get_class($e) . ' at ' . basename($e->getFile()) . ':' . $e->getLine();
    ga4Log('ERROR: UNEXPECTED ' . $status['error']);
    fwrite(STDERR, 'UNEXPECTED ' . $status['error'] . PHP_EOL);
}

if (!$check) {
    $status['finished_at'] = date('c');
    try {
        Happa\Ga4Report\writeJsonAtomic(HAPPA_GA4_ROOT . '/data/last-run.json', $status);
    } catch (Throwable $e) {
        ga4Log('ERROR: could not write data/last-run.json');
    }
}
exit($exitCode);
