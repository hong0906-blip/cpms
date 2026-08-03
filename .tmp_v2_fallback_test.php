<?php
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/services/AiExecutiveBriefService.php';
require_once __DIR__ . '/app/services/AiExecutiveChatService.php';

class NoV2Statement
{
    public $pdo;
    public $sql;
    public $params = array();

    public function __construct($pdo, $sql)
    {
        $this->pdo = $pdo;
        $this->sql = (string)$sql;
    }

    public function execute($params = array())
    {
        $this->params = is_array($params) ? $params : array();
        $this->pdo->executed[] = array('sql'=>$this->sql, 'params'=>$this->params);
        return true;
    }

    public function bindValue($key, $value, $type = null)
    {
        $this->params[$key] = $value;
        return true;
    }

    public function fetchColumn()
    {
        $table = '';
        if (isset($this->params[':table'])) $table = $this->params[':table'];
        if (isset($this->params[':table_name'])) $table = $this->params[':table_name'];
        if ($table !== '') return isset($this->pdo->tables[$table]) ? 1 : false;
        return 0;
    }

    public function fetch($mode = null)
    {
        if (strpos($this->sql, 'cpms_ai_ceo_index_results_v2') !== false && strpos($this->sql, 'SELECT r.*') !== false && isset($this->pdo->normalRow)) {
            return $this->pdo->normalRow;
        }
        if (strpos($this->sql, 'cpms_ai_chat_threads') !== false && strpos($this->sql, 'SELECT *') !== false) {
            return array('id'=>1, 'target_ym'=>'2026-08', 'status'=>'ACTIVE', 'memory_summary'=>'', 'current_source_fingerprint'=>'', 'title'=>'새 대화');
        }
        return false;
    }

    public function fetchAll($mode = null)
    {
        return array();
    }
}

class NoV2Pdo
{
    public $tables = array();
    public $executed = array();

    public function __construct()
    {
        $this->tables = array(
            'cpms_ai_chat_threads'=>true,
            'cpms_ai_chat_messages'=>true
        );
    }

    public function prepare($sql)
    {
        return new NoV2Statement($this, $sql);
    }

    public function query($sql)
    {
        $statement = new NoV2Statement($this, $sql);
        $statement->execute();
        return $statement;
    }

    public function lastInsertId()
    {
        return 1;
    }
}

class VersionedV2Pdo extends NoV2Pdo
{
    public $normalRow = array();

    public function __construct($version)
    {
        parent::__construct();
        foreach (array('cpms_ai_ceo_index_runs_v2', 'cpms_ai_ceo_index_results_v2', 'cpms_ai_ceo_project_results_v2', 'cpms_ai_cost_forecast_results_v2', 'cpms_ai_cost_forecast_runs_v2') as $table) {
            $this->tables[$table] = true;
        }
        $this->normalRow = array('id'=>10, 'run_id'=>20, 'analysis_date'=>'2026-08-02', 'target_ym'=>'2026-08', 'calculation_version'=>$version, 'source_fingerprint'=>str_repeat('a', 64));
    }
}

function test_assert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . "\n");
        exit(1);
    }
}

$expected = '신규 투입비 예측이 아직 실행되지 않았습니다. 자동 파이프라인 또는 개발부서 수동 분석을 먼저 실행해주세요.';
$pdo = new NoV2Pdo();

$brief = \App\Services\AiExecutiveBriefService::buildSourceData($pdo);
test_assert(empty($brief['ok']), 'V2 미존재 브리핑 원본은 실패해야 함');
test_assert(isset($brief['message']) && $brief['message'] === $expected, '브리핑 안내문이 정확해야 함');

$thread = array('id'=>1, 'target_ym'=>'2026-08', 'memory_summary'=>'', 'current_source_fingerprint'=>'');
$source = \App\Services\AiExecutiveChatService::buildSourceData($pdo, $thread, '이번 달 비용 상황을 알려줘');
test_assert(count($source) === 0, 'V2 미존재 대화 원본은 비어야 함');

$chat = \App\Services\AiExecutiveChatService::send($pdo, 1, '이번 달 비용 상황을 알려줘');
test_assert(empty($chat['ok']), 'V2 미존재 대화 답변은 실행되지 않아야 함');
test_assert(isset($chat['message']) && $chat['message'] === $expected, '대화 안내문이 정확해야 함');

$created = \App\Services\AiExecutiveChatService::createThread($pdo, '2026-08');
test_assert(empty($created['ok']), 'V2 미존재 새 대화는 생성되지 않아야 함');
test_assert(isset($created['message']) && $created['message'] === $expected, '새 대화 안내문이 정확해야 함');

foreach ($pdo->executed as $execution) {
    $sql = strtoupper($execution['sql']);
    test_assert(strpos($sql, 'INSERT INTO') === false, 'V2 미존재 시 메시지/대화 INSERT 금지');
    test_assert(strpos($sql, 'CPMS_AI_PROFIT_RISK_RESULTS') === false, '기존 위험분석 fallback 조회 금지');
    test_assert(strpos($sql, 'CPMS_AI_CEO_INDEX_RESULTS`') === false, '기존 7-2 CEO Index fallback 조회 금지');
}

$wrongVersionPdo = new VersionedV2Pdo('CEO_INDEX_V1');
$wrongVersion = \App\Services\AiCeoIndexService::latestNormalV2($wrongVersionPdo, '2026-08');
test_assert(count($wrongVersion) === 0, 'calculation_version 불일치 결과는 거부해야 함');

$validVersionPdo = new VersionedV2Pdo('COST_FORECAST_V2');
$validVersion = \App\Services\AiCeoIndexService::latestNormalV2($validVersionPdo, '2026-08');
test_assert(isset($validVersion['run_id']) && (int)$validVersion['run_id'] === 20, '정상 V2 결과는 사용할 수 있어야 함');
$normalSql = '';
foreach ($validVersionPdo->executed as $execution) {
    if (strpos($execution['sql'], 'SELECT r.*') !== false) $normalSql = $execution['sql'];
}
test_assert(substr_count($normalSql, "run_status='COMPLETED'") === 2, 'CEO와 투입비 예측 실행이 모두 정상 완료여야 함');
test_assert(strpos($normalSql, 'r.calculation_version=:ceo_result_version') !== false, 'CEO 결과 버전을 SQL에서 검증해야 함');
test_assert(strpos($normalSql, 'f.calculation_version=:forecast_result_version') !== false, '투입비 예측 결과 버전을 SQL에서 검증해야 함');

$v2ThreadCreated = \App\Services\AiExecutiveChatService::createThread($validVersionPdo, '2026-08');
test_assert(!empty($v2ThreadCreated['ok']), '정상 V2 자료가 있으면 새 대화를 만들 수 있어야 함');
$fingerprintStored = false;
foreach ($validVersionPdo->executed as $execution) {
    if (strpos($execution['sql'], 'INSERT INTO `cpms_ai_chat_threads`') !== false && isset($execution['params'][':fingerprint']) && $execution['params'][':fingerprint'] === str_repeat('a', 64)) $fingerprintStored = true;
}
test_assert($fingerprintStored, '새 대화에 최신 정상 V2 fingerprint를 저장해야 함');

$legacyThreadChat = \App\Services\AiExecutiveChatService::send($validVersionPdo, 1, '이번 달 비용 상황을 알려줘');
test_assert(empty($legacyThreadChat['ok']), '기존 자료 대화방에 V2 답변을 혼합하면 안 됨');
test_assert(isset($legacyThreadChat['message']) && strpos($legacyThreadChat['message'], '최신 V2 자료로 새 대화') !== false, '기존 대화방에는 V2 새 대화 안내가 필요함');

$briefCode = file_get_contents(__DIR__ . '/app/services/AiExecutiveBriefService.php');
$briefStart = strpos($briefCode, 'public static function generateLatest');
$briefSource = strpos($briefCode, 'self::buildSourceData($pdo)', $briefStart);
$briefApi = strpos($briefCode, 'OpenAiResponsesClient::request', $briefStart);
test_assert($briefStart !== false && $briefSource !== false && $briefApi !== false && $briefSource < $briefApi, '브리핑 V2 검증이 API 호출보다 먼저여야 함');

$chatCode = file_get_contents(__DIR__ . '/app/services/AiExecutiveChatService.php');
$chatStart = strpos($chatCode, 'public static function send');
$chatSource = strpos($chatCode, 'self::buildSourceData', $chatStart);
$chatApi = strpos($chatCode, 'OpenAiResponsesClient::request', $chatStart);
test_assert($chatStart !== false && $chatSource !== false && $chatApi !== false && $chatSource < $chatApi, '대화 V2 검증이 API 호출보다 먼저여야 함');

echo "V2 fallback guard tests passed\n";
