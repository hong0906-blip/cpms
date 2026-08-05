/*
 * CPMS 화면 가이드 투어
 *
 * 중요 원칙
 * 1. "첫 번째 폼", "첫 번째 버튼"처럼 의미를 추측해서 안내하지 않는다.
 * 2. 각 화면의 고유 ID, data 속성, action 주소, 실제 제목으로만 대상을 찾는다.
 * 3. 권한이나 현재 상태 때문에 대상이 보이지 않으면 그 단계는 건너뛴다.
 * 4. 구형 브라우저에서도 동작하도록 ES5 문법만 사용한다.
 */
(function () {
  'use strict';

  if (!document.addEventListener || !document.querySelectorAll) return;

  var state = {
    active: false,
    steps: [],
    index: 0,
    target: null,
    previousFocus: null,
    scrollTimer: null
  };
  var ui = {};
  var GUIDE_GAP = 12;
  var VIEW_MARGIN = 14;

  function trimText(value) {
    return String(value || '').replace(/\s+/g, ' ').replace(/^\s+|\s+$/g, '');
  }

  function getParam(name) {
    var query = window.location.search ? window.location.search.substring(1).split('&') : [];
    var i;
    for (i = 0; i < query.length; i++) {
      var pair = query[i].split('=');
      var key = '';
      try {
        key = decodeURIComponent((pair[0] || '').replace(/\+/g, ' '));
      } catch (e) {
        key = pair[0] || '';
      }
      if (key !== name) continue;
      try {
        return decodeURIComponent((pair.slice(1).join('=') || '').replace(/\+/g, ' '));
      } catch (ignore) {
        return pair.slice(1).join('=') || '';
      }
    }
    return '';
  }

  function hasGuideAncestor(element) {
    var node = element;
    while (node && node !== document) {
      if (node.getAttribute && node.getAttribute('data-cpms-guide-ui') === '1') return true;
      node = node.parentNode;
    }
    return false;
  }

  function isVisible(element) {
    if (!element || !element.getBoundingClientRect || hasGuideAncestor(element)) return false;
    var style = window.getComputedStyle ? window.getComputedStyle(element) : element.currentStyle;
    if (style && (style.display === 'none' || style.visibility === 'hidden' || parseFloat(style.opacity || '1') === 0)) return false;
    var rect = element.getBoundingClientRect();
    return rect.width > 2 && rect.height > 2;
  }

  function visibleElements(selector) {
    var result = [];
    var found;
    var i;
    if (!selector) return result;
    try {
      found = document.querySelectorAll(selector);
    } catch (e) {
      found = [];
    }
    for (i = 0; i < found.length; i++) {
      if (isVisible(found[i])) result.push(found[i]);
    }
    return result;
  }

  function firstVisible(selector) {
    var selectors = String(selector || '').split(',');
    var i;
    var found;
    for (i = 0; i < selectors.length; i++) {
      found = visibleElements(trimText(selectors[i]));
      if (found.length) return found[0];
    }
    return null;
  }

  function elementMatches(element, selector) {
    if (!element || element.nodeType !== 1) return false;
    var matcher = element.matches || element.msMatchesSelector || element.webkitMatchesSelector;
    if (!matcher) return false;
    try {
      return matcher.call(element, selector);
    } catch (e) {
      return false;
    }
  }

  function closestMatch(element, selector) {
    var node = element;
    while (node && node !== document) {
      if (elementMatches(node, selector)) return node;
      node = node.parentNode;
    }
    return null;
  }

  function byText(selector, text, closestSelector) {
    return function () {
      var found;
      var i;
      try {
        found = document.querySelectorAll(selector);
      } catch (e) {
        found = [];
      }
      for (i = 0; i < found.length; i++) {
        if (!isVisible(found[i])) continue;
        if (trimText(found[i].textContent || found[i].innerText).indexOf(text) === -1) continue;
        if (closestSelector) {
          var parent = closestMatch(found[i], closestSelector);
          if (parent && isVisible(parent)) return parent;
        }
        return found[i];
      }
      return null;
    };
  }

  function groupOf(selector) {
    return function () {
      var items = visibleElements(selector);
      if (!items.length) return null;
      if (items.length === 1) return items[0];
      var candidate = items[0].parentNode;
      var i;
      while (candidate && candidate !== document.body) {
        var containsAll = true;
        for (i = 1; i < items.length; i++) {
          if (!candidate.contains(items[i])) {
            containsAll = false;
            break;
          }
        }
        if (containsAll && isVisible(candidate)) return candidate;
        candidate = candidate.parentNode;
      }
      return items[0];
    };
  }

  function formByAction(actionPart, fieldName, fieldValue) {
    return function () {
      var forms = visibleElements('form[action*="' + actionPart + '"]');
      var i;
      for (i = 0; i < forms.length; i++) {
        if (!fieldName) return forms[i];
        var fields;
        try {
          fields = forms[i].querySelectorAll('[name="' + fieldName + '"]');
        } catch (e) {
          fields = [];
        }
        var j;
        for (j = 0; j < fields.length; j++) {
          if (typeof fieldValue === 'undefined' || String(fields[j].value) === String(fieldValue)) return forms[i];
        }
      }
      return null;
    };
  }

  function formWithField(fieldName, fieldValue) {
    return function () {
      var forms = visibleElements('form');
      var i;
      for (i = 0; i < forms.length; i++) {
        var fields;
        try {
          fields = forms[i].querySelectorAll('[name="' + fieldName + '"]');
        } catch (e) {
          fields = [];
        }
        var j;
        for (j = 0; j < fields.length; j++) {
          if (typeof fieldValue === 'undefined' || String(fields[j].value) === String(fieldValue)) return forms[i];
        }
      }
      return null;
    };
  }

  function firstVisiblePanel(selector) {
    return function () {
      return firstVisible(selector);
    };
  }

  function step(target, title, text, hint) {
    return {
      target: target,
      title: title,
      text: text,
      hint: hint || ''
    };
  }

  function guide(title, intro, flow, steps) {
    return {
      title: title,
      intro: intro,
      flow: flow,
      steps: steps || []
    };
  }

  function noticeSteps() {
    return [
      step(
        '#cpmsDashboardNoticeBoard',
        '공지사항 영역',
        '회사 전체에 공유되는 공지를 확인하는 곳입니다.\n상단에는 공지 등록 기능이 있고, 아래 목록에는 제목·작성자·게시기간·고정 여부가 표시됩니다.',
        '공지 내용을 읽을 때는 제목을 누릅니다. 관리 권한이 있는 사람에게만 등록·수정·삭제 기능이 보입니다.'
      ),
      step(
        '[data-dashboard-notice-create]',
        '새 공지 등록',
        '이 버튼을 누르면 공지 작성 창이 열립니다.\n1. 제목과 내용을 입력합니다.\n2. 필요하면 게시 시작일·종료일과 상단 고정을 설정합니다.\n3. 저장 버튼을 눌러 목록에 반영합니다.',
        '지금 강조된 것은 등록 버튼입니다. 빨간 휴지통 모양은 등록이 아니라 삭제입니다.'
      ),
      step(
        '.cpms-notice-table',
        '공지 목록 확인',
        '등록된 공지를 행 단위로 확인합니다. 고정 공지는 일반 공지보다 위에 표시될 수 있으며, 게시기간이 끝난 공지는 현재 노출 조건에 따라 보이지 않을 수 있습니다.',
        '제목, 작성자, 게시기간을 함께 확인하면 오래된 공지를 새 공지로 오인하는 일을 줄일 수 있습니다.'
      ),
      step(
        '[data-dashboard-notice-open]',
        '공지 내용 열기',
        '공지 제목을 누르면 상세 창이 열립니다. 이 동작은 내용을 읽는 기능이며 수정하거나 삭제하지 않습니다.',
        '첨부나 긴 안내문이 있다면 상세 창 안에서 끝까지 확인한 뒤 닫으세요.'
      ),
      step(
        '[data-notice-manage-cell]',
        '수정과 삭제 구분',
        '관리 칸의 연필 아이콘은 기존 공지를 수정하는 기능이고, 빨간 휴지통 아이콘은 공지를 삭제하는 기능입니다.\n삭제는 등록이 아니며 확인창을 거친 뒤 실행됩니다.',
        '삭제 전에는 제목과 게시기간을 다시 확인하세요. 삭제된 공지는 화면에서 바로 복구할 수 없습니다.'
      )
    ];
  }

  function dashboardGuide(executive) {
    var steps = [];
    if (executive) {
      steps.push(step(
        groupOf('[data-executive-tab]'),
        '임원 대시보드 업무 탭',
        '메인·부서 업무·결재 대기·현장 이슈 등 보고 싶은 범위를 바꾸는 영역입니다. 탭을 누르면 같은 화면 안의 아래 내용만 해당 범위로 교체됩니다.',
        '탭 이름을 먼저 확인한 뒤 수치를 읽어야 서로 다른 기준의 건수를 혼동하지 않습니다.'
      ));
      steps.push(step(
        firstVisiblePanel('[data-executive-tab-panel]'),
        '선택한 탭의 상세 현황',
        '현재 활성 탭에 해당하는 지표와 목록입니다. 숫자는 요약 건수이고, 연결된 항목이나 제목을 누르면 원문 또는 상세 화면으로 이동합니다.',
        '건수만 보지 말고 지연·반려·미처리 항목의 상세 내용을 함께 확인하세요.'
      ));
      steps.push(step(
        '[data-project-cost-modal-open]',
        '프로젝트 원가 상세',
        '프로젝트별 계약금액, 실제 투입비와 원가율을 자세히 확인하는 기능입니다. 버튼을 누르면 현장별 비교 창이 열립니다.',
        '원가율이 높거나 매출 기준이 없는 현장은 세부 투입 내역을 먼저 점검하세요.'
      ));
    } else {
      steps.push(step(
        '.cpms-dashboard-hero',
        '오늘 업무 요약',
        '로그인한 사용자가 오늘 먼저 확인해야 할 출퇴근 상태, 처리할 업무와 주요 알림을 요약합니다. 표시된 숫자는 각 상세 목록의 건수입니다.',
        '숫자가 0이 아니면 아래의 해당 목록에서 기한과 요청자를 확인하세요.'
      ));
      steps.push(step(
        '.cpms-attendance-actions',
        '출퇴근 처리',
        '현재 근무 상태와 출근·퇴근 실행 버튼입니다. 실제 근무 장소와 시간이 맞는지 확인한 뒤 한 번만 누르세요.',
        '반복 클릭하지 마세요. 처리 결과와 기록 시간이 화면에 바뀌었는지 확인하면 됩니다.'
      ));
      steps.push(step(
        '#cpmsEmployeeTasksPanel',
        '받은 업무와 요청 업무',
        '내가 처리해야 하는 업무와 내가 다른 사람에게 요청한 업무의 상태를 확인합니다. 제목을 누르면 지시 내용, 기한, 첨부파일과 처리 이력이 열립니다.',
        '기한이 임박한 받은 업무를 먼저 처리하고, 요청 업무는 완료 요청이나 담당자 변경 요청을 확인하세요.'
      ));
    }
    steps = steps.concat(noticeSteps());
    return guide(
      executive ? '임원 대시보드' : '개인 대시보드',
      executive
        ? '회사와 부서의 업무·결재·현장 위험 신호를 한 화면에서 점검하는 보고용 화면입니다.'
        : '오늘의 근무 상태, 받은 업무, 요청한 업무와 회사 공지를 한 화면에서 확인하는 시작 화면입니다.',
      executive
        ? '업무 탭 선택 → 이상 수치 확인 → 연결된 상세 목록 점검 순서로 보세요.'
        : '출퇴근 상태 확인 → 기한이 가까운 업무 처리 → 새 공지 확인 순서로 사용하세요.',
      steps
    );
  }

  function employeesGuide() {
    return guide(
      '임직원 연락처',
      '이름, 부서, 직급, 근무 위치와 연락처를 빠르게 찾는 사내 주소록입니다.',
      '부서 필터로 범위를 줄인 뒤 검색어를 입력하고, 카드에서 전화번호나 이메일을 선택하세요.',
      [
        step(
          '.employee-directory-filters',
          '부서별 필터',
          '전체 또는 공사·공무·관리·품질/안전 등 조직 분류를 선택합니다. 선택 즉시 아래 카드가 해당 분류만 남도록 바뀝니다.',
          '여러 부서를 동시에 선택하는 방식이 아니라 한 번에 한 분류를 보는 방식입니다.'
        ),
        step(
          '#employeeDirectorySearch',
          '직원 검색',
          '이름뿐 아니라 사번, 부서, 직급, 근무 위치, 이메일로도 검색할 수 있습니다. 입력하는 즉시 현재 부서 필터 안에서 결과가 좁혀집니다.',
          '결과가 없으면 부서 필터를 “전체”로 바꾸고 검색어를 짧게 입력해 보세요.'
        ),
        step(
          '.employee-directory-card-grid',
          '직원 카드 목록',
          '각 카드에서 사진, 성명·직급, 부서, 사번, 생일, 근무 위치, 입사일, 이메일과 전화번호를 확인합니다.',
          '모바일에서는 이메일과 전화번호를 누르면 메일 앱 또는 전화 기능으로 연결됩니다.'
        )
      ]
    );
  }

  function schedulerGuide() {
    return guide(
      '업무 일정',
      '업무·회의·휴가 일정을 달력과 기간 목록으로 함께 확인하는 화면입니다.',
      '상태 필터 선택 → 조회 조건 적용 → 달력 또는 기간 목록에서 상세 확인 순서로 사용하세요.',
      [
        step(
          groupOf('[data-scheduler-filter]'),
          '일정 상태 필터',
          '전체, 회의, 진행, 긴급, 완료 등 보고 싶은 일정 상태를 선택합니다. 선택한 상태에 맞춰 달력과 목록이 함께 좁혀집니다.',
          '긴급과 지연 항목을 먼저 확인하고, 완료 일정은 기록 확인이 필요할 때 사용하세요.'
        ),
        step(
          formWithField('r', 'scheduler'),
          '조회 기간과 담당자',
          '달력에 표시할 기간, 담당자 또는 검색 조건을 지정하는 조회 영역입니다. 조건을 바꾼 뒤 조회해야 아래 일정에 반영됩니다.',
          '일정이 안 보이면 조회 기간과 담당자 조건이 너무 좁지 않은지 먼저 확인하세요.'
        ),
        step(
          groupOf('[data-scheduler-day]'),
          '월간 달력',
          '날짜별 일정 제목과 상태를 한눈에 봅니다. 날짜 안의 일정 제목을 누르면 해당 업무나 회의의 상세 내용이 열립니다.',
          '같은 날짜에 일정이 많으면 아래 기간 목록에서 전체 내용을 비교하세요.'
        ),
        step(
          groupOf('[data-scheduler-period-row]'),
          '기간별 일정 목록',
          '선택 기간의 일정을 행 단위로 비교합니다. 시작일·마감일·담당자·상태를 함께 확인하기에 적합합니다.',
          '마감일이 가까운데 완료되지 않은 항목부터 상세보기로 들어가 처리하세요.'
        )
      ]
    );
  }

  function tasksGuide(route) {
    if (route === 'tasks/detail') {
      return guide(
        '업무 상세',
        '한 건의 업무에 대한 요청 내용, 담당자, 기한, 첨부파일과 처리 이력을 확인하고 실제 처리를 기록하는 화면입니다.',
        '요청 원문과 첨부 확인 → 진행 상태 처리 → 필요 시 전달 또는 의견 등록 순서로 사용하세요.',
        [
          step(byText('h1,h2,h3', '업무', '.bg-white,section,article'), '업무 기본 정보', '업무 제목, 요청자, 담당자, 우선순위와 기한을 확인합니다. 처리 전에 요청 내용과 완료 기준이 무엇인지 먼저 읽으세요.'),
          step(formByAction('tasks/complete'), '업무 완료 처리', '실제로 업무를 마친 뒤 결과 내용과 필요한 첨부파일을 입력해 완료 요청을 보냅니다.', '완료 버튼은 단순 저장이 아니라 요청자에게 검토를 요청하는 단계입니다.'),
          step(formByAction('tasks/files_download'), '첨부파일 확인', '업무에 첨부된 파일을 선택해 내려받거나 전체 다운로드합니다. 파일 이름과 등록자를 확인하세요.'),
          step(formByAction('tasks/transfer'), '담당자 전달', '내가 처리할 업무가 아닐 때 새 담당자와 전달 사유를 지정합니다.', '전달 전에 새 담당자와 업무 범위를 협의하고 사유를 남기세요.'),
          step(formByAction('tasks/cancel'), '요청 취소', '내가 요청한 업무가 더 이상 필요 없을 때 취소합니다. 이미 진행된 업무인지 먼저 확인하세요.', '취소는 완료 처리와 다릅니다. 요청 자체를 중단할 때만 사용하세요.')
        ]
      );
    }
    return guide(
      '나의 업무',
      '내가 받은 업무와 내가 요청한 업무를 나누어 보고, 완료·담당자 변경 요청을 승인하는 화면입니다.',
      '받은 업무의 기한 확인 → 요청 업무의 처리 상태 확인 → 승인 요청 검토 순서로 사용하세요.',
      [
        step(byText('h1,h2,h3', '내가 받은 업무', '.bg-white,section,article'), '내가 받은 업무', '다른 사람이 나에게 요청한 업무입니다. 제목, 요청자, 기한과 현재 상태를 확인하고 제목을 눌러 상세 처리 화면으로 이동합니다.', '기한 초과 또는 임박 항목을 먼저 처리하세요.'),
        step(byText('h1,h2,h3', '내가 요청한 업무', '.bg-white,section,article'), '내가 요청한 업무', '내가 다른 사람에게 요청한 업무와 진행 결과입니다. 담당자가 완료를 요청했거나 변경을 요청한 경우 이 목록에서 검토합니다.'),
        step(formWithField('requested_task_date'), '요청 업무 날짜 필터', '특정 날짜에 요청한 업무만 찾을 때 사용합니다. 날짜를 선택해 조회하고 전체를 보려면 조건을 초기화하세요.'),
        step(formByAction('tasks/completion_approve'), '완료 요청 승인', '담당자가 업무 완료를 요청한 경우 결과 내용과 첨부파일을 확인한 뒤 승인합니다.', '보완이 필요하면 바로 승인하지 말고 상세 내용과 완료 기준을 다시 확인하세요.'),
        step(formByAction('tasks/transfer_approve'), '담당자 변경 승인', '담당자가 다른 직원으로 업무 전달을 요청한 경우 새 담당자와 전달 사유를 확인한 뒤 승인합니다.', '승인하면 실제 담당자가 바뀌므로 대상 직원이 맞는지 확인하세요.')
      ]
    );
  }

  function approvalGuide(route) {
    if (route === 'approval_create') {
      return guide(
        '전자결재 문서 작성',
        '품의서, 휴가계 등 선택한 문서 종류를 작성해 결재선으로 제출하는 화면입니다.',
        '문서 종류 확인 → 제목·본문·첨부 입력 → 결재선 확인 → 제출 순서로 진행하세요.',
        [
          step(formByAction('approval_store'), '결재 문서 입력', '제목, 문서 내용, 관련 기간과 첨부파일을 입력합니다. 문서 종류에 따라 필수 항목이 달라질 수 있습니다.', '제출 전 기간, 금액, 첨부파일과 결재 대상자를 다시 확인하세요.'),
          step(byText('button', '제출'), '결재 제출', '입력한 문서를 결재선에 등록합니다. 제출 후에는 진행 중 문서에서 결재 상태를 확인합니다.', '임시 확인이 끝나지 않았다면 제출하지 말고 입력 내용을 먼저 검토하세요.')
        ]
      );
    }
    if (route === 'approval_detail') {
      return guide(
        '전자결재 상세',
        '문서 원문, 첨부, 참조자, 결재 이력과 현재 결재 상태를 확인하는 화면입니다.',
        '문서 내용과 첨부 확인 → 결재 이력 확인 → 권한이 있을 때 승인 또는 반려 순서로 처리하세요.',
        [
          step(byText('h1,h2', '전자결재', '.bg-white,section,article'), '결재 문서 내용', '작성자, 문서 종류, 제목과 본문을 읽고 첨부파일까지 확인합니다.'),
          step(formByAction('approval_decide', 'action', 'approve'), '승인', '문서 내용에 문제가 없고 결재 권한이 있을 때 승인합니다. 승인하면 다음 결재자에게 넘어가거나 최종 완료됩니다.', '승인 전 금액·기간·첨부와 결재 의견을 모두 확인하세요.'),
          step(formByAction('approval_decide', 'action', 'reject'), '반려', '수정이 필요한 문서를 작성자에게 돌려보냅니다. 반려 사유를 구체적으로 입력해야 작성자가 보완할 수 있습니다.', '반려는 취소나 삭제가 아닙니다. 수정할 내용을 사유에 명확히 적으세요.'),
          step(byText('h3', '결재 이력', '.bg-white,section,article'), '결재 이력', '누가 언제 승인 또는 반려했는지와 남긴 의견을 시간 순서로 확인합니다.'),
          step(formByAction('approval_delete'), '취소 문서 삭제', '취소된 문서를 목록에서 삭제하는 기능입니다. 문서 작성이나 승인 기능이 아닙니다.', '삭제 후 복구가 어려우므로 문서 번호와 상태를 다시 확인하세요.')
        ]
      );
    }
    return guide(
      '전자결재',
      '결재 문서를 작성하고 진행 중·반려·취소·완료 상태별로 조회하는 화면입니다.',
      '문서 작성 또는 상태 탭 선택 → 검색 조건 적용 → 문서 제목을 눌러 상세 확인 순서로 사용하세요.',
      [
        step(
          groupOf('a[href*="approval_create"]'),
          '새 결재 문서 작성',
          '품의서, 간이품의서, 휴가계 등 작성할 문서 종류를 선택합니다. 선택한 종류에 맞는 입력 화면으로 이동합니다.',
          '문서 종류를 잘못 선택하면 필수 항목과 결재 흐름이 달라질 수 있으니 먼저 구분하세요.'
        ),
        step(
          groupOf('a[href*="approval_home&view="]'),
          '결재 상태 탭',
          '진행 중은 아직 결재가 끝나지 않은 문서, 반려는 수정이 필요한 문서, 취소는 작성자가 중단한 문서, 완료는 최종 처리된 문서입니다.',
          '새 문서가 안 보이면 현재 선택한 상태 탭이 맞는지 확인하세요.'
        ),
        step(
          formWithField('r', 'approval_home'),
          '문서 검색',
          '작성자, 문서 종류, 검색어와 기간으로 문서를 좁힙니다. 조건을 바꾼 뒤 조회해야 목록에 반영됩니다.',
          '완료 탭에서는 기간 조건이 별도로 표시될 수 있습니다.'
        ),
        step(
          '.cpms-approval-table,.cpms-approval-mobile-list',
          '결재 문서 목록',
          '문서 번호, 종류, 제목, 작성자, 결재 상태와 최근 처리일을 확인합니다. 제목 또는 상세 버튼을 눌러 원문과 결재 이력으로 이동합니다.',
          '승인·반려는 목록의 제목만 보고 결정하지 말고 반드시 상세 문서를 확인한 뒤 처리하세요.'
        )
      ]
    );
  }

  function constructionBaseSteps() {
    return [
      step(
        formWithField('pid'),
        '대상 프로젝트 선택',
        '입력하거나 조회할 현장을 선택합니다. 이 선택에 따라 아래 공정·원가·이슈 데이터 전체가 바뀝니다.',
        '다른 현장 자료에 잘못 입력하지 않도록 저장 전에 프로젝트명을 한 번 더 확인하세요.'
      ),
      step(
        '.cpms-construction-tabs',
        '공사 업무 탭',
        '공사현황, 공정표, 일일현황, 노무비, 외주비, 장비비, 자재비, 기성내역서, 현장 이슈 등 업무 종류를 바꿉니다.',
        '탭을 이동하면 입력 양식과 조회 기준이 달라집니다. 현재 선택된 탭 색상을 확인하세요.'
      )
    ];
  }

  function constructionTabSteps(tab) {
    if (tab === 'roles') {
      return [
        step(formByAction('construction/roles_save'), '현장 담당자 지정', '현장·안전·품질 등 역할별 담당자를 선택해 프로젝트 책임 체계를 저장합니다.', '기존 담당자를 바꾸면 알림과 업무 책임 대상이 달라질 수 있습니다.'),
        step(byText('h2,h3', '담당', '.bg-white,section,article'), '현재 담당 체계', '저장된 역할별 담당자를 확인합니다. 비어 있는 역할이 있으면 권한자에게 지정을 요청하세요.')
      ];
    }
    if (tab === 'gantt' || tab === 'work') {
      return [
        step(groupOf('.gantt-tab[data-tab]'), '공정표 보기 전환', '개요·보드·진척 등 공정표 표시 방식을 바꿉니다. 같은 공정 데이터를 목적에 맞는 형태로 보는 기능입니다.'),
        step(firstVisiblePanel('[data-tab-panel]'), '현재 공정 현황', '공정별 시작일, 종료일, 선후 관계와 진행률을 확인합니다. 계획과 실제 진행이 다른 공정을 우선 점검하세요.'),
        step('#ganttProgressSave', '공정 진행률 저장', '실제 완료 정도를 입력해 공정 진행률을 갱신합니다.', '진행률은 기성·현황 보고에 영향을 줄 수 있으므로 근거를 확인하고 입력하세요.'),
        step('[data-modal-open="issueAdd"]', '공정 관련 이슈 등록', '지연 원인이나 현장 문제를 이슈로 등록해 담당자와 조치 과정을 관리합니다.')
      ];
    }
    if (tab === 'daily_status') {
      return [
        step(groupOf('[data-daily-status-date]'), '일자 선택', '확인할 작업일을 선택합니다. 날짜를 바꾸면 해당 일자의 작업 내용과 투입 현황이 표시됩니다.'),
        step(byText('h2,h3', '일일', '.bg-white,section,article'), '일일 작업 현황', '선택 날짜의 작업 내용, 인원과 진행 상황을 확인합니다. 누락된 날은 실제 작업 여부를 확인해 보완하세요.')
      ];
    }
    if (tab === 'labor') {
      return [
        step(groupOf('a[href*="tab=labor"][href*="labor_tab="]'), '노무비 보기 전환', '월별 집계와 인원 작성 화면을 전환합니다. 먼저 대상 월을 맞춘 뒤 작업하세요.'),
        step(groupOf('[data-labor-bulk-value]'), '공수 일괄 입력', '여러 근로자의 공수를 같은 값으로 한 번에 입력합니다. 개별 값이 다른 사람은 일괄 적용 후 따로 수정해야 합니다.', '일괄 입력은 기존 값을 바꿀 수 있으므로 선택 인원과 날짜를 먼저 확인하세요.'),
        step(formByAction('labor_worker_add'), '근로자 추가', '프로젝트 노무비를 작성할 근로자 정보를 추가합니다.'),
        step(formByAction('labor_workers_save'), '노무 공수 저장', '근로자별 작업일과 공수를 저장해 월별 노무비 집계에 반영합니다.', '저장 후 합계 공수와 금액이 예상과 같은지 확인하세요.')
      ];
    }
    if (tab === 'outsourcing') {
      return [
        step(groupOf('a[href*="tab=outsourcing"][href*="outsourcing_tab="]'), '외주비 조회·입력 전환', '월별 조회와 새 외주비 입력 화면을 전환합니다.'),
        step(formByAction('construction/outsourcing_cost_save', 'action', 'save'), '외주비 입력', '업체, 작업 내용, 금액과 귀속 월을 입력해 프로젝트 실제 투입비에 반영합니다.', '지금 강조된 양식의 실행 버튼 값은 “저장”입니다. 삭제 버튼과 구분하세요.'),
        step(formByAction('construction/outsourcing_cost_save', 'action', 'delete'), '외주비 삭제', '기존 외주비 행을 제거하는 기능입니다. 새 외주비를 입력하거나 수정하는 기능이 아닙니다.', '대상 업체, 작업일과 금액을 확인하세요. 삭제 후에는 복구가 어렵습니다.'),
        step(byText('h2,h3', '외주비', '.bg-white,section,article'), '외주비 내역', '선택한 월의 업체별 외주비와 합계를 확인합니다. 수정·삭제 전 대상 행을 다시 확인하세요.')
      ];
    }
    if (tab === 'equipment') {
      return [
        step('#equipmentMobileQuickForm', '장비비 빠른 조회', '모바일에서 대상 월과 입력 화면을 빠르게 바꾸는 영역입니다.'),
        step('#equipmentCreateForm', '장비 사용내역 등록', '장비명, 업체, 사용 기간·공수와 금액을 입력해 장비비에 반영합니다.', '장비와 사용내역은 서로 다른 자료이므로 현재 입력 영역 제목을 확인하세요.'),
        step(formByAction('construction/equipment_usage_save'), '장비 사용 실적 저장', '실제 장비 사용 날짜와 공수를 저장합니다. 저장된 실적이 월별 장비비 집계의 근거가 됩니다.'),
        step(byText('h2,h3', '장비비', '.bg-white,section,article'), '장비비 목록', '등록된 장비와 사용 실적, 월별 금액을 확인합니다.')
      ];
    }
    if (tab === 'materials') {
      return [
        step('#materialMobileQuickForm', '자재비 빠른 조회', '모바일에서 대상 월과 조회·입력 화면을 빠르게 전환합니다.'),
        step('#materialCreateForm', '자재 등록', '자재명, 거래처, 수량, 단가와 사용 정보를 입력합니다.', '수량과 단위가 맞아야 금액 집계가 정확합니다.'),
        step('#materialMonthlyExcelUpload', '자재 엑셀 업로드', '정해진 양식의 월별 자재 자료를 미리보기 후 일괄 반영합니다.', '업로드 전에 대상 프로젝트와 월, 열 구성이 양식과 같은지 확인하세요.'),
        step('#materialUsageBulkDeleteForm', '자재 사용내역 일괄 삭제', '선택한 여러 사용내역을 한 번에 삭제합니다. 등록 기능이 아닙니다.', '삭제 대상 체크와 건수를 확인하세요. 삭제 후 복구가 어렵습니다.'),
        step(byText('h2,h3', '자재', '.bg-white,section,article'), '자재비 목록', '거래처, 자재명, 수량·단가와 사용 금액을 확인합니다.')
      ];
    }
    if (tab === 'progress_statement') {
      return [
        step(formByAction('construction/progress_statement_upload'), '기성내역서 제출', '검토받을 기성내역서 파일과 대상 회차를 선택해 제출합니다. 제출 후 버전과 검토 상태가 기록됩니다.', '파일명, 프로젝트와 회차가 맞는지 확인한 뒤 제출하세요.'),
        step(byText('h2,h3', '기성', '.bg-white,section,article'), '제출·검토 상태', '현재 파일 버전, 제출 일시, 검토 의견과 보완 요청 여부를 확인합니다.'),
        step(formByAction('project/progress_statement_comment_save'), '검토 의견 등록', '검토자와 제출자가 보완 내용이나 답변을 기록합니다. 어떤 항목을 수정했는지 구체적으로 남기세요.')
      ];
    }
    if (tab === 'issues') {
      return [
        step('[data-modal-open="issueAdd"]', '현장 이슈 등록', '문제 내용, 중요도, 담당자와 조치 기한을 등록합니다. 등록 후 상태와 댓글로 해결 과정을 남깁니다.'),
        step(byText('h2,h3', '현장 이슈', '.bg-white,section,article'), '이슈 목록', '미해결·진행·완료 상태와 담당자, 기한을 확인합니다. 지연되거나 중요도가 높은 항목부터 처리하세요.'),
        step(formByAction('construction/issue_comment'), '이슈 댓글', '조치 내용과 진행 상황을 시간 순서로 기록합니다. 단순 완료 표시보다 실제 조치 근거를 남기세요.')
      ];
    }
    if (tab === 'security') {
      return [
        step('[data-modal-open="securityIssueAdd"]', '보안사고 등록', '보안 관련 사고 내용, 발생 시점, 담당자와 조치 내용을 등록합니다.'),
        step(byText('h2,h3', '보안', '.bg-white,section,article'), '보안사고 목록', '사고 상태와 후속 조치 기록을 확인합니다. 민감정보는 필요한 범위만 입력하세요.')
      ];
    }
    if (tab === 'safety') {
      return [
        step('[data-modal-open="safetyIncidentAdd"]', '안전사고 등록', '발생 일시, 사고 내용, 피해와 조치 사항을 등록합니다.'),
        step(byText('h2,h3', '안전사고', '.bg-white,section,article'), '안전사고 목록', '사고별 상태, 원인과 후속 조치를 확인합니다. 완료 처리 전 재발 방지 조치까지 기록하세요.')
      ];
    }
    return [
      step('.cpms-status-filter', '공사현황 조회 조건', '기간 또는 상태 조건을 선택해 현재 프로젝트의 계약·원가·진행 현황을 좁혀 봅니다.'),
      step('.cpms-status-mobile-table', '프로젝트 현황표', '계약금액, 실제 투입비, 진행 상태와 주요 지표를 비교합니다. 값이 비어 있으면 원천 입력 자료가 있는지 먼저 확인하세요.'),
      step(byText('h2,h3', '현황', '.bg-white,section,article'), '현재 공사현황', '선택 프로젝트의 핵심 진행 정보입니다. 계획 대비 차이가 큰 항목은 관련 비용·공정 탭에서 상세 원인을 확인하세요.')
    ];
  }

  function constructionGuide() {
    var tab = getParam('tab') || 'status';
    var names = {
      status: '공사현황',
      monthly_input: '월별 투입비',
      roles: '담당자 지정',
      gantt: '공정표',
      work: '공정표',
      daily_status: '일일현황',
      labor: '노무비',
      outsourcing: '외주비',
      equipment: '장비비',
      materials: '자재비',
      progress_statement: '기성내역서',
      issues: '현장 이슈',
      security: '보안사고',
      safety: '안전사고'
    };
    return guide(
      '공사 · ' + (names[tab] || '현장 관리'),
      '프로젝트별 공정, 실제 투입비, 기성자료와 현장 이슈를 업무 종류별로 관리하는 화면입니다.',
      '프로젝트 확인 → 업무 탭 확인 → 조회 또는 입력 → 목록에서 반영 결과 확인 순서로 사용하세요.',
      constructionBaseSteps().concat(constructionTabSteps(tab))
    );
  }

  function safetyGuide() {
    var tab = getParam('tab') || 'safety_cost';
    var steps = [
      step(formWithField('r', 'safety_home'), '프로젝트와 안전 업무 선택', '대상 프로젝트와 안전관리비·사고·포탈 자료 중 볼 업무를 선택합니다.', '저장 전에 프로젝트가 맞는지 다시 확인하세요.'),
      step(groupOf('a[href*="safety_home"][href*="tab="]'), '안전·보건 업무 탭', '안전관리비, 안전사고, 삼성 상생협력포탈 등 업무 화면을 전환합니다.')
    ];
    if (tab === 'samsung_portal') {
      steps.push(step(formByAction('safety/samsung_portal_upload'), '상생협력포탈 자료 업로드', '포탈에서 내려받은 대상자 자료를 지정 양식으로 업로드해 목록에 반영합니다.', '대상 프로젝트와 파일 기준일을 확인하세요.'));
      steps.push(step(formByAction('safety/samsung_portal_health_upload'), '건강검진 자료 업로드', '대상자의 건강검진 자료를 업로드해 안전관리 대상자 정보와 연결합니다.', '개인정보가 포함된 파일이므로 권한과 파일을 정확히 확인하세요.'));
      steps.push(step(byText('h3', '삼성 상생협력포탈', '.bg-white,section,article'), '포탈 대상자 목록', '업로드된 대상자의 상태, 교육·검진 관련 정보를 조회하고 필요한 행만 수정합니다.'));
    } else if (tab === 'incidents' || tab === 'safety_incident') {
      steps.push(step(formByAction('safety/safety_incident_save'), '안전사고 입력', '사고 발생일, 내용, 피해와 조치 결과를 등록합니다.', '사고 원인과 재발 방지 조치까지 구체적으로 기록하세요.'));
      steps.push(step(byText('h3', '안전사고', '.bg-white,section,article'), '안전사고 이력', '등록된 사고와 후속 조치 상태를 확인합니다. 상태 변경 전 조치 근거를 확인하세요.'));
    } else {
      steps.push(step('#safetyCostForm', '안전관리비 사용 등록', '사용일, 항목, 거래처, 공급가액과 증빙을 입력해 안전관리비 집계에 반영합니다.', '빨간 삭제 버튼과 등록 폼을 구분하고, 증빙 금액과 입력 금액이 같은지 확인하세요.'));
      steps.push(step(byText('h3', '안전관리비 사용내역', '.bg-white,section,article'), '안전관리비 사용내역', '계약 안전관리비, 사용 누계, 잔액과 항목별 사용 내역을 확인합니다.', '삭제는 해당 행의 기존 사용 기록을 제거하는 기능이며 등록이 아닙니다.'));
    }
    return guide(
      '안전·보건',
      '프로젝트별 안전관리비, 안전사고와 외부 포탈 자료를 관리하는 화면입니다.',
      '프로젝트와 업무 탭 선택 → 자료 입력 또는 업로드 → 목록과 합계 확인 순서로 사용하세요.',
      steps
    );
  }

  function estimateGuide() {
    var tab = getParam('tab') || 'write';
    var steps = [
      step(groupOf('a[href*="estimate_home&tab="]'), '견적 업무 탭', '견적 작성, 과거 단가 검색, 견적 이력, 입찰 결과 화면을 전환합니다. 현재 목적과 맞는 탭인지 먼저 확인하세요.')
    ];
    if (tab === 'search') {
      steps.push(step(formWithField('tab', 'search'), '과거 단가 검색 조건', '품목명, 기간과 프로젝트 등 조건으로 기존 견적 단가를 찾습니다. 비슷한 규격인지 함께 확인하세요.'));
      steps.push(step(formByAction('estimate/price_import_preview'), '단가 엑셀 가져오기', '정해진 양식의 단가 파일을 미리보기로 검증한 뒤 반영합니다.', '미리보기에서 품목·규격·단위 열이 올바르게 인식됐는지 확인하세요.'));
      steps.push(step(byText('h2,h3', '검색', '.bg-white,section,article'), '검색 결과', '과거 품목의 규격, 단위, 단가와 적용 시점을 비교합니다. 가장 최근 값만 보지 말고 공사 조건도 함께 판단하세요.'));
    } else if (tab === 'history') {
      steps.push(step(byText('h2,h3', '이력', '.bg-white,section,article'), '견적 이력', '저장된 견적의 작성일, 프로젝트, 총액과 상태를 확인합니다. 항목을 선택하면 세부 견적과 엑셀 다운로드 기능이 표시됩니다.'));
    } else if (tab === 'bid_result') {
      steps.push(step(formByAction('estimate/bid_result_save'), '입찰 결과 등록', '선택한 견적의 낙찰 여부, 최종 금액과 결과 메모를 저장합니다.', '대상 견적과 최종 금액을 확인한 뒤 저장하세요.'));
    } else {
      steps.push(step('#estimateWriteForm', '견적 기본정보와 품목', '프로젝트·견적명을 입력하고 품목별 규격, 단위, 수량과 단가를 작성합니다. 수량×단가가 금액에 반영됩니다.', '필수 정보와 부가세 적용 기준을 확인한 뒤 저장하세요.'));
      steps.push(step('#estimateItemSearchBtn', '과거 품목 찾기', '현재 작성 중인 품목과 비슷한 과거 단가를 검색해 참고하거나 선택 품목으로 추가합니다.', '과거 단가는 참고값입니다. 규격과 시점이 현재 조건과 같은지 확인하세요.'));
      steps.push(step('#estimateItemsTable', '견적 품목표', '작성한 품목의 규격, 단위, 수량, 단가와 금액을 행별로 검토합니다.', '빈 행, 중복 품목과 잘못된 단위를 저장 전에 확인하세요.'));
    }
    return guide(
      '견적관리',
      '견적서를 작성하고 과거 단가와 견적 이력, 실제 입찰 결과를 연결해 관리합니다.',
      '업무 탭 선택 → 조건 또는 기본정보 입력 → 품목·결과 검토 → 저장 순서로 사용하세요.',
      steps
    );
  }

  function projectGuide(route) {
    if (route === 'project/detail') {
      return guide(
        '프로젝트 상세',
        '한 프로젝트의 기본정보, 계약자료, 추가공사, 기성·원가 관련 자료를 모아 관리하는 화면입니다.',
        '프로젝트명 확인 → 필요한 관리 영역으로 이동 → 자료 입력 → 기존 이력 확인 순서로 사용하세요.',
        [
          step(byText('h1,h2', '프로젝트', '.bg-white,section,article'), '프로젝트 기본정보', '프로젝트명, 발주처, 계약기간, 상태와 담당자를 확인합니다. 모든 입력은 이 프로젝트에 귀속됩니다.'),
          step(formByAction('project_edit_save'), '프로젝트 정보 수정', '프로젝트의 기본정보와 담당 정보를 수정합니다.', '계약기간이나 상태 변경은 다른 집계 기준에 영향을 줄 수 있습니다.'),
          step(formByAction('project/contract_upload'), '계약서 업로드', '계약서 파일을 프로젝트에 보관하고 기존 계약자료와 연결합니다.', '최종본 여부와 파일명을 확인하세요.'),
          step(formByAction('project/contract_change_preview'), '계약 변경 미리보기', '변경 계약 자료를 바로 반영하기 전에 기존 값과 비교합니다.', '차이 내용을 검토한 뒤 확정 단계로 진행하세요.'),
          step(formByAction('project/additional_work_save'), '추가공사 등록', '계약 외 추가 작업의 내용과 금액을 별도 이력으로 저장합니다.'),
          step(formByAction('project/progress_save', 'action', 'create'), '기성 정보 등록', '새 기성 회차, 기성일, 확정매출 금액과 첨부파일을 등록해 프로젝트 진행 집계에 반영합니다.', '기존 행의 수정·삭제 버튼과 구분하세요. 지금 강조된 양식은 새 회차 등록입니다.')
        ]
      );
    }
    var tab = getParam('tab') || 'monthly_summary';
    var steps = [
      step(groupOf('a[href*="r=공무&tab="]'), '공무 업무 탭', '월별 투입비 집계, 프로젝트 관리, 기성내역서 검토와 협업 화면을 전환합니다.')
    ];
    if (tab === 'project_manage') {
      steps.push(step('[data-modal-open="projectAdd"]', '프로젝트 추가', '새 프로젝트의 이름, 발주처, 계약기간과 담당자 정보를 입력하는 창을 엽니다.', '빨간 삭제 기능과 별개입니다. 프로젝트가 중복 등록되지 않았는지 먼저 확인하세요.'));
      steps.push(step(groupOf('a[href*="project_status="]'), '프로젝트 상태 필터', '진행·완료 등 상태에 따라 프로젝트 목록을 좁힙니다. 상태를 바꾸면 아래 목록이 다시 조회됩니다.'));
      steps.push(step(byText('h2,h3', '프로젝트 관리', '.bg-white,section,article'), '프로젝트 목록', '프로젝트명, 계약기간, 담당자와 상태를 확인하고 프로젝트명을 눌러 상세 관리 화면으로 이동합니다.', '삭제는 해당 프로젝트 자료에 영향을 줄 수 있으므로 상세 확인 후 사용하세요.'));
    } else if (tab === 'progress_statement_review') {
      steps.push(step('#cpmsPsCards', '검토 상태 요약', '검토대기, 반려, 승인, Drive 실패 등 상태별 건수를 보여줍니다. 카드를 누르면 아래 목록이 해당 상태만 남도록 바뀝니다.'));
      steps.push(step(formWithField('tab', 'progress_statement_review'), '기성내역서 검색 조건', '현장, 대상 월, 기성 차수, 제출자와 제출일 기간으로 검토 대상을 좁힙니다.'));
      steps.push(step('#cpmsPsReviewList', '기성내역서 검토 목록', '프로젝트별 제출 버전, 제출자, 검토 상태와 보완 요청을 확인합니다. 현재 파일을 내려받아 실제 내용을 확인하세요.'));
      steps.push(step(formByAction('project/progress_statement_action', 'action', 'approve'), '기성내역서 승인', '현재 파일과 제출 정보를 확인한 뒤 검토를 승인합니다. Drive 재업로드나 댓글 등록 기능과는 별개입니다.', '파일을 실제로 열어 프로젝트·회차·금액을 확인한 뒤 승인하세요.'));
      steps.push(step(formByAction('project/progress_statement_action', 'action', 'reject'), '기성내역서 반려', '보완이 필요한 제출 건을 반려하고 필수 반려 사유를 작성합니다.', '수정할 시트·항목과 사유를 구체적으로 남겨야 재제출자가 정확히 보완할 수 있습니다.'));
      steps.push(step(formByAction('project/progress_statement_comment_save'), '검토 댓글 등록', '승인·반려와 별개로 질의, 답변 또는 수정 내용을 기록합니다. 댓글 등록은 결재 상태를 바꾸지 않습니다.'));
    } else {
      steps.push(step(byText('h2,h3', '월별', '.bg-white,section,article'), '월별 투입비 집계', '프로젝트별 노무비·외주비·장비비·자재비 등 실제 투입비를 월 단위로 비교합니다.', '합계가 예상과 다르면 공사 메뉴의 해당 비용 탭에서 원본 입력을 확인하세요.'));
      steps.push(step('.cpms-responsive-table', '프로젝트별 집계표', '월과 프로젝트별 비용 항목 및 합계를 확인합니다. 가로 스크롤이 필요한 경우 마지막 합계 열까지 확인하세요.'));
    }
    return guide(
      '공무·프로젝트',
      '프로젝트 기본정보와 계약자료, 월별 투입비 및 기성내역서를 관리하는 화면입니다.',
      '업무 탭 선택 → 프로젝트 또는 기간 확인 → 상세 자료 검토 순서로 사용하세요.',
      steps
    );
  }

  function qualityGuide() {
    return guide(
      '품질 파일관리',
      '프로젝트별 품질 문서를 분류해 업로드하고 필요한 파일을 검색·다운로드하는 화면입니다.',
      '프로젝트와 문서 종류 조회 → 파일 정보 입력·업로드 → 목록에서 반영 결과 확인 순서로 사용하세요.',
      [
        step(formWithField('r', 'quality_home'), '품질 문서 조회', '프로젝트, 문서 종류와 검색 조건을 선택해 필요한 품질 파일만 표시합니다.'),
        step(formByAction('quality/file_upload'), '품질 파일 업로드', '프로젝트, 문서 종류, 기준일, 제목과 파일을 선택해 등록합니다.', '프로젝트와 문서 종류를 잘못 지정하면 나중에 검색하기 어렵습니다.'),
        step(byText('h2,h3', '품질', '.bg-white,section,article'), '품질 파일 목록', '등록된 문서의 기준일, 제목, 등록자와 파일을 확인하고 필요한 파일을 내려받습니다.')
      ]
    );
  }

  function managementGuide() {
    var tab = getParam('tab') || 'employees';
    var steps = [
      step(groupOf('a[href*="r=관리"][href*="tab="]'), '관리 업무 탭', '직원, 인력, 출퇴근, 휴가, 급여·관리비와 자료 점검 화면을 전환합니다.', '개인정보와 금액을 다루므로 필요한 업무 탭과 권한을 확인하세요.')
    ];
    if (tab === 'employees') {
      steps.push(step('[data-modal-open="empAdd"]', '직원 추가', '신규 직원의 사번, 이름, 부서, 직급, 연락처, 계정과 권한 정보를 입력하는 창을 엽니다.', '퇴직자 복구와 신규 등록을 구분하고 이메일·사번 중복을 확인하세요.'));
      steps.push(step(formWithField('employee_view'), '재직·퇴직 직원 조회', '재직 상태와 검색어로 직원 목록을 좁힙니다. 퇴직자는 별도 탭에서 확인합니다.'));
      steps.push(step(byText('h2,h3', '직원', '.bg-white,section,article'), '직원 목록', '직원의 계정, 조직, 직급, 재직 상태와 수정 기능을 확인합니다.', '삭제 또는 퇴직 처리는 계정 접근에 영향을 주므로 대상 직원을 다시 확인하세요.'));
    } else if (tab === 'workforce') {
      steps.push(step(groupOf('a[href*="admin/workforce_"]'), '인력 추가·엑셀 업로드', '한 명씩 직접 추가하거나 지정 양식으로 여러 인력을 업로드합니다.'));
      steps.push(step(formWithField('tab', 'workforce'), '인력 조회', '프로젝트, 업체, 직종과 검색어로 등록된 인력을 찾습니다.'));
      steps.push(step(byText('h2,h3', '인력', '.bg-white,section,article'), '인력 목록', '인력의 소속, 직종, 연락처와 투입 상태를 확인합니다. 삭제는 신규 등록이 아닙니다.'));
    } else if (tab === 'attendance') {
      steps.push(step('.cpms-attendance-controls', '출퇴근 조회 기준', '연도·월, 부서와 직원 조건을 선택해 근태표를 조회합니다.'));
      steps.push(step('.cpms-attendance-month-table', '월간 근태표', '직원별 날짜의 출근·퇴근, 휴가와 이상 상태를 월 단위로 확인합니다. 셀을 선택하면 권한에 따라 상세 수정 창이 열립니다.'));
      steps.push(step('[data-attendance-cell-edit]', '근태 셀 수정', '해당 직원의 선택 날짜 근태를 수정하는 기능입니다.', '직원과 날짜, 수정 사유를 확인한 뒤 저장하세요.'));
    } else if (tab === 'leave_management') {
      steps.push(step('.cpms-leave-filter', '휴가 조회 조건', '연도, 직원, 부서와 처리 상태로 휴가 자료를 조회합니다.'));
      steps.push(step('.cpms-leave-table', '휴가 현황표', '직원별 발생·사용·잔여 휴가와 결재 상태를 확인합니다.', '잔여 일수는 승인 완료된 휴가 반영 여부까지 확인하세요.'));
    } else if (tab === 'labor_calc') {
      steps.push(step(formWithField('tab', 'labor_calc'), '급여 계산 기준', '연도·월과 프로젝트 등 계산 대상을 선택합니다.'));
      steps.push(step(byText('h2,h3', '급여', '.bg-white,section,article'), '급여·노무비 계산 결과', '근태와 공수 자료를 바탕으로 계산된 금액을 확인합니다. 원본 자료 누락 여부를 먼저 점검하세요.'));
    } else {
      steps.push(step(byText('h1,h2,h3', '관리', '.bg-white,section,article'), '현재 관리 업무', '선택한 관리 탭의 조회 조건, 입력 자료와 결과 목록입니다. 화면 제목과 기준 기간을 먼저 확인하세요.'));
      steps.push(step('#cpmsContentMain table', '관리 자료 목록', '조회된 자료의 대상자·프로젝트·기간과 금액을 행 단위로 확인합니다.', '수정·삭제 전에 대상 행과 기준 기간을 다시 확인하세요.'));
    }
    return guide(
      '관리',
      '직원, 인력, 출퇴근, 휴가와 회사 운영 자료를 권한에 따라 관리하는 화면입니다.',
      '업무 탭 선택 → 조회 기준 확인 → 필요한 자료 처리 → 목록에서 결과 검증 순서로 사용하세요.',
      steps
    );
  }

  function companyProfitGuide() {
    return guide(
      '경영현황',
      '선택 기간의 회사 매출, 실제 투입비, 관리비와 프로젝트별 손익을 종합해 보는 화면입니다.',
      '조회 기준 확인 → 전체 요약 → 기간 추이 → 프로젝트별 원인 확인 순서로 보세요.',
      [
        step('.cp-filter', '경영현황 조회 기준', '연도·월, 프로젝트 상태 등 집계 범위를 지정합니다. 조건을 바꾼 뒤 조회해야 모든 카드와 표가 같은 기준으로 갱신됩니다.', '서로 다른 기간의 수치를 비교하지 않도록 상단 조회 조건을 먼저 확인하세요.'),
        step('.cp-summary-grid', '회사 손익 요약', '확정 순이익, 매출, 실제 투입비, 현장 귀속 관리비와 목표 금액을 요약합니다. 순이익은 매출에서 집계 대상 비용을 차감한 결과입니다.', '매출 기준이 없는 프로젝트는 원가율 해석이 제한될 수 있습니다.'),
        step('.cp-period-chart', '기간별 변화', '월별 매출·비용·손익 변화를 비교합니다. 특정 월의 급격한 변화가 있으면 아래 프로젝트 상세에서 원인을 찾으세요.'),
        step(byText('h2,h3', '현장별 상세', '.cp-section,.bg-white,section,article'), '현장별 손익 상세', '프로젝트별 매출, 투입비, 목표, 순이익과 원가율을 비교합니다. 손실 또는 높은 원가율 현장을 우선 점검하세요.'),
        step(byText('h2,h3', '안전관리비', '.cp-section,.bg-white,section,article'), '안전관리비 요약', '안전관리비 계약액, 사용액, 잔액과 사용률을 별도로 확인합니다. 일반 투입비와 안전관리비 기준을 구분해 보세요.')
      ]
    );
  }

  function representativeGuide() {
    return guide(
      '대표 경영현황',
      '회사 전체 손익과 위험도가 높은 현장을 빠르게 판단하기 위한 대표용 요약 화면입니다.',
      '기간 선택 → 전체 지표 확인 → 위험현장 순위 확인 → 현장 카드 상세 열기 순서로 보세요.',
      [
        step('.rep-filter', '손익 조회 기간', '미리 정한 기간을 선택하거나 사용자 지정 기간을 입력합니다. 아래 모든 지표와 위험 순위가 이 기간 기준으로 계산됩니다.'),
        step('#repCustomForm', '사용자 지정 기간', '원하는 시작일과 종료일을 직접 지정합니다. 시작일이 종료일보다 늦지 않은지 확인하세요.'),
        step('.rep-metrics', '회사 핵심 지표', '선택 기간의 매출, 실제 투입비, 관리비, 순이익과 위험 현장 수를 요약합니다.', '요약 수치가 크게 변했으면 기간 조건과 원천 자료 누락 여부도 확인하세요.'),
        step('.rep-projects', '위험현장 우선순위', '손실, 높은 원가율, 목표 대비 초과 투입 등 위험 신호가 큰 현장부터 표시합니다.', '순위는 점검 우선순위이며 최종 판단은 현장 상세 자료를 함께 봐야 합니다.'),
        step('[data-project-id]', '현장 손익 상세 열기', '현장 카드를 누르면 계약·매출·비용 구성과 월별 추이를 상세 창에서 불러옵니다.')
      ]
    );
  }

  function analyticsGuide() {
    return guide(
      'CPMS 사용현황 분석',
      '직원별 접속과 메뉴·탭 사용량을 확인해 시스템 활용 상태를 점검하는 화면입니다.',
      '기간·조직 필터 적용 → 오늘 접속 확인 → 직원·메뉴·날짜·부서 순서로 분석하세요.',
      [
        step('[data-usage-filter-form]', '분석 조건', '기간, 부서, 직원과 메뉴 조건을 지정합니다. 이 조건은 오늘 요약을 제외한 아래 분석 자료에 적용됩니다.', '오늘 접속 요약은 선택 기간과 관계없이 오늘 00:00 이후 기준입니다.'),
        step('#today-summary', '오늘 접속 요약', '오늘 접속 직원, 미접속 직원과 계정 미등록 인원을 구분해 보여줍니다.', '미접속과 계정 미등록은 원인이 다르므로 별도로 확인하세요.'),
        step('#employee-usage', '직원별 사용현황', '직원별 마지막 접속, 접속 횟수와 활동량을 비교합니다. 단순 접속과 실제 메뉴 활동을 함께 보세요.'),
        step('#menu-ranking', '메뉴 사용 순위', '선택 기간에 어떤 최상위 메뉴가 많이 사용됐는지 보여줍니다. 메뉴를 선택하면 하위 탭 순위를 확인할 수 있습니다.'),
        step('#tab-ranking', '탭 사용 순위', '선택한 메뉴 안에서 실제로 많이 사용된 하위 기능을 비교합니다.'),
        step('#daily-trend', '날짜별 사용 추이', '날짜별 접속 직원 수, 접속 횟수와 활동 수 변화를 비교합니다.'),
        step('#department-usage', '부서별 사용현황', '부서별 사용자 수와 활동량을 비교해 교육이나 안내가 필요한 조직을 찾습니다.'),
        step('[data-usage-cleanup-form]', '오래된 사용기록 정리', '보존기간이 지난 분석용 기록을 삭제하는 관리자 기능입니다. 조회나 등록 기능이 아닙니다.', '삭제된 분석 기록은 복구하기 어려우므로 기준일과 보존 정책을 확인하세요.')
      ]
    );
  }

  function genericGuide() {
    var titleElement = firstVisible('#cpmsContentHeader h1,#cpmsContentMain h1,#cpmsContentMain h2');
    var title = titleElement ? trimText(titleElement.textContent || titleElement.innerText) : '현재 화면';
    return guide(
      title,
      '이 화면에는 아직 기능을 추측해 강조하는 안내를 넣지 않았습니다. 화면과 다른 설명을 막기 위해 확인된 요소만 안내합니다.',
      '화면 오른쪽 위의 ? 사용법을 다시 누르면 현재 주소와 화면 상태에 맞는 안내를 시작합니다.',
      [
        step(titleElement, '현재 화면 제목', '현재 작업하려는 화면이 맞는지 제목과 상단 정보를 먼저 확인하세요.', '등록·수정·삭제 버튼은 이름과 대상 자료를 확인한 뒤 사용하세요.')
      ]
    );
  }

  function currentGuide() {
    var route = getParam('r');
    var lower = String(route || '').toLowerCase();

    if (lower === '' || lower === 'dashboard_employee') return dashboardGuide(false);
    if (lower === 'dashboard_executive') return dashboardGuide(true);
    if (lower === 'notice' || lower === 'notices') {
      return guide('공지사항', '회사 공지를 읽고 권한이 있는 사용자가 등록·수정·삭제하는 화면입니다.', '새 공지는 등록 버튼, 기존 공지는 제목 또는 관리 아이콘을 사용하세요.', noticeSteps());
    }
    if (lower === 'employees_directory' || lower === 'employee_directory') return employeesGuide();
    if (lower === 'scheduler') return schedulerGuide();
    if (lower.indexOf('tasks/') === 0) return tasksGuide(lower);
    if (lower.indexOf('approval') === 0) return approvalGuide(lower);
    if (route === '공사' || lower === 'construction_home' || lower.indexOf('construction/') === 0) return constructionGuide();
    if (route === '안전/보건' || lower === 'safety_home' || lower.indexOf('safety/') === 0) return safetyGuide();
    if (route === '품질' || lower === 'quality_home' || lower.indexOf('quality/') === 0) return qualityGuide();
    if (lower === 'estimate_home' || lower.indexOf('estimate/') === 0) return estimateGuide();
    if (route === '공무' || lower.indexOf('project') === 0) return projectGuide(lower || route);
    if (route === '관리' || lower.indexOf('admin/') === 0 || lower.indexOf('management/') === 0) return managementGuide();
    if (lower.indexOf('company_profit') === 0) return companyProfitGuide();
    if (lower.indexOf('representative_management') === 0) return representativeGuide();
    if (lower.indexOf('usage_analytics') === 0) return analyticsGuide();
    return genericGuide();
  }

  function targetKey(element) {
    if (!element) return '';
    if (element.id) return 'id:' + element.id;
    var rect = element.getBoundingClientRect();
    return (element.tagName || '') + ':' + Math.round(rect.left) + ':' + Math.round(rect.top) + ':' + Math.round(rect.width) + ':' + Math.round(rect.height);
  }

  function resolveTarget(target) {
    if (!target) return null;
    if (typeof target === 'function') return target();
    if (typeof target === 'string') return firstVisible(target);
    return isVisible(target) ? target : null;
  }

  function pageTitleText() {
    var title = firstVisible('#cpmsContentHeader h1,#cpmsContentMain h1');
    return title ? trimText(title.textContent || title.innerText) : '';
  }

  function buildSteps() {
    var selectedGuide = currentGuide();
    var heading = pageTitleText();
    var displayTitle = selectedGuide.title || heading || '현재 화면';
    var steps = [{
      target: null,
      title: displayTitle + ' 사용법',
      text: selectedGuide.intro,
      hint: selectedGuide.flow
    }];
    var used = {};
    var i;

    for (i = 0; i < selectedGuide.steps.length; i++) {
      var configured = selectedGuide.steps[i];
      var target = resolveTarget(configured.target);
      if (!target) continue;
      var key = targetKey(target);
      if (used[key]) continue;
      used[key] = true;
      steps.push({
        target: target,
        title: configured.title,
        text: configured.text,
        hint: configured.hint
      });
    }

    steps.push({
      target: null,
      title: '안내가 끝났습니다',
      text: displayTitle + ' 화면에서 현재 보이는 기능과 실제 사용 순서를 안내했습니다.\n이제 화면의 버튼 이름과 대상 자료를 확인하면서 업무를 진행하세요.',
      hint: '다시 보고 싶을 때는 ? 사용법 버튼을 누르세요. 화면이나 탭을 이동하면 이동한 화면 전용 안내가 나옵니다.'
    });

    return steps;
  }

  function createElement(tag, className, parent) {
    var element = document.createElement(tag);
    if (className) element.className = className;
    element.setAttribute('data-cpms-guide-ui', '1');
    if (parent) parent.appendChild(element);
    return element;
  }

  function createUi() {
    if (ui.card) return;

    ui.shades = [];
    var i;
    for (i = 0; i < 4; i++) {
      ui.shades[i] = createElement('div', 'cpms-guide-shade', document.body);
      ui.shades[i].addEventListener('click', function () {
        nextStep();
      });
    }

    ui.highlight = createElement('div', 'cpms-guide-highlight', document.body);
    ui.highlight.setAttribute('aria-hidden', 'true');
    ui.highlight.addEventListener('click', function () {
      nextStep();
    });

    ui.card = createElement('section', 'cpms-guide-card', document.body);
    ui.card.setAttribute('role', 'dialog');
    ui.card.setAttribute('aria-modal', 'true');
    ui.card.setAttribute('aria-labelledby', 'cpmsGuideTitle');
    ui.card.setAttribute('aria-describedby', 'cpmsGuideText');

    createElement('div', 'cpms-guide-card__accent', ui.card);
    var body = createElement('div', 'cpms-guide-card__body', ui.card);
    var top = createElement('div', 'cpms-guide-card__top', body);
    var headingWrap = createElement('div', '', top);
    ui.eyebrow = createElement('div', 'cpms-guide-card__eyebrow', headingWrap);
    ui.title = createElement('h2', 'cpms-guide-card__title', headingWrap);
    ui.title.id = 'cpmsGuideTitle';
    ui.close = createElement('button', 'cpms-guide-card__close', top);
    ui.close.type = 'button';
    ui.close.setAttribute('aria-label', '사용법 안내 종료');
    ui.close.innerHTML = '&times;';
    ui.close.addEventListener('click', stopTour);

    ui.text = createElement('div', 'cpms-guide-card__text', body);
    ui.text.id = 'cpmsGuideText';
    ui.hint = createElement('div', 'cpms-guide-card__hint', body);
    ui.hintMark = createElement('span', 'cpms-guide-card__hint-mark', ui.hint);
    ui.hintMark.setAttribute('aria-hidden', 'true');
    ui.hintMark.innerHTML = '!';
    ui.hintText = createElement('span', '', ui.hint);

    var progress = createElement('div', 'cpms-guide-card__progress', body);
    ui.count = createElement('span', 'cpms-guide-card__count', progress);
    var bar = createElement('span', 'cpms-guide-card__bar', progress);
    ui.barValue = createElement('span', 'cpms-guide-card__bar-value', bar);

    var actions = createElement('div', 'cpms-guide-card__actions', body);
    ui.prev = createElement('button', 'cpms-guide-card__button', actions);
    ui.prev.type = 'button';
    ui.prev.innerHTML = '이전';
    ui.prev.addEventListener('click', previousStep);
    ui.next = createElement('button', 'cpms-guide-card__button cpms-guide-card__button--next', actions);
    ui.next.type = 'button';
    ui.next.innerHTML = '다음';
    ui.next.addEventListener('click', nextStep);

    ui.live = createElement('div', 'cpms-guide-live', document.body);
    ui.live.setAttribute('aria-live', 'polite');
  }

  function setRect(element, top, left, width, height) {
    element.style.top = Math.max(0, Math.round(top)) + 'px';
    element.style.left = Math.max(0, Math.round(left)) + 'px';
    element.style.width = Math.max(0, Math.round(width)) + 'px';
    element.style.height = Math.max(0, Math.round(height)) + 'px';
  }

  function showFullShade() {
    setRect(ui.shades[0], 0, 0, window.innerWidth, window.innerHeight);
    setRect(ui.shades[1], 0, 0, 0, 0);
    setRect(ui.shades[2], 0, 0, 0, 0);
    setRect(ui.shades[3], 0, 0, 0, 0);
    ui.highlight.className = 'cpms-guide-highlight';
  }

  function targetRect(target) {
    var rect = target.getBoundingClientRect();
    var pad = 7;
    var left = Math.max(7, rect.left - pad);
    var top = Math.max(7, rect.top - pad);
    var right = Math.min(window.innerWidth - 7, rect.right + pad);
    var bottom = Math.min(window.innerHeight - 7, rect.bottom + pad);
    return {
      left: left,
      top: top,
      right: right,
      bottom: bottom,
      width: Math.max(1, right - left),
      height: Math.max(1, bottom - top)
    };
  }

  function showTargetShade(rect) {
    setRect(ui.shades[0], 0, 0, window.innerWidth, rect.top);
    setRect(ui.shades[1], rect.top, 0, rect.left, rect.height);
    setRect(ui.shades[2], rect.top, rect.right, window.innerWidth - rect.right, rect.height);
    setRect(ui.shades[3], rect.bottom, 0, window.innerWidth, window.innerHeight - rect.bottom);
    setRect(ui.highlight, rect.top, rect.left, rect.width, rect.height);
    ui.highlight.className = 'cpms-guide-highlight is-visible';
  }

  function cardSize() {
    var rect = ui.card.getBoundingClientRect();
    return {
      width: rect.width || 390,
      height: rect.height || 290
    };
  }

  function placeCenteredCard() {
    var size = cardSize();
    var left = Math.max(VIEW_MARGIN, (window.innerWidth - size.width) / 2);
    var top = Math.max(VIEW_MARGIN, (window.innerHeight - size.height) / 2);
    ui.card.style.left = Math.round(left) + 'px';
    ui.card.style.top = Math.round(top) + 'px';
  }

  function placeTargetCard(rect) {
    if (window.innerWidth <= 767) return;
    var size = cardSize();
    var top;
    var left;
    var spaceRight = window.innerWidth - rect.right;
    var spaceLeft = rect.left;
    var spaceBelow = window.innerHeight - rect.bottom;
    var spaceAbove = rect.top;

    if (spaceRight >= size.width + GUIDE_GAP + VIEW_MARGIN) {
      left = rect.right + GUIDE_GAP;
      top = rect.top;
    } else if (spaceLeft >= size.width + GUIDE_GAP + VIEW_MARGIN) {
      left = rect.left - size.width - GUIDE_GAP;
      top = rect.top;
    } else if (spaceBelow >= size.height + GUIDE_GAP + VIEW_MARGIN) {
      left = rect.left + (rect.width - size.width) / 2;
      top = rect.bottom + GUIDE_GAP;
    } else if (spaceAbove >= size.height + GUIDE_GAP + VIEW_MARGIN) {
      left = rect.left + (rect.width - size.width) / 2;
      top = rect.top - size.height - GUIDE_GAP;
    } else {
      left = window.innerWidth - size.width - VIEW_MARGIN;
      top = window.innerHeight - size.height - VIEW_MARGIN;
    }

    left = Math.max(VIEW_MARGIN, Math.min(left, window.innerWidth - size.width - VIEW_MARGIN));
    top = Math.max(VIEW_MARGIN, Math.min(top, window.innerHeight - size.height - VIEW_MARGIN));
    ui.card.style.left = Math.round(left) + 'px';
    ui.card.style.top = Math.round(top) + 'px';
  }

  function renderPosition() {
    if (!state.active || !ui.card) return;
    if (!state.target || !isVisible(state.target)) {
      showFullShade();
      placeCenteredCard();
      return;
    }
    var rect = targetRect(state.target);
    showTargetShade(rect);
    placeTargetCard(rect);
  }

  function scrollTargetIntoView(target, callback) {
    if (!target || !target.getBoundingClientRect) {
      callback();
      return;
    }
    var rect = target.getBoundingClientRect();
    var safeTop = window.innerWidth <= 767 ? 74 : 18;
    var safeBottom = window.innerWidth <= 767 ? 210 : 18;
    var visible = rect.top >= safeTop && rect.bottom <= window.innerHeight - safeBottom;
    if (visible) {
      callback();
      return;
    }
    try {
      target.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
    } catch (e) {
      try {
        target.scrollIntoView(false);
      } catch (ignore) {}
    }
    if (state.scrollTimer) window.clearTimeout(state.scrollTimer);
    state.scrollTimer = window.setTimeout(callback, 330);
  }

  function renderStep() {
    if (!state.active || !state.steps.length) return;
    var current = state.steps[state.index];
    state.target = current.target && isVisible(current.target) ? current.target : null;

    ui.eyebrow.innerHTML = 'CPMS 화면 안내';
    ui.title.textContent = current.title;
    ui.text.textContent = current.text;
    ui.hintText.textContent = current.hint || '강조된 영역의 실제 이름과 설명을 확인한 뒤 다음 단계로 이동하세요.';
    ui.count.textContent = (state.index + 1) + ' / ' + state.steps.length;
    ui.barValue.style.width = Math.round(((state.index + 1) / state.steps.length) * 100) + '%';
    ui.prev.disabled = state.index === 0;
    ui.next.textContent = state.index === state.steps.length - 1 ? '완료' : '다음';
    ui.card.className = 'cpms-guide-card is-visible' + (state.target ? '' : ' is-centered');
    ui.live.textContent = current.title + '. ' + current.text;

    scrollTargetIntoView(state.target, function () {
      if (!state.active) return;
      renderPosition();
      try {
        ui.next.focus();
      } catch (e) {}
    });
  }

  function startTour() {
    createUi();
    if (state.active) stopTour();
    state.steps = buildSteps();
    if (!state.steps.length) return;
    state.active = true;
    state.index = 0;
    state.previousFocus = document.activeElement;
    document.body.className = document.body.className.replace(/\bcpms-guide-active\b/g, '').replace(/\s+/g, ' ').replace(/^\s+|\s+$/g, '') + ' cpms-guide-active';
    var i;
    for (i = 0; i < ui.shades.length; i++) ui.shades[i].className = 'cpms-guide-shade is-visible';
    renderStep();
  }

  function stopTour() {
    if (!ui.card) return;
    state.active = false;
    state.target = null;
    if (state.scrollTimer) {
      window.clearTimeout(state.scrollTimer);
      state.scrollTimer = null;
    }
    document.body.className = document.body.className.replace(/\bcpms-guide-active\b/g, '').replace(/\s+/g, ' ').replace(/^\s+|\s+$/g, '');
    var i;
    for (i = 0; i < ui.shades.length; i++) ui.shades[i].className = 'cpms-guide-shade';
    ui.highlight.className = 'cpms-guide-highlight';
    ui.card.className = 'cpms-guide-card';
    if (state.previousFocus && state.previousFocus.focus) {
      try {
        state.previousFocus.focus();
      } catch (e) {}
    }
  }

  function nextStep() {
    if (!state.active) return;
    if (state.index >= state.steps.length - 1) {
      stopTour();
      return;
    }
    state.index++;
    renderStep();
  }

  function previousStep() {
    if (!state.active || state.index <= 0) return;
    state.index--;
    renderStep();
  }

  function onKeyDown(event) {
    if (!state.active) return;
    var key = event.key || event.keyCode;
    if (key === 'Escape' || key === 'Esc' || key === 27) {
      event.preventDefault();
      stopTour();
    } else if (key === 'ArrowRight' || key === 39 || key === 'Enter' || key === 13) {
      if (event.target && /input|textarea|select/i.test(event.target.tagName || '')) return;
      event.preventDefault();
      nextStep();
    } else if (key === 'ArrowLeft' || key === 37) {
      event.preventDefault();
      previousStep();
    } else if (key === 'Tab') {
      var focusables = [ui.close, ui.prev, ui.next];
      var current = focusables.indexOf ? focusables.indexOf(document.activeElement) : -1;
      if (event.shiftKey && current <= 0) {
        event.preventDefault();
        ui.next.focus();
      } else if (!event.shiftKey && current === focusables.length - 1) {
        event.preventDefault();
        ui.close.focus();
      }
    }
  }

  function inspectTour() {
    var steps = buildSteps();
    var output = [];
    var i;
    for (i = 0; i < steps.length; i++) {
      output.push({
        number: i + 1,
        title: steps[i].title,
        target: steps[i].target
          ? (steps[i].target.id ? '#' + steps[i].target.id : String(steps[i].target.tagName || '').toLowerCase())
          : '화면 중앙'
      });
    }
    return {
      route: getParam('r'),
      tab: getParam('tab'),
      steps: output
    };
  }

  function bind() {
    var start = document.getElementById('cpmsGuideTourStart');
    if (!start) return;
    start.addEventListener('click', startTour);
    document.addEventListener('keydown', onKeyDown);
    window.addEventListener('resize', renderPosition);
    window.addEventListener('scroll', renderPosition, true);
    window.cpmsGuideTour = {
      start: startTour,
      stop: stopTour,
      next: nextStep,
      previous: previousStep,
      inspect: inspectTour
    };
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bind);
  } else {
    bind();
  }
})();
