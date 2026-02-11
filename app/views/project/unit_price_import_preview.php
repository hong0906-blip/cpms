<?php
/**
 * 공무 > 프로젝트 상세 > 단가표 업로드 미리보기
 * - 1줄 헤더/2줄 헤더 엑셀 파싱
 * - 노무/자재/안전 계획단가 분리 파싱 + 안전항목 자동판정
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\Db;
use App\Core\SimpleXlsxReader;

if (!Auth::check()) { header('Location: ?r=login'); exit; }
$role = Auth::userRole();
$dept = Auth::userDepartment();
$allowed = ($role === 'executive' || $dept === '공무' || $dept === '관리' || $dept === '관리부');
if (!$allowed) { http_response_code(403); echo '403 Forbidden'; exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'Method Not Allowed'; exit; }

$token = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
if (!csrf_check($token)) { flash_set('error', '보안 토큰이 유효하지 않습니다.'); header('Location: ?r=공무'); exit; }

$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
if ($projectId <= 0) { flash_set('error', '잘못된 프로젝트 ID'); header('Location: ?r=공무'); exit; }
if (!isset($_FILES['xlsx']) || !is_array($_FILES['xlsx'])) { flash_set('error', '엑셀 파일이 없습니다.'); header('Location: ?r=project/detail&id=' . $projectId); exit; }

$err = isset($_FILES['xlsx']['error']) ? (int)$_FILES['xlsx']['error'] : 999;
$tmp = isset($_FILES['xlsx']['tmp_name']) ? (string)$_FILES['xlsx']['tmp_name'] : '';
$name = isset($_FILES['xlsx']['name']) ? (string)$_FILES['xlsx']['name'] : '';
if ($err !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) { flash_set('error', '업로드 실패(파일 상태 확인)'); header('Location: ?r=project/detail&id=' . $projectId); exit; }
if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'xlsx') { flash_set('error', '엑셀 파일은 .xlsx만 지원합니다.'); header('Location: ?r=project/detail&id=' . $projectId); exit; }

$pdo = Db::pdo();
if (!$pdo) { flash_set('error', 'DB 연결 실패'); header('Location: ?r=project/detail&id=' . $projectId); exit; }

function nclean($v) {
    $v = str_replace(array(',', ' '), '', (string)$v);
    $v = preg_replace('/[^0-9\.\-]/', '', $v);
    return $v;
}
function normalize_header($v) {
    $v = trim((string)$v);
    $v = preg_replace('/\s+/', '', $v);
    return mb_strtolower($v, 'UTF-8');
}

$maps = array();
try {
    $st = $pdo->query("SELECT system_field, excel_headers, is_required FROM cpms_unit_price_header_map");
    foreach ($st->fetchAll() as $r) {
        $aliases = array();
        foreach (explode('|', (string)$r['excel_headers']) as $a) {
            $a = trim((string)$a);
            if ($a !== '') $aliases[] = $a;
        }
        $maps[(string)$r['system_field']] = array('headers' => $aliases, 'required' => (int)$r['is_required']);
    }
} catch (Exception $e) { $maps = array(); }
if (count($maps) === 0) { flash_set('error', '헤더 매핑 설정이 없습니다.'); header('Location: ?r=project/detail&id=' . $projectId); exit; }

$res = SimpleXlsxReader::readFirstSheet($tmp, 1000);
if (!empty($res['error'])) { flash_set('error', '엑셀 읽기 실패: ' . $res['error']); header('Location: ?r=project/detail&id=' . $projectId); exit; }
$rows = isset($res['rows']) ? $res['rows'] : array();
if (count($rows) < 2) { flash_set('error', '데이터가 없습니다.'); header('Location: ?r=project/detail&id=' . $projectId); exit; }

$header1 = isset($rows[0]) ? $rows[0] : array();
$header2 = isset($rows[1]) ? $rows[1] : array();
$maxCols = max(count($header1), count($header2));
$singleHeaders = array();
$doubleHeaders = array();
for ($i = 0; $i < $maxCols; $i++) {
    $h1 = isset($header1[$i]) ? trim((string)$header1[$i]) : '';
    $h2 = isset($header2[$i]) ? trim((string)$header2[$i]) : '';
    $singleHeaders[$i] = $h1;
    if ($h2 !== '' && preg_match('/(단가|금액|노무|자재|안전|경비|합계)/u', $h2)) {
        $doubleHeaders[$i] = $h2;
    } else {
        $doubleHeaders[$i] = ($h1 !== '') ? $h1 : $h2;
    }
}

$matchMap = function($headers) use ($maps) {
    $fieldToCol = array();
    for ($c = 0; $c < count($headers); $c++) {
        $h = isset($headers[$c]) ? (string)$headers[$c] : '';
        if ($h === '') continue;
        $hn = normalize_header($h);
        foreach ($maps as $sf => $cfg) {
            if (isset($fieldToCol[$sf])) continue;
            $aliases = isset($cfg['headers']) ? $cfg['headers'] : array();
            foreach ($aliases as $a) {
                if ($hn === normalize_header($a)) { $fieldToCol[$sf] = $c; break 2; }
            }
        }
    }
    $missing = array();
    foreach ($maps as $sf => $cfg) {
        if ((int)$cfg['required'] === 1 && !isset($fieldToCol[$sf])) $missing[] = $sf;
    }
    return array($fieldToCol, $missing);
};

list($fieldToCol1, $missing1) = $matchMap($singleHeaders);
list($fieldToCol2, $missing2) = $matchMap($doubleHeaders);
$useDouble = (count($missing2) <= count($missing1));
$fieldToCol = $useDouble ? $fieldToCol2 : $fieldToCol1;
$dataStart = $useDouble ? 2 : 1;
$missingRequired = $useDouble ? $missing2 : $missing1;
if (count($missingRequired) > 0) { flash_set('error', '필수 헤더가 없습니다: ' . implode(', ', $missingRequired)); header('Location: ?r=project/detail&id=' . $projectId); exit; }

$parsed = array();
$skipped = 0;
for ($r = $dataStart; $r < count($rows); $r++) {
    $row = $rows[$r];
    $allEmpty = true;
    for ($i = 0; $i < count($row); $i++) { if (trim((string)$row[$i]) !== '') { $allEmpty = false; break; } }
    if ($allEmpty) { $skipped++; continue; }

    $item = isset($fieldToCol['item_name']) ? trim((string)@$row[$fieldToCol['item_name']]) : '';
    $spec = isset($fieldToCol['spec']) ? trim((string)@$row[$fieldToCol['spec']]) : '';
    $unit = isset($fieldToCol['unit']) ? trim((string)@$row[$fieldToCol['unit']]) : '';
    $remark = isset($fieldToCol['remark']) ? trim((string)@$row[$fieldToCol['remark']]) : '';
    if ($item === '') { $skipped++; continue; }

    $qty = null; $up = null; $lup = null; $mup = null; $sup = null;
    if (isset($fieldToCol['qty'])) { $x = nclean(@$row[$fieldToCol['qty']]); if ($x !== '' && is_numeric($x)) $qty = (float)$x; }
    if (isset($fieldToCol['unit_price'])) { $x = nclean(@$row[$fieldToCol['unit_price']]); if ($x !== '' && is_numeric($x)) $up = (float)$x; }
    if (isset($fieldToCol['labor_unit_price'])) { $x = nclean(@$row[$fieldToCol['labor_unit_price']]); if ($x !== '' && is_numeric($x)) $lup = (float)$x; }
    if (isset($fieldToCol['material_unit_price'])) { $x = nclean(@$row[$fieldToCol['material_unit_price']]); if ($x !== '' && is_numeric($x)) $mup = (float)$x; }
    if (isset($fieldToCol['safety_unit_price'])) { $x = nclean(@$row[$fieldToCol['safety_unit_price']]); if ($x !== '' && is_numeric($x)) $sup = (float)$x; }

    $isSafety = (mb_strpos($item, '안전', 0, 'UTF-8') !== false || mb_strpos($spec, '안전', 0, 'UTF-8') !== false) ? 1 : 0;
    $parsed[] = array(
        'item_name' => $item, 'spec' => $spec, 'unit' => $unit, 'qty' => $qty,
        'unit_price' => $up, 'labor_unit_price' => $lup, 'material_unit_price' => $mup, 'safety_unit_price' => $sup,
        'is_safety' => $isSafety, 'remark' => $remark,
    );
}
if (count($parsed) === 0) { flash_set('error', '가져올 데이터가 없습니다.'); header('Location: ?r=project/detail&id=' . $projectId); exit; }

$importToken = bin2hex(openssl_random_pseudo_bytes(16));
if (!isset($_SESSION['unit_price_import']) || !is_array($_SESSION['unit_price_import'])) $_SESSION['unit_price_import'] = array();
$_SESSION['unit_price_import'][$importToken] = array('project_id' => $projectId, 'file_name' => $name, 'created_at' => time(), 'rows' => $parsed);

function hh($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>단가표 미리보기</title>
<style>body{font-family:Arial;background:#f6f7fb;margin:0;padding:24px}.card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:16px;max-width:1300px}.btn{padding:12px 14px;border-radius:12px;border:0;cursor:pointer;font-weight:800}.btn-primary{background:#111827;color:#fff}.btn-ghost{background:#f3f4f6;color:#111827}table{width:100%;border-collapse:collapse;margin-top:12px}th,td{border-top:1px solid #e5e7eb;padding:10px;font-size:13px;text-align:left}th{background:#f9fafb}.num{text-align:right}</style></head><body>
<div class="card">
<h2>단가표 업로드 미리보기</h2>
<div>파일: <b><?php echo hh($name); ?></b> / 행: <b><?php echo (int)count($parsed); ?></b> / 헤더형식: <b><?php echo $useDouble ? '2줄 헤더' : '1줄 헤더'; ?></b></div>
<div style="margin-top:10px;display:flex;gap:8px;">
<a class="btn btn-ghost" href="<?php echo hh(base_url()); ?>/?r=project/detail&id=<?php echo (int)$projectId; ?>">← 돌아가기</a>
<form method="post" action="<?php echo hh(base_url()); ?>/?r=project/unit_price_import_apply"><input type="hidden" name="_csrf" value="<?php echo hh(csrf_token()); ?>"><input type="hidden" name="project_id" value="<?php echo (int)$projectId; ?>"><input type="hidden" name="token" value="<?php echo hh($importToken); ?>"><button class="btn btn-primary" type="submit">적용(저장)</button></form>
</div>
<table><thead><tr><th>품명</th><th>규격</th><th>단위</th><th class="num">수량</th><th class="num">자재단가</th><th class="num">노무단가</th><th class="num">안전단가</th><th class="num">합계단가</th><th>안전항목</th><th>비고</th></tr></thead><tbody>
<?php foreach ($parsed as $r): ?><tr><td><?php echo hh($r['item_name']); ?></td><td><?php echo hh($r['spec']); ?></td><td><?php echo hh($r['unit']); ?></td><td class="num"><?php echo hh($r['qty']); ?></td><td class="num"><?php echo hh($r['material_unit_price']); ?></td><td class="num"><?php echo hh($r['labor_unit_price']); ?></td><td class="num"><?php echo hh($r['safety_unit_price']); ?></td><td class="num"><?php echo hh($r['unit_price']); ?></td><td><?php echo ((int)$r['is_safety']===1)?'Y':''; ?></td><td><?php echo hh($r['remark']); ?></td></tr><?php endforeach; ?>
</tbody></table>
</div></body></html>