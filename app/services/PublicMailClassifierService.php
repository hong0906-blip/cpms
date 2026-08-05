<?php
/**
 * 파일 경로: C:\www\cpms\app\services\PublicMailClassifierService.php
 *
 * 메일 제목, 발신자, 본문 일부를 이용해 부서, 중요도, 현장을 규칙으로 분류합니다.
 * PHP 5.6 호환 코드입니다.
 */

namespace App\Services;

class PublicMailClassifierService
{
    public function classify($message, $projects)
    {
        $subject = isset($message['subject']) ? (string)$message['subject'] : '';
        $from = isset($message['from_text']) ? (string)$message['from_text'] : '';
        $preview = isset($message['preview']) ? (string)$message['preview'] : '';
        $haystack = $this->lower($subject . ' ' . $from . ' ' . $preview);

        $departmentRules = array(
            '안전/보건' => array(
                '안전', '보건', '위험성평가', '작업계획서', '안전교육', '신규교육', '정기교육',
                '특별교육', '추락', '사고', '재해', '보호구', '건강검진', 'msds', 'sop'
            ),
            '품질' => array(
                '품질', '검측', '자재승인', '승인원', '시험성적서', 'cqi', '검사', '검수',
                '시공계획서', '품질계획서', '부적합', 'ncr'
            ),
            '관리' => array(
                '세금계산서', '거래명세서', '잔액확인서', '입금', '지급', '급여', '4대보험',
                '원천세', '회계', '법인카드', '청구서', '보험료', '퇴직공제', '노무비 지급'
            ),
            '공무' => array(
                '기성', '계약', '견적', '내역서', '변경계약', '추가공사', '공기연장',
                '청구내역', '설계변경', '도급', '실행예산', '원가', '정산'
            ),
            '공사' => array(
                '공사일정', '공정', '현장요청', '작업사항', '시공', '장비', '자재', '투입',
                '현장사진', '공사일보', '작업일보', '공정표'
            )
        );

        $scores = array();
        foreach ($departmentRules as $department => $keywords) {
            $scores[$department] = 0;
            foreach ($keywords as $keyword) {
                if ($this->contains($haystack, $this->lower($keyword))) {
                    $scores[$department]++;
                }
            }
        }

        arsort($scores);
        $department = '';
        $departmentScore = 0;
        foreach ($scores as $name => $score) {
            $department = $name;
            $departmentScore = (int)$score;
            break;
        }

        if ($departmentScore <= 0) {
            $department = '미분류';
        }

        $urgentKeywords = array(
            '긴급', '즉시', '금일', '오늘까지', '회신 필요', '회신요청', '기한', '제출기한',
            '사고', '추락', '작업중지', '중지', '미제출', '지급기한', '독촉', '최종통보'
        );
        $importantKeywords = array(
            '승인 요청', '검토 요청', '결재', '계약', '세금계산서', '청구', '기성', '공문',
            '법원', '국세청', '고용노동부', '산업안전보건공단', '감리', '발주처'
        );

        $priority = '보통';
        $important = false;
        $urgentMatches = array();
        $importantMatches = array();

        foreach ($urgentKeywords as $keyword) {
            if ($this->contains($haystack, $this->lower($keyword))) {
                $urgentMatches[] = $keyword;
            }
        }
        foreach ($importantKeywords as $keyword) {
            if ($this->contains($haystack, $this->lower($keyword))) {
                $importantMatches[] = $keyword;
            }
        }

        if (!empty($urgentMatches)) {
            $priority = '긴급';
            $important = true;
        } elseif (!empty($importantMatches)) {
            $priority = '높음';
            $important = true;
        }

        $projectId = '';
        $projectName = '';
        $projectScore = 0;

        if (is_array($projects)) {
            foreach ($projects as $project) {
                if (!is_array($project)) {
                    continue;
                }

                $name = isset($project['name']) ? trim((string)$project['name']) : '';
                $code = isset($project['code']) ? trim((string)$project['code']) : '';
                $score = 0;

                if ($name !== '' && $this->contains($haystack, $this->lower($name))) {
                    $score += 3;
                }
                if ($code !== '' && $this->contains($haystack, $this->lower($code))) {
                    $score += 4;
                }

                $tokens = preg_split('/[\s\-_\/\(\)\[\],.]+/u', $name);
                if (is_array($tokens)) {
                    foreach ($tokens as $token) {
                        $token = trim((string)$token);
                        if ($this->stringLength($token) >= 2 && $this->contains($haystack, $this->lower($token))) {
                            $score++;
                        }
                    }
                }

                if ($score > $projectScore) {
                    $projectScore = $score;
                    $projectId = isset($project['id']) ? (string)$project['id'] : '';
                    $projectName = $name;
                }
            }
        }

        if ($projectScore < 2) {
            $projectId = '';
            $projectName = '';
        }

        $confidence = 35;
        if ($departmentScore > 0) {
            $confidence += min(35, $departmentScore * 10);
        }
        if ($projectScore >= 2) {
            $confidence += min(20, $projectScore * 4);
        }
        if ($important) {
            $confidence += 5;
        }
        if ($confidence > 95) {
            $confidence = 95;
        }

        return array(
            'department' => $department,
            'department_score' => $departmentScore,
            'project_id' => $projectId,
            'project_name' => $projectName,
            'project_score' => $projectScore,
            'priority' => $priority,
            'important' => $important,
            'confidence' => $confidence,
            'urgent_keywords' => $urgentMatches,
            'important_keywords' => $importantMatches,
            'method' => 'rules',
            'classified_at' => date('Y-m-d H:i:s')
        );
    }

    /**
     * 규칙 결과가 애매한 경우에만 기존 CPMS OpenAI 설정을 이용해 보조분류합니다.
     * API 키가 없거나 호출에 실패하면 null을 반환해 규칙 결과를 그대로 사용합니다.
     */
    public function classifyAmbiguousWithGpt($message, $projects, $ruleResult)
    {
        $clientFile = __DIR__ . '/OpenAiResponsesClient.php';
        if (!is_file($clientFile)) {
            return null;
        }

        require_once $clientFile;
        if (!class_exists('App\\Services\\OpenAiResponsesClient')) {
            return null;
        }
        if (!OpenAiResponsesClient::hasApiKey() || !function_exists('curl_init')) {
            return null;
        }

        $projectOptions = array();
        $validProjects = array();
        if (is_array($projects)) {
            foreach ($projects as $project) {
                if (!is_array($project)) continue;
                $id = isset($project['id']) ? trim((string)$project['id']) : '';
                $name = isset($project['name']) ? trim((string)$project['name']) : '';
                $code = isset($project['code']) ? trim((string)$project['code']) : '';
                if ($id === '' || $name === '') continue;
                $projectOptions[] = array('id' => $id, 'name' => $name, 'code' => $code);
                $validProjects[$id] = array('name' => $name, 'code' => $code);
                if (count($projectOptions) >= 60) break;
            }
        }

        $input = array(
            'subject' => $this->privacySafeText(isset($message['subject']) ? $message['subject'] : '', 500),
            'sender' => $this->privacySafeText(isset($message['from_text']) ? $message['from_text'] : '', 300),
            'preview' => $this->privacySafeText(isset($message['preview']) ? $message['preview'] : '', 2400),
            'rule_result' => is_array($ruleResult) ? array(
                'department' => isset($ruleResult['department']) ? (string)$ruleResult['department'] : '미분류',
                'priority' => isset($ruleResult['priority']) ? (string)$ruleResult['priority'] : '보통',
                'project_id' => isset($ruleResult['project_id']) ? (string)$ruleResult['project_id'] : '',
                'confidence' => isset($ruleResult['confidence']) ? (int)$ruleResult['confidence'] : 0
            ) : array(),
            'projects' => $projectOptions
        );

        $schema = array(
            'type' => 'object',
            'additionalProperties' => false,
            'required' => array('department','project_id','priority','important','confidence','summary','required_action','due_hint'),
            'properties' => array(
                'department' => array('type' => 'string', 'enum' => array('공무','공사','안전/보건','품질','관리','일반','미분류')),
                'project_id' => array('type' => 'string'),
                'priority' => array('type' => 'string', 'enum' => array('긴급','높음','보통','낮음')),
                'important' => array('type' => 'boolean'),
                'confidence' => array('type' => 'integer', 'minimum' => 0, 'maximum' => 100),
                'summary' => array('type' => 'string'),
                'required_action' => array('type' => 'string'),
                'due_hint' => array('type' => 'string')
            )
        );

        $payload = array(
            'model' => OpenAiResponsesClient::model(),
            'store' => false,
            'reasoning' => array('effort' => 'low'),
            'instructions' => '당신은 건설회사 공용메일 분류 보조자입니다. 제공된 메일 일부만 보고 지정된 JSON 형식으로 분류하세요. 프로젝트는 제공된 projects 목록에서 명확할 때만 project_id를 선택하고, 불명확하면 빈 문자열로 반환하세요. 개인정보를 추측하거나 새로 만들지 마세요.',
            'input' => array(array(
                'role' => 'user',
                'content' => array(array(
                    'type' => 'input_text',
                    'text' => json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                ))
            )),
            'max_output_tokens' => 600,
            'text' => array('format' => array(
                'type' => 'json_schema',
                'name' => 'cpms_public_mail_classification',
                'description' => 'CPMS 공용메일 분류 결과',
                'strict' => true,
                'schema' => $schema
            ))
        );

        $result = OpenAiResponsesClient::request($payload, 'PUBLIC_MAIL_CLASSIFY');
        if (empty($result['ok']) || empty($result['output_text'])) {
            return null;
        }

        $decoded = json_decode((string)$result['output_text'], true);
        if (!is_array($decoded)) {
            return null;
        }

        $departmentOptions = array('공무','공사','안전/보건','품질','관리','일반','미분류');
        $priorityOptions = array('긴급','높음','보통','낮음');
        $department = isset($decoded['department']) && in_array($decoded['department'], $departmentOptions, true)
            ? (string)$decoded['department'] : '미분류';
        $priority = isset($decoded['priority']) && in_array($decoded['priority'], $priorityOptions, true)
            ? (string)$decoded['priority'] : '보통';
        $projectId = isset($decoded['project_id']) ? trim((string)$decoded['project_id']) : '';
        $projectName = '';
        if ($projectId !== '' && isset($validProjects[$projectId])) {
            $projectName = (string)$validProjects[$projectId]['name'];
        } else {
            $projectId = '';
        }

        $merged = is_array($ruleResult) ? $ruleResult : array();
        $merged['department'] = $department;
        $merged['project_id'] = $projectId;
        $merged['project_name'] = $projectName;
        $merged['priority'] = $priority;
        $merged['important'] = !empty($decoded['important']);
        $merged['confidence'] = isset($decoded['confidence']) ? max(0, min(100, (int)$decoded['confidence'])) : 50;
        $merged['summary'] = $this->shortText(isset($decoded['summary']) ? $decoded['summary'] : '', 500);
        $merged['required_action'] = $this->shortText(isset($decoded['required_action']) ? $decoded['required_action'] : '', 500);
        $merged['due_hint'] = $this->shortText(isset($decoded['due_hint']) ? $decoded['due_hint'] : '', 100);
        $merged['method'] = 'rules+gpt';
        $merged['gpt_pending'] = false;
        $merged['classified_at'] = date('Y-m-d H:i:s');
        if (isset($result['usage']) && is_array($result['usage'])) {
            $merged['gpt_usage'] = array(
                'input_tokens' => isset($result['usage']['input_tokens']) ? $result['usage']['input_tokens'] : null,
                'output_tokens' => isset($result['usage']['output_tokens']) ? $result['usage']['output_tokens'] : null,
                'total_tokens' => isset($result['usage']['total_tokens']) ? $result['usage']['total_tokens'] : null
            );
        }
        return $merged;
    }

    private function privacySafeText($value, $length)
    {
        $value = strip_tags((string)$value);
        $value = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[이메일]', $value);
        $value = preg_replace('/\b01[016789][\s.\-]?\d{3,4}[\s.\-]?\d{4}\b/', '[전화번호]', $value);
        $value = preg_replace('/\b\d{6}[\s\-]?[1-4]\d{6}\b/', '[주민번호]', $value);
        $value = preg_replace('/\b\d{10,20}\b/', '[긴 숫자]', $value);
        $value = preg_replace('/\s+/u', ' ', $value);
        return $this->shortText(trim($value), $length);
    }

    private function shortText($value, $length)
    {
        $value = trim((string)$value);
        $length = max(1, (int)$length);
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $length, 'UTF-8');
        }
        return substr($value, 0, $length);
    }

    private function contains($haystack, $needle)
    {
        if ($needle === '') {
            return false;
        }

        if (function_exists('mb_strpos')) {
            return mb_strpos($haystack, $needle, 0, 'UTF-8') !== false;
        }

        return strpos($haystack, $needle) !== false;
    }

    private function lower($value)
    {
        $value = (string)$value;
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }
        return strtolower($value);
    }

    private function stringLength($value)
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen((string)$value, 'UTF-8');
        }
        return strlen((string)$value);
    }
}
