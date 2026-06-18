<?php
/**
 * Payroll statement rendering/PDF helpers.
 * PHP 5.6 compatible.
 */

require_once __DIR__ . '/CompanyPayrollService.php';
require_once __DIR__ . '/ApprovalPdfService.php';

if (!function_exists('cpms_payroll_statement_h')) {
function cpms_payroll_statement_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}}

if (!function_exists('cpms_payroll_statement_money')) {
function cpms_payroll_statement_money($value) {
    return number_format((float)$value);
}}

if (!function_exists('cpms_payroll_statement_data')) {
function cpms_payroll_statement_data($year, $month, $employeeKey) {
    $effective = cpms_company_payroll_effective_version($year, $month);
    if (empty($effective['ok']) || !isset($effective['version']) || !is_array($effective['version'])) {
        return array('ok' => false, 'message' => '적용 중인 급여 기준월 버전이 없습니다.');
    }
    $employee = cpms_company_payroll_find_employee_in_version($effective['version'], $employeeKey);
    if (!is_array($employee)) return array('ok' => false, 'message' => '직원 급여 데이터를 찾지 못했습니다.');
    $employee = cpms_company_payroll_public_employee($employee);
    return array(
        'ok' => true,
        'year' => sprintf('%04d', (int)$year),
        'month' => sprintf('%02d', (int)$month),
        'effective_year' => isset($effective['effective_year']) ? $effective['effective_year'] : '',
        'effective_month' => isset($effective['effective_month']) ? $effective['effective_month'] : '',
        'version' => cpms_company_payroll_public_version($effective['version']),
        'employee' => $employee,
    );
}}

if (!function_exists('cpms_payroll_statement_render_html')) {
function cpms_payroll_statement_render_html($data, $printMode) {
    if (!is_array($data) || empty($data['ok']) || !isset($data['employee']) || !is_array($data['employee'])) {
        return '<div>급여명세서 데이터를 찾지 못했습니다.</div>';
    }
    $e = $data['employee'];
    $payItems = array(
        '기본급' => 'base_pay',
        '연장수당' => 'overtime_pay',
        '연차수당' => 'annual_leave_pay',
        '사원연금' => 'employee_pension',
        '식대' => 'meal_allowance',
        '차량유지비' => 'vehicle_allowance',
        '연구수당' => 'research_allowance',
        '육아수당' => 'childcare_allowance',
        '직책수당' => 'position_allowance',
        '연차수당2' => 'annual_leave_pay_2',
        '결근' => 'absence_deduction',
        '선급급여' => 'advance_pay',
    );
    $deductItems = array(
        '소득세' => 'income_tax',
        '지방소득세' => 'local_income_tax',
        '고용보험' => 'employment_insurance',
        '국민연금' => 'national_pension',
        '건강보험' => 'health_insurance',
        '노인장기요양' => 'long_term_care',
        '소득세정산' => 'income_tax_adjustment',
        '지방세정산' => 'local_tax_adjustment',
        '건강보험정산' => 'health_insurance_adjustment',
        '장기요양정산' => 'long_term_care_adjustment',
        '기타공제' => 'other_deduction',
    );
    $html = '';
    $html .= '<style>';
    $html .= 'body{font-family:Arial,"Malgun Gothic",sans-serif;color:#111827}.payroll-statement{max-width:860px;margin:0 auto;background:#fff}.statement-head{border-bottom:3px solid #111827;padding:18px 0;margin-bottom:18px}.statement-title{font-size:28px;font-weight:800;text-align:center}.meta-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px 18px;margin-top:16px}.meta-grid div{font-size:13px}.section-title{font-weight:800;margin:22px 0 8px}.statement-table{width:100%;border-collapse:collapse;font-size:13px}.statement-table th,.statement-table td{border:1px solid #d1d5db;padding:8px}.statement-table th{background:#f3f4f6;text-align:left}.money{text-align:right}.total-row th,.total-row td{background:#ecfdf5;font-weight:800}.final-pay{margin-top:18px;border:2px solid #111827;padding:14px;display:flex;justify-content:space-between;font-size:18px;font-weight:800}.memo{margin-top:12px;border:1px solid #d1d5db;padding:10px;min-height:44px;font-size:13px}.actions{max-width:860px;margin:0 auto 14px;display:flex;gap:8px}.btn{display:inline-block;padding:10px 14px;border-radius:10px;border:1px solid #d1d5db;text-decoration:none;color:#111827;font-weight:700;background:#fff}.btn-primary{background:#166534;color:#fff;border-color:#166534}@media(max-width:720px){.meta-grid{grid-template-columns:1fr}.statement-title{font-size:22px}.statement-table{font-size:12px}.statement-table th,.statement-table td{padding:6px}.final-pay{font-size:15px}}@media print{.actions{display:none}body{margin:0}.payroll-statement{max-width:none}.statement-head{padding-top:0}}';
    $html .= '</style>';
    $html .= '<div class="payroll-statement">';
    $html .= '<div class="statement-head"><div style="font-size:13px;font-weight:700;">주식회사 창명건설</div><div class="statement-title">급여명세서</div></div>';
    $html .= '<div class="meta-grid">';
    $html .= '<div><strong>지급월</strong> ' . cpms_payroll_statement_h($data['year'] . '년 ' . $data['month'] . '월') . '</div>';
    $html .= '<div><strong>적용 기준</strong> ' . cpms_payroll_statement_h($data['effective_year'] . '년 ' . $data['effective_month'] . '월 급여대장') . '</div>';
    $html .= '<div><strong>직원명</strong> ' . cpms_payroll_statement_h(isset($e['name']) ? $e['name'] : '') . '</div>';
    $html .= '<div><strong>직급</strong> ' . cpms_payroll_statement_h(isset($e['position']) ? $e['position'] : '') . '</div>';
    $html .= '<div><strong>입사일</strong> ' . cpms_payroll_statement_h(isset($e['joined_at']) ? $e['joined_at'] : '') . '</div>';
    $html .= '<div><strong>주민번호</strong> <span id="statement_resident">' . cpms_payroll_statement_h(isset($e['resident_masked']) ? $e['resident_masked'] : '') . '</span></div>';
    $html .= '</div>';

    $html .= '<div class="section-title">지급항목</div><table class="statement-table"><tbody>';
    foreach ($payItems as $label => $key) {
        $html .= '<tr><th>' . cpms_payroll_statement_h($label) . '</th><td class="money">' . cpms_payroll_statement_money(isset($e[$key]) ? $e[$key] : 0) . '</td></tr>';
    }
    $html .= '<tr class="total-row"><th>지급합계</th><td class="money">' . cpms_payroll_statement_money(isset($e['gross_pay']) ? $e['gross_pay'] : 0) . '</td></tr>';
    $html .= '</tbody></table>';

    $html .= '<div class="section-title">공제항목</div><table class="statement-table"><tbody>';
    foreach ($deductItems as $label2 => $key2) {
        $html .= '<tr><th>' . cpms_payroll_statement_h($label2) . '</th><td class="money">' . cpms_payroll_statement_money(isset($e[$key2]) ? $e[$key2] : 0) . '</td></tr>';
    }
    $html .= '<tr class="total-row"><th>공제총액</th><td class="money">' . cpms_payroll_statement_money(isset($e['total_deduction']) ? $e['total_deduction'] : 0) . '</td></tr>';
    $html .= '</tbody></table>';

    $html .= '<div class="final-pay"><span>차인지급액</span><span>' . cpms_payroll_statement_money(isset($e['net_pay']) ? $e['net_pay'] : 0) . '원</span></div>';
    $html .= '<div class="memo"><strong>비고</strong><br>' . nl2br(cpms_payroll_statement_h(isset($e['etc']) ? $e['etc'] : '')) . '</div>';
    $html .= '</div>';
    return $html;
}}

if (!function_exists('cpms_payroll_statement_create_pdf')) {
function cpms_payroll_statement_create_pdf($data, $user) {
    if (!is_array($data) || empty($data['ok'])) return array('ok' => false, 'message' => '급여명세서 데이터가 없습니다.');
    $employee = isset($data['employee']) && is_array($data['employee']) ? $data['employee'] : array();
    $name = isset($employee['name']) ? preg_replace('/[\/\\\\:\*\?"<>\|]+/', '_', (string)$employee['name']) : 'employee';
    $pdfName = 'payroll_statement_' . $data['year'] . $data['month'] . '_' . $name . '.pdf';
    $html = cpms_payroll_statement_render_html($data, true);
    $context = array('user' => $user, 'section' => 'company_payroll_statement_pdf', 'document_type' => '급여명세서', 'document_year' => $data['year'], 'document_month' => $data['month']);
    return cpms_approval_pdf_create_from_html($html, $pdfName, $context);
}}
