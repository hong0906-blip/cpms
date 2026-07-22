<?php
use App\Core\Db;

$pdo = null;
try {
    $pdo = Db::pdo();
} catch (Exception $e) {
    $pdo = null;
}
$dbOk = ($pdo !== null);

$projects = array();
$constructionEmployees = array();
$activeTab = isset($_GET['tab']) ? trim((string)$_GET['tab']) : 'monthly_summary';
$createdProjectId = isset($_GET['created_project_id']) ? (int)$_GET['created_project_id'] : 0;
if ($activeTab === '') $activeTab = 'monthly_summary';
if ($activeTab === 'monthly_input') $activeTab = 'monthly_summary';
if ($activeTab !== 'monthly_summary' && $activeTab !== 'project_manage' && $activeTab !== 'collaboration') $activeTab = 'monthly_summary';

$projectStatusTabs = array('입찰 진행중', '계약중', '진행중', '정산완료');
$activeProjectStatus = isset($_GET['project_status']) ? trim((string)$_GET['project_status']) : '진행중';
if (!in_array($activeProjectStatus, $projectStatusTabs, true)) $activeProjectStatus = '진행중';
$projectStatusCounts = array();
foreach ($projectStatusTabs as $projectStatusTab) $projectStatusCounts[$projectStatusTab] = 0;

if (!function_exists('cpms_project_index_status_condition')) {
function cpms_project_index_status_condition($status) {
    $status = trim((string)$status);
    if ($status === '입찰 진행중') {
        return "(status IN ('입찰 진행중', '대기중', '입찰검토', '가제', '정식전환대기') OR name LIKE '(가제)%')";
    }
    if ($status === '계약중') return "name NOT LIKE '(가제)%' AND status = '계약중'";
    if ($status === '정산완료') return "name NOT LIKE '(가제)%' AND status = '정산완료'";
    return "name NOT LIKE '(가제)%' AND (status IS NULL OR status = '' OR status = '진행중' OR status = '진행 중')";
}}

if ($dbOk && $activeTab === 'project_manage') {
    try {
        $statusCondition = cpms_project_index_status_condition($activeProjectStatus);
        $st = $pdo->prepare("
            SELECT id, name, client, contractor, location, start_date, end_date, contract_amount, status
              FROM cpms_projects
             WHERE " . $statusCondition . "
             ORDER BY id DESC
             LIMIT 200
        ");
        $st->execute();
        $projects = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($projects)) $projects = array();
    } catch (Exception $e) {
        $projects = array();
        flash_set('error', '프로젝트 목록 조회 실패: ' . $e->getMessage());
    }

    try {
        foreach ($projectStatusTabs as $projectStatusTab) {
            $countSql = "SELECT COUNT(*) FROM cpms_projects WHERE " . cpms_project_index_status_condition($projectStatusTab);
            $projectStatusCounts[$projectStatusTab] = (int)$pdo->query($countSql)->fetchColumn();
        }
    } catch (Exception $e) {
        foreach ($projectStatusTabs as $projectStatusTab) $projectStatusCounts[$projectStatusTab] = 0;
    }

    try {
        $stEmployees = $pdo->prepare("
            SELECT id, name
              FROM employees
             WHERE is_active = 1
               AND (department = '공사' OR name IN ('김영기', '강영복', '고영성'))
             ORDER BY
               CASE WHEN department = '공사' THEN 0 ELSE 1 END,
               name ASC,
               id ASC
        ");
        $stEmployees->execute();
        $constructionEmployees = $stEmployees->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($constructionEmployees)) $constructionEmployees = array();
    } catch (Exception $e) {
        $constructionEmployees = array();
    }
}

$flash = flash_get();

function status_badge_class($status) {
    $status = trim((string)$status);
    if ($status === '계약중') return 'bg-yellow-100 text-yellow-800 border-yellow-200';
    if ($status === '입찰 진행중' || $status === '대기중' || $status === '입찰검토' || $status === '가제' || $status === '정식전환대기') return 'bg-emerald-100 text-emerald-800 border-emerald-200';
    if ($status === '정산완료') return 'bg-slate-100 text-slate-700 border-slate-200';
    return 'bg-blue-100 text-blue-800 border-blue-200';
}

if (!function_exists('cpms_project_index_is_collab_draft_project')) {
function cpms_project_index_is_collab_draft_project($project) {
    $name = isset($project['name']) ? trim((string)$project['name']) : '';
    $status = isset($project['status']) ? trim((string)$project['status']) : '';
    if ($name !== '' && strpos($name, '(가제)') === 0) return true;
    if ($status === '가제' || $status === '입찰검토' || $status === '정식전환대기') return true;
    return false;
}}
?>

<div class="cpms-project-page mb-5">
  <div class="mt-3 flex flex-wrap gap-2">
    <a href="?r=공무&tab=monthly_summary" class="px-4 py-2 rounded-2xl border font-bold <?php echo $activeTab === 'monthly_summary' ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-700 border-gray-200'; ?>">월별 투입비 집계</a>
    <a href="?r=공무&tab=project_manage" class="cpms-project-manage-tab px-4 py-2 rounded-2xl border font-bold <?php echo $activeTab === 'project_manage' ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-700 border-gray-200'; ?>">프로젝트 관리</a>
    <a href="?r=public_affairs_collab"
       class="px-4 py-2 rounded-2xl border font-bold bg-white text-gray-700 border-gray-200">공무 협업툴</a>
  </div>
</div>

<?php if ($activeTab === 'monthly_summary'): ?>
  <?php try { require __DIR__ . '/monthly_summary.php'; } catch (Exception $e) { ?>
  <div class="rounded-xl border border-red-200 bg-red-50 text-red-800 p-4">
    <div class="font-bold">월별 투입비 집계를 불러오는 중 오류가 발생했습니다.</div>
    <div>공무 DB 설치/확인을 먼저 실행해주세요.</div>
    <div>오류: <?php echo h($e->getMessage()); ?></div>
  </div>
<?php } ?>
<?php elseif ($activeTab === 'collaboration'): ?>
  <script>
    window.location.replace('?r=public_affairs_collab');
  </script>
  <noscript>
    <meta http-equiv="refresh" content="0;url=?r=public_affairs_collab">
  </noscript>
  <div class="rounded-3xl border border-teal-100 bg-teal-50 text-teal-900 p-6 shadow-sm">
    <div class="text-xl font-extrabold">공무 협업툴로 이동하고 있습니다.</div>
    <div class="mt-2 text-sm font-bold text-teal-700">자동으로 열리지 않으면 아래 버튼을 눌러주세요.</div>
    <a href="?r=public_affairs_collab"
       class="mt-4 inline-flex px-5 py-3 rounded-2xl bg-teal-700 text-white font-extrabold shadow">
      공무 협업툴 열기
    </a>
    <a href="?r=public_affairs_collab&safe=1"
       class="mt-4 ml-2 inline-flex px-5 py-3 rounded-2xl bg-white text-teal-800 border border-teal-200 font-extrabold shadow">
      안전 모드
    </a>
    <a href="?r=public_affairs_collab_debug"
       class="mt-4 ml-2 inline-flex px-5 py-3 rounded-2xl bg-white text-gray-700 border border-gray-200 font-extrabold shadow"
       target="_blank" rel="noopener">
      진단 페이지
    </a>
  </div>
<?php else: ?>
<div class="flex items-center justify-between mb-6">
  <div>
    <h2 class="text-2xl font-extrabold text-gray-900">프로젝트 관리</h2>
  </div>

  <button type="button"
          class="px-5 py-3 rounded-2xl bg-gradient-to-r from-blue-600 to-cyan-500 text-white font-extrabold shadow-lg hover:shadow-xl transition"
          data-modal-open="projectAdd">
    <span class="inline-flex items-center gap-2">
      <i data-lucide="plus" class="w-5 h-5"></i> 프로젝트 생성
    </span>
  </button>
</div>

<?php if (!empty($flash) && is_array($flash)): ?>
  <?php
    $type = isset($flash['type']) ? $flash['type'] : 'info';
    $message = isset($flash['message']) ? $flash['message'] : '';
    $flashClass = 'bg-blue-50 border-blue-200 text-blue-800';
    if ($type === 'success') $flashClass = 'bg-emerald-50 border-emerald-200 text-emerald-800';
    if ($type === 'error') $flashClass = 'bg-red-50 border-red-200 text-red-800';
  ?>
  <div class="mb-6 border rounded-2xl p-4 font-bold <?php echo h($flashClass); ?>">
    <?php echo h($message); ?>
  </div>
<?php endif; ?>

<div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 border border-gray-100 overflow-hidden">
  <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
    <div class="font-extrabold text-gray-900"><?php echo h($activeProjectStatus); ?> 프로젝트 목록</div>
    <div class="text-xs text-gray-500">총 <?php echo $dbOk ? (int)count($projects) : 0; ?>건</div>
  </div>

  <div class="p-6">
    <div class="mb-5 flex flex-wrap gap-2">
      <?php foreach ($projectStatusTabs as $projectStatusTab): ?>
        <?php $projectStatusActive = ($projectStatusTab === $activeProjectStatus); ?>
        <a href="<?php echo h('?r=공무&tab=project_manage&project_status=' . urlencode($projectStatusTab)); ?>"
           class="px-4 py-2 rounded-2xl border font-extrabold inline-flex items-center gap-2 <?php echo $projectStatusActive ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-700 border-gray-200 hover:bg-gray-50'; ?>">
          <span><?php echo h($projectStatusTab); ?></span>
          <span class="text-xs px-2 py-0.5 rounded-full <?php echo $projectStatusActive ? 'bg-white/20 text-white' : 'bg-gray-100 text-gray-600'; ?>"><?php echo isset($projectStatusCounts[$projectStatusTab]) ? (int)$projectStatusCounts[$projectStatusTab] : 0; ?></span>
        </a>
      <?php endforeach; ?>
    </div>

    <?php if (!$dbOk): ?>
      <div class="text-sm text-gray-600">DB 연결이 필요합니다.</div>
    <?php elseif (!is_array($projects) || count($projects) === 0): ?>
      <div class="text-sm text-gray-600">등록된 프로젝트가 없습니다. 우측 상단에서 프로젝트를 생성해주세요.</div>
    <?php else: ?>
      <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
        <?php foreach ($projects as $project): ?>
          <?php
            $projectId = (int)$project['id'];
            $projectName = (string)$project['name'];
            $client = (string)$project['client'];
            $location = (string)$project['location'];
            $startDate = (string)$project['start_date'];
            $endDate = (string)$project['end_date'];
            $status = (string)$project['status'];
            $isCollabDraftProject = cpms_project_index_is_collab_draft_project($project);
          ?>
          <div class="rounded-3xl border <?php echo ($createdProjectId > 0 && $createdProjectId === $projectId) ? 'border-blue-300 bg-blue-50 ring-2 ring-blue-100' : 'border-gray-100 bg-white'; ?> hover:shadow-lg transition p-5">
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0">
                <a href="<?php echo h('?r=project/detail&id=' . $projectId); ?>" class="font-extrabold text-gray-900 truncate hover:text-blue-700 block"><?php echo h($projectName); ?></a>
                <?php if ($isCollabDraftProject): ?><div class="mt-2 inline-flex px-3 py-1 rounded-full bg-amber-100 text-amber-800 border border-amber-200 text-xs font-extrabold">가제 · 정식 프로젝트 전환 필요</div><?php endif; ?>
                <div class="text-sm text-gray-600 mt-1 truncate"><?php echo h($client); ?></div>
              </div>
              <span class="px-3 py-1.5 rounded-full text-sm font-extrabold border whitespace-nowrap <?php echo h(status_badge_class($status)); ?>">
                <?php echo h($status !== '' ? $status : '진행중'); ?>
              </span>
            </div>

            <div class="mt-4 space-y-2 text-sm text-gray-700">
              <div class="flex items-center gap-2">
                <i data-lucide="map-pin" class="w-4 h-4 text-gray-400"></i>
                <span class="truncate"><?php echo h($location); ?></span>
              </div>
              <div class="flex items-center gap-2">
                <i data-lucide="calendar" class="w-4 h-4 text-gray-400"></i>
                <span><?php echo h(($startDate !== '' ? $startDate : '-') . ' ~ ' . ($endDate !== '' ? $endDate : '-')); ?></span>
              </div>
            </div>

            <div class="mt-4 flex items-center justify-between gap-2">
              <a href="<?php echo h('?r=project/detail&id=' . $projectId); ?>"
                 class="px-3 py-2 rounded-2xl bg-gray-100 border border-gray-200 text-gray-700 font-extrabold hover:bg-gray-200">
                상세보기
              </a>
              <?php if ($isCollabDraftProject): ?>
                <a href="<?php echo h('?r=project/detail&id=' . $projectId); ?>"
                   class="px-3 py-2 rounded-2xl bg-amber-50 border border-amber-200 text-amber-800 font-extrabold hover:bg-amber-100">
                  정식 전환
                </a>
              <?php endif; ?>

              <form method="post" action="?r=project/project_delete" style="margin:0;">
                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                <input type="hidden" name="project_id" value="<?php echo (int)$projectId; ?>">
                <button type="submit"
                        class="px-3 py-2 rounded-2xl bg-red-50 border border-red-200 text-red-700 font-extrabold hover:bg-red-100"
                        onclick="return confirm('이 프로젝트를 삭제할까요?\n(관련 단가표/멤버/이슈도 함께 삭제됩니다.)');">
                  삭제
                </button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<div id="modal-projectAdd" class="fixed inset-0 z-50 hidden">
  <div class="absolute inset-0 bg-black/40" data-modal-close="projectAdd"></div>
  <div class="absolute inset-0 flex items-center justify-center p-4">
    <div class="w-full max-w-3xl bg-white rounded-3xl shadow-2xl border border-gray-100 overflow-hidden" style="max-height:90vh;">
      <div class="p-6 border-b border-gray-100 flex items-center justify-between">
        <div>
          <h3 class="text-xl font-extrabold text-gray-900">프로젝트 생성</h3>
        </div>
        <button type="button" class="p-3 rounded-2xl hover:bg-gray-50" data-modal-close="projectAdd">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <form method="post" action="?r=project/project_save" id="projectCreateForm">
        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>" id="csrf_project_create">
        <input type="hidden" name="unit_price_token" id="unit_price_token" value="">

        <div class="p-6 space-y-5 overflow-y-auto" style="max-height: calc(90vh - 170px);">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <div class="text-sm font-bold text-gray-700 mb-1">프로젝트명 *</div>
              <input name="name" required class="w-full px-4 py-3 rounded-2xl border border-gray-200 outline-none">
            </div>
            <div>
              <div class="text-sm font-bold text-gray-700 mb-1">발주처</div>
              <input name="client" class="w-full px-4 py-3 rounded-2xl border border-gray-200 outline-none">
            </div>
            <div>
              <div class="text-sm font-bold text-gray-700 mb-1">시공사</div>
              <input name="contractor" class="w-full px-4 py-3 rounded-2xl border border-gray-200 outline-none">
            </div>
            <div>
              <div class="text-sm font-bold text-gray-700 mb-1">상태</div>
              <select name="status" class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white outline-none">
                <option value="입찰 진행중">입찰 진행중</option>
                <option value="계약중">계약중</option>
                <option value="진행중" selected>진행중</option>
                <option value="정산완료">정산완료</option>
              </select>
            </div>
            <div class="md:col-span-2">
              <div class="text-sm font-bold text-gray-700 mb-1">현장 위치</div>
              <input name="location" class="w-full px-4 py-3 rounded-2xl border border-gray-200 outline-none">
            </div>
            <div>
              <div class="text-sm font-bold text-gray-700 mb-1">공사 시작일</div>
              <input type="date" name="start_date" class="w-full px-4 py-3 rounded-2xl border border-gray-200 outline-none">
            </div>
            <div>
              <div class="text-sm font-bold text-gray-700 mb-1">공사 종료일</div>
              <input type="date" name="end_date" class="w-full px-4 py-3 rounded-2xl border border-gray-200 outline-none">
            </div>
            <div class="md:col-span-2">
              <div class="text-sm font-bold text-gray-700 mb-1">계약금액</div>
              <input name="contract_amount" class="w-full px-4 py-3 rounded-2xl border border-gray-200 outline-none">
            </div>
            <div class="md:col-span-2">
              <div class="text-sm font-bold text-gray-700 mb-1">공사 담당자(메인) *</div>
              <select name="main_manager_id" id="main_manager_id" required class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white outline-none">
                <option value="">선택하세요</option>
                <?php foreach ($constructionEmployees as $employee): ?>
                  <option value="<?php echo (int)$employee['id']; ?>"><?php echo h($employee['name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="md:col-span-2">
              <div class="text-sm font-bold text-gray-700 mb-1">부담당자(서브) <span class="text-gray-400 font-medium">최대 4명</span></div>
              <select name="sub_manager_ids[]" id="sub_manager_ids" multiple class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white outline-none" style="min-height:120px;">
                <?php foreach ($constructionEmployees as $employee): ?>
                  <option value="<?php echo (int)$employee['id']; ?>"><?php echo h($employee['name']); ?></option>
                <?php endforeach; ?>
              </select>
              <div class="text-xs text-gray-500 mt-2">PC에서는 Ctrl 또는 Cmd를 누른 채 선택하세요.</div>
            </div>
          </div>

          <div class="bg-gray-50 rounded-3xl p-5 border border-gray-100">
            <div class="flex items-center justify-between gap-3 flex-wrap">
              <div>
                <div class="font-extrabold text-gray-900">엑셀 단가내역 업로드</div>
                <div class="text-sm text-gray-600 mt-1">프로젝트 생성 시 사용할 내역서를 미리보기로 확인합니다.</div>
              </div>
              <div class="flex items-center gap-2">
                <label class="px-4 py-2 rounded-2xl bg-white border border-gray-200 font-extrabold cursor-pointer hover:bg-gray-100">
                  <input type="file" id="unit_price_file" accept=".xlsx" class="hidden">
                  <i data-lucide="file-up" class="w-4 h-4 inline"></i> 파일 선택
                </label>
                <button type="button" id="btnPreview" class="px-4 py-2 rounded-2xl bg-gradient-to-r from-emerald-500 to-teal-500 text-white font-extrabold">
                  미리보기
                </button>
              </div>
            </div>

            <div class="mt-4 text-sm">
              <div id="previewStatus" class="text-gray-600">엑셀 파일을 선택한 뒤 미리보기를 실행해주세요.</div>
              <pre id="previewDebug" class="mt-3 hidden whitespace-pre-wrap rounded-2xl bg-gray-900 text-gray-50 p-3 text-xs overflow-auto"></pre>
            </div>

            <div id="previewWrap" class="mt-4 hidden">
              <div class="overflow-x-auto rounded-2xl border border-gray-200 bg-white">
                <table class="min-w-full text-sm">
                  <thead class="bg-gray-50 border-b border-gray-100">
                    <tr class="text-left text-gray-600">
                      <th class="px-3 py-2 font-extrabold">원본행</th>
                      <th class="px-3 py-2 font-extrabold">품명</th>
                      <th class="px-3 py-2 font-extrabold">규격</th>
                      <th class="px-3 py-2 font-extrabold">단위</th>
                      <th class="px-3 py-2 font-extrabold">수량</th>
                      <th class="px-3 py-2 font-extrabold">단가 재료비</th>
                      <th class="px-3 py-2 font-extrabold">단가 노무비</th>
                      <th class="px-3 py-2 font-extrabold">단가 경비</th>
                      <th class="px-3 py-2 font-extrabold">계산 단가 계</th>
                      <th class="px-3 py-2 font-extrabold">엑셀 단가 계</th>
                      <th class="px-3 py-2 font-extrabold">검증</th>
                    </tr>
                  </thead>
                  <tbody id="previewTbody"></tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <div class="p-6 border-t border-gray-100 bg-white" style="position: sticky; bottom: 0; z-index: 5;">
          <div class="flex items-center justify-end gap-2">
            <button type="button" class="px-5 py-3 rounded-2xl border border-gray-200 bg-white font-extrabold" data-modal-close="projectAdd">취소</button>
            <button type="submit" class="px-6 py-3 rounded-2xl bg-gradient-to-r from-blue-600 to-cyan-500 text-white font-extrabold">저장</button>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
(function(){
  function openModal(key){
    var modal = document.getElementById('modal-' + key);
    if (modal) modal.classList.remove('hidden');
  }
  function closeModal(key){
    var modal = document.getElementById('modal-' + key);
    if (modal) modal.classList.add('hidden');
  }

  document.querySelectorAll('[data-modal-open]').forEach(function(btn){
    btn.addEventListener('click', function(){
      openModal(btn.getAttribute('data-modal-open'));
    });
  });
  document.querySelectorAll('[data-modal-close]').forEach(function(btn){
    btn.addEventListener('click', function(){
      closeModal(btn.getAttribute('data-modal-close'));
    });
  });

  function fieldValue(row, key){
    if (!row || typeof row[key] === 'undefined' || row[key] === null) return '';
    return row[key];
  }
  function formatQty0(n){ var x=parseFloat(n); if(isNaN(x)) return ''; return Math.round(x).toLocaleString(); }
  function formatPrice1(n){ var x=parseFloat(n); if(isNaN(x)) return ''; return x.toLocaleString(undefined,{minimumFractionDigits:1,maximumFractionDigits:1}); }
  function formatAmount0(n){ var x=parseFloat(n); if(isNaN(x)) return ''; return Math.round(x).toLocaleString(); }

  var fileInput = document.getElementById('unit_price_file');
  var btnPreview = document.getElementById('btnPreview');
  var statusEl = document.getElementById('previewStatus');
  var debugEl = document.getElementById('previewDebug');
  var wrapEl = document.getElementById('previewWrap');
  var tbody = document.getElementById('previewTbody');
  var tokenEl = document.getElementById('unit_price_token');
  var csrfEl = document.getElementById('csrf_project_create');

  if (btnPreview) {
    btnPreview.addEventListener('click', function(){
      if (!fileInput || !fileInput.files || !fileInput.files[0]) {
        statusEl.textContent = '엑셀 파일을 먼저 선택해주세요.';
        if (wrapEl) wrapEl.classList.add('hidden');
        return;
      }

      statusEl.textContent = '미리보기를 생성 중입니다...';
      if (debugEl) {
        debugEl.classList.add('hidden');
        debugEl.textContent = '';
      }
      if (wrapEl) wrapEl.classList.add('hidden');
      if (tbody) tbody.innerHTML = '';

      var fd = new FormData();
      fd.append('_csrf', csrfEl ? csrfEl.value : '');
      fd.append('excel', fileInput.files[0]);

      fetch('?r=project/project_create_preview', { method:'POST', body:fd, credentials:'same-origin' })
        .then(function(res){ return res.json(); })
        .then(function(json){
          if (!json || !json.ok) {
            statusEl.textContent = (json && json.message) ? json.message : '미리보기에 실패했습니다.';
            if (wrapEl) wrapEl.classList.add('hidden');
            return;
          }

          if (tokenEl) tokenEl.value = json.token || '';

          var rows = json.rows || [];
          statusEl.textContent = '미리보기 ' + rows.length + '건이 준비되었습니다.';
          if (debugEl && window.location.search.indexOf('debug_unit_price=1') !== -1) {
            debugEl.textContent = JSON.stringify(json.debug || {}, null, 2);
            debugEl.classList.remove('hidden');
          }
          if (wrapEl) wrapEl.classList.remove('hidden');

          var max = Math.min(rows.length, 80);
          for (var i = 0; i < max; i++) {
            var row = rows[i] || {};
            var tr = document.createElement('tr');
            tr.className = 'border-b border-gray-100';
            function appendCell(text){
              var td = document.createElement('td');
              td.className = 'px-3 py-2';
              td.textContent = text;
              tr.appendChild(td);
            }
            appendCell(fieldValue(row, 'source_row'));
            appendCell(row.item_name || '');
            appendCell(row.spec || '');
            appendCell(row.unit || '');
            appendCell(formatQty0(fieldValue(row, 'qty')));
            appendCell(formatPrice1(fieldValue(row, 'material_unit_price')));
            appendCell(formatPrice1(fieldValue(row, 'labor_unit_price')));
            appendCell(formatPrice1(fieldValue(row, 'expense_unit_price')));
            appendCell(formatPrice1(fieldValue(row, 'calculated_unit_price') || fieldValue(row, 'total_unit_price')));
            appendCell(formatPrice1(fieldValue(row, 'excel_unit_price_total')));
            appendCell(row.unit_price_validation_text || '');
            tbody.appendChild(tr);
          }
        })
        .catch(function(){
          statusEl.textContent = '미리보기에 실패했습니다. 통신 상태를 확인해주세요.';
          if (wrapEl) wrapEl.classList.add('hidden');
        });
    });
  }

  var createForm = document.getElementById('projectCreateForm');
  var subManagerSelect = document.getElementById('sub_manager_ids');
  if (subManagerSelect) {
    subManagerSelect.addEventListener('change', function(){
      var selected = [];
      for (var i = 0; i < subManagerSelect.options.length; i++) {
        if (subManagerSelect.options[i].selected) selected.push(subManagerSelect.options[i]);
      }
      if (selected.length <= 4) return;
      selected[selected.length - 1].selected = false;
      alert('서브 담당자는 최대 4명까지 지정할 수 있습니다.');
    });
  }
  if (createForm) {
    createForm.addEventListener('submit', function(e){
      var mainManager = document.getElementById('main_manager_id');
      if (!mainManager || !mainManager.value) {
        e.preventDefault();
        alert('공사 담당자(메인)를 선택해야 저장할 수 있습니다.');
        return false;
      }
      return true;
    });
  }
})();
</script>
<?php endif; ?>
