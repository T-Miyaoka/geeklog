<?php
if (stripos($_SERVER['PHP_SELF'], basename(__FILE__)) !== false) {
    die('This file can not be used on its own.');
}

require_once __DIR__ . '/config.php';

function plugin_autoinstall_artifactmanager($pi_name)
{
    global $_ARTIFACTMANAGER_CONF;

    return array(
        'info' => array(
            'pi_name' => $_ARTIFACTMANAGER_CONF['pi_name'],
            'pi_display_name' => $_ARTIFACTMANAGER_CONF['pi_display_name'],
            'pi_version' => $_ARTIFACTMANAGER_CONF['pi_version'],
            'pi_gl_version' => $_ARTIFACTMANAGER_CONF['pi_gl_version'],
            'pi_homepage' => $_ARTIFACTMANAGER_CONF['pi_url'],
        ),
        'groups' => $_ARTIFACTMANAGER_CONF['groups'],
        'features' => $_ARTIFACTMANAGER_CONF['features'],
        'mappings' => $_ARTIFACTMANAGER_CONF['mappings'],
        'tables' => array(),
        'requires' => $_ARTIFACTMANAGER_CONF['requires'],
    );
}

/** Creates the configuration group on a fresh install. */
function plugin_load_configuration_artifactmanager($pi_name)
{
    require_once __DIR__ . '/install_defaults.php';
    return plugin_initconfig_artifactmanager();
}
