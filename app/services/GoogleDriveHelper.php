<?php
/**
 * Google Drive shared-drive integration helpers.
 * PHP 5.6 compatible, no external Google client dependency.
 */

if (!function_exists('cpms_drive_config')) {
function cpms_drive_config($key = null) {
    static $config = null;
    if ($config === null) {
        $file = dirname(__DIR__) . '/config/google_drive.php';
        $loaded = is_file($file) ? require $file : array();
        $config = is_array($loaded) ? $loaded : array();
    }
    if ($key === null) return $config;
    return isset($config[$key]) ? $config[$key] : null;
}}

if (!function_exists('cpms_drive_json_encode')) {
function cpms_drive_json_encode($data) {
    $options = 0;
    if (defined('JSON_UNESCAPED_UNICODE')) $options = $options | JSON_UNESCAPED_UNICODE;
    if (defined('JSON_UNESCAPED_SLASHES')) $options = $options | JSON_UNESCAPED_SLASHES;
    return json_encode($data, $options);
}}

if (!function_exists('cpms_drive_base64url_encode')) {
function cpms_drive_base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}}

if (!function_exists('cpms_drive_folder_id')) {
function cpms_drive_folder_id($key) {
    $folders = cpms_drive_config('folders');
    if (!is_array($folders)) return '';
    return isset($folders[$key]) ? trim((string)$folders[$key]) : '';
}}

if (!function_exists('cpms_drive_shared_drive_id')) {
function cpms_drive_shared_drive_id() {
    return trim((string)cpms_drive_config('shared_drive_id'));
}}

if (!function_exists('cpms_drive_mask_path')) {
function cpms_drive_mask_path($path) {
    $path = trim((string)$path);
    if ($path === '') return '';
    $path = str_replace('\\', '/', $path);
    $parts = explode('/', $path);
    $clean = array();
    foreach ($parts as $part) {
        if ($part !== '') array_push($clean, $part);
    }
    $count = count($clean);
    if ($count <= 3) return $path;
    return '.../' . implode('/', array_slice($clean, $count - 3));
}}

if (!function_exists('cpms_drive_storage_root')) {
function cpms_drive_storage_root() {
    if (function_exists('cpms_storage_root')) return cpms_storage_root();
    return dirname(dirname(__DIR__)) . '/storage';
}}

if (!function_exists('cpms_drive_log_path')) {
function cpms_drive_log_path() {
    return cpms_drive_storage_root() . '/logs/google_drive_upload_failures.log';
}}

if (!function_exists('cpms_drive_ensure_dir')) {
function cpms_drive_ensure_dir($dir) {
    if (function_exists('cpms_ensure_dir')) return cpms_ensure_dir($dir);
    if (is_dir($dir)) return true;
    return @mkdir($dir, 0777, true);
}}

if (!function_exists('cpms_drive_user_label')) {
function cpms_drive_user_label($user) {
    if (is_array($user)) {
        $name = isset($user['name']) ? trim((string)$user['name']) : '';
        $email = isset($user['email']) ? trim((string)$user['email']) : '';
        if ($name !== '' && $email !== '') return $name . ' <' . $email . '>';
        if ($name !== '') return $name;
        if ($email !== '') return $email;
        if (isset($user['id'])) return 'user#' . (int)$user['id'];
    }
    $label = trim((string)$user);
    return $label !== '' ? $label : '-';
}}

if (!function_exists('cpms_drive_redact_text')) {
function cpms_drive_redact_text($text) {
    $text = (string)$text;
    $patterns = array(
        '/"access_token"\s*:\s*"[^"]*"/i',
        '/"refresh_token"\s*:\s*"[^"]*"/i',
        '/"private_key"\s*:\s*"[^"]*"/i',
        '/Bearer\s+[A-Za-z0-9\._\-]+/i'
    );
    $replacements = array(
        '"access_token":"[redacted]"',
        '"refresh_token":"[redacted]"',
        '"private_key":"[redacted]"',
        'Bearer [redacted]'
    );
    $text = preg_replace($patterns, $replacements, $text);
    if (strlen($text) > 2000) $text = substr($text, 0, 2000) . '...';
    return $text;
}}

if (!function_exists('cpms_drive_context_value')) {
function cpms_drive_context_value($context, $key, $default) {
    if (is_array($context) && isset($context[$key])) return $context[$key];
    return $default;
}}

if (!function_exists('cpms_drive_log_upload_failure')) {
function cpms_drive_log_upload_failure($context) {
    if (!is_array($context)) $context = array();
    $row = array(
        'occurred_at' => date('Y-m-d H:i:s'),
        'user' => cpms_drive_user_label(cpms_drive_context_value($context, 'user', cpms_drive_context_value($context, 'uploaded_by', '-'))),
        'section' => (string)cpms_drive_context_value($context, 'section', ''),
        'approval_document_id' => (string)cpms_drive_context_value($context, 'approval_document_id', cpms_drive_context_value($context, 'approval_id', '')),
        'document_type' => (string)cpms_drive_context_value($context, 'document_type', ''),
        'project_id' => (string)cpms_drive_context_value($context, 'project_id', ''),
        'is_common_file' => (string)cpms_drive_context_value($context, 'is_common_file', ''),
        'document_year' => (string)cpms_drive_context_value($context, 'document_year', ''),
        'document_month' => (string)cpms_drive_context_value($context, 'document_month', ''),
        'original_name' => (string)cpms_drive_context_value($context, 'original_name', cpms_drive_context_value($context, 'file_name', '')),
        'target_folder_id' => (string)cpms_drive_context_value($context, 'target_folder_id', cpms_drive_context_value($context, 'drive_folder_id', '')),
        'message' => cpms_drive_redact_text((string)cpms_drive_context_value($context, 'message', '')),
        'http_status' => (int)cpms_drive_context_value($context, 'http_status', cpms_drive_context_value($context, 'http_code', 0)),
        'google_response_excerpt' => cpms_drive_redact_text((string)cpms_drive_context_value($context, 'google_response_excerpt', cpms_drive_context_value($context, 'response', '')))
    );

    $path = cpms_drive_log_path();
    if (!cpms_drive_ensure_dir(dirname($path))) return false;
    return (@file_put_contents($path, cpms_drive_json_encode($row) . "\n", FILE_APPEND | LOCK_EX) !== false);
}}

if (!function_exists('cpms_drive_read_service_account')) {
function cpms_drive_read_service_account() {
    $path = trim((string)cpms_drive_config('service_account_json_path'));
    $result = array(
        'ok' => false,
        'path' => $path,
        'masked_path' => cpms_drive_mask_path($path),
        'account' => null,
        'service_account_email' => '',
        'message' => ''
    );

    if ($path === '') {
        $result['message'] = 'Google Drive service-account JSON path is not configured.';
        return $result;
    }
    if (!is_file($path)) {
        $result['message'] = 'Google Drive service-account JSON file does not exist.';
        return $result;
    }
    if (!is_readable($path)) {
        $result['message'] = 'Google Drive service-account JSON file is not readable by PHP.';
        return $result;
    }

    $content = @file_get_contents($path);
    if ($content === false || trim($content) === '') {
        $result['message'] = 'Google Drive service-account JSON file is empty or unreadable.';
        return $result;
    }

    $account = @json_decode($content, true);
    if (!is_array($account)) {
        $result['message'] = 'Google Drive service-account JSON is invalid.';
        return $result;
    }
    if (!isset($account['client_email']) || trim((string)$account['client_email']) === '') {
        $result['message'] = 'Google Drive service-account JSON has no client_email.';
        return $result;
    }
    if (!isset($account['private_key']) || trim((string)$account['private_key']) === '') {
        $result['message'] = 'Google Drive service-account JSON has no private_key.';
        return $result;
    }

    $expected = trim((string)cpms_drive_config('service_account_email'));
    $actual = trim((string)$account['client_email']);
    if ($expected !== '' && strtolower($expected) !== strtolower($actual)) {
        $result['message'] = 'Google Drive service-account email does not match config.';
        return $result;
    }

    $result['ok'] = true;
    $result['account'] = $account;
    $result['service_account_email'] = $actual;
    $result['message'] = 'Google Drive service-account JSON is readable.';
    return $result;
}}

if (!function_exists('cpms_drive_curl_request')) {
function cpms_drive_curl_request($method, $url, $headers, $body, $timeout) {
    $result = array(
        'ok' => false,
        'http_code' => 0,
        'body' => '',
        'json' => null,
        'error' => ''
    );

    if (!function_exists('curl_init')) {
        $result['error'] = 'PHP cURL extension is not available.';
        return $result;
    }

    $ch = @curl_init();
    if (!$ch) {
        $result['error'] = 'cURL initialization failed.';
        return $result;
    }

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper((string)$method));
    curl_setopt($ch, CURLOPT_HTTPHEADER, is_array($headers) ? $headers : array());
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, (int)$timeout > 0 ? (int)$timeout : 60);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $result['http_code'] = $httpCode;
    $result['body'] = ($response === false) ? '' : (string)$response;
    if ($result['body'] !== '') {
        $json = @json_decode($result['body'], true);
        if (is_array($json)) $result['json'] = $json;
    }

    if ($response !== false && $httpCode >= 200 && $httpCode < 300) {
        $result['ok'] = true;
    } else {
        $result['error'] = $curlError !== '' ? $curlError : ('HTTP ' . $httpCode);
    }

    return $result;
}}

if (!function_exists('cpms_drive_get_access_token')) {
function cpms_drive_get_access_token($scope = '') {
    static $cache = null;
    $now = time();
    if (is_array($cache) && isset($cache['access_token']) && isset($cache['expires_at']) && (int)$cache['expires_at'] > ($now + 60)) {
        return array('ok' => true, 'access_token' => $cache['access_token'], 'expires_at' => $cache['expires_at'], 'http_code' => 0, 'message' => 'Access token loaded from request memory.');
    }

    $result = array(
        'ok' => false,
        'access_token' => '',
        'expires_at' => 0,
        'http_code' => 0,
        'message' => '',
        'response_excerpt' => ''
    );

    if (!extension_loaded('openssl')) {
        $result['message'] = 'PHP OpenSSL extension is not available.';
        return $result;
    }

    $read = cpms_drive_read_service_account();
    if (!$read['ok']) {
        $result['message'] = $read['message'];
        return $result;
    }
    $account = $read['account'];
    $tokenUrl = trim((string)cpms_drive_config('token_url'));
    if ($tokenUrl === '') $tokenUrl = 'https://oauth2.googleapis.com/token';
    if ($scope === '') {
        $scope = trim((string)cpms_drive_config('scope'));
        if ($scope === '') $scope = 'https://www.googleapis.com/auth/drive';
    }

    $claim = array(
        'iss' => $account['client_email'],
        'scope' => $scope,
        'aud' => $tokenUrl,
        'iat' => $now,
        'exp' => $now + 3600
    );
    $header = array('alg' => 'RS256', 'typ' => 'JWT');
    $input = cpms_drive_base64url_encode(json_encode($header)) . '.' . cpms_drive_base64url_encode(json_encode($claim));
    $signature = '';

    $privateKey = @openssl_pkey_get_private($account['private_key']);
    if (!$privateKey) {
        $result['message'] = 'OpenSSL could not read the service-account private key.';
        return $result;
    }
    $algo = defined('OPENSSL_ALGO_SHA256') ? OPENSSL_ALGO_SHA256 : 'sha256';
    $signed = @openssl_sign($input, $signature, $privateKey, $algo);
    if (function_exists('openssl_free_key')) @openssl_free_key($privateKey);
    if (!$signed) {
        $result['message'] = 'JWT signing failed.';
        return $result;
    }

    $jwt = $input . '.' . cpms_drive_base64url_encode($signature);
    $body = http_build_query(array(
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $jwt
    ), '', '&');

    $res = cpms_drive_curl_request('POST', $tokenUrl, array('Content-Type: application/x-www-form-urlencoded'), $body, 30);
    $result['http_code'] = $res['http_code'];
    $result['response_excerpt'] = cpms_drive_redact_text($res['body']);
    if (!$res['ok']) {
        $result['message'] = 'Access token request failed: ' . $res['error'];
        return $result;
    }
    if (!is_array($res['json']) || !isset($res['json']['access_token'])) {
        $result['message'] = 'Access token response was not recognized.';
        return $result;
    }

    $expiresIn = isset($res['json']['expires_in']) ? (int)$res['json']['expires_in'] : 3600;
    $expiresAt = $now + $expiresIn;
    $cache = array(
        'access_token' => (string)$res['json']['access_token'],
        'expires_at' => $expiresAt
    );

    $result['ok'] = true;
    $result['access_token'] = $cache['access_token'];
    $result['expires_at'] = $expiresAt;
    $result['message'] = 'Access token issued.';
    return $result;
}}

if (!function_exists('cpms_drive_api_url')) {
function cpms_drive_api_url($path, $params, $upload) {
    $baseKey = $upload ? 'upload_base_url' : 'api_base_url';
    $base = rtrim((string)cpms_drive_config($baseKey), '/');
    if ($base === '') $base = $upload ? 'https://www.googleapis.com/upload/drive/v3' : 'https://www.googleapis.com/drive/v3';
    $url = $base . '/' . ltrim((string)$path, '/');
    if (is_array($params) && count($params) > 0) {
        $url .= '?' . http_build_query($params, '', '&');
    }
    return $url;
}}

if (!function_exists('cpms_drive_authorized_request')) {
function cpms_drive_authorized_request($method, $path, $params, $body, $headers, $upload, $timeout) {
    $token = cpms_drive_get_access_token();
    if (!$token['ok']) {
        return array(
            'ok' => false,
            'http_code' => isset($token['http_code']) ? (int)$token['http_code'] : 0,
            'body' => '',
            'json' => null,
            'error' => $token['message']
        );
    }
    if (!is_array($headers)) $headers = array();
    array_unshift($headers, 'Authorization: Bearer ' . $token['access_token']);
    $url = cpms_drive_api_url($path, $params, $upload);
    return cpms_drive_curl_request($method, $url, $headers, $body, $timeout);
}}

if (!function_exists('cpms_drive_file_fields')) {
function cpms_drive_file_fields() {
    return 'id,name,mimeType,size,parents,trashed,webViewLink,webContentLink';
}}

if (!function_exists('cpms_drive_query_escape')) {
function cpms_drive_query_escape($value) {
    return str_replace(array('\\', "'"), array('\\\\', "\\'"), (string)$value);
}}

if (!function_exists('cpms_drive_sanitize_file_name')) {
function cpms_drive_sanitize_file_name($name, $maxLength) {
    $name = trim((string)$name);
    $name = preg_replace('/[\/\\\\:\*\?"<>\|\x00-\x1F]+/', '_', $name);
    $name = preg_replace('/\s+/', ' ', $name);
    $name = trim($name, " .\t\r\n");
    if ($name === '') $name = 'cpms_file';
    $maxLength = (int)$maxLength > 0 ? (int)$maxLength : 180;

    $length = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
    if ($length <= $maxLength) return $name;

    $ext = pathinfo($name, PATHINFO_EXTENSION);
    $base = $ext !== '' ? substr($name, 0, -(strlen($ext) + 1)) : $name;
    $keep = $maxLength - ($ext !== '' ? strlen($ext) + 1 : 0);
    if ($keep < 20) $keep = $maxLength;
    if (function_exists('mb_substr')) {
        $base = mb_substr($base, 0, $keep, 'UTF-8');
    } else {
        $base = substr($base, 0, $keep);
    }
    $base = trim($base, " .\t\r\n");
    if ($base === '') $base = 'cpms_file';
    return $ext !== '' ? ($base . '.' . $ext) : $base;
}}

if (!function_exists('cpms_drive_sanitize_folder_name')) {
function cpms_drive_sanitize_folder_name($name) {
    return cpms_drive_sanitize_file_name((string)$name, 120);
}}

if (!function_exists('cpms_drive_detect_mime_type')) {
function cpms_drive_detect_mime_type($path) {
    $mime = '';
    if (function_exists('finfo_open') && is_file($path)) {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = (string)@finfo_file($finfo, $path);
            @finfo_close($finfo);
        }
    }
    if ($mime === '' && function_exists('mime_content_type') && is_file($path)) {
        $mime = (string)@mime_content_type($path);
    }
    $mime = trim($mime);
    return $mime !== '' ? $mime : 'application/octet-stream';
}}

if (!function_exists('cpms_drive_find_folder')) {
function cpms_drive_find_folder($name, $parentFolderId) {
    $name = cpms_drive_sanitize_folder_name($name);
    $parentFolderId = trim((string)$parentFolderId);
    if ($name === '' || $parentFolderId === '') {
        return array('ok' => false, 'found' => false, 'file' => null, 'message' => 'Folder name or parent folder ID is empty.', 'http_code' => 0);
    }
    $q = "'" . cpms_drive_query_escape($parentFolderId) . "' in parents and name = '" . cpms_drive_query_escape($name) . "' and mimeType = 'application/vnd.google-apps.folder' and trashed = false";
    $params = array(
        'q' => $q,
        'fields' => 'files(id,name,mimeType,parents,webViewLink)',
        'supportsAllDrives' => 'true',
        'includeItemsFromAllDrives' => 'true',
        'corpora' => 'drive',
        'driveId' => cpms_drive_shared_drive_id(),
        'pageSize' => '10'
    );
    $res = cpms_drive_authorized_request('GET', 'files', $params, null, array('Accept: application/json'), false, 30);
    if (!$res['ok']) {
        return array('ok' => false, 'found' => false, 'file' => null, 'message' => 'Drive folder search failed: ' . $res['error'], 'http_code' => $res['http_code'], 'response' => $res['body']);
    }
    $files = (is_array($res['json']) && isset($res['json']['files']) && is_array($res['json']['files'])) ? $res['json']['files'] : array();
    if (count($files) > 0) {
        return array('ok' => true, 'found' => true, 'file' => $files[0], 'message' => 'Folder found.', 'http_code' => $res['http_code']);
    }
    return array('ok' => true, 'found' => false, 'file' => null, 'message' => 'Folder not found.', 'http_code' => $res['http_code']);
}}

if (!function_exists('cpms_drive_create_folder')) {
function cpms_drive_create_folder($name, $parentFolderId, $context) {
    if (!is_array($context)) $context = array();
    $name = cpms_drive_sanitize_folder_name($name);
    $parentFolderId = trim((string)$parentFolderId);
    if ($name === '' || $parentFolderId === '') {
        return array('ok' => false, 'file' => null, 'message' => 'Folder name or parent folder ID is empty.', 'http_code' => 0);
    }
    $metadata = array(
        'name' => $name,
        'mimeType' => 'application/vnd.google-apps.folder',
        'parents' => array($parentFolderId)
    );
    $params = array(
        'supportsAllDrives' => 'true',
        'fields' => cpms_drive_file_fields()
    );
    $res = cpms_drive_authorized_request('POST', 'files', $params, cpms_drive_json_encode($metadata), array('Content-Type: application/json; charset=UTF-8', 'Accept: application/json'), false, 30);
    if (!$res['ok']) {
        $context['target_folder_id'] = $parentFolderId;
        $context['original_name'] = $name;
        $context['message'] = 'Drive folder creation failed: ' . $res['error'];
        $context['http_status'] = $res['http_code'];
        $context['google_response_excerpt'] = $res['body'];
        cpms_drive_log_upload_failure($context);
        return array('ok' => false, 'file' => null, 'message' => $context['message'], 'http_code' => $res['http_code'], 'response' => $res['body']);
    }
    return array('ok' => true, 'file' => $res['json'], 'message' => 'Folder created.', 'http_code' => $res['http_code']);
}}

if (!function_exists('cpms_drive_find_or_create_folder')) {
function cpms_drive_find_or_create_folder($name, $parentFolderId, $context) {
    $found = cpms_drive_find_folder($name, $parentFolderId);
    if (!$found['ok']) {
        if (!is_array($context)) $context = array();
        $context['target_folder_id'] = $parentFolderId;
        $context['original_name'] = $name;
        $context['message'] = $found['message'];
        $context['http_status'] = isset($found['http_code']) ? (int)$found['http_code'] : 0;
        $context['google_response_excerpt'] = isset($found['response']) ? $found['response'] : '';
        cpms_drive_log_upload_failure($context);
        return array('ok' => false, 'created' => false, 'file' => null, 'message' => $found['message'], 'http_code' => $found['http_code']);
    }
    if ($found['found'] && is_array($found['file'])) {
        return array('ok' => true, 'created' => false, 'file' => $found['file'], 'message' => 'Existing folder used.', 'http_code' => $found['http_code']);
    }
    $created = cpms_drive_create_folder($name, $parentFolderId, $context);
    return array(
        'ok' => $created['ok'],
        'created' => $created['ok'],
        'file' => isset($created['file']) ? $created['file'] : null,
        'message' => $created['message'],
        'http_code' => $created['http_code']
    );
}}

if (!function_exists('cpms_drive_upload_file')) {
function cpms_drive_upload_file($localPath, $driveName, $folderId, $mimeType, $context) {
    if (!is_array($context)) $context = array();
    $localPath = trim((string)$localPath);
    $folderId = trim((string)$folderId);
    if ($localPath === '' || !is_file($localPath)) {
        $context['message'] = 'Local file does not exist for Drive upload.';
        $context['target_folder_id'] = $folderId;
        cpms_drive_log_upload_failure($context);
        return array('ok' => false, 'file' => null, 'message' => $context['message'], 'http_code' => 0);
    }
    if ($folderId === '') {
        $context['message'] = 'Drive target folder ID is empty.';
        cpms_drive_log_upload_failure($context);
        return array('ok' => false, 'file' => null, 'message' => $context['message'], 'http_code' => 0);
    }
    $driveName = trim((string)$driveName);
    if ($driveName === '') $driveName = basename($localPath);
    $driveName = cpms_drive_sanitize_file_name($driveName, 180);
    $mimeType = trim((string)$mimeType);
    if ($mimeType === '') $mimeType = cpms_drive_detect_mime_type($localPath);
    $content = @file_get_contents($localPath);
    if ($content === false) {
        $context['message'] = 'Local file could not be read for Drive upload.';
        $context['target_folder_id'] = $folderId;
        cpms_drive_log_upload_failure($context);
        return array('ok' => false, 'file' => null, 'message' => $context['message'], 'http_code' => 0);
    }

    $metadata = array(
        'name' => $driveName,
        'parents' => array($folderId),
        'mimeType' => $mimeType
    );
    $boundary = 'cpms_drive_' . md5(uniqid('', true));
    $body = '';
    $body .= '--' . $boundary . "\r\n";
    $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
    $body .= cpms_drive_json_encode($metadata) . "\r\n";
    $body .= '--' . $boundary . "\r\n";
    $body .= 'Content-Type: ' . $mimeType . "\r\n\r\n";
    $body .= $content . "\r\n";
    $body .= '--' . $boundary . "--\r\n";

    $params = array(
        'uploadType' => 'multipart',
        'supportsAllDrives' => 'true',
        'fields' => cpms_drive_file_fields()
    );
    $headers = array(
        'Content-Type: multipart/related; boundary=' . $boundary,
        'Content-Length: ' . strlen($body),
        'Accept: application/json'
    );
    $res = cpms_drive_authorized_request('POST', 'files', $params, $body, $headers, true, 120);
    if (!$res['ok'] || !is_array($res['json']) || !isset($res['json']['id'])) {
        $context['target_folder_id'] = $folderId;
        $context['original_name'] = cpms_drive_context_value($context, 'original_name', basename($localPath));
        $context['message'] = 'Drive file upload failed: ' . $res['error'];
        $context['http_status'] = $res['http_code'];
        $context['google_response_excerpt'] = $res['body'];
        cpms_drive_log_upload_failure($context);
        return array('ok' => false, 'file' => null, 'message' => $context['message'], 'http_code' => $res['http_code'], 'response' => $res['body']);
    }

    return array('ok' => true, 'file' => $res['json'], 'message' => 'Drive file uploaded.', 'http_code' => $res['http_code']);
}}

if (!function_exists('cpms_drive_get_file_info')) {
function cpms_drive_get_file_info($fileId) {
    $fileId = trim((string)$fileId);
    if ($fileId === '') return array('ok' => false, 'file' => null, 'message' => 'Drive file ID is empty.', 'http_code' => 0);
    $params = array(
        'supportsAllDrives' => 'true',
        'fields' => cpms_drive_file_fields()
    );
    $res = cpms_drive_authorized_request('GET', 'files/' . rawurlencode($fileId), $params, null, array('Accept: application/json'), false, 30);
    if (!$res['ok']) return array('ok' => false, 'file' => null, 'message' => 'Drive file info failed: ' . $res['error'], 'http_code' => $res['http_code'], 'response' => $res['body']);
    return array('ok' => true, 'file' => $res['json'], 'message' => 'Drive file info loaded.', 'http_code' => $res['http_code']);
}}

if (!function_exists('cpms_drive_delete_file')) {
function cpms_drive_delete_file($fileId, $context) {
    if (!is_array($context)) $context = array();
    $fileId = trim((string)$fileId);
    if ($fileId === '') return array('ok' => false, 'message' => 'Drive file ID is empty.', 'http_code' => 0);
    $params = array('supportsAllDrives' => 'true');
    $res = cpms_drive_authorized_request('DELETE', 'files/' . rawurlencode($fileId), $params, null, array('Accept: application/json'), false, 30);
    if (!$res['ok']) {
        $context['message'] = 'Drive file delete failed: ' . $res['error'];
        $context['http_status'] = $res['http_code'];
        $context['google_response_excerpt'] = $res['body'];
        cpms_drive_log_upload_failure($context);
        return array('ok' => false, 'message' => $context['message'], 'http_code' => $res['http_code'], 'response' => $res['body']);
    }
    return array('ok' => true, 'message' => 'Drive file deleted.', 'http_code' => $res['http_code']);
}}

if (!function_exists('cpms_drive_build_file_record')) {
function cpms_drive_build_file_record($fileInfo, $context) {
    if (!is_array($fileInfo)) $fileInfo = array();
    if (!is_array($context)) $context = array();
    $parents = (isset($fileInfo['parents']) && is_array($fileInfo['parents'])) ? $fileInfo['parents'] : array();
    $folderId = count($parents) > 0 ? (string)$parents[0] : (string)cpms_drive_context_value($context, 'drive_folder_id', cpms_drive_context_value($context, 'target_folder_id', ''));
    return array(
        'original_name' => (string)cpms_drive_context_value($context, 'original_name', ''),
        'stored_name' => isset($fileInfo['name']) ? (string)$fileInfo['name'] : (string)cpms_drive_context_value($context, 'stored_name', ''),
        'drive_file_id' => isset($fileInfo['id']) ? (string)$fileInfo['id'] : '',
        'drive_folder_id' => $folderId,
        'drive_web_view_link' => isset($fileInfo['webViewLink']) ? (string)$fileInfo['webViewLink'] : '',
        'drive_web_content_link' => isset($fileInfo['webContentLink']) ? (string)$fileInfo['webContentLink'] : '',
        'mime_type' => isset($fileInfo['mimeType']) ? (string)$fileInfo['mimeType'] : (string)cpms_drive_context_value($context, 'mime_type', ''),
        'size' => isset($fileInfo['size']) ? (string)$fileInfo['size'] : (string)cpms_drive_context_value($context, 'size', ''),
        'section' => (string)cpms_drive_context_value($context, 'section', ''),
        'document_type' => (string)cpms_drive_context_value($context, 'document_type', ''),
        'project_id' => (string)cpms_drive_context_value($context, 'project_id', ''),
        'approval_id' => (string)cpms_drive_context_value($context, 'approval_id', ''),
        'task_id' => (string)cpms_drive_context_value($context, 'task_id', ''),
        'uploaded_by' => cpms_drive_user_label(cpms_drive_context_value($context, 'uploaded_by', cpms_drive_context_value($context, 'user', ''))),
        'uploaded_at' => (string)cpms_drive_context_value($context, 'uploaded_at', date('Y-m-d H:i:s')),
        'storage_type' => 'google_drive',
        'local_backup_path' => (string)cpms_drive_context_value($context, 'local_backup_path', ''),
        'local_temp_path' => (string)cpms_drive_context_value($context, 'local_temp_path', ''),
        'upload_status' => (string)cpms_drive_context_value($context, 'upload_status', 'uploaded'),
        'document_year' => (string)cpms_drive_context_value($context, 'document_year', ''),
        'document_month' => (string)cpms_drive_context_value($context, 'document_month', ''),
        'drive_year_folder_id' => (string)cpms_drive_context_value($context, 'drive_year_folder_id', ''),
        'drive_type_folder_id' => (string)cpms_drive_context_value($context, 'drive_type_folder_id', ''),
        'drive_month_folder_id' => (string)cpms_drive_context_value($context, 'drive_month_folder_id', ''),
        'completed_pdf_year' => (string)cpms_drive_context_value($context, 'completed_pdf_year', cpms_drive_context_value($context, 'document_year', '')),
        'completed_pdf_month' => (string)cpms_drive_context_value($context, 'completed_pdf_month', cpms_drive_context_value($context, 'document_month', '')),
        'completed_pdf_year_folder_id' => (string)cpms_drive_context_value($context, 'completed_pdf_year_folder_id', cpms_drive_context_value($context, 'drive_year_folder_id', '')),
        'completed_pdf_type_folder_id' => (string)cpms_drive_context_value($context, 'completed_pdf_type_folder_id', cpms_drive_context_value($context, 'drive_type_folder_id', '')),
        'completed_pdf_month_folder_id' => (string)cpms_drive_context_value($context, 'completed_pdf_month_folder_id', cpms_drive_context_value($context, 'drive_month_folder_id', ''))
    );
}}

if (!function_exists('cpms_drive_build_storage_name')) {
function cpms_drive_build_storage_name($originalName, $section, $documentType, $projectName, $uploadedBy) {
    $parts = array(
        date('Ymd_His'),
        trim((string)$section),
        trim((string)$documentType),
        trim((string)$projectName),
        trim((string)$uploadedBy),
        trim((string)$originalName)
    );
    $clean = array();
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part !== '') array_push($clean, $part);
    }
    return cpms_drive_sanitize_file_name(implode('_', $clean), 180);
}}

if (!function_exists('cpms_drive_project_folder_name')) {
function cpms_drive_project_folder_name($projectName, $projectId) {
    $name = trim((string)$projectName);
    $projectId = (int)$projectId;
    if ($name === '') $name = 'PROJECT';
    $name = cpms_drive_sanitize_folder_name($name);
    if ($name === '' || $name === 'cpms_file') $name = 'PROJECT';
    if (function_exists('mb_substr')) {
        $name = mb_substr($name, 0, 90, 'UTF-8');
    } else {
        $name = substr($name, 0, 90);
    }
    $name = trim($name, " .\t\r\n");
    if ($name === '') $name = 'PROJECT';
    return cpms_drive_sanitize_folder_name($name . '_' . $projectId);
}}

if (!function_exists('cpms_drive_project_folder_schema')) {
function cpms_drive_project_folder_schema() {
    return array(
        'public_affairs' => array(
            'name' => urldecode('%30%31%5F%EA%B3%B5%EB%AC%B4'),
            'children' => array(
                'public_affairs_estimate' => urldecode('%EB%82%B4%EC%97%AD%EC%84%9C'),
                'public_affairs_contract' => urldecode('%EA%B3%84%EC%95%BD%EC%84%9C'),
                'public_affairs_site_docs' => urldecode('%ED%98%84%EC%84%A4%EC%9E%90%EB%A3%8C'),
                'public_affairs_monthly_cost' => urldecode('%EC%9B%94%EB%B3%84%ED%88%AC%EC%9E%85%EB%B9%84'),
                'public_affairs_progress' => urldecode('%EA%B8%B0%EC%84%B1')
            )
        ),
        'management' => array(
            'name' => urldecode('%30%32%5F%EA%B4%80%EB%A6%AC'),
            'children' => array(
                'management_statement' => urldecode('%EA%B1%B0%EB%9E%98%EB%AA%85%EC%84%B8%ED%91%9C'),
                'management_tax_invoice' => urldecode('%EC%84%B8%EA%B8%88%EA%B3%84%EC%82%B0%EC%84%9C'),
                'management_settlement' => urldecode('%EC%A0%95%EC%82%B0%EC%9E%90%EB%A3%8C'),
                'management_labor' => urldecode('%EB%85%B8%EB%AC%B4%EC%9E%90%EB%A3%8C'),
                'management_manpower' => urldecode('%EC%9D%B8%EB%A0%A5%EA%B4%80%EB%A6%AC'),
                'management_etc' => urldecode('%EA%B8%B0%ED%83%80')
            )
        ),
        'construction' => array(
            'name' => urldecode('%30%33%5F%EA%B3%B5%EC%82%AC'),
            'children' => array(
                'construction_material' => urldecode('%EC%9E%90%EC%9E%AC%EA%B5%AC%EC%9E%85%EB%B9%84'),
                'construction_daily_report' => urldecode('%EC%9D%BC%EC%9D%BC%EB%B3%B4%EA%B3%A0'),
                'construction_photo' => urldecode('%EA%B3%B5%EC%82%AC%EC%82%AC%EC%A7%84'),
                'construction_status' => urldecode('%EC%83%81%ED%99%A9%EC%9E%90%EB%A3%8C'),
                'construction_equipment' => urldecode('%EC%9E%A5%EB%B9%84%ED%88%AC%EC%9E%85'),
                'construction_labor' => urldecode('%EB%85%B8%EB%AC%B4%EB%B9%84'),
                'construction_etc' => urldecode('%EA%B8%B0%ED%83%80')
            )
        ),
        'safety_health' => array(
            'name' => urldecode('%30%34%5F%EC%95%88%EC%A0%84%EB%B3%B4%EA%B1%B4'),
            'children' => array(
                'safety_health_safety_cost' => urldecode('%EC%95%88%EC%A0%84%EA%B4%80%EB%A6%AC%EB%B9%84'),
                'safety_health_accident' => urldecode('%EC%95%88%EC%A0%84%EC%82%AC%EA%B3%A0'),
                'safety_health_samsung_portal' => urldecode('%EC%82%BC%EC%84%B1%EC%83%81%EC%83%9D%ED%98%91%EB%A0%A5%ED%8F%AC%ED%83%88'),
                'safety_health_ppe' => urldecode('%EB%B3%B4%ED%98%B8%EA%B5%AC'),
                'safety_health_education' => urldecode('%EA%B5%90%EC%9C%A1'),
                'safety_health_medical_checkup' => urldecode('%EA%B2%80%EC%A7%84'),
                'safety_health_etc' => urldecode('%EA%B8%B0%ED%83%80')
            )
        ),
        'quality' => array(
            'name' => urldecode('%30%35%5F%ED%92%88%EC%A7%88'),
            'children' => array(
                'quality_material_approval' => urldecode('%EC%9E%90%EC%9E%AC%EC%8A%B9%EC%9D%B8'),
                'quality_inspection' => urldecode('%EA%B2%80%EC%B8%A1'),
                'quality_test_report' => urldecode('%EC%8B%9C%ED%97%98%EC%84%B1%EC%A0%81%EC%84%9C'),
                'quality_cqi' => 'CQI',
                'quality_submission' => urldecode('%EC%A0%9C%EC%B6%9C%EB%AC%B8%EC%84%9C')
            )
        )
    );
}}

/*
if (!function_exists('cpms_drive_project_folder_schema')) {
function cpms_drive_project_folder_schema() {
    return array(
        'public_affairs' => array(
            'name' => '01_공무',
            'children' => array(
                'public_affairs_estimate' => '내역서',
                'public_affairs_contract' => '계약서',
                'public_affairs_site_docs' => '현설자료',
                'public_affairs_monthly_cost' => '월별투입비',
                'public_affairs_progress' => '기성'
            )
        ),
        'management' => array(
            'name' => '02_관리',
            'children' => array(
                'management_statement' => '거래명세표',
                'management_tax_invoice' => '세금계산서',
                'management_settlement' => '정산자료',
                'management_labor' => '노무자료',
                'management_manpower' => '인력관리'
            )
        ),
        'construction' => array(
            'name' => '03_공사',
            'children' => array(
                'construction_material' => '자재구입비',
                'construction_daily_report' => '일일보고',
                'construction_photo' => '공사사진',
                'construction_status' => '상황자료',
                'construction_equipment' => '장비투입'
            )
        ),
        'safety_health' => array(
            'name' => '04_안전보건',
            'children' => array(
                'safety_health_safety_cost' => '안전관리비',
                'safety_health_accident' => '안전사고',
                'safety_health_samsung_portal' => '삼성상생협력포탈',
                'safety_health_ppe' => '보호구',
                'safety_health_education' => '교육',
                'safety_health_medical_checkup' => '검진'
            )
        ),
        'quality' => array(
            'name' => '05_품질',
            'children' => array(
                'quality_material_approval' => '자재승인',
                'quality_inspection' => '검측',
                'quality_test_report' => '시험성적서',
                'quality_cqi' => 'CQI',
                'quality_submission' => '제출문서'
            )
        )
    );
}}

*/

if (!function_exists('cpms_drive_create_project_structure')) {
function cpms_drive_create_project_structure($projectId, $projectName, $userContext) {
    if (!is_array($userContext)) $userContext = array();
    $projectId = (int)$projectId;
    $rootFolderId = cpms_drive_folder_id('project_root');
    $result = array(
        'ok' => false,
        'status' => 'failed',
        'message' => '',
        'drive' => array(
            'status' => '',
            'synced_at' => '',
            'project_folder_id' => '',
            'project_folder_name' => '',
            'folders' => array(),
            'created_at' => date('Y-m-d H:i:s')
        ),
        'errors' => array()
    );

    if ($projectId <= 0) {
        $result['message'] = 'Project ID is empty.';
        return $result;
    }
    if ($rootFolderId === '') {
        $result['message'] = 'Project root Drive folder ID is not configured.';
        return $result;
    }

    $context = array(
        'user' => $userContext,
        'section' => 'project',
        'project_id' => $projectId,
        'target_folder_id' => $rootFolderId
    );
    $folderName = cpms_drive_project_folder_name($projectName, $projectId);
    $projectFolder = cpms_drive_find_or_create_folder($folderName, $rootFolderId, $context);
    if (!$projectFolder['ok'] || !is_array($projectFolder['file']) || !isset($projectFolder['file']['id'])) {
        $result['message'] = isset($projectFolder['message']) ? $projectFolder['message'] : 'Project Drive folder creation failed.';
        return $result;
    }

    $projectFolderId = (string)$projectFolder['file']['id'];
    $result['drive']['status'] = 'ready';
    $result['drive']['synced_at'] = date('Y-m-d H:i:s');
    $result['drive']['project_folder_id'] = $projectFolderId;
    $result['drive']['project_folder_name'] = $folderName;
    $result['drive']['folders']['project'] = $projectFolderId;

    $schema = cpms_drive_project_folder_schema();
    foreach ($schema as $sectionKey => $sectionInfo) {
        $sectionName = isset($sectionInfo['name']) ? $sectionInfo['name'] : $sectionKey;
        $sectionContext = $context;
        $sectionContext['target_folder_id'] = $projectFolderId;
        $sectionFolder = cpms_drive_find_or_create_folder($sectionName, $projectFolderId, $sectionContext);
        if (!$sectionFolder['ok'] || !is_array($sectionFolder['file']) || !isset($sectionFolder['file']['id'])) {
            array_push($result['errors'], $sectionName . ': ' . (isset($sectionFolder['message']) ? $sectionFolder['message'] : 'folder failed'));
            continue;
        }

        $sectionFolderId = (string)$sectionFolder['file']['id'];
        $result['drive']['folders'][$sectionKey] = $sectionFolderId;

        $children = isset($sectionInfo['children']) && is_array($sectionInfo['children']) ? $sectionInfo['children'] : array();
        foreach ($children as $childKey => $childName) {
            $childContext = $context;
            $childContext['target_folder_id'] = $sectionFolderId;
            $childFolder = cpms_drive_find_or_create_folder($childName, $sectionFolderId, $childContext);
            if (!$childFolder['ok'] || !is_array($childFolder['file']) || !isset($childFolder['file']['id'])) {
                array_push($result['errors'], $sectionName . '/' . $childName . ': ' . (isset($childFolder['message']) ? $childFolder['message'] : 'folder failed'));
                continue;
            }
            $result['drive']['folders'][$childKey] = (string)$childFolder['file']['id'];
        }
    }

    if (count($result['errors']) > 0) {
        $result['message'] = 'Project Drive folder was created, but some subfolders failed.';
        return $result;
    }

    $result['ok'] = true;
    $result['status'] = 'ready';
    $result['message'] = 'Project Drive folder structure is ready.';
    return $result;
}}

if (!function_exists('cpms_drive_db_column_exists')) {
function cpms_drive_db_column_exists($pdo, $table, $column) {
    if (!$pdo) return false;
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `" . $table . "` LIKE :col");
        $st->bindValue(':col', (string)$column);
        $st->execute();
        return $st->fetch() ? true : false;
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_drive_ensure_project_columns')) {
function cpms_drive_ensure_project_columns($pdo) {
    if (!$pdo) return false;
    try {
        $st = $pdo->prepare("SHOW TABLES LIKE :tbl");
        $st->bindValue(':tbl', 'cpms_projects');
        $st->execute();
        if (!$st->fetch()) return false;
    } catch (Exception $e) {
        return false;
    }

    $columns = array(
        'drive_status' => "ALTER TABLE cpms_projects ADD COLUMN drive_status VARCHAR(30) DEFAULT ''",
        'drive_folder_id' => "ALTER TABLE cpms_projects ADD COLUMN drive_folder_id VARCHAR(128) DEFAULT ''",
        'drive_folders_json' => "ALTER TABLE cpms_projects ADD COLUMN drive_folders_json TEXT NULL",
        'drive_error_message' => "ALTER TABLE cpms_projects ADD COLUMN drive_error_message TEXT NULL",
        'drive_updated_at' => "ALTER TABLE cpms_projects ADD COLUMN drive_updated_at DATETIME NULL"
    );

    $ok = true;
    foreach ($columns as $column => $sql) {
        if (!cpms_drive_db_column_exists($pdo, 'cpms_projects', $column)) {
            try {
                $pdo->exec($sql);
            } catch (Exception $e) {
                $ok = false;
            }
        }
    }
    return $ok;
}}

if (!function_exists('cpms_drive_save_project_structure_result')) {
function cpms_drive_save_project_structure_result($pdo, $projectId, $driveResult) {
    $projectId = (int)$projectId;
    if (!$pdo || $projectId <= 0 || !is_array($driveResult)) return false;
    if (!cpms_drive_ensure_project_columns($pdo)) return false;

    $drive = isset($driveResult['drive']) && is_array($driveResult['drive']) ? $driveResult['drive'] : array();
    $status = isset($driveResult['status']) ? trim((string)$driveResult['status']) : '';
    if ($status === '') $status = !empty($driveResult['ok']) ? 'ready' : 'failed';
    if (count($drive) > 0) {
        $drive['status'] = $status;
        if (!isset($drive['synced_at']) || trim((string)$drive['synced_at']) === '') {
            $drive['synced_at'] = date('Y-m-d H:i:s');
        }
    }
    $folderId = isset($drive['project_folder_id']) ? (string)$drive['project_folder_id'] : '';
    $message = isset($driveResult['message']) ? (string)$driveResult['message'] : '';
    if (isset($driveResult['errors']) && is_array($driveResult['errors']) && count($driveResult['errors']) > 0) {
        $message .= ' ' . implode(' / ', $driveResult['errors']);
    }
    if (strlen($message) > 2000) $message = substr($message, 0, 2000);

    try {
        $hasDriveData = (isset($drive['project_folder_id']) && trim((string)$drive['project_folder_id']) !== '')
            || (isset($drive['folders']) && is_array($drive['folders']) && count($drive['folders']) > 0);
        $setParts = array(
            'drive_status = :status',
            'drive_error_message = :error_message',
            'drive_updated_at = :updated_at'
        );
        if ($folderId !== '') {
            array_push($setParts, 'drive_folder_id = :folder_id');
        }
        if ($hasDriveData) {
            array_push($setParts, 'drive_folders_json = :folders_json');
        }
        $st = $pdo->prepare("UPDATE cpms_projects
            SET " . implode(",\n                ", $setParts) . "
            WHERE id = :project_id");
        $st->bindValue(':status', $status);
        if ($folderId !== '') $st->bindValue(':folder_id', $folderId);
        if ($hasDriveData) $st->bindValue(':folders_json', cpms_drive_json_encode($drive));
        $st->bindValue(':error_message', $status === 'ready' ? '' : $message);
        $st->bindValue(':updated_at', date('Y-m-d H:i:s'));
        $st->bindValue(':project_id', $projectId, PDO::PARAM_INT);
        $st->execute();
        return true;
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_drive_sync_project_after_create')) {
function cpms_drive_sync_project_after_create($pdo, $projectId, $projectName, $userContext, $section) {
    if (!is_array($userContext)) $userContext = array();
    $projectId = (int)$projectId;
    $projectName = trim((string)$projectName);
    $section = trim((string)$section);
    if ($section === '') $section = 'project';

    $result = array(
        'ok' => false,
        'saved' => false,
        'drive_result' => array(
            'ok' => false,
            'status' => 'failed',
            'message' => '',
            'drive' => array(),
            'errors' => array()
        ),
        'message' => ''
    );

    if (!$pdo || $projectId <= 0) {
        $result['message'] = 'Project Drive sync skipped because project ID or DB connection is empty.';
        $result['drive_result']['message'] = $result['message'];
        return $result;
    }

    try {
        $driveResult = cpms_drive_create_project_structure($projectId, $projectName, $userContext);
        $saved = cpms_drive_save_project_structure_result($pdo, $projectId, $driveResult);
        $result['drive_result'] = $driveResult;
        $result['saved'] = $saved;
        $result['ok'] = (!empty($driveResult['ok']) && $saved);
        $result['message'] = isset($driveResult['message']) ? (string)$driveResult['message'] : '';

        if (!$saved) {
            cpms_drive_log_upload_failure(array(
                'user' => $userContext,
                'section' => $section,
                'project_id' => $projectId,
                'original_name' => $projectName,
                'target_folder_id' => cpms_drive_folder_id('project_root'),
                'message' => 'Project was saved, but Drive metadata could not be saved to cpms_projects.'
            ));
        }
        return $result;
    } catch (Exception $driveException) {
        $folderName = cpms_drive_project_folder_name($projectName, $projectId);
        $driveResult = array(
            'ok' => false,
            'status' => 'failed',
            'message' => $driveException->getMessage(),
            'drive' => array(
                'status' => 'failed',
                'synced_at' => date('Y-m-d H:i:s'),
                'project_folder_id' => '',
                'project_folder_name' => $folderName,
                'folders' => array()
            ),
            'errors' => array($driveException->getMessage())
        );
        $saved = cpms_drive_save_project_structure_result($pdo, $projectId, $driveResult);
        cpms_drive_log_upload_failure(array(
            'user' => $userContext,
            'section' => $section,
            'project_id' => $projectId,
            'original_name' => $projectName,
            'target_folder_id' => cpms_drive_folder_id('project_root'),
            'message' => 'Project Drive folder creation exception: ' . $driveException->getMessage()
        ));
        $result['drive_result'] = $driveResult;
        $result['saved'] = $saved;
        $result['message'] = $driveException->getMessage();
        return $result;
    }
}}

if (!function_exists('cpms_drive_approval_folder_names')) {
function cpms_drive_approval_folder_names() {
    return array(
        'draft' => urldecode('%EA%B8%B0%EC%95%88%EC%84%9C'),
        'leave' => urldecode('%ED%9C%B4%EA%B0%80%EA%B3%84'),
        'proposal' => urldecode('%ED%92%88%EC%9D%98%EC%84%9C'),
        'expense' => urldecode('%EC%A7%80%EC%B6%9C%EA%B2%B0%EC%9D%98%EC%84%9C'),
        'unused_leave' => urldecode('%EB%AF%B8%EC%82%AC%EC%9A%A9%EC%97%B0%EC%B0%A8'),
        'completed' => urldecode('%EC%99%84%EB%A3%8C%EB%AC%B8%EC%84%9C'),
        'other' => urldecode('%EA%B8%B0%ED%83%80')
    );
}}

if (!function_exists('cpms_drive_approval_folder_name')) {
function cpms_drive_approval_folder_name($key) {
    $names = cpms_drive_approval_folder_names();
    $key = trim((string)$key);
    return isset($names[$key]) ? $names[$key] : $names['other'];
}}

if (!function_exists('cpms_drive_parse_approval_year_month_value')) {
function cpms_drive_parse_approval_year_month_value($value, $defaultYear) {
    $raw = trim((string)$value);
    $defaultYear = (int)$defaultYear > 0 ? (int)$defaultYear : (int)date('Y');
    if ($raw === '') return array('ok' => false, 'year' => '', 'month' => '');

    if (preg_match('/(\d{4})\D{0,5}(\d{1,2})/u', $raw, $m)) {
        $year = (int)$m[1];
        $month = (int)$m[2];
        if ($year > 0 && $month >= 1 && $month <= 12) {
            return array('ok' => true, 'year' => sprintf('%04d', $year), 'month' => sprintf('%02d', $month));
        }
        return array('ok' => false, 'year' => '', 'month' => '');
    }

    if (preg_match('/^\d{1,2}$/', $raw)) {
        $monthOnly = (int)$raw;
        if ($monthOnly >= 1 && $monthOnly <= 12) {
            return array('ok' => true, 'year' => sprintf('%04d', $defaultYear), 'month' => sprintf('%02d', $monthOnly));
        }
        return array('ok' => false, 'year' => '', 'month' => '');
    }

    $ts = strtotime($raw);
    if ($ts !== false) {
        return array('ok' => true, 'year' => date('Y', $ts), 'month' => date('m', $ts));
    }

    return array('ok' => false, 'year' => '', 'month' => '');
}}

if (!function_exists('cpms_drive_approval_month_info')) {
function cpms_drive_approval_month_info($year, $context) {
    if (!is_array($context)) $context = array();
    $year = (int)$year > 0 ? (int)$year : (int)date('Y');
    $raw = '';
    foreach (array('approval_month_value', 'document_date', 'completed_date', 'approval_date', 'date', 'document_month', 'completed_pdf_month', 'month') as $key) {
        if (isset($context[$key]) && trim((string)$context[$key]) !== '') {
            $raw = trim((string)$context[$key]);
            break;
        }
    }

    if ($raw !== '') {
        $parsed = cpms_drive_parse_approval_year_month_value($raw, $year);
        if (!empty($parsed['ok'])) {
            return array(
                'year' => (string)$parsed['year'],
                'month' => (string)$parsed['month'],
                'raw' => $raw,
                'used_fallback' => false,
                'message' => ''
            );
        }

        $message = urldecode('%EC%A0%84%EC%9E%90%EA%B2%B0%EC%9E%AC%20%EC%9B%94%20%EA%B0%92%20%ED%99%95%EC%9D%B8%20%ED%95%84%EC%9A%94') . ': ' . $raw;
        cpms_drive_log_upload_failure(array_merge($context, array('message' => $message)));
        return array(
            'year' => date('Y'),
            'month' => date('m'),
            'raw' => $raw,
            'used_fallback' => true,
            'message' => $message
        );
    }

    $fallbackDate = '';
    foreach (array('fallback_date', 'uploaded_at', 'created_at') as $fallbackKey) {
        if (isset($context[$fallbackKey]) && trim((string)$context[$fallbackKey]) !== '') {
            $fallbackDate = trim((string)$context[$fallbackKey]);
            break;
        }
    }
    $ts = $fallbackDate !== '' ? strtotime($fallbackDate) : false;
    if ($ts === false) $ts = time();

    return array(
        'year' => date('Y', $ts),
        'month' => date('m', $ts),
        'raw' => '',
        'used_fallback' => false,
        'message' => ''
    );
}}

if (!function_exists('cpms_drive_ensure_approval_folder')) {
function cpms_drive_ensure_approval_folder($year, $folderKey, $context) {
    if (!is_array($context)) $context = array();
    $year = (int)$year;
    if ($year <= 0) $year = (int)date('Y');
    $folderKey = trim((string)$folderKey);
    $names = cpms_drive_approval_folder_names();
    if (!isset($names[$folderKey])) $folderKey = 'other';
    $folderName = $names[$folderKey];
    $monthInfo = cpms_drive_approval_month_info($year, $context);
    $year = (int)$monthInfo['year'];
    $month = (string)$monthInfo['month'];
    $approvalRoot = cpms_drive_folder_id('approval');
    $result = array(
        'ok' => false,
        'year' => $year,
        'month' => $month,
        'year_folder_id' => '',
        'type_folder_id' => '',
        'month_folder_id' => '',
        'folder_key' => $folderKey,
        'folder_name' => $folderName,
        'folder_id' => '',
        'message' => '',
        'http_code' => 0
    );
    if ($approvalRoot === '') {
        $result['message'] = 'Approval Drive folder ID is not configured.';
        cpms_drive_log_upload_failure(array_merge($context, array(
            'section' => 'approval',
            'target_folder_id' => '',
            'message' => $result['message']
        )));
        return $result;
    }

    $baseContext = $context;
    $baseContext['section'] = 'approval';
    $baseContext['target_folder_id'] = $approvalRoot;
    $baseContext['original_name'] = (string)$year;
    $yearFolder = cpms_drive_find_or_create_folder((string)$year, $approvalRoot, $baseContext);
    $result['http_code'] = isset($yearFolder['http_code']) ? (int)$yearFolder['http_code'] : 0;
    if (!$yearFolder['ok'] || !is_array($yearFolder['file']) || !isset($yearFolder['file']['id'])) {
        $result['message'] = isset($yearFolder['message']) ? $yearFolder['message'] : 'Approval year folder failed.';
        return $result;
    }

    $yearFolderId = (string)$yearFolder['file']['id'];
    $result['year_folder_id'] = $yearFolderId;
    $childContext = $context;
    $childContext['section'] = 'approval';
    $childContext['target_folder_id'] = $yearFolderId;
    $childContext['original_name'] = $folderName;
    $folder = cpms_drive_find_or_create_folder($folderName, $yearFolderId, $childContext);
    $result['http_code'] = isset($folder['http_code']) ? (int)$folder['http_code'] : $result['http_code'];
    if (!$folder['ok'] || !is_array($folder['file']) || !isset($folder['file']['id'])) {
        $result['message'] = isset($folder['message']) ? $folder['message'] : 'Approval document-type folder failed.';
        return $result;
    }

    $typeFolderId = (string)$folder['file']['id'];
    $result['type_folder_id'] = $typeFolderId;
    $monthContext = $context;
    $monthContext['section'] = 'approval';
    $monthContext['target_folder_id'] = $typeFolderId;
    $monthContext['original_name'] = $month;
    $monthFolder = cpms_drive_find_or_create_folder($month, $typeFolderId, $monthContext);
    $result['http_code'] = isset($monthFolder['http_code']) ? (int)$monthFolder['http_code'] : $result['http_code'];
    if (!$monthFolder['ok'] || !is_array($monthFolder['file']) || !isset($monthFolder['file']['id'])) {
        $result['message'] = isset($monthFolder['message']) ? $monthFolder['message'] : 'Approval month folder failed.';
        return $result;
    }

    $result['ok'] = true;
    $result['month_folder_id'] = (string)$monthFolder['file']['id'];
    $result['folder_id'] = (string)$monthFolder['file']['id'];
    $result['message'] = 'Approval monthly target folder is ready: ' . sprintf('%04d', $year) . ' / ' . $folderName . ' / ' . $month . '.';
    return $result;
}}

if (!function_exists('cpms_drive_ensure_approval_year_folders')) {
function cpms_drive_ensure_approval_year_folders($year, $context) {
    if (!is_array($context)) $context = array();
    $year = (int)$year;
    if ($year <= 0) $year = (int)date('Y');
    $approvalRoot = cpms_drive_folder_id('approval');
    $result = array('ok' => false, 'year' => $year, 'folders' => array(), 'message' => '', 'errors' => array());
    if ($approvalRoot === '') {
        $result['message'] = 'Approval Drive folder ID is not configured.';
        return $result;
    }
    $baseContext = $context;
    $baseContext['section'] = 'approval';
    $baseContext['target_folder_id'] = $approvalRoot;
    $yearFolder = cpms_drive_find_or_create_folder((string)$year, $approvalRoot, $baseContext);
    if (!$yearFolder['ok'] || !is_array($yearFolder['file']) || !isset($yearFolder['file']['id'])) {
        $result['message'] = isset($yearFolder['message']) ? $yearFolder['message'] : 'Approval year folder failed.';
        return $result;
    }
    $yearFolderId = (string)$yearFolder['file']['id'];
    $result['folders']['year'] = $yearFolderId;

    $types = cpms_drive_approval_folder_names();
    foreach ($types as $key => $name) {
        $childContext = $baseContext;
        $childContext['target_folder_id'] = $yearFolderId;
        $folder = cpms_drive_find_or_create_folder($name, $yearFolderId, $childContext);
        if (!$folder['ok'] || !is_array($folder['file']) || !isset($folder['file']['id'])) {
            array_push($result['errors'], $name . ': ' . (isset($folder['message']) ? $folder['message'] : 'folder failed'));
            continue;
        }
        $result['folders'][$key] = (string)$folder['file']['id'];
    }

    if (count($result['errors']) > 0) {
        $result['message'] = 'Approval year folder was created, but some subfolders failed.';
        return $result;
    }
    $result['ok'] = true;
    $result['message'] = 'Approval year folder structure is ready.';
    return $result;
}}

if (!function_exists('cpms_drive_run_connection_check')) {
function cpms_drive_run_connection_check($userContext) {
    $result = array(
        'ok' => false,
        'json' => array('ok' => false, 'message' => ''),
        'token' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'upload' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'delete' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'test_file' => array(),
        'approval_root' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'approval_year_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'approval_type_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'approval_month_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'approval_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'approval_upload' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'approval_delete' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'approval_test_file' => array()
    );

    $read = cpms_drive_read_service_account();
    $result['json'] = array(
        'ok' => $read['ok'],
        'message' => $read['message'],
        'masked_path' => isset($read['masked_path']) ? $read['masked_path'] : '',
        'service_account_email' => isset($read['service_account_email']) ? $read['service_account_email'] : ''
    );
    if (!$read['ok']) return $result;

    $token = cpms_drive_get_access_token();
    $result['token'] = array(
        'ok' => $token['ok'],
        'http_code' => isset($token['http_code']) ? (int)$token['http_code'] : 0,
        'message' => isset($token['message']) ? $token['message'] : ''
    );
    if (!$token['ok']) return $result;

    $tmpDir = cpms_drive_storage_root() . '/tmp';
    if (!cpms_drive_ensure_dir($tmpDir)) {
        $result['upload']['message'] = 'Temporary directory could not be created.';
        return $result;
    }
    $tmpPath = @tempnam($tmpDir, 'drive_check_');
    if ($tmpPath === false || @file_put_contents($tmpPath, "CPMS Google Drive connection check\n" . date('Y-m-d H:i:s') . "\n") === false) {
        $result['upload']['message'] = 'Temporary test file could not be created.';
        return $result;
    }

    $fileName = 'CPMS_Drive_Check_' . date('Ymd_His') . '.txt';
    $context = array(
        'user' => $userContext,
        'section' => 'admin_drive_check',
        'original_name' => $fileName,
        'target_folder_id' => cpms_drive_folder_id('common_documents')
    );
    $upload = cpms_drive_upload_file($tmpPath, $fileName, cpms_drive_folder_id('common_documents'), 'text/plain', $context);
    $result['upload'] = array(
        'ok' => $upload['ok'],
        'http_code' => isset($upload['http_code']) ? (int)$upload['http_code'] : 0,
        'message' => isset($upload['message']) ? $upload['message'] : ''
    );
    if ($upload['ok'] && isset($upload['file']) && is_array($upload['file'])) {
        $result['test_file'] = array(
            'id' => isset($upload['file']['id']) ? (string)$upload['file']['id'] : '',
            'name' => isset($upload['file']['name']) ? (string)$upload['file']['name'] : '',
            'webViewLink' => isset($upload['file']['webViewLink']) ? (string)$upload['file']['webViewLink'] : ''
        );
        $delete = cpms_drive_delete_file($result['test_file']['id'], $context);
        $result['delete'] = array(
            'ok' => $delete['ok'],
            'http_code' => isset($delete['http_code']) ? (int)$delete['http_code'] : 0,
            'message' => isset($delete['message']) ? $delete['message'] : ''
        );
    }

    $approvalContext = array(
        'user' => $userContext,
        'section' => 'admin_drive_check_approval',
        'document_type' => cpms_drive_approval_folder_name('other'),
        'original_name' => $fileName,
        'target_folder_id' => cpms_drive_folder_id('approval'),
        'document_date' => date('Y-m-d'),
        'fallback_date' => date('Y-m-d H:i:s')
    );
    $approvalRootId = cpms_drive_folder_id('approval');
    if ($approvalRootId !== '') {
        $approvalRootInfo = cpms_drive_get_file_info($approvalRootId);
        $result['approval_root'] = array(
            'ok' => !empty($approvalRootInfo['ok']),
            'http_code' => isset($approvalRootInfo['http_code']) ? (int)$approvalRootInfo['http_code'] : 0,
            'message' => isset($approvalRootInfo['message']) ? $approvalRootInfo['message'] : ''
        );
    } else {
        $result['approval_root']['message'] = 'Approval Drive folder ID is not configured.';
    }
    $approvalFolder = cpms_drive_ensure_approval_folder((int)date('Y'), 'other', $approvalContext);
    $result['approval_folder'] = array(
        'ok' => !empty($approvalFolder['ok']),
        'http_code' => isset($approvalFolder['http_code']) ? (int)$approvalFolder['http_code'] : 0,
        'message' => isset($approvalFolder['message']) ? $approvalFolder['message'] : ''
    );
    $result['approval_year_folder'] = array(
        'ok' => (!empty($approvalFolder['ok']) && isset($approvalFolder['year_folder_id']) && trim((string)$approvalFolder['year_folder_id']) !== ''),
        'http_code' => isset($approvalFolder['http_code']) ? (int)$approvalFolder['http_code'] : 0,
        'message' => !empty($approvalFolder['ok']) ? ('Approval year folder is ready: ' . (isset($approvalFolder['year']) ? (string)$approvalFolder['year'] : date('Y')) . '.') : (isset($approvalFolder['message']) ? $approvalFolder['message'] : '')
    );
    $result['approval_type_folder'] = array(
        'ok' => (!empty($approvalFolder['ok']) && isset($approvalFolder['type_folder_id']) && trim((string)$approvalFolder['type_folder_id']) !== ''),
        'http_code' => isset($approvalFolder['http_code']) ? (int)$approvalFolder['http_code'] : 0,
        'message' => !empty($approvalFolder['ok']) ? ('Approval document-type folder is ready: ' . cpms_drive_approval_folder_name('other') . '.') : (isset($approvalFolder['message']) ? $approvalFolder['message'] : '')
    );
    $result['approval_month_folder'] = array(
        'ok' => (!empty($approvalFolder['ok']) && isset($approvalFolder['month_folder_id']) && trim((string)$approvalFolder['month_folder_id']) !== ''),
        'http_code' => isset($approvalFolder['http_code']) ? (int)$approvalFolder['http_code'] : 0,
        'message' => !empty($approvalFolder['ok']) ? ('Approval month folder is ready: ' . (isset($approvalFolder['month']) ? (string)$approvalFolder['month'] : date('m')) . '.') : (isset($approvalFolder['message']) ? $approvalFolder['message'] : '')
    );
    if (!empty($approvalFolder['ok']) && isset($approvalFolder['folder_id']) && trim((string)$approvalFolder['folder_id']) !== '') {
        $approvalContext['target_folder_id'] = (string)$approvalFolder['folder_id'];
        $approvalContext['drive_folder_id'] = (string)$approvalFolder['folder_id'];
        $approvalUpload = cpms_drive_upload_file($tmpPath, 'CPMS_Approval_Drive_Check_' . date('Ymd_His') . '.txt', (string)$approvalFolder['folder_id'], 'text/plain', $approvalContext);
        $result['approval_upload'] = array(
            'ok' => $approvalUpload['ok'],
            'http_code' => isset($approvalUpload['http_code']) ? (int)$approvalUpload['http_code'] : 0,
            'message' => isset($approvalUpload['message']) ? $approvalUpload['message'] : ''
        );
        if ($approvalUpload['ok'] && isset($approvalUpload['file']) && is_array($approvalUpload['file'])) {
            $result['approval_test_file'] = array(
                'id' => isset($approvalUpload['file']['id']) ? (string)$approvalUpload['file']['id'] : '',
                'name' => isset($approvalUpload['file']['name']) ? (string)$approvalUpload['file']['name'] : '',
                'webViewLink' => isset($approvalUpload['file']['webViewLink']) ? (string)$approvalUpload['file']['webViewLink'] : ''
            );
            if ($result['approval_test_file']['id'] !== '') {
                $approvalDelete = cpms_drive_delete_file($result['approval_test_file']['id'], $approvalContext);
                $result['approval_delete'] = array(
                    'ok' => $approvalDelete['ok'],
                    'http_code' => isset($approvalDelete['http_code']) ? (int)$approvalDelete['http_code'] : 0,
                    'message' => (isset($approvalDelete['message']) ? $approvalDelete['message'] : '') . ' / file_id=' . $result['approval_test_file']['id']
                );
            } else {
                $result['approval_delete'] = array('ok' => false, 'http_code' => 0, 'message' => 'Upload response did not include a Drive file ID.');
            }
        }
    }

    @unlink($tmpPath);
    $result['ok'] = (!empty($result['json']['ok']) && !empty($result['token']['ok']) && !empty($result['upload']['ok']) && !empty($result['delete']['ok']) && !empty($result['approval_root']['ok']) && !empty($result['approval_year_folder']['ok']) && !empty($result['approval_type_folder']['ok']) && !empty($result['approval_month_folder']['ok']) && !empty($result['approval_upload']['ok']) && !empty($result['approval_delete']['ok']));
    return $result;
}}
