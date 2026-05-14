<?php
function approval_google_chat_setting($pdo, $key, $defaultValue) {
    try {
        $st = $pdo->prepare("SELECT setting_value FROM cpms_approval_settings WHERE setting_key=:k LIMIT 1");
        $st->execute(array(':k'=>$key));
        $v = $st->fetchColumn();
        if ($v === false || $v === null || $v === '') { return $defaultValue; }
        return (string)$v;
    } catch (Exception $e) { return $defaultValue; }
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
    if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
    $resp = curl_exec($ch); $err = curl_error($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return array('status'=>$status, 'body'=>$resp, 'error'=>$err);
}
function approval_google_chat_get_access_token($pdo) {
    $jsonPath = approval_google_chat_setting($pdo, 'google_chat_service_account_json_path', '');
    if ($jsonPath === '' || !is_file($jsonPath)) { error_log('[google_chat] json path invalid'); return false; }
    $raw = @file_get_contents($jsonPath); if ($raw === false) { error_log('[google_chat] json read fail'); return false; }
    $sa = json_decode($raw, true); if (!is_array($sa)) { error_log('[google_chat] json decode fail'); return false; }
    $clientEmail = isset($sa['client_email']) ? (string)$sa['client_email'] : '';
    $privateKey = isset($sa['private_key']) ? (string)$sa['private_key'] : '';
    $tokenUri = isset($sa['token_uri']) ? (string)$sa['token_uri'] : 'https://oauth2.googleapis.com/token';
    if ($clientEmail==='' || $privateKey==='') { error_log('[google_chat] key missing'); return false; }
    $scope = approval_google_chat_setting($pdo, 'google_chat_oauth_scope', 'https://www.googleapis.com/auth/chat.bot');
    $now = time();
    $header = approval_google_chat_base64url(json_encode(array('alg'=>'RS256','typ'=>'JWT')));
    $payload = approval_google_chat_base64url(json_encode(array('iss'=>$clientEmail,'scope'=>$scope,'aud'=>$tokenUri,'iat'=>$now,'exp'=>$now+3600)));
    $unsigned = $header.'.'.$payload;
    $signature = '';
    if (!openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256)) { error_log('[google_chat] sign fail'); return false; }
    $jwt = $unsigned.'.'.approval_google_chat_base64url($signature);
    $resp = approval_google_chat_http_request('POST', $tokenUri, array('Content-Type: application/x-www-form-urlencoded'), http_build_query(array('grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer','assertion'=>$jwt)));
    $arr = json_decode((string)$resp['body'], true);
    if (!is_array($arr) || empty($arr['access_token'])) { error_log('[google_chat] token fail status='.$resp['status'].' body='.(string)$resp['body']); return false; }
    return (string)$arr['access_token'];
}
function approval_google_chat_api_post($pdo, $url, $bodyArray) {
    $token = approval_google_chat_get_access_token($pdo); if ($token === false) return array('ok'=>false,'status'=>0,'body'=>'');
    $resp = approval_google_chat_http_request('POST', $url, array('Authorization: Bearer '.$token, 'Content-Type: application/json; charset=utf-8'), json_encode($bodyArray));
    $ok = ($resp['status'] >= 200 && $resp['status'] < 300);
    if (!$ok) { error_log('[google_chat] post fail status='.$resp['status'].' body='.(string)$resp['body']); }
    return array('ok'=>$ok,'status'=>$resp['status'],'body'=>$resp['body']);
}
function approval_google_chat_setup_dm_space($pdo, $userName) {
    $url = 'https://chat.googleapis.com/v1/spaces:setup';
    $body = array('space'=>array('spaceType'=>'DIRECT_MESSAGE','singleUserBotDm'=>true),'memberships'=>array(array('member'=>array('name'=>$userName,'type'=>'HUMAN'))));
    $res = approval_google_chat_api_post($pdo, $url, $body); if (!$res['ok']) return false;
    $arr = json_decode((string)$res['body'], true); if (!is_array($arr) || empty($arr['name'])) return false;
    return (string)$arr['name'];
}
function approval_google_chat_send_message($pdo, $spaceName, $messageText) {
    $url = 'https://chat.googleapis.com/v1/'.trim((string)$spaceName).'/messages';
    $res = approval_google_chat_api_post($pdo, $url, array('text'=>$messageText));
    if (!$res['ok']) return false;
    $arr = json_decode((string)$res['body'], true);
    return (is_array($arr) && !empty($arr['name']));
}