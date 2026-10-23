<?php
require __DIR__ . '/bootstrap.php';
require dirname(__DIR__) . '/plugins/artifactmanager/functions.inc';

check(plugin_upgrade_artifactmanager() === true && $amPluginVersion === '1.2.0', 'upgrade version');
check(config::get_instance()->group_exists('artifactmanager'), 'upgrade creates the configuration group');
check(plugin_upgrade_artifactmanager() === true, 'idempotent upgrade');
$amDbError = true;
check(plugin_upgrade_artifactmanager() === false, 'database error rejected');
$amDbError = false; $amSkipUpdate = true; $amPluginVersion = '1.1.0';
check(plugin_upgrade_artifactmanager() === false, 'unchanged version rejected');
foreach ($amQueries as $q) { check($q === "UPDATE test_plugins SET pi_version = '1.2.0' WHERE pi_name = 'artifactmanager'", 'only own version column updated'); }
