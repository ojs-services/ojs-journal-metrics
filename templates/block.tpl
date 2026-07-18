{**
 * templates/block.tpl
 *
 * Journal Metrics — sidebar block (public metrics only).
 *}
<div class="pkp_block block_journal_metrics jmx-block">
	<h2 class="title">{translate key="plugins.generic.journalMetrics.block.title"}</h2>
	<div class="content">
		<ul class="jmx-block-list">
			{foreach from=$jmxBlockItems item=item}
			<li class="jmx-block-item">
				<span class="jmx-block-label">{$item.label|escape}</span>
				<span class="jmx-block-value">{$item.value|escape}</span>
			</li>
			{/foreach}
		</ul>
		{* {journal_metric} is available to any frontend template; used here
		   for the archive-depth row (returns '' unless the metric is public).
		   Subject to the editor's block-metric selection like every row. *}
		{if $jmxBlockShowArchive}
		{assign var=jmxArchiveDepth value="{journal_metric key="archiveDepth"}"}
		{if $jmxArchiveDepth}
		<div class="jmx-block-item">
			<span class="jmx-block-label">{translate key="plugins.generic.journalMetrics.metric.archiveDepth"}</span>
			<span class="jmx-block-value">{$jmxArchiveDepth}</span>
		</div>
		{/if}
		{/if}
		{if $jmxBlockMoreUrl}
		<a class="jmx-block-more" href="{$jmxBlockMoreUrl|escape}">{translate key="plugins.generic.journalMetrics.block.more"} &rarr;</a>
		{/if}
	</div>
</div>
