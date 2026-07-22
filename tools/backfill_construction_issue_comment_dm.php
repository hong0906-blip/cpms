<?php
/**
 * Send one Google Chat DM per existing construction issue comment/recipient pair.
 * Usage: php tools/backfill_construction_issue_comment_dm.php [--send]
 * PHP 5.6 compatible.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit;
}

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/services/ConstructionIssueCommentNotificationService.php';

use App\Core\Db;

$send = in_array('--send', isset($argv) && is_array($argv) ? $argv : array(), true);
$pdo = Db::pdo();
if (!$pdo) {
    fwrite(STDERR, "DB connection failed.\n");
    exit(1);
}

try {
    $sql = "SELECT c.id
        FROM cpms_project_issue_comments c
        JOIN cpms_project_issues i ON i.id = c.issue_id
        WHERE i.issue_kind IS NULL OR i.issue_kind = '' OR i.issue_kind = 'issue'
        ORDER BY c.id ASC";
    $st = $pdo->query($sql);
    $commentIds = $st ? $st->fetchAll(PDO::FETCH_COLUMN) : array();
} catch (Exception $e) {
    fwrite(STDERR, "Comment lookup failed: " . $e->getMessage() . "\n");
    exit(1);
}

$summary = array('comments' => count($commentIds), 'recipients' => 0, 'attempted' => 0, 'success' => 0, 'failed' => 0, 'already' => 0);
for ($i = 0; $i < count($commentIds); $i++) {
    $commentId = (int)$commentIds[$i];
    $row = cpms_construction_issue_comment_find($pdo, $commentId);
    if (!$row) continue;
    $recipientIds = cpms_construction_issue_comment_recipient_ids($pdo, (int)$row['issue_id'], (int)$row['project_id']);
    $summary['recipients'] += count($recipientIds);

    if ($send) {
        $result = cpms_construction_issue_comment_send_dm($pdo, $commentId, true);
        $summary['attempted'] += (int)$result['attempted'];
        $summary['success'] += (int)$result['success'];
        $summary['failed'] += (int)$result['failed'];
        $summary['already'] += (int)$result['already'];
    } else {
        for ($j = 0; $j < count($recipientIds); $j++) {
            if (cpms_construction_issue_comment_was_attempted($pdo, $commentId, (int)$recipientIds[$j])) $summary['already']++;
            else $summary['attempted']++;
        }
    }
}

echo ($send ? 'SEND' : 'DRY-RUN') . "\n";
foreach ($summary as $key => $value) echo $key . '=' . (int)$value . "\n";
exit($summary['failed'] > 0 ? 2 : 0);
