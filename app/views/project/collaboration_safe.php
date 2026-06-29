<?php
/**
 * 공무 협업툴 안전 모드
 * - 협업툴 본 화면과 서비스 파일을 불러오지 않고 서버 상태만 표시한다.
 * - PHP 5.6 호환 문법만 사용한다.
 */

if (!function_exists('cpms_pa_collab_safe_h')) {
function cpms_pa_collab_safe_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}}

if (!function_exists('cpms_pa_collab_safe_bool')) {
function cpms_pa_collab_safe_bool($value) {
    return $value ? '정상' : '확인 필요';
}}

if (!function_exists('cpms_pa_collab_safe_storage_state')) {
function cpms_pa_collab_safe_storage_state($exists, $writable) {
    if (!$exists) return '존재하지 않음 - 복구 필요';
    if (!$writable) return '쓰기 불가 - 권한 확인 필요';
    return '정상';
}}

if (!function_exists('cpms_pa_collab_safe_table_exists')) {
function cpms_pa_collab_safe_table_exists($pdo, $tableName) {
    if (!$pdo) return false;
    try {
        $dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($dbName === '') return false;
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :table_name");
        $st->execute(array(':db' => $dbName, ':table_name' => (string)$tableName));
        return ((int)$st->fetchColumn() > 0);
    } catch (Exception $e) {
        return false;
    }
}}

$rootDir = dirname(dirname(dirname(__DIR__)));
$storageRoot = function_exists('cpms_storage_root') ? cpms_storage_root() : ($rootDir . '/storage');
$collabStorage = $storageRoot . '/public_affairs_collab';
$pdo = null;
try {
    if (class_exists('\\App\\Core\\Db')) $pdo = \App\Core\Db::pdo();
} catch (Exception $e) {
    $pdo = null;
}

$authOk = false;
$userName = '';
$userEmail = '';
$userDepartment = '';
$userRole = '';
if (class_exists('\\App\\Core\\Auth')) {
    try {
        $authOk = \App\Core\Auth::check() ? true : false;
        $userName = (string)\App\Core\Auth::userName();
        $userEmail = (string)\App\Core\Auth::userEmail();
        $userDepartment = (string)\App\Core\Auth::userDepartment();
        $userRole = (string)\App\Core\Auth::userRole();
    } catch (Exception $e) {
        $authOk = false;
    }
}

$files = array(
    '서비스 파일' => $rootDir . '/app/services/PublicAffairsCollaborationService.php',
    '협업툴 화면' => $rootDir . '/app/views/project/collaboration.php',
    '협업툴 진입 화면' => $rootDir . '/app/views/project/collaboration_app.php',
    '협업툴 안전 화면' => $rootDir . '/app/views/project/collaboration_safe.php',
    '액션 처리' => $rootDir . '/app/views/project/collaboration_action.php',
    '파일 다운로드' => $rootDir . '/app/views/project/collaboration_file.php',
    'CSS' => $rootDir . '/public/assets/css/public_affairs_collaboration.css',
    'JS' => $rootDir . '/public/assets/js/public_affairs_collaboration.js',
);
?><!doctype html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>공무 협업툴 안전 모드</title>
  <style>
    body{margin:0;font-family:Arial,'Malgun Gothic',sans-serif;background:#f5f7fb;color:#172033}
    .wrap{max-width:980px;margin:0 auto;padding:34px 18px}
    .card{background:#fff;border:1px solid #dbe3ef;border-radius:14px;padding:22px;box-shadow:0 8px 24px rgba(15,23,42,.08)}
    h1{margin:0 0 8px;font-size:25px}
    .desc{margin:0 0 18px;color:#64748b;font-weight:700}
    .grid{display:grid;grid-template-columns:220px minmax(0,1fr);border-top:1px solid #eef2f7}
    .grid div{padding:10px 8px;border-bottom:1px solid #eef2f7;font-size:14px;word-break:break-all}
    .grid b{color:#334155}
    .actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:18px}
    .btn{display:inline-flex;text-decoration:none;border-radius:10px;padding:10px 14px;font-weight:900;border:1px solid #dbe3ef;color:#172033;background:#fff}
    .btn.primary{background:#0f766e;border-color:#0f766e;color:#fff}
  </style>
</head>
<body>
  <main class="wrap">
    <section class="card">
      <h1>공무 협업툴 안전 모드</h1>
      <p class="desc">현재 일반 모드에서 오류가 발생할 수 있어 협업툴 본 화면을 불러오지 않고 상태만 확인합니다.</p>
      <div class="grid">
        <div><b>현재 시간</b></div><div><?php echo cpms_pa_collab_safe_h(date('Y-m-d H:i:s')); ?></div>
        <div><b>PHP 버전</b></div><div><?php echo cpms_pa_collab_safe_h(PHP_VERSION); ?></div>
        <div><b>로그인</b></div><div><?php echo cpms_pa_collab_safe_h(cpms_pa_collab_safe_bool($authOk)); ?></div>
        <div><b>사용자</b></div><div><?php echo cpms_pa_collab_safe_h($userName . ' / ' . $userEmail); ?></div>
        <div><b>부서/권한</b></div><div><?php echo cpms_pa_collab_safe_h($userDepartment . ' / ' . $userRole); ?></div>
        <div><b>DB 연결</b></div><div><?php echo cpms_pa_collab_safe_h(cpms_pa_collab_safe_bool($pdo ? true : false)); ?></div>
        <div><b>프로젝트 테이블</b></div><div><?php echo cpms_pa_collab_safe_h(cpms_pa_collab_safe_bool(cpms_pa_collab_safe_table_exists($pdo, 'cpms_projects'))); ?></div>
        <div><b>직원 테이블</b></div><div><?php echo cpms_pa_collab_safe_h(cpms_pa_collab_safe_bool(cpms_pa_collab_safe_table_exists($pdo, 'employees'))); ?></div>
        <div><b>storage 경로</b></div><div><?php echo cpms_pa_collab_safe_h($storageRoot); ?></div>
        <div><b>storage 상태</b></div><div><?php echo cpms_pa_collab_safe_h(cpms_pa_collab_safe_storage_state(is_dir($storageRoot), is_dir($storageRoot) && is_writable($storageRoot))); ?></div>
        <div><b>협업툴 storage 상태</b></div><div><?php echo cpms_pa_collab_safe_h(cpms_pa_collab_safe_storage_state(is_dir($collabStorage), is_dir($collabStorage) && is_writable($collabStorage))); ?></div>
        <div><b>tasks.json</b></div><div><?php echo cpms_pa_collab_safe_h(cpms_pa_collab_safe_bool(is_file($collabStorage . '/tasks.json'))); ?></div>
        <div><b>settings.json</b></div><div><?php echo cpms_pa_collab_safe_h(cpms_pa_collab_safe_bool(is_file($collabStorage . '/settings.json'))); ?></div>
        <?php foreach ($files as $label => $path): ?>
          <div><b><?php echo cpms_pa_collab_safe_h($label); ?></b></div><div><?php echo cpms_pa_collab_safe_h((is_file($path) ? '정상' : '확인 필요') . ' - ' . $path); ?></div>
        <?php endforeach; ?>
      </div>
      <div class="actions">
        <a class="btn primary" href="?r=public_affairs_collab_repair" target="_blank">저장소 복구 실행</a>
        <a class="btn primary" href="?r=public_affairs_collab">일반 모드로 열기</a>
        <a class="btn" href="?r=public_affairs_collab_debug" target="_blank">진단 페이지 열기</a>
        <a class="btn" href="?r=공무">공무 화면으로 돌아가기</a>
      </div>
    </section>
  </main>
</body>
</html>
