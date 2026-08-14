<?php
/** Management vendor XLSX/XLS import. PHP 5.6 compatible. */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../core/SimpleXlsxReader.php';
require_once __DIR__ . '/../../core/SimpleXlsReader.php';
require_once __DIR__ . '/../../services/VendorService.php';

use App\Core\Auth;
use App\Core\Db;
use App\Core\SimpleXlsxReader;
use App\Core\SimpleXlsReader;
use App\Services\VendorService;

if (!Auth::check()) { header('Location: ?r=login'); exit; }
if (!Auth::isMaster() && !Auth::canManageEmployees()) { http_response_code(403); echo '403 Forbidden'; exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'Method Not Allowed'; exit; }
if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
    flash_set('error', '보안 토큰이 유효하지 않습니다.');
    header('Location: ?r=관리&tab=vendors&import=1');
    exit;
}

function cpms_vendor_import_finish($type, $message)
{
    flash_set($type, $message);
    header('Location: ?r=관리&tab=vendors&import=1');
    exit;
}

function cpms_vendor_import_header_key($value)
{
    $value = trim((string)$value);
    $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);
    $value = preg_replace('/[^0-9A-Za-z가-힣]+/u', '', $value);
    return $value;
}

function cpms_vendor_import_header_field($value, $aliases)
{
    $key = cpms_vendor_import_header_key($value);
    if ($key === '') return '';
    if (isset($aliases[$key])) return $aliases[$key];
    foreach (array('업체명','거래처명','회사명','공급업체명','거래업체명','상호명') as $nameKey) {
        if (strpos($key, $nameKey) !== false) return 'name';
    }
    if (strpos($key, '사업자등록번호') !== false || strpos($key, '사업자번호') !== false) return 'business_no';
    if (strpos($key, '대표자') !== false) return 'representative';
    if (strpos($key, '전화번호') !== false || strpos($key, '연락처') !== false) return 'phone';
    if (strpos($key, '계좌번호') !== false) return 'account_number';
    if (strpos($key, '예금주') !== false) return 'account_holder';
    if (strpos($key, '은행') !== false) return 'bank_name';
    if (strpos($key, '업체내역') !== false || $key === '내역' || strpos($key, '업종') !== false || strpos($key, '업태') !== false) return 'description';
    return '';
}

function cpms_vendor_import_find_header($sheets, $aliases)
{
    $result = array('sheet_index'=>-1,'row_index'=>-1,'map'=>array(),'rows'=>array(),'samples'=>array());
    foreach ($sheets as $sheetOffset => $sheet) {
        $sheetRows = isset($sheet['rows']) && is_array($sheet['rows']) ? $sheet['rows'] : array();
        foreach ($sheetRows as $rowIndex => $row) {
            if ($rowIndex > 49) break;
            if (!is_array($row)) continue;
            $candidateMap = array();
            foreach ($row as $columnIndex => $heading) {
                $heading = trim((string)$heading);
                if ($heading !== '' && count($result['samples']) < 12) {
                    $sample = function_exists('mb_substr') ? mb_substr($heading, 0, 40, 'UTF-8') : substr($heading, 0, 80);
                    $result['samples'][] = $sample;
                }
                $field = cpms_vendor_import_header_field($heading, $aliases);
                if ($field !== '' && !in_array($field, $candidateMap, true)) $candidateMap[(int)$columnIndex] = $field;
            }
            if (in_array('name', $candidateMap, true)) {
                $result['sheet_index'] = (int)$sheetOffset;
                $result['row_index'] = (int)$rowIndex;
                $result['map'] = $candidateMap;
                $result['rows'] = $sheetRows;
                return $result;
            }
        }
    }
    return $result;
}

if (!isset($_FILES['vendor_excel']) || !is_array($_FILES['vendor_excel'])) {
    cpms_vendor_import_finish('error', '업로드할 엑셀 파일을 선택해주세요.');
}
$file = $_FILES['vendor_excel'];
$errorCode = isset($file['error']) ? (int)$file['error'] : UPLOAD_ERR_NO_FILE;
$tmpName = isset($file['tmp_name']) ? (string)$file['tmp_name'] : '';
$originalName = isset($file['name']) ? basename((string)$file['name']) : '';
$fileSize = isset($file['size']) ? (int)$file['size'] : 0;
$extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
if ($errorCode !== UPLOAD_ERR_OK || $tmpName === '' || !is_uploaded_file($tmpName)) {
    cpms_vendor_import_finish('error', '엑셀 파일 업로드에 실패했습니다.');
}
if ($extension !== 'xlsx' && $extension !== 'xls') {
    cpms_vendor_import_finish('error', '.xlsx 또는 .xls 파일만 업로드할 수 있습니다.');
}
if ($fileSize <= 0 || $fileSize > (10 * 1024 * 1024)) {
    cpms_vendor_import_finish('error', '엑셀 파일은 10MB 이하만 업로드할 수 있습니다.');
}

$read = $extension === 'xls' ? SimpleXlsReader::readFirstSheet($tmpName, 2050) : SimpleXlsxReader::readFirstSheet($tmpName, 2050);
if (!is_array($read) || !empty($read['error'])) {
    cpms_vendor_import_finish('error', is_array($read) && isset($read['error']) ? (string)$read['error'] : '엑셀 파일을 읽지 못했습니다.');
}
$rows = isset($read['rows']) && is_array($read['rows']) ? $read['rows'] : array();
$sheets = array(array('index'=>1,'rows'=>$rows));
if ($extension === 'xlsx' && method_exists('App\\Core\\SimpleXlsxReader', 'readWorksheets')) {
    $workbookRead = SimpleXlsxReader::readWorksheets($tmpName, 2050, 20);
    if (is_array($workbookRead) && empty($workbookRead['error']) && isset($workbookRead['sheets']) && is_array($workbookRead['sheets'])) $sheets = $workbookRead['sheets'];
}
$headerAliases = array(
    '사업자등록번호'=>'business_no','사업자번호'=>'business_no',
    '업체명'=>'name','상호'=>'name','상호명'=>'name','거래처명'=>'name','회사명'=>'name','공급업체명'=>'name','거래업체명'=>'name',
    '내역'=>'description','업체내역'=>'description','업종'=>'description','업태'=>'description',
    '대표자명'=>'representative','대표자'=>'representative',
    '전화번호'=>'phone','연락처'=>'phone','업체전화번호'=>'phone',
    '은행'=>'bank_name','은행명'=>'bank_name',
    '계좌번호'=>'account_number','예금주'=>'account_holder'
);
$headerResult = cpms_vendor_import_find_header($sheets, $headerAliases);
$headerMap = isset($headerResult['map']) ? $headerResult['map'] : array();
$headerRowIndex = isset($headerResult['row_index']) ? (int)$headerResult['row_index'] : -1;
$rows = isset($headerResult['rows']) && is_array($headerResult['rows']) ? $headerResult['rows'] : array();
if ($headerRowIndex < 0 || !in_array('name', $headerMap, true)) {
    $samples = isset($headerResult['samples']) && is_array($headerResult['samples']) ? array_unique($headerResult['samples']) : array();
    $sampleText = count($samples) > 0 ? ' 읽힌 셀 예시: ' . implode(' / ', array_slice($samples, 0, 8)) : '';
    cpms_vendor_import_finish('error', '엑셀 시트 앞 50행에서 업체명 열을 찾을 수 없습니다.' . $sampleText);
}

$importRows = array();
foreach ($rows as $rowIndex => $row) {
    if ((int)$rowIndex <= $headerRowIndex || !is_array($row)) continue;
    $data = array('business_no'=>'','name'=>'','description'=>'','representative'=>'','phone'=>'','bank_name'=>'','account_number'=>'','account_holder'=>'');
    $hasValue = false;
    foreach ($headerMap as $columnIndex => $field) {
        $value = isset($row[$columnIndex]) ? trim((string)$row[$columnIndex]) : '';
        if ($value !== '') $hasValue = true;
        $data[$field] = $value;
    }
    if ($hasValue) $importRows[] = $data;
    if (count($importRows) >= 2000) break;
}
if (count($importRows) === 0) cpms_vendor_import_finish('error', '등록할 업체 데이터가 없습니다.');

$pdo = Db::pdo();
if (!$pdo) cpms_vendor_import_finish('error', 'DB 연결에 실패했습니다.');
VendorService::bootstrap($pdo, true);
$summary = VendorService::importVendorRows($pdo, $importRows, array(
    'name'=>(string)Auth::userName(),
    'email'=>(string)Auth::userEmail()
));
if (empty($summary['ok'])) cpms_vendor_import_finish('error', '업체 엑셀 자료를 반영하지 못했습니다.');
$message = '업체 엑셀 반영 완료: 신규 ' . (int)$summary['created'] . '건, 갱신 ' . (int)$summary['updated'] . '건, 건너뜀 ' . (int)$summary['skipped'] . '건';
if (!empty($summary['errors']) && is_array($summary['errors'])) $message .= ' / ' . implode(' | ', $summary['errors']);
cpms_vendor_import_finish('success', $message);
