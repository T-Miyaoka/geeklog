<?php
/**
 * Happa GA4 article report: library used by run-ga4-report.php and its tests.
 * Compatible with PHP 7.4 and later. Needs the openssl and json extensions; the
 * default HTTP transport needs curl.
 */

namespace Happa\Ga4Report;

class ReportException extends \RuntimeException
{
    /** @var string */
    public $codeName;
    /** @var bool */
    public $transient;

    public function __construct($codeName, $message, $transient = false)
    {
        parent::__construct($codeName . ': ' . $message);
        $this->codeName = $codeName;
        $this->transient = $transient;
    }
}

/**
 * Reads config.ini. Accepted keys: property_id, key_file, ca_bundle, timezone, history_days.
 * Relative paths are resolved against $root.
 */
function readConfig($root)
{
    $path = $root . '/credentials/config.ini';
    if (!is_file($path) || is_link($path)) {
        throw new ReportException('CONFIG_MISSING', 'credentials/config.ini was not found or is not a regular file.');
    }
    if (!is_readable($path)) {
        throw new ReportException('CONFIG_UNREADABLE', 'credentials/config.ini exists but the task identity cannot read it. Check the file ACL.');
    }
    $text = (string) @file_get_contents($path);
    $values = @parse_ini_string(preg_replace('/^\xEF\xBB\xBF/', '', $text), false, INI_SCANNER_RAW);
    if (!is_array($values)) {
        throw new ReportException('CONFIG_INVALID', 'credentials/config.ini could not be parsed; its contents are not logged.');
    }
    $allowed = array('property_id', 'key_file', 'ca_bundle', 'timezone', 'history_days');
    foreach (array_keys($values) as $key) {
        if (!in_array($key, $allowed, true)) {
            throw new ReportException('CONFIG_INVALID', 'Unsupported key ' . $key . '. Allowed: ' . implode(', ', $allowed) . '.');
        }
    }
    $config = array(
        'property_id' => trim(isset($values['property_id']) ? $values['property_id'] : ''),
        'key_file' => trim(isset($values['key_file']) ? $values['key_file'] : 'credentials/happa-ga4-reader.json'),
        'ca_bundle' => trim(isset($values['ca_bundle']) ? $values['ca_bundle'] : ''),
        'timezone' => trim(isset($values['timezone']) && $values['timezone'] !== '' ? $values['timezone'] : 'Asia/Tokyo'),
        'history_days' => (int) (isset($values['history_days']) && $values['history_days'] !== '' ? $values['history_days'] : 90),
    );
    if (!preg_match('/^[0-9]{6,15}$/', $config['property_id'])) {
        throw new ReportException('CONFIG_INVALID', 'property_id must be the numeric GA4 property ID (not the G- measurement ID).');
    }
    if (!in_array($config['timezone'], \DateTimeZone::listIdentifiers(), true)) {
        throw new ReportException('CONFIG_INVALID', 'timezone is not a valid IANA time zone.');
    }
    if ($config['history_days'] < 7 || $config['history_days'] > 800) {
        throw new ReportException('CONFIG_INVALID', 'history_days must be between 7 and 800.');
    }
    foreach (array('key_file', 'ca_bundle') as $key) {
        if ($config[$key] !== '' && !preg_match('#^([A-Za-z]:[\\\\/]|/)#', $config[$key])) {
            $config[$key] = $root . '/' . $config[$key];
        }
    }
    return $config;
}

/** Reads the service account key JSON and returns client_email, private_key and token_uri. */
function readServiceAccount($path)
{
    if (!is_file($path) || is_link($path)) {
        throw new ReportException('KEY_MISSING', 'The service account key file named by key_file was not found or is not a regular file.');
    }
    if (!is_readable($path)) {
        throw new ReportException('KEY_UNREADABLE', 'The service account key file exists but the task identity cannot read it. Check the file ACL.');
    }
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data) || (isset($data['type']) && $data['type'] !== 'service_account')
        || empty($data['client_email']) || empty($data['private_key'])) {
        throw new ReportException('KEY_INVALID', 'The key file is not a service account JSON key; its contents are not logged.');
    }
    return array(
        'client_email' => (string) $data['client_email'],
        'private_key' => (string) $data['private_key'],
        'token_uri' => !empty($data['token_uri']) ? (string) $data['token_uri'] : 'https://oauth2.googleapis.com/token',
    );
}

function base64Url($data)
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/** Builds the RS256-signed JWT assertion for the OAuth 2.0 service account flow. */
function buildAssertion(array $account, $now)
{
    $header = array('alg' => 'RS256', 'typ' => 'JWT');
    $claims = array(
        'iss' => $account['client_email'],
        'scope' => 'https://www.googleapis.com/auth/analytics.readonly',
        'aud' => $account['token_uri'],
        'iat' => $now,
        'exp' => $now + 3600,
    );
    $input = base64Url(json_encode($header)) . '.' . base64Url(json_encode($claims));
    $key = openssl_pkey_get_private($account['private_key']);
    if ($key === false) {
        throw new ReportException('KEY_INVALID', 'The private key in the key file could not be loaded.');
    }
    $signature = '';
    if (!openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256)) {
        throw new ReportException('KEY_INVALID', 'Signing the token request failed.');
    }
    return $input . '.' . base64Url($signature);
}

/** Default HTTP transport (curl). Returns array(status, body). */
function curlTransport($caBundle)
{
    return function ($method, $url, array $headers, $body) use ($caBundle) {
        if (!function_exists('curl_init')) {
            throw new ReportException('CURL_MISSING', 'Enable the PHP curl extension for the task.');
        }
        $curl = curl_init($url);
        $options = array(
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        );
        if ($caBundle !== '') {
            $options[CURLOPT_CAINFO] = $caBundle;
        }
        curl_setopt_array($curl, $options);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $errno = curl_errno($curl);
        curl_close($curl);
        if ($raw === false || $errno !== 0) {
            throw new ReportException('NETWORK_ERROR', 'curl error ' . $errno . ' for ' . parse_url($url, PHP_URL_HOST) . '.', true);
        }
        return array($status, (string) $raw);
    };
}

/** Summarizes a Google error body without echoing anything that could be sensitive. */
function describeApiError($status, $body)
{
    $decoded = json_decode($body, true);
    $detail = '';
    if (isset($decoded['error']['status'])) {
        $detail = (string) $decoded['error']['status'];
        if (isset($decoded['error']['message'])) {
            $detail .= ' ' . substr(preg_replace('/\s+/', ' ', (string) $decoded['error']['message']), 0, 300);
        }
    } elseif (isset($decoded['error'])) {
        $detail = is_string($decoded['error']) ? $decoded['error'] : 'error';
        if (isset($decoded['error_description'])) {
            $detail .= ' ' . substr((string) $decoded['error_description'], 0, 200);
        }
    }
    return 'HTTP ' . $status . ($detail !== '' ? ' ' . $detail : '');
}

class Client
{
    private $account;
    private $propertyId;
    private $http;
    private $clock;
    private $token = null;

    public function __construct(array $account, $propertyId, callable $http, callable $clock)
    {
        $this->account = $account;
        $this->propertyId = $propertyId;
        $this->http = $http;
        $this->clock = $clock;
    }

    private function accessToken()
    {
        if ($this->token !== null) {
            return $this->token;
        }
        $assertion = buildAssertion($this->account, (int) call_user_func($this->clock));
        $body = http_build_query(array(
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $assertion,
        ));
        list($status, $raw) = call_user_func($this->http, 'POST', $this->account['token_uri'],
            array('Content-Type: application/x-www-form-urlencoded'), $body);
        if ($status !== 200) {
            throw new ReportException('AUTH_FAILED', 'Token request was rejected: ' . describeApiError($status, $raw)
                . '. Check that the key for happa-ga4-reader is current and not disabled.', $status >= 500 || $status === 429);
        }
        $decoded = json_decode($raw, true);
        if (empty($decoded['access_token'])) {
            throw new ReportException('AUTH_FAILED', 'Token response had no access_token.');
        }
        $this->token = (string) $decoded['access_token'];
        return $this->token;
    }

    public function runReport(array $request)
    {
        $url = 'https://analyticsdata.googleapis.com/v1beta/properties/' . $this->propertyId . ':runReport';
        list($status, $raw) = call_user_func($this->http, 'POST', $url,
            array('Authorization: Bearer ' . $this->accessToken(), 'Content-Type: application/json'),
            json_encode($request));
        if ($status === 403) {
            throw new ReportException('PERMISSION_DENIED', describeApiError($status, $raw)
                . '. Add the happa-ga4-reader service account as a Viewer on the GA4 property and check property_id.');
        }
        if ($status !== 200) {
            throw new ReportException('API_ERROR', describeApiError($status, $raw), $status >= 500 || $status === 429);
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new ReportException('API_ERROR', 'runReport returned a body that is not JSON.', true);
        }
        return $decoded;
    }
}

/** Extracts the Geeklog story ID from a GA4 pagePathPlusQueryString value. */
function storyIdFromPath($path)
{
    if (preg_match('/[?&]story=([^&#]+)/', $path, $m)) {
        $sid = rawurldecode($m[1]);
    } elseif (preg_match('#/article\.php/([^/?&\#]+)#', $path, $m)) {
        $sid = rawurldecode($m[1]);
    } else {
        return null;
    }
    return preg_match('/^[A-Za-z0-9_.\-]{1,128}$/', $sid) ? $sid : null;
}

/** Returns the date ranges, ending with the last complete day before $today (Y-m-d). */
function dateRanges($today)
{
    $end = new \DateTimeImmutable($today . ' 00:00:00');
    $day = function ($offset) use ($end) {
        return $end->modify('-' . $offset . ' day')->format('Y-m-d');
    };
    return array(
        'yesterday' => array('start' => $day(1), 'end' => $day(1)),
        'last_7_days' => array('start' => $day(7), 'end' => $day(1)),
        'prev_7_days' => array('start' => $day(14), 'end' => $day(8)),
        'last_28_days' => array('start' => $day(28), 'end' => $day(1)),
    );
}

function reportRequest(array $range, $offset)
{
    return array(
        'dateRanges' => array(array('startDate' => $range['start'], 'endDate' => $range['end'])),
        'dimensions' => array(array('name' => 'pagePathPlusQueryString'), array('name' => 'pageTitle')),
        'metrics' => array(array('name' => 'screenPageViews'), array('name' => 'activeUsers'),
            array('name' => 'userEngagementDuration')),
        'dimensionFilter' => array('filter' => array('fieldName' => 'pagePathPlusQueryString',
            'stringFilter' => array('matchType' => 'CONTAINS', 'value' => 'article.php'))),
        'limit' => 10000,
        'offset' => $offset,
    );
}

/** Runs the reports and builds the article summary written to latest.json. */
function buildReport(Client $client, $today, $generatedAt)
{
    $ranges = dateRanges($today);
    $articles = array();
    $totals = array();
    foreach ($ranges as $name => $range) {
        $totals[$name] = array('views' => 0, 'active_users' => 0, 'engagement_seconds' => 0);
        $offset = 0;
        do {
            $response = $client->runReport(reportRequest($range, $offset));
            $rows = isset($response['rows']) && is_array($response['rows']) ? $response['rows'] : array();
            $rowCount = isset($response['rowCount']) ? (int) $response['rowCount'] : count($rows);
            foreach ($rows as $row) {
                $path = isset($row['dimensionValues'][0]['value']) ? (string) $row['dimensionValues'][0]['value'] : '';
                $title = isset($row['dimensionValues'][1]['value']) ? (string) $row['dimensionValues'][1]['value'] : '';
                $sid = storyIdFromPath($path);
                if ($sid === null) {
                    continue;
                }
                $views = (int) (isset($row['metricValues'][0]['value']) ? $row['metricValues'][0]['value'] : 0);
                $users = (int) (isset($row['metricValues'][1]['value']) ? $row['metricValues'][1]['value'] : 0);
                $seconds = (float) (isset($row['metricValues'][2]['value']) ? $row['metricValues'][2]['value'] : 0);
                if (!isset($articles[$sid])) {
                    $articles[$sid] = array('sid' => $sid, 'titles' => array(), 'metrics' => array());
                }
                if (!isset($articles[$sid]['metrics'][$name])) {
                    $articles[$sid]['metrics'][$name] = array('views' => 0, 'active_users' => 0, 'engagement_seconds' => 0.0);
                }
                $articles[$sid]['metrics'][$name]['views'] += $views;
                $articles[$sid]['metrics'][$name]['active_users'] += $users;
                $articles[$sid]['metrics'][$name]['engagement_seconds'] += $seconds;
                if ($title !== '' && $title !== '(not set)') {
                    $articles[$sid]['titles'][$title] = (isset($articles[$sid]['titles'][$title]) ? $articles[$sid]['titles'][$title] : 0) + $views;
                }
                $totals[$name]['views'] += $views;
                $totals[$name]['active_users'] += $users;
                $totals[$name]['engagement_seconds'] += $seconds;
            }
            $offset += count($rows);
        } while (count($rows) > 0 && $offset < $rowCount && $offset < 100000);
    }

    $out = array();
    foreach ($articles as $sid => $article) {
        arsort($article['titles']);
        $entry = array(
            'sid' => $sid,
            'language' => preg_match('/_(ja|en)$/', $sid, $m) ? $m[1] : null,
            'title' => $article['titles'] ? (string) key($article['titles']) : null,
            'url' => 'https://www.happa.bz/article.php?story=' . rawurlencode($sid),
        );
        foreach (array_keys($ranges) as $name) {
            $m = isset($article['metrics'][$name]) ? $article['metrics'][$name]
                : array('views' => 0, 'active_users' => 0, 'engagement_seconds' => 0.0);
            $entry[$name] = array(
                'views' => $m['views'],
                'active_users' => $m['active_users'],
                // Average engagement time per active user, in seconds (GA4's "average engagement time").
                'avg_engagement_seconds' => $m['active_users'] > 0 ? round($m['engagement_seconds'] / $m['active_users'], 1) : 0.0,
            );
        }
        $entry['views_change_7_days'] = $entry['last_7_days']['views'] - $entry['prev_7_days']['views'];
        $out[] = $entry;
    }
    usort($out, function ($a, $b) {
        if ($a['last_7_days']['views'] !== $b['last_7_days']['views']) {
            return $b['last_7_days']['views'] - $a['last_7_days']['views'];
        }
        if ($a['last_28_days']['views'] !== $b['last_28_days']['views']) {
            return $b['last_28_days']['views'] - $a['last_28_days']['views'];
        }
        return strcmp($a['sid'], $b['sid']);
    });
    foreach ($totals as $name => $t) {
        $totals[$name] = array('views' => $t['views'], 'active_users' => $t['active_users'],
            'avg_engagement_seconds' => $t['active_users'] > 0 ? round($t['engagement_seconds'] / $t['active_users'], 1) : 0.0);
    }

    return array(
        'schema_version' => 1,
        'generated_at' => $generatedAt,
        'report_date' => $ranges['yesterday']['end'],
        'ranges' => $ranges,
        'notes' => 'Articles only (article.php?story=...). Sorted by last_7_days views. avg_engagement_seconds is engagement time per active user; summed across URL variants of one article, so it is approximate.',
        'totals' => $totals,
        'articles' => $out,
    );
}

/** Writes JSON atomically (temporary file then rename) so readers never see a partial file. */
function writeJsonAtomic($path, array $data)
{
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
        throw new ReportException('WRITE_FAILED', 'Could not create ' . basename($dir) . '.');
    }
    $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || @file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
        @unlink($tmp);
        throw new ReportException('WRITE_FAILED', 'Could not write ' . basename($path) . '.');
    }
    if (is_file($path)) {
        @unlink($path); // rename() over an existing file can fail on Windows.
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new ReportException('WRITE_FAILED', 'Could not replace ' . basename($path) . '.');
    }
}

/** Removes history files older than $keepDays. Only touches files named YYYY-MM-DD.json. */
function pruneHistory($dir, $today, $keepDays)
{
    $cutoff = (new \DateTimeImmutable($today))->modify('-' . $keepDays . ' day')->format('Y-m-d');
    $removed = 0;
    foreach ((array) glob($dir . '/*.json') as $file) {
        $name = basename($file, '.json');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $name) && strcmp($name, $cutoff) < 0 && @unlink($file)) {
            $removed++;
        }
    }
    return $removed;
}
