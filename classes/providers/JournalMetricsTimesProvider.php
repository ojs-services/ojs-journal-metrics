<?php
/**
 * @file plugins/generic/journalMetrics/classes/providers/JournalMetricsTimesProvider.php
 *
 * Copyright (c) 2026 ojs-services.com
 * Distributed under the GNU GPL v3.
 *
 * @class JournalMetricsTimesProvider
 * @brief Turnaround-time metrics: avg / median / p80 / n for each span.
 *
 * BINDING RULE: duration metrics that need an acceptance DATE use
 * MIN(date_decided) over the accept set of OJS 3.4+ core statistics:
 * Decision::ACCEPT, SKIP_EXTERNAL_REVIEW, SEND_TO_PRODUCTION. Rejection
 * dates use Decision::INITIAL_DECLINE and DECLINE. The editorial counters
 * (JournalMetricsEditorialProvider) always use the core service.
 * Decision codes were renumbered in OJS 3.4 — never write them as
 * literals. Complete submissions: submission_progress = '' (a string
 * column since 3.4; comparing it with 0 would match drafts too).
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

namespace APP\plugins\generic\journalMetrics\classes\providers;

use PKP\decision\Decision;

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
	 * Decision codes that mark an acceptance, as an SQL list.
	 */
	private function _acceptSet() {
		return implode(',', array(Decision::ACCEPT, Decision::SKIP_EXTERNAL_REVIEW, Decision::SEND_TO_PRODUCTION));
	}

	/**
	 * Decision codes that mark a rejection, as an SQL list.
	 */
	private function _declineSet() {
		return implode(',', array(Decision::INITIAL_DECLINE, Decision::DECLINE));
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
				WHERE s.context_id = ? AND s.submission_progress = ''
				  AND s.date_submitted IS NOT NULL{$cov}
			) t
			WHERE t.first_decision IS NOT NULL
			  AND (t.first_pub IS NULL OR CAST(t.date_submitted AS DATE) <= t.first_pub)
		";
		return $this->_daysStats($sql, array_merge(array((int) $contextId), $covParams));
	}

	/**
	 * Submission -> acceptance (first decision in the accept set).
	 */
	private function _submissionToAcceptDays($contextId, $coverageYear) {
		list($cov, $covParams) = $this->_coverageClause('s.date_submitted', $coverageYear);
		$accept = $this->_acceptSet();
		$sql = "
			SELECT DATEDIFF(t.date_accepted, t.date_submitted) AS days
			FROM (
				SELECT s.date_submitted,
					(SELECT MIN(ed.date_decided) FROM edit_decisions ed
						WHERE ed.submission_id = s.submission_id AND ed.decision IN ({$accept})) AS date_accepted
				FROM submissions s
				WHERE s.context_id = ? AND s.submission_progress = ''
				  AND s.status <> 4 AND s.date_submitted IS NOT NULL{$cov}
			) t
			WHERE t.date_accepted IS NOT NULL
		";
		return $this->_daysStats($sql, array_merge(array((int) $contextId), $covParams));
	}

	/**
	 * Submission -> decline (first decision in the decline set, declined
	 * submissions only) — report section B (reject duration query).
	 */
	private function _submissionToRejectDays($contextId, $coverageYear) {
		list($cov, $covParams) = $this->_coverageClause('s.date_submitted', $coverageYear);
		$decline = $this->_declineSet();
		$sql = "
			SELECT DATEDIFF(t.date_declined, t.date_submitted) AS days
			FROM (
				SELECT s.date_submitted,
					(SELECT MIN(ed.date_decided) FROM edit_decisions ed
						WHERE ed.submission_id = s.submission_id AND ed.decision IN ({$decline})) AS date_declined
				FROM submissions s
				WHERE s.context_id = ? AND s.submission_progress = ''
				  AND s.status = 4 AND s.date_submitted IS NOT NULL{$cov}
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
		$accept = $this->_acceptSet();
		$sql = "
			SELECT DATEDIFF(t.date_published, t.date_accepted) AS days
			FROM (
				SELECT
					(SELECT MIN(ed.date_decided) FROM edit_decisions ed
						WHERE ed.submission_id = s.submission_id AND ed.decision IN ({$accept})) AS date_accepted,
					(SELECT MIN(p.date_published) FROM publications p
						WHERE p.submission_id = s.submission_id AND p.status = 3) AS date_published
				FROM submissions s
				WHERE s.context_id = ? AND s.submission_progress = '' AND s.status = 3{$cov}
			) t
			WHERE t.date_accepted IS NOT NULL AND t.date_published IS NOT NULL
		";
		return $this->_daysStats($sql, array_merge(array((int) $contextId), $covParams));
	}

	/**
	 * Submission -> first publication (total span), with the
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
				WHERE s.context_id = ? AND s.submission_progress = ''
				  AND s.status = 3 AND s.date_submitted IS NOT NULL{$cov}
			) t
			WHERE t.date_published IS NOT NULL
			  AND CAST(t.date_submitted AS DATE) <= t.date_published
		";
		return $this->_daysStats($sql, array_merge(array((int) $contextId), $covParams));
	}

	/**
	 * Reviewer report time (date_notified -> date_completed) over all
	 * completed reviews — the single completed-review definition above.
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
