<?php
require_once __DIR__ . '/../../bootstrap.php';
use App\Core\Auth; use App\Core\Db;
if (!Auth::check()) { header('Location: ?r=login'); exit; }
$dept = (string)Auth::userDepartment();
$role = (string)Auth::userRole();
$ok = Auth::isMaster() || $role === 'executive' || $dept === '공무' || $dept === '관리' || $dept === '관리부';
if (!$ok) { http_response_code(403); echo '403 Forbidden'; exit; }
function cpms_project_monthly_deduction_ensure_table($pdo) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_project_monthly_deductions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            ym VARCHAR(7) NOT NULL,
            deduction_name VARCHAR(190) NOT NULL,
            amount DECIMAL(15,2) NOT NULL DEFAULT 0,
            memo TEXT NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            INDEX idx_project_ym (project_id, ym)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        return true;
    } catch (Exception $e) {
        return false;
    }
}
function cpms_project_monthly_deduction_return_url($route, $tab, $pid) {
    $route = trim((string)$route);
    $tab = trim((string)$tab);
    if ($route !== '공무' && $route !== '공사') $route = '공무';
    if ($tab === '') $tab = 'monthly_input';
    return '?r=' . $route . '&tab=' . rawurlencode($tab) . '&pid=' . (int)$pid;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ?r=공무&tab=monthly_input'); exit; }
if (!csrf_check(isset($_POST['_csrf'])?$_POST['_csrf']:'')) { flash_set('error','보안 토큰 오류'); header('Location: ?r=공무&tab=monthly_input'); exit; }
$pid=isset($_POST['project_id'])?(int)$_POST['project_id']:0; $ym=isset($_POST['ym'])?trim((string)$_POST['ym']):''; $name=isset($_POST['deduction_name'])?trim((string)$_POST['deduction_name']):''; $amount=isset($_POST['amount'])?(float)preg_replace('/[^0-9.\-]/','',(string)$_POST['amount']):0; $memo=isset($_POST['memo'])?trim((string)$_POST['memo']):'';
$viewMonth=isset($_POST['view_month'])?trim((string)$_POST['view_month']):'';
$returnRoute=isset($_POST['return_route'])?trim((string)$_POST['return_route']):'공무';
$returnTab=isset($_POST['return_tab'])?trim((string)$_POST['return_tab']):'monthly_input';
$redirect=cpms_project_monthly_deduction_return_url($returnRoute,$returnTab,$pid);
if ($viewMonth === 'all' || preg_match('/^\d{4}-\d{2}$/',$viewMonth)) { $redirect .= '&view_month=' . rawurlencode($viewMonth); }
else if (preg_match('/^\d{4}-\d{2}$/',$ym)) { $redirect .= '&view_month=' . rawurlencode($ym); }
if ($pid<=0 || !preg_match('/^\d{4}-\d{2}$/',$ym) || $name==='') { flash_set('error','입력값을 확인하세요.'); header('Location: '.$redirect); exit; }
$pdo=Db::pdo();
try {
    if (!cpms_project_monthly_deduction_ensure_table($pdo)) { throw new Exception('공제분 테이블을 확인/생성하지 못했습니다.'); }
    $st=$pdo->prepare('INSERT INTO cpms_project_monthly_deductions (project_id, ym, deduction_name, amount, memo, created_at, updated_at) VALUES (:pid,:ym,:nm,:am,:mm,NOW(),NOW())');
    $st->bindValue(':pid',$pid,\PDO::PARAM_INT); $st->bindValue(':ym',$ym); $st->bindValue(':nm',$name); $st->bindValue(':am',$amount); $st->bindValue(':mm',$memo); $st->execute();
    flash_set('success','공제분을 저장했습니다.');
} catch (Exception $e) {
    flash_set('error','공제분 저장 실패: '.$e->getMessage());
}
header('Location: '.$redirect); exit;
