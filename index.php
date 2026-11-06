<?php
/**
 * @defgroup plugins_generic_journalMetrics Journal Metrics Plugin
 */

/**
 * @file plugins/generic/journalMetrics/index.php
 *
 * Copyright (c) 2026 ojs-services.com
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @ingroup plugins_generic_journalMetrics
 * @brief Wrapper for the Journal Metrics plugin.
 *
 * OJS loads the class through Composer (PSR-4). The explicit require covers
 * one case: installing over a site upgraded from OJS 3.3 where the plugin
 * was enabled. Earlier in that same upload request OJS looks the class up
 * before the files are copied, Composer remembers it as missing, and the
 * installer then could not load the plugin (OJS 3.4 stops with a fatal
 * error).
 */

if (!class_exists('APP\plugins\generic\journalMetrics\JournalMetricsPlugin', false)) {
	require_once __DIR__ . '/JournalMetricsPlugin.php';
}

return new \APP\plugins\generic\journalMetrics\JournalMetricsPlugin();
