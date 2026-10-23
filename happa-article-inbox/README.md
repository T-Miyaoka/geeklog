# Happa Geeklog article inbox importer

This package is installed outside `public_html` and is run by Windows Task Scheduler. It does not register a task, publish anything during installation, or modify Geeklog's own source/configuration files.

## Server paths

- Install directory: `C:\inetpub\wwwroot\geeklog\2_2_2\happa-article-inbox` (share path: `Y:\geeklog\2_2_2\happa-article-inbox`)
- PHP CLI: `C:\php-8.5.12\php.exe`
- Task definition: `Happa-Article-Inbox-Import.xml`
- Packages: `inbox\ready\<package-name>\article.json` plus any image files
- Terminal queues: `inbox\done` and `inbox\failed`
- Logs: `logs`

The task polls every five minutes and once at boot. To avoid importing a partially copied package, prepare it outside `inbox\ready` and move the finished directory into `inbox\ready` as the final step.

## One-time setup

1. Copy the importer files to the install directory above. Do not put the importer or credentials under `public_html`.
2. Create the private `credentials` directory beside `run-import.php`, copy `credentials.example.ini` to `credentials/credentials.ini`, and enter the dedicated Geeklog account's username/password. The importer reads only this new path, authenticates with Geeklog, and checks `story.edit` and topic edit access before saving. It uses Geeklog's existing `db-config.php`; do not copy database passwords into this package.
3. Restrict the `credentials` directory and `credentials/credentials.ini` with NTFS permissions so only the chosen Task Scheduler identity and server administrators can read them. The Task Scheduler identity needs read/execute on PHP, Geeklog, and the importer; modify on `inbox`, `logs`, and Geeklog's article-image directories; and local DB connectivity as already configured for the server's PHP/Geeklog installation. Keep the task at Least Privilege.
4. Import `Happa-Article-Inbox-Import.xml` in Task Scheduler. At import, select the dedicated server account that has the folder permissions above. For unattended runs, choose the appropriate “Run whether user is logged on or not” option and enter its password in Task Scheduler's UI; no Windows password is embedded in the XML.
5. Confirm the selected account can run `C:\php-8.5.12\php.exe C:\inetpub\wwwroot\geeklog\2_2_2\happa-article-inbox\run-import.php --check-credentials` from a command prompt on the server. This checks that the credentials file is readable, verifies Geeklog login, `story.edit`, and edit access to `Blog_ja`/`Blog_en`, and creates no article. Normal scheduled runs omit the argument and process packages from `inbox\ready`.

The task file deliberately contains no `UserId` or stored password. Select the execution identity in the import dialog. The XML is a definition only; it does not create or register the task.

## Package format

Each package is a directory containing `article.json` and optional image files. One package can contain one article or a Japanese/English pair. Topics are limited to `Blog_ja` and `Blog_en`; each article's language must be `ja` or `en`. SIDs must be unique.

For a narrowly scoped edit to an existing article that already has registered images, a package may set `update_existing: true` and `preserve_existing_content: true` with an exact `remove_bodytext_html` string and an empty `images` array. The importer verifies that the exact HTML occurs once, reads the current intro/body from Geeklog, removes only that body fragment, and saves through Geeklog's normal article path without uploading or renumbering attachments. It verifies that the fragment is gone and the registered image count is unchanged. This mode does not accept replacement text or package images.

`publish` defaults to `false` (saved as an unpublished Geeklog draft). Set it to `true` only when that language version is intended for immediate public release. `frontpage` is ignored for new articles: every newly imported blog article is shown on the site front page (a package value of `false` is logged as `FRONTPAGE_FORCED` and overridden). Updates to an existing article keep its current front-page setting, so corrections made in Geeklog are preserved. Write `"frontpage": true` in new packages. A timestamp, when supplied, is local server time in `YYYY-MM-DD HH:MM:SS` format. Article text is HTML and passes through Geeklog's normal article filters. Image tokens `[image1]`, `[image2]`, etc. are replaced with attached Geeklog article images; a token can appear in introtext or bodytext. Unused images are appended to bodytext.

Existing SIDs are always rejected as duplicates unless that article explicitly sets `"update_existing": true`. The image-repair mode requires the existing article to have no registered images. The content-only mode described above instead requires an existing image-bearing article and cannot upload, replace, renumber, or remove its attachments. Both modes require the same SID and topic and refuse missing or cross-topic stories. Use either only for an intentional correction to a known article SID.

Example `article.json`:

```json
{
  "schema_version": 1,
  "articles": [
    {
      "sid": "devblog-example_ja",
      "topic": "Blog_ja",
      "language": "ja",
      "title": "DEVELOPMENT SNAPSHOT #NN — 記事タイトル",
      "publish": false,
      "frontpage": true,
      "publish_at": "2026-10-17 12:00:00",
      "introtext": "<p>記事の導入です。[image1]</p>",
      "bodytext": "<p>本文です。</p>",
      "images": ["images/01.png"]
    },
    {
      "sid": "devblog-example_en",
      "topic": "Blog_en",
      "language": "en",
      "title": "DEVELOPMENT SNAPSHOT #NN — Article title",
      "publish": false,
      "frontpage": true,
      "publish_at": "2026-10-17 12:00:00",
      "introtext": "<p>English introduction. [image1]</p>",
      "bodytext": "<p>Article body.</p>",
      "images": ["images/01.png"]
    }
  ]
}
```

The manifest and images are prevalidated; image types are limited to JPEG, PNG, and GIF (10 MiB each, at most 10 per article). The article SIDs are checked for duplicates before any article in the package is saved. Each successful save uses Geeklog's existing `service_submit_story`/`Article` path so topic assignments, HTML filtering, plugin hooks, and feeds are maintained. If a later language version fails, earlier successful saves are not rolled back; the package is moved to `failed` and `import-error.txt` records the completed SIDs. Do not put credentials in a package.

The run log distinguishes an empty queue (credentials are not read in normal polling mode) from missing/unreadable/malformed credentials, Geeklog initialization failures, an unknown or inactive username, a rejected password, and global/topic permission failures. Initialization exceptions include only the exception class and source basename/line; the exception message is suppressed because it can contain sensitive configuration. The runner never logs the configured username or password. A queue-empty run is not an authentication test; use `--check-credentials` for that check without creating an article.

## Import XML and credentials

The XML is configured for the server's confirmed PHP and Geeklog paths. It intentionally leaves the Windows account and logon mode unspecified for selection during import. A dedicated Geeklog editor account's credentials go in `credentials/credentials.ini`, which must be protected by server ACLs and must never be copied back into the repository or shared outside the server. For migration, move the old sibling `credentials.ini` into this directory and deploy the matching runner together. The task starts a new PHP process every five minutes; no task restart or XML reimport is required. An already-running process is not hot-reloaded.

`Happa-Article-Inbox-Import.xml` is saved as UTF-16 little-endian with a BOM, matching the encoding declared in the XML. Keep that encoding when copying or re-saving the task definition; an encoding mismatch can make Task Scheduler reject the file before it reads the task settings.

## Backlog work history

Deploy `run-backlog.php` beside `run-import.php`. Normal five-minute polling also processes up to 100 JSON requests from the private `backlog/ready` directory, allowing bounded batches of historical records. Credential-check mode does not consume this queue. Requests use the existing credentials and require both the importer's existing login checks and `backlog.admin`; they do not grant permissions or create accounts.

Prepare a UTF-8 JSON request outside `backlog/ready`, then move it there as the final step:

```json
{
  "schema_version": 1,
  "source_id": "01a1613d-dc4e-71a1-a0a8-aed4265250cf",
  "title": "Codex使用フォルダの整理",
  "status": 1,
  "category": "other",
  "text": "【概要】\n短い進捗・結果の概要。\n\n【詳細】\n【対象】…\n【実施内容】…\n【確認結果】…\n【残作業】…",
  "event_id": "cleanup-phase1-20261022",
  "comment": "2026-10-22 Asia/Tokyo: 中間保存の整理結果…"
}
```

`source_id` deterministically addresses one ticket using Backlog's existing 32-character GUID format. Repeated updates to that source update the same ticket. A stable `event_id` and identical comment text prevent duplicate history entries on retries. Status values follow the site's Backlog labels: 0 new, 1 in progress, 2 awaiting approval, 3 feedback, 4 completed, 5 pending. New tickets use the authenticated account as holder and registrar; their date fields start with the server's current date and are preserved on updates.

`category` is `improvement` (改善項目) or `other` (その他). Omission preserves the existing classification, or uses Other for a new ticket. The administration screen separates lists and holder summaries by category and offers a combined view. Deploy `plugins/backlog/categories.inc`, the matching controller/templates, and the installer together. The authenticated administrator's first request verifies and, when needed, adds `work_category` to the existing table; existing records default to Other. Native ticket saves and private imports verify the same schema. No accounts or permissions are added. Historical execution dates remain in the work record; importing a record does not backdate its database creation timestamp.

The administration screen remembers category, all/assigned scope, and the completed-record checkbox in an account-scoped cookie for one year. Explicit selections override saved values; unchecking the checkbox clears its saved value. The cookie is restricted to `/admin/plugins/backlog/`, uses Secure/HttpOnly/SameSite=Lax, and contains only allowlisted filters. Ticket classification edits use separate state and do not replace the last selected list filters.

The Backlog list displays the short `【概要】` section and places `【詳細】` inside a native disclosure that starts collapsed. Keep the overview within 100 characters. Existing multiline tickets use their first line as the overview and retain their full text inside the disclosure. Ticket text is rendered literally, so HTML in work records does not change the page. No database migration or JavaScript is required.

The read-only ticket page uses the same overview/disclosure. Titles, descriptions and comments are accepted as literal scalar POST text; SQL escaping and HTML escaping remain at their respective boundaries. Browser form line endings normalize to LF, matching the importer. This prevents classification-only edits from truncating records at quotes, backslashes or Markdown/HTML notation.

`backlog/import-work-history.py` prepares one stable ticket per existing completion item and dated work record. It combines generic verification headings with their parent item, includes linked work-history text, preserves source hashes and historical dates, and splits oversized details into bounded comments. Reuse the generated manifest when preparing an updated batch so ticket identities remain stable. The tool rejects already-migrated link stubs. Before replacing source records with ticket links, verify every queued result and compare the title, full text hash, classification and status through a fresh read-only administration view. Candidate acceptance must not become final completion during migration.

Results and requests move to `backlog/done` or `backlog/failed`. A successful result includes the ticket URL, a SHA-256 of the stored description, the comment ID, and the read-back verification time. Existing tickets from a different source are never overwritten. Preserve a failed request for inspection; after a successful result and ticket verification, remove the acknowledged request/result intermediates. Keep credentials outside packages and keep all queue directories outside `public_html`.

Deploy the matching Backlog timestamp fix as well: ticket creation and comments must store `time()` rather than a converted UTC date string or the legacy hard-coded comment date. Existing historical rows are left intact. Run `backlog/tests/work-history.php` and `legacy-id.php` before deployment, then verify a real ticket and comment through the private inbox.
