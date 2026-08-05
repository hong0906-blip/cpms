<?php
/**
 * 파일 경로: C:\www\cpms\app\views\public_mail\index.php
 * 공용메일 목록 및 상세 화면입니다.
 */

use App\Services\PublicMailWebHelper;

$esc = array('App\\Services\\PublicMailWebHelper', 'h');
$items = isset($list['items']) && is_array($list['items']) ? $list['items'] : array();
$page = isset($list['page']) ? (int)$list['page'] : 1;
$pageCount = isset($list['page_count']) ? (int)$list['page_count'] : 1;
$total = isset($list['total']) ? (int)$list['total'] : 0;

$departmentOptions = array('공무', '공사', '안전/보건', '품질', '관리', '일반', '미분류');
$statusOptions = array('미확인', '확인', '담당자 지정', '처리중', '회신대기', '발송완료', '처리완료', '보류');
$priorityOptions = array('긴급', '높음', '보통', '낮음');

$buildUrl = function ($changes) use ($filters, $page) {
    $query = array_merge($filters, array('page' => $page));
    foreach ($changes as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }
    return 'public_mail.php?' . http_build_query($query, '', '&');
};
?>
<link rel="stylesheet" href="<?php echo call_user_func($esc, base_url()); ?>/assets/css/public_mail.css?v=20260805_3">

<div class="flex-1 min-w-0 overflow-auto bg-slate-50 public-mail-page" data-public-mail-page data-csrf-token="<?php echo call_user_func($esc, $csrfToken); ?>">
    <div class="public-mail-shell">
        <section class="public-mail-hero">
            <div>
                <div class="public-mail-eyebrow">COMPANY MAIL HUB</div>
                <h1>네이버 메일</h1>
                <p>네이버 원본메일을 보존하면서 현장·부서·담당자 기준으로 분류하고 처리합니다.</p>
            </div>
            <div class="public-mail-actions">
                <?php if (!empty($isMailAdmin)): ?>
                    <a class="pm-btn pm-btn-light" href="public_mail_settings.php">
                        <i data-lucide="settings"></i> 연동 설정
                    </a>
                <?php endif; ?>
                <a class="pm-btn pm-btn-gmail" href="<?php echo call_user_func($esc, $newMailUrl); ?>" target="_blank" rel="noopener noreferrer">
                    <i data-lucide="send"></i> Gmail로 새 메일
                </a>
                <button type="button" class="pm-btn pm-btn-primary" data-sync-mail="new">
                    <i data-lucide="refresh-cw"></i> 새 메일 확인
                </button>
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

        <?php if (empty($settings['enabled'])): ?>
            <div class="pm-alert pm-alert-warning">
                네이버 메일 연동이 아직 켜져 있지 않습니다.
                <?php if (!empty($isMailAdmin)): ?>
                    <a href="public_mail_settings.php">관리자 설정에서 네이버 계정을 연결하세요.</a>
                <?php else: ?>
                    최고관리자에게 네이버 메일 연결을 요청하세요.
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <section class="pm-stat-grid">
            <a class="pm-stat-card" href="<?php echo call_user_func($esc, $buildUrl(array('quick' => ''))); ?>">
                <span>전체메일</span><strong><?php echo (int)$counts['all']; ?></strong><small>CPMS에 분류된 메일</small>
            </a>
            <a class="pm-stat-card" href="<?php echo call_user_func($esc, $buildUrl(array('quick' => 'unread'))); ?>">
                <span>네이버 미열람</span><strong><?php echo (int)$counts['unread']; ?></strong><small>동기화 시점 기준</small>
            </a>
            <a class="pm-stat-card pm-stat-danger" href="<?php echo call_user_func($esc, $buildUrl(array('quick' => 'urgent'))); ?>">
                <span>긴급</span><strong><?php echo (int)$counts['urgent']; ?></strong><small>즉시 확인 필요</small>
            </a>
            <a class="pm-stat-card pm-stat-warning" href="<?php echo call_user_func($esc, $buildUrl(array('quick' => 'unclassified'))); ?>">
                <span>미분류</span><strong><?php echo (int)$counts['unclassified']; ?></strong><small>사람 확인 필요</small>
            </a>
            <a class="pm-stat-card" href="<?php echo call_user_func($esc, $buildUrl(array('quick' => 'unassigned'))); ?>">
                <span>담당자 없음</span><strong><?php echo (int)$counts['unassigned']; ?></strong><small>업무 배정 필요</small>
            </a>
            <a class="pm-stat-card" href="<?php echo call_user_func($esc, $buildUrl(array('quick' => 'unfinished'))); ?>">
                <span>미처리</span><strong><?php echo (int)$counts['unfinished']; ?></strong><small>완료 전 메일</small>
            </a>
        </section>

        <section class="pm-filter-card">
            <form method="get" action="public_mail.php" class="pm-filter-form">
                <div class="pm-search-field">
                    <i data-lucide="search"></i>
                    <input type="text" name="query" value="<?php echo call_user_func($esc, $filters['query']); ?>" placeholder="메일 제목 또는 발신자 검색">
                </div>
                <select name="period" aria-label="조회 기간">
                    <option value="1m" <?php echo $filters['period'] === '1m' ? 'selected' : ''; ?>>최근 1개월</option>
                    <option value="3m" <?php echo $filters['period'] === '3m' ? 'selected' : ''; ?>>최근 3개월</option>
                    <option value="6m" <?php echo $filters['period'] === '6m' ? 'selected' : ''; ?>>최근 6개월</option>
                    <option value="1y" <?php echo $filters['period'] === '1y' ? 'selected' : ''; ?>>최근 1년</option>
                    <option value="all" <?php echo $filters['period'] === 'all' ? 'selected' : ''; ?>>전체 수집기간</option>
                </select>
                <select name="department">
                    <option value="">전체 부서</option>
                    <?php foreach ($departmentOptions as $option): ?>
                        <option value="<?php echo call_user_func($esc, $option); ?>" <?php echo $filters['department'] === $option ? 'selected' : ''; ?>><?php echo call_user_func($esc, $option); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="project_id">
                    <option value="">전체 현장</option>
                    <?php foreach ($projects as $project): ?>
                        <option value="<?php echo call_user_func($esc, $project['id']); ?>" <?php echo $filters['project_id'] === (string)$project['id'] ? 'selected' : ''; ?>><?php echo call_user_func($esc, $project['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="status">
                    <option value="">전체 상태</option>
                    <?php foreach ($statusOptions as $option): ?>
                        <option value="<?php echo call_user_func($esc, $option); ?>" <?php echo $filters['status'] === $option ? 'selected' : ''; ?>><?php echo call_user_func($esc, $option); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="priority">
                    <option value="">전체 중요도</option>
                    <?php foreach ($priorityOptions as $option): ?>
                        <option value="<?php echo call_user_func($esc, $option); ?>" <?php echo $filters['priority'] === $option ? 'selected' : ''; ?>><?php echo call_user_func($esc, $option); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="assignee_id">
                    <option value="">전체 담당자</option>
                    <?php foreach ($employees as $employee): ?>
                        <option value="<?php echo call_user_func($esc, $employee['id']); ?>" <?php echo $filters['assignee_id'] === (string)$employee['id'] ? 'selected' : ''; ?>><?php echo call_user_func($esc, $employee['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="pm-btn pm-btn-primary" type="submit">검색</button>
                <a class="pm-btn pm-btn-light" href="public_mail.php">초기화</a>
            </form>
            <div class="pm-sync-summary">
                <span>마지막 성공: <?php echo call_user_func($esc, isset($syncState['last_success_at']) && $syncState['last_success_at'] !== '' ? $syncState['last_success_at'] : '아직 없음'); ?></span>
                <span>남은 초기수집: <?php echo isset($syncState['remaining_count']) ? (int)$syncState['remaining_count'] : 0; ?>건</span>
                <span>목록 결과: <?php echo $total; ?>건</span>
            </div>
        </section>

        <section class="pm-workspace <?php echo $detail ? 'has-detail' : ''; ?>">
            <div class="pm-list-panel">
                <div class="pm-list-head">
                    <div>메일 목록</div>
                    <small>원본 삭제·이동 없음</small>
                </div>

                <?php if (empty($items)): ?>
                    <div class="pm-empty">
                        <i data-lucide="inbox"></i>
                        <strong>표시할 메일이 없습니다.</strong>
                        <span>처음이라면 설정에서 최근 1년 메일 가져오기를 실행하세요.</span>
                    </div>
                <?php else: ?>
                    <div class="pm-mail-list">
                        <?php foreach ($items as $message): ?>
                            <?php
                            $classification = isset($message['classification']) && is_array($message['classification']) ? $message['classification'] : array();
                            $workflow = isset($message['workflow']) && is_array($message['workflow']) ? $message['workflow'] : array();
                            $department = !empty($workflow['department']) ? $workflow['department'] : (isset($classification['department']) ? $classification['department'] : '미분류');
                            $priority = !empty($workflow['priority']) ? $workflow['priority'] : (isset($classification['priority']) ? $classification['priority'] : '보통');
                            $projectName = !empty($workflow['project_name']) ? $workflow['project_name'] : (isset($classification['project_name']) ? $classification['project_name'] : '');
                            $rowUrl = $buildUrl(array('uid' => $message['uid']));
                            ?>
                            <a class="pm-mail-row <?php echo $selectedUid === (int)$message['uid'] ? 'is-selected' : ''; ?> <?php echo empty($message['is_seen']) ? 'is-unread' : ''; ?>" href="<?php echo call_user_func($esc, $rowUrl); ?>">
                                <div class="pm-mail-row-top">
                                    <span class="pm-badge pm-badge-<?php echo $priority === '긴급' ? 'danger' : ($priority === '높음' ? 'warning' : 'neutral'); ?>"><?php echo call_user_func($esc, $priority); ?></span>
                                    <span class="pm-department"><?php echo call_user_func($esc, $department); ?></span>
                                    <time><?php echo call_user_func($esc, substr($message['date_text'], 5, 11)); ?></time>
                                </div>
                                <div class="pm-subject"><?php echo call_user_func($esc, $message['subject']); ?></div>
                                <div class="pm-mail-meta">
                                    <span><?php echo call_user_func($esc, $message['from_text']); ?></span>
                                    <?php if ($projectName !== ''): ?><span class="pm-project-chip"><?php echo call_user_func($esc, $projectName); ?></span><?php endif; ?>
                                </div>
                                <div class="pm-preview">
                                    <?php echo call_user_func($esc, !empty($message['preview']) ? $message['preview'] : '본문 미리보기를 준비 중입니다.'); ?>
                                </div>
                                <div class="pm-mail-status">
                                    <span><?php echo call_user_func($esc, isset($workflow['status']) ? $workflow['status'] : '미확인'); ?></span>
                                    <span><?php echo call_user_func($esc, !empty($workflow['assignee_name']) ? $workflow['assignee_name'] : '담당자 없음'); ?></span>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($pageCount > 1): ?>
                    <div class="pm-pagination">
                        <a class="<?php echo $page <= 1 ? 'is-disabled' : ''; ?>" href="<?php echo call_user_func($esc, $buildUrl(array('page' => max(1, $page - 1)))); ?>">이전</a>
                        <span><?php echo $page; ?> / <?php echo $pageCount; ?></span>
                        <a class="<?php echo $page >= $pageCount ? 'is-disabled' : ''; ?>" href="<?php echo call_user_func($esc, $buildUrl(array('page' => min($pageCount, $page + 1)))); ?>">다음</a>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($detail): ?>
                <?php
                $detailClass = isset($detail['classification']) && is_array($detail['classification']) ? $detail['classification'] : array();
                $detailWorkflow = isset($detail['workflow']) && is_array($detail['workflow']) ? $detail['workflow'] : array();
                $selectedDepartment = !empty($detailWorkflow['department']) ? $detailWorkflow['department'] : (isset($detailClass['department']) ? $detailClass['department'] : '미분류');
                $selectedProjectId = !empty($detailWorkflow['project_id']) ? (string)$detailWorkflow['project_id'] : (isset($detailClass['project_id']) ? (string)$detailClass['project_id'] : '');
                $selectedAssigneeId = isset($detailWorkflow['assignee_id']) ? (string)$detailWorkflow['assignee_id'] : '';
                $selectedPriority = !empty($detailWorkflow['priority']) ? $detailWorkflow['priority'] : (isset($detailClass['priority']) ? $detailClass['priority'] : '보통');
                ?>
                <div class="pm-detail-panel">
                    <div class="pm-detail-toolbar">
                        <a class="pm-icon-btn" href="<?php echo call_user_func($esc, $buildUrl(array('uid' => null))); ?>" title="상세 닫기"><i data-lucide="x"></i></a>
                        <div class="pm-detail-actions">
                            <a class="pm-btn pm-btn-gmail" href="<?php echo call_user_func($esc, $replyMailUrl); ?>" target="_blank" rel="noopener noreferrer"><i data-lucide="reply"></i> Gmail로 답장쓰기</a>
                            <?php if (!empty($employees)): ?>
                                <button type="button" class="pm-btn pm-btn-primary" data-task-modal-open><i data-lucide="list-plus"></i> 업무요청 만들기</button>
                            <?php endif; ?>
                            <form method="post" action="public_mail_action.php" class="pm-inline-form">
                                <input type="hidden" name="csrf_token" value="<?php echo call_user_func($esc, $csrfToken); ?>">
                                <input type="hidden" name="action" value="reply_completed">
                                <input type="hidden" name="uid" value="<?php echo (int)$detail['uid']; ?>">
                                <button class="pm-btn pm-btn-light" type="submit"><i data-lucide="check"></i> 발송완료 처리</button>
                            </form>
                        </div>
                    </div>

                    <article class="pm-message-card">
                        <div class="pm-message-labels">
                            <span class="pm-badge pm-badge-neutral"><?php echo call_user_func($esc, $selectedDepartment); ?></span>
                            <span class="pm-badge pm-badge-<?php echo $selectedPriority === '긴급' ? 'danger' : ($selectedPriority === '높음' ? 'warning' : 'neutral'); ?>"><?php echo call_user_func($esc, $selectedPriority); ?></span>
                            <?php if (!empty($detailWorkflow['reply_completed'])): ?><span class="pm-badge pm-badge-success">발송완료</span><?php endif; ?>
                        </div>
                        <h2><?php echo call_user_func($esc, $detail['subject']); ?></h2>
                        <?php if (!empty($detailClass['summary']) || !empty($detailClass['required_action'])): ?>
                            <div class="pm-ai-summary">
                                <div><strong>AI 요약</strong><span><?php echo call_user_func($esc, isset($detailClass['method']) && $detailClass['method'] === 'rules+gpt' ? 'GPT 보조분류' : '규칙분류'); ?></span></div>
                                <?php if (!empty($detailClass['summary'])): ?><p><?php echo call_user_func($esc, $detailClass['summary']); ?></p><?php endif; ?>
                                <?php if (!empty($detailClass['required_action'])): ?><p><b>필요 조치:</b> <?php echo call_user_func($esc, $detailClass['required_action']); ?></p><?php endif; ?>
                                <?php if (!empty($detailClass['due_hint'])): ?><p><b>기한 단서:</b> <?php echo call_user_func($esc, $detailClass['due_hint']); ?></p><?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <dl class="pm-message-meta">
                            <div><dt>보낸 사람</dt><dd><?php echo call_user_func($esc, $detail['from_text']); ?></dd></div>
                            <div><dt>받는 사람</dt><dd><?php echo call_user_func($esc, $detail['to_text']); ?></dd></div>
                            <div><dt>수신일</dt><dd><?php echo call_user_func($esc, $detail['date_text']); ?></dd></div>
                        </dl>

                        <?php if (!empty($detail['attachments'])): ?>
                            <div class="pm-attachments">
                                <strong>첨부파일</strong>
                                <?php foreach ($detail['attachments'] as $attachment): ?>
                                    <a href="<?php echo call_user_func($esc, base_url()); ?>/public_mail_attachment.php?uid=<?php echo (int)$detail['uid']; ?>&part=<?php echo rawurlencode($attachment['part_id']); ?>">
                                        <i data-lucide="paperclip"></i>
                                        <span><?php echo call_user_func($esc, $attachment['filename']); ?></span>
                                        <small><?php echo number_format((int)$attachment['size']); ?> bytes</small>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <div class="pm-message-body"><?php echo $detail['body_html']; ?></div>
                    </article>

                    <form method="post" action="public_mail_action.php" class="pm-workflow-card" data-workflow-form>
                        <input type="hidden" name="csrf_token" value="<?php echo call_user_func($esc, $csrfToken); ?>">
                        <input type="hidden" name="action" value="update_workflow">
                        <input type="hidden" name="uid" value="<?php echo (int)$detail['uid']; ?>">
                        <input type="hidden" name="project_name" value="<?php echo call_user_func($esc, isset($detailWorkflow['project_name']) ? $detailWorkflow['project_name'] : ''); ?>" data-project-name>
                        <input type="hidden" name="assignee_name" value="<?php echo call_user_func($esc, isset($detailWorkflow['assignee_name']) ? $detailWorkflow['assignee_name'] : ''); ?>" data-assignee-name>

                        <div class="pm-card-title"><div><strong>업무 처리정보</strong><span>자동분류가 틀리면 직접 수정하세요.</span></div></div>
                        <div class="pm-form-grid">
                            <label>담당 부서
                                <select name="department">
                                    <?php foreach ($departmentOptions as $option): ?>
                                        <option value="<?php echo call_user_func($esc, $option); ?>" <?php echo $selectedDepartment === $option ? 'selected' : ''; ?>><?php echo call_user_func($esc, $option); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>관련 현장
                                <select name="project_id" data-project-select>
                                    <option value="">현장 미지정</option>
                                    <?php foreach ($projects as $project): ?>
                                        <option value="<?php echo call_user_func($esc, $project['id']); ?>" data-name="<?php echo call_user_func($esc, $project['name']); ?>" <?php echo $selectedProjectId === (string)$project['id'] ? 'selected' : ''; ?>><?php echo call_user_func($esc, $project['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>담당자
                                <select name="assignee_id" data-assignee-select>
                                    <option value="">담당자 미지정</option>
                                    <?php foreach ($employees as $employee): ?>
                                        <option value="<?php echo call_user_func($esc, $employee['id']); ?>" data-name="<?php echo call_user_func($esc, $employee['name']); ?>" <?php echo $selectedAssigneeId === (string)$employee['id'] ? 'selected' : ''; ?>><?php echo call_user_func($esc, $employee['name']); ?><?php echo $employee['department'] !== '' ? ' · ' . call_user_func($esc, $employee['department']) : ''; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>처리상태
                                <select name="status">
                                    <?php foreach ($statusOptions as $option): ?>
                                        <option value="<?php echo call_user_func($esc, $option); ?>" <?php echo isset($detailWorkflow['status']) && $detailWorkflow['status'] === $option ? 'selected' : ''; ?>><?php echo call_user_func($esc, $option); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>중요도
                                <select name="priority">
                                    <?php foreach ($priorityOptions as $option): ?>
                                        <option value="<?php echo call_user_func($esc, $option); ?>" <?php echo $selectedPriority === $option ? 'selected' : ''; ?>><?php echo call_user_func($esc, $option); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="pm-checkbox-label"><input type="checkbox" name="important" value="1" <?php echo !empty($detailWorkflow['important']) || !empty($detailClass['important']) ? 'checked' : ''; ?>> 중요메일로 표시</label>
                            <label class="pm-full-field">처리 메모
                                <textarea name="memo" rows="3" placeholder="처리내용, 회신기한, 확인사항 등을 기록하세요."><?php echo call_user_func($esc, isset($detailWorkflow['memo']) ? $detailWorkflow['memo'] : ''); ?></textarea>
                            </label>
                        </div>
                        <div class="pm-workflow-actions">
                            <button class="pm-btn pm-btn-primary" type="submit"><i data-lucide="save"></i> 처리정보 저장</button>
                        </div>
                    </form>

                    <form method="post" action="public_mail_action.php" class="pm-reclassify-form">
                        <input type="hidden" name="csrf_token" value="<?php echo call_user_func($esc, $csrfToken); ?>">
                        <input type="hidden" name="action" value="reclassify">
                        <input type="hidden" name="uid" value="<?php echo (int)$detail['uid']; ?>">
                        <button type="submit">자동분류 다시 실행<?php echo !empty($settings['use_gpt_classifier']) ? ' (애매하면 GPT)' : ''; ?></button>
                        <span>현재 규칙 신뢰도: <?php echo isset($detailClass['confidence']) ? (int)$detailClass['confidence'] : 0; ?>%</span>
                    </form>

                    <?php if (!empty($employees)): ?>
                        <?php
                        $taskPriorityMap = array('긴급' => 'urgent', '높음' => 'high', '보통' => 'normal', '낮음' => 'low');
                        $taskPriority = isset($taskPriorityMap[$selectedPriority]) ? $taskPriorityMap[$selectedPriority] : 'normal';
                        $taskTitle = '[네이버 메일] ' . $detail['subject'];
                        $taskContent = "네이버 메일에서 생성된 업무요청입니다.\n\n"
                            . '보낸 사람: ' . $detail['from_text'] . "\n"
                            . '수신일: ' . $detail['date_text'] . "\n"
                            . '메일 제목: ' . $detail['subject'] . "\n"
                            . '네이버 메일 UID: ' . (int)$detail['uid'] . "\n\n"
                            . "처리할 내용을 아래에 작성하세요.\n";
                        ?>
                        <div class="pm-modal" data-task-modal hidden>
                            <div class="pm-modal-backdrop" data-task-modal-close></div>
                            <section class="pm-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="pmTaskModalTitle">
                                <div class="pm-modal-head">
                                    <div><strong id="pmTaskModalTitle">업무요청 만들기</strong><span>메일 내용을 CPMS 나의 할 일로 연결합니다.</span></div>
                                    <button type="button" class="pm-icon-btn" data-task-modal-close aria-label="닫기"><i data-lucide="x"></i></button>
                                </div>
                                <form method="post" action="index.php?r=tasks/create" class="pm-task-form">
                                    <input type="hidden" name="_csrf" value="<?php echo call_user_func($esc, $taskCsrfToken); ?>">
                                    <input type="hidden" name="request_token" value="<?php echo call_user_func($esc, $taskRequestToken); ?>">
                                    <input type="hidden" name="task_kind" value="task">
                                    <label class="pm-full-field">업무 제목
                                        <input type="text" name="title" value="<?php echo call_user_func($esc, $taskTitle); ?>" maxlength="200" required>
                                    </label>
                                    <label class="pm-full-field">업무 내용
                                        <textarea name="content" rows="7"><?php echo call_user_func($esc, $taskContent); ?></textarea>
                                    </label>
                                    <div class="pm-form-grid">
                                        <label>담당자
                                            <select name="assignee_employee_ids[]" required>
                                                <option value="">담당자 선택</option>
                                                <?php foreach ($employees as $employee): ?>
                                                    <option value="<?php echo call_user_func($esc, $employee['id']); ?>" <?php echo $selectedAssigneeId === (string)$employee['id'] ? 'selected' : ''; ?>><?php echo call_user_func($esc, $employee['name']); ?><?php echo $employee['department'] !== '' ? ' · ' . call_user_func($esc, $employee['department']) : ''; ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label>담당 부서
                                            <select name="department">
                                                <?php foreach ($departmentOptions as $option): ?>
                                                    <option value="<?php echo call_user_func($esc, $option); ?>" <?php echo $selectedDepartment === $option ? 'selected' : ''; ?>><?php echo call_user_func($esc, $option); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label>관련 현장
                                            <select name="project_id">
                                                <option value="0">현장 미지정</option>
                                                <?php foreach ($projects as $project): ?>
                                                    <option value="<?php echo call_user_func($esc, $project['id']); ?>" <?php echo $selectedProjectId === (string)$project['id'] ? 'selected' : ''; ?>><?php echo call_user_func($esc, $project['name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label>중요도
                                            <select name="priority">
                                                <option value="urgent" <?php echo $taskPriority === 'urgent' ? 'selected' : ''; ?>>긴급</option>
                                                <option value="high" <?php echo $taskPriority === 'high' ? 'selected' : ''; ?>>높음</option>
                                                <option value="normal" <?php echo $taskPriority === 'normal' ? 'selected' : ''; ?>>보통</option>
                                                <option value="low" <?php echo $taskPriority === 'low' ? 'selected' : ''; ?>>낮음</option>
                                            </select>
                                        </label>
                                        <label>마감일
                                            <input type="date" name="due_date" value="<?php echo date('Y-m-d', strtotime('+3 days')); ?>">
                                        </label>
                                        <label>마감시간
                                            <input type="time" name="due_time" value="18:00">
                                        </label>
                                    </div>
                                    <div class="pm-modal-actions">
                                        <button type="button" class="pm-btn pm-btn-light" data-task-modal-close>취소</button>
                                        <button type="submit" class="pm-btn pm-btn-primary"><i data-lucide="list-plus"></i> 업무요청 등록</button>
                                    </div>
                                </form>
                            </section>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="pm-detail-placeholder">
                    <i data-lucide="mail-open"></i>
                    <strong>메일을 선택하세요.</strong>
                    <span>본문, 첨부파일, 자동분류와 담당자 정보를 한 화면에서 확인할 수 있습니다.</span>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>

<script src="<?php echo call_user_func($esc, base_url()); ?>/assets/js/public_mail.js?v=20260805_3"></script>
