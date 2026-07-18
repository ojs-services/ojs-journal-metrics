<?php
/**
 * @file plugins/generic/journalMetrics/JournalMetricsBlockPlugin.inc.php
 *
 * Copyright (c) 2026 ojs-services.com
 * Distributed under the GNU GPL v3.
 *
 * @class JournalMetricsBlockPlugin
 * @brief Sidebar block: a compact list of PUBLIC metrics read from the
 *  snapshot (single JSON read, zero computation at render time).
 */

import('lib.pkp.classes.plugins.BlockPlugin');

class JournalMetricsBlockPlugin extends BlockPlugin {

	/** @var JournalMetricsPlugin */
	protected $_parentPlugin;

	// The shown metrics come from the editor's selection
	// (JournalMetricsPlugin::getBlockMetrics), whitelisted against
	// getBlockMetricCandidates(). archiveDepth is rendered in block.tpl
	// via {journal_metric} and obeys the same selection.

	/**
	 * @param JournalMetricsPlugin $parentPlugin
	 */
	public function __construct($parentPlugin) {
		$this->_parentPlugin = $parentPlugin;
		parent::__construct();
	}

	/**
	 * Hide this plugin from the management interface (it's subsidiary).
	 */
	public function getHideManagement() {
		return true;
	}

	// getName() is deliberately NOT overridden: LazyLoadPlugin resolves it
	// to the lowercased class name ('journalmetricsblockplugin'), which is
	// what the sidebar management UI stores in the context 'sidebar' setting.

	/**
	 * @copydoc Plugin::getDisplayName()
	 */
	public function getDisplayName() {
		return __('plugins.generic.journalMetrics.block.displayName');
	}

	/**
	 * @copydoc Plugin::getDescription()
	 */
	public function getDescription() {
		return __('plugins.generic.journalMetrics.block.description');
	}

	/**
	 * Override the builtin to get the correct plugin path.
	 */
	public function getPluginPath() {
		return $this->_parentPlugin->getPluginPath();
	}

	/**
	 * @copydoc BlockPlugin::getContents()
	 */
	public function getContents($templateMgr, $request = null) {
		$context = $request ? $request->getContext() : null;
		if (!$context) return '';

		$plugin = $this->_parentPlugin;
		$manager = $plugin->getSnapshotManager();
		$snapshot = $manager->getSnapshot($context->getId());
		if (!$snapshot) return '';

		// Editor-selected metrics only; selection never bypasses the
		// public-visibility or small-sample filters below.
		$selection = $plugin->getBlockMetrics($context->getId());
		if (empty($selection)) return ''; // deliberate empty selection

		$nThreshold = (int) $plugin->getSettingWithDefault($context->getId(), 'rateNThreshold');
		$items = array();
		foreach ($selection as $key) {
			if ($key === 'archiveDepth') continue; // rendered in block.tpl
			if ($manager->getVisibility($snapshot, $key) !== 'public') continue;
			if ($manager->isSuppressed($snapshot, $key, $nThreshold)) continue;
			$value = $manager->lookupMetricValue($snapshot, $key);
			if ($value === null || $value === '') continue;
			if ($key === 'acceptanceRate') {
				$value = round(((float) $value) * 100, 1) . '%';
			} elseif (is_numeric($value) && (int) $value == $value) {
				$value = number_format((int) $value);
			}
			$items[] = array(
				'label' => __('plugins.generic.journalMetrics.metric.' . $key),
				'value' => $value,
			);
		}

		// archiveDepth goes through {journal_metric} in the template
		// (which enforces the public filter itself); resolve it here only
		// to decide whether anything will render at all.
		$showArchive = in_array('archiveDepth', $selection, true);
		$archiveValue = $showArchive
			? $plugin->smartyJournalMetric(array('key' => 'archiveDepth'), $templateMgr)
			: '';
		if (empty($items) && $archiveValue === '') return ''; // never an empty box

		$publicPageUrl = null;
		if ($plugin->getSetting($context->getId(), 'enablePublicPage')) {
			$publicPageUrl = $request->getDispatcher()->url(
				$request, ROUTE_PAGE, null, 'journalmetrics', 'publicpage'
			);
		}

		$templateMgr->assign(array(
			'jmxBlockItems'       => $items,
			'jmxBlockShowArchive' => $showArchive,
			'jmxBlockMoreUrl'     => $publicPageUrl,
		));
		return parent::getContents($templateMgr, $request);
	}
}
