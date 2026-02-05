<?php
/**
 * C:\www\cpms\app\views\project\project_create_preview.php
 * - 프로젝트 생성 모달에서 엑셀 업로드 → "견적 내역서" 탭 파싱 → JSON 반환
 *
 * ✅ 반영사항
 * - 업로드 key: excel / xlsx 둘 다 허용
 * - "견적 내역서" 탭에서: 품명, 규격, 단위, 수량, 합계단가, 금액 추출
 * - 숫자: 소수 둘째자리 반올림해서 JSON으로 전달(표시용)
 * - 세션에 임시저장: $_SESSION['project_create_unit_price'][token]['rows']
 *
 * PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;

header('Content-Type: application/json; charset=utf-8');

function out_json($arr) {
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!Auth::check()) out_json(array('ok' => 0, 'message' => '로그인이 필요합니다.'));

$role = Auth::userRole();
$dept = Auth::userDepartment();
$allowed = ($role === 'executive' || $dept === '공무' || $dept === '관리' || $dept === '관리부');
if (!$allowed) out_json(array('ok' => 0, 'message' => '권한이 없습니다.'));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') out_json(array('ok' => 0, 'message' => 'Method Not Allowed'));

$token = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
if (!csrf_check($token)) out_json(array('ok' => 0, 'message' => '보안 토큰이 유효하지 않습니다.'));

if (!class_exists('ZipArchive')) out_json(array('ok' => 0, 'message' => '서버에 ZipArchive 확장 모듈이 없습니다. (.xlsx 읽기 불가)'));

$fileKey = null;
if (isset($_FILES['excel'])) $fileKey = 'excel';
if ($fileKey === null && isset($_FILES['xlsx'])) $fileKey = 'xlsx';

if ($fileKey === null || !is_array($_FILES[$fileKey])) out_json(array('ok' => 0, 'message' => '엑셀 파일이 없습니다.'));

$f = $_FILES[$fileKey];
$err  = isset($f['error']) ? (int)$f['error'] : 999;
$tmp  = isset($f['tmp_name']) ? (string)$f['tmp_name'] : '';
$name = isset($f['name']) ? (string)$f['name'] : '';

if ($err !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
    out_json(array('ok' => 0, 'message' => '업로드 실패(파일 상태 확인)'));
}

$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
if ($ext !== 'xlsx') out_json(array('ok' => 0, 'message' => '.xlsx만 지원합니다.'));

function norm_k($s) {
    $s = trim((string)$s);
    $s = str_replace(array(' ', "\t", "\n", "\r"), '', $s);
    return $s;
}
function num_clean($v) {
    $v = (string)$v;
    $v = str_replace(array(',', ' '), '', $v);
    $v = preg_replace('/[^0-9\.\-]/', '', $v);
    return $v;
}
function fmt2($v) {
    if ($v === null || $v === '') return '';
    $f = (float)$v;
    $f = round($f, 2);
    $s = sprintf('%.2f', $f);
    $s = rtrim(rtrim($s, '0'), '.');
    return $s;
}
function colRefToIndex($cellRef) {
    if (!is_string($cellRef) || $cellRef === '') return 0;
    $letters = preg_replace('/[^A-Z]/', '', strtoupper($cellRef));
    if ($letters === '') return 0;
    $len = strlen($letters);
    $num = 0;
    for ($i = 0; $i < $len; $i++) {
        $num = $num * 26 + (ord($letters[$i]) - 64);
    }
    return (int)$num; // 1-based
}

/**
 * xlsx에서 특정 시트명("견적 내역서")의 sheet xml 경로를 찾는다.
 * - xl/workbook.xml 에서 sheetId/r:id 확인
 * - xl/_rels/workbook.xml.rels 에서 실제 타겟 sheet 파일 경로 확인
 */
function findSheetPathByName($zip, $sheetName) {
    $wb = $zip->getFromName('xl/workbook.xml');
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($wb === false || $rels === false) return null;

    $wbx = @simplexml_load_string($wb);
    if (!$wbx || !isset($wbx->sheets) || !isset($wbx->sheets->sheet)) return null;

    $relsx = @simplexml_load_string($rels);
    if (!$relsx || !isset($relsx->Relationship)) return null;

    // rId => Target
    $map = array();
    foreach ($relsx->Relationship as $rel) {
        $id = (string)$rel['Id'];
        $target = (string)$rel['Target'];
        if ($id !== '' && $target !== '') {
            $map[$id] = $target; // ex) worksheets/sheet2.xml
        }
    }

    $want = norm_k($sheetName);
    foreach ($wbx->sheets->sheet as $sh) {
        $nm = (string)$sh['name'];
        $rid = (string)$sh->attributes('r', true)['id']; // r:id
        if ($rid === '') $rid = (string)$sh['id']; // fallback(거의 안씀)

        if (norm_k($nm) === $want) {
            if (isset($map[$rid])) {
                // target은 workbook 기준 상대경로(ex: worksheets/sheet2.xml)
                return 'xl/' . $map[$rid];
            }
        }
    }
    return null;
}

/**
 * sheet xml 읽어서 rows(2차원 배열)로 변환
 */
function readSheetRows($zip, $sheetPath, $maxRows) {
    $result = array('rows' => array(), 'error' => null);

    $sharedStrings = array();
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $sx = @simplexml_load_string($sharedXml);
        if ($sx) {
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
        }
    }

    $sheetXml = $zip->getFromName($sheetPath);
    if ($sheetXml === false) {
        $result['error'] = '견적 내역서 시트 데이터를 찾을 수 없습니다.';
        return $result;
    }

    $sxSheet = @simplexml_load_string($sheetXml);
    if (!$sxSheet || !isset($sxSheet->sheetData)) {
        $result['error'] = '엑셀 시트 파싱에 실패했습니다.';
        return $result;
    }

    $rowsOut = array();
    $rowCount = 0;

    foreach ($sxSheet->sheetData->row as $row) {
        $rowCount++;
        if ($rowCount > (int)$maxRows) break;

        $cells = array(); // colIndex(1-based) => value
        if (isset($row->c)) {
            foreach ($row->c as $c) {
                $r = isset($c['r']) ? (string)$c['r'] : '';
                $colIndex = colRefToIndex($r);
                $t = isset($c['t']) ? (string)$c['t'] : '';
                $v = '';

                if ($t === 's') {
                    $idx = isset($c->v) ? (int)$c->v : -1;
                    $v = ($idx >= 0 && isset($sharedStrings[$idx])) ? $sharedStrings[$idx] : '';
                } else if ($t === 'inlineStr') {
                    if (isset($c->is) && isset($c->is->t)) $v = (string)$c->is->t;
                } else {
                    $v = isset($c->v) ? (string)$c->v : '';
                }

                if ($colIndex > 0) $cells[$colIndex] = $v;
            }
        }

        $maxCol = 0;
        foreach ($cells as $k => $vv) { if ($k > $maxCol) $maxCol = $k; }

        $rowArr = array();
        for ($i = 1; $i <= $maxCol; $i++) {
            $rowArr[] = isset($cells[$i]) ? $cells[$i] : '';
        }

        $rowsOut[] = $rowArr;
    }

    $result['rows'] = $rowsOut;
    return $result;
}

$zip = new ZipArchive();
if ($zip->open($tmp) !== true) out_json(array('ok' => 0, 'message' => '엑셀 파일을 열 수 없습니다. (손상/형식 확인)'));

$sheetPath = findSheetPathByName($zip, '견적 내역서');
if ($sheetPath === null) {
    // 혹시 시트명이 공백/표기 차이일 수 있어 후보 몇개 더
    $sheetPath = findSheetPathByName($zip, '견적내역서');
}
if ($sheetPath === null) {
    $zip->close();
    out_json(array('ok' => 0, 'message' => '엑셀에서 "견적 내역서" 탭을 찾지 못했습니다.'));
}

$res = readSheetRows($zip, $sheetPath, 1500);
$zip->close();

if (!empty($res['error'])) out_json(array('ok' => 0, 'message' => '엑셀 읽기 실패: ' . $res['error']));

$rows = isset($res['rows']) ? $res['rows'] : array();
if (!is_array($rows) || count($rows) === 0) out_json(array('ok' => 0, 'message' => '견적 내역서 탭에서 데이터를 찾지 못했습니다.'));

$headerRowIdx = -1;
$colMap = array(); // item_name/spec/unit/qty/total_unit_price/amount

// 헤더행 찾기(품명 기준)
$scanMax = (count($rows) > 200) ? 200 : count($rows);
for ($i = 0; $i < $scanMax; $i++) {
    $r = $rows[$i];
    for ($c = 0; $c < count($r); $c++) {
        if (norm_k($r[$c]) === '품명') { $headerRowIdx = $i; break 2; }
    }
}

// 헤더 기반 매핑
if ($headerRowIdx >= 0) {
    $hdr = $rows[$headerRowIdx];
    for ($c = 0; $c < count($hdr); $c++) {
        $h = norm_k($hdr[$c]);
        if ($h === '') continue;

        if ($h === '품명') $colMap['item_name'] = $c;
        if ($h === '규격' || $h === '사양') $colMap['spec'] = $c;
        if ($h === '단위') $colMap['unit'] = $c;
        if ($h === '수량') $colMap['qty'] = $c;

        if ($h === '합계단가' || $h === '합계단가(원)' || $h === '합계단가원' || $h === '합계단가(₩)') $colMap['total_unit_price'] = $c;
        if ($h === '금액' || $h === '공급가액' || $h === '합계금액') $colMap['amount'] = $c;
    }
}

// fallback(샘플 기준): 품명 C(2), 규격 D(3), 단위 E(4), 수량 F(5), 합계단가 M(12), 금액 N(13)
if (!isset($colMap['item_name']))        $colMap['item_name'] = 2;
if (!isset($colMap['spec']))             $colMap['spec'] = 3;
if (!isset($colMap['unit']))             $colMap['unit'] = 4;
if (!isset($colMap['qty']))              $colMap['qty'] = 5;
if (!isset($colMap['total_unit_price'])) $colMap['total_unit_price'] = 12;
if (!isset($colMap['amount']))           $colMap['amount'] = 13;

$startRow = ($headerRowIdx >= 0) ? ($headerRowIdx + 1) : 0;

$parsed = array();
$emptyStreak = 0;

for ($i = $startRow; $i < count($rows); $i++) {
    $r = $rows[$i];

    $item = isset($r[$colMap['item_name']]) ? trim((string)$r[$colMap['item_name']]) : '';
    $spec = isset($r[$colMap['spec']]) ? trim((string)$r[$colMap['spec']]) : '';
    $unit = isset($r[$colMap['unit']]) ? trim((string)$r[$colMap['unit']]) : '';

    $qtyRaw = isset($r[$colMap['qty']]) ? trim((string)$r[$colMap['qty']]) : '';
    $tupRaw = isset($r[$colMap['total_unit_price']]) ? trim((string)$r[$colMap['total_unit_price']]) : '';
    $amtRaw = isset($r[$colMap['amount']]) ? trim((string)$r[$colMap['amount']]) : '';

    if ($item === '' && $spec === '' && $unit === '' && $qtyRaw === '' && $tupRaw === '' && $amtRaw === '') {
        $emptyStreak++;
        if ($emptyStreak >= 80) break;
        continue;
    }
    $emptyStreak = 0;

    if ($item === '') continue;

    $nitem = norm_k($item);
    if ($nitem === '소계' || $nitem === '합계') continue;

    $q2 = num_clean($qtyRaw);
    $p2 = num_clean($tupRaw);
    $a2 = num_clean($amtRaw);

    $parsed[] = array(
        'item_name' => $item,
        'spec' => $spec,
        'unit' => $unit,
        'qty' => ($q2 === '' ? '' : fmt2($q2)),
        'total_unit_price' => ($p2 === '' ? '' : fmt2($p2)),
        'amount' => ($a2 === '' ? '' : fmt2($a2)),
    );

    if (count($parsed) >= 1200) break;
}

if (count($parsed) === 0) out_json(array('ok' => 0, 'message' => '추출된 항목이 없습니다. (견적 내역서 탭/형식 확인)'));

// 세션 토큰 저장
$importToken = bin2hex(openssl_random_pseudo_bytes(16));
if (!isset($_SESSION['project_create_unit_price']) || !is_array($_SESSION['project_create_unit_price'])) {
    $_SESSION['project_create_unit_price'] = array();
}
$_SESSION['project_create_unit_price'][$importToken] = array(
    'file_name' => $name,
    'created_at' => time(),
    'rows' => $parsed,
);

out_json(array(
    'ok' => 1,
    'message' => '미리보기를 불러왔습니다.',
    'token' => $importToken,
    'meta' => array('file_name' => $name, 'sheet' => '견적 내역서', 'count' => count($parsed)),
    'rows' => $parsed,
));
