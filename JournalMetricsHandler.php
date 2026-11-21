<?php
/**
 * @file plugins/generic/journalMetrics/JournalMetricsHandler.php
 *
 * Copyright (c) 2026 ojs-services.com
 * Distributed under the GNU GPL v3.
 *
 * @class JournalMetricsHandler
 * @brief Request handler for Journal Metrics pages
 *  (admin dashboard, settings, public page, country AJAX, recompute).
 */

namespace APP\plugins\generic\journalMetrics;

use APP\core\Application;
use APP\handler\Handler;
use APP\plugins\generic\journalMetrics\classes\JournalMetricsCompat;
use APP\plugins\generic\journalMetrics\classes\JournalMetricsSnapshotManager;
use APP\plugins\generic\journalMetrics\classes\providers\JournalMetricsManualProvider;
use APP\plugins\generic\journalMetrics\classes\providers\JournalMetricsBaseProvider;
use APP\template\TemplateManager;
use Illuminate\Support\Facades\DB;
use PKP\facades\Locale;
use PKP\plugins\PluginRegistry;
use PKP\security\authorization\ContextRequiredPolicy;
use PKP\security\authorization\PolicySet;
use PKP\security\authorization\RoleBasedHandlerOperationPolicy;
use PKP\security\Role;
use PKP\site\VersionCheck;

class JournalMetricsHandler extends Handler {

	/** @var JournalMetricsPlugin */
	protected $_plugin;

	/** @var string[] manual rows not saved by the last save (named like a computed metric) */
	protected $_manualRejected = array();

	public function __construct() {
		parent::__construct();

		// Admin operations require Manager or Site Admin role
		$this->addRoleAssignment(
			array(Role::ROLE_ID_SITE_ADMIN, Role::ROLE_ID_MANAGER),
			array('dashboard', 'settings', 'saveSettings', 'fetchData', 'recompute', 'articles')
		);

		// publicpage is NOT assigned to any role — authorize() handles it
	}

	/**
	 * @copydoc PKPHandler::authorize()
	 */
	public function authorize($request, &$args, $roleAssignments) {
		$op = $request->getRouter()->getRequestedOp($request);

		$this->addPolicy(new ContextRequiredPolicy($request));

		if ($op === 'publicpage') {
			$this->markRoleAssignmentsChecked();
			return parent::authorize($request, $args, array());
		}

		$rolePolicy = new PolicySet(PolicySet::COMBINING_PERMIT_OVERRIDES);

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

		// First visit on a fresh install — or after an update that changed
		// how the figures are counted: compute the fast groups
		// synchronously (seconds) and defer usage to the scheduled task.
		if ($snapshot === null || $manager->isOutdated($snapshot)) {
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
			'articleUrlBase' => $request->getDispatcher()->url($request, Application::ROUTE_PAGE, null, 'article', 'view'),
			'csrfToken'    => JournalMetricsCompat::csrfToken($request),
			'editorBox'    => $this->_editorBox($snapshot, $contextId, $request),
			'editorBoxTemplate' => $plugin->getTemplateResource('editorBox.tpl'),
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
				'label'    => __($base . 'metric.' . $key),
				'group'    => __($base . 'group.' . $meta['group']),
				'groupKey' => $meta['group'],
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
			// why the numbers differ (managers): which articles count where
			'editorBox'     => $this->_editorBox($plugin->getSnapshotManager()->getSnapshot($contextId), $contextId, $request),
			'editorBoxTemplate' => $plugin->getTemplateResource('editorBox.tpl'),
			// advanced: sections left out of the publication counts
			'journalSections'  => $this->_journalSections($contextId),
			'excludedSections' => $plugin->getExcludedSectionIds($contextId),
			// manual rows not saved by the last save, and saved rows named
			// like a computed metric (kept, not shown publicly)
			'manualRejected'  => trim(strip_tags((string) $request->getUserVar('rejected'))),
			'manualConflicts' => array_map(function ($row) use ($plugin) {
				return $plugin->isComputedMetricTitle(isset($row['title']) ? $row['title'] : '');
			}, $manualRows),
			'publicPageTitleByLocale' => $titleByLocale,
			'coverageStartYear' => $plugin->getCoverageStartYear($contextId),
			'currentYear'       => (int) date('Y'),
			'blockActive'      => $blockActive,
			'blockCandidates'  => $blockCandidates,
			'blockSelection'   => $blockSelection,
			// Deep anchor into Website Settings → Appearance → Setup, where
			// the sidebar is managed. Appended manually: the router would
			// urlencode the "/" inside the anchor and break the Vue tab.
			'sidebarSettingsUrl' => $dispatcher->url($request, Application::ROUTE_PAGE, null, 'management', 'settings', array('website')) . '#appearance/appearance-setup',
			'saveUrl'       => $dispatcher->url($request, Application::ROUTE_PAGE, null, 'journalmetrics', 'saveSettings'),
			'dashboardUrl'  => $dispatcher->url($request, Application::ROUTE_PAGE, null, 'journalmetrics', 'dashboard'),
			'recomputeUrl'  => $dispatcher->url($request, Application::ROUTE_PAGE, null, 'journalmetrics', 'recompute'),
			'publicPageUrl' => $dispatcher->url($request, Application::ROUTE_PAGE, null, 'journalmetrics', 'publicpage'),
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
			JournalMetricsCompat::notFound($request);
			return;
		}

		$context = $request->getContext();
		$this->_persistSettings($request, $context);

		$params = array('saved' => 1);
		if (!empty($this->_manualRejected)) {
			// shown once on the settings page: which rows were not saved and why
			$params['rejected'] = implode(' | ', array_slice($this->_manualRejected, 0, 5));
		}
		$request->redirect(null, 'journalmetrics', 'settings', null, $params);
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
			'editorialSection' => array('type' => 'string', 'default' => 'auto',
				'allowed' => array('auto', 'show', 'hide')),
			'yearBreakdown'    => array('type' => 'bool',   'default' => false),
		);
		$oldExcluded = $plugin->getExcludedSectionIds($contextId);

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

		// ---- Sections left out of the publication counts (advanced):
		//      only sections of this journal
		$postedSections = $request->getUserVar('excludedSections');
		$excluded = array();
		if (is_array($postedSections)) {
			$journalSections = array_keys($this->_journalSections($contextId));
			$excluded = array_values(array_intersect($journalSections, array_map('intval', $postedSections)));
		}
		$plugin->updateSetting($contextId, 'excludedSections', $excluded, 'object');
		sort($excluded);
		sort($oldExcluded);

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
		$rejected = array();
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
				// Rates, durations and counts are computed from the journal's
				// data: a NEW or CHANGED row named like a computed metric is
				// not saved. Rows saved before this rule stay as they are
				// (not shown publicly, marked on this page).
				if ($plugin->isComputedMetricTitle($row['title']) && !isset($existingByContent[$fingerprint])) {
					$rejected[] = $plugin->localize($row['title'], $context);
					continue;
				}
				$row['lastUpdated'] = isset($existingByContent[$fingerprint]) && $existingByContent[$fingerprint] !== ''
					? $existingByContent[$fingerprint]
					: date('Y-m-d');
				$rows[] = $row;
			}
		}
		$plugin->updateSetting($contextId, 'manualMetrics', $rows, 'object');
		$this->_manualRejected = $rejected;

		// ---- Reflect changes in the snapshot immediately (manual group
		// copy also refreshes the embedded visibility map) so the public
		// page — a single JSON read — is current without a recompute.
		$manager = $plugin->getSnapshotManager();
		if ($manager->getSnapshot($contextId) !== null) {
			$provider = new JournalMetricsManualProvider($plugin);
			$manager->updateGroup($contextId, 'manual', $provider->compute($contextId), 0);

			// Coverage changed: the stored workflow-derived groups are now
			// stale. Recompute them synchronously so the coverage
			// declaration and the numbers change together — a half-updated
			// state must never be visible.
			// Excluded sections changed: the publication counts (and the
			// explanation's numbers) change with them, in the same step.
			$groups = array();
			if ($coverage !== $oldCoverage) $groups = array('editorial', 'times', 'community');
			if ($excluded !== $oldExcluded) $groups = array_unique(array_merge($groups, array('editorial', 'output', 'community')));
			if (!empty($groups)) {
				$registry = $plugin->getProviderRegistry();
				foreach ($groups as $group) {
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
			// Visitors: 404, as before. Managers: a short note with a link
			// to the switch, so a closed page is not mistaken for a fault.
			if (!$plugin->canManage($request)) {
				JournalMetricsCompat::notFound($request);
				return;
			}
			$this->_setupTemplate($request, false);
			$templateMgr = TemplateManager::getManager($request);
			$templateMgr->assign(array(
				'pluginPath'   => $request->getBaseUrl() . '/' . $plugin->getPluginPath(),
				'assetVersion' => $this->_assetVersion(),
				'pageTitle'    => $plugin->localize($settings['publicPageTitle'], $context) ?: 'Journal Metrics',
				'jmxClosedNotice' => true,
				'settingsUrl'  => $request->getDispatcher()->url($request, Application::ROUTE_PAGE, null, 'journalmetrics', 'settings'),
			));
			$plugin->registerHeadingModifier($templateMgr);
			$templateMgr->display($plugin->getTemplateResource('publicpage.tpl'));
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
			'articleUrlBase' => $request->getDispatcher()->url($request, Application::ROUTE_PAGE, null, 'article', 'view'),
			'showDeveloperCredit' => (bool) $settings['showDeveloperCredit'],
			'pageTitle'    => $plugin->localize($settings['publicPageTitle'], $context) ?: 'Journal Metrics',
			// empty page: why, and (managers only) where to change it
			'jmxPublicCount' => count(array_filter($plugin->getVisibilityMap($contextId), function ($v) { return $v === 'public'; })),
			'jmxCanManage' => $plugin->canManage($request),
			'settingsUrl'  => $request->getDispatcher()->url($request, Application::ROUTE_PAGE, null, 'journalmetrics', 'settings'),
		));

		$plugin->registerHeadingModifier($templateMgr);
		$templateMgr->display($plugin->getTemplateResource('publicpage.tpl'));
	}

	// ------------------------------------------------------------------
	//  Recompute (admin, POST + CSRF)
	// ------------------------------------------------------------------

	/**
	 * "Recompute now": runs the fast provider groups synchronously and
	 * queues the expensive usage group for the next scheduled run
	 * (the usage queries are too slow for a web request).
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

		$excluded = $plugin->getExcludedSectionIds($contextId);
		$authorsByCountry = $dao->getAuthorsByCountry($contextId, $strategy, $fallback, $dateStart, $dateEnd, $excluded);
		$articlesByCountry = $dao->getArticlesByCountry($contextId, $dateStart, $dateEnd, $excluded);
		$totals = $dao->getTotals($contextId, $strategy, $fallback, $dateStart, $dateEnd, $excluded);

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
	 * surfaces and flagged 'insufficient' on the dashboard.
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
			'numberLocale'   => JournalMetricsPlugin::numberLocaleTag(),
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
				if (strpos($key, 'ui.') === 0 || strpos($key, 'group.') === 0 || strpos($key, 'section.') === 0) continue;
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

		// ---- Editorial
		$cards = array();
		if (isset($snapshot['editorial']['allTime'])) {
			// Rates are derived from the stored counts at read time, so a
			// snapshot written by an older version (core-computed rates)
			// displays the corrected values without waiting for a recompute.
			// n is the same decided cohort as the rate's denominator; zero
			// decisions -> null rate -> "insufficient data", never "0%".
			$allTime = array_merge(
				$snapshot['editorial']['allTime'],
				JournalMetricsPlugin::decisionRates($snapshot['editorial']['allTime'])
			);
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
			);
			foreach ($defs as $key => $def) {
				if (!$visible($key)) continue;
				$card = $this->_makeCard($key, $def, $nThreshold, $forPublic, $isPublic($key));
				if ($card !== null) $cards[] = $card;
			}
		}
		$snapshotByYear = null;
		if (isset($snapshot['editorial']['byYear']) && is_array($snapshot['editorial']['byYear'])) {
			$snapshotByYear = array();
			foreach ($snapshot['editorial']['byYear'] as $year => $data) {
				// Published articles per year live in the publications chart
				// (older snapshots still carry a core "published" column).
				unset($data['submissionsPublished']);
				$rates = JournalMetricsPlugin::decisionRates($data);
				$data['acceptanceRate'] = $rates['acceptanceRate'];
				$data['declineRate'] = $rates['declineRate'];
				$snapshotByYear[$year] = $data;
			}
		}
		$byYear = !$forPublic ? $snapshotByYear : null;
		if ($forPublic && $snapshotByYear !== null) {
			// Public by-year table only includes public editorial columns
			$byYear = array();
			$publicCols = array();
			foreach (array('submissionsReceived', 'submissionsAccepted', 'submissionsDeclined', 'acceptanceRate', 'declineRate') as $col) {
				if ($visible($col)) $publicCols[] = $col;
			}
			if (!empty($publicCols)) {
				foreach ($snapshotByYear as $year => $data) {
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

		// ---- Turnaround times
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
						// impossible (negative) spans left out — managers only
						'dropped' => (!$forPublic && !empty($stat['dropped'])) ? (int) $stat['dropped'] : null,
					),
				), $nThreshold, $forPublic, $isPublic($key));
				if ($card !== null) $cards[] = $card;
			}
		}
		if (!empty($cards)) $model['groups']['times'] = array('cards' => $cards);

		// ---- Usage
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

		// ---- Community (publications: authors, members, countries) and
		//      peer review (editorial process: reviewers, completed reviews)
		$cards = array();
		$communityExtras = array();
		$reviewCards = array();
		$reviewExtras = array();
		if (isset($snapshot['community'])) {
			$community = $snapshot['community'];
			$defs = array(
				'uniqueAuthors' => array('type' => 'count', 'value' => $this->_get($community, 'uniqueAuthors')),
				'memberCount'   => array('type' => 'count', 'value' => $this->_get($community, 'totalActiveUsers'),
					'detail' => array(
						'authors' => $this->_get($community, 'authors'),
						'readers' => $this->_get($community, 'readers'),
					)),
				'authorCountries' => array('type' => 'count', 'value' => isset($community['authorCountries']['count']) ? $community['authorCountries']['count'] : null),
			);
			foreach ($defs as $key => $def) {
				if (!$visible($key)) continue;
				$card = $this->_makeCard($key, $def, $nThreshold, $forPublic, $isPublic($key));
				if ($card !== null) $cards[] = $card;
			}
			$defs = array(
				'reviewerCount' => array('type' => 'count', 'value' => $this->_get($community, 'reviewers')),
				'completedReviews' => array('type' => 'count', 'value' => $this->_get($community, 'completedReviewsTotal'),
					'detail' => array(
						'avgPerArticle' => $this->_get($community, 'avgReviewsPerArticle'),
						'coverageYear'  => $model['coverageYear'],
					)),
			);
			foreach ($defs as $key => $def) {
				if (!$visible($key)) continue;
				$card = $this->_makeCard($key, $def, $nThreshold, $forPublic, $isPublic($key));
				if ($card !== null) $reviewCards[] = $card;
			}
			if ($visible('completedReviews') && !empty($community['completedReviewsByYear'])) {
				$reviewExtras['completedReviewsByYear'] = $community['completedReviewsByYear'];
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
		if (!empty($reviewCards) || !empty($reviewExtras)) {
			$model['groups']['review'] = array_merge(array('cards' => $reviewCards), $reviewExtras);
		}

		// ---- Publication output
		$cards = array();
		$outputExtras = array();
		if (isset($snapshot['output'])) {
			$output = $snapshot['output'];
			// Every published article: the same number as the per-year chart
			if ($visible('submissionsPublished')) {
				$card = $this->_makeCard('submissionsPublished', array(
					'type'  => 'count',
					'value' => JournalMetricsSnapshotManager::publishedTotal($snapshot),
				), $nThreshold, $forPublic, $isPublic('submissionsPublished'));
				if ($card !== null) $cards[] = $card;
			}
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
				// Advanced option: each year split into articles that went
				// through the editorial process and articles added directly
				if (!empty($settings['yearBreakdown']) && isset($output['articlesByYearProcess'])) {
					$split = array();
					foreach ($output['articlesByYear'] as $year => $total) {
						$process = isset($output['articlesByYearProcess'][$year]) ? (int) $output['articlesByYearProcess'][$year] : 0;
						$split[$year] = array('process' => $process, 'direct' => (int) $total - $process);
					}
					$outputExtras['articlesByYearSplit'] = $split;
				}
			}
			if ($visible('issuesPerYear') && !empty($output['issuesByYear'])) {
				$outputExtras['issuesByYear'] = $output['issuesByYear'];
			}
		}
		if (!empty($cards) || !empty($outputExtras)) {
			$model['groups']['output'] = array_merge(array('cards' => $cards), $outputExtras);
		}

		// ---- External indicators: values the editor entered by hand
		//      (title/description resolved for the UI locale). A row named
		//      like a computed metric never reaches the public page.
		if ($visible('manualMetrics') && !empty($snapshot['manual']) && is_array($snapshot['manual'])) {
			$context = Application::get()->getRequest()->getContext();
			$rows = array();
			foreach (array_values($snapshot['manual']) as $row) {
				$conflict = $plugin->isComputedMetricTitle(isset($row['title']) ? $row['title'] : '');
				if ($conflict && $forPublic) continue;
				$row['title'] = $plugin->localize(isset($row['title']) ? $row['title'] : '', $context);
				$row['description'] = $plugin->localize(isset($row['description']) ? $row['description'] : '', $context);
				if ($row['title'] === '') continue;
				$row['conflict'] = $conflict;
				$rows[] = $row;
			}
			if (!empty($rows)) $model['groups']['manual'] = array('rows' => $rows);
		}

		// ---- Two sections: Publications and Editorial process
		$model = $this->_applySections($model, $snapshot, $settings, $forPublic);

		return $model;
	}

	/**
	 * Arrange the groups into the page's sections and decide whether the
	 * editorial process section is shown:
	 *  - Publications: output, usage, community;
	 *  - Editorial process: editorial, times, review — with a short
	 *    explanation when articles were added without an editor decision;
	 *  - External indicators: the editor's own values.
	 * Setting "editorialSection": auto (shown once enough submissions have
	 * a final decision), show, hide. Managers always see every group; the
	 * public page drops a hidden section entirely.
	 *
	 * @return array the model with 'sections', 'editorialNote',
	 *  'editorialHidden' (null | 'auto' | 'setting')
	 */
	protected function _applySections($model, $snapshot, $settings, $forPublic) {
		$decided = JournalMetricsPlugin::decidedCount($snapshot);
		$hidden = JournalMetricsPlugin::editorialSectionHidden($settings['editorialSection'], $snapshot);
		$model['editorialHidden'] = $hidden;
		$model['decidedCount'] = $decided;

		$editorialGroups = array('editorial', 'times', 'review');
		if ($forPublic && $hidden !== null) {
			foreach ($editorialGroups as $group) unset($model['groups'][$group]);
		}

		// The explanation: only when articles were added without an editor
		// decision, from the same snapshot as the figures it explains.
		$model['editorialNote'] = null;
		$class = isset($snapshot['editorial']['classification']) ? $snapshot['editorial']['classification'] : null;
		$hasEditorial = false;
		foreach ($editorialGroups as $group) {
			if (!empty($model['groups'][$group])) $hasEditorial = true;
		}
		if ($hasEditorial && is_array($class) && !empty($class['publishedDirect'])) {
			$model['editorialNote'] = __('plugins.generic.journalMetrics.section.editorial.note', array(
				'process' => JournalMetricsPlugin::formatNumber((int) $class['process']),
				'direct'  => JournalMetricsPlugin::formatNumber((int) $class['publishedDirect']),
			));
		}

		$model['sections'] = array();
		foreach (array(
			'publications' => array('output', 'usage', 'community'),
			'editorial'    => $editorialGroups,
			'external'     => array('manual'),
		) as $section => $groups) {
			$present = array_values(array_filter($groups, function ($g) use ($model) { return !empty($model['groups'][$g]); }));
			if (!empty($present)) $model['sections'][] = array('key' => $section, 'groups' => $present);
		}
		return $model;
	}

	// ------------------------------------------------------------------
	//  Editor view: why the numbers differ
	// ------------------------------------------------------------------

	/**
	 * The journal's sections, id => title in the interface language.
	 *
	 * @param int $contextId
	 * @return array
	 */
	protected function _journalSections($contextId) {
		$ids = DB::table('sections')->where('journal_id', (int) $contextId)->orderBy('seq')->pluck('section_id')->all();
		if (empty($ids)) return array();
		$locale = Locale::getLocale();
		$titles = array();
		foreach (DB::table('section_settings')->whereIn('section_id', $ids)->where('setting_name', 'title')->get() as $row) {
			$id = (int) $row->section_id;
			if (!isset($titles[$id]) || $row->locale === $locale) $titles[$id] = (string) $row->setting_value;
		}
		$sections = array();
		foreach ($ids as $id) {
			$sections[(int) $id] = isset($titles[(int) $id]) && $titles[(int) $id] !== '' ? $titles[(int) $id] : '#' . (int) $id;
		}
		return $sections;
	}

	/**
	 * The editor box: a few lines with numbers that say which articles
	 * count where, and what the editor can do. Managers only. Null when
	 * there is nothing to explain (every article went through the
	 * editorial process and the section is shown).
	 *
	 * @return array|null ['lines' => [['text' => string, 'links' => [[label, url]]]], 'actions' => [[label, url]]]
	 */
	protected function _editorBox($snapshot, $contextId, $request) {
		$class = isset($snapshot['editorial']['classification']) ? $snapshot['editorial']['classification'] : null;
		if (!is_array($class)) return null;
		$plugin = $this->_getPlugin();
		$model = $this->_applySections(array('groups' => array()), $snapshot, $plugin->getAllSettings($contextId), false);
		$hidden = $model['editorialHidden'];
		if (empty($class['publishedDirect']) && empty($class['inconsistentDates']) && empty($class['awaitingDecision']) && $hidden === null) {
			return null;
		}

		$base = 'plugins.generic.journalMetrics.editorBox.';
		$n = function ($v) { return JournalMetricsPlugin::formatNumber((int) $v); };
		$dispatcher = $request->getDispatcher();
		$listUrl = function ($type) use ($dispatcher, $request) {
			return $dispatcher->url($request, Application::ROUTE_PAGE, null, 'journalmetrics', 'articles', null, array('type' => $type));
		};
		$settingsUrl = $dispatcher->url($request, Application::ROUTE_PAGE, null, 'journalmetrics', 'settings');

		$lines = array();
		$lines[] = array('text' => __($base . 'published', array('published' => $n($class['published']))), 'links' => array());
		$lines[] = array('text' => __($base . 'process', array('count' => $n($class['publishedProcess']))), 'links' => array());
		if (!empty($class['publishedDirect'])) {
			$lines[] = array('text' => __($base . 'direct', array('count' => $n($class['publishedDirect']))),
				'links' => array(array(__($base . 'list'), $listUrl('direct'))));
		}
		if (!empty($class['inconsistentDates'])) {
			$lines[] = array('text' => __($base . 'inconsistent', array('count' => $n($class['inconsistentDates']))),
				'links' => array(array(__($base . 'list'), $listUrl('inconsistent'))));
		}
		if (!empty($class['awaitingDecision'])) {
			$lines[] = array('text' => __($base . 'awaiting', array('count' => $n($class['awaitingDecision']))), 'links' => array());
		}
		if ($hidden === 'auto') {
			$lines[] = array('text' => __($base . 'hiddenAuto', array(
				'count' => $n($model['decidedCount']),
				'min'   => $n(JournalMetricsPlugin::EDITORIAL_SECTION_MIN_DECIDED),
			)), 'links' => array());
		} elseif ($hidden === 'setting') {
			$lines[] = array('text' => __($base . 'hiddenSetting'), 'links' => array());
		}

		return array(
			'lines'   => $lines,
			'actions' => array(
				array(__($base . 'actionSection'), $settingsUrl . '#jmxEditorialSection'),
				array(__($base . 'actionStartYear'), $settingsUrl . '#jmxAdvanced'),
			),
		);
	}

	/**
	 * Manager-only list: articles added without an editor decision
	 * (type=direct) or with inconsistent dates (type=inconsistent),
	 * newest first, with a count per year.
	 */
	public function articles($args, $request) {
		$this->_setupTemplate($request, true);
		$plugin = $this->_getPlugin();
		$context = $request->getContext();
		$contextId = (int) $context->getId();
		$type = $request->getUserVar('type') === 'inconsistent' ? 'inconsistent' : 'direct';

		$where = $type === 'direct'
			? JournalMetricsBaseProvider::publishedCondition('s', $plugin->getExcludedSectionIds($contextId))
				. ' AND NOT ' . JournalMetricsBaseProvider::editorDecisionCondition('s')
			: JournalMetricsBaseProvider::editorialProcessCondition('s') . ' AND ' . JournalMetricsBaseProvider::importedCondition('s');
		$limit = 2000;
		$rows = DB::select(
			"SELECT s.submission_id, s.current_publication_id, s.locale, s.date_submitted,
				(SELECT MIN(p.date_published) FROM publications p WHERE p.submission_id = s.submission_id AND p.status = 3) AS first_pub
			FROM submissions s
			WHERE s.context_id = ? AND {$where}
			ORDER BY first_pub DESC, s.submission_id DESC
			LIMIT " . ($limit + 1),
			array($contextId)
		);
		$truncated = count($rows) > $limit;
		$rows = array_slice($rows, 0, $limit);

		// Titles of the current publications, in the interface language when present
		$pubIds = array_map(function ($r) { return (int) $r->current_publication_id; }, $rows);
		$titles = array();
		$uiLocale = Locale::getLocale();
		foreach (array_chunk($pubIds, 500) as $chunk) {
			foreach (DB::table('publication_settings')->whereIn('publication_id', $chunk)->where('setting_name', 'title')->get() as $t) {
				$id = (int) $t->publication_id;
				if (!isset($titles[$id]) || $t->locale === $uiLocale) $titles[$id] = (string) $t->setting_value;
			}
		}

		$articles = array();
		$byYear = array();
		foreach ($rows as $r) {
			$year = $r->first_pub ? substr((string) $r->first_pub, 0, 4) : '—';
			$byYear[$year] = isset($byYear[$year]) ? $byYear[$year] + 1 : 1;
			$articles[] = array(
				'id'        => (int) $r->submission_id,
				'title'     => isset($titles[(int) $r->current_publication_id]) ? strip_tags($titles[(int) $r->current_publication_id]) : '#' . (int) $r->submission_id,
				'published' => $r->first_pub ? substr((string) $r->first_pub, 0, 10) : '',
				'submitted' => $r->date_submitted ? substr((string) $r->date_submitted, 0, 10) : '',
			);
		}
		krsort($byYear);

		$dispatcher = $request->getDispatcher();
		$templateMgr = TemplateManager::getManager($request);
		$templateMgr->assign(array(
			'pageWidth'    => 'full',
			'pluginPath'   => $request->getBaseUrl() . '/' . $plugin->getPluginPath(),
			'assetVersion' => $this->_assetVersion(),
			'listType'     => $type,
			'articles'     => $articles,
			'byYear'       => $byYear,
			'truncated'    => $truncated,
			'listLimit'    => $limit,
			'articleUrlBase' => $dispatcher->url($request, Application::ROUTE_PAGE, null, 'article', 'view'),
			'dashboardUrl' => $dispatcher->url($request, Application::ROUTE_PAGE, null, 'journalmetrics', 'dashboard'),
		));
		$templateMgr->display($plugin->getTemplateResource('articles.tpl'));
	}

	/**
	 * Build one metric card, applying the data-poor rules:
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
		foreach (array('editorial', 'times', 'usage', 'community', 'review', 'output', 'manual') as $group) {
			$labels['group.' . $group] = __('plugins.generic.journalMetrics.group.' . $group);
		}
		foreach (array('publications', 'editorial', 'external') as $section) {
			$labels['section.' . $section] = __('plugins.generic.journalMetrics.section.' . $section);
		}
		$uiKeys = array(
			'days', 'median', 'p80', 'n', 'views', 'downloads', 'articles', 'issues',
			'year', 'allTime', 'insufficient', 'publicBadge',
			'topArticlesTitle', 'trendTitle', 'completedReviewsTitle', 'byYearTitle',
			'articlesPerYearTitle', 'issuesPerYearTitle', 'since', 'totalIssues',
			'avgPerArticle', 'deskReject', 'postReview', 'authorsDetail', 'readersDetail',
			'noResults', 'source', 'lastUpdated', 'metricsUpdated', 'usagePending', 'droppedSpans',
			'countrySectionTitle', 'searchCountry', 'country', 'authors', 'share',
			'distribution', 'authorsByCountry', 'articlesByCountry', 'topCountries',
			'authorDistribution', 'countriesWord', 'other', 'rank', 'yearTableNote',
			'coverageNote', 'enteredByEditor', 'splitTitle', 'processArticles', 'directArticles', 'editorialHiddenAuto', 'editorialHiddenSetting', 'manualConflict',
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
			$release = '2.0.0.0';
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
		}
		parent::setupTemplate($request);
	}

}
