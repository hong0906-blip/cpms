'use strict';

var assert = require('assert');
var fs = require('fs');
var path = require('path');
var root = path.resolve(__dirname, '..');
var money = require(path.join(root, 'public/assets/js/money-input.js'));

function fakeInput(name, type, attributes) {
  attributes = attributes || {};
  attributes.name = name || '';
  attributes.type = type || 'text';
  return {
    tagName: 'INPUT',
    name: name || '',
    type: type || 'text',
    value: attributes.value || '',
    className: attributes.className || '',
    getAttribute: function (key) {
      return Object.prototype.hasOwnProperty.call(attributes, key) ? String(attributes[key]) : null;
    },
    hasAttribute: function (key) {
      return Object.prototype.hasOwnProperty.call(attributes, key);
    },
    setAttribute: function (key, value) {
      attributes[key] = String(value);
    },
    setCustomValidity: function (message) {
      this.validationMessage = message;
    }
  };
}

assert.strictEqual(money.formatValue('1000000'), '1,000,000');
assert.strictEqual(money.formatValue('1,234,567.89'), '1,234,567.89');
assert.strictEqual(money.formatValue('-250000'), '-250,000');
assert.strictEqual(money.rawValue('1,000,000'), '1000000');
assert.strictEqual(money.formatWonText('계약금액 1000000원'), '계약금액 1,000,000원');
assert.strictEqual(money.formatWonText('증감 +250000원'), '증감 +250,000원');
assert.strictEqual(money.formatMoneyHint('예: 1000000'), '예: 1,000,000');
assert.strictEqual(money.formatMoneyHint('2026년 예산 1000000'), '2026년 예산 1,000,000');
assert.strictEqual(money.isMoneyHeader('확정매출'), true);
assert.strictEqual(money.isMoneyHeader('원가율'), false);
assert.strictEqual(money.isMoneyHeader('수량'), false);

[
  'amount',
  'contract_amount',
  'recognized_amount',
  'items[0][unit_price]',
  'daily_wage',
  'monthly_regular_wage',
  'deposit_rate',
  'base_rate',
  'maintenance_fee',
  'monthly_payment',
  'manual_total_amount'
].forEach(function (name) {
  assert.strictEqual(money.isMoneyInput(fakeInput(name)), true, name + ' should be money');
});

[
  'target_rate',
  'min_completion_rate',
  'payment_method',
  'payment_due',
  'cost_date',
  'plan_total_days',
  'leave_annual_balance',
  'selected_unit_price_ids[]'
].forEach(function (name) {
  assert.strictEqual(money.isMoneyInput(fakeInput(name)), false, name + ' should not be money');
});

assert.strictEqual(money.isMoneyInput(fakeInput('amount', 'hidden')), false);
assert.strictEqual(money.isMoneyInput(fakeInput('base_rate', 'text', {'data-cpms-money': 'off'})), false);
assert.strictEqual(money.isMoneyInput(fakeInput('custom_value', 'text', {'data-cpms-money': '1'})), true);

var editableAmount = fakeInput('amount', 'number', {value: '1000000', min: '0'});
assert.strictEqual(money.prepareInput(editableAmount), true);
assert.strictEqual(editableAmount.type, 'text');
assert.strictEqual(editableAmount.value, '1,000,000');
var stripped = money.stripForm({querySelectorAll: function () { return [editableAmount]; }});
assert.strictEqual(stripped.valid, true);
assert.strictEqual(editableAmount.value, '1000000');
money.restoreEntries(stripped);
assert.strictEqual(editableAmount.value, '1,000,000');

var header = fs.readFileSync(path.join(root, 'app/views/layout/header.php'), 'utf8');
var app = fs.readFileSync(path.join(root, 'public/assets/js/app.js'), 'utf8');
assert.ok(header.indexOf("assets/js/money-input.js") !== -1, 'common layout must load money formatter');
assert.ok(app.indexOf('window.CPMSMoneyInput.stripForm(form)') !== -1, 'delayed native submit must strip commas');

console.log('PASS: global money input formatting checks');
