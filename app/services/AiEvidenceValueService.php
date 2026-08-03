<?php
/**
 * Builds PHP-owned evidence display values and validates numeric GPT text.
 * PHP 5.6 compatible.
 */
namespace App\Services;

class AiEvidenceValueService
{
    private static $valueTypes = array(
        'MONEY_KRW'=>true,
        'PERCENT'=>true,
        'COUNT'=>true,
        'SCORE'=>true,
        'RATE'=>true,
        'DATE'=>true,
        'DATETIME'=>true,
        'RANGE'=>true,
        'TEXT'=>true
    );

    private static function addUnique(&$values, $value)
    {
        $value = trim((string)$value);
        if ($value !== '' && !in_array($value, $values, true)) $values[] = $value;
    }

    private static function numberText($value, $maxDecimals)
    {
        if (!is_numeric($value)) return '';
        $number = (float)$value;
        if (!is_finite($number)) return '';
        $maxDecimals = max(0, min(8, (int)$maxDecimals));
        $text = number_format($number, $maxDecimals, '.', '');
        if (strpos($text, '.') !== false) $text = rtrim(rtrim($text, '0'), '.');
        if ($text === '-0') $text = '0';
        return $text;
    }

    private static function integerValue($value, &$integer)
    {
        if (!is_numeric($value)) return false;
        $number = (float)$value;
        if (!is_finite($number)) return false;
        $rounded = round($number);
        if (abs($number - $rounded) > 0.000001 || abs($rounded) > PHP_INT_MAX) return false;
        $integer = (int)$rounded;
        return true;
    }

    private static function signedText($negative, $value)
    {
        return $negative ? '-' . $value : $value;
    }

    private static function eokDecimal($integer)
    {
        $negative = $integer < 0;
        $absolute = abs($integer);
        $whole = (int)floor($absolute / 100000000);
        $remainder = $absolute % 100000000;
        $text = (string)$whole;
        if ($remainder > 0) {
            $fraction = str_pad((string)$remainder, 8, '0', STR_PAD_LEFT);
            $fraction = rtrim($fraction, '0');
            if ($fraction !== '') $text .= '.' . $fraction;
        }
        return self::signedText($negative, $text) . '억원';
    }

    private static function koreanMoneyParts($integer)
    {
        if ($integer === 0 || abs($integer) % 10000 !== 0) return '';
        $negative = $integer < 0;
        $absolute = abs($integer);
        $eok = (int)floor($absolute / 100000000);
        $man = (int)(($absolute % 100000000) / 10000);
        $text = '';
        if ($eok > 0) $text .= number_format($eok) . '억';
        if ($man > 0) $text .= ($text !== '' ? ' ' : '') . number_format($man) . '만원';
        else if ($eok > 0) $text .= '원';
        if ($text === '') return '';
        return self::signedText($negative, $text);
    }

    private static function moneyValues($value)
    {
        $values = array();
        if (!is_numeric($value)) return $values;
        $integer = 0;
        if (!self::integerValue($value, $integer)) {
            $text = self::numberText($value, 2);
            if ($text !== '') self::addUnique($values, number_format((float)$text, strpos($text, '.') === false ? 0 : strlen(substr(strrchr($text, '.'), 1)), '.', ',') . '원');
            return $values;
        }
        $absolute = abs($integer);
        $full = number_format($integer) . '원';
        $parts = self::koreanMoneyParts($integer);
        $eok = $absolute >= 10000000 ? self::eokDecimal($integer) : '';
        if ($absolute >= 100000000 && $eok !== '') self::addUnique($values, $eok);
        else if ($parts !== '') self::addUnique($values, $parts);
        else self::addUnique($values, $full);
        self::addUnique($values, $full);
        self::addUnique($values, $parts);
        self::addUnique($values, $eok);
        if ($integer < 0) {
            $positive = self::moneyValues(abs($integer));
            foreach ($positive as $item) {
                self::addUnique($values, $item . ' 적자');
                self::addUnique($values, $item . ' 손실');
            }
        }
        return $values;
    }

    private static function percentValues($value, $options)
    {
        $values = array();
        if (!is_numeric($value)) return $values;
        $scale = isset($options['percent_scale']) ? strtoupper(trim((string)$options['percent_scale'])) : 'PERCENT';
        $percent = (float)$value;
        if ($scale === 'RATIO') $percent *= 100;
        $text = self::numberText($percent, 3);
        if ($text === '') return $values;
        self::addUnique($values, $text . '%');
        if (strpos($text, '.') === false) self::addUnique($values, $text . '.0%');
        return $values;
    }

    private static function countValues($value, $unit)
    {
        $values = array();
        $integer = 0;
        if (!self::integerValue($value, $integer)) return $values;
        $unit = trim((string)$unit);
        if ($unit === '') $unit = '개';
        self::addUnique($values, number_format($integer) . $unit);
        return $values;
    }

    private static function scoreValues($value, $unit)
    {
        $values = array();
        if (!is_numeric($value)) return $values;
        $unit = trim((string)$unit);
        if ($unit === '') $unit = '점';
        $text = self::numberText($value, 3);
        if ($text === '') return $values;
        self::addUnique($values, $text . $unit);
        if (strpos($text, '.') === false) self::addUnique($values, $text . '.0' . $unit);
        return $values;
    }

    private static function dateValues($value, $withTime)
    {
        $values = array();
        $value = trim((string)$value);
        if ($value === '') return $values;
        if ($withTime && preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/', $value, $m)) {
            self::addUnique($values, $value);
            self::addUnique($values, $m[1] . '년 ' . (int)$m[2] . '월 ' . (int)$m[3] . '일 ' . $m[4] . ':' . $m[5]);
            return $values;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            self::addUnique($values, $value);
            self::addUnique($values, $m[1] . '.' . $m[2] . '.' . $m[3]);
            self::addUnique($values, $m[1] . '년 ' . (int)$m[2] . '월 ' . (int)$m[3] . '일');
            return $values;
        }
        if (preg_match('/^(\d{4})-(\d{2})$/', $value, $m)) {
            self::addUnique($values, $value);
            self::addUnique($values, $m[1] . '년 ' . (int)$m[2] . '월');
            return $values;
        }
        return $values;
    }

    public static function inferValueType($value, $unit)
    {
        $unit = trim((string)$unit);
        if ($unit === '원') return 'MONEY_KRW';
        if ($unit === '%') return 'PERCENT';
        if ($unit === '점') return 'SCORE';
        if (in_array($unit, array('개','건','개월','회','명'), true)) return 'COUNT';
        $text = trim((string)$value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', $text)) return 'DATETIME';
        if (preg_match('/^\d{4}-\d{2}(?:-\d{2})?$/', $text)) return 'DATE';
        return 'TEXT';
    }

    public static function buildEvidence($idKey, $id, $label, $rawValue, $unit, $valueType, $options)
    {
        $idKey = $idKey === 'metric_id' ? 'metric_id' : 'evidence_id';
        $type = strtoupper(trim((string)$valueType));
        if (!isset(self::$valueTypes[$type])) $type = self::inferValueType($rawValue, $unit);
        $values = array();
        if ($rawValue !== null) {
            if ($type === 'MONEY_KRW') $values = self::moneyValues($rawValue);
            else if ($type === 'PERCENT' || $type === 'RATE') $values = self::percentValues($rawValue, is_array($options) ? $options : array());
            else if ($type === 'COUNT') $values = self::countValues($rawValue, $unit);
            else if ($type === 'SCORE') $values = self::scoreValues($rawValue, $unit);
            else if ($type === 'DATE') $values = self::dateValues($rawValue, false);
            else if ($type === 'DATETIME') $values = self::dateValues($rawValue, true);
            else self::addUnique($values, (string)$rawValue . (string)$unit);
        }
        return array(
            $idKey=>(string)$id,
            'label'=>(string)$label,
            'value'=>$rawValue,
            'unit'=>(string)$unit,
            'raw_value'=>$rawValue,
            'value_type'=>$type,
            'display_value'=>count($values) ? $values[0] : '',
            'allowed_display_values'=>$values
        );
    }

    public static function metric($id, $label, $rawValue, $unit, $valueType, $options)
    {
        return self::buildEvidence('metric_id', $id, $label, $rawValue, $unit, $valueType, $options);
    }

    public static function evidence($id, $label, $rawValue, $unit, $valueType, $options)
    {
        return self::buildEvidence('evidence_id', $id, $label, $rawValue, $unit, $valueType, $options);
    }

    private static function preferredMoney($value)
    {
        $values = self::moneyValues($value);
        return count($values) ? $values[0] : '';
    }

    public static function moneyRange($idKey, $id, $label, $low, $high)
    {
        $lowText = self::preferredMoney($low);
        $highText = self::preferredMoney($high);
        $values = array();
        if ($lowText !== '' && $highText !== '') {
            $shortLow = preg_replace('/억원$/u', '억', $lowText);
            if (!is_string($shortLow) || $shortLow === '') $shortLow = $lowText;
            self::addUnique($values, $shortLow . '~' . $highText);
            self::addUnique($values, $shortLow . ' ~ ' . $highText);
            self::addUnique($values, $lowText . '에서 ' . $highText);
            self::addUnique($values, $lowText . '부터 ' . $highText);
        }
        foreach (self::moneyValues($low) as $item) self::addUnique($values, $item);
        foreach (self::moneyValues($high) as $item) self::addUnique($values, $item);
        $idKey = $idKey === 'metric_id' ? 'metric_id' : 'evidence_id';
        return array(
            $idKey=>(string)$id,
            'label'=>(string)$label,
            'value'=>array('low'=>$low,'high'=>$high),
            'unit'=>'원',
            'raw_value'=>array('low'=>$low,'high'=>$high),
            'value_type'=>'RANGE',
            'display_value'=>count($values) ? $values[0] : '',
            'allowed_display_values'=>$values
        );
    }

    public static function map($evidence)
    {
        $map = array();
        if (!is_array($evidence)) return $map;
        foreach ($evidence as $key=>$row) {
            if (!is_array($row)) continue;
            $id = '';
            if (isset($row['evidence_id'])) $id = (string)$row['evidence_id'];
            else if (isset($row['metric_id'])) $id = (string)$row['metric_id'];
            else if (!is_int($key)) $id = (string)$key;
            if ($id !== '') $map[$id] = $row;
        }
        return $map;
    }

    private static function failure($code, $message, $path, $invalid)
    {
        return array(
            'ok'=>false,
            'error_code'=>(string)$code,
            'message'=>(string)$message,
            'field_path'=>substr((string)$path, 0, 190),
            'invalid_number'=>substr((string)$invalid, 0, 80)
        );
    }

    public static function validateEvidenceMap($evidenceMap)
    {
        $evidenceMap = self::map($evidenceMap);
        foreach ($evidenceMap as $id=>$row) {
            if (!isset($row['value_type']) || !isset(self::$valueTypes[(string)$row['value_type']])) return self::failure('DISPLAY_VALUE_VALIDATION_FAILED', '근거 표시값 형식을 확인하지 못했습니다.', 'evidence.' . $id, '');
            if (!array_key_exists('raw_value', $row) || !isset($row['allowed_display_values']) || !is_array($row['allowed_display_values']) || !isset($row['display_value'])) return self::failure('DISPLAY_VALUE_VALIDATION_FAILED', '근거 표시값 형식을 확인하지 못했습니다.', 'evidence.' . $id, '');
            if ((string)$row['display_value'] !== '' && !in_array((string)$row['display_value'], $row['allowed_display_values'], true)) return self::failure('DISPLAY_VALUE_VALIDATION_FAILED', '근거 표시값 형식을 확인하지 못했습니다.', 'evidence.' . $id . '.display_value', '');
            foreach ($row['allowed_display_values'] as $display) if (!is_string($display) || strlen($display) > 190) return self::failure('DISPLAY_VALUE_VALIDATION_FAILED', '근거 표시값 형식을 확인하지 못했습니다.', 'evidence.' . $id . '.allowed_display_values', '');
        }
        return array('ok'=>true, 'error_code'=>'', 'message'=>'근거 표시값을 확인했습니다.');
    }

    private static function longerFirst($left, $right)
    {
        $a = strlen((string)$left);
        $b = strlen((string)$right);
        if ($a === $b) return 0;
        return $a > $b ? -1 : 1;
    }

    private static function firstNumberToken($text)
    {
        if (preg_match('/[-+]?\d[\d,]*(?:\.\d+)?(?:\s*(?:억원|억|만원|만|원|%|점|개|건|개월|회|명|년|월|일))?/u', (string)$text, $match)) return isset($match[0]) ? $match[0] : '';
        return '';
    }

    public static function validateSegments($segments, $evidence, $projects)
    {
        $map = self::map($evidence);
        $displayCheck = self::validateEvidenceMap($map);
        if (empty($displayCheck['ok'])) return $displayCheck;
        if (!is_array($segments)) return self::failure('SCHEMA_VALIDATION_FAILED', '응답 검증 대상을 확인하지 못했습니다.', '', '');
        if (!is_array($projects)) $projects = array();
        foreach ($segments as $segment) {
            if (!is_array($segment) || !isset($segment['text']) || !is_string($segment['text'])) continue;
            $text = $segment['text'];
            if (!preg_match('/\d/u', $text)) continue;
            $path = isset($segment['path']) ? (string)$segment['path'] : '';
            $ids = isset($segment['evidence_ids']) && is_array($segment['evidence_ids']) ? $segment['evidence_ids'] : array();
            if (count($ids) === 0) return self::failure('UNPROVIDED_NUMBER_FAILED', 'OpenAI 응답에 근거와 연결되지 않은 숫자가 포함되어 있습니다.', $path, self::firstNumberToken($text));
            $allowed = array();
            foreach ($ids as $id) {
                $id = (string)$id;
                if (!isset($map[$id])) return self::failure('EVIDENCE_VALIDATION_FAILED', 'OpenAI 응답에 확인할 수 없는 근거 ID가 포함되어 있습니다.', $path, '');
                self::addUnique($allowed, $id);
                if (isset($map[$id]['display_value'])) self::addUnique($allowed, $map[$id]['display_value']);
                if (isset($map[$id]['allowed_display_values']) && is_array($map[$id]['allowed_display_values'])) foreach ($map[$id]['allowed_display_values'] as $display) self::addUnique($allowed, $display);
            }
            $projectIds = isset($segment['project_ids']) && is_array($segment['project_ids']) ? $segment['project_ids'] : array();
            foreach ($projectIds as $projectId) {
                $projectId = (int)$projectId;
                if (!isset($projects[$projectId])) continue;
                self::addUnique($allowed, (string)$projects[$projectId]);
                self::addUnique($allowed, 'project_id: ' . $projectId);
                self::addUnique($allowed, 'project_id ' . $projectId);
                self::addUnique($allowed, '현장 ID ' . $projectId);
                self::addUnique($allowed, '현장번호 ' . $projectId);
            }
            usort($allowed, array(__CLASS__, 'longerFirst'));
            foreach ($allowed as $display) if ($display !== '') $text = str_replace($display, ' ', $text);
            if (preg_match('/\d/u', $text)) return self::failure('UNPROVIDED_NUMBER_FAILED', 'OpenAI 응답에 허용되지 않은 숫자 표현이 포함되어 있습니다.', $path, self::firstNumberToken($text));
        }
        return array('ok'=>true, 'error_code'=>'', 'message'=>'응답 숫자 표시값을 확인했습니다.');
    }
}
?>
