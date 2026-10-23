<?php
if (stripos($_SERVER['PHP_SELF'], basename(__FILE__)) !== false) {
    die('This file can not be used on its own.');
}

require_once __DIR__ . '/config.php';

global $_CONF, $LANG_configsections, $LANG_confignames, $LANG_configsubgroups, $LANG_tab, $LANG_fs, $LANG_configselects, $LANG_ARTIFACTMANAGER;
require_once __DIR__ . '/language/'
    . (isset($_CONF['language']) && strpos((string) $_CONF['language'], 'japanese') === 0 ? 'japanese_utf-8.php' : 'english.php');

define('ARTIFACTMANAGER_CONFIG_GROUP', 'artifactmanager');

function plugin_chkVersion_artifactmanager()
{
    global $_ARTIFACTMANAGER_CONF;
    return $_ARTIFACTMANAGER_CONF['pi_version'];
}

function plugin_upgrade_artifactmanager()
{
    global $_TABLES, $_ARTIFACTMANAGER_CONF;
    require_once __DIR__ . '/install_defaults.php';
    // 1.2.0: the settings moved to Geeklog's configuration screen (values migrated from build-settings.json).
    if (!plugin_initconfig_artifactmanager()) { return false; }
    $version = DB_escapeString($_ARTIFACTMANAGER_CONF['pi_version']);
    DB_query("UPDATE {$_TABLES['plugins']} SET pi_version = '$version' WHERE pi_name = 'artifactmanager'", 1);
    if (DB_error()) { return false; }
    return DB_getItem($_TABLES['plugins'], 'pi_version', "pi_name = 'artifactmanager'") === $_ARTIFACTMANAGER_CONF['pi_version'];
}

function plugin_autouninstall_artifactmanager()
{
    return array(
        'tables' => array(),
        'groups' => array('Artifact Manager Admin'),
        'features' => array('artifactmanager.admin'),
    );
}

function plugin_cclabel_artifactmanager()
{
    return 'Ashwind配信管理';
}

function plugin_getadminoption_artifactmanager()
{
    global $_CONF;
    if (!SEC_hasRights('artifactmanager.admin')) {
        return false;
    }
    return array(
        'Ashwind配信管理',
        $_CONF['site_admin_url'] . '/plugins/artifactmanager/index.php',
        '',
    );
}

/** Called by Geeklog after the configuration screen saved changes. */
function plugin_configchange_artifactmanager($group, $changes = array())
{
    if ($group !== ARTIFACTMANAGER_CONFIG_GROUP) { return; }
    try {
        am_sync_build_settings();
    } catch (Throwable $e) {
        COM_errorLog('Artifact Manager: ' . $e->getMessage());
    }
}

/** Options of the "download_category" select in the configuration screen (label => cid). */
function plugin_configmanager_select_download_category_artifactmanager()
{
    global $LANG_ARTIFACTMANAGER;
    $options = array($LANG_ARTIFACTMANAGER['no_download_registration'] => '');
    foreach (am_download_categories() as $cid => $title) {
        $label = $title;
        for ($i = 2; isset($options[$label]); $i++) { $label = $title . ' (' . $i . ')'; }
        $options[$label] = (string) $cid;
    }
    return $options;
}

// ---------------------------------------------------------------------------
// Shared helpers (admin page, build tab, configuration screen and its validation)
// ---------------------------------------------------------------------------

function am_load_json($path, array $fallback = array())
{
    if (!is_file($path)) return $fallback;
    $value = json_decode((string) @file_get_contents($path), true);
    return is_array($value) ? $value : $fallback;
}

function am_save_json($path, array $value)
{
    $temp = $path . '.tmp-' . bin2hex(random_bytes(4));
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (@file_put_contents($temp, $json, LOCK_EX) === false || !@rename($temp, $path)) {
        @unlink($temp);
        throw new RuntimeException('設定ファイルを保存できませんでした。');
    }
}

function am_is_absolute_path($path)
{
    return (bool) preg_match('/^[A-Za-z]:[\\\\\/]/', $path) || substr($path, 0, 2) === '\\\\';
}

function am_normalize_dir($path)
{
    $path = trim((string) $path, " \t\n\r\0\x0B\"'");
    if ($path === '' || strpos($path, "\0") !== false) throw new RuntimeException('配信元フォルダを入力してください。');
    if (!am_is_absolute_path($path)) throw new RuntimeException('配信元フォルダは絶対パスで指定してください。');
    if (preg_match('/(^|[\\\\\/])\.\.([\\\\\/]|$)/', $path)) throw new RuntimeException('配信元フォルダに「..」は使えません。');
    return rtrim(str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
}

/** Normalizes the distribution folder and requires it to exist (configuration screen). */
function am_validate_distribution_dir($path)
{
    $dir = am_normalize_dir($path);
    if (!is_dir($dir)) throw new RuntimeException('配信元フォルダが見つかりません: ' . $dir);
    return $dir;
}

function am_build_root()
{
    global $_CONF;
    return rtrim($_CONF['path'], '/\\') . DIRECTORY_SEPARATOR . 'ashwind-build';
}

function am_build_path()
{
    return am_build_root() . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, func_get_args());
}

function am_default_signing_key_path()
{
    return str_replace('/', '\\', am_build_path('secrets', 'update-signing-private.pem'));
}

function am_path_key($path)
{
    return strtolower(rtrim(str_replace('/', '\\', (string) $path), '\\')) . '\\';
}

function am_path_inside($path, $parent)
{
    return strpos(am_path_key($path), am_path_key($parent)) === 0;
}

function am_validate_signing_key_path($path)
{
    global $_CONF;
    $path = trim((string) $path, " \t\n\r\0\x0B\"'");
    if ($path === '' || strpos($path, "\0") !== false) throw new RuntimeException('署名鍵の配置場所を入力してください。');
    if (!am_is_absolute_path($path)) throw new RuntimeException('署名鍵の配置場所は絶対パスで指定してください。');
    if (preg_match('/(^|[\\\\\/])\.\.([\\\\\/]|$)/', $path)) throw new RuntimeException('署名鍵の配置場所に「..」は使えません。');
    if (am_path_inside($path, $_CONF['path_html'])) throw new RuntimeException('署名鍵を公開フォルダ（public_html）の中に置くことはできません。');
    if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'pem') throw new RuntimeException('署名鍵は .pem ファイルを指定してください。');
    return str_replace('/', '\\', $path);
}

function am_validate_branch($branch)
{
    $branch = trim((string) $branch);
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,99}$/', $branch) || strpos($branch, '..') !== false) throw new RuntimeException('ブランチ名が正しくありません。');
    return $branch;
}

function am_download_categories()
{
    global $_TABLES, $_PLUGINS;
    $list = array();
    if (!is_array($_PLUGINS) || !in_array('downloads', $_PLUGINS) || empty($_TABLES['downloadcategories'])) return $list;
    $result = DB_query("SELECT cid, title FROM {$_TABLES['downloadcategories']} ORDER BY corder, title", 1);
    if (!$result) $result = DB_query("SELECT cid, title FROM {$_TABLES['downloadcategories']} ORDER BY title", 1);
    while ($result && ($row = DB_fetchArray($result))) $list[(string) $row['cid']] = (string) $row['title'];
    return $list;
}

function am_validate_download_category($cid)
{
    $cid = trim((string) $cid);
    if ($cid !== '' && !array_key_exists($cid, am_download_categories())) throw new RuntimeException('ダウンロード登録カテゴリが見つかりません。');
    return $cid;
}

function am_validate_list_limit($value)
{
    $value = trim((string) $value);
    if (!preg_match('/^\d{1,3}$/', $value) || (int) $value < 1 || (int) $value > 100) throw new RuntimeException('最大表示件数は 1〜100 の整数で指定してください。');
    return (int) $value;
}

/** True when every setting of config.php exists in the configuration group. */
function am_config_complete()
{
    global $_ARTIFACTMANAGER_DEFAULT;
    $stored = config::get_instance()->get_config(ARTIFACTMANAGER_CONFIG_GROUP);
    if (!is_array($stored)) return false;
    foreach ($_ARTIFACTMANAGER_DEFAULT as $key => $unused) if (!array_key_exists($key, $stored)) return false;
    return true;
}

/** Current settings: Geeklog configuration values over the defaults in config.php. */
function am_settings()
{
    global $_ARTIFACTMANAGER_DEFAULT;
    $values = $_ARTIFACTMANAGER_DEFAULT;
    if (class_exists('config')) {
        $stored = config::get_instance()->get_config(ARTIFACTMANAGER_CONFIG_GROUP);
        if (is_array($stored)) {
            foreach ($values as $key => $unused) {
                if (array_key_exists($key, $stored) && $stored[$key] !== null) $values[$key] = $stored[$key];
            }
        }
    }
    return array(
        'distribution_dir' => (string) $values['distribution_dir'],
        'signing_key_path' => (string) $values['signing_key_path'] !== '' ? (string) $values['signing_key_path'] : am_default_signing_key_path(),
        'branch' => (string) $values['branch'] !== '' ? (string) $values['branch'] : 'main',
        'download_category' => (string) $values['download_category'],
        'register_download' => (bool) $values['register_download'],
        'list_limit' => max(1, min(100, (int) $values['list_limit'])),
    );
}

/**
 * Mirrors the build settings into <site>\ashwind-build\settings\build-settings.json,
 * which the build worker (Invoke-AshwindBuildQueue.ps1) reads. Writes only on a difference.
 */
function am_sync_build_settings()
{
    $dir = am_build_path('settings');
    if (!is_dir($dir)) return false;
    $settings = am_settings();
    $path = am_build_path('settings', 'build-settings.json');
    $current = am_load_json($path);
    $wanted = array(
        'signingKeyPath' => $settings['signing_key_path'],
        'branch' => $settings['branch'],
        'downloadCategory' => $settings['download_category'],
        'registerDownload' => $settings['register_download'],
    );
    $same = true;
    foreach ($wanted as $key => $value) {
        if (!array_key_exists($key, $current) || $current[$key] !== $value) { $same = false; break; }
    }
    if ($same) return false;
    am_save_json($path, array_merge($current, $wanted));
    return true;
}
