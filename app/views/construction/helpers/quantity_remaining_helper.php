<?php
/**
 * Contract item remaining quantity helper.
 * - PHP 5.6 compatible
 */

if (!function_exists('cpms_qr_table_exists')) {
function cpms_qr_table_exists($pdo, $table) {
    if (!$pdo || $table === '') return false;
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t");
        $st->bindValue(':t', (string)$table);
        $st->execute();
        return ((int)$st->fetchColumn() > 0);
    } catch (Exception $e) {
        return false;
    }
}
}

if (!function_exists('cpms_qr_column_exists')) {
function cpms_qr_column_exists($pdo, $table, $column) {
    if (!$pdo || $table === '' || $column === '') return false;
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `" . $table . "` LIKE :col");
        $st->bindValue(':col', (string)$column);
        $st->execute();
        return $st->fetch() ? true : false;
    } catch (Exception $e) {
        return false;
    }
}
}

if (!function_exists('cpms_qr_number')) {
function cpms_qr_number($value) {
    if ($value === null || $value === '') return 0.0;
    if (is_numeric((string)$value)) return (float)$value;
    $clean = preg_replace('/[^0-9.\-]/', '', (string)$value);
    return ($clean !== '' && is_numeric($clean)) ? (float)$clean : 0.0;
}
}

if (!function_exists('cpms_qr_unit_price_value')) {
function cpms_qr_unit_price_value($row) {
    $unitPrice = (isset($row['unit_price']) && is_numeric((string)$row['unit_price'])) ? (float)$row['unit_price'] : 0.0;
    if (abs($unitPrice) > 0.0001) return $unitPrice;
    $material = (isset($row['material_unit_price']) && is_numeric((string)$row['material_unit_price'])) ? (float)$row['material_unit_price'] : 0.0;
    $labor = (isset($row['labor_unit_price']) && is_numeric((string)$row['labor_unit_price'])) ? (float)$row['labor_unit_price'] : 0.0;
    $expense = (isset($row['expense_unit_price']) && is_numeric((string)$row['expense_unit_price'])) ? (float)$row['expense_unit_price'] : 0.0;
    return $material + $labor + $expense;
}
}

if (!function_exists('cpms_qr_search_text')) {
function cpms_qr_search_text($value) {
    $value = trim((string)$value);
    $value = str_replace(array("\r", "\n", ","), ' ', $value);
    $value = preg_replace('/\s+/u', ' ', $value);
    if (function_exists('mb_strtolower')) return mb_strtolower($value, 'UTF-8');
    return strtolower($value);
}
}

if (!function_exists('cpms_contract_item_row')) {
function cpms_contract_item_row($pdo, $projectId, $contractItemId) {
    if (!$pdo || (int)$projectId <= 0 || (int)$contractItemId <= 0) return null;
    if (!cpms_qr_table_exists($pdo, 'cpms_project_unit_prices')) return null;
    try {
        $st = $pdo->prepare("SELECT * FROM cpms_project_unit_prices WHERE project_id = :pid AND id = :id LIMIT 1");
        $st->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
        $st->bindValue(':id', (int)$contractItemId, PDO::PARAM_INT);
        $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    } catch (Exception $e) {
        return null;
    }
}
}

if (!function_exists('cpms_contract_item_used_quantity_excluding_task')) {
function cpms_contract_item_used_quantity_excluding_task($pdo, $projectId, $contractItemId, $excludeTaskId) {
    if (!$pdo || (int)$projectId <= 0 || (int)$contractItemId <= 0) return 0.0;
    if (!cpms_qr_table_exists($pdo, 'cpms_schedule_tasks')) return 0.0;
    if (!cpms_qr_table_exists($pdo, 'cpms_work_item_lines')) return 0.0;
    if (!cpms_qr_table_exists($pdo, 'cpms_project_unit_prices')) return 0.0;
    if (!cpms_qr_column_exists($pdo, 'cpms_schedule_tasks', 'work_id')) return 0.0;

    try {
        $sql = "SELECT COALESCE(SUM(COALESCE(l.planned_qty, u.qty, 0)), 0)
                  FROM cpms_schedule_tasks st
                  INNER JOIN cpms_work_item_lines l ON l.work_id = st.work_id
                  INNER JOIN cpms_project_unit_prices u ON u.id = l.unit_price_id AND u.project_id = st.project_id
                 WHERE st.project_id = :pid
                   AND l.unit_price_id = :uid
                   AND st.work_id IS NOT NULL
                   AND st.work_id > 0";
        if ((int)$excludeTaskId > 0) {
            $sql .= " AND st.id <> :exclude_task_id";
        }
        $st = $pdo->prepare($sql);
        $st->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
        $st->bindValue(':uid', (int)$contractItemId, PDO::PARAM_INT);
        if ((int)$excludeTaskId > 0) {
            $st->bindValue(':exclude_task_id', (int)$excludeTaskId, PDO::PARAM_INT);
        }
        $st->execute();
        return (float)$st->fetchColumn();
    } catch (Exception $e) {
        return 0.0;
    }
}
}

if (!function_exists('cpms_contract_item_used_quantity')) {
function cpms_contract_item_used_quantity($pdo, $projectId, $contractItemId, $excludeWorkId) {
    if (!$pdo || (int)$projectId <= 0 || (int)$contractItemId <= 0) return 0.0;
    if (!cpms_qr_table_exists($pdo, 'cpms_schedule_tasks')) return 0.0;
    if (!cpms_qr_table_exists($pdo, 'cpms_work_item_lines')) return 0.0;
    if (!cpms_qr_table_exists($pdo, 'cpms_project_unit_prices')) return 0.0;
    if (!cpms_qr_column_exists($pdo, 'cpms_schedule_tasks', 'work_id')) return 0.0;

    try {
        $sql = "SELECT COALESCE(SUM(COALESCE(l.planned_qty, u.qty, 0)), 0)
                  FROM cpms_schedule_tasks st
                  INNER JOIN cpms_work_item_lines l ON l.work_id = st.work_id
                  INNER JOIN cpms_project_unit_prices u ON u.id = l.unit_price_id AND u.project_id = st.project_id
                 WHERE st.project_id = :pid
                   AND l.unit_price_id = :uid
                   AND st.work_id IS NOT NULL
                   AND st.work_id > 0";
        if ((int)$excludeWorkId > 0) {
            $sql .= " AND l.work_id <> :exclude_work_id";
        }
        $st = $pdo->prepare($sql);
        $st->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
        $st->bindValue(':uid', (int)$contractItemId, PDO::PARAM_INT);
        if ((int)$excludeWorkId > 0) {
            $st->bindValue(':exclude_work_id', (int)$excludeWorkId, PDO::PARAM_INT);
        }
        $st->execute();
        return (float)$st->fetchColumn();
    } catch (Exception $e) {
        return 0.0;
    }
}
}

if (!function_exists('cpms_contract_item_remaining_quantity')) {
function cpms_contract_item_remaining_quantity($pdo, $projectId, $contractItemId, $excludeWorkId) {
    $row = cpms_contract_item_row($pdo, $projectId, $contractItemId);
    if (!is_array($row)) return 0.0;
    $contractQty = cpms_qr_number(isset($row['qty']) ? $row['qty'] : 0);
    $usedQty = cpms_contract_item_used_quantity($pdo, $projectId, $contractItemId, $excludeWorkId);
    return $contractQty - $usedQty;
}
}

if (!function_exists('cpms_contract_item_remaining_quantity_excluding_task')) {
function cpms_contract_item_remaining_quantity_excluding_task($pdo, $projectId, $contractItemId, $excludeTaskId) {
    $row = cpms_contract_item_row($pdo, $projectId, $contractItemId);
    if (!is_array($row)) return 0.0;
    $contractQty = cpms_qr_number(isset($row['qty']) ? $row['qty'] : 0);
    $usedQty = cpms_contract_item_used_quantity_excluding_task($pdo, $projectId, $contractItemId, $excludeTaskId);
    return $contractQty - $usedQty;
}
}

if (!function_exists('cpms_contract_items_with_remaining_quantity')) {
function cpms_contract_items_with_remaining_quantity($pdo, $projectId, $filters) {
    $items = array();
    if (!$pdo || (int)$projectId <= 0) return $items;
    if (!cpms_qr_table_exists($pdo, 'cpms_project_unit_prices')) return $items;
    if (!is_array($filters)) $filters = array();

    $includeDepleted = isset($filters['include_depleted']) ? (bool)$filters['include_depleted'] : true;
    $excludeWorkId = isset($filters['exclude_work_id']) ? (int)$filters['exclude_work_id'] : 0;
    $q = isset($filters['q']) ? cpms_qr_search_text($filters['q']) : '';
    $limit = isset($filters['limit']) ? (int)$filters['limit'] : 2000;
    if ($limit <= 0) $limit = 2000;

    $hasIsActive = cpms_qr_column_exists($pdo, 'cpms_project_unit_prices', 'is_active');
    $hasMaterial = cpms_qr_column_exists($pdo, 'cpms_project_unit_prices', 'material_unit_price');
    $hasLabor = cpms_qr_column_exists($pdo, 'cpms_project_unit_prices', 'labor_unit_price');
    $hasExpense = cpms_qr_column_exists($pdo, 'cpms_project_unit_prices', 'expense_unit_price');
    $hasImportOrder = cpms_qr_column_exists($pdo, 'cpms_project_unit_prices', 'import_order');

    try {
        $sql = "SELECT id, item_name, spec, unit, qty, unit_price";
        $sql .= $hasMaterial ? ", material_unit_price" : ", NULL AS material_unit_price";
        $sql .= $hasLabor ? ", labor_unit_price" : ", NULL AS labor_unit_price";
        $sql .= $hasExpense ? ", expense_unit_price" : ", NULL AS expense_unit_price";
        $sql .= " FROM cpms_project_unit_prices WHERE project_id = :pid";
        if ($hasIsActive) $sql .= " AND (is_active = 1 OR is_active IS NULL)";
        $sql .= $hasImportOrder ? " ORDER BY COALESCE(import_order, id) ASC, id ASC" : " ORDER BY id ASC";
        $sql .= " LIMIT " . (int)$limit;
        $st = $pdo->prepare($sql);
        $st->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) return $items;

        foreach ($rows as $row) {
            $id = isset($row['id']) ? (int)$row['id'] : 0;
            if ($id <= 0) continue;
            $unitPrice = cpms_qr_unit_price_value($row);
            $searchText = cpms_qr_search_text(
                (isset($row['item_name']) ? $row['item_name'] : '') . ' ' .
                (isset($row['spec']) ? $row['spec'] : '') . ' ' .
                (isset($row['unit']) ? $row['unit'] : '') . ' ' .
                (string)$unitPrice
            );
            if ($q !== '' && strpos($searchText, $q) === false) continue;

            $contractQty = cpms_qr_number(isset($row['qty']) ? $row['qty'] : 0);
            $usedQty = cpms_contract_item_used_quantity($pdo, $projectId, $id, $excludeWorkId);
            $remainingQty = $contractQty - $usedQty;
            if (!$includeDepleted && $remainingQty <= 0.0001) continue;

            $row['contract_item_id'] = $id;
            $row['contract_quantity'] = $contractQty;
            $row['used_quantity'] = $usedQty;
            $row['remaining_quantity'] = $remainingQty;
            $row['unit_price_total'] = $unitPrice;
            array_push($items, $row);
        }
    } catch (Exception $e) {
        $items = array();
    }

    return $items;
}
}

if (!function_exists('cpms_work_item_schedule_count')) {
function cpms_work_item_schedule_count($pdo, $projectId, $workId, $excludeTaskId) {
    if (!$pdo || (int)$projectId <= 0 || (int)$workId <= 0) return 0;
    if (!cpms_qr_table_exists($pdo, 'cpms_schedule_tasks')) return 0;
    if (!cpms_qr_column_exists($pdo, 'cpms_schedule_tasks', 'work_id')) return 0;
    try {
        $sql = "SELECT COUNT(*) FROM cpms_schedule_tasks WHERE project_id = :pid AND work_id = :wid";
        if ((int)$excludeTaskId > 0) $sql .= " AND id <> :exclude_task_id";
        $st = $pdo->prepare($sql);
        $st->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
        $st->bindValue(':wid', (int)$workId, PDO::PARAM_INT);
        if ((int)$excludeTaskId > 0) $st->bindValue(':exclude_task_id', (int)$excludeTaskId, PDO::PARAM_INT);
        $st->execute();
        return (int)$st->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}
}

if (!function_exists('cpms_work_item_validation_lines')) {
function cpms_work_item_validation_lines($pdo, $projectId, $workId) {
    $lines = array();
    if (!$pdo || (int)$projectId <= 0 || (int)$workId <= 0) return $lines;
    if (!cpms_qr_table_exists($pdo, 'cpms_work_item_lines')) return $lines;
    if (!cpms_qr_table_exists($pdo, 'cpms_project_unit_prices')) return $lines;

    try {
        $st = $pdo->prepare("SELECT l.unit_price_id, l.planned_qty, u.item_name, u.spec, u.unit, u.qty
                               FROM cpms_work_item_lines l
                               INNER JOIN cpms_project_unit_prices u ON u.id = l.unit_price_id
                              WHERE l.work_id = :wid AND u.project_id = :pid
                              ORDER BY u.id ASC");
        $st->bindValue(':wid', (int)$workId, PDO::PARAM_INT);
        $st->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : array();
    } catch (Exception $e) {
        return array();
    }
}
}

if (!function_exists('cpms_validate_work_item_line_quantities')) {
function cpms_validate_work_item_line_quantities($pdo, $projectId, $workId, $plannedQtyMap) {
    if (!$pdo || (int)$projectId <= 0) return array('ok' => false, 'message' => '프로젝트 정보가 올바르지 않습니다.');
    if (!is_array($plannedQtyMap)) return array('ok' => true, 'message' => '');

    $scheduledCount = ((int)$workId > 0) ? cpms_work_item_schedule_count($pdo, $projectId, $workId, 0) : 0;
    if ($scheduledCount <= 0) $scheduledCount = 1;

    foreach ($plannedQtyMap as $unitId => $plannedQtyRaw) {
        $unitId = (int)$unitId;
        if ($unitId <= 0) continue;
        $row = cpms_contract_item_row($pdo, $projectId, $unitId);
        if (!is_array($row)) {
            return array('ok' => false, 'message' => '선택한 내역서 항목을 찾을 수 없습니다.');
        }
        $contractQty = cpms_qr_number(isset($row['qty']) ? $row['qty'] : 0);
        $plannedQty = ($plannedQtyRaw === null || $plannedQtyRaw === '') ? $contractQty : cpms_qr_number($plannedQtyRaw);
        if ($plannedQty < 0) {
            return array('ok' => false, 'message' => '수량은 0보다 작을 수 없습니다.');
        }
        $availableForThisWork = cpms_contract_item_remaining_quantity($pdo, $projectId, $unitId, (int)$workId);
        $requiredQty = $plannedQty * $scheduledCount;
        if ($requiredQty > ($availableForThisWork + 0.0001)) {
            $itemName = isset($row['item_name']) ? (string)$row['item_name'] : '';
            return array('ok' => false, 'message' => '남은 수량보다 큰 수량은 입력할 수 없습니다. (' . $itemName . ')');
        }
    }

    return array('ok' => true, 'message' => '');
}
}

if (!function_exists('cpms_validate_work_item_can_be_scheduled')) {
function cpms_validate_work_item_can_be_scheduled($pdo, $projectId, $workId, $excludeTaskId) {
    if (!$pdo || (int)$projectId <= 0 || (int)$workId <= 0) return array('ok' => true, 'message' => '');
    $lines = cpms_work_item_validation_lines($pdo, $projectId, $workId);
    foreach ($lines as $line) {
        $unitId = isset($line['unit_price_id']) ? (int)$line['unit_price_id'] : 0;
        if ($unitId <= 0) continue;
        $contractQty = cpms_qr_number(isset($line['qty']) ? $line['qty'] : 0);
        $plannedQty = (isset($line['planned_qty']) && $line['planned_qty'] !== null && $line['planned_qty'] !== '') ? cpms_qr_number($line['planned_qty']) : $contractQty;
        $remainingQty = cpms_contract_item_remaining_quantity_excluding_task($pdo, $projectId, $unitId, (int)$excludeTaskId);
        if ($plannedQty > ($remainingQty + 0.0001)) {
            $itemName = isset($line['item_name']) ? (string)$line['item_name'] : '';
            return array('ok' => false, 'message' => '남은 수량보다 큰 수량은 입력할 수 없습니다. (' . $itemName . ')');
        }
    }
    return array('ok' => true, 'message' => '');
}
}
