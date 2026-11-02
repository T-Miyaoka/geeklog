# Geeklog 本体への変更

Geeklog 2.2.2 本体のファイルのうち、Happa サイトで変更しているものを置きます。中の並びはサイトのフォルダ構成と同じです。
置いてあるファイルは、サーバーで動いているファイルそのもの（Happa の変更を含む全体）です。

| ファイル | サーバー上の配置先 | Happa の変更 |
|---|---|---|
| `public_html/index.php` | `public_html/index.php` | 旧 `Blog` トピックから言語別トピック（`Blog_ja`/`Blog_en`）への転送、トップページの SEO ヘッダー（`happa_buildSeoHeader`）、記事が 1 本もないトピック一覧への `noindex` |
| `public_html/robots.txt` | `public_html/robots.txt` | 末尾に `Disallow: /profiles.php`（「記事をメールで送る」等、ほぼボットだけが来る）と `Disallow: /comment.php` を追加（2026-11-02） |
| `public_html/layout/denim_three/index.thtml` | `public_html/layout/denim_three/index.thtml` | `<head>` 先頭で GA4 のタグ `happa-analytics.js` を読む |
| `public_html/web.config` | `public_html/web.config` | IIS の書き換え規則と応答ヘッダー（CSP など）。2026-11-02 に CSP の `connect-src` へ GA4 の送信先を追加（下記） |
| `public_html/javascript/happa-analytics.js` | `public_html/javascript/happa-analytics.js` | Happa 独自のファイル。GA4 のタグ（下記） |

## 空のトピック一覧の noindex（2026-10-26）

記事もセンターブロックも無いトピック一覧（`Products`、`Calculator` など）はトピック名だけの空のページになり、
Google Search Console で「ソフト 404」と判定された（`index.php?topic=Products`、初検出 2026-10-20）。
1 ページ目に記事が無く、上部・下部のセンターブロックとメッセージも空のときだけ `<meta name="robots" content="noindex">` を出す。
ページはそのまま表示し、記事が入れば noindex は自動で外れる。存在しないトピックは従来どおり 404、記事のあるトピック、
トップページ、記事ページには影響しない。変更前のファイルはサーバーの `backups/index-soft404-noindex-20261026/index.php`。

元の Geeklog 2.2.2 との差分は `https://github.com/Geeklog-Core/geeklog` のタグ `v2.2.2` の `public_html/index.php` と比べて確認する。
サーバーのファイルは改行コードが CRLF と LF の混在なので、配置するときは変換しない。

## GA4 のタグ（happa-analytics.js、2026-11-02）

全ページの `<head>` 先頭の `<script defer src=".../javascript/happa-analytics.js?v=20261102-1">`（`layout/denim_three/index.thtml`）で読む。ファイルを変えたら `v` を上げる。`www.happa.bz` の公開ページだけを GA4 プロパティ「Happa」のストリーム「Happa」（`G-DR9RX5FWYK`）へ送る。

- 2026-09-10 から 2026-11-02 までは、同じプロパティのショップ用ストリーム（`G-DD72MESY9C`）へ送っていた。
- 管理画面・ログイン・ユーザー設定と `profiles.php`（「記事をメールで送る」は閲覧がほぼボット）は送らない。
- お問い合わせを送った後のページ（`.happa-contact__notice--success` がある `contact.php`）でイベント `contact_submit` を送る。GA4 でキーイベントに指定する。
- `web.config` の CSP（`connect-src`）は、GA4 の送信先 `https://*.google-analytics.com`、`https://*.analytics.google.com`、
  `https://analytics.google.com`、`https://www.google.com`、`https://*.googletagmanager.com` を許す。2026-11-02 まで
  `www.google-analytics.com` と `region1.google-analytics.com` しか許しておらず、`gtag.js` が `analytics.google.com/g/collect`
  （失敗時は `www.google.com/g/collect`）へ送るようになってから、ブラウザが送信を止めて計測がほぼ途絶えていた。
  送信先は Google 側で変わることがあるので、計測が急に減ったら最初にブラウザの CSP 違反を確かめる。
