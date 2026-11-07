<?php
/**
 * @file plugins/generic/journalMetrics/classes/providers/JournalMetricsTimesProvider.inc.php
 *
 * Copyright (c) 2026 ojs-services.com
 * Distributed under the GNU GPL v3.
 *
 * @class JournalMetricsTimesProvider
 * @brief Turnaround-time metrics: avg / median / p80 / n / dropped for
 *  each span.
 *
 * POPULATION: every query uses the one workflow filter
 * (JournalMetricsBaseProvider::workflowCondition): complete, submitted,
 * NOT imported submissions — the population of the core editorial
 * statistics. Imported archives carry import-time submission and decision
 * dates and would otherwise produce meaningless (even negative) spans.
 *
 * IMPOSSIBLE SPANS: a negative span (published before accepted, decided
 * before submitted, report completed before the request) is dropped and
 * counted in `dropped`; managers see that count on the dashboard.
 *
 * BINDING RULE: duration metrics that need an acceptance DATE use
 * MIN(date_decided) WHERE decision IN (1, 7) (ACCEPT +
 * SEND_TO_PRODUCTION; SKIP_REVIEW does not exist in OJS 3.3). This looser
 * set is for DATES only; the editorial counters
 * (JournalMetricsEditorialProvider) always use the core service.
 *
 * BINDING RULE: "completed review" everywhere =
 * date_completed IS NOT NULL AND declined = 0 AND cancelled = 0 AND
 * date_notified IS NOT NULL.
 *
 * COVERAGE: the statsCoverageStartYear setting applies to every query in
 * this group — via s.date_submitted >= coverage, and via
 * ra.date_notified >= coverage for review report times (a declared scope,
 * always announced on the public page — never a silent filter).
 *
 * Every query filters on s.context_id so multi-journal installs count
 * each journal separately. Aggregation (avg/median/p80) happens in PHP
 * with nearest-rank semantics, so the plugin also runs on MySQL < 8 /
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
	 */
	private function _firstDecisionDays($contextId, $coverageYear) {
		list($cov, $covParams) = $this->_coverageClause('s.date_submitted', $coverageYear);
		$sql = "
			SELECT DATEDIFF(t.first_decision, t.date_submitted) AS days
			FROM (
				SELECT s.date_submitted,
					(SELECT MIN(ed.date_decided) FROM edit_decisions ed
						WHERE ed.submission_id = s.submission_id) AS first_decision
				FROM submissions s
				WHERE s.context_id = ? AND " . self::workflowCondition('s') . "{$cov}
			) t
			WHERE t.first_decision IS NOT NULL
		";
		return $this->_daysStats($sql, array_merge(array((int) $contextId), $covParams));
	}

	/**
	 * Submission -> acceptance (first decision IN (1,7)).
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
				WHERE s.context_id = ? AND s.status <> 4 AND " . self::workflowCondition('s') . "{$cov}
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
				WHERE s.context_id = ? AND s.status = 4 AND " . self::workflowCondition('s') . "{$cov}
			) t
			WHERE t.date_declined IS NOT NULL
		";
		return $this->_daysStats($sql, array_merge(array((int) $contextId), $covParams));
	}

	/**
	 * Acceptance -> first publication.
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
				WHERE s.context_id = ? AND s.status = 3 AND " . self::workflowCondition('s') . "{$cov}
			) t
			WHERE t.date_accepted IS NOT NULL AND t.date_published IS NOT NULL
		";
		return $this->_daysStats($sql, array_merge(array((int) $contextId), $covParams));
	}

	/**
	 * Submission -> first publication (total span).
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
				WHERE s.context_id = ? AND s.status = 3 AND " . self::workflowCondition('s') . "{$cov}
			) t
			WHERE t.date_published IS NOT NULL
		";
		return $this->_daysStats($sql, array_merge(array((int) $contextId), $covParams));
	}

	/**
	 * Reviewer report time (date_notified -> date_completed) over all
	 * completed reviews of workflow submissions — the single
	 * completed-review definition above.
	 */
	private function _reviewReportDays($contextId, $coverageYear) {
		list($cov, $covParams) = $this->_coverageClause('ra.date_notified', $coverageYear);
		$sql = "
			SELECT DATEDIFF(ra.date_completed, ra.date_notified) AS days
			FROM review_assignments ra
			JOIN submissions s ON s.submission_id = ra.submission_id
			WHERE s.context_id = ? AND " . self::workflowCondition('s') . "
			  AND ra.date_completed IS NOT NULL AND ra.declined = 0
			  AND ra.cancelled = 0 AND ra.date_notified IS NOT NULL{$cov}
		";
		return $this->_daysStats($sql, array_merge(array((int) $contextId), $covParams));
	}

	/**
	 * Fetch the day list and aggregate to {avg, median, p80, n, dropped}.
	 */
	private function _daysStats($sql, $params) {
		$days = array();
		foreach ($this->selectRows($sql, $params) as $row) {
			if ($row['days'] === null) continue;
			$days[] = $row['days'];
		}
		return $this->aggregateDays($days);
	}
}
