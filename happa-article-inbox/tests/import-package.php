<?php
// Run from the repository root: php -n happa-article-inbox/tests/import-package.php
// Imports packages end to end against a stand-in Geeklog whose article save ends
// the process with die(1) after the redirect, as Geeklog 2.2.2's COM_redirect does.

function expect($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }

$root = sys_get_temp_dir() . '/happa-import-test-' . getmypid() . '-' . bin2hex(random_bytes(4));
$site = $root . '/site';
$import = $root . '/importer';
foreach (array($site . '/images/articles', $site . '/data/xpost', $site . '/public_html', $site . '/plugins/xpost/classes', $import . '/credentials', $import . '/inbox/ready') as $dir) {
    mkdir($dir, 0777, true);
}
copy(__DIR__ . '/../run-import.php', $import . '/run-import.php');
foreach (array('Core.php', 'Automation.php', 'Articles.php') as $class) {
    copy(__DIR__ . '/../../xpost/plugins/xpost/classes/' . $class, $site . '/plugins/xpost/classes/' . $class);
}
file_put_contents($import . '/credentials/credentials.ini', "username=importer\npassword=secret\n");

$fake = <<<'PHP'
<?php
define('USER_ACCOUNT_ACTIVE', 3);
define('TOPIC_SELECTED_OPTION', 'selectedtopics');
define('PLG_RET_OK', 0);
define('PLG_RET_ERROR', -1);
$_CONF = array('timezone' => 'Asia/Tokyo', 'path' => __DIR__ . '/', 'path_html' => __DIR__ . '/public_html/', 'path_system' => __DIR__ . '/', 'path_data' => __DIR__ . '/data/', 'path_images' => __DIR__ . '/images/', 'maximagesperarticle' => 10, 'meta_tags' => 0);
if (getenv('HAPPA_FAKE_XPOST_ENABLED') === '1') {
    // Geeklog's configuration with X-Post's inbox announcement switched on.
    class config { public static function get_instance() { return new self(); } public function get_config($group) { return $group === 'xpost' ? array('inbox_announce_enabled' => 1) : false; } }
}
$_TABLES = array('users' => 'users', 'stories' => 'stories', 'topics' => 'topics', 'topic_assignments' => 'topic_assignments', 'article_images' => 'article_images');
function fakeDb() { $f = __DIR__ . '/db.json'; return is_file($f) ? json_decode(file_get_contents($f), true) : array('stories' => array()); }
function fakeSave($db) { file_put_contents(__DIR__ . '/db.json', json_encode($db)); }
function DB_escapeString($v) { return addslashes($v); }
function DB_query($sql) {
    $db = fakeDb();
    if (strpos($sql, 'FROM users') !== false) { return array(array('uid' => 2, 'status' => 3)); }
    if (preg_match("/FROM topics WHERE tid = '([^']+)'/", $sql, $m)) { return array(array('tid' => $m[1], 'owner_id' => 2, 'group_id' => 1, 'perm_owner' => 3, 'perm_group' => 3, 'perm_members' => 2, 'perm_anon' => 2, 'archive_flag' => 0)); }
    if (preg_match("/FROM topic_assignments WHERE .* id = '([^']+)'/", $sql, $m)) { return isset($db['stories'][$m[1]]) ? array(array('tid' => $db['stories'][$m[1]]['tid'])) : array(); }
    if (preg_match("/FROM stories WHERE sid = '([^']+)'/", $sql, $m)) { return isset($db['stories'][$m[1]]) ? array($db['stories'][$m[1]] + array('sid' => $m[1])) : array(); }
    throw new RuntimeException('Unexpected query: ' . $sql);
}
function DB_fetchArray(&$result) { return array_shift($result) ?: false; }
function DB_count($table, $field, $value) {
    $db = fakeDb();
    if ($table === 'article_images') { return isset($db['stories'][$value]) ? $db['stories'][$value]['images'] : 0; }
    return isset($db['stories'][$value]) ? 1 : 0;
}
function SEC_encryptUserPassword($password, $uid) { return $password === 'secret' ? 0 : 1; }
function SESS_getUserDataFromId($uid) { return array('uid' => $uid); }
function SEC_getUserGroups($uid) { return array(); }
function SEC_getUserPermissions($a, $uid) { return 'story.edit'; }
function SEC_hasRights($right) { return true; }
function SEC_hasAccess() { return 3; }
function service_submit_story($args, &$output, &$svc_msg) {
    if (getenv('HAPPA_FAKE_FAIL_SID') === $args['sid']) { $svc_msg['error_desc'] = 'stand-in save refused'; return PLG_RET_ERROR; }
    $db = fakeDb();
    $count = 0;
    foreach ($_FILES as $file) { $count++; copy($file['tmp_name'], __DIR__ . '/images/articles/' . $args['sid'] . '_' . $count . '.jpg'); }
    $db['stories'][$args['sid']] = array('tid' => $args['category'][0], 'draft_flag' => (int) $args['draft_flag'], 'bodytext' => $args['bodytext'], 'images' => $count);
    fakeSave($db);
    // Geeklog 2.2.2: PLG_afterSaveSwitch() -> COM_redirect() -> die(1).
    echo "<html><head><meta http-equiv=\"refresh\" content=\"0; URL=/article.php?story={$args['sid']}\"></head></html>\n";
    die(1);
}
PHP;
file_put_contents($site . '/lib-common.php', $fake);
file_put_contents($site . '/lib-article.php', '<?php');
putenv('HAPPA_IMPORT_SITE_ROOT=' . $site);

function makePackage($import, $name, $articles, $jpegData, array $top = array())
{
    $dir = $import . '/inbox/ready/' . $name;
    mkdir($dir . '/images', 0777, true);
    foreach ($articles as $article) {
        foreach ($article['images'] as $image) { file_put_contents($dir . '/' . $image, $jpegData); }
    }
    file_put_contents($dir . '/article.json', json_encode(array('schema_version' => 1) + $top + array('articles' => $articles)));
}
// A minimal valid JPEG (1x1) so getimagesize() accepts it without the GD extension.
$jpegData = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');
$pair = array(
    array('sid' => 'devblog-t_ja', 'topic' => 'Blog_ja', 'language' => 'ja', 'title' => 'T', 'publish' => true, 'introtext' => '<p>j [image1]</p>', 'bodytext' => '', 'images' => array('images/ja-1.jpg', 'images/ja-2.jpg')),
    array('sid' => 'devblog-t_en', 'topic' => 'Blog_en', 'language' => 'en', 'title' => 'T', 'publish' => true, 'introtext' => '<p>e [image1]</p>', 'bodytext' => '', 'images' => array('images/en-1.jpg')),
);
makePackage($import, 'pair-package', $pair, $jpegData);

function runImporter($import)
{
    $command = array(PHP_BINARY, '-n', '-f', $import . '/run-import.php');
    return importerTestRun($command, $import);
}
function importerTestRun($command, $cwd)
{
    $process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $cwd, null, array('bypass_shell' => true));
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return array(proc_close($process), $out, $err);
}

list($code, $out, $err) = runImporter($import);
$db = json_decode(file_get_contents($site . '/db.json'), true);
$log = implode('', array_map('file_get_contents', glob($import . '/logs/*.log')));
expect($code === 0, 'The importer must finish normally: exit ' . $code . ' ' . $out . $err . $log);
expect(isset($db['stories']['devblog-t_ja'], $db['stories']['devblog-t_en']), 'Both articles must be saved.');
expect($db['stories']['devblog-t_ja']['images'] === 2 && $db['stories']['devblog-t_en']['images'] === 1, 'Images must be attached.');
expect(is_dir($import . '/inbox/done/pair-package') && !is_dir($import . '/inbox/processing/pair-package'), 'The package must move to done.');
expect(strpos($log, 'Imported package pair-package; articles=2; published=2.') !== false, 'The completion must be logged: ' . $log);
expect(substr_count($log, 'ARTICLE_SAVED:') === 2, 'Each article must be verified.');
expect(count(glob($site . '/data/happa-import-work-*')) === 0, 'Staged image copies must be removed.');
$result = json_decode(file_get_contents($import . '/inbox/done/pair-package/import-result.json'), true);
expect($result['status'] === 'completed' && !array_key_exists('x_announcement', $result) && strpos($log, 'X_ANNOUNCEMENT') === false, 'Without announce_on_x the result and log are unchanged: ' . json_encode($result));
echo "PASS a Japanese/English pair is fully imported although each save ends its process with die(1).\n";

// A save that Geeklog refuses moves the package to failed and records what was completed.
putenv('HAPPA_FAKE_FAIL_SID=devblog-f_en');
makePackage($import, 'fail-package', array(
    array('sid' => 'devblog-f_ja', 'topic' => 'Blog_ja', 'language' => 'ja', 'title' => 'F', 'publish' => true, 'introtext' => '<p>j</p>', 'bodytext' => '', 'images' => array()),
    array('sid' => 'devblog-f_en', 'topic' => 'Blog_en', 'language' => 'en', 'title' => 'F', 'publish' => true, 'introtext' => '<p>e</p>', 'bodytext' => '', 'images' => array()),
), $jpegData);
list($code, $out, $err) = runImporter($import);
putenv('HAPPA_FAKE_FAIL_SID');
$errorText = @file_get_contents($import . '/inbox/failed/fail-package/import-error.txt');
expect($code === 1 && is_dir($import . '/inbox/failed/fail-package'), 'A refused save must fail the package.');
expect(strpos($errorText, 'stand-in save refused') !== false && strpos($errorText, 'devblog-f_ja') !== false, 'The error and the completed article must be recorded: ' . $errorText);
expect(count(glob($site . '/data/happa-import-work-*')) === 0, 'Staged image copies must be removed after a failure.');
echo "PASS a refused save fails the package with the reason and the completed articles.\n";

// announce_on_x with the X-Post setting off: the import completes and the result says skipped.
$announced = array(
    array('sid' => 'devblog-x_ja', 'topic' => 'Blog_ja', 'language' => 'ja', 'title' => 'X', 'publish' => true, 'introtext' => '<p>j</p>', 'bodytext' => '', 'images' => array()),
    array('sid' => 'devblog-x_en', 'topic' => 'Blog_en', 'language' => 'en', 'title' => 'X', 'publish' => true, 'introtext' => '<p>e</p>', 'bodytext' => '', 'images' => array()),
);
makePackage($import, 'announce-off', $announced, $jpegData, array('announce_on_x' => true));
list($code, $out, $err) = runImporter($import);
$log = implode('', array_map('file_get_contents', glob($import . '/logs/*.log')));
$result = json_decode((string) @file_get_contents($import . '/inbox/done/announce-off/import-result.json'), true);
expect($code === 0 && $result['status'] === 'completed' && count($result['articles']) === 2, 'An announcing package imports normally: exit ' . $code . ' ' . $out . $err);
expect($result['x_announcement']['state'] === 'skipped' && strpos($result['x_announcement']['reason'], '記事取り込みからの告知を許可する') !== false && $result['x_announcement']['url'] === '', 'With the setting off x_announcement is skipped: ' . json_encode($result['x_announcement'], JSON_UNESCAPED_UNICODE));
expect(strpos($log, 'X_ANNOUNCEMENT_SKIPPED:') !== false && !is_file($site . '/data/xpost/history.json'), 'The skip is logged and nothing is recorded in X-Post.');
echo "PASS announce_on_x with the X-Post setting off imports and records skipped.\n";

// announce_on_x with the setting on but no readable X-Post credentials: failed, the import still completes.
putenv('HAPPA_FAKE_XPOST_ENABLED=1');
$announced[0]['sid'] = 'devblog-y_ja'; $announced[1]['sid'] = 'devblog-y_en';
makePackage($import, 'announce-denied', $announced, $jpegData, array('announce_on_x' => true, 'x_text' => 'text'));
list($code, $out, $err) = runImporter($import);
putenv('HAPPA_FAKE_XPOST_ENABLED');
$log = implode('', array_map('file_get_contents', glob($import . '/logs/*.log')));
$result = json_decode((string) @file_get_contents($import . '/inbox/done/announce-denied/import-result.json'), true);
expect($code === 0 && is_dir($import . '/inbox/done/announce-denied') && $result['status'] === 'completed', 'A failed announcement does not fail the import: exit ' . $code . ' ' . $out . $err);
expect($result['x_announcement']['state'] === 'failed' && strpos($result['x_announcement']['reason'], 'credentials.json') !== false, 'The missing permission is the reason: ' . json_encode($result['x_announcement'], JSON_UNESCAPED_UNICODE));
expect(strpos($log, 'X_ANNOUNCEMENT_FAILED:') !== false, 'The failure is logged.');
echo "PASS announce_on_x without X-Post access imports and records failed with the permission.\n";

// A package with an invalid announce_on_x is refused before any article is saved.
makePackage($import, 'announce-invalid', array(array('sid' => 'devblog-z_ja', 'topic' => 'Blog_ja', 'language' => 'ja', 'title' => 'Z', 'publish' => true, 'introtext' => '<p>z</p>', 'bodytext' => '', 'images' => array())), $jpegData, array('announce_on_x' => 'yes'));
list($code) = runImporter($import);
$db = json_decode(file_get_contents($site . '/db.json'), true);
expect($code === 1 && is_dir($import . '/inbox/failed/announce-invalid') && !isset($db['stories']['devblog-z_ja']), 'An invalid announce_on_x fails validation before saving.');
echo "PASS an invalid announce_on_x is refused before any article is saved.\n";

// A title that Geeklog would cut at 128 bytes (devblog-068: 132 bytes) is refused before any article is saved.
$longTitle = 'DEVELOPMENT SNAPSHOT #68 — 国の顔ぶれを1つの窓に：人事を内政管理へ統合し、任命の予約を差し替える';
makePackage($import, 'title-too-long', array(
    array('sid' => 'devblog-l_ja', 'topic' => 'Blog_ja', 'language' => 'ja', 'title' => $longTitle, 'publish' => true, 'introtext' => '<p>j</p>', 'bodytext' => '', 'images' => array()),
    array('sid' => 'devblog-l_en', 'topic' => 'Blog_en', 'language' => 'en', 'title' => 'L', 'publish' => true, 'introtext' => '<p>e</p>', 'bodytext' => '', 'images' => array()),
), $jpegData, array('announce_on_x' => true));
list($code) = runImporter($import);
$db = json_decode(file_get_contents($site . '/db.json'), true);
$errorText = (string) @file_get_contents($import . '/inbox/failed/title-too-long/import-error.txt');
expect($code === 1 && is_dir($import . '/inbox/failed/title-too-long') && !isset($db['stories']['devblog-l_ja']) && !isset($db['stories']['devblog-l_en']), 'A too-long title fails validation before any article is saved.');
expect(strpos($errorText, 'Article 1 title is 132 bytes') !== false, 'The error names the article and the byte length: ' . $errorText);

// HTML escaping counts: 125 bytes plus "&" (stored as "&amp;") is 129 bytes and is refused.
makePackage($import, 'title-escaped-too-long', array(
    array('sid' => 'devblog-m_en', 'topic' => 'Blog_en', 'language' => 'en', 'title' => str_repeat('a', 124) . '&', 'publish' => true, 'introtext' => '<p>e</p>', 'bodytext' => '', 'images' => array()),
), $jpegData);
list($code) = runImporter($import);
$errorText = (string) @file_get_contents($import . '/inbox/failed/title-escaped-too-long/import-error.txt');
expect($code === 1 && strpos($errorText, 'is 129 bytes after HTML escaping') !== false, 'The escaped length is checked: ' . $errorText);

// Exactly 128 bytes (42 Japanese characters and "ab") still imports.
$exactTitle = str_repeat('あ', 42) . 'ab';
makePackage($import, 'title-exact', array(
    array('sid' => 'devblog-e_ja', 'topic' => 'Blog_ja', 'language' => 'ja', 'title' => $exactTitle, 'publish' => true, 'introtext' => '<p>j</p>', 'bodytext' => '', 'images' => array()),
), $jpegData);
list($code) = runImporter($import);
$db = json_decode(file_get_contents($site . '/db.json'), true);
expect(strlen($exactTitle) === 128 && $code === 0 && isset($db['stories']['devblog-e_ja']), 'A 128-byte title is accepted.');
echo "PASS titles longer than 128 bytes after HTML escaping are refused before saving; 128 bytes is accepted.\n";

// The empty queue still ends quietly.
list($code) = runImporter($import);
expect($code === 0, 'An empty queue must exit 0.');
echo "PASS an empty queue exits normally.\n";

$remove = function ($path) use (&$remove) {
    if (is_dir($path) && !is_link($path)) { foreach (array_diff(scandir($path), array('.', '..')) as $name) { $remove($path . '/' . $name); } rmdir($path); }
    elseif (file_exists($path) || is_link($path)) { unlink($path); }
};
$remove($root);
