<?php
require_once dirname(__DIR__, 3) . '/lib-common.php';
if (empty($_USER['uid']) || (int) $_USER['uid'] < 2 || !SEC_inGroup('Root')) {
    http_response_code(403);
    echo COM_createHTMLDocument('<p>X API設定にはRoot管理者のログインが必要です。</p>', array('pagetitle' => 'X API設定'));
    exit;
}
header('Cache-Control: no-store, private');
header('Referrer-Policy: same-origin');
$embedded = isset($_GET['embedded']) && $_GET['embedded'] === '1';
if (!$embedded) {
    header('Location: ' . $_CONF['site_admin_url'] . '/configuration.php#xpost', true, 303);
    exit;
}
require_once $_CONF['path'] . 'plugins/xpost/classes/Core.php';
require_once $_CONF['path'] . 'plugins/xpost/classes/View.php';
require_once $_CONF['path'] . 'plugins/xpost/install_defaults.php';
use Happa\XPost\Store;
use Happa\XPost\Client;
use Happa\XPost\View;
function xp_settings_input($key)
{
    if (!isset($_POST[$key])) { return ''; }
    if (!is_string($_POST[$key]) || strlen($_POST[$key]) > 512) { throw new RuntimeException('入力値の形式または長さを確認してください。'); }
    return $_POST[$key];
}
$message = ''; $error = '';
try {
    $store = new Store(Store::privateDirectory($_CONF['path_data'], $_CONF['path_html']));
    $xpConfig = $store->configuration();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Never use a reauthentication form that could serialize entered secrets into HTML.
        if (!SECINT_checkToken()) { throw new RuntimeException('セキュリティトークンが期限切れです。ページを再読み込みしてください。'); }
        $action = xp_settings_input('action');
        if ($action === 'settings') {
            $input = array();
            foreach (array('username', 'api_key', 'api_secret', 'access_token', 'access_secret') as $key) { $input[$key] = xp_settings_input($key); }
            $store->configure($input);
            $message = 'API設定を保存しました。空欄の認証情報は変更していません。';
        } elseif ($action === 'check') {
            $user = (new Client($xpConfig, null, $store))->identity();
            $message = 'X APIへの接続を確認しました。投稿先: @' . $user['username'] . '（投稿は行っていません）。';
        } elseif ($action === 'register_configuration') {
            if (!plugin_initconfig_xpost()) { throw new RuntimeException('設定メニューを登録できませんでした。'); }
            $message = 'コンフィギュレーションにX-Postの設定入口を登録しました。';
        } else { throw new RuntimeException('操作を選択してください。'); }
    }
} catch (Throwable $e) {
    $error = $e instanceof RuntimeException ? $e->getMessage() : 'API設定を表示できません。保存先と権限を確認してください。';
}
try {
    if (!isset($store)) { throw new RuntimeException('保存先を確認してください。'); }
    $content = View::settings(array('base' => $_CONF['site_admin_url'] . '/plugins/xpost/settings.php' . ($embedded ? '?embedded=1' : ''),
        'embedded' => $embedded,
        'post_url' => $_CONF['site_admin_url'] . '/plugins/xpost/index.php', 'configuration_url' => $_CONF['site_admin_url'] . '/configuration.php',
        'css' => $_CONF['site_admin_url'] . '/plugins/xpost/style.css?v=settings-20261021',
        'token' => SEC_createToken(), 'config' => $store->configuration(), 'message' => $message, 'error' => $error,
        'registered' => config::get_instance()->group_exists('xpost')));
} catch (Throwable $e) {
    $content = '<p>API設定の保存先と権限を確認してください。</p>';
}
if ($embedded) {
    echo '<!doctype html><html lang="ja"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>X-PostのAPI設定</title><style>body{margin:0;font-family:sans-serif}body .xp-shell{margin:0;padding:0}body .xp-panel{margin:0}</style></head><body>'
        . $content . '</body></html>';
} else {
    echo COM_createHTMLDocument($content, array('pagetitle' => 'X-PostのAPI設定'));
}
