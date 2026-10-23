<?php
require_once __DIR__ . '/../plugins/xpost/classes/Core.php';
require_once __DIR__ . '/../plugins/xpost/classes/View.php';
use Happa\XPost\Client;
use Happa\XPost\Store;
use Happa\XPost\Replies;
use Happa\XPost\View;
$root = getenv('XPOST_TEST_DIR');
if (!$root) { throw new RuntimeException('Set XPOST_TEST_DIR'); }
if (!is_dir($root)) { mkdir($root, 0750, true); }
$count = 0;
function check($ok, $label) { global $count; if (!$ok) { throw new RuntimeException($label); } ++$count; echo "PASS: $label\n"; }
function rejects($fn, $label) { try { $fn(); } catch (Throwable $e) { check(true, $label); return; } check(false, $label); }
$config = array('username'=>'__MeeSan__','api_key'=>'key','api_secret'=>'secret','access_token'=>'token','access_secret'=>'secret2');
$data = array('data'=>array(
    array('id'=>'101','text'=>"<script>alert(1)</script> 日本語の長い返信です。\n次の行",'author_id'=>'9','created_at'=>'2026-10-21T00:00:00Z', 'conversation_id'=>'100','referenced_tweets'=>array(array('type'=>'replied_to','id'=>'100'))),
    array('id'=>'102','text'=>'English nested reply','author_id'=>'10','created_at'=>'2026-10-21T00:01:00Z','conversation_id'=>'100','referenced_tweets'=>array(array('type'=>'replied_to','id'=>'101')))),
    'includes'=>array('users'=>array(array('id'=>'9','name'=>'開発者 <&>','username'=>'reader'))),
    'meta'=>array('result_count'=>2,'next_token'=>'next+page/='));
$calls = array();
$client = new Client($config, function($method,$url,$headers,$body) use (&$calls,$data) {
    $calls[] = array($method,$url,$body); return array('status'=>200,'body'=>json_encode($data));
});
$result = $client->replies('100');
check(count($result['items'])===2 && $result['items'][0]['direct'] && !$result['items'][1]['direct'], 'Direct and nested replies distinguished');
check($result['items'][1]['author']==='ユーザー情報未取得', 'Missing author expansion is explicit');
$client->replies('100',$result['next_token']);
parse_str(parse_url($calls[1][1],PHP_URL_QUERY),$query);
check($query['query']==='conversation_id:100 is:reply' && $query['next_token']==='next+page/=' && $query['max_results']==='100', 'Pagination and query encoded');
check(count($calls)===2 && $calls[0][0]==='GET' && $calls[1][0]==='GET' && $calls[0][2]===null, 'Read-only requests, no automatic pagination');
// Fixed independent HMAC calculation, including decoded query parameters in normalization.
$url='https://api.x.com/2/tweets/search/recent?query=conversation_id%3A100%20is%3Areply&max_results=100';
$params=array('oauth_consumer_key'=>'key','oauth_nonce'=>'nonce','oauth_signature_method'=>'HMAC-SHA1','oauth_timestamp'=>'123','oauth_token'=>'token','oauth_version'=>'1.0','query'=>'conversation_id:100 is:reply','max_results'=>'100');
ksort($params,SORT_STRING); $pairs=array(); foreach($params as $k=>$v){$pairs[]=rawurlencode($k).'='.rawurlencode($v);}
$expected=base64_encode(hash_hmac('sha1','GET&'.rawurlencode('https://api.x.com/2/tweets/search/recent').'&'.rawurlencode(implode('&',$pairs)),'secret&secret2',true));
$header=Client::authorization('GET',$url,$config,'nonce','123');
check(strpos($header,'oauth_signature="'.rawurlencode($expected).'"')!==false && strpos($header,'query=')===false,'GET query participates in OAuth signature but not header');
$empty = new Client($config,function(){return array('status'=>200,'body'=>'{"meta":{"result_count":0}}');});
check($empty->replies('100')['items']===array(),'Valid zero-result envelope supported');
foreach(array('{"data":[],"errors":[{"detail":"secret"}],"meta":{"result_count":0}}','{}','{"meta":{"result_count":2}}') as $raw){
    $bad=new Client($config,function()use($raw){return array('status'=>200,'body'=>$raw);});
    rejects(function()use($bad){$bad->replies('100');},'Incomplete response does not become zero replies');
}
foreach(array(401,402,403,429,500) as $status){
    $bad=new Client($config,function()use($status){return array('status'=>$status,'body'=>'{"detail":"DO_NOT_DISPLAY_SECRET"}');});
    try{$bad->replies('100');check(false,'HTTP rejected');}catch(Happa\XPost\ApiException $e){check(!$e->uncertain && strpos($e->getMessage(),'DO_NOT_DISPLAY_SECRET')===false,'Read failure '.$status.' remains explicit and redacted');}
}
rejects(function()use($client){$client->replies('100&query=other');},'Arbitrary post input rejected');
rejects(function()use($client){$client->replies('100',"bad\ntoken");},'Invalid page token rejected');
$store=new Store($root.'/reply-fixture-'.bin2hex(random_bytes(4)));
$entry=array('state'=>'posted','post_id'=>'100','username'=>'__MeeSan__','sids'=>array('example'),'text'=>'Example','at'=>'2026-10-21','url'=>'https://x.com/__MeeSan__/status/100');
$store->write('history',array('saved'=>$entry)); $before=$store->read('history');
check(Replies::target($store,'saved',$config)==='100','Target resolved from server history');
rejects(function()use($store,$config){Replies::target($store,'absent',$config);},'Unknown target rejected');
foreach(array('failed','sending','uncertain') as $state){$e=$entry;$e['state']=$state;$store->write('history',array('saved'=>$e));rejects(function()use($store,$config){Replies::target($store,'saved',$config);},'Nonposted '.$state.' rejected');}
$e=$entry;$e['username']='another';$store->write('history',array('saved'=>$e));rejects(function()use($store,$config){Replies::target($store,'saved',$config);},'Changed account rejected');
$store->write('history',$before);
define('CSRF_TOKEN','_glsectoken');
$model=array('base'=>'https://www.happa.bz/admin/plugins/xpost/index.php','css'=>'style.css','token'=>'fixture-csrf','config'=>$config,'message'=>'','error'=>'','search'=>'','selected'=>array(),'recent'=>array(),'articles'=>array(),'text'=>'','preview'=>'','history'=>$before);
$result['key']='saved';$result['post_id']='100';$model['replies']=$result;
$html=View::render($model);
check(strpos($html,'<script>')===false && strpos($html,'&lt;script&gt;')!==false && strpos($html,'告知への直接返信')!==false,'Reply HTML escaped and labels displayed');
check(strpos($html,'次の100件を取得')!==false && strpos($html,'全期間の総数ではありません')!==false,'Pagination and limited coverage visible');
check($store->read('history')===$before,'Reply lookup leaves posting history unchanged');
file_put_contents($root.'/replies.html',$html);
$model['replies']['items']=array();$model['replies']['next_token']='';file_put_contents($root.'/replies-empty.html',View::render($model));
$model['replies']=null;$model['error']='X APIの利用制限です。時間をおいてください。（HTTP 429）';file_put_contents($root.'/replies-error.html',View::render($model));
echo 'TOTAL: '.$count." reply checks passed; no live X requests\n";
