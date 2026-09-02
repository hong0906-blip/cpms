  </main>
</div>
</div>

<script>
  if (window.lucide) { lucide.createIcons(); }
</script>

<!-- ==========================
     C:\www\cpms\app\views\layout\footer.php
     세션 유지(자동로그아웃 방지)
     - 1분마다 ping 호출해서 세션 파일 갱신
     - PHP 5.6 / 구형 브라우저도 동작하도록 XMLHttpRequest 사용
========================== -->
<script>
  (function(){
    function ping(){
      try {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', '?r=ping&_t=' + new Date().getTime(), true);
        xhr.send(null);
      } catch (e) {
        // 네트워크 오류는 무시 (화면 사용성 우선)
      }
    }

    // 최초 1회
    ping();

    // 1분마다(60,000ms)
    setInterval(ping, 60000);

    // 화면이 다시 활성화될 때도 1회 갱신
    if (document.addEventListener) {
      document.addEventListener('visibilitychange', function(){
        if (!document.hidden) ping();
      });
    }
  })();
</script>
<?php
$cpmsTasksRunDeferredWork = isset($_SESSION)
    && is_array($_SESSION)
    && !empty($_SESSION['cpms_tasks_deferred_work']);
if ($cpmsTasksRunDeferredWork) unset($_SESSION['cpms_tasks_deferred_work']);

/*
 * [업무요청 - 퇴사자 미완료 업무 자동 정리]
 *
 * 기존 deferred_sync는 세션 플래그가 있을 때만 실행되기 때문에,
 * 퇴사자가 남긴 진행중/대기/지연 업무만 있는 경우에는 정리 코드가
 * 호출되지 않을 수 있었습니다.
 *
 * 페이지를 그릴 때 아주 가벼운 EXISTS 조회로 대상이 있는지만 확인하고,
 * 대상이 있으면 기존 deferred_sync를 1회 실행합니다.
 * 실제 완료 처리는 app/views/tasks/deferred_sync.php가 담당합니다.
 * PHP 5.6 호환 문법만 사용합니다.
 */
$cpmsHasInactiveAssigneeTasks = false;
try {
    $cpmsFooterPdo = \App\Core\Db::pdo();
    if ($cpmsFooterPdo) {
        $cpmsFooterSql = "SELECT 1
                          FROM cpms_tasks t
                          INNER JOIN employees e ON e.id = t.assignee_employee_id
                          WHERE e.is_active = 0
                            AND t.assignee_employee_id IS NOT NULL
                            AND t.assignee_employee_id > 0
                            AND (t.status IS NULL OR t.status NOT IN ('done', 'cancelled'))
                          LIMIT 1";
        $cpmsFooterSt = $cpmsFooterPdo->query($cpmsFooterSql);
        $cpmsHasInactiveAssigneeTasks = $cpmsFooterSt ? (bool)$cpmsFooterSt->fetchColumn() : false;
    }
} catch (Exception $e) {
    /* 구형 DB/테이블 미존재 등으로 실패해도 기존 화면에는 영향을 주지 않음 */
    $cpmsHasInactiveAssigneeTasks = false;
}

if ($cpmsHasInactiveAssigneeTasks) {
    $cpmsTasksRunDeferredWork = true;
}
?>
<?php if ($cpmsTasksRunDeferredWork): ?>
<script>
  (function(){
    var attempts = 0;
    var body = <?php echo json_encode('_csrf=' . rawurlencode(csrf_token())); ?>;
    function runDeferredTaskWork(){
      attempts++;
      if (window.fetch) {
        window.fetch('?r=tasks/deferred_sync', {
          method: 'POST',
          credentials: 'same-origin',
          keepalive: true,
          headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
          body: body
        }).then(function(response){
          return response.ok ? response.json() : null;
        }).then(function(result){
          if (!result && attempts < 3) {
            window.setTimeout(runDeferredTaskWork, 1200);
            return;
          }

          /*
           * 퇴사자 미완료 업무가 실제 완료 처리되었다면 현재 화면은
           * 처리 전 DB 상태로 이미 그려진 화면이므로 한 번만 새로고침합니다.
           * 새로고침 후에는 해당 업무가 done 상태라 지연/진행중 목록에서 빠집니다.
           */
          if (
            result
            && result.ok
            && result.inactive_assignee_cleanup
            && parseInt(result.inactive_assignee_cleanup.completed || 0, 10) > 0
          ) {
            window.location.reload();
            return;
          }

          if (result && result.ok && parseInt(result.remaining || 0, 10) > 0 && attempts < 5) {
            window.setTimeout(runDeferredTaskWork, 500);
          }
        }, function(){
          if (attempts < 3) window.setTimeout(runDeferredTaskWork, 1200);
        });
        return;
      }
      try {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', '?r=tasks/deferred_sync', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
        xhr.onreadystatechange = function(){
          if (xhr.readyState !== 4 || xhr.status < 200 || xhr.status >= 300) return;
          try {
            var result = JSON.parse(xhr.responseText || '{}');
            if (
              result
              && result.ok
              && result.inactive_assignee_cleanup
              && parseInt(result.inactive_assignee_cleanup.completed || 0, 10) > 0
            ) {
              window.location.reload();
            }
          } catch (e) {}
        };
        xhr.send(body);
      } catch (e) {}
    }
    window.setTimeout(runDeferredTaskWork, 250);
  })();
</script>
<?php endif; ?>
</body>
</html>
