<?php
/**
 * C:\www\cpms\app\views\admin\employees.php
 * - 직원명부 관리 (목록형/테이블)
 * - 권한: 임원(executive) 또는 부서=관리(관리부)
 * - 부서: 관리/공무/품질/안전/공사 드롭다운(고정)
 * - 직급: 주임/대리/과장/차장/부장/이사/전무/상무/부사장/고문/대표 드롭다운(고정)
 * - 삭제: 가능(확인 모달)
 * - DB 컬럼 추가는 화면에서 버튼 클릭으로 처리
 *
 * (요청사항 반영)
 * - ID 컬럼 숨김(화면에서 제거)
 * - 월급 UI 전부 제거(컬럼/버튼/모달/JS)
 *
 * PHP 5.6 호환
 */

use App\Core\Db;
use App\Core\Auth;

$canManage = Auth::canManageEmployees();
if (!$canManage) {
    echo '<div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 font-bold">접근 권한이 없습니다. (임원/관리 전용)</div>';
    return;
}

// (월급 기능은 UI에서 제거했지만, 기존 코드 영향 최소화를 위해 변수는 유지)
$canSalary = (method_exists('App\\Core\\Auth', 'canManageSalary')) ? Auth::canManageSalary() : $canManage;

$pdo = Db::pdo();
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

$rows = array();
$dbOk = ($pdo !== null);

// 부서 고정 목록(요청사항)
$deptOptions = array('관리', '공무', '품질', '안전', '공사');

// 직급 고정 목록(요청사항)  ✅ '이사' 추가
$positionOptions = array('주임','대리','과장','차장','부장','이사','전무','상무','부사장','고문','대표');

// ==========================
// position 컬럼 존재 여부 확인 + 버튼으로 컬럼 추가(웹 클릭)
// ==========================
$positionEnabled = false;

function cpms_column_exists($pdo, $table, $column) {
    try {
        $dbName = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
        if ($dbName === '') return false;

        $sql = "SELECT COUNT(*)
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = :db
                  AND TABLE_NAME = :tbl
                  AND COLUMN_NAME = :col";
        $st = $pdo->prepare($sql);
        $st->bindValue(':db', $dbName);
        $st->bindValue(':tbl', $table);
        $st->bindValue(':col', $column);
        $st->execute();
        return ((int)$st->fetchColumn() > 0);
    } catch (\Exception $e) {
        return false;
    }
}

if ($dbOk) {
    $positionEnabled = cpms_column_exists($pdo, 'employees', 'position');
}

// 컬럼 추가 버튼 처리(POST)
if ($dbOk && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
    if (!csrf_check($token)) {
        flash_set('error', '보안 토큰이 유효하지 않습니다.');
        header('Location: ?r=관리');
        exit;
    }

    $action = isset($_POST['action']) ? (string)$_POST['action'] : '';

    if ($action === 'add_position_column') {
        try {
            $positionEnabled = cpms_column_exists($pdo, 'employees', 'position');
            if ($positionEnabled) {
                flash_set('success', '이미 직급(position) 컬럼이 존재합니다.');
            } else {
                $pdo->exec("ALTER TABLE employees ADD COLUMN position VARCHAR(20) NULL AFTER department");
                flash_set('success', '직급(position) 컬럼을 추가했습니다.');
            }
        } catch (\Exception $e) {
            flash_set('error', '직급 컬럼 추가 실패: ' . $e->getMessage());
        }
        header('Location: ?r=관리');
        exit;
    }
}

// ==========================
// 직원 목록 조회
// ==========================
if ($dbOk) {
    // 컬럼 없으면 SELECT에 position 넣으면 에러나서 분기
    if ($positionEnabled) {
        $sql = "SELECT id, email, name, department, position, monthly_salary, role, photo_path, is_active
                FROM employees
                WHERE 1=1 ";
    } else {
        $sql = "SELECT id, email, name, department, monthly_salary, role, photo_path, is_active
                FROM employees
                WHERE 1=1 ";
    }

    $params = array();

    if ($q !== '') {
        if ($positionEnabled) {
            $sql .= " AND (email LIKE :q OR name LIKE :q OR department LIKE :q OR position LIKE :q) ";
        } else {
            $sql .= " AND (email LIKE :q OR name LIKE :q OR department LIKE :q) ";
        }
        $params[':q'] = '%' . $q . '%';
    }

    $sql .= " ORDER BY is_active DESC, role DESC, department ASC, name ASC, id DESC LIMIT 500";

    $st = $pdo->prepare($sql);
    foreach ($params as $k => $v) $st->bindValue($k, $v);
    $st->execute();
    $rows = $st->fetchAll();
}
?>

<div class="flex items-center justify-between mb-6">
  <div>
    <div class="text-sm text-gray-500">관리</div>
    <h2 class="text-2xl font-extrabold text-gray-900">직원명부</h2>
    <div class="text-sm text-gray-500 mt-1">이메일을 기준으로 이름/사진/권한/부서(·직급)를 매칭합니다.</div>
  </div>
  <button type="button"
          class="px-5 py-3 rounded-2xl bg-gradient-to-r from-emerald-500 to-teal-500 text-white font-extrabold shadow-lg hover:shadow-xl transition"
          data-modal-open="empAdd">
    <span class="inline-flex items-center gap-2">
      <i data-lucide="plus" class="w-5 h-5"></i> 직원 추가
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

<?php if (!$dbOk): ?>
  <div class="bg-yellow-50 border border-yellow-200 text-yellow-800 rounded-2xl p-4 font-bold mb-6">
    DB 연결이 안 되어 직원명부를 불러올 수 없습니다. <br>
    <span class="font-normal">`app/config/database.php` 설정을 확인해주세요.</span>
  </div>
<?php endif; ?>

<?php if ($dbOk && !$positionEnabled): ?>
  <div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100 mb-6">
    <div class="flex flex-col md:flex-row gap-3 md:items-center md:justify-between">
      <div class="text-sm font-bold">
        직급 컬럼 상태:
        <?php if ($positionEnabled): ?>
          <span class="ml-2 px-3 py-1 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 text-xs font-extrabold">존재함</span>
        <?php else: ?>
          <span class="ml-2 px-3 py-1 rounded-full bg-orange-50 text-orange-700 border border-orange-200 text-xs font-extrabold">없음</span>
        <?php endif; ?>
      </div>

      <form method="post" class="m-0">
        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
        <input type="hidden" name="action" value="add_position_column">
        <button class="px-4 py-2 rounded-2xl border border-gray-200 bg-white font-extrabold hover:bg-gray-50"
                <?php echo $positionEnabled ? 'disabled style="opacity:.5;cursor:not-allowed;"' : ''; ?>>
          직급 컬럼 추가
        </button>
      </form>
    </div>

    <div class="text-xs text-gray-500 mt-2">
      ※ “직급 컬럼 추가”를 1번만 누르면 직원 추가/수정에서 직급 드롭다운이 활성화됩니다.
    </div>
  </div>
<?php endif; ?>

<div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100 mb-6">
  <form method="get" action="" class="flex flex-col md:flex-row gap-3 items-center">
    <input type="hidden" name="r" value="관리">
    <div class="flex-1 w-full">
      <div class="relative">
        <i data-lucide="search" class="w-5 h-5 text-gray-400 absolute left-4 top-1/2 -translate-y-1/2"></i>
        <input class="w-full pl-12 pr-4 py-3 rounded-2xl border border-gray-200 focus:ring-4 focus:ring-emerald-200 focus:border-emerald-400 outline-none"
               name="q" value="<?php echo h($q); ?>" placeholder="이메일/이름/부서<?php echo $positionEnabled ? '/직급' : ''; ?> 검색">
      </div>
    </div>
    <button class="px-5 py-3 rounded-2xl bg-white border border-gray-200 font-bold hover:bg-gray-50">
      검색
    </button>
  </form>
</div>

<?php if ($dbOk && is_array($rows) && count($rows) > 0): ?>
  <div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 border border-gray-100 overflow-hidden">
    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-gray-50 border-b border-gray-100">
          <tr class="text-left text-gray-600">
            <th class="px-4 py-3 font-extrabold">사진</th>
            <th class="px-4 py-3 font-extrabold">이름</th>
            <th class="px-4 py-3 font-extrabold">이메일</th>
            <th class="px-4 py-3 font-extrabold">부서</th>
            <?php if ($positionEnabled): ?>
              <th class="px-4 py-3 font-extrabold">직급</th>
            <?php endif; ?>
            <th class="px-4 py-3 font-extrabold">권한</th>
            <th class="px-4 py-3 font-extrabold">상태</th>
            <th class="px-4 py-3 font-extrabold">관리</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <?php foreach ($rows as $r): ?>
            <?php
              $id = (int)$r['id'];
              $email = (string)$r['email'];
              $name = (string)$r['name'];
              $dept = isset($r['department']) ? (string)$r['department'] : '';
              $pos = $positionEnabled && isset($r['position']) ? (string)$r['position'] : '';
              $salary = isset($r['monthly_salary']) ? $r['monthly_salary'] : null; // (UI 제거됨 - 내부 유지)
              $erole = (string)$r['role'];
              $active = ((int)$r['is_active'] === 1);
              $photo = isset($r['photo_path']) ? $r['photo_path'] : null;

              $roleLabel = ($erole === 'executive') ? '임원' : '직원';
              $statusLabel = $active ? '활성' : '비활성';

              $salaryText = ''; // (UI 제거됨 - 내부 유지)
              if ($salary !== null && $salary !== '') $salaryText = number_format((float)$salary) . '원';
            ?>
            <tr class="hover:bg-gray-50/60">
              <td class="px-4 py-3">
                <div class="w-10 h-10 rounded-2xl bg-gradient-to-br from-emerald-100 to-teal-100 flex items-center justify-center overflow-hidden border border-gray-100">
                  <?php if ($photo): ?>
                    <img src="<?php echo h($photo); ?>" alt="photo" class="w-full h-full object-cover">
                  <?php else: ?>
                    <i data-lucide="user" class="w-5 h-5 text-emerald-700"></i>
                  <?php endif; ?>
                </div>
              </td>

              <td class="px-4 py-3 font-extrabold text-gray-900"><?php echo h($name); ?></td>
              <td class="px-4 py-3 text-gray-700"><?php echo h($email); ?></td>
              <td class="px-4 py-3 text-gray-700"><?php echo h($dept); ?></td>

              <?php if ($positionEnabled): ?>
                <td class="px-4 py-3 text-gray-700"><?php echo h($pos); ?></td>
              <?php endif; ?>

              <td class="px-4 py-3">
                <span class="text-xs font-bold px-3 py-1 rounded-full border <?php echo ($erole === 'executive') ? 'bg-indigo-50 text-indigo-700 border-indigo-100' : 'bg-blue-50 text-blue-700 border-blue-100'; ?>">
                  <?php echo h($roleLabel); ?>
                </span>
              </td>

              <td class="px-4 py-3">
                <span class="text-xs font-bold px-3 py-1 rounded-full border <?php echo $active ? 'bg-emerald-50 text-emerald-700 border-emerald-100' : 'bg-gray-50 text-gray-700 border-gray-100'; ?>">
                  <?php echo h($statusLabel); ?>
                </span>
              </td>

              <td class="px-4 py-3">
                <div class="flex flex-wrap gap-2">
                  <button type="button"
                          class="px-3 py-2 rounded-2xl bg-white border border-gray-200 hover:bg-gray-50 text-xs font-bold"
                          data-emp-edit="<?php echo $id; ?>"
                          data-emp-email="<?php echo h($email); ?>"
                          data-emp-name="<?php echo h($name); ?>"
                          data-emp-dept="<?php echo h($dept); ?>"
                          <?php if ($positionEnabled): ?>data-emp-pos="<?php echo h($pos); ?>"<?php endif; ?>
                          data-emp-role="<?php echo h($erole); ?>"
                          data-emp-active="<?php echo $active ? '1' : '0'; ?>">
                    <i data-lucide="edit-2" class="w-4 h-4 inline"></i> 수정
                  </button>

                  <button type="button"
                          class="px-3 py-2 rounded-2xl bg-white border border-gray-200 hover:bg-gray-50 text-xs font-bold"
                          data-emp-photo="<?php echo $id; ?>">
                    <i data-lucide="image" class="w-4 h-4 inline"></i> 사진
                  </button>

                  <button type="button"
                          class="px-3 py-2 rounded-2xl bg-white border border-red-200 text-red-700 hover:bg-red-50 text-xs font-bold"
                          data-emp-delete="<?php echo $id; ?>"
                          data-emp-name-for="<?php echo h($name); ?>">
                    <i data-lucide="trash-2" class="w-4 h-4 inline"></i> 삭제
                  </button>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php elseif ($dbOk): ?>
  <div class="bg-white/70 border border-gray-200 rounded-2xl p-6 text-gray-600">
    직원이 없습니다. “직원 추가” 버튼으로 등록하세요.
  </div>
<?php endif; ?>

<!-- ==========================
     직원 추가 모달
========================== -->
<div id="modal-empAdd" class="fixed inset-0 z-50 hidden">
  <div class="absolute inset-0 bg-black/40" data-modal-close="empAdd"></div>
  <div class="absolute inset-0 flex items-center justify-center p-4">
    <div class="w-full max-w-xl bg-white rounded-3xl shadow-2xl border border-gray-100 overflow-hidden">
      <div class="p-6 border-b border-gray-100 flex items-center justify-between">
        <h3 class="text-xl font-extrabold text-gray-900">직원 추가</h3>
        <button type="button" class="p-3 rounded-2xl hover:bg-gray-50" data-modal-close="empAdd">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <form method="post" action="?r=admin/employees_save" class="p-6 space-y-4">
        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="">

        <div>
          <label class="block text-sm font-bold text-gray-700 mb-2">이메일(로그인 기준)</label>
          <input class="w-full px-4 py-3 rounded-2xl border border-gray-200" name="email" required placeholder="user@cmbuild.kr">
        </div>

        <div>
          <label class="block text-sm font-bold text-gray-700 mb-2">이름</label>
          <input class="w-full px-4 py-3 rounded-2xl border border-gray-200" name="name" required placeholder="홍길동">
        </div>

        <div>
          <label class="block text-sm font-bold text-gray-700 mb-2">부서</label>
          <select class="w-full px-4 py-3 rounded-2xl border border-gray-200" name="department">
            <option value="">(선택)</option>
            <?php foreach ($deptOptions as $d): ?>
              <option value="<?php echo h($d); ?>"><?php echo h($d); ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <?php if ($positionEnabled): ?>
        <div>
          <label class="block text-sm font-bold text-gray-700 mb-2">직급</label>
          <select class="w-full px-4 py-3 rounded-2xl border border-gray-200" name="position">
            <option value="">(선택)</option>
            <?php foreach ($positionOptions as $p): ?>
              <option value="<?php echo h($p); ?>"><?php echo h($p); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php else: ?>
          <div class="text-xs text-orange-700 bg-orange-50 border border-orange-200 rounded-2xl p-4 font-bold">
            직급 컬럼이 아직 없습니다. 위의 “직급 컬럼 추가” 버튼을 먼저 눌러주세요.
          </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-bold text-gray-700 mb-2">권한</label>
            <select class="w-full px-4 py-3 rounded-2xl border border-gray-200" name="role">
              <option value="employee">직원</option>
              <option value="executive">임원</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-bold text-gray-700 mb-2">상태</label>
            <select class="w-full px-4 py-3 rounded-2xl border border-gray-200" name="is_active">
              <option value="1">활성</option>
              <option value="0">비활성</option>
            </select>
          </div>
        </div>

        <button class="w-full py-3 rounded-2xl bg-gradient-to-r from-emerald-500 to-teal-500 text-white font-extrabold">
          저장
        </button>
      </form>
    </div>
  </div>
</div>

<!-- ==========================
     직원 수정 모달
========================== -->
<div id="modal-empEdit" class="fixed inset-0 z-50 hidden">
  <div class="absolute inset-0 bg-black/40" data-modal-close="empEdit"></div>
  <div class="absolute inset-0 flex items-center justify-center p-4">
    <div class="w-full max-w-xl bg-white rounded-3xl shadow-2xl border border-gray-100 overflow-hidden">
      <div class="p-6 border-b border-gray-100 flex items-center justify-between">
        <h3 class="text-xl font-extrabold text-gray-900">직원 수정</h3>
        <button type="button" class="p-3 rounded-2xl hover:bg-gray-50" data-modal-close="empEdit">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <form method="post" action="?r=admin/employees_save" class="p-6 space-y-4">
        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="empEditId" value="">

        <div>
          <label class="block text-sm font-bold text-gray-700 mb-2">이메일(로그인 기준)</label>
          <input class="w-full px-4 py-3 rounded-2xl border border-gray-200" name="email" id="empEditEmail" required>
        </div>

        <div>
          <label class="block text-sm font-bold text-gray-700 mb-2">이름</label>
          <input class="w-full px-4 py-3 rounded-2xl border border-gray-200" name="name" id="empEditName" required>
        </div>

        <div>
          <label class="block text-sm font-bold text-gray-700 mb-2">부서</label>
          <select class="w-full px-4 py-3 rounded-2xl border border-gray-200" name="department" id="empEditDept">
            <option value="">(선택)</option>
            <?php foreach ($deptOptions as $d): ?>
              <option value="<?php echo h($d); ?>"><?php echo h($d); ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <?php if ($positionEnabled): ?>
        <div>
          <label class="block text-sm font-bold text-gray-700 mb-2">직급</label>
          <select class="w-full px-4 py-3 rounded-2xl border border-gray-200" name="position" id="empEditPos">
            <option value="">(선택)</option>
            <?php foreach ($positionOptions as $p): ?>
              <option value="<?php echo h($p); ?>"><?php echo h($p); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-bold text-gray-700 mb-2">권한</label>
            <select class="w-full px-4 py-3 rounded-2xl border border-gray-200" name="role" id="empEditRole">
              <option value="employee">직원</option>
              <option value="executive">임원</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-bold text-gray-700 mb-2">상태</label>
            <select class="w-full px-4 py-3 rounded-2xl border border-gray-200" name="is_active" id="empEditActive">
              <option value="1">활성</option>
              <option value="0">비활성</option>
            </select>
          </div>
        </div>

        <button class="w-full py-3 rounded-2xl bg-gradient-to-r from-emerald-500 to-teal-500 text-white font-extrabold">
          저장
        </button>
      </form>
    </div>
  </div>
</div>

<!-- ==========================
     사진 업로드 모달
========================== -->
<div id="modal-empPhoto" class="fixed inset-0 z-50 hidden">
  <div class="absolute inset-0 bg-black/40" data-modal-close="empPhoto"></div>
  <div class="absolute inset-0 flex items-center justify-center p-4">
    <div class="w-full max-w-lg bg-white rounded-3xl shadow-2xl border border-gray-100 overflow-hidden">
      <div class="p-6 border-b border-gray-100 flex items-center justify-between">
        <h3 class="text-xl font-extrabold text-gray-900">사진 업로드</h3>
        <button type="button" class="p-3 rounded-2xl hover:bg-gray-50" data-modal-close="empPhoto">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <form method="post" action="?r=admin/employees_upload" enctype="multipart/form-data" class="p-6 space-y-4">
        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
        <input type="hidden" name="id" id="empPhotoId" value="">

        <div>
          <label class="block text-sm font-bold text-gray-700 mb-2">사진 파일</label>
          <input type="file" name="photo" accept=".jpg,.jpeg,.png,.webp" required
                 class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white">
          <div class="text-xs text-gray-500 mt-2">JPG/PNG/WEBP, 최대 2MB</div>
        </div>

        <button class="w-full py-3 rounded-2xl bg-gradient-to-r from-indigo-500 to-purple-500 text-white font-extrabold">
          업로드
        </button>
      </form>
    </div>
  </div>
</div>

<!-- ==========================
     직원 삭제 모달
========================== -->
<div id="modal-empDelete" class="fixed inset-0 z-50 hidden">
  <div class="absolute inset-0 bg-black/40" data-modal-close="empDelete"></div>
  <div class="absolute inset-0 flex items-center justify-center p-4">
    <div class="w-full max-w-lg bg-white rounded-3xl shadow-2xl border border-gray-100 overflow-hidden">
      <div class="p-6 border-b border-gray-100 flex items-center justify-between">
        <h3 class="text-xl font-extrabold text-gray-900">직원 삭제</h3>
        <button type="button" class="p-3 rounded-2xl hover:bg-gray-50" data-modal-close="empDelete">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <form method="post" action="?r=admin/employees_save" class="p-6 space-y-4">
        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" id="empDeleteId" value="">

        <div class="bg-red-50 border border-red-200 text-red-800 rounded-2xl p-4 font-bold">
          정말 삭제할까요?
        </div>
        <div class="text-sm text-gray-700 font-bold" id="empDeleteName"></div>

        <div class="flex gap-2">
          <button type="button" class="flex-1 py-3 rounded-2xl bg-white border border-gray-200 font-extrabold"
                  data-modal-close="empDelete">취소</button>
          <button class="flex-1 py-3 rounded-2xl bg-red-600 text-white font-extrabold">
            삭제
          </button>
        </div>
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

    var openBtn = t.closest ? t.closest('[data-modal-open]') : null;
    if (openBtn) {
      openModal(openBtn.getAttribute('data-modal-open'));
      e.preventDefault();
      return;
    }

    var closeBtn = t.closest ? t.closest('[data-modal-close]') : null;
    if (closeBtn) {
      closeModal(closeBtn.getAttribute('data-modal-close'));
      e.preventDefault();
      return;
    }

    var btnEdit = t.closest ? t.closest('[data-emp-edit]') : null;
    if (btnEdit) {
      document.getElementById('empEditId').value = btnEdit.getAttribute('data-emp-edit');
      document.getElementById('empEditEmail').value = btnEdit.getAttribute('data-emp-email') || '';
      document.getElementById('empEditName').value = btnEdit.getAttribute('data-emp-name') || '';
      document.getElementById('empEditDept').value = btnEdit.getAttribute('data-emp-dept') || '';
      var posEl = document.getElementById('empEditPos');
      if (posEl) posEl.value = btnEdit.getAttribute('data-emp-pos') || '';
      document.getElementById('empEditRole').value = btnEdit.getAttribute('data-emp-role') || 'employee';
      document.getElementById('empEditActive').value = btnEdit.getAttribute('data-emp-active') || '1';
      openModal('empEdit');
      e.preventDefault();
      return;
    }

    var btnPhoto = t.closest ? t.closest('[data-emp-photo]') : null;
    if (btnPhoto) {
      document.getElementById('empPhotoId').value = btnPhoto.getAttribute('data-emp-photo');
      openModal('empPhoto');
      e.preventDefault();
      return;
    }

    var btnDel = t.closest ? t.closest('[data-emp-delete]') : null;
    if (btnDel) {
      var did = btnDel.getAttribute('data-emp-delete');
      var dn = btnDel.getAttribute('data-emp-name-for') || '';
      document.getElementById('empDeleteId').value = did;
      document.getElementById('empDeleteName').innerHTML = '대상: ' + dn;
      openModal('empDelete');
      e.preventDefault();
      return;
    }
  });
})();
</script>