<?php
define('HAPPA_BACKLOG_LIBRARY_ONLY', true);
require __DIR__ . '/../../happa-article-inbox/run-backlog.php';
require __DIR__ . '/../plugins/backlog/api.inc';

function expect($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
function rejects($callback, $type = 'RuntimeException') {
    try { $callback(); } catch (Throwable $error) { if ($error instanceof $type) { return; } throw $error; }
    throw new RuntimeException('Expected rejection.');
}

// Token check: only the stored SHA-256 is compared, in constant time.
$dir = sys_get_temp_dir() . '/backlog-api-test-' . getmypid();
@mkdir($dir); @mkdir($dir . '/ready'); @mkdir($dir . '/done'); @mkdir($dir . '/failed'); @mkdir($dir . '/processing');
$token = str_repeat('A', 20) . 'b_c-' . str_repeat('9', 20);
file_put_contents($dir . '/token.sha256', hash('sha256', $token) . "\n");
expect(BACKLOG_apiAuthorized('Bearer ' . $token, $dir . '/token.sha256'), 'The stored token must authorize.');
foreach (array('', 'Bearer', 'Basic ' . $token, 'Bearer ' . $token . 'x', 'Bearer short', 'Bearer ' . $token . "\nX: y") as $header) {
    expect(!BACKLOG_apiAuthorized($header, $dir . '/token.sha256'), 'Rejected header: ' . json_encode($header));
}
expect(!BACKLOG_apiAuthorized('Bearer ' . $token, $dir . '/missing.sha256'), 'A missing hash file must deny.');
file_put_contents($dir . '/bad.sha256', 'not-a-hash');
expect(!BACKLOG_apiAuthorized('Bearer ' . $token, $dir . '/bad.sha256'), 'A malformed hash file must deny.');

// List filters are whitelisted; the search text is escaped.
$filters = BACKLOG_apiListFilters(array('category' => 'improvement', 'status' => '0,5,0', 'q' => "a'%_", 'limit' => '10'));
expect($filters['statuses'] === array(0, 5) && $filters['limit'] === 10 && !$filters['include_text'], 'Filters must normalize.');
$where = BACKLOG_apiListWhere($filters, 'addslashes');
expect(strpos($where, "t.work_category = 'improvement'") !== false && strpos($where, 't.status IN (0,5)') !== false, 'Filters must reach the condition.');
expect(strpos($where, "a\\'\\\\%\\\\_") !== false, 'LIKE wildcards and quotes must be escaped: ' . $where);
foreach (array(array('category' => 'x'), array('status' => '6'), array('status' => '1;DROP'), array('limit' => '0'), array('limit' => '2001'), array('offset' => '-1')) as $bad) {
    rejects(function () use ($bad) { BACKLOG_apiListFilters($bad); }, 'InvalidArgumentException');
}
expect(BACKLOG_apiListWhere(BACKLOG_apiListFilters(array()), 'addslashes') === '', 'No filter means all tickets.');

// Ticket rows: overview, source ID and labels.
$row = array('ticketid' => 'abc', 'status' => '4', 'work_category' => 'improvement', 'title' => 'T', 'limityear' => 2026, 'limitmonth' => 10, 'limitday' => 24,
    'regdate' => 1792800000, 'text' => "Codex作業ID: 01a1613d-dc4e-71a1-a0a8-aed4265250cf\n【概要】\n短い概要\n\n【詳細】\n長い詳細", 'comment_count' => '2', 'last_comment' => null);
$ticket = BACKLOG_apiTicket($row, false);
expect($ticket['overview'] === '短い概要' && $ticket['status_label'] === '完了' && $ticket['source_id'] === '01a1613d-dc4e-71a1-a0a8-aed4265250cf', 'Ticket summary fields.');
expect(!isset($ticket['text']) && isset(BACKLOG_apiTicket($row, true)['text']), 'Description only on request.');
expect(BACKLOG_apiTicket(array_merge($row, array('text' => "手作業の記録\n2行目")), false)['source_id'] === null, 'Native tickets have no source ID.');
expect(BACKLOG_apiOverview("\n  最初の行\n次") === '最初の行', 'Legacy tickets use the first line.');

// Queueing writes outside ready first, never overwrites, and reports results.
$body = '{"schema_version":1}';
$name = BACKLOG_apiQueueName($body, 1792800000);
expect(preg_match('/\Aapi-20261024[0-9]{6}-[0-9a-f]{16}\z/', $name) === 1, 'Queue names follow the importer pattern: ' . $name);
expect(BACKLOG_apiEnqueue($dir, $name, $body) === true && file_get_contents($dir . '/ready/' . $name . '.json') === $body, 'The request must land in ready.');
expect(!glob($dir . '/*.uploading'), 'No partial file may remain.');
expect(BACKLOG_apiEnqueue($dir, $name, $body) === false, 'A duplicate must not be queued twice.');
expect(BACKLOG_apiResult($dir, $name)['state'] === 'ready', 'Queued state.');
rename($dir . '/ready/' . $name . '.json', $dir . '/done/' . $name . '.json');
file_put_contents($dir . '/done/' . $name . '.json.result.json', '{"status":"completed"}');
$result = BACKLOG_apiResult($dir, $name);
expect($result['state'] === 'done' && $result['result']['status'] === 'completed', 'Finished result.');
rejects(function () use ($dir) { BACKLOG_apiResult($dir, '../x'); }, 'InvalidArgumentException');
rejects(function () use ($dir) { BACKLOG_apiEnqueue($dir, 'a/b', '{}'); }, 'InvalidArgumentException');

// Status requests: only ticket_id/status/event_id/comment, for an existing ticket.
$status = array('schema_version' => 1, 'kind' => 'status', 'ticket_id' => '3604c890-963e-f740-e14f-a08936af', 'status' => 4,
    'event_id' => 'status-refresh-20261024', 'comment' => '完了を確認した。');
expect(backlogValidateRequest($status) === $status, 'A valid status request passes unchanged.');
foreach (array(array('title', 'x'), array('text', 'x'), array('category', 'other'), array('status', 6), array('status', '4'), array('ticket_id', "'; DROP"),
    array('comment', ''), array('event_id', 'bad id'), array('kind', 'delete')) as $change) {
    $bad = $status; $bad[$change[0]] = $change[1];
    rejects(function () use ($bad) { backlogValidateRequest($bad); });
}
$bad = $status; unset($bad['comment']); rejects(function () use ($bad) { backlogValidateRequest($bad); });

$allowed = true; $modifiable = true; $rows = array(); $queries = array(); $comments = array();
function SEC_hasRights($right) { global $allowed; return $allowed && $right === 'backlog.admin'; }
function BACKLOG_canModifyTicket($id) { global $modifiable; return $modifiable; }
function BACKLOG_execute($type, $args) { global $comments; $comments[] = $args; }
function DB_escapeString($text) { return addslashes($text); }
function DB_query($sql) {
    global $queries, $rows, $comments;
    $queries[] = $sql;
    if (preg_match("/^UPDATE tickets SET status = ([0-9]) WHERE ticketid = '([^']*)'$/", $sql, $m)) { $rows[$m[2]]['status'] = $m[1]; return null; }
    if (strpos($sql, 'FROM tickets') !== false && preg_match("/ticketid = '([^']*)'/", $sql, $m)) { return $rows[$m[1]] ?? false; }
    if (strpos($sql, 'FROM comments') !== false) { return $comments ? array('commentid' => 'C1', 'regdate' => time()) : false; }
    throw new RuntimeException('Unexpected SQL: ' . $sql);
}
function DB_fetchArray($row) { return $row; }
$_TABLES = array('backlog_ticket' => 'tickets', 'backlog_comment' => 'comments'); $_USER = array('uid' => 2);

rejects(function () use ($status) { backlogApplyRequest($status); });
expect(!array_filter($queries, function ($q) { return strpos($q, 'UPDATE') === 0; }), 'A missing ticket must not be written.');
$rows[$status['ticket_id']] = array('ticketid' => $status['ticket_id'], 'status' => '1', 'title' => '手作業の記録', 'text' => '本文', 'work_category' => 'other');
$modifiable = false; $queries = array();
rejects(function () use ($status) { backlogApplyRequest($status); });
expect(!array_filter($queries, function ($q) { return strpos($q, 'UPDATE') === 0; }), 'Ownership checks run before writes.');
$modifiable = true; $queries = array();
$applied = backlogApplyRequest($status);
expect($applied['previous_status'] === 1 && $applied['ticket_status'] === 4 && (int) $rows[$status['ticket_id']]['status'] === 4, 'Status must change.');
expect($rows[$status['ticket_id']]['title'] === '手作業の記録' && $rows[$status['ticket_id']]['text'] === '本文', 'Other fields stay as they were.');
$updates = array_values(array_filter($queries, function ($q) { return strpos($q, 'UPDATE') === 0; }));
expect(count($updates) === 1 && strpos($updates[0], 'title') === false && strpos($updates[0], 'text') === false, 'Only the status column is updated.');
expect(count($comments) === 1 && strpos($comments[0]['comment'], "[status-refresh-20261024]\n完了を確認した。") === 0, 'The reason is kept as a comment.');
$allowed = false;
rejects(function () use ($status) { backlogApplyRequest($status); });

array_map('unlink', glob($dir . '/*/*') ?: array()); array_map('unlink', glob($dir . '/*.*') ?: array());
foreach (array('ready', 'done', 'failed', 'processing') as $sub) { @rmdir($dir . '/' . $sub); } @rmdir($dir);
echo "Backlog API checks passed: token, filters, rows, queue, results, status-only requests.\n";
