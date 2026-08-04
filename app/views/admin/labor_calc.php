<?php
/**
 * C:\www\cpms\app\views\admin\labor_calc.php
 * - 관리 > 노무비 계산
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/labor_consultant_labor_only_override.php';
require_once __DIR__ . '/labor_consultant_helpers.php';
require_once __DIR__ . '/../../services/ManagementDriveService.php';

$pdo = \App\Core\Db::pdo();
$user = \App\Core\Auth::user();
$canAccess = cpms_labor_consultant_can_access($pdo, $user);
$section = isset($_GET['section']) ? trim((string)$_GET['section']) : 'consultant';
if ($section !== 'consultant' && $section !== 'tax') {
    $section = 'consultant';
}
$projectId = isset($_GET['project_id']) ? $_GET['project_id'] : 'all';
$ym = isset($_GET['ym']) ? $_GET['ym'] : cpms_labor_consultant_current_ym();
$projectId = cpms_labor_consultant_normalize_project_filter($projectId);
$ym = cpms_labor_consultant_normalize_ym($ym);
$flash = function_exists('flash_get') ? flash_get() : null;

if (!$canAccess) {
    echo '<div class="rounded-2xl border border-red-200 bg-red-50 p-4 font-bold text-red-700">접근 권한이 없습니다. 관리부서 전용 화면입니다.</div>';
    return;
}

$setupRows = cpms_labor_consultant_setup_status($pdo, false);
$activeTemplate = cpms_labor_consultant_get_active_template($pdo);
$templateHistory = cpms_labor_consultant_list_template_history($pdo);
if ($activeTemplate) {
    $activeTemplatePath = cpms_labor_consultant_resolve_stored_path(isset($activeTemplate['stored_path']) ? $activeTemplate['stored_path'] : '');
    if ($activeTemplatePath === '' || !is_file($activeTemplatePath)) {
        $activeTemplate = null;
    }
}
$viewData = cpms_labor_consultant_load_view_data($pdo, $projectId, $ym);
$projects = isset($viewData['projects']) ? $viewData['projects'] : array();
$rows = isset($viewData['rows']) ? $viewData['rows'] : array();
$daysInMonth = isset($viewData['days_in_month']) ? (int)$viewData['days_in_month'] : cpms_labor_consultant_days_in_month($ym);
$emptyMessage = isset($viewData['message']) ? $viewData['message'] : '';
$totalAmount = 0;
foreach ($rows as $row) {
    $totalAmount += isset($row['amount']) ? (float)$row['amount'] : 0;
}
?>

<div class="mx-auto max-w-full space-y-6">
  <div class="flex flex-wrap items-start justify-between gap-4">
    <div>
      <div class="text-2xl font-black tracking-tight text-gray-900">노무비 계산</div>
    </div>
    <div class="rounded-2xl border border-gray-200 bg-white px-4 py-3 text-right shadow-sm">
      <div class="text-xs text-gray-500">노무비 조회 합계</div>
      <div class="text-2xl font-black text-gray-900"><?php echo h(number_format($totalAmount)); ?></div>
      <div class="text-xs text-gray-500"><?php echo h($ym); ?></div>
    </div>
  </div>

  <?php if (is_array($flash) && isset($flash['type']) && isset($flash['message'])): ?>
    <?php
    $flashClass = 'border-blue-200 bg-blue-50 text-blue-800';
    if ($flash['type'] === 'success') $flashClass = 'border-green-200 bg-green-50 text-green-800';
    if ($flash['type'] === 'error' || $flash['type'] === 'danger') $flashClass = 'border-red-200 bg-red-50 text-red-800';
    ?>
    <div class="rounded-2xl border p-4 font-bold <?php echo h($flashClass); ?>">
      <?php echo h($flash['message']); ?>
    </div>
  <?php endif; ?>

  <div class="grid gap-3 md:grid-cols-2">
    <a href="<?php echo h(cpms_labor_consultant_view_url($projectId, $ym, 'consultant')); ?>" class="rounded-2xl border px-5 py-4 font-extrabold <?php echo $section === 'consultant' ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-gray-200 bg-white text-gray-900'; ?>">
      노무사 확인용
    </a>
    <a href="<?php echo h(cpms_labor_consultant_view_url($projectId, $ym, 'tax')); ?>" class="rounded-2xl border px-5 py-4 font-extrabold <?php echo $section === 'tax' ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-gray-200 bg-white text-gray-900'; ?>">
      세무서 전달용
    </a>
  </div>

  <?php if ($section === 'tax'): ?>
    <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
      <div class="text-lg font-extrabold text-gray-900">세무서 전달용</div>
      <div class="mt-3 text-sm text-gray-600">추후 작업 예정입니다.</div>
    </div>
    <?php return; ?>
  <?php endif; ?>

  <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <div class="text-lg font-extrabold text-gray-900">노무사 확인용</div>
        <div class="mt-1 text-sm text-gray-600">공사 &gt; 노무비 &gt; 노무비 탭에 표시되는 공수와 금액만 가져옵니다. 외주비 날짜와 전액 외주비 인원은 제외됩니다.</div>
      </div>
      <form method="post" action="?r=admin/labor_consultant_setup" class="m-0">
        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
        <input type="hidden" name="project_id" value="<?php echo h($projectId); ?>">
        <input type="hidden" name="ym" value="<?php echo h($ym); ?>">
        <button type="submit" class="rounded-xl border border-gray-200 bg-white px-4 py-2 font-bold text-gray-700 hover:bg-gray-50">관리 DB 설치/확인</button>
      </form>
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
      <div class="space-y-4">
        <form method="get" class="grid gap-3 rounded-2xl border border-gray-200 bg-gray-50 p-4 lg:grid-cols-[minmax(0,1fr)_180px_auto_auto] lg:items-end">
          <input type="hidden" name="r" value="관리">
          <input type="hidden" name="tab" value="labor_calc">
          <input type="hidden" name="section" value="consultant">

          <div>
            <div class="mb-1 text-xs font-bold text-gray-600">현장 선택</div>
            <select name="project_id" class="w-full rounded-xl border border-gray-300 bg-white px-3 py-2">
              <option value="all" <?php echo $projectId === 'all' ? 'selected' : ''; ?>>전체 현장</option>
              <?php foreach ($projects as $project): ?>
                <?php $optionId = (string)(int)(isset($project['id']) ? $project['id'] : 0); ?>
                <option value="<?php echo h($optionId); ?>" <?php echo $projectId === $optionId ? 'selected' : ''; ?>>
                  <?php echo h(isset($project['name']) ? $project['name'] : ''); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <div class="mb-1 text-xs font-bold text-gray-600">년월 선택</div>
            <input type="month" name="ym" value="<?php echo h($ym); ?>" class="w-full rounded-xl border border-gray-300 bg-white px-3 py-2">
          </div>

          <button type="submit" class="rounded-xl bg-gray-900 px-4 py-2 font-bold text-white">조회</button>

          <?php
          $laborExportUrl = '?r=admin/labor_consultant_export&project_id=' . urlencode($projectId) . '&ym=' . urlencode($ym);
          $laborDownloadEnabled = ($activeTemplate);
          ?>
          <button type="button" id="laborConsultantDownloadBtn" data-url="<?php echo h($laborExportUrl); ?>" class="rounded-xl px-4 py-2 text-center font-bold <?php echo $laborDownloadEnabled ? 'bg-emerald-600 text-white' : 'bg-gray-200 text-gray-500'; ?>" <?php echo $laborDownloadEnabled ? '' : 'disabled'; ?>>엑셀 다운로드</button>
        </form>
        <div id="laborConsultantDownloadStatus" class="mt-3 hidden rounded-2xl border px-4 py-3 text-sm font-bold"></div>

        <?php if ($emptyMessage !== ''): ?>
          <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm font-bold text-amber-800"><?php echo h($emptyMessage); ?></div>
        <?php endif; ?>

        <div class="overflow-x-auto rounded-2xl border border-gray-200">
          <table class="min-w-[2280px] w-full text-xs">
            <thead class="bg-gray-100">
              <tr>
                <th class="border border-gray-200 px-3 py-2">현장명</th>
                <th class="border border-gray-200 px-3 py-2">성명</th>
                <th class="border border-gray-200 px-3 py-2">직종</th>
                <th class="border border-gray-200 px-3 py-2">연락처</th>
                <th class="border border-gray-200 px-3 py-2">주민등록번호</th>
                <th class="border border-gray-200 px-3 py-2">주소</th>
                <th class="border border-gray-200 px-3 py-2">예금주</th>
                <th class="border border-gray-200 px-3 py-2">은행명</th>
                <th class="border border-gray-200 px-3 py-2">계좌번호</th>
                <th class="border border-gray-200 px-3 py-2">단가</th>
                <th class="border border-gray-200 px-3 py-2">출력일수</th>
                <th class="border border-gray-200 px-3 py-2">총 공수</th>
                <th class="border border-gray-200 px-3 py-2">노무비 반영금액</th>
                <?php for ($d = 1; $d <= $daysInMonth; $d++): ?>
                  <th class="border border-gray-200 px-2 py-2"><?php echo h((string)$d); ?></th>
                <?php endfor; ?>
                <th class="border border-gray-200 px-3 py-2">합계</th>
              </tr>
            </thead>
            <tbody>
              <?php if (count($rows) > 0): ?>
                <?php foreach ($rows as $idx => $row): ?>
                  <tr class="<?php echo ($idx % 2 === 0) ? 'bg-white' : 'bg-gray-50'; ?>">
                    <td class="border border-gray-200 px-3 py-2"><?php echo h(isset($row['project_name']) ? $row['project_name'] : ''); ?></td>
                    <td class="border border-gray-200 px-3 py-2 font-bold text-gray-900"><?php echo h(isset($row['worker_name']) ? $row['worker_name'] : ''); ?></td>
                    <td class="border border-gray-200 px-3 py-2"><?php echo h(isset($row['role']) && $row['role'] !== '' ? $row['role'] : '-'); ?></td>
                    <td class="border border-gray-200 px-3 py-2"><?php echo h(isset($row['phone']) ? $row['phone'] : ''); ?></td>
                    <td class="border border-gray-200 px-3 py-2"><?php echo h(isset($row['resident_no']) ? $row['resident_no'] : ''); ?></td>
                    <td class="border border-gray-200 px-3 py-2"><?php echo h(isset($row['address']) ? $row['address'] : ''); ?></td>
                    <td class="border border-gray-200 px-3 py-2"><?php echo h(isset($row['account_holder']) ? $row['account_holder'] : ''); ?></td>
                    <td class="border border-gray-200 px-3 py-2"><?php echo h(isset($row['bank_name']) ? $row['bank_name'] : ''); ?></td>
                    <td class="border border-gray-200 px-3 py-2"><?php echo h(isset($row['bank_account']) ? $row['bank_account'] : ''); ?></td>
                    <td class="border border-gray-200 px-3 py-2 text-right"><?php echo h(number_format(isset($row['wage_rate']) ? (float)$row['wage_rate'] : 0)); ?></td>
                    <td class="border border-gray-200 px-3 py-2 text-right"><?php echo h(number_format(isset($row['work_days_count']) ? (int)$row['work_days_count'] : 0)); ?></td>
                    <td class="border border-gray-200 px-3 py-2 text-right"><?php echo h(rtrim(rtrim(number_format(isset($row['total_gongsu']) ? (float)$row['total_gongsu'] : 0, 2, '.', ''), '0'), '.')); ?></td>
                    <td class="border border-gray-200 px-3 py-2 text-right"><?php echo h(number_format(isset($row['amount']) ? (float)$row['amount'] : 0)); ?></td>
                    <?php for ($d = 1; $d <= $daysInMonth; $d++): ?>
                      <?php
                      $dayValue = '';
                      if (isset($row['days']) && is_array($row['days']) && isset($row['days'][$d])) {
                          $dayValue = $row['days'][$d];
                          if ($dayValue !== '') {
                              $dayValue = rtrim(rtrim(number_format((float)$dayValue, 2, '.', ''), '0'), '.');
                          }
                      }
                      ?>
                      <td class="border border-gray-200 px-2 py-2 text-center"><?php echo h($dayValue); ?></td>
                    <?php endfor; ?>
                    <td class="border border-gray-200 px-3 py-2 text-right font-bold"><?php echo h(rtrim(rtrim(number_format(isset($row['total_gongsu']) ? (float)$row['total_gongsu'] : 0, 2, '.', ''), '0'), '.')); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr>
                  <td colspan="<?php echo h((string)(14 + $daysInMonth)); ?>" class="px-4 py-8 text-center text-sm text-gray-500">조회된 데이터가 없습니다.</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="space-y-4">
        <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4">
          <div class="text-sm font-extrabold text-gray-900">현재 사용 중인 양식</div>
          <?php if ($activeTemplate): ?>
            <div class="mt-3 text-sm text-gray-700">
              <div><span class="font-bold">파일명:</span> <?php echo h(isset($activeTemplate['original_name']) ? $activeTemplate['original_name'] : ''); ?></div>
              <div class="mt-1"><span class="font-bold">업로드일:</span> <?php echo h(isset($activeTemplate['uploaded_at']) ? $activeTemplate['uploaded_at'] : '-'); ?></div>
              <?php if (isset($activeTemplate['storage_type']) && (string)$activeTemplate['storage_type'] === 'google_drive'): ?>
                <div class="mt-2 flex flex-wrap gap-2 text-xs">
                  <?php if (isset($activeTemplate['drive_web_view_link']) && trim((string)$activeTemplate['drive_web_view_link']) !== ''): ?>
                    <a class="font-bold text-blue-700" href="<?php echo h($activeTemplate['drive_web_view_link']); ?>" target="_blank" rel="noopener"><?php echo h(cpms_management_drive_label('view')); ?></a>
                  <?php endif; ?>
                  <?php if (isset($activeTemplate['drive_web_content_link']) && trim((string)$activeTemplate['drive_web_content_link']) !== ''): ?>
                    <a class="font-bold text-gray-700" href="<?php echo h($activeTemplate['drive_web_content_link']); ?>" target="_blank" rel="noopener noreferrer" data-cpms-no-loading="1"><?php echo h(cpms_management_drive_label('download')); ?></a>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <div class="mt-3 text-sm font-bold text-amber-800">등록된 노무사 확인용 양식이 없습니다. 먼저 엑셀 양식을 업로드해주세요.</div>
          <?php endif; ?>
        </div>

        <form method="post" action="?r=admin/labor_consultant_template_upload" enctype="multipart/form-data" class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
          <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
          <input type="hidden" name="project_id" value="<?php echo h($projectId); ?>">
          <input type="hidden" name="ym" value="<?php echo h($ym); ?>">
          <div class="text-sm font-extrabold text-gray-900">노무사 확인용 엑셀 양식 업로드</div>
          <div class="mt-2 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-bold text-amber-800">노무비가 입력된 다운로드 결과 파일이 아니라, 비어 있는 원본 양식 파일을 올려주세요.</div>
          <input type="file" name="template_file" accept=".xlsx" class="mt-4 w-full rounded-xl border border-gray-300 bg-white px-3 py-2" required>
          <button type="submit" class="mt-4 w-full rounded-xl bg-emerald-600 px-4 py-2 font-bold text-white">양식파일 업로드/교체</button>
        </form>

        <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
          <div class="flex items-center justify-between gap-3">
            <div class="text-sm font-extrabold text-gray-900">양식 업로드 이력</div>
            <div class="text-xs font-bold text-gray-500"><?php echo h(number_format(count($templateHistory))); ?>개</div>
          </div>
          <div class="mt-2 text-xs text-gray-500">현재 양식과 이전에 올린 원본 파일을 확인하고 다시 받을 수 있습니다.</div>

          <?php if (count($templateHistory) > 0): ?>
            <div class="mt-3 max-h-[420px] space-y-2 overflow-y-auto pr-1">
              <?php foreach ($templateHistory as $templateItem): ?>
                <?php
                $templateItemId = isset($templateItem['id']) ? (int)$templateItem['id'] : 0;
                $templateItemActive = isset($templateItem['is_active']) && (int)$templateItem['is_active'] === 1;
                $templateItemName = isset($templateItem['original_name']) ? trim((string)$templateItem['original_name']) : '';
                if ($templateItemName === '') $templateItemName = '이름 없는 양식.xlsx';
                $templateLooksGenerated = strpos($templateItemName, '노무사확인용_노무비_') === 0;
                $templateUploader = isset($templateItem['uploader_name']) ? trim((string)$templateItem['uploader_name']) : '';
                if ($templateUploader === '' && isset($templateItem['uploader_email'])) $templateUploader = trim((string)$templateItem['uploader_email']);
                if ($templateUploader === '') $templateUploader = '-';
                $templateUploadedAt = isset($templateItem['uploaded_at']) ? trim((string)$templateItem['uploaded_at']) : '';
                if ($templateUploadedAt === '') $templateUploadedAt = '-';
                $templateFileSize = isset($templateItem['file_size']) ? (int)$templateItem['file_size'] : 0;
                $templateFileSizeText = $templateFileSize > 0 ? number_format($templateFileSize / 1024, 1) . ' KB' : '-';
                $templateLocalPath = cpms_labor_consultant_safe_template_path($templateItem);
                $templateDriveDownload = isset($templateItem['drive_web_content_link']) ? trim((string)$templateItem['drive_web_content_link']) : '';
                $templateDriveDownloadAvailable = ($templateDriveDownload !== ''
                    && strpos($templateDriveDownload, "\r") === false
                    && strpos($templateDriveDownload, "\n") === false
                    && preg_match('/^https:\/\//i', $templateDriveDownload));
                $templateLocalDownloadAvailable = ($templateItemId > 0 && $templateLocalPath !== '');
                $templateDownloadUrl = '?r=admin/labor_consultant_template_download&template_id=' . urlencode((string)$templateItemId);
                ?>
                <div class="rounded-xl border px-3 py-3 <?php echo $templateItemActive ? 'border-emerald-300 bg-emerald-50' : 'border-gray-200 bg-gray-50'; ?>">
                  <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                      <div class="break-all text-xs font-extrabold text-gray-900"><?php echo h($templateItemName); ?></div>
                      <div class="mt-1 text-[11px] text-gray-500"><?php echo h($templateUploadedAt); ?> · <?php echo h($templateUploader); ?> · <?php echo h($templateFileSizeText); ?></div>
                    </div>
                    <span class="shrink-0 rounded-full px-2 py-1 text-[10px] font-extrabold <?php echo $templateItemActive ? 'bg-emerald-600 text-white' : 'bg-gray-200 text-gray-600'; ?>"><?php echo $templateItemActive ? '현재 사용' : '이전 양식'; ?></span>
                  </div>
                  <?php if ($templateLooksGenerated): ?>
                    <div class="mt-2 rounded-lg bg-red-100 px-2 py-1.5 text-[11px] font-extrabold text-red-700">결과파일 의심: 시스템에서 내려받은 노무비 파일명과 같습니다.</div>
                  <?php endif; ?>
                  <?php if ($templateLocalDownloadAvailable): ?>
                    <a href="<?php echo h($templateDownloadUrl); ?>" download data-cpms-no-loading="1" class="mt-2 inline-flex rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-bold text-gray-700 hover:bg-gray-100">서버 원본 다운로드</a>
                  <?php elseif ($templateDriveDownloadAvailable): ?>
                    <a href="<?php echo h($templateDriveDownload); ?>" target="_blank" rel="noopener noreferrer" data-cpms-no-loading="1" class="mt-2 inline-flex rounded-lg border border-blue-300 bg-blue-50 px-3 py-1.5 text-xs font-bold text-blue-700 hover:bg-blue-100">Drive 원본 다운로드</a>
                  <?php else: ?>
                    <div class="mt-2 text-xs font-bold text-red-600">보관 파일을 찾을 수 없습니다.</div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="mt-3 rounded-xl bg-gray-50 px-3 py-4 text-center text-xs text-gray-500">아직 저장된 양식 이력이 없습니다.</div>
          <?php endif; ?>
        </div>

        <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
          <div class="text-sm font-extrabold text-gray-900">다운로드 안내</div>
          <ul class="mt-3 list-disc space-y-1 pl-5 text-xs text-gray-600">
            <li>노무비 기간은 해당 월 1일 ~ 말일 기준입니다.</li>
            <li>공수 수정 승인 완료 값만 반영됩니다.</li>
            <li>날짜로 선택한 외주비 기간은 일별 공수, 출력일수, 총공수에서 제외됩니다.</li>
            <li>전액 외주비 인원은 제외하고, 비율 배분 인원은 노무비 비율만큼의 금액만 반영됩니다.</li>
            <li>엑셀은 업로드한 양식 파일을 그대로 기반으로 생성됩니다.</li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</div>
<script>
(function() {
  var button = document.getElementById('laborConsultantDownloadBtn');
  var status = document.getElementById('laborConsultantDownloadStatus');
  if (!button || !status) return;

  function showStatus(message, isError) {
    status.className = 'mt-3 rounded-2xl border px-4 py-3 text-sm font-bold ' + (isError ? 'border-red-200 bg-red-50 text-red-700' : 'border-emerald-200 bg-emerald-50 text-emerald-700');
    status.textContent = message;
  }

  button.addEventListener('click', function() {
    var url = button.getAttribute('data-url');
    if (!url || button.disabled) return;

    // 파일: app/views/admin/labor_calc.php
    // 사전 검증과 실제 생성을 두 번 요청하던 방식을 없애 대기시간과 DB 조회를 절반으로 줄입니다.
    button.disabled = true;
    showStatus('엑셀 다운로드를 준비 중입니다.', false);
    window.location.href = url;
    window.setTimeout(function() {
      showStatus('다운로드 요청을 보냈습니다. 파일 생성이 완료되면 자동으로 저장됩니다.', false);
      button.disabled = false;
    }, 1000);
  });
})();
</script>
