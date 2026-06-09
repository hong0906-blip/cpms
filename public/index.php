<?php
/**
 * C:\www\cpms\public\index.php
 * - Router
 *
 * ✅ 수정사항(요청한 것만)
 * 1) 관리 섹션 404 해결: ?r=관리, ?r=관리자 둘 다 admin/index로 연결
 * 2) 관리 화면에서 사용하는 admin/... 저장 라우트 연결
 */

require_once __DIR__ . '/../app/bootstrap.php';

$route = isset($_GET['r']) ? trim($_GET['r']) : '대시보드';
if ($route === '') $route = '대시보드';

// ==========================
//  세션 유지용 Ping
//  - footer.php에서 주기적으로 호출해서 세션 만료(자동로그아웃)를 방지
// ==========================
$dashboardType = isset($_SESSION['dashboardType']) ? (string)$_SESSION['dashboardType'] : 'employee';

if ($route === 'tasks/my_list') {
    if (!\App\Core\Auth::check()) {
        header('Location: ?r=login');
        exit;
    }
    \App\Core\View::render('tasks/my_list', array(
        'title' => urldecode('%EB%82%98%EC%9D%98%20%ED%95%A0%EC%9D%BC'),
        'selectedMenu' => urldecode('%EB%8C%80%EC%8B%9C%EB%B3%B4%EB%93%9C'),
        'dashboardType' => $dashboardType,
    ));
    exit;
}

if ($route === 'tasks/executive_summary') {
    if (!\App\Core\Auth::check()) {
        header('Location: ?r=login');
        exit;
    }
    if (!(\App\Core\Auth::isMaster() || \App\Core\Auth::userRole() === 'executive' || \App\Core\Auth::canManageEmployees())) {
        http_response_code(403);
        echo '403 Forbidden';
        exit;
    }
    \App\Core\View::render('tasks/executive_summary', array(
        'title' => urldecode('%EB%B6%80%EC%84%9C%EB%B3%84%20%EC%97%85%EB%AC%B4%20%ED%98%84%ED%99%A9'),
        'selectedMenu' => urldecode('%EB%8C%80%EC%8B%9C%EB%B3%B4%EB%93%9C'),
        'dashboardType' => $dashboardType,
    ));
    exit;
}

if ($route === 'ping') {
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo 'OK';
    exit;
}

// ==========================
//  ✅ 관리 섹션 404 방지(호환)
// ==========================
if ($route === '관리자') {
    $route = '관리';
}


// ==========================
//  ASCII 우회 라우트 (Bad Request 한글 URL 방지)
//  - dashboard_executive ASCII 라우트
//  - safety_home ASCII 라우트
// ==========================
if ($route === 'dashboard') {
    $route = '대시보드';
}
if ($route === 'dashboard_executive') {
    $_SESSION['dashboardType'] = 'executive';
    $route = '대시보드';
}
if ($route === 'dashboard_employee') {
    $_SESSION['dashboardType'] = 'employee';
    $route = '대시보드';
}
if ($route === 'safety_home') {
    $route = '안전/보건';
}

if ($route === 'construction_home') {
    $route = '공사';
}
if ($route === '공무/프로젝트상세' || $route === 'project_view') {
    $route = 'project/detail';
}
if ($route === '공무/프로젝트수정' || $route === 'project/update' || $route === 'project_update') {
    $route = 'project/project_update';
}
if ($route === 'approval_home') { $route = '전자결재'; }
if ($route === 'approval_active') {
    $_GET['view'] = 'active';
    $route = '전자결재';
}
if ($route === 'approval_cancelled') {
    $_GET['view'] = 'cancelled';
    $route = '전자결재';
}
if ($route === 'approval_completed') {
    $_GET['view'] = 'completed';
    $route = '전자결재';
}
if ($route === 'estimate_home' || $route === '견적단가 추천') {
    $route = '견적관리';
}
if ($route === 'estimate/write') {
    $_GET['tab'] = 'write';
    $route = '견적관리';
}
if ($route === 'estimate/search') {
    $_GET['tab'] = 'search';
    $route = '견적관리';
}
if ($route === 'estimate/history') {
    $_GET['tab'] = 'history';
    $route = '견적관리';
}
if ($route === 'estimate/bid_result') {
    $_GET['tab'] = 'bid_result';
    $route = '견적관리';
}

// ==========================
//  액션(POST 처리) 라우트 먼저
// ==========================
if ($route === 'admin/employees_save') {
    require_once __DIR__ . '/../app/views/admin/employees_save.php';
    exit;
}
if ($route === 'admin/employees_upload') {
    require_once __DIR__ . '/../app/views/admin/employees_upload.php';
    exit;
}
if ($route === 'admin/employees_columns_save') {
    require_once __DIR__ . '/../app/views/admin/employees_columns_save.php';
    exit;
}

if ($route === 'approval_google_chat_settings') { require_once __DIR__ . '/../app/views/approval/google_chat_settings.php'; exit; }
if ($route === 'approval_google_chat_settings_save') { require_once __DIR__ . '/../app/views/approval/google_chat_settings_save.php'; exit; }
if ($route === 'approval_google_chat_employee_dm_create') { require_once __DIR__ . '/../app/views/approval/google_chat_employee_dm_create.php'; exit; }
if ($route === 'approval_google_chat_employee_test') { require_once __DIR__ . '/../app/views/approval/google_chat_employee_test.php'; exit; }
if ($route === 'google_chat_event') { require_once __DIR__ . '/../app/views/approval/google_chat_event.php'; exit; }

// ==========================
//  관리(노무비) 관련 액션(POST 처리)
// ==========================
if ($route === 'admin/direct_rates_save') {
    require_once __DIR__ . '/../app/views/admin/direct_rates_save.php';
    exit;
}
if ($route === 'admin/direct_team_save') {
    require_once __DIR__ . '/../app/views/admin/direct_team_save.php';
    exit;
}
if ($route === 'admin/labor_entries_save') {
    require_once __DIR__ . '/../app/views/admin/labor_entries_save.php';
    exit;
}
if ($route === 'admin/labor_consultant_setup') {
    require_once __DIR__ . '/../app/views/admin/labor_consultant_setup.php';
    exit;
}
if ($route === 'admin/labor_consultant_template_upload') {
    require_once __DIR__ . '/../app/views/admin/labor_consultant_template_upload.php';
    exit;
}
if ($route === 'admin/labor_consultant_export') {
    require_once __DIR__ . '/../app/views/admin/labor_consultant_export.php';
    exit;
}

if ($route === 'db_setup_estimate') {
    require_once __DIR__ . '/db_setup_estimate.php';
    exit;
}

if ($route === 'estimate/estimate_save') {
    require_once __DIR__ . '/../app/views/estimate/estimate_save.php';
    exit;
}
if ($route === 'estimate/price_import_preview') {
    require_once __DIR__ . '/../app/views/estimate/price_import_preview.php';
    exit;
}
if ($route === 'estimate/price_import_apply') {
    require_once __DIR__ . '/../app/views/estimate/price_import_apply.php';
    exit;
}
if ($route === 'estimate/price_recommend') {
    require_once __DIR__ . '/../app/views/estimate/price_recommend.php';
    exit;
}
if ($route === 'estimate/item_search') {
    require_once __DIR__ . '/../app/views/estimate/item_search.php';
    exit;
}
if ($route === 'estimate/bid_result_save') {
    require_once __DIR__ . '/../app/views/estimate/bid_result_save.php';
    exit;
}
if ($route === 'estimate/export_xlsx') {
    require_once __DIR__ . '/../app/views/estimate/export_xlsx.php';
    exit;
}


if ($route === 'db_setup_project_monthly') {
    $dept = (string)\App\Core\Auth::userDepartment();
    $role = (string)\App\Core\Auth::userRole();
    $ok = \App\Core\Auth::isMaster() || $role === 'executive' || $dept === '공무' || $dept === '관리' || $dept === '관리부';
    if (!$ok) { http_response_code(403); echo '403 Forbidden'; exit; }
    require_once __DIR__ . '/db_setup_project_monthly.php';
    exit;
}

// ==========================
//  공무(프로젝트) 액션(POST 처리)
// ==========================
if ($route === 'project/project_save') {
    require_once __DIR__ . '/../app/views/project/project_save.php';
    exit;
}

/**
 * ✅ [추가] 프로젝트 수정 저장(POST)
 * - debug_project_update=1 쿼리는 project_update.php에서 실패 원인 JSON을 반환
 * - app/views/project/project_update.php
 */
if ($route === 'project/project_update' || $route === 'project/project_edit_save' || $route === 'project_edit_save') {
    require_once __DIR__ . '/../app/views/project/project_update.php';
    exit;
}

/**
 * ✅ [추가] 프로젝트 삭제(POST)
 * - app/views/project/project_delete.php
 */
if ($route === 'project/project_delete') {
    require_once __DIR__ . '/../app/views/project/project_delete.php';
    exit;
}
if ($route === 'project/monthly_deduction_save') {
    require_once __DIR__ . '/../app/views/project/monthly_deduction_save.php';
    exit;
}
if ($route === 'project/monthly_deduction_delete') {
    require_once __DIR__ . '/../app/views/project/monthly_deduction_delete.php';
    exit;
}


/**
 * ✅ [추가] 프로젝트 생성 모달에서 엑셀 업로드 → 미리보기(JSON)
 * - app/views/project/project_create_preview.php
 */
if ($route === 'project/project_create_preview') {
    require_once __DIR__ . '/../app/views/project/project_create_preview.php';
    exit;
}
if ($route === 'project/unit_price_update_preview') {
    require_once __DIR__ . '/../app/views/project/unit_price_update_preview.php';
    exit;
}
if ($route === 'project/contract_change_preview') {
    require_once __DIR__ . '/../app/views/project/contract_change_preview.php';
    exit;
}

/**
 * ✅ [추가] 계약서 업로드(프로젝트 상세에서 업로드)
 * - app/views/project/contract_upload.php
 */
if ($route === 'project/contract_upload') {
    require_once __DIR__ . '/../app/views/project/contract_upload.php';
    exit;
}

/**
 * ✅ [추가] 계약서 다운로드(권한 체크 후 다운로드)
 * - app/views/project/contract_download.php
 */
if ($route === 'project/contract_download') {
    require_once __DIR__ . '/../app/views/project/contract_download.php';
    exit;
}

if ($route === 'project/unit_price_add') {
    require_once __DIR__ . '/../app/views/project/unit_price_add.php';
    exit;
}
if ($route === 'project/unit_price_delete') {
    require_once __DIR__ . '/../app/views/project/unit_price_delete.php';
    exit;
}
if ($route === 'project/unit_price_import_preview') {
    require_once __DIR__ . '/../app/views/project/unit_price_import_preview.php';
    exit;
}
if ($route === 'project/unit_price_import_apply') {
    require_once __DIR__ . '/../app/views/project/unit_price_import_apply.php';
    exit;
}

if ($route === 'project/header_mapping_save') {
    require_once __DIR__ . '/../app/views/project/header_mapping_save.php';
    exit;
}


if ($route === 'project/unit_price_toggle_safety') {
    require_once __DIR__ . '/../app/views/project/unit_price_toggle_safety.php';
    exit;
}

/**
 * ==========================
 * ✅ 이슈(등록/상태/댓글) 액션(POST 처리)
 * ==========================
 */
if ($route === 'project/issue_create') {
    require_once __DIR__ . '/../app/views/project/issue_create.php';
    exit;
}
if ($route === 'project/issue_comment_create') {
    require_once __DIR__ . '/../app/views/project/issue_comment_create.php';
    exit;
}
if ($route === 'project/issue_update') {
    require_once __DIR__ . '/../app/views/project/issue_update.php';
    exit;
}

// ==========================
//  공사(Construction) 액션(POST 처리)
// ==========================
if ($route === 'construction/roles_save') {
    require_once __DIR__ . '/../app/views/construction/roles_save.php';
    exit;
}
if ($route === 'construction/template_generate') {
    require_once __DIR__ . '/../app/views/construction/template_generate.php';
    exit;
}
if ($route === 'construction/schedule_seed_from_template') {
    require_once __DIR__ . '/../app/views/construction/schedule_seed_from_template.php';
    exit;
}
if ($route === 'construction/schedule_save') {
    require_once __DIR__ . '/../app/views/construction/schedule_save.php';
    exit;
}
if ($route === 'construction/schedule_move') {
    require_once __DIR__ . '/../app/views/construction/schedule_move.php';
    exit;
}
if ($route === 'construction/schedule_delete') {
    require_once __DIR__ . '/../app/views/construction/schedule_delete.php';
    exit;
}
if ($route === 'construction/schedule_progress_save') {
    require_once __DIR__ . '/../app/views/construction/schedule_progress_save.php';
    exit;
}
if ($route === 'construction/schedule_task_item_progress_save') {
    require_once __DIR__ . '/../app/views/construction/schedule_task_item_progress_save.php';
    exit;
}
if ($route === 'construction/safety_incident_create') {
    require_once __DIR__ . '/../app/views/construction/safety_incident_create.php';
    exit;
}

if ($route === 'construction/issue_status_save') {
    require_once __DIR__ . '/../app/views/construction/issue_status_save.php';
    exit;
}
if ($route === 'construction/labor_worker_add') {
    require_once __DIR__ . '/../app/views/construction/labor_worker_add.php';
    exit;
}
if ($route === 'construction/labor_worker_delete') {
    require_once __DIR__ . '/../app/views/construction/labor_worker_delete.php';
    exit;
}
// 인원작성 저장 기능
if ($route === 'construction/labor_workers_save') {
    require_once __DIR__ . '/../app/views/construction/labor_workers_save.php';
    exit;
}
if ($route === 'construction/labor_sheet_download') {
    require_once __DIR__ . '/../app/views/construction/labor_sheet_download.php';
    exit;
}


if ($route === 'construction/labor_cell_save') {
    // [변경] 구버전 labor_cell_save 차단/통합: 새 액션으로 일원화
    require_once __DIR__ . '/../app/views/construction/labor_gongsu_override_save.php';
    exit;
}
// [변경] JSON action layout 차단: action 파일만 실행 후 즉시 종료
if ($route === 'construction/labor_gongsu_override_save') {
    require_once __DIR__ . '/../app/views/construction/labor_gongsu_override_save.php';
    exit;
}
if ($route === 'construction/labor_gongsu_override_decide') {
    require_once __DIR__ . '/../app/views/construction/labor_gongsu_override_decide.php';
    exit;
}
if ($route === 'request/create') {
    require_once __DIR__ . '/../app/views/request/create.php';
    exit;
}
if ($route === 'request/decide') {
    require_once __DIR__ . '/../app/views/request/decide.php';
    exit;
}
if ($route === 'tasks/create') {
    require_once __DIR__ . '/../app/views/tasks/create.php';
    exit;
}
if ($route === 'tasks/update_status') {
    require_once __DIR__ . '/../app/views/tasks/update_status.php';
    exit;
}
if ($route === 'tasks/complete') {
    require_once __DIR__ . '/../app/views/tasks/complete.php';
    exit;
}
if ($route === 'tasks/revision') {
    require_once __DIR__ . '/../app/views/tasks/revision.php';
    exit;
}
if ($route === 'tasks/cancel') {
    require_once __DIR__ . '/../app/views/tasks/cancel.php';
    exit;
}
if ($route === 'tasks/detail') {
    require_once __DIR__ . '/../app/views/tasks/detail.php';
    exit;
}
if ($route === 'db_setup_tasks') {
    require_once __DIR__ . '/db_setup_tasks.php';
    exit;
}

// 공사 페이지 전용 이슈 등록/댓글(리다이렉트가 공사로 돌아오게)
if ($route === 'construction/issue_save') {
    require_once __DIR__ . '/../app/views/construction/issue_save.php';
    exit;
}
if ($route === 'construction/issue_create') {
    require_once __DIR__ . '/../app/views/construction/issue_create.php';
    exit;
}
if ($route === 'construction/issue_comment_create') {
    require_once __DIR__ . '/../app/views/construction/issue_comment_create.php';
    exit;
}
// [변경] issue_update 경로 폐기: issue_state_save 새 상태변경 action으로 Apache 400 우회
if ($route === 'construction/issue_state_save') {
    require_once __DIR__ . '/../app/views/construction/issue_state_save.php';
    exit;
}
if ($route === 'construction/issue_update') {
    require_once __DIR__ . '/../app/views/construction/issue_update.php';
    exit;
}


if ($route === 'construction/daily_work_save') {
    require_once __DIR__ . '/../app/views/construction/daily_work_save.php';
    exit;
}
if ($route === 'construction/daily_cost_save') {
    require_once __DIR__ . '/../app/views/construction/daily_cost_save.php';
    exit;
}
if ($route === 'construction/recognized_save') {
    require_once __DIR__ . '/../app/views/construction/recognized_save.php';
    exit;
}
if ($route === 'construction/sample_c5_seed') {
    require_once __DIR__ . '/../app/views/construction/sample_c5_seed.php';
    exit;
}

if ($route === 'construction/equipment_item_save') {
    require_once __DIR__ . '/../app/views/construction/equipment_item_save.php';
    exit;
}
if ($route === 'construction/equipment_item_delete') {
    require_once __DIR__ . '/../app/views/construction/equipment_item_delete.php';
    exit;
}
if ($route === 'construction/equipment_usage_save') {
    require_once __DIR__ . '/../app/views/construction/equipment_usage_save.php';
    exit;
}
if ($route === 'construction/equipment_gongsu_override_save') {
    require_once __DIR__ . '/../app/views/construction/equipment_gongsu_override_save.php';
    exit;
}
if ($route === 'construction/equipment_gongsu_override_decide') {
    require_once __DIR__ . '/../app/views/construction/equipment_gongsu_override_decide.php';
    exit;
}
if ($route === 'construction/equipment_usage_delete') {
    require_once __DIR__ . '/../app/views/construction/equipment_usage_delete.php';
    exit;
}
if ($route === 'construction/material_item_save') {
    require_once __DIR__ . '/../app/views/construction/material_item_save.php';
    exit;
}
if ($route === 'construction/material_item_delete') {
    require_once __DIR__ . '/../app/views/construction/material_item_delete.php';
    exit;
}
if ($route === 'construction/material_usage_save') {
    require_once __DIR__ . '/../app/views/construction/material_usage_save.php';
    exit;
}

if ($route === 'construction/equipment_vendor_search') {
    require_once __DIR__ . '/../app/views/construction/equipment_vendor_search.php';
    exit;
}
if ($route === 'construction/material_vendor_search') {
    require_once __DIR__ . '/../app/views/construction/material_vendor_search.php';
    exit;
}
if ($route === 'construction/material_usage_delete') {
    require_once __DIR__ . '/../app/views/construction/material_usage_delete.php';
    exit;
}

if ($route === 'construction/work_item_save') {
    require_once __DIR__ . '/../app/views/construction/work_item_save.php';
    exit;
}
if ($route === 'construction/work_item_delete') {
    require_once __DIR__ . '/../app/views/construction/work_item_delete.php';
    exit;
}
if ($route === 'construction/work_item_line_save') {
    require_once __DIR__ . '/../app/views/construction/work_item_line_save.php';
    exit;
}
if ($route === 'construction/work_item_line_delete') {
    require_once __DIR__ . '/../app/views/construction/work_item_line_delete.php';
    exit;
}
// ==========================
//  안전(안전사고) 액션(POST 처리)
// ==========================
if ($route === 'safety/safety_incident_save') {
    require_once __DIR__ . '/../app/views/safety/safety_incident_save.php';
    exit;
}
if ($route === 'safety/incident_update') {
    require_once __DIR__ . '/../app/views/safety/incident_update.php';
    exit;
}
if ($route === 'construction/safety_incident_action_save') {
    require_once __DIR__ . '/../app/views/construction/safety_incident_action_save.php';
    exit;
}



if ($route === 'approval_store') { require_once __DIR__ . '/../app/views/approval/store.php'; exit; }
if ($route === 'approval_decide') { require_once __DIR__ . '/../app/views/approval/decide.php'; exit; }
if ($route === 'approval_cancel') { require_once __DIR__ . '/../app/views/approval/cancel.php'; exit; }
if ($route === 'approval_delete') { require_once __DIR__ . '/../app/views/approval/delete.php'; exit; }
if ($route === 'db_setup_approval') { require_once __DIR__ . '/db_setup_approval.php'; exit; }
if ($route === 'attendance/check_in') { require_once __DIR__ . '/../app/views/attendance/check_in.php'; exit; }
if ($route === 'attendance/check_out') { require_once __DIR__ . '/../app/views/attendance/check_out.php'; exit; }
if ($route === 'attendance/request_save') { require_once __DIR__ . '/../app/views/attendance/request_save.php'; exit; }
if ($route === 'management/attendance') { require_once __DIR__ . '/../app/views/management/attendance.php'; exit; }
if ($route === 'management/attendance_request_approve') { require_once __DIR__ . '/../app/views/management/attendance_request_approve.php'; exit; }
if ($route === 'management/attendance_request_reject') { require_once __DIR__ . '/../app/views/management/attendance_request_reject.php'; exit; }
if ($route === 'management/attendance_record_save') { require_once __DIR__ . '/../app/views/management/attendance_record_save.php'; exit; }
if ($route === 'management/leave_save') { require_once __DIR__ . '/../app/views/management/leave_save.php'; exit; }
if ($route === 'management/leave_delete') { require_once __DIR__ . '/../app/views/management/leave_delete.php'; exit; }
if ($route === 'management/attendance_settings_save') { require_once __DIR__ . '/../app/views/management/attendance_settings_save.php'; exit; }
if ($route === 'management/attendance_geofence_save') { require_once __DIR__ . '/../app/views/management/attendance_geofence_save.php'; exit; }
if ($route === 'management/attendance_geofence_delete') { require_once __DIR__ . '/../app/views/management/attendance_geofence_delete.php'; exit; }
// db_setup_attendance 라우트
if ($route === 'db_setup_attendance') {
    if (!(\App\Core\Auth::isMaster() || \App\Core\Auth::canManageEmployees())) {
        http_response_code(403);
        echo '403 Forbidden';
        exit;
    }
    require_once __DIR__ . '/db_setup_attendance.php';
    exit;
}
// ==========================
//  로그인/로그아웃
// ==========================
if ($route === 'login') {
    \App\Core\View::render('auth/login', array(
        'title' => '로그인',
        'hideLayout' => true,
    ));
    exit;
}
if ($route === 'logout') {
    \App\Core\Auth::logout();
    header('Location: ?r=login');
    exit;
}

// ==========================
//  로그인 체크
// ==========================
if (!\App\Core\Auth::check()) {
    header('Location: ?r=login');
    exit;
}

// ==========================
//  대시보드 타입(직원/임원)
// ==========================
if (isset($_GET['dv'])) {
    $dv = (string)$_GET['dv'];
    if ($dv === 'employee' || $dv === 'executive') {
        $_SESSION['dashboardType'] = $dv;
    }
}
$dashboardType = isset($_SESSION['dashboardType']) ? (string)$_SESSION['dashboardType'] : 'employee';

// ==========================
//  견적관리 직접 URL 접근 차단
// ==========================
if ($route === '견적관리' && !\App\Core\Auth::canAccessEstimate()) {
    http_response_code(403);
    echo '접근 권한이 없습니다.';
    exit;
}


// ==========================
//  관리 라우트 강제 진단(debug_route=1)
// ==========================
if ($route === '관리' && isset($_GET['debug_route']) && (string)$_GET['debug_route'] === '1') {
    if (\App\Core\Auth::check() && (\App\Core\Auth::isMaster() || \App\Core\Auth::canManageEmployees())) {
        header('Content-Type: text/plain; charset=utf-8');
        echo "[ROUTE DEBUG]\n";
        echo 'route=' . $route . "\n";
        echo 'view=admin/index' . "\n";
        echo 'selectedMenu=관리' . "\n";
        echo 'Auth::isMaster()=' . (\App\Core\Auth::isMaster() ? 'true' : 'false') . "\n";
        echo 'Auth::canManageEmployees()=' . (\App\Core\Auth::canManageEmployees() ? 'true' : 'false') . "\n";
        echo '__FILE__=' . __FILE__ . "\n";
        exit;
    }
}

// ==========================
//  화면 매핑
// ==========================
$views = array(
    '공무'      => 'project/index',
    '공사'      => 'construction/index',
    '안전/보건' => 'safety/index',
    '품질'      => 'quality/index',
    '전자결재'  => 'approval/index',
    '관리'      => 'admin/index',
    '견적관리'  => 'estimate/index',
);

// ==========================
//  대시보드
// ==========================
if ($route === '대시보드') {
    $role = \App\Core\Auth::userRole();
    if ($role === 'executive' && $dashboardType === 'executive') {
        $view = 'dashboard/executive';
    } else {
        $view = 'dashboard/employee';
    }

    \App\Core\View::render($view, array(
        'title' => '대시보드',
        'selectedMenu' => '대시보드',
        'dashboardType' => $dashboardType,
    ));
    exit;
}

// ==========================
//  공무(프로젝트) 서브 페이지
// ==========================
if ($route === 'project/detail') {
    \App\Core\View::render('project/detail', array(
        'title' => '프로젝트 상세',
        'selectedMenu' => '공무',
        'dashboardType' => $dashboardType,
    ));
    exit;
}
if ($route === 'project/header_mapping') {
    \App\Core\View::render('project/header_mapping', array(
        'title' => '단가표 헤더 매핑',
        'selectedMenu' => '공무',
        'dashboardType' => $dashboardType,
    ));
    exit;
}

if ($route === 'approval_create') { \App\Core\View::render('approval/create', array('title'=>'전자결재 작성','selectedMenu'=>'전자결재','dashboardType'=>$dashboardType)); exit; }
if ($route === 'approval_detail') { \App\Core\View::render('approval/detail', array('title'=>'전자결재 상세','selectedMenu'=>'전자결재','dashboardType'=>$dashboardType)); exit; }
if ($route === 'approval_print') { require_once __DIR__ . '/../app/views/approval/print.php'; exit; }
if ($route === 'approval_download_excel') { require_once __DIR__ . '/../app/views/approval/download_excel.php'; exit; }
if ($route === 'approval_google_holiday_sync') { \App\Core\View::render('approval/google_holiday_sync', array('title'=>'공휴일 동기화','selectedMenu'=>'전자결재','dashboardType'=>$dashboardType)); exit; }
// ==========================
//  일반 메뉴
// ==========================
$view = isset($views[$route]) ? $views[$route] : 'placeholder/index';

\App\Core\View::render($view, array(
    'title' => $route,
    'selectedMenu' => $route,
    'dashboardType' => $dashboardType,
));
