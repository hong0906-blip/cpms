<?php
/* ==========================================
   CPMS - 노무비 계산 (labor_calc.php)
   ========================================== */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ---------------------------
   공통 유틸 (중복선언 방지)
--------------------------- */
if (!function_exists('h')) {
    function h($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('csrf_token')) {
    function csrf_token() {
        if (empty($_SESSION['csrf_token'])) {
            if (function_exists('openssl_random_pseudo_bytes')) {
                $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(16));
            } else {
                $_SESSION['csrf_token'] = md5(uniqid('', true));
            }
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrf_check')) {
    function csrf_check($token) {
        if (!isset($_SESSION['csrf_token'])) return false;
        if (function_exists('hash_equals')) {
            return hash_equals($_SESSION['csrf_token'], $token);
        }
        return $_SESSION['csrf_token'] === $token;
    }
}

if (!function_exists('flash_set')) {
    function flash_set($type, $msg) {
        $_SESSION['flash'] = array('type' => $type, 'msg' => $msg);
    }
}

if (!function_exists('flash_get')) {
    function flash_get() {
        if (!empty($_SESSION['flash'])) {
            $f = $_SESSION['flash'];
            unset($_SESSION['flash']);
            return $f;
        }
        return null;
    }
}

/* ---------------------------
   DB 연결
   ✅ 수정한 부분(최소 수정)
   - 기존: class_exists('Db') / Db::pdo()
   - 변경: \App\Core\Db::pdo() 사용 (네 프로젝트 구조에 맞춤)
--------------------------- */
$dbOk = false;
$pdo  = null;

try {
    if (class_exists('\\App\\Core\\Db') && method_exists('\\App\\Core\\Db', 'pdo')) {
        $pdo = \App\Core\Db::pdo();
        if ($pdo) $dbOk = true;
    }
} catch (Exception $e) {
    $dbOk = false;
}

/* ---------------------------
   테이블 존재 확인
--------------------------- */
if (!function_exists('cpms_table_exists')) {
    function cpms_table_exists($pdo, $tableName) {
        if (!$pdo) return false;

        $sql = "SELECT 1
                  FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                   AND table_name = :t
                 LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->execute(array(':t' => $tableName));
        return (bool)$st->fetchColumn();
    }
}

$entriesTable = 'labor_entries';
$directRatesTable = 'direct_team_rates';

$entriesOk = false;
$directOk  = false;
$projectsOk = false;

if ($dbOk) {
    $entriesOk = cpms_table_exists($pdo, $entriesTable);
    $directOk = cpms_table_exists($pdo, $directRatesTable);
    $projectsOk = cpms_table_exists($pdo, 'projects');
}

// ---------- 테이블 생성 SQL (웹에서 실행 버튼용/참고용) ----------
$sqlAll = "/* =========================\n"
    . "   CPMS - 노무비 계산용 테이블 생성 (MySQL 5.6)\n"
    . "   - labor_entries\n"
    . "   - direct_team_rates\n"
    . "   - projects (최소 컬럼)\n"
    . "   ========================= */\n\n"
    . "CREATE TABLE IF NOT EXISTS `labor_entries` (\n"
    . "  `id` INT NOT NULL AUTO_INCREMENT,\n"
    . "  `project_id` INT NOT NULL,\n"
    . "  `work_date` DATE NOT NULL,\n"
    . "  `worker_type` VARCHAR(20) NOT NULL DEFAULT 'direct',  -- direct / subcontract / equipment 등\n"
    . "  `employee_id` INT NULL,                               -- 직영이면 내부ID(없으면 NULL)\n"
    . "  `worker_name` VARCHAR(80) NOT NULL,\n"
    . "  `company_name` VARCHAR(120) NULL,\n"
    . "  `daily_wage` INT NULL,                                -- 공사팀 입력(외주/장비), 직영은 관리부 설정값으로 덮어씀\n"
    . "  `man_days` DECIMAL(10,2) NOT NULL DEFAULT 1.00,        -- 공수(일수)\n"
    . "  `memo` VARCHAR(255) NULL,\n"
    . "  `created_at` DATETIME NOT NULL,\n"
    . "  PRIMARY KEY (`id`),\n"
    . "  KEY `idx_labor_entries_pid_date` (`project_id`, `work_date`)\n"
    . ") ENGINE=InnoDB DEFAULT CHARSET=utf8;\n\n"
    . "CREATE TABLE IF NOT EXISTS `direct_team_rates` (\n"
    . "  `id` INT NOT NULL AUTO_INCREMENT,\n"
    . "  `employee_id` INT NOT NULL,\n"
    . "  `daily_wage` INT NOT NULL DEFAULT 0,\n"
    . "  `updated_at` DATETIME NOT NULL,\n"
    . "  PRIMARY KEY (`id`),\n"
    . "  UNIQUE KEY `uk_direct_team_rates_employee` (`employee_id`)\n"
    . ") ENGINE=InnoDB DEFAULT CHARSET=utf8;\n\n"
    . "CREATE TABLE IF NOT EXISTS `projects` (\n"
    . "  `id` INT NOT NULL AUTO_INCREMENT,\n"
    . "  `name` VARCHAR(200) NOT NULL,\n"
    . "  `created_at` DATETIME NOT NULL,\n"
    . "  PRIMARY KEY (`id`)\n"
    . ") ENGINE=InnoDB DEFAULT CHARSET=utf8;\n";

/* ---------------------------
   프로젝트 목록
--------------------------- */
$projects = array();
if ($dbOk && $projectsOk) {
    try {
        $st = $pdo->query("SELECT id, name FROM projects ORDER BY id DESC LIMIT 200");
        $projects = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $projects = array();
    }
}

/* ---------------------------
   화면 입력값 처리
--------------------------- */
$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
$month     = isset($_GET['month']) ? preg_replace('/[^0-9-]/', '', $_GET['month']) : date('Y-m');

$monthStart = $month . '-01';
$monthEnd   = date('Y-m-t', strtotime($monthStart));

/* ---------------------------
   데이터 조회 (공수 원장)
--------------------------- */
$entries = array();
if ($dbOk && $entriesOk && $projectId > 0) {
    $sql = "SELECT id, project_id, work_date, worker_type, employee_id, worker_name, company_name, daily_wage, man_days, memo
              FROM labor_entries
             WHERE project_id = :pid
               AND work_date BETWEEN :s AND :e
             ORDER BY work_date ASC, id ASC";
    $st = $pdo->prepare($sql);
    $st->execute(array(':pid' => $projectId, ':s' => $monthStart, ':e' => $monthEnd));
    $entries = $st->fetchAll(PDO::FETCH_ASSOC);
}

/* ---------------------------
   직영팀 단가(관리부 설정)
--------------------------- */
$directRates = array(); // employee_id => daily_wage
if ($dbOk && $directOk) {
    $st = $pdo->query("SELECT employee_id, daily_wage FROM direct_team_rates");
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $directRates[(int)$r['employee_id']] = (int)$r['daily_wage'];
    }
}

/* ---------------------------
   합계 계산
--------------------------- */
$totalDirect = 0;
$totalOther  = 0;

foreach ($entries as $row) {
    $type = $row['worker_type'];
    $manDays = (float)$row['man_days'];
    $wage = (int)$row['daily_wage'];

    if ($type === 'direct') {
        $eid = (int)$row['employee_id'];
        if ($eid > 0 && isset($directRates[$eid])) {
            $wage = (int)$directRates[$eid]; // 직영은 관리부 설정 단가로 덮어씀
        }
        $totalDirect += ($wage * $manDays);
    } else {
        $totalOther += ($wage * $manDays);
    }
}

$totalAll = $totalDirect + $totalOther;

$flash = flash_get();
// bootstrap.php의 flash는 ['type','message'] 형태.
// (이 파일의 과거 버전은 msg 키를 사용했으니 둘 다 호환)
$flashMsg = null;
if (is_array($flash)) {
    if (isset($flash['msg'])) {
        $flashMsg = $flash['msg'];
    } elseif (isset($flash['message'])) {
        $flashMsg = $flash['message'];
    }
}
?>

<div class="p-6 max-w-6xl mx-auto">
  <div class="flex items-start justify-between gap-4">
    <div>
      <div class="text-2xl font-black tracking-tight">노무비 계산</div>
      <div class="text-sm text-gray-500 mt-1">프로젝트별 월 공수/노무비 합계 (직영 단가는 관리부 설정으로 자동 반영)</div>
    </div>

    <div class="text-right">
      <div class="text-xs text-gray-500">합계</div>
      <div class="text-2xl font-black"><?= number_format($totalAll) ?> 원</div>
      <div class="text-xs text-gray-500 mt-1">직영 <?= number_format($totalDirect) ?> / 외주·장비 <?= number_format($totalOther) ?></div>
    </div>
  </div>

  <?php if ($flash && $flashMsg !== null): ?>
    <div class="mt-4 rounded-2xl p-4 border
      <?= $flash['type'] === 'success' ? 'bg-green-50 border-green-200 text-green-800' : '' ?>
      <?= $flash['type'] === 'error' ? 'bg-red-50 border-red-200 text-red-800' : '' ?>
      <?= $flash['type'] === 'info' ? 'bg-blue-50 border-blue-200 text-blue-800' : '' ?>
    ">
      <div class="font-bold"><?= h($flashMsg) ?></div>
    </div>
  <?php endif; ?>

  <div class="mt-6 bg-white rounded-2xl shadow-sm border border-gray-200 p-5">
    <form method="get" class="flex flex-wrap items-end gap-3">
      <!-- C:\www\cpms\public\index.php 라우팅 기준: 관리 화면(tab=labor_calc)로 조회 유지 -->
      <input type="hidden" name="r" value="관리"/>
      <input type="hidden" name="tab" value="labor_calc"/>

      <div>
        <div class="text-xs text-gray-500 mb-1">프로젝트</div>
        <select name="project_id" class="border border-gray-300 rounded-xl px-3 py-2 min-w-[240px]">
          <option value="0">프로젝트 선택</option>
          <?php foreach ($projects as $p): ?>
            <option value="<?= (int)$p['id'] ?>" <?= $projectId === (int)$p['id'] ? 'selected' : '' ?>>
              <?= h($p['name']) ?> (<?= (int)$p['id'] ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <div class="text-xs text-gray-500 mb-1">월</div>
        <input type="month" name="month" value="<?= h($month) ?>" class="border border-gray-300 rounded-xl px-3 py-2"/>
      </div>

      <button type="submit" class="bg-black text-white rounded-xl px-4 py-2 font-bold">조회</button>
    </form>

    <?php if (!$dbOk): ?>
      <div class="mt-4 bg-red-50 border border-red-200 text-red-800 rounded-2xl p-4">
        <div class="font-extrabold">DB 연결 실패</div>
        <div class="text-sm mt-2">Db 연결 객체(\App\Core\Db::pdo())를 가져오지 못했습니다. (bootstrap 로딩/DB 설정을 확인하세요)</div>
      </div>
    <?php endif; ?>

    <?php if ($dbOk && (!$entriesOk || !$directOk || !$projectsOk)): ?>
      <div class="mt-4 bg-orange-50 border border-orange-200 text-orange-800 rounded-2xl p-4">
        <div class="font-extrabold">노무비 계산용 테이블이 없습니다.</div>
        <div class="text-sm mt-2">phpMyAdmin 없이도 아래 버튼으로 <span class="font-bold">필요 테이블 3개</span>를 자동 생성할 수 있습니다.</div>

        <div class="mt-3 flex flex-wrap items-center gap-2">
          <form method="post" action="?r=admin/labor_entries_save" onsubmit="return confirm('노무비 계산용 테이블(labor_entries, direct_team_rates, projects)을 생성할까요?');">
            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"/>
            <input type="hidden" name="action" value="ensure_tables"/>
            <button type="submit" class="bg-orange-600 text-white rounded-xl px-4 py-2 font-extrabold">
              웹에서 자동 생성
            </button>
          </form>
          <div class="text-xs text-orange-700">
            ※ 기존 테이블이 있으면 덮어쓰지 않습니다(<span class="font-bold">CREATE TABLE IF NOT EXISTS</span>)
          </div>
        </div>

        <pre class="mt-3 text-xs bg-white border border-orange-200 rounded-2xl p-3 overflow-x-auto"><code><?= h($sqlAll) ?></code></pre>
      </div>
    <?php endif; ?>
  </div>

  <div class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-4">
    <div class="lg:col-span-2 bg-white rounded-2xl shadow-sm border border-gray-200 p-5">
      <div class="flex items-center justify-between">
        <div class="font-black text-lg">공수 원장</div>
        <div class="text-xs text-gray-500">공사팀 입력(외주/장비) + 직영 입력(직영은 단가 자동 덮어씀)</div>
      </div>

      <?php if (!$projectId): ?>
        <div class="mt-4 text-gray-500">프로젝트를 선택하면 공수 원장이 표시됩니다.</div>
      <?php elseif (!$entriesOk): ?>
        <div class="mt-4 text-gray-500">테이블이 없어서 공수 원장을 표시할 수 없습니다.</div>
      <?php else: ?>
        <div class="mt-4 overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead>
              <tr class="text-left text-gray-500 border-b">
                <th class="py-2 pr-3">일자</th>
                <th class="py-2 pr-3">구분</th>
                <th class="py-2 pr-3">성명</th>
                <th class="py-2 pr-3">업체</th>
                <th class="py-2 pr-3 text-right">일급(원)</th>
                <th class="py-2 pr-3 text-right">공수</th>
                <th class="py-2 pr-3 text-right">금액</th>
                <th class="py-2 pr-3">비고</th>
                <th class="py-2 pr-3"></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($entries as $row): ?>
                <?php
                  $type = $row['worker_type'];
                  $man = (float)$row['man_days'];
                  $wage = (int)$row['daily_wage'];

                  if ($type === 'direct') {
                      $eid = (int)$row['employee_id'];
                      if ($eid > 0 && isset($directRates[$eid])) {
                          $wage = (int)$directRates[$eid];
                      }
                  }
                  $amt = $wage * $man;
                ?>
                <tr class="border-b">
                  <td class="py-2 pr-3 whitespace-nowrap"><?= h($row['work_date']) ?></td>
                  <td class="py-2 pr-3 whitespace-nowrap">
                    <?php if ($type === 'direct'): ?>
                      <span class="px-2 py-1 rounded-lg bg-blue-50 text-blue-700 text-xs font-bold">직영</span>
                    <?php elseif ($type === 'subcontract'): ?>
                      <span class="px-2 py-1 rounded-lg bg-green-50 text-green-700 text-xs font-bold">외주</span>
                    <?php else: ?>
                      <span class="px-2 py-1 rounded-lg bg-gray-50 text-gray-700 text-xs font-bold"><?= h($type) ?></span>
                    <?php endif; ?>
                  </td>
                  <td class="py-2 pr-3"><?= h($row['worker_name']) ?></td>
                  <td class="py-2 pr-3"><?= h($row['company_name']) ?></td>
                  <td class="py-2 pr-3 text-right"><?= number_format($wage) ?></td>
                  <td class="py-2 pr-3 text-right"><?= number_format($man, 2) ?></td>
                  <td class="py-2 pr-3 text-right font-bold"><?= number_format($amt) ?></td>
                  <td class="py-2 pr-3"><?= h($row['memo']) ?></td>
                  <td class="py-2 pr-3 text-right">
                    <form method="post" action="?r=admin/labor_entries_save" onsubmit="return confirm('삭제할까요?');">
                      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"/>
                      <input type="hidden" name="action" value="delete"/>
                      <input type="hidden" name="id" value="<?= (int)$row['id'] ?>"/>
                      <input type="hidden" name="project_id" value="<?= (int)$projectId ?>"/>
                      <input type="hidden" name="month" value="<?= h($month) ?>"/>
                      <button type="submit" class="text-red-600 font-bold">삭제</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>

              <?php if (empty($entries)): ?>
                <tr>
                  <td colspan="9" class="py-6 text-center text-gray-500">데이터가 없습니다.</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-5">
      <div class="font-black text-lg">공수 수동입력(테스트)</div>
      <div class="text-xs text-gray-500 mt-1">현재는 테스트용. 추후 공사팀 입력 화면으로 분리 예정</div>

      <form method="post" action="?r=admin/labor_entries_save" class="mt-4 space-y-3">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"/>
        <input type="hidden" name="action" value="add"/>
        <input type="hidden" name="project_id" value="<?= (int)$projectId ?>"/>
        <input type="hidden" name="month" value="<?= h($month) ?>"/>

        <div>
          <div class="text-xs text-gray-500 mb-1">일자</div>
          <input type="date" name="work_date" value="<?= h(date('Y-m-d')) ?>" class="w-full border border-gray-300 rounded-xl px-3 py-2"/>
        </div>

        <div>
          <div class="text-xs text-gray-500 mb-1">구분</div>
          <select name="worker_type" class="w-full border border-gray-300 rounded-xl px-3 py-2">
            <option value="direct">직영</option>
            <option value="subcontract">외주</option>
            <option value="equipment">장비</option>
          </select>
        </div>

        <div>
          <div class="text-xs text-gray-500 mb-1">직영 Employee ID (직영일 때만)</div>
          <input type="number" name="employee_id" value="" class="w-full border border-gray-300 rounded-xl px-3 py-2" placeholder="예: 101"/>
        </div>

        <div>
          <div class="text-xs text-gray-500 mb-1">성명</div>
          <input type="text" name="worker_name" value="" class="w-full border border-gray-300 rounded-xl px-3 py-2" placeholder="홍길동"/>
        </div>

        <div>
          <div class="text-xs text-gray-500 mb-1">업체명(외주/장비)</div>
          <input type="text" name="company_name" value="" class="w-full border border-gray-300 rounded-xl px-3 py-2" placeholder="OO건설"/>
        </div>

        <div class="grid grid-cols-2 gap-2">
          <div>
            <div class="text-xs text-gray-500 mb-1">일급(원)</div>
            <input type="number" name="daily_wage" value="" class="w-full border border-gray-300 rounded-xl px-3 py-2" placeholder="200000"/>
          </div>
          <div>
            <div class="text-xs text-gray-500 mb-1">공수</div>
            <input type="number" step="0.01" name="man_days" value="1.00" class="w-full border border-gray-300 rounded-xl px-3 py-2"/>
          </div>
        </div>

        <div>
          <div class="text-xs text-gray-500 mb-1">비고</div>
          <input type="text" name="note" value="" class="w-full border border-gray-300 rounded-xl px-3 py-2" placeholder="메모"/>
        </div>

        <button type="submit" class="w-full bg-black text-white rounded-xl px-4 py-2 font-black">
          저장
        </button>
      </form>

      <div class="mt-4 text-xs text-gray-500 leading-relaxed">
        ※ 직영은 employee_id가 있으면 <span class="font-bold">direct_team_rates의 일급</span>으로 계산됩니다.<br/>
        ※ 외주/장비는 여기 입력한 일급이 그대로 계산됩니다.
      </div>
    </div>
  </div>
</div>
