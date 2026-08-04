<?php
/**
 * C:\www\cpms\app\views\admin\labor_consultant_labor_only_override.php
 *
 * 관리 > 노무비 계산 > 노무사 확인용 전용 데이터 조립
 * - 공사 > 노무비 > 노무비 탭에 표시되는 날짜와 공수만 사용
 * - 날짜로 선택한 외주비 기간은 일별 공수, 출력일수, 총공수에서 제외
 * - 전액 외주비 인원은 제외
 * - 비율 배분 인원은 공사 노무비 탭과 동일하게 전체 공수를 표시하고
 *   노무비 반영금액만 설정 비율로 계산
 * - PHP 5.6 호환
 */

if (!function_exists('cpms_labor_consultant_is_date_mode')) {
    function cpms_labor_consultant_is_date_mode($worker) {
        if (!is_array($worker)) return false;

        $ratio = function_exists('cpms_resolve_worker_outsourcing_ratio')
            ? (int)cpms_resolve_worker_outsourcing_ratio($worker)
            : (isset($worker['outsourcing_ratio']) ? (int)$worker['outsourcing_ratio'] : 0);

        $start = isset($worker['outsourcing_start_date'])
            ? trim((string)$worker['outsourcing_start_date'])
            : '';
        $end = isset($worker['outsourcing_end_date'])
            ? trim((string)$worker['outsourcing_end_date'])
            : '';

        if ($ratio !== 100) return false;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) return false;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) return false;
        return $start <= $end;
    }
}

if (!function_exists('cpms_labor_consultant_show_labor_date')) {
    function cpms_labor_consultant_show_labor_date($worker, $dateKey) {
        $dateKey = trim((string)$dateKey);
        if ($dateKey === '') return false;
        if (!cpms_labor_consultant_is_date_mode($worker)) return true;

        $start = trim((string)$worker['outsourcing_start_date']);
        $end = trim((string)$worker['outsourcing_end_date']);
        return !($dateKey >= $start && $dateKey <= $end);
    }
}

/**
 * 이 함수는 labor_consultant_helpers.php보다 먼저 선언된다.
 * 기존 helper는 function_exists 검사 후 같은 함수를 다시 선언하지 않는다.
 */
if (!function_exists('cpms_labor_consultant_load_project_month_rows')) {
    function cpms_labor_consultant_load_project_month_rows($pdo, $projectRow, $ym) {
        require_once __DIR__ . '/../construction/tabs/partials/labor_data_loader.php';

        $rows = array();
        if (!$pdo || !is_array($projectRow)) return $rows;

        $projectId = isset($projectRow['id']) ? (int)$projectRow['id'] : 0;
        $projectName = isset($projectRow['name']) ? trim((string)$projectRow['name']) : '';
        if ($projectId <= 0 || $projectName === '') return $rows;
        $projectManagerName = cpms_labor_consultant_project_manager_name($pdo, $projectId);

        $daysInMonth = cpms_labor_consultant_days_in_month($ym);
        $gongsuData = cpms_load_gongsu_data($pdo, $projectName, $ym);
        $dataset = cpms_apply_labor_overrides_to_dataset(
            isset($gongsuData['gongsu_map']) ? $gongsuData['gongsu_map'] : array(),
            isset($gongsuData['output_days']) ? $gongsuData['output_days'] : array(),
            isset($gongsuData['gongsu_unit']) ? $gongsuData['gongsu_unit'] : array(),
            $projectId,
            $ym
        );

        $attendanceWorkers = array();
        if (isset($gongsuData['all_workers']) && is_array($gongsuData['all_workers']) && count($gongsuData['all_workers']) > 0) {
            $attendanceWorkers = $gongsuData['all_workers'];
        } else if (isset($gongsuData['workers']) && is_array($gongsuData['workers'])) {
            $attendanceWorkers = $gongsuData['workers'];
        }

        $excludedWorkers = isset($gongsuData['excluded_workers']) && is_array($gongsuData['excluded_workers'])
            ? $gongsuData['excluded_workers']
            : array();
        cpms_cleanup_project_labor_workers($pdo, $projectId, $excludedWorkers);
        cpms_sync_project_labor_workers_from_attendance($pdo, $projectId, $attendanceWorkers);

        $projectWorkers = cpms_load_project_labor_workers($pdo, $projectId);
        $laborWorkerRatioMap = cpms_load_project_labor_worker_month_ratio_map($pdo, $projectId, $ym, $projectWorkers);
        $projectWorkers = cpms_apply_project_labor_worker_month_ratios($projectWorkers, $laborWorkerRatioMap);
        $projectWorkers = cpms_labor_consultant_unique_project_workers($projectWorkers);

        $directTeamMembers = cpms_load_direct_team_members($pdo);
        $directMemberMap = cpms_labor_consultant_direct_member_map($directTeamMembers);
        $workerRows = cpms_build_project_worker_rows($projectWorkers, $directTeamMembers);
        $timesheetWorkers = cpms_build_timesheet_workers($workerRows);
        $roleMap = isset($gongsuData['role_map']) && is_array($gongsuData['role_map'])
            ? $gongsuData['role_map']
            : array();
        $gongsuMap = isset($dataset['gongsu_map']) && is_array($dataset['gongsu_map'])
            ? $dataset['gongsu_map']
            : array();
        $processedWorkerKeys = array();

        foreach ($timesheetWorkers as $worker) {
            $workerName = isset($worker['name']) ? trim((string)$worker['name']) : '';
            if ($workerName === '') continue;

            $workerKey = function_exists('cpms_normalize_worker_key')
                ? cpms_normalize_worker_key($workerName)
                : strtolower($workerName);
            if ($workerKey === '') continue;
            if (isset($processedWorkerKeys[$workerKey])) continue;
            $processedWorkerKeys[$workerKey] = true;

            $dailyMap = isset($gongsuMap[$workerKey]) && is_array($gongsuMap[$workerKey])
                ? $gongsuMap[$workerKey]
                : array();
            $days = array();
            $totalGongsu = 0.0;
            $outputDays = 0;

            $d = 1;
            while ($d <= $daysInMonth) {
                $dateKey = $ym . '-' . str_pad((string)$d, 2, '0', STR_PAD_LEFT);

                // 공사 > 노무비 > 노무비 탭에서 숨기는 외주비 날짜는 관리 화면에서도 제외한다.
                if (!cpms_labor_consultant_show_labor_date($worker, $dateKey)) {
                    $days[$d] = '';
                    $d++;
                    continue;
                }

                $value = isset($dailyMap[$dateKey]) && is_numeric($dailyMap[$dateKey])
                    ? (float)$dailyMap[$dateKey]
                    : 0.0;
                if ($value > 0) {
                    $days[$d] = round($value, 2);
                    $totalGongsu += (float)$days[$d];
                    $outputDays++;
                } else {
                    $days[$d] = '';
                }
                $d++;
            }

            if ($totalGongsu <= 0) continue;

            $wageRate = function_exists('cpms_resolve_labor_wage_rate')
                ? (float)cpms_resolve_labor_wage_rate($worker)
                : 0.0;
            $outsourcingRatio = function_exists('cpms_resolve_worker_outsourcing_ratio')
                ? (int)cpms_resolve_worker_outsourcing_ratio($worker)
                : ((isset($worker['is_outsourcing']) && (int)$worker['is_outsourcing'] === 1) ? 100 : 0);

            $laborAmounts = function_exists('cpms_labor_calculate_worker_month_amounts')
                ? cpms_labor_calculate_worker_month_amounts($worker, $gongsuMap, $ym)
                : array(
                    'total_amount' => round($totalGongsu * $wageRate),
                    'outsourcing_ratio' => $outsourcingRatio,
                    'labor_ratio' => 100 - $outsourcingRatio,
                    'outsourcing_amount' => round(round($totalGongsu * $wageRate) * $outsourcingRatio / 100),
                    'labor_amount' => round($totalGongsu * $wageRate) - round(round($totalGongsu * $wageRate) * $outsourcingRatio / 100),
                );

            $laborAmount = isset($laborAmounts['labor_amount']) ? (float)$laborAmounts['labor_amount'] : 0.0;
            if ($laborAmount <= 0) continue;

            // 지급총액은 현재 노무비 탭에서 실제 표시되는 공수 기준이다.
            $visibleTotalAmount = round($totalGongsu * $wageRate);

            $rows[count($rows)] = array(
                'project_id' => $projectId,
                'project_name' => $projectName,
                'project_start_date' => isset($projectRow['start_date']) ? (string)$projectRow['start_date'] : '',
                'project_end_date' => isset($projectRow['end_date']) ? (string)$projectRow['end_date'] : '',
                'project_manager_name' => $projectManagerName,
                'worker_name' => $workerName,
                'role' => isset($roleMap[$workerKey]) ? (string)$roleMap[$workerKey] : '',
                'phone' => cpms_labor_consultant_worker_detail_value($worker, $directMemberMap, $workerKey, 'phone', ''),
                'address' => cpms_labor_consultant_worker_detail_value($worker, $directMemberMap, $workerKey, 'address', ''),
                'resident_no' => cpms_labor_consultant_worker_detail_value($worker, $directMemberMap, $workerKey, 'resident_no', ''),
                'account_holder' => cpms_labor_consultant_worker_detail_value($worker, $directMemberMap, $workerKey, 'account_holder', ''),
                'bank_name' => cpms_labor_consultant_worker_detail_value($worker, $directMemberMap, $workerKey, 'bank_name', ''),
                'bank_account' => cpms_labor_consultant_worker_detail_value($worker, $directMemberMap, $workerKey, 'bank_account', ''),
                'company_name' => cpms_labor_consultant_worker_detail_value($worker, $directMemberMap, $workerKey, 'company_name', '창명건설'),
                'subcontract_type' => '',
                'foreigner' => '',
                'wage_rate' => $wageRate,
                'work_days_count' => $outputDays,
                'output_days' => $outputDays,
                'total_gongsu' => round($totalGongsu, 2),
                'total_amount' => (float)$visibleTotalAmount,
                'labor_ratio' => isset($laborAmounts['labor_ratio']) ? (int)$laborAmounts['labor_ratio'] : (100 - $outsourcingRatio),
                'labor_amount' => $laborAmount,
                'outsourcing_ratio' => isset($laborAmounts['outsourcing_ratio']) ? (int)$laborAmounts['outsourcing_ratio'] : $outsourcingRatio,
                'outsourcing_amount' => isset($laborAmounts['outsourcing_amount']) ? (float)$laborAmounts['outsourcing_amount'] : 0.0,
                'amount' => $laborAmount,
                'days' => $days,
            );
        }

        return $rows;
    }
}

/**
 * 노무사 확인용 엑셀 행 복제 시 원본 데이터 행의 가로 병합도 함께 복제합니다.
 * 예: B10:C10 병합 행을 3명분으로 늘리면 B11:C11, B12:C12도 생성합니다.
 */
if (!function_exists('cpms_labor_consultant_capture_row_merges')) {
    function cpms_labor_consultant_capture_row_merges($sheetDoc, $rowNum) {
        $result = array();
        $rowNum = (int)$rowNum;
        if (!($sheetDoc instanceof DOMDocument) || $rowNum <= 0) return $result;

        $xpath = new DOMXPath($sheetDoc);
        $mergeNodes = $xpath->query('/*[local-name()="worksheet"]/*[local-name()="mergeCells"]/*[local-name()="mergeCell"]');
        if (!$mergeNodes) return $result;

        foreach ($mergeNodes as $mergeNode) {
            $ref = strtoupper(trim((string)$mergeNode->getAttribute('ref')));
            if (!preg_match('/^([A-Z]+)([0-9]+):([A-Z]+)([0-9]+)$/', $ref, $m)) continue;
            $startRow = (int)$m[2];
            $endRow = (int)$m[4];

            // 세로 병합은 복제하면 다른 행과 겹칠 수 있으므로, 한 행 안의 가로 병합만 복제합니다.
            if ($startRow !== $rowNum || $endRow !== $rowNum) continue;
            $result[count($result)] = array(
                'start_col' => $m[1],
                'end_col' => $m[3]
            );
        }
        return $result;
    }
}

if (!function_exists('cpms_labor_consultant_merge_cells_node')) {
    function cpms_labor_consultant_merge_cells_node($sheetDoc) {
        if (!($sheetDoc instanceof DOMDocument)) return null;
        $xpath = new DOMXPath($sheetDoc);
        $nodes = $xpath->query('/*[local-name()="worksheet"]/*[local-name()="mergeCells"]');
        if ($nodes && $nodes->length > 0) return $nodes->item(0);

        $worksheet = $sheetDoc->documentElement;
        if (!($worksheet instanceof DOMElement)) return null;
        $namespace = $worksheet->namespaceURI;
        $mergeCells = $namespace !== ''
            ? $sheetDoc->createElementNS($namespace, 'mergeCells')
            : $sheetDoc->createElement('mergeCells');
        $mergeCells->setAttribute('count', '0');

        $sheetDataNodes = $xpath->query('/*[local-name()="worksheet"]/*[local-name()="sheetData"]');
        if ($sheetDataNodes && $sheetDataNodes->length > 0) {
            $sheetData = $sheetDataNodes->item(0);
            $next = $sheetData->nextSibling;
            while ($next && !($next instanceof DOMElement)) {
                $next = $next->nextSibling;
            }
            if ($next) $worksheet->insertBefore($mergeCells, $next);
            else $worksheet->appendChild($mergeCells);
        } else {
            $worksheet->appendChild($mergeCells);
        }
        return $mergeCells;
    }
}

if (!function_exists('cpms_labor_consultant_append_merge_ref')) {
    function cpms_labor_consultant_append_merge_ref($sheetDoc, $ref) {
        $ref = strtoupper(trim((string)$ref));
        if ($ref === '' || !($sheetDoc instanceof DOMDocument)) return false;

        $mergeCells = cpms_labor_consultant_merge_cells_node($sheetDoc);
        if (!($mergeCells instanceof DOMElement)) return false;

        foreach ($mergeCells->childNodes as $child) {
            if (!($child instanceof DOMElement) || $child->localName !== 'mergeCell') continue;
            if (strtoupper((string)$child->getAttribute('ref')) === $ref) return true;
        }

        $namespace = $sheetDoc->documentElement instanceof DOMElement
            ? $sheetDoc->documentElement->namespaceURI
            : '';
        $mergeCell = $namespace !== ''
            ? $sheetDoc->createElementNS($namespace, 'mergeCell')
            : $sheetDoc->createElement('mergeCell');
        $mergeCell->setAttribute('ref', $ref);
        $mergeCells->appendChild($mergeCell);

        $count = 0;
        foreach ($mergeCells->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'mergeCell') $count++;
        }
        $mergeCells->setAttribute('count', (string)$count);
        return true;
    }
}

/**
 * 기존 helper보다 먼저 선언되어 원본 행 복제 함수를 대체합니다.
 */
if (!function_exists('cpms_labor_consultant_prepare_target_rows')) {
    function cpms_labor_consultant_prepare_target_rows($sheetDoc, $headerRow, $dataCount) {
        $dataCount = (int)$dataCount;
        if ($dataCount <= 0) return array();

        $xpath = new DOMXPath($sheetDoc);
        $sheetDataList = $xpath->query('//*[local-name()="worksheet"]/*[local-name()="sheetData"]');
        if (!$sheetDataList || $sheetDataList->length < 1) return array();
        $sheetData = $sheetDataList->item(0);

        $rowNodes = $xpath->query('./*[local-name()="row"]', $sheetData);
        $sampleRowNode = null;
        $sampleRowNum = (int)$headerRow + 1;

        foreach ($rowNodes as $rowNode) {
            $rowNum = (int)$rowNode->getAttribute('r');
            if ($rowNum >= $sampleRowNum) {
                $sampleRowNode = $rowNode;
                $sampleRowNum = $rowNum;
                break;
            }
        }

        if (!$sampleRowNode) {
            $headerRowNode = cpms_labor_consultant_find_row_node($sheetDoc, $headerRow);
            if (!$headerRowNode) return array();
            $sampleRowNode = $headerRowNode->cloneNode(true);
            cpms_labor_consultant_reindex_row_node($sampleRowNode, $headerRow + 1);
            if ($headerRowNode->nextSibling) {
                $sheetData->insertBefore($sampleRowNode, $headerRowNode->nextSibling);
            } else {
                $sheetData->appendChild($sampleRowNode);
            }
            $sampleRowNum = $headerRow + 1;
        }

        // 행을 이동하기 전에 원본 데이터 행의 가로 병합 정보를 보관합니다.
        $sampleRowMerges = cpms_labor_consultant_capture_row_merges($sheetDoc, $sampleRowNum);

        $offset = $dataCount - 1;
        cpms_labor_consultant_shift_sheet_rows($sheetDoc, $sampleRowNum, $offset);
        cpms_labor_consultant_shift_merged_cells($sheetDoc, $sampleRowNum, $offset);

        $targetRows = array();
        $targetRows[count($targetRows)] = $sampleRowNode;
        $insertAfter = $sampleRowNode;
        $i = 1;
        while ($i < $dataCount) {
            $clone = $sampleRowNode->cloneNode(true);
            $targetRowNum = $sampleRowNum + $i;
            cpms_labor_consultant_reindex_row_node($clone, $targetRowNum);
            if ($insertAfter->nextSibling) {
                $sheetData->insertBefore($clone, $insertAfter->nextSibling);
            } else {
                $sheetData->appendChild($clone);
            }

            // 복제된 각 데이터 행에도 원본과 같은 가로 병합을 생성합니다.
            foreach ($sampleRowMerges as $mergeSpec) {
                $startCol = isset($mergeSpec['start_col']) ? (string)$mergeSpec['start_col'] : '';
                $endCol = isset($mergeSpec['end_col']) ? (string)$mergeSpec['end_col'] : '';
                if ($startCol === '' || $endCol === '') continue;
                cpms_labor_consultant_append_merge_ref(
                    $sheetDoc,
                    $startCol . $targetRowNum . ':' . $endCol . $targetRowNum
                );
            }

            $targetRows[count($targetRows)] = $clone;
            $insertAfter = $clone;
            $i++;
        }

        cpms_labor_consultant_update_dimension($sheetDoc);
        return $targetRows;
    }
}

/**
 * XLSX 셀 주소를 행/열 번호로 변환합니다.
 * 파일: app/views/admin/labor_consultant_labor_only_override.php
 */
if (!function_exists('cpms_labor_consultant_fix_ref_to_pos')) {
    function cpms_labor_consultant_fix_ref_to_pos($ref) {
        $ref = strtoupper(trim((string)$ref));
        if (!preg_match('/^([A-Z]+)([0-9]+)$/', $ref, $m)) return array(0, 0);
        $col = 0;
        $letters = $m[1];
        $length = strlen($letters);
        for ($i = 0; $i < $length; $i++) {
            $col = ($col * 26) + (ord($letters[$i]) - 64);
        }
        return array((int)$m[2], $col);
    }
}

if (!function_exists('cpms_labor_consultant_fix_col_letter')) {
    function cpms_labor_consultant_fix_col_letter($index) {
        $index = (int)$index;
        if ($index <= 0) return '';
        $letters = '';
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $letters = chr(65 + $mod) . $letters;
            $index = (int)(($index - 1) / 26);
        }
        return $letters;
    }
}

if (!function_exists('cpms_labor_consultant_fix_range_rect')) {
    function cpms_labor_consultant_fix_range_rect($ref) {
        $ref = strtoupper(trim((string)$ref));
        $parts = explode(':', $ref, 2);
        if (count($parts) === 1) $parts[1] = $parts[0];
        list($r1, $c1) = cpms_labor_consultant_fix_ref_to_pos($parts[0]);
        list($r2, $c2) = cpms_labor_consultant_fix_ref_to_pos($parts[1]);
        if ($r1 <= 0 || $c1 <= 0 || $r2 <= 0 || $c2 <= 0) return null;
        return array(
            'r1' => min($r1, $r2),
            'r2' => max($r1, $r2),
            'c1' => min($c1, $c2),
            'c2' => max($c1, $c2)
        );
    }
}

if (!function_exists('cpms_labor_consultant_fix_rect_overlap')) {
    function cpms_labor_consultant_fix_rect_overlap($a, $b) {
        if (!is_array($a) || !is_array($b)) return false;
        return !(
            $a['r2'] < $b['r1'] || $b['r2'] < $a['r1']
            || $a['c2'] < $b['c1'] || $b['c2'] < $a['c1']
        );
    }
}

if (!function_exists('cpms_labor_consultant_fix_sheet_list')) {
    function cpms_labor_consultant_fix_sheet_list($zip) {
        $sheets = array();
        if (!($zip instanceof ZipArchive)) return $sheets;
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbookXml === false || $relsXml === false) return $sheets;

        $workbook = @simplexml_load_string($workbookXml);
        $rels = @simplexml_load_string($relsXml);
        if (!$workbook || !$rels || !isset($workbook->sheets)) return $sheets;

        $targets = array();
        foreach ($rels->Relationship as $rel) {
            $rid = (string)$rel['Id'];
            $target = ltrim(str_replace('\\', '/', (string)$rel['Target']), '/');
            if ($rid === '' || $target === '') continue;
            if (strpos($target, 'xl/') !== 0) $target = 'xl/' . $target;
            $targets[$rid] = $target;
        }

        $index = 0;
        foreach ($workbook->sheets->sheet as $sheet) {
            $attrs = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $rid = isset($attrs['id']) ? (string)$attrs['id'] : '';
            if ($rid !== '' && isset($targets[$rid])) {
                $sheets[] = array(
                    'name' => (string)$sheet['name'],
                    'path' => $targets[$rid],
                    'index' => $index
                );
            }
            $index++;
        }
        return $sheets;
    }
}

if (!function_exists('cpms_labor_consultant_fix_shared_strings')) {
    function cpms_labor_consultant_fix_shared_strings($zip) {
        $values = array();
        if (!($zip instanceof ZipArchive)) return $values;
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false || $xml === '') return $values;
        $doc = new DOMDocument('1.0', 'UTF-8');
        if (!@$doc->loadXML($xml)) return $values;
        $xpath = new DOMXPath($doc);
        $items = $xpath->query('//*[local-name()="sst"]/*[local-name()="si"]');
        foreach ($items as $item) {
            $text = '';
            $texts = $xpath->query('.//*[local-name()="t"]', $item);
            foreach ($texts as $textNode) $text .= (string)$textNode->textContent;
            $values[] = $text;
        }
        return $values;
    }
}

if (!function_exists('cpms_labor_consultant_fix_cell_text')) {
    function cpms_labor_consultant_fix_cell_text($cellNode, $sharedStrings) {
        if (!($cellNode instanceof DOMElement)) return '';
        $type = (string)$cellNode->getAttribute('t');
        if ($type === 's') {
            $values = $cellNode->getElementsByTagName('v');
            if ($values->length <= 0) return '';
            $idx = (int)$values->item(0)->textContent;
            return isset($sharedStrings[$idx]) ? (string)$sharedStrings[$idx] : '';
        }
        if ($type === 'inlineStr') {
            $texts = $cellNode->getElementsByTagName('t');
            $text = '';
            foreach ($texts as $textNode) $text .= (string)$textNode->textContent;
            return $text;
        }
        $values = $cellNode->getElementsByTagName('v');
        if ($values->length > 0) return (string)$values->item(0)->textContent;
        return trim((string)$cellNode->textContent);
    }
}

if (!function_exists('cpms_labor_consultant_fix_label_key')) {
    function cpms_labor_consultant_fix_label_key($value) {
        $value = trim((string)$value);
        $value = str_replace(array("\r", "\n", "\t", ' ', '/', 'ㆍ', '·'), '', $value);
        return $value;
    }
}

if (!function_exists('cpms_labor_consultant_fix_sheet_rows')) {
    function cpms_labor_consultant_fix_sheet_rows($sheetDoc, $sharedStrings) {
        $rows = array();
        if (!($sheetDoc instanceof DOMDocument)) return $rows;
        $xpath = new DOMXPath($sheetDoc);
        $rowNodes = $xpath->query('//*[local-name()="worksheet"]/*[local-name()="sheetData"]/*[local-name()="row"]');
        foreach ($rowNodes as $rowNode) {
            $rowNum = (int)$rowNode->getAttribute('r');
            if ($rowNum <= 0) continue;
            $rows[$rowNum] = array();
            $cells = $xpath->query('./*[local-name()="c"]', $rowNode);
            foreach ($cells as $cellNode) {
                list(, $colNum) = cpms_labor_consultant_fix_ref_to_pos($cellNode->getAttribute('r'));
                if ($colNum <= 0) continue;
                $rows[$rowNum][$colNum] = array(
                    'text' => cpms_labor_consultant_fix_cell_text($cellNode, $sharedStrings),
                    'node' => $cellNode
                );
            }
        }
        return $rows;
    }
}

/**
 * 노무비 양식의 반복 헤더 행을 모두 찾습니다.
 * 한 시트 안에 소계 구간이 여러 개 있어도 각 구간의 헤더를 별도로 복구합니다.
 */
if (!function_exists('cpms_labor_consultant_fix_header_rows')) {
    function cpms_labor_consultant_fix_header_rows($rows) {
        $result = array();
        $targetLabels = array(
            '출력월' => true,
            '성명' => true,
            '공종' => true,
            '직종' => true,
            '출력일수' => true,
            '임금단가' => true,
            '단가' => true,
            '지급총액' => true,
            '영수인예금주' => true,
            '예금주' => true,
            '은행명' => true,
            '계좌번호' => true,
            '인력사업체명' => true,
            '인력사업체' => true,
            '인력사업체업체명' => true,
            '인력사업체명업체명' => true
        );

        foreach ($rows as $rowNum => $cells) {
            $count = 0;
            $hasOutputMonth = false;
            $hasName = false;
            $hasOutputDays = false;
            foreach ($cells as $cell) {
                $key = cpms_labor_consultant_fix_label_key(isset($cell['text']) ? $cell['text'] : '');
                if (isset($targetLabels[$key])) $count++;
                if ($key === '출력월') $hasOutputMonth = true;
                if ($key === '성명') $hasName = true;
                if ($key === '출력일수') $hasOutputDays = true;
            }

            $nextDayCount = 0;
            if (isset($rows[$rowNum + 1])) {
                foreach ($rows[$rowNum + 1] as $nextCell) {
                    $text = trim((string)(isset($nextCell['text']) ? $nextCell['text'] : ''));
                    if (preg_match('/^(?:[1-9]|[12][0-9]|3[01])(?:\.0+)?$/', $text)) $nextDayCount++;
                }
            }

            if (($hasOutputMonth && $hasName) || ($count >= 4 && ($hasOutputDays || $nextDayCount >= 5))) {
                $result[] = (int)$rowNum;
            }
        }
        return array_values(array_unique($result));
    }
}

if (!function_exists('cpms_labor_consultant_fix_merge_cells_node')) {
    function cpms_labor_consultant_fix_merge_cells_node($sheetDoc) {
        $xpath = new DOMXPath($sheetDoc);
        $nodes = $xpath->query('/*[local-name()="worksheet"]/*[local-name()="mergeCells"]');
        if ($nodes && $nodes->length > 0) return $nodes->item(0);

        $worksheet = $sheetDoc->documentElement;
        if (!($worksheet instanceof DOMElement)) return null;
        $namespace = $worksheet->namespaceURI;
        $mergeCells = $namespace !== ''
            ? $sheetDoc->createElementNS($namespace, 'mergeCells')
            : $sheetDoc->createElement('mergeCells');
        $mergeCells->setAttribute('count', '0');

        $sheetDataNodes = $xpath->query('/*[local-name()="worksheet"]/*[local-name()="sheetData"]');
        if ($sheetDataNodes && $sheetDataNodes->length > 0) {
            $sheetData = $sheetDataNodes->item(0);
            $next = $sheetData->nextSibling;
            while ($next && !($next instanceof DOMElement)) $next = $next->nextSibling;
            if ($next) $worksheet->insertBefore($mergeCells, $next);
            else $worksheet->appendChild($mergeCells);
        } else {
            $worksheet->appendChild($mergeCells);
        }
        return $mergeCells;
    }
}

if (!function_exists('cpms_labor_consultant_fix_refresh_merge_count')) {
    function cpms_labor_consultant_fix_refresh_merge_count($mergeCells) {
        if (!($mergeCells instanceof DOMElement)) return;
        $count = 0;
        foreach ($mergeCells->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'mergeCell') $count++;
        }
        $mergeCells->setAttribute('count', (string)$count);
    }
}

/**
 * 겹치는 잘못된 병합을 제거한 뒤 원본 양식의 정확한 병합을 추가합니다.
 */
if (!function_exists('cpms_labor_consultant_fix_apply_merge')) {
    function cpms_labor_consultant_fix_apply_merge($sheetDoc, $ref) {
        $targetRect = cpms_labor_consultant_fix_range_rect($ref);
        if (!$targetRect) return false;
        $mergeCells = cpms_labor_consultant_fix_merge_cells_node($sheetDoc);
        if (!($mergeCells instanceof DOMElement)) return false;

        $remove = array();
        foreach ($mergeCells->childNodes as $child) {
            if (!($child instanceof DOMElement) || $child->localName !== 'mergeCell') continue;
            $existingRef = strtoupper(trim((string)$child->getAttribute('ref')));
            if ($existingRef === strtoupper($ref)) return true;
            $existingRect = cpms_labor_consultant_fix_range_rect($existingRef);
            if ($existingRect && cpms_labor_consultant_fix_rect_overlap($targetRect, $existingRect)) {
                $remove[] = $child;
            }
        }
        foreach ($remove as $node) $mergeCells->removeChild($node);

        $namespace = $sheetDoc->documentElement instanceof DOMElement
            ? $sheetDoc->documentElement->namespaceURI
            : '';
        $mergeCell = $namespace !== ''
            ? $sheetDoc->createElementNS($namespace, 'mergeCell')
            : $sheetDoc->createElement('mergeCell');
        $mergeCell->setAttribute('ref', strtoupper($ref));
        $mergeCells->appendChild($mergeCell);
        cpms_labor_consultant_fix_refresh_merge_count($mergeCells);
        return true;
    }
}

/**
 * 원본 양식에서 헤더 병합 패턴을 읽습니다.
 * 출력월, 공종, 임금단가, 지급총액, 계좌정보 등의 세로 병합을 양식 그대로 보존합니다.
 */
if (!function_exists('cpms_labor_consultant_fix_template_merge_patterns')) {
    function cpms_labor_consultant_fix_template_merge_patterns($templatePath) {
        $patterns = array();
        $templatePath = trim((string)$templatePath);
        if ($templatePath === '' || !is_file($templatePath) || !class_exists('ZipArchive')) return $patterns;

        $zip = new ZipArchive();
        if ($zip->open($templatePath) !== true) return $patterns;
        $sharedStrings = cpms_labor_consultant_fix_shared_strings($zip);
        $sheets = cpms_labor_consultant_fix_sheet_list($zip);

        foreach ($sheets as $sheet) {
            $path = isset($sheet['path']) ? (string)$sheet['path'] : '';
            $xml = $path !== '' ? $zip->getFromName($path) : false;
            if ($xml === false || $xml === '') continue;
            $doc = new DOMDocument('1.0', 'UTF-8');
            $doc->preserveWhiteSpace = true;
            $doc->formatOutput = false;
            if (!@$doc->loadXML($xml)) continue;

            $rows = cpms_labor_consultant_fix_sheet_rows($doc, $sharedStrings);
            $headerRows = cpms_labor_consultant_fix_header_rows($rows);
            if (count($headerRows) <= 0) continue;
            $headerRow = (int)$headerRows[0];

            $xpath = new DOMXPath($doc);
            $mergeNodes = $xpath->query('/*[local-name()="worksheet"]/*[local-name()="mergeCells"]/*[local-name()="mergeCell"]');
            foreach ($mergeNodes as $mergeNode) {
                $ref = strtoupper(trim((string)$mergeNode->getAttribute('ref')));
                $rect = cpms_labor_consultant_fix_range_rect($ref);
                if (!$rect) continue;

                // 헤더 행과 그 바로 아래 날짜 행에 걸친 병합만 패턴으로 사용합니다.
                if ($rect['r1'] < $headerRow || $rect['r2'] > $headerRow + 2) continue;
                $patterns[] = array(
                    'c1' => $rect['c1'],
                    'c2' => $rect['c2'],
                    'r1_offset' => $rect['r1'] - $headerRow,
                    'r2_offset' => $rect['r2'] - $headerRow
                );
            }
            if (count($patterns) > 0) break;
        }
        $zip->close();
        return $patterns;
    }
}

if (!function_exists('cpms_labor_consultant_fix_fallback_header_merges')) {
    function cpms_labor_consultant_fix_fallback_header_merges($sheetDoc, $rows, $headerRow) {
        $changed = 0;
        if (!isset($rows[$headerRow]) || !isset($rows[$headerRow + 1])) return $changed;

        $labels = array(
            '출력월' => true,
            '성명' => true,
            '공종' => true,
            '직종' => true,
            '임금단가' => true,
            '단가' => true,
            '지급총액' => true,
            '영수인예금주' => true,
            '예금주' => true,
            '은행명' => true,
            '계좌번호' => true,
            '인력사업체명' => true,
            '인력사업체' => true,
            '인력사업체업체명' => true,
            '인력사업체명업체명' => true
        );

        foreach ($rows[$headerRow] as $colNum => $cell) {
            $key = cpms_labor_consultant_fix_label_key(isset($cell['text']) ? $cell['text'] : '');
            if (!isset($labels[$key])) continue;
            $col = cpms_labor_consultant_fix_col_letter($colNum);
            if ($col === '') continue;
            if (cpms_labor_consultant_fix_apply_merge($sheetDoc, $col . $headerRow . ':' . $col . ($headerRow + 1))) {
                $changed++;
            }
        }
        return $changed;
    }
}

if (!function_exists('cpms_labor_consultant_fix_canonical_number')) {
    function cpms_labor_consultant_fix_canonical_number($value) {
        $value = trim((string)$value);
        if ($value === '' || !is_numeric($value)) return '';
        $number = (float)$value;
        if (abs($number - round($number)) < 0.00000001) return (string)(int)round($number);
        $formatted = number_format($number, 8, '.', '');
        return rtrim(rtrim($formatted, '0'), '.');
    }
}

if (!function_exists('cpms_labor_consultant_fix_replace_numeric_cell')) {
    function cpms_labor_consultant_fix_replace_numeric_cell($sheetDoc, $cellNode, $canonicalValue) {
        if (!($sheetDoc instanceof DOMDocument) || !($cellNode instanceof DOMElement)) return false;
        if ($canonicalValue === '') return false;

        $remove = array();
        foreach ($cellNode->childNodes as $child) {
            if (!($child instanceof DOMElement)) continue;
            if ($child->localName === 'f') continue;
            $remove[] = $child;
        }
        foreach ($remove as $child) $cellNode->removeChild($child);
        $cellNode->removeAttribute('t');

        $namespace = $sheetDoc->documentElement instanceof DOMElement
            ? $sheetDoc->documentElement->namespaceURI
            : '';
        $valueNode = $namespace !== ''
            ? $sheetDoc->createElementNS($namespace, 'v')
            : $sheetDoc->createElement('v');
        $valueNode->appendChild($sheetDoc->createTextNode($canonicalValue));
        $cellNode->appendChild($valueNode);
        return true;
    }
}

/**
 * 생성된 노무사 확인용 XLSX를 최종 보정합니다.
 * 1) 원본 양식의 헤더 병합을 반복 구간마다 그대로 복원
 * 2) 일별 공수 숫자를 General 형식으로 강제하여 1. 대신 1로 표시
 */
if (!function_exists('cpms_labor_consultant_fix_export_workbook')) {
    function cpms_labor_consultant_fix_export_workbook($filePath, $templatePath) {
        $result = array(
            'ok' => false,
            'message' => '',
            'updated_cells' => 0,
            'updated_styles' => 0,
            'restored_merges' => 0
        );
        $filePath = trim((string)$filePath);
        if ($filePath === '' || !is_file($filePath)) {
            $result['message'] = '생성된 엑셀 파일을 찾을 수 없습니다.';
            return $result;
        }
        if (!class_exists('ZipArchive') || !class_exists('DOMDocument')) {
            $result['message'] = '엑셀 후처리 모듈을 사용할 수 없습니다.';
            return $result;
        }

        $templatePatterns = cpms_labor_consultant_fix_template_merge_patterns($templatePath);
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            $result['message'] = '생성된 엑셀 파일을 열 수 없습니다.';
            return $result;
        }

        $stylesXml = $zip->getFromName('xl/styles.xml');
        if ($stylesXml === false || $stylesXml === '') {
            $zip->close();
            $result['message'] = '엑셀 스타일 정보를 찾을 수 없습니다.';
            return $result;
        }

        $sharedStrings = cpms_labor_consultant_fix_shared_strings($zip);
        $sheets = cpms_labor_consultant_fix_sheet_list($zip);
        $sheetEntries = array();
        $styleIdsNeeded = array();

        foreach ($sheets as $sheet) {
            $sheetPath = isset($sheet['path']) ? (string)$sheet['path'] : '';
            $sheetXml = $sheetPath !== '' ? $zip->getFromName($sheetPath) : false;
            if ($sheetXml === false || $sheetXml === '') continue;

            $sheetDoc = new DOMDocument('1.0', 'UTF-8');
            $sheetDoc->preserveWhiteSpace = true;
            $sheetDoc->formatOutput = false;
            if (!@$sheetDoc->loadXML($sheetXml)) continue;

            $rows = cpms_labor_consultant_fix_sheet_rows($sheetDoc, $sharedStrings);
            $headerRows = cpms_labor_consultant_fix_header_rows($rows);
            if (count($headerRows) <= 0) continue;

            // 원본 양식의 병합 패턴을 한 시트 안의 모든 반복 헤더에 적용합니다.
            foreach ($headerRows as $headerRow) {
                $before = $result['restored_merges'];
                if (count($templatePatterns) > 0) {
                    foreach ($templatePatterns as $pattern) {
                        $r1 = $headerRow + (int)$pattern['r1_offset'];
                        $r2 = $headerRow + (int)$pattern['r2_offset'];
                        $c1 = cpms_labor_consultant_fix_col_letter($pattern['c1']);
                        $c2 = cpms_labor_consultant_fix_col_letter($pattern['c2']);
                        if ($r1 <= 0 || $r2 <= 0 || $c1 === '' || $c2 === '') continue;
                        if (cpms_labor_consultant_fix_apply_merge($sheetDoc, $c1 . $r1 . ':' . $c2 . $r2)) {
                            $result['restored_merges']++;
                        }
                    }
                }
                // 양식 패턴을 못 읽었거나 일부 열이 빠진 경우 지정 헤더를 한 번 더 보정합니다.
                $result['restored_merges'] += cpms_labor_consultant_fix_fallback_header_merges($sheetDoc, $rows, $headerRow);
            }

            // 날짜 열 번호를 반복 헤더의 다음 행에서 수집합니다.
            $dayColumns = array();
            $dataStartRow = PHP_INT_MAX;
            foreach ($headerRows as $headerRow) {
                $dataStartRow = min($dataStartRow, $headerRow + 2);
                foreach (array($headerRow, $headerRow + 1, $headerRow + 2) as $dayHeaderRow) {
                    if (!isset($rows[$dayHeaderRow])) continue;
                    foreach ($rows[$dayHeaderRow] as $colNum => $cell) {
                        $text = trim((string)(isset($cell['text']) ? $cell['text'] : ''));
                        if (preg_match('/^(?:[1-9]|[12][0-9]|3[01])(?:\.0+)?$/', $text)) {
                            $dayColumns[(int)$colNum] = true;
                        }
                    }
                }
            }

            $xpath = new DOMXPath($sheetDoc);
            $cellNodes = $xpath->query('//*[local-name()="worksheet"]/*[local-name()="sheetData"]/*[local-name()="row"]/*[local-name()="c"]');
            $targetCells = array();
            foreach ($cellNodes as $cellNode) {
                $ref = (string)$cellNode->getAttribute('r');
                list($rowNum, $colNum) = cpms_labor_consultant_fix_ref_to_pos($ref);
                if ($rowNum < $dataStartRow || !isset($dayColumns[$colNum])) continue;

                $rawText = cpms_labor_consultant_fix_cell_text($cellNode, $sharedStrings);
                $rawText = trim((string)$rawText);
                if ($rawText === '' || !preg_match('/^-?[0-9]+(?:\.[0-9]*)?$/', $rawText)) continue;
                $canonical = cpms_labor_consultant_fix_canonical_number($rawText);
                if ($canonical === '') continue;

                cpms_labor_consultant_fix_replace_numeric_cell($sheetDoc, $cellNode, $canonical);
                $styleId = $cellNode->hasAttribute('s') ? (int)$cellNode->getAttribute('s') : 0;
                $styleIdsNeeded[$styleId] = $styleId;
                $targetCells[] = array('node' => $cellNode, 'style_id' => $styleId);
                $result['updated_cells']++;
            }

            $sheetEntries[] = array(
                'path' => $sheetPath,
                'doc' => $sheetDoc,
                'cells' => $targetCells
            );
        }

        $stylesDoc = new DOMDocument('1.0', 'UTF-8');
        $stylesDoc->preserveWhiteSpace = true;
        $stylesDoc->formatOutput = false;
        if (!@$stylesDoc->loadXML($stylesXml)) {
            $zip->close();
            $result['message'] = '엑셀 스타일 정보를 읽을 수 없습니다.';
            return $result;
        }

        $stylesXpath = new DOMXPath($stylesDoc);
        $cellXfsNodes = $stylesXpath->query('/*[local-name()="styleSheet"]/*[local-name()="cellXfs"]');
        $styleMap = array();
        if ($cellXfsNodes && $cellXfsNodes->length > 0 && count($styleIdsNeeded) > 0) {
            $cellXfs = $cellXfsNodes->item(0);
            $xfList = $stylesXpath->query('/*[local-name()="styleSheet"]/*[local-name()="cellXfs"]/*[local-name()="xf"]');
            $xfNodes = array();
            foreach ($xfList as $xfNode) $xfNodes[] = $xfNode;

            foreach ($styleIdsNeeded as $styleId) {
                $styleId = (int)$styleId;
                $baseId = isset($xfNodes[$styleId]) ? $styleId : 0;
                if (!isset($xfNodes[$baseId])) continue;
                $clone = $xfNodes[$baseId]->cloneNode(true);
                // General 형식. 1.0 또는 1. 대신 1로, 1.5는 1.5로 표시됩니다.
                $clone->setAttribute('numFmtId', '0');
                $clone->setAttribute('applyNumberFormat', '0');
                $newStyleId = count($xfNodes);
                $cellXfs->appendChild($clone);
                $xfNodes[] = $clone;
                $styleMap[$styleId] = $newStyleId;
                $result['updated_styles']++;
            }
            $cellXfs->setAttribute('count', (string)count($xfNodes));
        }

        foreach ($sheetEntries as $entry) {
            foreach ($entry['cells'] as $cellInfo) {
                $styleId = (int)$cellInfo['style_id'];
                if (isset($styleMap[$styleId])) {
                    $cellInfo['node']->setAttribute('s', (string)$styleMap[$styleId]);
                }
            }
            $zip->addFromString($entry['path'], $entry['doc']->saveXML());
        }
        if ($result['updated_styles'] > 0) {
            $zip->addFromString('xl/styles.xml', $stylesDoc->saveXML());
        }
        $zip->close();

        $result['ok'] = true;
        $result['message'] = '원본 양식 병합과 일별 공수 표시형식을 복구했습니다.';
        return $result;
    }
}
