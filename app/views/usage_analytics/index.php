<?php
/**
 * C:\www\cpms\app\views\usage_analytics\index.php
 * - CPMS 사용현황 통계, 필터, 설치/정리 관리 화면
 * - PHP 5.6 호환
 */

$usageInstalled = !empty($usageInstalled);
$usageData = isset($usageData) && is_array($usageData) ? $usageData : array();
$usageFilters = isset($usageFilters) && is_array($usageFilters) ? $usageFilters : array();
$usageLoadError = isset($usageLoadError) ? (string)$usageLoadError : '';
$usageDetail = isset($usageDetail) && is_array($usageDetail) ? $usageDetail : null;
$usageReviewTargets = isset($usageReviewTargets) && is_array($usageReviewTargets) ? $usageReviewTargets : array();

if (!function_exists('cpms_usage_view_datetime')) {
function cpms_usage_view_datetime($value) {
    $value = trim((string)$value);
    if ($value === '') return '-';
    $timestamp = strtotime($value);
    return $timestamp ? date('Y-m-d H:i', $timestamp) : $value;
}}
if (!function_exists('cpms_usage_view_query')) {
function cpms_usage_view_query($overrides) {
    $params = $_GET;
    if (!is_array($params)) $params = array();
    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') unset($params[$key]);
        else $params[$key] = $value;
    }
    return '?' . http_build_query($params, '', '&');
}}

$usageCssPath = dirname(dirname(dirname(__DIR__))) . '/public/assets/css/usage_analytics.css';
$usageJsPath = dirname(dirname(dirname(__DIR__))) . '/public/assets/js/usage_analytics.js';
?>
<link rel="stylesheet" href="<?php echo h(asset_url('assets/css/usage_analytics.css') . '?v=' . (string)@filemtime($usageCssPath)); ?>">
<script defer src="<?php echo h(asset_url('assets/js/usage_analytics.js') . '?v=' . (string)@filemtime($usageJsPath)); ?>"></script>

<div class="ua-page">
  <div class="ua-heading">
    <div>
      <div class="ua-eyebrow">접속 및 메뉴 이용 기록</div>
      <h1>CPMS 사용현황 분석</h1>
      <p>상단 오늘 카드와 선택 기간 통계를 구분해 표시합니다. 상세 활동기록은 기본 180일 동안 보관합니다.</p>
    </div>
    <?php if ($usageInstalled): ?>
      <div class="ua-heading-actions">
        <?php
          $exportParams = $_GET;
          if (!is_array($exportParams)) $exportParams = array();
          $exportParams['r'] = 'usage_analytics/export';
          unset($exportParams['user_id'], $exportParams['detail_page']);
        ?>
        <a class="ua-button ua-button-secondary" href="?<?php echo h(http_build_query($exportParams, '', '&')); ?>">
          <i data-lucide="download"></i> 조회 로그 내보내기
        </a>
        <form method="post" action="?r=usage_analytics/setup">
          <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
          <button class="ua-button ua-button-secondary" type="submit"><i data-lucide="database"></i> 설치/업데이트 확인</button>
        </form>
        <form method="post" action="?r=usage_analytics/cleanup" data-usage-cleanup-form data-cutoff="<?php echo h(\App\Services\UsageAnalyticsService::retentionCutoffText()); ?>">
          <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
          <button class="ua-button ua-button-danger" type="submit"><i data-lucide="trash-2"></i> 180일 지난 상세 로그 정리</button>
        </form>
      </div>
    <?php endif; ?>
  </div>

  <?php if (!$usageInstalled): ?>
    <section class="ua-install-panel">
      <div class="ua-install-icon"><i data-lucide="database-zap"></i></div>
      <div>
        <h2>사용기록 기능 설치가 필요합니다</h2>
        <p>버튼을 누르면 접속 세션과 활동기록 테이블 및 인덱스를 자동으로 생성하거나 필요한 항목만 업데이트합니다. 여러 번 실행해도 중복 생성되지 않습니다.</p>
      </div>
      <form method="post" action="?r=usage_analytics/setup">
        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
        <button class="ua-button ua-button-primary" type="submit"><i data-lucide="wrench"></i> 사용기록 기능 설치/업데이트</button>
      </form>
    </section>
  <?php else: ?>
    <?php if ($usageLoadError !== ''): ?>
      <div class="ua-alert ua-alert-danger"><?php echo h($usageLoadError); ?></div>
    <?php elseif (count($usageData) > 0): ?>
      <?php
        $summary = isset($usageData['summary']) ? $usageData['summary'] : array();
        $menuStats = isset($usageData['menu_stats']) ? $usageData['menu_stats'] : array();
        $tabStats = isset($usageData['tab_stats']) ? $usageData['tab_stats'] : array();
        $employeeRows = isset($usageData['employee_rows']) ? $usageData['employee_rows'] : array();
        $knownMenus = isset($usageData['known_menus']) ? $usageData['known_menus'] : array();
        $knownTabs = isset($usageData['known_tabs']) ? $usageData['known_tabs'] : array();
        $selectedTabMenu = isset($usageData['selected_menu']) ? (string)$usageData['selected_menu'] : 'dashboard';
        $selectedTabMenuName = isset($knownMenus[$selectedTabMenu]) ? $knownMenus[$selectedTabMenu] : $selectedTabMenu;
        $topMenu = count($menuStats) > 0 ? $menuStats[0] : null;
        $leastMenu = count($menuStats) > 0 ? $menuStats[count($menuStats) - 1] : null;
        $topTab = count($tabStats) > 0 ? $tabStats[0] : null;
        $leastTab = count($tabStats) > 0 ? $tabStats[count($tabStats) - 1] : null;
      ?>

      <?php if ($usageDetail): ?>
        <?php require __DIR__ . '/user_detail.php'; ?>
      <?php endif; ?>

      <form class="ua-filter-panel" method="get" action="" data-usage-filter-form>
        <input type="hidden" name="r" value="usage_analytics">
        <div class="ua-filter-grid">
          <label><span>조회 기간</span>
            <select name="period" data-usage-period>
              <option value="today" <?php echo $usageFilters['period'] === 'today' ? 'selected' : ''; ?>>오늘</option>
              <option value="7d" <?php echo $usageFilters['period'] === '7d' ? 'selected' : ''; ?>>최근 7일</option>
              <option value="30d" <?php echo $usageFilters['period'] === '30d' ? 'selected' : ''; ?>>최근 30일</option>
              <option value="month" <?php echo $usageFilters['period'] === 'month' ? 'selected' : ''; ?>>이번 달</option>
              <option value="custom" <?php echo $usageFilters['period'] === 'custom' ? 'selected' : ''; ?>>사용자 지정 기간</option>
            </select>
          </label>
          <label data-usage-custom-date><span>시작일</span><input type="date" name="date_from" value="<?php echo h($usageFilters['date_from']); ?>"></label>
          <label data-usage-custom-date><span>종료일</span><input type="date" name="date_to" value="<?php echo h($usageFilters['date_to']); ?>"></label>
          <label><span>직원 이름</span><input type="search" name="q" maxlength="50" value="<?php echo h($usageFilters['q']); ?>" placeholder="이름 검색"></label>
          <label><span>부서</span>
            <select name="department"><option value="">전체</option><?php foreach ($usageData['departments'] as $department): ?><option value="<?php echo h($department); ?>" <?php echo $usageFilters['department'] === $department ? 'selected' : ''; ?>><?php echo h($department); ?></option><?php endforeach; ?></select>
          </label>
          <label><span>직급</span>
            <select name="position"><option value="">전체</option><?php foreach ($usageData['positions'] as $position): ?><option value="<?php echo h($position); ?>" <?php echo $usageFilters['position'] === $position ? 'selected' : ''; ?>><?php echo h($position); ?></option><?php endforeach; ?></select>
          </label>
          <label><span>메뉴</span>
            <select name="menu" data-usage-menu><option value="">전체</option><?php foreach ($knownMenus as $menuKey => $menuName): ?><option value="<?php echo h($menuKey); ?>" <?php echo $usageFilters['menu'] === $menuKey ? 'selected' : ''; ?>><?php echo h($menuName); ?></option><?php endforeach; ?></select>
          </label>
          <label><span>탭</span>
            <select name="tab"><option value="">전체</option><?php foreach ($knownTabs as $tabKey => $tabName): ?><option value="<?php echo h($tabKey); ?>" <?php echo $usageFilters['tab'] === $tabKey ? 'selected' : ''; ?>><?php echo h($tabName); ?></option><?php endforeach; ?></select>
          </label>
          <label><span>현재 접속</span><select name="online"><option value="all">전체</option><option value="yes" <?php echo $usageFilters['online'] === 'yes' ? 'selected' : ''; ?>>접속 중</option><option value="no" <?php echo $usageFilters['online'] === 'no' ? 'selected' : ''; ?>>미접속</option></select></label>
          <label><span>기간 내 접속</span><select name="connected"><option value="all">전체</option><option value="yes" <?php echo $usageFilters['connected'] === 'yes' ? 'selected' : ''; ?>>접속</option><option value="no" <?php echo $usageFilters['connected'] === 'no' ? 'selected' : ''; ?>>미접속</option></select></label>
          <label><span>직원 정렬</span><select name="sort"><option value="activity_desc" <?php echo $usageFilters['sort'] === 'activity_desc' ? 'selected' : ''; ?>>활동 수 많은 순</option><option value="access_desc" <?php echo $usageFilters['sort'] === 'access_desc' ? 'selected' : ''; ?>>접속 횟수 많은 순</option><option value="last_asc" <?php echo $usageFilters['sort'] === 'last_asc' ? 'selected' : ''; ?>>마지막 접속 오래된 순</option><option value="name" <?php echo $usageFilters['sort'] === 'name' ? 'selected' : ''; ?>>이름순</option></select></label>
        </div>
        <div class="ua-filter-actions">
          <div><strong><?php echo h($usageFilters['period_label']); ?></strong> · <?php echo h($usageFilters['date_from']); ?> ~ <?php echo h($usageFilters['date_to']); ?></div>
          <div><a class="ua-button ua-button-secondary" href="?r=usage_analytics">초기화</a><button class="ua-button ua-button-primary" type="submit"><i data-lucide="search"></i> 조회</button></div>
        </div>
      </form>

      <section class="ua-summary-section" id="today-summary">
        <div class="ua-section-title"><div><span>항상 오늘 기준</span><h2>오늘 접속 요약</h2></div><p>선택 기간 필터와 관계없이 오늘 00:00 이후 기록입니다.</p></div>
        <div class="ua-summary-grid">
          <a href="#today-connected" class="ua-summary-card ua-accent-blue"><span>오늘 접속자</span><strong><?php echo number_format(isset($summary['today_connected_users']) ? $summary['today_connected_users'] : 0); ?>명</strong><small>활성 직원 · 계정 등록자</small></a>
          <a href="#today-not-connected" class="ua-summary-card ua-accent-amber"><span>오늘 미접속자</span><strong><?php echo number_format(isset($summary['today_not_connected_users']) ? $summary['today_not_connected_users'] : 0); ?>명</strong><small>활성 직원 · 계정 등록자</small></a>
          <a href="#employee-usage" class="ua-summary-card ua-accent-green"><span>현재 접속 중</span><strong><?php echo number_format(isset($summary['currently_online_users']) ? $summary['currently_online_users'] : 0); ?>명</strong><small>마지막 활동 10분 이내 · 직원 중복 제거</small></a>
          <a href="#employee-usage" class="ua-summary-card ua-accent-violet"><span>오늘 총 접속 횟수</span><strong><?php echo number_format(isset($summary['today_connection_count']) ? $summary['today_connection_count'] : 0); ?>회</strong><small>새 세션 또는 30분 후 재접속</small></a>
          <a href="#daily-trend" class="ua-summary-card ua-accent-rose"><span>오늘 총 활동 수</span><strong><?php echo number_format(isset($summary['today_activity_count']) ? $summary['today_activity_count'] : 0); ?>회</strong><small>중복 새로고침 제외</small></a>
          <a href="#department-usage" class="ua-summary-card ua-accent-cyan"><span>이번 달 활성 사용자</span><strong><?php echo number_format(isset($summary['month_active_users']) ? $summary['month_active_users'] : 0); ?>명</strong><small>이번 달 접속 기록 보유 직원</small></a>
        </div>
      </section>

      <section class="ua-insight-grid">
        <article><span>접속 횟수가 가장 많은 직원</span><strong><?php echo $usageData['most_connections'] && (int)$usageData['most_connections']['period_connections'] > 0 ? h($usageData['most_connections']['name']) : '-'; ?></strong><small><?php echo $usageData['most_connections'] ? number_format($usageData['most_connections']['period_connections']) . '회' : '기록 없음'; ?></small></article>
        <article><span>실제 활동이 가장 많은 직원</span><strong><?php echo $usageData['most_activities'] && (int)$usageData['most_activities']['period_activities'] > 0 ? h($usageData['most_activities']['name']) : '-'; ?></strong><small><?php echo $usageData['most_activities'] ? number_format($usageData['most_activities']['period_activities']) . '회' : '기록 없음'; ?></small></article>
        <article><span>가장 많이 사용한 메뉴</span><strong><?php echo $topMenu && (int)$topMenu['usage_count'] > 0 ? h($topMenu['menu_name']) : '-'; ?></strong><small><?php echo $topMenu ? number_format($topMenu['usage_count']) . '회' : '기록 없음'; ?></small></article>
        <article><span>가장 적게 사용한 메뉴</span><strong><?php echo $leastMenu ? h($leastMenu['menu_name']) : '-'; ?></strong><small><?php echo $leastMenu ? number_format($leastMenu['usage_count']) . '회' : '기록 없음'; ?></small></article>
      </section>

      <div class="ua-two-columns">
        <section class="ua-panel" id="today-connected">
          <div class="ua-panel-title"><div><span>오늘 기준</span><h2>오늘 접속 직원</h2></div><b><?php echo count($usageData['today_connected']); ?>명</b></div>
          <div class="ua-table-wrap"><table><thead><tr><th>이름</th><th>부서</th><th>직급</th><th>접속 횟수</th><th>마지막 활동</th><th>상태</th></tr></thead><tbody>
            <?php if (count($usageData['today_connected']) === 0): ?><tr><td colspan="6" class="ua-empty">오늘 접속한 직원이 없습니다.</td></tr><?php endif; ?>
            <?php foreach ($usageData['today_connected'] as $row): ?><tr><td><a href="<?php echo h(cpms_usage_view_query(array('user_id' => $row['id'], 'detail_page' => null))); ?>"><?php echo h($row['name']); ?></a></td><td><?php echo h($row['department']); ?></td><td><?php echo h($row['position']); ?></td><td><?php echo number_format($row['today_connections']); ?></td><td><?php echo h(cpms_usage_view_datetime($row['last_activity_at'])); ?></td><td><span class="ua-status <?php echo $row['is_online'] ? 'is-online' : ''; ?>"><?php echo $row['is_online'] ? '접속 중' : '오프라인'; ?></span></td></tr><?php endforeach; ?>
          </tbody></table></div>
        </section>

        <section class="ua-panel" id="today-not-connected">
          <div class="ua-panel-title"><div><span>오늘 기준</span><h2>오늘 미접속 직원</h2></div><b><?php echo count($usageData['today_not_connected']); ?>명</b></div>
          <div class="ua-table-wrap"><table><thead><tr><th>이름</th><th>부서</th><th>직급</th><th>마지막 접속일</th><th>경과일</th></tr></thead><tbody>
            <?php if (count($usageData['today_not_connected']) === 0): ?><tr><td colspan="5" class="ua-empty">오늘 미접속 직원이 없습니다.</td></tr><?php endif; ?>
            <?php foreach ($usageData['today_not_connected'] as $row): ?><tr><td><a href="<?php echo h(cpms_usage_view_query(array('user_id' => $row['id'], 'detail_page' => null))); ?>"><?php echo h($row['name']); ?></a></td><td><?php echo h($row['department']); ?></td><td><?php echo h($row['position']); ?></td><td><?php echo h(cpms_usage_view_datetime($row['last_activity_at'])); ?></td><td><?php echo $row['days_since_last'] === null ? '-' : number_format($row['days_since_last']) . '일'; ?></td></tr><?php endforeach; ?>
          </tbody></table></div>
        </section>
      </div>

      <section class="ua-panel ua-account-panel">
        <div class="ua-panel-title"><div><span>활성 직원 중 이메일 없음</span><h2>CPMS 계정 미등록</h2></div><b><?php echo count($usageData['account_unregistered']); ?>명</b></div>
        <?php if (count($usageData['account_unregistered']) === 0): ?><div class="ua-empty-box">계정 미등록 직원이 없습니다.</div><?php else: ?><div class="ua-chip-list"><?php foreach ($usageData['account_unregistered'] as $row): ?><span><?php echo h($row['name']); ?> · <?php echo h($row['department']); ?> · <?php echo h($row['position']); ?></span><?php endforeach; ?></div><?php endif; ?>
      </section>

      <section class="ua-panel" id="employee-usage">
        <div class="ua-panel-title"><div><span><?php echo h($usageFilters['period_label']); ?></span><h2>직원별 사용현황</h2></div><b>최대 500명 표시</b></div>
        <div class="ua-table-wrap"><table><thead><tr><th>이름</th><th>부서</th><th>직급</th><th>오늘 접속</th><th>기간 접속</th><th>기간 활동</th><th>메뉴 수</th><th>마지막 활동</th><th>현재 상태</th></tr></thead><tbody>
          <?php if (count($employeeRows) === 0): ?><tr><td colspan="9" class="ua-empty">조건에 맞는 직원이 없습니다.</td></tr><?php endif; ?>
          <?php foreach ($employeeRows as $row): ?><tr><td><a class="ua-user-link" href="<?php echo h(cpms_usage_view_query(array('user_id' => $row['id'], 'detail_page' => null))); ?>"><?php echo h($row['name']); ?></a></td><td><?php echo h($row['department']); ?></td><td><?php echo h($row['position']); ?></td><td><?php echo number_format($row['today_connections']); ?></td><td><?php echo number_format($row['period_connections']); ?></td><td><strong><?php echo number_format($row['period_activities']); ?></strong></td><td><?php echo number_format($row['menu_count']); ?></td><td><?php echo h(cpms_usage_view_datetime($row['last_activity_at'])); ?></td><td><span class="ua-status <?php echo $row['is_online'] ? 'is-online' : ''; ?>"><?php echo $row['is_online'] ? '접속 중' : '오프라인'; ?></span></td></tr><?php endforeach; ?>
        </tbody></table></div>
      </section>

      <div class="ua-two-columns ua-rank-columns">
        <section class="ua-panel" id="menu-ranking">
          <div class="ua-panel-title"><div><span>최상위 메뉴</span><h2>메뉴 사용 순위</h2></div><b><?php echo h($usageFilters['period_label']); ?></b></div>
          <div class="ua-table-wrap"><table><thead><tr><th>순위</th><th>메뉴</th><th>이용</th><th>직원 수</th><th>1명당 평균</th><th>비율</th><th>이전 대비</th></tr></thead><tbody>
            <?php foreach ($menuStats as $row): ?><tr><td><?php echo number_format($row['rank']); ?></td><td><a href="<?php echo h(cpms_usage_view_query(array('menu' => $row['menu_key'], 'tab' => null, 'user_id' => null, 'detail_page' => null))); ?>"><?php echo h($row['menu_name']); ?></a></td><td><strong><?php echo number_format($row['usage_count']); ?></strong></td><td><?php echo number_format($row['user_count']); ?></td><td><?php echo number_format($row['average_per_user'], 1); ?></td><td><?php echo number_format($row['ratio'], 1); ?>%</td><td><span class="ua-change <?php echo $row['change_percent'] > 0 ? 'up' : ($row['change_percent'] < 0 ? 'down' : ''); ?>"><?php echo $row['change_percent'] > 0 ? '+' : ''; ?><?php echo number_format($row['change_percent'], 1); ?>%</span></td></tr><?php endforeach; ?>
          </tbody></table></div>
        </section>

        <section class="ua-panel" id="tab-ranking">
          <div class="ua-panel-title"><div><span><?php echo h($selectedTabMenuName); ?> 하위 탭</span><h2>탭 사용 순위</h2></div><a href="<?php echo h(cpms_usage_view_query(array('menu' => null, 'tab' => null))); ?>">전체 메뉴 보기</a></div>
          <div class="ua-mini-insights"><span>최다 <b><?php echo $topTab && (int)$topTab['usage_count'] > 0 ? h($topTab['tab_name']) : '-'; ?></b></span><span>최소 <b><?php echo $leastTab ? h($leastTab['tab_name']) : '-'; ?></b></span></div>
          <div class="ua-table-wrap"><table><thead><tr><th>탭</th><th>이용</th><th>직원 수</th><th>마지막 이용</th><th>비율</th></tr></thead><tbody>
            <?php if (count($tabStats) === 0): ?><tr><td colspan="5" class="ua-empty">등록된 탭 정보가 없습니다.</td></tr><?php endif; ?>
            <?php foreach ($tabStats as $row): ?><tr><td><?php echo h($row['tab_name']); ?></td><td><strong><?php echo number_format($row['usage_count']); ?></strong></td><td><?php echo number_format($row['user_count']); ?></td><td><?php echo h(cpms_usage_view_datetime($row['last_used_at'])); ?></td><td><?php echo number_format($row['ratio'], 1); ?>%</td></tr><?php endforeach; ?>
          </tbody></table></div>
        </section>
      </div>

      <section class="ua-panel" id="daily-trend">
        <div class="ua-panel-title"><div><span><?php echo h($usageFilters['date_from']); ?> ~ <?php echo h($usageFilters['date_to']); ?></span><h2>날짜별 접속자 및 활동량 변화</h2></div><div class="ua-legend"><span class="users">접속 직원</span><span class="connections">접속 횟수</span><span class="activities">활동 수</span></div></div>
        <div class="ua-trend" data-usage-trend aria-label="날짜별 사용 추세 그래프"></div>
      </section>

      <section class="ua-panel" id="department-usage">
        <div class="ua-panel-title"><div><span><?php echo h($usageFilters['period_label']); ?></span><h2>부서별 사용현황</h2></div></div>
        <div class="ua-table-wrap"><table><thead><tr><th>부서</th><th>활성 직원</th><th>기간 이용 직원</th><th>접속 횟수</th><th>활동 수</th></tr></thead><tbody>
          <?php foreach ($usageData['department_stats'] as $row): ?><tr><td><strong><?php echo h($row['department']); ?></strong></td><td><?php echo number_format($row['employee_count']); ?></td><td><?php echo number_format($row['active_user_count']); ?></td><td><?php echo number_format($row['connection_count']); ?></td><td><?php echo number_format($row['activity_count']); ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
      </section>

      <?php if (\App\Core\Auth::isDevelopmentDepartment()): ?>
      <?php
        $usageReviewClassLabels = array(
          'KEEP_REQUIRED' => '필수기능 유지',
          'USABILITY_REVIEW' => '사용성 점검',
          'TRAINING_CANDIDATE' => '교육 필요 가능성',
          'LOCATION_REVIEW' => '위치 개선 검토',
          'MERGE_REVIEW' => '통합 검토',
          'HIDE_REVIEW' => '숨김 검토',
          'LIMITED_USE' => '제한적 사용',
          'NORMAL_USE' => '정상 사용',
          'INSUFFICIENT_DATA' => '근거 부족'
        );
        $usageReviewStatusLabels = array('NEW'=>'신규 검토','CHECKING'=>'확인 중','KEEP'=>'유지 결정','TRAINING'=>'교육 진행','RELOCATE'=>'위치 개선 예정','IMPROVE'=>'사용성 개선 예정','MERGE_PLANNED'=>'통합 예정','HIDE_PLANNED'=>'숨김 예정','EXCLUDED'=>'제외','COMPLETED'=>'완료');
        $usageFunnelStepLabels = array('PROJECT_SELECT'=>'현장 선택','COST_TYPE_SELECT'=>'비용항목 선택','AMOUNT_INPUT'=>'금액 입력','FORM_INPUT'=>'기타 입력','SAVE_ATTEMPT'=>'저장 시도','SAVE_SUCCESS'=>'저장 성공','SAVE_FAILURE'=>'저장 실패','EXIT_WITHOUT_SAVE'=>'저장 없이 이탈');
      ?>
      <section class="ua-panel" id="review-targets">
        <div class="ua-panel-title">
          <div><span>개발부서 전용 · 자동 삭제 없음</span><h2>기능별 사용흐름 검토 대상</h2></div>
          <form method="post" action="?r=usage_analytics/review_refresh">
            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
            <input type="hidden" name="days" value="30">
            <button class="ua-button ua-button-primary" type="submit">최근 30일 근거 다시 계산</button>
          </form>
        </div>
        <p>사용량만으로 기능을 없애지 않습니다. 업무상 필수 여부, 대상 인원, 부서 제한, 비정기 업무, 완료율과 오류율을 함께 확인하고 사람이 최종 결정을 기록합니다.</p>
        <div class="ua-table-wrap"><table><thead><tr><th>기능·경로</th><th>자동 분류와 근거</th><th>30일 사용흐름</th><th>업무 기준</th><th>사람의 검토 결정</th></tr></thead><tbody>
        <?php if (count($usageReviewTargets) === 0): ?><tr><td colspan="5" class="ua-empty">설치/업데이트 후 기능 이벤트가 쌓이면 검토 근거를 계산할 수 있습니다.</td></tr><?php endif; ?>
        <?php foreach ($usageReviewTargets as $reviewRow): ?>
          <?php
            $reviewFeatureKey = isset($reviewRow['feature_key']) ? (string)$reviewRow['feature_key'] : '';
            $reviewClass = isset($reviewRow['review_classification']) ? (string)$reviewRow['review_classification'] : 'INSUFFICIENT_DATA';
            $reviewStatus = isset($reviewRow['review_status']) ? (string)$reviewRow['review_status'] : 'NEW';
            $reviewCompletion = isset($reviewRow['completion_rate']) && $reviewRow['completion_rate'] !== null ? number_format((float)$reviewRow['completion_rate'], 1).'%' : '-';
            $reviewError = isset($reviewRow['error_rate']) && $reviewRow['error_rate'] !== null ? number_format((float)$reviewRow['error_rate'], 1).'%' : '-';
            $reviewDepartmentData = !empty($reviewRow['department_completion_data']) ? json_decode($reviewRow['department_completion_data'], true) : array();
            $reviewRoleData = !empty($reviewRow['role_completion_data']) ? json_decode($reviewRow['role_completion_data'], true) : array();
            $reviewFunnelData = !empty($reviewRow['funnel_data']) ? json_decode($reviewRow['funnel_data'], true) : array();
            if (!is_array($reviewDepartmentData)) $reviewDepartmentData = array();
            if (!is_array($reviewRoleData)) $reviewRoleData = array();
            if (!is_array($reviewFunnelData)) $reviewFunnelData = array();
          ?>
          <tr>
            <td><strong><?php echo h(isset($reviewRow['feature_name']) && trim((string)$reviewRow['feature_name']) !== '' ? $reviewRow['feature_name'] : $reviewFeatureKey); ?></strong><br><small><?php echo h(isset($reviewRow['menu_path']) ? $reviewRow['menu_path'] : ''); ?></small></td>
            <td><span class="ua-status"><?php echo h(isset($usageReviewClassLabels[$reviewClass]) ? $usageReviewClassLabels[$reviewClass] : '확인 필요'); ?></span><br><small><?php echo h(isset($reviewRow['evidence_text']) ? $reviewRow['evidence_text'] : ''); ?></small><br><small><?php echo h(isset($reviewRow['recommendation']) ? $reviewRow['recommendation'] : ''); ?></small></td>
            <td>이벤트 <?php echo number_format(isset($reviewRow['recent_usage_count']) ? (int)$reviewRow['recent_usage_count'] : 0); ?>회<br>사용자 <?php echo number_format(isset($reviewRow['unique_user_count']) ? (int)$reviewRow['unique_user_count'] : 0); ?>명 / 대상 <?php echo number_format(isset($reviewRow['target_user_count']) ? (int)$reviewRow['target_user_count'] : 0); ?>명<br>완료 <?php echo h($reviewCompletion); ?> · 오류 <?php echo h($reviewError); ?><br>저장 없이 이탈 <?php echo number_format(isset($reviewRow['exit_without_save_count']) ? (int)$reviewRow['exit_without_save_count'] : 0); ?>회<br>PC <?php echo isset($reviewRow['pc_completion_rate'])&&$reviewRow['pc_completion_rate']!==null?h(number_format((float)$reviewRow['pc_completion_rate'],1).'%'):'-'; ?> · 모바일 <?php echo isset($reviewRow['mobile_completion_rate'])&&$reviewRow['mobile_completion_rate']!==null?h(number_format((float)$reviewRow['mobile_completion_rate'],1).'%'):'-'; ?>
              <?php if(count($reviewDepartmentData)>0||count($reviewRoleData)>0): ?><details style="margin-top:6px"><summary>부서·권한별 완료율</summary><?php foreach(array_slice($reviewDepartmentData,0,5) as $groupRow): ?><small style="display:block">부서 <?php echo h(isset($groupRow['group'])?$groupRow['group']:'미지정'); ?>: <?php echo isset($groupRow['completion_rate'])&&$groupRow['completion_rate']!==null?h(number_format((float)$groupRow['completion_rate'],1).'%'):'-'; ?></small><?php endforeach; ?><?php foreach(array_slice($reviewRoleData,0,5) as $groupRow): ?><small style="display:block">권한 <?php echo h(isset($groupRow['group'])?$groupRow['group']:'미지정'); ?>: <?php echo isset($groupRow['completion_rate'])&&$groupRow['completion_rate']!==null?h(number_format((float)$groupRow['completion_rate'],1).'%'):'-'; ?></small><?php endforeach; ?></details><?php endif; ?>
              <?php if(count($reviewFunnelData)>0): ?><details style="margin-top:6px"><summary>단계별 도달률</summary><?php foreach($reviewFunnelData as $stepRow):$stepCode=isset($stepRow['step'])?(string)$stepRow['step']:''; ?><small style="display:block"><?php echo h(isset($usageFunnelStepLabels[$stepCode])?$usageFunnelStepLabels[$stepCode]:'확인 필요'); ?>: <?php echo number_format(isset($stepRow['count'])?(int)$stepRow['count']:0); ?>회 / <?php echo h(number_format(isset($stepRow['reach_rate'])?(float)$stepRow['reach_rate']:0,1).'%'); ?></small><?php endforeach; ?></details><?php endif; ?>
            </td>
            <td><form method="post" action="?r=usage_analytics/feature_save" style="display:grid;gap:6px;min-width:190px">
              <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="feature_key" value="<?php echo h($reviewFeatureKey); ?>"><input type="hidden" name="feature_name" value="<?php echo h(isset($reviewRow['feature_name']) ? $reviewRow['feature_name'] : ''); ?>"><input type="hidden" name="menu_path" value="<?php echo h(isset($reviewRow['menu_path']) ? $reviewRow['menu_path'] : ''); ?>">
              <select name="business_importance"><option value="NORMAL"<?php echo isset($reviewRow['business_importance']) && $reviewRow['business_importance']==='NORMAL'?' selected':''; ?>>보통</option><option value="IMPORTANT"<?php echo isset($reviewRow['business_importance']) && $reviewRow['business_importance']==='IMPORTANT'?' selected':''; ?>>중요</option><option value="REQUIRED"<?php echo isset($reviewRow['business_importance']) && $reviewRow['business_importance']==='REQUIRED'?' selected':''; ?>>필수</option><option value="OPTIONAL"<?php echo isset($reviewRow['business_importance']) && $reviewRow['business_importance']==='OPTIONAL'?' selected':''; ?>>선택</option></select>
              <label>대상 인원 <input type="number" name="target_user_count" min="0" value="<?php echo (int)(isset($reviewRow['target_user_count']) ? $reviewRow['target_user_count'] : 0); ?>" style="width:75px"></label>
              <label><input type="checkbox" name="department_limited" value="1"<?php echo !empty($reviewRow['department_limited'])?' checked':''; ?>> 특정 부서 한정</label><label><input type="checkbox" name="seasonal_or_irregular" value="1"<?php echo !empty($reviewRow['seasonal_or_irregular'])?' checked':''; ?>> 비정기·계절 업무</label>
              <input name="alternative_feature_key" maxlength="190" value="<?php echo h(isset($reviewRow['alternative_feature_key']) ? $reviewRow['alternative_feature_key'] : ''); ?>" placeholder="대체 기능 키(확인된 경우만)">
              <button class="ua-button ua-button-secondary" type="submit">업무 기준 저장</button>
            </form></td>
            <td><form method="post" action="?r=usage_analytics/review_save" style="display:grid;gap:6px;min-width:190px">
              <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="feature_key" value="<?php echo h($reviewFeatureKey); ?>">
              <select name="review_status"><?php foreach($usageReviewStatusLabels as $reviewStatusKey=>$reviewStatusLabel): ?><option value="<?php echo h($reviewStatusKey); ?>"<?php echo $reviewStatus===$reviewStatusKey?' selected':''; ?>><?php echo h($reviewStatusLabel); ?></option><?php endforeach; ?></select>
              <input type="number" name="owner_employee_id" min="0" value="<?php echo (int)(isset($reviewRow['owner_employee_id']) ? $reviewRow['owner_employee_id'] : 0); ?>" placeholder="담당자 직원번호">
              <textarea name="review_comment" maxlength="1000" placeholder="결정 사유·후속조치"><?php echo h(isset($reviewRow['review_comment']) ? $reviewRow['review_comment'] : ''); ?></textarea>
              <button class="ua-button ua-button-primary" type="submit">검토 결정 저장</button>
            </form></td>
          </tr>
        <?php endforeach; ?>
        </tbody></table></div>
      </section>
      <?php endif; ?>

      <script>window.cpmsUsageTrendData = <?php echo json_encode($usageData['trend'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
    <?php else: ?>
      <div class="ua-alert">표시할 사용현황 데이터가 없습니다.</div>
    <?php endif; ?>
  <?php endif; ?>
</div>
