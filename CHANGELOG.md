# Changelog

## 1.0.3.14 — 2026-11-27 · OJS 3.3

### Fixed
- On themes that make the page title a second-level heading (Classic), the
  public page's section and group headings now sit one level below it, so
  screen readers get a correct outline.
- The decorative mark before the "developed by" link is hidden from screen
  readers and has enough contrast on light theme backgrounds.
- The last places that still said "Editor-declared metrics" (the public page
  note, the metric name in the visibility list, the plugin description and
  the README) now say "External indicators" in every language, as the rest of
  the plugin does.

### Düzeltildi
- Sayfa başlığını ikinci düzey başlık yapan temalarda (Classic) kamu
  sayfasının bölüm ve grup başlıkları artık bir alt düzeyde; ekran okuyucular
  doğru bir başlık yapısı görür.
- "Geliştiren" bağlantısının önündeki süs işareti ekran okuyuculardan
  gizlendi ve açık renkli tema zeminlerinde yeterli kontrasta sahip.
- Hâlâ "Editör beyanı metrikler" diyen son yerler (kamu sayfası notu,
  görünürlük listesindeki metrik adı, eklenti açıklaması ve README) eklentinin
  geri kalanı gibi her dilde "Dış göstergeler" oldu.

## 1.0.3.13 — 2026-11-26 · OJS 3.3

### Fixed
- In an interface language the plugin does not ship (for example Italian),
  its texts now appear in English instead of raw keys such as
  "##plugins.generic.journalMetrics…##". A text missing from a shipped
  translation also appears in English.

### Düzeltildi
- Eklentinin dil dosyası olmayan bir arayüz dilinde (ör. İtalyanca)
  metinler artık "##plugins.generic.journalMetrics…##" gibi ham anahtar
  yerine İngilizce görünür. Bir çeviride eksik kalan metin de İngilizce
  görünür.

## 1.0.3.12 — 2026-11-26 · OJS 3.3

### Added
- Spanish and Portuguese (Portugal and Brazil) interface; numbers are
  written the way each language writes them (for example "71,6 %",
  "192,6 días").

### Eklendi
- İspanyolca ve Portekizce (Portekiz ve Brezilya) arayüz; sayılar her
  dilin kendi yazımıyla gösterilir (örneğin "71,6 %", "192,6 días").

## 1.0.3.11 — 2026-11-23 · OJS 3.3

### Fixed
- The nightly calculation keeps running: the plugin puts its task back
  into Acron's task list when it is missing (sites where the figures had
  stopped updating, no action needed). The settings page tells managers
  when it has not run for two days.

### Düzeltildi
- Gece hesaplaması kesilmeden çalışır: eklenti, görevi Acron görev
  listesinde yoksa kendisi geri ekler (sayıları güncellenmeyen siteler,
  elle bir şey gerekmez). Ayar sayfası, iki gündür çalışmadıysa
  yöneticiye bunu söyler.

## 1.0.3.10 — 2026-11-22 · OJS 3.3

### Fixed
- An article or issue with a published status but a publication date in
  the future is no longer counted as published (card, per-year charts,
  times to publication, the editor box). It counts from its date on; the
  nightly refresh picks it up, nothing to do by hand.

### Düzeltildi
- Durumu "yayımlandı" olup yayın tarihi ileride olan makale ya da sayı
  artık yayımlanmış sayılmaz (kart, yıllara göre grafikler, yayına geçen
  süreler, editör kutusu). Tarihinden itibaren sayılır; gece yenilemesi
  bunu kendiliğinden yapar, elle bir şey gerekmez.

## 1.0.3.9 — 2026-11-21 · OJS 3.3

### Changed — Publications and Editorial process
- Every published article now counts in the publication figures, wherever
  it came from. Journals that added articles straight to an issue (for
  example after moving from another system) saw a "Published" card lower
  than the articles-per-year chart; card, chart and total are now the same
  number.
- Received, accepted and declined submissions, rates and times now count
  only submissions with at least one editor decision. Articles added to an
  issue without a decision no longer raise "Received" or shorten
  "Submission to publication". Submissions brought over from another
  system together with their decisions now count in the counters and
  rates; when their dates do not fit together they stay out of the times.
- The public page groups the figures under two headings, "Publications"
  and "Editorial process". When articles were added without a decision, a
  sentence with the numbers says so. The editorial section appears
  automatically once 20 submissions have a final decision; one setting
  shows or hides it.
- Managers see a short box on the dashboard and the settings page that
  says, with numbers, which articles count where, with a list of them.
- "Editor-declared metrics" are now "External indicators", each marked
  "Entered by the editor". A new value named like a computed figure (for
  example "Acceptance rate") is not saved; earlier rows like that stay on
  the settings page, marked, and are not shown publicly.
- Advanced (closed by default): sections left out of the publication
  counts, and a per-year split of the articles chart.
- The sidebar block and `{journal_metric}` follow the public page: while
  the "Editorial process" section is hidden there, its figures (for
  example the acceptance rate and submission-to-publication time) do not
  appear in the block or through `{journal_metric}` either.
- In the sidebar block, durations carry their unit ("192.6 days"), as on
  the public page.
- After the update the figures are recomputed on the next dashboard visit
  or scheduled run.

### Fixed
- The `{journal_metric}` template function is registered only once even
  when OJS builds the plugin twice in one request (the same check as the
  OJS 3.4–3.5 version).

### Değişti — Yayınlar ve Editoryal süreç
- Yayımlanmış her makale, nereden gelirse gelsin, yayın sayılarına girer.
  Makaleleri doğrudan sayıya ekleyen dergilerde (ör. başka bir sistemden
  geçenlerde) "Yayımlanan" kartı yıllara göre makale grafiğinden düşük
  görünüyordu; kart, grafik ve toplam artık aynı sayıdır.
- Alınan, kabul edilen ve reddedilen gönderi, oranlar ve süreler yalnız en
  az bir editör kararı almış gönderileri sayar. Karar olmadan sayıya
  eklenen makaleler artık "Alınan gönderi"yi artırmaz, "Gönderimden yayına"
  süresini kısaltmaz. Başka sistemden kararlarıyla taşınan gönderiler artık
  sayaçlara ve oranlara girer; tarihleri tutarsızsa sürelere girmez.
- Kamu sayfası göstergeleri iki başlık altında toplar: "Yayınlar" ve
  "Editoryal süreç". Karar olmadan eklenen makale varsa bunu sayılarla
  söyleyen bir cümle çıkar. Editoryal bölüm, 20 gönderi son kararını alınca
  kendiliğinden görünür; tek bir ayarla gösterilir ya da gizlenir.
- Yöneticiler panoda ve ayar sayfasında hangi makalenin nerede sayıldığını
  sayılarla anlatan kısa bir kutu ve bu makalelerin listesini görür.
- "Editör beyanı metrikler" artık "Dış göstergeler"; her kartta "Editör
  tarafından girildi" yazar. Hesaplanan bir göstergenin adını taşıyan yeni
  değer (ör. "Kabul oranı") kaydedilmez; daha önce kaydedilmiş böyle
  satırlar ayar sayfasında işaretli kalır, kamu sayfasında gösterilmez.
- Gelişmiş (varsayılan kapalı): yayın sayılarına katılmayacak bölümler ve
  yıllara göre makale kırılımı.
- Kenar çubuğu bloğu ve `{journal_metric}` kamu sayfasına uyar: "Editoryal
  süreç" bölümü orada gizliyken o bölümün sayıları (ör. kabul oranı,
  gönderiden yayına süre) blokta ve `{journal_metric}` ile de görünmez.
- Kenar çubuğu bloğunda süreler birimiyle yazılır ("192,6 gün"), kamu
  sayfasındaki gibi.
- Güncellemeden sonra sayılar ilk pano ziyaretinde ya da ilk zamanlanmış
  çalıştırmada yeniden hesaplanır.

### Düzeltildi
- `{journal_metric}` şablon işlevi, OJS eklentiyi aynı istekte iki kez
  kursa da yalnız bir kez kaydedilir (OJS 3.4–3.5 sürümündeki denetimin
  aynısı).

## 1.0.3.8 — 2026-11-20 · OJS 3.3

### Changed
- The public metrics page shows its title exactly once on every theme.
  Themes that print the page title in their own header keep theirs;
  everywhere else the page prints the title itself — as the page's main
  heading (h1), or as h2 where the theme header already has its own h1 —
  so the page has one h1.

## 1.0.3.6 — 2026-11-15 · OJS 3.3

### Changed
- The plugin now declares itself compatible with every OJS 3.3.0 release
  (`3.3.0.x`) instead of a fixed list that ended at 3.3.0.22, so it is
  accepted on newer 3.3 patch releases too. No code changes.

## 1.0.3.5 — 2026-11-09 · OJS 3.3

### Fixed
- The nightly statistics task now stays in the Acron task list. The list
  is rebuilt for the whole site (for example with "Reload Scheduled Tasks"
  in the site administration), where the plugin is not enabled — it is
  enabled per journal — so the task could be left out. The task is now
  listed while the plugin is enabled in any journal, and only once.
- Work queued for a journal where the plugin has since been switched off
  is skipped.
- A task left behind after the plugin was removed logs a warning instead
  of reporting an error on every run.

## 1.0.3.4 — 2026-11-08 · OJS 3.3

### Fixed
- The snapshot task can now be run by hand from a plain PHP script, not
  only through OJS's scheduled-task tool or the web task runner. Before,
  such a run stopped with "Call to a member function ... on null" because
  OJS core looked for a page request that a script does not have. The task
  now sets that up itself, the way OJS's own command-line tools do; each
  journal is still computed by its own id. If the plugin still cannot be
  loaded, the task log says so in one clear line and names the command to
  use instead.

### Changed
- The archive depth figure "Total issues" is now worked out from the same
  per-year issue counts as the "Issues per year" chart, so the two always
  agree. Results are unchanged.

## 1.0.3.3 — 2026-11-08 · OJS 3.3

### Fixed
- Imported articles no longer distort the workflow metrics. Archives loaded
  into OJS get their submission and decision dates on the day of the
  import, after publication, which produced meaningless and even negative
  turnaround times. Every workflow metric (editorial counters and rates,
  all turnaround times, completed reviews) now uses one shared rule, the
  same one OJS's own statistics use: an article whose submission date is
  later than its first publication date counts as imported and is left
  out. Readership, publication output and author figures still count it.
- Impossible spans that remain (for example an acceptance recorded after
  publication, or a review completed before it was requested) are left
  out of the averages. The dashboard shows managers how many were left out
  on each turnaround card.
- On themes that give plugin pages no content container (Immersion,
  Pragma, Classic, Health Sciences and others) the public metrics page ran
  from edge to edge of the window. It is now at most 1140px wide and
  centred, with a small side margin on phones; inside a theme's own
  column nothing changes.

### Changed
- The settings page says how many imported articles are left out of the
  editorial metrics.
- With the public page switched off, managers who open its address see a
  short note with a link to the setting instead of "page not found";
  visitors still get "page not found".
- When no metric has been made public yet, the public page says so
  ("No metrics have been made public yet") instead of "Metrics are being
  prepared"; managers also get a link to the settings.
- Metrics stored before the update keep their old figures until they are
  recomputed: the scheduled task does it within a day, or press
  "Recompute now" on the dashboard.

### Düzeltildi (TR)
- İçe aktarılan makaleler artık iş akışı göstergelerini bozmuyor. OJS'ye
  sonradan yüklenen arşivlerin gönderi ve karar tarihleri yükleme gününe,
  yani yayından sonraya düşüyor; bu da anlamsız, hatta eksi süreler
  üretiyordu. Bütün iş akışı göstergeleri (editoryal sayaçlar ve oranlar,
  bütün süreç süreleri, tamamlanan hakemlikler) artık tek bir ortak kural
  kullanıyor; kural OJS'nin kendi istatistikleriyle aynı: gönderi tarihi
  ilk yayın tarihinden sonra olan makale içe aktarılmış sayılır ve dışarıda
  kalır. Okunma, yayın çıktısı ve yazar göstergeleri bu makaleleri saymaya
  devam ediyor.
- Geriye kalan imkânsız süreler (örneğin yayından sonra kaydedilmiş bir
  kabul ya da istenmeden önce tamamlanmış bir hakemlik) ortalamalara
  girmiyor. Panoda her süre kartında kaçının dışarıda kaldığı yöneticiye
  gösteriliyor.
- Eklenti sayfalarına içerik kabı vermeyen temalarda (Immersion, Pragma,
  Classic, Health Sciences ve diğerleri) herkese açık metrik sayfası
  pencerenin bir ucundan öbür ucuna uzanıyordu. Artık en çok 1140px
  genişlikte ve ortalı; telefonda iki yanda küçük bir boşluk var. Temanın
  kendi sütunu varsa hiçbir şey değişmiyor.

### Değişti (TR)
- Ayar sayfası, editoryal göstergelerin dışında kalan içe aktarılmış
  makale sayısını gösteriyor.
- Herkese açık sayfa kapalıyken adresini açan yönetici "sayfa bulunamadı"
  yerine ayara bağlantı veren kısa bir not görüyor; ziyaretçi yine "sayfa
  bulunamadı" görüyor.
- Henüz hiçbir gösterge herkese açık değilse sayfa bunu söylüyor ("Henüz
  herkese açık gösterge seçilmedi"); "Metrikler hazırlanıyor" yazmıyor.
  Yöneticiye ayrıca ayarlara bağlantı gösteriliyor.
- Güncellemeden önce kaydedilmiş metrikler yeniden hesaplanana kadar eski
  değerleri gösterir: zamanlanmış görev bunu bir gün içinde yapar ya da
  panodaki "Şimdi yeniden hesapla" düğmesine basın.
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
- Keyboard focus is clearly outlined, and transitions are switched off
  when the reader's system asks for reduced motion.
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
- Klavye odağı belirgin bir çerçeveyle görünüyor; okurun sistemi
  hareketin azaltılmasını istiyorsa geçiş efektleri kapanıyor.
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
