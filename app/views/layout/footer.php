  </main>
</div>
</div>

<script>
  if (window.lucide) { lucide.createIcons(); }
</script>

<!-- ==========================
     C:\www\cpms\app\views\layout\footer.php
     세션 유지(자동로그아웃 방지)
     - 5분마다 ping 호출해서 세션 파일 갱신
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

    // 5분마다(300,000ms)
    setInterval(ping, 300000);

    // 화면이 다시 활성화될 때도 1회 갱신
    if (document.addEventListener) {
      document.addEventListener('visibilitychange', function(){
        if (!document.hidden) ping();
      });
    }
  })();
</script>
</body>
</html>