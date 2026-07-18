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
 */

require_once('JournalMetricsPlugin.inc.php');

return new JournalMetricsPlugin();
