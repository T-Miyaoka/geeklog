<?php
require_once __DIR__.'/../plugins/xpost/classes/Core.php';
require_once __DIR__.'/../plugins/xpost/classes/View.php';
use Happa\XPost\Store;use Happa\XPost\Feedback;use Happa\XPost\Policy;use Happa\XPost\View;
$output=getenv('XPOST_TEST_DIR');if(!$output)throw new RuntimeException('Set XPOST_TEST_DIR');
$count=0;function check($ok,$name){global $count;if(!$ok)throw new RuntimeException($name);++$count;echo "PASS: $name\n";}
function rejects($fn,$name){try{$fn();}catch(RuntimeException $e){check(true,$name);return;}throw new RuntimeException($name);}
$conf=array('username'=>'__MeeSan__','api_key'=>'fixture-key','api_secret'=>'fixture-secret','access_token'=>'fixture-token','access_secret'=>'fixture-secret2');
$entry=array('state'=>'posted','post_id'=>'100','username'=>'__MeeSan__','sids'=>array('example_ja'),'at'=>gmdate('c'),'url'=>'https://x.com/__MeeSan__/status/100');
$draft=array('root'=>'100','root_key'=>'root','received'=>time(),'status'=>'registered','source'=>array('id'=>'201','text'=>'不作と蟲の減収を分けて表示できますか？'),
    'analysis'=>array('status'=>'registered','summary'=>'原因別表示の提案','draft'=>'ご提案ありがとうございます！🌾 改善項目EVT-044に登録しました。','backlog_id'=>'EVT-044','commit'=>'c56b1149'));
$pending=$draft;$pending['status']='pending';unset($pending['analysis']);$pending['source']['id']='202';
$question=$pending;$question['status']='question';$question['source']=array('id'=>'203','text'=>'<script>alert(1)</script>質問です');
$processed=$draft;$processed['status']='reaction';$processed['analysis']['draft']='';
$state=array('roots'=>array(),'last_sync'=>time(),'items'=>array('201'=>$draft,'202'=>$pending,'203'=>$question,'204'=>$processed));
$store=new Store($output.'/inbox-unit-'.bin2hex(random_bytes(4)));$store->configure($conf);$store->write('history',array('root'=>$entry));$store->write('feedback',$state);
$feedback=new Feedback($store);$v=Feedback::draftVersion($draft,$conf);$history=$store->read('history');
check(count(Feedback::inbox($state,array()))===3,'Only actionable replies and drafts shown');
rejects(function()use($feedback,$conf){$feedback->approve('201',str_repeat('0',64),2,$conf);},'Stale/tampered version rejected');
$changed=$conf;$changed['username']='other';rejects(function()use($feedback,$changed,$v){$feedback->approve('201',$v,2,$changed);},'Account change rejects stale approval');
rejects(function()use($feedback,$conf,$v){$feedback->approve('201',$v,1,$conf);},'Anonymous UID rejected');
$store->write('reply-history',array(array('target'=>'201','state'=>'uncertain')));
rejects(function()use($feedback,$conf,$v){$feedback->approve('201',$v,2,$conf);},'Already replied or uncertain targets cannot be approved');$store->write('reply-history',array());
$approval=$feedback->approve('201',$v,2,$conf);check($approval['state']==='approved' && $approval['sent']===false && $approval['uid']===2,'One approval records actor/version without sending');
check(isset(Feedback::inbox($store->read('feedback'),array())['201']),'Approved unsent draft remains visible');
foreach(array('failed','sending','uncertain')as$deliveryState){
    $deliveryEntry=array('target'=>'201','state'=>$deliveryState,'text'=>$draft['analysis']['draft']);
    $visible=Feedback::inbox($store->read('feedback'),array($deliveryEntry));
    check(isset($visible['201']) && $visible['201']['delivery']['state']===$deliveryState,'Delivery '.$deliveryState.' remains in inbox');
}
check(!isset(Feedback::inbox($store->read('feedback'),array(array('target'=>'201','state'=>'posted')))['201']),'Only confirmed posted reply hides approved item');
check(!isset(Feedback::inbox($store->read('feedback'),array(array('target'=>'201','state'=>'posted'),array('target'=>'201','state'=>'failed')))['201']),'Later failure cannot override successful send');
check(isset(Feedback::inbox(array(),array(array('target'=>'999','state'=>'uncertain','text'=>'orphan')))['999']),'Uncertain send without feedback record is still visible');
rejects(function()use($feedback,$conf,$v){$feedback->approve('201',$v,2,$conf);},'Repeated approval rejected');
rejects(function()use($feedback,$conf,$pending){$feedback->resolve('202',Feedback::draftVersion($pending,$conf),2,$conf);},'Pending analysis cannot be silently dismissed');
$feedback->resolve('203',Feedback::draftVersion($question,$conf),2,$conf);
check(!isset(Feedback::inbox($store->read('feedback'),array())['203']),'Handled question disappears');
$decline=$state;$store->write('feedback',$decline);$feedback->resolve('201',$v,2,$conf,true);
check($store->read('feedback')['items']['201']['review']['state']==='declined' && isset(Feedback::inbox($store->read('feedback'),array())['201']),'Declined unsent draft remains visible');
rejects(function()use($feedback,$conf,$pending){$feedback->resolve('202',Feedback::draftVersion($pending,$conf),2,$conf,true);},'Decline cannot skip pending analysis');
$feedback->resolve('203',Feedback::draftVersion($question,$conf),2,$conf);
check($store->read('history')===$history && $store->read('reply-history')===array() && !Policy::replyPublishingAllowed($store) && $store->read('api-budget')===array(),'Local review preserves histories, disabled policy and zero API usage');
define('CSRF_TOKEN','_glsectoken');
$model=array('base'=>'/admin/plugins/xpost/index.php','css'=>'style.css','token'=>'csrf','config'=>$conf,'message'=>'','error'=>'','search'=>'','selected'=>array(),'recent'=>array(),'articles'=>array(),'text'=>'','preview'=>'','history'=>array('root'=>$entry),'feedback'=>$state,'reply_history'=>array(),'reply_enabled'=>false);
$html=View::render($model);check(strpos($html,'<script>')===false && strpos($html,'&lt;script&gt;')!==false,'Untrusted reply text escaped');
check(strpos($html,'下書き承認待ち')<strpos($html,'質問・確認待ち') && strpos($html,'value="decline_draft"')!==false,'Approval drafts appear first with explicit decline action');
check(strpos($html,'下書きを承認')!==false && strpos($html,'name="draft_version"')!==false && strpos($html,'value="approve_draft"')!==false,'Draft offers one signed-version approval button');
check(strpos($html,'投稿履歴')===false && strpos($html,'1. 記事を選択')===false && strpos($html,'側へ移動しました')===false,'Long histories, numbering and migration notice removed');
$model['feedback']=$store->read('feedback');$html=View::render($model);check(strpos($html,'承認見送り・未送信')!==false && strpos($html,'質問です')===false,'Unsent declined draft remains while handled question is hidden');
$approvedState=$state;$approvedState['items']['201']['approval']=$approval;$model['feedback']=$approvedState;
$html=View::render($model);check(strpos($html,'承認済み・未送信')!==false && strpos($html,'disabled>送信無効')!==false && strpos($html,'value="reply_preview"')===false,'Approved draft stays visible with sending disabled');
$model['reply_enabled']=true;check(strpos(View::render($model),'value="reply_preview"')!==false,'Explicitly enabled policy exposes existing manual signed-confirmation path for approved draft');
$model['reply_history']=array(array('target'=>'201','state'=>'uncertain'));check(strpos(View::render($model),'value="reply_preview"')===false,'Unknown outcome never offers resend even with enabled policy');$model['reply_history']=array();$model['reply_enabled']=false;
foreach(array('failed','uncertain','sending')as$deliveryState){
    $model['reply_history']=array(array('target'=>'201','state'=>$deliveryState));$html=View::render($model);
    check(strpos($html,$deliveryState==='failed'?'送信失敗・未送信':($deliveryState==='sending'?'送信中・結果の確認待ち':'送信結果不明・要確認'))!==false
        && ($deliveryState==='failed'||strpos($html,'送信した返信文（結果未確認）')!==false),'Rendered delivery state '.$deliveryState.' is explicit and does not claim unknown replies are unsent');
    check(strpos($html,'value="approve_draft"')===false && strpos($html,'value="reply_preview"')===false,'No resend/approval control for delivery state '.$deliveryState.' while disabled');
}
$model['reply_history']=array(array('target'=>'201','state'=>'posted'));check(strpos(View::render($model),'承認済み・未送信')===false,'Posted item disappears in rendered screen');$model['reply_history']=array();
$retained=new Store($output.'/retained-'.bin2hex(random_bytes(4)));$retained->configure($conf);$retained->write('history',array('root'=>$entry));$retained->write('feedback',$approvedState);
$noCalls=new Happa\XPost\Client($conf,function(){throw new RuntimeException('Unexpected API call');},$retained);
(new Feedback($retained))->sync($noCalls,$conf,time()+92*86400);
check(isset(Feedback::inbox($retained->read('feedback'),array())['201']) && !isset($retained->read('feedback')['items']['201']['analysis']['draft']),'Approved unsent metadata survives retention without retaining expired raw draft text');
$model['feedback']=$retained->read('feedback');check(strpos(View::render($model),'本文の保管期限が過ぎています')!==false,'Expired unsent draft remains visible with recreation notice');
$retained->write('reply-history',array(array('target'=>'201','state'=>'posted')));(new Feedback($retained))->sync($noCalls,$conf,time()+93*86400);
check(!isset($retained->read('feedback')['items']['201']),'Confirmed sent approval can retire normally');
$model['feedback']['items']=array();for($i=300;$i<323;$i++)$model['feedback']['items'][(string)$i]=$question;
$html=View::render($model);check(substr_count($html,'class="xp-inbox-item"')===20 && strpos($html,'次へ')!==false,'Inbox pagination bounds visible entries without dropping older ones');
$model['inbox_page']=2;check(substr_count(View::render($model),'class="xp-inbox-item"')===3,'Second page exposes remaining actionable replies');
$fixture=$output.'/inbox-controller';$base=dirname(__DIR__);
foreach(array('/public_html/admin/plugins/xpost','/plugins/xpost/classes','/data')as$dir)if(!is_dir($fixture.$dir))mkdir($fixture.$dir,0750,true);
copy($base.'/public_html/admin/plugins/xpost/index.php',$fixture.'/public_html/admin/plugins/xpost/index.php');copy($base.'/public_html/admin/plugins/xpost/style.css',$fixture.'/public_html/admin/plugins/xpost/style.css');
copy(__DIR__.'/bootstrap-fixture.php',$fixture.'/public_html/base-fixture.php');copy(__DIR__.'/inbox-bootstrap-fixture.php',$fixture.'/public_html/lib-common.php');
foreach(array('Core.php','Automation.php','View.php','Articles.php')as$file)copy($base.'/plugins/xpost/classes/'.$file,$fixture.'/plugins/xpost/classes/'.$file);
$webStore=new Store($fixture.'/data/xpost');$webStore->configure($conf);$webStore->write('history',array('root'=>$entry));$webStore->write('reply-history',array());
foreach(array('inbox_csrf'=>false,'inbox_stale'=>false,'inbox_approve'=>true,'inbox_decline'=>false)as$case=>$ok){
    $webStore->write('feedback',$state);putenv('XPOST_CONTROLLER_CASE='.$case);
    $p=proc_open(array(PHP_BINARY,'-n',$fixture.'/public_html/admin/plugins/xpost/index.php'),array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes);
    fclose($pipes[0]);$html=stream_get_contents($pipes[1]);fclose($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[2]);$exit=proc_close($p);
    check($exit===0 && $errors==='' && !empty($webStore->read('feedback')['items']['201']['approval'])===$ok,'Controller '.$case.' only mutates approval after valid CSRF and version');
    check(!file_exists($fixture.'/data/xpost/api-budget.json') && $webStore->read('reply-history')===array() && $webStore->read('history')===array('root'=>$entry),'Controller '.$case.' never sends or changes publication history');
    if($case==='inbox_decline')check($webStore->read('feedback')['items']['201']['review']['state']==='declined','Decline controller records processed state without approval');
}
$webStore->write('feedback',$state);putenv('XPOST_CONTROLLER_CASE');
echo "TOTAL: $count inbox checks passed; no API calls\n";
