<?php
require __DIR__ . '/bootstrap.php';
require dirname(__DIR__) . '/plugins/artifactmanager/functions.inc';
require dirname(__DIR__) . '/plugins/artifactmanager/packages.php';
$dir = $amTestRoot . '/dist';
foreach (array('0.1.9', '0.1.13', '0.1.14', '0.1.15', '0.1.16') as $v) {
    file_put_contents("$dir/AshwindChronicle-$v-win-x64.zip", "zip $v");
    mkdir("$dir/installer/$v", 0777, true); file_put_contents("$dir/installer/$v/AshwindChronicle-Setup-$v.exe", 'exe');
    mkdir("$dir/pending/$v", 0777, true); file_put_contents("$dir/pending/$v/manifest.json", '{}');
}
mkdir("$dir/pending/history", 0777, true); file_put_contents("$dir/pending/history/manifest-0.1.14.json", '{}');
file_put_contents("$dir/AshwindChronicle-custom.zip", 'x');
file_put_contents("$dir/manifest.json", json_encode(array('version' => '0.1.15', 'channel' => 'stable', 'package' => array('fileName' => 'AshwindChronicle-0.1.15-win-x64.zip', 'sha256' => str_repeat('a', 64)))));

$manifest = am_current_manifest($dir);
check($manifest['version'] === '0.1.15', 'manifest read');
check(am_delete_blocker('AshwindChronicle-0.1.14-win-x64.zip', $manifest) === '', 'older version is finished');
check(am_delete_blocker('AshwindChronicle-0.1.9-win-x64.zip', $manifest) === '', 'version compared numerically (0.1.9 < 0.1.15)');
check(am_delete_blocker('AshwindChronicle-0.1.15-win-x64.zip', $manifest) !== '', 'published package kept');
check(am_delete_blocker('AshwindChronicle-0.1.16-win-x64.zip', $manifest) !== '', 'package awaiting publication kept');
check(am_delete_blocker('AshwindChronicle-custom.zip', $manifest) !== '', 'unknown version kept');
check(am_delete_blocker('AshwindChronicle-0.1.14-win-x64.zip', null) !== '', 'nothing deletable without a manifest');

$registry = array("$dir|AshwindChronicle-0.1.14-win-x64.zip" => array('sha256' => 'x'), "$dir|AshwindChronicle-0.1.15-win-x64.zip" => array('sha256' => 'y'));
$problems = am_delete_package($dir, 'AshwindChronicle-0.1.14-win-x64.zip', $manifest, $registry);
check($problems === array() && !file_exists("$dir/AshwindChronicle-0.1.14-win-x64.zip"), 'finished ZIP deleted');
check(!file_exists("$dir/installer/0.1.14") && !file_exists("$dir/pending/0.1.14"), 'its installer and staged manifest deleted');
check(is_file("$dir/pending/history/manifest-0.1.14.json"), 'manifest backups kept');
check(!isset($registry["$dir|AshwindChronicle-0.1.14-win-x64.zip"]) && isset($registry["$dir|AshwindChronicle-0.1.15-win-x64.zip"]), 'hash cache entry removed');
check(is_dir("$dir/installer/0.1.13") && is_file("$dir/AshwindChronicle-0.1.13-win-x64.zip"), 'other versions untouched');

foreach (array('AshwindChronicle-0.1.15-win-x64.zip', 'AshwindChronicle-0.1.16-win-x64.zip', 'AshwindChronicle-custom.zip', '../site/x.zip', 'manifest.json') as $name) {
    $threw = false;
    try { am_delete_package($dir, $name, $manifest, $registry); } catch (RuntimeException $e) { $threw = true; }
    check($threw, "refused: $name");
}
check(is_file("$dir/AshwindChronicle-0.1.15-win-x64.zip") && is_dir("$dir/installer/0.1.15") && is_dir("$dir/pending/0.1.16") && is_file("$dir/manifest.json"), 'refused deletions left everything in place');

// While the update service is stopped, manifest.json.disabled still decides what is published.
rename("$dir/manifest.json", "$dir/manifest.json.disabled");
$manifest = am_current_manifest($dir);
check($manifest['version'] === '0.1.15' && am_delete_blocker('AshwindChronicle-0.1.15-win-x64.zip', $manifest) !== '', 'stopped manifest still protects its package');
