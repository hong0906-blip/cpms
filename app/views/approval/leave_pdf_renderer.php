<?php
/*
 * 파일경로: app/views/approval/leave_pdf_renderer.php
 * 기능: 완료문서 PDF의 휴가계 본문을 사번 기준으로 렌더링
 *
 * ApprovalPdfService.php는 template_leave.php를 먼저 불러온 뒤
 * cpms_approval_pdf_render_safe_leave_body()를 function_exists로 등록합니다.
 * 이 파일에서 휴가계 전용 렌더러를 먼저 정의하여, 거대한 PDF 서비스 파일을
 * 직접 수정하지 않고 휴가계 양식 변경만 독립적으로 유지합니다.
 * PHP 5.6 호환
 */

require_once __DIR__ . '/leave_identity_helpers.php';

if (!function_exists('cpms_approval_pdf_render_safe_leave_body')) {
    function cpms_approval_pdf_render_safe_leave_body($data, $lines)
    {
        $data = is_array($data) ? $data : array();
        $lines = is_array($lines) ? $lines : array();
        $requestType = approval_doc_get($data, 'request_type', approval_ko('%EC%97%B0%EC%B0%A8'));
        $requestTypeEtc = approval_doc_get($data, 'request_type_etc', '');
        if ($requestType === approval_ko('%EA%B8%B0%ED%83%80') && $requestTypeEtc !== '') {
            $requestType .= ' (' . $requestTypeEtc . ')';
        }
        $startDate = approval_doc_get($data, 'leave_start_date', '');
        $endDate = approval_doc_get($data, 'leave_end_date', '');
        $days = approval_doc_get($data, 'leave_days', '');
        $period = trim($startDate . ($endDate !== '' ? ' ~ ' . $endDate : ''));
        if ($days !== '') {
            $period .= ' / ' . $days . approval_ko('%EC%9D%BC');
        }
        $applicantName = approval_doc_get($data, 'applicant_sign_name', approval_doc_get($data, 'applicant_name', '-'));
        $applicantEmail = approval_doc_get($data, 'applicant_email', approval_doc_get($data, 'writer_email', ''));
        $applicantSign = cpms_approval_pdf_leave_sign_html(array(), $applicantEmail, true);
        $requestDate = approval_doc_get($data, 'request_date', '');
        if ($requestDate === '') {
            $requestDate = date('Y-m-d');
        }
        $employeeNo = approval_leave_resolve_employee_no($data);
        $approvalWidth = count($lines) * 14 + 8;
        if ($approvalWidth < 50) {
            $approvalWidth = 50;
        }
        if ($approvalWidth > 100) {
            $approvalWidth = 100;
        }
        $approvalMargin = 100 - $approvalWidth;

        $html = '<div class="pdf-document pdf-leave-document">';
        $html .= '<div class="pdf-title">' . h(approval_ko('%ED%9C%B4%EA%B0%80%EA%B3%84')) . '</div>';
        $html .= '<div class="pdf-leave-approval" style="width:' . $approvalWidth . '%;margin-left:' . $approvalMargin . '%">' . cpms_approval_pdf_render_approval_table($lines, array()) . '</div>';
        $html .= cpms_approval_pdf_render_line_messages($data, $lines);
        $html .= '<table class="pdf-table pdf-leave-form">';
        $html .= '<tr><th>' . h(approval_ko('%EC%8B%A0%EC%B2%AD%EA%B5%AC%EB%B6%84')) . '</th><td colspan="3">' . h($requestType) . '</td></tr>';
        $html .= '<tr><th>' . h(approval_ko('%EC%86%8C%EC%86%8D')) . '</th><td>' . h(approval_doc_get($data, 'department', '-')) . '</td><th>' . h(approval_ko('%EC%A7%81%EC%9C%84')) . '</th><td>' . h(approval_doc_get($data, 'position', '-')) . '</td></tr>';
        $html .= '<tr><th>' . h(approval_ko('%EC%84%B1%EB%AA%85')) . '</th><td>' . h(approval_doc_get($data, 'applicant_name', '-')) . '</td><th>' . h(approval_ko('%EC%82%AC%EB%B2%88')) . '</th><td>' . h($employeeNo !== '' ? $employeeNo : '-') . '</td></tr>';
        $html .= '<tr><th>' . h(approval_ko('%ED%9C%B4%EA%B0%80%EA%B8%B0%EA%B0%84')) . '</th><td colspan="3">' . h($period !== '' ? $period : '-') . '</td></tr>';
        $html .= '<tr><th>' . h(approval_ko('%ED%9C%B4%EA%B0%80%EC%82%AC%EC%9C%A0')) . '</th><td colspan="3" class="pdf-leave-reason">' . nl2br(h(approval_doc_get($data, 'leave_reason', '-'))) . '<div style="margin-top:12mm">' . h(approval_default_leave_agreement()) . '</div></td></tr>';
        $html .= '</table>';
        $html .= '<div class="pdf-date">' . h($requestDate) . '</div>';
        $html .= '<table class="pdf-applicant-table"><tr><td style="width:46mm">' . h(approval_ko('%EC%8B%A0%EC%B2%AD%EC%9D%B8')) . '&nbsp;&nbsp;' . h($applicantName) . '</td><td class="pdf-applicant-sign">' . $applicantSign . '</td><td style="width:28mm;font-size:3.8mm">(' . h(approval_ko('%EC%9D%B8%20%EB%98%90%EB%8A%94%20%EC%84%9C%EB%AA%85')) . ')</td></tr></table>';
        $html .= '<div class="pdf-company">' . h(approval_ko('%EC%A3%BC%EC%8B%9D%ED%9A%8C%EC%82%AC%20%EC%B0%BD%EB%AA%85%EA%B1%B4%EC%84%A4')) . '</div>';
        $html .= '</div>';
        return $html;
    }
}
