<?php
/**
 * C:\www\cpms\app\core\XlsxLite.php
 * - PHP 5.6용 초경량 XLSX 파서(ZipArchive + SimpleXML)
 * - 특정 시트(견적 내역서)에서 필요한 컬럼만 추출
 *
 * 추출 컬럼(엑셀 기준):
 *  - 품명: C열
 *  - 단위: E열
 *  - 수량: F열
 *  - 합계단가: M열
 *  - 금액: N열
 */

namespace App\Core;

class XlsxLite
{
    /**
     * 견적 내역서 시트에서 품명/단위/수량/합계단가/금액 추출
     * @param string $xlsxPath
     * @param string $sheetName 기본: '견적 내역서'
     * @return array (rows)
     */
    public static function extractEstimateItems($xlsxPath, $sheetName = '견적 내역서')
    {
        if (!is_file($xlsxPath)) return array();

        $zip = new \ZipArchive();
        if ($zip->open($xlsxPath) !== true) return array();

        // sharedStrings 로드(문자열 테이블)
        $sharedStrings = array();
        $ssXml = self::zipRead($zip, 'xl/sharedStrings.xml');
        if ($ssXml !== '') {
            $sx = @simplexml_load_string($ssXml);
            if ($sx) {
                foreach ($sx->si as $si) {
                    // <si><t> 또는 <si><r><t> 형태 모두 대응
                    $text = '';
                    if (isset($si->t)) {
                        $text = (string)$si->t;
                    } elseif (isset($si->r)) {
                        foreach ($si->r as $r) {
                            if (isset($r->t)) $text .= (string)$r->t;
                        }
                    }
                    $sharedStrings[] = $text;
                }
            }
        }

        // workbook에서 "견적 내역서" 시트 찾기
        $workbookXml = self::zipRead($zip, 'xl/workbook.xml');
        if ($workbookXml === '') {
            $zip->close();
            return array();
        }

        $workbook = @simplexml_load_string($workbookXml);
        if (!$workbook || !isset($workbook->sheets)) {
            $zip->close();
            return array();
        }

        $targetRid = null;
        foreach ($workbook->sheets->sheet as $sh) {
            $name = (string)$sh['name'];
            if ($name === $sheetName) {
                // r:id 는 네임스페이스가 걸려있을 수 있음
                $attrs = $sh->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
                $targetRid = isset($attrs['id']) ? (string)$attrs['id'] : null;
                break;
            }
        }

        if ($targetRid === null) {
            // 시트명이 정확히 안 맞을 경우(공백 등) 느슨하게 한번 더 찾기
            foreach ($workbook->sheets->sheet as $sh) {
                $name = (string)$sh['name'];
                if (mb_strpos($name, $sheetName) !== false) {
                    $attrs = $sh->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
                    $targetRid = isset($attrs['id']) ? (string)$attrs['id'] : null;
                    break;
                }
            }
        }

        if ($targetRid === null) {
            $zip->close();
            return array();
        }

        // 관계 파일에서 rid -> sheet xml 경로 찾기
        $relsXml = self::zipRead($zip, 'xl/_rels/workbook.xml.rels');
        if ($relsXml === '') {
            $zip->close();
            return array();
        }

        $rels = @simplexml_load_string($relsXml);
        if (!$rels) {
            $zip->close();
            return array();
        }

        $targetSheetPath = null;
        foreach ($rels->Relationship as $rel) {
            if ((string)$rel['Id'] === $targetRid) {
                $targetSheetPath = 'xl/' . (string)$rel['Target']; // 예: worksheets/sheet2.xml
                $targetSheetPath = str_replace('\\', '/', $targetSheetPath);
                break;
            }
        }

        if ($targetSheetPath === null) {
            $zip->close();
            return array();
        }

        $sheetXml = self::zipRead($zip, $targetSheetPath);
        if ($sheetXml === '') {
            $zip->close();
            return array();
        }

        $sheet = @simplexml_load_string($sheetXml);
        if (!$sheet || !isset($sheet->sheetData)) {
            $zip->close();
            return array();
        }

        // 필요한 컬럼 (엑셀 기준)
        $needCols = array(
            'C' => 'item_name',       // 품명
            'E' => 'unit',            // 단위
            'F' => 'qty',             // 수량
            'M' => 'total_unit_price',// 합계단가
            'N' => 'amount',          // 금액
        );

        $rows = array();
        $foundDataOnce = false;
        $emptyStreak = 0;

        foreach ($sheet->sheetData->row as $rowNode) {
            $row = array(
                'item_name' => '',
                'unit' => '',
                'qty' => '',
                'total_unit_price' => '',
                'amount' => '',
            );

            if (!isset($rowNode->c)) continue;

            foreach ($rowNode->c as $c) {
                $r = (string)$c['r']; // 예: C17
                if ($r === '') continue;

                // 컬럼 문자만 추출(C, E, F, M, N)
                $col = preg_replace('/[^A-Z]/', '', $r);
                if (!isset($needCols[$col])) continue;

                $val = self::cellValue($c, $sharedStrings);
                $key = $needCols[$col];
                $row[$key] = $val;
            }

            // 행 필터링(실제 항목만)
            $item = trim($row['item_name']);
            $unit = trim($row['unit']);
            $qty  = trim($row['qty']);
            $amt  = trim($row['amount']);

            // 품명이 없으면 보통 헤더/공백/소계 -> 스킵
            if ($item === '') {
                if ($foundDataOnce) $emptyStreak++;
                if ($emptyStreak >= 50) break; // 데이터 끝으로 판단
                continue;
            }

            // 그룹 제목(예: "1. 건축공사") 같은 줄은 단위/수량/금액이 비는 경우가 많음 -> 스킵
            if ($unit === '' && $qty === '' && $amt === '') {
                if ($foundDataOnce) $emptyStreak++;
                if ($emptyStreak >= 50) break;
                continue;
            }

            // 정상 데이터로 판단
            $foundDataOnce = true;
            $emptyStreak = 0;

            $rows[] = $row;
        }

        $zip->close();
        return $rows;
    }

    /**
     * Zip에서 파일 읽기
     */
    private static function zipRead(\ZipArchive $zip, $name)
    {
        $idx = $zip->locateName($name);
        if ($idx === false) return '';
        $data = $zip->getFromIndex($idx);
        return ($data !== false) ? $data : '';
    }

    /**
     * 셀 값 추출(문자열/숫자/inlineStr 대응)
     */
    private static function cellValue($cellNode, $sharedStrings)
    {
        $t = isset($cellNode['t']) ? (string)$cellNode['t'] : '';
        // inlineStr
        if ($t === 'inlineStr' && isset($cellNode->is)) {
            $text = '';
            if (isset($cellNode->is->t)) {
                $text = (string)$cellNode->is->t;
            } elseif (isset($cellNode->is->r)) {
                foreach ($cellNode->is->r as $r) {
                    if (isset($r->t)) $text .= (string)$r->t;
                }
            }
            return trim($text);
        }

        // shared string
        if ($t === 's') {
            $idx = isset($cellNode->v) ? (int)$cellNode->v : -1;
            if ($idx >= 0 && isset($sharedStrings[$idx])) {
                return trim((string)$sharedStrings[$idx]);
            }
            return '';
        }

        // 일반 숫자/문자(대부분 <v> 사용)
        if (isset($cellNode->v)) {
            return trim((string)$cellNode->v);
        }

        return '';
    }
}
