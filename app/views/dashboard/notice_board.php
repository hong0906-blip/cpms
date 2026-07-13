<?php
/**
 * Dashboard notice board.
 * PHP 5.6 compatible.
 */

if (!function_exists('cpms_dashboard_notice_label')) {
function cpms_dashboard_notice_label($key) {
    $labels = array(
        'notice' => '%EA%B3%B5%EC%A7%80%EC%82%AC%ED%95%AD',
        'subtitle' => '%EC%B5%9C%EC%8B%A0+%EA%B3%B5%EC%A7%80+%EB%82%B4%EC%9A%A9%EC%9D%84+%ED%99%95%EC%9D%B8%ED%95%B4+%EC%A3%BC%EC%84%B8%EC%9A%94.',
        'empty' => '%EB%93%B1%EB%A1%9D%EB%90%9C+%EA%B3%B5%EC%A7%80%EC%82%AC%ED%95%AD%EC%9D%B4+%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.',
        'pinned' => '%EA%B3%A0%EC%A0%95',
        'normal' => '%EC%9D%BC%EB%B0%98',
        'title' => '%EC%A0%9C%EB%AA%A9',
        'writer' => '%EC%9E%91%EC%84%B1%EC%9E%90',
        'date' => '%EB%93%B1%EB%A1%9D%EC%9D%BC',
        'status' => '%EC%83%81%ED%83%9C',
        'manage' => '%EA%B4%80%EB%A6%AC',
        'create' => '%EB%93%B1%EB%A1%9D',
        'edit' => '%EC%88%98%EC%A0%95',
        'save' => '%EC%A0%80%EC%9E%A5',
        'delete' => '%EC%82%AD%EC%A0%9C',
        'cancel' => '%EC%B7%A8%EC%86%8C',
        'active' => '%ED%99%9C%EC%84%B1',
        'inactive' => '%EC%88%A8%EA%B9%80',
        'fixed' => '%EC%83%81%EB%8B%A8+%EA%B3%A0%EC%A0%95',
        'notice_title' => '%EA%B3%B5%EC%A7%80+%EC%A0%9C%EB%AA%A9',
        'notice_content' => '%EA%B3%B5%EC%A7%80+%EB%82%B4%EC%9A%A9',
        'recent' => '%EC%B5%9C%EA%B7%BC+%EA%B3%B5%EC%A7%80',
        'close' => '%EB%8B%AB%EA%B8%B0',
        'all' => '%EC%A0%84%EC%B2%B4',
        'detail' => '%EA%B3%B5%EC%A7%80+%EC%83%81%EC%84%B8',
        'visible' => '%EB%85%B8%EC%B6%9C',
        'count_unit' => '%EA%B1%B4',
        'edit_save' => '%EC%88%98%EC%A0%95+%EC%A0%80%EC%9E%A5',
        'new_notice' => '%EC%83%88+%EA%B3%B5%EC%A7%80+%EB%93%B1%EB%A1%9D',
        'today_hidden' => '%EC%98%A4%EB%8A%98+23%3A59%EA%B9%8C%EC%A7%80+%EB%8B%A4%EC%8B%9C+%ED%91%9C%EC%8B%9C%EB%90%98%EC%A7%80+%EC%95%8A%EC%8A%B5%EB%8B%88%EB%8B%A4.',
        'confirm_delete' => '%EC%82%AD%EC%A0%9C%ED%95%98%EC%8B%9C%EA%B2%A0%EC%8A%B5%EB%8B%88%EA%B9%8C%3F',
        'saved' => '%EA%B3%B5%EC%A7%80%EB%A5%BC+%EB%93%B1%EB%A1%9D%ED%96%88%EC%8A%B5%EB%8B%88%EB%8B%A4.',
        'deleted' => '%EA%B3%B5%EC%A7%80%EB%A5%BC+%EC%82%AD%EC%A0%9C%ED%96%88%EC%8A%B5%EB%8B%88%EB%8B%A4.',
        'invalid' => '%EC%A0%9C%EB%AA%A9%EA%B3%BC+%EB%82%B4%EC%9A%A9%EC%9D%84+%EC%9E%85%EB%A0%A5%ED%95%B4+%EC%A3%BC%EC%84%B8%EC%9A%94.',
        'forbidden' => '%EA%B6%8C%ED%95%9C%EC%9D%B4+%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.'
    );
    return isset($labels[$key]) ? urldecode($labels[$key]) : (string)$key;
}}

if (!function_exists('cpms_dashboard_notice_store_path')) {
function cpms_dashboard_notice_store_path() {
    return cpms_storage_root() . '/notices/dashboard_notices.json';
}}

if (!function_exists('cpms_dashboard_notice_read_store')) {
function cpms_dashboard_notice_read_store() {
    $data = cpms_read_json_file(cpms_dashboard_notice_store_path(), array('items' => array()));
    if (!is_array($data)) $data = array();
    if (!isset($data['items']) || !is_array($data['items'])) $data['items'] = array();
    return $data;
}}

if (!function_exists('cpms_dashboard_notice_write_store')) {
function cpms_dashboard_notice_write_store($data) {
    if (!is_array($data)) $data = array();
    if (!isset($data['items']) || !is_array($data['items'])) $data['items'] = array();
    $data['updated_at'] = date('Y-m-d H:i:s');
    return cpms_write_json_file(cpms_dashboard_notice_store_path(), $data);
}}

if (!function_exists('cpms_dashboard_notice_new_id')) {
function cpms_dashboard_notice_new_id() {
    return 'DN-' . date('YmdHis') . '-' . substr(md5(uniqid('', true)), 0, 8);
}}

if (!function_exists('cpms_dashboard_notice_flash_set')) {
function cpms_dashboard_notice_flash_set($type, $message) {
    $_SESSION['_dashboard_notice_flash'] = array('type' => (string)$type, 'message' => (string)$message);
}}

if (!function_exists('cpms_dashboard_notice_flash_get')) {
function cpms_dashboard_notice_flash_get() {
    if (!empty($_SESSION['_dashboard_notice_flash']) && is_array($_SESSION['_dashboard_notice_flash'])) {
        $flash = $_SESSION['_dashboard_notice_flash'];
        unset($_SESSION['_dashboard_notice_flash']);
        return $flash;
    }
    return null;
}}

if (!function_exists('cpms_dashboard_notice_can_manage')) {
function cpms_dashboard_notice_can_manage() {
    if (!class_exists('App\\Core\\Auth') || !\App\Core\Auth::check()) return false;
    if (\App\Core\Auth::isMaster()) return true;
    if (\App\Core\Auth::userRole() === 'executive') return true;

    $dept = trim((string)\App\Core\Auth::userDepartment());
    $dept = str_replace(array(' ', "\t", "\r", "\n"), '', $dept);
    $managementDepts = array(
        urldecode('%EA%B4%80%EB%A6%AC'),
        urldecode('%EA%B4%80%EB%A6%AC%EB%B6%80'),
        urldecode('%EA%B4%80%EB%A6%AC%ED%8C%80')
    );
    if (in_array($dept, $managementDepts, true)) return true;

    $roleText = trim((string)\App\Core\Auth::userRole());
    $positionText = method_exists('App\\Core\\Auth', 'userPosition') ? trim((string)\App\Core\Auth::userPosition()) : '';
    $checkText = $roleText . ' ' . $positionText;
    $checkText = str_replace(array(' ', "\t", "\r", "\n", '-', '_'), '', $checkText);
    if (function_exists('mb_strtolower')) $checkText = mb_strtolower($checkText, 'UTF-8');
    else $checkText = strtolower($checkText);

    $allowedWords = array(
        urldecode('%EB%8C%80%ED%91%9C'),
        urldecode('%EB%B6%80%EC%82%AC%EC%9E%A5'),
        'ceo',
        'president',
        'vicepresident',
        'vp'
    );
    foreach ($allowedWords as $word) {
        $word = str_replace(array(' ', "\t", "\r", "\n", '-', '_'), '', (string)$word);
        if (function_exists('mb_strtolower')) $word = mb_strtolower($word, 'UTF-8');
        else $word = strtolower($word);
        if ($word !== '' && strpos($checkText, $word) !== false) return true;
    }

    return false;
}}

if (!function_exists('cpms_dashboard_notice_normalize_item')) {
function cpms_dashboard_notice_normalize_item($item) {
    if (!is_array($item)) $item = array();
    $id = isset($item['id']) ? trim((string)$item['id']) : '';
    if ($id === '') $id = cpms_dashboard_notice_new_id();
    return array(
        'id' => $id,
        'title' => isset($item['title']) ? trim((string)$item['title']) : '',
        'content' => isset($item['content']) ? trim((string)$item['content']) : '',
        'author_name' => isset($item['author_name']) ? trim((string)$item['author_name']) : '',
        'author_email' => isset($item['author_email']) ? trim((string)$item['author_email']) : '',
        'is_active' => isset($item['is_active']) ? (int)$item['is_active'] : 1,
        'is_pinned' => isset($item['is_pinned']) ? (int)$item['is_pinned'] : 0,
        'created_at' => isset($item['created_at']) ? trim((string)$item['created_at']) : date('Y-m-d H:i:s'),
        'updated_at' => isset($item['updated_at']) ? trim((string)$item['updated_at']) : ''
    );
}}

if (!function_exists('cpms_dashboard_notice_sorted_items')) {
function cpms_dashboard_notice_sorted_items($includeInactive) {
    $store = cpms_dashboard_notice_read_store();
    $items = array();
    foreach ($store['items'] as $item) {
        $row = cpms_dashboard_notice_normalize_item($item);
        if (!$includeInactive && (int)$row['is_active'] !== 1) continue;
        if ($row['title'] === '' || $row['content'] === '') continue;
        $items[] = $row;
    }
    usort($items, function($a, $b) {
        $ap = isset($a['is_pinned']) ? (int)$a['is_pinned'] : 0;
        $bp = isset($b['is_pinned']) ? (int)$b['is_pinned'] : 0;
        if ($ap !== $bp) return ($ap > $bp) ? -1 : 1;
        $at = isset($a['created_at']) ? strtotime((string)$a['created_at']) : 0;
        $bt = isset($b['created_at']) ? strtotime((string)$b['created_at']) : 0;
        if ($at === $bt) return 0;
        return ($at > $bt) ? -1 : 1;
    });
    return $items;
}}

if (!function_exists('cpms_dashboard_notice_save_item')) {
function cpms_dashboard_notice_save_item($input) {
    $store = cpms_dashboard_notice_read_store();
    $id = isset($input['id']) ? trim((string)$input['id']) : '';
    $now = date('Y-m-d H:i:s');
    $found = false;
    $savedItem = null;
    $nextItems = array();

    $userName = class_exists('App\\Core\\Auth') ? (string)\App\Core\Auth::userName() : '';
    $userEmail = class_exists('App\\Core\\Auth') ? (string)\App\Core\Auth::userEmail() : '';

    foreach ($store['items'] as $item) {
        $row = cpms_dashboard_notice_normalize_item($item);
        if ($id !== '' && $row['id'] === $id) {
            $row['title'] = isset($input['title']) ? trim((string)$input['title']) : '';
            $row['content'] = isset($input['content']) ? trim((string)$input['content']) : '';
            $row['is_active'] = isset($input['is_active']) ? (int)$input['is_active'] : 0;
            $row['is_pinned'] = isset($input['is_pinned']) ? (int)$input['is_pinned'] : 0;
            $row['updated_at'] = $now;
            $found = true;
            $savedItem = $row;
        }
        $nextItems[] = $row;
    }

    if (!$found) {
        $savedItem = array(
            'id' => cpms_dashboard_notice_new_id(),
            'title' => isset($input['title']) ? trim((string)$input['title']) : '',
            'content' => isset($input['content']) ? trim((string)$input['content']) : '',
            'author_name' => $userName,
            'author_email' => $userEmail,
            'is_active' => isset($input['is_active']) ? (int)$input['is_active'] : 1,
            'is_pinned' => isset($input['is_pinned']) ? (int)$input['is_pinned'] : 0,
            'created_at' => $now,
            'updated_at' => ''
        );
        $nextItems[] = $savedItem;
    }

    $store['items'] = $nextItems;
    $ok = cpms_dashboard_notice_write_store($store);
    return array(
        'ok' => $ok ? true : false,
        'created' => $found ? false : true,
        'item' => is_array($savedItem) ? $savedItem : array()
    );
}}

if (!function_exists('cpms_dashboard_notice_employee_column_exists')) {
function cpms_dashboard_notice_employee_column_exists($pdo, $column) {
    if (!$pdo) return false;
    $column = trim((string)$column);
    if ($column === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $column)) return false;
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM employees LIKE :col");
        $st->execute(array(':col' => $column));
        return (bool)$st->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_dashboard_notice_receiver_employee_ids')) {
function cpms_dashboard_notice_receiver_employee_ids($pdo) {
    $ids = array();
    if (!$pdo) return $ids;
    if (!cpms_dashboard_notice_employee_column_exists($pdo, 'google_chat_enabled')) return $ids;

    $where = array('google_chat_enabled = 1');
    if (cpms_dashboard_notice_employee_column_exists($pdo, 'is_active')) {
        $where[] = 'is_active = 1';
    }

    $hasDmSpace = cpms_dashboard_notice_employee_column_exists($pdo, 'google_chat_dm_space_name');
    $hasUserName = cpms_dashboard_notice_employee_column_exists($pdo, 'google_chat_user_name');
    $dmConditions = array();
    if ($hasDmSpace) $dmConditions[] = "(google_chat_dm_space_name IS NOT NULL AND TRIM(google_chat_dm_space_name) <> '')";
    if ($hasUserName) $dmConditions[] = "(google_chat_user_name IS NOT NULL AND TRIM(google_chat_user_name) <> '')";
    if (count($dmConditions) === 0) return $ids;
    $where[] = '(' . implode(' OR ', $dmConditions) . ')';

    try {
        $sql = 'SELECT id FROM employees WHERE ' . implode(' AND ', $where) . ' ORDER BY id ASC';
        $st = $pdo->query($sql);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $employeeId = isset($row['id']) ? (int)$row['id'] : 0;
            if ($employeeId > 0) $ids[] = $employeeId;
        }
    } catch (Exception $e) {
        error_log('[dashboard_notice_chat] receiver lookup failed: ' . $e->getMessage());
    }
    return $ids;
}}

if (!function_exists('cpms_dashboard_notice_build_created_dm_message')) {
function cpms_dashboard_notice_build_created_dm_message($pdo, $notice, $employeeId) {
    if (!is_array($notice)) $notice = array();
    $title = isset($notice['title']) ? trim((string)$notice['title']) : '';
    $author = isset($notice['author_name']) ? trim((string)$notice['author_name']) : '';
    if ($author === '') $author = isset($notice['author_email']) ? trim((string)$notice['author_email']) : '';
    if ($author === '') $author = '-';
    if ($title === '') $title = '-';

    if (function_exists('cpms_app_route_url')) {
        $url = cpms_app_route_url($pdo, 'notices', array(), (int)$employeeId);
    } else if (function_exists('cpms_public_base_url')) {
        $url = cpms_public_base_url($pdo) . '/?r=notices';
    } else {
        $url = '?r=notices';
    }

    $lines = array();
    $lines[] = urldecode('%EA%B3%B5%EC%A7%80%EC%82%AC%ED%95%AD%EC%9D%B4%20%EC%9E%91%EC%84%B1%EB%90%98%EC%97%88%EC%8A%B5%EB%8B%88%EB%8B%A4.%20%ED%99%95%EC%9D%B8%ED%95%B4%EC%A3%BC%EC%84%B8%EC%9A%94.');
    $lines[] = urldecode('%EC%9E%91%EC%84%B1%EC%9E%90') . ' : ' . $author;
    $lines[] = urldecode('%EC%A0%9C%EB%AA%A9') . ' : ' . $title;
    $lines[] = 'URL : ' . $url;
    return implode("\n", $lines);
}}

if (!function_exists('cpms_dashboard_notice_send_created_dm')) {
function cpms_dashboard_notice_send_created_dm($pdo, $notice) {
    $result = array('total' => 0, 'sent' => 0, 'failed' => 0);
    if (!$pdo || !is_array($notice)) return $result;
    if (!function_exists('cpms_send_google_chat_to_employee')) return $result;

    $employeeIds = cpms_dashboard_notice_receiver_employee_ids($pdo);
    $result['total'] = count($employeeIds);
    foreach ($employeeIds as $employeeId) {
        $message = cpms_dashboard_notice_build_created_dm_message($pdo, $notice, (int)$employeeId);
        $ok = cpms_send_google_chat_to_employee($pdo, (int)$employeeId, $message, 0, 'NOTICE_CREATED', 'DASHBOARD_NOTICE');
        if ($ok) $result['sent']++;
        else $result['failed']++;
    }
    return $result;
}}

if (!function_exists('cpms_dashboard_notice_source_id')) {
function cpms_dashboard_notice_source_id($notice) {
    $noticeId = is_array($notice) && isset($notice['id']) ? trim((string)$notice['id']) : '';
    if ($noticeId === '') $noticeId = date('YmdHis');
    return (int)hexdec(substr(md5($noticeId), 0, 7));
}}

if (!function_exists('cpms_dashboard_notice_excerpt')) {
function cpms_dashboard_notice_excerpt($content, $limit) {
    $content = trim((string)$content);
    $limit = (int)$limit;
    if ($limit <= 0) return '';
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($content, 'UTF-8') <= $limit) return $content;
        return mb_substr($content, 0, $limit, 'UTF-8') . '...';
    }
    if (preg_match_all('/./us', $content, $matches) && isset($matches[0]) && is_array($matches[0])) {
        if (count($matches[0]) <= $limit) return $content;
        return implode('', array_slice($matches[0], 0, $limit)) . '...';
    }
    if (strlen($content) <= $limit) return $content;
    return substr($content, 0, $limit) . '...';
}}

if (!function_exists('cpms_dashboard_notice_build_created_company_message')) {
function cpms_dashboard_notice_build_created_company_message($notice) {
    if (!is_array($notice)) $notice = array();
    $title = isset($notice['title']) ? trim((string)$notice['title']) : '';
    $content = isset($notice['content']) ? cpms_dashboard_notice_excerpt($notice['content'], 500) : '';
    $author = isset($notice['author_name']) ? trim((string)$notice['author_name']) : '';
    if ($author === '') $author = isset($notice['author_email']) ? trim((string)$notice['author_email']) : '';
    if ($author === '') $author = '-';
    if ($title === '') $title = '-';
    if ($content === '') $content = '-';

    $lines = array(
        '[CPMS 공지사항]',
        '',
        '새 공지사항이 등록되었습니다.',
        '',
        '제목 : ' . $title,
        '내용 :',
        $content,
        '',
        '작성자 : ' . $author,
        '',
        '공지사항에서 확인해 주세요.'
    );
    return implode("\n", $lines);
}}

if (!function_exists('cpms_dashboard_notice_send_created_company_chat')) {
function cpms_dashboard_notice_send_created_company_chat($pdo, $notice) {
    if (!$pdo || !is_array($notice)) return false;
    if (!function_exists('cpms_google_chat_send_to_company_space')) {
        require_once __DIR__ . '/../common/chat_notification_helpers.php';
    }
    if (!function_exists('cpms_google_chat_send_to_company_space')) return false;
    $message = cpms_dashboard_notice_build_created_company_message($notice);
    $sourceId = cpms_dashboard_notice_source_id($notice);
    return cpms_google_chat_send_to_company_space($pdo, $message, 'NOTICE_CREATED_COMPANY_SPACE', $sourceId, 'DASHBOARD_NOTICE');
}}

if (!function_exists('cpms_dashboard_notice_delete_item')) {
function cpms_dashboard_notice_delete_item($id) {
    $id = trim((string)$id);
    if ($id === '') return false;
    $store = cpms_dashboard_notice_read_store();
    $nextItems = array();
    foreach ($store['items'] as $item) {
        $row = cpms_dashboard_notice_normalize_item($item);
        if ($row['id'] === $id) continue;
        $nextItems[] = $row;
    }
    $store['items'] = $nextItems;
    return cpms_dashboard_notice_write_store($store);
}}

if (!function_exists('cpms_dashboard_notice_return_url')) {
function cpms_dashboard_notice_return_url() {
    $url = isset($_SERVER['REQUEST_URI']) ? trim((string)$_SERVER['REQUEST_URI']) : '';
    if ($url === '') $url = '?r=dashboard';
    return $url;
}}

if (!function_exists('cpms_dashboard_notice_meta')) {
function cpms_dashboard_notice_meta($notice) {
    $author = isset($notice['author_name']) && trim((string)$notice['author_name']) !== '' ? trim((string)$notice['author_name']) : '-';
    $created = isset($notice['created_at']) ? trim((string)$notice['created_at']) : '';
    if ($created !== '' && strlen($created) > 16) $created = substr($created, 0, 16);
    return $author . ' / ' . ($created !== '' ? $created : '-');
}}

if (!function_exists('cpms_render_dashboard_notice_board')) {
function cpms_render_dashboard_notice_board($pdo) {
    $canManage = cpms_dashboard_notice_can_manage();
    $items = cpms_dashboard_notice_sorted_items($canManage);
    $returnUrl = cpms_dashboard_notice_return_url();
    $actionUrl = base_url() . '/?r=notice_save';
    $noticeFlash = cpms_dashboard_notice_flash_get();
    ?>
    <div id="cpmsDashboardNoticeBoard" class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100 mb-8">
        <?php if (is_array($noticeFlash) && isset($noticeFlash['message']) && trim((string)$noticeFlash['message']) !== ''): ?>
            <div class="mb-4 p-4 rounded-2xl border <?php echo (isset($noticeFlash['type']) && (string)$noticeFlash['type'] === 'success') ? 'bg-emerald-50 border-emerald-200 text-emerald-700' : 'bg-red-50 border-red-200 text-red-700'; ?>">
                <?php echo h($noticeFlash['message']); ?>
            </div>
        <?php endif; ?>
        <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4 mb-5">
            <div class="min-w-0">
                <div class="flex items-center gap-3">
                    <span class="inline-flex items-center justify-center w-11 h-11 rounded-2xl bg-sky-50 text-sky-700 border border-sky-100">
                        <i data-lucide="megaphone" class="w-5 h-5"></i>
                    </span>
                    <div>
                        <h2 class="text-2xl font-extrabold text-gray-900"><?php echo h(cpms_dashboard_notice_label('notice')); ?></h2>
                        <div class="text-sm text-gray-500 mt-1"><?php echo h(cpms_dashboard_notice_label('subtitle')); ?></div>
                    </div>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span class="px-3 py-2 rounded-full bg-slate-100 text-slate-700 text-sm font-extrabold"><?php echo h(cpms_dashboard_notice_label('all')); ?> <?php echo count($items); ?><?php echo h(cpms_dashboard_notice_label('count_unit')); ?></span>
                <?php if ($canManage): ?>
                    <a href="#cpmsDashboardNoticeFormWrap" class="inline-flex items-center gap-2 px-4 py-3 rounded-2xl bg-gray-900 text-white text-sm font-extrabold">
                        <i data-lucide="plus" class="w-4 h-4"></i><?php echo h(cpms_dashboard_notice_label('create')); ?>
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <?php if (count($items) === 0): ?>
            <div class="p-6 rounded-2xl border border-dashed border-gray-300 text-sm text-gray-500"><?php echo h(cpms_dashboard_notice_label('empty')); ?></div>
        <?php else: ?>
            <div class="overflow-x-auto rounded-2xl border border-gray-200 bg-white">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-gray-600">
                        <tr>
                            <th class="px-4 py-3 text-left font-extrabold w-24"><?php echo h(cpms_dashboard_notice_label('status')); ?></th>
                            <th class="px-4 py-3 text-left font-extrabold"><?php echo h(cpms_dashboard_notice_label('title')); ?></th>
                            <th class="px-4 py-3 text-left font-extrabold w-36"><?php echo h(cpms_dashboard_notice_label('writer')); ?></th>
                            <th class="px-4 py-3 text-left font-extrabold w-40"><?php echo h(cpms_dashboard_notice_label('date')); ?></th>
                            <?php if ($canManage): ?>
                                <th class="px-4 py-3 text-right font-extrabold w-36"><?php echo h(cpms_dashboard_notice_label('manage')); ?></th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $notice): ?>
                            <?php
                            $noticeId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$notice['id']);
                            $noticeTitle = isset($notice['title']) ? (string)$notice['title'] : '';
                            $noticeContent = isset($notice['content']) ? (string)$notice['content'] : '';
                            $createdAt = isset($notice['created_at']) ? (string)$notice['created_at'] : '';
                            if ($createdAt !== '' && strlen($createdAt) > 16) $createdAt = substr($createdAt, 0, 16);
                            ?>
                            <tr class="border-t border-gray-100 hover:bg-sky-50/40">
                                <td class="px-4 py-3 align-top">
                                    <?php if ((int)$notice['is_pinned'] === 1): ?>
                                        <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full bg-amber-50 text-amber-700 border border-amber-100 text-xs font-extrabold">
                                            <i data-lucide="pin" class="w-3 h-3"></i><?php echo h(cpms_dashboard_notice_label('pinned')); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex px-2 py-1 rounded-full bg-slate-100 text-slate-600 text-xs font-bold"><?php echo h(cpms_dashboard_notice_label('normal')); ?></span>
                                    <?php endif; ?>
                                    <?php if ($canManage && (int)$notice['is_active'] !== 1): ?>
                                        <span class="mt-1 inline-flex px-2 py-1 rounded-full bg-gray-100 text-gray-500 text-xs font-bold"><?php echo h(cpms_dashboard_notice_label('inactive')); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 align-top">
                                    <button type="button" class="text-left font-extrabold text-gray-900 hover:text-sky-700 break-words" data-dashboard-notice-open="<?php echo h($noticeId); ?>">
                                        <?php echo h($noticeTitle); ?>
                                    </button>
                                    <div id="cpmsDashboardNoticeContent-<?php echo h($noticeId); ?>" class="hidden">
                                        <div data-notice-title><?php echo h($noticeTitle); ?></div>
                                        <div data-notice-meta><?php echo h(cpms_dashboard_notice_meta($notice)); ?></div>
                                        <div data-notice-body><?php echo nl2br(h($noticeContent)); ?></div>
                                    </div>
                                </td>
                                <td class="px-4 py-3 align-top text-gray-600"><?php echo h(isset($notice['author_name']) && trim((string)$notice['author_name']) !== '' ? $notice['author_name'] : '-'); ?></td>
                                <td class="px-4 py-3 align-top text-gray-600"><?php echo h($createdAt !== '' ? $createdAt : '-'); ?></td>
                                <?php if ($canManage): ?>
                                    <td class="px-4 py-3 align-top text-right">
                                        <div class="inline-flex items-center gap-2">
                                            <button type="button"
                                                class="inline-flex items-center justify-center w-9 h-9 rounded-xl border border-gray-200 bg-white text-gray-700 hover:bg-gray-50"
                                                title="<?php echo h(cpms_dashboard_notice_label('edit')); ?>"
                                                data-dashboard-notice-edit
                                                data-id="<?php echo h($notice['id']); ?>"
                                                data-title="<?php echo h($noticeTitle); ?>"
                                                data-content="<?php echo h($noticeContent); ?>"
                                                data-active="<?php echo (int)$notice['is_active']; ?>"
                                                data-pinned="<?php echo (int)$notice['is_pinned']; ?>">
                                                <i data-lucide="pencil" class="w-4 h-4"></i>
                                            </button>
                                            <form method="post" action="<?php echo h($actionUrl); ?>" data-dashboard-notice-delete>
                                                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                                <input type="hidden" name="return_url" value="<?php echo h($returnUrl); ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo h($notice['id']); ?>">
                                                <button type="submit" class="inline-flex items-center justify-center w-9 h-9 rounded-xl border border-red-100 bg-red-50 text-red-700 hover:bg-red-100" title="<?php echo h(cpms_dashboard_notice_label('delete')); ?>">
                                                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if ($canManage): ?>
            <div id="cpmsDashboardNoticeFormWrap" class="mt-5 rounded-2xl border border-gray-200 bg-slate-50 p-4">
                <div class="font-extrabold text-gray-900 mb-3"><?php echo h(cpms_dashboard_notice_label('new_notice')); ?></div>
                <form id="cpmsDashboardNoticeForm" method="post" action="<?php echo h($actionUrl); ?>" class="space-y-3">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="return_url" value="<?php echo h($returnUrl); ?>">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1"><?php echo h(cpms_dashboard_notice_label('notice_title')); ?></label>
                        <input type="text" name="title" class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white" required>
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1"><?php echo h(cpms_dashboard_notice_label('notice_content')); ?></label>
                        <textarea name="content" rows="5" class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white" required></textarea>
                    </div>
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="flex flex-wrap items-center gap-3">
                            <label class="inline-flex items-center gap-2 px-3 py-2 rounded-2xl bg-white border border-gray-200 text-sm font-bold text-gray-700">
                                <input type="hidden" name="is_active" value="0">
                                <input type="checkbox" name="is_active" value="1" checked>
                                <?php echo h(cpms_dashboard_notice_label('visible')); ?>
                            </label>
                            <label class="inline-flex items-center gap-2 px-3 py-2 rounded-2xl bg-white border border-gray-200 text-sm font-bold text-gray-700">
                                <input type="hidden" name="is_pinned" value="0">
                                <input type="checkbox" name="is_pinned" value="1">
                                <?php echo h(cpms_dashboard_notice_label('fixed')); ?>
                            </label>
                        </div>
                        <div class="flex items-center gap-2">
                            <button type="button" data-dashboard-notice-form-reset class="px-4 py-3 rounded-2xl border border-gray-200 bg-white text-gray-700 font-extrabold"><?php echo h(cpms_dashboard_notice_label('cancel')); ?></button>
                            <button type="submit" data-dashboard-notice-submit class="px-5 py-3 rounded-2xl bg-gray-900 text-white font-extrabold"><?php echo h(cpms_dashboard_notice_label('save')); ?></button>
                        </div>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <div id="modal-dashboardNoticeDetail" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 bg-black/45" data-dashboard-notice-detail-close></div>
        <div class="absolute inset-0 flex items-center justify-center p-4">
            <div class="w-full max-w-3xl max-h-[88vh] overflow-hidden rounded-3xl bg-white shadow-2xl border border-gray-100">
                <div class="flex items-center justify-between px-6 py-5 border-b border-gray-100">
                    <div class="min-w-0">
                        <div class="text-2xl font-extrabold text-gray-900" data-dashboard-notice-detail-title><?php echo h(cpms_dashboard_notice_label('detail')); ?></div>
                        <div class="text-sm text-gray-500 mt-1" data-dashboard-notice-detail-meta></div>
                    </div>
                    <button type="button" class="p-3 rounded-2xl hover:bg-gray-100" data-dashboard-notice-detail-close><?php echo h(cpms_dashboard_notice_label('close')); ?></button>
                </div>
                <div class="p-5 md:p-6 overflow-y-auto max-h-[66vh] text-sm leading-7 text-gray-700" data-dashboard-notice-detail-body></div>
            </div>
        </div>
    </div>

    <script>
    (function(){
        var detailModal = document.getElementById('modal-dashboardNoticeDetail');
        var deleteMessage = <?php echo json_encode(cpms_dashboard_notice_label('confirm_delete')); ?>;
        var editSaveText = <?php echo json_encode(cpms_dashboard_notice_label('edit_save')); ?>;
        var saveText = <?php echo json_encode(cpms_dashboard_notice_label('save')); ?>;
        var newNoticeText = <?php echo json_encode(cpms_dashboard_notice_label('new_notice')); ?>;

        function closeDetail() {
            if (detailModal) detailModal.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        }
        function openDetail(id) {
            if (!detailModal || !id) return;
            var template = document.getElementById('cpmsDashboardNoticeContent-' + id);
            if (!template) return;
            var title = template.querySelector('[data-notice-title]');
            var meta = template.querySelector('[data-notice-meta]');
            var body = template.querySelector('[data-notice-body]');
            var detailTitle = detailModal.querySelector('[data-dashboard-notice-detail-title]');
            var detailMeta = detailModal.querySelector('[data-dashboard-notice-detail-meta]');
            var detailBody = detailModal.querySelector('[data-dashboard-notice-detail-body]');
            if (detailTitle) detailTitle.textContent = title ? title.textContent : '';
            if (detailMeta) detailMeta.textContent = meta ? meta.textContent : '';
            if (detailBody) detailBody.innerHTML = body ? body.innerHTML : '';
            detailModal.classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
        }

        var detailCloseButtons = document.querySelectorAll('[data-dashboard-notice-detail-close]');
        for (var j = 0; j < detailCloseButtons.length; j++) {
            detailCloseButtons[j].addEventListener('click', function(e){
                e.preventDefault();
                closeDetail();
            });
        }

        var openButtons = document.querySelectorAll('[data-dashboard-notice-open]');
        for (var k = 0; k < openButtons.length; k++) {
            openButtons[k].addEventListener('click', function(e){
                e.preventDefault();
                openDetail(this.getAttribute('data-dashboard-notice-open'));
            });
        }

        var deleteForms = document.querySelectorAll('[data-dashboard-notice-delete]');
        for (var d = 0; d < deleteForms.length; d++) {
            deleteForms[d].addEventListener('submit', function(e){
                if (!confirm(deleteMessage)) {
                    e.preventDefault();
                    return false;
                }
            });
        }

        var form = document.getElementById('cpmsDashboardNoticeForm');
        if (form) {
            var formTitle = document.querySelector('#cpmsDashboardNoticeFormWrap .font-extrabold');
            var submitButton = form.querySelector('[data-dashboard-notice-submit]');
            var idInput = form.querySelector('input[name="id"]');
            var titleInput = form.querySelector('input[name="title"]');
            var contentInput = form.querySelector('textarea[name="content"]');
            var activeInput = form.querySelector('input[type="checkbox"][name="is_active"]');
            var pinnedInput = form.querySelector('input[type="checkbox"][name="is_pinned"]');
            function resetForm() {
                if (idInput) idInput.value = '';
                if (titleInput) titleInput.value = '';
                if (contentInput) contentInput.value = '';
                if (activeInput) activeInput.checked = true;
                if (pinnedInput) pinnedInput.checked = false;
                if (submitButton) submitButton.textContent = saveText;
                if (formTitle) formTitle.textContent = newNoticeText;
            }
            var resetButton = form.querySelector('[data-dashboard-notice-form-reset]');
            if (resetButton) {
                resetButton.addEventListener('click', function(e){
                    e.preventDefault();
                    resetForm();
                });
            }
            var editButtons = document.querySelectorAll('[data-dashboard-notice-edit]');
            for (var eidx = 0; eidx < editButtons.length; eidx++) {
                editButtons[eidx].addEventListener('click', function(e){
                    e.preventDefault();
                    if (idInput) idInput.value = this.getAttribute('data-id') || '';
                    if (titleInput) titleInput.value = this.getAttribute('data-title') || '';
                    if (contentInput) contentInput.value = this.getAttribute('data-content') || '';
                    if (activeInput) activeInput.checked = (this.getAttribute('data-active') === '1');
                    if (pinnedInput) pinnedInput.checked = (this.getAttribute('data-pinned') === '1');
                    if (submitButton) submitButton.textContent = editSaveText;
                    if (formTitle) formTitle.textContent = editSaveText;
                    try { form.scrollIntoView({behavior:'smooth', block:'start'}); } catch (err) { form.scrollIntoView(); }
                });
            }
        }

        document.addEventListener('keydown', function(e){
            if (e.key === 'Escape') {
                if (detailModal && !detailModal.classList.contains('hidden')) closeDetail();
            }
        });

        if (window.lucide) { try { lucide.createIcons(); } catch (ignore) {} }
    })();
    </script>
    <?php
}}

if (!function_exists('cpms_render_dashboard_notice_modal')) {
function cpms_render_dashboard_notice_modal() {
    $activeItems = cpms_dashboard_notice_sorted_items(false);
    if (count($activeItems) === 0) return;
    $signatureParts = array();
    foreach ($activeItems as $noticeSignatureRow) {
        $signatureParts[] =
            (isset($noticeSignatureRow['id']) ? (string)$noticeSignatureRow['id'] : '') . ':' .
            (isset($noticeSignatureRow['created_at']) ? (string)$noticeSignatureRow['created_at'] : '') . ':' .
            (isset($noticeSignatureRow['updated_at']) ? (string)$noticeSignatureRow['updated_at'] : '') . ':' .
            md5((isset($noticeSignatureRow['title']) ? (string)$noticeSignatureRow['title'] : '') . "\n" . (isset($noticeSignatureRow['content']) ? (string)$noticeSignatureRow['content'] : ''));
    }
    $noticeSignature = md5(implode('|', $signatureParts));
    ?>
    <div id="modal-dashboardNoticeAuto" class="fixed inset-0 z-50 hidden" data-dashboard-notice-auto="1">
        <div class="absolute inset-0 bg-black/45" data-dashboard-notice-auto-close></div>
        <div class="absolute inset-0 flex items-center justify-center p-4">
            <div class="w-full max-w-3xl max-h-[88vh] overflow-hidden rounded-3xl bg-white shadow-2xl border border-gray-100">
                <div class="flex items-center justify-between px-6 py-5 border-b border-gray-100">
                    <div>
                        <div class="text-2xl font-extrabold text-gray-900"><?php echo h(cpms_dashboard_notice_label('notice')); ?></div>
                        <div class="text-sm text-gray-500 mt-1"><?php echo h(cpms_dashboard_notice_label('recent')); ?></div>
                    </div>
                    <button type="button" class="p-3 rounded-2xl hover:bg-gray-100" data-dashboard-notice-auto-close><?php echo h(cpms_dashboard_notice_label('close')); ?></button>
                </div>
                <div class="p-5 md:p-6 overflow-y-auto max-h-[66vh] space-y-4">
                    <?php foreach ($activeItems as $notice): ?>
                        <div class="rounded-2xl border border-gray-200 bg-slate-50 p-4">
                            <div class="flex flex-wrap items-center gap-2">
                                <?php if ((int)$notice['is_pinned'] === 1): ?>
                                    <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full bg-amber-50 text-amber-700 border border-amber-100 text-xs font-extrabold">
                                        <i data-lucide="pin" class="w-3 h-3"></i><?php echo h(cpms_dashboard_notice_label('pinned')); ?>
                                    </span>
                                <?php endif; ?>
                                <div class="text-lg font-extrabold text-gray-900"><?php echo h(isset($notice['title']) ? $notice['title'] : ''); ?></div>
                            </div>
                            <div class="text-xs text-gray-500 mt-2"><?php echo h(cpms_dashboard_notice_meta($notice)); ?></div>
                            <div class="mt-4 text-sm leading-7 text-gray-700 whitespace-normal"><?php echo nl2br(h(isset($notice['content']) ? $notice['content'] : '')); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="px-6 py-4 border-t border-gray-100 bg-gray-50 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <div class="text-xs text-gray-500"><?php echo h(cpms_dashboard_notice_label('today_hidden')); ?></div>
                    <button type="button" class="px-5 py-3 rounded-2xl bg-gray-900 text-white font-extrabold" data-dashboard-notice-auto-close><?php echo h(cpms_dashboard_notice_label('close')); ?></button>
                </div>
            </div>
        </div>
    </div>
    <script>
    (function(){
        var autoModal = document.getElementById('modal-dashboardNoticeAuto');
        var storageKey = 'cpms_dashboard_notice_closed_until';
        var signatureKey = 'cpms_dashboard_notice_signature';
        var noticeSignature = <?php echo json_encode($noticeSignature); ?>;
        function endOfTodayTime() {
            var now = new Date();
            return new Date(now.getFullYear(), now.getMonth(), now.getDate(), 23, 59, 59, 999).getTime();
        }
        function shouldShowAuto() {
            try {
                var raw = window.localStorage ? localStorage.getItem(storageKey) : '';
                var storedSignature = window.localStorage ? (localStorage.getItem(signatureKey) || '') : '';
                var until = raw ? parseInt(raw, 10) : 0;
                if (noticeSignature && storedSignature !== noticeSignature) return true;
                if (until && until >= new Date().getTime()) return false;
            } catch (e) {}
            return true;
        }
        function closeAuto() {
            if (autoModal) autoModal.classList.add('hidden');
            try {
                if (window.localStorage) {
                    localStorage.setItem(storageKey, String(endOfTodayTime()));
                    localStorage.setItem(signatureKey, noticeSignature || '');
                }
            } catch (e) {}
            document.body.classList.remove('overflow-hidden');
        }
        function openAuto() {
            if (!autoModal || !shouldShowAuto()) return;
            autoModal.classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
        }
        var closeButtons = document.querySelectorAll('[data-dashboard-notice-auto-close]');
        for (var i = 0; i < closeButtons.length; i++) {
            closeButtons[i].addEventListener('click', function(e){
                e.preventDefault();
                closeAuto();
            });
        }
        document.addEventListener('keydown', function(e){
            if (e.key === 'Escape' && autoModal && !autoModal.classList.contains('hidden')) closeAuto();
        });
        openAuto();
        if (window.lucide) { try { lucide.createIcons(); } catch (ignore) {} }
    })();
    </script>
    <?php
}}

if (!function_exists('cpms_dashboard_birthday_text')) {
function cpms_dashboard_birthday_text($encoded) {
    return urldecode($encoded);
}}

if (!function_exists('cpms_dashboard_birthday_today')) {
function cpms_dashboard_birthday_today() {
    if (function_exists('attendance_today')) return attendance_today();
    try {
        $dt = new DateTime('now', new DateTimeZone('Asia/Seoul'));
        return $dt->format('Y-m-d');
    } catch (Exception $e) {
        return date('Y-m-d');
    }
}}

if (!function_exists('cpms_dashboard_birthday_employee_column_exists')) {
function cpms_dashboard_birthday_employee_column_exists($pdo, $column) {
    if (!$pdo) return false;
    $column = trim((string)$column);
    if ($column === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $column)) return false;
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM employees LIKE :col");
        $st->execute(array(':col' => $column));
        return (bool)$st->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_dashboard_birthday_month_day')) {
function cpms_dashboard_birthday_month_day($value) {
    $value = trim((string)$value);
    if ($value === '' || $value === '0000-00-00') return array(0, 0);

    $month = 0;
    $day = 0;
    if (preg_match('/^\d{4}[-\/.](\d{1,2})[-\/.](\d{1,2})$/', $value, $m)) {
        $month = (int)$m[1];
        $day = (int)$m[2];
    } else if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})$/', $value, $m)) {
        $month = (int)$m[1];
        $day = (int)$m[2];
    } else if (preg_match('/(\d{1,2})\D+(\d{1,2})/', $value, $m)) {
        $month = (int)$m[1];
        $day = (int)$m[2];
    }

    if (!checkdate($month, $day, 2000)) return array(0, 0);
    return array($month, $day);
}}

if (!function_exists('cpms_dashboard_birthday_message')) {
function cpms_dashboard_birthday_message($person) {
    $name = is_array($person) && isset($person['name']) ? trim((string)$person['name']) : '';
    $position = is_array($person) && isset($person['position']) ? trim((string)$person['position']) : '';
    $suffix = cpms_dashboard_birthday_text('%EC%83%9D%EC%9D%BC%EC%B6%95%ED%95%98%ED%95%A9%EB%8B%88%EB%8B%A4%21');
    if ($name === '') return '';
    if ($position !== '') return $name . ' ' . $position . cpms_dashboard_birthday_text('%EB%8B%98%20') . $suffix;
    return $name . cpms_dashboard_birthday_text('%EB%8B%98%20') . $suffix;
}}

if (!function_exists('cpms_dashboard_birthday_today_employees')) {
function cpms_dashboard_birthday_today_employees($pdo, $today) {
    $items = array();
    if (!$pdo) return $items;
    if (!cpms_dashboard_birthday_employee_column_exists($pdo, 'name')) return $items;
    if (!cpms_dashboard_birthday_employee_column_exists($pdo, 'birth_date')) return $items;

    $todayParts = cpms_dashboard_birthday_month_day($today);
    $todayMonth = isset($todayParts[0]) ? (int)$todayParts[0] : 0;
    $todayDay = isset($todayParts[1]) ? (int)$todayParts[1] : 0;
    if ($todayMonth < 1 || $todayDay < 1) return $items;

    $positionSelect = cpms_dashboard_birthday_employee_column_exists($pdo, 'position') ? 'position' : "'' AS position";
    $where = array("birth_date IS NOT NULL", "CAST(birth_date AS CHAR) <> ''", "CAST(birth_date AS CHAR) <> '0000-00-00'");
    if (cpms_dashboard_birthday_employee_column_exists($pdo, 'is_active')) {
        $where[] = 'is_active = 1';
    }

    try {
        $sql = "SELECT id, name, " . $positionSelect . ", birth_date FROM employees WHERE " . implode(' AND ', $where) . " ORDER BY name ASC, id ASC";
        $st = $pdo->query($sql);
        if (!$st) return array();
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $birthParts = cpms_dashboard_birthday_month_day(isset($row['birth_date']) ? $row['birth_date'] : '');
            $birthMonth = isset($birthParts[0]) ? (int)$birthParts[0] : 0;
            $birthDay = isset($birthParts[1]) ? (int)$birthParts[1] : 0;
            if ($birthMonth !== $todayMonth || $birthDay !== $todayDay) continue;
            $message = cpms_dashboard_birthday_message($row);
            if ($message === '') continue;
            $row['message'] = $message;
            $items[] = $row;
        }
    } catch (Exception $e) {
        return array();
    }

    return $items;
}}

if (!function_exists('cpms_render_dashboard_birthday_modal')) {
function cpms_render_dashboard_birthday_modal($pdo) {
    $today = cpms_dashboard_birthday_today();
    $birthdays = cpms_dashboard_birthday_today_employees($pdo, $today);
    if (count($birthdays) === 0) return;

    $signatureParts = array($today);
    foreach ($birthdays as $birthdayRow) {
        $signatureParts[] =
            (isset($birthdayRow['id']) ? (string)$birthdayRow['id'] : '') . ':' .
            (isset($birthdayRow['name']) ? (string)$birthdayRow['name'] : '') . ':' .
            (isset($birthdayRow['position']) ? (string)$birthdayRow['position'] : '') . ':' .
            (isset($birthdayRow['birth_date']) ? (string)$birthdayRow['birth_date'] : '');
    }
    $birthdaySignature = md5(implode('|', $signatureParts));
    $cakeSrc = function_exists('asset_url') ? asset_url('assets/img/birthday-cake.svg') : 'assets/img/birthday-cake.svg';
    $fireworkSrc = function_exists('asset_url') ? asset_url('assets/img/birthday-fireworks.svg') : 'assets/img/birthday-fireworks.svg';
    ?>
    <style>
      .cpms-birthday-modal-card{background:linear-gradient(135deg,#fff7ed 0%,#ffffff 48%,#ecfeff 100%)}
      .cpms-birthday-visual{display:flex;align-items:center;justify-content:center;gap:14px;margin:6px auto 18px}
      .cpms-birthday-img{display:block;max-width:100%;height:auto;filter:drop-shadow(0 16px 20px rgba(15,23,42,.18))}
      .cpms-birthday-cake{width:132px;animation:cpms-birthday-cake-pop 1.4s ease-in-out infinite}
      .cpms-birthday-firework{width:96px;animation:cpms-birthday-firework-pop 1.1s ease-in-out infinite}
      .cpms-birthday-firework.is-right{animation-delay:.18s}
      .cpms-birthday-message{word-break:keep-all;overflow-wrap:anywhere;letter-spacing:0}
      @keyframes cpms-birthday-cake-pop{0%,100%{transform:translateY(0) scale(1)}50%{transform:translateY(-5px) scale(1.035)}}
      @keyframes cpms-birthday-firework-pop{0%,100%{transform:scale(1);opacity:.92}50%{transform:scale(1.08);opacity:1}}
      @media (max-width:640px){
        .cpms-birthday-visual{gap:8px}
        .cpms-birthday-cake{width:108px}
        .cpms-birthday-firework{width:72px}
      }
      @media (prefers-reduced-motion:reduce){
        .cpms-birthday-cake,.cpms-birthday-firework{animation:none}
      }
    </style>
    <div id="modal-dashboardBirthdayAuto" class="fixed inset-0 hidden" style="z-index:60;" data-dashboard-birthday-auto="1">
        <div class="absolute inset-0 bg-slate-950/55" data-dashboard-birthday-auto-close></div>
        <div class="absolute inset-0 flex items-center justify-center p-4">
            <div class="cpms-birthday-modal-card relative w-full max-w-2xl max-h-[90vh] overflow-hidden rounded-3xl bg-white shadow-2xl border border-amber-100">
                <div class="p-5 md:p-7 text-center">
                    <div class="flex justify-end">
                        <button type="button" class="px-4 py-2 rounded-2xl border border-amber-200 bg-white/80 text-gray-700 font-extrabold hover:bg-white" data-dashboard-birthday-auto-close><?php echo h(cpms_dashboard_notice_label('close')); ?></button>
                    </div>
                    <div class="cpms-birthday-visual" aria-hidden="true">
                        <img class="cpms-birthday-img cpms-birthday-firework" src="<?php echo h($fireworkSrc); ?>" alt="<?php echo h(cpms_dashboard_birthday_text('%ED%8F%AD%EC%A3%BD%20%EC%9D%B4%EB%AF%B8%EC%A7%80')); ?>">
                        <img class="cpms-birthday-img cpms-birthday-cake" src="<?php echo h($cakeSrc); ?>" alt="<?php echo h(cpms_dashboard_birthday_text('%EC%BC%80%EC%9D%B4%ED%81%AC%20%EC%9D%B4%EB%AF%B8%EC%A7%80')); ?>">
                        <img class="cpms-birthday-img cpms-birthday-firework is-right" src="<?php echo h($fireworkSrc); ?>" alt="<?php echo h(cpms_dashboard_birthday_text('%ED%8F%AD%EC%A3%BD%20%EC%9D%B4%EB%AF%B8%EC%A7%80')); ?>">
                    </div>
                    <div class="inline-flex items-center justify-center px-3 py-1 rounded-full bg-amber-100 text-amber-800 text-sm font-extrabold border border-amber-200"><?php echo h(cpms_dashboard_birthday_text('%EC%98%A4%EB%8A%98%20%EC%83%9D%EC%9D%BC%EC%9E%90')); ?></div>
                    <?php if (count($birthdays) === 1): ?>
                        <div class="cpms-birthday-message mt-4 text-2xl md:text-4xl leading-tight font-black text-gray-950"><?php echo h(isset($birthdays[0]['message']) ? $birthdays[0]['message'] : ''); ?></div>
                    <?php else: ?>
                        <div class="mt-4 space-y-2">
                            <?php foreach ($birthdays as $birthday): ?>
                                <div class="cpms-birthday-message rounded-2xl border border-amber-100 bg-white/80 px-4 py-3 text-xl md:text-2xl leading-snug font-black text-gray-950"><?php echo h(isset($birthday['message']) ? $birthday['message'] : ''); ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <div class="mt-4 text-base md:text-lg font-extrabold text-slate-600"><?php echo h(cpms_dashboard_birthday_text('%ED%95%A8%EA%BB%98%20%EC%B6%95%ED%95%98%ED%95%B4%EC%A3%BC%EC%84%B8%EC%9A%94.')); ?></div>
                </div>
                <div class="px-6 py-4 border-t border-amber-100 bg-white/70 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <div class="text-xs text-gray-500"><?php echo h(cpms_dashboard_notice_label('today_hidden')); ?></div>
                    <button type="button" class="px-5 py-3 rounded-2xl bg-gray-900 text-white font-extrabold" data-dashboard-birthday-auto-close><?php echo h(cpms_dashboard_notice_label('close')); ?></button>
                </div>
            </div>
        </div>
    </div>
    <script>
    (function(){
        var birthdayModal = document.getElementById('modal-dashboardBirthdayAuto');
        var storageKey = 'cpms_dashboard_birthday_closed_until';
        var signatureKey = 'cpms_dashboard_birthday_signature';
        var birthdaySignature = <?php echo json_encode($birthdaySignature); ?>;
        function endOfTodayTime() {
            var now = new Date();
            return new Date(now.getFullYear(), now.getMonth(), now.getDate(), 23, 59, 59, 999).getTime();
        }
        function shouldShowBirthday() {
            try {
                var raw = window.localStorage ? localStorage.getItem(storageKey) : '';
                var storedSignature = window.localStorage ? (localStorage.getItem(signatureKey) || '') : '';
                var until = raw ? parseInt(raw, 10) : 0;
                if (birthdaySignature && storedSignature !== birthdaySignature) return true;
                if (until && until >= new Date().getTime()) return false;
            } catch (e) {}
            return true;
        }
        function noticeModalOpen() {
            var noticeModal = document.getElementById('modal-dashboardNoticeAuto');
            return !!(noticeModal && !noticeModal.classList.contains('hidden'));
        }
        function closeBirthday() {
            if (birthdayModal) birthdayModal.classList.add('hidden');
            try {
                if (window.localStorage) {
                    localStorage.setItem(storageKey, String(endOfTodayTime()));
                    localStorage.setItem(signatureKey, birthdaySignature || '');
                }
            } catch (e) {}
            if (!noticeModalOpen()) document.body.classList.remove('overflow-hidden');
        }
        function openBirthday() {
            if (!birthdayModal || !shouldShowBirthday()) return;
            birthdayModal.classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
        }
        var closeButtons = document.querySelectorAll('[data-dashboard-birthday-auto-close]');
        for (var i = 0; i < closeButtons.length; i++) {
            closeButtons[i].addEventListener('click', function(e){
                e.preventDefault();
                closeBirthday();
            });
        }
        document.addEventListener('keydown', function(e){
            if (e.key === 'Escape' && birthdayModal && !birthdayModal.classList.contains('hidden')) closeBirthday();
        });
        setTimeout(openBirthday, 120);
    })();
    </script>
    <?php
}}
?>
