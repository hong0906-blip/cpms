<?php
/**
 * 파일 경로: C:\www\cpms\app\views\public_mail\detail_fragment.php
 * 메일 본문과 첨부파일만 비동기로 출력합니다. PHP 5.6 호환 코드입니다.
 * CPMS_PUBLIC_MAIL_VERSION: 1.7.21
 */
if (!isset($esc) || !is_callable($esc)) {
    $esc = function ($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };
}
$attachments = isset($detail['attachments']) && is_array($detail['attachments']) ? $detail['attachments'] : array();
$driveRecords = isset($detail['drive_records']) && is_array($detail['drive_records']) ? $detail['drive_records'] : array();
$bodyHtml = isset($detail['body_html']) ? (string)$detail['body_html'] : '';
$bodyText = isset($detail['body_text']) ? trim((string)$detail['body_text']) : '';
$bodyDocumentHtml = isset($detail['body_document_html']) ? (string)$detail['body_document_html'] : '';
$bodyHtmlSource = isset($detail['body_html_source']) ? (string)$detail['body_html_source'] : '';
$htmlCandidateCount = (isset($detail['html_part_count']) ? (int)$detail['html_part_count'] : 0)
    + (isset($detail['loose_html_candidate_count']) ? (int)$detail['loose_html_candidate_count'] : 0);
$rawMessageBytes = isset($detail['raw_message_bytes']) ? (int)$detail['raw_message_bytes'] : 0;
$rawOriginalStatus = isset($detail['raw_original_status']) ? (string)$detail['raw_original_status'] : '';
$bodyFallbackText = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($bodyHtml), ENT_QUOTES, 'UTF-8')));
$bodyFallbackHtml = $bodyText !== ''
    ? '<div class="pm-plain-mail">' . nl2br(call_user_func($esc, $bodyText)) . '</div>'
    : ($bodyFallbackText !== ''
        ? '<div class="pm-plain-mail">' . nl2br(call_user_func($esc, $bodyFallbackText)) . '</div>'
        : '<div class="pm-empty-body">표시할 메일 본문이 없습니다.</div>');
$messageKey = isset($detail['message_key']) ? (string)$detail['message_key'] : '';
$baseUrl = isset($baseUrl) ? rtrim((string)$baseUrl, '/') : '';
?>
<div class="pm-detail-fragment" data-detail-fragment data-message-key="<?php echo call_user_func($esc, $messageKey); ?>">
    <div class="pm-detail-fragment-toolbar">
        <span>본문 준비: <?php echo call_user_func($esc, isset($detail['body_cache_updated_at']) && $detail['body_cache_updated_at'] !== '' ? $detail['body_cache_updated_at'] : '방금'); ?>
            · <?php echo $rawOriginalStatus === 'verified' ? '원문 EML 전체' : ($rawOriginalStatus === 'skipped_large' ? '대용량 MIME 구조' : '빠른 MIME 본문'); ?>
        </span>
        <?php if ($bodyHtmlSource === 'text_fallback'): ?>
            <span class="pm-body-source-warning">HTML 후보 <?php echo number_format($htmlCandidateCount); ?>개 · 원문 <?php echo number_format($rawMessageBytes); ?> bytes</span>
        <?php endif; ?>
        <button type="button" class="pm-text-button" data-rebuild-body-cache data-message-key="<?php echo call_user_func($esc, $messageKey); ?>">EML 원문 다시 읽기</button>
    </div>


    <?php if (!empty($attachments)): ?>
        <div class="pm-attachments">
            <div class="pm-attachments-title">
                <strong>첨부파일</strong>
                <span>파일 원본은 CPMS 서버에 저장하지 않습니다.</span>
            </div>
            <?php foreach ($attachments as $attachment): ?>
                <?php
                $partId = isset($attachment['part_id']) ? (string)$attachment['part_id'] : '';
                $savedDriveRecord = null;
                foreach ($driveRecords as $candidateRecord) {
                    if (!is_array($candidateRecord)) continue;
                    if (isset($candidateRecord['message_key'],$candidateRecord['part_id']) && (string)$candidateRecord['message_key'] === $messageKey && (string)$candidateRecord['part_id'] === $partId) {
                        $savedDriveRecord = $candidateRecord; break;
                    }
                }
                $isLarge = !empty($attachment['is_large']);
                $fileName = isset($attachment['filename']) ? (string)$attachment['filename'] : '첨부파일';
                $fileSize = isset($attachment['size']) ? (int)$attachment['size'] : 0;
                ?>
                <div class="pm-attachment-row <?php echo $isLarge ? 'is-large' : ''; ?>">
                    <div class="pm-attachment-info">
                        <i data-lucide="<?php echo $isLarge ? 'hard-drive-download' : 'paperclip'; ?>"></i>
                        <div>
                            <span><?php echo call_user_func($esc, $fileName); ?></span>
                            <small><?php echo $isLarge ? '네이버 대용량 첨부' : ($fileSize > 0 ? number_format($fileSize) . ' bytes' : '용량 확인 중'); ?></small>
                        </div>
                    </div>
                    <div class="pm-attachment-actions">
                        <a class="pm-btn pm-btn-light" data-mail-attachment-download <?php echo $isLarge ? 'target="_blank" rel="noopener noreferrer"' : 'download="' . call_user_func($esc, $fileName) . '"'; ?> href="<?php echo call_user_func($esc, $baseUrl); ?>/public_mail_attachment.php?message=<?php echo rawurlencode($messageKey); ?>&amp;part=<?php echo rawurlencode($partId); ?>" aria-label="<?php echo call_user_func($esc, $fileName); ?> 내 PC로 다운로드">
                            <i data-lucide="download"></i> 내 PC로 다운로드
                        </a>
                        <button type="button" class="pm-btn pm-btn-drive" data-save-attachment-drive data-message-key="<?php echo call_user_func($esc,$messageKey); ?>" data-part-id="<?php echo call_user_func($esc,$partId); ?>" data-project-id="<?php echo call_user_func($esc,$selectedProjectId); ?>">
                            <i data-lucide="cloud-upload"></i> Google Drive에 저장
                        </button>
                        <?php if (is_array($savedDriveRecord) && !empty($savedDriveRecord['drive_web_view_link'])): ?>
                            <a class="pm-btn pm-btn-success" target="_blank" rel="noopener noreferrer" href="<?php echo call_user_func($esc,$savedDriveRecord['drive_web_view_link']); ?>">
                                <i data-lucide="external-link"></i> Drive에서 보기
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="pm-message-body-wrap">
        <?php if ($bodyDocumentHtml !== ''): ?>
            <iframe class="pm-message-document" data-mail-document title="메일 원문" sandbox="allow-same-origin allow-popups" referrerpolicy="no-referrer" srcdoc="<?php echo call_user_func($esc, $bodyDocumentHtml); ?>"></iframe>
        <?php else: ?>
            <div class="pm-message-body"><?php echo $bodyHtml !== '' ? $bodyHtml : $bodyFallbackHtml; ?></div>
        <?php endif; ?>
    </div>
</div>
