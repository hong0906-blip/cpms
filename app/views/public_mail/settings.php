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
$repair=isset($syncState['metadata_repair'])&&is_array($syncState['metadata_repair'])?$syncState['metadata_repair']:array();
$repairTotal=isset($repair['total_count'])?(int)$repair['total_count']:0;
$repairTargets=isset($repair['target_count'])?(int)$repair['target_count']:0;
$repairProcessed=isset($repair['processed_count'])?(int)$repair['processed_count']:0;
$repairRepaired=isset($repair['repaired_count'])?(int)$repair['repaired_count']:0;
$repairFailed=isset($repair['failed_count'])?(int)$repair['failed_count']:0;
$repairRemaining=isset($repair['remaining_count'])?(int)$repair['remaining_count']:0;
$repairPercent=$repairTotal>0?(int)floor(($repairProcessed/$repairTotal)*100):0;
if ($repairPercent>100) $repairPercent=100;
$repairLastPing=isset($repair['last_http_ping_at'])?trim((string)$repair['last_http_ping_at']):'';
$repairLastPingTs=$repairLastPing!==''?strtotime($repairLastPing):false;
$repairCronStale=!empty($repair['active'])&&($repairLastPingTs===false||(time()-$repairLastPingTs)>180);
$repairLockActive=!empty($repair['lock_is_active']);
?>
<link rel="stylesheet" href="<?php echo call_user_func($esc,base_url()); ?>/assets/css/public_mail.css?v=20260806_76">
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
      </div>


      <div class="pm-settings-card">
        <div class="pm-card-title"><div><strong>설치 버전·목록 속도 상태</strong><span>실제 적용된 핵심 파일과 메일 색인 상태를 확인합니다.</span></div><span class="pm-status-dot <?php echo !empty($indexStatus['writable'])?'is-on':''; ?>"><?php echo !empty($indexStatus['writable'])?'정상':'확인 필요'; ?></span></div>
        <div class="pm-sync-state-list">
          <div><span>설치 패키지</span><strong><?php echo call_user_func($esc,isset($packageVersion)?$packageVersion:'1.7.6'); ?></strong></div>
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
        <p class="pm-help-text">구버전 캐시가 있어도 네이버에서 본문을 다시 받지 않습니다. 메일을 열 때 저장된 HTML만 빠르게 변환하며, 제목 복구 작업도 본문 캐시를 삭제하지 않습니다.</p>
      </div>

      <div class="pm-settings-card pm-repair-card" data-repair-active="<?php echo !empty($repair['active'])?'1':'0'; ?>" data-repair-paused="<?php echo !empty($repair['paused'])?'1':'0'; ?>">
        <div class="pm-card-title"><div><strong>깨진 제목·한글 전체 자동복구</strong><span>버튼은 한 번만 누르면 됩니다. 외부 자동동기화가 남은 메일을 끝까지 나눠서 처리합니다.</span></div><span class="pm-status-dot <?php echo !empty($repair['active'])?'is-on':''; ?>" data-repair-status-dot><?php echo !empty($repair['active'])?(!empty($repair['paused'])?'일시중지':'진행 중'):(!empty($repair['cancelled'])?'취소됨':($repairTotal>0&&$repairRemaining===0?'완료':'대기')); ?></span></div>
        <div class="pm-alert pm-alert-success"><strong>20건씩 계속 누를 필요가 없습니다.</strong><br>시작 버튼을 한 번 누르면 cron-job.org가 1분마다 다음 묶음을 자동 처리합니다. 브라우저를 닫아도 계속 진행됩니다.</div>
        <div class="pm-progress-wrap"><div class="pm-progress-track"><span data-repair-progress-bar style="width:<?php echo $repairPercent; ?>%"></span></div><div class="pm-progress-label"><strong data-repair-progress-percent><?php echo $repairPercent; ?>%</strong><span data-repair-progress-label><?php echo number_format($repairProcessed); ?> / <?php echo number_format($repairTotal); ?>건 확인</span></div></div>
        <div class="pm-sync-state-list">
          <div><span>상태</span><strong data-repair-status><?php echo !empty($repair['active'])?(!empty($repair['paused'])?'일시중지':'복구 중'):(!empty($repair['cancelled'])?'취소됨':($repairTotal>0&&$repairRemaining===0?'완료':'대기')); ?></strong></div>
          <div><span>처음 발견된 복구 대상</span><strong data-repair-targets><?php echo number_format($repairTargets); ?>건</strong></div>
          <div><span>복구 완료</span><strong data-repair-repaired><?php echo number_format($repairRepaired); ?>건</strong></div>
          <div><span>남은 확인</span><strong data-repair-remaining><?php echo number_format($repairRemaining); ?>건</strong></div>
          <div><span>확인 실패</span><strong data-repair-failed><?php echo number_format($repairFailed); ?>건</strong></div>
          <div><span>최근 실행</span><strong data-repair-last-run><?php echo call_user_func($esc,!empty($repair['last_run_at'])?$repair['last_run_at']:'아직 없음'); ?></strong></div>
          <div><span>최근 실행 처리</span><strong data-repair-last-processed><?php echo number_format(isset($repair['last_run_processed_count'])?(int)$repair['last_run_processed_count']:0); ?>건</strong></div>
          <div><span>외부 자동호출 최근 수신</span><strong data-repair-last-ping><?php echo call_user_func($esc,$repairLastPing!==''?$repairLastPing:'아직 없음'); ?></strong></div>
          <div><span>외부 자동호출 결과</span><strong data-repair-http-status><?php echo call_user_func($esc,!empty($repair['last_http_status'])?$repair['last_http_status']:'아직 없음'); ?></strong></div>
          <div><span>복구 잠금</span><strong data-repair-lock-status><?php echo $repairLockActive?'실행 중':'해제'; ?></strong></div>
        </div>
        <div class="pm-alert pm-alert-error" data-repair-cron-warning<?php echo $repairCronStale?'':' hidden'; ?>>자동복구가 등록되었지만 최근 3분 동안 외부 자동호출이 확인되지 않았습니다. cron-job.org의 URL과 X-CPMS-Mail-Key 헤더를 확인하거나 아래의 지금 1회 실행을 눌러 점검하세요.</div>
        <?php if (!empty($repair['last_message'])): ?><div class="pm-alert pm-alert-success" data-repair-message><?php echo call_user_func($esc,$repair['last_message']); ?></div><?php else: ?><div class="pm-help-text" data-repair-message>아직 복구 작업을 시작하지 않았습니다.</div><?php endif; ?>
        <?php if (!empty($repair['last_error'])): ?><div class="pm-alert pm-alert-error" data-repair-error><?php echo call_user_func($esc,$repair['last_error']); ?></div><?php endif; ?>
        <div class="pm-settings-actions pm-wrap-actions">
          <button type="button" class="pm-btn pm-btn-primary" data-metadata-repair="start"><i data-lucide="languages"></i> 깨진 메일 전체 복구 시작</button>
          <button type="button" class="pm-btn pm-btn-light" data-metadata-repair="run_once"><i data-lucide="play-circle"></i> 지금 1회 실행</button>
          <button type="button" class="pm-btn pm-btn-light" data-metadata-repair="pause"><i data-lucide="pause"></i> 일시중지</button>
          <button type="button" class="pm-btn pm-btn-light" data-metadata-repair="resume"><i data-lucide="play"></i> 다시 시작</button>
          <button type="button" class="pm-btn pm-btn-danger" data-metadata-repair="cancel"><i data-lucide="square"></i> 취소</button>
        </div>
        <p class="pm-help-text">메일 본문 전체나 첨부파일을 다시 받지 않고, 제목·보낸사람·받는사람 헤더만 네이버 원본에서 읽습니다. 일반 메일 메뉴와 직원 화면에서는 복구 작업을 실행하지 않습니다.</p>
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
<script src="<?php echo call_user_func($esc,base_url()); ?>/assets/js/public_mail.js?v=20260806_76"></script>
