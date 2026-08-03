<?php
register_shutdown_function(function () {
    $error = error_get_last();
    if (is_array($error) && isset($error['type']) && in_array((int)$error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
        echo "\nRENDER_FATAL\n";
    }
});

require_once __DIR__ . '/app/bootstrap.php';

if (getenv('CPMS_TEST_SQLITE') === '1') {
    $testPdo = new PDO('sqlite::memory:');
    $testPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $dbReflection = new ReflectionClass('App\\Core\\Db');
    $pdoProperty = $dbReflection->getProperty('pdo');
    $pdoProperty->setAccessible(true);
    $pdoProperty->setValue(null, $testPdo);
}

$_SESSION['cpms_user'] = array(
    'id'=>999999,
    'name'=>'렌더링검사',
    'email'=>'',
    'department'=>'대표',
    'position'=>'대표',
    'role'=>'employee',
    'employee_role'=>'employee'
);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/cpms/public/index.php';
$_SERVER['HTTP_HOST'] = 'localhost';
$_GET = array(
    'r'=>'ceo_index',
    'tab'=>getenv('CPMS_TEST_TAB') ? getenv('CPMS_TEST_TAB') : 'overview',
    'target_ym'=>'2026-08'
);

require __DIR__ . '/public/index.php';
