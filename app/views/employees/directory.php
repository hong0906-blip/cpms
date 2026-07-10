<?php
/**
 * app/views/employees/directory.php
 * - 임직원 연락처 카드 화면
 * - 관리 > 직원명부(employees) 데이터를 보기 전용으로 표시
 * - PHP 5.6 호환
 */

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) {
    echo '<div class="rounded-2xl border border-red-200 bg-red-50 p-4 font-bold text-red-700">로그인이 필요합니다.</div>';
    return;
}

$pdo = Db::pdo();
$rows = array();
$loadError = '';

if (!function_exists('cpms_employee_directory_column_exists')) {
function cpms_employee_directory_column_exists($pdo, $column) {
    if (!$pdo || trim((string)$column) === '') return false;
    try {
        $dbName = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
        if ($dbName === '') return false;
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=:db AND TABLE_NAME='employees' AND COLUMN_NAME=:col");
        $st->execute(array(':db' => $dbName, ':col' => $column));
        return ((int)$st->fetchColumn() > 0);
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_employee_directory_table_exists')) {
function cpms_employee_directory_table_exists($pdo) {
    if (!$pdo) return false;
    try {
        $st = $pdo->prepare("SHOW TABLES LIKE 'employees'");
        $st->execute();
        return (bool)$st->fetch(PDO::FETCH_NUM);
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_employee_directory_select_column')) {
function cpms_employee_directory_select_column($pdo, $column, $alias, $fallbackSql) {
    if (cpms_employee_directory_column_exists($pdo, $column)) {
        return $column . ' AS ' . $alias;
    }
    return $fallbackSql . ' AS ' . $alias;
}}

if (!function_exists('cpms_employee_directory_first_select_column')) {
function cpms_employee_directory_first_select_column($pdo, $columns, $alias, $fallbackSql) {
    if (!is_array($columns)) $columns = array();
    for ($i = 0; $i < count($columns); $i++) {
        $column = trim((string)$columns[$i]);
        if ($column !== '' && cpms_employee_directory_column_exists($pdo, $column)) {
            return $column . ' AS ' . $alias;
        }
    }
    return $fallbackSql . ' AS ' . $alias;
}}

if (!function_exists('cpms_employee_directory_normalize_dept')) {
function cpms_employee_directory_normalize_dept($dept) {
    $dept = trim((string)$dept);
    $map = array(
        '관리부' => '관리',
        '관리팀' => '관리',
        '공무부' => '공무',
        '공무팀' => '공무',
        '공사부' => '공사',
        '공사팀' => '공사',
        '품질부' => '품질',
        '품질팀' => '품질',
        '안전부' => '안전',
        '안전팀' => '안전',
        '보건부' => '보건',
        '보건팀' => '보건',
        '안전보건' => '안전',
        '안전/보건' => '안전',
        '개발부' => '개발',
        '개발팀' => '개발',
    );
    if (isset($map[$dept])) return $map[$dept];
    if (substr($dept, -3) === '부' || substr($dept, -3) === '팀') {
        $dept = substr($dept, 0, -3);
    }
    return trim($dept);
}}

if (!function_exists('cpms_employee_directory_filter_group')) {
function cpms_employee_directory_filter_group($dept, $sectionName) {
    $sectionName = trim((string)$sectionName);
    if ($sectionName === 'CEO' || $sectionName === '임원') return $sectionName;
    $dept = cpms_employee_directory_normalize_dept($dept);
    if ($dept === '품질' || $dept === '안전' || $dept === '보건') return '품질/안전';
    return $dept !== '' ? $dept : '기타';
}}

if (!function_exists('cpms_employee_directory_position_weight')) {
function cpms_employee_directory_position_weight($position) {
    $position = trim((string)$position);
    $weights = array(
        '회장' => 1,
        '대표' => 2,
        '대표이사' => 2,
        '부사장' => 3,
        '고문' => 4,
        '전무' => 5,
        '상무' => 6,
        '이사' => 7,
        '부장' => 8,
        '차장' => 9,
        '과장' => 10,
        '대리' => 11,
        '주임' => 12,
        '사원' => 13,
    );
    if (isset($weights[$position])) return $weights[$position];
    return 99;
}}

if (!function_exists('cpms_employee_directory_is_ceo')) {
function cpms_employee_directory_is_ceo($row) {
    $position = isset($row['position']) ? trim((string)$row['position']) : '';
    $email = isset($row['email']) ? strtolower(trim((string)$row['email'])) : '';
    if ($position === '회장' || $position === '대표' || $position === '대표이사') return true;
    return ($email === 'chairman@cmbuild.kr' || $email === 'ceo@cmbuild.kr');
}}

if (!function_exists('cpms_employee_directory_is_executive')) {
function cpms_employee_directory_is_executive($row) {
    if (cpms_employee_directory_is_ceo($row)) return false;
    $role = isset($row['role']) ? trim((string)$row['role']) : '';
    $position = isset($row['position']) ? trim((string)$row['position']) : '';
    if ($role === 'executive') return true;
    return in_array($position, array('부사장', '고문', '전무', '상무', '이사'), true);
}}

if (!function_exists('cpms_employee_directory_compare_rows')) {
function cpms_employee_directory_compare_rows($a, $b) {
    $aw = cpms_employee_directory_position_weight(isset($a['position']) ? $a['position'] : '');
    $bw = cpms_employee_directory_position_weight(isset($b['position']) ? $b['position'] : '');
    if ($aw !== $bw) return ($aw < $bw) ? -1 : 1;

    $ah = isset($a['hire_date']) ? trim((string)$a['hire_date']) : '';
    $bh = isset($b['hire_date']) ? trim((string)$b['hire_date']) : '';
    if ($ah === '' && $bh !== '') return 1;
    if ($ah !== '' && $bh === '') return -1;
    if ($ah !== $bh) return ($ah < $bh) ? -1 : 1;

    $an = isset($a['name']) ? trim((string)$a['name']) : '';
    $bn = isset($b['name']) ? trim((string)$b['name']) : '';
    return strcmp($an, $bn);
}}

if (!function_exists('cpms_employee_directory_birthday')) {
function cpms_employee_directory_birthday($value) {
    $value = trim((string)$value);
    if ($value === '' || $value === '0000-00-00') {
        return array('display' => '-', 'days' => null, 'highlight' => false);
    }

    $month = 0;
    $day = 0;
    if (preg_match('/^\d{4}-(\d{1,2})-(\d{1,2})$/', $value, $m)) {
        $month = (int)$m[1];
        $day = (int)$m[2];
    } elseif (preg_match('/(\d{1,2})\D+(\d{1,2})/', $value, $m)) {
        $month = (int)$m[1];
        $day = (int)$m[2];
    }

    if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
        return array('display' => $value, 'days' => null, 'highlight' => false);
    }

    try {
        $today = new DateTime('today');
        $birthday = DateTime::createFromFormat('Y-m-d', $today->format('Y') . '-' . sprintf('%02d', $month) . '-' . sprintf('%02d', $day));
        if (!$birthday) return array('display' => sprintf('%02d월 %02d일', $month, $day), 'days' => null, 'highlight' => false);
        if ($birthday < $today) $birthday->modify('+1 year');
        $days = (int)$today->diff($birthday)->days;
        return array(
            'display' => sprintf('%02d월 %02d일 (+%d일)', $month, $day, $days),
            'days' => $days,
            'highlight' => ($days <= 15),
        );
    } catch (Exception $e) {
        return array('display' => sprintf('%02d월 %02d일', $month, $day), 'days' => null, 'highlight' => false);
    }
}}

if (!function_exists('cpms_employee_directory_initial')) {
function cpms_employee_directory_initial($name) {
    $name = trim((string)$name);
    if ($name === '') return '?';
    if (function_exists('mb_substr')) return mb_substr($name, 0, 1, 'UTF-8');
    return substr($name, 0, 1);
}}

if (!function_exists('cpms_employee_directory_photo_src')) {
function cpms_employee_directory_photo_src($photoPath) {
    $photoPath = trim((string)$photoPath);
    if ($photoPath === '') return '';
    if (preg_match('/^https?:\/\//i', $photoPath) || substr($photoPath, 0, 1) === '/') return $photoPath;
    return asset_url($photoPath);
}}

if (!function_exists('cpms_employee_directory_value')) {
function cpms_employee_directory_value($value, $fallback) {
    $value = trim((string)$value);
    return $value !== '' ? $value : $fallback;
}}

if ($pdo && cpms_employee_directory_table_exists($pdo)) {
    try {
        $isActiveExists = cpms_employee_directory_column_exists($pdo, 'is_active');
        $selectParts = array(
            'id',
            'email',
            'name',
            'department',
            cpms_employee_directory_select_column($pdo, 'position', 'position', "''"),
            cpms_employee_directory_select_column($pdo, 'role', 'role', "'employee'"),
            cpms_employee_directory_select_column($pdo, 'is_active', 'is_active', '1'),
            cpms_employee_directory_select_column($pdo, 'hire_date', 'hire_date', 'NULL'),
            cpms_employee_directory_select_column($pdo, 'birth_date', 'birth_date', 'NULL'),
            cpms_employee_directory_select_column($pdo, 'photo_path', 'photo_path', "''"),
            cpms_employee_directory_first_select_column($pdo, array('employee_no', 'employee_number', 'emp_no', 'staff_no'), 'employee_no', "''"),
            cpms_employee_directory_first_select_column($pdo, array('phone', 'mobile', 'phone_number', 'tel'), 'phone', "''"),
            cpms_employee_directory_first_select_column($pdo, array('work_location', 'office_location', 'work_site', 'location'), 'work_location', "''"),
        );
        $sql = 'SELECT ' . implode(',', $selectParts) . ' FROM employees';
        if ($isActiveExists) $sql .= ' WHERE is_active = 1';
        $sql .= ' ORDER BY name ASC LIMIT 1000';
        $st = $pdo->query($sql);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) $rows = array();
    } catch (Exception $e) {
        $rows = array();
        $loadError = '임직원 명부를 불러오는 중 오류가 발생했습니다: ' . $e->getMessage();
    }
} else {
    $loadError = '직원명부 테이블을 찾을 수 없습니다.';
}

$sections = array(
    'CEO' => array(),
    '임원' => array(),
);
$rankGroups = array();
$filterCounts = array('전체' => 0);

for ($i = 0; $i < count($rows); $i++) {
    $row = $rows[$i];
    $row['department'] = cpms_employee_directory_normalize_dept(isset($row['department']) ? $row['department'] : '');
    $row['position'] = cpms_employee_directory_value(isset($row['position']) ? $row['position'] : '', '');
    if (cpms_employee_directory_is_ceo($row)) {
        $sectionName = 'CEO';
        $sections['CEO'][] = $row;
    } elseif (cpms_employee_directory_is_executive($row)) {
        $sectionName = '임원';
        $sections['임원'][] = $row;
    } else {
        $sectionName = cpms_employee_directory_value(isset($row['position']) ? $row['position'] : '', '직원');
        if (!isset($rankGroups[$sectionName])) $rankGroups[$sectionName] = array();
        $rankGroups[$sectionName][] = $row;
    }

    $filter = cpms_employee_directory_filter_group(isset($row['department']) ? $row['department'] : '', $sectionName);
    if (!isset($filterCounts[$filter])) $filterCounts[$filter] = 0;
    $filterCounts[$filter]++;
    $filterCounts['전체']++;
}

foreach ($sections as $sectionKey => $sectionRows) {
    usort($sections[$sectionKey], 'cpms_employee_directory_compare_rows');
}

uksort($rankGroups, function($a, $b) {
    $aw = cpms_employee_directory_position_weight($a);
    $bw = cpms_employee_directory_position_weight($b);
    if ($aw !== $bw) return ($aw < $bw) ? -1 : 1;
    return strcmp($a, $b);
});
foreach ($rankGroups as $rank => $rankRows) {
    usort($rankGroups[$rank], 'cpms_employee_directory_compare_rows');
}

$filterButtons = array('전체', 'CEO', '임원', '공무', '공사', '관리', '품질/안전', '개발', '기타');
$initialSearch = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
?>

<style>
.employee-directory-page{margin:-32px;padding:28px 32px 48px;background:#f7f9fb;min-height:calc(100vh - 78px);color:#222;font-family:"Noto Sans KR",-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
.employee-directory-title{margin:0 0 14px;text-align:center;font-size:26px;line-height:1.25;font-weight:800;color:#1a2a44}
.employee-directory-toolbar{display:flex;flex-direction:column;align-items:center;gap:12px;margin-bottom:20px}
.employee-directory-filters{display:flex;flex-wrap:wrap;justify-content:center;gap:8px}
.employee-directory-filter{min-height:32px;padding:6px 14px;border:1px solid #c7cdd7;border-radius:6px;background:#fff;color:#1f2937;font-size:14px;font-weight:700;cursor:pointer}
.employee-directory-filter.is-active{background:#1a2a44;border-color:#1a2a44;color:#fff}
.employee-directory-search{width:280px;max-width:100%;height:34px;padding:6px 10px;border:1px solid #9ca3af;background:#fff;color:#111827;font-size:14px;text-align:left}
.employee-directory-section{margin-top:28px}
.employee-directory-section-title{margin:0 0 12px;border-left:5px solid #1a2a44;padding-left:8px;color:#1a2a44;font-size:16px;line-height:1.2;font-weight:800}
.employee-directory-card-grid{display:grid;grid-template-columns:repeat(auto-fill,194px);gap:8px;align-items:start;justify-content:start}
.employee-directory-card{position:relative;width:194px;min-height:302px;padding:6px;border:1px solid #111;border-radius:8px;background:#fff;box-shadow:0 2px 5px rgba(0,0,0,.14);overflow:hidden;text-align:center;transition:transform .18s ease,box-shadow .18s ease}
.employee-directory-card:hover{transform:translateY(-2px);box-shadow:0 6px 14px rgba(15,23,42,.16)}
.employee-directory-card.is-birthday{background:#ffe9ef}
.employee-directory-card[data-filter="공사"]{border-color:#007a33}
.employee-directory-card[data-filter="공무"]{border-color:#d32f2f}
.employee-directory-card[data-filter="관리"]{border-color:#f57c00}
.employee-directory-card[data-filter="품질/안전"]{border-color:#1565c0}
.employee-directory-card[data-filter="CEO"],.employee-directory-card[data-filter="임원"]{border-color:#000}
.employee-directory-birthday-marker{position:absolute;left:6px;top:6px;z-index:2;display:flex;width:22px;height:22px;align-items:center;justify-content:center;color:#f97316}
.employee-directory-birthday-marker svg{width:20px;height:20px;stroke-width:2.3}
.employee-directory-photo{position:relative;width:100%;height:174px;border-radius:4px;background:#f1f1f1;display:flex;align-items:center;justify-content:center;overflow:hidden}
.employee-directory-photo img{position:absolute;inset:0;width:100%;height:100%;object-fit:contain;object-position:center center;background:#f1f1f1}
.employee-directory-photo-fallback{display:flex;width:76px;height:76px;align-items:center;justify-content:center;border-radius:999px;background:#dbeafe;color:#1d4ed8;font-size:34px;font-weight:900}
.employee-directory-name{margin-top:8px;color:#000;font-size:12px;line-height:1.25;font-weight:800;word-break:keep-all;overflow-wrap:anywhere}
.employee-directory-dept{margin-top:2px;color:#111827;font-size:12px;line-height:1.2;font-weight:800}
.employee-directory-info{margin-top:4px;color:#333;font-size:11px;line-height:1.42}
.employee-directory-line{display:flex;min-height:15px;align-items:center;justify-content:center;gap:3px;white-space:normal;word-break:keep-all;overflow-wrap:anywhere}
.employee-directory-line svg{width:11px;height:11px;flex:0 0 auto;stroke-width:2.2}
.employee-directory-line a{color:#0044cc;text-decoration:none}
.employee-directory-empty{margin-top:18px;padding:18px;border:1px solid #e5e7eb;border-radius:12px;background:#fff;color:#6b7280;font-weight:700;text-align:center}
@media (max-width:767px){
  .employee-directory-page{margin:-14px;padding:18px 14px 110px;min-height:calc(100vh - 60px)}
  .employee-directory-title{font-size:22px}
  .employee-directory-toolbar{align-items:stretch}
  .employee-directory-filters{justify-content:flex-start;overflow-x:auto;flex-wrap:nowrap;padding-bottom:2px}
  .employee-directory-filter{flex:0 0 auto}
  .employee-directory-search{width:100%;height:42px;font-size:16px}
  .employee-directory-card-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
  .employee-directory-card{width:100%;min-height:290px}
  .employee-directory-photo{height:164px}
}
</style>

<div class="employee-directory-page">
  <h2 class="employee-directory-title">임직원 연락처</h2>

  <div class="employee-directory-toolbar">
    <div class="employee-directory-filters" aria-label="임직원 필터">
      <?php for ($i = 0; $i < count($filterButtons); $i++): ?>
        <?php $filterLabel = $filterButtons[$i]; ?>
        <?php if ($filterLabel !== '전체' && (!isset($filterCounts[$filterLabel]) || (int)$filterCounts[$filterLabel] < 1)) continue; ?>
        <button type="button" class="employee-directory-filter<?php echo $filterLabel === '전체' ? ' is-active' : ''; ?>" data-employee-filter="<?php echo h($filterLabel); ?>">
          <?php echo h($filterLabel); ?>
        </button>
      <?php endfor; ?>
    </div>
    <input type="text" id="employeeDirectorySearch" class="employee-directory-search" value="<?php echo h($initialSearch); ?>" placeholder="이름, 사번, 부서, 직급, 위치, 이메일 검색">
  </div>

  <?php if ($loadError !== ''): ?>
    <div class="employee-directory-empty"><?php echo h($loadError); ?></div>
  <?php elseif (count($rows) < 1): ?>
    <div class="employee-directory-empty">표시할 임직원이 없습니다.</div>
  <?php else: ?>
    <?php
      $renderSections = array();
      if (count($sections['CEO']) > 0) $renderSections[] = array('label' => 'CEO', 'rows' => $sections['CEO']);
      if (count($sections['임원']) > 0) $renderSections[] = array('label' => '임원', 'rows' => $sections['임원']);
      foreach ($rankGroups as $rank => $rankRows) {
          if (count($rankRows) > 0) $renderSections[] = array('label' => $rank, 'rows' => $rankRows);
      }
    ?>
    <?php for ($s = 0; $s < count($renderSections); $s++): ?>
      <?php $section = $renderSections[$s]; ?>
      <div class="employee-directory-section" data-employee-section>
        <div class="employee-directory-section-title"><?php echo h($section['label']); ?></div>
        <div class="employee-directory-card-grid">
          <?php for ($i = 0; $i < count($section['rows']); $i++): ?>
            <?php
              $emp = $section['rows'][$i];
              $name = cpms_employee_directory_value(isset($emp['name']) ? $emp['name'] : '', '-');
              $position = cpms_employee_directory_value(isset($emp['position']) ? $emp['position'] : '', '');
              $department = cpms_employee_directory_value(isset($emp['department']) ? $emp['department'] : '', '-');
              $sectionLabel = isset($section['label']) ? (string)$section['label'] : '';
              $cardDeptLabel = ($sectionLabel === 'CEO' || $sectionLabel === '임원') ? $sectionLabel : $department;
              $birthday = cpms_employee_directory_birthday(isset($emp['birth_date']) ? $emp['birth_date'] : '');
              $photoSrc = cpms_employee_directory_photo_src(isset($emp['photo_path']) ? $emp['photo_path'] : '');
              $employeeNo = cpms_employee_directory_value(isset($emp['employee_no']) ? $emp['employee_no'] : '', '-');
              $workLocation = cpms_employee_directory_value(isset($emp['work_location']) ? $emp['work_location'] : '', '-');
              $hireDate = cpms_employee_directory_value(isset($emp['hire_date']) ? $emp['hire_date'] : '', '-');
              $email = cpms_employee_directory_value(isset($emp['email']) ? $emp['email'] : '', '-');
              $phone = cpms_employee_directory_value(isset($emp['phone']) ? $emp['phone'] : '', '-');
              $filterGroup = cpms_employee_directory_filter_group($department, $sectionLabel);
              $nameLine = $position !== '' ? ($name . ' (' . $position . ')') : $name;
              $searchText = $name . ' ' . $employeeNo . ' ' . $position . ' ' . $department . ' ' . $workLocation . ' ' . $email . ' ' . $phone . ' ' . $cardDeptLabel;
            ?>
            <div class="employee-directory-card<?php echo !empty($birthday['highlight']) ? ' is-birthday' : ''; ?>" data-filter="<?php echo h($filterGroup); ?>" data-search="<?php echo h($searchText); ?>">
              <?php if (!empty($birthday['highlight'])): ?>
                <div class="employee-directory-birthday-marker"><i data-lucide="cake"></i></div>
              <?php endif; ?>
              <div class="employee-directory-photo">
                <span class="employee-directory-photo-fallback"><?php echo h(cpms_employee_directory_initial($name)); ?></span>
                <?php if ($photoSrc !== ''): ?>
                  <img src="<?php echo h($photoSrc); ?>" alt="<?php echo h($name); ?>" onerror="this.style.display='none';">
                <?php endif; ?>
              </div>
              <div class="employee-directory-name"><?php echo h($nameLine); ?></div>
              <div class="employee-directory-dept"><?php echo h($cardDeptLabel); ?></div>
              <div class="employee-directory-info">
                <div class="employee-directory-line"><i data-lucide="badge"></i><span>사번 <?php echo h($employeeNo); ?></span></div>
                <div class="employee-directory-line"><i data-lucide="cake"></i><span><?php echo h($birthday['display']); ?></span></div>
                <div class="employee-directory-line"><i data-lucide="building-2"></i><span><?php echo h($workLocation); ?></span></div>
                <div class="employee-directory-line"><i data-lucide="calendar-days"></i><span><?php echo h($hireDate); ?></span></div>
                <div class="employee-directory-line"><i data-lucide="mail"></i><?php if ($email !== '-'): ?><a href="mailto:<?php echo h($email); ?>"><?php echo h($email); ?></a><?php else: ?><span>-</span><?php endif; ?></div>
                <div class="employee-directory-line"><i data-lucide="phone"></i><?php if ($phone !== '-'): ?><a href="tel:<?php echo h($phone); ?>"><?php echo h($phone); ?></a><?php else: ?><span>-</span><?php endif; ?></div>
              </div>
            </div>
          <?php endfor; ?>
        </div>
      </div>
    <?php endfor; ?>
    <div id="employeeDirectoryEmpty" class="employee-directory-empty" style="display:none;">검색 결과가 없습니다.</div>
  <?php endif; ?>
</div>

<script>
(function(){
    var activeFilter = '전체';
    var buttons = document.querySelectorAll ? document.querySelectorAll('[data-employee-filter]') : [];
    var cards = document.querySelectorAll ? document.querySelectorAll('.employee-directory-card') : [];
    var sections = document.querySelectorAll ? document.querySelectorAll('[data-employee-section]') : [];
    var search = document.getElementById ? document.getElementById('employeeDirectorySearch') : null;
    var empty = document.getElementById ? document.getElementById('employeeDirectoryEmpty') : null;

    function trimText(value) {
        return (value || '').replace(/^\s+|\s+$/g, '');
    }

    function hasClass(el, className) {
        return el && (' ' + el.className + ' ').indexOf(' ' + className + ' ') >= 0;
    }

    function addClass(el, className) {
        if (el && !hasClass(el, className)) el.className += ' ' + className;
    }

    function removeClass(el, className) {
        if (!el) return;
        el.className = (' ' + el.className + ' ').replace(' ' + className + ' ', ' ').replace(/^\s+|\s+$/g, '');
    }

    function applyFilter() {
        var query = search ? trimText(search.value).toLowerCase() : '';
        var visibleCount = 0;
        for (var i = 0; i < cards.length; i++) {
            var card = cards[i];
            var group = card.getAttribute('data-filter') || '';
            var haystack = (card.getAttribute('data-search') || card.innerText || '').toLowerCase();
            var filterOk = (activeFilter === '전체' || group === activeFilter);
            var searchOk = (query === '' || haystack.indexOf(query) >= 0);
            var show = filterOk && searchOk;
            card.style.display = show ? '' : 'none';
            if (show) visibleCount++;
        }
        for (var s = 0; s < sections.length; s++) {
            var sectionCards = sections[s].querySelectorAll ? sections[s].querySelectorAll('.employee-directory-card') : [];
            var sectionVisible = false;
            for (var c = 0; c < sectionCards.length; c++) {
                if (sectionCards[c].style.display !== 'none') {
                    sectionVisible = true;
                    break;
                }
            }
            sections[s].style.display = sectionVisible ? '' : 'none';
        }
        if (empty) empty.style.display = visibleCount > 0 ? 'none' : '';
    }

    for (var i = 0; i < buttons.length; i++) {
        buttons[i].onclick = function(){
            activeFilter = this.getAttribute('data-employee-filter') || '전체';
            for (var b = 0; b < buttons.length; b++) removeClass(buttons[b], 'is-active');
            addClass(this, 'is-active');
            applyFilter();
        };
    }
    if (search) search.onkeyup = applyFilter;
    applyFilter();
})();
</script>
