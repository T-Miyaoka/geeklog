<?php

// +---------------------------------------------------------------------------+
// | Backlog Plugin for Geeklog                                                |
// +---------------------------------------------------------------------------+
// | geeklog/plugins/backlog/functions.inc                                     |
// +---------------------------------------------------------------------------+
// | Copyright (C) 2022 Takeshi Miyaoka                                        |
// |                                                                           |
// | Constructed with the Universal Plugin                                     |
// +---------------------------------------------------------------------------+
// | This program is free software; you can redistribute it and/or             |
// | modify it under the terms of the GNU General Public License               |
// | as published by the Free Software Foundation; either version 2            |
// | of the License, or (at your option) any later version.                    |
// |                                                                           |
// | This program is distributed in the hope that it will be useful,           |
// | but WITHOUT ANY WARRANTY; without even the implied warranty of            |
// | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the             |
// | GNU General Public License for more details.                              |
// |                                                                           |
// | You should have received a copy of the GNU General Public License         |
// | along with this program; if not, write to the Free Software Foundation,   |
// | Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.           |
// |                                                                           |
// +---------------------------------------------------------------------------+

if (stripos($_SERVER['PHP_SELF'], basename(__FILE__)) !== false) {
    die ('This file can not be used on its own.');
}

/**
* Includes language file 
*/
$langfile = dirname(__FILE__) . '/language/' . $_CONF['language'] . '.php';

if (file_exists($langfile)) {
	require_once $langfile;
} else {
	require_once dirname(__FILE__) . '/language/english_utf-8.php';
}

/*
* Loads config
*/
require_once dirname(__FILE__) . '/config.php';

// +---------------------------------------------------------------------------+
// | Geeklog Plugin API Implementations                                        |
// +---------------------------------------------------------------------------+

/**
* Returns the items for this plugin that should appear on the main menu
*
* @return    array   Associative array of menu items to be added to menu
*/
function plugin_getmenuitems_backlog() {
    return FALSE;
}

/**
* Checks to see if this Plugin uses comments
*
* @return   boolean TRUE or FALSE
*/
function plugin_commentsupport_backlog() {
    return FALSE;
}

/**
* This will put an option for the plugin in the command and control block on
* moderation.php
*
* @return   array   Array containing (plugin name, admin url, url of plugin icon)
*/
function plugin_cclabel_backlog() {
    global $_CONF;

    $pi_name   = BACKLOG_str('admin_label');
    $admin_url = $_CONF['site_admin_url'] . '/plugins/backlog/index.php';
    $icon_url  = $_CONF['site_url'] . '/backlog/images/backlog.png';

    return array($pi_name, $admin_url, $icon_url);
}

/**
* Returns the administrative option for this plugin
*
* @return   array   Array containing (plugin name, plugin admin url, # of items
*                   in plugin or '')
*/
function plugin_getadminoption_backlog() {
    global $_USER, $_CONF, $_TABLES;

    if (SEC_hasRights('backlog.admin')){
        $total = DB_getItem($_TABLES['backlog_ticket'], 'count(*)', "status != 4");
        $count = DB_getItem($_TABLES['backlog_ticket'], 'count(*)', "holder = {$_USER['uid']} and status != 4");

        $pi_name   = BACKLOG_str('admin_label');
        $admin_url = $_CONF['site_admin_url'] . '/plugins/backlog/index.php';
        $num_items  = $count.'/'.$total;

        return array($pi_name, $admin_url, $num_items);
    }
}

/*
* Returns the version for this plugin
*
* @return string VersionNo
*/
function plugin_chkVersion_backlog() {
	global $_BACKLOG_CONF;
	return $_BACKLOG_CONF['pi_version'];
}

/**
* Upgrades this plugin
*
* @return   boolean TRUE (= success)
*/
function plugin_upgrade_backlog() {
	global $_CONF, $_TABLES, $_BACKLOG_CONF;

	// Updates plugin info
	$sql = "UPDATE {$_TABLES['plugins']} "
		 . "  SET pi_version = '" . addslashes($_BACKLOG_CONF['pi_version']) . "' "
		 . "  WHERE (pi_name = 'backlog')";
	$result = DB_query($sql);

	return TRUE;
}

/**
* Automatic uninstall function for plugins
*
* @return   array
*/
function plugin_autouninstall_backlog() {
    global $_BACKLOG_CONF;

    $out = array (
        /* give the name of the tables, without $_TABLES[] */
        'tables'     => array('backlog_ticket', 'backlog_comment'),
		/* give the full name of the group, as in the db */
        'groups'     => array($_BACKLOG_CONF['pi_admin']),
		/* give the full name of the feature, as in the db */
        'features'   => array($_BACKLOG_CONF['pi_name']  . '.admin'),
		/* give the full name of the block, including 'phpblock_', etc */
        'php_blocks' => array(),
		/* give all vars with their name */
        'vars'       => array()
    );

    return $out;
}

// +---------------------------------------------------------------------------+
// | Backlog Plugin API Implementations                                        |
// +---------------------------------------------------------------------------+

require_once __DIR__ . '/categories.inc';

function BACKLOG_str($str) {
    global $LANG_BACKLOG;
    return isset($LANG_BACKLOG[$str]) ? $LANG_BACKLOG[$str] : '(undefined)';
}

function BACKLOG_getArgs ($key) {

  if (isset($_GET[$key])) {
      $ret = is_scalar($_GET[$key]) ? COM_applyFilter($_GET[$key]) : '';
  } else {
      if (isset($_POST[$key])) {
          $ret = is_scalar($_POST[$key]) ? COM_applyFilter($_POST[$key]) : '';
      } else {
          $ret = '';
      }
  }

  return $ret;
}

/** Plain work records are escaped at SQL/output boundaries, not stripped on input. */
function BACKLOG_getRecordArgs($key)
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { return ''; }
    return is_string($_POST[$key] ?? null) ? str_replace(array("\r\n", "\r"), "\n", $_POST[$key]) : '';
}

function BACKLOG_getInternalArgs ($val, $key) {

  if (isset($val[$key])) {
      $ret = $val[$key];
  } else {
      $ret = '';
  }
  return $ret;
}

function BACKLOG_isValidTicketId($ticketId) {
    return preg_match(
        // Legacy varchar(32) rows contain the GUID truncated to eight final digits.
        '/\A[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-(?:[0-9A-F]{8}|[0-9A-F]{12})\z/i',
        (string) $ticketId
    ) === 1;
}

function BACKLOG_canModifyTicket($ticketId) {
    global $_USER, $_TABLES;

    if (SEC_inGroup('Root')) {
        return true;
    }

    if (!BACKLOG_isValidTicketId($ticketId)) {
        return false;
    }

    $safeTicketId = DB_escapeString($ticketId);
    $register = (int) DB_getItem(
        $_TABLES['backlog_ticket'],
        'register',
        "ticketid = '{$safeTicketId}'"
    );

    return $register > 0 && $register === (int) $_USER['uid'];
}

function BACKLOG_getComment($ticket_id) {
    global $_USER, $_TABLES;

    $comment = array();

    if (!BACKLOG_isValidTicketId($ticket_id)) {
        return $comment;
    }

    $ticket_id = DB_escapeString($ticket_id);
    $sql = "SELECT * FROM {$_TABLES['backlog_comment']} where ticketid = '{$ticket_id}'";
    $result = DB_query($sql);
    $num = DB_numrows($result);

    for ($i = 0; $i < $num; ++$i) {
        $A = DB_fetchArray($result);
            
        array_push(
            $comment,
            array(
                'commentid' => $A['commentid'],
                'text'      => $A['text'],
                'register'  => $A['register'],
                'fullname'  => DB_getItem($_TABLES['users'], 'fullname', "uid = '" . $A['register'] . "'"),
                'fname'     => $A['filename'],
                'fdata'     => $A['filedata'],
            ),
        );
    }

    return $comment;
}

function BACKLOG_getTicket($ticket_id) {
    global $_USER, $_TABLES;

    $ticket = array();

    if($ticket_id == "") {
        $ticket =  array(
            'ticket_id'   => BACKLOG_newGuid(),
            'disable'     => '',
            'title'       => '',
            'text'        => '',
            'category'    => BACKLOG_categoryFilter($GLOBALS['BACKLOG_CATEGORY_FILTER'] ?? 'other') === 'improvement' ? 'improvement' : 'other',
            'opt_year'    => COM_getYearFormOptions(date('Y')),
            'opt_month'   => COM_getMonthFormOptions(date('m')),
            'opt_day'     => COM_getDayFormOptions(date('d')),
            'opt_holder'  => BACKLOG_getUserFormOptions(''),
            'opt_status'  => BACKLOG_getStatusFormOptions('0'),
            'opt_display' => 'none',
            'opt_fname'   => '',
            'opt_fdelete' => 'none',
            'opt_fupload' => '',
        );
    }
    else {
        if (!BACKLOG_isValidTicketId($ticket_id)) {
            return array();
        }

        $safeTicketId = DB_escapeString($ticket_id);
        $sql = "SELECT * FROM {$_TABLES['backlog_ticket']} where ticketid = '{$safeTicketId}'";
        $result = DB_query($sql);
        $A = DB_fetchArray($result);

        if (!$A) {
            return array();
        }

        if($A['register'] != $_USER['uid'])
        {
            $disable = ' disabled';
            $display = 'none';
        }
        else {
            $disable = '';
            $display = '';
        }

        if($A['filename']) {
            $fname = $A['filename'];
            $fdelete = '';
            $fupload = 'none';
        }
        else {
            $fname   = '';
            $fdelete = 'none';
            $fupload = '';
        }

        $ticket = array(
            'ticket_id'   => $ticket_id,
            'disable'     => $disable,
            'title'       => htmlspecialchars($A['title'], ENT_QUOTES, 'UTF-8'),
            'text'        => $A['text'],
            'category'    => $A['work_category'] ?? 'other',
            'opt_year'    => COM_getYearFormOptions($A['limityear']),
            'opt_month'   => COM_getMonthFormOptions($A['limitmonth']),
            'opt_day'     => COM_getDayFormOptions($A['limitday']),
            'opt_holder'  => BACKLOG_getUserFormOptions($A['holder']),
            'opt_status'  => BACKLOG_getStatusFormOptions($A['status']),
            'opt_display' => $display,
            'opt_fname'   => htmlspecialchars($fname, ENT_QUOTES, 'UTF-8'),
            'opt_fdelete' => $fdelete,
            'opt_fupload' => $fupload,
        );
    }

    return $ticket;
}

function BACKLOG_getUserFormOptions($uid) {
    global $_USER, $_TABLES;

    $sql = "SELECT uid, username FROM {$_TABLES['users']} WHERE status = 3 and algorithm != 0 order by username";
    $result = DB_query($sql);
    $num = DB_numrows($result);
    $ret = '';

    for ($i = 0; $i < $num; ++$i)
    {
        $A = DB_fetchArray($result);

        if($A['uid'] == $uid){
          $selected_user =  ' selected="selected"';
        }
        else{
          $selected_user = '';
        }

        $ret .= '<option value="'.$A['uid'].'"'.$selected_user.'>'.$A['username'].'</option>'."\n";
    }
    return $ret;
}

function BACKLOG_getStatusFormOptions($status_no) {
  global $_TABLES;

  $ret = '';

  $arrstatus = array('0','1','2','3','4','5');
  $arrstatus[$status_no] = ' selected="selected"';

  $ret = '<option value="0"'.$arrstatus['0'].'>'.BACKLOG_str('status_new').'</option>'."\n"
       . '<option value="1"'.$arrstatus['1'].'>'.BACKLOG_str('status_do').'</option>'."\n"
       . '<option value="2"'.$arrstatus['2'].'>'.BACKLOG_str('status_done').'</option>'."\n"
       . '<option value="3"'.$arrstatus['3'].'>'.BACKLOG_str('status_feedback').'</option>'."\n"
       . '<option value="4"'.$arrstatus['4'].'>'.BACKLOG_str('status_close').'</option>'."\n"
       . '<option value="5"'.$arrstatus['5'].'>'.BACKLOG_str('status_pending').'</option>'."\n";

  return $ret;
}

function BACKLOG_getSummaryList() {
  global $_CONF, $_TABLES;

  $sql = "SELECT us.uid, us.username, count(*) cn "
       . "FROM {$_TABLES['backlog_ticket']} bl, {$_TABLES['users']} us "
       . "WHERE bl.status != 4 and bl.holder = us.uid "
       . (BACKLOG_categoryCondition('bl') === '' ? '' : ' AND ' . BACKLOG_categoryCondition('bl') . ' ')
       . "group by bl.holder order by cn desc, username asc;";

  $result = DB_query($sql);
  $num = DB_numrows($result);

  $ret = '';
  $trclass = '';

  for ($i = 0; $i < $num; ++$i)
  {
    $A = DB_fetchArray($result);
    $limitout = BACKLOG_getLimitEndCount($A['uid']);
    $closeout = DB_getItem($_TABLES['backlog_ticket'], 'count(*)', "status = 4 and holder = '{$A['uid']}'" . (BACKLOG_categoryCondition() === '' ? '' : ' AND ' . BACKLOG_categoryCondition()));

    if($trclass == "admin-listitem1") {
      $trclass = "admin-listitem2";
    }
    else {
      $trclass = "admin-listitem1";
    }
    
    switch($i+1) {
      case 1:
        $ranking = '<img src="'.$_CONF['site_url'].'/backlog/images/gold.gif">';
        break;
      case 2:
        $ranking = '<img src="'.$_CONF['site_url'].'/backlog/images/silver.gif">';
        break;
      case 3:
        $ranking = '<img src="'.$_CONF['site_url'].'/backlog/images/blonze.gif">';
        break;
      default:
        $ranking = $i + 1;
        break;
    }
    
    $ret .= '<tr class='.$trclass.' style="height: 32px;">'."\n"
         .  '  <td class="admin-list-field" style="text-align: center;">'.$ranking.'</td>'."\n"
         .  '  <td class="admin-list-field">'.$A['username'].'</td>'."\n"
         .  '  <td><a href="./index.php?mode=filter&opt=1&uid='.$A['uid'].BACKLOG_categorySuffix().'">'.$A['cn'].' '.BACKLOG_str('count').'</a></td>'."\n"
         .  '  <td><a href="./index.php?mode=filter&opt=2&uid='.$A['uid'].BACKLOG_categorySuffix().'">'.$limitout.' '.BACKLOG_str('count').'</a></td>'."\n"
         .  '  <td><a href="./index.php?mode=filter&opt=3&uid='.$A['uid'].BACKLOG_categorySuffix().'">'.$closeout.' '.BACKLOG_str('count').'</a></td>'."\n"
         .  '</tr>'."\n";
  }

  return $ret;
}

function BACKLOG_getLimitEndCount($uid) {
    global $_TABLES;

    $dt = new DateTime();
    $now = $dt->format('Y-m-d H:i:s');

    $uid = (int) $uid;
    $sql = "SELECT limityear, limitmonth, limitday "
         . "FROM {$_TABLES['backlog_ticket']} "
         . "WHERE status != 4 and holder = {$uid}"
         . (BACKLOG_categoryCondition() === '' ? '' : ' AND ' . BACKLOG_categoryCondition());

    $result = DB_query($sql);
    $num = DB_numrows($result);
    
    $limitout = 0;

    for ($i = 0; $i < $num; ++$i)
    {
        $A = DB_fetchArray($result);
        $end = "{$A['limityear']}-{$A['limitmonth']}-{$A['limitday']} 00:00:00";
        if (strtotime($end) < strtotime($now)) {
          $limitout++;
        }
    }

    return $limitout;
}

function BACKLOG_unpack($fdata) {
  $tmp = unpack("c*", $fdata);
  $ret = '';
  foreach($tmp as $val) {
    if($ret == '') { 
      $ret = $val;
    }
    else {
      $ret .= ','.$val;
    }
  }
  return $ret;
}

function BACKLOG_newGuid() {
    mt_srand((int)microtime(false) * 10000);

    $rand = mt_rand(1000000, 9999999);
    $timestamp = date("HisYmd");
    $uniqid = uniqid(mt_rand(), true);

    $characters = strtoupper(md5($rand . $timestamp . $uniqid));
    $guid = preg_replace("/^([0-9A-F]{8})([0-9A-F]{4})([0-9A-F]{4})([0-9A-F]{4})([0-9A-F]{12})$/is", "$1-$2-$3-$4-$5", $characters);

    return $guid;
}

function BACKLOG_getFile() {

    if (!isset($_FILES['upfile'])
        || $_FILES['upfile']['error'] !== UPLOAD_ERR_OK
        || !is_uploaded_file($_FILES['upfile']['tmp_name'])) {
        return array(
            'val' => 0, 
            'msg' => "", 
            'data' => array('fname' => "", 'fdata' => ""));
    }

    $backlog_config = config::get_instance();
    $_BACKLOG_CONF = $backlog_config->get_config('backlog');
    $fsize = filesize($_FILES["upfile"]["tmp_name"]);
    $fname = basename(str_replace(array("\r", "\n"), '', $_FILES["upfile"]["name"]));

    $allowedExtensions = array(
        'txt', 'csv', 'pdf',
        'png', 'jpg', 'jpeg', 'gif', 'webp',
        'docx', 'xlsx', 'pptx',
    );
    $allowedMimeTypes = array(
        'txt'  => array('text/plain'),
        'csv'  => array('text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'),
        'pdf'  => array('application/pdf'),
        'png'  => array('image/png'),
        'jpg'  => array('image/jpeg'),
        'jpeg' => array('image/jpeg'),
        'gif'  => array('image/gif'),
        'webp' => array('image/webp'),
        'docx' => array('application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'),
        'xlsx' => array('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'),
        'pptx' => array('application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'),
    );
    $extension = strtolower(pathinfo($fname, PATHINFO_EXTENSION));

    if ($fname === '' || strlen($fname) > 255) {
        return array(
            'val' => 1,
            'msg' => BACKLOG_str('blockmsg_sizeover'),
            'data' => array('fname' => '', 'fdata' => '')
        );
    }

    if (!in_array($extension, $allowedExtensions, true)) {
        return array(
            'val' => 1,
            'msg' => BACKLOG_str('blockmsg_filetype'),
            'data' => array('fname' => '', 'fdata' => '')
        );
    }

    if (!class_exists('finfo')) {
        COM_errorLog('Backlog upload rejected: the PHP fileinfo extension is unavailable.');
        return array(
            'val' => 1,
            'msg' => BACKLOG_str('blockmsg_filetype'),
            'data' => array('fname' => '', 'fdata' => '')
        );
    }

    $fileInfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $fileInfo->file($_FILES['upfile']['tmp_name']);
    if ($mimeType === false || !in_array($mimeType, $allowedMimeTypes[$extension], true)) {
        COM_errorLog('Backlog upload rejected: file extension and MIME type do not match.');
        return array(
            'val' => 1,
            'msg' => BACKLOG_str('blockmsg_filetype'),
            'data' => array('fname' => '', 'fdata' => '')
        );
    }

    if($_BACKLOG_CONF['maxfilesize'] * 1024 * 1024 < $fsize)
    {
        return array(
            'val' => 1, 
            'msg' => BACKLOG_str('blockmsg_sizeover'), 
            'data' => array('fname' => "", 'fdata' => ""));
    }

    $fp = fopen($_FILES['upfile']['tmp_name'], "rb");
    $fdata = fread($fp, $fsize);
    fclose($fp);
    $fdata = BACKLOG_unpack($fdata);

    return array(
        'val' => 0, 
        'msg' => "", 
        'data' => array('fname' => $fname, 'fdata' => $fdata));
}

/** Render a short overview and a natively collapsed, keyboard-accessible history. */
function BACKLOG_renderTicketText($text)
{
    $text = str_replace(array("\r\n", "\r"), "\n", trim((string) $text));
    $visible = preg_replace('/^Codex作業ID: [a-f0-9-]+\n*/u', '', $text);
    $summary = '';
    $detail = $visible;
    if (preg_match('/^【概要】\s*\n?(.*?)\n+【詳細】\s*\n?(.*)$/us', $visible, $parts)) {
        $summary = trim($parts[1]);
        $detail = trim($parts[2]);
    } else {
        $lines = preg_split('/\n+/u', $visible, -1, PREG_SPLIT_NO_EMPTY);
        $summary = trim($lines[0] ?? '');
        if ($summary === $visible) { $detail = ''; }
    }
    if (preg_match('/^(.{100})(.+)$/us', $summary, $long)) {
        $summary = $long[1] . '…';
        $detail = $visible;
    }
    $escape = function ($value) { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
    $style = 'white-space:pre-wrap;overflow-wrap:anywhere;word-break:normal;line-height:1.7;font-weight:400;';
    $html = '<div class="backlog-overview" style="' . $style . '">' . $escape($summary) . '</div>';
    if ($detail !== '') {
        $html .= '<details class="backlog-details" style="margin-top:.6em;font-weight:400;">'
            . '<summary style="cursor:pointer;color:#075985;font-weight:400;">詳細を表示</summary>'
            . '<div style="margin-top:.6em;padding:.7em .9em;border-left:2px solid #cbd5e1;' . $style . '">'
            . $escape($detail) . '</div></details>';
    }
    return $html;
}

function BACKLOG_execute($type, $lang) {
  global $_CONF, $_USER, $_TABLES, $_IMAGE_TYPE, $MESSAGE;

  $type = (string) $type;
  $fname = '';
  $fdata = '';

  $category    = (string) BACKLOG_getInternalArgs($lang, 'category');
  $rawId       = (string) BACKLOG_getInternalArgs($lang, 'id');
  $id          = DB_escapeString($rawId);
  $status      = max(0, min(5, (int) BACKLOG_getInternalArgs($lang, 'status')));
  $title       = DB_escapeString((string) BACKLOG_getInternalArgs($lang, 'title'));
  $text        = DB_escapeString((string) BACKLOG_getInternalArgs($lang, 'text'));
  $limit_year  = max(2000, min(2100, (int) BACKLOG_getInternalArgs($lang, 'limit_year')));
  $limit_month = max(1, min(12, (int) BACKLOG_getInternalArgs($lang, 'limit_month')));
  $limit_day   = max(1, min(31, (int) BACKLOG_getInternalArgs($lang, 'limit_day')));
  $uid         = max(0, (int) BACKLOG_getInternalArgs($lang, 'uid'));
  $fdelete     = BACKLOG_getInternalArgs($lang, 'fdelete');
  $fname       = DB_escapeString(basename(str_replace(array("\r", "\n"), '', (string) BACKLOG_getInternalArgs($lang, 'fname'))));
  $fdata       = DB_escapeString((string) BACKLOG_getInternalArgs($lang, 'fdata'));
  $opt         = (int) BACKLOG_getInternalArgs($lang, 'opt');
  $opt1        = BACKLOG_getInternalArgs($lang, 'opt1');
  $opt2        = BACKLOG_getInternalArgs($lang, 'opt2');
  $comment     = DB_escapeString((string) BACKLOG_getInternalArgs($lang, 'comment'));
  $regdate     = time();

  switch ($type) {
    case 'addcomment':
      if (!BACKLOG_isValidTicketId($rawId)) {
          return $MESSAGE[29];
      }
    
      $commentid = BACKLOG_newGuid();
      $sql  = "";
      $sql .= "insert into {$_TABLES['backlog_comment']} ";
      $sql .= "(ticketid, commentid, text, register, regdate, filename, filedata) ";
      $sql .= "values ";
      //$sql .= "('{$id}','{$commentid}','{$comment}',{$_USER['uid']},{$regdate},'{$fname}','{$fdata}')";
      $sql .= "('{$id}','{$commentid}','{$comment}',{$_USER['uid']},{$regdate},'{$fname}','{$fdata}')";
      DB_query($sql);
      return BACKLOG_str('blockmsg_regist');

      break;

    case 'save':
      if ($category !== '' && !in_array($category, array('improvement', 'other'), true)) { return $MESSAGE[29]; }
      BACKLOG_ensureCategorySchema();

        $sql = "SELECT count(*) cnt FROM {$_TABLES['backlog_ticket']} WHERE ticketid = '{$id}'";
        $result = DB_query($sql);
        $A = DB_fetchArray($result);

        if($A['cnt'] == 0){
          $sql  = "";
          $sql .= "insert into {$_TABLES['backlog_ticket']} ";
          $sql .= "(ticketid, work_category, status, title, text, limityear, limitmonth, limitday, holder, register, regdate, filename, filedata) ";
          $sql .= "values ";
          $sql .= "('{$id}','" . ($category === '' ? 'other' : $category) . "',{$status},'{$title}','{$text}',{$limit_year},{$limit_month},{$limit_day},{$uid},{$_USER['uid']},{$regdate},'{$fname}','{$fdata}')";
          DB_query($sql);
          return BACKLOG_str('blockmsg_regist');
        }
        else{
          if (!BACKLOG_canModifyTicket($rawId)) {
              COM_accessLog("Unauthorized Backlog update attempt by user {$_USER['username']}.");
              return $MESSAGE[29];
          }

          $sql  = "";
          $sql .= "update {$_TABLES['backlog_ticket']} set ";

          if($fdelete == "on") {
            $sql .= "filename = '',";
            $sql .= "filedata = '',";
          }

          $sql .= "status = {$status},";
          if ($category !== '') { $sql .= "work_category = '{$category}',"; }
          $sql .= "title = '{$title}',";
          $sql .= "text = '{$text}',";
          $sql .= "limityear = {$limit_year},";
          $sql .= "limitmonth = {$limit_month},";
          $sql .= "limitday = {$limit_day},";
          $sql .= "holder = {$uid} ";
          $sql .= "WHERE ticketid = '{$id}'";
          DB_query($sql);
          return BACKLOG_str('blockmsg_regist');
      }

      break;

    case 'delete':
        if (!BACKLOG_canModifyTicket($rawId)) {
          COM_accessLog("Unauthorized Backlog delete attempt by user {$_USER['username']}.");
          return $MESSAGE[29];
        }
        $sql = "delete FROM {$_TABLES['backlog_ticket']} WHERE ticketid = '{$id}'";
        DB_query($sql);
        return BACKLOG_str('blockmsg_remove');
      break;

    case 'reload':

      $status = array(
        BACKLOG_str('status_new'),
        BACKLOG_str('status_do'),
        BACKLOG_str('status_done'),
        BACKLOG_str('status_feedback'),
        BACKLOG_str('status_close'),
        BACKLOG_str('status_pending')
      );

      $opt_where = '';

      if($opt1 != '1'){
        $opt_where = "where holder = '" . $_USER['uid'] . "'";
        if($opt2 != 'checked'){
          $opt_where .= " and status != 4";
        }
      }
      else {
        if($opt2 != 'checked'){
          $opt_where .= "where status != 4";
        }
      }

      $categoryCondition = BACKLOG_categoryCondition();
      if ($categoryCondition !== '') { $opt_where .= ($opt_where === '' ? ' where ' : ' and ') . $categoryCondition; }
      $sql = "SELECT * FROM {$_TABLES['backlog_ticket']} ".$opt_where." order by limityear,limitmonth,limitday";
      $result = DB_query($sql);
      $num = DB_numrows($result);

      $ret = '';
      $trclass = '';
      
      for ($i = 0; $i < $num; ++$i)
      {
        $A = DB_fetchArray($result);

        if($trclass == "admin-listitem1"){
          $trclass = "admin-listitem2";
        }
        else{
          $trclass = "admin-listitem1";
        }

        $holder = DB_getItem($_TABLES['users'], 'username', "uid = '" . $A['holder'] . "'");
        $register = DB_getItem($_TABLES['users'], 'username', "uid = '" . $A['register'] . "'");
        $filename = DB_getItem($_TABLES['backlog_ticket'], 'filename', "ticketid = '" . $A['ticketid'] . "'");

        $limit_date = $A['limityear'].'-'.$A['limitmonth'].'-'.$A['limitday'];
        $dt = new DateTime();
        $today = $dt->format('Y-m-d');
        
        if(strtotime($limit_date) > strtotime($today)){
          $span_class = "yes";
        }
        elseif(strtotime($limit_date) < strtotime($today)){
          $span_class = "no";
        }
        else{
          $span_class = "no";
        }

        $text = BACKLOG_renderTicketText($A['text']);

        $ret .= '<tr class="'.$trclass.' backlog-ticket-row">';
        $ret .= '<td class="admin-list-field"><a href="./index.php?mode=edit&id='.$A['ticketid'].BACKLOG_categorySuffix().'"><img src="'.$_CONF['layout_url'].'/images/edit.'.$_IMAGE_TYPE.'"></a></td>';
        $ret .= '<td class="admin-list-field"><a href="./index.php?mode=comment&id='.$A['ticketid'].BACKLOG_categorySuffix().'"><img src="'.$_CONF['layout_url'].'/images/icon_info.'.$_IMAGE_TYPE.'"></a></td>';
        
        if($filename == "") {
          $ret .= '<td class="admin-list-field">-</td>';        
        }
        else{
          $ret .= '<td class="admin-list-field"><a href="'.$_CONF['site_url'].'/backlog/create_file.php?&id='.$A['ticketid'].'"><img src="'.$_CONF['site_url'].'/backlog/images/clip.png'.'"></a></td>';
        }
        
        $ret .= '<td class="admin-list-field backlog-status">'.$status[$A['status']].'</td>';
        $ret .= '<td class="admin-list-field backlog-date"><span class="'.$span_class.'">'.$limit_date.'</span></td>';
        $ret .= '<td class="admin-list-field backlog-title">'.htmlspecialchars($A['title'], ENT_QUOTES, 'UTF-8').(($GLOBALS['BACKLOG_CATEGORY_FILTER'] ?? 'all') === 'all' ? '<div style="margin-top:.3em;color:#475569;font-weight:400;">'.BACKLOG_categoryLabel($A['work_category'] ?? 'other').'</div>' : '').'</td>';
        $ret .= '<td class="admin-list-field backlog-text">'.$text.'</td>';
        $ret .= '<td class="admin-list-field">'.htmlspecialchars($holder, ENT_QUOTES, 'UTF-8').'</td>';
        $ret .= '<td class="admin-list-field">'.htmlspecialchars($register, ENT_QUOTES, 'UTF-8').'</td>';
        $ret .= '</tr>';
      }

      return $ret;

      break;

    case 'filter':

      $status = array(
        BACKLOG_str('status_new'),
        BACKLOG_str('status_do'),
        BACKLOG_str('status_done'),
        BACKLOG_str('status_feedback'),
        BACKLOG_str('status_close'),
        BACKLOG_str('status_pending')
      );

      if($opt == 3) {
        $sql = "SELECT * "
             . "FROM {$_TABLES['backlog_ticket']} "
             . "WHERE status = 4 and holder = '{$uid}'";
      }
      else{
        $sql = "SELECT * "
             . "FROM {$_TABLES['backlog_ticket']} "
             . "WHERE status != 4 and holder = '{$uid}'";
      }

      $categoryCondition = BACKLOG_categoryCondition();
      if ($categoryCondition !== '') { $sql .= ' AND ' . $categoryCondition; }
      $result = DB_query($sql);
      $num = DB_numrows($result);

      $ret = '';
      $trclass = '';

      for ($i = 0; $i < $num; ++$i)
      {
        $A = DB_fetchArray($result);

        if($trclass == "admin-listitem1") {
          $trclass = "admin-listitem2";
        }
        else{
          $trclass = "admin-listitem1";
        }

        $holder = DB_getItem($_TABLES['users'], 'username', "uid = '" . $A['holder'] . "'");
        $register = DB_getItem($_TABLES['users'], 'username', "uid = '" . $A['register'] . "'");
        $filename = DB_getItem($_TABLES['backlog_ticket'], 'filename', "ticketid = '" . $A['ticketid']. "'");

        $limit_date = $A['limityear'].'-'.$A['limitmonth'].'-'.$A['limitday'];
        $dt = new DateTime();
        $today = $dt->format('Y-m-d');

        if(strtotime($limit_date) > strtotime($today)){
          $span_class = "yes";
        }
        elseif(strtotime($limit_date) < strtotime($today)){
          $span_class = "no";
        }
        else{
          $span_class = "no";
        }
        
        if($opt == 2 and $span_class == "yes") {
        }
        else{

          $text = BACKLOG_renderTicketText($A['text']);

          $ret .= '<tr class="'.$trclass.' backlog-ticket-row">';
          $ret .= '<td class="admin-list-field"><a href="./index.php?mode=edit&id='.$A['ticketid'].BACKLOG_categorySuffix().'"><img src="'.$_CONF['site_url'].'/backlog/images/edit.png'.'"></a></td>';
          $ret .= '<td class="admin-list-field"><a href="./index.php?mode=comment&id='.$A['ticketid'].BACKLOG_categorySuffix().'"><img src="'.$_CONF['layout_url'].'/images/icon_info.'.$_IMAGE_TYPE.'"></a></td>';

          if($filename == "") {
            $ret .= '<td class="admin-list-field">-</td>';
          }
          else{
            $ret .= '<td class="admin-list-field"><a href="'.$_CONF['site_url'].'/backlog/create_file.php?&id='.$A['ticketid'].'"><img src="'.$_CONF['site_url'].'/backlog/images/clip.png'.'"></a></td>';
          }

          $ret .= '<td class="admin-list-field backlog-status">'.$status[$A['status']].'</td>';
          $ret .= '<td class="admin-list-field backlog-date"><span class="'.$span_class.'">'.$limit_date.'</span></td>';
          $ret .= '<td class="admin-list-field backlog-title">'.htmlspecialchars($A['title'], ENT_QUOTES, 'UTF-8').(($GLOBALS['BACKLOG_CATEGORY_FILTER'] ?? 'all') === 'all' ? '<div style="margin-top:.3em;color:#475569;font-weight:400;">'.BACKLOG_categoryLabel($A['work_category'] ?? 'other').'</div>' : '').'</td>';
          $ret .= '<td class="admin-list-field backlog-text">'.$text.'</td>';
          $ret .= '<td class="admin-list-field">'.htmlspecialchars($holder, ENT_QUOTES, 'UTF-8').'</td>';
          $ret .= '<td class="admin-list-field">'.htmlspecialchars($register, ENT_QUOTES, 'UTF-8').'</td>';
          $ret .= '</tr>';
        }
      }

      return $ret;
      
      break;

    default:
    COM_errorLog(__FUNCTION__ . ': Unknown type "' . $type . '" was called.');
      break;
  }
}
?>
