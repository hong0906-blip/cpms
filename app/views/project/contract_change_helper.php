<?php
/**
 * Contract change comparison helper.
 * - PHP 5.6 compatible
 */

if (!function_exists('cpms_contract_change_table_exists')) {
function cpms_contract_change_table_exists($pdo, $table) {
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

if (!function_exists('cpms_contract_change_column_exists')) {
function cpms_contract_change_column_exists($pdo, $table, $column) {
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

if (!function_exists('cpms_contract_change_number')) {
function cpms_contract_change_number($value) {
    if ($value === null || $value === '') return 0.0;
    if (is_numeric((string)$value)) return (float)$value;
    $clean = preg_replace('/[^0-9.\-]/', '', (string)$value);
    return ($clean !== '' && is_numeric($clean)) ? (float)$clean : 0.0;
}
}

if (!function_exists('cpms_contract_change_number_same')) {
function cpms_contract_change_number_same($a, $b) {
    if (($a === null || $a === '') && ($b === null || $b === '')) return true;
    if (is_numeric((string)$a) && is_numeric((string)$b)) {
        return (abs(((float)$a) - ((float)$b)) < 0.0001);
    }
    return ((string)$a === (string)$b);
}
}

if (!function_exists('cpms_contract_change_unit_price_value')) {
function cpms_contract_change_unit_price_value($row) {
    $unitPrice = (isset($row['unit_price']) && is_numeric((string)$row['unit_price'])) ? (float)$row['unit_price'] : 0.0;
    if (abs($unitPrice) > 0.0001) return $unitPrice;
    $material = (isset($row['material_unit_price']) && is_numeric((string)$row['material_unit_price'])) ? (float)$row['material_unit_price'] : 0.0;
    $labor = (isset($row['labor_unit_price']) && is_numeric((string)$row['labor_unit_price'])) ? (float)$row['labor_unit_price'] : 0.0;
    $expense = (isset($row['expense_unit_price']) && is_numeric((string)$row['expense_unit_price'])) ? (float)$row['expense_unit_price'] : 0.0;
    return $material + $labor + $expense;
}
}

if (!function_exists('cpms_contract_change_normalize_value')) {
function cpms_contract_change_normalize_value($value) {
    $value = trim((string)$value);
    $value = str_replace(array("\r", "\n", ","), ' ', $value);
    $value = preg_replace('/\s+/u', ' ', $value);
    $value = trim((string)$value);
    if (function_exists('mb_strtolower')) return mb_strtolower($value, 'UTF-8');
    return strtolower($value);
}
}

if (!function_exists('cpms_contract_change_row_key')) {
function cpms_contract_change_row_key($row) {
    $tradeGroup = isset($row['trade_group']) ? cpms_contract_change_normalize_value($row['trade_group']) : '';
    $subTrade = isset($row['sub_trade']) ? cpms_contract_change_normalize_value($row['sub_trade']) : '';
    $locationName = isset($row['location_name']) ? cpms_contract_change_normalize_value($row['location_name']) : '';
    $item = isset($row['item_name']) ? cpms_contract_change_normalize_value($row['item_name']) : '';
    $spec = isset($row['spec']) ? cpms_contract_change_normalize_value($row['spec']) : '';
    $unit = isset($row['unit']) ? cpms_contract_change_normalize_value($row['unit']) : '';
    return $tradeGroup . '|' . $subTrade . '|' . $locationName . '|' . $item . '|' . $spec . '|' . $unit;
}
}

if (!function_exists('cpms_contract_change_row_key_empty')) {
function cpms_contract_change_row_key_empty($key) {
    return trim(str_replace('|', '', (string)$key)) === '';
}
}

if (!function_exists('cpms_contract_change_fmt')) {
function cpms_contract_change_fmt($value) {
    if ($value === null || $value === '') return '';
    if (!is_numeric((string)$value)) return (string)$value;
    return number_format((float)$value, 0);
}
}

if (!function_exists('cpms_contract_change_badge')) {
function cpms_contract_change_badge($type, $label, $oldValue, $newValue) {
    return array(
        'type' => (string)$type,
        'label' => (string)$label,
        'old' => $oldValue,
        'new' => $newValue
    );
}
}

if (!function_exists('cpms_contract_change_compare_rows')) {
function cpms_contract_change_compare_rows($oldRows, $newRows) {
    if (!is_array($oldRows)) $oldRows = array();
    if (!is_array($newRows)) $newRows = array();

    $oldMap = array();
    $activeOldRows = array();
    foreach ($oldRows as $oldIndex => $oldRow) {
        if (!is_array($oldRow)) continue;
        if (isset($oldRow['is_active']) && (int)$oldRow['is_active'] === 0) continue;
        $activeIndex = count($activeOldRows);
        $activeOldRows[$activeIndex] = $oldRow;
        $key = cpms_contract_change_row_key($oldRow);
        if (cpms_contract_change_row_key_empty($key)) continue;
        if (!isset($oldMap[$key])) $oldMap[$key] = array();
        array_push($oldMap[$key], $activeIndex);
    }

    $usedOld = array();
    $changes = array();
    $summary = array(
        'kept' => 0,
        'changed' => 0,
        'inserted' => 0,
        'excluded' => 0,
        'unit_price_changed' => 0,
        'quantity_increased' => 0,
        'quantity_decreased' => 0
    );

    foreach ($newRows as $newRow) {
        if (!is_array($newRow)) continue;
        $key = cpms_contract_change_row_key($newRow);
        if (cpms_contract_change_row_key_empty($key)) continue;

        $matchIndex = -1;
        if (isset($oldMap[$key])) {
            foreach ($oldMap[$key] as $candidate) {
                if (!isset($usedOld[$candidate])) {
                    $matchIndex = (int)$candidate;
                    break;
                }
            }
        }

        if ($matchIndex < 0 || !isset($activeOldRows[$matchIndex])) {
            $summary['inserted']++;
            array_push($changes, array(
                'status' => '신규',
                'old_id' => 0,
                'old_row' => null,
                'row' => $newRow,
                'badges' => array(cpms_contract_change_badge('ADDED', '추가항목', null, null))
            ));
            continue;
        }

        $usedOld[$matchIndex] = 1;
        $oldRow = $activeOldRows[$matchIndex];
        $badges = array();

        $oldUnitPrice = cpms_contract_change_unit_price_value($oldRow);
        $newUnitPrice = cpms_contract_change_unit_price_value($newRow);
        if (!cpms_contract_change_number_same($oldUnitPrice, $newUnitPrice)) {
            $summary['unit_price_changed']++;
            array_push($badges, cpms_contract_change_badge('UNIT_PRICE_CHANGED', '단가 변경', $oldUnitPrice, $newUnitPrice));
        }

        $oldQty = isset($oldRow['qty']) ? $oldRow['qty'] : null;
        $newQty = isset($newRow['qty']) ? $newRow['qty'] : null;
        if (!cpms_contract_change_number_same($oldQty, $newQty)) {
            $oldQtyNum = cpms_contract_change_number($oldQty);
            $newQtyNum = cpms_contract_change_number($newQty);
            if ($newQtyNum > $oldQtyNum) {
                $summary['quantity_increased']++;
                array_push($badges, cpms_contract_change_badge('QUANTITY_INCREASED', '수량 증가', $oldQty, $newQty));
            } else {
                $summary['quantity_decreased']++;
                array_push($badges, cpms_contract_change_badge('QUANTITY_DECREASED', '수량 감소', $oldQty, $newQty));
            }
        }

        if (count($badges) > 0) $summary['changed']++;
        else $summary['kept']++;

        array_push($changes, array(
            'status' => (count($badges) > 0 ? '변경' : '유지'),
            'old_id' => isset($oldRow['id']) ? (int)$oldRow['id'] : 0,
            'old_row' => $oldRow,
            'row' => $newRow,
            'badges' => $badges
        ));
    }

    $excluded = array();
    foreach ($activeOldRows as $oldIndex => $oldRow) {
        if (isset($usedOld[$oldIndex])) continue;
        $summary['excluded']++;
        $oldRow['badges'] = array(cpms_contract_change_badge('DELETED_SUSPECTED', '삭제 의심', null, null));
        array_push($excluded, $oldRow);
    }

    return array('changes' => $changes, 'excluded' => $excluded, 'summary' => $summary);
}
}

if (!function_exists('cpms_contract_change_render_badges')) {
function cpms_contract_change_render_badges($badges) {
    if (!is_array($badges) || count($badges) === 0) return '';
    $html = '';
    foreach ($badges as $badge) {
        if (!is_array($badge)) continue;
        $label = isset($badge['label']) ? (string)$badge['label'] : '';
        $old = isset($badge['old']) ? $badge['old'] : null;
        $new = isset($badge['new']) ? $badge['new'] : null;
        $text = '[' . $label . ']';
        if (($old !== null && $old !== '') || ($new !== null && $new !== '')) {
            $text .= ' ' . cpms_contract_change_fmt($old) . ' → ' . cpms_contract_change_fmt($new);
        }
        $html .= '<span class="cpms-change-badge">' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</span> ';
    }
    return $html;
}
}

if (!function_exists('cpms_contract_change_badges_from_log')) {
function cpms_contract_change_badges_from_log($row) {
    if (!is_array($row)) return array();
    $type = isset($row['change_type']) ? (string)$row['change_type'] : '';
    if ($type === 'ADDED') return array(cpms_contract_change_badge('ADDED', '추가항목', null, null));
    if ($type === 'UNIT_PRICE_CHANGED') {
        return array(cpms_contract_change_badge('UNIT_PRICE_CHANGED', '단가 변경', isset($row['old_unit_price']) ? $row['old_unit_price'] : null, isset($row['new_unit_price']) ? $row['new_unit_price'] : null));
    }
    if ($type === 'QUANTITY_INCREASED') {
        return array(cpms_contract_change_badge('QUANTITY_INCREASED', '수량 증가', isset($row['old_quantity']) ? $row['old_quantity'] : null, isset($row['new_quantity']) ? $row['new_quantity'] : null));
    }
    if ($type === 'QUANTITY_DECREASED') {
        return array(cpms_contract_change_badge('QUANTITY_DECREASED', '수량 감소', isset($row['old_quantity']) ? $row['old_quantity'] : null, isset($row['new_quantity']) ? $row['new_quantity'] : null));
    }
    if ($type === 'SPEC_CHANGED') return array(cpms_contract_change_badge('SPEC_CHANGED', '규격 변경', null, null));
    if ($type === 'UNIT_CHANGED') return array(cpms_contract_change_badge('UNIT_CHANGED', '단위 변경', null, null));
    if ($type === 'DELETED_SUSPECTED') return array(cpms_contract_change_badge('DELETED_SUSPECTED', '삭제 의심', null, null));
    return array();
}
}
