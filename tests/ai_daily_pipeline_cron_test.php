<?php
/** AI daily pipeline cron wiring regression test. PHP 5.6 compatible. */

$root = dirname(__DIR__);
$service = file_get_contents($root . '/app/services/AiDailyPipelineService.php');
$job = file_get_contents($root . '/tools/ai_daily_pipeline_job.php');
$setupView = file_get_contents($root . '/app/views/admin/ai_pipeline_setup.php');
$snapshotSetupView = file_get_contents($root . '/app/views/admin/ai_snapshot_setup.php');
$guide = file_get_contents($root . '/docs/ai_daily_pipeline_cron.md');

$failures = array();
$checks = 0;

function cpms_ai_cron_true($label, $condition)
{
    global $failures, $checks;
    $checks++;
    if (!$condition) $failures[] = $label;
}

$ownedServices = array(
    'AiDailySnapshotService',
    'AiInputCompletionPatternService',
    'AiCostForecastV2Service',
    'AiForecastAccuracyService',
    'AiInputReliabilityService',
    'AiAnomalyDetectionService',
    'AiProfitRiskService',
    'AiExecutiveBriefService',
    'AiMemoryService'
);
foreach ($ownedServices as $serviceName) {
    cpms_ai_cron_true(
        $serviceName . ' is installed by pipeline setup',
        strpos($service, $serviceName . '::installOrUpdate($pdo)') !== false
    );
    cpms_ai_cron_true(
        $serviceName . ' is required by pipeline readiness',
        strpos($service, $serviceName . '::isInstalled($pdo)') !== false
    );
}
cpms_ai_cron_true('CEO Index V2 is installed by pipeline setup', strpos($service, 'AiCeoIndexService::installV2($pdo)') !== false);
cpms_ai_cron_true('CEO Index V2 is required by pipeline readiness', strpos($service, 'AiCeoIndexService::isV2Installed($pdo)') !== false);

$orderedSteps = array("array('snapshot'", "array('input_pattern'", "array('forecast_v2'", "array('projection_risk'", "array('ceo_index_v2'", "array('gpt_summary'");
$previousPosition = -1;
foreach ($orderedSteps as $stepText) {
    $position = strpos($service, $stepText);
    cpms_ai_cron_true($stepText . ' exists in pipeline', $position !== false);
    cpms_ai_cron_true($stepText . ' keeps pipeline order', $position !== false && $position > $previousPosition);
    $previousPosition = $position;
}

cpms_ai_cron_true('CLI fixes Asia/Seoul timezone', strpos($job, "date_default_timezone_set('Asia/Seoul')") !== false);
cpms_ai_cron_true('CLI uses a non-blocking file lock', strpos($job, 'LOCK_EX | LOCK_NB') !== false);
cpms_ai_cron_true('CLI supports setup-only mode', strpos($job, '--setup-only=1') !== false);
cpms_ai_cron_true('CLI auto-installs missing pipeline schema', strpos($job, '!AiDailyPipelineService::isInstalled($pdo)') !== false);
cpms_ai_cron_true('CLI exposes partial completion as exit code 2', strpos($job, "else if (\$status === 'PARTIAL') \$exitCode = 2;") !== false);
cpms_ai_cron_true('Admin setup shows Korean cron timezone', strpos($setupView, 'CRON_TZ=Asia/Seoul') !== false);
cpms_ai_cron_true('Admin setup shows every-day 19:00 expression', strpos($setupView, "'0 19 * * * cd '") !== false);
cpms_ai_cron_true('Snapshot setup directs scheduling to the full pipeline', strpos($snapshotSetupView, '일일 스냅샷도 매일 19:00 파이프라인의 첫 단계') !== false);
cpms_ai_cron_true('Guide documents every-day 19:00 expression', strpos($guide, '0 19 * * * cd /') !== false);

if (count($failures) > 0) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . ' / ' . $checks . "\n");
    foreach ($failures as $failure) fwrite(STDERR, '- ' . $failure . "\n");
    exit(1);
}

echo 'PASS: ' . $checks . " AI daily pipeline cron checks\n";
?>
