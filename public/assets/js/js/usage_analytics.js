/**
 * C:\www\cpms\public\assets\js\usage_analytics.js
 * - CPMS 사용현황 필터 보조, 로그 정리 확인, 순수 SVG 추세 그래프
 */
(function () {
  'use strict';

  var svgNamespace = 'http://www.w3.org/2000/svg';

  function svgElement(name, attributes, text) {
    var element = document.createElementNS(svgNamespace, name);
    var key;
    attributes = attributes || {};
    for (key in attributes) {
      if (Object.prototype.hasOwnProperty.call(attributes, key)) {
        element.setAttribute(key, String(attributes[key]));
      }
    }
    if (typeof text !== 'undefined' && text !== null) {
      element.appendChild(document.createTextNode(String(text)));
    }
    return element;
  }

  function setupFilters() {
    var form = document.querySelector('[data-usage-filter-form]');
    if (!form) return;
    var period = form.querySelector('[data-usage-period]');
    var customFields = form.querySelectorAll('[data-usage-custom-date]');
    var menu = form.querySelector('[data-usage-menu]');
    var tab = form.querySelector('select[name="tab"]');

    function toggleDates() {
      var show = period && period.value === 'custom';
      var index;
      for (index = 0; index < customFields.length; index++) {
        customFields[index].style.display = show ? '' : 'none';
      }
    }
    if (period) {
      period.addEventListener('change', toggleDates);
      toggleDates();
    }
    if (menu && tab) {
      menu.addEventListener('change', function () {
        tab.value = '';
      });
    }
  }

  function setupCleanupConfirm() {
    var forms = document.querySelectorAll('[data-usage-cleanup-form]');
    var index;
    for (index = 0; index < forms.length; index++) {
      forms[index].addEventListener('submit', function (event) {
        var cutoff = this.getAttribute('data-cutoff') || '';
        var message = cutoff + ' 이전 상세 활동기록을 삭제합니다. 오늘과 최근 기록은 삭제되지 않습니다. 계속하시겠습니까?';
        if (!window.confirm(message)) event.preventDefault();
      });
    }
  }

  function renderTrend() {
    var container = document.querySelector('[data-usage-trend]');
    var rows = window.cpmsUsageTrendData;
    if (!container || !rows || !rows.length) return;

    var width = Math.max(760, rows.length * 46);
    var height = 290;
    var left = 42;
    var right = 18;
    var top = 22;
    var bottom = 42;
    var plotWidth = width - left - right;
    var plotHeight = height - top - bottom;
    var maxValue = 1;
    var index;
    for (index = 0; index < rows.length; index++) {
      maxValue = Math.max(maxValue, Number(rows[index].users) || 0, Number(rows[index].connections) || 0, Number(rows[index].activities) || 0);
    }
    maxValue = Math.ceil(maxValue / 5) * 5 || 5;

    var svg = svgElement('svg', {
      viewBox: '0 0 ' + width + ' ' + height,
      role: 'img',
      'aria-label': '날짜별 접속 직원 수, 접속 횟수, 활동 수 추세'
    });
    var gridCount = 5;
    var gridIndex;
    for (gridIndex = 0; gridIndex <= gridCount; gridIndex++) {
      var gridY = top + (plotHeight * gridIndex / gridCount);
      var gridValue = Math.round(maxValue * (gridCount - gridIndex) / gridCount);
      svg.appendChild(svgElement('line', { x1: left, y1: gridY, x2: width - right, y2: gridY, 'class': 'ua-grid-line' }));
      svg.appendChild(svgElement('text', { x: left - 8, y: gridY + 3, 'text-anchor': 'end' }, gridValue));
    }

    var usersPoints = [];
    var connectionsPoints = [];
    var slot = plotWidth / rows.length;
    var labelEvery = rows.length > 31 ? 5 : (rows.length > 14 ? 2 : 1);
    for (index = 0; index < rows.length; index++) {
      var row = rows[index];
      var x = left + slot * index + slot / 2;
      var users = Number(row.users) || 0;
      var connections = Number(row.connections) || 0;
      var activities = Number(row.activities) || 0;
      var usersY = top + plotHeight - (users / maxValue * plotHeight);
      var connectionsY = top + plotHeight - (connections / maxValue * plotHeight);
      var activityHeight = activities / maxValue * plotHeight;
      var barWidth = Math.max(4, Math.min(16, slot * 0.34));

      var activityBar = svgElement('rect', {
        x: x - barWidth / 2,
        y: top + plotHeight - activityHeight,
        width: barWidth,
        height: Math.max(0, activityHeight),
        rx: 3,
        'class': 'ua-bar-activities'
      });
      activityBar.appendChild(svgElement('title', {}, row.date + ' 활동 ' + activities + '회'));
      svg.appendChild(activityBar);

      usersPoints.push(x + ',' + usersY);
      connectionsPoints.push(x + ',' + connectionsY);
      if (index % labelEvery === 0 || index === rows.length - 1) {
        svg.appendChild(svgElement('text', { x: x, y: height - 16, 'text-anchor': 'middle' }, String(row.date).substring(5)));
      }
    }

    svg.appendChild(svgElement('polyline', { points: usersPoints.join(' '), 'class': 'ua-line-users' }));
    svg.appendChild(svgElement('polyline', { points: connectionsPoints.join(' '), 'class': 'ua-line-connections' }));

    for (index = 0; index < rows.length; index++) {
      var pointRow = rows[index];
      var pointX = left + slot * index + slot / 2;
      var pointUsers = Number(pointRow.users) || 0;
      var pointConnections = Number(pointRow.connections) || 0;
      var pointUsersY = top + plotHeight - (pointUsers / maxValue * plotHeight);
      var pointConnectionsY = top + plotHeight - (pointConnections / maxValue * plotHeight);
      var usersCircle = svgElement('circle', { cx: pointX, cy: pointUsersY, r: 3.2, 'class': 'ua-point-users' });
      usersCircle.appendChild(svgElement('title', {}, pointRow.date + ' 접속 직원 ' + pointUsers + '명'));
      svg.appendChild(usersCircle);
      var connectionsCircle = svgElement('circle', { cx: pointX, cy: pointConnectionsY, r: 3.2, 'class': 'ua-point-connections' });
      connectionsCircle.appendChild(svgElement('title', {}, pointRow.date + ' 접속 ' + pointConnections + '회'));
      svg.appendChild(connectionsCircle);
    }

    container.innerHTML = '';
    container.appendChild(svg);
  }

  function init() {
    setupFilters();
    setupCleanupConfirm();
    renderTrend();
    if (window.lucide) window.lucide.createIcons();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
}());
