<?php
require_once '../../../lib-common.php';

if (!SEC_hasRights('artifactmanager.admin')) {
    COM_accessLog('Access denied to Artifact Manager for uid ' . (int) $_USER['uid']);
    echo COM_refresh($_CONF['site_url'] . '/index.php');
    exit;
}

$dataDir = rtrim($_CONF['path_data'], '/\\') . DIRECTORY_SEPARATOR . 'artifactmanager';
$registryPath = $dataDir . DIRECTORY_SEPARATOR . 'registry.json';
if (!is_dir($dataDir) && !@mkdir($dataDir, 0750, true)) COM_errorLog('Artifact Manager could not create: ' . $dataDir);

// Settings live in Geeklog's configuration (group "artifactmanager", plugin 1.2.0).
// Create missing items here as well, so the page works before the plugin upgrade is run.
if (!am_config_complete()) {
    require_once $_CONF['path'] . 'plugins/artifactmanager/install_defaults.php';
    if (!plugin_initconfig_artifactmanager()) COM_errorLog('Artifact Manager could not create its configuration group.');
}

function am_h($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function am_file_info($path, array $cached = array()) {
    $size=(int)@filesize($path); $mtime=(int)@filemtime($path);
    $same=isset($cached['size'],$cached['mtime'])&&(int)$cached['size']===$size&&(int)$cached['mtime']===$mtime;
    return array('filename'=>basename($path),'size'=>$size,'mtime'=>$mtime,'sha256'=>$same&&isset($cached['sha256'])?(string)$cached['sha256']:'','sha512'=>$same&&isset($cached['sha512'])?(string)$cached['sha512']:'');
}
function am_format_size($bytes) {
    $units=array('B','KB','MB','GB','TB'); $v=max(0,(float)$bytes); $i=0;
    while($v>=1024&&$i<count($units)-1){$v/=1024;$i++;}
    return number_format($v,$i===0?0:2).' '.$units[$i];
}
/** Button that opens this plugin's group in Geeklog's configuration screen (it selects groups by POST). */
function am_config_button($label = '設定（コンフィギュレーション）') {
    global $_CONF;
    return '<form method="post" action="'.am_h($_CONF['site_admin_url']).'/configuration.php" class="am-inline-form"><input type="hidden" name="conf_group" value="'.am_h(ARTIFACTMANAGER_CONFIG_GROUP).'"><button class="am-button secondary is-small" type="submit">'.am_h($label).'</button></form>';
}
require_once $_CONF['path'] . 'plugins/artifactmanager/packages.php';

$settings = am_settings();
$configError = '';
try { $distributionDir = am_normalize_dir($settings['distribution_dir']); }
catch (RuntimeException $e) { $distributionDir = ''; $configError = $e->getMessage(); }
$registry=am_load_json($registryPath); $message=''; $error='';
require_once $_CONF['path'].'plugins/artifactmanager/build.php';
$tab=(isset($_GET['tab'])&&$_GET['tab']==='build')?'build':'files';

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        if(!SEC_checkToken())throw new RuntimeException('セキュリティトークンが期限切れです。ページを再読み込みしてください。');
        if($distributionDir==='')throw new RuntimeException('配信元フォルダの設定が正しくありません: '.$configError);
        $action=isset($_POST['action'])?(string)$_POST['action']:'';
        if($action==='calculate_hash'){
            $name=basename(str_replace('\\','/',(string)$_POST['filename']));
            if(strtolower(pathinfo($name,PATHINFO_EXTENSION))!=='zip')throw new RuntimeException('ZIPファイルだけがハッシュ計算の対象です。');
            $path=am_target($distributionDir,$name); if(!is_file($path))throw new RuntimeException('対象ファイルが見つかりません。');
            $item=am_file_info($path); $item['sha256']=(string)hash_file('sha256',$path); $item['sha512']=(string)hash_file('sha512',$path);
            $registry[$distributionDir.'|'.$name]=$item; am_save_json($registryPath,$registry); $message='ハッシュを計算しました。';
        }elseif($action==='delete_package'||$action==='delete_finished'){
            $manifest=am_current_manifest($distributionDir);
            if($action==='delete_package'){
                $names=array(basename(str_replace('\\','/',isset($_POST['filename'])?(string)$_POST['filename']:'')));
            }else{
                $names=array();
                foreach((array)@scandir($distributionDir)as$name){
                    if(strtolower(pathinfo($name,PATHINFO_EXTENSION))==='zip'&&is_file(am_target($distributionDir,$name))&&am_delete_blocker($name,$manifest)==='')$names[]=$name;
                }
                if(!$names)throw new RuntimeException('公開を終えた配信ファイルはありません。');
            }
            $deleted=array();$problems=array();
            try{
                foreach($names as$name){$problems=array_merge($problems,am_delete_package($distributionDir,$name,$manifest,$registry));$deleted[]=$name;}
            }finally{
                if($deleted)am_save_json($registryPath,$registry);
            }
            $message=implode('、',$deleted).' を削除しました（インストーラーと公開待ちmanifestを含む）。';
            if($problems)$error=implode(' ',$problems);
        }elseif($action==='stop_update'){
            $from=am_target($distributionDir,'manifest.json'); $to=am_target($distributionDir,'manifest.json.disabled');
            if(!is_file($from)||file_exists($to)||!@rename($from,$to))throw new RuntimeException('自動更新を停止できませんでした。ファイル権限を確認してください。');
            $message='自動更新を停止しました。ZIPはそのまま保持されています。';
        }elseif($action==='resume_update'){
            $from=am_target($distributionDir,'manifest.json.disabled'); $to=am_target($distributionDir,'manifest.json');
            if(!is_file($from)||file_exists($to))throw new RuntimeException('再開可能なmanifest.json.disabledがありません。');
            $manifest=am_read_manifest($from); $packagePath=am_target($distributionDir,$manifest['package']);
            if(!is_file($packagePath))throw new RuntimeException('manifestが参照するZIPがありません: '.$manifest['package']);
            $actual=strtolower((string)hash_file('sha256',$packagePath));
            if(!hash_equals($manifest['sha256'],$actual))throw new RuntimeException('ZIPのSHA-256がmanifestと一致しないため、自動更新を再開しませんでした。');
            if(!@rename($from,$to))throw new RuntimeException('自動更新を再開できませんでした。ファイル権限を確認してください。');
            $message='ZIPとmanifestの整合性を確認し、自動更新を再開しました。';
        }elseif(strpos($action,'build_')===0){
            $tab='build';
            $message=amb_handle_post($action,$distributionDir);
        }
    }catch(Throwable $e){$error=$e->getMessage();COM_errorLog('Artifact Manager: '.$e->getMessage());}
}

$directoryReady=$distributionDir!==''&&is_dir($distributionDir)&&is_readable($distributionDir); $files=array();
if($directoryReady)foreach((array)@scandir($distributionDir)as$name){
    if($name==='.'||$name==='..'||strtolower(pathinfo($name,PATHINFO_EXTENSION))!=='zip')continue;
    $path=am_target($distributionDir,$name);if(!is_file($path))continue;$key=$distributionDir.'|'.$name;
    $files[$name]=am_file_info($path,isset($registry[$key])?$registry[$key]:array());
}
uasort($files, function (array $left, array $right) {
    $modifiedComparison = $right['mtime'] <=> $left['mtime'];
    if ($modifiedComparison !== 0) return $modifiedComparison;
    return strnatcasecmp($right['filename'], $left['filename']);
});
$manifestName='';
if($directoryReady)$manifestName=is_file(am_target($distributionDir,'manifest.json'))?'manifest.json':(is_file(am_target($distributionDir,'manifest.json.disabled'))?'manifest.json.disabled':'');
$manifest=null;if($manifestName!=='')try{$manifest=am_read_manifest(am_target($distributionDir,$manifestName));}catch(Throwable $e){$error=$error===''?$e->getMessage():$error;}
$latestPackageName=$files?array_key_first($files):'';$manifestWarning='';
if(is_array($manifest)){
    $manifestPackage=(string)$manifest['package'];$manifestVersion=(string)$manifest['version'];
    if(!isset($files[$manifestPackage])){
        $manifestWarning='manifestが参照するパッケージが配置フォルダにありません。配信対象: '.$manifestPackage;
    }elseif($latestPackageName!==''&&$manifestPackage!==$latestPackageName){
        $manifestWarning='最新の配置パッケージとmanifestの配信対象が一致していません。最新配置: '.$latestPackageName.' / manifest配信対象: '.$manifestPackage.' (version '.$manifestVersion.')';
    }else{
        $packageVersion=am_package_version($manifestPackage);
        if($packageVersion!==''&&$packageVersion!==$manifestVersion)$manifestWarning='manifestのversionと配信対象ファイル名のバージョンが一致していません。manifest: '.$manifestVersion.' / ファイル: '.$packageVersion;
    }
}

// The build worker deploys to the folder in its own config\server.json (set by Install-AshwindBuildServer.ps1).
$workerDeployDir='';
$serverConfig=am_load_json(am_build_path('config','server.json'));
if(!empty($serverConfig['deployDirectory']))$workerDeployDir=(string)$serverConfig['deployDirectory'];

$token=SEC_createToken();$base=am_h($_CONF['site_admin_url']).'/plugins/artifactmanager/index.php';
$hidden='<input type="hidden" name="'.CSRF_TOKEN.'" value="'.am_h($token).'">';
$content='<link rel="stylesheet" href="'.am_h($_CONF['site_admin_url']).'/plugins/artifactmanager/style.css?v=20261023-mobile-commit">';
$content.='<div class="am-shell"><section class="am-hero"><h1>Ashwind Chronicle 配信管理</h1><p>サーバーへ直接配置された配信資材を検出し、ファイル情報と公開状態を管理します。</p></section>';
$content.='<nav class="am-tabs"><a'.($tab==='files'?' class="is-active"':'').' href="'.$base.'">配信ファイル</a><a'.($tab==='build'?' class="is-active"':'').' href="'.$base.'?tab=build">ビルド</a><span class="am-tabs-tools">'.am_config_button().'</span></nav>';
if($message!=='')$content.='<div class="am-notice">'.am_h($message).'</div>';if($error!=='')$content.='<div class="am-notice is-error">'.am_h($error).'</div>';
if($configError!=='')$content.='<div class="am-notice is-error">配信元フォルダの設定が正しくありません: '.am_h($configError).' コンフィギュレーションで修正してください。</div>';
if($distributionDir!==''&&$workerDeployDir!==''&&am_path_key($workerDeployDir)!==am_path_key($distributionDir))$content.='<div class="am-notice is-warning"><strong>ビルドワーカーの配置先が異なります</strong><br>ビルドは '.am_h($workerDeployDir).' へ配置されます（config\\server.json）。配信元フォルダを変えた場合は、サーバーで Install-AshwindBuildServer.ps1 -DeployDirectory を実行して合わせてください。</div>';
    if($tab==='build'){
        try{$content.=amb_render($distributionDir,$token,$base);}
        catch(Throwable $e){COM_errorLog('Artifact Manager build tab: '.$e->getMessage());$content.='<div class="am-notice is-error">'.am_h($e->getMessage()).'</div>';}
    }
    elseif(!$directoryReady)$content.='<div class="am-empty">配信元フォルダを参照できません。コンフィギュレーションの配信元フォルダとアクセス権を確認してください。</div>';
    else{
        $active=is_array($manifest)?$manifest['package']:'';
        if($manifestWarning!=='')$content.='<div class="am-notice is-warning"><strong>配信定義の不一致</strong><br>'.am_h($manifestWarning).'</div>';
        $content.='<section class="am-service"><div><h2>自動更新サービス</h2>'.($manifestName==='manifest.json'?'<span class="am-badge published">公開中</span>':($manifestName==='manifest.json.disabled'?'<span class="am-badge stopped">停止中</span>':'<span class="am-badge legacy">manifest未配置</span>')).'</div>';
        if(is_array($manifest))$content.='<dl class="am-service-meta"><div><dt>manifestバージョン</dt><dd>'.am_h($manifest['version']).'</dd></div><div><dt>manifest配信対象</dt><dd>'.am_h($manifest['package']).'</dd></div><div><dt>最新配置</dt><dd>'.am_h($latestPackageName!==''?$latestPackageName:'なし').'</dd></div><div><dt>チャンネル</dt><dd>'.am_h($manifest['channel']).'</dd></div></dl>';
        $content.='<div class="am-actions">';
        if($manifestName==='manifest.json')$content.='<form method="post" onsubmit="return confirm(\'自動更新を停止しますか？ZIPは削除されません。\')"><input type="hidden" name="action" value="stop_update">'.$hidden.'<button class="am-button secondary" type="submit">自動更新を停止</button></form>';
        elseif($manifestName==='manifest.json.disabled')$content.='<form method="post"><input type="hidden" name="action" value="resume_update">'.$hidden.'<button class="am-button" type="submit">検証して自動更新を再開</button></form>';
        $content.='</div></section>';

        // Package list
        $finished=array();$finishedSize=0;
        foreach($files as$name=>$item)if(am_delete_blocker($name,$manifest)===''){$finished[]=$name;$finishedSize+=$item['size'];}
        // Show the newest packages up to the configured limit; the manifest's target is always listed.
        $limit=$settings['list_limit'];$shown=array_slice($files,0,$limit,true);
        if($active!==''&&isset($files[$active])&&!isset($shown[$active]))$shown[$active]=$files[$active];
        $content.='<section class="am-packages"><div class="am-section-head"><h2>配置済みパッケージ <span class="am-count">'.(count($shown)<count($files)?count($shown).' / '.count($files).'件':count($files).'件').'</span></h2>';
        if($finished)$content.='<form method="post" class="am-inline-form" onsubmit="return confirm(\'公開を終えた '.count($finished).' 件（'.am_h(am_format_size($finishedSize)).'）を削除しますか？インストーラーと公開待ちmanifestも削除され、これらのバージョンへは戻せなくなります。\')"><input type="hidden" name="action" value="delete_finished">'.$hidden.'<button class="am-button danger is-small" type="submit">公開を終えた '.count($finished).' 件を削除（'.am_h(am_format_size($finishedSize)).'）</button></form>';
        $content.='</div>';
        if(!$files)$content.='<div class="am-empty">ZIPファイルは配置されていません。</div>';
        else{
            $content.='<div class="am-table-wrap"><table class="am-table"><thead><tr><th>パッケージ</th><th>状態</th><th class="is-num">サイズ</th><th>更新日時</th><th>SHA-256</th><th class="is-ops">操作</th></tr></thead><tbody>';
            foreach($shown as$name=>$item){
                $isRef=is_array($manifest)&&$name===$active;$isActive=$isRef&&$manifestName==='manifest.json';
                $blocker=am_delete_blocker($name,$manifest);$isFinished=$blocker==='';
                $packageVersion=am_package_version($name);
                $isPending=!$isRef&&is_array($manifest)&&$packageVersion!==''&&version_compare($packageVersion,$manifest['version'],'>');
                if($isActive){$state='公開中';$badge='published';$note='manifest.json が参照している公開パッケージ';}
                elseif($isRef){$state='停止中';$badge='stopped';$note='自動更新停止中の配信対象';}
                elseif($isPending){$state='公開待ち';$badge='newest';$note='manifestより新しいバージョン';}
                elseif($isFinished){$state='公開終了';$badge='legacy';$note='公開を終えたパッケージ';}
                else{$state='対象外';$badge='legacy';$note=$blocker;}
                $hashState='';
                if($isRef&&$item['sha256']!=='')$hashState=hash_equals($manifest['sha256'],strtolower($item['sha256']))?'一致':'不一致';
                $hashCell=$item['sha256']!==''?'<code class="am-hash-short" title="'.am_h($item['sha256']).'">'.am_h(substr($item['sha256'],0,12)).'…</code>':'<span class="am-muted">未計算</span>';
                if($hashState!=='')$hashCell.=' <span class="am-check '.($hashState==='一致'?'is-ok':'is-ng').'">'.am_h($hashState).'</span>';
                $content.='<tr class="'.($isActive?'is-published':($isFinished?'is-finished':'')).'">'
                    .'<td data-label="パッケージ"><div class="am-file">'.am_h($name).'</div><div class="am-sub">'.am_h($note).'</div></td>'
                    .'<td data-label="状態"><span class="am-badges">'.($name===$latestPackageName?'<span class="am-badge newest">最新配置</span>':'').'<span class="am-badge '.$badge.'">'.am_h($state).'</span></span></td>'
                    .'<td data-label="サイズ" class="is-num">'.am_h(am_format_size($item['size'])).'</td>'
                    .'<td data-label="更新日時" class="is-date">'.am_h(date('Y-m-d H:i',$item['mtime'])).'</td>'
                    .'<td data-label="SHA-256">'.$hashCell.'</td>'
                    .'<td data-label="操作" class="is-ops"><div class="am-row-actions">'
                    .'<form method="post" class="am-inline-form"><input type="hidden" name="action" value="calculate_hash"><input type="hidden" name="filename" value="'.am_h($name).'">'.$hidden.'<button class="am-button secondary is-small" type="submit">'.($item['sha256']!==''?'再計算':'ハッシュ計算').'</button></form>';
                if($isFinished)$content.='<form method="post" class="am-inline-form" onsubmit="return confirm(\''.am_h($name).' を削除しますか？インストーラーと公開待ちmanifestも削除され、このバージョンへは戻せなくなります。\')"><input type="hidden" name="action" value="delete_package"><input type="hidden" name="filename" value="'.am_h($name).'">'.$hidden.'<button class="am-button danger is-small" type="submit">削除</button></form>';
                $content.='</div></td></tr>';
            }
            $content.='</tbody></table></div>';
            if(count($shown)<count($files))$content.='<p class="am-footnote">新しい順に '.count($shown).' 件を表示しています（全 '.count($files).' 件）。表示件数はコンフィギュレーションの「一覧の最大表示件数」で変更できます。</p>';
        }
        $content.='<p class="am-footnote">削除できるのは、manifest のバージョンより古く配信対象でない「公開終了」のパッケージだけです。削除するとそのバージョンのインストーラーと公開待ちmanifestも消えます（manifestの控え pending\\history は残ります）。</p></section>';
    }
$content.='</div>';echo COM_createHTMLDocument($content,array('pagetitle'=>'Ashwind配信管理'));
