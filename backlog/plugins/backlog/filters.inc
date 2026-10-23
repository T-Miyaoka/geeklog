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
    return array(
        'category' => BACKLOG_categoryFilter($category),
        'opt1' => in_array($opt1, array('1', '2'), true) ? $opt1 : '2',
        'opt2' => $opt2 === 'checked' ? 'checked' : '',
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
