<?php
/**
 * Construction labor outsourcing-worker registration regression guards.
 * PHP 5.6 compatible and DB-independent.
 */

$root = dirname(__DIR__);
$failures = array();
$checks = 0;

function cpms_outsourcing_worker_guard($label, $condition)
{
    global $failures, $checks;
    $checks++;
    if (!$condition) $failures[] = $label;
}

$tabSource = file_get_contents($root . '/app/views/construction/tabs/labor.php');
$saveSource = file_get_contents($root . '/app/views/construction/labor_workforce_save.php');
$formStart = strpos($tabSource, 'data-construction-workforce-register');
$formEnd = $formStart !== false ? strpos($tabSource, '</form>', $formStart) : false;
$registrationForm = ($formStart !== false && $formEnd !== false) ? substr($tabSource, $formStart, $formEnd - $formStart) : '';

cpms_outsourcing_worker_guard(
    'registration form exposes the outsourcing-worker checkbox and guidance',
    strpos($tabSource, 'name="is_outsourcing"') !== false
        && strpos($tabSource, '>외주비인원<') !== false
        && strpos($tabSource, '임금단가, 주민번호, 은행명, 계좌번호, 예금주는 입력하지 않아도 됩니다.') !== false
);
cpms_outsourcing_worker_guard(
    'exactly five requested fields are marked as outsourcing-optional',
    substr_count($registrationForm, 'data-outsourcing-optional-field') === 5
        && strpos($registrationForm, 'name="resident_no"') !== false
        && strpos($registrationForm, 'name="daily_wage"') !== false
        && strpos($registrationForm, 'name="bank_name"') !== false
        && strpos($registrationForm, 'name="bank_account"') !== false
        && strpos($registrationForm, 'name="account_holder"') !== false
);
cpms_outsourcing_worker_guard(
    'client validation toggles required fields and the wage minimum',
    strpos($tabSource, "fields[i].required = !isOutsourcing") !== false
        && strpos($tabSource, "fields[i].min = isOutsourcing ? '0'") !== false
        && strpos($tabSource, "checkbox.addEventListener('change', syncOutsourcingFields)") !== false
);
cpms_outsourcing_worker_guard(
    'server recognizes only an explicit outsourcing checkbox value',
    strpos($saveSource, "isset(\$_POST['is_outsourcing'])") !== false
        && strpos($saveSource, "(string)\$_POST['is_outsourcing'] === '1'") !== false
);
cpms_outsourcing_worker_guard(
    'server skips only the five requested required fields for outsourcing workers',
    strpos($saveSource, "'resident_no' => true") !== false
        && strpos($saveSource, "'daily_wage' => true") !== false
        && strpos($saveSource, "'bank_name' => true") !== false
        && strpos($saveSource, "'bank_account' => true") !== false
        && strpos($saveSource, "'account_holder' => true") !== false
        && strpos($saveSource, 'if ($isOutsourcingWorker && isset($outsourcingOptionalFields[$field])) continue;') !== false
);
cpms_outsourcing_worker_guard(
    'outsourcing workers may submit a blank or zero wage while malformed wages still fail',
    strpos($saveSource, '!$isDevelopmentDepartment && !$isOutsourcingWorker && (int)$wageDigits <= 0') !== false
        && strpos($saveSource, "!preg_match('/^\\d+$/', \$wageDigits)") !== false
);
cpms_outsourcing_worker_guard(
    'changed PHP files remain compatible with PHP 5.6 syntax',
    strpos($tabSource, '??') === false && strpos($saveSource, '??') === false
);

if (count($failures) > 0) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . ' / ' . $checks . "\n");
    foreach ($failures as $failure) fwrite(STDERR, '- ' . $failure . "\n");
    exit(1);
}

echo 'PASS: ' . $checks . " outsourcing-worker registration guards\n";
