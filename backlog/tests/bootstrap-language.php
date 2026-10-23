<?php
require __DIR__ . '/work-history.php';
$fixture = getenv('CODEX_BACKLOG_TEST_DIR');
if (!$fixture || strpos(str_replace('\\', '/', $fixture), 'E:/Codex/') !== 0) {
    throw new RuntimeException('Set CODEX_BACKLOG_TEST_DIR under E:/Codex.');
}
if (!is_dir($fixture)) { mkdir($fixture, 0750, true); }
file_put_contents($fixture . '/lib-common.php', '<?php $_CONF = array("timezone" => "Asia/Tokyo", "path_system" => __DIR__ . "/"); $LANG24 = array(70 => "shared-toolbar"); $LANG32 = array(9 => "shared-language"); (function () { global $LANG24, $LANG32; if (($LANG32[9] ?? null) !== "shared-language" || ($LANG24[70] ?? null) !== "shared-toolbar") { throw new RuntimeException("Plugin language cannot read the shared catalogue"); } })();');
file_put_contents($fixture . '/lib-article.php', '<?php');
define('HAPPA_SITE_ROOT', $fixture);
define('HAPPA_IMPORT_ROOT', $fixture);
$importer = file_get_contents(__DIR__ . '/../../happa-article-inbox/run-import.php');
loadFunction($importer, 'bootstrapGeeklog');
bootstrapGeeklog();
expect($GLOBALS['LANG32'][9] === 'shared-language', 'Language catalogue must remain global after bootstrap.');
echo "PASS actual CLI bootstrap shares the core language catalogue with plugins.\n";
