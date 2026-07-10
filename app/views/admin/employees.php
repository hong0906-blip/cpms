<?php
use App\Core\Db;
use App\Core\Auth;

require_once __DIR__ . '/leave_management_helpers.php';
require_once __DIR__ . '/../../services/EmployeeVehicleService.php';

$canManage = Auth::canManageEmployees();
if (!$canManage) {
    echo '<div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 font-bold">접근 권한이 없습니다. (임원/관리 전용)</div>';
    return;
}

$pdo = Db::pdo();
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$employeeView = isset($_GET['employee_view']) ? (string)$_GET['employee_view'] : 'active';
if (!in_array($employeeView, array('active', 'retired'), true)) $employeeView = 'active';
$isRetiredView = ($employeeView === 'retired');
$rows = array();
$dbOk = ($pdo !== null);
$employeeLoadError = '';

if ($dbOk) {
    cpms_leave_apply_accruals_until($pdo, date('Y-m-d'));
}

if (!function_exists('cpms_employee_can_assign_development_department')) {
function cpms_employee_can_assign_development_department() {
    if (method_exists('App\\Core\\Auth', 'canAssignDevelopmentDepartment')) {
        return Auth::canAssignDevelopmentDepartment();
    }
    $dept = trim((string)Auth::userDepartment());
    if ($dept === '관리' || $dept === '관리부' || $dept === '관리팀') return true;
    $values = array(Auth::userRole(), Auth::userPosition(), Auth::userName());
    $words = array('대표', '대표이사', '대표님', '부사장');
    for ($i = 0; $i < count($values); $i++) {
        $value = trim((string)$values[$i]);
        if ($value === '') continue;
        for ($j = 0; $j < count($words); $j++) {
            if (strpos($value, $words[$j]) !== false) return true;
        }
    }
    return false;
}}

if (!function_exists('cpms_employee_can_assign_executive_role')) {
function cpms_employee_can_assign_executive_role() {
    if (method_exists('App\\Core\\Auth', 'canAssignExecutiveRole')) {
        return Auth::canAssignExecutiveRole();
    }
    return cpms_employee_can_assign_development_department();
}}

$canAssignDevelopmentDept = cpms_employee_can_assign_development_department();
$canAssignExecutiveRole = cpms_employee_can_assign_executive_role();
$deptOptions = array('관리', '공무', '품질', '안전', '보건', '공사');
if ($canAssignDevelopmentDept) $deptOptions[] = '개발';
$positionOptions = array('주임','대리','과장','차장','부장','이사','전무','상무','부사장','고문','대표');

if (!function_exists('cpms_balance_badge')) {
function cpms_balance_badge($label, $value) {
    $labelEsc = h((string)$label);
    $numValue = null;
    $displayValue = '-';
    $isMinus = false;

    if ($value !== null && $value !== '') {
        if (is_numeric($value)) {
            $numValue = (float)$value;
            $isMinus = ($numValue < 0);
            if (floor($numValue) == $numValue) {
                $displayValue = (string)(int)$numValue;
            } else {
                $displayValue = number_format($numValue, 1, '.', '');
            }
        } else {
            $displayValue = (string)$value;
        }
    }

    $valueEsc = h($displayValue);
    if ($isMinus) {
        return '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-red-100 text-red-700 border border-red-200">'
            .$labelEsc.' '.$valueEsc.' <span>청산필요</span></span>';
    }

    return '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-gray-100 text-gray-700 border border-gray-200">'
        .$labelEsc.' '.$valueEsc.'</span>';
}}

if (!function_exists('cpms_column_exists')) {
function cpms_column_exists($pdo, $table, $column) {
    try {
        $dbName = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
        if ($dbName === '') return false;
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=:db AND TABLE_NAME=:tbl AND COLUMN_NAME=:col");
        $st->execute(array(':db'=>$dbName, ':tbl'=>$table, ':col'=>$column));
        return ((int)$st->fetchColumn() > 0);
    } catch (\Exception $e) { return false; }
}}

if (!function_exists('cpms_employee_table_exists')) {
function cpms_employee_table_exists($pdo, $table) {
    try {
        $st = $pdo->prepare("SHOW TABLES LIKE :tbl");
        $st->execute(array(':tbl' => $table));
        return (bool)$st->fetch(PDO::FETCH_NUM);
    } catch (\Exception $e) { return false; }
}}

if (!function_exists('cpms_employee_signature_file_by_email')) {
function cpms_employee_signature_file_by_email($email) {
    $parts = explode('@', (string)$email);
    $name = isset($parts[0]) ? trim((string)$parts[0]) : '';
    if ($name === '') return '';
    $exts = array('png', 'PNG', 'jpg', 'JPG', 'jpeg', 'JPEG', 'webp', 'WEBP');
    $baseDirs = array('storage/signatures', 'public/storage/signatures');
    $root = dirname(dirname(dirname(__DIR__)));
    for ($i = 0; $i < count($baseDirs); $i++) {
        for ($j = 0; $j < count($exts); $j++) {
            $rel = $baseDirs[$i] . '/' . $name . '.' . $exts[$j];
            if (is_file($root . '/' . $rel)) return $rel;
        }
    }
    return '';
}}

if (!function_exists('cpms_employee_signature_effective_path')) {
function cpms_employee_signature_effective_path($row) {
    $stored = isset($row['signature_path']) ? trim((string)$row['signature_path']) : '';
    if ($stored !== '') return array('path' => $stored, 'source' => 'stored');
    $email = isset($row['email']) ? trim((string)$row['email']) : '';
    $matched = cpms_employee_signature_file_by_email($email);
    if ($matched !== '') return array('path' => $matched, 'source' => 'matched');
    return array('path' => '', 'source' => '');
}}

if (!function_exists('cpms_employee_construction_pm_id')) {
function cpms_employee_construction_pm_id($pdo) {
    if (!$pdo || !cpms_employee_table_exists($pdo, 'cpms_approval_settings')) return 0;
    try {
        $st = $pdo->prepare("SELECT setting_value FROM cpms_approval_settings WHERE setting_key IN ('approval_construction_pm_employee_id','construction_pm_employee_id') AND setting_value IS NOT NULL AND TRIM(setting_value) <> '' ORDER BY CASE setting_key WHEN 'approval_construction_pm_employee_id' THEN 1 ELSE 2 END LIMIT 1");
        $st->execute();
        return (int)$st->fetchColumn();
    } catch (\Exception $e) { return 0; }
}}

$constructionPmEmployeeId = $dbOk ? cpms_employee_construction_pm_id($pdo) : 0;

// 직원명부 컬럼 존재 여부 체크
$positionEnabled = $dbOk ? cpms_column_exists($pdo, 'employees', 'position') : false;
$hireDateEnabled = $dbOk ? cpms_column_exists($pdo, 'employees', 'hire_date') : false;
$resignDateEnabled = $dbOk ? cpms_column_exists($pdo, 'employees', 'resign_date') : false;
$monthlyRegularWageEnabled = $dbOk ? cpms_column_exists($pdo, 'employees', 'monthly_regular_wage') : false;
$leaveMonthlyEnabled = $dbOk ? cpms_column_exists($pdo, 'employees', 'leave_monthly_balance') : false;
$leaveAnnualEnabled = $dbOk ? cpms_column_exists($pdo, 'employees', 'leave_annual_balance') : false;
$leaveHalfEnabled = $dbOk ? cpms_column_exists($pdo, 'employees', 'leave_half_balance') : false;
$birthDateEnabled = $dbOk ? cpms_column_exists($pdo, 'employees', 'birth_date') : false;
$siteManagerEnabled = $dbOk ? cpms_column_exists($pdo, 'employees', 'approval_can_be_site_manager') : false;
$teamLeaderEnabled = $dbOk ? cpms_column_exists($pdo, 'employees', 'approval_can_be_team_leader') : false;
$gongmuEnabled = $dbOk ? cpms_column_exists($pdo, 'employees', 'approval_can_be_gongmu_approver') : false;
$manageApproverEnabled = $dbOk ? cpms_column_exists($pdo, 'employees', 'approval_can_be_manage_approver') : false;
$isTeamLeaderEnabled = $dbOk ? cpms_column_exists($pdo, 'employees', 'is_team_leader') : false;
$teamLeaderIdEnabled = $dbOk ? cpms_column_exists($pdo, 'employees', 'team_leader_id') : false;
$googleChatEnabled = $dbOk ? cpms_column_exists($pdo, 'employees', 'google_chat_enabled') : false;
$googleChatUserEnabled = $dbOk ? cpms_column_exists($pdo, 'employees', 'google_chat_user_name') : false;
$googleChatSpaceEnabled = $dbOk ? cpms_column_exists($pdo, 'employees', 'google_chat_dm_space_name') : false;
$photoPathEnabled = $dbOk ? cpms_column_exists($pdo, 'employees', 'photo_path') : false;
$employeeNoEnabled = $dbOk ? cpms_column_exists($pdo, 'employees', 'employee_no') : false;
$phoneEnabled = $dbOk ? cpms_column_exists($pdo, 'employees', 'phone') : false;
$workLocationEnabled = $dbOk ? cpms_column_exists($pdo, 'employees', 'work_location') : false;
$signaturePathEnabled = $dbOk ? cpms_column_exists($pdo, 'employees', 'signature_path') : false;
$vehicleNumbersEnabled = $dbOk ? cpms_column_exists($pdo, 'employees', 'vehicle_numbers') : false;
$vehicleNumberEnabled = $dbOk ? cpms_column_exists($pdo, 'employees', 'vehicle_number') : false;


if ($dbOk) {
    $positionSelect = $positionEnabled ? 'position' : "'' AS position";
    $hireDateSelect = $hireDateEnabled ? 'hire_date' : 'NULL AS hire_date';
    $resignDateSelect = $resignDateEnabled ? 'resign_date' : 'NULL AS resign_date';
    $wageSelect = $monthlyRegularWageEnabled ? 'monthly_regular_wage' : 'NULL AS monthly_regular_wage';
    $lmSelect = $leaveMonthlyEnabled ? 'leave_monthly_balance' : 'NULL AS leave_monthly_balance';
    $laSelect = $leaveAnnualEnabled ? 'leave_annual_balance' : 'NULL AS leave_annual_balance';
    $lhSelect = $leaveHalfEnabled ? 'leave_half_balance' : 'NULL AS leave_half_balance';
    $birthDateSelect = $birthDateEnabled ? 'birth_date' : 'NULL AS birth_date';
    $siteManagerSelect = $siteManagerEnabled ? 'approval_can_be_site_manager' : '0 AS approval_can_be_site_manager';
    $teamLeaderSelect = $teamLeaderEnabled ? 'approval_can_be_team_leader' : '0 AS approval_can_be_team_leader';
    $gongmuSelect = $gongmuEnabled ? 'approval_can_be_gongmu_approver' : '0 AS approval_can_be_gongmu_approver';
    $manageSelect = $manageApproverEnabled ? 'approval_can_be_manage_approver' : '0 AS approval_can_be_manage_approver';
    $isTeamLeaderSelect = $isTeamLeaderEnabled ? 'is_team_leader' : '0 AS is_team_leader';
    $teamLeaderIdSelect = $teamLeaderIdEnabled ? 'team_leader_id' : '0 AS team_leader_id';
    $chatEnabledSelect = $googleChatEnabled ? 'google_chat_enabled' : '0 AS google_chat_enabled';
    $chatUserSelect = $googleChatUserEnabled ? 'google_chat_user_name' : "'' AS google_chat_user_name";
    $chatSpaceSelect = $googleChatSpaceEnabled ? 'google_chat_dm_space_name' : "'' AS google_chat_dm_space_name";
    $photoPathSelect = $photoPathEnabled ? 'photo_path' : "'' AS photo_path";
    $employeeNoSelect = $employeeNoEnabled ? 'employee_no' : "'' AS employee_no";
    $phoneSelect = $phoneEnabled ? 'phone' : "'' AS phone";
    $workLocationSelect = $workLocationEnabled ? 'work_location' : "'' AS work_location";
    $signaturePathSelect = $signaturePathEnabled ? 'signature_path' : "'' AS signature_path";
    $vehicleNumbersSelect = $vehicleNumbersEnabled ? 'vehicle_numbers' : "'' AS vehicle_numbers";
    $vehicleNumberSelect = $vehicleNumberEnabled ? 'vehicle_number' : "'' AS vehicle_number";

    $sql = "SELECT id,email,name,department,{$positionSelect},{$hireDateSelect},{$resignDateSelect},{$wageSelect},{$lmSelect},{$laSelect},{$lhSelect},{$birthDateSelect},{$siteManagerSelect},{$teamLeaderSelect},{$gongmuSelect},{$manageSelect},{$isTeamLeaderSelect},{$teamLeaderIdSelect},{$chatEnabledSelect},{$chatUserSelect},{$chatSpaceSelect},{$photoPathSelect},{$employeeNoSelect},{$phoneSelect},{$workLocationSelect},{$signaturePathSelect},{$vehicleNumbersSelect},{$vehicleNumberSelect},role,is_active FROM employees WHERE 1=1";
    $params = array();
    if ($isRetiredView) {
        $sql .= " AND is_active=0";
    } else {
        $sql .= " AND is_active=1";
    }
    if ($q !== '') {
        $sql .= " AND (email LIKE :q OR name LIKE :q OR department LIKE :q" . ($positionEnabled ? " OR position LIKE :q" : "") . ($employeeNoEnabled ? " OR employee_no LIKE :q" : "") . ($phoneEnabled ? " OR phone LIKE :q" : "") . ($workLocationEnabled ? " OR work_location LIKE :q" : "") . ")";
        $params[':q'] = '%'.$q.'%';
    }
    if ($isRetiredView) {
        if ($resignDateEnabled) {
            $sql .= " ORDER BY CASE WHEN resign_date IS NULL OR CAST(resign_date AS CHAR) = '' THEN 1 ELSE 0 END ASC, resign_date DESC, name ASC, id DESC LIMIT 500";
        } else {
            $sql .= " ORDER BY name ASC, id DESC LIMIT 500";
        }
    } else {
        $sql .= " ORDER BY CASE position"
            . " WHEN '대표' THEN 1"
            . " WHEN '대표이사' THEN 1"
            . " WHEN '부사장' THEN 2"
            . " WHEN '고문' THEN 3"
            . " WHEN '전무' THEN 4"
            . " WHEN '상무' THEN 5"
            . " WHEN '부장' THEN 6"
            . " WHEN '차장' THEN 7"
            . " WHEN '과장' THEN 8"
            . " WHEN '대리' THEN 9"
            . " WHEN '주임' THEN 10"
            . " ELSE 99 END ASC,"
            . " CASE WHEN hire_date IS NULL OR CAST(hire_date AS CHAR) = '' THEN 1 ELSE 0 END ASC,"
            . " hire_date ASC, name ASC, id DESC LIMIT 500";
    }

    // 직원명부 안전 SELECT
    try {
        $st = $pdo->prepare($sql);
        foreach ($params as $k => $v) $st->bindValue($k, $v);
        $st->execute();
        $rows = $st->fetchAll();
        if (is_array($rows)) {
            for ($i = 0; $i < count($rows); $i++) {
                $employeeId = isset($rows[$i]['id']) ? (int)$rows[$i]['id'] : 0;
                if ($employeeId <= 0) continue;
                $normalized = cpms_leave_normalize_employee_balances($pdo, $employeeId);
                if ($normalized['leave_monthly_balance'] !== null) $rows[$i]['leave_monthly_balance'] = $normalized['leave_monthly_balance'];
                if ($normalized['leave_annual_balance'] !== null) $rows[$i]['leave_annual_balance'] = $normalized['leave_annual_balance'];
                if ($normalized['leave_half_balance'] !== null) $rows[$i]['leave_half_balance'] = $normalized['leave_half_balance'];
                $rows[$i]['vehicle_numbers_display'] = cpms_employee_vehicle_display(cpms_employee_vehicle_row_numbers($rows[$i]));
                $signatureInfo = cpms_employee_signature_effective_path($rows[$i]);
                $rows[$i]['signature_effective_path'] = isset($signatureInfo['path']) ? (string)$signatureInfo['path'] : '';
                $rows[$i]['signature_effective_source'] = isset($signatureInfo['source']) ? (string)$signatureInfo['source'] : '';
            }
        }
    } catch (\Exception $e) {
        $rows = array();
        $employeeLoadError = '직원명부 조회 중 오류가 발생했습니다: '.$e->getMessage();
    }
}

if (!function_exists('cpms_employee_team_dept_key')) {
function cpms_employee_team_dept_key($dept) {
    $dept = trim((string)$dept);
    $dept = str_replace(array(' ', "\t", "\r", "\n"), '', $dept);
    $suffixes = array(urldecode('%EB%B6%80'), urldecode('%ED%8C%80'));
    for ($i = 0; $i < count($suffixes); $i++) {
        $suffix = $suffixes[$i];
        $len = function_exists('mb_strlen') ? mb_strlen($suffix, 'UTF-8') : strlen($suffix);
        if ($suffix !== '' && function_exists('mb_substr')) {
            if (mb_substr($dept, -$len, $len, 'UTF-8') === $suffix) $dept = mb_substr($dept, 0, mb_strlen($dept, 'UTF-8') - $len, 'UTF-8');
        }
    }
    $map = array(
        'gongmu' => urldecode('%EA%B3%B5%EB%AC%B4'),
        'manage' => urldecode('%EA%B4%80%EB%A6%AC'),
        'construction' => urldecode('%EA%B3%B5%EC%82%AC'),
        'safety' => urldecode('%EC%95%88%EC%A0%84'),
        'health' => urldecode('%EB%B3%B4%EA%B1%B4'),
        'quality' => urldecode('%ED%92%88%EC%A7%88')
    );
    foreach ($map as $key => $label) {
        if ($dept === $label) return $key;
    }
    return '';
}}

$teamLeaderCandidates = array();
if ($dbOk) {
    try {
        $positionSelect2 = $positionEnabled ? 'position' : "'' AS position";
        $isTeamLeaderSelect2 = $isTeamLeaderEnabled ? 'is_team_leader' : '0 AS is_team_leader';
        $approvalLeadSelect2 = $teamLeaderEnabled ? 'approval_can_be_team_leader' : '0 AS approval_can_be_team_leader';
        $isTeamLeaderOrder = $isTeamLeaderEnabled ? 'is_team_leader' : '0';
        $approvalLeadOrder = $teamLeaderEnabled ? 'approval_can_be_team_leader' : '0';
        $sqlLead = "SELECT id,name,department,{$positionSelect2},{$isTeamLeaderSelect2},{$approvalLeadSelect2} FROM employees WHERE is_active=1 ORDER BY department ASC, CASE WHEN {$isTeamLeaderOrder}=1 OR {$approvalLeadOrder}=1 THEN 0 ELSE 1 END ASC, name ASC";
        $teamLeaderCandidates = $pdo->query($sqlLead)->fetchAll();
        if (!is_array($teamLeaderCandidates)) $teamLeaderCandidates = array();
    } catch (\Exception $e) {
        $teamLeaderCandidates = array();
    }
}
$teamLeaderOptionsHtml = '<option value="">(나의 팀장 선택 없음)</option>';
for ($i = 0; $i < count($teamLeaderCandidates); $i++) {
    $tl = $teamLeaderCandidates[$i];
    $tlId = isset($tl['id']) ? (int)$tl['id'] : 0;
    if ($tlId <= 0) continue;
    $tlLabel = isset($tl['name']) ? (string)$tl['name'] : '';
    $tlDept = isset($tl['department']) ? trim((string)$tl['department']) : '';
    $tlPos = isset($tl['position']) ? trim((string)$tl['position']) : '';
    if ($tlDept !== '' || $tlPos !== '') $tlLabel .= ' / ' . $tlDept . ' / ' . $tlPos;
    $teamLeaderOptionsHtml .= '<option value="' . $tlId . '" data-team-dept="' . h(cpms_employee_team_dept_key($tlDept)) . '">' . h($tlLabel) . '</option>';
}
?>
<div class="flex items-center justify-between mb-6">
  <div>
    <h2 class="text-2xl font-extrabold text-gray-900">직원명부</h2>
  </div>
  <div class="flex flex-wrap gap-2 justify-end">
    <form method="post" action="?r=admin/employees_save" onsubmit="return confirm('입사날짜 기준으로 기존 사번을 다시 생성합니다. 진행할까요?');">
      <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
      <input type="hidden" name="action" value="generate_employee_numbers">
      <input type="hidden" name="employee_view" value="<?php echo h($employeeView); ?>">
      <button type="submit" class="px-5 py-3 rounded-2xl border bg-white text-gray-800 font-extrabold">사번 일괄 생성</button>
    </form>
    <button type="button" class="px-5 py-3 rounded-2xl bg-gradient-to-r from-emerald-500 to-teal-500 text-white font-extrabold" data-modal-open="empAdd">직원 추가</button>
  </div>
</div>
<?php $flash = flash_get(); // 직원명부 flash 메시지 ?>
<?php if (is_array($flash) && !empty($flash['message'])): ?>
  <div class="mb-4 p-4 rounded-2xl border <?php echo ($flash['type']==='success')?'bg-emerald-50 border-emerald-200 text-emerald-700':'bg-red-50 border-red-200 text-red-700'; ?>">
    <?php echo h($flash['message']); ?>
  </div>
<?php endif; ?>
<?php if (!empty($employeeLoadError)): ?><div class="mb-4 border border-red-300 bg-red-50 text-red-700 p-3 rounded"><?php echo h($employeeLoadError); ?></div><?php endif; ?>

<div class="flex flex-wrap gap-2 mb-4">
  <a class="px-4 py-2 rounded-2xl border font-bold <?php echo !$isRetiredView ? 'bg-emerald-500 text-white border-emerald-500' : 'bg-white text-gray-700 border-gray-200'; ?>" href="?r=관리&tab=employees&employee_view=active<?php echo ($q !== '') ? '&q=' . rawurlencode($q) : ''; ?>">재직자</a>
  <a class="px-4 py-2 rounded-2xl border font-bold <?php echo $isRetiredView ? 'bg-gray-800 text-white border-gray-800' : 'bg-white text-gray-700 border-gray-200'; ?>" href="?r=관리&tab=employees&employee_view=retired<?php echo ($q !== '') ? '&q=' . rawurlencode($q) : ''; ?>">퇴직자</a>
</div>

<div class="bg-white/80 rounded-3xl shadow p-6 mb-6 border border-gray-100"><form method="get" class="flex gap-3 items-center"><input type="hidden" name="r" value="관리"><input type="hidden" name="tab" value="employees"><input type="hidden" name="employee_view" value="<?php echo h($employeeView); ?>"><input class="w-full px-4 py-3 rounded-2xl border" name="q" value="<?php echo h($q); ?>" placeholder="이메일/이름/사번/연락처/위치/부서/직급 검색"><button class="px-5 py-3 rounded-2xl border bg-white">검색</button></form></div>

<div class="bg-white/80 rounded-3xl shadow border border-gray-100 overflow-hidden"><div class="overflow-x-auto"><table class="min-w-full text-sm"><thead class="bg-gray-50"><tr><th class="px-4 py-3">사진</th><th class="px-4 py-3">이름</th><th class="px-4 py-3">사번</th><th class="px-4 py-3">연락처</th><th class="px-4 py-3">위치</th><th class="px-4 py-3">이메일</th><th class="px-4 py-3">부서</th><th class="px-4 py-3">입사일</th><?php if ($isRetiredView): ?><th class="px-4 py-3">퇴직일</th><?php endif; ?><th class="px-4 py-3">직급</th><th class="px-4 py-3">차량번호</th><th class="px-4 py-3">서명</th><th class="px-4 py-3">권한</th><th class="px-4 py-3">상태</th><th class="px-4 py-3">관리</th></tr></thead><tbody class="divide-y">
<?php foreach($rows as $r): $first = mb_substr((string)$r['name'],0,1,'UTF-8'); $photoPath = isset($r['photo_path']) ? (string)$r['photo_path'] : ''; ?>
<tr>
<td class="px-4 py-3"><div class="w-10 h-10 rounded-2xl bg-emerald-100 flex items-center justify-center font-bold text-emerald-700 relative overflow-hidden"><?php if($photoPath !== ''): ?><img src="<?php echo h($photoPath); ?>" class="w-10 h-10 rounded-2xl object-cover absolute inset-0" onerror="this.style.display='none';"><?php endif; ?><span><?php echo h($first); ?></span></div></td>
<td class="px-4 py-3 font-bold"><?php echo h($r['name']); ?></td><td class="px-4 py-3"><?php echo h(isset($r['employee_no']) && trim((string)$r['employee_no']) !== '' ? $r['employee_no'] : '-'); ?></td><td class="px-4 py-3"><?php echo h(isset($r['phone']) && trim((string)$r['phone']) !== '' ? $r['phone'] : '-'); ?></td><td class="px-4 py-3"><?php echo h(isset($r['work_location']) && trim((string)$r['work_location']) !== '' ? $r['work_location'] : '-'); ?></td><td class="px-4 py-3"><?php echo h($r['email']); ?></td><td class="px-4 py-3"><?php echo h($r['department']); ?></td><td class="px-4 py-3"><?php echo h($r['hire_date'] ? $r['hire_date'] : '-'); ?></td><?php if ($isRetiredView): ?><td class="px-4 py-3"><?php echo h(isset($r['resign_date']) && $r['resign_date'] ? $r['resign_date'] : '-'); ?></td><?php endif; ?><td class="px-4 py-3"><?php echo h($r['position']); ?></td><td class="px-4 py-3"><?php echo h(isset($r['vehicle_numbers_display']) && $r['vehicle_numbers_display'] !== '' ? $r['vehicle_numbers_display'] : '-'); ?></td><td class="px-4 py-3"><?php $signatureEffectivePath = isset($r['signature_effective_path']) ? trim((string)$r['signature_effective_path']) : ''; $signatureEffectiveSource = isset($r['signature_effective_source']) ? trim((string)$r['signature_effective_source']) : ''; if ($signatureEffectivePath !== '') { echo $signatureEffectiveSource === 'matched' ? '<span class="text-sky-700 font-bold">기존</span>' : '<span class="text-emerald-700 font-bold">등록</span>'; } else { echo '-'; } ?></td><td class="px-4 py-3"><?php echo h($r['role']==='executive'?'임원':'직원'); ?></td><td class="px-4 py-3"><?php echo ((int)$r['is_active']===1)?'재직':'퇴직'; ?></td>
<td class="px-4 py-3"><div class="flex gap-2"><button type="button" class="px-3 py-2 border rounded-2xl" data-emp-edit="<?php echo (int)$r['id']; ?>" data-emp-email="<?php echo h($r['email']); ?>" data-emp-name="<?php echo h($r['name']); ?>" data-emp-employee-no="<?php echo h(isset($r['employee_no']) ? $r['employee_no'] : ''); ?>" data-emp-phone="<?php echo h(isset($r['phone']) ? $r['phone'] : ''); ?>" data-emp-work-location="<?php echo h(isset($r['work_location']) ? $r['work_location'] : ''); ?>" data-emp-signature="<?php echo h(isset($r['signature_effective_path']) ? $r['signature_effective_path'] : ''); ?>" data-emp-signature-source="<?php echo h(isset($r['signature_effective_source']) ? $r['signature_effective_source'] : ''); ?>" data-emp-dept="<?php echo h($r['department']); ?>" data-emp-pos="<?php echo h($r['position']); ?>" data-emp-role="<?php echo h($r['role']); ?>" data-emp-active="<?php echo (int)$r['is_active']; ?>" data-emp-hire-date="<?php echo h($r['hire_date']); ?>" data-emp-resign-date="<?php echo h(isset($r['resign_date']) ? $r['resign_date'] : ''); ?>" data-emp-wage="<?php echo h(isset($r['monthly_regular_wage']) ? $r['monthly_regular_wage'] : ''); ?>" data-emp-lbm="<?php echo h($r['leave_monthly_balance']); ?>" data-emp-lba="<?php echo h($r['leave_annual_balance']); ?>" data-emp-lbh="<?php echo h($r['leave_half_balance']); ?>" data-emp-birth-date="<?php echo h(isset($r['birth_date']) ? $r['birth_date'] : ''); ?>" data-emp-can-site="<?php echo h(isset($r['approval_can_be_site_manager']) ? $r['approval_can_be_site_manager'] : '0'); ?>" data-emp-can-lead="<?php echo h(isset($r['approval_can_be_team_leader']) ? $r['approval_can_be_team_leader'] : '0'); ?>" data-emp-can-gongmu="<?php echo h(isset($r['approval_can_be_gongmu_approver']) ? $r['approval_can_be_gongmu_approver'] : '0'); ?>" data-emp-can-manage="<?php echo h(isset($r['approval_can_be_manage_approver']) ? $r['approval_can_be_manage_approver'] : '0'); ?>" data-emp-is-team-leader="<?php echo h(isset($r['is_team_leader']) ? $r['is_team_leader'] : '0'); ?>" data-emp-team-leader-id="<?php echo h(isset($r['team_leader_id']) ? $r['team_leader_id'] : ''); ?>" data-emp-chat-enabled="<?php echo h(isset($r['google_chat_enabled']) ? $r['google_chat_enabled'] : '0'); ?>" data-emp-chat-user="<?php echo h(isset($r['google_chat_user_name']) ? $r['google_chat_user_name'] : ''); ?>" data-emp-chat-space="<?php echo h(isset($r['google_chat_dm_space_name']) ? $r['google_chat_dm_space_name'] : ''); ?>" data-emp-vehicle-numbers="<?php echo h(isset($r['vehicle_numbers_display']) ? $r['vehicle_numbers_display'] : ''); ?>" data-emp-photo="<?php echo h(isset($r['photo_path']) ? $r['photo_path'] : ''); ?>">수정</button><button type="button" class="px-3 py-2 border border-red-200 text-red-700 rounded-2xl" data-emp-delete="<?php echo (int)$r['id']; ?>" data-emp-name-for="<?php echo h($r['name']); ?>">삭제</button></div></td>
</tr>
<?php endforeach; ?>
</tbody></table></div></div>

<?php /* 직원 추가 모달 입사일/휴가잔여 */ ?>
<div id="modal-empAdd" class="fixed inset-0 z-50 hidden"><div class="absolute inset-0 bg-black/40" data-modal-close="empAdd"></div><div class="absolute inset-0 flex items-center justify-center p-4"><div class="w-full max-w-6xl bg-white rounded-3xl p-6" style="max-height:90vh;overflow-y:auto;position:relative;"><button type="button" class="absolute right-4 top-4 px-3 py-1 border rounded-xl" data-modal-close="empAdd">✕</button><form method="post" action="?r=admin/employees_save" class="space-y-3" enctype="multipart/form-data"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="save"><div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3"><input class="w-full px-4 py-2 border rounded-2xl" name="email" placeholder="이메일" required><input class="w-full px-4 py-2 border rounded-2xl" name="name" placeholder="이름" required><select class="w-full px-4 py-2 border rounded-2xl" name="department"><option value="">(부서)</option><?php foreach($deptOptions as $d): ?><option value="<?php echo h($d); ?>"><?php echo h($d); ?></option><?php endforeach; ?></select><select class="w-full px-4 py-2 border rounded-2xl" name="position"><option value="">(직급)</option><?php foreach($positionOptions as $p): ?><option value="<?php echo h($p); ?>"><?php echo h($p); ?></option><?php endforeach; ?></select><?php if ($canAssignExecutiveRole): ?><select class="w-full px-4 py-2 border rounded-2xl" name="role"><option value="employee">직원</option><option value="executive">임원</option></select><?php else: ?><input type="hidden" name="role" value="employee"><?php endif; ?><select class="w-full px-4 py-2 border rounded-2xl" name="is_active"><option value="1">재직</option><option value="0">퇴직</option></select></div><div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3"><input type="date" class="w-full px-4 py-2 border rounded-2xl mt-1" name="hire_date" id="empAddHireDate" placeholder="입사날짜"><input type="date" class="w-full px-4 py-2 border rounded-2xl mt-1" name="birth_date" id="empAddBirthDate" placeholder="생년월일"><input type="number" step="0.5" class="px-3 py-2 border rounded-2xl" name="leave_monthly_balance" placeholder="남은 월차"><input type="number" step="0.5" class="px-3 py-2 border rounded-2xl" name="leave_annual_balance" placeholder="남은 연차"><input type="number" step="0.5" class="px-3 py-2 border rounded-2xl" name="leave_half_balance" placeholder="남은 반차"></div><div class="grid grid-cols-1 md:grid-cols-2 gap-3"><div class="border rounded-2xl p-3 space-y-1"><div class="font-bold">전자결재 역할</div><label class="block"><input type="checkbox" name="approval_can_be_site_manager" value="1"> 소장 결재자</label><label class="block"><input type="checkbox" name="approval_can_be_team_leader" value="1"> 팀장 결재자</label><label class="block"><input type="checkbox" name="approval_can_be_gongmu_approver" value="1"> 공무 결재자</label><label class="block"><input type="checkbox" name="approval_can_be_manage_approver" value="1"> 관리 결재자</label></div><div class="border rounded-2xl p-3 space-y-2"><div class="font-bold">Google Chat 개인 DM</div><label class="block"><input type="checkbox" name="google_chat_enabled" value="1"> Google Chat 개인 DM 사용</label><input class="w-full px-4 py-2 border rounded-2xl" name="google_chat_user_name" placeholder="Google Chat User Name"><input class="w-full px-4 py-2 border rounded-2xl" name="google_chat_dm_space_name" placeholder="Google Chat DM Space ID"><div class="text-xs text-gray-500">직원 저장 후 수정 화면에서 Google Chat DM Space를 자동 생성할 수 있습니다.</div></div></div><div class="border rounded-2xl p-3 space-y-2"><div class="font-bold">직원 사진</div><input type="file" name="employee_photo" accept="image/jpeg,image/png,image/webp" class="w-full px-4 py-2 border rounded-2xl"><div class="text-xs text-gray-500">JPG, PNG, WEBP 파일만 업로드 가능합니다. 권장 크기: 정사각형 사진</div></div><div class="sticky bottom-0 bg-white pt-3 flex gap-2 justify-end"><button type="button" class="px-4 py-3 rounded-2xl border" data-modal-close="empAdd">저장하지 않고 닫기</button><button class="px-6 py-3 rounded-2xl bg-emerald-500 text-white font-bold">저장</button></div></form></div></div></div>


<?php /* 직원 수정 모달 입사일/휴가잔여 */ ?>
<div id="modal-empEdit" class="fixed inset-0 z-50 hidden"><div class="absolute inset-0 bg-black/40" data-modal-close="empEdit"></div><div class="absolute inset-0 flex items-center justify-center p-4"><div class="w-full max-w-6xl bg-white rounded-3xl p-6" style="max-height:90vh;overflow-y:auto;position:relative;"><button type="button" class="absolute right-4 top-4 px-3 py-1 border rounded-xl" data-modal-close="empEdit">✕</button><form method="post" action="?r=admin/employees_save" class="space-y-3" enctype="multipart/form-data"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="empEditId"><div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3"><input class="w-full px-4 py-2 border rounded-2xl" name="email" id="empEditEmail" required><input class="w-full px-4 py-2 border rounded-2xl" name="name" id="empEditName" required><select class="w-full px-4 py-2 border rounded-2xl" name="department" id="empEditDept"><option value="">(부서)</option><?php foreach($deptOptions as $d): ?><option value="<?php echo h($d); ?>"><?php echo h($d); ?></option><?php endforeach; ?></select><select class="w-full px-4 py-2 border rounded-2xl" name="position" id="empEditPos"><option value="">(직급)</option><?php foreach($positionOptions as $p): ?><option value="<?php echo h($p); ?>"><?php echo h($p); ?></option><?php endforeach; ?></select><?php if ($canAssignExecutiveRole): ?><select class="w-full px-4 py-2 border rounded-2xl" name="role" id="empEditRole"><option value="employee">직원</option><option value="executive">임원</option></select><?php else: ?><input type="hidden" name="role" id="empEditRole" value="employee"><?php endif; ?><select class="w-full px-4 py-2 border rounded-2xl" name="is_active" id="empEditActive"><option value="1">재직</option><option value="0">퇴직</option></select><div class="rounded-2xl border border-emerald-100 bg-emerald-50 p-3"><div class="font-bold text-emerald-800">근무 정보</div><label class="mt-2 block font-semibold text-gray-800" for="empEditHireDate">입사날짜</label><input type="date" class="w-full px-4 py-2 border rounded-2xl mt-1" name="hire_date" id="empEditHireDate"></div><div class="grid grid-cols-3 gap-2"><input type="number" step="0.5" class="px-3 py-2 border rounded-2xl" name="leave_monthly_balance" id="empEditLbm" placeholder="남은 월차"><input type="number" step="0.5" class="px-3 py-2 border rounded-2xl" name="leave_annual_balance" id="empEditLba" placeholder="남은 연차"><input type="number" step="0.5" class="px-3 py-2 border rounded-2xl" name="leave_half_balance" id="empEditLbh" placeholder="남은 반차"></div><div class="rounded-2xl border border-sky-100 bg-sky-50 p-3"><div class="font-bold text-sky-800">개인 정보</div><label class="mt-2 block font-semibold text-gray-800" for="empEditBirthDate">생년월일</label><input type="date" class="w-full px-4 py-2 border rounded-2xl mt-1" name="birth_date" id="empEditBirthDate"></div><div class="border rounded-2xl p-3 space-y-1"><div class="font-bold">전자결재 역할</div><label class="block"><input type="checkbox" name="approval_can_be_site_manager" id="empEditCanSite" value="1"> 소장 결재자</label><label class="block"><input type="checkbox" name="approval_can_be_team_leader" id="empEditCanLead" value="1"> 팀장 결재자</label><label class="block"><input type="checkbox" name="approval_can_be_gongmu_approver" id="empEditCanGongmu" value="1"> 공무 결재자</label><label class="block"><input type="checkbox" name="approval_can_be_manage_approver" id="empEditCanManage" value="1"> 관리 결재자</label></div><div class="border rounded-2xl p-3 space-y-2"><div class="font-bold">Google Chat 개인 DM</div><label class="block"><input type="checkbox" name="google_chat_enabled" id="empEditChatEnabled" value="1"> Google Chat 개인 DM 사용</label><input class="w-full px-4 py-2 border rounded-2xl" name="google_chat_user_name" id="empEditChatUser" placeholder="Google Chat User Name"><input class="w-full px-4 py-2 border rounded-2xl" name="google_chat_dm_space_name" id="empEditChatSpace" placeholder="Google Chat DM Space ID"><div class="flex gap-2"><button type="button" class="px-3 py-2 border rounded-2xl" data-chat-dm-create>DM Space 자동생성</button><button type="button" class="px-3 py-2 border rounded-2xl" data-chat-test>테스트 메시지 보내기</button></div></div></div><div class="border rounded-2xl p-3 space-y-2"><div class="font-bold">현재 사진</div><div id="empEditPhotoPreview" class="text-sm text-gray-600">등록된 사진 없음</div><div><label class="block font-semibold mb-1">새 사진 업로드</label><input type="file" name="employee_photo" id="empEditPhotoInput" accept="image/jpeg,image/png,image/webp" class="w-full px-4 py-2 border rounded-2xl"><div class="text-xs text-gray-500 mt-1">JPG, PNG, WEBP 파일만 업로드 가능합니다. 권장 크기: 정사각형 사진</div></div><label class="block"><input type="checkbox" name="remove_photo" id="empEditRemovePhoto" value="1"> 현재 사진 삭제</label></div><div class="sticky bottom-0 bg-white pt-3 flex gap-2 justify-end"><button type="button" class="px-4 py-3 rounded-2xl border" data-modal-close="empEdit">저장하지 않고 닫기</button><button class="px-6 py-3 rounded-2xl bg-emerald-500 text-white font-bold">수정 저장</button></div></form></div></div></div>
<form id="chatDmCreateForm" method="post" action="?r=approval_google_chat_employee_dm_create" style="display:none;"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="employee_id" id="chatDmCreateEmployeeId"></form><form id="chatTestForm" method="post" action="?r=approval_google_chat_employee_test" style="display:none;"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="employee_id" id="chatTestEmployeeId"></form><div id="modal-empDelete" class="fixed inset-0 z-50 hidden"><div class="absolute inset-0 bg-black/40" data-modal-close="empDelete"></div><div class="absolute inset-0 flex items-center justify-center p-4"><div class="w-full max-w-md bg-white rounded-3xl p-6"><form method="post" action="?r=admin/employees_save" class="space-y-3"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="empDeleteId"><div id="empDeleteName"></div><button class="w-full py-3 rounded-2xl bg-red-600 text-white">삭제</button></form></div></div></div>
<script>
(function(){
function o(name) {
    var el = document.getElementById('modal-' + name);
    if (el) el.classList.remove('hidden');
}
function c(name) {
    var el = document.getElementById('modal-' + name);
    if (el) el.classList.add('hidden');
}    
function setValue(id, value) {
    var el = document.getElementById(id);
    if (el) el.value = value || '';
}
function setChecked(id, checked) {
    var el = document.getElementById(id);
    if (el) el.checked = !!checked;
}
function insertAfterId(anchorId, html) {
    var el = document.getElementById(anchorId);
    if (el && el.insertAdjacentHTML) el.insertAdjacentHTML('afterend', html);
}
function insertAfterInput(selector, html) {
    var el = document.querySelector ? document.querySelector(selector) : null;
    if (el && el.parentNode && el.parentNode.insertAdjacentHTML) el.parentNode.insertAdjacentHTML('afterend', html);
}
var teamLeaderOptions = <?php echo json_encode($teamLeaderOptionsHtml); ?>;
var teamDepartmentKeys = <?php $teamDepartmentKeys = array(); foreach ($deptOptions as $d) { $teamDepartmentKeys[$d] = cpms_employee_team_dept_key($d); } echo json_encode($teamDepartmentKeys); ?>;
insertAfterInput('#modal-empAdd input[name=approval_can_be_team_leader]', '<label class="block"><input type="checkbox" name="approval_can_be_construction_pm" id="empAddCanConstructionPm" value="1"> 공사PM 결재자</label>');
insertAfterInput('#empEditCanLead', '<label class="block"><input type="checkbox" name="approval_can_be_construction_pm" id="empEditCanConstructionPm" value="1"> 공사PM 결재자</label>');
insertAfterId('empAddBirthDate', '<div id="empAddContactBlock" class="border rounded-2xl p-3 space-y-2"><div class="font-bold">연락처/사번/위치/서명</div><input type="text" class="w-full px-4 py-2 border rounded-2xl" name="employee_no" id="empAddEmployeeNo" placeholder="사번"><input type="text" class="w-full px-4 py-2 border rounded-2xl" name="phone" id="empAddPhone" placeholder="연락처 010-0000-0000"><input type="text" class="w-full px-4 py-2 border rounded-2xl" name="work_location" id="empAddWorkLocation" placeholder="위치 예: 본사"><label class="block text-sm font-semibold text-gray-800">전자결재 서명 파일</label><input type="file" name="signature_file" id="empAddSignatureInput" accept="image/jpeg,image/png,image/webp" class="w-full px-4 py-2 border rounded-2xl"><div class="text-xs text-gray-500">JPG, PNG, WEBP 파일만 가능합니다.</div></div>');
insertAfterId('empAddContactBlock', '<div class="border rounded-2xl p-3 space-y-2"><div class="font-bold">팀장 설정</div><label class="block text-sm font-semibold">팀장 선택</label><select class="w-full px-4 py-2 border rounded-2xl" name="is_team_leader" id="empAddIsTeamLeader"><option value="0">일반 직원</option><option value="1">팀장</option></select><label class="block text-sm font-semibold">나의 팀장 선택</label><select class="w-full px-4 py-2 border rounded-2xl" name="team_leader_id" id="empAddTeamLeaderId">' + teamLeaderOptions + '</select><div class="text-xs text-gray-500">선택한 팀장은 전자결재 결재라인에 우선 적용됩니다.</div></div>');
insertAfterId('empEditBirthDate', '<div id="empEditContactBlock" class="border rounded-2xl p-3 space-y-2"><div class="font-bold">연락처/사번/위치/서명</div><input type="text" class="w-full px-4 py-2 border rounded-2xl" name="employee_no" id="empEditEmployeeNo" placeholder="사번"><input type="text" class="w-full px-4 py-2 border rounded-2xl" name="phone" id="empEditPhone" placeholder="연락처 010-0000-0000"><input type="text" class="w-full px-4 py-2 border rounded-2xl" name="work_location" id="empEditWorkLocation" placeholder="위치 예: 본사"><div id="empEditSignaturePreview" class="text-sm text-gray-600">등록된 서명 없음</div><div class="min-h-16 rounded-2xl border bg-white flex items-center justify-center p-2"><img id="empEditSignatureImage" src="" alt="서명 미리보기" class="max-h-12 max-w-full object-contain" style="display:none;"></div><label class="block text-sm font-semibold text-gray-800">새 서명 파일 업로드</label><input type="file" name="signature_file" id="empEditSignatureInput" accept="image/jpeg,image/png,image/webp" class="w-full px-4 py-2 border rounded-2xl"><label class="block"><input type="checkbox" name="remove_signature" id="empEditRemoveSignature" value="1"> 현재 서명 삭제</label><div class="text-xs text-gray-500">전자결재 문서에 들어갈 서명 이미지입니다.</div></div>');
insertAfterId('empEditContactBlock', '<div class="border rounded-2xl p-3 space-y-2"><div class="font-bold">팀장 설정</div><label class="block text-sm font-semibold">팀장 선택</label><select class="w-full px-4 py-2 border rounded-2xl" name="is_team_leader" id="empEditIsTeamLeader"><option value="0">일반 직원</option><option value="1">팀장</option></select><label class="block text-sm font-semibold">나의 팀장 선택</label><select class="w-full px-4 py-2 border rounded-2xl" name="team_leader_id" id="empEditTeamLeaderId">' + teamLeaderOptions + '</select><div class="text-xs text-gray-500">선택한 팀장은 전자결재 결재라인에 우선 적용됩니다.</div></div>');
function syncTeamLeaderFields(prefix) {
    var leader = document.getElementById(prefix + 'TeamLeaderId');
    if (!leader) return;
    leader.disabled = false;
}
function teamDeptKey(value) {
    var rawValue = value || '';
    if (teamDepartmentKeys && Object.prototype.hasOwnProperty.call(teamDepartmentKeys, rawValue)) {
        return teamDepartmentKeys[rawValue] || '';
    }
    value = (value || '').replace(/\s/g, '');
    value = value.replace(/(부|팀)$/, '');
    if (value === '공무') return 'gongmu';
    if (value === '관리') return 'manage';
    if (value === '공사') return 'construction';
    if (value === '품질') return 'quality';
    if (value === '안전') return 'safety';
    if (value === '보건') return 'health';
    return '';
}
function teamDeptAllows(key, optKey) {
    if (key === '' || optKey === '') return true;
    if (key === optKey) return true;
    if (key === 'safety' || key === 'health') {
        return optKey === 'construction' || optKey === 'safety' || optKey === 'health' || optKey === 'quality';
    }
    if (key === 'quality') {
        return optKey === 'quality' || optKey === 'safety' || optKey === 'health';
    }
    return false;
}
function filterTeamLeaderOptions(prefix) {
    var leader = document.getElementById(prefix + 'TeamLeaderId');
    if (!leader) return;
    var deptEl = prefix === 'empEdit' ? document.getElementById('empEditDept') : document.querySelector('#modal-empAdd select[name=department]');
    var key = teamDeptKey(deptEl ? deptEl.value : '');
    var currentId = '';
    if (prefix === 'empEdit') {
        var currentIdEl = document.getElementById('empEditId');
        currentId = currentIdEl ? currentIdEl.value : '';
    }
    var selectedVisible = (leader.value === '');
    for (var i = 0; i < leader.options.length; i++) {
        var option = leader.options[i];
        var optKey = option.getAttribute('data-team-dept') || '';
        var visible = option.value === '' || teamDeptAllows(key, optKey);
        if (currentId !== '' && option.value === currentId) visible = false;
        if (option.selected && visible) selectedVisible = true;
        option.style.display = visible ? '' : 'none';
    }
    if (!selectedVisible) leader.value = '';
}
syncTeamLeaderFields('empAdd');
syncTeamLeaderFields('empEdit');
filterTeamLeaderOptions('empAdd');
filterTeamLeaderOptions('empEdit');
insertAfterId('empAddHireDate', '<input type="date" class="w-full px-4 py-2 border rounded-2xl mt-1" name="resign_date" id="empAddResignDate" placeholder="퇴직일">');
insertAfterId('empAddResignDate', '<input type="number" step="0.01" class="px-3 py-2 border rounded-2xl" name="monthly_regular_wage" id="empAddWage" placeholder="통상임금(월)">');
insertAfterId('empAddWage', '<input type="text" class="w-full px-4 py-2 border rounded-2xl" name="vehicle_numbers" id="empAddVehicleNumbers" placeholder="차량번호 (쉼표로 여러 대 입력)">');
insertAfterId('empEditHireDate', '<input type="date" class="w-full px-4 py-2 border rounded-2xl mt-2" name="resign_date" id="empEditResignDate" placeholder="퇴직일">');
insertAfterId('empEditResignDate', '<input type="number" step="0.01" class="w-full px-4 py-2 border rounded-2xl mt-2" name="monthly_regular_wage" id="empEditWage" placeholder="통상임금(월)">');
insertAfterId('empEditWage', '<input type="text" class="w-full px-4 py-2 border rounded-2xl mt-2" name="vehicle_numbers" id="empEditVehicleNumbers" placeholder="차량번호 (예: 56로1245, 255도3829)">');
document.addEventListener('click', function(e) {
    var t = e.target;
    var op = t.closest ? t.closest('[data-modal-open]') : null;
    if (op) {
        if (op.getAttribute('data-modal-open') === 'empAdd') setChecked('empAddCanConstructionPm', false);
        o(op.getAttribute('data-modal-open'));
        e.preventDefault();
        return;
    }
    var cl = t.closest ? t.closest('[data-modal-close]') : null;
    if (cl) { c(cl.getAttribute('data-modal-close')); e.preventDefault(); return; }
    var be = t.closest ? t.closest('[data-emp-edit]') : null;
    if (be) {
        try {
            var email = be.getAttribute('data-emp-email') || '';
            var chatUser = be.getAttribute('data-emp-chat-user') || '';
            if (chatUser === '' && email !== '') chatUser = 'users/' + email;
            setValue('empEditId', be.getAttribute('data-emp-edit') || '');
            setValue('empEditEmail', email);
            setValue('empEditName', be.getAttribute('data-emp-name') || '');
            setValue('empEditEmployeeNo', be.getAttribute('data-emp-employee-no') || '');
            setValue('empEditPhone', be.getAttribute('data-emp-phone') || '');
            setValue('empEditWorkLocation', be.getAttribute('data-emp-work-location') || '');
            setValue('empEditDept', be.getAttribute('data-emp-dept') || '');
            setValue('empEditPos', be.getAttribute('data-emp-pos') || '');
            setValue('empEditRole', be.getAttribute('data-emp-role') || 'employee');
            setValue('empEditActive', be.getAttribute('data-emp-active') || '1');
            setValue('empEditHireDate', be.getAttribute('data-emp-hire-date') || '');
            setValue('empEditResignDate', be.getAttribute('data-emp-resign-date') || '');
            setValue('empEditWage', be.getAttribute('data-emp-wage') || '');
            setValue('empEditLbm', be.getAttribute('data-emp-lbm') || '');
            setValue('empEditLba', be.getAttribute('data-emp-lba') || '');
            setValue('empEditLbh', be.getAttribute('data-emp-lbh') || '');
            setValue('empEditBirthDate', be.getAttribute('data-emp-birth-date') || '');
            setChecked('empEditCanSite', be.getAttribute('data-emp-can-site') === '1');
            setChecked('empEditCanLead', be.getAttribute('data-emp-can-lead') === '1');
            setChecked('empEditCanConstructionPm', (be.getAttribute('data-emp-edit') || '') === '<?php echo (int)$constructionPmEmployeeId; ?>');
            setChecked('empEditCanGongmu', be.getAttribute('data-emp-can-gongmu') === '1');
            setChecked('empEditCanManage', be.getAttribute('data-emp-can-manage') === '1');
            setValue('empEditIsTeamLeader', be.getAttribute('data-emp-is-team-leader') === '1' ? '1' : '0');
            setValue('empEditTeamLeaderId', be.getAttribute('data-emp-team-leader-id') || '');
            filterTeamLeaderOptions('empEdit');
            syncTeamLeaderFields('empEdit');
            setChecked('empEditChatEnabled', be.getAttribute('data-emp-chat-enabled') === '1');
            setValue('empEditChatUser', chatUser);
            setValue('empEditChatSpace', be.getAttribute('data-emp-chat-space') || '');
            setValue('empEditVehicleNumbers', be.getAttribute('data-emp-vehicle-numbers') || '');
            var photoPath = be.getAttribute('data-emp-photo') || '';
            var photoPreview = document.getElementById('empEditPhotoPreview');
            if (photoPreview) {
                if (photoPath !== '') photoPreview.innerHTML = '<img src="' + photoPath + '" class="w-20 h-20 rounded-2xl object-cover border" onerror="this.style.display=\'none\';this.parentNode.innerHTML=\'등록된 사진 없음\';">';
                else photoPreview.innerHTML = '등록된 사진 없음';
            }
            setChecked('empEditRemovePhoto', false);
            var photoInput = document.getElementById('empEditPhotoInput');
            if (photoInput) photoInput.value = '';
            var signaturePath = be.getAttribute('data-emp-signature') || '';
            var signatureSource = be.getAttribute('data-emp-signature-source') || '';
            var signaturePreview = document.getElementById('empEditSignaturePreview');
            if (signaturePreview) {
                if (signaturePath !== '' && signatureSource === 'matched') signaturePreview.textContent = '기존 서명 자동 매칭: ' + signaturePath;
                else if (signaturePath !== '') signaturePreview.textContent = '등록된 서명: ' + signaturePath;
                else signaturePreview.textContent = '등록된 서명 없음';
            }
            var signatureImage = document.getElementById('empEditSignatureImage');
            if (signatureImage) {
                if (signaturePath !== '') {
                    signatureImage.src = signaturePath;
                    signatureImage.style.display = 'block';
                } else {
                    signatureImage.removeAttribute('src');
                    signatureImage.style.display = 'none';
                }
            }
            var removeSignatureEl = document.getElementById('empEditRemoveSignature');
            if (removeSignatureEl) {
                removeSignatureEl.checked = false;
                removeSignatureEl.disabled = (signatureSource === 'matched');
            }
            var signatureInput = document.getElementById('empEditSignatureInput');
            if (signatureInput) signatureInput.value = '';
        } catch (err) {
            if (window.console) console.error(err);
        }
        o('empEdit');
        e.preventDefault();
        return;
    }
    var dmBtn = t.closest ? t.closest('[data-chat-dm-create]') : null;
    if (dmBtn) {
        var empId = document.getElementById('empEditId') ? document.getElementById('empEditId').value : '';
        if (!empId) { alert('직원을 먼저 선택해주세요.'); e.preventDefault(); return; }
        setValue('chatDmCreateEmployeeId', empId);
        document.getElementById('chatDmCreateForm').submit();
        e.preventDefault();
        return;
    }
    var testBtn = t.closest ? t.closest('[data-chat-test]') : null;
    if (testBtn) {
        var empId2 = document.getElementById('empEditId') ? document.getElementById('empEditId').value : '';
        if (!empId2) { alert('직원을 먼저 선택해주세요.'); e.preventDefault(); return; }
        setValue('chatTestEmployeeId', empId2);
        document.getElementById('chatTestForm').submit();
        e.preventDefault();
        return;
    }
    var bd = t.closest ? t.closest('[data-emp-delete]') : null;
    if (bd) {
        setValue('empDeleteId', bd.getAttribute('data-emp-delete') || '');
        var deleteName = document.getElementById('empDeleteName');
        if (deleteName) deleteName.innerHTML = '대상: ' + (bd.getAttribute('data-emp-name-for') || '');
        o('empDelete');
        e.preventDefault();
        return;
    }
});
document.addEventListener('change', function(e) {
    var t = e.target;
    if (!t) return;
    if (t.id === 'empAddIsTeamLeader') syncTeamLeaderFields('empAdd');
    if (t.id === 'empEditIsTeamLeader') syncTeamLeaderFields('empEdit');
    if (t.name === 'department') filterTeamLeaderOptions('empAdd');
    if (t.id === 'empEditDept') filterTeamLeaderOptions('empEdit');
});
})();
</script>
