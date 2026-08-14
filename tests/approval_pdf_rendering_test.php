<?php
/**
 * Completed approval PDF rendering/download regression checks.
 * PHP 5.6 compatible and DB-independent.
 */

$root = dirname(__DIR__);
require_once $root . '/app/views/approval/_common.php';

if (!function_exists('h')) {
    function h($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('approval_sign_path_by_email')) {
    function approval_sign_path_by_email($email)
    {
        return '';
    }
}

require_once $root . '/app/services/ApprovalPdfService.php';

function approval_pdf_test_assert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
}

$lines = array(
    array(
        'role_type' => 'PM',
        'approver_name' => 'PM 테스트',
        'approver_email' => 'pm@example.com',
        'line_status' => 'APPROVED',
        'acted_at' => '2026-08-14 09:00:00'
    ),
    array(
        'role_type' => approval_ko('%EB%8C%80%ED%91%9C%EC%9D%B4%EC%82%AC'),
        'approver_name' => '대표 테스트',
        'approver_email' => 'ceo@example.com',
        'line_status' => 'APPROVED',
        'acted_at' => '2026-08-14 09:10:00'
    )
);

$leaveContent = array(
    'request_type' => approval_ko('%EC%97%B0%EC%B0%A8'),
    'department' => '개발부',
    'position' => '과장',
    'applicant_name' => '휴가 신청자 테스트',
    'applicant_sign_name' => '휴가 신청자 테스트',
    'applicant_email' => 'leave@example.com',
    'birth_date' => '1990-01-02',
    'leave_start_date' => '2026-08-17',
    'leave_end_date' => '2026-08-18',
    'leave_days' => '2',
    'leave_reason' => '휴가 사유 본문 테스트',
    'request_date' => '2026-08-14'
);
$leaveHtml = cpms_approval_pdf_render_document_body('leave', $leaveContent, $lines, array());
approval_pdf_test_assert(strpos($leaveHtml, 'class="pdf-document pdf-leave-document"') !== false, 'leave PDF must use the legacy-mPDF-safe document layout');
approval_pdf_test_assert(strpos($leaveHtml, '휴가 신청자 테스트') !== false, 'leave PDF must include the applicant');
approval_pdf_test_assert(strpos($leaveHtml, '휴가 사유 본문 테스트') !== false, 'leave PDF must include the leave reason body');
approval_pdf_test_assert(strpos($leaveHtml, '2026-08-17 ~ 2026-08-18') !== false, 'leave PDF must include the leave period');
approval_pdf_test_assert(substr_count($leaveHtml, '<table') >= 2, 'leave PDF must include approval and document tables');
approval_pdf_test_assert(strpos($leaveHtml, 'rowspan=') === false, 'leave PDF approval matrix must avoid the legacy mPDF rowspan failure');
approval_pdf_test_assert(strpos($leaveHtml, 'colspan="3"') !== false, 'leave PDF must preserve the original four-column form layout');
approval_pdf_test_assert(strpos($leaveHtml, 'pdf-applicant-table') !== false, 'leave PDF must preserve the original applicant signature placement');
$ceoApprovalHtml = cpms_approval_pdf_render_approval_table(array(array(
    'role_type' => approval_ko('%EB%B6%80%EC%82%AC%EC%9E%A5'),
    'approver_name' => approval_ko('%EB%8C%80%ED%91%9C%20%EC%8A%B9%EC%9D%B8%20%ED%85%8C%EC%8A%A4%ED%8A%B8'),
    'line_status' => 'CEO_APPROVED',
    'acted_at' => '2026-08-14 09:20:00'
)), array());
approval_pdf_test_assert(strpos($ceoApprovalHtml, approval_ko('%EB%8C%80%ED%91%9C%EC%8A%B9%EC%9D%B8')) !== false, 'CEO bypassed approval cells must show representative approval text');

$proposalContent = array(
    'draft_date' => '2026-08-14',
    'effective_date' => '2026-08-15',
    'draft_department' => '공무부',
    'drafter_name' => '기안자 테스트',
    'writer_email' => 'writer@example.com',
    'title' => '기안서 제목 본문 테스트',
    'headline' => '기안서 머리말 테스트',
    'reason' => '기안 사유 테스트',
    'company_name' => '거래처 테스트',
    'contract_amount' => '1000000',
    'advance_amount' => '500000',
    'special_note' => '특기사항 본문 테스트',
    'payment_request_date' => '2026-08-20',
    'budget_status' => '예산 확보'
);
$proposalHtml = cpms_approval_pdf_render_document_body('proposal', $proposalContent, $lines, array(
    'etc' => array(array('original_name' => '첨부자료 테스트.pdf'))
));
approval_pdf_test_assert(strpos($proposalHtml, 'class="pdf-document pdf-proposal-document"') !== false, 'proposal PDF must use the legacy-mPDF-safe document layout');
approval_pdf_test_assert(strpos($proposalHtml, '기안서 제목 본문 테스트') !== false, 'proposal PDF must include the title field');
approval_pdf_test_assert(strpos($proposalHtml, '기안 사유 테스트') !== false, 'proposal PDF must include the proposal reason body');
approval_pdf_test_assert(strpos($proposalHtml, '특기사항 본문 테스트') !== false, 'proposal PDF must include special notes');
approval_pdf_test_assert(strpos($proposalHtml, '첨부자료 테스트.pdf') !== false, 'proposal PDF must include attachment names');
approval_pdf_test_assert(substr_count($proposalHtml, '<table') >= 3, 'proposal PDF must retain metadata, approval, and document tables');
approval_pdf_test_assert(strpos($proposalHtml, 'rowspan=') === false, 'proposal PDF approval matrix must avoid the legacy mPDF rowspan failure');
approval_pdf_test_assert(strpos($proposalHtml, 'pdf-proposal-head-layout') !== false, 'proposal PDF must preserve the original side-by-side metadata and approval layout');
approval_pdf_test_assert(strpos($proposalHtml, 'pdf-proposal-next') !== false, 'proposal PDF must preserve the original numbered proposal body');

$pdfSource = file_get_contents($root . '/app/services/ApprovalPdfService.php');
$downloadSource = file_get_contents($root . '/app/views/approval/completed_pdf.php');
approval_pdf_test_assert(strpos($pdfSource, "simpleTables')) \$mpdf->simpleTables = false") !== false, 'flat PDF forms must keep the standard table renderer enabled');
approval_pdf_test_assert(strpos($pdfSource, "new mPDF('ko-aCJK', \$mpdfPageFormat, 0, 'unbatang')") !== false, 'mPDF must embed a Korean font instead of relying on Adobe UHC');
approval_pdf_test_assert(strpos($pdfSource, '.pdf-table{') !== false && strpos($pdfSource, 'page-break-inside:auto') !== false, 'the complete document tables must be allowed to span PDF pages');
approval_pdf_test_assert(cpms_approval_pdf_render_version() >= 10, 'existing blank PDFs must be queued for normalized-signature regeneration');
approval_pdf_test_assert(strpos($pdfSource, 'cpms_approval_pdf_embedded_image_data_uri') !== false && strpos($pdfSource, 'imagecopyresampled') !== false, 'large or transparent approval signatures must be normalized before legacy mPDF renders them');
approval_pdf_test_assert(strpos($downloadSource, 'cpms_drive_download_file($fileId)') !== false, 'completed PDFs must download through the authenticated Drive API');
approval_pdf_test_assert(strpos($downloadSource, "header('Location: '") === false, 'completed PDFs must not redirect users to a private Drive link');
approval_pdf_test_assert(strpos($downloadSource, "'Content-Type: application/pdf'") !== false, 'completed PDF response must use the PDF content type');
approval_pdf_test_assert(strpos($downloadSource, 'cpms_approval_pdf_cache_get_path($fileId') < strpos($downloadSource, 'cpms_drive_download_file($fileId)'), 'completed PDF downloads must check the protected local cache before Drive');
approval_pdf_test_assert(strpos($downloadSource, '@readfile($cachedPath)') !== false, 'cached completed PDFs must stream directly from disk');
approval_pdf_test_assert(basename(cpms_approval_pdf_cache_path('drive-file-id')) === sha1('drive-file-id') . '.pdf', 'completed PDF cache names must not expose Drive file IDs');
approval_pdf_test_assert(strpos($downloadSource, "header('ETag: ' . \$etag)") !== false, 'completed PDF previews must support permission-checked browser revalidation');

$currentLinks = cpms_approval_pdf_links_html(array(
    'id' => 91,
    'doc_status' => 'COMPLETED',
    'completed_pdf_drive_file_id' => 'drive-file-id',
    'completed_pdf_render_version' => cpms_approval_pdf_render_version(),
    'completed_pdf_upload_status' => 'uploaded'
));
approval_pdf_test_assert(strpos($currentLinks, 'approval_completed_pdf&amp;id=91') !== false, 'current PDFs must expose the protected preview route without requiring a Drive web link');
approval_pdf_test_assert(strpos($currentLinks, 'download=1') !== false, 'current PDFs must expose the protected download route without requiring a Drive content link');

$staleLinks = cpms_approval_pdf_links_html(array(
    'id' => 92,
    'doc_status' => 'COMPLETED',
    'completed_pdf_drive_file_id' => 'old-drive-file-id',
    'completed_pdf_render_version' => cpms_approval_pdf_render_version() - 1,
    'completed_pdf_upload_status' => 'uploaded'
));
approval_pdf_test_assert(strpos($staleLinks, 'PDF 재생성 중') !== false, 'stale broken PDFs must be hidden while automatic regeneration runs');

if (isset($_GET['preview']) && (string)$_GET['preview'] === 'leave') {
    echo '<!doctype html><html lang="ko"><head><meta charset="utf-8">' . cpms_approval_pdf_safe_style() . '</head><body>' . $leaveHtml . '</body></html>';
    exit;
}
if (isset($_GET['preview']) && (string)$_GET['preview'] === 'proposal') {
    echo '<!doctype html><html lang="ko"><head><meta charset="utf-8">' . cpms_approval_pdf_safe_style() . '</head><body>' . $proposalHtml . '</body></html>';
    exit;
}

echo 'OK: approval PDF rendering tests passed' . PHP_EOL;
