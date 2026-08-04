<?php
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../services/CostDataEventService.php';
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
$id=isset($_POST['id'])?(int)$_POST['id']:0; $pid=isset($_POST['pid'])?(int)$_POST['pid']:(isset($_POST['project_id'])?(int)$_POST['project_id']:0);
$viewMonth=isset($_POST['view_month'])?trim((string)$_POST['view_month']):'';
$returnRoute=isset($_POST['return_route'])?trim((string)$_POST['return_route']):'공무';
$returnTab=isset($_POST['return_tab'])?trim((string)$_POST['return_tab']):'monthly_input';
$redirect=cpms_project_monthly_deduction_return_url($returnRoute,$returnTab,$pid);
if ($viewMonth === 'all' || preg_match('/^\d{4}-\d{2}$/',$viewMonth)) { $redirect .= '&view_month=' . rawurlencode($viewMonth); }
if ($id>0) {
    try {
        $pdo=Db::pdo();
        if (!cpms_project_monthly_deduction_ensure_table($pdo)) { throw new Exception('공제분 테이블을 확인/생성하지 못했습니다.'); }
        $beforeSt=$pdo->prepare('SELECT * FROM cpms_project_monthly_deductions WHERE id=:id' . ($pid > 0 ? ' AND project_id=:pid' : '') . ' LIMIT 1');
        if (!$beforeSt) { throw new Exception('삭제할 공제분 조회 준비에 실패했습니다.'); }
        $beforeSt->bindValue(':id',$id,\PDO::PARAM_INT);
        if ($pid > 0) { $beforeSt->bindValue(':pid',$pid,\PDO::PARAM_INT); }
        if (!$beforeSt->execute()) { throw new Exception('삭제할 공제분을 조회하지 못했습니다.'); }
        $beforeRow=$beforeSt->fetch(\PDO::FETCH_ASSOC);
        $sql = 'DELETE FROM cpms_project_monthly_deductions WHERE id=:id';
        if ($pid > 0) { $sql .= ' AND project_id=:pid'; }
        $st=$pdo->prepare($sql);
        if (!$st) { throw new Exception('공제분 삭제 준비에 실패했습니다.'); }
        $st->bindValue(':id',$id,\PDO::PARAM_INT);
        if ($pid > 0) { $st->bindValue(':pid',$pid,\PDO::PARAM_INT); }
        if (!$st->execute()) { throw new Exception('공제분을 삭제하지 못했습니다.'); }
        if (is_array($beforeRow)) {
            \App\Services\CostDataEventService::recordChange($pdo, array(
                'project_id'=>isset($beforeRow['project_id'])?(int)$beforeRow['project_id']:$pid, 'cost_type'=>'other',
                'target_type'=>'monthly_deduction', 'target_id'=>(string)$id, 'event_action'=>'DELETE', 'source_type'=>'DIRECT',
                'actual_date'=>'', 'settlement_ym'=>isset($beforeRow['ym'])?$beforeRow['ym']:'',
                'old_amount'=>isset($beforeRow['amount'])?$beforeRow['amount']:null, 'new_amount'=>null,
                'old_data'=>$beforeRow, 'new_data'=>array(), 'reason'=>isset($beforeRow['memo'])?$beforeRow['memo']:'', 'source_file'=>__FILE__
            ));
        }
        flash_set('success','공제분을 삭제했습니다.');
    } catch (Exception $e) {
        flash_set('error','공제분 삭제 실패: '.$e->getMessage());
    }
}
header('Location: '.$redirect); exit;
