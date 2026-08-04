<?php
/**
 * 예측형 AI 데이터 구조의 원천 분류, 마감 경계, 정확도 및 보존 SQL 회귀 테스트.
 * PHP 5.6 CLI에서 외부 테스트 프레임워크 없이 실행할 수 있다.
 */

require_once dirname(__DIR__) . '/app/services/AiCostDataGovernanceService.php';
require_once dirname(__DIR__) . '/app/services/AiForecastAccuracyService.php';
require_once dirname(__DIR__) . '/app/services/UsageAnalyticsService.php';
require_once dirname(__DIR__) . '/app/services/AiMemoryService.php';

use App\Services\AiCostDataGovernanceService;
use App\Services\AiCostForecastV2Service;
use App\Services\AiForecastAccuracyService;
use App\Services\AiProjectTypeService;
use App\Services\UsageAnalyticsService;
use App\Services\AiMemoryService;
use App\Services\CostChangeService;

date_default_timezone_set('Asia/Seoul');

$failures = array();
$checks = 0;

function cpms_ai_structure_same($label, $expected, $actual)
{
    global $failures, $checks;
    $checks++;
    if ($expected !== $actual) {
        $failures[] = $label . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true);
    }
}

function cpms_ai_structure_true($label, $actual)
{
    cpms_ai_structure_same($label, true, (bool)$actual);
}

cpms_ai_structure_same('June direct is historical', 'HISTORICAL_MIGRATION', AiCostDataGovernanceService::classifyOrigin('DIRECT', 'CREATE', '2026-06-30 23:59:59', array()));
cpms_ai_structure_same('July direct is live', 'LIVE_EMPLOYEE_INPUT', AiCostDataGovernanceService::classifyOrigin('DIRECT', 'CREATE', '2026-07-01 00:00:00', array()));
cpms_ai_structure_same('Excel is system import', 'SYSTEM_IMPORT', AiCostDataGovernanceService::classifyOrigin('EXCEL', 'CREATE', '2026-08-01 09:00:00', array()));
cpms_ai_structure_same('Manual historical supplement is backfill', 'MANUAL_BACKFILL', AiCostDataGovernanceService::classifyOrigin('MANUAL_BACKFILL', 'CREATE', '2026-08-01 09:00:00', array()));
cpms_ai_structure_same('Re-entry is explicit', 'RE_ENTRY', AiCostDataGovernanceService::classifyOrigin('DIRECT', 'RE_ENTRY', '2026-08-01 09:00:00', array()));
cpms_ai_structure_same('Unknown path needs review', 'UNKNOWN_REVIEW', AiCostDataGovernanceService::classifyOrigin('LEGACY_JOB', 'CREATE', '2026-08-01 09:00:00', array()));
cpms_ai_structure_same('July alone does not prove live origin', 'UNKNOWN_REVIEW', AiCostDataGovernanceService::classifyOrigin('SYSTEM', 'CREATE', '2026-08-01 09:00:00', array()));
cpms_ai_structure_same('Unverified direct input needs review', 'UNKNOWN_REVIEW', AiCostDataGovernanceService::classifyOrigin('DIRECT', 'CREATE', '2026-08-01 09:00:00', array('employee_input_verified'=>false)));
cpms_ai_structure_same('Late old-month direct entry needs review', 'UNKNOWN_REVIEW', AiCostDataGovernanceService::classifyOrigin('DIRECT', 'CREATE', '2026-08-01 09:00:00', array('settlement_ym'=>'2026-06')));

$historicalEligibility = AiCostDataGovernanceService::defaultEligibility('HISTORICAL_MIGRATION', false, false);
$liveEligibility = AiCostDataGovernanceService::defaultEligibility('LIVE_EMPLOYEE_INPUT', false, false);
cpms_ai_structure_same('Historical amount usable', 1, $historicalEligibility['amount']);
cpms_ai_structure_same('Historical timing excluded', 0, $historicalEligibility['timing']);
cpms_ai_structure_same('Live timing usable', 1, $liveEligibility['timing']);
cpms_ai_structure_same('Admin correction requires reason', true, AiCostDataGovernanceService::requiresChangeReason('ADMIN_CORRECTION'));
cpms_ai_structure_same('June finalized amount group is usable by default', true, AiCostDataGovernanceService::amountGroupEligible(false, 1, '2026-06', 'material'));

cpms_ai_structure_same('Labor remains in calendar month', '2026-07', CostChangeService::settlementYm('labor', '2026-07-26'));
cpms_ai_structure_same('Non-labor through 25th remains in month', '2026-07', CostChangeService::settlementYm('material', '2026-07-25'));
cpms_ai_structure_same('Non-labor from 26th belongs to next month', '2026-08', CostChangeService::settlementYm('material', '2026-07-26'));

cpms_ai_structure_same('Labor closes at month end', '2024-02-29', AiForecastAccuracyService::closeDate('2024-02', 'labor'));
cpms_ai_structure_same('Non-labor closes on 25th', '2026-08-25', AiForecastAccuracyService::closeDate('2026-08', 'material'));

$over = AiForecastAccuracyService::metrics(120, 90, 110, 100);
cpms_ai_structure_same('Signed error forecast minus actual', 20.0, $over['signed_error']);
cpms_ai_structure_same('Absolute error', 20.0, $over['absolute_error']);
cpms_ai_structure_same('APE decimal', 0.2, $over['absolute_percentage_error']);
cpms_ai_structure_same('Over direction', 'OVER', $over['error_direction']);
cpms_ai_structure_same('Outside range', 0, $over['in_expected_range']);
$zero = AiForecastAccuracyService::metrics(0, 0, 0, 0);
cpms_ai_structure_same('Actual zero has null APE', null, $zero['absolute_percentage_error']);
cpms_ai_structure_same('Exact direction', 'EXACT', $zero['error_direction']);

$tables = AiCostForecastV2Service::requiredTables();
$resultSql = $tables[AiCostForecastV2Service::RESULT_TABLE];
$categorySql = $tables[AiCostForecastV2Service::CATEGORY_TABLE];
cpms_ai_structure_true('Result uniqueness is run scoped', strpos($resultSql, '(run_id,project_id)') !== false);
cpms_ai_structure_true('Category uniqueness is run scoped', strpos($categorySql, '(run_id,project_id,cost_type)') !== false);
cpms_ai_structure_same('Result does not overwrite by day', false, strpos($resultSql, '(analysis_date,target_ym,project_id)') !== false);
cpms_ai_structure_same('Category does not overwrite by day', false, strpos($categorySql, '(analysis_date,target_ym,project_id,cost_type)') !== false);

cpms_ai_structure_same('Null confidence stays unavailable', null, AiCostForecastV2Service::adjustConfidence(null, array()));
$limitedConfidence = AiCostForecastV2Service::adjustConfidence(92, array('live_input_sample_count'=>0,'timing_pattern_month_count'=>0,'amount_pattern_month_count'=>6,'similar_project_sample_count'=>0,'new_site_flag'=>1,'contract_change_flag'=>1,'schedule_change_flag'=>1,'recent_correction_ratio'=>0.3));
cpms_ai_structure_true('Weak evidence lowers confidence', $limitedConfidence < 50);
cpms_ai_structure_same('Unavailable confidence grade', 'INSUFFICIENT', AiCostForecastV2Service::confidenceGrade(null));
cpms_ai_structure_same('Low confidence grade', 'LOW', AiCostForecastV2Service::confidenceGrade(55));
cpms_ai_structure_same('Medium confidence grade', 'MEDIUM', AiCostForecastV2Service::confidenceGrade(75));
cpms_ai_structure_same('High confidence grade', 'HIGH', AiCostForecastV2Service::confidenceGrade(90));

cpms_ai_structure_same('Comparable scale and duration accepted', true, AiProjectTypeService::isComparableScaleDuration(1000,100,1500,150));
cpms_ai_structure_same('Very different contract scale excluded', false, AiProjectTypeService::isComparableScaleDuration(1000,100,2500,100));
cpms_ai_structure_same('Very different duration excluded', false, AiProjectTypeService::isComparableScaleDuration(1000,100,1000,250));

$requiredReview=UsageAnalyticsService::classifyReviewTarget('REQUIRED',0,0,null,null,null,null,null);
cpms_ai_structure_same('Unused required feature stays required', 'KEEP_REQUIRED', $requiredReview['classification']);
$errorReview=UsageAnalyticsService::classifyReviewTarget('NORMAL',30,10,60,30,80,80,0);
cpms_ai_structure_same('High save failure needs usability review', 'USABILITY_REVIEW', $errorReview['classification']);
$mobileReview=UsageAnalyticsService::classifyReviewTarget('NORMAL',30,10,80,0,90,50,0);
cpms_ai_structure_same('Mobile completion gap needs review', 'USABILITY_REVIEW', $mobileReview['classification']);
$optionalReview=UsageAnalyticsService::classifyReviewTarget('OPTIONAL',0,0,null,null,null,null,null);
cpms_ai_structure_same('Unused optional feature is only a hide review candidate', 'HIDE_REVIEW', $optionalReview['classification']);
$locationReview=UsageAnalyticsService::classifyReviewTarget('NORMAL',10,0,null,null,null,null,null);
cpms_ai_structure_same('Low action reach needs location review', 'LOCATION_REVIEW', $locationReview['classification']);
$mergeReview=UsageAnalyticsService::classifyReviewTarget('NORMAL',10,5,100,0,100,100,0,array('alternative_feature_key'=>'replacement:submit'));
cpms_ai_structure_same('Known alternative only creates merge review', 'MERGE_REVIEW', $mergeReview['classification']);
cpms_ai_structure_same('Workflow keys are route scoped', 'project/project_save:submit', UsageAnalyticsService::workflowFeatureKey('project/project_save','submit'));

$memoryPriority=AiMemoryService::applicationPriority();
cpms_ai_structure_same('Calculated evidence has highest memory priority', 'CALCULATED_EVIDENCE', $memoryPriority[0]);
cpms_ai_structure_same('Project memory precedes company memory', 'PROJECT', $memoryPriority[1]);
cpms_ai_structure_same('Personal preference cannot override company rule', 'PERSONAL', $memoryPriority[3]);

if (count($failures) > 0) {
    fwrite(STDERR, "FAIL: " . count($failures) . " / " . $checks . "\n");
    foreach ($failures as $failure) fwrite(STDERR, "- " . $failure . "\n");
    exit(1);
}

echo "PASS: " . $checks . " predictive AI data structure checks\n";
