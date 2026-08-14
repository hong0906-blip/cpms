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
        xhr.send(body);
      } catch (e) {}
    }
    window.setTimeout(runDeferredTaskWork, 250);
  })();
</script>
<?php endif; ?>
</body>
</html>
