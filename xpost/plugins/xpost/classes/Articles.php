<?php
namespace Happa\XPost;

class Articles
{
    private static function wherePublic()
    {
        global $_TABLES;
        // Public story and at least one topic; every assigned topic must be public.
        return "s.draft_flag = 0 AND s.date <= NOW() AND s.perm_anon >= 2"
            . " AND (s.statuscode = 0 OR s.expire IS NULL OR s.expire > NOW())"
            . " AND EXISTS (SELECT 1 FROM {$_TABLES['topic_assignments']} ta WHERE ta.type='article' AND ta.id=s.sid)"
            . " AND NOT EXISTS (SELECT 1 FROM {$_TABLES['topic_assignments']} ta LEFT JOIN {$_TABLES['topics']} t ON t.tid=ta.tid"
            . " WHERE ta.type='article' AND ta.id=s.sid AND (t.tid IS NULL OR t.perm_anon < 2))";
    }
    public static function recent($search = '')
    {
        global $_TABLES;
        $where = self::wherePublic();
        if ($search !== '') {
            // Escape LIKE wildcards, then SQL-escape the complete literal.
            $like = DB_escapeString('%' . str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $search) . '%');
            $where .= " AND (s.sid LIKE '$like' OR s.title LIKE '$like')";
        }
        $result = DB_query("SELECT s.sid,s.title,s.date FROM {$_TABLES['stories']} s WHERE $where ORDER BY s.date DESC, s.sid LIMIT 60");
        $rows = array();
        while ($row = DB_fetchArray($result)) { $rows[] = $row; }
        return $rows;
    }
    public static function selected(array $sids)
    {
        global $_TABLES, $_CONF;
        $sids = array_values(array_unique(array_filter($sids, function ($sid) { return $sid !== ''; })));
        if (count($sids) < 1 || count($sids) > 2) { throw new \RuntimeException('記事を1件または2件選択してください。'); }
        $articles = array();
        foreach ($sids as $sid) {
            if (!is_string($sid) || strlen($sid) > 128 || strpos($sid, "\0") !== false) {
                throw new \RuntimeException('記事IDを確認してください。');
            }
            $id = DB_escapeString($sid);
            $result = DB_query("SELECT s.sid,s.title FROM {$_TABLES['stories']} s WHERE s.sid='$id' AND " . self::wherePublic());
            $row = DB_fetchArray($result);
            if (!$row) { throw new \RuntimeException('公開中の記事を選択してください。非公開・予約・期限切れの記事は投稿できません。'); }
            $row['url'] = rtrim($_CONF['site_url'], '/') . '/article.php?story=' . rawurlencode($row['sid']);
            $articles[] = $row;
        }
        return $articles;
    }
}
