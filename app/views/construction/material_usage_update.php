<?php
/**
 * 공사 > 자재구입비 > 입력내역 수정
 * - 선택한 자재구입비 한 건의 사용일자/구분/선급여부/업체정보/금액/내역/비고 수정
 * - 공용 자재 마스터를 직접 덮어쓰지 않고 수정 대상 한 건만 새 정보로 연결
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/partials/project_month_options_helper.php';
require_once __DIR__ . '/partials/material_statement_helper.php';
require_once __DIR__ . '/partials/material_usage_helper.php';
require_once __DIR__ . '/../../services/CostChangeService.php';

use App\Core\Auth;
use App\Core\Db;
use App\Services\CostChangeService;

if (!Auth::check()) { header('Location: ?r=login'); exit; }
if (!Auth::canManageConstruction()) { http_response_code(403); echo '403 Forbidden'; exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'Method Not Allowed'; exit; }
if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
    flash_set('error', '보안 토큰 오류');
    header('Location: ?r=construction_home');
    exit;
}

$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$usageId = isset($_POST['usage_id']) ? (int)$_POST['usage_id'] : 0;
$useDate = isset($_POST['use_date']) ? trim((string)$_POST['use_date']) : '';
$category = isset($_POST['category']) ? trim((string)$_POST['category']) : '';
$advanceYn = cpms_material_advance_yn(isset($_POST['advance_yn']) ? $_POST['advance_yn'] : 'N');
$vendorName = isset($_POST['vendor_name']) ? trim((string)$_POST['vendor_name']) : '';
$representative = isset($_POST['representative']) ? trim((string)$_POST['representative']) : '';
$phone = isset($_POST['phone']) ? trim((string)$_POST['phone']) : '';
$bizNo = isset($_POST['biz_no']) ? trim((string)$_POST['biz_no']) : '';
$amountRaw = isset($_POST['amount']) ? (string)$_POST['amount'] : '';
$amountSign = isset($_POST['amount_sign']) ? trim((string)$_POST['amount_sign']) : '';
$memo = isset($_POST['memo']) ? trim((string)$_POST['memo']) : '';
$remark = isset($_POST['remark']) ? trim((string)$_POST['remark']) : '';
$materialsTab = isset($_POST['materials_tab']) ? trim((string)$_POST['materials_tab']) : 'input';
$defaultYm = cpms_construction_current_business_ym();
$ym = isset($_POST['ym']) ? trim((string)$_POST['ym']) : $defaultYm;
if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = $defaultYm;

$redirect = '?r=construction_home&pid=' . $projectId
    . '&tab=materials&materials_tab=' . urlencode($materialsTab)
    . '&ym=' . urlencode($ym);

/**
 * 화면에서 받은 금액을 숫자로 변환하고 일반/공제 부호를 적용한다.
 */
function cpms_material_usage_update_money($value, $signValue)
{
    $raw = trim((string)$value);
    if (!preg_match('/\d/', $raw)) return null;

    $isNegative = (strpos($raw, '-') !== false);
    $raw = str_replace(array(',', ' ', "\t", '원'), '', $raw);
    $raw = preg_replace('/[^0-9.]/', '', $raw);
    if ($raw === '' || $raw === '-' || $raw === '.') return null;

    $signValue = trim((string)$signValue);
    if ($signValue === '-' || $signValue === 'minus' || $signValue === 'deduct' || $signValue === 'negative') {
        $isNegative = true;
    } else if ($signValue === '+' || $signValue === 'plus' || $signValue === 'normal') {
        $isNegative = false;
    }

    $amount = (float)$raw;
    return $isNegative ? ($amount * -1) : $amount;
}

/**
 * 선택월의 자재구입비 입력 가능 범위(전월 26일~선택월 25일)를 반환한다.
 */
function cpms_material_usage_update_month_range($ym)
{
    $prevYm = date('Y-m', strtotime($ym . '-01 -1 month'));
    return array('start' => $prevYm . '-26', 'end' => $ym . '-25');
}

/**
 * 입력한 사용일자가 선택월 범위 안에 있는지 확인한다.
 */
function cpms_material_usage_update_in_range($date, $ym)
{
    $range = cpms_material_usage_update_month_range($ym);
    $ts = strtotime($date);
    $startTs = strtotime($range['start']);
    $endTs = strtotime($range['end']);
    if ($ts === false || $startTs === false || $endTs === false) return false;
    return ($ts >= $startTs && $ts <= $endTs);
}

$allowedCategories = array(
    '자재비' => true,
    '구매품' => true,
    '기타경비' => true
);
$amount = cpms_material_usage_update_money($amountRaw, $amountSign);

if (
    $projectId <= 0 ||
    $usageId <= 0 ||
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $useDate) ||
    $amount === null ||
    !isset($allowedCategories[$category]) ||
    $vendorName === ''
) {
    flash_set('error', '수정값이 올바르지 않습니다. 사용일자, 구분, 업체명, 공급가액을 확인해주세요.');
    header('Location: ' . $redirect);
    exit;
}

if (!cpms_material_usage_update_in_range($useDate, $ym)) {
    $range = cpms_material_usage_update_month_range($ym);
    flash_set('error', '사용일자는 ' . $range['start'] . ' ~ ' . $range['end'] . ' 범위만 저장할 수 있습니다.');
    header('Location: ' . $redirect);
    exit;
}

$pdo = Db::pdo();
if (!$pdo) {
    flash_set('error', 'DB 연결 실패');
    header('Location: ' . $redirect);
    exit;
}

cpms_material_usage_ensure_schema($pdo);
$hasMaterialAdvanceYn = cpms_material_usage_column_exists($pdo, 'advance_yn');

try {
    $st = $pdo->prepare("SELECT u.*, i.is_deleted, i.category, i.vendor_name, i.representative,
                               i.phone, i.biz_no, i.base_rate, i.remark
                          FROM cpms_material_usage u
                          JOIN cpms_material_items i
                            ON i.id = u.material_id
                           AND i.project_id = u.project_id
                         WHERE u.id = :id
                           AND u.project_id = :pid
                         LIMIT 1");
    $st->bindValue(':id', $usageId, PDO::PARAM_INT);
    $st->bindValue(':pid', $projectId, PDO::PARAM_INT);
    $st->execute();
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!is_array($row) || (isset($row['is_deleted']) && (int)$row['is_deleted'] === 1)) {
        flash_set('error', '수정할 자재구입비 사용내역을 찾을 수 없습니다.');
        header('Location: ' . $redirect);
        exit;
    }

    if (isset($row['category']) && trim((string)$row['category']) === '안전관리비') {
        flash_set('error', '안전관리비 사용내역은 안전섹션에서 수정해주세요.');
        header('Location: ' . $redirect);
        exit;
    }

    $sourceYm = CostChangeService::effectiveSettlementYm(
        $pdo,
        'material',
        (string)$usageId,
        'material',
        isset($row['use_date']) ? $row['use_date'] : ''
    );
    $sourceLock = CostChangeService::lockInfo(
        'material',
        isset($row['use_date']) ? $row['use_date'] : '',
        $sourceYm,
        date('Y-m-d')
    );
    $destinationLock = CostChangeService::lockInfo('material', $useDate, '', date('Y-m-d'));

    if (!empty($sourceLock['locked']) || !empty($destinationLock['locked'])) {
        flash_set('error', '마감된 기간의 자료입니다. 수정하려면 비용 변경 승인이 필요합니다.');
        header('Location: ' . $redirect);
        exit;
    }

    $baseRate = abs((float)$amount);
    $now = date('Y-m-d H:i:s');

    /*
     * 거래명세표 테이블 점검은 트랜잭션 시작 전에 처리한다.
     * MySQL의 테이블 변경 작업이 트랜잭션을 자동 종료하는 상황을 막기 위함이다.
     */
    $statementSchemaReady = false;
    try {
        $statementSchemaReady = cpms_material_statement_ensure_schema($pdo)
            && cpms_material_statement_schema_ready($pdo);
    } catch (Exception $statementSchemaException) {
        $statementSchemaReady = false;
    }

    $pdo->beginTransaction();

    /*
     * 업체정보는 cpms_material_items에 저장된다.
     * 기존 마스터를 바로 수정하면 같은 마스터를 쓰는 다른 날짜 자료까지 바뀔 수 있으므로,
     * 입력값이 완전히 같은 마스터를 재사용하거나 새 마스터를 만든 뒤 이 사용내역 한 건만 연결한다.
     */
    $stExact = $pdo->prepare("SELECT id
                               FROM cpms_material_items
                              WHERE project_id = :pid
                                AND category = :category
                                AND COALESCE(vendor_name, '') = :vendor_name
                                AND COALESCE(spec, '') = ''
                                AND COALESCE(representative, '') = :representative
                                AND COALESCE(phone, '') = :phone
                                AND COALESCE(biz_no, '') = :biz_no
                                AND base_rate = :base_rate
                                AND COALESCE(remark, '') = :remark
                                AND is_deleted = 0
                              ORDER BY id ASC
                              LIMIT 1");
    $stExact->bindValue(':pid', $projectId, PDO::PARAM_INT);
    $stExact->bindValue(':category', $category);
    $stExact->bindValue(':vendor_name', $vendorName);
    $stExact->bindValue(':representative', $representative);
    $stExact->bindValue(':phone', $phone);
    $stExact->bindValue(':biz_no', $bizNo);
    $stExact->bindValue(':base_rate', $baseRate);
    $stExact->bindValue(':remark', $remark);
    $stExact->execute();
    $targetMaterialId = (int)$stExact->fetchColumn();

    if ($targetMaterialId <= 0) {
        $stInsertItem = $pdo->prepare("INSERT INTO cpms_material_items
            (project_id, category, vendor_name, spec, representative, phone, biz_no,
             base_rate, remark, is_deleted, created_at, updated_at)
            VALUES
            (:pid, :category, :vendor_name, '', :representative, :phone, :biz_no,
             :base_rate, :remark, 0, :created_at, :updated_at)");
        $stInsertItem->bindValue(':pid', $projectId, PDO::PARAM_INT);
        $stInsertItem->bindValue(':category', $category);
        $stInsertItem->bindValue(':vendor_name', $vendorName);
        $stInsertItem->bindValue(':representative', $representative);
        $stInsertItem->bindValue(':phone', $phone);
        $stInsertItem->bindValue(':biz_no', $bizNo);
        $stInsertItem->bindValue(':base_rate', $baseRate);
        $stInsertItem->bindValue(':remark', $remark);
        $stInsertItem->bindValue(':created_at', $now);
        $stInsertItem->bindValue(':updated_at', $now);
        $stInsertItem->execute();
        $targetMaterialId = (int)$pdo->lastInsertId();
    }

    if ($targetMaterialId <= 0) {
        throw new Exception('수정할 업체정보를 저장하지 못했습니다.');
    }

    /* 변경 후 동일한 자재 마스터와 사용일자가 겹치는지 확인한다. */
    $stDup = $pdo->prepare("SELECT id
                             FROM cpms_material_usage
                            WHERE project_id = :pid
                              AND material_id = :mid
                              AND use_date = :use_date
                              AND id <> :id
                            LIMIT 1");
    $stDup->bindValue(':pid', $projectId, PDO::PARAM_INT);
    $stDup->bindValue(':mid', $targetMaterialId, PDO::PARAM_INT);
    $stDup->bindValue(':use_date', $useDate);
    $stDup->bindValue(':id', $usageId, PDO::PARAM_INT);
    $stDup->execute();
    if ($stDup->fetch()) {
        throw new Exception('같은 업체정보와 사용일자로 이미 등록된 자재구입비가 있습니다.');
    }

    /* 선택한 자재구입비 한 건만 수정한다. */
    if ($hasMaterialAdvanceYn) {
        $up = $pdo->prepare("UPDATE cpms_material_usage
                               SET material_id = :material_id,
                                   use_date = :use_date,
                                   amount = :amount,
                                   advance_yn = :advance_yn,
                                   memo = :memo
                             WHERE id = :id
                               AND project_id = :pid");
        $up->bindValue(':advance_yn', $advanceYn);
    } else {
        $up = $pdo->prepare("UPDATE cpms_material_usage
                               SET material_id = :material_id,
                                   use_date = :use_date,
                                   amount = :amount,
                                   memo = :memo
                             WHERE id = :id
                               AND project_id = :pid");
    }
    $up->bindValue(':material_id', $targetMaterialId, PDO::PARAM_INT);
    $up->bindValue(':use_date', $useDate);
    $up->bindValue(':amount', $amount);
    $up->bindValue(':memo', $memo);
    $up->bindValue(':id', $usageId, PDO::PARAM_INT);
    $up->bindValue(':pid', $projectId, PDO::PARAM_INT);
    $up->execute();

    /* 업체명 자동완성용 공용 프리셋도 최신 정보로 갱신한다. */
    try {
        $stPreset = $pdo->prepare("INSERT INTO cpms_material_vendor_presets
            (vendor_name, category, representative, phone, biz_no, base_rate, remark, created_at, updated_at)
            VALUES
            (:vendor_name, :category, :representative, :phone, :biz_no, :base_rate, :remark, :created_at, :updated_at)
            ON DUPLICATE KEY UPDATE
                category = VALUES(category),
                representative = VALUES(representative),
                phone = VALUES(phone),
                biz_no = VALUES(biz_no),
                base_rate = VALUES(base_rate),
                remark = VALUES(remark),
                updated_at = VALUES(updated_at)");
        $stPreset->bindValue(':vendor_name', $vendorName);
        $stPreset->bindValue(':category', $category);
        $stPreset->bindValue(':representative', $representative);
        $stPreset->bindValue(':phone', $phone);
        $stPreset->bindValue(':biz_no', $bizNo);
        $stPreset->bindValue(':base_rate', $baseRate);
        $stPreset->bindValue(':remark', $remark);
        $stPreset->bindValue(':created_at', $now);
        $stPreset->bindValue(':updated_at', $now);
        $stPreset->execute();
    } catch (Exception $presetException) {
        /* 자동완성 프리셋 실패는 본 자재구입비 수정에 영향을 주지 않는다. */
    }

    /* 거래명세표 연결 날짜와 정산월도 함께 맞춘다. */
    if ($statementSchemaReady) {
        $stStatement = $pdo->prepare("UPDATE cpms_material_statement_files
                                        SET use_date = :use_date,
                                            ym = :ym
                                      WHERE project_id = :pid
                                        AND material_usage_id = :usage_id
                                        AND is_deleted = 0");
        $stStatement->bindValue(':use_date', $useDate);
        $stStatement->bindValue(':ym', $ym);
        $stStatement->bindValue(':pid', $projectId, PDO::PARAM_INT);
        $stStatement->bindValue(':usage_id', $usageId, PDO::PARAM_INT);
        $stStatement->execute();
    }

    $pdo->commit();

    $successMessage = '자재구입비 사용내역과 업체정보를 수정했습니다.';
    $uploadResult = cpms_material_statement_store_uploaded_file_for_usage_rows(
        $pdo,
        'statement_file',
        $projectId,
        $targetMaterialId,
        array(array('id' => $usageId, 'use_date' => $useDate)),
        $ym
    );

    if (isset($uploadResult['has_file']) && $uploadResult['has_file']) {
        if (isset($uploadResult['ok']) && $uploadResult['ok']) {
            $successMessage .= ' ' . (
                isset($uploadResult['message']) && trim((string)$uploadResult['message']) !== ''
                ? (string)$uploadResult['message']
                : '거래명세표를 첨부했습니다.'
            );
            flash_set('success', $successMessage);
        } else {
            flash_set(
                'error',
                $successMessage . ' 다만 거래명세표 첨부 실패: '
                . (isset($uploadResult['message']) ? $uploadResult['message'] : '알 수 없는 오류')
            );
        }
    } else {
        flash_set('success', $successMessage);
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash_set('error', '수정 실패: ' . $e->getMessage());
}

header('Location: ' . $redirect);
exit;
