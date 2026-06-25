<?php
/**
 * layout/header.php
 * - Tailwind CDN + lucide + (추가) 접힘/펼침 표시용 CSS
 */
$bodyRoute = isset($_GET['r']) ? trim((string)$_GET['r']) : '';
$bodyRouteClass = 'cpms-route-' . preg_replace('/[^a-z0-9_-]+/i', '-', $bodyRoute !== '' ? $bodyRoute : 'dashboard');
$bodySelectedClass = 'cpms-selected-' . preg_replace('/[^a-z0-9_-]+/i', '-', isset($selectedMenu) ? (string)$selectedMenu : '');
?>
<!doctype html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <meta http-equiv="x-ua-compatible" content="ie=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo h($title); ?></title>

  <!-- Tailwind (빌드 없이) -->
  <script src="https://cdn.tailwindcss.com"></script>

  <!-- lucide 아이콘 -->
  <script src="https://unpkg.com/lucide@latest"></script>

  <!-- App JS -->
  <script defer src="<?php echo h(asset_url('assets/js/app.js') . '?v=' . (string)@filemtime(dirname(dirname(dirname(__DIR__))) . '/public/assets/js/app.js')); ?>"></script>

  <!-- (중요) Sidebar 접힘/펼침에 따른 표시 제어 -->
  <style>
    #cpmsSidebar[data-collapsed="1"] .when-expanded { display:none !important; }
    #cpmsSidebar[data-collapsed="0"] .when-collapsed { display:none !important; }
    #cpmsContentShell { min-width: 0; }
    .cpms-mobile-bottom-nav { display: none; }
    .cpms-approval-mobile-list { display: none; }
    .cpms-monthly-mobile-summary { display: none; }
    .cpms-responsive-table-wrap {
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
    }
    .cpms-responsive-table {
      width: 100%;
      min-width: 720px;
      border-collapse: collapse;
    }
    .cpms-responsive-table th,
    .cpms-responsive-table td {
      white-space: nowrap;
      vertical-align: middle;
    }
    .cpms-responsive-table th.text-left,
    .cpms-responsive-table td.text-left,
    .cpms-responsive-table [data-wrap="1"] {
      white-space: normal;
      word-break: keep-all;
      overflow-wrap: anywhere;
    }
    .cpms-chip-row {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      min-width: 0;
    }
    .cpms-chip {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      max-width: 100%;
      min-width: 0;
      line-height: 1.35;
      white-space: normal;
      word-break: keep-all;
      overflow-wrap: anywhere;
      text-align: center;
    }

    .cpms-global-loading {
      position: fixed;
      inset: 0;
      z-index: 2147483000;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 24px;
      background: rgba(15, 23, 42, 0.58);
      pointer-events: auto;
    }

    .cpms-global-loading.is-visible {
      display: flex;
    }

    .cpms-global-loading__box {
      width: min(360px, 92vw);
      padding: 28px 24px;
      border-radius: 24px;
      border: 1px solid rgba(255,255,255,.7);
      background: rgba(255,255,255,.94);
      box-shadow: 0 26px 80px rgba(15,23,42,.28);
      text-align: center;
    }

    .cpms-global-loading__logo {
      width: 86px;
      height: 86px;
      margin: 0 auto 16px;
      padding: 10px;
      border-radius: 24px;
      border: 1px solid #e5e7eb;
      background: #fff;
      object-fit: contain;
      animation: cpms-loading-pulse 1.35s ease-in-out infinite;
    }

    .cpms-global-loading__title {
      color: #0f172a;
      font-size: 20px;
      line-height: 1.35;
      font-weight: 900;
      letter-spacing: 0;
    }

    .cpms-global-loading__text {
      margin-top: 8px;
      color: #475569;
      font-size: 14px;
      line-height: 1.55;
      font-weight: 700;
    }

    .cpms-global-loading__bar {
      position: relative;
      overflow: hidden;
      height: 6px;
      margin-top: 18px;
      border-radius: 999px;
      background: #e2e8f0;
    }

    .cpms-global-loading__bar:before {
      content: "";
      position: absolute;
      inset: 0 auto 0 0;
      width: 45%;
      border-radius: inherit;
      background: linear-gradient(90deg, #2563eb, #06b6d4);
      animation: cpms-loading-slide 1.05s ease-in-out infinite;
    }

    body.cpms-loading-active {
      cursor: wait;
    }

    @keyframes cpms-loading-pulse {
      0%, 100% { transform: scale(1); opacity: 1; }
      50% { transform: scale(1.045); opacity: .88; }
    }

    @keyframes cpms-loading-slide {
      0% { left: -48%; }
      100% { left: 104%; }
    }

    @media (max-width: 767px) {
      html,
      body {
        height: auto;
        min-height: 100%;
      }

      body {
        overflow: auto;
        background: #f8fafc;
      }

      body > .flex.h-screen {
        display: block;
        height: auto;
        min-height: 100vh;
      }

      #cpmsSidebar {
        display: none !important;
      }

      #cpmsContentShell {
        display: block;
        min-height: 100vh;
        overflow: visible;
      }

      #cpmsContentHeader {
        position: sticky;
        top: 0;
        z-index: 30;
        align-items: flex-start;
        gap: 12px;
        padding: 12px 14px;
        background: rgba(255,255,255,.92);
      }

      #cpmsContentHeader h1 {
        font-size: 20px;
        line-height: 1.25;
      }

      #cpmsContentHeader > div {
        max-width: 100%;
        flex-wrap: wrap;
      }

      #cpmsContentHeader .cpms-user-chip {
        width: 100%;
        justify-content: space-between;
        padding: 8px 10px;
        border-radius: 14px;
      }

      #cpmsContentMain {
        padding: 14px 14px 96px !important;
        overflow: visible;
      }

      .cpms-mobile-hide {
        display: none !important;
      }

      .cpms-mobile-bottom-nav {
        position: fixed;
        left: 0;
        right: 0;
        bottom: 0;
        z-index: 40;
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(54px, 1fr));
        gap: 4px;
        padding: 8px 10px calc(8px + env(safe-area-inset-bottom));
        border-top: 1px solid #e5e7eb;
        background: rgba(255,255,255,.96);
        box-shadow: 0 -10px 30px rgba(15,23,42,.08);
        backdrop-filter: blur(14px);
      }

      .cpms-mobile-bottom-nav a {
        min-width: 0;
        height: 58px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 4px;
        border-radius: 14px;
        color: #64748b;
        font-size: 11px;
        font-weight: 800;
      }

      .cpms-mobile-bottom-nav a.is-active {
        color: #0f172a;
        background: #eef6ff;
      }

      .cpms-mobile-bottom-nav i {
        width: 20px;
        height: 20px;
      }

      input,
      select,
      textarea,
      button {
        font-size: 16px;
      }

      .rounded-3xl {
        border-radius: 20px;
      }

      .cpms-dashboard-hero {
        padding: 18px !important;
        margin-bottom: 14px !important;
        box-shadow: none !important;
      }

      .cpms-dashboard-hero > .flex {
        flex-direction: column;
      }

      .cpms-dashboard-hero h2 {
        font-size: 24px;
        line-height: 1.25;
      }

      .cpms-dashboard-hero p {
        font-size: 14px;
        line-height: 1.5;
      }

      .cpms-attendance-actions,
      .cpms-attendance-actions form,
      .cpms-attendance-actions button {
        width: 100%;
      }

      .cpms-attendance-actions button,
      .cpms-attendance-actions form button,
      .cpms-attendance-actions > div {
        min-height: 52px;
        border-radius: 16px;
      }

      #cpmsEmployeeTasksPanel {
        margin-bottom: 14px !important;
        padding: 16px !important;
        border-radius: 20px !important;
        box-shadow: none !important;
      }

      #cpmsEmployeeTasksPanel h2 {
        font-size: 22px;
      }

      #cpmsEmployeeTasksToggle,
      #cpmsEmployeeTasksPanel > div:first-child > div:last-child,
      #cpmsEmployeeTasksPanel > [data-cpms-employee-task-body] {
        display: none !important;
      }

      #cpmsEmployeeTasksPanel .cpms-task-summary {
        display: block !important;
      }

      #cpmsEmployeeTasksPanel .cpms-task-summary .mt-3 {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
      }

      #cpmsEmployeeTasksPanel .cpms-task-summary span {
        justify-content: center;
        text-align: center;
        border-radius: 14px;
      }

      .cpms-approval-page > .bg-gradient-to-r {
        padding: 20px !important;
      }

      .cpms-approval-page h2 {
        font-size: 22px;
        line-height: 1.25;
      }

      .cpms-approval-page .cpms-approval-mobile-list {
        display: block;
      }

      .cpms-approval-page .cpms-approval-table {
        display: none !important;
      }

      .cpms-approval-page .grid {
        gap: 10px;
      }

      .cpms-approval-decision-panel {
        align-items: stretch;
      }

      .cpms-approval-decision-panel form,
      .cpms-approval-decision-panel button,
      .cpms-approval-decision-panel input {
        width: 100%;
      }

      .cpms-project-manage-tab,
      .cpms-construction-tabs,
      .cpms-construction-tab-select {
        display: none !important;
      }

      .cpms-monthly-filter {
        display: grid !important;
        grid-template-columns: 1fr;
        align-items: stretch !important;
      }

      .cpms-monthly-filter select,
      .cpms-monthly-filter button {
        width: 100%;
        min-width: 0 !important;
      }

      .cpms-monthly-mobile-summary {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
        margin-bottom: 12px;
      }

      .cpms-monthly-mobile-summary > div:first-child,
      .cpms-monthly-mobile-summary > div:nth-child(3) {
        grid-column: span 2;
      }

      .cpms-monthly-table-scroll {
        border: 1px solid #e5e7eb;
        border-radius: 16px;
        background: #fff;
      }

      .cpms-monthly-table-scroll table {
        font-size: 12px;
      }

      .cpms-monthly-deduction {
        display: none !important;
      }

      .cpms-construction-page {
        margin-bottom: 12px !important;
      }

      .cpms-construction-page h2,
      .cpms-project-page h2 {
        font-size: 22px;
      }

      .cpms-responsive-table {
        min-width: 680px;
      }

      .cpms-responsive-table th,
      .cpms-responsive-table td {
        font-size: 12px;
      }

      .cpms-chip-row {
        gap: 6px;
      }

      .cpms-chip {
        font-size: 11px;
      }
    }
  </style>
</head>

<body class="h-screen <?php echo h($bodyRouteClass . ' ' . $bodySelectedClass); ?>">
  <div id="cpmsGlobalLoading" class="cpms-global-loading" role="status" aria-live="polite" aria-hidden="true">
    <div class="cpms-global-loading__box">
      <img src="<?php echo h(base_url()); ?>/assets/img/logo.png" alt="CPMS" class="cpms-global-loading__logo">
      <div class="cpms-global-loading__title">잠시만 기다려주세요</div>
      <div class="cpms-global-loading__text">요청을 처리하고 있습니다.</div>
      <div class="cpms-global-loading__bar"></div>
    </div>
  </div>
  <script>
    (function(){
      try {
        var loadingKey = 'cpms_global_loading_next';
        var loadingTtl = 15000;
        if (!window.sessionStorage) return;
        var loadingStartedAt = parseInt(sessionStorage.getItem(loadingKey) || '', 10);
        if (!loadingStartedAt || (new Date()).getTime() - loadingStartedAt > loadingTtl) {
          sessionStorage.removeItem(loadingKey);
          return;
        }
        var el = document.getElementById('cpmsGlobalLoading');
        if (el) {
          el.className = el.className.replace(/\bis-visible\b/g, '').trim() + ' is-visible';
          el.setAttribute('aria-hidden', 'false');
        }
        if (document.body) {
          document.body.className = document.body.className.replace(/\bcpms-loading-active\b/g, '').trim() + ' cpms-loading-active';
        }
      } catch (e) {}
    })();
  </script>
  <div class="flex h-screen bg-gradient-to-br from-gray-50 via-blue-50/50 to-cyan-50/30">
