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
	//  The one definition: which articles count where
	// ------------------------------------------------------------------
	//
	//  Counts (publications, authors, countries, usage): every published
	//      article, wherever it came from          -> publishedCondition()
	//  Editorial counters and rates (received, accepted, declined):
	//      only submissions that went through the editorial process
	//                                              -> editorialProcessCondition()
	//  Durations (decision times, time to publication, review times):
	//      editorial process AND consistent dates  -> durationCondition()
	//
	//  Every metric uses these functions; none repeats the rules.

	/** Decision codes that mark an acceptance (as OJS core counts them). */
	public static function acceptDecisions() {
		return array(1); // SUBMISSION_EDITOR_DECISION_ACCEPT (OJS 3.3 core counts this one)
	}

	/** Decision code of a rejection before review (desk reject). */
	public static function deskDeclineDecision() {
		return 9; // SUBMISSION_EDITOR_DECISION_INITIAL_DECLINE
	}

	/** Decision code of a rejection after review. */
	public static function reviewDeclineDecision() {
		return 4; // SUBMISSION_EDITOR_DECISION_DECLINE
	}

	/**
	 * Recommendations are advice from a recommending editor, not decisions;
	 * they do not make a submission part of the editorial process.
	 */
	public static function recommendationDecisions() {
		// SUBMISSION_EDITOR_RECOMMEND_ACCEPT, _PENDING_REVISIONS, _RESUBMIT, _DECLINE
		return array(11, 12, 13, 14);
	}

	/**
	 * SQL condition: the submission is complete and was submitted
	 * (OJS 3.3: submission_progress is a number, 0 = complete).
	 *
	 * @param string $s alias of the submissions table
	 * @return string
	 */
	public static function completeCondition($s = 's') {
		return "{$s}.submission_progress = 0 AND {$s}.date_submitted IS NOT NULL";
	}

	/**
	 * SQL condition: at least one editor decision exists for the
	 * submission (recommendations do not count). Articles added straight
	 * to an issue without any decision do not meet it.
	 *
	 * @param string $s alias of the submissions table
	 * @return string
	 */
	public static function editorDecisionCondition($s = 's') {
		return "EXISTS (SELECT 1 FROM edit_decisions jmx_ed"
			. " WHERE jmx_ed.submission_id = {$s}.submission_id"
			. " AND jmx_ed.decision NOT IN (" . implode(',', array_map('intval', self::recommendationDecisions())) . "))";
	}

	/**
	 * SQL condition: the submission went through the editorial process —
	 * complete, submitted, and at least one editor decision.
	 *
	 * @param string $s alias of the submissions table
	 * @return string
	 */
	public static function editorialProcessCondition($s = 's') {
		return self::completeCondition($s) . " AND " . self::editorDecisionCondition($s);
	}

	/**
	 * SQL condition: the submission's dates are INCONSISTENT — its first
	 * publication date lies before its submission date (typical for
	 * archives loaded into OJS: they get submission dates at import time).
	 * Such submissions still count in counts and rates, not in durations.
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
	 * SQL condition for every DURATION metric: editorial process and
	 * consistent dates.
	 *
	 * @param string $s alias of the submissions table
	 * @return string
	 */
	public static function durationCondition($s = 's') {
		return self::editorialProcessCondition($s) . " AND NOT " . self::importedCondition($s);
	}

	/**
	 * SQL condition: the publication date is today or earlier. A date in
	 * the future means "not published yet", even with a published status
	 * (an editor can publish with a later date). Compared with tomorrow
	 * in the journal's time zone, so DATE and DATETIME columns both work;
	 * the value is generated here (digits and dashes only).
	 *
	 * @param string $column e.g. "p.date_published"
	 * @return string
	 */
	public static function datePublishedReachedCondition($column) {
		return "{$column} < '" . date('Y-m-d', strtotime('tomorrow')) . "'";
	}

	/**
	 * SQL condition: a PUBLISHED article — published status and a
	 * published publication dated today or earlier — optionally outside
	 * the sections the editor excluded from counts. Every count of
	 * published articles (card, chart, totals, authors, countries) and
	 * every figure based on the publication date uses it, so they always
	 * agree. A future-dated article starts counting on its date (the
	 * nightly snapshot picks it up).
	 *
	 * @param string $s alias of the submissions table
	 * @param int[] $excludedSectionIds
	 * @return string
	 */
	public static function publishedCondition($s = 's', $excludedSectionIds = array()) {
		$sql = "{$s}.status = 3 AND EXISTS (SELECT 1 FROM publications jmx_pp"
			. " WHERE jmx_pp.submission_id = {$s}.submission_id AND jmx_pp.status = 3"
			. " AND jmx_pp.date_published IS NOT NULL"
			. " AND " . self::datePublishedReachedCondition('jmx_pp.date_published') . ")";
		$excluded = array_values(array_filter(array_map('intval', (array) $excludedSectionIds)));
		if (!empty($excluded)) {
			$sql .= " AND NOT EXISTS (SELECT 1 FROM publications jmx_ps"
				. " WHERE jmx_ps.publication_id = {$s}.current_publication_id"
				. " AND jmx_ps.section_id IN (" . implode(',', $excluded) . "))";
		}
		return $sql;
	}

	/**
	 * How the journal's articles divide between the rules above, for the
	 * explanation shown under the editorial section and the editor box.
	 * One query per number; the same conditions as the metrics, so the
	 * explanation can never disagree with the cards.
	 *
	 * @param int $contextId
	 * @param int[] $excludedSectionIds sections left out of the counts
	 * @param int $coverageYear editorial data from this year on (0 = all)
	 * @return array
	 */
	public function classifySubmissions($contextId, $excludedSectionIds = array(), $coverageYear = 0) {
		$contextId = (int) $contextId;
		$published = self::publishedCondition('s', $excludedSectionIds);
		$cov = $coverageYear > 0 ? " AND s.date_submitted >= '" . (int) $coverageYear . "-01-01'" : '';
		$count = function ($where) use ($contextId) {
			$row = $this->selectRow("SELECT COUNT(*) AS c FROM submissions s WHERE s.context_id = ? AND {$where}", array($contextId));
			return $row ? (int) $row['c'] : 0;
		};
		return array(
			// all published articles (= the "Published" card)
			'published'          => $count($published),
			// published, went through the editorial process
			'publishedProcess'   => $count($published . ' AND ' . self::editorialProcessCondition('s')),
			// published without any editor decision (added straight to an issue)
			'publishedDirect'    => $count($published . ' AND NOT ' . self::editorDecisionCondition('s')),
			// submissions behind the editorial figures (any outcome)
			'process'            => $count(self::editorialProcessCondition('s') . $cov),
			// editorial process, but dates inconsistent: left out of durations
			'inconsistentDates'  => $count(self::editorialProcessCondition('s') . ' AND ' . self::importedCondition('s') . $cov),
			// complete, still waiting for a first decision
			'awaitingDecision'   => $count(self::completeCondition('s') . ' AND s.status = 1 AND NOT ' . self::editorDecisionCondition('s')),
		);
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
