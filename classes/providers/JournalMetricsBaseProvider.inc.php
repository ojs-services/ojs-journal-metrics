<?php
/**
 * @file plugins/generic/journalMetrics/classes/providers/JournalMetricsBaseProvider.inc.php
 *
 * Copyright (c) 2026 ojs-services.com
 * Distributed under the GNU GPL v3.
 *
 * @class JournalMetricsBaseProvider
 * @brief Abstract base class for metric providers.
 *
 * A provider computes one snapshot group for one journal context. Providers
 * are pure readers: they never write to the database. All SQL definitions
 * are binding to the Phase 0 research report (JOURNAL-METRICS-FAZ0-RAPOR.md);
 * the joins, filters and decision sets used there must not be altered.
 */

import('lib.pkp.classes.db.DAO');

abstract class JournalMetricsBaseProvider extends DAO {

	/** @var JournalMetricsPlugin */
	protected $_plugin;

	/**
	 * @param JournalMetricsPlugin $plugin
	 */
	public function __construct($plugin) {
		parent::__construct();
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
		$result = $this->retrieve($sql, $params);
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
	 * Median/p80 semantics replicate the Phase 0 SQL exactly:
	 * sort ascending, median = value at rank CEIL(n*0.5),
	 * p80 = value at rank CEIL(n*0.8) (1-based ranks).
	 *
	 * The aggregation happens in PHP (instead of SQL window functions) so
	 * the plugin also runs on MySQL < 8 / MariaDB < 10.2 — the same
	 * approach OJS core takes in PKPStatsEditorialService. The values are
	 * validated 1:1 against the Phase 0 window-function outputs.
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
