<?php
if (!defined('VERSION')) { http_response_code(404); exit; }
// Loaded by Geeklog's admin/configuration.php for the xpost group. Whole hours 0-720; 0 disables the interval check.
global $_CONF_VALIDATE, $LANG_XPOST_CONFIG;
$_CONF_VALIDATE['xpost']['announcement_interval_hours'] = array(
    'rule' => '/^(?:[0-9]|[1-9][0-9]|[1-6][0-9]{2}|7[01][0-9]|720)$/D',
    'message' => isset($LANG_XPOST_CONFIG['interval_invalid']) ? $LANG_XPOST_CONFIG['interval_invalid'] : 'Enter whole hours from 0 to 720.',
);
