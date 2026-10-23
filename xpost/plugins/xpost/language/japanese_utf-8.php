<?php
if (!defined('VERSION')) { http_response_code(404); exit; }
$LANG_configsections['xpost'] = array('label' => 'X-Post', 'title' => 'X-Postの設定');
$LANG_configsubgroups['xpost']['sg_main'] = 'API設定';
$LANG_tab['xpost']['tab_api'] = 'API設定';
$LANG_fs['xpost']['fs_api'] = 'API設定と接続確認';
$LANG_configsubgroups['xpost']['sg_posting'] = '投稿設定';
$LANG_tab['xpost']['tab_posting'] = '投稿設定';
$LANG_fs['xpost']['fs_posting'] = 'ブログ告知';
$LANG_confignames['xpost']['announcement_interval_hours'] = '告知の最短間隔（時間。0で間隔の確認なし）';
$LANG_XPOST_CONFIG = array('interval_invalid' => '告知の最短間隔は0〜720の整数（時間）で入力してください。');
require_once dirname(__DIR__) . '/configuration-panel.php';
$LANG_confignames['xpost']['api_settings_link'] = xpost_configuration_panel($_CONF['site_admin_url'], 'API設定と接続確認');
