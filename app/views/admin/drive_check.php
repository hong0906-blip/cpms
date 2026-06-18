<?php
/**
 * Master-only Google Drive connection check.
 * PHP 5.6 compatible.
 */

use App\Core\Auth;
use App\Core\Db;

require_once __DIR__ . '/../../services/GoogleDriveHelper.php';
require_once __DIR__ . '/../../services/ApprovalPdfService.php';
require_once __DIR__ . '/../../services/PublicAffairsDriveService.php';
require_once __DIR__ . '/../../services/ManagementDriveService.php';
require_once __DIR__ . '/../../services/ConstructionDriveService.php';
require_once __DIR__ . '/../../services/SafetyHealthDriveService.php';
require_once __DIR__ . '/../../services/QualityDriveService.php';

if (!(Auth::isMaster() || Auth::canManageEmployees())) {
    echo '<div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 font-bold">접근 권한이 없습니다. 마스터 관리자만 사용할 수 있습니다.</div>';
    return;
}

if (!function_exists('cpms_admin_drive_check_badge')) {
function cpms_admin_drive_check_badge($ok) {
    if ($ok) {
        return '<span class="inline-flex px-2 py-1 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs font-bold">정상</span>';
    }
    return '<span class="inline-flex px-2 py-1 rounded-lg bg-red-50 border border-red-200 text-red-700 text-xs font-bold">확인필요</span>';
}}

if (!function_exists('cpms_admin_drive_check_row')) {
function cpms_admin_drive_check_row($label, $ok, $message, $httpCode) {
    echo '<tr>';
    echo '<th class="text-left p-3 border-b border-gray-100 bg-gray-50 w-52">' . h($label) . '</th>';
    echo '<td class="p-3 border-b border-gray-100">' . cpms_admin_drive_check_badge($ok) . '</td>';
    echo '<td class="p-3 border-b border-gray-100 text-sm text-gray-700">' . h($message) . '</td>';
    echo '<td class="p-3 border-b border-gray-100 text-sm text-gray-500">' . ((int)$httpCode > 0 ? h((string)(int)$httpCode) : '-') . '</td>';
    echo '</tr>';
}}

$config = cpms_drive_config();
$jsonInfo = cpms_drive_read_service_account();
$checkResult = null;
$checkError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
    if (!csrf_check($token)) {
        $checkError = '보안 토큰이 올바르지 않습니다. 새로고침 후 다시 시도해주세요.';
    } else {
        $publicAffairsProjectId = isset($_POST['public_affairs_project_id']) ? (int)$_POST['public_affairs_project_id'] : 0;
        $constructionProjectId = isset($_POST['construction_project_id']) ? (int)$_POST['construction_project_id'] : $publicAffairsProjectId;
        $managementProjectId = isset($_POST['management_project_id']) ? (int)$_POST['management_project_id'] : $publicAffairsProjectId;
        $safetyHealthProjectId = isset($_POST['safety_health_project_id']) ? (int)$_POST['safety_health_project_id'] : $publicAffairsProjectId;
        $qualityProjectId = isset($_POST['quality_project_id']) ? (int)$_POST['quality_project_id'] : $publicAffairsProjectId;
        $checkResult = cpms_drive_run_connection_check(Auth::user());
        if (is_array($checkResult)) {
            $checkResult['completed_pdf'] = cpms_approval_pdf_run_admin_check(Auth::user());
            $checkResult['public_affairs'] = cpms_public_affairs_drive_run_admin_check(Db::pdo(), Auth::user(), $publicAffairsProjectId);
            $checkResult['construction'] = cpms_construction_drive_run_admin_check(Db::pdo(), Auth::user(), $constructionProjectId);
            $checkResult['safety_health'] = cpms_safety_health_drive_run_admin_check(Db::pdo(), Auth::user(), $safetyHealthProjectId);
            $checkResult['quality'] = cpms_quality_drive_run_admin_check(Db::pdo(), Auth::user(), $qualityProjectId);
            $checkResult['management'] = cpms_management_drive_run_admin_check(Db::pdo(), Auth::user(), $managementProjectId);
        }
    }
}

$folders = isset($config['folders']) && is_array($config['folders']) ? $config['folders'] : array();
?>

<div class="space-y-5">
  <div>
    <div class="text-sm text-gray-500">관리 / Google Drive</div>
    <h3 class="text-xl font-extrabold text-gray-900">Drive 연결 점검</h3>
  </div>

  <?php if ($checkError !== ''): ?>
    <div class="p-4 rounded-2xl border border-red-200 bg-red-50 text-red-700 font-bold">
      <?php echo h($checkError); ?>
    </div>
  <?php endif; ?>

  <div class="bg-white border border-gray-200 rounded-2xl p-4">
    <div class="font-extrabold text-gray-900 mb-3">설정</div>
    <table class="w-full border-collapse text-sm">
      <tr>
        <th class="text-left p-3 border-b border-gray-100 bg-gray-50 w-56">서비스 계정 JSON</th>
        <td class="p-3 border-b border-gray-100"><code><?php echo h(cpms_drive_mask_path(isset($config['service_account_json_path']) ? $config['service_account_json_path'] : '')); ?></code></td>
      </tr>
      <tr>
        <th class="text-left p-3 border-b border-gray-100 bg-gray-50">서비스 계정 이메일</th>
        <td class="p-3 border-b border-gray-100"><code><?php echo h(isset($config['service_account_email']) ? $config['service_account_email'] : ''); ?></code></td>
      </tr>
      <tr>
        <th class="text-left p-3 border-b border-gray-100 bg-gray-50">공유드라이브 ID</th>
        <td class="p-3 border-b border-gray-100"><code><?php echo h(cpms_drive_shared_drive_id()); ?></code></td>
      </tr>
      <tr>
        <th class="text-left p-3 border-b border-gray-100 bg-gray-50">공통문서 폴더 ID</th>
        <td class="p-3 border-b border-gray-100"><code><?php echo h(isset($folders['common_documents']) ? $folders['common_documents'] : ''); ?></code></td>
      </tr>
      <tr>
        <th class="text-left p-3 border-b border-gray-100 bg-gray-50"><?php echo h(urldecode('%EC%A0%84%EC%9E%90%EA%B2%B0%EC%9E%AC%20%ED%8F%B4%EB%8D%94%20ID')); ?></th>
        <td class="p-3 border-b border-gray-100"><code><?php echo h(isset($folders['approval']) ? $folders['approval'] : ''); ?></code></td>
      </tr>
      <tr>
        <th class="text-left p-3 bg-gray-50">실패 로그</th>
        <td class="p-3"><code><?php echo h(cpms_drive_log_path()); ?></code></td>
      </tr>
    </table>
  </div>

  <div class="bg-white border border-gray-200 rounded-2xl p-4">
    <div class="flex items-center justify-between gap-3 mb-3">
      <div>
        <div class="font-extrabold text-gray-900">현재 JSON 확인</div>
        <div class="text-xs text-gray-500 mt-1">private_key 내용은 표시하지 않습니다.</div>
      </div>
      <?php echo cpms_admin_drive_check_badge(!empty($jsonInfo['ok'])); ?>
    </div>
    <div class="text-sm text-gray-700"><?php echo h(isset($jsonInfo['message']) ? $jsonInfo['message'] : ''); ?></div>
    <?php if (!empty($jsonInfo['service_account_email'])): ?>
      <div class="text-xs text-gray-500 mt-2">읽은 계정: <code><?php echo h($jsonInfo['service_account_email']); ?></code></div>
    <?php endif; ?>
  </div>

  <form method="post" action="?r=<?php echo urlencode(urldecode('%EA%B4%80%EB%A6%AC')); ?>&tab=drive_check" class="bg-white border border-gray-200 rounded-2xl p-4">
    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
    <div class="flex flex-wrap items-end gap-3">
      <label class="block text-sm font-bold text-gray-700" for="public_affairs_project_id">
        <span class="block mb-2">공무 테스트 프로젝트 ID</span>
        <input id="public_affairs_project_id" name="public_affairs_project_id" type="number" min="1" class="w-48 px-3 py-3 rounded-xl border border-gray-300 text-sm" placeholder="미입력 시 첫 프로젝트">
      </label>
      <label class="block text-sm font-bold text-gray-700" for="construction_project_id">
        <span class="block mb-2"><?php echo h(urldecode('%EA%B3%B5%EC%82%AC%20%ED%85%8C%EC%8A%A4%ED%8A%B8%20%ED%94%84%EB%A1%9C%EC%A0%9D%ED%8A%B8%20%49%44')); ?></span>
        <input id="construction_project_id" name="construction_project_id" type="number" min="1" class="w-48 px-3 py-3 rounded-xl border border-gray-300 text-sm" placeholder="미입력 시 첫 프로젝트">
      </label>
      <label class="block text-sm font-bold text-gray-700" for="safety_health_project_id">
        <span class="block mb-2"><?php echo h(urldecode('%EC%95%88%EC%A0%84%EB%B3%B4%EA%B1%B4%20%ED%85%8C%EC%8A%A4%ED%8A%B8%20%ED%94%84%EB%A1%9C%EC%A0%9D%ED%8A%B8%20%49%44')); ?></span>
        <input id="safety_health_project_id" name="safety_health_project_id" type="number" min="1" class="w-48 px-3 py-3 rounded-xl border border-gray-300 text-sm" placeholder="미입력 시 첫 프로젝트">
      </label>
      <label class="block text-sm font-bold text-gray-700" for="management_project_id">
        <span class="block mb-2"><?php echo h(urldecode('%EA%B4%80%EB%A6%AC%20%ED%85%8C%EC%8A%A4%ED%8A%B8%20%ED%94%84%EB%A1%9C%EC%A0%9D%ED%8A%B8%20%49%44')); ?></span>
        <input id="management_project_id" name="management_project_id" type="number" min="1" class="w-48 px-3 py-3 rounded-xl border border-gray-300 text-sm" placeholder="미입력 시 첫 프로젝트">
      </label>
      <label class="block text-sm font-bold text-gray-700" for="quality_project_id">
        <span class="block mb-2"><?php echo h(urldecode('%ED%92%88%EC%A7%88%20%ED%85%8C%EC%8A%A4%ED%8A%B8%20%ED%94%84%EB%A1%9C%EC%A0%9D%ED%8A%B8%20%49%44')); ?></span>
        <input id="quality_project_id" name="quality_project_id" type="number" min="1" class="w-48 px-3 py-3 rounded-xl border border-gray-300 text-sm" placeholder="미입력 시 첫 프로젝트">
      </label>
      <button type="submit" class="px-4 py-3 rounded-xl bg-gray-900 text-white font-extrabold">연결 점검 실행</button>
    </div>
    <div class="text-xs text-gray-500 mt-2">입력한 프로젝트의 01_공무 / 기성 / 현재연도 / 현재월 폴더에서 업로드와 삭제를 점검합니다.</div>
  </form>

  <div class="bg-white border border-gray-200 rounded-2xl p-4">
    <div class="font-extrabold text-gray-900">기존 프로젝트 Drive 보정</div>
    <div class="text-sm text-gray-600 mt-1">운영 중 생성된 기존 프로젝트의 Drive 폴더 ID와 기본 하위 폴더를 동기화합니다.</div>
    <a href="?r=<?php echo urlencode(urldecode('%EA%B4%80%EB%A6%AC')); ?>&tab=project_drive_sync" class="inline-flex mt-3 px-4 py-3 rounded-xl bg-emerald-700 text-white font-extrabold">기존 프로젝트 Drive 동기화 열기</a>
  </div>

  <?php if (is_array($checkResult)): ?>
    <div class="bg-white border border-gray-200 rounded-2xl p-4">
      <div class="font-extrabold text-gray-900 mb-3">점검 결과</div>
      <table class="w-full border-collapse">
        <thead>
          <tr>
            <th class="text-left p-3 border-b border-gray-200 bg-gray-50">항목</th>
            <th class="text-left p-3 border-b border-gray-200 bg-gray-50">결과</th>
            <th class="text-left p-3 border-b border-gray-200 bg-gray-50">메시지</th>
            <th class="text-left p-3 border-b border-gray-200 bg-gray-50">HTTP</th>
          </tr>
        </thead>
        <tbody>
          <?php
            cpms_admin_drive_check_row(urldecode('%4A%53%4F%4E%20%ED%8C%8C%EC%9D%BC%20%EC%9D%BD%EA%B8%B0'), !empty($checkResult['json']['ok']), isset($checkResult['json']['message']) ? $checkResult['json']['message'] : '', 0);
            cpms_admin_drive_check_row(urldecode('%41%63%63%65%73%73%20%54%6F%6B%65%6E%20%EB%B0%9C%EA%B8%89'), !empty($checkResult['token']['ok']), isset($checkResult['token']['message']) ? $checkResult['token']['message'] : '', isset($checkResult['token']['http_code']) ? $checkResult['token']['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%30%33%5F%EA%B3%B5%ED%86%B5%EB%AC%B8%EC%84%9C%20%EC%97%85%EB%A1%9C%EB%93%9C'), !empty($checkResult['upload']['ok']), isset($checkResult['upload']['message']) ? $checkResult['upload']['message'] : '', isset($checkResult['upload']['http_code']) ? $checkResult['upload']['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%ED%85%8C%EC%8A%A4%ED%8A%B8%20%ED%8C%8C%EC%9D%BC%20%EC%82%AD%EC%A0%9C'), !empty($checkResult['delete']['ok']), isset($checkResult['delete']['message']) ? $checkResult['delete']['message'] : '', isset($checkResult['delete']['http_code']) ? $checkResult['delete']['http_code'] : 0);
            $approvalRoot = (isset($checkResult['approval_root']) && is_array($checkResult['approval_root'])) ? $checkResult['approval_root'] : array();
            $approvalYear = (isset($checkResult['approval_year_folder']) && is_array($checkResult['approval_year_folder'])) ? $checkResult['approval_year_folder'] : array();
            $approvalType = (isset($checkResult['approval_type_folder']) && is_array($checkResult['approval_type_folder'])) ? $checkResult['approval_type_folder'] : array();
            $approvalMonth = (isset($checkResult['approval_month_folder']) && is_array($checkResult['approval_month_folder'])) ? $checkResult['approval_month_folder'] : array();
            cpms_admin_drive_check_row(urldecode('%EC%A0%84%EC%9E%90%EA%B2%B0%EC%9E%AC%20%30%31%5F%EC%A0%84%EC%9E%90%EA%B2%B0%EC%9E%AC%20%ED%8F%B4%EB%8D%94'), !empty($approvalRoot['ok']), isset($approvalRoot['message']) ? $approvalRoot['message'] : '', isset($approvalRoot['http_code']) ? $approvalRoot['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EC%A0%84%EC%9E%90%EA%B2%B0%EC%9E%AC%20%ED%98%84%EC%9E%AC%EC%97%B0%EB%8F%84%20%ED%8F%B4%EB%8D%94'), !empty($approvalYear['ok']), isset($approvalYear['message']) ? $approvalYear['message'] : '', isset($approvalYear['http_code']) ? $approvalYear['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EC%A0%84%EC%9E%90%EA%B2%B0%EC%9E%AC%20%EA%B8%B0%ED%83%80%20%ED%8F%B4%EB%8D%94'), !empty($approvalType['ok']), isset($approvalType['message']) ? $approvalType['message'] : '', isset($approvalType['http_code']) ? $approvalType['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EC%A0%84%EC%9E%90%EA%B2%B0%EC%9E%AC%20%ED%98%84%EC%9E%AC%EC%9B%94%20%ED%8F%B4%EB%8D%94'), !empty($approvalMonth['ok']), isset($approvalMonth['message']) ? $approvalMonth['message'] : '', isset($approvalMonth['http_code']) ? $approvalMonth['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EC%A0%84%EC%9E%90%EA%B2%B0%EC%9E%AC%20%ED%85%8C%EC%8A%A4%ED%8A%B8%20%EC%97%85%EB%A1%9C%EB%93%9C'), !empty($checkResult['approval_upload']['ok']), isset($checkResult['approval_upload']['message']) ? $checkResult['approval_upload']['message'] : '', isset($checkResult['approval_upload']['http_code']) ? $checkResult['approval_upload']['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EC%A0%84%EC%9E%90%EA%B2%B0%EC%9E%AC%20%ED%85%8C%EC%8A%A4%ED%8A%B8%20%EC%82%AD%EC%A0%9C'), !empty($checkResult['approval_delete']['ok']), isset($checkResult['approval_delete']['message']) ? $checkResult['approval_delete']['message'] : '', isset($checkResult['approval_delete']['http_code']) ? $checkResult['approval_delete']['http_code'] : 0);
            $pdfCheck = (isset($checkResult['completed_pdf']) && is_array($checkResult['completed_pdf'])) ? $checkResult['completed_pdf'] : array();
            $pdfMpdfFile = (isset($pdfCheck['mpdf_file']) && is_array($pdfCheck['mpdf_file'])) ? $pdfCheck['mpdf_file'] : array();
            $pdfMpdfLoad = (isset($pdfCheck['mpdf_load']) && is_array($pdfCheck['mpdf_load'])) ? $pdfCheck['mpdf_load'] : array();
            $pdfMbstring = (isset($pdfCheck['mbstring']) && is_array($pdfCheck['mbstring'])) ? $pdfCheck['mbstring'] : array();
            $pdfGd = (isset($pdfCheck['gd']) && is_array($pdfCheck['gd'])) ? $pdfCheck['gd'] : array();
            $pdfMpdfTemp = (isset($pdfCheck['mpdf_temp']) && is_array($pdfCheck['mpdf_temp'])) ? $pdfCheck['mpdf_temp'] : array();
            $pdfWkhtml = (isset($pdfCheck['wkhtmltopdf']) && is_array($pdfCheck['wkhtmltopdf'])) ? $pdfCheck['wkhtmltopdf'] : array();
            $pdfCreate = (isset($pdfCheck['create']) && is_array($pdfCheck['create'])) ? $pdfCheck['create'] : array();
            $pdfSize = (isset($pdfCheck['validate_size']) && is_array($pdfCheck['validate_size'])) ? $pdfCheck['validate_size'] : array();
            $pdfHeader = (isset($pdfCheck['validate_header']) && is_array($pdfCheck['validate_header'])) ? $pdfCheck['validate_header'] : array();
            $pdfFolder = (isset($pdfCheck['approval_folder']) && is_array($pdfCheck['approval_folder'])) ? $pdfCheck['approval_folder'] : array();
            $pdfYearFolder = (isset($pdfCheck['approval_year_folder']) && is_array($pdfCheck['approval_year_folder'])) ? $pdfCheck['approval_year_folder'] : array();
            $pdfTypeFolder = (isset($pdfCheck['approval_type_folder']) && is_array($pdfCheck['approval_type_folder'])) ? $pdfCheck['approval_type_folder'] : array();
            $pdfMonthFolder = (isset($pdfCheck['approval_month_folder']) && is_array($pdfCheck['approval_month_folder'])) ? $pdfCheck['approval_month_folder'] : array();
            $pdfUpload = (isset($pdfCheck['upload']) && is_array($pdfCheck['upload'])) ? $pdfCheck['upload'] : array();
            $pdfDelete = (isset($pdfCheck['delete']) && is_array($pdfCheck['delete'])) ? $pdfCheck['delete'] : array();
            $pdfCleanup = (isset($pdfCheck['cleanup']) && is_array($pdfCheck['cleanup'])) ? $pdfCheck['cleanup'] : array();
            cpms_admin_drive_check_row(urldecode('%6D%50%44%46%20%ED%95%B5%EC%8B%AC%20%ED%8C%8C%EC%9D%BC'), !empty($pdfMpdfFile['ok']), (isset($pdfMpdfFile['message']) ? $pdfMpdfFile['message'] : '') . (isset($pdfMpdfFile['path']) && trim((string)$pdfMpdfFile['path']) !== '' ? ' / ' . cpms_drive_mask_path($pdfMpdfFile['path']) : ''), 0);
            cpms_admin_drive_check_row(urldecode('%6D%50%44%46%20%ED%81%B4%EB%9E%98%EC%8A%A4%20%EB%A1%9C%EB%94%A9'), !empty($pdfMpdfLoad['ok']), isset($pdfMpdfLoad['message']) ? $pdfMpdfLoad['message'] : '', 0);
            cpms_admin_drive_check_row(urldecode('%6D%62%73%74%72%69%6E%67%20%ED%99%95%EC%9E%A5'), !empty($pdfMbstring['ok']), isset($pdfMbstring['message']) ? $pdfMbstring['message'] : '', 0);
            cpms_admin_drive_check_row(urldecode('%67%64%20%ED%99%95%EC%9E%A5'), !empty($pdfGd['ok']), isset($pdfGd['message']) ? $pdfGd['message'] : '', 0);
            cpms_admin_drive_check_row(urldecode('%6D%50%44%46%20%EC%9E%84%EC%8B%9C%20%ED%8F%B4%EB%8D%94'), !empty($pdfMpdfTemp['ok']), (isset($pdfMpdfTemp['message']) ? $pdfMpdfTemp['message'] : '') . (isset($pdfMpdfTemp['path']) && trim((string)$pdfMpdfTemp['path']) !== '' ? ' / ' . cpms_drive_mask_path($pdfMpdfTemp['path']) : ''), 0);
            cpms_admin_drive_check_row(urldecode('%77%6B%68%74%6D%6C%74%6F%70%64%66%20%EB%B3%B4%EC%A1%B0%20%ED%9B%84%EB%B3%B4'), !empty($pdfWkhtml['ok']), (isset($pdfWkhtml['message']) ? $pdfWkhtml['message'] : '') . (isset($pdfWkhtml['path']) && trim((string)$pdfWkhtml['path']) !== '' ? ' / ' . $pdfWkhtml['path'] : ''), 0);
            cpms_admin_drive_check_row(urldecode('%ED%85%8C%EC%8A%A4%ED%8A%B8%20%48%54%4D%4C%EC%9D%84%20%50%44%46%EB%A1%9C%20%EC%83%9D%EC%84%B1'), !empty($pdfCreate['ok']), isset($pdfCreate['message']) ? $pdfCreate['message'] : '', 0);
            cpms_admin_drive_check_row(urldecode('%EC%83%9D%EC%84%B1%EB%90%9C%20%50%44%46%20%ED%81%AC%EA%B8%B0'), !empty($pdfSize['ok']), isset($pdfSize['message']) ? $pdfSize['message'] : '', 0);
            cpms_admin_drive_check_row(urldecode('%EC%83%9D%EC%84%B1%EB%90%9C%20%50%44%46%20%ED%97%A4%EB%8D%94'), !empty($pdfHeader['ok']), isset($pdfHeader['message']) ? $pdfHeader['message'] : '', 0);
            cpms_admin_drive_check_row(urldecode('%EC%99%84%EB%A3%8C%EB%AC%B8%EC%84%9C%20%ED%98%84%EC%9E%AC%EC%97%B0%EB%8F%84%20%ED%8F%B4%EB%8D%94'), !empty($pdfYearFolder['ok']), isset($pdfYearFolder['message']) ? $pdfYearFolder['message'] : '', isset($pdfYearFolder['http_code']) ? $pdfYearFolder['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EC%99%84%EB%A3%8C%EB%AC%B8%EC%84%9C%20%EB%AC%B8%EC%84%9C%EC%A2%85%EB%A5%98%20%ED%8F%B4%EB%8D%94'), !empty($pdfTypeFolder['ok']), isset($pdfTypeFolder['message']) ? $pdfTypeFolder['message'] : '', isset($pdfTypeFolder['http_code']) ? $pdfTypeFolder['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EC%99%84%EB%A3%8C%EB%AC%B8%EC%84%9C%20%ED%98%84%EC%9E%AC%EC%9B%94%20%ED%8F%B4%EB%8D%94'), !empty($pdfMonthFolder['ok']), isset($pdfMonthFolder['message']) ? $pdfMonthFolder['message'] : '', isset($pdfMonthFolder['http_code']) ? $pdfMonthFolder['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EC%99%84%EB%A3%8C%EB%AC%B8%EC%84%9C%20%ED%8F%B4%EB%8D%94%20%ED%99%95%EC%9D%B8'), !empty($pdfFolder['ok']), isset($pdfFolder['message']) ? $pdfFolder['message'] : '', isset($pdfFolder['http_code']) ? $pdfFolder['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%ED%85%8C%EC%8A%A4%ED%8A%B8%20%50%44%46%20%44%72%69%76%65%20%EC%97%85%EB%A1%9C%EB%93%9C'), !empty($pdfUpload['ok']), isset($pdfUpload['message']) ? $pdfUpload['message'] : '', isset($pdfUpload['http_code']) ? $pdfUpload['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%ED%85%8C%EC%8A%A4%ED%8A%B8%20%50%44%46%20%44%72%69%76%65%20%EC%82%AD%EC%A0%9C'), !empty($pdfDelete['ok']), isset($pdfDelete['message']) ? $pdfDelete['message'] : '', isset($pdfDelete['http_code']) ? $pdfDelete['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%ED%85%8C%EC%8A%A4%ED%8A%B8%20%EC%9E%84%EC%8B%9C%20%50%44%46%20%EC%82%AD%EC%A0%9C'), !empty($pdfCleanup['ok']), isset($pdfCleanup['message']) ? $pdfCleanup['message'] : '', 0);
            $publicAffairsCheck = (isset($checkResult['public_affairs']) && is_array($checkResult['public_affairs'])) ? $checkResult['public_affairs'] : array();
            $paProject = (isset($publicAffairsCheck['project']) && is_array($publicAffairsCheck['project'])) ? $publicAffairsCheck['project'] : array();
            $paRoot = (isset($publicAffairsCheck['public_affairs_folder']) && is_array($publicAffairsCheck['public_affairs_folder'])) ? $publicAffairsCheck['public_affairs_folder'] : array();
            $paProgress = (isset($publicAffairsCheck['progress_folder']) && is_array($publicAffairsCheck['progress_folder'])) ? $publicAffairsCheck['progress_folder'] : array();
            $paYear = (isset($publicAffairsCheck['year_folder']) && is_array($publicAffairsCheck['year_folder'])) ? $publicAffairsCheck['year_folder'] : array();
            $paMonth = (isset($publicAffairsCheck['month_folder']) && is_array($publicAffairsCheck['month_folder'])) ? $publicAffairsCheck['month_folder'] : array();
            $paUpload = (isset($publicAffairsCheck['upload']) && is_array($publicAffairsCheck['upload'])) ? $publicAffairsCheck['upload'] : array();
            $paDelete = (isset($publicAffairsCheck['delete']) && is_array($publicAffairsCheck['delete'])) ? $publicAffairsCheck['delete'] : array();
            $paCleanup = (isset($publicAffairsCheck['cleanup']) && is_array($publicAffairsCheck['cleanup'])) ? $publicAffairsCheck['cleanup'] : array();
            cpms_admin_drive_check_row(urldecode('%EA%B3%B5%EB%AC%B4%20%EC%A0%90%EA%B2%80%20%ED%94%84%EB%A1%9C%EC%A0%9D%ED%8A%B8'), !empty($paProject['ok']), isset($paProject['message']) ? $paProject['message'] : '', 0);
            cpms_admin_drive_check_row(urldecode('%EA%B3%B5%EB%AC%B4%20%30%31%5F%EA%B3%B5%EB%AC%B4%20%ED%8F%B4%EB%8D%94'), !empty($paRoot['ok']), isset($paRoot['message']) ? $paRoot['message'] : '', isset($paRoot['http_code']) ? $paRoot['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EA%B3%B5%EB%AC%B4%20%EA%B8%B0%EC%84%B1%20%ED%8F%B4%EB%8D%94'), !empty($paProgress['ok']), isset($paProgress['message']) ? $paProgress['message'] : '', isset($paProgress['http_code']) ? $paProgress['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EA%B3%B5%EB%AC%B4%20%ED%98%84%EC%9E%AC%EC%97%B0%EB%8F%84%20%ED%8F%B4%EB%8D%94'), !empty($paYear['ok']), isset($paYear['message']) ? $paYear['message'] : '', isset($paYear['http_code']) ? $paYear['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EA%B3%B5%EB%AC%B4%20%ED%98%84%EC%9E%AC%EC%9B%94%20%ED%8F%B4%EB%8D%94'), !empty($paMonth['ok']), isset($paMonth['message']) ? $paMonth['message'] : '', isset($paMonth['http_code']) ? $paMonth['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EA%B3%B5%EB%AC%B4%20%EA%B8%B0%EC%84%B1%20%ED%85%8C%EC%8A%A4%ED%8A%B8%20%EC%97%85%EB%A1%9C%EB%93%9C'), !empty($paUpload['ok']), isset($paUpload['message']) ? $paUpload['message'] : '', isset($paUpload['http_code']) ? $paUpload['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EA%B3%B5%EB%AC%B4%20%EA%B8%B0%EC%84%B1%20%ED%85%8C%EC%8A%A4%ED%8A%B8%20%EC%82%AD%EC%A0%9C'), !empty($paDelete['ok']), isset($paDelete['message']) ? $paDelete['message'] : '', isset($paDelete['http_code']) ? $paDelete['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EA%B3%B5%EB%AC%B4%20%ED%85%8C%EC%8A%A4%ED%8A%B8%20%EC%9E%84%EC%8B%9C%ED%8C%8C%EC%9D%BC%20%EC%82%AD%EC%A0%9C'), !empty($paCleanup['ok']), isset($paCleanup['message']) ? $paCleanup['message'] : '', 0);
            $constructionCheck = (isset($checkResult['construction']) && is_array($checkResult['construction'])) ? $checkResult['construction'] : array();
            $coProject = (isset($constructionCheck['project']) && is_array($constructionCheck['project'])) ? $constructionCheck['project'] : array();
            $coRoot = (isset($constructionCheck['construction_folder']) && is_array($constructionCheck['construction_folder'])) ? $constructionCheck['construction_folder'] : array();
            $coMaterial = (isset($constructionCheck['material_folder']) && is_array($constructionCheck['material_folder'])) ? $constructionCheck['material_folder'] : array();
            $coEquipment = (isset($constructionCheck['equipment_folder']) && is_array($constructionCheck['equipment_folder'])) ? $constructionCheck['equipment_folder'] : array();
            $coDaily = (isset($constructionCheck['daily_report_folder']) && is_array($constructionCheck['daily_report_folder'])) ? $constructionCheck['daily_report_folder'] : array();
            $coPhoto = (isset($constructionCheck['photo_folder']) && is_array($constructionCheck['photo_folder'])) ? $constructionCheck['photo_folder'] : array();
            $coStatus = (isset($constructionCheck['status_folder']) && is_array($constructionCheck['status_folder'])) ? $constructionCheck['status_folder'] : array();
            $coLabor = (isset($constructionCheck['labor_folder']) && is_array($constructionCheck['labor_folder'])) ? $constructionCheck['labor_folder'] : array();
            $coYear = (isset($constructionCheck['year_folder']) && is_array($constructionCheck['year_folder'])) ? $constructionCheck['year_folder'] : array();
            $coMonth = (isset($constructionCheck['month_folder']) && is_array($constructionCheck['month_folder'])) ? $constructionCheck['month_folder'] : array();
            $coUpload = (isset($constructionCheck['upload']) && is_array($constructionCheck['upload'])) ? $constructionCheck['upload'] : array();
            $coDelete = (isset($constructionCheck['delete']) && is_array($constructionCheck['delete'])) ? $constructionCheck['delete'] : array();
            $coCleanup = (isset($constructionCheck['cleanup']) && is_array($constructionCheck['cleanup'])) ? $constructionCheck['cleanup'] : array();
            cpms_admin_drive_check_row(urldecode('%EA%B3%B5%EC%82%AC%20%EC%A0%90%EA%B2%80%20%ED%94%84%EB%A1%9C%EC%A0%9D%ED%8A%B8'), !empty($coProject['ok']), isset($coProject['message']) ? $coProject['message'] : '', 0);
            cpms_admin_drive_check_row(urldecode('%EA%B3%B5%EC%82%AC%20%30%33%5F%EA%B3%B5%EC%82%AC%20%ED%8F%B4%EB%8D%94'), !empty($coRoot['ok']), isset($coRoot['message']) ? $coRoot['message'] : '', isset($coRoot['http_code']) ? $coRoot['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EA%B3%B5%EC%82%AC%20') . cpms_construction_drive_label('material') . urldecode('%20%ED%8F%B4%EB%8D%94'), !empty($coMaterial['ok']), isset($coMaterial['message']) ? $coMaterial['message'] : '', isset($coMaterial['http_code']) ? $coMaterial['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EA%B3%B5%EC%82%AC%20') . cpms_construction_drive_label('equipment') . urldecode('%20%ED%8F%B4%EB%8D%94'), !empty($coEquipment['ok']), isset($coEquipment['message']) ? $coEquipment['message'] : '', isset($coEquipment['http_code']) ? $coEquipment['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EA%B3%B5%EC%82%AC%20') . cpms_construction_drive_label('daily_report') . urldecode('%20%ED%8F%B4%EB%8D%94'), !empty($coDaily['ok']), isset($coDaily['message']) ? $coDaily['message'] : '', isset($coDaily['http_code']) ? $coDaily['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EA%B3%B5%EC%82%AC%20') . cpms_construction_drive_label('photo') . urldecode('%20%ED%8F%B4%EB%8D%94'), !empty($coPhoto['ok']), isset($coPhoto['message']) ? $coPhoto['message'] : '', isset($coPhoto['http_code']) ? $coPhoto['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EA%B3%B5%EC%82%AC%20') . cpms_construction_drive_label('status') . urldecode('%20%ED%8F%B4%EB%8D%94'), !empty($coStatus['ok']), isset($coStatus['message']) ? $coStatus['message'] : '', isset($coStatus['http_code']) ? $coStatus['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EA%B3%B5%EC%82%AC%20') . cpms_construction_drive_label('labor') . urldecode('%20%ED%8F%B4%EB%8D%94'), !empty($coLabor['ok']), isset($coLabor['message']) ? $coLabor['message'] : '', isset($coLabor['http_code']) ? $coLabor['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EA%B3%B5%EC%82%AC%20%ED%98%84%EC%9E%AC%EC%97%B0%EB%8F%84%20%ED%8F%B4%EB%8D%94'), !empty($coYear['ok']), isset($coYear['message']) ? $coYear['message'] : '', isset($coYear['http_code']) ? $coYear['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EA%B3%B5%EC%82%AC%20%ED%98%84%EC%9E%AC%EC%9B%94%20%ED%8F%B4%EB%8D%94'), !empty($coMonth['ok']), isset($coMonth['message']) ? $coMonth['message'] : '', isset($coMonth['http_code']) ? $coMonth['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EA%B3%B5%EC%82%AC%20%ED%85%8C%EC%8A%A4%ED%8A%B8%20%EC%97%85%EB%A1%9C%EB%93%9C'), !empty($coUpload['ok']), isset($coUpload['message']) ? $coUpload['message'] : '', isset($coUpload['http_code']) ? $coUpload['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EA%B3%B5%EC%82%AC%20%ED%85%8C%EC%8A%A4%ED%8A%B8%20%EC%82%AD%EC%A0%9C'), !empty($coDelete['ok']), isset($coDelete['message']) ? $coDelete['message'] : '', isset($coDelete['http_code']) ? $coDelete['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EA%B3%B5%EC%82%AC%20%ED%85%8C%EC%8A%A4%ED%8A%B8%20%EC%9E%84%EC%8B%9C%ED%8C%8C%EC%9D%BC%20%EC%82%AD%EC%A0%9C'), !empty($coCleanup['ok']), isset($coCleanup['message']) ? $coCleanup['message'] : '', 0);
            $safetyHealthCheck = (isset($checkResult['safety_health']) && is_array($checkResult['safety_health'])) ? $checkResult['safety_health'] : array();
            $shProject = (isset($safetyHealthCheck['project']) && is_array($safetyHealthCheck['project'])) ? $safetyHealthCheck['project'] : array();
            $shRoot = (isset($safetyHealthCheck['safety_health_folder']) && is_array($safetyHealthCheck['safety_health_folder'])) ? $safetyHealthCheck['safety_health_folder'] : array();
            $shSafetyCost = (isset($safetyHealthCheck['safety_cost_folder']) && is_array($safetyHealthCheck['safety_cost_folder'])) ? $safetyHealthCheck['safety_cost_folder'] : array();
            $shAccident = (isset($safetyHealthCheck['accident_folder']) && is_array($safetyHealthCheck['accident_folder'])) ? $safetyHealthCheck['accident_folder'] : array();
            $shSamsung = (isset($safetyHealthCheck['samsung_portal_folder']) && is_array($safetyHealthCheck['samsung_portal_folder'])) ? $safetyHealthCheck['samsung_portal_folder'] : array();
            $shPpe = (isset($safetyHealthCheck['ppe_folder']) && is_array($safetyHealthCheck['ppe_folder'])) ? $safetyHealthCheck['ppe_folder'] : array();
            $shEducation = (isset($safetyHealthCheck['education_folder']) && is_array($safetyHealthCheck['education_folder'])) ? $safetyHealthCheck['education_folder'] : array();
            $shMedical = (isset($safetyHealthCheck['medical_checkup_folder']) && is_array($safetyHealthCheck['medical_checkup_folder'])) ? $safetyHealthCheck['medical_checkup_folder'] : array();
            $shYear = (isset($safetyHealthCheck['year_folder']) && is_array($safetyHealthCheck['year_folder'])) ? $safetyHealthCheck['year_folder'] : array();
            $shMonth = (isset($safetyHealthCheck['month_folder']) && is_array($safetyHealthCheck['month_folder'])) ? $safetyHealthCheck['month_folder'] : array();
            $shUpload = (isset($safetyHealthCheck['upload']) && is_array($safetyHealthCheck['upload'])) ? $safetyHealthCheck['upload'] : array();
            $shDelete = (isset($safetyHealthCheck['delete']) && is_array($safetyHealthCheck['delete'])) ? $safetyHealthCheck['delete'] : array();
            $shCommonRoot = (isset($safetyHealthCheck['common_safety_health_folder']) && is_array($safetyHealthCheck['common_safety_health_folder'])) ? $safetyHealthCheck['common_safety_health_folder'] : array();
            $shCommonSamsung = (isset($safetyHealthCheck['common_samsung_portal_folder']) && is_array($safetyHealthCheck['common_samsung_portal_folder'])) ? $safetyHealthCheck['common_samsung_portal_folder'] : array();
            $shCommonYear = (isset($safetyHealthCheck['common_year_folder']) && is_array($safetyHealthCheck['common_year_folder'])) ? $safetyHealthCheck['common_year_folder'] : array();
            $shCommonMonth = (isset($safetyHealthCheck['common_month_folder']) && is_array($safetyHealthCheck['common_month_folder'])) ? $safetyHealthCheck['common_month_folder'] : array();
            $shCommonUpload = (isset($safetyHealthCheck['common_upload']) && is_array($safetyHealthCheck['common_upload'])) ? $safetyHealthCheck['common_upload'] : array();
            $shCommonDelete = (isset($safetyHealthCheck['common_delete']) && is_array($safetyHealthCheck['common_delete'])) ? $safetyHealthCheck['common_delete'] : array();
            $shCleanup = (isset($safetyHealthCheck['cleanup']) && is_array($safetyHealthCheck['cleanup'])) ? $safetyHealthCheck['cleanup'] : array();
            cpms_admin_drive_check_row(urldecode('%EC%95%88%EC%A0%84%EB%B3%B4%EA%B1%B4%20%EC%A0%90%EA%B2%80%20%ED%94%84%EB%A1%9C%EC%A0%9D%ED%8A%B8'), !empty($shProject['ok']), isset($shProject['message']) ? $shProject['message'] : '', 0);
            cpms_admin_drive_check_row(urldecode('%EC%95%88%EC%A0%84%EB%B3%B4%EA%B1%B4%20%30%34%5F%EC%95%88%EC%A0%84%EB%B3%B4%EA%B1%B4%20%ED%8F%B4%EB%8D%94'), !empty($shRoot['ok']), isset($shRoot['message']) ? $shRoot['message'] : '', isset($shRoot['http_code']) ? $shRoot['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EC%95%88%EC%A0%84%EB%B3%B4%EA%B1%B4%20') . cpms_safety_health_drive_label('safety_cost') . urldecode('%20%ED%8F%B4%EB%8D%94'), !empty($shSafetyCost['ok']), isset($shSafetyCost['message']) ? $shSafetyCost['message'] : '', isset($shSafetyCost['http_code']) ? $shSafetyCost['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EC%95%88%EC%A0%84%EB%B3%B4%EA%B1%B4%20') . cpms_safety_health_drive_label('accident') . urldecode('%20%ED%8F%B4%EB%8D%94'), !empty($shAccident['ok']), isset($shAccident['message']) ? $shAccident['message'] : '', isset($shAccident['http_code']) ? $shAccident['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EC%95%88%EC%A0%84%EB%B3%B4%EA%B1%B4%20') . cpms_safety_health_drive_label('samsung_portal') . urldecode('%20%ED%8F%B4%EB%8D%94'), !empty($shSamsung['ok']), isset($shSamsung['message']) ? $shSamsung['message'] : '', isset($shSamsung['http_code']) ? $shSamsung['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EC%95%88%EC%A0%84%EB%B3%B4%EA%B1%B4%20') . cpms_safety_health_drive_label('ppe') . urldecode('%20%ED%8F%B4%EB%8D%94'), !empty($shPpe['ok']), isset($shPpe['message']) ? $shPpe['message'] : '', isset($shPpe['http_code']) ? $shPpe['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EC%95%88%EC%A0%84%EB%B3%B4%EA%B1%B4%20') . cpms_safety_health_drive_label('education') . urldecode('%20%ED%8F%B4%EB%8D%94'), !empty($shEducation['ok']), isset($shEducation['message']) ? $shEducation['message'] : '', isset($shEducation['http_code']) ? $shEducation['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EC%95%88%EC%A0%84%EB%B3%B4%EA%B1%B4%20') . cpms_safety_health_drive_label('medical_checkup') . urldecode('%20%ED%8F%B4%EB%8D%94'), !empty($shMedical['ok']), isset($shMedical['message']) ? $shMedical['message'] : '', isset($shMedical['http_code']) ? $shMedical['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EC%95%88%EC%A0%84%EB%B3%B4%EA%B1%B4%20%ED%98%84%EC%9E%AC%EC%97%B0%EB%8F%84%20%ED%8F%B4%EB%8D%94'), !empty($shYear['ok']), isset($shYear['message']) ? $shYear['message'] : '', isset($shYear['http_code']) ? $shYear['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EC%95%88%EC%A0%84%EB%B3%B4%EA%B1%B4%20%ED%98%84%EC%9E%AC%EC%9B%94%20%ED%8F%B4%EB%8D%94'), !empty($shMonth['ok']), isset($shMonth['message']) ? $shMonth['message'] : '', isset($shMonth['http_code']) ? $shMonth['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EC%95%88%EC%A0%84%EB%B3%B4%EA%B1%B4%20%ED%85%8C%EC%8A%A4%ED%8A%B8%20%EC%97%85%EB%A1%9C%EB%93%9C'), !empty($shUpload['ok']), isset($shUpload['message']) ? $shUpload['message'] : '', isset($shUpload['http_code']) ? $shUpload['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EC%95%88%EC%A0%84%EB%B3%B4%EA%B1%B4%20%ED%85%8C%EC%8A%A4%ED%8A%B8%20%EC%82%AD%EC%A0%9C'), !empty($shDelete['ok']), isset($shDelete['message']) ? $shDelete['message'] : '', isset($shDelete['http_code']) ? $shDelete['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EA%B3%B5%ED%86%B5%EB%AC%B8%EC%84%9C%20%EC%95%88%EC%A0%84%EB%B3%B4%EA%B1%B4%20%ED%8F%B4%EB%8D%94'), !empty($shCommonRoot['ok']), isset($shCommonRoot['message']) ? $shCommonRoot['message'] : '', isset($shCommonRoot['http_code']) ? $shCommonRoot['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EA%B3%B5%ED%86%B5%EB%AC%B8%EC%84%9C%20%EC%95%88%EC%A0%84%EB%B3%B4%EA%B1%B4%2F') . cpms_safety_health_drive_label('samsung_portal') . urldecode('%20%ED%8F%B4%EB%8D%94'), !empty($shCommonSamsung['ok']), isset($shCommonSamsung['message']) ? $shCommonSamsung['message'] : '', isset($shCommonSamsung['http_code']) ? $shCommonSamsung['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EA%B3%B5%ED%86%B5%EB%AC%B8%EC%84%9C%20%EC%95%88%EC%A0%84%EB%B3%B4%EA%B1%B4%20%ED%98%84%EC%9E%AC%EC%97%B0%EB%8F%84%20%ED%8F%B4%EB%8D%94'), !empty($shCommonYear['ok']), isset($shCommonYear['message']) ? $shCommonYear['message'] : '', isset($shCommonYear['http_code']) ? $shCommonYear['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EA%B3%B5%ED%86%B5%EB%AC%B8%EC%84%9C%20%EC%95%88%EC%A0%84%EB%B3%B4%EA%B1%B4%20%ED%98%84%EC%9E%AC%EC%9B%94%20%ED%8F%B4%EB%8D%94'), !empty($shCommonMonth['ok']), isset($shCommonMonth['message']) ? $shCommonMonth['message'] : '', isset($shCommonMonth['http_code']) ? $shCommonMonth['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EA%B3%B5%ED%86%B5%20%EC%95%88%EC%A0%84%EB%B3%B4%EA%B1%B4%20%ED%85%8C%EC%8A%A4%ED%8A%B8%20%EC%97%85%EB%A1%9C%EB%93%9C'), !empty($shCommonUpload['ok']), isset($shCommonUpload['message']) ? $shCommonUpload['message'] : '', isset($shCommonUpload['http_code']) ? $shCommonUpload['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EA%B3%B5%ED%86%B5%20%EC%95%88%EC%A0%84%EB%B3%B4%EA%B1%B4%20%ED%85%8C%EC%8A%A4%ED%8A%B8%20%EC%82%AD%EC%A0%9C'), !empty($shCommonDelete['ok']), isset($shCommonDelete['message']) ? $shCommonDelete['message'] : '', isset($shCommonDelete['http_code']) ? $shCommonDelete['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EC%95%88%EC%A0%84%EB%B3%B4%EA%B1%B4%20%ED%85%8C%EC%8A%A4%ED%8A%B8%20%EC%9E%84%EC%8B%9C%ED%8C%8C%EC%9D%BC%20%EC%82%AD%EC%A0%9C'), !empty($shCleanup['ok']), isset($shCleanup['message']) ? $shCleanup['message'] : '', 0);
            $qualityCheck = (isset($checkResult['quality']) && is_array($checkResult['quality'])) ? $checkResult['quality'] : array();
            $qaProject = (isset($qualityCheck['project']) && is_array($qualityCheck['project'])) ? $qualityCheck['project'] : array();
            $qaRoot = (isset($qualityCheck['quality_folder']) && is_array($qualityCheck['quality_folder'])) ? $qualityCheck['quality_folder'] : array();
            $qaMaterial = (isset($qualityCheck['material_approval_folder']) && is_array($qualityCheck['material_approval_folder'])) ? $qualityCheck['material_approval_folder'] : array();
            $qaInspection = (isset($qualityCheck['inspection_folder']) && is_array($qualityCheck['inspection_folder'])) ? $qualityCheck['inspection_folder'] : array();
            $qaTestReport = (isset($qualityCheck['test_report_folder']) && is_array($qualityCheck['test_report_folder'])) ? $qualityCheck['test_report_folder'] : array();
            $qaCqi = (isset($qualityCheck['cqi_folder']) && is_array($qualityCheck['cqi_folder'])) ? $qualityCheck['cqi_folder'] : array();
            $qaSubmission = (isset($qualityCheck['submission_folder']) && is_array($qualityCheck['submission_folder'])) ? $qualityCheck['submission_folder'] : array();
            $qaYear = (isset($qualityCheck['year_folder']) && is_array($qualityCheck['year_folder'])) ? $qualityCheck['year_folder'] : array();
            $qaMonth = (isset($qualityCheck['month_folder']) && is_array($qualityCheck['month_folder'])) ? $qualityCheck['month_folder'] : array();
            $qaUpload = (isset($qualityCheck['upload']) && is_array($qualityCheck['upload'])) ? $qualityCheck['upload'] : array();
            $qaDelete = (isset($qualityCheck['delete']) && is_array($qualityCheck['delete'])) ? $qualityCheck['delete'] : array();
            $qaCommonRoot = (isset($qualityCheck['common_quality_folder']) && is_array($qualityCheck['common_quality_folder'])) ? $qualityCheck['common_quality_folder'] : array();
            $qaCommonSubmission = (isset($qualityCheck['common_submission_folder']) && is_array($qualityCheck['common_submission_folder'])) ? $qualityCheck['common_submission_folder'] : array();
            $qaCommonYear = (isset($qualityCheck['common_year_folder']) && is_array($qualityCheck['common_year_folder'])) ? $qualityCheck['common_year_folder'] : array();
            $qaCommonMonth = (isset($qualityCheck['common_month_folder']) && is_array($qualityCheck['common_month_folder'])) ? $qualityCheck['common_month_folder'] : array();
            $qaCommonUpload = (isset($qualityCheck['common_upload']) && is_array($qualityCheck['common_upload'])) ? $qualityCheck['common_upload'] : array();
            $qaCommonDelete = (isset($qualityCheck['common_delete']) && is_array($qualityCheck['common_delete'])) ? $qualityCheck['common_delete'] : array();
            $qaCleanup = (isset($qualityCheck['cleanup']) && is_array($qualityCheck['cleanup'])) ? $qualityCheck['cleanup'] : array();
            cpms_admin_drive_check_row(urldecode('%ED%92%88%EC%A7%88%20%EC%A0%90%EA%B2%80%20%ED%94%84%EB%A1%9C%EC%A0%9D%ED%8A%B8'), !empty($qaProject['ok']), isset($qaProject['message']) ? $qaProject['message'] : '', 0);
            cpms_admin_drive_check_row(urldecode('%ED%92%88%EC%A7%88%20%30%35%5F%ED%92%88%EC%A7%88%20%ED%8F%B4%EB%8D%94'), !empty($qaRoot['ok']), isset($qaRoot['message']) ? $qaRoot['message'] : '', isset($qaRoot['http_code']) ? $qaRoot['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%ED%92%88%EC%A7%88%20') . cpms_quality_drive_label('material_approval') . urldecode('%20%ED%8F%B4%EB%8D%94'), !empty($qaMaterial['ok']), isset($qaMaterial['message']) ? $qaMaterial['message'] : '', isset($qaMaterial['http_code']) ? $qaMaterial['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%ED%92%88%EC%A7%88%20') . cpms_quality_drive_label('inspection') . urldecode('%20%ED%8F%B4%EB%8D%94'), !empty($qaInspection['ok']), isset($qaInspection['message']) ? $qaInspection['message'] : '', isset($qaInspection['http_code']) ? $qaInspection['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%ED%92%88%EC%A7%88%20') . cpms_quality_drive_label('test_report') . urldecode('%20%ED%8F%B4%EB%8D%94'), !empty($qaTestReport['ok']), isset($qaTestReport['message']) ? $qaTestReport['message'] : '', isset($qaTestReport['http_code']) ? $qaTestReport['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%ED%92%88%EC%A7%88%20') . cpms_quality_drive_label('cqi') . urldecode('%20%ED%8F%B4%EB%8D%94'), !empty($qaCqi['ok']), isset($qaCqi['message']) ? $qaCqi['message'] : '', isset($qaCqi['http_code']) ? $qaCqi['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%ED%92%88%EC%A7%88%20') . cpms_quality_drive_label('submission') . urldecode('%20%ED%8F%B4%EB%8D%94'), !empty($qaSubmission['ok']), isset($qaSubmission['message']) ? $qaSubmission['message'] : '', isset($qaSubmission['http_code']) ? $qaSubmission['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%ED%92%88%EC%A7%88%20%ED%98%84%EC%9E%AC%EC%97%B0%EB%8F%84%20%ED%8F%B4%EB%8D%94'), !empty($qaYear['ok']), isset($qaYear['message']) ? $qaYear['message'] : '', isset($qaYear['http_code']) ? $qaYear['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%ED%92%88%EC%A7%88%20%ED%98%84%EC%9E%AC%EC%9B%94%20%ED%8F%B4%EB%8D%94'), !empty($qaMonth['ok']), isset($qaMonth['message']) ? $qaMonth['message'] : '', isset($qaMonth['http_code']) ? $qaMonth['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%ED%92%88%EC%A7%88%20%ED%85%8C%EC%8A%A4%ED%8A%B8%20%EC%97%85%EB%A1%9C%EB%93%9C'), !empty($qaUpload['ok']), isset($qaUpload['message']) ? $qaUpload['message'] : '', isset($qaUpload['http_code']) ? $qaUpload['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%ED%92%88%EC%A7%88%20%ED%85%8C%EC%8A%A4%ED%8A%B8%20%EC%82%AD%EC%A0%9C'), !empty($qaDelete['ok']), isset($qaDelete['message']) ? $qaDelete['message'] : '', isset($qaDelete['http_code']) ? $qaDelete['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EA%B3%B5%ED%86%B5%EB%AC%B8%EC%84%9C%20%ED%92%88%EC%A7%88%20%ED%8F%B4%EB%8D%94'), !empty($qaCommonRoot['ok']), isset($qaCommonRoot['message']) ? $qaCommonRoot['message'] : '', isset($qaCommonRoot['http_code']) ? $qaCommonRoot['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EA%B3%B5%ED%86%B5%EB%AC%B8%EC%84%9C%20%ED%92%88%EC%A7%88%2F') . cpms_quality_drive_label('submission') . urldecode('%20%ED%8F%B4%EB%8D%94'), !empty($qaCommonSubmission['ok']), isset($qaCommonSubmission['message']) ? $qaCommonSubmission['message'] : '', isset($qaCommonSubmission['http_code']) ? $qaCommonSubmission['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EA%B3%B5%ED%86%B5%EB%AC%B8%EC%84%9C%20%ED%92%88%EC%A7%88%20%ED%98%84%EC%9E%AC%EC%97%B0%EB%8F%84%20%ED%8F%B4%EB%8D%94'), !empty($qaCommonYear['ok']), isset($qaCommonYear['message']) ? $qaCommonYear['message'] : '', isset($qaCommonYear['http_code']) ? $qaCommonYear['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EA%B3%B5%ED%86%B5%EB%AC%B8%EC%84%9C%20%ED%92%88%EC%A7%88%20%ED%98%84%EC%9E%AC%EC%9B%94%20%ED%8F%B4%EB%8D%94'), !empty($qaCommonMonth['ok']), isset($qaCommonMonth['message']) ? $qaCommonMonth['message'] : '', isset($qaCommonMonth['http_code']) ? $qaCommonMonth['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EA%B3%B5%ED%86%B5%20%ED%92%88%EC%A7%88%20%ED%85%8C%EC%8A%A4%ED%8A%B8%20%EC%97%85%EB%A1%9C%EB%93%9C'), !empty($qaCommonUpload['ok']), isset($qaCommonUpload['message']) ? $qaCommonUpload['message'] : '', isset($qaCommonUpload['http_code']) ? $qaCommonUpload['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EA%B3%B5%ED%86%B5%20%ED%92%88%EC%A7%88%20%ED%85%8C%EC%8A%A4%ED%8A%B8%20%EC%82%AD%EC%A0%9C'), !empty($qaCommonDelete['ok']), isset($qaCommonDelete['message']) ? $qaCommonDelete['message'] : '', isset($qaCommonDelete['http_code']) ? $qaCommonDelete['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%ED%92%88%EC%A7%88%20%ED%85%8C%EC%8A%A4%ED%8A%B8%20%EC%9E%84%EC%8B%9C%ED%8C%8C%EC%9D%BC%20%EC%82%AD%EC%A0%9C'), !empty($qaCleanup['ok']), isset($qaCleanup['message']) ? $qaCleanup['message'] : '', 0);
            $managementCheck = (isset($checkResult['management']) && is_array($checkResult['management'])) ? $checkResult['management'] : array();
            $mgProject = (isset($managementCheck['project']) && is_array($managementCheck['project'])) ? $managementCheck['project'] : array();
            $mgRoot = (isset($managementCheck['management_folder']) && is_array($managementCheck['management_folder'])) ? $managementCheck['management_folder'] : array();
            $mgStatement = (isset($managementCheck['statement_folder']) && is_array($managementCheck['statement_folder'])) ? $managementCheck['statement_folder'] : array();
            $mgTax = (isset($managementCheck['tax_invoice_folder']) && is_array($managementCheck['tax_invoice_folder'])) ? $managementCheck['tax_invoice_folder'] : array();
            $mgSettlement = (isset($managementCheck['settlement_folder']) && is_array($managementCheck['settlement_folder'])) ? $managementCheck['settlement_folder'] : array();
            $mgLabor = (isset($managementCheck['labor_folder']) && is_array($managementCheck['labor_folder'])) ? $managementCheck['labor_folder'] : array();
            $mgManpower = (isset($managementCheck['manpower_folder']) && is_array($managementCheck['manpower_folder'])) ? $managementCheck['manpower_folder'] : array();
            $mgYear = (isset($managementCheck['year_folder']) && is_array($managementCheck['year_folder'])) ? $managementCheck['year_folder'] : array();
            $mgMonth = (isset($managementCheck['month_folder']) && is_array($managementCheck['month_folder'])) ? $managementCheck['month_folder'] : array();
            $mgUpload = (isset($managementCheck['upload']) && is_array($managementCheck['upload'])) ? $managementCheck['upload'] : array();
            $mgDelete = (isset($managementCheck['delete']) && is_array($managementCheck['delete'])) ? $managementCheck['delete'] : array();
            $mgCommonRoot = (isset($managementCheck['common_management_folder']) && is_array($managementCheck['common_management_folder'])) ? $managementCheck['common_management_folder'] : array();
            $mgCommonManpower = (isset($managementCheck['common_manpower_folder']) && is_array($managementCheck['common_manpower_folder'])) ? $managementCheck['common_manpower_folder'] : array();
            $mgCommonYear = (isset($managementCheck['common_year_folder']) && is_array($managementCheck['common_year_folder'])) ? $managementCheck['common_year_folder'] : array();
            $mgCommonMonth = (isset($managementCheck['common_month_folder']) && is_array($managementCheck['common_month_folder'])) ? $managementCheck['common_month_folder'] : array();
            $mgCommonUpload = (isset($managementCheck['common_upload']) && is_array($managementCheck['common_upload'])) ? $managementCheck['common_upload'] : array();
            $mgCommonDelete = (isset($managementCheck['common_delete']) && is_array($managementCheck['common_delete'])) ? $managementCheck['common_delete'] : array();
            cpms_admin_drive_check_row(urldecode('%EA%B4%80%EB%A6%AC%20%EC%A0%90%EA%B2%80%20%ED%94%84%EB%A1%9C%EC%A0%9D%ED%8A%B8'), !empty($mgProject['ok']), isset($mgProject['message']) ? $mgProject['message'] : '', 0);
            cpms_admin_drive_check_row(urldecode('%EA%B4%80%EB%A6%AC%20%30%32%5F%EA%B4%80%EB%A6%AC%20%ED%8F%B4%EB%8D%94'), !empty($mgRoot['ok']), isset($mgRoot['message']) ? $mgRoot['message'] : '', isset($mgRoot['http_code']) ? $mgRoot['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EA%B4%80%EB%A6%AC%20%EA%B1%B0%EB%9E%98%EB%AA%85%EC%84%B8%ED%91%9C%20%ED%8F%B4%EB%8D%94'), !empty($mgStatement['ok']), isset($mgStatement['message']) ? $mgStatement['message'] : '', isset($mgStatement['http_code']) ? $mgStatement['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EA%B4%80%EB%A6%AC%20%EC%84%B8%EA%B8%88%EA%B3%84%EC%82%B0%EC%84%9C%20%ED%8F%B4%EB%8D%94'), !empty($mgTax['ok']), isset($mgTax['message']) ? $mgTax['message'] : '', isset($mgTax['http_code']) ? $mgTax['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EA%B4%80%EB%A6%AC%20%EC%A0%95%EC%82%B0%EC%9E%90%EB%A3%8C%20%ED%8F%B4%EB%8D%94'), !empty($mgSettlement['ok']), isset($mgSettlement['message']) ? $mgSettlement['message'] : '', isset($mgSettlement['http_code']) ? $mgSettlement['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EA%B4%80%EB%A6%AC%20%EB%85%B8%EB%AC%B4%EC%9E%90%EB%A3%8C%20%ED%8F%B4%EB%8D%94'), !empty($mgLabor['ok']), isset($mgLabor['message']) ? $mgLabor['message'] : '', isset($mgLabor['http_code']) ? $mgLabor['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EA%B4%80%EB%A6%AC%20%EC%9D%B8%EB%A0%A5%EA%B4%80%EB%A6%AC%20%ED%8F%B4%EB%8D%94'), !empty($mgManpower['ok']), isset($mgManpower['message']) ? $mgManpower['message'] : '', isset($mgManpower['http_code']) ? $mgManpower['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EA%B4%80%EB%A6%AC%20%ED%98%84%EC%9E%AC%EC%97%B0%EB%8F%84%20%ED%8F%B4%EB%8D%94'), !empty($mgYear['ok']), isset($mgYear['message']) ? $mgYear['message'] : '', isset($mgYear['http_code']) ? $mgYear['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EA%B4%80%EB%A6%AC%20%ED%98%84%EC%9E%AC%EC%9B%94%20%ED%8F%B4%EB%8D%94'), !empty($mgMonth['ok']), isset($mgMonth['message']) ? $mgMonth['message'] : '', isset($mgMonth['http_code']) ? $mgMonth['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EA%B4%80%EB%A6%AC%20%ED%85%8C%EC%8A%A4%ED%8A%B8%20%EC%97%85%EB%A1%9C%EB%93%9C'), !empty($mgUpload['ok']), isset($mgUpload['message']) ? $mgUpload['message'] : '', isset($mgUpload['http_code']) ? $mgUpload['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EA%B4%80%EB%A6%AC%20%ED%85%8C%EC%8A%A4%ED%8A%B8%20%EC%82%AD%EC%A0%9C'), !empty($mgDelete['ok']), isset($mgDelete['message']) ? $mgDelete['message'] : '', isset($mgDelete['http_code']) ? $mgDelete['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EA%B3%B5%ED%86%B5%EB%AC%B8%EC%84%9C%20%EA%B4%80%EB%A6%AC%20%ED%8F%B4%EB%8D%94'), !empty($mgCommonRoot['ok']), isset($mgCommonRoot['message']) ? $mgCommonRoot['message'] : '', isset($mgCommonRoot['http_code']) ? $mgCommonRoot['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EA%B3%B5%ED%86%B5%EB%AC%B8%EC%84%9C%20%EA%B4%80%EB%A6%AC%2F%EC%9D%B8%EB%A0%A5%EA%B4%80%EB%A6%AC%20%ED%8F%B4%EB%8D%94'), !empty($mgCommonManpower['ok']), isset($mgCommonManpower['message']) ? $mgCommonManpower['message'] : '', isset($mgCommonManpower['http_code']) ? $mgCommonManpower['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EA%B3%B5%ED%86%B5%EB%AC%B8%EC%84%9C%20%EA%B4%80%EB%A6%AC%20%ED%98%84%EC%9E%AC%EC%97%B0%EB%8F%84%20%ED%8F%B4%EB%8D%94'), !empty($mgCommonYear['ok']), isset($mgCommonYear['message']) ? $mgCommonYear['message'] : '', isset($mgCommonYear['http_code']) ? $mgCommonYear['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EA%B3%B5%ED%86%B5%EB%AC%B8%EC%84%9C%20%EA%B4%80%EB%A6%AC%20%ED%98%84%EC%9E%AC%EC%9B%94%20%ED%8F%B4%EB%8D%94'), !empty($mgCommonMonth['ok']), isset($mgCommonMonth['message']) ? $mgCommonMonth['message'] : '', isset($mgCommonMonth['http_code']) ? $mgCommonMonth['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EA%B3%B5%ED%86%B5%EB%AC%B8%EC%84%9C%20%EA%B4%80%EB%A6%AC%20%ED%85%8C%EC%8A%A4%ED%8A%B8%20%EC%97%85%EB%A1%9C%EB%93%9C'), !empty($mgCommonUpload['ok']), isset($mgCommonUpload['message']) ? $mgCommonUpload['message'] : '', isset($mgCommonUpload['http_code']) ? $mgCommonUpload['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EA%B3%B5%ED%86%B5%EB%AC%B8%EC%84%9C%20%EA%B4%80%EB%A6%AC%20%ED%85%8C%EC%8A%A4%ED%8A%B8%20%EC%82%AD%EC%A0%9C'), !empty($mgCommonDelete['ok']), isset($mgCommonDelete['message']) ? $mgCommonDelete['message'] : '', isset($mgCommonDelete['http_code']) ? $mgCommonDelete['http_code'] : 0);
          ?>
        </tbody>
      </table>
      <?php if (!empty($checkResult['test_file']['id'])): ?>
        <div class="mt-3 text-xs text-gray-500">
          테스트 파일 ID: <code><?php echo h($checkResult['test_file']['id']); ?></code>
          / 파일명: <code><?php echo h(isset($checkResult['test_file']['name']) ? $checkResult['test_file']['name'] : ''); ?></code>
        </div>
      <?php endif; ?>
      <?php if (!empty($checkResult['approval_test_file']['id'])): ?>
        <div class="mt-2 text-xs text-gray-500">
          <?php echo h(urldecode('%EC%A0%84%EC%9E%90%EA%B2%B0%EC%9E%AC%20%ED%85%8C%EC%8A%A4%ED%8A%B8%20%ED%8C%8C%EC%9D%BC%20ID')); ?>: <code><?php echo h($checkResult['approval_test_file']['id']); ?></code>
          / <?php echo h(urldecode('%ED%8C%8C%EC%9D%BC%EB%AA%85')); ?>: <code><?php echo h(isset($checkResult['approval_test_file']['name']) ? $checkResult['approval_test_file']['name'] : ''); ?></code>
        </div>
      <?php endif; ?>
      <?php if (!empty($checkResult['completed_pdf']['test_file']['id'])): ?>
        <div class="mt-2 text-xs text-gray-500">
          <?php echo h(urldecode('%EC%99%84%EB%A3%8C%EB%AC%B8%EC%84%9C%20%50%44%46%20%ED%85%8C%EC%8A%A4%ED%8A%B8%20%ED%8C%8C%EC%9D%BC%20ID')); ?>: <code><?php echo h($checkResult['completed_pdf']['test_file']['id']); ?></code>
          / <?php echo h(urldecode('%ED%8C%8C%EC%9D%BC%EB%AA%85')); ?>: <code><?php echo h(isset($checkResult['completed_pdf']['test_file']['name']) ? $checkResult['completed_pdf']['test_file']['name'] : ''); ?></code>
        </div>
      <?php endif; ?>
      <?php if (!empty($checkResult['public_affairs']['test_file']['id'])): ?>
        <div class="mt-2 text-xs text-gray-500">
          공무 기성 테스트 파일 ID: <code><?php echo h($checkResult['public_affairs']['test_file']['id']); ?></code>
          / 파일명: <code><?php echo h(isset($checkResult['public_affairs']['test_file']['name']) ? $checkResult['public_affairs']['test_file']['name'] : ''); ?></code>
        </div>
      <?php endif; ?>
      <?php if (!empty($checkResult['construction']['test_file']['id'])): ?>
        <div class="mt-2 text-xs text-gray-500">
          <?php echo h(urldecode('%EA%B3%B5%EC%82%AC%20%ED%85%8C%EC%8A%A4%ED%8A%B8%20%ED%8C%8C%EC%9D%BC%20%49%44')); ?>: <code><?php echo h($checkResult['construction']['test_file']['id']); ?></code>
          / <?php echo h(urldecode('%ED%8C%8C%EC%9D%BC%EB%AA%85')); ?>: <code><?php echo h(isset($checkResult['construction']['test_file']['name']) ? $checkResult['construction']['test_file']['name'] : ''); ?></code>
        </div>
      <?php endif; ?>
      <?php if (!empty($checkResult['safety_health']['test_file']['id'])): ?>
        <div class="mt-2 text-xs text-gray-500">
          <?php echo h(urldecode('%EC%95%88%EC%A0%84%EB%B3%B4%EA%B1%B4%20%ED%85%8C%EC%8A%A4%ED%8A%B8%20%ED%8C%8C%EC%9D%BC%20%49%44')); ?>: <code><?php echo h($checkResult['safety_health']['test_file']['id']); ?></code>
          / <?php echo h(urldecode('%ED%8C%8C%EC%9D%BC%EB%AA%85')); ?>: <code><?php echo h(isset($checkResult['safety_health']['test_file']['name']) ? $checkResult['safety_health']['test_file']['name'] : ''); ?></code>
        </div>
      <?php endif; ?>
      <?php if (!empty($checkResult['safety_health']['common_test_file']['id'])): ?>
        <div class="mt-2 text-xs text-gray-500">
          <?php echo h(urldecode('%EA%B3%B5%ED%86%B5%20%EC%95%88%EC%A0%84%EB%B3%B4%EA%B1%B4%20%ED%85%8C%EC%8A%A4%ED%8A%B8%20%ED%8C%8C%EC%9D%BC%20%49%44')); ?>: <code><?php echo h($checkResult['safety_health']['common_test_file']['id']); ?></code>
          / <?php echo h(urldecode('%ED%8C%8C%EC%9D%BC%EB%AA%85')); ?>: <code><?php echo h(isset($checkResult['safety_health']['common_test_file']['name']) ? $checkResult['safety_health']['common_test_file']['name'] : ''); ?></code>
        </div>
      <?php endif; ?>
      <?php if (!empty($checkResult['quality']['test_file']['id'])): ?>
        <div class="mt-2 text-xs text-gray-500">
          <?php echo h(urldecode('%ED%92%88%EC%A7%88%20%ED%85%8C%EC%8A%A4%ED%8A%B8%20%ED%8C%8C%EC%9D%BC%20%49%44')); ?>: <code><?php echo h($checkResult['quality']['test_file']['id']); ?></code>
          / <?php echo h(urldecode('%ED%8C%8C%EC%9D%BC%EB%AA%85')); ?>: <code><?php echo h(isset($checkResult['quality']['test_file']['name']) ? $checkResult['quality']['test_file']['name'] : ''); ?></code>
        </div>
      <?php endif; ?>
      <?php if (!empty($checkResult['quality']['common_test_file']['id'])): ?>
        <div class="mt-2 text-xs text-gray-500">
          <?php echo h(urldecode('%EA%B3%B5%ED%86%B5%20%ED%92%88%EC%A7%88%20%ED%85%8C%EC%8A%A4%ED%8A%B8%20%ED%8C%8C%EC%9D%BC%20%49%44')); ?>: <code><?php echo h($checkResult['quality']['common_test_file']['id']); ?></code>
          / <?php echo h(urldecode('%ED%8C%8C%EC%9D%BC%EB%AA%85')); ?>: <code><?php echo h(isset($checkResult['quality']['common_test_file']['name']) ? $checkResult['quality']['common_test_file']['name'] : ''); ?></code>
        </div>
      <?php endif; ?>
      <?php if (!empty($checkResult['management']['test_file']['id'])): ?>
        <div class="mt-2 text-xs text-gray-500">
          <?php echo h(urldecode('%EA%B4%80%EB%A6%AC%20%ED%85%8C%EC%8A%A4%ED%8A%B8%20%ED%8C%8C%EC%9D%BC%20%49%44')); ?>: <code><?php echo h($checkResult['management']['test_file']['id']); ?></code>
          / <?php echo h(urldecode('%ED%8C%8C%EC%9D%BC%EB%AA%85')); ?>: <code><?php echo h(isset($checkResult['management']['test_file']['name']) ? $checkResult['management']['test_file']['name'] : ''); ?></code>
        </div>
      <?php endif; ?>
      <?php if (!empty($checkResult['management']['common_test_file']['id'])): ?>
        <div class="mt-2 text-xs text-gray-500">
          <?php echo h(urldecode('%EA%B3%B5%ED%86%B5%20%EA%B4%80%EB%A6%AC%20%ED%85%8C%EC%8A%A4%ED%8A%B8%20%ED%8C%8C%EC%9D%BC%20%49%44')); ?>: <code><?php echo h($checkResult['management']['common_test_file']['id']); ?></code>
          / <?php echo h(urldecode('%ED%8C%8C%EC%9D%BC%EB%AA%85')); ?>: <code><?php echo h(isset($checkResult['management']['common_test_file']['name']) ? $checkResult['management']['common_test_file']['name'] : ''); ?></code>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>
