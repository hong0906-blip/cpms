<?php
/**
 * C:\www\cpms\app\core\EstimateXlsxReader.php
 * - PHP 5.6용 XLSX 시트명으로 읽기(ZipArchive + SimpleXML)
 * - "견적 내역서" 탭에서 행/열 값을 뽑아내기 위한 기반
 *
 * 제한:
 * - 수식 계산은 하지 않고, 셀의 v 값만 읽음
 */

namespace App\Core;

class EstimateXlsxReader
{
    public static function readSheetByName($filePath, $sheetName, $maxRows)
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
            $result['error'] = '엑셀 파일을 열 수 없습니다.';
            return $result;
        }

        // sharedStrings
        $sharedStrings = array();
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedXml !== false) {
            $sx = @simplexml_load_string($sharedXml);
            if ($sx) {
                foreach ($sx->si as $si) {
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

        // workbook.xml 에서 시트명 찾기
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        if ($workbookXml === false) {
            $zip->close();
            $result['error'] = 'workbook.xml을 찾을 수 없습니다.';
            return $result;
        }

        $wb = @simplexml_load_string($workbookXml);
        if (!$wb || !isset($wb->sheets)) {
            $zip->close();
            $result['error'] = 'workbook.xml 파싱 실패';
            return $result;
        }

        $targetRid = null;

        foreach ($wb->sheets->sheet as $sh) {
            $nm = (string)$sh['name'];
            if ($nm === (string)$sheetName) {
                $attrs = $sh->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
                $targetRid = isset($attrs['id']) ? (string)$attrs['id'] : null;
                break;
            }
        }

        // 시트명 정확히 안 맞으면(공백/접두어 등) 포함검색
        if ($targetRid === null) {
            foreach ($wb->sheets->sheet as $sh) {
                $nm = (string)$sh['name'];
                if ($nm !== '' && mb_strpos($nm, (string)$sheetName) !== false) {
                    $attrs = $sh->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
                    $targetRid = isset($attrs['id']) ? (string)$attrs['id'] : null;
                    break;
                }
            }
        }

        if ($targetRid === null) {
            $zip->close();
            $result['error'] = '시트("' . $sheetName . '")를 찾을 수 없습니다.';
            return $result;
        }

        // rels에서 rid -> sheet xml 경로 찾기
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($relsXml === false) {
            $zip->close();
            $result['error'] = 'workbook.xml.rels를 찾을 수 없습니다.';
            return $result;
        }

        $rels = @simplexml_load_string($relsXml);
        if (!$rels) {
            $zip->close();
            $result['error'] = 'rels 파싱 실패';
            return $result;
        }

        $sheetPath = null;
        foreach ($rels->Relationship as $rel) {
            if ((string)$rel['Id'] === (string)$targetRid) {
                $sheetPath = 'xl/' . (string)$rel['Target']; // worksheets/sheet2.xml
                $sheetPath = str_replace('\\', '/', $sheetPath);
                break;
            }
        }

        if ($sheetPath === null) {
            $zip->close();
            $result['error'] = '시트 XML 경로를 찾지 못했습니다.';
            return $result;
        }

        $sheetXml = $zip->getFromName($sheetPath);
        if ($sheetXml === false) {
            $zip->close();
            $result['error'] = '시트 XML을 열 수 없습니다: ' . $sheetPath;
            return $result;
        }

        $sxSheet = @simplexml_load_string($sheetXml);
        if (!$sxSheet || !isset($sxSheet->sheetData)) {
            $zip->close();
            $result['error'] = '시트 파싱 실패';
            return $result;
        }

        $rowsOut = array();
        $rowCount = 0;

        foreach ($sxSheet->sheetData->row as $row) {
            $rowCount++;
            if ($rowCount > (int)$maxRows) break;

            $cells = array(); // colIndex(1-based) => value
            if (isset($row->c)) {
                foreach ($row->c as $c) {
                    $r = isset($c['r']) ? (string)$c['r'] : '';
                    $colIndex = self::colRefToIndex($r); // A1 -> 1
                    $t = isset($c['t']) ? (string)$c['t'] : '';
                    $v = '';

                    if ($t === 's') {
                        $idx = isset($c->v) ? (int)$c->v : -1;
                        $v = ($idx >= 0 && isset($sharedStrings[$idx])) ? $sharedStrings[$idx] : '';
                    } else if ($t === 'inlineStr') {
                        if (isset($c->is) && isset($c->is->t)) $v = (string)$c->is->t;
                    } else {
                        $v = isset($c->v) ? (string)$c->v : '';
                    }

                    if ($colIndex > 0) $cells[$colIndex] = $v;
                }
            }

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

    private static function colRefToIndex($cellRef)
    {
        if (!is_string($cellRef) || $cellRef === '') return 0;

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
