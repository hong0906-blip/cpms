/*
 * 파일 경로: C:\www\cpms\public\assets\js\public_mail.js
 * 공용메일 화면 동작
 */
(function () {
    'use strict';

    function findPage() {
        return document.querySelector('[data-public-mail-page]');
    }

    function csrfToken() {
        var page = findPage();
        return page ? (page.getAttribute('data-csrf-token') || '') : '';
    }

    function showLoading(message) {
        var old = document.querySelector('.pm-loading-overlay');
        if (old) {
            old.parentNode.removeChild(old);
        }

        var overlay = document.createElement('div');
        overlay.className = 'pm-loading-overlay';
        overlay.innerHTML = '<div class="pm-loading-box"><div class="pm-spinner"></div><strong>'
            + escapeHtml(message || '처리 중입니다.')
            + '</strong><p>창을 닫지 말고 잠시 기다려 주세요.</p></div>';
        document.body.appendChild(overlay);
        return overlay;
    }

    function hideLoading() {
        var overlay = document.querySelector('.pm-loading-overlay');
        if (overlay && overlay.parentNode) {
            overlay.parentNode.removeChild(overlay);
        }
    }

    function escapeHtml(value) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(String(value || '')));
        return div.innerHTML;
    }

    function encodeForm(data) {
        var parts = [];
        var key;
        for (key in data) {
            if (Object.prototype.hasOwnProperty.call(data, key)) {
                parts.push(encodeURIComponent(key) + '=' + encodeURIComponent(data[key]));
            }
        }
        return parts.join('&');
    }

    function postJson(data, callback) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'public_mail_action.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) {
                return;
            }

            var result = null;
            try {
                result = JSON.parse(xhr.responseText);
            } catch (e) {
                result = { ok: false, message: '서버 응답을 확인할 수 없습니다.' };
            }

            callback(result, xhr.status);
        };
        xhr.send(encodeForm(data));
    }

    function bindSyncButtons() {
        var buttons = document.querySelectorAll('[data-sync-mail]');
        var i;

        for (i = 0; i < buttons.length; i++) {
            buttons[i].addEventListener('click', function () {
                var mode = this.getAttribute('data-sync-mail') === 'initial' ? 'sync_initial' : 'sync_new';
                var loading = showLoading(mode === 'sync_initial' ? '메일을 묶음으로 가져오는 중입니다.' : '새 메일을 확인하는 중입니다.');

                postJson({
                    action: mode,
                    csrf_token: csrfToken(),
                    response_type: 'json'
                }, function (result) {
                    hideLoading(loading);
                    if (result && result.ok) {
                        alert(result.message || '완료되었습니다.');
                        window.location.reload();
                        return;
                    }
                    alert(result && result.message ? result.message : '메일 동기화에 실패했습니다.');
                });
            });
        }
    }

    function bindConnectionTest() {
        var button = document.querySelector('[data-test-connection]');
        if (!button) {
            return;
        }

        button.addEventListener('click', function () {
            var form = button.closest('form');
            var username = form.querySelector('[name="username"]');
            var password = form.querySelector('[name="password"]');
            var loading = showLoading('네이버 메일 연결을 확인하는 중입니다.');

            postJson({
                action: 'test_connection',
                csrf_token: csrfToken(),
                username: username ? username.value : '',
                password: password ? password.value : '',
                response_type: 'json'
            }, function (result) {
                hideLoading(loading);
                if (result && result.ok) {
                    alert((result.message || '연결이 정상입니다.') + '\n현재 받은메일함: ' + (result.mail_count || 0) + '건');
                    return;
                }
                alert(result && result.message ? result.message : '연결 확인에 실패했습니다.');
            });
        });
    }

    function bindRepairButton() {
        var button = document.querySelector('[data-repair-mail]');
        if (!button) return;
        button.addEventListener('click', function () {
            var loading = showLoading('기존 메일의 한글 제목과 본문 미리보기를 복구하는 중입니다.');
            postJson({
                action: 'repair_metadata',
                csrf_token: csrfToken(),
                response_type: 'json',
                limit: 20
            }, function (result) {
                hideLoading(loading);
                alert(result && result.message ? result.message : '복구 작업을 확인할 수 없습니다.');
                if (result && result.ok && parseInt(result.repaired_count, 10) > 0) window.location.reload();
            });
        });
    }

    function bindWorkflowNames() {
        var form = document.querySelector('[data-workflow-form]');
        if (!form) {
            return;
        }

        var projectSelect = form.querySelector('[data-project-select]');
        var projectName = form.querySelector('[data-project-name]');
        var assigneeSelect = form.querySelector('[data-assignee-select]');
        var assigneeName = form.querySelector('[data-assignee-name]');

        function updateHidden(select, hidden) {
            if (!select || !hidden) {
                return;
            }
            var option = select.options[select.selectedIndex];
            hidden.value = option ? (option.getAttribute('data-name') || '') : '';
        }

        if (projectSelect) {
            projectSelect.addEventListener('change', function () {
                updateHidden(projectSelect, projectName);
            });
        }
        if (assigneeSelect) {
            assigneeSelect.addEventListener('change', function () {
                updateHidden(assigneeSelect, assigneeName);
            });
        }

        form.addEventListener('submit', function () {
            updateHidden(projectSelect, projectName);
            updateHidden(assigneeSelect, assigneeName);
        });
    }

    function bindTaskModal() {
        var modal = document.querySelector('[data-task-modal]');
        var opener = document.querySelector('[data-task-modal-open]');
        if (!modal || !opener) {
            return;
        }

        function openModal() {
            modal.hidden = false;
            document.body.classList.add('pm-modal-open');
            var titleInput = modal.querySelector('[name="title"]');
            if (titleInput) {
                window.setTimeout(function () { titleInput.focus(); }, 30);
            }
        }

        function closeModal() {
            modal.hidden = true;
            document.body.classList.remove('pm-modal-open');
        }

        opener.addEventListener('click', openModal);
        var closers = modal.querySelectorAll('[data-task-modal-close]');
        var i;
        for (i = 0; i < closers.length; i++) {
            closers[i].addEventListener('click', closeModal);
        }

        document.addEventListener('keydown', function (event) {
            if (event.keyCode === 27 && !modal.hidden) {
                closeModal();
            }
        });
    }

    function startPeriodicSync() {
        var page = findPage();
        if (!page || !document.querySelector('[data-sync-mail="new"]')) {
            return;
        }

        window.setInterval(function () {
            if (document.hidden) {
                return;
            }
            postJson({
                action: 'sync_new',
                csrf_token: csrfToken(),
                response_type: 'json',
                limit: 20
            }, function (result) {
                if (result && result.ok && parseInt(result.added_count, 10) > 0) {
                    window.location.reload();
                }
            });
        }, 300000);
    }

    function init() {
        if (!findPage()) {
            return;
        }
        bindSyncButtons();
        bindConnectionTest();
        bindRepairButton();
        bindWorkflowNames();
        bindTaskModal();
        // 새 메일 자동확인은 사이드바 공통 1분 동기화가 담당합니다.

        if (window.lucide && typeof window.lucide.createIcons === 'function') {
            window.lucide.createIcons();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
