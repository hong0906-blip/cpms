<?php
/**
 * 공사 > 투입비 상세
 * - 공무에 있던 월별 투입비 상세내역 화면을 공사 탭에서 재사용
 * - PHP 5.6 호환
 */

$cpmsMonthlyInputRoute = '공사';
$cpmsMonthlyInputTab = 'monthly_input';
$cpmsMonthlyInputSelectedProjectId = isset($pid) ? (int)$pid : 0;
$cpmsMonthlyInputShowProjectFilter = false;

require __DIR__ . '/../../project/monthly_input.php';
