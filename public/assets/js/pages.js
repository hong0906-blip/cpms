// public/assets/js/pages.js
// - 모달 열기/닫기, 토스트(간단), 프로젝트 상세 채우기(샘플)

(function () {
  function qs(sel, root) { return (root || document).querySelector(sel); }
  function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  function showModal(name) {
    var el = qs('#modal-' + name);
    if (!el) return;
    el.classList.remove('hidden');
    if (window.lucide) { try { lucide.createIcons(); } catch (e) {} }
  }

  function hideModal(name) {
    var el = qs('#modal-' + name);
    if (!el) return;
    el.classList.add('hidden');
  }

  function toast(msg) {
    var box = document.createElement('div');
    box.className = 'fixed bottom-6 left-1/2 -translate-x-1/2 z-[999] px-4 py-3 rounded-2xl bg-gray-900 text-white text-sm font-bold shadow-2xl';
    box.textContent = msg;
    document.body.appendChild(box);
    setTimeout(function () { try { document.body.removeChild(box); } catch(e){} }, 1400);
  }

  // 관리 > 임직원 명부 > 나의 팀장 선택 보정
  // 공사부서 직원은 모든 부서에서 '나의 팀장'으로 선택할 수 있다.
  // 기존 employees.php의 부서 필터가 공사부서 옵션을 숨기거나
  // 기존에 저장된 공사부서 team_leader_id를 비우더라도 화면에서 다시 복원한다.
  function initEmployeeTeamLeaderCompatibility() {
    var addLeader = qs('#empAddTeamLeaderId');
    var editLeader = qs('#empEditTeamLeaderId');
    if (!addLeader && !editLeader) return;

    function revealConstructionOptions(leaderEl, preferredId) {
      if (!leaderEl) return;
      var preferred = String(preferredId || '');

      // 어느 부서 직원이든 공사부서 직원은 팀장 후보에 보이게 한다.
      for (var i = 0; i < leaderEl.options.length; i++) {
        var option = leaderEl.options[i];
        var optionKey = option.getAttribute('data-team-dept') || '';
        if (optionKey === 'construction') {
          option.style.display = '';
        }
      }

      // 기존에 공사부서 팀장이 저장돼 있었다면 기존 필터가 값을 비워도 복원한다.
      if (preferred !== '') {
        for (var j = 0; j < leaderEl.options.length; j++) {
          if (String(leaderEl.options[j].value) !== preferred) continue;
          var preferredKey = leaderEl.options[j].getAttribute('data-team-dept') || '';
          if (preferredKey === 'construction') {
            leaderEl.options[j].style.display = '';
            leaderEl.value = preferred;
          }
          break;
        }
      }
    }

    function refreshAdd(preferredId) {
      revealConstructionOptions(addLeader, preferredId);
    }

    function refreshEdit(preferredId) {
      revealConstructionOptions(editLeader, preferredId);
    }

    document.addEventListener('click', function (e) {
      var target = e.target && e.target.closest ? e.target.closest('[data-emp-edit]') : null;
      if (!target) return;
      var savedLeaderId = target.getAttribute('data-emp-team-leader-id') || '';
      window.setTimeout(function () { refreshEdit(savedLeaderId); }, 0);
    }, true);

    document.addEventListener('change', function (e) {
      var target = e.target;
      if (!target) return;
      if (target.id === 'empEditDept') {
        var currentEditLeader = editLeader ? editLeader.value : '';
        window.setTimeout(function () { refreshEdit(currentEditLeader); }, 0);
      } else if (target.name === 'department' && target.closest && target.closest('#modal-empAdd')) {
        var currentAddLeader = addLeader ? addLeader.value : '';
        window.setTimeout(function () { refreshAdd(currentAddLeader); }, 0);
      }
    }, true);

    refreshAdd(addLeader ? addLeader.value : '');
    refreshEdit(editLeader ? editLeader.value : '');
  }


  // 이벤트 위임
  document.addEventListener('click', function (e) {
    var t = e.target;

    // data-modal-open
    var open = t.closest ? t.closest('[data-modal-open]') : null;
    if (open) {
      var name = open.getAttribute('data-modal-open');

      // project detail 샘플 채우기
      if (name === 'projectDetail' && window.CPMS && Array.isArray(window.CPMS.projects)) {
        var pid = open.getAttribute('data-project-id');
        var p = null;
        for (var i=0; i<window.CPMS.projects.length; i++) {
          if (window.CPMS.projects[i].id === pid) { p = window.CPMS.projects[i]; break; }
        }
        if (p) {
          var title = qs('#projectDetailTitle'); if (title) title.textContent = p.name || '-';
          var loc = qs('#projectDetailLocation'); if (loc) loc.textContent = p.location || '-';
          var dt = qs('#projectDetailDate'); if (dt) dt.textContent = (p.startDate || '-') + ' ~ ' + (p.endDate || '-');
          var bud = qs('#projectDetailBudget'); if (bud) bud.textContent = p.budget || '-';
          var mgr = qs('#projectDetailManager'); if (mgr) mgr.textContent = p.manager || '-';

          var list = qs('#projectDetailContracts');
          if (list) {
            list.innerHTML = '';
            var cs = p.contracts || [];
            if (!cs.length) {
              list.innerHTML = '<div class="text-sm text-gray-500">첨부 없음</div>';
            } else {
              for (var j=0; j<cs.length; j++) {
                var c = cs[j];
                var row = document.createElement('div');
                row.className = 'flex items-center justify-between gap-3 p-3 rounded-2xl bg-white border border-gray-100';
                row.innerHTML =
                  '<div class="min-w-0">' +
                    '<div class="font-bold text-gray-900 truncate">' + (c.name || '-') + '</div>' +
                    '<div class="text-xs text-gray-500 mt-1">' + (c.size || '-') + ' · ' + (c.uploadDate || '-') + '</div>' +
                  '</div>' +
                  '<button type="button" class="px-3 py-2 rounded-2xl bg-white border border-gray-200 text-sm font-bold" data-toast="download">' +
                    '<span class="inline-flex items-center gap-2"><i data-lucide="download" class="w-4 h-4"></i> 다운로드</span>' +
                  '</button>';
                list.appendChild(row);
              }
            }
          }
        }
      }

      showModal(name);
      e.preventDefault();
      return;
    }

    // data-modal-close
    var close = t.closest ? t.closest('[data-modal-close]') : null;
    if (close) {
      var cname = close.getAttribute('data-modal-close');
      hideModal(cname);
      e.preventDefault();
      return;
    }

    // toast
    var ts = t.closest ? t.closest('[data-toast]') : null;
    if (ts) {
      var v = ts.getAttribute('data-toast') || '처리되었습니다(샘플)';
      toast(v);
      if (window.lucide) { try { lucide.createIcons(); } catch(e){} }
      e.preventDefault();
      return;
    }
  });
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initEmployeeTeamLeaderCompatibility);
  } else {
    initEmployeeTeamLeaderCompatibility();
  }
})();