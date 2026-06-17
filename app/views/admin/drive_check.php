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
        $checkResult = cpms_drive_run_connection_check(Auth::user());
        if (is_array($checkResult)) {
            $checkResult['completed_pdf'] = cpms_approval_pdf_run_admin_check(Auth::user());
            $checkResult['public_affairs'] = cpms_public_affairs_drive_run_admin_check(Db::pdo(), Auth::user(), $publicAffairsProjectId);
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
    <label class="block text-sm font-bold text-gray-700 mb-2" for="public_affairs_project_id">공무 테스트 프로젝트 ID</label>
    <div class="flex flex-wrap items-center gap-3">
      <input id="public_affairs_project_id" name="public_affairs_project_id" type="number" min="1" class="w-48 px-3 py-3 rounded-xl border border-gray-300 text-sm" placeholder="미입력 시 첫 프로젝트">
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
            cpms_admin_drive_check_row('JSON 파일 읽기', !empty($checkResult['json']['ok']), isset($checkResult['json']['message']) ? $checkResult['json']['message'] : '', 0);
            cpms_admin_drive_check_row('Access Token 발급', !empty($checkResult['token']['ok']), isset($checkResult['token']['message']) ? $checkResult['token']['message'] : '', isset($checkResult['token']['http_code']) ? $checkResult['token']['http_code'] : 0);
            cpms_admin_drive_check_row('03_공통문서 업로드', !empty($checkResult['upload']['ok']), isset($checkResult['upload']['message']) ? $checkResult['upload']['message'] : '', isset($checkResult['upload']['http_code']) ? $checkResult['upload']['http_code'] : 0);
            cpms_admin_drive_check_row('테스트 파일 삭제', !empty($checkResult['delete']['ok']), isset($checkResult['delete']['message']) ? $checkResult['delete']['message'] : '', isset($checkResult['delete']['http_code']) ? $checkResult['delete']['http_code'] : 0);
            cpms_admin_drive_check_row(urldecode('%EC%A0%84%EC%9E%90%EA%B2%B0%EC%9E%AC%20%ED%98%84%EC%9E%AC%EC%97%B0%EB%8F%84%2F%EA%B8%B0%ED%83%80%20%ED%8F%B4%EB%8D%94'), !empty($checkResult['approval_folder']['ok']), isset($checkResult['approval_folder']['message']) ? $checkResult['approval_folder']['message'] : '', isset($checkResult['approval_folder']['http_code']) ? $checkResult['approval_folder']['http_code'] : 0);
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
            cpms_admin_drive_check_row('공무 점검 프로젝트', !empty($paProject['ok']), isset($paProject['message']) ? $paProject['message'] : '', 0);
            cpms_admin_drive_check_row('공무 01_공무 폴더', !empty($paRoot['ok']), isset($paRoot['message']) ? $paRoot['message'] : '', isset($paRoot['http_code']) ? $paRoot['http_code'] : 0);
            cpms_admin_drive_check_row('공무 기성 폴더', !empty($paProgress['ok']), isset($paProgress['message']) ? $paProgress['message'] : '', isset($paProgress['http_code']) ? $paProgress['http_code'] : 0);
            cpms_admin_drive_check_row('공무 현재연도 폴더', !empty($paYear['ok']), isset($paYear['message']) ? $paYear['message'] : '', isset($paYear['http_code']) ? $paYear['http_code'] : 0);
            cpms_admin_drive_check_row('공무 현재월 폴더', !empty($paMonth['ok']), isset($paMonth['message']) ? $paMonth['message'] : '', isset($paMonth['http_code']) ? $paMonth['http_code'] : 0);
            cpms_admin_drive_check_row('공무 기성 테스트 업로드', !empty($paUpload['ok']), isset($paUpload['message']) ? $paUpload['message'] : '', isset($paUpload['http_code']) ? $paUpload['http_code'] : 0);
            cpms_admin_drive_check_row('공무 기성 테스트 삭제', !empty($paDelete['ok']), isset($paDelete['message']) ? $paDelete['message'] : '', isset($paDelete['http_code']) ? $paDelete['http_code'] : 0);
            cpms_admin_drive_check_row('공무 테스트 임시파일 삭제', !empty($paCleanup['ok']), isset($paCleanup['message']) ? $paCleanup['message'] : '', 0);
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
    </div>
  <?php endif; ?>
</div>
