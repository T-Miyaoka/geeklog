<?php
define('HAPPA_BACKLOG_LIBRARY_ONLY', true);
require __DIR__ . '/../../happa-article-inbox/run-backlog.php';
require __DIR__ . '/../plugins/backlog/categories.inc';

function expect($condition, $message) {
    if (!$condition) { throw new RuntimeException($message); }
}
function rejects($callback) {
    try { $callback(); } catch (RuntimeException $error) { return; }
    throw new RuntimeException('Expected rejection.');
}
function loadFunction($source, $wanted) {
    $tokens = token_get_all($source);
    for ($i = 0; $i < count($tokens); $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) { continue; }
        $j = $i + 1;
        while (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) { $j++; }
        if (!is_array($tokens[$j]) || $tokens[$j][1] !== $wanted) { continue; }
        $body = ''; $depth = 0; $opened = false;
        for ($j = $i; $j < count($tokens); $j++) {
            $token = $tokens[$j];
            $text = is_array($token) ? $token[1] : $token;
            $body .= $text;
            if ($token === '{' || (is_array($token) && in_array($token[0], array(T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES), true))) { $depth++; $opened = true; }
            if ($token === '}' && --$depth === 0 && $opened) { eval($body); return; }
        }
    }
    throw new RuntimeException('Function not found: ' . $wanted);
}

$allowed = false; $modifiable = true; $databaseMode = 'foreign'; $queries = array();
function SEC_hasRights($right) { global $allowed; return $allowed && $right === 'backlog.admin'; }
function BACKLOG_canModifyTicket($id) { global $modifiable; return $modifiable; }
function DB_escapeString($text) { return addslashes($text); }
function DB_query($sql) {
    global $queries, $databaseMode, $request;
    if (strpos($sql, 'SHOW COLUMNS') === 0) { return array('Field' => 'work_category'); }
    $queries[] = $sql;
    if ($databaseMode === 'foreign') { return array('text' => 'A different task'); }
    if ($databaseMode === 'own') { return array('text' => $request['marker']); }
    return array('cnt' => 0);
}
function DB_fetchArray($row) { return $row; }
function DB_numrows($row) { return $row ? 1 : 0; }
function BACKLOG_str($key) { return $key; }
function BACKLOG_newGuid() { return str_repeat('c', 32); }

$native = file_get_contents(__DIR__ . '/../plugins/backlog/functions.inc');
foreach (array('BACKLOG_getInternalArgs', 'BACKLOG_isValidTicketId', 'BACKLOG_execute') as $name) { loadFunction($native, $name); }
$_TABLES = array('backlog_ticket' => 'tickets', 'backlog_comment' => 'comments');
$_USER = array('uid' => 2); $_CONF = array(); $_IMAGE_TYPE = 'png'; $MESSAGE = array(29 => 'denied');
$base = array('schema_version' => 1, 'source_id' => '01a1613d-dc4e-71a1-a0a8-aed4265250cf', 'title' => 'Storage cleanup', 'text' => 'Verified evidence', 'status' => 1);
$request = backlogValidateRequest($base);
expect($request['ticket_id'] === backlogValidateRequest($base)['ticket_id'], 'Retries must address the same ticket.');
foreach (array(array('schema_version', 2), array('source_id', '../../outside'), array('status', 6), array('status', '1'), array('title', ''), array('text', str_repeat('x', 50001))) as $change) {
    $bad = $base; $bad[$change[0]] = $change[1]; rejects(function () use ($bad) { backlogValidateRequest($bad); });
}
$bad = $base; $bad['comment'] = 'An update without an event ID';
rejects(function () use ($bad) { backlogValidateRequest($bad); });
rejects(function () use ($request) { backlogApplyRequest($request); });
expect(count($queries) === 0, 'Missing permission must reject before database access.');
$allowed = true;
rejects(function () use ($request) { backlogApplyRequest($request); });
expect(count($queries) === 1 && strpos($queries[0], 'SELECT') === 0, 'A foreign ticket must not be updated.');
$queries = array(); $databaseMode = 'own'; $modifiable = false;
rejects(function () use ($request) { backlogApplyRequest($request); });
expect(count($queries) === 1, 'Ownership checks must run before writes.');
$databaseMode = 'native'; $modifiable = true; $queries = array(); $before = time();
BACKLOG_execute('addcomment', array('id' => $request['ticket_id'], 'comment' => 'A current work-history entry'));
preg_match('/,([0-9]{10}),/', $queries[0], $commentTime);
expect(count($queries) === 1 && isset($commentTime[1]) && (int) $commentTime[1] >= $before && (int) $commentTime[1] <= time(), 'Comments must store the current Unix timestamp.');
$queries = array();
BACKLOG_execute('save', array('id' => $request['ticket_id'], 'title' => 'Test', 'text' => 'Test', 'status' => 1, 'uid' => 2, 'limit_year' => 2026, 'limit_month' => 10, 'limit_day' => 22));
preg_match('/,([0-9]{10}),/', $queries[1], $ticketTime);
expect(count($queries) === 2 && isset($ticketTime[1]) && (int) $ticketTime[1] >= $before && (int) $ticketTime[1] <= time(), 'Ticket creation must store the current Unix timestamp.');
echo "Work-history checks passed: request bounds, stable identity, authorization, ownership, ticket/comment timestamps.\n";
