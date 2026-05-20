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
            'msg_header_not_found' => json_decode('"\uB2E8\uAC00\uB0B4\uC5ED \uD5E4\uB354\uB97C \uCC3E\uC9C0 \uBABB\uD588\uC2B5\uB2C8\uB2E4. \uD488\uBA85/\uADDC\uACA9/\uB2E8\uC704/\uC218\uB7C9/\uB2E8\uAC00/\uACC4 \uC704\uCE58\uB97C \uD655\uC778\uD574\uC8FC\uC138\uC694."'),
            'msg_unit_price_not_found' => json_decode('"\uB2E8\uAC00\uC758 \uACC4 \uCEEC\uB7FC\uC744 \uD655\uC815\uD558\uC9C0 \uBABB\uD588\uC2B5\uB2C8\uB2E4. \uBBF8\uB9AC\uBCF4\uAE30\uC5D0\uC11C \uC5F4 \uC704\uCE58\uB97C \uD655\uC778\uD574\uC8FC\uC138\uC694."'),
            'msg_rows_not_found' => json_decode('"\uC815\uC0C1 \uB2E8\uAC00\uB0B4\uC5ED \uD589\uC744 \uCC3E\uC9C0 \uBABB\uD588\uC2B5\uB2C8\uB2E4. \uD488\uBA85/\uB2E8\uC704/\uC218\uB7C9/\uB2E8\uAC00 \uACC4 \uAC12\uC744 \uD655\uC778\uD574\uC8FC\uC138\uC694."'),
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
    $value = str_replace(array(',', ' '), '', trim((string)$value));
    $value = preg_replace('/[^0-9\.\-]/', '', $value);
    if ($value === '' || $value === '-' || $value === '.' || $value === '-.') return null;
    if (!is_numeric($value)) return null;
    return (float)$value;
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
            $sheets[] = array('name' => 'sheet1', 'path' => 'xl/worksheets/sheet1.xml');
        }
        return $sheets;
    }

    $wbx = @simplexml_load_string($wb);
    $relx = @simplexml_load_string($rels);
    if (!$wbx || !$relx || !isset($wbx->sheets) || !isset($wbx->sheets->sheet)) {
        if ($zip->getFromName('xl/worksheets/sheet1.xml') !== false) {
            $sheets[] = array('name' => 'sheet1', 'path' => 'xl/worksheets/sheet1.xml');
        }
        return $sheets;
    }

    $targets = array();
    foreach ($relx->Relationship as $rel) {
        $id = (string)$rel['Id'];
        $target = (string)$rel['Target'];
        if ($id !== '' && $target !== '') $targets[$id] = 'xl/' . ltrim($target, '/');
    }

    foreach ($wbx->sheets->sheet as $sheet) {
        $name = (string)$sheet['name'];
        $rid = (string)$sheet->attributes('r', true)['id'];
        if ($rid === '') $rid = (string)$sheet['id'];
        if ($rid !== '' && isset($targets[$rid])) {
            $sheets[] = array('name' => $name, 'path' => $targets[$rid]);
        }
    }

    if (count($sheets) === 0 && $zip->getFromName('xl/worksheets/sheet1.xml') !== false) {
        $sheets[] = array('name' => 'sheet1', 'path' => 'xl/worksheets/sheet1.xml');
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
        $sharedStrings[] = $text;
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

if (!function_exists('cpms_project_unit_price_group_above')) {
function cpms_project_unit_price_group_above($matrix, $row, $col) {
    $bestLabel = '';
    $bestDistance = 9999;
    $scan = (int)$row - 1;
    while ($scan >= 1) {
        $value = '';
        if (isset($matrix[$scan]) && isset($matrix[$scan][(int)$col])) $value = $matrix[$scan][(int)$col];
        $norm = cpms_project_unit_price_label_normalize($value);
        if ($norm !== '') {
            if (cpms_project_unit_price_header_match($norm, cpms_project_unit_price_lang('unit_price'))) {
                $distance = (int)$row - $scan;
                if ($distance < $bestDistance) {
                    $bestDistance = $distance;
                    $bestLabel = cpms_project_unit_price_lang('unit_price');
                }
            }
            if (cpms_project_unit_price_header_match($norm, cpms_project_unit_price_lang('amount'))) {
                $distance = (int)$row - $scan;
                if ($distance < $bestDistance) {
                    $bestDistance = $distance;
                    $bestLabel = cpms_project_unit_price_lang('amount');
                }
            }
        }
        $scan--;
    }
    return $bestLabel;
}}

if (!function_exists('cpms_project_unit_price_header_positions')) {
function cpms_project_unit_price_header_positions($matrix, $maxRow, $maxCol, $headerLimit) {
    $positions = array(
        'item_name' => array(),
        'spec' => array(),
        'unit' => array(),
        'qty' => array(),
        'remark' => array(),
        'unit_price_direct' => array(),
        'amount_direct' => array(),
        'unit_price_group' => array(),
        'amount_group' => array()
    );

    $lastRow = ($maxRow < $headerLimit) ? $maxRow : $headerLimit;
    $r = 1;
    while ($r <= $lastRow) {
        $c = 1;
        while ($c <= $maxCol) {
            $value = '';
            if (isset($matrix[$r]) && isset($matrix[$r][$c])) $value = $matrix[$r][$c];
            $norm = cpms_project_unit_price_label_normalize($value);
            if ($norm !== '') {
                if (cpms_project_unit_price_header_match($norm, cpms_project_unit_price_lang('item_name'))) $positions['item_name'][] = array('row'=>$r,'col'=>$c);
                if (cpms_project_unit_price_header_match($norm, cpms_project_unit_price_lang('spec'))) $positions['spec'][] = array('row'=>$r,'col'=>$c);
                if (cpms_project_unit_price_header_match($norm, cpms_project_unit_price_lang('unit'))) $positions['unit'][] = array('row'=>$r,'col'=>$c);
                if (cpms_project_unit_price_header_match($norm, cpms_project_unit_price_lang('qty'))) $positions['qty'][] = array('row'=>$r,'col'=>$c);
                if (cpms_project_unit_price_header_match($norm, cpms_project_unit_price_lang('remark'))) $positions['remark'][] = array('row'=>$r,'col'=>$c);

                if (cpms_project_unit_price_header_match($norm, cpms_project_unit_price_lang('total_unit_price')) || cpms_project_unit_price_header_match($norm, cpms_project_unit_price_lang('unit_price') . cpms_project_unit_price_lang('sum')) || cpms_project_unit_price_header_match($norm, cpms_project_unit_price_lang('unit_price') . cpms_project_unit_price_lang('grand_total'))) {
                    $positions['unit_price_direct'][] = array('row'=>$r,'col'=>$c);
                }
                if (cpms_project_unit_price_header_match($norm, cpms_project_unit_price_lang('total_amount')) || cpms_project_unit_price_header_match($norm, cpms_project_unit_price_lang('amount') . cpms_project_unit_price_lang('sum'))) {
                    $positions['amount_direct'][] = array('row'=>$r,'col'=>$c);
                }

                if ($norm === cpms_project_unit_price_label_normalize(cpms_project_unit_price_lang('sum'))) {
                    $group = cpms_project_unit_price_group_above($matrix, $r, $c);
                    if ($group === cpms_project_unit_price_lang('unit_price')) $positions['unit_price_group'][] = array('row'=>$r,'col'=>$c);
                    if ($group === cpms_project_unit_price_lang('amount')) $positions['amount_group'][] = array('row'=>$r,'col'=>$c);
                }
            }
            $c++;
        }
        $r++;
    }
    return $positions;
}}

if (!function_exists('cpms_project_unit_price_pick_position')) {
function cpms_project_unit_price_pick_position($positions) {
    if (!is_array($positions) || count($positions) === 0) return null;
    usort($positions, function($a, $b) {
        if ((int)$a['row'] === (int)$b['row']) return ((int)$a['col'] - (int)$b['col']);
        return ((int)$a['row'] - (int)$b['row']);
    });
    return $positions[0];
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
        'field_rows' => array()
    );

    $positions = cpms_project_unit_price_header_positions($matrix, $maxRow, $maxCol, 80);
    $columns = array();
    $fieldRows = array();

    $picked = cpms_project_unit_price_pick_position($positions['item_name']);
    if ($picked !== null) { $columns['item_name'] = (int)$picked['col']; $fieldRows['item_name'] = (int)$picked['row']; }
    $picked = cpms_project_unit_price_pick_position($positions['spec']);
    if ($picked !== null) { $columns['spec'] = (int)$picked['col']; $fieldRows['spec'] = (int)$picked['row']; }
    $picked = cpms_project_unit_price_pick_position($positions['unit']);
    if ($picked !== null) { $columns['unit'] = (int)$picked['col']; $fieldRows['unit'] = (int)$picked['row']; }
    $picked = cpms_project_unit_price_pick_position($positions['qty']);
    if ($picked !== null) { $columns['qty'] = (int)$picked['col']; $fieldRows['qty'] = (int)$picked['row']; }
    $picked = cpms_project_unit_price_pick_position($positions['remark']);
    if ($picked !== null) { $columns['remark'] = (int)$picked['col']; $fieldRows['remark'] = (int)$picked['row']; }

    $picked = cpms_project_unit_price_pick_position($positions['unit_price_group']);
    if ($picked === null) $picked = cpms_project_unit_price_pick_position($positions['unit_price_direct']);
    if ($picked !== null) { $columns['unit_price'] = (int)$picked['col']; $fieldRows['unit_price'] = (int)$picked['row']; }

    $picked = cpms_project_unit_price_pick_position($positions['amount_group']);
    if ($picked === null) $picked = cpms_project_unit_price_pick_position($positions['amount_direct']);
    if ($picked !== null) { $columns['amount'] = (int)$picked['col']; $fieldRows['amount'] = (int)$picked['row']; }

    if (!isset($columns['unit_price'])) {
        if (count($positions['amount_group']) > 0 || count($positions['unit_price_direct']) > 0 || count($positions['amount_direct']) > 0) {
            $result['message'] = cpms_project_unit_price_lang('msg_unit_price_not_found');
        }
        return $result;
    }

    if (!isset($columns['item_name']) || !isset($columns['unit']) || !isset($columns['qty'])) return $result;

    $headerEndRow = 0;
    foreach ($fieldRows as $field => $row) {
        if ((int)$row > $headerEndRow) $headerEndRow = (int)$row;
    }
    if ($headerEndRow <= 0) return $result;

    $detectedColumns = array();
    foreach ($columns as $field => $col) {
        $detectedColumns[$field] = cpms_project_unit_price_col_to_letter((int)$col) . cpms_project_unit_price_lang('column_suffix');
    }

    $result['ok'] = true;
    $result['message'] = '';
    $result['header_end_row'] = $headerEndRow;
    $result['data_start_row'] = $headerEndRow + 1;
    $result['columns'] = $columns;
    $result['detected_columns'] = $detectedColumns;
    $result['field_rows'] = $fieldRows;
    return $result;
}}

if (!function_exists('cpms_project_unit_price_should_skip_item')) {
function cpms_project_unit_price_should_skip_item($itemName) {
    $itemName = trim((string)$itemName);
    $normalized = cpms_project_unit_price_label_normalize($itemName);
    if ($itemName === '' || $normalized === '') return true;
    if (preg_match('/^\[[^\]]+\]$/u', $itemName)) return true;
    if (mb_strpos($normalized, cpms_project_unit_price_label_normalize(cpms_project_unit_price_lang('grand_total')), 0, 'UTF-8') !== false) return true;
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
    if ($normalized === cpms_project_unit_price_label_normalize(cpms_project_unit_price_lang('sum'))) return true;
    return false;
}}

if (!function_exists('cpms_project_unit_price_extract_rows')) {
function cpms_project_unit_price_extract_rows($matrix, $maxRow, $detected) {
    $rows = array();
    $columns = isset($detected['columns']) ? $detected['columns'] : array();
    $dataStartRow = isset($detected['data_start_row']) ? (int)$detected['data_start_row'] : 0;
    if ($dataStartRow <= 0) return $rows;

    $blankCount = 0;
    $rowNumber = $dataStartRow;
    while ($rowNumber <= $maxRow) {
        $itemName = isset($columns['item_name']) && isset($matrix[$rowNumber][$columns['item_name']]) ? cpms_project_unit_price_text_normalize($matrix[$rowNumber][$columns['item_name']]) : '';
        $spec = isset($columns['spec']) && isset($matrix[$rowNumber][$columns['spec']]) ? cpms_project_unit_price_text_normalize($matrix[$rowNumber][$columns['spec']]) : '';
        $unit = isset($columns['unit']) && isset($matrix[$rowNumber][$columns['unit']]) ? cpms_project_unit_price_text_normalize($matrix[$rowNumber][$columns['unit']]) : '';
        $qtyRaw = isset($columns['qty']) && isset($matrix[$rowNumber][$columns['qty']]) ? $matrix[$rowNumber][$columns['qty']] : '';
        $unitPriceRaw = isset($columns['unit_price']) && isset($matrix[$rowNumber][$columns['unit_price']]) ? $matrix[$rowNumber][$columns['unit_price']] : '';
        $amountRaw = isset($columns['amount']) && isset($matrix[$rowNumber][$columns['amount']]) ? $matrix[$rowNumber][$columns['amount']] : '';
        $remark = isset($columns['remark']) && isset($matrix[$rowNumber][$columns['remark']]) ? cpms_project_unit_price_text_normalize($matrix[$rowNumber][$columns['remark']]) : '';

        if ($itemName === '' && trim((string)$unit) === '' && trim((string)$qtyRaw) === '' && trim((string)$unitPriceRaw) === '') {
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
        $unitPrice = cpms_project_unit_price_number_parse($unitPriceRaw);
        $amount = cpms_project_unit_price_number_parse($amountRaw);

        if ($qty === null || $unitPrice === null) {
            $rowNumber++;
            continue;
        }

        $isSafety = 0;
        if (mb_strpos($itemName, cpms_project_unit_price_lang('safety'), 0, 'UTF-8') !== false || mb_strpos($spec, cpms_project_unit_price_lang('safety'), 0, 'UTF-8') !== false) $isSafety = 1;

        $rows[] = array(
            'item_name' => $itemName,
            'spec' => $spec,
            'unit' => $unit,
            'qty' => $qty,
            'unit_price' => $unitPrice,
            'total_unit_price' => $unitPrice,
            'amount' => $amount,
            'labor_unit_price' => null,
            'material_unit_price' => null,
            'safety_unit_price' => null,
            'is_safety' => $isSafety,
            'remark' => $remark,
            'source_row' => $rowNumber
        );
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
        'data_start_row' => 0
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

        $loaded = cpms_project_unit_price_sheet_matrix($zip, $sheetPath, $sharedStrings, 3000, 160);
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

    $result['ok'] = true;
    $result['rows'] = $best['rows'];
    $result['detected_columns'] = isset($best['detected']['detected_columns']) ? $best['detected']['detected_columns'] : array();
    $result['sheet_name'] = isset($best['detected']['sheet_name']) ? $best['detected']['sheet_name'] : '';
    $result['header_end_row'] = isset($best['detected']['header_end_row']) ? (int)$best['detected']['header_end_row'] : 0;
    $result['data_start_row'] = isset($best['detected']['data_start_row']) ? (int)$best['detected']['data_start_row'] : 0;
    return $result;
}}
