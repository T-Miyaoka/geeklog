<?php
/**
 * Token-authenticated Backlog API helpers (used by public_html/admin/plugins/backlog/api.php).
 * Reads come from the database; writes are validated and placed in the private importer queue
 * (happa-article-inbox/backlog/ready), which applies them with its own Geeklog authentication.
 */

const BACKLOG_API_STATUS_LABELS = array(0 => '新規', 1 => '対応中', 2 => '承認待ち', 3 => 'フィードバック', 4 => '完了', 5 => '保留');
const BACKLOG_API_MAX_BODY = 65536;

/** Constant-time check of "Authorization: Bearer <token>" against the stored SHA-256 (hex) of the token. */
function BACKLOG_apiAuthorized($authorization, $hashFile)
{
    if (!is_string($authorization) || !preg_match('/\ABearer ([A-Za-z0-9_-]{32,128})\z/', trim($authorization), $match)) {
        return false;
    }
    if (!is_file($hashFile) || is_link($hashFile)) {
        return false;
    }
    $stored = strtolower(trim((string) file_get_contents($hashFile)));
    if (!preg_match('/\A[0-9a-f]{64}\z/', $stored)) {
        return false;
    }
    return hash_equals($stored, hash('sha256', $match[1]));
}

function BACKLOG_apiOverview($text)
{
    $text = str_replace("\r\n", "\n", (string) $text);
    $text = preg_replace('/\ACodex作業ID: [^\n]*\n/u', '', $text);
    if (preg_match('/【概要】\n(.*?)(?:\n\s*\n|\n【詳細】|\z)/us', $text, $match)) {
        return trim($match[1]);
    }
    foreach (explode("\n", $text) as $line) {
        if (trim($line) !== '') { return trim($line); }
    }
    return '';
}

/** Source ID of tickets created through the private inbox ("Codex作業ID: <uuid>" first line), or null. */
function BACKLOG_apiSourceId($text)
{
    return preg_match('/\ACodex作業ID: ([a-f0-9-]{36})(?:\r?\n|\z)/u', (string) $text, $match) ? $match[1] : null;
}

/** Whitelisted list filters. Values never reach SQL unescaped. */
function BACKLOG_apiListFilters($query)
{
    $category = $query['category'] ?? 'all';
    if (!in_array($category, array('improvement', 'other', 'all'), true)) {
        throw new InvalidArgumentException('category must be improvement, other or all.');
    }
    $statuses = array();
    if (isset($query['status']) && $query['status'] !== '') {
        foreach (explode(',', (string) $query['status']) as $value) {
            if (!preg_match('/\A[0-5]\z/', $value)) { throw new InvalidArgumentException('status must be a comma list of 0-5.'); }
            $statuses[] = (int) $value;
        }
    }
    $limit = isset($query['limit']) ? (int) $query['limit'] : 500;
    $offset = isset($query['offset']) ? (int) $query['offset'] : 0;
    if ($limit < 1 || $limit > 2000 || $offset < 0) {
        throw new InvalidArgumentException('limit must be 1-2000 and offset must not be negative.');
    }
    $search = isset($query['q']) ? (string) $query['q'] : '';
    if (strlen($search) > 200) { throw new InvalidArgumentException('q must be at most 200 bytes.'); }
    return array('category' => $category, 'statuses' => array_values(array_unique($statuses)), 'limit' => $limit,
        'offset' => $offset, 'q' => $search, 'include_text' => ($query['include_text'] ?? '0') === '1');
}

function BACKLOG_apiListWhere($filters, $escape)
{
    $where = array();
    if ($filters['category'] !== 'all') { $where[] = "t.work_category = '" . $filters['category'] . "'"; }
    if ($filters['statuses']) { $where[] = 't.status IN (' . implode(',', array_map('intval', $filters['statuses'])) . ')'; }
    if ($filters['q'] !== '') {
        $like = $escape(addcslashes($filters['q'], '%_\\'));
        $where[] = "(t.title LIKE '%{$like}%' OR t.ticketid = '" . $escape($filters['q']) . "')";
    }
    return $where ? 'WHERE ' . implode(' AND ', $where) : '';
}

function BACKLOG_apiTicket($row, $includeText)
{
    $ticket = array(
        'id' => $row['ticketid'],
        'title' => $row['title'],
        'status' => (int) $row['status'],
        'status_label' => BACKLOG_API_STATUS_LABELS[(int) $row['status']] ?? '',
        'category' => $row['work_category'],
        'registered_at' => $row['regdate'] ? date(DATE_ATOM, (int) $row['regdate']) : null,
        'limit_date' => sprintf('%04d-%02d-%02d', (int) $row['limityear'], (int) $row['limitmonth'], (int) $row['limitday']),
        'holder' => $row['holder_name'] ?? null,
        'register' => $row['register_name'] ?? null,
        'comments' => (int) ($row['comment_count'] ?? 0),
        'last_comment_at' => !empty($row['last_comment']) ? date(DATE_ATOM, (int) $row['last_comment']) : null,
        'source_id' => BACKLOG_apiSourceId($row['text']),
        'overview' => BACKLOG_apiOverview($row['text']),
        'url' => 'https://www.happa.bz/admin/plugins/backlog/index.php?mode=comment&id=' . $row['ticketid'],
    );
    if ($includeText) { $ticket['text'] = $row['text']; }
    return $ticket;
}

/** Queue file name: unique per request content, accepted by the importer's name check. */
function BACKLOG_apiQueueName($body, $now)
{
    return 'api-' . gmdate('YmdHis', $now) . '-' . substr(hash('sha256', $body), 0, 16);
}

/** Writes outside ready first and moves it in as the last step (the importer's partial-copy rule). */
function BACKLOG_apiEnqueue($queueRoot, $name, $body)
{
    if (!preg_match('/\A[a-zA-Z0-9_-]{1,100}\z/', $name)) { throw new InvalidArgumentException('Invalid queue name.'); }
    $ready = $queueRoot . '/ready';
    if (!is_dir($ready)) { throw new RuntimeException('The Backlog queue is not available.'); }
    foreach (array('ready', 'processing', 'done', 'failed') as $state) {
        if (file_exists($queueRoot . '/' . $state . '/' . $name . '.json')) { return false; }
    }
    $temporary = $queueRoot . '/' . $name . '.json.uploading';
    if (file_put_contents($temporary, $body, LOCK_EX) !== strlen($body)) {
        @unlink($temporary);
        throw new RuntimeException('The Backlog request could not be written to the queue.');
    }
    if (!rename($temporary, $ready . '/' . $name . '.json')) {
        @unlink($temporary);
        throw new RuntimeException('The Backlog request could not be moved into the queue.');
    }
    return true;
}

/** Queue state and, when finished, the importer's result for a request name. */
function BACKLOG_apiResult($queueRoot, $name)
{
    if (!preg_match('/\A[a-zA-Z0-9_-]{1,100}\z/', $name)) { throw new InvalidArgumentException('Invalid queue name.'); }
    foreach (array('done', 'failed') as $state) {
        $result = $queueRoot . '/' . $state . '/' . $name . '.json.result.json';
        if (is_file($result)) {
            return array('name' => $name, 'state' => $state, 'result' => json_decode((string) file_get_contents($result), true));
        }
    }
    foreach (array('processing', 'ready') as $state) {
        if (is_file($queueRoot . '/' . $state . '/' . $name . '.json')) { return array('name' => $name, 'state' => $state); }
    }
    return array('name' => $name, 'state' => 'unknown');
}
