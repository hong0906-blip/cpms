<?php
/**
 * C:\www\cpms\app\views\admin\workforce_upload.php
 * - 관리 > 인력관리 엑셀 업로드 화면
 * - PHP 5.6 호환
 */

use App\Core\Auth;

if (!(Auth::isMaster() || Auth::canManageEmployees())) {
    echo '<div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 font-bold">접근 권한이 없습니다.</div>';
    return;
}
?>

<link rel="stylesheet" href="<?php echo h(asset_url('assets/css/workforce.css')); ?>">

<div class="cpms-workforce-page">
  <div class="mb-6">
    <div class="text-sm text-gray-500">관리 / 인력관리</div>
    <h2 class="text-2xl font-extrabold text-gray-900">엑셀 업로드</h2>
    <div class="text-sm text-gray-500 mt-1">근로자명단.xlsx 파일을 업로드하면 저장 전에 미리보기를 먼저 보여줍니다.</div>
  </div>

  <form method="post" action="?r=admin/workforce_import_preview" enctype="multipart/form-data" class="rounded-3xl border border-gray-200 bg-white p-6 space-y-5 max-w-3xl">
    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">

    <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4 text-sm text-gray-700">
      <div class="font-extrabold text-gray-900">업로드 기준</div>
      <div class="mt-2">3행을 헤더로 보고, 4행부터 데이터를 읽습니다. 성명이 없는 행은 자동으로 제외합니다.</div>
      <div class="mt-1">주민번호와 계좌번호는 평문 저장하지 않고 암호화 저장합니다.</div>
    </div>

    <label class="block">
      <span class="block text-sm font-bold text-gray-700 mb-1">엑셀 파일</span>
      <input type="file" name="worker_excel" accept=".xlsx,.xls,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel" required class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white">
    </label>

    <label class="block">
      <span class="block text-sm font-bold text-gray-700 mb-1"><?php echo h(urldecode('%EA%B8%B0%EC%A4%80%EC%9B%94')); ?></span>
      <input type="month" name="target_month" value="<?php echo h(date('Y-m')); ?>" class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white">
    </label>

    <label class="block">
      <span class="block text-sm font-bold text-gray-700 mb-1">전체 적용 업체명</span>
      <input name="default_agency_name" class="w-full px-4 py-3 rounded-2xl border border-gray-200" placeholder="비워두면 엑셀 컬럼 매핑 값을 사용합니다.">
    </label>

    <div class="flex justify-end gap-2">
      <a href="?r=관리&tab=workforce" class="px-5 py-3 rounded-2xl border border-gray-200 font-bold">취소</a>
      <button class="px-6 py-3 rounded-2xl bg-gray-900 text-white font-extrabold">미리보기</button>
    </div>
  </form>
</div>
