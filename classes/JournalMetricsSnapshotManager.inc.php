<?php
/**
 * @file plugins/generic/journalMetrics/classes/JournalMetricsSnapshotManager.inc.php
 *
 * Copyright (c) 2026 ojs-services.com
 * Distributed under the GNU GPL v3.
 *
 * @class JournalMetricsSnapshotManager
 * @brief Reads and writes the per-journal metrics snapshot.
 *
 * Storage (architecture decision 2 / Phase 0 F16): ONE JSON document per
 * journal in plugin_settings under the key `metricsSnapshot`, setting type
 * 'object' (core DAO json_encode/json_decode). plugin_settings.setting_value
 * is TEXT (64 KB); every write logs the encoded size and raises a warning
 * above 48 KB (R9).
 *
 * The snapshot schema is the Phase 0 report §5 schema, schemaVersion 1,
 * plus usage.monthlySeries (last 24 months) and mandatory `n` on every
 * duration/rate metric.
 */

define('JOURNAL_METRICS_SCHEMA_VERSION', 1);
define('JOURNAL_METRICS_SNAPSHOT_WARN_BYTES', 49152); // 48 KB (R9)

class JournalMetricsSnapshotManager {

	/** Snapshot groups in canonical order */
	public static $GROUPS = array('editorial', 'times', 'community', 'output', 'manual', 'usage');

	/** @var JournalMetricsPlugin */
	private $_plugin;

	public function __construct($plugin) {
		$this->_plugin = $plugin;
	}

	// ------------------------------------------------------------------
	//  Read
	// ------------------------------------------------------------------

	/**
	 * The stored snapshot for a journal, or null when none exists yet.
	 * @param int $contextId
	 * @return array|null
	 */
	public function getSnapshot($contextId) {
		$snapshot = $this->_plugin->getSetting((int) $contextId, 'metricsSnapshot');
		return is_array($snapshot) ? $snapshot : null;
	}

	/**
	 * True when every group has been computed at least once.
	 */
	public function isComplete($snapshot) {
		if (!is_array($snapshot)) return false;
		$updated = isset($snapshot['groupsUpdatedAt']) ? $snapshot['groupsUpdatedAt'] : array();
		foreach (self::$GROUPS as $group) {
			if (empty($updated[$group])) return false;
		}
		return true;
	}

	/**
	 * Visibility of a metric key inside a snapshot (falls back to the
	 * default 'admin' for unknown keys).
	 */
	public function getVisibility($snapshot, $metricKey) {
		if (is_array($snapshot) && isset($snapshot['visibility'][$metricKey])) {
			$value = $snapshot['visibility'][$metricKey];
			if (in_array($value, array('hidden', 'admin', 'public'), true)) return $value;
		}
		return 'admin';
	}

	/**
	 * Resolve a scalar display value for {journal_metric}.
	 *
	 * @param array $snapshot
	 * @param string $key metric catalogue key
	 * @param string|null $year optional year for per-year lookups
	 * @return string|int|float|null
	 */
	public function lookupMetricValue($snapshot, $key, $year = null) {
		$editorialKeys = array(
			'submissionsReceived', 'submissionsAccepted', 'submissionsDeclined',
			'submissionsPublished', 'acceptanceRate', 'declineRate',
		);
		if (in_array($key, $editorialKeys, true)) {
			if ($year !== null && isset($snapshot['editorial']['byYear'][$year][$key])) {
				return $snapshot['editorial']['byYear'][$year][$key];
			}
			return isset($snapshot['editorial']['allTime'][$key]) ? $snapshot['editorial']['allTime'][$key] : null;
		}

		$timeKeys = array(
			'firstDecisionDays', 'submissionToAcceptDays', 'submissionToRejectDays',
			'reviewReportDays', 'acceptToPublishDays', 'submissionToPublishDays',
		);
		if (in_array($key, $timeKeys, true)) {
			return isset($snapshot['times'][$key]['avg']) ? $snapshot['times'][$key]['avg'] : null;
		}

		switch ($key) {
			case 'totalAbstractViews':
				return isset($snapshot['usage']['totalAbstractViews']) ? $snapshot['usage']['totalAbstractViews'] : null;
			case 'totalGalleyDownloads':
				return isset($snapshot['usage']['totalGalleyDownloads']) ? $snapshot['usage']['totalGalleyDownloads'] : null;
			case 'currentYearUsage':
				if (!isset($snapshot['usage']['currentYear'])) return null;
				$cy = $snapshot['usage']['currentYear'];
				return $cy['views'] . ' / ' . $cy['downloads'];
			case 'avgDownloadsPerArticle':
				return isset($snapshot['usage']['avgDownloadsPerArticle']) ? $snapshot['usage']['avgDownloadsPerArticle'] : null;
			case 'accessCountryCount':
				return isset($snapshot['usage']['accessCountryCount']) ? $snapshot['usage']['accessCountryCount'] : null;
			case 'uniqueAuthors':
				return isset($snapshot['community']['uniqueAuthors']) ? $snapshot['community']['uniqueAuthors'] : null;
			case 'reviewerCount':
				return isset($snapshot['community']['reviewers']) ? $snapshot['community']['reviewers'] : null;
			case 'memberCount':
				return isset($snapshot['community']['totalActiveUsers']) ? $snapshot['community']['totalActiveUsers'] : null;
			case 'authorCountries':
				return isset($snapshot['community']['authorCountries']['count']) ? $snapshot['community']['authorCountries']['count'] : null;
			case 'completedReviews':
				if ($year !== null && isset($snapshot['community']['completedReviewsByYear'][$year])) {
					return $snapshot['community']['completedReviewsByYear'][$year];
				}
				return isset($snapshot['community']['completedReviewsTotal']) ? $snapshot['community']['completedReviewsTotal'] : null;
			case 'articlesPerYear':
				if ($year !== null && isset($snapshot['output']['articlesByYear'][$year])) {
					return $snapshot['output']['articlesByYear'][$year];
				}
				return null;
			case 'issuesPerYear':
				if ($year !== null && isset($snapshot['output']['issuesByYear'][$year])) {
					return $snapshot['output']['issuesByYear'][$year];
				}
				return null;
			case 'archiveDepth':
				return isset($snapshot['output']['archiveYears']) ? $snapshot['output']['archiveYears'] : null;
		}
		return null;
	}

	/**
	 * Small-sample suppression (R1 / architecture decision 9): true when a
	 * rate or duration metric rests on fewer than $threshold records and
	 * must therefore not be published on public surfaces (public page
	 * cards, sidebar block, {journal_metric}).
	 */
	public function isSuppressed($snapshot, $metricKey, $threshold) {
		$threshold = max(1, (int) $threshold);
		if (!is_array($snapshot)) return true;

		if (in_array($metricKey, array('acceptanceRate', 'declineRate'), true)) {
			$allTime = isset($snapshot['editorial']['allTime']) ? $snapshot['editorial']['allTime'] : array();
			$decided = (isset($allTime['submissionsAccepted']) ? (int) $allTime['submissionsAccepted'] : 0)
				+ (isset($allTime['submissionsDeclined']) ? (int) $allTime['submissionsDeclined'] : 0);
			return $decided < $threshold;
		}

		$timeKeys = array(
			'firstDecisionDays', 'submissionToAcceptDays', 'submissionToRejectDays',
			'reviewReportDays', 'acceptToPublishDays', 'submissionToPublishDays',
		);
		if (in_array($metricKey, $timeKeys, true)) {
			$n = isset($snapshot['times'][$metricKey]['n']) ? (int) $snapshot['times'][$metricKey]['n'] : 0;
			return $n < $threshold;
		}

		return false;
	}

	// ------------------------------------------------------------------
	//  Write
	// ------------------------------------------------------------------

	/**
	 * Merge one computed group into the stored snapshot.
	 *
	 * @param int $contextId
	 * @param string $group one of self::$GROUPS
	 * @param mixed $payload the provider's compute() result
	 * @param int $durationMs
	 * @return array [snapshot, warning|null, sizeBytes] warning is a
	 *  human-readable string when the encoded snapshot exceeds the 48 KB
	 *  threshold (also written to error_log); the size is returned so the
	 *  scheduled task can record it in its execution log.
	 */
	public function updateGroup($contextId, $group, $payload, $durationMs) {
		$contextId = (int) $contextId;
		$snapshot = $this->getSnapshot($contextId);
		if (!is_array($snapshot)) {
			$snapshot = array(
				'schemaVersion'   => JOURNAL_METRICS_SCHEMA_VERSION,
				'generatedAt'     => null,
				'contextId'       => $contextId,
				'durations'       => array(),
				'groupsUpdatedAt' => array(),
			);
		}

		$snapshot['schemaVersion'] = JOURNAL_METRICS_SCHEMA_VERSION;
		$snapshot['contextId'] = $contextId;
		$snapshot[$group] = $payload;
		$snapshot['durations'][$group . 'Ms'] = (int) $durationMs;
		$snapshot['groupsUpdatedAt'][$group] = $this->_now();

		// Copy the visibility map into the snapshot on every write so the
		// public page reads a single JSON document (architecture decision 6).
		$snapshot['visibility'] = $this->_plugin->getVisibilityMap($contextId);

		// Coverage declaration (backward-compatible root field within
		// schemaVersion 1): written only when a coverage year is set, so
		// older snapshots and the no-coverage default stay byte-identical.
		$coverageYear = $this->_plugin->getCoverageStartYear($contextId);
		if ($coverageYear > 0) {
			$snapshot['coverage'] = array('editorialStartYear' => $coverageYear);
		} else {
			unset($snapshot['coverage']);
		}

		// Full-cycle stamp: set generatedAt once all groups are present.
		if ($this->isComplete($snapshot)) {
			$snapshot['generatedAt'] = $this->_now();
		}

		$warning = null;
		$size = strlen(json_encode($snapshot, JSON_UNESCAPED_UNICODE));
		if ($size > JOURNAL_METRICS_SNAPSHOT_WARN_BYTES) {
			$warning = sprintf(
				'Journal Metrics snapshot for context %d is %d bytes (> %d). plugin_settings holds TEXT (64 KB); consider trimming series/top lists.',
				$contextId, $size, JOURNAL_METRICS_SNAPSHOT_WARN_BYTES
			);
			error_log('[journalMetrics] WARNING: ' . $warning);
		}

		$this->_plugin->updateSetting($contextId, 'metricsSnapshot', $snapshot, 'object');
		return array($snapshot, $warning, $size);
	}

	/**
	 * Refresh only the visibility section (called after settings saves so
	 * visibility changes reach the public page without a recompute).
	 */
	public function refreshVisibility($contextId) {
		$contextId = (int) $contextId;
		$snapshot = $this->getSnapshot($contextId);
		if (!is_array($snapshot)) return;
		$snapshot['visibility'] = $this->_plugin->getVisibilityMap($contextId);
		$this->_plugin->updateSetting($contextId, 'metricsSnapshot', $snapshot, 'object');
	}

	// ------------------------------------------------------------------
	//  Work queue (chunked nightly runs — R4)
	// ------------------------------------------------------------------

	/**
	 * The pending work queue, stored site-wide (context 0).
	 * @return array list of [contextId, group] pairs
	 */
	public function getQueue() {
		$queue = $this->_plugin->getSetting(0, 'snapshotQueue');
		return is_array($queue) ? $queue : array();
	}

	/**
	 * @param array $queue list of [contextId, group] pairs
	 */
	public function saveQueue($queue) {
		$this->_plugin->updateSetting(0, 'snapshotQueue', array_values($queue), 'object');
	}

	/**
	 * Build a fresh full-cycle queue: every enabled journal × every group,
	 * fast groups first per journal, expensive groups afterwards.
	 *
	 * @param array $contextIds
	 * @param array $fastGroups
	 * @param array $expensiveGroups
	 * @return array
	 */
	public function buildQueue($contextIds, $fastGroups, $expensiveGroups) {
		$queue = array();
		foreach ($contextIds as $contextId) {
			foreach ($fastGroups as $group) {
				$queue[] = array((int) $contextId, $group);
			}
		}
		foreach ($contextIds as $contextId) {
			foreach ($expensiveGroups as $group) {
				$queue[] = array((int) $contextId, $group);
			}
		}
		return $queue;
	}

	/**
	 * Queue the expensive groups of one journal (used by the "recompute
	 * now" button: fast groups run synchronously, usage is deferred to the
	 * next scheduled run).
	 *
	 * @param int $contextId
	 * @param array $expensiveGroups
	 */
	public function queueExpensive($contextId, $expensiveGroups) {
		$queue = $this->getQueue();
		foreach ($expensiveGroups as $group) {
			$entry = array((int) $contextId, $group);
			if (!in_array($entry, $queue)) {
				array_unshift($queue, $entry);
			}
		}
		$this->saveQueue($queue);
	}

	/**
	 * Current timestamp in ISO 8601.
	 */
	private function _now() {
		return date('c');
	}
}
