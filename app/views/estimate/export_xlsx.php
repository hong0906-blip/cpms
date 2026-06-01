<?php
/**
 * 견적서 엑셀 다운로드
 * - PHP 5.6 호환, ZipArchive 기반 최소 XLSX 생성
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/helpers.php';

use App\Core\Db;

cpms_estimate_require_access(false);

$pdo = Db::pdo();
if (!$pdo || !cpms_estimate_tables_ready($pdo)) {
    http_response_code(500);
    echo '견적관리 DB 설정이 필요합니다.';
    exit;
}

$estimateId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$estimate = cpms_estimate_get_estimate($pdo, $estimateId);
if (!$estimate) {
    http_response_code(404);
    echo '견적서를 찾을 수 없습니다.';
    exit;
}
$items = cpms_estimate_get_items($pdo, $estimateId);

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    echo '서버에 ZipArchive 확장 모듈이 없습니다.';
    exit;
}

function cpms_estimate_xlsx_col($index)
{
    $index = (int)$index;
    $letters = '';
    while ($index > 0) {
        $mod = ($index - 1) % 26;
        $letters = chr(65 + $mod) . $letters;
        $index = (int)(($index - 1) / 26);
    }
    return $letters;
}

function cpms_estimate_xlsx_xml($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function cpms_estimate_xlsx_cell_xml($row, $col, $value, $style)
{
    $ref = cpms_estimate_xlsx_col($col) . (int)$row;
    $styleAttr = $style > 0 ? ' s="' . (int)$style . '"' : '';
    if ($value === null || $value === '') {
        return '<c r="' . $ref . '"' . $styleAttr . '/>';
    }
    if (is_numeric($value)) {
        return '<c r="' . $ref . '"' . $styleAttr . '><v>' . (0 + $value) . '</v></c>';
    }
    return '<c r="' . $ref . '" t="inlineStr"' . $styleAttr . '><is><t>' . cpms_estimate_xlsx_xml($value) . '</t></is></c>';
}

function cpms_estimate_xlsx_row_xml($rowNum, $values, $style)
{
    $xml = '<row r="' . (int)$rowNum . '">';
    $col = 1;
    foreach ($values as $value) {
        $xml .= cpms_estimate_xlsx_cell_xml($rowNum, $col, $value, $style);
        $col++;
    }
    return $xml . '</row>';
}

$rows = array();
$rows[] = array('견적서');
$rows[] = array('');
$rows[] = array('견적일자', $estimate['estimate_date'], '공사명', $estimate['project_name']);
$rows[] = array('발주처', $estimate['client'], '공구', $estimate['section_name'], '원청사', $estimate['contractor']);
$rows[] = array('공사성격', $estimate['work_character'], '공사종류', $estimate['work_kind'], '공사난이도', $estimate['difficulty']);
$rows[] = array('견적성격', $estimate['estimate_type'], '간접공사비 포함', ((int)$estimate['include_indirect'] === 1 ? '포함' : '미포함'));
$rows[] = array('비고', $estimate['remark']);
$rows[] = array('');
$rows[] = array('공종', '품명', '규격', '단위', '수량', '추천단가', '제출단가', '금액', '추천근거', '비고');

foreach ($items as $item) {
    $rows[] = array(
        isset($item['work_type']) ? $item['work_type'] : '',
        isset($item['item_name']) ? $item['item_name'] : '',
        isset($item['spec']) ? $item['spec'] : '',
        isset($item['unit']) ? $item['unit'] : '',
        isset($item['qty']) ? $item['qty'] : '',
        isset($item['recommended_unit_price']) ? $item['recommended_unit_price'] : '',
        isset($item['submitted_unit_price']) ? $item['submitted_unit_price'] : '',
        isset($item['amount']) ? $item['amount'] : '',
        cpms_estimate_recommendation_brief(isset($item['recommendation_json']) ? $item['recommendation_json'] : ''),
        isset($item['remark']) ? $item['remark'] : '',
    );
}
$rows[] = array('');
$rows[] = array('', '', '', '', '', '', '합계', isset($estimate['total_amount']) ? $estimate['total_amount'] : '');

$sheetRows = '';
for ($i = 0; $i < count($rows); $i++) {
    $rowNum = $i + 1;
    $style = 0;
    if ($rowNum === 1) $style = 1;
    if ($rowNum === 9) $style = 2;
    $sheetRows .= cpms_estimate_xlsx_row_xml($rowNum, $rows[$i], $style);
}

$sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
    . '<cols>'
    . '<col min="1" max="1" width="14" customWidth="1"/><col min="2" max="3" width="24" customWidth="1"/><col min="4" max="4" width="10" customWidth="1"/>'
    . '<col min="5" max="8" width="14" customWidth="1"/><col min="9" max="9" width="60" customWidth="1"/><col min="10" max="10" width="24" customWidth="1"/>'
    . '</cols><sheetData>' . $sheetRows . '</sheetData></worksheet>';

$workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
    . '<sheets><sheet name="견적서" sheetId="1" r:id="rId1"/></sheets></workbook>';

$relsRoot = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
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
    . '</Types>';

$styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<fonts count="3"><font><sz val="11"/><name val="맑은 고딕"/></font><font><b/><sz val="16"/><name val="맑은 고딕"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="맑은 고딕"/></font></fonts>'
    . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF1F2937"/><bgColor indexed="64"/></patternFill></fill></fills>'
    . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
    . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
    . '<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="2" fillId="2" borderId="0" xfId="0" applyFill="1" applyFont="1"/></cellXfs>'
    . '</styleSheet>';

$tmpFile = tempnam(sys_get_temp_dir(), 'cpms_estimate_');
$zip = new ZipArchive();
if ($zip->open($tmpFile, ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    echo '엑셀 파일 생성 실패';
    exit;
}
$zip->addFromString('[Content_Types].xml', $contentTypes);
$zip->addFromString('_rels/.rels', $relsRoot);
$zip->addFromString('xl/workbook.xml', $workbookXml);
$zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
$zip->addFromString('xl/styles.xml', $styles);
$zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
$zip->close();

$safeName = preg_replace('/[\\\\\/:*?"<>|]+/', '_', (string)$estimate['project_name']);
if ($safeName === '') $safeName = 'estimate_' . $estimateId;
$downloadName = $safeName . '_견적서.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($downloadName));
header('Content-Length: ' . filesize($tmpFile));
readfile($tmpFile);
@unlink($tmpFile);
exit;
