<?php
// Isolated fixture only. Never deploy on Geeklog.
$savedPost=$_POST;$savedGet=$_GET;$savedMethod=isset($_SERVER['REQUEST_METHOD'])?$_SERVER['REQUEST_METHOD']:'GET';
require __DIR__.'/base-fixture.php';
require_once dirname(__DIR__).'/plugins/xpost/classes/Core.php';
if(getenv('XPOST_INBOX_WEB')==='1'){
    $_POST=$savedPost;$_GET=$savedGet;$_SERVER['REQUEST_METHOD']=$savedMethod;
    $_CONF['site_admin_url']='http://127.0.0.1:18765/admin';
}else{
    $fixtureStore=new Happa\XPost\Store($_CONF['path_data'].'xpost');$fixtureState=$fixtureStore->read('feedback');
    $_SERVER['REQUEST_METHOD']='POST';$_POST=array('action'=>'approve_draft','target'=>'201',
        'draft_version'=>Happa\XPost\Feedback::draftVersion($fixtureState['items']['201'],$fixtureStore->configuration()));
    if($case==='inbox_stale')$_POST['draft_version']=str_repeat('0',64);
    if($case==='inbox_decline')$_POST['action']='decline_draft';
    if($case==='inbox_csrf')$case='csrf';
}
