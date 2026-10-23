<?php
if (!defined('VERSION')) { http_response_code(404); exit; }
// Shadow DOM keeps secrets out of Geeklog's enclosing configuration form.
function xpost_configuration_panel($adminUrl, $title)
{
    $url = htmlspecialchars($adminUrl . '/plugins/xpost/', ENT_QUOTES, 'UTF-8');
    return '<style>#config_main .config_name:has(#xp-settings-panel){padding:0;margin:0}'
        . '#config_main:has(#xp-settings-panel) .config_label{float:none;width:100%;padding:0}'
        . '#config_main:has(#xp-settings-panel) #bottom_buttons{display:none}</style>'
        . '<div id="xp-settings-panel" role="region" aria-label="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
        . '" data-settings-url="' . $url . 'settings.php?embedded=1">API設定を読み込んでいます…</div>';
}
