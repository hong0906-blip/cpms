<?php
/**
 * OpenAI Responses API REST client.
 * PHP 5.6 compatible. API keys are never returned to views or logs.
 */

namespace App\Services;

use Exception;

class OpenAiResponsesClient
{
    const DEFAULT_MODEL = 'gpt-5.6-terra';
    const DEFAULT_ENDPOINT = 'https://api.openai.com/v1/responses';
    const DEFAULT_MAX_OUTPUT_TOKENS = 1800;
    const DEFAULT_QA_MAX_OUTPUT_TOKENS = 1400;
    const DEFAULT_TIMEOUT_SECONDS = 60;
    const DEFAULT_CONNECT_TIMEOUT_SECONDS = 10;
    const DEFAULT_SCHEMA_VERSION = 'executive_brief_v1';
    const DEFAULT_REASONING_EFFORT = 'low';

    private static $configCache = null;

    private static function readLocalConfig($file)
    {
        $result = array();
        if (!is_file($file) || !is_readable($file)) return $result;
        $contents = @file_get_contents($file);
        if (!is_string($contents) || $contents === '') return $result;
        $stringKeys = array('api_key','model','qa_model','endpoint','schema_version','reasoning_effort','qa_reasoning_effort');
        foreach ($stringKeys as $key) {
            $quotedKey = preg_quote($key, '/');
            if (preg_match("/['\"]" . $quotedKey . "['\"]\\s*=>\\s*'((?:\\\\.|[^'\\\\])*)'/s", $contents, $match)) {
                $result[$key] = str_replace(array("\\\\", "\\'"), array("\\", "'"), $match[1]);
            } else if (preg_match('/[\'\"]' . $quotedKey . '[\'\"]\\s*=>\\s*"((?:\\\\.|[^"\\\\])*)"/s', $contents, $match)) {
                $result[$key] = stripcslashes($match[1]);
            }
        }
        foreach (array('max_output_tokens','qa_max_output_tokens','timeout_seconds','connect_timeout_seconds') as $key) {
            $quotedKey = preg_quote($key, '/');
            if (preg_match('/[\'\"]' . $quotedKey . '[\'\"]\\s*=>\\s*([0-9]+)/', $contents, $match)) $result[$key] = (int)$match[1];
        }
        return $result;
    }

    public static function resetConfigCache()
    {
        self::$configCache = null;
    }

    public static function loadConfig()
    {
        if (is_array(self::$configCache)) return self::$configCache;

        $config = array(
            'api_key' => '',
            'source' => 'NONE',
            'model' => self::DEFAULT_MODEL,
            'qa_model' => self::DEFAULT_MODEL,
            'reasoning_effort' => self::DEFAULT_REASONING_EFFORT,
            'qa_reasoning_effort' => self::DEFAULT_REASONING_EFFORT,
            'endpoint' => self::DEFAULT_ENDPOINT,
            'max_output_tokens' => self::DEFAULT_MAX_OUTPUT_TOKENS,
            'qa_max_output_tokens' => self::DEFAULT_QA_MAX_OUTPUT_TOKENS,
            'timeout_seconds' => self::DEFAULT_TIMEOUT_SECONDS,
            'connect_timeout_seconds' => self::DEFAULT_CONNECT_TIMEOUT_SECONDS,
            'schema_version' => self::DEFAULT_SCHEMA_VERSION,
        );

        $localFile = __DIR__ . '/../config/openai.local.php';
        $localConfig = self::readLocalConfig($localFile);

        foreach (array('model', 'qa_model', 'reasoning_effort', 'qa_reasoning_effort', 'endpoint', 'schema_version') as $key) {
            if (isset($localConfig[$key]) && trim((string)$localConfig[$key]) !== '') {
                $config[$key] = trim((string)$localConfig[$key]);
            }
        }
        foreach (array('max_output_tokens', 'qa_max_output_tokens', 'timeout_seconds', 'connect_timeout_seconds') as $key) {
            if (isset($localConfig[$key]) && is_numeric($localConfig[$key])) {
                $config[$key] = (int)$localConfig[$key];
            }
        }

        $config['max_output_tokens'] = max(100, min(10000, (int)$config['max_output_tokens']));
        $config['qa_max_output_tokens'] = max(100, min(10000, (int)$config['qa_max_output_tokens']));
        $config['timeout_seconds'] = max(5, min(180, (int)$config['timeout_seconds']));
        $config['connect_timeout_seconds'] = max(2, min(60, (int)$config['connect_timeout_seconds']));
        $config['endpoint'] = self::DEFAULT_ENDPOINT;
        $envModel = getenv('OPENAI_MODEL');
        if (is_string($envModel) && trim($envModel) !== '') $config['model'] = trim($envModel);
        $envQaModel = getenv('OPENAI_QA_MODEL');
        if (is_string($envQaModel) && trim($envQaModel) !== '') {
            $config['qa_model'] = trim($envQaModel);
        } else if (!isset($localConfig['qa_model']) || trim((string)$localConfig['qa_model']) === '') {
            $config['qa_model'] = $config['model'];
        }
        $envReasoning = getenv('OPENAI_REASONING_EFFORT');
        if (is_string($envReasoning) && trim($envReasoning) !== '') $config['reasoning_effort'] = trim($envReasoning);
        $envQaReasoning = getenv('OPENAI_QA_REASONING_EFFORT');
        if (is_string($envQaReasoning) && trim($envQaReasoning) !== '') {
            $config['qa_reasoning_effort'] = trim($envQaReasoning);
        } else if (!isset($localConfig['qa_reasoning_effort']) || trim((string)$localConfig['qa_reasoning_effort']) === '') {
            $config['qa_reasoning_effort'] = $config['reasoning_effort'];
        }
        if (!preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $config['model'])) $config['model'] = self::DEFAULT_MODEL;
        if (!preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $config['qa_model'])) $config['qa_model'] = self::DEFAULT_MODEL;
        $config['reasoning_effort'] = self::normalizeReasoningEffort($config['reasoning_effort']);
        $config['qa_reasoning_effort'] = self::normalizeReasoningEffort($config['qa_reasoning_effort']);
        if (!preg_match('/^[A-Za-z0-9._-]{1,50}$/', $config['schema_version'])) $config['schema_version'] = self::DEFAULT_SCHEMA_VERSION;

        $envKey = getenv('OPENAI_API_KEY');
        if (is_string($envKey) && trim($envKey) !== '') {
            $config['api_key'] = trim($envKey);
            $config['source'] = 'ENV';
        } else if (isset($localConfig['api_key']) && is_string($localConfig['api_key']) && trim($localConfig['api_key']) !== '') {
            $config['api_key'] = trim($localConfig['api_key']);
            $config['source'] = 'LOCAL';
        }

        self::$configCache = $config;
        return self::$configCache;
    }

    public static function apiKeySource()
    {
        $config = self::loadConfig();
        return isset($config['source']) ? (string)$config['source'] : 'NONE';
    }

    public static function hasApiKey()
    {
        $config = self::loadConfig();
        return isset($config['api_key']) && is_string($config['api_key']) && trim($config['api_key']) !== '';
    }

    public static function maskedConfigurationStatus()
    {
        $config = self::loadConfig();
        $available = self::hasApiKey();
        return array(
            'available' => $available,
            'source' => $available ? self::apiKeySource() : 'NONE',
            'source_label' => self::apiKeySource() === 'ENV' ? '환경변수 사용' : (self::apiKeySource() === 'LOCAL' ? '로컬 비밀설정 사용' : '설정 없음'),
            'model' => self::model(),
            'qa_model' => self::qaModel(),
            'reasoning_effort' => self::reasoningEffort(),
            'qa_reasoning_effort' => self::qaReasoningEffort(),
            'max_output_tokens' => (int)$config['max_output_tokens'],
            'qa_max_output_tokens' => (int)$config['qa_max_output_tokens'],
            'timeout_seconds' => (int)$config['timeout_seconds'],
            'connect_timeout_seconds' => (int)$config['connect_timeout_seconds'],
            'schema_version' => (string)$config['schema_version'],
            'curl_available' => function_exists('curl_init'),
            'message' => $available ? 'OpenAI API 키가 설정되어 있습니다.' : 'OpenAI API 키가 설정되지 않았습니다.',
        );
    }

    public static function model()
    {
        $config = self::loadConfig();
        return isset($config['model']) ? (string)$config['model'] : self::DEFAULT_MODEL;
    }

    public static function qaModel()
    {
        $config = self::loadConfig();
        return isset($config['qa_model']) ? (string)$config['qa_model'] : self::DEFAULT_MODEL;
    }

    public static function normalizeReasoningEffort($value)
    {
        $value = strtolower(trim((string)$value));
        return in_array($value, array('none','low','medium','high','xhigh','max'), true) ? $value : self::DEFAULT_REASONING_EFFORT;
    }

    public static function reasoningEffort()
    {
        $config = self::loadConfig();
        return self::normalizeReasoningEffort(isset($config['reasoning_effort']) ? $config['reasoning_effort'] : self::DEFAULT_REASONING_EFFORT);
    }

    public static function qaReasoningEffort()
    {
        $config = self::loadConfig();
        return self::normalizeReasoningEffort(isset($config['qa_reasoning_effort']) ? $config['qa_reasoning_effort'] : self::DEFAULT_REASONING_EFFORT);
    }

    public static function maxOutputTokens()
    {
        $config = self::loadConfig();
        return isset($config['max_output_tokens']) ? (int)$config['max_output_tokens'] : self::DEFAULT_MAX_OUTPUT_TOKENS;
    }

    public static function qaMaxOutputTokens()
    {
        $config = self::loadConfig();
        return isset($config['qa_max_output_tokens']) ? (int)$config['qa_max_output_tokens'] : self::DEFAULT_QA_MAX_OUTPUT_TOKENS;
    }

    public static function schemaVersion()
    {
        $config = self::loadConfig();
        return isset($config['schema_version']) ? (string)$config['schema_version'] : self::DEFAULT_SCHEMA_VERSION;
    }

    public static function endpoint()
    {
        return self::DEFAULT_ENDPOINT;
    }

    public static function buildHeaders()
    {
        $config = self::loadConfig();
        if (!self::hasApiKey()) return array();
        return array(
            'Authorization: Bearer ' . $config['api_key'],
            'Content-Type: application/json',
        );
    }

    public static function extractOutputText($response)
    {
        if (!is_array($response) || !isset($response['output']) || !is_array($response['output'])) return '';
        foreach ($response['output'] as $output) {
            if (!is_array($output) || !isset($output['type']) || $output['type'] !== 'message') continue;
            if (!isset($output['content']) || !is_array($output['content'])) continue;
            foreach ($output['content'] as $content) {
                if (!is_array($content) || !isset($content['type']) || $content['type'] !== 'output_text') continue;
                if (isset($content['text']) && is_string($content['text'])) return $content['text'];
            }
        }
        return '';
    }

    public static function extractRefusal($response)
    {
        if (!is_array($response) || !isset($response['output']) || !is_array($response['output'])) return '';
        foreach ($response['output'] as $output) {
            if (!is_array($output) || !isset($output['content']) || !is_array($output['content'])) continue;
            foreach ($output['content'] as $content) {
                if (!is_array($content)) continue;
                if (isset($content['type']) && $content['type'] === 'refusal') {
                    return isset($content['refusal']) && is_string($content['refusal']) ? $content['refusal'] : 'refused';
                }
            }
        }
        return '';
    }

    public static function extractUsage($response)
    {
        $usage = array('input_tokens' => null, 'output_tokens' => null, 'total_tokens' => null);
        if (!is_array($response) || !isset($response['usage']) || !is_array($response['usage'])) return $usage;
        foreach (array('input_tokens', 'output_tokens', 'total_tokens') as $key) {
            if (isset($response['usage'][$key]) && is_numeric($response['usage'][$key])) $usage[$key] = max(0, (int)$response['usage'][$key]);
        }
        return $usage;
    }

    public static function sanitizeError($httpStatus, $curlErrno, $curlError, $response)
    {
        $httpStatus = (int)$httpStatus;
        $curlErrno = (int)$curlErrno;
        $curlError = strtolower(trim((string)$curlError));
        if ($curlErrno !== 0) {
            $timeoutCodes = array();
            if (defined('CURLE_OPERATION_TIMEDOUT')) $timeoutCodes[] = (int)constant('CURLE_OPERATION_TIMEDOUT');
            if (in_array($curlErrno, $timeoutCodes, true) || strpos($curlError, 'timed out') !== false || strpos($curlError, 'timeout') !== false) {
                return array('code' => 'OPENAI_TIMEOUT', 'message' => 'OpenAI 응답시간이 초과되었습니다.');
            }
            return array('code' => 'OPENAI_NETWORK', 'message' => '서버에서 OpenAI API에 연결하지 못했습니다.');
        }
        if ($httpStatus === 400) return array('code' => 'OPENAI_400', 'message' => 'OpenAI 요청 설정을 확인해주세요.');
        if ($httpStatus === 401) return array('code' => 'OPENAI_401', 'message' => 'OpenAI API 키가 올바르지 않거나 사용할 수 없습니다.');
        if ($httpStatus === 403) return array('code' => 'OPENAI_403', 'message' => 'OpenAI 프로젝트의 GPT-5.6 모델 사용 권한을 확인해주세요.');
        if ($httpStatus === 404) return array('code' => 'OPENAI_404', 'message' => '설정된 OpenAI 모델 ID를 확인해주세요.');
        if ($httpStatus === 429) return array('code' => 'OPENAI_429', 'message' => 'OpenAI API 사용량 또는 호출 제한을 확인해주세요.');
        if ($httpStatus >= 500 && $httpStatus <= 599) return array('code' => 'OPENAI_5XX', 'message' => 'OpenAI 서비스가 일시적으로 응답하지 않습니다.');
        if ($httpStatus < 200 || $httpStatus > 299) return array('code' => 'OPENAI_HTTP', 'message' => 'OpenAI 요청을 처리하지 못했습니다.');
        return array('code' => 'OPENAI_RESPONSE', 'message' => 'OpenAI 응답 형식을 확인하지 못했습니다.');
    }

    public static function request($payload, $taskType)
    {
        $started = microtime(true);
        $empty = array(
            'ok' => false, 'http_status' => null, 'response_id' => '', 'response' => array(), 'output_text' => '',
            'refused' => false, 'usage' => array('input_tokens' => null, 'output_tokens' => null, 'total_tokens' => null),
            'elapsed_ms' => 0, 'error_code' => '', 'message' => 'OpenAI 요청을 처리하지 못했습니다.'
        );
        if (!self::hasApiKey()) {
            $empty['error_code'] = 'OPENAI_KEY_MISSING';
            $empty['message'] = 'OpenAI API 키가 설정되지 않았습니다.';
            return $empty;
        }
        if (!function_exists('curl_init')) {
            $empty['error_code'] = 'CURL_MISSING';
            $empty['message'] = '서버의 PHP cURL 기능을 확인해주세요.';
            return $empty;
        }
        if (!is_array($payload)) {
            $empty['error_code'] = 'REQUEST_INVALID';
            return $empty;
        }
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            $empty['error_code'] = 'JSON_ENCODE_FAILED';
            $empty['message'] = 'OpenAI 요청자료를 준비하지 못했습니다.';
            return $empty;
        }

        $config = self::loadConfig();
        $ch = curl_init();
        if ($ch === false) {
            $empty['error_code'] = 'CURL_INIT_FAILED';
            $empty['message'] = '서버에서 OpenAI API에 연결하지 못했습니다.';
            return $empty;
        }
        curl_setopt($ch, CURLOPT_URL, self::endpoint());
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, self::buildHeaders());
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, (int)$config['connect_timeout_seconds']);
        curl_setopt($ch, CURLOPT_TIMEOUT, (int)$config['timeout_seconds']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_HEADER, false);

        $raw = curl_exec($ch);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $empty['elapsed_ms'] = (int)round((microtime(true) - $started) * 1000);
        $empty['http_status'] = $httpStatus > 0 ? $httpStatus : null;

        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if ($curlErrno !== 0 || $httpStatus < 200 || $httpStatus > 299) {
            $safe = self::sanitizeError($httpStatus, $curlErrno, $curlError, is_array($decoded) ? $decoded : array());
            $empty['error_code'] = $safe['code'];
            $empty['message'] = $safe['message'];
            error_log('[OpenAI] task=' . preg_replace('/[^A-Z0-9_]/', '', strtoupper((string)$taskType)) . ' status=' . ($httpStatus > 0 ? $httpStatus : 'NETWORK'));
            return $empty;
        }
        if (!is_array($decoded)) {
            $empty['error_code'] = 'JSON_DECODE_FAILED';
            $empty['message'] = 'OpenAI 응답 형식을 확인하지 못했습니다.';
            return $empty;
        }
        $empty['response'] = $decoded;
        $empty['response_id'] = isset($decoded['id']) && is_string($decoded['id']) ? substr($decoded['id'], 0, 100) : '';
        $empty['usage'] = self::extractUsage($decoded);
        $refusal = self::extractRefusal($decoded);
        if ($refusal !== '') {
            $empty['refused'] = true;
            $empty['error_code'] = 'OPENAI_REFUSED';
            $empty['message'] = 'OpenAI가 요청 처리를 거부했습니다.';
            return $empty;
        }
        $empty['output_text'] = self::extractOutputText($decoded);
        if ($empty['output_text'] === '') {
            $empty['error_code'] = 'OUTPUT_MISSING';
            $empty['message'] = 'OpenAI 응답 형식을 확인하지 못했습니다.';
            return $empty;
        }
        $empty['ok'] = true;
        $empty['error_code'] = '';
        $empty['message'] = 'OpenAI 응답을 받았습니다.';
        return $empty;
    }

    public static function testConnection()
    {
        $schema = array(
            'type' => 'object',
            'additionalProperties' => false,
            'required' => array('status'),
            'properties' => array('status' => array('type' => 'string', 'enum' => array('OK'))),
        );
        $config = self::loadConfig();
        $payload = array(
            'model' => self::model(),
            'store' => false,
            'reasoning' => array('effort' => self::reasoningEffort()),
            'instructions' => '연결 확인 요청입니다. 반드시 지정된 JSON 형식으로만 응답하세요.',
            'input' => array(array('role' => 'user', 'content' => array(array('type' => 'input_text', 'text' => 'CPMS OpenAI 연결 테스트입니다. 반드시 status 값에 OK만 반환하세요.')))),
            'max_output_tokens' => 100,
            'text' => array('format' => array('type' => 'json_schema', 'name' => 'cpms_connection_test', 'description' => 'CPMS OpenAI 연결 확인', 'strict' => true, 'schema' => $schema)),
        );
        $result = self::request($payload, 'CONNECTION_TEST');
        if (!empty($result['ok'])) {
            $decoded = json_decode($result['output_text'], true);
            if (!is_array($decoded) || !isset($decoded['status']) || $decoded['status'] !== 'OK') {
                $result['ok'] = false;
                $result['error_code'] = 'TEST_RESPONSE_INVALID';
                $result['message'] = 'OpenAI 연결 응답 형식을 확인하지 못했습니다.';
            } else {
                $result['message'] = 'OpenAI 연결에 성공했습니다.';
            }
        }
        $result['model'] = self::model();
        unset($result['response'], $result['output_text']);
        return $result;
    }
}
