<?php
namespace Happa\XPost;
require_once __DIR__ . '/Automation.php';

class ApiException extends \RuntimeException
{
    public $uncertain;
    public function __construct($message, $uncertain = false)
    {
        parent::__construct($message);
        $this->uncertain = $uncertain;
    }
}

class Text
{
    public static function plain($html)
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    // Conservative Unicode weighting; compound emoji may count more than in X.
    public static function weight($text)
    {
        $text = preg_replace('~https?://[a-zA-Z0-9./?&=%_#:+@!,$;*()\-]+~', str_repeat('a', 23), $text);
        if ($text === null || !preg_match('//u', $text)) {
            throw new \RuntimeException('投稿文はUTF-8で入力してください。');
        }
        preg_match_all('/./us', $text, $characters);
        $weight = 0;
        foreach ($characters[0] as $char) {
            $weight += preg_match('/^[\x{0000}-\x{10FF}\x{2000}-\x{200D}\x{2010}-\x{201F}\x{2032}-\x{2037}]$/u', $char) ? 1 : 2;
        }
        return $weight;
    }

    public static function validate($text, array $articles)
    {
        if (count($articles) < 1 || count($articles) > 2 || count(array_unique(array_column($articles, 'sid'))) !== count($articles)) {
            throw new \RuntimeException('異なる記事を1件または2件選択してください。');
        }
        if (trim($text) === '' || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text)) {
            throw new \RuntimeException('投稿文を入力してください（制御文字は使用できません）。');
        }
        if (self::weight($text) > 280) {
            throw new \RuntimeException('投稿文が280文字の上限を超えています。短くしてください。');
        }
        foreach ($articles as $article) {
            if (!preg_match('~(?:^|\s)' . preg_quote($article['url'], '~') . '(?=\s|$)~u', $text)) {
                throw new \RuntimeException('選択したすべての記事URLを投稿文に含めてください。');
            }
        }
    }

    public static function draft(array $articles)
    {
        $titles = array_map(function ($article) { return self::plain($article['title']); }, $articles);
        $links = implode("\n", array_map(function ($article) { return $article['url']; }, $articles));
        $suffix = "\n" . $links . "\n#AshwindChronicle #GameDev";
        while (self::weight(implode("\n", $titles) . $suffix) > 280) {
            $longest = 0;
            foreach ($titles as $i => $title) {
                if (self::weight($title) > self::weight($titles[$longest])) { $longest = $i; }
            }
            $titles[$longest] = preg_replace('/.$/us', '', $titles[$longest]);
        }
        return implode("\n", $titles) . $suffix;
    }
}

class Store
{
    private $directory;
    public static function privateDirectory($dataRoot, $webRoot)
    {
        $data = realpath($dataRoot); $web = realpath($webRoot);
        if ($data === false || $web === false) { throw new \RuntimeException('保存先と公開フォルダの設定を確認してください。'); }
        $data = str_replace('\\', '/', $data); $web = rtrim(str_replace('\\', '/', $web), '/');
        if (strcasecmp($data, $web) === 0 || stripos($data, $web . '/') === 0) {
            throw new \RuntimeException('X投稿の保存先は公開フォルダの外に設定してください。');
        }
        return rtrim($data, '/') . '/xpost';
    }
    public function __construct($directory)
    {
        $this->directory = rtrim($directory, '/\\');
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0750, true)) {
            throw new \RuntimeException('X投稿用データフォルダを作成できません。');
        }
    }
    public function read($name, array $fallback = array())
    {
        $path = $this->directory . '/' . $name . '.json';
        if (!file_exists($path)) { return $fallback; }
        $json = @file_get_contents($path);
        $data = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('X投稿の保存データを読み取れません。ファイルを確認してください。');
        }
        return $data;
    }
    public function write($name, array $data)
    {
        $path = $this->directory . '/' . $name . '.json';
        $temp = $path . '.tmp-' . bin2hex(random_bytes(8));
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false || @file_put_contents($temp, $json, LOCK_EX) === false) {
            @unlink($temp);
            throw new \RuntimeException('X投稿の保存に失敗しました。');
        }
        @chmod($temp, 0600);
        if (!@rename($temp, $path)) {
            @unlink($temp);
            throw new \RuntimeException('X投稿の保存に失敗しました。');
        }
    }
    public function locked(callable $action, $name = 'posting')
    {
        if (!in_array($name, array('posting', 'budget', 'feedback'), true)) { throw new \RuntimeException('Invalid lock'); }
        $handle = @fopen($this->directory . '/' . $name . '.lock', 'c');
        if (!$handle || !flock($handle, LOCK_EX | LOCK_NB)) {
            if ($handle) { fclose($handle); }
            throw new \RuntimeException('別の投稿処理が実行中です。しばらくしてから再確認してください。');
        }
        try { return $action(); }
        finally { flock($handle, LOCK_UN); fclose($handle); }
    }
    public function configuration()
    {
        return $this->read('credentials', array('username' => '__MeeSan__', 'api_key' => '', 'api_secret' => '', 'access_token' => '', 'access_secret' => ''));
    }
    public function configure(array $input)
    {
        return $this->locked(function () use ($input) {
            $config = $this->configuration();
            $original = $config;
            $username = ltrim(trim(isset($input['username']) ? $input['username'] : ''), '@');
            if (!preg_match('/^[A-Za-z0-9_]{1,15}$/D', $username)) {
                throw new \RuntimeException('Xアカウント名を正しく入力してください。');
            }
            $config['username'] = $username;
            foreach (array('api_key', 'api_secret', 'access_token', 'access_secret') as $key) {
                $value = isset($input[$key]) ? trim($input[$key]) : '';
                if ($value !== '') {
                    if (strlen($value) > 512 || preg_match('/[\x00-\x20\x7F]/', $value)) {
                        throw new \RuntimeException('認証情報の形式を確認してください。');
                    }
                    $config[$key] = $value;
                }
            }
            if ($config !== $original) { $this->write('credentials', $config); }
            return $config;
        });
    }
}

class Client
{
    private $config;
    private $transport;
    private $store;
    public function __construct(array $config, ?callable $transport = null, ?Store $store = null)
    {
        foreach (array('api_key', 'api_secret', 'access_token', 'access_secret', 'username') as $key) {
            if (empty($config[$key])) { throw new \RuntimeException('X APIの認証情報を設定してください。'); }
        }
        $this->config = $config;
        $this->transport = $transport;
        $this->store = $store;
    }
    public static function authorization($method, $url, array $config, $nonce, $timestamp)
    {
        $params = array('oauth_consumer_key' => $config['api_key'], 'oauth_nonce' => $nonce,
            'oauth_signature_method' => 'HMAC-SHA1', 'oauth_timestamp' => (string) $timestamp,
            'oauth_token' => $config['access_token'], 'oauth_version' => '1.0');
        $baseUrl = explode('?', $url, 2);
        $signing = $params;
        if (isset($baseUrl[1])) {
            foreach (explode('&', $baseUrl[1]) as $pair) {
                $parts = explode('=', $pair, 2);
                $signing[rawurldecode($parts[0])] = rawurldecode(isset($parts[1]) ? $parts[1] : '');
            }
        }
        ksort($signing, SORT_STRING);
        $encoded = array();
        foreach ($signing as $key => $value) { $encoded[] = rawurlencode($key) . '=' . rawurlencode($value); }
        $base = strtoupper($method) . '&' . rawurlencode($baseUrl[0]) . '&' . rawurlencode(implode('&', $encoded));
        $params['oauth_signature'] = base64_encode(hash_hmac('sha1', $base,
            rawurlencode($config['api_secret']) . '&' . rawurlencode($config['access_secret']), true));
        $header = array();
        foreach ($params as $key => $value) { $header[] = rawurlencode($key) . '="' . rawurlencode($value) . '"'; }
        return 'OAuth ' . implode(', ', $header);
    }
    public static function caBundle()
    {
        $path = dirname(__DIR__) . '/certs/cacert.pem';
        if (!is_file($path)) {
            throw new ApiException('X投稿プラグインのCA証明書ファイルがありません。配置を確認してください。');
        }
        return $path;
    }
    public static function connectionFailure($number, $method)
    {
        // Fixed messages only: raw cURL errors may contain server paths or proxy credentials.
        $messages = array(5 => 'プロキシの名前解決に失敗しました。', 6 => 'X APIの名前解決に失敗しました。',
            7 => 'X APIへ接続できません。', 28 => 'X APIへの接続がタイムアウトしました。',
            35 => 'X APIとのTLS接続に失敗しました。', 60 => 'X APIのサーバー証明書を検証できません。',
            77 => 'CA証明書ファイルを読み取れません。');
        $message = isset($messages[$number]) ? $messages[$number] : 'X APIとの通信に失敗しました。';
        return new ApiException($message . '（cURL ' . (int) $number . '）', $method === 'POST');
    }
    private function request($method, $path, ?array $payload = null, $envelope = false)
    {
        if (!$this->transport && !$this->store) { throw new ApiException('月額予算管理用の保存先が必要です。'); }
        try { $reservation = $this->store ? Budget::reserve($this->store, $method, $path, $payload) : null; }
        catch (\RuntimeException $e) { throw new ApiException($e->getMessage(), false); }
        $url = 'https://api.x.com' . $path;
        $headers = array('Accept: application/json', 'Content-Type: application/json',
            'Authorization: ' . self::authorization($method, $url, $this->config, bin2hex(random_bytes(16)), time()));
        $body = $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($this->transport) {
            $response = call_user_func($this->transport, $method, $url, $headers, $body);
        } else {
            if (!function_exists('curl_init')) { throw new ApiException('サーバーのPHP cURL拡張を有効にしてください。'); }
            $caBundle = self::caBundle();
            $curl = curl_init($url);
            curl_setopt_array($curl, array(CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 40,
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_CAINFO => $caBundle));
            if ($body !== null) { curl_setopt($curl, CURLOPT_POSTFIELDS, $body); }
            $raw = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $curlNumber = curl_errno($curl);
            curl_close($curl);
            if ($raw === false) { throw self::connectionFailure($curlNumber, $method); }
            $response = array('status' => $status, 'body' => $raw);
        }
        $status = (int) $response['status'];
        if ($this->store && $status >= 200 && $status < 300) {
            Budget::settle($this->store, $reservation, $method, $path, $payload, json_decode($response['body'], true));
        }
        if ($status < 200 || $status >= 300) {
            $errorData = json_decode($response['body'], true);
            $format = is_array($errorData) ? 'JSON' : (stripos(ltrim($response['body']), '<') === 0 ? 'HTML' : 'その他');
            $codes = array();
            if (isset($errorData['errors']) && is_array($errorData['errors'])) {
                foreach ($errorData['errors'] as $item) {
                    if (isset($item['code']) && is_numeric($item['code'])) { $codes[] = (int) $item['code']; }
                }
            }
            $detail = '（HTTP ' . $status . '、' . $format . '応答' . ($codes ? '、Xコード ' . implode('/', $codes) : '') . '）';
            $messages = array(401 => 'X APIの認証情報を確認してください。', 402 => 'X APIの利用枠・残高を確認してください。',
                403 => 'XアプリのRead and Write権限とAPI利用権限を確認してください。', 429 => 'X APIの利用制限です。時間をおいてください。');
            throw new ApiException((isset($messages[$status]) ? $messages[$status] : 'X APIが要求を受理しませんでした。') . $detail,
                $method === 'POST' && ($status >= 500 || $status < 400 || $status === 408));
        }
        $result = json_decode($response['body'], true);
        if ($envelope) {
            if (!is_array($result) || !empty($result['errors']) || !isset($result['meta']['result_count'])
                || !is_int($result['meta']['result_count']) || $result['meta']['result_count'] < 0
                || (isset($result['data']) && !is_array($result['data']))
                || count(isset($result['data']) ? $result['data'] : array()) !== $result['meta']['result_count']) {
                throw new ApiException('返信の取得結果が不完全、または確認不能です。返信なしとは判定していません。');
            }
            return $result;
        }
        if (!is_array($result) || empty($result['data'])) {
            throw new ApiException('X APIの応答を確認できませんでした。', $method === 'POST');
        }
        return $result['data'];
    }
    public function identity()
    {
        $user = $this->request('GET', '/2/users/me');
        if (empty($user['id']) || empty($user['username']) || strcasecmp($this->config['username'], $user['username']) !== 0) {
            throw new \RuntimeException('認証されたXアカウントが設定した投稿先と一致しません。');
        }
        return $user;
    }
    public function publish($text)
    {
        $post = $this->request('POST', '/2/tweets', array('text' => $text));
        if (empty($post['id']) || !preg_match('/^[0-9]+$/D', $post['id'])) {
            throw new ApiException('投稿IDを確認できませんでした。', true);
        }
        return $post;
    }
    public function replies($postId, $nextToken = '', array $options = array())
    {
        if (!preg_match('/^[0-9]{1,25}$/D', $postId) || !is_string($nextToken)
            || strlen($nextToken) > 2048 || preg_match('/[\x00-\x20\x7F]/', $nextToken)) {
            throw new \RuntimeException('返信の取得対象またはページ情報を確認してください。');
        }
        $query = array('query' => 'conversation_id:' . $postId . ' is:reply', 'max_results' => '100',
            'sort_order' => 'recency', 'tweet.fields' => 'author_id,created_at,conversation_id,referenced_tweets',
            'expansions' => 'author_id', 'user.fields' => 'name,username');
        if (isset($options['max_results'])) { $query['max_results'] = (string) max(10, min(100, (int) $options['max_results'])); }
        if (isset($options['authors']) && !$options['authors']) { unset($query['expansions'], $query['user.fields']); }
        if (!empty($options['exclude_self']) && preg_match('/^[A-Za-z0-9_]{1,15}$/D', $this->config['username'])) {
            $query['query'] .= ' -from:' . $this->config['username'];
        }
        foreach (array('since_id', 'until_id', 'start_time', 'end_time') as $field) {
            if (!empty($options[$field])) {
                $value = $options[$field];
                if (!is_string($value) || !preg_match(in_array($field, array('since_id', 'until_id'), true) ? '/^[0-9]{1,25}$/D' : '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/D', $value)) {
                    throw new \RuntimeException('差分取得の条件を確認してください。');
                }
                $query[$field] = $value;
            }
        }
        if ((isset($query['since_id']) || isset($query['until_id'])) &&
            (isset($query['start_time']) || isset($query['end_time']))) { throw new \RuntimeException('ID条件と日時条件を混在指定できません。'); }
        if ($nextToken !== '') { $query['next_token'] = $nextToken; }
        $result = $this->request('GET', '/2/tweets/search/recent?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986), null, true);
        $users = array();
        foreach (isset($result['includes']['users']) ? $result['includes']['users'] : array() as $user) {
            if (isset($user['id'])) { $users[$user['id']] = $user; }
        }
        $items = array();
        foreach (isset($result['data']) ? $result['data'] : array() as $reply) {
            if (!isset($reply['id'], $reply['text'], $reply['author_id'], $reply['created_at'], $reply['conversation_id'])
                || !preg_match('/^[0-9]{1,25}$/D', $reply['id']) || !is_string($reply['text'])
                || (string) $reply['conversation_id'] !== $postId) {
                throw new ApiException('返信の取得結果を確認できませんでした。');
            }
            $parent = '';
            foreach (isset($reply['referenced_tweets']) ? $reply['referenced_tweets'] : array() as $ref) {
                if (isset($ref['type'], $ref['id']) && $ref['type'] === 'replied_to') { $parent = (string) $ref['id']; }
            }
            if ($parent === '' || !preg_match('/^[0-9]{1,25}$/D', $parent)) { throw new ApiException('返信先を確認できませんでした。'); }
            $user = isset($users[$reply['author_id']]) ? $users[$reply['author_id']] : array();
            $items[] = array('id' => $reply['id'], 'text' => $reply['text'], 'created_at' => $reply['created_at'],
                'author_id' => (string) $reply['author_id'],
                'author' => isset($user['name']) ? $user['name'] : 'ユーザー情報未取得',
                'username' => isset($user['username']) ? $user['username'] : '', 'parent' => $parent,
                'direct' => $parent === $postId, 'url' => 'https://x.com/i/web/status/' . $reply['id']);
        }
        $next = isset($result['meta']['next_token']) ? $result['meta']['next_token'] : '';
        if (!is_string($next) || strlen($next) > 2048 || preg_match('/[\x00-\x20\x7F]/', $next)) {
            throw new ApiException('返信の次ページ情報を確認できませんでした。');
        }
        return array('items' => $items, 'next_token' => $next, 'checked_at' => gmdate('c'));
    }
    public function replyTarget($id, $root)
    {
        if (!preg_match('/^[0-9]{1,25}$/D', $id) || !preg_match('/^[0-9]{1,25}$/D', $root) || $id === $root) {
            throw new \RuntimeException('返信先コメントを選択してください。');
        }
        $post = $this->request('GET', '/2/tweets/' . $id . '?tweet.fields=conversation_id,author_id,referenced_tweets');
        if (!isset($post['id'], $post['text'], $post['conversation_id']) || (string) $post['id'] !== $id
            || (string) $post['conversation_id'] !== $root || !is_string($post['text'])) {
            throw new \RuntimeException('返信先が選択した告知の会話内にあることを確認できません。');
        }
        $parent = '';
        foreach (isset($post['referenced_tweets']) ? $post['referenced_tweets'] : array() as $ref) {
            if (isset($ref['type'], $ref['id']) && $ref['type'] === 'replied_to') { $parent = (string) $ref['id']; }
        }
        return array('id' => $id, 'text' => $post['text'], 'parent' => $parent);
    }
    public function publishReply($target, $text)
    {
        if (!preg_match('/^[0-9]{1,25}$/D', $target)) { throw new \RuntimeException('返信先を確認してください。'); }
        $post = $this->request('POST', '/2/tweets', array('text' => $text, 'reply' => array('in_reply_to_tweet_id' => $target)));
        if (empty($post['id']) || !preg_match('/^[0-9]+$/D', $post['id'])) {
            throw new ApiException('返信の投稿IDを確認できませんでした。自動再送しないでください。', true);
        }
        return $post;
    }
}

class Replies
{
    public static function target(Store $store, $key, array $config)
    {
        $history = $store->read('history');
        if (!isset($history[$key]['state'], $history[$key]['post_id'], $history[$key]['username'])
            || $history[$key]['state'] !== 'posted'
            || strcasecmp($history[$key]['username'], $config['username']) !== 0
            || !preg_match('/^[0-9]{1,25}$/D', $history[$key]['post_id'])) {
            throw new \RuntimeException('現在の投稿先アカウントの公開済み履歴を選択してください。');
        }
        return $history[$key]['post_id'];
    }
}

class ReplyPublisher
{
    public static function validate($text)
    {
        if (!is_string($text) || trim($text) === '' || strlen($text) > 12000
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text) || Text::weight($text) > 280) {
            throw new \RuntimeException('返信文を280文字以内で入力してください（制御文字は使用できません）。');
        }
    }
    private static function key(Store $store)
    {
        $data = $store->read('reply-preview-key');
        if (empty($data['key'])) { throw new \RuntimeException('返信確認情報がありません。内容を再確認してください。'); }
        return $data['key'];
    }
    public static function preview(Store $store, $key, $target, $text, $uid, array $config, Client $client)
    {
        self::validate($text);
        $root = Replies::target($store, $key, $config);
        $post = $client->replyTarget($target, $root);
        $store->locked(function () use ($store) {
            if (!$store->read('reply-preview-key')) { $store->write('reply-preview-key', array('key' => bin2hex(random_bytes(32)))); }
        });
        $data = array('key' => $key, 'root' => $root, 'target' => $target, 'target_text' => $post['text'], 'text' => $text,
            'uid' => (int) $uid, 'expires' => time() + 1200, 'config' => hash('sha256', json_encode($config)));
        $encoded = base64_encode(json_encode($data, JSON_UNESCAPED_UNICODE));
        $data['signed'] = $encoded . '.' . hash_hmac('sha256', $encoded, self::key($store));
        return $data;
    }
    public static function verify(Store $store, $signed, $uid, array $config)
    {
        $parts = explode('.', $signed);
        if (count($parts) !== 2 || !hash_equals(hash_hmac('sha256', $parts[0], self::key($store)), $parts[1])) {
            throw new \RuntimeException('返信確認情報が変更されています。内容を再確認してください。');
        }
        $data = json_decode(base64_decode($parts[0], true), true);
        if (!is_array($data) || !isset($data['key'], $data['root'], $data['target'], $data['text'], $data['uid'], $data['expires'], $data['config'])
            || $data['uid'] !== (int) $uid || $data['expires'] < time()
            || !hash_equals(hash('sha256', json_encode($config)), $data['config'])) {
            throw new \RuntimeException('返信確認情報が期限切れ、または投稿先設定が変更されています。内容を再確認してください。');
        }
        self::validate($data['text']);
        return $data;
    }
    public static function publish(Store $store, $signed, $uid, array $config, Client $client)
    {
        // This deployment is draft-only until X's written approval and a separate explicit enablement change.
        if (!Policy::replyPublishingAllowed($store)) { throw new \RuntimeException('返信の自動送信は無効です。Xの承認が得られるまでは下書きのみ作成します。'); }
        $data = self::verify($store, $signed, $uid, $config);
        return $store->locked(function () use ($store, $data, $uid, $config, $client) {
            if (Replies::target($store, $data['key'], $config) !== $data['root']) { throw new \RuntimeException('元の告知情報が変更されています。'); }
            $history = $store->read('reply-history');
            foreach ($history as $entry) {
                if (strcasecmp($entry['username'], $config['username']) === 0 && $entry['target'] === $data['target'] && $entry['state'] !== 'failed') {
                    throw new \RuntimeException('このコメントへは返信済み、または送信結果の確認待ちです。自動再送しないでください。');
                }
            }
            $user = $client->identity();
            $client->replyTarget($data['target'], $data['root']);
            $attempt = bin2hex(random_bytes(16));
            $history[$attempt] = array('root' => $data['root'], 'target' => $data['target'], 'text' => $data['text'],
                'username' => $user['username'], 'uid' => (int) $uid, 'state' => 'sending', 'at' => gmdate('c'), 'url' => '');
            $store->write('reply-history', $history);
            try {
                $post = $client->publishReply($data['target'], $data['text']);
                $history[$attempt]['state'] = 'posted';
                $history[$attempt]['post_id'] = $post['id'];
                $history[$attempt]['url'] = 'https://x.com/' . rawurlencode($user['username']) . '/status/' . $post['id'];
            } catch (ApiException $error) {
                $history[$attempt]['state'] = $error->uncertain ? 'uncertain' : 'failed';
                $store->write('reply-history', $history);
                throw new \RuntimeException($error->getMessage() . ($error->uncertain ? ' 返信履歴を確認し、自動再送しないでください。' : ' 返信は未送信です。'));
            }
            $store->write('reply-history', $history);
            return $history[$attempt];
        });
    }
}

class Publisher
{
    private $store;
    public function __construct(Store $store) { $this->store = $store; }
    public function publish(array $articles, $text, $uid, Client $client)
    {
        Text::validate($text, $articles);
        return $this->store->locked(function () use ($articles, $text, $uid, $client) {
            $history = $this->store->read('history');
            Policy::announcementDue($history);
            $sids = array_column($articles, 'sid');
            foreach ($history as $entry) {
                if (!isset($entry['sids'], $entry['state'])) { throw new \RuntimeException('投稿履歴の形式を確認してください。'); }
                if (array_intersect($sids, $entry['sids']) && $entry['state'] !== 'failed') {
                    throw new \RuntimeException('選択した記事は投稿済み、または送信結果の確認待ちです。履歴を確認してください。');
                }
            }
            $user = $client->identity();
            $key = bin2hex(random_bytes(16));
            $history[$key] = array('sids' => $sids, 'text' => $text, 'uid' => (int) $uid,
                'username' => $user['username'], 'user_id' => $user['id'], 'state' => 'sending', 'at' => gmdate('c'), 'url' => '');
            $this->store->write('history', $history);
            try {
                $post = $client->publish($text);
                $history[$key]['state'] = 'posted';
                $history[$key]['post_id'] = $post['id'];
                $history[$key]['url'] = 'https://x.com/' . rawurlencode($user['username']) . '/status/' . $post['id'];
            } catch (ApiException $error) {
                $history[$key]['state'] = $error->uncertain ? 'uncertain' : 'failed';
                $this->store->write('history', $history);
                throw new \RuntimeException($error->getMessage() . ($error->uncertain ? ' 投稿履歴からXの公開状態を確認してください。再送は停止しました。' : ''));
            }
            // A failed persistence leaves the durable "sending" marker, blocking duplicate attempts.
            $this->store->write('history', $history);
            return $history[$key];
        });
    }
    public function reconcile($key, $url, $notPublished)
    {
        return $this->store->locked(function () use ($key, $url, $notPublished) {
            $history = $this->store->read('history');
            if (!isset($history[$key]) || !in_array($history[$key]['state'], array('sending', 'uncertain'), true)) {
                throw new \RuntimeException('確認待ちの投稿を選択してください。');
            }
            if ($notPublished) {
                $history[$key]['state'] = 'failed';
            } else {
                if (!preg_match('~^https://x\.com/([A-Za-z0-9_]+)/status/([0-9]+)$~D', trim($url), $matches)
                    || strcasecmp($matches[1], $history[$key]['username']) !== 0) {
                    throw new \RuntimeException('履歴と同じアカウントの公開済み投稿URLを入力してください。');
                }
                $history[$key]['state'] = 'posted';
                $history[$key]['post_id'] = $matches[2];
                $history[$key]['url'] = trim($url);
            }
            $history[$key]['reconciled_at'] = gmdate('c');
            $this->store->write('history', $history);
        });
    }
}

class Preview
{
    private static function key(Store $store)
    {
        return $store->locked(function () use ($store) {
            $key = $store->read('preview-key');
            if (!$key) { $key = array('key' => bin2hex(random_bytes(32))); $store->write('preview-key', $key); }
            if (empty($key['key'])) { throw new \RuntimeException('確認画面の署名キーを読み取れません。'); }
            return $key['key'];
        });
    }
    public static function create(Store $store, array $articles, $text, $uid, array $config)
    {
        Text::validate($text, $articles);
        $data = base64_encode(json_encode(array('sids' => array_column($articles, 'sid'), 'text' => $text,
            'uid' => (int) $uid, 'expires' => time() + 1200, 'config' => hash('sha256', json_encode($config))), JSON_UNESCAPED_UNICODE));
        return $data . '.' . hash_hmac('sha256', $data, self::key($store));
    }
    public static function verify(Store $store, $signed, $uid, array $config)
    {
        $parts = explode('.', $signed);
        if (count($parts) !== 2 || !hash_equals(hash_hmac('sha256', $parts[0], self::key($store)), $parts[1])) {
            throw new \RuntimeException('確認画面の内容が変更されています。もう一度内容を確認してください。');
        }
        $data = json_decode(base64_decode($parts[0], true), true);
        if (!is_array($data) || !isset($data['uid'], $data['expires'], $data['sids'], $data['text'], $data['config'])
            || $data['uid'] !== (int) $uid || $data['expires'] < time()
            || !hash_equals(hash('sha256', json_encode($config)), $data['config'])) {
            throw new \RuntimeException('確認画面が期限切れ、またはAPI設定が変更されています。もう一度内容を確認してください。');
        }
        return $data;
    }
}
