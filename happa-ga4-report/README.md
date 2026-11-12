# Happa GA4 記事レポート

開発者ブログのテーマ選びの参考にするため、Google アナリティクス（GA4）から記事別の閲覧数と滞在時間を毎日 06:00 に取得し、
JSON に保存する CLI です。Windows のタスクスケジューラーで実行します。`public_html` の外に置き、Geeklog の設定やデータベースには触れません。
読み取り専用のスコープ（`analytics.readonly`）だけを使い、GA4 の設定は変更しません。

## サーバー上の配置

- 配置先: `C:\inetpub\wwwroot\geeklog\2_2_2\happa-ga4-report`（開発 PC からは `Y:\geeklog\2_2_2\happa-ga4-report`）
- PHP CLI: `C:\php-8.5.12\php.exe`（`openssl`、`curl`、`json` 拡張が必要）
- タスク定義: `Happa-GA4-Report.xml`（毎日 06:00。止まっていた場合は起動後すぐ実行。上限 30 分）
- 出力: `data\latest.json`（最新）、`data\history\<集計日>.json`（既定 90 日分）、`data\last-run.json`（直近の実行結果）
- ログ: `logs\ga4-<日付>.log`

## 初回の設定

1. `run-ga4-report.php`、`ga4-report.inc.php`、`Happa-GA4-Report.xml`、`config.example.ini` を配置先にコピーする。
2. 配置先に `credentials` フォルダを作り、`config.example.ini` を `credentials\config.ini` としてコピーして `property_id`（数値のプロパティ ID）を入れる。
3. サービスアカウント `happa-ga4-reader` の JSON 鍵を `credentials\happa-ga4-reader.json` に置く。鍵はリポジトリや記事パッケージに入れない。
4. `credentials` フォルダは NTFS ACL で、タスクの実行アカウントと管理者だけが読めるようにする。実行アカウントには `data` と `logs` の変更権限が要る。
5. GA4 の「管理 > プロパティのアクセス管理」で、`happa-ga4-reader` のサービスアカウントのメールアドレスに「閲覧者」を付ける。
   Google Cloud 側でそのプロジェクトの **Google Analytics Data API** が有効になっていること。
6. 実行アカウントで `C:\php-8.5.12\php.exe C:\inetpub\wwwroot\geeklog\2_2_2\happa-ga4-report\run-ga4-report.php --check` を実行し、`OK:` が出ることを確かめる（何も保存しない）。
7. `Happa-GA4-Report.xml` をタスクスケジューラーにインポートし、実行アカウントと「ユーザーがログオンしているかどうかにかかわらず実行する」を選ぶ。
   XML にアカウントやパスワードは入っていない。XML は UTF-16 LE（BOM 付き）のまま扱う。

## 取得内容と出力

GA4 Data API の `runReport` を、次の 4 期間それぞれについて呼ぶ。すべて実行日の前日（最後の確定日）までの範囲。

| キー | 期間 |
|---|---|
| `yesterday` | 前日 |
| `last_7_days` | 直近 7 日 |
| `prev_7_days` | その前の 7 日（伸び・落ち込みの比較用） |
| `last_28_days` | 直近 28 日 |

ディメンションは `pagePathPlusQueryString`、`pageTitle`、指標は `screenPageViews`、`activeUsers`、`userEngagementDuration`。
加えて、同じ期間・ディメンションで `engagedSessions` を、直近 7 日について流入元・端末・国の内訳を取得する（下記）。
`article.php?story=<sid>`（または `article.php/<sid>`）の記事ページだけを対象にし、同じ記事の URL の違い（`fbclid` など）は合算する。

`data\latest.json` の形:

```json
{
  "schema_version": 1,
  "generated_at": "2026-10-26T06:00:12+09:00",
  "report_date": "2026-10-25",
  "ranges": { "yesterday": { "start": "2026-10-25", "end": "2026-10-25" }, "last_7_days": { "...": "..." } },
  "totals": { "last_7_days": { "views": 57, "active_users": 45, "avg_engagement_seconds": 60.1 } },
  "articles": [
    {
      "sid": "devblog-059-calendar-weather-preview_ja",
      "language": "ja",
      "title": "DEVELOPMENT SNAPSHOT #59 — ...",
      "url": "https://www.happa.bz/article.php?story=devblog-059-calendar-weather-preview_ja",
      "yesterday":    { "views": 10, "active_users": 8,  "avg_engagement_seconds": 80.0 },
      "last_7_days":  { "views": 45, "active_users": 35, "avg_engagement_seconds": 71.4 },
      "prev_7_days":  { "views": 0,  "active_users": 0,  "avg_engagement_seconds": 0.0 },
      "last_28_days": { "views": 45, "active_users": 35, "avg_engagement_seconds": 71.4 },
      "views_change_7_days": 45
    }
  ]
}
```

- `articles` は直近 7 日のエンゲージしたセッション数（`engaged_sessions`）の多い順、同数なら閲覧数の多い順（2026-11-12 から）。`avg_engagement_seconds` はアクティブユーザー 1 人あたりのエンゲージメント時間（GA4 の「平均エンゲージメント時間」）で、URL の違いを合算しているため概算。
- 書き込みは一時ファイルからの置き換えなので、読み手が書きかけのファイルを見ることはない。
- 失敗したときは `latest.json` を前回のまま残し、`last-run.json` に `status: failed` と `error_code` を書いて終了コード 1 で終わる。
  読み手は `generated_at`／`report_date` が当日・前日でなければ古いデータとして扱う。
- 一時的な失敗（通信エラー、HTTP 429・5xx）は 30 秒、90 秒の間隔で 2 回まで再試行する。

### 人とボットを見分けるための項目（2026-11-12）

2026-11-02 に計測を直してサイトマップに全記事を載せてから、全記事に「1 人 1 回・滞在 0 秒」の閲覧が均等に付くようになった
（クローラーやリンクプレビューが JavaScript を実行して計測される）。閲覧数だけでは読者の関心が分からないため、次を加えた。

- 各期間の `engaged_sessions`: その記事を含むセッションのうち、GA4 がエンゲージしたと判定したもの（10 秒以上、キーイベント、
  2 ページ以上のいずれか）。記事ごと（`articles[].<期間>`）と合計（`totals.<期間>`）。題材選びにはこちらを使う。
- `totals.<期間>.not_found_views`: 存在しない記事 ID（`404 Error` の題名）への閲覧。記事一覧と合計からは除く。
- `breakdowns`: 直近 7 日の記事ページ（404 を除く）を `source_medium`（`sessionSourceMedium`）、`device`（`deviceCategory`）、
  `country` で分けた上位 15 件。各項目は `value`、`views`、`active_users`、`engaged_sessions`、`avg_engagement_seconds`。
- `enrichment`: 上の追加の取得の結果（`status` が `ok` か `partial`、`errors` に失敗した項目とエラーコード）。
  追加の取得が API に拒否されても、閲覧数などの本体は従来どおり保存し、`engaged_sessions` と該当の `breakdowns` は `null` になる。
  一時的な失敗（429・5xx、通信エラー）は本体と同じく全体を再試行する。
- `--check` は追加の取得も 1 件ずつ試し、拒否されたものを `WARN:` で示す（日次の実行は止めない）。

## エラーコード

| コード | 意味と対処 |
|---|---|
| `CONFIG_MISSING` / `CONFIG_UNREADABLE` / `CONFIG_INVALID` | `credentials\config.ini` が無い・読めない・不正。`property_id` は数値のプロパティ ID |
| `KEY_MISSING` / `KEY_UNREADABLE` / `KEY_INVALID` | 鍵ファイルが無い・読めない・サービスアカウント鍵でない |
| `AUTH_FAILED` | トークン発行の拒否。鍵が無効化・削除されていないか確認 |
| `PERMISSION_DENIED` | サービスアカウントにプロパティの閲覧権限が無い、または `property_id` の誤り |
| `API_ERROR` / `NETWORK_ERROR` | API またはネットワークのエラー（再試行後も失敗） |
| `CURL_MISSING` | PHP の curl 拡張が無効 |

ログとエラーには鍵、トークン、鍵ファイルの中身を出さない。

## テスト

リポジトリのルートから `php -n happa-ga4-report/tests/report.php`。ネットワークには出ず、Google の API を模した応答で、署名、集計、エラー処理、出力を確かめる。
