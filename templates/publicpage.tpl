{**
 * templates/publicpage.tpl
 *
 * Journal Metrics — public-facing metrics page.
 * The view model handed to this page contains ONLY metrics whose
 * visibility is "public" (filtered server-side); nothing else is emitted.
 *}
{* The theme header is captured, printed unchanged, and checked, so the
   title is shown exactly once and the page has one h1:
   0 = the theme header already shows the title -> we print none;
   2 = the theme header has its own h1 (e.g. a logo) -> we print an h2;
   anything else (1, or any doubt or error) -> we print the title as h1. *}
{capture assign="jmxThemeHeader"}{include file="frontend/components/header.tpl" pageTitleTranslated=$pageTitle}{/capture}
{$jmxThemeHeader}
{assign var="jmxTitleHeading" value=$jmxThemeHeader|jmx_title_heading:$pageTitle}
{* Section headings sit one level below the page title: h2 normally, h3 where the title itself is an h2 (Classic) *}
{if $jmxTitleHeading === 2}{assign var="jmxSectionLevel" value=3}{else}{assign var="jmxSectionLevel" value=2}{/if}
{capture assign="jmxTitleHtml"}{if $jmxTitleHeading === 0}{elseif $jmxTitleHeading === 2}<h2 class="jmx-public-title">{$pageTitle|escape}</h2>{else}<h1 class="jmx-public-title">{$pageTitle|escape}</h1>{/if}{/capture}

{if $jmxClosedNotice}
{* The public page is switched off. Only managers get here (visitors get
   a 404): tell them why the page is empty and where to switch it on. *}
<div class="page jmx-public-page">
	<link rel="stylesheet" href="{$pluginPath}/css/journalMetrics.css?v={$assetVersion|escape}" />
	{if $jmxTitleHtml}
	<header class="jmx-public-header">
		{$jmxTitleHtml}
	</header>
	{/if}
	<div class="jmx-dashboard">
		<div class="jmx-section jmx-manager-note" role="status">
			<p>{translate key="plugins.generic.journalMetrics.public.closedNotice"}</p>
			<p><a class="jmx-btn jmx-btn-primary" href="{$settingsUrl|escape}">{translate key="plugins.generic.journalMetrics.public.openSettings"}</a></p>
		</div>
	</div>
</div>
{else}
<div class="page jmx-public-page">

	<link rel="stylesheet" href="{$pluginPath}/css/journalMetrics.css?v={$assetVersion|escape}" />

	<header class="jmx-public-header">
		{$jmxTitleHtml}
		<p class="jmx-public-subtitle">{translate key="plugins.generic.journalMetrics.public.subtitle"}</p>
	</header>

	<div class="jmx-dashboard">

		{* Trust layer: last-updated stamp *}
		{if $generatedAt}
		<div class="jmx-updated-stamp" style="margin-bottom:{if $coverageYear}4px{else}14px{/if};">
			&#x1F552; {translate key="plugins.generic.journalMetrics.ui.metricsUpdated"}: {$generatedAt|escape}
		</div>
		{/if}
		{if $coverageYear}
		<div class="jmx-updated-stamp jmx-coverage-line" style="margin-bottom:14px;">
			{translate key="plugins.generic.journalMetrics.ui.coverageLine" year=$coverageYear}
		</div>
		{/if}

		{* Metric groups (rendered by JS; public-only data) *}
		<div id="jmxGroups"></div>

		{* Geographic distribution (rendered from the snapshot only when
		   the authorCountries metric is public; no live queries) *}
		{if $showCountrySection}
		<div class="jmx-section" id="jmxCountrySection" style="display:none;">
			<div class="jmx-section-header">
				<h{$jmxSectionLevel}>{translate key="plugins.generic.journalMetrics.ui.countrySectionTitle"}</h{$jmxSectionLevel}>
			</div>

			<div class="jmx-charts-row">
				<div class="jmx-chart-card">
					<div class="jmx-chart-title">{translate key="plugins.generic.journalMetrics.ui.topCountries"}</div>
					<div class="jmx-bar-chart" id="jmxCountryBarChart"></div>
				</div>
				<div class="jmx-chart-card">
					<div class="jmx-chart-title">{translate key="plugins.generic.journalMetrics.ui.authorDistribution"}</div>
					<div class="jmx-donut-wrapper">
						<svg class="jmx-donut-svg" viewBox="0 0 200 200" id="jmxDonutChart"></svg>
						<div class="jmx-donut-legend" id="jmxDonutLegend"></div>
					</div>
				</div>
			</div>

			<div class="jmx-tabs" id="jmxCountryTabs" role="tablist">
				{if $jmxSettings.showAuthorsTab}
				<div class="jmx-tab active" data-tab="authors" role="tab" id="jmx-tabbtn-authors" aria-controls="jmx-tab-authors" aria-selected="true" tabindex="0">{translate key="plugins.generic.journalMetrics.ui.authorsByCountry"}</div>
				{/if}
				{if $jmxSettings.showArticlesTab}
				<div class="jmx-tab{if !$jmxSettings.showAuthorsTab} active{/if}" data-tab="articles" role="tab" id="jmx-tabbtn-articles" aria-controls="jmx-tab-articles" aria-selected="{if !$jmxSettings.showAuthorsTab}true{else}false{/if}" tabindex="{if !$jmxSettings.showAuthorsTab}0{else}-1{/if}">{translate key="plugins.generic.journalMetrics.ui.articlesByCountry"}</div>
				{/if}
			</div>

			{if $jmxSettings.showAuthorsTab}
			<div class="jmx-tab-content active" id="jmx-tab-authors" role="tabpanel" aria-labelledby="jmx-tabbtn-authors">
				<div class="jmx-table-toolbar">
					<input type="text" class="jmx-search-box" placeholder="{translate key="plugins.generic.journalMetrics.ui.searchCountry"}" id="jmxSearchAuthors" aria-label="{translate key="plugins.generic.journalMetrics.ui.searchCountry"}" />
				</div>
				<div class="jmx-table-wrap">
					<table class="jmx-table">
						<thead><tr>
							<th style="width:40px">#</th>
							<th>{translate key="plugins.generic.journalMetrics.ui.country"}</th>
							<th style="width:80px">{translate key="plugins.generic.journalMetrics.ui.authors"}</th>
							<th style="width:100px">{translate key="plugins.generic.journalMetrics.ui.distribution"}</th>
							<th style="width:60px">{translate key="plugins.generic.journalMetrics.ui.share"}</th>
						</tr></thead>
						<tbody id="jmxAuthorsBody"></tbody>
					</table>
				</div>
			</div>
			{/if}

			{if $jmxSettings.showArticlesTab}
			<div class="jmx-tab-content{if !$jmxSettings.showAuthorsTab} active{/if}" id="jmx-tab-articles" role="tabpanel" aria-labelledby="jmx-tabbtn-articles">
				<div class="jmx-table-toolbar">
					<input type="text" class="jmx-search-box" placeholder="{translate key="plugins.generic.journalMetrics.ui.searchCountry"}" id="jmxSearchArticles" aria-label="{translate key="plugins.generic.journalMetrics.ui.searchCountry"}" />
				</div>
				<div class="jmx-table-wrap">
					<table class="jmx-table">
						<thead><tr>
							<th style="width:40px">#</th>
							<th>{translate key="plugins.generic.journalMetrics.ui.country"}</th>
							<th style="width:80px">{translate key="plugins.generic.journalMetrics.ui.articles"}</th>
							<th style="width:100px">{translate key="plugins.generic.journalMetrics.ui.distribution"}</th>
							<th style="width:60px">{translate key="plugins.generic.journalMetrics.ui.share"}</th>
						</tr></thead>
						<tbody id="jmxArticlesBody"></tbody>
					</table>
				</div>
			</div>
			{/if}
		</div>
		{/if}

		{* Empty state: no metric made public yet, or public metrics without
		   enough data so far. Managers also get the way to the settings. *}
		<div id="jmxEmptyState" class="jmx-section jmx-empty-state" style="display:none;">
			{if $jmxPublicCount}
				{translate key="plugins.generic.journalMetrics.public.noMetrics"}
			{else}
				{translate key="plugins.generic.journalMetrics.public.noPublicMetrics"}
			{/if}
			{if $jmxCanManage}
			<p class="jmx-empty-action"><a class="jmx-btn jmx-btn-primary" href="{$settingsUrl|escape}">{translate key="plugins.generic.journalMetrics.public.openSettings"}</a></p>
			{/if}
		</div>

		{* Trust layer: methodology / source note *}
		<div class="jmx-data-note">
			<span><span class="jmx-note-dot"></span> {translate key="plugins.generic.journalMetrics.public.autoNote"}</span>
			<span><span class="jmx-note-dot"></span> {translate key="plugins.generic.journalMetrics.public.manualNote"}</span>
		</div>

		{* Developer credit — public page only: governed by the
		   showDeveloperCredit setting (default on). When disabled the
		   block is not rendered at all (same no-leak pattern as metrics). *}
		{if $showDeveloperCredit}
		<div class="jmx-developer-credit">
			<a href="https://ojs-services.com" target="_blank" rel="noopener noreferrer">
				<span class="jmx-dev-icon" aria-hidden="true">&#x2666;</span>
				Developed by <strong>ojs-services.com</strong>
			</a>
		</div>
		{/if}
	</div>
</div>

<script>
	window.journalMetricsData = {$jmxData};
	window.journalMetricsData.headingBase = {$jmxSectionLevel|intval};
	window.journalMetricsConfig = {ldelim}
		isAdmin: false,
		fetchUrl: null,
		articleUrlBase: '{$articleUrlBase|escape:"javascript"}',
		countryDisplay: '{$jmxSettings.countryDisplay|escape:"javascript"}',
		sortOrder: '{$jmxSettings.sortOrder|escape:"javascript"}',
		minThreshold: {$jmxSettings.minThreshold|intval},
		includeUnknown: {if $jmxSettings.includeUnknown}true{else}false{/if}
	{rdelim};
	(function() {ldelim}
		document.addEventListener('DOMContentLoaded', function() {ldelim}
			var groups = window.journalMetricsData && window.journalMetricsData.groups;
			var hasContent = false;
			if (groups) {ldelim}
				for (var key in groups) {ldelim}
					if (Object.prototype.hasOwnProperty.call(groups, key)) {ldelim} hasContent = true; break; {rdelim}
				{rdelim}
			{rdelim}
			if (!hasContent) {ldelim}
				var el = document.getElementById('jmxEmptyState');
				if (el) el.style.display = 'block';
			{rdelim}
		{rdelim});
	{rdelim})();
</script>
<script src="{$pluginPath}/js/journalMetrics.js?v={$assetVersion|escape}"></script>
{/if}

{include file="frontend/components/footer.tpl"}
