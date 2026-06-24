<?php
require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) { header('Location: ?r=login'); exit; }

$dept = (string)Auth::userDepartment();
$role = (string)Auth::userRole();
$ok = Auth::isMaster() || $role === 'executive' || $dept === '공무' || $dept === '관리' || $dept === '관리부';
if (!$ok) { http_response_code(403); echo '403 Forbidden'; exit; }

function cpms_monthly_summary_remark_save_ym_valid($ym) {
    return preg_match('/^\d{4}-\d{2}$/', (string)$ym);
}

function cpms_monthly_summary_remark_save_ensure_table($pdo) {
    if (!$pdo) return false;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_project_monthly_summary_remarks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            ym VARCHAR(7) NOT NULL,
            remark TEXT NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY uk_project_monthly_summary_remark (project_id, ym),
            KEY idx_ym (ym)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        return true;
    } catch (Exception $e) {
        return false;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ?r=공무&tab=monthly_summary'); exit; }
if (!csrf_check(isset($_POST['_csrf']) ? $_POST['_csrf'] : '')) {
    flash_set('error', '보안 토큰 오류');
    header('Location: ?r=공무&tab=monthly_summary');
    exit;
}

$ym = isset($_POST['ym']) ? trim((string)$_POST['ym']) : date('Y-m');
if (!cpms_monthly_summary_remark_save_ym_valid($ym)) $ym = date('Y-m');
$redirect = '?r=공무&tab=monthly_summary&ym=' . rawurlencode($ym);
$remarks = isset($_POST['remarks']) && is_array($_POST['remarks']) ? $_POST['remarks'] : array();

$pdo = Db::pdo();
try {
    if (!$pdo) throw new Exception('DB 연결이 필요합니다.');
    if (!cpms_monthly_summary_remark_save_ensure_table($pdo)) throw new Exception('비고 테이블을 확인/생성하지 못했습니다.');

    $st = $pdo->prepare("INSERT INTO cpms_project_monthly_summary_remarks
            (project_id, ym, remark, created_at, updated_at)
        VALUES
            (:project_id, :ym, :remark_insert, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            remark = :remark_update,
            updated_at = NOW()");

    foreach ($remarks as $projectId => $remark) {
        $pid = (int)$projectId;
        if ($pid <= 0) continue;
        $text = trim((string)$remark);
        $st->bindValue(':project_id', $pid, PDO::PARAM_INT);
        $st->bindValue(':ym', $ym);
        $st->bindValue(':remark_insert', $text);
        $st->bindValue(':remark_update', $text);
        $st->execute();
    }
    flash_set('success', '월별 투입비 집계 비고를 저장했습니다.');
} catch (Exception $e) {
    flash_set('error', '비고 저장 실패: ' . $e->getMessage());
}

header('Location: ' . $redirect);
exit;
