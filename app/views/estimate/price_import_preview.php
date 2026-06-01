<?php
/**
 * 과거 단가 엑셀 업로드 미리보기
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../../core/SimpleXlsxReader.php';

use App\Core\Db;
use App\Core\SimpleXlsxReader;

cpms_estimate_require_access(false);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit;
}

if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
    flash_set('error', '보안 토큰이 유효하지 않습니다.');
    header('Location: ?r=estimate_home&tab=search');
    exit;
}

$pdo = Db::pdo();
if (!$pdo || !cpms_estimate_tables_ready($pdo)) {
    flash_set('error', '견적관리 DB 설정이 필요합니다.');
    header('Location: ?r=estimate_home&tab=search');
    exit;
}

if (!isset($_FILES['xlsx']) || !is_array($_FILES['xlsx'])) {
    flash_set('error', '엑셀 파일이 없습니다.');
    header('Location: ?r=estimate_home&tab=search');
    exit;
}

$file = $_FILES['xlsx'];
$err = isset($file['error']) ? (int)$file['error'] : 999;
$tmp = isset($file['tmp_name']) ? (string)$file['tmp_name'] : '';
$name = isset($file['name']) ? (string)$file['name'] : '';
if ($err !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
    flash_set('error', '업로드 실패(파일 상태 확인)');
    header('Location: ?r=estimate_home&tab=search');
    exit;
}
if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'xlsx') {
    flash_set('error', '.xlsx 파일만 업로드할 수 있습니다.');
    header('Location: ?r=estimate_home&tab=search');
    exit;
}

$defaultPriceType = isset($_POST['price_type']) ? (string)$_POST['price_type'] : 'contract';
if (!in_array($defaultPriceType, array('contract', 'estimate', 'submitted'), true)) $defaultPriceType = 'contract';
$sourceName = trim((string)(isset($_POST['source_name']) ? $_POST['source_name'] : ''));
if ($sourceName === '') $sourceName = $name;

function cpms_estimate_import_norm($value)
{
    $value = trim((string)$value);
    $value = str_replace(array(' ', "\r", "\n", "\t", '_', '-'), '', $value);
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function cpms_estimate_import_pick_type($value, $fallback)
{
    $v = cpms_estimate_import_norm($value);
    if ($v === '') return $fallback;
    if (strpos($v, '계약') !== false) return 'contract';
    if (strpos($v, '제출') !== false) return 'submitted';
    if (strpos($v, '견적') !== false) return 'estimate';
    return $fallback;
}

function cpms_estimate_import_date($value)
{
    return cpms_estimate_excel_serial_date($value);
}

function cpms_estimate_xlsx_entry_text($zip, $name)
{
    $data = $zip->getFromName($name);
    return ($data === false) ? '' : $data;
}

function cpms_estimate_xlsx_col_index($cellRef)
{
    $letters = preg_replace('/[^A-Z]/', '', strtoupper((string)$cellRef));
    if ($letters === '') return 0;
    $n = 0;
    $len = strlen($letters);
    for ($i = 0; $i < $len; $i++) $n = ($n * 26) + (ord($letters[$i]) - 64);
    return $n;
}

function cpms_estimate_xlsx_cell_value($cell, $sharedStrings)
{
    $t = isset($cell['t']) ? (string)$cell['t'] : '';
    if ($t === 's') {
        $idx = isset($cell->v) ? (int)$cell->v : -1;
        return ($idx >= 0 && isset($sharedStrings[$idx])) ? trim((string)$sharedStrings[$idx]) : '';
    }
    if ($t === 'inlineStr') {
        if (isset($cell->is->t)) return trim((string)$cell->is->t);
        $text = '';
        if (isset($cell->is->r)) {
            foreach ($cell->is->r as $run) if (isset($run->t)) $text .= (string)$run->t;
        }
        return trim($text);
    }
    return isset($cell->v) ? trim((string)$cell->v) : '';
}

function cpms_estimate_xlsx_read_workbook($filePath)
{
    $result = array('ok' => false, 'sheets' => array(), 'message' => '');
    if (!class_exists('ZipArchive')) {
        $result['message'] = '서버에 ZipArchive 확장 모듈이 없습니다.';
        return $result;
    }
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        $result['message'] = '엑셀 파일을 열 수 없습니다.';
        return $result;
    }
    $sharedStrings = array();
    $sharedXml = cpms_estimate_xlsx_entry_text($zip, 'xl/sharedStrings.xml');
    if ($sharedXml !== '') {
        $sx = @simplexml_load_string($sharedXml);
        if ($sx) {
            foreach ($sx->si as $si) {
                $text = '';
                if (isset($si->t)) $text = (string)$si->t;
                else if (isset($si->r)) foreach ($si->r as $run) if (isset($run->t)) $text .= (string)$run->t;
                $sharedStrings[] = $text;
            }
        }
    }

    $workbookXml = cpms_estimate_xlsx_entry_text($zip, 'xl/workbook.xml');
    $relsXml = cpms_estimate_xlsx_entry_text($zip, 'xl/_rels/workbook.xml.rels');
    $workbook = @simplexml_load_string($workbookXml);
    $rels = @simplexml_load_string($relsXml);
    if (!$workbook || !$rels || !isset($workbook->sheets)) {
        $zip->close();
        $result['message'] = 'workbook 정보를 읽지 못했습니다.';
        return $result;
    }

    $relMap = array();
    foreach ($rels->Relationship as $rel) {
        $target = ltrim((string)$rel['Target'], '/');
        if (strpos($target, 'xl/') !== 0) $target = 'xl/' . $target;
        $relMap[(string)$rel['Id']] = str_replace('\\', '/', $target);
    }

    foreach ($workbook->sheets->sheet as $sheetNode) {
        $sheetName = (string)$sheetNode['name'];
        $attrs = $sheetNode->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $rid = isset($attrs['id']) ? (string)$attrs['id'] : '';
        if ($rid === '' || !isset($relMap[$rid])) continue;
        $sheetXml = cpms_estimate_xlsx_entry_text($zip, $relMap[$rid]);
        $sheet = @simplexml_load_string($sheetXml);
        if (!$sheet || !isset($sheet->sheetData)) continue;
        $rows = array();
        foreach ($sheet->sheetData->row as $rowNode) {
            $rowNum = isset($rowNode['r']) ? (int)$rowNode['r'] : (count($rows) + 1);
            $cells = array();
            $maxCol = 0;
            foreach ($rowNode->c as $cell) {
                $col = cpms_estimate_xlsx_col_index((string)$cell['r']);
                if ($col <= 0) continue;
                if ($col > $maxCol) $maxCol = $col;
                $cells[$col] = cpms_estimate_xlsx_cell_value($cell, $sharedStrings);
            }
            $rows[] = array('rownum' => $rowNum, 'cells' => $cells, 'max_col' => $maxCol);
        }
        $result['sheets'][$sheetName] = $rows;
    }

    $zip->close();
    $result['ok'] = true;
    return $result;
}

function cpms_estimate_xlsx_cell($row, $col)
{
    return (isset($row['cells'][$col])) ? trim((string)$row['cells'][$col]) : '';
}

function cpms_estimate_import_parse_categories($rows, $sourceName)
{
    $categories = array();
    $categoryCode = '';
    $categoryName = '';
    $sort = 0;
    foreach ($rows as $row) {
        $c1 = cpms_estimate_xlsx_cell($row, 1);
        $c2 = cpms_estimate_xlsx_cell($row, 2);
        $c3 = cpms_estimate_xlsx_cell($row, 3);
        if (preg_match('/^([A-Z])\.\s*(.+)$/u', $c1, $m)) {
            $categoryCode = $m[1];
            $categoryName = trim($m[2]);
            if (strpos($categoryName, '공종') === 0) $categoryName = '공종';
            $sort = 0;
            continue;
        }
        if ($categoryName === '' || $c1 === '' || $c1 === '순번') continue;
        $sort++;
        if ($categoryName === '공종') {
            if ($c3 === '') continue;
            $categories[] = array('category_code'=>$categoryCode, 'category_name'=>$categoryName, 'item_code'=>$c1, 'parent_name'=>$c2, 'item_name'=>$c3, 'item_note'=>'', 'sort_order'=>$sort, 'source_name'=>$sourceName);
        } else {
            if ($c2 === '') continue;
            $categories[] = array('category_code'=>$categoryCode, 'category_name'=>$categoryName, 'item_code'=>$c1, 'parent_name'=>'', 'item_name'=>$c2, 'item_note'=>$c3, 'sort_order'=>$sort, 'source_name'=>$sourceName);
        }
    }
    return $categories;
}

function cpms_estimate_import_parse_price_sheet($rows, $sheetKind, $sourceName)
{
    $parsed = array();
    foreach ($rows as $row) {
        if ((int)$row['rownum'] < 6) continue;
        $projectName = cpms_estimate_xlsx_cell($row, 1);
        $dateValue = ($sheetKind === 'contract') ? cpms_estimate_xlsx_cell($row, 3) : cpms_estimate_xlsx_cell($row, 2);
        $client = ($sheetKind === 'contract') ? cpms_estimate_xlsx_cell($row, 4) : cpms_estimate_xlsx_cell($row, 3);
        $sectionName = ($sheetKind === 'contract') ? cpms_estimate_xlsx_cell($row, 5) : cpms_estimate_xlsx_cell($row, 4);
        $contractor = ($sheetKind === 'contract') ? cpms_estimate_xlsx_cell($row, 6) : cpms_estimate_xlsx_cell($row, 5);
        $estimateType = ($sheetKind === 'contract') ? '' : cpms_estimate_xlsx_cell($row, 6);
        $workCharacter = ($sheetKind === 'contract') ? cpms_estimate_xlsx_cell($row, 7) : cpms_estimate_xlsx_cell($row, 7);
        $contractAmount = ($sheetKind === 'contract') ? cpms_estimate_parse_number(cpms_estimate_xlsx_cell($row, 8)) : cpms_estimate_parse_number(cpms_estimate_xlsx_cell($row, 8));
        $indirect = ($sheetKind === 'contract') ? cpms_estimate_xlsx_cell($row, 9) : cpms_estimate_xlsx_cell($row, 9);
        $difficulty = ($sheetKind === 'contract') ? cpms_estimate_xlsx_cell($row, 10) : cpms_estimate_xlsx_cell($row, 10);
        $workKind = ($sheetKind === 'contract') ? cpms_estimate_xlsx_cell($row, 11) : cpms_estimate_xlsx_cell($row, 11);
        $workType = ($sheetKind === 'contract') ? cpms_estimate_xlsx_cell($row, 12) : cpms_estimate_xlsx_cell($row, 12);
        $itemName = ($sheetKind === 'contract') ? cpms_estimate_xlsx_cell($row, 13) : cpms_estimate_xlsx_cell($row, 13);
        $spec = ($sheetKind === 'contract') ? cpms_estimate_xlsx_cell($row, 14) : cpms_estimate_xlsx_cell($row, 14);
        $unit = ($sheetKind === 'contract') ? cpms_estimate_xlsx_cell($row, 15) : cpms_estimate_xlsx_cell($row, 15);
        $material = cpms_estimate_parse_number(cpms_estimate_xlsx_cell($row, 16));
        $labor = cpms_estimate_parse_number(cpms_estimate_xlsx_cell($row, 17));
        $expense = cpms_estimate_parse_number(cpms_estimate_xlsx_cell($row, 18));
        $unitPrice = 0.0;
        $hasPrice = false;
        foreach (array($material, $labor, $expense) as $part) {
            if ($part !== null) {
                $unitPrice += (float)$part;
                $hasPrice = true;
            }
        }
        if ($itemName === '' || $unit === '' || !$hasPrice || $unitPrice <= 0) continue;
        $remarkParts = array();
        if ($estimateType !== '') $remarkParts[] = '견적성격: ' . $estimateType;
        if ($workCharacter !== '') $remarkParts[] = '공사성격: ' . $workCharacter;
        if ($workKind !== '') $remarkParts[] = '공사종류: ' . $workKind;
        if ($difficulty !== '') $remarkParts[] = '난이도: ' . $difficulty;
        if ($indirect !== '') $remarkParts[] = '간접비: ' . $indirect;
        $parsed[] = array(
            'project_name' => $projectName,
            'sub_project_name' => ($sheetKind === 'contract') ? cpms_estimate_xlsx_cell($row, 2) : '',
            'work_type' => $workType,
            'item_name' => $itemName,
            'spec' => $spec,
            'unit' => $unit,
            'client' => $client,
            'section_name' => $sectionName,
            'contractor' => $contractor,
            'price_type' => ($sheetKind === 'contract') ? 'contract' : 'estimate',
            'unit_price' => $unitPrice,
            'material_unit_price' => $material,
            'labor_unit_price' => $labor,
            'expense_unit_price' => $expense,
            'contract_amount' => $contractAmount,
            'contract_date' => cpms_estimate_import_date($dateValue),
            'bid_result' => ($sheetKind === 'contract') ? '성공' : '실패-사유불명',
            'remark' => implode(' / ', $remarkParts),
            'source_row' => (int)$row['rownum'],
            'source_name' => $sourceName,
        );
    }
    return $parsed;
}

function cpms_estimate_import_parse_known_workbook($filePath, $sourceName)
{
    $book = cpms_estimate_xlsx_read_workbook($filePath);
    if (empty($book['ok'])) return array('ok'=>false, 'message'=>$book['message']);
    $sheets = $book['sheets'];
    $hasKnown = isset($sheets['견적단가리스트 (계약)']) || isset($sheets['견적단가리스트 (견적)']);
    if (!$hasKnown) return array('ok'=>false, 'message'=>'known workbook not matched');
    $rows = array();
    if (isset($sheets['견적단가리스트 (계약)'])) {
        $rows = array_merge($rows, cpms_estimate_import_parse_price_sheet($sheets['견적단가리스트 (계약)'], 'contract', $sourceName));
    }
    if (isset($sheets['견적단가리스트 (견적)'])) {
        $rows = array_merge($rows, cpms_estimate_import_parse_price_sheet($sheets['견적단가리스트 (견적)'], 'estimate', $sourceName));
    }
    $categories = array();
    if (isset($sheets['분류항목'])) {
        $categories = cpms_estimate_import_parse_categories($sheets['분류항목'], $sourceName);
    }
    return array('ok'=>true, 'rows'=>$rows, 'categories'=>$categories, 'known'=>true);
}

function cpms_estimate_import_find_header($rows)
{
    $aliases = array(
        'work_type' => array('공종', '공정', '대공종'),
        'item_name' => array('품명', '자재명', '명칭', '항목'),
        'spec' => array('규격', '사양', '형식'),
        'unit' => array('단위', 'uom'),
        'unit_price' => array('단가', '계약단가', '견적단가', '제출단가', '합계단가', '단위단가'),
        'client' => array('발주처'),
        'section_name' => array('공구', '구간'),
        'contractor' => array('원청사', '시공사'),
        'contract_date' => array('계약일', '견적일', '견적일자', '일자'),
        'price_type' => array('구분', '단가구분'),
        'remark' => array('비고', '메모', '특이사항'),
    );

    $maxHeaderRows = count($rows) < 10 ? count($rows) : 10;
    for ($r = 0; $r < $maxHeaderRows; $r++) {
        $row = isset($rows[$r]) && is_array($rows[$r]) ? $rows[$r] : array();
        $map = array();
        for ($c = 0; $c < count($row); $c++) {
            $h = cpms_estimate_import_norm($row[$c]);
            if ($h === '') continue;
            foreach ($aliases as $field => $keys) {
                if (isset($map[$field])) continue;
                foreach ($keys as $key) {
                    if ($h === cpms_estimate_import_norm($key) || strpos($h, cpms_estimate_import_norm($key)) !== false) {
                        $map[$field] = $c;
                        break 2;
                    }
                }
            }
        }
        if (isset($map['item_name']) && isset($map['unit']) && isset($map['unit_price'])) {
            return array('row' => $r, 'map' => $map);
        }
    }
    return null;
}

$categories = array();
$known = cpms_estimate_import_parse_known_workbook($tmp, $sourceName);
if (!empty($known['ok'])) {
    $parsed = isset($known['rows']) && is_array($known['rows']) ? $known['rows'] : array();
    $categories = isset($known['categories']) && is_array($known['categories']) ? $known['categories'] : array();
    if (count($parsed) === 0) {
        flash_set('error', '가져올 계약/견적 단가 데이터가 없습니다.');
        header('Location: ?r=estimate_home&tab=search');
        exit;
    }

    $token = bin2hex(openssl_random_pseudo_bytes(16));
    if (!isset($_SESSION['estimate_price_import']) || !is_array($_SESSION['estimate_price_import'])) $_SESSION['estimate_price_import'] = array();
    $_SESSION['estimate_price_import'][$token] = array(
        'created_at' => time(),
        'file_name' => $name,
        'source_name' => $sourceName,
        'rows' => $parsed,
        'categories' => $categories,
    );
    $isKnownWorkbook = true;
} else {
    $isKnownWorkbook = false;
}

if (!$isKnownWorkbook) {
$res = SimpleXlsxReader::readFirstSheet($tmp, 3000);
if (!empty($res['error'])) {
    flash_set('error', '엑셀 읽기 실패: ' . $res['error']);
    header('Location: ?r=estimate_home&tab=search');
    exit;
}
$rows = isset($res['rows']) && is_array($res['rows']) ? $res['rows'] : array();
$header = cpms_estimate_import_find_header($rows);
if ($header === null) {
    flash_set('error', '필수 헤더를 찾지 못했습니다. 품명/단위/단가 헤더를 확인해주세요.');
    header('Location: ?r=estimate_home&tab=search');
    exit;
}

$map = $header['map'];
$parsed = array();
for ($r = ((int)$header['row'] + 1); $r < count($rows); $r++) {
    $row = isset($rows[$r]) && is_array($rows[$r]) ? $rows[$r] : array();
    $itemName = isset($map['item_name']) && isset($row[$map['item_name']]) ? trim((string)$row[$map['item_name']]) : '';
    $unit = isset($map['unit']) && isset($row[$map['unit']]) ? trim((string)$row[$map['unit']]) : '';
    $price = isset($map['unit_price']) && isset($row[$map['unit_price']]) ? cpms_estimate_parse_number($row[$map['unit_price']]) : null;
    if ($itemName === '' || $unit === '' || $price === null || $price <= 0) continue;

    $parsed[] = array(
        'work_type' => isset($map['work_type']) && isset($row[$map['work_type']]) ? trim((string)$row[$map['work_type']]) : '',
        'item_name' => $itemName,
        'spec' => isset($map['spec']) && isset($row[$map['spec']]) ? trim((string)$row[$map['spec']]) : '',
        'unit' => $unit,
        'client' => isset($map['client']) && isset($row[$map['client']]) ? trim((string)$row[$map['client']]) : '',
        'section_name' => isset($map['section_name']) && isset($row[$map['section_name']]) ? trim((string)$row[$map['section_name']]) : '',
        'contractor' => isset($map['contractor']) && isset($row[$map['contractor']]) ? trim((string)$row[$map['contractor']]) : '',
        'price_type' => isset($map['price_type']) && isset($row[$map['price_type']]) ? cpms_estimate_import_pick_type($row[$map['price_type']], $defaultPriceType) : $defaultPriceType,
        'unit_price' => $price,
        'contract_date' => isset($map['contract_date']) && isset($row[$map['contract_date']]) ? cpms_estimate_import_date($row[$map['contract_date']]) : null,
        'remark' => isset($map['remark']) && isset($row[$map['remark']]) ? trim((string)$row[$map['remark']]) : '',
        'source_row' => $r + 1,
    );
}

if (count($parsed) === 0) {
    flash_set('error', '가져올 단가 데이터가 없습니다.');
    header('Location: ?r=estimate_home&tab=search');
    exit;
}

$token = bin2hex(openssl_random_pseudo_bytes(16));
if (!isset($_SESSION['estimate_price_import']) || !is_array($_SESSION['estimate_price_import'])) $_SESSION['estimate_price_import'] = array();
$_SESSION['estimate_price_import'][$token] = array(
    'created_at' => time(),
    'file_name' => $name,
    'source_name' => $sourceName,
    'rows' => $parsed,
    'categories' => array(),
);
}
?>
<!doctype html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>과거 단가 업로드 미리보기</title>
  <style>
    body{font-family:Arial,sans-serif;background:#f6f7fb;margin:0;padding:24px;color:#111827;}
    .card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:18px;}
    .btn{display:inline-block;padding:12px 14px;border-radius:12px;border:0;text-decoration:none;font-weight:800;cursor:pointer;}
    .primary{background:#111827;color:#fff}.ghost{background:#f3f4f6;color:#111827}
    table{width:100%;border-collapse:collapse;margin-top:14px;font-size:13px}th,td{border-top:1px solid #e5e7eb;padding:9px;text-align:left}th{background:#f9fafb}.num{text-align:right}
  </style>
</head>
<body>
<div class="card">
  <h2>과거 단가 업로드 미리보기</h2>
  <div>파일: <b><?php echo h($name); ?></b> / 출처: <b><?php echo h($sourceName); ?></b> / 가져올 행: <b><?php echo (int)count($parsed); ?></b><?php if (!empty($categories)): ?> / 분류항목: <b><?php echo (int)count($categories); ?></b><?php endif; ?></div>
  <div style="margin-top:12px;display:flex;gap:8px;">
    <a class="btn ghost" href="<?php echo h(base_url()); ?>/?r=estimate_home&tab=search">돌아가기</a>
    <form method="post" action="<?php echo h(base_url()); ?>/?r=estimate/price_import_apply" style="margin:0;">
      <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
      <input type="hidden" name="token" value="<?php echo h($token); ?>">
      <button type="submit" class="btn primary">적용(저장)</button>
    </form>
  </div>
  <table>
    <thead><tr><th>원본행</th><th>구분</th><th>공종</th><th>품명</th><th>규격</th><th>단위</th><th class="num">재료비</th><th class="num">노무비</th><th class="num">경비</th><th class="num">합산단가</th><th>발주처</th><th>공구</th><th>원청사</th><th>계약/제출일</th><th>비고</th></tr></thead>
    <tbody>
      <?php foreach ($parsed as $row): ?>
      <tr>
        <td><?php echo (int)$row['source_row']; ?></td>
        <td><?php echo h(cpms_estimate_price_type_label($row['price_type'])); ?></td>
        <td><?php echo h($row['work_type']); ?></td>
        <td><?php echo h($row['item_name']); ?></td>
        <td><?php echo h($row['spec']); ?></td>
        <td><?php echo h($row['unit']); ?></td>
        <td class="num"><?php echo cpms_estimate_format_money(isset($row['material_unit_price']) ? $row['material_unit_price'] : ''); ?></td>
        <td class="num"><?php echo cpms_estimate_format_money(isset($row['labor_unit_price']) ? $row['labor_unit_price'] : ''); ?></td>
        <td class="num"><?php echo cpms_estimate_format_money(isset($row['expense_unit_price']) ? $row['expense_unit_price'] : ''); ?></td>
        <td class="num"><?php echo cpms_estimate_format_money($row['unit_price']); ?></td>
        <td><?php echo h($row['client']); ?></td>
        <td><?php echo h($row['section_name']); ?></td>
        <td><?php echo h($row['contractor']); ?></td>
        <td><?php echo h($row['contract_date']); ?></td>
        <td><?php echo h($row['remark']); ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
</body>
</html>
