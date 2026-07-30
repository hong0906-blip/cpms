<?php
/**
 * 공무 > 월별 투입비 집계 PDF 수동 Google Drive 저장.
 * PHP 5.6 compatible.
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../services/PublicAffairsMonthlySummaryPdfService.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) {
    header('Location: ?r=login');
    exit;
}

$department = (string)Auth::userDepartment();
$role = (string)Auth::userRole();
$allowed = Auth::isMaster()
    || $role === 'executive'
    || in_array($department, array('공무', '공무부', '공무팀', '관리', '관리부', '관리팀'), true);
if (!$allowed) {
    http_response_code(403);
    echo '403 Forbidden';
    exit;
}

if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ?r=공무&tab=monthly_summary');
    exit;
}
if (!csrf_check(isset($_POST['_csrf']) ? $_POST['_csrf'] : '')) {
    flash_set('error', '보안 토큰이 올바르지 않습니다. 다시 시도해주세요.');
    header('Location: ?r=공무&tab=monthly_summary');
    exit;
}

$ym = isset($_POST['ym']) ? trim((string)$_POST['ym']) : date('Y-m');
if (!cpms_public_affairs_monthly_summary_valid_ym($ym)) $ym = date('Y-m');
$redirect = '?r=공무&tab=monthly_summary&ym=' . rawurlencode($ym);
unset($_SESSION['_monthly_summary_drive_result']);

try {
    $pdo = Db::pdo();
    if (!$pdo) throw new Exception('DB 연결이 필요합니다.');
    $result = cpms_public_affairs_monthly_summary_generate(
        $pdo,
        $ym,
        date('Y-m-d'),
        Auth::user(),
        array('mode' => 'manual', 'force' => true)
    );
    if (empty($result['ok'])) {
        throw new Exception(isset($result['message']) ? $result['message'] : 'PDF 저장에 실패했습니다.');
    }
    $driveRecord = isset($result['record']) && is_array($result['record']) ? $result['record'] : array();
    $_SESSION['_monthly_summary_drive_result'] = array(
        'file_name' => isset($driveRecord['file_name']) ? (string)$driveRecord['file_name'] : '',
        'file_link' => isset($driveRecord['drive_web_view_link']) ? (string)$driveRecord['drive_web_view_link'] : '',
        'folder_link' => isset($driveRecord['drive_month_folder_web_view_link']) ? (string)$driveRecord['drive_month_folder_web_view_link'] : ''
    );
    flash_set('success', isset($result['message']) ? $result['message'] : '월별 투입비 집계 PDF를 Google Drive에 저장했습니다.');
} catch (Exception $e) {
    flash_set('error', '월별 투입비 집계 PDF 저장 실패: ' . $e->getMessage());
}

header('Location: ' . $redirect);
exit;
