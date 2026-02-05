<?php
/**
 * C:\\www\\cpms\\app\\views\\admin\\direct_team.php
 * - 직영팀 명부 관리 (간단형)
 * - 관리부에서 직영팀 인력 등록/수정/삭제
 * - 일급은 "직영팀 일급 설정" 탭에서 설정
 *
 * PHP 5.6 호환
 */

use App\Core\Db;

$pdo = Db::pdo();
$dbOk = ($pdo !== null);

function cpms_table_exists_local($pdo, $table) {
    try {
        $dbName = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
        if ($dbName === '') return false;
        $sql = "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl";
        $st = $pdo->prepare($sql);
        $st->bindValue(':db', $dbName);
        $st->bindValue(':tbl', $table);
        $st->execute();
        return ((int)$st->fetchColumn() > 0);
    } catch (\Exception $e) {
        return false;
    }
}

$tableOk = false;
$rows = array();
if ($dbOk) {
    $tableOk = cpms_table_exists_local($pdo, 'direct_team_members');
    if ($tableOk) {
        $st = $pdo->prepare("SELECT id, name, note, is_active, daily_wage FROM direct_team_members ORDER BY is_active DESC, name ASC, id DESC LIMIT 500");
        $st->execute();
        $rows = $st->fetchAll();
    }
}
?>
?>

<div class="flex items-center justify-between mb-6">
  <div>
    <h3 class="text-xl font-extrabold text-gray-900">직영팀 명부</h3>
    <div class="text-sm text-gray-500 mt-1">직영팀 인력(이름/메모/상태)을 등록합니다. 일급은 “직영팀 일급 설정” 탭에서 설정하세요.</div>
  </div>
</div>

<?php if (!$dbOk): ?>
  <div class="bg-yellow-50 border border-yellow-200 text-yellow-800 rounded-2xl p-4 font-bold mb-6">
    DB 연결이 안 되어 직영팀 명부를 불러올 수 없습니다.
    <br><span class="font-normal">`app/config/database.php` 설정을 확인해주세요.</span>
  </div>
<?php endif; ?>

<?php if ($dbOk && !$tableOk): ?>
  <div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100 mb-6">
    <div class="font-bold text-sm mb-2">직영팀 명부 테이블이 없습니다.</div>
    <div class="text-xs text-gray-500 mb-4">아래 버튼을 누르면 <code>direct_team_members</code> 테이블이 자동 생성됩니다.</div>
    <form method="post" action="?r=admin/direct_rates_save" class="m-0">
      <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
      <input type="hidden" name="action" value="ensure_table">
      <button class="px-4 py-2 rounded-2xl bg-white border border-gray-200 font-extrabold hover:bg-gray-50">
        직영팀 명부 테이블 생성
      </button>
    </form>
  </div>
<?php endif; ?>

<?php if ($dbOk && $tableOk): ?>
  <div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100 mb-6">
    <div class="font-extrabold text-gray-900 mb-3">인력 추가</div>
    <form method="post" action="?r=admin/direct_team_save" class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
      <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="">

      <div>
        <label class="block text-sm font-bold text-gray-700 mb-2">이름</label>
        <input class="w-full px-4 py-3 rounded-2xl border border-gray-200" name="name" required placeholder="홍길동">
      </div>
      <div>
        <label class="block text-sm font-bold text-gray-700 mb-2">메모</label>
        <input class="w-full px-4 py-3 rounded-2xl border border-gray-200" name="note" placeholder="(선택) 팀/직종/기타">
      </div>
      <div>
        <label class="block text-sm font-bold text-gray-700 mb-2">상태</label>
        <select class="w-full px-4 py-3 rounded-2xl border border-gray-200" name="is_active">
          <option value="1">활성</option>
          <option value="0">비활성</option>
        </select>
      </div>
      <button class="w-full py-3 rounded-2xl bg-gradient-to-r from-indigo-500 to-purple-500 text-white font-extrabold">
        추가
      </button>
    </form>
  </div>

  <div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 border border-gray-100 overflow-hidden">
    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-gray-50 border-b border-gray-100">
          <tr class="text-left text-gray-600">
            <th class="px-4 py-3 font-extrabold">이름</th>
            <th class="px-4 py-3 font-extrabold">메모</th>
            <th class="px-4 py-3 font-extrabold">일급</th>
            <th class="px-4 py-3 font-extrabold">상태</th>
            <th class="px-4 py-3 font-extrabold">관리</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <?php if (is_array($rows) && count($rows) > 0): ?>
            <?php foreach ($rows as $r): ?>
              <?php
                $id = (int)$r['id'];
                $name = (string)$r['name'];
                $note = isset($r['note']) ? (string)$r['note'] : '';
                $active = ((int)$r['is_active'] === 1);
                $wage = isset($r['daily_wage']) ? (int)$r['daily_wage'] : 0;
              ?>
              <tr class="hover:bg-gray-50/60">
                <td class="px-4 py-3 font-extrabold text-gray-900">
                  <form method="post" action="?r=admin/direct_team_save" class="m-0">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
                    <input class="w-full px-3 py-2 rounded-2xl border border-gray-200" name="name" value="<?php echo h($name); ?>" required>
                </td>
                <td class="px-4 py-3">
                    <input class="w-full px-3 py-2 rounded-2xl border border-gray-200" name="note" value="<?php echo h($note); ?>" placeholder="(선택)">
                </td>
                <td class="px-4 py-3 text-gray-700">
                  <?php echo number_format($wage); ?>원
                </td>
                <td class="px-4 py-3">
                    <select class="w-full px-3 py-2 rounded-2xl border border-gray-200" name="is_active">
                      <option value="1" <?php echo $active ? 'selected' : ''; ?>>활성</option>
                      <option value="0" <?php echo !$active ? 'selected' : ''; ?>>비활성</option>
                    </select>
                </td>
                <td class="px-4 py-3">
                    <div class="flex gap-2">
                      <button class="px-3 py-2 rounded-2xl bg-white border border-gray-200 hover:bg-gray-50 text-xs font-bold">
                        저장
                      </button>
                      </form>

                      <form method="post" action="?r=admin/direct_team_save" class="m-0" onsubmit="return confirm('삭제할까요?');">
                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
                        <button class="px-3 py-2 rounded-2xl bg-white border border-red-200 text-red-700 hover:bg-red-50 text-xs font-bold">
                          삭제
                        </button>
                      </form>
                    </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="5" class="px-4 py-6 text-gray-600">직영팀 인력이 없습니다. 위에서 추가하세요.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>
