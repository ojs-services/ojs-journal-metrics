<?php
/**
 * @file plugins/generic/journalMetrics/classes/providers/JournalMetricsManualProvider.inc.php
 *
 * Copyright (c) 2026 ojs-services.com
 * Distributed under the GNU GPL v3.
 *
 * @class JournalMetricsManualProvider
 * @brief G6 manual metrics: copies the editor-declared rows from the
 *  plugin settings into the snapshot so the public page reads ONE JSON.
 *
 * The rows themselves are maintained in the settings form (max 5 rows;
 * title / value / source / description; lastUpdated is stamped server-side
 * on save). Manual values are never computed, never charted, and are
 * displayed with an "editor-declared" badge on public surfaces.
 */

import('plugins.generic.journalMetrics.classes.providers.JournalMetricsBaseProvider');

class JournalMetricsManualProvider extends JournalMetricsBaseProvider {

	/**
	 * @copydoc JournalMetricsBaseProvider::getGroup()
	 */
	public function getGroup() {
		return 'manual';
	}

	/**
	 * @copydoc JournalMetricsBaseProvider::compute()
	 */
	public function compute($contextId) {
		$rows = $this->_plugin->getManualMetrics((int) $contextId);
		$clean = array();
		foreach ($rows as $row) {
			if (!is_array($row)) continue;
			// title/description are multilingual (locale => string); legacy
			// plain strings pass through unchanged and are resolved at
			// render time by JournalMetricsPlugin::localize().
			$title = isset($row['title']) ? $this->_trimField($row['title']) : '';
			if ($this->_isEmptyField($title)) continue;
			$clean[] = array(
				'title'       => $title,
				'value'       => isset($row['value']) ? trim((string) $row['value']) : '',
				'source'      => isset($row['source']) ? trim((string) $row['source']) : '',
				'description' => isset($row['description']) ? $this->_trimField($row['description']) : '',
				'lastUpdated' => isset($row['lastUpdated']) ? (string) $row['lastUpdated'] : '',
			);
		}
		return $clean;
	}

	/**
	 * Trim a plain or multilingual (locale => string) field.
	 */
	private function _trimField($value) {
		if (!is_array($value)) return trim((string) $value);
		foreach ($value as $locale => $translation) {
			$value[$locale] = trim((string) $translation);
		}
		return $value;
	}

	/**
	 * True when a plain or multilingual field has no content at all.
	 */
	private function _isEmptyField($value) {
		if (!is_array($value)) return trim((string) $value) === '';
		return trim(implode('', $value)) === '';
	}
}
