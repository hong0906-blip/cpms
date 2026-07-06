<?php
/**
 * 공사 이슈 저장 액션 (PHP 5.6)
 * - 이슈등록 모달/저장
 * - 이슈 테이블 자동 생성
 * - 등록자 정보 저장
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../common/chat_notification_helpers.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) { header('Location: ?r=login'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'Method Not Allowed'; exit; }
if (!(Auth::canManageConstruction() || Auth::canManageEmployees() || Auth::isMaster() || Auth::userRole() === 'executive')) {
    http_response_code(403); echo '403 Forbidden'; exit;
}

$token = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
if (!csrf_check($token)) { flash_set('error','보안 토큰이 유효하지 않습니다.'); header('Location: ?r=공사'); exit; }

$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$title = isset($_POST['title']) ? trim((string)$_POST['title']) : '';
$description = isset($_POST['description']) ? trim((string)$_POST['description']) : '';
$priority = isset($_POST['priority']) ? trim((string)$_POST['priority']) : '보통';
$status = isset($_POST['status']) ? trim((string)$_POST['status']) : '접수';
$issueKind = isset($_POST['issue_kind']) ? trim((string)$_POST['issue_kind']) : 'issue';
if ($issueKind !== 'security') $issueKind = 'issue';
$redirectTab = ($issueKind === 'security') ? 'security' : 'issues';
$issueLabel = ($issueKind === 'security') ? '보안사고' : '이슈';
if ($projectId <= 0 || $title === '') { flash_set('error','필수값을 입력해주세요.'); header('Location: ?r=공사&pid='.$projectId.'&tab='.$redirectTab); exit; }
if (mb_strlen($title,'UTF-8') > 200) $title = mb_substr($title,0,200,'UTF-8');
if (!in_array($priority, array('낮음','보통','높음','긴급'), true)) $priority = '보통';
if (!in_array($status, array('접수','처리중','처리완료'), true)) $status = '접수';

$pdo = Db::pdo();
if (!$pdo) { flash_set('error','DB 연결 실패'); header('Location: ?r=공사&pid='.$projectId.'&tab='.$redirectTab); exit; }

$createdBy = null;
if (method_exists('App\\Core\\Auth', 'id')) {
    $tmpId = Auth::id();
    if ($tmpId !== null && $tmpId !== false && $tmpId !== '') $createdBy = (int)$tmpId;
}
$createdByName = '';
if (method_exists('App\\Core\\Auth', 'userName')) $createdByName = trim((string)Auth::userName());
if ($createdByName === '') $createdByName = (string)Auth::userEmail();
if ($createdByName === '') $createdByName = isset($_SESSION['email']) ? (string)$_SESSION['email'] : '사용자';

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_project_issues (
      id INT AUTO_INCREMENT PRIMARY KEY,
      project_id INT NOT NULL,
      issue_kind VARCHAR(20) NOT NULL DEFAULT 'issue',
      title VARCHAR(200) NOT NULL,
      description TEXT NULL,
      priority VARCHAR(20) NOT NULL DEFAULT '보통',
      status VARCHAR(20) NOT NULL DEFAULT '접수',
      created_by INT NULL,
      created_by_name VARCHAR(80) NULL,
      created_at DATETIME NOT NULL,
      updated_at DATETIME NOT NULL,
      reason VARCHAR(255) NULL,
      created_by_email VARCHAR(255) NULL,
      KEY idx_project_issues_project(project_id),
      KEY idx_project_issues_kind(issue_kind),
      KEY idx_project_issues_status(status),
      KEY idx_project_issues_created(created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

    $columns = array(
      'issue_kind' => "ALTER TABLE cpms_project_issues ADD COLUMN issue_kind VARCHAR(20) NOT NULL DEFAULT 'issue' AFTER project_id",
      'title' => "ALTER TABLE cpms_project_issues ADD COLUMN title VARCHAR(200) NOT NULL DEFAULT ''",
      'description' => "ALTER TABLE cpms_project_issues ADD COLUMN description TEXT NULL",
      'priority' => "ALTER TABLE cpms_project_issues ADD COLUMN priority VARCHAR(20) NOT NULL DEFAULT '보통'",
      'created_by' => "ALTER TABLE cpms_project_issues ADD COLUMN created_by INT NULL",
      'created_by_email' => "ALTER TABLE cpms_project_issues ADD COLUMN created_by_email VARCHAR(255) NULL",
      'updated_at' => "ALTER TABLE cpms_project_issues ADD COLUMN updated_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00'"
    );
    foreach ($columns as $col => $sql) {
      $q = $pdo->query("SHOW COLUMNS FROM cpms_project_issues LIKE '".$col."'");
      if (!$q || !$q->fetch()) $pdo->exec($sql);
    }

    $now = date('Y-m-d H:i:s');
    $createdByEmail = (string)Auth::userEmail();
    $st = $pdo->prepare("INSERT INTO cpms_project_issues(project_id, issue_kind, title, description, priority, status, created_by, created_by_name, created_by_email, created_at, updated_at, reason)
                         VALUES(:pid,:kind,:tt,:ds,:pr,:st,:cb,:cn,:em,:ca,:ua,:rs)");
    $st->bindValue(':pid', $projectId, PDO::PARAM_INT);
    $st->bindValue(':kind', $issueKind);
    $st->bindValue(':tt', $title);
    $st->bindValue(':ds', $description !== '' ? $description : null, $description !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $st->bindValue(':pr', $priority);
    $st->bindValue(':st', $status);
    $st->bindValue(':cb', $createdBy, $createdBy !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
    $st->bindValue(':cn', $createdByName);
    $st->bindValue(':em', $createdByEmail);
    $st->bindValue(':ca', $now);
    $st->bindValue(':ua', $now);
    $st->bindValue(':rs', $title);
    $st->execute();
    $issueId = (int)$pdo->lastInsertId();

    $projectName = cpms_google_chat_project_name($pdo, $projectId);
    $shortDescription = $description !== '' ? $description : $title;
    if (mb_strlen($shortDescription, 'UTF-8') > 300) {
        $shortDescription = mb_substr($shortDescription, 0, 300, 'UTF-8');
    }
    $messageText = implode("\n", array(
        cpms_chat_priority_prefix('issue', $priority),
        '',
        '현장명 : '.$projectName,
        '제목 : '.$title,
        '구분 : '.$priority,
        '등록자 : '.$createdByName,
        '',
        '내용 :',
        $shortDescription,
        '',
        '협업툴 '.$issueLabel.'에서 확인해주세요.'
    ));
    cpms_google_chat_send_to_executives($pdo, $messageText, 'CREATED', $issueId, ($issueKind === 'security' ? 'SECURITY_ISSUE' : 'ISSUE'));
    
    flash_set('success',$issueLabel.'가 등록되었습니다.');
  } catch (Exception $e) {
    flash_set('error',$issueLabel.' 등록 실패: '.$e->getMessage());
  }

header('Location: ?r=공사&pid='.$projectId.'&tab='.$redirectTab);
exit;
