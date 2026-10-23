# Download registration guidance

These files extend the deployed Downloads plugin without changing its save,
upload, permission or configuration mechanisms.

- Category rows link to `op=uploadFile&cid=...`.
- New admin registrations accept that category only when it exists and passes
  the existing category read-access filter. Edit, clone and validation retries
  retain their original category handling.
- The editor template loads guidance assets. They identify the destination,
  explain public-card/detail-page fields, and group supporting metadata.
- Preview buttons have `type=button`. No request, file upload or database write
  occurs during preview. Selected file inputs remain intact. Preview text is
  inserted with `textContent`; HTML/autotags are simplified, not executed.
- This is a content/layout preview, not an exact rendering of server-processed
  markup, media autotags or access/date-dependent publication. The existing
  server-side save remains unchanged. The submitting preview button is omitted
  so every visible preview action stays local.

Deployment paths mirror `plugins/` and `public_html/` under Geeklog 2.2.2.
The plugin's packaged admin copy is not the live admin entry point.

## Minimal editor and shared card (2026-10-21)

The preview uses the public template's `happa-download-card` structure and the
site's shared brand CSS, including image/body/action columns and responsive
layout. Scoped resets prevent admin form label widths from leaking into card
metadata. Modified preview typography uses regular weight.

PNG/JPEG/WebP/GIF selections are read with browser FileReader data URLs, without
CSP changes, network requests or uploads. Clearing a selection clears its image;
asynchronous reads cannot restore an older selection.

Visible inputs are category, title, download file, version, introduction and
optional image, plus collapsed optional details. Internal and publication inputs
stay enabled but hidden so their existing values survive edits/retries. IDs,
owner, publication status, post mode, dates and supporting metadata are not reset.
Grey placeholders provide examples without populating values and disappear when
typing. Existing data and server-side save defaults are unchanged.

Evidence: `E:\Codex\CodexVerificationTemp\download-card-minimal-20261021`.
Hidden-browser checks cover shared public styles, local images and removal,
placeholders, compact fields, preserved hidden values, mobile containment, inert
markup, zero upload/save requests and unchanged categories. Existing unrelated
cookie-consent CSP errors are outside this change.

Verification (2026-10-21): PHP/JS syntax checks, live hidden-browser category
navigation and selection, local-file retention, preview text and version,
inert markup, desktop/mobile captures, zero mutation requests and unchanged
category records. Evidence and the verification script are stored externally
under `E:\Codex\CodexVerificationTemp\downloads-registration-20261021`.

## Registration for the latest-build page (2026-10-21)

The description field is relabelled "このビルドで対応したこと" (one item per line)
and remains required. Version is now required in the form (it is the large
number on the public card). The detail text and preview image inputs stay in the
form but are hidden, so existing values survive edits. The preview renders the
public "latest build" card with the same line splitting as the listing, using
`downloads-page.css` (now also linked from `mod_download.thtml`).
