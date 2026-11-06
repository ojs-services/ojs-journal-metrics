<?php
/**
 * @file plugins/generic/journalMetrics/JournalMetricsPlugin.php
 *
 * Copyright (c) 2026 ojs-services.com
 * Distributed under the GNU GPL v3.
 *
 * @class JournalMetricsPlugin
 * @brief Main class for the Journal Metrics plugin (OJS 3.4 and 3.5).
 *
 * Provides a full journal metrics catalogue (editorial, turnaround times,
 * usage, community, publication output, manual metrics) with a nightly
 * snapshot cache, an admin dashboard, a public page, a sidebar block and
 * a {journal_metric} Smarty function.
 */

namespace APP\plugins\generic\journalMetrics;

use APP\core\Application;
use APP\plugins\generic\journalMetrics\classes\JournalMetricsCompat;
use APP\plugins\generic\journalMetrics\classes\JournalMetricsCountryDAO;
use APP\plugins\generic\journalMetrics\classes\JournalMetricsSnapshotManager;
use APP\plugins\generic\journalMetrics\classes\JournalMetricsSnapshotTask;
use APP\plugins\generic\journalMetrics\classes\providers\JournalMetricsProviderRegistry;
use APP\template\TemplateManager;
use PKP\config\Config;
use PKP\core\PKPComponentRouter;
use PKP\core\PKPPageRouter;
use PKP\facades\Locale;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\RedirectAction;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\plugins\interfaces\HasTaskScheduler;
use PKP\plugins\PluginRegistry;
use PKP\scheduledTask\PKPScheduler;
use PKP\security\Role;
use PKP\security\Validation;
use PKP\site\VersionCheck;

if (!interface_exists(HasTaskScheduler::class)) {
	require_once __DIR__ . '/classes/compat/HasTaskSchedulerPolyfill.php';
}

class JournalMetricsPlugin extends GenericPlugin implements HasTaskScheduler {

	/** Navigation menu item type (the value stored in the database) */
	public const NMI_TYPE_JOURNAL_METRICS = 'NMI_TYPE_JOURNAL_METRICS';

	/**
	 * @copydoc Plugin::register()
	 */
	public function register($category, $path, $mainContextId = null) {
		$success = parent::register($category, $path, $mainContextId);

		// OJS 3.4: the nightly task goes through Acron. Registered
		// regardless of the enabled state so Acron can (re)parse the
		// crontab during installation; the callback checks getEnabled().
		// OJS 3.5 rejects this hook (it throws) — there the task is added
		// by registerSchedules() below.
		if (!JournalMetricsCompat::hasCoreScheduler()) {
			Hook::add('AcronPlugin::parseCronTab', array($this, 'callbackParseCronTab'));
		}

		if ($success && $this->getEnabled()) {
			// Route /journalmetrics requests to our handler
			Hook::add('LoadHandler', array($this, 'callbackLoadHandler'));

			// {journal_metric} Smarty function + sidebar block stylesheet
			Hook::add('TemplateManager::display', array($this, 'callbackTemplateDisplay'));

			// Backend side menu entry
			Hook::add('TemplateManager::setupBackendPage', array($this, 'callbackSetupBackendPage'));

			// Frontend navigation menu item type
			Hook::add('NavigationMenus::itemTypes', array($this, 'addNavigationMenuItemTypes'));
			Hook::add('NavigationMenus::displaySettings', array($this, 'setNavigationMenuItemDisplaySettings'));

			// Sidebar block
			PluginRegistry::register('blocks', new JournalMetricsBlockPlugin($this), $this->getPluginPath());
		}

		return $success;
	}

	/**
	 * OJS 3.5: add the nightly snapshot task to the core scheduler. It runs
	 * hourly; the task itself starts a full cycle at most once per ~20 hours
	 * and otherwise only continues an unfinished one.
	 *
	 * @copydoc \PKP\plugins\interfaces\HasTaskScheduler::registerSchedules()
	 */
	public function registerSchedules(PKPScheduler $scheduler): void {
		$scheduler
			->addSchedule(new JournalMetricsSnapshotTask())
			->hourly()
			->name(JournalMetricsSnapshotTask::class)
			->withoutOverlapping();
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
		$actions = array();

		if ($this->getEnabled()) {
			$dispatcher = $request->getDispatcher();
			$actions[] = new LinkAction(
				'dashboard',
				new RedirectAction(
					$dispatcher->url($request, Application::ROUTE_PAGE, null, 'journalmetrics', 'dashboard')
				),
				__('plugins.generic.journalMetrics.dashboard'),
				null
			);

			// Settings live on a full backend page (no modal)
			$actions[] = new LinkAction(
				'settings',
				new RedirectAction(
					$dispatcher->url($request, Application::ROUTE_PAGE, null, 'journalmetrics', 'settings')
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
		$page = $args[0];
		$handler =& $args[3];

		if ($page === 'journalmetrics') {
			$handler = new JournalMetricsHandler();
			return true;
		}
		return false;
	}

	/**
	 * OJS 3.4: register our scheduled task file with Acron.
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
	 * TemplateManager::display callback: registers the {journal_metric}
	 * Smarty function and the sidebar block stylesheet.
	 */
	public function callbackTemplateDisplay($hookName, $args) {
		$templateMgr = $args[0];

		// Registering a Smarty plugin twice throws; the plugin list may
		// build a second instance of this class in the same request.
		if (!isset($templateMgr->registered_plugins['function']['journal_metric'])) {
			$templateMgr->registerPlugin('function', 'journal_metric', array($this, 'smartyJournalMetric'));
		}

		$this->_registerBlockStyles($templateMgr);

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

		$release = '2.0.0.0';
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
	 * TemplateManager::setupBackendPage callback (no arguments): add the
	 * "Journal Metrics" entry to the backend side menu.
	 */
	public function callbackSetupBackendPage($hookName, $args) {
		$request = Application::get()->getRequest();
		$context = $request->getContext();
		if (!$context) return false;

		// Journal managers and site admins only. Reviewers/authors also
		// have a backend (the menu state exists for them too), but the
		// metrics dashboard is not part of their interface — the handler
		// would deny the page anyway; the link must not appear either.
		$user = $request->getUser();
		if (!$user) return false;
		if (!$user->hasRole(array(Role::ROLE_ID_MANAGER), $context->getId())
				&& !Validation::isSiteAdmin()) return false;

		$router = $request->getRouter();
		if (!$router instanceof PKPPageRouter) return false;

		$templateMgr = TemplateManager::getManager($request);
		$menu = (array) $templateMgr->getState('menu');
		if (empty($menu) || isset($menu['journalmetrics'])) return false;

		$menu['journalmetrics'] = array(
			'name' => __('plugins.generic.journalMetrics.menuTitle'),
			'url'  => $request->getDispatcher()->url($request, Application::ROUTE_PAGE, null, 'journalmetrics', 'dashboard'),
			'isCurrent' => ($router->getRequestedPage($request) === 'journalmetrics'),
			// OJS 3.5 menu entries need an icon from the core set; 3.4 ignores it.
			'icon' => 'Statistics',
		);
		$templateMgr->setState(array('menu' => $menu));
		return false;
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
		$types[self::NMI_TYPE_JOURNAL_METRICS] = array(
			'title'       => __('plugins.generic.journalMetrics.navMenuItem.title'),
			'description' => __('plugins.generic.journalMetrics.navMenuItem.description'),
		);
	}

	/**
	 * Set URL and visibility for the Journal Metrics NMI type.
	 */
	public function setNavigationMenuItemDisplaySettings($hookName, $args) {
		$navigationMenuItem = $args[0];

		if ($navigationMenuItem->getType() !== self::NMI_TYPE_JOURNAL_METRICS) {
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
				$request, Application::ROUTE_PAGE, null, 'journalmetrics', 'publicpage'
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
		$isBackend = ($router instanceof PKPComponentRouter);
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
		if (in_array($key, array('acceptanceRate', 'declineRate'), true)) {
			$value = self::formatRate($value);
		}
		return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
	}

	/**
	 * Editorial decision rates over the DECIDED cohort:
	 * accepted ÷ (accepted + declined) and declined ÷ (accepted + declined),
	 * unrounded. The single definition used by every surface (dashboard,
	 * public page, sidebar block, {journal_metric}).
	 *
	 * The core service's own rates are not used: without a date range it
	 * divides by ALL received submissions (those still under review
	 * included), with a range it counts a submission-date cohort that does
	 * not match the displayed decision counts, and it rounds to 2 decimals.
	 *
	 * @param array $row Snapshot row holding submissionsAccepted /
	 *  submissionsDeclined (and optionally the desk/post-review split)
	 * @return array Rate keys => float, or null when nothing was decided
	 *  ("no rate" — never 0%)
	 */
	public static function decisionRates($row) {
		$accepted = isset($row['submissionsAccepted']) ? (int) $row['submissionsAccepted'] : 0;
		$declined = isset($row['submissionsDeclined']) ? (int) $row['submissionsDeclined'] : 0;
		$desk = isset($row['submissionsDeclinedDeskReject']) ? (int) $row['submissionsDeclinedDeskReject'] : 0;
		$review = isset($row['submissionsDeclinedPostReview']) ? (int) $row['submissionsDeclinedPostReview'] : 0;
		$decided = $accepted + $declined;
		if ($decided <= 0) {
			return array(
				'acceptanceRate' => null, 'declineRate' => null,
				'declinedDeskRate' => null, 'declinedReviewRate' => null,
			);
		}
		return array(
			'acceptanceRate'     => $accepted / $decided,
			'declineRate'        => $declined / $decided,
			'declinedDeskRate'   => $desk / $decided,
			'declinedReviewRate' => $review / $decided,
		);
	}

	/**
	 * Number formatting follows the INTERFACE language, never the browser's:
	 * Turkish gets Turkish conventions, every other UI locale falls back to
	 * English ones (the plugin's labels fall back to English there too).
	 * The JS renderer receives the same choice via numberLocaleTag(), so
	 * server- and client-rendered numbers on one page always agree.
	 *
	 * @param string|null $locale OJS locale code; null = current UI locale
	 * @return bool
	 */
	private static function _isTurkishNumbers($locale) {
		if ($locale === null) $locale = Locale::getLocale();
		return strpos((string) $locale, 'tr') === 0;
	}

	/**
	 * BCP 47 tag the JS renderer formats numbers with ('tr-TR' / 'en-US').
	 */
	public static function numberLocaleTag($locale = null) {
		return self::_isTurkishNumbers($locale) ? 'tr-TR' : 'en-US';
	}

	/**
	 * Display form of a plain number in the interface language
	 * (2.825 / 192,6 vs 2,825 / 192.6). Up to 3 decimals are kept, as
	 * JavaScript's toLocaleString() does, so block and page match.
	 */
	public static function formatNumber($value, $locale = null) {
		$value = (float) $value;
		$plain = rtrim(rtrim(sprintf('%.3F', $value), '0'), '.');
		$dot = strpos($plain, '.');
		$decimals = ($dot === false) ? 0 : strlen($plain) - $dot - 1;
		return self::_isTurkishNumbers($locale)
			? number_format($value, $decimals, ',', '.')
			: number_format($value, $decimals, '.', ',');
	}

	/**
	 * Display form of a rate: percent with exactly one decimal, in the
	 * interface language ("%66,7" / "66.7%"). Rounding happens here only —
	 * stored values stay unrounded.
	 */
	public static function formatRate($rate, $locale = null) {
		$percent = round(((float) $rate) * 100, 1);
		return self::_isTurkishNumbers($locale)
			? '%' . number_format($percent, 1, ',', '.')
			: number_format($percent, 1, '.', ',') . '%';
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
			// Editorial
			'submissionsReceived'      => array('group' => 'editorial'),
			'submissionsAccepted'      => array('group' => 'editorial'),
			'submissionsDeclined'      => array('group' => 'editorial'),
			'acceptanceRate'           => array('group' => 'editorial'),
			'declineRate'              => array('group' => 'editorial'),
			'submissionsPublished'     => array('group' => 'editorial'),
			// Turnaround times
			'firstDecisionDays'        => array('group' => 'times'),
			'submissionToAcceptDays'   => array('group' => 'times'),
			'submissionToRejectDays'   => array('group' => 'times'),
			'reviewReportDays'         => array('group' => 'times'),
			'acceptToPublishDays'      => array('group' => 'times'),
			'submissionToPublishDays'  => array('group' => 'times'),
			// Usage
			'totalAbstractViews'       => array('group' => 'usage'),
			'totalGalleyDownloads'     => array('group' => 'usage'),
			'currentYearUsage'         => array('group' => 'usage'),
			'avgDownloadsPerArticle'   => array('group' => 'usage'),
			'topArticles'              => array('group' => 'usage'),
			'usageTrend'               => array('group' => 'usage'),
			'accessCountryCount'       => array('group' => 'usage'),
			// Community
			'uniqueAuthors'            => array('group' => 'community'),
			'reviewerCount'            => array('group' => 'community'),
			'memberCount'              => array('group' => 'community'),
			'authorCountries'          => array('group' => 'community'),
			'completedReviews'         => array('group' => 'community'),
			// Publication output
			'articlesPerYear'          => array('group' => 'output'),
			'issuesPerYear'            => array('group' => 'output'),
			'archiveDepth'             => array('group' => 'output'),
			// Manual metrics (one switch for the whole card group)
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
			// n below this value are hidden on rendered pages.
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
		if ($name === 'publicPageTitle' && is_array($val)) {
			$val = self::normalizeLocaleKeys($val, $this->_contextLocaleCodes($contextId));
		}
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
		if (!is_array($rows)) return array();
		$rows = array_slice(array_values($rows), 0, 5);
		$locales = $this->_contextLocaleCodes($contextId);
		foreach ($rows as $i => $row) {
			if (!is_array($row)) continue;
			foreach (array('title', 'description') as $field) {
				if (isset($row[$field]) && is_array($row[$field])) {
					$rows[$i][$field] = self::normalizeLocaleKeys($row[$field], $locales);
				}
			}
		}
		return $rows;
	}

	/**
	 * Values saved by the OJS 3.3 line are keyed by 3.3 locale codes
	 * (en_US, tr_TR); OJS 3.4+ uses en, tr (pt_BR, fr_CA… keep the region).
	 * The core upgrade does not touch plugin data, so legacy keys are moved
	 * here, on read, to the journal locale they belong to. A value already
	 * stored under the new code wins. Saving the settings page then writes
	 * the new codes only.
	 *
	 * @param array $value locale => string
	 * @param array $locales the journal's locale codes
	 * @return array
	 */
	public static function normalizeLocaleKeys(array $value, array $locales) {
		$out = array();
		$legacy = array();
		foreach ($value as $key => $translation) {
			if (in_array($key, $locales, true) || !preg_match('/^([a-z]{2,3})_[A-Z]{2}/', (string) $key)) {
				$out[$key] = $translation;
			} else {
				$legacy[$key] = $translation;
			}
		}
		foreach ($legacy as $key => $translation) {
			$language = substr($key, 0, strpos($key, '_'));
			$target = in_array($language, $locales, true) ? $language : $key;
			if (!isset($out[$target]) || trim((string) $out[$target]) === '') {
				$out[$target] = $translation;
			}
		}
		return $out;
	}

	/**
	 * All locale codes a journal uses (form, interface and primary).
	 *
	 * @param int $contextId
	 * @return array
	 */
	protected function _contextLocaleCodes($contextId) {
		static $cache = array();
		$contextId = (int) $contextId;
		if (!isset($cache[$contextId])) {
			$context = $contextId ? Application::getContextDAO()->getById($contextId) : null;
			$cache[$contextId] = $context ? array_values(array_unique(array_merge(
				(array) $context->getSupportedFormLocales(),
				(array) $context->getSupportedLocales(),
				array($context->getPrimaryLocale())
			))) : array();
		}
		return $cache[$contextId];
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
		$names = (array) $context->getSupportedFormLocaleNames();
		if (empty($names)) {
			$primary = $context->getPrimaryLocale();
			$names = array($primary => $primary);
		}
		$primary = $context->getPrimaryLocale();
		if (isset($names[$primary])) {
			$names = array($primary => $names[$primary]) + $names;
		}
		return $names;
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
		$preferred = array(Locale::getLocale());
		if ($context) $preferred[] = $context->getPrimaryLocale();
		foreach ($preferred as $locale) {
			if (isset($value[$locale]) && trim((string) $value[$locale]) !== '') {
				return trim((string) $value[$locale]);
			}
			// Snapshots written by the OJS 3.3 line carry 3.3 locale codes
			// (tr_TR for tr) until the next recompute.
			foreach ($value as $key => $translation) {
				if (strpos((string) $key, $locale . '_') === 0 && trim((string) $translation) !== '') {
					return trim((string) $translation);
				}
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
	 * ISO 3166-1 country name map in the interface language (the core
	 * country list; hardcoded English fallback if it is unavailable).
	 *
	 * @return array ['TR' => 'Türkiye', ...]
	 */
	public function getCountryNames() {
		static $map = null;
		if ($map !== null) return $map;

		$map = array();
		try {
			foreach (Locale::getCountries() as $country) {
				$map[$country->getAlpha2()] = $country->getLocalName();
			}
		} catch (\Throwable $e) {
			error_log('Journal Metrics: country list unavailable, using the built-in names: ' . $e->getMessage());
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
		static $manager = null;
		if ($manager === null) $manager = new JournalMetricsSnapshotManager($this);
		return $manager;
	}

	/**
	 * @return JournalMetricsProviderRegistry
	 */
	public function getProviderRegistry() {
		static $registry = null;
		if ($registry === null) $registry = new JournalMetricsProviderRegistry($this);
		return $registry;
	}

	/**
	 * @return JournalMetricsCountryDAO
	 */
	public function getCountryDAO() {
		return new JournalMetricsCountryDAO();
	}
}
