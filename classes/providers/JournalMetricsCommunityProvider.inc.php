<?php
/**
 * @file plugins/generic/journalMetrics/classes/providers/JournalMetricsCommunityProvider.inc.php
 *
 * Copyright (c) 2026 ojs-services.com
 * Distributed under the GNU GPL v3.
 *
 * @class JournalMetricsCommunityProvider
 * @brief G4 community metrics: unique authors, role counts, author
 *  countries, completed reviews per year.
 *
 * Role counts use the ROLE_ID constants verified in the Phase 0 report
 * (D12): REVIEWER=4096, AUTHOR=65536, READER=1048576. Disabled users are
 * excluded everywhere. The unique-author deduplication is delegated to
 * JournalMetricsCountryDAO (ported verbatim from countryStats), honouring
 * the configured strategy (ORCID -> email -> name by default).
 *
 * COVERAGE: the statsCoverageStartYear setting applies ONLY to the
 * completed-review figures here (ra.date_notified >= coverage); unique
 * authors, role counts and author countries always cover the whole
 * archive — the archive enriches them by design.
 *
 * BINDING RULE (architecture decision 4): "completed review" =
 * date_completed IS NOT NULL AND declined = 0 AND cancelled = 0 AND
 * date_notified IS NOT NULL — identical filter in yearly and total counts.
 */

import('plugins.generic.journalMetrics.classes.providers.JournalMetricsBaseProvider');

class JournalMetricsCommunityProvider extends JournalMetricsBaseProvider {

	/**
	 * @copydoc JournalMetricsBaseProvider::getGroup()
	 */
	public function getGroup() {
		return 'community';
	}

	/**
	 * @copydoc JournalMetricsBaseProvider::compute()
	 */
	public function compute($contextId) {
		$contextId = (int) $contextId;
		$settings = $this->_plugin->getAllSettings($contextId);
		$dao = $this->_plugin->getCountryDAO();
		$coverageYear = $this->_plugin->getCoverageStartYear($contextId);
		$covClause = $coverageYear > 0 ? " AND ra.date_notified >= ?" : "";
		$covParams = $coverageYear > 0 ? array($coverageYear . '-01-01') : array();

		// Unique authors + country distribution (countryStats dedup, D13)
		$totals = $dao->getTotals($contextId, $settings['uniqueStrategy'], $settings['orcidFallback']);
		$authorsByCountry = $dao->getAuthorsByCountry($contextId, $settings['uniqueStrategy'], $settings['orcidFallback']);
		$articlesByCountry = $dao->getArticlesByCountry($contextId);

		$topCountries = array();
		foreach (array_slice($authorsByCountry, 0, 10) as $row) {
			$topCountries[] = array(
				'country' => (string) $row['country'],
				'authors' => (int) $row['author_count'],
			);
		}

		// Full enriched lists so the public page can render the whole
		// geographic distribution section from the snapshot alone (the
		// admin-only fetchData op is never exposed to visitors).
		$authorsEnriched = $this->_plugin->enrichCountryData($authorsByCountry, 'author_count');
		$articlesEnriched = $this->_plugin->enrichCountryData($articlesByCountry, 'article_count');

		// Role-based counts (report D12), active (disabled = 0) users only
		$roleCounts = $this->_roleCounts($contextId);

		// Total distinct active users holding any role in this journal
		$memberRow = $this->selectRow(
			"SELECT COUNT(DISTINCT uug.user_id) AS total,
				COUNT(DISTINCT CASE WHEN u.disabled = 0 THEN uug.user_id END) AS active
			FROM user_user_groups uug
			JOIN user_groups ug ON ug.user_group_id = uug.user_group_id
			JOIN users u ON u.user_id = uug.user_id
			WHERE ug.context_id = ?",
			array($contextId)
		);

		// Completed reviews per year + per-article average (report B7)
		$completedByYear = array();
		$rows = $this->selectRows(
			"SELECT YEAR(ra.date_notified) AS yr,
				COUNT(*) AS completed
			FROM review_assignments ra
			JOIN submissions s ON s.submission_id = ra.submission_id
			WHERE s.context_id = ?
			  AND ra.date_completed IS NOT NULL AND ra.declined = 0
			  AND ra.cancelled = 0 AND ra.date_notified IS NOT NULL{$covClause}
			GROUP BY YEAR(ra.date_notified)
			ORDER BY yr",
			array_merge(array($contextId), $covParams)
		);
		foreach ($rows as $row) {
			$completedByYear[(string) $row['yr']] = (int) $row['completed'];
		}

		$reviewTotals = $this->selectRow(
			"SELECT COUNT(*) AS completed_reviews,
				COUNT(DISTINCT ra.submission_id) AS reviewed_articles
			FROM review_assignments ra
			JOIN submissions s ON s.submission_id = ra.submission_id
			WHERE s.context_id = ?
			  AND ra.date_completed IS NOT NULL AND ra.declined = 0
			  AND ra.cancelled = 0 AND ra.date_notified IS NOT NULL{$covClause}",
			array_merge(array($contextId), $covParams)
		);
		$completedTotal = $reviewTotals ? (int) $reviewTotals['completed_reviews'] : 0;
		$reviewedArticles = $reviewTotals ? (int) $reviewTotals['reviewed_articles'] : 0;

		return array(
			'uniqueAuthors'    => (int) $totals['totalAuthors'],
			'reviewers'        => $roleCounts['reviewers'],
			'authors'          => $roleCounts['authors'],
			'readers'          => $roleCounts['readers'],
			'totalActiveUsers' => $memberRow ? (int) $memberRow['active'] : 0,
			'completedReviewsByYear' => $completedByYear,
			'completedReviewsTotal'  => $completedTotal,
			'avgReviewsPerArticle'   => $reviewedArticles > 0
				? round($completedTotal / $reviewedArticles, 2) : null,
			'authorCountries' => array(
				'count'         => (int) $totals['totalCountries'],
				'top'           => $topCountries,
				'authors'       => $authorsEnriched,
				'articles'      => $articlesEnriched,
				'totalAuthors'  => (int) $totals['totalAuthors'],
				'totalArticles' => (int) $totals['totalArticles'],
			),
		);
	}

	/**
	 * Active user counts per role (ROLE_ID constants from
	 * lib/pkp/classes/security/Role.inc.php, verified in Phase 0 D12).
	 */
	private function _roleCounts($contextId) {
		$rows = $this->selectRows(
			"SELECT ug.role_id,
				COUNT(DISTINCT CASE WHEN u.disabled = 0 THEN uug.user_id END) AS active_users
			FROM user_groups ug
			JOIN user_user_groups uug ON uug.user_group_id = ug.user_group_id
			JOIN users u ON u.user_id = uug.user_id
			WHERE ug.context_id = ?
			GROUP BY ug.role_id",
			array($contextId)
		);
		$byRole = array();
		foreach ($rows as $row) {
			$byRole[(int) $row['role_id']] = (int) $row['active_users'];
		}
		return array(
			'reviewers' => isset($byRole[4096]) ? $byRole[4096] : 0,     // ROLE_ID_REVIEWER
			'authors'   => isset($byRole[65536]) ? $byRole[65536] : 0,   // ROLE_ID_AUTHOR
			'readers'   => isset($byRole[1048576]) ? $byRole[1048576] : 0, // ROLE_ID_READER
		);
	}
}
