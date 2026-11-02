<?php
/**
 * Happa sitemap builder (CLI only). Compatible with PHP 7.4 and Geeklog 2.2.2.
 *
 * Writes public_html/sitemap.xml with the fixed pages (top, blog lists, shop, downloads, contact)
 * and every story an anonymous visitor can read. Japanese and English editions of the
 * same story (sids ending in _ja / _en) are linked with hreflang alternates.
 * Reads the database only; the file is replaced only when its content changes.
 * Started by the article inbox importer after an import and once a day.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

define('HAPPA_SITEMAP_ROOT', __DIR__);
// HAPPA_SITEMAP_SITE_ROOT is only for tests (a stand-in Geeklog); the server does not set it.
if (!defined('HAPPA_SITEMAP_SITE_ROOT')) {
    define('HAPPA_SITEMAP_SITE_ROOT', getenv('HAPPA_SITEMAP_SITE_ROOT') ?: 'C:/inetpub/wwwroot/geeklog/2_2_2/public_html');
}

/** Pages that are not stories: [Japanese edition, English edition, changefreq, priority, x-default]. */
function happaSitemapFixedPages()
{
    return array(
        array('/?lang=ja', '/?lang=en', 'weekly', '1.0', true),
        array('/index.php?topic=Blog_ja', '/index.php?topic=Blog_en', 'weekly', '0.9', false),
        array('/myshop/index.php?lang=ja', '/myshop/index.php?lang=en', 'weekly', '0.7', false),
        array('/downloads/index.php?lang=ja', '/downloads/index.php?lang=en', 'monthly', '0.6', false),
        array('/contact.php?lang=ja', '/contact.php?lang=en', 'yearly', '0.4', false),
    );
}

function happaSitemapEscape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/** "base_ja" -> array("base", "ja"); a sid without a language suffix -> array(sid, ""). */
function happaSitemapSplitLanguage($sid)
{
    if (preg_match('/^(.+)_(ja|en)$/', $sid, $match)) {
        return array($match[1], $match[2]);
    }
    return array($sid, '');
}

/**
 * Builds the sitemap XML.
 * $stories: list of array('sid' => string, 'lastmod' => 'YYYY-MM-DD'), newest first.
 */
function happaSitemapBuild($siteUrl, array $stories)
{
    $siteUrl = rtrim($siteUrl, '/');
    $lines = array(
        '<?xml version="1.0" encoding="UTF-8"?>',
        '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"',
        '        xmlns:xhtml="http://www.w3.org/1999/xhtml">',
    );
    $entry = function ($loc, array $alternates, $lastmod, $changefreq, $priority) use (&$lines) {
        $lines[] = '  <url>';
        $lines[] = '    <loc>' . happaSitemapEscape($loc) . '</loc>';
        foreach ($alternates as $language => $href) {
            $lines[] = '    <xhtml:link rel="alternate" hreflang="' . happaSitemapEscape($language) . '" href="' . happaSitemapEscape($href) . '" />';
        }
        if ($lastmod !== '') {
            $lines[] = '    <lastmod>' . happaSitemapEscape($lastmod) . '</lastmod>';
        }
        $lines[] = '    <changefreq>' . $changefreq . '</changefreq>';
        $lines[] = '    <priority>' . $priority . '</priority>';
        $lines[] = '  </url>';
    };

    foreach (happaSitemapFixedPages() as $page) {
        list($jaPath, $enPath, $changefreq, $priority, $withDefault) = $page;
        $ja = $siteUrl . $jaPath;
        $en = $siteUrl . $enPath;
        $alternates = array('ja' => $ja, 'en' => $en);
        if ($withDefault) {
            $alternates['x-default'] = $ja;
        }
        $entry($ja, $alternates, '', $changefreq, $priority);
        $entry($en, $alternates, '', $changefreq, $priority);
    }

    $bySid = array();
    foreach ($stories as $story) {
        $bySid[$story['sid']] = $story;
    }
    foreach ($stories as $story) {
        $sid = $story['sid'];
        $loc = $siteUrl . '/article.php?story=' . rawurlencode($sid);
        list($base, $language) = happaSitemapSplitLanguage($sid);
        $alternates = array();
        if ($language !== '') {
            foreach (array('ja', 'en') as $edition) {
                if (isset($bySid[$base . '_' . $edition])) {
                    $alternates[$edition] = $siteUrl . '/article.php?story=' . rawurlencode($base . '_' . $edition);
                }
            }
            if (count($alternates) < 2) {
                $alternates = array();
            }
        }
        $entry($loc, $alternates, $story['lastmod'], 'monthly', '0.8');
    }
    $lines[] = '</urlset>';
    return implode("\n", $lines) . "\n";
}

/** Writes $content to $path through a temporary file. Returns true when the file changed. */
function happaSitemapWrite($path, $content)
{
    if (is_file($path) && (string) @file_get_contents($path) === $content) {
        return false;
    }
    $temporary = $path . '.tmp-' . getmypid();
    if (@file_put_contents($temporary, $content) === strlen($content) && @rename($temporary, $path)) {
        return true;
    }
    @unlink($temporary);
    // The task account may be allowed to change sitemap.xml but not to create files beside it.
    if (is_file($path) && @file_put_contents($path, $content, LOCK_EX) === strlen($content)) {
        return true;
    }
    throw new RuntimeException('SITEMAP_WRITE_FAILED: the sitemap could not be written (check the task account\'s rights on public_html\\sitemap.xml).');
}

function happaSitemapBootstrapGeeklog()
{
    global $_CONF, $_TABLES, $_USER, $_GROUPS, $_RIGHTS, $_LOCALE, $_SCRIPTS,
        $_DEVICE, $_URL,
        $_TOPICS, $_STRUCT_DATA, $_DB_dbms, $_COM_VERBOSE, $TEMPLATE_OPTIONS,
        $LANG24, $LANG32, $LANG_ISO639_1, $LANG_MONTH, $LANG_MONTH_SHORT, $LANG_WEEK,
        $LANG_WEEK_SHORT, $LANG_AMPM, $LANG_dateformats;

    if (!is_file(HAPPA_SITEMAP_SITE_ROOT . '/lib-common.php')) {
        throw new RuntimeException('GEEKLOG_BOOTSTRAP_FAILED: lib-common.php was not found at the configured server path.');
    }
    $_SERVER['PHP_SELF'] = HAPPA_SITEMAP_ROOT . '/build-sitemap.php';
    $_SERVER['SCRIPT_NAME'] = '/happa-sitemap/build-sitemap.php';
    $_SERVER['REQUEST_URI'] = $_SERVER['SCRIPT_NAME'];
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['HTTP_USER_AGENT'] = 'Happa-Sitemap-CLI/1.0';
    $_SERVER['REQUEST_METHOD'] = 'CLI';
    require_once HAPPA_SITEMAP_SITE_ROOT . '/lib-common.php';
    if (!empty($_CONF['timezone'])) {
        date_default_timezone_set($_CONF['timezone']);
    }
}

/** Stories an anonymous visitor can read: published, not drafts, readable by anonymous users. */
function happaSitemapReadStories()
{
    global $_TABLES;
    $result = DB_query("SELECT sid, date, modified FROM {$_TABLES['stories']}"
        . " WHERE draft_flag = 0 AND date <= NOW() AND perm_anon >= 2 ORDER BY date DESC, sid ASC");
    $stories = array();
    while ($row = DB_fetchArray($result)) {
        $sid = (string) $row['sid'];
        if ($sid === '') {
            continue;
        }
        $stamp = (string) $row['date'];
        if (!empty($row['modified']) && strcmp((string) $row['modified'], $stamp) > 0) {
            $stamp = (string) $row['modified'];
        }
        $stories[] = array('sid' => $sid, 'lastmod' => substr($stamp, 0, 10));
    }
    return $stories;
}

function happaSitemapRecord(array $record)
{
    $folder = HAPPA_SITEMAP_ROOT . '/data';
    if (!is_dir($folder)) {
        @mkdir($folder, 0750, true);
    }
    $record['finished_at'] = date('c');
    @file_put_contents($folder . '/last-run.json', json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function happaSitemapMain()
{
    global $_CONF;
    try {
        happaSitemapBootstrapGeeklog();
        $stories = happaSitemapReadStories();
        $content = happaSitemapBuild($_CONF['site_url'], $stories);
        $path = rtrim($_CONF['path_html'], '/\\') . '/sitemap.xml';
        $changed = happaSitemapWrite($path, $content);
        $sids = array_map(function ($story) { return $story['sid']; }, $stories);
        happaSitemapRecord(array('status' => 'completed', 'changed' => $changed, 'stories' => count($stories), 'sids' => $sids));
        echo 'SITEMAP_' . ($changed ? 'UPDATED' : 'UNCHANGED') . ': stories=' . count($stories) . PHP_EOL;
        exit(0);
    } catch (Throwable $error) {
        $message = $error instanceof RuntimeException ? $error->getMessage()
            : 'SITEMAP_FAILED: ' . get_class($error) . ' at ' . basename($error->getFile()) . ':' . (int) $error->getLine();
        happaSitemapRecord(array('status' => 'failed', 'error' => $message));
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

if (!defined('HAPPA_SITEMAP_LIBRARY_ONLY')) {
    happaSitemapMain();
}
