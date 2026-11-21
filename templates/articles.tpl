{**
 * templates/articles.tpl
 *
 * Journal Metrics — manager-only list: articles added without an editor
 * decision (listType = direct) or with inconsistent dates (inconsistent),
 * with a count per year. Linked from the editor box.
 *}
{extends file="layouts/backend.tpl"}

{block name="page"}
<div class="jmx-dashboard" id="jmxArticleList">

	<link rel="stylesheet" href="{$pluginPath}/css/journalMetrics.css?v={$assetVersion|escape}" />

	<div class="jmx-page-header">
		<div>
			<h1>{if $listType == 'inconsistent'}{translate key="plugins.generic.journalMetrics.articles.inconsistentTitle"}{else}{translate key="plugins.generic.journalMetrics.articles.directTitle"}{/if}</h1>
			<div class="jmx-updated-stamp">{if $listType == 'inconsistent'}{translate key="plugins.generic.journalMetrics.articles.inconsistentDesc"}{else}{translate key="plugins.generic.journalMetrics.articles.directDesc"}{/if}</div>
		</div>
		<div class="jmx-header-right">
			<a class="jmx-btn" href="{$dashboardUrl|escape}">&larr; {translate key="plugins.generic.journalMetrics.dashboard.title"}</a>
		</div>
	</div>

	{if !$articles}
	<div class="jmx-section"><p>{translate key="plugins.generic.journalMetrics.articles.none"}</p></div>
	{else}
	<div class="jmx-section">
		<h2 class="jmx-page-section-title">{translate key="plugins.generic.journalMetrics.articles.byYear"}</h2>
		<div class="jmx-table-wrap">
			<table class="jmx-table jmx-year-table">
				<thead><tr><th scope="col">{translate key="plugins.generic.journalMetrics.ui.year"}</th><th scope="col">{translate key="plugins.generic.journalMetrics.ui.articles"}</th></tr></thead>
				<tbody>
				{foreach from=$byYear key=year item=count}
				<tr><td class="jmx-count-bold">{$year|escape}</td><td>{$count|intval}</td></tr>
				{/foreach}
				</tbody>
			</table>
		</div>
	</div>

	<div class="jmx-section">
		<h2 class="jmx-page-section-title">{translate key="plugins.generic.journalMetrics.articles.list"}</h2>
		<div class="jmx-table-wrap">
			<table class="jmx-table">
				<thead><tr>
					<th scope="col">{translate key="plugins.generic.journalMetrics.articles.titleCol"}</th>
					<th scope="col" style="width:130px">{translate key="plugins.generic.journalMetrics.articles.publishedCol"}</th>
					{if $listType == 'inconsistent'}<th scope="col" style="width:130px">{translate key="plugins.generic.journalMetrics.articles.submittedCol"}</th>{/if}
				</tr></thead>
				<tbody>
				{foreach from=$articles item=article}
				<tr>
					<td><a class="jmx-top-link" href="{$articleUrlBase|escape}/{$article.id|intval}">{$article.title|escape}</a></td>
					<td>{$article.published|escape}</td>
					{if $listType == 'inconsistent'}<td>{$article.submitted|escape}</td>{/if}
				</tr>
				{/foreach}
				</tbody>
			</table>
		</div>
		{if $truncated}<p class="jmx-table-footnote">{translate key="plugins.generic.journalMetrics.articles.truncated" limit=$listLimit|intval}</p>{/if}
	</div>
	{/if}
</div>
{/block}
