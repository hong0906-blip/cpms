<?php
/**
 * C:\www\cpms\app\services\ExcelWorkerImporter.php
 * - 근로자명단.xlsx 읽기
 * - 3행 헤더, 4행부터 데이터
 * - PHPExcel 1.8 우선 사용, 없으면 .xlsx만 ZipArchive로 보조 처리
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/CryptoHelper.php';

if (!class_exists('ExcelWorkerImporter')) {
class ExcelWorkerImporter
{
    public function defaultMapping()
    {
        return array(
            'import_no' => 1,
            'resident_no' => 2,
            'name' => 3,
            'job_type' => 4,
            'agency_name' => 0,
            'birth_date' => 5,
            'phone' => 6,
            'address' => 7,
            'daily_wage' => 8,
            'account_holder' => 9,
            'bank_name' => 10,
            'bank_account' => 11,
            'memo' => 12,
        );
    }

    public function fieldLabels()
    {
        return array(
            'import_no' => '순번',
            'resident_no' => '주민번호',
            'name' => '성명',
            'job_type' => '구분/직종',
            'agency_name' => '인력사 업체명',
            'birth_date' => '생년월일',
            'phone' => '연락처',
            'address' => '집주소',
            'daily_wage' => '단가',
            'account_holder' => '예금주',
            'bank_name' => '은행명',
            'bank_account' => '계좌',
            'memo' => '비고',
        );
    }

    public function columnOptions()
    {
        return array(
            0 => '사용 안 함',
            1 => 'A: 순번',
            2 => 'B: 주민번호',
            3 => 'C: 성명',
            4 => 'D: 구분',
            5 => 'E: 생년월일',
            6 => 'F: 연락처',
            7 => 'G: 집주소',
            8 => 'H: 단가',
            9 => 'I: 예금주',
            10 => 'J: 은행명',
            11 => 'K: 계좌',
            12 => 'L: 비고',
        );
    }

    public function normalizeMapping($mapping)
    {
        $base = $this->defaultMapping();
        if (!is_array($mapping)) return $base;
        foreach ($base as $field => $defaultCol) {
            if (!isset($mapping[$field])) continue;
            $col = $this->columnToIndex($mapping[$field]);
            if ($col < 0 || $col > 50) $col = 0;
            $base[$field] = $col;
        }
        return $base;
    }

    public function parse($filePath, $mapping, $defaultAgencyName)
    {
        $result = array(
            'error' => '',
            'sheet_name' => '',
            'header' => array(),
            'rows' => array(),
            'raw_total_rows' => 0,
        );

        if (!is_file($filePath)) {
            $result['error'] = '엑셀 파일을 찾을 수 없습니다.';
            return $result;
        }

        $mapping = $this->normalizeMapping($mapping);
        $sheet = $this->readSheet($filePath);
        if (!is_array($sheet) || (isset($sheet['error']) && $sheet['error'] !== '')) {
            $result['error'] = is_array($sheet) && isset($sheet['error']) ? (string)$sheet['error'] : '엑셀 읽기에 실패했습니다.';
            return $result;
        }

        $rows = isset($sheet['rows']) && is_array($sheet['rows']) ? $sheet['rows'] : array();
        $result['sheet_name'] = isset($sheet['sheet_name']) ? (string)$sheet['sheet_name'] : '';
        $result['header'] = isset($rows[3]) && is_array($rows[3]) ? $rows[3] : array();

        $maxRow = 0;
        foreach ($rows as $rowNo => $rowCells) {
            if ((int)$rowNo > $maxRow) $maxRow = (int)$rowNo;
        }

        for ($rowNo = 4; $rowNo <= $maxRow; $rowNo++) {
            $cells = isset($rows[$rowNo]) && is_array($rows[$rowNo]) ? $rows[$rowNo] : array();
            $hasAnyValue = false;
            foreach ($cells as $cellValue) {
                if (trim((string)$cellValue) !== '') {
                    $hasAnyValue = true;
                    break;
                }
            }
            if (!$hasAnyValue) continue;

            $result['raw_total_rows']++;
            $data = array();
            foreach ($mapping as $field => $colNo) {
                $data[$field] = $colNo > 0 ? $this->cell($cells, $colNo) : '';
            }

            $defaultAgencyName = trim((string)$defaultAgencyName);
            if ($defaultAgencyName !== '') {
                $data['agency_name'] = $defaultAgencyName;
            }

            $data = $this->normalizeRow($data);
            $result['rows'][] = array(
                'row_no' => $rowNo,
                'data' => $data,
                'raw' => $cells,
            );
        }

        return $result;
    }

    public function normalizeRow($data)
    {
        if (!is_array($data)) $data = array();

        $dailyWage = $this->normalizeMoney(isset($data['daily_wage']) ? $data['daily_wage'] : '');
        $phone = trim((string)(isset($data['phone']) ? $data['phone'] : ''));
        $birthDate = $this->normalizeDate(isset($data['birth_date']) ? $data['birth_date'] : '');

        return array(
            'import_no' => trim((string)(isset($data['import_no']) ? $data['import_no'] : '')),
            'resident_no' => $this->normalizeSensitiveText(isset($data['resident_no']) ? $data['resident_no'] : '', true),
            'name' => trim((string)(isset($data['name']) ? $data['name'] : '')),
            'job_type' => trim((string)(isset($data['job_type']) ? $data['job_type'] : '')),
            'agency_name' => trim((string)(isset($data['agency_name']) ? $data['agency_name'] : '')),
            'birth_date' => $birthDate,
            'phone' => CryptoHelper::formatPhone($phone),
            'phone_digits' => CryptoHelper::normalizePhoneDigits($phone),
            'address' => trim((string)(isset($data['address']) ? $data['address'] : '')),
            'daily_wage' => $dailyWage,
            'account_holder' => trim((string)(isset($data['account_holder']) ? $data['account_holder'] : '')),
            'bank_name' => trim((string)(isset($data['bank_name']) ? $data['bank_name'] : '')),
            'bank_account' => $this->normalizeSensitiveText(isset($data['bank_account']) ? $data['bank_account'] : '', false),
            'memo' => trim((string)(isset($data['memo']) ? $data['memo'] : '')),
            'source_type' => 'excel',
            'is_active' => 1,
        );
    }

    public function normalizeMoney($value)
    {
        $raw = trim((string)$value);
        $raw = str_replace(array(',', ' ', "\t", '원'), '', $raw);
        $raw = preg_replace('/[^0-9\-]/', '', $raw);
        if ($raw === '' || !is_numeric($raw)) return 0;
        $num = (int)$raw;
        return $num < 0 ? 0 : $num;
    }

    public function normalizeSensitiveText($value, $isResidentNo)
    {
        $raw = trim((string)$value);
        if ($raw === '') return '';

        $raw = str_replace(array("\xC2\xA0", '　'), ' ', $raw);
        $raw = trim($raw);

        if (preg_match('/^[+-]?\d+(?:\.\d+)?[eE][+-]?\d+$/', $raw)) {
            $raw = sprintf('%.0f', (float)$raw);
        }
        if (preg_match('/^\d+\.0+$/', $raw)) {
            $raw = (string)((float)$raw);
            if (preg_match('/^[+-]?\d+(?:\.\d+)?[eE][+-]?\d+$/', $raw)) {
                $raw = sprintf('%.0f', (float)$raw);
            }
            $raw = preg_replace('/\.0+$/', '', $raw);
        }

        $raw = trim($raw);
        if ($isResidentNo) {
            $digits = CryptoHelper::normalizeDigits($raw);
            if (strlen($digits) === 13 && strpos($raw, '-') === false) {
                return substr($digits, 0, 6) . '-' . substr($digits, 6);
            }
        }

        return $raw;
    }

    public function normalizeDate($value)
    {
        $raw = trim((string)$value);
        if ($raw === '') return null;

        if (preg_match('/^\d+\.0+$/', $raw)) $raw = (string)((int)$raw);

        if (preg_match('/^\d{4}[-\.\/]\d{1,2}[-\.\/]\d{1,2}$/', $raw)) {
            $raw = str_replace(array('.', '/'), '-', $raw);
            $parts = explode('-', $raw);
            $y = (int)$parts[0];
            $m = (int)$parts[1];
            $d = (int)$parts[2];
            if (checkdate($m, $d, $y)) return sprintf('%04d-%02d-%02d', $y, $m, $d);
        }

        if (preg_match('/^\d{8}$/', $raw)) {
            $y2 = (int)substr($raw, 0, 4);
            $m2 = (int)substr($raw, 4, 2);
            $d2 = (int)substr($raw, 6, 2);
            if (checkdate($m2, $d2, $y2)) return sprintf('%04d-%02d-%02d', $y2, $m2, $d2);
        }

        if (preg_match('/^\d+$/', $raw)) {
            $serial = (int)$raw;
            if ($serial > 20000 && $serial < 60000) {
                try {
                    $dt = new DateTime('1899-12-30');
                    $dt->modify('+' . $serial . ' days');
                    return $dt->format('Y-m-d');
                } catch (Exception $e) {
                    return null;
                }
            }
        }

        return null;
    }

    private function readSheet($filePath)
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $this->loadPHPExcel();
        if (class_exists('PHPExcel_IOFactory')) {
            return $this->readWithPHPExcel($filePath);
        }
        if ($ext === 'xls') {
            return array('error' => '.xls 파일을 읽으려면 PHPExcel 1.8 라이브러리가 필요합니다. .xlsx 파일을 사용해주세요.');
        }
        return $this->readWithZipArchive($filePath);
    }

    private function loadPHPExcel()
    {
        if (class_exists('PHPExcel_IOFactory')) return;
        $root = dirname(dirname(__DIR__));
        $candidates = array(
            $root . '/app/libraries/PHPExcel/PHPExcel.php',
            $root . '/app/libraries/PHPExcel/PHPExcel/IOFactory.php',
            $root . '/vendor/phpoffice/phpexcel/Classes/PHPExcel.php',
            $root . '/vendor/phpoffice/phpexcel/Classes/PHPExcel/IOFactory.php',
        );
        foreach ($candidates as $file) {
            if (is_file($file)) {
                require_once $file;
            }
        }
    }

    private function readWithPHPExcel($filePath)
    {
        $result = array('error' => '', 'rows' => array(), 'sheet_name' => '');
        try {
            $reader = PHPExcel_IOFactory::createReaderForFile($filePath);
            $excel = $reader->load($filePath);
            $sheet = $excel->getSheetByName('근로자명단');
            if (!$sheet) $sheet = $excel->getSheet(0);
            $result['sheet_name'] = $sheet->getTitle();
            $highestRow = (int)$sheet->getHighestRow();
            $highestColumnIndex = min(50, PHPExcel_Cell::columnIndexFromString($sheet->getHighestColumn()));

            for ($r = 1; $r <= $highestRow; $r++) {
                $result['rows'][$r] = array();
                for ($c = 1; $c <= $highestColumnIndex; $c++) {
                    $cell = $sheet->getCellByColumnAndRow($c - 1, $r);
                    $result['rows'][$r][$c] = $this->phpExcelCellValue($cell);
                }
            }
        } catch (Exception $e) {
            $result['error'] = '엑셀 파일을 읽는 중 오류가 발생했습니다: ' . $e->getMessage();
        }
        return $result;
    }

    private function phpExcelCellValue($cell)
    {
        $value = '';
        try {
            if (method_exists($cell, 'getFormattedValue')) {
                $value = $cell->getFormattedValue();
            }
        } catch (Exception $e) {
            $value = '';
        }

        if (trim((string)$value) === '') {
            try {
                $value = $cell->getCalculatedValue();
            } catch (Exception $e2) {
                $value = $cell->getValue();
            }
        }

        return trim((string)$value);
    }

    private function readWithZipArchive($filePath)
    {
        $result = array('error' => '', 'rows' => array(), 'sheet_name' => '');
        if (!class_exists('ZipArchive')) {
            $result['error'] = '서버에 ZipArchive 확장 모듈이 없어 .xlsx 파일을 읽을 수 없습니다.';
            return $result;
        }

        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            $result['error'] = '엑셀 파일을 열 수 없습니다.';
            return $result;
        }

        $sheetList = $this->xlsxSheetList($zip);
        $target = null;
        foreach ($sheetList as $sheet) {
            if ((string)$sheet['name'] === '근로자명단') {
                $target = $sheet;
                break;
            }
        }
        if (!$target && count($sheetList) > 0) $target = $sheetList[0];
        if (!$target) {
            $zip->close();
            $result['error'] = '엑셀 시트를 찾을 수 없습니다.';
            return $result;
        }

        $sharedStrings = $this->xlsxSharedStrings($zip);
        $sheetXml = $zip->getFromName($target['path']);
        if ($sheetXml === false) {
            $zip->close();
            $result['error'] = '엑셀 시트 데이터를 찾을 수 없습니다.';
            return $result;
        }

        $sx = $this->xlsxLoadXml($sheetXml);
        if (!$sx || !isset($sx->sheetData)) {
            $zip->close();
            $result['error'] = '엑셀 시트 파싱에 실패했습니다.';
            return $result;
        }

        foreach ($sx->sheetData->row as $row) {
            $rowNo = isset($row['r']) ? (int)$row['r'] : 0;
            if ($rowNo <= 0) continue;
            if (!isset($result['rows'][$rowNo])) $result['rows'][$rowNo] = array();
            if (!isset($row->c)) continue;
            foreach ($row->c as $cell) {
                $ref = isset($cell['r']) ? (string)$cell['r'] : '';
                $colNo = $this->columnToIndex($ref);
                if ($colNo <= 0 || $colNo > 50) continue;
                $result['rows'][$rowNo][$colNo] = $this->xlsxCellValue($cell, $sharedStrings);
            }
        }

        $result['sheet_name'] = isset($target['name']) ? (string)$target['name'] : '';
        $zip->close();
        return $result;
    }

    private function xlsxSharedStrings($zip)
    {
        $strings = array();
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) return $strings;
        $sx = $this->xlsxLoadXml($xml);
        if (!$sx) return $strings;
        foreach ($sx->si as $si) {
            $strings[] = $this->xlsxStringItemText($si);
        }
        return $strings;
    }

    private function xlsxSheetList($zip)
    {
        $sheets = array();
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbookXml === false || $relsXml === false) return $sheets;

        $wb = $this->xlsxLoadXml($workbookXml);
        $rels = $this->xlsxLoadXml($relsXml);
        if (!$wb || !$rels || !isset($wb->sheets)) return $sheets;

        $targets = array();
        foreach ($rels->Relationship as $rel) {
            $rid = (string)$rel['Id'];
            $target = (string)$rel['Target'];
            if ($rid === '' || $target === '') continue;
            $target = str_replace('\\', '/', $target);
            $target = preg_replace('#^\.\./#', '', $target);
            $target = ltrim($target, '/');
            if (strpos($target, 'xl/') !== 0) $target = 'xl/' . $target;
            $targets[$rid] = $target;
        }

        foreach ($wb->sheets->sheet as $sheet) {
            $rid = isset($sheet['id']) ? (string)$sheet['id'] : '';
            $name = (string)$sheet['name'];
            if ($rid !== '' && isset($targets[$rid])) {
                $sheets[] = array('name' => $name, 'path' => $targets[$rid]);
            }
        }
        return $sheets;
    }

    private function xlsxCellValue($cell, $sharedStrings)
    {
        $type = isset($cell['t']) ? (string)$cell['t'] : '';
        if ($type === 's') {
            $idx = isset($cell->v) ? (int)$cell->v : -1;
            return ($idx >= 0 && isset($sharedStrings[$idx])) ? trim((string)$sharedStrings[$idx]) : '';
        }
        if ($type === 'inlineStr') {
            if (isset($cell->is)) return trim($this->xlsxStringItemText($cell->is));
            return '';
        }
        if ($type === 'str' && isset($cell->v)) return trim((string)$cell->v);
        return isset($cell->v) ? trim((string)$cell->v) : '';
    }

    private function xlsxLoadXml($xml)
    {
        $xml = $this->xlsxStripNamespaces((string)$xml);
        return @simplexml_load_string($xml);
    }

    private function xlsxStripNamespaces($xml)
    {
        $xml = preg_replace('/\sxmlns(:[A-Za-z0-9_\\-]+)?="[^"]*"/', '', (string)$xml);
        $xml = preg_replace('/(<\\/?)([A-Za-z0-9_\\-]+):/', '$1', $xml);
        $xml = preg_replace('/\\s([A-Za-z0-9_\\-]+):([A-Za-z0-9_\\-]+)=/', ' $2=', $xml);
        return $xml;
    }

    private function xlsxStringItemText($item)
    {
        $text = '';
        if (isset($item->t)) {
            $text .= (string)$item->t;
        }
        if (isset($item->r)) {
            foreach ($item->r as $run) {
                if (isset($run->t)) $text .= (string)$run->t;
            }
        }
        return $text;
    }

    private function cell($cells, $colNo)
    {
        return isset($cells[$colNo]) ? trim((string)$cells[$colNo]) : '';
    }

    private function columnToIndex($value)
    {
        if (is_numeric($value)) return (int)$value;
        $letters = preg_replace('/[^A-Z]/', '', strtoupper((string)$value));
        if ($letters === '') return 0;
        $num = 0;
        $len = strlen($letters);
        for ($i = 0; $i < $len; $i++) {
            $num = $num * 26 + (ord($letters[$i]) - 64);
        }
        return (int)$num;
    }
}
}
