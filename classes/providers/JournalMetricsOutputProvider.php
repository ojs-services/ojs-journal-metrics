<?php
/**
 * @file plugins/generic/journalMetrics/classes/providers/JournalMetricsOutputProvider.php
 *
 * Copyright (c) 2026 ojs-services.com
 * Distributed under the GNU GPL v3.
 *
 * @class JournalMetricsOutputProvider
 * @brief Publication output: articles/year, issues/year, archive depth.
 *
 * COVERAGE: statsCoverageStartYear does NOT apply here — publication
 * output always covers the whole archive.
 *
 * Articles are counted by their FIRST published publication so later
 * versions are not double-counted. Every published article counts
 * (JournalMetricsBaseProvider::publishedCondition), including articles
 * added without an editor decision; sections the editor excluded from
 * counts are left out. publishedTotal = the sum of articlesByYear.
 */

namespace APP\plugins\generic\journalMetrics\classes\providers;

class JournalMetricsOutputProvider extends JournalMetricsBaseProvider {

	/**
	 * @copydoc JournalMetricsBaseProvider::getGroup()
	 */
	public function getGroup() {
		return 'output';
	}

	/**
	 * @copydoc JournalMetricsBaseProvider::compute()
	 */
	public function compute($contextId) {
		$contextId = (int) $contextId;

		// Published articles per year (first published publication). Every
		// published article counts, wherever it came from; only sections the
		// editor excluded from counts are left out. The "Published" card is
		// the sum of this series, so card, chart and totals always agree.
		// "process" splits each year into articles that went through the
		// editorial process and articles added without an editor decision.
		$articlesByYear = array();
		$articlesByYearProcess = array();
		$rows = $this->selectRows(
			"SELECT YEAR(first_pub) AS yr, COUNT(*) AS articles, SUM(in_process) AS process FROM (
				SELECT s.submission_id, MIN(p.date_published) AS first_pub,
					MAX(CASE WHEN " . self::editorialProcessCondition('s') . " THEN 1 ELSE 0 END) AS in_process
				FROM submissions s
				JOIN publications p ON p.submission_id = s.submission_id AND p.status = 3
				WHERE s.context_id = ? AND " . self::publishedCondition('s', $this->_plugin->getExcludedSectionIds($contextId)) . "
				GROUP BY s.submission_id
			) t
			WHERE first_pub IS NOT NULL
			GROUP BY YEAR(first_pub) ORDER BY yr",
			array($contextId)
		);
		foreach ($rows as $row) {
			$articlesByYear[(string) $row['yr']] = (int) $row['articles'];
			$articlesByYearProcess[(string) $row['yr']] = (int) $row['process'];
		}

		// Published issues per year
		$issuesByYear = array();
		$rows = $this->selectRows(
			"SELECT YEAR(date_published) AS yr, COUNT(*) AS issues
			FROM issues
			WHERE journal_id = ? AND published = 1 AND date_published IS NOT NULL
			  AND " . self::datePublishedReachedCondition('date_published') . "
			GROUP BY YEAR(date_published) ORDER BY yr",
			array($contextId)
		);
		foreach ($rows as $row) {
			$issuesByYear[(string) $row['yr']] = (int) $row['issues'];
		}

		// Archive depth — derived from the per-year counts above, so the
		// total always equals the sum of the chart (one source, no drift).
		$years = array_map('intval', array_keys($issuesByYear));
		$firstYear = $years ? min($years) : null;
		$lastYear  = $years ? max($years) : null;

		return array(
			'articlesByYear'       => $articlesByYear,
			'articlesByYearProcess' => $articlesByYearProcess,
			'publishedTotal'       => (int) array_sum($articlesByYear),
			'issuesByYear'         => $issuesByYear,
			'firstPublicationYear' => $firstYear,
			'archiveYears'         => ($firstYear !== null && $lastYear !== null)
				? ($lastYear - $firstYear + 1) : null,
			'totalIssues'          => (int) array_sum($issuesByYear),
		);
	}
}
