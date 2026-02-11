<?php
/**
 * 공사 샘플 프로젝트(요청명) 원가/공정 데이터 생성
 * - 공정률 70%
 * - 원가율 87% (주간 기준)
 */
require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) { header('Location:?r=login'); exit; }
if (!(Auth::userRole()==='executive' || Auth::userDepartment()==='공사')) { http_response_code(403); exit; }
if ($_SERVER['REQUEST_METHOD']!=='POST' || !csrf_check(isset($_POST['_csrf'])?(string)$_POST['_csrf']:'')) { header('Location:?r=공사'); exit; }

$pdo = Db::pdo();
if (!$pdo) {
    flash_set('error', 'DB 연결 실패 (샘플 데이터 생성 불가)');
    header('Location:?r=공사');
    exit;
}

$projectName = '25년 천안 C5 UT동 지하 응축수 Tank 구축공사 중 패드공사';
$client = '샘플 발주처';
$contractor = '샘플 시공사';
$location = '천안 C5 UT동 지하';
$startDate = date('Y-m-01');
$endDate = date('Y-m-t', strtotime('+3 month'));
$status = '진행중';

$totalContract = 10000000.00; // 계약금액(단가표 총액)
$progressAmount = 7000000.00; // 내부기성(주간)
$actualCost = 6090000.00;     // 실제원가(주간) = 87%

$unitQty = 100.0;
$unitPrice = 100000.0;
$doneQty = 70.0;

$today = date('Y-m-d');
$curYm = date('Y-m');

try {
    $pdo->beginTransaction();

    // 1) 프로젝트 upsert
    $st = $pdo->prepare('SELECT id FROM cpms_projects WHERE name = :name ORDER BY id DESC LIMIT 1');
    $st->bindValue(':name', $projectName);
    $st->execute();
    $pid = (int)$st->fetchColumn();

    if ($pid > 0) {
        $stU = $pdo->prepare('UPDATE cpms_projects SET client=:client, contractor=:contractor, location=:location, start_date=:sd, end_date=:ed, contract_amount=:amt, status=:status, updated_at=NOW() WHERE id=:id');
        $stU->bindValue(':client', $client);
        $stU->bindValue(':contractor', $contractor);
        $stU->bindValue(':location', $location);
        $stU->bindValue(':sd', $startDate);
        $stU->bindValue(':ed', $endDate);
        $stU->bindValue(':amt', (int)$totalContract, PDO::PARAM_INT);
        $stU->bindValue(':status', $status);
        $stU->bindValue(':id', $pid, PDO::PARAM_INT);
        $stU->execute();
    } else {
        $stI = $pdo->prepare('INSERT INTO cpms_projects(name, client, contractor, location, start_date, end_date, contract_amount, status) VALUES(:name,:client,:contractor,:location,:sd,:ed,:amt,:status)');
        $stI->bindValue(':name', $projectName);
        $stI->bindValue(':client', $client);
        $stI->bindValue(':contractor', $contractor);
        $stI->bindValue(':location', $location);
        $stI->bindValue(':sd', $startDate);
        $stI->bindValue(':ed', $endDate);
        $stI->bindValue(':amt', (int)$totalContract, PDO::PARAM_INT);
        $stI->bindValue(':status', $status);
        $stI->execute();
        $pid = (int)$pdo->lastInsertId();
    }

    // 2) 기존 원가/공정 샘플 데이터 초기화
    $st = $pdo->prepare('DELETE FROM cpms_daily_work_qty WHERE project_id=:pid');
    $st->bindValue(':pid', $pid, PDO::PARAM_INT);
    $st->execute();

    $st = $pdo->prepare('DELETE FROM cpms_daily_cost_entries WHERE project_id=:pid');
    $st->bindValue(':pid', $pid, PDO::PARAM_INT);
    $st->execute();

    $st = $pdo->prepare('DELETE FROM cpms_monthly_recognized WHERE project_id=:pid');
    $st->bindValue(':pid', $pid, PDO::PARAM_INT);
    $st->execute();

    $st = $pdo->prepare('DELETE FROM cpms_project_unit_prices WHERE project_id=:pid');
    $st->bindValue(':pid', $pid, PDO::PARAM_INT);
    $st->execute();

    // 3) 단가표(총액 1,000만원)
    $st = $pdo->prepare('INSERT INTO cpms_project_unit_prices(project_id, item_name, spec, unit, qty, unit_price, labor_unit_price, material_unit_price, safety_unit_price, is_safety, remark) VALUES(:pid,:name,:spec,:unit,:qty,:up,:labor,:material,:safety,:is_safety,:remark)');
    $st->bindValue(':pid', $pid, PDO::PARAM_INT);
    $st->bindValue(':name', '패드 콘크리트 타설');
    $st->bindValue(':spec', '샘플');
    $st->bindValue(':unit', '식');
    $st->bindValue(':qty', $unitQty);
    $st->bindValue(':up', $unitPrice);
    $st->bindValue(':labor', 40000.0);
    $st->bindValue(':material', 55000.0);
    $st->bindValue(':safety', 5000.0);
    $st->bindValue(':is_safety', 0, PDO::PARAM_INT);
    $st->bindValue(':remark', '요청 샘플 데이터(공정률 70% / 원가율 87%)');
    $st->execute();
    $unitPriceId = (int)$pdo->lastInsertId();

    // 4) 실적수량(이번 주 기준 내부기성 700만원)
    $st = $pdo->prepare('INSERT INTO cpms_daily_work_qty(project_id, unit_price_id, work_date, done_qty, memo) VALUES(:pid,:uid,:wd,:qty,:memo)');
    $st->bindValue(':pid', $pid, PDO::PARAM_INT);
    $st->bindValue(':uid', $unitPriceId, PDO::PARAM_INT);
    $st->bindValue(':wd', $today);
    $st->bindValue(':qty', $doneQty);
    $st->bindValue(':memo', '요청 샘플 실적');
    $st->execute();

    // 5) 실제원가(이번 주 기준 합계 609만원 -> 원가율 87%)
    $costRows = array(
        array('노무', 2500000.00),
        array('자재', 3290000.00),
        array('안전', 300000.00),
    );
    $st = $pdo->prepare('INSERT INTO cpms_daily_cost_entries(project_id, cost_date, cost_type, amount, memo) VALUES(:pid,:cd,:type,:amt,:memo)');
    foreach ($costRows as $r) {
        $st->bindValue(':pid', $pid, PDO::PARAM_INT);
        $st->bindValue(':cd', $today);
        $st->bindValue(':type', $r[0]);
        $st->bindValue(':amt', $r[1]);
        $st->bindValue(':memo', '요청 샘플 원가');
        $st->execute();
    }

    // 6) 월별 인정기성(참고용)
    $st = $pdo->prepare('INSERT INTO cpms_monthly_recognized(project_id, ym, recognized_cum_amount) VALUES(:pid,:ym,:amt)');
    $st->bindValue(':pid', $pid, PDO::PARAM_INT);
    $st->bindValue(':ym', $curYm);
    $st->bindValue(':amt', $progressAmount);
    $st->execute();

    $pdo->commit();

    $progressRate = ($progressAmount / $totalContract) * 100.0;
    $costRate = ($actualCost / $progressAmount) * 100.0;

    flash_set('success', '샘플 프로젝트 적용 완료: 공정률 '.number_format($progressRate, 2).'% / 원가율 '.number_format($costRate, 2).'%');
    header('Location:?r=공사&pid='.(int)$pid.'&tab=cost_progress&sub=summary&period=week');
    exit;
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash_set('error', '샘플 프로젝트 적용 실패: ' . $e->getMessage());
    header('Location:?r=공사');
    exit;
}