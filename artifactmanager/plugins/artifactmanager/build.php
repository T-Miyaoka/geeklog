<?php
/**
 * Ashwind Chronicle server build queue: admin page side ("ビルド" tab of the Artifact Manager).
 *
 * The page only writes requests and settings; the scheduled task
 * \Task\Ashwind-Build-Queue (tools/ashwind-build-server/Invoke-AshwindBuildQueue.ps1) builds.
 *   <site>\ashwind-build\queue\ready\<id>.json      build requests (written here)
 *   <site>\ashwind-build\queue\cancel\<id>          cancel flags (written here)
 *   <site>\ashwind-build\queue\{running,done,failed} job state (read here)
 *   <site>\ashwind-build\settings\build-settings.json  signing key path, branch, downloads (mirror of the
 *                                                     Geeklog configuration, written by am_sync_build_settings())
 *   <site>\ashwind-build\settings\published.json   publish history (written here)
 *   <site>\ashwind-build\status\worker.json         worker heartbeat (read here)
 * The page never reads the signing key; the worker reports whether it can.
 */
if (stripos($_SERVER['PHP_SELF'], basename(__FILE__)) !== false) {
    die('This file can not be used on its own.');
}

function amb_root()
{
    return am_build_root();
}

function amb_path()
{
    return am_build_root() . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, func_get_args());
}

/** Build settings, edited in Geeklog's configuration screen (see am_settings()). */
function amb_settings()
{
    $s = am_settings();
    return array(
        'signingKeyPath' => $s['signing_key_path'],
        'branch' => $s['branch'],
        'downloadCategory' => $s['download_category'],
        'registerDownload' => $s['register_download'],
    );
}

function amb_published_version($distributionDir)
{
    foreach (array('manifest.json', 'manifest.json.disabled') as $name) {
        $path = am_target($distributionDir, $name);
        if (is_file($path)) {
            $m = am_read_manifest($path);
            return $m['version'];
        }
    }
    return '';
}

function amb_next_version($version)
{
    if (!preg_match('/^(\d+)\.(\d+)\.(\d+)$/', (string) $version, $m)) return '';
    return $m[1] . '.' . $m[2] . '.' . ((int) $m[3] + 1);
}

function amb_jobs()
{
    $jobs = array();
    foreach (array('ready', 'running', 'done', 'failed') as $folder) {
        foreach ((array) @glob(amb_path('queue', $folder, '*.json')) as $file) {
            if (!is_string($file)) continue;
            $job = am_load_json($file);
            if (!isset($job['id'])) continue;
            $job['_folder'] = $folder;
            if ($folder === 'ready') $job['state'] = 'queued';
            $jobs[(string) $job['id']] = $job;
        }
    }
    uasort($jobs, function ($a, $b) {
        return strcmp(isset($b['requestedAt']) ? $b['requestedAt'] : '', isset($a['requestedAt']) ? $a['requestedAt'] : '');
    });
    return $jobs;
}

function amb_active_job(array $jobs)
{
    foreach ($jobs as $job) if ($job['_folder'] === 'ready' || $job['_folder'] === 'running') return $job;
    return null;
}

function amb_valid_id($id)
{
    $id = (string) $id;
    if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) throw new RuntimeException('ジョブIDが正しくありません。');
    return $id;
}

function amb_published()
{
    return am_load_json(amb_path('settings', 'published.json'));
}

/** Registers the online setup (wrapped in a ZIP: the Downloads plugin serves zip/7z/pdf/txt only). */
function amb_register_download($version, $notes, $setupPath, $cid)
{
    global $_CONF, $_TABLES, $_USER, $_DLM_CONF, $_PLUGINS;
    if (!in_array('downloads', $_PLUGINS)) throw new RuntimeException('ダウンロードプラグインが有効ではありません。');
    require_once $_CONF['path'] . 'plugins/downloads/include/functions.php';
    require_once $_CONF['path'] . 'plugins/downloads/include/download.class.php';
    if (!class_exists('ZipArchive')) throw new RuntimeException('PHPのZIP拡張が無効なため、セットアップをダウンロード一覧へ登録できません。');
    if (!is_file($setupPath)) throw new RuntimeException('セットアップが見つかりません: ' . $setupPath);
    $escCid = DB_escapeString($cid);
    if (DB_count($_TABLES['downloadcategories'], 'cid', $escCid) == 0) throw new RuntimeException('ダウンロード登録カテゴリが見つかりません。');
    $escVersion = DB_escapeString($version);
    $existing = DB_getItem($_TABLES['downloads'], 'lid', "cid = '$escCid' AND version = '$escVersion'");
    if (!empty($existing)) return (string) $existing;

    // Continue the look of the latest entry in the category (title, project, comments, format).
    $template = array('title' => 'Ashwind Chronicle Windows 開発ビルド', 'project' => '', 'homepage' => '', 'commentcode' => -1, 'postmode' => 'plaintext');
    $result = DB_query("SELECT title, project, homepage, commentcode, postmode FROM {$_TABLES['downloads']} WHERE cid = '$escCid' ORDER BY date DESC LIMIT 1");
    if ($result && ($row = DB_fetchArray($result))) {
        foreach ($template as $key => $unused) if (isset($row[$key]) && $row[$key] !== '') $template[$key] = $row[$key];
    }

    $fileName = 'AshwindChronicle-Setup-' . $version . '-win-x64.zip';
    $secretId = md5(uniqid('', true));
    $store = rtrim($_DLM_CONF['path_filestore'], '/\\') . DIRECTORY_SEPARATOR;
    $target = $store . DLM_createSafeFileName($fileName, $secretId);
    $zip = new ZipArchive();
    if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('ダウンロード用ZIPを作成できません: ' . $target);
    $zip->addFile($setupPath, basename($setupPath));
    $zip->close();

    $download = new DLDownload();
    $lid = $download->_createID('ashwind_');
    $now = time();
    $fields = array(
        '_lid' => $lid, '_cid' => $cid, '_title' => (string) $template['title'], '_url' => $fileName,
        '_homepage' => (string) $template['homepage'], '_version' => $version,
        '_size' => (int) filesize($target), '_secret_id' => $secretId, '_md5' => md5_file($target),
        '_logourl' => '', '_mg_autotag' => '', '_tags' => '', '_date' => $now,
        '_commentcode' => (int) $template['commentcode'], '_project' => (string) $template['project'],
        '_description' => (string) $notes, '_detail' => '', '_owner_id' => (int) $_USER['uid'],
        '_postmode' => (string) $template['postmode'], '_is_released' => 1, '_is_listing' => 1,
        '_createddate' => date('Y-m-d H:i:s', $now),
    );
    // DLDownload keeps its fields private; set them and save through its own _addToDatabase()
    // so the HTML filter, item-saved hooks and feeds run as for an admin registration.
    $save = function (array $values) {
        foreach ($values as $name => $value) $this->{$name} = $value;
        $this->_addToDatabase();
    };
    try {
        Closure::bind($save, $download, 'DLDownload')($fields);
        if (DB_count($_TABLES['downloads'], 'lid', DB_escapeString($lid)) == 0) throw new RuntimeException('ダウンロード一覧への登録に失敗しました。');
    } catch (Throwable $e) {
        @unlink($target);
        throw $e;
    }
    return $lid;
}

function amb_handle_post($action, $distributionDir)
{
    global $_USER;
    if ($action === 'build_request') {
        $version = trim((string) (isset($_POST['version']) ? $_POST['version'] : ''));
        if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) throw new RuntimeException('バージョンは 0.1.17 の形式で入力してください。');
        $published = amb_published_version($distributionDir);
        if ($published !== '' && version_compare($version, $published, '<=')) throw new RuntimeException('バージョンは公開中の ' . $published . ' より新しくしてください。');
        if (amb_active_job(amb_jobs()) !== null) throw new RuntimeException('待機中または実行中のビルドがあります。終わってから依頼してください。');
        $notes = str_replace("\r\n", "\n", trim((string) (isset($_POST['release_notes']) ? $_POST['release_notes'] : '')));
        if ($notes === '') throw new RuntimeException('このビルドで対応したことを1行以上入力してください。');
        if (strlen($notes) > 20000) throw new RuntimeException('更新内容が長すぎます。');
        $id = date('YmdHis') . '-' . bin2hex(random_bytes(3));
        am_save_json(amb_path('queue', 'ready', $id . '.json'), array(
            'schema' => 1, 'id' => $id, 'version' => $version, 'releaseNotes' => $notes,
            'requestedBy' => isset($_USER['username']) ? (string) $_USER['username'] : '', 'requestedAt' => date('c'),
        ));
        return 'ビルドを依頼しました（' . $version . '）。数分以内に開始します。';
    }
    if ($action === 'build_cancel') {
        $id = amb_valid_id(isset($_POST['job']) ? $_POST['job'] : '');
        if (!is_file(amb_path('queue', 'ready', $id . '.json')) && !is_file(amb_path('queue', 'running', $id . '.json'))) throw new RuntimeException('取り消せるビルドがありません。');
        if (@file_put_contents(amb_path('queue', 'cancel', $id), date('c')) === false) throw new RuntimeException('取消を記録できませんでした。フォルダの権限を確認してください。');
        return 'ビルドの取消を依頼しました。';
    }
    if ($action === 'build_publish') {
        $id = amb_valid_id(isset($_POST['job']) ? $_POST['job'] : '');
        $job = am_load_json(amb_path('queue', 'done', $id . '.json'));
        if (!isset($job['state']) || $job['state'] !== 'staged') throw new RuntimeException('公開できるビルドではありません。');
        $version = (string) $job['version'];
        $current = am_target($distributionDir, 'manifest.json');
        if (!is_file($current)) throw new RuntimeException('自動更新が停止中です。先に再開してから公開してください。');
        $currentManifest = am_read_manifest($current);
        if (version_compare($version, $currentManifest['version'], '<=')) throw new RuntimeException('このビルドは公開中の ' . $currentManifest['version'] . ' より新しくありません。');
        $pending = $distributionDir . DIRECTORY_SEPARATOR . 'pending' . DIRECTORY_SEPARATOR . $version . DIRECTORY_SEPARATOR . 'manifest.json';
        if (!is_file($pending)) throw new RuntimeException('公開待ちのmanifestがありません: ' . $pending);
        $manifest = am_read_manifest($pending);
        if ($manifest['version'] !== $version) throw new RuntimeException('公開待ちmanifestのバージョンがビルドと一致しません。');
        $packagePath = am_target($distributionDir, $manifest['package']);
        if (!is_file($packagePath)) throw new RuntimeException('manifestが参照するZIPがありません: ' . $manifest['package']);
        if (!hash_equals($manifest['sha256'], strtolower((string) hash_file('sha256', $packagePath)))) throw new RuntimeException('ZIPのSHA-256がmanifestと一致しないため、公開しませんでした。');
        $setupPath = isset($job['result']['setup']) ? (string) $job['result']['setup'] : '';
        if ($setupPath === '' || !is_file($setupPath)) throw new RuntimeException('オンラインインストーラーが配置されていません。');

        // Keep the replaced manifest so a release can be rolled back by hand.
        $history = $distributionDir . DIRECTORY_SEPARATOR . 'pending' . DIRECTORY_SEPARATOR . 'history';
        if (!is_dir($history) && !@mkdir($history, 0750, true)) throw new RuntimeException('manifestの控えを保存できません。');
        if (!@copy($current, $history . DIRECTORY_SEPARATOR . 'manifest-' . $currentManifest['version'] . '-' . date('YmdHis') . '.json')) throw new RuntimeException('manifestの控えを保存できません。');
        $temp = am_target($distributionDir, '.manifest.json.publish-' . bin2hex(random_bytes(4)));
        if (!@copy($pending, $temp) || !@rename($temp, $current)) {
            @unlink($temp);
            throw new RuntimeException('manifest.jsonを置き換えられませんでした。ファイル権限を確認してください。');
        }
        $message = $version . ' を公開しました。利用者の自動更新が始まります。';
        $published = amb_published();
        $record = array('publishedAt' => date('c'), 'publishedBy' => isset($_USER['username']) ? (string) $_USER['username'] : '', 'job' => $id);
        $settings = amb_settings();
        if (!empty($_POST['register_download'])) {
            try {
                if ($settings['downloadCategory'] === '') throw new RuntimeException('ダウンロード登録カテゴリが未設定です。');
                $record['downloadLid'] = amb_register_download($version, isset($job['releaseNotes']) ? (string) $job['releaseNotes'] : '', $setupPath, $settings['downloadCategory']);
                $message .= ' ダウンロード一覧にセットアップを登録しました。';
            } catch (Throwable $e) {
                $record['downloadError'] = $e->getMessage();
                $message .= ' ただし、ダウンロード一覧への登録は失敗しました: ' . $e->getMessage();
                COM_errorLog('Artifact Manager download registration: ' . $e->getMessage());
            }
        }
        $published[$version] = $record;
        am_save_json(amb_path('settings', 'published.json'), $published);
        return $message;
    }
    if ($action === 'build_register_download') {
        // Retry only the Downloads registration of an already published version.
        $id = amb_valid_id(isset($_POST['job']) ? $_POST['job'] : '');
        $job = am_load_json(amb_path('queue', 'done', $id . '.json'));
        $version = isset($job['version']) ? (string) $job['version'] : '';
        $published = amb_published();
        if ($version === '' || !isset($published[$version])) throw new RuntimeException('公開済みのビルドではありません。');
        if (!empty($published[$version]['downloadLid'])) throw new RuntimeException('このバージョンは登録済みです。');
        $settings = amb_settings();
        if ($settings['downloadCategory'] === '') throw new RuntimeException('ダウンロード登録カテゴリが未設定です。コンフィギュレーションで選んでください。');
        $setupPath = isset($job['result']['setup']) ? (string) $job['result']['setup'] : '';
        $published[$version]['downloadLid'] = amb_register_download($version, isset($job['releaseNotes']) ? (string) $job['releaseNotes'] : '', $setupPath, $settings['downloadCategory']);
        unset($published[$version]['downloadError']);
        am_save_json(amb_path('settings', 'published.json'), $published);
        return 'ダウンロード一覧にセットアップを登録しました。';
    }
    throw new RuntimeException('不明な操作です。');
}

function amb_log_tail($id, $lines = 40)
{
    $path = amb_path('logs', $id . '.log');
    if (!is_file($path)) return '';
    $size = (int) filesize($path);
    $handle = @fopen($path, 'rb');
    if (!$handle) return '';
    fseek($handle, max(0, $size - 65536));
    $text = (string) stream_get_contents($handle);
    fclose($handle);
    $all = preg_split('/\r?\n/', rtrim($text));
    if ($size > 65536) array_shift($all);
    return implode("\n", array_slice($all, -$lines));
}

function amb_time($value)
{
    $time = strtotime((string) $value);
    return $time ? date('Y-m-d H:i', $time) : '';
}

function amb_render($distributionDir, $token, $base)
{
    $action = $base . '?tab=build';
    $hidden = '<input type="hidden" name="' . CSRF_TOKEN . '" value="' . am_h($token) . '">';
    $root = amb_root();
    $html = '';
    if (!is_dir(amb_path('queue', 'ready'))) {
        return '<div class="am-empty">ビルド用フォルダ ' . am_h($root) . ' がありません。サーバーで tools/ashwind-build-server/Install-AshwindBuildServer.ps1 を実行してください。</div>';
    }
    $settings = amb_settings();
    $status = am_load_json(amb_path('status', 'worker.json'));
    $jobs = amb_jobs();
    $active = amb_active_job($jobs);
    $published = amb_published();
    try { $publishedVersion = amb_published_version($distributionDir); } catch (Throwable $e) { $publishedVersion = ''; }
    // While a build is active the tab refreshes itself in place (see amb_refresh_script), so opened 詳細 stay open.
    if ($active !== null) $html .= '<noscript><meta http-equiv="refresh" content="30;url=' . am_h($action) . '"></noscript>';

    // Worker status
    $checked = isset($status['checkedAt']) ? strtotime((string) $status['checkedAt']) : 0;
    $alive = $checked && (time() - $checked) < 360;
    $problems = array();
    if (!$checked) $problems[] = 'ワーカーがまだ一度も動いていません。タスク \\Task\\Ashwind-Build-Queue を確認してください。';
    elseif (!$alive && $active === null) $problems[] = 'ワーカーの最終確認が ' . amb_time($status['checkedAt']) . ' です。タスクが止まっていないか確認してください。';
    if (!empty($status['configError'])) $problems[] = '設定エラー: ' . $status['configError'];
    if (!empty($status['signingKeyProblem'])) $problems[] = '署名鍵: ' . $status['signingKeyProblem'];
    if ($checked && empty($status['git'])) $problems[] = 'Git が見つかりません。';
    if ($checked && (empty($status['dotnetSdks']) || !preg_match('/(^|\n)10\./', (string) $status['dotnetSdks']))) $problems[] = '.NET 10 SDK が見つかりません。';
    $html .= '<section class="am-service"><div><h2>ビルドワーカー</h2>' . ($problems ? '<span class="am-badge stopped">要確認</span>' : '<span class="am-badge published">待機可能</span>') . '</div>';
    $html .= '<dl class="am-service-meta"><div><dt>最終確認</dt><dd>' . am_h($checked ? amb_time($status['checkedAt']) : '未実行') . '</dd></div>'
        . '<div><dt>取得元ブランチ</dt><dd>' . am_h($settings['branch']) . '</dd></div>'
        . '<div><dt>前回取得したコミット</dt><dd>' . am_h(!empty($status['repositoryCommit']) ? $status['repositoryCommit'] : '未取得') . '</dd></div>'
        . '<div><dt>空き容量</dt><dd>' . am_h(isset($status['freeGB']) ? $status['freeGB'] . ' GB' : '不明') . '</dd></div></dl><div></div></section>';
    foreach ($problems as $problem) $html .= '<div class="am-notice is-warning">' . am_h($problem) . '</div>';

    // Request form
    $html .= '<section class="am-config amb-request"><h2>ビルドして配置</h2>'
        . '<p>GitHub の <code>' . am_h($settings['branch']) . '</code> ブランチの最新を取得してビルドし、ZIPとオンラインインストーラーを配信フォルダへ配置します。'
        . 'manifest.json は置き換えないので、利用者への自動更新は「公開」を押すまで始まりません。所要時間は15〜25分程度です。</p>';
    if ($active !== null) {
        $html .= '<div class="am-notice">' . am_h($active['version']) . ' のビルドが' . ($active['_folder'] === 'ready' ? '開始待ち' : '実行中') . 'です。</div>';
    } else {
        $html .= '<form method="post" action="' . am_h($action) . '">' . $hidden . '<input type="hidden" name="action" value="build_request">'
            . '<div class="am-field"><label for="amb-version">バージョン</label><input type="text" id="amb-version" name="version" value="' . am_h(amb_next_version($publishedVersion)) . '" pattern="\d+\.\d+\.\d+" required><small>公開中: ' . am_h($publishedVersion !== '' ? $publishedVersion : 'なし') . '。これより新しい番号にしてください。</small></div>'
            . '<div class="am-field"><label for="amb-notes">このビルドで対応したこと</label><textarea id="amb-notes" name="release_notes" rows="5" required placeholder="1行に1項目"></textarea><small>manifest の更新内容と、公開時にダウンロード一覧の説明に使います。</small></div>'
            . '<div class="am-actions"><button class="am-button" type="submit">ビルドを依頼</button></div></form>';
    }
    $html .= '</section>';

    // Jobs (newest first, up to the configured limit)
    $labels = array('queued' => '開始待ち', 'running' => '実行中', 'staged' => '公開待ち', 'failed' => '失敗', 'cancelled' => '取消');
    $stages = array('prepare' => '準備', 'git' => 'ソース取得', 'publish' => 'アプリ発行', 'opening-economy' => '開始前経済の準備', 'zip' => 'ZIP作成', 'msi' => 'MSI作成', 'setup' => 'セットアップ作成', 'deploy' => '配置と確認', 'staged' => '配置完了');
    $limit = am_settings()['list_limit'];
    $html .= '<section class="am-packages"><div class="am-section-head"><h2>ビルド履歴 <span class="am-count">' . (count($jobs) > $limit ? $limit . ' / ' . count($jobs) . '件' : count($jobs) . '件') . '</span></h2></div>';
    if (!$jobs) {
        $html .= '<div class="am-empty">まだビルドはありません。</div>';
    } else {
        $html .= '<div class="am-table-wrap"><table class="am-table amb-jobs"><thead><tr><th>バージョン</th><th>状態</th><th>依頼</th><th>開始</th><th>終了</th><th>コミット</th><th class="is-ops">操作</th></tr></thead><tbody>';
        foreach (array_slice($jobs, 0, $limit, true) as $id => $job) {
            $state = isset($job['state']) ? (string) $job['state'] : 'queued';
            $version = isset($job['version']) ? (string) $job['version'] : '';
            $isPublished = $state === 'staged' && isset($published[$version]);
            $label = $isPublished ? '公開済み' : (isset($labels[$state]) ? $labels[$state] : $state);
            $badge = $isPublished || $state === 'staged' ? 'published' : ($state === 'failed' || $state === 'cancelled' ? 'stopped' : 'newest');
            $stage = isset($job['stage'], $stages[$job['stage']]) ? $stages[$job['stage']] : '';
            $commit = isset($job['commit']) ? substr((string) $job['commit'], 0, 10) : '';
            $subject = isset($job['commitSubject']) ? (string) $job['commitSubject'] : '';
            $rowClass = $state === 'staged' && !$isPublished ? 'is-published' : ($state === 'running' || $state === 'queued' ? 'is-running' : '');

            $ops = '';
            if ($job['_folder'] === 'ready' || $job['_folder'] === 'running') {
                $ops .= '<form method="post" class="am-inline-form" action="' . am_h($action) . '" onsubmit="return confirm(\'このビルドを取り消しますか？\')">' . $hidden . '<input type="hidden" name="action" value="build_cancel"><input type="hidden" name="job" value="' . am_h($id) . '"><button class="am-button secondary is-small" type="submit">取り消す</button></form>';
            }
            if ($isPublished && empty($published[$version]['downloadLid'])) {
                $ops .= '<form method="post" class="am-inline-form" action="' . am_h($action) . '">' . $hidden . '<input type="hidden" name="action" value="build_register_download"><input type="hidden" name="job" value="' . am_h($id) . '"><button class="am-button secondary is-small" type="submit">ダウンロード一覧に登録</button></form>';
            }
            if ($state === 'staged' && !$isPublished && $publishedVersion !== '' && version_compare($version, $publishedVersion, '>')) {
                $ops .= '<form method="post" class="am-inline-form amb-publish" action="' . am_h($action) . '" onsubmit="return confirm(\'' . am_h($version) . ' を公開しますか？利用者への自動更新が始まります。\')">' . $hidden
                    . '<input type="hidden" name="action" value="build_publish"><input type="hidden" name="job" value="' . am_h($id) . '">'
                    . '<label class="amb-check"><input type="checkbox" name="register_download" value="1"' . ($settings['registerDownload'] ? ' checked' : '') . '> ダウンロード一覧に登録</label>'
                    . '<button class="am-button is-small" type="submit">公開する</button></form>';
            }

            $html .= '<tr class="' . $rowClass . '">'
                . '<td data-label="バージョン"><div class="am-file">' . am_h($version) . '</div><div class="am-sub">' . am_h(isset($job['requestedBy']) ? $job['requestedBy'] : '') . '</div></td>'
                . '<td data-label="状態"><span class="am-badges"><span class="am-badge ' . $badge . '">' . am_h($label) . '</span></span>' . ($state === 'running' && $stage !== '' ? '<div class="am-sub">' . am_h($stage) . ' 中</div>' : '') . '</td>'
                . '<td data-label="依頼" class="is-date">' . am_h(amb_time(isset($job['requestedAt']) ? $job['requestedAt'] : '') ?: '-') . '</td>'
                . '<td data-label="開始" class="is-date">' . am_h(amb_time(isset($job['startedAt']) ? $job['startedAt'] : '') ?: '-') . '</td>'
                . '<td data-label="終了" class="is-date">' . am_h(amb_time(isset($job['finishedAt']) ? $job['finishedAt'] : '') ?: '-') . '</td>'
                . '<td data-label="コミット" class="amb-commit">' . ($commit !== '' ? '<code>' . am_h($commit) . '</code> <span class="amb-subject" title="' . am_h($subject) . '">' . am_h($subject) . '</span>' : '<span class="am-muted">-</span>') . '</td>'
                . '<td data-label="操作" class="is-ops"><div class="am-row-actions">' . ($ops !== '' ? $ops : '<span class="am-muted">-</span>') . '</div></td></tr>';

            // Second line: what needs attention stays visible, the rest folds into 詳細.
            $visible = '';
            if (!empty($job['error'])) $visible .= '<div class="amb-line is-error">' . am_h($job['error']) . '</div>';
            if ($state === 'running' && !empty($job['lastLine'])) $visible .= '<div class="amb-line"><span class="amb-line-label">最新の出力</span><code>' . am_h($job['lastLine']) . '</code></div>';
            $details = '';
            if (!empty($job['releaseNotes'])) $details .= '<div class="amb-notes">' . nl2br(am_h($job['releaseNotes'])) . '</div>';
            if (!empty($job['result']['setupUrl'])) $details .= '<div class="amb-line"><span class="amb-line-label">オンラインインストーラー</span><a href="' . am_h($job['result']['setupUrl']) . '">' . am_h($job['result']['setupUrl']) . '</a></div>';
            if ($isPublished) {
                $p = $published[$version];
                $details .= '<div class="amb-line"><span class="amb-line-label">公開</span>' . am_h(amb_time($p['publishedAt'])) . ' / ' . am_h($p['publishedBy'])
                    . (!empty($p['downloadLid']) ? ' / ダウンロード登録 ' . am_h($p['downloadLid']) : '') . (!empty($p['downloadError']) ? ' / ダウンロード登録失敗: ' . am_h($p['downloadError']) : '') . '</div>';
            }
            $tail = amb_log_tail($id);
            if ($tail !== '') $details .= '<div class="amb-log"><span class="amb-line-label">ログ（末尾）</span><pre>' . am_h($tail) . '</pre></div>';
            if ($visible !== '' || $details !== '') {
                $html .= '<tr class="amb-detail ' . $rowClass . '"><td colspan="7">' . $visible
                    . ($details !== '' ? '<details data-job="' . am_h($id) . '"><summary>詳細（更新内容' . ($tail !== '' ? '・ログ' : '') . '）</summary>' . $details . '</details>' : '') . '</td></tr>';
            }
        }
        $html .= '</tbody></table></div>';
        if (count($jobs) > $limit) $html .= '<p class="am-footnote">新しい順に ' . $limit . ' 件を表示しています（全 ' . count($jobs) . ' 件）。表示件数はコンフィギュレーションの「一覧の最大表示件数」で変更できます。</p>';
    }
    $html .= '</section>';
    return '<div id="amb-build" data-active="' . ($active !== null ? '1' : '0') . '" data-refresh-url="' . am_h($action) . '">' . $html . '</div>'
        . ($active !== null ? amb_refresh_script() : '');
}

// Replaces only #amb-build with a freshly fetched copy every 30 s while a build is active,
// keeping which 詳細 are open and the log scroll position (stuck to the bottom when it was there).
function amb_refresh_script()
{
    return <<<'JS'
<script>
(function () {
    var root = document.getElementById('amb-build');
    if (!root || !window.fetch || !window.DOMParser) return;
    var url = root.getAttribute('data-refresh-url');
    function schedule() { if (root.getAttribute('data-active') === '1') setTimeout(tick, 30000); }
    function tick() {
        if (root.contains(document.activeElement) && /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName)) { schedule(); return; }
        fetch(url, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) {
            if (!r.ok) throw new Error(r.status);
            return r.text();
        }).then(function (text) {
            var next = new DOMParser().parseFromString(text, 'text/html').getElementById('amb-build');
            if (!next) return;
            var state = {};
            Array.prototype.forEach.call(root.querySelectorAll('details[data-job]'), function (d) {
                var pre = d.querySelector('pre');
                state[d.getAttribute('data-job')] = {
                    open: d.open,
                    top: pre ? pre.scrollTop : 0,
                    bottom: pre ? pre.scrollHeight - pre.scrollTop - pre.clientHeight < 8 : true
                };
            });
            root.innerHTML = next.innerHTML;
            root.setAttribute('data-active', next.getAttribute('data-active'));
            Array.prototype.forEach.call(root.querySelectorAll('details[data-job]'), function (d) {
                var s = state[d.getAttribute('data-job')];
                if (!s || !s.open) return;
                d.open = true;
                var pre = d.querySelector('pre');
                if (pre) pre.scrollTop = s.bottom ? pre.scrollHeight : s.top;
            });
        }).catch(function () {}).then(schedule);
    }
    schedule();
})();
</script>
JS;
}
