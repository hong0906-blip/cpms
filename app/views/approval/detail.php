<?php
use App\Core\Db;

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/document_templates.php';
require_once __DIR__ . '/template_style.php';
require_once __DIR__ . '/template_proposal.php';
require_once __DIR__ . '/template_leave.php';
require_once __DIR__ . '/template_unused_leave.php';

$pdo = Db::pdo();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$u = \App\Core\Auth::user();

if (!$pdo || $id <= 0) {
    echo '<div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4">' . h(approval_ko('%EB%AC%B8%EC%84%9C%EB%A5%BC%20%EC%B0%BE%EC%9D%84%20%EC%88%98%20%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.')) . '</div>';
    return;
}

$st = $pdo->prepare("SELECT * FROM cpms_approval_documents WHERE id=:id LIMIT 1");
$st->execute(array(':id' => $id));
$d = $st->fetch(PDO::FETCH_ASSOC);

if (!$d) {
    echo '<div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4">' . h(approval_ko('%EB%AC%B8%EC%84%9C%EB%A5%BC%20%EC%B0%BE%EC%9D%84%20%EC%88%98%20%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.')) . '</div>';
    return;
}

if (!approval_can_view_document($pdo, $d, $u)) {
    echo '<div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4">' . h(approval_ko('%EC%9D%B4%20%EB%AC%B8%EC%84%9C%EB%A5%BC%20%EB%B3%BC%20%EA%B6%8C%ED%95%9C%EC%9D%B4%20%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.')) . '</div>';
    return;
}

$st = $pdo->prepare("SELECT * FROM cpms_approval_lines WHERE document_id=:id ORDER BY line_order");
$st->execute(array(':id' => $id));
$lines = $st->fetchAll(PDO::FETCH_ASSOC);
if (!is_array($lines)) {
    $lines = array();
}

$content = approval_parse_content(isset($d['content']) ? $d['content'] : '');
$filesByType = array();
if (isset($d['doc_type']) && $d['doc_type'] === 'proposal' && approval_table_exists($pdo, 'cpms_approval_files')) {
    $fs = $pdo->prepare("SELECT * FROM cpms_approval_files WHERE document_id=:id ORDER BY id DESC");
    $fs->execute(array(':id' => $id));
    $fileRows = $fs->fetchAll(PDO::FETCH_ASSOC);
    if (is_array($fileRows)) {
        for ($i = 0; $i < count($fileRows); $i++) {
            $f = $fileRows[$i];
            $k = isset($f['file_type']) ? $f['file_type'] : '';
            if ($k !== '' && !isset($filesByType[$k])) {
                $filesByType[$k] = $f;
            }
        }
    }
}

$references = approval_fetch_references($pdo, $id);
$approvalLogs = array();
if (approval_table_exists($pdo, 'cpms_approval_logs')) {
    try {
        $logSt = $pdo->prepare("SELECT l.*, al.role_type, al.approver_name AS line_approver_name FROM cpms_approval_logs l LEFT JOIN cpms_approval_lines al ON al.id=l.line_id WHERE l.document_id=:id ORDER BY l.created_at ASC, l.id ASC");
        $logSt->execute(array(':id' => $id));
        $approvalLogs = $logSt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($approvalLogs)) {
            $approvalLogs = array();
        }
    } catch (Exception $e) {
        $approvalLogs = array();
    }
}
$canCancel = approval_is_document_owner($pdo, $d, $u) && approval_can_cancel_document($d);
$canDelete = approval_can_delete_document($pdo, $d, $u);

$uid = approval_current_employee_id($pdo, $u);
$userEmail = approval_current_user_email($u);
$userName = approval_current_user_name($u);
$myPendingLine = null;
for ($i = 0; $i < count($lines); $i++) {
    $line = $lines[$i];
    $isPending = (isset($line['line_status']) && $line['line_status'] === 'PENDING');
    $isMineById = ($uid > 0 && isset($line['approver_id']) && (int)$line['approver_id'] === $uid);
    $isMineByEmail = ($userEmail !== '' && isset($line['approver_email']) && strtolower(trim((string)$line['approver_email'])) === strtolower($userEmail));
    $isMineByName = ($userName !== '' && isset($line['approver_name']) && trim((string)$line['approver_name']) === $userName);
    $isDelegated = (isset($line['line_status']) && $line['line_status'] === 'DELEGATED') || (isset($line['is_delegated']) && (int)$line['is_delegated'] === 1);
    if ($isPending && !$isDelegated && ($isMineById || $isMineByEmail || $isMineByName)) {
        $myPendingLine = $line;
        break;
    }
}
$canDecide = (isset($d['doc_status']) && $d['doc_status'] === 'PENDING' && $myPendingLine);
$isRecipientEditablePlan = ($canDecide && isset($d['doc_type']) && $d['doc_type'] === 'unused_leave_plan');
?>
<div class="space-y-4">
    <div class="no-print flex flex-wrap gap-2">
        <a href="javascript:history.back()" class="px-3 py-2 bg-gray-100 rounded"><?php echo h(approval_ko('%EB%92%A4%EB%A1%9C%EA%B0%80%EA%B8%B0')); ?></a>
        <a href="?r=approval_home" class="px-3 py-2 bg-gray-100 rounded"><?php echo h(approval_ko('%EB%AA%A9%EB%A1%9D%EC%9C%BC%EB%A1%9C')); ?></a>
        <a href="?r=approval_print&id=<?php echo (int)$id; ?>" class="px-3 py-2 bg-indigo-100 rounded"><?php echo h(approval_ko('%EC%B6%9C%EB%A0%A5')); ?></a>
        <?php if ($canCancel) { ?>
            <form method="post" action="?r=approval_cancel">
                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
                <button class="px-3 py-2 bg-rose-100 rounded"><?php echo h(approval_ko('%EC%9A%94%EC%B2%AD%EC%B7%A8%EC%86%8C')); ?></button>
            </form>
        <?php } ?>
        <?php if ($canDelete) { ?>
            <form method="post" action="?r=approval_delete" onsubmit="return confirm('<?php echo h(approval_ko('%EC%B7%A8%EC%86%8C%EB%AC%B8%EC%84%9C%EB%A5%BC%20%EC%82%AD%EC%A0%9C%ED%95%A0%EA%B9%8C%EC%9A%94%3F')); ?>');">
                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
                <button class="px-3 py-2 bg-gray-200 rounded"><?php echo h(approval_ko('%EC%82%AD%EC%A0%9C')); ?></button>
            </form>
        <?php } ?>
    </div>

    <div class="no-print bg-white rounded-2xl border p-4">
        <div class="flex flex-wrap gap-2 items-center">
            <span class="inline-flex items-center px-3 py-1 rounded-full border text-xs font-bold <?php echo h(approval_status_badge(isset($d['doc_status']) ? $d['doc_status'] : '')); ?>">
                <?php echo h(approval_status_label(isset($d['doc_status']) ? $d['doc_status'] : '')); ?>
            </span>
            <span class="font-bold"><?php echo h(isset($d['title']) ? $d['title'] : ''); ?></span>
            <span class="text-sm text-gray-500"><?php echo h(approval_doc_label(isset($d['doc_type']) ? $d['doc_type'] : '')); ?></span>
        </div>
    </div>

    <?php if (isset($d['doc_status']) && $d['doc_status'] === 'PENDING' && !$isRecipientEditablePlan) { ?>
        <div class="no-print cpms-approval-decision-panel <?php echo (isset($d['doc_type']) && (string)$d['doc_type'] === 'leave') ? '' : 'cpms-mobile-hide'; ?> bg-white rounded-2xl border p-4 flex flex-wrap gap-3 items-center">
            <?php if ($canDecide) { ?>
                <form method="post" action="?r=approval_decide" style="display:inline;">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
                    <input type="hidden" name="action" value="approve">
                    <button type="submit" class="px-6 py-3 rounded-xl bg-emerald-600 text-white font-extrabold"><?php echo h(approval_ko('%EC%8A%B9%EC%9D%B8%ED%95%98%EA%B8%B0')); ?></button>
                </form>
                <form method="post" action="?r=approval_decide" class="flex flex-wrap gap-2 items-center">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
                    <input type="hidden" name="action" value="reject">
                    <input type="text" name="reject_reason" class="border rounded-xl px-3 py-2 min-w-[280px]" placeholder="<?php echo h(approval_ko('%EB%B0%98%EB%A0%A4%EC%82%AC%EC%9C%A0%20%EC%9E%85%EB%A0%A5')); ?>" required>
                    <button type="submit" class="px-6 py-3 rounded-xl bg-rose-600 text-white font-extrabold"><?php echo h(approval_ko('%EB%B0%98%EB%A0%A4%ED%95%98%EA%B8%B0')); ?></button>
                </form>
            <?php } else { ?>
                <div class="text-sm text-gray-600"><?php echo h(approval_ko('%ED%98%84%EC%9E%AC%20%EA%B2%B0%EC%9E%AC%EC%9E%90%EB%A7%8C%20%EC%8A%B9%EC%9D%B8%2F%EB%B0%98%EB%A0%A4%ED%95%A0%20%EC%88%98%20%EC%9E%88%EC%8A%B5%EB%8B%88%EB%8B%A4.')); ?></div>
            <?php } ?>
        </div>
    <?php } ?>

    <?php if (isset($d['doc_status']) && $d['doc_status'] === 'REJECTED') { ?>
        <div class="bg-rose-50 border border-rose-200 rounded-2xl p-4">
            <?php echo h(approval_ko('%EB%B0%98%EB%A0%A4%EB%8B%A8%EA%B3%84')); ?>: <?php echo h(approval_role_label(isset($d['rejected_step']) ? $d['rejected_step'] : '')); ?>
            / <?php echo h(approval_ko('%EB%B0%98%EB%A0%A4%EC%9D%BC%EC%8B%9C')); ?>: <?php echo h(isset($d['updated_at']) ? $d['updated_at'] : ''); ?>
            / <?php echo h(approval_ko('%EB%B0%98%EB%A0%A4%EC%82%AC%EC%9C%A0')); ?>: <?php echo h(isset($d['reject_reason']) ? $d['reject_reason'] : ''); ?>
        </div>
    <?php } ?>

    <?php if ($isRecipientEditablePlan) { ?>
        <form method="post" action="?r=approval_decide">
            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
            <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
            <input type="hidden" name="action" value="approve">
            <?php render_approval_unused_leave_plan_document($content, $lines, 'approve_edit', array()); ?>
            <div class="no-print bg-white rounded-2xl border p-4 flex flex-wrap gap-3 items-center">
                <button type="submit" class="px-6 py-3 rounded-xl bg-emerald-600 text-white font-extrabold"><?php echo h(approval_ko('%EC%9E%91%EC%84%B1%20%ED%9B%84%20%EC%88%98%EB%9D%BD%ED%95%98%EA%B8%B0')); ?></button>
            </div>
        </form>
        <form method="post" action="?r=approval_decide" class="no-print bg-white rounded-2xl border p-4 flex flex-wrap gap-2 items-center">
            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
            <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
            <input type="hidden" name="action" value="reject">
            <input type="text" name="reject_reason" class="border rounded-xl px-3 py-2 min-w-[280px]" placeholder="<?php echo h(approval_ko('%EB%B0%98%EB%A0%A4%EC%82%AC%EC%9C%A0%20%EC%9E%85%EB%A0%A5')); ?>" required>
            <button type="submit" class="px-6 py-3 rounded-xl bg-rose-600 text-white font-extrabold"><?php echo h(approval_ko('%EB%B0%98%EB%A0%A4%ED%95%98%EA%B8%B0')); ?></button>
        </form>
    <?php } else { ?>
        <?php
        if (isset($d['doc_type']) && $d['doc_type'] === 'leave') {
            render_approval_leave_document($content, $lines, 'view', array());
        } else if (isset($d['doc_type']) && $d['doc_type'] === 'unused_leave_notice') {
            render_approval_unused_leave_notice_document($content, $lines, 'view', array());
        } else if (isset($d['doc_type']) && $d['doc_type'] === 'unused_leave_plan') {
            render_approval_unused_leave_plan_document($content, $lines, 'view', array());
        } else {
            render_approval_proposal_document($content, $lines, 'view', $filesByType, array());
        }
        ?>
    <?php } ?>

    <?php if (count($references) > 0) { ?>
        <div class="bg-white rounded-2xl border p-4 no-print">
            <h3 class="font-extrabold mb-2"><?php echo h(approval_ko('%EC%B0%B8%EC%A1%B0%EC%9E%90')); ?></h3>
            <div class="flex flex-wrap gap-2">
                <?php for ($i = 0; $i < count($references); $i++) { ?>
                    <span class="px-3 py-1 rounded-full bg-cyan-50 text-cyan-800 border border-cyan-100 text-sm">
                        <?php echo h($references[$i]['employee_name']); ?>
                        <?php if (isset($references[$i]['employee_department']) && trim((string)$references[$i]['employee_department']) !== '') { ?>
                            / <?php echo h($references[$i]['employee_department']); ?>
                        <?php } ?>
                    </span>
                <?php } ?>
            </div>
        </div>
    <?php } ?>

    <div class="bg-white rounded-2xl border p-4 no-print">
        <h3 class="font-extrabold mb-2"><?php echo h(approval_ko('%EA%B2%B0%EC%9E%AC%20%EC%9D%B4%EB%A0%A5')); ?></h3>
        <?php if (count($approvalLogs) === 0) { ?>
            <div class="text-sm text-gray-500"><?php echo h(approval_ko('%EA%B2%B0%EC%9E%AC%20%EC%9D%B4%EB%A0%A5%EC%9D%B4%20%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.')); ?></div>
        <?php } else { ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm border-collapse">
                    <thead>
                        <tr class="bg-gray-50">
                            <th class="border px-2 py-1 text-left"><?php echo h(approval_ko('%EC%9D%BC%EC%8B%9C')); ?></th>
                            <th class="border px-2 py-1 text-left"><?php echo h(approval_ko('%EB%8B%A8%EA%B3%84')); ?></th>
                            <th class="border px-2 py-1 text-left"><?php echo h(approval_ko('%EC%B2%98%EB%A6%AC%EC%9E%90')); ?></th>
                            <th class="border px-2 py-1 text-left"><?php echo h(approval_ko('%EC%B2%98%EB%A6%AC%EB%82%B4%EC%9A%A9')); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php for ($li = 0; $li < count($approvalLogs); $li++) {
                            $log = $approvalLogs[$li];
                            $roleLabel = isset($log['role_type']) && trim((string)$log['role_type']) !== '' ? approval_role_label($log['role_type']) : '-';
                            $actor = isset($log['actor_name']) && trim((string)$log['actor_name']) !== '' ? trim((string)$log['actor_name']) : '-';
                            $actionType = isset($log['action_type']) ? strtoupper(trim((string)$log['action_type'])) : '';
                            $actionText = approval_status_label($actionType);
                            if ($actionType === 'APPROVE') {
                                $actionText = approval_ko('%EC%8A%B9%EC%9D%B8');
                            } else if ($actionType === 'REJECT') {
                                $actionText = approval_ko('%EB%B0%98%EB%A0%A4');
                            }
                            $note = isset($log['action_note']) ? trim((string)$log['action_note']) : '';
                        ?>
                            <tr>
                                <td class="border px-2 py-1"><?php echo h(isset($log['created_at']) ? $log['created_at'] : ''); ?></td>
                                <td class="border px-2 py-1"><?php echo h($roleLabel); ?></td>
                                <td class="border px-2 py-1"><?php echo h($actor); ?></td>
                                <td class="border px-2 py-1"><?php echo h($actionText . ($note !== '' ? ' - ' . $note : '')); ?></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        <?php } ?>
    </div>

    <?php if (isset($d['doc_type']) && $d['doc_type'] === 'leave' && approval_table_exists($pdo, 'cpms_approval_leave_deductions')) {
        $dd = $pdo->prepare("SELECT * FROM cpms_approval_leave_deductions WHERE document_id=:id LIMIT 1");
        $dd->execute(array(':id' => $id));
        $dr = $dd->fetch(PDO::FETCH_ASSOC);
    ?>
        <div class="bg-white rounded-2xl border p-4 mt-3 no-print">
            <h3 class="font-extrabold"><?php echo h(approval_ko('%ED%9C%B4%EA%B0%80%20%EC%B0%A8%EA%B0%90')); ?></h3>
            <?php if ($dr) { ?>
                <div><?php echo h(approval_ko('%ED%9C%B4%EA%B0%80%20%EC%B0%A8%EA%B0%90%20%EC%99%84%EB%A3%8C')); ?></div>
                <div><?php echo h(approval_ko('%EC%A2%85%EB%A5%98')); ?>: <?php echo h($dr['leave_type']); ?> / <?php echo h(approval_ko('%EC%B0%A8%EA%B0%90')); ?>: <?php echo h((string)$dr['deduct_amount']); ?></div>
                <div><?php echo h(approval_ko('%EC%B0%A8%EA%B0%90%20%EC%A0%84')); ?>: <?php echo h((string)$dr['balance_before']); ?> / <?php echo h(approval_ko('%EC%B0%A8%EA%B0%90%20%ED%9B%84')); ?>: <?php echo h((string)$dr['balance_after']); ?></div>
                <div><?php echo h(approval_ko('%EC%B0%A8%EA%B0%90%EC%9D%BC%EC%8B%9C')); ?>: <?php echo h($dr['deducted_at']); ?></div>
                <div class="text-sm text-gray-600 mt-2"><?php echo h($dr['note']); ?></div>
            <?php } else { ?>
                <div><?php echo h(approval_ko('%EC%B5%9C%EC%A2%85%20%EC%8A%B9%EC%9D%B8%20%ED%9B%84%20%EC%B0%A8%EA%B0%90%20%EC%98%88%EC%A0%95')); ?></div>
            <?php } ?>
        </div>
    <?php } ?>
</div>
