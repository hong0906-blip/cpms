<?php
/**
 * C:\www\cpms\app\core\SimpleXlsxReader.php
 * - PHP 5.6에서 .xlsx(Office Open XML) 파일을 "최소 기능"으로 읽기
 * - 1) 첫 번째 시트(sheet1.xml) 기준
 * - 2) 문자열(sharedStrings) 지원
 * - 3) 헤더(1행) + 데이터(2행~) 읽기 용도
 *
 * 제한:
 * - .xls(구버전)는 지원하지 않음
 * - 수식은 값(v)만 읽으며, 계산은 하지 않음
 *
 * PHP 5.6 호환
 */

namespace App\Core;

class SimpleXlsxReader
{
    /**
     * @param string $filePath 업로드된 .xlsx 경로
     * @param int $maxRows 최대 읽을 행 수(안전장치)
     * @return array array('rows' => array(array(...)), 'error' => string|null)
     */
    public static function readFirstSheet($filePath, $maxRows)
    {
        $result = array('rows' => array(), 'error' => null);

        if (!is_string($filePath) || $filePath === '' || !file_exists($filePath)) {
            $result['error'] = '파일을 찾을 수 없습니다.';
            return $result;
        }

        if (!class_exists('ZipArchive')) {
            $result['error'] = '서버에 ZipArchive 확장 모듈이 없습니다. (.xlsx 읽기 불가)';
            return $result;
        }

        $zip = new \ZipArchive();
        if ($zip->open($filePath) !== true) {
            $result['error'] = '엑셀 파일을 열 수 없습니다. (손상되었거나 형식이 다릅니다)';
            return $result;
        }

        // 1) sharedStrings.xml (문자열 테이블)
        $sharedStrings = array();
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedXml !== false) {
            $sx = @simplexml_load_string($sharedXml);
            if ($sx) {
                foreach ($sx->si as $si) {
                    // si 안에 t가 여러 개 있을 수 있어 연결 처리
                    $text = '';
                    if (isset($si->t)) {
                        $text = (string)$si->t;
                    } else if (isset($si->r)) {
                        foreach ($si->r as $run) {
                            if (isset($run->t)) $text .= (string)$run->t;
                        }
                    }
                    $sharedStrings[] = $text;
                }
            }
        }

        // 2) 첫 번째 시트: 우선 sheet1.xml 시도
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        if ($sheetXml === false) {
            // 혹시 sheet2.xml 등만 있을 때: 가장 먼저 발견되는 sheet*.xml 찾기
            $sheetXml = false;
            for ($i = 1; $i <= 10; $i++) {
                $tmp = $zip->getFromName('xl/worksheets/sheet' . $i . '.xml');
                if ($tmp !== false) { $sheetXml = $tmp; break; }
            }
        }

        if ($sheetXml === false) {
            $zip->close();
            $result['error'] = '엑셀 시트 데이터를 찾을 수 없습니다.';
            return $result;
        }

        $sxSheet = @simplexml_load_string($sheetXml);
        if (!$sxSheet || !isset($sxSheet->sheetData)) {
            $zip->close();
            $result['error'] = '엑셀 시트 파싱에 실패했습니다.';
            return $result;
        }

        $rowsOut = array();
        $rowCount = 0;

        foreach ($sxSheet->sheetData->row as $row) {
            $rowCount++;
            if ($rowCount > (int)$maxRows) break;

            $cells = array(); // colIndex(int) => value
            if (isset($row->c)) {
                foreach ($row->c as $c) {
                    $r = isset($c['r']) ? (string)$c['r'] : '';
                    $colIndex = self::colRefToIndex($r); // A1 -> 1
                    $t = isset($c['t']) ? (string)$c['t'] : '';
                    $v = '';

                    if ($t === 's') {
                        // shared string
                        $idx = isset($c->v) ? (int)$c->v : -1;
                        $v = ($idx >= 0 && isset($sharedStrings[$idx])) ? $sharedStrings[$idx] : '';
                    } else if ($t === 'inlineStr') {
                        if (isset($c->is) && isset($c->is->t)) $v = (string)$c->is->t;
                    } else {
                        // number / general
                        $v = isset($c->v) ? (string)$c->v : '';
                    }

                    if ($colIndex > 0) {
                        $cells[$colIndex] = $v;
                    }
                }
            }

            // 최대 컬럼까지 채우는 '순서형 배열'로 변환
            $maxCol = 0;
            foreach ($cells as $k => $vv) { if ($k > $maxCol) $maxCol = $k; }

            $rowArr = array();
            for ($i = 1; $i <= $maxCol; $i++) {
                $rowArr[] = isset($cells[$i]) ? $cells[$i] : '';
            }
            $rowsOut[] = $rowArr;
        }

        $zip->close();
        $result['rows'] = $rowsOut;
        return $result;
    }

    /**
     * 셀 참조("AB12")에서 열 인덱스(1-based) 추출
     * @param string $cellRef
     * @return int
     */
    private static function colRefToIndex($cellRef)
    {
        if (!is_string($cellRef) || $cellRef === '') return 0;

        // 문자만 추출: AB12 -> AB
        $letters = preg_replace('/[^A-Z]/', '', strtoupper($cellRef));
        if ($letters === '') return 0;

        $len = strlen($letters);
        $num = 0;
        for ($i = 0; $i < $len; $i++) {
            $num = $num * 26 + (ord($letters[$i]) - 64);
        }
        return (int)$num;
    }
}