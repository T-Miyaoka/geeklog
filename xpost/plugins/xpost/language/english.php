<?php
if (!defined('VERSION')) { http_response_code(404); exit; }
$LANG_configsections['xpost'] = array('label' => 'X-Post', 'title' => 'X-Post settings');
$LANG_configsubgroups['xpost']['sg_main'] = 'API settings';
$LANG_tab['xpost']['tab_api'] = 'API settings';
$LANG_fs['xpost']['fs_api'] = 'API settings and connection check';
$LANG_configsubgroups['xpost']['sg_posting'] = 'Posting settings';
$LANG_tab['xpost']['tab_posting'] = 'Posting settings';
$LANG_fs['xpost']['fs_posting'] = 'Blog announcements';
$LANG_confignames['xpost']['announcement_interval_hours'] = 'Minimum announcement interval (hours; 0 = no interval check)';
$LANG_confignames['xpost']['inbox_announce_enabled'] = 'Allow announcements from the article importer (posts only when the package also sets announce_on_x)';
$LANG_configselects['xpost'][0] = array('Yes' => 1, 'No' => 0);
$LANG_XPOST_CONFIG = array('interval_invalid' => 'Enter the minimum announcement interval as whole hours from 0 to 720.');
require_once dirname(__DIR__) . '/configuration-panel.php';
$LANG_confignames['xpost']['api_settings_link'] = xpost_configuration_panel($_CONF['site_admin_url'], 'API settings and connection check');
