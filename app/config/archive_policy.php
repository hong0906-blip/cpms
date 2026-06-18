<?php
/**
 * CPMS server JSON archive policy.
 * PHP 5.6 compatible.
 */

return array(
    'archive_enabled' => true,
    'keep_recent_years' => 2,
    'archive_drive_root' => urldecode('%EC%8B%9C%EC%8A%A4%ED%85%9C%EB%8D%B0%EC%9D%B4%ED%84%B0%EC%95%84%EC%B9%B4%EC%9D%B4%EB%B8%8C'),
    'archive_dry_run_default' => true,
    'archive_compression' => 'gz',
    'archive_restore_cache_hours' => 24,
    'archive_backup_root_folder_id' => '1jyiSecqmxBmtH6MJ1r0JWwX6dsvk3r0W',
    'archive_backup_root_name' => urldecode('00_%EC%8B%9C%EC%8A%A4%ED%85%9C%EB%B0%B1%EC%97%85'),
    'archive_management_root_name' => urldecode('04_%EA%B4%80%EB%A6%AC%EB%B6%80'),
    'archive_pending_delete_days' => 30,
);
