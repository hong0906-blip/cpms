<?php
/**
 * 파일: app/views/admin/monthly_input.php
 * 관리 > 투입비 상세
 * - 공사 섹션의 투입비 상세 계산과 상세 명세를 그대로 사용합니다.
 * - 관리 화면에서만 현장 선택, 계좌정보 표시, 총합계 열 제외를 적용합니다.
 * - 현장 선택 목록에서 계약 종료일이 지난 현장은 "(계약종료)"를 표시합니다.
 * - 계약 종료일이 연장되어 오늘 이후 날짜로 수정되면 표시는 자동으로 사라집니다.
 * - PHP 5.6 호환
 */

$cpmsMonthlyInputRoute = '관리';
$cpmsMonthlyInputTab = 'monthly_input';
$cpmsMonthlyInputSelectedProjectId = isset($_GET['pid']) ? (int)$_GET['pid'] : 0;
$cpmsMonthlyInputShowProjectFilter = true;
$cpmsMonthlyInputProjectFilterLabel = '현장 선택 :';
$cpmsMonthlyInputManagementMode = true;
$cpmsMonthlyInputIncludeBankInfo = true;
$cpmsMonthlyInputShowTotalColumn = false;
$cpmsMonthlyInputShowRevenueRow = false;
$cpmsMonthlyInputSingleMonthOnly = true;
$cpmsMonthlyInputHideEmptySections = true;
$cpmsMonthlyInputCompactTable = true;
$cpmsMonthlyInputShowDeductionEntry = false;
$cpmsMonthlyInputShowProfitRow = false;

require __DIR__ . '/../construction/tabs/monthly_input.php';

/*
 * 관리 > 투입비 상세 전용 현장 계약종료 표시
 *
 * 공통 투입비 화면에서 불러온 $monthlyProjects의 end_date를 사용합니다.
 * 오늘이 계약 종료일과 같은 날이면 아직 계약기간으로 보고 표시하지 않습니다.
 * 오늘이 계약 종료일보다 지난 경우에만 "(계약종료)"를 붙입니다.
 * 계약기간이 연장되어 end_date가 미래 날짜로 수정되면 다음 화면 조회부터 자동으로 제거됩니다.
 */
$cpmsMonthlyInputEndedProjectIds = array();
$cpmsMonthlyInputToday = date('Y-m-d');

if (isset($monthlyProjects) && is_array($monthlyProjects)) {
    foreach ($monthlyProjects as $cpmsMonthlyInputProject) {
        $cpmsMonthlyInputProjectId = isset($cpmsMonthlyInputProject['id']) ? (int)$cpmsMonthlyInputProject['id'] : 0;
        $cpmsMonthlyInputEndDate = isset($cpmsMonthlyInputProject['end_date']) ? trim((string)$cpmsMonthlyInputProject['end_date']) : '';

        if ($cpmsMonthlyInputProjectId <= 0 || $cpmsMonthlyInputEndDate === '') {
            continue;
        }

        /* DATETIME 값이 들어와도 앞의 YYYY-MM-DD 부분만 사용합니다. */
        $cpmsMonthlyInputEndDate = substr($cpmsMonthlyInputEndDate, 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $cpmsMonthlyInputEndDate)) {
            continue;
        }

        if (strcmp($cpmsMonthlyInputEndDate, $cpmsMonthlyInputToday) < 0) {
            $cpmsMonthlyInputEndedProjectIds[] = $cpmsMonthlyInputProjectId;
        }
    }
}
?>
<?php if (count($cpmsMonthlyInputEndedProjectIds) > 0): ?>
<script>
(function () {
    /* 관리 > 투입비 상세의 현장 선택 목록에만 계약종료 문구를 붙입니다. */
    var endedProjectIds = <?php echo json_encode($cpmsMonthlyInputEndedProjectIds); ?>;
    var select = document.querySelector('select[name="pid"]');
    var endedMap = {};
    var i;

    if (!select || !endedProjectIds || !endedProjectIds.length) {
        return;
    }

    for (i = 0; i < endedProjectIds.length; i++) {
        endedMap[String(endedProjectIds[i])] = true;
    }

    for (i = 0; i < select.options.length; i++) {
        var option = select.options[i];
        var optionValue = String(option.value || '');
        var optionText = option.text || '';

        if (endedMap[optionValue] && optionText.indexOf(' (계약종료)') === -1) {
            option.text = optionText + ' (계약종료)';
        }
    }
}());
</script>
<?php endif; ?>
