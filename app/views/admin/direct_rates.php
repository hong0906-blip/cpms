<?php
/**
 * C:\www\cpms\app\views\admin\direct_rates.php
 * - 직영팀(내부 인력) 설정
 * - 직영팀 명부는 별도 탭(직영팀 명부)에서 확인
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
$sql = "SELECT * FROM direct_team_members ORDER BY id DESC";
    $st = $pdo->prepare($sql);
    $st->execute();
    $members = $st->fetchAll();
}
?>

<div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100 mb-6">
  <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
    <div>
      <div class="text-sm text-gray-500">관리</div>
      <h2 class="text-2xl font-extrabold text-gray-900">직영팀 설정</h2>
      <div class="text-sm text-gray-500 mt-1">
        직영팀 인력 정보를 등록하고 일급을 설정합니다.
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
      ※ 1번만 누르면 됩니다. 생성 후 아래에서 인력을 등록하고 일급을 설정하세요.
    </div>
  </div>
<?php endif; ?>

<?php if ($tableOk): ?>
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
        <label class="block text-sm font-bold text-gray-700 mb-2">주민등록번호</label>
        <input class="w-full px-4 py-3 rounded-2xl border border-gray-200" name="resident_no" placeholder="000000-0000000">
      </div>
      <div>
        <label class="block text-sm font-bold text-gray-700 mb-2">핸드폰번호</label>
        <input class="w-full px-4 py-3 rounded-2xl border border-gray-200" name="phone" placeholder="010-0000-0000">
      </div>
      <div>
        <label class="block text-sm font-bold text-gray-700 mb-2">주소</label>
        <input class="w-full px-4 py-3 rounded-2xl border border-gray-200" name="address" placeholder="주소">
      </div>
      <div>
        <label class="block text-sm font-bold text-gray-700 mb-2">임금단가</label>
        <input class="w-full px-4 py-3 rounded-2xl border border-gray-200" name="deposit_rate" placeholder="예: 180000">
      </div>
      <div>
        <label class="block text-sm font-bold text-gray-700 mb-2">계좌번호</label>
        <input class="w-full px-4 py-3 rounded-2xl border border-gray-200" name="bank_account" placeholder="000-0000-0000">
      </div>
      <div>
        <label class="block text-sm font-bold text-gray-700 mb-2">은행명</label>
        <input class="w-full px-4 py-3 rounded-2xl border border-gray-200" name="bank_name" placeholder="은행명">
      </div>
      <div>
        <label class="block text-sm font-bold text-gray-700 mb-2">예금주</label>
        <input class="w-full px-4 py-3 rounded-2xl border border-gray-200" name="account_holder" placeholder="예금주">
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
            <th class="px-4 py-3 font-extrabold">주민등록번호</th>
            <th class="px-4 py-3 font-extrabold">핸드폰번호</th>
            <th class="px-4 py-3 font-extrabold">주소</th>
            <th class="px-4 py-3 font-extrabold">임금단가</th>
            <th class="px-4 py-3 font-extrabold">계좌번호</th>
            <th class="px-4 py-3 font-extrabold">은행명</th>
            <th class="px-4 py-3 font-extrabold">예금주</th>
            <th class="px-4 py-3 font-extrabold">관리</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <?php if (is_array($members) && count($members) > 0): ?>
            <?php foreach ($members as $m): ?>
              <?php
                $id = (int)$m['id'];
                $name = (string)$m['name'];
                $residentNo = isset($m['resident_no']) ? (string)$m['resident_no'] : '';
                $phone = isset($m['phone']) ? (string)$m['phone'] : '';
                $address = isset($m['address']) ? (string)$m['address'] : '';
                $depositRate = isset($m['deposit_rate']) ? (string)$m['deposit_rate'] : '';
                $bankAccount = isset($m['bank_account']) ? (string)$m['bank_account'] : '';
                $bankName = isset($m['bank_name']) ? (string)$m['bank_name'] : '';
                $accountHolder = isset($m['account_holder']) ? (string)$m['account_holder'] : '';
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
                    <input class="w-full px-3 py-2 rounded-2xl border border-gray-200" name="resident_no" value="<?php echo h($residentNo); ?>">
                </td>
                <td class="px-4 py-3">
                    <input class="w-full px-3 py-2 rounded-2xl border border-gray-200" name="phone" value="<?php echo h($phone); ?>">
                </td>
                <td class="px-4 py-3">
                    <input class="w-full px-3 py-2 rounded-2xl border border-gray-200" name="address" value="<?php echo h($address); ?>">
                </td>
                <td class="px-4 py-3">
                    <input class="w-full px-3 py-2 rounded-2xl border border-gray-200" name="deposit_rate" value="<?php echo h($depositRate); ?>">
                </td>
                <td class="px-4 py-3">
                    <input class="w-full px-3 py-2 rounded-2xl border border-gray-200" name="bank_account" value="<?php echo h($bankAccount); ?>">
                </td>
                <td class="px-4 py-3">
                    <input class="w-full px-3 py-2 rounded-2xl border border-gray-200" name="bank_name" value="<?php echo h($bankName); ?>">
                </td>
                <td class="px-4 py-3">
                    <input class="w-full px-3 py-2 rounded-2xl border border-gray-200" name="account_holder" value="<?php echo h($accountHolder); ?>">
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
              <td colspan="9" class="px-4 py-6 text-gray-600">직영팀 인력이 없습니다. 위에서 추가하세요.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>
