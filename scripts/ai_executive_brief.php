<?php
/** Latest executive briefing generator. PHP 5.6 CLI only. */

if (php_sapi_name() !== 'cli') exit(1);
if (isset($argv) && count($argv) > 1) { echo "Arguments are not supported.\n"; exit(1); }

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/services/OpenAiResponsesClient.php';
require_once dirname(__DIR__) . '/app/services/AiExecutiveBriefService.php';

use App\Core\Db;
use App\Services\AiExecutiveBriefService;

try {
    $result=AiExecutiveBriefService::generateLatest(Db::pdo(),'CLI',false);
    if (empty($result['ok'])) {
        echo "Executive brief failed\n";
        echo isset($result['message'])?$result['message'] . "\n":"Please check the OpenAI and risk analysis setup.\n";
        exit(1);
    }
    echo "Executive brief completed\n";
    echo 'Analysis date: ' . (isset($result['analysis_date'])?$result['analysis_date']:'-') . "\n";
    echo 'Target month: ' . (isset($result['target_ym'])?$result['target_ym']:'-') . "\n";
    echo 'Projects: ' . (isset($result['projects'])?(int)$result['projects']:0) . "\n";
    echo 'Model: ' . (isset($result['model'])?$result['model']:'-') . "\n";
    echo 'Status: ' . (isset($result['status'])?$result['status']:'-') . "\n";
    echo 'Cached: ' . (!empty($result['cached'])?'yes':'no') . "\n";
    exit(0);
} catch (Exception $e) {
    error_log('[OpenAI] task=EXECUTIVE_BRIEF status=FAILED');
    echo "Executive brief failed\n";
    exit(1);
}
