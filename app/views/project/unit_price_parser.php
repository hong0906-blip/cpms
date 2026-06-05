<?php
if (!function_exists('cpms_project_unit_price_lang')) {
function cpms_project_unit_price_lang($key) {
    static $map = null;
    if ($map === null) {
        $map = array(
            'item_name' => json_decode('"\uD488\uBA85"'),
            'spec' => json_decode('"\uADDC\uACA9"'),
            'unit' => json_decode('"\uB2E8\uC704"'),
            'qty' => json_decode('"\uC218\uB7C9"'),
            'unit_price' => json_decode('"\uB2E8\uAC00"'),
            'amount' => json_decode('"\uAE08\uC561"'),
            'material' => json_decode('"\uC7AC\uB8CC\uBE44"'),
            'material_alt' => json_decode('"\uC790\uC7AC\uBE44"'),
            'material_price' => json_decode('"\uC790\uC7AC\uB2E8\uAC00"'),
            'labor' => json_decode('"\uB178\uBB34\uBE44"'),
            'labor_alt' => json_decode('"\uB178\uBB34"'),
            'expense' => json_decode('"\uACBD\uBE44"'),
            'expense_extra' => json_decode('"\uAE30\uD0C0\uACBD\uBE44"'),
            'expense_price' => json_decode('"\uACBD\uBE44\uB2E8\uAC00"'),
            'expense_extra_price' => json_decode('"\uAE30\uD0C0\uACBD\uBE44\uB2E8\uAC00"'),
            'expense_misc' => json_decode('"\uC7A1\uBE44"'),
            'expense_misc_material' => json_decode('"\uC7A1\uC790\uC7AC"'),
            'expense_misc_price' => json_decode('"\uC7A1\uBE44\uB2E8\uAC00"'),
            'sum' => json_decode('"\uACC4"'),
            'remark' => json_decode('"\uBE44\uACE0"'),
            'total_unit_price' => json_decode('"\uD569\uACC4\uB2E8\uAC00"'),
            'total_amount' => json_decode('"\uD569\uACC4\uAE08\uC561"'),
            'safety' => json_decode('"\uC548\uC804"'),
            'subtotal' => json_decode('"\uC18C\uACC4"'),
            'grand_total' => json_decode('"\uD569\uACC4"'),
            'overall_total' => json_decode('"\uCD1D\uACC4"'),
            'work_total' => json_decode('"\uACF5\uC0AC\uBE44\uD569\uACC4"'),
            'direct_total' => json_decode('"\uC9C1\uC811\uACF5\uC0AC\uBE44\uACC4"'),
            'indirect_total' => json_decode('"\uAC04\uC811\uACF5\uC0AC\uBE44\uACC4"'),
            'column_suffix' => json_decode('"\uC5F4"'),
            'validation_ok' => json_decode('"\uC815\uC0C1"'),
            'validation_mismatch' => json_decode('"\uACC4 \uBD88\uC77C\uCE58"'),
            'validation_missing' => json_decode('"\uC5D1\uC140 \uACC4 \uC5C6\uC74C"'),
            'msg_header_not_found' => json_decode('"\uB2E8\uAC00\uB0B4\uC5ED \uD5E4\uB354\uB97C \uCC3E\uC9C0 \uBABB\uD588\uC2B5\uB2C8\uB2E4. \uD488\uBA85/\uADDC\uACA9/\uB2E8\uC704/\uC218\uB7C9/\uB2E8\uAC00/\uC7AC\uB8CC\uBE44/\uB178\uBB34\uBE44/\uACBD\uBE44 \uC704\uCE58\uB97C \uD655\uC778\uD574\uC8FC\uC138\uC694."'),
            'msg_unit_group_not_found' => json_decode('"\uB2E8\uAC00 \uADF8\uB8F9 \uC544\uB798\uC758 \uC7AC\uB8CC\uBE44/\uB178\uBB34\uBE44/\uACBD\uBE44 \uCEEC\uB7FC\uC744 \uD655\uC815\uD558\uC9C0 \uBABB\uD588\uC2B5\uB2C8\uB2E4."'),
            'msg_rows_not_found' => json_decode('"\uC815\uC0C1 \uB2E8\uAC00\uB0B4\uC5ED \uD589\uC744 \uCC3E\uC9C0 \uBABB\uD588\uC2B5\uB2C8\uB2E4. \uD488\uBA85/\uB2E8\uC704/\uC218\uB7C9/\uB2E8\uAC00 \uC138\uBD80\uAC12\uC744 \uD655\uC778\uD574\uC8FC\uC138\uC694."'),
            'msg_db_fail' => json_decode('"\u0044\u0042 \uC5F0\uACB0 \uC2E4\uD328"'),
            'msg_file_missing' => json_decode('"\uC5C5\uB85C\uB4DC \uD30C\uC77C\uC774 \uC5C6\uC2B5\uB2C8\uB2E4."'),
            'msg_zip_missing' => json_decode('"\uC11C\uBC84\uC5D0 \u005A\u0069\u0070\u0041\u0072\u0063\u0068\u0069\u0076\u0065 \uD655\uC7A5 \uBAA8\uB4C8\uC774 \uC5C6\uC2B5\uB2C8\uB2E4."'),
            'msg_open_fail' => json_decode('"\uC5D1\uC140 \uD30C\uC77C\uC744 \uC5F4 \uC218 \uC5C6\uC2B5\uB2C8\uB2E4."'),
            'msg_sheet_missing' => json_decode('"\uC5D1\uC140 \uC2DC\uD2B8\uB97C \uCC3E\uC744 \uC218 \uC5C6\uC2B5\uB2C8\uB2E4."'),
            'msg_sheet_data_missing' => json_decode('"\uC2DC\uD2B8 \uB370\uC774\uD130\uB97C \uCC3E\uC744 \uC218 \uC5C6\uC2B5\uB2C8\uB2E4."'),
            'msg_sheet_xml_fail' => json_decode('"\uC2DC\uD2B8 \u0058\u004D\u004C\uC744 \uC77D\uC744 \uC218 \uC5C6\uC2B5\uB2C8\uB2E4."')
        );
    }
    return isset($map[$key]) ? $map[$key] : '';
}}

if (!function_exists('cpms_project_unit_price_label_normalize')) {
function cpms_project_unit_price_label_normalize($value) {
    $value = trim((string)$value);
    $value = str_replace(array("\r", "\n", "\t", ' '), '', $value);
    $value = mb_strtolower($value, 'UTF-8');
    return $value;
}}

if (!function_exists('cpms_project_unit_price_text_normalize')) {
function cpms_project_unit_price_text_normalize($value) {
    return trim((string)$value);
}}

if (!function_exists('cpms_project_unit_price_number_parse')) {
function cpms_project_unit_price_number_parse($value) {
    $value = str_replace(array(',', ' ', json_decode('"\uC6D0"')), '', trim((string)$value));
    $value = preg_replace('/[^0-9\.\-]/', '', $value);
    if ($value === '' || $value === '-' || $value === '.' || $value === '-.') return null;
    if (!is_numeric($value)) return null;
    return (float)$value;
}}

if (!function_exists('cpms_project_unit_price_number_zero')) {
function cpms_project_unit_price_number_zero($value) {
    $parsed = cpms_project_unit_price_number_parse($value);
    return ($parsed === null) ? 0.0 : $parsed;
}}

if (!function_exists('cpms_project_unit_price_col_to_letter')) {
function cpms_project_unit_price_col_to_letter($index) {
    $index = (int)$index;
    if ($index <= 0) return '';
    $letters = '';
    while ($index > 0) {
        $mod = ($index - 1) % 26;
        $letters = chr(65 + $mod) . $letters;
        $index = (int)(($index - 1) / 26);
    }
    return $letters;
}}

if (!function_exists('cpms_project_unit_price_ref_to_pos')) {
function cpms_project_unit_price_ref_to_pos($ref) {
    $ref = strtoupper((string)$ref);
    if (!preg_match('/^([A-Z]+)([0-9]+)$/', $ref, $m)) return array(0, 0);
    $letters = $m[1];
    $row = (int)$m[2];
    $col = 0;
    $len = strlen($letters);
    $i = 0;
    while ($i < $len) {
        $col = ($col * 26) + (ord($letters[$i]) - 64);
        $i++;
    }
    return array($row, $col);
}}

if (!function_exists('cpms_project_unit_price_sheet_list')) {
function cpms_project_unit_price_sheet_list($zip) {
    $sheets = array();
    $wb = $zip->getFromName('xl/workbook.xml');
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($wb === false || $rels === false) {
        if ($zip->getFromName('xl/worksheets/sheet1.xml') !== false) {
            array_push($sheets, array('name' => 'sheet1', 'path' => 'xl/worksheets/sheet1.xml'));
        }
        return $sheets;
    }

    $wbx = @simplexml_load_string($wb);
    $relx = @simplexml_load_string($rels);
    if (!$wbx || !$relx || !isset($wbx->sheets) || !isset($wbx->sheets->sheet)) {
        if ($zip->getFromName('xl/worksheets/sheet1.xml') !== false) {
            array_push($sheets, array('name' => 'sheet1', 'path' => 'xl/worksheets/sheet1.xml'));
        }
        return $sheets;
    }

    $targets = array();
    foreach ($relx->Relationship as $rel) {
        $id = (string)$rel['Id'];
        $target = (string)$rel['Target'];
        if ($id !== '') {
            $target = ltrim($target, '/');
            if (strpos($target, 'xl/') === 0) {
                $targets[$id] = $target;
            } else {
                $targets[$id] = 'xl/' . $target;
            }
        }
    }

    foreach ($wbx->sheets->sheet as $sheet) {
        $name = (string)$sheet['name'];
        $rAttrs = $sheet->attributes('r', true);
        $rid = (isset($rAttrs['id'])) ? (string)$rAttrs['id'] : '';
        if ($rid === '') $rid = (string)$sheet['id'];
        if ($rid !== '' && isset($targets[$rid])) {
            array_push($sheets, array('name' => $name, 'path' => $targets[$rid]));
        }
    }

    if (count($sheets) === 0 && $zip->getFromName('xl/worksheets/sheet1.xml') !== false) {
        array_push($sheets, array('name' => 'sheet1', 'path' => 'xl/worksheets/sheet1.xml'));
    }
    return $sheets;
}}

if (!function_exists('cpms_project_unit_price_shared_strings')) {
function cpms_project_unit_price_shared_strings($zip) {
    $sharedStrings = array();
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml === false) return $sharedStrings;

    $sx = @simplexml_load_string($sharedXml);
    if (!$sx) return $sharedStrings;

    foreach ($sx->si as $si) {
        $text = '';
        if (isset($si->t)) {
            $text = (string)$si->t;
        } else if (isset($si->r)) {
            foreach ($si->r as $run) {
                if (isset($run->t)) $text .= (string)$run->t;
            }
        }
        array_push($sharedStrings, $text);
    }
    return $sharedStrings;
}}

if (!function_exists('cpms_project_unit_price_sheet_matrix')) {
function cpms_project_unit_price_sheet_matrix($zip, $sheetPath, $sharedStrings, $maxRows, $maxCols) {
    $result = array('ok'=>false, 'matrix'=>array(), 'max_row'=>0, 'max_col'=>0, 'message'=>'');
    $sheetXml = $zip->getFromName($sheetPath);
    if ($sheetXml === false) {
        $result['message'] = cpms_project_unit_price_lang('msg_sheet_data_missing');
        return $result;
    }

    $sx = @simplexml_load_string($sheetXml);
    if (!$sx || !isset($sx->sheetData)) {
        $result['message'] = cpms_project_unit_price_lang('msg_sheet_xml_fail');
        return $result;
    }

    $matrix = array();
    $maxRowFound = 0;
    $maxColFound = 0;
    $autoRow = 0;

    foreach ($sx->sheetData->row as $rowNode) {
        $autoRow++;
        $rowNum = isset($rowNode['r']) ? (int)$rowNode['r'] : $autoRow;
        if ($rowNum <= 0) $rowNum = $autoRow;
        if ($rowNum > (int)$maxRows) break;

        if (!isset($matrix[$rowNum])) $matrix[$rowNum] = array();
        if ($rowNum > $maxRowFound) $maxRowFound = $rowNum;

        foreach ($rowNode->c as $cellNode) {
            $ref = isset($cellNode['r']) ? (string)$cellNode['r'] : '';
            list($cellRow, $cellCol) = cpms_project_unit_price_ref_to_pos($ref);
            if ($cellRow <= 0) $cellRow = $rowNum;
            if ($cellCol <= 0 || $cellCol > (int)$maxCols) continue;

            $type = isset($cellNode['t']) ? (string)$cellNode['t'] : '';
            $value = '';
            if ($type === 's') {
                $idx = isset($cellNode->v) ? (int)$cellNode->v : -1;
                $value = ($idx >= 0 && isset($sharedStrings[$idx])) ? $sharedStrings[$idx] : '';
            } else if ($type === 'inlineStr') {
                if (isset($cellNode->is->t)) {
                    $value = (string)$cellNode->is->t;
                } else if (isset($cellNode->is->r)) {
                    foreach ($cellNode->is->r as $run) {
                        if (isset($run->t)) $value .= (string)$run->t;
                    }
                }
            } else {
                $value = isset($cellNode->v) ? (string)$cellNode->v : '';
            }

            $matrix[$cellRow][$cellCol] = $value;
            if ($cellRow > $maxRowFound) $maxRowFound = $cellRow;
            if ($cellCol > $maxColFound) $maxColFound = $cellCol;
        }
    }

    if (isset($sx->mergeCells) && isset($sx->mergeCells->mergeCell)) {
        foreach ($sx->mergeCells->mergeCell as $mergeNode) {
            $mergeRef = isset($mergeNode['ref']) ? (string)$mergeNode['ref'] : '';
            if (!preg_match('/^([A-Z]+[0-9]+):([A-Z]+[0-9]+)$/', $mergeRef, $m)) continue;
            list($startRow, $startCol) = cpms_project_unit_price_ref_to_pos($m[1]);
            list($endRow, $endCol) = cpms_project_unit_price_ref_to_pos($m[2]);
            if ($startRow <= 0 || $startCol <= 0) continue;
            if ($startRow > (int)$maxRows || $startCol > (int)$maxCols) continue;

            $mergeValue = '';
            if (isset($matrix[$startRow]) && isset($matrix[$startRow][$startCol])) {
                $mergeValue = $matrix[$startRow][$startCol];
            }
            if ($mergeValue === '') continue;

            $r = $startRow;
            while ($r <= $endRow && $r <= (int)$maxRows) {
                if (!isset($matrix[$r])) $matrix[$r] = array();
                $c = $startCol;
                while ($c <= $endCol && $c <= (int)$maxCols) {
                    if (!isset($matrix[$r][$c]) || $matrix[$r][$c] === '') $matrix[$r][$c] = $mergeValue;
                    if ($c > $maxColFound) $maxColFound = $c;
                    $c++;
                }
                if ($r > $maxRowFound) $maxRowFound = $r;
                $r++;
            }
        }
    }

    $result['ok'] = true;
    $result['matrix'] = $matrix;
    $result['max_row'] = $maxRowFound;
    $result['max_col'] = $maxColFound;
    return $result;
}}

if (!function_exists('cpms_project_unit_price_header_match')) {
function cpms_project_unit_price_header_match($normalizedValue, $keyword) {
    if ($normalizedValue === '') return false;
    return (mb_strpos($normalizedValue, cpms_project_unit_price_label_normalize($keyword), 0, 'UTF-8') !== false);
}}

if (!function_exists('cpms_project_unit_price_header_match_any')) {
function cpms_project_unit_price_header_match_any($normalizedValue, $keywords, $exactOnly) {
    if (!is_array($keywords)) $keywords = array($keywords);
    foreach ($keywords as $keyword) {
        if ($exactOnly) {
            if ($normalizedValue === cpms_project_unit_price_label_normalize($keyword)) return true;
        } else {
            if (cpms_project_unit_price_header_match($normalizedValue, $keyword)) return true;
        }
    }
    return false;
}}

if (!function_exists('cpms_project_unit_price_header_exact')) {
function cpms_project_unit_price_header_exact($normalizedValue, $keyword) {
    return ($normalizedValue === cpms_project_unit_price_label_normalize($keyword));
}}

if (!function_exists('cpms_project_unit_price_item_name_keys')) {
function cpms_project_unit_price_item_name_keys() {
    return array(cpms_project_unit_price_lang('item_name'), '공종명', '공 종 명', '공종', '품목명', '품목', '명칭');
}}

if (!function_exists('cpms_project_unit_price_spec_keys')) {
function cpms_project_unit_price_spec_keys() {
    return array(cpms_project_unit_price_lang('spec'), '규 격', '규격명', '사양');
}}

if (!function_exists('cpms_project_unit_price_unit_keys')) {
function cpms_project_unit_price_unit_keys() {
    return array(cpms_project_unit_price_lang('unit'), '단 위');
}}

if (!function_exists('cpms_project_unit_price_qty_keys')) {
function cpms_project_unit_price_qty_keys() {
    return array(cpms_project_unit_price_lang('qty'), '수 량', '물량');
}}

if (!function_exists('cpms_project_unit_price_remark_keys')) {
function cpms_project_unit_price_remark_keys() {
    return array(cpms_project_unit_price_lang('remark'), '비 고', '적요');
}}

if (!function_exists('cpms_project_unit_price_find_positions')) {
function cpms_project_unit_price_find_positions($matrix, $maxRow, $maxCol, $headerLimit) {
    $positions = array(
        'item_name' => array(),
        'spec' => array(),
        'unit' => array(),
        'qty' => array(),
        'remark' => array()
    );
    $lastRow = ($maxRow < $headerLimit) ? $maxRow : $headerLimit;
    $r = 1;
    while ($r <= $lastRow) {
        $c = 1;
        while ($c <= $maxCol) {
            $value = isset($matrix[$r][$c]) ? $matrix[$r][$c] : '';
            $norm = cpms_project_unit_price_label_normalize($value);
            if ($norm !== '') {
                if (cpms_project_unit_price_header_match_any($norm, cpms_project_unit_price_item_name_keys(), false)) array_push($positions['item_name'], array('row'=>$r,'col'=>$c));
                if (cpms_project_unit_price_header_match_any($norm, cpms_project_unit_price_spec_keys(), false)) array_push($positions['spec'], array('row'=>$r,'col'=>$c));
                if (cpms_project_unit_price_header_match_any($norm, cpms_project_unit_price_unit_keys(), false)) array_push($positions['unit'], array('row'=>$r,'col'=>$c));
                if (cpms_project_unit_price_header_match_any($norm, cpms_project_unit_price_qty_keys(), false)) array_push($positions['qty'], array('row'=>$r,'col'=>$c));
                if (cpms_project_unit_price_header_match_any($norm, cpms_project_unit_price_remark_keys(), false)) array_push($positions['remark'], array('row'=>$r,'col'=>$c));
            }
            $c++;
        }
        $r++;
    }
    return $positions;
}}

if (!function_exists('cpms_project_unit_price_pick_nearest_header')) {
function cpms_project_unit_price_pick_nearest_header($positions, $maxHeaderRow) {
    if (!is_array($positions) || count($positions) === 0) return null;
    $candidates = array();
    foreach ($positions as $pos) {
        if (!isset($pos['row']) || !isset($pos['col'])) continue;
        if ((int)$pos['row'] <= (int)$maxHeaderRow) array_push($candidates, $pos);
    }
    if (count($candidates) === 0) $candidates = $positions;
    usort($candidates, function($a, $b) {
        if ((int)$a['row'] === (int)$b['row']) return ((int)$a['col'] - (int)$b['col']);
        return ((int)$b['row'] - (int)$a['row']);
    });
    return $candidates[0];
}}

if (!function_exists('cpms_project_unit_price_find_detail_col')) {
function cpms_project_unit_price_find_detail_col($matrix, $row, $startCol, $endCol, $keyword, $exactOnly) {
    $c = (int)$startCol;
    while ($c <= (int)$endCol) {
        $value = isset($matrix[$row][$c]) ? $matrix[$row][$c] : '';
        $norm = cpms_project_unit_price_label_normalize($value);
        if ($norm !== '') {
            if (cpms_project_unit_price_header_match_any($norm, $keyword, $exactOnly)) return $c;
        }
        $c++;
    }
    return 0;
}}

if (!function_exists('cpms_project_unit_price_first_group_col')) {
function cpms_project_unit_price_first_group_col($matrix, $row, $fromCol, $maxCol, $keyword) {
    $c = (int)$fromCol;
    while ($c <= (int)$maxCol) {
        $value = isset($matrix[$row][$c]) ? $matrix[$row][$c] : '';
        $norm = cpms_project_unit_price_label_normalize($value);
        if ($norm !== '' && cpms_project_unit_price_header_match($norm, $keyword)) return $c;
        $c++;
    }
    return 0;
}}

if (!function_exists('cpms_project_unit_price_segment_end')) {
function cpms_project_unit_price_segment_end($matrix, $row, $startCol, $maxCol, $keyword) {
    $end = (int)$startCol;
    $c = (int)$startCol + 1;
    while ($c <= (int)$maxCol) {
        $value = isset($matrix[$row][$c]) ? $matrix[$row][$c] : '';
        $norm = cpms_project_unit_price_label_normalize($value);
        if ($norm !== '' && cpms_project_unit_price_header_match($norm, $keyword)) {
            $end = $c;
            $c++;
            continue;
        }
        break;
    }
    return $end;
}}

if (!function_exists('cpms_project_unit_price_material_keys')) {
function cpms_project_unit_price_material_keys() {
    return array(cpms_project_unit_price_lang('material'), cpms_project_unit_price_lang('material_alt'), cpms_project_unit_price_lang('material_price'), '재료', '자재');
}}

if (!function_exists('cpms_project_unit_price_labor_keys')) {
function cpms_project_unit_price_labor_keys() {
    return array(cpms_project_unit_price_lang('labor'), cpms_project_unit_price_lang('labor_alt'), '인건비');
}}

if (!function_exists('cpms_project_unit_price_expense_keys')) {
function cpms_project_unit_price_expense_keys() {
    return array(
        cpms_project_unit_price_lang('expense'),
        cpms_project_unit_price_lang('expense_extra'),
        cpms_project_unit_price_lang('expense_price'),
        cpms_project_unit_price_lang('expense_extra_price'),
        cpms_project_unit_price_lang('expense_misc'),
        cpms_project_unit_price_lang('expense_misc_material'),
        cpms_project_unit_price_lang('expense_misc_price')
    );
}}

if (!function_exists('cpms_project_unit_price_total_keys')) {
function cpms_project_unit_price_total_keys() {
    return array(
        cpms_project_unit_price_lang('sum'),
        cpms_project_unit_price_lang('total_unit_price'),
        cpms_project_unit_price_lang('unit_price') . cpms_project_unit_price_lang('sum'),
        '견적금액',
        '합계금액',
        '총금액'
    );
}}

if (!function_exists('cpms_project_unit_price_total_group_keys')) {
function cpms_project_unit_price_total_group_keys() {
    return array('견적금액', '합계금액', '합계', '총액', '총금액');
}}

if (!function_exists('cpms_project_unit_price_segment_end_any')) {
function cpms_project_unit_price_segment_end_any($matrix, $row, $startCol, $maxCol, $keywords) {
    $end = (int)$startCol;
    $c = (int)$startCol + 1;
    while ($c <= (int)$maxCol) {
        $value = isset($matrix[$row][$c]) ? $matrix[$row][$c] : '';
        $norm = cpms_project_unit_price_label_normalize($value);
        if ($norm !== '' && cpms_project_unit_price_header_match_any($norm, $keywords, false)) {
            $end = $c;
            $c++;
            continue;
        }
        break;
    }
    return $end;
}}

if (!function_exists('cpms_project_unit_price_count_header_in_row')) {
function cpms_project_unit_price_count_header_in_row($matrix, $row, $maxCol, $keywords, $exactOnly) {
    $count = 0;
    $c = 1;
    while ($c <= (int)$maxCol) {
        $value = isset($matrix[$row][$c]) ? $matrix[$row][$c] : '';
        $norm = cpms_project_unit_price_label_normalize($value);
        if ($norm !== '' && cpms_project_unit_price_header_match_any($norm, $keywords, $exactOnly)) $count++;
        $c++;
    }
    return $count;
}}

if (!function_exists('cpms_project_unit_price_find_flat_group')) {
function cpms_project_unit_price_find_flat_group($matrix, $maxRow, $maxCol, $headerLimit) {
    $lastRow = ($maxRow < $headerLimit) ? $maxRow : $headerLimit;
    $r = 1;
    while ($r <= $lastRow) {
        $materialCol = cpms_project_unit_price_find_detail_col($matrix, $r, 1, $maxCol, cpms_project_unit_price_material_keys(), false);
        $laborCol = cpms_project_unit_price_find_detail_col($matrix, $r, 1, $maxCol, cpms_project_unit_price_labor_keys(), false);
        $expenseCol = cpms_project_unit_price_find_detail_col($matrix, $r, 1, $maxCol, cpms_project_unit_price_expense_keys(), false);
        if ($materialCol > 0 && $laborCol > 0 && $expenseCol > 0 && $materialCol !== $laborCol && $materialCol !== $expenseCol && $laborCol !== $expenseCol) {
            $minCol = min($materialCol, $laborCol, $expenseCol);
            $maxDetailCol = max($materialCol, $laborCol, $expenseCol);
            $excelTotalCol = cpms_project_unit_price_find_detail_col($matrix, $r, $minCol, $maxCol, cpms_project_unit_price_total_keys(), false);
            if ($excelTotalCol > 0 && $excelTotalCol > $maxDetailCol) $maxDetailCol = $excelTotalCol;
            return array(
                'group_header_row' => $r,
                'detail_header_row' => $r,
                'unit_price_group_start_col' => $minCol,
                'unit_price_group_end_col' => $maxDetailCol,
                'amount_group_start_col' => 0,
                'material_unit_price_col' => $materialCol,
                'labor_unit_price_col' => $laborCol,
                'expense_unit_price_col' => $expenseCol,
                'excel_unit_price_total_col' => $excelTotalCol,
                'amount_col' => 0
            );
        }
        $r++;
    }
    return null;
}}

if (!function_exists('cpms_project_unit_price_find_cost_group')) {
function cpms_project_unit_price_find_cost_group($matrix, $maxRow, $maxCol, $headerLimit) {
    $lastRow = ($maxRow < $headerLimit) ? $maxRow : $headerLimit;
    $r = 1;
    while ($r <= $lastRow) {
        $materialStart = cpms_project_unit_price_find_detail_col($matrix, $r, 1, $maxCol, cpms_project_unit_price_material_keys(), false);
        $laborStart = cpms_project_unit_price_find_detail_col($matrix, $r, 1, $maxCol, cpms_project_unit_price_labor_keys(), false);
        $expenseStart = cpms_project_unit_price_find_detail_col($matrix, $r, 1, $maxCol, cpms_project_unit_price_expense_keys(), false);

        $groupCount = 0;
        if ($materialStart > 0) $groupCount++;
        if ($laborStart > 0) $groupCount++;
        if ($expenseStart > 0) $groupCount++;
        if ($groupCount < 2) {
            $r++;
            continue;
        }

        $materialEnd = ($materialStart > 0) ? cpms_project_unit_price_segment_end_any($matrix, $r, $materialStart, $maxCol, cpms_project_unit_price_material_keys()) : 0;
        $laborEnd = ($laborStart > 0) ? cpms_project_unit_price_segment_end_any($matrix, $r, $laborStart, $maxCol, cpms_project_unit_price_labor_keys()) : 0;
        $expenseEnd = ($expenseStart > 0) ? cpms_project_unit_price_segment_end_any($matrix, $r, $expenseStart, $maxCol, cpms_project_unit_price_expense_keys()) : 0;

        $costStartCols = array();
        $costEndCols = array();
        if ($materialStart > 0) { $costStartCols[] = $materialStart; $costEndCols[] = $materialEnd; }
        if ($laborStart > 0) { $costStartCols[] = $laborStart; $costEndCols[] = $laborEnd; }
        if ($expenseStart > 0) { $costStartCols[] = $expenseStart; $costEndCols[] = $expenseEnd; }
        $minCostCol = min($costStartCols);
        $maxCostCol = max($costEndCols);

        $detailMax = $r + 8;
        if ($detailMax > $lastRow) $detailMax = $lastRow;
        $dr = $r + 1;
        while ($dr <= $detailMax) {
            $unitLabelCount = cpms_project_unit_price_count_header_in_row($matrix, $dr, $maxCol, cpms_project_unit_price_lang('unit_price'), true);
            if ($unitLabelCount <= 0) {
                $dr++;
                continue;
            }

            $materialCol = 0;
            $laborCol = 0;
            $expenseCol = 0;
            if ($materialStart > 0) {
                $materialCol = cpms_project_unit_price_find_detail_col($matrix, $dr, $materialStart, $materialEnd, cpms_project_unit_price_lang('unit_price'), true);
                if ($materialCol <= 0) $materialCol = $materialStart;
            }
            if ($laborStart > 0) {
                $laborCol = cpms_project_unit_price_find_detail_col($matrix, $dr, $laborStart, $laborEnd, cpms_project_unit_price_lang('unit_price'), true);
                if ($laborCol <= 0) $laborCol = $laborStart;
            }
            if ($expenseStart > 0) {
                $expenseCol = cpms_project_unit_price_find_detail_col($matrix, $dr, $expenseStart, $expenseEnd, cpms_project_unit_price_lang('unit_price'), true);
                if ($expenseCol <= 0) $expenseCol = $expenseStart;
            }

            $priceColCount = 0;
            if ($materialCol > 0) $priceColCount++;
            if ($laborCol > 0) $priceColCount++;
            if ($expenseCol > 0) $priceColCount++;
            if ($priceColCount < 2) {
                $dr++;
                continue;
            }

            $totalStart = cpms_project_unit_price_find_detail_col($matrix, $r, $maxCostCol + 1, $maxCol, cpms_project_unit_price_total_group_keys(), false);
            $totalEnd = 0;
            $excelTotalCol = 0;
            $amountCol = 0;
            if ($totalStart > 0) {
                $totalEnd = cpms_project_unit_price_segment_end_any($matrix, $r, $totalStart, $maxCol, cpms_project_unit_price_total_group_keys());
                $excelTotalCol = cpms_project_unit_price_find_detail_col($matrix, $dr, $totalStart, $totalEnd, cpms_project_unit_price_lang('unit_price'), true);
                $amountCol = cpms_project_unit_price_find_detail_col($matrix, $dr, $totalStart, $totalEnd, cpms_project_unit_price_lang('amount'), true);
                if ($excelTotalCol <= 0) $excelTotalCol = $totalStart;
                if ($amountCol <= 0 && $totalEnd > $totalStart) $amountCol = $totalStart + 1;
            }

            if ($excelTotalCol <= 0) {
                $excelTotalCol = cpms_project_unit_price_find_detail_col($matrix, $dr, $maxCostCol + 1, $maxCol, cpms_project_unit_price_total_keys(), false);
            }

            return array(
                'group_header_row' => $r,
                'detail_header_row' => $dr,
                'unit_price_group_start_col' => $minCostCol,
                'unit_price_group_end_col' => $maxCostCol,
                'amount_group_start_col' => $totalStart,
                'material_unit_price_col' => $materialCol,
                'labor_unit_price_col' => $laborCol,
                'expense_unit_price_col' => $expenseCol,
                'excel_unit_price_total_col' => $excelTotalCol,
                'amount_col' => $amountCol
            );
        }

        $r++;
    }
    return null;
}}

if (!function_exists('cpms_project_unit_price_find_simple_price_group')) {
function cpms_project_unit_price_find_simple_price_group($matrix, $maxRow, $maxCol, $headerLimit) {
    $lastRow = ($maxRow < $headerLimit) ? $maxRow : $headerLimit;
    $r = 1;
    while ($r <= $lastRow) {
        $itemCol = cpms_project_unit_price_find_detail_col($matrix, $r, 1, $maxCol, cpms_project_unit_price_item_name_keys(), false);
        $specCol = cpms_project_unit_price_find_detail_col($matrix, $r, 1, $maxCol, cpms_project_unit_price_spec_keys(), false);
        $unitCol = cpms_project_unit_price_find_detail_col($matrix, $r, 1, $maxCol, cpms_project_unit_price_unit_keys(), false);
        $qtyCol = cpms_project_unit_price_find_detail_col($matrix, $r, 1, $maxCol, cpms_project_unit_price_qty_keys(), false);
        if ($itemCol <= 0 || $specCol <= 0 || $unitCol <= 0 || $qtyCol <= 0) {
            $r++;
            continue;
        }

        $unitLabelCount = cpms_project_unit_price_count_header_in_row($matrix, $r, $maxCol, cpms_project_unit_price_lang('unit_price'), true);
        if ($unitLabelCount !== 1) {
            $r++;
            continue;
        }

        $unitPriceCol = cpms_project_unit_price_find_detail_col($matrix, $r, 1, $maxCol, cpms_project_unit_price_lang('unit_price'), true);
        $amountCol = cpms_project_unit_price_find_detail_col($matrix, $r, $unitPriceCol + 1, $maxCol, cpms_project_unit_price_lang('amount'), true);
        if ($unitPriceCol <= 0) {
            $r++;
            continue;
        }

        return array(
            'group_header_row' => $r,
            'detail_header_row' => $r,
            'unit_price_group_start_col' => $unitPriceCol,
            'unit_price_group_end_col' => ($amountCol > 0 ? $amountCol : $unitPriceCol),
            'amount_group_start_col' => ($amountCol > 0 ? $amountCol : 0),
            'material_unit_price_col' => 0,
            'labor_unit_price_col' => 0,
            'expense_unit_price_col' => 0,
            'excel_unit_price_total_col' => $unitPriceCol,
            'amount_col' => $amountCol
        );
    }
    return null;
}}

if (!function_exists('cpms_project_unit_price_find_unit_group')) {
function cpms_project_unit_price_find_unit_group($matrix, $maxRow, $maxCol, $headerLimit) {
    $best = null;
    $lastRow = ($maxRow < $headerLimit) ? $maxRow : $headerLimit;
    $r = 1;
    while ($r <= $lastRow) {
        $c = 1;
        while ($c <= $maxCol) {
            $value = isset($matrix[$r][$c]) ? $matrix[$r][$c] : '';
            $norm = cpms_project_unit_price_label_normalize($value);
            if ($norm === '' || !cpms_project_unit_price_header_match($norm, cpms_project_unit_price_lang('unit_price'))) {
                $c++;
                continue;
            }

            $startCol = $c;
            $segmentEnd = cpms_project_unit_price_segment_end($matrix, $r, $startCol, $maxCol, cpms_project_unit_price_lang('unit_price'));
            $amountStart = cpms_project_unit_price_first_group_col($matrix, $r, $startCol + 1, $maxCol, cpms_project_unit_price_lang('amount'));
            $endCol = ($amountStart > 0) ? ($amountStart - 1) : $segmentEnd;
            if ($endCol < $startCol) $endCol = $startCol;

            $searchEndCol = $endCol;
            if ($amountStart <= 0 && $segmentEnd === $startCol) $searchEndCol = $maxCol;
            $detailMax = $r + 10;
            if ($detailMax > $lastRow) $detailMax = $lastRow;

            $dr = $r + 1;
            while ($dr <= $detailMax) {
                $materialCol = cpms_project_unit_price_find_detail_col($matrix, $dr, $startCol, $searchEndCol, cpms_project_unit_price_material_keys(), false);
                $laborCol = cpms_project_unit_price_find_detail_col($matrix, $dr, $startCol, $searchEndCol, cpms_project_unit_price_labor_keys(), false);
                $expenseCol = cpms_project_unit_price_find_detail_col($matrix, $dr, $startCol, $searchEndCol, cpms_project_unit_price_expense_keys(), false);
                if ($materialCol > 0 && $laborCol > 0 && $expenseCol > 0 && $materialCol !== $laborCol && $materialCol !== $expenseCol && $laborCol !== $expenseCol) {
                    $excelTotalCol = cpms_project_unit_price_find_detail_col($matrix, $dr, $startCol, $searchEndCol, cpms_project_unit_price_total_keys(), false);
                    $amountTotalCol = 0;
                    if ($amountStart > 0) {
                        $amountEnd = $maxCol;
                        $amountTotalCol = cpms_project_unit_price_find_detail_col($matrix, $dr, $amountStart, $amountEnd, cpms_project_unit_price_lang('sum'), true);
                    }
                    $candidate = array(
                        'group_header_row' => $r,
                        'detail_header_row' => $dr,
                        'unit_price_group_start_col' => $startCol,
                        'unit_price_group_end_col' => $searchEndCol,
                        'amount_group_start_col' => $amountStart,
                        'material_unit_price_col' => $materialCol,
                        'labor_unit_price_col' => $laborCol,
                        'expense_unit_price_col' => $expenseCol,
                        'excel_unit_price_total_col' => $excelTotalCol,
                        'amount_col' => $amountTotalCol
                    );
                    if ($best === null) {
                        $best = $candidate;
                    } else {
                        $bestWidth = (int)$best['unit_price_group_end_col'] - (int)$best['unit_price_group_start_col'];
                        $candidateWidth = $searchEndCol - $startCol;
                        if ($r < (int)$best['group_header_row'] || ($r === (int)$best['group_header_row'] && $candidateWidth < $bestWidth)) {
                            $best = $candidate;
                        }
                    }
                    break;
                }
                $dr++;
            }

            $c = ($segmentEnd > $c) ? ($segmentEnd + 1) : ($c + 1);
        }
        $r++;
    }
    if ($best === null) $best = cpms_project_unit_price_find_cost_group($matrix, $maxRow, $maxCol, $headerLimit);
    if ($best === null) $best = cpms_project_unit_price_find_simple_price_group($matrix, $maxRow, $maxCol, $headerLimit);
    if ($best === null) $best = cpms_project_unit_price_find_flat_group($matrix, $maxRow, $maxCol, $headerLimit);
    return $best;
}}

if (!function_exists('cpms_project_unit_price_detect_columns')) {
function cpms_project_unit_price_detect_columns($matrix, $maxRow, $maxCol, $sheetName) {
    $result = array(
        'ok' => false,
        'message' => cpms_project_unit_price_lang('msg_header_not_found'),
        'sheet_name' => $sheetName,
        'header_end_row' => 0,
        'data_start_row' => 0,
        'columns' => array(),
        'detected_columns' => array(),
        'field_rows' => array(),
        'debug' => array()
    );

    $unitGroup = cpms_project_unit_price_find_unit_group($matrix, $maxRow, $maxCol, 80);
    if (!is_array($unitGroup)) {
        $result['message'] = cpms_project_unit_price_lang('msg_unit_group_not_found');
        return $result;
    }

    $positions = cpms_project_unit_price_find_positions($matrix, $maxRow, $maxCol, 80);
    $columns = array(
        'material_unit_price' => (int)$unitGroup['material_unit_price_col'],
        'labor_unit_price' => (int)$unitGroup['labor_unit_price_col'],
        'expense_unit_price' => (int)$unitGroup['expense_unit_price_col']
    );
    $fieldRows = array(
        'material_unit_price' => (int)$unitGroup['detail_header_row'],
        'labor_unit_price' => (int)$unitGroup['detail_header_row'],
        'expense_unit_price' => (int)$unitGroup['detail_header_row']
    );
    if ((int)$unitGroup['excel_unit_price_total_col'] > 0) {
        $columns['excel_unit_price_total'] = (int)$unitGroup['excel_unit_price_total_col'];
        $fieldRows['excel_unit_price_total'] = (int)$unitGroup['detail_header_row'];
    }
    if ((int)$unitGroup['amount_col'] > 0) {
        $columns['amount'] = (int)$unitGroup['amount_col'];
        $fieldRows['amount'] = (int)$unitGroup['detail_header_row'];
    }

    foreach (array('item_name', 'spec', 'unit', 'qty', 'remark') as $field) {
        $picked = cpms_project_unit_price_pick_nearest_header($positions[$field], (int)$unitGroup['detail_header_row']);
        if ($picked !== null) {
            $columns[$field] = (int)$picked['col'];
            $fieldRows[$field] = (int)$picked['row'];
        }
    }

    if (!isset($columns['item_name']) || !isset($columns['spec']) || !isset($columns['unit']) || !isset($columns['qty'])) {
        return $result;
    }

    $headerEndRow = 0;
    foreach ($fieldRows as $field => $row) {
        if ((int)$row > $headerEndRow) $headerEndRow = (int)$row;
    }
    if ($headerEndRow <= 0) return $result;

    $detectedColumns = array();
    foreach ($columns as $field => $col) {
        $detectedColumns[$field] = cpms_project_unit_price_col_to_letter((int)$col) . cpms_project_unit_price_lang('column_suffix');
    }
    $detectedColumns['unit_price_group_start_col'] = cpms_project_unit_price_col_to_letter((int)$unitGroup['unit_price_group_start_col']) . cpms_project_unit_price_lang('column_suffix');
    $detectedColumns['unit_price_group_end_col'] = cpms_project_unit_price_col_to_letter((int)$unitGroup['unit_price_group_end_col']) . cpms_project_unit_price_lang('column_suffix');

    $debug = array(
        'sheet_name' => $sheetName,
        'group_header_row' => (int)$unitGroup['group_header_row'],
        'detail_header_row' => (int)$unitGroup['detail_header_row'],
        'item_col' => cpms_project_unit_price_col_to_letter((int)$columns['item_name']),
        'spec_col' => cpms_project_unit_price_col_to_letter((int)$columns['spec']),
        'unit_col' => cpms_project_unit_price_col_to_letter((int)$columns['unit']),
        'qty_col' => cpms_project_unit_price_col_to_letter((int)$columns['qty']),
        'unit_price_group_start_col' => cpms_project_unit_price_col_to_letter((int)$unitGroup['unit_price_group_start_col']),
        'unit_price_group_end_col' => cpms_project_unit_price_col_to_letter((int)$unitGroup['unit_price_group_end_col']),
        'material_unit_price_col' => cpms_project_unit_price_col_to_letter((int)$unitGroup['material_unit_price_col']),
        'labor_unit_price_col' => cpms_project_unit_price_col_to_letter((int)$unitGroup['labor_unit_price_col']),
        'expense_unit_price_col' => cpms_project_unit_price_col_to_letter((int)$unitGroup['expense_unit_price_col']),
        'excel_unit_price_total_col' => ((int)$unitGroup['excel_unit_price_total_col'] > 0 ? cpms_project_unit_price_col_to_letter((int)$unitGroup['excel_unit_price_total_col']) : ''),
        'amount_col' => ((int)$unitGroup['amount_col'] > 0 ? cpms_project_unit_price_col_to_letter((int)$unitGroup['amount_col']) : ''),
        'first_rows' => array()
    );

    $result['ok'] = true;
    $result['message'] = '';
    $result['header_end_row'] = $headerEndRow;
    $result['data_start_row'] = $headerEndRow + 1;
    $result['columns'] = $columns;
    $result['detected_columns'] = $detectedColumns;
    $result['field_rows'] = $fieldRows;
    $result['debug'] = $debug;
    return $result;
}}

if (!function_exists('cpms_project_unit_price_should_skip_item')) {
function cpms_project_unit_price_should_skip_item($itemName) {
    $itemName = trim((string)$itemName);
    $normalized = cpms_project_unit_price_label_normalize($itemName);
    if ($itemName === '' || $normalized === '') return true;
    if (preg_match('/^\[[^\]]+\]$/u', $itemName)) return true;
    if (mb_strpos($normalized, cpms_project_unit_price_label_normalize(cpms_project_unit_price_lang('grand_total')), 0, 'UTF-8') !== false) return true;
    $sumNorm = cpms_project_unit_price_label_normalize(cpms_project_unit_price_lang('sum'));
    if ($normalized === $sumNorm) return true;
    if (mb_substr($normalized, 0 - mb_strlen($sumNorm, 'UTF-8'), mb_strlen($sumNorm, 'UTF-8'), 'UTF-8') === $sumNorm) return true;
    if (preg_match('/('
        . preg_quote(cpms_project_unit_price_label_normalize(cpms_project_unit_price_lang('subtotal')), '/')
        . '|'
        . preg_quote(cpms_project_unit_price_label_normalize(cpms_project_unit_price_lang('grand_total')), '/')
        . '|'
        . preg_quote(cpms_project_unit_price_label_normalize(cpms_project_unit_price_lang('overall_total')), '/')
        . '|'
        . preg_quote(cpms_project_unit_price_label_normalize(cpms_project_unit_price_lang('work_total')), '/')
        . '|'
        . preg_quote(cpms_project_unit_price_label_normalize(cpms_project_unit_price_lang('direct_total')), '/')
        . '|'
        . preg_quote(cpms_project_unit_price_label_normalize(cpms_project_unit_price_lang('indirect_total')), '/')
        . ')$/u', $normalized)) return true;
    return false;
}}

if (!function_exists('cpms_project_unit_price_extract_rows')) {
function cpms_project_unit_price_extract_rows($matrix, $maxRow, $detected) {
    $rows = array();
    $columns = isset($detected['columns']) ? $detected['columns'] : array();
    $dataStartRow = isset($detected['data_start_row']) ? (int)$detected['data_start_row'] : 0;
    if ($dataStartRow <= 0) return $rows;

    $blankCount = 0;
    $importOrder = 0;
    $rowNumber = $dataStartRow;
    while ($rowNumber <= $maxRow) {
        $itemName = isset($columns['item_name']) && isset($matrix[$rowNumber][$columns['item_name']]) ? cpms_project_unit_price_text_normalize($matrix[$rowNumber][$columns['item_name']]) : '';
        $spec = isset($columns['spec']) && isset($matrix[$rowNumber][$columns['spec']]) ? cpms_project_unit_price_text_normalize($matrix[$rowNumber][$columns['spec']]) : '';
        $unit = isset($columns['unit']) && isset($matrix[$rowNumber][$columns['unit']]) ? cpms_project_unit_price_text_normalize($matrix[$rowNumber][$columns['unit']]) : '';
        $qtyRaw = isset($columns['qty']) && isset($matrix[$rowNumber][$columns['qty']]) ? $matrix[$rowNumber][$columns['qty']] : '';
        $materialRaw = isset($columns['material_unit_price']) && isset($matrix[$rowNumber][$columns['material_unit_price']]) ? $matrix[$rowNumber][$columns['material_unit_price']] : '';
        $laborRaw = isset($columns['labor_unit_price']) && isset($matrix[$rowNumber][$columns['labor_unit_price']]) ? $matrix[$rowNumber][$columns['labor_unit_price']] : '';
        $expenseRaw = isset($columns['expense_unit_price']) && isset($matrix[$rowNumber][$columns['expense_unit_price']]) ? $matrix[$rowNumber][$columns['expense_unit_price']] : '';
        $excelTotalRaw = isset($columns['excel_unit_price_total']) && isset($matrix[$rowNumber][$columns['excel_unit_price_total']]) ? $matrix[$rowNumber][$columns['excel_unit_price_total']] : '';
        $amountRaw = isset($columns['amount']) && isset($matrix[$rowNumber][$columns['amount']]) ? $matrix[$rowNumber][$columns['amount']] : '';
        $remark = isset($columns['remark']) && isset($matrix[$rowNumber][$columns['remark']]) ? cpms_project_unit_price_text_normalize($matrix[$rowNumber][$columns['remark']]) : '';

        if ($itemName === '' && trim((string)$unit) === '' && trim((string)$qtyRaw) === '' && trim((string)$materialRaw) === '' && trim((string)$laborRaw) === '' && trim((string)$expenseRaw) === '') {
            $blankCount++;
            if ($blankCount >= 50 && count($rows) > 0) break;
            $rowNumber++;
            continue;
        }
        $blankCount = 0;

        if (cpms_project_unit_price_should_skip_item($itemName)) {
            $rowNumber++;
            continue;
        }
        if (trim((string)$unit) === '') {
            $rowNumber++;
            continue;
        }

        $qty = cpms_project_unit_price_number_parse($qtyRaw);
        $materialParsed = cpms_project_unit_price_number_parse($materialRaw);
        $laborParsed = cpms_project_unit_price_number_parse($laborRaw);
        $expenseParsed = cpms_project_unit_price_number_parse($expenseRaw);
        $excelUnitPriceTotal = cpms_project_unit_price_number_parse($excelTotalRaw);
        $hasAnyUnitPricePart = ($materialParsed !== null || $laborParsed !== null || $expenseParsed !== null || $excelUnitPriceTotal !== null);
        if ($qty === null || !$hasAnyUnitPricePart) {
            $rowNumber++;
            continue;
        }

        $materialUnitPrice = ($materialParsed === null) ? 0.0 : $materialParsed;
        $laborUnitPrice = ($laborParsed === null) ? 0.0 : $laborParsed;
        $expenseUnitPrice = ($expenseParsed === null) ? 0.0 : $expenseParsed;
        $calculatedUnitPrice = $materialUnitPrice + $laborUnitPrice + $expenseUnitPrice;
        $unitPrice = ($excelUnitPriceTotal !== null) ? $excelUnitPriceTotal : $calculatedUnitPrice;
        $amount = cpms_project_unit_price_number_parse($amountRaw);

        if ($excelUnitPriceTotal !== null && $materialParsed === null && $laborParsed === null && $expenseParsed === null) {
            $validationCode = 'ok';
            $validationText = cpms_project_unit_price_lang('validation_ok');
        } else if ($excelUnitPriceTotal === null) {
            $validationCode = 'missing';
            $validationText = cpms_project_unit_price_lang('validation_missing');
        } else if (abs($excelUnitPriceTotal - $calculatedUnitPrice) < 0.0001) {
            $validationCode = 'ok';
            $validationText = cpms_project_unit_price_lang('validation_ok');
        } else {
            $validationCode = 'mismatch';
            $validationText = cpms_project_unit_price_lang('validation_mismatch');
        }

        $isSafety = 0;
        if (mb_strpos($itemName, cpms_project_unit_price_lang('safety'), 0, 'UTF-8') !== false || mb_strpos($spec, cpms_project_unit_price_lang('safety'), 0, 'UTF-8') !== false) $isSafety = 1;

        $importOrder++;
        array_push($rows, array(
            'item_name' => $itemName,
            'spec' => $spec,
            'unit' => $unit,
            'qty' => $qty,
            'material_unit_price' => $materialUnitPrice,
            'labor_unit_price' => $laborUnitPrice,
            'expense_unit_price' => $expenseUnitPrice,
            'unit_price' => $unitPrice,
            'total_unit_price' => $unitPrice,
            'calculated_unit_price' => $calculatedUnitPrice,
            'excel_unit_price_total' => $excelUnitPriceTotal,
            'amount' => $amount,
            'is_safety' => $isSafety,
            'remark' => $remark,
            'source_row' => $rowNumber,
            'import_order' => $importOrder,
            'unit_price_validation' => $validationCode,
            'unit_price_validation_text' => $validationText
        ));
        $rowNumber++;
    }

    return $rows;
}}

if (!function_exists('cpms_project_parse_unit_price_xlsx')) {
function cpms_project_parse_unit_price_xlsx($pdo, $tmpFile) {
    $result = array(
        'ok' => false,
        'rows' => array(),
        'message' => '',
        'detected_columns' => array(),
        'sheet_name' => '',
        'header_end_row' => 0,
        'data_start_row' => 0,
        'debug' => array()
    );

    if (!$pdo) {
        $result['message'] = cpms_project_unit_price_lang('msg_db_fail');
        return $result;
    }
    if (!is_string($tmpFile) || $tmpFile === '' || !file_exists($tmpFile)) {
        $result['message'] = cpms_project_unit_price_lang('msg_file_missing');
        return $result;
    }
    if (!class_exists('ZipArchive')) {
        $result['message'] = cpms_project_unit_price_lang('msg_zip_missing');
        return $result;
    }

    $zip = new ZipArchive();
    if ($zip->open($tmpFile) !== true) {
        $result['message'] = cpms_project_unit_price_lang('msg_open_fail');
        return $result;
    }

    $sharedStrings = cpms_project_unit_price_shared_strings($zip);
    $sheets = cpms_project_unit_price_sheet_list($zip);
    if (count($sheets) === 0) {
        $zip->close();
        $result['message'] = cpms_project_unit_price_lang('msg_sheet_missing');
        return $result;
    }

    $best = null;
    $bestScore = -1;
    $bestMessage = cpms_project_unit_price_lang('msg_header_not_found');

    foreach ($sheets as $sheet) {
        $sheetName = isset($sheet['name']) ? (string)$sheet['name'] : '';
        $sheetPath = isset($sheet['path']) ? (string)$sheet['path'] : '';
        if ($sheetPath === '') continue;

        $loaded = cpms_project_unit_price_sheet_matrix($zip, $sheetPath, $sharedStrings, 3000, 180);
        if (empty($loaded['ok'])) {
            if ($loaded['message'] !== '') $bestMessage = $loaded['message'];
            continue;
        }

        $detected = cpms_project_unit_price_detect_columns($loaded['matrix'], (int)$loaded['max_row'], (int)$loaded['max_col'], $sheetName);
        if (empty($detected['ok'])) {
            if (isset($detected['message']) && $detected['message'] !== '') $bestMessage = $detected['message'];
            continue;
        }

        $rows = cpms_project_unit_price_extract_rows($loaded['matrix'], (int)$loaded['max_row'], $detected);
        if (count($rows) === 0) {
            $bestMessage = cpms_project_unit_price_lang('msg_rows_not_found');
            continue;
        }

        $score = (count($rows) * 10) + count($detected['columns']);
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = array('rows' => $rows, 'detected' => $detected);
        }
    }

    $zip->close();

    if ($best === null) {
        $result['message'] = $bestMessage;
        return $result;
    }

    $debug = isset($best['detected']['debug']) ? $best['detected']['debug'] : array();
    $debug['first_rows'] = array_slice($best['rows'], 0, 10);

    $result['ok'] = true;
    $result['rows'] = $best['rows'];
    $result['detected_columns'] = isset($best['detected']['detected_columns']) ? $best['detected']['detected_columns'] : array();
    $result['sheet_name'] = isset($best['detected']['sheet_name']) ? $best['detected']['sheet_name'] : '';
    $result['header_end_row'] = isset($best['detected']['header_end_row']) ? (int)$best['detected']['header_end_row'] : 0;
    $result['data_start_row'] = isset($best['detected']['data_start_row']) ? (int)$best['detected']['data_start_row'] : 0;
    $result['debug'] = $debug;
    return $result;
}}
