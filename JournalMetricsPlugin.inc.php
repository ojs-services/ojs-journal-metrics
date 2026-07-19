<?php
/**
 * @file plugins/generic/journalMetrics/JournalMetricsPlugin.inc.php
 *
 * Copyright (c) 2026 ojs-services.com
 * Distributed under the GNU GPL v3.
 *
 * @class JournalMetricsPlugin
 * @brief Main class for the Journal Metrics plugin.
 *
 * Provides a full journal metrics catalogue (editorial, turnaround times,
 * usage, community, publication output, manual metrics) with a nightly
 * snapshot cache, an admin dashboard, a public page, a sidebar block and
 * a {journal_metric} Smarty function.
 */

import('lib.pkp.classes.plugins.GenericPlugin');

define('NMI_TYPE_JOURNAL_METRICS', 'NMI_TYPE_JOURNAL_METRICS');

class JournalMetricsPlugin extends GenericPlugin {

	/** @var boolean Guard so the Smarty function is registered only once */
	private $_smartyFunctionRegistered = false;

	/**
	 * @copydoc Plugin::register()
	 */
	public function register($category, $path, $mainContextId = null) {
		$success = parent::register($category, $path, $mainContextId);

		// Scheduled task registration must happen regardless of the enabled
		// state so acron can (re)parse the crontab during installation.
		// The callback itself checks getEnabled() (usageStats pattern).
		HookRegistry::register('AcronPlugin::parseCronTab', array($this, 'callbackParseCronTab'));

		if ($success && $this->getEnabled()) {
			// Route /journalmetrics requests to our handler
			HookRegistry::register('LoadHandler', array($this, 'callbackLoadHandler'));

			// Backend sidebar menu entry + {journal_metric} Smarty function
			HookRegistry::register('TemplateManager::display', array($this, 'callbackTemplateDisplay'));

			// Frontend navigation menu item type
			HookRegistry::register('NavigationMenus::itemTypes', array($this, 'addNavigationMenuItemTypes'));
			HookRegistry::register('NavigationMenus::displaySettings', array($this, 'setNavigationMenuItemDisplaySettings'));

			// Sidebar block
			$this->import('JournalMetricsBlockPlugin');
			PluginRegistry::register('blocks', new JournalMetricsBlockPlugin($this), $this->getPluginPath());
		}

		return $success;
	}

	/**
	 * @copydoc Plugin::getDisplayName()
	 */
	public function getDisplayName() {
		return __('plugins.generic.journalMetrics.displayName');
	}

	/**
	 * @copydoc Plugin::getDescription()
	 */
	public function getDescription() {
		return __('plugins.generic.journalMetrics.description');
	}

	/**
	 * @copydoc Plugin::isSitePlugin()
	 */
	public function isSitePlugin() {
		return false;
	}

	// ------------------------------------------------------------------
	//  Actions (Plugin Gallery buttons)
	// ------------------------------------------------------------------

	/**
	 * @copydoc Plugin::getActions()
	 */
	public function getActions($request, $actionArgs) {
		import('lib.pkp.classes.linkAction.request.RedirectAction');
		import('lib.pkp.classes.linkAction.LinkAction');

		$router = $request->getRouter();
		$actions = array();

		if ($this->getEnabled()) {
			$dispatcher = $request->getDispatcher();
			$actions[] = new LinkAction(
				'dashboard',
				new RedirectAction(
					$dispatcher->url($request, ROUTE_PAGE, null, 'journalmetrics', 'dashboard')
				),
				__('plugins.generic.journalMetrics.dashboard'),
				null
			);

			// Settings live on a full backend page (no modal)
			$actions[] = new LinkAction(
				'settings',
				new RedirectAction(
					$dispatcher->url($request, ROUTE_PAGE, null, 'journalmetrics', 'settings')
				),
				__('manager.plugins.settings'),
				null
			);
		}

		return array_merge($actions, parent::getActions($request, $actionArgs));
	}

	/**
	 * @copydoc Plugin::manage()
	 *
	 * All settings live on the /journalmetrics/settings backend page;
	 * there is no modal management flow.
	 */
	public function manage($args, $request) {
		return parent::manage($args, $request);
	}

	// ------------------------------------------------------------------
	//  Hook Callbacks
	// ------------------------------------------------------------------

	/**
	 * Route /journalmetrics/* requests to our handler.
	 */
	public function callbackLoadHandler($hookName, $args) {
		$page =& $args[0];

		if ($page === 'journalmetrics') {
			require_once($this->getPluginPath() . '/JournalMetricsHandler.inc.php');
			define('HANDLER_CLASS', 'JournalMetricsHandler');
			return true;
		}
		return false;
	}

	/**
	 * Register our scheduled task file with acron.
	 * @see AcronPlugin::parseCronTab()
	 */
	public function callbackParseCronTab($hookName, $args) {
		if ($this->getEnabled() || !Config::getVar('general', 'installed')) {
			$taskFilesPath =& $args[0]; // Reference needed.
			$taskFilesPath[] = $this->getPluginPath() . DIRECTORY_SEPARATOR . 'scheduledTasks.xml';
		}
		return false;
	}

	/**
	 * TemplateManager::display callback: injects the backend sidebar link
	 * and registers the {journal_metric} Smarty function.
	 */
	public function callbackTemplateDisplay($hookName, $args) {
		$templateMgr = $args[0];

		// Register the Smarty function once per request
		if (!$this->_smartyFunctionRegistered) {
			$templateMgr->registerPlugin('function', 'journal_metric', array($this, 'smartyJournalMetric'));
			$this->_smartyFunctionRegistered = true;
		}

		$this->_registerBlockStyles($templateMgr);
		$this->_addSidebarLink($templateMgr);

		return false;
	}

	/**
	 * Register the sidebar block's OWN small stylesheet
	 * (css/journalMetricsBlock.css) for the frontend, so the block looks
	 * the same on every page of the site. Registered from the display
	 * hook — not from BlockPlugin::getContents() — because the sidebar
	 * renders after the <head> (and its stylesheet list) is already
	 * printed. The full dashboard stylesheet is never loaded site-wide.
	 */
	private function _registerBlockStyles($templateMgr) {
		static $registered = false;
		if ($registered) return;

		$request = Application::get()->getRequest();
		$context = $request->getContext();
		if (!$context) return;

		// Only when the block can actually render: placed in the sidebar
		// AND the editor's metric selection is not deliberately empty.
		$sidebarBlocks = (array) $context->getData('sidebar');
		if (!in_array('journalmetricsblockplugin', $sidebarBlocks, true)) return;
		if (!count($this->getBlockMetrics($context->getId()))) return;

		import('lib.pkp.classes.site.VersionCheck');
		$release = '1.0.0.0';
		$info = VersionCheck::parseVersionXML($this->getPluginPath() . '/version.xml');
		if ($info && !empty($info['release'])) $release = $info['release'];

		$templateMgr->addStyleSheet(
			'journalMetricsBlock',
			$request->getBaseUrl() . '/' . $this->getPluginPath() . '/css/journalMetricsBlock.css?v=' . $release,
			array('contexts' => 'frontend')
		);
		$registered = true;
	}

	/**
	 * Inject the "Journal Metrics" link into the management sidebar.
	 * Only fires on backend pages (detected via the template menu state).
	 */
	private function _addSidebarLink($templateMgr) {
		$request = Application::get()->getRequest();
		$context = $request->getContext();
		if (!$context) return;

		$router = $request->getRouter();
		if (strpos(get_class($router), 'PageRouter') === false) return;

		$menu = (array) $templateMgr->getState('menu');
		if (empty($menu) || isset($menu['journalmetrics'])) return;

		$requestedPage = $router->getRequestedPage($request);
		$dispatcher = $request->getDispatcher();

		$menu['journalmetrics'] = array(
			'name' => __('plugins.generic.journalMetrics.menuTitle'),
			'url'  => $dispatcher->url($request, ROUTE_PAGE, null, 'journalmetrics', 'dashboard'),
			'isCurrent' => ($requestedPage === 'journalmetrics'),
		);
		$templateMgr->setState(array('menu' => $menu));
	}

	// ------------------------------------------------------------------
	//  Navigation Menu Item Type
	// ------------------------------------------------------------------

	/**
	 * Register the custom Navigation Menu Item type so editors can add
	 * "Journal Metrics" to any frontend navigation menu.
	 */
	public function addNavigationMenuItemTypes($hookName, $args) {
		$types =& $args[0];
		$types[NMI_TYPE_JOURNAL_METRICS] = array(
			'title'       => __('plugins.generic.journalMetrics.navMenuItem.title'),
			'description' => __('plugins.generic.journalMetrics.navMenuItem.description'),
		);
	}

	/**
	 * Set URL and visibility for the Journal Metrics NMI type.
	 */
	public function setNavigationMenuItemDisplaySettings($hookName, $args) {
		$navigationMenuItem = $args[0];

		if ($navigationMenuItem->getType() !== NMI_TYPE_JOURNAL_METRICS) {
			return;
		}

		$request = Application::get()->getRequest();
		$context = $request->getContext();

		$isVisible = false;
		if ($context) {
			$isVisible = (bool) $this->getSetting($context->getId(), 'enablePublicPage');
		}
		$navigationMenuItem->setIsDisplayed($isVisible);

		if ($context) {
			$dispatcher = $request->getDispatcher();
			$navigationMenuItem->setUrl($dispatcher->url(
				$request, ROUTE_PAGE, null, 'journalmetrics', 'publicpage'
			));
		}
	}

	// ------------------------------------------------------------------
	//  {journal_metric} Smarty function
	// ------------------------------------------------------------------

	/**
	 * {journal_metric key="submissionsReceived" year="2024" default="—"}
	 *
	 * Reads the snapshot cache — never triggers computation. On frontend
	 * templates only metrics whose visibility is "public" are emitted;
	 * anything else renders the default string.
	 */
	public function smartyJournalMetric($params, $smarty) {
		$request = Application::get()->getRequest();
		$context = $request->getContext();
		if (!$context) return '';

		$key = isset($params['key']) ? (string) $params['key'] : '';
		$year = isset($params['year']) ? (string) $params['year'] : null;
		$default = isset($params['default']) ? (string) $params['default'] : '';
		if ($key === '') return $default;

		$manager = $this->getSnapshotManager();
		$snapshot = $manager->getSnapshot($context->getId());
		if (!$snapshot) return $default;

		// Frontend requests only expose public metrics. Backend (dashboard,
		// component) templates may read everything.
		$router = $request->getRouter();
		$isBackend = is_a($router, 'PKPComponentRouter');
		if (!$isBackend) {
			$op = method_exists($router, 'getRequestedOp') ? $router->getRequestedOp($request) : '';
			$page = method_exists($router, 'getRequestedPage') ? $router->getRequestedPage($request) : '';
			$isBackend = ($page === 'journalmetrics' && $op === 'dashboard');
		}
		if (!$isBackend) {
			if ($manager->getVisibility($snapshot, $key) !== 'public') {
				return $default;
			}
			$nThreshold = (int) $this->getSettingWithDefault($context->getId(), 'rateNThreshold');
			if ($manager->isSuppressed($snapshot, $key, $nThreshold)) {
				return $default;
			}
		}

		$value = $manager->lookupMetricValue($snapshot, $key, $year);
		if ($value === null || $value === '') return $default;
		return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
	}

	// ------------------------------------------------------------------
	//  Metric catalogue
	// ------------------------------------------------------------------

	/**
	 * The complete visibility-controllable metric catalogue.
	 *
	 * Keys are stable identifiers used in the visibilityMap setting, the
	 * snapshot "visibility" section, the settings form and the templates.
	 * One entry may cover several snapshot values that always travel
	 * together (e.g. currentYearUsage = views + downloads of this year).
	 *
	 * @return array key => ['group' => string]
	 */
	public function getMetricCatalog() {
		return array(
			// G1 — Editorial
			'submissionsReceived'      => array('group' => 'editorial'),
			'submissionsAccepted'      => array('group' => 'editorial'),
			'submissionsDeclined'      => array('group' => 'editorial'),
			'acceptanceRate'           => array('group' => 'editorial'),
			'declineRate'              => array('group' => 'editorial'),
			'submissionsPublished'     => array('group' => 'editorial'),
			// G2 — Turnaround times
			'firstDecisionDays'        => array('group' => 'times'),
			'submissionToAcceptDays'   => array('group' => 'times'),
			'submissionToRejectDays'   => array('group' => 'times'),
			'reviewReportDays'         => array('group' => 'times'),
			'acceptToPublishDays'      => array('group' => 'times'),
			'submissionToPublishDays'  => array('group' => 'times'),
			// G3 — Usage
			'totalAbstractViews'       => array('group' => 'usage'),
			'totalGalleyDownloads'     => array('group' => 'usage'),
			'currentYearUsage'         => array('group' => 'usage'),
			'avgDownloadsPerArticle'   => array('group' => 'usage'),
			'topArticles'              => array('group' => 'usage'),
			'usageTrend'               => array('group' => 'usage'),
			'accessCountryCount'       => array('group' => 'usage'),
			// G4 — Community
			'uniqueAuthors'            => array('group' => 'community'),
			'reviewerCount'            => array('group' => 'community'),
			'memberCount'              => array('group' => 'community'),
			'authorCountries'          => array('group' => 'community'),
			'completedReviews'         => array('group' => 'community'),
			// G5 — Publication output
			'articlesPerYear'          => array('group' => 'output'),
			'issuesPerYear'            => array('group' => 'output'),
			'archiveDepth'             => array('group' => 'output'),
			// G6 — Manual metrics (one switch for the whole card group)
			'manualMetrics'            => array('group' => 'manual'),
		);
	}

	/**
	 * Metric keys that carry a methodology tooltip on rendered pages
	 * (rates use the core cohort semantics, times use fixed decision sets).
	 *
	 * @return array key => locale key of the tooltip text
	 */
	public function getMethodologyTooltips() {
		$base = 'plugins.generic.journalMetrics.tooltip.';
		return array(
			'acceptanceRate'          => $base . 'acceptanceRate',
			'declineRate'             => $base . 'declineRate',
			'firstDecisionDays'       => $base . 'firstDecisionDays',
			'submissionToAcceptDays'  => $base . 'submissionToAcceptDays',
			'submissionToRejectDays'  => $base . 'submissionToRejectDays',
			'reviewReportDays'        => $base . 'reviewReportDays',
			'acceptToPublishDays'     => $base . 'acceptToPublishDays',
			'submissionToPublishDays' => $base . 'submissionToPublishDays',
		);
	}

	// ------------------------------------------------------------------
	//  Settings helpers
	// ------------------------------------------------------------------

	/**
	 * Default settings values.
	 */
	public function getDefaultSettings() {
		return array(
			// Country statistics (countryStats parity)
			'uniqueStrategy'   => 'orcid',
			'orcidFallback'    => 'email_name',  // email_name | email | name | none
			'countryDisplay'   => 'both',         // code | name | both
			'sortOrder'        => 'count_desc',   // count_desc | name_asc
			'minThreshold'     => 1,
			'includeUnknown'   => 0,
			'showAuthorsTab'   => 1,
			'showArticlesTab'  => 1,
			// Public page
			'enablePublicPage' => 0,
			'publicPageTitle'  => 'Journal Metrics',
			// "Developed by" credit link on the PUBLIC page only (the
			// dashboard and settings page always show it). Default ON.
			'showDeveloperCredit' => 1,
			// Rate/time suppression threshold: rates and time metrics with
			// n below this value are hidden on rendered pages (R1/R9).
			'rateNThreshold'   => 10,
			// Editorial statistics coverage start year (0 = no coverage,
			// all-time). A transparent SCOPE DECLARATION, not a value edit:
			// when set, the public page automatically shows
			// "Editorial statistics cover YYYY–present".
			'statsCoverageStartYear' => 0,
		);
	}

	/**
	 * Get a plugin setting with default fallback.
	 */
	public function getSettingWithDefault($contextId, $name) {
		$val = $this->getSetting($contextId, $name);
		$defaults = $this->getDefaultSettings();
		return ($val !== null && $val !== '') ? $val : (isset($defaults[$name]) ? $defaults[$name] : null);
	}

	/**
	 * Get all scalar settings merged with defaults.
	 */
	public function getAllSettings($contextId) {
		$defaults = $this->getDefaultSettings();
		$settings = array();
		foreach ($defaults as $key => $default) {
			$settings[$key] = $this->getSettingWithDefault($contextId, $key);
		}
		return $settings;
	}

	/**
	 * The per-metric visibility map, merged with defaults.
	 * Default: every metric is "admin"; editors deliberately open metrics
	 * to "public". Values: hidden | admin | public.
	 *
	 * @return array metricKey => visibility
	 */
	public function getVisibilityMap($contextId) {
		$stored = $this->getSetting($contextId, 'visibilityMap');
		if (!is_array($stored)) $stored = array();
		$map = array();
		foreach ($this->getMetricCatalog() as $key => $meta) {
			$value = isset($stored[$key]) ? $stored[$key] : 'admin';
			if (!in_array($value, array('hidden', 'admin', 'public'), true)) $value = 'admin';
			$map[$key] = $value;
		}
		return $map;
	}

	/**
	 * The editorial-statistics coverage start year for a journal:
	 * 0 = no coverage set (all-time). Applies ONLY to workflow-derived
	 * metrics (editorial counters/rates, turnaround times, completed
	 * reviews); usage, community counts, output and manual metrics always
	 * cover the whole archive.
	 *
	 * @param int $contextId
	 * @return int
	 */
	public function getCoverageStartYear($contextId) {
		$year = (int) $this->getSettingWithDefault($contextId, 'statsCoverageStartYear');
		if ($year < 1900 || $year > (int) date('Y')) return 0;
		return $year;
	}

	/**
	 * Candidate metric keys for the sidebar block, in display order.
	 * The block may only ever show keys from this list.
	 *
	 * @return array
	 */
	public function getBlockMetricCandidates() {
		return array(
			'submissionsPublished', 'acceptanceRate', 'submissionToPublishDays',
			'totalAbstractViews', 'totalGalleyDownloads', 'uniqueAuthors',
			'authorCountries', 'archiveDepth',
		);
	}

	/**
	 * Metrics selected for the sidebar block (whitelisted against the
	 * candidate list). Default: every candidate. An explicitly saved
	 * empty selection means "show nothing" — the block then does not
	 * render at all. Selection never overrides the public-visibility or
	 * small-sample filters; those are applied afterwards.
	 *
	 * @param int $contextId
	 * @return array
	 */
	public function getBlockMetrics($contextId) {
		$stored = $this->getSetting($contextId, 'blockMetrics');
		$candidates = $this->getBlockMetricCandidates();
		if (!is_array($stored)) return $candidates;
		return array_values(array_intersect($candidates, $stored));
	}

	/**
	 * The manual metric rows (max 5), as stored.
	 *
	 * title and description are multilingual (array locale => string);
	 * legacy single-string values are accepted and resolved by localize().
	 *
	 * @return array [ ['title'=>, 'value'=>, 'source'=>, 'description'=>, 'lastUpdated'=>], ... ]
	 */
	public function getManualMetrics($contextId) {
		$rows = $this->getSetting($contextId, 'manualMetrics');
		return is_array($rows) ? array_slice(array_values($rows), 0, 5) : array();
	}

	// ------------------------------------------------------------------
	//  Localization helpers (journal-locale aware fields)
	// ------------------------------------------------------------------

	/**
	 * The journal's supported form locales as code => display name,
	 * primary locale first.
	 *
	 * @param Context $context
	 * @return array
	 */
	public function getSupportedLocaleNames($context) {
		$locales = $context->getSupportedFormLocales();
		if (empty($locales)) $locales = array($context->getPrimaryLocale());
		$primary = $context->getPrimaryLocale();
		if (in_array($primary, $locales, true)) {
			$locales = array_merge(array($primary), array_diff($locales, array($primary)));
		}
		$all = AppLocale::getAllLocales();
		$out = array();
		foreach ($locales as $locale) {
			$out[$locale] = isset($all[$locale]) ? $all[$locale] : $locale;
		}
		return $out;
	}

	/**
	 * Resolve a possibly-localized value (array locale => string, or a
	 * legacy plain string) for the current UI locale, falling back to the
	 * journal's primary locale, then to any non-empty translation.
	 *
	 * @param mixed $value
	 * @param Context|null $context
	 * @return string
	 */
	public function localize($value, $context = null) {
		if (!is_array($value)) return trim((string) $value);
		$preferred = array(AppLocale::getLocale());
		if ($context) $preferred[] = $context->getPrimaryLocale();
		foreach ($preferred as $locale) {
			if (isset($value[$locale]) && trim((string) $value[$locale]) !== '') {
				return trim((string) $value[$locale]);
			}
		}
		foreach ($value as $translation) {
			if (trim((string) $translation) !== '') return trim((string) $translation);
		}
		return '';
	}

	// ------------------------------------------------------------------
	//  Country names (shared by handler and community provider)
	// ------------------------------------------------------------------

	/**
	 * ISO 3166-1 country name map (Sokil ISO codes, bundled with OJS;
	 * hardcoded fallback otherwise).
	 *
	 * @return array ['TR' => 'Turkey', ...]
	 */
	public function getCountryNames() {
		static $map = null;
		if ($map !== null) return $map;

		$map = array();
		try {
			if (class_exists('\Sokil\IsoCodes\IsoCodesFactory')) {
				$isoCodes = new \Sokil\IsoCodes\IsoCodesFactory();
				foreach ($isoCodes->getCountries() as $country) {
					$map[$country->getAlpha2()] = $country->getName();
				}
			}
		} catch (\Exception $e) {
			// fallback below
		}
		if (empty($map)) {
			$map = array(
				'AF'=>'Afghanistan','AL'=>'Albania','DZ'=>'Algeria','AD'=>'Andorra','AO'=>'Angola',
				'AR'=>'Argentina','AM'=>'Armenia','AU'=>'Australia','AT'=>'Austria','AZ'=>'Azerbaijan',
				'BH'=>'Bahrain','BD'=>'Bangladesh','BY'=>'Belarus','BE'=>'Belgium','BA'=>'Bosnia and Herzegovina',
				'BR'=>'Brazil','BG'=>'Bulgaria','CA'=>'Canada','CL'=>'Chile','CN'=>'China',
				'CO'=>'Colombia','HR'=>'Croatia','CU'=>'Cuba','CY'=>'Cyprus','CZ'=>'Czechia',
				'DK'=>'Denmark','EG'=>'Egypt','EE'=>'Estonia','ET'=>'Ethiopia','FI'=>'Finland',
				'FR'=>'France','GE'=>'Georgia','DE'=>'Germany','GH'=>'Ghana','GR'=>'Greece',
				'HU'=>'Hungary','IN'=>'India','ID'=>'Indonesia','IR'=>'Iran','IQ'=>'Iraq',
				'IE'=>'Ireland','IL'=>'Israel','IT'=>'Italy','JP'=>'Japan','JO'=>'Jordan',
				'KZ'=>'Kazakhstan','KE'=>'Kenya','KR'=>'South Korea','KW'=>'Kuwait','LB'=>'Lebanon',
				'LY'=>'Libya','LT'=>'Lithuania','LU'=>'Luxembourg','MY'=>'Malaysia','MX'=>'Mexico',
				'MA'=>'Morocco','NL'=>'Netherlands','NZ'=>'New Zealand','NG'=>'Nigeria','NO'=>'Norway',
				'OM'=>'Oman','PK'=>'Pakistan','PS'=>'Palestine','PE'=>'Peru','PH'=>'Philippines',
				'PL'=>'Poland','PT'=>'Portugal','QA'=>'Qatar','RO'=>'Romania','RU'=>'Russia',
				'SA'=>'Saudi Arabia','RS'=>'Serbia','SG'=>'Singapore','SK'=>'Slovakia','SI'=>'Slovenia',
				'ZA'=>'South Africa','ES'=>'Spain','SD'=>'Sudan','SE'=>'Sweden','CH'=>'Switzerland',
				'SY'=>'Syria','TW'=>'Taiwan','TH'=>'Thailand','TN'=>'Tunisia','TR'=>'Turkey',
				'UA'=>'Ukraine','AE'=>'United Arab Emirates','GB'=>'United Kingdom','US'=>'United States',
				'UY'=>'Uruguay','UZ'=>'Uzbekistan','VE'=>'Venezuela','VN'=>'Vietnam','YE'=>'Yemen',
			);
		}
		return $map;
	}

	/**
	 * Enrich raw country rows to [{code, name, count}, ...].
	 *
	 * @param array $rows raw DAO rows
	 * @param string $countField 'author_count' or 'article_count'
	 * @return array
	 */
	public function enrichCountryData($rows, $countField) {
		$names = $this->getCountryNames();
		$result = array();
		foreach ($rows as $row) {
			$code = strtoupper(trim($row['country']));
			if ($code === '') {
				$code = 'XX';
				$name = 'Unknown';
			} else {
				$name = isset($names[$code]) ? $names[$code] : $code;
			}
			$result[] = array(
				'code'  => $code,
				'name'  => $name,
				'count' => (int) $row[$countField],
			);
		}
		return $result;
	}

	// ------------------------------------------------------------------
	//  Component accessors
	// ------------------------------------------------------------------

	/**
	 * @return JournalMetricsSnapshotManager
	 */
	public function getSnapshotManager() {
		$this->import('classes.JournalMetricsSnapshotManager');
		static $manager = null;
		if ($manager === null) $manager = new JournalMetricsSnapshotManager($this);
		return $manager;
	}

	/**
	 * @return JournalMetricsProviderRegistry
	 */
	public function getProviderRegistry() {
		$this->import('classes.providers.JournalMetricsProviderRegistry');
		static $registry = null;
		if ($registry === null) $registry = new JournalMetricsProviderRegistry($this);
		return $registry;
	}

	/**
	 * @return JournalMetricsCountryDAO
	 */
	public function getCountryDAO() {
		$this->import('classes.JournalMetricsCountryDAO');
		return new JournalMetricsCountryDAO();
	}
}
