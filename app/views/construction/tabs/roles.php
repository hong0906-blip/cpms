<?php
/**
 * C:\www\cpms\app\views\construction\tabs\roles.php
 * - 공사: 담당지정 탭
 *
 * 사용 변수:
 * - $pdo (PDO)
 * - $pid (int)
 * - $projectRow (array)
 */

use App\Core\Auth;

$role = Auth::userRole();
$dept = Auth::userDepartment();
$canSave = Auth::canManageConstruction();

// 직원 목록(부서별)
function load_employees_by_dept($pdo, $deptName) {
    $list = array();
    try {
        $st = $pdo->prepare("SELECT id, name, department, position FROM employees WHERE department = :d AND is_active = 1 ORDER BY position, name");
        $st->bindValue(':d', (string)$deptName);
        $st->execute();
        $list = $st->fetchAll();
    } catch (Exception $e) {
        $list = array();
    }
    return $list;
}

$empsSafety  = load_employees_by_dept($pdo, '안전');
$empsQuality = load_employees_by_dept($pdo, '품질');
$empsSite    = load_employees_by_dept($pdo, '공사');
$allEmployees = array();
try {
    $stAllEmployees = $pdo->query("SELECT id, name, department, position FROM employees WHERE is_active = 1 ORDER BY department, position, name");
    $allEmployees = $stAllEmployees ? $stAllEmployees->fetchAll() : array();
} catch (Exception $e) {
    $allEmployees = array();
}

// 현재 값
$row = null;
try {
    $st = $pdo->prepare("SELECT * FROM cpms_construction_roles WHERE project_id = :pid LIMIT 1");
    $st->bindValue(':pid', (int)$pid, \PDO::PARAM_INT);
    $st->execute();
    $row = $st->fetch();
} catch (Exception $e) {
    $row = null;
}

$curSafety  = $row ? (int)$row['safety_employee_id'] : 0;
$curQuality = $row ? (int)$row['quality_employee_id'] : 0;
$curSite    = $row ? (int)$row['site_employee_id'] : 0;
$subManagerIds = array();
try {
    $stSubManagers = $pdo->prepare("SELECT employee_id FROM cpms_project_members WHERE project_id = :pid AND LOWER(TRIM(role)) = 'sub' ORDER BY employee_id");
    $stSubManagers->bindValue(':pid', (int)$pid, \PDO::PARAM_INT);
    $stSubManagers->execute();
    $subRows = $stSubManagers->fetchAll();
    foreach ($subRows as $subRow) {
        $subManagerIds[] = (int)$subRow['employee_id'];
    }
} catch (Exception $e) {
    $subManagerIds = array();
}
?>

<div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h3 class="text-xl font-extrabold text-gray-900">담당 지정</h3>
        </div>
        <div class="p-3 bg-gradient-to-br from-yellow-500 to-orange-500 rounded-2xl shadow-lg shadow-yellow-500/30">
            <i data-lucide="users" class="w-5 h-5 text-white"></i>
        </div>
    </div>

    <form method="post" action="<?php echo h(base_url()); ?>/?r=construction/roles_save" class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
        <input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>">

        <div class="md:col-span-1">
            <label class="text-sm font-bold text-gray-700">현장 담당(공사) 메인</label>
            <select name="site_employee_id" class="w-full mt-1 px-4 py-3 rounded-2xl border border-gray-200" <?php echo $canSave?'':'disabled'; ?>>
                <option value="0">미지정</option>
                <?php foreach ($empsSite as $e): ?>
                    <option value="<?php echo (int)$e['id']; ?>" <?php echo ((int)$e['id']===$curSite)?'selected':''; ?>>
                        <?php echo h($e['name']); ?><?php echo ($e['position']?' · '.h($e['position']):''); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <div class="mt-4 space-y-3">
                <?php for ($subSlot = 0; $subSlot < 4; $subSlot++): ?>
                    <?php $selectedSubManagerId = isset($subManagerIds[$subSlot]) ? (int)$subManagerIds[$subSlot] : 0; ?>
                    <div>
                        <label class="text-sm font-bold text-gray-700">현장 담당(공사) 서브 <?php echo (int)($subSlot + 1); ?></label>
                        <select name="sub_manager_ids[]" data-sub-manager-slot class="w-full mt-1 px-4 py-3 rounded-2xl border border-gray-200" <?php echo $canSave?'':'disabled'; ?>>
                            <option value="0">미지정</option>
                            <?php foreach ($allEmployees as $e): ?>
                                <option value="<?php echo (int)$e['id']; ?>" <?php echo ((int)$e['id'] === $selectedSubManagerId)?'selected':''; ?>>
                                    <?php echo h($e['department'].' / '.$e['position'].' / '.$e['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endfor; ?>
                <div class="text-xs text-gray-500">공무 섹션의 부담당자(서브)와 같은 명단으로 연동됩니다.</div>
            </div>
        </div>

        <div>
            <label class="text-sm font-bold text-gray-700">안전 담당(안전) 메인</label>
            <select name="safety_employee_id" class="w-full mt-1 px-4 py-3 rounded-2xl border border-gray-200" <?php echo $canSave?'':'disabled'; ?>>
                <option value="0">미지정</option>
                <?php foreach ($empsSafety as $e): ?>
                    <option value="<?php echo (int)$e['id']; ?>" <?php echo ((int)$e['id']===$curSafety)?'selected':''; ?>>
                        <?php echo h($e['name']); ?><?php echo ($e['position']?' · '.h($e['position']):''); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="text-sm font-bold text-gray-700">품질 담당(품질) 메인</label>
            <select name="quality_employee_id" class="w-full mt-1 px-4 py-3 rounded-2xl border border-gray-200" <?php echo $canSave?'':'disabled'; ?>>
                <option value="0">미지정</option>
                <?php foreach ($empsQuality as $e): ?>
                    <option value="<?php echo (int)$e['id']; ?>" <?php echo ((int)$e['id']===$curQuality)?'selected':''; ?>>
                        <?php echo h($e['name']); ?><?php echo ($e['position']?' · '.h($e['position']):''); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php if ($canSave): ?>
            <div class="md:col-span-3 flex justify-end">
                <button type="submit" class="px-5 py-3 rounded-2xl bg-gray-900 text-white font-extrabold">저장</button>
            </div>
        <?php else: ?>
            <div class="md:col-span-3 text-sm text-gray-500">※ 저장 권한이 없습니다. (공사/임원만)</div>
        <?php endif; ?>
    </form>

</div>
<script>
(function () {
    var slots = document.querySelectorAll('[data-sub-manager-slot]');
    var i;
    for (i = 0; i < slots.length; i++) {
        slots[i].addEventListener('change', function () {
            if (!this.value || this.value === '0') return;
            var j;
            for (j = 0; j < slots.length; j++) {
                if (slots[j] !== this && slots[j].value === this.value) {
                    this.value = '0';
                    window.alert('같은 서브 담당자를 중복 지정할 수 없습니다.');
                    return;
                }
            }
        });
    }
}());
</script>
