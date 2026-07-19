# Changelog

## 1.0.3.0 — 2026-07-19

### Added
- **Clickable most-read articles**: titles in the "Most read articles"
  table now link to the article page, on both the dashboard and the
  public page. Links print cleanly (no underline or link color on paper).
- **Bulk visibility actions**: the Metric Visibility matrix gained
  "Set all to Hidden / Dashboard / Public" buttons, plus the same trio on
  every group header to change a single group at once. The buttons only
  fill in the form — nothing changes until you press Save.

### Fixed
- The sidebar block now looks the same on every page of the site. Its
  styles moved into a small dedicated stylesheet that loads exactly when
  the block is shown — pages without the block load nothing extra.

## 1.0.2.0 — 2026-07-19

### Added
- **Editorial statistics coverage start year** (`statsCoverageStartYear`).
  Journals whose early OJS records are incomplete (e.g. content migrated in
  bulk from a previous system) can declare a start year for editorial
  workflow statistics. When set:
  - Editorial Activity counters/rates, Turnaround Times and the completed
    peer-review figures cover the declared year onward; the per-year table
    starts at that year and earlier years are never written to the snapshot.
  - Yearly averages are computed from the covered full years only (the
    current year is excluded).
  - The coverage is **always openly declared** — "Editorial statistics cover
    {year}–present." appears next to the "Metrics last updated" stamp on both
    the dashboard and the public page, a "{year}+" chip marks the affected
    section headers, and a note appears under the completed-reviews card.
    Narrowed scope is never silent.
  - Usage statistics, publication output and community/author figures are
    intentionally NOT affected: the whole archive keeps counting there.
  - Saving a changed start year recomputes the affected groups in the same
    request, so the declaration and the numbers always change together.
  - Values outside 1900–current year fall back to "no coverage" (whole
    archive), which is also the default.
- **Developer credit toggle**: the "Developed by ojs-services.com" link on
  the public page can now be turned off in the settings (default: shown).
  The dashboard and the settings page always show the credit.

## 1.0.1.0 — 2026-07-19

First public release.

### Added
- `<compatibility>` block in `version.xml` (OJS 3.3.0.0 – 3.3.0.22); the
  installer now refuses the package on unsupported OJS versions.
- Run lock for the snapshot task: overlapping scheduled runs can no longer
  compute the same work twice.
- Accessibility: WAI-ARIA tab pattern (roles, `aria-selected`, arrow-key
  navigation) on the country tables, `aria-label`s on all charts and on every
  settings input, WCAG AA contrast for dimmed/insufficient cards and chart
  axis labels.
- Footnote under the per-year editorial table explaining the counting axes
  (received = submission date; accepted/declined and rates = decision date;
  published = first publication date).
- Print stylesheet: the public page prints cleanly on A4 (both country tables
  shown, interactive controls hidden, chart colors preserved) — ready to
  attach to indexing applications.
- `CHANGELOG.md` (this file).

### Changed
- Monthly usage chart now renders a continuous 24-month axis; months without
  data are drawn as zero.
- Per-year editorial table skips years with no activity at all.
- "This year's views / downloads" with no usage data is now treated as
  insufficient (hidden on public surfaces, dimmed on the dashboard).
- Snapshot size logging moved from the PHP error log to the scheduled task's
  execution log; the error log only receives the 48 KB threshold warning.

### Removed
- CSV export (dashboard button, handler op and helpers) — superseded by the
  print-friendly public page.

### Fixed
- Scheduled task now survives PHP engine errors in a single provider
  (`Throwable` handling) instead of aborting the whole run.
- JSON embedded in `<script>` blocks is hex-encoded (`JSON_HEX_TAG` et al.)
  as defense-in-depth against markup breakout.

## 1.0.0.x — 2026-07-17 … 2026-07-18 (internal iterations)

- 1.0.0.6 — geographic distribution wired to the visibility matrix and the
  public page (snapshot-served, no live queries); asset cache-buster reads
  `version.xml`.
- 1.0.0.5 — multilingual public page title and manual metric
  title/description (one value per active journal language).
- 1.0.0.4 — shorter editorial labels; removed per-card source badges.
- 1.0.0.3 — public page section moved to the top of the settings page.
- 1.0.0.2 — dashboard-wide CSV dump removed.
- 1.0.0.1 — all settings moved from the plugin modal to a full backend
  settings page.
- 1.0.0.0 — initial implementation: six metric provider groups, nightly
  chunked snapshot task, admin dashboard, public page, sidebar block,
  `{journal_metric}` template function, TR/EN locales; numbers validated
  1:1 against OJS core editorial statistics on reference data.
