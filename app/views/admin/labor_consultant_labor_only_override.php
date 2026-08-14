<?php

if (!defined('CPMS_LABOR_CONSULTANT_EXCEL_FIX_VERSION')) {
    define('CPMS_LABOR_CONSULTANT_EXCEL_FIX_VERSION', '2026-08-04-v5');
}

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
        $projectWorkers = cpms_apply_project_labor_worker_month_wages($projectWorkers, cpms_load_project_labor_worker_wage_map($pdo, $projectId, $ym));
        $projectWorkers = cpms_labor_consultant_unique_project_workers($projectWorkers);

        $directTeamMembers = cpms_load_direct_team_members($pdo);
        $directMemberMap = cpms_labor_consultant_direct_member_map($directTeamMembers);
        $workerRows = cpms_build_project_worker_rows($projectWorkers, $directTeamMembers, $pdo, $ym);
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
            $visibleTotalAmount = isset($laborAmounts['total_amount']) ? (float)$laborAmounts['total_amount'] : round($totalGongsu * $wageRate);

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
 * 노무사 양식의 근로자 2행 구조를 감지합니다.
 * - 위 행: 출력일수(근무한 날은 1)
 * - 아래 행: 총공수(1, 1.5 등 실제 공수)
 * - 출력월/공종/임금단가/지급총액/계좌정보/인력사업체명 등은 두 행 세로 병합
 */
if (!function_exists('cpms_labor_consultant_detect_header_block_height')) {
    function cpms_labor_consultant_detect_header_block_height($sheetDoc, $headerRow) {
        $headerRow = (int)$headerRow;
        if (!($sheetDoc instanceof DOMDocument) || $headerRow <= 0) return 1;

        $height = 1;
        $xpath = new DOMXPath($sheetDoc);
        $mergeNodes = $xpath->query('/*[local-name()="worksheet"]/*[local-name()="mergeCells"]/*[local-name()="mergeCell"]');
        foreach ($mergeNodes as $mergeNode) {
            $ref = strtoupper(trim((string)$mergeNode->getAttribute('ref')));
            if (!preg_match('/^([A-Z]+)([0-9]+):([A-Z]+)([0-9]+)$/', $ref, $m)) continue;
            $startRow = (int)$m[2];
            $endRow = (int)$m[4];
            if ($startRow !== $headerRow) continue;
            if ($endRow < $headerRow || $endRow > $headerRow + 3) continue;
            $candidate = ($endRow - $headerRow) + 1;
            if ($candidate > $height) $height = $candidate;
        }

        // 세로 병합이 누락된 변형 양식이라도 다음 행에 1~31 날짜가 여러 개 있으면 2행 헤더입니다.
        $nextRowNodes = $xpath->query('/*[local-name()="worksheet"]/*[local-name()="sheetData"]/*[local-name()="row"][@r="' . ($headerRow + 1) . '"]');
        if ($nextRowNodes && $nextRowNodes->length > 0) {
            $dayCount = 0;
            $cellNodes = $xpath->query('./*[local-name()="c"]', $nextRowNodes->item(0));
            foreach ($cellNodes as $cellNode) {
                $valueNodes = $cellNode->getElementsByTagName('v');
                if ($valueNodes->length <= 0) continue;
                $raw = trim((string)$valueNodes->item(0)->textContent);
                if (!preg_match('/^(?:[1-9]|[12][0-9]|3[01])(?:\.0+)?$/', $raw)) continue;
                $dayCount++;
            }
            if ($dayCount >= 3 && $height < 2) $height = 2;
        }

        return $height;
    }
}

if (!function_exists('cpms_labor_consultant_detect_worker_block_height')) {
    function cpms_labor_consultant_detect_worker_block_height($sheetDoc, $sampleRowNum) {
        $sampleRowNum = (int)$sampleRowNum;
        if (!($sheetDoc instanceof DOMDocument) || $sampleRowNum <= 0) return 1;

        $xpath = new DOMXPath($sheetDoc);
        $mergeNodes = $xpath->query('/*[local-name()="worksheet"]/*[local-name()="mergeCells"]/*[local-name()="mergeCell"]');
        $verticalMergeCount = 0;
        foreach ($mergeNodes as $mergeNode) {
            $ref = strtoupper(trim((string)$mergeNode->getAttribute('ref')));
            if (!preg_match('/^([A-Z]+)([0-9]+):([A-Z]+)([0-9]+)$/', $ref, $m)) continue;
            $startRow = (int)$m[2];
            $endRow = (int)$m[4];
            if ($startRow === $sampleRowNum && $endRow === $sampleRowNum + 1) {
                $verticalMergeCount++;
            }
        }

        // 출력월/공종/임금단가/지급총액/계좌정보 등 한 열이라도 2행 병합이면
        // 해당 근로자 양식은 위=출력일수, 아래=총공수의 2행 구조입니다.
        return $verticalMergeCount >= 1 ? 2 : 1;
    }
}

if (!function_exists('cpms_labor_consultant_capture_block_merges')) {
    function cpms_labor_consultant_capture_block_merges($sheetDoc, $startRow, $blockHeight) {
        $result = array();
        $startRow = (int)$startRow;
        $blockHeight = (int)$blockHeight;
        $endRow = $startRow + $blockHeight - 1;
        if (!($sheetDoc instanceof DOMDocument) || $startRow <= 0 || $blockHeight <= 0) return $result;

        $xpath = new DOMXPath($sheetDoc);
        $mergeNodes = $xpath->query('/*[local-name()="worksheet"]/*[local-name()="mergeCells"]/*[local-name()="mergeCell"]');
        foreach ($mergeNodes as $mergeNode) {
            $ref = strtoupper(trim((string)$mergeNode->getAttribute('ref')));
            if (!preg_match('/^([A-Z]+)([0-9]+):([A-Z]+)([0-9]+)$/', $ref, $m)) continue;
            $r1 = (int)$m[2];
            $r2 = (int)$m[4];
            if ($r1 < $startRow || $r2 > $endRow) continue;
            $result[] = array(
                'c1' => $m[1],
                'c2' => $m[3],
                'r1_offset' => $r1 - $startRow,
                'r2_offset' => $r2 - $startRow
            );
        }
        return $result;
    }
}

if (!function_exists('cpms_labor_consultant_prepare_target_row_blocks')) {
    function cpms_labor_consultant_prepare_target_row_blocks($sheetDoc, $headerRow, $dataCount) {
        $blocks = array();
        $dataCount = (int)$dataCount;
        $headerRow = (int)$headerRow;
        if (!($sheetDoc instanceof DOMDocument) || $dataCount <= 0 || $headerRow <= 0) return $blocks;

        $xpath = new DOMXPath($sheetDoc);
        $sheetDataList = $xpath->query('//*[local-name()="worksheet"]/*[local-name()="sheetData"]');
        if (!$sheetDataList || $sheetDataList->length < 1) return $blocks;
        $sheetData = $sheetDataList->item(0);

        // 핵심 수정:
        // 기존 코드는 headerRow + 1을 근로자 행으로 잡아 날짜 번호가 적힌 두 번째 헤더 행을 복제했습니다.
        // 원본 양식의 세로 병합을 확인해 헤더 높이를 먼저 계산한 뒤 실제 근로자 시작 행을 찾습니다.
        $headerHeight = cpms_labor_consultant_detect_header_block_height($sheetDoc, $headerRow);
        $dataStartRow = $headerRow + $headerHeight;

        $sampleRowNode = null;
        $sampleRowNum = $dataStartRow;

        // 먼저 실제 2행 근로자 블록의 세로 병합 시작 행을 찾습니다.
        // 스타일만 있고 값이 비어 있는 원본 양식에서도 가장 정확한 기준입니다.
        $mergeNodes = $xpath->query('/*[local-name()="worksheet"]/*[local-name()="mergeCells"]/*[local-name()="mergeCell"]');
        $mergeStartCandidates = array();
        foreach ($mergeNodes as $mergeNode) {
            $ref = strtoupper(trim((string)$mergeNode->getAttribute('ref')));
            if (!preg_match('/^([A-Z]+)([0-9]+):([A-Z]+)([0-9]+)$/', $ref, $m)) continue;
            $r1 = (int)$m[2];
            $r2 = (int)$m[4];
            if ($r1 < $dataStartRow || $r2 !== $r1 + 1) continue;
            $mergeStartCandidates[$r1] = isset($mergeStartCandidates[$r1]) ? $mergeStartCandidates[$r1] + 1 : 1;
        }
        if (count($mergeStartCandidates) > 0) {
            ksort($mergeStartCandidates);
            foreach ($mergeStartCandidates as $candidateRow => $candidateCount) {
                if ($candidateCount < 1) continue;
                $candidateNode = cpms_labor_consultant_find_row_node($sheetDoc, (int)$candidateRow);
                if ($candidateNode instanceof DOMElement) {
                    $sampleRowNode = $candidateNode;
                    $sampleRowNum = (int)$candidateRow;
                    break;
                }
            }
        }

        // 병합 시작 행을 찾지 못한 단순 양식은 헤더 다음 실제 행을 사용합니다.
        if (!($sampleRowNode instanceof DOMElement)) {
            $rowNodes = $xpath->query('./*[local-name()="row"]', $sheetData);
            foreach ($rowNodes as $rowNode) {
                $rowNum = (int)$rowNode->getAttribute('r');
                if ($rowNum < $dataStartRow) continue;
                $sampleRowNode = $rowNode;
                $sampleRowNum = $rowNum;
                break;
            }
        }
        if (!($sampleRowNode instanceof DOMElement)) return $blocks;

        $blockHeight = cpms_labor_consultant_detect_worker_block_height($sheetDoc, $sampleRowNum);
        $sampleRows = array($sampleRowNode);
        if ($blockHeight === 2) {
            $secondRow = cpms_labor_consultant_find_row_node($sheetDoc, $sampleRowNum + 1);
            if ($secondRow instanceof DOMElement) {
                $sampleRows[] = $secondRow;
            } else {
                $blockHeight = 1;
            }
        }

        $sampleEndRow = $sampleRowNum + $blockHeight - 1;
        $mergeSpecs = cpms_labor_consultant_capture_block_merges($sheetDoc, $sampleRowNum, $blockHeight);
        $offset = ($dataCount - 1) * $blockHeight;

        // 첫 근로자 블록 아래의 소계/합계/다음 표를 정확히 이동합니다.
        cpms_labor_consultant_shift_sheet_rows($sheetDoc, $sampleEndRow, $offset);
        cpms_labor_consultant_shift_merged_cells($sheetDoc, $sampleEndRow, $offset);

        for ($workerIndex = 0; $workerIndex < $dataCount; $workerIndex++) {
            $targetStartRow = $sampleRowNum + ($workerIndex * $blockHeight);
            $blockRows = array();

            if ($workerIndex === 0) {
                $blockRows = $sampleRows;
            } else {
                $previous = $blocks[count($blocks) - 1];
                $insertAfter = $previous[count($previous) - 1];

                for ($rowOffset = 0; $rowOffset < $blockHeight; $rowOffset++) {
                    $clone = $sampleRows[$rowOffset]->cloneNode(true);
                    cpms_labor_consultant_reindex_row_node($clone, $targetStartRow + $rowOffset);
                    if ($insertAfter->nextSibling) {
                        $sheetData->insertBefore($clone, $insertAfter->nextSibling);
                    } else {
                        $sheetData->appendChild($clone);
                    }
                    $blockRows[] = $clone;
                    $insertAfter = $clone;
                }

                // 행 노드만 복제하면 병합 정의는 복제되지 않으므로
                // 원본 근로자 블록의 모든 가로/세로 병합을 상대 위치 그대로 생성합니다.
                foreach ($mergeSpecs as $mergeSpec) {
                    $ref = $mergeSpec['c1'] . ($targetStartRow + (int)$mergeSpec['r1_offset'])
                        . ':' . $mergeSpec['c2'] . ($targetStartRow + (int)$mergeSpec['r2_offset']);
                    cpms_labor_consultant_append_merge_ref($sheetDoc, $ref);
                }
            }

            $blocks[] = $blockRows;
        }

        cpms_labor_consultant_update_dimension($sheetDoc);
        return $blocks;
    }
}

if (!function_exists('cpms_labor_consultant_clear_block_column')) {
    function cpms_labor_consultant_clear_block_column($sheetDoc, $rowNode, $colIndex) {
        $cellNode = cpms_labor_consultant_find_or_create_cell($sheetDoc, $rowNode, (int)$colIndex);
        if ($cellNode instanceof DOMElement) cpms_labor_consultant_clear_cell_value($cellNode);
    }
}

/**
 * 노무사 양식에 데이터를 입력합니다.
 * 2행 양식이면 위 행에는 출력일수 1, 아래 행에는 실제 총공수를 기록합니다.
 */
if (!function_exists('cpms_labor_consultant_fill_sheet_rows')) {
    function cpms_labor_consultant_fill_sheet_rows($sheetDoc, $headers, $dataRows) {
        $headerRow = isset($headers['row']) ? (int)$headers['row'] : 0;
        $columns = isset($headers['columns']) && is_array($headers['columns']) ? $headers['columns'] : array();
        if ($headerRow <= 0 || count($columns) === 0 || !is_array($dataRows)) return false;

        $blocks = cpms_labor_consultant_prepare_target_row_blocks($sheetDoc, $headerRow, count($dataRows));
        if (count($blocks) !== count($dataRows)) return false;

        foreach ($blocks as $idx => $blockRows) {
            if (!is_array($blockRows) || count($blockRows) < 1) continue;
            $topRow = $blockRows[0];
            $bottomRow = count($blockRows) > 1 ? $blockRows[1] : null;
            $rowData = isset($dataRows[$idx]) && is_array($dataRows[$idx]) ? $dataRows[$idx] : array();

            // 입력 대상 열만 비우고, 양식의 고정문구/수식/서식은 그대로 둡니다.
            foreach ($columns as $columnKey => $columnIndex) {
                cpms_labor_consultant_clear_block_column($sheetDoc, $topRow, $columnIndex);
                if ($bottomRow instanceof DOMElement) {
                    cpms_labor_consultant_clear_block_column($sheetDoc, $bottomRow, $columnIndex);
                }
            }

            $textFields = array(
                'project_name' => 'project_name',
                'worker_name' => 'worker_name',
                'role' => 'role',
                'phone' => 'phone',
                'resident_no' => 'resident_no',
                'address' => 'address',
                'account_holder' => 'account_holder',
                'bank_name' => 'bank_name',
                'bank_account' => 'bank_account',
                'company_name' => 'company_name'
            );
            foreach ($textFields as $columnKey => $dataKey) {
                if (!isset($columns[$columnKey])) continue;
                cpms_labor_consultant_set_cell_value(
                    $sheetDoc,
                    $topRow,
                    $columns[$columnKey],
                    isset($rowData[$dataKey]) ? $rowData[$dataKey] : '',
                    false
                );
            }

            if (isset($columns['wage_rate'])) {
                cpms_labor_consultant_set_cell_value($sheetDoc, $topRow, $columns['wage_rate'], isset($rowData['wage_rate']) ? $rowData['wage_rate'] : '', true);
            }
            if (isset($columns['amount'])) {
                cpms_labor_consultant_set_cell_value($sheetDoc, $topRow, $columns['amount'], isset($rowData['amount']) ? $rowData['amount'] : '', true);
            }

            $outputDays = isset($rowData['output_days']) ? $rowData['output_days'] : (isset($rowData['work_days_count']) ? $rowData['work_days_count'] : '');
            $totalGongsu = isset($rowData['total_gongsu']) ? $rowData['total_gongsu'] : '';

            if ($bottomRow instanceof DOMElement) {
                // 양식이 같은 열의 위/아래 셀로 출력일수와 총공수를 표시하는 경우입니다.
                if (isset($columns['output_days'])) {
                    cpms_labor_consultant_set_cell_value($sheetDoc, $topRow, $columns['output_days'], $outputDays, true);
                    cpms_labor_consultant_set_cell_value($sheetDoc, $bottomRow, $columns['output_days'], $totalGongsu, true);
                } else if (isset($columns['total_gongsu'])) {
                    cpms_labor_consultant_set_cell_value($sheetDoc, $topRow, $columns['total_gongsu'], $outputDays, true);
                    cpms_labor_consultant_set_cell_value($sheetDoc, $bottomRow, $columns['total_gongsu'], $totalGongsu, true);
                }
            } else {
                if (isset($columns['output_days'])) {
                    cpms_labor_consultant_set_cell_value($sheetDoc, $topRow, $columns['output_days'], $outputDays, true);
                }
                if (isset($columns['total_gongsu'])) {
                    cpms_labor_consultant_set_cell_value($sheetDoc, $topRow, $columns['total_gongsu'], $totalGongsu, true);
                }
            }

            if (isset($rowData['days']) && is_array($rowData['days'])) {
                foreach ($rowData['days'] as $day => $value) {
                    $dayKey = 'day_' . (int)$day;
                    if (!isset($columns[$dayKey])) continue;
                    $hasWork = ($value !== '' && is_numeric($value) && (float)$value > 0);
                    if ($bottomRow instanceof DOMElement) {
                        cpms_labor_consultant_set_cell_value($sheetDoc, $topRow, $columns[$dayKey], $hasWork ? 1 : '', $hasWork);
                        cpms_labor_consultant_set_cell_value($sheetDoc, $bottomRow, $columns[$dayKey], $hasWork ? $value : '', $hasWork);
                    } else {
                        cpms_labor_consultant_set_cell_value($sheetDoc, $topRow, $columns[$dayKey], $value, ($value !== ''));
                    }
                }
            }
        }

        cpms_labor_consultant_update_dimension($sheetDoc);
        return true;
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
if (!function_exists('cpms_labor_consultant_fix_project_key')) {
    function cpms_labor_consultant_fix_project_key($name) {
        $name = trim((string)$name);
        if ($name === '') return '';
        if (function_exists('mb_strtolower')) {
            $name = mb_strtolower($name, 'UTF-8');
        } else {
            $name = strtolower($name);
        }
        $normalized = @preg_replace('/[^\p{L}\p{N}]+/u', '', $name);
        if ($normalized === null) {
            $normalized = str_replace(array(' ', "\r", "\n", "\t", '-', '_', '.', ',', '(', ')', '[', ']', '/', '\\'), '', $name);
        }
        return trim((string)$normalized);
    }
}

if (!function_exists('cpms_labor_consultant_fix_group_project_rows')) {
    function cpms_labor_consultant_fix_group_project_rows($dataRows) {
        $groups = array();
        if (!is_array($dataRows)) return $groups;
        foreach ($dataRows as $row) {
            if (!is_array($row)) continue;
            $projectName = isset($row['project_name']) ? trim((string)$row['project_name']) : '';
            $key = cpms_labor_consultant_fix_project_key($projectName);
            if ($key === '') continue;
            if (!isset($groups[$key])) $groups[$key] = array();
            $groups[$key][] = $row;
        }
        return $groups;
    }
}

if (!function_exists('cpms_labor_consultant_fix_sheet_project_name')) {
    function cpms_labor_consultant_fix_sheet_project_name($rows, $fallbackName) {
        if (is_array($rows)) {
            for ($rowNum = 1; $rowNum <= 4; $rowNum++) {
                if (!isset($rows[$rowNum]) || !is_array($rows[$rowNum])) continue;
                ksort($rows[$rowNum]);
                foreach ($rows[$rowNum] as $colNum => $cell) {
                    $text = isset($cell['text']) ? trim((string)$cell['text']) : '';
                    $key = cpms_labor_consultant_fix_label_key($text);
                    if ($key !== '현장명') continue;
                    for ($nextCol = (int)$colNum + 1; $nextCol <= (int)$colNum + 12; $nextCol++) {
                        if (!isset($rows[$rowNum][$nextCol])) continue;
                        $candidate = isset($rows[$rowNum][$nextCol]['text'])
                            ? trim((string)$rows[$rowNum][$nextCol]['text'])
                            : '';
                        if ($candidate !== '') return $candidate;
                    }
                }
            }
        }
        return trim((string)$fallbackName);
    }
}

if (!function_exists('cpms_labor_consultant_fix_find_row_node')) {
    function cpms_labor_consultant_fix_find_row_node($sheetDoc, $rowNum) {
        if (!($sheetDoc instanceof DOMDocument)) return null;
        $rowNum = (int)$rowNum;
        if ($rowNum <= 0) return null;
        $xpath = new DOMXPath($sheetDoc);
        $nodes = $xpath->query('/*[local-name()="worksheet"]/*[local-name()="sheetData"]/*[local-name()="row"][@r="' . $rowNum . '"]');
        if ($nodes && $nodes->length > 0) return $nodes->item(0);
        return null;
    }
}

if (!function_exists('cpms_labor_consultant_fix_find_or_create_cell_node')) {
    function cpms_labor_consultant_fix_find_or_create_cell_node($sheetDoc, $rowNode, $colNum) {
        if (!($sheetDoc instanceof DOMDocument) || !($rowNode instanceof DOMElement)) return null;
        $colNum = (int)$colNum;
        if ($colNum <= 0) return null;
        $rowNum = (int)$rowNode->getAttribute('r');
        $targetRef = cpms_labor_consultant_fix_col_letter($colNum) . $rowNum;
        $insertBefore = null;
        foreach ($rowNode->childNodes as $child) {
            if (!($child instanceof DOMElement) || $child->localName !== 'c') continue;
            list(, $existingCol) = cpms_labor_consultant_fix_ref_to_pos($child->getAttribute('r'));
            if ($existingCol === $colNum) return $child;
            if ($existingCol > $colNum) {
                $insertBefore = $child;
                break;
            }
        }
        $namespace = $sheetDoc->documentElement instanceof DOMElement
            ? $sheetDoc->documentElement->namespaceURI
            : '';
        $cellNode = $namespace !== ''
            ? $sheetDoc->createElementNS($namespace, 'c')
            : $sheetDoc->createElement('c');
        $cellNode->setAttribute('r', $targetRef);
        if ($insertBefore) $rowNode->insertBefore($cellNode, $insertBefore);
        else $rowNode->appendChild($cellNode);
        return $cellNode;
    }
}

if (!function_exists('cpms_labor_consultant_fix_clear_numeric_cell')) {
    function cpms_labor_consultant_fix_clear_numeric_cell($cellNode) {
        if (!($cellNode instanceof DOMElement)) return;
        $remove = array();
        foreach ($cellNode->childNodes as $child) {
            if ($child instanceof DOMElement) $remove[] = $child;
        }
        foreach ($remove as $child) $cellNode->removeChild($child);
        if ($cellNode->hasAttribute('t')) $cellNode->removeAttribute('t');
    }
}

if (!function_exists('cpms_labor_consultant_fix_write_number')) {
    function cpms_labor_consultant_fix_write_number($sheetDoc, $rowNode, $colNum, $value) {
        $cellNode = cpms_labor_consultant_fix_find_or_create_cell_node($sheetDoc, $rowNode, $colNum);
        if (!($cellNode instanceof DOMElement)) return null;
        cpms_labor_consultant_fix_clear_numeric_cell($cellNode);
        if ($value === '' || $value === null || !is_numeric($value)) return $cellNode;
        $canonical = cpms_labor_consultant_fix_canonical_number($value);
        if ($canonical === '') return $cellNode;
        $namespace = $sheetDoc->documentElement instanceof DOMElement
            ? $sheetDoc->documentElement->namespaceURI
            : '';
        $valueNode = $namespace !== ''
            ? $sheetDoc->createElementNS($namespace, 'v')
            : $sheetDoc->createElement('v');
        $valueNode->appendChild($sheetDoc->createTextNode($canonical));
        $cellNode->appendChild($valueNode);
        return $cellNode;
    }
}

if (!function_exists('cpms_labor_consultant_fix_add_style_target')) {
    function cpms_labor_consultant_fix_add_style_target($cellNode, &$targetCells, &$styleIdsNeeded) {
        if (!($cellNode instanceof DOMElement)) return;
        $styleId = $cellNode->hasAttribute('s') ? (int)$cellNode->getAttribute('s') : 0;
        $styleIdsNeeded[$styleId] = $styleId;
        $targetCells[] = array('node' => $cellNode, 'style_id' => $styleId);
    }
}

if (!function_exists('cpms_labor_consultant_fix_worker_merges')) {
    function cpms_labor_consultant_fix_worker_merges($sheetDoc, $topRow, $bottomRow) {
        $refs = array(
            'A' . $topRow . ':A' . $bottomRow,
            'B' . $topRow . ':C' . $topRow,
            'B' . $bottomRow . ':C' . $bottomRow,
            'X' . $topRow . ':X' . $bottomRow,
            'Y' . $topRow . ':Y' . $bottomRow,
            'AD' . $topRow . ':AD' . $bottomRow,
            'AE' . $topRow . ':AE' . $bottomRow,
            'AF' . $topRow . ':AF' . $bottomRow,
            'AG' . $topRow . ':AG' . $bottomRow,
            'AH' . $topRow . ':AH' . $bottomRow,
            'AI' . $topRow . ':AI' . $bottomRow,
            'E' . $bottomRow . ':F' . $bottomRow
        );
        $changed = 0;
        foreach ($refs as $ref) {
            if (cpms_labor_consultant_fix_apply_merge($sheetDoc, $ref)) $changed++;
        }
        return $changed;
    }
}

/**
 * 생성된 노무사 확인용 XLSX를 최종 보정합니다.
 *
 * 원본 양식의 실제 구조:
 * - 근로자 1명당 2행
 * - 1~15일: 윗행 G~U
 * - 16~31일: 아랫행 G~V
 * - W 윗행: 출력일수
 * - W 아랫행: 총공수
 * - 출력월/공종, 임금단가, 지급총액, 계좌정보, 인력사업체명은 각 2행 병합
 *
 * PHP 5.6 호환.
 */
if (!function_exists('cpms_labor_consultant_fix_export_workbook')) {
    function cpms_labor_consultant_fix_export_workbook($filePath, $templatePath, $dataRows) {
        $result = array(
            'ok' => false,
            'message' => '',
            'updated_cells' => 0,
            'updated_styles' => 0,
            'restored_merges' => 0,
            'fixed_projects' => 0
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

        $projectGroups = cpms_labor_consultant_fix_group_project_rows($dataRows);
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
            $sheetName = isset($sheet['name']) ? (string)$sheet['name'] : '';
            $sheetXml = $sheetPath !== '' ? $zip->getFromName($sheetPath) : false;
            if ($sheetXml === false || $sheetXml === '') continue;

            $sheetDoc = new DOMDocument('1.0', 'UTF-8');
            $sheetDoc->preserveWhiteSpace = true;
            $sheetDoc->formatOutput = false;
            if (!@$sheetDoc->loadXML($sheetXml)) continue;

            $rows = cpms_labor_consultant_fix_sheet_rows($sheetDoc, $sharedStrings);
            $headerRows = cpms_labor_consultant_fix_header_rows($rows);
            if (count($headerRows) <= 0) continue;

            // 헤더 병합은 원본 양식 패턴을 그대로 복구합니다.
            foreach ($headerRows as $headerRow) {
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
                $result['restored_merges'] += cpms_labor_consultant_fix_fallback_header_merges($sheetDoc, $rows, $headerRow);
            }

            $projectName = cpms_labor_consultant_fix_sheet_project_name($rows, $sheetName);
            $projectKey = cpms_labor_consultant_fix_project_key($projectName);
            if ($projectKey === '' || !isset($projectGroups[$projectKey])) {
                $sheetEntries[] = array('path' => $sheetPath, 'doc' => $sheetDoc, 'cells' => array());
                continue;
            }

            $projectRows = $projectGroups[$projectKey];
            $dataStartRow = ((int)$headerRows[0]) + 2;
            $targetCells = array();

            foreach ($projectRows as $workerIndex => $rowData) {
                $topRowNum = $dataStartRow + ((int)$workerIndex * 2);
                $bottomRowNum = $topRowNum + 1;
                $topRowNode = cpms_labor_consultant_fix_find_row_node($sheetDoc, $topRowNum);
                $bottomRowNode = cpms_labor_consultant_fix_find_row_node($sheetDoc, $bottomRowNum);
                if (!($topRowNode instanceof DOMElement) || !($bottomRowNode instanceof DOMElement)) continue;

                // 원본 양식에서 근로자 1명마다 필요한 병합을 정확히 복원합니다.
                $result['restored_merges'] += cpms_labor_consultant_fix_worker_merges($sheetDoc, $topRowNum, $bottomRowNum);

                // 기존 생성기가 16~31일 값을 윗행에 덮어쓴 상태일 수 있으므로
                // 날짜 입력칸을 모두 비운 뒤 실제 양식 위치에 다시 기록합니다.
                for ($colNum = 7; $colNum <= 22; $colNum++) {
                    $topCell = cpms_labor_consultant_fix_find_or_create_cell_node($sheetDoc, $topRowNode, $colNum);
                    $bottomCell = cpms_labor_consultant_fix_find_or_create_cell_node($sheetDoc, $bottomRowNode, $colNum);
                    cpms_labor_consultant_fix_clear_numeric_cell($topCell);
                    cpms_labor_consultant_fix_clear_numeric_cell($bottomCell);
                }

                $days = isset($rowData['days']) && is_array($rowData['days']) ? $rowData['days'] : array();
                for ($day = 1; $day <= 31; $day++) {
                    $value = isset($days[$day]) ? $days[$day] : '';
                    if ($value === '' || !is_numeric($value) || (float)$value <= 0) continue;
                    if ($day <= 15) {
                        $targetRowNode = $topRowNode;
                        $targetColNum = 6 + $day; // 1일=G, 15일=U
                    } else {
                        $targetRowNode = $bottomRowNode;
                        $targetColNum = $day - 9; // 16일=G, 31일=V
                    }
                    $cellNode = cpms_labor_consultant_fix_write_number($sheetDoc, $targetRowNode, $targetColNum, $value);
                    cpms_labor_consultant_fix_add_style_target($cellNode, $targetCells, $styleIdsNeeded);
                    $result['updated_cells']++;
                }

                // W 윗행은 출력일수, W 아랫행은 총공수입니다.
                $outputDays = isset($rowData['output_days'])
                    ? $rowData['output_days']
                    : (isset($rowData['work_days_count']) ? $rowData['work_days_count'] : '');
                $totalGongsu = isset($rowData['total_gongsu']) ? $rowData['total_gongsu'] : '';

                $outputDaysCell = cpms_labor_consultant_fix_write_number($sheetDoc, $topRowNode, 23, $outputDays);
                $totalGongsuCell = cpms_labor_consultant_fix_write_number($sheetDoc, $bottomRowNode, 23, $totalGongsu);
                cpms_labor_consultant_fix_add_style_target($outputDaysCell, $targetCells, $styleIdsNeeded);
                cpms_labor_consultant_fix_add_style_target($totalGongsuCell, $targetCells, $styleIdsNeeded);
                $result['updated_cells'] += 2;
            }

            $result['fixed_projects']++;
            $sheetEntries[] = array(
                'path' => $sheetPath,
                'doc' => $sheetDoc,
                'cells' => $targetCells
            );
        }

        // 기존 테두리/배경/정렬은 유지하고 숫자 형식만 General로 복제합니다.
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
                $clone->setAttribute('numFmtId', '0');
                $clone->setAttribute('applyNumberFormat', '1');
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
        $result['message'] = '근로자 2행 구조, 병합, 출력일수와 총공수를 원본 양식 기준으로 복구했습니다.';
        return $result;
    }
}
