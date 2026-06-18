<?php
/**
 * Master-only data archive management.
 * PHP 5.6 compatible.
 */

use App\Core\Auth;

require_once __DIR__ . '/../../services/DataArchiveService.php';

if (!Auth::isMaster()) {
    echo '<div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 font-bold">Master permission is required.</div>';
    return;
}

if (!function_exists('cpms_archive_view_bytes')) {
function cpms_archive_view_bytes($bytes) {
    $bytes = (float)$bytes;
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    $idx = 0;
    while ($bytes >= 1024 && $idx < count($units) - 1) {
        $bytes = $bytes / 1024;
        $idx++;
    }
    return number_format($bytes, $idx === 0 ? 0 : 2) . ' ' . $units[$idx];
}}

if (!function_exists('cpms_archive_view_badge')) {
function cpms_archive_view_badge($ok, $text) {
    $class = $ok ? 'bg-emerald-50 border-emerald-200 text-emerald-700' : 'bg-amber-50 border-amber-200 text-amber-700';
    return '<span class="inline-flex px-2 py-1 rounded-lg border text-xs font-bold ' . $class . '">' . h($text) . '</span>';
}}

$types = cpms_archive_type_definitions();
$policy = cpms_archive_policy();
$cutoffYear = cpms_archive_get_cutoff_year((int)date('Y'));
$selectedYear = isset($_REQUEST['archive_year']) ? (int)$_REQUEST['archive_year'] : $cutoffYear;
if ($selectedYear < 2000 || $selectedYear > 2100) $selectedYear = $cutoffYear;
$selectedType = isset($_REQUEST['archive_type']) ? trim((string)$_REQUEST['archive_type']) : 'company_overhead';
if (!isset($types[$selectedType])) $selectedType = 'company_overhead';
$result = null;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
    if (!csrf_check($token)) {
        $error = 'Security token is invalid. Please refresh and try again.';
    } else {
        $action = isset($_POST['archive_action']) ? trim((string)$_POST['archive_action']) : 'dry_run';
        if ($action === 'dry_run') {
            $result = cpms_archive_run($selectedYear, $selectedType, true, '', Auth::user());
        } else if ($action === 'archive') {
            $confirm = isset($_POST['confirm_text']) ? trim((string)$_POST['confirm_text']) : '';
            $result = cpms_archive_run($selectedYear, $selectedType, false, $confirm, Auth::user());
        } else if ($action === 'remove_local') {
            $archiveId = isset($_POST['archive_id']) ? trim((string)$_POST['archive_id']) : '';
            $mode = isset($_POST['remove_mode']) ? trim((string)$_POST['remove_mode']) : 'move';
            $confirm2 = isset($_POST['remove_confirm']) ? trim((string)$_POST['remove_confirm']) : '';
            $result = cpms_archive_remove_local_details($selectedYear, $archiveId, $mode, $confirm2, Auth::user());
        } else if ($action === 'restore') {
            $archiveId2 = isset($_POST['archive_id']) ? trim((string)$_POST['archive_id']) : '';
            $confirm3 = isset($_POST['restore_confirm']) ? trim((string)$_POST['restore_confirm']) : '';
            $overwrite = isset($_POST['restore_overwrite']) && (string)$_POST['restore_overwrite'] === '1';
            $result = cpms_archive_restore_from_drive($selectedYear, $archiveId2, $confirm3, $overwrite, Auth::user());
        } else if ($action === 'cleanup_cache') {
            $result = cpms_archive_cleanup_cache();
        }
    }
}

$usage = cpms_archive_usage_report();
$indexData = cpms_archive_load_index($selectedYear);
$summaryData = cpms_archive_read_json(cpms_archive_summary_path($selectedYear), array());
$targetPreview = cpms_archive_find_targets($selectedYear, $selectedType);
$summaryPreview = !empty($targetPreview['ok']) ? cpms_archive_build_summary_from_targets($targetPreview) : array();
?>

<div class="space-y-5">
  <div>
    <div class="text-sm text-gray-500"><?php echo h(urldecode('%EA%B4%80%EB%A6%AC%20%2F%20%EC%8B%9C%EC%8A%A4%ED%85%9C%EC%A0%90%EA%B2%80')); ?></div>
    <h3 class="text-xl font-extrabold text-gray-900"><?php echo h(urldecode('%EB%8D%B0%EC%9D%B4%ED%84%B0%20%EC%95%84%EC%B9%B4%EC%9D%B4%EB%B8%8C%20%EA%B4%80%EB%A6%AC')); ?></h3>
    <div class="text-sm text-gray-500 mt-1">Dry-run is the default. Local JSON details are never removed until a verified archive exists and Master confirms.</div>
  </div>

  <?php if ($error !== ''): ?>
    <div class="p-4 rounded-2xl border border-red-200 bg-red-50 text-red-700 font-bold"><?php echo h($error); ?></div>
  <?php endif; ?>

  <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
    <div class="bg-white border border-gray-200 rounded-2xl p-4">
      <div class="font-extrabold text-gray-900">Archive policy</div>
      <table class="w-full mt-3 text-sm">
        <tr><th class="text-left py-2 text-gray-500">Enabled</th><td class="py-2"><?php echo cpms_archive_view_badge(!empty($policy['archive_enabled']), !empty($policy['archive_enabled']) ? 'enabled' : 'disabled'); ?></td></tr>
        <tr><th class="text-left py-2 text-gray-500">Keep recent years</th><td class="py-2"><?php echo h((string)$policy['keep_recent_years']); ?></td></tr>
        <tr><th class="text-left py-2 text-gray-500">Archive cutoff</th><td class="py-2"><?php echo h((string)$cutoffYear); ?> and older</td></tr>
        <tr><th class="text-left py-2 text-gray-500">Compression</th><td class="py-2"><?php echo h((string)$policy['archive_compression']); ?> <?php echo function_exists('gzencode') ? '' : '(gzip unavailable)'; ?></td></tr>
        <tr><th class="text-left py-2 text-gray-500">Cache hours</th><td class="py-2"><?php echo h((string)$policy['archive_restore_cache_hours']); ?></td></tr>
      </table>
    </div>

    <div class="bg-white border border-gray-200 rounded-2xl p-4">
      <div class="font-extrabold text-gray-900">Server data usage</div>
      <div class="mt-3 text-2xl font-extrabold text-gray-900"><?php echo h(cpms_archive_view_bytes($usage['total_size'])); ?></div>
      <div class="text-sm text-gray-500"><?php echo h((string)$usage['file_count']); ?> JSON files under data</div>
      <div class="mt-3 max-h-40 overflow-auto text-xs">
        <?php foreach ($usage['by_year'] as $yearKey => $row): ?>
          <div class="flex justify-between border-b border-gray-100 py-1">
            <span><?php echo h((string)$yearKey); ?></span>
            <span><?php echo h(cpms_archive_view_bytes(isset($row['size']) ? $row['size'] : 0)); ?> / <?php echo h((string)(isset($row['file_count']) ? $row['file_count'] : 0)); ?> files</span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="bg-white border border-gray-200 rounded-2xl p-4">
      <div class="font-extrabold text-gray-900">Drive location rule</div>
      <div class="mt-3 text-sm text-gray-700 leading-6">
        <div><strong>General:</strong> 00 system backup / archive root / YYYY</div>
        <div><strong>Sensitive:</strong> 04 management / archive root / YYYY</div>
        <div class="mt-2 text-xs text-gray-500">Payroll, payroll statements, resident-number encrypted data, account-number data and sensitive overhead items use the management location.</div>
      </div>
    </div>
  </div>

  <form method="post" action="?r=<?php echo urlencode(urldecode('%EA%B4%80%EB%A6%AC')); ?>&tab=data_archive" class="bg-white border border-gray-200 rounded-2xl p-4">
    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
    <div class="grid grid-cols-1 md:grid-cols-5 gap-3 items-end">
      <label class="block text-sm font-bold text-gray-700">
        <span class="block mb-2">Year</span>
        <input type="number" name="archive_year" min="2000" max="2100" value="<?php echo h((string)$selectedYear); ?>" class="w-full px-3 py-3 rounded-xl border border-gray-300">
      </label>
      <label class="block text-sm font-bold text-gray-700 md:col-span-2">
        <span class="block mb-2">Archive type</span>
        <select name="archive_type" class="w-full px-3 py-3 rounded-xl border border-gray-300">
          <?php foreach ($types as $typeKey => $typeDef): ?>
            <?php $sensitivity = isset($typeDef['sensitivity']) ? (string)$typeDef['sensitivity'] : 'general'; ?>
            <option value="<?php echo h($typeKey); ?>" <?php echo $selectedType === $typeKey ? 'selected' : ''; ?>>
              <?php echo h($typeKey . ' - ' . (isset($typeDef['label']) ? $typeDef['label'] : '') . ' / ' . $sensitivity); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <button type="submit" name="archive_action" value="dry_run" class="px-4 py-3 rounded-xl bg-gray-900 text-white font-extrabold">Dry-run</button>
      <div class="flex gap-2">
        <input type="text" name="confirm_text" value="" placeholder="YES" class="w-24 px-3 py-3 rounded-xl border border-gray-300">
        <button type="submit" name="archive_action" value="archive" onclick="return confirm('Create archive and upload to Drive? Local data will not be removed.');" class="px-4 py-3 rounded-xl bg-emerald-700 text-white font-extrabold">Archive</button>
      </div>
    </div>
    <div class="text-xs text-gray-500 mt-2">Actual archive requires YES. Upload, size/checksum, download, gzip/JSON verification, index and summary write are performed before any local removal is possible.</div>
  </form>

  <div class="bg-white border border-gray-200 rounded-2xl p-4">
    <div class="flex flex-wrap justify-between gap-3">
      <div>
        <div class="font-extrabold text-gray-900">Current target preview</div>
        <div class="text-sm text-gray-500 mt-1"><?php echo h(isset($targetPreview['message']) ? $targetPreview['message'] : ''); ?></div>
      </div>
      <div><?php echo cpms_archive_view_badge(!empty($targetPreview['archive_allowed']), !empty($targetPreview['archive_allowed']) ? 'archive target' : 'server retained'); ?></div>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-4 gap-3 mt-4 text-sm">
      <div class="p-3 rounded-xl bg-gray-50 border border-gray-100"><strong>Files</strong><br><?php echo h((string)(isset($targetPreview['file_count']) ? $targetPreview['file_count'] : 0)); ?></div>
      <div class="p-3 rounded-xl bg-gray-50 border border-gray-100"><strong>Records</strong><br><?php echo h((string)(isset($targetPreview['record_count']) ? $targetPreview['record_count'] : 0)); ?></div>
      <div class="p-3 rounded-xl bg-gray-50 border border-gray-100"><strong>Original size</strong><br><?php echo h(cpms_archive_view_bytes(isset($targetPreview['original_size']) ? $targetPreview['original_size'] : 0)); ?></div>
      <div class="p-3 rounded-xl bg-gray-50 border border-gray-100"><strong>Expected Drive path</strong><br><span class="text-xs"><?php echo h(isset($targetPreview['expected_drive_path']) ? $targetPreview['expected_drive_path'] : ''); ?></span></div>
    </div>
    <?php if (!empty($targetPreview['invalid_json'])): ?>
      <div class="mt-3 p-3 rounded-xl bg-red-50 border border-red-200 text-red-700 text-sm font-bold">Invalid JSON exists. Actual archive is blocked.</div>
    <?php endif; ?>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mt-4">
      <div>
        <div class="font-bold text-gray-800 mb-2">Removable after verified archive</div>
        <div class="max-h-48 overflow-auto text-xs border border-gray-100 rounded-xl p-3 bg-gray-50">
          <?php foreach ((isset($targetPreview['removable_files']) && is_array($targetPreview['removable_files'])) ? $targetPreview['removable_files'] : array() as $file): ?>
            <div><?php echo h($file); ?></div>
          <?php endforeach; ?>
          <?php if (empty($targetPreview['removable_files'])): ?><div class="text-gray-500">No removable year-partitioned files.</div><?php endif; ?>
        </div>
      </div>
      <div>
        <div class="font-bold text-gray-800 mb-2">Summary preview</div>
        <div class="max-h-48 overflow-auto text-xs border border-gray-100 rounded-xl p-3 bg-gray-50">
          <pre><?php echo h(cpms_archive_json_encode($summaryPreview)); ?></pre>
        </div>
      </div>
    </div>
  </div>

  <?php if (is_array($result)): ?>
    <div class="bg-white border <?php echo !empty($result['ok']) ? 'border-emerald-200' : 'border-red-200'; ?> rounded-2xl p-4">
      <div class="font-extrabold <?php echo !empty($result['ok']) ? 'text-emerald-800' : 'text-red-700'; ?>">Action result</div>
      <div class="text-sm mt-2"><?php echo h(isset($result['message']) ? $result['message'] : ''); ?></div>
      <div class="max-h-80 overflow-auto mt-3 text-xs bg-gray-50 border border-gray-100 rounded-xl p-3">
        <pre><?php echo h(cpms_archive_json_encode($result)); ?></pre>
      </div>
    </div>
  <?php endif; ?>

  <div class="bg-white border border-gray-200 rounded-2xl p-4">
    <div class="font-extrabold text-gray-900">Verified archive index</div>
    <div class="overflow-auto mt-3">
      <table class="w-full border-collapse text-sm">
        <thead>
          <tr>
            <th class="text-left p-3 border-b bg-gray-50">Archive ID</th>
            <th class="text-left p-3 border-b bg-gray-50">Type</th>
            <th class="text-left p-3 border-b bg-gray-50">Sensitivity</th>
            <th class="text-left p-3 border-b bg-gray-50">Status</th>
            <th class="text-left p-3 border-b bg-gray-50">Drive file</th>
            <th class="text-left p-3 border-b bg-gray-50">Local removed</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($indexData['archives'] as $archiveRow): ?>
            <?php if (!is_array($archiveRow)) continue; ?>
            <tr>
              <td class="p-3 border-b"><?php echo h(isset($archiveRow['archive_id']) ? $archiveRow['archive_id'] : ''); ?></td>
              <td class="p-3 border-b"><?php echo h(isset($archiveRow['archive_type']) ? $archiveRow['archive_type'] : ''); ?></td>
              <td class="p-3 border-b"><?php echo h(isset($archiveRow['sensitivity']) ? $archiveRow['sensitivity'] : ''); ?></td>
              <td class="p-3 border-b"><?php echo h(isset($archiveRow['status']) ? $archiveRow['status'] : ''); ?></td>
              <td class="p-3 border-b"><code><?php echo h(isset($archiveRow['drive_file_id']) ? $archiveRow['drive_file_id'] : ''); ?></code></td>
              <td class="p-3 border-b"><?php echo !empty($archiveRow['local_detail_removed']) ? 'yes' : 'no'; ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($indexData['archives'])): ?>
            <tr><td colspan="6" class="p-3 text-gray-500">No archive index entries for this year.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
    <form method="post" action="?r=<?php echo urlencode(urldecode('%EA%B4%80%EB%A6%AC')); ?>&tab=data_archive" class="bg-white border border-gray-200 rounded-2xl p-4">
      <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
      <input type="hidden" name="archive_year" value="<?php echo h((string)$selectedYear); ?>">
      <input type="hidden" name="archive_type" value="<?php echo h($selectedType); ?>">
      <div class="font-extrabold text-gray-900">Remove local details</div>
      <div class="text-xs text-gray-500 mt-1">Enabled only by verified index status. Move is the default.</div>
      <label class="block text-sm font-bold mt-3">
        <span class="block mb-2">Archive ID</span>
        <input type="text" name="archive_id" value="<?php echo h($selectedType . '_' . sprintf('%04d', $selectedYear)); ?>" class="w-full px-3 py-3 rounded-xl border border-gray-300">
      </label>
      <label class="block text-sm font-bold mt-3">
        <span class="block mb-2">Mode</span>
        <select name="remove_mode" class="w-full px-3 py-3 rounded-xl border border-gray-300">
          <option value="move">Move to storage/archive_pending_delete</option>
          <option value="delete">Final delete</option>
        </select>
      </label>
      <label class="block text-sm font-bold mt-3">
        <span class="block mb-2">Confirm</span>
        <input type="text" name="remove_confirm" placeholder="YES or DELETE" class="w-full px-3 py-3 rounded-xl border border-gray-300">
      </label>
      <button type="submit" name="archive_action" value="remove_local" onclick="return confirm('Process local detail files for this verified archive?');" class="mt-3 px-4 py-3 rounded-xl bg-amber-700 text-white font-extrabold">Process local details</button>
    </form>

    <form method="post" action="?r=<?php echo urlencode(urldecode('%EA%B4%80%EB%A6%AC')); ?>&tab=data_archive" class="bg-white border border-gray-200 rounded-2xl p-4">
      <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
      <input type="hidden" name="archive_year" value="<?php echo h((string)$selectedYear); ?>">
      <input type="hidden" name="archive_type" value="<?php echo h($selectedType); ?>">
      <div class="font-extrabold text-gray-900">Restore from Drive</div>
      <div class="text-xs text-gray-500 mt-1">Existing files are not overwritten unless checked.</div>
      <label class="block text-sm font-bold mt-3">
        <span class="block mb-2">Archive ID</span>
        <input type="text" name="archive_id" value="<?php echo h($selectedType . '_' . sprintf('%04d', $selectedYear)); ?>" class="w-full px-3 py-3 rounded-xl border border-gray-300">
      </label>
      <label class="inline-flex items-center gap-2 text-sm font-bold mt-3">
        <input type="checkbox" name="restore_overwrite" value="1">
        Allow overwrite
      </label>
      <label class="block text-sm font-bold mt-3">
        <span class="block mb-2">Confirm</span>
        <input type="text" name="restore_confirm" placeholder="YES" class="w-full px-3 py-3 rounded-xl border border-gray-300">
      </label>
      <button type="submit" name="archive_action" value="restore" onclick="return confirm('Restore archive details from Drive?');" class="mt-3 px-4 py-3 rounded-xl bg-emerald-700 text-white font-extrabold">Restore</button>
    </form>

    <form method="post" action="?r=<?php echo urlencode(urldecode('%EA%B4%80%EB%A6%AC')); ?>&tab=data_archive" class="bg-white border border-gray-200 rounded-2xl p-4">
      <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
      <input type="hidden" name="archive_year" value="<?php echo h((string)$selectedYear); ?>">
      <input type="hidden" name="archive_type" value="<?php echo h($selectedType); ?>">
      <div class="font-extrabold text-gray-900">Cache and JSON views</div>
      <div class="text-xs text-gray-500 mt-1">Cache path: storage/tmp/archive_cache</div>
      <button type="submit" name="archive_action" value="cleanup_cache" class="mt-3 px-4 py-3 rounded-xl bg-gray-900 text-white font-extrabold">Cleanup cache</button>
      <div class="mt-4 text-xs bg-gray-50 border border-gray-100 rounded-xl p-3 max-h-52 overflow-auto">
        <div class="font-bold mb-2">archive_summary/<?php echo h(sprintf('%04d', $selectedYear)); ?>.json</div>
        <pre><?php echo h(cpms_archive_json_encode($summaryData)); ?></pre>
      </div>
    </form>
  </div>
</div>
