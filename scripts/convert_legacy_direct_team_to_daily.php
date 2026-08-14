<?php
/**
 * 지정된 예전 직영팀 5명을 기존 단가제 일용직으로 전환하고 직영팀 명부에서 삭제합니다.
 * - PHP 5.6 호환
 * - 여러 번 실행해도 이미 삭제된 이름은 건너뜁니다.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/services/DirectTeamConversionService.php';

use App\Core\Db;

$pdo = Db::pdo();
if (!$pdo) {
    fwrite(STDERR, "DB 연결 실패\n");
    exit(2);
}

$names = array('강구열', '고경준', '신대선', '오만성', '한재규');
$converter = new DirectTeamConversionService($pdo);
if (!$converter->prepareSchema()) {
    fwrite(STDERR, "인력관리 테이블 준비 실패\n");
    exit(3);
}

try {
    $placeholders = implode(',', array_fill(0, count($names), '?'));
    $st = $pdo->prepare("SELECT id, name FROM direct_team_members
                         WHERE name IN (" . $placeholders . ")
                         ORDER BY id ASC");
    $st->execute($names);
    $members = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($members)) $members = array();

    $pdo->beginTransaction();
    $results = array();
    foreach ($members as $member) {
        $memberId = isset($member['id']) ? (int)$member['id'] : 0;
        if ($memberId <= 0) continue;
        $results[] = $converter->convertAndDelete($memberId, 0);
    }
    $pdo->commit();

    $convertedNames = array();
    $convertedRows = 0;
    foreach ($results as $result) {
        $convertedNames[] = isset($result['member_name']) ? (string)$result['member_name'] : '';
        $convertedRows += isset($result['converted_rows']) ? (int)$result['converted_rows'] : 0;
    }
    $missingNames = array_values(array_diff($names, $convertedNames));

    echo '직영팀 삭제 ' . count($convertedNames) . '명, 일용직 노무비 행 전환 ' . $convertedRows . "건\n";
    if (count($convertedNames) > 0) echo '처리: ' . implode(', ', $convertedNames) . "\n";
    if (count($missingNames) > 0) echo '이미 삭제되었거나 명부에 없음: ' . implode(', ', $missingNames) . "\n";
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, '전환 실패: ' . $e->getMessage() . "\n");
    exit(4);
}
