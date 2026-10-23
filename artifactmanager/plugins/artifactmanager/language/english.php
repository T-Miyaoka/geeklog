<?php
if (stripos($_SERVER['PHP_SELF'], basename(__FILE__)) !== false) {
    die('This file can not be used on its own.');
}

$LANG_ARTIFACTMANAGER = array(
    'no_download_registration' => '(Do not register)',
);

// Geeklog configuration screen
$LANG_configsections['artifactmanager'] = array(
    'label' => 'Ashwind Artifact Manager',
    'title' => 'Ashwind Artifact Manager Configuration',
);

$LANG_confignames['artifactmanager'] = array(
    'distribution_dir' => 'Distribution folder',
    'signing_key_path' => 'Signing key path (.pem)',
    'branch' => 'Branch to build',
    'download_category' => 'Downloads category',
    'register_download' => 'Preselect "register in Downloads" when publishing',
    'list_limit' => 'Maximum rows in lists (packages, build history)',
);

$LANG_configsubgroups['artifactmanager'] = array(
    'sg_main' => 'Main Settings',
);

$LANG_tab['artifactmanager'] = array(
    'tab_main' => 'Distribution and Build',
);

$LANG_fs['artifactmanager'] = array(
    'fs_distribution' => 'Distribution files',
    'fs_build' => 'Server build',
    'fs_display' => 'Display',
);

$LANG_configselects['artifactmanager'] = array(
    0 => array('Yes' => 1, 'No' => 0),
    1 => array('Yes' => true, 'No' => false),
);
