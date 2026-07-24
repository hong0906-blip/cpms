<?php
/**
 * C:\www\cpms\app\services\ProgressStatementService.php
 * 기성내역서 제출·검토의 조회, 권한, 파일 버전, 댓글, 처리이력 공통 서비스.
 * PHP 5.6 compatible.
 */

use App\Core\Auth;

if (!function_exists('cpms_progress_statement_table_exists')) {
function cpms_progress_statement_table_exists($pdo, $tableName) {
    if (!$pdo || !preg_match('/^[a-z0-9_]+$/i', (string)$tableName)) return false;
    try {
        $st = $pdo->prepare("SHOW TABLES LIKE :table_name");
        $st->execute(array(':table_name' => (string)$tableName));
        return (bool)$st->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_progress_statement_schema_ready')) {
function cpms_progress_statement_schema_ready($pdo) {
    $tables = array(
        'cpms_progress_statements',
        'cpms_progress_statement_files',
        'cpms_progress_statement_comments',
        'cpms_progress_statement_histories'
    );
    foreach ($tables as $tableName) {
        if (!cpms_progress_statement_table_exists($pdo, $tableName)) return false;
    }
    return true;
}}

if (!function_exists('cpms_progress_statement_department')) {
function cpms_progress_statement_department($value) {
    $value = trim((string)$value);
    if (method_exists('App\\Core\\Auth', 'normalizeDepartmentValue')) {
        return (string)Auth::normalizeDepartmentValue($value);
    }
    if ($value === '공무부' || $value === '공무팀') return '공무';
    if ($value === '공사부' || $value === '공사팀') return '공사';
    if ($value === '관리부' || $value === '관리팀') return '관리';
    return $value;
}}

if (!function_exists('cpms_progress_statement_is_public_affairs')) {
function cpms_progress_statement_is_public_affairs() {
    return Auth::check() && cpms_progress_statement_department(Auth::userDepartment()) === '공무';
}}

if (!function_exists('cpms_progress_statement_is_executive')) {
function cpms_progress_statement_is_executive() {
    if (!Auth::check()) return false;
    if ((string)Auth::userRole() === 'executive') return true;
    $text = trim((string)Auth::userPosition()) . ' ' . trim((string)Auth::userStoredRole());
    foreach (array('대표', '대표이사', '부사장', '임원') as $word) {
        if (strpos($text, $word) !== false) return true;
    }
    return false;
}}

if (!function_exists('cpms_progress_statement_actor')) {
function cpms_progress_statement_actor($pdo) {
    $authUser = Auth::user();
    $actor = array(
        'id' => 0,
        'name' => (string)Auth::userName(),
        'email' => (string)Auth::userEmail(),
        'department' => (string)Auth::userDepartment(),
        'position' => (string)Auth::userPosition(),
        'role' => (string)Auth::userRole(),
        'photo_path' => is_array($authUser) && isset($authUser['photo_path']) ? (string)$authUser['photo_path'] : ''
    );
    if (!$pdo || trim($actor['email']) === '') return $actor;
    try {
        $st = $pdo->prepare("SELECT id, name, email, department, position, role, photo_path FROM employees WHERE LOWER(TRIM(email)) = LOWER(TRIM(:email)) LIMIT 1");
        $st->execute(array(':email' => $actor['email']));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            foreach ($actor as $key => $value) {
                if (isset($row[$key])) $actor[$key] = $row[$key];
            }
            $actor['id'] = isset($row['id']) ? (int)$row['id'] : 0;
        }
    } catch (Exception $e) {
    }
    return $actor;
}}

if (!function_exists('cpms_progress_statement_project_member')) {
function cpms_progress_statement_project_member($pdo, $projectId, $employeeId) {
    if (!$pdo || (int)$projectId <= 0 || (int)$employeeId <= 0) return false;
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM cpms_project_members WHERE project_id = :project_id AND employee_id = :employee_id AND LOWER(TRIM(role)) IN ('main','sub')");
        $st->execute(array(':project_id' => (int)$projectId, ':employee_id' => (int)$employeeId));
        return ((int)$st->fetchColumn() > 0);
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_progress_statement_project_exists')) {
function cpms_progress_statement_project_exists($pdo, $projectId) {
    if (!$pdo || (int)$projectId <= 0) return false;
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM cpms_projects WHERE id = :id");
        $st->execute(array(':id' => (int)$projectId));
        return ((int)$st->fetchColumn() > 0);
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_progress_statement_can_submit')) {
function cpms_progress_statement_can_submit($pdo, $projectId, $actor) {
    if (!Auth::check() || !is_array($actor)) return false;
    // 개발 부서는 운영 전 기능 검증을 위해 프로젝트 담당자 배정 없이 제출·재제출할 수 있다.
    if (Auth::isDevelopmentDepartment()) return true;
    return cpms_progress_statement_project_member($pdo, $projectId, isset($actor['id']) ? (int)$actor['id'] : 0);
}}

if (!function_exists('cpms_progress_statement_can_review')) {
function cpms_progress_statement_can_review() {
    return Auth::check() && (Auth::isMaster() || cpms_progress_statement_is_public_affairs());
}}

if (!function_exists('cpms_progress_statement_can_view_project')) {
function cpms_progress_statement_can_view_project($pdo, $projectId, $actor) {
    if (!Auth::check()) return false;
    if (Auth::isMaster() || cpms_progress_statement_is_public_affairs() || cpms_progress_statement_is_executive()) return true;
    return cpms_progress_statement_project_member($pdo, $projectId, isset($actor['id']) ? (int)$actor['id'] : 0);
}}

if (!function_exists('cpms_progress_statement_can_comment')) {
function cpms_progress_statement_can_comment($pdo, $projectId, $actor) {
    return cpms_progress_statement_can_view_project($pdo, $projectId, $actor);
}}

if (!function_exists('cpms_progress_statement_find')) {
function cpms_progress_statement_find($pdo, $statementId, $forUpdate) {
    if (!$pdo || (int)$statementId <= 0 || !cpms_progress_statement_schema_ready($pdo)) return null;
    try {
        $sql = "SELECT s.*, p.name AS project_name,
                       f.original_file_name AS current_original_file_name,
                       f.server_file_name AS current_server_file_name,
                       f.server_file_path AS current_server_file_path,
                       f.file_size AS current_file_size,
                       f.mime_type AS current_mime_type,
                       f.version_no AS current_version_no
                  FROM cpms_progress_statements s
                  JOIN cpms_projects p ON p.id = s.project_id
                  LEFT JOIN cpms_progress_statement_files f ON f.id = s.latest_file_id
                 WHERE s.id = :id LIMIT 1";
        if ($forUpdate) $sql .= " FOR UPDATE";
        $st = $pdo->prepare($sql);
        $st->execute(array(':id' => (int)$statementId));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    } catch (Exception $e) {
        return null;
    }
}}

if (!function_exists('cpms_progress_statement_status_label')) {
function cpms_progress_statement_status_label($status) {
    $map = array('pending' => '검토대기', 'resubmitted' => '재검토대기', 'approved' => '승인완료', 'rejected' => '반려');
    return isset($map[$status]) ? $map[$status] : (string)$status;
}}

if (!function_exists('cpms_progress_statement_status_class')) {
function cpms_progress_statement_status_class($status) {
    $map = array(
        'pending' => 'bg-blue-100 text-blue-800 border-blue-200',
        'resubmitted' => 'bg-orange-100 text-orange-800 border-orange-200',
        'approved' => 'bg-emerald-100 text-emerald-800 border-emerald-200',
        'rejected' => 'bg-red-100 text-red-800 border-red-200'
    );
    return isset($map[$status]) ? $map[$status] : 'bg-gray-100 text-gray-700 border-gray-200';
}}

if (!function_exists('cpms_progress_statement_event_label')) {
function cpms_progress_statement_event_label($eventType) {
    $map = array(
        'submitted' => '최초 제출', 'resubmitted' => '재제출', 'approved' => '승인',
        'rejected' => '반려', 'commented' => '댓글 작성',
        'drive_upload_success' => 'Drive 업로드 성공', 'drive_upload_failed' => 'Drive 업로드 실패',
        'drive_retry_success' => 'Drive 재업로드 성공', 'drive_retry_failed' => 'Drive 재업로드 실패'
    );
    return isset($map[$eventType]) ? $map[$eventType] : (string)$eventType;
}}

if (!function_exists('cpms_progress_statement_add_history')) {
function cpms_progress_statement_add_history($pdo, $statementId, $eventType, $oldStatus, $newStatus, $actor, $description) {
    $st = $pdo->prepare("INSERT INTO cpms_progress_statement_histories
        (statement_id, event_type, old_status, new_status, actor_employee_id, actor_name, actor_email, description, created_at)
        VALUES (:statement_id, :event_type, :old_status, :new_status, :actor_employee_id, :actor_name, :actor_email, :description, :created_at)");
    $st->execute(array(
        ':statement_id' => (int)$statementId,
        ':event_type' => (string)$eventType,
        ':old_status' => (string)$oldStatus,
        ':new_status' => (string)$newStatus,
        ':actor_employee_id' => isset($actor['id']) && (int)$actor['id'] > 0 ? (int)$actor['id'] : null,
        ':actor_name' => isset($actor['name']) ? (string)$actor['name'] : '',
        ':actor_email' => isset($actor['email']) ? (string)$actor['email'] : '',
        ':description' => (string)$description,
        ':created_at' => date('Y-m-d H:i:s')
    ));
}}

if (!function_exists('cpms_progress_statement_detect_mime')) {
function cpms_progress_statement_detect_mime($path) {
    $mime = '';
    if (function_exists('finfo_open')) {
        $fi = @finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) {
            $mime = (string)@finfo_file($fi, $path);
            @finfo_close($fi);
        }
    }
    if ($mime === '' && function_exists('mime_content_type')) $mime = (string)@mime_content_type($path);
    return strtolower(trim($mime));
}}

if (!function_exists('cpms_progress_statement_store_upload')) {
function cpms_progress_statement_store_upload($projectId, $fieldName) {
    if (!isset($_FILES[$fieldName]) || !is_array($_FILES[$fieldName])) throw new Exception('기성내역서 엑셀파일을 첨부해주세요.');
    $file = $_FILES[$fieldName];
    $error = isset($file['error']) ? (int)$file['error'] : UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_NO_FILE) throw new Exception('기성내역서 엑셀파일을 첨부해주세요.');
    if ($error !== UPLOAD_ERR_OK) throw new Exception('파일 업로드 오류가 발생했습니다. (코드 ' . $error . ')');
    $original = isset($file['name']) ? basename(str_replace('\\', '/', (string)$file['name'])) : '';
    $tmp = isset($file['tmp_name']) ? (string)$file['tmp_name'] : '';
    $size = isset($file['size']) ? (int)$file['size'] : 0;
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if ($original === '' || !in_array($ext, array('xls', 'xlsx'), true)) throw new Exception('xls 또는 xlsx 파일만 제출할 수 있습니다.');
    if ($size <= 0 || $size > (30 * 1024 * 1024)) throw new Exception('파일은 30MB 이하만 제출할 수 있습니다.');
    if ($tmp === '' || !is_uploaded_file($tmp)) throw new Exception('정상적인 업로드 파일이 아닙니다.');
    $mime = cpms_progress_statement_detect_mime($tmp);
    $allowedMimes = array(
        'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/zip', 'application/octet-stream', 'application/x-ole-storage',
        'application/cdfv2', 'application/vnd.ms-office'
    );
    if ($mime === '' || !in_array($mime, $allowedMimes, true)) throw new Exception('파일 내용이 Excel 형식으로 확인되지 않습니다. (MIME: ' . $mime . ')');
    $root = function_exists('cpms_storage_root') ? cpms_storage_root() : dirname(dirname(__DIR__)) . '/storage';
    $dir = rtrim($root, '/\\') . '/progress_statements/' . (int)$projectId . '/' . date('Y');
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) throw new Exception('업로드 폴더를 만들 수 없습니다.');
    $stored = 'ps_' . (int)$projectId . '_' . date('Ymd_His') . '_' . substr(sha1(uniqid('', true)), 0, 16) . '.' . $ext;
    $path = $dir . '/' . $stored;
    if (!@move_uploaded_file($tmp, $path)) throw new Exception('업로드 파일 저장에 실패했습니다.');
    return array('original' => $original, 'stored' => $stored, 'path' => $path, 'size' => $size, 'mime' => $mime);
}}

if (!function_exists('cpms_progress_statement_remove_uncommitted_file')) {
function cpms_progress_statement_remove_uncommitted_file($fileInfo) {
    if (is_array($fileInfo) && isset($fileInfo['path']) && is_file($fileInfo['path'])) @unlink($fileInfo['path']);
}}

if (!function_exists('cpms_progress_statement_insert_file')) {
function cpms_progress_statement_insert_file($pdo, $statementId, $versionNo, $fileInfo, $actor, $submissionType) {
    $st = $pdo->prepare("INSERT INTO cpms_progress_statement_files
        (statement_id, version_no, original_file_name, server_file_name, server_file_path, file_size, mime_type,
         uploaded_by, uploaded_by_name, uploaded_by_email, submission_type, uploaded_at)
        VALUES (:statement_id, :version_no, :original_file_name, :server_file_name, :server_file_path, :file_size, :mime_type,
         :uploaded_by, :uploaded_by_name, :uploaded_by_email, :submission_type, :uploaded_at)");
    $st->execute(array(
        ':statement_id' => (int)$statementId, ':version_no' => (int)$versionNo,
        ':original_file_name' => (string)$fileInfo['original'], ':server_file_name' => (string)$fileInfo['stored'],
        ':server_file_path' => (string)$fileInfo['path'], ':file_size' => (int)$fileInfo['size'], ':mime_type' => (string)$fileInfo['mime'],
        ':uploaded_by' => isset($actor['id']) && (int)$actor['id'] > 0 ? (int)$actor['id'] : null,
        ':uploaded_by_name' => isset($actor['name']) ? (string)$actor['name'] : '',
        ':uploaded_by_email' => isset($actor['email']) ? (string)$actor['email'] : '',
        ':submission_type' => (string)$submissionType, ':uploaded_at' => date('Y-m-d H:i:s')
    ));
    return (int)$pdo->lastInsertId();
}}

if (!function_exists('cpms_progress_statement_files')) {
function cpms_progress_statement_files($pdo, $statementId) {
    if (!$pdo || !cpms_progress_statement_schema_ready($pdo)) return array();
    try {
        $st = $pdo->prepare("SELECT * FROM cpms_progress_statement_files WHERE statement_id = :id ORDER BY version_no DESC, id DESC");
        $st->execute(array(':id' => (int)$statementId));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : array();
    } catch (Exception $e) { return array(); }
}}

if (!function_exists('cpms_progress_statement_comments')) {
function cpms_progress_statement_comments($pdo, $statementId) {
    if (!$pdo || !cpms_progress_statement_schema_ready($pdo)) return array();
    try {
        $st = $pdo->prepare("SELECT c.*,
                    COALESCE(NULLIF(e_id.photo_path, ''), NULLIF(e_email.photo_path, ''), NULLIF(c.author_photo_path, '')) AS display_photo_path
                FROM cpms_progress_statement_comments c
                LEFT JOIN employees e_id ON e_id.id = c.author_employee_id
                LEFT JOIN employees e_email
                    ON e_id.id IS NULL
                   AND TRIM(e_email.email) = TRIM(c.author_email)
                WHERE c.statement_id = :id
                ORDER BY c.parent_comment_id ASC, c.created_at ASC, c.id ASC");
        $st->execute(array(':id' => (int)$statementId));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : array();
    } catch (Exception $e) { return array(); }
}}

if (!function_exists('cpms_progress_statement_histories')) {
function cpms_progress_statement_histories($pdo, $statementId) {
    if (!$pdo || !cpms_progress_statement_schema_ready($pdo)) return array();
    try {
        $st = $pdo->prepare("SELECT h.*,
                    COALESCE(NULLIF(e_id.photo_path, ''), NULLIF(e_email.photo_path, '')) AS actor_photo_path
                FROM cpms_progress_statement_histories h
                LEFT JOIN employees e_id ON e_id.id = h.actor_employee_id
                LEFT JOIN employees e_email
                    ON e_id.id IS NULL
                   AND TRIM(e_email.email) = TRIM(h.actor_email)
                WHERE h.statement_id = :id
                ORDER BY h.created_at ASC, h.id ASC");
        $st->execute(array(':id' => (int)$statementId));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : array();
    } catch (Exception $e) { return array(); }
}}

if (!function_exists('cpms_progress_statement_related_maps')) {
function cpms_progress_statement_related_maps($pdo, $statementIds) {
    $result = array('files' => array(), 'comments' => array(), 'histories' => array());
    if (!$pdo || !is_array($statementIds) || count($statementIds) === 0 || !cpms_progress_statement_schema_ready($pdo)) return $result;
    $ids = array();
    foreach ($statementIds as $statementId) {
        $statementId = (int)$statementId;
        if ($statementId > 0) $ids[$statementId] = $statementId;
    }
    if (count($ids) === 0) return $result;
    $in = implode(',', array_map('intval', array_values($ids)));
    $queries = array(
        'files' => "SELECT * FROM cpms_progress_statement_files WHERE statement_id IN (" . $in . ") ORDER BY statement_id ASC, version_no DESC, id DESC",
        'comments' => "SELECT c.*,
                COALESCE(NULLIF(e_id.photo_path, ''), NULLIF(e_email.photo_path, ''), NULLIF(c.author_photo_path, '')) AS display_photo_path
            FROM cpms_progress_statement_comments c
            LEFT JOIN employees e_id ON e_id.id = c.author_employee_id
            LEFT JOIN employees e_email
                ON e_id.id IS NULL
               AND TRIM(e_email.email) = TRIM(c.author_email)
            WHERE c.statement_id IN (" . $in . ")
            ORDER BY c.statement_id ASC, c.parent_comment_id ASC, c.created_at ASC, c.id ASC",
        'histories' => "SELECT h.*,
                COALESCE(NULLIF(e_id.photo_path, ''), NULLIF(e_email.photo_path, '')) AS actor_photo_path
            FROM cpms_progress_statement_histories h
            LEFT JOIN employees e_id ON e_id.id = h.actor_employee_id
            LEFT JOIN employees e_email
                ON e_id.id IS NULL
               AND TRIM(e_email.email) = TRIM(h.actor_email)
            WHERE h.statement_id IN (" . $in . ")
            ORDER BY h.statement_id ASC, h.created_at ASC, h.id ASC"
    );
    foreach ($queries as $mapKey => $sql) {
        try {
            $st = $pdo->query($sql);
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $statementId = isset($row['statement_id']) ? (int)$row['statement_id'] : 0;
                if ($statementId <= 0) continue;
                if (!isset($result[$mapKey][$statementId])) $result[$mapKey][$statementId] = array();
                $result[$mapKey][$statementId][] = $row;
            }
        } catch (Exception $e) {
            $result[$mapKey] = array();
        }
    }
    return $result;
}}

if (!function_exists('cpms_progress_statement_photo_url')) {
function cpms_progress_statement_photo_url($path) {
    $path = trim((string)$path);
    if ($path === '') return '';
    if (preg_match('/^https?:\/\//i', $path)) return $path;
    $path = str_replace('\\', '/', $path);
    if (strpos($path, '/') === 0) return $path;
    return (function_exists('base_url') ? base_url() : '') . '/' . ltrim($path, '/');
}}

if (!function_exists('cpms_progress_statement_comment_initial')) {
function cpms_progress_statement_comment_initial($name) {
    $name = trim((string)$name);
    if ($name === '') return '?';
    return function_exists('mb_substr') ? mb_substr($name, 0, 1, 'UTF-8') : substr($name, 0, 1);
}}

if (!function_exists('cpms_progress_statement_render_comment_item')) {
function cpms_progress_statement_render_comment_item($comment, $childrenMap, $statementId, $returnUrl, $depth) {
    $commentId = isset($comment['id']) ? (int)$comment['id'] : 0;
    $name = isset($comment['author_name']) && trim((string)$comment['author_name']) !== '' ? (string)$comment['author_name'] : '작성자';
    $photoPath = isset($comment['display_photo_path']) && trim((string)$comment['display_photo_path']) !== ''
        ? $comment['display_photo_path']
        : (isset($comment['author_photo_path']) ? $comment['author_photo_path'] : '');
    $photoUrl = cpms_progress_statement_photo_url($photoPath);
    $indentClass = ((int)$depth > 0) ? 'ml-6 md:ml-10 border-l-4 border-slate-100 pl-3 md:pl-4' : '';
    ?>
    <div class="<?php echo h($indentClass); ?>">
        <div class="rounded-2xl border border-gray-200 bg-white p-3">
            <div class="flex items-start gap-3">
                <?php if ($photoUrl !== ''): ?>
                    <img src="<?php echo h($photoUrl); ?>" alt="<?php echo h($name); ?>" class="w-10 h-10 rounded-full object-cover border border-gray-200 shrink-0">
                <?php else: ?>
                    <div class="w-10 h-10 rounded-full bg-slate-100 border border-slate-200 flex items-center justify-center text-sm font-extrabold text-slate-600 shrink-0"><?php echo h(cpms_progress_statement_comment_initial($name)); ?></div>
                <?php endif; ?>
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2"><b><?php echo h($name); ?></b><span class="text-xs text-gray-500"><?php echo h(isset($comment['created_at']) ? $comment['created_at'] : ''); ?></span></div>
                    <div class="mt-2 text-sm text-gray-800 whitespace-pre-line break-words"><?php echo h(isset($comment['comment_text']) ? $comment['comment_text'] : ''); ?></div>
                    <details class="mt-2">
                        <summary class="cursor-pointer text-xs font-bold text-blue-700">답글 쓰기</summary>
                        <form method="post" action="?r=project/progress_statement_comment_save" class="mt-2 flex flex-col md:flex-row gap-2" data-cpms-progress-comment-form>
                            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                            <input type="hidden" name="statement_id" value="<?php echo (int)$statementId; ?>">
                            <input type="hidden" name="parent_comment_id" value="<?php echo (int)$commentId; ?>">
                            <input type="hidden" name="return_url" value="<?php echo h($returnUrl); ?>">
                            <textarea name="comment_text" required maxlength="2000" rows="2" class="flex-1 px-3 py-2 rounded-xl border" placeholder="대댓글을 입력하세요."></textarea>
                            <button class="px-4 py-2 rounded-xl bg-gray-900 text-white font-extrabold">대댓글 등록</button>
                        </form>
                    </details>
                </div>
            </div>
        </div>
        <?php if (isset($childrenMap[$commentId]) && is_array($childrenMap[$commentId])): ?>
            <div class="mt-2 space-y-2">
                <?php foreach ($childrenMap[$commentId] as $childComment): ?>
                    <?php cpms_progress_statement_render_comment_item($childComment, $childrenMap, $statementId, $returnUrl, ((int)$depth + 1)); ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
}}

if (!function_exists('cpms_progress_statement_render_comments')) {
function cpms_progress_statement_render_comments($comments, $statementId, $returnUrl) {
    $childrenMap = array();
    $root = array();
    if (!is_array($comments)) $comments = array();
    foreach ($comments as $comment) {
        $parentId = isset($comment['parent_comment_id']) ? (int)$comment['parent_comment_id'] : 0;
        if ($parentId > 0) {
            if (!isset($childrenMap[$parentId])) $childrenMap[$parentId] = array();
            $childrenMap[$parentId][] = $comment;
        } else {
            $root[] = $comment;
        }
    }
    if (count($root) === 0) {
        echo '<div class="rounded-xl border border-dashed border-gray-300 p-3 text-sm text-gray-500">등록된 댓글이 없습니다.</div>';
        return;
    }
    echo '<div class="space-y-3">';
    foreach ($root as $comment) cpms_progress_statement_render_comment_item($comment, $childrenMap, $statementId, $returnUrl, 0);
    echo '</div>';
}}

if (!function_exists('cpms_progress_statement_render_histories')) {
function cpms_progress_statement_render_histories($histories) {
    if (!is_array($histories) || count($histories) === 0) {
        echo '<div class="mt-2 rounded-xl border border-dashed border-gray-300 p-3 text-sm text-gray-500">등록된 처리이력이 없습니다.</div>';
        return;
    }
    echo '<div class="mt-2 space-y-3">';
    foreach ($histories as $history) {
        $actorName = isset($history['actor_name']) && trim((string)$history['actor_name']) !== ''
            ? (string)$history['actor_name']
            : '처리자';
        $photoUrl = cpms_progress_statement_photo_url(isset($history['actor_photo_path']) ? $history['actor_photo_path'] : '');
        ?>
        <div class="flex items-start gap-3 rounded-2xl border border-gray-200 bg-white p-3">
            <?php if ($photoUrl !== ''): ?>
                <img src="<?php echo h($photoUrl); ?>" alt="<?php echo h($actorName); ?>" class="w-10 h-10 rounded-full object-cover border border-gray-200 shrink-0">
            <?php else: ?>
                <div class="w-10 h-10 rounded-full bg-slate-100 border border-slate-200 flex items-center justify-center text-sm font-extrabold text-slate-600 shrink-0"><?php echo h(cpms_progress_statement_comment_initial($actorName)); ?></div>
            <?php endif; ?>
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <b class="text-gray-900"><?php echo h(cpms_progress_statement_event_label(isset($history['event_type']) ? $history['event_type'] : '')); ?></b>
                    <span class="font-bold text-gray-700"><?php echo h($actorName); ?></span>
                    <span class="text-xs text-gray-500"><?php echo h(isset($history['created_at']) ? $history['created_at'] : ''); ?></span>
                </div>
                <?php if (isset($history['description']) && trim((string)$history['description']) !== ''): ?>
                    <div class="mt-1 text-sm text-gray-600 whitespace-pre-line break-words"><?php echo h($history['description']); ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
    echo '</div>';
}}

if (!function_exists('cpms_progress_statement_flush_redirect')) {
function cpms_progress_statement_flush_redirect($url) {
    $url = cpms_progress_statement_safe_return($url, '?r=대시보드');
    if (headers_sent()) return false;
    ignore_user_abort(true);
    header('Location: ' . $url, true, 303);
    header('Content-Length: 0');
    header('Connection: close');
    if (session_id() !== '') @session_write_close();
    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
        return true;
    }
    while (ob_get_level() > 0) @ob_end_flush();
    @flush();
    return true;
}}

if (!function_exists('cpms_progress_statement_safe_return')) {
function cpms_progress_statement_safe_return($value, $fallback) {
    $value = trim((string)$value);
    if ($value === '' || strpos($value, '?r=') !== 0 || preg_match('/[\r\n]/', $value)) return $fallback;
    return $value;
}}
