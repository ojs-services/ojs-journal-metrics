<?php
/**
 * @file plugins/generic/journalMetrics/classes/providers/JournalMetricsUsageProvider.php
 *
 * Copyright (c) 2026 ojs-services.com
 * Distributed under the GNU GPL v3.
 *
 * @class JournalMetricsUsageProvider
 * @brief Usage metrics from the OJS 3.4+ COUNTER tables.
 *
 * ISOLATION RULE: every query against the usage
 * tables lives in THIS class and nowhere else.
 *
 * Sources (OJS 3.4 / 3.5; the 3.4 upgrade moves the 3.3 `metrics` rows
 * here, keeping their assoc types):
 *   metrics_submission              per-day views / downloads
 *     assoc_type 1048585 (Application::ASSOC_TYPE_SUBMISSION)
 *                        abstract landing page views
 *     assoc_type 515     (Application::ASSOC_TYPE_SUBMISSION_FILE)
 *                        galley file downloads — the same definition as
 *                        the 3.3 line (supplementary files, 531, are not
 *                        counted there either)
 *   metrics_submission_geo_monthly  country of access, per month
 *
 * COVERAGE: statsCoverageStartYear does NOT apply here — usage always
 * covers the whole archive (the archive enriches these figures).
 *
 * This provider is EXPENSIVE (~10-40 s per aggregate on multi-million-row
 * tables) and therefore only ever runs inside the chunked
 * nightly task, never synchronously from a web request.
 */

namespace APP\plugins\generic\journalMetrics\classes\providers;

class JournalMetricsUsageProvider extends JournalMetricsBaseProvider {

	/** Decimal values of the ASSOC_TYPE_* hex constants */
	const ASSOC_SUBMISSION = 1048585;
	const ASSOC_SUBMISSION_FILE = 515;

	/** Months kept in the monthly series */
	const SERIES_MONTHS = 24;

	/**
	 * @copydoc JournalMetricsBaseProvider::getGroup()
	 */
	public function getGroup() {
		return 'usage';
	}

	/**
	 * @copydoc JournalMetricsBaseProvider::isExpensive()
	 */
	public function isExpensive() {
		return true;
	}

	/**
	 * @copydoc JournalMetricsBaseProvider::compute()
	 */
	public function compute($contextId) {
		$contextId = (int) $contextId;

		// --- Single pass over views+downloads grouped by month.
		// Feeds: totals, current-year figures AND the 24-month series
		// (one scan, no extra passes).
		// Month keys keep the 3.3 'YYYYMM' shape, so the snapshot format and
		// the charts are identical on both lines.
		$monthly = $this->selectRows(
			"SELECT DATE_FORMAT(m.date, '%Y%m') AS month,
				SUM(CASE WHEN m.assoc_type = " . self::ASSOC_SUBMISSION . " THEN m.metric ELSE 0 END) AS views,
				SUM(CASE WHEN m.assoc_type = " . self::ASSOC_SUBMISSION_FILE . " THEN m.metric ELSE 0 END) AS downloads
			FROM metrics_submission m
			WHERE m.context_id = ?
			  AND m.assoc_type IN (" . self::ASSOC_SUBMISSION . ", " . self::ASSOC_SUBMISSION_FILE . ")
			  AND m.date IS NOT NULL
			GROUP BY DATE_FORMAT(m.date, '%Y%m')",
			array($contextId)
		);

		$totalViews = 0;
		$totalDownloads = 0;
		$currentYear = date('Y');
		$yearViews = 0;
		$yearDownloads = 0;
		$byMonth = array();
		foreach ($monthly as $row) {
			$month = (string) $row['month'];
			$views = (int) $row['views'];
			$downloads = (int) $row['downloads'];
			$totalViews += $views;
			$totalDownloads += $downloads;
			if (strpos($month, $currentYear) === 0) {
				$yearViews += $views;
				$yearDownloads += $downloads;
			}
			$byMonth[$month] = array('views' => $views, 'downloads' => $downloads);
		}
		krsort($byMonth);
		$series = array_slice($byMonth, 0, self::SERIES_MONTHS, true);
		ksort($series);

		// --- Average downloads per article
		$avgRow = $this->selectRow(
			"SELECT SUM(m.metric) AS total_downloads,
				COUNT(DISTINCT m.submission_id) AS articles
			FROM metrics_submission m
			WHERE m.context_id = ?
			  AND m.assoc_type = " . self::ASSOC_SUBMISSION_FILE,
			array($contextId)
		);
		$articlesWithDownloads = $avgRow ? (int) $avgRow['articles'] : 0;
		$avgDownloads = ($articlesWithDownloads > 0)
			? round(((int) $avgRow['total_downloads']) / $articlesWithDownloads, 1)
			: null;

		// --- Top 10 most viewed articles; titles resolved
		// afterwards in a cheap per-id query (locale-aware).
		$top = $this->selectRows(
			"SELECT m.submission_id, SUM(m.metric) AS total_views
			FROM metrics_submission m
			WHERE m.context_id = ?
			  AND m.assoc_type = " . self::ASSOC_SUBMISSION . "
			GROUP BY m.submission_id
			ORDER BY total_views DESC
			LIMIT 10",
			array($contextId)
		);
		$topArticles = array();
		foreach ($top as $row) {
			$submissionId = (int) $row['submission_id'];
			$topArticles[] = array(
				'submissionId' => $submissionId,
				'views'        => (int) $row['total_views'],
				'title'        => $this->_getSubmissionTitle($submissionId, $contextId),
			);
		}

		// --- Access country count from the geo table (filled only when
		// the journal records the country of access — a site / usage
		// statistics setting); 0 distinct -> null so the metric is treated
		// as "no data" (RED) and auto-hidden.
		$countryRow = $this->selectRow(
			"SELECT COUNT(DISTINCT g.country) AS cnt
			FROM metrics_submission_geo_monthly g
			WHERE g.context_id = ?
			  AND g.country IS NOT NULL AND g.country <> ''",
			array($contextId)
		);
		$countryCount = ($countryRow && (int) $countryRow['cnt'] > 0) ? (int) $countryRow['cnt'] : null;

		return array(
			'totalAbstractViews'    => $totalViews,
			'totalGalleyDownloads'  => $totalDownloads,
			'currentYear'           => array(
				'year'      => (int) $currentYear,
				'views'     => $yearViews,
				'downloads' => $yearDownloads,
			),
			'avgDownloadsPerArticle'  => $avgDownloads,
			'articlesWithDownloads'   => $articlesWithDownloads,
			'topArticles'             => $topArticles,
			'accessCountryCount'      => $countryCount,
			'monthlySeries'           => $series,
		);
	}

	/**
	 * Resolve a submission's current title (context primary locale first,
	 * then any available locale).
	 */
	private function _getSubmissionTitle($submissionId, $contextId) {
		$rows = $this->selectRows(
			"SELECT ps.setting_value, ps.locale
			FROM publication_settings ps
			JOIN submissions s ON ps.publication_id = s.current_publication_id
			WHERE s.submission_id = ? AND ps.setting_name = 'title'",
			array((int) $submissionId)
		);
		if (empty($rows)) return '';

		static $primaryLocale = null;
		if ($primaryLocale === null) {
			$row = $this->selectRow(
				"SELECT primary_locale FROM journals WHERE journal_id = ?",
				array((int) $contextId)
			);
			$primaryLocale = $row ? (string) $row['primary_locale'] : '';
		}
		foreach ($rows as $row) {
			if ($row['locale'] === $primaryLocale && trim((string) $row['setting_value']) !== '') {
				return (string) $row['setting_value'];
			}
		}
		foreach ($rows as $row) {
			if (trim((string) $row['setting_value']) !== '') {
				return (string) $row['setting_value'];
			}
		}
		return '';
	}
}
