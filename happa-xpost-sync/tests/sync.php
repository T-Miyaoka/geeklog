<?php
// Run from the repository root: php -n happa-xpost-sync/tests/sync.php
// No network access. The plugin is replaced by a stand-in under a temporary site root.
require_once __DIR__ . '/../xpost-sync.inc.php';
use function Happa\XPostSync\isTransient;
use function Happa\XPostSync\runWithRetry;

$passed = 0;
function check($ok, $label) { global $passed; if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); } $passed++; echo "PASS: $label\n"; }

// Messages exactly as the plugin builds them (Core::connectionFailure and the HTTP error detail).
foreach (array('X APIとのTLS接続に失敗しました。（cURL 35）', 'X APIへの接続がタイムアウトしました。（cURL 28）', 'X APIへ接続できません。（cURL 7）',
    'X APIの名前解決に失敗しました。（cURL 6）', 'X APIの利用制限です。時間をおいてください。（HTTP 429、JSON応答）',
    'X APIが要求を受理しませんでした。（HTTP 503、HTML応答）') as $m) {
    check(isTransient($m), 'transient: ' . $m);
}
foreach (array('X APIのサーバー証明書を検証できません。（cURL 60）', 'X APIの認証情報を確認してください。（HTTP 401、JSON応答）',
    'XアプリのRead and Write権限とAPI利用権限を確認してください。（HTTP 403、JSON応答、Xコード 453）', 'X APIの利用枠・残高を確認してください。（HTTP 402、JSON応答）',
    '月額のAPI予算上限に達したため停止しました。', '返信の取得結果が不完全、または確認不能です。返信なしとは判定していません。') as $m) {
    check(!isTransient($m), 'not retried: ' . $m);
}

$sleeps = array(); $logs = array(); $n = 0;
list($r, $attempts) = runWithRetry(function () use (&$n) { $n++; if ($n < 3) { throw new RuntimeException('X APIとのTLS接続に失敗しました。（cURL 35）'); } return 'ok'; },
    array(60, 180), function ($s) use (&$sleeps) { $sleeps[] = $s; }, function ($l) use (&$logs) { $logs[] = $l; });
check($r === 'ok' && $attempts === 3 && $sleeps === array(60, 180) && count($logs) === 2, 'two TLS failures are retried after 60s and 180s, then succeed');

$sleeps = array(); $n = 0;
try { runWithRetry(function () use (&$n) { $n++; throw new RuntimeException('X APIとのTLS接続に失敗しました。（cURL 35）'); }, array(60, 180),
    function ($s) use (&$sleeps) { $sleeps[] = $s; }, function () {}); $ok = false; } catch (RuntimeException $e) { $ok = $n === 3 && count($sleeps) === 2; }
check($ok, 'gives up after three attempts');

$n = 0;
try { runWithRetry(function () use (&$n) { $n++; throw new RuntimeException('X APIの認証情報を確認してください。（HTTP 401、JSON応答）'); }, array(60, 180),
    function () { throw new LogicException('must not sleep'); }, function () {}); $ok = false; } catch (RuntimeException $e) { $ok = $n === 1; }
check($ok, 'authentication errors stop at once without waiting');

// End to end against a stand-in plugin.
$site = sys_get_temp_dir() . '/happa-xpost-sync-' . bin2hex(random_bytes(4));
mkdir($site . '/plugins/xpost/classes', 0750, true); mkdir($site . '/data/xpost', 0750, true);
file_put_contents($site . '/plugins/xpost/classes/Core.php', '<?php namespace Happa\XPost;
class Store { public $dir; function __construct($d) { $this->dir = $d; } function configuration() { return array("username" => "x"); } }
class Client { function __construct($c, $t, $s) {} }
class Budget { static function state($s) { return array("spent" => 0); } }
class Feedback { private $s; function __construct($s) { $this->s = $s; }
  function sync($client, $config) { $mode = trim((string) @file_get_contents($this->s->dir . "/mode"));
    if ($mode === "fail") { throw new \RuntimeException("X APIの認証情報を確認してください。（HTTP 401、JSON応答）"); }
    file_put_contents($this->s->dir . "/synced", "1"); return array("status" => "synced", "new_items" => 2, "pages" => 1, "incomplete_roots" => 0); }
  function pending() { return array(1, 2); } }');
$tool = sys_get_temp_dir() . '/happa-xpost-sync-tool-' . bin2hex(random_bytes(4)); mkdir($tool);
copy(__DIR__ . '/../run-xpost-sync.php', $tool . '/run-xpost-sync.php'); copy(__DIR__ . '/../xpost-sync.inc.php', $tool . '/xpost-sync.inc.php');
$run = function (array $args) use ($site, $tool) {
    $env = array('HAPPA_XPOST_SYNC_SITE_ROOT' => $site, 'PATH' => getenv('PATH'));
    $p = proc_open(array_merge(array(PHP_BINARY, '-n', $tool . '/run-xpost-sync.php'), $args), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, null, $env);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); return array(proc_close($p), $out, $err);
};
list($code, $out, $err) = $run(array());
$last = json_decode(file_get_contents($tool . '/last-run.json'), true);
check($code === 0 && is_file($site . '/data/xpost/synced') && $last['status'] === 'ok' && $last['result']['new_items'] === 2 && $last['pending'] === 2,
    'sync runs against data/xpost and records the result in last-run.json');
$log = implode('', array_map('file_get_contents', glob($tool . '/logs/*.log')));
check(strpos($log, 'SYNC_STARTED') !== false && strpos($log, 'SYNC_FINISHED: attempts=1') !== false, 'log records start and finish');
file_put_contents($site . '/data/xpost/mode', 'fail');
list($code, $out, $err) = $run(array());
$last = json_decode(file_get_contents($tool . '/last-run.json'), true);
check($code === 1 && $last['status'] === 'failed' && strpos($last['error'], 'HTTP 401') !== false && strpos($err, 'HTTP 401') !== false, 'a failure exits 1 and is recorded');
list($code, $out) = $run(array('--status'));
check($code === 0 && json_decode($out, true)['pending'] === 2, '--status reads without syncing');
list($code) = $run(array('--post'));
check($code === 2, 'unknown arguments are rejected');

foreach (array_merge(glob($tool . '/logs/*'), glob($tool . '/*'), glob($site . '/data/xpost/*'), array($site . '/plugins/xpost/classes/Core.php')) as $f) { if (is_file($f)) { unlink($f); } }
foreach (array($tool . '/logs', $tool, $site . '/data/xpost', $site . '/data', $site . '/plugins/xpost/classes', $site . '/plugins/xpost', $site . '/plugins', $site) as $d) { @rmdir($d); }
echo "TOTAL: $passed checks passed; no network requests\n";
