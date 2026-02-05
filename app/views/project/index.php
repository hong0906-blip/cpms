<?php
/**
 * C:\www\cpms\app\views\project\index.php
 *
 * ✅ 반영사항
 * - 카드에서 #id 숨김
 * - 상태 뱃지: 한 줄 고정 + 조금 키움 + 색상(계약중 노랑 / 대기중 초록 / 진행중 파랑)
 * - 생성 시 상태 선택(계약중/대기중/진행중)
 * - 수정/삭제 버튼 추가(모달 수정 + POST 삭제)
 * - 엑셀 미리보기: 규격 컬럼 추가
 * - 담당자: 메인 + 부담당자(멀티) 유지
 * - 모달: 내부 스크롤 + 버튼 sticky 유지
 *
 * PHP 5.6 호환
 */

use App\Core\Db;

$pdo = Db::pdo();
$dbOk = ($pdo !== null);

$projects = array();
$constructionEmployees = array();
$memberMap = array(); // project_id => array('main'=>id, 'subs'=>array(ids))

if ($dbOk) {
    try {
        $st = $pdo->prepare("
            SELECT id, name, client, contractor, location, start_date, end_date, contract_amount, status
            FROM cpms_projects
            ORDER BY id DESC
            LIMIT 200
        ");
        $st->execute();
        $projects = $st->fetchAll();
        if (!is_array($projects)) $projects = array();
    } catch (Exception $e) {
        $projects = array();
        flash_set('error', '프로젝트 목록 조회 실패: ' . $e->getMessage());
    }

    try {
        $stE = $pdo->prepare("
            SELECT id, name
            FROM employees
            WHERE department = '공사'
              AND is_active = 1
            ORDER BY name ASC, id ASC
        ");
        $stE->execute();
        $constructionEmployees = $stE->fetchAll();
        if (!is_array($constructionEmployees)) $constructionEmployees = array();
    } catch (Exception $e) {
        $constructionEmployees = array();
        flash_set('error', '공사 담당자 목록 조회 실패: ' . $e->getMessage());
    }

    // 멤버 맵(수정모달 기본값 세팅용)
    if (count($projects) > 0) {
        $ids = array();
        foreach ($projects as $p) $ids[] = (int)$p['id'];
        $in = implode(',', array_map('intval', $ids));
        if ($in !== '') {
            try {
                $stm = $pdo->query("SELECT project_id, employee_id, role FROM cpms_project_members WHERE project_id IN ($in)");
                $rows = $stm->fetchAll();
                if (is_array($rows)) {
                    foreach ($rows as $r) {
                        $pid = (int)$r['project_id'];
                        $eid = (int)$r['employee_id'];
                        $role = (string)$r['role'];
                        if (!isset($memberMap[$pid])) $memberMap[$pid] = array('main' => 0, 'subs' => array());
                        if ($role === 'main') $memberMap[$pid]['main'] = $eid;
                        if ($role === 'sub') $memberMap[$pid]['subs'][] = $eid;
                    }
                }
            } catch (Exception $e) {}
        }
    }
}

$flash = flash_get();

function status_badge_class($st) {
    $st = trim((string)$st);
    // ✅ 배경색: 계약중=노랑, 대기중=초록, 진행중=파랑
    if ($st === '계약중') return 'bg-yellow-100 text-yellow-800 border-yellow-200';
    if ($st === '대기중') return 'bg-emerald-100 text-emerald-800 border-emerald-200';
    return 'bg-blue-100 text-blue-800 border-blue-200'; // 진행중 기본
}
?>

<div class="flex items-center justify-between mb-6">
  <div>
    <div class="text-sm text-gray-500">공무</div>
    <h2 class="text-2xl font-extrabold text-gray-900">프로젝트 관리</h2>
    <div class="text-sm text-gray-500 mt-1">프로젝트 생성 시 단가내역서(엑셀)를 업로드하면 공정 템플릿으로 사용됩니다.</div>
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
    $t = isset($flash['type']) ? $flash['type'] : 'info';
    $m = isset($flash['message']) ? $flash['message'] : '';
    $cls = 'bg-blue-50 border-blue-200 text-blue-800';
    if ($t === 'success') $cls = 'bg-emerald-50 border-emerald-200 text-emerald-800';
    if ($t === 'error') $cls = 'bg-red-50 border-red-200 text-red-800';
  ?>
  <div class="mb-6 border rounded-2xl p-4 font-bold <?php echo h($cls); ?>">
    <?php echo h($m); ?>
  </div>
<?php endif; ?>

<div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 border border-gray-100 overflow-hidden">
  <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
    <div class="font-extrabold text-gray-900">프로젝트 목록</div>
    <div class="text-xs text-gray-500">총 <?php echo $dbOk ? (int)count($projects) : 0; ?>건</div>
  </div>

  <div class="p-6">
    <?php if (!$dbOk): ?>
      <div class="text-sm text-gray-600">DB 연결이 필요합니다.</div>
    <?php elseif (!is_array($projects) || count($projects) === 0): ?>
      <div class="text-sm text-gray-600">등록된 프로젝트가 없습니다. 우측 상단에서 프로젝트를 생성하세요.</div>
    <?php else: ?>
      <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
        <?php foreach ($projects as $p): ?>
          <?php
            $pid = (int)$p['id'];
            $pname = (string)$p['name'];
            $client = (string)$p['client'];
            $location = (string)$p['location'];
            $sd = (string)$p['start_date'];
            $ed = (string)$p['end_date'];
            $status = (string)$p['status'];

            $mainId = isset($memberMap[$pid]) ? (int)$memberMap[$pid]['main'] : 0;
            $subs = isset($memberMap[$pid]) ? $memberMap[$pid]['subs'] : array();
            if (!is_array($subs)) $subs = array();
          ?>

          <div class="block rounded-3xl border border-gray-100 bg-white hover:shadow-lg transition p-5">
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0">
                <!-- ✅ #id 제거 -->
                <div class="font-extrabold text-gray-900 truncate"><?php echo h($pname); ?></div>
                <div class="text-sm text-gray-600 mt-1 truncate"><?php echo h($client); ?></div>
              </div>

              <!-- ✅ 뱃지: 한줄 고정 + 글자/패딩 살짝 키움 + 색상 -->
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
                <span><?php echo h(($sd !== '' ? $sd : '-') . ' ~ ' . ($ed !== '' ? $ed : '-')); ?></span>
              </div>
            </div>

            <div class="mt-4 flex items-center justify-between gap-2">
              <a href="<?php echo h('?r=project/detail&id=' . $pid); ?>"
                 class="text-xs text-gray-500 hover:text-gray-800 font-bold">
                클릭해서 상세로 이동
              </a>

              <div class="flex items-center gap-2">
                <!-- ✅ 수정 버튼 -->
                <button type="button"
                        class="px-3 py-2 rounded-2xl bg-gray-100 border border-gray-200 text-gray-700 font-extrabold hover:bg-gray-200"
                        data-edit-open="1"
                        data-pid="<?php echo (int)$pid; ?>"
                        data-name="<?php echo h($pname); ?>"
                        data-client="<?php echo h($client); ?>"
                        data-contractor="<?php echo h((string)$p['contractor']); ?>"
                        data-location="<?php echo h($location); ?>"
                        data-sd="<?php echo h($sd); ?>"
                        data-ed="<?php echo h($ed); ?>"
                        data-status="<?php echo h($status); ?>"
                        data-ca="<?php echo h((string)$p['contract_amount']); ?>"
                        data-main="<?php echo (int)$mainId; ?>"
                        data-subs="<?php echo h(implode(',', array_map('intval', $subs))); ?>">
                  수정
                </button>

                <!-- ✅ 삭제 버튼 -->
                <form method="post" action="?r=project/project_delete" style="margin:0;">
                  <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                  <input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>">
                  <button type="submit"
                          class="px-3 py-2 rounded-2xl bg-red-50 border border-red-200 text-red-700 font-extrabold hover:bg-red-100"
                          onclick="return confirm('이 프로젝트를 삭제할까요?\\n(관련 단가/멤버/이슈도 함께 삭제됩니다)');">
                    삭제
                  </button>
                </form>
              </div>
            </div>
          </div>

        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- =========================
  모달: 프로젝트 생성
========================= -->
<div id="modal-projectAdd" class="fixed inset-0 z-50 hidden">
  <div class="absolute inset-0 bg-black/40" data-modal-close="projectAdd"></div>
  <div class="absolute inset-0 flex items-center justify-center p-4">
    <div class="w-full max-w-3xl bg-white rounded-3xl shadow-2xl border border-gray-100 overflow-hidden" style="max-height:90vh;">
      <div class="p-6 border-b border-gray-100 flex items-center justify-between">
        <div>
          <div class="text-sm text-gray-500">공무</div>
          <h3 class="text-xl font-extrabold text-gray-900">프로젝트 생성</h3>
          <div class="text-sm text-gray-500 mt-1">엑셀 업로드 후 미리보기 확인 → 저장</div>
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

            <!-- ✅ 상태 선택 -->
            <div>
              <div class="text-sm font-bold text-gray-700 mb-1">상태</div>
              <select name="status" class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white outline-none">
                <option value="계약중">계약중</option>
                <option value="대기중">대기중</option>
                <option value="진행중" selected>진행중</option>
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
              <input name="contract_amount" class="w-full px-4 py-3 rounded-2xl border border-gray-200 outline-none" placeholder="숫자/콤마 아무거나 입력해도 저장은 숫자만">
            </div>

            <!-- 메인 담당자 -->
            <div class="md:col-span-2">
              <div class="text-sm font-bold text-gray-700 mb-1">공사 담당자(메인) *</div>
              <select name="main_manager_id" id="main_manager_id" required class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white outline-none">
                <option value="">선택하세요</option>
                <?php foreach ($constructionEmployees as $e): ?>
                  <option value="<?php echo (int)$e['id']; ?>"><?php echo h($e['name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- 서브 담당자 -->
            <div class="md:col-span-2">
              <div class="text-sm font-bold text-gray-700 mb-1">부담당자(서브) <span class="text-gray-400 font-normal">(여러 명 선택 가능)</span></div>
              <select name="sub_manager_ids[]" id="sub_manager_ids" multiple
                      class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white outline-none" style="min-height:120px;">
                <?php foreach ($constructionEmployees as $e): ?>
                  <option value="<?php echo (int)$e['id']; ?>"><?php echo h($e['name']); ?></option>
                <?php endforeach; ?>
              </select>
              <div class="text-xs text-gray-500 mt-2">PC: Ctrl(또는 Cmd) 누르고 여러 명 선택</div>
            </div>
          </div>

          <!-- 엑셀 업로드 + 미리보기 -->
          <div class="bg-gray-50 rounded-3xl p-5 border border-gray-100">
            <div class="flex items-center justify-between gap-3 flex-wrap">
              <div>
                <div class="font-extrabold text-gray-900">단가내역서(엑셀) 업로드</div>
                <div class="text-sm text-gray-600 mt-1">“견적 내역서” 탭에서 품명/규격/단위/수량/합계단가/금액을 읽습니다.</div>
              </div>
              <div class="flex items-center gap-2">
                <label class="px-4 py-2 rounded-2xl bg-white border border-gray-200 font-extrabold cursor-pointer hover:bg-gray-100">
                  <input type="file" id="unit_price_file" accept=".xlsx" class="hidden">
                  <i data-lucide="file-up" class="w-4 h-4 inline"></i> 파일 선택
                </label>
                <button type="button" id="btnPreview"
                        class="px-4 py-2 rounded-2xl bg-gradient-to-r from-emerald-500 to-teal-500 text-white font-extrabold">
                  미리보기
                </button>
              </div>
            </div>

            <div class="mt-4 text-sm">
              <div id="previewStatus" class="text-gray-600">※ 업로드 후 “미리보기”로 확인하세요.</div>
            </div>

            <div id="previewWrap" class="mt-4 hidden">
              <div class="overflow-x-auto rounded-2xl border border-gray-200 bg-white">
                <table class="min-w-full text-sm">
                  <thead class="bg-gray-50 border-b border-gray-100">
                    <tr class="text-left text-gray-600">
                      <th class="px-3 py-2 font-extrabold">품명</th>
                      <th class="px-3 py-2 font-extrabold">규격</th>
                      <th class="px-3 py-2 font-extrabold">단위</th>
                      <th class="px-3 py-2 font-extrabold">수량</th>
                      <th class="px-3 py-2 font-extrabold">합계단가</th>
                      <th class="px-3 py-2 font-extrabold">금액</th>
                    </tr>
                  </thead>
                  <tbody id="previewTbody"></tbody>
                </table>
              </div>
              <div class="text-xs text-gray-500 mt-2">※ 숫자는 소수 둘째자리에서 반올림됩니다.</div>
            </div>
          </div>
        </div>

        <div class="p-6 border-t border-gray-100 bg-white" style="position: sticky; bottom: 0; z-index: 5;">
          <div class="flex items-center justify-end gap-2">
            <button type="button" class="px-5 py-3 rounded-2xl border border-gray-200 bg-white font-extrabold"
                    data-modal-close="projectAdd">취소</button>
            <button type="submit" class="px-6 py-3 rounded-2xl bg-gradient-to-r from-blue-600 to-cyan-500 text-white font-extrabold">
              저장
            </button>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- =========================
  모달: 프로젝트 수정
========================= -->
<div id="modal-projectEdit" class="fixed inset-0 z-50 hidden">
  <div class="absolute inset-0 bg-black/40" data-modal-close="projectEdit"></div>
  <div class="absolute inset-0 flex items-center justify-center p-4">
    <div class="w-full max-w-3xl bg-white rounded-3xl shadow-2xl border border-gray-100 overflow-hidden" style="max-height:90vh;">
      <div class="p-6 border-b border-gray-100 flex items-center justify-between">
        <div>
          <div class="text-sm text-gray-500">공무</div>
          <h3 class="text-xl font-extrabold text-gray-900">프로젝트 수정</h3>
        </div>
        <button type="button" class="p-3 rounded-2xl hover:bg-gray-50" data-modal-close="projectEdit">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <form method="post" action="?r=project/project_update" id="projectEditForm">
        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
        <input type="hidden" name="project_id" id="edit_project_id" value="0">

        <div class="p-6 space-y-5 overflow-y-auto" style="max-height: calc(90vh - 170px);">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <div class="text-sm font-bold text-gray-700 mb-1">프로젝트명 *</div>
              <input name="name" id="edit_name" required class="w-full px-4 py-3 rounded-2xl border border-gray-200 outline-none">
            </div>

            <div>
              <div class="text-sm font-bold text-gray-700 mb-1">발주처</div>
              <input name="client" id="edit_client" class="w-full px-4 py-3 rounded-2xl border border-gray-200 outline-none">
            </div>

            <div>
              <div class="text-sm font-bold text-gray-700 mb-1">시공사</div>
              <input name="contractor" id="edit_contractor" class="w-full px-4 py-3 rounded-2xl border border-gray-200 outline-none">
            </div>

            <div>
              <div class="text-sm font-bold text-gray-700 mb-1">상태</div>
              <select name="status" id="edit_status" class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white outline-none">
                <option value="계약중">계약중</option>
                <option value="대기중">대기중</option>
                <option value="진행중">진행중</option>
              </select>
            </div>

            <div class="md:col-span-2">
              <div class="text-sm font-bold text-gray-700 mb-1">현장 위치</div>
              <input name="location" id="edit_location" class="w-full px-4 py-3 rounded-2xl border border-gray-200 outline-none">
            </div>

            <div>
              <div class="text-sm font-bold text-gray-700 mb-1">공사 시작일</div>
              <input type="date" name="start_date" id="edit_sd" class="w-full px-4 py-3 rounded-2xl border border-gray-200 outline-none">
            </div>

            <div>
              <div class="text-sm font-bold text-gray-700 mb-1">공사 종료일</div>
              <input type="date" name="end_date" id="edit_ed" class="w-full px-4 py-3 rounded-2xl border border-gray-200 outline-none">
            </div>

            <div class="md:col-span-2">
              <div class="text-sm font-bold text-gray-700 mb-1">계약금액</div>
              <input name="contract_amount" id="edit_ca" class="w-full px-4 py-3 rounded-2xl border border-gray-200 outline-none">
            </div>

            <div class="md:col-span-2">
              <div class="text-sm font-bold text-gray-700 mb-1">공사 담당자(메인) *</div>
              <select name="main_manager_id" id="edit_main" required class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white outline-none">
                <option value="">선택하세요</option>
                <?php foreach ($constructionEmployees as $e): ?>
                  <option value="<?php echo (int)$e['id']; ?>"><?php echo h($e['name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="md:col-span-2">
              <div class="text-sm font-bold text-gray-700 mb-1">부담당자(서브)</div>
              <select name="sub_manager_ids[]" id="edit_subs" multiple
                      class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white outline-none" style="min-height:120px;">
                <?php foreach ($constructionEmployees as $e): ?>
                  <option value="<?php echo (int)$e['id']; ?>"><?php echo h($e['name']); ?></option>
                <?php endforeach; ?>
              </select>
              <div class="text-xs text-gray-500 mt-2">PC: Ctrl(또는 Cmd) 누르고 여러 명 선택</div>
            </div>
          </div>
        </div>

        <div class="p-6 border-t border-gray-100 bg-white" style="position: sticky; bottom: 0; z-index: 5;">
          <div class="flex items-center justify-end gap-2">
            <button type="button" class="px-5 py-3 rounded-2xl border border-gray-200 bg-white font-extrabold"
                    data-modal-close="projectEdit">취소</button>
            <button type="submit" class="px-6 py-3 rounded-2xl bg-gray-900 text-white font-extrabold rounded-2xl">
              수정 저장
            </button>
          </div>
        </div>

      </form>
    </div>
  </div>
</div>

<script>
(function(){
  function openModal(key){
    var m = document.getElementById('modal-' + key);
    if (m) m.classList.remove('hidden');
  }
  function closeModal(key){
    var m = document.getElementById('modal-' + key);
    if (m) m.classList.add('hidden');
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

  // ===== 수정 모달 열기 + 값 세팅 =====
  function clearMulti(sel){
    for (var i=0;i<sel.options.length;i++) sel.options[i].selected = false;
  }
  function setMulti(sel, ids){
    var map = {};
    ids.forEach(function(x){ map[String(x)] = true; });
    for (var i=0;i<sel.options.length;i++){
      var v = sel.options[i].value;
      sel.options[i].selected = !!map[String(v)];
    }
  }

  document.querySelectorAll('[data-edit-open="1"]').forEach(function(btn){
    btn.addEventListener('click', function(){
      document.getElementById('edit_project_id').value = btn.getAttribute('data-pid') || '0';
      document.getElementById('edit_name').value = btn.getAttribute('data-name') || '';
      document.getElementById('edit_client').value = btn.getAttribute('data-client') || '';
      document.getElementById('edit_contractor').value = btn.getAttribute('data-contractor') || '';
      document.getElementById('edit_location').value = btn.getAttribute('data-location') || '';
      document.getElementById('edit_sd').value = btn.getAttribute('data-sd') || '';
      document.getElementById('edit_ed').value = btn.getAttribute('data-ed') || '';
      document.getElementById('edit_ca').value = btn.getAttribute('data-ca') || '';

      var st = btn.getAttribute('data-status') || '진행중';
      document.getElementById('edit_status').value = st;

      var main = btn.getAttribute('data-main') || '';
      document.getElementById('edit_main').value = main;

      var subsRaw = btn.getAttribute('data-subs') || '';
      var subs = [];
      if (subsRaw.trim() !== '') {
        subsRaw.split(',').forEach(function(x){
          x = x.trim();
          if (x !== '') subs.push(x);
        });
      }
      var subSel = document.getElementById('edit_subs');
      clearMulti(subSel);
      setMulti(subSel, subs);

      openModal('projectEdit');
    });
  });

  // ===== 엑셀 미리보기 =====
  function round2(n){
    var x = parseFloat(n);
    if (isNaN(x)) return '';
    return (Math.round(x * 100) / 100).toFixed(2).replace(/\.00$/, '').replace(/(\.\d)0$/, '$1');
  }

  var fileInput = document.getElementById('unit_price_file');
  var btnPreview = document.getElementById('btnPreview');
  var statusEl = document.getElementById('previewStatus');
  var wrapEl = document.getElementById('previewWrap');
  var tbody = document.getElementById('previewTbody');
  var tokenEl = document.getElementById('unit_price_token');
  var csrfEl = document.getElementById('csrf_project_create');

  btnPreview.addEventListener('click', function(){
    if (!fileInput.files || !fileInput.files[0]) {
      statusEl.textContent = '엑셀 파일을 먼저 선택하세요.';
      wrapEl.classList.add('hidden');
      return;
    }

    statusEl.textContent = '미리보기를 생성 중입니다...';
    wrapEl.classList.add('hidden');
    tbody.innerHTML = '';

    var fd = new FormData();
    fd.append('_csrf', csrfEl ? csrfEl.value : '');
    fd.append('excel', fileInput.files[0]);

    fetch('?r=project/project_create_preview', { method: 'POST', body: fd, credentials:'same-origin' })
      .then(function(res){ return res.json(); })
      .then(function(json){
        if (!json || !json.ok) {
          statusEl.textContent = (json && json.message) ? json.message : '미리보기 실패';
          wrapEl.classList.add('hidden');
          return;
        }

        tokenEl.value = json.token || '';

        var rows = json.rows || [];
        statusEl.textContent = '미리보기 ' + rows.length + '건 (저장하면 단가내역서가 프로젝트에 반영됩니다.)';
        wrapEl.classList.remove('hidden');

        var max = Math.min(rows.length, 80);
        for (var i=0; i<max; i++){
          var r = rows[i] || {};
          var tr = document.createElement('tr');
          tr.className = 'border-b border-gray-100';

          function td(txt){
            var x = document.createElement('td');
            x.className = 'px-3 py-2';
            x.textContent = txt;
            return x;
          }

          tr.appendChild(td(r.item_name || ''));
          tr.appendChild(td(r.spec || ''));                 // ✅ 규격
          tr.appendChild(td(r.unit || ''));
          tr.appendChild(td(round2(r.qty || '')));
          tr.appendChild(td(round2(r.total_unit_price || '')));
          tr.appendChild(td(round2(r.amount || '')));
          tbody.appendChild(tr);
        }

        if (rows.length > max) {
          var tr2 = document.createElement('tr');
          var td2 = document.createElement('td');
          td2.colSpan = 6;
          td2.className = 'px-3 py-2 text-xs text-gray-500';
          td2.textContent = '※ 화면에는 ' + max + '건만 표시됩니다. 저장하면 전체가 반영됩니다.';
          tr2.appendChild(td2);
          tbody.appendChild(tr2);
        }
      })
      .catch(function(){
        statusEl.textContent = '미리보기 실패(통신 오류)';
        wrapEl.classList.add('hidden');
      });
  });

  // 저장 전 메인 담당자 체크
  var form = document.getElementById('projectCreateForm');
  form.addEventListener('submit', function(e){
    var mm = document.getElementById('main_manager_id');
    if (!mm || !mm.value) {
      e.preventDefault();
      alert('공사 담당자(메인)를 선택해야 저장됩니다.');
      return false;
    }
    return true;
  });
})();
</script>
