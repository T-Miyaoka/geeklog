<?php
require __DIR__ . '/bootstrap.php';
require dirname(__DIR__) . '/plugins/artifactmanager/functions.inc';
$settingsFile = $_CONF['path'] . 'ashwind-build/settings/build-settings.json';
file_put_contents($settingsFile, json_encode(array('signingKeyPath' => 'D:\\keys\\sign.pem', 'branch' => 'release', 'downloadCategory' => 'ashwind', 'registerDownload' => false, 'workerOnly' => 'kept')));

require dirname(__DIR__) . '/plugins/artifactmanager/install_defaults.php';
check(plugin_initconfig_artifactmanager() === true, 'configuration group created');
$c = config::get_instance()->get_config('artifactmanager');
check($c['signing_key_path'] === 'D:\\keys\\sign.pem' && $c['branch'] === 'release', 'build settings migrated from build-settings.json');
check($c['download_category'] === 'ashwind' && $c['register_download'] === false, 'download settings migrated');
check($c['distribution_dir'] === 'C:\\inetpub\\wwwroot\\geeklog\\2_2_2\\public_html\\ashwind-chronicle\\updates\\stable', 'distribution folder default');
$names = array_column(config::get_instance()->added, 0);
check($names === array('sg_main', 'tab_main', 'fs_distribution', 'distribution_dir', 'fs_build', 'signing_key_path', 'branch', 'download_category', 'register_download', 'fs_display', 'list_limit'), 'all items added once in order');
$count = count(config::get_instance()->added);
check(plugin_initconfig_artifactmanager() === true && count(config::get_instance()->added) === $count, 'second run adds nothing and keeps values');

// A missing item is added on its own without touching the others.
unset(config::get_instance()->config_array['artifactmanager']['branch']);
config::get_instance()->config_array['artifactmanager']['distribution_dir'] = 'E:\\dist';
plugin_initconfig_artifactmanager();
$added = array_slice(config::get_instance()->added, $count);
check(count($added) === 1 && $added[0][0] === 'branch', 'only the missing item is added');
check(config::get_instance()->config_array['artifactmanager']['distribution_dir'] === 'E:\\dist', 'existing value kept');

// Configuration changes are mirrored for the build worker.
config::get_instance()->config_array['artifactmanager']['branch'] = 'main';
config::get_instance()->config_array['artifactmanager']['register_download'] = true;
plugin_configchange_artifactmanager('artifactmanager', array('branch'));
$json = json_decode(file_get_contents($settingsFile), true);
check($json['branch'] === 'main' && $json['registerDownload'] === true && $json['signingKeyPath'] === 'D:\\keys\\sign.pem', 'build-settings.json follows the configuration');
check($json['workerOnly'] === 'kept', 'unknown keys in build-settings.json are kept');
$mtime = filemtime($settingsFile); clearstatcache();
check(am_sync_build_settings() === false, 'no rewrite without a difference');
plugin_configchange_artifactmanager('Core', array('site_name'));
check(am_settings()['branch'] === 'main', 'other groups ignored');

// Empty signing key path falls back to the site default.
config::get_instance()->config_array['artifactmanager']['signing_key_path'] = '';
check(substr(am_settings()['signing_key_path'], -strlen('secrets\\update-signing-private.pem')) === 'secrets\\update-signing-private.pem', 'default signing key path');

// Select options for the downloads category.
$options = plugin_configmanager_select_download_category_artifactmanager();
check($options === array('（登録しない）' => '', 'Ashwind Chronicle' => 'ashwind', 'Tools' => '12'), 'download category options');

// Validation used by the configuration screen.
require dirname(__DIR__) . '/plugins/artifactmanager/configuration_validation.php';
check(is_string(custom_validation_artifactmanagerdistributiondir('x', array(array('distribution_dir' => 'relative\\path')))), 'relative distribution folder rejected');
check(is_string(custom_validation_artifactmanagerdistributiondir('x', array(array('distribution_dir' => 'Z:\\no\\such\\folder')))), 'missing distribution folder rejected');
check(is_string(custom_validation_artifactmanagerdistributiondir('x', array(array('distribution_dir' => 'C:\\a\\..\\b')))), 'parent traversal rejected');
check(custom_validation_artifactmanagersigningkeypath('x', array(array('signing_key_path' => 'D:\\keys\\sign.pem'))) === true, 'signing key accepted');
check(is_string(custom_validation_artifactmanagersigningkeypath('x', array(array('signing_key_path' => 'D:\\keys\\sign.txt')))), 'non-pem key rejected');
check(is_string(custom_validation_artifactmanagersigningkeypath('x', array(array('signing_key_path' => str_replace('/', '\\', $_CONF['path_html']) . 'k.pem')))), 'key inside public_html rejected');
check(custom_validation_artifactmanagerbranch('x', array(array('branch' => 'feature/x-1'))) === true, 'branch accepted');
check(is_string(custom_validation_artifactmanagerbranch('x', array(array('branch' => 'a..b')))), 'bad branch rejected');
check(custom_validation_artifactmanagerdownloadcategory('x', array(array('download_category' => ''))) === true, 'no category accepted');
check(custom_validation_artifactmanagerdownloadcategory('x', array(array('download_category' => '12'))) === true, 'existing category accepted');
check(is_string(custom_validation_artifactmanagerdownloadcategory('x', array(array('download_category' => '99')))), 'unknown category rejected');
check(custom_validation_artifactmanagerlistlimit('x', array(array('list_limit' => '10'))) === true, 'list limit accepted');
foreach (array('0', '101', 'abc', '-1', '2.5') as $bad) check(is_string(custom_validation_artifactmanagerlistlimit('x', array(array('list_limit' => $bad)))), "list limit $bad rejected");
check(am_settings()['list_limit'] === 10, 'list limit default 10');
config::get_instance()->config_array['artifactmanager']['list_limit'] = '25';
check(am_settings()['list_limit'] === 25, 'list limit from configuration');
check(am_config_complete(), 'configuration complete');
unset(config::get_instance()->config_array['artifactmanager']['list_limit']);
check(!am_config_complete(), 'missing item detected');
plugin_initconfig_artifactmanager();
check(am_config_complete() && am_settings()['list_limit'] === 10, 'missing list limit added on an existing group');
