<?php
if (!function_exists('cpms_project_unit_price_lang')) {
function cpms_project_unit_price_lang($key) {
    static $map = null;
    if ($map === null) {
        $map = array(
            'item_name' => json_decode('"\uD488\uBA85"'),
            'trade_group' => json_decode('"\uACF5\uC885\uADF8\uB8F9"'),
            'sub_trade' => json_decode('"\uC138\uBD80\uACF5\uC885"'),
            'location_name' => json_decode('"\uC704\uCE58"'),
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

if (!function_exists('cpms_project_unit_price_is_yellow_rgb')) {
function cpms_project_unit_price_is_yellow_rgb($rgb) {
    $rgb = strtoupper(trim((string)$rgb));
    if ($rgb === '') return false;
    if (strlen($rgb) > 6) $rgb = substr($rgb, -6);
    if (in_array($rgb, array('FFFF00', 'FFF2CC', 'FFE699', 'FFD966', 'FFFF99', 'FFFFCC'), true)) return true;
    if (strlen($rgb) !== 6 || !preg_match('/^[0-9A-F]{6}$/', $rgb)) return false;
    $r = hexdec(substr($rgb, 0, 2));
    $g = hexdec(substr($rgb, 2, 2));
    $b = hexdec(substr($rgb, 4, 2));
    return ($r >= 220 && $g >= 190 && $b <= 140);
}}

if (!function_exists('cpms_project_unit_price_yellow_style_ids')) {
function cpms_project_unit_price_yellow_style_ids($zip) {
    $styleIds = array();
    $stylesXml = $zip->getFromName('xl/styles.xml');
    if ($stylesXml === false) return $styleIds;
    $sx = @simplexml_load_string($stylesXml);
    if (!$sx) return $styleIds;

    $yellowFillIds = array();
    $fillIndex = 0;
    if (isset($sx->fills) && isset($sx->fills->fill)) {
        foreach ($sx->fills->fill as $fill) {
            $isYellow = false;
            if (isset($fill->patternFill)) {
                if (isset($fill->patternFill->fgColor)) {
                    $rgb = isset($fill->patternFill->fgColor['rgb']) ? (string)$fill->patternFill->fgColor['rgb'] : '';
                    $indexed = isset($fill->patternFill->fgColor['indexed']) ? (int)$fill->patternFill->fgColor['indexed'] : -1;
                    if (cpms_project_unit_price_is_yellow_rgb($rgb) || $indexed === 13) $isYellow = true;
                }
                if (!$isYellow && isset($fill->patternFill->bgColor)) {
                    $rgb = isset($fill->patternFill->bgColor['rgb']) ? (string)$fill->patternFill->bgColor['rgb'] : '';
                    $indexed = isset($fill->patternFill->bgColor['indexed']) ? (int)$fill->patternFill->bgColor['indexed'] : -1;
                    if (cpms_project_unit_price_is_yellow_rgb($rgb) || $indexed === 13) $isYellow = true;
                }
            }
            if ($isYellow) $yellowFillIds[$fillIndex] = true;
            $fillIndex++;
        }
    }

    $xfIndex = 0;
    if (isset($sx->cellXfs) && isset($sx->cellXfs->xf)) {
        foreach ($sx->cellXfs->xf as $xf) {
            $fillId = isset($xf['fillId']) ? (int)$xf['fillId'] : -1;
            if ($fillId >= 0 && isset($yellowFillIds[$fillId])) $styleIds[$xfIndex] = true;
            $xfIndex++;
        }
    }
    return $styleIds;
}}

if (!function_exists('cpms_project_unit_price_sheet_matrix')) {
function cpms_project_unit_price_sheet_matrix($zip, $sheetPath, $sharedStrings, $maxRows, $maxCols) {
    $result = array('ok'=>false, 'matrix'=>array(), 'yellow_cells'=>array(), 'max_row'=>0, 'max_col'=>0, 'message'=>'');
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
    $yellowCells = array();
    $yellowStyleIds = cpms_project_unit_price_yellow_style_ids($zip);

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
            $styleId = isset($cellNode['s']) ? (int)$cellNode['s'] : -1;
            if ($styleId >= 0 && isset($yellowStyleIds[$styleId])) {
                if (!isset($yellowCells[$cellRow])) $yellowCells[$cellRow] = array();
                $yellowCells[$cellRow][$cellCol] = true;
            }
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
    $result['yellow_cells'] = $yellowCells;
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
    return array(cpms_project_unit_price_lang('item_name'), '품 명', '자재명', '자재', '품목명', '품목', '명칭', '공종명', '공 종 명');
}}

if (!function_exists('cpms_project_unit_price_trade_group_keys')) {
function cpms_project_unit_price_trade_group_keys() {
    return array(cpms_project_unit_price_lang('trade_group'), '공종 그룹', '대공종', '공종명', '공 종 명', '공종');
}}

if (!function_exists('cpms_project_unit_price_sub_trade_keys')) {
function cpms_project_unit_price_sub_trade_keys() {
    return array(cpms_project_unit_price_lang('sub_trade'), '세부 공종', '소공종', '세부');
}}

if (!function_exists('cpms_project_unit_price_location_keys')) {
function cpms_project_unit_price_location_keys() {
    return array(cpms_project_unit_price_lang('location_name'), '시공위치', '작업위치', '구역', '층', '실명');
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
        'trade_group' => array(),
        'sub_trade' => array(),
        'location_name' => array(),
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
                if (cpms_project_unit_price_header_match_any($norm, cpms_project_unit_price_trade_group_keys(), false)) array_push($positions['trade_group'], array('row'=>$r,'col'=>$c));
                if (cpms_project_unit_price_header_match_any($norm, cpms_project_unit_price_sub_trade_keys(), false)) array_push($positions['sub_trade'], array('row'=>$r,'col'=>$c));
                if (cpms_project_unit_price_header_match_any($norm, cpms_project_unit_price_location_keys(), false)) array_push($positions['location_name'], array('row'=>$r,'col'=>$c));
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

    foreach (array('trade_group', 'sub_trade', 'location_name', 'item_name', 'spec', 'unit', 'qty', 'remark') as $field) {
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
        $tradeGroup = isset($columns['trade_group']) && isset($matrix[$rowNumber][$columns['trade_group']]) ? cpms_project_unit_price_text_normalize($matrix[$rowNumber][$columns['trade_group']]) : '';
        $subTrade = isset($columns['sub_trade']) && isset($matrix[$rowNumber][$columns['sub_trade']]) ? cpms_project_unit_price_text_normalize($matrix[$rowNumber][$columns['sub_trade']]) : '';
        $locationName = isset($columns['location_name']) && isset($matrix[$rowNumber][$columns['location_name']]) ? cpms_project_unit_price_text_normalize($matrix[$rowNumber][$columns['location_name']]) : '';
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
        $rowData = array(
            'trade_group' => $tradeGroup,
            'sub_trade' => $subTrade,
            'location_name' => $locationName,
            'work_group' => $tradeGroup,
            'sub_work_group' => $subTrade,
            'item_name' => $itemName,
            'original_item_name' => $itemName,
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
            'source_row_no' => $rowNumber,
            'source_sheet_name' => isset($detected['sheet_name']) ? (string)$detected['sheet_name'] : '',
            'import_order' => $importOrder,
            'unit_price_validation' => $validationCode,
            'unit_price_validation_text' => $validationText
        );
        $rowData['item_fingerprint'] = cpms_project_unit_price_fingerprint($rowData);
        array_push($rows, $rowData);
        $rowNumber++;
    }

    return $rows;
}}

if (!function_exists('cpms_project_unit_price_decode')) {
function cpms_project_unit_price_decode($value) {
    return json_decode('"' . $value . '"');
}}

if (!function_exists('cpms_project_unit_price_standard_text')) {
function cpms_project_unit_price_standard_text($key) {
    static $map = null;
    if ($map === null) {
        $map = array(
            'sheet_name' => cpms_project_unit_price_decode('\\u0043\\u0050\\u004d\\u0053\\u005f\\uD45C\\uC900\\uB0B4\\uC5ED\\uC11C'),
            'row_type' => cpms_project_unit_price_decode('\\uD589\\uAD6C\\uBD84'),
            'trade_group' => cpms_project_unit_price_decode('\\uACF5\\uC885\\uADF8\\uB8F9'),
            'sub_trade' => cpms_project_unit_price_decode('\\uC138\\uBD80\\uACF5\\uC885'),
            'location_name' => cpms_project_unit_price_decode('\\uC704\\uCE58'),
            'item_name' => cpms_project_unit_price_decode('\\uD488\\uBA85'),
            'spec' => cpms_project_unit_price_decode('\\uADDC\\uACA9'),
            'unit' => cpms_project_unit_price_decode('\\uB2E8\\uC704'),
            'qty' => cpms_project_unit_price_decode('\\uC218\\uB7C9'),
            'material_unit_price' => cpms_project_unit_price_decode('\\uC7AC\\uB8CC\\uBE44\\uB2E8\\uAC00'),
            'labor_unit_price' => cpms_project_unit_price_decode('\\uB178\\uBB34\\uBE44\\uB2E8\\uAC00'),
            'expense_unit_price' => cpms_project_unit_price_decode('\\uACBD\\uBE44\\uB2E8\\uAC00'),
            'unit_price' => cpms_project_unit_price_decode('\\uD569\\uACC4\\uB2E8\\uAC00'),
            'amount' => cpms_project_unit_price_decode('\\uAE08\\uC561'),
            'remark' => cpms_project_unit_price_decode('\\uBE44\\uACE0'),
            'location_type' => cpms_project_unit_price_decode('\\uC704\\uCE58'),
            'work_type' => cpms_project_unit_price_decode('\\uACF5\\uC885'),
            'detail_type' => cpms_project_unit_price_decode('\\uB0B4\\uC5ED'),
            'normal' => cpms_project_unit_price_decode('\\uC815\\uC0C1'),
            'missing_item' => cpms_project_unit_price_decode('\\uD488\\uBA85 \\uC5C6\\uC74C'),
            'missing_qty' => cpms_project_unit_price_decode('\\uC218\\uB7C9 \\uC5C6\\uC74C'),
            'missing_price' => cpms_project_unit_price_decode('\\uB2E8\\uAC00 \\uC5C6\\uC74C'),
            'calculated_price' => cpms_project_unit_price_decode('\\uB2E8\\uAC00 \\uACC4\\uC0B0'),
            'calculated_amount' => cpms_project_unit_price_decode('\\uAE08\\uC561 \\uACC4\\uC0B0'),
            'not_standard' => cpms_project_unit_price_decode('\\u0043\\u0050\\u004d\\u0053 \\uD45C\\uC900\\uB0B4\\uC5ED\\uC11C \\uC591\\uC2DD\\uC774 \\uC544\\uB2D9\\uB2C8\\uB2E4.'),
            'no_rows' => cpms_project_unit_price_decode('\\uC800\\uC7A5 \\uAC00\\uB2A5\\uD55C \\uB0B4\\uC5ED \\uD589\\uC774 \\uC5C6\\uC2B5\\uB2C8\\uB2E4.'),
            'missing_column_prefix' => cpms_project_unit_price_decode('\\uD544\\uC218 \\uCEEC\\uB7FC ['),
            'missing_column_suffix' => cpms_project_unit_price_decode(']\\uC744 \\uCC3E\\uC744 \\uC218 \\uC5C6\\uC2B5\\uB2C8\\uB2E4.'),
            'sheet_fallback' => cpms_project_unit_price_decode('\\uC2DC\\uD2B8 [\\u0043\\u0050\\u004d\\u0053\\u005f\\uD45C\\uC900\\uB0B4\\uC5ED\\uC11C]\\uB97C \\uCC3E\\uC744 \\uC218 \\uC5C6\\uC5B4 \\uCCAB \\uBC88\\uC9F8 \\uC2DC\\uD2B8\\uB97C \\uAC80\\uC0AC\\uD588\\uC2B5\\uB2C8\\uB2E4.')
        );
    }
    return isset($map[$key]) ? $map[$key] : '';
}}

if (!function_exists('cpms_project_unit_price_standard_labels')) {
function cpms_project_unit_price_standard_labels() {
    return array(
        'row_type' => cpms_project_unit_price_standard_text('row_type'),
        'trade_group' => cpms_project_unit_price_standard_text('trade_group'),
        'sub_trade' => cpms_project_unit_price_standard_text('sub_trade'),
        'location_name' => cpms_project_unit_price_standard_text('location_name'),
        'item_name' => cpms_project_unit_price_standard_text('item_name'),
        'spec' => cpms_project_unit_price_standard_text('spec'),
        'unit' => cpms_project_unit_price_standard_text('unit'),
        'qty' => cpms_project_unit_price_standard_text('qty'),
        'material_unit_price' => cpms_project_unit_price_standard_text('material_unit_price'),
        'labor_unit_price' => cpms_project_unit_price_standard_text('labor_unit_price'),
        'expense_unit_price' => cpms_project_unit_price_standard_text('expense_unit_price'),
        'unit_price' => cpms_project_unit_price_standard_text('unit_price'),
        'amount' => cpms_project_unit_price_standard_text('amount'),
        'remark' => cpms_project_unit_price_standard_text('remark')
    );
}}

if (!function_exists('cpms_project_unit_price_standard_header')) {
function cpms_project_unit_price_standard_header($matrix, $maxRow, $maxCol) {
    $labels = cpms_project_unit_price_standard_labels();
    $best = array('row'=>0, 'columns'=>array(), 'found'=>0, 'missing'=>array_keys($labels));
    $lastRow = ((int)$maxRow < 80) ? (int)$maxRow : 80;
    $r = 1;
    while ($r <= $lastRow) {
        $columns = array();
        $c = 1;
        while ($c <= (int)$maxCol) {
            $cell = isset($matrix[$r][$c]) ? $matrix[$r][$c] : '';
            $norm = cpms_project_unit_price_label_normalize($cell);
            if ($norm !== '') {
                foreach ($labels as $field => $label) {
                    if (!isset($columns[$field]) && $norm === cpms_project_unit_price_label_normalize($label)) {
                        $columns[$field] = $c;
                    }
                }
            }
            $c++;
        }
        $missing = array();
        foreach ($labels as $field => $label) {
            if (!isset($columns[$field])) array_push($missing, $field);
        }
        $found = count($labels) - count($missing);
        if ($found > (int)$best['found']) {
            $best = array('row'=>$r, 'columns'=>$columns, 'found'=>$found, 'missing'=>$missing);
        }
        if (count($missing) === 0) return array('ok'=>true, 'row'=>$r, 'columns'=>$columns, 'missing'=>array());
        $r++;
    }
    $best['ok'] = false;
    return $best;
}}

if (!function_exists('cpms_project_unit_price_standard_cell')) {
function cpms_project_unit_price_standard_cell($matrix, $rowNumber, $columns, $field) {
    if (!isset($columns[$field])) return '';
    $col = (int)$columns[$field];
    return isset($matrix[$rowNumber][$col]) ? cpms_project_unit_price_text_normalize($matrix[$rowNumber][$col]) : '';
}}

if (!function_exists('cpms_project_unit_price_standard_row_text')) {
function cpms_project_unit_price_standard_row_text($matrix, $rowNumber, $maxCol) {
    $parts = array();
    $c = 1;
    while ($c <= (int)$maxCol) {
        $value = isset($matrix[$rowNumber][$c]) ? trim((string)$matrix[$rowNumber][$c]) : '';
        if ($value !== '') array_push($parts, $value);
        $c++;
    }
    return implode(' ', $parts);
}}

if (!function_exists('cpms_project_unit_price_standard_is_total_row')) {
function cpms_project_unit_price_standard_is_total_row($text) {
    $norm = cpms_project_unit_price_label_normalize($text);
    if ($norm === '') return false;
    $words = array(
        cpms_project_unit_price_decode('\\uC18C\\uACC4'),
        cpms_project_unit_price_decode('\\uD569\\uACC4'),
        cpms_project_unit_price_decode('\\uCD1D\\uACC4'),
        cpms_project_unit_price_decode('\\uACF5\\uAE09\\uAC00\\uC561'),
        cpms_project_unit_price_decode('\\uBD80\\uAC00\\uC138'),
        cpms_project_unit_price_decode('\\uD569\\uC0B0')
    );
    foreach ($words as $word) {
        if (mb_strpos($norm, cpms_project_unit_price_label_normalize($word), 0, 'UTF-8') !== false) return true;
    }
    return false;
}}

if (!function_exists('cpms_project_unit_price_standard_first_value')) {
function cpms_project_unit_price_standard_first_value($matrix, $rowNumber, $maxCol, $skipCols) {
    if (!is_array($skipCols)) $skipCols = array();
    $c = 1;
    while ($c <= (int)$maxCol) {
        if (isset($skipCols[$c])) {
            $c++;
            continue;
        }
        $value = isset($matrix[$rowNumber][$c]) ? trim((string)$matrix[$rowNumber][$c]) : '';
        if ($value !== '') return $value;
        $c++;
    }
    return '';
}}

if (!function_exists('cpms_project_unit_price_standard_group_name')) {
function cpms_project_unit_price_standard_group_name($value) {
    $value = trim((string)$value);
    $value = preg_replace('/^[0-9]+[\.\)\-]\s*/u', '', $value);
    return trim((string)$value);
}}

if (!function_exists('cpms_project_unit_price_standard_yellow_count')) {
function cpms_project_unit_price_standard_yellow_count($yellowCells, $rowNumber) {
    if (!is_array($yellowCells) || !isset($yellowCells[$rowNumber]) || !is_array($yellowCells[$rowNumber])) return 0;
    return count($yellowCells[$rowNumber]);
}}

if (!function_exists('cpms_project_unit_price_fingerprint')) {
function cpms_project_unit_price_fingerprint($row) {
    if (!is_array($row)) return '';
    $values = array();
    foreach (array('trade_group', 'sub_trade', 'location_name', 'item_name', 'spec', 'unit') as $field) {
        $value = '';
        if (isset($row[$field])) $value = $row[$field];
        if ($field === 'trade_group' && $value === '' && isset($row['work_group'])) $value = $row['work_group'];
        if ($field === 'sub_trade' && $value === '' && isset($row['sub_work_group'])) $value = $row['sub_work_group'];
        array_push($values, cpms_project_unit_price_label_normalize($value));
    }
    $joined = implode('|', $values);
    if (trim(str_replace('|', '', $joined)) === '') return '';
    return sha1($joined);
}}

if (!function_exists('cpms_project_unit_price_standard_extract_rows')) {
function cpms_project_unit_price_standard_extract_rows($matrix, $yellowCells, $maxRow, $maxCol, $detected) {
    $rows = array();
    $columns = isset($detected['columns']) ? $detected['columns'] : array();
    $dataStartRow = isset($detected['data_start_row']) ? (int)$detected['data_start_row'] : 0;
    if ($dataStartRow <= 0) return $rows;

    $currentLocation = '';
    $currentTradeGroup = '';
    $blankCount = 0;
    $importOrder = 0;
    $rowNumber = $dataStartRow;
    while ($rowNumber <= (int)$maxRow) {
        $rowType = cpms_project_unit_price_standard_cell($matrix, $rowNumber, $columns, 'row_type');
        $tradeGroup = cpms_project_unit_price_standard_cell($matrix, $rowNumber, $columns, 'trade_group');
        $subTrade = cpms_project_unit_price_standard_cell($matrix, $rowNumber, $columns, 'sub_trade');
        $locationName = cpms_project_unit_price_standard_cell($matrix, $rowNumber, $columns, 'location_name');
        $itemName = cpms_project_unit_price_standard_cell($matrix, $rowNumber, $columns, 'item_name');
        $spec = cpms_project_unit_price_standard_cell($matrix, $rowNumber, $columns, 'spec');
        $unit = cpms_project_unit_price_standard_cell($matrix, $rowNumber, $columns, 'unit');
        $qtyRaw = cpms_project_unit_price_standard_cell($matrix, $rowNumber, $columns, 'qty');
        $materialRaw = cpms_project_unit_price_standard_cell($matrix, $rowNumber, $columns, 'material_unit_price');
        $laborRaw = cpms_project_unit_price_standard_cell($matrix, $rowNumber, $columns, 'labor_unit_price');
        $expenseRaw = cpms_project_unit_price_standard_cell($matrix, $rowNumber, $columns, 'expense_unit_price');
        $totalRaw = cpms_project_unit_price_standard_cell($matrix, $rowNumber, $columns, 'unit_price');
        $amountRaw = cpms_project_unit_price_standard_cell($matrix, $rowNumber, $columns, 'amount');
        $remark = cpms_project_unit_price_standard_cell($matrix, $rowNumber, $columns, 'remark');
        $rowText = cpms_project_unit_price_standard_row_text($matrix, $rowNumber, $maxCol);

        if (trim($rowText) === '') {
            $blankCount++;
            if ($blankCount >= 50 && count($rows) > 0) break;
            $rowNumber++;
            continue;
        }
        $blankCount = 0;

        $rowTypeNorm = cpms_project_unit_price_label_normalize($rowType);
        $locationNorm = cpms_project_unit_price_label_normalize(cpms_project_unit_price_standard_text('location_type'));
        $workNorm = cpms_project_unit_price_label_normalize(cpms_project_unit_price_standard_text('work_type'));
        $detailNorm = cpms_project_unit_price_label_normalize(cpms_project_unit_price_standard_text('detail_type'));
        $isLocationRowType = ($rowTypeNorm === $locationNorm || ($rowTypeNorm !== '' && $locationNorm !== '' && mb_strpos($rowTypeNorm, $locationNorm, 0, 'UTF-8') !== false));
        $isWorkRowType = ($rowTypeNorm === $workNorm || ($rowTypeNorm !== '' && $workNorm !== '' && mb_strpos($rowTypeNorm, $workNorm, 0, 'UTF-8') !== false));
        $itemYellow = (isset($columns['item_name']) && isset($yellowCells[$rowNumber]) && isset($yellowCells[$rowNumber][(int)$columns['item_name']]));
        $rowYellow = (cpms_project_unit_price_standard_yellow_count($yellowCells, $rowNumber) >= 2);

        if ($isLocationRowType || $itemYellow || $rowYellow) {
            $skipCols = array();
            if (isset($columns['row_type'])) $skipCols[(int)$columns['row_type']] = true;
            $newLocation = ($locationName !== '') ? $locationName : (($itemName !== '') ? $itemName : cpms_project_unit_price_standard_first_value($matrix, $rowNumber, $maxCol, $skipCols));
            if (trim((string)$newLocation) !== '') $currentLocation = trim((string)$newLocation);
            $rowNumber++;
            continue;
        }

        if ($isWorkRowType || ($itemName !== '' && $qtyRaw === '' && $unit === '' && cpms_project_unit_price_standard_group_name($itemName) !== $itemName)) {
            $newGroup = ($tradeGroup !== '') ? $tradeGroup : (($itemName !== '') ? cpms_project_unit_price_standard_group_name($itemName) : '');
            if ($newGroup !== '') $currentTradeGroup = $newGroup;
            $rowNumber++;
            continue;
        }

        if (cpms_project_unit_price_standard_is_total_row($rowText)) {
            $rowNumber++;
            continue;
        }

        if ($tradeGroup === '' && $currentTradeGroup !== '') $tradeGroup = $currentTradeGroup;
        if ($locationName === '' && $currentLocation !== '') $locationName = $currentLocation;

        if ($itemName === '' && trim((string)$qtyRaw) === '') {
            if ($tradeGroup !== '' && $unit === '') $currentTradeGroup = $tradeGroup;
            $rowNumber++;
            continue;
        }

        $qty = cpms_project_unit_price_number_parse($qtyRaw);
        $materialParsed = cpms_project_unit_price_number_parse($materialRaw);
        $laborParsed = cpms_project_unit_price_number_parse($laborRaw);
        $expenseParsed = cpms_project_unit_price_number_parse($expenseRaw);
        $unitPriceParsed = cpms_project_unit_price_number_parse($totalRaw);
        $amount = cpms_project_unit_price_number_parse($amountRaw);
        $materialUnitPrice = ($materialParsed === null) ? 0.0 : $materialParsed;
        $laborUnitPrice = ($laborParsed === null) ? 0.0 : $laborParsed;
        $expenseUnitPrice = ($expenseParsed === null) ? 0.0 : $expenseParsed;
        $calculatedUnitPrice = $materialUnitPrice + $laborUnitPrice + $expenseUnitPrice;
        $priceCalculated = false;
        if ($unitPriceParsed === null && ($materialParsed !== null || $laborParsed !== null || $expenseParsed !== null)) {
            $unitPriceParsed = $calculatedUnitPrice;
            $priceCalculated = true;
        }
        $amountCalculated = false;
        if ($amount === null && $qty !== null && $unitPriceParsed !== null) {
            $amount = (float)$qty * (float)$unitPriceParsed;
            $amountCalculated = true;
        }

        $status = cpms_project_unit_price_standard_text('normal');
        if ($itemName === '') $status = cpms_project_unit_price_standard_text('missing_item');
        else if ($qty === null) $status = cpms_project_unit_price_standard_text('missing_qty');
        else if ($unitPriceParsed === null) $status = cpms_project_unit_price_standard_text('missing_price');
        else if ($priceCalculated) $status = cpms_project_unit_price_standard_text('calculated_price');
        else if ($amountCalculated) $status = cpms_project_unit_price_standard_text('calculated_amount');

        $isSafety = 0;
        if (mb_strpos($itemName, cpms_project_unit_price_lang('safety'), 0, 'UTF-8') !== false || mb_strpos($spec, cpms_project_unit_price_lang('safety'), 0, 'UTF-8') !== false) $isSafety = 1;

        $importOrder++;
        $rowData = array(
            'row_type' => $rowType,
            'trade_group' => $tradeGroup,
            'sub_trade' => $subTrade,
            'location_name' => $locationName,
            'work_group' => $tradeGroup,
            'sub_work_group' => $subTrade,
            'item_name' => $itemName,
            'original_item_name' => $itemName,
            'spec' => $spec,
            'unit' => $unit,
            'qty' => $qty,
            'material_unit_price' => $materialUnitPrice,
            'labor_unit_price' => $laborUnitPrice,
            'expense_unit_price' => $expenseUnitPrice,
            'unit_price' => $unitPriceParsed,
            'total_unit_price' => $unitPriceParsed,
            'calculated_unit_price' => $calculatedUnitPrice,
            'excel_unit_price_total' => cpms_project_unit_price_number_parse($totalRaw),
            'amount' => $amount,
            'is_safety' => $isSafety,
            'remark' => $remark,
            'source_row' => $rowNumber,
            'source_row_no' => $rowNumber,
            'source_sheet_name' => isset($detected['sheet_name']) ? (string)$detected['sheet_name'] : '',
            'import_order' => $importOrder,
            'unit_price_validation' => $priceCalculated ? 'calculated' : 'ok',
            'unit_price_validation_text' => $status,
            'preview_status' => $status,
            'is_importable' => ($itemName !== '' && $qty !== null && $unitPriceParsed !== null) ? 1 : 0
        );
        $rowData['item_fingerprint'] = cpms_project_unit_price_fingerprint($rowData);
        array_push($rows, $rowData);
        $rowNumber++;
    }
    return $rows;
}}

if (!function_exists('cpms_project_parse_standard_unit_price_from_zip')) {
function cpms_project_parse_standard_unit_price_from_zip($zip, $sharedStrings, $sheets) {
    $result = array('ok'=>false, 'header_found'=>false, 'rows'=>array(), 'message'=>cpms_project_unit_price_standard_text('not_standard'), 'detected_columns'=>array(), 'sheet_name'=>'', 'header_end_row'=>0, 'data_start_row'=>0, 'debug'=>array(), 'format_type'=>'standard');
    if (!is_array($sheets) || count($sheets) === 0) return $result;

    $standardName = cpms_project_unit_price_standard_text('sheet_name');
    $orderedSheets = array();
    foreach ($sheets as $sheet) {
        $name = isset($sheet['name']) ? (string)$sheet['name'] : '';
        if ($name === $standardName) array_push($orderedSheets, $sheet);
    }
    foreach ($sheets as $sheet) {
        $name = isset($sheet['name']) ? (string)$sheet['name'] : '';
        if ($name !== $standardName) array_push($orderedSheets, $sheet);
    }

    $bestMissing = array();
    foreach ($orderedSheets as $sheet) {
        $sheetName = isset($sheet['name']) ? (string)$sheet['name'] : '';
        $sheetPath = isset($sheet['path']) ? (string)$sheet['path'] : '';
        if ($sheetPath === '') continue;
        $loaded = cpms_project_unit_price_sheet_matrix($zip, $sheetPath, $sharedStrings, 5000, 180);
        if (empty($loaded['ok'])) continue;

        $header = cpms_project_unit_price_standard_header($loaded['matrix'], (int)$loaded['max_row'], (int)$loaded['max_col']);
        if (empty($header['ok'])) {
            if (isset($header['found']) && (int)$header['found'] > 0) $bestMissing = isset($header['missing']) ? $header['missing'] : array();
            continue;
        }

        $detected = array(
            'columns' => isset($header['columns']) ? $header['columns'] : array(),
            'sheet_name' => $sheetName,
            'header_end_row' => (int)$header['row'],
            'data_start_row' => ((int)$header['row']) + 1
        );
        $rows = cpms_project_unit_price_standard_extract_rows($loaded['matrix'], isset($loaded['yellow_cells']) ? $loaded['yellow_cells'] : array(), (int)$loaded['max_row'], (int)$loaded['max_col'], $detected);
        $result['header_found'] = true;
        if (count($rows) === 0) {
            $result['message'] = cpms_project_unit_price_standard_text('no_rows');
            return $result;
        }

        $detectedColumns = array();
        foreach ($detected['columns'] as $field => $col) {
            $detectedColumns[$field] = cpms_project_unit_price_col_to_letter((int)$col) . cpms_project_unit_price_lang('column_suffix');
        }
        $debug = array('sheet_name'=>$sheetName, 'first_rows'=>array_slice($rows, 0, 10));
        if ($sheetName !== $standardName) $debug['notice'] = cpms_project_unit_price_standard_text('sheet_fallback');

        $result['ok'] = true;
        $result['rows'] = $rows;
        $result['message'] = '';
        $result['detected_columns'] = $detectedColumns;
        $result['sheet_name'] = $sheetName;
        $result['header_end_row'] = (int)$detected['header_end_row'];
        $result['data_start_row'] = (int)$detected['data_start_row'];
        $result['debug'] = $debug;
        return $result;
    }

    if (count($bestMissing) > 0) {
        $labels = cpms_project_unit_price_standard_labels();
        $firstMissing = isset($bestMissing[0]) ? (string)$bestMissing[0] : '';
        $label = isset($labels[$firstMissing]) ? $labels[$firstMissing] : $firstMissing;
        $result['message'] = cpms_project_unit_price_standard_text('missing_column_prefix') . $label . cpms_project_unit_price_standard_text('missing_column_suffix');
    }
    return $result;
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

    $standard = cpms_project_parse_standard_unit_price_from_zip($zip, $sharedStrings, $sheets);
    if (is_array($standard) && !empty($standard['ok'])) {
        $zip->close();
        return $standard;
    }
    if (is_array($standard) && !empty($standard['header_found'])) {
        $zip->close();
        $result['message'] = isset($standard['message']) ? $standard['message'] : cpms_project_unit_price_standard_text('no_rows');
        return $result;
    }

    $best = null;
    $bestScore = -1;
    $bestMessage = isset($standard['message']) ? $standard['message'] : cpms_project_unit_price_lang('msg_header_not_found');

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
    $result['format_type'] = 'legacy';
    return $result;
}}
