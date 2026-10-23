# Ashwind Artifact Manager

Geeklog 2.2.2 の管理画面から、サーバー上に直接配置された Ashwind Chronicle の配信資材を管理するプラグインです。

- 配信元フォルダをスキャンし、配置済みZIPを一覧表（パッケージ・状態・サイズ・更新日時・SHA-256・操作）で表示
- ファイルサイズ、更新日時、SHA-256 / SHA-512 の参照・再計算
- 新旧2種類のmanifest形式を読み取り、配信対象ZIPとバージョンを表示
- `manifest.json` の安全なリネームによる自動更新サービスの停止・再開
- 再開前にmanifestとZIPのSHA-256整合性を検証
- 公開を終えた配信ファイルの削除（1件ずつ、または「公開を終えた n 件を削除」でまとめて）

`manifest.json` は独立した配信物として登録解除しません。一覧では参照先ZIPの行を「公開中」として示します。

### 公開を終えた配信ファイルの削除

削除できるのは、現在の manifest（`manifest.json`、停止中は `manifest.json.disabled`）のバージョンより古く、
その配信対象でないZIP（状態「公開終了」）だけです。公開中・停止中の配信対象、manifest より新しい公開待ちのZIP、
ファイル名からバージョンを判定できないZIP、manifest がない時は削除できません（サーバー側でも再判定します）。
削除時は、同じバージョンのオンラインインストーラー `installer\<version>\` と公開待ちmanifest `pending\<version>\` も削除します。
manifest の控え `pending\history` は残します。削除したバージョンへは戻せなくなります。

## 設定（コンフィギュレーション）

設定は Geeklog の管理画面「コンフィギュレーション > Ashwind配信管理」で変更します（1.2.0 以降。配信管理画面の
「コンフィギュレーションで変更」ボタンからも開けます）。保存時に入力を検証します。

| 項目 | 内容 |
|---|---|
| 配信元フォルダ | 配信ZIPと manifest を置くサーバー上の絶対パス（存在するフォルダ） |
| 署名鍵の配置場所 | public_html の外の `.pem`。画面は鍵を読みません |
| 取得するブランチ | サーバーでのビルドが GitHub から取得するブランチ |
| ダウンロード登録カテゴリ | 公開時にセットアップを登録するダウンロードプラグインのカテゴリ |
| 公開時に「ダウンロード一覧に登録」を最初から選ぶ | 公開ボタン横のチェックの初期値 |

ビルド関連の設定は、保存時に `ashwind-build\settings\build-settings.json`（ビルドワーカーが読む）へ反映します。
ビルドワーカーの配置先は `ashwind-build\config\server.json` の `deployDirectory` です。配信元フォルダを変えた場合は
サーバーで `Install-AshwindBuildServer.ps1 -DeployDirectory <フォルダ>` を実行して合わせてください（異なる間は画面に警告を出します）。
1.1.0 からの更新時は、`build-settings.json` の値をコンフィギュレーションの初期値として引き継ぎます。
ハッシュキャッシュは `data/artifactmanager/registry.json` に保存します。Web画面から大容量ファイルをアップロードする機能はありません。

## ビルドタブ（サーバーでのビルドと公開）

「ビルド」タブから、Web サーバー上でのビルドを依頼し、公開します。処理本体は
`tools/ashwind-build-server` のタスク（`\Task\Ashwind-Build-Queue`）で、この画面は依頼と設定の書き込みだけを行います。

- **ビルドして配置**: バージョンと「このビルドで対応したこと」を入力して依頼します。タスクが GitHub の
  設定ブランチを取得し、ZIP とオンラインインストーラーを配信フォルダへ配置し、署名済み manifest を
  `pending\<version>\manifest.json` に置きます（公開されません）。進捗・ログ・取消を表示します。
- **公開する**: 公開待ちの manifest と ZIP の SHA-256 を照合してから `manifest.json` を置き換えます。
  置き換え前の manifest は `pending\history` に残します。同時に、セットアップを ZIP に包んでダウンロード一覧へ
  登録できます（ダウンロードプラグインは exe を配信しないため）。登録だけ失敗した場合は後から再試行できます。
- **ビルド設定**: 現在の設定（署名鍵の配置場所、取得ブランチ、ダウンロード登録カテゴリ）を表示します。変更は
  コンフィギュレーションで行います。画面は鍵を読まず、タスクのアカウントが読めるかどうかをワーカー状態欄に表示します。

ファイル: `public_html/admin/plugins/artifactmanager/index.php`（画面）、`plugins/artifactmanager/build.php`（ビルドタブの処理）、
`plugins/artifactmanager/packages.php`（manifest読取と削除）、`install_defaults.php` / `configuration_validation.php`（コンフィギュレーション）。
テスト: `php tests/upgrade.php`、`php tests/settings.php`、`php tests/packages.php`。

