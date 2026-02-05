<?php
/**
 * app/config/db.php
 * - DB 설정(나중에 실DB 붙일 때 사용)
 * - 현재는 샘플 프로젝트라 DB 없이도 동작하도록 되어 있음
 */

return array(
    'enabled' => false, // true로 바꾸면 DB 로그인/조회로 전환 가능
    'host' => '127.0.0.1',
    'port' => 3306,
    'name' => 'hyupyeob',
    'user' => 'root',
    'pass' => '',
    'charset' => 'utf8mb4',
);