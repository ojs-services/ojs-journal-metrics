<?php
/**
 * @file plugins/generic/journalMetrics/classes/providers/JournalMetricsEditorialProvider.inc.php
 *
 * Copyright (c) 2026 ojs-services.com
 * Distributed under the GNU GPL v3.
 *
 * @class JournalMetricsEditorialProvider
 * @brief G1 editorial counters and rates.
 *
 * BINDING RULE (architecture decision 3): all COUNTERS and RATES in this
 * group come from the core editorialStats service output verbatim
 * (accepted = decision ACCEPT(1) only), so the plugin always matches the
 * core Statistics screen 1:1. The looser accept definition
 * (decision IN (1,7)) is used ONLY for date-based duration metrics in
 * JournalMetricsTimesProvider — never here. Do not mix the two.
 *
 * COVERAGE: the statsCoverageStartYear setting applies here (a declared
 * scope, always announced on the public page — never a silent filter).
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
		$service = Services::get('editorialStats');
		$coverageYear = $this->_plugin->getCoverageStartYear($contextId);
		$currentYear = (int) date('Y');

		// All-time overview — the 16 core keys, stored as key => value.
		// With a declared coverage, the whole group starts at Jan 1 of the
		// coverage year (no dateEnd).
		$allTimeArgs = array('contextIds' => array($contextId));
		if ($coverageYear > 0) {
			$allTimeArgs['dateStart'] = $coverageYear . '-01-01';
		}
		$allTime = $this->_overviewToMap($service->getOverview($allTimeArgs));

		// Per-year overviews. Without coverage: the last YEARS_BACK
		// calendar years. With coverage: every year from the coverage year
		// on — years before it are never written to the snapshot.
		$byYear = array();
		$startYear = ($coverageYear > 0)
			? $coverageYear
			: $currentYear - (self::YEARS_BACK - 1);
		for ($year = $startYear; $year <= $currentYear; $year++) {
			$overview = $this->_overviewToMap($service->getOverview(array(
				'contextIds' => array($contextId),
				'dateStart'  => $year . '-01-01',
				'dateEnd'    => $year . '-12-31',
			)));
			$byYear[(string) $year] = array(
				'submissionsReceived'           => $overview['submissionsReceived'],
				'submissionsAccepted'           => $overview['submissionsAccepted'],
				'submissionsDeclined'           => $overview['submissionsDeclined'],
				'submissionsDeclinedDeskReject' => $overview['submissionsDeclinedDeskReject'],
				'submissionsDeclinedPostReview' => $overview['submissionsDeclinedPostReview'],
				'submissionsPublished'          => $overview['submissionsPublished'],
				'acceptanceRate'                => $overview['acceptanceRate'],
				'declineRate'                   => $overview['declineRate'],
			);
		}

		if ($coverageYear > 0) {
			// The core getAverages() does not support a date range, so with
			// a declared coverage the yearly averages are computed here:
			// arithmetic mean of the byYear values over the FULL years from
			// the coverage year (current year excluded). No full year -> null.
			$averages = $this->_averagesFromByYear($byYear, $coverageYear, $currentYear);
		} else {
			// Yearly averages over full years; the service returns -1 when
			// no full year of data exists — normalized to null (R6).
			$averages = $service->getAverages(array('contextIds' => array($contextId)));
			foreach ($averages as $key => $value) {
				if ($value === -1) $averages[$key] = null;
			}
		}

		return array(
			'allTime'        => $allTime,
			'byYear'         => $byYear,
			'yearlyAverages' => $averages,
		);
	}

	/**
	 * Arithmetic yearly averages from byYear rows over the full years
	 * [coverageYear .. currentYear-1]; null for every key when there is
	 * no full year.
	 */
	private function _averagesFromByYear($byYear, $coverageYear, $currentYear) {
		$keys = array(
			'submissionsReceived', 'submissionsAccepted', 'submissionsDeclined',
			'submissionsDeclinedDeskReject', 'submissionsDeclinedPostReview',
			'submissionsPublished',
		);
		$fullYears = 0;
		$sums = array_fill_keys($keys, 0);
		for ($year = $coverageYear; $year < $currentYear; $year++) {
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

	/**
	 * Flatten the service's [{key, name, value}, ...] rows to key => value.
	 */
	private function _overviewToMap($overview) {
		$map = array();
		foreach ($overview as $row) {
			$map[$row['key']] = $row['value'];
		}
		return $map;
	}
}
