<?php
/** Persist only the three user-visible list filters, scoped to the signed-in user. */
function BACKLOG_filterCookieName($uid)
{
    return 'backlog_filters_' . max(0, (int) $uid);
}

function BACKLOG_decodeFilterCookie($value)
{
    $defaults = array('category' => 'other', 'opt1' => '2', 'opt2' => '');
    if (!is_string($value) || strlen($value) > 512) { return $defaults; }
    $saved = json_decode($value, true);
    if (!is_array($saved) || ($saved['version'] ?? null) !== 1) { return $defaults; }
    return array(
        'category' => BACKLOG_categoryFilter($saved['category'] ?? ''),
        'opt1' => in_array($saved['opt1'] ?? '', array('1', '2'), true) ? $saved['opt1'] : '2',
        'opt2' => ($saved['opt2'] ?? '') === 'checked' ? 'checked' : '',
    );
}

function BACKLOG_resolveFilters($get, $post, $saved, $method)
{
    $filterPost = $method === 'POST' && array_key_exists('opt1', $post);
    $category = $get['category'] ?? ($filterPost ? ($post['category'] ?? $saved['category']) : ($post['view_category'] ?? $saved['category']));
    $opt1 = $get['opt1'] ?? ($filterPost ? $post['opt1'] : $saved['opt1']);
    if (array_key_exists('opt2', $get)) {
        $opt2 = $get['opt2'];
    } elseif ($filterPost) {
        // An unchecked checkbox is omitted by the browser; omission must clear a saved check.
        $opt2 = $post['opt2'] ?? '';
    } else {
        $opt2 = $saved['opt2'];
    }
    // The search text is not saved: it comes only from the address (q=) or the list form.
    $search = $get['q'] ?? ($filterPost ? ($post['q'] ?? '') : '');
    return array(
        'category' => BACKLOG_categoryFilter($category),
        'opt1' => in_array($opt1, array('1', '2'), true) ? $opt1 : '2',
        'opt2' => $opt2 === 'checked' ? 'checked' : '',
        'q' => BACKLOG_searchQuery($search),
        'persist' => $filterPost || array_key_exists('category', $get) || array_key_exists('opt1', $get) || array_key_exists('opt2', $get),
    );
}

function BACKLOG_persistFilters($uid, $filters)
{
    setcookie(BACKLOG_filterCookieName($uid), json_encode(array(
        'version' => 1, 'category' => $filters['category'], 'opt1' => $filters['opt1'], 'opt2' => $filters['opt2'],
    )), array(
        'expires' => time() + 31536000,
        'path' => '/admin/plugins/backlog/',
        'secure' => true, 'httponly' => true, 'samesite' => 'Lax',
    ));
}

/** Normalizes the list search text: whitespace (including full-width spaces) collapses, at most 200 characters. */
function BACKLOG_searchQuery($value)
{
    // preg with /u rejects malformed UTF-8; mbstring is not required.
    if (!is_string($value) || $value === '' || preg_match('//u', $value) !== 1) { return ''; }
    $value = trim((string) preg_replace('/[\s\x{3000}]+/u', ' ', $value));
    return preg_match('/\A.{0,200}/us', $value, $match) === 1 ? rtrim($match[0]) : '';
}

/** Search terms separated by spaces; every term must match. At most 10 distinct terms are used. */
function BACKLOG_searchTerms($query)
{
    $query = BACKLOG_searchQuery($query);
    return $query === '' ? array() : array_slice(array_values(array_unique(explode(' ', $query))), 0, 10);
}

/**
 * SQL condition for the list search: each term matches the title, the body, the ticket ID or a comment.
 * LIKE wildcards in the terms are literal; $escape quotes the pattern for a SQL string.
 */
function BACKLOG_searchCondition($query, $ticketTable, $commentTable, $escape)
{
    $parts = array();
    foreach (BACKLOG_searchTerms($query) as $term) {
        $like = $escape('%' . addcslashes($term, '\\%_') . '%');
        $parts[] = "({$ticketTable}.title LIKE '{$like}' OR {$ticketTable}.text LIKE '{$like}' OR {$ticketTable}.ticketid LIKE '{$like}'"
            . " OR EXISTS (SELECT 1 FROM {$commentTable} backlog_search_comment"
            . " WHERE backlog_search_comment.ticketid = {$ticketTable}.ticketid AND backlog_search_comment.text LIKE '{$like}'))";
    }
    return implode(' AND ', $parts);
}

/** Line shown above the list while a search is active. */
function BACKLOG_searchSummary($query, $count, $category)
{
    $query = BACKLOG_searchQuery($query);
    if ($query === '') { return ''; }
    return '<p class="backlog-search-summary" style="margin:.5em 0;font-weight:400;">「'
        . htmlspecialchars($query, ENT_QUOTES, 'UTF-8') . '」の検索結果：' . max(0, (int) $count) . '件　'
        . '<a href="./index.php?category=' . BACKLOG_categoryFilter($category) . '">検索を解除</a></p>';
}
