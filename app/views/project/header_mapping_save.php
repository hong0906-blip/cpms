<?php
/**
 * C:\www\cpms\app\views\project\header_mapping_save.php
 * - 단가표 헤더 매핑 저장(관리자)
 *
 * PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\Db;

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
    header('Location: ?r=project/header_mapping');
    exit;
}

$pdo = Db::pdo();
if (!$pdo) {
    flash_set('error', 'DB 연결 실패');
    header('Location: ?r=project/header_mapping');
    exit;
}

$excelHeaders = isset($_POST['excel_headers']) && is_array($_POST['excel_headers']) ? $_POST['excel_headers'] : array();
$isRequired = isset($_POST['is_required']) && is_array($_POST['is_required']) ? $_POST['is_required'] : array();

if (count($excelHeaders) === 0) {
    flash_set('error', '저장할 데이터가 없습니다.');
    header('Location: ?r=project/header_mapping');
    exit;
}

try {
    $pdo->beginTransaction();

    $st = $pdo->prepare("UPDATE cpms_unit_price_header_map
                         SET excel_headers = :eh, is_required = :req
                         WHERE system_field = :sf");

    foreach ($excelHeaders as $sf => $eh) {
        $sf2 = trim((string)$sf);
        if ($sf2 === '') continue;

        $eh2 = trim((string)$eh);
        // 너무 지저분해지지 않게 공백 정리
        $eh2 = preg_replace('/\s+/', ' ', $eh2);

        $req = isset($isRequired[$sf2]) ? 1 : 0;

        $st->bindValue(':sf', $sf2);
        $st->bindValue(':eh', $eh2);
        $st->bindValue(':req', $req, PDO::PARAM_INT);
        $st->execute();
    }

    $pdo->commit();

    flash_set('success', '헤더 매핑이 저장되었습니다.');
    header('Location: ?r=project/header_mapping');
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash_set('error', '저장 실패: ' . $e->getMessage());
    header('Location: ?r=project/header_mapping');
    exit;
}