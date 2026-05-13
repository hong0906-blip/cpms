<?php
use App\Core\Db;
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { exit; }
csrf_validate();
$pdo = Db::pdo();
$user = \App\Core\Auth::user();
if (!$pdo || !$user) { exit; }
$docType = isset($_POST['doc_type']) ? trim((string)$_POST['doc_type']) : 'proposal';
$titleInput = isset($_POST['title']) ? trim((string)$_POST['title']) : '';
$gongmuId = isset($_POST['gongmu_id']) ? (int)$_POST['gongmu_id'] : 0;
$manageId = isset($_POST['manage_id']) ? (int)$_POST['manage_id'] : 0;
$vp = $pdo->query("SELECT id,name,email FROM employees WHERE is_active=1 AND position='부사장' LIMIT 1")->fetch();
$ceo = $pdo->query("SELECT id,name,email FROM employees WHERE is_active=1 AND position IN ('대표','대표이사') LIMIT 1")->fetch();
if (!$vp || !$ceo) { flash_set('danger','직원명부에서 부사장 또는 대표가 등록되어 있지 않습니다. 관리 메뉴에서 먼저 등록해주세요.'); header('Location: ?r=approval_create&type='.$docType); exit; }
if ($gongmuId <= 0 || $manageId <= 0) { flash_set('danger','공무/관리 결재자를 선택해주세요.'); header('Location: ?r=approval_create&type='.$docType); exit; }

$contentData = array();
if ($docType === 'leave') {
    $contentData = array('applicant_name'=>trim((string)$_POST['applicant_name']),'department'=>trim((string)$_POST['department']),'position'=>trim((string)$_POST['position']),'request_date'=>trim((string)$_POST['request_date']),'leave_type'=>trim((string)$_POST['leave_type']),'start_date'=>trim((string)$_POST['start_date']),'end_date'=>trim((string)$_POST['end_date']),'leave_days'=>trim((string)$_POST['leave_days']),'reason'=>trim((string)$_POST['reason']),'emergency_contact'=>trim((string)$_POST['emergency_contact']));
    $title = '휴가계 - '.$contentData['applicant_name'].' - '.$contentData['start_date'];
} else {
    $contentData = array('doc_no'=>trim((string)$_POST['doc_no']),'draft_date'=>trim((string)$_POST['draft_date']),'effective_date'=>trim((string)$_POST['effective_date']),'draft_department'=>trim((string)$_POST['draft_department']),'drafter_name'=>trim((string)$_POST['drafter_name']),'draft_type'=>trim((string)$_POST['draft_type']),'title'=>$titleInput,'body'=>trim((string)$_POST['body']),'reason'=>trim((string)$_POST['reason']),'detail'=>trim((string)$_POST['detail']),'special_note'=>trim((string)$_POST['special_note']),'payment_request_date'=>trim((string)$_POST['payment_request_date']),'budget_status'=>trim((string)$_POST['budget_status']),'attached_doc_note'=>trim((string)$_POST['attached_doc_note']));
    $title = $titleInput;
}
if ($title === '') { $title = ($docType === 'leave') ? '휴가계' : '품의서'; }
$contentJson = json_encode($contentData);

$pdo->beginTransaction();
$st = $pdo->prepare("INSERT INTO cpms_approval_documents (doc_type,title,content,doc_status,current_step_order,created_by_id,created_by_name,created_at,updated_at) VALUES (:t,:ti,:c,'PENDING',1,:uid,:un,NOW(),NOW())");
$st->execute(array(':t'=>$docType,':ti'=>$title,':c'=>$contentJson,':uid'=>(int)$user['id'],':un'=>$user['name']));
$did = (int)$pdo->lastInsertId();
$lineEmployees = array($gongmuId=>$pdo->prepare("SELECT id,name,email FROM employees WHERE id=:id AND is_active=1 AND department='공무' LIMIT 1"),$manageId=>$pdo->prepare("SELECT id,name,email FROM employees WHERE id=:id AND is_active=1 AND department='관리' LIMIT 1"));
$lineEmployees[$gongmuId]->execute(array(':id'=>$gongmuId)); $gongmu = $lineEmployees[$gongmuId]->fetch();
$lineEmployees[$manageId]->execute(array(':id'=>$manageId)); $manage = $lineEmployees[$manageId]->fetch();
$lines = array(array('role'=>'공무','emp'=>$gongmu),array('role'=>'관리','emp'=>$manage),array('role'=>'부사장','emp'=>$vp),array('role'=>'대표','emp'=>$ceo));
for ($i=0;$i<count($lines);$i++) {
    if (!$lines[$i]['emp']) { $pdo->rollBack(); flash_set('danger','결재라인 구성에 실패했습니다.'); header('Location: ?r=approval_create&type='.$docType); exit; }
    $pdo->prepare("INSERT INTO cpms_approval_lines (document_id,line_order,role_type,approver_id,approver_name,approver_email,line_status) VALUES (?,?,?,?,?,?,?)")
        ->execute(array($did,$i+1,$lines[$i]['role'],$lines[$i]['emp']['id'],$lines[$i]['emp']['name'],$lines[$i]['emp']['email'],($i===0?'PENDING':'WAITING')));
}

if (isset($_FILES['attachments']) && is_array($_FILES['attachments']['name'])) {
    $base = dirname(__DIR__,3).'/storage/approvals/'.$did;
    if (!is_dir($base)) { @mkdir($base, 0777, true); }
    for ($i=0;$i<count($_FILES['attachments']['name']);$i++) {
        if ((int)$_FILES['attachments']['error'][$i] !== UPLOAD_ERR_OK) continue;
        $orig = (string)$_FILES['attachments']['name'][$i];
        $tmp = (string)$_FILES['attachments']['tmp_name'][$i];
        $ext = pathinfo($orig, PATHINFO_EXTENSION);
        $safe = 'f_'.date('YmdHis').'_'.$i.'_'.mt_rand(1000,9999).($ext?'.'.$ext:'');
        $target = $base.'/'.$safe;
        if (@move_uploaded_file($tmp, $target)) {
            $rel = 'storage/approvals/'.$did.'/'.$safe;
            $pdo->prepare("INSERT INTO cpms_approval_files (document_id,original_name,saved_name,file_path,created_at) VALUES (:d,:o,:s,:p,NOW())")
                ->execute(array(':d'=>$did,':o'=>$orig,':s'=>$safe,':p'=>$rel));
        }
    }
}
$pdo->commit();
header('Location: ?r=approval_detail&id='.$did);