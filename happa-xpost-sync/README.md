# Happa X-Post 返信の定期取り込み

X の開発者ブログ告知に付いた返信を、Web サーバーのタスクスケジューラーで定期的に取り込む CLI です。タスクは毎時 40 分に起動し、
プラグインの判定（前回の取り込みから 8 時間＝28800 秒たったときだけ取り込み、それ以外は `not_due` で X へ通信しない）に従って、実際の取り込みは約 8 時間おきになります。
X-Post プラグイン（`plugins/xpost`）の取り込み処理（`xpost/feedback-cli.php --sync` と同じ `Feedback::sync`）をそのまま呼び、
一時的な通信失敗だけを再試行します。X への投稿・返信の下書き作成・返信の送信はしません。`public_html` の外に置きます。

## なぜ必要か

README の「定期処理 `happa`（8 時間ごと）」に当たる仕組みが動いておらず、2026-10-25 15:31 以降は手動で流したときしか返信が取り込まれていなかった。
2026-10-26 10:00 の手動の取り込みは `X APIとのTLS接続に失敗しました。（cURL 35）` で失敗したが、同じ PHP・証明書での接続確認と
13:23 の再実行は成功しており、一時的な失敗だった。取り込みは 1 回の失敗で全体が止まるため、ここで再試行する。

## サーバー上の配置

- 配置先: `C:\inetpub\wwwroot\geeklog\2_2_2\happa-xpost-sync`（開発 PC からは `Y:\geeklog\2_2_2\happa-xpost-sync`）
- PHP CLI: `C:\php-8.5.12\php.exe`（`curl` 拡張。GA4 レポートと同じ）
- 使うもの: `plugins\xpost\classes\Core.php`（プラグイン本体）と、非公開の `data\xpost`（`credentials.json`・`feedback.json` など）
- タスク定義: `Happa-XPost-Sync.xml`（毎時 40 分、止まっていた場合は起動後すぐ実行、上限 30 分）。8 時間ちょうどの間隔で起動すると、前回の記録が
  数秒遅れただけでプラグインが `not_due` と判定して 1 回分飛ばすため、起動は 1 時間ごとにして取り込みの間隔はプラグインに任せる。
- ログ: `logs\xpost-sync-<日付>.log`、直近の結果: `last-run.json`

## 初回の設定

1. `run-xpost-sync.php`、`xpost-sync.inc.php`、`Happa-XPost-Sync.xml` を配置先にコピーする。
2. タスクの実行アカウントには、`data\xpost` の読み書きと、配置先の `logs`・`last-run.json` の書き込み権限が要る。
   GA4 レポートと同じアカウント（`data` を書ける Web 管理用アカウント）を使う。
3. 実行アカウントで `C:\php-8.5.12\php.exe C:\inetpub\wwwroot\geeklog\2_2_2\happa-xpost-sync\run-xpost-sync.php --status` を実行し、
   予算と未対応件数の JSON が出ることを確かめる（X へは通信しない）。
4. `Happa-XPost-Sync.xml` をタスクスケジューラーにインポートし、実行アカウントと「ユーザーがログオンしているかどうかにかかわらず実行する」を選ぶ。
   XML にアカウントやパスワードは入っていない。XML は UTF-16 LE（BOM 付き）のまま扱う。

## 再試行

次の失敗だけ、60 秒後と 180 秒後に再試行する（最大 3 回）。プラグインは取得途中のページ位置を保存するので、再試行は続きから取得する。

- 通信の失敗: cURL 5（プロキシの名前解決）、6（名前解決）、7（接続）、28（タイムアウト）、35（TLS 接続）、52（空応答）、56（受信エラー）
- X の HTTP 429（利用制限）と 5xx

認証（401）、権限（403）、利用枠（402）、証明書の検証（cURL 60）、予算上限、不完全な応答は再試行せず、そのまま失敗として記録する。
取り込みの結果は `last-run.json` の `status`（`ok` / `failed`）、`attempts`、`result`（`status` が `synced` なら `new_items`・`pages`・`incomplete_roots`、
8 時間たっていなければ `not_due`）、`pending` で確かめる。

## テスト

リポジトリのルートから `php -n happa-xpost-sync/tests/sync.php`。ネットワークには出ず、プラグインの代わりのスタンドインで再試行の判定・結果の記録を確かめる。
