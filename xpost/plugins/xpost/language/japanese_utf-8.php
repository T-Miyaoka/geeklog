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
$LANG_confignames['xpost']['inbox_announce_enabled'] = '記事取り込みからの告知を許可する（記事パッケージの announce_on_x と両方が有効な時だけ投稿）';
$LANG_configselects['xpost'][0] = array('はい' => 1, 'いいえ' => 0);
$LANG_XPOST_CONFIG = array('interval_invalid' => '告知の最短間隔は0〜720の整数（時間）で入力してください。');
require_once dirname(__DIR__) . '/configuration-panel.php';
$LANG_confignames['xpost']['api_settings_link'] = xpost_configuration_panel($_CONF['site_admin_url'], 'API設定と接続確認');
