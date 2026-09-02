/*
 * CPMS 공통 금액 입력 포맷터.
 * - 모든 화면에서 금액을 입력하는 즉시 천 단위 쉼표를 표시한다.
 * - 폼 전송 직전에는 쉼표를 제거해 기존 PHP 저장 로직과 호환한다.
 * - ES5 문법만 사용해 구형 브라우저와 PHP 5.6 기반 화면에서도 동작한다.
 */
(function (root, factory) {
  var api = factory(root, root && root.document ? root.document : null);
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root) root.CPMSMoneyInput = api;
  if (root && root.document) api.bind();
})(typeof window !== 'undefined' ? window : null, function (root, document) {
  var activeAttribute = 'data-cpms-money-active';
  var originalTypeAttribute = 'data-cpms-money-original-type';

  function attribute(element, name) {
    if (!element || !element.getAttribute) return '';
    var value = element.getAttribute(name);
    return value === null ? '' : String(value);
  }

  function hasAttribute(element, name) {
    if (!element) return false;
    if (element.hasAttribute) return element.hasAttribute(name);
    return element.getAttribute ? element.getAttribute(name) !== null : false;
  }

  function hasClass(element, className) {
    var classes = ' ' + String(element && element.className ? element.className : '') + ' ';
    return classes.indexOf(' ' + className + ' ') !== -1;
  }

  function inputType(input) {
    return String(attribute(input, 'type') || input.type || 'text').toLowerCase();
  }

  function explicitMoneyInput(input) {
    if (hasAttribute(input, 'data-money-input')) return true;
    if (hasAttribute(input, 'data-material-money')) return true;
    if (hasAttribute(input, 'data-equipment-money')) return true;
    if (hasAttribute(input, 'data-cpms-money') && attribute(input, 'data-cpms-money').toLowerCase() !== 'off') return true;
    if (hasClass(input, 'js-proposal-money-input')) return true;
    return false;
  }

  function moneyHint(input) {
    var hint = [
      attribute(input, 'placeholder'),
      attribute(input, 'title'),
      attribute(input, 'aria-label')
    ].join(' ');
    if (input && input.closest) {
      var label = input.closest('label');
      if (label && label.textContent) hint += ' ' + label.textContent;
    }
    return hint;
  }

  function isMoneyInput(input) {
    if (!input || String(input.tagName || '').toUpperCase() !== 'INPUT') return false;
    if (hasAttribute(input, 'data-no-money-format')) return false;
    if (attribute(input, 'data-cpms-money').toLowerCase() === 'off') return false;

    var type = inputType(input);
    if (type !== 'text' && type !== 'number' && type !== 'tel' && type !== 'search') return false;
    if (explicitMoneyInput(input)) return true;

    var name = String(attribute(input, 'name') || input.name || '').toLowerCase();
    if (name === '') {
      var unnamedHint = moneyHint(input);
      return !/(원가율|비율|퍼센트|%|일수|수량|개수|공수)/.test(unnamedHint)
        && /(금액|단가|임금|급여|월급|보증금|매출|매입|비용|원가|가액|예산)/.test(unnamedHint);
    }

    if (name === 'base_rate' || name === 'deposit_rate' || /(?:hourly|daily|wage|labor)_rate/.test(name)) return true;
    if (name === 'payment_method' || name === 'payment_due' || /^leave_/.test(name)) return false;
    if (/(?:^|_|\[)(?:id|ids|type|method|date|year|month|day|days|count|qty|quantity|rate|ratio|percent|percentage|eligible|token|file|name|yn)(?:$|_|\[|\])/.test(name)) return false;

    if (/(amount|price|cost|salary|wage|budget|revenue|sales|fee|payment|deposit|allowance|bonus|premium|principal|income|expense|deduction|tax|insurance|rent|balance|subtotal|grand_total|total)/.test(name)) return true;

    var hint = moneyHint(input);
    if (/(원가율|비율|퍼센트|%|일수|수량|개수|공수)/.test(hint)) return false;
    return /(금액|단가|임금|급여|월급|보증금|매출|매입|비용|원가|가액|예산)/.test(hint);
  }

  function moneyParts(value, allowNegative) {
    var text = String(value === null || typeof value === 'undefined' ? '' : value);
    text = text.replace(/,/g, '').replace(/\s+/g, '');
    var negative = allowNegative && text.charAt(0) === '-';
    text = text.replace(/-/g, '').replace(/[^0-9.]/g, '');

    if (text === '') {
      return {
        raw: negative ? '-' : '',
        formatted: negative ? '-' : ''
      };
    }

    var dotIndex = text.indexOf('.');
    var hasDecimalPoint = dotIndex !== -1;
    var integerPart = hasDecimalPoint ? text.substring(0, dotIndex) : text;
    var decimalPart = hasDecimalPoint ? text.substring(dotIndex + 1).replace(/\./g, '') : '';
    integerPart = integerPart.replace(/^0+(?=\d)/, '');
    if (integerPart === '') integerPart = '0';

    var sign = negative ? '-' : '';
    var decimalText = hasDecimalPoint ? '.' + decimalPart : '';
    return {
      raw: sign + integerPart + decimalText,
      formatted: sign + integerPart.replace(/\B(?=(\d{3})+(?!\d))/g, ',') + decimalText
    };
  }

  function allowsNegative(input) {
    var min = attribute(input, 'min');
    if (min !== '' && !isNaN(parseFloat(min)) && parseFloat(min) >= 0) return false;
    return true;
  }

  function rawValue(value, allowNegative) {
    return moneyParts(value, allowNegative !== false).raw;
  }

  function formatValue(value, allowNegative) {
    return moneyParts(value, allowNegative !== false).formatted;
  }

  function formatWonText(value) {
    return String(value === null || typeof value === 'undefined' ? '' : value).replace(/([+-]?\d[\d,]*(?:\.\d+)?)\s*원/g, function (matched, numberText) {
      var plus = numberText.charAt(0) === '+' ? '+' : '';
      if (plus) numberText = numberText.substring(1);
      return plus + formatValue(numberText, true) + '원';
    });
  }

  function formatMoneyHint(value) {
    var fullText = String(value || '');
    return fullText.replace(/([+-]?\d[\d,]*(?:\.\d+)?)/g, function (matched, captured, offset) {
      var digitCount = matched.replace(/[^0-9]/g, '').length;
      if (digitCount < 4) return matched;
      var plainNumber = parseInt(matched.replace(/[^0-9]/g, ''), 10);
      if (digitCount === 4 && plainNumber >= 1900 && plainNumber <= 2100 && fullText.charAt(offset + matched.length) === '년') return matched;
      var plus = matched.charAt(0) === '+' ? '+' : '';
      var numberText = plus ? matched.substring(1) : matched;
      return plus + formatValue(numberText, true);
    });
  }

  function isMoneyHeader(value) {
    var text = String(value || '').replace(/\s+/g, ' ').replace(/^\s+|\s+$/g, '');
    if (text === '' || text === '월') return false;
    if (/(원가율|비율|퍼센트|%|수량|일수|공수|건수|번호|연도|차수)/.test(text)) return false;
    return /(금액|단가|매출|매입|원가|급여|임금|지급|공제|보증금|예산|비용|합계|수익|손익|잔액|계약액|청구액|보험료|월급)/.test(text);
  }

  function formatPlainMoneyElement(element) {
    if (!element || typeof element.textContent === 'undefined') return false;
    if (String(element.tagName || '').toUpperCase() === 'SCRIPT' || String(element.tagName || '').toUpperCase() === 'STYLE') return false;
    if (element.children && element.children.length > 0) return false;
    var text = String(element.textContent || '');
    var match = text.match(/^(\s*)([+-]?\d[\d,]*(?:\.\d+)?)(\s*원)?(\s*)$/);
    if (!match) return false;
    var plus = match[2].charAt(0) === '+' ? '+' : '';
    var numberText = plus ? match[2].substring(1) : match[2];
    var nextText = match[1] + plus + formatValue(numberText, true) + (match[3] || '') + match[4];
    if (nextText !== text) {
      if (element.childNodes && element.childNodes.length === 1 && element.childNodes[0].nodeType === 3) {
        element.childNodes[0].nodeValue = nextText;
      } else {
        element.textContent = nextText;
      }
    }
    return true;
  }

  function formatMoneyCell(cell) {
    if (!cell) return;
    if (formatPlainMoneyElement(cell)) return;
    if (!cell.querySelectorAll) return;
    var leaves = cell.querySelectorAll('span, strong, b, div, p');
    for (var leafIndex = 0; leafIndex < leaves.length; leafIndex++) formatPlainMoneyElement(leaves[leafIndex]);
  }

  function formatMoneyTable(table) {
    if (!table || !table.querySelectorAll) return;
    var headerRows = table.querySelectorAll('thead tr');
    if (!headerRows.length) return;
    var headerRow = headerRows[headerRows.length - 1];
    var headerCells = headerRow.cells || headerRow.querySelectorAll('th, td');
    var moneyColumns = {};
    for (var headerIndex = 0; headerIndex < headerCells.length; headerIndex++) {
      if (isMoneyHeader(headerCells[headerIndex].textContent)) moneyColumns[headerIndex] = true;
    }
    var rows = table.querySelectorAll('tbody tr, tfoot tr');
    for (var rowIndex = 0; rowIndex < rows.length; rowIndex++) {
      var cells = rows[rowIndex].cells || rows[rowIndex].querySelectorAll('th, td');
      for (var cellIndex = 0; cellIndex < cells.length; cellIndex++) {
        if (moneyColumns[cellIndex]) formatMoneyCell(cells[cellIndex]);
      }
    }
  }

  function formatWonTextNodes(node) {
    if (!document || !node) return;
    if (node.nodeType === 3) {
      var parentTag = String(node.parentNode && node.parentNode.tagName ? node.parentNode.tagName : '').toUpperCase();
      if (parentTag !== 'SCRIPT' && parentTag !== 'STYLE' && parentTag !== 'TEXTAREA' && parentTag !== 'OPTION') {
        var formattedText = formatWonText(node.nodeValue);
        if (formattedText !== node.nodeValue) node.nodeValue = formattedText;
      }
      return;
    }
    if (!document.createTreeWalker) return;
    var showText = root.NodeFilter ? root.NodeFilter.SHOW_TEXT : 4;
    var walker = document.createTreeWalker(node, showText, null, false);
    var textNode;
    while ((textNode = walker.nextNode())) {
      var tag = String(textNode.parentNode && textNode.parentNode.tagName ? textNode.parentNode.tagName : '').toUpperCase();
      if (tag === 'SCRIPT' || tag === 'STYLE' || tag === 'TEXTAREA' || tag === 'OPTION') continue;
      var nextText = formatWonText(textNode.nodeValue);
      if (nextText !== textNode.nodeValue) textNode.nodeValue = nextText;
    }
  }

  function formatDisplayTree(node) {
    if (!document || !node) return;
    formatWonTextNodes(node);
    var element = (node.nodeType === 1 || node.nodeType === 9) ? node : node.parentNode;
    if (!element) return;

    if (element.matches && element.matches('[data-cpms-money-display], .cpms-money, .money, .amount')) {
      formatPlainMoneyElement(element);
    }
    if (element.querySelectorAll) {
      var explicit = element.querySelectorAll('[data-cpms-money-display], .cpms-money, .money, .amount');
      for (var explicitIndex = 0; explicitIndex < explicit.length; explicitIndex++) formatPlainMoneyElement(explicit[explicitIndex]);
    }

    var closestTable = element.closest ? element.closest('table') : null;
    if (closestTable) formatMoneyTable(closestTable);
    if (String(element.tagName || '').toUpperCase() === 'TABLE') formatMoneyTable(element);
    if (element.querySelectorAll) {
      var tables = element.querySelectorAll('table');
      for (var tableIndex = 0; tableIndex < tables.length; tableIndex++) formatMoneyTable(tables[tableIndex]);
    }
  }

  function caretFromDigitsRight(value, digitsRight) {
    var count = 0;
    var index;
    for (index = value.length; index > 0; index--) {
      if (/\d/.test(value.charAt(index - 1))) count++;
      if (count > digitsRight) return index;
    }
    return 0;
  }

  function validateInput(input) {
    if (!input || !input.setCustomValidity) return true;
    var raw = rawValue(input.value, allowsNegative(input));
    var validNumber = raw === '' || /^-?\d+(?:\.\d*)?$/.test(raw);
    var message = validNumber ? '' : '금액을 숫자로 입력해주세요.';
    var number = validNumber && raw !== '' ? parseFloat(raw) : null;
    var min = attribute(input, 'min');
    var max = attribute(input, 'max');
    if (message === '' && number !== null && min !== '' && !isNaN(parseFloat(min)) && number < parseFloat(min)) {
      message = '금액은 ' + min + ' 이상 입력해주세요.';
    }
    if (message === '' && number !== null && max !== '' && !isNaN(parseFloat(max)) && number > parseFloat(max)) {
      message = '금액은 ' + max + ' 이하로 입력해주세요.';
    }
    input.setCustomValidity(message);
    return message === '';
  }

  function formatInput(input, preserveCaret) {
    if (!input || !isMoneyInput(input) || input._cpmsMoneyComposing) return false;
    var current = String(input.value || '');
    var selectionStart = typeof input.selectionStart === 'number' ? input.selectionStart : current.length;
    var digitsRight = current.substring(selectionStart).replace(/[^0-9]/g, '').length;
    var formatted = formatValue(current, allowsNegative(input));
    if (input.value !== formatted) input.value = formatted;
    validateInput(input);
    if (preserveCaret && document && document.activeElement === input && input.setSelectionRange) {
      var nextCaret = caretFromDigitsRight(formatted, digitsRight);
      try { input.setSelectionRange(nextCaret, nextCaret); } catch (ignore) {}
    }
    return true;
  }

  function prepareInput(input) {
    if (!isMoneyInput(input)) return false;
    if (!hasAttribute(input, originalTypeAttribute)) {
      input.setAttribute(originalTypeAttribute, inputType(input));
    }
    if (inputType(input) === 'number') {
      try { input.type = 'text'; } catch (ignore) { input.setAttribute('type', 'text'); }
    }
    input.setAttribute(activeAttribute, '1');
    input.setAttribute('inputmode', 'decimal');
    var placeholder = attribute(input, 'placeholder');
    if (placeholder !== '') input.setAttribute('placeholder', formatMoneyHint(placeholder));
    formatInput(input, false);
    return true;
  }

  function prepareTree(node) {
    if (!node) return;
    if (String(node.tagName || '').toUpperCase() === 'INPUT') prepareInput(node);
    if (!node.querySelectorAll) return;
    var inputs = node.querySelectorAll('input');
    for (var index = 0; index < inputs.length; index++) prepareInput(inputs[index]);
  }

  function activeMoneyInputs(form) {
    if (!form || !form.querySelectorAll) return [];
    var all = form.querySelectorAll('input');
    var result = [];
    for (var index = 0; index < all.length; index++) {
      if (prepareInput(all[index])) result.push(all[index]);
    }
    return result;
  }

  function stripForm(form) {
    var inputs = activeMoneyInputs(form);
    var entries = [];
    var valid = true;
    for (var index = 0; index < inputs.length; index++) {
      var input = inputs[index];
      entries.push({input: input, formatted: input.value});
      input.value = rawValue(input.value, allowsNegative(input));
      if (!validateInput(input)) valid = false;
    }
    entries.valid = valid;
    return entries;
  }

  function restoreEntries(entries) {
    if (!entries) return;
    for (var index = 0; index < entries.length; index++) {
      var input = entries[index] && entries[index].input ? entries[index].input : null;
      if (input) formatInput(input, false);
    }
  }

  function bind() {
    if (!document || document._cpmsMoneyInputBound) return;
    document._cpmsMoneyInputBound = true;
    prepareTree(document);
    formatDisplayTree(document);

    document.addEventListener('compositionstart', function (event) {
      if (isMoneyInput(event.target)) event.target._cpmsMoneyComposing = true;
    }, false);
    document.addEventListener('compositionend', function (event) {
      if (!event.target) return;
      event.target._cpmsMoneyComposing = false;
      prepareInput(event.target);
      formatInput(event.target, true);
    }, false);
    document.addEventListener('input', function (event) {
      if (!event.target) return;
      prepareInput(event.target);
      formatInput(event.target, true);
    }, false);
    document.addEventListener('focusin', function (event) {
      if (event.target) prepareInput(event.target);
    }, false);
    document.addEventListener('blur', function (event) {
      if (event.target && isMoneyInput(event.target)) formatInput(event.target, false);
    }, true);
    document.addEventListener('submit', function (event) {
      var form = event.target;
      if (!form || String(form.tagName || '').toUpperCase() !== 'FORM') return;
      var entries = stripForm(form);
      if (!entries.valid) {
        event.preventDefault();
        if (form.reportValidity) form.reportValidity();
      }
      root.setTimeout(function () { restoreEntries(entries); }, 0);
    }, true);

    if (root.MutationObserver && document.documentElement) {
      var observer = new root.MutationObserver(function (mutations) {
        for (var mutationIndex = 0; mutationIndex < mutations.length; mutationIndex++) {
          var nodes = mutations[mutationIndex].addedNodes || [];
          for (var nodeIndex = 0; nodeIndex < nodes.length; nodeIndex++) {
            prepareTree(nodes[nodeIndex]);
            formatDisplayTree(nodes[nodeIndex]);
          }
        }
      });
      observer.observe(document.documentElement, {childList: true, subtree: true});
    }
  }

  return {
    bind: bind,
    isMoneyInput: isMoneyInput,
    formatValue: formatValue,
    formatWonText: formatWonText,
    formatMoneyHint: formatMoneyHint,
    isMoneyHeader: isMoneyHeader,
    rawValue: rawValue,
    formatInput: formatInput,
    prepareInput: prepareInput,
    prepareTree: prepareTree,
    formatDisplayTree: formatDisplayTree,
    stripForm: stripForm,
    restoreEntries: restoreEntries
  };
});
