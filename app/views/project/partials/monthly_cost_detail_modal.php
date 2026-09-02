<?php
/**
 * 파일: app/views/project/partials/monthly_cost_detail_modal.php
 * 월별 투입비 상세 공통 모달
 *
 * 사용 전 변수:
 * - $cpmsMonthlyCostDetailPayload: 프로젝트/월별 상세 데이터 배열
 * - $cpmsMonthlyCostDetailTriggerSelector: 클릭 버튼 선택자(선택)
 * - $cpmsMonthlyCostDetailDesktopOnly: true이면 767px 이하에서 클릭 비활성화(선택)
 *
 * PHP 5.6 호환
 */

$cpmsMonthlyCostDetailPayload = isset($cpmsMonthlyCostDetailPayload) && is_array($cpmsMonthlyCostDetailPayload)
    ? $cpmsMonthlyCostDetailPayload
    : array();
$cpmsMonthlyCostDetailTriggerSelector = isset($cpmsMonthlyCostDetailTriggerSelector) && trim((string)$cpmsMonthlyCostDetailTriggerSelector) !== ''
    ? trim((string)$cpmsMonthlyCostDetailTriggerSelector)
    : '.cpms-monthly-detail-trigger';
$cpmsMonthlyCostDetailDesktopOnly = isset($cpmsMonthlyCostDetailDesktopOnly) ? (bool)$cpmsMonthlyCostDetailDesktopOnly : false;
$cpmsMonthlyCostDetailEndpoint = isset($cpmsMonthlyCostDetailEndpoint) ? trim((string)$cpmsMonthlyCostDetailEndpoint) : '';
$cpmsMonthlyCostDetailJson = json_encode(
    $cpmsMonthlyCostDetailPayload,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
if (!is_string($cpmsMonthlyCostDetailJson) || $cpmsMonthlyCostDetailJson === '') $cpmsMonthlyCostDetailJson = '{}';
?>
<style>
.cpms-monthly-detail-trigger,
.cpms-monthly-input-detail-trigger {
  width: 100%;
  min-height: 34px;
  padding: 2px 0;
  border: 0;
  background: transparent;
  color: inherit;
  font: inherit;
  font-weight: 800;
  text-align: right;
  white-space: nowrap;
  cursor: pointer;
  text-decoration: underline;
  text-decoration-color: rgba(37, 99, 235, .42);
  text-underline-offset: 3px;
}
.cpms-monthly-detail-trigger:hover,
.cpms-monthly-input-detail-trigger:hover {
  color: #1d4ed8;
  background: #eff6ff;
}
.cpms-cost-detail-modal {
  position: fixed;
  inset: 0;
  z-index: 2147482000;
  display: none;
  align-items: center;
  justify-content: center;
  padding: 24px;
  background: rgba(15, 23, 42, .62);
  backdrop-filter: blur(3px);
}
.cpms-cost-detail-modal.is-open { display: flex; }
.cpms-cost-detail-dialog {
  width: min(1380px, calc(100vw - 48px));
  max-height: min(92vh, 920px);
  display: flex;
  flex-direction: column;
  overflow: hidden;
  border: 1px solid #dbe3ef;
  border-radius: 24px;
  background: #fff;
  box-shadow: 0 30px 100px rgba(15, 23, 42, .36);
}
.cpms-cost-detail-header {
  flex: 0 0 auto;
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 18px;
  padding: 20px 24px;
  border-bottom: 1px solid #e5e7eb;
  background: linear-gradient(135deg, #f8fafc, #fff);
}
.cpms-cost-detail-title { min-width: 0; }
.cpms-cost-detail-title h4 {
  margin: 0;
  color: #0f172a;
  font-size: 22px;
  line-height: 1.35;
  font-weight: 900;
}
.cpms-cost-detail-meta {
  margin-top: 5px;
  color: #64748b;
  font-size: 13px;
  line-height: 1.45;
  font-weight: 700;
}
.cpms-cost-detail-total {
  margin-top: 8px;
  color: #1d4ed8;
  font-size: 17px;
  font-weight: 900;
}
.cpms-cost-detail-close {
  flex: 0 0 auto;
  min-width: 72px;
  min-height: 42px;
  padding: 8px 16px;
  border: 1px solid #cbd5e1;
  border-radius: 12px;
  background: #fff;
  color: #334155;
  font-size: 14px;
  font-weight: 900;
  cursor: pointer;
}
.cpms-cost-detail-close:hover { background: #f1f5f9; }
.cpms-cost-detail-tabs {
  flex: 0 0 auto;
  display: none;
  gap: 8px;
  padding: 12px 24px;
  overflow-x: auto;
  border-bottom: 1px solid #e5e7eb;
  background: #fff;
}
.cpms-cost-detail-tabs.is-visible { display: flex; }
.cpms-cost-detail-tab {
  flex: 0 0 auto;
  min-width: 150px;
  min-height: 42px;
  padding: 8px 18px;
  border: 1px solid #cbd5e1;
  border-radius: 12px;
  background: #fff;
  color: #475569;
  font-size: 14px;
  font-weight: 900;
  cursor: pointer;
}
.cpms-cost-detail-tab.is-active {
  border-color: #1d4ed8;
  background: #1d4ed8;
  color: #fff;
}
.cpms-cost-detail-body {
  flex: 1 1 auto;
  min-height: 0;
  overflow: auto;
  padding: 20px 24px 26px;
  overscroll-behavior: contain;
}
.cpms-cost-detail-table-wrap {
  width: 100%;
  overflow: auto;
  border: 1px solid #dbe3ef;
  border-radius: 16px;
  background: #fff;
}
.cpms-cost-detail-table {
  width: 100%;
  min-width: 980px;
  border-collapse: separate;
  border-spacing: 0;
  color: #1e293b;
  font-size: 13px;
}
.cpms-cost-detail-table th,
.cpms-cost-detail-table td {
  padding: 11px 12px;
  border-right: 1px solid #e5e7eb;
  border-bottom: 1px solid #e5e7eb;
  vertical-align: middle;
  white-space: nowrap;
}
.cpms-cost-detail-table th {
  position: sticky;
  top: 0;
  z-index: 4;
  background: #e9eef5;
  color: #0f172a;
  font-weight: 900;
  text-align: left;
}
.cpms-cost-detail-table th:first-child,
.cpms-cost-detail-table td:first-child {
  position: sticky;
  left: 0;
  z-index: 3;
  min-width: 105px;
  background: inherit;
  font-weight: 800;
}
.cpms-cost-detail-table th:first-child {
  z-index: 5;
  background: #e9eef5;
}
.cpms-cost-detail-table tbody tr:nth-child(even) td { background: #f8fafc; }
.cpms-cost-detail-table tbody tr:nth-child(odd) td { background: #fff; }
.cpms-cost-detail-table tbody tr.is-daily-change td { background:#ecfdf5 !important; }
.cpms-cost-detail-table tbody tr.is-daily-decrease td { background:#fff7ed !important; }
.cpms-cost-detail-change-badge { display:inline-flex; margin-left:8px; padding:3px 7px; border-radius:999px; background:#d1fae5; color:#047857; font-size:11px; font-weight:900; }
.cpms-cost-detail-table tr:last-child td { border-bottom: 0; }
.cpms-cost-detail-table th:last-child,
.cpms-cost-detail-table td:last-child { border-right: 0; }
.cpms-cost-detail-table .is-number {
  text-align: right;
  font-variant-numeric: tabular-nums;
}
.cpms-cost-detail-table .is-center { text-align: center; }
.cpms-cost-detail-table .is-remark {
  min-width: 210px;
  max-width: 360px;
  white-space: normal;
  word-break: keep-all;
  overflow-wrap: anywhere;
}
.cpms-cost-detail-file-list {
  display: flex;
  min-width: 190px;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
}
.cpms-cost-detail-file-name {
  width: 100%;
  max-width: 260px;
  overflow: hidden;
  color: #475569;
  font-size: 11px;
  font-weight: 700;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.cpms-cost-detail-file-link {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-height: 30px;
  padding: 5px 9px;
  border: 1px solid #bfdbfe;
  border-radius: 9px;
  background: #eff6ff;
  color: #1d4ed8;
  font-size: 11px;
  font-weight: 900;
  text-decoration: none;
}
.cpms-cost-detail-file-link.is-download {
  border-color: #d1d5db;
  background: #fff;
  color: #374151;
}
.cpms-cost-detail-empty {
  padding: 70px 20px;
  border: 1px dashed #cbd5e1;
  border-radius: 16px;
  background: #f8fafc;
  color: #64748b;
  text-align: center;
  font-size: 14px;
  font-weight: 800;
}
body.cpms-cost-detail-open { overflow: hidden !important; }
@media (max-width: 767px) {
  .cpms-cost-detail-modal {
    align-items: flex-end;
    padding: 0;
  }
  .cpms-cost-detail-dialog {
    width: 100%;
    max-height: 94vh;
    border-right: 0;
    border-bottom: 0;
    border-left: 0;
    border-radius: 22px 22px 0 0;
  }
  .cpms-cost-detail-header { padding: 16px 14px; }
  .cpms-cost-detail-title h4 { font-size: 17px; }
  .cpms-cost-detail-meta { font-size: 12px; }
  .cpms-cost-detail-total { font-size: 15px; }
  .cpms-cost-detail-tabs { padding: 10px 12px; }
  .cpms-cost-detail-tab { min-height: 44px; font-size: 12px; }
  .cpms-cost-detail-body { padding: 12px 10px calc(18px + env(safe-area-inset-bottom)); }
  .cpms-cost-detail-table { min-width: 860px; font-size: 12px; }
  .cpms-cost-detail-table th,
  .cpms-cost-detail-table td { padding: 9px 10px; }
  <?php if ($cpmsMonthlyCostDetailDesktopOnly): ?>
  .cpms-monthly-input-detail-trigger {
    pointer-events: none;
    text-decoration: none;
  }
  <?php endif; ?>
}
</style>

<div id="cpmsCostDetailModal" class="cpms-cost-detail-modal" aria-hidden="true">
  <div class="cpms-cost-detail-dialog" role="dialog" aria-modal="true" aria-labelledby="cpmsCostDetailTitle">
    <div class="cpms-cost-detail-header">
      <div class="cpms-cost-detail-title">
        <h4 id="cpmsCostDetailTitle">상세 내역</h4>
        <div id="cpmsCostDetailMeta" class="cpms-cost-detail-meta"></div>
        <div id="cpmsCostDetailTotal" class="cpms-cost-detail-total"></div>
      </div>
      <button type="button" class="cpms-cost-detail-close" data-cost-detail-close aria-label="상세 모달 닫기">닫기</button>
    </div>
    <div id="cpmsCostDetailTabs" class="cpms-cost-detail-tabs">
      <button type="button" class="cpms-cost-detail-tab is-active" data-outsourcing-tab="labor_outsourcing">노무성 외주비</button>
      <button type="button" class="cpms-cost-detail-tab" data-outsourcing-tab="manual_outsourcing">일반 외주비</button>
    </div>
    <div id="cpmsCostDetailBody" class="cpms-cost-detail-body"></div>
  </div>
</div>

<script>
(function(){
  'use strict';
  var detailData = <?php echo $cpmsMonthlyCostDetailJson; ?>;
  var triggerSelector = <?php echo json_encode($cpmsMonthlyCostDetailTriggerSelector); ?>;
  var desktopOnly = <?php echo $cpmsMonthlyCostDetailDesktopOnly ? 'true' : 'false'; ?>;
  var detailEndpoint = <?php echo json_encode($cpmsMonthlyCostDetailEndpoint); ?>;
  var modal = document.getElementById('cpmsCostDetailModal');
  var title = document.getElementById('cpmsCostDetailTitle');
  var meta = document.getElementById('cpmsCostDetailMeta');
  var total = document.getElementById('cpmsCostDetailTotal');
  var body = document.getElementById('cpmsCostDetailBody');
  var tabs = document.getElementById('cpmsCostDetailTabs');
  var currentProjectId = '';
  var currentYm = '';
  var currentType = '';
  var currentCompany = '';
  var currentCategory = '';
  var currentRowLabel = '';
  var currentSnapshotDate = '';
  var currentLoadKey = '';
  var currentOutsourcingTab = 'labor_outsourcing';

  function escapeHtml(value) {
    return String(value === null || typeof value === 'undefined' ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function escapeAttr(value) { return escapeHtml(value); }

  function formatMoney(value) {
    var number = parseFloat(value);
    if (isNaN(number) || Math.abs(number) < 0.0001) return '0';
    return String(Math.round(number)).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  }

  function formatCount(value) {
    if (value === null || typeof value === 'undefined' || value === '') return '-';
    var number = parseFloat(value);
    if (isNaN(number)) return String(value);
    if (Math.abs(number - Math.round(number)) < 0.0001) return String(Math.round(number));
    return String(number.toFixed(2)).replace(/0+$/, '').replace(/\.$/, '');
  }

  function formatFileSize(value) {
    var size = parseFloat(value);
    if (isNaN(size) || size <= 0) return '';
    if (size >= 1048576) return (size / 1048576).toFixed(1) + 'MB';
    if (size >= 1024) return Math.round(size / 1024) + 'KB';
    return Math.round(size) + 'B';
  }

  function trimText(value) {
    return String(value === null || typeof value === 'undefined' ? '' : value).replace(/^\s+|\s+$/g, '');
  }

  function emptyHtml(message) {
    return '<div class="cpms-cost-detail-empty">' + escapeHtml(message || '등록된 상세 내역이 없습니다.') + '</div>';
  }

  function fileHtml(files) {
    if (!files || !files.length) return '-';
    var html = '<div class="cpms-cost-detail-file-list">';
    for (var i = 0; i < files.length; i++) {
      var file = files[i] || {};
      var name = trimText(file.name) || '첨부파일';
      var sizeText = formatFileSize(file.file_size);
      html += '<div class="cpms-cost-detail-file-name" title="' + escapeAttr(name) + '">' + escapeHtml(name) + (sizeText ? ' · ' + escapeHtml(sizeText) : '') + '</div>';
      if (file.view_url) html += '<a class="cpms-cost-detail-file-link" href="' + escapeAttr(file.view_url) + '" target="_blank" rel="noopener">보기</a>';
      if (file.download_url) html += '<a class="cpms-cost-detail-file-link is-download" href="' + escapeAttr(file.download_url) + '">다운로드</a>';
    }
    html += '</div>';
    return html;
  }

  function tableHtml(columns, rows) {
    if (!rows || !rows.length) return emptyHtml('등록된 상세 내역이 없습니다.');
    var html = '<div class="cpms-cost-detail-table-wrap"><table class="cpms-cost-detail-table"><thead><tr>';
    var i;
    for (i = 0; i < columns.length; i++) html += '<th>' + escapeHtml(columns[i].label) + '</th>';
    html += '</tr></thead><tbody>';
    for (var r = 0; r < rows.length; r++) {
      var row = rows[r] || {};
      var rowClass = '';
      if (row.is_changed) rowClass = ' class="is-daily-change"';
      else if (parseFloat(row.change_amount || 0) < -0.01) rowClass = ' class="is-daily-decrease"';
      html += '<tr' + rowClass + '>';
      for (i = 0; i < columns.length; i++) {
        var column = columns[i];
        var value = row[column.key];
        var displayValue = '';
        var rawHtml = false;
        if (column.format === 'money') displayValue = formatMoney(value);
        else if (column.format === 'count') displayValue = formatCount(value);
        else if (column.format === 'files') { displayValue = fileHtml(value); rawHtml = true; }
        else if (value === null || typeof value === 'undefined' || value === '') displayValue = '-';
        else displayValue = String(value);
        var cls = '';
        if (column.align === 'right') cls += ' is-number';
        if (column.align === 'center') cls += ' is-center';
        if (column.remark) cls += ' is-remark';
        html += '<td class="' + cls.replace(/^\s+/, '') + '">' + (rawHtml ? displayValue : escapeHtml(displayValue)) + '</td>';
      }
      html += '</tr>';
    }
    html += '</tbody></table></div>';
    return html;
  }

  function normalizeCompany(value) {
    value = trimText(value);
    if (value === '-' || value === '전체' || value === '데이터 없음') return '';
    return value;
  }

  function filterRows(rows, typeName) {
    rows = rows || [];
    var company = normalizeCompany(currentCompany);
    var category = trimText(currentCategory);
    if (!company && !category) return rows;
    var filtered = [];
    for (var i = 0; i < rows.length; i++) {
      var row = rows[i] || {};
      var rowCompany = '';
      if (typeName === 'labor' || typeName === 'labor_outsourcing' || typeName === 'manual_outsourcing') rowCompany = trimText(row.company_name);
      else if (typeName === 'equipment' || typeName === 'material' || typeName === 'safety') rowCompany = trimText(row.vendor_name);
      var rowCategory = trimText(row.category);
      if (company && rowCompany !== company) continue;
      if (category && rowCategory !== category) continue;
      filtered.push(row);
    }
    return filtered;
  }

  function sumRows(rows) {
    var sum = 0;
    rows = rows || [];
    for (var i = 0; i < rows.length; i++) {
      var amount = parseFloat(rows[i] && rows[i].amount);
      if (!isNaN(amount)) sum += amount;
    }
    return sum;
  }

  function formatSignedMoney(value) {
    var number = parseFloat(value);
    if (isNaN(number) || Math.abs(number) < 0.01) return '';
    return (number > 0 ? '+' : '-') + formatMoney(Math.abs(number)) + '원';
  }

  function ensureCurrentData(callback) {
    if (currentMonthData()) {
      callback();
      return;
    }
    if (!detailEndpoint) {
      callback();
      return;
    }
    var loadKey = currentProjectId + '|' + currentYm + '|' + currentSnapshotDate;
    currentLoadKey = loadKey;
    body.innerHTML = emptyHtml('상세 내역을 불러오는 중입니다.');
    var joiner = detailEndpoint.indexOf('?') >= 0 ? '&' : '?';
    var url = detailEndpoint + joiner
      + 'project_id=' + encodeURIComponent(currentProjectId)
      + '&ym=' + encodeURIComponent(currentYm)
      + '&snapshot_date=' + encodeURIComponent(currentSnapshotDate || '');
    var xhr = new XMLHttpRequest();
    xhr.open('GET', url, true);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.onreadystatechange = function() {
      if (xhr.readyState !== 4 || loadKey !== currentLoadKey) return;
      if (xhr.status < 200 || xhr.status >= 300) {
        body.innerHTML = emptyHtml('상세 내역을 불러오지 못했습니다. 잠시 후 다시 시도해 주세요.');
        return;
      }
      var response = null;
      try { response = JSON.parse(xhr.responseText); } catch (e) { response = null; }
      if (!response || !response.ok || !response.month) {
        body.innerHTML = emptyHtml(response && response.message ? response.message : '상세 데이터를 찾을 수 없습니다.');
        return;
      }
      if (!detailData[currentProjectId]) detailData[currentProjectId] = {project_name: response.project_name || '', months:{}};
      if (!detailData[currentProjectId].months) detailData[currentProjectId].months = {};
      if (response.project_name) detailData[currentProjectId].project_name = response.project_name;
      detailData[currentProjectId].months[currentYm] = response.month;
      callback();
    };
    xhr.send(null);
  }

  function currentMonthData() {
    var project = detailData && detailData[currentProjectId] ? detailData[currentProjectId] : null;
    if (!project || !project.months) return null;
    return project.months[currentYm] || null;
  }

  function setActiveOutsourcingTab(tabName) {
    currentOutsourcingTab = tabName === 'manual_outsourcing' ? 'manual_outsourcing' : 'labor_outsourcing';
    var tabButtons = tabs ? tabs.querySelectorAll('[data-outsourcing-tab]') : [];
    for (var i = 0; i < tabButtons.length; i++) {
      var active = tabButtons[i].getAttribute('data-outsourcing-tab') === currentOutsourcingTab;
      if (active) tabButtons[i].classList.add('is-active');
      else tabButtons[i].classList.remove('is-active');
    }
    renderCurrent();
  }

  function renderCurrent() {
    var project = detailData && detailData[currentProjectId] ? detailData[currentProjectId] : null;
    var monthData = currentMonthData();
    if (!project || !monthData) {
      body.innerHTML = emptyHtml('상세 데이터를 찾을 수 없습니다.');
      return;
    }

    var rows = [];
    var columns = [];
    var label = '';
    var totalValue = 0;
    var combinedOutsourcingTotal = monthData.totals ? monthData.totals.outsourcing : 0;

    if (currentType === 'labor') {
      label = '노무비';
      rows = filterRows(monthData.labor || [], 'labor');
      totalValue = sumRows(rows);
      columns = [
        {key:'name', label:'성명'},
        {key:'job_type', label:'직종'},
        {key:'company_name', label:'업체명'},
        {key:'ratio_label', label:'노무비 비율', align:'center'},
        {key:'output_days', label:'출력일수', format:'count', align:'right'},
        {key:'total_gongsu', label:'총공수', format:'count', align:'right'},
        {key:'wage_rate', label:'단가', format:'money', align:'right'},
        {key:'amount', label:'지급총액', format:'money', align:'right'},
        {key:'remark', label:'비고', remark:true}
      ];
    } else if (currentType === 'equipment') {
      label = '장비비';
      rows = filterRows(monthData.equipment || [], 'equipment');
      totalValue = sumRows(rows);
      columns = [
        {key:'use_date_label', label:'사용일자', align:'center'},
        {key:'category', label:'구분'},
        {key:'vendor_name', label:'업체명'},
        {key:'total_work_unit', label:'총장비공수', format:'count', align:'right'},
        {key:'base_rate', label:'단가', format:'money', align:'right'},
        {key:'amount', label:'총장비비', format:'money', align:'right'},
        {key:'remark', label:'비고', remark:true},
        {key:'files', label:'증빙파일', format:'files'}
      ];
    } else if (currentType === 'material') {
      label = currentCategory ? currentCategory : '자재구입비';
      rows = filterRows(monthData.material || [], 'material');
      totalValue = sumRows(rows);
      columns = [
        {key:'use_date', label:'일자', align:'center'},
        {key:'category', label:'구분'},
        {key:'vendor_name', label:'업체명'},
        {key:'amount', label:'공급가액', format:'money', align:'right'},
        {key:'remark', label:'비고', remark:true},
        {key:'files', label:'증빙파일', format:'files'}
      ];
    } else if (currentType === 'safety') {
      label = '안전관리비';
      rows = filterRows(monthData.safety || [], 'safety');
      totalValue = sumRows(rows);
      columns = [
        {key:'use_date', label:'사용일자', align:'center'},
        {key:'category', label:'구분'},
        {key:'vendor_name', label:'업체명'},
        {key:'item_name', label:'품목·사용내용'},
        {key:'amount', label:'공급가액', format:'money', align:'right'},
        {key:'remark', label:'비고', remark:true},
        {key:'files', label:'명세표', format:'files'}
      ];
    } else if (currentType === 'outsourcing') {
      if (currentOutsourcingTab === 'manual_outsourcing') {
        label = '일반 외주비';
        rows = filterRows(monthData.manual_outsourcing || [], 'manual_outsourcing');
        totalValue = sumRows(rows);
        columns = [
          {key:'expense_date', label:'일자', align:'center'},
          {key:'category', label:'구분'},
          {key:'company_name', label:'업체명'},
          {key:'amount', label:'금액', format:'money', align:'right'},
          {key:'memo', label:'비고', remark:true},
          {key:'files', label:'첨부파일', format:'files'}
        ];
      } else {
        label = '노무성 외주비';
        rows = filterRows(monthData.labor_outsourcing || [], 'labor_outsourcing');
        totalValue = sumRows(rows);
        columns = [
          {key:'name', label:'성명'},
          {key:'company_name', label:'업체명'},
          {key:'ratio_label', label:'외주비 비율', align:'center'},
          {key:'output_days', label:'출력일수', format:'count', align:'right'},
          {key:'total_gongsu', label:'총공수', format:'count', align:'right'},
          {key:'wage_rate', label:'단가', format:'money', align:'right'},
          {key:'amount', label:'지급총액', format:'money', align:'right'},
          {key:'remark', label:'비고', remark:true}
        ];
      }
    }

    var change = monthData.change || {};
    var changeKey = currentType === 'material' ? 'material' : (currentType === 'outsourcing' ? 'outsourcing' : currentType);
    var changeDelta = change.deltas && typeof change.deltas[changeKey] !== 'undefined' ? parseFloat(change.deltas[changeKey]) : 0;
    if (isNaN(changeDelta)) changeDelta = 0;

    var titleParts = [project.project_name || '', label];
    if (currentCompany) titleParts.push(currentCompany);
    title.textContent = titleParts.join(' · ');
    var metaText = String(currentYm || '').replace('-', '.') + ' 기준';
    if (change.snapshot_date) metaText += ' · 스냅샷 ' + String(change.snapshot_date).replace(/-/g, '.');
    if (change.previous_date) metaText += ' · 비교 ' + String(change.previous_date).replace(/-/g, '.');
    if (currentRowLabel && currentRowLabel !== label) metaText += ' · ' + currentRowLabel;
    meta.textContent = metaText;
    var deltaText = formatSignedMoney(changeDelta);
    if (currentType === 'outsourcing') {
      total.textContent = '현재 탭 소계 ' + formatMoney(totalValue) + '원 · 외주비 전체 ' + formatMoney(combinedOutsourcingTotal) + '원' + (deltaText ? ' · 전일 대비 ' + deltaText : '');
    } else {
      total.textContent = '상세 합계 ' + formatMoney(totalValue) + '원' + (deltaText ? ' · 전일 대비 ' + deltaText : '');
    }
    body.innerHTML = tableHtml(columns, rows);
  }

  function openModal(trigger) {
    if (!trigger) return;
    if (desktopOnly && window.matchMedia && window.matchMedia('(max-width: 767px)').matches) return;
    currentProjectId = String(trigger.getAttribute('data-project-id') || '');
    currentYm = String(trigger.getAttribute('data-ym') || '');
    currentType = String(trigger.getAttribute('data-detail-type') || '');
    currentCompany = String(trigger.getAttribute('data-company') || '');
    currentCategory = String(trigger.getAttribute('data-category') || '');
    currentRowLabel = String(trigger.getAttribute('data-row-label') || '');
    currentSnapshotDate = String(trigger.getAttribute('data-snapshot-date') || '');
    currentOutsourcingTab = 'labor_outsourcing';
    if (tabs) {
      if (currentType === 'outsourcing') tabs.classList.add('is-visible');
      else tabs.classList.remove('is-visible');
      var tabButtons = tabs.querySelectorAll('[data-outsourcing-tab]');
      for (var i = 0; i < tabButtons.length; i++) {
        if (tabButtons[i].getAttribute('data-outsourcing-tab') === 'labor_outsourcing') tabButtons[i].classList.add('is-active');
        else tabButtons[i].classList.remove('is-active');
      }
    }
    if (modal) {
      modal.classList.add('is-open');
      modal.setAttribute('aria-hidden', 'false');
      document.body.classList.add('cpms-cost-detail-open');
    }
    ensureCurrentData(renderCurrent);
  }

  function closeModal() {
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('cpms-cost-detail-open');
  }

  document.addEventListener('click', function(event) {
    var target = event.target;
    var trigger = null;
    if (target && target.closest) {
      try { trigger = target.closest(triggerSelector); } catch (e) { trigger = target.closest('.cpms-monthly-detail-trigger'); }
    }
    if (trigger) {
      event.preventDefault();
      openModal(trigger);
      return;
    }
    var closeButton = target && target.closest ? target.closest('[data-cost-detail-close]') : null;
    if (closeButton) {
      event.preventDefault();
      closeModal();
      return;
    }
    var tabButton = target && target.closest ? target.closest('[data-outsourcing-tab]') : null;
    if (tabButton) {
      event.preventDefault();
      setActiveOutsourcingTab(tabButton.getAttribute('data-outsourcing-tab'));
      return;
    }
    if (target === modal) closeModal();
  });

  document.addEventListener('keydown', function(event) {
    if ((event.key === 'Escape' || event.keyCode === 27) && modal && modal.classList.contains('is-open')) closeModal();
  });
})();
</script>
