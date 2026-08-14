<?php
/**
 * C:\www\cpms\app\views\admin\workforce.php
 * - 관리 > 인력관리 목록/검색/엑셀 import 미리보기
 * - PHP 5.6 호환
 */

use App\Core\Auth;
use App\Core\Db;

require_once __DIR__ . '/../../services/WorkerRepository.php';
require_once __DIR__ . '/../../services/ExcelWorkerImporter.php';

$canManageWorkforce = (Auth::isMaster() || Auth::canManageEmployees());
if (!$canManageWorkforce) {
    echo '<div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 font-bold">접근 권한이 없습니다. 관리자 또는 관리팀만 사용할 수 있습니다.</div>';
    return;
}

$pdo = Db::pdo();
$repo = new WorkerRepository($pdo);
$repo->ensureSchema();

$filters = array(
    'q' => isset($_GET['q']) ? trim((string)$_GET['q']) : '',
    'phone' => isset($_GET['phone']) ? trim((string)$_GET['phone']) : '',
    'job_type' => isset($_GET['job_type']) ? trim((string)$_GET['job_type']) : '',
    'agency_name' => isset($_GET['agency_name']) ? trim((string)$_GET['agency_name']) : '',
    'status' => isset($_GET['status']) ? trim((string)$_GET['status']) : 'active',
);
$workforceUser = Auth::user();
$workforceUserId = is_array($workforceUser) && isset($workforceUser['id']) ? (int)$workforceUser['id'] : 0;
$workforceRelaxedEdit = Auth::isDevelopmentDepartment();
// 관리 인력관리 진입 시에도 기존 모든 현장 인원을 삭제 없이 마스터로 편입합니다.
$repo->syncLegacyProjectWorkers(0, $workforceUserId, 5000);
$workers = $repo->listWorkers($filters, 500);

function cpms_workforce_missing_fields($worker)
{
    if (!is_array($worker) || (isset($worker['status']) && $worker['status'] === 'deleted')) return array();
    $missing = array();
    $checks = array(
        'name' => '이름', 'phone' => '연락처', 'resident_no_masked' => '주민번호',
        'job_type' => '구분/직종', 'agency_name' => '인력사 업체명',
        'bank_name' => '은행명', 'bank_account_masked' => '계좌번호', 'account_holder' => '예금주'
    );
    foreach ($checks as $field => $label) {
        if (!isset($worker[$field]) || trim((string)$worker[$field]) === '') $missing[] = $label;
    }
    if (!isset($worker['daily_wage']) || (int)$worker['daily_wage'] <= 0) $missing[] = '임금단가';
    return $missing;
}

foreach ($workers as $workerIndex => $workerRow) {
    $missingFields = cpms_workforce_missing_fields($workerRow);
    $workers[$workerIndex]['missing_fields'] = $missingFields;
    $workers[$workerIndex]['missing_count'] = count($missingFields);
}
usort($workers, function ($a, $b) {
    $aMissing = isset($a['missing_count']) ? (int)$a['missing_count'] : 0;
    $bMissing = isset($b['missing_count']) ? (int)$b['missing_count'] : 0;
    if ($aMissing !== $bMissing) return $aMissing > $bMissing ? -1 : 1;
    $aName = isset($a['name']) ? (string)$a['name'] : '';
    $bName = isset($b['name']) ? (string)$b['name'] : '';
    return strcmp($aName, $bName);
});

$importToken = isset($_GET['import_token']) ? trim((string)$_GET['import_token']) : '';
$preview = null;
if ($importToken !== '' && isset($_SESSION['worker_import_preview'][$importToken]) && is_array($_SESSION['worker_import_preview'][$importToken])) {
    $preview = $_SESSION['worker_import_preview'][$importToken];
}

$importer = new ExcelWorkerImporter();
$fieldLabels = $importer->fieldLabels();
$columnOptions = $importer->columnOptions();
$defaultMapping = $importer->defaultMapping();
if ($preview && isset($preview['mapping']) && is_array($preview['mapping'])) {
    $defaultMapping = $importer->normalizeMapping($preview['mapping']);
}

function cpms_workforce_status_label($status)
{
    if ($status === 'deleted') return 'deleted';
    if ($status === 'inactive') return 'inactive';
    return 'active';
}
?>

<link rel="stylesheet" href="<?php echo h(asset_url('assets/css/workforce.css')); ?>">

<div class="cpms-workforce-page">
  <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
    <div>
      <h2 class="text-2xl font-extrabold text-gray-900">인력관리</h2>
    </div>
    <div class="flex flex-wrap gap-2">
      <a href="?r=admin/workforce_form" class="px-4 py-3 rounded-2xl bg-emerald-600 text-white font-extrabold">인력 추가</a>
      <a href="?r=admin/workforce_upload" class="px-4 py-3 rounded-2xl bg-gray-900 text-white font-extrabold">엑셀 업로드</a>
      <button type="button" class="px-4 py-3 rounded-2xl border border-red-200 text-red-600 font-extrabold" data-workforce-bulk-delete>선택 삭제</button>
    </div>
  </div>

  <form id="workforceBulkDeleteForm" method="post" action="?r=admin/workforce_delete" style="display:none;">
    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
  </form>

  <?php if ($preview && is_array($preview)): ?>
    <?php $summary = isset($preview['summary']) && is_array($preview['summary']) ? $preview['summary'] : array(); ?>
    <div class="mb-6 rounded-3xl border border-amber-200 bg-amber-50 p-5">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
          <div class="text-sm font-bold text-amber-800">엑셀 미리보기</div>
          <div class="text-xs text-amber-700 mt-1">저장 전 컬럼 매핑과 중복 항목을 확인하세요.</div>
        </div>
        <a href="?r=관리&tab=workforce" class="px-3 py-2 rounded-xl border border-amber-300 bg-white text-amber-800 font-bold">미리보기 닫기</a>
      </div>

      <div class="mt-4 grid grid-cols-2 md:grid-cols-5 gap-3">
        <div class="cpms-workforce-stat"><b><?php echo (int)(isset($summary['total_rows']) ? $summary['total_rows'] : 0); ?></b><span>전체 행 수</span></div>
        <div class="cpms-workforce-stat"><b><?php echo (int)(isset($summary['importable_rows']) ? $summary['importable_rows'] : 0); ?></b><span>import 가능</span></div>
        <div class="cpms-workforce-stat"><b><?php echo (int)(isset($summary['no_name_rows']) ? $summary['no_name_rows'] : 0); ?></b><span>성명 없음</span></div>
        <div class="cpms-workforce-stat"><b><?php echo (int)(isset($summary['duplicate_rows']) ? $summary['duplicate_rows'] : 0); ?></b><span>중복 의심</span></div>
        <div class="cpms-workforce-stat"><b><?php echo (int)(isset($summary['error_rows']) ? $summary['error_rows'] : 0); ?></b><span>오류</span></div>
      </div>

      <form method="post" action="?r=admin/workforce_import_process" class="mt-5 space-y-4">
        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
        <input type="hidden" name="import_token" value="<?php echo h($importToken); ?>">
        <input type="hidden" name="default_agency_name" value="<?php echo h(isset($preview['default_agency_name']) ? $preview['default_agency_name'] : ''); ?>">

        <div class="rounded-2xl bg-white border border-amber-100 p-4">
          <div class="font-extrabold text-gray-900">컬럼 매핑</div>
          <div class="mt-3 grid grid-cols-1 md:grid-cols-3 lg:grid-cols-4 gap-3">
            <?php foreach ($fieldLabels as $field => $label): ?>
              <label class="text-sm">
                <span class="block text-xs font-bold text-gray-500 mb-1"><?php echo h($label); ?></span>
                <select name="mapping[<?php echo h($field); ?>]" class="w-full px-3 py-2 rounded-xl border border-gray-200">
                  <?php foreach ($columnOptions as $colNo => $colLabel): ?>
                    <option value="<?php echo (int)$colNo; ?>" <?php echo ((int)(isset($defaultMapping[$field]) ? $defaultMapping[$field] : 0) === (int)$colNo) ? 'selected' : ''; ?>>
                      <?php echo h($colLabel); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </label>
            <?php endforeach; ?>
          </div>
          <label class="mt-4 inline-flex items-center gap-2 text-sm font-bold text-gray-700">
            <input type="checkbox" name="update_duplicate" value="1" checked>
            중복이면 기존 인력 정보를 업데이트합니다.
          </label>
        </div>

        <div class="overflow-x-auto rounded-2xl border border-gray-200 bg-white">
          <table class="min-w-[1280px] w-full text-sm">
            <thead class="bg-gray-100 text-gray-700">
              <tr>
                <th class="px-3 py-2 border">행</th>
                <th class="px-3 py-2 border">상태</th>
                <th class="px-3 py-2 border">성명</th>
                <th class="px-3 py-2 border">주민번호</th>
                <th class="px-3 py-2 border">연락처</th>
                <th class="px-3 py-2 border">구분/직종</th>
                <th class="px-3 py-2 border">인력사 업체명</th>
                <th class="px-3 py-2 border">단가</th>
                <th class="px-3 py-2 border">은행명</th>
                <th class="px-3 py-2 border">계좌</th>
                <th class="px-3 py-2 border">비고</th>
              </tr>
            </thead>
            <tbody>
              <?php $previewRows = isset($preview['rows']) && is_array($preview['rows']) ? $preview['rows'] : array(); ?>
              <?php foreach (array_slice($previewRows, 0, 80) as $pr): ?>
                <?php $d = isset($pr['data']) && is_array($pr['data']) ? $pr['data'] : array(); ?>
                <tr>
                  <td class="px-3 py-2 border text-center"><?php echo (int)(isset($pr['row_no']) ? $pr['row_no'] : 0); ?></td>
                  <td class="px-3 py-2 border"><?php echo h(isset($pr['status']) ? $pr['status'] : ''); ?></td>
                  <td class="px-3 py-2 border font-bold"><?php echo h(isset($d['name']) ? $d['name'] : ''); ?></td>
                  <td class="px-3 py-2 border"><?php echo h(CryptoHelper::maskResidentNo(isset($d['resident_no']) ? $d['resident_no'] : '')); ?></td>
                  <td class="px-3 py-2 border"><?php echo h(isset($d['phone']) ? $d['phone'] : ''); ?></td>
                  <td class="px-3 py-2 border"><?php echo h(isset($d['job_type']) ? $d['job_type'] : ''); ?></td>
                  <td class="px-3 py-2 border"><?php echo h(isset($d['agency_name']) ? $d['agency_name'] : ''); ?></td>
                  <td class="px-3 py-2 border text-right"><?php echo number_format((int)(isset($d['daily_wage']) ? $d['daily_wage'] : 0)); ?></td>
                  <td class="px-3 py-2 border"><?php echo h(isset($d['bank_name']) ? $d['bank_name'] : ''); ?></td>
                  <td class="px-3 py-2 border"><?php echo h(CryptoHelper::maskBankAccount(isset($d['bank_account']) ? $d['bank_account'] : '')); ?></td>
                  <td class="px-3 py-2 border"><?php echo h(isset($d['memo']) ? $d['memo'] : ''); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="flex justify-end">
          <button type="submit" class="px-5 py-3 rounded-2xl bg-emerald-600 text-white font-extrabold">import 실행</button>
        </div>
      </form>
    </div>
  <?php endif; ?>

  <form method="get" class="rounded-3xl border border-gray-200 bg-white p-5 mb-5">
    <input type="hidden" name="r" value="관리">
    <input type="hidden" name="tab" value="workforce">
    <div class="grid grid-cols-1 md:grid-cols-6 gap-3">
      <input name="q" value="<?php echo h($filters['q']); ?>" class="px-4 py-3 rounded-2xl border border-gray-200 md:col-span-2" placeholder="이름을 입력하세요">
      <input name="phone" value="<?php echo h($filters['phone']); ?>" class="px-4 py-3 rounded-2xl border border-gray-200" placeholder="연락처">
      <input name="job_type" value="<?php echo h($filters['job_type']); ?>" class="px-4 py-3 rounded-2xl border border-gray-200" placeholder="구분/직종">
      <input name="agency_name" value="<?php echo h($filters['agency_name']); ?>" class="px-4 py-3 rounded-2xl border border-gray-200" placeholder="인력사 업체명">
      <select name="status" class="px-4 py-3 rounded-2xl border border-gray-200">
        <option value="">전체</option>
        <option value="active" <?php echo $filters['status']==='active'?'selected':''; ?>>active</option>
        <option value="inactive" <?php echo $filters['status']==='inactive'?'selected':''; ?>>inactive</option>
        <option value="deleted" <?php echo $filters['status']==='deleted'?'selected':''; ?>>deleted</option>
      </select>
    </div>
    <div class="mt-3 flex flex-wrap justify-end gap-2">
      <a href="?r=관리&tab=workforce" class="px-4 py-2 rounded-2xl border border-gray-200 font-bold">초기화</a>
      <button class="px-4 py-2 rounded-2xl bg-gray-900 text-white font-extrabold">검색</button>
    </div>
  </form>

  <div class="rounded-3xl border border-gray-200 bg-white overflow-hidden">
    <div class="overflow-x-auto">
      <table class="min-w-[1320px] w-full text-sm">
        <thead class="bg-gray-50 text-gray-700">
          <tr>
            <th class="px-3 py-3 border-b"><input type="checkbox" data-workforce-check-all></th>
            <th class="px-3 py-3 border-b text-left">미입력</th>
            <th class="px-3 py-3 border-b text-left">이름</th>
            <th class="px-3 py-3 border-b text-left">연락처</th>
            <th class="px-3 py-3 border-b text-left">주민번호</th>
            <th class="px-3 py-3 border-b text-left">구분/직종</th>
            <th class="px-3 py-3 border-b text-left">인력사 업체명</th>
            <th class="px-3 py-3 border-b text-right">임금단가</th>
            <th class="px-3 py-3 border-b text-left">은행명</th>
            <th class="px-3 py-3 border-b text-left">계좌번호</th>
            <th class="px-3 py-3 border-b text-left">예금주</th>
            <th class="px-3 py-3 border-b text-left">상태</th>
            <th class="px-3 py-3 border-b">수정</th>
            <th class="px-3 py-3 border-b">삭제</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <?php if (empty($workers)): ?>
            <tr><td colspan="14" class="px-4 py-8 text-center text-gray-500">등록된 인력이 없습니다.</td></tr>
          <?php else: ?>
            <?php foreach ($workers as $w): ?>
              <?php
                $residentMasked = isset($w['resident_no_masked']) ? trim((string)$w['resident_no_masked']) : '';
                if ($residentMasked === '') $residentMasked = '-';
                $bankMasked = isset($w['bank_account_masked']) ? trim((string)$w['bank_account_masked']) : '';
                if ($bankMasked === '') $bankMasked = '-';
              ?>
              <tr class="<?php echo isset($w['missing_count']) && (int)$w['missing_count'] > 0 ? 'bg-red-50 text-red-900' : ''; ?>">
                <td class="px-3 py-3 text-center"><input type="checkbox" class="workforce-row-check" value="<?php echo (int)$w['id']; ?>"></td>
                <td class="px-3 py-3">
                  <?php if (isset($w['missing_count']) && (int)$w['missing_count'] > 0): ?>
                    <span class="inline-flex rounded-full bg-red-600 px-2 py-1 text-xs font-extrabold text-white"><?php echo (int)$w['missing_count']; ?>개</span>
                    <div class="mt-1 max-w-[180px] text-[11px] font-bold text-red-700"><?php echo h(implode(', ', $w['missing_fields'])); ?></div>
                  <?php else: ?>
                    <span class="text-xs font-bold text-emerald-700">완료</span>
                  <?php endif; ?>
                </td>
                <td class="px-3 py-3 font-extrabold text-gray-900"><?php echo h($w['name']); ?></td>
                <td class="px-3 py-3"><?php echo h($w['phone']); ?></td>
                <td class="px-3 py-3" data-sensitive-cell>
                  <div class="cpms-sensitive-wrap">
                    <span class="cpms-sensitive-value" data-sensitive-display data-masked="<?php echo h($residentMasked); ?>"><?php echo h($residentMasked); ?></span>
                    <?php if ($residentMasked !== '-'): ?>
                      <button type="button" class="cpms-sensitive-toggle" data-workforce-sensitive-toggle data-worker-id="<?php echo (int)$w['id']; ?>" data-sensitive-field="resident_no" data-sensitive-visible="0">마스킹 해제</button>
                    <?php endif; ?>
                  </div>
                </td>
                <td class="px-3 py-3"><?php echo h($w['job_type']); ?></td>
                <td class="px-3 py-3"><?php echo h($w['agency_name']); ?></td>
                <td class="px-3 py-3 text-right"><?php echo number_format((int)$w['daily_wage']); ?></td>
                <td class="px-3 py-3"><?php echo h($w['bank_name']); ?></td>
                <td class="px-3 py-3" data-sensitive-cell>
                  <div class="cpms-sensitive-wrap">
                    <span class="cpms-sensitive-value" data-sensitive-display data-masked="<?php echo h($bankMasked); ?>"><?php echo h($bankMasked); ?></span>
                    <?php if ($bankMasked !== '-'): ?>
                      <button type="button" class="cpms-sensitive-toggle" data-workforce-sensitive-toggle data-worker-id="<?php echo (int)$w['id']; ?>" data-sensitive-field="bank_account" data-sensitive-visible="0">마스킹 해제</button>
                    <?php endif; ?>
                  </div>
                </td>
                <td class="px-3 py-3"><?php echo h($w['account_holder']); ?></td>
                <td class="px-3 py-3"><?php echo h(cpms_workforce_status_label($w['status'])); ?></td>
                <td class="px-3 py-3 text-center"><button type="button" class="px-3 py-2 rounded-xl border border-gray-200 bg-white font-bold" data-workforce-edit data-worker-id="<?php echo (int)$w['id']; ?>">수정</button></td>
                <td class="px-3 py-3 text-center">
                  <?php if ($w['status'] !== 'deleted'): ?>
                    <form method="post" action="?r=admin/workforce_delete" class="inline" data-workforce-delete-form>
                      <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                      <input type="hidden" name="id" value="<?php echo (int)$w['id']; ?>">
                      <button type="submit" class="px-3 py-2 rounded-xl border border-red-200 text-red-600 font-bold">삭제</button>
                    </form>
                  <?php else: ?>
                    -
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div id="workforceEditModal" class="fixed inset-0 z-50 hidden" aria-hidden="true">
    <div class="absolute inset-0 bg-black/50" data-workforce-edit-close></div>
    <div class="absolute inset-0 flex items-center justify-center p-4">
      <form id="workforceEditForm" method="post" action="?r=admin/workforce_save" data-cpms-no-loading="1" data-workforce-relaxed="<?php echo $workforceRelaxedEdit ? '1' : '0'; ?>" novalidate class="relative max-h-[92vh] w-full max-w-4xl overflow-y-auto rounded-3xl bg-white p-6 shadow-2xl">
        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
        <input type="hidden" name="ajax" value="1">
        <input type="hidden" name="id" value="">
        <input type="hidden" name="import_no" value="">
        <input type="hidden" name="birth_date" value="">
        <input type="hidden" name="is_active" value="1">
        <input type="hidden" name="source_type" value="manual">
        <div class="flex items-center justify-between gap-3">
          <div><h3 class="text-xl font-extrabold text-gray-900">인력 수정</h3><div class="mt-1 text-xs font-bold <?php echo $workforceRelaxedEdit ? 'text-blue-700' : 'text-red-600'; ?>"><?php echo $workforceRelaxedEdit ? '개발부서는 이름만 필수이며 나머지는 비워두고 저장할 수 있습니다.' : '필수 9개 항목을 모두 채워 저장하세요.'; ?></div></div>
          <button type="button" class="rounded-xl border border-gray-200 px-3 py-2 font-bold" data-workforce-edit-close>닫기</button>
        </div>
        <div id="workforceEditError" role="alert" aria-live="assertive" class="sticky top-0 z-20 mt-4 hidden rounded-2xl border border-red-200 bg-red-50 p-3 text-sm font-bold text-red-700 shadow-sm"></div>
        <div class="mt-5 grid grid-cols-1 gap-4 md:grid-cols-3">
          <label><span class="block text-sm font-bold mb-1">이름</span><input name="name" required class="w-full rounded-2xl border border-gray-200 px-4 py-3"></label>
          <label><span class="block text-sm font-bold mb-1">연락처</span><input name="phone" <?php echo $workforceRelaxedEdit ? '' : 'required'; ?> class="w-full rounded-2xl border border-gray-200 px-4 py-3"></label>
          <label><span class="block text-sm font-bold mb-1">주민번호</span><input name="resident_no" <?php echo $workforceRelaxedEdit ? '' : 'required'; ?> class="w-full rounded-2xl border border-gray-200 px-4 py-3"></label>
          <label><span class="block text-sm font-bold mb-1">구분/직종</span><input name="job_type" <?php echo $workforceRelaxedEdit ? '' : 'required'; ?> class="w-full rounded-2xl border border-gray-200 px-4 py-3"></label>
          <label><span class="block text-sm font-bold mb-1">인력사 업체명</span><input name="agency_name" <?php echo $workforceRelaxedEdit ? '' : 'required'; ?> class="w-full rounded-2xl border border-gray-200 px-4 py-3"></label>
          <label><span class="block text-sm font-bold mb-1">임금단가</span><input name="daily_wage" type="number" min="<?php echo $workforceRelaxedEdit ? '0' : '1'; ?>" <?php echo $workforceRelaxedEdit ? '' : 'required'; ?> class="w-full rounded-2xl border border-gray-200 px-4 py-3"></label>
          <label><span class="block text-sm font-bold mb-1">은행명</span><input name="bank_name" <?php echo $workforceRelaxedEdit ? '' : 'required'; ?> class="w-full rounded-2xl border border-gray-200 px-4 py-3"></label>
          <label><span class="block text-sm font-bold mb-1">계좌번호</span><input name="bank_account" <?php echo $workforceRelaxedEdit ? '' : 'required'; ?> class="w-full rounded-2xl border border-gray-200 px-4 py-3"></label>
          <label><span class="block text-sm font-bold mb-1">예금주</span><input name="account_holder" <?php echo $workforceRelaxedEdit ? '' : 'required'; ?> class="w-full rounded-2xl border border-gray-200 px-4 py-3"></label>
          <label class="md:col-span-3"><span class="block text-sm font-bold mb-1">주소(선택)</span><input name="address" class="w-full rounded-2xl border border-gray-200 px-4 py-3"></label>
          <label class="md:col-span-3"><span class="block text-sm font-bold mb-1">비고(선택)</span><textarea name="memo" rows="3" class="w-full rounded-2xl border border-gray-200 px-4 py-3"></textarea></label>
        </div>
        <div class="mt-5 flex justify-end gap-2">
          <button type="button" class="rounded-2xl border border-gray-200 px-5 py-3 font-bold" data-workforce-edit-close>취소</button>
          <button type="button" class="rounded-2xl bg-emerald-600 px-6 py-3 font-extrabold text-white disabled:cursor-not-allowed disabled:bg-gray-400" data-workforce-edit-submit>저장</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php $adminWorkforceJsPath = dirname(dirname(dirname(__DIR__))) . '/public/assets/js/admin_workforce.js'; ?>
<script defer src="<?php echo h(asset_url('assets/js/admin_workforce.js') . '?v=' . (string)@filemtime($adminWorkforceJsPath)); ?>"></script>
