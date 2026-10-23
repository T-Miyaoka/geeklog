<?php
// Local automation bridge: never web-accessible, never accepts credentials in arguments.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/plugins/xpost/classes/Core.php';
use Happa\XPost\Store;
use Happa\XPost\Client;
use Happa\XPost\Feedback;
use Happa\XPost\Budget;
use Happa\XPost\Policy;
$options = getopt('', array('data:', 'sync', 'queue', 'status', 'analysis:', 'repo:'));
try {
    $directory = isset($options['data']) ? $options['data'] : 'Y:/geeklog/2_2_2/data/xpost';
    if (!is_dir($directory)) { throw new RuntimeException('既存の非公開xpostデータフォルダーを指定してください。'); }
    $store = new Store($directory); $feedback = new Feedback($store);
    if (isset($options['sync'])) {
        $result = $feedback->sync(new Client($store->configuration(), null, $store), $store->configuration());
    } elseif (isset($options['queue'])) { $result = $feedback->pending(); }
    elseif (isset($options['analysis'])) {
        $input = json_decode(file_get_contents($options['analysis']), true);
        if (!is_array($input) || !isset($input['id'], $input['analysis'])) { throw new RuntimeException('分析ファイルの形式を確認してください。'); }
        $analysis = $input['analysis'];
        if (isset($analysis['status']) && in_array($analysis['status'], array('registered', 'duplicate'), true)) {
            $repo = isset($options['repo']) ? $options['repo'] : 'D:/Source/アプリ開発';
            $commit = isset($analysis['commit']) ? $analysis['commit'] : '';
            if (!preg_match('/^[a-f0-9]{8,40}$/D', $commit)) { throw new RuntimeException('確認済みコミットIDが必要です。'); }
            $run = function (array $args) use ($repo) {
                $p = proc_open(array_merge(array('git', '-C', $repo), $args), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
                if (!is_resource($p)) { throw new RuntimeException('Git検証を開始できません。'); }
                fclose($pipes[0]); $out = stream_get_contents($pipes[1]); fclose($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[2]);
                if (proc_close($p) !== 0) { throw new RuntimeException('改善項目のコミット検証が失敗しました。'); }
                return $out;
            };
            $run(array('merge-base', '--is-ancestor', $commit, 'HEAD'));
            $md = $run(array('show', $commit . ':AshwindChronicle/Doc/改善項目一覧.md'));
            $id = isset($analysis['backlog_id']) ? $analysis['backlog_id'] : '';
            if (!preg_match('/^[A-Z]+-[0-9]{3}$/D', $id) || !preg_match('/^\|\s*(?:<a[^>]*><\/a>)?' . preg_quote($id, '/') . '\s*\|[^\r\n]+$/m', $md, $row)
                || count(explode('|', trim($row[0], '| \r\n'))) !== 6
                || strpos($md, '| ID | 優先度 | 仕様変更 | 改善項目名 | 課題内容 | 推奨対策 |') === false) {
                throw new RuntimeException('指定コミットの6列表に改善項目がありません。');
            }
        }
        $result = $feedback->record((string) $input['id'], $analysis);
    } else { $result = array('budget' => Budget::state($store), 'reply_enabled' => Policy::replyPublishingAllowed($store), 'pending' => count($feedback->pending())); }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
} catch (Throwable $e) {
    // No raw PHP/transport trace, file contents, or credentials in automation output.
    fwrite(STDERR, ($e instanceof RuntimeException ? $e->getMessage() : '返信処理を完了できませんでした。') . "\n"); exit(1);
}
