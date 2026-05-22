<?php
use App\Core\Db;

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/document_templates.php';
require_once __DIR__ . '/template_style.php';
require_once __DIR__ . '/template_proposal.php';
require_once __DIR__ . '/template_leave.php';

if (!function_exists('approval_create_fetch_employee_by_name')) {
    function approval_create_fetch_employee_by_name($pdo, $name)
    {
        if (!$pdo || trim((string)$name) === '') {
            return null;
        }
        try {
            $st = $pdo->prepare("SELECT id,name,email,department,position FROM employees WHERE is_active=1 AND name=:name LIMIT 1");
            $st->execute(array(':name' => $name));
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ? $row : null;
        } catch (Exception $e) {
            return null;
        }
    }
}

if (!function_exists('approval_create_find_by_position')) {
    function approval_create_find_by_position($employees, $positions)
    {
        $employees = is_array($employees) ? $employees : array();
        $positions = is_array($positions) ? $positions : array();
        for ($i = 0; $i < count($employees); $i++) {
            $pos = isset($employees[$i]['position']) ? trim((string)$employees[$i]['position']) : '';
            if (in_array($pos, $positions, true)) {
                return $employees[$i];
            }
        }
        return null;
    }
}

if (!function_exists('approval_create_employee_list_by_dept')) {
    function approval_create_employee_list_by_dept($employees, $departments)
    {
        $list = array();
        $employees = is_array($employees) ? $employees : array();
        $departments = is_array($departments) ? $departments : array();
        for ($i = 0; $i < count($employees); $i++) {
            $dept = isset($employees[$i]['department']) ? approval_norm_dept($employees[$i]['department']) : '';
            if (in_array($dept, $departments, true)) {
                $list[] = $employees[$i];
            }
        }
        return $list;
    }
}

$pdo = Db::pdo();
$type = isset($_GET['type']) ? trim((string)$_GET['type']) : 'proposal';
$isLeave = ($type === 'leave');
$u = \App\Core\Auth::user();
$dept = isset($u['department']) ? trim((string)$u['department']) : '';
$name = isset($u['name']) ? trim((string)$u['name']) : '';
$email = isset($u['email']) ? trim((string)$u['email']) : '';
$position = isset($u['position']) ? trim((string)$u['position']) : '';
$creatorEmployeeId = approval_current_employee_id($pdo, $u);

$employees = array();
$site = array();
$lead = array();
$gongmu = array();
$manage = array();
$vp = null;
$ceo = null;
$myBirth = '';
$myEmp = array();
$constructionPmName = approval_ko('%EB%B0%95%EC%9B%90%EB%8D%95');
$gongmuPmName = approval_ko('%EA%B3%A0%EC%98%81%EC%84%B1');
$constructionPm = null;
$gongmuPm = null;

if ($pdo) {
    $birthSel = approval_column_exists($pdo, 'employees', 'birth_date') ? 'birth_date' : "'' AS birth_date";
    $flagSite = approval_column_exists($pdo, 'employees', 'approval_can_be_site_manager');
    $flagLead = approval_column_exists($pdo, 'employees', 'approval_can_be_team_leader');
    $flagGongmu = approval_column_exists($pdo, 'employees', 'approval_can_be_gongmu_approver');
    $flagManage = approval_column_exists($pdo, 'employees', 'approval_can_be_manage_approver');
    $sql = "SELECT id,name,email,department,position," . $birthSel . "," .
        ($flagSite ? "approval_can_be_site_manager" : "0") . " AS approval_can_be_site_manager," .
        ($flagLead ? "approval_can_be_team_leader" : "0") . " AS approval_can_be_team_leader," .
        ($flagGongmu ? "approval_can_be_gongmu_approver" : "0") . " AS approval_can_be_gongmu_approver," .
        ($flagManage ? "approval_can_be_manage_approver" : "0") . " AS approval_can_be_manage_approver " .
        "FROM employees WHERE is_active=1 ORDER BY name";
    try {
        $employees = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($employees)) {
            $employees = array();
        }
    } catch (Exception $e) {
        $employees = array();
    }

    for ($i = 0; $i < count($employees); $i++) {
        $e = $employees[$i];
        if ((int)$e['approval_can_be_site_manager'] === 1) {
            $site[] = $e;
        }
        if ((int)$e['approval_can_be_team_leader'] === 1) {
            $lead[] = $e;
        }
        if ((int)$e['approval_can_be_gongmu_approver'] === 1) {
            $gongmu[] = $e;
        }
        if ((int)$e['approval_can_be_manage_approver'] === 1) {
            $manage[] = $e;
        }
        if ($creatorEmployeeId > 0 && isset($e['id']) && (int)$e['id'] === $creatorEmployeeId) {
            $myEmp = $e;
        }
        if (empty($myEmp) && $email !== '' && isset($e['email']) && strtolower(trim((string)$e['email'])) === strtolower($email)) {
            $myEmp = $e;
        }
        if (empty($myEmp) && $name !== '' && isset($e['name']) && trim((string)$e['name']) === $name) {
            $myEmp = $e;
        }
    }

    if (count($site) === 0) {
        $site = approval_create_employee_list_by_dept($employees, array(approval_ko('%EA%B3%B5%EC%82%AC')));
    }
    if (count($gongmu) === 0) {
        $gongmu = approval_create_employee_list_by_dept($employees, array(approval_ko('%EA%B3%B5%EB%AC%B4')));
    }
    if (count($manage) === 0) {
        $manage = approval_create_employee_list_by_dept($employees, array(approval_ko('%EA%B4%80%EB%A6%AC')));
    }
    if (count($lead) === 0) {
        $leadPositions = array(approval_ko('%ED%8C%80%EC%9E%A5'), approval_ko('%EA%B3%BC%EC%9E%A5'), approval_ko('%EC%B0%A8%EC%9E%A5'), approval_ko('%EB%B6%80%EC%9E%A5'), approval_ko('%EC%83%81%EB%AC%B4'), approval_ko('%EC%A0%84%EB%AC%B4'));
        for ($i = 0; $i < count($employees); $i++) {
            $pos = isset($employees[$i]['position']) ? trim((string)$employees[$i]['position']) : '';
            if (in_array($pos, $leadPositions, true)) {
                $lead[] = $employees[$i];
            }
        }
    }

    $vp = approval_create_find_by_position($employees, array(approval_ko('%EB%B6%80%EC%82%AC%EC%9E%A5')));
    $ceo = approval_create_find_by_position($employees, array(approval_ko('%EB%8C%80%ED%91%9C%EC%9D%B4%EC%82%AC'), approval_ko('%EB%8C%80%ED%91%9C')));
    $constructionPm = approval_create_fetch_employee_by_name($pdo, $constructionPmName);
    $gongmuPm = approval_create_fetch_employee_by_name($pdo, $gongmuPmName);
}

if (!empty($myEmp)) {
    if (isset($myEmp['department'])) {
        $dept = trim((string)$myEmp['department']);
    }
    if (isset($myEmp['position'])) {
        $position = trim((string)$myEmp['position']);
    }
    if (isset($myEmp['name']) && trim((string)$myEmp['name']) !== '') {
        $name = trim((string)$myEmp['name']);
    }
    if (isset($myEmp['email']) && trim((string)$myEmp['email']) !== '') {
        $email = trim((string)$myEmp['email']);
    }
    if (isset($myEmp['birth_date'])) {
        $myBirth = trim((string)$myEmp['birth_date']);
    }
}

$normDept = approval_norm_dept($dept);
$defaultPm = null;
if ($normDept === approval_ko('%EA%B3%B5%EB%AC%B4')) {
    $defaultPm = $gongmuPm;
} else if ($normDept === approval_ko('%EA%B3%B5%EC%82%AC') || $normDept === approval_ko('%EC%95%88%EC%A0%84')) {
    $defaultPm = $constructionPm;
}

$pmCandidates = array();
if ($constructionPm) {
    $pmCandidates[] = $constructionPm;
}
if ($gongmuPm && (!$constructionPm || (int)$gongmuPm['id'] !== (int)$constructionPm['id'])) {
    $pmCandidates[] = $gongmuPm;
}
for ($i = 0; $i < count($employees); $i++) {
    $id = isset($employees[$i]['id']) ? (int)$employees[$i]['id'] : 0;
    $exists = false;
    for ($j = 0; $j < count($pmCandidates); $j++) {
        if ((int)$pmCandidates[$j]['id'] === $id) {
            $exists = true;
            break;
        }
    }
    if (!$exists) {
        $pmCandidates[] = $employees[$i];
    }
}

$init = array(
    'birth_date' => $myBirth,
    'draft_date' => date('Y-m-d'),
    'effective_date' => date('Y-m-d'),
    'draft_department' => $dept,
    'drafter_name' => $name,
    'title' => '',
    'department' => $dept,
    'position' => $position,
    'applicant_name' => $name,
    'leave_start_date' => date('Y-m-d'),
    'leave_end_date' => date('Y-m-d'),
    'leave_days' => '1',
    'request_date' => date('Y-m-d'),
    'applicant_sign_name' => $name,
    'writer_email' => $email,
    'applicant_email' => $email,
    'delegate_level' => 'none'
);

$approvalOptions = array(
    'site' => $site,
    'gongmu' => $gongmu,
    'manage' => $manage,
    'team_lead' => $lead,
    'vp' => $vp,
    'ceo' => $ceo,
    'construction_pm' => $constructionPm,
    'gongmu_pm' => $gongmuPm,
    'pm_candidates' => $pmCandidates,
    'default_pm_id' => ($defaultPm && isset($defaultPm['id'])) ? (int)$defaultPm['id'] : 0,
    'leave_pm' => $defaultPm,
    'employees' => $employees,
    'writer_email' => $email
);
?>
<div class="mb-4 flex items-center justify-between">
    <div class="flex gap-2">
        <a href="?r=approval_home" onclick="if(history.length>1){history.back();return false;}" class="px-4 py-2 bg-white border-2 border-gray-400 rounded-xl font-bold text-gray-800"><?php echo h(approval_ko('%EB%92%A4%EB%A1%9C%EA%B0%80%EA%B8%B0')); ?></a>
        <a href="?r=approval_home" class="px-4 py-2 bg-white border-2 border-gray-400 rounded-xl font-bold text-gray-800"><?php echo h(approval_ko('%EC%A0%84%EC%9E%90%EA%B2%B0%EC%9E%AC%20%EB%AA%A9%EB%A1%9D')); ?></a>
    </div>
</div>
<form method="post" action="?r=approval_store" enctype="multipart/form-data">
    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
    <input type="hidden" name="doc_type" value="<?php echo $isLeave ? 'leave' : 'proposal'; ?>">
    <?php
    if ($isLeave) {
        render_approval_leave_document($init, array(), 'edit', $approvalOptions);
    } else {
        render_approval_proposal_document($init, array(), 'edit', array(), $approvalOptions);
    }
    ?>
    <div class="mt-6 rounded-2xl border border-indigo-100 bg-indigo-50 p-4 text-sm text-indigo-900">
        <?php echo h(approval_ko('%EC%9E%91%EC%84%B1%20%EB%82%B4%EC%9A%A9%EC%9D%84%20%ED%99%95%EC%9D%B8%ED%95%9C%20%EB%92%A4%20%EC%A0%84%EC%9E%90%EA%B2%B0%EC%9E%AC%20%EB%B3%B4%EB%82%B4%EA%B8%B0%EB%A5%BC%20%EB%88%84%EB%A5%B4%EB%A9%B4%20%EA%B2%B0%EC%9E%AC%EC%84%A0%20%EC%88%9C%EC%84%9C%EB%8C%80%EB%A1%9C%20%EC%9A%94%EC%B2%AD%EC%9D%B4%20%EC%A0%84%EB%8B%AC%EB%90%A9%EB%8B%88%EB%8B%A4.')); ?>
    </div>
    <div class="mt-4 flex items-center justify-between gap-3">
        <div class="flex gap-2">
            <a href="?r=approval_home" onclick="if(history.length>1){history.back();return false;}" class="px-4 py-3 bg-white border-2 border-gray-400 rounded-xl font-bold text-gray-800"><?php echo h(approval_ko('%EB%92%A4%EB%A1%9C%EA%B0%80%EA%B8%B0')); ?></a>
            <a href="?r=approval_home" class="px-4 py-3 bg-white border-2 border-gray-400 rounded-xl font-bold text-gray-800"><?php echo h(approval_ko('%EB%AA%A9%EB%A1%9D%EC%9C%BC%EB%A1%9C')); ?></a>
        </div>
        <button type="submit" class="px-8 py-4 rounded-2xl bg-indigo-600 text-white font-extrabold shadow-lg"><?php echo h(approval_ko('%EC%A0%84%EC%9E%90%EA%B2%B0%EC%9E%AC%20%EB%B3%B4%EB%82%B4%EA%B8%B0')); ?></button>
    </div>
</form>
