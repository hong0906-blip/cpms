<?php
/**
 * Google Drive shared drive settings.
 * PHP 5.6 compatible.
 *
 * Do not store service-account JSON content here. Only the server-side path is
 * kept in this config file.
 */

return array(
    'enabled' => true,
    'service_account_json_path' => '/home/cmbuild/www/cpms_private/google/cpms-drive-uploader.json',
    'service_account_email' => 'cpms-drive-uploader@cpms-drive-integration.iam.gserviceaccount.com',
    'shared_drive_id' => '0AK1A2u1kkMtQUk9PVA',
    'scope' => 'https://www.googleapis.com/auth/drive',
    'token_url' => 'https://oauth2.googleapis.com/token',
    'api_base_url' => 'https://www.googleapis.com/drive/v3',
    'upload_base_url' => 'https://www.googleapis.com/upload/drive/v3',
    'folders' => array(
        'system_backup' => '1jyiSecqmxBmtH6MJ1r0JWwX6dsvk3r0W',
        'approval' => '15wAD9EH-Wux1d0DkeN7yjWhUhx-L5j1u',
        'project_root' => '1SAfaa8oNHe_9KAh_rTHd21MzznTJSGfu',
        'common_documents' => '1etwbK2I-Ki2DpBqpfbEZg9RmXPRhrpCf',
        'upload_failed' => '1c37g17r6rHbY_0WXuk00Li852Ru4avWu',
    ),
);
