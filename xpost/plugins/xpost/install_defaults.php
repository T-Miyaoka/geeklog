<?php
if (!defined('VERSION')) { http_response_code(404); exit; }
function plugin_initconfig_xpost()
{
    $manager = config::get_instance();
    // Labels are cached in the configuration search JavaScript; refresh on explicit install/registration only.
    if (class_exists('Geeklog\\Cache')) { \Geeklog\Cache::delete('configuration.autocomplete_data'); }
    if (!$manager->group_exists('xpost')) {
        // Navigation metadata only. API credentials remain in private data/xpost/credentials.json.
        foreach (array(
            array('sg_main', null, 'subgroup'), array('tab_api', null, 'tab'),
            array('fs_api', null, 'fieldset'), array('api_settings_link', '', 'placeholder')
        ) as $entry) {
            $manager->add($entry[0], $entry[1], $entry[2], 0, 0, null, 0, true, 'xpost', 0);
        }
    }
    xpost_ensure_posting_configuration($manager);
    return $manager->group_exists('xpost');
}

/**
 * Adds the posting settings (subgroup 1) when they are missing. Geeklog's
 * config::add() replaces an existing row with its default, so each entry is
 * added only when absent and an administrator's saved value is never reset.
 * Kept in its own subgroup because the API subgroup hides the normal save button.
 */
function xpost_ensure_posting_configuration($manager)
{
    if (!$manager->group_exists('xpost')) { return; }
    $existing = $manager->get_config('xpost');
    if (!is_array($existing)) { $existing = array(); }
    foreach (array(
        array('sg_posting', null, 'subgroup', 1, 0, 0, 0),
        array('tab_posting', null, 'tab', 1, 1, 0, 1),
        array('fs_posting', null, 'fieldset', 1, 1, 0, 1),
        array('announcement_interval_hours', 24, 'text', 1, 1, 10, 1),
        // Announcements from the article importer stay off until an administrator enables them (selection 0: yes/no).
        array('inbox_announce_enabled', 0, 'select', 1, 1, 20, 1, 0),
    ) as $entry) {
        if (!array_key_exists($entry[0], $existing)) {
            $manager->add($entry[0], $entry[1], $entry[2], $entry[3], $entry[4], isset($entry[7]) ? $entry[7] : null, $entry[5], true, 'xpost', $entry[6]);
        }
    }
}
