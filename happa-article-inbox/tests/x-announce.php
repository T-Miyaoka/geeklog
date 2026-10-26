<?php
// Run from the repository root: php -n happa-article-inbox/tests/x-announce.php
// Announcing imported articles on X through the X-Post plugin's own Store, Client and
// Publisher. The X API is a stand-in transport; nothing is sent over the network.
define('HAPPA_ARTICLE_LIBRARY_ONLY', true);
require __DIR__ . '/../run-import.php';

$count = 0;
function expect($condition, $message) { global $count; if (!$condition) { throw new RuntimeException('FAIL: ' . $message); } $count++; echo "PASS $message\n"; }

$classes = realpath(__DIR__ . '/../../xpost/plugins/xpost/classes');
require_once $classes . '/Core.php';
require_once $classes . '/Articles.php';
$root = sys_get_temp_dir() . '/happa-x-announce-test-' . getmypid() . '-' . bin2hex(random_bytes(4));
mkdir($root, 0777, true);
$config = array('username' => '__MeeSan__', 'api_key' => 'k', 'api_secret' => 's', 'access_token' => 't', 'access_secret' => 'u');

function storeDir($name, $withCredentials = true)
{
    global $root, $config;
    $dir = $root . '/' . $name . '/xpost';
    mkdir($dir, 0777, true);
    if ($withCredentials) { file_put_contents($dir . '/credentials.json', json_encode($config)); }
    return $dir;
}
function readJson($dir, $name) { $path = $dir . '/' . $name . '.json'; return is_file($path) ? json_decode(file_get_contents($path), true) : array(); }

/** Stand-in X API: identity succeeds; the post answers with $postStatus. Counts every request. */
function standInClient(&$calls, $postStatus = 201, $dir = null)
{
    global $config;
    $transport = function ($method, $url, $headers, $body) use (&$calls, $postStatus) {
        $calls[] = $method . ' ' . parse_url($url, PHP_URL_PATH);
        if ($method === 'GET') { return array('status' => 200, 'body' => '{"data":{"id":"42","username":"__MeeSan__"}}'); }
        if ($postStatus >= 200 && $postStatus < 300) { return array('status' => $postStatus, 'body' => '{"data":{"id":"2114844960390553999","text":"x"}}'); }
        return array('status' => $postStatus, 'body' => '{"errors":[{"code":1}]}');
    };
    return new Happa\XPost\Client($config, $transport, $dir === null ? null : new Happa\XPost\Store($dir));
}

function publicArticles(array $sids)
{
    return array_map(function ($sid) {
        return array('sid' => $sid, 'title' => 'DEVELOPMENT SNAPSHOT #68 ' . $sid, 'url' => 'https://www.happa.bz/article.php?story=' . rawurlencode($sid));
    }, $sids);
}

$pair = array('schema_version' => 1, 'announce_on_x' => true, 'articles' => array(
    array('sid' => 'devblog-068_ja', 'topic' => 'Blog_ja', 'language' => 'ja', 'publish' => true),
    array('sid' => 'devblog-068_en', 'topic' => 'Blog_en', 'language' => 'en', 'publish' => true),
));
function options($dir, $client, $enabled = 1, $articles = 'publicArticles')
{
    global $classes;
    return array('enabled' => $enabled, 'store_dir' => $dir, 'xpost_classes' => $classes, 'articles' => $articles, 'client' => $client);
}

// Manifest validation of the new fields.
$package = $root . '/package';
mkdir($package);
$article = array('sid' => 'devblog-v_ja', 'topic' => 'Blog_ja', 'language' => 'ja', 'title' => 'T', 'introtext' => '<p>t</p>', 'bodytext' => '');
$manifestCase = function (array $extra) use ($package, $article) {
    file_put_contents($package . '/article.json', json_encode(array('schema_version' => 1, 'articles' => array($article)) + $extra));
    try { return validateManifest($package); } catch (RuntimeException $error) { return $error->getMessage(); }
};
expect(is_array($manifestCase(array())) && !importerAnnouncementRequested($manifestCase(array())), 'a package without announce_on_x is valid and does not request an announcement');
expect(!importerAnnouncementRequested($manifestCase(array('announce_on_x' => false))), 'announce_on_x=false does not request an announcement');
expect(importerAnnouncementRequested($manifestCase(array('announce_on_x' => true, 'x_text' => 'text'))), 'announce_on_x=true with x_text is valid and requests an announcement');
expect(strpos($manifestCase(array('announce_on_x' => 'true')), 'announce_on_x must be true or false') !== false, 'a non-boolean announce_on_x is rejected');
expect(strpos($manifestCase(array('announce_on_x' => null)), 'announce_on_x must be true or false') !== false, 'a null announce_on_x is rejected');
foreach (array('', '   ', 12, null, array('a')) as $bad) {
    expect(strpos((string) $manifestCase(array('x_text' => $bad)), 'x_text must be a non-empty string') !== false, 'x_text ' . json_encode($bad) . ' is rejected');
}
expect(strpos($manifestCase(array('x_text' => str_repeat('a', 12001))), 'x_text must be a non-empty string') !== false, 'an over-long x_text is rejected');

// Setting off: nothing is read from X and nothing is recorded.
$dir = storeDir('disabled'); $calls = array();
$result = importerAnnounceOnX($pair, 7, options($dir, standInClient($calls, 201, $dir), 0));
expect($result['state'] === 'skipped' && strpos($result['reason'], '記事取り込みからの告知を許可する') !== false && $calls === array() && !is_file($dir . '/history.json'), 'with the X-Post setting off the announcement is skipped without any request');
$result = importerAnnounceOnX($pair, 7, options($dir, standInClient($calls, 201, $dir), null));
expect($result['state'] === 'skipped' && $calls === array(), 'an unregistered setting counts as off');
expect(!Happa\XPost\Policy::inboxAnnouncementAllowed(), 'without Geeklog configuration the setting is off');

// No article to publish.
$drafts = $pair; $drafts['articles'][0]['publish'] = false; unset($drafts['articles'][1]['publish']);
$result = importerAnnounceOnX($drafts, 7, options($dir, standInClient($calls, 201, $dir)));
expect($result['state'] === 'skipped' && strpos($result['reason'], 'publish=true') !== false && $calls === array(), 'a package without publish=true articles is skipped');

// Missing permissions or data.
$noCredentials = storeDir('no-credentials', false);
$result = importerAnnounceOnX($pair, 7, options($noCredentials, standInClient($calls, 201, $noCredentials)));
expect($result['state'] === 'failed' && strpos($result['reason'], 'credentials.json') !== false && $calls === array(), 'unreadable credentials.json fails with the permission named');
$result = importerAnnounceOnX($pair, 7, options($root . '/missing/xpost', standInClient($calls, 201, $noCredentials)));
expect($result['state'] === 'failed' && strpos($result['reason'], 'data/xpost') !== false, 'a missing data/xpost folder fails with the reason');
expect(importerXPostAccessProblems(storeDir('access')) === array(), 'a readable and writable data/xpost has no access problem');
$result = importerAnnounceOnX($pair, 7, array('xpost_classes' => $root . '/no-plugin') + options($dir, null));
expect($result['state'] === 'failed' && strpos($result['reason'], 'X-Post') !== false, 'a missing X-Post plugin fails');

// Articles that are not public right before sending.
$notPublic = function () { throw new RuntimeException('公開中の記事を選択してください。'); };
$result = importerAnnounceOnX($pair, 7, options($dir, standInClient($calls, 201, $dir), 1, $notPublic));
expect($result['state'] === 'skipped' && strpos($result['reason'], '公開中') !== false && $calls === array(), 'articles that are not public when re-read are skipped');

// Success with the X-Post default text.
$dir = storeDir('success'); $calls = array();
$result = importerAnnounceOnX($pair, 7, options($dir, standInClient($calls, 201, $dir)));
$history = readJson($dir, 'history'); $entry = reset($history);
expect($result === array('state' => 'posted', 'url' => 'https://x.com/__MeeSan__/status/2114844960390553999', 'reason' => ''), 'a permitted announcement is posted and returns its URL: ' . json_encode($result, JSON_UNESCAPED_UNICODE));
expect($calls === array('GET /2/users/me', 'POST /2/tweets'), 'the announcement checks the account and posts exactly once');
expect(count($history) === 1 && $entry['state'] === 'posted' && $entry['uid'] === 7 && $entry['sids'] === array('devblog-068_ja', 'devblog-068_en'), 'history.json records posted, the importer uid and both SIDs');
expect($entry['text'] === Happa\XPost\Text::draft(publicArticles(array('devblog-068_ja', 'devblog-068_en'))), 'without x_text the X-Post default text is used');
$budget = readJson($dir, 'api-budget'); $month = reset($budget);
expect($month['spent'] === 210 && $month['pending'] === array(), 'the API budget is reserved and settled for both requests');

// Duplicate: the same articles are never posted again, even after the interval.
$history[key($history)]['at'] = gmdate('c', time() - 30 * 86400);
(new Happa\XPost\Store($dir))->write('history', $history); $calls = array();
$result = importerAnnounceOnX($pair, 7, options($dir, standInClient($calls, 201, $dir)));
expect($result['state'] === 'skipped' && strpos($result['reason'], '投稿済み') !== false && $calls === array() && count(readJson($dir, 'history')) === 1, 'already announced articles are skipped without a request');

// Interval: another article within 24 hours of the last announcement.
$history[key($history)]['at'] = gmdate('c', time() - 3600);
(new Happa\XPost\Store($dir))->write('history', $history); $calls = array();
$other = $pair; $other['articles'][0]['sid'] = 'devblog-069_ja'; $other['articles'][1]['sid'] = 'devblog-069_en';
$result = importerAnnounceOnX($other, 7, options($dir, standInClient($calls, 201, $dir)));
expect($result['state'] === 'skipped' && strpos($result['reason'], '24時間') !== false && $calls === array(), 'an announcement within the minimum interval is skipped with the reason');

// x_text overrides the text; a text without the article URLs is refused before any request.
$dir = storeDir('x-text'); $calls = array();
$custom = $pair; $custom['x_text'] = "開発者ブログ #68\r\nhttps://www.happa.bz/article.php?story=devblog-068_ja\r\nhttps://www.happa.bz/article.php?story=devblog-068_en";
$result = importerAnnounceOnX($custom, 7, options($dir, standInClient($calls, 201, $dir)));
$history = readJson($dir, 'history'); $entry = reset($history);
expect($result['state'] === 'posted' && $entry['text'] === str_replace("\r\n", "\n", $custom['x_text']), 'x_text replaces the default text (line breaks normalized to LF)');
$dir = storeDir('x-text-bad'); $calls = array();
$custom['x_text'] = '開発者ブログ #68';
$result = importerAnnounceOnX($custom, 7, options($dir, standInClient($calls, 201, $dir)));
expect($result['state'] === 'failed' && strpos($result['reason'], 'URL') !== false && $calls === array() && !is_file($dir . '/history.json'), 'an x_text without the article URLs fails before any request');
$custom['x_text'] = str_repeat('あ', 141) . ' https://www.happa.bz/article.php?story=devblog-068_ja https://www.happa.bz/article.php?story=devblog-068_en';
$result = importerAnnounceOnX($custom, 7, options($dir, standInClient($calls, 201, $dir)));
expect($result['state'] === 'failed' && strpos($result['reason'], '280') !== false && $calls === array(), 'an x_text over 280 characters fails before any request');

// A clear refusal from X is failed and may be retried from the X-Post screen.
$dir = storeDir('refused'); $calls = array();
$result = importerAnnounceOnX($pair, 7, options($dir, standInClient($calls, 403, $dir)));
$history = readJson($dir, 'history'); $entry = reset($history);
expect($result['state'] === 'failed' && strpos($result['reason'], 'HTTP 403') !== false && $entry['state'] === 'failed', 'an X refusal is failed and recorded as failed: ' . $result['reason']);

// An unknown outcome is uncertain and is never resent.
$dir = storeDir('uncertain'); $calls = array();
$result = importerAnnounceOnX($pair, 7, options($dir, standInClient($calls, 503, $dir)));
$history = readJson($dir, 'history'); $entry = reset($history);
expect($result['state'] === 'uncertain' && $entry['state'] === 'uncertain' && strpos($result['reason'], '再送は停止') !== false, 'an unknown outcome (HTTP 503) is uncertain: ' . $result['reason']);
$history[key($history)]['at'] = gmdate('c', time() - 30 * 86400);
(new Happa\XPost\Store($dir))->write('history', $history); $calls = array();
$result = importerAnnounceOnX($pair, 7, options($dir, standInClient($calls, 201, $dir)));
expect($result['state'] === 'skipped' && $calls === array(), 'an uncertain announcement is not resent by a later import');

// Transport failure on the POST (no HTTP response) is uncertain too.
$dir = storeDir('transport'); $calls = array();
$failing = new Happa\XPost\Client($config, function ($method) use (&$calls) {
    $calls[] = $method;
    if ($method === 'GET') { return array('status' => 200, 'body' => '{"data":{"id":"42","username":"__MeeSan__"}}'); }
    throw Happa\XPost\Client::connectionFailure(28, 'POST');
}, new Happa\XPost\Store($dir));
$result = importerAnnounceOnX($pair, 7, options($dir, $failing));
$history = readJson($dir, 'history'); $entry = reset($history);
expect($result['state'] === 'uncertain' && $entry['state'] === 'uncertain' && count($calls) === 2, 'a timeout after sending is recorded and reported as uncertain: ' . $result['reason']);

// The API budget is enforced: no request when it cannot be reserved.
$dir = storeDir('budget'); $calls = array();
(new Happa\XPost\Store($dir))->write('api-budget', array(gmdate('Y-m') => array('spent' => 19995, 'pending' => array(), 'started_at' => 'test')));
$result = importerAnnounceOnX($pair, 7, options($dir, standInClient($calls, 201, $dir)));
expect($result['state'] === 'failed' && strpos($result['reason'], '予算') !== false && $calls === array(), 'an exhausted API budget fails before any request');

// No secret reaches the result.
expect(strpos(json_encode($result) . json_encode(importerAnnounceOnX($pair, 7, options(storeDir('secret'), standInClient($calls, 403)))), '"k"') === false, 'results contain no credential values');

$remove = function ($path) use (&$remove) {
    if (is_dir($path) && !is_link($path)) { foreach (array_diff(scandir($path), array('.', '..')) as $name) { $remove($path . '/' . $name); } rmdir($path); }
    elseif (file_exists($path) || is_link($path)) { unlink($path); }
};
$remove($root);
echo "TOTAL: $count announcement checks passed; no network requests\n";
