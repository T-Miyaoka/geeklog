<?php
if (stripos($_SERVER['PHP_SELF'], basename(__FILE__)) !== false) {
    die('This file can not be used on its own.');
}

$LANG_ARTIFACTMANAGER = array(
    'no_download_registration' => '（登録しない）',
);

// Geeklog configuration screen (コンフィギュレーション > Ashwind配信管理)
$LANG_configsections['artifactmanager'] = array(
    'label' => 'Ashwind配信管理',
    'title' => 'Ashwind配信管理の設定',
);

$LANG_confignames['artifactmanager'] = array(
    'distribution_dir' => '配信元フォルダ',
    'signing_key_path' => '署名鍵の配置場所（.pem）',
    'branch' => '取得するブランチ',
    'download_category' => 'ダウンロード登録カテゴリ',
    'register_download' => '公開時に「ダウンロード一覧に登録」を最初から選ぶ',
    'list_limit' => '一覧の最大表示件数（パッケージ・ビルド履歴）',
);

$LANG_configsubgroups['artifactmanager'] = array(
    'sg_main' => '主な設定',
);

$LANG_tab['artifactmanager'] = array(
    'tab_main' => '配信とビルド',
);

$LANG_fs['artifactmanager'] = array(
    'fs_distribution' => '配信ファイル',
    'fs_build' => 'サーバーでのビルド',
    'fs_display' => '表示',
);

$LANG_configselects['artifactmanager'] = array(
    0 => array('はい' => 1, 'いいえ' => 0),
    1 => array('はい' => true, 'いいえ' => false),
);
