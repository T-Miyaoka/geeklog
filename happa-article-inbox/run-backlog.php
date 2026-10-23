<?php
/** Private, authenticated Backlog work-history inbox. No HTTP entry point. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

function backlogValidateRequest($request)
{
    if (!is_array($request) || ($request['schema_version'] ?? null) !== 1) {
        throw new RuntimeException('Unsupported Backlog request schema.');
    }
    if (!is_string($request['source_id'] ?? null) || !preg_match('/^[a-f0-9-]{36}$/D', $request['source_id'])) {
        throw new RuntimeException('A source chat UUID is required.');
    }
    foreach (array('title' => 255, 'text' => 50000) as $name => $limit) {
        if (!is_string($request[$name] ?? null) || trim($request[$name]) === '' || strlen($request[$name]) > $limit) {
            throw new RuntimeException('Missing or oversized Backlog ' . $name . '.');
        }
    }
    if (!is_int($request['status'] ?? null) || $request['status'] < 0 || $request['status'] > 5) {
        throw new RuntimeException('Backlog status must be an integer from 0 to 5.');
    }
    if (isset($request['category']) && !in_array($request['category'], array('improvement', 'other'), true)) {
        throw new RuntimeException('Backlog category must be improvement or other.');
    }
    if (isset($request['comment'])) {
        if (!is_string($request['comment']) || strlen($request['comment']) > 16000 || !is_string($request['event_id'] ?? null) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $request['event_id'])) {
            throw new RuntimeException('A bounded comment and stable event ID are required.');
        }
    }
    $hash = md5('codex-task:' . $request['source_id']);
    // Match the existing varchar(32) GUID format accepted by Backlog.
    $request['ticket_id'] = substr($hash, 0, 8) . '-' . substr($hash, 8, 4) . '-' . substr($hash, 12, 4) . '-' . substr($hash, 16, 4) . '-' . substr($hash, 20, 8);
    $request['marker'] = 'Codex作業ID: ' . $request['source_id'];
    return $request;
}

function backlogApplyRequest($request)
{
    global $_TABLES, $_USER;
    if (!SEC_hasRights('backlog.admin') || !function_exists('BACKLOG_execute')) {
        throw new RuntimeException('The authenticated account lacks Backlog administration access.');
    }
    $id = $request['ticket_id'];
    $query = DB_query("SELECT * FROM {$_TABLES['backlog_ticket']} WHERE ticketid = '{$id}'");
    $existing = DB_fetchArray($query);
    if ($existing && strpos($existing['text'], $request['marker']) === false) {
        throw new RuntimeException('Refusing to overwrite a ticket owned by another source.');
    }
    if ($existing && !BACKLOG_canModifyTicket($id)) {
        throw new RuntimeException('The authenticated account cannot modify this ticket.');
    }
    $category = $request['category'] ?? ($existing['work_category'] ?? 'other');
    $text = $request['marker'] . "\n" . $request['text'];
    $now = time();
    BACKLOG_execute('save', array(
        'id' => $id, 'status' => $request['status'], 'title' => $request['title'], 'text' => $text, 'category' => $category,
        'limit_year' => $existing ? (int) $existing['limityear'] : (int) date('Y'),
        'limit_month' => $existing ? (int) $existing['limitmonth'] : (int) date('m'),
        'limit_day' => $existing ? (int) $existing['limitday'] : (int) date('d'),
        'uid' => $existing ? (int) $existing['holder'] : (int) $_USER['uid']
    ));
    $saved = DB_fetchArray(DB_query("SELECT * FROM {$_TABLES['backlog_ticket']} WHERE ticketid = '{$id}'"));
    if (!$saved || $saved['title'] !== $request['title'] || $saved['text'] !== $text || (int) $saved['status'] !== $request['status'] || $saved['work_category'] !== $category) {
        throw new RuntimeException('Ticket read-back verification failed.');
    }
    if (!$existing && abs((int) $saved['regdate'] - $now) > 60) {
        throw new RuntimeException('Ticket timestamp verification failed.');
    }
    $commentId = null;
    if (!empty($request['comment'])) {
        $comment = '[' . $request['event_id'] . "]\n" . $request['comment'];
        $escaped = DB_escapeString($comment);
        $actor = (int) $_USER['uid'];
        $row = DB_fetchArray(DB_query("SELECT commentid, regdate FROM {$_TABLES['backlog_comment']} WHERE ticketid = '{$id}' AND text = '{$escaped}' AND register = {$actor} LIMIT 1"));
        if (!$row) {
            BACKLOG_execute('addcomment', array('id' => $id, 'comment' => $comment));
            $row = DB_fetchArray(DB_query("SELECT commentid, regdate FROM {$_TABLES['backlog_comment']} WHERE ticketid = '{$id}' AND text = '{$escaped}' AND register = {$actor} ORDER BY regdate DESC LIMIT 1"));
            if (!$row || abs((int) $row['regdate'] - $now) > 60) {
                throw new RuntimeException('Comment read-back or timestamp verification failed.');
            }
        }
        $commentId = $row['commentid'];
    }
    return array('status' => 'completed', 'ticket_id' => $id, 'ticket_category' => $category,
        'ticket_url' => 'https://www.happa.bz/admin/plugins/backlog/index.php?mode=comment&id=' . $id,
        'ticket_text_sha256' => hash('sha256', $text), 'comment_id' => $commentId,
        'verified_at' => date(DATE_ATOM), 'authenticated' => true, 'backlog_admin' => true);
}

function backlogMain()
{
    $root = __DIR__ . '/backlog';
    foreach (array('ready', 'processing', 'done', 'failed') as $directory) {
        if (!is_dir($root . '/' . $directory)) {
            mkdir($root . '/' . $directory, 0750, true);
        }
    }
    $lock = fopen($root . '/runner.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        return;
    }
    $files = array_slice(glob($root . '/ready/*.json') ?: array(), 0, 100);
    if (!$files) {
        return;
    }
    define('HAPPA_ARTICLE_LIBRARY_ONLY', true);
    require_once __DIR__ . '/run-import.php';
    $initialized = false;
    foreach ($files as $file) {
        $name = basename($file);
        if (!preg_match('/^[a-zA-Z0-9_-]{1,100}\.json$/D', $name) || is_link($file) || filesize($file) > 65536) {
            continue;
        }
        $processing = $root . '/processing/' . $name;
        if (file_exists($processing) || !rename($file, $processing)) {
            continue;
        }
        try {
            $request = backlogValidateRequest(json_decode(file_get_contents($processing), true));
            if (!$initialized) {
                $credentials = readCredentials();
                bootstrapGeeklogSafely();
                authenticateGeeklog($credentials);
                unset($credentials);
                $initialized = true;
            }
            $result = backlogApplyRequest($request);
            $terminal = 'done';
        } catch (Throwable $error) {
            // Configuration/authentication details must not enter a ticket or result.
            $safe = $error instanceof RuntimeException && $error->getFile() === __FILE__;
            $result = array('status' => 'failed', 'error' => $safe ? $error->getMessage() : 'Backlog processing failed; inspect the private server log.',
                'error_type' => get_class($error), 'error_file' => basename($error->getFile()), 'error_line' => $error->getLine());
            $terminal = 'failed';
        }
        file_put_contents($root . '/' . $terminal . '/' . $name . '.result.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        rename($processing, $root . '/' . $terminal . '/' . $name);
    }
    flock($lock, LOCK_UN);
    fclose($lock);
}

if (!defined('HAPPA_BACKLOG_LIBRARY_ONLY')) {
    backlogMain();
}
