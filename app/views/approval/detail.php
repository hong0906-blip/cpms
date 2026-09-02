<?php
/*
 * 파일경로: app/views/approval/detail.php
 * 화면: 전자결재 상세
 * 추가: 첫 결재 전 수정 / 반려 후 수정 재상신 / 재상신 이력 연결
 * PHP 5.6 호환
 */
use App\Core\Db;

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/document_templates.php';
require_once __DIR__ . '/template_style.php';
require_once __DIR__ . '/template_proposal.php';
require_once __DIR__ . '/template_leave.php';
require_once __DIR__ . '/template_unused_leave.php';
require_once __DIR__ . '/resubmit_helpers.php';
require_once __DIR__ . '/../../services/ApprovalPdfService.php';

if (!function_exists('approval_detail_render_comment_items')) {
    function approval_detail_render_comment_items($approvalComments, $showEmpty)
    {
        $approvalComments = is_array($approvalComments) ? $approvalComments : array();
        if (count($approvalComments) === 0) {
            if ($showEmpty) {
                echo '<div class="text-sm text-gray-500">' . h(approval_ko('%EB%93%B1%EB%A1%9D%EB%90%9C%20%EC%8A%B9%EC%9D%B8%20%EC%9D%98%EA%B2%AC%EC%9D%B4%20%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.')) . '</div>';
            }
            return;
        }
        echo '<div class="space-y-2">';
        for ($ci = 0; $ci < count($approvalComments); $ci++) {
            $comment = $approvalComments[$ci];
            $role = isset($comment['role']) ? trim((string)$comment['role']) : '';
            $actor = isset($comment['actor']) ? trim((string)$comment['actor']) : '';
            $createdAt = isset($comment['created_at']) ? trim((string)$comment['created_at']) : '';
            $note = isset($comment['note']) ? trim((string)$comment['note']) : '';
            echo '<div class="rounded-xl border border-indigo-100 bg-indigo-50/40 p-3">';
            echo '<div class="flex flex-wrap items-center gap-2 text-xs text-gray-500">';
            echo '<span class="font-extrabold text-indigo-700">' . h($role !== '' ? approval_role_label($role) : '-') . '</span>';
            echo '<span>' . h($actor !== '' ? $actor : '-') . '</span>';
            if ($createdAt !== '') echo '<span>' . h($createdAt) . '</span>';
            echo '</div>';
            echo '<div class="mt-2 text-sm leading-6 text-gray-800">' . nl2br(h($note)) . '</div>';
            echo '</div>';
        }
        echo '</div>';
    }
}

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
if (!is_array($lines)) $lines = array();

$content = approval_parse_content(isset($d['content']) ? $d['content'] : '');
$filesByType = array();
$fileRows = array();
if (isset($d['doc_type']) && approval_is_proposal_doc_type($d['doc_type']) && approval_table_exists($pdo, 'cpms_approval_files')) {
    $fs = $pdo->prepare("SELECT * FROM cpms_approval_files WHERE document_id=:id ORDER BY id DESC");
    $fs->execute(array(':id' => $id));
    $fileRows = $fs->fetchAll(PDO::FETCH_ASSOC);
    if (is_array($fileRows)) {
        for ($i = 0; $i < count($fileRows); $i++) {
            $f = $fileRows[$i];
            $k = isset($f['file_type']) ? $f['file_type'] : '';
            if ($k !== '') {
                if (!isset($filesByType[$k]) || !is_array($filesByType[$k])) $filesByType[$k] = array();
                $filesByType[$k][] = $f;
            }
        }
    }
}

$needsDeferredDriveSync = false;
for ($driveFileIndex = 0; $driveFileIndex < count($fileRows); $driveFileIndex++) {
    $driveFileStatus = isset($fileRows[$driveFileIndex]['upload_status']) ? strtolower(trim((string)$fileRows[$driveFileIndex]['upload_status'])) : '';
    $driveLocalPath = isset($fileRows[$driveFileIndex]['file_path']) ? trim((string)$fileRows[$driveFileIndex]['file_path']) : '';
    if ((in_array($driveFileStatus, array('pending', 'failed'), true) && $driveLocalPath !== '') || ($driveFileStatus === 'uploaded' && $driveLocalPath !== '')) {
        $needsDeferredDriveSync = true;
        break;
    }
}

$detailDocStatus = isset($d['doc_status']) ? strtoupper(trim((string)$d['doc_status'])) : '';
$detailPdfFileId = isset($d['completed_pdf_drive_file_id']) ? trim((string)$d['completed_pdf_drive_file_id']) : '';
$detailPdfStatus = isset($d['completed_pdf_upload_status']) ? strtolower(trim((string)$d['completed_pdf_upload_status'])) : '';
$detailPdfVersion = isset($d['completed_pdf_render_version']) ? (int)$d['completed_pdf_render_version'] : 0;
$detailPdfNeedsCurrentRender = ($detailPdfFileId !== '' && $detailPdfVersion < cpms_approval_pdf_render_version());
$detailPdfExpectedSize = isset($d['completed_pdf_size']) ? (int)$d['completed_pdf_size'] : 0;
$detailPdfCacheMissing = ($detailPdfFileId !== '' && !$detailPdfNeedsCurrentRender && cpms_approval_pdf_cache_get_path($detailPdfFileId, $detailPdfExpectedSize) === '');
if (in_array($detailDocStatus, array('APPROVED', 'COMPLETED'), true)
    && (($detailPdfFileId === '' && in_array($detailPdfStatus, array('', 'pending', 'processing', 'failed'), true)) || $detailPdfNeedsCurrentRender || $detailPdfCacheMissing)) {
    $needsDeferredDriveSync = true;
}

$references = approval_fetch_references($pdo, $id);
$approvalLogs = array();
if (approval_table_exists($pdo, 'cpms_approval_logs')) {
    try {
        $logSt = $pdo->prepare("SELECT l.*, al.role_type, al.approver_name AS line_approver_name FROM cpms_approval_logs l LEFT JOIN cpms_approval_lines al ON al.id=l.line_id WHERE l.document_id=:id ORDER BY l.created_at ASC, l.id ASC");
        $logSt->execute(array(':id' => $id));
        $approvalLogs = $logSt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($approvalLogs)) $approvalLogs = array();
    } catch (Exception $e) {
        $approvalLogs = array();
    }
}

$canCancel = approval_is_document_owner($pdo, $d, $u) && approval_can_cancel_document($d);
$canCancelApprovedLeave = approval_can_cancel_approved_leave($pdo, $d, $u);
$canDelete = approval_can_delete_document($pdo, $d, $u);

/* 수정 / 재상신 권한 */
$canEditBeforeFirstDecision = approval_resubmit_can_edit_before_first_decision($pdo, $d, $u);
$resubmittedChild = ($detailDocStatus === 'REJECTED') ? approval_resubmit_find_child($pdo, $id) : null;
$canResubmitRejected = approval_resubmit_can_resubmit($pdo, $d, $u) && !$resubmittedChild;
$resubmitSourceId = isset($content['resubmit_source_id']) ? (int)$content['resubmit_source_id'] : 0;
$resubmitRootId = isset($content['resubmit_root_id']) ? (int)$content['resubmit_root_id'] : 0;
$resubmitRevision = isset($content['resubmit_revision']) ? (int)$content['resubmit_revision'] : 0;

$uid = approval_current_employee_id($pdo, $u);
$userEmail = approval_current_user_email($u);
$userName = approval_current_user_name($u);
$isCeoUser = approval_is_ceo_user($pdo, $u);
$myPendingLine = null;
$finalActionLineOrder = 0;
for ($i = 0; $i < count($lines); $i++) {
    $line = $lines[$i];
    $lineStatus = isset($line['line_status']) ? strtoupper(trim((string)$line['line_status'])) : '';
    $isDelegated = ($lineStatus === 'DELEGATED') || (isset($line['is_delegated']) && (int)$line['is_delegated'] === 1);
    $isNonActionable = ($isDelegated || $lineStatus === 'SKIPPED');
    $lineOrder = isset($line['line_order']) ? (int)$line['line_order'] : 0;
    if (!$isNonActionable && $lineOrder > $finalActionLineOrder) $finalActionLineOrder = $lineOrder;
    $isPending = (isset($line['line_status']) && $line['line_status'] === 'PENDING');
    $isMineById = ($uid > 0 && isset($line['approver_id']) && (int)$line['approver_id'] === $uid);
    $isMineByEmail = ($userEmail !== '' && isset($line['approver_email']) && strtolower(trim((string)$line['approver_email'])) === strtolower($userEmail));
    $isMineByName = ($userName !== '' && isset($line['approver_name']) && trim((string)$line['approver_name']) === $userName);
    if ($myPendingLine === null && $isPending && !$isDelegated && ($isMineById || $isMineByEmail || $isMineByName)) $myPendingLine = $line;
}

$canCeoDirectApprove = ($detailDocStatus === 'PENDING' && $isCeoUser);
$canDecide = ($detailDocStatus === 'PENDING' && ($myPendingLine || $canCeoDirectApprove));
$canReject = ($detailDocStatus === 'PENDING' && $myPendingLine);
$myPendingLineOrder = ($myPendingLine && isset($myPendingLine['line_order'])) ? (int)$myPendingLine['line_order'] : 0;
$myPendingRole = ($myPendingLine && isset($myPendingLine['role_type'])) ? $myPendingLine['role_type'] : '';
$isFinalCeoDecision = ($canCeoDirectApprove || ($canDecide && approval_role_is_ceo($myPendingRole) && $myPendingLineOrder > 0 && $myPendingLineOrder === $finalActionLineOrder));
$isRecipientEditablePlan = ($canDecide && !$canCeoDirectApprove && isset($d['doc_type']) && $d['doc_type'] === 'unused_leave_plan');
$detailDocType = isset($d['doc_type']) ? strtolower(trim((string)$d['doc_type'])) : '';
$approvalCommentsEnabled = ($detailDocType === 'leave' || approval_is_proposal_doc_type($detailDocType));
$approvalComments = array();
$leaveRestoreLog = null;
for ($restoreIndex = count($approvalLogs) - 1; $restoreIndex >= 0; $restoreIndex--) {
    $restoreActionType = isset($approvalLogs[$restoreIndex]['action_type']) ? strtoupper(trim((string)$approvalLogs[$restoreIndex]['action_type'])) : '';
    if ($restoreActionType === 'LEAVE_RESTORE') {
        $leaveRestoreLog = $approvalLogs[$restoreIndex];
        break;
    }
}
if ($approvalCommentsEnabled) {
    for ($ci = 0; $ci < count($approvalLogs); $ci++) {
        $log = $approvalLogs[$ci];
        $actionType = isset($log['action_type']) ? strtoupper(trim((string)$log['action_type'])) : '';
        $note = isset($log['action_note']) ? trim((string)$log['action_note']) : '';
        if ($actionType !== 'APPROVE' || $note === '') continue;
        $actor = isset($log['actor_name']) && trim((string)$log['actor_name']) !== '' ? trim((string)$log['actor_name']) : '';
        if ($actor === '' && isset($log['line_approver_name'])) $actor = trim((string)$log['line_approver_name']);
        $approvalComments[] = array(
            'role' => isset($log['role_type']) ? $log['role_type'] : '',
            'actor' => $actor,
            'created_at' => isset($log['created_at']) ? $log['created_at'] : '',
            'note' => $note
        );
    }
}
$showApprovalCommentModal = ($approvalCommentsEnabled && $canDecide && count($approvalComments) > 0);
?>
<div class="space-y-4">
    <div class="no-print flex flex-wrap gap-2">
        <a href="javascript:history.back()" class="px-3 py-2 bg-gray-100 rounded"><?php echo h(approval_ko('%EB%92%A4%EB%A1%9C%EA%B0%80%EA%B8%B0')); ?></a>
        <a href="?r=approval_home" class="px-3 py-2 bg-gray-100 rounded"><?php echo h(approval_ko('%EB%AA%A9%EB%A1%9D%EC%9C%BC%EB%A1%9C')); ?></a>
        <a href="?r=approval_print&id=<?php echo (int)$id; ?>" class="px-3 py-2 bg-indigo-100 rounded"><?php echo h(approval_ko('%EC%B6%9C%EB%A0%A5')); ?></a>

        <?php if ($canEditBeforeFirstDecision) { ?>
            <a href="?r=approval_create&type=<?php echo h($detailDocType); ?>&edit_id=<?php echo (int)$id; ?>" class="px-3 py-2 bg-blue-600 text-white rounded font-extrabold">수정</a>
        <?php } ?>
        <?php if ($canResubmitRejected) { ?>
            <a href="?r=approval_create&type=<?php echo h($detailDocType); ?>&resubmit_id=<?php echo (int)$id; ?>" class="px-3 py-2 bg-amber-500 text-white rounded font-extrabold">수정 후 재상신</a>
        <?php } else if ($resubmittedChild && isset($resubmittedChild['id'])) { ?>
            <a href="?r=approval_detail&id=<?php echo (int)$resubmittedChild['id']; ?>" class="px-3 py-2 bg-emerald-600 text-white rounded font-extrabold">재상신 문서 보기</a>
        <?php } ?>

        <?php if ($canCancel) { ?>
            <form method="post" action="?r=approval_cancel">
                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
                <button class="px-3 py-2 bg-rose-100 rounded"><?php echo h(approval_ko('%EC%9A%94%EC%B2%AD%EC%B7%A8%EC%86%8C')); ?></button>
            </form>
        <?php } ?>
        <?php if ($canCancelApprovedLeave) { ?>
            <form method="post" action="?r=approval_cancel" onsubmit="return confirm('<?php echo h(approval_ko('%EC%9D%B4%20%ED%9C%B4%EA%B0%80%EA%B3%84%EC%9D%98%20%EC%8A%B9%EC%9D%B8%EC%9D%84%20%EC%B7%A8%EC%86%8C%ED%95%98%EA%B3%A0%20%EC%B0%A8%EA%B0%90%EB%90%9C%20%ED%9C%B4%EA%B0%80%EB%A5%BC%20%EB%B3%B5%EA%B5%AC%ED%95%A0%EA%B9%8C%EC%9A%94%3F')); ?>');">
                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
                <button class="px-3 py-2 bg-amber-100 text-amber-900 rounded font-bold"><?php echo h(approval_ko('%EC%8A%B9%EC%9D%B8%EC%B7%A8%EC%86%8C')); ?></button>
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
        <?php if ($resubmitSourceId > 0) { ?>
            <div class="mt-3 flex flex-wrap items-center gap-2 rounded-xl border border-blue-100 bg-blue-50 px-3 py-2 text-sm text-blue-900">
                <span class="font-extrabold"><?php echo $resubmitRevision > 0 ? (int)$resubmitRevision . '차 재상신 문서' : '재상신 문서'; ?></span>
                <span>이전 반려문서:</span>
                <a class="font-extrabold underline" href="?r=approval_detail&id=<?php echo (int)$resubmitSourceId; ?>">#<?php echo (int)$resubmitSourceId; ?> 보기</a>
                <?php if ($resubmitRootId > 0 && $resubmitRootId !== $resubmitSourceId) { ?>
                    <span>/</span><a class="font-bold underline" href="?r=approval_detail&id=<?php echo (int)$resubmitRootId; ?>">최초 문서 #<?php echo (int)$resubmitRootId; ?></a>
                <?php } ?>
            </div>
        <?php } ?>
    </div>

    <?php echo cpms_approval_pdf_links_html($d); ?>

    <?php if ($detailDocStatus === 'PENDING' && !$isRecipientEditablePlan) { ?>
        <div class="no-print cpms-approval-decision-panel <?php echo ((isset($d['doc_type']) && (string)$d['doc_type'] === 'leave') || $isFinalCeoDecision) ? '' : 'cpms-mobile-hide'; ?> bg-white rounded-2xl border p-4">
            <?php if ($canDecide) { ?>
                <div class="space-y-4">
                    <?php if ($approvalCommentsEnabled) { ?>
                        <div>
                            <div class="font-extrabold text-gray-900 mb-2"><?php echo h(approval_ko('%EC%8A%B9%EC%9D%B8%20%EC%9D%98%EA%B2%AC')); ?></div>
                            <?php approval_detail_render_comment_items($approvalComments, true); ?>
                        </div>
                    <?php } ?>
                    <?php if ($canCeoDirectApprove) { ?>
                        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-800"><?php echo h(approval_ko('%EB%8C%80%ED%91%9C%EB%8A%94%20%ED%98%84%EC%9E%AC%20%EA%B2%B0%EC%9E%AC%20%EC%88%9C%EC%84%9C%EC%99%80%20%EA%B4%80%EA%B3%84%EC%97%86%EC%9D%B4%20%EB%B0%94%EB%A1%9C%20%EC%B5%9C%EC%A2%85%20%EC%8A%B9%EC%9D%B8%ED%95%A0%20%EC%88%98%20%EC%9E%88%EC%8A%B5%EB%8B%88%EB%8B%A4.')); ?></div>
                    <?php } ?>
                    <div class="flex flex-col lg:flex-row lg:items-start gap-3">
                        <form method="post" action="?r=approval_decide" class="flex-1 space-y-2">
                            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                            <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
                            <input type="hidden" name="action" value="approve">
                            <?php if ($approvalCommentsEnabled) { ?>
                                <textarea name="approval_comment" rows="3" class="w-full border rounded-xl px-3 py-2" placeholder="<?php echo h(approval_ko('%EC%8A%B9%EC%9D%B8%20%EC%8B%9C%20%EB%82%A8%EA%B8%B8%20%EC%9D%98%EA%B2%AC%EC%9D%84%20%EC%9E%85%EB%A0%A5%ED%95%98%EC%84%B8%EC%9A%94.')); ?>"></textarea>
                            <?php } ?>
                            <button type="submit" class="px-6 py-3 rounded-xl bg-emerald-600 text-white font-extrabold"<?php echo $isFinalCeoDecision ? ' onclick="return confirm(\'' . h(approval_ko('%EB%8C%80%ED%91%9C%EC%8A%B9%EC%9D%B8%20%EC%B2%98%EB%A6%AC%ED%95%98%EC%8B%9C%EA%B2%A0%EC%8A%B5%EB%8B%88%EA%B9%8C%3F')) . '\');"' : ''; ?>><?php echo h(approval_ko('%EC%8A%B9%EC%9D%B8%ED%95%98%EA%B8%B0')); ?></button>
                        </form>
                        <?php if ($canReject) { ?>
                            <form method="post" action="?r=approval_decide" class="flex flex-wrap gap-2 items-center">
                                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
                                <input type="hidden" name="action" value="reject">
                                <input type="text" name="reject_reason" class="border rounded-xl px-3 py-2 min-w-[280px]" placeholder="<?php echo h(approval_ko('%EB%B0%98%EB%A0%A4%EC%82%AC%EC%9C%A0%20%EC%9E%85%EB%A0%A5')); ?>" required>
                                <button type="submit" class="px-6 py-3 rounded-xl bg-rose-600 text-white font-extrabold"><?php echo h(approval_ko('%EB%B0%98%EB%A0%A4%ED%95%98%EA%B8%B0')); ?></button>
                            </form>
                        <?php } ?>
                    </div>
                </div>
            <?php } else { ?>
                <?php if ($approvalCommentsEnabled && count($approvalComments) > 0) { ?>
                    <div class="mb-4">
                        <div class="font-extrabold text-gray-900 mb-2"><?php echo h(approval_ko('%EC%8A%B9%EC%9D%B8%20%EC%9D%98%EA%B2%AC')); ?></div>
                        <?php approval_detail_render_comment_items($approvalComments, false); ?>
                    </div>
                <?php } ?>
                <div class="text-sm text-gray-600"><?php echo h(approval_ko('%ED%98%84%EC%9E%AC%20%EA%B2%B0%EC%9E%AC%EC%9E%90%EB%A7%8C%20%EC%8A%B9%EC%9D%B8%2F%EB%B0%98%EB%A0%A4%ED%95%A0%20%EC%88%98%20%EC%9E%88%EC%8A%B5%EB%8B%88%EB%8B%A4.')); ?></div>
            <?php } ?>
        </div>
    <?php } ?>

    <?php if ($approvalCommentsEnabled && count($approvalComments) > 0 && (!isset($d['doc_status']) || $d['doc_status'] !== 'PENDING' || $isRecipientEditablePlan)) { ?>
        <div class="no-print bg-white rounded-2xl border p-4">
            <div class="font-extrabold text-gray-900 mb-2"><?php echo h(approval_ko('%EC%8A%B9%EC%9D%B8%20%EC%9D%98%EA%B2%AC')); ?></div>
            <?php approval_detail_render_comment_items($approvalComments, false); ?>
        </div>
    <?php } ?>

    <?php if ($showApprovalCommentModal) { ?>
        <div id="modal-approvalCommentsAuto" class="fixed inset-0 z-50 hidden">
            <div class="absolute inset-0 bg-black/45" data-approval-comments-close></div>
            <div class="absolute inset-0 flex items-center justify-center p-4">
                <div class="w-full max-w-3xl max-h-[88vh] overflow-hidden rounded-3xl bg-white shadow-2xl border border-gray-100">
                    <div class="flex items-center justify-between px-6 py-5 border-b border-gray-100">
                        <div>
                            <div class="text-2xl font-extrabold text-gray-900"><?php echo h(approval_ko('%EC%8A%B9%EC%9D%B8%20%EC%9D%98%EA%B2%AC')); ?></div>
                            <div class="text-sm text-gray-500 mt-1"><?php echo h(approval_ko('%EC%9D%B4%EC%A0%84%20%EA%B2%B0%EC%9E%AC%EC%9E%90%EA%B0%80%20%EB%82%A8%EA%B8%B4%20%EC%9D%98%EA%B2%AC')); ?></div>
                        </div>
                        <button type="button" class="p-3 rounded-2xl hover:bg-gray-100" data-approval-comments-close><?php echo h(approval_ko('%EB%8B%AB%EA%B8%B0')); ?></button>
                    </div>
                    <div class="p-5 md:p-6 overflow-y-auto max-h-[66vh]"><?php approval_detail_render_comment_items($approvalComments, false); ?></div>
                    <div class="px-6 py-4 border-t border-gray-100 bg-gray-50 flex justify-end">
                        <button type="button" class="px-5 py-3 rounded-2xl bg-gray-900 text-white font-extrabold" data-approval-comments-close><?php echo h(approval_ko('%EB%8B%AB%EA%B8%B0')); ?></button>
                    </div>
                </div>
            </div>
        </div>
        <script>
        (function(){
            var modal = document.getElementById('modal-approvalCommentsAuto');
            function closeModal(){if(modal)modal.classList.add('hidden');document.body.classList.remove('overflow-hidden');}
            function openModal(){if(!modal)return;modal.classList.remove('hidden');document.body.classList.add('overflow-hidden');}
            var closeButtons=document.querySelectorAll('[data-approval-comments-close]');
            for(var i=0;i<closeButtons.length;i++){closeButtons[i].addEventListener('click',function(e){e.preventDefault();closeModal();});}
            document.addEventListener('keydown',function(e){if((e.key==='Escape'||e.keyCode===27)&&modal&&!modal.classList.contains('hidden'))closeModal();});
            openModal();
        })();
        </script>
    <?php } ?>

    <?php if (isset($d['doc_status']) && $d['doc_status'] === 'REJECTED') { ?>
        <div class="bg-rose-50 border border-rose-200 rounded-2xl p-4">
            <?php echo h(approval_ko('%EB%B0%98%EB%A0%A4%EB%8B%A8%EA%B3%84')); ?>: <?php echo h(approval_role_label(isset($d['rejected_step']) ? $d['rejected_step'] : '')); ?>
            / <?php echo h(approval_ko('%EB%B0%98%EB%A0%A4%EC%9D%BC%EC%8B%9C')); ?>: <?php echo h(isset($d['updated_at']) ? $d['updated_at'] : ''); ?>
            / <?php echo h(approval_ko('%EB%B0%98%EB%A0%A4%EC%82%AC%EC%9C%A0')); ?>: <?php echo h(isset($d['reject_reason']) ? $d['reject_reason'] : ''); ?>
            <?php if ($resubmittedChild && isset($resubmittedChild['id'])) { ?>
                <div class="mt-3"><a class="inline-flex px-4 py-2 rounded-xl bg-emerald-600 text-white font-extrabold" href="?r=approval_detail&id=<?php echo (int)$resubmittedChild['id']; ?>">재상신된 문서 #<?php echo (int)$resubmittedChild['id']; ?> 보기</a></div>
            <?php } ?>
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
                        <?php if (isset($references[$i]['employee_department']) && trim((string)$references[$i]['employee_department']) !== '') { ?> / <?php echo h($references[$i]['employee_department']); ?><?php } ?>
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
                    <thead><tr class="bg-gray-50">
                        <th class="border px-2 py-1 text-left"><?php echo h(approval_ko('%EC%9D%BC%EC%8B%9C')); ?></th>
                        <th class="border px-2 py-1 text-left"><?php echo h(approval_ko('%EB%8B%A8%EA%B3%84')); ?></th>
                        <th class="border px-2 py-1 text-left"><?php echo h(approval_ko('%EC%B2%98%EB%A6%AC%EC%9E%90')); ?></th>
                        <th class="border px-2 py-1 text-left"><?php echo h(approval_ko('%EC%B2%98%EB%A6%AC%EB%82%B4%EC%9A%A9')); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php for ($li = 0; $li < count($approvalLogs); $li++) {
                        $log = $approvalLogs[$li];
                        $roleLabel = isset($log['role_type']) && trim((string)$log['role_type']) !== '' ? approval_role_label($log['role_type']) : '-';
                        $actor = isset($log['actor_name']) && trim((string)$log['actor_name']) !== '' ? trim((string)$log['actor_name']) : '-';
                        $actionType = isset($log['action_type']) ? strtoupper(trim((string)$log['action_type'])) : '';
                        $actionText = approval_status_label($actionType);
                        if ($actionType === 'APPROVE') $actionText = approval_ko('%EC%8A%B9%EC%9D%B8');
                        else if ($actionType === 'CEO_DIRECT_APPROVE') $actionText = approval_status_label('CEO_DIRECT_APPROVE');
                        else if ($actionType === 'REJECT') $actionText = approval_ko('%EB%B0%98%EB%A0%A4');
                        else if ($actionType === 'APPROVED_LEAVE_CANCEL') $actionText = approval_ko('%EC%8A%B9%EC%9D%B8%EC%B7%A8%EC%86%8C');
                        else if ($actionType === 'LEAVE_RESTORE') $actionText = approval_ko('%ED%9C%B4%EA%B0%80%EB%B3%B5%EA%B5%AC');
                        else if ($actionType === 'EDIT') $actionText = '수정';
                        else if ($actionType === 'RESUBMIT') $actionText = '재상신';
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
            <?php if ($leaveRestoreLog) { ?>
                <div class="mt-2 rounded-xl border border-amber-200 bg-amber-50 p-3 text-amber-900">
                    <div class="font-extrabold"><?php echo h(approval_ko('%EC%8A%B9%EC%9D%B8%20%EC%B7%A8%EC%86%8C%20%EB%B0%8F%20%ED%9C%B4%EA%B0%80%20%EB%B3%B5%EA%B5%AC%20%EC%99%84%EB%A3%8C')); ?></div>
                    <div class="mt-1 text-sm"><?php echo h(isset($leaveRestoreLog['action_note']) ? $leaveRestoreLog['action_note'] : ''); ?></div>
                    <div class="mt-1 text-xs"><?php echo h(isset($leaveRestoreLog['created_at']) ? $leaveRestoreLog['created_at'] : ''); ?></div>
                </div>
            <?php } ?>
            <?php if ($dr) { ?>
                <div><?php echo h($leaveRestoreLog ? approval_ko('%EA%B8%B0%EC%A1%B4%20%ED%9C%B4%EA%B0%80%20%EC%B0%A8%EA%B0%90%20%EA%B8%B0%EB%A1%9D') : approval_ko('%ED%9C%B4%EA%B0%80%20%EC%B0%A8%EA%B0%90%20%EC%99%84%EB%A3%8C')); ?></div>
                <div><?php echo h(approval_ko('%EC%A2%85%EB%A5%98')); ?>: <?php echo h($dr['leave_type']); ?> / <?php echo h(approval_ko('%EC%B0%A8%EA%B0%90')); ?>: <?php echo h((string)$dr['deduct_amount']); ?></div>
                <div><?php echo h(approval_ko('%EC%B0%A8%EA%B0%90%20%EC%A0%84')); ?>: <?php echo h((string)$dr['balance_before']); ?> / <?php echo h(approval_ko('%EC%B0%A8%EA%B0%90%20%ED%9B%84')); ?>: <?php echo h((string)$dr['balance_after']); ?></div>
                <div><?php echo h(approval_ko('%EC%B0%A8%EA%B0%90%EC%9D%BC%EC%8B%9C')); ?>: <?php echo h($dr['deducted_at']); ?></div>
                <div class="text-sm text-gray-600 mt-2"><?php echo h($dr['note']); ?></div>
            <?php } else if (!$leaveRestoreLog) { ?>
                <div><?php echo h(approval_ko('%EC%B5%9C%EC%A2%85%20%EC%8A%B9%EC%9D%B8%20%ED%9B%84%20%EC%B0%A8%EA%B0%90%20%EC%98%88%EC%A0%95')); ?></div>
            <?php } ?>
        </div>
    <?php } ?>
</div>
<?php if ($needsDeferredDriveSync) { ?>
<script>
(function () {
    if (!window.fetch) return;
    var body = <?php echo json_encode('id=' . (int)$id . '&_csrf=' . rawurlencode(csrf_token())); ?>;
    window.setTimeout(function () {
        window.fetch('?r=approval_deferred_sync', {
            method: 'POST',
            credentials: 'same-origin',
            keepalive: true,
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body: body
        }).then(function (response) {
            if (!response.ok) return null;
            return response.json();
        }).then(function (result) {
            if (!result || !result.ok || !result.completed_pdf || !result.completed_pdf.ok) return;
            if (!result.completed_pdf.skipped) window.location.reload();
        }).catch(function () {});
    }, 50);
}());
</script>
<?php } ?>
