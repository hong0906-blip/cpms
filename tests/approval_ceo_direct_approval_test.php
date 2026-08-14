<?php

$root = dirname(__DIR__);
require_once $root . '/app/views/approval/_common.php';
require_once $root . '/app/views/approval/line_rules.php';

if (!function_exists('h')) {
    function h($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('approval_sign_path_by_email')) {
    function approval_sign_path_by_email($email)
    {
        return '';
    }
}
require_once $root . '/app/views/approval/document_templates.php';

class ApprovalCeoDirectFakeStatement
{
    private $pdo;
    private $sql;

    public function __construct($pdo, $sql)
    {
        $this->pdo = $pdo;
        $this->sql = $sql;
    }

    public function execute($params)
    {
        $this->pdo->executions[] = array('sql' => $this->sql, 'params' => $params);
        if (stripos($this->sql, 'INSERT INTO cpms_approval_lines') !== false) {
            $this->pdo->lastInsertIdValue++;
        }
        return true;
    }

    public function fetchAll($mode)
    {
        if (stripos($this->sql, 'SHOW COLUMNS FROM') !== false) {
            $rows = array();
            for ($i = 0; $i < count($this->pdo->columns); $i++) {
                $rows[] = array('Field' => $this->pdo->columns[$i]);
            }
            return $rows;
        }
        return $this->pdo->lines;
    }

    public function fetchColumn()
    {
        if (stripos($this->sql, 'SELECT DATABASE()') !== false) return 'test_database';
        if (stripos($this->sql, 'information_schema.TABLES') !== false) return 1;
        return null;
    }
}

class ApprovalCeoDirectFakePdo
{
    public $lines = array();
    public $executions = array();
    public $columns = array(
        'id', 'document_id', 'line_order', 'role_type', 'approver_id',
        'approver_name', 'approver_email', 'line_status', 'acted_at',
        'sign_path', 'reject_reason', 'is_delegated', 'delegated_by_role'
    );
    public $lastInsertIdValue = 1000;

    public function prepare($sql)
    {
        return new ApprovalCeoDirectFakeStatement($this, $sql);
    }

    public function query($sql)
    {
        return new ApprovalCeoDirectFakeStatement($this, $sql);
    }

    public function lastInsertId()
    {
        return $this->lastInsertIdValue;
    }
}

function approval_ceo_test_assert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: " . $message . PHP_EOL);
        exit(1);
    }
}

$ceoUser = array('id' => 103, 'name' => 'CEO', 'email' => 'ceo@example.com', 'position' => approval_ko('%EB%8C%80%ED%91%9C%EC%9D%B4%EC%82%AC'));
approval_ceo_test_assert(approval_is_ceo_user(null, $ceoUser), 'CEO authority should be recognized from the employee position');
approval_ceo_test_assert(approval_can_view_all_active_documents(null, $ceoUser), 'CEO should be able to open every active approval document');

$duplicateLines = array();
$duplicateSeen = array();
$sameExecutive = array('id' => 103, 'name' => 'CEO', 'email' => 'ceo@example.com');
approval_line_rules_add_line($duplicateLines, $duplicateSeen, approval_ko('%EA%B4%80%EB%A6%AC'), $sameExecutive, array());
approval_line_rules_add_line($duplicateLines, $duplicateSeen, approval_ko('%EB%8C%80%ED%91%9C%EC%9D%B4%EC%82%AC'), $sameExecutive, array('allow_duplicate_employee' => 1));
approval_ceo_test_assert(count($duplicateLines) === 2, 'a small proposal must retain a separate CEO line even when the CEO also owns an earlier approval role');

$pdo = new ApprovalCeoDirectFakePdo();
$pdo->lines = array(
    array('id' => 11, 'document_id' => 7, 'line_order' => 1, 'role_type' => 'pm', 'approver_id' => 101, 'approver_name' => 'PM', 'approver_email' => 'pm@example.com', 'line_status' => 'PENDING'),
    array('id' => 12, 'document_id' => 7, 'line_order' => 2, 'role_type' => 'vp', 'approver_id' => 102, 'approver_name' => 'VP', 'approver_email' => 'vp@example.com', 'line_status' => 'WAITING'),
    array('id' => 13, 'document_id' => 7, 'line_order' => 3, 'role_type' => 'ceo', 'approver_id' => 103, 'approver_name' => 'CEO', 'approver_email' => 'ceo@example.com', 'line_status' => 'WAITING')
);

$result = approval_apply_ceo_direct_approval(
    $pdo,
    7,
    array('id' => 103, 'name' => 'CEO', 'email' => 'ceo@example.com'),
    'uploads/signatures/ceo.png'
);

approval_ceo_test_assert((int)$result['open_count'] === 3, 'all open approval lines should be processed');
approval_ceo_test_assert((int)$result['bypassed_count'] === 2, 'PM and VP lines should be delegated by the CEO direct approval');
approval_ceo_test_assert((int)$result['ceo_line_id'] === 13, 'the CEO line should retain the CEO signature');
approval_ceo_test_assert((int)$result['step'] === 3, 'the document should advance to the final line order');
approval_ceo_test_assert(strpos($result['note'], approval_status_label('DELEGATED')) !== false, 'the audit note should identify delegated preceding lines');

$delegatedUpdates = 0;
$signedCeoUpdates = 0;
for ($i = 0; $i < count($pdo->executions); $i++) {
    $execution = $pdo->executions[$i];
    if (strpos($execution['sql'], 'UPDATE cpms_approval_lines') !== false
        && strpos($execution['sql'], "line_status='DELEGATED'") !== false
        && strpos($execution['sql'], 'is_delegated=1') !== false
        && isset($execution['params'][':delegated_by_role'])) {
        $delegatedUpdates++;
    }
    if (strpos($execution['sql'], "line_status='APPROVED'") !== false
        && isset($execution['params'][':id'])
        && (int)$execution['params'][':id'] === 13
        && isset($execution['params'][':sign_path'])
        && $execution['params'][':sign_path'] === 'uploads/signatures/ceo.png') {
        $signedCeoUpdates++;
    }
}

approval_ceo_test_assert($delegatedUpdates === 2, 'two preceding approval cells should persist DELEGATED');
approval_ceo_test_assert($signedCeoUpdates === 1, 'the CEO approval cell should persist the CEO signature');

$pdoWithDelegatedCeoLine = new ApprovalCeoDirectFakePdo();
$pdoWithDelegatedCeoLine->lines = array(
    array('id' => 31, 'document_id' => 9, 'line_order' => 1, 'role_type' => 'pm', 'approver_id' => 301, 'approver_name' => 'PM', 'approver_email' => 'pm3@example.com', 'line_status' => 'PENDING'),
    array('id' => 32, 'document_id' => 9, 'line_order' => 2, 'role_type' => 'vp', 'approver_id' => 302, 'approver_name' => 'VP', 'approver_email' => 'vp3@example.com', 'line_status' => 'WAITING'),
    array('id' => 33, 'document_id' => 9, 'line_order' => 3, 'role_type' => 'ceo', 'approver_id' => 303, 'approver_name' => 'CEO', 'approver_email' => 'ceo3@example.com', 'line_status' => 'DELEGATED', 'is_delegated' => 1)
);
$delegatedCeoResult = approval_apply_ceo_direct_approval(
    $pdoWithDelegatedCeoLine,
    9,
    array('id' => 303, 'name' => 'CEO', 'email' => 'ceo3@example.com'),
    'uploads/signatures/ceo3.png'
);
approval_ceo_test_assert((int)$delegatedCeoResult['ceo_line_id'] === 33, 'an auto-delegated CEO line should be reused for the direct approval');
$delegatedCeoSigned = 0;
for ($i = 0; $i < count($pdoWithDelegatedCeoLine->executions); $i++) {
    $execution = $pdoWithDelegatedCeoLine->executions[$i];
    if (strpos($execution['sql'], "line_status='APPROVED'") !== false
        && strpos($execution['sql'], "'DELEGATED'") !== false
        && strpos($execution['sql'], 'is_delegated=0') !== false
        && strpos($execution['sql'], 'delegated_by_role=NULL') !== false
        && isset($execution['params'][':id'])
        && (int)$execution['params'][':id'] === 33
        && isset($execution['params'][':sign_path'])
        && $execution['params'][':sign_path'] === 'uploads/signatures/ceo3.png') {
        $delegatedCeoSigned++;
    }
}
approval_ceo_test_assert($delegatedCeoSigned === 1, 'an auto-delegated CEO line should change to APPROVED with the CEO signature');

$pdoWithoutCeoLine = new ApprovalCeoDirectFakePdo();
$pdoWithoutCeoLine->lines = array(
    array('id' => 21, 'document_id' => 8, 'line_order' => 1, 'role_type' => 'pm', 'approver_id' => 201, 'approver_name' => 'PM', 'approver_email' => 'pm2@example.com', 'line_status' => 'PENDING'),
    array('id' => 22, 'document_id' => 8, 'line_order' => 2, 'role_type' => 'vp', 'approver_id' => 202, 'approver_name' => 'VP', 'approver_email' => 'vp2@example.com', 'line_status' => 'WAITING')
);
$resultWithoutCeoLine = approval_apply_ceo_direct_approval(
    $pdoWithoutCeoLine,
    8,
    array('id' => 203, 'name' => 'CEO', 'email' => 'ceo2@example.com'),
    'uploads/signatures/ceo2.png'
);
approval_ceo_test_assert((int)$resultWithoutCeoLine['ceo_line_id'] === 1001, 'a missing legacy CEO line should be created for the representative signature');
approval_ceo_test_assert(!empty($resultWithoutCeoLine['ceo_line_created']), 'the result should report that a missing CEO line was created');
approval_ceo_test_assert((int)$resultWithoutCeoLine['bypassed_count'] === 2, 'CEO should delegate the preceding lines when creating a missing CEO line');
$insertedSignedCeo = 0;
for ($i = 0; $i < count($pdoWithoutCeoLine->executions); $i++) {
    $execution = $pdoWithoutCeoLine->executions[$i];
    if (strpos($execution['sql'], 'INSERT INTO cpms_approval_lines') !== false
        && strpos($execution['sql'], 'is_delegated') !== false
        && isset($execution['params'][':sign_path'])
        && $execution['params'][':sign_path'] === 'uploads/signatures/ceo2.png') {
        $insertedSignedCeo++;
    }
}
approval_ceo_test_assert($insertedSignedCeo === 1, 'a legacy document without a CEO line should receive a signed CEO approval cell');

ob_start();
approval_render_sign_cell(array('line_status' => 'CEO_APPROVED'), array());
$renderedSignCell = ob_get_clean();
approval_ceo_test_assert(strpos($renderedSignCell, approval_status_label('CEO_APPROVED')) !== false, 'a bypassed signature cell should visibly show representative approval');

$decideSource = file_get_contents($root . '/app/views/approval/decide.php');
$detailSource = file_get_contents($root . '/app/views/approval/detail.php');
$templateSource = file_get_contents($root . '/app/views/approval/document_templates.php');
$lineRulesSource = file_get_contents($root . '/app/views/approval/line_rules.php');

approval_ceo_test_assert(strpos($decideSource, "'CEO_DIRECT_APPROVE'") !== false, 'direct approval should be saved in approval history');
approval_ceo_test_assert(strpos($detailSource, '$canCeoDirectApprove') !== false, 'CEO direct approval should be available from document details');
approval_ceo_test_assert(strpos($templateSource, "status === 'CEO_APPROVED'") !== false, 'representative approval should render in signature cells');
approval_ceo_test_assert(strpos($lineRulesSource, '$smallProposalDelegationReason') !== false, 'new small proposals should retain an auto-delegated CEO line');
approval_ceo_test_assert(strpos($detailSource, "h(approval_ko('%EC%8A%B9%EC%9D%B8%ED%95%98%EA%B8%B0'))") !== false, 'the CEO should see the standard approval button on every pending document');

echo "OK: CEO direct approval tests passed" . PHP_EOL;
