<?php
/** 공사 > 업체등록. 일반 부서는 전체 필수, 개발부서는 업체명 외 정보 보완 등록 가능. */

use App\Services\VendorService;

require_once __DIR__ . '/../../../services/VendorService.php';

$constructionVendorBootstrap = VendorService::bootstrap($pdo, true);
$constructionVendorQuery = isset($_GET['vendor_q']) ? trim((string)$_GET['vendor_q']) : '';
$constructionVendorRows = !empty($constructionVendorBootstrap['ok']) ? VendorService::listVendors($pdo, $constructionVendorQuery, 100) : array();
$constructionVendorFlash = flash_get();
$constructionVendorRelaxed = \App\Core\Auth::isDevelopmentDepartment();
?>

<div class="space-y-5">
  <div><h3 class="text-xl font-extrabold">업체등록</h3><p class="mt-1 text-sm text-gray-600">공사팀이 등록한 업체는 관리섹션 업체관리와 자재·장비·안전관리비·외주비 검색에 즉시 공유됩니다.</p></div>
  <?php if ($constructionVendorFlash): ?><div class="p-4 rounded-2xl border <?php echo $constructionVendorFlash['type']==='success'?'bg-emerald-50 border-emerald-200 text-emerald-700':'bg-red-50 border-red-200 text-red-700'; ?> font-bold"><?php echo h($constructionVendorFlash['message']); ?></div><?php endif; ?>
  <?php if (empty($constructionVendorBootstrap['ok'])): ?><div class="p-4 rounded-2xl border border-red-200 bg-red-50 text-red-700 font-bold">업체 마스터를 준비할 수 없습니다. 관리팀에 문의해주세요.</div><?php else: ?>
  <?php if ($canEdit): ?>
  <form method="post" action="<?php echo h(base_url()); ?>/?r=vendor/save" class="bg-white border border-gray-200 rounded-2xl p-5 space-y-4">
    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="context" value="construction"><input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>">
    <div class="rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-sm font-bold text-blue-800"><?php echo $constructionVendorRelaxed ? '개발부서는 업체명만 입력해도 임시 등록할 수 있으며 나머지 정보는 나중에 보완할 수 있습니다.' : '업체 등록 시 아래 8개 항목을 모두 입력해야 합니다. 은행정보는 등록에만 사용되며 공사팀 업체 목록에는 표시되지 않습니다.'; ?></div>
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-3">
      <label class="text-sm font-bold text-gray-700">사업자등록번호 <?php if (!$constructionVendorRelaxed): ?><span class="text-red-500">*</span><?php endif; ?><input <?php echo $constructionVendorRelaxed ? '' : 'required'; ?> name="business_no" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-300"></label>
      <label class="text-sm font-bold text-gray-700">업체명 <span class="text-red-500">*</span><input required name="name" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-300"></label>
      <label class="text-sm font-bold text-gray-700">내역 <?php if (!$constructionVendorRelaxed): ?><span class="text-red-500">*</span><?php endif; ?><input <?php echo $constructionVendorRelaxed ? '' : 'required'; ?> name="description" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-300" placeholder="철물점, 장비업체 등"></label>
      <label class="text-sm font-bold text-gray-700">대표자명 <?php if (!$constructionVendorRelaxed): ?><span class="text-red-500">*</span><?php endif; ?><input <?php echo $constructionVendorRelaxed ? '' : 'required'; ?> name="representative" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-300"></label>
      <label class="text-sm font-bold text-gray-700">전화번호 <?php if (!$constructionVendorRelaxed): ?><span class="text-red-500">*</span><?php endif; ?><input <?php echo $constructionVendorRelaxed ? '' : 'required'; ?> name="phone" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-300"></label>
      <label class="text-sm font-bold text-gray-700">은행 <?php if (!$constructionVendorRelaxed): ?><span class="text-red-500">*</span><?php endif; ?><input <?php echo $constructionVendorRelaxed ? '' : 'required'; ?> name="bank_name" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-300 bg-white"></label>
      <label class="text-sm font-bold text-gray-700">계좌번호 <?php if (!$constructionVendorRelaxed): ?><span class="text-red-500">*</span><?php endif; ?><input <?php echo $constructionVendorRelaxed ? '' : 'required'; ?> name="account_number" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-300 bg-white"></label>
      <label class="text-sm font-bold text-gray-700">예금주 <?php if (!$constructionVendorRelaxed): ?><span class="text-red-500">*</span><?php endif; ?><input <?php echo $constructionVendorRelaxed ? '' : 'required'; ?> name="account_holder" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-300 bg-white"></label>
    </div>
    <div class="flex justify-end"><button class="px-5 py-3 rounded-xl bg-blue-600 text-white font-extrabold">업체 등록</button></div>
  </form>
  <?php endif; ?>

  <form method="get" class="bg-white border border-gray-200 rounded-2xl p-4 flex flex-col md:flex-row gap-3"><input type="hidden" name="r" value="공사"><input type="hidden" name="pid" value="<?php echo (int)$pid; ?>"><input type="hidden" name="tab" value="vendors"><input name="vendor_q" value="<?php echo h($constructionVendorQuery); ?>" class="flex-1 px-4 py-3 rounded-xl border border-gray-300" placeholder="업체명, 사업자등록번호, 내역, 대표자명, 전화번호 검색"><button class="px-5 py-3 rounded-xl bg-gray-900 text-white font-extrabold">검색</button></form>
  <div class="bg-white border border-gray-200 rounded-2xl overflow-hidden"><div class="p-4 border-b font-extrabold">공용 업체 목록</div><div class="overflow-x-auto"><table class="min-w-[850px] w-full text-sm"><thead><tr class="bg-gray-50"><th class="p-3 text-left">사업자등록번호</th><th class="p-3 text-left">업체명</th><th class="p-3 text-left">내역</th><th class="p-3 text-left">대표자명</th><th class="p-3 text-left">전화번호</th></tr></thead><tbody><?php if(count($constructionVendorRows)===0): ?><tr><td colspan="5" class="p-8 text-center text-gray-500">등록되거나 검색된 업체가 없습니다.</td></tr><?php endif; ?><?php foreach($constructionVendorRows as $row): ?><tr class="border-t"><td class="p-3"><?php echo h(isset($row['business_no'])?$row['business_no']:''); ?></td><td class="p-3 font-extrabold"><?php echo h(isset($row['name'])?$row['name']:''); ?></td><td class="p-3"><?php echo h(isset($row['description'])?$row['description']:''); ?></td><td class="p-3"><?php echo h(isset($row['representative'])?$row['representative']:''); ?></td><td class="p-3"><?php echo h(isset($row['phone'])?$row['phone']:''); ?></td></tr><?php endforeach; ?></tbody></table></div></div>
  <?php endif; ?>
</div>
