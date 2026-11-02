# Happa サイトマップ

`public_html/sitemap.xml` を、決まったページと公開中の記事から作り直す CLI です（2026-11-02）。
以前の `sitemap.xml` は 2026-08-15 に手で作った 12 件（記事は 2 本だけ）のままで、その後の記事が Google へ伝わっていなかった。

- 決まったページ：トップ、開発者ブログの一覧（`Blog_ja`/`Blog_en`）、ショップ、ダウンロード、お問い合わせ。どれも日本語版と英語版を `hreflang` で結ぶ。
- 記事：下書きでなく、公開日時を過ぎ、匿名ユーザーが読める（`perm_anon >= 2`）記事すべて。`article.php?story=<sid>`。
  `_ja`／`_en` で終わる同じ記事の両方があれば `hreflang` で結ぶ。`lastmod` は公開日時と更新日時の新しい方の日付。
- データベースは読むだけ。内容が変わった時だけ一時ファイルから置き換える。失敗した時は前の `sitemap.xml` を残す。

## 起動のされ方

専用のタスクは無く、記事取り込み（`happa-article-inbox/run-import.php`、5 分ごと）が別の PHP プロセスで起動する。

- 記事のパッケージを取り込んだ後。
- 取り込むものが無い回で、前回の起動から 24 時間たった時（管理画面で記事を直した分を拾う）。
- `happa-sitemap\rebuild-requested` というファイルがある時（中身は問わない。起動した時に消す）。すぐ作り直したい時に置く。

結果は取り込みのログ（`SITEMAP_REFRESHED` / `SITEMAP_FAILED`）と `data\last-run.json`（件数・記事の sid・失敗の理由）に残る。

## サーバー上の配置

- 配置先：`C:\inetpub\wwwroot\geeklog\2_2_2\happa-sitemap\build-sitemap.php`（`happa-article-inbox` と並べる）
- 実行アカウントには `data` の変更権限と、`public_html\sitemap.xml` の変更権限が要る（`public_html` に一時ファイルを作れない時は、`sitemap.xml` をそのまま上書きする）。
- Search Console には `https://www.happa.bz/sitemap.xml` を登録済み（2026-08-15）。URL は変わらないので登録し直す必要はない。

## テスト

リポジトリのルートから `php -n happa-sitemap/tests/sitemap.php`。
