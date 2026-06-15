<?php
/**
 * 자재구입비(장비 방식 복제)
 * 공사 > 자재구입비 > 입력
 * - 자재구입비 마스터 저장
 * - use_dates가 함께 오면 같은 월 사용일자까지 저장
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/partials/master_dedupe_helper.php';
require_once __DIR__ . '/partials/material_statement_helper.php';
require_once __DIR__ . '/partials/material_usage_helper.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) { header('Location: ?r=login'); exit; }

$role = Auth::userRole();
$dept = Auth::userDepartment();
if (!Auth::canManageConstruction()) {
    http_response_code(403);
    echo '403 Forbidden';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'Method Not Allowed'; exit; }
if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
    flash_set('error', '보안 토큰이 유효하지 않습니다.');
    header('Location: ?r=공사');
    exit;
}

$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$materialsTab = isset($_POST['materials_tab']) ? trim((string)$_POST['materials_tab']) : 'input';
$ym = isset($_POST['ym']) ? trim((string)$_POST['ym']) : date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = date('Y-m');

$redirect = '?r=공사&pid=' . $projectId . '&tab=materials&materials_tab=' . urlencode($materialsTab) . '&ym=' . urlencode($ym);
if ($projectId <= 0) {
    flash_set('error', '프로젝트 정보가 올바르지 않습니다.');
    header('Location: ' . $redirect);
    exit;
}

$bulkAction = isset($_POST['bulk_action']) ? trim((string)$_POST['bulk_action']) : '';
if ($bulkAction === 'preview' || $bulkAction === 'apply') {
    $pdo = Db::pdo();
    if (!$pdo) {
        flash_set('error', 'DB 연결 실패');
        header('Location: ' . $redirect);
        exit;
    }
    cpms_material_usage_ensure_schema($pdo);
    if ($bulkAction === 'preview') {
        material_bulk_preview_action($pdo, $projectId, $ym);
    } else {
        material_bulk_apply_action($pdo, $projectId, $ym);
    }
    exit;
}

$category = trim((string)(isset($_POST['category']) ? $_POST['category'] : ''));
$allowedMaterialCategories = array('자재비'=>true, '구매품'=>true, '기타경비'=>true);
$vendorName = trim((string)(isset($_POST['vendor_name']) ? $_POST['vendor_name'] : ''));
// 자재: 규격 제거
$spec = '';
$representative = trim((string)(isset($_POST['representative']) ? $_POST['representative'] : ''));
$phone = trim((string)(isset($_POST['phone']) ? $_POST['phone'] : ''));
$bizNo = trim((string)(isset($_POST['biz_no']) ? $_POST['biz_no'] : ''));
$baseRate = isset($_POST['base_rate']) ? (float)$_POST['base_rate'] : 0;
$remark = trim((string)(isset($_POST['remark']) ? $_POST['remark'] : ''));
$advanceYn = cpms_material_advance_yn(isset($_POST['advance_yn']) ? $_POST['advance_yn'] : 'N');
$usageDates = isset($_POST['usage_dates']) ? $_POST['usage_dates'] : array();
$useDatesText = trim((string)(isset($_POST['use_dates']) ? $_POST['use_dates'] : ''));

if ($category === '' || $vendorName === '') {
    flash_set('error', '구분, 업체명은 필수입니다.');
    header('Location: ' . $redirect);
    exit;
}
if (!isset($allowedMaterialCategories[$category])) {
    if ($category === '안전관리비') {
        flash_set('error', '안전관리비 사용내역은 안전섹션에서 등록해주세요.');
    } else {
        flash_set('error', '허용되지 않은 구분입니다.');
    }
    header('Location: ' . $redirect);
    exit;
}

function material_parse_use_dates($text, $ym)
{
    $result = array();
    if ($text === '') return $result;

    $text = str_replace(array("\r\n", "\n", ';', '|'), ',', $text);
    $tokens = explode(',', $text);
    $range = material_month_range($ym);
    $rangeStart = strtotime($range['start']);
    $rangeEnd = strtotime($range['end']);

    foreach ($tokens as $tk) {
        $token = trim($tk);
        if ($token === '') continue;

        if (strpos($token, '~') !== false) {
            $parts = explode('~', $token, 2);
            $start = trim($parts[0]);
            $end = trim($parts[1]);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
                continue;
            }
            $sTs = strtotime($start);
            $eTs = strtotime($end);
            if ($sTs === false || $eTs === false) continue;
            if ($sTs > $eTs) { $tmp = $sTs; $sTs = $eTs; $eTs = $tmp; }
            for ($t = $sTs; $t <= $eTs; $t += 86400) {
                if ($rangeStart === false || $rangeEnd === false || $t < $rangeStart || $t > $rangeEnd) continue;
                $result[date('Y-m-d', $t)] = true;
            }
            continue;
        }

        if (preg_match('/^\d{1,2}$/', $token)) {
            $token = $ym . '-' . sprintf('%02d', (int)$token);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $token)) {
            $ts = strtotime($token);
            if ($ts !== false && $rangeStart !== false && $rangeEnd !== false && $ts >= $rangeStart && $ts <= $rangeEnd) {
                $result[date('Y-m-d', $ts)] = true;
            }
        }
    }

    return array_keys($result);
}

function material_month_range($ym)
{
    $prevYm = date('Y-m', strtotime($ym . '-01 -1 month'));
    return array(
        'start' => $prevYm . '-26',
        'end' => $ym . '-25',
    );
}

function material_is_in_month_range($date, $ym)
{
    $range = material_month_range($ym);
    $ts = strtotime($date);
    $startTs = strtotime($range['start']);
    $endTs = strtotime($range['end']);
    if ($ts === false || $startTs === false || $endTs === false) return false;
    return ($ts >= $startTs && $ts <= $endTs);
}

function material_collect_usage_dates($usageDates, $text, $ym)
{
    $result = array();

    if (is_array($usageDates)) {
        foreach ($usageDates as $d) {
            $date = trim((string)$d);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;
            $ts = strtotime($date);
            if ($ts !== false && material_is_in_month_range($date, $ym)) {
                $result[date('Y-m-d', $ts)] = true;
            }
        }
    }

    $legacy = material_parse_use_dates($text, $ym);
    foreach ($legacy as $d) {
        $result[$d] = true;
    }

    return array_keys($result);
}

function material_bulk_redirect_url($projectId, $ym, $token)
{
    $url = '?r=공사&pid=' . (int)$projectId . '&tab=materials&materials_tab=input&ym=' . urlencode((string)$ym);
    if ($token !== '') $url .= '&bulk_token=' . urlencode((string)$token);
    return $url;
}

function material_bulk_valid_ym($ym)
{
    $ym = trim((string)$ym);
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) return false;
    $year = (int)substr($ym, 0, 4);
    $month = (int)substr($ym, 5, 2);
    return ($year >= 2000 && $year <= 2100 && $month >= 1 && $month <= 12);
}

function material_bulk_prev_ym($ym)
{
    return date('Y-m', strtotime($ym . '-01 -1 month'));
}

function material_bulk_excel_error_values()
{
    return array('#NAME?'=>true, '#VALUE!'=>true, '#REF!'=>true, '#DIV/0!'=>true, '#N/A'=>true, '#NUM!'=>true, '#NULL!'=>true);
}

function material_bulk_is_excel_error($value)
{
    $v = strtoupper(trim((string)$value));
    $errors = material_bulk_excel_error_values();
    return isset($errors[$v]);
}

function material_bulk_clean_cell($value, &$hadError)
{
    if (material_bulk_is_excel_error($value)) {
        $hadError = true;
        return '';
    }
    return trim((string)$value);
}

function material_bulk_parse_money($value)
{
    $raw = trim((string)$value);
    if ($raw === '' || material_bulk_is_excel_error($raw)) return 0.0;
    $raw = str_replace(array(',', ' ', "\t", '원'), '', $raw);
    $raw = preg_replace('/[^0-9.\-]/', '', $raw);
    if ($raw === '' || $raw === '-' || $raw === '.' || !is_numeric($raw)) return 0.0;
    return (float)$raw;
}

function material_bulk_normalize_category($value)
{
    $v = trim((string)$value);
    $key = preg_replace('/\s+/u', '', $v);
    $map = array(
        '자재비' => '자재비',
        '자재' => '자재비',
        '구매품' => '구매품',
        '구매' => '구매품',
        '기타경비' => '기타경비',
        '경비' => '기타경비',
        '안전관리비' => '안전관리비'
    );
    return isset($map[$key]) ? $map[$key] : $v;
}

function material_bulk_normalize_advance($value)
{
    $v = strtoupper(trim((string)$value));
    if ($v === 'Y' || $v === 'YES' || $v === '1' || $v === 'TRUE' || $v === '선급' || $v === '예') return 'Y';
    return 'N';
}

function material_bulk_day_to_date($dayValue, $ym, &$message, &$dayNumber)
{
    $message = '';
    $dayNumber = 0;
    $raw = trim((string)$dayValue);
    if ($raw === '' || material_bulk_is_excel_error($raw)) {
        $message = '일 값이 없습니다.';
        return '';
    }
    $raw = str_replace(array(',', ' '), '', $raw);
    if (preg_match('/^\d+\.0+$/', $raw)) {
        $raw = (string)((int)$raw);
    }
    if (!preg_match('/^\d+$/', $raw)) {
        $message = '일 값이 숫자가 아닙니다.';
        return '';
    }
    $dayNumber = (int)$raw;
    if ($dayNumber < 1 || $dayNumber > 31) {
        $message = '일 값은 1~31 범위여야 합니다.';
        return '';
    }
    $targetYm = ($dayNumber >= 26) ? material_bulk_prev_ym($ym) : $ym;
    $year = (int)substr($targetYm, 0, 4);
    $month = (int)substr($targetYm, 5, 2);
    if (!checkdate($month, $dayNumber, $year)) {
        $message = $targetYm . '-' . sprintf('%02d', $dayNumber) . '은 존재하지 않는 날짜입니다.';
        return '';
    }
    return $targetYm . '-' . sprintf('%02d', $dayNumber);
}

function material_bulk_text_key($value)
{
    return cpms_master_dedupe_text_key($value);
}

function material_bulk_duplicate_key($row)
{
    return implode('|', array(
        isset($row['use_date']) ? (string)$row['use_date'] : '',
        isset($row['category']) ? (string)$row['category'] : '',
        material_bulk_text_key(isset($row['vendor_name']) ? $row['vendor_name'] : ''),
        cpms_master_dedupe_biz_key(isset($row['biz_no']) ? $row['biz_no'] : ''),
        cpms_master_dedupe_money_key(isset($row['amount']) ? $row['amount'] : 0),
        material_bulk_text_key(isset($row['remark']) ? $row['remark'] : '')
    ));
}

function material_bulk_usage_unique_key($row)
{
    return implode('|', array(
        isset($row['use_date']) ? (string)$row['use_date'] : '',
        isset($row['category']) ? (string)$row['category'] : '',
        material_bulk_text_key(isset($row['vendor_name']) ? $row['vendor_name'] : ''),
        cpms_master_dedupe_biz_key(isset($row['biz_no']) ? $row['biz_no'] : ''),
        cpms_master_dedupe_money_key(isset($row['amount']) ? $row['amount'] : 0)
    ));
}

function material_bulk_existing_duplicate($pdo, $projectId, $row, $strictRemark)
{
    if (!$pdo || (int)$projectId <= 0 || !is_array($row)) return false;
    $sql = "SELECT u.id, u.use_date, u.amount, i.category, i.vendor_name, i.biz_no, i.remark
              FROM cpms_material_usage u
              JOIN cpms_material_items i ON i.id = u.material_id
             WHERE u.project_id = :pid
               AND u.use_date = :use_date
               AND i.category = :category
               AND u.amount = :amount
               AND i.is_deleted = 0";
    $st = $pdo->prepare($sql);
    $st->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
    $st->bindValue(':use_date', isset($row['use_date']) ? (string)$row['use_date'] : '');
    $st->bindValue(':category', isset($row['category']) ? (string)$row['category'] : '');
    $st->bindValue(':amount', isset($row['amount']) ? (float)$row['amount'] : 0);
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) return false;

    $targetVendor = material_bulk_text_key(isset($row['vendor_name']) ? $row['vendor_name'] : '');
    $targetBiz = cpms_master_dedupe_biz_key(isset($row['biz_no']) ? $row['biz_no'] : '');
    $targetRemark = material_bulk_text_key(isset($row['remark']) ? $row['remark'] : '');
    foreach ($rows as $r) {
        if (material_bulk_text_key(isset($r['vendor_name']) ? $r['vendor_name'] : '') !== $targetVendor) continue;
        if (cpms_master_dedupe_biz_key(isset($r['biz_no']) ? $r['biz_no'] : '') !== $targetBiz) continue;
        if (!$strictRemark) return true;
        if (material_bulk_text_key(isset($r['remark']) ? $r['remark'] : '') !== $targetRemark) continue;
        return true;
    }
    return false;
}

function material_bulk_xlsx_col_index($cellRef)
{
    $letters = preg_replace('/[^A-Z]/', '', strtoupper((string)$cellRef));
    if ($letters === '') return 0;
    $num = 0;
    $len = strlen($letters);
    for ($i = 0; $i < $len; $i++) {
        $num = $num * 26 + (ord($letters[$i]) - 64);
    }
    return (int)$num;
}

function material_bulk_xlsx_shared_strings($zip)
{
    $strings = array();
    $xml = $zip->getFromName('xl/sharedStrings.xml');
    if ($xml === false) return $strings;
    $sx = @simplexml_load_string($xml);
    if (!$sx) return $strings;
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

function material_bulk_xlsx_sheet_list($zip)
{
    $sheets = array();
    $workbookXml = $zip->getFromName('xl/workbook.xml');
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($workbookXml === false || $relsXml === false) return $sheets;
    $wb = @simplexml_load_string($workbookXml);
    $rels = @simplexml_load_string($relsXml);
    if (!$wb || !$rels || !isset($wb->sheets)) return $sheets;

    $targets = array();
    foreach ($rels->Relationship as $rel) {
        $rid = (string)$rel['Id'];
        $target = (string)$rel['Target'];
        if ($rid === '' || $target === '') continue;
        $target = str_replace('\\', '/', $target);
        $target = ltrim($target, '/');
        if (strpos($target, 'xl/') !== 0) $target = 'xl/' . $target;
        $targets[$rid] = $target;
    }

    $index = 0;
    foreach ($wb->sheets->sheet as $sheet) {
        $attrs = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $rid = isset($attrs['id']) ? (string)$attrs['id'] : '';
        $name = (string)$sheet['name'];
        if ($rid !== '' && isset($targets[$rid])) {
            $sheets[count($sheets)] = array('name'=>$name, 'path'=>$targets[$rid], 'index'=>$index);
        }
        $index++;
    }
    return $sheets;
}

function material_bulk_xlsx_read_sheet($filePath, $preferredSheetName, $maxRows)
{
    $result = array('rows'=>array(), 'sheet_name'=>'', 'used_fallback'=>0, 'error'=>'');
    if (!is_file($filePath)) {
        $result['error'] = '엑셀 파일을 찾을 수 없습니다.';
        return $result;
    }
    if (!class_exists('ZipArchive')) {
        $result['error'] = '서버에 ZipArchive 확장 모듈이 없어 .xlsx 파일을 읽을 수 없습니다.';
        return $result;
    }
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        $result['error'] = '엑셀 파일을 열 수 없습니다. 파일이 손상되었거나 xlsx 형식이 아닙니다.';
        return $result;
    }
    $sheetList = material_bulk_xlsx_sheet_list($zip);
    $target = null;
    if (count($sheetList) > 0) {
        foreach ($sheetList as $sheet) {
            if ((string)$sheet['name'] === (string)$preferredSheetName) {
                $target = $sheet;
                break;
            }
        }
        if (!$target) {
            $target = $sheetList[0];
            $result['used_fallback'] = 1;
        }
    } else {
        $target = array('name'=>'첫 번째 시트', 'path'=>'xl/worksheets/sheet1.xml', 'index'=>0);
        $result['used_fallback'] = 1;
    }

    $sheetXml = $zip->getFromName($target['path']);
    if ($sheetXml === false) {
        $zip->close();
        $result['error'] = '엑셀 시트 데이터를 찾을 수 없습니다.';
        return $result;
    }
    $sharedStrings = material_bulk_xlsx_shared_strings($zip);
    $sx = @simplexml_load_string($sheetXml);
    if (!$sx || !isset($sx->sheetData)) {
        $zip->close();
        $result['error'] = '엑셀 시트 파싱에 실패했습니다.';
        return $result;
    }

    $rows = array();
    foreach ($sx->sheetData->row as $row) {
        $rowNo = isset($row['r']) ? (int)$row['r'] : (count($rows) + 1);
        if ($rowNo > (int)$maxRows) break;
        $cells = array();
        if (isset($row->c)) {
            foreach ($row->c as $cell) {
                $ref = isset($cell['r']) ? (string)$cell['r'] : '';
                $col = material_bulk_xlsx_col_index($ref);
                if ($col <= 0) continue;
                $type = isset($cell['t']) ? (string)$cell['t'] : '';
                $value = '';
                if ($type === 's') {
                    $idx = isset($cell->v) ? (int)$cell->v : -1;
                    $value = ($idx >= 0 && isset($sharedStrings[$idx])) ? $sharedStrings[$idx] : '';
                } else if ($type === 'inlineStr') {
                    if (isset($cell->is) && isset($cell->is->t)) $value = (string)$cell->is->t;
                } else {
                    $value = isset($cell->v) ? (string)$cell->v : '';
                }
                $cells[$col] = $value;
            }
        }
        $rows[$rowNo] = $cells;
    }
    $zip->close();
    $result['rows'] = $rows;
    $result['sheet_name'] = isset($target['name']) ? (string)$target['name'] : '';
    return $result;
}

function material_bulk_xlsx_cell($rows, $rowNo, $colNo)
{
    if (!isset($rows[$rowNo]) || !is_array($rows[$rowNo])) return '';
    return isset($rows[$rowNo][$colNo]) ? (string)$rows[$rowNo][$colNo] : '';
}

function material_bulk_normalize_header($value)
{
    return preg_replace('/\s+/u', '', trim((string)$value));
}

function material_bulk_parse_preview_rows($pdo, $projectId, $ym, $filePath, &$meta, &$error)
{
    $meta = array('sheet_name'=>'', 'used_fallback'=>0, 'normal_count'=>0, 'excluded_count'=>0, 'error_count'=>0, 'duplicate_count'=>0);
    $error = '';
    $book = material_bulk_xlsx_read_sheet($filePath, '3.구매,자재,경비', 3000);
    if ($book['error'] !== '') {
        $error = $book['error'];
        return array();
    }
    $rows = isset($book['rows']) && is_array($book['rows']) ? $book['rows'] : array();
    $meta['sheet_name'] = isset($book['sheet_name']) ? (string)$book['sheet_name'] : '';
    $meta['used_fallback'] = isset($book['used_fallback']) ? (int)$book['used_fallback'] : 0;

    $expected = array(1=>'일', 2=>'구분', 3=>'선급여부', 4=>'업체명', 5=>'내역', 6=>'대표자명', 7=>'전화번호', 8=>'사업자등록번호', 9=>'공급가액', 10=>'비고');
    $missing = array();
    foreach ($expected as $col => $label) {
        $header = material_bulk_normalize_header(material_bulk_xlsx_cell($rows, 3, $col));
        if ($header !== material_bulk_normalize_header($label)) {
            $missing[count($missing)] = chr(64 + $col) . '열 ' . $label;
        }
    }
    if (count($missing) > 0) {
        $error = '3행에서 필요한 헤더를 찾지 못했습니다: ' . implode(', ', $missing);
        return array();
    }

    $previewRows = array();
    $seen = array();
    $seenUsage = array();
    $maxRow = 0;
    foreach ($rows as $rowNo => $rowData) {
        if ((int)$rowNo > $maxRow) $maxRow = (int)$rowNo;
    }
    for ($rowNo = 4; $rowNo <= $maxRow; $rowNo++) {
        $hadFormulaError = false;
        $rawDay = material_bulk_clean_cell(material_bulk_xlsx_cell($rows, $rowNo, 1), $hadFormulaError);
        $rawCategory = material_bulk_clean_cell(material_bulk_xlsx_cell($rows, $rowNo, 2), $hadFormulaError);
        $rawAdvance = material_bulk_clean_cell(material_bulk_xlsx_cell($rows, $rowNo, 3), $hadFormulaError);
        $vendorName = material_bulk_clean_cell(material_bulk_xlsx_cell($rows, $rowNo, 4), $hadFormulaError);
        $detail = material_bulk_clean_cell(material_bulk_xlsx_cell($rows, $rowNo, 5), $hadFormulaError);
        $representative = material_bulk_clean_cell(material_bulk_xlsx_cell($rows, $rowNo, 6), $hadFormulaError);
        $phone = material_bulk_clean_cell(material_bulk_xlsx_cell($rows, $rowNo, 7), $hadFormulaError);
        $bizNo = material_bulk_clean_cell(material_bulk_xlsx_cell($rows, $rowNo, 8), $hadFormulaError);
        $rawAmount = material_bulk_clean_cell(material_bulk_xlsx_cell($rows, $rowNo, 9), $hadFormulaError);
        $remark = material_bulk_clean_cell(material_bulk_xlsx_cell($rows, $rowNo, 10), $hadFormulaError);

        if ($rawDay === '' && $rawCategory === '' && $rawAmount === '') continue;

        $category = material_bulk_normalize_category($rawCategory);
        $advanceYn = material_bulk_normalize_advance($rawAdvance);
        $errors = array();
        $statusType = 'normal';
        $saveable = 1;

        $dateMessage = '';
        $dayNumber = 0;
        $useDate = material_bulk_day_to_date($rawDay, $ym, $dateMessage, $dayNumber);
        if ($useDate === '') $errors[count($errors)] = $dateMessage;
        if ($category === '') {
            $errors[count($errors)] = '구분이 없습니다.';
        } else if ($category === '안전관리비') {
            $statusType = 'excluded';
            $saveable = 0;
        } else if ($category !== '자재비' && $category !== '구매품' && $category !== '기타경비') {
            $errors[count($errors)] = '허용되지 않은 구분입니다.';
        }
        $amount = material_bulk_parse_money($rawAmount);
        if ($amount <= 0) $errors[count($errors)] = '공급가액은 0원보다 커야 합니다.';

        $row = array(
            'row_no'=>$rowNo,
            'raw_day'=>$rawDay,
            'day_number'=>$dayNumber,
            'use_date'=>$useDate,
            'category'=>$category,
            'advance_yn'=>$advanceYn,
            'vendor_name'=>$vendorName,
            'detail'=>$detail,
            'representative'=>$representative,
            'phone'=>$phone,
            'biz_no'=>$bizNo,
            'amount'=>$amount,
            'remark'=>$remark,
            'status_type'=>$statusType,
            'status'=>'정상',
            'saveable'=>$saveable
        );

        if ($statusType === 'excluded') {
            $row['status'] = '제외 - 안전관리비는 안전섹션에서 등록';
            $meta['excluded_count']++;
        } else if (count($errors) > 0) {
            $row['status_type'] = 'error';
            $row['status'] = '오류 - ' . implode(' / ', $errors);
            $row['saveable'] = 0;
            $meta['error_count']++;
        } else {
            $dupKey = material_bulk_duplicate_key($row);
            $usageKey = material_bulk_usage_unique_key($row);
            if (isset($seen[$dupKey])) {
                $row['status_type'] = 'duplicate';
                $row['status'] = '중복 - 미리보기 내 중복';
                $row['saveable'] = 0;
                $meta['duplicate_count']++;
            } else if (isset($seenUsage[$usageKey])) {
                $row['status_type'] = 'duplicate';
                $row['status'] = '중복 - 같은 자재/일자 미리보기 중복';
                $row['saveable'] = 0;
                $meta['duplicate_count']++;
            } else if (material_bulk_existing_duplicate($pdo, $projectId, $row, true)) {
                $row['status_type'] = 'duplicate';
                $row['status'] = '중복 - 이미 등록된 자료';
                $row['saveable'] = 0;
                $meta['duplicate_count']++;
            } else if (material_bulk_existing_duplicate($pdo, $projectId, $row, false)) {
                $row['status_type'] = 'duplicate';
                $row['status'] = '중복 - 같은 자재/일자 기존 사용내역';
                $row['saveable'] = 0;
                $meta['duplicate_count']++;
            } else {
                $seen[$dupKey] = true;
                $seenUsage[$usageKey] = true;
                if ($hadFormulaError) {
                    $row['status'] = '정상 - 엑셀 수식 오류값은 빈 값 처리';
                }
                $meta['normal_count']++;
            }
        }
        $previewRows[count($previewRows)] = $row;
    }
    return $previewRows;
}

function material_bulk_preview_action($pdo, $projectId, $fallbackYm)
{
    $bulkYm = isset($_POST['bulk_ym']) ? trim((string)$_POST['bulk_ym']) : '';
    if (!material_bulk_valid_ym($bulkYm)) {
        flash_set('error', '등록할 년/월을 먼저 선택해주세요.');
        header('Location: ' . material_bulk_redirect_url($projectId, $fallbackYm, ''));
        exit;
    }
    if (!isset($_FILES['bulk_xlsx']) || !is_array($_FILES['bulk_xlsx'])) {
        flash_set('error', '업로드할 엑셀 파일을 선택해주세요.');
        header('Location: ' . material_bulk_redirect_url($projectId, $bulkYm, ''));
        exit;
    }
    $file = $_FILES['bulk_xlsx'];
    $errorCode = isset($file['error']) ? (int)$file['error'] : 4;
    $tmpName = isset($file['tmp_name']) ? (string)$file['tmp_name'] : '';
    $originalName = isset($file['name']) ? (string)$file['name'] : '';
    if ($errorCode !== UPLOAD_ERR_OK || $tmpName === '' || !is_uploaded_file($tmpName)) {
        flash_set('error', '엑셀 파일 업로드에 실패했습니다.');
        header('Location: ' . material_bulk_redirect_url($projectId, $bulkYm, ''));
        exit;
    }
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($ext !== 'xlsx') {
        flash_set('error', '자재구입비 일괄등록은 .xlsx 파일만 업로드할 수 있습니다.');
        header('Location: ' . material_bulk_redirect_url($projectId, $bulkYm, ''));
        exit;
    }

    $meta = array();
    $parseError = '';
    $rows = material_bulk_parse_preview_rows($pdo, $projectId, $bulkYm, $tmpName, $meta, $parseError);
    if ($parseError !== '') {
        flash_set('error', $parseError);
        header('Location: ' . material_bulk_redirect_url($projectId, $bulkYm, ''));
        exit;
    }
    if (count($rows) === 0) {
        flash_set('error', '엑셀에서 등록할 자재구입비 행을 찾지 못했습니다.');
        header('Location: ' . material_bulk_redirect_url($projectId, $bulkYm, ''));
        exit;
    }

    $token = substr(md5(uniqid('', true)), 0, 20);
    if (!isset($_SESSION['material_bulk_preview']) || !is_array($_SESSION['material_bulk_preview'])) {
        $_SESSION['material_bulk_preview'] = array();
    }
    $_SESSION['material_bulk_preview'][$token] = array(
        'project_id'=>(int)$projectId,
        'ym'=>$bulkYm,
        'rows'=>$rows,
        'meta'=>$meta,
        'original_name'=>$originalName,
        'created_at'=>time()
    );
    flash_set('success', '엑셀 미리보기를 만들었습니다. 정상 ' . (int)$meta['normal_count'] . '건 / 제외 ' . (int)$meta['excluded_count'] . '건 / 오류 ' . (int)$meta['error_count'] . '건 / 중복 ' . (int)$meta['duplicate_count'] . '건');
    header('Location: ' . material_bulk_redirect_url($projectId, $bulkYm, $token));
    exit;
}

function material_bulk_save_row($pdo, $projectId, $row, $now)
{
    $category = material_bulk_normalize_category(isset($row['category']) ? $row['category'] : '');
    if ($category === '안전관리비' || ($category !== '자재비' && $category !== '구매품' && $category !== '기타경비')) return 0;

    $vendorName = trim((string)(isset($row['vendor_name']) ? $row['vendor_name'] : ''));
    $representative = trim((string)(isset($row['representative']) ? $row['representative'] : ''));
    $phone = trim((string)(isset($row['phone']) ? $row['phone'] : ''));
    $bizNo = trim((string)(isset($row['biz_no']) ? $row['biz_no'] : ''));
    $amount = isset($row['amount']) ? (float)$row['amount'] : 0.0;
    $remark = trim((string)(isset($row['remark']) ? $row['remark'] : ''));
    $detail = trim((string)(isset($row['detail']) ? $row['detail'] : ''));
    $advanceYn = material_bulk_normalize_advance(isset($row['advance_yn']) ? $row['advance_yn'] : 'N');
    $useDate = isset($row['use_date']) ? (string)$row['use_date'] : '';
    if ($useDate === '' || $amount <= 0) return 0;

    $sourceRow = array('representative'=>$representative, 'phone'=>$phone, 'biz_no'=>$bizNo, 'remark'=>$remark);
    $existingItem = cpms_find_existing_material_item($pdo, $projectId, $category, $vendorName, $bizNo, $amount);
    if ($existingItem) {
        $materialId = isset($existingItem['id']) ? (int)$existingItem['id'] : 0;
        cpms_update_material_item_fill_blanks($pdo, $materialId, $sourceRow, $now);
    } else {
        $st = $pdo->prepare("INSERT INTO cpms_material_items
            (project_id, category, vendor_name, spec, representative, phone, biz_no, base_rate, remark, is_deleted, created_at, updated_at)
            VALUES
            (:pid, :category, :vendor, '', :rep, :phone, :biz_no, :base_rate, :remark, 0, :now, :now)");
        $st->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
        $st->bindValue(':category', $category);
        $st->bindValue(':vendor', $vendorName);
        $st->bindValue(':rep', $representative);
        $st->bindValue(':phone', $phone);
        $st->bindValue(':biz_no', $bizNo);
        $st->bindValue(':base_rate', $amount);
        $st->bindValue(':remark', $remark);
        $st->bindValue(':now', $now);
        $st->execute();
        $materialId = (int)$pdo->lastInsertId();
    }
    if ($materialId <= 0) return 0;

    if ($vendorName !== '') {
        try {
            $stPreset = $pdo->prepare("INSERT INTO cpms_material_vendor_presets (vendor_name, category, representative, phone, biz_no, base_rate, remark, created_at, updated_at) VALUES (:vendor, :category, :rep, :phone, :biz_no, :base_rate, :remark, :now, :now) ON DUPLICATE KEY UPDATE category=VALUES(category), representative=VALUES(representative), phone=VALUES(phone), biz_no=VALUES(biz_no), base_rate=VALUES(base_rate), remark=VALUES(remark), updated_at=VALUES(updated_at)");
            $stPreset->bindValue(':vendor', $vendorName);
            $stPreset->bindValue(':category', $category);
            $stPreset->bindValue(':rep', $representative);
            $stPreset->bindValue(':phone', $phone);
            $stPreset->bindValue(':biz_no', $bizNo);
            $stPreset->bindValue(':base_rate', $amount);
            $stPreset->bindValue(':remark', $remark);
            $stPreset->bindValue(':now', $now);
            $stPreset->execute();
        } catch (Exception $e) {}
    }

    $hasAdvance = cpms_material_usage_column_exists($pdo, 'advance_yn');
    if ($hasAdvance) {
        $stU = $pdo->prepare("INSERT INTO cpms_material_usage
            (project_id, material_id, use_date, amount, advance_yn, memo, created_at)
            VALUES
            (:pid, :mid, :use_date, :amount, :advance_yn, :memo, :created_at)
            ON DUPLICATE KEY UPDATE amount = VALUES(amount), advance_yn = VALUES(advance_yn), memo = VALUES(memo)");
    } else {
        $stU = $pdo->prepare("INSERT INTO cpms_material_usage
            (project_id, material_id, use_date, amount, memo, created_at)
            VALUES
            (:pid, :mid, :use_date, :amount, :memo, :created_at)
            ON DUPLICATE KEY UPDATE amount = VALUES(amount), memo = VALUES(memo)");
    }
    $stU->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
    $stU->bindValue(':mid', (int)$materialId, PDO::PARAM_INT);
    $stU->bindValue(':use_date', $useDate);
    $stU->bindValue(':amount', $amount);
    if ($hasAdvance) $stU->bindValue(':advance_yn', $advanceYn);
    $stU->bindValue(':memo', $detail);
    $stU->bindValue(':created_at', $now);
    $stU->execute();
    return 1;
}

function material_bulk_apply_action($pdo, $projectId, $fallbackYm)
{
    $token = isset($_POST['bulk_token']) ? trim((string)$_POST['bulk_token']) : '';
    if ($token === '' || !isset($_SESSION['material_bulk_preview'][$token]) || !is_array($_SESSION['material_bulk_preview'][$token])) {
        flash_set('error', '미리보기 데이터가 만료되었습니다. 엑셀을 다시 업로드해주세요.');
        header('Location: ' . material_bulk_redirect_url($projectId, $fallbackYm, ''));
        exit;
    }
    $preview = $_SESSION['material_bulk_preview'][$token];
    if ((int)$preview['project_id'] !== (int)$projectId) {
        flash_set('error', '프로젝트 정보가 일치하지 않습니다.');
        header('Location: ' . material_bulk_redirect_url($projectId, $fallbackYm, ''));
        exit;
    }
    $bulkYm = isset($preview['ym']) ? (string)$preview['ym'] : $fallbackYm;
    $postedRows = isset($_POST['rows']) && is_array($_POST['rows']) ? $_POST['rows'] : array();
    $previewRows = isset($preview['rows']) && is_array($preview['rows']) ? $preview['rows'] : array();
    $saved = 0;
    $skipped = 0;
    $errors = 0;
    $now = date('Y-m-d H:i:s');
    $applySeen = array();

    foreach ($previewRows as $idx => $baseRow) {
        if (!is_array($baseRow) || !isset($baseRow['saveable']) || (int)$baseRow['saveable'] !== 1) {
            $skipped++;
            continue;
        }
        if (!isset($postedRows[$idx]) || !is_array($postedRows[$idx]) || !isset($postedRows[$idx]['include'])) {
            $skipped++;
            continue;
        }
        $postRow = $postedRows[$idx];
        $row = $baseRow;
        foreach (array('category', 'advance_yn', 'vendor_name', 'detail', 'representative', 'phone', 'biz_no', 'remark') as $field) {
            if (isset($postRow[$field])) $row[$field] = trim((string)$postRow[$field]);
        }
        if (isset($postRow['amount'])) $row['amount'] = material_bulk_parse_money($postRow['amount']);
        $row['category'] = material_bulk_normalize_category(isset($row['category']) ? $row['category'] : '');
        $row['advance_yn'] = material_bulk_normalize_advance(isset($row['advance_yn']) ? $row['advance_yn'] : 'N');

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', isset($row['use_date']) ? $row['use_date'] : '')) {
            $errors++;
            continue;
        }
        if ($row['category'] === '안전관리비' || ($row['category'] !== '자재비' && $row['category'] !== '구매품' && $row['category'] !== '기타경비')) {
            $skipped++;
            continue;
        }
        if ((float)$row['amount'] <= 0) {
            $errors++;
            continue;
        }
        $usageKey = material_bulk_usage_unique_key($row);
        if (isset($applySeen[$usageKey])) {
            $skipped++;
            continue;
        }
        if (material_bulk_existing_duplicate($pdo, $projectId, $row, true) || material_bulk_existing_duplicate($pdo, $projectId, $row, false)) {
            $skipped++;
            continue;
        }
        try {
            $saved += material_bulk_save_row($pdo, $projectId, $row, $now);
            $applySeen[$usageKey] = true;
        } catch (Exception $e) {
            $errors++;
        }
    }

    unset($_SESSION['material_bulk_preview'][$token]);
    flash_set('success', '자재구입비 일괄등록 완료: 저장 ' . (int)$saved . '건 / 제외·중복 ' . (int)$skipped . '건 / 오류 ' . (int)$errors . '건');
    header('Location: ' . material_bulk_redirect_url($projectId, $bulkYm, ''));
    exit;
}
$pdo = Db::pdo();
if (!$pdo) {
    flash_set('error', 'DB 연결 실패');
    header('Location: ' . $redirect);
    exit;
}
cpms_material_usage_ensure_schema($pdo);
$hasMaterialAdvanceYn = cpms_material_usage_column_exists($pdo, 'advance_yn');

try {
    $now = date('Y-m-d H:i:s');
    $sourceRow = array(
        'representative' => $representative,
        'phone' => $phone,
        'biz_no' => $bizNo,
        'remark' => $remark
    );
    $existingItem = cpms_find_existing_material_item($pdo, $projectId, $category, $vendorName, $bizNo, $baseRate);
    $isReused = false;
    if ($existingItem) {
        $materialId = isset($existingItem['id']) ? (int)$existingItem['id'] : 0;
        cpms_update_material_item_fill_blanks($pdo, $materialId, $sourceRow, $now);
        $isReused = ($materialId > 0);
    } else {
        $st = $pdo->prepare("INSERT INTO cpms_material_items
            (project_id, category, vendor_name, spec, representative, phone, biz_no, base_rate, remark, is_deleted, created_at, updated_at)
            VALUES
            (:pid, :category, :vendor, :spec, :rep, :phone, :biz_no, :base_rate, :remark, 0, :now, :now)");
        $st->bindValue(':pid', $projectId, PDO::PARAM_INT);
        $st->bindValue(':category', $category);
        $st->bindValue(':vendor', $vendorName);
        $st->bindValue(':spec', $spec);
        $st->bindValue(':rep', $representative);
        $st->bindValue(':phone', $phone);
        $st->bindValue(':biz_no', $bizNo);
        $st->bindValue(':base_rate', $baseRate);
        $st->bindValue(':remark', $remark);
        $st->bindValue(':now', $now);
        $st->execute();
        $materialId = (int)$pdo->lastInsertId();
    }

    // 공용 업체 프리셋 저장
    $stPreset = $pdo->prepare("INSERT INTO cpms_material_vendor_presets (vendor_name, category, representative, phone, biz_no, base_rate, remark, created_at, updated_at) VALUES (:vendor, :category, :rep, :phone, :biz_no, :base_rate, :remark, :now, :now) ON DUPLICATE KEY UPDATE category=VALUES(category), representative=VALUES(representative), phone=VALUES(phone), biz_no=VALUES(biz_no), base_rate=VALUES(base_rate), remark=VALUES(remark), updated_at=VALUES(updated_at)");
    $stPreset->bindValue(':vendor', $vendorName);
    $stPreset->bindValue(':category', $category);
    $stPreset->bindValue(':rep', $representative);
    $stPreset->bindValue(':phone', $phone);
    $stPreset->bindValue(':biz_no', $bizNo);
    $stPreset->bindValue(':base_rate', $baseRate);
    $stPreset->bindValue(':remark', $remark);
    $stPreset->bindValue(':now', $now);
    $stPreset->execute();

    $dates = material_collect_usage_dates($usageDates, $useDatesText, $ym);
    foreach ($dates as $d) {
        if (!material_is_in_month_range($d, $ym)) {
            $range = material_month_range($ym);
            flash_set('error', '사용일자는 ' . $range['start'] . ' ~ ' . $range['end'] . ' 범위만 저장할 수 있습니다.');
            header('Location: ' . $redirect);
            exit;
        }
    }
    if ($materialId > 0 && count($dates) > 0) {
        if ($hasMaterialAdvanceYn) {
            $stU = $pdo->prepare("INSERT INTO cpms_material_usage
                (project_id, material_id, use_date, amount, advance_yn, memo, created_at)
                VALUES
                (:pid, :eid, :d, :amt, :advance_yn, :memo, :created_at)
                ON DUPLICATE KEY UPDATE amount = VALUES(amount), advance_yn = VALUES(advance_yn), memo = VALUES(memo)");
        } else {
            $stU = $pdo->prepare("INSERT INTO cpms_material_usage
                (project_id, material_id, use_date, amount, memo, created_at)
                VALUES
                (:pid, :eid, :d, :amt, :memo, :created_at)
                ON DUPLICATE KEY UPDATE amount = VALUES(amount), memo = VALUES(memo)");
        }
        $stFindUsage = $pdo->prepare("SELECT id, use_date FROM cpms_material_usage WHERE project_id = :pid AND material_id = :mid AND use_date = :d LIMIT 1");
        $savedUsageRows = array();
        foreach ($dates as $d) {
            $stU->bindValue(':pid', $projectId, PDO::PARAM_INT);
            $stU->bindValue(':eid', $materialId, PDO::PARAM_INT);
            $stU->bindValue(':d', $d);
            $stU->bindValue(':amt', $baseRate);
            if ($hasMaterialAdvanceYn) $stU->bindValue(':advance_yn', $advanceYn);
            $stU->bindValue(':memo', '');
            $stU->bindValue(':created_at', $now);
            $stU->execute();

            $stFindUsage->bindValue(':pid', $projectId, PDO::PARAM_INT);
            $stFindUsage->bindValue(':mid', $materialId, PDO::PARAM_INT);
            $stFindUsage->bindValue(':d', $d);
            $stFindUsage->execute();
            $usageRow = $stFindUsage->fetch(PDO::FETCH_ASSOC);
            if (is_array($usageRow) && isset($usageRow['id'])) {
                $savedUsageRows[count($savedUsageRows)] = $usageRow;
            }
        }
    } else {
        $savedUsageRows = array();
    }

    if ($isReused) {
        $baseMessage = '기존 자재구입비에 사용일자를 추가했습니다.';
    } else {
        $baseMessage = '새 자재구입비를 등록했습니다.';
    }

    $uploadResult = cpms_material_statement_store_uploaded_file_for_usage_rows($pdo, 'statement_file', $projectId, $materialId, $savedUsageRows, $ym);
    if (isset($uploadResult['has_file']) && $uploadResult['has_file']) {
        if (isset($uploadResult['ok']) && $uploadResult['ok']) {
            flash_set('success', $baseMessage . ' 거래명세표를 첨부했습니다.');
        } else {
            flash_set('error', $baseMessage . ' 다만 거래명세표 첨부 실패: ' . (isset($uploadResult['message']) ? $uploadResult['message'] : '알 수 없는 오류'));
        }
    } else {
        flash_set('success', $baseMessage);
    }
    header('Location: ' . $redirect);
    exit;
} catch (Exception $e) {
    flash_set('error', '저장 실패: ' . $e->getMessage());
    header('Location: ' . $redirect);
    exit;
}
