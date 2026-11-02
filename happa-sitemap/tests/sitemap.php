<?php
// Run from the repository root: php -n happa-sitemap/tests/sitemap.php
// Builds the sitemap from stories, then runs the CLI end to end against a stand-in Geeklog,
// and has the article importer start it on schedule and on request.

$count = 0;
function expect($condition, $message) { global $count; if (!$condition) { throw new RuntimeException('FAIL: ' . $message); } $count++; echo "PASS $message\n"; }

define('HAPPA_SITEMAP_LIBRARY_ONLY', true);
require __DIR__ . '/../build-sitemap.php';

// 1. Building: fixed pages, story editions linked by hreflang, a lone edition without alternates.
$xml = happaSitemapBuild('https://www.happa.bz/', array(
    array('sid' => 'devblog-076-regiment_ja', 'lastmod' => '2026-11-01'),
    array('sid' => 'devblog-076-regiment_en', 'lastmod' => '2026-11-01'),
    array('sid' => 'devblog-050-only_ja', 'lastmod' => '2026-10-01'),
    array('sid' => 'about&more', 'lastmod' => '2026-09-01'),
));
/** The <url> block whose <loc> is $loc, or '' when it is not listed. */
function urlBlock($xml, $loc)
{
    $needle = '<loc>' . htmlspecialchars($loc, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</loc>';
    $at = strpos($xml, $needle);
    if ($at === false) { return ''; }
    $open = strrpos(substr($xml, 0, $at), '<url>');
    return substr($xml, $open, strpos($xml, '</url>', $at) - $open);
}
expect(substr_count($xml, '<url>') === 14 && substr_count($xml, '</url>') === 14, 'ten fixed pages and four stories are listed');
expect(strpos($xml, '<?xml version="1.0" encoding="UTF-8"?>') === 0 && substr($xml, -10) === "</urlset>\n", 'the sitemap starts with the XML declaration and ends with urlset');
expect(strpos($xml, '<loc>https://www.happa.bz/?lang=ja</loc>') < strpos($xml, 'article.php') && urlBlock($xml, 'https://www.happa.bz/contact.php?lang=en') !== '', 'the fixed pages come first and keep their ?lang editions');
$block = urlBlock($xml, 'https://www.happa.bz/article.php?story=devblog-076-regiment_en');
expect(substr_count($block, '<xhtml:link rel="alternate"') === 2 && strpos($block, 'hreflang="ja" href="https://www.happa.bz/article.php?story=devblog-076-regiment_ja"') !== false, 'both editions of a story are linked with hreflang');
$block = urlBlock($xml, 'https://www.happa.bz/article.php?story=devblog-050-only_ja');
expect($block !== '' && strpos($block, 'xhtml:link') === false, 'an edition without its pair has no alternates');
expect(strpos($block, '<lastmod>2026-10-01</lastmod>') !== false, 'lastmod is the story date');
expect(urlBlock($xml, 'https://www.happa.bz/article.php?story=about%26more') !== '' && strpos($xml, '&more') === false, 'sids are URL-encoded and XML-escaped');
expect(strpos(urlBlock($xml, 'https://www.happa.bz/?lang=en'), 'hreflang="x-default"') !== false, 'the top page keeps its x-default');
expect(strpos(urlBlock($xml, 'https://www.happa.bz/index.php?topic=Blog_en'), 'hreflang="ja" href="https://www.happa.bz/index.php?topic=Blog_ja"') !== false, 'the blog lists of both languages are linked');

// 2. End to end against a stand-in Geeklog: only readable stories, write only on change.
$root = sys_get_temp_dir() . '/happa-sitemap-test-' . getmypid() . '-' . bin2hex(random_bytes(4));
$site = $root . '/site';
$builder = $root . '/happa-sitemap';
mkdir($site . '/public_html', 0777, true);
mkdir($builder, 0777, true);
copy(__DIR__ . '/../build-sitemap.php', $builder . '/build-sitemap.php');
$fake = <<<'PHP'
<?php
$_CONF = array('site_url' => 'https://www.happa.bz', 'path_html' => __DIR__ . '/', 'timezone' => 'Asia/Tokyo');
$_TABLES = array('stories' => 'gl_stories');
function DB_query($sql)
{
    file_put_contents(__DIR__ . '/query.txt', $sql);
    // The stand-in database already applies the WHERE clause the builder sends.
    $rows = json_decode(file_get_contents(__DIR__ . '/stories.json'), true);
    return new ArrayIterator($rows);
}
function DB_fetchArray($result)
{
    if (!$result->valid()) { return false; }
    $row = $result->current();
    $result->next();
    return $row;
}
PHP;
file_put_contents($site . '/public_html/lib-common.php', $fake);
file_put_contents($site . '/public_html/stories.json', json_encode(array(
    array('sid' => 'devblog-002-x_ja', 'date' => '2026-10-02 10:00:00', 'modified' => '2026-10-05 09:00:00'),
    array('sid' => 'devblog-002-x_en', 'date' => '2026-10-02 10:00:00', 'modified' => null),
)));

function runBuilder($builder, $site)
{
    $command = escapeshellarg(PHP_BINARY) . ' -n -f ' . escapeshellarg($builder . '/build-sitemap.php');
    $env = 'HAPPA_SITEMAP_SITE_ROOT=' . escapeshellarg($site . '/public_html') . ' ';
    exec($env . $command . ' 2>&1', $output, $code);
    return array($code, implode("\n", $output));
}

list($code, $output) = runBuilder($builder, $site);
expect($code === 0 && strpos($output, 'SITEMAP_UPDATED: stories=2') !== false, 'the CLI writes the sitemap');
$sql = file_get_contents($site . '/public_html/query.txt');
expect(strpos($sql, 'draft_flag = 0') !== false && strpos($sql, 'date <= NOW()') !== false && strpos($sql, 'perm_anon >= 2') !== false, 'only published stories readable by anonymous visitors are read');
$written = file_get_contents($site . '/public_html/sitemap.xml');
expect(strpos($written, '<lastmod>2026-10-05</lastmod>') !== false, 'a later modified date becomes lastmod');
$record = json_decode(file_get_contents($builder . '/data/last-run.json'), true);
expect($record['status'] === 'completed' && $record['stories'] === 2 && $record['changed'] === true, 'the run is recorded');
touch($site . '/public_html/sitemap.xml', time() - 1000);
$before = filemtime($site . '/public_html/sitemap.xml');
list($code, $output) = runBuilder($builder, $site);
clearstatcache();
expect($code === 0 && strpos($output, 'SITEMAP_UNCHANGED') !== false && filemtime($site . '/public_html/sitemap.xml') === $before, 'an unchanged sitemap is not rewritten');
expect(count(glob($site . '/public_html/sitemap.xml.tmp-*')) === 0, 'no temporary file is left behind');
rename($site . '/public_html/lib-common.php', $site . '/public_html/lib-common.off');
list($code, $output) = runBuilder($builder, $site);
$record = json_decode(file_get_contents($builder . '/data/last-run.json'), true);
expect($code === 1 && $record['status'] === 'failed' && strpos($record['error'], 'GEEKLOG_BOOTSTRAP_FAILED') === 0, 'a failure exits 1 and is recorded');
expect(file_get_contents($site . '/public_html/sitemap.xml') === $written, 'a failure keeps the previous sitemap');
rename($site . '/public_html/lib-common.off', $site . '/public_html/lib-common.php');

// 3. The importer starts the builder when it is due and on request.
putenv('HAPPA_IMPORT_SITEMAP_ROOT=' . $builder);
putenv('HAPPA_SITEMAP_SITE_ROOT=' . $site . '/public_html');
define('HAPPA_ARTICLE_LIBRARY_ONLY', true);
// A copy beside the builder, so the importer's logs go to the test folder.
mkdir($root . '/happa-article-inbox', 0777, true);
copy(__DIR__ . '/../../happa-article-inbox/run-import.php', $root . '/happa-article-inbox/run-import.php');
require $root . '/happa-article-inbox/run-import.php';
@unlink($builder . '/data/last-attempt');
expect(importerSitemapDue(), 'the sitemap is due when it was never attempted');
unlink($site . '/public_html/sitemap.xml');
importerRefreshSitemap('test');
expect(is_file($site . '/public_html/sitemap.xml') && is_file($builder . '/data/last-attempt'), 'the importer rebuilds the sitemap and stamps the attempt');
expect(!importerSitemapDue(), 'the sitemap is not due again within a day');
touch($builder . '/rebuild-requested');
expect(importerSitemapDue(), 'a rebuild request makes it due');
importerRefreshSitemap('test');
expect(!is_file($builder . '/rebuild-requested') && !importerSitemapDue(), 'the request is consumed');
touch($builder . '/data/last-attempt', time() - 90000);
expect(importerSitemapDue(), 'it is due again after a day');
putenv('HAPPA_IMPORT_SITEMAP_ROOT=' . $root . '/missing');
expect(!importerSitemapDue(), 'nothing is due when the builder is not installed');

echo "OK $count checks\n";
