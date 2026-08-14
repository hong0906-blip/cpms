<?php
/**
 * 관리 > 투입비 상세
 * - 공사 섹션의 투입비 상세 계산과 상세 명세를 그대로 사용합니다.
 * - 관리 화면에서만 현장 선택, 계좌정보 표시, 총합계 열 제외를 적용합니다.
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
