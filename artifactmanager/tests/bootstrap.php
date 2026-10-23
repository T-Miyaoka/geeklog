<?php
// Minimal Geeklog stand-ins for running the plugin code from the command line.
$_SERVER['PHP_SELF'] = '/test.php';
$amTestRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'am-test-' . bin2hex(random_bytes(4));
mkdir($amTestRoot . '/site/ashwind-build/settings', 0777, true);
mkdir($amTestRoot . '/site/public_html', 0777, true);
mkdir($amTestRoot . '/dist', 0777, true);
$_CONF = array('path' => $amTestRoot . '/site/', 'path_html' => $amTestRoot . '/site/public_html/', 'language' => 'japanese_utf-8');
$_TABLES = array('plugins' => 'test_plugins', 'downloadcategories' => 'test_dlcat');
$_PLUGINS = array('downloads');
$amCategories = array(array('cid' => 'ashwind', 'title' => 'Ashwind Chronicle'), array('cid' => '12', 'title' => 'Tools'));
$amLog = array();

class config
{
    private static $instance;
    public $config_array = array();
    public $added = array();
    public static function get_instance() { return self::$instance ?: (self::$instance = new config()); }
    public static function reset() { self::$instance = new config(); }
    public function group_exists($group) { return array_key_exists($group, $this->config_array); }
    public function &get_config($group) { $f = false; if (array_key_exists($group, $this->config_array)) return $this->config_array[$group]; return $f; }
    public function add($name, $default, $type, $sg, $fs = null, $sel = null, $sort = 0, $set = true, $group = 'Core', $tab = null)
    {
        $this->added[] = array($name, $type, $fs, $sel, $sort, $group, $tab);
        if ($type === 'subgroup' || $type === 'tab') return;
        if ($set) $this->config_array[$group][$name] = $default;
    }
}
function COM_errorLog($m) { global $amLog; $amLog[] = $m; }
$amQueries = array(); $amDbError = false; $amPluginVersion = '1.1.0'; $amSkipUpdate = false;
function DB_query($sql, $ignore = 0)
{
    global $amCategories, $amQueries, $amDbError, $amPluginVersion, $amSkipUpdate, $_ARTIFACTMANAGER_CONF;
    if (strpos($sql, 'UPDATE') === 0) {
        $amQueries[] = $sql;
        if (!$amDbError && !$amSkipUpdate) $amPluginVersion = $_ARTIFACTMANAGER_CONF['pi_version'];
        return !$amDbError;
    }
    return array('rows' => $amCategories);
}
function DB_getItem($table, $field, $where) { global $amPluginVersion; return $amPluginVersion; }
function DB_fetchArray(&$result) { if (!is_array($result) || !$result['rows']) return false; return array_shift($result['rows']); }
function DB_error() { global $amDbError; return $amDbError; }
function DB_escapeString($s) { return addslashes($s); }
function check($ok, $label) { if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } echo "PASS: $label\n"; }
function am_test_rmtree($p) { if (is_file($p) || is_link($p)) return unlink($p); foreach (array_diff(scandir($p), array('.', '..')) as $e) am_test_rmtree("$p/$e"); return rmdir($p); }
register_shutdown_function(function () use ($amTestRoot) { am_test_rmtree($amTestRoot); });
