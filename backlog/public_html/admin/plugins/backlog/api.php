<?php
/**
 * Backlog API (JSON, token-authenticated).
 *
 *   GET  api.php?action=list[&category=improvement|other|all][&status=0,1][&q=..][&include_text=1][&limit=500][&offset=0]
 *   GET  api.php?action=get&id=<ticket id>              ticket with description and comments
 *   POST api.php?action=submit   body: private-inbox request (kind "ticket" or "status")
 *   GET  api.php?action=result&name=<queued name>        importer result for a submitted request
 *
 * Authorization: Bearer <token>. The server keeps only the token's SHA-256 in
 * <site>/happa-article-inbox/api/token.sha256 (outside public_html). Writes are never applied here:
 * they are validated and placed in <site>/happa-article-inbox/backlog/ready, and the scheduled
 * importer applies them with its own Geeklog login and backlog.admin check.
 */
$siteRoot = dirname(__DIR__, 4);
require_once $siteRoot . '/plugins/backlog/api.inc';

function BACKLOG_apiRespond($code, $payload)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if ($authorization === '' && function_exists('getallheaders')) {
    foreach (getallheaders() as $name => $value) {
        if (strcasecmp($name, 'Authorization') === 0) { $authorization = $value; }
    }
}
if (empty($_SERVER['HTTPS']) || strtolower((string) $_SERVER['HTTPS']) === 'off') {
    BACKLOG_apiRespond(403, array('error' => 'HTTPS is required.'));
}
if (!BACKLOG_apiAuthorized($authorization, $siteRoot . '/happa-article-inbox/api/token.sha256')) {
    header('WWW-Authenticate: Bearer');
    BACKLOG_apiRespond(401, array('error' => 'A valid API token is required.'));
}

$action = (string) ($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$queueRoot = $siteRoot . '/happa-article-inbox/backlog';

try {
    if ($action === 'submit') {
        if ($method !== 'POST') { BACKLOG_apiRespond(405, array('error' => 'submit requires POST.')); }
        $body = (string) file_get_contents('php://input', false, null, 0, BACKLOG_API_MAX_BODY + 1);
        if (strlen($body) > BACKLOG_API_MAX_BODY) { BACKLOG_apiRespond(413, array('error' => 'The request exceeds 64 KiB.')); }
        $request = json_decode($body, true);
        define('HAPPA_BACKLOG_LIBRARY_ONLY', true);
        require_once $siteRoot . '/happa-article-inbox/run-backlog.php';
        try {
            $validated = backlogValidateRequest($request);
        } catch (RuntimeException $error) {
            BACKLOG_apiRespond(422, array('error' => $error->getMessage()));
        }
        $name = BACKLOG_apiQueueName($body, time());
        $queued = BACKLOG_apiEnqueue($queueRoot, $name, $body);
        BACKLOG_apiRespond($queued ? 202 : 200, array('queued' => $name, 'new' => $queued, 'ticket_id' => $validated['ticket_id'],
            'ticket_url' => 'https://www.happa.bz/admin/plugins/backlog/index.php?mode=comment&id=' . $validated['ticket_id'],
            'note' => 'The scheduled importer applies queued requests every five minutes; poll action=result.'));
    }
    if ($action === 'result') {
        BACKLOG_apiRespond(200, BACKLOG_apiResult($queueRoot, (string) ($_GET['name'] ?? '')));
    }
    if ($action !== 'list' && $action !== 'get') {
        BACKLOG_apiRespond(400, array('error' => 'action must be list, get, submit or result.'));
    }
    if ($method !== 'GET') { BACKLOG_apiRespond(405, array('error' => $action . ' requires GET.')); }

    require_once $siteRoot . '/public_html/lib-common.php';
    $escape = 'DB_escapeString';
    $tickets = $_TABLES['backlog_ticket'];
    $comments = $_TABLES['backlog_comment'];
    $users = $_TABLES['users'];
    $select = "SELECT t.ticketid, t.status, t.work_category, t.title, t.text, t.limityear, t.limitmonth, t.limitday, t.regdate,"
        . " h.username AS holder_name, r.username AS register_name,"
        . " (SELECT COUNT(*) FROM {$comments} c WHERE c.ticketid = t.ticketid) AS comment_count,"
        . " (SELECT MAX(c.regdate) FROM {$comments} c WHERE c.ticketid = t.ticketid) AS last_comment"
        . " FROM {$tickets} t LEFT JOIN {$users} h ON h.uid = t.holder LEFT JOIN {$users} r ON r.uid = t.register ";

    if ($action === 'get') {
        $id = (string) ($_GET['id'] ?? '');
        if (!preg_match('/\A[0-9A-Za-z-]{1,32}\z/', $id)) { BACKLOG_apiRespond(400, array('error' => 'id is required.')); }
        $row = DB_fetchArray(DB_query($select . "WHERE t.ticketid = '" . $escape($id) . "'"));
        if (!$row) { BACKLOG_apiRespond(404, array('error' => 'The ticket does not exist.')); }
        $ticket = BACKLOG_apiTicket($row, true);
        $ticket['comment_list'] = array();
        $result = DB_query("SELECT c.commentid, c.text, c.regdate, u.username FROM {$comments} c LEFT JOIN {$users} u ON u.uid = c.register"
            . " WHERE c.ticketid = '" . $escape($id) . "' ORDER BY c.regdate, c.commentid");
        while ($comment = DB_fetchArray($result)) {
            $ticket['comment_list'][] = array('id' => $comment['commentid'], 'text' => $comment['text'], 'register' => $comment['username'],
                'registered_at' => $comment['regdate'] ? date(DATE_ATOM, (int) $comment['regdate']) : null);
        }
        BACKLOG_apiRespond(200, $ticket);
    }

    $filters = BACKLOG_apiListFilters($_GET);
    $where = BACKLOG_apiListWhere($filters, $escape);
    $total = (int) DB_fetchArray(DB_query("SELECT COUNT(*) AS cnt FROM {$tickets} t {$where}"))['cnt'];
    $result = DB_query($select . $where . " ORDER BY t.regdate DESC, t.ticketid LIMIT {$filters['offset']}, {$filters['limit']}");
    $list = array();
    while ($row = DB_fetchArray($result)) { $list[] = BACKLOG_apiTicket($row, $filters['include_text']); }
    BACKLOG_apiRespond(200, array('total' => $total, 'offset' => $filters['offset'], 'count' => count($list), 'tickets' => $list));
} catch (InvalidArgumentException $error) {
    BACKLOG_apiRespond(400, array('error' => $error->getMessage()));
} catch (RuntimeException $error) {
    // Messages raised by api.inc describe only the queue state; anything else stays on the server.
    $own = basename($error->getFile()) === 'api.inc';
    BACKLOG_apiRespond(500, array('error' => $own ? $error->getMessage() : 'Backlog API failed.', 'error_type' => get_class($error)));
} catch (Throwable $error) {
    // Configuration details must not leave the server.
    BACKLOG_apiRespond(500, array('error' => 'Backlog API failed.', 'error_type' => get_class($error)));
}
