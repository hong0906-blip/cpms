<?php
/**
 * app/config/database.php
 * - CPMS DB 설정
 * - PHP 5.6 호환
 */
return array(
    // attendance/db.php 기준으로 통일
    'host'    => 'localhost',
    'port'    => 3306,
    'dbname'  => 'cmbuild_db',
    'user'    => 'cmbuild',
    'pass'    => 'cmbuild@db',
    'charset' => 'utf8mb4',
);