<?php
/**
 * 파일 경로: C:\www\cpms\app\views\public_mail\settings.php
 * 공용메일 관리자 설정 화면입니다.
 */

use App\Services\PublicMailWebHelper;
$esc = array('App\\Services\\PublicMailWebHelper', 'h');
?>
<link rel="stylesheet" href="<?php echo call_user_func($esc, base_url()); ?>/assets/css/public_mail.css?v=20260805">

<main class="flex-1 min-w-0 overflow-auto bg-slate-50 public-mail-page" data-public-mail-page data-csrf-token="<?php echo call_user_func($esc, $csrfToken); ?>">
    <div class="public-mail-shell pm-settings-shell">
        <section class="public-mail-hero">
            <div>
                <div class="public-mail-eyebrow">ADMIN SETTINGS</div>
                <h1>네이버 메일 연동 설정</h1>
                <p>네이버 애플리케이션 비밀번호는 최초 한 번 저장하면 계속 사용합니다.</p>
            </div>
            <div class="public-mail-actions">
                <a class="pm-btn pm-btn-light" href="public_mail.php"><i data-lucide="arrow-left"></i> 네이버 메일로 돌아가기</a>
            </div>
        </section>

        <?php if ($flash && isset($flash['message'])): ?>
            <div class="pm-alert <?php echo isset($flash['type']) && $flash['type'] === 'error' ? 'pm-alert-error' : 'pm-alert-success'; ?>">
                <?php echo call_user_func($esc, $flash['message']); ?>
            </div>
        <?php endif; ?>

        <?php if ($errorMessage !== ''): ?>
            <div class="pm-alert pm-alert-error"><?php echo call_user_func($esc, $errorMessage); ?></div>
        <?php endif; ?>

        <section class="pm-settings-grid">
            <div class="pm-settings-card">
                <div class="pm-card-title">
                    <div><strong>네이버 연결정보</strong><span>일반 비밀번호가 아닌 애플리케이션 비밀번호를 사용합니다.</span></div>
                    <span class="pm-status-dot <?php echo !empty($settings['enabled']) ? 'is-on' : ''; ?>"><?php echo !empty($settings['enabled']) ? '사용 중' : '사용 안 함'; ?></span>
                </div>

                <form method="post" action="public_mail_action.php" class="pm-settings-form">
                    <input type="hidden" name="csrf_token" value="<?php echo call_user_func($esc, $csrfToken); ?>">
                    <input type="hidden" name="action" value="save_settings">

                    <label class="pm-toggle-row">
                        <input type="checkbox" name="enabled" value="1" <?php echo !empty($settings['enabled']) ? 'checked' : ''; ?>>
                        <span><strong>네이버 메일 연동 사용</strong><small>끄면 자동 동기화 요청이 실행되지 않습니다.</small></span>
                    </label>

                    <label>네이버 아이디
                        <input type="text" name="username" value="<?php echo call_user_func($esc, isset($settings['username']) ? $settings['username'] : ''); ?>" placeholder="예: cmbuild" required>
                    </label>

                    <label>애플리케이션 비밀번호
                        <input type="password" name="password" value="" placeholder="변경할 때만 새 비밀번호 입력" autocomplete="new-password">
                        <small>저장된 비밀번호는 화면에 다시 표시하지 않습니다.</small>
                    </label>

                    <div class="pm-form-grid">
                        <label>초기 가져오기 범위
                            <select name="initial_years">
                                <?php for ($year = 1; $year <= 5; $year++): ?>
                                    <option value="<?php echo $year; ?>" <?php echo isset($settings['initial_years']) && (int)$settings['initial_years'] === $year ? 'selected' : ''; ?>>최근 <?php echo $year; ?>년</option>
                                <?php endfor; ?>
                            </select>
                        </label>
                        <label>한 번에 처리할 수
                            <select name="batch_size">
                                <?php foreach (array(20, 30, 50, 100) as $batch): ?>
                                    <option value="<?php echo $batch; ?>" <?php echo isset($settings['batch_size']) && (int)$settings['batch_size'] === $batch ? 'selected' : ''; ?>><?php echo $batch; ?>건</option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>

                    <label class="pm-toggle-row">
                        <input type="checkbox" name="use_gpt_classifier" value="1" <?php echo !empty($settings['use_gpt_classifier']) ? 'checked' : ''; ?>>
                        <span><strong>애매한 메일만 GPT 보조분류</strong><small>규칙으로 구분하기 어려운 메일만 사용하며, 한 묶음당 최대 3건으로 제한합니다.</small></span>
                    </label>

                    <div class="pm-connection-box">
                        <div><span>IMAP 서버</span><strong>imap.naver.com</strong></div>
                        <div><span>포트</span><strong>993</strong></div>
                        <div><span>보안</span><strong>SSL</strong></div>
                        <div><span>원본 처리</span><strong>읽기 전용</strong></div>
                    </div>

                    <div class="pm-settings-actions">
                        <button type="button" class="pm-btn pm-btn-light" data-test-connection><i data-lucide="plug-zap"></i> 연결 확인</button>
                        <button type="submit" class="pm-btn pm-btn-primary"><i data-lucide="save"></i> 설정 저장</button>
                    </div>
                </form>
            </div>

            <div class="pm-settings-card">
                <div class="pm-card-title">
                    <div><strong>최근 <?php echo isset($settings['initial_years']) ? (int)$settings['initial_years'] : 1; ?>년 메일 가져오기</strong><span>본문과 첨부파일은 서버에 전부 복사하지 않습니다.</span></div>
                </div>

                <div class="pm-sync-state-list">
                    <div><span>마지막 성공</span><strong><?php echo call_user_func($esc, isset($syncState['last_success_at']) && $syncState['last_success_at'] !== '' ? $syncState['last_success_at'] : '아직 없음'); ?></strong></div>
                    <div><span>마지막 처리 건수</span><strong><?php echo isset($syncState['last_batch_count']) ? (int)$syncState['last_batch_count'] : 0; ?>건</strong></div>
                    <div><span>마지막 GPT 보조분류</span><strong><?php echo isset($syncState['last_gpt_count']) ? (int)$syncState['last_gpt_count'] : 0; ?>건</strong></div>
                    <div><span>검색된 기간 내 메일</span><strong><?php echo isset($syncState['last_search_count']) ? (int)$syncState['last_search_count'] : 0; ?>건</strong></div>
                    <div><span>남은 메일</span><strong><?php echo isset($syncState['remaining_count']) ? (int)$syncState['remaining_count'] : 0; ?>건</strong></div>
                    <div><span>네이버 받은메일함</span><strong><?php echo isset($syncState['mailbox_total']) ? (int)$syncState['mailbox_total'] : 0; ?>건</strong></div>
                </div>

                <?php if (!empty($syncState['last_error'])): ?>
                    <div class="pm-alert pm-alert-error">최근 오류: <?php echo call_user_func($esc, $syncState['last_error']); ?></div>
                <?php endif; ?>

                <button type="button" class="pm-btn pm-btn-primary pm-full-button" data-sync-mail="initial"><i data-lucide="download-cloud"></i> 다음 묶음 가져오기</button>
                <p class="pm-help-text">버튼을 누를 때마다 설정한 수만큼 가져오며, 중간에 멈춰도 다음 실행에서 이어집니다.</p>
            </div>

            <div class="pm-settings-card pm-danger-zone">
                <div class="pm-card-title">
                    <div><strong>CPMS 메일정보 초기화</strong><span>네이버 원본메일은 삭제되지 않습니다.</span></div>
                </div>
                <form method="post" action="public_mail_action.php" onsubmit="return confirm('CPMS에 저장된 분류와 처리정보를 초기화하시겠습니까?');">
                    <input type="hidden" name="csrf_token" value="<?php echo call_user_func($esc, $csrfToken); ?>">
                    <input type="hidden" name="action" value="reset_mail_data">
                    <label>확인 문구
                        <input type="text" name="confirmation" placeholder="초기화 입력">
                    </label>
                    <button type="submit" class="pm-btn pm-btn-danger"><i data-lucide="trash-2"></i> CPMS 메일정보 초기화</button>
                </form>
            </div>
        </section>
    </div>
</main>

<script src="<?php echo call_user_func($esc, base_url()); ?>/assets/js/public_mail.js?v=20260805"></script>
