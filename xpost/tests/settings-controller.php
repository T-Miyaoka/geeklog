<?php
require_once __DIR__ . '/../plugins/xpost/classes/Core.php';
use Happa\XPost\Store;
$output=getenv('XPOST_TEST_DIR');if(!$output){throw new RuntimeException('Set XPOST_TEST_DIR');}
$fixture=$output.'/settings-'.bin2hex(random_bytes(4));$base=dirname(__DIR__);
foreach(array('/public_html/admin/plugins/xpost','/plugins/xpost/classes','/data')as$dir){mkdir($fixture.$dir,0750,true);}
copy($base.'/public_html/admin/plugins/xpost/settings.php',$fixture.'/public_html/admin/plugins/xpost/settings.php');
copy(__DIR__.'/bootstrap-fixture.php',$fixture.'/public_html/base-fixture.php');
copy(__DIR__.'/settings-bootstrap-fixture.php',$fixture.'/public_html/lib-common.php');
copy($base.'/plugins/xpost/install_defaults.php',$fixture.'/plugins/xpost/install_defaults.php');
foreach(array('Core.php','Automation.php','View.php')as$file){copy($base.'/plugins/xpost/classes/'.$file,$fixture.'/plugins/xpost/classes/'.$file);}
$store=new Store($fixture.'/data/xpost');$config=array('username'=>'__MeeSan__','api_key'=>'fixture-key','api_secret'=>'fixture-secret','access_token'=>'fixture-token','access_secret'=>'fixture-token-secret');
$cases=array('anonymous'=>'Root管理者のログインが必要','get'=>'','blank'=>'API設定を保存しました','settings'=>'API設定を保存しました',
 'csrf'=>'セキュリティトークンが期限切れ','check_csrf'=>'セキュリティトークンが期限切れ','invalid'=>'Xアカウント名を正しく','array'=>'入力値の形式','unknown'=>'操作を選択','register'=>'設定入口を登録しました',
 'embedded_get'=>'X API設定','embedded_save'=>'API設定を保存しました','embedded_csrf'=>'セキュリティトークンが期限切れ');
foreach($cases as$case=>$expected){
 $store->write('credentials',$config);$before=hash_file('sha256',$fixture.'/data/xpost/credentials.json');putenv('XPOST_CONTROLLER_CASE='.$case);
 $p=proc_open(array(PHP_BINARY,'-n',$fixture.'/public_html/admin/plugins/xpost/settings.php'),array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes);
 fclose($pipes[0]);$html=stream_get_contents($pipes[1]);fclose($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[2]);$exit=proc_close($p);
 if($exit!==0||$error!==''||($expected!=='' && strpos($html,$expected)===false)||strpos($html,'fixture-secret')!==false||strpos($html,'fixture-token')!==false){throw new RuntimeException('Settings case failed: '.$case.' / '.$error);}
 if($case==='get' && $html!==''){throw new RuntimeException('Legacy standalone page still rendered');}
 if(strpos($case,'embedded_')===0 && (strpos($html,'?embedded=1')===false || strpos($html,'class="xp-header"')!==false)){throw new RuntimeException('Embedded form lost inline context');}
 if(hash_file('sha256',$fixture.'/data/xpost/credentials.json')!==$before){throw new RuntimeException('Existing credentials changed: '.$case);}
 if(file_exists($fixture.'/data/xpost/history.json')||file_exists($fixture.'/data/xpost/api-budget.json')){throw new RuntimeException('Unexpected post or API call: '.$case);}
 echo 'PASS: settings controller '.$case."; credential bytes unchanged\n";
}
echo 'TOTAL: '.count($cases)." dedicated settings cases passed; no X calls\n";
