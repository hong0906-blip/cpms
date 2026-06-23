<?php
/**
 * C:\www\cpms\app\views\admin\workforce_form.php
 * - 관리 > 인력관리 신규/수정 폼
 * - PHP 5.6 호환
 */

use App\Core\Auth;
use App\Core\Db;

require_once __DIR__ . '/../../services/WorkerRepository.php';

if (!(Auth::isMaster() || Auth::canManageEmployees())) {
    echo '<div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 font-bold">접근 권한이 없습니다.</div>';
    return;
}

$pdo = Db::pdo();
$repo = new WorkerRepository($pdo);
$repo->ensureSchema();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$worker = $id > 0 ? $repo->getById($id, true) : null;
if ($id > 0 && !$worker) {
    echo '<div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 font-bold">인력 정보를 찾을 수 없습니다.</div>';
    return;
}

function cpms_worker_form_value($worker, $key)
{
    return is_array($worker) && isset($worker[$key]) ? (string)$worker[$key] : '';
}
?>

<link rel="stylesheet" href="<?php echo h(asset_url('assets/css/workforce.css')); ?>">

<div class="cpms-workforce-page">
  <div class="mb-6">
    <h2 class="text-2xl font-extrabold text-gray-900"><?php echo $id > 0 ? '인력 수정' : '인력 추가'; ?></h2>
  </div>

  <form method="post" action="?r=admin/workforce_save" class="rounded-3xl border border-gray-200 bg-white p-6 space-y-5">
    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
    <input type="hidden" name="id" value="<?php echo (int)$id; ?>">

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <label>
        <span class="block text-sm font-bold text-gray-700 mb-1">이름</span>
        <input name="name" required value="<?php echo h(cpms_worker_form_value($worker, 'name')); ?>" class="w-full px-4 py-3 rounded-2xl border border-gray-200">
      </label>
      <label>
        <span class="block text-sm font-bold text-gray-700 mb-1">주민등록번호</span>
        <input name="resident_no" value="" class="w-full px-4 py-3 rounded-2xl border border-gray-200" placeholder="<?php echo h($id > 0 ? cpms_worker_form_value($worker, 'resident_no_masked') . ' / 변경 시에만 입력' : '650720-1234567'); ?>">
      </label>
      <label>
        <span class="block text-sm font-bold text-gray-700 mb-1">생년월일</span>
        <input type="date" name="birth_date" value="<?php echo h(cpms_worker_form_value($worker, 'birth_date')); ?>" class="w-full px-4 py-3 rounded-2xl border border-gray-200">
      </label>
      <label>
        <span class="block text-sm font-bold text-gray-700 mb-1">연락처</span>
        <input name="phone" value="<?php echo h(cpms_worker_form_value($worker, 'phone')); ?>" class="w-full px-4 py-3 rounded-2xl border border-gray-200" placeholder="010-0000-0000">
      </label>
      <label class="md:col-span-2">
        <span class="block text-sm font-bold text-gray-700 mb-1">주소</span>
        <input name="address" value="<?php echo h(cpms_worker_form_value($worker, 'address')); ?>" class="w-full px-4 py-3 rounded-2xl border border-gray-200">
      </label>
      <label>
        <span class="block text-sm font-bold text-gray-700 mb-1">구분/직종</span>
        <input name="job_type" value="<?php echo h(cpms_worker_form_value($worker, 'job_type')); ?>" class="w-full px-4 py-3 rounded-2xl border border-gray-200" placeholder="형틀목수">
      </label>
      <label>
        <span class="block text-sm font-bold text-gray-700 mb-1">인력사 업체명</span>
        <input name="agency_name" value="<?php echo h(cpms_worker_form_value($worker, 'agency_name')); ?>" class="w-full px-4 py-3 rounded-2xl border border-gray-200" placeholder="업체명">
      </label>
      <label>
        <span class="block text-sm font-bold text-gray-700 mb-1">임금단가</span>
        <input name="daily_wage" value="<?php echo h(cpms_worker_form_value($worker, 'daily_wage')); ?>" class="w-full px-4 py-3 rounded-2xl border border-gray-200" placeholder="250000">
      </label>
      <label>
        <span class="block text-sm font-bold text-gray-700 mb-1">은행명</span>
        <input name="bank_name" value="<?php echo h(cpms_worker_form_value($worker, 'bank_name')); ?>" class="w-full px-4 py-3 rounded-2xl border border-gray-200">
      </label>
      <label>
        <span class="block text-sm font-bold text-gray-700 mb-1">계좌번호</span>
        <input name="bank_account" value="" class="w-full px-4 py-3 rounded-2xl border border-gray-200" placeholder="<?php echo h($id > 0 ? cpms_worker_form_value($worker, 'bank_account_masked') . ' / 변경 시에만 입력' : '계좌번호'); ?>">
      </label>
      <label>
        <span class="block text-sm font-bold text-gray-700 mb-1">예금주</span>
        <input name="account_holder" value="<?php echo h(cpms_worker_form_value($worker, 'account_holder')); ?>" class="w-full px-4 py-3 rounded-2xl border border-gray-200">
      </label>
      <label>
        <span class="block text-sm font-bold text-gray-700 mb-1">순번(import_no)</span>
        <input name="import_no" value="<?php echo h(cpms_worker_form_value($worker, 'import_no')); ?>" class="w-full px-4 py-3 rounded-2xl border border-gray-200">
      </label>
      <label>
        <span class="block text-sm font-bold text-gray-700 mb-1">사용여부</span>
        <select name="is_active" class="w-full px-4 py-3 rounded-2xl border border-gray-200">
          <option value="1" <?php echo (!$worker || (int)$worker['is_active'] === 1) ? 'selected' : ''; ?>>active</option>
          <option value="0" <?php echo ($worker && (int)$worker['is_active'] === 0) ? 'selected' : ''; ?>>inactive</option>
        </select>
      </label>
      <label class="md:col-span-3">
        <span class="block text-sm font-bold text-gray-700 mb-1">비고</span>
        <textarea name="memo" rows="4" class="w-full px-4 py-3 rounded-2xl border border-gray-200"><?php echo h(cpms_worker_form_value($worker, 'memo')); ?></textarea>
      </label>
    </div>

    <div class="flex justify-end gap-2">
      <a href="?r=관리&tab=workforce" class="px-5 py-3 rounded-2xl border border-gray-200 font-bold">취소</a>
      <button type="submit" class="px-6 py-3 rounded-2xl bg-emerald-600 text-white font-extrabold">저장</button>
    </div>
  </form>
</div>
