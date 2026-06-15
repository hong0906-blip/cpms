<?php
/**
 * C:\www\cpms\app\services\EquipmentExcelImporter.php
 * - 장비비 엑셀(2.장비비 시트)을 미리보기 데이터로 변환하는 서비스
 * - PHP 5.6 호환: array() 문법, type hint/return type 미사용
 */

require_once __DIR__ . '/../views/construction/partials/master_dedupe_helper.php';

if (!class_exists('EquipmentExcelImporter')) {
class EquipmentExcelImporter
{
    private $pdo;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * 업로드된 장비비 엑셀을 읽어서 미리보기 배열로 만든다.
     *
     * @param string $filePath
     * @param int $projectId
     * @param string $baseYearMonth YYYY-MM
     * @return array
     */
    public function parse($filePath, $projectId, $baseYearMonth)
    {
        $result = array(
            'error' => '',
            'sheet_name' => '',
            'warnings' => array(),
            'summary' => array(
                'total_count' => 0,
                'valid_count' => 0,
                'error_count' => 0,
                'warning_count' => 0,
                'duplicate_count' => 0,
                'update_count' => 0,
                'total_amount' => 0
            ),
            'rows' => array()
        );

        if (!preg_match('/^\d{4}-\d{2}$/', (string)$baseYearMonth)) {
            $result['error'] = '기준년월이 올바르지 않습니다. 예: 2026-06';
            return $result;
        }

        if (!is_file($filePath)) {
            $result['error'] = '업로드된 엑셀 파일을 찾을 수 없습니다.';
            return $result;
        }

        $sheetResult = $this->readEquipmentSheet($filePath);
        if (isset($sheetResult['error']) && $sheetResult['error'] !== '') {
            $result['error'] = $sheetResult['error'];
            return $result;
        }

        $sheetRows = isset($sheetResult['rows']) && is_array($sheetResult['rows']) ? $sheetResult['rows'] : array();
        $result['sheet_name'] = isset($sheetResult['sheet_name']) ? (string)$sheetResult['sheet_name'] : '';

        $dateWarnings = array();
        $dateMap = $this->readDateHeaders($sheetRows, $baseYearMonth, $dateWarnings);
        $result['warnings'] = $dateWarnings;

        $topCount = isset($dateMap['top']) && is_array($dateMap['top']) ? count($dateMap['top']) : 0;
        $bottomCount = isset($dateMap['bottom']) && is_array($dateMap['bottom']) ? count($dateMap['bottom']) : 0;
        if (($topCount + $bottomCount) <= 0) {
            $result['error'] = '날짜 헤더를 하나도 읽지 못했습니다. 2행/3행의 날짜 영역을 확인해주세요.';
            return $result;
        }

        $result['rows'] = $this->parseEquipmentRows($sheetRows, $dateMap, $projectId, $baseYearMonth);
        $this->fillSummary($result);

        return $result;
    }

    /**
     * 날짜 헤더를 읽는다.
     * - 상단 데이터행은 J~AD의 2행 날짜를 사용한다.
     * - 하단 데이터행은 P~AE의 3행 날짜를 사용한다.
     */
    public function readDateHeaders($sheetRows, $baseYearMonth, &$warnings)
    {
        $warnings = array();
        $dateMap = array('top' => array(), 'bottom' => array());

        for ($col = 10; $col <= 31; $col++) {
            if ($col <= 30) {
                $warning = '';
                $dateValue = $this->headerCellToDate($this->getCell($sheetRows, 2, $col), $baseYearMonth, 'top', $warning);
                if ($dateValue !== '') {
                    $dateMap['top'][$col] = $dateValue;
                } else if ($warning !== '') {
                    $warnings[count($warnings)] = $this->columnLetter($col) . '2: ' . $warning;
                }
            }

            if ($col >= 16) {
                $warning = '';
                $dateValue = $this->headerCellToDate($this->getCell($sheetRows, 3, $col), $baseYearMonth, 'bottom', $warning);
                if ($dateValue !== '') {
                    $dateMap['bottom'][$col] = $dateValue;
                } else if ($warning !== '') {
                    $warnings[count($warnings)] = $this->columnLetter($col) . '3: ' . $warning;
                }
            }
        }

        return $dateMap;
    }

    /**
     * 4~61행을 2줄 단위로 읽고, 날짜별 금액을 미리보기 row로 변환한다.
     */
    public function parseEquipmentRows($sheetRows, $dateMap, $projectId, $baseYearMonth)
    {
        $previewRows = array();
        $seenKeys = array();
        $lastCategory = '';

        for ($topRow = 4; $topRow <= 61; $topRow += 2) {
            $bottomRow = $topRow + 1;
            if ($bottomRow > 61) {
                break;
            }

            $categoryError = false;
            $categoryValue = $this->getCleanCellValue($sheetRows, $topRow, 2, $categoryError);
            if ($this->isStopRow($categoryValue)) {
                break;
            }

            if ($categoryValue !== '' && !$this->isRowMarker($categoryValue)) {
                $lastCategory = $categoryValue;
            }
            $equipmentCategory = $lastCategory;

            $info = $this->readEquipmentInfo($sheetRows, $topRow, $bottomRow, $projectId, $equipmentCategory);

            $this->appendAmountRows($previewRows, $seenKeys, $sheetRows, $dateMap, $info, $projectId, $topRow, 'top');
            $this->appendAmountRows($previewRows, $seenKeys, $sheetRows, $dateMap, $info, $projectId, $bottomRow, 'bottom');
        }

        return $previewRows;
    }

    /**
     * 셀 값을 공백/오류값 방어 처리 후 문자열로 반환한다.
     */
    public function getCleanCellValue($sheetRows, $rowNo, $colNo, &$hadError)
    {
        $hadError = false;
        $cell = $this->getCell($sheetRows, $rowNo, $colNo);
        $value = isset($cell['value']) ? trim((string)$cell['value']) : '';

        if ($this->isErrorValue($value) || (isset($cell['formula_error']) && $cell['formula_error'])) {
            $hadError = true;
            return '';
        }

        return $value;
    }

    /**
     * 금액 셀을 숫자로 바꾼다.
     * - 빈값/0/문자/오류값: null
     * - 음수: 음수 숫자를 반환해서 validateRow에서 오류 처리
     */
    public function normalizeAmount($value)
    {
        $raw = trim((string)$value);
        if ($raw === '' || $this->isErrorValue($raw)) {
            return null;
        }

        $raw = str_replace(array(',', ' ', "\t", '원'), '', $raw);
        $raw = preg_replace('/[^0-9.\-]/', '', $raw);
        if ($raw === '' || $raw === '-' || $raw === '.' || !is_numeric($raw)) {
            return null;
        }

        $amount = (float)$raw;
        if (abs($amount) < 0.0001) {
            return null;
        }

        return $amount;
    }

    public function normalizeBusinessNo($value)
    {
        $digits = preg_replace('/[^0-9]/', '', (string)$value);
        if ($digits === '' || (int)$digits === 0) {
            return '';
        }
        if (strlen($digits) === 10) {
            return substr($digits, 0, 3) . '-' . substr($digits, 3, 2) . '-' . substr($digits, 5);
        }
        return $digits;
    }

    public function isErrorValue($value)
    {
        $v = strtoupper(trim((string)$value));
        $errors = array(
            '#NAME?' => true,
            '#REF!' => true,
            '#VALUE!' => true,
            '#DIV/0!' => true,
            '#N/A' => true,
            '#NUM!' => true,
            '#NULL!' => true
        );
        return isset($errors[$v]);
    }

    /**
     * 사업자번호가 있으면 우선 매칭하고, 없으면 기존 장비 중 같은 정보가 있는지 찾는다.
     */
    public function findExistingEquipment($projectId, $businessNo, $equipmentCategory, $spec, $vendorName, $baseRate)
    {
        if (!$this->pdo || (int)$projectId <= 0) {
            return null;
        }

        $targetBiz = cpms_master_dedupe_biz_key($businessNo);
        if ($targetBiz !== '') {
            try {
                $st = $this->pdo->prepare("SELECT * FROM cpms_equipment_items WHERE project_id = :pid AND is_deleted = 0 ORDER BY id ASC");
                $st->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
                $st->execute();
                $rows = $st->fetchAll(PDO::FETCH_ASSOC);
                if (is_array($rows)) {
                    $targetCategory = cpms_master_dedupe_text_key($equipmentCategory);
                    $targetSpec = cpms_master_dedupe_text_key($spec);
                    foreach ($rows as $row) {
                        $rowBiz = cpms_master_dedupe_biz_key(isset($row['biz_no']) ? $row['biz_no'] : '');
                        if ($rowBiz !== $targetBiz) {
                            continue;
                        }
                        $rowCategory = cpms_master_dedupe_text_key(isset($row['category']) ? $row['category'] : '');
                        $rowSpec = cpms_master_dedupe_text_key(isset($row['spec']) ? $row['spec'] : '');
                        if ($targetCategory !== '' && $rowCategory !== '' && $targetCategory !== $rowCategory) {
                            continue;
                        }
                        if ($targetSpec !== '' && $rowSpec !== '' && $targetSpec !== $rowSpec) {
                            continue;
                        }
                        return $row;
                    }
                }
            } catch (Exception $e) {
                return null;
            }
        }

        return cpms_find_existing_equipment_item($this->pdo, $projectId, $equipmentCategory, $vendorName, $spec, $businessNo, $baseRate);
    }

    /**
     * 이미 등록된 같은 장비/일자가 있는지 확인한다.
     */
    public function findDuplicateEquipmentDaily($projectId, $workDate, $equipmentCategory, $businessNo, $spec, $vendorName, $equipmentId)
    {
        if (!$this->pdo || (int)$projectId <= 0 || $workDate === '') {
            return null;
        }

        try {
            if ((int)$equipmentId > 0) {
                $st = $this->pdo->prepare("SELECT * FROM cpms_equipment_usage WHERE project_id = :pid AND equipment_id = :eid AND use_date = :use_date LIMIT 1");
                $st->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
                $st->bindValue(':eid', (int)$equipmentId, PDO::PARAM_INT);
                $st->bindValue(':use_date', $workDate);
                $st->execute();
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    return $row;
                }
            }

            $sql = "SELECT u.*, i.category, i.vendor_name, i.spec, i.biz_no
                      FROM cpms_equipment_usage u
                      JOIN cpms_equipment_items i ON i.id = u.equipment_id
                     WHERE u.project_id = :pid
                       AND u.use_date = :use_date
                       AND i.is_deleted = 0
                       AND i.category = :category";
            $st2 = $this->pdo->prepare($sql);
            $st2->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
            $st2->bindValue(':use_date', $workDate);
            $st2->bindValue(':category', (string)$equipmentCategory);
            $st2->execute();
            $rows = $st2->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows)) {
                return null;
            }

            $targetBiz = cpms_master_dedupe_biz_key($businessNo);
            $targetSpec = cpms_master_dedupe_text_key($spec);
            $targetVendor = cpms_master_dedupe_text_key($vendorName);
            foreach ($rows as $row) {
                if ($targetSpec !== cpms_master_dedupe_text_key(isset($row['spec']) ? $row['spec'] : '')) {
                    continue;
                }
                if ($targetBiz !== '') {
                    if ($targetBiz === cpms_master_dedupe_biz_key(isset($row['biz_no']) ? $row['biz_no'] : '')) {
                        return $row;
                    }
                    continue;
                }
                if ($targetVendor !== '' && $targetVendor === cpms_master_dedupe_text_key(isset($row['vendor_name']) ? $row['vendor_name'] : '')) {
                    return $row;
                }
            }
        } catch (Exception $e) {
            return null;
        }

        return null;
    }

    public function validateRow($row)
    {
        $errors = array();
        $warnings = array();

        if (!isset($row['project_id']) || (int)$row['project_id'] <= 0) {
            $errors[count($errors)] = '프로젝트 정보가 없습니다.';
        }
        if (!isset($row['work_date']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$row['work_date'])) {
            $errors[count($errors)] = '사용일자가 올바르지 않습니다.';
        }
        if (!isset($row['equipment_category']) || trim((string)$row['equipment_category']) === '') {
            $errors[count($errors)] = '장비 구분이 없습니다.';
        }
        if (!isset($row['amount']) || (float)$row['amount'] <= 0) {
            $errors[count($errors)] = '금액은 0보다 커야 합니다.';
        }
        if (isset($row['amount']) && (float)$row['amount'] < 0) {
            $errors[count($errors)] = '금액은 음수일 수 없습니다.';
        }

        if (!isset($row['vendor_name']) || trim((string)$row['vendor_name']) === '') {
            $warnings[count($warnings)] = '업체명이 비어 있습니다.';
        }
        if (!isset($row['equipment_spec']) || trim((string)$row['equipment_spec']) === '') {
            $warnings[count($warnings)] = '규격이 비어 있습니다.';
        }
        if (!isset($row['business_no']) || trim((string)$row['business_no']) === '') {
            $warnings[count($warnings)] = '사업자등록번호가 비어 있습니다.';
        }

        return array('errors' => $errors, 'warnings' => $warnings);
    }

    private function readEquipmentInfo($sheetRows, $topRow, $bottomRow, $projectId, $equipmentCategory)
    {
        $formulaWarnings = array();

        $vendorError = false;
        $specError = false;
        $repError = false;
        $phoneError = false;
        $bizError = false;
        $baseError = false;
        $memoTopError = false;
        $memoBottomError = false;

        $vendorName = $this->getCleanCellValue($sheetRows, $topRow, 3, $vendorError);
        $spec = $this->getCleanCellValue($sheetRows, $topRow, 4, $specError);
        $representative = $this->getCleanCellValue($sheetRows, $topRow, 5, $repError);
        $phone = $this->getCleanCellValue($sheetRows, $topRow, 6, $phoneError);
        $businessNo = $this->normalizeBusinessNo($this->getCleanCellValue($sheetRows, $topRow, 7, $bizError));
        $basePriceValue = $this->getCleanCellValue($sheetRows, $topRow, 8, $baseError);
        $memo = $this->getCleanCellValue($sheetRows, $topRow, 34, $memoTopError);
        if ($memo === '') {
            $memo = $this->getCleanCellValue($sheetRows, $bottomRow, 34, $memoBottomError);
        }

        if ($vendorError) $formulaWarnings[count($formulaWarnings)] = '업체명 수식 오류값은 빈 값으로 처리했습니다.';
        if ($specError) $formulaWarnings[count($formulaWarnings)] = '규격 수식 오류값은 빈 값으로 처리했습니다.';
        if ($repError) $formulaWarnings[count($formulaWarnings)] = '대표자명 수식 오류값은 빈 값으로 처리했습니다.';
        if ($phoneError) $formulaWarnings[count($formulaWarnings)] = '전화번호 수식 오류값은 빈 값으로 처리했습니다.';
        if ($bizError) $formulaWarnings[count($formulaWarnings)] = '사업자등록번호 오류값은 빈 값으로 처리했습니다.';

        $basePrice = $this->normalizeAmount($basePriceValue);
        if ($basePrice === null || $basePrice < 0) {
            $basePrice = 0;
        }

        $existingEquipment = $this->findExistingEquipment($projectId, $businessNo, $equipmentCategory, $spec, $vendorName, $basePrice);
        $equipmentId = 0;
        if (is_array($existingEquipment) && isset($existingEquipment['id'])) {
            $equipmentId = (int)$existingEquipment['id'];
            if ($vendorName === '' && isset($existingEquipment['vendor_name'])) $vendorName = (string)$existingEquipment['vendor_name'];
            if ($spec === '' && isset($existingEquipment['spec'])) $spec = (string)$existingEquipment['spec'];
            if ($representative === '' && isset($existingEquipment['representative'])) $representative = (string)$existingEquipment['representative'];
            if ($phone === '' && isset($existingEquipment['phone'])) $phone = (string)$existingEquipment['phone'];
            if ($businessNo === '' && isset($existingEquipment['biz_no'])) $businessNo = (string)$existingEquipment['biz_no'];
            if ($basePrice <= 0 && isset($existingEquipment['base_rate'])) $basePrice = (float)$existingEquipment['base_rate'];
            if ($memo === '' && isset($existingEquipment['remark'])) $memo = (string)$existingEquipment['remark'];
        } else {
            $formulaWarnings[count($formulaWarnings)] = '기존 장비 마스터와 매칭되지 않았습니다. 저장 시 신규 장비로 등록됩니다.';
        }

        return array(
            'project_id' => (int)$projectId,
            'equipment_category' => (string)$equipmentCategory,
            'vendor_name' => $vendorName,
            'equipment_spec' => $spec,
            'representative' => $representative,
            'phone' => $phone,
            'business_no' => $businessNo,
            'base_price' => $basePrice,
            'memo' => $memo,
            'equipment_id' => $equipmentId,
            'warnings' => $formulaWarnings
        );
    }

    private function appendAmountRows(&$previewRows, &$seenKeys, $sheetRows, $dateMap, $info, $projectId, $rowNo, $lineType)
    {
        $lineDateMap = isset($dateMap[$lineType]) && is_array($dateMap[$lineType]) ? $dateMap[$lineType] : array();

        for ($col = 10; $col <= 31; $col++) {
            // 요청 양식 기준:
            // - 상단 행은 J~AD(전월 26~말일 + 당월 1~15)
            // - 하단 행은 P~AE(당월 16~31)
            // 일부 오래된 엑셀 파일은 병합/숨김 셀의 캐시값이 J~O 하단, AE 상단에 남아 있어
            // 실제 화면에 보이지 않는 값을 금액으로 오인하지 않도록 사용 범위만 읽는다.
            if ($lineType === 'top' && $col > 30) {
                continue;
            }
            if ($lineType === 'bottom' && $col < 16) {
                continue;
            }

            $cell = $this->getCell($sheetRows, $rowNo, $col);
            $rawValue = isset($cell['value']) ? (string)$cell['value'] : '';

            if ($this->isErrorValue($rawValue) || (isset($cell['formula_error']) && $cell['formula_error'])) {
                $amount = null;
                continue;
            }

            $amount = $this->normalizeAmount($rawValue);
            if ($amount === null) {
                continue;
            }

            $row = array(
                'status_type' => 'new',
                'status' => '신규',
                'saveable' => 1,
                'project_id' => (int)$projectId,
                'work_date' => '',
                'equipment_category' => isset($info['equipment_category']) ? (string)$info['equipment_category'] : '',
                'vendor_name' => isset($info['vendor_name']) ? (string)$info['vendor_name'] : '',
                'business_no' => isset($info['business_no']) ? (string)$info['business_no'] : '',
                'equipment_spec' => isset($info['equipment_spec']) ? (string)$info['equipment_spec'] : '',
                'representative' => isset($info['representative']) ? (string)$info['representative'] : '',
                'phone' => isset($info['phone']) ? (string)$info['phone'] : '',
                'base_price' => isset($info['base_price']) ? (float)$info['base_price'] : 0,
                'amount' => $amount,
                'memo' => isset($info['memo']) ? (string)$info['memo'] : '',
                'equipment_id' => isset($info['equipment_id']) ? (int)$info['equipment_id'] : 0,
                'source_row' => (int)$rowNo,
                'source_col' => $this->columnLetter($col),
                'errors' => array(),
                'warnings' => isset($info['warnings']) && is_array($info['warnings']) ? $info['warnings'] : array()
            );

            if (!isset($lineDateMap[$col])) {
                $row['status_type'] = 'error';
                $row['status'] = '오류 - 날짜 헤더가 없는 칸에 금액이 있습니다.';
                $row['saveable'] = 0;
                $row['errors'][count($row['errors'])] = $this->columnLetter($col) . $rowNo . ' 셀은 날짜를 알 수 없어 저장할 수 없습니다.';
                $previewRows[count($previewRows)] = $row;
                continue;
            }
            $row['work_date'] = $lineDateMap[$col];

            $validation = $this->validateRow($row);
            foreach ($validation['errors'] as $message) {
                $row['errors'][count($row['errors'])] = $message;
            }
            foreach ($validation['warnings'] as $message) {
                $row['warnings'][count($row['warnings'])] = $message;
            }

            if (count($row['errors']) > 0) {
                $row['status_type'] = 'error';
                $row['status'] = '오류 - ' . implode(' / ', $row['errors']);
                $row['saveable'] = 0;
                $previewRows[count($previewRows)] = $row;
                continue;
            }

            $duplicateKey = $this->previewDuplicateKey($row);
            if (isset($seenKeys[$duplicateKey])) {
                $row['status_type'] = 'duplicate';
                $row['status'] = '중복 제외 - 미리보기 안에서 같은 장비/일자가 중복되었습니다.';
                $row['saveable'] = 0;
                $previewRows[count($previewRows)] = $row;
                continue;
            }

            $existingUsage = $this->findDuplicateEquipmentDaily(
                $projectId,
                $row['work_date'],
                $row['equipment_category'],
                $row['business_no'],
                $row['equipment_spec'],
                $row['vendor_name'],
                $row['equipment_id']
            );
            if (is_array($existingUsage) && isset($existingUsage['id'])) {
                $row['status_type'] = 'update';
                $row['status'] = '기존 업데이트';
                $row['existing_usage_id'] = (int)$existingUsage['id'];
            }

            $seenKeys[$duplicateKey] = true;
            $previewRows[count($previewRows)] = $row;
        }
    }

    private function fillSummary(&$result)
    {
        $summary = array(
            'total_count' => 0,
            'valid_count' => 0,
            'error_count' => 0,
            'warning_count' => 0,
            'duplicate_count' => 0,
            'update_count' => 0,
            'total_amount' => 0
        );

        $rows = isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : array();
        foreach ($rows as $row) {
            $summary['total_count']++;
            $statusType = isset($row['status_type']) ? (string)$row['status_type'] : '';
            if ($statusType === 'error') $summary['error_count']++;
            if ($statusType === 'duplicate') $summary['duplicate_count']++;
            if ($statusType === 'update') $summary['update_count']++;
            if (isset($row['warnings']) && is_array($row['warnings']) && count($row['warnings']) > 0) $summary['warning_count']++;
            if (isset($row['saveable']) && (int)$row['saveable'] === 1) {
                $summary['valid_count']++;
                $summary['total_amount'] += isset($row['amount']) ? (float)$row['amount'] : 0;
            }
        }

        $result['summary'] = $summary;
    }

    private function readEquipmentSheet($filePath)
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if ($ext === 'xls') {
            return $this->readWithPHPExcel($filePath);
        }
        return $this->readWithZipArchive($filePath, '2.장비비');
    }

    private function readWithPHPExcel($filePath)
    {
        $root = dirname(dirname(__DIR__));
        $phpExcel = $root . '/app/libraries/PHPExcel/PHPExcel.php';
        $phpExcelIo = $root . '/app/libraries/PHPExcel/PHPExcel/IOFactory.php';
        if (is_file($phpExcel)) require_once $phpExcel;
        if (is_file($phpExcelIo)) require_once $phpExcelIo;

        if (!class_exists('PHPExcel_IOFactory')) {
            return array('error' => '.xls 파일을 읽으려면 PHPExcel 1.8.1 라이브러리가 필요합니다. 현재 서버에서는 .xlsx 파일을 업로드해주세요.');
        }

        try {
            $reader = PHPExcel_IOFactory::createReaderForFile($filePath);
            $excel = $reader->load($filePath);
            $sheet = $excel->getSheetByName('2.장비비');
            if (!$sheet) {
                return array('error' => '2.장비비 시트를 찾을 수 없습니다.');
            }

            $rows = array();
            for ($r = 1; $r <= 65; $r++) {
                for ($c = 1; $c <= 34; $c++) {
                    $cell = $sheet->getCellByColumnAndRow($c - 1, $r);
                    $value = '';
                    $formulaError = false;
                    try {
                        $value = $cell->getCalculatedValue();
                    } catch (Exception $e) {
                        $formulaError = true;
                        $value = '';
                    }
                    if ($this->isErrorValue($value)) {
                        $formulaError = true;
                    }
                    if (!isset($rows[$r])) $rows[$r] = array();
                    $rows[$r][$c] = array(
                        'value' => (string)$value,
                        'formula_error' => $formulaError,
                        'type' => '',
                        'style' => '',
                        'is_date' => (class_exists('PHPExcel_Shared_Date') && PHPExcel_Shared_Date::isDateTime($cell)) ? 1 : 0
                    );
                }
            }

            return array('error' => '', 'rows' => $rows, 'sheet_name' => '2.장비비');
        } catch (Exception $e) {
            return array('error' => '엑셀 파일을 읽는 중 오류가 발생했습니다: ' . $e->getMessage());
        }
    }

    private function readWithZipArchive($filePath, $preferredSheetName)
    {
        $result = array('error' => '', 'rows' => array(), 'sheet_name' => '');

        if (!class_exists('ZipArchive')) {
            $result['error'] = '서버에 ZipArchive 확장 모듈이 없어 .xlsx 파일을 읽을 수 없습니다.';
            return $result;
        }

        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            $result['error'] = '엑셀 파일을 열 수 없습니다. 파일이 손상되었거나 xlsx 형식이 아닙니다.';
            return $result;
        }

        $sheetList = $this->xlsxSheetList($zip);
        $target = null;
        foreach ($sheetList as $sheet) {
            if ((string)$sheet['name'] === (string)$preferredSheetName) {
                $target = $sheet;
                break;
            }
        }
        if (!$target) {
            $zip->close();
            $result['error'] = '2.장비비 시트를 찾을 수 없습니다.';
            return $result;
        }

        $sheetXml = $zip->getFromName($target['path']);
        if ($sheetXml === false) {
            $zip->close();
            $result['error'] = '2.장비비 시트 데이터를 찾을 수 없습니다.';
            return $result;
        }

        $sharedStrings = $this->xlsxSharedStrings($zip);
        $sx = @simplexml_load_string($sheetXml);
        if (!$sx || !isset($sx->sheetData)) {
            $zip->close();
            $result['error'] = '2.장비비 시트 파싱에 실패했습니다.';
            return $result;
        }

        $rows = array();
        foreach ($sx->sheetData->row as $row) {
            $rowNo = isset($row['r']) ? (int)$row['r'] : 0;
            if ($rowNo <= 0 || $rowNo > 65) {
                continue;
            }
            if (!isset($rows[$rowNo])) {
                $rows[$rowNo] = array();
            }
            if (!isset($row->c)) {
                continue;
            }
            foreach ($row->c as $cell) {
                $ref = isset($cell['r']) ? (string)$cell['r'] : '';
                $col = $this->columnRefToIndex($ref);
                if ($col <= 0 || $col > 34) {
                    continue;
                }
                $rows[$rowNo][$col] = $this->xlsxCellInfo($cell, $sharedStrings);
            }
        }

        $zip->close();
        $result['rows'] = $rows;
        $result['sheet_name'] = isset($target['name']) ? (string)$target['name'] : '';
        return $result;
    }

    private function xlsxSharedStrings($zip)
    {
        $strings = array();
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return $strings;
        }
        $sx = @simplexml_load_string($xml);
        if (!$sx) {
            return $strings;
        }
        foreach ($sx->si as $si) {
            $text = '';
            if (isset($si->t)) {
                $text = (string)$si->t;
            } else if (isset($si->r)) {
                foreach ($si->r as $run) {
                    if (isset($run->t)) $text .= (string)$run->t;
                }
            }
            $strings[count($strings)] = $text;
        }
        return $strings;
    }

    private function xlsxSheetList($zip)
    {
        $sheets = array();
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbookXml === false || $relsXml === false) {
            return $sheets;
        }

        $wb = @simplexml_load_string($workbookXml);
        $rels = @simplexml_load_string($relsXml);
        if (!$wb || !$rels || !isset($wb->sheets)) {
            return $sheets;
        }

        $targets = array();
        foreach ($rels->Relationship as $rel) {
            $rid = (string)$rel['Id'];
            $target = (string)$rel['Target'];
            if ($rid === '' || $target === '') {
                continue;
            }
            $target = str_replace('\\', '/', $target);
            $target = preg_replace('#^\.\./#', '', $target);
            $target = ltrim($target, '/');
            if (strpos($target, 'xl/') !== 0) {
                $target = 'xl/' . $target;
            }
            $targets[$rid] = $target;
        }

        foreach ($wb->sheets->sheet as $sheet) {
            $attrs = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $rid = isset($attrs['id']) ? (string)$attrs['id'] : '';
            $name = (string)$sheet['name'];
            if ($rid !== '' && isset($targets[$rid])) {
                $sheets[count($sheets)] = array('name' => $name, 'path' => $targets[$rid]);
            }
        }

        return $sheets;
    }

    private function xlsxCellInfo($cell, $sharedStrings)
    {
        $type = isset($cell['t']) ? (string)$cell['t'] : '';
        $style = isset($cell['s']) ? (string)$cell['s'] : '';
        $hasFormula = isset($cell->f);
        $formulaError = false;
        $value = '';

        if ($type === 's') {
            $idx = isset($cell->v) ? (int)$cell->v : -1;
            $value = ($idx >= 0 && isset($sharedStrings[$idx])) ? $sharedStrings[$idx] : '';
        } else if ($type === 'inlineStr') {
            if (isset($cell->is) && isset($cell->is->t)) {
                $value = (string)$cell->is->t;
            }
        } else {
            $value = isset($cell->v) ? (string)$cell->v : '';
        }

        if ($type === 'e' || $this->isErrorValue($value)) {
            $formulaError = true;
        }
        if ($hasFormula && trim((string)$value) === '') {
            $formulaError = true;
        }

        return array(
            'value' => trim((string)$value),
            'formula_error' => $formulaError,
            'type' => $type,
            'style' => $style,
            'is_date' => 0
        );
    }

    private function headerCellToDate($cell, $baseYearMonth, $lineType, &$warning)
    {
        $warning = '';
        $value = isset($cell['value']) ? trim((string)$cell['value']) : '';
        if ($value === '') {
            return '';
        }
        if ($this->isErrorValue($value) || (isset($cell['formula_error']) && $cell['formula_error'])) {
            $warning = '날짜 헤더에 엑셀 수식 오류값이 있습니다.';
            return '';
        }

        if (preg_match('/^\d{4}[-\/]\d{1,2}[-\/]\d{1,2}$/', $value)) {
            $value = str_replace('/', '-', $value);
            $parts = explode('-', $value);
            $year = (int)$parts[0];
            $month = (int)$parts[1];
            $day = (int)$parts[2];
            if (checkdate($month, $day, $year)) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
            $warning = $value . '은 존재하지 않는 날짜입니다.';
            return '';
        }

        $clean = str_replace(array(',', ' '), '', $value);
        if (preg_match('/^\d+\.0+$/', $clean)) {
            $clean = (string)((int)$clean);
        }
        if (!preg_match('/^\d+$/', $clean)) {
            $warning = '날짜 헤더가 숫자가 아닙니다.';
            return '';
        }

        $number = (int)$clean;
        if ($number > 31) {
            $serialDate = $this->excelSerialToDate($number);
            if ($serialDate !== '') {
                return $serialDate;
            }
            $warning = 'Excel 날짜값을 실제 날짜로 변환하지 못했습니다.';
            return '';
        }

        if ($number < 1 || $number > 31) {
            $warning = '일자는 1~31 범위여야 합니다.';
            return '';
        }

        $targetYm = $baseYearMonth;
        if ($lineType === 'top' && $number >= 26) {
            $targetYm = date('Y-m', strtotime($baseYearMonth . '-01 -1 month'));
        }
        $year = (int)substr($targetYm, 0, 4);
        $month = (int)substr($targetYm, 5, 2);
        if (!checkdate($month, $number, $year)) {
            $warning = $targetYm . '-' . sprintf('%02d', $number) . '은 존재하지 않는 날짜입니다.';
            return '';
        }

        return $targetYm . '-' . sprintf('%02d', $number);
    }

    private function excelSerialToDate($serial)
    {
        $serial = (int)$serial;
        if ($serial <= 0) {
            return '';
        }
        $timestamp = strtotime('1899-12-30 +' . $serial . ' days');
        if ($timestamp === false) {
            return '';
        }
        return date('Y-m-d', $timestamp);
    }

    private function getCell($sheetRows, $rowNo, $colNo)
    {
        if (isset($sheetRows[$rowNo]) && is_array($sheetRows[$rowNo]) && isset($sheetRows[$rowNo][$colNo])) {
            return $sheetRows[$rowNo][$colNo];
        }
        return array('value' => '', 'formula_error' => false, 'type' => '', 'style' => '', 'is_date' => 0);
    }

    private function previewDuplicateKey($row)
    {
        return implode('|', array(
            isset($row['work_date']) ? (string)$row['work_date'] : '',
            cpms_master_dedupe_text_key(isset($row['equipment_category']) ? $row['equipment_category'] : ''),
            cpms_master_dedupe_biz_key(isset($row['business_no']) ? $row['business_no'] : ''),
            cpms_master_dedupe_text_key(isset($row['equipment_spec']) ? $row['equipment_spec'] : ''),
            cpms_master_dedupe_text_key(isset($row['vendor_name']) ? $row['vendor_name'] : '')
        ));
    }

    private function isStopRow($value)
    {
        $compact = preg_replace('/\s+/u', '', trim((string)$value));
        return ($compact === '합계' || strpos($compact, '합계') !== false);
    }

    private function isRowMarker($value)
    {
        $value = trim((string)$value);
        return ($value === '1' || $value === '2');
    }

    private function columnRefToIndex($cellRef)
    {
        $letters = preg_replace('/[^A-Z]/', '', strtoupper((string)$cellRef));
        if ($letters === '') {
            return 0;
        }
        $num = 0;
        $len = strlen($letters);
        for ($i = 0; $i < $len; $i++) {
            $num = $num * 26 + (ord($letters[$i]) - 64);
        }
        return (int)$num;
    }

    private function columnLetter($colNo)
    {
        $colNo = (int)$colNo;
        $letters = '';
        while ($colNo > 0) {
            $mod = ($colNo - 1) % 26;
            $letters = chr(65 + $mod) . $letters;
            $colNo = (int)(($colNo - $mod) / 26);
        }
        return $letters;
    }
}
}
