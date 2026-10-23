<?php
// Isolated controller fixture. Never install this bootstrap on the real website.
define('VERSION', '2.2.2'); define('CSRF_TOKEN', '_glsectoken');
$case = getenv('XPOST_CONTROLLER_CASE');
$_USER = array('uid' => $case === 'anonymous' ? 1 : 2);
$_CONF = array('path' => dirname(__DIR__) . '/', 'path_html' => __DIR__ . '/',
    'path_data' => dirname(__DIR__) . '/data/', 'site_url' => 'https://www.happa.bz', 'site_admin_url' => 'https://www.happa.bz/admin');
$_TABLES = array('stories' => 'test_stories', 'topics' => 'test_topics', 'topic_assignments' => 'test_topic_assignments');
// lib-common.php retains this singleton globally and the Root menu uses it when rendering.
class FixtureGeeklogConfig { public function _get_groups() { return array('Core'); } }
$config = new FixtureGeeklogConfig();
$_SERVER['REQUEST_METHOD'] = in_array($case, array('anonymous', 'get'), true) ? 'GET' : 'POST';
$_GET = array();
$_POST = array('sid' => 'example_ja', 'second_sid' => '', 'text' => '紹介 https://www.happa.bz/article.php?story=example_ja');
if ($case === 'draft') { $_POST['action'] = 'draft'; }
elseif ($case === 'preview') { $_POST['action'] = 'preview'; }
elseif ($case === 'tamper') { $_POST = array('action' => 'publish', 'preview_id' => 'invalid.signature'); }
elseif ($case === 'settings' || $case === 'csrf') {
    $_POST = array('action' => 'settings', 'username' => '__MeeSan__', 'api_key' => 'fixture-key', 'api_secret' => 'fixture-secret',
        'access_token' => 'fixture-token', 'access_secret' => 'fixture-token-secret');
}
elseif (strpos($case, 'retry_') === 0) {
    // Incoming article/text fields must never replace the selected history record.
    $_POST = array('action' => 'retry', 'key' => 'saved', 'sid' => 'tampered', 'text' => 'tampered');
    if ($case === 'retry_missing') { $_POST['key'] = 'missing'; }
}
elseif ($case === 'reply_publish_csrf' || $case === 'reply_publish_tamper') {
    $_POST = array('action' => 'reply_publish', 'reply_preview' => 'invalid.signature');
}
elseif (strpos($case, 'replies_') === 0) {
    $_POST = array('action' => 'replies', 'key' => $case === 'replies_missing' ? 'absent' : 'saved');
}
function SEC_inGroup($group) { global $case; return $case !== 'anonymous'; }
function SECINT_checkToken() { global $case; return !in_array($case, array('csrf', 'retry_csrf', 'replies_csrf', 'reply_publish_csrf'), true); }
function SEC_createToken() { return 'fixture-csrf'; }
function COM_createHTMLDocument($content, $options)
{
    global $config;
    $groups = $config->_get_groups();
    if ($groups !== array('Core')) { throw new RuntimeException('Geeklog configuration singleton was changed.'); }
    return '<!doctype html><meta charset="UTF-8"><title>' . $options['pagetitle'] . '</title>' . $content;
}
function DB_escapeString($value) { return addslashes($value); }
class FixtureResult { public $rows; public function __construct($rows) { $this->rows = $rows; } }
function DB_query($sql)
{
    global $case;
    $rows = array(array('sid' => 'example_ja', 'title' => '日本語テスト記事', 'date' => '2026-10-19 12:00:00'),
        array('sid' => 'example_en', 'title' => 'English test article', 'date' => '2026-10-19 12:00:00'));
    if (preg_match("/s\\.sid='([^']*)'/", $sql, $match)) {
        $rows = array_values(array_filter($rows, function ($row) use ($match, $case) {
            return $row['sid'] === $match[1] && !($case === 'retry_unpublished' && $row['sid'] === 'example_en');
        }));
    }
    return new FixtureResult($rows);
}
function DB_fetchArray($result) { return array_shift($result->rows); }
