<?php
/**
 * @file plugins/generic/journalMetrics/classes/providers/JournalMetricsEditorialProvider.inc.php
 *
 * Copyright (c) 2026 ojs-services.com
 * Distributed under the GNU GPL v3.
 *
 * @class JournalMetricsEditorialProvider
 * @brief Editorial counters and rates, and how the journal's articles
 *  divide between the publication counts and the editorial figures.
 *
 * POPULATION: every counter uses JournalMetricsBaseProvider::
 * editorialProcessCondition() — a complete submission with at least one
 * editor decision. Articles added to an issue without any decision count
 * as publications (JournalMetricsOutputProvider), never as received
 * submissions. Archives with inconsistent dates (submitted after their
 * publication) do count here; they are only left out of durations.
 *
 * COUNTERS (same semantics as OJS core statistics):
 * - received: by submission date;
 * - accepted: decision ACCEPT (the OJS 3.3 core rule) by decision date,
 *   submission not declined since;
 * - declined: INITIAL_DECLINE (desk) and DECLINE (after review) by
 *   decision date, submission still declined.
 * Dates are compared half-open (>= Jan 1, < next Jan 1), so the last day
 * of a year is included. The RATES are derived over the decided cohort
 * (JournalMetricsPlugin::decisionRates) and stored unrounded.
 *
 * COVERAGE: the statsCoverageStartYear setting applies here (a declared
 * scope, always announced on the public page — never a silent filter).
 *
 * The classification (published / process / added without a decision /
 * inconsistent dates / awaiting a decision) is computed in this same group
 * so the explanation shown with the editorial figures always comes from
 * the same snapshot as the figures themselves.
 */

import('plugins.generic.journalMetrics.classes.providers.JournalMetricsBaseProvider');

class JournalMetricsEditorialProvider extends JournalMetricsBaseProvider {

	/** Number of calendar years (including the current one) in byYear */
	const YEARS_BACK = 5;

	/**
	 * @copydoc JournalMetricsBaseProvider::getGroup()
	 */
	public function getGroup() {
		return 'editorial';
	}

	/**
	 * @copydoc JournalMetricsBaseProvider::compute()
	 */
	public function compute($contextId) {
		$contextId = (int) $contextId;
		$coverageYear = $this->_plugin->getCoverageStartYear($contextId);
		$currentYear = (int) date('Y');

		// All time — with a declared coverage, from Jan 1 of that year on.
		$allTime = $this->_counts($contextId, $coverageYear > 0 ? $coverageYear . '-01-01' : null, null);
		$allTime = array_merge($allTime, JournalMetricsPlugin::decisionRates($allTime));

		// Per year. Without coverage: the last YEARS_BACK calendar years.
		// With coverage: every year from the coverage year on.
		$byYear = array();
		$startYear = ($coverageYear > 0) ? $coverageYear : $currentYear - (self::YEARS_BACK - 1);
		for ($year = $startYear; $year <= $currentYear; $year++) {
			$row = $this->_counts($contextId, $year . '-01-01', ($year + 1) . '-01-01');
			$rates = JournalMetricsPlugin::decisionRates($row);
			$row['acceptanceRate'] = $rates['acceptanceRate'];
			$row['declineRate'] = $rates['declineRate'];
			$byYear[(string) $year] = $row;
		}

		return array(
			'allTime'        => $allTime,
			'byYear'         => $byYear,
			'yearlyAverages' => $this->_averagesFromByYear($byYear, $startYear, $currentYear),
			'classification' => $this->classifySubmissions(
				$contextId,
				$this->_plugin->getExcludedSectionIds($contextId),
				$coverageYear
			),
		);
	}

	/**
	 * The editorial counters for one period [$start, $end).
	 *
	 * @param int $contextId
	 * @param string|null $start inclusive (Y-m-d) or null
	 * @param string|null $end exclusive (Y-m-d) or null
	 * @return array
	 */
	private function _counts($contextId, $start, $end) {
		$process = self::editorialProcessCondition('s');

		list($rangeSql, $rangeParams) = $this->_range('s.date_submitted', $start, $end);
		$row = $this->selectRow(
			"SELECT COUNT(*) AS c FROM submissions s WHERE s.context_id = ? AND {$process}{$rangeSql}",
			array_merge(array($contextId), $rangeParams)
		);
		$received = $row ? (int) $row['c'] : 0;

		$accepted = $this->_countDecided($contextId, self::acceptDecisions(), 's.status <> 4', $start, $end);
		$desk = $this->_countDecided($contextId, array(self::deskDeclineDecision()), 's.status = 4', $start, $end);
		$review = $this->_countDecided($contextId, array(self::reviewDeclineDecision()), 's.status = 4', $start, $end);

		return array(
			'submissionsReceived'           => $received,
			'submissionsAccepted'           => $accepted,
			'submissionsDeclined'           => $desk + $review,
			'submissionsDeclinedDeskReject' => $desk,
			'submissionsDeclinedPostReview' => $review,
		);
	}

	/**
	 * Distinct submissions with one of $decisions decided in [$start, $end)
	 * whose current status still matches (an acceptance later reversed by
	 * a decline is not counted as accepted — OJS core's rule).
	 */
	private function _countDecided($contextId, $decisions, $statusSql, $start, $end) {
		list($rangeSql, $rangeParams) = $this->_range('ed.date_decided', $start, $end);
		$row = $this->selectRow(
			"SELECT COUNT(DISTINCT s.submission_id) AS c
			FROM submissions s
			JOIN edit_decisions ed ON ed.submission_id = s.submission_id
			WHERE s.context_id = ? AND " . self::editorialProcessCondition('s') . "
			  AND ed.decision IN (" . implode(',', array_map('intval', $decisions)) . ")
			  AND {$statusSql}{$rangeSql}",
			array_merge(array($contextId), $rangeParams)
		);
		return $row ? (int) $row['c'] : 0;
	}

	/**
	 * Half-open date range on a column: [" AND col >= ? AND col < ?", params].
	 */
	private function _range($column, $start, $end) {
		$sql = '';
		$params = array();
		if ($start !== null) { $sql .= " AND {$column} >= ?"; $params[] = $start; }
		if ($end !== null) { $sql .= " AND {$column} < ?"; $params[] = $end; }
		return array($sql, $params);
	}

	/**
	 * Arithmetic yearly averages from byYear rows over the full years
	 * [startYear .. currentYear-1]; null for every key when there is no
	 * full year.
	 */
	private function _averagesFromByYear($byYear, $startYear, $currentYear) {
		$keys = array(
			'submissionsReceived', 'submissionsAccepted', 'submissionsDeclined',
			'submissionsDeclinedDeskReject', 'submissionsDeclinedPostReview',
		);
		$fullYears = 0;
		$sums = array_fill_keys($keys, 0);
		for ($year = $startYear; $year < $currentYear; $year++) {
			$row = isset($byYear[(string) $year]) ? $byYear[(string) $year] : null;
			if ($row === null) continue;
			$fullYears++;
			foreach ($keys as $key) {
				$sums[$key] += isset($row[$key]) ? (int) $row[$key] : 0;
			}
		}
		$averages = array();
		foreach ($keys as $key) {
			$averages[$key] = ($fullYears > 0) ? (int) round($sums[$key] / $fullYears) : null;
		}
		return $averages;
	}
}
