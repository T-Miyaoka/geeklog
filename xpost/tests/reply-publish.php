<?php
require_once __DIR__ . '/../plugins/xpost/classes/Core.php';
require_once __DIR__ . '/../plugins/xpost/classes/View.php';
use Happa\XPost\Store;
use Happa\XPost\Client;
use Happa\XPost\ReplyPublisher;
use Happa\XPost\View;
$root = getenv('XPOST_TEST_DIR');
if (!$root) { throw new RuntimeException('Set XPOST_TEST_DIR'); }
if (!is_dir($root)) { mkdir($root, 0750, true); }
$count=0;
function check($ok,$label){global $count;if(!$ok){throw new RuntimeException($label);}++$count;echo "PASS: $label\n";}
function rejects($fn,$label){try{$fn();}catch(Throwable $e){check(true,$label);return;}check(false,$label);}
$config=array('username'=>'__MeeSan__','api_key'=>'key','api_secret'=>'secret','access_token'=>'token','access_secret'=>'secret2');
$text='ご提案ありがとうございます！🌾 不作と蟲による減収の内訳表示を、改善項目 EVT-044 に登録しました🛠️';
$calls=array();$status=201;$malformed=false;$wrongRoot=false;$wrongAccount=false;$failSave=false;
$client=new Client($config,function($method,$url,$headers,$body)use(&$calls,&$status,&$malformed,&$wrongRoot,&$wrongAccount){
 $calls[]=array($method,$url,$body);
 if($method==='POST'){return array('status'=>$status,'body'=>$malformed?'{}':json_encode(array('data'=>array('id'=>'300','text'=>'posted'))));}
 return array('status'=>200,'body'=>json_encode(array('data'=>strpos($url,'users/me')!==false
  ?array('id'=>'1','username'=>$wrongAccount?'other':'__MeeSan__')
  :array('id'=>'200','conversation_id'=>$wrongRoot?'999':'100','text'=>'提案 <script>内容</script>'))));
});
function fixture(){global $root; $store=new Store($root.'/reply-publish-'.bin2hex(random_bytes(4)));$store->write('reply-policy',array('user_enabled'=>true,'x_approval_reference'=>'isolated-test-only'));$store->write('history',array('saved'=>array('state'=>'posted','post_id'=>'100','username'=>'__MeeSan__','sids'=>array('example'),'text'=>'Article','at'=>'2026-10-21','url'=>'')));return $store;}
$store=fixture();$before=$store->read('history');
$preview=ReplyPublisher::preview($store,'saved','200',$text,2,$config,$client);
check(count($calls)===1 && $calls[0][0]==='GET','Preview verifies real target with GET only');
check(ReplyPublisher::verify($store,$preview['signed'],2,$config)['text']===$text,'Exact approved emoji text survives signing');
rejects(function()use($store,$preview,$config){ReplyPublisher::verify($store,'X'.$preview['signed'],2,$config);},'Tampered preview blocked');
rejects(function()use($store,$preview,$config){ReplyPublisher::verify($store,$preview['signed'],3,$config);},'Wrong user blocked');
$changed=$config;$changed['api_key']='changed';rejects(function()use($store,$preview,$changed){ReplyPublisher::verify($store,$preview['signed'],2,$changed);},'Changed credentials blocked');
$expired=$preview;$expired['expires']=time()-1;unset($expired['signed']);$encoded=base64_encode(json_encode($expired));$expiredToken=$encoded.'.'.hash_hmac('sha256',$encoded,$store->read('reply-preview-key')['key']);
rejects(function()use($store,$expiredToken,$config){ReplyPublisher::verify($store,$expiredToken,2,$config);},'Expired preview blocked');
foreach(array('',str_repeat('あ',141),"bad\x00text")as$bad){rejects(function()use($bad){ReplyPublisher::validate($bad);},'Invalid reply text blocked');}
$result=ReplyPublisher::publish($store,$preview['signed'],2,$config,$client);
check($result['state']==='posted' && $result['url']==='https://x.com/__MeeSan__/status/300','Reply success persisted');
$posts=array_values(array_filter($calls,function($c){return $c[0]==='POST';}));$payload=json_decode($posts[0][2],true);
check(count($posts)===1 && $payload===array('text'=>$text,'reply'=>array('in_reply_to_tweet_id'=>'200')),'One exact reply sent to comment, not root announcement');
check($store->read('history')===$before,'Article announcement history untouched');
$n=count($calls);rejects(function()use($store,$preview,$config,$client){ReplyPublisher::publish($store,$preview['signed'],2,$config,$client);},'Duplicate reply blocked');check(count($calls)===$n,'Duplicate makes zero network requests');
foreach(array(401,403,429,500,408)as$http){$store=fixture();$preview=ReplyPublisher::preview($store,'saved','200',$text,2,$config,$client);$status=$http;
 rejects(function()use($store,$preview,$config,$client){ReplyPublisher::publish($store,$preview['signed'],2,$config,$client);},'HTTP '.$http.' not success');
 $history=$store->read('reply-history');$state=reset($history)['state'];check($state===($http>=500||$http===408?'uncertain':'failed'),'Durable HTTP '.$http.' state');
 if($state==='uncertain'){$n=count($calls);rejects(function()use($store,$preview,$config,$client){ReplyPublisher::publish($store,$preview['signed'],2,$config,$client);},'Uncertain result cannot resend');check(count($calls)===$n,'Uncertain retry zero API calls');}
}$status=201;
$store=fixture();$preview=ReplyPublisher::preview($store,'saved','200',$text,2,$config,$client);$malformed=true;
rejects(function()use($store,$preview,$config,$client){ReplyPublisher::publish($store,$preview['signed'],2,$config,$client);},'Malformed success remains uncertain');check(reset($store->read('reply-history'))['state']==='uncertain','Malformed success durable protection');$malformed=false;
$store=fixture();$wrongRoot=true;rejects(function()use($store,$text,$config,$client){ReplyPublisher::preview($store,'saved','200',$text,2,$config,$client);},'Comment from other conversation blocked');$wrongRoot=false;
$preview=ReplyPublisher::preview($store,'saved','200',$text,2,$config,$client);$wrongAccount=true;
rejects(function()use($store,$preview,$config,$client){ReplyPublisher::publish($store,$preview['signed'],2,$config,$client);},'API account mismatch blocks reply');check($store->read('reply-history')===array(),'Wrong account leaves no sending marker');$wrongAccount=false;
$store->write('reply-history',array('crash'=>array('username'=>'__MeeSan__','target'=>'200','state'=>'sending')));$n=count($calls);
rejects(function()use($store,$preview,$config,$client){ReplyPublisher::publish($store,$preview['signed'],2,$config,$client);},'Crashed sending marker blocks reply');check(count($calls)===$n,'Crash retry makes zero requests');
define('CSRF_TOKEN','_glsectoken');$preview['target_text']='<script>target</script>';
$model=array('base'=>'https://www.happa.bz/admin/plugins/xpost/index.php','css'=>'style.css','token'=>'csrf','config'=>$config,'message'=>'','error'=>'','search'=>'','selected'=>array(),'recent'=>array(),'articles'=>array(),'text'=>'','preview'=>'','history'=>array(),'reply_preview'=>$preview,'reply_history'=>array($result));
$html=View::render($model);check(strpos($html,'<script>')===false && strpos($html,'&lt;script&gt;target')!==false,'Reply confirmation and history escaped');
check(strpos($html,'返信をXに公開する')!==false && strpos($html,'公開済み返信を開く')===false,'Explicit reply confirmation remains available without showing completed reply history');
file_put_contents($root.'/reply-confirmation.html',$html);
echo 'TOTAL: '.$count." reply publishing checks passed; no live posts\n";
