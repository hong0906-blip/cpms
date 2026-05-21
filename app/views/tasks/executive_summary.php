<?php
use App\Core\Auth;
use App\Core\Db;

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/task_feed_helper.php';

if (!Auth::check()) {
    header('Location: ?r=login');
    exit;
}
if (!(Auth::isMaster() || Auth::userRole() === 'executive' || Auth::canManageEmployees())) {
    http_response_code(403);
    echo '403 Forbidden';
    exit;
}

$pdo = Db::pdo();
$department = isset($_GET['department']) ? trim((string)$_GET['department']) : '';
$data = cpms_task_feed_for_executive($pdo, array('department' => $department));

if (isset($_GET['format']) && (string)$_GET['format'] === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}
?>
<div class="space-y-8">
    <div class="bg-white rounded-3xl p-6 border border-gray-100">
        <h2 class="text-2xl font-extrabold text-gray-900">업무 현황 요약</h2>
        <div class="grid grid-cols-2 md:grid-cols-6 gap-4 mt-4">
            <div class="p-4 rounded-2xl bg-slate-50 border border-slate-200"><div class="text-xs text-slate-500">오늘 할일</div><div class="mt-2 text-2xl font-extrabold text-slate-900"><?php echo (int)$data['summary']['today']; ?></div></div>
            <div class="p-4 rounded-2xl bg-rose-50 border border-rose-200"><div class="text-xs text-rose-500">긴급 요청</div><div class="mt-2 text-2xl font-extrabold text-rose-700"><?php echo (int)$data['summary']['urgent']; ?></div></div>
            <div class="p-4 rounded-2xl bg-amber-50 border border-amber-200"><div class="text-xs text-amber-500">마감 임박</div><div class="mt-2 text-2xl font-extrabold text-amber-700"><?php echo (int)$data['summary']['due_soon']; ?></div></div>
            <div class="p-4 rounded-2xl bg-red-50 border border-red-200"><div class="text-xs text-red-500">지연 업무</div><div class="mt-2 text-2xl font-extrabold text-red-700"><?php echo (int)$data['summary']['delayed']; ?></div></div>
            <div class="p-4 rounded-2xl bg-emerald-50 border border-emerald-200"><div class="text-xs text-emerald-500">완료</div><div class="mt-2 text-2xl font-extrabold text-emerald-700"><?php echo (int)$data['summary']['done']; ?></div></div>
            <div class="p-4 rounded-2xl bg-blue-50 border border-blue-200"><div class="text-xs text-blue-500">승인대기</div><div class="mt-2 text-2xl font-extrabold text-blue-700"><?php echo (int)$data['summary']['approval_pending']; ?></div></div>
        </div>
    </div>
</div>
