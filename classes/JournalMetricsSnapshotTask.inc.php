<?php
/**
 * @file plugins/generic/journalMetrics/classes/JournalMetricsSnapshotTask.inc.php
 *
 * Copyright (c) 2026 ojs-services.com
 * Distributed under the GNU GPL v3.
 *
 * @class JournalMetricsSnapshotTask
 * @brief Chunked nightly snapshot computation (architecture decision 5 / R4).
 *
 * Acron executes scheduled tasks in the shutdown phase of a web request,
 * bound by PHP max_execution_time. This task therefore works through a
 * persistent queue of (journal × provider-group) work items and stops
 * after a fixed time budget, saving the snapshot after EVERY item. The
 * next run (acron or real cron via tools/runScheduledTasks.php) resumes
 * where it left off. A full pass over all journals may span several runs;
 * that is by design.
 */

import('lib.pkp.classes.scheduledTask.ScheduledTask');

class JournalMetricsSnapshotTask extends ScheduledTask {

	/** Soft time budget per run, in seconds */
	const TIME_BUDGET = 20;

	/** A run lock older than this is considered stale, in seconds */
	const LOCK_TIMEOUT = 600;

	/**
	 * @copydoc ScheduledTask::getName()
	 */
	public function getName() {
		return __('plugins.generic.journalMetrics.task.name');
	}

	/**
	 * @copydoc ScheduledTask::executeActions()
	 */
	protected function executeActions() {
		$plugin = PluginRegistry::getPlugin('generic', 'journalmetricsplugin');
		if (!$plugin) {
			// Lazy-load plugins are not in the registry during scheduled
			// task runs; load the category to resolve it.
			PluginRegistry::loadCategory('generic');
			$plugin = PluginRegistry::getPlugin('generic', 'journalmetricsplugin');
		}
		// NOTE: no global $plugin->getEnabled() gate here — it resolves
		// against the CURRENT request context (null in CLI/cron, one
		// journal under acron) and would wrongly skip other journals'
		// work. Enablement is enforced per journal in _getEnabledContextIds().
		if (!$plugin) {
			return true;
		}

		$manager = $plugin->getSnapshotManager();
		$registry = $plugin->getProviderRegistry();

		// Run lock (L2): acron may fire from two overlapping web requests;
		// only one runner may drain the queue at a time. plugin_settings
		// offers no atomic compare-and-swap, so this is a best-effort lock
		// with a stale timeout — good enough to stop double computation.
		$runId = uniqid('jmx', true);
		$lock = $plugin->getSetting(0, 'snapshotRunLock');
		if (is_array($lock) && isset($lock['startedAt'])
				&& (time() - (int) $lock['startedAt']) < self::LOCK_TIMEOUT) {
			$this->addExecutionLogEntry(
				'Journal Metrics: another snapshot run is in progress; skipping.',
				SCHEDULED_TASK_MESSAGE_TYPE_NOTICE
			);
			return true;
		}
		$plugin->updateSetting(0, 'snapshotRunLock', array('runId' => $runId, 'startedAt' => time()), 'object');

		try {
			return $this->_drainQueue($plugin, $manager, $registry, $runId);
		} finally {
			// Release only our own lock (a stale-takeover may have replaced it)
			$lock = $plugin->getSetting(0, 'snapshotRunLock');
			if (is_array($lock) && isset($lock['runId']) && $lock['runId'] === $runId) {
				$plugin->updateSetting(0, 'snapshotRunLock', array(), 'object');
			}
		}
	}

	/**
	 * Work through the (journal × group) queue within the time budget.
	 */
	private function _drainQueue($plugin, $manager, $registry, $runId) {
		$queue = $manager->getQueue();
		if (empty($queue)) {
			// Start a new full cycle only when the last completed cycle is
			// older than ~20 hours — the task itself fires hourly so that
			// unfinished cycles can progress, but a full recomputation
			// happens once per day.
			$lastCycle = $plugin->getSetting(0, 'snapshotCycleCompletedAt');
			if ($lastCycle && (time() - strtotime($lastCycle)) < 20 * 3600) {
				return true;
			}
			$queue = $manager->buildQueue(
				$this->_getEnabledContextIds($plugin),
				$registry->getGroups(true),
				array_diff($registry->getGroups(false), $registry->getGroups(true))
			);
			if (empty($queue)) {
				$this->addExecutionLogEntry(
					'Journal Metrics: no journals with the plugin enabled; nothing to do.',
					SCHEDULED_TASK_MESSAGE_TYPE_NOTICE
				);
				return true;
			}
			$this->addExecutionLogEntry(
				sprintf('Journal Metrics: starting a new snapshot cycle (%d work items).', count($queue)),
				SCHEDULED_TASK_MESSAGE_TYPE_NOTICE
			);
		}

		$startedAt = microtime(true);
		$done = 0;

		while (!empty($queue)) {
			list($contextId, $group) = $queue[0];
			$provider = $registry->getProvider($group);
			if (!$provider) {
				array_shift($queue); // unknown group (stale queue) — drop
				continue;
			}

			try {
				$t0 = microtime(true);
				$payload = $provider->compute($contextId);
				$durationMs = (int) round((microtime(true) - $t0) * 1000);
				list(, $warning, $sizeBytes) = $manager->updateGroup($contextId, $group, $payload, $durationMs);
				if ($warning !== null) {
					$this->addExecutionLogEntry($warning, SCHEDULED_TASK_MESSAGE_TYPE_WARNING);
				}
				$this->addExecutionLogEntry(
					sprintf('Journal Metrics: context %d group "%s" computed in %d ms (snapshot %d bytes).', $contextId, $group, $durationMs, $sizeBytes),
					SCHEDULED_TASK_MESSAGE_TYPE_NOTICE
				);
			} catch (Throwable $e) {
				$this->addExecutionLogEntry(
					sprintf('Journal Metrics: context %d group "%s" FAILED: %s', $contextId, $group, $e->getMessage()),
					SCHEDULED_TASK_MESSAGE_TYPE_ERROR
				);
			}

			array_shift($queue);
			$manager->saveQueue($queue);
			$done++;

			if ((microtime(true) - $startedAt) > self::TIME_BUDGET && !empty($queue)) {
				$this->addExecutionLogEntry(
					sprintf('Journal Metrics: time budget reached after %d items; %d items remain for the next run.', $done, count($queue)),
					SCHEDULED_TASK_MESSAGE_TYPE_NOTICE
				);
				return true;
			}
		}

		$plugin->updateSetting(0, 'snapshotCycleCompletedAt', date('c'), 'string');
		$this->addExecutionLogEntry(
			sprintf('Journal Metrics: snapshot cycle completed (%d items this run).', $done),
			SCHEDULED_TASK_MESSAGE_TYPE_COMPLETED
		);
		return true;
	}

	/**
	 * IDs of enabled journals where the plugin is enabled.
	 */
	private function _getEnabledContextIds($plugin) {
		$contextIds = array();
		$contextDao = Application::getContextDAO();
		$contexts = $contextDao->getAll(true); // enabled only
		while ($context = $contexts->next()) {
			if ($plugin->getSetting($context->getId(), 'enabled')) {
				$contextIds[] = (int) $context->getId();
			}
		}
		return $contextIds;
	}
}
