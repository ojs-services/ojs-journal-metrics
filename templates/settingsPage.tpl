{**
 * templates/settingsPage.tpl
 *
 * Journal Metrics — full-page settings (backend). All plugin settings
 * live here; there is no modal management flow.
 *}
{extends file="layouts/backend.tpl"}

{block name="page"}
<div class="jmx-dashboard" id="jmxSettingsPage">

	<link rel="stylesheet" href="{$pluginPath}/css/journalMetrics.css?v={$assetVersion|escape}" />

	{* Header *}
	<div class="jmx-page-header">
		<div>
			<h1>{translate key="plugins.generic.journalMetrics.settings.pageTitle"}</h1>
			<div class="jmx-updated-stamp">{translate key="plugins.generic.journalMetrics.settings.pageSubtitle"}</div>
		</div>
		<div class="jmx-header-right">
			<a class="jmx-btn" href="{$dashboardUrl|escape}">&larr; {translate key="plugins.generic.journalMetrics.dashboard.title"}</a>
		</div>
	</div>

	{if $justSaved}
	<div class="jmx-toast-success" id="jmxSavedToast">
		&#x2713; {translate key="plugins.generic.journalMetrics.settings.saved"}
	</div>
	{/if}

	<form method="post" action="{$saveUrl|escape}" id="jmxSettingsForm">
		{csrf}

		{* ---------- Public page (first: the user should know where the
		   metrics will appear before configuring them below) ---------- *}
		<div class="jmx-section">
			<div class="jmx-section-header"><h2>{translate key="plugins.generic.journalMetrics.settings.publicPage"}</h2></div>
			<p class="jmx-settings-hint">{translate key="plugins.generic.journalMetrics.settings.enablePublicPage.desc"}</p>
			<div class="jmx-check-list">
				<label><input type="checkbox" name="enablePublicPage" value="1" {if $jmxSettings.enablePublicPage}checked{/if} /> {translate key="plugins.generic.journalMetrics.settings.enablePublicPage"}</label>
			</div>
			<div class="jmx-form-grid">
				<div class="jmx-form-field">
					<label>{translate key="plugins.generic.journalMetrics.settings.publicPageTitle"}</label>
					{foreach from=$supportedLocales key=localeCode item=localeName}
					<div class="jmx-locale-input">
						<input type="text" name="publicPageTitle[{$localeCode|escape}]" value="{$publicPageTitleByLocale.$localeCode|escape}" maxlength="120" class="jmx-text-input" aria-label="{translate key="plugins.generic.journalMetrics.settings.publicPageTitle"} ({$localeName|escape})" />
						<span class="jmx-locale-chip">{$localeName|escape}</span>
					</div>
					{/foreach}
				</div>
				<div class="jmx-form-field">
					<label>{translate key="plugins.generic.journalMetrics.settings.publicPageUrl"}</label>
					<div class="jmx-url-box">
						<span>&#x1F517;</span>
						<a href="{$publicPageUrl|escape}" target="_blank">{$publicPageUrl|escape}</a>
					</div>
					<p class="jmx-settings-hint">{translate key="plugins.generic.journalMetrics.settings.publicPageUrl.desc"}</p>
				</div>
			</div>
			<div class="jmx-check-list" style="margin-top:10px;">
				<label><input type="checkbox" name="showDeveloperCredit" value="1" {if $jmxSettings.showDeveloperCredit}checked{/if} /> {translate key="plugins.generic.journalMetrics.settings.showDeveloperCredit"}</label>
			</div>
			<p class="jmx-settings-hint">{translate key="plugins.generic.journalMetrics.settings.showDeveloperCredit.desc"}</p>
		</div>

		{* ---------- Sidebar block ---------- *}
		<div class="jmx-section">
			<div class="jmx-section-header"><h2>{translate key="plugins.generic.journalMetrics.settings.block"}</h2></div>
			<div class="jmx-block-status">
				{if $blockActive}
				<span class="jmx-status-pill jmx-status-on">{translate key="plugins.generic.journalMetrics.settings.block.statusActive"}</span>
				{else}
				<span class="jmx-status-pill jmx-status-off">{translate key="plugins.generic.journalMetrics.settings.block.statusPassive"}</span>
				{/if}
				<a class="jmx-btn" href="{$sidebarSettingsUrl|escape}">{translate key="plugins.generic.journalMetrics.settings.block.manage"}</a>
			</div>
			<p class="jmx-settings-hint">{translate key="plugins.generic.journalMetrics.settings.block.themeNote"}</p>

			<p class="jmx-settings-hint" style="margin-top:12px;"><strong>{translate key="plugins.generic.journalMetrics.settings.block.metrics"}</strong> — {translate key="plugins.generic.journalMetrics.settings.block.metricsDesc"}</p>
			<div class="jmx-check-list jmx-check-grid">
				{foreach from=$blockCandidates key=metricKey item=metricLabel}
				<label>
					<input type="checkbox" name="blockMetrics[]" value="{$metricKey|escape}"
						{if in_array($metricKey, $blockSelection)}checked{/if} />
					{$metricLabel|escape}
				</label>
				{/foreach}
			</div>
		</div>

		{* ---------- Editorial statistics coverage ---------- *}
		<div class="jmx-section">
			<div class="jmx-section-header"><h2>{translate key="plugins.generic.journalMetrics.settings.coverage"}</h2></div>
			<p class="jmx-settings-hint">{translate key="plugins.generic.journalMetrics.settings.coverage.desc"}</p>
			<div class="jmx-form-field" style="max-width:280px;">
				<label for="statsCoverageStartYear">{translate key="plugins.generic.journalMetrics.settings.coverage.year"}</label>
				<input type="number" min="1900" max="{$currentYear|intval}" step="1"
					name="statsCoverageStartYear" id="statsCoverageStartYear"
					value="{if $coverageStartYear}{$coverageStartYear|intval}{/if}"
					class="jmx-num-input" placeholder="{translate key="plugins.generic.journalMetrics.settings.coverage.placeholder"}" />
			</div>
		</div>

		{* ---------- Visibility matrix ---------- *}
		<div class="jmx-section">
			<div class="jmx-section-header"><h2>{translate key="plugins.generic.journalMetrics.settings.visibility"}</h2></div>
			<p class="jmx-settings-hint">{translate key="plugins.generic.journalMetrics.settings.visibility.desc"}</p>
			<div class="jmx-table-wrap">
				<table class="jmx-settings-table">
					<thead>
						<tr>
							<th>{translate key="plugins.generic.journalMetrics.settings.visibility.group"}</th>
							<th>{translate key="plugins.generic.journalMetrics.settings.visibility.metric"}</th>
							<th style="width:150px">{translate key="plugins.generic.journalMetrics.settings.visibility.level"}</th>
						</tr>
					</thead>
					<tbody>
						{foreach from=$metricMatrix key=metricKey item=meta}
						<tr>
							<td class="jmx-muted">{$meta.group|escape}</td>
							<td>{$meta.label|escape}</td>
							<td>
								<select name="visibility[{$metricKey|escape}]" class="jmx-vis-select jmx-filter-select">
									{foreach from=$visibilityOptions key=optValue item=optLabel}
									<option value="{$optValue|escape}" {if $visibility.$metricKey == $optValue}selected{/if}>{$optLabel|escape}</option>
									{/foreach}
								</select>
							</td>
						</tr>
						{/foreach}
					</tbody>
				</table>
			</div>
		</div>

		{* ---------- Manual metrics ---------- *}
		<div class="jmx-section">
			<div class="jmx-section-header"><h2>{translate key="plugins.generic.journalMetrics.settings.manual"}</h2></div>
			<p class="jmx-settings-hint">{translate key="plugins.generic.journalMetrics.settings.manual.desc"}</p>
			<div class="jmx-table-wrap">
				<table class="jmx-settings-table" id="jmxManualTable">
					<thead>
						<tr>
							<th style="width:22%">{translate key="plugins.generic.journalMetrics.settings.manual.title"}</th>
							<th style="width:12%">{translate key="plugins.generic.journalMetrics.settings.manual.value"}</th>
							<th style="width:18%">{translate key="plugins.generic.journalMetrics.settings.manual.source"}</th>
							<th>{translate key="plugins.generic.journalMetrics.settings.manual.description"}</th>
							<th style="width:100px">{translate key="plugins.generic.journalMetrics.settings.manual.lastUpdated"}</th>
							<th style="width:50px"></th>
						</tr>
					</thead>
					<tbody id="jmxManualBody">
						{foreach from=$manualMetrics item=row name=manualRows}
						{assign var=i value=$smarty.foreach.manualRows.index}
						<tr>
							<td>
								{foreach from=$supportedLocales key=localeCode item=localeName}
								<div class="jmx-locale-input">
									<input type="text" name="manualMetrics[{$i}][title][{$localeCode|escape}]" value="{$row.title.$localeCode|escape}" maxlength="80" aria-label="{translate key="plugins.generic.journalMetrics.settings.manual.title"} ({$localeName|escape})" />
									<span class="jmx-locale-chip">{$localeName|escape}</span>
								</div>
								{/foreach}
							</td>
							<td><input type="text" name="manualMetrics[{$i}][value]" value="{$row.value|escape}" maxlength="20" aria-label="{translate key="plugins.generic.journalMetrics.settings.manual.value"}" /></td>
							<td><input type="text" name="manualMetrics[{$i}][source]" value="{$row.source|escape}" maxlength="80" aria-label="{translate key="plugins.generic.journalMetrics.settings.manual.source"}" /></td>
							<td>
								{foreach from=$supportedLocales key=localeCode item=localeName}
								<div class="jmx-locale-input">
									<input type="text" name="manualMetrics[{$i}][description][{$localeCode|escape}]" value="{$row.description.$localeCode|escape}" maxlength="200" aria-label="{translate key="plugins.generic.journalMetrics.settings.manual.description"} ({$localeName|escape})" />
									<span class="jmx-locale-chip">{$localeName|escape}</span>
								</div>
								{/foreach}
							</td>
							<td class="jmx-muted">{$row.lastUpdated|escape}</td>
							<td><button type="button" class="jmx-row-remove">&times;</button></td>
						</tr>
						{/foreach}
					</tbody>
				</table>
			</div>
			<button type="button" class="jmx-row-add" id="jmxManualAdd" data-max="{$manualMaxRows|intval}">
				+ {translate key="plugins.generic.journalMetrics.settings.manual.addRow"}
			</button>
		</div>

		{* ---------- Author deduplication ---------- *}
		<div class="jmx-section">
			<div class="jmx-section-header"><h2>{translate key="plugins.generic.journalMetrics.settings.scope"}</h2></div>
			<div class="jmx-form-grid">
				<div class="jmx-form-field">
					<label for="uniqueStrategy">{translate key="plugins.generic.journalMetrics.settings.uniqueStrategy"}</label>
					<select name="uniqueStrategy" id="uniqueStrategy" class="jmx-filter-select">
						{foreach from=$uniqueStrategyOptions key=optValue item=optLabel}
						<option value="{$optValue|escape}" {if $jmxSettings.uniqueStrategy == $optValue}selected{/if}>{$optLabel|escape}</option>
						{/foreach}
					</select>
					<p class="jmx-settings-hint">{translate key="plugins.generic.journalMetrics.settings.uniqueStrategy.desc"}</p>
				</div>
				<div class="jmx-form-field">
					<label for="orcidFallback">{translate key="plugins.generic.journalMetrics.settings.orcidFallback"}</label>
					<select name="orcidFallback" id="orcidFallback" class="jmx-filter-select">
						{foreach from=$orcidFallbackOptions key=optValue item=optLabel}
						<option value="{$optValue|escape}" {if $jmxSettings.orcidFallback == $optValue}selected{/if}>{$optLabel|escape}</option>
						{/foreach}
					</select>
					<p class="jmx-settings-hint">{translate key="plugins.generic.journalMetrics.settings.orcidFallback.desc"}</p>
				</div>
			</div>
		</div>

		{* ---------- Appearance & thresholds ---------- *}
		<div class="jmx-section">
			<div class="jmx-section-header"><h2>{translate key="plugins.generic.journalMetrics.settings.appearance"}</h2></div>
			<div class="jmx-form-grid">
				<div class="jmx-form-field">
					<label for="countryDisplay">{translate key="plugins.generic.journalMetrics.settings.countryDisplay"}</label>
					<select name="countryDisplay" id="countryDisplay" class="jmx-filter-select">
						{foreach from=$countryDisplayOptions key=optValue item=optLabel}
						<option value="{$optValue|escape}" {if $jmxSettings.countryDisplay == $optValue}selected{/if}>{$optLabel|escape}</option>
						{/foreach}
					</select>
				</div>
				<div class="jmx-form-field">
					<label for="sortOrder">{translate key="plugins.generic.journalMetrics.settings.sortOrder"}</label>
					<select name="sortOrder" id="sortOrder" class="jmx-filter-select">
						{foreach from=$sortOrderOptions key=optValue item=optLabel}
						<option value="{$optValue|escape}" {if $jmxSettings.sortOrder == $optValue}selected{/if}>{$optLabel|escape}</option>
						{/foreach}
					</select>
				</div>
				<div class="jmx-form-field">
					<label for="minThreshold">{translate key="plugins.generic.journalMetrics.settings.minThreshold"}</label>
					<input type="number" min="1" name="minThreshold" id="minThreshold" value="{$jmxSettings.minThreshold|intval}" class="jmx-num-input" />
					<p class="jmx-settings-hint">{translate key="plugins.generic.journalMetrics.settings.minThreshold.desc"}</p>
				</div>
				<div class="jmx-form-field">
					<label for="rateNThreshold">{translate key="plugins.generic.journalMetrics.settings.rateNThreshold"}</label>
					<input type="number" min="0" name="rateNThreshold" id="rateNThreshold" value="{$jmxSettings.rateNThreshold|intval}" class="jmx-num-input" />
					<p class="jmx-settings-hint">{translate key="plugins.generic.journalMetrics.settings.rateNThreshold.desc"}</p>
				</div>
			</div>
			<div class="jmx-check-list">
				<label><input type="checkbox" name="includeUnknown" value="1" {if $jmxSettings.includeUnknown}checked{/if} /> {translate key="plugins.generic.journalMetrics.settings.includeUnknown"}</label>
				<label><input type="checkbox" name="showAuthorsTab" value="1" {if $jmxSettings.showAuthorsTab}checked{/if} /> {translate key="plugins.generic.journalMetrics.settings.showAuthorsTab"}</label>
				<label><input type="checkbox" name="showArticlesTab" value="1" {if $jmxSettings.showArticlesTab}checked{/if} /> {translate key="plugins.generic.journalMetrics.settings.showArticlesTab"}</label>
			</div>
		</div>

		<div class="jmx-form-actions">
			<button type="submit" class="jmx-btn jmx-btn-primary jmx-btn-lg">{translate key="common.save"}</button>
		</div>
	</form>

	{* ---------- Recompute (outside the form) ---------- *}
	<div class="jmx-section">
		<div class="jmx-section-header"><h2>{translate key="plugins.generic.journalMetrics.settings.recompute"}</h2></div>
		<p class="jmx-settings-hint">{translate key="plugins.generic.journalMetrics.settings.recompute.desc"}</p>
		<button type="button" class="jmx-btn jmx-btn-primary" id="jmxSettingsRecompute" data-url="{$recomputeUrl|escape}">
			&#x21BB; {translate key="plugins.generic.journalMetrics.dashboard.recompute"}
		</button>
		<span id="jmxRecomputeStatus" class="jmx-settings-hint" style="margin-left:10px;"></span>
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
	// The OJS backend is a Vue app: it re-renders the page subtree after
	// this inline script has run, replacing the original DOM nodes. All
	// listeners therefore live on `document` (which survives the
	// re-render) and elements are looked up at event time.
	(function() {ldelim}
		var MAX_ROWS = {$manualMaxRows|intval};
		var FIELD_LABELS = {ldelim}
			title: '{translate key="plugins.generic.journalMetrics.settings.manual.title"|escape:"javascript"}',
			description: '{translate key="plugins.generic.journalMetrics.settings.manual.description"|escape:"javascript"}',
			value: '{translate key="plugins.generic.journalMetrics.settings.manual.value"|escape:"javascript"}',
			source: '{translate key="plugins.generic.journalMetrics.settings.manual.source"|escape:"javascript"}'
		{rdelim};
		var LOCALES = [
			{foreach from=$supportedLocales key=localeCode item=localeName name=locList}
			{ldelim} code: '{$localeCode|escape:"javascript"}', label: '{$localeName|escape:"javascript"}' {rdelim}{if !$smarty.foreach.locList.last},{/if}
			{/foreach}
		];

		function localizedCell(field, maxLen) {ldelim}
			var html = '';
			for (var i = 0; i < LOCALES.length; i++) {ldelim}
				html += '<div class="jmx-locale-input">' +
					'<input type="text" name="manualMetrics[0][' + field + '][' + LOCALES[i].code + ']" value="" maxlength="' + maxLen + '" aria-label="' + FIELD_LABELS[field] + ' (' + LOCALES[i].label + ')" />' +
					'<span class="jmx-locale-chip">' + LOCALES[i].label + '</span></div>';
			{rdelim}
			return html;
		{rdelim}

		function reindex() {ldelim}
			var body = document.getElementById('jmxManualBody');
			var addBtn = document.getElementById('jmxManualAdd');
			if (!body) return;
			var rows = body.querySelectorAll('tr');
			for (var i = 0; i < rows.length; i++) {ldelim}
				var inputs = rows[i].querySelectorAll('input');
				for (var j = 0; j < inputs.length; j++) {ldelim}
					inputs[j].name = inputs[j].name.replace(/manualMetrics\[\d+\]/, 'manualMetrics[' + i + ']');
				{rdelim}
			{rdelim}
			if (addBtn) addBtn.style.display = rows.length >= MAX_ROWS ? 'none' : '';
		{rdelim}

		document.addEventListener('click', function(e) {ldelim}
			var target = e.target;

			// ---- Add a manual metric row
			if (target.closest && target.closest('#jmxManualAdd')) {ldelim}
				var body = document.getElementById('jmxManualBody');
				if (!body || body.querySelectorAll('tr').length >= MAX_ROWS) return;
				var tr = document.createElement('tr');
				tr.innerHTML =
					'<td>' + localizedCell('title', 80) + '</td>' +
					'<td><input type="text" name="manualMetrics[0][value]" value="" maxlength="20" aria-label="' + FIELD_LABELS.value + '" /></td>' +
					'<td><input type="text" name="manualMetrics[0][source]" value="" maxlength="80" aria-label="' + FIELD_LABELS.source + '" /></td>' +
					'<td>' + localizedCell('description', 200) + '</td>' +
					'<td class="jmx-muted">&mdash;</td>' +
					'<td><button type="button" class="jmx-row-remove">&times;</button></td>';
				body.appendChild(tr);
				reindex();
				return;
			{rdelim}

			// ---- Remove a manual metric row
			if (target.classList && target.classList.contains('jmx-row-remove')) {ldelim}
				var tr = target.closest('tr');
				if (tr) tr.parentNode.removeChild(tr);
				reindex();
				return;
			{rdelim}

			// ---- Recompute button (uses the settings form's CSRF token)
			var recomputeBtn = target.closest ? target.closest('#jmxSettingsRecompute') : null;
			if (recomputeBtn) {ldelim}
				if (recomputeBtn.disabled) return;
				recomputeBtn.disabled = true;
				var statusEl = document.getElementById('jmxRecomputeStatus');
				var xhr = new XMLHttpRequest();
				xhr.open('POST', recomputeBtn.getAttribute('data-url'), true);
				xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
				xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
				xhr.onreadystatechange = function() {ldelim}
					if (xhr.readyState === 4) {ldelim}
						recomputeBtn.disabled = false;
						if (statusEl) {ldelim}
							statusEl.textContent = xhr.status === 200
								? '{translate key="plugins.generic.journalMetrics.settings.recompute.done"}'
								: '{translate key="plugins.generic.journalMetrics.settings.recompute.failed"}';
						{rdelim}
					{rdelim}
				{rdelim};
				var csrfInput = document.querySelector('#jmxSettingsForm input[name="csrfToken"]');
				xhr.send('csrfToken=' + encodeURIComponent(csrfInput ? csrfInput.value : ''));
			{rdelim}
		{rdelim});

		// ---- Initial state (runs after the Vue re-render settles)
		document.addEventListener('DOMContentLoaded', function() {ldelim}
			setTimeout(function() {ldelim}
				reindex();
				var toast = document.getElementById('jmxSavedToast');
				if (toast) setTimeout(function() {ldelim} toast.style.opacity = '0'; {rdelim}, 3500);
			{rdelim}, 0);
		{rdelim});
	{rdelim})();
</script>

{/block}
