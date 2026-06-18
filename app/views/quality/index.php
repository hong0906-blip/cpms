<?php
/**
 * Quality file management.
 * PHP 5.6 compatible.
 */

use App\Core\Auth;
use App\Core\Db;

require_once __DIR__ . '/quality_file_helper.php';

if (!function_exists('cpms_quality_view_text')) {
function cpms_quality_view_text($encoded) {
    return urldecode($encoded);
}}

if (!function_exists('cpms_quality_view_file_size')) {
function cpms_quality_view_file_size($bytes) {
    $bytes = (float)$bytes;
    if ($bytes <= 0) return '-';
    $units = array('B', 'KB', 'MB', 'GB');
    $idx = 0;
    while ($bytes >= 1024 && $idx < count($units) - 1) {
        $bytes = $bytes / 1024;
        $idx++;
    }
    return ($idx === 0 ? (string)(int)$bytes : number_format($bytes, 1)) . $units[$idx];
}}

$pdo = Db::pdo();
$documentOptions = cpms_quality_file_document_options();
$projects = $pdo ? cpms_quality_file_projects_for_user($pdo) : array();
$items = $pdo ? cpms_quality_file_visible_items($pdo) : array();
$canUploadCommon = $pdo ? cpms_quality_file_can_upload($pdo, 0) : false;
$hasUploadTarget = $canUploadCommon || count($projects) > 0;
$docFilter = isset($_GET['doc']) ? trim((string)$_GET['doc']) : '';
$projectFilter = isset($_GET['pid']) ? (int)$_GET['pid'] : -1;
$filtered = array();

foreach ($items as $row) {
    if ($docFilter !== '' && isset($documentOptions[$docFilter])) {
        $rowDoc = isset($row['document_type']) ? (string)$row['document_type'] : '';
        if ($rowDoc !== $docFilter) continue;
    }
    if ($projectFilter >= 0) {
        $rowProject = isset($row['project_id']) ? (int)$row['project_id'] : 0;
        if ($rowProject !== $projectFilter) continue;
    }
    $filtered[] = $row;
}

$items = array_slice($filtered, 0, 100);
$labelQuality = cpms_quality_view_text('%ED%92%88%EC%A7%88');
$labelUpload = cpms_quality_view_text('%EC%97%85%EB%A1%9C%EB%93%9C');
$labelList = cpms_quality_view_text('%ED%8C%8C%EC%9D%BC%20%EB%AA%A9%EB%A1%9D');
$labelProject = cpms_quality_view_text('%ED%94%84%EB%A1%9C%EC%A0%9D%ED%8A%B8');
$labelCommon = cpms_quality_view_text('%EA%B3%B5%ED%86%B5%20%ED%92%88%EC%A7%88');
$labelDocumentType = cpms_quality_view_text('%EB%AC%B8%EC%84%9C%EC%A2%85%EB%A5%98');
$labelBasisMonth = cpms_quality_view_text('%EA%B8%B0%EC%A4%80%EC%9B%94');
$labelBasisDate = cpms_quality_view_text('%EA%B8%B0%EC%A4%80%EC%9D%BC');
$labelTitle = cpms_quality_view_text('%EC%A0%9C%EB%AA%A9');
$labelMemo = cpms_quality_view_text('%EB%A9%94%EB%AA%A8');
$labelFiles = cpms_quality_view_text('%ED%8C%8C%EC%9D%BC');
$labelNoFiles = cpms_quality_view_text('%EB%93%B1%EB%A1%9D%EB%90%9C%20%ED%92%88%EC%A7%88%20%ED%8C%8C%EC%9D%BC%EC%9D%B4%20%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.');
$labelAll = cpms_quality_view_text('%EC%A0%84%EC%B2%B4');
$labelStorage = cpms_quality_view_text('%EC%A0%80%EC%9E%A5');
$labelUploadedAt = cpms_quality_view_text('%EB%93%B1%EB%A1%9D%EC%9D%BC');
$labelActions = cpms_quality_view_text('%EC%97%B4%EA%B8%B0');
$labelNoPermission = cpms_quality_view_text('%EC%97%85%EB%A1%9C%EB%93%9C%20%EA%B6%8C%ED%95%9C%EC%9D%B4%20%EC%9E%88%EB%8A%94%20%ED%92%88%EC%A7%88%20%EB%8C%80%EC%83%81%EC%9D%B4%20%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.');
?>

<div class="space-y-5">
  <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
    <div>
      <div class="text-sm text-gray-500"><?php echo h($labelQuality); ?></div>
      <h2 class="text-2xl font-extrabold text-gray-900"><?php echo h(cpms_quality_view_text('%ED%92%88%EC%A7%88%20%ED%8C%8C%EC%9D%BC%20%EA%B4%80%EB%A6%AC')); ?></h2>
    </div>
    <form method="get" class="flex flex-wrap items-center gap-2">
      <input type="hidden" name="r" value="quality_home">
      <select name="pid" class="h-10 rounded-lg border border-gray-200 bg-white px-3 text-sm">
        <option value="-1"><?php echo h($labelAll . ' ' . $labelProject); ?></option>
        <?php if ($canUploadCommon): ?>
          <option value="0" <?php echo $projectFilter === 0 ? 'selected' : ''; ?>><?php echo h($labelCommon); ?></option>
        <?php endif; ?>
        <?php foreach ($projects as $project): ?>
          <?php $pid = isset($project['id']) ? (int)$project['id'] : 0; ?>
          <option value="<?php echo h((string)$pid); ?>" <?php echo $projectFilter === $pid ? 'selected' : ''; ?>><?php echo h(isset($project['name']) ? $project['name'] : ('#' . $pid)); ?></option>
        <?php endforeach; ?>
      </select>
      <select name="doc" class="h-10 rounded-lg border border-gray-200 bg-white px-3 text-sm">
        <option value=""><?php echo h($labelAll . ' ' . $labelDocumentType); ?></option>
        <?php foreach ($documentOptions as $key => $label): ?>
          <option value="<?php echo h($key); ?>" <?php echo $docFilter === $key ? 'selected' : ''; ?>><?php echo h($label); ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="inline-flex h-10 items-center gap-2 rounded-lg bg-gray-900 px-3 text-sm font-bold text-white">
        <i data-lucide="search" class="h-4 w-4"></i><?php echo h(cpms_quality_view_text('%EC%A1%B0%ED%9A%8C')); ?>
      </button>
    </form>
  </div>

  <div class="grid grid-cols-1 xl:grid-cols-3 gap-5">
    <section class="xl:col-span-1 bg-white border border-gray-200 rounded-lg p-5">
      <div class="flex items-center justify-between mb-4">
        <h3 class="font-extrabold text-gray-900"><?php echo h($labelUpload); ?></h3>
        <span class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-cyan-50 text-cyan-700">
          <i data-lucide="upload-cloud" class="h-5 w-5"></i>
        </span>
      </div>

      <?php if (!$hasUploadTarget): ?>
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm font-bold text-amber-700"><?php echo h($labelNoPermission); ?></div>
      <?php else: ?>
        <form method="post" action="<?php echo h(base_url()); ?>/?r=quality/file_upload" enctype="multipart/form-data" class="space-y-4">
          <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">

          <label class="block">
            <span class="mb-1 block text-sm font-bold text-gray-700"><?php echo h($labelProject); ?></span>
            <select name="project_id" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" required>
              <?php if ($canUploadCommon): ?>
                <option value="0"><?php echo h($labelCommon); ?></option>
              <?php endif; ?>
              <?php foreach ($projects as $project): ?>
                <?php $pid = isset($project['id']) ? (int)$project['id'] : 0; ?>
                <?php if ($pid > 0 && !cpms_quality_file_can_upload($pdo, $pid)) continue; ?>
                <option value="<?php echo h((string)$pid); ?>"><?php echo h(isset($project['name']) ? $project['name'] : ('#' . $pid)); ?></option>
              <?php endforeach; ?>
            </select>
          </label>

          <label class="block">
            <span class="mb-1 block text-sm font-bold text-gray-700"><?php echo h($labelDocumentType); ?></span>
            <select name="document_type" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" required>
              <?php foreach ($documentOptions as $key => $label): ?>
                <option value="<?php echo h($key); ?>"><?php echo h($label); ?></option>
              <?php endforeach; ?>
            </select>
          </label>

          <div class="grid grid-cols-2 gap-3">
            <label class="block">
              <span class="mb-1 block text-sm font-bold text-gray-700"><?php echo h($labelBasisMonth); ?></span>
              <input type="month" name="basis_month" value="<?php echo h(date('Y-m')); ?>" class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
            </label>
            <label class="block">
              <span class="mb-1 block text-sm font-bold text-gray-700"><?php echo h($labelBasisDate); ?></span>
              <input type="date" name="basis_date" value="<?php echo h(date('Y-m-d')); ?>" class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
            </label>
          </div>

          <label class="block">
            <span class="mb-1 block text-sm font-bold text-gray-700"><?php echo h($labelTitle); ?></span>
            <input type="text" name="title" maxlength="120" class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
          </label>

          <label class="block">
            <span class="mb-1 block text-sm font-bold text-gray-700"><?php echo h($labelMemo); ?></span>
            <textarea name="description" rows="3" class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm"></textarea>
          </label>

          <label class="block">
            <span class="mb-1 block text-sm font-bold text-gray-700"><?php echo h($labelFiles); ?></span>
            <input type="file" name="quality_files[]" multiple class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm" accept=".pdf,.xlsx,.xls,.csv,.jpg,.jpeg,.png,.webp,.zip,.doc,.docx,.hwp,.hwpx,.txt" required>
          </label>

          <button type="submit" class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-cyan-700 px-4 py-3 text-sm font-extrabold text-white">
            <i data-lucide="upload" class="h-4 w-4"></i><?php echo h($labelUpload); ?>
          </button>
        </form>
      <?php endif; ?>
    </section>

    <section class="xl:col-span-2 bg-white border border-gray-200 rounded-lg overflow-hidden">
      <div class="flex items-center justify-between border-b border-gray-100 p-5">
        <h3 class="font-extrabold text-gray-900"><?php echo h($labelList); ?></h3>
        <span class="text-sm font-bold text-gray-500"><?php echo h((string)count($items)); ?></span>
      </div>

      <div class="overflow-x-auto">
        <table class="min-w-full border-collapse text-sm">
          <thead>
            <tr class="bg-gray-50 text-left text-xs font-bold text-gray-500">
              <th class="p-3 border-b border-gray-100"><?php echo h($labelDocumentType); ?></th>
              <th class="p-3 border-b border-gray-100"><?php echo h($labelProject); ?></th>
              <th class="p-3 border-b border-gray-100"><?php echo h($labelFiles); ?></th>
              <th class="p-3 border-b border-gray-100"><?php echo h($labelStorage); ?></th>
              <th class="p-3 border-b border-gray-100"><?php echo h($labelUploadedAt); ?></th>
              <th class="p-3 border-b border-gray-100"><?php echo h($labelActions); ?></th>
            </tr>
          </thead>
          <tbody>
            <?php if (count($items) === 0): ?>
              <tr>
                <td colspan="6" class="p-6 text-center text-gray-500"><?php echo h($labelNoFiles); ?></td>
              </tr>
            <?php endif; ?>
            <?php foreach ($items as $row): ?>
              <?php
                $docType = isset($row['document_type']) ? (string)$row['document_type'] : 'etc';
                $docLabel = cpms_quality_file_document_label($docType);
                $projectName = isset($row['project_name']) && trim((string)$row['project_name']) !== '' ? trim((string)$row['project_name']) : $labelCommon;
                $year = isset($row['document_year']) ? (string)$row['document_year'] : '';
                $month = isset($row['document_month']) ? (string)$row['document_month'] : '';
                $original = isset($row['original_name']) ? (string)$row['original_name'] : '';
                $title = isset($row['title']) && trim((string)$row['title']) !== '' ? (string)$row['title'] : $original;
                $isDrive = cpms_quality_drive_is_drive_file($row);
                $storage = $isDrive ? 'Google Drive' : 'Local';
                $statusClass = $isDrive ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-gray-50 text-gray-700 border-gray-200';
              ?>
              <tr class="align-top hover:bg-gray-50/70">
                <td class="p-3 border-b border-gray-100">
                  <div class="font-extrabold text-gray-900"><?php echo h($docLabel); ?></div>
                  <div class="mt-1 text-xs text-gray-500"><?php echo h($year . ($month !== '' ? ' / ' . $month : '')); ?></div>
                </td>
                <td class="p-3 border-b border-gray-100 text-gray-700"><?php echo h($projectName); ?></td>
                <td class="p-3 border-b border-gray-100">
                  <div class="max-w-xs truncate font-bold text-gray-900" title="<?php echo h($title); ?>"><?php echo h($title); ?></div>
                  <div class="mt-1 max-w-xs truncate text-xs text-gray-500" title="<?php echo h($original); ?>"><?php echo h($original); ?></div>
                  <div class="mt-1 text-xs text-gray-400"><?php echo h(cpms_quality_view_file_size(isset($row['file_size']) ? $row['file_size'] : 0)); ?></div>
                </td>
                <td class="p-3 border-b border-gray-100">
                  <span class="inline-flex rounded-lg border px-2 py-1 text-xs font-bold <?php echo h($statusClass); ?>"><?php echo h($storage); ?></span>
                </td>
                <td class="p-3 border-b border-gray-100 text-xs text-gray-500"><?php echo h(isset($row['uploaded_at']) ? (string)$row['uploaded_at'] : ''); ?></td>
                <td class="p-3 border-b border-gray-100"><?php echo cpms_quality_file_actions_html($row); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</div>
