/**
 * Journal Metrics Plugin — dashboard & public page renderer.
 * Renders metric group cards, SVG charts, tables and the country section.
 * No external dependencies (vanilla JS + inline SVG).
 */
(function () {
  'use strict';

  var D = window.journalMetricsData;
  var C = window.journalMetricsConfig || {};
  if (!D) return;

  var L = D.labels || {};
  var TIPS = D.tooltips || {};
  // Numbers follow the interface language chosen server-side
  // (JournalMetricsPlugin::numberLocaleTag), never the browser's.
  var NL = D.numberLocale || 'en-US';
  var PCT_FIRST = NL.indexOf('tr') === 0; // Turkish: %66,7

  var COLORS = [
    '#0d9488', '#0891b2', '#2563eb', '#7c3aed', '#c026d3',
    '#e11d48', '#ea580c', '#d97706', '#65a30d', '#059669',
    '#6366f1', '#a855f7'
  ];
  var GROUP_ICONS = {
    editorial: '📥', times: '⏱', usage: '📈',
    community: '👥', output: '📚', manual: '⭐'
  };
  var GROUP_ORDER = ['editorial', 'times', 'usage', 'community', 'output', 'manual'];

  // ---------------------------------------------------------------------
  //  Utilities
  // ---------------------------------------------------------------------

  function t(key) { return L['ui.' + key] || key; }
  function label(key) {
    if (L[key]) return L[key];
    // byYear detail columns without a catalogue label of their own
    if (key === 'submissionsDeclinedDeskReject') return label('submissionsDeclined') + ' (' + t('deskReject') + ')';
    if (key === 'submissionsDeclinedPostReview') return label('submissionsDeclined') + ' (' + t('postReview') + ')';
    return key;
  }

  function escHtml(str) {
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(String(str)));
    return d.innerHTML;
  }

  function fmt(value) {
    if (value === null || value === undefined) return '—';
    if (typeof value === 'number') return value.toLocaleString(NL);
    return String(value);
  }

  // A percentage (already x100) with one decimal, sign placed per language.
  function pctStr(pct) {
    var s = Number(pct).toLocaleString(NL, { minimumFractionDigits: 1, maximumFractionDigits: 1 });
    return PCT_FIRST ? '%' + s : s + '%';
  }

  // Same display rule as JournalMetricsPlugin::formatRate().
  function fmtRate(rate) {
    return pctStr(Math.round(rate * 1000) / 10);
  }

  function pctOf(val, total) {
    return pctStr(total > 0 ? (val / total) * 100 : 0);
  }

  // ---------------------------------------------------------------------
  //  Metric cards
  // ---------------------------------------------------------------------

  function cardValueHtml(card) {
    if (card.insufficient) {
      return '<div class="jmx-card-value jmx-dim">—</div>' +
        '<div class="jmx-card-sub jmx-dim">' + escHtml(t('insufficient')) + '</div>';
    }
    var v = card.value;
    var main = '';
    var sub = '';

    switch (card.type) {
      case 'rate':
        main = fmtRate(v);
        if (card.n !== null && card.n !== undefined) sub = t('n') + ' = ' + fmt(card.n);
        break;
      case 'days':
        main = fmt(v) + ' <span class="jmx-unit">' + escHtml(t('days')) + '</span>';
        if (card.detail) {
          sub = t('median') + ': ' + fmt(card.detail.median) +
            ' · ' + t('p80') + ': ' + fmt(card.detail.p80) +
            ' · ' + t('n') + ' = ' + fmt(card.n);
          // managers only (the server sends it on the dashboard only)
          if (card.detail.dropped) sub += ' · ' + escHtml(t('droppedSpans')) + ': ' + fmt(card.detail.dropped);
        }
        break;
      case 'pair':
        main = fmt(v.views) + ' <span class="jmx-unit">' + escHtml(t('views')) + '</span> / ' +
          fmt(v.downloads) + ' <span class="jmx-unit">' + escHtml(t('downloads')) + '</span>';
        sub = String(v.year);
        break;
      default: // count
        main = fmt(v);
        sub = cardCountDetail(card);
    }

    return '<div class="jmx-card-value">' + main + '</div>' +
      (sub ? '<div class="jmx-card-sub">' + sub + '</div>' : '');
  }

  function cardCountDetail(card) {
    var d = card.detail;
    if (!d) return '';
    var parts = [];
    if (d.deskReject !== undefined) {
      parts.push(t('deskReject') + ': ' + fmt(d.deskReject));
      parts.push(t('postReview') + ': ' + fmt(d.postReview));
    }
    if (d.authors !== undefined) parts.push(t('authorsDetail') + ': ' + fmt(d.authors));
    if (d.readers !== undefined) parts.push(t('readersDetail') + ': ' + fmt(d.readers));
    if (d.avgPerArticle !== undefined && d.avgPerArticle !== null) parts.push(t('avgPerArticle') + ': ' + fmt(d.avgPerArticle));
    if (d.coverageYear) parts.push(coverageText());
    if (d.since !== undefined && d.since !== null) parts.push(t('since') + ' ' + String(d.since));
    if (d.totalIssues !== undefined && d.totalIssues !== null) parts.push(t('totalIssues') + ': ' + fmt(d.totalIssues));
    if (d.articles !== undefined && d.articles !== null) parts.push(fmt(d.articles) + ' ' + t('articles'));
    return parts.map(escHtml).join(' · ');
  }

  function renderCard(card, groupKey) {
    var badges = '';
    if (C.isAdmin && card.isPublic) {
      badges += '<span class="jmx-badge jmx-badge-public">' + escHtml(t('publicBadge')) + '</span>';
    }
    var tip = '';
    if (TIPS[card.key]) {
      tip = '<span class="jmx-tip" tabindex="0">?<span class="jmx-tip-text">' + escHtml(TIPS[card.key]) + '</span></span>';
    }
    return '<div class="jmx-card' + (card.insufficient ? ' jmx-card-insufficient' : '') + '" data-metric="' + escHtml(card.key) + '">' +
      '<div class="jmx-card-top"><span class="jmx-card-label">' + escHtml(label(card.key)) + tip + '</span>' + badges + '</div>' +
      cardValueHtml(card) +
      '</div>';
  }

  // ---------------------------------------------------------------------
  //  SVG charts
  // ---------------------------------------------------------------------

  // Bar charts are drawn at the pixel width of their box (one SVG unit =
  // one CSS pixel), so the 12px axis labels set in the stylesheet are 12px
  // on every screen. Where the box is narrow, fewer labels are printed so
  // they never overlap. Charts are redrawn when the box width changes.
  // Below a minimum width (a box squeezed by the surrounding layout) the
  // chart keeps that minimum and its box scrolls sideways instead of
  // shrinking the labels.
  var CHARTS = [];
  var CHAR_PX = 7.4; // average width of a 12px label glyph (digits, '-')

  function chartHost(spec) {
    CHARTS.push(spec);
    return '<div class="jmx-chart-host" data-jmx-chart="' + (CHARTS.length - 1) + '"></div>';
  }

  /** Smallest step from the list that leaves `minPx` per label. */
  function labelStep(slotPx, minPx, steps) {
    for (var i = 0; i < steps.length; i++) {
      if (steps[i] * slotPx >= minPx) return steps[i];
    }
    return steps[steps.length - 1];
  }

  function drawCharts() {
    var hosts = document.querySelectorAll('.jmx-chart-host[data-jmx-chart]');
    [].forEach.call(hosts, function (host) {
      var spec = CHARTS[parseInt(host.getAttribute('data-jmx-chart'), 10)];
      if (!spec) return;
      var w = Math.floor(host.getBoundingClientRect().width);
      if (w < 1) w = 640; // hidden box: any width, redrawn when shown
      if (host.getAttribute('data-jmx-w') === String(w)) return;
      host.setAttribute('data-jmx-w', String(w));
      host.innerHTML = spec.type === 'trend'
        ? trendChartSvg(spec.data, w)
        : yearBarChartSvg(spec.data, spec.color, spec.label, w);
    });
  }

  /** Vertical dual-series bar chart for the monthly usage trend. */
  function trendChartSvg(series, W) {
    var keys = Object.keys(series).sort();
    if (!keys.length) return '';

    // Continuous 24-month axis ending at the latest month present;
    // months without data render as zero (snapshot schema unchanged).
    var last = keys[keys.length - 1];
    var endYear = parseInt(last.substring(0, 4), 10);
    var endMonth = parseInt(last.substring(4, 6), 10);
    var months = [];
    var filled = {};
    for (var back = 23; back >= 0; back--) {
      var yy = endYear, mm = endMonth - back;
      while (mm <= 0) { mm += 12; yy--; }
      var key = String(yy) + (mm < 10 ? '0' : '') + mm;
      filled[key] = series[key] || { views: 0, downloads: 0 };
      months.push(key);
    }
    series = filled;

    var sumV = 0, sumD = 0;
    months.forEach(function (m) { sumV += series[m].views; sumD += series[m].downloads; });
    var trendAria = t('trendTitle') + ': ' + sumV.toLocaleString(NL) + ' ' + t('views') +
      ', ' + sumD.toLocaleString(NL) + ' ' + t('downloads');
    var max = 1;
    months.forEach(function (m) {
      max = Math.max(max, series[m].views, series[m].downloads);
    });
    W = Math.max(240, W || 640);
    var H = Math.round(Math.max(180, Math.min(300, W * 0.3)));
    var padT = 12, padB = 30, padR = 10;
    var padL = Math.ceil(Math.round(max).toLocaleString(NL).length * CHAR_PX) + 12;
    var innerW = W - padL - padR, innerH = H - padT - padB;
    var group = innerW / months.length;
    var barW = Math.max(1.5, Math.min(14, group * 0.34));
    // one 'YYYY-MM' label every `step` months, never closer than its width
    var step = labelStep(group, 7 * CHAR_PX + 10, [1, 2, 3, 4, 6, 12]);
    var svg = '<svg viewBox="0 0 ' + W + ' ' + H + '" style="width:' + W + 'px" class="jmx-trend-svg" role="img" aria-label="' + escHtml(trendAria) + '">';

    // horizontal gridlines + labels
    for (var g = 0; g <= 4; g++) {
      var gy = padT + innerH - (innerH * g / 4);
      var gv = Math.round(max * g / 4);
      svg += '<line x1="' + padL + '" y1="' + gy + '" x2="' + (W - padR) + '" y2="' + gy + '" class="jmx-grid"/>';
      svg += '<text x="' + (padL - 6) + '" y="' + (gy + 4) + '" class="jmx-axis-label" text-anchor="end">' + gv.toLocaleString(NL) + '</text>';
    }

    months.forEach(function (m, i) {
      var x = padL + i * group + (group - barW * 2 - 2) / 2;
      var hv = series[m].views / max * innerH;
      var hd = series[m].downloads / max * innerH;
      svg += '<rect x="' + x + '" y="' + (padT + innerH - hv) + '" width="' + barW + '" height="' + hv + '" fill="#0d9488"><title>' + m + ' · ' + t('views') + ': ' + series[m].views.toLocaleString(NL) + '</title></rect>';
      svg += '<rect x="' + (x + barW + 2) + '" y="' + (padT + innerH - hd) + '" width="' + barW + '" height="' + hd + '" fill="#2563eb"><title>' + m + ' · ' + t('downloads') + ': ' + series[m].downloads.toLocaleString(NL) + '</title></rect>';
      // the latest month is always labelled; others every `step` months
      if ((months.length - 1 - i) % step === 0) {
        var ml = m.substring(0, 4) + '-' + m.substring(4);
        var lx = Math.min(Math.max(x + barW, padL + 26), W - padR - 26);
        svg += '<text x="' + lx + '" y="' + (H - padB + 17) + '" class="jmx-axis-label" text-anchor="middle">' + ml + '</text>';
      }
    });

    svg += '</svg>';
    svg += '<div class="jmx-legend">' +
      '<span><span class="jmx-dot" style="background:#0d9488"></span>' + escHtml(t('views')) + '</span>' +
      '<span><span class="jmx-dot" style="background:#2563eb"></span>' + escHtml(t('downloads')) + '</span></div>';
    return svg;
  }

  /** Simple single-series vertical bar chart keyed by year. */
  function yearBarChartSvg(byYear, color, chartLabel, W) {
    var years = Object.keys(byYear).sort();
    if (!years.length) return '';
    var max = 1;
    years.forEach(function (y) { max = Math.max(max, byYear[y]); });
    W = Math.max(200, W || 340);
    var H = Math.round(Math.max(160, Math.min(240, W * 0.5)));
    var padT = 10, padB = 28, padR = 8;
    var padL = Math.ceil(Math.round(max).toLocaleString(NL).length * CHAR_PX) + 11;
    var innerW = W - padL - padR, innerH = H - padT - padB;
    var group = innerW / years.length;
    var barW = Math.max(4, Math.min(34, group * 0.6));
    // one year label every `step` years (the latest year always)
    var step = labelStep(group, 4 * CHAR_PX + 8, [1, 2, 3, 4, 5, 10, 20]);
    var barAria = (chartLabel || '') + ': ' + years.map(function (y) { return y + ' = ' + byYear[y]; }).join(', ');
    var svg = '<svg viewBox="0 0 ' + W + ' ' + H + '" style="width:' + W + 'px" class="jmx-yearbar-svg" role="img" aria-label="' + escHtml(barAria) + '">';
    for (var g = 0; g <= 2; g++) {
      var gy = padT + innerH - (innerH * g / 2);
      svg += '<line x1="' + padL + '" y1="' + gy + '" x2="' + (W - padR) + '" y2="' + gy + '" class="jmx-grid"/>';
      svg += '<text x="' + (padL - 5) + '" y="' + (gy + 4) + '" class="jmx-axis-label" text-anchor="end">' + Math.round(max * g / 2).toLocaleString(NL) + '</text>';
    }
    years.forEach(function (y, i) {
      var h = byYear[y] / max * innerH;
      var x = padL + i * group + (group - barW) / 2;
      svg += '<rect x="' + x + '" y="' + (padT + innerH - h) + '" width="' + barW + '" height="' + h + '" fill="' + color + '" rx="2"><title>' + y + ': ' + byYear[y].toLocaleString(NL) + '</title></rect>';
      if ((years.length - 1 - i) % step === 0) {
        svg += '<text x="' + (x + barW / 2) + '" y="' + (H - padB + 16) + '" class="jmx-axis-label" text-anchor="middle">' + y + '</text>';
      }
    });
    svg += '</svg>';
    return svg;
  }

  // ---------------------------------------------------------------------
  //  Group sections
  // ---------------------------------------------------------------------

  function sectionShell(groupKey, bodyHtml) {
    // Coverage declaration chip: workflow-derived groups only
    var chip = '';
    if (D.coverageYear && (groupKey === 'editorial' || groupKey === 'times')) {
      chip = ' <span class="jmx-coverage-chip" title="' + escHtml(coverageText()) + '">' + escHtml(String(D.coverageYear) + '+') + '</span>';
    }
    return '<div class="jmx-section" data-group="' + groupKey + '">' +
      '<div class="jmx-section-header"><h2>' +
      (GROUP_ICONS[groupKey] || '') + ' ' + escHtml(label('group.' + groupKey)) + chip +
      '</h2></div>' + bodyHtml + '</div>';
  }

  function coverageText() {
    return t('coverageNote').replace('{$year}', String(D.coverageYear));
  }

  function renderEditorial(group) {
    var html = '<div class="jmx-cards">' + (group.cards || []).map(function (card) { return renderCard(card, 'editorial'); }).join('') + '</div>';
    if (group.byYear && Object.keys(group.byYear).length) {
      var years = Object.keys(group.byYear).sort();
      var cols = {};
      years.forEach(function (y) {
        Object.keys(group.byYear[y]).forEach(function (c) { cols[c] = true; });
      });
      var colKeys = Object.keys(cols);
      html += '<div class="jmx-table-wrap"><table class="jmx-table jmx-year-table"><thead><tr><th>' + escHtml(t('year')) + '</th>';
      colKeys.forEach(function (c) { html += '<th>' + escHtml(label(c)) + '</th>'; });
      html += '</tr></thead><tbody>';
      var renderedYears = 0;
      years.forEach(function (y) {
        // skip years with no activity at all (every column zero/empty)
        var hasAny = colKeys.some(function (c) {
          var raw = group.byYear[y][c];
          return raw !== null && raw !== undefined && Number(raw) !== 0;
        });
        if (!hasAny) return;
        renderedYears++;
        html += '<tr><td class="jmx-count-bold">' + y + '</td>';
        colKeys.forEach(function (c) {
          var v = group.byYear[y][c];
          if ((c === 'acceptanceRate' || c === 'declineRate') && v !== null && v !== undefined) {
            v = fmtRate(v);
          }
          html += '<td>' + fmt(v) + '</td>';
        });
        html += '</tr>';
      });
      html += '</tbody></table></div>';
      if (!renderedYears) return sectionShell('editorial',
        '<div class="jmx-cards">' + (group.cards || []).map(function (card) { return renderCard(card, 'editorial'); }).join('') + '</div>');
      html += '<p class="jmx-table-footnote">' + escHtml(t('yearTableNote')) + '</p>';
    }
    return sectionShell('editorial', html);
  }

  function renderTimes(group) {
    return sectionShell('times',
      '<div class="jmx-cards">' + (group.cards || []).map(function (card) { return renderCard(card, 'times'); }).join('') + '</div>');
  }

  function renderUsage(group) {
    var html = '<div class="jmx-cards">' + (group.cards || []).map(function (card) { return renderCard(card, 'usage'); }).join('') + '</div>';
    if (group.monthlySeries) {
      html += '<div class="jmx-chart-card jmx-wide"><div class="jmx-chart-title">' + escHtml(t('trendTitle')) + '</div>' +
        chartHost({ type: 'trend', data: group.monthlySeries }) + '</div>';
    }
    if (group.topArticles && group.topArticles.length) {
      html += '<div class="jmx-chart-card jmx-wide"><div class="jmx-chart-title">' + escHtml(t('topArticlesTitle')) + '</div>' +
        '<div class="jmx-table-wrap"><table class="jmx-table"><thead><tr><th style="width:40px">' + escHtml(t('rank')) + '</th><th>' + escHtml(t('articles')) + '</th><th style="width:110px">' + escHtml(t('views')) + '</th></tr></thead><tbody>';
      var max = group.topArticles[0].views || 1;
      group.topArticles.forEach(function (article, i) {
        var titleText = article.title || ('#' + article.submissionId);
        var titleHtml = escHtml(titleText);
        if (C.articleUrlBase && article.submissionId) {
          // Stale-snapshot edge case: an article removed since the last
          // snapshot may 404 until the next refresh — accepted as-is.
          var href = C.articleUrlBase + '/' + encodeURIComponent(String(article.submissionId));
          titleHtml = '<a class="jmx-top-link" href="' + escHtml(href) + '" rel="noopener">' + escHtml(titleText) + '</a>';
        }
        html += '<tr><td class="jmx-muted">' + (i + 1) + '</td><td>' + titleHtml + '</td>' +
          '<td><span class="jmx-count-bold">' + fmt(article.views) + '</span>' +
          '<div class="jmx-mini-bar-bg"><div class="jmx-mini-bar-fill" style="width:' + (article.views / max * 100) + '%"></div></div></td></tr>';
      });
      html += '</tbody></table></div></div>';
    }
    return sectionShell('usage', html);
  }

  function renderCommunity(group) {
    var html = '<div class="jmx-cards">' + (group.cards || []).map(function (card) { return renderCard(card, 'community'); }).join('') + '</div>';
    if (group.completedReviewsByYear && Object.keys(group.completedReviewsByYear).length) {
      html += '<div class="jmx-chart-card"><div class="jmx-chart-title">' + escHtml(t('completedReviewsTitle')) + '</div>' +
        chartHost({ type: 'year', data: group.completedReviewsByYear, color: '#7c3aed', label: t('completedReviewsTitle') }) + '</div>';
    }
    return sectionShell('community', html);
  }

  function renderOutput(group) {
    var html = '<div class="jmx-cards">' + (group.cards || []).map(function (card) { return renderCard(card, 'output'); }).join('') + '</div>';
    var charts = '';
    if (group.articlesByYear && Object.keys(group.articlesByYear).length) {
      charts += '<div class="jmx-chart-card"><div class="jmx-chart-title">' + escHtml(t('articlesPerYearTitle')) + '</div>' +
        chartHost({ type: 'year', data: group.articlesByYear, color: '#0d9488', label: t('articlesPerYearTitle') }) + '</div>';
    }
    if (group.issuesByYear && Object.keys(group.issuesByYear).length) {
      charts += '<div class="jmx-chart-card"><div class="jmx-chart-title">' + escHtml(t('issuesPerYearTitle')) + '</div>' +
        chartHost({ type: 'year', data: group.issuesByYear, color: '#0891b2', label: t('issuesPerYearTitle') }) + '</div>';
    }
    if (charts) html += '<div class="jmx-charts-row">' + charts + '</div>';
    return sectionShell('output', html);
  }

  function renderManual(group) {
    if (!group.rows || !group.rows.length) return '';
    var html = '<div class="jmx-cards">';
    group.rows.forEach(function (row) {
      html += '<div class="jmx-card jmx-card-manual">' +
        '<div class="jmx-card-top"><span class="jmx-card-label">' + escHtml(row.title) + '</span></div>' +
        '<div class="jmx-card-value">' + escHtml(row.value) + '</div>' +
        '<div class="jmx-card-sub">' +
        (row.source ? escHtml(t('source')) + ': ' + escHtml(row.source) + ' · ' : '') +
        (row.lastUpdated ? escHtml(t('lastUpdated')) + ': ' + escHtml(row.lastUpdated) : '') +
        '</div>' +
        (row.description ? '<div class="jmx-card-desc">' + escHtml(row.description) + '</div>' : '') +
        '</div>';
    });
    html += '</div>';
    return sectionShell('manual', html);
  }

  var RENDERERS = {
    editorial: renderEditorial, times: renderTimes, usage: renderUsage,
    community: renderCommunity, output: renderOutput, manual: renderManual
  };

  function renderGroups() {
    var el = document.getElementById('jmxGroups');
    if (!el) return;
    CHARTS.length = 0;
    var html = '';
    GROUP_ORDER.forEach(function (key) {
      if (D.groups && D.groups[key] && RENDERERS[key]) {
        html += RENDERERS[key](D.groups[key]);
      }
    });
    el.innerHTML = html;
    drawCharts();
  }

  // ---------------------------------------------------------------------
  //  Country section (countryStats parity)
  // ---------------------------------------------------------------------

  var COUNTRY = { authors: [], articles: [], totals: { totalAuthors: 0, totalArticles: 0, totalCountries: 0 } };

  function formatCountry(d) {
    switch (C.countryDisplay) {
      case 'code': return d.code;
      case 'name': return d.name;
      default: return d.code + ' — ' + d.name;
    }
  }

  function sortCountryData(data, order) {
    var arr = data.slice();
    if (order === 'name_asc') {
      arr.sort(function (a, b) { return a.name.localeCompare(b.name); });
    } else {
      arr.sort(function (a, b) { return b.count - a.count; });
    }
    return arr;
  }

  function renderCountryBarChart(data) {
    var el = document.getElementById('jmxCountryBarChart');
    if (!el) return;
    var sorted = sortCountryData(data, 'count_desc').slice(0, 12);
    var max = sorted.length ? sorted[0].count : 1;
    el.setAttribute('role', 'img');
    el.setAttribute('aria-label', t('topCountries') + ': ' + sorted.slice(0, 3).map(function (d) {
      return d.name + ' ' + fmt(d.count);
    }).join(', '));
    var html = '';
    sorted.forEach(function (d, i) {
      html += '<div class="jmx-bar-row">' +
        '<div class="jmx-bar-country" title="' + escHtml(formatCountry(d)) + '">' + escHtml(formatCountry(d)) + '</div>' +
        '<div class="jmx-bar-track"><div class="jmx-bar-fill" style="width:' + (d.count / max * 100) + '%;background:' + COLORS[i % COLORS.length] + '"></div></div>' +
        '<span class="jmx-bar-count">' + fmt(d.count) + '</span></div>';
    });
    el.innerHTML = html;
  }

  function renderCountryDonut(data, total) {
    var svg = document.getElementById('jmxDonutChart');
    var legend = document.getElementById('jmxDonutLegend');
    if (!svg || !legend) return;

    var sorted = sortCountryData(data, 'count_desc');
    var top8 = sorted.slice(0, 8);
    var topSum = 0;
    top8.forEach(function (d) { topSum += d.count; });
    var segments = top8.map(function (d, i) {
      return { label: formatCountry(d), value: d.count, color: COLORS[i % COLORS.length] };
    });
    if (total - topSum > 0) segments.push({ label: t('other'), value: total - topSum, color: '#94a3b8' });

    var segTotal = 0;
    segments.forEach(function (s) { segTotal += s.value; });
    if (!segTotal) segTotal = 1;

    var cx = 100, cy = 100, r = 80, inner = 52, angle = -Math.PI / 2, paths = '';
    segments.forEach(function (seg) {
      var sweep = (seg.value / segTotal) * Math.PI * 2;
      if (sweep < 0.001) return;
      var x1 = cx + r * Math.cos(angle), y1 = cy + r * Math.sin(angle);
      var x2 = cx + r * Math.cos(angle + sweep), y2 = cy + r * Math.sin(angle + sweep);
      var ix1 = cx + inner * Math.cos(angle + sweep), iy1 = cy + inner * Math.sin(angle + sweep);
      var ix2 = cx + inner * Math.cos(angle), iy2 = cy + inner * Math.sin(angle);
      var large = sweep > Math.PI ? 1 : 0;
      paths += '<path d="M ' + x1.toFixed(2) + ' ' + y1.toFixed(2) +
        ' A ' + r + ' ' + r + ' 0 ' + large + ' 1 ' + x2.toFixed(2) + ' ' + y2.toFixed(2) +
        ' L ' + ix1.toFixed(2) + ' ' + iy1.toFixed(2) +
        ' A ' + inner + ' ' + inner + ' 0 ' + large + ' 0 ' + ix2.toFixed(2) + ' ' + iy2.toFixed(2) +
        ' Z" fill="' + seg.color + '" stroke="#fff" stroke-width="2"/>';
      angle += sweep;
    });
    paths += '<text x="' + cx + '" y="' + (cy - 4) + '" class="jmx-donut-center-text">' + (COUNTRY.totals.totalCountries || 0) + '</text>';
    paths += '<text x="' + cx + '" y="' + (cy + 14) + '" class="jmx-donut-center-label">' + escHtml(t('countriesWord')) + '</text>';
    svg.setAttribute('aria-label', t('authorDistribution') + ': ' + (COUNTRY.totals.totalCountries || 0) + ' ' + t('countriesWord'));
    svg.innerHTML = paths;

    legend.innerHTML = segments.map(function (seg) {
      return '<div class="jmx-donut-legend-item"><span class="jmx-donut-dot" style="background:' + seg.color + '"></span>' +
        '<span class="jmx-donut-legend-label" title="' + escHtml(seg.label) + '">' + escHtml(seg.label) + '</span>' +
        '<span class="jmx-donut-legend-pct">' + pctOf(seg.value, segTotal) + '</span></div>';
    }).join('');
  }

  function renderCountryTable(type) {
    var bodyId = type === 'authors' ? 'jmxAuthorsBody' : 'jmxArticlesBody';
    var body = document.getElementById(bodyId);
    if (!body) return;

    var data = type === 'authors' ? COUNTRY.authors : COUNTRY.articles;
    var total = type === 'authors' ? COUNTRY.totals.totalAuthors : COUNTRY.totals.totalArticles;
    var searchEl = document.getElementById(type === 'authors' ? 'jmxSearchAuthors' : 'jmxSearchArticles');
    var query = searchEl ? searchEl.value.toLowerCase() : '';

    var sorted = sortCountryData(data, C.sortOrder);
    var max = sorted.length ? sorted[0].count : 1;
    var html = '';
    var shown = 0;
    sorted.forEach(function (d) {
      if (query && d.name.toLowerCase().indexOf(query) === -1 && d.code.toLowerCase().indexOf(query) === -1) return;
      shown++;
      html += '<tr><td class="jmx-muted">' + shown + '</td>' +
        '<td><div class="jmx-country-cell"><span class="jmx-flag-placeholder">' + escHtml(d.code) + '</span><span>' + escHtml(d.name) + '</span></div></td>' +
        '<td><span class="jmx-count-bold">' + fmt(d.count) + '</span></td>' +
        '<td><div class="jmx-mini-bar-bg"><div class="jmx-mini-bar-fill" style="width:' + (d.count / max * 100) + '%"></div></div></td>' +
        '<td><span class="jmx-pct">' + pctOf(d.count, total) + '</span></td></tr>';
    });
    if (!shown) {
      html = '<tr><td colspan="5" class="jmx-empty-row">' + escHtml(t('noResults')) + '</td></tr>';
    }
    body.innerHTML = html;
  }

  function applyCountryFilters(rows) {
    var out = rows;
    if (C.minThreshold > 1) {
      out = out.filter(function (r) { return r.count >= C.minThreshold; });
    }
    if (!C.includeUnknown) {
      out = out.filter(function (r) { return r.code !== 'XX'; });
    }
    return out;
  }

  function renderCountrySection() {
    renderCountryBarChart(COUNTRY.authors);
    renderCountryDonut(COUNTRY.authors, COUNTRY.totals.totalAuthors);
    renderCountryTable('authors');
    renderCountryTable('articles');
  }

  function fetchCountryData() {
    if (!C.fetchUrl) return;
    var timeFilter = document.getElementById('jmxTimeFilter');
    var strategyFilter = document.getElementById('jmxStrategyFilter');
    var url = C.fetchUrl;

    var stratVal = strategyFilter ? strategyFilter.value : 'orcid';
    url += (url.indexOf('?') > -1 ? '&' : '?') + 'strategy=' + encodeURIComponent(stratVal);

    var timeVal = timeFilter ? timeFilter.value : 'all';
    if (timeVal === '12' || timeVal === '24') {
      var d = new Date();
      d.setMonth(d.getMonth() - parseInt(timeVal, 10));
      url += '&dateStart=' + encodeURIComponent(d.toISOString().split('T')[0]);
    }

    var xhr = new XMLHttpRequest();
    xhr.open('GET', url, true);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.onreadystatechange = function () {
      if (xhr.readyState === 4 && xhr.status === 200) {
        try {
          var resp = JSON.parse(xhr.responseText);
          COUNTRY.authors = applyCountryFilters(resp.authorsByCountry || []);
          COUNTRY.articles = applyCountryFilters(resp.articlesByCountry || []);
          COUNTRY.totals = resp.totals || COUNTRY.totals;
          renderCountrySection();
        } catch (e) {
          if (window.console) console.error('journalMetrics: country fetch parse failed', e);
        }
      }
    };
    xhr.send();
  }

  function initCountrySection() {
    var section = document.getElementById('jmxCountrySection');
    if (!section) return;

    // Snapshot-backed rendering (public page, and instant first paint on
    // the dashboard before the live fetch responds).
    if (D.countrySection && D.countrySection.authors) {
      COUNTRY.authors = applyCountryFilters(D.countrySection.authors);
      COUNTRY.articles = applyCountryFilters(D.countrySection.articles || []);
      COUNTRY.totals = D.countrySection.totals || COUNTRY.totals;
      section.style.display = '';
      renderCountrySection();
    } else if (!C.fetchUrl) {
      // Public page with no snapshot data yet: keep the section hidden
      return;
    }

    fetchCountryData();

    var timeFilter = document.getElementById('jmxTimeFilter');
    var strategyFilter = document.getElementById('jmxStrategyFilter');
    if (timeFilter) timeFilter.addEventListener('change', fetchCountryData);
    if (strategyFilter) strategyFilter.addEventListener('change', fetchCountryData);

    ['jmxSearchAuthors', 'jmxSearchArticles'].forEach(function (id) {
      var el = document.getElementById(id);
      if (el) {
        el.addEventListener('input', function () {
          renderCountryTable(id === 'jmxSearchAuthors' ? 'authors' : 'articles');
        });
      }
    });

    initCountryTabs(section);
  }

  /**
   * Accessible tab strip (WAI-ARIA tabs pattern): click, Enter/Space,
   * arrow keys + Home/End; aria-selected and roving tabindex maintained.
   */
  function initCountryTabs(section) {
    var tabs = [].slice.call(section.querySelectorAll('.jmx-tab'));
    if (!tabs.length) return;

    function activate(tab) {
      var contents = section.querySelectorAll('.jmx-tab-content');
      for (var j = 0; j < tabs.length; j++) {
        var isActive = tabs[j] === tab;
        tabs[j].classList.toggle('active', isActive);
        tabs[j].setAttribute('aria-selected', isActive ? 'true' : 'false');
        tabs[j].setAttribute('tabindex', isActive ? '0' : '-1');
      }
      for (var k = 0; k < contents.length; k++) contents[k].classList.remove('active');
      var target = document.getElementById('jmx-tab-' + tab.getAttribute('data-tab'));
      if (target) target.classList.add('active');
    }

    tabs.forEach(function (tab, index) {
      // initial ARIA state mirrors the server-rendered .active class
      tab.setAttribute('tabindex', tab.classList.contains('active') ? '0' : '-1');
      tab.setAttribute('aria-selected', tab.classList.contains('active') ? 'true' : 'false');

      tab.addEventListener('click', function () { activate(tab); });
      tab.addEventListener('keydown', function (e) {
        var next = null;
        if (e.key === 'Enter' || e.key === ' ') { activate(tab); e.preventDefault(); return; }
        if (e.key === 'ArrowRight' || e.key === 'ArrowDown') next = tabs[(index + 1) % tabs.length];
        if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') next = tabs[(index - 1 + tabs.length) % tabs.length];
        if (e.key === 'Home') next = tabs[0];
        if (e.key === 'End') next = tabs[tabs.length - 1];
        if (next) { activate(next); next.focus(); e.preventDefault(); }
      });
    });
  }

  // ---------------------------------------------------------------------
  //  Recompute button (admin)
  // ---------------------------------------------------------------------

  function initRecompute() {
    var btn = document.getElementById('jmxRecomputeBtn');
    if (!btn || !C.csrfToken) return;
    btn.addEventListener('click', function () {
      if (btn.disabled) return;
      btn.disabled = true;
      var original = btn.innerHTML;
      btn.innerHTML = '…';
      var xhr = new XMLHttpRequest();
      xhr.open('POST', btn.getAttribute('data-url'), true);
      xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
      xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      xhr.onreadystatechange = function () {
        if (xhr.readyState === 4) {
          if (xhr.status === 200) {
            window.location.reload();
          } else {
            btn.disabled = false;
            btn.innerHTML = original;
            if (window.console) console.error('journalMetrics: recompute failed', xhr.status);
          }
        }
      };
      xhr.send('csrfToken=' + encodeURIComponent(C.csrfToken));
    });
  }

  // ---------------------------------------------------------------------
  //  Init
  // ---------------------------------------------------------------------

  function init() {
    renderGroups();
    initCountrySection();
    initRecompute();

    // redraw the bar charts when their box width changes (resize, rotate,
    // late stylesheet, print layout)
    var timer = null;
    function later() { clearTimeout(timer); timer = setTimeout(drawCharts, 150); }
    window.addEventListener('resize', later);
    window.addEventListener('load', drawCharts);
    if (window.matchMedia) {
      var mq = window.matchMedia('print');
      if (mq.addEventListener) mq.addEventListener('change', drawCharts);
      else if (mq.addListener) mq.addListener(drawCharts);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
