<?php
/**
 * C:\www\cpms\app\views\usage_analytics\user_detail.php
 * - 직원별 접속 요약, 자주 쓰는 메뉴/탭, 페이지 단위 상세 활동기록 화면
 * - PHP 5.6 호환
 */

$detailEmployee = isset($usageDetail['employee']) && is_array($usageDetail['employee']) ? $usageDetail['employee'] : array();
$detailEvents = isset($usageDetail['events']) && is_array($usageDetail['events']) ? $usageDetail['events'] : array();

if (!function_exists('cpms_usage_detail_event_label')) {
function cpms_usage_detail_event_label($event) {
    $eventType = isset($event['event_type']) ? (string)$event['event_type'] : '';
    if ($eventType === 'session_start') return 'CPMS 접속 시작';

    $menuName = isset($event['menu_name']) ? trim((string)$event['menu_name']) : '';
    $tabName = isset($event['tab_name']) ? trim((string)$event['tab_name']) : '';
    $actionName = isset($event['action_name']) ? trim((string)$event['action_name']) : '';
    $parts = array();
    if ($menuName !== '') $parts[] = $menuName;
    if ($tabName !== '' && $tabName !== $menuName) $parts[] = $tabName;
    if ($actionName !== '') $parts[] = $actionName;
    else $parts[] = '열람';
    return implode(' · ', $parts);
}}
?>

<section class="ua-detail-panel" id="user-detail">
  <div class="ua-detail-heading">
    <div>
      <span>직원 상세 활동 · <?php echo h($usageFilters['period_label']); ?></span>
      <h2><?php echo h(isset($detailEmployee['name']) ? $detailEmployee['name'] : '직원'); ?></h2>
      <p><?php echo h(isset($detailEmployee['department']) ? $detailEmployee['department'] : ''); ?> · <?php echo h(isset($detailEmployee['position']) ? $detailEmployee['position'] : ''); ?></p>
    </div>
    <a class="ua-detail-close" href="<?php echo h(cpms_usage_view_query(array('user_id' => null, 'detail_page' => null))); ?>" aria-label="직원 상세 닫기"><i data-lucide="x"></i></a>
  </div>

  <div class="ua-detail-summary">
    <div><span>오늘 접속 횟수</span><strong><?php echo number_format(isset($usageDetail['today_connections']) ? $usageDetail['today_connections'] : 0); ?>회</strong></div>
    <div><span>마지막 활동</span><strong><?php echo h(cpms_usage_view_datetime(isset($usageDetail['last_activity_at']) ? $usageDetail['last_activity_at'] : '')); ?></strong></div>
    <div><span>현재 상태</span><strong class="<?php echo !empty($usageDetail['is_online']) ? 'ua-online-text' : ''; ?>"><?php echo !empty($usageDetail['is_online']) ? '접속 중' : '오프라인'; ?></strong></div>
    <div><span>기간 상세 활동</span><strong><?php echo number_format(isset($usageDetail['total_events']) ? $usageDetail['total_events'] : 0); ?>건</strong></div>
  </div>

  <div class="ua-detail-popular">
    <div>
      <h3>자주 사용하는 메뉴</h3>
      <div class="ua-chip-list">
        <?php if (count($usageDetail['frequent_menus']) === 0): ?><span>기록 없음</span><?php endif; ?>
        <?php foreach ($usageDetail['frequent_menus'] as $row): ?><span><?php echo h($row['menu_name']); ?> <b><?php echo number_format($row['usage_count']); ?></b></span><?php endforeach; ?>
      </div>
    </div>
    <div>
      <h3>자주 사용하는 탭</h3>
      <div class="ua-chip-list">
        <?php if (count($usageDetail['frequent_tabs']) === 0): ?><span>기록 없음</span><?php endif; ?>
        <?php foreach ($usageDetail['frequent_tabs'] as $row): ?><span><?php echo h($row['tab_name']); ?> <b><?php echo number_format($row['usage_count']); ?></b></span><?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="ua-detail-timeline">
    <div class="ua-panel-title"><div><span>민감한 본문·문서 제목·개인정보 미수집</span><h3>활동 목록</h3></div><b><?php echo number_format($usageDetail['page']); ?> / <?php echo number_format($usageDetail['total_pages']); ?> 페이지</b></div>
    <?php if (count($detailEvents) === 0): ?><div class="ua-empty-box">선택 기간의 활동기록이 없습니다.</div><?php endif; ?>
    <?php foreach ($detailEvents as $event): ?>
      <div class="ua-timeline-row">
        <time><?php echo h(cpms_usage_view_datetime(isset($event['event_at']) ? $event['event_at'] : '')); ?></time>
        <span class="ua-timeline-dot <?php echo isset($event['event_type']) && $event['event_type'] === 'session_start' ? 'is-session' : ''; ?>"></span>
        <div><strong><?php echo h(cpms_usage_detail_event_label($event)); ?></strong></div>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if ((int)$usageDetail['total_pages'] > 1): ?>
    <nav class="ua-pagination" aria-label="직원 상세 활동 페이지">
      <?php if ((int)$usageDetail['page'] > 1): ?><a href="<?php echo h(cpms_usage_view_query(array('detail_page' => (int)$usageDetail['page'] - 1))); ?>">이전</a><?php endif; ?>
      <span><?php echo number_format($usageDetail['page']); ?> / <?php echo number_format($usageDetail['total_pages']); ?></span>
      <?php if ((int)$usageDetail['page'] < (int)$usageDetail['total_pages']): ?><a href="<?php echo h(cpms_usage_view_query(array('detail_page' => (int)$usageDetail['page'] + 1))); ?>">다음</a><?php endif; ?>
    </nav>
  <?php endif; ?>
</section>
