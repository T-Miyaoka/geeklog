<?php
if (!defined('VERSION')) { http_response_code(404); exit; }
require_once __DIR__ . '/config.php';
global $_CONF, $LANG_configsections, $LANG_configsubgroups, $LANG_tab, $LANG_fs, $LANG_confignames, $LANG_configselects, $LANG_XPOST_CONFIG;
require __DIR__ . '/language/' . (isset($_CONF['language']) && strpos($_CONF['language'], 'japanese') === 0 ? 'japanese_utf-8.php' : 'english.php');
function plugin_chkVersion_xpost() { global $_XPOST_CONF; return $_XPOST_CONF['version']; }
function plugin_upgrade_xpost() { require_once __DIR__ . '/install_defaults.php'; return plugin_initconfig_xpost(); }
function plugin_autouninstall_xpost() { return array('tables' => array(), 'groups' => array(), 'features' => array()); }
function plugin_getheadercode_xpost()
{
    global $_CONF;
    if (basename(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '') !== 'configuration.php' || !SEC_inGroup('Root')) { return ''; }
    xpost_register_missing_configuration();
    return '<script src="' . htmlspecialchars($_CONF['site_admin_url'] . '/plugins/xpost/settings-embedded.js?v=inbox-20261021', ENT_QUOTES, 'UTF-8') . '" defer></script>';
}
function plugin_getadminoption_xpost()
{
    global $_CONF;
    if (!SEC_inGroup('Root')) { return false; }
    xpost_register_missing_configuration();
    return array('X-Post', $_CONF['site_admin_url'] . '/plugins/xpost/index.php', '');
}
// One-time, non-destructive registration of settings added after the original install (Root requests only).
function xpost_register_missing_configuration()
{
    if (!class_exists('config')) { return; }
    require_once __DIR__ . '/install_defaults.php';
    xpost_ensure_posting_configuration(config::get_instance());
}
