<?php

// +---------------------------------------------------------------------------+
// | Backlog Plugin for Geeklog                                                |
// +---------------------------------------------------------------------------+
// | admin/index.php                                                           |
// +---------------------------------------------------------------------------+
// | Copyright (C) 2015 MeYan                                                  |
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

require_once ('../../../lib-common.php');

if (!SEC_hasRights('backlog.admin')) {
  exit;
}

BACKLOG_ensureCategorySchema();
$filterCookie = BACKLOG_filterCookieName($_USER['uid']);
$savedFilters = BACKLOG_decodeFilterCookie($_COOKIE[$filterCookie] ?? '');
$listFilters = BACKLOG_resolveFilters($_GET, $_POST, $savedFilters, $_SERVER['REQUEST_METHOD']);
$BACKLOG_CATEGORY_FILTER = $listFilters['category'];
if ($listFilters['persist']) { BACKLOG_persistFilters($_USER['uid'], $listFilters); }

// +---------------------------------------------------------------------------+
// | DEFINE|
// +---------------------------------------------------------------------------+

$T = new Template($_CONF['path'] . 'plugins/backlog/templates');
$T->set_file(array (
    'headermsg'        => 'blockheader-message.thtml',
    'blockheader'      => 'blockheader.thtml',
    'top_menu'         => 'topmenu.thtml',
    'option_menu'      => 'optionmenu.thtml',
    'edit'             => 'edit.thtml',
    'summary'          => 'sumheader.thtml',
    'table_header'     => 'tableheader.thtml',
    'table_footer'     => 'tablefooter.thtml',
    'editor_header'    => 'editor_header.thtml',
    'editor_main'      => 'editor_main.thtml',
    'ticket_read'      => 'ticket_read.thtml',
    'editor_option'    => 'editor_option.thtml',
    'editor_footer'    => 'editor_footer.thtml',
    'editor_comment'   => 'editor_comment.thtml',
    ));

$T->set_var(array(
    'icon_url'         => $_CONF['site_url'] . '/backlog/images/backlog.png',
    'lang_modename'    => BACKLOG_str('edit_mode'),
    'lang_piname'      => BACKLOG_str('piname'),
    'msg_title'        => BACKLOG_str('blockmsg_title'),
    'menu_msg'         => BACKLOG_str('menu_msg'),
    'menu_create'      => BACKLOG_str('menu_create'),
    'menu_listview'    => BACKLOG_str('menu_listview'),
    'menu_summaryt'    => BACKLOG_str('menu_summaryt'),
    'menu_management'  => BACKLOG_str('menu_management'),
    'confirm_delete'   => BACKLOG_str('confirm_delete'),
    'save'             => BACKLOG_str('save'),
    'addcomment'       => BACKLOG_str('addcomment'),
    'cancel'           => BACKLOG_str('cancel'),
    'delete'           => BACKLOG_str('delete'),
    'prev'             => BACKLOG_str('prev'),
    'edit'             => BACKLOG_str('edit'),
    'option_showall'   => BACKLOG_str('option_showall'),
    'option_assigned'  => BACKLOG_str('option_assigned'),
    'option_completed' => BACKLOG_str('option_completed'),
    'option_reload'    => BACKLOG_str('option_reload'),
    'ticket_title'     => BACKLOG_str('ticket_title'),
    'ticket_value'     => BACKLOG_str('ticket_value'),
    'ticket_limit'     => BACKLOG_str('ticket_limit'),
    'ticket_holder'    => BACKLOG_str('ticket_holder'),
    'ticket_status'    => BACKLOG_str('ticket_status'),
    'ticket_file'      => BACKLOG_str('ticket_file'),
    'ticket_year'      => BACKLOG_str('ticket_year'),
    'ticket_month'     => BACKLOG_str('ticket_month'),
    'ticket_day'       => BACKLOG_str('ticket_day'),
    'ticket_comment'   => BACKLOG_str('ticket_comment'),
    'ticket_remove'    => BACKLOG_str('ticket_remove'),
    'ticket_register'  => BACKLOG_str('ticket_register'),
    'select_file'      => BACKLOG_str('select_file'),
    'holder_rank'      => BACKLOG_str('holder_rank'),
    'holder_id'        => BACKLOG_str('holder_id'),
    'holder_assign'    => BACKLOG_str('holder_assign'),
    'holder_limitover' => BACKLOG_str('holder_limitover'),
    'holder_complete'  => BACKLOG_str('holder_complete'),
    'comment_history'  => BACKLOG_str('comment_history'),
    'comment_form'     => BACKLOG_str('comment_form'),
    ));

// +---------------------------------------------------------------------------+
// | Args                                                                      |
// +---------------------------------------------------------------------------+

$mode        = BACKLOG_getArgs('mode');
$id          = BACKLOG_getArgs('id');
$status      = BACKLOG_getArgs('status');
$title       = BACKLOG_getRecordArgs('title');
$text        = BACKLOG_getRecordArgs('text');
$limit_year  = BACKLOG_getArgs('limit_year');
$limit_month = BACKLOG_getArgs('limit_month');
$limit_day   = BACKLOG_getArgs('limit_day');
$uid         = BACKLOG_getArgs('uid');
$fupload     = BACKLOG_getArgs('fupload');
$fname       = BACKLOG_getArgs('fname');
$fdelete     = BACKLOG_getArgs('fdelete');
$opt         = BACKLOG_getArgs('opt');
$opt1        = $listFilters['opt1'];
$opt2        = $listFilters['opt2'];
$comment     = BACKLOG_getRecordArgs('comment');
$feedback    = BACKLOG_getRecordArgs('feedback');
$category    = $BACKLOG_CATEGORY_FILTER;
$T->set_var(array(
    'category_navigation' => BACKLOG_categoryNavigation($category, $listFilters['q']),
    'category_value' => $category,
    'search_value' => htmlspecialchars($listFilters['q'], ENT_QUOTES, 'UTF-8'),
    'search_summary' => '',
));

$mutatingModes = array(
    BACKLOG_str('addcomment'),
    BACKLOG_str('save'),
    BACKLOG_str('delete'),
);

if (in_array($mode, $mutatingModes, true)
    && ($_SERVER['REQUEST_METHOD'] !== 'POST' || !SEC_checkToken())) {
    COM_accessLog("Invalid CSRF token for Backlog action by user {$_USER['username']}.");
    $content = COM_showMessageText($MESSAGE[29], $MESSAGE[30]);
    COM_output(COM_createHTMLDocument($content, array('pagetitle' => $MESSAGE[30])));
    exit;
}

$T->set_var(array(
    'gltoken_name' => CSRF_TOKEN,
    'gltoken'      => SEC_createToken(),
));

switch ($mode) {

  case BACKLOG_str('addcomment'):

    $ret = BACKLOG_getFile();
    $lang = array(
        'id'          => $id,
        'comment'     => $comment,
        'fname'       => $ret['data']['fname'],
        'fdata'       => $ret['data']['fdata'],
        );
    $result = BACKLOG_execute('addcomment',$lang);

  case 'comment':

    $ticket = BACKLOG_getTicket($id);
    $comment = BACKLOG_getComment($id);

    $message = "";
    if(count($comment) == 0) {
        $message = "<dd>" . BACKLOG_str('no_comment') . "</dd>";
    }
    else {

        for ($i = 0; $i < count($comment); ++$i) {

            $message .= "    <dd><header class='uk-comment-header uk-grid-medium uk-flex-middle uk-grid'>\n";
            $message .= "      <div class='uk-width-auto'>" . USER_getPhoto($comment[$i]['register'], "", "", 60) . "</div>\n";
            $message .= "      <div class='uk-width-expand'>\n";
            $message .= "          <ul class='uk-comment-meta uk-subnav uk-subnav-divider uk-margin-remove-top'>\n";
            $message .= "              <li>" . htmlspecialchars($comment[$i]['fullname'], ENT_QUOTES, 'UTF-8') . "</li>\n";
            $message .= "              <li></li>\n";
            $message .= "          </ul>\n";
            $message .= "          <div class='uk-comment-body' style='white-space:pre-wrap;overflow-wrap:anywhere;line-height:1.7;font-weight:400;'>" . htmlspecialchars($comment[$i]['text'], ENT_QUOTES, 'UTF-8') . "</div>\n";
            $message .= "      </div>\n";
            $message .= "    </header></dd>";

        }
    }

    $T->set_var(array(
        'category_options' => BACKLOG_categoryOptions($ticket['category']),
        'ticket_id'    => $ticket['ticket_id'],
        'disable'      => ' disabled',
        'title'        => $ticket['title'],
        'text'         => htmlspecialchars($ticket['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        'opt_fname'    => $ticket['opt_fname'],
        'opt_fdelete'  => $ticket['opt_fdelete'],
        'comment_list' => $message,
        'category_label' => BACKLOG_categoryLabel($ticket['category']),
        'ticket_body' => BACKLOG_renderTicketText($ticket['text']),
        ));

    $content = "";

    $T->parse('output', 'blockheader');
    $content .= $T->finish($T->get_var('output'));

    $T->parse('output', 'top_menu');
    $content .= $T->finish($T->get_var('output'));
  
    $T->parse('output', 'editor_header');
    $content .= $T->finish($T->get_var('output'));

    $T->parse('output', 'ticket_read');
    $content .= $T->finish($T->get_var('output'));

    $T->parse('output', 'editor_comment');
    $content .= $T->finish($T->get_var('output'));

    break;

  case 'edit':

    $ticket = BACKLOG_getTicket($id);
    if (empty($ticket)) {
        $content = COM_showMessageText('指定されたチケットが見つかりません。', 'Backlog');
        break;
    }

    $T->set_var(array(
        'category_options' => BACKLOG_categoryOptions($ticket['category']),
        'ticket_id'   => $ticket['ticket_id'],
        'disable'     => $ticket['disable'],
        'title'       => $ticket['title'],
        'text'        => htmlspecialchars($ticket['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        'opt_year'    => $ticket['opt_year'],
        'opt_month'   => $ticket['opt_month'],
        'opt_day'     => $ticket['opt_day'],
        'opt_holder'  => $ticket['opt_holder'],
        'opt_status'  => $ticket['opt_status'],
        'opt_display' => $ticket['opt_display'],
        'opt_fname'   => $ticket['opt_fname'],
        'opt_fdelete' => $ticket['opt_fdelete'],
        'opt_fupload' => $ticket['opt_fupload'],
        ));

    $content = "";

    $T->parse('output', 'blockheader');
    $content .= $T->finish($T->get_var('output'));

    $T->parse('output', 'top_menu');
    $content .= $T->finish($T->get_var('output'));

    $T->parse('output', 'editor_header');
    $content .= $T->finish($T->get_var('output'));

    $T->parse('output', 'editor_main');
    $content .= $T->finish($T->get_var('output'));

    $T->parse('output', 'editor_option');
    $content .= $T->finish($T->get_var('output'));

    break;

  case BACKLOG_str('save'):

    // Keep the stored work record literal; renderers escape it at the HTML boundary.
    $data = $text;

    $content = "";

    if($title == "" or $text == "")
    {
        $T->set_var(array(
            'msg_text' => BACKLOG_str('blockmsg_caution')
            ));

        $T->parse('output', 'blockheader');
        $content .= $T->finish($T->get_var('output'));

        $T->parse('output', 'headermsg');
        $content .= $T->finish($T->get_var('output'));

        break;
    }

    $previousStatus = null;
    if (BACKLOG_isValidTicketId($id)) {
        $storedStatus = DB_getItem($_TABLES['backlog_ticket'], 'status', "ticketid = '" . DB_escapeString($id) . "'");
        if ($storedStatus !== null && $storedStatus !== '') { $previousStatus = (int) $storedStatus; }
    }
    $feedbackAction = BACKLOG_feedbackAction($status, $previousStatus, $feedback);
    if ($feedbackAction === 'missing') {
        $T->set_var(array(
            'msg_text' => '差戻しにするときは、差戻しコメントを入力してください。チケットは保存していません。'
            ));

        $T->parse('output', 'blockheader');
        $content .= $T->finish($T->get_var('output'));

        $T->parse('output', 'headermsg');
        $content .= $T->finish($T->get_var('output'));

        break;
    }

    $ret = BACKLOG_getFile();

    if($ret['val'] == 1) {
        $T->set_var(array(
            'msg_text' => BACKLOG_str('blockmsg_sizeover')
            ));

        $T->parse('output', 'blockheader');
        $content .= $T->finish($T->get_var('output'));

        $T->parse('output', 'headermsg');
        $content .= $T->finish($T->get_var('output'));

        break;
    }
    
    $lang = array(
        'category'    => BACKLOG_categoryFilter($_POST['category'] ?? '') === 'improvement' ? 'improvement' : 'other',
        'id'          => $id,
        'status'      => $status,
        'title'       => $title,
        'text'        => $data,
        'limit_year'  => $limit_year,
        'limit_month' => $limit_month,
        'limit_day'   => $limit_day,
        'uid'         => $uid,
        'fdelete'     => $fdelete,
        'fname'       => $ret['data']['fname'],
        'fdata'       => $ret['data']['fdata'],
        'opt1'        => $opt1,
        'opt2'        => $opt2,
        'q'           => $listFilters['q'],
        );

    $result = BACKLOG_execute('save',$lang);
    if ($feedbackAction === 'record' && $result === BACKLOG_str('blockmsg_regist')) {
        // The 差戻し reason goes into the ticket's comment history, next to the status change.
        BACKLOG_execute('addcomment', array(
            'id'      => $id,
            'comment' => BACKLOG_feedbackCommentText($feedback),
            ));
    }
    $list = BACKLOG_execute('reload',$lang);
    $T->set_var('search_summary', BACKLOG_searchSummary($listFilters['q'], $GLOBALS['BACKLOG_LIST_COUNT'] ?? 0, $category));
    if($list == ""){
        $message = '<br/>'.BACKLOG_str('no_ticket');
    }
    else {
        $message = '';
    }

    $T->set_var(array(
        'msg_text'       => $result,
        'checked1'       => $opt1 === '1' ? ' checked' : '',
        'checked2'       => $opt1 === '1' ? '' : ' checked',
        'checked3'       => $opt2,
        'tickets_list'   => $list,
        'message'        => $message,
        ));

    $T->parse('output', 'headermsg');
    $content .= $T->finish($T->get_var('output'));

    $T->parse('output', 'blockheader');
    $content .= $T->finish($T->get_var('output'));

    $T->parse('output', 'top_menu');
    $content .= $T->finish($T->get_var('output'));

    $T->parse('output', 'option_menu');
    $content .= $T->finish($T->get_var('output'));

    $T->parse('output', 'table_header');
    $content .= $T->finish($T->get_var('output'));

    $T->parse('output', 'table_footer');
    $content .= $T->finish($T->get_var('output'));

    break;

  case BACKLOG_str('delete'):

    $lang = array(
      'id'   => $id,
      'opt1' => $opt1,
      'opt2' => $opt2,
      'q'    => $listFilters['q'],
    );

    $result = BACKLOG_execute('delete',$lang);
    $list = BACKLOG_execute('reload',$lang);
    $T->set_var('search_summary', BACKLOG_searchSummary($listFilters['q'], $GLOBALS['BACKLOG_LIST_COUNT'] ?? 0, $category));
    if($list == ""){
      $message = '<br/>'.BACKLOG_str('no_ticket');
    }
    else {
      $message = '';
    }

    $T->set_var(array(
        'msg_text'       => $result,
        'checked1'       => $opt1 === '1' ? ' checked' : '',
        'checked2'       => $opt1 === '1' ? '' : ' checked',
        'checked3'       => $opt2,
        'tickets_list'   => $list,
        'message'        => $message,
        ));

    $content = "";

    $T->parse('output', 'headermsg');
    $content .= $T->finish($T->get_var('output'));

    $T->parse('output', 'blockheader');
    $content .= $T->finish($T->get_var('output'));

    $T->parse('output', 'top_menu');
    $content .= $T->finish($T->get_var('output'));
  
    $T->parse('output', 'option_menu');
    $content .= $T->finish($T->get_var('output'));

    $T->parse('output', 'table_header');
    $content .= $T->finish($T->get_var('output'));

    $T->parse('output', 'table_footer');
    $content .= $T->finish($T->get_var('output'));

    break;

  case 'sum':

    $list = BACKLOG_getSummaryList();

    $T->set_var(array(
        'summary_list'     => $list,
        ));

    $content = "";

    $T->parse('output', 'blockheader');
    $content .= $T->finish($T->get_var('output'));

    $T->parse('output', 'top_menu');
    $content .= $T->finish($T->get_var('output'));
  
    $T->parse('output', 'summary');
    $content .= $T->finish($T->get_var('output'));

    $T->parse('output', 'table_footer');
    $content .= $T->finish($T->get_var('output'));

    break;
    
  case 'filter':

    $lang = array(
      'opt' => $opt,
      'uid' => $uid,
    );

    $list = BACKLOG_execute('filter', $lang);
    if($list == ""){
      $message = '<br/>'.BACKLOG_str('no_ticket');
    }
    else {
      $message = '';
    }

    $T->set_var(array(
        'tickets_list'   => $list,
        'message'        => $message,
        ));

    $content = "";

    $T->parse('output', 'blockheader');
    $content .= $T->finish($T->get_var('output'));

    $T->parse('output', 'top_menu');
    $content .= $T->finish($T->get_var('output'));
  
    $T->parse('output', 'table_header');
    $content .= $T->finish($T->get_var('output'));

    $T->parse('output', 'table_footer');
    $content .= $T->finish($T->get_var('output'));

    break;
    
  default:

    $lang = array(
      'opt1' => $opt1,
      'opt2' => $opt2,
      'q'    => $listFilters['q'],
    );

    if($opt1 == 1){
      $checked1 = ' checked';
      $checked2 = '';
    }
    else{
      $checked1 = '';
      $checked2 = ' checked';
    }

    $list = BACKLOG_execute('reload', $lang);
    $T->set_var('search_summary', BACKLOG_searchSummary($listFilters['q'], $GLOBALS['BACKLOG_LIST_COUNT'] ?? 0, $category));
    if($list == ""){
      $message = '<br/>'.BACKLOG_str('no_ticket');
    }
    else {
      $message = '';
    }

    $T->set_var(array(
        'checked1'         => $checked1,
        'checked2'         => $checked2,
        'checked3'         => $opt2,
        'tickets_list'     => $list,
        'message'          => $message,
        ));

    $content = "";

    $T->parse('output', 'blockheader');
    $content .= $T->finish($T->get_var('output'));

    $T->parse('output', 'top_menu');
    $content .= $T->finish($T->get_var('output'));
  
    $T->parse('output', 'option_menu');
    $content .= $T->finish($T->get_var('output'));

    $T->parse('output', 'table_header');
    $content .= $T->finish($T->get_var('output'));

    $T->parse('output', 'table_footer');
    $content .= $T->finish($T->get_var('output'));
 
    break;
}

$content .= "</div></div>";

$display = COM_createHTMLDocument($content);
COM_output($display);

?>
