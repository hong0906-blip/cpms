<?php
/**
 * 출퇴근 근태관리 월간/주간 현황 XLSX 다운로드
 * - 화면과 동일한 조회 데이터/상태 판정을 재사용
 * - PHP 5.6 호환, ZipArchive 기반 XLSX 생성
 */

$cpmsAttendanceExportBufferStarted = false;
if (function_exists('ob_start')) {
    $cpmsAttendanceExportBufferStarted = @ob_start();
}

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../attendance/common.php';

use App\Core\Auth;

if (!Auth::check()) {
    header('Location: ?r=login');
    exit;
}

$canExportAttendance = (Auth::isMaster() || attendance_is_manager() || Auth::userRole() === 'executive');
if (!$canExportAttendance) {
    http_response_code(403);
    echo '403 Forbidden';
    exit;
}

$exportTab = isset($_GET['atab']) ? trim((string)$_GET['atab']) : 'monthly';
if ($exportTab !== 'weekly') $exportTab = 'monthly';
$_GET['atab'] = $exportTab;

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    echo '서버에 ZipArchive 확장 모듈이 없어 엑셀 파일을 만들 수 없습니다.';
    exit;
}

$cpmsAttendanceDataOnly = true;
$cpmsAttendanceEmbeddedInExecutiveDashboard = (Auth::userRole() === 'executive');
require __DIR__ . '/../admin/attendance.php';

if (!$pdo) {
    http_response_code(500);
    echo '근태 데이터를 불러오지 못했습니다.';
    exit;
}

if (!function_exists('cpms_attendance_xlsx_col')) {
function cpms_attendance_xlsx_col($index)
{
    $index = (int)$index;
    $letters = '';
    while ($index > 0) {
        $mod = ($index - 1) % 26;
        $letters = chr(65 + $mod) . $letters;
        $index = (int)(($index - 1) / 26);
    }
    return $letters;
}}

if (!function_exists('cpms_attendance_xlsx_xml')) {
function cpms_attendance_xlsx_xml($value)
{
    $value = (string)$value;
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value);
    $flags = ENT_QUOTES;
    if (defined('ENT_XML1')) $flags = $flags | ENT_XML1;
    if (defined('ENT_SUBSTITUTE')) $flags = $flags | ENT_SUBSTITUTE;
    return htmlspecialchars($value, $flags, 'UTF-8');
}}

if (!function_exists('cpms_attendance_xlsx_cell')) {
function cpms_attendance_xlsx_cell($row, $col, $value, $style)
{
    $ref = cpms_attendance_xlsx_col($col) . (int)$row;
    $styleAttr = ' s="' . (int)$style . '"';
    if ($value === null || $value === '') {
        return '<c r="' . $ref . '"' . $styleAttr . '/>';
    }
    return '<c r="' . $ref . '" t="inlineStr"' . $styleAttr . '><is><t xml:space="preserve">' . cpms_attendance_xlsx_xml($value) . '</t></is></c>';
}}

if (!function_exists('cpms_attendance_xlsx_row')) {
function cpms_attendance_xlsx_row($row, $cells, $height)
{
    $heightAttr = $height > 0 ? ' ht="' . (float)$height . '" customHeight="1"' : '';
    $xml = '<row r="' . (int)$row . '"' . $heightAttr . '>';
    for ($i = 0; $i < count($cells); $i++) {
        $cell = is_array($cells[$i]) ? $cells[$i] : array($cells[$i], 0);
        $value = isset($cell[0]) ? $cell[0] : '';
        $style = isset($cell[1]) ? (int)$cell[1] : 0;
        $xml .= cpms_attendance_xlsx_cell($row, $i + 1, $value, $style);
    }
    return $xml . '</row>';
}}

if (!function_exists('cpms_attendance_xlsx_filled_row')) {
function cpms_attendance_xlsx_filled_row($count, $style, $firstValue)
{
    $cells = array();
    for ($i = 0; $i < $count; $i++) {
        $cells[] = array($i === 0 ? $firstValue : '', $style);
    }
    return $cells;
}}

if (!function_exists('cpms_attendance_xlsx_status_style')) {
function cpms_attendance_xlsx_status_style($status)
{
    if ($status === 'normal') return 17;
    if ($status === 'late') return 18;
    if ($status === 'vacation') return 19;
    if ($status === 'missing_checkout') return 20;
    return 21;
}}

if (!function_exists('cpms_attendance_xlsx_status_text')) {
function cpms_attendance_xlsx_status_text($cell)
{
    if (!is_array($cell)) return '-';
    $status = isset($cell['status']) ? (string)$cell['status'] : 'none';
    if ($status === 'none') return '-';
    $lines = array();
    $checkIn = isset($cell['check_in']) ? trim((string)$cell['check_in']) : '';
    $checkOut = isset($cell['check_out']) ? trim((string)$cell['check_out']) : '';
    if ($checkIn !== '') $lines[] = $checkIn;
    if ($checkOut !== '') $lines[] = $checkOut;
    if ($checkIn !== '' && $checkOut === '' && $status !== 'vacation') $lines[] = '-';
    $lines[] = isset($cell['label']) && trim((string)$cell['label']) !== '' ? (string)$cell['label'] : '-';
    return implode("\n", $lines);
}}

$isWeeklyExport = ($exportTab === 'weekly');
$identityHeaders = $isWeeklyExport
    ? array('사번', '이름', '부서 / 직급', '근무지', '주간 누적', '출근', '지각', '휴가', '52시간')
    : array('사번', '이름', '부서 / 직급', '근무지');
$identityCount = count($identityHeaders);
$dateCount = count($monthDates);
$totalColumns = $identityCount + $dateCount;
if ($totalColumns < 10) $totalColumns = 10;
$lastColumn = cpms_attendance_xlsx_col($totalColumns);
$firstDateColumn = cpms_attendance_xlsx_col($identityCount + 1);

$periodTitle = $isWeeklyExport
    ? (($weekLabel !== '' ? $weekLabel . ' · ' : '') . $weekRangeLabel)
    : ($month . ' 월간 현황');
$titleText = '출퇴근 근태관리 · ' . ($isWeeklyExport ? '주간 현황' : '월간 현황');
$summaryCards = array(
    array('총 인원', (int)$monthlySummary['total'] . '명', 3, 4),
    array('정상 출근', (int)$monthlySummary['normal'] . $attendanceSummaryCountUnit . ' · ' . attendance_monthly_percent($monthlySummary['normal'], $attendanceSummaryDenominator), 5, 6),
    array('지각', (int)$monthlySummary['late'] . $attendanceSummaryCountUnit . ' · ' . attendance_monthly_percent($monthlySummary['late'], $attendanceSummaryDenominator), 7, 8),
    array('휴가', (int)$monthlySummary['vacation'] . $attendanceSummaryVacationUnit . ' · ' . attendance_monthly_percent($monthlySummary['vacation'], $attendanceSummaryDenominator), 9, 10),
    array('미퇴근', (int)$monthlySummary['missing_checkout'] . $attendanceSummaryCountUnit . ' · ' . attendance_monthly_percent($monthlySummary['missing_checkout'], $attendanceSummaryDenominator), 11, 12)
);

$sheetRows = '';
$mergeCells = array();

$sheetRows .= cpms_attendance_xlsx_row(1, cpms_attendance_xlsx_filled_row($totalColumns, 1, $titleText), 34);
$mergeCells[] = 'A1:' . $lastColumn . '1';
$sheetRows .= cpms_attendance_xlsx_row(2, cpms_attendance_xlsx_filled_row($totalColumns, 2, $periodTitle . ' / ' . $reportStatusDate . ' 기준'), 24);
$mergeCells[] = 'A2:' . $lastColumn . '2';
$sheetRows .= cpms_attendance_xlsx_row(3, cpms_attendance_xlsx_filled_row($totalColumns, 0, ''), 8);

$cardLabelCells = array();
$cardValueCells = array();
for ($colIndex = 1; $colIndex <= $totalColumns; $colIndex++) {
    $cardIndex = (int)floor((($colIndex - 1) * 5) / $totalColumns);
    if ($cardIndex > 4) $cardIndex = 4;
    $cardLabelCells[] = array('', $summaryCards[$cardIndex][2]);
    $cardValueCells[] = array('', $summaryCards[$cardIndex][3]);
}
for ($cardIndex = 0; $cardIndex < 5; $cardIndex++) {
    $cardStart = (int)floor(($cardIndex * $totalColumns) / 5) + 1;
    $cardEnd = (int)floor((($cardIndex + 1) * $totalColumns) / 5);
    if ($cardEnd < $cardStart) $cardEnd = $cardStart;
    $cardLabelCells[$cardStart - 1][0] = $summaryCards[$cardIndex][0];
    $cardValueCells[$cardStart - 1][0] = $summaryCards[$cardIndex][1];
    $mergeCells[] = cpms_attendance_xlsx_col($cardStart) . '4:' . cpms_attendance_xlsx_col($cardEnd) . '4';
    $mergeCells[] = cpms_attendance_xlsx_col($cardStart) . '5:' . cpms_attendance_xlsx_col($cardEnd) . '5';
}
$sheetRows .= cpms_attendance_xlsx_row(4, $cardLabelCells, 23);
$sheetRows .= cpms_attendance_xlsx_row(5, $cardValueCells, 31);
$sheetRows .= cpms_attendance_xlsx_row(6, cpms_attendance_xlsx_filled_row($totalColumns, 0, ''), 8);
$legendText = '● 정상   ● 지각   ● 휴가   ● 미퇴근    |    오늘 미퇴근은 18:00 이후부터 표시';
$sheetRows .= cpms_attendance_xlsx_row(7, cpms_attendance_xlsx_filled_row($totalColumns, 13, $legendText), 24);
$mergeCells[] = 'A7:' . $lastColumn . '7';
$sheetRows .= cpms_attendance_xlsx_row(8, cpms_attendance_xlsx_filled_row($totalColumns, 0, ''), 8);

$headerCells = array();
foreach ($identityHeaders as $headerLabel) $headerCells[] = array($headerLabel, 14);
foreach ($monthDates as $dateInfo) {
    $monthNo = isset($dateInfo['month']) ? (int)$dateInfo['month'] : (int)substr($month, 5, 2);
    $dayNo = isset($dateInfo['day']) ? (int)$dateInfo['day'] : 0;
    $weekDay = isset($dateInfo['week']) ? (string)$dateInfo['week'] : '';
    $dateLabel = $isWeeklyExport ? ($monthNo . '/' . $dayNo . '/' . $weekDay) : ($dayNo . '/' . $weekDay);
    $headerCells[] = array($dateLabel, !empty($dateInfo['weekend']) ? 15 : 14);
}
while (count($headerCells) < $totalColumns) $headerCells[] = array('', 14);
$sheetRows .= cpms_attendance_xlsx_row(9, $headerCells, 28);

$dataRow = 10;
foreach ($monthlyRows as $monthlyRow) {
    $employee = isset($monthlyRow['employee']) && is_array($monthlyRow['employee']) ? $monthlyRow['employee'] : array();
    $stats = isset($monthlyRow['stats']) && is_array($monthlyRow['stats']) ? $monthlyRow['stats'] : array();
    $employeeNo = isset($employee['employee_no']) ? trim((string)$employee['employee_no']) : '';
    $employeeName = isset($employee['name']) ? trim((string)$employee['name']) : '';
    $department = isset($employee['department']) ? trim((string)$employee['department']) : '';
    $position = isset($employee['position']) ? trim((string)$employee['position']) : '';
    $departmentPosition = $department !== '' ? $department : '-';
    if ($position !== '') $departmentPosition .= ' / ' . $position;
    $workLocation = isset($employee['work_location']) ? trim((string)$employee['work_location']) : '';
    $workMinutes = isset($stats['work_minutes']) ? max(0, (int)$stats['work_minutes']) : 0;
    $rowCells = array(
        array($employeeNo !== '' ? $employeeNo : '-', 16),
        array($employeeName !== '' ? $employeeName : '-', 16),
        array($departmentPosition, 16),
        array($workLocation !== '' ? $workLocation : '-', 16)
    );
    if ($isWeeklyExport) {
        $rowCells[] = array(attendance_hm($workMinutes), 16);
        $rowCells[] = array((isset($stats['work_days']) ? (int)$stats['work_days'] : 0) . '일', 16);
        $rowCells[] = array((isset($stats['late']) ? (int)$stats['late'] : 0) . '회', 16);
        $rowCells[] = array((isset($stats['vacation']) ? (int)$stats['vacation'] : 0) . '일', 16);
        $rowCells[] = array($workMinutes > $attendanceWeeklyLimitMinutes ? attendance_hm($workMinutes) . ' 초과' : '정상', $workMinutes > $attendanceWeeklyLimitMinutes ? 22 : 23);
    }
    foreach ($monthDates as $dateInfo) {
        $dateKey = isset($dateInfo['date']) ? (string)$dateInfo['date'] : '';
        $cell = (isset($monthlyRow['cells']) && isset($monthlyRow['cells'][$dateKey])) ? $monthlyRow['cells'][$dateKey] : array('status' => 'none');
        $status = isset($cell['status']) ? (string)$cell['status'] : 'none';
        $rowCells[] = array(cpms_attendance_xlsx_status_text($cell), cpms_attendance_xlsx_status_style($status));
    }
    while (count($rowCells) < $totalColumns) $rowCells[] = array('', 21);
    $sheetRows .= cpms_attendance_xlsx_row($dataRow, $rowCells, 58);
    $dataRow++;
}

if (count($monthlyRows) === 0) {
    $sheetRows .= cpms_attendance_xlsx_row($dataRow, cpms_attendance_xlsx_filled_row($totalColumns, 21, '조건에 맞는 근태 데이터가 없습니다.'), 36);
    $mergeCells[] = 'A' . $dataRow . ':' . $lastColumn . $dataRow;
    $dataRow++;
}
$lastDataRow = $dataRow - 1;

$columnXml = '';
$widths = $isWeeklyExport
    ? array(14, 14, 21, 13, 16, 10, 10, 10, 19)
    : array(14, 14, 21, 13);
for ($i = 0; $i < $identityCount; $i++) {
    $width = isset($widths[$i]) ? $widths[$i] : 14;
    $columnXml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $width . '" customWidth="1"/>';
}
if ($dateCount > 0) {
    $columnXml .= '<col min="' . ($identityCount + 1) . '" max="' . ($identityCount + $dateCount) . '" width="12" customWidth="1"/>';
}
if ($identityCount + $dateCount < $totalColumns) {
    $columnXml .= '<col min="' . ($identityCount + $dateCount + 1) . '" max="' . $totalColumns . '" width="12" customWidth="1"/>';
}

$mergeXml = '';
if (count($mergeCells) > 0) {
    $mergeXml = '<mergeCells count="' . count($mergeCells) . '">';
    foreach ($mergeCells as $mergeRef) $mergeXml .= '<mergeCell ref="' . $mergeRef . '"/>';
    $mergeXml .= '</mergeCells>';
}

$freezeSplit = $identityCount;
$sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
    . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
    . '<dimension ref="A1:' . $lastColumn . $lastDataRow . '"/>'
    . '<sheetViews><sheetView showGridLines="0" tabSelected="1" workbookViewId="0"><pane xSplit="' . $freezeSplit . '" ySplit="9" topLeftCell="' . $firstDateColumn . '10" activePane="bottomRight" state="frozen"/><selection pane="topRight" activeCell="' . $firstDateColumn . '1" sqref="' . $firstDateColumn . '1"/><selection pane="bottomLeft" activeCell="A10" sqref="A10"/><selection pane="bottomRight" activeCell="' . $firstDateColumn . '10" sqref="' . $firstDateColumn . '10"/></sheetView></sheetViews>'
    . '<sheetFormatPr defaultRowHeight="18"/><cols>' . $columnXml . '</cols>'
    . '<sheetData>' . $sheetRows . '</sheetData>'
    . '<autoFilter ref="A9:' . $lastColumn . $lastDataRow . '"/>' . $mergeXml
    . '<pageMargins left="0.25" right="0.25" top="0.5" bottom="0.5" header="0.2" footer="0.2"/>'
    . '<pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0" paperSize="9"/>'
    . '</worksheet>';

$styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<fonts count="10">'
    . '<font><sz val="10"/><color rgb="FF0F172A"/><name val="맑은 고딕"/></font>'
    . '<font><b/><sz val="18"/><color rgb="FFFFFFFF"/><name val="맑은 고딕"/></font>'
    . '<font><b/><sz val="10"/><color rgb="FF0F172A"/><name val="맑은 고딕"/></font>'
    . '<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="맑은 고딕"/></font>'
    . '<font><b/><sz val="15"/><color rgb="FF020617"/><name val="맑은 고딕"/></font>'
    . '<font><b/><sz val="10"/><color rgb="FF166534"/><name val="맑은 고딕"/></font>'
    . '<font><b/><sz val="10"/><color rgb="FFC2410C"/><name val="맑은 고딕"/></font>'
    . '<font><b/><sz val="10"/><color rgb="FF1D4ED8"/><name val="맑은 고딕"/></font>'
    . '<font><b/><sz val="10"/><color rgb="FFDC2626"/><name val="맑은 고딕"/></font>'
    . '<font><sz val="9"/><color rgb="FF64748B"/><name val="맑은 고딕"/></font>'
    . '</fonts>'
    . '<fills count="15">'
    . '<fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
    . '<fill><patternFill patternType="solid"><fgColor rgb="FF071A98"/><bgColor indexed="64"/></patternFill></fill>'
    . '<fill><patternFill patternType="solid"><fgColor rgb="FFF8FAFC"/><bgColor indexed="64"/></patternFill></fill>'
    . '<fill><patternFill patternType="solid"><fgColor rgb="FFEAF2FF"/><bgColor indexed="64"/></patternFill></fill>'
    . '<fill><patternFill patternType="solid"><fgColor rgb="FFDCFCE7"/><bgColor indexed="64"/></patternFill></fill>'
    . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFEDD5"/><bgColor indexed="64"/></patternFill></fill>'
    . '<fill><patternFill patternType="solid"><fgColor rgb="FFDBEAFE"/><bgColor indexed="64"/></patternFill></fill>'
    . '<fill><patternFill patternType="solid"><fgColor rgb="FFFEE2E2"/><bgColor indexed="64"/></patternFill></fill>'
    . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFFFFF"/><bgColor indexed="64"/></patternFill></fill>'
    . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFF1F2"/><bgColor indexed="64"/></patternFill></fill>'
    . '<fill><patternFill patternType="solid"><fgColor rgb="FFF0FDF4"/><bgColor indexed="64"/></patternFill></fill>'
    . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFF7ED"/><bgColor indexed="64"/></patternFill></fill>'
    . '<fill><patternFill patternType="solid"><fgColor rgb="FFEFF6FF"/><bgColor indexed="64"/></patternFill></fill>'
    . '<fill><patternFill patternType="solid"><fgColor rgb="FFF1F5F9"/><bgColor indexed="64"/></patternFill></fill>'
    . '</fills>'
    . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color rgb="FFE5E7EB"/></left><right style="thin"><color rgb="FFE5E7EB"/></right><top style="thin"><color rgb="FFE5E7EB"/></top><bottom style="thin"><color rgb="FFE5E7EB"/></bottom><diagonal/></border></borders>'
    . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
    . '<cellXfs count="24">'
    . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
    . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
    . '<xf numFmtId="0" fontId="2" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
    . '<xf numFmtId="0" fontId="7" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
    . '<xf numFmtId="0" fontId="4" fillId="9" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
    . '<xf numFmtId="0" fontId="5" fillId="5" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
    . '<xf numFmtId="0" fontId="4" fillId="9" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
    . '<xf numFmtId="0" fontId="6" fillId="6" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
    . '<xf numFmtId="0" fontId="4" fillId="9" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
    . '<xf numFmtId="0" fontId="7" fillId="7" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
    . '<xf numFmtId="0" fontId="4" fillId="9" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
    . '<xf numFmtId="0" fontId="8" fillId="8" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
    . '<xf numFmtId="0" fontId="4" fillId="9" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
    . '<xf numFmtId="0" fontId="9" fillId="9" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
    . '<xf numFmtId="0" fontId="3" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
    . '<xf numFmtId="0" fontId="8" fillId="10" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
    . '<xf numFmtId="0" fontId="2" fillId="9" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
    . '<xf numFmtId="0" fontId="5" fillId="11" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
    . '<xf numFmtId="0" fontId="6" fillId="12" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
    . '<xf numFmtId="0" fontId="7" fillId="13" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
    . '<xf numFmtId="0" fontId="8" fillId="10" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
    . '<xf numFmtId="0" fontId="9" fillId="9" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
    . '<xf numFmtId="0" fontId="8" fillId="10" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
    . '<xf numFmtId="0" fontId="5" fillId="11" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
    . '</cellXfs>'
    . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
    . '</styleSheet>';

$sheetName = $isWeeklyExport ? '주간 현황' : '월간 현황';
$workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
    . '<fileVersion appName="xl" lastEdited="7" lowestEdited="7" rupBuild="0"/>'
    . '<workbookPr defaultThemeVersion="166925"/>'
    . '<bookViews><workbookView xWindow="0" yWindow="0" windowWidth="24000" windowHeight="12000"/></bookViews>'
    . '<sheets><sheet name="' . cpms_attendance_xlsx_xml($sheetName) . '" sheetId="1" state="visible" r:id="rId1"/></sheets>'
    . '<calcPr calcId="191029" fullCalcOnLoad="1"/></workbook>';

$relsRoot = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
    . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
    . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
    . '</Relationships>';

$workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
    . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
    . '</Relationships>';

$contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    . '<Default Extension="xml" ContentType="application/xml"/>'
    . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
    . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
    . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
    . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
    . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
    . '</Types>';

$createdAt = gmdate('Y-m-d\TH:i:s\Z');
$coreProperties = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
    . '<dc:creator>CPMS</dc:creator><cp:lastModifiedBy>CPMS</cp:lastModifiedBy>'
    . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $createdAt . '</dcterms:created>'
    . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $createdAt . '</dcterms:modified>'
    . '</cp:coreProperties>';
$extendedProperties = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
    . '<Application>Microsoft Excel</Application><DocSecurity>0</DocSecurity><ScaleCrop>false</ScaleCrop>'
    . '<HeadingPairs><vt:vector size="2" baseType="variant"><vt:variant><vt:lpstr>Worksheets</vt:lpstr></vt:variant><vt:variant><vt:i4>1</vt:i4></vt:variant></vt:vector></HeadingPairs>'
    . '<TitlesOfParts><vt:vector size="1" baseType="lpstr"><vt:lpstr>' . cpms_attendance_xlsx_xml($sheetName) . '</vt:lpstr></vt:vector></TitlesOfParts>'
    . '<Company>CPMS</Company><LinksUpToDate>false</LinksUpToDate><SharedDoc>false</SharedDoc><HyperlinksChanged>false</HyperlinksChanged><AppVersion>16.0300</AppVersion>'
    . '</Properties>';

$tmpFile = tempnam(sys_get_temp_dir(), 'cpms_attendance_');
$zip = new ZipArchive();
if ($zip->open($tmpFile, ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    echo '엑셀 파일 생성에 실패했습니다.';
    exit;
}
$zip->addFromString('[Content_Types].xml', $contentTypes);
$zip->addFromString('_rels/.rels', $relsRoot);
$zip->addFromString('xl/workbook.xml', $workbookXml);
$zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
$zip->addFromString('xl/styles.xml', $styles);
$zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
$zip->addFromString('docProps/core.xml', $coreProperties);
$zip->addFromString('docProps/app.xml', $extendedProperties);
$zip->close();

$requiredXlsxParts = array(
    '[Content_Types].xml',
    '_rels/.rels',
    'xl/workbook.xml',
    'xl/_rels/workbook.xml.rels',
    'xl/styles.xml',
    'xl/worksheets/sheet1.xml',
    'docProps/core.xml',
    'docProps/app.xml'
);
$verifyZip = new ZipArchive();
$verifyResult = $verifyZip->open($tmpFile, ZipArchive::CHECKCONS);
$xlsxPackageValid = ($verifyResult === true);
if ($xlsxPackageValid) {
    foreach ($requiredXlsxParts as $requiredXlsxPart) {
        if ($verifyZip->locateName($requiredXlsxPart) === false || $verifyZip->getFromName($requiredXlsxPart) === false) {
            $xlsxPackageValid = false;
            break;
        }
    }
}
if ($verifyResult === true) $verifyZip->close();
if (!$xlsxPackageValid) {
    while (ob_get_level() > 0) {
        if (!@ob_end_clean()) break;
    }
    @unlink($tmpFile);
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo '엑셀 파일을 생성하지 못했습니다. 잠시 후 다시 시도해 주세요.';
    exit;
}

if ($isWeeklyExport) {
    $downloadName = '근태관리_주간현황_' . str_replace(array(' ', '~'), array('_', '-'), $weekLabel . '_' . $weekRangeLabel) . '.xlsx';
} else {
    $downloadName = '근태관리_월간현황_' . $month . '.xlsx';
}
$downloadName = preg_replace('~[\\\\/:*?"<>|]+~', '_', $downloadName);

$fileHandle = @fopen($tmpFile, 'rb');
if (!$fileHandle) {
    while (ob_get_level() > 0) {
        if (!@ob_end_clean()) break;
    }
    @unlink($tmpFile);
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo '엑셀 파일을 읽지 못했습니다. 잠시 후 다시 시도해 주세요.';
    exit;
}
while (ob_get_level() > 0) {
    if (!@ob_end_clean()) break;
}
if (headers_sent($sentFile, $sentLine)) {
    fclose($fileHandle);
    @unlink($tmpFile);
    error_log('[attendance_export] headers already sent: ' . $sentFile . ':' . $sentLine);
    echo '엑셀 다운로드를 시작하지 못했습니다.';
    exit;
}
@ini_set('zlib.output_compression', '0');
if (function_exists('header_remove')) {
    @header_remove('Content-Encoding');
    @header_remove('Content-Length');
}
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: attachment; filename="attendance.xlsx"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
header('Cache-Control: private, max-age=0, no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
while (!feof($fileHandle)) {
    $chunk = fread($fileHandle, 8192);
    if ($chunk === false) break;
    echo $chunk;
}
fclose($fileHandle);
@unlink($tmpFile);
exit;
