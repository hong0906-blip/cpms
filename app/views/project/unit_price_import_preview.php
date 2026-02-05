<?php
/**
 * C:\www\cpms\app\views\project\unit_price_import_preview.php
 * - 엑셀(.xlsx) 업로드 후 미리보기 페이지 출력
 * - 헤더(1행) 기반으로 매핑(설정 테이블 cpms_unit_price_header_map) 적용
 * - 적용 버튼을 누르면 unit_price_import_apply.php로 저장
 *
 * PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\Db;
use App\Core\SimpleXlsxReader;

if (!Auth::check()) { header('Location: ?r=login'); exit; }

// 권한: 임원 또는 공무/관리
$role = Auth::userRole();
$dept = Auth::userDepartment();
$allowed = ($role === 'executive' || $dept === '공무' || $dept === '관리' || $dept === '관리부');
if (!$allowed) {
    http_response_code(403);
    echo '403 Forbidden';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit;
}

$token = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
if (!csrf_check($token)) {
    flash_set('error', '보안 토큰이 유효하지 않습니다.');
    header('Location: ?r=공무');
    exit;
}

$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
if ($projectId <= 0) {
    flash_set('error', '잘못된 프로젝트 ID');
    header('Location: ?r=공무');
    exit;
}

if (!isset($_FILES['xlsx']) || !is_array($_FILES['xlsx'])) {
    flash_set('error', '엑셀 파일이 없습니다.');
    header('Location: ?r=project/detail&id=' . $projectId);
    exit;
}

$err = isset($_FILES['xlsx']['error']) ? (int)$_FILES['xlsx']['error'] : 999;
$tmp = isset($_FILES['xlsx']['tmp_name']) ? (string)$_FILES['xlsx']['tmp_name'] : '';
$name = isset($_FILES['xlsx']['name']) ? (string)$_FILES['xlsx']['name'] : '';

if ($err !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
    flash_set('error', '업로드 실패(파일 상태 확인)');
    header('Location: ?r=project/detail&id=' . $projectId);
    exit;
}

// 확장자 체크(.xlsx)
$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
if ($ext !== 'xlsx') {
    flash_set('error', '엑셀 파일은 .xlsx만 지원합니다.');
    header('Location: ?r=project/detail&id=' . $projectId);
    exit;
}

$pdo = Db::pdo();
if (!$pdo) {
    flash_set('error', 'DB 연결 실패');
    header('Location: ?r=project/detail&id=' . $projectId);
    exit;
}

// 헤더 매핑 읽기
$maps = array(); // system_field => array('headers'=>[], 'required'=>0)
try {
    $st = $pdo->query("SELECT system_field, excel_headers, is_required FROM cpms_unit_price_header_map");
    $rows = $st->fetchAll();
    foreach ($rows as $r) {
        $sf = (string)$r['system_field'];
        $headersRaw = (string)$r['excel_headers'];
        $required = (int)$r['is_required'];

        $parts = explode('|', $headersRaw);
        $headers = array();
        foreach ($parts as $p) {
            $p2 = trim((string)$p);
            if ($p2 !== '') $headers[] = $p2;
        }

        $maps[$sf] = array('headers' => $headers, 'required' => $required);
    }
} catch (Exception $e) {
    $maps = array();
}

if (count($maps) === 0) {
    flash_set('error', '헤더 매핑 설정이 없습니다. DB 설정 페이지에서 기본 매핑 저장을 먼저 해주세요.');
    header('Location: ?r=project/detail&id=' . $projectId);
    exit;
}

// 엑셀 읽기(최대 500행)
$res = SimpleXlsxReader::readFirstSheet($tmp, 500);
if (!empty($res['error'])) {
    flash_set('error', '엑셀 읽기 실패: ' . $res['error']);
    header('Location: ?r=project/detail&id=' . $projectId);
    exit;
}

$rows = isset($res['rows']) ? $res['rows'] : array();
if (count($rows) < 2) {
    flash_set('error', '데이터가 없습니다. (1행 헤더 + 2행부터 데이터 필요)');
    header('Location: ?r=project/detail&id=' . $projectId);
    exit;
}

// 1행 헤더
$headerRow = $rows[0];
$headerNorm = array(); // index(0-based) => headerText
for ($i = 0; $i < count($headerRow); $i++) {
    $headerNorm[$i] = trim((string)$headerRow[$i]);
}

// 헤더 매칭: system_field => colIndex(0-based)
$fieldToCol = array();

foreach ($maps as $sf => $cfg) {
    $found = -1;
    $aliases = isset($cfg['headers']) ? $cfg['headers'] : array();

    // 헤더명 비교는 "대소문자 무시 + 공백 trim" 정도만 적용
    for ($c = 0; $c < count($headerNorm); $c++) {
        $h = (string)$headerNorm[$c];
        if ($h === '') continue;

        foreach ($aliases as $a) {
            if (mb_strtolower($h, 'UTF-8') === mb_strtolower((string)$a, 'UTF-8')) {
                $found = $c;
                break 2;
            }
        }
    }

    if ($found >= 0) $fieldToCol[$sf] = $found;
}

// 필수 헤더 체크
$missingRequired = array();
foreach ($maps as $sf => $cfg) {
    if ((int)$cfg['required'] === 1) {
        if (!isset($fieldToCol[$sf])) $missingRequired[] = $sf;
    }
}
if (count($missingRequired) > 0) {
    flash_set('error', '필수 헤더가 없습니다: ' . implode(', ', $missingRequired) . ' (헤더 매핑 설정 확인)');
    header('Location: ?r=project/detail&id=' . $projectId);
    exit;
}

// 데이터 행 파싱
$parsed = array();
$skipped = 0;

function num_clean($v) {
    $v = (string)$v;
    $v = str_replace(array(',', ' '), '', $v);
    $v = preg_replace('/[^0-9\.\-]/', '', $v);
    return $v;
}

for ($r = 1; $r < count($rows); $r++) {
    $row = $rows[$r];

    // 빈행 체크(전체가 빈값이면 스킵)
    $allEmpty = true;
    for ($i = 0; $i < count($row); $i++) {
        if (trim((string)$row[$i]) !== '') { $allEmpty = false; break; }
    }
    if ($allEmpty) { $skipped++; continue; }

    $item = '';
    $spec = '';
    $unit = '';
    $qty = null;
    $up = null;
    $remark = '';

    if (isset($fieldToCol['item_name']))  $item = trim((string)@$row[$fieldToCol['item_name']]);
    if (isset($fieldToCol['spec']))       $spec = trim((string)@$row[$fieldToCol['spec']]);
    if (isset($fieldToCol['unit']))       $unit = trim((string)@$row[$fieldToCol['unit']]);
    if (isset($fieldToCol['qty'])) {
        $q = trim((string)@$row[$fieldToCol['qty']]);
        $q2 = num_clean($q);
        if ($q2 !== '') $qty = (float)$q2;
    }
    if (isset($fieldToCol['unit_price'])) {
        $p = trim((string)@$row[$fieldToCol['unit_price']]);
        $p2 = num_clean($p);
        if ($p2 !== '') $up = (float)$p2;
    }
    if (isset($fieldToCol['remark']))     $remark = trim((string)@$row[$fieldToCol['remark']]);

    // 필수값(item_name, unit_price 등) 최소 체크
    if ($item === '') { $skipped++; continue; }
    // unit_price는 매핑 테이블에서 required로 설정할 수 있음 → 여기서는 null 허용(필요시 DB설정에서 required=1로 강제)
    // 다만 기본 시드는 unit_price를 required=1로 넣어둠 → 그래서 여기까지 오면 대부분 존재함

    $parsed[] = array(
        'item_name' => $item,
        'spec' => $spec,
        'unit' => $unit,
        'qty' => $qty,
        'unit_price' => $up,
        'remark' => $remark,
    );
}

if (count($parsed) === 0) {
    flash_set('error', '가져올 데이터가 없습니다. (품명 필수, 빈행은 자동 제외)');
    header('Location: ?r=project/detail&id=' . $projectId);
    exit;
}

// 세션에 임시 저장(토큰)
$importToken = bin2hex(openssl_random_pseudo_bytes(16));
if (!isset($_SESSION['unit_price_import']) || !is_array($_SESSION['unit_price_import'])) {
    $_SESSION['unit_price_import'] = array();
}
$_SESSION['unit_price_import'][$importToken] = array(
    'project_id' => $projectId,
    'file_name' => $name,
    'created_at' => time(),
    'rows' => $parsed,
);

// ---- 미리보기 HTML 출력(레이아웃 없이 단독 페이지) ----
header('Content-Type: text/html; charset=utf-8');

function hh($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>단가표 미리보기</title>
    <style>
        body { font-family: Arial, sans-serif; background:#f6f7fb; margin:0; padding:24px; }
        .card { background:#fff; border:1px solid #e5e7eb; border-radius:16px; padding:16px; max-width:1100px; }
        .muted { color:#6b7280; font-size:13px; }
        .btn { padding:12px 14px; border-radius:12px; border:0; cursor:pointer; font-weight:800; }
        .btn-primary { background:#111827; color:#fff; }
        .btn-ghost { background:#f3f4f6; color:#111827; }
        table { width:100%; border-collapse: collapse; margin-top:12px; }
        th, td { border-top:1px solid #e5e7eb; padding:10px; font-size:13px; text-align:left; }
        th { background:#f9fafb; color:#374151; }
        td.num, th.num { text-align:right; }
        .top { display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; align-items:flex-end; }
        .pill { display:inline-block; padding:6px 10px; border-radius:999px; background:#eef2ff; color:#3730a3; font-weight:800; font-size:12px; }
    </style>
</head>
<body>
    <div class="card">
        <div class="top">
            <div>
                <div class="pill">미리보기</div>
                <h2 style="margin:10px 0 0 0;">단가표 업로드 미리보기</h2>
                <div class="muted" style="margin-top:6px;">
                    파일: <b><?php echo hh($name); ?></b> /
                    가져온 행: <b><?php echo (int)count($parsed); ?></b>
                    <?php if ($skipped > 0): ?> / 제외된 행: <b><?php echo (int)$skipped; ?></b><?php endif; ?>
                </div>
            </div>

            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <a class="btn btn-ghost" href="<?php echo hh(base_url()); ?>/?r=project/detail&id=<?php echo (int)$projectId; ?>">← 돌아가기</a>

                <form method="post" action="<?php echo hh(base_url()); ?>/?r=project/unit_price_import_apply" style="margin:0;">
                    <input type="hidden" name="_csrf" value="<?php echo hh(csrf_token()); ?>">
                    <input type="hidden" name="project_id" value="<?php echo (int)$projectId; ?>">
                    <input type="hidden" name="token" value="<?php echo hh($importToken); ?>">
                    <button class="btn btn-primary" type="submit" onclick="return confirm('미리보기 내용대로 단가표를 저장할까요?');">
                        적용(저장)
                    </button>
                </form>
            </div>
        </div>

        <table>
            <thead>
            <tr>
                <th>품명</th>
                <th>규격</th>
                <th>단위</th>
                <th class="num">수량</th>
                <th class="num">단가</th>
                <th>비고</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($parsed as $r): ?>
                <tr>
                    <td><b><?php echo hh($r['item_name']); ?></b></td>
                    <td><?php echo hh($r['spec']); ?></td>
                    <td><?php echo hh($r['unit']); ?></td>
                    <td class="num"><?php echo hh($r['qty']); ?></td>
                    <td class="num"><b><?php echo hh($r['unit_price']); ?></b></td>
                    <td><?php echo hh($r['remark']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div class="muted" style="margin-top:12px;">
            * “적용(저장)”을 누르기 전까지는 DB에 저장되지 않습니다.<br>
            * 헤더명이 안 맞으면: 공무 > “헤더 매핑 설정”에서 매핑을 수정하세요.
        </div>
    </div>
</body>
</html>