{**
 * templates/dashboard.tpl
 *
 * Journal Metrics — admin dashboard.
 * The metric sections are rendered client-side from the jmxData view model
 * (visibility already applied server-side).
 *}
{extends file="layouts/backend.tpl"}

{block name="page"}
<div class="jmx-dashboard" id="jmxDashboard">

	<link rel="stylesheet" href="{$pluginPath}/css/journalMetrics.css?v={$assetVersion|escape}" />

	{* Header *}
	<div class="jmx-page-header">
		<div>
			<h1>{translate key="plugins.generic.journalMetrics.dashboard.title"}</h1>
			<div class="jmx-updated-stamp">
				{if $generatedAt}
					{translate key="plugins.generic.journalMetrics.ui.metricsUpdated"}: <span id="jmxStamp">{$generatedAt|escape}</span>
				{else}
					{translate key="plugins.generic.journalMetrics.dashboard.neverComputed"}
				{/if}
				{if $usagePending}
					<span class="jmx-pending-pill">{translate key="plugins.generic.journalMetrics.ui.usagePending"}</span>
				{/if}
				{if $coverageYear}
					<span class="jmx-coverage-line">{translate key="plugins.generic.journalMetrics.ui.coverageLine" year=$coverageYear}</span>
				{/if}
			</div>
		</div>
		<div class="jmx-header-right">
			<button type="button" class="jmx-btn jmx-btn-primary" id="jmxRecomputeBtn"
				data-url="{url page="journalmetrics" op="recompute" escape=false}">
				&#x21BB; {translate key="plugins.generic.journalMetrics.dashboard.recompute"}
			</button>
			<a class="jmx-btn" href="{url page="journalmetrics" op="settings"}">
				&#x2699; {translate key="plugins.generic.journalMetrics.dashboard.settings"}
			</a>
		</div>
	</div>

	{* Metric groups (rendered by JS) *}
	<div id="jmxGroups"></div>

	{* Country statistics section (countryStats parity, live filters).
	   Governed by the authorCountries visibility key. *}
	{if $showCountrySection}
	<div class="jmx-section" id="jmxCountrySection">
		<div class="jmx-section-header">
			<h2>{translate key="plugins.generic.journalMetrics.ui.countrySectionTitle"}</h2>
			<div class="jmx-header-right">
				<select class="jmx-filter-select" id="jmxTimeFilter" aria-label="{translate key="plugins.generic.journalMetrics.filter.allTime"}">
					<option value="all">{translate key="plugins.generic.journalMetrics.filter.allTime"}</option>
					<option value="12">{translate key="plugins.generic.journalMetrics.filter.last12"}</option>
					<option value="24">{translate key="plugins.generic.journalMetrics.filter.last24"}</option>
				</select>
				<select class="jmx-filter-select" id="jmxStrategyFilter" aria-label="{translate key="plugins.generic.journalMetrics.filter.uniqueBy"}">
					<option value="orcid" {if $jmxSettings.uniqueStrategy == 'orcid'}selected{/if}>{translate key="plugins.generic.journalMetrics.filter.uniqueBy"}: ORCID</option>
					<option value="email" {if $jmxSettings.uniqueStrategy == 'email'}selected{/if}>{translate key="plugins.generic.journalMetrics.filter.uniqueBy"}: {translate key="plugins.generic.journalMetrics.filter.email"}</option>
					<option value="name" {if $jmxSettings.uniqueStrategy == 'name'}selected{/if}>{translate key="plugins.generic.journalMetrics.filter.uniqueBy"}: {translate key="plugins.generic.journalMetrics.filter.fullName"}</option>
				</select>
			</div>
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
			<div class="jmx-tab active" data-tab="authors" role="tab" id="jmx-tabbtn-authors" aria-controls="jmx-tab-authors" aria-selected="true" tabindex="0">&#x270D; {translate key="plugins.generic.journalMetrics.ui.authorsByCountry"}</div>
			{/if}
			{if $jmxSettings.showArticlesTab}
			<div class="jmx-tab{if !$jmxSettings.showAuthorsTab} active{/if}" data-tab="articles" role="tab" id="jmx-tabbtn-articles" aria-controls="jmx-tab-articles" aria-selected="{if !$jmxSettings.showAuthorsTab}true{else}false{/if}" tabindex="{if !$jmxSettings.showAuthorsTab}0{else}-1{/if}">&#x1F4C4; {translate key="plugins.generic.journalMetrics.ui.articlesByCountry"}</div>
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

	{* Data notes *}
	<div class="jmx-data-note">
		<span><span class="jmx-note-dot"></span> {translate key="plugins.generic.journalMetrics.note.snapshot"}</span>
		<span><span class="jmx-note-dot"></span> {translate key="plugins.generic.journalMetrics.note.coreConsistency"}</span>
		<span><span class="jmx-note-dot"></span> {translate key="plugins.generic.journalMetrics.note.uniqueBy" strategy=$jmxSettings.uniqueStrategy|upper}</span>
	</div>

	{* Developer credit *}
	<div class="jmx-developer-credit">
		<a href="https://ojs-services.com" target="_blank" rel="noopener noreferrer">
			<span class="jmx-dev-icon">&#x2666;</span>
			Developed by <strong>ojs-services.com</strong>
		</a>
	</div>
</div>

<script>
	window.journalMetricsData = {$jmxData};
	window.journalMetricsConfig = {ldelim}
		isAdmin:   true,
		csrfToken: '{$csrfToken|escape:"javascript"}',
		fetchUrl:  '{url page="journalmetrics" op="fetchData" escape=false}',
		countryDisplay: '{$jmxSettings.countryDisplay|escape:"javascript"}',
		sortOrder: '{$jmxSettings.sortOrder|escape:"javascript"}',
		minThreshold: {$jmxSettings.minThreshold|intval},
		includeUnknown: {if $jmxSettings.includeUnknown}true{else}false{/if}
	{rdelim};
</script>
<script src="{$pluginPath}/js/journalMetrics.js?v={$assetVersion|escape}"></script>

{/block}
