<?php
/**
 * 파일 경로: C:\www\cpms\app\views\public_mail\detail_fragment.php
 * 메일 본문과 첨부파일만 비동기로 출력합니다. PHP 5.6 호환 코드입니다.
 *
 * v1.7.23
 * - 국세청/홈택스 전자세금계산서 메일은 실제 안내메일 본문을 먼저 표시합니다.
 * - NTS_eTaxInvoice.html은 빈 안전보기 화면을 거치지 않고 바로 다운로드합니다.
 * - 다운로드한 국세청 HTML은 사용자가 직접 열고 사업자등록번호를 입력해 확인합니다.
 * - 일반 메일/일반 첨부/Google Drive 저장 동작은 기존 그대로 유지합니다.
 *
 * CPMS_PUBLIC_MAIL_VERSION: 1.7.23
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

/** 국세청 NTS_eTaxInvoice HTML 첨부인지 화면에서만 가볍게 판정합니다. */
$isNtsHtmlFilename = function ($filename) {
    $filename = strtolower(trim((string)$filename));
    if ($filename === '') return false;
    if (!preg_match('/\.html?$/i', $filename)) return false;
    return strpos($filename, 'nts_etaxinvoice') !== false
        || strpos($filename, 'nts-etaxinvoice') !== false;
};

/*
 * 국세청 전자세금계산서 메일 여부를 발신자와 제목 중심으로 판정합니다.
 * 단순히 본문에 '국세청'이라는 글자가 있다는 이유만으로 일반 메일까지 바꾸지 않습니다.
 */
$subjectText = isset($detail['subject']) ? (string)$detail['subject'] : '';
$fromText = isset($detail['from_text']) ? (string)$detail['from_text'] : '';
$fromEmail = isset($detail['from_email']) ? (string)$detail['from_email'] : '';
$taxInvoiceSignal = strtolower($subjectText . ' ' . $fromText . ' ' . $fromEmail . ' ' . $bodyText);
$isHometaxSender = strpos($taxInvoiceSignal, 'hometax.go.kr') !== false
    || strpos($taxInvoiceSignal, 'hometax') !== false
    || strpos($taxInvoiceSignal, '홈택스') !== false;
$isTaxInvoiceSubject = strpos($taxInvoiceSignal, '세금계산서') !== false
    || strpos($taxInvoiceSignal, '전자세금') !== false
    || strpos($taxInvoiceSignal, 'nts_etaxinvoice') !== false
    || strpos($taxInvoiceSignal, 'nts-etaxinvoice') !== false;
$isNtsTaxInvoiceMail = $isHometaxSender && $isTaxInvoiceSubject;

/*
 * 새 방식에서는 NTS HTML을 전용 박스 하나로만 표시합니다.
 * 캐시가 새로 만들어져 NTS 파일이 일반 첨부목록에도 들어오더라도 중복 표시하지 않습니다.
 */
$normalAttachments = array();
$ntsAttachmentPartId = '';
foreach ($attachments as $attachmentItem) {
    if (!is_array($attachmentItem)) continue;
    $candidateFilename = isset($attachmentItem['filename']) ? (string)$attachmentItem['filename'] : '';
    if (call_user_func($isNtsHtmlFilename, $candidateFilename)) {
        if ($ntsAttachmentPartId === '' && isset($attachmentItem['part_id'])) {
            $ntsAttachmentPartId = (string)$attachmentItem['part_id'];
        }
        $isNtsTaxInvoiceMail = true;
        continue;
    }
    $normalAttachments[] = $attachmentItem;
}

$taxInvoiceDownloadUrl = $baseUrl . '/public_mail_attachment.php?message=' . rawurlencode($messageKey)
    . '&view=tax_invoice_download';
$taxInvoiceBodyUrl = $baseUrl . '/public_mail_attachment.php?message=' . rawurlencode($messageKey)
    . '&view=tax_invoice_mail_body';
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

    <?php if ($isNtsTaxInvoiceMail && $messageKey !== ''): ?>
        <!-- 국세청 메일은 NTS 보안 HTML이 아니라 실제 안내메일 본문을 먼저 표시합니다. -->
        <div class="pm-message-body-wrap">
            <iframe class="pm-message-document"
                    data-mail-document
                    title="국세청 전자세금계산서 안내메일 본문"
                    sandbox="allow-same-origin allow-popups"
                    referrerpolicy="no-referrer"
                    src="<?php echo call_user_func($esc, $taxInvoiceBodyUrl); ?>"></iframe>
        </div>

        <!-- 국세청 NTS_eTaxInvoice.html은 중간 보기 화면 없이 바로 내려받습니다. -->
        <div class="pm-attachments">
            <div class="pm-attachments-title">
                <strong>국세청 전자세금계산서</strong>
                <span>원본 보안파일을 내려받은 뒤 파일을 열고 회사 사업자등록번호를 입력하면 계산서를 확인할 수 있습니다.</span>
            </div>
            <div class="pm-attachment-row">
                <div class="pm-attachment-info">
                    <i data-lucide="receipt-text"></i>
                    <div>
                        <span>NTS_eTaxInvoice.html</span>
                        <small>국세청 보안 전자세금계산서 · 다운로드 후 사업자등록번호 입력</small>
                    </div>
                </div>
                <div class="pm-attachment-actions">
                    <a class="pm-btn pm-btn-primary"
                       href="<?php echo call_user_func($esc, $taxInvoiceDownloadUrl); ?>"
                       download="NTS_eTaxInvoice.html"
                       aria-label="국세청 전자세금계산서 다운로드">
                        <i data-lucide="download"></i> 세금계산서 다운로드
                    </a>
                </div>
            </div>
        </div>

        <?php if (!empty($normalAttachments)): ?>
            <div class="pm-attachments">
                <div class="pm-attachments-title">
                    <strong>첨부파일</strong>
                    <span>파일 원본은 CPMS 서버에 저장하지 않습니다.</span>
                </div>
                <?php foreach ($normalAttachments as $attachment): ?>
                    <?php
                    $partId = isset($attachment['part_id']) ? (string)$attachment['part_id'] : '';
                    $savedDriveRecord = null;
                    foreach ($driveRecords as $candidateRecord) {
                        if (!is_array($candidateRecord)) continue;
                        if (isset($candidateRecord['message_key'],$candidateRecord['part_id']) && (string)$candidateRecord['message_key'] === $messageKey && (string)$candidateRecord['part_id'] === $partId) {
                            $savedDriveRecord = $candidateRecord;
                            break;
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

    <?php else: ?>
        <!-- 일반 메일은 기존 첨부파일 -> 본문 순서를 그대로 유지합니다. -->
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
                            $savedDriveRecord = $candidateRecord;
                            break;
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
    <?php endif; ?>
</div>
