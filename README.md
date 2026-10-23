# Happa Geeklog（www.happa.bz）のカスタマイズ

Happa サイト（Geeklog 2.2.2）で使っている独自プラグインと、Geeklog 本体・既存プラグインへの変更を
プラグインごとのディレクトリで管理します。各ディレクトリの中はサイトのフォルダ構成と同じ並びです。

| ディレクトリ | 内容 | サーバー上の配置先 |
|---|---|---|
| `artifactmanager/` | Ashwind Chronicle 配信管理（配信ファイル、ビルド依頼・公開、署名鍵設定） | `plugins/artifactmanager/`、`public_html/admin/plugins/artifactmanager/` |
| `backlog/` | Backlog（作業履歴チケット） | `plugins/backlog/`、`public_html/admin/plugins/backlog/` |
| `downloads/` | Downloads プラグインの変更（公開ページ・登録画面） | `plugins/downloads/`、`public_html/downloads/`、`public_html/admin/plugins/downloads/` |
| `xpost/` | X 自動投稿 | `plugins/xpost/`、`public_html/admin/plugins/xpost/` |
| `happa-article-inbox/` | 記事・Backlog 取り込み（CLI、タスクスケジューラーで実行） | `<site>\happa-article-inbox\`（public_html の外） |

- サイトのルートは `C:\inetpub\wwwroot\geeklog\2_2_2`（開発 PC からは共有 `Y:\geeklog\2_2_2`）。
- `main` はサーバーで動いているものと一致させる。サーバーへ配置したら、同じ内容を `main` へ入れる。
- 認証情報・データ・ログ（`credentials/`、`data/`、`logs/` など）はリポジトリへ入れない。
- 各ディレクトリの `tests/` はそのディレクトリをカレントにせず、リポジトリのルートから
  `php -n <ディレクトリ>/tests/<ファイル>.php` のように実行する。

Ashwind Chronicle のビルドキュー（Web サーバー上でビルドするタスク）は、ゲーム本体のビルド手順と一体のため
`T-Miyaoka/AshwindChronicle` の `tools/ashwind-build-server` に置いている。

## 取り込み元

2026-10-23 に `T-Miyaoka/AshwindChronicle` の `tools/` から移した。どれもサーバーの現行ファイルと一致することを確認済み。

| ディレクトリ | 取り込み元 |
|---|---|
| `artifactmanager/` | `tools/geeklog-artifactmanager` @ `claude/am-mobile-commit-cell` (0493d05f) |
| `backlog/` | `tools/geeklog-backlog` @ `main` (ddec3f76) |
| `downloads/` | `tools/geeklog-downloads` @ `main` (ddec3f76) |
| `xpost/` | `tools/geeklog-xpost` @ `claude/xpost-announcement-interval` (9651adb2) |
| `happa-article-inbox/` | `tools/happa-article-inbox-importer` @ `claude/article-frontpage` (a0cc2fc9) |
