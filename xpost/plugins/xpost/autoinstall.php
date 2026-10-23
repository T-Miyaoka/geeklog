<?php
if (!defined('VERSION')) { http_response_code(404); exit; }
require_once __DIR__ . '/config.php';
function plugin_autoinstall_xpost($name)
{
    global $_XPOST_CONF;
    return array('info' => array('pi_name' => 'xpost', 'pi_display_name' => 'X-Post',
        'pi_version' => $_XPOST_CONF['version'], 'pi_gl_version' => $_XPOST_CONF['geeklog'],
        'pi_homepage' => 'https://www.happa.bz/'), 'groups' => array(), 'features' => array(),
        'mappings' => array(), 'tables' => array(), 'requires' => array());
}
function plugin_load_configuration_xpost($name)
{
    require_once __DIR__ . '/install_defaults.php';
    return plugin_initconfig_xpost();
}
