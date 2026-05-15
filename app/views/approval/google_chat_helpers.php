<?php
function approval_google_chat_set_last_error($message) {
    $GLOBALS['approval_google_chat_last_error'] = (string)$message;
}

function approval_google_chat_get_last_error() {
    if (isset($GLOBALS['approval_google_chat_last_error'])) {
        return (string)$GLOBALS['approval_google_chat_last_error'];
    }
    return '';
}

function approval_google_chat_setting($pdo, $key, $defaultValue) {
    try {
        $st = $pdo->prepare("SELECT setting_value FROM cpms_approval_settings WHERE setting_key=:k LIMIT 1");
        $st->execute(array(':k' => $key));
        $v = $st->fetchColumn();
        if ($v === false || $v === null || $v === '') {
            return $defaultValue;
        }
        return (string)$v;
    } catch (Exception $e) {
        return $defaultValue;
    }
}

function approval_google_chat_base64url($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function approval_google_chat_http_request($method, $url, $headers, $body) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return array('status' => $status, 'body' => $resp, 'error' => $err);
}

function approval_google_chat_get_access_token($pdo) {
    $jsonPath = approval_google_chat_setting($pdo, 'google_chat_service_account_json_path', '/www/cpms/storage/secrets/google-chat-service-account.json');
    if ($jsonPath === '' || !is_file($jsonPath)) {
        approval_google_chat_set_last_error('서비스 계정 JSON 파일을 찾을 수 없습니다.');        
        error_log('[google_chat] json path invalid');
        return false;
    }
    if (!is_readable($jsonPath)) {
        approval_google_chat_set_last_error('서비스 계정 JSON 파일은 있으나 읽을 수 없습니다.');
        error_log('[google_chat] json not readable');
        return false;
    }    
    $raw = @file_get_contents($jsonPath);
    if ($raw === false) {
        approval_google_chat_set_last_error('서비스 계정 JSON 파일은 있으나 읽을 수 없습니다.');        
        error_log('[google_chat] json read fail');
        return false;
    }
    $sa = json_decode($raw, true);
    if (!is_array($sa)) {
        error_log('[google_chat] json decode fail');
        return false;
    }
    $clientEmail = isset($sa['client_email']) ? (string)$sa['client_email'] : '';
    $privateKey = isset($sa['private_key']) ? (string)$sa['private_key'] : '';
    $tokenUri = isset($sa['token_uri']) ? (string)$sa['token_uri'] : 'https://oauth2.googleapis.com/token';
    if ($clientEmail === '' || $privateKey === '') {
        error_log('[google_chat] key missing');
        return false;
    }
    $scope = approval_google_chat_setting($pdo, 'google_chat_oauth_scope', 'https://www.googleapis.com/auth/chat.bot');
    $impersonationUser = approval_google_chat_setting($pdo, 'google_chat_impersonation_user', '');
    $scope = trim((string)$scope);    
    $impersonationUser = trim((string)$impersonationUser);
    if ($impersonationUser !== '' && strpos($impersonationUser, 'users/') === 0) {
        approval_google_chat_set_last_error('google_chat_impersonation_user에는 users/를 붙이지 말고 회사 Google Workspace 이메일만 입력해주세요.');
        error_log('[google_chat] impersonation user invalid value=' . $impersonationUser);
        return false;
    }        
    $now = time();
    $header = approval_google_chat_base64url(json_encode(array('alg' => 'RS256', 'typ' => 'JWT')));
    $payloadArray = array(
        'iss' => $clientEmail,
        'scope' => $scope,
        'aud' => $tokenUri,
        'iat' => $now,
        'exp' => $now + 3600
    );
    if ($impersonationUser !== '') {
        $payloadArray['sub'] = $impersonationUser;
    }
    $payload = approval_google_chat_base64url(json_encode($payloadArray));
    $unsigned = $header . '.' . $payload;
    $signature = '';
    if (!openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
        error_log('[google_chat] sign fail');
        return false;
    }
    $jwt = $unsigned . '.' . approval_google_chat_base64url($signature);
    $resp = approval_google_chat_http_request('POST', $tokenUri, array('Content-Type: application/x-www-form-urlencoded'), http_build_query(array('grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt)));
    $arr = json_decode((string)$resp['body'], true);
    if (!is_array($arr) || empty($arr['access_token'])) {
        $safeError = 'Access Token 발급 실패';
        $status = isset($resp['status']) ? (int)$resp['status'] : 0;
        if ($status > 0) {
            $safeError .= "\n상태: HTTP " . $status;
        }
        $errorCode = '';
        $errorDescription = '';
        $errorUri = '';
        if (is_array($arr)) {
            if (isset($arr['error'])) {
                $errorCode = trim((string)$arr['error']);
            }
            if (isset($arr['error_description'])) {
                $errorDescription = trim((string)$arr['error_description']);
            }
            if (isset($arr['error_uri'])) {
                $errorUri = trim((string)$arr['error_uri']);
            }
        }
        if ($errorCode !== '') {
            $safeError .= "\n오류: " . $errorCode;
        }
        if ($errorDescription !== '') {
            $safeError .= "\n설명: " . $errorDescription;
        }
        if ($errorUri !== '') {
            $safeError .= "\n안내: " . $errorUri;
        }
        if ($errorCode === 'invalid_scope') {
            $safeError .= "\n\n해결 안내:\n- CPMS google_chat_oauth_scope 값에 쉼표가 들어갔는지 확인\n- 관리자 콘솔 OAuth 범위와 CPMS 범위가 맞는지 확인";
        } elseif ($errorCode === 'unauthorized_client') {
            $safeError .= "\n\n해결 안내:\n- Google Cloud 서비스 계정의 도메인 전체 위임이 켜져 있는지 확인\n- Google Workspace 관리자 콘솔의 도메인 전체 위임에 Client ID가 등록되어 있는지 확인";
        } elseif ($errorCode === 'invalid_grant') {
            $safeError .= "\n\n해결 안내:\n- google_chat_impersonation_user가 실제 회사 Google Workspace 계정인지 확인\n- users/ 접두사가 붙어 있지 않은지 확인\n- 서버 시간이 크게 틀어져 있지 않은지 확인\n- 서비스 계정 JSON 키가 폐기된 키가 아닌지 확인";
        }
        if (strlen($safeError) > 1000) {
            $safeError = substr($safeError, 0, 1000);
        }
        approval_google_chat_set_last_error($safeError);    
        error_log('[google_chat] token fail status=' . $resp['status'] . ' body=' . (string)$resp['body']);
        return false;
    }
    return (string)$arr['access_token'];
}

function approval_google_chat_api_post($pdo, $url, $bodyArray) {
    approval_google_chat_set_last_error('');    
    $token = approval_google_chat_get_access_token($pdo);
    if ($token === false) {
        return array('ok' => false, 'status' => 0, 'body' => '');
    }
    $resp = approval_google_chat_http_request('POST', $url, array('Authorization: Bearer ' . $token, 'Content-Type: application/json; charset=utf-8'), json_encode($bodyArray));
    $ok = ($resp['status'] >= 200 && $resp['status'] < 300);
    if (!$ok) {
        if ((int)$resp['status'] === 403) {
            $statusText = '';
            $messageText = '';
            $respBodyArray = json_decode((string)$resp['body'], true);
            if (is_array($respBodyArray) && isset($respBodyArray['error']) && is_array($respBodyArray['error'])) {
                if (isset($respBodyArray['error']['status'])) {
                    $statusText = trim((string)$respBodyArray['error']['status']);
                }
                if (isset($respBodyArray['error']['message'])) {
                    $messageText = trim((string)$respBodyArray['error']['message']);
                }
            }
            $safeError = 'Google Chat API 403 권한 오류';
            if ($statusText !== '') {
                $safeError .= "\n상태: " . $statusText;
            }
            if ($messageText !== '') {
                $safeError .= "\n메시지: " . $messageText;
            }
            if (strlen($safeError) > 1000) {
                $safeError = substr($safeError, 0, 1000);
            }
            approval_google_chat_set_last_error($safeError);
        } elseif ((int)$resp['status'] === 400) {
            approval_google_chat_set_last_error('Google Chat API 400 요청 오류');
        } else {
            approval_google_chat_set_last_error('Google Chat API 호출 실패 (HTTP ' . (int)$resp['status'] . ')');
        }        
        error_log('[google_chat] post fail status=' . $resp['status'] . ' body=' . (string)$resp['body']);
    }
    return array('ok' => $ok, 'status' => $resp['status'], 'body' => $resp['body']);
}

function approval_google_chat_setup_dm_space($pdo, $userName) {
    $url = 'https://chat.googleapis.com/v1/spaces:setup';
    $body = array(
        'space' => array('spaceType' => 'DIRECT_MESSAGE', 'singleUserBotDm' => true),
        'memberships' => array(
            array('member' => array('name' => $userName, 'type' => 'HUMAN'))
        )
    );
    $res = approval_google_chat_api_post($pdo, $url, $body);
    if (!$res['ok']) {
        return false;
    }
    $arr = json_decode((string)$res['body'], true);
    if (!is_array($arr) || empty($arr['name'])) {
        error_log('[google_chat] setup dm invalid response status=' . $res['status']);
        return false;
    }
    return (string)$arr['name'];
}

function approval_google_chat_send_message($pdo, $spaceName, $messageText) {
    $url = 'https://chat.googleapis.com/v1/' . trim((string)$spaceName) . '/messages';
    $res = approval_google_chat_api_post($pdo, $url, array('text' => $messageText));
    if (!$res['ok']) {
        return false;
    }
    $arr = json_decode((string)$res['body'], true);
    return (is_array($arr) && !empty($arr['name']));
}

function approval_google_chat_employee_column_exists($pdo, $column) {
    try {
        $dbName = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
        if ($dbName === '') {
            return false;
        }
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=:db AND TABLE_NAME='employees' AND COLUMN_NAME=:col");
        $st->execute(array(':db' => $dbName, ':col' => $column));
        return ((int)$st->fetchColumn() > 0);
    } catch (Exception $e) {
        return false;
    }
}

function approval_google_chat_get_employee_for_dm($pdo, $employeeId) {
    $columns = array('id', 'name', 'email');
    if (approval_google_chat_employee_column_exists($pdo, 'google_chat_enabled')) {
        $columns[] = 'google_chat_enabled';
    } else {
        $columns[] = '0 AS google_chat_enabled';
    }
    if (approval_google_chat_employee_column_exists($pdo, 'google_chat_user_name')) {
        $columns[] = 'google_chat_user_name';
    } else {
        $columns[] = "'' AS google_chat_user_name";
    }
    if (approval_google_chat_employee_column_exists($pdo, 'google_chat_dm_space_name')) {
        $columns[] = 'google_chat_dm_space_name';
    } else {
        $columns[] = "'' AS google_chat_dm_space_name";
    }

    $sql = 'SELECT ' . implode(',', $columns) . ' FROM employees WHERE id=:id LIMIT 1';
    $st = $pdo->prepare($sql);
    $st->execute(array(':id' => (int)$employeeId));
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return false;
    }
    return $row;
}

function approval_google_chat_save_employee_chat_fields($pdo, $employeeId, $fieldMap) {
    if (!is_array($fieldMap) || empty($fieldMap)) {
        return true;
    }

    $setParts = array();
    $params = array(':id' => (int)$employeeId);
    foreach ($fieldMap as $col => $val) {
        if (!approval_google_chat_employee_column_exists($pdo, $col)) {
            continue;
        }
        $paramName = ':v_' . $col;
        $setParts[] = $col . '=' . $paramName;
        $params[$paramName] = $val;
    }

    if (empty($setParts)) {
        return true;
    }

    $sql = 'UPDATE employees SET ' . implode(',', $setParts) . ' WHERE id=:id';
    $st = $pdo->prepare($sql);
    return $st->execute($params);
}