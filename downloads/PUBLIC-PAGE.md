# Public download page: files first

The 2026-10-21 change replaces the oversized hero with a compact green/gold logo
header, followed by the existing file listing and, only when rows are visible,
a neutral three-step installation guide. It does not assume ZIP or installer
packaging. File-specific instructions remain on the existing detail page.

Only presentation state and text are changed in `public_html/downloads/index.php`.
Database queries, permissions, release/date checks, category filtering, ordering,
pagination, localization overlays, visit/download endpoints and file storage are
unchanged. Empty-state text describes the current listing, without promising a
release date or incorrectly offering a download. Existing fragment links to
`#dlm_filelist_info` are retained. Thumbnail-free records now use the same public
card structure without changing their URL placeholders.

`downloads-page.css` is scoped to the public listing, preserving the green/gold
palette and existing logo and not changing the registration preview. New/modified
text uses regular weight. No server configuration or registered data is changed.

Tests: `php -n downloads/tests/public-layout.php` (15 checks).
Evidence: `E:\Codex\CodexVerificationTemp\downloads-public-priority-20261021`.
Live hidden-browser checks cover HTTP 200, Japanese/English empty states, no
empty-state download CTA or guide, compact branding and mobile containment.
Available-file states use explicitly labelled DOM-only fixtures rendered with
the real deployed card template (image and no-image cases). These are not
registered/published records and no delivery links are followed. Verification
does not submit forms or alter database/configuration records.

## Latest build first, no detail page (2026-10-21)

The listing now shows the newest released file as the "latest build" card and
every older file as one row under "earlier versions" (always newest first; the
sort menu is no longer shown). The version is shown once, as a large number on a
green plate. The latest card lists "what this build changes": the description
column, one item per line (leading bullets removed, markup reduced to text and
escaped). Earlier versions show only the first item. The header button downloads
the latest file directly.

The detail page was removed: `index.php?id=...` redirects to the listing, the
"more" link is no longer appended, and no template links to `{filedetail_url}`.
The thumbnail image is not used. Pagination counts only the earlier versions.
`#dlm_filelist_info` still exists (on the main column); its brand-theme bar
style is neutralised in `downloads-page.css`. Records no longer carry the
`pluginRow` class, so theme row styles do not apply. Headings use the brand
serif stack; numbers prefer Yu Mincho for even figures. Regular weight only.

Tests: `php downloads/tests/public-layout.php` (64 checks: change
list parsing, latest/earlier split with 0, 1 and 3 files in both languages,
template order, no detail links, no bold, detail-page redirect).
