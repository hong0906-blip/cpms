<?php
/**
 * 안전사고 저장 액션 (PHP 5.6)
 * - 안전사고등록 모달/저장
 * - 안전사고 테이블 자동 생성
 * - 등록자 정보 저장
 */
require_once __DIR__ . '/../../bootstrap.php';

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
$occurredAt = isset($_POST['occurred_at']) ? trim((string)$_POST['occurred_at']) : '';
$severity = isset($_POST['severity']) ? trim((string)$_POST['severity']) : '보통';
$status = isset($_POST['status']) ? trim((string)$_POST['status']) : '접수';
if ($projectId <= 0 || $title === '') { flash_set('error','필수값을 입력해주세요.'); header('Location: ?r=안전/보건'); exit; }
if (mb_strlen($title,'UTF-8') > 200) $title = mb_substr($title,0,200,'UTF-8');
if (!in_array($severity, array('경미','보통','중대','긴급'), true)) $severity = '보통';
if (!in_array($status, array('접수','처리중','처리완료'), true)) $status = '접수';

$occurredSql = null;
if ($occurredAt !== '') {
    $occurredAt = str_replace('T', ' ', $occurredAt);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $occurredAt)) $occurredSql = $occurredAt . ':00';
}

$pdo = Db::pdo();
if (!$pdo) { flash_set('error','DB 연결 실패'); header('Location: ?r=안전/보건'); exit; }

$createdBy = null;
if (method_exists('App\\Core\\Auth', 'id')) { $tmpId = Auth::id(); if ($tmpId !== null && $tmpId !== false && $tmpId !== '') $createdBy = (int)$tmpId; }
$createdByName = method_exists('App\\Core\\Auth', 'userName') ? trim((string)Auth::userName()) : '';
if ($createdByName === '') $createdByName = (string)Auth::userEmail();
if ($createdByName === '') $createdByName = isset($_SESSION['email']) ? (string)$_SESSION['email'] : '사용자';

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_safety_incidents (
      id INT AUTO_INCREMENT PRIMARY KEY,
      project_id INT NOT NULL,
      title VARCHAR(200) NOT NULL,
      description TEXT NULL,
      occurred_at DATETIME NULL,
      severity VARCHAR(20) NOT NULL DEFAULT '보통',
      status VARCHAR(20) NOT NULL DEFAULT '접수',
      created_by INT NULL,
      created_by_name VARCHAR(80) NULL,
      created_at DATETIME NOT NULL,
      updated_at DATETIME NOT NULL,
      created_by_email VARCHAR(255) NULL,
      KEY idx_safety_incidents_project(project_id),
      KEY idx_safety_incidents_status(status),
      KEY idx_safety_incidents_created(created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

    $columns = array(
      'severity' => "ALTER TABLE cpms_safety_incidents ADD COLUMN severity VARCHAR(20) NOT NULL DEFAULT '보통'",
      'created_by' => "ALTER TABLE cpms_safety_incidents ADD COLUMN created_by INT NULL",
      'updated_at' => "ALTER TABLE cpms_safety_incidents ADD COLUMN updated_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00'",
      'action_note' => "ALTER TABLE cpms_safety_incidents ADD COLUMN action_note TEXT NULL",
      'action_by' => "ALTER TABLE cpms_safety_incidents ADD COLUMN action_by INT NULL",
      'action_by_name' => "ALTER TABLE cpms_safety_incidents ADD COLUMN action_by_name VARCHAR(80) NULL",
      'action_at' => "ALTER TABLE cpms_safety_incidents ADD COLUMN action_at DATETIME NULL"
    );
    foreach ($columns as $col => $sql) {
      $q = $pdo->query("SHOW COLUMNS FROM cpms_safety_incidents LIKE '".$col."'");
      if (!$q || !$q->fetch()) $pdo->exec($sql);
    }

    $now = date('Y-m-d H:i:s');
    $st = $pdo->prepare("INSERT INTO cpms_safety_incidents(project_id,title,description,occurred_at,severity,status,created_by,created_by_name,created_at,updated_at)
                         VALUES(:pid,:tt,:ds,:oc,:sv,:st,:cb,:cn,:ca,:ua)");
    $st->bindValue(':pid', $projectId, PDO::PARAM_INT);
    $st->bindValue(':tt', $title);
    $st->bindValue(':ds', $description !== '' ? $description : null, $description !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $st->bindValue(':oc', $occurredSql !== null ? $occurredSql : null, $occurredSql !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $st->bindValue(':sv', $severity);
    $st->bindValue(':st', $status);
    $st->bindValue(':cb', $createdBy, $createdBy !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
    $st->bindValue(':cn', $createdByName);
    $st->bindValue(':ca', $now);
    $st->bindValue(':ua', $now);
    $st->execute();

    flash_set('success','안전사고가 등록되었습니다. 안전/보건 탭에서 후속조치를 입력할 수 있습니다.');
} catch (Exception $e) {
    flash_set('error','안전사고 등록 실패: '.$e->getMessage());
}

header('Location: ?r=안전/보건');
exit;