<?php
require __DIR__ . '/work-history.php';
function DB_getItem($table, $field, $where) { return ''; }
function BACKLOG_renderTicketText($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
$_CONF = array('layout_url' => '', 'site_url' => '');

// Normalization: spaces (including full-width) collapse; malformed text and non-strings are ignored.
expect(BACKLOG_searchQuery("  TST-017\u{3000}\t海路  ") === 'TST-017 海路', 'Whitespace must collapse.');
expect(BACKLOG_searchQuery("\xff\xfe") === '' && BACKLOG_searchQuery(array('x')) === '', 'Malformed input must be ignored.');
expect(BACKLOG_searchQuery(str_repeat('港', 250)) === str_repeat('港', 200), 'The text must be limited to 200 characters.');
expect(BACKLOG_searchTerms('a b a c') === array('a', 'b', 'c'), 'Terms must be distinct.');
expect(count(BACKLOG_searchTerms(implode(' ', range(1, 15)))) === 10, 'At most 10 terms are used.');

// The condition: every term must match one of title, body, ticket ID or a comment; wildcards are literal.
$condition = BACKLOG_searchCondition("TST-017 50%_x'", 'tickets', 'comments', 'addslashes');
expect(substr_count($condition, '(tickets.title LIKE') === 2 && strpos($condition, ")) AND (tickets.title LIKE") !== false
    && strpos($condition, "tickets.title LIKE '%TST-017%'") !== false, 'Terms must be combined with AND: ' . $condition);
expect(strpos($condition, "comments backlog_search_comment WHERE backlog_search_comment.ticketid = tickets.ticketid") !== false, 'Comments must be searched.');
expect(strpos($condition, "'%50\\\\%\\\\_x\\'%'") !== false, 'LIKE wildcards and quotes must be escaped: ' . $condition);
expect(BACKLOG_searchCondition('   ', 'tickets', 'comments', 'addslashes') === '', 'An empty search must not restrict the list.');

// Filters: the search comes from the address or the list form, and is never stored in the cookie.
$saved = BACKLOG_decodeFilterCookie('');
expect(BACKLOG_resolveFilters(array('q' => ' 港 '), array(), $saved, 'GET')['q'] === '港', 'Address searches must be read.');
expect(BACKLOG_resolveFilters(array(), array('opt1' => '1', 'q' => '海路'), $saved, 'POST')['q'] === '海路', 'Form searches must be read.');
expect(BACKLOG_resolveFilters(array(), array('mode' => '保存', 'q' => '海路'), $saved, 'POST')['q'] === '', 'Saving a ticket must not start a search.');
expect(BACKLOG_resolveFilters(array(), array(), $saved, 'GET')['q'] === '', 'A plain revisit must not restore a search.');

// The list query receives the condition after the status and category conditions.
$GLOBALS['BACKLOG_CATEGORY_FILTER'] = 'improvement';
$queries = array(); $databaseMode = 'native';
BACKLOG_execute('reload', array('opt1' => '1', 'opt2' => '', 'q' => 'TST-016'));
$sql = end($queries);
expect(strpos($sql, "where status != 4 and work_category = 'improvement' and (tickets.title LIKE '%TST-016%'") !== false, 'The list must apply the search: ' . $sql);
expect(($GLOBALS['BACKLOG_LIST_COUNT'] ?? null) === 1, 'The list must record the number of rows.');
$queries = array();
BACKLOG_execute('reload', array('opt1' => '1', 'opt2' => 'checked'));
expect(strpos(end($queries), 'LIKE') === false, 'Without a search the list must be unchanged.');

// Summary and navigation escape the text and keep it across categories.
$summary = BACKLOG_searchSummary('<b>"港"</b>', 3, 'all');
expect(strpos($summary, '&lt;b&gt;&quot;港&quot;&lt;/b&gt;') !== false && strpos($summary, '3件') !== false && strpos($summary, 'category=all') !== false, 'The summary must escape the search text.');
expect(BACKLOG_searchSummary('', 3, 'all') === '', 'No summary without a search.');
expect(strpos(BACKLOG_categoryNavigation('other', '港 海路'), 'category=improvement&amp;q=' . rawurlencode('港 海路')) !== false, 'Category links must keep the search.');
expect(strpos(BACKLOG_categoryNavigation('other'), '&amp;q=') === false, 'Category links must not add an empty search.');
echo "Search checks passed: normalization, AND terms, comments, escaping, filters, list query, summary, navigation.\n";
