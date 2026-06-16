<?php
/**
 * Existing project Drive folder sync admin screen.
 * PHP 5.6 compatible.
 */

use App\Core\Auth;
use App\Core\Db;

require_once __DIR__ . '/../../services/ProjectDriveSyncService.php';

if (!(Auth::isMaster() || Auth::canManageEmployees())) {
    echo '<div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 font-bold">접근 권한이 없습니다. 마스터 또는 관리자만 사용할 수 있습니다.</div>';
    return;
}

if (!function_exists('cpms_admin_project_drive_sync_badge_class')) {
function cpms_admin_project_drive_sync_badge_class($status) {
    $status = trim((string)$status);
    if ($status === '성공' || $status === '생성' || $status === '하위 생성' || $status === '이미 있음' || $status === '이미 Drive 정보 있음' || $status === '기존 Drive 폴더 재사용 예정') {
        return 'bg-emerald-50 border-emerald-200 text-emerald-700';
    }
    if ($status === '일부 생성' || $status === '일부 실패' || $status === 'Drive 정보는 있으나 폴더 확인 필요' || $status === 'Drive 폴더 재연결 예정') {
        return 'bg-amber-50 border-amber-200 text-amber-700';
    }
    if ($status === '확인 필요' || $status === '중복 확인 필요' || $status === '실패' || $status === 'Drive 확인 실패' || $status === 'Drive 설정 확인 필요') {
        return 'bg-red-50 border-red-200 text-red-700';
    }
    return 'bg-gray-50 border-gray-200 text-gray-700';
}}

if (!function_exists('cpms_admin_project_drive_sync_badge')) {
function cpms_admin_project_drive_sync_badge($status) {
    $class = cpms_admin_project_drive_sync_badge_class($status);
    return '<span class="inline-flex px-2 py-1 rounded-lg border text-xs font-bold ' . h($class) . '">' . h($status !== '' ? $status : '-') . '</span>';
}}

if (!function_exists('cpms_admin_project_drive_sync_scope')) {
function cpms_admin_project_drive_sync_scope($value) {
    $value = trim((string)$value);
    if ($value === 'failed' || $value === 'single') return $value;
    return 'all';
}}

$pdo = Db::pdo();
$result = null;
$errorMessage = '';
$selectedScope = isset($_POST['scope']) ? cpms_admin_project_drive_sync_scope($_POST['scope']) : 'all';
$selectedProjectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
    if (!csrf_check($token)) {
        $errorMessage = '보안 토큰이 올바르지 않습니다. 새로고침 후 다시 시도해주세요.';
    } else if (!$pdo) {
        $errorMessage = 'DB 연결에 실패했습니다.';
    } else {
        $action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';
        if ($action === 'preview') {
            $result = cpms_project_drive_sync_preview($pdo, $selectedScope, $selectedProjectId, Auth::user());
        } else if ($action === 'run') {
            $result = cpms_project_drive_sync_run($pdo, $selectedScope, $selectedProjectId, Auth::user());
        } else {
            $errorMessage = '알 수 없는 요청입니다.';
        }
    }
}

$isRunResult = (is_array($result) && isset($result['mode']) && $result['mode'] === 'run');
$isPreviewResult = (is_array($result) && isset($result['mode']) && $result['mode'] === 'preview');
?>

<div class="space-y-5">
  <div>
    <div class="text-sm text-gray-500">관리 / Google Drive</div>
    <h3 class="text-xl font-extrabold text-gray-900">기존 프로젝트 Drive 폴더 동기화</h3>
    <div class="text-sm text-gray-500 mt-1">이미 운영 중인 프로젝트에 Drive 폴더 ID와 기본 하위 폴더 정보를 보정합니다.</div>
  </div>

  <?php if ($errorMessage !== ''): ?>
    <div class="p-4 rounded-2xl border border-red-200 bg-red-50 text-red-700 font-bold">
      <?php echo h($errorMessage); ?>
    </div>
  <?php endif; ?>

  <div class="bg-white border border-gray-200 rounded-2xl p-4">
    <div class="font-extrabold text-gray-900 mb-3">안내</div>
    <ul class="list-disc pl-5 text-sm text-gray-700 space-y-1">
      <li>기존 프로젝트를 삭제하거나 다시 만들지 않습니다.</li>
      <li>Drive 폴더가 없는 프로젝트만 생성하고, 같은 이름의 폴더가 있으면 재사용합니다.</li>
      <li>기존 Drive 정보가 있으면 먼저 실제 Drive 폴더를 확인한 뒤 재사용합니다.</li>
      <li>기존 로컬 첨부파일은 이동하거나 삭제하지 않습니다.</li>
      <li>전자결재 폴더는 프로젝트 폴더 안에 만들지 않습니다.</li>
      <li>실제 실행 전에는 <code>storage/backups</code> 아래에 프로젝트 데이터 백업을 생성합니다.</li>
    </ul>
  </div>

  <form method="post" action="?r=<?php echo urlencode('관리'); ?>&tab=project_drive_sync" class="bg-white border border-gray-200 rounded-2xl p-4">
    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <div>
        <div class="text-sm font-bold text-gray-700 mb-1">대상</div>
        <select name="scope" class="w-full px-3 py-2 rounded-xl border border-gray-200 bg-white">
          <option value="all" <?php echo $selectedScope === 'all' ? 'selected' : ''; ?>>전체 프로젝트</option>
          <option value="failed" <?php echo $selectedScope === 'failed' ? 'selected' : ''; ?>>실패/확인 필요 프로젝트만</option>
          <option value="single" <?php echo $selectedScope === 'single' ? 'selected' : ''; ?>>특정 프로젝트 1개</option>
        </select>
      </div>
      <div>
        <div class="text-sm font-bold text-gray-700 mb-1">프로젝트 ID</div>
        <input type="number" name="project_id" value="<?php echo $selectedProjectId > 0 ? (int)$selectedProjectId : ''; ?>" class="w-full px-3 py-2 rounded-xl border border-gray-200" placeholder="특정 프로젝트 선택 시 입력">
      </div>
      <div class="flex items-end gap-2">
        <button type="submit" name="action" value="preview" class="px-4 py-2 rounded-xl bg-gray-900 text-white font-extrabold">미리보기</button>
        <button type="submit" name="action" value="run" class="px-4 py-2 rounded-xl bg-emerald-700 text-white font-extrabold" onclick="return confirm('기존 프로젝트 Drive 폴더 동기화를 실제 실행할까요? 실행 전 백업이 생성됩니다.');">실제 실행</button>
      </div>
    </div>
  </form>

  <?php if (is_array($result)): ?>
    <div class="bg-white border border-gray-200 rounded-2xl p-4">
      <div class="flex items-center justify-between gap-3 mb-3 flex-wrap">
        <div>
          <div class="font-extrabold text-gray-900"><?php echo $isRunResult ? '실행 결과' : '미리보기 결과'; ?></div>
          <div class="text-xs text-gray-500 mt-1"><?php echo h(isset($result['message']) ? $result['message'] : ''); ?></div>
        </div>
        <div class="text-sm text-gray-600">
          전체 <?php echo isset($result['total']) ? (int)$result['total'] : 0; ?>건
          <?php if ($isRunResult): ?>
            / 성공 <?php echo (int)$result['success']; ?>건
            / 실패 <?php echo (int)$result['failed']; ?>건
            / 건너뜀 <?php echo (int)$result['skipped']; ?>건
          <?php endif; ?>
        </div>
      </div>

      <?php if ($isRunResult): ?>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-4 text-sm">
          <div class="rounded-xl border border-gray-100 bg-gray-50 p-3">
            <div class="font-bold text-gray-700">백업</div>
            <div class="mt-1"><?php echo !empty($result['backup']['ok']) ? cpms_admin_project_drive_sync_badge('성공') : cpms_admin_project_drive_sync_badge('실패'); ?></div>
            <div class="mt-2 text-gray-600"><?php echo h(isset($result['backup']['message']) ? $result['backup']['message'] : ''); ?></div>
            <?php if (!empty($result['backup']['path'])): ?>
              <div class="mt-1 text-xs text-gray-500"><code><?php echo h($result['backup']['path']); ?></code></div>
            <?php endif; ?>
          </div>
          <div class="rounded-xl border border-gray-100 bg-gray-50 p-3">
            <div class="font-bold text-gray-700">실행 로그</div>
            <div class="mt-1"><?php echo !empty($result['log_written']) ? cpms_admin_project_drive_sync_badge('성공') : cpms_admin_project_drive_sync_badge('실패'); ?></div>
            <div class="mt-2 text-xs text-gray-500"><code><?php echo h(isset($result['log_path']) ? $result['log_path'] : cpms_project_drive_sync_log_path()); ?></code></div>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($isPreviewResult): ?>
        <div class="overflow-x-auto rounded-xl border border-gray-200">
          <table class="min-w-full text-sm">
            <thead class="bg-gray-50">
              <tr class="text-left text-gray-600">
                <th class="p-3 border-b">프로젝트 ID</th>
                <th class="p-3 border-b">프로젝트명</th>
                <th class="p-3 border-b">현재 Drive 상태</th>
                <th class="p-3 border-b">예상 생성 폴더명</th>
                <th class="p-3 border-b">처리 예정 상태</th>
                <th class="p-3 border-b">메시지</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($result['rows'] as $row): ?>
                <tr>
                  <td class="p-3 border-b"><?php echo (int)$row['project_id']; ?></td>
                  <td class="p-3 border-b font-bold text-gray-900"><?php echo h($row['project_name'] !== '' ? $row['project_name'] : '-'); ?></td>
                  <td class="p-3 border-b"><?php echo h($row['current_drive_status']); ?></td>
                  <td class="p-3 border-b"><code><?php echo h($row['expected_folder_name']); ?></code></td>
                  <td class="p-3 border-b"><?php echo cpms_admin_project_drive_sync_badge($row['planned_status']); ?></td>
                  <td class="p-3 border-b text-gray-600"><?php echo h($row['message']); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <?php if ($isRunResult): ?>
        <div class="overflow-x-auto rounded-xl border border-gray-200">
          <table class="min-w-full text-sm">
            <thead class="bg-gray-50">
              <tr class="text-left text-gray-600">
                <th class="p-3 border-b">프로젝트명</th>
                <th class="p-3 border-b">프로젝트 ID</th>
                <th class="p-3 border-b">프로젝트 폴더 ID</th>
                <th class="p-3 border-b">공무</th>
                <th class="p-3 border-b">관리</th>
                <th class="p-3 border-b">공사</th>
                <th class="p-3 border-b">안전보건</th>
                <th class="p-3 border-b">품질</th>
                <th class="p-3 border-b">최종 상태</th>
                <th class="p-3 border-b">메시지</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($result['rows'] as $row): ?>
                <?php $sections = isset($row['section_statuses']) && is_array($row['section_statuses']) ? $row['section_statuses'] : array(); ?>
                <tr>
                  <td class="p-3 border-b font-bold text-gray-900"><?php echo h(isset($row['project_name']) && $row['project_name'] !== '' ? $row['project_name'] : '-'); ?></td>
                  <td class="p-3 border-b"><?php echo isset($row['project_id']) ? (int)$row['project_id'] : 0; ?></td>
                  <td class="p-3 border-b"><code><?php echo h(isset($row['project_folder_id']) ? $row['project_folder_id'] : ''); ?></code></td>
                  <td class="p-3 border-b"><?php echo cpms_admin_project_drive_sync_badge(isset($sections['public_affairs']) ? $sections['public_affairs'] : '-'); ?></td>
                  <td class="p-3 border-b"><?php echo cpms_admin_project_drive_sync_badge(isset($sections['management']) ? $sections['management'] : '-'); ?></td>
                  <td class="p-3 border-b"><?php echo cpms_admin_project_drive_sync_badge(isset($sections['construction']) ? $sections['construction'] : '-'); ?></td>
                  <td class="p-3 border-b"><?php echo cpms_admin_project_drive_sync_badge(isset($sections['safety_health']) ? $sections['safety_health'] : '-'); ?></td>
                  <td class="p-3 border-b"><?php echo cpms_admin_project_drive_sync_badge(isset($sections['quality']) ? $sections['quality'] : '-'); ?></td>
                  <td class="p-3 border-b"><?php echo cpms_admin_project_drive_sync_badge(isset($row['final_status']) ? $row['final_status'] : '-'); ?></td>
                  <td class="p-3 border-b text-gray-600"><?php echo h(isset($row['message']) ? $row['message'] : ''); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="bg-white border border-gray-200 rounded-2xl p-4 text-sm text-gray-600">
    <div class="font-extrabold text-gray-900 mb-2">이번 작업에서 하지 않는 것</div>
    <div>기존 로컬 첨부파일의 Drive 이관은 수행하지 않습니다. 이후 별도 이관 도구로 진행하는 것을 제안합니다.</div>
  </div>
</div>
