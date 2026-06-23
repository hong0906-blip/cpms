<?php
/**
 * Notice board page.
 * PHP 5.6 compatible.
 */

require_once __DIR__ . '/../dashboard/notice_board.php';

$pdo = \App\Core\Db::pdo();

cpms_render_dashboard_notice_board($pdo);
?>
