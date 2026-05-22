<?php

if (!function_exists('cpms_master_dedupe_text_key')) {
function cpms_master_dedupe_text_key($value)
{
    $value = trim((string)$value);
    if ($value === '') return '';
    $value = preg_replace('/\s+/u', '', $value);
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($value, 'UTF-8');
    }
    return strtolower($value);
}}

if (!function_exists('cpms_master_dedupe_biz_key')) {
function cpms_master_dedupe_biz_key($value)
{
    $value = preg_replace('/[^0-9]/', '', trim((string)$value));
    return (string)$value;
}}

if (!function_exists('cpms_master_dedupe_money_key')) {
function cpms_master_dedupe_money_key($value)
{
    return number_format((float)$value, 2, '.', '');
}}

if (!function_exists('cpms_material_master_group_key_from_values')) {
function cpms_material_master_group_key_from_values($projectId, $category, $vendorName, $bizNo, $baseRate)
{
    $vendorKey = cpms_master_dedupe_text_key($vendorName);
    $bizKey = cpms_master_dedupe_biz_key($bizNo);
    $amountKey = cpms_master_dedupe_money_key($baseRate);
    $parts = array((int)$projectId, trim((string)$category), $vendorKey, $amountKey);
    if ($bizKey !== '') {
        $parts[count($parts)] = 'biz:' . $bizKey;
    } else {
        $parts[count($parts)] = 'biz:empty';
    }
    return implode('|', $parts);
}}

if (!function_exists('cpms_material_master_group_key')) {
function cpms_material_master_group_key($row)
{
    return cpms_material_master_group_key_from_values(
        isset($row['project_id']) ? (int)$row['project_id'] : 0,
        isset($row['category']) ? $row['category'] : '',
        isset($row['vendor_name']) ? $row['vendor_name'] : '',
        isset($row['biz_no']) ? $row['biz_no'] : '',
        isset($row['base_rate']) ? $row['base_rate'] : 0
    );
}}

if (!function_exists('cpms_equipment_master_group_key_from_values')) {
function cpms_equipment_master_group_key_from_values($projectId, $category, $vendorName, $spec, $bizNo, $baseRate)
{
    $vendorKey = cpms_master_dedupe_text_key($vendorName);
    $specKey = cpms_master_dedupe_text_key($spec);
    $bizKey = cpms_master_dedupe_biz_key($bizNo);
    $amountKey = cpms_master_dedupe_money_key($baseRate);
    $parts = array((int)$projectId, trim((string)$category), $vendorKey, $specKey, $amountKey);
    if ($bizKey !== '') {
        $parts[count($parts)] = 'biz:' . $bizKey;
    } else {
        $parts[count($parts)] = 'biz:empty';
    }
    return implode('|', $parts);
}}

if (!function_exists('cpms_equipment_master_group_key')) {
function cpms_equipment_master_group_key($row)
{
    return cpms_equipment_master_group_key_from_values(
        isset($row['project_id']) ? (int)$row['project_id'] : 0,
        isset($row['category']) ? $row['category'] : '',
        isset($row['vendor_name']) ? $row['vendor_name'] : '',
        isset($row['spec']) ? $row['spec'] : '',
        isset($row['biz_no']) ? $row['biz_no'] : '',
        isset($row['base_rate']) ? $row['base_rate'] : 0
    );
}}

if (!function_exists('cpms_find_existing_material_item')) {
function cpms_find_existing_material_item($pdo, $projectId, $category, $vendorName, $bizNo, $baseRate)
{
    if (!$pdo || (int)$projectId <= 0) return null;
    $sql = "SELECT *
              FROM cpms_material_items
             WHERE project_id = :pid
               AND category = :category
               AND is_deleted = 0
               AND base_rate = :base_rate
             ORDER BY id ASC";
    $st = $pdo->prepare($sql);
    $st->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
    $st->bindValue(':category', trim((string)$category));
    $st->bindValue(':base_rate', (float)$baseRate);
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) return null;

    $targetVendor = cpms_master_dedupe_text_key($vendorName);
    $targetBiz = cpms_master_dedupe_biz_key($bizNo);
    foreach ($rows as $row) {
        $rowVendor = cpms_master_dedupe_text_key(isset($row['vendor_name']) ? $row['vendor_name'] : '');
        if ($rowVendor !== $targetVendor) continue;
        $rowBiz = cpms_master_dedupe_biz_key(isset($row['biz_no']) ? $row['biz_no'] : '');
        if ($targetBiz !== '' && $rowBiz !== '') {
            if ($rowBiz === $targetBiz) return $row;
            continue;
        }
        return $row;
    }
    return null;
}}

if (!function_exists('cpms_find_existing_equipment_item')) {
function cpms_find_existing_equipment_item($pdo, $projectId, $category, $vendorName, $spec, $bizNo, $baseRate)
{
    if (!$pdo || (int)$projectId <= 0) return null;
    $sql = "SELECT *
              FROM cpms_equipment_items
             WHERE project_id = :pid
               AND category = :category
               AND is_deleted = 0
               AND base_rate = :base_rate
             ORDER BY id ASC";
    $st = $pdo->prepare($sql);
    $st->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
    $st->bindValue(':category', trim((string)$category));
    $st->bindValue(':base_rate', (float)$baseRate);
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) return null;

    $targetVendor = cpms_master_dedupe_text_key($vendorName);
    $targetSpec = cpms_master_dedupe_text_key($spec);
    $targetBiz = cpms_master_dedupe_biz_key($bizNo);
    foreach ($rows as $row) {
        $rowVendor = cpms_master_dedupe_text_key(isset($row['vendor_name']) ? $row['vendor_name'] : '');
        $rowSpec = cpms_master_dedupe_text_key(isset($row['spec']) ? $row['spec'] : '');
        if ($rowVendor !== $targetVendor || $rowSpec !== $targetSpec) continue;
        $rowBiz = cpms_master_dedupe_biz_key(isset($row['biz_no']) ? $row['biz_no'] : '');
        if ($targetBiz !== '' && $rowBiz !== '') {
            if ($rowBiz === $targetBiz) return $row;
            continue;
        }
        return $row;
    }
    return null;
}}

if (!function_exists('cpms_merge_first_non_empty')) {
function cpms_merge_first_non_empty($baseValue, $newValue)
{
    $baseText = trim((string)$baseValue);
    $newText = trim((string)$newValue);
    if ($baseText !== '') return $baseValue;
    if ($newText === '') return $baseValue;
    return $newValue;
}}

if (!function_exists('cpms_update_material_item_fill_blanks')) {
function cpms_update_material_item_fill_blanks($pdo, $itemId, $sourceRow, $now)
{
    if (!$pdo || (int)$itemId <= 0 || !is_array($sourceRow)) return false;
    $st = $pdo->prepare("SELECT representative, phone, biz_no, remark FROM cpms_material_items WHERE id = :id LIMIT 1");
    $st->bindValue(':id', (int)$itemId, PDO::PARAM_INT);
    $st->execute();
    $existing = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($existing)) return false;

    $nextRepresentative = cpms_merge_first_non_empty(isset($existing['representative']) ? $existing['representative'] : '', isset($sourceRow['representative']) ? $sourceRow['representative'] : '');
    $nextPhone = cpms_merge_first_non_empty(isset($existing['phone']) ? $existing['phone'] : '', isset($sourceRow['phone']) ? $sourceRow['phone'] : '');
    $nextBiz = cpms_merge_first_non_empty(isset($existing['biz_no']) ? $existing['biz_no'] : '', isset($sourceRow['biz_no']) ? $sourceRow['biz_no'] : '');
    $nextRemark = cpms_merge_first_non_empty(isset($existing['remark']) ? $existing['remark'] : '', isset($sourceRow['remark']) ? $sourceRow['remark'] : '');

    if (
        (string)$nextRepresentative === (string)(isset($existing['representative']) ? $existing['representative'] : '') &&
        (string)$nextPhone === (string)(isset($existing['phone']) ? $existing['phone'] : '') &&
        (string)$nextBiz === (string)(isset($existing['biz_no']) ? $existing['biz_no'] : '') &&
        (string)$nextRemark === (string)(isset($existing['remark']) ? $existing['remark'] : '')
    ) {
        return false;
    }

    $up = $pdo->prepare("UPDATE cpms_material_items
                            SET representative = :representative,
                                phone = :phone,
                                biz_no = :biz_no,
                                remark = :remark,
                                updated_at = :updated_at
                          WHERE id = :id");
    $up->bindValue(':representative', $nextRepresentative);
    $up->bindValue(':phone', $nextPhone);
    $up->bindValue(':biz_no', $nextBiz);
    $up->bindValue(':remark', $nextRemark);
    $up->bindValue(':updated_at', $now);
    $up->bindValue(':id', (int)$itemId, PDO::PARAM_INT);
    $up->execute();
    return true;
}}

if (!function_exists('cpms_update_equipment_item_fill_blanks')) {
function cpms_update_equipment_item_fill_blanks($pdo, $itemId, $sourceRow, $now)
{
    if (!$pdo || (int)$itemId <= 0 || !is_array($sourceRow)) return false;
    $st = $pdo->prepare("SELECT representative, phone, biz_no, remark FROM cpms_equipment_items WHERE id = :id LIMIT 1");
    $st->bindValue(':id', (int)$itemId, PDO::PARAM_INT);
    $st->execute();
    $existing = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($existing)) return false;

    $nextRepresentative = cpms_merge_first_non_empty(isset($existing['representative']) ? $existing['representative'] : '', isset($sourceRow['representative']) ? $sourceRow['representative'] : '');
    $nextPhone = cpms_merge_first_non_empty(isset($existing['phone']) ? $existing['phone'] : '', isset($sourceRow['phone']) ? $sourceRow['phone'] : '');
    $nextBiz = cpms_merge_first_non_empty(isset($existing['biz_no']) ? $existing['biz_no'] : '', isset($sourceRow['biz_no']) ? $sourceRow['biz_no'] : '');
    $nextRemark = cpms_merge_first_non_empty(isset($existing['remark']) ? $existing['remark'] : '', isset($sourceRow['remark']) ? $sourceRow['remark'] : '');

    if (
        (string)$nextRepresentative === (string)(isset($existing['representative']) ? $existing['representative'] : '') &&
        (string)$nextPhone === (string)(isset($existing['phone']) ? $existing['phone'] : '') &&
        (string)$nextBiz === (string)(isset($existing['biz_no']) ? $existing['biz_no'] : '') &&
        (string)$nextRemark === (string)(isset($existing['remark']) ? $existing['remark'] : '')
    ) {
        return false;
    }

    $up = $pdo->prepare("UPDATE cpms_equipment_items
                            SET representative = :representative,
                                phone = :phone,
                                biz_no = :biz_no,
                                remark = :remark,
                                updated_at = :updated_at
                          WHERE id = :id");
    $up->bindValue(':representative', $nextRepresentative);
    $up->bindValue(':phone', $nextPhone);
    $up->bindValue(':biz_no', $nextBiz);
    $up->bindValue(':remark', $nextRemark);
    $up->bindValue(':updated_at', $now);
    $up->bindValue(':id', (int)$itemId, PDO::PARAM_INT);
    $up->execute();
    return true;
}}

if (!function_exists('cpms_material_duplicate_groups')) {
function cpms_material_duplicate_groups($pdo)
{
    $result = array('groups' => array(), 'summary' => array('group_count' => 0, 'mergeable_count' => 0, 'conflict_count' => 0));
    if (!$pdo) return $result;

    $st = $pdo->query("SELECT * FROM cpms_material_items WHERE is_deleted = 0 ORDER BY project_id ASC, category ASC, vendor_name ASC, base_rate ASC, id ASC");
    $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : array();
    if (!is_array($rows)) $rows = array();

    $groups = array();
    foreach ($rows as $row) {
        $key = cpms_material_master_group_key($row);
        if (!isset($groups[$key])) $groups[$key] = array();
        $groups[$key][count($groups[$key])] = $row;
    }

    foreach ($groups as $groupRows) {
        if (count($groupRows) <= 1) continue;
        $idList = array();
        $mainId = 0;
        foreach ($groupRows as $row) {
            $itemId = isset($row['id']) ? (int)$row['id'] : 0;
            if ($itemId <= 0) continue;
            $idList[count($idList)] = $itemId;
            if ($mainId === 0 || $itemId < $mainId) $mainId = $itemId;
        }
        if ($mainId <= 0 || count($idList) <= 1) continue;

        $placeholders = implode(',', array_fill(0, count($idList), '?'));
        $stUsage = $pdo->prepare("SELECT * FROM cpms_material_usage WHERE material_id IN (" . $placeholders . ") ORDER BY use_date ASC, id ASC");
        $index = 1;
        foreach ($idList as $idValue) {
            $stUsage->bindValue($index, (int)$idValue, PDO::PARAM_INT);
            $index++;
        }
        $stUsage->execute();
        $usageRows = $stUsage->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($usageRows)) $usageRows = array();

        $dateMap = array();
        foreach ($usageRows as $usageRow) {
            $dateKey = isset($usageRow['use_date']) ? (string)$usageRow['use_date'] : '';
            if ($dateKey === '') continue;
            if (!isset($dateMap[$dateKey])) $dateMap[$dateKey] = array();
            $dateMap[$dateKey][count($dateMap[$dateKey])] = $usageRow;
        }

        $conflicts = array();
        foreach ($dateMap as $dateKey => $dateRows) {
            if (count($dateRows) <= 1) continue;
            $baseAmount = null;
            $sameAmount = true;
            foreach ($dateRows as $dateRow) {
                $amountValue = cpms_master_dedupe_money_key(isset($dateRow['amount']) ? $dateRow['amount'] : 0);
                if ($baseAmount === null) {
                    $baseAmount = $amountValue;
                } else if ($baseAmount !== $amountValue) {
                    $sameAmount = false;
                    break;
                }
            }
            if (!$sameAmount) {
                $conflicts[count($conflicts)] = array('date' => $dateKey, 'reason' => 'amount_mismatch', 'rows' => $dateRows);
            }
        }

        $group = array(
            'project_id' => isset($groupRows[0]['project_id']) ? (int)$groupRows[0]['project_id'] : 0,
            'category' => isset($groupRows[0]['category']) ? (string)$groupRows[0]['category'] : '',
            'vendor_name' => isset($groupRows[0]['vendor_name']) ? (string)$groupRows[0]['vendor_name'] : '',
            'biz_no' => isset($groupRows[0]['biz_no']) ? (string)$groupRows[0]['biz_no'] : '',
            'base_rate' => isset($groupRows[0]['base_rate']) ? (float)$groupRows[0]['base_rate'] : 0,
            'main_id' => $mainId,
            'duplicate_ids' => array_values(array_diff($idList, array($mainId))),
            'rows' => $groupRows,
            'usage_rows' => $usageRows,
            'conflicts' => $conflicts,
            'mergeable' => (count($conflicts) === 0)
        );
        $result['groups'][count($result['groups'])] = $group;
    }

    $result['summary']['group_count'] = count($result['groups']);
    foreach ($result['groups'] as $group) {
        if (isset($group['mergeable']) && $group['mergeable']) $result['summary']['mergeable_count']++;
        else $result['summary']['conflict_count']++;
    }
    return $result;
}}

if (!function_exists('cpms_equipment_duplicate_groups')) {
function cpms_equipment_duplicate_groups($pdo)
{
    $result = array('groups' => array(), 'summary' => array('group_count' => 0, 'mergeable_count' => 0, 'conflict_count' => 0));
    if (!$pdo) return $result;

    $st = $pdo->query("SELECT * FROM cpms_equipment_items WHERE is_deleted = 0 ORDER BY project_id ASC, category ASC, vendor_name ASC, spec ASC, base_rate ASC, id ASC");
    $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : array();
    if (!is_array($rows)) $rows = array();

    $groups = array();
    foreach ($rows as $row) {
        $key = cpms_equipment_master_group_key($row);
        if (!isset($groups[$key])) $groups[$key] = array();
        $groups[$key][count($groups[$key])] = $row;
    }

    foreach ($groups as $groupRows) {
        if (count($groupRows) <= 1) continue;
        $idList = array();
        $mainId = 0;
        foreach ($groupRows as $row) {
            $itemId = isset($row['id']) ? (int)$row['id'] : 0;
            if ($itemId <= 0) continue;
            $idList[count($idList)] = $itemId;
            if ($mainId === 0 || $itemId < $mainId) $mainId = $itemId;
        }
        if ($mainId <= 0 || count($idList) <= 1) continue;

        $placeholders = implode(',', array_fill(0, count($idList), '?'));
        $stUsage = $pdo->prepare("SELECT * FROM cpms_equipment_usage WHERE equipment_id IN (" . $placeholders . ") ORDER BY use_date ASC, id ASC");
        $index = 1;
        foreach ($idList as $idValue) {
            $stUsage->bindValue($index, (int)$idValue, PDO::PARAM_INT);
            $index++;
        }
        $stUsage->execute();
        $usageRows = $stUsage->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($usageRows)) $usageRows = array();

        $usageIdList = array();
        $dateMap = array();
        foreach ($usageRows as $usageRow) {
            $usageId = isset($usageRow['id']) ? (int)$usageRow['id'] : 0;
            if ($usageId > 0) $usageIdList[count($usageIdList)] = $usageId;
            $dateKey = isset($usageRow['use_date']) ? (string)$usageRow['use_date'] : '';
            if ($dateKey === '') continue;
            if (!isset($dateMap[$dateKey])) $dateMap[$dateKey] = array();
            $dateMap[$dateKey][count($dateMap[$dateKey])] = $usageRow;
        }

        $pendingOverrideMap = array();
        if (count($usageIdList) > 0) {
            $usagePlaceholders = implode(',', array_fill(0, count($usageIdList), '?'));
            $stPending = $pdo->prepare("SELECT * FROM cpms_equipment_gongsu_overrides WHERE equipment_usage_id IN (" . $usagePlaceholders . ") AND status = 'pending' ORDER BY id ASC");
            $pIndex = 1;
            foreach ($usageIdList as $usageIdValue) {
                $stPending->bindValue($pIndex, (int)$usageIdValue, PDO::PARAM_INT);
                $pIndex++;
            }
            $stPending->execute();
            $pendingRows = $stPending->fetchAll(PDO::FETCH_ASSOC);
            if (is_array($pendingRows)) {
                foreach ($pendingRows as $pendingRow) {
                    $pendingOverrideMap[(int)$pendingRow['equipment_usage_id']] = $pendingRow;
                }
            }
        }

        $conflicts = array();
        foreach ($dateMap as $dateKey => $dateRows) {
            if (count($dateRows) <= 1) continue;
            $baseSignature = null;
            $sameValue = true;
            foreach ($dateRows as $dateRow) {
                $usageId = isset($dateRow['id']) ? (int)$dateRow['id'] : 0;
                if ($usageId > 0 && isset($pendingOverrideMap[$usageId])) {
                    $sameValue = false;
                    $conflicts[count($conflicts)] = array('date' => $dateKey, 'reason' => 'pending_override', 'rows' => $dateRows);
                    break;
                }
                $signature = cpms_master_dedupe_money_key(isset($dateRow['work_unit']) ? $dateRow['work_unit'] : 0) . '|' .
                    cpms_master_dedupe_money_key(isset($dateRow['base_rate_snapshot']) ? $dateRow['base_rate_snapshot'] : 0) . '|' .
                    cpms_master_dedupe_money_key(isset($dateRow['amount']) ? $dateRow['amount'] : 0);
                if ($baseSignature === null) {
                    $baseSignature = $signature;
                } else if ($baseSignature !== $signature) {
                    $sameValue = false;
                    $conflicts[count($conflicts)] = array('date' => $dateKey, 'reason' => 'usage_mismatch', 'rows' => $dateRows);
                    break;
                }
            }
            if (!$sameValue) continue;
        }

        if (count($conflicts) === 0 && count($pendingOverrideMap) > 0) {
            foreach ($pendingOverrideMap as $pendingRow) {
                $conflicts[count($conflicts)] = array(
                    'date' => isset($pendingRow['use_date']) ? (string)$pendingRow['use_date'] : '',
                    'reason' => 'pending_override',
                    'rows' => array($pendingRow)
                );
            }
        }

        $group = array(
            'project_id' => isset($groupRows[0]['project_id']) ? (int)$groupRows[0]['project_id'] : 0,
            'category' => isset($groupRows[0]['category']) ? (string)$groupRows[0]['category'] : '',
            'vendor_name' => isset($groupRows[0]['vendor_name']) ? (string)$groupRows[0]['vendor_name'] : '',
            'spec' => isset($groupRows[0]['spec']) ? (string)$groupRows[0]['spec'] : '',
            'biz_no' => isset($groupRows[0]['biz_no']) ? (string)$groupRows[0]['biz_no'] : '',
            'base_rate' => isset($groupRows[0]['base_rate']) ? (float)$groupRows[0]['base_rate'] : 0,
            'main_id' => $mainId,
            'duplicate_ids' => array_values(array_diff($idList, array($mainId))),
            'rows' => $groupRows,
            'usage_rows' => $usageRows,
            'pending_override_map' => $pendingOverrideMap,
            'conflicts' => $conflicts,
            'mergeable' => (count($conflicts) === 0)
        );
        $result['groups'][count($result['groups'])] = $group;
    }

    $result['summary']['group_count'] = count($result['groups']);
    foreach ($result['groups'] as $group) {
        if (isset($group['mergeable']) && $group['mergeable']) $result['summary']['mergeable_count']++;
        else $result['summary']['conflict_count']++;
    }
    return $result;
}}

if (!function_exists('cpms_material_dedupe_apply')) {
function cpms_material_dedupe_apply($pdo)
{
    $scan = cpms_material_duplicate_groups($pdo);
    $result = array('merged_groups' => 0, 'skipped_groups' => 0, 'message' => '');
    if (!$pdo) return $result;

    $now = date('Y-m-d H:i:s');
    foreach ($scan['groups'] as $group) {
        if (!isset($group['mergeable']) || !$group['mergeable']) {
            $result['skipped_groups']++;
            continue;
        }

        $mainId = isset($group['main_id']) ? (int)$group['main_id'] : 0;
        $duplicateIds = isset($group['duplicate_ids']) && is_array($group['duplicate_ids']) ? $group['duplicate_ids'] : array();
        if ($mainId <= 0 || count($duplicateIds) === 0) continue;

        $pdo->beginTransaction();
        try {
            foreach ($group['rows'] as $row) {
                $itemId = isset($row['id']) ? (int)$row['id'] : 0;
                if ($itemId > 0 && $itemId !== $mainId) {
                    cpms_update_material_item_fill_blanks($pdo, $mainId, $row, $now);
                }
            }

            foreach ($duplicateIds as $duplicateId) {
                $stUsage = $pdo->prepare("SELECT * FROM cpms_material_usage WHERE material_id = :mid ORDER BY use_date ASC, id ASC");
                $stUsage->bindValue(':mid', (int)$duplicateId, PDO::PARAM_INT);
                $stUsage->execute();
                $usageRows = $stUsage->fetchAll(PDO::FETCH_ASSOC);
                if (!is_array($usageRows)) $usageRows = array();

                foreach ($usageRows as $usageRow) {
                    $usageId = isset($usageRow['id']) ? (int)$usageRow['id'] : 0;
                    $useDate = isset($usageRow['use_date']) ? (string)$usageRow['use_date'] : '';
                    $amount = isset($usageRow['amount']) ? (float)$usageRow['amount'] : 0.0;
                    $memo = isset($usageRow['memo']) ? (string)$usageRow['memo'] : '';

                    $stMainUsage = $pdo->prepare("SELECT * FROM cpms_material_usage WHERE project_id = :pid AND material_id = :mid AND use_date = :use_date LIMIT 1");
                    $stMainUsage->bindValue(':pid', isset($usageRow['project_id']) ? (int)$usageRow['project_id'] : 0, PDO::PARAM_INT);
                    $stMainUsage->bindValue(':mid', $mainId, PDO::PARAM_INT);
                    $stMainUsage->bindValue(':use_date', $useDate);
                    $stMainUsage->execute();
                    $mainUsage = $stMainUsage->fetch(PDO::FETCH_ASSOC);

                    if ($mainUsage) {
                        $mainAmount = isset($mainUsage['amount']) ? (float)$mainUsage['amount'] : 0.0;
                        if (cpms_master_dedupe_money_key($mainAmount) === cpms_master_dedupe_money_key($amount)) {
                            if (trim((string)(isset($mainUsage['memo']) ? $mainUsage['memo'] : '')) === '' && trim((string)$memo) !== '') {
                                $upMain = $pdo->prepare("UPDATE cpms_material_usage SET memo = :memo WHERE id = :id");
                                $upMain->bindValue(':memo', $memo);
                                $upMain->bindValue(':id', (int)$mainUsage['id'], PDO::PARAM_INT);
                                $upMain->execute();
                            }
                            $delUsage = $pdo->prepare("DELETE FROM cpms_material_usage WHERE id = :id");
                            $delUsage->bindValue(':id', $usageId, PDO::PARAM_INT);
                            $delUsage->execute();
                        }
                    } else {
                        $moveUsage = $pdo->prepare("UPDATE cpms_material_usage SET material_id = :main_id WHERE id = :id");
                        $moveUsage->bindValue(':main_id', $mainId, PDO::PARAM_INT);
                        $moveUsage->bindValue(':id', $usageId, PDO::PARAM_INT);
                        $moveUsage->execute();
                    }
                }

                $deactivate = $pdo->prepare("UPDATE cpms_material_items SET is_deleted = 1, updated_at = :updated_at WHERE id = :id");
                $deactivate->bindValue(':updated_at', $now);
                $deactivate->bindValue(':id', (int)$duplicateId, PDO::PARAM_INT);
                $deactivate->execute();
            }

            $pdo->commit();
            $result['merged_groups']++;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $result['skipped_groups']++;
        }
    }

    $result['message'] = '자동 병합 ' . (int)$result['merged_groups'] . '개 / 충돌 또는 건너뜀 ' . (int)$result['skipped_groups'] . '개';
    return $result;
}}

if (!function_exists('cpms_equipment_dedupe_apply')) {
function cpms_equipment_dedupe_apply($pdo)
{
    $scan = cpms_equipment_duplicate_groups($pdo);
    $result = array('merged_groups' => 0, 'skipped_groups' => 0, 'message' => '');
    if (!$pdo) return $result;

    $now = date('Y-m-d H:i:s');
    foreach ($scan['groups'] as $group) {
        if (!isset($group['mergeable']) || !$group['mergeable']) {
            $result['skipped_groups']++;
            continue;
        }

        $mainId = isset($group['main_id']) ? (int)$group['main_id'] : 0;
        $duplicateIds = isset($group['duplicate_ids']) && is_array($group['duplicate_ids']) ? $group['duplicate_ids'] : array();
        if ($mainId <= 0 || count($duplicateIds) === 0) continue;

        $pdo->beginTransaction();
        try {
            foreach ($group['rows'] as $row) {
                $itemId = isset($row['id']) ? (int)$row['id'] : 0;
                if ($itemId > 0 && $itemId !== $mainId) {
                    cpms_update_equipment_item_fill_blanks($pdo, $mainId, $row, $now);
                }
            }

            foreach ($duplicateIds as $duplicateId) {
                $stUsage = $pdo->prepare("SELECT * FROM cpms_equipment_usage WHERE equipment_id = :eid ORDER BY use_date ASC, id ASC");
                $stUsage->bindValue(':eid', (int)$duplicateId, PDO::PARAM_INT);
                $stUsage->execute();
                $usageRows = $stUsage->fetchAll(PDO::FETCH_ASSOC);
                if (!is_array($usageRows)) $usageRows = array();

                foreach ($usageRows as $usageRow) {
                    $usageId = isset($usageRow['id']) ? (int)$usageRow['id'] : 0;
                    $useDate = isset($usageRow['use_date']) ? (string)$usageRow['use_date'] : '';
                    $projectId = isset($usageRow['project_id']) ? (int)$usageRow['project_id'] : 0;
                    $signature = cpms_master_dedupe_money_key(isset($usageRow['work_unit']) ? $usageRow['work_unit'] : 0) . '|' .
                        cpms_master_dedupe_money_key(isset($usageRow['base_rate_snapshot']) ? $usageRow['base_rate_snapshot'] : 0) . '|' .
                        cpms_master_dedupe_money_key(isset($usageRow['amount']) ? $usageRow['amount'] : 0);

                    $stMainUsage = $pdo->prepare("SELECT * FROM cpms_equipment_usage WHERE project_id = :pid AND equipment_id = :eid AND use_date = :use_date LIMIT 1");
                    $stMainUsage->bindValue(':pid', $projectId, PDO::PARAM_INT);
                    $stMainUsage->bindValue(':eid', $mainId, PDO::PARAM_INT);
                    $stMainUsage->bindValue(':use_date', $useDate);
                    $stMainUsage->execute();
                    $mainUsage = $stMainUsage->fetch(PDO::FETCH_ASSOC);

                    if ($mainUsage) {
                        $mainSignature = cpms_master_dedupe_money_key(isset($mainUsage['work_unit']) ? $mainUsage['work_unit'] : 0) . '|' .
                            cpms_master_dedupe_money_key(isset($mainUsage['base_rate_snapshot']) ? $mainUsage['base_rate_snapshot'] : 0) . '|' .
                            cpms_master_dedupe_money_key(isset($mainUsage['amount']) ? $mainUsage['amount'] : 0);
                        if ($mainSignature === $signature) {
                            $updateOverrides = $pdo->prepare("UPDATE cpms_equipment_gongsu_overrides
                                                                 SET equipment_usage_id = :main_usage_id,
                                                                     equipment_id = :main_equipment_id
                                                               WHERE equipment_usage_id = :usage_id");
                            $updateOverrides->bindValue(':main_usage_id', (int)$mainUsage['id'], PDO::PARAM_INT);
                            $updateOverrides->bindValue(':main_equipment_id', $mainId, PDO::PARAM_INT);
                            $updateOverrides->bindValue(':usage_id', $usageId, PDO::PARAM_INT);
                            $updateOverrides->execute();

                            $deleteUsage = $pdo->prepare("DELETE FROM cpms_equipment_usage WHERE id = :id");
                            $deleteUsage->bindValue(':id', $usageId, PDO::PARAM_INT);
                            $deleteUsage->execute();
                        }
                    } else {
                        $moveUsage = $pdo->prepare("UPDATE cpms_equipment_usage SET equipment_id = :main_id WHERE id = :id");
                        $moveUsage->bindValue(':main_id', $mainId, PDO::PARAM_INT);
                        $moveUsage->bindValue(':id', $usageId, PDO::PARAM_INT);
                        $moveUsage->execute();

                        $updateUsageOverrides = $pdo->prepare("UPDATE cpms_equipment_gongsu_overrides SET equipment_id = :main_id WHERE equipment_usage_id = :usage_id");
                        $updateUsageOverrides->bindValue(':main_id', $mainId, PDO::PARAM_INT);
                        $updateUsageOverrides->bindValue(':usage_id', $usageId, PDO::PARAM_INT);
                        $updateUsageOverrides->execute();
                    }
                }

                $updateAllOverrides = $pdo->prepare("UPDATE cpms_equipment_gongsu_overrides SET equipment_id = :main_id WHERE equipment_id = :duplicate_id");
                $updateAllOverrides->bindValue(':main_id', $mainId, PDO::PARAM_INT);
                $updateAllOverrides->bindValue(':duplicate_id', (int)$duplicateId, PDO::PARAM_INT);
                $updateAllOverrides->execute();

                $deactivate = $pdo->prepare("UPDATE cpms_equipment_items SET is_deleted = 1, updated_at = :updated_at WHERE id = :id");
                $deactivate->bindValue(':updated_at', $now);
                $deactivate->bindValue(':id', (int)$duplicateId, PDO::PARAM_INT);
                $deactivate->execute();
            }

            $pdo->commit();
            $result['merged_groups']++;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $result['skipped_groups']++;
        }
    }

    $result['message'] = '자동 병합 ' . (int)$result['merged_groups'] . '개 / 충돌 또는 건너뜀 ' . (int)$result['skipped_groups'] . '개';
    return $result;
}}
