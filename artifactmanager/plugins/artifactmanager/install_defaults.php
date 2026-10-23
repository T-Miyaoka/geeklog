<?php
if (stripos($_SERVER['PHP_SELF'], basename(__FILE__)) !== false) {
    die('This file can not be used on its own.');
}

require_once __DIR__ . '/functions.inc';

/**
 * Creates the missing items of the "artifactmanager" configuration group. Existing values are kept.
 * New build items take their first value from build-settings.json, which the admin page
 * edited directly before 1.2.0, so upgrading does not change the build worker's settings.
 */
function plugin_initconfig_artifactmanager()
{
    global $_ARTIFACTMANAGER_DEFAULT;
    $group = ARTIFACTMANAGER_CONFIG_GROUP;
    $manager = config::get_instance();
    $hadGroup = $manager->group_exists($group);
    $existing = $hadGroup ? $manager->get_config($group) : array();
    if (!is_array($existing)) $existing = array();

    $values = $_ARTIFACTMANAGER_DEFAULT;
    $values['signing_key_path'] = am_default_signing_key_path();
    $legacy = am_load_json(am_build_path('settings', 'build-settings.json'));
    if (!empty($legacy['signingKeyPath'])) $values['signing_key_path'] = (string) $legacy['signingKeyPath'];
    if (!empty($legacy['branch'])) $values['branch'] = (string) $legacy['branch'];
    if (isset($legacy['downloadCategory'])) $values['download_category'] = (string) $legacy['downloadCategory'];
    if (isset($legacy['registerDownload'])) $values['register_download'] = (bool) $legacy['registerDownload'];

    // name, default, type, subgroup, fieldset, selection array, sort order
    $items = array(
        array('sg_main', null, 'subgroup', 0, 0, null, 0),
        array('tab_main', null, 'tab', 0, 0, null, 0),
        array('fs_distribution', null, 'fieldset', 0, 0, null, 0),
        array('distribution_dir', $values['distribution_dir'], 'text', 0, 0, null, 10),
        array('fs_build', null, 'fieldset', 0, 1, null, 0),
        array('signing_key_path', $values['signing_key_path'], 'text', 0, 1, null, 20),
        array('branch', $values['branch'], 'text', 0, 1, null, 30),
        array('download_category', $values['download_category'], 'select', 0, 1, null, 40),
        array('register_download', $values['register_download'], 'select', 0, 1, 1, 50),
        array('fs_display', null, 'fieldset', 0, 2, null, 0),
        array('list_limit', $values['list_limit'], 'text', 0, 2, null, 60),
    );
    $added = false;
    foreach ($items as $item) {
        list($name, $default, $type, $subgroup, $fieldset, $selection, $sort) = $item;
        // Subgroup and tab rows are not part of get_config(); they exist whenever the group does.
        if ($type === 'subgroup' || $type === 'tab') {
            if ($hadGroup) continue;
        } elseif (array_key_exists($name, $existing)) {
            continue;
        }
        $manager->add($name, $default, $type, $subgroup, $fieldset, $selection, $sort, true, $group, 0);
        $added = true;
    }
    // The configuration search caches its labels.
    if ($added && class_exists('Geeklog\\Cache')) { \Geeklog\Cache::delete('configuration.autocomplete_data'); }
    if (!$manager->group_exists($group)) return false;
    try {
        am_sync_build_settings();
    } catch (Throwable $e) {
        COM_errorLog('Artifact Manager: ' . $e->getMessage());
    }
    return true;
}
