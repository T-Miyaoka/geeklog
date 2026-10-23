<?php
require_once dirname(__DIR__) . '/plugins/xpost/classes/Core.php';
use Happa\XPost\Store;
use Happa\XPost\Preview;
$base = dirname(__DIR__); $output = getenv('XPOST_TEST_DIR');
if (!$output) { throw new RuntimeException('Set XPOST_TEST_DIR outside the repository.'); }
$fixture = $output . '/controller-' . bin2hex(random_bytes(4));
foreach (array('/public_html/admin/plugins/xpost', '/plugins/xpost/classes', '/data') as $path) { mkdir($fixture . $path, 0750, true); }
copy($base . '/public_html/admin/plugins/xpost/index.php', $fixture . '/public_html/admin/plugins/xpost/index.php');
copy(__DIR__ . '/bootstrap-fixture.php', $fixture . '/public_html/lib-common.php');
foreach (array('Core.php', 'Automation.php', 'Articles.php', 'View.php') as $file) { copy($base . '/plugins/xpost/classes/' . $file, $fixture . '/plugins/xpost/classes/' . $file); }
$cases = array('anonymous' => 'Root管理者のログインが必要', 'get' => 'X-Post', 'csrf' => 'セキュリティトークンが期限切れ',
    'draft' => '内容を再確認', 'preview' => 'Xに公開する', 'tamper' => '確認画面の内容が変更', 'settings' => 'API設定はコンフィギュレーション側',
    'retry_single' => '前回の記事と投稿文を引き継ぎました', 'retry_pair' => '前回の記事と投稿文を引き継ぎました',
    'retry_missing' => '未送信・再試行可能の履歴を選択', 'retry_posted' => '未送信・再試行可能の履歴を選択',
    'retry_sending' => '未送信・再試行可能の履歴を選択', 'retry_uncertain' => '未送信・再試行可能の履歴を選択',
    'retry_unpublished' => '公開中の記事を選択', 'retry_csrf' => 'セキュリティトークンが期限切れ',
    'retry_incomplete' => '前回の記事と投稿文を読み取れません',
    'retry_resolved' => '選択した記事は投稿済み', 'retry_pending_overlap' => '選択した記事は投稿済み',
    'replies_missing' => '公開済み履歴を選択', 'replies_failed' => '公開済み履歴を選択',
    'replies_account' => '公開済み履歴を選択', 'replies_csrf' => 'セキュリティトークンが期限切れ',
    'reply_publish_csrf' => 'セキュリティトークンが期限切れ');
foreach ($cases as $case => $expected) {
    $retry = strpos($case, 'retry_') === 0;
    $reply = strpos($case, 'replies_') === 0;
    if ($reply) {
        $store = new Store($fixture . '/data/xpost');
        $store->write('history', array('saved' => array('state' => $case === 'replies_failed' ? 'failed' : 'posted',
            'post_id' => '100', 'username' => $case === 'replies_account' ? 'other' : '__MeeSan__',
            'sids' => array('example_ja'), 'at' => '2026-10-21', 'url' => '')));
        $historyBefore = file_get_contents($fixture . '/data/xpost/history.json');
    }
    if ($retry) {
        $store = new Store($fixture . '/data/xpost');
        $sids = $case === 'retry_single' ? array('example_ja') : array('example_ja', 'example_en');
        $savedText = "編集済みの紹介 <&> \"確認\"\nhttps://www.happa.bz/article.php?story=example_ja"
            . (count($sids) === 2 ? "\nhttps://www.happa.bz/article.php?story=example_en" : '') . "\n#GameDev";
        $state = in_array($case, array('retry_posted', 'retry_sending', 'retry_uncertain'), true) ? substr($case, 6) : 'failed';
        $entry = array('sids' => $sids, 'text' => $savedText, 'state' => $state, 'at' => '2026-10-19T22:40:22+00:00', 'url' => '');
        if ($case === 'retry_incomplete') { unset($entry['text']); }
        $history = array('saved' => $entry);
        $blocked = in_array($case, array('retry_resolved', 'retry_pending_overlap'), true);
        if ($blocked) {
            $other = $entry; $other['state'] = $case === 'retry_resolved' ? 'posted' : 'uncertain';
            if ($case === 'retry_pending_overlap') { $other['sids'] = array('example_en'); }
            $history['later'] = $other;
        }
        $store->write('history', $history);
        $historyBefore = file_get_contents($fixture . '/data/xpost/history.json');
    }
    putenv('XPOST_CONTROLLER_CASE=' . $case);
    $process = proc_open(array(PHP_BINARY, '-n', $fixture . '/public_html/admin/plugins/xpost/index.php'),
        array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    fclose($pipes[0]); $html = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $errors = stream_get_contents($pipes[2]); fclose($pipes[2]); $exit = proc_close($process);
    if ($exit !== 0 || $errors !== '' || strpos($html, $expected) === false || strpos($html, 'fixture-secret') !== false || strpos($html, 'fixture-token') !== false) {
        throw new RuntimeException('Controller case failed: ' . $case . ' / ' . $errors);
    }
    if ($case === 'csrf' && file_exists($fixture . '/data/xpost/credentials.json')) { throw new RuntimeException('Invalid CSRF saved credentials'); }
    if ($case === 'draft' && strpos($html, 'Xに公開する') !== false) { throw new RuntimeException('Publishing is available without preview'); }
    if ($case === 'anonymous' && is_dir($fixture . '/data/xpost')) { throw new RuntimeException('Anonymous request initialized private store'); }
    if ($retry) {
        if (file_get_contents($fixture . '/data/xpost/history.json') !== $historyBefore) { throw new RuntimeException('Retry modified history or sent a post'); }
        $dom = new DOMDocument(); libxml_use_internal_errors(true); $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xpath = new DOMXPath($dom);
        $confirmation = $xpath->query('//form[input[@name="action" and @value="publish"]]');
        $retryForms = $xpath->query('//form[input[@name="action" and @value="retry"]]');
        if ($retryForms->length !== 0) { throw new RuntimeException('Completed/failed history must not add long retry lists'); }
        if ($retryForms->length && ($retryForms->item(0)->getAttribute('action') !== 'https://www.happa.bz/admin/plugins/xpost/index.php#confirmation'
            || $xpath->query('.//input[@name="_glsectoken"]', $retryForms->item(0))->length !== 1)) { throw new RuntimeException('Retry form lacks confirmation target or CSRF token'); }
        if (in_array($case, array('retry_single', 'retry_pair'), true)) {
            if ($confirmation->length !== 1 || $xpath->query('//section[@id="confirmation"]')->length !== 1) { throw new RuntimeException('Retry did not open confirmation'); }
            $signed = $xpath->query('.//input[@name="preview_id"]', $confirmation->item(0))->item(0)->getAttribute('value');
            $confirmed = Preview::verify($store, $signed, 2, $store->configuration());
            if ($confirmed['sids'] !== $sids || $confirmed['text'] !== $savedText || $xpath->query('//textarea[@name="text"]')->item(0)->textContent !== $savedText) {
                throw new RuntimeException('Retry did not preserve exact saved articles and edited text');
            }
            foreach (array('sid' => $sids[0], 'second_sid' => isset($sids[1]) ? $sids[1] : '') as $name => $sid) {
                $option = $xpath->query('//select[@name="' . $name . '"]/option[@selected]');
                if ($sid !== '' && ($option->length !== 1 || $option->item(0)->getAttribute('value') !== $sid)) { throw new RuntimeException('Retry did not retain article selection'); }
            }
            file_put_contents($output . '/' . $case . '.html', $html);
        } elseif ($confirmation->length !== 0) { throw new RuntimeException('Rejected retry exposed publishing confirmation'); }
    } elseif ($reply) {
        if (file_get_contents($fixture . '/data/xpost/history.json') !== $historyBefore || strpos($html, 'この取得範囲・ページでは返信が見つかりません') !== false) {
            throw new RuntimeException('Rejected reply lookup changed history or claimed zero replies');
        }
    } elseif ($case === 'reply_publish_csrf') {
        if (is_file($fixture . '/data/xpost/reply-history.json')) { throw new RuntimeException('Invalid CSRF created reply history'); }
    } elseif (is_file($fixture . '/data/xpost/history.json')) { throw new RuntimeException('Controller tests unexpectedly sent a post'); }
    echo 'PASS: controller ' . $case . "\n";
}
echo 'TOTAL: ' . count($cases) . " controller cases passed; no X requests\n";
