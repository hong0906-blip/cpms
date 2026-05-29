<?php
/**
 * C:\www\cpms\app\views\admin\labor_calc.php
 * - 관리 > 노무비 계산
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/labor_consultant_helpers.php';

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
      <div class="mt-1 text-sm text-gray-500">관리부서 전용 노무사 확인용 조회와 양식 기반 엑셀 다운로드를 제공합니다.</div>
    </div>
    <div class="rounded-2xl border border-gray-200 bg-white px-4 py-3 text-right shadow-sm">
      <div class="text-xs text-gray-500">조회 합계</div>
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
        <div class="mt-1 text-sm text-gray-500">공사섹션 노무비 계산 결과와 같은 기준으로 월별 노무비를 조회합니다.</div>
      </div>
      <form method="post" action="?r=admin/labor_consultant_setup" class="m-0">
        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
        <input type="hidden" name="project_id" value="<?php echo h($projectId); ?>">
        <input type="hidden" name="ym" value="<?php echo h($ym); ?>">
        <button type="submit" class="rounded-xl border border-gray-200 bg-white px-4 py-2 font-bold text-gray-700 hover:bg-gray-50">관리 DB 설치/확인</button>
      </form>
    </div>

    <div class="mt-5 overflow-x-auto">
      <table class="min-w-full border border-gray-200 text-sm">
        <thead class="bg-gray-50">
          <tr>
            <th class="border border-gray-200 px-3 py-2 text-left">구분</th>
            <th class="border border-gray-200 px-3 py-2 text-left">대상</th>
            <th class="border border-gray-200 px-3 py-2 text-left">결과</th>
            <th class="border border-gray-200 px-3 py-2 text-left">메시지</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($setupRows as $setupRow): ?>
            <tr>
              <td class="border border-gray-200 px-3 py-2"><?php echo h(isset($setupRow['kind']) ? $setupRow['kind'] : ''); ?></td>
              <td class="border border-gray-200 px-3 py-2"><?php echo h(isset($setupRow['target']) ? $setupRow['target'] : ''); ?></td>
              <td class="border border-gray-200 px-3 py-2 <?php echo (isset($setupRow['status']) && $setupRow['status'] === '성공') ? 'text-emerald-700' : 'text-red-700'; ?>"><?php echo h(isset($setupRow['status']) ? $setupRow['status'] : ''); ?></td>
              <td class="border border-gray-200 px-3 py-2"><?php echo h(isset($setupRow['message']) ? $setupRow['message'] : ''); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
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
          <table class="min-w-[2200px] w-full text-xs">
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
                <th class="border border-gray-200 px-3 py-2">총 공수</th>
                <th class="border border-gray-200 px-3 py-2">지급금액</th>
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
                  <td colspan="<?php echo h((string)(13 + $daysInMonth)); ?>" class="px-4 py-8 text-center text-sm text-gray-500">조회된 데이터가 없습니다.</td>
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
          <div class="mt-1 text-xs text-gray-500">업로드한 .xlsx 양식을 이후 다운로드에 계속 재사용합니다.</div>
          <input type="file" name="template_file" accept=".xlsx" class="mt-4 w-full rounded-xl border border-gray-300 bg-white px-3 py-2" required>
          <button type="submit" class="mt-4 w-full rounded-xl bg-emerald-600 px-4 py-2 font-bold text-white">양식파일 업로드/교체</button>
        </form>

        <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
          <div class="text-sm font-extrabold text-gray-900">다운로드 안내</div>
          <ul class="mt-3 list-disc space-y-1 pl-5 text-xs text-gray-600">
            <li>노무비 기간은 해당 월 1일 ~ 말일 기준입니다.</li>
            <li>공수 수정 승인 완료 값만 반영됩니다.</li>
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

  function cleanErrorText(text) {
    text = String(text || '');
    text = text.replace(/<script[\s\S]*?<\/script>/gi, ' ');
    text = text.replace(/<style[\s\S]*?<\/style>/gi, ' ');
    text = text.replace(/<[^>]*>/g, ' ');
    text = text.replace(/\s+/g, ' ').trim();
    if (text === '') text = '서버가 엑셀 파일 대신 오류 응답을 반환했습니다.';
    return text.substring(0, 500);
  }

  function filenameFromDisposition(disposition) {
    disposition = String(disposition || '');
    var star = disposition.match(/filename\*=UTF-8''([^;]+)/i);
    if (star && star[1]) {
      try { return decodeURIComponent(star[1].replace(/"/g, '')); } catch (e) {}
    }
    var plain = disposition.match(/filename="?([^";]+)"?/i);
    if (plain && plain[1]) return plain[1];
    return 'labor_consultant.xlsx';
  }

  function addQuery(url, key, value) {
    var glue = url.indexOf('?') === -1 ? '?' : '&';
    return url + glue + encodeURIComponent(key) + '=' + encodeURIComponent(value);
  }

  button.addEventListener('click', function() {
    var url = button.getAttribute('data-url');
    if (!url || button.disabled) return;
    if (!window.fetch) {
      window.location.href = url;
      return;
    }

    button.disabled = true;
    showStatus('엑셀 다운로드를 준비 중입니다.', false);

    fetch(addQuery(url, 'check_labor_export', '1'), { credentials: 'same-origin' }).then(function(res) {
      return res.text();
    }).then(function(text) {
      var message = cleanErrorText(text);
      if (message !== 'OK') {
        throw new Error(message);
      }
      window.location.href = url;
      showStatus('엑셀 다운로드가 시작되었습니다.', false);
      button.disabled = false;
    }).catch(function(err) {
      var message = err && err.message ? err.message : '알 수 없는 오류';
      if (message === 'Failed to fetch') {
        showStatus('다운로드 검증 요청이 실패했습니다. 직접 다운로드로 전환합니다.', true);
        window.location.href = url;
      } else {
        showStatus('다운로드 실패: ' + message, true);
      }
      button.disabled = false;
    });
  });
})();
</script>
