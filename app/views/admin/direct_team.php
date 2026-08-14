<?php
/**
 * 관리 > 직영팀 명부
 * - PHP 5.6 호환
 */

use App\Core\Auth;
use App\Core\Db;

if (!Auth::canManageEmployees()) {
    echo '<div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 font-bold">접근 권한이 없습니다. (임원/관리 전용)</div>';
    return;
}

$pdo = Db::pdo();
$dbOk = ($pdo !== null);
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$directTeamView = isset($_GET['direct_team_view']) ? trim((string)$_GET['direct_team_view']) : 'active';
if (!in_array($directTeamView, array('active', 'retired'), true)) $directTeamView = 'active';
$isRetiredView = ($directTeamView === 'retired');
$rows = array();
$loadError = '';
$tableOk = false;
$activeCount = 0;

if (!function_exists('cpms_direct_team_table_exists')) {
function cpms_direct_team_table_exists($pdo, $table) {
    if (!$pdo) return false;
    try {
        $st = $pdo->prepare("SHOW TABLES LIKE :table_name");
        $st->bindValue(':table_name', (string)$table);
        $st->execute();
        return (bool)$st->fetch(PDO::FETCH_NUM);
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_direct_team_column_exists')) {
function cpms_direct_team_column_exists($pdo, $table, $column) {
    try {
        $dbName = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
        if ($dbName === '') return false;
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=:db AND TABLE_NAME=:table_name AND COLUMN_NAME=:column_name");
        $st->execute(array(':db'=>$dbName, ':table_name'=>$table, ':column_name'=>$column));
        return ((int)$st->fetchColumn() > 0);
    } catch (Exception $e) { return false; }
}}

if ($dbOk) {
    $tableOk = cpms_direct_team_table_exists($pdo, 'direct_team_members');
    if ($tableOk) {
        try {
            $activeCount = (int)$pdo->query("SELECT COUNT(*) FROM direct_team_members WHERE is_active = 1")->fetchColumn();
            $sql = "SELECT * FROM direct_team_members WHERE is_active = :is_active";
            $params = array(':is_active' => $isRetiredView ? 0 : 1);
            if ($q !== '') {
                $searchColumns = array('name');
                foreach (array('phone', 'bank_name', 'account_holder') as $optionalSearchColumn) {
                    if (cpms_direct_team_column_exists($pdo, 'direct_team_members', $optionalSearchColumn)) $searchColumns[] = $optionalSearchColumn;
                }
                if (cpms_direct_team_column_exists($pdo, 'direct_team_members', 'vehicle_number')) $searchColumns[] = 'vehicle_number';
                $searchParts = array();
                foreach ($searchColumns as $searchColumn) $searchParts[] = $searchColumn . ' LIKE :q';
                $sql .= " AND (" . implode(' OR ', $searchParts) . ")";
                $params[':q'] = '%' . $q . '%';
            }
            if ($isRetiredView) {
                if (cpms_direct_team_column_exists($pdo, 'direct_team_members', 'resign_date')) $sql .= " ORDER BY CASE WHEN resign_date IS NULL OR resign_date = '' THEN 1 ELSE 0 END, resign_date DESC, name ASC, id DESC LIMIT 500";
                else $sql .= " ORDER BY name ASC, id DESC LIMIT 500";
            } else {
                if (cpms_direct_team_column_exists($pdo, 'direct_team_members', 'hire_date')) $sql .= " ORDER BY CASE WHEN hire_date IS NULL OR hire_date = '' THEN 1 ELSE 0 END, hire_date ASC, name ASC, id DESC LIMIT 500";
                else $sql .= " ORDER BY name ASC, id DESC LIMIT 500";
            }
            $st = $pdo->prepare($sql);
            foreach ($params as $key => $value) $st->bindValue($key, $value);
            $st->execute();
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows)) $rows = array();
        } catch (Exception $e) {
            $rows = array();
            $loadError = '직영팀 명부 조회 중 오류가 발생했습니다: ' . $e->getMessage();
        }
    }
}

if (!function_exists('cpms_direct_team_sensitive_display')) {
function cpms_direct_team_sensitive_display($value, $isMoney) {
    $value = trim((string)$value);
    if ($value === '') return '-';
    if ($isMoney) return number_format((int)preg_replace('/[^0-9]/', '', $value)) . '원';
    return $value;
}}

$flash = flash_get();
?>

<div class="flex flex-wrap items-center justify-between gap-3 mb-6">
  <div>
    <h2 class="text-2xl font-extrabold text-gray-900">직영팀 명부</h2>
  </div>
  <button type="button" class="px-5 py-3 rounded-2xl bg-gradient-to-r from-emerald-500 to-teal-500 text-white font-extrabold" data-direct-modal-open="directTeamAdd">직영팀 추가</button>
</div>

<?php if (is_array($flash) && !empty($flash['message'])): ?>
  <div class="mb-4 p-4 rounded-2xl border <?php echo $flash['type'] === 'success' ? 'bg-emerald-50 border-emerald-200 text-emerald-700' : 'bg-red-50 border-red-200 text-red-700'; ?>">
    <?php echo h($flash['message']); ?>
  </div>
<?php endif; ?>
<?php if (!$dbOk): ?><div class="mb-4 border border-red-300 bg-red-50 text-red-700 p-3 rounded">DB 연결 실패</div><?php endif; ?>
<?php if ($loadError !== ''): ?><div class="mb-4 border border-red-300 bg-red-50 text-red-700 p-3 rounded"><?php echo h($loadError); ?></div><?php endif; ?>
<?php if ($dbOk && !$tableOk): ?><div class="mb-4 border border-blue-200 bg-blue-50 text-blue-800 p-3 rounded-2xl">직영팀을 처음 저장할 때 명부 테이블이 자동으로 생성됩니다.</div><?php endif; ?>

<div class="flex flex-wrap items-center justify-between gap-3 mb-4">
  <div class="flex flex-wrap gap-2">
    <a class="px-4 py-2 rounded-2xl border font-bold <?php echo !$isRetiredView ? 'bg-emerald-500 text-white border-emerald-500' : 'bg-white text-gray-700 border-gray-200'; ?>" href="?r=관리&amp;tab=direct_team&amp;direct_team_view=active<?php echo $q !== '' ? '&amp;q=' . rawurlencode($q) : ''; ?>">재직자</a>
    <a class="px-4 py-2 rounded-2xl border font-bold <?php echo $isRetiredView ? 'bg-gray-800 text-white border-gray-800' : 'bg-white text-gray-700 border-gray-200'; ?>" href="?r=관리&amp;tab=direct_team&amp;direct_team_view=retired<?php echo $q !== '' ? '&amp;q=' . rawurlencode($q) : ''; ?>">퇴직자</a>
  </div>
  <div class="flex flex-wrap items-center gap-2">
    <button type="button" class="px-4 py-2 rounded-2xl border border-sky-200 bg-sky-50 text-sky-800 font-extrabold" data-sensitive-toggle aria-pressed="false">민감정보 보이기</button>
    <?php if (!$isRetiredView): ?>
      <div class="inline-flex items-center gap-2 rounded-2xl border border-emerald-100 bg-emerald-50 px-3 py-2 shadow-sm"><span class="text-xs font-bold text-emerald-700">총 직영팀</span><span class="text-base font-extrabold text-emerald-900"><?php echo number_format($activeCount); ?>명</span></div>
    <?php endif; ?>
  </div>
</div>

<div class="bg-white/80 rounded-3xl shadow p-6 mb-6 border border-gray-100">
  <form method="get" class="flex gap-3 items-center">
    <input type="hidden" name="r" value="관리"><input type="hidden" name="tab" value="direct_team"><input type="hidden" name="direct_team_view" value="<?php echo h($directTeamView); ?>">
    <input class="w-full px-4 py-3 rounded-2xl border" name="q" value="<?php echo h($q); ?>" placeholder="이름/연락처/차량번호/은행/예금주 검색">
    <button class="px-5 py-3 rounded-2xl border bg-white">검색</button>
  </form>
</div>

<div class="bg-white/80 rounded-3xl shadow border border-gray-100 overflow-hidden">
  <div class="overflow-x-auto">
    <table class="min-w-full text-sm">
      <thead class="bg-gray-50"><tr><th class="px-4 py-3">사진</th><th class="px-4 py-3">이름</th><th class="px-4 py-3">연락처</th><th class="px-4 py-3">입사일</th><th class="px-4 py-3">차량번호</th><th class="px-4 py-3">주민번호</th><th class="px-4 py-3">계좌번호</th><th class="px-4 py-3">은행</th><th class="px-4 py-3">예금주</th><th class="px-4 py-3">월급</th><th class="px-4 py-3">관리</th></tr></thead>
      <tbody class="divide-y">
      <?php if (count($rows) > 0): ?>
        <?php foreach ($rows as $row): ?>
          <?php
            $id = isset($row['id']) ? (int)$row['id'] : 0;
            $name = isset($row['name']) ? (string)$row['name'] : '';
            $first = function_exists('mb_substr') ? mb_substr($name, 0, 1, 'UTF-8') : substr($name, 0, 1);
            $photoPath = isset($row['photo_path']) ? trim((string)$row['photo_path']) : '';
            $residentDisplay = cpms_direct_team_sensitive_display(isset($row['resident_no']) ? $row['resident_no'] : '', false);
            $accountDisplay = cpms_direct_team_sensitive_display(isset($row['bank_account']) ? $row['bank_account'] : '', false);
            $salaryDisplay = cpms_direct_team_sensitive_display(isset($row['monthly_salary']) ? $row['monthly_salary'] : '', true);
          ?>
          <tr>
            <td class="px-4 py-3"><div class="w-10 h-10 rounded-2xl bg-emerald-100 flex items-center justify-center font-bold text-emerald-700 relative overflow-hidden"><?php if ($photoPath !== ''): ?><img src="<?php echo h($photoPath); ?>" alt="<?php echo h($name); ?> 사진" class="w-10 h-10 rounded-2xl object-cover absolute inset-0" onerror="this.style.display='none';"><?php endif; ?><span><?php echo h($first); ?></span></div></td>
            <td class="px-4 py-3 font-bold"><?php echo h($name); ?></td>
            <td class="px-4 py-3"><?php echo h(isset($row['phone']) && trim((string)$row['phone']) !== '' ? $row['phone'] : '-'); ?></td>
            <td class="px-4 py-3"><?php echo h(isset($row['hire_date']) && trim((string)$row['hire_date']) !== '' ? $row['hire_date'] : '-'); ?></td>
            <td class="px-4 py-3"><?php echo h(isset($row['vehicle_number']) && trim((string)$row['vehicle_number']) !== '' ? $row['vehicle_number'] : '-'); ?></td>
            <td class="px-4 py-3"><span data-sensitive-value="<?php echo h($residentDisplay); ?>">***</span></td>
            <td class="px-4 py-3"><span data-sensitive-value="<?php echo h($accountDisplay); ?>">***</span></td>
            <td class="px-4 py-3"><?php echo h(isset($row['bank_name']) && trim((string)$row['bank_name']) !== '' ? $row['bank_name'] : '-'); ?></td>
            <td class="px-4 py-3"><?php echo h(isset($row['account_holder']) && trim((string)$row['account_holder']) !== '' ? $row['account_holder'] : '-'); ?></td>
            <td class="px-4 py-3 text-right font-bold"><span data-sensitive-value="<?php echo h($salaryDisplay); ?>">***</span></td>
            <td class="px-4 py-3"><div class="flex gap-2">
              <button type="button" class="px-3 py-2 border rounded-2xl" data-direct-edit="<?php echo $id; ?>" data-name="<?php echo h($name); ?>" data-phone="<?php echo h(isset($row['phone']) ? $row['phone'] : ''); ?>" data-hire-date="<?php echo h(isset($row['hire_date']) ? $row['hire_date'] : ''); ?>" data-vehicle-number="<?php echo h(isset($row['vehicle_number']) ? $row['vehicle_number'] : ''); ?>" data-resident-no="<?php echo h(isset($row['resident_no']) ? $row['resident_no'] : ''); ?>" data-bank-account="<?php echo h(isset($row['bank_account']) ? $row['bank_account'] : ''); ?>" data-bank-name="<?php echo h(isset($row['bank_name']) ? $row['bank_name'] : ''); ?>" data-account-holder="<?php echo h(isset($row['account_holder']) ? $row['account_holder'] : ''); ?>" data-monthly-salary="<?php echo h(isset($row['monthly_salary']) ? $row['monthly_salary'] : ''); ?>" data-active="<?php echo isset($row['is_active']) ? (int)$row['is_active'] : 1; ?>" data-photo="<?php echo h($photoPath); ?>">수정</button>
              <button type="button" class="px-3 py-2 border border-red-200 text-red-700 rounded-2xl" data-direct-delete="<?php echo $id; ?>" data-delete-name="<?php echo h($name); ?>">삭제</button>
            </div></td>
          </tr>
        <?php endforeach; ?>
      <?php else: ?>
        <tr><td colspan="11" class="px-4 py-8 text-center text-gray-500"><?php echo $isRetiredView ? '퇴직한 직영팀 인원이 없습니다.' : '재직 중인 직영팀 인원이 없습니다.'; ?></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php
if (!function_exists('cpms_direct_team_form_fields')) {
function cpms_direct_team_form_fields($prefix, $includeStatus) {
    ?>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <div><label class="block text-sm font-bold mb-1" for="<?php echo h($prefix); ?>Name">이름</label><input class="w-full px-4 py-3 rounded-2xl border" id="<?php echo h($prefix); ?>Name" name="name" required></div>
      <div><label class="block text-sm font-bold mb-1" for="<?php echo h($prefix); ?>Phone">연락처</label><input class="w-full px-4 py-3 rounded-2xl border" id="<?php echo h($prefix); ?>Phone" name="phone" placeholder="010-0000-0000"></div>
      <div><label class="block text-sm font-bold mb-1" for="<?php echo h($prefix); ?>HireDate">입사일</label><input type="date" class="w-full px-4 py-3 rounded-2xl border" id="<?php echo h($prefix); ?>HireDate" name="hire_date"></div>
      <div><label class="block text-sm font-bold mb-1" for="<?php echo h($prefix); ?>VehicleNumber">차량번호</label><input class="w-full px-4 py-3 rounded-2xl border" id="<?php echo h($prefix); ?>VehicleNumber" name="vehicle_number"></div>
      <div><label class="block text-sm font-bold mb-1" for="<?php echo h($prefix); ?>ResidentNo">주민번호</label><input class="w-full px-4 py-3 rounded-2xl border" id="<?php echo h($prefix); ?>ResidentNo" name="resident_no" placeholder="000000-0000000"></div>
      <div><label class="block text-sm font-bold mb-1" for="<?php echo h($prefix); ?>BankAccount">계좌번호</label><input class="w-full px-4 py-3 rounded-2xl border" id="<?php echo h($prefix); ?>BankAccount" name="bank_account"></div>
      <div><label class="block text-sm font-bold mb-1" for="<?php echo h($prefix); ?>BankName">은행</label><input class="w-full px-4 py-3 rounded-2xl border" id="<?php echo h($prefix); ?>BankName" name="bank_name"></div>
      <div><label class="block text-sm font-bold mb-1" for="<?php echo h($prefix); ?>AccountHolder">예금주</label><input class="w-full px-4 py-3 rounded-2xl border" id="<?php echo h($prefix); ?>AccountHolder" name="account_holder"></div>
      <div><label class="block text-sm font-bold mb-1" for="<?php echo h($prefix); ?>MonthlySalary">월급</label><input type="text" inputmode="numeric" class="w-full px-4 py-3 rounded-2xl border" id="<?php echo h($prefix); ?>MonthlySalary" name="monthly_salary" data-money-input placeholder="3,000,000" required></div>
      <?php if ($includeStatus): ?><div><label class="block text-sm font-bold mb-1" for="<?php echo h($prefix); ?>Active">재직 상태</label><select class="w-full px-4 py-3 rounded-2xl border" id="<?php echo h($prefix); ?>Active" name="is_active"><option value="1">재직</option><option value="0">퇴직</option></select></div><?php endif; ?>
      <div class="md:col-span-2"><label class="block text-sm font-bold mb-1">사진</label><input type="file" name="direct_team_photo" accept="image/jpeg,image/png,image/webp" class="w-full px-4 py-3 rounded-2xl border"><div class="mt-1 text-xs text-gray-500">JPG, PNG, WEBP / 최대 5MB</div></div>
    </div>
    <?php
}}
?>

<div id="modal-directTeamAdd" class="fixed inset-0 z-50 hidden"><div class="absolute inset-0 bg-black/40" data-direct-modal-close="directTeamAdd"></div><div class="absolute inset-0 flex items-center justify-center p-4"><div class="relative w-full max-w-3xl max-h-[90vh] overflow-y-auto bg-white rounded-3xl p-6"><button type="button" class="absolute right-4 top-4 px-3 py-1 border rounded-xl" data-direct-modal-close="directTeamAdd">✕</button><h3 class="text-xl font-extrabold mb-5">직영팀 추가</h3><form method="post" action="?r=admin/direct_team_save" enctype="multipart/form-data" class="space-y-5"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="direct_team_view" value="active"><?php cpms_direct_team_form_fields('directAdd', false); ?><div class="flex justify-end gap-2"><button type="button" class="px-4 py-3 rounded-2xl border" data-direct-modal-close="directTeamAdd">취소</button><button class="px-6 py-3 rounded-2xl bg-emerald-500 text-white font-extrabold">저장</button></div></form></div></div></div>

<div id="modal-directTeamEdit" class="fixed inset-0 z-50 hidden"><div class="absolute inset-0 bg-black/40" data-direct-modal-close="directTeamEdit"></div><div class="absolute inset-0 flex items-center justify-center p-4"><div class="relative w-full max-w-3xl max-h-[90vh] overflow-y-auto bg-white rounded-3xl p-6"><button type="button" class="absolute right-4 top-4 px-3 py-1 border rounded-xl" data-direct-modal-close="directTeamEdit">✕</button><h3 class="text-xl font-extrabold mb-5">직영팀 수정</h3><form method="post" action="?r=admin/direct_team_save" enctype="multipart/form-data" class="space-y-5"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="directEditId"><input type="hidden" name="direct_team_view" value="<?php echo h($directTeamView); ?>"><?php cpms_direct_team_form_fields('directEdit', true); ?><div class="rounded-2xl border p-3"><div class="font-bold mb-2">현재 사진</div><div id="directEditPhotoPreview" class="text-sm text-gray-500">등록된 사진 없음</div><label class="mt-3 block"><input type="checkbox" name="remove_photo" id="directEditRemovePhoto" value="1"> 현재 사진 삭제</label></div><div class="flex justify-end gap-2"><button type="button" class="px-4 py-3 rounded-2xl border" data-direct-modal-close="directTeamEdit">취소</button><button class="px-6 py-3 rounded-2xl bg-emerald-500 text-white font-extrabold">수정 저장</button></div></form></div></div></div>

<div id="modal-directTeamDelete" class="fixed inset-0 z-50 hidden"><div class="absolute inset-0 bg-black/40" data-direct-modal-close="directTeamDelete"></div><div class="absolute inset-0 flex items-center justify-center p-4"><div class="w-full max-w-md bg-white rounded-3xl p-6"><h3 class="text-xl font-extrabold">직영팀 삭제</h3><div id="directDeleteName" class="mt-3 text-gray-700"></div><p class="mt-3 rounded-2xl bg-amber-50 px-4 py-3 text-sm text-amber-800">노무비 이력이 있으면 기존 현장별 단가와 과거 이력을 유지한 일용직으로 전환한 뒤 직영팀 명부에서 삭제합니다.</p><form method="post" action="?r=admin/direct_team_save" class="mt-5"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="directDeleteId"><input type="hidden" name="direct_team_view" value="<?php echo h($directTeamView); ?>"><div class="flex justify-end gap-2"><button type="button" class="px-4 py-3 rounded-2xl border" data-direct-modal-close="directTeamDelete">취소</button><button class="px-5 py-3 rounded-2xl bg-red-600 text-white font-extrabold">삭제</button></div></form></div></div></div>

<script>
(function () {
    function modal(name, show) {
        var el = document.getElementById('modal-' + name);
        if (!el) return;
        if (show) el.classList.remove('hidden'); else el.classList.add('hidden');
    }
    function setValue(id, value) {
        var el = document.getElementById(id);
        if (el) el.value = value === null || typeof value === 'undefined' ? '' : value;
    }
    function formatMoney(value) {
        var digits = String(value || '').replace(/[^0-9]/g, '');
        if (digits === '') return '';
        return digits.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }
    document.addEventListener('input', function (event) {
        var target = event.target;
        if (target && target.getAttribute && target.getAttribute('data-money-input') !== null) target.value = formatMoney(target.value);
    });
    document.addEventListener('click', function (event) {
        var target = event.target;
        var open = target.closest ? target.closest('[data-direct-modal-open]') : null;
        if (open) { modal(open.getAttribute('data-direct-modal-open'), true); event.preventDefault(); return; }
        var close = target.closest ? target.closest('[data-direct-modal-close]') : null;
        if (close) { modal(close.getAttribute('data-direct-modal-close'), false); event.preventDefault(); return; }
        var toggle = target.closest ? target.closest('[data-sensitive-toggle]') : null;
        if (toggle) {
            var visible = toggle.getAttribute('aria-pressed') === 'true';
            var values = document.querySelectorAll('[data-sensitive-value]');
            for (var i = 0; i < values.length; i++) values[i].textContent = visible ? '***' : (values[i].getAttribute('data-sensitive-value') || '-');
            toggle.setAttribute('aria-pressed', visible ? 'false' : 'true');
            toggle.textContent = visible ? '민감정보 보이기' : '민감정보 숨기기';
            event.preventDefault();
            return;
        }
        var edit = target.closest ? target.closest('[data-direct-edit]') : null;
        if (edit) {
            setValue('directEditId', edit.getAttribute('data-direct-edit'));
            setValue('directEditName', edit.getAttribute('data-name'));
            setValue('directEditPhone', edit.getAttribute('data-phone'));
            setValue('directEditHireDate', edit.getAttribute('data-hire-date'));
            setValue('directEditVehicleNumber', edit.getAttribute('data-vehicle-number'));
            setValue('directEditResidentNo', edit.getAttribute('data-resident-no'));
            setValue('directEditBankAccount', edit.getAttribute('data-bank-account'));
            setValue('directEditBankName', edit.getAttribute('data-bank-name'));
            setValue('directEditAccountHolder', edit.getAttribute('data-account-holder'));
            setValue('directEditMonthlySalary', formatMoney(edit.getAttribute('data-monthly-salary')));
            setValue('directEditActive', edit.getAttribute('data-active') === '0' ? '0' : '1');
            var photo = edit.getAttribute('data-photo') || '';
            var preview = document.getElementById('directEditPhotoPreview');
            if (preview) {
                preview.textContent = '';
                if (photo === '') {
                    preview.textContent = '등록된 사진 없음';
                } else {
                    var image = document.createElement('img');
                    image.src = photo;
                    image.alt = '현재 사진';
                    image.className = 'w-20 h-20 rounded-2xl object-cover border';
                    preview.appendChild(image);
                }
            }
            var removePhoto = document.getElementById('directEditRemovePhoto');
            if (removePhoto) removePhoto.checked = false;
            modal('directTeamEdit', true);
            event.preventDefault();
            return;
        }
        var del = target.closest ? target.closest('[data-direct-delete]') : null;
        if (del) {
            setValue('directDeleteId', del.getAttribute('data-direct-delete'));
            var name = document.getElementById('directDeleteName');
            if (name) name.textContent = '대상: ' + (del.getAttribute('data-delete-name') || '');
            modal('directTeamDelete', true);
            event.preventDefault();
        }
    });
}());
</script>
