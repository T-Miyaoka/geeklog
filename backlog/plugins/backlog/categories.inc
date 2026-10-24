<?php
require_once __DIR__ . '/filters.inc';
/** Work-history classification shared by the admin UI and private importer. */
function BACKLOG_categoryFilter($value)
{
    return in_array($value, array('improvement', 'other', 'all'), true) ? $value : 'other';
}

function BACKLOG_categoryLabel($value)
{
    return $value === 'improvement' ? '改善項目' : 'その他';
}

function BACKLOG_categoryOptions($value)
{
    $html = '';
    foreach (array('improvement', 'other') as $category) {
        $html .= '<option value="' . $category . '"' . ($value === $category ? ' selected' : '') . '>'
            . BACKLOG_categoryLabel($category) . '</option>';
    }
    return $html;
}

function BACKLOG_categoryCondition($alias = '')
{
    global $BACKLOG_CATEGORY_FILTER;
    $category = BACKLOG_categoryFilter($BACKLOG_CATEGORY_FILTER ?? 'all');
    if ($category === 'all') { return ''; }
    // Aliases are supplied by the plugin, never by a request.
    $prefix = $alias === 'bl' ? 'bl.' : '';
    return $prefix . "work_category = '" . $category . "'";
}

function BACKLOG_categorySuffix()
{
    global $BACKLOG_CATEGORY_FILTER;
    return '&amp;category=' . BACKLOG_categoryFilter($BACKLOG_CATEGORY_FILTER ?? 'other');
}

function BACKLOG_categoryNavigation($selected, $query = '')
{
    // Switching the category keeps the current search.
    $query = BACKLOG_searchQuery($query);
    $search = $query === '' ? '' : '&amp;q=' . rawurlencode($query);
    $html = '<style>.backlog-ticket-row > td{vertical-align:top}.backlog-ticket-row .backlog-text{min-width:20em;max-width:36em}.backlog-ticket-row .backlog-date,.backlog-ticket-row .backlog-status{white-space:nowrap}.backlog-ticket-row .backlog-title{min-width:12em}.backlog-ticket-row .backlog-date span,.admin-list-table th,.backlog-editor dt,.backlog-editor label{font-weight:400}.admin-list-table th{white-space:nowrap}</style>'
        . '<nav aria-label="作業履歴の種別" style="display:flex;flex-wrap:wrap;gap:.5em;margin:1em 0;font-weight:400;">';
    foreach (array('improvement' => '改善項目', 'other' => 'その他', 'all' => 'すべて') as $category => $label) {
        $active = $selected === $category;
        $html .= '<a href="./index.php?category=' . $category . $search . '"'
            . ($active ? ' aria-current="page"' : '')
            . ' style="padding:.45em 1em;border:1px solid #94a3b8;border-radius:4px;font-weight:400;'
            . ($active ? 'background:#e0f2fe;color:#075985;' : 'background:#fff;') . '">' . $label . '</a>';
    }
    return $html . '</nav>';
}

function BACKLOG_ensureCategorySchema()
{
    global $_TABLES;
    static $ready = false;
    if ($ready) { return; }
    if (!SEC_hasRights('backlog.admin')) {
        throw new RuntimeException('Backlog administration access is required for the category schema.');
    }
    $table = $_TABLES['backlog_ticket'];
    $column = DB_query("SHOW COLUMNS FROM {$table} LIKE 'work_category'");
    if (DB_numrows($column) === 0) {
        // Existing tickets belong to Other until explicitly classified.
        DB_query("ALTER TABLE {$table} ADD COLUMN work_category varchar(16) NOT NULL DEFAULT 'other'", 1);
        $column = DB_query("SHOW COLUMNS FROM {$table} LIKE 'work_category'");
        if (DB_numrows($column) === 0) {
            throw new RuntimeException('Backlog category schema migration could not be verified.');
        }
    }
    $ready = true;
}
