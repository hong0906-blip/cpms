<?php
use App\Core\Db;

require_once __DIR__ . '/_common.php';

$pdo = Db::pdo();
$u = \App\Core\Auth::user();
$uid = approval_current_employee_id($pdo, $u);
$userEmail = approval_current_user_email($u);
$userName = approval_current_user_name($u);
$isAdmin = approval_is_admin_user($u);

$view = isset($_GET['view']) ? trim((string)$_GET['view']) : 'active';
if (isset($_GET['show_cancelled']) && (string)$_GET['show_cancelled'] === '1') {
    $view = 'cancelled';
}
if (!in_array($view, array('active', 'cancelled', 'completed'), true)) {
    $view = 'active';
}

$docTypeFilter = isset($_GET['doc_type']) ? trim((string)$_GET['doc_type']) : '';
$titleFilter = isset($_GET['title']) ? trim((string)$_GET['title']) : '';
$authorFilter = isset($_GET['author']) ? trim((string)$_GET['author']) : '';
$dateFromFilter = isset($_GET['date_from']) ? trim((string)$_GET['date_from']) : '';
$dateToFilter = isset($_GET['date_to']) ? trim((string)$_GET['date_to']) : '';
$queryFilter = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

$rows = array();
$countCards = array();
$docHasCreatedByEmail = ($pdo && approval_table_column_exists($pdo, 'cpms_approval_documents', 'created_by_email'));

if ($pdo) {
    $params = array();
    $where = array();

    if ($view === 'cancelled') {
        $where[] = "UPPER(COALESCE(d.doc_status, '')) = 'CANCELLED'";
    } else if ($view === 'completed') {
        $where[] = "UPPER(COALESCE(d.doc_status, '')) IN ('APPROVED', 'COMPLETED')";
    } else {
        $where[] = "UPPER(COALESCE(d.doc_status, '')) NOT IN ('CANCELLED', 'APPROVED', 'COMPLETED')";
    }

    if (!$isAdmin) {
        $ownerParts = array();
        $lineParts = array();

        if ($uid > 0) {
            $ownerParts[] = "d.created_by_id = :uid";
            $lineParts[] = "x.approver_id = :uid";
            $params[':uid'] = $uid;
        }
        if ($userName !== '') {
            $ownerParts[] = "d.created_by_name = :uname";
            $lineParts[] = "x.approver_name = :uname";
            $params[':uname'] = $userName;
        }
        if ($userEmail !== '') {
            $lineParts[] = "LOWER(TRIM(x.approver_email)) = LOWER(TRIM(:email))";
            $params[':email'] = $userEmail;
            if ($docHasCreatedByEmail) {
                $ownerParts[] = "LOWER(TRIM(d.created_by_email)) = LOWER(TRIM(:owner_email))";
                $params[':owner_email'] = $userEmail;
            }
        }

        $relatedParts = array();
        if (count($ownerParts) > 0) {
            $relatedParts[] = '(' . implode(' OR ', $ownerParts) . ')';
        }
        if (count($lineParts) > 0) {
            $relatedParts[] = "EXISTS (SELECT 1 FROM cpms_approval_lines x WHERE x.document_id = d.id AND (" . implode(' OR ', $lineParts) . "))";
        }

        if (count($relatedParts) > 0) {
            $where[] = '(' . implode(' OR ', $relatedParts) . ')';
        } else {
            $where[] = '1 = 0';
        }
    }

    if ($view === 'completed') {
        if ($docTypeFilter !== '') {
            $where[] = "d.doc_type = :doc_type";
            $params[':doc_type'] = $docTypeFilter;
        }
        if ($titleFilter !== '') {
            $where[] = "d.title LIKE :title";
            $params[':title'] = '%' . $titleFilter . '%';
        }
        if ($authorFilter !== '') {
            $where[] = "d.created_by_name LIKE :author";
            $params[':author'] = '%' . $authorFilter . '%';
        }
        if ($dateFromFilter !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFromFilter)) {
            $where[] = "DATE(d.created_at) >= :date_from";
            $params[':date_from'] = $dateFromFilter;
        }
        if ($dateToFilter !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateToFilter)) {
            $where[] = "DATE(d.created_at) <= :date_to";
            $params[':date_to'] = $dateToFilter;
        }
        if ($queryFilter !== '') {
            $where[] = "(d.title LIKE :q OR d.created_by_name LIKE :q OR d.doc_type LIKE :q)";
            $params[':q'] = '%' . $queryFilter . '%';
        }
    }

    $myLineSelect = "NULL";
    $myLineWhere = array();
    if ($uid > 0) {
        $myLineWhere[] = "my.approver_id = :uid";
    }
    if ($userEmail !== '') {
        $myLineWhere[] = "LOWER(TRIM(my.approver_email)) = LOWER(TRIM(:email))";
    }
    if ($userName !== '') {
        $myLineWhere[] = "my.approver_name = :uname";
    }
    if (count($myLineWhere) > 0) {
        $myLineSelect = "(SELECT my.line_status FROM cpms_approval_lines my WHERE my.document_id = d.id AND (" . implode(' OR ', $myLineWhere) . ") ORDER BY my.line_order ASC LIMIT 1)";
    }

    $sql = "SELECT d.*,
                   " . $myLineSelect . " AS my_line_status,
                   (SELECT cur.role_type FROM cpms_approval_lines cur WHERE cur.document_id = d.id AND cur.line_status = 'PENDING' ORDER BY cur.line_order ASC LIMIT 1) AS current_role
              FROM cpms_approval_documents d";
    if (count($where) > 0) {
        $sql .= " WHERE " . implode(' AND ', $where);
    }
    $sql .= " ORDER BY d.updated_at DESC, d.id DESC LIMIT 300";

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) { $rows = array(); }
}

$countCards = array();
if ($view === 'cancelled') {
    $mineCancelled = 0;
    for ($i = 0; $i < count($rows); $i++) {
        if (approval_is_document_owner($pdo, $rows[$i], $u)) {
            $mineCancelled++;
        }
    }
    $countCards = array(
        array('label' => '취소문서', 'count' => count($rows)),
        array('label' => '내가 취소한 문서', 'count' => $mineCancelled)
    );
} else if ($view === 'completed') {
    $mineCompleted = 0;
    $approvedByMe = 0;
    for ($i = 0; $i < count($rows); $i++) {
        $row = $rows[$i];
        if (approval_is_document_owner($pdo, $row, $u)) {
            $mineCompleted++;
        }
        if (isset($row['my_line_status']) && in_array(strtoupper(trim((string)$row['my_line_status'])), array('APPROVED', 'REJECTED', 'PENDING', 'WAITING', 'SKIPPED'), true)) {
            $approvedByMe++;
        }
    }
    $countCards = array(
        array('label' => '완료문서', 'count' => count($rows)),
        array('label' => '내가 작성한 완료문서', 'count' => $mineCompleted),
        array('label' => '내가 결재한 완료문서', 'count' => $approvedByMe)
    );
} else {
    $recv = 0;
    $mine = 0;
    $prog = 0;
    $rej = 0;
    for ($i = 0; $i < count($rows); $i++) {
        $row = $rows[$i];
        if (approval_is_document_owner($pdo, $row, $u)) { $mine++; }
        if (isset($row['my_line_status']) && $row['my_line_status'] !== null && trim((string)$row['my_line_status']) !== '') { $recv++; }
        $status = strtoupper(trim((string)(isset($row['doc_status']) ? $row['doc_status'] : '')));
        if ($status === 'PENDING' || $status === 'DRAFT') { $prog++; }
        if ($status === 'REJECTED') { $rej++; }
    }
    $countCards = array(
        array('label' => '받은 결재', 'count' => $recv),
        array('label' => '나의 요청', 'count' => $mine),
        array('label' => '진행중', 'count' => $prog),
        array('label' => '반려', 'count' => $rej)
    );
}

$canDb = \App\Core\Auth::isMaster() || \App\Core\Auth::canManageEmployees() || \App\Core\Auth::userRole() === 'executive';
$pageTitle = approval_document_title_by_view($view);
$emptyMessage = approval_document_empty_message($view);

function approval_tab_class($currentView, $targetView)
{
    if ($currentView === $targetView) {
        return 'bg-white text-indigo-700 font-extrabold';
    }
    return 'bg-white/15 text-indigo-50';
}
?>
<div class="space-y-5">
    <div class="bg-gradient-to-r from-indigo-600 to-cyan-500 rounded-3xl p-7 text-white shadow-xl">
        <div class="flex flex-col xl:flex-row xl:items-start xl:justify-between gap-6">
            <div class="min-w-0">
                <h2 class="text-3xl font-extrabold"><?php echo h($pageTitle); ?></h2>
                <p class="mt-2 text-indigo-100">
                    <?php if ($view === 'cancelled') { ?>
                        취소된 전자결재 문서를 확인하고 필요한 경우 삭제할 수 있습니다.
                    <?php } else if ($view === 'completed') { ?>
                        완료된 전자결재 문서를 종류별, 제목별, 작성자별, 작성일자별로 검색할 수 있습니다.
                    <?php } else { ?>
                        기안서와 휴가계를 작성하고 결재 진행상태를 확인합니다.
                    <?php } ?>
                </p>
                <?php if ($canDb) { ?>
                    <p class="mt-2 text-indigo-100 text-sm">전자결재 DB 설치/확인은 문서, 결재라인, 첨부, 알림 테이블을 생성합니다.</p>
                <?php } ?>
            </div>
            <div class="flex flex-wrap items-center justify-start xl:justify-end gap-3 shrink-0 max-w-none">
                <?php if ($canDb) { ?>
                    <a class="inline-flex items-center justify-center whitespace-nowrap shrink-0 min-w-max px-4 py-2 rounded-xl bg-amber-200 text-amber-950" href="?r=db_setup_approval">전자결재 DB 설치/확인</a>
                <?php } ?>
                <a class="inline-flex items-center justify-center whitespace-nowrap shrink-0 min-w-max px-4 py-2 rounded-xl bg-white text-indigo-700" href="?r=approval_create&type=proposal">기안서 작성</a>
                <a class="inline-flex items-center justify-center whitespace-nowrap shrink-0 min-w-max px-4 py-2 rounded-xl bg-white text-cyan-700" href="?r=approval_create&type=leave">휴가계 작성</a>
            </div>
        </div>

        <div class="flex flex-wrap gap-2 mt-5">
            <a href="?r=approval_home&view=active" class="inline-flex items-center justify-center whitespace-nowrap shrink-0 min-w-max px-4 py-2 rounded-xl <?php echo approval_tab_class($view, 'active'); ?>">진행문서 보기</a>
            <a href="?r=approval_home&view=cancelled" class="inline-flex items-center justify-center whitespace-nowrap shrink-0 min-w-max px-4 py-2 rounded-xl <?php echo approval_tab_class($view, 'cancelled'); ?>">취소문서 보기</a>
            <a href="?r=approval_home&view=completed" class="inline-flex items-center justify-center whitespace-nowrap shrink-0 min-w-max px-4 py-2 rounded-xl <?php echo approval_tab_class($view, 'completed'); ?>">완료된 문서 보기</a>
        </div>
    </div>

    <?php if ($view === 'completed') { ?>
        <form method="get" action="" class="bg-white rounded-3xl border p-5">
            <input type="hidden" name="r" value="approval_home">
            <input type="hidden" name="view" value="completed">
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-6 gap-3">
                <div>
                    <div class="text-sm font-bold text-gray-700 mb-1">문서 종류</div>
                    <select name="doc_type" class="w-full border rounded-xl px-3 py-2">
                        <option value="">전체</option>
                        <option value="proposal" <?php echo ($docTypeFilter === 'proposal') ? 'selected' : ''; ?>>기안서</option>
                        <option value="leave" <?php echo ($docTypeFilter === 'leave') ? 'selected' : ''; ?>>휴가계</option>
                    </select>
                </div>
                <div>
                    <div class="text-sm font-bold text-gray-700 mb-1">제목 검색</div>
                    <input type="text" name="title" value="<?php echo h($titleFilter); ?>" class="w-full border rounded-xl px-3 py-2">
                </div>
                <div>
                    <div class="text-sm font-bold text-gray-700 mb-1">작성자 검색</div>
                    <input type="text" name="author" value="<?php echo h($authorFilter); ?>" class="w-full border rounded-xl px-3 py-2">
                </div>
                <div>
                    <div class="text-sm font-bold text-gray-700 mb-1">작성일자 시작</div>
                    <input type="date" name="date_from" value="<?php echo h($dateFromFilter); ?>" class="w-full border rounded-xl px-3 py-2">
                </div>
                <div>
                    <div class="text-sm font-bold text-gray-700 mb-1">작성일자 종료</div>
                    <input type="date" name="date_to" value="<?php echo h($dateToFilter); ?>" class="w-full border rounded-xl px-3 py-2">
                </div>
                <div>
                    <div class="text-sm font-bold text-gray-700 mb-1">통합 검색어</div>
                    <input type="text" name="q" value="<?php echo h($queryFilter); ?>" class="w-full border rounded-xl px-3 py-2">
                </div>
            </div>
            <div class="flex flex-wrap gap-2 mt-4">
                <button type="submit" class="px-4 py-2 rounded-xl bg-gray-900 text-white font-bold">검색</button>
                <a href="?r=approval_home&view=completed" class="px-4 py-2 rounded-xl bg-gray-100 text-gray-900 font-bold">초기화</a>
            </div>
        </form>
    <?php } ?>

    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-<?php echo count($countCards) >= 4 ? '4' : '3'; ?> gap-4 mt-6 mb-6">
        <?php for ($i = 0; $i < count($countCards); $i++) { ?>
            <div class="bg-white rounded-2xl border p-5 text-gray-900">
                <div class="text-gray-800 font-bold"><?php echo h($countCards[$i]['label']); ?></div>
                <div class="text-2xl font-extrabold text-gray-950"><?php echo (int)$countCards[$i]['count']; ?></div>
            </div>
        <?php } ?>
    </div>

    <div class="bg-white rounded-3xl border p-5 overflow-x-auto mt-6 text-gray-900">
        <table class="min-w-[1100px] w-full text-base text-gray-900">
            <thead>
                <?php if ($view === 'completed') { ?>
                    <tr class="text-left border-b text-gray-950">
                        <th class="py-3 px-3">종류</th>
                        <th class="py-3 px-3">제목</th>
                        <th class="py-3 px-3">작성자</th>
                        <th class="py-3 px-3">작성일시</th>
                        <th class="py-3 px-3">완료일시</th>
                        <th class="py-3 px-3">상태</th>
                        <th class="py-3 px-3">상세보기</th>
                    </tr>
                <?php } else { ?>
                    <tr class="text-left border-b text-gray-950">
                        <th class="py-3 px-3">종류</th>
                        <th class="py-3 px-3">제목</th>
                        <th class="py-3 px-3">작성자</th>
                        <th class="py-3 px-3">상태</th>
                        <th class="py-3 px-3">현재 단계</th>
                        <th class="py-3 px-3">작성일시</th>
                        <th class="py-3 px-3">액션</th>
                    </tr>
                <?php } ?>
            </thead>
            <tbody>
                <?php if (count($rows) === 0) { ?>
                    <tr>
                        <td colspan="<?php echo ($view === 'completed') ? '7' : '7'; ?>" class="py-6 px-3 text-center text-gray-500"><?php echo h($emptyMessage); ?></td>
                    </tr>
                <?php } else { ?>
                    <?php for ($i = 0; $i < count($rows); $i++) {
                        $r = $rows[$i];
                        $rowMine = approval_is_document_owner($pdo, $r, $u);
                        $rowCanCancel = $rowMine && approval_can_cancel_document($r);
                        $rowCanDelete = approval_can_delete_document($pdo, $r, $u);
                        ?>
                        <?php if ($view === 'completed') { ?>
                            <tr class="border-b">
                                <td class="py-4 px-3 text-gray-900"><?php echo h(approval_doc_label(isset($r['doc_type']) ? $r['doc_type'] : '')); ?></td>
                                <td class="py-4 px-3 text-gray-900"><?php echo h(isset($r['title']) ? $r['title'] : ''); ?></td>
                                <td class="py-4 px-3 text-gray-900"><?php echo h(isset($r['created_by_name']) ? $r['created_by_name'] : ''); ?></td>
                                <td class="py-4 px-3 text-gray-900"><?php echo h(isset($r['created_at']) ? $r['created_at'] : ''); ?></td>
                                <td class="py-4 px-3 text-gray-900"><?php echo h(isset($r['updated_at']) ? $r['updated_at'] : ''); ?></td>
                                <td class="py-4 px-3 text-gray-900"><span class="px-4 py-2 rounded-full border <?php echo approval_status_badge(isset($r['doc_status']) ? $r['doc_status'] : ''); ?>"><?php echo h(approval_status_label(isset($r['doc_status']) ? $r['doc_status'] : '')); ?></span></td>
                                <td class="py-4 px-3 text-gray-900"><a href="?r=approval_detail&id=<?php echo (int)$r['id']; ?>" class="text-indigo-700 font-bold">상세보기</a></td>
                            </tr>
                        <?php } else { ?>
                            <tr class="border-b">
                                <td class="py-4 px-3 text-gray-900"><?php echo h(approval_doc_label(isset($r['doc_type']) ? $r['doc_type'] : '')); ?></td>
                                <td class="py-4 px-3 text-gray-900"><?php echo h(isset($r['title']) ? $r['title'] : ''); ?></td>
                                <td class="py-4 px-3 text-gray-900"><?php echo h(isset($r['created_by_name']) ? $r['created_by_name'] : ''); ?></td>
                                <td class="py-4 px-3 text-gray-900"><span class="px-4 py-2 rounded-full border <?php echo approval_status_badge(isset($r['doc_status']) ? $r['doc_status'] : ''); ?>"><?php echo h(approval_status_label(isset($r['doc_status']) ? $r['doc_status'] : '')); ?></span></td>
                                <td class="py-4 px-3 text-gray-900"><?php echo h(isset($r['current_role']) && trim((string)$r['current_role']) !== '' ? $r['current_role'] : '-'); ?></td>
                                <td class="py-4 px-3 text-gray-900"><?php echo h(isset($r['created_at']) ? $r['created_at'] : ''); ?></td>
                                <td class="py-4 px-3 text-gray-900">
                                    <div class="flex flex-wrap items-center gap-3">
                                        <a href="?r=approval_detail&id=<?php echo (int)$r['id']; ?>" class="text-indigo-700 font-bold">상세보기</a>
                                        <?php if ($rowCanCancel) { ?>
                                            <form method="post" action="?r=approval_cancel" style="display:inline;">
                                                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                                <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                                <button class="text-rose-700 font-bold">요청취소</button>
                                            </form>
                                        <?php } ?>
                                        <?php if ($rowCanDelete) { ?>
                                            <form method="post" action="?r=approval_delete" style="display:inline;" onsubmit="return confirm('취소문서를 삭제하시겠습니까?');">
                                                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                                <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                                <button class="text-gray-800 font-bold">삭제</button>
                                            </form>
                                        <?php } ?>
                                    </div>
                                </td>
                            </tr>
                        <?php } ?>
                    <?php } ?>
                <?php } ?>
            </tbody>
        </table>
    </div>
</div>
