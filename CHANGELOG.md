# Changelog

## 1.0.3.2 — 2026-11-06 · OJS 3.3

### Accessibility
Applies to the dashboard, the settings page, the public metrics page and
the sidebar block. Computed numbers do not change.
- No text is smaller than 12px. Badges, card notes, table headers,
  tooltips, footnotes, chips, small buttons and the credit line used
  9–11.5px.
- Text contrast is at least 4.5:1. Secondary text is darker; the
  "Recompute now" button and other primary buttons use the darker teal;
  table headers set their own background, because some themes painted
  them grey; the sidebar block's "All metrics" link has its own colour.
- Country bars: the count now sits next to the bar in body text colour.
  White numbers on the bar colours were too faint.
- Bar charts (usage trend, completed reviews, articles and issues per
  year) are drawn at the width of their box, so axis labels are 12px on
  every screen. On narrow screens fewer labels are shown; a box narrower
  than the chart scrolls sideways instead of shrinking the labels.
- Clickable targets are at least 24 × 24px: the "?" methodology tips,
  most-read article links, the block's "All metrics" link, the credit
  link, the small buttons of the visibility matrix, the checkbox rows and
  the text fields of the editor-declared metrics table.
- Backend pages keep a usable width of 300px. On a phone, where the OJS
  side menu leaves only a sliver of the screen, they scroll sideways
  instead of squeezing fields below 24px.

### Housekeeping
- Code comments no longer refer to internal process notes (no code
  change).
- The Turkish README is now `README_TR.md`; both READMEs link to each
  other.

### Erişilebilirlik (TR)
Pano, ayarlar sayfası, kamu metrik sayfası ve kenar bloğu için geçerli.
Hesaplanan sayılar değişmez.
- 12px'ten küçük yazı yok. Rozetler, kart notları, tablo başlıkları,
  ipuçları, dipnotlar, çipler, küçük düğmeler ve geliştirici satırı
  9–11,5px idi.
- Yazı kontrastı en az 4,5:1. İkincil yazı koyulaştı; "Şimdi yeniden
  hesapla" ve diğer birincil düğmeler koyu yeşil-mavi; tablo başlıkları
  kendi zeminini kullanıyor, çünkü bazı temalar onları griye boyuyordu;
  kenar bloğundaki "Tüm metrikler" bağlantısının rengi kendi.
- Ülke çubukları: sayı artık çubuğun yanında, gövde yazı renginde.
  Çubuk renklerinin üstündeki beyaz sayılar okunmuyordu.
- Çubuk grafikler (kullanım eğilimi, tamamlanan hakemlikler, yıllara göre
  makale ve sayı) kutularının genişliğinde çiziliyor; eksen etiketleri
  her ekranda 12px. Dar ekranda daha az etiket gösteriliyor; grafikten
  dar bir kutu etiketleri küçültmek yerine yana kayıyor.
- Tıklanan hedefler en az 24 × 24px: "?" yöntem ipuçları, en çok okunan
  makale bağlantıları, bloktaki "Tüm metrikler" bağlantısı, geliştirici
  bağlantısı, görünürlük matrisinin küçük düğmeleri, onay kutusu
  satırları ve editör beyanı metrikler tablosunun metin alanları.
- Yönetim sayfaları en az 300px kullanılabilir genişliği koruyor.
  Telefonda OJS yan menüsü ekranın küçük bir kısmını bıraktığında
  alanları 24px'in altına sıkıştırmak yerine yana kayıyor.

### Bakım (TR)
- Kod yorumlarında iç süreç notlarına atıf kalmadı (kod değişmedi).
- Türkçe README artık `README_TR.md`; iki README birbirine bağlantı
  veriyor.

## 1.0.3.1 — 2026-11-06 · OJS 3.3

### Fixed
- Acceptance and rejection rates are now calculated over decided
  submissions only: accepted ÷ (accepted + declined) and
  declined ÷ (accepted + declined). Submissions still under review no
  longer lower the rates, the two rates add up to 100%, and the "n" shown
  under a rate is exactly its denominator. Rates are shown with one
  decimal: 66.7% in English, %66,7 in Turkish.
- The same definition applies to the per-year table, the sidebar block
  and the `{journal_metric}` template function. Per-year rates used to
  follow a different group of submissions than the counts in the same
  row; they now match the row's accepted and declined counts. A year
  without any decision shows "—" instead of 0%.
- `{journal_metric key="acceptanceRate"}` (and `declineRate`) now returns
  the formatted percentage in the interface language (66.7% / %66,7)
  instead of a fraction (0.65). Other values are returned unchanged.
- Numbers on the dashboard, the public page and the sidebar block follow
  the interface language (Turkish 2.825 · 192,6 · %66,7; English 2,825 ·
  192.6 · 66.7%). The dashboard and public page used to follow the
  visitor's browser language, and the sidebar block always used English.
- The editorial statistics coverage year no longer changes how rates are
  calculated; it still sets the first year that is counted.
- Metrics already stored show the corrected rates right after the update;
  no recomputation is needed.

### Düzeltildi (TR)
- Kabul ve ret oranları artık yalnız karar verilmiş gönderiler üzerinden
  hesaplanıyor: kabul ÷ (kabul + ret) ve ret ÷ (kabul + ret). İncelemesi
  süren gönderiler oranları düşürmüyor, iki oranın toplamı %100 ediyor ve
  oranın altındaki "n" tam olarak paydası. Oranlar bir ondalıkla
  gösteriliyor: Türkçede %66,7, İngilizcede 66.7%.
- Aynı tanım yıllık tabloda, kenar bloğunda ve `{journal_metric}` şablon
  işlevinde de geçerli. Yıllık oranlar önceden aynı satırdaki sayılardan
  farklı bir gönderi grubuna dayanıyordu; artık satırın kabul ve ret
  sayılarıyla uyumlu. Hiç karar olmayan yıl %0 yerine "—" gösteriyor.
- `{journal_metric key="acceptanceRate"}` (ve `declineRate`) artık oranı
  kesir (0.65) yerine arayüz dilinde biçimlenmiş yüzde olarak (%66,7 /
  66.7%) döndürüyor. Diğer değerler değişmeden döner.
- Panodaki, kamu sayfasındaki ve kenar bloğundaki sayılar arayüz dilini
  izliyor (Türkçe 2.825 · 192,6 · %66,7; İngilizce 2,825 · 192.6 ·
  66.7%). Önceden pano ve kamu sayfası ziyaretçinin tarayıcı dilini,
  kenar bloğu ise her zaman İngilizce biçimi kullanıyordu.
- Kapsam başlangıç yılı artık oran hesabını değiştirmiyor; yalnız hangi
  yıldan itibaren sayılacağını belirliyor.
- Kayıtlı metrikler güncellemeden hemen sonra düzeltilmiş oranları
  gösteriyor; yeniden hesaplama gerekmiyor.

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

## 1.0.0.x — 2026-07-17 … 2026-07-18 (pre-release)

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
