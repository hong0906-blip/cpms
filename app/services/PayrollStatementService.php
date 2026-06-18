<?php
/**
 * Payroll statement rendering/PDF/Drive helpers.
 * PHP 5.6 compatible.
 */

require_once __DIR__ . '/CompanyPayrollService.php';
require_once __DIR__ . '/CompanyOverheadDriveService.php';
require_once __DIR__ . '/ApprovalPdfService.php';

if (!function_exists('cpms_payroll_statement_h')) {
function cpms_payroll_statement_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}}

if (!function_exists('cpms_payroll_statement_money')) {
function cpms_payroll_statement_money($value) {
    $value = (float)$value;
    return number_format($value);
}}

if (!function_exists('cpms_payroll_statement_root')) {
function cpms_payroll_statement_root() {
    return cpms_company_payroll_data_root() . '/payroll_statements';
}}

if (!function_exists('cpms_payroll_statement_logs_root')) {
function cpms_payroll_statement_logs_root() {
    return cpms_company_payroll_data_root() . '/payroll_statement_logs';
}}

if (!function_exists('cpms_payroll_statement_tmp_root')) {
function cpms_payroll_statement_tmp_root() {
    return cpms_drive_storage_root() . '/tmp/payroll_statements';
}}

if (!function_exists('cpms_payroll_statement_template_root')) {
function cpms_payroll_statement_template_root() {
    return cpms_company_payroll_data_root() . '/payroll_statement_template';
}}

if (!function_exists('cpms_payroll_statement_template_file')) {
function cpms_payroll_statement_template_file() {
    return cpms_payroll_statement_template_root() . '/current.xlsx';
}}

if (!function_exists('cpms_payroll_statement_template_meta_file')) {
function cpms_payroll_statement_template_meta_file() {
    return cpms_payroll_statement_template_root() . '/meta.json';
}}

if (!function_exists('cpms_payroll_statement_ensure_dir')) {
function cpms_payroll_statement_ensure_dir($dir) {
    if (function_exists('cpms_ensure_dir')) return cpms_ensure_dir($dir);
    if (is_dir($dir)) return true;
    return @mkdir($dir, 0777, true);
}}

if (!function_exists('cpms_payroll_statement_template_load_meta')) {
function cpms_payroll_statement_template_load_meta() {
    return cpms_company_payroll_read_json(cpms_payroll_statement_template_meta_file());
}}

if (!function_exists('cpms_payroll_statement_template_backup_current')) {
function cpms_payroll_statement_template_backup_current() {
    $root = cpms_payroll_statement_template_root();
    $current = cpms_payroll_statement_template_file();
    $metaFile = cpms_payroll_statement_template_meta_file();
    if (!is_file($current) && !is_file($metaFile)) return true;
    $history = rtrim($root, '/\\') . '/history';
    if (!cpms_payroll_statement_ensure_dir($history)) return false;
    $stamp = date('Ymd_His');
    if (is_file($current) && !@copy($current, $history . '/' . $stamp . '_statement_template.xlsx')) return false;
    if (is_file($metaFile)) @copy($metaFile, $history . '/' . $stamp . '_statement_template_meta.json');
    return true;
}}

if (!function_exists('cpms_payroll_statement_file')) {
function cpms_payroll_statement_file($year, $month) {
    $ym = cpms_company_payroll_normalize_year_month($year, $month);
    return cpms_payroll_statement_root() . '/' . $ym['year'] . '/' . $ym['month'] . '.json';
}}

if (!function_exists('cpms_payroll_statement_history_dir')) {
function cpms_payroll_statement_history_dir($year, $month) {
    $ym = cpms_company_payroll_normalize_year_month($year, $month);
    return cpms_payroll_statement_root() . '/' . $ym['year'] . '/' . $ym['month'] . '_history';
}}

if (!function_exists('cpms_payroll_statement_log_file')) {
function cpms_payroll_statement_log_file($year, $month) {
    $ym = cpms_company_payroll_normalize_year_month($year, $month);
    return cpms_payroll_statement_logs_root() . '/' . $ym['year'] . '/' . $ym['month'] . '.json';
}}

if (!function_exists('cpms_payroll_statement_load_month')) {
function cpms_payroll_statement_load_month($year, $month) {
    return cpms_company_payroll_read_json(cpms_payroll_statement_file($year, $month));
}}

if (!function_exists('cpms_payroll_statement_write_month')) {
function cpms_payroll_statement_write_month($year, $month, $data) {
    return cpms_company_payroll_write_json(cpms_payroll_statement_file($year, $month), $data);
}}

if (!function_exists('cpms_payroll_statement_backup_existing')) {
function cpms_payroll_statement_backup_existing($year, $month) {
    $old = cpms_payroll_statement_load_month($year, $month);
    if (!is_array($old)) return true;
    $dir = cpms_payroll_statement_history_dir($year, $month);
    if (!cpms_payroll_statement_ensure_dir($dir)) return false;
    $backup = rtrim($dir, '/\\') . '/' . date('Ymd_His') . '_payroll_statements.json';
    return cpms_company_payroll_write_json($backup, $old);
}}

if (!function_exists('cpms_payroll_statement_append_log')) {
function cpms_payroll_statement_append_log($year, $month, $entry) {
    $path = cpms_payroll_statement_log_file($year, $month);
    $logs = cpms_company_payroll_read_json($path);
    if (!is_array($logs)) $logs = array();
    if (!is_array($entry)) $entry = array();
    if (isset($entry['error_summary'])) $entry['error_summary'] = cpms_drive_redact_text((string)$entry['error_summary']);
    $logs[] = $entry;
    return cpms_company_payroll_write_json($path, $logs);
}}

if (!function_exists('cpms_payroll_statement_payment_date')) {
function cpms_payroll_statement_payment_date($year, $month, $employee) {
    if (is_array($employee) && isset($employee['payment_date']) && trim((string)$employee['payment_date']) !== '') {
        return cpms_company_payroll_normalize_date($employee['payment_date']);
    }
    return sprintf('%04d-%02d-15', (int)$year, (int)$month);
}}

if (!function_exists('cpms_payroll_statement_data_from_employee')) {
function cpms_payroll_statement_data_from_employee($year, $month, $effectiveYear, $effectiveMonth, $version, $employee) {
    $ym = cpms_company_payroll_normalize_year_month($year, $month);
    $publicEmployee = cpms_company_payroll_public_employee($employee, true);
    return array(
        'ok' => true,
        'year' => $ym['year'],
        'month' => $ym['month'],
        'effective_year' => (string)$effectiveYear,
        'effective_month' => (string)$effectiveMonth,
        'payroll_version_year' => (string)$effectiveYear,
        'payroll_version_month' => (string)$effectiveMonth,
        'company_name' => '주식회사 창명건설',
        'payment_date' => cpms_payroll_statement_payment_date($ym['year'], $ym['month'], $publicEmployee),
        'version' => cpms_company_payroll_public_version($version),
        'employee' => $publicEmployee,
    );
}}

if (!function_exists('cpms_payroll_statement_data')) {
function cpms_payroll_statement_data($year, $month, $employeeKey) {
    $effective = cpms_company_payroll_effective_version($year, $month);
    if (empty($effective['ok']) || !isset($effective['version']) || !is_array($effective['version'])) {
        return array('ok' => false, 'message' => '적용 중인 급여 기준월 버전이 없습니다.');
    }
    $employee = cpms_company_payroll_find_employee_in_version($effective['version'], $employeeKey);
    if (!is_array($employee)) return array('ok' => false, 'message' => '직원 급여 데이터를 찾지 못했습니다.');
    return cpms_payroll_statement_data_from_employee($year, $month, $effective['effective_year'], $effective['effective_month'], $effective['version'], $employee);
}}

if (!function_exists('cpms_payroll_statement_pay_items')) {
function cpms_payroll_statement_pay_items() {
    return array(
        array('label' => '기본급', 'key' => 'base_pay'),
        array('label' => '연장수당', 'key' => 'overtime_pay'),
        array('label' => '연차수당', 'key' => 'annual_leave_pay'),
        array('label' => '식대', 'key' => 'meal_allowance'),
        array('label' => '차량유지비', 'key' => 'vehicle_allowance'),
        array('label' => '육아수당', 'key' => 'childcare_allowance'),
        array('label' => '연구수당', 'key' => 'research_allowance'),
        array('label' => '직책수당', 'key' => 'position_allowance'),
        array('label' => '결근', 'key' => 'absence_deduction'),
        array('label' => '연차수당', 'key' => 'annual_leave_pay_2'),
        array('label' => '선급급여', 'key' => 'advance_pay')
    );
}}

if (!function_exists('cpms_payroll_statement_deduct_items')) {
function cpms_payroll_statement_deduct_items() {
    return array(
        array('label' => '소득세', 'key' => 'income_tax'),
        array('label' => '지방소득세', 'key' => 'local_income_tax'),
        array('label' => '고용보험', 'key' => 'employment_insurance'),
        array('label' => '국민연금', 'key' => 'national_pension'),
        array('label' => '건강보험', 'key' => 'health_insurance'),
        array('label' => '노인장기요양', 'key' => 'long_term_care'),
        array('label' => '소득세정산', 'key' => 'income_tax_adjustment'),
        array('label' => '지방세정산', 'key' => 'local_tax_adjustment'),
        array('label' => '건강보험 정산', 'key' => 'health_insurance_adjustment'),
        array('label' => '장기요양 정산', 'key' => 'long_term_care_adjustment'),
        array('label' => '기타공제', 'key' => 'other_deduction')
    );
}}

if (!function_exists('cpms_payroll_statement_value')) {
function cpms_payroll_statement_value($employee, $key, $default) {
    if (is_array($employee) && isset($employee[$key])) return $employee[$key];
    return $default;
}}

if (!function_exists('cpms_payroll_statement_is_zero_amount')) {
function cpms_payroll_statement_is_zero_amount($value) {
    if (is_int($value) || is_float($value)) return ((float)$value == 0.0);
    $text = trim((string)$value);
    if ($text === '') return true;
    $text = str_replace(array(',', ' ', "\t", "\r", "\n"), '', $text);
    if ($text === '' || $text === '-' || $text === '0' || $text === '0.0') return true;
    if (is_numeric($text)) return ((float)$text == 0.0);
    return false;
}}

if (!function_exists('cpms_payroll_statement_template_color_css')) {
function cpms_payroll_statement_template_color_css($value) {
    $value = strtoupper(preg_replace('/[^0-9A-F]/', '', (string)$value));
    if (strlen($value) >= 6) $value = substr($value, -6);
    if (strlen($value) !== 6) return '';
    return '#' . $value;
}}

if (!function_exists('cpms_payroll_statement_template_border_css')) {
function cpms_payroll_statement_template_border_css($sideNode, $sideName) {
    if (!$sideNode || !isset($sideNode['style']) || trim((string)$sideNode['style']) === '') return '';
    $width = ((string)$sideNode['style'] === 'medium' || (string)$sideNode['style'] === 'thick') ? '2px' : '1px';
    $color = '#111111';
    if (isset($sideNode->color)) {
        $attrs = $sideNode->color->attributes();
        if (isset($attrs['rgb'])) {
            $parsed = cpms_payroll_statement_template_color_css((string)$attrs['rgb']);
            if ($parsed !== '') $color = $parsed;
        }
    }
    return 'border-' . $sideName . ':' . $width . ' solid ' . $color . ';';
}}

if (!function_exists('cpms_payroll_statement_template_parse_styles')) {
function cpms_payroll_statement_template_parse_styles($zip) {
    $styles = array();
    $xml = $zip->getFromName('xl/styles.xml');
    if ($xml === false) return $styles;
    $sx = @simplexml_load_string($xml);
    if (!$sx) return $styles;

    $fonts = array();
    if (isset($sx->fonts->font)) {
        foreach ($sx->fonts->font as $font) {
            $item = array('bold' => false, 'size' => '', 'color' => '');
            if (isset($font->b)) $item['bold'] = true;
            if (isset($font->sz)) {
                $attrs = $font->sz->attributes();
                if (isset($attrs['val'])) $item['size'] = (string)$attrs['val'];
            }
            if (isset($font->color)) {
                $attrs2 = $font->color->attributes();
                if (isset($attrs2['rgb'])) $item['color'] = cpms_payroll_statement_template_color_css((string)$attrs2['rgb']);
            }
            $fonts[] = $item;
        }
    }

    $fills = array();
    if (isset($sx->fills->fill)) {
        foreach ($sx->fills->fill as $fill) {
            $color = '';
            if (isset($fill->patternFill->fgColor)) {
                $attrs3 = $fill->patternFill->fgColor->attributes();
                if (isset($attrs3['rgb'])) $color = cpms_payroll_statement_template_color_css((string)$attrs3['rgb']);
            }
            $fills[] = $color;
        }
    }

    $borders = array();
    if (isset($sx->borders->border)) {
        foreach ($sx->borders->border as $border) {
            $css = '';
            $css .= cpms_payroll_statement_template_border_css(isset($border->left) ? $border->left : null, 'left');
            $css .= cpms_payroll_statement_template_border_css(isset($border->right) ? $border->right : null, 'right');
            $css .= cpms_payroll_statement_template_border_css(isset($border->top) ? $border->top : null, 'top');
            $css .= cpms_payroll_statement_template_border_css(isset($border->bottom) ? $border->bottom : null, 'bottom');
            $borders[] = $css;
        }
    }

    if (isset($sx->cellXfs->xf)) {
        $idx = 0;
        foreach ($sx->cellXfs->xf as $xf) {
            $css2 = '';
            $fontId = isset($xf['fontId']) ? (int)$xf['fontId'] : -1;
            if ($fontId >= 0 && isset($fonts[$fontId])) {
                if (!empty($fonts[$fontId]['bold'])) $css2 .= 'font-weight:bold;';
                if ($fonts[$fontId]['size'] !== '') $css2 .= 'font-size:' . max(8, min(22, (float)$fonts[$fontId]['size'])) . 'pt;';
                if ($fonts[$fontId]['color'] !== '') $css2 .= 'color:' . $fonts[$fontId]['color'] . ';';
            }
            $fillId = isset($xf['fillId']) ? (int)$xf['fillId'] : -1;
            if ($fillId > 1 && isset($fills[$fillId]) && $fills[$fillId] !== '') $css2 .= 'background-color:' . $fills[$fillId] . ';';
            $borderId = isset($xf['borderId']) ? (int)$xf['borderId'] : -1;
            if ($borderId >= 0 && isset($borders[$borderId])) $css2 .= $borders[$borderId];
            if (isset($xf->alignment)) {
                $align = $xf->alignment;
                if (isset($align['horizontal']) && trim((string)$align['horizontal']) !== '') {
                    $h = (string)$align['horizontal'];
                    if ($h === 'center' || $h === 'right' || $h === 'left') $css2 .= 'text-align:' . $h . ';';
                }
                if (isset($align['vertical']) && trim((string)$align['vertical']) !== '') {
                    $v = (string)$align['vertical'];
                    if ($v === 'center') $v = 'middle';
                    if ($v === 'top' || $v === 'middle' || $v === 'bottom') $css2 .= 'vertical-align:' . $v . ';';
                }
                if (isset($align['wrapText']) && (string)$align['wrapText'] === '1') $css2 .= 'white-space:normal;';
            }
            $styles[$idx] = $css2;
            $idx++;
        }
    }
    return $styles;
}}

if (!function_exists('cpms_payroll_statement_template_sheet_info')) {
function cpms_payroll_statement_template_sheet_info($zip) {
    $fallback = array('name' => '', 'path' => 'xl/worksheets/sheet1.xml');
    $workbookXml = $zip->getFromName('xl/workbook.xml');
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($workbookXml === false || $relsXml === false) return $fallback;
    $workbook = @simplexml_load_string($workbookXml);
    $rels = @simplexml_load_string($relsXml);
    if (!$workbook || !$rels || !isset($workbook->sheets->sheet)) return $fallback;
    $relMap = array();
    foreach ($rels->Relationship as $rel) {
        $id = (string)$rel['Id'];
        $target = (string)$rel['Target'];
        if ($id !== '' && $target !== '') {
            if (strpos($target, '/') === 0) $path = ltrim($target, '/');
            else $path = 'xl/' . ltrim($target, '/');
            $relMap[$id] = $path;
        }
    }
    $first = null;
    $chosen = null;
    foreach ($workbook->sheets->sheet as $sheet) {
        if ($first === null) $first = $sheet;
        $name = (string)$sheet['name'];
        if ($name === '급여명세서' || strpos($name, '급여명세서') !== false) {
            $chosen = $sheet;
            break;
        }
    }
    if ($chosen === null) $chosen = $first;
    if ($chosen === null) return $fallback;
    $attrs = $chosen->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
    $rid = isset($attrs['id']) ? (string)$attrs['id'] : '';
    return array(
        'name' => (string)$chosen['name'],
        'path' => ($rid !== '' && isset($relMap[$rid])) ? $relMap[$rid] : $fallback['path']
    );
}}

if (!function_exists('cpms_payroll_statement_template_ref_to_pos')) {
function cpms_payroll_statement_template_ref_to_pos($ref) {
    if (!preg_match('/^([A-Z]+)([0-9]+)$/i', (string)$ref, $m)) return array(0, 0);
    return array((int)$m[2], cpms_company_payroll_col_ref_to_index($m[1]));
}}

if (!function_exists('cpms_payroll_statement_template_dimension')) {
function cpms_payroll_statement_template_dimension($ref) {
    $maxRow = 1;
    $maxCol = 1;
    $parts = explode(':', (string)$ref);
    $last = count($parts) > 1 ? $parts[count($parts) - 1] : $parts[0];
    $pos = cpms_payroll_statement_template_ref_to_pos($last);
    if ($pos[0] > 0) $maxRow = $pos[0];
    if ($pos[1] > 0) $maxCol = $pos[1];
    return array($maxRow, $maxCol);
}}

if (!function_exists('cpms_payroll_statement_parse_template_file')) {
function cpms_payroll_statement_parse_template_file($path) {
    $result = array('ok' => false, 'message' => '', 'sheet_name' => '', 'cells' => array(), 'merges' => array(), 'covered' => array(), 'styles' => array(), 'col_widths' => array(), 'row_heights' => array(), 'max_row' => 1, 'max_col' => 1);
    if (!is_file($path)) {
        $result['message'] = '급여명세서 양식 파일을 찾지 못했습니다.';
        return $result;
    }
    if (!class_exists('ZipArchive')) {
        $result['message'] = '서버에 ZipArchive 확장 모듈이 없습니다.';
        return $result;
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        $result['message'] = '급여명세서 양식 XLSX를 열지 못했습니다.';
        return $result;
    }
    $shared = cpms_company_payroll_xlsx_read_shared_strings($zip);
    $sheetInfo = cpms_payroll_statement_template_sheet_info($zip);
    $sheetXml = $zip->getFromName($sheetInfo['path']);
    if ($sheetXml === false) $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetXml === false) {
        $zip->close();
        $result['message'] = '급여명세서 양식 시트를 찾지 못했습니다.';
        return $result;
    }
    $sheet = @simplexml_load_string($sheetXml);
    if (!$sheet || !isset($sheet->sheetData)) {
        $zip->close();
        $result['message'] = '급여명세서 양식 시트를 읽지 못했습니다.';
        return $result;
    }
    $styles = cpms_payroll_statement_template_parse_styles($zip);
    $maxRow = 1;
    $maxCol = 1;
    if (isset($sheet->dimension) && isset($sheet->dimension['ref'])) {
        $dim = cpms_payroll_statement_template_dimension((string)$sheet->dimension['ref']);
        $maxRow = max($maxRow, $dim[0]);
        $maxCol = max($maxCol, $dim[1]);
    }
    $colWidths = array();
    if (isset($sheet->cols->col)) {
        foreach ($sheet->cols->col as $colNode) {
            $min = isset($colNode['min']) ? (int)$colNode['min'] : 1;
            $max = isset($colNode['max']) ? (int)$colNode['max'] : $min;
            $width = isset($colNode['width']) ? (float)$colNode['width'] : 8.43;
            $px = (int)round(max(18, min(160, $width * 7)));
            for ($cc = $min; $cc <= $max; $cc++) $colWidths[$cc] = $px;
            if ($max > $maxCol) $maxCol = $max;
        }
    }
    $cells = array();
    $rowHeights = array();
    foreach ($sheet->sheetData->row as $rowNode) {
        $r = isset($rowNode['r']) ? (int)$rowNode['r'] : 0;
        if ($r <= 0) continue;
        if ($r > $maxRow) $maxRow = $r;
        if (isset($rowNode['ht'])) $rowHeights[$r] = (int)round(max(14, min(70, (float)$rowNode['ht'] * 1.33)));
        foreach ($rowNode->c as $cell) {
            $ref = isset($cell['r']) ? (string)$cell['r'] : '';
            $pos = cpms_payroll_statement_template_ref_to_pos($ref);
            if ($pos[0] <= 0 || $pos[1] <= 0) continue;
            if ($pos[0] > $maxRow) $maxRow = $pos[0];
            if ($pos[1] > $maxCol) $maxCol = $pos[1];
            if (!isset($cells[$pos[0]])) $cells[$pos[0]] = array();
            $cells[$pos[0]][$pos[1]] = array(
                'value' => cpms_company_payroll_xlsx_cell_value($cell, $shared),
                'style' => isset($cell['s']) ? (int)$cell['s'] : 0
            );
        }
    }
    $merges = array();
    $covered = array();
    if (isset($sheet->mergeCells->mergeCell)) {
        foreach ($sheet->mergeCells->mergeCell as $mergeCell) {
            $ref = isset($mergeCell['ref']) ? (string)$mergeCell['ref'] : '';
            $parts2 = explode(':', $ref);
            if (count($parts2) !== 2) continue;
            $start = cpms_payroll_statement_template_ref_to_pos($parts2[0]);
            $end = cpms_payroll_statement_template_ref_to_pos($parts2[1]);
            if ($start[0] <= 0 || $start[1] <= 0 || $end[0] <= 0 || $end[1] <= 0) continue;
            $rowspan = max(1, $end[0] - $start[0] + 1);
            $colspan = max(1, $end[1] - $start[1] + 1);
            if (!isset($merges[$start[0]])) $merges[$start[0]] = array();
            $merges[$start[0]][$start[1]] = array('rowspan' => $rowspan, 'colspan' => $colspan);
            for ($rr = $start[0]; $rr <= $end[0]; $rr++) {
                if (!isset($covered[$rr])) $covered[$rr] = array();
                for ($cc2 = $start[1]; $cc2 <= $end[1]; $cc2++) {
                    if ($rr === $start[0] && $cc2 === $start[1]) continue;
                    $covered[$rr][$cc2] = true;
                }
            }
            if ($end[0] > $maxRow) $maxRow = $end[0];
            if ($end[1] > $maxCol) $maxCol = $end[1];
        }
    }
    $zip->close();
    $result['ok'] = true;
    $result['sheet_name'] = isset($sheetInfo['name']) ? (string)$sheetInfo['name'] : '';
    $result['cells'] = $cells;
    $result['merges'] = $merges;
    $result['covered'] = $covered;
    $result['styles'] = $styles;
    $result['col_widths'] = $colWidths;
    $result['row_heights'] = $rowHeights;
    $result['max_row'] = min(120, max(1, $maxRow));
    $result['max_col'] = min(40, max(1, $maxCol));
    $result['message'] = '급여명세서 양식을 읽었습니다.';
    return $result;
}}

if (!function_exists('cpms_payroll_statement_template_save_upload')) {
function cpms_payroll_statement_template_save_upload($file, $user) {
    if (!is_array($file) || !isset($file['error']) || (int)$file['error'] === UPLOAD_ERR_NO_FILE) {
        return array('ok' => false, 'message' => '업로드할 급여명세서 양식 XLSX를 선택해 주세요.');
    }
    if ((int)$file['error'] !== UPLOAD_ERR_OK) {
        return array('ok' => false, 'message' => '급여명세서 양식 업로드 오류가 발생했습니다. 코드: ' . (int)$file['error']);
    }
    $originalName = isset($file['name']) ? trim((string)$file['name']) : 'payroll_statement_template.xlsx';
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($ext !== 'xlsx') return array('ok' => false, 'message' => '.xlsx 급여명세서 양식만 업로드할 수 있습니다.');
    $tmpName = isset($file['tmp_name']) ? (string)$file['tmp_name'] : '';
    $parsed = cpms_payroll_statement_parse_template_file($tmpName);
    if (empty($parsed['ok'])) return array('ok' => false, 'message' => isset($parsed['message']) ? $parsed['message'] : '급여명세서 양식을 읽지 못했습니다.');
    $root = cpms_payroll_statement_template_root();
    if (!cpms_payroll_statement_ensure_dir($root)) return array('ok' => false, 'message' => '급여명세서 양식 저장 폴더를 만들지 못했습니다.');
    if (!cpms_payroll_statement_template_backup_current()) return array('ok' => false, 'message' => '기존 급여명세서 양식을 history로 보관하지 못했습니다.');
    $target = cpms_payroll_statement_template_file();
    $moved = false;
    if (is_uploaded_file($tmpName)) $moved = @move_uploaded_file($tmpName, $target);
    if (!$moved) $moved = @copy($tmpName, $target);
    if (!$moved) return array('ok' => false, 'message' => '급여명세서 양식을 저장하지 못했습니다.');
    $meta = array(
        'uploaded_original_name' => $originalName,
        'uploaded_at' => date('Y-m-d H:i:s'),
        'uploaded_by' => cpms_company_payroll_user_label($user),
        'sheet_name' => isset($parsed['sheet_name']) ? (string)$parsed['sheet_name'] : '',
        'max_row' => isset($parsed['max_row']) ? (int)$parsed['max_row'] : 0,
        'max_col' => isset($parsed['max_col']) ? (int)$parsed['max_col'] : 0
    );
    if (!cpms_company_payroll_write_json(cpms_payroll_statement_template_meta_file(), $meta)) {
        return array('ok' => false, 'message' => '급여명세서 양식 메타 정보를 저장하지 못했습니다.');
    }
    return array('ok' => true, 'message' => '급여명세서 양식을 저장했습니다. 기존 생성 PDF는 새 양식으로 다시 생성해 주세요.', 'meta' => $meta);
}}

if (!function_exists('cpms_payroll_statement_template_normalize_label')) {
function cpms_payroll_statement_template_normalize_label($value) {
    $value = trim((string)$value);
    $value = preg_replace('/\s+/u', '', $value);
    return $value;
}}

if (!function_exists('cpms_payroll_statement_template_set_cell')) {
function cpms_payroll_statement_template_set_cell(&$template, $row, $col, $value, $amount) {
    if ($row <= 0 || $col <= 0 || $row > $template['max_row'] || $col > $template['max_col']) return false;
    if (!isset($template['cells'][$row])) $template['cells'][$row] = array();
    if (!isset($template['cells'][$row][$col]) || !is_array($template['cells'][$row][$col])) $template['cells'][$row][$col] = array('value' => '', 'style' => 0);
    $template['cells'][$row][$col]['value'] = (string)$value;
    $template['cells'][$row][$col]['generated'] = true;
    if ($amount) $template['cells'][$row][$col]['amount'] = true;
    return true;
}}

if (!function_exists('cpms_payroll_statement_template_target_col')) {
function cpms_payroll_statement_template_target_col($template, $row, $labelCol) {
    $target = $labelCol + 1;
    if (isset($template['merges'][$row]) && isset($template['merges'][$row][$labelCol])) {
        $target = $labelCol + (int)$template['merges'][$row][$labelCol]['colspan'];
    }
    while ($target <= $template['max_col'] && isset($template['covered'][$row]) && !empty($template['covered'][$row][$target])) $target++;
    if ($target > $template['max_col']) $target = $labelCol;
    return $target;
}}

if (!function_exists('cpms_payroll_statement_template_find_header_col')) {
function cpms_payroll_statement_template_find_header_col($template, $headerText) {
    $needle = cpms_payroll_statement_template_normalize_label($headerText);
    for ($r = 1; $r <= $template['max_row']; $r++) {
        if (!isset($template['cells'][$r]) || !is_array($template['cells'][$r])) continue;
        foreach ($template['cells'][$r] as $c => $cell) {
            $value = isset($cell['value']) ? cpms_payroll_statement_template_normalize_label($cell['value']) : '';
            if ($value !== '' && strpos($value, $needle) !== false) return (int)$c;
        }
    }
    return 0;
}}

if (!function_exists('cpms_payroll_statement_template_amount_key')) {
function cpms_payroll_statement_template_amount_key($label, &$payCounters) {
    $norm = cpms_payroll_statement_template_normalize_label($label);
    if ($norm === '연차수당') {
        if (!isset($payCounters['annual_leave_pay'])) $payCounters['annual_leave_pay'] = 0;
        $payCounters['annual_leave_pay']++;
        return $payCounters['annual_leave_pay'] >= 2 ? array('pay', 'annual_leave_pay_2') : array('pay', 'annual_leave_pay');
    }
    $pay = array(
        '기본급' => 'base_pay',
        '연장수당' => 'overtime_pay',
        '식대' => 'meal_allowance',
        '차량유지비' => 'vehicle_allowance',
        '육아수당' => 'childcare_allowance',
        '연구수당' => 'research_allowance',
        '직책수당' => 'position_allowance',
        '결근' => 'absence_deduction',
        '선급급여' => 'advance_pay',
        '사원연금' => 'employee_pension'
    );
    foreach ($pay as $k => $v) {
        if ($norm === cpms_payroll_statement_template_normalize_label($k)) return array('pay', $v);
    }
    $deduct = array(
        '소득세' => 'income_tax',
        '지방소득세' => 'local_income_tax',
        '고용보험' => 'employment_insurance',
        '국민연금' => 'national_pension',
        '건강보험' => 'health_insurance',
        '노인장기요양' => 'long_term_care',
        '소득세정산' => 'income_tax_adjustment',
        '지방세정산' => 'local_tax_adjustment',
        '건강보험정산' => 'health_insurance_adjustment',
        '장기요양정산' => 'long_term_care_adjustment',
        '기타공제' => 'other_deduction'
    );
    foreach ($deduct as $k2 => $v2) {
        if ($norm === cpms_payroll_statement_template_normalize_label($k2)) return array('deduct', $v2);
    }
    return array('', '');
}}

if (!function_exists('cpms_payroll_statement_template_basic_value')) {
function cpms_payroll_statement_template_basic_value($label, $data, $employee) {
    $norm = cpms_payroll_statement_template_normalize_label($label);
    $belongMonth = $data['year'] . '년 ' . (int)$data['month'] . '월';
    $map = array(
        '사업장명' => array(isset($data['company_name']) ? $data['company_name'] : '주식회사 창명건설', 'right', false),
        '귀속연월' => array($belongMonth, 'right', false),
        '지급일' => array(isset($data['payment_date']) ? $data['payment_date'] : '', 'right', false),
        '성명' => array(cpms_payroll_statement_value($employee, 'name', ''), 'right', false),
        '생년월일' => array(cpms_payroll_statement_value($employee, 'birth_date', ''), 'right', false),
        '부서명' => array(cpms_payroll_statement_value($employee, 'department', ''), 'right', false),
        '직위' => array(cpms_payroll_statement_value($employee, 'position', ''), 'right', false),
        '직급' => array(cpms_payroll_statement_value($employee, 'position', ''), 'right', false),
        '은행명' => array(cpms_payroll_statement_value($employee, 'bank_name', ''), 'right', false),
        '계좌번호' => array(cpms_payroll_statement_value($employee, 'bank_account', ''), 'right', false),
        '연장근로시간' => array(cpms_payroll_statement_value($employee, 'overtime_hours', ''), 'below', false),
        '야간근로시간' => array(cpms_payroll_statement_value($employee, 'night_hours', ''), 'below', false),
        '휴일근로시간' => array(cpms_payroll_statement_value($employee, 'holiday_hours', ''), 'below', false),
        '통상시급' => array(cpms_payroll_statement_money(cpms_payroll_statement_value($employee, 'regular_hourly_wage', 0)), 'below', true),
        '주민번호' => array(cpms_payroll_statement_value($employee, 'resident_masked', ''), 'right', false)
    );
    foreach ($map as $k => $v) {
        if ($norm === cpms_payroll_statement_template_normalize_label($k)) return array(true, $v[0], $v[1], $v[2]);
    }
    return array(false, '', 'right', false);
}}

if (!function_exists('cpms_payroll_statement_template_prepare')) {
function cpms_payroll_statement_template_prepare($template, $data) {
    $employee = isset($data['employee']) && is_array($data['employee']) ? $data['employee'] : array();
    $template['hidden_rows'] = array();
    $payAmountCol = cpms_payroll_statement_template_find_header_col($template, '지급액');
    $deductAmountCol = cpms_payroll_statement_template_find_header_col($template, '공제액');
    $payCounters = array();
    $title = $data['year'] . '년 ' . (int)$data['month'] . '월 급여명세서';

    for ($r = 1; $r <= $template['max_row']; $r++) {
        if (!isset($template['cells'][$r]) || !is_array($template['cells'][$r])) continue;
        foreach ($template['cells'][$r] as $c => $cell) {
            $text = isset($cell['value']) ? trim((string)$cell['value']) : '';
            if ($text === '') continue;
            if (strpos($text, '급여명세서') !== false) {
                cpms_payroll_statement_template_set_cell($template, $r, (int)$c, $title, false);
                continue;
            }
            $basic = cpms_payroll_statement_template_basic_value($text, $data, $employee);
            if (!empty($basic[0])) {
                $targetRow = $r;
                $targetCol = cpms_payroll_statement_template_target_col($template, $r, (int)$c);
                if (isset($basic[2]) && $basic[2] === 'below') {
                    $targetRow = ($r + 1 <= $template['max_row']) ? $r + 1 : $r;
                    $targetCol = (int)$c;
                }
                cpms_payroll_statement_template_set_cell($template, $targetRow, $targetCol, $basic[1], !empty($basic[3]));
                continue;
            }
            $amountKey = cpms_payroll_statement_template_amount_key($text, $payCounters);
            if ($amountKey[0] !== '' && $amountKey[1] !== '') {
                $amount = cpms_payroll_statement_value($employee, $amountKey[1], 0);
                if (cpms_payroll_statement_is_zero_amount($amount)) {
                    $template['hidden_rows'][$r] = true;
                } else {
                    $targetAmountCol = $amountKey[0] === 'deduct' ? $deductAmountCol : $payAmountCol;
                    if ($targetAmountCol <= 0) $targetAmountCol = cpms_payroll_statement_template_target_col($template, $r, (int)$c);
                    cpms_payroll_statement_template_set_cell($template, $r, $targetAmountCol, cpms_payroll_statement_money($amount), true);
                }
                continue;
            }
            $norm = cpms_payroll_statement_template_normalize_label($text);
            if ($norm === '지급합계') {
                $grossCol = $payAmountCol > 0 ? $payAmountCol : cpms_payroll_statement_template_target_col($template, $r, (int)$c);
                cpms_payroll_statement_template_set_cell($template, $r, $grossCol, cpms_payroll_statement_money(cpms_payroll_statement_value($employee, 'gross_pay', 0)), true);
            } else if ($norm === '공제합계' || $norm === '공제총액') {
                $totalCol = $deductAmountCol > 0 ? $deductAmountCol : cpms_payroll_statement_template_target_col($template, $r, (int)$c);
                cpms_payroll_statement_template_set_cell($template, $r, $totalCol, cpms_payroll_statement_money(cpms_payroll_statement_value($employee, 'total_deduction', 0)), true);
            } else if ($norm === '실지급액' || $norm === '차인지급액') {
                $netCol = cpms_payroll_statement_template_target_col($template, $r, (int)$c);
                if ($netCol === (int)$c && $template['max_col'] >= 16) $netCol = 16;
                cpms_payroll_statement_template_set_cell($template, $r, $netCol, cpms_payroll_statement_money(cpms_payroll_statement_value($employee, 'net_pay', 0)), true);
            }
        }
    }
    return $template;
}}

if (!function_exists('cpms_payroll_statement_render_uploaded_template_html')) {
function cpms_payroll_statement_render_uploaded_template_html($data) {
    $path = cpms_payroll_statement_template_file();
    if (!is_file($path)) return '';
    $template = cpms_payroll_statement_parse_template_file($path);
    if (empty($template['ok'])) return '';
    $template = cpms_payroll_statement_template_prepare($template, $data);
    $totalWidth = 0;
    for ($c = 1; $c <= $template['max_col']; $c++) {
        $totalWidth += isset($template['col_widths'][$c]) ? (int)$template['col_widths'][$c] : 36;
    }
    if ($totalWidth < 620) $totalWidth = 620;
    if ($totalWidth > 820) $totalWidth = 820;
    $html = '<style>';
    $html .= 'body{font-family:"Malgun Gothic","Noto Sans CJK KR","NanumGothic",Arial,sans-serif;color:#111;margin:0;background:#fff}.payroll-template-wrap{width:' . (int)$totalWidth . 'px;margin:0 auto;padding:10px 6px;background:#fff;box-sizing:border-box}.payroll-template-table{border-collapse:collapse;table-layout:fixed;width:100%;font-size:10.5pt}.payroll-template-table td{padding:2px 5px;box-sizing:border-box;word-break:keep-all;white-space:normal;overflow:hidden;line-height:1.25}.payroll-template-table td.generated{text-align:center}.payroll-template-table td.amount{text-align:right}.payroll-template-table td.title-cell{font-size:20pt;font-weight:bold;text-align:center}.payroll-template-empty{color:transparent}@media(max-width:900px){.payroll-template-wrap{width:100%;overflow-x:auto}.payroll-template-table{font-size:9.5pt}}@media print{.actions{display:none}.payroll-template-wrap{width:auto;margin:0;padding:0}.payroll-template-table{font-size:10pt}}';
    $html .= '</style><div class="payroll-template-wrap"><table class="payroll-template-table"><colgroup>';
    for ($col = 1; $col <= $template['max_col']; $col++) {
        $w = isset($template['col_widths'][$col]) ? (int)$template['col_widths'][$col] : 36;
        $html .= '<col style="width:' . $w . 'px">';
    }
    $html .= '</colgroup><tbody>';
    for ($row = 1; $row <= $template['max_row']; $row++) {
        if (isset($template['hidden_rows'][$row])) continue;
        $height = isset($template['row_heights'][$row]) ? (int)$template['row_heights'][$row] : 20;
        $html .= '<tr style="height:' . $height . 'px">';
        for ($col2 = 1; $col2 <= $template['max_col']; $col2++) {
            if (isset($template['covered'][$row]) && !empty($template['covered'][$row][$col2])) continue;
            $cell = isset($template['cells'][$row]) && isset($template['cells'][$row][$col2]) ? $template['cells'][$row][$col2] : array('value' => '', 'style' => 0);
            $merge = isset($template['merges'][$row]) && isset($template['merges'][$row][$col2]) ? $template['merges'][$row][$col2] : array('rowspan' => 1, 'colspan' => 1);
            $value = isset($cell['value']) ? (string)$cell['value'] : '';
            $styleIdx = isset($cell['style']) ? (int)$cell['style'] : 0;
            $style = isset($template['styles'][$styleIdx]) ? $template['styles'][$styleIdx] : '';
            if ($style === '') $style = 'border:none;';
            $class = '';
            if (!empty($cell['generated'])) $class .= ' generated';
            if (!empty($cell['amount'])) $class .= ' amount';
            if (strpos($value, '급여명세서') !== false) $class .= ' title-cell';
            if ($value === '') $class .= ' payroll-template-empty';
            $html .= '<td class="' . trim($class) . '" style="' . cpms_payroll_statement_h($style) . '"';
            if ((int)$merge['rowspan'] > 1) $html .= ' rowspan="' . (int)$merge['rowspan'] . '"';
            if ((int)$merge['colspan'] > 1) $html .= ' colspan="' . (int)$merge['colspan'] . '"';
            $html .= '>' . ($value === '' ? '&nbsp;' : cpms_payroll_statement_h($value)) . '</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</tbody></table></div>';
    return $html;
}}

if (!function_exists('cpms_payroll_statement_render_html')) {
function cpms_payroll_statement_render_html($data, $printMode) {
    if (!is_array($data) || empty($data['ok']) || !isset($data['employee']) || !is_array($data['employee'])) {
        return '<div>급여명세서 데이터를 찾지 못했습니다.</div>';
    }
    $uploadedTemplateHtml = cpms_payroll_statement_render_uploaded_template_html($data);
    if ($uploadedTemplateHtml !== '') return $uploadedTemplateHtml;
    $e = $data['employee'];
    $title = $data['year'] . '년 ' . (int)$data['month'] . '월 급여명세서';
    $belongMonth = $data['year'] . '년 ' . (int)$data['month'] . '월';
    $paymentDate = isset($data['payment_date']) ? (string)$data['payment_date'] : '';
    $bankName = cpms_payroll_statement_value($e, 'bank_name', '');
    $bankAccount = cpms_payroll_statement_value($e, 'bank_account', '');
    $residentMasked = cpms_payroll_statement_value($e, 'resident_masked', '');
    $payItems = cpms_payroll_statement_pay_items();
    $deductItems = cpms_payroll_statement_deduct_items();

    $html = '';
    $html .= '<style>';
    $html .= 'body{font-family:"Malgun Gothic","Noto Sans CJK KR","NanumGothic",Arial,sans-serif;color:#111;margin:0;background:#fff}.actions{max-width:920px;margin:14px auto;display:block}.btn{display:inline-block;padding:9px 13px;border:1px solid #cbd5e1;border-radius:6px;text-decoration:none;color:#111827;font-weight:700;background:#fff}.btn-primary{background:#166534;color:#fff;border-color:#166534}.payroll-sheet{width:780px;margin:0 auto;padding:22px 18px 26px;background:#fff;box-sizing:border-box}.payroll-title{text-align:center;font-size:24px;font-weight:800;margin:0 0 22px}.info-table,.statement-table,.time-table,.final-table{width:100%;border-collapse:collapse;table-layout:fixed;font-size:12px}.info-table th,.info-table td,.statement-table th,.statement-table td,.time-table th,.time-table td,.final-table th,.final-table td{border:1px solid #333;padding:6px 8px;vertical-align:middle}.info-table th,.time-table th{background:#e8f1f8;text-align:center;font-weight:800}.info-table td,.time-table td{text-align:center}.time-table{margin-top:14px}.statement-table{margin-top:14px}.statement-table .section-row th{background:#fff;text-align:left;font-weight:800;border-left:0;border-right:0;border-top:0;padding:8px 0 5px}.statement-table .header-row th{background:#f3f4f6;text-align:center;font-weight:800}.statement-table th.item{text-align:left;font-weight:700;background:#fff}.statement-table td.amount{text-align:right}.total-row th,.total-row td{background:#eef7f7;font-weight:800}.final-table{margin-top:14px}.final-table th{background:#fff;text-align:left;font-size:15px}.final-table td{text-align:right;font-size:16px;font-weight:800}.thanks{text-align:left;font-size:13px;font-weight:700;margin-top:18px}.memo-box{border:1px solid #333;min-height:34px;padding:8px;font-size:12px}@media(max-width:900px){.payroll-sheet{width:100%;padding:14px}.payroll-title{font-size:20px}.info-table,.statement-table,.time-table,.final-table{font-size:11px}.info-table th,.info-table td,.statement-table th,.statement-table td,.time-table th,.time-table td,.final-table th,.final-table td{padding:5px 4px}}@media print{.actions{display:none}.payroll-sheet{width:auto;margin:0;padding:0}.payroll-title{margin-top:0}}';
    $html .= '</style>';
    $html .= '<div class="payroll-sheet">';
    $html .= '<h1 class="payroll-title">' . cpms_payroll_statement_h($title) . '</h1>';
    $html .= '<table class="info-table"><tbody>';
    $html .= '<tr><th colspan="4" style="background:#fff;text-align:left;">기본정보</th></tr>';
    $html .= '<tr><th style="width:22%">사업장명</th><td colspan="3">' . cpms_payroll_statement_h(isset($data['company_name']) ? $data['company_name'] : '주식회사 창명건설') . '</td></tr>';
    $html .= '<tr><th>귀속연월</th><td>' . cpms_payroll_statement_h($belongMonth) . '</td><th>지급일</th><td>' . cpms_payroll_statement_h($paymentDate) . '</td></tr>';
    $html .= '<tr><th>성명</th><td>' . cpms_payroll_statement_h(cpms_payroll_statement_value($e, 'name', '')) . '</td><th>생년월일</th><td>' . cpms_payroll_statement_h(cpms_payroll_statement_value($e, 'birth_date', '')) . '</td></tr>';
    $html .= '<tr><th>부서명</th><td>' . cpms_payroll_statement_h(cpms_payroll_statement_value($e, 'department', '')) . '</td><th>직위</th><td>' . cpms_payroll_statement_h(cpms_payroll_statement_value($e, 'position', '')) . '</td></tr>';
    $html .= '<tr><th>은행명</th><td>' . cpms_payroll_statement_h($bankName) . '</td><th>계좌번호</th><td>' . cpms_payroll_statement_h($bankAccount) . '</td></tr>';
    $html .= '<tr><th>주민번호</th><td colspan="3"><span id="statement_resident">' . cpms_payroll_statement_h($residentMasked) . '</span></td></tr>';
    $html .= '</tbody></table>';
    $html .= '<table class="time-table"><tbody><tr>';
    $html .= '<th>연장근로시간</th><td>' . cpms_payroll_statement_h(cpms_payroll_statement_value($e, 'overtime_hours', '')) . '</td>';
    $html .= '<th>야간근로시간</th><td>' . cpms_payroll_statement_h(cpms_payroll_statement_value($e, 'night_hours', '')) . '</td>';
    $html .= '<th>휴일근로시간</th><td>' . cpms_payroll_statement_h(cpms_payroll_statement_value($e, 'holiday_hours', '')) . '</td>';
    $html .= '<th>통상시급</th><td class="amount">' . cpms_payroll_statement_h(cpms_payroll_statement_money(cpms_payroll_statement_value($e, 'regular_hourly_wage', 0))) . '</td>';
    $html .= '</tr></tbody></table>';

    $html .= '<table class="statement-table"><tbody>';
    $html .= '<tr class="section-row"><th colspan="2">지급내역</th></tr>';
    $html .= '<tr class="header-row"><th>지급항목</th><th>지급액</th></tr>';
    for ($i = 0; $i < count($payItems); $i++) {
        $item = $payItems[$i];
        $amount = cpms_payroll_statement_value($e, $item['key'], 0);
        if (cpms_payroll_statement_is_zero_amount($amount)) continue;
        $html .= '<tr><th class="item">' . cpms_payroll_statement_h($item['label']) . '</th><td class="amount">' . cpms_payroll_statement_money($amount) . '</td></tr>';
    }
    $html .= '<tr class="total-row"><th>지급합계</th><td class="amount">' . cpms_payroll_statement_money(cpms_payroll_statement_value($e, 'gross_pay', 0)) . '</td></tr>';
    $html .= '</tbody></table>';

    $html .= '<table class="statement-table"><tbody>';
    $html .= '<tr class="section-row"><th colspan="2">공제내역</th></tr>';
    $html .= '<tr class="header-row"><th>공제항목</th><th>공제액</th></tr>';
    for ($j = 0; $j < count($deductItems); $j++) {
        $item2 = $deductItems[$j];
        $amount2 = cpms_payroll_statement_value($e, $item2['key'], 0);
        if (cpms_payroll_statement_is_zero_amount($amount2)) continue;
        $html .= '<tr><th class="item">' . cpms_payroll_statement_h($item2['label']) . '</th><td class="amount">' . cpms_payroll_statement_money($amount2) . '</td></tr>';
    }
    $html .= '<tr class="total-row"><th>공제합계</th><td class="amount">' . cpms_payroll_statement_money(cpms_payroll_statement_value($e, 'total_deduction', 0)) . '</td></tr>';
    $html .= '</tbody></table>';

    $html .= '<table class="statement-table final-table"><tbody>';
    $html .= '<tr><th>실 지급액</th><td>' . cpms_payroll_statement_money(cpms_payroll_statement_value($e, 'net_pay', 0)) . '</td></tr>';
    $html .= '</tbody></table>';
    $memo = cpms_payroll_statement_value($e, 'etc', '');
    if (trim((string)$memo) !== '') {
        $html .= '<div style="font-weight:800;margin-top:14px;">비고</div><div class="memo-box">' . nl2br(cpms_payroll_statement_h($memo)) . '</div>';
    }
    $html .= '<div class="thanks">귀하의 노고에 감사드립니다.</div>';
    $html .= '</div>';
    return $html;
}}

if (!function_exists('cpms_payroll_statement_pdf_html')) {
function cpms_payroll_statement_pdf_html($data) {
    $html = '<!doctype html><html><head><meta charset="utf-8"><title>급여명세서</title></head><body>';
    $html .= cpms_payroll_statement_render_html($data, true);
    $html .= '</body></html>';
    return $html;
}}

if (!function_exists('cpms_payroll_statement_file_birth_suffix')) {
function cpms_payroll_statement_file_birth_suffix($employee) {
    $birth = is_array($employee) && isset($employee['birth_date']) ? preg_replace('/\D+/', '', (string)$employee['birth_date']) : '';
    if (strlen($birth) >= 8) return substr($birth, 2, 6);
    if (strlen($birth) >= 6) return substr($birth, 0, 6);
    $key = is_array($employee) && isset($employee['employee_key']) ? preg_replace('/[^A-Za-z0-9]/', '', (string)$employee['employee_key']) : '';
    return $key !== '' ? substr($key, -6) : '';
}}

if (!function_exists('cpms_payroll_statement_pdf_name')) {
function cpms_payroll_statement_pdf_name($data, &$usedNames, $timestampSuffix) {
    $employee = isset($data['employee']) && is_array($data['employee']) ? $data['employee'] : array();
    $name = isset($employee['name']) ? (string)$employee['name'] : '직원';
    $name = preg_replace('/[\/\\\\:\*\?"<>\|\x00-\x1F]+/', '_', $name);
    $base = $data['year'] . $data['month'] . '_급여명세서_' . $name;
    if (isset($usedNames[$base])) {
        $suffix = cpms_payroll_statement_file_birth_suffix($employee);
        if ($suffix !== '') $base .= '_' . $suffix;
    }
    if ($timestampSuffix !== '') $base .= '_' . $timestampSuffix;
    $pdfName = cpms_drive_sanitize_file_name($base . '.pdf', 180);
    $seq = 2;
    $unique = $pdfName;
    while (isset($usedNames[$unique])) {
        $unique = cpms_drive_sanitize_file_name(preg_replace('/\.pdf$/i', '', $pdfName) . '_' . $seq . '.pdf', 180);
        $seq++;
    }
    $usedNames[$base] = true;
    $usedNames[$unique] = true;
    return $unique;
}}

if (!function_exists('cpms_payroll_statement_create_pdf_named')) {
function cpms_payroll_statement_create_pdf_named($data, $pdfName, $user) {
    if (!is_array($data) || empty($data['ok'])) return array('ok' => false, 'message' => '급여명세서 데이터가 없습니다.');
    $context = array(
        'user' => $user,
        'section' => 'company_payroll_statement_pdf',
        'document_type' => '급여명세서',
        'document_year' => $data['year'],
        'document_month' => $data['month'],
        'original_name' => $pdfName
    );
    return cpms_approval_pdf_create_from_html(cpms_payroll_statement_pdf_html($data), $pdfName, $context);
}}

if (!function_exists('cpms_payroll_statement_create_pdf')) {
function cpms_payroll_statement_create_pdf($data, $user) {
    $used = array();
    $pdfName = cpms_payroll_statement_pdf_name($data, $used, '');
    return cpms_payroll_statement_create_pdf_named($data, $pdfName, $user);
}}

if (!function_exists('cpms_payroll_statement_cleanup_temp')) {
function cpms_payroll_statement_cleanup_temp($path) {
    $path = trim((string)$path);
    if ($path === '') return true;
    if (function_exists('cpms_approval_pdf_cleanup_temp_file') && strpos(str_replace('\\', '/', $path), '/approval_pdf/') !== false) {
        return cpms_approval_pdf_cleanup_temp_file($path);
    }
    $real = realpath($path);
    if ($real === false) return true;
    $root = realpath(cpms_payroll_statement_tmp_root());
    if ($root === false) return false;
    $realNorm = str_replace('\\', '/', $real);
    $rootNorm = rtrim(str_replace('\\', '/', $root), '/') . '/';
    if (strpos($realNorm, $rootNorm) !== 0) return false;
    return @unlink($real);
}}

if (!function_exists('cpms_payroll_statement_ensure_drive_folder')) {
function cpms_payroll_statement_ensure_drive_folder($year, $month, $user, $section) {
    $context = array(
        'user' => $user,
        'section' => $section,
        'document_type' => '급여명세서',
        'document_year' => sprintf('%04d', (int)$year),
        'document_month' => sprintf('%02d', (int)$month)
    );
    return cpms_company_overhead_drive_ensure_month_subfolder('payroll', '임직원월급', $year, $month, '급여명세서', $context);
}}

if (!function_exists('cpms_payroll_statement_empty_result')) {
function cpms_payroll_statement_empty_result($year, $month, $effectiveYear, $effectiveMonth, $userLabel, $mode) {
    $ym = cpms_company_payroll_normalize_year_month($year, $month);
    return array(
        'year' => $ym['year'],
        'month' => $ym['month'],
        'generated_at' => date('Y-m-d H:i:s'),
        'generated_by' => $userLabel,
        'generation_mode' => $mode,
        'payroll_version_year' => (string)$effectiveYear,
        'payroll_version_month' => (string)$effectiveMonth,
        'employee_count' => 0,
        'success_count' => 0,
        'failed_count' => 0,
        'drive_folder_id' => '',
        'drive_folder_web_view_link' => '',
        'zip_name' => '',
        'zip_storage_type' => '',
        'zip_drive_file_id' => '',
        'zip_drive_folder_id' => '',
        'zip_drive_web_view_link' => '',
        'zip_drive_web_content_link' => '',
        'zip_status' => '',
        'zip_error' => '',
        'items' => array()
    );
}}

if (!function_exists('cpms_payroll_statement_base_item')) {
function cpms_payroll_statement_base_item($employee, $pdfName) {
    return array(
        'employee_key' => is_array($employee) && isset($employee['employee_key']) ? (string)$employee['employee_key'] : '',
        'name' => is_array($employee) && isset($employee['name']) ? (string)$employee['name'] : '',
        'pdf_name' => $pdfName,
        'storage_type' => '',
        'drive_file_id' => '',
        'drive_folder_id' => '',
        'drive_web_view_link' => '',
        'drive_web_content_link' => '',
        'generated_at' => date('Y-m-d H:i:s'),
        'status' => 'pending',
        'error' => '',
        'kakao_sent_status' => '',
        'kakao_sent_at' => '',
        'kakao_recipient' => '',
        'kakao_error' => ''
    );
}}

if (!function_exists('cpms_payroll_statement_replace_item')) {
function cpms_payroll_statement_replace_item($items, $item) {
    if (!is_array($items)) $items = array();
    $key = isset($item['employee_key']) ? (string)$item['employee_key'] : '';
    $replaced = false;
    for ($i = 0; $i < count($items); $i++) {
        if (is_array($items[$i]) && isset($items[$i]['employee_key']) && (string)$items[$i]['employee_key'] === $key) {
            $items[$i] = $item;
            $replaced = true;
            break;
        }
    }
    if (!$replaced) $items[] = $item;
    return $items;
}}

if (!function_exists('cpms_payroll_statement_find_item')) {
function cpms_payroll_statement_find_item($result, $employeeKey) {
    if (!is_array($result) || !isset($result['items']) || !is_array($result['items'])) return null;
    $employeeKey = trim((string)$employeeKey);
    foreach ($result['items'] as $item) {
        if (is_array($item) && isset($item['employee_key']) && (string)$item['employee_key'] === $employeeKey) return $item;
    }
    return null;
}}

if (!function_exists('cpms_payroll_statement_item_map')) {
function cpms_payroll_statement_item_map($result) {
    $map = array();
    if (!is_array($result) || !isset($result['items']) || !is_array($result['items'])) return $map;
    foreach ($result['items'] as $item) {
        if (is_array($item) && isset($item['employee_key'])) $map[(string)$item['employee_key']] = $item;
    }
    return $map;
}}

if (!function_exists('cpms_payroll_statement_upload_generated_file')) {
function cpms_payroll_statement_upload_generated_file($path, $name, $mimeType, $folder, $user, $year, $month, $section) {
    $context = array(
        'user' => $user,
        'uploaded_by' => $user,
        'section' => $section,
        'document_type' => '급여명세서',
        'document_year' => sprintf('%04d', (int)$year),
        'document_month' => sprintf('%02d', (int)$month),
        'original_name' => $name,
        'stored_name' => $name,
        'mime_type' => $mimeType,
        'size' => is_file($path) ? (int)@filesize($path) : 0,
        'target_folder_id' => isset($folder['folder_id']) ? (string)$folder['folder_id'] : '',
        'drive_folder_id' => isset($folder['folder_id']) ? (string)$folder['folder_id'] : '',
        'drive_year_folder_id' => isset($folder['year_folder_id']) ? (string)$folder['year_folder_id'] : '',
        'drive_type_folder_id' => isset($folder['category_folder_id']) ? (string)$folder['category_folder_id'] : '',
        'drive_month_folder_id' => isset($folder['month_folder_id']) ? (string)$folder['month_folder_id'] : ''
    );
    return cpms_drive_upload_file($path, $name, isset($folder['folder_id']) ? (string)$folder['folder_id'] : '', $mimeType, $context);
}}

if (!function_exists('cpms_payroll_statement_recount')) {
function cpms_payroll_statement_recount($result) {
    $success = 0;
    $failed = 0;
    $items = isset($result['items']) && is_array($result['items']) ? $result['items'] : array();
    for ($i = 0; $i < count($items); $i++) {
        $status = is_array($items[$i]) && isset($items[$i]['status']) ? (string)$items[$i]['status'] : '';
        if ($status === 'success') $success++;
        else if ($status === 'failed') $failed++;
    }
    $result['employee_count'] = count($items);
    $result['success_count'] = $success;
    $result['failed_count'] = $failed;
    return $result;
}}

if (!function_exists('cpms_payroll_statement_create_zip')) {
function cpms_payroll_statement_create_zip($year, $month, $pdfFiles) {
    if (!class_exists('ZipArchive')) {
        return array('ok' => false, 'path' => '', 'name' => '', 'message' => 'ZipArchive PHP extension is not available.');
    }
    if (!is_array($pdfFiles) || count($pdfFiles) === 0) {
        return array('ok' => false, 'path' => '', 'name' => '', 'message' => 'ZIP에 담을 PDF가 없습니다.');
    }
    $dir = cpms_payroll_statement_tmp_root();
    if (!cpms_payroll_statement_ensure_dir($dir)) {
        return array('ok' => false, 'path' => '', 'name' => '', 'message' => '급여명세서 ZIP 임시 폴더를 만들지 못했습니다.');
    }
    $ym = cpms_company_payroll_normalize_year_month($year, $month);
    $zipName = $ym['year'] . $ym['month'] . '_급여명세서_전체.zip';
    $zipPath = rtrim($dir, '/\\') . '/' . $ym['year'] . $ym['month'] . '_payroll_statements_' . date('Ymd_His') . '_' . mt_rand(1000, 9999) . '.zip';
    $zip = new ZipArchive();
    $opened = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($opened !== true) {
        return array('ok' => false, 'path' => '', 'name' => $zipName, 'message' => 'ZIP 파일을 만들지 못했습니다.');
    }
    for ($i = 0; $i < count($pdfFiles); $i++) {
        if (!is_array($pdfFiles[$i])) continue;
        $path = isset($pdfFiles[$i]['path']) ? (string)$pdfFiles[$i]['path'] : '';
        $name = isset($pdfFiles[$i]['name']) ? (string)$pdfFiles[$i]['name'] : basename($path);
        if ($path !== '' && is_file($path)) $zip->addFile($path, $name);
    }
    $zip->close();
    if (!is_file($zipPath) || (int)@filesize($zipPath) <= 0) {
        return array('ok' => false, 'path' => '', 'name' => $zipName, 'message' => 'ZIP 파일이 비어 있습니다.');
    }
    return array('ok' => true, 'path' => $zipPath, 'name' => $zipName, 'message' => 'ZIP 파일을 생성했습니다.');
}}

if (!function_exists('cpms_payroll_statement_generate_month')) {
function cpms_payroll_statement_generate_month($year, $month, $user, $options) {
    if (!is_array($options)) $options = array();
    $ym = cpms_company_payroll_normalize_year_month($year, $month);
    $year = $ym['year'];
    $month = $ym['month'];
    $force = !empty($options['force']);
    $employeeKey = isset($options['employee_key']) ? trim((string)$options['employee_key']) : '';
    $mode = isset($options['mode']) ? trim((string)$options['mode']) : 'manual';
    if ($mode === '') $mode = 'manual';
    $userLabel = ($mode === 'cron') ? 'system' : cpms_company_payroll_user_label($user);
    $existing = cpms_payroll_statement_load_month($year, $month);

    if (!$force && $employeeKey === '' && is_array($existing) && isset($existing['generated_at']) && trim((string)$existing['generated_at']) !== '') {
        return array('ok' => true, 'skipped' => true, 'message' => '이미 생성된 월 급여명세서가 있어 중복 생성하지 않았습니다.', 'result' => $existing);
    }
    if ($force && is_array($existing) && !cpms_payroll_statement_backup_existing($year, $month)) {
        return array('ok' => false, 'message' => '기존 급여명세서 생성 결과를 history로 백업하지 못했습니다.');
    }

    $effective = cpms_company_payroll_effective_version($year, $month);
    if (empty($effective['ok']) || !isset($effective['version']) || !is_array($effective['version'])) {
        $message = isset($effective['message']) ? (string)$effective['message'] : '적용 급여 기준월 버전이 없습니다.';
        cpms_payroll_statement_append_log($year, $month, array(
            'executed_at' => date('Y-m-d H:i:s'),
            'executed_by' => $userLabel,
            'mode' => $mode,
            'target_year' => $year,
            'target_month' => $month,
            'error_summary' => $message
        ));
        return array('ok' => false, 'message' => $message);
    }

    $folder = cpms_payroll_statement_ensure_drive_folder($year, $month, $user, 'company_payroll_statement_generate');
    if (empty($folder['ok'])) {
        $message2 = isset($folder['message']) ? (string)$folder['message'] : '급여명세서 Drive 폴더를 준비하지 못했습니다.';
        cpms_payroll_statement_append_log($year, $month, array(
            'executed_at' => date('Y-m-d H:i:s'),
            'executed_by' => $userLabel,
            'mode' => $mode,
            'target_year' => $year,
            'target_month' => $month,
            'payroll_version_year' => isset($effective['effective_year']) ? (string)$effective['effective_year'] : '',
            'payroll_version_month' => isset($effective['effective_month']) ? (string)$effective['effective_month'] : '',
            'error_summary' => $message2
        ));
        return array('ok' => false, 'message' => $message2);
    }

    $version = $effective['version'];
    $employees = isset($version['employees']) && is_array($version['employees']) ? $version['employees'] : array();
    $targetEmployees = array();
    for ($i = 0; $i < count($employees); $i++) {
        if (!is_array($employees[$i])) continue;
        if ($employeeKey !== '' && (!isset($employees[$i]['employee_key']) || (string)$employees[$i]['employee_key'] !== $employeeKey)) continue;
        $targetEmployees[] = $employees[$i];
    }
    if ($employeeKey !== '' && count($targetEmployees) === 0) return array('ok' => false, 'message' => '재생성할 직원 급여 데이터를 찾지 못했습니다.');

    if ($employeeKey !== '' && is_array($existing) && !$force) {
        $result = $existing;
    } else if ($employeeKey !== '' && is_array($existing)) {
        $result = $existing;
        $result['generated_at'] = date('Y-m-d H:i:s');
        $result['generated_by'] = $userLabel;
        $result['generation_mode'] = $mode;
    } else {
        $result = cpms_payroll_statement_empty_result($year, $month, $effective['effective_year'], $effective['effective_month'], $userLabel, $mode);
    }
    if ($employeeKey === '') {
        $result = cpms_payroll_statement_empty_result($year, $month, $effective['effective_year'], $effective['effective_month'], $userLabel, $mode);
    }
    $result['drive_folder_id'] = isset($folder['folder_id']) ? (string)$folder['folder_id'] : '';
    $result['drive_folder_web_view_link'] = isset($folder['sub_folder_web_view_link']) ? (string)$folder['sub_folder_web_view_link'] : '';
    $result['payroll_version_year'] = (string)$effective['effective_year'];
    $result['payroll_version_month'] = (string)$effective['effective_month'];

    $usedNames = array();
    $generatedPdfs = array();
    $timestampSuffix = $force ? date('YmdHi') : '';
    $errors = array();

    for ($j = 0; $j < count($targetEmployees); $j++) {
        $employee = $targetEmployees[$j];
        $data = cpms_payroll_statement_data_from_employee($year, $month, $effective['effective_year'], $effective['effective_month'], $version, $employee);
        $pdfName = cpms_payroll_statement_pdf_name($data, $usedNames, $timestampSuffix);
        $item = cpms_payroll_statement_base_item($employee, $pdfName);
        $pdf = cpms_payroll_statement_create_pdf_named($data, $pdfName, $user);
        if (empty($pdf['ok']) || !isset($pdf['path']) || !is_file($pdf['path'])) {
            $item['status'] = 'failed';
            $item['error'] = isset($pdf['message']) ? cpms_drive_redact_text((string)$pdf['message']) : 'PDF 생성에 실패했습니다.';
            $errors[] = $item['name'] . ': ' . $item['error'];
            $result['items'] = cpms_payroll_statement_replace_item(isset($result['items']) ? $result['items'] : array(), $item);
            continue;
        }

        $upload = cpms_payroll_statement_upload_generated_file($pdf['path'], $pdfName, 'application/pdf', $folder, $user, $year, $month, 'company_payroll_statement_pdf_upload');
        if (empty($upload['ok']) || !isset($upload['file']) || !is_array($upload['file'])) {
            $item['status'] = 'failed';
            $item['error'] = isset($upload['message']) ? cpms_drive_redact_text((string)$upload['message']) : 'Drive 업로드에 실패했습니다.';
            $errors[] = $item['name'] . ': ' . $item['error'];
            cpms_payroll_statement_cleanup_temp($pdf['path']);
            $result['items'] = cpms_payroll_statement_replace_item(isset($result['items']) ? $result['items'] : array(), $item);
            continue;
        }
        $record = cpms_drive_build_file_record($upload['file'], array(
            'user' => $user,
            'section' => 'company_payroll_statement_pdf_upload',
            'document_type' => '급여명세서',
            'document_year' => $year,
            'document_month' => $month,
            'original_name' => $pdfName,
            'stored_name' => $pdfName,
            'target_folder_id' => isset($folder['folder_id']) ? (string)$folder['folder_id'] : ''
        ));
        $item['status'] = 'success';
        $item['storage_type'] = 'google_drive';
        $item['drive_file_id'] = isset($record['drive_file_id']) ? (string)$record['drive_file_id'] : '';
        $item['drive_folder_id'] = isset($record['drive_folder_id']) ? (string)$record['drive_folder_id'] : '';
        $item['drive_web_view_link'] = isset($record['drive_web_view_link']) ? (string)$record['drive_web_view_link'] : '';
        $item['drive_web_content_link'] = isset($record['drive_web_content_link']) ? (string)$record['drive_web_content_link'] : '';
        $item['error'] = '';
        $generatedPdfs[] = array('path' => $pdf['path'], 'name' => $pdfName);
        $result['items'] = cpms_payroll_statement_replace_item(isset($result['items']) ? $result['items'] : array(), $item);
    }

    if ($employeeKey === '') {
        $zip = cpms_payroll_statement_create_zip($year, $month, $generatedPdfs);
        if (!empty($zip['ok'])) {
            $zipUpload = cpms_payroll_statement_upload_generated_file($zip['path'], $zip['name'], 'application/zip', $folder, $user, $year, $month, 'company_payroll_statement_zip_upload');
            $result['zip_name'] = $zip['name'];
            if (!empty($zipUpload['ok']) && isset($zipUpload['file']) && is_array($zipUpload['file'])) {
                $zipRecord = cpms_drive_build_file_record($zipUpload['file'], array(
                    'user' => $user,
                    'section' => 'company_payroll_statement_zip_upload',
                    'document_type' => '급여명세서 ZIP',
                    'document_year' => $year,
                    'document_month' => $month,
                    'original_name' => $zip['name'],
                    'stored_name' => $zip['name'],
                    'target_folder_id' => isset($folder['folder_id']) ? (string)$folder['folder_id'] : ''
                ));
                $result['zip_status'] = 'success';
                $result['zip_storage_type'] = 'google_drive';
                $result['zip_drive_file_id'] = isset($zipRecord['drive_file_id']) ? (string)$zipRecord['drive_file_id'] : '';
                $result['zip_drive_folder_id'] = isset($zipRecord['drive_folder_id']) ? (string)$zipRecord['drive_folder_id'] : '';
                $result['zip_drive_web_view_link'] = isset($zipRecord['drive_web_view_link']) ? (string)$zipRecord['drive_web_view_link'] : '';
                $result['zip_drive_web_content_link'] = isset($zipRecord['drive_web_content_link']) ? (string)$zipRecord['drive_web_content_link'] : '';
                $result['zip_error'] = '';
            } else {
                $result['zip_status'] = 'failed';
                $result['zip_error'] = isset($zipUpload['message']) ? cpms_drive_redact_text((string)$zipUpload['message']) : 'ZIP Drive 업로드에 실패했습니다.';
                $errors[] = $result['zip_error'];
            }
            cpms_payroll_statement_cleanup_temp($zip['path']);
        } else {
            $result['zip_name'] = $year . $month . '_급여명세서_전체.zip';
            $result['zip_status'] = 'failed';
            $result['zip_error'] = isset($zip['message']) ? (string)$zip['message'] : 'ZIP 생성에 실패했습니다.';
            if (!class_exists('ZipArchive')) $result['zip_status'] = 'unsupported';
        }
    }

    for ($c = 0; $c < count($generatedPdfs); $c++) {
        if (isset($generatedPdfs[$c]['path'])) cpms_payroll_statement_cleanup_temp($generatedPdfs[$c]['path']);
    }

    $result = cpms_payroll_statement_recount($result);
    $saved = cpms_payroll_statement_write_month($year, $month, $result);
    cpms_payroll_statement_append_log($year, $month, array(
        'executed_at' => date('Y-m-d H:i:s'),
        'executed_by' => $userLabel,
        'mode' => $mode,
        'target_year' => $year,
        'target_month' => $month,
        'payroll_version_year' => (string)$effective['effective_year'],
        'payroll_version_month' => (string)$effective['effective_month'],
        'employee_count' => isset($result['employee_count']) ? (int)$result['employee_count'] : 0,
        'success_pdf_count' => isset($result['success_count']) ? (int)$result['success_count'] : 0,
        'failed_pdf_count' => isset($result['failed_count']) ? (int)$result['failed_count'] : 0,
        'zip_created' => (isset($result['zip_status']) && $result['zip_status'] === 'success') ? 'yes' : 'no',
        'drive_uploaded' => (isset($result['success_count']) && (int)$result['success_count'] > 0) ? 'yes' : 'no',
        'error_summary' => count($errors) > 0 ? implode(' / ', array_slice($errors, 0, 5)) : ''
    ));
    if (!$saved) return array('ok' => false, 'message' => '급여명세서 생성 결과 JSON을 저장하지 못했습니다.', 'result' => $result);
    return array('ok' => true, 'message' => '급여명세서 PDF 생성이 완료되었습니다.', 'result' => $result);
}}

if (!function_exists('cpms_payroll_statement_stream_drive_file')) {
function cpms_payroll_statement_stream_drive_file($fileId, $downloadName, $mimeType) {
    $download = cpms_drive_download_file($fileId);
    if (empty($download['ok'])) return $download;
    return array(
        'ok' => true,
        'content' => isset($download['content']) ? (string)$download['content'] : '',
        'name' => $downloadName,
        'mime_type' => $mimeType,
        'message' => '파일을 다운로드했습니다.'
    );
}}

if (!function_exists('cpms_payroll_statement_run_due_notice')) {
function cpms_payroll_statement_run_due_notice($year, $month) {
    $nowYear = (int)date('Y');
    $nowMonth = (int)date('m');
    if ((int)$year !== $nowYear || (int)$month !== $nowMonth) return false;
    if ((int)date('d') < 15) return false;
    if ((int)date('d') === 15 && (int)date('H') < 8) return false;
    $existing = cpms_payroll_statement_load_month($year, $month);
    return !is_array($existing) || !isset($existing['generated_at']) || trim((string)$existing['generated_at']) === '';
}}

if (!function_exists('cpms_payroll_statement_drive_run_admin_check')) {
function cpms_payroll_statement_drive_run_admin_check($userContext) {
    $year = date('Y');
    $month = date('m');
    $result = array(
        'management_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'overhead_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'payroll_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'year_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'month_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'original_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'statement_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'mpdf' => array('ok' => false, 'message' => ''),
        'ziparchive' => array('ok' => false, 'message' => ''),
        'test_pdf_upload' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'test_zip_upload' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'test_delete' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'supports_all_drives_delete' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'cron_script' => array('ok' => false, 'message' => ''),
        'test_files' => array()
    );
    $context = array('user' => $userContext, 'section' => 'admin_drive_check_payroll_statement', 'document_type' => '급여명세서', 'document_year' => $year, 'document_month' => $month);
    $original = cpms_company_overhead_drive_ensure_month_subfolder('payroll', '임직원월급', $year, $month, '원본급여대장', $context);
    $statement = cpms_company_overhead_drive_ensure_month_subfolder('payroll', '임직원월급', $year, $month, '급여명세서', $context);
    $base = !empty($statement['ok']) ? $statement : $original;
    $result['management_folder'] = array('ok' => !empty($base['management_folder_id']), 'http_code' => isset($base['http_code']) ? (int)$base['http_code'] : 0, 'message' => !empty($base['management_folder_id']) ? '04_관리부 folder is ready.' : (isset($base['message']) ? (string)$base['message'] : 'Folder check failed.'));
    $result['overhead_folder'] = array('ok' => !empty($base['overhead_folder_id']), 'http_code' => isset($base['http_code']) ? (int)$base['http_code'] : 0, 'message' => !empty($base['overhead_folder_id']) ? '총관리비 folder is ready.' : (isset($base['message']) ? (string)$base['message'] : 'Folder check failed.'));
    $result['payroll_folder'] = array('ok' => !empty($base['category_folder_id']), 'http_code' => isset($base['http_code']) ? (int)$base['http_code'] : 0, 'message' => !empty($base['category_folder_id']) ? '임직원월급 folder is ready.' : (isset($base['message']) ? (string)$base['message'] : 'Folder check failed.'));
    $result['year_folder'] = array('ok' => !empty($base['year_folder_id']), 'http_code' => isset($base['http_code']) ? (int)$base['http_code'] : 0, 'message' => !empty($base['year_folder_id']) ? $year . ' folder is ready.' : (isset($base['message']) ? (string)$base['message'] : 'Folder check failed.'));
    $result['month_folder'] = array('ok' => !empty($base['month_folder_id']), 'http_code' => isset($base['http_code']) ? (int)$base['http_code'] : 0, 'message' => !empty($base['month_folder_id']) ? $month . ' folder is ready.' : (isset($base['message']) ? (string)$base['message'] : 'Folder check failed.'));
    $result['original_folder'] = array('ok' => !empty($original['sub_folder_id']), 'http_code' => isset($original['http_code']) ? (int)$original['http_code'] : 0, 'message' => !empty($original['sub_folder_id']) ? '원본급여대장 folder is ready.' : (isset($original['message']) ? (string)$original['message'] : 'Folder check failed.'));
    $result['statement_folder'] = array('ok' => !empty($statement['sub_folder_id']), 'http_code' => isset($statement['http_code']) ? (int)$statement['http_code'] : 0, 'message' => !empty($statement['sub_folder_id']) ? '급여명세서 folder is ready.' : (isset($statement['message']) ? (string)$statement['message'] : 'Folder check failed.'));
    $mpdf = cpms_approval_pdf_mpdf_is_available($context);
    $result['mpdf'] = array('ok' => !empty($mpdf['ok']), 'message' => isset($mpdf['message']) ? (string)$mpdf['message'] : '');
    $result['ziparchive'] = array('ok' => class_exists('ZipArchive'), 'message' => class_exists('ZipArchive') ? 'ZipArchive extension is available.' : 'ZipArchive extension is not available.');
    $cronPath = dirname(dirname(__DIR__)) . '/tools/payroll_statement_monthly_job.php';
    $result['cron_script'] = array('ok' => is_file($cronPath), 'message' => is_file($cronPath) ? 'Cron script exists.' : 'Cron script is missing.');

    if (!empty($statement['ok'])) {
        $testName = 'CPMS_Payroll_Statement_Check_' . date('Ymd_His') . '.pdf';
        $html = '<!doctype html><html><head><meta charset="utf-8"></head><body><div style="font-family:Malgun Gothic,sans-serif;border:1px solid #111;padding:20px">CPMS 급여명세서 PDF 점검<br>' . cpms_payroll_statement_h(date('Y-m-d H:i:s')) . '</div></body></html>';
        $pdf = cpms_approval_pdf_create_from_html($html, $testName, $context);
        if (!empty($pdf['ok']) && isset($pdf['path']) && is_file($pdf['path'])) {
            $upload = cpms_payroll_statement_upload_generated_file($pdf['path'], $testName, 'application/pdf', $statement, $userContext, $year, $month, 'admin_drive_check_payroll_statement_pdf');
            $result['test_pdf_upload'] = array('ok' => !empty($upload['ok']), 'http_code' => isset($upload['http_code']) ? (int)$upload['http_code'] : 0, 'message' => isset($upload['message']) ? (string)$upload['message'] : '');
            if (!empty($upload['ok']) && isset($upload['file']['id'])) $result['test_files'][] = (string)$upload['file']['id'];
            cpms_payroll_statement_cleanup_temp($pdf['path']);
        } else {
            $result['test_pdf_upload']['message'] = isset($pdf['message']) ? (string)$pdf['message'] : 'Test PDF creation failed.';
        }
        if (class_exists('ZipArchive')) {
            $dir = cpms_payroll_statement_tmp_root();
            cpms_payroll_statement_ensure_dir($dir);
            $zipPath = rtrim($dir, '/\\') . '/CPMS_Payroll_Statement_Zip_Check_' . date('Ymd_His') . '.zip';
            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                $zip->addFromString('check.txt', 'CPMS payroll statement ZIP check ' . date('Y-m-d H:i:s'));
                $zip->close();
                $zipName = basename($zipPath);
                $zipUpload = cpms_payroll_statement_upload_generated_file($zipPath, $zipName, 'application/zip', $statement, $userContext, $year, $month, 'admin_drive_check_payroll_statement_zip');
                $result['test_zip_upload'] = array('ok' => !empty($zipUpload['ok']), 'http_code' => isset($zipUpload['http_code']) ? (int)$zipUpload['http_code'] : 0, 'message' => isset($zipUpload['message']) ? (string)$zipUpload['message'] : '');
                if (!empty($zipUpload['ok']) && isset($zipUpload['file']['id'])) $result['test_files'][] = (string)$zipUpload['file']['id'];
                cpms_payroll_statement_cleanup_temp($zipPath);
            } else {
                $result['test_zip_upload']['message'] = 'Test ZIP file could not be created.';
            }
        } else {
            $result['test_zip_upload']['message'] = 'ZipArchive extension is not available.';
        }
        $deleteOk = true;
        $lastHttp = 0;
        $lastMessage = '';
        for ($i = 0; $i < count($result['test_files']); $i++) {
            $delete = cpms_drive_delete_file($result['test_files'][$i], $context);
            $lastHttp = isset($delete['http_code']) ? (int)$delete['http_code'] : 0;
            $lastMessage = isset($delete['message']) ? (string)$delete['message'] : '';
            if (empty($delete['ok'])) $deleteOk = false;
        }
        $result['test_delete'] = array('ok' => ($deleteOk && count($result['test_files']) > 0), 'http_code' => $lastHttp, 'message' => $lastMessage);
        $result['supports_all_drives_delete'] = array('ok' => ($deleteOk && $lastHttp === 204), 'http_code' => $lastHttp, 'message' => ($lastHttp === 204 ? 'Delete API returned HTTP 204 with supportsAllDrives=true.' : 'Delete API did not return HTTP 204.'));
    }
    return $result;
}}
