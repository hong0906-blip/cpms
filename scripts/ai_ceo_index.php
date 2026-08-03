<?php
/** Latest saved profit-risk results to CEO Index. PHP 5.6 CLI only. */

if (php_sapi_name() !== 'cli') {
    exit(1);
}

if (isset($argv) && count($argv) > 1) {
    echo "Arguments are not supported.\n";
    exit(1);
}

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/services/AiCeoIndexService.php';

use App\Core\Db;
use App\Services\AiCeoIndexService;

try {
    $result = AiCeoIndexService::calculateLatest(Db::pdo(), 'CLI');
    if (empty($result['ok'])) {
        echo "CEO Index failed\n";
        echo isset($result['message']) ? $result['message'] . "\n" : "Please check the saved risk analysis and setup status.\n";
        exit(1);
    }

    echo "CEO Index completed\n";
    echo 'Analysis date: ' . (isset($result['analysis_date']) ? $result['analysis_date'] : '-') . "\n";
    echo 'Target month: ' . (isset($result['target_ym']) ? $result['target_ym'] : '-') . "\n";
    echo 'Projects: ' . (isset($result['projects']) ? (int)$result['projects'] : 0) . "\n";
    echo 'Analyzable: ' . (isset($result['analyzable']) ? (int)$result['analyzable'] : 0) . "\n";
    echo 'Coverage: ' . (isset($result['coverage']) ? number_format((float)$result['coverage'], 1) : '0.0') . "%\n";
    echo 'CEO Index: ' . (isset($result['score']) && $result['score'] !== null ? number_format((float)$result['score'], 1) : '-') . "\n";
    echo 'Grade: ' . (isset($result['grade']) ? $result['grade'] : 'INSUFFICIENT') . "\n";
    exit(0);
} catch (Exception $e) {
    error_log('[AiCeoIndex] CLI run failed');
    echo "CEO Index failed\n";
    exit(1);
}
