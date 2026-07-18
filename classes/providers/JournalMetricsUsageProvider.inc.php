<?php
/**
 * @file plugins/generic/journalMetrics/classes/providers/JournalMetricsUsageProvider.inc.php
 *
 * Copyright (c) 2026 ojs-services.com
 * Distributed under the GNU GPL v3.
 *
 * @class JournalMetricsUsageProvider
 * @brief G3 usage metrics from the legacy OJS 3.3 `metrics` table.
 *
 * ISOLATION RULE (architecture decision 1 / R3): every query against the
 * `metrics` table lives in THIS class and nowhere else. OJS 3.4+ replaces
 * the table with metrics_* split tables; a future port only touches this
 * provider.
 *
 * Constants verified in the Phase 0 report (C8):
 *   ASSOC_TYPE_SUBMISSION      = 1048585  (abstract landing page views)
 *   ASSOC_TYPE_SUBMISSION_FILE = 515      (galley file downloads)
 *   metric_type                = 'ojs::counter'
 *
 * COVERAGE: statsCoverageStartYear does NOT apply here — usage always
 * covers the whole archive (the archive enriches these figures).
 *
 * This provider is EXPENSIVE (~10-40 s per aggregate on multi-million-row
 * tables — Phase 0 C10) and therefore only ever runs inside the chunked
 * nightly task, never synchronously from a web request.
 */

import('plugins.generic.journalMetrics.classes.providers.JournalMetricsBaseProvider');

class JournalMetricsUsageProvider extends JournalMetricsBaseProvider {

	/** Phase 0 C8 constants (decimal values of the ASSOC_TYPE_* hex) */
	const ASSOC_SUBMISSION = 1048585;
	const ASSOC_SUBMISSION_FILE = 515;
	const METRIC_TYPE = 'ojs::counter';

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
		// (architecture decision 2: same scan, no extra passes).
		$monthly = $this->selectRows(
			"SELECT m.month,
				SUM(CASE WHEN m.assoc_type = " . self::ASSOC_SUBMISSION . " THEN m.metric ELSE 0 END) AS views,
				SUM(CASE WHEN m.assoc_type = " . self::ASSOC_SUBMISSION_FILE . " THEN m.metric ELSE 0 END) AS downloads
			FROM metrics m
			WHERE m.metric_type = ? AND m.context_id = ?
			  AND m.assoc_type IN (" . self::ASSOC_SUBMISSION . ", " . self::ASSOC_SUBMISSION_FILE . ")
			  AND m.month IS NOT NULL
			GROUP BY m.month",
			array(self::METRIC_TYPE, $contextId)
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

		// --- Average downloads per article (report C9-4)
		$avgRow = $this->selectRow(
			"SELECT SUM(m.metric) AS total_downloads,
				COUNT(DISTINCT m.submission_id) AS articles
			FROM metrics m
			WHERE m.metric_type = ? AND m.context_id = ?
			  AND m.assoc_type = " . self::ASSOC_SUBMISSION_FILE . "
			  AND m.submission_id IS NOT NULL",
			array(self::METRIC_TYPE, $contextId)
		);
		$articlesWithDownloads = $avgRow ? (int) $avgRow['articles'] : 0;
		$avgDownloads = ($articlesWithDownloads > 0)
			? round(((int) $avgRow['total_downloads']) / $articlesWithDownloads, 1)
			: null;

		// --- Top 10 most viewed articles (report C9-5); titles resolved
		// afterwards in a cheap per-id query (locale-aware).
		$top = $this->selectRows(
			"SELECT m.submission_id, SUM(m.metric) AS total_views
			FROM metrics m
			WHERE m.metric_type = ? AND m.context_id = ?
			  AND m.assoc_type = " . self::ASSOC_SUBMISSION . "
			  AND m.submission_id IS NOT NULL
			GROUP BY m.submission_id
			ORDER BY total_views DESC
			LIMIT 10",
			array(self::METRIC_TYPE, $contextId)
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

		// --- Access country count (reserved — G3-6). Phase 0 C11 found
		// country_id empty on every inspected install; 0 distinct -> null
		// so the metric is treated as "no data" (RED) and auto-hidden.
		$countryRow = $this->selectRow(
			"SELECT COUNT(DISTINCT m.country_id) AS cnt
			FROM metrics m
			WHERE m.metric_type = ? AND m.context_id = ?
			  AND m.country_id IS NOT NULL AND m.country_id <> ''",
			array(self::METRIC_TYPE, $contextId)
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
