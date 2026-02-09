<?php
/**
 * C:\\www\\cpms\\app\\views\\admin\\direct_team.php
 * - 직영팀 명부 확인 (간단형)
 * - 관리부에서 직영팀 설정 탭에 등록된 인력만 표시
 * - 일급/인력 등록은 "직영팀 설정" 탭에서 관리
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
        $st = $pdo->prepare("SELECT * FROM direct_team_members WHERE deposit_rate IS NOT NULL AND deposit_rate <> '' ORDER BY id DESC LIMIT 500");
        $st->execute();
        $rows = $st->fetchAll();
    }
}
?>

<div class="flex items-center justify-between mb-6">
  <div>
    <h3 class="text-xl font-extrabold text-gray-900">직영팀 명부</h3>
    <div class="text-sm text-gray-500 mt-1">직영팀 설정에서 등록한 인력만 표시됩니다.</div>
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
  <div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 border border-gray-100 overflow-hidden">
    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-gray-50 border-b border-gray-100">
          <tr class="text-left text-gray-600">
            <th class="px-4 py-3 font-extrabold">이름</th>
            <th class="px-4 py-3 font-extrabold">핸드폰번호</th>
            <th class="px-4 py-3 font-extrabold">주소</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <?php if (is_array($rows) && count($rows) > 0): ?>
            <?php foreach ($rows as $r): ?>
              <?php
                $name = (string)$r['name'];
                $phone = isset($r['phone']) ? (string)$r['phone'] : '';
                $address = isset($r['address']) ? (string)$r['address'] : '';
              ?>
              <tr class="hover:bg-gray-50/60">
                <td class="px-4 py-3 font-extrabold text-gray-900"><?php echo h($name); ?></td>
                <td class="px-4 py-3">
                    <?php echo h($phone); ?>
                </td>
                <td class="px-4 py-3">
                    <?php echo h($address); ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="3" class="px-4 py-6 text-gray-600">직영팀 설정에서 먼저 등록하세요.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>
