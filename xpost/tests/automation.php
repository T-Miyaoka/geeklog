<?php
require_once __DIR__ . '/../plugins/xpost/classes/Core.php';
require_once __DIR__ . '/../plugins/xpost/classes/View.php';
use Happa\XPost\Store;
use Happa\XPost\Client;
use Happa\XPost\Budget;
use Happa\XPost\Feedback;
use Happa\XPost\Policy;
use Happa\XPost\ReplyPublisher;
$root = getenv('XPOST_TEST_DIR'); if (!$root) { throw new RuntimeException('Set XPOST_TEST_DIR'); }
if (!is_dir($root)) { mkdir($root, 0750, true); }
$count = 0;
function check($ok, $label) { global $count; if (!$ok) { throw new RuntimeException($label); } ++$count; echo "PASS: $label\n"; }
function rejects($fn, $label) { try { $fn(); } catch (Throwable $e) { check(true, $label); return; } check(false, $label); }
function fixture() { global $root; return new Store($root . '/automation-' . bin2hex(random_bytes(4))); }
$config = array('username'=>'__MeeSan__','api_key'=>'key','api_secret'=>'secret','access_token'=>'token','access_secret'=>'secret2');
$store = fixture(); check(!Policy::replyPublishingAllowed($store), 'Replies disabled without X approval');
$store->write('reply-policy',array('user_enabled'=>true));check(!Policy::replyPublishingAllowed($store),'User enablement alone cannot allow reply publishing');
$calls=0;$client=new Client($config,function()use(&$calls){++$calls;return array('status'=>200,'body'=>'{"data":{"id":"1","username":"__MeeSan__"}}');},$store);
rejects(function()use($store,$client,$config){ReplyPublisher::publish($store,'invalid',2,$config,$client);},'Disabled reply publish rejected before any API call');check($calls===0,'Disabled reply publish performs zero network calls');
$now=time();rejects(function()use($now){Policy::announcementDue(array(array('state'=>'posted','at'=>gmdate('c',$now-86399))),$now);},'Announcement cooldown 24h');
Policy::announcementDue(array(array('state'=>'posted','at'=>gmdate('c',$now-86400))),$now);check(true,'Announcement due at exact 24h');
rejects(function(){Policy::announcementDue(array(array('state'=>'posted','at'=>'invalid')));},'Unknown publication time fails closed');
check(Policy::announcementIntervalHours()===24,'Interval defaults to 24h without Geeklog configuration');
foreach(array(array(6,6),array('12',12),array(0,0),array(720,720),array(48.0,48),array(-1,24),array(721,24),array('abc',24),array('',24),array(1.5,24),array(true,24),array(array(),24)) as $c){check(Policy::announcementIntervalHours($c[0])===$c[1],'Interval '.json_encode($c[0]).' resolves to '.$c[1].'h');}
$six=array(array('state'=>'posted','at'=>gmdate('c',$now-6*3600)));
Policy::announcementDue($six,$now,6);check(true,'Configured 6h interval allows announcement at exactly 6h');
try{Policy::announcementDue($six,$now+0,'7');check(false,'Configured 7h interval rejects at 6h');}catch(RuntimeException $e){check(strpos($e->getMessage(),'7時間')!==false,'Rejection message names the configured 7h interval');}
rejects(function()use($now){Policy::announcementDue(array(array('state'=>'sending','at'=>gmdate('c',$now-3599))),$now,1);},'Configured interval still counts sending records');
rejects(function()use($now){Policy::announcementDue(array(array('state'=>'uncertain','at'=>gmdate('c',$now-60))),$now,'bad');},'Invalid configured interval falls back to 24h guard');
Policy::announcementDue(array(array('state'=>'posted','at'=>gmdate('c',$now-60)),array('state'=>'uncertain','at'=>'invalid')),$now,0);check(true,'Interval 0 disables only the time check');
$r=Budget::reserve($store,'GET','/2/tweets/search/recent?max_results=10',null);check(Budget::state($store)['reserved']===50,'Worst-case search cost reserved before network');
Budget::settle($store,$r,'GET','/2/tweets/search/recent?max_results=10',null,array('meta'=>array('result_count'=>0)));
check(Budget::state($store)['spent']===0 && Budget::state($store)['reserved']===0,'Successful empty search costs no post resources');
$r=Budget::reserve($store,'GET','/2/tweets/search/recent?max_results=10&expansions=author_id',null);check($r['amount']===150,'Author expansions included in upper bound');
Budget::settle($store,$r,'GET','/2/tweets/search/recent?max_results=10&expansions=author_id',null,array('data'=>array(array('id'=>'2')),'includes'=>array('users'=>array(array('id'=>'3'))),'meta'=>array('result_count'=>1)));
check(Budget::state($store)['spent']===15,'Post plus user resources charged');
$r=Budget::reserve($store,'POST','/2/tweets',array('text'=>'https://www.happa.bz/'));check($r['amount']===200,'URL announcement write cost included');
Budget::settle($store,$r,'POST','/2/tweets',null,array('data'=>array('id'=>'9')));
$failed=Budget::reserve($store,'GET','/2/users/me',null);check(Budget::state($store)['reserved']===10,'Uncertain request reservation retained');
$month=gmdate('Y-m');$store->write('api-budget',array($month=>array('spent'=>19995,'pending'=>array(),'started_at'=>'test')));
rejects(function()use($client){$client->identity();},'Budget refuses request that would cross 20USD');check($calls===0,'Budget denial causes zero network calls');
check(Budget::state($store)['warning'],'80 percent warning surfaced');
$store->write('api-budget',array($month=>array('spent'=>19990,'pending'=>array('first'=>10),'started_at'=>'test')));
rejects(function()use($store){Budget::reserve($store,'GET','/2/users/me',null);},'Other pending requests count against the ceiling');
$store->write('api-budget',array($month=>array('spent'=>20000,'pending'=>array(),'started_at'=>'test')));check(Budget::state($store)['blocked'],'20USD boundary stops processing');
$store->write('api-budget',array($month=>array('spent'=>'corrupt','pending'=>array(),'started_at'=>'test')));rejects(function()use($store){Budget::state($store);},'Corrupt ledger cannot reset spend');
rejects(function()use($store){Budget::reserve($store,'GET','/undefined',null);},'Undefined tariff fails closed');
$store=fixture();$entry=array('state'=>'posted','post_id'=>'100','username'=>'__MeeSan__','user_id'=>'1','at'=>gmdate('c',$now-3600));$store->write('history',array('root'=>$entry));
$feedback=new Feedback($store);$historyBefore=$store->read('history');$pages=array();$failPage=false;
$transport=function($method,$url)use(&$pages,&$failPage){
 $pages[]=$url;parse_str(parse_url($url,PHP_URL_QUERY),$q);
 if($failPage&&isset($q['next_token'])){return array('status'=>429,'body'=>'{}');}
 $second=isset($q['next_token']);$data=$second
 ?array(array('id'=>'202','text'=>'nested','author_id'=>'8','created_at'=>'2026-10-21T00:00:01Z','conversation_id'=>'100','referenced_tweets'=>array(array('type'=>'replied_to','id'=>'201'))))
 :array(array('id'=>'201','text'=>'要望 <script>untrusted</script>','author_id'=>'7','created_at'=>'2026-10-21T00:00:00Z','conversation_id'=>'100','referenced_tweets'=>array(array('type'=>'replied_to','id'=>'100'))),array('id'=>'203','text'=>'own reply','author_id'=>'1','created_at'=>'2026-10-21T00:00:02Z','conversation_id'=>'100','referenced_tweets'=>array(array('type'=>'replied_to','id'=>'201'))));
 return array('status'=>200,'body'=>json_encode(array('data'=>$data,'meta'=>$second?array('result_count'=>1):array('result_count'=>2,'next_token'=>'page2'))));
};
$client=new Client($config,$transport,$store);$failPage=true;
rejects(function()use($feedback,$client,$config,$now){$feedback->sync($client,$config,$now);},'Page failure is explicit');
$state=$store->read('feedback');check($state['roots']['100']['since']==='' && $state['roots']['100']['next']==='page2','Checkpoint does not advance on unfinished page');
check(count($feedback->pending())===1,'Received page saved and own reply excluded');
$failPage=false;$feedback->sync($client,$config,$now);$state=$store->read('feedback');
check(count($feedback->pending())===2 && $state['roots']['100']['since']==='203' && $state['roots']['100']['next']==='','Successful resume completes checkpoint and stores replies once');
parse_str(parse_url(end($pages),PHP_URL_QUERY),$q);check($q['max_results']==='10' && !isset($q['expansions']) && $q['next_token']==='page2','Automation uses small pages without user expansion');
$n=count($pages);check($feedback->sync($client,$config,$now+28799)['status']==='not_due' && count($pages)===$n,'8h cooldown performs zero network calls');
$feedback->sync($client,$config,$now+28800);parse_str(parse_url($pages[$n],PHP_URL_QUERY),$q);check($q['since_id']==='203' && !isset($q['start_time'], $q['end_time']) && isset($q['until_id']),'Next 8h run uses committed since_id with ID-only bounds');
$expectedUntil=(string)(($now+28800-30)*1000-1288834974657);
check($q['until_id']===(string)(((int)$expectedUntil)*4194304),'Exclusive upper ID is first Snowflake at saved end timestamp');
check(count($feedback->pending())===2,'Repeated data never requeues analyzed IDs');
$feedback->record('201',array('status'=>'duplicate','summary'=>'既存項目','draft'=>'ご提案ありがとうございます🌾','backlog_id'=>'EVT-044','commit'=>'c56b1149'));
check(count($feedback->pending())===1,'Analysis state durable and queue reduced');
$store->write('reply-history',array('already'=>array('target'=>'202','state'=>'posted')));
rejects(function()use($feedback){$feedback->record('202',array('status'=>'reaction','summary'=>'thanks','draft'=>'ありがとうございます'));},'No duplicate thank-you draft for a published reply');
$store->write('reply-history',array());
rejects(function()use($feedback){$feedback->record('202',array('status'=>'question','summary'=>'question','draft'=>'thank you'));},'Question cannot receive automatic thank-you draft');
rejects(function()use($feedback){$feedback->record('201',array('status'=>'reaction','summary'=>'again','draft'=>''));},'Analyzed comment cannot be processed twice');
rejects(function()use($feedback){$feedback->record('202',array('status'=>'registered','summary'=>'not saved','draft'=>'ありがとう'));},'Registered claim requires backlog ID and commit');
check($store->read('history')===$historyBefore && $store->read('reply-history')===array(),'Sync and analysis never mutate announcement/reply publication histories');
$feedback->sync($client,$config,$now+32*86400);$state=$store->read('feedback');check(!isset($state['items']['201']['source']['text']) && !isset($state['items']['202']['source']['text']),'30-day raw text retention applies even to pending comments');
$feedback->sync($client,$config,$now+92*86400);check($store->read('feedback')['items']===array(),'90-day processed-ID retention bounded');
$pageStore=fixture();$pageStore->write('history',array('root'=>$entry));$pageFeedback=new Feedback($pageStore);
$pageClient=new Client($config,function(){return array('status'=>200,'body'=>'{"meta":{"result_count":0,"next_token":"more"}}');},$pageStore);
$partial=$pageFeedback->sync($pageClient,$config,$now);check($partial['status']==='partial' && $partial['pages']===12 && $partial['incomplete_roots']===1,'Page cap explicitly reports incomplete sync');
check($pageStore->read('feedback')['roots']['100']['since']==='' && $pageStore->read('feedback')['roots']['100']['next']==='more','Page cap preserves continuation without advancing since_id');

// Regression: ID-mode failures must retain both the fixed boundary and every checkpoint.
$idStore=fixture();$idStore->write('history',array('root'=>$entry));
$idStore->write('feedback',array('roots'=>array('100'=>array('since'=>'200','checked'=>$now-28800,'next'=>'','reconciled'=>$now)), 'items'=>array(), 'last_sync'=>0));
$idFeedback=new Feedback($idStore);$pages=array();$failPage=true;$idClient=new Client($config,$transport,$idStore);
rejects(function()use($idFeedback,$idClient,$config,$now){$idFeedback->sync($idClient,$config,$now);},'ID-mode page failure stays explicit');
$idState=$idStore->read('feedback');
check($idState['roots']['100']['since']==='200' && $idState['roots']['100']['next']==='page2','ID-mode failed continuation preserves since and next token');
$fixedEnd=$idState['roots']['100']['end'];parse_str(parse_url($pages[0],PHP_URL_QUERY),$firstIdQuery);
check(isset($firstIdQuery['since_id'],$firstIdQuery['until_id']) && !isset($firstIdQuery['start_time']) && !isset($firstIdQuery['end_time']),'ID request never mixes time parameters');
$failPage=false;$idFeedback->sync($idClient,$config,$now+120);parse_str(parse_url(end($pages),PHP_URL_QUERY),$resumedIdQuery);
check($resumedIdQuery['until_id']===$firstIdQuery['until_id'] && $resumedIdQuery['since_id']==='200' && $resumedIdQuery['next_token']==='page2','ID resume reuses exact original lower/upper bounds and token');
check($idStore->read('feedback')['roots']['100']['end']===$fixedEnd && count($idFeedback->pending())===2,'ID resume retains saved end and deduplicates replies');
check(Budget::state($idStore)['reserved']===50 && Budget::state($idStore)['spent']===15,'Failed page reservation retained while successful pages settle actual resources');
$callCount=count($pages);$budgetBefore=Budget::state($idStore);
foreach(array(array('since_id'=>'200','end_time'=>$fixedEnd),array('until_id'=>'300','start_time'=>$fixedEnd),array('since_id'=>'200','start_time'=>$fixedEnd),array('until_id'=>'300','end_time'=>$fixedEnd)) as $mixed){
 rejects(function()use($idClient,$mixed){$idClient->replies('100','',$mixed);},'Mixed ID/time bounds rejected locally');
}
check(count($pages)===$callCount && Budget::state($idStore)===$budgetBefore,'Invalid mixed bounds consume neither network nor budget reservation');
define('CSRF_TOKEN','_glsectoken');
$model=array('base'=>'https://www.happa.bz/admin/plugins/xpost/index.php','css'=>'style.css','token'=>'csrf','config'=>$config,'message'=>'','error'=>'','search'=>'','selected'=>array(),'recent'=>array(),'articles'=>array(),'text'=>'','preview'=>'','history'=>array(),
 'budget'=>array('month'=>gmdate('Y-m'),'spent'=>16000,'reserved'=>50,'warning'=>true,'blocked'=>false),'reply_enabled'=>false,
 'reply_preview'=>array('target'=>'200','target_text'=>'<script>target</script>','text'=>'Thanks','signed'=>'fixture'),
 'feedback'=>array('items'=>array('200'=>array('status'=>'registered','analysis'=>array('summary'=>'<script>proposal</script>','draft'=>'ご提案ありがとうございます！🌾 EVT-044に登録しました。','backlog_id'=>'EVT-044')))));
$html=Happa\XPost\View::render($model);
$dom=new DOMDocument();libxml_use_internal_errors(true);$dom->loadHTML('<?xml encoding="UTF-8">'.$html);$xpath=new DOMXPath($dom);
check($xpath->query('//header//div[@id="automation-status"]')->length===0 && strpos($html,'ブログ告知: 24時間に1回')===false && strpos($html,'Developer Consoleで別途')===false,'Header excludes automation, contract and usage-status explanations');
check(strpos($html,'<button type="submit">返信をXに公開する</button>')===false,'Live policy still hides reply publication button');
check(strpos($html,'<script>')===false && strpos($html,'お礼の下書き（未送信）')!==false && strpos($html,'承認済みでも未送信なら一覧に残り')!==false,'Feedback is escaped and header distinguishes approval from delivery');
file_put_contents($root.'/automation-view.html',$html);
echo 'TOTAL: '.$count." automation checks passed; no live requests\n";
