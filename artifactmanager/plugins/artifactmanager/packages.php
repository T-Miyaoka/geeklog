<?php
/**
 * Distribution folder helpers of the Artifact Manager: manifest reading and deletion of
 * packages whose publication has finished. Used by the admin page and the build tab.
 */
if (stripos($_SERVER['PHP_SELF'], basename(__FILE__)) !== false) {
    die('This file can not be used on its own.');
}

function am_target($dir, $name) { return $dir . DIRECTORY_SEPARATOR . basename(str_replace('\\', '/', (string) $name)); }
function am_read_manifest($path) {
    $raw = @file_get_contents($path);
    if (is_string($raw) && substr($raw, 0, 3) === "\xEF\xBB\xBF") $raw = substr($raw, 3);
    $m = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($m) || json_last_error() !== JSON_ERROR_NONE) throw new RuntimeException('manifest.jsonをJSONとして読み取れません。');
    if (isset($m['package']) && is_array($m['package'])) {
        foreach (array('version', 'channel') as $key) if (empty($m[$key])) throw new RuntimeException('manifest.jsonに必須項目 ' . $key . ' がありません。');
        foreach (array('fileName', 'sha256') as $key) if (empty($m['package'][$key])) throw new RuntimeException('manifest.jsonのpackageに必須項目 ' . $key . ' がありません。');
        return array('version'=>(string)$m['version'], 'channel'=>(string)$m['channel'], 'package'=>basename(str_replace('\\','/',(string)$m['package']['fileName'])), 'sha256'=>strtolower((string)$m['package']['sha256']), 'published_at'=>isset($m['timestampUtc'])?(string)$m['timestampUtc']:'');
    }
    foreach (array('version', 'packageUri', 'sha256') as $key) if (empty($m[$key])) throw new RuntimeException('manifest.jsonに必須項目 ' . $key . ' がありません。');
    return array('version'=>(string)$m['version'], 'channel'=>'stable', 'package'=>basename((string)parse_url((string)$m['packageUri'], PHP_URL_PATH)), 'sha256'=>strtolower((string)$m['sha256']), 'published_at'=>isset($m['publishedAtUtc'])?(string)$m['publishedAtUtc']:'');
}
function am_package_version($name) {
    return preg_match('/^AshwindChronicle-(.+?)(?:-win-x64)?\.zip$/i', (string) $name, $matches)
        ? (string) $matches[1]
        : '';
}
/** The manifest that decides what is published: manifest.json, or manifest.json.disabled while stopped. */
function am_current_manifest($dir) {
    foreach (array('manifest.json', 'manifest.json.disabled') as $name) {
        $path = am_target($dir, $name);
        if (is_file($path)) return am_read_manifest($path);
    }
    return null;
}
/**
 * Returns '' when the ZIP finished its publication (an older version than the current manifest and
 * not its target), otherwise why it must be kept. Packages awaiting publication are never deletable.
 */
function am_delete_blocker($name, $manifest) {
    if (!is_array($manifest)) return 'manifestがないため、公開を終えたか判定できません。';
    if ($name === $manifest['package']) return 'manifestの配信対象です。';
    $version = am_package_version($name);
    if (!preg_match('/^\d+(?:\.\d+){1,3}$/', $version)) return 'ファイル名からバージョンを判定できません。';
    if (version_compare($version, $manifest['version'], '>=')) return '公開中または公開待ちのバージョンです。';
    return '';
}
function am_remove_tree($path) {
    if (is_link($path) || is_file($path)) return @unlink($path);
    if (!is_dir($path)) return true;
    $ok = true;
    foreach ((array) @scandir($path) as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $ok = am_remove_tree($path . DIRECTORY_SEPARATOR . $entry) && $ok;
    }
    return @rmdir($path) && $ok;
}
/**
 * Deletes a finished package: the ZIP, its online installer (installer\<version>) and its staged
 * manifest (pending\<version>). Manifest backups in pending\history are kept.
 * Returns the list of problems with the companion folders (the ZIP itself throws).
 */
function am_delete_package($dir, $name, $manifest, array &$registry) {
    $name = basename(str_replace('\\', '/', (string) $name));
    if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'zip') throw new RuntimeException('ZIPファイルだけが削除の対象です。');
    $path = am_target($dir, $name);
    if (!is_file($path)) throw new RuntimeException('対象ファイルが見つかりません: ' . $name);
    $blocker = am_delete_blocker($name, $manifest);
    if ($blocker !== '') throw new RuntimeException($name . ' は削除できません。' . $blocker);
    if (!@unlink($path)) throw new RuntimeException($name . ' を削除できませんでした。ダウンロード中で使用されているか、ファイル権限が不足しています。');
    unset($registry[$dir . '|' . $name]);
    $problems = array();
    $version = am_package_version($name);
    foreach (array('installer', 'pending') as $folder) {
        $companion = $dir . DIRECTORY_SEPARATOR . $folder . DIRECTORY_SEPARATOR . $version;
        if (file_exists($companion) && !am_remove_tree($companion)) $problems[] = $folder . DIRECTORY_SEPARATOR . $version . ' の一部を削除できませんでした。';
    }
    return $problems;
}
