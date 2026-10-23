<?php
if (stripos($_SERVER['PHP_SELF'], basename(__FILE__)) !== false) {
    die('This file can not be used on its own.');
}

global $_ARTIFACTMANAGER_CONF, $_ARTIFACTMANAGER_DEFAULT;

$_ARTIFACTMANAGER_CONF = array(
    'pi_name' => 'artifactmanager',
    'pi_display_name' => 'Ashwind Artifact Manager',
    'pi_version' => '1.2.0',
    'pi_gl_version' => '2.2.2',
    'pi_url' => 'https://www.happa.bz/',
    'groups' => array(
        'Artifact Manager Admin' => 'Users in this group can manage Ashwind Chronicle distribution artifacts',
    ),
    'features' => array(
        'artifactmanager.admin' => 'Manage Ashwind Chronicle distribution artifacts',
    ),
    'mappings' => array(
        'artifactmanager.admin' => array('Artifact Manager Admin'),
    ),
    // This plugin stores its registry as JSON and has no database dependency.
    'requires' => array(),
);

// Defaults of the settings edited in Geeklog's configuration screen (group "artifactmanager").
// An empty signing_key_path means <site>\ashwind-build\secrets\update-signing-private.pem.
$_ARTIFACTMANAGER_DEFAULT = array(
    'distribution_dir' => 'C:\\inetpub\\wwwroot\\geeklog\\2_2_2\\public_html\\ashwind-chronicle\\updates\\stable',
    'signing_key_path' => '',
    'branch' => 'main',
    'download_category' => '',
    'register_download' => true,
    'list_limit' => 10,
);
