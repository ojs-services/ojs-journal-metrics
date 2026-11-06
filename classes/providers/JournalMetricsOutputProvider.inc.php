<?php
/**
 * @file plugins/generic/journalMetrics/classes/providers/JournalMetricsOutputProvider.inc.php
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
 * versions are not double-counted — the same rule as the core
 * editorialStats service, whose yearly figures these queries reproduce.
 */

import('plugins.generic.journalMetrics.classes.providers.JournalMetricsBaseProvider');

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

		// Published articles per year (first published publication)
		$articlesByYear = array();
		$rows = $this->selectRows(
			"SELECT YEAR(first_pub) AS yr, COUNT(*) AS articles FROM (
				SELECT s.submission_id, MIN(p.date_published) AS first_pub
				FROM submissions s
				JOIN publications p ON p.submission_id = s.submission_id AND p.status = 3
				WHERE s.status = 3 AND s.context_id = ?
				GROUP BY s.submission_id
			) t
			WHERE first_pub IS NOT NULL
			GROUP BY YEAR(first_pub) ORDER BY yr",
			array($contextId)
		);
		foreach ($rows as $row) {
			$articlesByYear[(string) $row['yr']] = (int) $row['articles'];
		}

		// Published issues per year
		$issuesByYear = array();
		$rows = $this->selectRows(
			"SELECT YEAR(date_published) AS yr, COUNT(*) AS issues
			FROM issues
			WHERE journal_id = ? AND published = 1 AND date_published IS NOT NULL
			GROUP BY YEAR(date_published) ORDER BY yr",
			array($contextId)
		);
		foreach ($rows as $row) {
			$issuesByYear[(string) $row['yr']] = (int) $row['issues'];
		}

		// Archive depth
		$depth = $this->selectRow(
			"SELECT MIN(YEAR(date_published)) AS first_year,
				MAX(YEAR(date_published)) AS last_year,
				COUNT(*) AS total_issues
			FROM issues
			WHERE journal_id = ? AND published = 1 AND date_published IS NOT NULL",
			array($contextId)
		);

		$firstYear = ($depth && $depth['first_year'] !== null) ? (int) $depth['first_year'] : null;
		$lastYear  = ($depth && $depth['last_year'] !== null) ? (int) $depth['last_year'] : null;

		return array(
			'articlesByYear'       => $articlesByYear,
			'issuesByYear'         => $issuesByYear,
			'firstPublicationYear' => $firstYear,
			'archiveYears'         => ($firstYear !== null && $lastYear !== null)
				? ($lastYear - $firstYear + 1) : null,
			'totalIssues'          => $depth ? (int) $depth['total_issues'] : 0,
		);
	}
}
