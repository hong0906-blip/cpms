<?php
use App\Core\Db;
use App\Core\Auth;

$canManage = Auth::canManageEmployees();
if (!$canManage) {
    echo '<div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 font-bold">접근 권한이 없습니다. (임원/관리 전용)</div>';
    return;
}

$pdo = Db::pdo();
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$rows = array();
$dbOk = ($pdo !== null);
$employeeLoadError = '';

$deptOptions = array('관리', '공무', '품질', '안전', '공사');
$positionOptions = array('주임','대리','과장','차장','부장','이사','전무','상무','부사장','고문','대표');

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

// 직원명부 컬럼 존재 여부 체크
$positionEnabled = $dbOk ? cpms_column_exists($pdo, 'employees', 'position') : false;
$hireDateEnabled = $dbOk ? cpms_column_exists($pdo, 'employees', 'hire_date') : false;
$leaveMonthlyEnabled = $dbOk ? cpms_column_exists($pdo, 'employees', 'leave_monthly_balance') : false;
$leaveAnnualEnabled = $dbOk ? cpms_column_exists($pdo, 'employees', 'leave_annual_balance') : false;
$leaveHalfEnabled = $dbOk ? cpms_column_exists($pdo, 'employees', 'leave_half_balance') : false;
$birthDateEnabled = $dbOk ? cpms_column_exists($pdo, 'employees', 'birth_date') : false;
$siteManagerEnabled = $dbOk ? cpms_column_exists($pdo, 'employees', 'approval_can_be_site_manager') : false;
$teamLeaderEnabled = $dbOk ? cpms_column_exists($pdo, 'employees', 'approval_can_be_team_leader') : false;
$gongmuEnabled = $dbOk ? cpms_column_exists($pdo, 'employees', 'approval_can_be_gongmu_approver') : false;
$manageApproverEnabled = $dbOk ? cpms_column_exists($pdo, 'employees', 'approval_can_be_manage_approver') : false;
$googleChatEnabled = $dbOk ? cpms_column_exists($pdo, 'employees', 'google_chat_enabled') : false;
$googleChatUserEnabled = $dbOk ? cpms_column_exists($pdo, 'employees', 'google_chat_user_name') : false;
$googleChatSpaceEnabled = $dbOk ? cpms_column_exists($pdo, 'employees', 'google_chat_dm_space_name') : false;


if ($dbOk) {
    $positionSelect = $positionEnabled ? 'position' : "'' AS position";
    $hireDateSelect = $hireDateEnabled ? 'hire_date' : 'NULL AS hire_date';
    $lmSelect = $leaveMonthlyEnabled ? 'leave_monthly_balance' : 'NULL AS leave_monthly_balance';
    $laSelect = $leaveAnnualEnabled ? 'leave_annual_balance' : 'NULL AS leave_annual_balance';
    $lhSelect = $leaveHalfEnabled ? 'leave_half_balance' : 'NULL AS leave_half_balance';
    $birthDateSelect = $birthDateEnabled ? 'birth_date' : 'NULL AS birth_date';
    $siteManagerSelect = $siteManagerEnabled ? 'approval_can_be_site_manager' : '0 AS approval_can_be_site_manager';
    $teamLeaderSelect = $teamLeaderEnabled ? 'approval_can_be_team_leader' : '0 AS approval_can_be_team_leader';
    $gongmuSelect = $gongmuEnabled ? 'approval_can_be_gongmu_approver' : '0 AS approval_can_be_gongmu_approver';
    $manageSelect = $manageApproverEnabled ? 'approval_can_be_manage_approver' : '0 AS approval_can_be_manage_approver';
    $chatEnabledSelect = $googleChatEnabled ? 'google_chat_enabled' : '0 AS google_chat_enabled';
    $chatUserSelect = $googleChatUserEnabled ? 'google_chat_user_name' : "'' AS google_chat_user_name";
    $chatSpaceSelect = $googleChatSpaceEnabled ? 'google_chat_dm_space_name' : "'' AS google_chat_dm_space_name";

    $sql = "SELECT id,email,name,department,{$positionSelect},{$hireDateSelect},{$lmSelect},{$laSelect},{$lhSelect},{$birthDateSelect},{$siteManagerSelect},{$teamLeaderSelect},{$gongmuSelect},{$manageSelect},{$chatEnabledSelect},{$chatUserSelect},{$chatSpaceSelect},role,photo_path,is_active FROM employees WHERE 1=1";
    $params = array();
    if ($q !== '') {
        $sql .= " AND (email LIKE :q OR name LIKE :q OR department LIKE :q" . ($positionEnabled ? " OR position LIKE :q" : "") . ")";
        $params[':q'] = '%'.$q.'%';
    }
    $sql .= " ORDER BY is_active DESC, role DESC, department ASC, name ASC, id DESC LIMIT 500";

    // 직원명부 안전 SELECT
    try {
        $st = $pdo->prepare($sql);
        foreach ($params as $k => $v) $st->bindValue($k, $v);
        $st->execute();
        $rows = $st->fetchAll();
    } catch (\Exception $e) {
        $rows = array();
        $employeeLoadError = '직원명부 조회 중 오류가 발생했습니다: '.$e->getMessage();
    }
}
?>
<div class="flex items-center justify-between mb-6">
  <div>
    <div class="text-sm text-gray-500">관리 / 직원명부</div>
    <h2 class="text-2xl font-extrabold text-gray-900">직원명부</h2>
    <div class="text-sm text-gray-500 mt-1">기존 직원명부 UI 복구 + 입사일/휴가 잔여 컬럼 확장.</div>
  </div>
  <button type="button" class="px-5 py-3 rounded-2xl bg-gradient-to-r from-emerald-500 to-teal-500 text-white font-extrabold" data-modal-open="empAdd">직원 추가</button>
</div>
<details class="mb-4 bg-gray-50 border border-gray-200 rounded-2xl p-4"><summary class="font-bold cursor-pointer">직원명부 컬럼 진단 보기</summary><div class="text-xs mt-2">EMPLOYEES_PAGE_LOADED=yes / position=<?php echo $positionEnabled?'yes':'no'; ?> / hire_date=<?php echo $hireDateEnabled?'yes':'no'; ?> / leave_monthly_balance=<?php echo $leaveMonthlyEnabled?'yes':'no'; ?> / leave_annual_balance=<?php echo $leaveAnnualEnabled?'yes':'no'; ?> / leave_half_balance=<?php echo $leaveHalfEnabled?'yes':'no'; ?> / birth_date=<?php echo $birthDateEnabled?'yes':'no'; ?> / approval_can_be_site_manager=<?php echo $siteManagerEnabled?'yes':'no'; ?> / approval_can_be_team_leader=<?php echo $teamLeaderEnabled?'yes':'no'; ?> / approval_can_be_gongmu_approver=<?php echo $gongmuEnabled?'yes':'no'; ?> / approval_can_be_manage_approver=<?php echo $manageApproverEnabled?'yes':'no'; ?> / google_chat_enabled=<?php echo $googleChatEnabled?'yes':'no'; ?> / google_chat_user_name=<?php echo $googleChatUserEnabled?'yes':'no'; ?> / google_chat_dm_space_name=<?php echo $googleChatSpaceEnabled?'yes':'no'; ?></div></details>
<?php $flash = flash_get(); // 직원명부 flash 메시지 ?>
<?php if (is_array($flash) && !empty($flash['message'])): ?>
  <div class="mb-4 p-4 rounded-2xl border <?php echo ($flash['type']==='success')?'bg-emerald-50 border-emerald-200 text-emerald-700':'bg-red-50 border-red-200 text-red-700'; ?>">
    <?php echo h($flash['message']); ?>
  </div>
<?php endif; ?>
<?php if (!empty($employeeLoadError)): ?><div class="mb-4 border border-red-300 bg-red-50 text-red-700 p-3 rounded"><?php echo h($employeeLoadError); ?></div><?php endif; ?>

<div class="bg-white/80 rounded-3xl shadow p-4 mb-4 border border-gray-100"><div class="flex gap-2 flex-wrap">
<form method="post" action="?r=admin/employees_columns_save"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="add_position_column"><button class="px-3 py-2 border rounded-2xl bg-white">직급 컬럼 추가</button></form>
<form method="post" action="?r=admin/employees_columns_save"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="add_hire_date_column"><button class="px-3 py-2 border rounded-2xl bg-white">입사날짜 컬럼 추가</button></form>
<form method="post" action="?r=admin/employees_columns_save"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="add_leave_balance_columns"><button class="px-3 py-2 border rounded-2xl bg-white">휴가잔여 컬럼 추가</button></form>
<form method="post" action="?r=admin/employees_columns_save"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="add_employee_attendance_columns"><button class="px-3 py-2 border rounded-2xl bg-white">직원명부 추가 컬럼 전체 생성/확인</button></form>
<form method="post" action="?r=admin/employees_columns_save"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="add_employee_approval_chat_columns"><button class="px-3 py-2 border rounded-2xl bg-white">전자결재/생년월일/Google Chat 컬럼 생성</button></form>
</div></div>

<div class="bg-white/80 rounded-3xl shadow p-6 mb-6 border border-gray-100"><form method="get" class="flex gap-3 items-center"><input type="hidden" name="r" value="관리"><input type="hidden" name="tab" value="employees"><input class="w-full px-4 py-3 rounded-2xl border" name="q" value="<?php echo h($q); ?>" placeholder="이메일/이름/부서/직급 검색"><button class="px-5 py-3 rounded-2xl border bg-white">검색</button></form></div>

<div class="bg-white/80 rounded-3xl shadow border border-gray-100 overflow-hidden"><div class="overflow-x-auto"><table class="min-w-full text-sm"><thead class="bg-gray-50"><tr><th class="px-4 py-3">사진</th><th class="px-4 py-3">이름</th><th class="px-4 py-3">이메일</th><th class="px-4 py-3">부서</th><th class="px-4 py-3">입사일</th><th class="px-4 py-3">직급</th><th class="px-4 py-3">권한</th><th class="px-4 py-3">상태</th><th class="px-4 py-3">관리</th></tr></thead><tbody class="divide-y">
<?php foreach($rows as $r): $first = mb_substr((string)$r['name'],0,1,'UTF-8'); ?>
<tr>
<td class="px-4 py-3"><?php if(!empty($r['photo_path'])): ?><img src="<?php echo h($r['photo_path']); ?>" class="w-10 h-10 rounded-2xl object-cover"><?php else: ?><div class="w-10 h-10 rounded-2xl bg-emerald-100 flex items-center justify-center font-bold text-emerald-700"><?php echo h($first); ?></div><?php endif; ?></td>
<td class="px-4 py-3 font-bold"><?php echo h($r['name']); ?></td><td class="px-4 py-3"><?php echo h($r['email']); ?></td><td class="px-4 py-3"><?php echo h($r['department']); ?></td><td class="px-4 py-3"><?php echo h($r['hire_date'] ? $r['hire_date'] : '-'); ?></td><td class="px-4 py-3"><?php echo h($r['position']); ?></td><td class="px-4 py-3"><?php echo h($r['role']==='executive'?'임원':'직원'); ?></td><td class="px-4 py-3"><?php echo ((int)$r['is_active']===1)?'활성':'비활성'; ?></td>
<td class="px-4 py-3"><div class="flex gap-2"><button type="button" class="px-3 py-2 border rounded-2xl" data-emp-edit="<?php echo (int)$r['id']; ?>" data-emp-email="<?php echo h($r['email']); ?>" data-emp-name="<?php echo h($r['name']); ?>" data-emp-dept="<?php echo h($r['department']); ?>" data-emp-pos="<?php echo h($r['position']); ?>" data-emp-role="<?php echo h($r['role']); ?>" data-emp-active="<?php echo (int)$r['is_active']; ?>" data-emp-hire-date="<?php echo h($r['hire_date']); ?>" data-emp-lbm="<?php echo h($r['leave_monthly_balance']); ?>" data-emp-lba="<?php echo h($r['leave_annual_balance']); ?>" data-emp-lbh="<?php echo h($r['leave_half_balance']); ?>" data-emp-birth-date="<?php echo h(isset($r['birth_date']) ? $r['birth_date'] : ''); ?>" data-emp-can-site="<?php echo h(isset($r['approval_can_be_site_manager']) ? $r['approval_can_be_site_manager'] : '0'); ?>" data-emp-can-lead="<?php echo h(isset($r['approval_can_be_team_leader']) ? $r['approval_can_be_team_leader'] : '0'); ?>" data-emp-can-gongmu="<?php echo h(isset($r['approval_can_be_gongmu_approver']) ? $r['approval_can_be_gongmu_approver'] : '0'); ?>" data-emp-can-manage="<?php echo h(isset($r['approval_can_be_manage_approver']) ? $r['approval_can_be_manage_approver'] : '0'); ?>" data-emp-chat-enabled="<?php echo h(isset($r['google_chat_enabled']) ? $r['google_chat_enabled'] : '0'); ?>" data-emp-chat-user="<?php echo h(isset($r['google_chat_user_name']) ? $r['google_chat_user_name'] : ''); ?>" data-emp-chat-space="<?php echo h(isset($r['google_chat_dm_space_name']) ? $r['google_chat_dm_space_name'] : ''); ?>">수정</button><button type="button" class="px-3 py-2 border border-red-200 text-red-700 rounded-2xl" data-emp-delete="<?php echo (int)$r['id']; ?>" data-emp-name-for="<?php echo h($r['name']); ?>">삭제</button></div></td>
</tr>
<?php endforeach; ?>
</tbody></table></div></div>

<?php /* 직원 추가 모달 입사일/휴가잔여 */ ?>
<div id="modal-empAdd" class="fixed inset-0 z-50 hidden"><div class="absolute inset-0 bg-black/40" data-modal-close="empAdd"></div><div class="absolute inset-0 flex items-center justify-center p-4"><div class="w-full max-w-6xl bg-white rounded-3xl p-6" style="max-height:90vh;overflow-y:auto;position:relative;"><button type="button" class="absolute right-4 top-4 px-3 py-1 border rounded-xl" data-modal-close="empAdd">✕</button><form method="post" action="?r=admin/employees_save" class="space-y-3"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="save"><div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3"><input class="w-full px-4 py-2 border rounded-2xl" name="email" placeholder="이메일" required><input class="w-full px-4 py-2 border rounded-2xl" name="name" placeholder="이름" required><select class="w-full px-4 py-2 border rounded-2xl" name="department"><option value="">(부서)</option><?php foreach($deptOptions as $d): ?><option value="<?php echo h($d); ?>"><?php echo h($d); ?></option><?php endforeach; ?></select><select class="w-full px-4 py-2 border rounded-2xl" name="position"><option value="">(직급)</option><?php foreach($positionOptions as $p): ?><option value="<?php echo h($p); ?>"><?php echo h($p); ?></option><?php endforeach; ?></select><select class="w-full px-4 py-2 border rounded-2xl" name="role"><option value="employee">직원</option><option value="executive">임원</option></select><select class="w-full px-4 py-2 border rounded-2xl" name="is_active"><option value="1">활성</option><option value="0">비활성</option></select></div><div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3"><input type="date" class="w-full px-4 py-2 border rounded-2xl mt-1" name="hire_date" id="empAddHireDate" placeholder="입사날짜"><input type="date" class="w-full px-4 py-2 border rounded-2xl mt-1" name="birth_date" id="empAddBirthDate" placeholder="생년월일"><input type="number" step="0.01" class="px-3 py-2 border rounded-2xl" name="leave_monthly_balance" placeholder="남은 월차"><input type="number" step="0.01" class="px-3 py-2 border rounded-2xl" name="leave_annual_balance" placeholder="남은 연차"><input type="number" step="0.01" class="px-3 py-2 border rounded-2xl" name="leave_half_balance" placeholder="남은 반차"></div><div class="grid grid-cols-1 md:grid-cols-2 gap-3"><div class="border rounded-2xl p-3 space-y-1"><div class="font-bold">전자결재 역할</div><label class="block"><input type="checkbox" name="approval_can_be_site_manager" value="1"> 소장 결재자</label><label class="block"><input type="checkbox" name="approval_can_be_team_leader" value="1"> 팀장 결재자</label><label class="block"><input type="checkbox" name="approval_can_be_gongmu_approver" value="1"> 공무 결재자</label><label class="block"><input type="checkbox" name="approval_can_be_manage_approver" value="1"> 관리 결재자</label></div><div class="border rounded-2xl p-3 space-y-2"><div class="font-bold">Google Chat 개인 DM</div><label class="block"><input type="checkbox" name="google_chat_enabled" value="1"> Google Chat 개인 DM 사용</label><input class="w-full px-4 py-2 border rounded-2xl" name="google_chat_user_name" placeholder="Google Chat User Name"><input class="w-full px-4 py-2 border rounded-2xl" name="google_chat_dm_space_name" placeholder="Google Chat DM Space ID"></div></div><div class="sticky bottom-0 bg-white pt-3 flex gap-2 justify-end"><button type="button" class="px-4 py-3 rounded-2xl border" data-modal-close="empAdd">저장하지 않고 닫기</button><button class="px-6 py-3 rounded-2xl bg-emerald-500 text-white font-bold">저장</button></div></form></div></div></div>

<?php /* 직원 수정 모달 입사일/휴가잔여 */ ?>
<div id="modal-empEdit" class="fixed inset-0 z-50 hidden"><div class="absolute inset-0 bg-black/40" data-modal-close="empEdit"></div><div class="absolute inset-0 flex items-center justify-center p-4"><div class="w-full max-w-6xl bg-white rounded-3xl p-6" style="max-height:90vh;overflow-y:auto;position:relative;"><button type="button" class="absolute right-4 top-4 px-3 py-1 border rounded-xl" data-modal-close="empEdit">✕</button><form method="post" action="?r=admin/employees_save" class="space-y-3"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="empEditId"><div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3"><input class="w-full px-4 py-2 border rounded-2xl" name="email" id="empEditEmail" required><input class="w-full px-4 py-2 border rounded-2xl" name="name" id="empEditName" required><select class="w-full px-4 py-2 border rounded-2xl" name="department" id="empEditDept"><option value="">(부서)</option><?php foreach($deptOptions as $d): ?><option value="<?php echo h($d); ?>"><?php echo h($d); ?></option><?php endforeach; ?></select><select class="w-full px-4 py-2 border rounded-2xl" name="position" id="empEditPos"><option value="">(직급)</option><?php foreach($positionOptions as $p): ?><option value="<?php echo h($p); ?>"><?php echo h($p); ?></option><?php endforeach; ?></select><select class="w-full px-4 py-2 border rounded-2xl" name="role" id="empEditRole"><option value="employee">직원</option><option value="executive">임원</option></select><select class="w-full px-4 py-2 border rounded-2xl" name="is_active" id="empEditActive"><option value="1">활성</option><option value="0">비활성</option></select><div class="rounded-2xl border border-emerald-100 bg-emerald-50 p-3"><div class="font-bold text-emerald-800">근무 정보</div><label class="mt-2 block font-semibold text-gray-800" for="empEditHireDate">입사날짜</label><input type="date" class="w-full px-4 py-2 border rounded-2xl mt-1" name="hire_date" id="empEditHireDate"><div class="text-xs text-gray-600 mt-1">회사 입사일 또는 기준 입사일입니다.</div></div><div class="grid grid-cols-3 gap-2"><input type="number" step="0.01" class="px-3 py-2 border rounded-2xl" name="leave_monthly_balance" id="empEditLbm" placeholder="남은 월차"><input type="number" step="0.01" class="px-3 py-2 border rounded-2xl" name="leave_annual_balance" id="empEditLba" placeholder="남은 연차"><input type="number" step="0.01" class="px-3 py-2 border rounded-2xl" name="leave_half_balance" id="empEditLbh" placeholder="남은 반차"></div><div class="rounded-2xl border border-sky-100 bg-sky-50 p-3"><div class="font-bold text-sky-800">개인 정보</div><label class="mt-2 block font-semibold text-gray-800" for="empEditBirthDate">생년월일</label><input type="date" class="w-full px-4 py-2 border rounded-2xl mt-1" name="birth_date" id="empEditBirthDate"><div class="text-xs text-gray-600 mt-1">휴가계 작성 시 자동 표시됩니다.</div></div><div class="border rounded-2xl p-3 space-y-1"><div class="font-bold">전자결재 역할</div><label class="block"><input type="checkbox" name="approval_can_be_site_manager" id="empEditCanSite" value="1"> 소장 결재자</label><label class="block"><input type="checkbox" name="approval_can_be_team_leader" id="empEditCanLead" value="1"> 팀장 결재자</label><label class="block"><input type="checkbox" name="approval_can_be_gongmu_approver" id="empEditCanGongmu" value="1"> 공무 결재자</label><label class="block"><input type="checkbox" name="approval_can_be_manage_approver" id="empEditCanManage" value="1"> 관리 결재자</label></div><div class="border rounded-2xl p-3 space-y-2"><div class="font-bold">Google Chat 개인 DM</div><label class="block"><input type="checkbox" name="google_chat_enabled" id="empEditChatEnabled" value="1"> Google Chat 개인 DM 사용</label><input class="w-full px-4 py-2 border rounded-2xl" name="google_chat_user_name" id="empEditChatUser" placeholder="Google Chat User Name"><input class="w-full px-4 py-2 border rounded-2xl" name="google_chat_dm_space_name" id="empEditChatSpace" placeholder="Google Chat DM Space ID"><div class="text-xs text-gray-500">Google Chat 개인 DM을 사용하려면 Google Chat App 설정 후 직원별 DM Space ID를 등록해야 합니다.</div></div></div><div class="sticky bottom-0 bg-white pt-3 flex gap-2 justify-end"><button type="button" class="px-4 py-3 rounded-2xl border" data-modal-close="empEdit">저장하지 않고 닫기</button><button class="px-6 py-3 rounded-2xl bg-emerald-500 text-white font-bold">수정 저장</button></div></form></div></div></div>
<div id="modal-empDelete" class="fixed inset-0 z-50 hidden"><div class="absolute inset-0 bg-black/40" data-modal-close="empDelete"></div><div class="absolute inset-0 flex items-center justify-center p-4"><div class="w-full max-w-md bg-white rounded-3xl p-6"><form method="post" action="?r=admin/employees_save" class="space-y-3"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="empDeleteId"><div id="empDeleteName"></div><button class="w-full py-3 rounded-2xl bg-red-600 text-white">삭제</button></form></div></div></div>
<script>
(function(){function o(n){var e=document.getElementById('modal-'+n);if(e)e.classList.remove('hidden');}function c(n){var e=document.getElementById('modal-'+n);if(e)e.classList.add('hidden');}
document.addEventListener('click',function(e){var t=e.target,op=t.closest?t.closest('[data-modal-open]'):null;if(op){o(op.getAttribute('data-modal-open'));e.preventDefault();return;}var cl=t.closest?t.closest('[data-modal-close]'):null;if(cl){c(cl.getAttribute('data-modal-close'));e.preventDefault();return;}var be=t.closest?t.closest('[data-emp-edit]'):null;if(be){document.getElementById('empEditId').value=be.getAttribute('data-emp-edit')||'';document.getElementById('empEditEmail').value=be.getAttribute('data-emp-email')||'';document.getElementById('empEditName').value=be.getAttribute('data-emp-name')||'';document.getElementById('empEditDept').value=be.getAttribute('data-emp-dept')||'';document.getElementById('empEditPos').value=be.getAttribute('data-emp-pos')||'';document.getElementById('empEditRole').value=be.getAttribute('data-emp-role')||'employee';document.getElementById('empEditActive').value=be.getAttribute('data-emp-active')||'1';document.getElementById('empEditHireDate').value=be.getAttribute('data-emp-hire-date')||'';document.getElementById('empEditLbm').value=be.getAttribute('data-emp-lbm')||'';document.getElementById('empEditLba').value=be.getAttribute('data-emp-lba')||'';document.getElementById('empEditLbh').value=be.getAttribute('data-emp-lbh')||'';document.getElementById('empEditBirthDate').value=be.getAttribute('data-emp-birth-date')||'';document.getElementById('empEditCanSite').checked=(be.getAttribute('data-emp-can-site')==='1');document.getElementById('empEditCanLead').checked=(be.getAttribute('data-emp-can-lead')==='1');document.getElementById('empEditCanGongmu').checked=(be.getAttribute('data-emp-can-gongmu')==='1');document.getElementById('empEditCanManage').checked=(be.getAttribute('data-emp-can-manage')==='1');document.getElementById('empEditChatEnabled').checked=(be.getAttribute('data-emp-chat-enabled')==='1');document.getElementById('empEditChatUser').value=be.getAttribute('data-emp-chat-user')||'';document.getElementById('empEditChatSpace').value=be.getAttribute('data-emp-chat-space')||'';o('empEdit');e.preventDefault();return;}var bd=t.closest?t.closest('[data-emp-delete]'):null;if(bd){document.getElementById('empDeleteId').value=bd.getAttribute('data-emp-delete')||'';document.getElementById('empDeleteName').innerHTML='대상: '+(bd.getAttribute('data-emp-name-for')||'');o('empDelete');e.preventDefault();return;}});
})();
</script>