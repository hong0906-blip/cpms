<?php
/**
 * layout/header.php
 * - Tailwind CDN + lucide + (추가) 접힘/펼침 표시용 CSS
 */
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
  <script defer src="<?php echo h(asset_url('assets/js/app.js')); ?>"></script>

  <!-- (중요) Sidebar 접힘/펼침에 따른 표시 제어 -->
  <style>
    #cpmsSidebar[data-collapsed="1"] .when-expanded { display:none !important; }
    #cpmsSidebar[data-collapsed="0"] .when-collapsed { display:none !important; }
  </style>
</head>

<body class="h-screen">
  <div class="flex h-screen bg-gradient-to-br from-gray-50 via-blue-50/50 to-cyan-50/30">