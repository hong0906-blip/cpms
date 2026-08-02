<?php
/**
 * 파일: app/views/construction/tabs/monthly_input.php
 * 공사 > 투입비 상세
 *
 * 수정 내용
 * 1) 선택한 월까지만 누적 합계를 표시합니다.
 *    예: 7월 조회 시 8월 금액은 누적 합계에서 제외합니다.
 * 2) 노무비 기본 업체명을 '-'가 아니라 '창명건설'로 표시합니다.
 *
 * PHP 5.6 호환
 */

$cpmsMonthlyInputRoute = '공사';
$cpmsMonthlyInputTab = 'monthly_input';
$cpmsMonthlyInputSelectedProjectId = isset($pid) ? (int)$pid : 0;
$cpmsMonthlyInputShowProjectFilter = false;

/*
 * 공통 투입비 상세 화면을 먼저 실행합니다.
 * require로 실행한 파일의 계산 변수는 현재 파일 범위에 그대로 남기 때문에,
 * 화면 출력 후 선택 월 기준 누적값을 정확하게 다시 반영할 수 있습니다.
 */
ob_start();
require __DIR__ . '/../../project/monthly_input.php';
$cpmsMonthlyInputHtml = ob_get_clean();

require_once __DIR__ . '/../../project/partials/monthly_cost_detail_helper.php';
$cpmsMonthlyInputDetailMonths = isset($displayMonths) && is_array($displayMonths) ? array_values($displayMonths) : array();
$cpmsMonthlyInputProjectName = is_array($selectedProject) && isset($selectedProject['name']) ? trim((string)$selectedProject['name']) : '';
$cpmsMonthlyCostDetailPayload = cpms_monthly_cost_detail_payload_for_project(
    $pdo,
    $cpmsMonthlyInputSelectedProjectId,
    $cpmsMonthlyInputProjectName,
    $cpmsMonthlyInputDetailMonths
);
$cpmsMonthlyInputDetailMonthsJson = json_encode($cpmsMonthlyInputDetailMonths, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($cpmsMonthlyInputDetailMonthsJson) || $cpmsMonthlyInputDetailMonthsJson === '') $cpmsMonthlyInputDetailMonthsJson = '[]';

$cpmsSelectedViewMonth = isset($selectedViewMonth) ? trim((string)$selectedViewMonth) : '';
$cpmsAllMonths = isset($allMonths) && is_array($allMonths) ? $allMonths : array();
$cpmsCumulativeMonths = array();

if ($cpmsSelectedViewMonth === 'all' || $cpmsSelectedViewMonth === '') {
    $cpmsCumulativeMonths = $cpmsAllMonths;
} else {
    foreach ($cpmsAllMonths as $cpmsMonthValue) {
        $cpmsMonthValue = trim((string)$cpmsMonthValue);
        if ($cpmsMonthValue !== '' && $cpmsMonthValue <= $cpmsSelectedViewMonth) {
            $cpmsCumulativeMonths[] = $cpmsMonthValue;
        }
    }
}

if (count($cpmsCumulativeMonths) === 0) {
    $cpmsCumulativeMonths = isset($displayMonths) && is_array($displayMonths)
        ? $displayMonths
        : $cpmsAllMonths;
}

/* 노무비 기본 행은 실제로 창명건설 금액이므로 업체명도 창명건설로 표시합니다. */
$cpmsMonthlyInputHtml = preg_replace(
    '/(<td class="border p-2"><\/td><td class="border p-2">)-(<\/td><td class="border p-2">노무비 합계<\/td>)/u',
    '$1창명건설$2',
    $cpmsMonthlyInputHtml
);

/* 누적 합계 안내 문구도 선택 월 기준으로 변경합니다. */
if ($cpmsSelectedViewMonth !== 'all' && preg_match('/^\d{4}-\d{2}$/', $cpmsSelectedViewMonth)) {
    $cpmsCumulativeLabel = str_replace('-', '.', $cpmsSelectedViewMonth) . '까지 누적 합계';
    $cpmsMonthlyInputHtml = str_replace('프로젝트 계약기간 전체 합계', $cpmsCumulativeLabel, $cpmsMonthlyInputHtml);
}

$cpmsSumMonths = function ($monthMap) use ($cpmsCumulativeMonths) {
    $sum = 0.0;
    if (!is_array($monthMap)) return $sum;
    foreach ($cpmsCumulativeMonths as $ym) {
        $sum += isset($monthMap[$ym]) ? (float)$monthMap[$ym] : 0.0;
    }
    return $sum;
};

$cpmsCumulativePayload = array(
    'revenue' => $cpmsSumMonths(isset($monthlyRevenue) ? $monthlyRevenue : array()),
    'sections' => array(),
    'section_subtotals' => array(),
    'subtotal1' => $cpmsSumMonths(isset($subtotal1) ? $subtotal1 : array()),
    'final_total' => $cpmsSumMonths(isset($finalTotal) ? $finalTotal : array()),
    'profit' => $cpmsSumMonths(isset($profit) ? $profit : array()),
);

$cpmsLabels = isset($labels) && is_array($labels) ? $labels : array();
$cpmsRowsBySection = isset($rowsBySection) && is_array($rowsBySection) ? $rowsBySection : array();
$cpmsSumBySection = isset($sumBySection) && is_array($sumBySection) ? $sumBySection : array();

foreach ($cpmsLabels as $cpmsSectionKey => $cpmsSectionTitle) {
    $cpmsSectionTitle = (string)$cpmsSectionTitle;
    $cpmsCumulativePayload['sections'][$cpmsSectionTitle] = array();

    $cpmsSectionRows = isset($cpmsRowsBySection[$cpmsSectionKey]) && is_array($cpmsRowsBySection[$cpmsSectionKey])
        ? $cpmsRowsBySection[$cpmsSectionKey]
        : array();

    foreach ($cpmsSectionRows as $cpmsSectionRow) {
        $cpmsRowMonths = isset($cpmsSectionRow['months']) && is_array($cpmsSectionRow['months'])
            ? $cpmsSectionRow['months']
            : array();
        $cpmsCumulativePayload['sections'][$cpmsSectionTitle][] = $cpmsSumMonths($cpmsRowMonths);
    }

    $cpmsSectionMonthMap = isset($cpmsSumBySection[$cpmsSectionKey]) && is_array($cpmsSumBySection[$cpmsSectionKey])
        ? $cpmsSumBySection[$cpmsSectionKey]
        : array();
    $cpmsCumulativePayload['section_subtotals'][$cpmsSectionTitle] = $cpmsSumMonths($cpmsSectionMonthMap);
}

echo $cpmsMonthlyInputHtml;
?>
<script>
(function () {
    'use strict';

    var payload = <?php echo json_encode($cpmsCumulativePayload, JSON_UNESCAPED_UNICODE); ?>;
    var wrap = document.querySelector('.cpms-monthly-table-scroll');
    var table = wrap ? wrap.querySelector('table') : null;
    if (!table || !table.tBodies || !table.tBodies.length) return;

    function trimText(value) {
        return String(value || '').replace(/^\s+|\s+$/g, '');
    }

    function formatMoney(value) {
        var number = parseFloat(value);
        if (isNaN(number) || Math.abs(number) < 0.0001) return '-';
        var rounded = Math.round(number);
        return String(rounded).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    function setLastCell(row, value) {
        if (!row || !row.cells || !row.cells.length) return;
        row.cells[row.cells.length - 1].textContent = formatMoney(value);
    }

    var rows = table.tBodies[0].rows;
    var currentSection = '';
    var sectionIndexes = {};

    for (var i = 0; i < rows.length; i++) {
        var row = rows[i];
        if (!row.cells || !row.cells.length) continue;

        var firstText = trimText(row.cells[0].textContent);
        var secondText = row.cells.length > 1 ? trimText(row.cells[1].textContent) : '';

        if (firstText === '매출금액(기성관리 인정금액 우선)') {
            setLastCell(row, payload.revenue);
            continue;
        }

        if (payload.sections && Object.prototype.hasOwnProperty.call(payload.sections, firstText)) {
            currentSection = firstText;
            sectionIndexes[currentSection] = 0;
            continue;
        }

        if (currentSection && firstText === currentSection + ' 소계') {
            setLastCell(row, payload.section_subtotals[currentSection] || 0);
            currentSection = '';
            continue;
        }

        if (firstText === '1차 합계') {
            setLastCell(row, payload.subtotal1);
            continue;
        }

        if (firstText === '최종 합계') {
            setLastCell(row, payload.final_total);
            continue;
        }

        if (firstText === '손익') {
            setLastCell(row, payload.profit);
            continue;
        }

        if (currentSection && firstText === '') {
            if (secondText === '데이터 없음') continue;

            var values = payload.sections[currentSection] || [];
            var index = sectionIndexes[currentSection] || 0;
            if (index < values.length) {
                setLastCell(row, values[index]);
                sectionIndexes[currentSection] = index + 1;
            }
        }
    }
})();
</script>

<script>
(function () {
    'use strict';

    var projectId = <?php echo (int)$cpmsMonthlyInputSelectedProjectId; ?>;
    var displayMonths = <?php echo $cpmsMonthlyInputDetailMonthsJson; ?>;
    var tableWrap = document.querySelector('.cpms-monthly-table-scroll');
    var table = tableWrap ? tableWrap.querySelector('table') : null;
    if (!table || !table.tBodies || !table.tBodies.length || projectId <= 0) return;

    var sectionMap = {
        '1. 외주비': {type:'outsourcing', category:''},
        '2. 구매품': {type:'material', category:'구매품'},
        '3. 자재비': {type:'material', category:'자재비'},
        '4. 장비비': {type:'equipment', category:''},
        '5. 노무비': {type:'labor', category:''},
        '6. 기타경비': {type:'material', category:'기타경비'}
    };

    function trimText(value) {
        return String(value || '').replace(/^\s+|\s+$/g, '');
    }

    function hasAmount(value) {
        value = trimText(value);
        if (value === '' || value === '-' || value === '0') return false;
        return /[0-9]/.test(value);
    }

    function makeButton(cell, config, ym, company, rowLabel) {
        if (!cell || !hasAmount(cell.textContent)) return;
        var originalText = trimText(cell.textContent);
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'cpms-monthly-input-detail-trigger';
        button.textContent = originalText;
        button.setAttribute('data-project-id', String(projectId));
        button.setAttribute('data-ym', String(ym || ''));
        button.setAttribute('data-detail-type', config.type);
        button.setAttribute('data-company', company || '');
        button.setAttribute('data-category', config.category || '');
        button.setAttribute('data-row-label', rowLabel || '');
        button.setAttribute('aria-label', (rowLabel || config.type) + ' 상세 보기');
        cell.textContent = '';
        cell.appendChild(button);
    }

    var rows = table.tBodies[0].rows;
    var currentSectionTitle = '';
    var currentConfig = null;

    for (var i = 0; i < rows.length; i++) {
        var row = rows[i];
        if (!row.cells || row.cells.length < 4) continue;
        var firstText = trimText(row.cells[0].textContent);
        var secondText = row.cells.length > 1 ? trimText(row.cells[1].textContent) : '';
        var thirdText = row.cells.length > 2 ? trimText(row.cells[2].textContent) : '';

        if (Object.prototype.hasOwnProperty.call(sectionMap, firstText)) {
            currentSectionTitle = firstText;
            currentConfig = sectionMap[firstText];
            continue;
        }

        if (currentSectionTitle && firstText === currentSectionTitle + ' 소계') {
            currentSectionTitle = '';
            currentConfig = null;
            continue;
        }

        if (!currentConfig || firstText !== '' || secondText === '데이터 없음') continue;

        for (var monthIndex = 0; monthIndex < displayMonths.length; monthIndex++) {
            var cellIndex = 3 + monthIndex;
            if (cellIndex >= row.cells.length - 1) break;
            makeButton(row.cells[cellIndex], currentConfig, displayMonths[monthIndex], secondText, thirdText);
        }
    }
})();
</script>
<?php
$cpmsMonthlyCostDetailTriggerSelector = '.cpms-monthly-input-detail-trigger';
$cpmsMonthlyCostDetailDesktopOnly = true;
require __DIR__ . '/../../project/partials/monthly_cost_detail_modal.php';
?>
