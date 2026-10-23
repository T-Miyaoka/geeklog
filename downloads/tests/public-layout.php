<?php
// Runs the public listing's presentation logic with stubbed Geeklog/DB calls (no database).
$root = dirname(__DIR__);
$source = file_get_contents($root . '/public_html/downloads/index.php');
function checkLayout($ok, $label) { if (!$ok) { throw new RuntimeException($label); } echo "PASS: $label\n"; }

// --- change list parsing -------------------------------------------------------
if (!preg_match('/function happaChangeItems\(.*?\n}\n/s', $source, $fn)) throw new RuntimeException('happaChangeItems missing');
eval($fn[0]);
checkLayout(happaChangeItems("・研究画面を整理\n\n- 地図を軽く\r\n3) 保存を速く", 'plaintext') === array('研究画面を整理', '地図を軽く', '保存を速く'), 'one item per line, bullets removed');
checkLayout(happaChangeItems('<p>A &amp; B</p><ul><li>C</li></ul>', 'html') === array('A & B', 'C'), 'html reduced to plain items');
checkLayout(happaChangeItems('<script>x</script>', 'plaintext') === array('<script>x</script>'), 'plaintext kept as text (escaped on output)');

// --- listing state ---------------------------------------------------------------
if (!preg_match('/\$listingSql = .*?(?=\$display \.= PLG_replaceTags)/s', $source, $m)) throw new RuntimeException('Listing block missing');
class LayoutVars {
    public $values = array(); public $parsed = array();
    function set_var($k, $v) { $this->values[$k] = $v; }
    function parse($target, $file, $append = false) {
        $out = $file . ':' . $this->values['current'];
        $this->values[$target] = ($append ? ($this->values[$target] ?? '') : '') . $out;
        $this->parsed[] = $target;
    }
}
$rows = array(); $queries = array();
function DB_query($sql) { global $rows, $queries; $queries[] = $sql; return substr($sql, -10) === 'LIMIT 0, 1' ? array_slice($rows, 0, 1) : array_slice($rows, 1); }
function DB_numRows($r) { return count($r); }
function DB_fetchArray(&$r) { return array_shift($r); }
function DB_escapeString($s) { return addslashes($s); }
function DLM_htmlspecialchars($s) { return htmlspecialchars($s, ENT_QUOTES); }
function DLM_PrettySize($s) { return $s . ' B'; }
function COM_buildURL($u) { return $u; }
function COM_printPageNavigation() { return 'PAGER'; }
function happaReleasedText($d) { return $d . ' 公開'; }
function dlformat($T, $A) { $T->set_var('current', $A['lid']); }
$_CONF = array('site_url' => 'https://example.test'); $_TABLES = array('downloads' => 'd', 'downloadcategories' => 'c');
$_DLM_CONF = array('date_format' => '%Y/%m/%d');
$carr_count = 0; $sql_cid_list = ''; $now = 0; $cid = 'root'; $nppage = 0; $orderby = 'dated'; $page = 1; $show = 10;
foreach (array(true, false) as $listingJapanese) {
    foreach (array(0, 1, 3) as $count) {
        $rows = array(); for ($i = 0; $i < $count; $i++) { $rows[] = array('lid' => "f$i", 'version' => "0.9.$i", 'size' => 10, 'date' => 0); }
        $maxrows = $count; $T = new LayoutVars();
        eval($m[0]);
        checkLayout($hasVisibleFiles === ($count > 0), "visible files state ($count)");
        checkLayout($T->values['happa_guide_style'] === ($count > 0 ? '' : 'display:none'), 'guide only with files');
        checkLayout($T->values['happa_latest_style'] === ($count > 0 ? '' : 'display:none'), 'latest section only with files');
        checkLayout($T->values['happa_past_style'] === ($count > 1 ? '' : 'display:none'), 'earlier versions only when there are older files');
        checkLayout(($T->values['happa_latest_record'] === 'latest:f0') === ($count > 0), 'newest file rendered as the latest build');
        checkLayout($T->values['filelisting_records'] === ($count > 1 ? implode('', array_map(function ($i) { return "records:f$i"; }, range(1, $count - 1))) : ''), 'older files listed once, latest excluded');
        checkLayout(($T->values['happa_hero_cta'] !== '') === ($count > 0) && ($count === 0 || strpos($T->values['happa_hero_cta'], 'visit.php?id=f0') !== false), 'hero button downloads the latest file');
        checkLayout(($T->values['happa_empty_state'] !== '') === ($count === 0), 'empty state only without files');
        checkLayout(strpos($T->values['happa_empty_state'] . $T->values['happa_hero_cta'] . $T->values['happa_past_count'], $listingJapanese ? ($count > 0 ? '最新版をダウンロード' : '開発者ブログを見る') : ($count > 0 ? 'Download the latest build' : 'Read the developer blog')) !== false, 'localized text');
    }
}

// --- templates -----------------------------------------------------------------------
$tpl = $root . '/plugins/downloads/templates/default/';
$page = file_get_contents($tpl . 'filelisting.thtml');
checkLayout(strpos($page, '{happa_latest_record}') < strpos($page, '{filelisting_records}') && strpos($page, '{filelisting_records}') < strpos($page, 'happa-dl-steps'), 'latest, earlier versions, then guide');
checkLayout(strpos($page, 'id="dlm_filelist_info"') !== false, 'existing fragment target preserved');
foreach (array('filelisting_latest.thtml', 'filelisting_record.thtml', 'filelisting_record_no_tn.thtml') as $name) {
    $record = file_get_contents($tpl . $name);
    checkLayout(strpos($record, '{visitfile_url}') !== false && strpos($record, '{filedetail_url}') === false && strpos($record, '{happa_version}') !== false, "$name downloads without a detail link");
}
checkLayout(preg_match('/<b>|<strong>|font-weight:\s*(bold|[6-9]00)/i', $page . file_get_contents($root . '/public_html/downloads/downloads-page.css')) === 0, 'no bold text');
checkLayout(strpos($source, "COM_redirect(\$_CONF['site_url'] . '/downloads/index.php');") !== false, 'detail page redirects to the listing');
