{**
 * templates/editorBox.tpl
 *
 * Journal Metrics — "why do the numbers differ?" box. Managers only
 * (dashboard and settings page); never on the public page. The lines are
 * built and translated by the handler (JournalMetricsHandler::_editorBox).
 *}
{if $editorBox}
<div class="jmx-section jmx-editor-box" role="region" aria-labelledby="jmxEditorBoxTitle">
	<h2 id="jmxEditorBoxTitle" class="jmx-editor-box-title">{translate key="plugins.generic.journalMetrics.editorBox.title"}</h2>
	<ul class="jmx-editor-box-list">
		{foreach from=$editorBox.lines item=line}
		<li>{$line.text|escape}{foreach from=$line.links item=link} <a href="{$link[1]|escape}">{$link[0]|escape}</a>{/foreach}</li>
		{/foreach}
	</ul>
	<p class="jmx-editor-box-actions"><strong>{translate key="plugins.generic.journalMetrics.editorBox.actions"}</strong>
		{foreach from=$editorBox.actions item=action name=boxActions}<a href="{$action[1]|escape}">{$action[0]|escape}</a>{if !$smarty.foreach.boxActions.last} · {/if}{/foreach}
	</p>
</div>
{/if}
