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

// ==========================
//  공무(프로젝트) 액션(POST 처리)
// ==========================
if ($route === 'project/project_save') {
    require_once __DIR__ . '/../app/views/project/project_save.php';
    exit;
}

/**
 * ✅ [추가] 프로젝트 수정 저장(POST)
 * - app/views/project/project_update.php
 */
if ($route === 'project/project_update') {
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


/**
 * ✅ [추가] 프로젝트 생성 모달에서 엑셀 업로드 → 미리보기(JSON)
 * - app/views/project/project_create_preview.php
 */
if ($route === 'project/project_create_preview') {
    require_once __DIR__ . '/../app/views/project/project_create_preview.php';
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
if ($route === 'dashboard/issue_update') {
    require_once __DIR__ . '/../app/views/dashboard/issue_update.php';
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
if ($route === 'construction/schedule_delete') {
    require_once __DIR__ . '/../app/views/construction/schedule_delete.php';
    exit;
}
if ($route === 'construction/safety_incident_create') {
    require_once __DIR__ . '/../app/views/construction/safety_incident_create.php';
    exit;
}

// 공사 페이지 전용 이슈 등록/댓글(리다이렉트가 공사로 돌아오게)
if ($route === 'construction/issue_create') {
    require_once __DIR__ . '/../app/views/construction/issue_create.php';
    exit;
}
if ($route === 'construction/issue_comment_create') {
    require_once __DIR__ . '/../app/views/construction/issue_comment_create.php';
    exit;
}

// ==========================
//  안전(안전사고) 액션(POST 처리)
// ==========================
if ($route === 'safety/incident_update') {
    require_once __DIR__ . '/../app/views/safety/incident_update.php';
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
//  화면 매핑
// ==========================
$views = array(
    '공무'      => 'project/index',
    '공사'      => 'construction/index',
    '안전/보건' => 'safety/index',
    '품질'      => 'quality/index',
    '전자결재'  => 'placeholder/index',
    '관리'      => 'admin/index',
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

// ==========================
//  일반 메뉴
// ==========================
$view = isset($views[$route]) ? $views[$route] : 'placeholder/index';

\App\Core\View::render($view, array(
    'title' => $route,
    'selectedMenu' => $route,
    'dashboardType' => $dashboardType,
));
