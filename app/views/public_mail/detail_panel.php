<?php
/**
 * 파일 경로: C:\www\cpms\app\views\public_mail\detail_panel.php
 * 메일 상세 패널 전체를 비동기로 출력합니다. PHP 5.6 호환 코드입니다.
 */
if (!isset($esc) || !is_callable($esc)) {
    $esc = function ($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };
}
$detailClass = isset($detail['classification']) && is_array($detail['classification']) ? $detail['classification'] : array();
$detailWorkflow = isset($detail['workflow']) && is_array($detail['workflow']) ? $detail['workflow'] : array();
$selectedDepartment = !empty($detailWorkflow['department']) ? $detailWorkflow['department'] : (isset($detailClass['department']) ? $detailClass['department'] : '미분류');
$selectedProjectId = !empty($detailWorkflow['project_id']) ? (string)$detailWorkflow['project_id'] : (isset($detailClass['project_id']) ? (string)$detailClass['project_id'] : '');
$selectedAssigneeId = isset($detailWorkflow['assignee_id']) ? (string)$detailWorkflow['assignee_id'] : '';
$selectedPriority = !empty($detailWorkflow['priority']) ? $detailWorkflow['priority'] : (isset($detailClass['priority']) ? $detailClass['priority'] : '보통');
?>
<div class="pm-detail-panel">
    <div class="pm-detail-toolbar">
        <a class="pm-icon-btn pm-reader-back-button" data-mail-detail-close href="public_mail.php" title="메일 목록으로 돌아가기" aria-label="메일 목록으로 돌아가기"><i data-lucide="arrow-left"></i><span>뒤로</span></a>
        <div class="pm-detail-actions">
            <a class="pm-btn pm-btn-gmail" href="<?php echo call_user_func($esc, $replyMailUrl); ?>" target="_blank" rel="noopener noreferrer"><i data-lucide="reply"></i> Gmail로 답장쓰기</a>
            <?php if (!empty($employees)): ?>
                <button type="button" class="pm-btn pm-btn-primary" data-task-modal-open><i data-lucide="list-plus"></i> 업무요청 만들기</button>
            <?php endif; ?>
        </div>
    </div>

    <article class="pm-message-card">
        <div class="pm-message-labels">
            <span class="pm-badge pm-badge-neutral"><?php echo call_user_func($esc, isset($detail['mailbox_name']) ? $detail['mailbox_name'] : '받은메일함'); ?></span>
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

        <div class="pm-detail-content" data-mail-detail-content data-message-key="<?php echo call_user_func($esc, $detail['message_key']); ?>" data-cache-ready="<?php echo !empty($detail['body_cache_ready']) ? '1' : '0'; ?>">
            <?php if (!empty($detail['body_cache_ready'])): ?>
                <?php
                $baseUrl = function_exists('base_url') ? rtrim((string)base_url(), '/') : '';
                include __DIR__ . '/detail_fragment.php';
                ?>
            <?php else: ?>
                <div class="pm-detail-local-loading">
                    <div class="pm-spinner"></div>
                    <strong>메일 본문을 준비하고 있습니다.</strong>
                    <span>이 영역만 불러오며 사이드바와 다른 메뉴는 계속 사용할 수 있습니다.</span>
                </div>
            <?php endif; ?>
        </div>
    </article>

    <form method="post" action="public_mail_action.php" class="pm-workflow-card" data-workflow-form>
        <input type="hidden" name="csrf_token" value="<?php echo call_user_func($esc, $csrfToken); ?>">
        <input type="hidden" name="action" value="update_workflow">
        <input type="hidden" name="message_key" value="<?php echo call_user_func($esc, $detail['message_key']); ?>">
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
        <input type="hidden" name="message_key" value="<?php echo call_user_func($esc, $detail['message_key']); ?>">
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
            . '네이버 메일함: ' . (isset($detail['mailbox_name']) ? $detail['mailbox_name'] : '') . "\n"
            . '메일 식별값: ' . $detail['message_key'] . "\n\n"
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
