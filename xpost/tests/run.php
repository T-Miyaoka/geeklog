<?php
require_once dirname(__DIR__) . '/plugins/xpost/classes/Core.php';
require_once dirname(__DIR__) . '/plugins/xpost/classes/View.php';
use Happa\XPost\Text;
use Happa\XPost\Store;
use Happa\XPost\Client;
use Happa\XPost\Publisher;
use Happa\XPost\Preview;
use Happa\XPost\View;

$root = getenv('XPOST_TEST_DIR');
if (!$root) { fwrite(STDERR, "Set XPOST_TEST_DIR to an external test output directory.\n"); exit(1); }
if (!is_dir($root)) { mkdir($root, 0750, true); }
$count = 0;
function check($condition, $label) { global $count; if (!$condition) { throw new RuntimeException('FAIL: ' . $label); } $count++; echo "PASS: $label\n"; }
function rejects(callable $action, $label) { try { $action(); } catch (Throwable $error) { check(true, $label); return; } check(false, $label); }
function fixture($name) { global $root; return new Store($root . '/' . $name . '-' . bin2hex(random_bytes(4))); }
define('VERSION', '2.2.2');
function metadataFixture() { require dirname(__DIR__) . '/plugins/xpost/autoinstall.php'; return plugin_autoinstall_xpost('xpost'); }
$metadata = metadataFixture();
check($metadata['info']['pi_version'] === '1.0.0' && $metadata['info']['pi_gl_version'] === '2.2.2', 'Geeklog discovers metadata when including plugin from function scope');
$config = array('username' => '__MeeSan__', 'api_key' => 'test-key', 'api_secret' => 'test-secret', 'access_token' => 'test-token', 'access_secret' => 'test-token-secret');
check(is_readable(Client::caBundle()) && strpos(file_get_contents(Client::caBundle()), 'BEGIN CERTIFICATE') !== false, 'Plugin has its own readable CA bundle');
check(strpos(Client::connectionFailure(77, 'GET')->getMessage(), 'CA証明書') !== false, 'CA file failure is identified without raw transport details');
check(Client::connectionFailure(28, 'POST')->uncertain && !Client::connectionFailure(28, 'GET')->uncertain, 'Transport failure preserves POST unknown-outcome protection');
$articles = array(array('sid' => 'example_ja', 'title' => '開発者ブログ 日本語', 'url' => 'https://www.happa.bz/article.php?story=example_ja'),
    array('sid' => 'example_en', 'title' => 'Developer blog English', 'url' => 'https://www.happa.bz/article.php?story=example_en'));
$text = Text::draft($articles);
check(Text::weight('あいうabc') === 9, 'Japanese and ASCII weights');
check(Text::weight('https://www.happa.bz/article.php?story=long_article_identifier') === 23, 'Article URLs have fixed weight');
check(Text::weight("a\nb") === 3, 'Newlines count');
check(Text::weight($text) <= 280, 'Bilingual generated draft fits');
Text::validate($text, $articles);
check(true, 'Bilingual URLs survive generation');
$long = $articles; $long[0]['title'] = str_repeat('長い日本語タイトル', 100); $long[1]['title'] = str_repeat('Long title ', 100);
check(Text::weight(Text::draft($long)) <= 280, 'Long localized titles truncate while retaining URLs');
rejects(function () use ($articles) { Text::validate(str_repeat('あ', 141), $articles); }, 'Overlength text rejected');
rejects(function () use ($articles) { Text::validate('Missing links', $articles); }, 'Missing article URLs rejected');
rejects(function () use ($text) { Text::validate($text, array()); }, 'No selected article rejected');
rejects(function () use ($articles) { Text::validate("\x00" . Text::draft($articles), $articles); }, 'Control characters rejected');

$store = fixture('settings');
if (!is_dir($root . '/public-fixture')) { mkdir($root . '/public-fixture', 0750, true); }
check(strpos(Store::privateDirectory($root, $root . '/public-fixture'), '/xpost') !== false, 'Credential directory is outside web root');
rejects(function () use ($root) { Store::privateDirectory($root . '/public-fixture', $root . '/public-fixture'); }, 'Public credential storage is rejected');
$store->configure($config); $store->configure(array('username' => '__MeeSan__', 'api_key' => ''));
check($store->configuration()['api_key'] === 'test-key', 'Blank credential fields preserve existing credentials');
rejects(function () use ($store) { $store->configure(array('username' => 'bad/name')); }, 'Invalid target account rejected');
$signed = Preview::create($store, $articles, $text, 2, $config);
check(Preview::verify($store, $signed, 2, $config)['text'] === $text, 'Confirmed text round trips');
rejects(function () use ($store, $signed, $config) { Preview::verify($store, 'X' . $signed, 2, $config); }, 'Tampered preview rejected');
rejects(function () use ($store, $signed, $config) { Preview::verify($store, $signed, 3, $config); }, 'Preview bound to Geeklog user');
$changed = $config; $changed['username'] = 'another';
rejects(function () use ($store, $signed, $changed) { Preview::verify($store, $signed, 2, $changed); }, 'Changed target/configuration invalidates preview');

// Independent OAuth reference, calculated using Python's hmac in the verification runner.
$header = Client::authorization('POST', 'https://api.x.com/2/tweets', $config, 'test-nonce', '1234567890');
file_put_contents($root . '/oauth-header.txt', $header);
check(strpos($header, 'oauth_signature=') !== false, 'OAuth signature generated without signing JSON fields');
$calls = array();
$transport = function ($method, $url, $headers, $body) use (&$calls) {
    $calls[] = array($method, $url, $body);
    return array('status' => $method === 'GET' ? 200 : 201, 'body' => $method === 'GET'
        ? '{"data":{"id":"1234","username":"__MeeSan__"}}' : '{"data":{"id":"99887766","text":"published"}}');
};
$store = fixture('success'); $publisher = new Publisher($store); $client = new Client($config, $transport);
$result = $publisher->publish($articles, $text, 2, $client);
check($result['state'] === 'posted' && $result['url'] === 'https://x.com/__MeeSan__/status/99887766', 'Public URL saved only after API success');
check(count($calls) === 2 && $calls[0][1] === 'https://api.x.com/2/users/me' && $calls[1][1] === 'https://api.x.com/2/tweets', 'Identity is checked before publishing');
check(json_decode($calls[1][2], true)['text'] === $text, 'Exact confirmed bilingual text sent once');
rejects(function () use ($publisher, $articles, $text, $client) { $publisher->publish($articles, $text, 2, $client); }, 'Duplicate article group blocked');
check(count($calls) === 2, 'Duplicate attempt makes zero API calls');
rejects(function () use ($publisher, $articles, $client) { $publisher->publish(array($articles[1]), Text::draft(array($articles[1])), 2, $client); }, 'Article cannot be duplicated using a different pairing');

$store = fixture('mismatch'); $calls = array();
$mismatch = new Client($config, function () use (&$calls) { $calls[] = true; return array('status' => 200, 'body' => '{"data":{"id":"55","username":"other"}}'); });
rejects(function () use ($store, $articles, $text, $mismatch) { (new Publisher($store))->publish($articles, $text, 2, $mismatch); }, 'Wrong X account blocks publishing');
check(count($calls) === 1 && $store->read('history') === array(), 'Account mismatch creates no post and no sent marker');

foreach (array(401 => 'failed', 403 => 'failed', 429 => 'failed', 500 => 'uncertain', 408 => 'uncertain', 302 => 'uncertain') as $status => $state) {
    $store = fixture('http-' . $status);
    $client = new Client($config, function ($method) use ($status) { return array('status' => $method === 'GET' ? 200 : $status,
        'body' => $method === 'GET' ? '{"data":{"id":"1234","username":"__MeeSan__"}}' : '{"error":"do not reflect TOKEN"}'); });
    rejects(function () use ($store, $articles, $text, $client) { (new Publisher($store))->publish($articles, $text, 2, $client); }, 'HTTP ' . $status . ' reported as failure');
    $history = $store->read('history'); check(reset($history)['state'] === $state, 'HTTP ' . $status . ' retry state: ' . $state);
}
$store = fixture('retry-success');
$previous = array('sids' => array_column($articles, 'sid'), 'text' => $text, 'state' => 'failed');
$store->write('history', array('previous' => $previous)); $calls = array();
$retryPublisher = new Publisher($store); $retryClient = new Client($config, $transport);
$retryPublisher->publish($articles, $text, 2, $retryClient);
$retryHistory = $store->read('history');
check($retryHistory['previous'] === $previous && count($retryHistory) === 2 && end($retryHistory)['state'] === 'posted', 'Confirmed retry preserves failed record and adds a successful attempt');
check(count($calls) === 2 && json_decode($calls[1][2], true)['text'] === $text, 'Confirmed retry sends the saved text exactly once');
rejects(function () use ($retryPublisher, $articles, $text, $retryClient) { $retryPublisher->publish($articles, $text, 2, $retryClient); }, 'Successful retry cannot be duplicated from the old failed record');
check(count($calls) === 2, 'Duplicate retry makes no additional API calls');
$store = fixture('malformed');
$client = new Client($config, function ($method) { return array('status' => $method === 'GET' ? 200 : 201,
    'body' => $method === 'GET' ? '{"data":{"id":"1234","username":"__MeeSan__"}}' : '{"data":{}}'); });
rejects(function () use ($store, $articles, $text, $client) { (new Publisher($store))->publish($articles, $text, 2, $client); }, 'Malformed API success is not reported as published');
$history = $store->read('history'); $key = key($history);
check($history[$key]['state'] === 'uncertain', 'Unknown result blocks retry');
$publisher = new Publisher($store);
rejects(function () use ($publisher, $articles, $text, $client) { $publisher->publish($articles, $text, 2, $client); }, 'Unknown outcome cannot be reposted');
rejects(function () use ($publisher, $key) { $publisher->reconcile($key, 'https://x.com/other/status/999', false); }, 'Manual post URL must belong to recorded account');
$publisher->reconcile($key, 'https://x.com/__MeeSan__/status/999', false);
check($store->read('history')[$key]['state'] === 'posted', 'Manual confirmed URL resolves unknown state');
$store = fixture('crash'); $store->write('history', array('pending' => array('sids' => array('example_ja'), 'state' => 'sending')));
rejects(function () use ($store, $articles, $text, $client) { (new Publisher($store))->publish($articles, $text, 2, $client); }, 'Crash marker prevents duplicate send');
$publisher = new Publisher($store); $publisher->reconcile('pending', '', true);
check($store->read('history')['pending']['state'] === 'failed', 'Explicit unpublished confirmation releases crash marker');
$directory = $root . '/corrupt-' . bin2hex(random_bytes(4)); $store = new Store($directory); file_put_contents($directory . '/history.json', '{broken');
rejects(function () use ($store, $articles, $text, $client) { (new Publisher($store))->publish($articles, $text, 2, $client); }, 'Corrupt history fails closed');
$directory = $root . '/locked-' . bin2hex(random_bytes(4)); $store = new Store($directory); $handle = fopen($directory . '/posting.lock', 'c'); flock($handle, LOCK_EX);
rejects(function () use ($store) { $store->locked(function () {}); }, 'Concurrent request cannot acquire posting lock'); flock($handle, LOCK_UN); fclose($handle);

define('CSRF_TOKEN', '_glsectoken');
$model = array('base' => '/admin/plugins/xpost/index.php', 'css' => '/admin/plugins/xpost/style.css', 'token' => 'test-csrf',
    'config' => $config, 'message' => '', 'error' => '', 'search' => '', 'selected' => array('sid' => 'example_ja', 'second_sid' => 'example_en'),
    'recent' => $long, 'articles' => $articles, 'text' => $text, 'preview' => 'test-preview', 'history' => array());
$html = View::render($model);
check(strpos($html, 'test-secret') === false && strpos($html, 'test-token') === false, 'Credential secrets never appear in HTML');
check(strpos($html, 'type="password"') === false && strpos($html, 'value="check"') === false && strpos($html, '/settings.php') !== false, 'Posting view links to separate settings without credential or connection forms');
$settingsModel = array('base'=>'/admin/plugins/xpost/settings.php','css'=>'style.css','token'=>'csrf','config'=>$config,'message'=>'','error'=>'',
    'post_url'=>'/admin/plugins/xpost/index.php','configuration_url'=>'/admin/configuration.php','registered'=>true);
$html = View::settings($settingsModel);
check(strpos($html, 'test-secret') === false && strpos($html, 'test-token') === false, 'Dedicated settings never reveals stored secret values');
$settingsDom=new DOMDocument();libxml_use_internal_errors(true);$settingsDom->loadHTML('<?xml encoding="UTF-8">'.$html);$settingsXpath=new DOMXPath($settingsDom);
check($settingsXpath->query('//header/p[contains(.,"空欄保存")]')->length===1 && $settingsXpath->query('//section[@id="settings"]/p')->length===0, 'API operating instructions stay together in title header');
$dom = new DOMDocument(); libxml_use_internal_errors(true); $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
$submitted = array('username' => '__MeeSan__'); $masked = 0;
foreach ($dom->getElementsByTagName('input') as $input) {
    if ($input->getAttribute('type') === 'password') {
        $submitted[$input->getAttribute('name')] = $input->getAttribute('value');
        if ($input->getAttribute('placeholder') === '••••••••') { $masked++; }
    }
}
$settingsStore = fixture('masked-settings'); $settingsStore->configure($config); $settingsStore->configure($submitted);
check($masked === 4 && $settingsStore->configuration() === $config, 'All saved credentials display masks while unchanged form submission preserves every secret');
$model['error'] = '<script>alert(1)</script>';
check(strpos(View::render($model), '<script>alert(1)</script>') === false, 'Error text escaped');
$model['error'] = ''; $model['text'] = "DEVELOPMENT SNAPSHOT #59\n暦は計算待ちで止まらない。今日の天気を先に描く仕組み。\nThe calendar keeps up: previewing today’s weather.\nJA https://www.happa.bz/article.php?story=devblog-059-calendar-weather-preview_ja\nEN https://www.happa.bz/article.php?story=devblog-059-calendar-weather-preview_en\n#AshwindChronicle #GameDev";
$model['recent'] = array(array('sid' => 'example_ja', 'title' => 'DEVELOPMENT SNAPSHOT #59 — 暦は計算待ちで止まらない。今日の天気を先に描く仕組み。'), array('sid' => 'example_en', 'title' => 'DEVELOPMENT SNAPSHOT #59 — The calendar keeps up: previewing today’s weather.'));
file_put_contents($root . '/preview.html', '<!doctype html><html lang="ja"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Happa X投稿 UI検証</title><style>body{margin:0;background:#f4f6f1;font-family:Meiryo,sans-serif}</style></head><body>' . View::render($model) . '</body></html>');
function historyDom(array $model)
{
    $dom = new DOMDocument(); libxml_use_internal_errors(true); $dom->loadHTML('<?xml encoding="UTF-8">' . View::render($model));
    return new DOMXPath($dom);
}
$historyModel=$model;$historyModel['articles']=array();$historyModel['preview']='';$historyModel['history']=array(
    'posted'=>array('sids'=>array('example_ja'),'state'=>'posted','at'=>gmdate('c'),'url'=>'https://x.com/test/status/1'),
    'failed'=>array('sids'=>array('example_ja'),'state'=>'failed','at'=>gmdate('c'),'text'=>'old'));
$before=$historyModel['history'];$xpath=historyDom($historyModel);
check($xpath->query('//section[@id="history" or @id="reply-history"]')->length===0,'Completed posting and reply history sections are absent');
check($xpath->query('//h2[text()="記事を選択"]')->length===1 && $xpath->query('//h2[starts-with(text(),"1.") or starts-with(text(),"2.")]')->length===0,'Article headings have no step numbers');
check(strpos(View::render($historyModel),'API設定・接続確認はコンフィギュレーション側へ移動しました')===false,'Obsolete settings migration notice removed');
$historyModel['history']['pending']=array('sids'=>array('example_en'),'state'=>'uncertain','at'=>gmdate('c'));
$xpath=historyDom($historyModel);
check($xpath->query('//section[@id="unresolved"]//form[input[@name="action" and @value="reconcile"]]')->length===1,'Uncertain results retain a compact safety reconciliation control');
check($historyModel['history']['posted']===$before['posted'] && $historyModel['history']['failed']===$before['failed'],'Hiding history never deletes duplicate prevention data');
echo "TOTAL: $count passed\n";
