// public/assets/js/app.js
// - 기존: Sidebar collapse
// - 추가: pages.js 자동 로드(레이아웃 파일 수정 없이)

(function () {
  function refreshIcons() {
    if (window.lucide) {
      try { lucide.createIcons(); } catch (e) {}
    }
  }

  // pages.js 자동 로드 (현재 스크립트 경로에서 같은 폴더의 pages.js 로드)
  (function loadPagesJs() {
    try {
      var cur = document.currentScript && document.currentScript.src ? document.currentScript.src : '';
      if (!cur) return;
      // .../assets/js/app.js -> .../assets/js/
      var base = cur.replace(/app\.js(\?.*)?$/i, '');
      var s = document.createElement('script');
      s.src = base + 'pages.js';
      s.defer = true;
      document.head.appendChild(s);
    } catch (e) {}
  })();

  // Sidebar collapse
  var sidebar = document.getElementById('cpmsSidebar');
  var toggle = document.getElementById('sidebarToggle');

  function setCollapsed(collapsed) {
    if (!sidebar) return;

    sidebar.className = sidebar.className.replace(/\bw-72\b/g, '').replace(/\bw-20\b/g, '').trim();
    sidebar.className += collapsed ? ' w-20' : ' w-72';

    sidebar.setAttribute('data-collapsed', collapsed ? '1' : '0');

    if (toggle) {
      toggle.innerHTML = collapsed
        ? '<i data-lucide="chevron-right" class="w-4 h-4 text-gray-600"></i>'
        : '<i data-lucide="chevron-left" class="w-4 h-4 text-gray-600"></i>';
    }

    localStorage.setItem('cpms_sidebar_collapsed', collapsed ? '1' : '0');
    refreshIcons();
  }

  if (sidebar && toggle) {
    var saved = localStorage.getItem('cpms_sidebar_collapsed') === '1';
    setCollapsed(saved);

    toggle.addEventListener('click', function () {
      var isCollapsed = sidebar.getAttribute('data-collapsed') === '1';
      setCollapsed(!isCollapsed);
    });
  }

  refreshIcons();
})();