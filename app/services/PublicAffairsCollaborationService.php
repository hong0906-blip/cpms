<?php
/**
 * 공무 협업툴 공통 서비스
 * - 업무/댓글/첨부/변경이력을 새 MySQL 테이블 없이 JSON 파일로 분리 저장한다.
 * - 기존 CPMS의 storage 경로, Auth 세션, 프로젝트/직원 조회 방식을 재사용한다.
 * - PHP 5.6 호환 문법만 사용한다.
 */

if (!function_exists('cpms_public_affairs_collab_config_path')) {
function cpms_public_affairs_collab_config_path() {
    return dirname(__DIR__) . '/config/public_affairs_collaboration.php';
}}

if (!function_exists('cpms_public_affairs_collab_default_settings')) {
function cpms_public_affairs_collab_default_settings() {
    $defaults = array(
        'task_types' => array(),
        'statuses' => array(),
        'priorities' => array(),
        'quick_filters' => array(),
        'card_fields' => array(),
        'status_transition_rules' => array(),
        'default_assignee_employee_id' => 0,
    );
    $configPath = cpms_public_affairs_collab_config_path();
    if (is_file($configPath)) {
        $loaded = require $configPath;
        if (is_array($loaded)) {
            foreach ($defaults as $key => $value) {
                if ($key === 'status_transition_rules' && isset($loaded[$key]) && is_array($loaded[$key])) {
                    $defaults[$key] = $loaded[$key];
                } elseif (isset($loaded[$key]) && is_array($loaded[$key])) {
                    $defaults[$key] = array_values($loaded[$key]);
                } elseif ($key === 'default_assignee_employee_id' && isset($loaded[$key])) {
                    $defaults[$key] = (int)$loaded[$key];
                }
            }
        }
    }
    return $defaults;
}}

if (!function_exists('cpms_public_affairs_collab_root_dir')) {
function cpms_public_affairs_collab_root_dir() {
    return cpms_storage_root() . '/public_affairs_collab';
}}

if (!function_exists('cpms_public_affairs_collab_store_path')) {
function cpms_public_affairs_collab_store_path($name) {
    $safe = preg_replace('/[^a-z0-9_\\-]/i', '', (string)$name);
    if ($safe === '') $safe = 'store';
    return cpms_public_affairs_collab_root_dir() . '/' . $safe . '.json';
}}

if (!function_exists('cpms_public_affairs_collab_read_json')) {
function cpms_public_affairs_collab_read_json($path, $defaultValue) {
    if (!is_file($path)) return $defaultValue;
    $text = @file_get_contents($path);
    if ($text === false || trim((string)$text) === '') return $defaultValue;
    $data = @json_decode($text, true);
    return is_array($data) ? $data : $defaultValue;
}}

if (!function_exists('cpms_public_affairs_collab_json_flags')) {
function cpms_public_affairs_collab_json_flags($pretty) {
    $flags = 0;
    if (defined('JSON_UNESCAPED_UNICODE')) $flags |= JSON_UNESCAPED_UNICODE;
    if ($pretty && defined('JSON_PRETTY_PRINT')) $flags |= JSON_PRETTY_PRINT;
    if (defined('JSON_HEX_TAG')) $flags |= JSON_HEX_TAG;
    if (defined('JSON_HEX_AMP')) $flags |= JSON_HEX_AMP;
    if (defined('JSON_HEX_APOS')) $flags |= JSON_HEX_APOS;
    if (defined('JSON_HEX_QUOT')) $flags |= JSON_HEX_QUOT;
    return $flags;
}}

if (!function_exists('cpms_public_affairs_collab_write_json')) {
function cpms_public_affairs_collab_write_json($path, $data) {
    $dir = dirname($path);
    if (!cpms_ensure_dir($dir)) return false;
    $json = json_encode($data, cpms_public_affairs_collab_json_flags(true));
    if (!is_string($json)) return false;
    return (@file_put_contents($path, $json, LOCK_EX) !== false);
}}

if (!function_exists('cpms_public_affairs_collab_bootstrap_result')) {
function cpms_public_affairs_collab_bootstrap_result() {
    return isset($GLOBALS['cpms_public_affairs_collab_bootstrap_result']) && is_array($GLOBALS['cpms_public_affairs_collab_bootstrap_result'])
        ? $GLOBALS['cpms_public_affairs_collab_bootstrap_result']
        : array('ok' => false, 'message' => '초기화가 실행되지 않았습니다.');
}}

if (!function_exists('cpms_public_affairs_collab_bootstrap_storage')) {
function cpms_public_affairs_collab_bootstrap_storage($create = true) {
    $create = (bool)$create;
    $root = function_exists('cpms_storage_root') ? cpms_storage_root() : dirname(dirname(__DIR__)) . '/storage';
    $collabRoot = cpms_public_affairs_collab_root_dir();
    $result = array(
        'ok' => false,
        'message' => '',
        'storage_root' => $root,
        'storage_root_exists' => is_dir($root) ? 1 : 0,
        'storage_root_writable' => (is_dir($root) && is_writable($root)) ? 1 : 0,
        'collab_root' => $collabRoot,
        'collab_root_exists' => is_dir($collabRoot) ? 1 : 0,
        'collab_root_writable' => (is_dir($collabRoot) && is_writable($collabRoot)) ? 1 : 0,
        'created' => array(),
    );

    if (!is_dir($root)) {
        if (!$create || !cpms_ensure_dir($root)) {
            $result['message'] = 'storage root 폴더를 만들 수 없습니다.';
            $GLOBALS['cpms_public_affairs_collab_bootstrap_result'] = $result;
            return $result;
        }
    }
    $result['storage_root_exists'] = is_dir($root) ? 1 : 0;
    $result['storage_root_writable'] = (is_dir($root) && is_writable($root)) ? 1 : 0;
    if (!$result['storage_root_exists'] || !$result['storage_root_writable']) {
        $result['message'] = 'storage root 폴더 쓰기 권한을 확인해주세요.';
        $GLOBALS['cpms_public_affairs_collab_bootstrap_result'] = $result;
        return $result;
    }

    if (!is_dir($collabRoot)) {
        if (!$create) {
            $result['message'] = '공무 협업툴 storage 폴더가 없습니다. repair 라우트를 실행해주세요.';
            $GLOBALS['cpms_public_affairs_collab_bootstrap_result'] = $result;
            return $result;
        }
        if (!cpms_ensure_dir($collabRoot)) {
            $result['message'] = '공무 협업툴 storage 폴더를 만들 수 없습니다.';
            $GLOBALS['cpms_public_affairs_collab_bootstrap_result'] = $result;
            return $result;
        }
    }
    $result['collab_root_exists'] = is_dir($collabRoot) ? 1 : 0;
    $result['collab_root_writable'] = (is_dir($collabRoot) && is_writable($collabRoot)) ? 1 : 0;
    if (!$result['collab_root_exists'] || !$result['collab_root_writable']) {
        $result['message'] = '공무 협업툴 storage 폴더 쓰기 권한을 확인해주세요.';
        $GLOBALS['cpms_public_affairs_collab_bootstrap_result'] = $result;
        return $result;
    }

    if ($create) {
        cpms_ensure_dir($collabRoot . '/files');
    }

    $storeNames = array('tasks', 'comments', 'attachments', 'history', 'collab_project_meta', 'project_activity', 'templates', 'checklists', 'saved_views');
    foreach ($storeNames as $storeName) {
        $path = cpms_public_affairs_collab_store_path($storeName);
        if (!is_file($path)) {
            if (!$create) {
                $result['message'] = $storeName . '.json 파일이 없습니다. repair 라우트를 실행해주세요.';
                $GLOBALS['cpms_public_affairs_collab_bootstrap_result'] = $result;
                return $result;
            }
            $ok = cpms_public_affairs_collab_write_json($path, array('last_id' => 0, 'items' => array()));
            $result['created'][$storeName . '.json'] = $ok ? 1 : 0;
            if (!$ok) {
                $result['message'] = $storeName . '.json 파일을 만들 수 없습니다.';
                $GLOBALS['cpms_public_affairs_collab_bootstrap_result'] = $result;
                return $result;
            }
        } else {
            $result['created'][$storeName . '.json'] = 0;
        }
    }

    $settingsPath = cpms_public_affairs_collab_store_path('settings');
    if (!is_file($settingsPath)) {
        if (!$create) {
            $result['message'] = 'settings.json 파일이 없습니다. repair 라우트를 실행해주세요.';
            $GLOBALS['cpms_public_affairs_collab_bootstrap_result'] = $result;
            return $result;
        }
        $settings = cpms_public_affairs_collab_default_settings();
        $settings['updated_at'] = date('Y-m-d H:i:s');
        $ok = cpms_public_affairs_collab_write_json($settingsPath, $settings);
        $result['created']['settings.json'] = $ok ? 1 : 0;
        if (!$ok) {
            $result['message'] = 'settings.json 파일을 만들 수 없습니다.';
            $GLOBALS['cpms_public_affairs_collab_bootstrap_result'] = $result;
            return $result;
        }
    } else {
        $result['created']['settings.json'] = 0;
    }

    $result['ok'] = true;
    $result['message'] = 'OK';
    $GLOBALS['cpms_public_affairs_collab_bootstrap_result'] = $result;
    return $result;
}}

if (!function_exists('cpms_public_affairs_collab_load_store')) {
function cpms_public_affairs_collab_load_store($name) {
    cpms_public_affairs_collab_bootstrap_storage(true);
    $path = cpms_public_affairs_collab_store_path($name);
    $store = cpms_public_affairs_collab_read_json($path, array());
    if ((string)$name === 'attachments' && !is_file($path)) {
        // 공무 협업툴 첨부파일: 이전 구현의 files.json이 있으면 attachments.json으로 이어받는다.
        $legacyPath = cpms_public_affairs_collab_root_dir() . '/files.json';
        $legacyStore = cpms_public_affairs_collab_read_json($legacyPath, array());
        if (isset($legacyStore['items']) && is_array($legacyStore['items'])) $store = $legacyStore;
    }
    if (!isset($store['last_id'])) $store['last_id'] = 0;
    if (!isset($store['items']) || !is_array($store['items'])) $store['items'] = array();
    return $store;
}}

if (!function_exists('cpms_public_affairs_collab_save_store')) {
function cpms_public_affairs_collab_save_store($name, $store) {
    if (!is_array($store)) $store = array();
    if (!isset($store['last_id'])) $store['last_id'] = 0;
    if (!isset($store['items']) || !is_array($store['items'])) $store['items'] = array();
    return cpms_public_affairs_collab_write_json(cpms_public_affairs_collab_store_path($name), $store);
}}

if (!function_exists('cpms_public_affairs_collab_normalize_setting_list')) {
function cpms_public_affairs_collab_normalize_setting_list($value, $fallback) {
    $items = array();
    if (is_string($value)) {
        $value = preg_split('/\\r\\n|\\r|\\n/', $value);
    }
    if (is_array($value)) {
        foreach ($value as $row) {
            $row = trim((string)$row);
            if ($row === '') continue;
            if (!in_array($row, $items, true)) $items[] = $row;
        }
    }
    if (count($items) === 0 && is_array($fallback)) return array_values($fallback);
    return $items;
}}

if (!function_exists('cpms_public_affairs_collab_settings')) {
function cpms_public_affairs_collab_settings() {
    cpms_public_affairs_collab_bootstrap_storage(true);
    $defaults = cpms_public_affairs_collab_default_settings();
    $stored = cpms_public_affairs_collab_read_json(cpms_public_affairs_collab_store_path('settings'), array());
    $settings = array();
    foreach ($defaults as $key => $fallback) {
        if ($key === 'default_assignee_employee_id') {
            $settings[$key] = isset($stored[$key]) ? (int)$stored[$key] : (int)$fallback;
        } elseif ($key === 'status_transition_rules') {
            $settings[$key] = isset($stored[$key]) && is_array($stored[$key]) ? $stored[$key] : $fallback;
        } else {
            $settings[$key] = cpms_public_affairs_collab_normalize_setting_list(isset($stored[$key]) ? $stored[$key] : array(), $fallback);
        }
    }
    return $settings;
}}

if (!function_exists('cpms_public_affairs_collab_save_settings')) {
function cpms_public_affairs_collab_save_settings($settings) {
    $defaults = cpms_public_affairs_collab_default_settings();
    $data = array();
    foreach ($defaults as $key => $fallback) {
        if ($key === 'default_assignee_employee_id') {
            $data[$key] = isset($settings[$key]) ? (int)$settings[$key] : 0;
        } elseif ($key === 'status_transition_rules') {
            $data[$key] = isset($settings[$key]) && is_array($settings[$key]) ? $settings[$key] : $fallback;
        } else {
            $data[$key] = cpms_public_affairs_collab_normalize_setting_list(isset($settings[$key]) ? $settings[$key] : array(), $fallback);
        }
    }
    $data['updated_at'] = date('Y-m-d H:i:s');
    return cpms_public_affairs_collab_write_json(cpms_public_affairs_collab_store_path('settings'), $data);
}}

if (!function_exists('cpms_public_affairs_collab_template_seed_row')) {
function cpms_public_affairs_collab_template_seed_row($sortOrder, $name, $taskType, $title, $content, $priority, $dueDays, $contractImpact, $scheduleImpact, $checklistItems) {
    $now = date('Y-m-d H:i:s');
    return array(
        'id' => (int)$sortOrder,
        'template_name' => (string)$name,
        'task_type' => (string)$taskType,
        'default_title' => (string)$title,
        'default_content' => (string)$content,
        'default_status' => '할 일',
        'default_priority' => (string)$priority,
        'default_due_days' => (int)$dueDays,
        'default_contract_impact' => (string)$contractImpact,
        'default_schedule_impact' => (string)$scheduleImpact,
        'checklist_items' => is_array($checklistItems) ? array_values($checklistItems) : array(),
        'is_active' => 1,
        'sort_order' => (int)$sortOrder,
        'created_at' => $now,
        'updated_at' => $now,
    );
}}

if (!function_exists('cpms_public_affairs_collab_default_templates')) {
function cpms_public_affairs_collab_default_templates() {
    // 공무 협업툴 업무 템플릿: 반복되는 입찰/계약/기성/변경계약 업무를 빠르게 생성하기 위한 기본값.
    return array(
        cpms_public_affairs_collab_template_seed_row(1, '입찰 검토', '기타', '입찰 검토 - {{project_name}}', '입찰 조건, 공사 범위, 제출 마감일, 리스크 사항을 확인해주세요.', '높음', 3, '확인필요', '확인필요', array('입찰 공고 확인', '현장 설명 조건 확인', '공사 범위 확인', '제출 마감일 확인', '필요 서류 확인', '리스크 사항 기록')),
        cpms_public_affairs_collab_template_seed_row(2, '견적 요청', '기타', '견적 요청 - {{project_name}}', '견적 요청 범위와 협력업체 회신 일정을 정리해주세요.', '보통', 3, '없음', '확인필요', array('견적 요청 범위 정리', '협력업체 선정', '견적 요청 발송', '견적 회신 확인', '비교표 작성', '최종 단가 검토')),
        cpms_public_affairs_collab_template_seed_row(3, '계약 검토', '계약 검토', '계약 검토 - {{project_name}}', '계약금액, 공사기간, 특수조건과 보증 조건을 검토해주세요.', '높음', 3, '있음', '확인필요', array('계약금액 확인', '공사기간 확인', '발주처/시공사 정보 확인', '계약 특수조건 확인', '지체상금 조건 확인', '하자보증 조건 확인', '내부 결재 필요 여부 확인')),
        cpms_public_affairs_collab_template_seed_row(4, '단가내역서 검토', '내역서 검토', '단가내역서 검토 - {{project_name}}', '품명, 규격, 단위, 수량, 단가와 합계 금액을 검토해주세요.', '보통', 4, '있음', '확인필요', array('품명 누락 확인', '규격 확인', '단위 확인', '수량 확인', '단가 확인', '금액 합계 확인', '공정표 연동 가능 여부 확인')),
        cpms_public_affairs_collab_template_seed_row(5, '실행내역 확인', '실행내역 확인', '실행내역 확인 - {{project_name}}', '실행예산 기준과 주요 공종 금액, 원가율 영향을 확인해주세요.', '보통', 4, '있음', '없음', array('실행예산 기준 확인', '주요 공종 금액 확인', '원가율 영향 확인', '누락 공종 확인', '위험 공종 표시', '담당자 확인')),
        cpms_public_affairs_collab_template_seed_row(6, '변경계약 검토', '변경계약', '변경계약 검토 - {{project_name}}', '변경계약 사유, 변경금액, 공기 영향 여부를 확인해주세요.', '높음', 3, '있음', '확인필요', array('변경 사유 확인', '변경 전/후 내역 비교', '변경금액 확인', '공기 영향 확인', '발주처 승인 여부 확인', '내부 결재 필요 여부 확인')),
        cpms_public_affairs_collab_template_seed_row(7, '추가공사 검토', '추가공사', '추가공사 검토 - {{project_name}}', '추가공사 발생 사유, 작업 범위, 근거자료와 계약 반영 여부를 확인해주세요.', '높음', 3, '있음', '확인필요', array('추가공사 발생 사유 확인', '작업 범위 확인', '사진/근거자료 첨부', '견적금액 확인', '발주처 승인 여부 확인', '계약 반영 여부 확인')),
        cpms_public_affairs_collab_template_seed_row(8, '기성 청구 준비', '기성/청구', '기성 청구 준비 - {{project_name}}', '기성 기준월, 기성률, 증빙자료와 청구금액을 정리해주세요.', '높음', 5, '있음', '없음', array('기성 기준월 확인', '작업 완료 범위 확인', '기성률 확인', '증빙자료 첨부', '청구금액 확인', '발주처 제출 여부 확인')),
        cpms_public_affairs_collab_template_seed_row(9, '발주처 요청사항 처리', '발주처 요청사항', '발주처 요청사항 처리 - {{project_name}}', '발주처 요청 내용과 회신 필요일, 담당자와 처리 결과를 기록해주세요.', '보통', 2, '확인필요', '확인필요', array('요청 내용 확인', '요청일 확인', '회신 필요일 확인', '담당자 지정', '관련 자료 첨부', '처리 결과 기록')),
        cpms_public_affairs_collab_template_seed_row(10, '회의 후속조치', '회의 후속조치', '회의 후속조치 - {{project_name}}', '회의 결정사항과 담당자별 후속조치 마감일을 정리해주세요.', '보통', 2, '없음', '확인필요', array('회의 일자 확인', '참석자 확인', '결정사항 정리', '담당자 지정', '마감일 지정', '완료 여부 확인')),
    );
}}

if (!function_exists('cpms_public_affairs_collab_sort_template')) {
function cpms_public_affairs_collab_sort_template($a, $b) {
    $aSort = is_array($a) && isset($a['sort_order']) ? (int)$a['sort_order'] : 0;
    $bSort = is_array($b) && isset($b['sort_order']) ? (int)$b['sort_order'] : 0;
    if ($aSort === $bSort) {
        $aId = is_array($a) && isset($a['id']) ? (int)$a['id'] : 0;
        $bId = is_array($b) && isset($b['id']) ? (int)$b['id'] : 0;
        if ($aId === $bId) return 0;
        return ($aId < $bId) ? -1 : 1;
    }
    return ($aSort < $bSort) ? -1 : 1;
}}

if (!function_exists('cpms_public_affairs_collab_normalize_template')) {
function cpms_public_affairs_collab_normalize_template($row) {
    if (!is_array($row)) $row = array();
    if (!isset($row['id'])) $row['id'] = 0;
    if (!isset($row['template_name'])) $row['template_name'] = '';
    if (!isset($row['task_type'])) $row['task_type'] = '기타';
    if (!isset($row['default_title'])) $row['default_title'] = '';
    if (!isset($row['default_content'])) $row['default_content'] = '';
    if (!isset($row['default_status']) || trim((string)$row['default_status']) === '') $row['default_status'] = '할 일';
    if (!isset($row['default_priority']) || trim((string)$row['default_priority']) === '') $row['default_priority'] = '보통';
    if (!isset($row['default_due_days'])) $row['default_due_days'] = 0;
    if (!isset($row['default_contract_impact']) || trim((string)$row['default_contract_impact']) === '') $row['default_contract_impact'] = '없음';
    if (!isset($row['default_schedule_impact']) || trim((string)$row['default_schedule_impact']) === '') $row['default_schedule_impact'] = '없음';
    if (!isset($row['checklist_items']) || !is_array($row['checklist_items'])) $row['checklist_items'] = array();
    $cleanItems = array();
    foreach ($row['checklist_items'] as $item) {
        $item = trim((string)$item);
        if ($item !== '') $cleanItems[] = $item;
    }
    $row['checklist_items'] = $cleanItems;
    if (!isset($row['is_active'])) $row['is_active'] = 1;
    if (!isset($row['sort_order'])) $row['sort_order'] = isset($row['id']) ? (int)$row['id'] : 0;
    if (!isset($row['created_at'])) $row['created_at'] = '';
    if (!isset($row['updated_at'])) $row['updated_at'] = '';
    return $row;
}}

if (!function_exists('cpms_public_affairs_collab_templates')) {
function cpms_public_affairs_collab_templates($activeOnly = false) {
    $store = cpms_public_affairs_collab_load_store('templates');
    if (!isset($store['items']) || !is_array($store['items']) || count($store['items']) === 0) {
        $items = cpms_public_affairs_collab_default_templates();
        $store = array('last_id' => count($items), 'items' => $items);
        cpms_public_affairs_collab_save_store('templates', $store);
    }
    $items = array();
    foreach ($store['items'] as $row) {
        $row = cpms_public_affairs_collab_normalize_template($row);
        if ($activeOnly && empty($row['is_active'])) continue;
        $items[] = $row;
    }
    usort($items, 'cpms_public_affairs_collab_sort_template');
    return $items;
}}

if (!function_exists('cpms_public_affairs_collab_template_by_id')) {
function cpms_public_affairs_collab_template_by_id($templateId) {
    $templateId = (int)$templateId;
    if ($templateId <= 0) return null;
    $items = cpms_public_affairs_collab_templates(false);
    foreach ($items as $row) {
        if (isset($row['id']) && (int)$row['id'] === $templateId) return $row;
    }
    return null;
}}

if (!function_exists('cpms_public_affairs_collab_reset_templates')) {
function cpms_public_affairs_collab_reset_templates($actor) {
    if (!cpms_public_affairs_collab_is_admin_user()) return array('ok' => false, 'message' => '템플릿 관리 권한이 없습니다.');
    $items = cpms_public_affairs_collab_default_templates();
    $ok = cpms_public_affairs_collab_save_store('templates', array('last_id' => count($items), 'items' => $items));
    if ($ok) {
        cpms_public_affairs_collab_add_project_activity(0, '템플릿 기본값 재생성', 'template', '', '기본 템플릿', '공무 협업툴 기본 업무 템플릿을 다시 생성했습니다.', $actor, 0);
    }
    return array('ok' => $ok, 'message' => $ok ? '기본 템플릿을 다시 생성했습니다.' : '템플릿 저장에 실패했습니다.');
}}

if (!function_exists('cpms_public_affairs_collab_toggle_template')) {
function cpms_public_affairs_collab_toggle_template($templateId, $isActive, $actor) {
    if (!cpms_public_affairs_collab_is_admin_user()) return array('ok' => false, 'message' => '템플릿 관리 권한이 없습니다.');
    $templateId = (int)$templateId;
    $store = cpms_public_affairs_collab_load_store('templates');
    $found = false;
    $templateName = '';
    for ($i = 0; $i < count($store['items']); $i++) {
        if (!is_array($store['items'][$i]) || !isset($store['items'][$i]['id']) || (int)$store['items'][$i]['id'] !== $templateId) continue;
        $store['items'][$i] = cpms_public_affairs_collab_normalize_template($store['items'][$i]);
        $templateName = (string)$store['items'][$i]['template_name'];
        $store['items'][$i]['is_active'] = $isActive ? 1 : 0;
        $store['items'][$i]['updated_at'] = date('Y-m-d H:i:s');
        $found = true;
        break;
    }
    if (!$found) return array('ok' => false, 'message' => '템플릿을 찾을 수 없습니다.');
    $ok = cpms_public_affairs_collab_save_store('templates', $store);
    if ($ok) {
        cpms_public_affairs_collab_add_project_activity(0, $isActive ? '템플릿 활성화' : '템플릿 비활성화', 'template', '', $templateName, '업무 템플릿 상태를 변경했습니다.', $actor, 0);
    }
    return array('ok' => $ok, 'message' => $ok ? '템플릿 상태를 변경했습니다.' : '템플릿 저장에 실패했습니다.');
}}

if (!function_exists('cpms_public_affairs_collab_apply_template_vars')) {
function cpms_public_affairs_collab_apply_template_vars($text, $projectName, $project) {
    $client = '';
    $contractor = '';
    if (is_array($project)) {
        $client = isset($project['client']) ? (string)$project['client'] : '';
        $contractor = isset($project['contractor']) ? (string)$project['contractor'] : '';
    }
    return str_replace(
        array('{{project_name}}', '{{today}}', '{{client}}', '{{contractor}}'),
        array((string)$projectName, date('Y-m-d'), $client, $contractor),
        (string)$text
    );
}}

if (!function_exists('cpms_public_affairs_collab_due_date_from_days')) {
function cpms_public_affairs_collab_due_date_from_days($days) {
    $days = (int)$days;
    if ($days <= 0) return '';
    return date('Y-m-d', strtotime('+' . $days . ' day'));
}}

if (!function_exists('cpms_public_affairs_collab_checklists')) {
function cpms_public_affairs_collab_checklists($taskId) {
    $taskId = (int)$taskId;
    $store = cpms_public_affairs_collab_load_store('checklists');
    $rows = array();
    foreach ($store['items'] as $row) {
        if (!is_array($row) || !isset($row['task_id']) || (int)$row['task_id'] !== $taskId) continue;
        if (isset($row['deleted_at']) && trim((string)$row['deleted_at']) !== '') continue;
        if (!isset($row['is_done'])) $row['is_done'] = 0;
        if (!isset($row['sort_order'])) $row['sort_order'] = isset($row['id']) ? (int)$row['id'] : 0;
        $rows[] = $row;
    }
    usort($rows, 'cpms_public_affairs_collab_sort_template');
    return $rows;
}}

if (!function_exists('cpms_public_affairs_collab_checklist_counts_by_task')) {
function cpms_public_affairs_collab_checklist_counts_by_task() {
    $store = cpms_public_affairs_collab_load_store('checklists');
    $counts = array();
    foreach ($store['items'] as $row) {
        if (!is_array($row) || !isset($row['task_id'])) continue;
        if (isset($row['deleted_at']) && trim((string)$row['deleted_at']) !== '') continue;
        $taskId = (int)$row['task_id'];
        if ($taskId <= 0) continue;
        if (!isset($counts[$taskId])) $counts[$taskId] = array('total' => 0, 'done' => 0);
        $counts[$taskId]['total']++;
        if (!empty($row['is_done'])) $counts[$taskId]['done']++;
    }
    return $counts;
}}

if (!function_exists('cpms_public_affairs_collab_checklist_count_for_task')) {
function cpms_public_affairs_collab_checklist_count_for_task($counts, $taskId, $key) {
    $taskId = (int)$taskId;
    if (!is_array($counts) || !isset($counts[$taskId]) || !is_array($counts[$taskId])) return 0;
    return isset($counts[$taskId][$key]) ? (int)$counts[$taskId][$key] : 0;
}}

if (!function_exists('cpms_public_affairs_collab_sync_task_checklist_counts')) {
function cpms_public_affairs_collab_sync_task_checklist_counts($taskId) {
    $taskId = (int)$taskId;
    if ($taskId <= 0) return false;
    $counts = cpms_public_affairs_collab_checklist_counts_by_task();
    $total = cpms_public_affairs_collab_checklist_count_for_task($counts, $taskId, 'total');
    $done = cpms_public_affairs_collab_checklist_count_for_task($counts, $taskId, 'done');
    $store = cpms_public_affairs_collab_load_store('tasks');
    $changed = false;
    for ($i = 0; $i < count($store['items']); $i++) {
        if (!is_array($store['items'][$i]) || !isset($store['items'][$i]['id']) || (int)$store['items'][$i]['id'] !== $taskId) continue;
        if (!isset($store['items'][$i]['checklist_total']) || (int)$store['items'][$i]['checklist_total'] !== $total) {
            $store['items'][$i]['checklist_total'] = $total;
            $changed = true;
        }
        if (!isset($store['items'][$i]['checklist_done']) || (int)$store['items'][$i]['checklist_done'] !== $done) {
            $store['items'][$i]['checklist_done'] = $done;
            $changed = true;
        }
        break;
    }
    if (!$changed) return true;
    return cpms_public_affairs_collab_save_store('tasks', $store);
}}

if (!function_exists('cpms_public_affairs_collab_create_checklists_for_task')) {
function cpms_public_affairs_collab_create_checklists_for_task($task, $items, $actor) {
    if (!is_array($task) || !isset($task['id']) || !is_array($items) || count($items) === 0) return 0;
    $store = cpms_public_affairs_collab_load_store('checklists');
    $created = 0;
    $now = date('Y-m-d H:i:s');
    $sort = 1;
    foreach ($items as $title) {
        $title = cpms_public_affairs_collab_clean_text($title, 200);
        if ($title === '') continue;
        $nextId = (int)$store['last_id'] + 1;
        $store['last_id'] = $nextId;
        $store['items'][] = array(
            'id' => $nextId,
            'task_id' => (int)$task['id'],
            'project_id' => isset($task['project_id']) ? (int)$task['project_id'] : 0,
            'title' => $title,
            'is_done' => 0,
            'sort_order' => $sort,
            'created_at' => $now,
            'updated_at' => $now,
            'completed_at' => '',
            'completed_by_id' => 0,
            'completed_by_name' => '',
            'deleted_at' => '',
        );
        $sort++;
        $created++;
    }
    if ($created <= 0) return 0;
    if (!cpms_public_affairs_collab_save_store('checklists', $store)) return 0;
    cpms_public_affairs_collab_sync_task_checklist_counts((int)$task['id']);
    cpms_public_affairs_collab_add_history((int)$task['id'], isset($task['project_id']) ? (int)$task['project_id'] : 0, '체크리스트 생성', 'checklist', '', (string)$created . '개', '템플릿 체크리스트가 생성되었습니다.', $actor);
    cpms_public_affairs_collab_add_project_activity(isset($task['project_id']) ? (int)$task['project_id'] : 0, '체크리스트 생성', 'checklist', '', cpms_public_affairs_collab_task_no($task), '업무 체크리스트가 생성되었습니다.', $actor, (int)$task['id']);
    return $created;
}}

if (!function_exists('cpms_public_affairs_collab_add_checklist_item')) {
function cpms_public_affairs_collab_add_checklist_item($task, $title, $actor) {
    if (!is_array($task)) return array('ok' => false, 'message' => '업무를 찾을 수 없습니다.');
    $title = cpms_public_affairs_collab_clean_text($title, 200);
    if ($title === '') return array('ok' => false, 'message' => '체크리스트 항목을 입력해주세요.');
    $store = cpms_public_affairs_collab_load_store('checklists');
    $nextId = (int)$store['last_id'] + 1;
    $store['last_id'] = $nextId;
    $rows = cpms_public_affairs_collab_checklists((int)$task['id']);
    $now = date('Y-m-d H:i:s');
    $store['items'][] = array(
        'id' => $nextId,
        'task_id' => (int)$task['id'],
        'project_id' => isset($task['project_id']) ? (int)$task['project_id'] : 0,
        'title' => $title,
        'is_done' => 0,
        'sort_order' => count($rows) + 1,
        'created_at' => $now,
        'updated_at' => $now,
        'completed_at' => '',
        'completed_by_id' => 0,
        'completed_by_name' => '',
        'deleted_at' => '',
    );
    if (!cpms_public_affairs_collab_save_store('checklists', $store)) return array('ok' => false, 'message' => '체크리스트 저장에 실패했습니다.');
    cpms_public_affairs_collab_sync_task_checklist_counts((int)$task['id']);
    cpms_public_affairs_collab_add_history((int)$task['id'], isset($task['project_id']) ? (int)$task['project_id'] : 0, '체크리스트 항목 추가', 'checklist', '', $title, '체크리스트 항목이 추가되었습니다.', $actor);
    cpms_public_affairs_collab_add_project_activity(isset($task['project_id']) ? (int)$task['project_id'] : 0, '체크리스트 항목 추가', 'checklist', '', $title, '업무 체크리스트 항목이 추가되었습니다.', $actor, (int)$task['id']);
    return array('ok' => true, 'message' => '체크리스트 항목을 추가했습니다.', 'checklist_id' => $nextId);
}}

if (!function_exists('cpms_public_affairs_collab_toggle_checklist_item')) {
function cpms_public_affairs_collab_toggle_checklist_item($task, $checklistId, $isDone, $actor) {
    if (!is_array($task)) return array('ok' => false, 'message' => '업무를 찾을 수 없습니다.');
    $checklistId = (int)$checklistId;
    $store = cpms_public_affairs_collab_load_store('checklists');
    $found = false;
    $title = '';
    $oldValue = '';
    $newValue = $isDone ? '완료' : '미완료';
    for ($i = 0; $i < count($store['items']); $i++) {
        if (!is_array($store['items'][$i]) || !isset($store['items'][$i]['id']) || (int)$store['items'][$i]['id'] !== $checklistId) continue;
        if (!isset($store['items'][$i]['task_id']) || (int)$store['items'][$i]['task_id'] !== (int)$task['id']) continue;
        $title = isset($store['items'][$i]['title']) ? (string)$store['items'][$i]['title'] : '';
        $oldValue = !empty($store['items'][$i]['is_done']) ? '완료' : '미완료';
        $store['items'][$i]['is_done'] = $isDone ? 1 : 0;
        $store['items'][$i]['updated_at'] = date('Y-m-d H:i:s');
        if ($isDone) {
            $store['items'][$i]['completed_at'] = date('Y-m-d H:i:s');
            $store['items'][$i]['completed_by_id'] = is_array($actor) && isset($actor['id']) ? (int)$actor['id'] : 0;
            $store['items'][$i]['completed_by_name'] = cpms_public_affairs_collab_actor_label($actor);
        } else {
            $store['items'][$i]['completed_at'] = '';
            $store['items'][$i]['completed_by_id'] = 0;
            $store['items'][$i]['completed_by_name'] = '';
        }
        $found = true;
        break;
    }
    if (!$found) return array('ok' => false, 'message' => '체크리스트 항목을 찾을 수 없습니다.');
    if (!cpms_public_affairs_collab_save_store('checklists', $store)) return array('ok' => false, 'message' => '체크리스트 저장에 실패했습니다.');
    cpms_public_affairs_collab_sync_task_checklist_counts((int)$task['id']);
    $action = $isDone ? '체크리스트 완료' : '체크리스트 해제';
    cpms_public_affairs_collab_add_history((int)$task['id'], isset($task['project_id']) ? (int)$task['project_id'] : 0, $action, 'checklist', $oldValue, $newValue, $title, $actor);
    cpms_public_affairs_collab_add_project_activity(isset($task['project_id']) ? (int)$task['project_id'] : 0, $action, 'checklist', $oldValue, $newValue, $title, $actor, (int)$task['id']);
    return array('ok' => true, 'message' => $isDone ? '체크리스트를 완료했습니다.' : '체크리스트 완료를 해제했습니다.');
}}

if (!function_exists('cpms_public_affairs_collab_saved_views')) {
function cpms_public_affairs_collab_saved_views($projectId) {
    $projectId = (int)$projectId;
    $store = cpms_public_affairs_collab_load_store('saved_views');
    $rows = array();
    foreach ($store['items'] as $row) {
        if (!is_array($row) || !isset($row['project_id']) || (int)$row['project_id'] !== $projectId) continue;
        if (isset($row['deleted_at']) && trim((string)$row['deleted_at']) !== '') continue;
        $rows[] = $row;
    }
    usort($rows, 'cpms_public_affairs_collab_sort_created_desc');
    return $rows;
}}

if (!function_exists('cpms_public_affairs_collab_saved_view_by_id')) {
function cpms_public_affairs_collab_saved_view_by_id($viewId, $projectId) {
    $viewId = (int)$viewId;
    $rows = cpms_public_affairs_collab_saved_views($projectId);
    foreach ($rows as $row) {
        if (isset($row['id']) && (int)$row['id'] === $viewId) return $row;
    }
    return null;
}}

if (!function_exists('cpms_public_affairs_collab_save_view')) {
function cpms_public_affairs_collab_save_view($projectId, $post, $actor) {
    $projectId = (int)$projectId;
    if ($projectId <= 0) return array('ok' => false, 'message' => '프로젝트를 선택해주세요.');
    $name = cpms_public_affairs_collab_clean_text(isset($post['view_name']) ? $post['view_name'] : '', 80);
    if ($name === '') return array('ok' => false, 'message' => '저장할 보기 이름을 입력해주세요.');
    $allowedFilterKeys = array('project_name','assignee_employee_id','requester_employee_id','task_type','priority','status','due_to','due_from','keyword','quick','section','view_mode','contract_impact','schedule_impact');
    $filters = array();
    foreach ($allowedFilterKeys as $key) {
        if (isset($post[$key])) $filters[$key] = is_array($post[$key]) ? array_values($post[$key]) : (string)$post[$key];
    }
    $store = cpms_public_affairs_collab_load_store('saved_views');
    $nextId = (int)$store['last_id'] + 1;
    $store['last_id'] = $nextId;
    $now = date('Y-m-d H:i:s');
    $store['items'][] = array(
        'id' => $nextId,
        'project_id' => $projectId,
        'view_name' => $name,
        'section' => isset($post['section']) ? (string)$post['section'] : 'board',
        'filters' => $filters,
        'created_by_id' => is_array($actor) && isset($actor['id']) ? (int)$actor['id'] : 0,
        'created_by_name' => cpms_public_affairs_collab_actor_label($actor),
        'created_at' => $now,
        'updated_at' => $now,
        'deleted_at' => '',
    );
    if (!cpms_public_affairs_collab_save_store('saved_views', $store)) return array('ok' => false, 'message' => '저장된 보기 저장에 실패했습니다.');
    cpms_public_affairs_collab_add_project_activity($projectId, 'Saved View 생성', 'saved_view', '', $name, '필터 보기를 저장했습니다.', $actor, 0);
    return array('ok' => true, 'message' => '현재 필터를 Saved View로 저장했습니다.', 'view_id' => $nextId);
}}

if (!function_exists('cpms_public_affairs_collab_delete_saved_view')) {
function cpms_public_affairs_collab_delete_saved_view($viewId, $projectId, $actor) {
    $viewId = (int)$viewId;
    $projectId = (int)$projectId;
    $store = cpms_public_affairs_collab_load_store('saved_views');
    $found = false;
    $viewName = '';
    for ($i = 0; $i < count($store['items']); $i++) {
        if (!is_array($store['items'][$i]) || !isset($store['items'][$i]['id']) || (int)$store['items'][$i]['id'] !== $viewId) continue;
        if (!isset($store['items'][$i]['project_id']) || (int)$store['items'][$i]['project_id'] !== $projectId) continue;
        $creatorId = isset($store['items'][$i]['created_by_id']) ? (int)$store['items'][$i]['created_by_id'] : 0;
        $actorId = is_array($actor) && isset($actor['id']) ? (int)$actor['id'] : 0;
        if (!cpms_public_affairs_collab_is_admin_user() && ($creatorId <= 0 || $creatorId !== $actorId)) {
            return array('ok' => false, 'message' => 'Saved View 삭제 권한이 없습니다.');
        }
        $viewName = isset($store['items'][$i]['view_name']) ? (string)$store['items'][$i]['view_name'] : '';
        $store['items'][$i]['deleted_at'] = date('Y-m-d H:i:s');
        $store['items'][$i]['updated_at'] = date('Y-m-d H:i:s');
        $found = true;
        break;
    }
    if (!$found) return array('ok' => false, 'message' => 'Saved View를 찾을 수 없습니다.');
    if (!cpms_public_affairs_collab_save_store('saved_views', $store)) return array('ok' => false, 'message' => 'Saved View 삭제 처리에 실패했습니다.');
    cpms_public_affairs_collab_add_project_activity($projectId, 'Saved View 삭제', 'saved_view', $viewName, '', '저장된 필터 보기를 삭제했습니다.', $actor, 0);
    return array('ok' => true, 'message' => 'Saved View를 삭제했습니다.');
}}

if (!function_exists('cpms_public_affairs_collab_normalize_dept')) {
function cpms_public_affairs_collab_normalize_dept($dept) {
    $dept = trim((string)$dept);
    $map = array(
        '공무부' => '공무',
        '공무팀' => '공무',
        '관리부' => '관리',
        '관리팀' => '관리',
        '공사부' => '공사',
        '공사팀' => '공사',
        '안전부' => '안전',
        '안전팀' => '안전',
        '안전/보건' => '보건',
        '안전보건' => '보건',
        '보건부' => '보건',
        '보건팀' => '보건',
        '품질부' => '품질',
        '품질팀' => '품질',
    );
    if (isset($map[$dept])) return $map[$dept];
    foreach (array('공무', '관리', '공사', '안전', '품질', '보건') as $keyword) {
        if (strpos($dept, $keyword) !== false) return $keyword;
    }
    return $dept;
}}

if (!function_exists('cpms_public_affairs_collab_table_exists')) {
function cpms_public_affairs_collab_table_exists($pdo, $tableName) {
    if (!$pdo) return false;
    $tableName = trim((string)$tableName);
    if ($tableName === '') return false;
    try {
        $st = $pdo->prepare("SHOW TABLES LIKE :table_name");
        $st->execute(array(':table_name' => $tableName));
        return (bool)$st->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_public_affairs_collab_column_expr')) {
function cpms_public_affairs_collab_column_expr($pdo, $tableName, $columnName, $fallbackExpr) {
    if (!$pdo) return $fallbackExpr;
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `" . str_replace('`', '', $tableName) . "` LIKE :column_name");
        $st->execute(array(':column_name' => $columnName));
        if ($st->fetch(PDO::FETCH_ASSOC)) return '`' . str_replace('`', '', $columnName) . '`';
    } catch (Exception $e) {
    }
    return $fallbackExpr;
}}

if (!function_exists('cpms_public_affairs_collab_column_exists')) {
function cpms_public_affairs_collab_column_exists($pdo, $tableName, $columnName) {
    if (!$pdo) return false;
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `" . str_replace('`', '', $tableName) . "` LIKE :column_name");
        $st->execute(array(':column_name' => $columnName));
        return $st->fetch(PDO::FETCH_ASSOC) ? true : false;
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_public_affairs_collab_is_draft_project_name')) {
function cpms_public_affairs_collab_is_draft_project_name($name) {
    $name = trim((string)$name);
    return (strpos($name, '(가제)') === 0);
}}

if (!function_exists('cpms_public_affairs_collab_draft_project_name')) {
function cpms_public_affairs_collab_draft_project_name($name) {
    $name = trim((string)$name);
    if ($name === '') return '';
    if (cpms_public_affairs_collab_is_draft_project_name($name)) return $name;
    return '(가제) ' . $name;
}}

if (!function_exists('cpms_public_affairs_collab_official_project_name')) {
function cpms_public_affairs_collab_official_project_name($name) {
    $name = trim((string)$name);
    if (cpms_public_affairs_collab_is_draft_project_name($name)) {
        $name = trim(substr($name, strlen('(가제)')));
    }
    return $name;
}}

if (!function_exists('cpms_public_affairs_collab_load_project_meta')) {
function cpms_public_affairs_collab_load_project_meta() {
    // 공무 협업툴 프로젝트 Space: 가제 여부/즐겨찾기/단계를 DB 컬럼 추가 없이 JSON으로 보관한다.
    return cpms_public_affairs_collab_load_store('collab_project_meta');
}}

if (!function_exists('cpms_public_affairs_collab_save_project_meta')) {
function cpms_public_affairs_collab_save_project_meta($store) {
    return cpms_public_affairs_collab_save_store('collab_project_meta', $store);
}}

if (!function_exists('cpms_public_affairs_collab_project_meta_by_id')) {
function cpms_public_affairs_collab_project_meta_by_id($projectId, $store) {
    $projectId = (int)$projectId;
    if ($projectId <= 0 || !is_array($store) || !isset($store['items']) || !is_array($store['items'])) return array();
    foreach ($store['items'] as $row) {
        if (is_array($row) && isset($row['project_id']) && (int)$row['project_id'] === $projectId) return $row;
    }
    return array();
}}

if (!function_exists('cpms_public_affairs_collab_upsert_project_meta')) {
function cpms_public_affairs_collab_upsert_project_meta($projectId, $meta) {
    $projectId = (int)$projectId;
    if ($projectId <= 0 || !is_array($meta)) return false;
    $store = cpms_public_affairs_collab_load_project_meta();
    $items = isset($store['items']) && is_array($store['items']) ? $store['items'] : array();
    $found = false;
    for ($i = 0; $i < count($items); $i++) {
        if (is_array($items[$i]) && isset($items[$i]['project_id']) && (int)$items[$i]['project_id'] === $projectId) {
            $items[$i] = array_merge($items[$i], $meta);
            $items[$i]['project_id'] = $projectId;
            $items[$i]['updated_at'] = date('Y-m-d H:i:s');
            $found = true;
            break;
        }
    }
    if (!$found) {
        $nextId = (int)$store['last_id'] + 1;
        $store['last_id'] = $nextId;
        $meta['id'] = $nextId;
        $meta['project_id'] = $projectId;
        $meta['created_at'] = isset($meta['created_at']) ? $meta['created_at'] : date('Y-m-d H:i:s');
        $meta['updated_at'] = date('Y-m-d H:i:s');
        $items[] = $meta;
    }
    $store['items'] = $items;
    return cpms_public_affairs_collab_save_project_meta($store);
}}

if (!function_exists('cpms_public_affairs_collab_is_draft_project')) {
function cpms_public_affairs_collab_is_draft_project($project, $metaStore) {
    if (!is_array($project)) return false;
    if (isset($project['name']) && cpms_public_affairs_collab_is_draft_project_name($project['name'])) return true;
    $projectId = isset($project['id']) ? (int)$project['id'] : 0;
    $meta = cpms_public_affairs_collab_project_meta_by_id($projectId, $metaStore);
    return (is_array($meta) && isset($meta['is_draft']) && (int)$meta['is_draft'] === 1);
}}

if (!function_exists('cpms_public_affairs_collab_add_project_activity')) {
function cpms_public_affairs_collab_add_project_activity($projectId, $action, $field, $oldValue, $newValue, $message, $actor, $taskId = 0) {
    // 공무 협업툴 프로젝트 Activity: 가제 프로젝트 생성/전환 같은 Space 변경 이력을 별도 JSON에 남긴다.
    $store = cpms_public_affairs_collab_load_store('project_activity');
    $nextId = (int)$store['last_id'] + 1;
    $store['last_id'] = $nextId;
    $taskId = (int)$taskId;
    $activityTask = $taskId > 0 ? cpms_public_affairs_collab_find_task($taskId) : null;
    $store['items'][] = array(
        'id' => $nextId,
        'project_id' => (int)$projectId,
        'task_id' => $taskId,
        'task_no' => $taskId > 0 ? cpms_public_affairs_collab_task_no($activityTask) : '',
        'action' => (string)$action,
        'field' => (string)$field,
        'old_value' => is_array($oldValue) ? implode(', ', $oldValue) : (string)$oldValue,
        'new_value' => is_array($newValue) ? implode(', ', $newValue) : (string)$newValue,
        'message' => (string)$message,
        'actor_id' => is_array($actor) && isset($actor['id']) ? (int)$actor['id'] : 0,
        'actor_name' => cpms_public_affairs_collab_actor_label($actor),
        'created_at' => date('Y-m-d H:i:s'),
    );
    return cpms_public_affairs_collab_save_store('project_activity', $store);
}}

if (!function_exists('cpms_public_affairs_collab_project_activities')) {
function cpms_public_affairs_collab_project_activities($projectId, $limit) {
    $projectId = (int)$projectId;
    $limit = (int)$limit;
    if ($limit <= 0) $limit = 50;
    $store = cpms_public_affairs_collab_load_store('project_activity');
    $rows = array();
    $activityKeys = array();
    foreach ($store['items'] as $row) {
        if (!is_array($row) || !isset($row['project_id']) || (int)$row['project_id'] !== $projectId) continue;
        $rows[] = $row;
        $key = (isset($row['action']) ? (string)$row['action'] : '') . '|' . (isset($row['field']) ? (string)$row['field'] : '') . '|' . (isset($row['actor_name']) ? (string)$row['actor_name'] : '') . '|' . (isset($row['created_at']) ? (string)$row['created_at'] : '');
        if (isset($row['task_id']) && (int)$row['task_id'] > 0) $activityKeys[$key] = true;
    }
    $historyStore = cpms_public_affairs_collab_load_store('history');
    $historyItems = isset($historyStore['items']) && is_array($historyStore['items']) ? $historyStore['items'] : array();
    foreach ($historyItems as $row) {
        if (!is_array($row) || !isset($row['project_id']) || (int)$row['project_id'] !== $projectId) continue;
        $key = (isset($row['action']) ? (string)$row['action'] : '') . '|' . (isset($row['field']) ? (string)$row['field'] : '') . '|' . (isset($row['actor_name']) ? (string)$row['actor_name'] : '') . '|' . (isset($row['created_at']) ? (string)$row['created_at'] : '');
        if (isset($activityKeys[$key])) continue;
        $row['message'] = (isset($row['task_no']) ? $row['task_no'] . ' ' : '') . (isset($row['message']) ? $row['message'] : '');
        $rows[] = $row;
    }
    usort($rows, 'cpms_public_affairs_collab_sort_created_desc');
    return array_slice($rows, 0, $limit);
}}

if (!function_exists('cpms_public_affairs_collab_fetch_projects')) {
function cpms_public_affairs_collab_fetch_projects($pdo) {
    $rows = array();
    if (!$pdo || !cpms_public_affairs_collab_table_exists($pdo, 'cpms_projects')) return $rows;
    try {
        $clientCol = cpms_public_affairs_collab_column_expr($pdo, 'cpms_projects', 'client', "'' AS client");
        $contractorCol = cpms_public_affairs_collab_column_expr($pdo, 'cpms_projects', 'contractor', "'' AS contractor");
        $locationCol = cpms_public_affairs_collab_column_expr($pdo, 'cpms_projects', 'location', "'' AS location");
        $startCol = cpms_public_affairs_collab_column_expr($pdo, 'cpms_projects', 'start_date', "NULL AS start_date");
        $endCol = cpms_public_affairs_collab_column_expr($pdo, 'cpms_projects', 'end_date', "NULL AS end_date");
        $amountCol = cpms_public_affairs_collab_column_expr($pdo, 'cpms_projects', 'contract_amount', "NULL AS contract_amount");
        $statusCol = cpms_public_affairs_collab_column_expr($pdo, 'cpms_projects', 'status', "'' AS status");
        $st = $pdo->query("SELECT id, name, " . $clientCol . ", " . $contractorCol . ", " . $locationCol . ", " . $startCol . ", " . $endCol . ", " . $amountCol . ", " . $statusCol . " FROM cpms_projects ORDER BY id DESC");
        $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : array();
    } catch (Exception $e) {
        $rows = array();
    }
    return is_array($rows) ? $rows : array();
}}

if (!function_exists('cpms_public_affairs_collab_project_main_manager_name')) {
function cpms_public_affairs_collab_project_main_manager_name($pdo, $projectId) {
    $projectId = (int)$projectId;
    if (!$pdo || $projectId <= 0 || !cpms_public_affairs_collab_table_exists($pdo, 'cpms_project_members') || !cpms_public_affairs_collab_table_exists($pdo, 'employees')) return '';
    try {
        $st = $pdo->prepare("SELECT e.name FROM cpms_project_members pm JOIN employees e ON e.id = pm.employee_id WHERE pm.project_id = :pid AND LOWER(TRIM(pm.role)) = 'main' ORDER BY e.name ASC LIMIT 1");
        $st->bindValue(':pid', $projectId, PDO::PARAM_INT);
        $st->execute();
        $name = $st->fetchColumn();
        return ($name !== false) ? trim((string)$name) : '';
    } catch (Exception $e) {
        return '';
    }
}}

if (!function_exists('cpms_public_affairs_collab_project_tasks')) {
function cpms_public_affairs_collab_project_tasks($tasks, $projectId) {
    $rows = array();
    $projectId = (int)$projectId;
    if ($projectId <= 0 || !is_array($tasks)) return $rows;
    foreach ($tasks as $task) {
        if (is_array($task) && isset($task['project_id']) && (int)$task['project_id'] === $projectId) $rows[] = $task;
    }
    return $rows;
}}

if (!function_exists('cpms_public_affairs_collab_project_stats')) {
function cpms_public_affairs_collab_project_stats($tasks) {
    // 공무 협업툴 프로젝트 홈/Summary: 프로젝트별 업무 흐름 집계를 한 곳에서 만든다.
    $stats = array(
        'total' => 0,
        'active' => 0,
        'done' => 0,
        'delayed' => 0,
        'today' => 0,
        'week' => 0,
        'contract_impact' => 0,
        'schedule_impact' => 0,
        'last_activity_at' => '',
        'by_status' => array(),
        'by_priority' => array(),
        'by_assignee' => array(),
    );
    $today = date('Y-m-d');
    $weekEnd = date('Y-m-d', strtotime('+7 day'));
    if (!is_array($tasks)) return $stats;
    foreach ($tasks as $task) {
        if (!is_array($task)) continue;
        $stats['total']++;
        $status = isset($task['status']) ? (string)$task['status'] : '';
        $priority = isset($task['priority']) ? (string)$task['priority'] : '';
        $assignee = isset($task['assignee_name']) && trim((string)$task['assignee_name']) !== '' ? (string)$task['assignee_name'] : '미지정';
        if (!isset($stats['by_status'][$status])) $stats['by_status'][$status] = 0;
        $stats['by_status'][$status]++;
        if (!isset($stats['by_priority'][$priority])) $stats['by_priority'][$priority] = 0;
        $stats['by_priority'][$priority]++;
        if (!isset($stats['by_assignee'][$assignee])) $stats['by_assignee'][$assignee] = 0;
        $stats['by_assignee'][$assignee]++;
        if ($status === '완료') $stats['done']++;
        else $stats['active']++;
        if (cpms_public_affairs_collab_is_delayed($task)) $stats['delayed']++;
        if (cpms_public_affairs_collab_is_due_today($task)) $stats['today']++;
        $dueDate = isset($task['due_date']) ? (string)$task['due_date'] : '';
        if ($status !== '완료' && $dueDate !== '' && strcmp($dueDate, $today) >= 0 && strcmp($dueDate, $weekEnd) <= 0) $stats['week']++;
        if (isset($task['contract_impact']) && ((string)$task['contract_impact'] === '있음' || (string)$task['contract_impact'] === '확인필요')) $stats['contract_impact']++;
        if (isset($task['schedule_impact']) && ((string)$task['schedule_impact'] === '있음' || (string)$task['schedule_impact'] === '확인필요')) $stats['schedule_impact']++;
        $activityAt = isset($task['updated_at']) && trim((string)$task['updated_at']) !== '' ? (string)$task['updated_at'] : (isset($task['created_at']) ? (string)$task['created_at'] : '');
        if ($activityAt !== '' && ($stats['last_activity_at'] === '' || strcmp($activityAt, $stats['last_activity_at']) > 0)) $stats['last_activity_at'] = $activityAt;
    }
    return $stats;
}}

if (!function_exists('cpms_public_affairs_collab_project_spaces')) {
function cpms_public_affairs_collab_project_spaces($pdo, $projects, $tasks) {
    // 공무 협업툴 프로젝트 홈: 기존 CPMS 프로젝트를 Space로 가져오고, 가제 메타를 함께 붙인다.
    $metaStore = cpms_public_affairs_collab_load_project_meta();
    $rows = array();
    if (!is_array($projects)) $projects = array();
    foreach ($projects as $project) {
        if (!is_array($project) || !isset($project['id'])) continue;
        $projectId = (int)$project['id'];
        if ($projectId <= 0) continue;
        $projectTasks = cpms_public_affairs_collab_project_tasks($tasks, $projectId);
        $stats = cpms_public_affairs_collab_project_stats($projectTasks);
        $meta = cpms_public_affairs_collab_project_meta_by_id($projectId, $metaStore);
        $isDraft = cpms_public_affairs_collab_is_draft_project($project, $metaStore) ? 1 : 0;
        $project['is_draft'] = $isDraft;
        $project['space_type'] = $isDraft ? 'draft' : 'official';
        $project['phase'] = isset($meta['phase']) ? (string)$meta['phase'] : '';
        $project['favorite'] = isset($meta['favorite']) ? (int)$meta['favorite'] : 0;
        $project['description'] = isset($meta['description']) ? (string)$meta['description'] : '';
        $project['manager_name'] = cpms_public_affairs_collab_project_main_manager_name($pdo, $projectId);
        $project['stats'] = $stats;
        $rows[] = $project;
    }
    return $rows;
}}

if (!function_exists('cpms_public_affairs_collab_find_project_space')) {
function cpms_public_affairs_collab_find_project_space($spaces, $projectId) {
    $projectId = (int)$projectId;
    if ($projectId <= 0 || !is_array($spaces)) return null;
    foreach ($spaces as $space) {
        if (is_array($space) && isset($space['id']) && (int)$space['id'] === $projectId) return $space;
    }
    return null;
}}

if (!function_exists('cpms_public_affairs_collab_project_home_summary')) {
function cpms_public_affairs_collab_project_home_summary($spaces) {
    $summary = array('total' => 0, 'official' => 0, 'draft' => 0, 'delayed_projects' => 0, 'today_tasks' => 0);
    if (!is_array($spaces)) return $summary;
    foreach ($spaces as $space) {
        if (!is_array($space)) continue;
        $summary['total']++;
        if (isset($space['is_draft']) && (int)$space['is_draft'] === 1) $summary['draft']++;
        else $summary['official']++;
        $stats = isset($space['stats']) && is_array($space['stats']) ? $space['stats'] : array();
        if (isset($stats['delayed']) && (int)$stats['delayed'] > 0) $summary['delayed_projects']++;
        if (isset($stats['today'])) $summary['today_tasks'] += (int)$stats['today'];
    }
    return $summary;
}}

if (!function_exists('cpms_public_affairs_collab_fetch_employees')) {
function cpms_public_affairs_collab_fetch_employees($pdo) {
    $rows = array();
    if (!$pdo || !cpms_public_affairs_collab_table_exists($pdo, 'employees')) return $rows;
    try {
        $departmentCol = cpms_public_affairs_collab_column_expr($pdo, 'employees', 'department', "'' AS department");
        $positionCol = cpms_public_affairs_collab_column_expr($pdo, 'employees', 'position', "'' AS position");
        $roleCol = cpms_public_affairs_collab_column_expr($pdo, 'employees', 'role', "'employee' AS role");
        $emailCol = cpms_public_affairs_collab_column_expr($pdo, 'employees', 'email', "'' AS email");
        $where = cpms_public_affairs_collab_column_exists($pdo, 'employees', 'is_active') ? ' WHERE is_active = 1' : '';
        $st = $pdo->query("SELECT id, name, " . $emailCol . ", " . $departmentCol . ", " . $positionCol . ", " . $roleCol . " FROM employees" . $where . " ORDER BY department ASC, position ASC, name ASC, id ASC");
        $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : array();
    } catch (Exception $e) {
        $rows = array();
    }
    if (!is_array($rows)) $rows = array();
    foreach ($rows as $i => $row) {
        $rows[$i]['department'] = cpms_public_affairs_collab_normalize_dept(isset($row['department']) ? $row['department'] : '');
    }
    return $rows;
}}

if (!function_exists('cpms_public_affairs_collab_current_employee')) {
function cpms_public_affairs_collab_current_employee($pdo) {
    $userName = class_exists('App\\Core\\Auth') ? (string)\App\Core\Auth::userName() : '';
    $userEmail = class_exists('App\\Core\\Auth') ? (string)\App\Core\Auth::userEmail() : '';
    $result = array(
        'id' => 0,
        'name' => $userName,
        'email' => $userEmail,
        'department' => class_exists('App\\Core\\Auth') ? cpms_public_affairs_collab_normalize_dept(\App\Core\Auth::userDepartment()) : '',
        'position' => class_exists('App\\Core\\Auth') ? (string)\App\Core\Auth::userPosition() : '',
        'role' => class_exists('App\\Core\\Auth') ? (string)\App\Core\Auth::userRole() : '',
    );
    if (!$pdo || $userEmail === '' || !cpms_public_affairs_collab_table_exists($pdo, 'employees')) return $result;
    try {
        $departmentCol = cpms_public_affairs_collab_column_expr($pdo, 'employees', 'department', "'' AS department");
        $positionCol = cpms_public_affairs_collab_column_expr($pdo, 'employees', 'position', "'' AS position");
        $roleCol = cpms_public_affairs_collab_column_expr($pdo, 'employees', 'role', "'employee' AS role");
        $st = $pdo->prepare("SELECT id, name, email, " . $departmentCol . ", " . $positionCol . ", " . $roleCol . " FROM employees WHERE LOWER(TRIM(email)) = LOWER(TRIM(:email)) LIMIT 1");
        $st->execute(array(':email' => $userEmail));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $result['id'] = isset($row['id']) ? (int)$row['id'] : 0;
            $result['name'] = isset($row['name']) && trim((string)$row['name']) !== '' ? (string)$row['name'] : $result['name'];
            $result['email'] = isset($row['email']) ? (string)$row['email'] : $result['email'];
            $result['department'] = cpms_public_affairs_collab_normalize_dept(isset($row['department']) ? $row['department'] : $result['department']);
            $result['position'] = isset($row['position']) ? (string)$row['position'] : $result['position'];
            $result['role'] = isset($row['role']) ? (string)$row['role'] : $result['role'];
        }
    } catch (Exception $e) {
    }
    return $result;
}}

if (!function_exists('cpms_public_affairs_collab_is_admin_user')) {
function cpms_public_affairs_collab_is_admin_user() {
    if (!class_exists('App\\Core\\Auth')) return false;
    if (\App\Core\Auth::isMaster()) return true;
    if (\App\Core\Auth::userRole() === 'executive') return true;
    $dept = cpms_public_affairs_collab_normalize_dept(\App\Core\Auth::userDepartment());
    return ($dept === '공무' || $dept === '관리');
}}

if (!function_exists('cpms_public_affairs_collab_can_create_task')) {
function cpms_public_affairs_collab_can_create_task() {
    if (!class_exists('App\\Core\\Auth') || !\App\Core\Auth::check()) return false;
    return true;
}}

if (!function_exists('cpms_public_affairs_collab_project_name')) {
function cpms_public_affairs_collab_project_name($projects, $projectId) {
    $projectId = (int)$projectId;
    if (!is_array($projects)) return '';
    foreach ($projects as $project) {
        if (isset($project['id']) && (int)$project['id'] === $projectId) {
            return isset($project['name']) ? (string)$project['name'] : '';
        }
    }
    return '';
}}

if (!function_exists('cpms_public_affairs_collab_employee_by_id')) {
function cpms_public_affairs_collab_employee_by_id($employees, $employeeId) {
    $employeeId = (int)$employeeId;
    if (!is_array($employees) || $employeeId <= 0) return null;
    foreach ($employees as $employee) {
        if (isset($employee['id']) && (int)$employee['id'] === $employeeId) return $employee;
    }
    return null;
}}

if (!function_exists('cpms_public_affairs_collab_employee_name')) {
function cpms_public_affairs_collab_employee_name($employee) {
    if (!is_array($employee)) return '';
    $name = isset($employee['name']) ? trim((string)$employee['name']) : '';
    return $name;
}}

if (!function_exists('cpms_public_affairs_collab_employee_email')) {
function cpms_public_affairs_collab_employee_email($employee) {
    if (!is_array($employee)) return '';
    return isset($employee['email']) ? trim((string)$employee['email']) : '';
}}

if (!function_exists('cpms_public_affairs_collab_employee_ids_from_value')) {
function cpms_public_affairs_collab_employee_ids_from_value($value) {
    $ids = array();
    if (!is_array($value)) $value = array($value);
    foreach ($value as $id) {
        $id = (int)$id;
        if ($id > 0 && !in_array($id, $ids, true)) $ids[] = $id;
    }
    return $ids;
}}

if (!function_exists('cpms_public_affairs_collab_task_ref_names')) {
function cpms_public_affairs_collab_task_ref_names($task) {
    if (!is_array($task)) return '';
    if (isset($task['reference_names']) && is_array($task['reference_names'])) {
        return implode(', ', $task['reference_names']);
    }
    return '';
}}

if (!function_exists('cpms_public_affairs_collab_task_no')) {
function cpms_public_affairs_collab_task_no($task) {
    // 공무 협업툴 업무카드: 사용자가 보는 업무번호(PA-0001 형식)를 만든다.
    if (is_array($task) && isset($task['task_no']) && trim((string)$task['task_no']) !== '') {
        return trim((string)$task['task_no']);
    }
    $taskId = is_array($task) && isset($task['id']) ? (int)$task['id'] : 0;
    return 'PA-' . str_pad((string)$taskId, 4, '0', STR_PAD_LEFT);
}}

if (!function_exists('cpms_public_affairs_collab_normalize_task')) {
function cpms_public_affairs_collab_normalize_task($task) {
    // 공무 협업툴 업무카드: 기존 JSON 데이터에 새 필드가 없어도 화면이 깨지지 않게 보정한다.
    if (!is_array($task)) $task = array();
    if (!isset($task['id'])) $task['id'] = 0;
    if (!isset($task['task_no']) || trim((string)$task['task_no']) === '') {
        $task['task_no'] = cpms_public_affairs_collab_task_no($task);
    }
    if (!isset($task['project_id'])) $task['project_id'] = 0;
    if (!isset($task['project_name'])) $task['project_name'] = '';
    if (!isset($task['task_type']) || trim((string)$task['task_type']) === '') $task['task_type'] = '기타';
    if (!isset($task['title'])) $task['title'] = '';
    if (!isset($task['content'])) $task['content'] = '';
    if (!isset($task['status']) || trim((string)$task['status']) === '') $task['status'] = '할 일';
    if (!isset($task['priority']) || trim((string)$task['priority']) === '') $task['priority'] = '보통';
    if (!isset($task['creator_employee_id'])) $task['creator_employee_id'] = 0;
    if (!isset($task['creator_name'])) $task['creator_name'] = '';
    if (!isset($task['creator_email'])) $task['creator_email'] = '';
    if (!isset($task['requester_employee_id'])) $task['requester_employee_id'] = 0;
    if (!isset($task['requester_name'])) $task['requester_name'] = '';
    if (!isset($task['requester_email'])) $task['requester_email'] = '';
    if (!isset($task['assignee_employee_id'])) $task['assignee_employee_id'] = 0;
    if (!isset($task['assignee_name'])) $task['assignee_name'] = '';
    if (!isset($task['assignee_email'])) $task['assignee_email'] = '';
    if (!isset($task['reference_employee_ids']) || !is_array($task['reference_employee_ids'])) $task['reference_employee_ids'] = array();
    if (!isset($task['reference_names']) || !is_array($task['reference_names'])) $task['reference_names'] = array();
    if (!isset($task['reference_emails']) || !is_array($task['reference_emails'])) $task['reference_emails'] = array();
    if (!isset($task['contract_impact']) || trim((string)$task['contract_impact']) === '') {
        $task['contract_impact'] = '없음';
    }
    if (!isset($task['schedule_impact']) || trim((string)$task['schedule_impact']) === '') {
        $task['schedule_impact'] = '없음';
    }
    if (!isset($task['due_date'])) $task['due_date'] = '';
    if (!isset($task['due_time'])) $task['due_time'] = '';
    if (!isset($task['related_amount'])) $task['related_amount'] = '';
    if (!isset($task['document_link'])) $task['document_link'] = '';
    if (!isset($task['comment_count'])) $task['comment_count'] = 0;
    if (!isset($task['file_count'])) $task['file_count'] = 0;
    if (!isset($task['template_id'])) $task['template_id'] = 0;
    if (!isset($task['template_name'])) $task['template_name'] = '';
    if (!isset($task['checklist_total'])) $task['checklist_total'] = 0;
    if (!isset($task['checklist_done'])) $task['checklist_done'] = 0;
    if (!isset($task['archived'])) $task['archived'] = 0;
    if (!isset($task['created_at'])) $task['created_at'] = '';
    if (!isset($task['updated_at'])) $task['updated_at'] = '';
    if (!isset($task['completed_at'])) $task['completed_at'] = '';
    if (!isset($task['rejected_at'])) $task['rejected_at'] = '';
    if (!isset($task['held_at'])) $task['held_at'] = '';
    if (!isset($task['start_date'])) $task['start_date'] = '';
    return $task;
}}

if (!function_exists('cpms_public_affairs_collab_list_tasks')) {
function cpms_public_affairs_collab_list_tasks() {
    $store = cpms_public_affairs_collab_load_store('tasks');
    $items = isset($store['items']) && is_array($store['items']) ? array_values($store['items']) : array();
    for ($i = 0; $i < count($items); $i++) {
        $items[$i] = cpms_public_affairs_collab_normalize_task($items[$i]);
    }
    usort($items, 'cpms_public_affairs_collab_sort_recent');
    return $items;
}}

if (!function_exists('cpms_public_affairs_collab_sort_recent')) {
function cpms_public_affairs_collab_sort_recent($a, $b) {
    $aId = isset($a['id']) ? (int)$a['id'] : 0;
    $bId = isset($b['id']) ? (int)$b['id'] : 0;
    if ($aId === $bId) return 0;
    return ($aId > $bId) ? -1 : 1;
}}

if (!function_exists('cpms_public_affairs_collab_sort_created_desc')) {
function cpms_public_affairs_collab_sort_created_desc($a, $b) {
    $aDate = is_array($a) && isset($a['created_at']) ? (string)$a['created_at'] : '';
    $bDate = is_array($b) && isset($b['created_at']) ? (string)$b['created_at'] : '';
    if ($aDate === $bDate) return 0;
    return (strcmp($aDate, $bDate) > 0) ? -1 : 1;
}}

if (!function_exists('cpms_public_affairs_collab_find_task')) {
function cpms_public_affairs_collab_find_task($taskId) {
    $taskId = (int)$taskId;
    if ($taskId <= 0) return null;
    $store = cpms_public_affairs_collab_load_store('tasks');
    if (!isset($store['items']) || !is_array($store['items'])) return null;
    foreach ($store['items'] as $task) {
        if (is_array($task) && isset($task['id']) && (int)$task['id'] === $taskId) return cpms_public_affairs_collab_normalize_task($task);
    }
    return null;
}}

if (!function_exists('cpms_public_affairs_collab_actor_label')) {
function cpms_public_affairs_collab_actor_label($actor) {
    if (!is_array($actor)) return '';
    $name = isset($actor['name']) ? trim((string)$actor['name']) : '';
    if ($name !== '') return $name;
    return isset($actor['email']) ? trim((string)$actor['email']) : '';
}}

if (!function_exists('cpms_public_affairs_collab_add_history')) {
function cpms_public_affairs_collab_add_history($taskId, $projectId, $action, $field, $oldValue, $newValue, $message, $actor) {
    $store = cpms_public_affairs_collab_load_store('history');
    $nextId = (int)$store['last_id'] + 1;
    $store['last_id'] = $nextId;
    $historyTask = cpms_public_affairs_collab_find_task($taskId);
    $store['items'][] = array(
        'id' => $nextId,
        'task_id' => (int)$taskId,
        'task_no' => cpms_public_affairs_collab_task_no($historyTask),
        'project_id' => (int)$projectId,
        'action' => (string)$action,
        'field' => (string)$field,
        'old_value' => is_array($oldValue) ? implode(', ', $oldValue) : (string)$oldValue,
        'new_value' => is_array($newValue) ? implode(', ', $newValue) : (string)$newValue,
        'message' => (string)$message,
        'actor_id' => is_array($actor) && isset($actor['id']) ? (int)$actor['id'] : 0,
        'actor_name' => cpms_public_affairs_collab_actor_label($actor),
        'created_at' => date('Y-m-d H:i:s'),
    );
    return cpms_public_affairs_collab_save_store('history', $store);
}}

if (!function_exists('cpms_public_affairs_collab_is_delayed')) {
function cpms_public_affairs_collab_is_delayed($task) {
    if (!is_array($task)) return false;
    $status = isset($task['status']) ? (string)$task['status'] : '';
    if ($status === '완료') return false;
    $dueDate = isset($task['due_date']) ? trim((string)$task['due_date']) : '';
    if ($dueDate === '' || !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $dueDate)) return false;
    return (strcmp($dueDate, date('Y-m-d')) < 0);
}}

if (!function_exists('cpms_public_affairs_collab_is_due_today')) {
function cpms_public_affairs_collab_is_due_today($task) {
    if (!is_array($task)) return false;
    $status = isset($task['status']) ? (string)$task['status'] : '';
    if ($status === '완료') return false;
    $dueDate = isset($task['due_date']) ? trim((string)$task['due_date']) : '';
    return ($dueDate !== '' && $dueDate === date('Y-m-d'));
}}

if (!function_exists('cpms_public_affairs_collab_user_matches_task')) {
function cpms_public_affairs_collab_user_matches_task($task, $employee) {
    if (!is_array($task) || !is_array($employee)) return false;
    $employeeId = isset($employee['id']) ? (int)$employee['id'] : 0;
    $employeeEmail = isset($employee['email']) ? strtolower(trim((string)$employee['email'])) : '';
    $idFields = array('creator_employee_id', 'requester_employee_id', 'assignee_employee_id');
    foreach ($idFields as $field) {
        if ($employeeId > 0 && isset($task[$field]) && (int)$task[$field] === $employeeId) return true;
    }
    $emailFields = array('creator_email', 'requester_email', 'assignee_email');
    foreach ($emailFields as $field) {
        $value = isset($task[$field]) ? strtolower(trim((string)$task[$field])) : '';
        if ($employeeEmail !== '' && $value !== '' && $value === $employeeEmail) return true;
    }
    if (isset($task['reference_employee_ids']) && is_array($task['reference_employee_ids'])) {
        foreach ($task['reference_employee_ids'] as $id) {
            if ($employeeId > 0 && (int)$id === $employeeId) return true;
        }
    }
    if (isset($task['reference_emails']) && is_array($task['reference_emails'])) {
        foreach ($task['reference_emails'] as $email) {
            $email = strtolower(trim((string)$email));
            if ($employeeEmail !== '' && $email !== '' && $email === $employeeEmail) return true;
        }
    }
    return false;
}}

if (!function_exists('cpms_public_affairs_collab_apply_quick_filter')) {
function cpms_public_affairs_collab_apply_quick_filter($tasks, $quickFilter, $employee) {
    // 공무 협업툴 보드: 좌측 메뉴와 빠른 필터에서 쓰는 카드 필터링.
    if (!is_array($tasks)) return array();
    $quickFilter = trim((string)$quickFilter);
    if ($quickFilter === '' || $quickFilter === 'all') return $tasks;
    $result = array();
    foreach ($tasks as $task) {
        if (!is_array($task)) continue;
        $status = isset($task['status']) ? (string)$task['status'] : '';
        $priority = isset($task['priority']) ? (string)$task['priority'] : '';
        $contractImpact = isset($task['contract_impact']) ? (string)$task['contract_impact'] : '없음';
        $scheduleImpact = isset($task['schedule_impact']) ? (string)$task['schedule_impact'] : '없음';
        $matched = false;
        if ($quickFilter === 'mine') {
            $employeeId = is_array($employee) && isset($employee['id']) ? (int)$employee['id'] : 0;
            $employeeEmail = is_array($employee) && isset($employee['email']) ? strtolower(trim((string)$employee['email'])) : '';
            if ($employeeId > 0 && isset($task['assignee_employee_id']) && (int)$task['assignee_employee_id'] === $employeeId) $matched = true;
            if (!$matched && $employeeEmail !== '' && isset($task['assignee_email']) && strtolower(trim((string)$task['assignee_email'])) === $employeeEmail) $matched = true;
        }
        if ($quickFilter === 'today') $matched = cpms_public_affairs_collab_is_due_today($task);
        if ($quickFilter === 'delayed') $matched = cpms_public_affairs_collab_is_delayed($task);
        if ($quickFilter === 'urgent') $matched = ($priority === '긴급');
        if ($quickFilter === 'approval') $matched = ($status === '결재대기' || $status === '검토중');
        if ($quickFilter === 'contract') $matched = ($contractImpact === '있음' || $contractImpact === '확인필요');
        if ($quickFilter === 'schedule') $matched = ($scheduleImpact === '있음' || $scheduleImpact === '확인필요');
        if ($quickFilter === 'hide_done') $matched = ($status !== '완료');
        if ($quickFilter === 'pending') $matched = ($status === '할 일' || $status === '요청' || $status === '접수');
        if ($quickFilter === 'done') $matched = ($status === '완료');
        if ($matched) $result[] = $task;
    }
    return $result;
}}

if (!function_exists('cpms_public_affairs_collab_user_can_view_task')) {
function cpms_public_affairs_collab_user_can_view_task($task, $employee) {
    if (cpms_public_affairs_collab_is_admin_user()) return true;
    return (class_exists('App\\Core\\Auth') && \App\Core\Auth::check());
}}

if (!function_exists('cpms_public_affairs_collab_user_can_edit_task')) {
function cpms_public_affairs_collab_user_can_edit_task($task, $employee) {
    if (cpms_public_affairs_collab_is_admin_user()) return true;
    if (!is_array($task) || !is_array($employee)) return false;
    $employeeId = isset($employee['id']) ? (int)$employee['id'] : 0;
    if ($employeeId > 0 && isset($task['creator_employee_id']) && (int)$task['creator_employee_id'] === $employeeId) return true;
    if ($employeeId > 0 && isset($task['assignee_employee_id']) && (int)$task['assignee_employee_id'] === $employeeId) return true;
    $employeeEmail = isset($employee['email']) ? strtolower(trim((string)$employee['email'])) : '';
    if ($employeeEmail !== '' && isset($task['creator_email']) && strtolower(trim((string)$task['creator_email'])) === $employeeEmail) return true;
    if ($employeeEmail !== '' && isset($task['assignee_email']) && strtolower(trim((string)$task['assignee_email'])) === $employeeEmail) return true;
    return false;
}}

if (!function_exists('cpms_public_affairs_collab_visible_tasks')) {
function cpms_public_affairs_collab_visible_tasks($tasks, $employee) {
    $visible = array();
    if (!is_array($tasks)) return $visible;
    foreach ($tasks as $task) {
        if (!is_array($task)) continue;
        if (cpms_public_affairs_collab_user_can_view_task($task, $employee)) $visible[] = $task;
    }
    return $visible;
}}

if (!function_exists('cpms_public_affairs_collab_user_can_access_module')) {
function cpms_public_affairs_collab_user_can_access_module($employee) {
    return (class_exists('App\\Core\\Auth') && \App\Core\Auth::check());
}}

if (!function_exists('cpms_public_affairs_collab_clean_text')) {
function cpms_public_affairs_collab_clean_text($value, $maxLength) {
    $value = trim((string)$value);
    $value = str_replace("\0", '', $value);
    if ($maxLength > 0 && function_exists('mb_substr')) return mb_substr($value, 0, $maxLength, 'UTF-8');
    if ($maxLength > 0) return substr($value, 0, $maxLength);
    return $value;
}}

if (!function_exists('cpms_public_affairs_collab_choice')) {
function cpms_public_affairs_collab_choice($value, $allowed, $fallback) {
    $value = trim((string)$value);
    if (is_array($allowed) && in_array($value, $allowed, true)) return $value;
    return $fallback;
}}

if (!function_exists('cpms_public_affairs_collab_date')) {
function cpms_public_affairs_collab_date($value) {
    $value = trim((string)$value);
    if ($value !== '' && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $value)) return $value;
    return '';
}}

if (!function_exists('cpms_public_affairs_collab_time')) {
function cpms_public_affairs_collab_time($value) {
    $value = trim((string)$value);
    if ($value !== '' && preg_match('/^\\d{2}:\\d{2}$/', $value)) return $value;
    return '';
}}

if (!function_exists('cpms_public_affairs_collab_amount')) {
function cpms_public_affairs_collab_amount($value) {
    $value = trim((string)$value);
    if ($value === '') return '';
    return preg_replace('/[^0-9.\\-]/', '', $value);
}}

if (!function_exists('cpms_public_affairs_collab_next_task_no')) {
function cpms_public_affairs_collab_next_task_no($store) {
    // 공무 협업툴 업무카드: 삭제/보관된 번호를 재사용하지 않도록 저장소의 가장 큰 PA 번호 다음 번호를 발급한다.
    $maxNo = 0;
    $items = isset($store['items']) && is_array($store['items']) ? $store['items'] : array();
    foreach ($items as $task) {
        if (!is_array($task) || !isset($task['task_no'])) continue;
        if (preg_match('/^PA-(\\d+)$/', trim((string)$task['task_no']), $m)) {
            $num = (int)$m[1];
            if ($num > $maxNo) $maxNo = $num;
        }
    }
    if ($maxNo <= 0 && isset($store['last_id'])) $maxNo = (int)$store['last_id'];
    return 'PA-' . str_pad((string)($maxNo + 1), 4, '0', STR_PAD_LEFT);
}}

if (!function_exists('cpms_public_affairs_collab_transition_rules')) {
function cpms_public_affairs_collab_transition_rules($settings) {
    if (is_array($settings) && isset($settings['status_transition_rules']) && is_array($settings['status_transition_rules'])) {
        return $settings['status_transition_rules'];
    }
    return array(
        '할 일' => array('진행중'),
        '진행중' => array('검토중', '대기', '보류', '완료'),
        '검토중' => array('진행중', '완료', '보류'),
        '대기' => array('진행중'),
        '보류' => array('진행중'),
        '완료' => array(),
    );
}}

if (!function_exists('cpms_public_affairs_collab_validate_status_transition')) {
function cpms_public_affairs_collab_validate_status_transition($task, $newStatus, $actor, $post) {
    // 공무 협업툴 칸반: Jira형 workflow처럼 일반 사용자의 상태 이동을 제한한다.
    if (!is_array($task)) return array('ok' => false, 'message' => '업무를 찾을 수 없습니다.');
    $oldStatus = isset($task['status']) ? (string)$task['status'] : '';
    $newStatus = trim((string)$newStatus);
    if ($newStatus === '' || $newStatus === $oldStatus) return array('ok' => true, 'message' => '');

    if ($newStatus === '결재대기') {
        $documentLink = isset($task['document_link']) ? trim((string)$task['document_link']) : '';
        if (isset($post['document_link'])) $documentLink = trim((string)$post['document_link']);
        $files = cpms_public_affairs_collab_files(isset($task['id']) ? (int)$task['id'] : 0);
        if ($documentLink === '' && count($files) === 0) {
            return array('ok' => false, 'message' => '결재대기로 이동하려면 관련 문서 또는 첨부파일이 필요합니다.');
        }
    }

    if ($newStatus === '반려' || $newStatus === '보류') {
        $reason = isset($post['transition_reason']) ? trim((string)$post['transition_reason']) : '';
        if ($reason === '') {
            return array('ok' => false, 'message' => $newStatus . '로 이동하려면 사유를 입력해주세요.');
        }
    }

    if (cpms_public_affairs_collab_is_admin_user()) return array('ok' => true, 'message' => '');

    $settings = cpms_public_affairs_collab_settings();
    $rules = cpms_public_affairs_collab_transition_rules($settings);
    $allowed = isset($rules[$oldStatus]) && is_array($rules[$oldStatus]) ? $rules[$oldStatus] : array();
    if (!in_array($newStatus, $allowed, true)) {
        return array('ok' => false, 'message' => '현재 상태에서는 ' . $newStatus . ' 상태로 바로 이동할 수 없습니다.');
    }
    return array('ok' => true, 'message' => '');
}}

if (!function_exists('cpms_public_affairs_collab_mentions_from_text')) {
function cpms_public_affairs_collab_mentions_from_text($text) {
    $mentions = array();
    if (preg_match_all('/@([^\\s@]+)/u', (string)$text, $matches)) {
        foreach ($matches[1] as $name) {
            $name = trim((string)$name);
            if ($name !== '' && !in_array($name, $mentions, true)) $mentions[] = $name;
        }
    }
    return $mentions;
}}

if (!function_exists('cpms_public_affairs_collab_project_amount')) {
function cpms_public_affairs_collab_project_amount($value) {
    $value = trim((string)$value);
    if ($value === '') return null;
    $clean = preg_replace('/[^0-9]/', '', $value);
    if ($clean === '') return null;
    return (int)$clean;
}}

if (!function_exists('cpms_public_affairs_collab_project_find')) {
function cpms_public_affairs_collab_project_find($pdo, $projectId) {
    $projectId = (int)$projectId;
    if (!$pdo || $projectId <= 0 || !cpms_public_affairs_collab_table_exists($pdo, 'cpms_projects')) return null;
    try {
        $st = $pdo->prepare("SELECT * FROM cpms_projects WHERE id = :id LIMIT 1");
        $st->bindValue(':id', $projectId, PDO::PARAM_INT);
        $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    } catch (Exception $e) {
        return null;
    }
}}

if (!function_exists('cpms_public_affairs_collab_project_main_manager_id')) {
function cpms_public_affairs_collab_project_main_manager_id($pdo, $projectId) {
    $projectId = (int)$projectId;
    if (!$pdo || $projectId <= 0 || !cpms_public_affairs_collab_table_exists($pdo, 'cpms_project_members')) return 0;
    try {
        $st = $pdo->prepare("SELECT employee_id FROM cpms_project_members WHERE project_id = :pid AND LOWER(TRIM(role)) = 'main' ORDER BY employee_id ASC LIMIT 1");
        $st->bindValue(':pid', $projectId, PDO::PARAM_INT);
        $st->execute();
        return (int)$st->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}}

if (!function_exists('cpms_public_affairs_collab_save_project_main_member')) {
function cpms_public_affairs_collab_save_project_main_member($pdo, $projectId, $managerId) {
    $projectId = (int)$projectId;
    $managerId = (int)$managerId;
    if (!$pdo || $projectId <= 0 || $managerId <= 0 || !cpms_public_affairs_collab_table_exists($pdo, 'cpms_project_members')) return true;
    try {
        $del = $pdo->prepare("DELETE FROM cpms_project_members WHERE project_id = :pid AND LOWER(TRIM(role)) = 'main'");
        $del->bindValue(':pid', $projectId, PDO::PARAM_INT);
        $del->execute();
        $ins = $pdo->prepare("INSERT INTO cpms_project_members(project_id, employee_id, role) VALUES(:pid, :eid, 'main')");
        $ins->bindValue(':pid', $projectId, PDO::PARAM_INT);
        $ins->bindValue(':eid', $managerId, PDO::PARAM_INT);
        $ins->execute();
    } catch (Exception $e) {
        return false;
    }
    return true;
}}

if (!function_exists('cpms_public_affairs_collab_sync_construction_role_after_convert')) {
function cpms_public_affairs_collab_sync_construction_role_after_convert($pdo, $projectId, $managerId) {
    // 공무 협업툴 정식 전환: 전환 후에만 공사섹션 담당자 연결을 생성한다.
    $projectId = (int)$projectId;
    $managerId = (int)$managerId;
    if (!$pdo || $projectId <= 0 || $managerId <= 0 || !cpms_public_affairs_collab_table_exists($pdo, 'cpms_construction_roles')) return true;
    try {
        $st = $pdo->prepare("SELECT project_id FROM cpms_construction_roles WHERE project_id = :pid LIMIT 1");
        $st->bindValue(':pid', $projectId, PDO::PARAM_INT);
        $st->execute();
        if ($st->fetch()) {
            $up = $pdo->prepare("UPDATE cpms_construction_roles SET site_employee_id = :sid WHERE project_id = :pid");
            $up->bindValue(':sid', $managerId, PDO::PARAM_INT);
            $up->bindValue(':pid', $projectId, PDO::PARAM_INT);
            $up->execute();
        } else {
            $ins = $pdo->prepare("INSERT INTO cpms_construction_roles(project_id, site_employee_id) VALUES(:pid, :sid)");
            $ins->bindValue(':pid', $projectId, PDO::PARAM_INT);
            $ins->bindValue(':sid', $managerId, PDO::PARAM_INT);
            $ins->execute();
        }
    } catch (Exception $e) {
        return false;
    }
    return true;
}}

if (!function_exists('cpms_public_affairs_collab_create_draft_project')) {
function cpms_public_affairs_collab_create_draft_project($pdo, $post, $actor, $employees) {
    // 공무 협업툴 프로젝트 홈: 계약 전/입찰 단계 업무를 시작하기 위한 "(가제)" Space 생성.
    if (!$pdo || !cpms_public_affairs_collab_table_exists($pdo, 'cpms_projects')) {
        return array('ok' => false, 'message' => '프로젝트 테이블을 찾을 수 없습니다.', 'project_id' => 0);
    }
    $name = cpms_public_affairs_collab_clean_text(isset($post['project_name']) ? $post['project_name'] : '', 200);
    if ($name === '') return array('ok' => false, 'message' => '프로젝트명을 입력해주세요.', 'project_id' => 0);
    $draftName = cpms_public_affairs_collab_draft_project_name($name);
    $client = cpms_public_affairs_collab_clean_text(isset($post['client']) ? $post['client'] : '', 200);
    $description = cpms_public_affairs_collab_clean_text(isset($post['description']) ? $post['description'] : '', 0);
    $phase = cpms_public_affairs_collab_clean_text(isset($post['phase']) ? $post['phase'] : '입찰검토', 50);
    $managerId = isset($post['manager_employee_id']) ? (int)$post['manager_employee_id'] : 0;
    if ($managerId <= 0 && is_array($actor) && isset($actor['id'])) $managerId = (int)$actor['id'];
    $startDate = cpms_public_affairs_collab_date(isset($post['start_date']) ? $post['start_date'] : '');
    $endDate = cpms_public_affairs_collab_date(isset($post['end_date']) ? $post['end_date'] : '');
    $favorite = isset($post['favorite']) ? 1 : 0;

    try {
        $columns = array('name');
        $holders = array(':name');
        $values = array(':name' => $draftName);
        $candidateValues = array(
            'client' => $client,
            'contractor' => '',
            'location' => '',
            'start_date' => ($startDate !== '' ? $startDate : null),
            'end_date' => ($endDate !== '' ? $endDate : null),
            'contract_amount' => null,
            'status' => $phase,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        );
        foreach ($candidateValues as $column => $value) {
            if (!cpms_public_affairs_collab_column_exists($pdo, 'cpms_projects', $column)) continue;
            $columns[] = '`' . $column . '`';
            $holders[] = ':' . $column;
            $values[':' . $column] = $value;
        }

        $pdo->beginTransaction();
        $sql = "INSERT INTO cpms_projects (" . implode(',', $columns) . ") VALUES (" . implode(',', $holders) . ")";
        $st = $pdo->prepare($sql);
        foreach ($values as $key => $value) $st->bindValue($key, $value);
        $st->execute();
        $projectId = (int)$pdo->lastInsertId();
        if ($managerId > 0) {
            if (!cpms_public_affairs_collab_save_project_main_member($pdo, $projectId, $managerId)) {
                throw new Exception('프로젝트 담당자를 저장하지 못했습니다.');
            }
        }
        $pdo->commit();

        $metaOk = cpms_public_affairs_collab_upsert_project_meta($projectId, array(
            'is_draft' => 1,
            'phase' => $phase,
            'favorite' => $favorite,
            'description' => $description,
            'created_by_id' => is_array($actor) && isset($actor['id']) ? (int)$actor['id'] : 0,
            'created_by_name' => cpms_public_affairs_collab_actor_label($actor),
        ));
        cpms_public_affairs_collab_add_project_activity($projectId, '가제 프로젝트 생성', 'project', '', $draftName, '공무 협업툴에서 가제 프로젝트가 생성되었습니다.', $actor);
        return array('ok' => true, 'message' => $metaOk ? '가제 프로젝트가 생성되었습니다.' : '가제 프로젝트는 생성되었지만 메타 저장을 확인해주세요.', 'project_id' => $projectId, 'project_name' => $draftName);
    } catch (Exception $e) {
        if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
        return array('ok' => false, 'message' => '가제 프로젝트 생성에 실패했습니다: ' . $e->getMessage(), 'project_id' => 0);
    }
}}

if (!function_exists('cpms_public_affairs_collab_convert_draft_project')) {
function cpms_public_affairs_collab_convert_draft_project($pdo, $projectId, $post, $actor) {
    // 공무 협업툴/공무 프로젝트 상세: "(가제)" Space를 정식 CPMS 프로젝트로 전환한다.
    $projectId = (int)$projectId;
    $project = cpms_public_affairs_collab_project_find($pdo, $projectId);
    if (!is_array($project)) return array('ok' => false, 'message' => '프로젝트를 찾을 수 없습니다.', 'project_id' => $projectId);
    $metaStore = cpms_public_affairs_collab_load_project_meta();
    if (!cpms_public_affairs_collab_is_draft_project($project, $metaStore)) {
        return array('ok' => false, 'message' => '가제 프로젝트만 정식 전환할 수 있습니다.', 'project_id' => $projectId);
    }

    $name = cpms_public_affairs_collab_clean_text(isset($post['name']) ? $post['name'] : cpms_public_affairs_collab_official_project_name(isset($project['name']) ? $project['name'] : ''), 200);
    $name = cpms_public_affairs_collab_official_project_name($name);
    $client = cpms_public_affairs_collab_clean_text(isset($post['client']) ? $post['client'] : (isset($project['client']) ? $project['client'] : ''), 200);
    $contractor = cpms_public_affairs_collab_clean_text(isset($post['contractor']) ? $post['contractor'] : (isset($project['contractor']) ? $project['contractor'] : ''), 200);
    $status = cpms_public_affairs_collab_clean_text(isset($post['status']) ? $post['status'] : '계약중', 50);
    if ($status === '대기중' || $status === '입찰검토' || $status === '가제' || $status === '정식전환대기') $status = '입찰 진행중';
    if ($status === '' || $status === '진행 중') $status = '진행중';
    if (!in_array($status, array('입찰 진행중', '계약중', '진행중', '정산완료'), true)) $status = '계약중';
    $location = cpms_public_affairs_collab_clean_text(isset($post['location']) ? $post['location'] : (isset($project['location']) ? $project['location'] : ''), 200);
    $startDate = cpms_public_affairs_collab_date(isset($post['start_date']) ? $post['start_date'] : (isset($project['start_date']) ? $project['start_date'] : ''));
    $endDate = cpms_public_affairs_collab_date(isset($post['end_date']) ? $post['end_date'] : (isset($project['end_date']) ? $project['end_date'] : ''));
    $amount = cpms_public_affairs_collab_project_amount(isset($post['contract_amount']) ? $post['contract_amount'] : (isset($project['contract_amount']) ? $project['contract_amount'] : ''));
    $managerId = isset($post['main_manager_id']) ? (int)$post['main_manager_id'] : cpms_public_affairs_collab_project_main_manager_id($pdo, $projectId);

    $missing = array();
    if ($name === '') $missing[] = '프로젝트명';
    if ($client === '') $missing[] = '발주처';
    if ($contractor === '') $missing[] = '시공사';
    if ($amount === null || (int)$amount <= 0) $missing[] = '계약금액';
    if ($startDate === '' || $endDate === '') $missing[] = '공사기간';
    if ($managerId <= 0) $missing[] = '공사 담당자';
    if (count($missing) > 0) {
        return array('ok' => false, 'message' => '정식 전환에 필요한 정보가 부족합니다: ' . implode(', ', $missing), 'project_id' => $projectId);
    }

    try {
        $set = array();
        $values = array(':id' => $projectId);
        $updateValues = array(
            'name' => $name,
            'client' => $client,
            'contractor' => $contractor,
            'location' => $location,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'contract_amount' => $amount,
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        );
        foreach ($updateValues as $column => $value) {
            if (!cpms_public_affairs_collab_column_exists($pdo, 'cpms_projects', $column)) continue;
            $set[] = '`' . $column . '` = :' . $column;
            $values[':' . $column] = $value;
        }
        if (count($set) <= 0) return array('ok' => false, 'message' => '수정 가능한 프로젝트 컬럼을 찾지 못했습니다.', 'project_id' => $projectId);

        $pdo->beginTransaction();
        $st = $pdo->prepare("UPDATE cpms_projects SET " . implode(', ', $set) . " WHERE id = :id");
        foreach ($values as $key => $value) {
            if ($key === ':id') $st->bindValue($key, (int)$value, PDO::PARAM_INT);
            else $st->bindValue($key, $value);
        }
        $st->execute();
        if (!cpms_public_affairs_collab_save_project_main_member($pdo, $projectId, $managerId)) {
            throw new Exception('프로젝트 담당자를 저장하지 못했습니다.');
        }
        if (!cpms_public_affairs_collab_sync_construction_role_after_convert($pdo, $projectId, $managerId)) {
            throw new Exception('공사섹션 담당자 연결에 실패했습니다.');
        }
        $pdo->commit();

        cpms_public_affairs_collab_upsert_project_meta($projectId, array(
            'is_draft' => 0,
            'converted_at' => date('Y-m-d H:i:s'),
            'converted_by_id' => is_array($actor) && isset($actor['id']) ? (int)$actor['id'] : 0,
            'converted_by_name' => cpms_public_affairs_collab_actor_label($actor),
        ));
        cpms_public_affairs_collab_add_project_activity($projectId, '정식 프로젝트 전환', 'project', isset($project['name']) ? $project['name'] : '', $name, '가제 프로젝트가 정식 프로젝트로 전환되었습니다.', $actor);
        return array('ok' => true, 'message' => '정식 프로젝트로 전환되었습니다.', 'project_id' => $projectId, 'project_name' => $name);
    } catch (Exception $e) {
        if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
        return array('ok' => false, 'message' => '정식 전환에 실패했습니다: ' . $e->getMessage(), 'project_id' => $projectId);
    }
}}

if (!function_exists('cpms_public_affairs_collab_create_task')) {
function cpms_public_affairs_collab_create_task($pdo, $post, $files, $actor, $projects, $employees) {
    $settings = cpms_public_affairs_collab_settings();
    $projectId = isset($post['project_id']) ? (int)$post['project_id'] : 0;
    if ($projectId <= 0) return array('ok' => false, 'message' => '업무를 만들 프로젝트 Space를 선택해주세요.', 'task_id' => 0);
    $projectName = cpms_public_affairs_collab_clean_text(isset($post['project_name']) ? $post['project_name'] : '', 200);
    if ($projectName === '') $projectName = cpms_public_affairs_collab_clean_text(isset($post['site_name']) ? $post['site_name'] : '', 200);
    if ($projectName === '' && $projectId > 0) $projectName = cpms_public_affairs_collab_project_name($projects, $projectId);
    if ($projectName === '') return array('ok' => false, 'message' => '프로젝트 정보를 찾을 수 없습니다.', 'task_id' => 0);

    $projectContext = array();
    if (is_array($projects)) {
        foreach ($projects as $projectRow) {
            if (is_array($projectRow) && isset($projectRow['id']) && (int)$projectRow['id'] === $projectId) {
                $projectContext = $projectRow;
                break;
            }
        }
    }
    $templateId = isset($post['template_id']) ? (int)$post['template_id'] : 0;
    $template = $templateId > 0 ? cpms_public_affairs_collab_template_by_id($templateId) : null;
    if (is_array($template) && !empty($template['is_active'])) {
        if (!isset($post['task_type']) || trim((string)$post['task_type']) === '') $post['task_type'] = isset($template['task_type']) ? $template['task_type'] : '';
        if (!isset($post['title']) || trim((string)$post['title']) === '') $post['title'] = cpms_public_affairs_collab_apply_template_vars(isset($template['default_title']) ? $template['default_title'] : '', $projectName, $projectContext);
        if (!isset($post['content']) || trim((string)$post['content']) === '') $post['content'] = cpms_public_affairs_collab_apply_template_vars(isset($template['default_content']) ? $template['default_content'] : '', $projectName, $projectContext);
        if (!isset($post['status']) || trim((string)$post['status']) === '') $post['status'] = isset($template['default_status']) ? $template['default_status'] : '';
        if (!isset($post['priority']) || trim((string)$post['priority']) === '') $post['priority'] = isset($template['default_priority']) ? $template['default_priority'] : '';
        if (!isset($post['contract_impact']) || trim((string)$post['contract_impact']) === '') $post['contract_impact'] = isset($template['default_contract_impact']) ? $template['default_contract_impact'] : '없음';
        if (!isset($post['schedule_impact']) || trim((string)$post['schedule_impact']) === '') $post['schedule_impact'] = isset($template['default_schedule_impact']) ? $template['default_schedule_impact'] : '없음';
        if (!isset($post['due_date']) || trim((string)$post['due_date']) === '') $post['due_date'] = cpms_public_affairs_collab_due_date_from_days(isset($template['default_due_days']) ? (int)$template['default_due_days'] : 0);
    } else {
        $templateId = 0;
        $template = null;
    }

    $title = cpms_public_affairs_collab_clean_text(isset($post['title']) ? $post['title'] : '', 200);
    if ($title === '') return array('ok' => false, 'message' => '업무 제목을 입력해주세요.', 'task_id' => 0);

    $requesterId = isset($post['requester_employee_id']) ? (int)$post['requester_employee_id'] : 0;
    if ($requesterId <= 0 && isset($actor['id'])) $requesterId = (int)$actor['id'];
    $requester = cpms_public_affairs_collab_employee_by_id($employees, $requesterId);
    if (!is_array($requester)) $requester = $actor;

    $assigneeId = isset($post['assignee_employee_id']) ? (int)$post['assignee_employee_id'] : 0;
    if ($assigneeId <= 0 && isset($settings['default_assignee_employee_id'])) $assigneeId = (int)$settings['default_assignee_employee_id'];
    $assignee = cpms_public_affairs_collab_employee_by_id($employees, $assigneeId);
    if (!is_array($assignee)) return array('ok' => false, 'message' => '담당자를 선택해주세요.', 'task_id' => 0);

    $refIds = cpms_public_affairs_collab_employee_ids_from_value(isset($post['reference_employee_ids']) ? $post['reference_employee_ids'] : array());
    $refNames = array();
    $refEmails = array();
    foreach ($refIds as $refId) {
        $refEmployee = cpms_public_affairs_collab_employee_by_id($employees, $refId);
        if (!is_array($refEmployee)) continue;
        $refName = cpms_public_affairs_collab_employee_name($refEmployee);
        if ($refName !== '') $refNames[] = $refName;
        $refEmail = cpms_public_affairs_collab_employee_email($refEmployee);
        if ($refEmail !== '') $refEmails[] = $refEmail;
    }

    $store = cpms_public_affairs_collab_load_store('tasks');
    $taskNo = cpms_public_affairs_collab_next_task_no($store);
    $taskId = (int)$store['last_id'] + 1;
    $store['last_id'] = $taskId;
    $now = date('Y-m-d H:i:s');
    $task = array(
        'id' => $taskId,
        'task_no' => $taskNo,
        'project_id' => $projectId,
        'project_name' => $projectName,
        'task_type' => cpms_public_affairs_collab_choice(isset($post['task_type']) ? $post['task_type'] : '', $settings['task_types'], isset($settings['task_types'][0]) ? $settings['task_types'][0] : '기타'),
        'title' => $title,
        'content' => cpms_public_affairs_collab_clean_text(isset($post['content']) ? $post['content'] : '', 0),
        'creator_employee_id' => isset($actor['id']) ? (int)$actor['id'] : 0,
        'creator_name' => cpms_public_affairs_collab_actor_label($actor),
        'creator_email' => isset($actor['email']) ? (string)$actor['email'] : '',
        'requester_employee_id' => isset($requester['id']) ? (int)$requester['id'] : 0,
        'requester_name' => cpms_public_affairs_collab_employee_name($requester),
        'requester_email' => cpms_public_affairs_collab_employee_email($requester),
        'assignee_employee_id' => isset($assignee['id']) ? (int)$assignee['id'] : 0,
        'assignee_name' => cpms_public_affairs_collab_employee_name($assignee),
        'assignee_email' => cpms_public_affairs_collab_employee_email($assignee),
        'reference_employee_ids' => $refIds,
        'reference_names' => $refNames,
        'reference_emails' => $refEmails,
        'priority' => cpms_public_affairs_collab_choice(isset($post['priority']) ? $post['priority'] : '', $settings['priorities'], '보통'),
        'status' => cpms_public_affairs_collab_choice(isset($post['status']) ? $post['status'] : '', $settings['statuses'], isset($settings['statuses'][0]) ? $settings['statuses'][0] : '할 일'),
        'start_date' => cpms_public_affairs_collab_date(isset($post['start_date']) ? $post['start_date'] : ''),
        'due_date' => cpms_public_affairs_collab_date(isset($post['due_date']) ? $post['due_date'] : ''),
        'due_time' => cpms_public_affairs_collab_time(isset($post['due_time']) ? $post['due_time'] : ''),
        'related_amount' => cpms_public_affairs_collab_amount(isset($post['related_amount']) ? $post['related_amount'] : ''),
        'contract_impact' => cpms_public_affairs_collab_clean_text(isset($post['contract_impact']) ? $post['contract_impact'] : '없음', 20),
        'schedule_impact' => cpms_public_affairs_collab_clean_text(isset($post['schedule_impact']) ? $post['schedule_impact'] : '없음', 20),
        'document_link' => cpms_public_affairs_collab_clean_text(isset($post['document_link']) ? $post['document_link'] : '', 500),
        'template_id' => $templateId,
        'template_name' => is_array($template) && isset($template['template_name']) ? (string)$template['template_name'] : '',
        'checklist_total' => 0,
        'checklist_done' => 0,
        'comment_count' => 0,
        'file_count' => 0,
        'archived' => 0,
        'created_at' => $now,
        'updated_at' => $now,
        'completed_at' => '',
        'rejected_at' => '',
        'held_at' => '',
    );
    $store['items'][] = $task;
    if (!cpms_public_affairs_collab_save_store('tasks', $store)) {
        return array('ok' => false, 'message' => '업무 저장에 실패했습니다. storage 쓰기 권한을 확인해주세요.', 'task_id' => 0);
    }
    $createAction = $templateId > 0 ? '템플릿 업무 생성' : '업무 생성';
    $createMessage = $templateId > 0 ? '업무 템플릿으로 공무 업무카드가 생성되었습니다.' : '공무 협업툴 업무가 생성되었습니다.';
    cpms_public_affairs_collab_add_history($taskId, $projectId, $createAction, 'task', '', $title, $createMessage, $actor);
    cpms_public_affairs_collab_add_project_activity($projectId, $createAction, 'task', '', $taskNo . ' ' . $title, $createMessage, $actor, $taskId);
    if (is_array($template) && isset($template['checklist_items']) && is_array($template['checklist_items'])) {
        cpms_public_affairs_collab_create_checklists_for_task($task, $template['checklist_items'], $actor);
    }
    cpms_public_affairs_collab_save_uploaded_files($task, isset($files['attachments']) ? $files['attachments'] : null, $actor);
    $freshTask = cpms_public_affairs_collab_find_task($taskId);
    if (!is_array($freshTask)) $freshTask = $task;
    return array('ok' => true, 'message' => '업무가 등록되었습니다.', 'task_id' => $taskId, 'task_no' => $taskNo, 'task' => cpms_public_affairs_collab_normalize_task($freshTask));
}}

if (!function_exists('cpms_public_affairs_collab_update_task')) {
function cpms_public_affairs_collab_update_task($taskId, $post, $actor, $projects, $employees) {
    $taskId = (int)$taskId;
    $settings = cpms_public_affairs_collab_settings();
    $store = cpms_public_affairs_collab_load_store('tasks');
    $foundIndex = -1;
    $task = null;
    for ($i = 0; $i < count($store['items']); $i++) {
        if (isset($store['items'][$i]['id']) && (int)$store['items'][$i]['id'] === $taskId) {
            $foundIndex = $i;
            $task = cpms_public_affairs_collab_normalize_task($store['items'][$i]);
            break;
        }
    }
    if (!is_array($task)) return array('ok' => false, 'message' => '업무를 찾을 수 없습니다.');

    $changes = array();
    $now = date('Y-m-d H:i:s');
    if (isset($post['title'])) {
        $new = cpms_public_affairs_collab_clean_text($post['title'], 200);
        if ($new !== '' && $new !== (string)$task['title']) {
            $changes[] = array('제목 변경', 'title', $task['title'], $new);
            $task['title'] = $new;
        }
    }
    if (isset($post['content'])) {
        $new = cpms_public_affairs_collab_clean_text($post['content'], 0);
        if ($new !== (string)$task['content']) {
            $changes[] = array('내용 변경', 'content', $task['content'], $new);
            $task['content'] = $new;
        }
    }
    if (isset($post['task_type'])) {
        $new = cpms_public_affairs_collab_choice($post['task_type'], $settings['task_types'], $task['task_type']);
        if ($new !== (string)$task['task_type']) {
            $changes[] = array('업무유형 변경', 'task_type', $task['task_type'], $new);
            $task['task_type'] = $new;
        }
    }
    if (isset($post['project_name'])) {
        $new = cpms_public_affairs_collab_clean_text($post['project_name'], 200);
        if ($new !== '' && $new !== (string)$task['project_name']) {
            $changes[] = array('현장명/프로젝트명 변경', 'project_name', $task['project_name'], $new);
            $task['project_name'] = $new;
        }
    }
    if (isset($post['assignee_employee_id'])) {
        $assignee = cpms_public_affairs_collab_employee_by_id($employees, (int)$post['assignee_employee_id']);
        if (is_array($assignee)) {
            $newId = isset($assignee['id']) ? (int)$assignee['id'] : 0;
            if ($newId > 0 && (!isset($task['assignee_employee_id']) || (int)$task['assignee_employee_id'] !== $newId)) {
                $changes[] = array('담당자 변경', 'assignee_employee_id', isset($task['assignee_name']) ? $task['assignee_name'] : '', cpms_public_affairs_collab_employee_name($assignee));
                $task['assignee_employee_id'] = $newId;
                $task['assignee_name'] = cpms_public_affairs_collab_employee_name($assignee);
                $task['assignee_email'] = cpms_public_affairs_collab_employee_email($assignee);
            }
        }
    }
    if (isset($post['reference_employee_ids_present'])) {
        $refIds = cpms_public_affairs_collab_employee_ids_from_value(isset($post['reference_employee_ids']) ? $post['reference_employee_ids'] : array());
        $oldNames = isset($task['reference_names']) && is_array($task['reference_names']) ? $task['reference_names'] : array();
        $refNames = array();
        $refEmails = array();
        foreach ($refIds as $refId) {
            $refEmployee = cpms_public_affairs_collab_employee_by_id($employees, $refId);
            if (!is_array($refEmployee)) continue;
            $refName = cpms_public_affairs_collab_employee_name($refEmployee);
            if ($refName !== '') $refNames[] = $refName;
            $refEmail = cpms_public_affairs_collab_employee_email($refEmployee);
            if ($refEmail !== '') $refEmails[] = $refEmail;
        }
        $oldJoined = implode(', ', $oldNames);
        $newJoined = implode(', ', $refNames);
        if ($oldJoined !== $newJoined) {
            $changes[] = array('참조자 변경', 'reference_employee_ids', $oldJoined, $newJoined);
            $task['reference_employee_ids'] = $refIds;
            $task['reference_names'] = $refNames;
            $task['reference_emails'] = $refEmails;
        }
    }
    if (isset($post['status'])) {
        $new = cpms_public_affairs_collab_choice($post['status'], $settings['statuses'], $task['status']);
        if ($new !== (string)$task['status']) {
            $transition = cpms_public_affairs_collab_validate_status_transition($task, $new, $actor, $post);
            if (empty($transition['ok'])) {
                return array('ok' => false, 'message' => isset($transition['message']) ? $transition['message'] : '상태를 변경할 수 없습니다.', 'task_id' => $taskId, 'task' => $task);
            }
            $oldStatus = $task['status'];
            $changes[] = array('상태 변경', 'status', $oldStatus, $new);
            $task['status'] = $new;
            if ($new === '완료') $task['completed_at'] = $now;
            if ($oldStatus === '완료' && $new !== '완료') {
                $oldCompletedAt = isset($task['completed_at']) ? (string)$task['completed_at'] : '';
                $task['completed_at'] = '';
                $changes[] = array('완료 취소', 'completed_at', $oldCompletedAt, '');
            }
            if ($new === '반려') $task['rejected_at'] = $now;
            if ($new === '보류') $task['held_at'] = $now;
            if ($new === '완료') $changes[] = array('완료 처리', 'status_action', $oldStatus, $new);
            if ($new === '반려') $changes[] = array('반려 처리', 'status_action', $oldStatus, $new);
            if ($new === '보류') $changes[] = array('보류 처리', 'status_action', $oldStatus, $new);
            if (($new === '반려' || $new === '보류') && isset($post['transition_reason']) && trim((string)$post['transition_reason']) !== '') {
                $changes[] = array($new . ' 사유', 'transition_reason', '', trim((string)$post['transition_reason']));
            }
        }
    }
    if (isset($post['priority'])) {
        $new = cpms_public_affairs_collab_choice($post['priority'], $settings['priorities'], $task['priority']);
        if ($new !== (string)$task['priority']) {
            $changes[] = array('우선순위 변경', 'priority', $task['priority'], $new);
            $task['priority'] = $new;
        }
    }
    if (isset($post['due_date'])) {
        $newDate = cpms_public_affairs_collab_date($post['due_date']);
        if ($newDate !== (string)$task['due_date']) {
            $changes[] = array('마감일 변경', 'due_date', $task['due_date'], $newDate);
            $task['due_date'] = $newDate;
        }
    }
    if (isset($post['start_date'])) {
        $newStartDate = cpms_public_affairs_collab_date($post['start_date']);
        $oldStartDate = isset($task['start_date']) ? (string)$task['start_date'] : '';
        if ($newStartDate !== $oldStartDate) {
            $changes[] = array('시작일 변경', 'start_date', $oldStartDate, $newStartDate);
            $task['start_date'] = $newStartDate;
        }
    }
    if (isset($post['due_time'])) {
        $newTime = cpms_public_affairs_collab_time($post['due_time']);
        if ($newTime !== (string)$task['due_time']) {
            $changes[] = array('마감시간 변경', 'due_time', $task['due_time'], $newTime);
            $task['due_time'] = $newTime;
        }
    }
    if (isset($post['related_amount'])) {
        $new = cpms_public_affairs_collab_amount($post['related_amount']);
        if ($new !== (string)$task['related_amount']) {
            $changes[] = array('관련 금액 변경', 'related_amount', $task['related_amount'], $new);
            $task['related_amount'] = $new;
        }
    }
    if (isset($post['contract_impact'])) {
        $new = cpms_public_affairs_collab_clean_text($post['contract_impact'], 20);
        $old = isset($task['contract_impact']) ? (string)$task['contract_impact'] : '없음';
        if ($new !== $old) {
            $changes[] = array('계약 영향 변경', 'contract_impact', $old, $new);
            $task['contract_impact'] = $new;
        }
    }
    if (isset($post['schedule_impact'])) {
        $new = cpms_public_affairs_collab_clean_text($post['schedule_impact'], 20);
        $old = isset($task['schedule_impact']) ? (string)$task['schedule_impact'] : '없음';
        if ($new !== $old) {
            $changes[] = array('공기 영향 변경', 'schedule_impact', $old, $new);
            $task['schedule_impact'] = $new;
        }
    }
    if (isset($post['document_link'])) {
        $new = cpms_public_affairs_collab_clean_text($post['document_link'], 500);
        if ($new !== (string)$task['document_link']) {
            $changes[] = array('관련 문서 링크 변경', 'document_link', $task['document_link'], $new);
            $task['document_link'] = $new;
        }
    }

    if (count($changes) === 0) return array('ok' => true, 'message' => '변경된 내용이 없습니다.', 'task_id' => $taskId, 'task' => $task);
    $task['updated_at'] = $now;
    $store['items'][$foundIndex] = $task;
    if (!cpms_public_affairs_collab_save_store('tasks', $store)) return array('ok' => false, 'message' => '업무 수정 저장에 실패했습니다.');
    foreach ($changes as $change) {
        cpms_public_affairs_collab_add_history($taskId, isset($task['project_id']) ? (int)$task['project_id'] : 0, $change[0], $change[1], $change[2], $change[3], $change[0] . '이 기록되었습니다.', $actor);
        $activityAction = $change[0];
        if ($change[0] === '완료 처리') $activityAction = '업무 완료';
        if ($change[0] === '보류 처리') $activityAction = '업무 보류';
        if ($change[0] === '반려 처리') $activityAction = '업무 반려';
        cpms_public_affairs_collab_add_project_activity(isset($task['project_id']) ? (int)$task['project_id'] : 0, $activityAction, $change[1], $change[2], $change[3], cpms_public_affairs_collab_task_no($task) . ' ' . $activityAction . '이 기록되었습니다.', $actor, $taskId);
    }
    return array('ok' => true, 'message' => '업무가 수정되었습니다.', 'task_id' => $taskId, 'task' => cpms_public_affairs_collab_normalize_task($task));
}}

if (!function_exists('cpms_public_affairs_collab_comments')) {
function cpms_public_affairs_collab_comments($taskId) {
    $taskId = (int)$taskId;
    $store = cpms_public_affairs_collab_load_store('comments');
    $rows = array();
    foreach ($store['items'] as $row) {
        if (!is_array($row) || !isset($row['task_id']) || (int)$row['task_id'] !== $taskId) continue;
        if (isset($row['deleted_at']) && trim((string)$row['deleted_at']) !== '') continue;
        $rows[] = $row;
    }
    usort($rows, 'cpms_public_affairs_collab_sort_asc');
    return $rows;
}}

if (!function_exists('cpms_public_affairs_collab_sort_asc')) {
function cpms_public_affairs_collab_sort_asc($a, $b) {
    $aId = isset($a['id']) ? (int)$a['id'] : 0;
    $bId = isset($b['id']) ? (int)$b['id'] : 0;
    if ($aId === $bId) return 0;
    return ($aId < $bId) ? -1 : 1;
}}

if (!function_exists('cpms_public_affairs_collab_add_comment')) {
function cpms_public_affairs_collab_add_comment($task, $content, $actor) {
    if (!is_array($task)) return array('ok' => false, 'message' => '업무를 찾을 수 없습니다.');
    $content = cpms_public_affairs_collab_clean_text($content, 0);
    if ($content === '') return array('ok' => false, 'message' => '댓글 내용을 입력해주세요.');
    $store = cpms_public_affairs_collab_load_store('comments');
    $nextId = (int)$store['last_id'] + 1;
    $store['last_id'] = $nextId;
    $comment = array(
        'id' => $nextId,
        'task_id' => (int)$task['id'],
        'project_id' => isset($task['project_id']) ? (int)$task['project_id'] : 0,
        'content' => $content,
        'mentions' => cpms_public_affairs_collab_mentions_from_text($content),
        'created_by_id' => is_array($actor) && isset($actor['id']) ? (int)$actor['id'] : 0,
        'created_by_name' => cpms_public_affairs_collab_actor_label($actor),
        'created_by_email' => is_array($actor) && isset($actor['email']) ? (string)$actor['email'] : '',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => '',
        'deleted_at' => '',
    );
    $store['items'][] = $comment;
    if (!cpms_public_affairs_collab_save_store('comments', $store)) return array('ok' => false, 'message' => '댓글 저장에 실패했습니다.');
    cpms_public_affairs_collab_add_history((int)$task['id'], isset($task['project_id']) ? (int)$task['project_id'] : 0, '댓글 등록', 'comment', '', $content, '댓글이 등록되었습니다.', $actor);
    cpms_public_affairs_collab_add_project_activity(isset($task['project_id']) ? (int)$task['project_id'] : 0, '댓글 등록', 'comment', '', cpms_public_affairs_collab_task_no($task), '업무카드에 댓글이 등록되었습니다.', $actor, (int)$task['id']);
    cpms_public_affairs_collab_sync_task_counts((int)$task['id']);
    return array('ok' => true, 'message' => '댓글이 등록되었습니다.', 'comment' => $comment);
}}

if (!function_exists('cpms_public_affairs_collab_files')) {
function cpms_public_affairs_collab_files($taskId) {
    $taskId = (int)$taskId;
    $store = cpms_public_affairs_collab_load_store('attachments');
    $rows = array();
    foreach ($store['items'] as $row) {
        if (!is_array($row) || !isset($row['task_id']) || (int)$row['task_id'] !== $taskId) continue;
        if (isset($row['deleted_at']) && trim((string)$row['deleted_at']) !== '') continue;
        $rows[] = $row;
    }
    usort($rows, 'cpms_public_affairs_collab_sort_asc');
    return $rows;
}}

if (!function_exists('cpms_public_affairs_collab_history')) {
function cpms_public_affairs_collab_history($taskId) {
    $taskId = (int)$taskId;
    $store = cpms_public_affairs_collab_load_store('history');
    $rows = array();
    foreach ($store['items'] as $row) {
        if (is_array($row) && isset($row['task_id']) && (int)$row['task_id'] === $taskId) $rows[] = $row;
    }
    usort($rows, 'cpms_public_affairs_collab_sort_created_desc');
    return $rows;
}}

if (!function_exists('cpms_public_affairs_collab_count_by_task')) {
function cpms_public_affairs_collab_count_by_task($storeName) {
    // 공무 협업툴 업무카드: 칸반/목록에 표시할 댓글·첨부 수를 task_id 기준으로 집계한다.
    $store = cpms_public_affairs_collab_load_store($storeName);
    $counts = array();
    $items = isset($store['items']) && is_array($store['items']) ? $store['items'] : array();
    foreach ($items as $row) {
        if (!is_array($row) || !isset($row['task_id'])) continue;
        if (isset($row['deleted_at']) && trim((string)$row['deleted_at']) !== '') continue;
        $taskId = (int)$row['task_id'];
        if ($taskId <= 0) continue;
        if (!isset($counts[$taskId])) $counts[$taskId] = 0;
        $counts[$taskId]++;
    }
    return $counts;
}}

if (!function_exists('cpms_public_affairs_collab_task_counts')) {
function cpms_public_affairs_collab_task_counts() {
    // 공무 협업툴 업무카드: 화면에서 댓글 수와 첨부 수를 함께 보여주기 위한 통합 카운트.
    return array(
        'comments' => cpms_public_affairs_collab_count_by_task('comments'),
        'files' => cpms_public_affairs_collab_count_by_task('attachments'),
        'checklists' => cpms_public_affairs_collab_checklist_counts_by_task(),
    );
}}

if (!function_exists('cpms_public_affairs_collab_count_for_task')) {
function cpms_public_affairs_collab_count_for_task($counts, $taskId, $key) {
    $taskId = (int)$taskId;
    if (!is_array($counts) || !isset($counts[$key]) || !is_array($counts[$key])) return 0;
    return isset($counts[$key][$taskId]) ? (int)$counts[$key][$taskId] : 0;
}}

if (!function_exists('cpms_public_affairs_collab_sync_task_counts')) {
function cpms_public_affairs_collab_sync_task_counts($taskId) {
    // 공무 협업툴 상세패널: 댓글/첨부 저장 뒤 업무카드 JSON의 집계 필드를 실제 저장소 기준으로 맞춘다.
    $taskId = (int)$taskId;
    if ($taskId <= 0) return false;
    $counts = cpms_public_affairs_collab_task_counts();
    $commentCount = cpms_public_affairs_collab_count_for_task($counts, $taskId, 'comments');
    $fileCount = cpms_public_affairs_collab_count_for_task($counts, $taskId, 'files');
    $checklistTotal = cpms_public_affairs_collab_checklist_count_for_task($counts['checklists'], $taskId, 'total');
    $checklistDone = cpms_public_affairs_collab_checklist_count_for_task($counts['checklists'], $taskId, 'done');
    $store = cpms_public_affairs_collab_load_store('tasks');
    if (!isset($store['items']) || !is_array($store['items'])) return false;
    $changed = false;
    for ($i = 0; $i < count($store['items']); $i++) {
        if (!is_array($store['items'][$i]) || !isset($store['items'][$i]['id']) || (int)$store['items'][$i]['id'] !== $taskId) continue;
        if (!isset($store['items'][$i]['comment_count']) || (int)$store['items'][$i]['comment_count'] !== $commentCount) {
            $store['items'][$i]['comment_count'] = $commentCount;
            $changed = true;
        }
        if (!isset($store['items'][$i]['file_count']) || (int)$store['items'][$i]['file_count'] !== $fileCount) {
            $store['items'][$i]['file_count'] = $fileCount;
            $changed = true;
        }
        if (!isset($store['items'][$i]['checklist_total']) || (int)$store['items'][$i]['checklist_total'] !== $checklistTotal) {
            $store['items'][$i]['checklist_total'] = $checklistTotal;
            $changed = true;
        }
        if (!isset($store['items'][$i]['checklist_done']) || (int)$store['items'][$i]['checklist_done'] !== $checklistDone) {
            $store['items'][$i]['checklist_done'] = $checklistDone;
            $changed = true;
        }
        break;
    }
    if (!$changed) return true;
    return cpms_public_affairs_collab_save_store('tasks', $store);
}}

if (!function_exists('cpms_public_affairs_collab_task_payload')) {
function cpms_public_affairs_collab_task_payload($taskId) {
    // 공무 협업툴 상세패널 AJAX: 업무, 댓글, 첨부, 변경이력을 한 번에 내려준다.
    $task = cpms_public_affairs_collab_find_task($taskId);
    if (!is_array($task)) return null;
    $comments = cpms_public_affairs_collab_comments($taskId);
    $files = cpms_public_affairs_collab_files($taskId);
    $history = cpms_public_affairs_collab_history($taskId);
    $checklists = cpms_public_affairs_collab_checklists($taskId);
    $task['comment_count'] = count($comments);
    $task['file_count'] = count($files);
    $checklistDone = 0;
    foreach ($checklists as $row) {
        if (!empty($row['is_done'])) $checklistDone++;
    }
    $task['checklist_total'] = count($checklists);
    $task['checklist_done'] = $checklistDone;
    $task['is_delayed'] = cpms_public_affairs_collab_is_delayed($task) ? 1 : 0;
    $task['is_due_today'] = cpms_public_affairs_collab_is_due_today($task) ? 1 : 0;
    return array(
        'task' => $task,
        'comments' => $comments,
        'files' => $files,
        'checklists' => $checklists,
        'history' => $history,
    );
}}

if (!function_exists('cpms_public_affairs_collab_find_file')) {
function cpms_public_affairs_collab_find_file($fileId) {
    $fileId = (int)$fileId;
    $store = cpms_public_affairs_collab_load_store('attachments');
    foreach ($store['items'] as $row) {
        if (!is_array($row) || !isset($row['id']) || (int)$row['id'] !== $fileId) continue;
        if (isset($row['deleted_at']) && trim((string)$row['deleted_at']) !== '') return null;
        if (!isset($row['file_path']) && isset($row['stored_path'])) $row['file_path'] = $row['stored_path'];
        if (!isset($row['stored_path']) && isset($row['file_path'])) $row['stored_path'] = $row['file_path'];
        return $row;
    }
    return null;
}}

if (!function_exists('cpms_public_affairs_collab_save_uploaded_files')) {
function cpms_public_affairs_collab_save_uploaded_files($task, $files, $actor) {
    if (!is_array($task) || !is_array($files) || !isset($files['name'])) return array();
    $saved = array();
    $names = is_array($files['name']) ? $files['name'] : array($files['name']);
    $tmpNames = is_array($files['tmp_name']) ? $files['tmp_name'] : array($files['tmp_name']);
    $errors = is_array($files['error']) ? $files['error'] : array($files['error']);
    $sizes = is_array($files['size']) ? $files['size'] : array($files['size']);
    $types = is_array($files['type']) ? $files['type'] : array($files['type']);
    $taskId = isset($task['id']) ? (int)$task['id'] : 0;
    $projectId = isset($task['project_id']) ? (int)$task['project_id'] : 0;
    if ($taskId <= 0) return $saved;
    $taskNo = cpms_public_affairs_collab_task_no($task);
    $safeTaskNo = preg_replace('/[^A-Za-z0-9_\\-]/', '', $taskNo);
    if ($safeTaskNo === '') $safeTaskNo = 'TASK-' . $taskId;
    $targetDir = cpms_public_affairs_collab_root_dir() . '/files/' . $safeTaskNo;
    if (!cpms_ensure_dir($targetDir)) return $saved;
    $blocked = array('php'=>true,'phtml'=>true,'phar'=>true,'exe'=>true,'bat'=>true,'cmd'=>true,'sh'=>true,'js'=>true,'html'=>true,'htm'=>true);
    $allowed = array('pdf'=>true,'doc'=>true,'docx'=>true,'xls'=>true,'xlsx'=>true,'ppt'=>true,'pptx'=>true,'jpg'=>true,'jpeg'=>true,'png'=>true,'gif'=>true,'zip'=>true,'txt'=>true);
    $store = cpms_public_affairs_collab_load_store('attachments');
    for ($i = 0; $i < count($names); $i++) {
        $originalName = isset($names[$i]) ? trim((string)$names[$i]) : '';
        $originalName = basename(str_replace('\\', '/', $originalName));
        $tmpName = isset($tmpNames[$i]) ? (string)$tmpNames[$i] : '';
        $errorCode = isset($errors[$i]) ? (int)$errors[$i] : UPLOAD_ERR_NO_FILE;
        $size = isset($sizes[$i]) ? (int)$sizes[$i] : 0;
        if ($errorCode !== UPLOAD_ERR_OK || $originalName === '' || $tmpName === '') continue;
        if ($size <= 0 || $size > (50 * 1024 * 1024)) continue;
        if (!is_uploaded_file($tmpName)) continue;
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ext === '' || isset($blocked[$ext]) || !isset($allowed[$ext])) continue;
        $storedName = 'pa_collab_' . $projectId . '_' . $taskId . '_' . date('Ymd_His') . '_' . substr(md5(uniqid('', true) . $originalName), 0, 10) . '.' . $ext;
        $storedPath = rtrim($targetDir, '/\\') . '/' . $storedName;
        if (!@move_uploaded_file($tmpName, $storedPath)) continue;
        $nextId = (int)$store['last_id'] + 1;
        $store['last_id'] = $nextId;
        $item = array(
            'id' => $nextId,
            'task_id' => $taskId,
            'task_no' => $taskNo,
            'project_id' => $projectId,
            'original_name' => $originalName,
            'stored_name' => $storedName,
            'stored_path' => $storedPath,
            'file_path' => $storedPath,
            'file_size' => $size,
            'mime_type' => isset($types[$i]) ? (string)$types[$i] : '',
            'uploaded_by_id' => is_array($actor) && isset($actor['id']) ? (int)$actor['id'] : 0,
            'uploaded_by_name' => cpms_public_affairs_collab_actor_label($actor),
            'uploaded_at' => date('Y-m-d H:i:s'),
            'deleted_at' => '',
        );
        $store['items'][] = $item;
        $saved[] = $item;
        cpms_public_affairs_collab_add_history($taskId, $projectId, '첨부파일 등록', 'attachment', '', $originalName, '첨부파일이 등록되었습니다.', $actor);
        cpms_public_affairs_collab_add_project_activity($projectId, '첨부파일 등록', 'attachment', '', $taskNo . ' ' . $originalName, '업무카드에 첨부파일이 등록되었습니다.', $actor, $taskId);
    }
    if (count($saved) > 0 && !cpms_public_affairs_collab_save_store('attachments', $store)) return array();
    if (count($saved) > 0) cpms_public_affairs_collab_sync_task_counts($taskId);
    return $saved;
}}

if (!function_exists('cpms_public_affairs_collab_lower')) {
function cpms_public_affairs_collab_lower($value) {
    $value = (string)$value;
    if (function_exists('mb_strtolower')) return mb_strtolower($value, 'UTF-8');
    return strtolower($value);
}}

if (!function_exists('cpms_public_affairs_collab_apply_filters')) {
function cpms_public_affairs_collab_apply_filters($tasks, $filters) {
    if (!is_array($tasks)) return array();
    $result = array();
    $projectId = isset($filters['project_id']) ? (int)$filters['project_id'] : 0;
    $projectName = cpms_public_affairs_collab_lower(trim((string)(isset($filters['project_name']) ? $filters['project_name'] : '')));
    $assigneeId = isset($filters['assignee_employee_id']) ? (int)$filters['assignee_employee_id'] : 0;
    $requesterId = isset($filters['requester_employee_id']) ? (int)$filters['requester_employee_id'] : 0;
    $status = isset($filters['status']) ? trim((string)$filters['status']) : '';
    $priority = isset($filters['priority']) ? trim((string)$filters['priority']) : '';
    $taskType = isset($filters['task_type']) ? trim((string)$filters['task_type']) : '';
    $dueFrom = cpms_public_affairs_collab_date(isset($filters['due_from']) ? $filters['due_from'] : '');
    $dueTo = cpms_public_affairs_collab_date(isset($filters['due_to']) ? $filters['due_to'] : '');
    $keyword = cpms_public_affairs_collab_lower(trim((string)(isset($filters['keyword']) ? $filters['keyword'] : '')));
    foreach ($tasks as $task) {
        if (!is_array($task)) continue;
        if ($projectId > 0 && (!isset($task['project_id']) || (int)$task['project_id'] !== $projectId)) continue;
        if ($projectName !== '') {
            $taskProjectName = cpms_public_affairs_collab_lower(isset($task['project_name']) ? (string)$task['project_name'] : '');
            if (strpos($taskProjectName, $projectName) === false) continue;
        }
        if ($assigneeId > 0 && (!isset($task['assignee_employee_id']) || (int)$task['assignee_employee_id'] !== $assigneeId)) continue;
        if ($requesterId > 0 && (!isset($task['requester_employee_id']) || (int)$task['requester_employee_id'] !== $requesterId)) continue;
        if ($status !== '' && (!isset($task['status']) || (string)$task['status'] !== $status)) continue;
        if ($priority !== '' && (!isset($task['priority']) || (string)$task['priority'] !== $priority)) continue;
        if ($taskType !== '' && (!isset($task['task_type']) || (string)$task['task_type'] !== $taskType)) continue;
        $dueDate = isset($task['due_date']) ? (string)$task['due_date'] : '';
        if ($dueFrom !== '' && ($dueDate === '' || strcmp($dueDate, $dueFrom) < 0)) continue;
        if ($dueTo !== '' && ($dueDate === '' || strcmp($dueDate, $dueTo) > 0)) continue;
        if ($keyword !== '') {
            $haystack = cpms_public_affairs_collab_lower(
                cpms_public_affairs_collab_task_no($task) . ' ' .
                (isset($task['title']) ? $task['title'] : '') . ' ' .
                (isset($task['content']) ? $task['content'] : '') . ' ' .
                (isset($task['project_name']) ? $task['project_name'] : '') . ' ' .
                (isset($task['task_type']) ? $task['task_type'] : '') . ' ' .
                (isset($task['assignee_name']) ? $task['assignee_name'] : '') . ' ' .
                (isset($task['requester_name']) ? $task['requester_name'] : '') . ' ' .
                cpms_public_affairs_collab_task_ref_names($task)
            );
            if (strpos($haystack, $keyword) === false) continue;
        }
        $result[] = $task;
    }
    return $result;
}}

if (!function_exists('cpms_public_affairs_collab_summary')) {
function cpms_public_affairs_collab_summary($tasks, $employee) {
    $summary = array('all' => 0, 'mine' => 0, 'today' => 0, 'delayed' => 0, 'done' => 0);
    $today = date('Y-m-d');
    if (!is_array($tasks)) return $summary;
    foreach ($tasks as $task) {
        if (!is_array($task)) continue;
        $summary['all']++;
        if (is_array($employee)) {
            $employeeId = isset($employee['id']) ? (int)$employee['id'] : 0;
            $employeeEmail = isset($employee['email']) ? strtolower(trim((string)$employee['email'])) : '';
            if (($employeeId > 0 && isset($task['assignee_employee_id']) && (int)$task['assignee_employee_id'] === $employeeId) ||
                ($employeeEmail !== '' && isset($task['assignee_email']) && strtolower(trim((string)$task['assignee_email'])) === $employeeEmail)) {
                $summary['mine']++;
            }
        }
        $status = isset($task['status']) ? (string)$task['status'] : '';
        if ($status === '완료') $summary['done']++;
        if ($status !== '완료' && isset($task['due_date']) && (string)$task['due_date'] === $today) $summary['today']++;
        if (cpms_public_affairs_collab_is_delayed($task)) $summary['delayed']++;
    }
    return $summary;
}}

if (!function_exists('cpms_public_affairs_collab_group_by_status')) {
function cpms_public_affairs_collab_group_by_status($tasks, $statuses) {
    $groups = array();
    if (!is_array($statuses)) $statuses = array();
    foreach ($statuses as $status) $groups[$status] = array();
    $groups['기타'] = array();
    if (is_array($tasks)) {
        foreach ($tasks as $task) {
            if (!is_array($task)) continue;
            $status = isset($task['status']) ? (string)$task['status'] : '';
            if ($status === '' || !isset($groups[$status])) $status = '기타';
            $groups[$status][] = $task;
        }
    }
    if (count($groups['기타']) === 0) unset($groups['기타']);
    return $groups;
}}
