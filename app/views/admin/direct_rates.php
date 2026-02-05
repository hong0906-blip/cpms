<?php
/**
 * C:\www\cpms\app\views\admin\direct_rates.php
 * - 직영팀(내부 인력) 일급 설정
 * - 직영팀 명부는 별도 탭(직영팀 명부)에서 관리
 * - PHP 5.6 호환
 */

use App\Core\Db;

$pdo = Db::pdo();
$dbOk = ($pdo !== null);

function cpms_table_exists_local($pdo, $table) {
    try {
        $dbName = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
        if ($dbName === '') return false;
        $sql = "SELECT COUNT(*)
                FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl";
        $st = $pdo->prepare($sql);
        $st->bindValue(':db', $dbName);
        $st->bindValue(':tbl', $table);
        $st->execute();
        return ((int)$st->fetchColumn() > 0);
    } catch (\Exception $e) {
        return false;
    }
}

$tableOk = ($dbOk && cpms_table_exists_local($pdo, 'direct_team_members'));
$members = array();

if ($tableOk) {
    $sql = "SELECT id, name, note, daily_wage, is_active
            FROM direct_team_members
            ORDER BY is_active DESC, name ASC, id DESC";
    $st = $pdo->prepare($sql);
    $st->execute();
    $members = $st->fetchAll();
}
?>

<div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100 mb-6">
  <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
    <div>
      <div class="text-sm text-gray-500">관리</div>
      <h2 class="text-2xl font-extrabold text-gray-900">직영팀 일급 설정</h2>
      <div class="text-sm text-gray-500 mt-1">
        직영팀 명부에서 등록한 인력의 <span class="font-bold">일급</span>을 설정합니다.
      </div>
    </div>
    <div class="flex gap-2">
      <a href="?r=관리&tab=direct_team"
         class="px-4 py-2 rounded-2xl border border-gray-200 bg-white font-extrabold hover:bg-gray-50">
        직영팀 명부로
      </a>
    </div>
  </div>
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

<?php if (!$dbOk): ?>
  <div class="bg-yellow-50 border border-yellow-200 text-yellow-800 rounded-2xl p-4 font-bold mb-6">
    DB 연결이 안 되어 데이터를 불러올 수 없습니다. <br>
    <span class="font-normal">`app/config/database.php` 설정을 확인해주세요.</span>
  </div>
<?php endif; ?>

<?php if ($dbOk && !$tableOk): ?>
  <div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100 mb-6">
    <div class="flex flex-col md:flex-row gap-3 md:items-center md:justify-between">
      <div class="text-sm font-bold">
        테이블 상태:
        <span class="ml-2 px-3 py-1 rounded-full bg-orange-50 text-orange-700 border border-orange-200 text-xs font-extrabold">없음</span>
      </div>

      <form method="post" action="?r=admin/direct_rates_save" class="m-0">
        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
        <!-- 과거/현재 모두 호환: action=ensure_table -->
        <input type="hidden" name="action" value="ensure_table">
        <button class="px-4 py-2 rounded-2xl border border-gray-200 bg-white font-extrabold hover:bg-gray-50">
          직영팀 명부 테이블 생성
        </button>
      </form>
    </div>

    <div class="text-xs text-gray-500 mt-2">
      ※ 1번만 누르면 됩니다. 생성 후 “직영팀 명부” 탭에서 인력 등록 → 여기서 일급 설정하세요.
    </div>
  </div>
<?php endif; ?>

<?php if ($tableOk && is_array($members) && count($members) > 0): ?>
  <div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 border border-gray-100 overflow-hidden">
    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-gray-50 border-b border-gray-100">
          <tr class="text-left text-gray-600">
            <th class="px-4 py-3 font-extrabold">이름</th>
            <th class="px-4 py-3 font-extrabold">비고</th>
            <th class="px-4 py-3 font-extrabold">일급</th>
            <th class="px-4 py-3 font-extrabold">상태</th>
            <th class="px-4 py-3 font-extrabold">관리</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <?php foreach ($members as $m): ?>
            <?php
              $id = (int)$m['id'];
              $name = (string)$m['name'];
              $note = isset($m['note']) ? (string)$m['note'] : '';
              $wage = isset($m['daily_wage']) ? (int)$m['daily_wage'] : 0;
              $active = ((int)$m['is_active'] === 1);
              $statusLabel = $active ? '활성' : '비활성';
              $wageText = ($wage > 0) ? number_format((float)$wage) . '원' : '-';
            ?>
            <tr class="hover:bg-gray-50/60">
              <td class="px-4 py-3 font-extrabold text-gray-900"><?php echo h($name); ?></td>
              <td class="px-4 py-3 text-gray-700"><?php echo h($note); ?></td>
              <td class="px-4 py-3 text-gray-900 font-bold"><?php echo h($wageText); ?></td>
              <td class="px-4 py-3">
                <span class="text-xs font-bold px-3 py-1 rounded-full border <?php echo $active ? 'bg-emerald-50 text-emerald-700 border-emerald-100' : 'bg-gray-50 text-gray-700 border-gray-100'; ?>">
                  <?php echo h($statusLabel); ?>
                </span>
              </td>
              <td class="px-4 py-3">
                <button type="button"
                        class="px-3 py-2 rounded-2xl bg-white border border-gray-200 hover:bg-gray-50 text-xs font-bold"
                        data-wage-edit="<?php echo $id; ?>"
                        data-wage-name="<?php echo h($name); ?>"
                        data-wage-val="<?php echo (int)$wage; ?>">
                  <i data-lucide="edit-2" class="w-4 h-4 inline"></i> 일급 수정
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php elseif ($tableOk): ?>
  <div class="bg-white/70 border border-gray-200 rounded-2xl p-6 text-gray-600">
    직영팀 명부가 없습니다. 먼저 <span class="font-bold">직영팀 명부</span> 탭에서 인력을 등록해주세요.
  </div>
<?php endif; ?>

<!-- ==========================
     일급 수정 모달
========================== -->
<div id="modal-wageEdit" class="fixed inset-0 z-50 hidden">
  <div class="absolute inset-0 bg-black/40" data-modal-close="wageEdit"></div>
  <div class="absolute inset-0 flex items-center justify-center p-4">
    <div class="w-full max-w-lg bg-white rounded-3xl shadow-2xl border border-gray-100 overflow-hidden">
      <div class="p-6 border-b border-gray-100 flex items-center justify-between">
        <h3 class="text-xl font-extrabold text-gray-900">직영팀 일급 수정</h3>
        <button type="button" class="p-3 rounded-2xl hover:bg-gray-50" data-modal-close="wageEdit">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <form method="post" action="?r=admin/direct_rates_save" class="p-6 space-y-4">
        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
        <input type="hidden" name="action" value="save_rate">
        <input type="hidden" name="member_id" id="wageMemberId" value="">

        <div class="bg-gray-50 border border-gray-200 rounded-2xl p-4">
          <div class="text-sm text-gray-500">대상</div>
          <div class="text-lg font-extrabold text-gray-900" id="wageMemberName"></div>
        </div>

        <div>
          <label class="block text-sm font-bold text-gray-700 mb-2">일급(원)</label>
          <input class="w-full px-4 py-3 rounded-2xl border border-gray-200" name="daily_wage" id="wageDaily" placeholder="예: 180000" required>
          <div class="text-xs text-gray-500 mt-2">숫자만 입력 (콤마는 자동 제거)</div>
        </div>

        <button class="w-full py-3 rounded-2xl bg-gradient-to-r from-emerald-500 to-teal-500 text-white font-extrabold">
          저장
        </button>
      </form>
    </div>
  </div>
</div>

<script>
(function(){
  function openModal(name){
    var el = document.getElementById('modal-' + name);
    if (!el) return;
    el.classList.remove('hidden');
    try { if (window.lucide) lucide.createIcons(); } catch(e){}
  }
  function closeModal(name){
    var el = document.getElementById('modal-' + name);
    if (!el) return;
    el.classList.add('hidden');
  }

  document.addEventListener('click', function(e){
    var t = e.target;

    var closeBtn = t.closest ? t.closest('[data-modal-close]') : null;
    if (closeBtn) {
      closeModal(closeBtn.getAttribute('data-modal-close'));
      e.preventDefault();
      return;
    }

    var btn = t.closest ? t.closest('[data-wage-edit]') : null;
    if (btn) {
      document.getElementById('wageMemberId').value = btn.getAttribute('data-wage-edit');
      document.getElementById('wageMemberName').innerHTML = btn.getAttribute('data-wage-name') || '';
      document.getElementById('wageDaily').value = btn.getAttribute('data-wage-val') || '';
      openModal('wageEdit');
      e.preventDefault();
      return;
    }
  });
})();
</script>
