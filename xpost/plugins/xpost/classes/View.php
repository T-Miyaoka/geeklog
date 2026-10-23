<?php
namespace Happa\XPost;

class View
{
    public static function h($value) { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    private static function token($token)
    {
        return '<input type="hidden" name="' . self::h(CSRF_TOKEN) . '" value="' . self::h($token) . '">';
    }
    private static function inbox(array $model, $base, $token)
    {
        $h = array(__CLASS__, 'h');
        $state = isset($model['feedback']) ? $model['feedback'] : array();
        $items = Feedback::inbox($state, isset($model['reply_history']) ? $model['reply_history'] : array());
        $total = count($items); $pages = max(1, (int) ceil($total / 20));
        $page = min($pages, max(1, isset($model['inbox_page']) ? (int) $model['inbox_page'] : 1));
        $out = '<section class="xp-panel" id="feedback-drafts"><h2>返信の確認・下書き承認</h2>';
        $out .= '<p class="xp-inbox-status">' . (!empty($state['last_sync']) ? '最終巡回: ' . $h(gmdate('Y-m-d H:i', $state['last_sync'])) . ' UTC' : '返信はまだ巡回確認されていません。')
            . ' / 取得済み返信 ' . count(isset($state['items']) ? $state['items'] : array()) . '件 / 要対応 ' . $total . '件</p>';
        if (!$items) { $out .= '<p>取得済みの範囲では、未対応の返信・承認待ちの下書きはありません。</p>'; }
        foreach (isset($state['roots']) ? $state['roots'] : array() as $root) {
            if (!empty($root['next'])) { $out .= '<p class="xp-hint">取得途中のため、未確認の返信が残る場合があります。</p>'; break; }
        }
        $labels = array('pending' => '分析待ち', 'question' => '質問・確認待ち', 'needs_review' => '要確認', 'registered' => '下書き承認待ち', 'duplicate' => '下書き承認待ち');
        $ordered = array_reverse($items, true);
        uasort($ordered, function ($a, $b) { return (int) empty($a['analysis']['draft']) <=> (int) empty($b['analysis']['draft']); });
        foreach (array_slice($ordered, ($page - 1) * 20, 20, true) as $id => $item) {
            $a = isset($item['analysis']) ? $item['analysis'] : array();
            $delivery = isset($item['delivery']['state']) ? $item['delivery']['state'] : '';
            $deliveryLabels = array('failed' => '送信失敗・未送信', 'sending' => '送信中・結果の確認待ち', 'uncertain' => '送信結果不明・要確認');
            $approved = !empty($item['approval']);
            $label = isset($deliveryLabels[$delivery]) ? $deliveryLabels[$delivery] : ($approved ? '承認済み・未送信'
                : (!empty($item['review']) ? '承認見送り・未送信' : (isset($labels[$item['status']]) ? $labels[$item['status']] : '要確認')));
            $out .= '<article class="xp-inbox-item"><p>' . $h($label)
                . (!empty($a['backlog_id']) ? ' / ' . $h($a['backlog_id']) : '') . ' · <a href="https://x.com/i/web/status/' . $h($id) . '" target="_blank" rel="noopener noreferrer">元の返信</a></p>';
            if (isset($item['source']['text'])) { $out .= '<p class="xp-comment">' . nl2br($h($item['source']['text'])) . '</p>'; }
            $displayDraft = isset($item['delivery']['text']) ? $item['delivery']['text'] : (isset($a['draft']) ? $a['draft'] : '');
            $draftLabel = ($delivery === 'sending' || $delivery === 'uncertain') ? '送信した返信文（結果未確認）'
                : ($delivery === 'failed' ? '送信を試みた返信文（失敗）' : 'お礼の下書き（未送信）');
            if ($displayDraft !== '') { $out .= '<p class="xp-hint">' . $draftLabel . '</p><pre class="xp-saved-text">' . $h($displayDraft) . '</pre>'; }
            elseif (!empty($a['summary'])) { $out .= '<p>' . $h($a['summary']) . '</p>'; }
            if ($delivery === 'sending' || $delivery === 'uncertain') {
                $out .= '<p class="xp-hint">X側で送信結果を確認してください。自動再送はしません。</p></article>'; continue;
            }
            if ($approved || $delivery !== '') {
                if (empty($a['draft'])) { $out .= '<p class="xp-hint">本文の保管期限が過ぎています。未送信として保持し、下書きの再作成が必要です。</p></article>'; continue; }
                if (empty($model['reply_enabled'])) { $out .= '<p class="xp-hint">返信送信は無効です。未送信のまま一覧に保持します。</p><button type="button" disabled>送信無効</button></article>'; continue; }
                if (!empty($item['root_key']) && empty($item['delivery_orphan']) && $approved
                    && isset($item['approval']['version']) && hash_equals($item['approval']['version'], Feedback::draftVersion($item, $model['config']))) {
                    $out .= '<form method="post" action="' . $base . '#reply-confirmation">' . $token . '<input type="hidden" name="action" value="reply_preview">'
                        . '<input type="hidden" name="key" value="' . $h($item['root_key']) . '"><input type="hidden" name="target" value="' . $h($id) . '">'
                        . '<input type="hidden" name="reply_text" value="' . $h($a['draft']) . '"><button type="submit">送信内容を確認</button></form></article>'; continue;
                }
                $out .= '<p class="xp-hint">本文・投稿先・承認情報を再確認してください。自動送信はしません。</p></article>'; continue;
            }
            if (!empty($item['review'])) { $out .= '<p class="xp-hint">見送りのまま未送信として保持しています。</p></article>'; continue; }
            if ($item['status'] === 'pending') { $out .= '<p class="xp-hint">定期処理で分析します。</p></article>'; continue; }
            $out .= '<div class="xp-inbox-actions"><form method="post" action="' . $base . '#feedback-drafts">' . $token
                . '<input type="hidden" name="target" value="' . $h($id) . '"><input type="hidden" name="draft_version" value="' . $h(Feedback::draftVersion($item, $model['config'])) . '">'
                . '<input type="hidden" name="action" value="' . (!empty($a['draft']) ? 'approve_draft' : 'resolve_reply') . '">'
                . '<button type="submit">' . (!empty($a['draft']) ? '下書きを承認' : '確認済みにする') . '</button></form>';
            if (!empty($a['draft'])) {
                $out .= '<form method="post" action="' . $base . '#feedback-drafts">' . $token
                    . '<input type="hidden" name="target" value="' . $h($id) . '"><input type="hidden" name="draft_version" value="' . $h(Feedback::draftVersion($item, $model['config'])) . '">'
                    . '<input type="hidden" name="action" value="decline_draft"><button type="submit" class="xp-secondary">見送る</button></form>';
            }
            $out .= '</div></article>';
        }
        if ($pages > 1) {
            $out .= '<nav class="xp-inbox-pages" aria-label="未対応返信のページ">';
            if ($page > 1) { $out .= '<a href="' . $base . '?inbox_page=' . ($page - 1) . '#feedback-drafts">前へ</a> '; }
            $out .= $page . ' / ' . $pages;
            if ($page < $pages) { $out .= ' <a href="' . $base . '?inbox_page=' . ($page + 1) . '#feedback-drafts">次へ</a>'; }
            $out .= '</nav>';
        }
        return $out . '</section>';
    }
    public static function render(array $model)
    {
        $h = array(__CLASS__, 'h');
        $base = $h($model['base']); $token = self::token($model['token']);
        $settings = $h(isset($model['settings_url']) ? $model['settings_url'] : preg_replace('~/index\.php$~', '/settings.php', $model['base']));
        $out = '<link rel="stylesheet" href="' . $h($model['css']) . '"><main class="xp-shell">'
            . '<header class="xp-header"><p class="xp-kicker">HAPPA / ARTICLE PUBLISHING</p><h1>X-Post</h1>'
            . '<p>記事を @' . $h($model['config']['username']) . ' へ投稿し、取得済み返信とお礼の下書きを確認します。承認済みでも未送信なら一覧に残り、送信成功後に非表示になります。</p></header>'
            . '<nav class="xp-nav"><a href="#feedback-drafts">返信の確認</a><a href="#compose">記事と投稿文</a><a href="' . $settings . '">API設定（コンフィギュレーション）</a></nav>';
        if ($model['message'] !== '') { $out .= '<div class="xp-notice" role="status">' . $h($model['message']) . '</div>'; }
        if ($model['error'] !== '') { $out .= '<div class="xp-notice xp-error" role="alert">' . $h($model['error']) . '</div>'; }
        $out .= self::inbox($model, $base, $token);
        $out .= '<section class="xp-panel" id="compose"><h2>記事を選択</h2><p>日英など、最大2記事を1件の投稿にまとめられます。</p>'
            . '<form method="get" action="' . $base . '" class="xp-search"><label for="xp-q">タイトル・記事IDで検索</label>'
            . '<div><input id="xp-q" name="q" value="' . $h($model['search']) . '" maxlength="100"><button type="submit">検索</button></div></form>'
            . '<form method="post" action="' . $base . '">' . $token . '<input type="hidden" name="action" value="draft">';
        foreach (array('sid' => '記事', 'second_sid' => '追加の記事（任意）') as $name => $label) {
            $selected = isset($model['selected'][$name]) ? $model['selected'][$name] : '';
            $out .= '<label for="xp-' . $name . '">' . $label . '</label><select id="xp-' . $name . '" name="' . $name . '"><option value="">選択してください</option>';
            foreach ($model['recent'] as $row) {
                $out .= '<option value="' . $h($row['sid']) . '"' . ($selected === $row['sid'] ? ' selected' : '') . '>'
                    . $h(Text::plain($row['title'])) . ' / ' . $h($row['sid']) . '</option>';
            }
            $out .= '</select>';
        }
        $out .= '<button type="submit">紹介文を作成</button></form></section>';
        if ($model['articles']) {
            $out .= '<section class="xp-panel" id="confirmation"><h2>投稿内容を確認</h2><ul class="xp-articles">';
            foreach ($model['articles'] as $article) {
                $out .= '<li><a href="' . $h($article['url']) . '" target="_blank" rel="noopener noreferrer">' . $h(Text::plain($article['title'])) . '</a></li>';
            }
            $out .= '</ul><form method="post" action="' . $base . '">' . $token;
            foreach (array_values($model['articles']) as $i => $article) {
                $out .= '<input type="hidden" name="' . ($i === 0 ? 'sid' : 'second_sid') . '" value="' . $h($article['sid']) . '">';
            }
            $out .= '<label for="xp-text">投稿文</label><textarea id="xp-text" name="text" rows="9" maxlength="4000" required>' . $h($model['text']) . '</textarea>'
                . '<p class="xp-hint">確認時の文字数: ' . Text::weight($model['text']) . ' / 280。URLは23文字換算。複合絵文字は多めに数えます。</p>'
                . '<div class="xp-actions"><button type="submit" name="action" value="preview" class="xp-secondary">内容を再確認</button></div>';
            $out .= '</form>';
            if ($model['preview']) {
                $out .= '<div class="xp-preview"><p class="xp-account">投稿先 @' . $h($model['config']['username']) . '</p><pre>' . $h($model['text']) . '</pre></div>'
                    . '<form method="post" action="' . $base . '">' . $token . '<input type="hidden" name="action" value="publish">'
                    . '<input type="hidden" name="preview_id" value="' . $h($model['preview']) . '">'
                    . '<p class="xp-hint">下のボタンを押すと、この内容をXへ1件公開します。</p><button type="submit">Xに公開する</button></form>';
            }
            $out .= '</section>';
        }
        $unresolved = array_filter($model['history'], function ($entry) { return in_array($entry['state'], array('sending', 'uncertain'), true); });
        if ($unresolved) {
            $out .= '<section class="xp-panel" id="unresolved"><h2>送信結果の確認が必要です</h2><p>結果不明の投稿は再送しません。Xで確認してから結果を記録してください。</p>';
            foreach ($unresolved as $key => $entry) {
                $out .= '<details><summary>' . $h(implode(' / ', $entry['sids'])) . '</summary>'
                    . '<form method="post" action="' . $base . '">' . $token . '<input type="hidden" name="action" value="reconcile"><input type="hidden" name="key" value="' . $h($key) . '">'
                    . '<label>公開済み投稿URL<input name="post_url" type="url" placeholder="https://x.com/account/status/123"></label>'
                    . '<button type="submit" name="resolution" value="posted">公開済みURLを記録</button>'
                    . '<label class="xp-checkbox"><input type="checkbox" name="not_published" value="1">Xで未投稿であることを確認しました</label>'
                    . '<button type="submit" name="resolution" value="retry" class="xp-secondary">再試行を許可</button></form></details>';
            }
            $out .= '</section>';
        }
        if (!empty($model['replies'])) {
            $replies = $model['replies'];
            $out .= '<section class="xp-panel" id="replies"><h2>返信コメント</h2><p>対象: <a href="https://x.com/i/web/status/'
                . $h($replies['post_id']) . '" target="_blank" rel="noopener noreferrer">元の告知を開く</a></p>'
                . '<p class="xp-hint">取得時刻（UTC）: ' . $h($replies['checked_at']) . ' / このページ ' . count($replies['items']) . '件</p>'
                . '<p>直近7日に作成された会話内の返信を新しい順に表示します。非公開・削除済み・検索対象外の返信や7日より前の返信は確認できません。全期間の総数ではありません。</p>';
            if (!$replies['items']) { $out .= '<p class="xp-empty">この取得範囲・ページでは返信が見つかりませんでした。全期間で返信なしという意味ではありません。</p>'; }
            foreach ($replies['items'] as $reply) {
                $out .= '<article class="xp-reply"><p>' . $h($reply['author']) . ($reply['username'] !== '' ? ' (@' . $h($reply['username']) . ')' : '')
                    . '</p><p class="xp-hint">' . $h($reply['created_at']) . ' / ' . ($reply['direct'] ? '告知への直接返信' : '会話内の返信') . '</p>'
                    . '<pre class="xp-saved-text">' . $h($reply['text']) . '</pre><a href="' . $h($reply['url'])
                    . '" target="_blank" rel="noopener noreferrer">Xで返信を開く</a>'
                    . '<form method="post" action="' . $base . '#reply-confirmation">' . $token
                    . '<input type="hidden" name="action" value="reply_preview"><input type="hidden" name="key" value="' . $h($replies['key']) . '">'
                    . '<input type="hidden" name="target" value="' . $h($reply['id']) . '">'
                    . '<label for="reply-text-' . $h($reply['id']) . '">このコメントへの返信文</label>'
                    . '<textarea id="reply-text-' . $h($reply['id']) . '" name="reply_text" rows="3" maxlength="4000" required></textarea>'
                    . '<button type="submit" class="xp-secondary">返信内容を確認</button></form></article>';
            }
            if ($replies['next_token'] !== '') {
                $out .= '<p>続きがあります。現在の表示は取得範囲の一部です。</p><form method="post" action="' . $base . '#replies">' . $token
                    . '<input type="hidden" name="action" value="replies"><input type="hidden" name="key" value="' . $h($replies['key']) . '">'
                    . '<input type="hidden" name="next_token" value="' . $h($replies['next_token']) . '">'
                    . '<button type="submit" class="xp-secondary">次の100件を取得</button></form>';
            }
            $out .= '</section>';
        }
        if (!empty($model['reply_preview'])) {
            $reply = $model['reply_preview'];
            $out .= '<section class="xp-panel" id="reply-confirmation"><h2>返信内容を確認</h2><p>投稿先 @' . $h($model['config']['username']) . '</p>'
                . '<p><a href="https://x.com/i/web/status/' . $h($reply['target']) . '" target="_blank" rel="noopener noreferrer">返信先コメント</a></p>'
                . '<pre class="xp-saved-text">' . $h($reply['target_text']) . '</pre><div class="xp-preview"><pre>' . $h($reply['text']) . '</pre></div>'
                . '<p class="xp-hint">文字数: ' . Text::weight($reply['text']) . ' / 280。下のボタンでこのコメントへ1件返信を公開します。</p>'
                . '<form method="post" action="' . $base . '#reply-history">' . $token
                . '<input type="hidden" name="action" value="reply_publish"><input type="hidden" name="reply_preview" value="' . $h($reply['signed']) . '">'
                . (isset($model['reply_enabled']) && !$model['reply_enabled']
                    ? '<p class="xp-notice">返信送信は無効です。Xの承認が得られるまでは下書きのみです。</p>'
                    : '<button type="submit">返信をXに公開する</button>') . '</form></section>';
        }
        $out .= '</main>';
        return $out;
    }
    public static function settings(array $model)
    {
        $h = array(__CLASS__, 'h'); $base = $h($model['base']); $token = self::token($model['token']);
        $instructions = '<p>Read and Write権限のAPI情報を設定します。認証情報は非公開ファイルに保存し、投稿・定期処理で共用します。</p>'
            . '<p>設定済みの秘密値は伏字です。変更する項目だけ入力してください。空欄保存は既存の値を保持します。接続確認は保存済み設定を使用し、Xへの投稿は行いません。</p>';
        $out = '<link rel="stylesheet" href="' . $h($model['css']) . '"><main class="xp-shell">'
            . '<header class="xp-header"><p class="xp-kicker">HAPPA / CONFIGURATION</p><h1>X-PostのAPI設定</h1>'
            . $instructions . '</header>'
            . '<nav class="xp-nav"><a href="' . $h($model['configuration_url']) . '">コンフィギュレーションへ戻る</a>'
            . '<a href="' . $h($model['post_url']) . '">X投稿管理へ戻る</a></nav>';
        if (!empty($model['embedded'])) {
            $out = '<link rel="stylesheet" href="' . $h($model['css']) . '"><main class="xp-shell"><header class="xp-config-header">' . $instructions . '</header>';
        }
        if ($model['message'] !== '') { $out .= '<div class="xp-notice" role="status">' . $h($model['message']) . '</div>'; }
        if ($model['error'] !== '') { $out .= '<div class="xp-notice xp-error" role="alert">' . $h($model['error']) . '</div>'; }
        if (empty($model['registered'])) {
            $out .= '<form method="post" action="' . $base . '">' . $token
                . '<input type="hidden" name="action" value="register_configuration"><button type="submit" class="xp-secondary">コンフィギュレーションに設定入口を登録</button></form>';
        }
        $out .= '<section class="xp-panel" id="settings"><h2>X API設定</h2>'
            . '<form method="post" action="' . $base . '" autocomplete="off">' . $token . '<input type="hidden" name="action" value="settings">'
            . '<label for="xp-username">投稿先アカウント（@なし）</label><input id="xp-username" name="username" value="' . $h($model['config']['username']) . '" pattern="[A-Za-z0-9_]{1,15}" required>';
        foreach (array('api_key' => 'API Key', 'api_secret' => 'API Key Secret', 'access_token' => 'Access Token', 'access_secret' => 'Access Token Secret') as $key => $label) {
            $out .= '<label for="xp-' . $key . '">' . $label . ' <span class="xp-hint">' . (!empty($model['config'][$key]) ? '設定済み' : '未設定')
                . '</span></label><input id="xp-' . $key . '" type="password" name="' . $key . '" value=""'
                . (!empty($model['config'][$key]) ? ' placeholder="••••••••"' : '') . ' autocomplete="new-password" maxlength="512">';
        }
        $out .= '<button type="submit">設定を保存</button></form><form method="post" action="' . $base . '">' . $token
            . '<input type="hidden" name="action" value="check"><button type="submit" class="xp-secondary">接続と投稿先を確認</button></form></section></main>';
        return $out;
    }
}
