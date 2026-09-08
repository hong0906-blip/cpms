<?php
/**
 * 파일경로: public/approval_cost_correction.php
 * 기능: 비용 수정/누락 전자결재 저장 + 작성화면 AJAX
 * 작성화면은 ?r=approval_create&type=cost_correction 에서 기존 전자결재 레이아웃으로 표시됩니다.
 * PHP 5.6 호환
 */
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/views/approval/_common.php';
require_once __DIR__ . '/../app/views/approval/line_rules.php';
require_once __DIR__ . '/../app/views/approval/notification_helpers.php';
require_once __DIR__ . '/../app/services/ApprovalCostCorrectionService.php';

use App\Core\Auth;
use App\Core\Db;
use App\Services\ApprovalCostCorrectionService;

if (!Auth::check()) { header('Location: ./?r=login'); exit; }
$pdo = Db::pdo();
$user = Auth::user();
if (!$pdo || !$user) { http_response_code(500); echo 'DB 또는 로그인 정보를 확인할 수 없습니다.'; exit; }

if (!function_exists('ecc_json_response')) {
function ecc_json_response($data, $status) {
    http_response_code((int)$status);
    header('Content-Type: application/json; charset=UTF-8');
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) $json = json_encode($data);
    echo $json; exit;
}}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax'])) {
    $ajax = trim((string)$_GET['ajax']);
    if ($ajax === 'workers') {
        $projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
        $workDate = isset($_GET['work_date']) ? trim((string)$_GET['work_date']) : '';
        if ($workDate !== '') {
            ecc_json_response(array('ok'=>true,'rows'=>ApprovalCostCorrectionService::laborWorkersForMonth($pdo,$projectId,$workDate)),200);
        }
        ecc_json_response(array('ok'=>true,'workers'=>ApprovalCostCorrectionService::laborWorkers($pdo,$projectId)),200);
    }
    if ($ajax === 'current_gongsu') {
        $projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
        $workerName = isset($_GET['worker_name']) ? trim((string)$_GET['worker_name']) : '';
        $workDate = isset($_GET['work_date']) ? trim((string)$_GET['work_date']) : '';
        $value = ApprovalCostCorrectionService::currentLaborGongsu($pdo,$projectId,$workerName,$workDate);
        ecc_json_response(array('ok'=>true,'value'=>(float)$value),200);
    }
    if ($ajax === 'targets') {
        $projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
        $type = isset($_GET['type']) ? strtolower(trim((string)$_GET['type'])) : '';
        $category = isset($_GET['category']) ? trim((string)$_GET['category']) : '';
        $useDate = isset($_GET['use_date']) ? trim((string)$_GET['use_date']) : '';
        if (!ApprovalCostCorrectionService::validFormType($type)) ecc_json_response(array('ok'=>false,'rows'=>array(),'message'=>'양식을 확인해주세요.'),400);
        ecc_json_response(array('ok'=>true,'rows'=>ApprovalCostCorrectionService::existingTargets($pdo,$type,$projectId,$category,$useDate)),200);
    }
    if ($ajax === 'vendors') {
        $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
        $rows = ApprovalCostCorrectionService::searchVendors($pdo,$q,20);
        ecc_json_response(array('ok'=>true,'rows'=>$rows),200);
    }
    ecc_json_response(array('ok'=>false,'message'=>'지원하지 않는 요청입니다.'),404);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ./?r=approval_create&type=cost_correction');
    exit;
}

$resubmitSourceId = isset($_POST['resubmit_source_id']) ? (int)$_POST['resubmit_source_id'] : 0;
$writeRedirect = './?r=approval_create&type=cost_correction' . ($resubmitSourceId > 0 ? '&resubmit_id=' . $resubmitSourceId : '');
$token = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
if (!csrf_check($token)) {
    if (function_exists('flash_set')) flash_set('danger','보안 토큰이 만료되었습니다. 다시 작성해주세요.');
    header('Location: ' . $writeRedirect);
    exit;
}

try {
    $formType = isset($_POST['form_type']) ? strtolower(trim((string)$_POST['form_type'])) : '';
    if ($formType !== ApprovalCostCorrectionService::FORM_LABOR && isset($_POST['use_date_cost'])) {
        $_POST['use_date'] = trim((string)$_POST['use_date_cost']);
    }
    $files = isset($_FILES['evidence']) && is_array($_FILES['evidence']) ? $_FILES['evidence'] : array();
    $result = ApprovalCostCorrectionService::createDocument($pdo,$user,$_POST,$files);
    $docId = isset($result['document_id']) ? (int)$result['document_id'] : 0;
    if ($docId <= 0) throw new Exception('전자결재 문서번호를 확인할 수 없습니다.');
    if (function_exists('flash_set')) flash_set('success',$resubmitSourceId > 0 ? '반려된 비용 수정/누락 문서를 수정 후 재상신했습니다.' : '비용 수정/누락 전자결재를 상신했습니다. 최종 승인 후 공사자료에 자동 반영됩니다.');
    header('Location: ./?r=approval_detail&id=' . $docId);
    exit;
} catch (Exception $e) {
    if (function_exists('flash_set')) flash_set('danger',$e->getMessage());
    header('Location: ' . $writeRedirect);
    exit;
}
