<?php
/**
 * C:\www\cpms\app\views\construction\tabs\roles.php
 * - 공사: 담당지정 탭
 * - 공사 메인 1 + 서브 4 / 안전 메인 1 + 서브 2 / 품질 메인 1 + 서브 2
 *
 * 사용 변수:
 * - $pdo (PDO)
 * - $pid (int)
 * - $projectRow (array)
 *
 * PHP 5.6 호환
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

// 현재 메인 값
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

// 공사 서브 담당자
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

// 안전/품질 서브 담당자: 기존 프로젝트 멤버 테이블의 별도 role을 사용한다.
$safetySubIds = array();
$qualitySubIds = array();
try {
    $stSpecialSubs = $pdo->prepare("SELECT employee_id, LOWER(TRIM(role)) AS member_role
                                    FROM cpms_project_members
                                    WHERE project_id = :pid
                                      AND LOWER(TRIM(role)) IN ('safety_sub', 'qual_sub')
                                    ORDER BY employee_id");
    $stSpecialSubs->bindValue(':pid', (int)$pid, \PDO::PARAM_INT);
    $stSpecialSubs->execute();
    $specialSubRows = $stSpecialSubs->fetchAll();
    foreach ($specialSubRows as $specialSubRow) {
        $memberRole = isset($specialSubRow['member_role']) ? (string)$specialSubRow['member_role'] : '';
        $employeeId = isset($specialSubRow['employee_id']) ? (int)$specialSubRow['employee_id'] : 0;
        if ($employeeId <= 0) continue;
        if ($memberRole === 'safety_sub') $safetySubIds[] = $employeeId;
        if ($memberRole === 'qual_sub') $qualitySubIds[] = $employeeId;
    }
} catch (Exception $e) {
    $safetySubIds = array();
    $qualitySubIds = array();
}
?>

<div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h3 class="text-xl font-extrabold text-gray-900">담당 지정</h3>
            <div class="mt-1 text-xs text-gray-500">공사 최대 5명 · 안전 최대 3명 · 품질 최대 3명</div>
        </div>
        <div class="p-3 bg-gradient-to-br from-yellow-500 to-orange-500 rounded-2xl shadow-lg shadow-yellow-500/30">
            <i data-lucide="users" class="w-5 h-5 text-white"></i>
        </div>
    </div>

    <form method="post" action="<?php echo h(base_url()); ?>/?r=construction/roles_save" class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
        <input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>">

        <!-- 공사 담당 -->
        <div class="md:col-span-1 rounded-2xl border border-gray-100 bg-gray-50/50 p-4">
            <div class="text-sm font-extrabold text-gray-900 mb-3">공사 담당 · 최대 5명</div>
            <label class="text-sm font-bold text-gray-700">메인</label>
            <select name="site_employee_id" class="w-full mt-1 px-4 py-3 rounded-2xl border border-gray-200 bg-white" <?php echo $canSave?'':'disabled'; ?>>
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
                        <label class="text-sm font-bold text-gray-700">서브 <?php echo (int)($subSlot + 1); ?></label>
                        <select name="sub_manager_ids[]" data-sub-manager-slot class="w-full mt-1 px-4 py-3 rounded-2xl border border-gray-200 bg-white" <?php echo $canSave?'':'disabled'; ?>>
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

        <!-- 안전 담당 -->
        <div class="rounded-2xl border border-gray-100 bg-gray-50/50 p-4">
            <div class="text-sm font-extrabold text-gray-900 mb-3">안전 담당 · 최대 3명</div>
            <label class="text-sm font-bold text-gray-700">메인</label>
            <select name="safety_employee_id" data-safety-main class="w-full mt-1 px-4 py-3 rounded-2xl border border-gray-200 bg-white" <?php echo $canSave?'':'disabled'; ?>>
                <option value="0">미지정</option>
                <?php foreach ($empsSafety as $e): ?>
                    <option value="<?php echo (int)$e['id']; ?>" <?php echo ((int)$e['id']===$curSafety)?'selected':''; ?>>
                        <?php echo h($e['name']); ?><?php echo ($e['position']?' · '.h($e['position']):''); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <div class="mt-4 space-y-3">
                <?php for ($safetySubSlot = 0; $safetySubSlot < 2; $safetySubSlot++): ?>
                    <?php $selectedSafetySubId = isset($safetySubIds[$safetySubSlot]) ? (int)$safetySubIds[$safetySubSlot] : 0; ?>
                    <div>
                        <label class="text-sm font-bold text-gray-700">서브 <?php echo (int)($safetySubSlot + 1); ?></label>
                        <select name="safety_sub_employee_ids[]" data-safety-sub class="w-full mt-1 px-4 py-3 rounded-2xl border border-gray-200 bg-white" <?php echo $canSave?'':'disabled'; ?>>
                            <option value="0">미지정</option>
                            <?php foreach ($empsSafety as $e): ?>
                                <option value="<?php echo (int)$e['id']; ?>" <?php echo ((int)$e['id'] === $selectedSafetySubId)?'selected':''; ?>>
                                    <?php echo h($e['name']); ?><?php echo ($e['position']?' · '.h($e['position']):''); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endfor; ?>
            </div>
            <div class="mt-3 text-xs text-gray-500">서브 담당자도 해당 현장의 안전관리비·안전사고 담당 권한과 이슈 알림 대상에 포함됩니다.</div>
        </div>

        <!-- 품질 담당 -->
        <div class="rounded-2xl border border-gray-100 bg-gray-50/50 p-4">
            <div class="text-sm font-extrabold text-gray-900 mb-3">품질 담당 · 최대 3명</div>
            <label class="text-sm font-bold text-gray-700">메인</label>
            <select name="quality_employee_id" data-quality-main class="w-full mt-1 px-4 py-3 rounded-2xl border border-gray-200 bg-white" <?php echo $canSave?'':'disabled'; ?>>
                <option value="0">미지정</option>
                <?php foreach ($empsQuality as $e): ?>
                    <option value="<?php echo (int)$e['id']; ?>" <?php echo ((int)$e['id']===$curQuality)?'selected':''; ?>>
                        <?php echo h($e['name']); ?><?php echo ($e['position']?' · '.h($e['position']):''); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <div class="mt-4 space-y-3">
                <?php for ($qualitySubSlot = 0; $qualitySubSlot < 2; $qualitySubSlot++): ?>
                    <?php $selectedQualitySubId = isset($qualitySubIds[$qualitySubSlot]) ? (int)$qualitySubIds[$qualitySubSlot] : 0; ?>
                    <div>
                        <label class="text-sm font-bold text-gray-700">서브 <?php echo (int)($qualitySubSlot + 1); ?></label>
                        <select name="quality_sub_employee_ids[]" data-quality-sub class="w-full mt-1 px-4 py-3 rounded-2xl border border-gray-200 bg-white" <?php echo $canSave?'':'disabled'; ?>>
                            <option value="0">미지정</option>
                            <?php foreach ($empsQuality as $e): ?>
                                <option value="<?php echo (int)$e['id']; ?>" <?php echo ((int)$e['id'] === $selectedQualitySubId)?'selected':''; ?>>
                                    <?php echo h($e['name']); ?><?php echo ($e['position']?' · '.h($e['position']):''); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endfor; ?>
            </div>
            <div class="mt-3 text-xs text-gray-500">품질 서브 담당자도 현장 담당자로 저장되며 이슈 알림 대상에 포함됩니다.</div>
        </div>

        <?php if ($canSave): ?>
            <div class="md:col-span-3 flex justify-end">
                <button type="submit" class="px-5 py-3 rounded-2xl bg-gray-900 text-white font-extrabold">저장</button>
            </div>
        <?php else: ?>
            <div class="md:col-span-3 text-sm text-gray-500">※ 저장 권한이 없습니다. (공사/공무/임원만)</div>
        <?php endif; ?>
    </form>

</div>
<script>
(function () {
    // 기존 공사 서브 담당자 중복 방지
    var constructionSlots = document.querySelectorAll('[data-sub-manager-slot]');
    var i;
    for (i = 0; i < constructionSlots.length; i++) {
        constructionSlots[i].addEventListener('change', function () {
            if (!this.value || this.value === '0') return;
            var j;
            for (j = 0; j < constructionSlots.length; j++) {
                if (constructionSlots[j] !== this && constructionSlots[j].value === this.value) {
                    this.value = '0';
                    window.alert('같은 공사 서브 담당자를 중복 지정할 수 없습니다.');
                    return;
                }
            }
        });
    }

    // 안전/품질은 메인과 서브 간 중복까지 차단한다.
    function bindSpecialGroup(mainSelector, subSelector, label) {
        var main = document.querySelector(mainSelector);
        var subs = document.querySelectorAll(subSelector);
        if (!main || !subs.length) return;

        function validateSub(changed) {
            if (!changed.value || changed.value === '0') return true;
            if (main.value && main.value !== '0' && changed.value === main.value) {
                changed.value = '0';
                window.alert(label + ' 메인 담당자와 서브 담당자는 같은 사람으로 지정할 수 없습니다.');
                return false;
            }
            var j;
            for (j = 0; j < subs.length; j++) {
                if (subs[j] !== changed && subs[j].value === changed.value) {
                    changed.value = '0';
                    window.alert('같은 ' + label + ' 서브 담당자를 중복 지정할 수 없습니다.');
                    return false;
                }
            }
            return true;
        }

        for (var k = 0; k < subs.length; k++) {
            subs[k].addEventListener('change', function () { validateSub(this); });
        }

        main.addEventListener('change', function () {
            if (!this.value || this.value === '0') return;
            var cleared = false;
            for (var j = 0; j < subs.length; j++) {
                if (subs[j].value === this.value) {
                    subs[j].value = '0';
                    cleared = true;
                }
            }
            if (cleared) window.alert(label + ' 메인과 같은 사람으로 지정된 서브 담당자를 해제했습니다.');
        });
    }

    bindSpecialGroup('[data-safety-main]', '[data-safety-sub]', '안전');
    bindSpecialGroup('[data-quality-main]', '[data-quality-sub]', '품질');
}());
</script>
