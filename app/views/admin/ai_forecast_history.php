<?php
/**
 * 기본 월말 예상 투입비 결과 화면.
 * PHP 5.6 compatible.
 */

use App\Core\Auth;
use App\Core\Db;
use App\Services\AiMonthlyForecastService;

if (!Auth::check() || !(Auth::isDevelopmentDepartment() || Auth::canManageEmployees())) {
    http_response_code(403);
    echo '<div style="padding:16px;border:1px solid #fecaca;border-radius:14px;background:#fef2f2;color:#b91c1c;font-weight:800;">' . h('접근 권한이 없습니다.') . '</div>';
    return;
}

require_once __DIR__ . '/../../services/AiMonthlyForecastService.php';

if (!function_exists('cpms_ai_forecast_money')) {
    function cpms_ai_forecast_money($value)
    {
        return number_format((float)$value) . '원';
    }
}

if (!function_exists('cpms_ai_forecast_rate')) {
    function cpms_ai_forecast_rate($value)
    {
        if ($value === null || $value === '') return '-';
        return number_format((float)$value, 1) . '%';
    }
}

if (!function_exists('cpms_ai_forecast_basis_label')) {
    function cpms_ai_forecast_basis_label($value)
    {
        $labels = array(
            'HISTORICAL_RATIO'=>'과거 입력완료율',
            'RECENT_MEDIAN'=>'최근 완료월 중앙값',
            'LINEAR'=>'단순 기간 진행률',
            'INSUFFICIENT'=>'예측자료 부족',
            'MIXED'=>'비용별 혼합 방식'
        );
        return isset($labels[$value]) ? $labels[$value] : (string)$value;
    }
}

if (!function_exists('cpms_ai_forecast_status_label')) {
    function cpms_ai_forecast_status_label($value)
    {
        $labels = array('READY'=>'기본 분석 가능','LIMITED'=>'자료 제한적','INSUFFICIENT'=>'자료 부족');
        return isset($labels[$value]) ? $labels[$value] : (string)$value;
    }
}

if (!function_exists('cpms_ai_forecast_category_label')) {
    function cpms_ai_forecast_category_label($value)
    {
        $labels = array(
            'labor'=>'노무비','outsourcing'=>'외주비','purchase'=>'구매품','material'=>'자재비',
            'equipment'=>'장비비','other_expense'=>'기타경비','safety'=>'안전관리비',
            'health'=>'보건비','other'=>'기타 투입비'
        );
        return isset($labels[$value]) ? $labels[$value] : (string)$value;
    }
}

if (!function_exists('cpms_ai_forecast_category_rows')) {
    function cpms_ai_forecast_category_rows($json)
    {
        $decoded = AiMonthlyForecastService::decodeData($json);
        $allowedCategories = array('labor','outsourcing','purchase','material','equipment','other_expense','safety','health','other');
        $allowedFields = array('current','forecast','low','high','progress_rate','basis_type','history_month_count','data_status','guide');
        $rows = array();
        foreach ($allowedCategories as $category) {
            if (!isset($decoded[$category]) || !is_array($decoded[$category])) continue;
            $row = array();
            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $decoded[$category]) && (is_scalar($decoded[$category][$field]) || $decoded[$category][$field] === null)) {
                    $row[$field] = $decoded[$category][$field];
                }
            }
            $rows[$category] = $row;
        }
        return $rows;
    }
}

if (!function_exists('cpms_ai_forecast_warnings')) {
    function cpms_ai_forecast_warnings($json)
    {
        $decoded = AiMonthlyForecastService::decodeData($json);
        $warnings = array();
        foreach ($decoded as $value) {
            if (!is_string($value)) continue;
            $value = trim($value);
            if ($value !== '') $warnings[] = function_exists('mb_substr') ? mb_substr($value, 0, 300, 'UTF-8') : substr($value, 0, 300);
            if (count($warnings) >= 20) break;
        }
        return $warnings;
    }
}

$forecastHistoryPdo = Db::pdo();
$forecastHistoryInstalled = AiMonthlyForecastService::isInstalled($forecastHistoryPdo);
$latestContext = AiMonthlyForecastService::latestForecastContext($forecastHistoryPdo);
$filters = array(
    'forecast_date'=>isset($_GET['forecast_date']) ? trim((string)$_GET['forecast_date']) : '',
    'target_ym'=>isset($_GET['target_ym']) ? trim((string)$_GET['target_ym']) : '',
    'project_id'=>isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0,
    'project_status'=>isset($_GET['project_status']) ? trim((string)$_GET['project_status']) : '',
    'data_status'=>isset($_GET['data_status']) ? trim((string)$_GET['data_status']) : '',
    'basis_type'=>isset($_GET['basis_type']) ? trim((string)$_GET['basis_type']) : '',
    'q'=>isset($_GET['q']) ? trim((string)$_GET['q']) : ''
);
if ($filters['forecast_date'] === '' && isset($latestContext['forecast_date'])) $filters['forecast_date'] = $latestContext['forecast_date'];
if ($filters['target_ym'] === '' && isset($latestContext['target_ym'])) $filters['target_ym'] = $latestContext['target_ym'];
$forecastPage = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$forecastPerPage = 50;
$forecastTotal = $forecastHistoryInstalled ? AiMonthlyForecastService::countForecasts($forecastHistoryPdo, $filters) : 0;
$forecastPages = max(1, (int)ceil($forecastTotal / $forecastPerPage));
if ($forecastPage > $forecastPages) $forecastPage = $forecastPages;
$forecastRows = $forecastHistoryInstalled ? AiMonthlyForecastService::listForecasts($forecastHistoryPdo, $filters, $forecastPage, $forecastPerPage) : array();
$forecastSummary = $forecastHistoryInstalled ? AiMonthlyForecastService::historySummary($forecastHistoryPdo, $filters) : array();
$forecastOptions = $forecastHistoryInstalled ? AiMonthlyForecastService::historyOptions($forecastHistoryPdo) : array('projects'=>array(),'statuses'=>array(),'dates'=>array(),'months'=>array());
$pageParams = $_GET;
$pageParams['r'] = 'admin/ai_forecast_history';
?>

<style>
  .fh-wrap{color:#0f172a}.fh-wrap *{box-sizing:border-box}.fh-hero,.fh-card,.fh-filter,.fh-table-card{border:1px solid #e2e8f0;border-radius:18px;background:#fff;box-shadow:0 8px 26px rgba(15,23,42,.05)}
  .fh-hero{padding:24px;background:linear-gradient(135deg,#fff 0%,#eff6ff 100%)}.fh-hero h2{margin:0;font-size:27px;font-weight:900;letter-spacing:-.03em}.fh-hero p{margin:9px 0 0;color:#475569;line-height:1.7}.fh-links{display:flex;flex-wrap:wrap;gap:8px;margin-top:15px}.fh-link,.fh-btn{display:inline-flex;align-items:center;justify-content:center;min-height:39px;padding:8px 13px;border-radius:10px;background:#2563eb;color:#fff;text-decoration:none;font-weight:800;border:0;cursor:pointer}.fh-link.secondary,.fh-btn.secondary{border:1px solid #cbd5e1;background:#fff;color:#334155}
  .fh-note,.fh-warning{margin-top:14px;padding:14px 16px;border-radius:13px;font-size:13px;line-height:1.7}.fh-note{border:1px solid #bfdbfe;background:#eff6ff;color:#1e40af}.fh-warning{border:1px solid #fde68a;background:#fffbeb;color:#92400e}.fh-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:14px}.fh-card{padding:16px}.fh-label{font-size:11px;color:#64748b;font-weight:800}.fh-value{margin-top:7px;font-size:18px;font-weight:900;overflow-wrap:anywhere}
  .fh-filter{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:14px;padding:17px}.fh-field label{display:block;margin-bottom:6px;color:#475569;font-size:11px;font-weight:800}.fh-field input,.fh-field select{width:100%;height:40px;padding:8px 10px;border:1px solid #cbd5e1;border-radius:9px;background:#fff}.fh-actions{display:flex;align-items:flex-end;gap:7px}
  .fh-table-card{margin-top:14px;padding:0;overflow:hidden}.fh-scroll{overflow-x:auto}.fh-table{width:100%;min-width:1440px;border-collapse:collapse;font-size:12px}.fh-table th,.fh-table td{padding:11px 9px;border-bottom:1px solid #e2e8f0;text-align:left;vertical-align:top}.fh-table th{background:#f8fafc;color:#475569;font-weight:900;white-space:nowrap}.fh-table td.money{text-align:right;white-space:nowrap}.fh-badge{display:inline-flex;padding:4px 8px;border-radius:999px;background:#e2e8f0;color:#334155;font-weight:900;white-space:nowrap}.fh-badge.READY{background:#dcfce7;color:#166534}.fh-badge.LIMITED{background:#dbeafe;color:#1d4ed8}.fh-badge.INSUFFICIENT{background:#ffedd5;color:#c2410c}.fh-detail{min-width:1080px;margin-top:8px;padding:12px;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc}.fh-detail table{width:100%;border-collapse:collapse}.fh-detail th,.fh-detail td{padding:8px;border:1px solid #e2e8f0}.fh-detail ul{margin:10px 0 0;padding-left:20px}.fh-pager{display:flex;justify-content:center;gap:8px;padding:15px}.fh-pager a,.fh-pager span{padding:8px 12px;border:1px solid #cbd5e1;border-radius:9px;text-decoration:none;color:#334155;background:#fff}.fh-pager .current{background:#2563eb;color:#fff;border-color:#2563eb}
  @media(max-width:1000px){.fh-summary,.fh-filter{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:640px){.fh-hero{padding:18px}.fh-summary,.fh-filter{grid-template-columns:1fr}.fh-links,.fh-links .fh-link,.fh-actions,.fh-actions .fh-btn{width:100%}}
</style>

<div class="fh-wrap">
  <section class="fh-hero">
    <h2>기본 월말 예상 투입비</h2>
    <p>저장된 최신 일일 스냅샷과 동일 현장의 과거 완료월 자료를 이용한 PHP 통계 계산 결과입니다. 통합 비용 이벤트의 증감액은 실제 투입비 계산에 사용하지 않습니다.</p>
    <div class="fh-links"><a class="fh-link" href="?r=admin%2Fai_forecast_setup">기본 월말 예측 설정</a><a class="fh-link secondary" href="?r=admin%2Fai_reliability_history">입력 신뢰도 결과</a><a class="fh-link secondary" href="?r=admin%2Fai_snapshot_history">일일 스냅샷 이력</a><a class="fh-link secondary" href="?r=admin%2Fai_data_audit">AI 데이터 준비상태 점검</a></div>
  </section>

  <div class="fh-note"><strong>현재 결과는 기본 통계 예측이며 확정금액이 아닙니다.</strong><br>입력 신뢰도와 담당자별 입력 지연은 다음 단계에서 반영됩니다.</div>
  <?php if (!$forecastHistoryInstalled): ?><div class="fh-warning"><strong>기본 월말 예측 기능이 아직 설치되지 않았습니다.</strong><br><a href="?r=admin%2Fai_forecast_setup">기본 월말 예측 설정</a>에서 전용 테이블을 설치해주세요.</div><?php endif; ?>

  <section class="fh-summary">
    <?php
      $summaryCards = array(
        '예측 현장 수'=>number_format(isset($forecastSummary['project_count'])?(int)$forecastSummary['project_count']:0) . '개',
        '현재 투입비 합계'=>cpms_ai_forecast_money(isset($forecastSummary['current_total'])?$forecastSummary['current_total']:0),
        '월말 예상 합계'=>cpms_ai_forecast_money(isset($forecastSummary['forecast_total'])?$forecastSummary['forecast_total']:0),
        '예상 하한 합계'=>cpms_ai_forecast_money(isset($forecastSummary['low_total'])?$forecastSummary['low_total']:0),
        '예상 상한 합계'=>cpms_ai_forecast_money(isset($forecastSummary['high_total'])?$forecastSummary['high_total']:0),
        '앞으로 추가 예상'=>cpms_ai_forecast_money(isset($forecastSummary['remaining_total'])?$forecastSummary['remaining_total']:0),
        '자료 부족 현장'=>number_format(isset($forecastSummary['insufficient_count'])?(int)$forecastSummary['insufficient_count']:0) . '개',
        '마지막 계산시각'=>!empty($forecastSummary['last_calculated_at'])?$forecastSummary['last_calculated_at']:'-'
      );
      foreach ($summaryCards as $label=>$value):
    ?><div class="fh-card"><div class="fh-label"><?php echo h($label); ?></div><div class="fh-value"><?php echo h($value); ?></div></div><?php endforeach; ?>
  </section>

  <form class="fh-filter" method="get">
    <input type="hidden" name="r" value="admin/ai_forecast_history">
    <div class="fh-field"><label>예측일</label><select name="forecast_date"><option value="">전체</option><?php foreach ((array)$forecastOptions['dates'] as $value): ?><option value="<?php echo h($value); ?>"<?php echo $filters['forecast_date']===$value?' selected':''; ?>><?php echo h($value); ?></option><?php endforeach; ?></select></div>
    <div class="fh-field"><label>대상 월</label><select name="target_ym"><option value="">전체</option><?php foreach ((array)$forecastOptions['months'] as $value): ?><option value="<?php echo h($value); ?>"<?php echo $filters['target_ym']===$value?' selected':''; ?>><?php echo h($value); ?></option><?php endforeach; ?></select></div>
    <div class="fh-field"><label>프로젝트</label><select name="project_id"><option value="0">전체</option><?php foreach ((array)$forecastOptions['projects'] as $option): ?><option value="<?php echo h((int)$option['project_id']); ?>"<?php echo $filters['project_id']==(int)$option['project_id']?' selected':''; ?>><?php echo h($option['project_name']); ?></option><?php endforeach; ?></select></div>
    <div class="fh-field"><label>프로젝트 상태</label><select name="project_status"><option value="">전체</option><?php foreach ((array)$forecastOptions['statuses'] as $option): $value=isset($option['status'])?(string)$option['status']:''; ?><option value="<?php echo h($value); ?>"<?php echo $filters['project_status']===$value?' selected':''; ?>><?php echo h($value); ?></option><?php endforeach; ?></select></div>
    <div class="fh-field"><label>자료 상태</label><select name="data_status"><option value="">전체</option><?php foreach (array('READY','LIMITED','INSUFFICIENT') as $value): ?><option value="<?php echo h($value); ?>"<?php echo $filters['data_status']===$value?' selected':''; ?>><?php echo h(cpms_ai_forecast_status_label($value)); ?></option><?php endforeach; ?></select></div>
    <div class="fh-field"><label>대표 예측방식</label><select name="basis_type"><option value="">전체</option><?php foreach (array('HISTORICAL_RATIO','RECENT_MEDIAN','LINEAR','INSUFFICIENT','MIXED') as $value): ?><option value="<?php echo h($value); ?>"<?php echo $filters['basis_type']===$value?' selected':''; ?>><?php echo h(cpms_ai_forecast_basis_label($value)); ?></option><?php endforeach; ?></select></div>
    <div class="fh-field"><label>현장명 검색</label><input type="text" name="q" value="<?php echo h($filters['q']); ?>" maxlength="100" placeholder="현장명"></div>
    <div class="fh-actions"><button class="fh-btn" type="submit">조회</button><a class="fh-btn secondary" href="?r=admin%2Fai_forecast_history">초기화</a></div>
  </form>

  <section class="fh-table-card"><div class="fh-scroll"><table class="fh-table"><thead><tr><th>현장</th><th>상태</th><th>현재 투입</th><th>월말 예상</th><th>예상범위</th><th>추가 예상</th><th>대표 예측방식</th><th>과거월</th><th>자료 상태</th><th>스냅샷 날짜</th><th>상세</th></tr></thead><tbody>
  <?php if (count($forecastRows)===0): ?><tr><td colspan="11" style="padding:34px;text-align:center;color:#64748b;">조회된 예측 결과가 없습니다.</td></tr><?php else: foreach ($forecastRows as $row):
      $categoryRows=cpms_ai_forecast_category_rows(isset($row['category_forecast_data'])?$row['category_forecast_data']:'');
      $warnings=cpms_ai_forecast_warnings(isset($row['warning_data'])?$row['warning_data']:'');
      $previous=isset($row['previous_forecast_input_amount'])&&$row['previous_forecast_input_amount']!==null?(float)$row['previous_forecast_input_amount']:null;
  ?><tr>
    <td><strong><?php echo h($row['project_name_snapshot']); ?></strong><div style="color:#64748b;margin-top:3px;">#<?php echo h((int)$row['project_id']); ?> · <?php echo h($row['target_ym']); ?></div></td>
    <td><?php echo h($row['project_status_snapshot']); ?></td>
    <td class="money"><?php echo h(cpms_ai_forecast_money($row['current_input_amount'])); ?></td>
    <td class="money"><strong><?php echo h(cpms_ai_forecast_money($row['forecast_input_amount'])); ?></strong><?php if ($previous!==null): $difference=(float)$row['forecast_input_amount']-$previous; ?><div style="color:#64748b;margin-top:3px;">이전 대비 <?php echo h(($difference>0?'+':'') . number_format($difference) . '원'); ?></div><?php endif; ?></td>
    <td class="money"><?php echo h(cpms_ai_forecast_money($row['forecast_low_amount'])); ?><br>~ <?php echo h(cpms_ai_forecast_money($row['forecast_high_amount'])); ?></td>
    <td class="money"><?php echo h(cpms_ai_forecast_money($row['remaining_estimated_amount'])); ?></td>
    <td><?php echo h(cpms_ai_forecast_basis_label($row['basis_type'])); ?></td>
    <td><?php echo h(number_format((int)$row['history_month_count'])); ?>개월</td>
    <td><span class="fh-badge <?php echo h($row['data_status']); ?>"><?php echo h(cpms_ai_forecast_status_label($row['data_status'])); ?></span></td>
    <td><?php echo h($row['snapshot_date']); ?></td>
    <td><details><summary>보기</summary><div class="fh-detail">
      <table><thead><tr><th>비용 종류</th><th>현재 금액</th><th>예상 금액</th><th>예상범위</th><th>기간 진행률</th><th>예측방식</th><th>과거자료</th><th>안내</th></tr></thead><tbody>
      <?php foreach ($categoryRows as $category=>$item): ?><tr><td><?php echo h(cpms_ai_forecast_category_label($category)); ?></td><td class="money"><?php echo h(cpms_ai_forecast_money(isset($item['current'])?$item['current']:0)); ?></td><td class="money"><?php echo h(cpms_ai_forecast_money(isset($item['forecast'])?$item['forecast']:0)); ?></td><td class="money"><?php echo h(cpms_ai_forecast_money(isset($item['low'])?$item['low']:0)); ?> ~ <?php echo h(cpms_ai_forecast_money(isset($item['high'])?$item['high']:0)); ?></td><td><?php echo h(cpms_ai_forecast_rate(isset($item['progress_rate'])?(float)$item['progress_rate']:null)); ?></td><td><?php echo h(cpms_ai_forecast_basis_label(isset($item['basis_type'])?$item['basis_type']:'')); ?></td><td><?php echo h(number_format(isset($item['history_month_count'])?(int)$item['history_month_count']:0)); ?>개월</td><td><?php echo h(isset($item['guide'])?$item['guide']:'-'); ?></td></tr><?php endforeach; ?>
      </tbody></table>
      <?php if (count($warnings)>0): ?><ul><?php foreach ($warnings as $warning): ?><li><?php echo h($warning); ?></li><?php endforeach; ?></ul><?php endif; ?>
      <div style="margin-top:10px;color:#64748b;">예측일 <?php echo h($row['forecast_date']); ?> · 최초 생성 <?php echo h($row['first_created_at']); ?> · 최근 계산 <?php echo h($row['last_calculated_at']); ?> · 계산 <?php echo h(number_format((int)$row['calculation_count'])); ?>회</div>
    </div></details></td>
  </tr><?php endforeach; endif; ?>
  </tbody></table></div>
  <?php if ($forecastPages>1): ?><nav class="fh-pager"><?php if ($forecastPage>1): $pageParams['page']=$forecastPage-1; ?><a href="?<?php echo h(http_build_query($pageParams)); ?>">이전</a><?php endif; ?><span class="current"><?php echo h($forecastPage); ?> / <?php echo h($forecastPages); ?></span><?php if ($forecastPage<$forecastPages): $pageParams['page']=$forecastPage+1; ?><a href="?<?php echo h(http_build_query($pageParams)); ?>">다음</a><?php endif; ?></nav><?php endif; ?>
  </section>
</div>
