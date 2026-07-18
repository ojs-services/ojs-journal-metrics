<?php
/**
 * @file plugins/generic/journalMetrics/JournalMetricsHandler.inc.php
 *
 * Copyright (c) 2026 ojs-services.com
 * Distributed under the GNU GPL v3.
 *
 * @class JournalMetricsHandler
 * @brief Request handler for Journal Metrics pages
 *  (admin dashboard, settings, public page, country AJAX, recompute).
 */

import('classes.handler.Handler');

class JournalMetricsHandler extends Handler {

	/** @var JournalMetricsPlugin */
	protected $_plugin;

	public function __construct() {
		parent::__construct();

		// Admin operations require Manager or Site Admin role
		$this->addRoleAssignment(
			array(ROLE_ID_SITE_ADMIN, ROLE_ID_MANAGER),
			array('dashboard', 'settings', 'saveSettings', 'fetchData', 'recompute')
		);

		// publicpage is NOT assigned to any role — authorize() handles it
	}

	/**
	 * @copydoc PKPHandler::authorize()
	 */
	public function authorize($request, &$args, $roleAssignments) {
		$op = $request->getRouter()->getRequestedOp($request);

		import('lib.pkp.classes.security.authorization.ContextRequiredPolicy');
		$this->addPolicy(new ContextRequiredPolicy($request));

		if ($op === 'publicpage') {
			$this->markRoleAssignmentsChecked();
			return parent::authorize($request, $args, array());
		}

		import('lib.pkp.classes.security.authorization.PolicySet');
		$rolePolicy = new PolicySet(COMBINING_PERMIT_OVERRIDES);

		import('lib.pkp.classes.security.authorization.RoleBasedHandlerOperationPolicy');
		foreach ($roleAssignments as $role => $operations) {
			$rolePolicy->addPolicy(
				new RoleBasedHandlerOperationPolicy($request, $role, $operations)
			);
		}
		$this->addPolicy($rolePolicy);

		return parent::authorize($request, $args, $roleAssignments);
	}

	/**
	 * @return JournalMetricsPlugin
	 */
	protected function _getPlugin() {
		if (!$this->_plugin) {
			$this->_plugin = PluginRegistry::getPlugin('generic', 'journalmetricsplugin');
		}
		return $this->_plugin;
	}

	// ------------------------------------------------------------------
	//  Admin Dashboard
	// ------------------------------------------------------------------

	/**
	 * Display the admin metrics dashboard.
	 */
	public function dashboard($args, $request) {
		$this->_setupTemplate($request, true);

		$plugin = $this->_getPlugin();
		$context = $request->getContext();
		$contextId = $context->getId();

		$manager = $plugin->getSnapshotManager();
		$snapshot = $manager->getSnapshot($contextId);

		// First visit on a fresh install: compute the fast groups
		// synchronously (seconds) and defer usage to the scheduled task.
		if ($snapshot === null) {
			$this->_computeFastGroups($contextId);
			$snapshot = $manager->getSnapshot($contextId);
		}

		$viewModel = $this->_buildViewModel($snapshot, $contextId, false);
		$settings = $plugin->getAllSettings($contextId);
		$visibilityMap = $plugin->getVisibilityMap($contextId);

		$templateMgr = TemplateManager::getManager($request);
		$templateMgr->assign(array(
			'pageWidth'    => 'full',
			'showCountrySection' => ($visibilityMap['authorCountries'] !== 'hidden'),
			'pluginPath'   => $request->getBaseUrl() . '/' . $plugin->getPluginPath(),
			'assetVersion' => $this->_assetVersion(),
			'jmxData'      => json_encode($viewModel, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
			'jmxSettings'  => $settings,
			'isAdmin'      => true,
			'generatedAt'  => $viewModel['generatedAt'],
			'usagePending' => $viewModel['usagePending'],
			'coverageYear' => $viewModel['coverageYear'],
			'csrfToken'    => $request->getSession()->getCSRFToken(),
		));

		$templateMgr->display($plugin->getTemplateResource('dashboard.tpl'));
	}

	// ------------------------------------------------------------------
	//  Settings page (full backend page — no modal)
	// ------------------------------------------------------------------

	/**
	 * Display the plugin settings as a full backend page.
	 */
	public function settings($args, $request) {
		$this->_setupTemplate($request, true);

		$plugin = $this->_getPlugin();
		$context = $request->getContext();
		$contextId = $context->getId();

		$base = 'plugins.generic.journalMetrics.';

		// Visibility matrix rows: metric key -> [label, group label]
		$matrix = array();
		foreach ($plugin->getMetricCatalog() as $key => $meta) {
			$matrix[$key] = array(
				'label' => __($base . 'metric.' . $key),
				'group' => __($base . 'group.' . $meta['group']),
			);
		}

		// Journal locales for multilingual fields (public page title,
		// manual metric title/description) — primary locale first.
		$supportedLocales = $plugin->getSupportedLocaleNames($context);
		$primaryLocale = $context->getPrimaryLocale();

		// Normalize the stored public page title to one entry per locale
		// (legacy plain strings land on the primary locale).
		$settings = $plugin->getAllSettings($contextId);
		$storedTitle = $settings['publicPageTitle'];
		$titleByLocale = array();
		foreach (array_keys($supportedLocales) as $locale) {
			if (is_array($storedTitle)) {
				$titleByLocale[$locale] = isset($storedTitle[$locale]) ? (string) $storedTitle[$locale] : '';
			} else {
				$titleByLocale[$locale] = ($locale === $primaryLocale) ? (string) $storedTitle : '';
			}
		}

		// Normalize manual rows: title/description as locale => value maps
		$manualRows = array();
		foreach ($plugin->getManualMetrics($contextId) as $row) {
			foreach (array('title', 'description') as $field) {
				$value = isset($row[$field]) ? $row[$field] : '';
				$byLocale = array();
				foreach (array_keys($supportedLocales) as $locale) {
					if (is_array($value)) {
						$byLocale[$locale] = isset($value[$locale]) ? (string) $value[$locale] : '';
					} else {
						$byLocale[$locale] = ($locale === $primaryLocale) ? (string) $value : '';
					}
				}
				$row[$field] = $byLocale;
			}
			$manualRows[] = $row;
		}

		// Sidebar block status: read the context's own 'sidebar' setting
		// live — OJS's sidebar management is the single source of truth
		// (the plugin has no enable/disable switch of its own).
		$sidebarBlocks = (array) $context->getData('sidebar');
		$blockActive = in_array('journalmetricsblockplugin', $sidebarBlocks, true);

		// Candidate metrics for the block + current selection
		$blockCandidates = array();
		foreach ($plugin->getBlockMetricCandidates() as $key) {
			$blockCandidates[$key] = __($base . 'metric.' . $key);
		}
		$blockSelection = $plugin->getBlockMetrics($contextId);

		$dispatcher = $request->getDispatcher();
		$templateMgr = TemplateManager::getManager($request);
		$templateMgr->assign(array(
			'pluginPath'    => $request->getBaseUrl() . '/' . $plugin->getPluginPath(),
			'assetVersion'  => $this->_assetVersion(),
			'jmxSettings'   => $settings,
			'visibility'    => $plugin->getVisibilityMap($contextId),
			'manualMetrics' => $manualRows,
			'metricMatrix'  => $matrix,
			'manualMaxRows' => 5,
			'supportedLocales' => $supportedLocales,
			'publicPageTitleByLocale' => $titleByLocale,
			'coverageStartYear' => $plugin->getCoverageStartYear($contextId),
			'currentYear'       => (int) date('Y'),
			'blockActive'      => $blockActive,
			'blockCandidates'  => $blockCandidates,
			'blockSelection'   => $blockSelection,
			// Deep anchor into Website Settings → Appearance → Setup, where
			// the sidebar is managed. Appended manually: the router would
			// urlencode the "/" inside the anchor and break the Vue tab.
			'sidebarSettingsUrl' => $dispatcher->url($request, ROUTE_PAGE, null, 'management', 'settings', array('website')) . '#appearance/appearance-setup',
			'saveUrl'       => $dispatcher->url($request, ROUTE_PAGE, null, 'journalmetrics', 'saveSettings'),
			'dashboardUrl'  => $dispatcher->url($request, ROUTE_PAGE, null, 'journalmetrics', 'dashboard'),
			'recomputeUrl'  => $dispatcher->url($request, ROUTE_PAGE, null, 'journalmetrics', 'recompute'),
			'publicPageUrl' => $dispatcher->url($request, ROUTE_PAGE, null, 'journalmetrics', 'publicpage'),
			'justSaved'     => (bool) $request->getUserVar('saved'),
			'uniqueStrategyOptions' => array(
				'orcid' => __($base . 'filter.orcid'),
				'email' => __($base . 'filter.email'),
				'name'  => __($base . 'filter.fullName'),
			),
			'orcidFallbackOptions' => array(
				'email_name' => __($base . 'settings.orcidFallback.emailName'),
				'email'      => __($base . 'settings.orcidFallback.email'),
				'name'       => __($base . 'settings.orcidFallback.name'),
				'none'       => __($base . 'settings.orcidFallback.none'),
			),
			'countryDisplayOptions' => array(
				'both' => __($base . 'settings.countryDisplay.both'),
				'code' => __($base . 'settings.countryDisplay.code'),
				'name' => __($base . 'settings.countryDisplay.name'),
			),
			'sortOrderOptions' => array(
				'count_desc' => __($base . 'settings.sortOrder.countDesc'),
				'name_asc'   => __($base . 'settings.sortOrder.nameAsc'),
			),
			'visibilityOptions' => array(
				'hidden' => __($base . 'settings.visibility.hidden'),
				'admin'  => __($base . 'settings.visibility.admin'),
				'public' => __($base . 'settings.visibility.public'),
			),
		));

		$templateMgr->display($plugin->getTemplateResource('settingsPage.tpl'));
	}

	/**
	 * Persist the settings page (POST + CSRF), then redirect back.
	 */
	public function saveSettings($args, $request) {
		if (!$request->isPost() || !$request->checkCSRF()) {
			$request->getDispatcher()->handle404();
			return;
		}

		$context = $request->getContext();
		$this->_persistSettings($request, $context);

		$request->redirect(null, 'journalmetrics', 'settings', null, array('saved' => 1));
	}

	/**
	 * Sanitize + save every plugin setting from the request.
	 * (Former JournalMetricsSettingsForm::execute logic, modal-free.)
	 */
	protected function _persistSettings($request, $context) {
		$plugin = $this->_getPlugin();
		$contextId = $context->getId();
		$supportedLocales = array_keys($plugin->getSupportedLocaleNames($context));
		$primaryLocale = $context->getPrimaryLocale();

		$oldCoverage = $plugin->getCoverageStartYear($contextId);

		// ---- Scalar settings
		$settingsToSave = array(
			'uniqueStrategy'   => array('type' => 'string', 'default' => 'orcid',
				'allowed' => array('orcid', 'email', 'name')),
			'orcidFallback'    => array('type' => 'string', 'default' => 'email_name',
				'allowed' => array('email_name', 'email', 'name', 'none')),
			'countryDisplay'   => array('type' => 'string', 'default' => 'both',
				'allowed' => array('both', 'code', 'name')),
			'sortOrder'        => array('type' => 'string', 'default' => 'count_desc',
				'allowed' => array('count_desc', 'name_asc')),
			'minThreshold'     => array('type' => 'int',    'default' => 1),
			'includeUnknown'   => array('type' => 'bool',   'default' => false),
			'enablePublicPage' => array('type' => 'bool',   'default' => false),
			'showDeveloperCredit' => array('type' => 'bool', 'default' => true),
			'showAuthorsTab'   => array('type' => 'bool',   'default' => true),
			'showArticlesTab'  => array('type' => 'bool',   'default' => true),
			'rateNThreshold'   => array('type' => 'int',    'default' => 10),
		);

		foreach ($settingsToSave as $name => $meta) {
			$value = $request->getUserVar($name);
			switch ($meta['type']) {
				case 'int':
					$value = max(0, (int) $value);
					break;
				case 'bool':
					$value = $value ? 1 : 0;
					break;
				case 'string':
				default:
					$value = trim(strip_tags((string) $value));
					if ($value === '') $value = $meta['default'];
					if (isset($meta['allowed']) && !in_array($value, $meta['allowed'], true)) {
						$value = $meta['default'];
					}
					break;
			}
			$plugin->updateSetting($contextId, $name, $value, $meta['type'] === 'int' ? 'int' : 'string');
		}

		// ---- Public page title: one value per supported journal locale
		$postedTitle = $request->getUserVar('publicPageTitle');
		$titleByLocale = array();
		foreach ($supportedLocales as $locale) {
			$value = '';
			if (is_array($postedTitle) && isset($postedTitle[$locale])) {
				$value = trim(strip_tags((string) $postedTitle[$locale]));
			} elseif (!is_array($postedTitle) && $locale === $primaryLocale) {
				$value = trim(strip_tags((string) $postedTitle));
			}
			if (function_exists('mb_substr')) $value = mb_substr($value, 0, 120);
			$titleByLocale[$locale] = $value;
		}
		if (trim(implode('', $titleByLocale)) === '') {
			$titleByLocale[$primaryLocale] = 'Journal Metrics';
		}
		$plugin->updateSetting($contextId, 'publicPageTitle', $titleByLocale, 'object');

		// ---- Editorial statistics coverage start year: 0 (no coverage)
		//      or a plausible year; anything else falls back to 0.
		$coverage = (int) trim(strip_tags((string) $request->getUserVar('statsCoverageStartYear')));
		if ($coverage !== 0 && ($coverage < 1900 || $coverage > (int) date('Y'))) $coverage = 0;
		$plugin->updateSetting($contextId, 'statsCoverageStartYear', $coverage, 'int');

		// ---- Sidebar block metric selection (whitelist: candidate keys
		//      only; an empty selection is valid and hides the block)
		$postedBlock = $request->getUserVar('blockMetrics');
		$candidates = $plugin->getBlockMetricCandidates();
		$blockMetrics = array();
		if (is_array($postedBlock)) {
			$blockMetrics = array_values(array_intersect($candidates, array_map('strval', $postedBlock)));
		}
		$plugin->updateSetting($contextId, 'blockMetrics', $blockMetrics, 'object');

		// ---- Visibility matrix (only known metric keys, only known values)
		$posted = $request->getUserVar('visibility');
		$visibilityMap = array();
		foreach ($plugin->getMetricCatalog() as $key => $meta) {
			$value = (is_array($posted) && isset($posted[$key])) ? $posted[$key] : 'admin';
			if (!in_array($value, array('hidden', 'admin', 'public'), true)) $value = 'admin';
			$visibilityMap[$key] = $value;
		}
		$plugin->updateSetting($contextId, 'visibilityMap', $visibilityMap, 'object');

		// ---- Manual metrics (max 5 rows; sanitized; lastUpdated stamped
		//      server-side and only refreshed when the row content changed)
		$postedRows = $request->getUserVar('manualMetrics');
		$existing = $plugin->getManualMetrics($contextId);
		$existingByContent = array();
		foreach ($existing as $row) {
			$existingByContent[$this->_manualRowFingerprint($row)] = isset($row['lastUpdated']) ? $row['lastUpdated'] : '';
		}

		$rows = array();
		if (is_array($postedRows)) {
			foreach (array_values($postedRows) as $raw) {
				if (count($rows) >= 5) break;
				if (!is_array($raw)) continue;
				$row = array(
					'title'       => $this->_cleanManualLocalized($raw, 'title', 80, $supportedLocales, $primaryLocale),
					'value'       => $this->_cleanManualText($raw, 'value', 20),
					'source'      => $this->_cleanManualText($raw, 'source', 80),
					'description' => $this->_cleanManualLocalized($raw, 'description', 200, $supportedLocales, $primaryLocale),
				);
				// A row needs a title in at least one journal locale
				if (trim(implode('', $row['title'])) === '') continue;
				$fingerprint = $this->_manualRowFingerprint($row);
				$row['lastUpdated'] = isset($existingByContent[$fingerprint]) && $existingByContent[$fingerprint] !== ''
					? $existingByContent[$fingerprint]
					: date('Y-m-d');
				$rows[] = $row;
			}
		}
		$plugin->updateSetting($contextId, 'manualMetrics', $rows, 'object');

		// ---- Reflect changes in the snapshot immediately (manual group
		// copy also refreshes the embedded visibility map) so the public
		// page — a single JSON read — is current without a recompute.
		$manager = $plugin->getSnapshotManager();
		if ($manager->getSnapshot($contextId) !== null) {
			$plugin->import('classes.providers.JournalMetricsManualProvider');
			$provider = new JournalMetricsManualProvider($plugin);
			$manager->updateGroup($contextId, 'manual', $provider->compute($contextId), 0);

			// Coverage changed: the stored workflow-derived groups are now
			// stale. Recompute them synchronously so the coverage
			// declaration and the numbers change together — a half-updated
			// state must never be visible.
			if ($coverage !== $oldCoverage) {
				$registry = $plugin->getProviderRegistry();
				foreach (array('editorial', 'times', 'community') as $group) {
					$groupProvider = $registry->getProvider($group);
					if (!$groupProvider) continue;
					$t0 = microtime(true);
					$payload = $groupProvider->compute($contextId);
					$manager->updateGroup($contextId, $group, $payload, (int) round((microtime(true) - $t0) * 1000));
				}
			}
		}
	}

	/**
	 * Sanitize a manual metric text field.
	 */
	protected function _cleanManualText($raw, $field, $maxLen) {
		$value = isset($raw[$field]) ? $raw[$field] : '';
		if (is_array($value)) $value = reset($value); // defensive: unexpected array
		$value = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $value)));
		if (function_exists('mb_substr')) {
			return mb_substr($value, 0, $maxLen);
		}
		return substr($value, 0, $maxLen);
	}

	/**
	 * Sanitize a multilingual manual metric field: one cleaned value per
	 * supported journal locale (legacy plain strings land on the primary).
	 *
	 * @return array locale => string
	 */
	protected function _cleanManualLocalized($raw, $field, $maxLen, $supportedLocales, $primaryLocale) {
		$posted = isset($raw[$field]) ? $raw[$field] : array();
		$out = array();
		foreach ($supportedLocales as $locale) {
			$value = '';
			if (is_array($posted) && isset($posted[$locale])) {
				$value = $posted[$locale];
			} elseif (!is_array($posted) && $locale === $primaryLocale) {
				$value = $posted;
			}
			$value = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $value)));
			$out[$locale] = function_exists('mb_substr') ? mb_substr($value, 0, $maxLen) : substr($value, 0, $maxLen);
		}
		return $out;
	}

	/**
	 * Content fingerprint of a manual row (excludes lastUpdated).
	 */
	protected function _manualRowFingerprint($row) {
		return md5(json_encode(array(
			isset($row['title']) ? $row['title'] : '',
			isset($row['value']) ? $row['value'] : '',
			isset($row['source']) ? $row['source'] : '',
			isset($row['description']) ? $row['description'] : '',
		)));
	}

	// ------------------------------------------------------------------
	//  Public Page
	// ------------------------------------------------------------------

	/**
	 * Display the public metrics page. Renders ONLY metrics whose
	 * visibility is "public" — anything else is never emitted, not even
	 * hidden in markup (acceptance criterion 5 of the test strategy).
	 * The public page never triggers computation.
	 */
	public function publicpage($args, $request) {
		$plugin = $this->_getPlugin();
		$context = $request->getContext();
		$contextId = $context->getId();

		$settings = $plugin->getAllSettings($contextId);
		if (!$settings['enablePublicPage']) {
			$request->getDispatcher()->handle404();
			return;
		}

		$this->_setupTemplate($request, false);

		$manager = $plugin->getSnapshotManager();
		$snapshot = $manager->getSnapshot($contextId);
		$viewModel = $this->_buildViewModel($snapshot, $contextId, true);

		$templateMgr = TemplateManager::getManager($request);
		$templateMgr->assign(array(
			'pluginPath'   => $request->getBaseUrl() . '/' . $plugin->getPluginPath(),
			'assetVersion' => $this->_assetVersion(),
			'jmxData'      => json_encode($viewModel, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
			'jmxSettings'  => $settings,
			'showCountrySection' => !empty($viewModel['countrySection']),
			'isAdmin'      => false,
			'generatedAt'  => $viewModel['generatedAt'],
			'coverageYear' => $viewModel['coverageYear'],
			'showDeveloperCredit' => (bool) $settings['showDeveloperCredit'],
			'pageTitle'    => $plugin->localize($settings['publicPageTitle'], $context) ?: 'Journal Metrics',
		));

		$templateMgr->display($plugin->getTemplateResource('publicpage.tpl'));
	}

	// ------------------------------------------------------------------
	//  Recompute (admin, POST + CSRF)
	// ------------------------------------------------------------------

	/**
	 * "Recompute now": runs the fast provider groups synchronously and
	 * queues the expensive usage group for the next scheduled run
	 * (architecture decision 5).
	 */
	public function recompute($args, $request) {
		if (!$request->isPost() || !$request->checkCSRF()) {
			header('Content-Type: application/json; charset=utf-8');
			http_response_code(403);
			echo json_encode(array('status' => false, 'error' => 'csrf'));
			exit;
		}

		$contextId = $request->getContext()->getId();
		$computed = $this->_computeFastGroups($contextId);

		header('Content-Type: application/json; charset=utf-8');
		echo json_encode(array(
			'status'      => true,
			'computed'    => $computed,
			'usageQueued' => true,
		));
		exit;
	}

	/**
	 * Compute all fast (non-expensive) groups synchronously and queue the
	 * expensive ones. Returns the list of computed groups.
	 */
	protected function _computeFastGroups($contextId) {
		$plugin = $this->_getPlugin();
		$manager = $plugin->getSnapshotManager();
		$registry = $plugin->getProviderRegistry();

		$computed = array();
		foreach ($registry->getProviders() as $group => $provider) {
			if ($provider->isExpensive()) continue;
			$t0 = microtime(true);
			$payload = $provider->compute($contextId);
			$manager->updateGroup($contextId, $group, $payload, (int) round((microtime(true) - $t0) * 1000));
			$computed[] = $group;
		}

		$expensive = array_diff($registry->getGroups(false), $registry->getGroups(true));
		$manager->queueExpensive($contextId, $expensive);

		return $computed;
	}

	// ------------------------------------------------------------------
	//  AJAX country data (countryStats parity: live filters)
	// ------------------------------------------------------------------

	/**
	 * Return JSON country data for AJAX filter updates on the dashboard's
	 * country section.
	 */
	public function fetchData($args, $request) {
		$plugin = $this->_getPlugin();
		$contextId = $request->getContext()->getId();

		$allowedStrategies = array('orcid', 'email', 'name');
		$allowedFallbacks = array('email_name', 'email', 'name', 'none');

		$strategy = $request->getUserVar('strategy');
		$strategy = in_array($strategy, $allowedStrategies) ? $strategy : 'orcid';

		$fallback = $request->getUserVar('fallback');
		$fallback = in_array($fallback, $allowedFallbacks) ? $fallback : 'email_name';

		$dateStart = $request->getUserVar('dateStart');
		$dateStart = ($dateStart && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStart)) ? $dateStart : null;

		$dateEnd = $request->getUserVar('dateEnd');
		$dateEnd = ($dateEnd && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateEnd)) ? $dateEnd : null;

		$threshold = max(1, (int) ($request->getUserVar('threshold') ?: 1));

		$dao = $plugin->getCountryDAO();

		$authorsByCountry = $dao->getAuthorsByCountry($contextId, $strategy, $fallback, $dateStart, $dateEnd);
		$articlesByCountry = $dao->getArticlesByCountry($contextId, $dateStart, $dateEnd);
		$totals = $dao->getTotals($contextId, $strategy, $fallback, $dateStart, $dateEnd);

		if ($threshold > 1) {
			$authorsByCountry = array_values(array_filter($authorsByCountry, function ($r) use ($threshold) {
				return (int) $r['author_count'] >= $threshold;
			}));
			$articlesByCountry = array_values(array_filter($articlesByCountry, function ($r) use ($threshold) {
				return (int) $r['article_count'] >= $threshold;
			}));
		}

		$authorsByCountry = $plugin->enrichCountryData($authorsByCountry, 'author_count');
		$articlesByCountry = $plugin->enrichCountryData($articlesByCountry, 'article_count');

		header('Content-Type: application/json; charset=utf-8');
		echo json_encode(array(
			'authorsByCountry'  => $authorsByCountry,
			'articlesByCountry' => $articlesByCountry,
			'totals'            => $totals,
		));
		exit;
	}

	// ------------------------------------------------------------------
	//  View model
	// ------------------------------------------------------------------

	/**
	 * Build the template/JS view model from a snapshot.
	 *
	 * For the public surface ($forPublic = true) ONLY metrics with
	 * visibility "public" are included — everything else is stripped
	 * before the data reaches the template. Data-poor metrics (n = 0 or
	 * null value; rates with n below the threshold) are removed on public
	 * surfaces and flagged 'insufficient' on the dashboard (R1).
	 *
	 * @return array
	 */
	protected function _buildViewModel($snapshot, $contextId, $forPublic) {
		$plugin = $this->_getPlugin();
		$manager = $plugin->getSnapshotManager();
		$settings = $plugin->getAllSettings($contextId);
		$nThreshold = max(0, (int) $settings['rateNThreshold']);

		$model = array(
			'generatedAt'    => null,
			'usagePending'   => true,
			'coverageYear'   => null,
			'groups'         => array(),
			'countrySection' => null,
			'tooltips'       => array(),
			'labels'         => $this->_metricLabels(),
		);

		if (!is_array($snapshot)) return $model;

		$model['generatedAt'] = isset($snapshot['generatedAt']) ? $snapshot['generatedAt'] : null;
		if ($model['generatedAt'] === null && isset($snapshot['groupsUpdatedAt']) && is_array($snapshot['groupsUpdatedAt'])) {
			// Partial snapshot: show the newest group stamp instead
			$stamps = array_values(array_filter($snapshot['groupsUpdatedAt']));
			if (!empty($stamps)) $model['generatedAt'] = max($stamps);
		}
		if ($model['generatedAt'] !== null) {
			// Human-readable stamp for the trust layer
			$ts = strtotime($model['generatedAt']);
			if ($ts) $model['generatedAt'] = date('Y-m-d H:i', $ts);
		}
		$model['usagePending'] = empty($snapshot['groupsUpdatedAt']['usage']);
		if (isset($snapshot['coverage']['editorialStartYear'])) {
			$model['coverageYear'] = (int) $snapshot['coverage']['editorialStartYear'];
		}

		foreach ($plugin->getMethodologyTooltips() as $key => $localeKey) {
			$model['tooltips'][$key] = __($localeKey);
		}

		// On public surfaces, even metric NAMES of non-public metrics must
		// not reach the HTML: strip label/tooltip dictionary entries for
		// anything that is not public (ui.* and group.* strings are generic).
		if ($forPublic) {
			$visibilityMap = $plugin->getVisibilityMap($contextId);
			foreach ($model['labels'] as $key => $labelText) {
				if (strpos($key, 'ui.') === 0 || strpos($key, 'group.') === 0) continue;
				if (!isset($visibilityMap[$key]) || $visibilityMap[$key] !== 'public') {
					unset($model['labels'][$key]);
				}
			}
			foreach ($model['tooltips'] as $key => $tip) {
				if (!isset($visibilityMap[$key]) || $visibilityMap[$key] !== 'public') {
					unset($model['tooltips'][$key]);
				}
			}
		}

		$visible = function ($key) use ($manager, $snapshot, $forPublic) {
			$visibility = $manager->getVisibility($snapshot, $key);
			if ($forPublic) return $visibility === 'public';
			return $visibility !== 'hidden';
		};
		$isPublic = function ($key) use ($manager, $snapshot) {
			return $manager->getVisibility($snapshot, $key) === 'public';
		};

		// ---- G1 Editorial
		$cards = array();
		if (isset($snapshot['editorial']['allTime'])) {
			$allTime = $snapshot['editorial']['allTime'];
			// Rates are meaningful only over the decided cohort: a journal
			// with zero decisions must show "insufficient data", not "0%".
			$decided = (isset($allTime['submissionsAccepted']) ? (int) $allTime['submissionsAccepted'] : 0)
				+ (isset($allTime['submissionsDeclined']) ? (int) $allTime['submissionsDeclined'] : 0);

			$defs = array(
				'submissionsReceived'  => array('type' => 'count', 'value' => $this->_get($allTime, 'submissionsReceived')),
				'submissionsAccepted'  => array('type' => 'count', 'value' => $this->_get($allTime, 'submissionsAccepted')),
				'submissionsDeclined'  => array('type' => 'count', 'value' => $this->_get($allTime, 'submissionsDeclined'),
					'detail' => array(
						'deskReject' => $this->_get($allTime, 'submissionsDeclinedDeskReject'),
						'postReview' => $this->_get($allTime, 'submissionsDeclinedPostReview'),
					)),
				'acceptanceRate'       => array('type' => 'rate', 'value' => $this->_get($allTime, 'acceptanceRate'), 'n' => $decided),
				'declineRate'          => array('type' => 'rate', 'value' => $this->_get($allTime, 'declineRate'), 'n' => $decided),
				'submissionsPublished' => array('type' => 'count', 'value' => $this->_get($allTime, 'submissionsPublished')),
			);
			foreach ($defs as $key => $def) {
				if (!$visible($key)) continue;
				$card = $this->_makeCard($key, $def, $nThreshold, $forPublic, $isPublic($key));
				if ($card !== null) $cards[] = $card;
			}
		}
		$byYear = (isset($snapshot['editorial']['byYear']) && !$forPublic) ? $snapshot['editorial']['byYear'] : null;
		if ($forPublic && isset($snapshot['editorial']['byYear'])) {
			// Public by-year table only includes public editorial columns
			$byYear = array();
			$publicCols = array();
			foreach (array('submissionsReceived', 'submissionsAccepted', 'submissionsDeclined', 'submissionsPublished', 'acceptanceRate', 'declineRate') as $col) {
				if ($visible($col)) $publicCols[] = $col;
			}
			if (!empty($publicCols)) {
				foreach ($snapshot['editorial']['byYear'] as $year => $data) {
					// Rate columns obey the same small-sample rule as the
					// cards: below-threshold decided cohorts render blank.
					$yearDecided = (isset($data['submissionsAccepted']) ? (int) $data['submissionsAccepted'] : 0)
						+ (isset($data['submissionsDeclined']) ? (int) $data['submissionsDeclined'] : 0);
					$row = array();
					foreach ($publicCols as $col) {
						$value = isset($data[$col]) ? $data[$col] : null;
						if (in_array($col, array('acceptanceRate', 'declineRate'), true)
								&& $yearDecided < max(1, $nThreshold)) {
							$value = null;
						}
						$row[$col] = $value;
					}
					$byYear[$year] = $row;
				}
			} else {
				$byYear = null;
			}
		}
		if (!empty($cards) || !empty($byYear)) {
			$model['groups']['editorial'] = array('cards' => $cards, 'byYear' => $byYear);
		}

		// ---- G2 Times
		$cards = array();
		if (isset($snapshot['times'])) {
			foreach ($snapshot['times'] as $key => $stat) {
				if (!$visible($key)) continue;
				$card = $this->_makeCard($key, array(
					'type'  => 'days',
					'value' => isset($stat['avg']) ? $stat['avg'] : null,
					'n'     => isset($stat['n']) ? (int) $stat['n'] : 0,
					'detail' => array(
						'median' => isset($stat['median']) ? $stat['median'] : null,
						'p80'    => isset($stat['p80']) ? $stat['p80'] : null,
					),
				), $nThreshold, $forPublic, $isPublic($key));
				if ($card !== null) $cards[] = $card;
			}
		}
		if (!empty($cards)) $model['groups']['times'] = array('cards' => $cards);

		// ---- G3 Usage
		$cards = array();
		$usageExtras = array();
		if (isset($snapshot['usage'])) {
			$usage = $snapshot['usage'];
			$defs = array(
				'totalAbstractViews'     => array('type' => 'count', 'value' => $this->_get($usage, 'totalAbstractViews')),
				'totalGalleyDownloads'   => array('type' => 'count', 'value' => $this->_get($usage, 'totalGalleyDownloads')),
				'currentYearUsage'       => array('type' => 'pair', 'value' => isset($usage['currentYear']) ? $usage['currentYear'] : null),
				'avgDownloadsPerArticle' => array('type' => 'count', 'value' => $this->_get($usage, 'avgDownloadsPerArticle'),
					'detail' => array('articles' => $this->_get($usage, 'articlesWithDownloads'))),
				'accessCountryCount'     => array('type' => 'count', 'value' => $this->_get($usage, 'accessCountryCount')),
			);
			foreach ($defs as $key => $def) {
				if (!$visible($key)) continue;
				$card = $this->_makeCard($key, $def, $nThreshold, $forPublic, $isPublic($key));
				if ($card !== null) $cards[] = $card;
			}
			if ($visible('topArticles') && !empty($usage['topArticles'])) {
				$usageExtras['topArticles'] = $usage['topArticles'];
			}
			if ($visible('usageTrend') && !empty($usage['monthlySeries'])) {
				$usageExtras['monthlySeries'] = $usage['monthlySeries'];
			}
		}
		if (!empty($cards) || !empty($usageExtras)) {
			$model['groups']['usage'] = array_merge(array('cards' => $cards), $usageExtras);
		}

		// ---- G4 Community
		$cards = array();
		$communityExtras = array();
		if (isset($snapshot['community'])) {
			$community = $snapshot['community'];
			$defs = array(
				'uniqueAuthors' => array('type' => 'count', 'value' => $this->_get($community, 'uniqueAuthors')),
				'reviewerCount' => array('type' => 'count', 'value' => $this->_get($community, 'reviewers')),
				'memberCount'   => array('type' => 'count', 'value' => $this->_get($community, 'totalActiveUsers'),
					'detail' => array(
						'authors' => $this->_get($community, 'authors'),
						'readers' => $this->_get($community, 'readers'),
					)),
				'authorCountries' => array('type' => 'count', 'value' => isset($community['authorCountries']['count']) ? $community['authorCountries']['count'] : null),
				'completedReviews' => array('type' => 'count', 'value' => $this->_get($community, 'completedReviewsTotal'),
					'detail' => array(
						'avgPerArticle' => $this->_get($community, 'avgReviewsPerArticle'),
						'coverageYear'  => $model['coverageYear'],
					)),
			);
			foreach ($defs as $key => $def) {
				if (!$visible($key)) continue;
				$card = $this->_makeCard($key, $def, $nThreshold, $forPublic, $isPublic($key));
				if ($card !== null) $cards[] = $card;
			}
			if ($visible('completedReviews') && !empty($community['completedReviewsByYear'])) {
				$communityExtras['completedReviewsByYear'] = $community['completedReviewsByYear'];
			}
			// Full geographic distribution section (charts + tables),
			// governed by the authorCountries visibility key. Rendered
			// from the snapshot so the public page needs no live queries.
			if ($visible('authorCountries') && !empty($community['authorCountries']['authors'])) {
				$ac = $community['authorCountries'];
				$model['countrySection'] = array(
					'authors'  => array_values($ac['authors']),
					'articles' => isset($ac['articles']) ? array_values($ac['articles']) : array(),
					'totals'   => array(
						'totalAuthors'   => isset($ac['totalAuthors']) ? (int) $ac['totalAuthors'] : 0,
						'totalArticles'  => isset($ac['totalArticles']) ? (int) $ac['totalArticles'] : 0,
						'totalCountries' => isset($ac['count']) ? (int) $ac['count'] : 0,
					),
				);
			}
		}
		if (!empty($cards) || !empty($communityExtras)) {
			$model['groups']['community'] = array_merge(array('cards' => $cards), $communityExtras);
		}

		// ---- G5 Output
		$cards = array();
		$outputExtras = array();
		if (isset($snapshot['output'])) {
			$output = $snapshot['output'];
			if ($visible('archiveDepth')) {
				$card = $this->_makeCard('archiveDepth', array(
					'type'  => 'count',
					'value' => $this->_get($output, 'archiveYears'),
					'detail' => array(
						'since'       => $this->_get($output, 'firstPublicationYear'),
						'totalIssues' => $this->_get($output, 'totalIssues'),
					),
				), $nThreshold, $forPublic, $isPublic('archiveDepth'));
				if ($card !== null) $cards[] = $card;
			}
			if ($visible('articlesPerYear') && !empty($output['articlesByYear'])) {
				$outputExtras['articlesByYear'] = $output['articlesByYear'];
			}
			if ($visible('issuesPerYear') && !empty($output['issuesByYear'])) {
				$outputExtras['issuesByYear'] = $output['issuesByYear'];
			}
		}
		if (!empty($cards) || !empty($outputExtras)) {
			$model['groups']['output'] = array_merge(array('cards' => $cards), $outputExtras);
		}

		// ---- G6 Manual (title/description resolved for the UI locale)
		if ($visible('manualMetrics') && !empty($snapshot['manual']) && is_array($snapshot['manual'])) {
			$context = Application::get()->getRequest()->getContext();
			$rows = array();
			foreach (array_values($snapshot['manual']) as $row) {
				$row['title'] = $plugin->localize(isset($row['title']) ? $row['title'] : '', $context);
				$row['description'] = $plugin->localize(isset($row['description']) ? $row['description'] : '', $context);
				if ($row['title'] === '') continue;
				$rows[] = $row;
			}
			if (!empty($rows)) $model['groups']['manual'] = array('rows' => $rows);
		}

		return $model;
	}

	/**
	 * Build one metric card, applying the data-poor rules (R1):
	 * - value null (or n = 0 for rate/days types): public -> drop the card,
	 *   dashboard -> keep it flagged 'insufficient' (rendered dimmed).
	 * - rate/days types with 0 < n < threshold: same treatment.
	 *
	 * @return array|null
	 */
	protected function _makeCard($key, $def, $nThreshold, $forPublic, $isPublicMetric) {
		$value = isset($def['value']) ? $def['value'] : null;
		$n = isset($def['n']) ? (int) $def['n'] : null;
		$type = $def['type'];

		$insufficient = ($value === null);
		if (!$insufficient && in_array($type, array('rate', 'days'), true)) {
			if ($n !== null && $n < max(1, $nThreshold)) $insufficient = true;
		}
		if ($type === 'pair' && (!is_array($value) || ((int) $value['views'] === 0 && (int) $value['downloads'] === 0))) {
			// A 0/0 usage year means "no usage data yet": drop the card on
			// public surfaces, dim it on the dashboard (same as null).
			$insufficient = true;
		}

		if ($insufficient && $forPublic) return null;

		return array(
			'key'          => $key,
			'type'         => $type,
			'value'        => $value,
			'n'            => $n,
			'detail'       => isset($def['detail']) ? $def['detail'] : null,
			'insufficient' => $insufficient,
			'isPublic'     => (bool) $isPublicMetric,
		);
	}

	/**
	 * Localized display labels for every metric key + UI strings used by
	 * the dashboard/public-page JavaScript renderer.
	 */
	protected function _metricLabels() {
		$plugin = $this->_getPlugin();
		$labels = array();
		foreach ($plugin->getMetricCatalog() as $key => $meta) {
			$labels[$key] = __('plugins.generic.journalMetrics.metric.' . $key);
		}
		foreach (array('editorial', 'times', 'usage', 'community', 'output', 'manual') as $group) {
			$labels['group.' . $group] = __('plugins.generic.journalMetrics.group.' . $group);
		}
		$uiKeys = array(
			'days', 'median', 'p80', 'n', 'views', 'downloads', 'articles', 'issues',
			'year', 'allTime', 'insufficient', 'publicBadge',
			'topArticlesTitle', 'trendTitle', 'completedReviewsTitle', 'byYearTitle',
			'articlesPerYearTitle', 'issuesPerYearTitle', 'since', 'totalIssues',
			'avgPerArticle', 'deskReject', 'postReview', 'authorsDetail', 'readersDetail',
			'noResults', 'source', 'lastUpdated', 'metricsUpdated', 'usagePending',
			'countrySectionTitle', 'searchCountry', 'country', 'authors', 'share',
			'distribution', 'authorsByCountry', 'articlesByCountry', 'topCountries',
			'authorDistribution', 'countriesWord', 'other', 'rank', 'yearTableNote',
			'coverageNote',
		);
		foreach ($uiKeys as $key) {
			$labels['ui.' . $key] = __('plugins.generic.journalMetrics.ui.' . $key);
		}
		return $labels;
	}

	protected function _get($array, $key) {
		return isset($array[$key]) ? $array[$key] : null;
	}

	/**
	 * Cache-busting asset version, read from the plugin's version.xml
	 * file (the versions DB row lags behind on filesystem deployments).
	 */
	protected function _assetVersion() {
		static $release = null;
		if ($release === null) {
			$release = '1.0.0.0';
			import('lib.pkp.classes.site.VersionCheck');
			$info = VersionCheck::parseVersionXML($this->_getPlugin()->getPluginPath() . '/version.xml');
			if ($info && !empty($info['release'])) $release = $info['release'];
		}
		return $release;
	}

	// ------------------------------------------------------------------
	//  Template setup + country helpers (countryStats parity)
	// ------------------------------------------------------------------

	/**
	 * Set up the template (backend page = true, frontend = false).
	 */
	protected function _setupTemplate($request, $isBackend = true) {
		if ($isBackend) {
			$this->_isBackendPage = true;
			if (!defined('APT_BACKEND_PAGE')) {
				define('APT_BACKEND_PAGE', true);
			}
		}
		parent::setupTemplate($request);
		AppLocale::requireComponents(LOCALE_COMPONENT_PKP_MANAGER, LOCALE_COMPONENT_APP_MANAGER);
	}

}
