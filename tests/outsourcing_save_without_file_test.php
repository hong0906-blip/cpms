<?php
/**
 * Outsourcing cost save regression guards.
 * PHP 5.6 compatible and DB-independent.
 */

$root = dirname(__DIR__);
$failures = array();
$checks = 0;

function cpms_outsourcing_save_guard($label, $condition)
{
    global $failures, $checks;
    $checks++;
    if (!$condition) $failures[] = $label;
}

$tabSource = file_get_contents($root . '/app/views/construction/tabs/outsourcing.php');
$saveSource = file_get_contents($root . '/app/views/construction/outsourcing_cost_save.php');
$dataSource = file_get_contents($root . '/app/views/construction/tabs/partials/outsourcing_data_helper.php');

cpms_outsourcing_save_guard(
    'all saves use the normal multipart form POST',
    strpos($tabSource, 'name="attachments[]"') !== false
        && strpos($tabSource, "form.addEventListener('submit',function(){") !== false
        && strpos($tabSource, "fd.append('ajax','1')") === false
);
cpms_outsourcing_save_guard(
    'drag and drop adds files to the real attachment input',
    strpos($tabSource, "dropZone.addEventListener('drop'") !== false
        && strpos($tabSource, 'picker.files=transfer.files') !== false
);
cpms_outsourcing_save_guard(
    'attachment upload is optional on the server',
    strpos($saveSource, "if (isset(\$_FILES['attachments']))") !== false
);
cpms_outsourcing_save_guard(
    'cost schema does not eagerly prepare attachment schema',
    strpos($dataSource, "cpms_outsourcing_file_ensure_schema(\$pdo);") === false
);
cpms_outsourcing_save_guard(
    'memo binding follows optional schema availability',
    strpos($saveSource, "if (\$outsourcingHasMemoColumn) \$st->bindValue(':memo'") !== false
);
cpms_outsourcing_save_guard(
    'advance payment N/Y is saved and exposed in the input form',
    strpos($tabSource, 'name="advance_payment_yn"') !== false
        && strpos($saveSource, "\$st->bindValue(':advance_payment_yn',\$advancePaymentYn)") !== false
        && strpos($dataSource, 'advance_payment_yn CHAR(1)') !== false
);
cpms_outsourcing_save_guard(
    'monthly outsourcing table exposes attachment links and advance payment',
    strpos($tabSource, '$monthlyFiles=isset($outsourcingFilesByCost[$monthlyRid])') !== false
        && substr_count($tabSource, '>선급여부<') >= 2
);

if (count($failures) > 0) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . ' / ' . $checks . "\n");
    foreach ($failures as $failure) fwrite(STDERR, '- ' . $failure . "\n");
    exit(1);
}

echo 'PASS: ' . $checks . " outsourcing no-file save guards\n";
