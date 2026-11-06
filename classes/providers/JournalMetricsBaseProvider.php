<?php
/**
 * @file plugins/generic/journalMetrics/classes/providers/JournalMetricsBaseProvider.php
 *
 * Copyright (c) 2026 ojs-services.com
 * Distributed under the GNU GPL v3.
 *
 * @class JournalMetricsBaseProvider
 * @brief Abstract base class for metric providers.
 *
 * A provider computes one snapshot group for one journal context. Providers
 * are pure readers: they never write to the database. The SQL definitions
 * documented on each provider are fixed: their joins, filters and decision
 * sets must not be altered without re-validating the resulting numbers.
 *
 * OJS 3.4 / 3.5: the SQL runs through Laravel's query builder (DB::select);
 * decision codes come from PKP\decision\Decision (renumbered in 3.4).
 */

namespace APP\plugins\generic\journalMetrics\classes\providers;

use Illuminate\Support\Facades\DB;

abstract class JournalMetricsBaseProvider {

	/** @var JournalMetricsPlugin */
	protected $_plugin;

	/**
	 * @param JournalMetricsPlugin $plugin
	 */
	public function __construct($plugin) {
		$this->_plugin = $plugin;
	}

	/**
	 * Snapshot group key this provider fills (e.g. 'editorial', 'usage').
	 * @return string
	 */
	abstract public function getGroup();

	/**
	 * Compute the snapshot payload for one context.
	 * @param int $contextId
	 * @return array
	 */
	abstract public function compute($contextId);

	/**
	 * Expensive providers (full metrics-table scans) are executed last and
	 * are never run synchronously from a web request.
	 * @return boolean
	 */
	public function isExpensive() {
		return false;
	}

	// ------------------------------------------------------------------
	//  Shared helpers
	// ------------------------------------------------------------------

	/**
	 * Run a SELECT and return all rows as associative arrays.
	 *
	 * @param string $sql
	 * @param array $params
	 * @return array
	 */
	protected function selectRows($sql, $params = array()) {
		$result = DB::select($sql, $params);
		$rows = array();
		foreach ($result as $row) {
			$rows[] = (array) $row;
		}
		return $rows;
	}

	/**
	 * Run a SELECT expected to return a single row; null if empty.
	 */
	protected function selectRow($sql, $params = array()) {
		$rows = $this->selectRows($sql, $params);
		return isset($rows[0]) ? $rows[0] : null;
	}

	/**
	 * Aggregate a list of day counts into {avg, median, p80, n}.
	 *
	 * Median/p80 use nearest-rank semantics:
	 * sort ascending, median = value at rank CEIL(n*0.5),
	 * p80 = value at rank CEIL(n*0.8) (1-based ranks).
	 *
	 * The aggregation happens in PHP (instead of SQL window functions) so
	 * the plugin also runs on MySQL < 8 / MariaDB < 10.2 — the same
	 * approach OJS core takes in PKPStatsEditorialService. The results match
	 * the equivalent window-function SQL 1:1.
	 *
	 * @param array $days list of integers
	 * @return array ['avg'=>float|null,'median'=>int|null,'p80'=>int|null,'n'=>int]
	 */
	protected function aggregateDays($days) {
		$days = array_map('intval', $days);
		$n = count($days);
		if ($n === 0) {
			return array('avg' => null, 'median' => null, 'p80' => null, 'n' => 0);
		}
		sort($days);
		$avg = round(array_sum($days) / $n, 1);
		$median = $days[(int) ceil($n * 0.5) - 1];
		$p80 = $days[(int) ceil($n * 0.8) - 1];
		return array('avg' => $avg, 'median' => $median, 'p80' => $p80, 'n' => $n);
	}
}
