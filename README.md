# Journal Metrics for OJS

**Show the numbers that matter about your journal — automatically, transparently, in one place.**

Journal Metrics is a generic plugin for Open Journal Systems 3.3 that turns the
data already inside your OJS installation into a professional metrics page:
editorial activity, turnaround times, readership, community reach and
publication output. Journals use it to strengthen indexing applications
(DOAJ, Scopus, Web of Science and regional indexes ask for exactly these
numbers), to give authors realistic expectations about review and publication
times, and to demonstrate editorial transparency to readers and institutions.

## What you get

- **Admin dashboard** — every metric at a glance: metric cards, per-year
  tables, a 24-month usage trend, most-read articles and a full geographic
  distribution section (author/article countries with charts and tables).
- **Public metrics page** — a clean, theme-aware page at
  `/journalmetrics/publicpage` showing **only** the metrics you choose to
  publish. Print-friendly: the page breaks cleanly on A4, ready to attach to
  an indexing application.
- **Sidebar block** — selected public metrics in your journal's sidebar.
- **`{journal_metric}` template function** — theme developers can place any
  public metric anywhere: `{journal_metric key="submissionsPublished"}`.
- **Navigation menu integration** — add the public page to any menu with the
  built-in "Journal Metrics" menu item type.
- **Multilingual** — TR/EN interface; the public page title and
  editor-declared metrics accept a value per active journal language.

## Screenshots

**Admin dashboard**

![Admin dashboard](screenshots/admin_dashboard1.png)
![Admin dashboard](screenshots/admin_dashboard2.png)
![Admin dashboard](screenshots/admin_dashboard3.png)
![Admin dashboard](screenshots/admin_dashboard4.png)
![Admin dashboard](screenshots/admin_dashboard5.png)

**Public metrics page & sidebar block**

![Public metrics page and sidebar block](screenshots/public_page_sidebar_block.png)

**Settings**

![Settings](screenshots/settings_page.png)

## The metric catalogue

**Editorial activity** — submissions received, accepted, declined (with
desk/post-review split), articles published, acceptance and rejection rates.
Counters and rates are computed by OJS's own editorial statistics engine, so
they always match the built-in Statistics screens.
*Acceptance rate* = among submissions that reached a final decision, the share
that were accepted — the same methodology OJS itself uses.

**Turnaround times** — days to first decision, to acceptance, to rejection,
average review time, acceptance-to-publication and submission-to-publication.
Every time metric reports the average, the median, the 80th percentile (the
value 80% of articles stay under) and the number of articles measured, so a
single outlier can never distort the story.

**Usage** — total abstract views and file downloads, this year's figures,
average downloads per article, the ten most-read articles and a 24-month
views/downloads trend, all from OJS's standard usage statistics.

**Community** — unique authors (with configurable ORCID → e-mail → name
de-duplication), reviewers, registered users, completed reviews per year and
the geographic distribution of your authors.

**Publication output** — articles per year, issues per year and archive depth.

**Editor-declared metrics** — up to five values that do not live in OJS
(CiteScore, index coverage, and similar). They are entered by the editorial
team with a source and are shown separately from the automatic metrics, each
stamped with its last update date.

## Built on trust

- Automatic values are **computed, never edited**. Editors decide what is
  shown or hidden — they cannot change a number.
- Editor-declared metrics are kept visibly separate from automatic ones.
- Every public page carries a **"Metrics last updated"** stamp, and every rate
  and time metric explains its methodology in a tooltip.
- Small samples are handled honestly: rates and times based on fewer records
  than a configurable threshold are suppressed instead of shown as misleading
  precision.
- Scope is never narrowed silently: if a journal declares a coverage start
  year for its editorial statistics, that coverage is always announced right
  on the page, next to the numbers it applies to.

## Works with every kind of journal

- **Journals managing the full workflow in OJS** get the complete catalogue
  automatically — editorial counters, rates and all turnaround times included.
- **Journals that import articles directly** (XML import, back-issue uploads)
  simply hide the editorial group and run the same professional page on usage,
  community, output and editor-declared metrics.
- Every metric has its own visibility switch: **Hidden**, **Dashboard**
  (managers only, the default) or **Public**. Nothing is published unless an
  editor deliberately opens it.

## Installation

1. Website Settings → Plugins → **Upload A New Plugin** and select
   `journalMetrics-1_0_3_0.tar.gz`.
2. Enable **Journal Metrics** under Generic Plugins.
3. Open **Journal Metrics** from the left management menu; the fast metric
   groups are computed on first visit.
4. **Run the scheduled task once after installation** so usage statistics are
   computed immediately (they are otherwise picked up by the next scheduled
   run):

   ```
   php tools/runScheduledTasks.php plugins/generic/journalMetrics/scheduledTasks.xml
   ```

The plugin registers itself with the Acron plugin and refreshes its snapshot
automatically (hourly runs, one full recomputation per day). On large
installations we recommend adding the command above as a real cron job.
No `config.inc.php` changes and no FTP access are required.

## Configuration

Everything lives on one settings page (Journal Metrics → **Settings**):
the public page switch and title, the per-metric visibility matrix,
editor-declared metrics, author de-duplication strategy, display options,
the small-sample threshold and a "Recompute now" button. The
"Developed by ojs-services.com" credit link on the public page can also be
turned off there.

**Editorial statistics coverage** — many journals moved to OJS mid-life:
the early volumes were imported in bulk, so submission dates and editorial
decisions only exist for recent years. For exactly this case the settings
page offers a coverage start year. Editorial counters, rates, turnaround
times and completed peer-review figures then cover that year onward, yearly
averages use only the covered full years, and the pages openly declare
"Editorial statistics cover {year}–present." next to the last-updated
stamp — narrowed scope is always declared, never silent. Usage, publication
output and community figures keep counting the whole archive.

## Compatibility

| | |
|---|---|
| OJS | 3.3.0-x (3.3.0.0 – 3.3.0.22) |
| PHP | 7.4 – 8.1 |
| Database | MySQL / MariaDB (standard OJS setups) |
| Journals | single- and multi-journal installations |
| Themes | works on any OJS 3.3 theme — default or custom; see our themes: [ojs-services.com/ojs-themes](https://ojs-services.com/ojs-themes) |
| Plugin version | 1.0.3.0 |

## License & support

GNU GPL v3.

**OJS Services** — [ojs-services.com](https://ojs-services.com) · info@ojs-services.com
