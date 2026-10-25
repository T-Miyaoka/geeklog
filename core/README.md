# Geeklog 本体への変更

Geeklog 2.2.2 本体のファイルのうち、Happa サイトで変更しているものを置きます。中の並びはサイトのフォルダ構成と同じです。
置いてあるファイルは、サーバーで動いているファイルそのもの（Happa の変更を含む全体）です。

| ファイル | サーバー上の配置先 | Happa の変更 |
|---|---|---|
| `public_html/index.php` | `public_html/index.php` | 旧 `Blog` トピックから言語別トピック（`Blog_ja`/`Blog_en`）への転送、トップページの SEO ヘッダー（`happa_buildSeoHeader`）、記事が 1 本もないトピック一覧への `noindex` |

## 空のトピック一覧の noindex（2026-10-26）

記事もセンターブロックも無いトピック一覧（`Products`、`Calculator` など）はトピック名だけの空のページになり、
Google Search Console で「ソフト 404」と判定された（`index.php?topic=Products`、初検出 2026-10-20）。
1 ページ目に記事が無く、上部・下部のセンターブロックとメッセージも空のときだけ `<meta name="robots" content="noindex">` を出す。
ページはそのまま表示し、記事が入れば noindex は自動で外れる。存在しないトピックは従来どおり 404、記事のあるトピック、
トップページ、記事ページには影響しない。変更前のファイルはサーバーの `backups/index-soft404-noindex-20261026/index.php`。

元の Geeklog 2.2.2 との差分は `https://github.com/Geeklog-Core/geeklog` のタグ `v2.2.2` の `public_html/index.php` と比べて確認する。
サーバーのファイルは改行コードが CRLF と LF の混在なので、配置するときは変換しない。
