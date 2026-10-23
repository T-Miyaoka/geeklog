<?php
namespace Happa\XPost;

class Policy
{
    public static function replyPublishingAllowed(Store $store)
    {
        $policy = $store->read('reply-policy');
        return isset($policy['user_enabled']) && $policy['user_enabled'] === true && !empty($policy['x_approval_reference']);
    }
    const DEFAULT_ANNOUNCEMENT_INTERVAL_HOURS = 24;
    const MAX_ANNOUNCEMENT_INTERVAL_HOURS = 720;

    /**
     * Minimum hours between blog announcements, from the Geeklog configuration
     * (xpost / announcement_interval_hours). 0 disables the interval check only;
     * per-article duplicate protection is unaffected. Missing or invalid values
     * fall back to the 24-hour default rather than disabling the guard.
     */
    public static function announcementIntervalHours($configured = null)
    {
        if ($configured === null) { $configured = self::configuredAnnouncementInterval(); }
        if (is_float($configured) && floor($configured) === $configured) { $configured = (int) $configured; }
        if (is_string($configured) && preg_match('/^\s*([0-9]{1,3})\s*$/D', $configured, $match)) { $configured = (int) $match[1]; }
        if (!is_int($configured) || $configured < 0 || $configured > self::MAX_ANNOUNCEMENT_INTERVAL_HOURS) {
            return self::DEFAULT_ANNOUNCEMENT_INTERVAL_HOURS;
        }
        return $configured;
    }
    private static function configuredAnnouncementInterval()
    {
        if (!class_exists('config')) { return null; }
        $values = \config::get_instance()->get_config('xpost');
        return is_array($values) && array_key_exists('announcement_interval_hours', $values) ? $values['announcement_interval_hours'] : null;
    }
    public static function announcementDue(array $history, $now = null, $intervalHours = null)
    {
        $now = $now === null ? time() : $now;
        $hours = self::announcementIntervalHours($intervalHours);
        if ($hours === 0) { return; }
        foreach ($history as $entry) {
            if (isset($entry['state'], $entry['at']) && in_array($entry['state'], array('posted', 'sending', 'uncertain'), true)) {
                $at = strtotime($entry['at']);
                if ($at === false) { throw new \RuntimeException('告知時刻が確認不能のため送信を停止しました。'); }
                if ($now - $at < $hours * 3600) { throw new \RuntimeException('前回のブログ告知から' . $hours . '時間が経過していません。'); }
            }
        }
    }
}

class Budget
{
    const LIMIT = 20000; // integer milli-USD, never floating-point money
    public static function state(Store $store, $month = null)
    {
        $month = $month === null ? gmdate('Y-m') : $month;
        $all = $store->read('api-budget');
        $row = isset($all[$month]) ? $all[$month] : array('spent' => 0, 'pending' => array(), 'started_at' => gmdate('c'));
        if (!isset($row['spent'], $row['pending']) || !is_int($row['spent']) || $row['spent'] < 0 || !is_array($row['pending'])) {
            throw new \RuntimeException('API予算台帳を確認できないため停止しました。');
        }
        $reserved = 0;
        foreach ($row['pending'] as $amount) {
            if (!is_int($amount) || $amount < 0) { throw new \RuntimeException('API予算の予約額が不正です。'); }
            $reserved += $amount;
        }
        return array('month' => $month, 'spent' => $row['spent'], 'reserved' => $reserved, 'limit' => self::LIMIT,
            'warning' => $row['spent'] + $reserved >= 16000, 'blocked' => $row['spent'] + $reserved >= self::LIMIT,
            'scope' => 'xpost only; UTC month; earlier and external usage excluded', 'started_at' => $row['started_at']);
    }
    private static function ceiling($method, $path, $payload)
    {
        if ($method === 'POST' && $path === '/2/tweets') {
            return !empty($payload['text']) && preg_match('~https?://~i', $payload['text']) ? 200 : 15;
        }
        if ($method === 'GET' && $path === '/2/users/me') { return 10; }
        if ($method === 'GET' && preg_match('~^/2/tweets/[0-9]+\?~', $path)) { return 5; }
        if ($method === 'GET' && strpos($path, '/2/tweets/search/recent?') === 0) {
            parse_str(parse_url($path, PHP_URL_QUERY), $query);
            $n = isset($query['max_results']) ? (int) $query['max_results'] : 100;
            return $n * (isset($query['expansions']) ? 15 : 5);
        }
        throw new \RuntimeException('料金未定義のAPIは停止しました。');
    }
    public static function reserve(Store $store, $method, $path, $payload)
    {
        return $store->locked(function () use ($store, $method, $path, $payload) {
            $state = self::state($store); $amount = self::ceiling($method, $path, $payload);
            if ($state['spent'] + $state['reserved'] + $amount > self::LIMIT) {
                throw new \RuntimeException('月額20ドルのAPI予算に必要額を確保できないため停止しました。');
            }
            $all = $store->read('api-budget');
            $month = $state['month'];
            if (!isset($all[$month])) { $all[$month] = array('spent' => 0, 'pending' => array(), 'started_at' => gmdate('c')); }
            $id = bin2hex(random_bytes(12)); $all[$month]['pending'][$id] = $amount;
            // Retain 12 monthly summaries; never clear an outstanding reservation automatically.
            foreach ($all as $old => $row) { if ($old < gmdate('Y-m', strtotime('-12 months')) && empty($row['pending'])) { unset($all[$old]); } }
            $store->write('api-budget', $all);
            return array('month' => $month, 'id' => $id, 'amount' => $amount);
        }, 'budget');
    }
    public static function settle(Store $store, array $reservation, $method, $path, $payload, $result)
    {
        // Malformed/error responses retain the full reservation. No assumed free failures or UTC-day discounts.
        if (!is_array($result) || !empty($result['errors'])) { return; }
        $cost = $reservation['amount'];
        if ($method === 'GET') {
            if (strpos($path, '/2/tweets/search/recent?') === 0) {
                if (!isset($result['meta']['result_count']) || !is_int($result['meta']['result_count'])
                    || $result['meta']['result_count'] !== count(isset($result['data']) ? $result['data'] : array())) { return; }
                $cost = $result['meta']['result_count'] * 5 + count(isset($result['includes']['users']) ? $result['includes']['users'] : array()) * 10;
            } elseif (empty($result['data'])) { return; }
        } elseif (empty($result['data']['id'])) { return; }
        if ($cost > $reservation['amount']) { throw new \RuntimeException('API料金見積りを超える応答のため停止しました。'); }
        $store->locked(function () use ($store, $reservation, $cost) {
            $all = $store->read('api-budget'); $month = $reservation['month']; $id = $reservation['id'];
            if (!isset($all[$month]['pending'][$id])) { throw new \RuntimeException('API予算の予約記録がありません。'); }
            unset($all[$month]['pending'][$id]); $all[$month]['spent'] += $cost; $store->write('api-budget', $all);
        }, 'budget');
    }
}

class Feedback
{
    private $store;
    public function __construct(Store $store) { $this->store = $store; }
    private static function newer($a, $b) { return strlen($a) > strlen($b) || (strlen($a) === strlen($b) && strcmp($a, $b) > 0); }
    public function sync(Client $client, array $config, $now = null)
    {
        $now = $now === null ? time() : $now;
        return $this->store->locked(function () use ($client, $config, $now) {
            $state = $this->store->read('feedback', array('roots' => array(), 'items' => array(), 'last_sync' => 0));
            if ($now - $state['last_sync'] < 28800) { return array('status' => 'not_due', 'new_items' => 0); }
            $history = $this->store->read('history'); $roots = array();
            foreach ($history as $entry) {
                if ($entry['state'] !== 'posted' || empty($entry['post_id']) || empty($entry['username'])
                    || strcasecmp($entry['username'], $config['username']) !== 0) { continue; }
                $stamp = isset($entry['at']) ? strtotime($entry['at']) : false;
                if ($stamp === false || $stamp < $now - 7 * 86400) { continue; }
                $roots[$entry['post_id']] = $entry;
            }
            // Roots with an unfinished page are first, followed by least-recently checked roots.
            uksort($roots, function ($a, $b) use ($state) {
                $ra = isset($state['roots'][$a]) ? $state['roots'][$a] : array(); $rb = isset($state['roots'][$b]) ? $state['roots'][$b] : array();
                $pa = empty($ra['next']) ? 1 : 0; $pb = empty($rb['next']) ? 1 : 0;
                return $pa !== $pb ? $pa - $pb : ((isset($ra['checked']) ? $ra['checked'] : 0) <=> (isset($rb['checked']) ? $rb['checked'] : 0));
            });
            $pages = 0; $added = 0;
            foreach ($roots as $root => $entry) {
                $root = (string) $root;
                $r = isset($state['roots'][$root]) ? $state['roots'][$root] : array('since' => '', 'checked' => 0, 'next' => '');
                if (empty($r['next'])) {
                    if ($now - $r['checked'] < 28800) { continue; }
                    $r['end'] = gmdate('Y-m-d\TH:i:s\Z', $now - 30);
                    $r['highest'] = $r['since'];
                    // Daily 24-hour overlap recovers delayed search indexing; IDs deduplicate analysis.
                    $r['overlap'] = empty($r['reconciled']) || $now - $r['reconciled'] >= 86400;
                    $r['start'] = gmdate('Y-m-d\TH:i:s\Z', $now - (empty($r['since']) ? 7 * 86400 - 60 : 86400));
                }
                do {
                    if ($pages >= 12) { break 2; }
                    $options = array('max_results' => 10, 'authors' => false, 'exclude_self' => true);
                    if ($r['overlap'] || $r['since'] === '') {
                        $options['start_time'] = $r['start']; $options['end_time'] = $r['end'];
                    } else {
                        // X rejects ID bounds mixed with time bounds. Keep the saved end
                        // fixed across resumed pages by using the first Snowflake at that millisecond.
                        $end = strtotime($r['end']);
                        if ($end === false || PHP_INT_SIZE < 8 || $end < 1288834975 || $end > 3487858230) {
                            throw new \RuntimeException('差分取得の終了境界を確認できません。');
                        }
                        $options['since_id'] = $r['since'];
                        $options['until_id'] = (string) (($end * 1000 - 1288834974657) * 4194304);
                    }
                    try { $result = $client->replies($root, $r['next'], $options); }
                    catch (\Throwable $e) {
                        $state['roots'][$root] = $r; $this->store->write('feedback', $state);
                        throw $e;
                    }
                    ++$pages;
                    foreach ($result['items'] as $item) {
                        if (self::newer($item['id'], $r['highest'])) { $r['highest'] = $item['id']; }
                        if (isset($entry['user_id']) && $item['author_id'] === (string) $entry['user_id']) { continue; }
                        if (!isset($state['items'][$item['id']])) {
                            $state['items'][$item['id']] = array('root' => $root, 'root_key' => array_search($entry, $history, true),
                                'source' => $item, 'received' => $now, 'status' => 'pending'); ++$added;
                        }
                    }
                    $r['next'] = $result['next_token'];
                    if ($r['next'] === '') {
                        $r['since'] = $r['highest']; $r['checked'] = $now;
                        if ($r['overlap']) { $r['reconciled'] = $now; }
                    }
                    $state['roots'][$root] = $r;
                    $this->store->write('feedback', $state); // Save each page before following its token.
                } while ($r['next'] !== '');
            }
            $delivery = self::deliveryStates($this->store->read('reply-history'));
            foreach ($state['items'] as $id => &$item) {
                if ($item['received'] < $now - 30 * 86400) {
                    unset($item['source']['text'], $item['analysis']['summary'], $item['analysis']['draft']);
                    if ($item['status'] === 'pending') { $item['status'] = 'needs_review'; }
                }
                if ($item['received'] < $now - 90 * 86400
                    && (empty($item['approval']) || (isset($delivery[$id]) && $delivery[$id]['state'] === 'posted'))) { unset($state['items'][$id]); }
            }
            unset($item);
            foreach ($state['roots'] as $id => $r) { if (!isset($roots[$id]) && isset($r['checked']) && $r['checked'] < $now - 90 * 86400) { unset($state['roots'][$id]); } }
            $state['last_sync'] = $now; $this->store->write('feedback', $state);
            $incomplete = 0;
            foreach ($roots as $id => $entry) {
                if (!isset($state['roots'][$id]) || !empty($state['roots'][$id]['next']) || $now - $state['roots'][$id]['checked'] >= 28800) { ++$incomplete; }
            }
            return array('status' => $incomplete ? 'partial' : 'synced', 'new_items' => $added, 'pages' => $pages, 'incomplete_roots' => $incomplete);
        }, 'feedback');
    }
    public function pending()
    {
        $state = $this->store->read('feedback', array('items' => array()));
        return array_filter($state['items'], function ($item) { return $item['status'] === 'pending'; });
    }
    public static function draftVersion(array $item, array $config)
    {
        return hash('sha256', json_encode(array(isset($item['root']) ? $item['root'] : '', isset($item['root_key']) ? $item['root_key'] : '',
            isset($item['source']) ? $item['source'] : array(), isset($item['analysis']) ? $item['analysis'] : array(), hash('sha256', json_encode($config)))));
    }
    public static function deliveryStates(array $replies)
    {
        $states = array(); $rank = array('failed' => 1, 'sending' => 2, 'uncertain' => 3, 'posted' => 4);
        foreach ($replies as $reply) {
            if (!isset($reply['target'], $reply['state'], $rank[$reply['state']])) { continue; }
            $id = (string) $reply['target'];
            if (!isset($states[$id]) || $rank[$reply['state']] >= $rank[$states[$id]['state']]) { $states[$id] = $reply; }
        }
        return $states;
    }
    public static function inbox(array $state, array $replies)
    {
        $delivery = self::deliveryStates($replies);
        $source = isset($state['items']) ? $state['items'] : array();
        foreach ($delivery as $id => $entry) {
            if ($entry['state'] !== 'posted' && !isset($source[$id])) {
                $source[$id] = array('status' => 'needs_review', 'root' => isset($entry['root']) ? $entry['root'] : '',
                    'analysis' => array('draft' => isset($entry['text']) ? $entry['text'] : ''), 'delivery_orphan' => true);
            }
        }
        $items = array();
        foreach ($source as $id => $item) {
            if (isset($delivery[$id]) && $delivery[$id]['state'] === 'posted') { continue; }
            if (isset($delivery[$id])) { $item['delivery'] = $delivery[$id]; $items[$id] = $item; continue; }
            if (!empty($item['approval']) || (isset($item['review']['state']) && $item['review']['state'] === 'declined')) { $items[$id] = $item; continue; }
            if (!empty($item['review'])) { continue; }
            $status = isset($item['status']) ? $item['status'] : 'needs_review';
            $draft = isset($item['analysis']['draft']) ? $item['analysis']['draft'] : '';
            if (in_array($status, array('pending', 'needs_review', 'question'), true)
                || ($draft !== '' && in_array($status, array('registered', 'duplicate'), true))) { $items[$id] = $item; }
        }
        return $items;
    }
    public function approve($id, $version, $uid, array $config)
    {
        if (!preg_match('/^[0-9]{1,25}$/D', $id) || !preg_match('/^[a-f0-9]{64}$/D', $version) || (int) $uid < 2) {
            throw new \RuntimeException('承認対象を確認してください。');
        }
        return $this->store->locked(function () use ($id, $version, $uid, $config) {
            $state = $this->store->read('feedback');
            $inbox = self::inbox($state, $this->store->read('reply-history'));
            if (!isset($inbox[$id]['analysis']['draft']) || $inbox[$id]['analysis']['draft'] === '' || !empty($inbox[$id]['delivery'])
                || (!empty($inbox[$id]['approval']['version']) && hash_equals($inbox[$id]['approval']['version'], self::draftVersion($inbox[$id], $config)))
                || !in_array($inbox[$id]['status'], array('registered', 'duplicate'), true)) {
                throw new \RuntimeException('この下書きは承認済み・処理済み、または承認できる状態ではありません。');
            }
            $item = $inbox[$id];
            if (!hash_equals(self::draftVersion($item, $config), $version)) { throw new \RuntimeException('下書き又は投稿先が変更されました。再読み込みして確認してください。'); }
            if (empty($item['analysis']['backlog_id']) || empty($item['analysis']['commit'])
                || Replies::target($this->store, $item['root_key'], $config) !== (string) $item['root']) {
                throw new \RuntimeException('改善項目又は返信先の確認情報が不足しています。');
            }
            ReplyPublisher::validate($item['analysis']['draft']);
            $state['items'][$id]['approval'] = array('at' => gmdate('c'), 'uid' => (int) $uid, 'version' => $version, 'state' => 'approved', 'sent' => false);
            unset($state['items'][$id]['review']);
            $this->store->write('feedback', $state);
            return $state['items'][$id]['approval'];
        }, 'feedback');
    }
    public function resolve($id, $version, $uid, array $config, $decline = false)
    {
        if (!preg_match('/^[0-9]{1,25}$/D', $id) || !preg_match('/^[a-f0-9]{64}$/D', $version) || (int) $uid < 2) { throw new \RuntimeException('確認対象を選択してください。'); }
        return $this->store->locked(function () use ($id, $version, $uid, $config, $decline) {
            $state = $this->store->read('feedback'); $items = self::inbox($state, $this->store->read('reply-history'));
            $valid = isset($items[$id]) && empty($items[$id]['approval']) && empty($items[$id]['delivery']) && empty($items[$id]['review']) && ($decline
                ? in_array($items[$id]['status'], array('registered', 'duplicate'), true) && !empty($items[$id]['analysis']['draft'])
                : in_array($items[$id]['status'], array('question', 'needs_review'), true) && empty($items[$id]['analysis']['draft']));
            if (!$valid) { throw new \RuntimeException('確認済み・分析待ち、又は指定の操作ができない項目です。'); }
            if (!hash_equals(self::draftVersion($items[$id], $config), $version)) { throw new \RuntimeException('返信内容が変更されました。再読み込みしてください。'); }
            $state['items'][$id]['review'] = array('at' => gmdate('c'), 'uid' => (int) $uid, 'state' => $decline ? 'declined' : 'handled');
            $this->store->write('feedback', $state);
        }, 'feedback');
    }
    public function record($id, array $analysis)
    {
        if (array_diff(array_keys($analysis), array('status', 'summary', 'draft', 'backlog_id', 'commit'))) { throw new \RuntimeException('未定義の分析項目があります。'); }
        $allowed = array('registered', 'duplicate', 'question', 'reaction', 'spam', 'needs_review', 'already_replied');
        if (!isset($analysis['status'], $analysis['summary'], $analysis['draft']) || !in_array($analysis['status'], $allowed, true)
            || !is_string($analysis['summary']) || strlen($analysis['summary']) > 4000 || !is_string($analysis['draft'])) {
            throw new \RuntimeException('返信分析の記録形式を確認してください。');
        }
        if ($analysis['draft'] !== '') { ReplyPublisher::validate($analysis['draft']); }
        if ($analysis['draft'] !== '' && !in_array($analysis['status'], array('registered', 'duplicate'), true)) {
            throw new \RuntimeException('お礼下書きは改善項目への新規登録又は関連付けを確認した提案だけが対象です。');
        }
        if (in_array($analysis['status'], array('registered', 'duplicate'), true)
            && (empty($analysis['backlog_id']) || !preg_match('/^[A-Z]+-[0-9]{3}$/D', $analysis['backlog_id']) || empty($analysis['commit']))) {
            throw new \RuntimeException('改善項目IDと確認済みコミットが必要です。');
        }
        return $this->store->locked(function () use ($id, $analysis) {
            $state = $this->store->read('feedback');
            if (!isset($state['items'][$id])) { throw new \RuntimeException('取得済み返信IDを指定してください。'); }
            if ($state['items'][$id]['status'] !== 'pending') { throw new \RuntimeException('この返信は分析済みです。'); }
            foreach ($this->store->read('reply-history') as $reply) {
                if (isset($reply['target'], $reply['state']) && $reply['target'] === $id && $reply['state'] !== 'failed' && $analysis['draft'] !== '') {
                    throw new \RuntimeException('返信済み又は結果不明のコメントへ重複する下書きは作成できません。');
                }
            }
            $state['items'][$id]['status'] = $analysis['status']; $state['items'][$id]['analysis'] = $analysis;
            $state['items'][$id]['analyzed_at'] = gmdate('c'); $this->store->write('feedback', $state);
            return $state['items'][$id];
        }, 'feedback');
    }
}
