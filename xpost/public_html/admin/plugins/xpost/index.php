<?php
require_once dirname(__DIR__, 3) . '/lib-common.php';

// Root-only: article content and credentials cannot be exposed to ordinary editors.
if (empty($_USER['uid']) || (int) $_USER['uid'] < 2 || !SEC_inGroup('Root')) {
    http_response_code(403);
    echo COM_createHTMLDocument('<p>X投稿管理にはRoot管理者のログインが必要です。</p>', array('pagetitle' => 'X投稿管理'));
    exit;
}
header('Cache-Control: no-store, private');
header('Referrer-Policy: same-origin');
require_once $_CONF['path'] . 'plugins/xpost/classes/Core.php';
require_once $_CONF['path'] . 'plugins/xpost/classes/Articles.php';
require_once $_CONF['path'] . 'plugins/xpost/classes/View.php';

use Happa\XPost\Store;
use Happa\XPost\Articles;
use Happa\XPost\Text;
use Happa\XPost\Preview;
use Happa\XPost\Client;
use Happa\XPost\Replies;
use Happa\XPost\ReplyPublisher;
use Happa\XPost\Budget;
use Happa\XPost\Policy;
use Happa\XPost\Publisher;
use Happa\XPost\View;
use Happa\XPost\Feedback;

function xp_input(array $source, $key, $max = 4000)
{
    if (!isset($source[$key])) { return ''; }
    if (!is_string($source[$key]) || strlen($source[$key]) > $max) {
        throw new RuntimeException('入力値の形式または長さを確認してください。');
    }
    return $source[$key];
}

$message = ''; $error = ''; $articles = array(); $text = ''; $preview = '';
$selected = array('sid' => '', 'second_sid' => '');
$replies = null; $replyKey = '';
$replyPreview = null;
try {
    $store = new Store(Store::privateDirectory($_CONF['path_data'], $_CONF['path_html']));
    $xpConfig = $store->configuration();
    $search = trim(xp_input($_GET, 'q', 300));
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Fail closed without Geeklog's reauthentication form serializing API secrets into HTML.
        if (!SECINT_checkToken()) { throw new RuntimeException('セキュリティトークンが期限切れです。ページを再読み込みしてください。'); }
        $action = xp_input($_POST, 'action', 30);
        $publisher = new Publisher($store);
        if ($action === 'approve_draft') {
            (new Feedback($store))->approve(xp_input($_POST, 'target', 25), xp_input($_POST, 'draft_version', 64), $_USER['uid'], $xpConfig);
            $message = 'お礼の下書きを承認しました。承認済み・未送信として一覧に残しました（Xへは送信していません）。';
        } elseif ($action === 'resolve_reply') {
            (new Feedback($store))->resolve(xp_input($_POST, 'target', 25), xp_input($_POST, 'draft_version', 64), $_USER['uid'], $xpConfig);
            $message = '返信を確認済みにしました。Xへの送信は行っていません。';
        } elseif ($action === 'decline_draft') {
            (new Feedback($store))->resolve(xp_input($_POST, 'target', 25), xp_input($_POST, 'draft_version', 64), $_USER['uid'], $xpConfig, true);
            $message = '下書きの承認を見送りました。未送信として一覧に残しています。';
        } elseif ($action === 'replies') {
            $replyKey = xp_input($_POST, 'key', 64);
            $postId = Replies::target($store, $replyKey, $xpConfig);
            $replies = (new Client($xpConfig, null, $store))->replies($postId, xp_input($_POST, 'next_token', 2048));
            $replies['post_id'] = $postId;
            $replies['key'] = $replyKey;
        } elseif ($action === 'reply_preview') {
            $replyPreview = ReplyPublisher::preview($store, xp_input($_POST, 'key', 64), xp_input($_POST, 'target', 25),
                xp_input($_POST, 'reply_text', 12000), $_USER['uid'], $xpConfig, new Client($xpConfig, null, $store));
        } elseif ($action === 'reply_publish') {
            $result = ReplyPublisher::publish($store, xp_input($_POST, 'reply_preview', 40000), $_USER['uid'], $xpConfig, new Client($xpConfig, null, $store));
            $message = '返信をXへ公開しました。';
        } elseif ($action === 'settings') {
            throw new RuntimeException('API設定はコンフィギュレーション側の専用設定画面で変更してください。');
        } elseif ($action === 'check') {
            throw new RuntimeException('接続確認はコンフィギュレーション側の専用設定画面で実行してください。');
        } elseif ($action === 'publish') {
            $confirmed = Preview::verify($store, xp_input($_POST, 'preview_id', 20000), $_USER['uid'], $xpConfig);
            // Re-read publication state immediately before sending, rather than trusting hidden fields.
            $articles = Articles::selected($confirmed['sids']);
            $text = $confirmed['text'];
            $result = $publisher->publish($articles, $text, $_USER['uid'], new Client($xpConfig, null, $store));
            $message = 'Xへ公開しました。';
            $articles = array(); $text = '';
        } elseif ($action === 'retry') {
            // Load saved content on the server; a retry only prepares a fresh confirmation.
            $history = $store->read('history');
            $key = xp_input($_POST, 'key', 64);
            if (!isset($history[$key]['state']) || $history[$key]['state'] !== 'failed') {
                throw new RuntimeException('未送信・再試行可能の履歴を選択してください。');
            }
            $entry = $history[$key];
            if (!isset($entry['sids'], $entry['text']) || !is_array($entry['sids']) || !is_string($entry['text'])) {
                throw new RuntimeException('前回の記事と投稿文を読み取れません。記事を選び直してください。');
            }
            foreach ($history as $attempt) {
                if ($attempt['state'] !== 'failed' && array_intersect($entry['sids'], $attempt['sids'])) {
                    throw new RuntimeException('選択した記事は投稿済み、または送信結果の確認待ちです。履歴を確認してください。');
                }
            }
            $articles = Articles::selected($entry['sids']);
            $selected = array('sid' => $articles[0]['sid'], 'second_sid' => isset($articles[1]) ? $articles[1]['sid'] : '');
            $text = $entry['text'];
            $preview = Preview::create($store, $articles, $text, $_USER['uid'], $xpConfig);
            $message = '前回の記事と投稿文を引き継ぎました。内容と投稿先を確認してから公開してください。';
        } elseif ($action === 'draft' || $action === 'preview') {
            $selected = array('sid' => xp_input($_POST, 'sid', 128), 'second_sid' => xp_input($_POST, 'second_sid', 128));
            $articles = Articles::selected(array_values($selected));
            $text = $action === 'draft' ? Text::draft($articles) : xp_input($_POST, 'text', 12000);
            if ($action === 'preview') {
                $preview = Preview::create($store, $articles, $text, $_USER['uid'], $xpConfig);
            }
        } elseif ($action === 'reconcile') {
            $resolution = xp_input($_POST, 'resolution', 20);
            if ($resolution !== 'posted' && ($resolution !== 'retry' || xp_input($_POST, 'not_published', 1) !== '1')) {
                throw new RuntimeException('再試行を許可するには、Xで未投稿であることを確認してチェックしてください。');
            }
            $publisher->reconcile(xp_input($_POST, 'key', 64), xp_input($_POST, 'post_url', 300), $resolution === 'retry');
            $message = '手動確認の結果を投稿履歴に記録しました。';
        } else { throw new RuntimeException('操作を選択してください。'); }
    }
} catch (Throwable $exception) {
    // Do not log raw transport responses, credentials, or database exception details.
    $error = $exception instanceof RuntimeException ? $exception->getMessage() : '処理を完了できませんでした。設定とサーバーの状態を確認してください。';
}
try {
    if (!isset($store)) { throw new RuntimeException('X投稿用データフォルダを確認してください。'); }
    $xpConfig = $store->configuration();
    $recent = Articles::recent(isset($search) ? $search : '');
    // Keep chosen articles in the selectors even when they are outside the search results.
    foreach ($articles as $article) {
        if (!in_array($article['sid'], array_column($recent, 'sid'), true)) { $recent[] = $article; }
    }
    $history = $store->read('history');
    $content = View::render(array('base' => $_CONF['site_admin_url'] . '/plugins/xpost/index.php',
        'css' => $_CONF['site_admin_url'] . '/plugins/xpost/style.css?v=delivery-20261021', 'token' => SEC_createToken(),
        'config' => $xpConfig, 'settings_url' => $_CONF['site_admin_url'] . '/configuration.php#xpost',
        'message' => $message, 'error' => $error, 'search' => isset($search) ? $search : '',
        'selected' => $selected, 'recent' => $recent, 'articles' => $articles, 'text' => $text, 'preview' => $preview,
        'inbox_page' => max(1, (int) xp_input($_GET, 'inbox_page', 8)),
        'history' => $history, 'replies' => $replies, 'reply_preview' => $replyPreview, 'reply_history' => $store->read('reply-history'),
        'budget' => Budget::state($store), 'reply_enabled' => Policy::replyPublishingAllowed($store), 'feedback' => $store->read('feedback')));
} catch (Throwable $exception) {
    $content = '<p>X投稿管理を表示できません。データフォルダの権限と保存データを確認してください。</p>';
}
echo COM_createHTMLDocument($content, array('pagetitle' => 'X-Post'));
