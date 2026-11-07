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
 * are pure readers: they never write to the database. The SQL definitions
 * documented on each provider are fixed: their joins, filters and decision
 * sets must not be altered without re-validating the resulting numbers.
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

	// ------------------------------------------------------------------
	//  The one workflow filter
	// ------------------------------------------------------------------

	/**
	 * SQL condition: the submission was IMPORTED. OJS core's rule
	 * (PKPStatsEditorialQueryBuilder): the submission date is later than
	 * its first publication date — archives loaded into OJS get their
	 * submission (and decision / review) dates at import time.
	 *
	 * @param string $s alias of the submissions table
	 * @return string
	 */
	public static function importedCondition($s = 's') {
		return "EXISTS (SELECT 1 FROM publications jmx_pi"
			. " WHERE jmx_pi.submission_id = {$s}.submission_id AND jmx_pi.status = 3"
			. " AND jmx_pi.date_published IS NOT NULL"
			. " AND jmx_pi.date_published < CAST({$s}.date_submitted AS DATE))";
	}

	/**
	 * SQL condition shared by EVERY workflow-derived metric (editorial
	 * durations, review times, completed reviews): a complete, submitted,
	 * not-imported submission — the same population OJS core counts in
	 * its editorial statistics. Usage, publication output, authors and
	 * countries do not use it: imported articles are real content there.
	 *
	 * @param string $s alias of the submissions table
	 * @return string
	 */
	public static function workflowCondition($s = 's') {
		return "{$s}.submission_progress = 0 AND {$s}.date_submitted IS NOT NULL"
			. " AND NOT " . self::importedCondition($s);
	}

	/**
	 * Aggregate a list of day counts into {avg, median, p80, n, dropped}.
	 *
	 * Negative spans (e.g. published before accepted, decided before
	 * submitted) are impossible and come from inconsistent data; they are
	 * left out and counted in `dropped` (shown to managers only).
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
	 * @return array ['avg'=>float|null,'median'=>int|null,'p80'=>int|null,'n'=>int,'dropped'=>int]
	 */
	protected function aggregateDays($days) {
		$days = array_map('intval', $days);
		$all = count($days);
		$days = array_values(array_filter($days, function ($d) { return $d >= 0; }));
		$n = count($days);
		$dropped = $all - $n;
		if ($n === 0) {
			return array('avg' => null, 'median' => null, 'p80' => null, 'n' => 0, 'dropped' => $dropped);
		}
		sort($days);
		$avg = round(array_sum($days) / $n, 1);
		$median = $days[(int) ceil($n * 0.5) - 1];
		$p80 = $days[(int) ceil($n * 0.8) - 1];
		return array('avg' => $avg, 'median' => $median, 'p80' => $p80, 'n' => $n, 'dropped' => $dropped);
	}
}
