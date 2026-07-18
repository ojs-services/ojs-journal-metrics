{**
 * templates/publicpage.tpl
 *
 * Journal Metrics — public-facing metrics page.
 * The view model handed to this page contains ONLY metrics whose
 * visibility is "public" (filtered server-side); nothing else is emitted.
 *}
{include file="frontend/components/header.tpl" pageTitleTranslated=$pageTitle}

<div class="page jmx-public-page">

	<link rel="stylesheet" href="{$pluginPath}/css/journalMetrics.css?v={$assetVersion|escape}" />

	<header class="jmx-public-header">
		<h2>{$pageTitle|escape}</h2>
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
				<h2>{translate key="plugins.generic.journalMetrics.ui.countrySectionTitle"}</h2>
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

		{* Empty state: no public metrics or snapshot not ready *}
		<div id="jmxEmptyState" class="jmx-section" style="display:none;text-align:center;padding:36px 16px;color:#64748b;">
			{translate key="plugins.generic.journalMetrics.public.noMetrics"}
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
				<span class="jmx-dev-icon">&#x2666;</span>
				Developed by <strong>ojs-services.com</strong>
			</a>
		</div>
		{/if}
	</div>
</div>

<script>
	window.journalMetricsData = {$jmxData};
	window.journalMetricsConfig = {ldelim}
		isAdmin: false,
		fetchUrl: null,
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

{include file="frontend/components/footer.tpl"}
