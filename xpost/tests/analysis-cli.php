<?php
require_once __DIR__ . '/../plugins/xpost/classes/Core.php';
$root = getenv('XPOST_TEST_DIR'); if (!$root) { throw new RuntimeException('Set XPOST_TEST_DIR'); }
$fixture = $root . '/analysis-cli-' . bin2hex(random_bytes(4)); mkdir($fixture, 0750, true);
$repo = $fixture . '/repo'; mkdir($repo); mkdir($repo . '/AshwindChronicle'); mkdir($repo . '/AshwindChronicle/Doc');
function run(array $args) {
    $p = proc_open($args, array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')), $pipes);
    fclose($pipes[0]); $out=stream_get_contents($pipes[1]);fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);
    return array(proc_close($p),$out,$err);
}
foreach(array(array('init'),array('config','user.name','Fixture'),array('config','user.email','fixture@example.invalid')) as $args) {
    if(run(array_merge(array('git','-C',$repo),$args))[0]!==0){throw new RuntimeException('Fixture git initialization failed');}
}
$md="| ID | 優先度 | 仕様変更 | 改善項目名 | 課題内容 | 推奨対策 |\n|---|---|---|---|---|---|\n| EVT-044 | 中 | | Test | Test | Test |\n";
file_put_contents($repo.'/AshwindChronicle/Doc/改善項目一覧.md',$md);
run(array('git','-C',$repo,'add','.'));run(array('git','-C',$repo,'commit','-m','fixture'));
$commit=trim(run(array('git','-C',$repo,'rev-parse','HEAD'))[1]);
$store=new Happa\XPost\Store($fixture.'/data');$store->write('feedback',array('items'=>array('123'=>array('status'=>'pending'))));
$input=array('id'=>'123','analysis'=>array('status'=>'registered','summary'=>'test','draft'=>'ありがとうございます','backlog_id'=>'EVT-044','commit'=>$commit));
$file=$fixture.'/analysis.json';
$cli=function($data)use($input,$file,$fixture,$repo){file_put_contents($file,json_encode($data));return run(array(PHP_BINARY,'-n',__DIR__.'/../feedback-cli.php','--data='.$fixture.'/data','--repo='.$repo,'--analysis='.$file));};
$bad=$input;$bad['analysis']['commit']=str_repeat('0',40);if($cli($bad)[0]===0){throw new RuntimeException('Unknown commit accepted');}echo "PASS: analysis CLI rejects unknown commits\n";
$bad=$input;$bad['analysis']['backlog_id']='EVT-999';if($cli($bad)[0]===0){throw new RuntimeException('Unknown backlog accepted');}echo "PASS: analysis CLI rejects absent backlog ID\n";
if($cli($input)[0]!==0){throw new RuntimeException('Verified analysis rejected');}echo "PASS: analysis CLI verifies committed six-column backlog before recording\n";
if($cli($input)[0]===0){throw new RuntimeException('Duplicate analysis accepted');}echo "PASS: analysis CLI rejects repeat processing\n";
echo "TOTAL: 4 analysis CLI checks passed; no X requests\n";
