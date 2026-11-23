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
		// state so acron can (re)parse the crontab at site level and during
		// installation. The callback checks every journal, not the request's.
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

		// Keep the nightly task in Acron's task list
		$this->ensureAcronTask();

		if ($page === 'journalmetrics') {
			require_once($this->getPluginPath() . '/JournalMetricsHandler.inc.php');
			define('HANDLER_CLASS', 'JournalMetricsHandler');
			return true;
		}
		return false;
	}

	/** Checked Acron's task list in this request already */
	private static $_acronTaskChecked = false;

	/** Hours without a completed nightly cycle before managers are warned */
	const NIGHTLY_STALE_HOURS = 48;

	/**
	 * Self-repair of Acron's task list.
	 *
	 * Acron builds its list only when a plugin is installed, upgraded,
	 * enabled or disabled. Once our task is missing from it (a rebuild by
	 * an older version of this plugin that answered for the request's
	 * journal only, a copy-and-command-line update that never rebuilds,
	 * a hand-edited list), nothing ever puts it back and the figures stop
	 * updating. So on page requests in a journal where the plugin is on,
	 * check the list and add the task when it is missing.
	 *
	 * Cheap: the list is read from the plugin settings cache that Acron
	 * itself reads on every request; it is written only when the task is
	 * missing (or listed twice). Running twice is impossible: Acron and the
	 * server cron share the task's last-run time, and the task holds its
	 * own run lock.
	 */
	public function ensureAcronTask() {
		if (self::$_acronTaskChecked) return;
		self::$_acronTaskChecked = true;
		if (!Config::getVar('general', 'installed')) return;
		try {
			$dao = DAORegistry::getDAO('PluginSettingsDAO');
			if (!$dao->getSetting(0, 'acronplugin', 'enabled')) return; // tasks run another way
			$crontab = $dao->getSetting(0, 'acronplugin', 'crontab');
			if (!is_array($crontab)) return; // Acron builds its list on its next request

			$ours = array(
				// as in scheduledTasks.xml: plugins.generic.journalMetrics.classes.JournalMetricsSnapshotTask
				'className' => str_replace(array('/', '\\'), '.', $this->getPluginPath()) . '.classes.JournalMetricsSnapshotTask',
				'frequency' => array('minute' => '0'),
				'args'      => array(),
			);
			$kept = array();
			$found = false;
			$changed = false;
			foreach ($crontab as $task) {
				$class = is_array($task) && isset($task['className']) ? (string) $task['className'] : '';
				if ($class === $ours['className']) {
					if ($found) { $changed = true; continue; } // listed twice
					$found = true;
				}
				$kept[] = $task;
			}
			if (!$found) {
				$kept[] = $ours;
				$changed = true;
			}
			if ($changed) {
				// through the DAO, so the settings cache is updated as well
				$dao->updateSetting(0, 'acronplugin', 'crontab', $kept, 'object');
			}
		} catch (Throwable $e) {
			error_log('Journal Metrics: could not check the Acron task list: ' . $e->getMessage());
		}
	}

	/**
	 * Has the nightly calculation stopped? For the managers' notice on the
	 * settings page. Measured by the last COMPLETED full cycle, which only
	 * the scheduled task writes (a dashboard recompute does not hide it).
	 *
	 * @param array|null $snapshot this journal's snapshot
	 * @return array|null null = fine; else ['days' => int, 'date' => 'Y-m-d'|null]
	 *  (date null = no full cycle has ever completed)
	 */
	public function nightlyRunStatus($snapshot) {
		$limit = time() - self::NIGHTLY_STALE_HOURS * 3600;
		$last = $this->getSetting(0, 'snapshotCycleCompletedAt');
		$lastTs = $last ? strtotime((string) $last) : false;
		if ($lastTs) {
			if ($lastTs >= $limit) return null;
			return array('days' => (int) floor((time() - $lastTs) / 86400), 'date' => date('Y-m-d', $lastTs));
		}
		// never completed: warn only once the figures themselves are old
		// (a fresh install computes on the first dashboard visit)
		$generated = is_array($snapshot) && !empty($snapshot['generatedAt']) ? strtotime((string) $snapshot['generatedAt']) : false;
		if ($generated && $generated < $limit) {
			return array('days' => (int) floor((time() - $generated) / 86400), 'date' => null);
		}
		return null;
	}

	/**
	 * Register our scheduled task file with acron.
	 * @see AcronPlugin::parseCronTab()
	 */
	public function callbackParseCronTab($hookName, $args) {
		// The plugin is enabled per journal, but acron rebuilds its task
		// list for the whole site — from the site administration, on
		// install/upgrade, or while another journal toggles a plugin.
		// getEnabled() answers only for the request's journal (none at
		// site level), so the task must not depend on it: it is listed
		// while the plugin is enabled in ANY journal. The task itself
		// skips journals where the plugin is off.
		if (!Config::getVar('general', 'installed') || $this->_isEnabledInAnyContext()) {
			$taskFilesPath =& $args[0]; // Reference needed.
			// Several plugin instances may answer in one request (the plugin
			// grid builds its own); list the file only once.
			$file = $this->getPluginPath() . DIRECTORY_SEPARATOR . 'scheduledTasks.xml';
			if (!in_array($file, $taskFilesPath, true)) {
				$taskFilesPath[] = $file;
			}
		}
		return false;
	}

	/**
	 * Is the plugin enabled in at least one journal (enabled or not)?
	 * @return bool
	 */
	private function _isEnabledInAnyContext() {
		$contexts = Application::getContextDAO()->getAll();
		while ($context = $contexts->next()) {
			if ($this->getSetting($context->getId(), 'enabled')) {
				return true;
			}
		}
		return false;
	}

	/**
	 * TemplateManager::display callback: injects the backend sidebar link
	 * and registers the {journal_metric} Smarty function.
	 */
	public function callbackTemplateDisplay($hookName, $args) {
		$templateMgr = $args[0];

		// Register the Smarty function once per request. Registering it
		// twice throws; a second instance of this class in the same request
		// has its own flag, so ask Smarty as well.
		if (!$this->_smartyFunctionRegistered && !isset($templateMgr->registered_plugins['function']['journal_metric'])) {
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

		// Journal managers and site admins only. Reviewers/authors also
		// have a backend (the menu state exists for them too), but the
		// metrics dashboard is not part of their interface — the handler
		// would deny the page anyway; the link must not appear either.
		$user = $request->getUser();
		if (!$user) return;
		import('lib.pkp.classes.security.Validation');
		if (!$user->hasRole(array(ROLE_ID_MANAGER), $context->getId())
				&& !Validation::isSiteAdmin()) return;

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
			// hidden "Editorial process" section: its figures stay hidden here too
			if ($this->isEditorialSectionMetric($key)
					&& self::editorialSectionHidden($this->getSettingWithDefault($context->getId(), 'editorialSection'), $snapshot) !== null) {
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
		if ($locale === null) $locale = AppLocale::getLocale();
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
			// Publications: every published article (card = chart total)
			'submissionsPublished'     => array('group' => 'output'),
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
			// The "Editorial process" section of the public page:
			// auto = shown once EDITORIAL_SECTION_MIN_DECIDED submissions
			// have a final decision; show | hide = always / never.
			'editorialSection' => 'auto',
			// Advanced: split the articles-per-year chart into articles
			// that went through the editorial process and articles added
			// without an editor decision.
			'yearBreakdown'    => 0,
		);
	}

	/** Decided submissions needed before "auto" shows the editorial section. */
	const EDITORIAL_SECTION_MIN_DECIDED = 20;

	/**
	 * Submissions with a final decision (accepted + declined), as the
	 * editorial section counts them.
	 *
	 * @param array|null $snapshot
	 * @return int
	 */
	public static function decidedCount($snapshot) {
		$allTime = isset($snapshot['editorial']['allTime']) ? $snapshot['editorial']['allTime'] : array();
		return (isset($allTime['submissionsAccepted']) ? (int) $allTime['submissionsAccepted'] : 0)
			+ (isset($allTime['submissionsDeclined']) ? (int) $allTime['submissionsDeclined'] : 0);
	}

	/**
	 * Is the "Editorial process" section hidden from visitors? One rule
	 * for the public page, the sidebar block and {journal_metric}.
	 *
	 * @param string $mode the editorialSection setting (auto | show | hide)
	 * @param array|null $snapshot
	 * @return string|null null = shown, 'setting' = hidden by the editor,
	 *  'auto' = too few decided submissions yet
	 */
	public static function editorialSectionHidden($mode, $snapshot) {
		$mode = in_array($mode, array('auto', 'show', 'hide'), true) ? $mode : 'auto';
		if ($mode === 'hide') return 'setting';
		if ($mode === 'auto' && self::decidedCount($snapshot) < self::EDITORIAL_SECTION_MIN_DECIDED) return 'auto';
		return null;
	}

	/**
	 * Metric keys shown under "Editorial process" (editorial counters and
	 * rates, turnaround times, reviewer figures).
	 *
	 * @param string $key
	 * @return bool
	 */
	public function isEditorialSectionMetric($key) {
		if (in_array($key, array('reviewerCount', 'completedReviews'), true)) return true;
		$catalog = $this->getMetricCatalog();
		return isset($catalog[$key]) && in_array($catalog[$key]['group'], array('editorial', 'times'), true);
	}

	/**
	 * Sections left out of the publication counts (advanced setting),
	 * limited to sections that still exist in this journal.
	 *
	 * @param int $contextId
	 * @return int[]
	 */
	public function getExcludedSectionIds($contextId) {
		$stored = $this->getSetting((int) $contextId, 'excludedSections');
		if (!is_array($stored) || empty($stored)) return array();
		$ids = array_values(array_unique(array_filter(array_map('intval', $stored))));
		if (empty($ids)) return array();
		$result = DAORegistry::getDAO('UserDAO')->retrieve(
			'SELECT section_id FROM sections WHERE journal_id = ? AND section_id IN (' . implode(',', $ids) . ')',
			array((int) $contextId)
		);
		$valid = array();
		foreach ($result as $row) $valid[] = (int) $row->section_id;
		return $valid;
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
	/**
	 * Is this a computed metric's name? A value the editor enters by hand
	 * must not stand in for (or contradict) a figure the plugin computes:
	 * rates and durations always come from the journal's own data.
	 * Compared against the metric names in every language the plugin
	 * ships, ignoring case, spacing, punctuation and Turkish letters.
	 *
	 * @param string|array $title a plain title or locale => title
	 * @return bool
	 */
	public function isComputedMetricTitle($title) {
		static $names = null;
		if ($names === null) {
			$names = array();
			foreach (glob($this->getPluginPath() . '/locale/*/locale.po') ?: array() as $file) {
				$po = (string) file_get_contents($file);
				if (preg_match_all('/msgid "plugins\.generic\.journalMetrics\.metric\.(\w+)"\s*\nmsgstr "((?:[^"\\\\]|\\\\.)*)"/', $po, $m, PREG_SET_ORDER)) {
					foreach ($m as $entry) {
						if ($entry[1] === 'manualMetrics') continue;
						$name = self::_normalizeMetricName(stripcslashes($entry[2]));
						if ($name !== '') $names[$name] = true;
					}
				}
			}
		}
		foreach ((array) $title as $value) {
			if (is_string($value) && isset($names[self::_normalizeMetricName($value)])) return true;
		}
		return false;
	}

	/**
	 * Lower case, Turkish letters folded, punctuation and spacing removed.
	 */
	private static function _normalizeMetricName($text) {
		$text = function_exists('mb_strtolower') ? mb_strtolower((string) $text, 'UTF-8') : strtolower((string) $text);
		$text = strtr($text, array('ı' => 'i', 'i̇' => 'i', 'ş' => 's', 'ğ' => 'g', 'ü' => 'u', 'ö' => 'o', 'ç' => 'c', 'â' => 'a', 'î' => 'i', 'û' => 'u'));
		return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $text));
	}

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

	/**
	 * Imported submissions of a journal — the ones every workflow metric
	 * leaves out (JournalMetricsBaseProvider::importedCondition, OJS core's
	 * rule). Shown on the settings page.
	 *
	 * @param int $contextId
	 * @return int
	 */
	public function countImportedSubmissions($contextId) {
		$this->import('classes.providers.JournalMetricsBaseProvider');
		$result = DAORegistry::getDAO('UserDAO')->retrieve(
			'SELECT COUNT(*) AS cnt FROM submissions s WHERE s.context_id = ? AND '
				. JournalMetricsBaseProvider::importedCondition('s'),
			array((int) $contextId)
		);
		$row = $result->current();
		return $row ? (int) $row->cnt : 0;
	}

	/**
	 * Does the current user manage this journal (journal manager or site
	 * admin)? Used for manager-only notes on frontend pages.
	 *
	 * @param PKPRequest $request
	 * @return bool
	 */
	public function canManage($request) {
		$user = $request->getUser();
		$context = $request->getContext();
		if (!$user || !$context) return false;
		import('lib.pkp.classes.security.Validation');
		return $user->hasRole(array(ROLE_ID_MANAGER), $context->getId()) || Validation::isSiteAdmin();
	}

	// ------------------------------------------------------------------
	//  Public page title: shown exactly once on any theme
	// ------------------------------------------------------------------

	/**
	 * Register the |jmx_title_heading modifier used by the public page.
	 * If registration fails the page template still renders its own h1.
	 *
	 * @param TemplateManager $templateMgr
	 */
	public function registerHeadingModifier($templateMgr) {
		try {
			if (!isset($templateMgr->registered_plugins['modifier']['jmx_title_heading'])) {
				$templateMgr->registerPlugin('modifier', 'jmx_title_heading', array($this, 'smartyTitleHeading'));
			}
		} catch (\Throwable $e) {
			error_log('Journal Metrics: could not register jmx_title_heading: ' . $e->getMessage());
		}
	}

	/**
	 * Smarty modifier: which heading should the public page print for its
	 * title, given the theme header HTML? Some themes show the page title
	 * in their header, some carry their own h1 (a site logo), most neither.
	 *  0 — none: the header already shows the title as a visible heading;
	 *  2 — h2: the header has another visible h1, so the page keeps one h1;
	 *  1 — h1: everything else, and any doubt (empty header, an error), so
	 *      the page is never left without a title.
	 *
	 * @param string $html  rendered theme header
	 * @param string $title the page title
	 * @return int 0, 1 or 2
	 */
	public function smartyTitleHeading($html, $title = '') {
		try {
			$want = self::_normalizeHeadingText($title);
			$html = is_scalar($html) ? (string) $html : '';
			if ($want === '' || $html === '') return 1;
			if (!preg_match_all('#<h([1-6])\b([^>]*)>(.*?)</h\1\s*>#is', $html, $headings, PREG_SET_ORDER)) {
				return 1;
			}
			$otherH1 = false;
			foreach ($headings as $heading) {
				if (self::_isHiddenHeading($heading[2])) continue;
				if (self::_normalizeHeadingText($heading[3]) === $want) return 0;
				if ($heading[1] === '1') $otherH1 = true;
			}
			return $otherH1 ? 2 : 1;
		} catch (\Throwable $e) {
			return 1;
		}
	}

	/**
	 * Is a heading hidden from view (screen-reader-only class, the hidden
	 * attribute or aria-hidden)? Such a heading does not count as shown.
	 *
	 * @param string $attributes the heading's attribute string
	 * @return bool
	 */
	private static function _isHiddenHeading($attributes) {
		if (preg_match('/aria-hidden\s*=\s*["\']?true/i', $attributes)) return true;
		$classes = '';
		if (preg_match('/\bclass\s*=\s*("([^"]*)"|\'([^\']*)\')/i', $attributes, $m)) {
			$classes = isset($m[3]) ? $m[3] : $m[2];
			$attributes = str_replace($m[0], '', $attributes);
		}
		if (preg_match('/(^|\s)hidden(\s|=|$)/i', $attributes)) return true;
		foreach (preg_split('/\s+/', strtolower($classes), -1, PREG_SPLIT_NO_EMPTY) as $class) {
			if (in_array($class, array('sr-only', 'visually-hidden', 'visuallyhidden', 'screen-reader-text', 'd-none'), true)
					|| strpos($class, 'screen_reader') !== false || strpos($class, 'screen-reader') !== false) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Heading text for comparison: tags stripped, entities decoded,
	 * whitespace collapsed, case folded.
	 *
	 * @param string $text
	 * @return string
	 */
	private static function _normalizeHeadingText($text) {
		$text = html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = trim((string) preg_replace('/\s+/u', ' ', $text)); // null on broken UTF-8
		return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
	}
}
