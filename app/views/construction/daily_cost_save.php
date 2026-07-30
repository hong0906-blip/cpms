<?php
/**
 * 공사 > 원가/공정 > 기타 투입비 저장.
 * 실제 사용일자의 귀속월을 서버에서 다시 계산해 마감 자료의 직접 입력을 차단한다.
 * PHP 5.6 compatible.
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../services/CostChangeService.php';

use App\Core\Auth;
use App\Core\Db;
use App\Services\CostChangeService;

if (!Auth::check()) { header('Location: ?r=login'); exit; }
if (!Auth::canManageConstruction()) { http_response_code(403); echo '403 Forbidden'; exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'Method Not Allowed'; exit; }
if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
    flash_set('error', '보안 토큰이 올바르지 않습니다.');
    header('Location: ?r=공사');
    exit;
}

$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$costDate = isset($_POST['cost_date']) ? trim((string)$_POST['cost_date']) : '';
$costType = isset($_POST['cost_type']) ? trim((string)$_POST['cost_type']) : '';
$amountText = isset($_POST['amount']) ? preg_replace('/[^0-9.\-]/', '', (string)$_POST['amount']) : '';
$amount = $amountText === '' ? 0 : (float)$amountText;
$memo = isset($_POST['memo']) ? trim((string)$_POST['memo']) : '';
$redirect = '?r=공사&pid=' . $projectId . '&tab=cost_progress&sub=cost';
$allowedTypes = array('노무', '자재', '안전', '장비', '외주', '기타');

if ($projectId <= 0 || CostChangeService::validDate($costDate) === '' || !in_array($costType, $allowedTypes, true)) {
    flash_set('error', '비용 입력값이 올바르지 않습니다.');
    header('Location: ' . $redirect);
    exit;
}

$lockType = ($costType === '노무') ? 'labor' : 'daily_cost';
$lockInfo = CostChangeService::lockInfo($lockType, $costDate, '', date('Y-m-d'));
if (!empty($lockInfo['locked'])) {
    flash_set('error', '마감된 기간의 자료입니다. 추가하려면 비용 변경 승인이 필요합니다.');
    header('Location: ' . $redirect);
    exit;
}

$pdo = Db::pdo();
if (!$pdo) {
    flash_set('error', 'DB 연결에 실패했습니다.');
    header('Location: ' . $redirect);
    exit;
}

try {
    $st = $pdo->prepare("INSERT INTO cpms_daily_cost_entries(project_id,cost_date,cost_type,amount,memo) VALUES(:pid,:d,:t,:a,:m)");
    $st->bindValue(':pid', $projectId, PDO::PARAM_INT);
    $st->bindValue(':d', $costDate);
    $st->bindValue(':t', $costType);
    $st->bindValue(':a', $amount);
    $st->bindValue(':m', $memo);
    $st->execute();
    flash_set('success', '비용 입력을 저장했습니다.');
} catch (Exception $e) {
    flash_set('error', '저장 실패: ' . $e->getMessage());
}

header('Location: ' . $redirect);
exit;
