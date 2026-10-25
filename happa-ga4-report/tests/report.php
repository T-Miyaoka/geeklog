<?php
// Run from the repository root: php -n happa-ga4-report/tests/report.php
// No network access: the HTTP transport is a stand-in that checks the requests it receives.
require_once __DIR__ . '/../ga4-report.inc.php';

use Happa\Ga4Report as G;

$passed = 0;
function check($condition, $label)
{
    global $passed;
    if (!$condition) {
        fwrite(STDERR, "FAIL: $label\n");
        exit(1);
    }
    $passed++;
    echo "PASS: $label\n";
}

$fixture = sys_get_temp_dir() . '/happa-ga4-test-' . bin2hex(random_bytes(4));
mkdir($fixture . '/credentials', 0750, true);

// --- Key and assertion -------------------------------------------------------
$pkey = openssl_pkey_new(array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA));
openssl_pkey_export($pkey, $pem);
$public = openssl_pkey_get_details($pkey)['key'];
$keyFile = $fixture . '/credentials/happa-ga4-reader.json';
file_put_contents($keyFile, json_encode(array('type' => 'service_account', 'client_email' => 'happa-ga4-reader@example.iam.gserviceaccount.com',
    'private_key' => $pem, 'token_uri' => 'https://oauth2.googleapis.com/token')));
$account = G\readServiceAccount($keyFile);
$jwt = G\buildAssertion($account, 1700000000);
list($h, $c, $s) = explode('.', $jwt);
$claims = json_decode(base64_decode(strtr($c, '-_', '+/')), true);
check(openssl_verify($h . '.' . $c, base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)), $public, OPENSSL_ALGO_SHA256) === 1,
    'assertion is RS256-signed with the service account key');
check($claims['scope'] === 'https://www.googleapis.com/auth/analytics.readonly' && $claims['exp'] - $claims['iat'] === 3600
    && $claims['iss'] === 'happa-ga4-reader@example.iam.gserviceaccount.com', 'assertion asks only for read-only Analytics scope for one hour');

file_put_contents($fixture . '/bad.json', json_encode(array('type' => 'authorized_user', 'client_email' => 'x', 'private_key' => 'y')));
try { G\readServiceAccount($fixture . '/bad.json'); $ok = false; } catch (G\ReportException $e) { $ok = $e->codeName === 'KEY_INVALID'; }
check($ok, 'non-service-account key files are rejected');

// --- Config ------------------------------------------------------------------
try { G\readConfig($fixture); $ok = false; } catch (G\ReportException $e) { $ok = $e->codeName === 'CONFIG_MISSING'; }
check($ok, 'missing config is reported as CONFIG_MISSING');
file_put_contents($fixture . '/credentials/config.ini', "property_id=G-ABC123\n");
try { G\readConfig($fixture); $ok = false; } catch (G\ReportException $e) { $ok = $e->codeName === 'CONFIG_INVALID'; }
check($ok, 'a G- measurement ID is rejected in place of the numeric property ID');
file_put_contents($fixture . '/credentials/config.ini', "property_id=123456789\npassword=x\n");
try { G\readConfig($fixture); $ok = false; } catch (G\ReportException $e) { $ok = $e->codeName === 'CONFIG_INVALID'; }
check($ok, 'unknown config keys are rejected');
file_put_contents($fixture . '/credentials/config.ini', "\xEF\xBB\xBFproperty_id=123456789\n");
$config = G\readConfig($fixture);
check($config['key_file'] === $fixture . '/credentials/happa-ga4-reader.json' && $config['timezone'] === 'Asia/Tokyo' && $config['history_days'] === 90,
    'defaults: key file beside config, Asia/Tokyo, 90 days of history (BOM tolerated)');

// --- Paths -------------------------------------------------------------------
check(G\storyIdFromPath('/article.php?story=devblog-059-calendar-weather-preview_ja') === 'devblog-059-calendar-weather-preview_ja', 'story= query is parsed');
check(G\storyIdFromPath('/article.php/devblog-001_en?fbclid=1') === 'devblog-001_en', 'path-style article URL is parsed');
check(G\storyIdFromPath('/article.php?mode=print&story=a_ja&x=1') === 'a_ja', 'story parameter is found among others');
check(G\storyIdFromPath('/index.php') === null && G\storyIdFromPath('/article.php?story=%3Cscript%3E') === null, 'non-article and malformed IDs are ignored');
$r = G\dateRanges('2026-10-26');
check($r['yesterday'] === array('start' => '2026-10-25', 'end' => '2026-10-25') && $r['last_7_days']['start'] === '2026-10-19'
    && $r['prev_7_days'] === array('start' => '2026-10-12', 'end' => '2026-10-18') && $r['last_28_days']['start'] === '2026-09-28',
    'date ranges end on the last complete day');

// --- Report against a stand-in API --------------------------------------------
$requests = array();
$data = array(
    '2026-10-25' => array(array('/article.php?story=devblog-059_ja', 'DEVELOPMENT SNAPSHOT #59', 10, 8, 640)),
    '2026-10-19' => array(array('/article.php?story=devblog-059_ja', 'DEVELOPMENT SNAPSHOT #59', 40, 30, 2400),
        array('/article.php?story=devblog-059_ja&fbclid=z', 'DEVELOPMENT SNAPSHOT #59', 5, 5, 100),
        array('/article.php?story=devblog-058_en', 'Snapshot 58', 12, 10, 300),
        array('/index.php', 'Home', 999, 900, 1)),
    '2026-10-12' => array(array('/article.php?story=devblog-058_en', 'Snapshot 58', 30, 20, 600)),
    '2026-09-28' => array(array('/article.php?story=devblog-058_en', 'Snapshot 58', 60, 40, 1200),
        array('/article.php?story=devblog-059_ja', 'DEVELOPMENT SNAPSHOT #59', 45, 35, 2500)),
);
$tokenRequests = 0;
$transport = function ($method, $url, $headers, $body) use (&$requests, &$tokenRequests, $data) {
    if ($url === 'https://oauth2.googleapis.com/token') {
        $tokenRequests++;
        parse_str($body, $form);
        if ($form['grant_type'] !== 'urn:ietf:params:oauth:grant-type:jwt-bearer' || substr_count($form['assertion'], '.') !== 2) {
            return array(400, '{"error":"invalid_grant"}');
        }
        return array(200, '{"access_token":"test-token","expires_in":3599}');
    }
    if ($url !== 'https://analyticsdata.googleapis.com/v1beta/properties/123456789:runReport' || !in_array('Authorization: Bearer test-token', $headers, true)) {
        return array(401, '{"error":{"status":"UNAUTHENTICATED","message":"bad"}}');
    }
    $req = json_decode($body, true);
    $requests[] = $req;
    $rows = array();
    foreach ($data[$req['dateRanges'][0]['startDate']] as $row) {
        $rows[] = array('dimensionValues' => array(array('value' => $row[0]), array('value' => $row[1])),
            'metricValues' => array(array('value' => (string) $row[2]), array('value' => (string) $row[3]), array('value' => (string) $row[4])));
    }
    return array(200, json_encode(array('rows' => $rows, 'rowCount' => count($rows))));
};
$client = new G\Client($account, '123456789', $transport, function () { return 1700000000; });
$report = G\buildReport($client, '2026-10-26', '2026-10-26T06:00:05+09:00');
check(count($requests) === 4 && $tokenRequests === 1, 'one token, one runReport per date range');
check($requests[0]['metrics'][2]['name'] === 'userEngagementDuration' && $requests[0]['dimensions'][0]['name'] === 'pagePathPlusQueryString',
    'requests views, active users and engagement time by page path with query');
check(count($report['articles']) === 2 && $report['articles'][0]['sid'] === 'devblog-059_ja', 'non-article pages are excluded; sorted by 7-day views');
$a = $report['articles'][0];
check($a['last_7_days']['views'] === 45 && $a['last_7_days']['active_users'] === 35 && $a['last_7_days']['avg_engagement_seconds'] === 71.4,
    'URL variants of one article are combined');
check($a['yesterday']['avg_engagement_seconds'] === 80.0 && $a['prev_7_days']['views'] === 0 && $a['views_change_7_days'] === 45 && $a['language'] === 'ja',
    'per-range metrics, 7-day change and language are filled');
check($report['articles'][1]['views_change_7_days'] === -18 && $report['articles'][1]['title'] === 'Snapshot 58'
    && $report['articles'][1]['url'] === 'https://www.happa.bz/article.php?story=devblog-058_en', 'falling article, title and URL');
check($report['report_date'] === '2026-10-25' && $report['totals']['last_7_days']['views'] === 57, 'report date and totals');

// --- Errors ------------------------------------------------------------------
$deny = new G\Client($account, '123456789', function ($m, $u) {
    return strpos($u, 'oauth2') !== false ? array(200, '{"access_token":"t"}') : array(403, '{"error":{"status":"PERMISSION_DENIED","message":"User does not have sufficient permissions"}}');
}, function () { return 1700000000; });
try { G\buildReport($deny, '2026-10-26', 'x'); $ok = false; } catch (G\ReportException $e) { $ok = $e->codeName === 'PERMISSION_DENIED' && !$e->transient && strpos($e->getMessage(), 'Viewer') !== false; }
check($ok, '403 explains that the service account needs Viewer access, and is not retried');
$busy = new G\Client($account, '123456789', function ($m, $u) {
    return strpos($u, 'oauth2') !== false ? array(200, '{"access_token":"t"}') : array(503, '{"error":{"status":"UNAVAILABLE","message":"try later"}}');
}, function () { return 1700000000; });
try { G\buildReport($busy, '2026-10-26', 'x'); $ok = false; } catch (G\ReportException $e) { $ok = $e->transient; }
check($ok, '5xx is marked as transient for retry');
$auth = new G\Client($account, '123456789', function () { return array(400, '{"error":"invalid_grant","error_description":"Invalid JWT Signature."}'); }, function () { return 1700000000; });
try { G\buildReport($auth, '2026-10-26', 'x'); $ok = false; } catch (G\ReportException $e) { $ok = $e->codeName === 'AUTH_FAILED' && strpos($e->getMessage(), 'test-token') === false; }
check($ok, 'rejected token request is reported as AUTH_FAILED');

// --- Output ------------------------------------------------------------------
$out = $fixture . '/data';
G\writeJsonAtomic($out . '/latest.json', $report);
G\writeJsonAtomic($out . '/latest.json', $report);
$read = json_decode(file_get_contents($out . '/latest.json'), true);
check($read['articles'][0]['title'] === 'DEVELOPMENT SNAPSHOT #59' && count(glob($out . '/*.tmp-*')) === 0, 'latest.json is replaced atomically with UTF-8 text');
mkdir($out . '/history');
foreach (array('2026-07-01', '2026-07-27', '2026-07-28', '2026-10-25', 'notes') as $n) {
    file_put_contents($out . '/history/' . $n . '.json', '{}');
}
check(G\pruneHistory($out . '/history', '2026-10-26', 90) === 2 && is_file($out . '/history/2026-07-28.json') && is_file($out . '/history/notes.json'),
    'history keeps 90 days and only removes dated files');

// --- CLI ---------------------------------------------------------------------
$cli = array(PHP_BINARY, '-n', __DIR__ . '/../run-ga4-report.php', '--date=bad');
$p = proc_open($cli, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
check(proc_close($p) === 2, 'CLI rejects unknown arguments');

array_map('unlink', array_merge(glob($out . '/history/*'), glob($out . '/*.json'), glob($fixture . '/credentials/*'), glob($fixture . '/*.json')));
@rmdir($out . '/history'); @rmdir($out); @rmdir($fixture . '/credentials'); @rmdir($fixture);
echo "TOTAL: $passed GA4 report checks passed; no network requests\n";
