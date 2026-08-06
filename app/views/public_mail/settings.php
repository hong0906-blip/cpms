<?php
/**
 * 파일 경로: C:\www\cpms\app\views\public_mail\settings.php
 * 네이버 메일 관리자 설정 화면입니다.
 */
use App\Services\PublicMailWebHelper;
$esc=array('App\\Services\\PublicMailWebHelper','h');
$full=isset($syncState['full_import'])&&is_array($syncState['full_import'])?$syncState['full_import']:array();
$total=isset($full['total_count'])?(int)$full['total_count']:0;
$processed=isset($full['processed_count'])?(int)$full['processed_count']:0;
$remaining=isset($full['remaining_count'])?(int)$full['remaining_count']:0;
$percent=$total>0?(int)floor(($processed/$total)*100):0;
if ($percent>100) $percent=100;
$titleRefresh=isset($syncState['title_refresh'])&&is_array($syncState['title_refresh'])?$syncState['title_refresh']:array();
$titleTotal=isset($titleRefresh['total_count'])?(int)$titleRefresh['total_count']:0;
$titleProcessed=isset($titleRefresh['processed_count'])?(int)$titleRefresh['processed_count']:0;
$titleUpdated=isset($titleRefresh['updated_count'])?(int)$titleRefresh['updated_count']:0;
$titleApplied=isset($titleRefresh['applied_count'])?(int)$titleRefresh['applied_count']:$titleUpdated;
$recentRecovery=isset($syncState['recent_mail_recovery'])&&is_array($syncState['recent_mail_recovery'])?$syncState['recent_mail_recovery']:array();
$newFailures=isset($syncState['new_message_failures'])&&is_array($syncState['new_message_failures'])?$syncState['new_message_failures']:array();
$newFailureCount=count($newFailures);
$titleFailed=isset($titleRefresh['failed_count'])?(int)$titleRefresh['failed_count']:0;
$titleRemaining=isset($titleRefresh['remaining_count'])?(int)$titleRefresh['remaining_count']:0;
$titleRelated=isset($titleRefresh['related_count'])?(int)$titleRefresh['related_count']:0;
$titleBroken=isset($titleRefresh['broken_count'])?(int)$titleRefresh['broken_count']:$titleTotal;
$titleNormal=isset($titleRefresh['normal_count'])?(int)$titleRefresh['normal_count']:0;
$titleSkipped=isset($titleRefresh['skipped_count'])?(int)$titleRefresh['skipped_count']:0;
$titleLastReason=isset($titleRefresh['last_result_reason'])?(string)$titleRefresh['last_result_reason']:'';
$titleOldPreview=isset($titleRefresh['last_old_subject_preview'])?(string)$titleRefresh['last_old_subject_preview']:'';
$titleCandidatePreview=isset($titleRefresh['last_candidate_subject_preview'])?(string)$titleRefresh['last_candidate_subject_preview']:'';
$titlePercent=$titleTotal>0?(int)floor(($titleProcessed/$titleTotal)*100):0;
if($titlePercent>100)$titlePercent=100;
?>
<link rel="stylesheet" href="<?php echo call_user_func($esc,base_url()); ?>/assets/css/public_mail.css?v=20260806_7191">
<div class="flex-1 min-w-0 overflow-auto bg-slate-50 public-mail-page" data-public-mail-page data-public-mail-settings data-csrf-token="<?php echo call_user_func($esc,$csrfToken); ?>">
  <div class="public-mail-shell pm-settings-shell">
    <section class="public-mail-hero">
      <div><div class="public-mail-eyebrow">ADMIN SETTINGS</div><h1>네이버 메일 연동 설정</h1><p>직원 화면에서는 자동수집을 실행하지 않습니다. 외부 예약서비스가 백그라운드에서 동기화합니다.</p></div>
      <div class="public-mail-actions"><a class="pm-btn pm-btn-light" href="public_mail.php"><i data-lucide="arrow-left"></i> 네이버 메일로 돌아가기</a></div>
    </section>

    <?php if ($flash&&isset($flash['message'])): ?><div class="pm-alert <?php echo isset($flash['type'])&&$flash['type']==='error'?'pm-alert-error':'pm-alert-success'; ?>"><?php echo call_user_func($esc,$flash['message']); ?></div><?php endif; ?>
    <?php if ($errorMessage!==''): ?><div class="pm-alert pm-alert-error"><?php echo call_user_func($esc,$errorMessage); ?></div><?php endif; ?>

    <section class="pm-settings-grid">
      <div class="pm-settings-card">
        <div class="pm-card-title"><div><strong>네이버 연결정보</strong><span>애플리케이션 비밀번호는 한 번 저장하면 계속 사용합니다.</span></div><span class="pm-status-dot <?php echo !empty($settings['enabled'])?'is-on':''; ?>"><?php echo !empty($settings['enabled'])?'사용 중':'사용 안 함'; ?></span></div>
        <form method="post" action="public_mail_action.php" class="pm-settings-form">
          <input type="hidden" name="csrf_token" value="<?php echo call_user_func($esc,$csrfToken); ?>"><input type="hidden" name="action" value="save_settings">
          <label class="pm-toggle-row"><input type="checkbox" name="enabled" value="1" <?php echo !empty($settings['enabled'])?'checked':''; ?>><span><strong>네이버 메일 연동 사용</strong><small>끄면 자동동기화가 실행되지 않습니다.</small></span></label>
          <label>네이버 아이디<input type="text" name="username" value="<?php echo call_user_func($esc,isset($settings['username'])?$settings['username']:''); ?>" placeholder="예: cmbuild" required></label>
          <label>애플리케이션 비밀번호<input type="password" name="password" value="" placeholder="변경할 때만 새 비밀번호 입력" autocomplete="new-password"><small>저장된 비밀번호는 화면에 다시 표시하지 않습니다.</small></label>
          <label>한 번에 처리할 메일 수<select name="batch_size"><?php foreach(array(50,100,150,200) as $batch): ?><option value="<?php echo $batch; ?>" <?php echo isset($settings['batch_size'])&&(int)$settings['batch_size']===$batch?'selected':''; ?>><?php echo $batch; ?>건</option><?php endforeach; ?></select></label>
          <label class="pm-toggle-row"><input type="checkbox" name="use_gpt_classifier" value="1" <?php echo !empty($settings['use_gpt_classifier'])?'checked':''; ?>><span><strong>애매한 메일만 GPT 보조분류</strong><small>규칙으로 판단하기 어려운 메일만 사용합니다.</small></span></label>
          <label class="pm-toggle-row"><input type="checkbox" name="include_spam" value="1" <?php echo !empty($settings['include_spam'])?'checked':''; ?>><span><strong>스팸메일함 포함</strong><small>기본값은 제외입니다.</small></span></label>
          <label class="pm-toggle-row"><input type="checkbox" name="include_trash" value="1" <?php echo !empty($settings['include_trash'])?'checked':''; ?>><span><strong>휴지통 포함</strong><small>기본값은 제외입니다.</small></span></label>
          <div class="pm-connection-box"><div><span>IMAP 서버</span><strong>imap.naver.com</strong></div><div><span>포트</span><strong>993</strong></div><div><span>보안</span><strong>SSL</strong></div><div><span>원본 처리</span><strong>읽기 전용</strong></div></div>
          <div class="pm-settings-actions"><button type="button" class="pm-btn pm-btn-light" data-test-connection><i data-lucide="plug-zap"></i> 연결 확인</button><button type="submit" class="pm-btn pm-btn-primary"><i data-lucide="save"></i> 설정 저장</button></div>
        </form>
      </div>

      <div class="pm-settings-card pm-import-card" data-import-active="<?php echo !empty($full['active'])?'1':'0'; ?>" data-import-paused="<?php echo !empty($full['paused'])?'1':'0'; ?>">
        <div class="pm-card-title"><div><strong>전체 메일 가져오기</strong><span>버튼은 한 번만 누르면 됩니다. 받은메일함·네이버 보낸메일함·사용자 메일함을 자동으로 이어서 가져옵니다.</span></div></div>
        <div class="pm-progress-wrap"><div class="pm-progress-track"><span style="width:<?php echo $percent; ?>%"></span></div><div class="pm-progress-label"><strong><?php echo $percent; ?>%</strong><span><?php echo number_format($processed); ?> / <?php echo number_format($total); ?>건</span></div></div>
        <div class="pm-sync-state-list">
          <div><span>상태</span><strong data-import-status><?php echo !empty($full['active'])?(!empty($full['paused'])?'일시중지':'가져오는 중'):(!empty($full['cancelled'])?'취소됨':($total>0&&$remaining===0?'완료':'대기')); ?></strong></div>
          <div><span>남은 메일</span><strong data-import-remaining><?php echo number_format($remaining); ?>건</strong></div>
          <div><span>마지막 처리</span><strong><?php echo call_user_func($esc,isset($syncState['last_success_at'])&&$syncState['last_success_at']!==''?$syncState['last_success_at']:'아직 없음'); ?></strong></div>
          <div><span>최근 처리 건수</span><strong><?php echo isset($syncState['last_batch_count'])?(int)$syncState['last_batch_count']:0; ?>건</strong></div>
        </div>
        <?php if (!empty($full['last_message'])): ?><div class="pm-alert pm-alert-success" data-import-message><?php echo call_user_func($esc,$full['last_message']); ?></div><?php endif; ?>
        <?php if (!empty($syncState['last_error'])): ?><div class="pm-alert pm-alert-error">최근 오류: <?php echo call_user_func($esc,$syncState['last_error']); ?></div><?php endif; ?>
        <div class="pm-settings-actions pm-wrap-actions">
          <button type="button" class="pm-btn pm-btn-primary" data-full-import="start"><i data-lucide="download-cloud"></i> 전체 메일 가져오기</button>
          <button type="button" class="pm-btn pm-btn-light" data-full-import="pause"><i data-lucide="pause"></i> 일시중지</button>
          <button type="button" class="pm-btn pm-btn-light" data-full-import="resume"><i data-lucide="play"></i> 다시 시작</button>
          <button type="button" class="pm-btn pm-btn-danger" data-full-import="cancel"><i data-lucide="square"></i> 취소</button>
        </div>
        <p class="pm-help-text">버튼을 한 번 누르면 작업상태만 등록됩니다. 직원 브라우저는 반복 수집을 하지 않으며, 외부 예약서비스가 1분마다 다음 묶음을 처리합니다. 화면을 닫아도 진행됩니다. Gmail에서 보낸 메일은 네이버 보낸메일함이 아니라 Gmail 보낸편지함에 남습니다.</p>
        <div class="pm-alert pm-alert-success">
          <strong>최근 48시간 누락 메일 재확인</strong><br>
          <?php echo call_user_func($esc,!empty($recentRecovery['last_message'])?$recentRecovery['last_message']:'패치 설치 후 자동으로 실행됩니다.'); ?>
        </div>
        <div class="pm-sync-state-list">
          <div><span>재확인 상태</span><strong><?php echo !empty($recentRecovery['active'])?'확인 중':(!empty($recentRecovery['finished_at'])?'완료':'대기'); ?></strong></div>
          <div><span>재확인 추가 메일</span><strong><?php echo number_format(isset($recentRecovery['added_count'])?(int)$recentRecovery['added_count']:0); ?>건</strong></div>
          <div><span>격리·재시도 메일</span><strong><?php echo number_format($newFailureCount); ?>건</strong></div>
          <div><span>마지막 재확인</span><strong><?php echo call_user_func($esc,!empty($recentRecovery['last_run_at'])?$recentRecovery['last_run_at']:'아직 없음'); ?></strong></div>
        </div>
      </div>


      <div class="pm-settings-card">
        <div class="pm-card-title"><div><strong>설치 버전·목록 속도 상태</strong><span>실제 적용된 핵심 파일과 메일 색인 상태를 확인합니다.</span></div><span class="pm-status-dot <?php echo !empty($indexStatus['writable'])?'is-on':''; ?>"><?php echo !empty($indexStatus['writable'])?'정상':'확인 필요'; ?></span></div>
        <div class="pm-sync-state-list">
          <div><span>설치 패키지</span><strong><?php echo call_user_func($esc,isset($packageVersion)?$packageVersion:'1.7.19.1'); ?></strong></div>
          <div><span>목록 색인 버전</span><strong><?php echo isset($indexStatus['version'])?(int)$indexStatus['version']:0; ?></strong></div>
          <div><span>색인 메일 수</span><strong><?php echo number_format(isset($indexStatus['item_count'])?(int)$indexStatus['item_count']:0); ?>건</strong></div>
          <div><span>마지막 색인 갱신</span><strong><?php echo call_user_func($esc,!empty($indexStatus['updated_at'])?$indexStatus['updated_at']:'아직 없음'); ?></strong></div>
        </div>
        <p class="pm-help-text">메뉴 진입 시 전체 messages.json과 workflow.json을 다시 계산하지 않고, 미리 만들어 둔 mail_index.json만 읽습니다.</p>
      </div>

      <div class="pm-settings-card">
        <div class="pm-card-title"><div><strong>메일 본문 속도 상태</strong><span>기존 캐시는 삭제하지 않고 서버 안에서 즉시 현재 형식으로 변환합니다.</span></div><span class="pm-status-dot <?php echo !empty($cacheStats['storage_writable'])?'is-on':''; ?>"><?php echo !empty($cacheStats['storage_writable'])?'정상':'쓰기 확인 필요'; ?></span></div>
        <div class="pm-sync-state-list">
          <div><span>전체 저장 메일</span><strong><?php echo number_format(isset($cacheStats['total_messages'])?(int)$cacheStats['total_messages']:0); ?>건</strong></div>
          <div><span>본문 준비 완료</span><strong><?php echo number_format(isset($cacheStats['cached_messages'])?(int)$cacheStats['cached_messages']:0); ?>건</strong></div>
          <div><span>본문 미준비</span><strong><?php echo number_format(isset($cacheStats['missing_messages'])?(int)$cacheStats['missing_messages']:0); ?>건</strong></div>
          <div><span>구버전 캐시</span><strong><?php echo number_format(isset($cacheStats['legacy_messages'])?(int)$cacheStats['legacy_messages']:0); ?>건</strong></div>
        </div>
        <p class="pm-help-text">구버전 캐시가 있어도 네이버에서 본문을 다시 받지 않습니다. 메일을 열 때 저장된 HTML만 빠르게 변환하며, 제목 재수집도 본문 캐시를 삭제하지 않습니다.</p>
      </div>

      <div class="pm-settings-card pm-title-normalization-card"
           id="title-refresh"
           data-title-refresh-card
           data-title-refresh-active="<?php echo !empty($titleRefresh['active'])?'1':'0'; ?>"
           data-title-refresh-paused="<?php echo !empty($titleRefresh['paused'])?'1':'0'; ?>"
           data-title-refresh-status="<?php echo call_user_func($esc,isset($titleRefresh['status'])?$titleRefresh['status']:'ready'); ?>">
        <?php
          $titleStatus='대기';
          if (!empty($titleRefresh['active'])) {
              $titleStatus=!empty($titleRefresh['paused'])?'일시중지':((isset($titleRefresh['phase'])&&$titleRefresh['phase']==='merging')?'제목 적용 중':'수집 중');
          } elseif (isset($titleRefresh['status'])&&$titleRefresh['status']==='completed') {
              $titleStatus='완료';
          } elseif (isset($titleRefresh['status'])&&$titleRefresh['status']==='cancelled') {
              $titleStatus='취소됨';
          }
        ?>
        <div class="pm-card-title">
          <div>
            <strong>비즈니스온·스마트빌 깨진 제목 복구</strong>
            <span>mailing@businesson.co.kr에서 온 메일 중 실제로 깨진 제목만 골라 원본 제목을 확인합니다.</span>
          </div>
          <span class="pm-status-dot <?php echo !empty($titleRefresh['active'])&&empty($titleRefresh['paused'])?'is-on':''; ?>" data-title-refresh-status-label><?php echo $titleStatus; ?></span>
        </div>

        <div class="pm-alert pm-alert-success">
          <strong>전체 5,559건을 다시 확인하지 않습니다.</strong><br>
          mailing@businesson.co.kr 관련 메일만 확인하고 정상 제목은 그대로 유지합니다. 일반 메일 목록과 상세화면에서는 복구 작업을 실행하지 않습니다.
        </div>

        <div class="pm-progress-label">
          <strong data-title-refresh-percent><?php echo $titlePercent; ?>%</strong>
          <span><span data-title-refresh-processed><?php echo number_format($titleProcessed); ?></span> / <span data-title-refresh-total><?php echo number_format($titleTotal); ?></span>건</span>
        </div>
        <div class="pm-progress-track"><span data-title-refresh-progress style="width:<?php echo $titlePercent; ?>%"></span></div>

        <div class="pm-sync-state-list">
          <div><span>비즈니스온 관련 메일</span><strong><span data-title-refresh-related><?php echo number_format($titleRelated); ?></span>건</strong></div>
          <div><span>깨진 제목 발견</span><strong><span data-title-refresh-broken><?php echo number_format($titleBroken); ?></span>건</strong></div>
          <div><span>정상 제목 유지</span><strong><span data-title-refresh-normal><?php echo number_format($titleNormal); ?></span>건</strong></div>
          <div><span>대상 확인</span><strong><span data-title-refresh-processed><?php echo number_format($titleProcessed); ?></span>건</strong></div>
          <div><span>수집된 정상 제목</span><strong><span data-title-refresh-updated><?php echo number_format($titleUpdated); ?></span>건</strong></div>
          <div><span>실제 화면 적용</span><strong><span data-title-refresh-applied><?php echo number_format($titleApplied); ?></span>건</strong></div>
          <div><span>건너뜀·실패</span><strong><span data-title-refresh-skipped><?php echo number_format($titleSkipped+$titleFailed); ?></span>건</strong></div>
          <div><span>남은 대상</span><strong><span data-title-refresh-remaining><?php echo number_format($titleRemaining); ?></span>건</strong></div>
          <div><span>최근 작업</span><strong data-title-refresh-last-run><?php echo call_user_func($esc,!empty($titleRefresh['last_run_at'])?$titleRefresh['last_run_at']:'아직 없음'); ?></strong></div>
        </div>

        <div class="pm-alert pm-alert-warning" data-title-refresh-notice <?php echo empty($titleRefresh['last_message'])?'hidden':''; ?>>
          <span data-title-refresh-message><?php echo call_user_func($esc,isset($titleRefresh['last_message'])?$titleRefresh['last_message']:''); ?></span>
        </div>
        <div class="pm-alert pm-alert-error" data-title-refresh-error <?php echo empty($titleRefresh['last_error'])?'hidden':''; ?>>
          <strong>최근 확인 내용</strong><br><span data-title-refresh-error-text style="white-space:pre-line"><?php echo call_user_func($esc,isset($titleRefresh['last_error'])?$titleRefresh['last_error']:''); ?></span>
        </div>
        <div class="pm-alert pm-alert-warning" data-title-refresh-result-box <?php echo $titleLastReason===''?'hidden':''; ?>>
          <strong>마지막 판정: <span data-title-refresh-result-reason><?php echo call_user_func($esc,$titleLastReason); ?></span></strong><br>
          <span>기존 제목: </span><span data-title-refresh-old-preview><?php echo call_user_func($esc,$titleOldPreview!==''?$titleOldPreview:'(없음)'); ?></span><br>
          <span>원본 후보: </span><span data-title-refresh-candidate-preview><?php echo call_user_func($esc,$titleCandidatePreview!==''?$titleCandidatePreview:'(없음)'); ?></span>
        </div>

        <div class="pm-settings-actions pm-wrap-actions">
          <form method="post" action="public_mail_action.php" onsubmit="return confirm('mailing@businesson.co.kr에서 온 메일 중 깨진 제목만 복구할까요? 정상 제목은 그대로 유지합니다.');">
            <input type="hidden" name="csrf_token" value="<?php echo call_user_func($esc,$csrfToken); ?>">
            <input type="hidden" name="action" value="start_smartbill_title_refresh">
            <button type="submit" class="pm-btn pm-btn-primary"><i data-lucide="refresh-cw"></i> 비즈니스온 깨진 제목 복구</button>
          </form>
          <button type="button" class="pm-btn pm-btn-light" data-title-refresh-run-once><i data-lucide="chevrons-right"></i> 지금 1건 처리</button>
          <form method="post" action="public_mail_action.php">
            <input type="hidden" name="csrf_token" value="<?php echo call_user_func($esc,$csrfToken); ?>">
            <input type="hidden" name="action" value="pause_original_title_refresh">
            <button type="submit" class="pm-btn pm-btn-light"><i data-lucide="pause"></i> 일시중지</button>
          </form>
          <form method="post" action="public_mail_action.php">
            <input type="hidden" name="csrf_token" value="<?php echo call_user_func($esc,$csrfToken); ?>">
            <input type="hidden" name="action" value="resume_original_title_refresh">
            <button type="submit" class="pm-btn pm-btn-light"><i data-lucide="play"></i> 다시 시작</button>
          </form>
          <form method="post" action="public_mail_action.php" onsubmit="return confirm('비즈니스온 깨진 제목 복구를 취소할까요? 지금까지 복구된 제목은 메일 목록에 적용합니다.');">
            <input type="hidden" name="csrf_token" value="<?php echo call_user_func($esc,$csrfToken); ?>">
            <input type="hidden" name="action" value="cancel_original_title_refresh">
            <button type="submit" class="pm-btn pm-btn-danger"><i data-lucide="square"></i> 취소</button>
          </form>
        </div>

        <p class="pm-help-text">
          깨진 것으로 선별된 비즈니스온 메일 제목만 한 번에 1건 요청합니다. Subject 전용 조회가 실패하면 본문 없이 16KB 머리글 조회로 자동 전환합니다. 네이버에 접속하기 전에 다음 위치를 먼저 저장하므로, 특정 메일에서 서버 응답이 끊겨도 그 1건만 자동으로 건너뛰고 계속 진행합니다.
        </p>
      </div>

      <div class="pm-settings-card">
        <div class="pm-card-title"><div><strong>24시간 외부 자동동기화</strong><span>직원 브라우저와 서버업체 설정 없이 cron-job.org가 자동으로 호출합니다.</span></div><span class="pm-status-dot <?php echo !empty($syncState['last_cron_at'])?'is-on':''; ?>" data-cron-status><?php echo isset($syncState['last_cron_status'])&&$syncState['last_cron_status']==='success'?'정상':(isset($syncState['last_cron_status'])&&$syncState['last_cron_status']==='error'?'오류':(!empty($syncState['last_cron_at'])?'실행 기록 있음':'등록 대기')); ?></span></div>
        <div class="pm-alert pm-alert-success"><strong>직원 화면 자동수집: 사용 안 함</strong><br>이제 CPMS를 사용하는 동안 1분마다 로딩이 발생하지 않습니다.</div>
        <label>cron-job.org에 등록할 주소<div class="pm-copy-field"><input type="text" readonly value="<?php echo call_user_func($esc,isset($cronInfo['url'])?$cronInfo['url']:''); ?>" data-cron-url><button type="button" class="pm-btn pm-btn-light" data-copy-cron-url>주소 복사</button></div></label>
        <label>요청 헤더 이름<div class="pm-copy-field"><input type="text" readonly value="<?php echo call_user_func($esc,isset($cronInfo['header_name'])?$cronInfo['header_name']:'X-CPMS-Mail-Key'); ?>" data-cron-header><button type="button" class="pm-btn pm-btn-light" data-copy-cron-header>이름 복사</button></div></label>
        <label>요청 헤더 비밀키<div class="pm-copy-field"><input type="text" readonly value="<?php echo call_user_func($esc,isset($cronInfo['header_value'])?$cronInfo['header_value']:''); ?>" data-cron-key><button type="button" class="pm-btn pm-btn-light" data-copy-cron-key>비밀키 복사</button></div><small>비밀키는 네이버 비밀번호가 아닙니다. 공개 게시판이나 문서에 올리지 마세요.</small></label>
        <div class="pm-sync-state-list">
          <div><span>권장 호출간격</span><strong>1분</strong></div>
          <div><span>마지막 자동호출</span><strong data-cron-last-at><?php echo call_user_func($esc,!empty($syncState['last_cron_at'])?$syncState['last_cron_at']:'아직 없음'); ?></strong></div>
          <div><span>최근 결과</span><strong data-cron-last-result><?php echo call_user_func($esc,!empty($syncState['last_cron_result'])?$syncState['last_cron_result']:'아직 없음'); ?></strong></div>
          <div><span>브라우저 주기실행</span><strong>완전 중지</strong></div>
        </div>
        <div class="pm-help-text">
          <strong>cron-job.org 등록방법</strong><br>
          1. cron-job.org 회원가입 후 CREATE CRONJOB을 누릅니다.<br>
          2. 위 주소를 URL에 붙여넣고 실행주기를 Every minute으로 선택합니다.<br>
          3. Advanced에서 Request headers를 열고, 위 헤더 이름과 비밀키를 각각 입력합니다.<br>
          4. 저장 후 TEST RUN을 누르고 이 화면의 [상태만 새로고침]으로 정상 여부를 확인합니다.
        </div>
        <div class="pm-settings-actions pm-wrap-actions">
          <button type="button" class="pm-btn pm-btn-light" data-refresh-sync-status><i data-lucide="activity"></i> 상태만 새로고침</button>
          <button type="button" class="pm-btn pm-btn-primary" data-run-automation><i data-lucide="refresh-cw"></i> 지금 한 번 실행</button>
          <form method="post" action="public_mail_action.php" onsubmit="return confirm('비밀키를 새로 만들면 기존 키는 작동하지 않습니다. cron-job.org 설정도 새 키로 바꿔야 합니다. 계속할까요?');"><input type="hidden" name="csrf_token" value="<?php echo call_user_func($esc,$csrfToken); ?>"><input type="hidden" name="action" value="regenerate_cron_token"><button type="submit" class="pm-btn pm-btn-light"><i data-lucide="key-round"></i> 비밀키 새로 만들기</button></form>
        </div>
      </div>

      <div class="pm-settings-card">
        <div class="pm-card-title"><div><strong>첨부파일 저장정책</strong><span>메일 첨부파일을 CPMS 서버 디스크에 보관하지 않습니다.</span></div><span class="pm-status-dot is-on">서버 무저장</span></div>
        <div class="pm-sync-state-list"><div><span>내 PC 다운로드</span><strong>네이버 → 브라우저 직접 전송</strong></div><div><span>Google Drive 저장</span><strong>네이버 → Drive 분할 전송</strong></div><div><span>CPMS 서버 저장</span><strong>사용 안 함</strong></div><div><span>기존 임시 캐시</span><strong>업데이트 시 자동 정리</strong></div></div>
        <p class="pm-help-text">서버에는 메일 제목·발신자·분류정보와 Google Drive 파일 ID만 남습니다. 첨부파일 원본은 저장하지 않습니다.</p>
      </div>

      <div class="pm-settings-card pm-danger-zone">
        <div class="pm-card-title"><div><strong>CPMS 메일정보 초기화</strong><span>네이버 원본메일은 삭제되지 않습니다.</span></div></div>
        <form method="post" action="public_mail_action.php" onsubmit="return confirm('CPMS에 저장된 분류와 처리정보를 초기화하시겠습니까?');"><input type="hidden" name="csrf_token" value="<?php echo call_user_func($esc,$csrfToken); ?>"><input type="hidden" name="action" value="reset_mail_data"><label>확인 문구<input type="text" name="confirmation" placeholder="초기화 입력"></label><button type="submit" class="pm-btn pm-btn-danger"><i data-lucide="trash-2"></i> CPMS 메일정보 초기화</button></form>
      </div>
    </section>
  </div>
</div>
<script src="<?php echo call_user_func($esc,base_url()); ?>/assets/js/public_mail.js?v=20260806_7191"></script>
