<?php
/**
 * @file plugins/generic/journalMetrics/classes/providers/JournalMetricsTimesProvider.inc.php
 *
 * Copyright (c) 2026 ojs-services.com
 * Distributed under the GNU GPL v3.
 *
 * @class JournalMetricsTimesProvider
 * @brief G2 turnaround-time metrics: avg / median / p80 / n for each span.
 *
 * BINDING RULE (architecture decision 3): duration metrics that need an
 * acceptance DATE use MIN(date_decided) WHERE decision IN (1, 7)
 * (ACCEPT + SEND_TO_PRODUCTION — Phase 0 report B5/B6; SKIP_REVIEW does
 * not exist in OJS 3.3). This looser set is for DATES only; counters and
 * rates (JournalMetricsEditorialProvider) always use the core service.
 *
 * BINDING RULE (architecture decision 4): "completed review" everywhere =
 * date_completed IS NOT NULL AND declined = 0 AND cancelled = 0 AND
 * date_notified IS NOT NULL.
 *
 * COVERAGE: the statsCoverageStartYear setting applies to every query in
 * this group via s.date_submitted >= coverage (a declared scope, always
 * announced on the public page — never a silent filter).
 *
 * The inner queries are ported verbatim from Phase 0 report B6-1/2/3 with
 * one addition: an s.context_id filter (Phase 0 ran on a single-journal
 * database; multi-journal installs need the filter — R10). Aggregation
 * (avg/median/p80) happens in PHP with the exact rank semantics of the
 * Phase 0 window-function SQL, so the plugin also runs on MySQL < 8 /
 * MariaDB < 10.2 (same approach as core PKPStatsEditorialService).
 */

import('plugins.generic.journalMetrics.classes.providers.JournalMetricsBaseProvider');

class JournalMetricsTimesProvider extends JournalMetricsBaseProvider {

	/**
	 * @copydoc JournalMetricsBaseProvider::getGroup()
	 */
	public function getGroup() {
		return 'times';
	}

	/**
	 * @copydoc JournalMetricsBaseProvider::compute()
	 */
	public function compute($contextId) {
		$coverageYear = $this->_plugin->getCoverageStartYear($contextId);
		return array(
			'firstDecisionDays'       => $this->_firstDecisionDays($contextId, $coverageYear),
			'submissionToAcceptDays'  => $this->_submissionToAcceptDays($contextId, $coverageYear),
			'submissionToRejectDays'  => $this->_submissionToRejectDays($contextId, $coverageYear),
			'acceptToPublishDays'     => $this->_acceptToPublishDays($contextId, $coverageYear),
			'submissionToPublishDays' => $this->_submissionToPublishDays($contextId, $coverageYear),
			'reviewReportDays'        => $this->_reviewReportDays($contextId, $coverageYear),
		);
	}

	/**
	 * SQL fragment + params for the coverage filter on a submissions alias.
	 *
	 * @return array [clause, params]
	 */
	private function _coverageClause($column, $coverageYear) {
		if ($coverageYear <= 0) return array('', array());
		return array(" AND $column >= ?", array($coverageYear . '-01-01'));
	}

	/**
	 * Submission -> first editorial decision of ANY type.
	 * Mirrors core's daysToDecision population (incomplete + imported
	 * submissions excluded via the core date heuristic).
	 */
	private function _firstDecisionDays($contextId, $coverageYear) {
		list($cov, $covParams) = $this->_coverageClause('s.date_submitted', $coverageYear);
		$sql = "
			SELECT DATEDIFF(t.first_decision, t.date_submitted) AS days
			FROM (
				SELECT s.date_submitted,
					(SELECT MIN(ed.date_decided) FROM edit_decisions ed
						WHERE ed.submission_id = s.submission_id) AS first_decision,
					(SELECT MIN(p.date_published) FROM publications p
						WHERE p.submission_id = s.submission_id AND p.status = 3) AS first_pub
				FROM submissions s
				WHERE s.context_id = ? AND s.submission_progress = 0
				  AND s.date_submitted IS NOT NULL{$cov}
			) t
			WHERE t.first_decision IS NOT NULL
			  AND (t.first_pub IS NULL OR CAST(t.date_submitted AS DATE) <= t.first_pub)
		";
		return $this->_daysStats($sql, array_merge(array((int) $contextId), $covParams));
	}

	/**
	 * Submission -> acceptance (first decision IN (1,7)) — report B6-1.
	 */
	private function _submissionToAcceptDays($contextId, $coverageYear) {
		list($cov, $covParams) = $this->_coverageClause('s.date_submitted', $coverageYear);
		$sql = "
			SELECT DATEDIFF(t.date_accepted, t.date_submitted) AS days
			FROM (
				SELECT s.date_submitted,
					(SELECT MIN(ed.date_decided) FROM edit_decisions ed
						WHERE ed.submission_id = s.submission_id AND ed.decision IN (1,7)) AS date_accepted
				FROM submissions s
				WHERE s.context_id = ? AND s.submission_progress = 0
				  AND s.status <> 4 AND s.date_submitted IS NOT NULL{$cov}
			) t
			WHERE t.date_accepted IS NOT NULL
		";
		return $this->_daysStats($sql, array_merge(array((int) $contextId), $covParams));
	}

	/**
	 * Submission -> decline (first decision IN (4,9), declined submissions
	 * only) — report section B (reject duration query).
	 */
	private function _submissionToRejectDays($contextId, $coverageYear) {
		list($cov, $covParams) = $this->_coverageClause('s.date_submitted', $coverageYear);
		$sql = "
			SELECT DATEDIFF(t.date_declined, t.date_submitted) AS days
			FROM (
				SELECT s.date_submitted,
					(SELECT MIN(ed.date_decided) FROM edit_decisions ed
						WHERE ed.submission_id = s.submission_id AND ed.decision IN (4,9)) AS date_declined
				FROM submissions s
				WHERE s.context_id = ? AND s.submission_progress = 0
				  AND s.status = 4 AND s.date_submitted IS NOT NULL{$cov}
			) t
			WHERE t.date_declined IS NOT NULL
		";
		return $this->_daysStats($sql, array_merge(array((int) $contextId), $covParams));
	}

	/**
	 * Acceptance -> first publication — report B6-2.
	 */
	private function _acceptToPublishDays($contextId, $coverageYear) {
		list($cov, $covParams) = $this->_coverageClause('s.date_submitted', $coverageYear);
		$sql = "
			SELECT DATEDIFF(t.date_published, t.date_accepted) AS days
			FROM (
				SELECT
					(SELECT MIN(ed.date_decided) FROM edit_decisions ed
						WHERE ed.submission_id = s.submission_id AND ed.decision IN (1,7)) AS date_accepted,
					(SELECT MIN(p.date_published) FROM publications p
						WHERE p.submission_id = s.submission_id AND p.status = 3) AS date_published
				FROM submissions s
				WHERE s.context_id = ? AND s.submission_progress = 0 AND s.status = 3{$cov}
			) t
			WHERE t.date_accepted IS NOT NULL AND t.date_published IS NOT NULL
		";
		return $this->_daysStats($sql, array_merge(array((int) $contextId), $covParams));
	}

	/**
	 * Submission -> first publication (total span) — report B6-3, with the
	 * core-style import exclusion (date_submitted <= first date_published).
	 */
	private function _submissionToPublishDays($contextId, $coverageYear) {
		list($cov, $covParams) = $this->_coverageClause('s.date_submitted', $coverageYear);
		$sql = "
			SELECT DATEDIFF(t.date_published, t.date_submitted) AS days
			FROM (
				SELECT s.date_submitted,
					(SELECT MIN(p.date_published) FROM publications p
						WHERE p.submission_id = s.submission_id AND p.status = 3) AS date_published
				FROM submissions s
				WHERE s.context_id = ? AND s.submission_progress = 0
				  AND s.status = 3 AND s.date_submitted IS NOT NULL{$cov}
			) t
			WHERE t.date_published IS NOT NULL
			  AND CAST(t.date_submitted AS DATE) <= t.date_published
		";
		return $this->_daysStats($sql, array_merge(array((int) $contextId), $covParams));
	}

	/**
	 * Reviewer report time (date_notified -> date_completed) over all
	 * completed reviews — single completed-review definition (decision 4).
	 */
	private function _reviewReportDays($contextId, $coverageYear) {
		list($cov, $covParams) = $this->_coverageClause('ra.date_notified', $coverageYear);
		$sql = "
			SELECT DATEDIFF(ra.date_completed, ra.date_notified) AS days
			FROM review_assignments ra
			JOIN submissions s ON s.submission_id = ra.submission_id
			WHERE s.context_id = ?
			  AND ra.date_completed IS NOT NULL AND ra.declined = 0
			  AND ra.cancelled = 0 AND ra.date_notified IS NOT NULL{$cov}
		";
		return $this->_daysStats($sql, array_merge(array((int) $contextId), $covParams));
	}

	/**
	 * Fetch the day list and aggregate to {avg, median, p80, n}.
	 */
	private function _daysStats($sql, $params) {
		$days = array();
		foreach ($this->selectRows($sql, $params) as $row) {
			$days[] = $row['days'];
		}
		return $this->aggregateDays($days);
	}
}
