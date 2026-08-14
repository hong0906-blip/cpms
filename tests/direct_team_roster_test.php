<?php
/**
 * 직영팀 명부/월급 배분 회귀 검사.
 * PHP 5.6 호환, DB 독립 실행.
 */

$root = dirname(__DIR__);
$failures = array();
$checks = 0;

function cpms_direct_team_guard($label, $condition)
{
    global $failures, $checks;
    $checks++;
    if (!$condition) $failures[] = $label;
}

$adminIndex = file_get_contents($root . '/app/views/admin/index.php');
$directView = file_get_contents($root . '/app/views/admin/direct_team.php');
$directSave = file_get_contents($root . '/app/views/admin/direct_team_save.php');
$conversionService = file_get_contents($root . '/app/services/DirectTeamConversionService.php');
$legacyConversionScript = file_get_contents($root . '/scripts/convert_legacy_direct_team_to_daily.php');
$laborView = file_get_contents($root . '/app/views/construction/tabs/labor.php');
$laborAdd = file_get_contents($root . '/app/views/construction/labor_worker_add.php');
$laborSave = file_get_contents($root . '/app/views/construction/labor_workers_save.php');
$loader = file_get_contents($root . '/app/views/construction/tabs/partials/labor_data_loader.php');
$employeeDirectory = file_get_contents($root . '/app/views/employees/directory.php');

cpms_direct_team_guard('admin exposes separate employee and direct-team tabs',
    strpos($adminIndex, "'employees' => array('label' => '임직원 명부'") !== false
    && strpos($adminIndex, "'direct_team' => array('label' => '직영팀 명부'") !== false
);
cpms_direct_team_guard('direct roster contains requested columns and masked sensitive toggle',
    strpos($directView, '>주민번호<') !== false
    && strpos($directView, '>계좌번호<') !== false
    && strpos($directView, '>월급<') !== false
    && strpos($directView, 'data-sensitive-toggle') !== false
    && strpos($directView, '>***<') !== false
);
cpms_direct_team_guard('salary input uses comma formatting and server strips separators',
    strpos($directView, 'data-money-input') !== false
    && strpos($directView, "replace(/[^0-9]/g, '')") !== false
    && strpos($directSave, "preg_replace('/[^0-9]/', '', (string)\$_POST['monthly_salary'])") !== false
);
cpms_direct_team_guard('labor personnel tab adds active direct-team member by dropdown',
    strpos($laborView, 'name="direct_member_id"') !== false
    && strpos($laborView, '직영팀 이름 선택') !== false
    && strpos($laborAdd, 'WHERE id = :id AND is_active = 1') !== false
);
cpms_direct_team_guard('retired direct-team workers are blocked from previous-month import',
    strpos($laborView, '퇴직 · 추가 불가') !== false
    && strpos($laborSave, '퇴직한 직영팀 인원은 노무비에 가져올 수 없습니다.') !== false
);
cpms_direct_team_guard('monthly salary is divided by all-project output days',
    strpos($loader, 'function cpms_direct_team_salary_allocations') !== false
    && strpos($loader, "'total_output_days'=>0") !== false
    && strpos($loader, '$monthlySalary <= 0') !== false
    && strpos($loader, '((float)$salary / (float)$days)') !== false
    && strpos($loader, '$billingUnits = $isMonthlySalary ? $totalOutputDays : $totalGongsu;') !== false
);
cpms_direct_team_guard('direct-team salary is forced into labor rather than outsourcing',
    strpos($loader, '$merged[\'outsourcing_ratio\'] = 0;') !== false
    && strpos($laborSave, '$isDirectSalaryWorker') !== false
);
cpms_direct_team_guard('monthly salary is required and must be positive',
    strpos($directView, 'name="monthly_salary" data-money-input placeholder="3,000,000" required') !== false
    && strpos($directSave, '$monthlySalary <= 0') !== false
);
cpms_direct_team_guard('used direct-team members convert to daily workers while preserving rates',
    strpos($directSave, 'new DirectTeamConversionService') !== false
    && strpos($directSave, '기존 현장별 단가와 노무비 이력을 유지한 일용직으로 전환') !== false
    && strpos($conversionService, 'direct_member_id = NULL') !== false
    && strpos($conversionService, "source_type = 'direct_team_converted'") !== false
    && strpos($conversionService, "isset(\$laborRow['daily_wage_snapshot'])") !== false
    && strpos($conversionService, "isset(\$laborRow['deposit_rate'])") !== false
    && strpos($directSave, '노무비 이력이 있는 직영팀 인원은 삭제할 수 없습니다.') === false
);
cpms_direct_team_guard('legacy five-person conversion script is present and idempotent',
    strpos($legacyConversionScript, "'강구열'") !== false
    && strpos($legacyConversionScript, "'고경준'") !== false
    && strpos($legacyConversionScript, "'신대선'") !== false
    && strpos($legacyConversionScript, "'오만성'") !== false
    && strpos($legacyConversionScript, "'한재규'") !== false
    && strpos($legacyConversionScript, 'convertAndDelete') !== false
);
cpms_direct_team_guard('employee directory places active direct team below assistant managers',
    strpos($employeeDirectory, "FROM direct_team_members") !== false
    && strpos($employeeDirectory, "WHERE is_active = 1") !== false
    && strpos($employeeDirectory, "(string)\$rank === '주임'") !== false
    && strpos($employeeDirectory, "array('label' => '직영팀', 'rows' => \$directTeamRows)") !== false
);
cpms_direct_team_guard('direct-team directory cards omit employee number location and email',
    substr_count($employeeDirectory, 'if (!$isDirectTeamCard)') >= 2
    && strpos($employeeDirectory, "'employee_no'] = ''") !== false
    && strpos($employeeDirectory, "'work_location'] = ''") !== false
    && strpos($employeeDirectory, "'email'] = ''") !== false
);

if (count($failures) > 0) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . ' / ' . $checks . "\n");
    foreach ($failures as $failure) fwrite(STDERR, '- ' . $failure . "\n");
    exit(1);
}

echo 'PASS: ' . $checks . " direct-team roster guards\n";
