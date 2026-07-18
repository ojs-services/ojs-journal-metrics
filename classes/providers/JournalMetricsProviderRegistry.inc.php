<?php
/**
 * @file plugins/generic/journalMetrics/classes/providers/JournalMetricsProviderRegistry.inc.php
 *
 * Copyright (c) 2026 ojs-services.com
 * Distributed under the GNU GPL v3.
 *
 * @class JournalMetricsProviderRegistry
 * @brief Ordered registry of all metric providers.
 *
 * Fast (cheap) providers come first so a fresh install gets a usable
 * snapshot on the first chunked run; the expensive usage provider runs
 * last and may be deferred across runs (architecture decision 5 / R4).
 */

class JournalMetricsProviderRegistry {

	/** @var JournalMetricsPlugin */
	private $_plugin;

	/** @var array|null */
	private $_providers = null;

	public function __construct($plugin) {
		$this->_plugin = $plugin;
	}

	/**
	 * All providers in execution order.
	 * @return JournalMetricsBaseProvider[] keyed by group
	 */
	public function getProviders() {
		if ($this->_providers === null) {
			$classes = array(
				'JournalMetricsEditorialProvider',
				'JournalMetricsTimesProvider',
				'JournalMetricsCommunityProvider',
				'JournalMetricsOutputProvider',
				'JournalMetricsManualProvider',
				'JournalMetricsUsageProvider', // expensive; always last
			);
			$this->_providers = array();
			foreach ($classes as $class) {
				$this->_plugin->import('classes.providers.' . $class);
				$provider = new $class($this->_plugin);
				$this->_providers[$provider->getGroup()] = $provider;
			}
		}
		return $this->_providers;
	}

	/**
	 * @param string $group
	 * @return JournalMetricsBaseProvider|null
	 */
	public function getProvider($group) {
		$providers = $this->getProviders();
		return isset($providers[$group]) ? $providers[$group] : null;
	}

	/**
	 * Group keys in execution order.
	 * @param boolean $fastOnly exclude expensive providers
	 * @return array
	 */
	public function getGroups($fastOnly = false) {
		$groups = array();
		foreach ($this->getProviders() as $group => $provider) {
			if ($fastOnly && $provider->isExpensive()) continue;
			$groups[] = $group;
		}
		return $groups;
	}
}
