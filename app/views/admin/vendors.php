<?php
/** 관리 > 업체관리. PHP 5.6 compatible. */

use App\Core\Auth;
use App\Services\VendorService;

require_once __DIR__ . '/../../services/VendorService.php';

$vendorBootstrap = VendorService::bootstrap($pdo, true);
$vendorQuery = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$vendorRows = !empty($vendorBootstrap['ok']) ? VendorService::listVendors($pdo, $vendorQuery, 500) : array();
$vendorEditId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$vendorEdit = $vendorEditId > 0 ? VendorService::getById($pdo, $vendorEditId) : null;
$vendorShowForm = (isset($_GET['mode']) && (string)$_GET['mode'] === 'create') || is_array($vendorEdit);
$vendorLegacyMode = isset($_GET['legacy']) && (string)$_GET['legacy'] === '1';
$vendorImportMode = isset($_GET['import']) && (string)$_GET['import'] === '1';
$vendorLegacyGroups = $vendorLegacyMode ? VendorService::legacyGroups($pdo) : array();
$vendorAllForLink = $vendorLegacyMode ? VendorService::listVendors($pdo, '', 1000) : array();
$vendorFlash = flash_get();

if (!function_exists('cpms_vendor_admin_value')) {
function cpms_vendor_admin_value($row, $key) {
    return is_array($row) && isset($row[$key]) ? (string)$row[$key] : '';
}}
if (!function_exists('cpms_vendor_admin_source_label')) {
function cpms_vendor_admin_source_label($source) {
    $labels = array(
        'material_preset'=>'자재 업체 프리셋',
        'equipment_preset'=>'장비 업체 프리셋',
        'material_item'=>'자재구입비',
        'equipment_item'=>'장비',
        'outsourcing'=>'외주비',
        'safety'=>'안전관리비'
    );
    return isset($labels[$source]) ? $labels[$source] : (string)$source;
}}
?>

<div class="space-y-5">
  <div class="flex flex-wrap items-center justify-between gap-3">
    <div>
      <h2 class="text-2xl font-extrabold text-gray-900">업체관리</h2>
      <p class="mt-1 text-sm text-gray-600">CPMS 전체 비용 화면이 함께 사용하는 통합 업체 마스터입니다.</p>
    </div>
    <div class="flex flex-wrap gap-2">
      <a href="?r=관리&tab=vendors&mode=create" class="px-4 py-3 rounded-xl bg-emerald-600 text-white font-extrabold">업체 등록</a>
      <a href="?r=관리&tab=vendors&import=1" class="px-4 py-3 rounded-xl bg-blue-600 text-white font-extrabold">엑셀 업로드</a>
      <a href="?r=관리&tab=vendors&legacy=1" class="px-4 py-3 rounded-xl border border-amber-300 bg-amber-50 text-amber-800 font-extrabold">기존 업체 연결 확인</a>
    </div>
  </div>

  <?php if ($vendorFlash): ?>
    <div class="p-4 rounded-2xl border <?php echo $vendorFlash['type']==='success' ? 'bg-emerald-50 border-emerald-200 text-emerald-700' : 'bg-red-50 border-red-200 text-red-700'; ?> font-bold"><?php echo h($vendorFlash['message']); ?></div>
  <?php endif; ?>

  <?php if (empty($vendorBootstrap['ok'])): ?>
    <div class="p-4 rounded-2xl border border-red-200 bg-red-50 text-red-700 font-bold">업체 마스터 테이블을 준비할 수 없습니다. DB 테이블 생성/변경 권한을 확인해주세요.</div>
  <?php else: ?>
    <?php if ((int)$vendorBootstrap['created'] > 0 || (int)$vendorBootstrap['linked'] > 0): ?>
      <div class="p-4 rounded-2xl border border-blue-200 bg-blue-50 text-blue-800 text-sm">기존 업체 동기화: 신규 마스터 <?php echo (int)$vendorBootstrap['created']; ?>건, 안전하게 연결된 기존 자료 <?php echo (int)$vendorBootstrap['linked']; ?>건. 기존 거래값은 변경하지 않았습니다.</div>
    <?php endif; ?>

    <form method="get" action="" class="bg-white border border-gray-200 rounded-2xl p-4 flex flex-col md:flex-row gap-3">
      <input type="hidden" name="r" value="관리"><input type="hidden" name="tab" value="vendors">
      <input type="text" name="q" value="<?php echo h($vendorQuery); ?>" class="flex-1 px-4 py-3 rounded-xl border border-gray-300" placeholder="업체명, 사업자등록번호, 내역, 대표자명, 전화번호 검색">
      <button type="submit" class="px-5 py-3 rounded-xl bg-gray-900 text-white font-extrabold">검색</button>
      <a href="?r=관리&tab=vendors" class="px-5 py-3 rounded-xl border border-gray-300 text-center font-bold">초기화</a>
    </form>

    <?php if ($vendorImportMode): ?>
      <form method="post" action="<?php echo h(base_url()); ?>/?r=admin/vendor_import" enctype="multipart/form-data" class="bg-white border border-blue-200 rounded-2xl p-5 space-y-4">
        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
        <div class="flex items-start justify-between gap-3"><div><h3 class="text-lg font-extrabold">업체 엑셀 업로드</h3><p class="mt-1 text-sm text-gray-600">엑셀 시트에서 헤더를 찾아 최대 2,000개 업체를 등록하거나 갱신합니다.</p></div><a href="?r=관리&tab=vendors" class="text-sm font-bold text-gray-500">닫기</a></div>
        <div class="rounded-xl bg-blue-50 border border-blue-100 p-4 text-sm text-blue-900"><div class="font-extrabold">엑셀 열 이름</div><div class="mt-1">사업자등록번호 / 업체명 / 내역 / 대표자명 / 전화번호 / 은행 / 계좌번호 / 예금주</div><div class="mt-2 text-xs">업체명은 필수입니다. 사업자등록번호와 계좌번호는 엑셀에서 텍스트 형식으로 입력하면 앞자리 0이 유지됩니다. 내역에는 구매품·기타경비 같은 비용 구분이 아니라 업체 업종이나 특성을 입력해주세요.</div></div>
        <input type="file" name="vendor_excel" accept=".xlsx,.xls,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel" required class="w-full px-4 py-3 rounded-xl border border-gray-300 bg-white">
        <div class="flex justify-end"><button type="submit" class="px-5 py-3 rounded-xl bg-blue-600 text-white font-extrabold" onclick="return confirm('엑셀의 업체정보를 통합 업체 마스터에 반영할까요?');">업로드 및 반영</button></div>
      </form>
    <?php endif; ?>

    <?php if ($vendorShowForm): ?>
      <form method="post" action="<?php echo h(base_url()); ?>/?r=vendor/save" class="bg-white border border-gray-200 rounded-2xl p-5 space-y-4">
        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
        <input type="hidden" name="context" value="management">
        <input type="hidden" name="vendor_id" value="<?php echo is_array($vendorEdit) ? (int)$vendorEdit['id'] : 0; ?>">
        <div class="flex items-center justify-between gap-3">
          <h3 class="text-lg font-extrabold"><?php echo is_array($vendorEdit) ? '업체 수정' : '업체 등록'; ?></h3>
          <a href="?r=관리&tab=vendors" class="text-sm font-bold text-gray-500">닫기</a>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-3">
          <label class="text-sm font-bold text-gray-700">사업자등록번호<input name="business_no" value="<?php echo h(cpms_vendor_admin_value($vendorEdit,'business_no')); ?>" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-300" placeholder="123-45-67890"></label>
          <label class="text-sm font-bold text-gray-700">업체명 <span class="text-red-500">*</span><input required name="name" value="<?php echo h(cpms_vendor_admin_value($vendorEdit,'name')); ?>" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-300"></label>
          <label class="text-sm font-bold text-gray-700">내역<input name="description" value="<?php echo h(cpms_vendor_admin_value($vendorEdit,'description')); ?>" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-300" placeholder="철물점, 건설업, 장비업체 등"><span class="mt-1 block text-xs font-normal text-gray-500">구매품·기타경비 같은 비용 구분이 아니라 업체 업종이나 특성을 입력합니다.</span></label>
          <label class="text-sm font-bold text-gray-700">대표자명<input name="representative" value="<?php echo h(cpms_vendor_admin_value($vendorEdit,'representative')); ?>" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-300"></label>
          <label class="text-sm font-bold text-gray-700">전화번호<input name="phone" value="<?php echo h(cpms_vendor_admin_value($vendorEdit,'phone')); ?>" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-300"></label>
          <label class="text-sm font-bold text-gray-700">은행<input name="bank_name" value="<?php echo h(cpms_vendor_admin_value($vendorEdit,'bank_name')); ?>" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-300"></label>
          <label class="text-sm font-bold text-gray-700">계좌번호<input name="account_number" value="<?php echo h(cpms_vendor_admin_value($vendorEdit,'account_number')); ?>" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-300"></label>
          <label class="text-sm font-bold text-gray-700">예금주<input name="account_holder" value="<?php echo h(cpms_vendor_admin_value($vendorEdit,'account_holder')); ?>" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-300"></label>
        </div>
        <div class="flex justify-end"><button class="px-5 py-3 rounded-xl bg-emerald-600 text-white font-extrabold">저장</button></div>
      </form>
    <?php endif; ?>

    <?php if ($vendorLegacyMode): ?>
      <div class="bg-white border border-amber-200 rounded-2xl p-5 space-y-3">
        <div class="flex items-start justify-between gap-3"><div><h3 class="text-lg font-extrabold">기존 업체 연결 확인</h3><p class="text-sm text-gray-600 mt-1">사업자번호 또는 완전히 동일한 업체명으로 확정 가능한 자료는 자동 연결됩니다. 유사한 이름은 자동 병합하지 않습니다.</p></div><a href="?r=관리&tab=vendors" class="text-sm font-bold text-gray-500">닫기</a></div>
        <?php if (count($vendorLegacyGroups) === 0): ?>
          <div class="p-4 rounded-xl bg-emerald-50 text-emerald-700 font-bold">확인이 필요한 기존 업체 연결이 없습니다.</div>
        <?php else: ?>
          <div class="overflow-x-auto"><table class="min-w-full text-sm border-collapse"><thead><tr class="bg-gray-50"><th class="border p-2">사용 화면</th><th class="border p-2">기존 업체명</th><th class="border p-2">사업자번호</th><th class="border p-2">건수</th><th class="border p-2">연결할 업체</th></tr></thead><tbody>
          <?php foreach ($vendorLegacyGroups as $legacyRow): ?>
            <tr><td class="border p-2"><?php echo h(cpms_vendor_admin_source_label($legacyRow['source'])); ?></td><td class="border p-2 font-bold"><?php echo h($legacyRow['name']); ?></td><td class="border p-2"><?php echo h($legacyRow['business_no']); ?></td><td class="border p-2 text-center"><?php echo (int)$legacyRow['count']; ?></td><td class="border p-2"><form method="post" action="<?php echo h(base_url()); ?>/?r=admin/vendor_link_save" class="flex min-w-[340px] gap-2"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="legacy_token" value="<?php echo h($legacyRow['token']); ?>"><select required name="vendor_id" class="flex-1 px-2 py-2 rounded-lg border border-gray-300"><option value="">관리자가 업체 선택</option><?php foreach ($vendorAllForLink as $linkVendor): ?><option value="<?php echo (int)$linkVendor['id']; ?>" <?php echo (int)$legacyRow['suggested_id']===(int)$linkVendor['id'] ? 'selected' : ''; ?>><?php echo h($linkVendor['name'] . (trim((string)$linkVendor['business_no']) !== '' ? ' / ' . $linkVendor['business_no'] : '')); ?></option><?php endforeach; ?></select><button class="px-3 py-2 rounded-lg bg-amber-600 text-white font-bold">연결</button></form></td></tr>
          <?php endforeach; ?>
          </tbody></table></div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="bg-white border border-gray-200 rounded-2xl overflow-hidden">
      <div class="p-4 border-b font-extrabold">업체 목록 <?php echo number_format(count($vendorRows)); ?>건</div>
      <div class="overflow-x-auto"><table class="min-w-[1200px] w-full text-sm border-collapse"><thead><tr class="bg-gray-50 text-gray-700"><th class="border-b p-3 text-left">사업자등록번호</th><th class="border-b p-3 text-left">업체명</th><th class="border-b p-3 text-left">내역</th><th class="border-b p-3 text-left">대표자명</th><th class="border-b p-3 text-left">전화번호</th><th class="border-b p-3 text-left">은행</th><th class="border-b p-3 text-left">계좌번호</th><th class="border-b p-3 text-left">예금주</th><th class="border-b p-3 text-center">관리</th></tr></thead><tbody>
      <?php if (count($vendorRows) === 0): ?><tr><td colspan="9" class="p-8 text-center text-gray-500">등록되거나 검색된 업체가 없습니다.</td></tr><?php endif; ?>
      <?php foreach ($vendorRows as $vendorRow): ?><tr class="hover:bg-gray-50"><td class="border-b p-3"><?php echo h(cpms_vendor_admin_value($vendorRow,'business_no')); ?></td><td class="border-b p-3 font-extrabold"><?php echo h(cpms_vendor_admin_value($vendorRow,'name')); ?></td><td class="border-b p-3"><?php echo h(cpms_vendor_admin_value($vendorRow,'description')); ?></td><td class="border-b p-3"><?php echo h(cpms_vendor_admin_value($vendorRow,'representative')); ?></td><td class="border-b p-3"><?php echo h(cpms_vendor_admin_value($vendorRow,'phone')); ?></td><td class="border-b p-3"><?php echo h(cpms_vendor_admin_value($vendorRow,'bank_name')); ?></td><td class="border-b p-3"><?php echo h(cpms_vendor_admin_value($vendorRow,'account_number')); ?></td><td class="border-b p-3"><?php echo h(cpms_vendor_admin_value($vendorRow,'account_holder')); ?></td><td class="border-b p-3"><div class="flex items-center justify-center gap-2"><a href="?r=관리&tab=vendors&edit=<?php echo (int)$vendorRow['id']; ?>" class="px-3 py-2 rounded-lg border border-gray-300 font-bold">수정</a><form method="post" action="<?php echo h(base_url()); ?>/?r=admin/vendor_delete" onsubmit="return confirm('이 업체를 삭제할까요? 업체는 검색·등록 목록에서 숨겨지며 기존 거래와 첨부자료는 삭제되지 않습니다.');"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="vendor_id" value="<?php echo (int)$vendorRow['id']; ?>"><button type="submit" class="px-3 py-2 rounded-lg border border-red-200 bg-red-50 text-red-700 font-bold">삭제</button></form></div></td></tr><?php endforeach; ?>
      </tbody></table></div>
    </div>
  <?php endif; ?>
</div>
