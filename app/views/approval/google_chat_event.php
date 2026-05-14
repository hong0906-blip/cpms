<?php
if ($_SERVER['REQUEST_METHOD'] === 'GET') { header('Content-Type: text/plain; charset=utf-8'); echo 'CPMS Google Chat endpoint OK'; exit; }
$raw = file_get_contents('php://input');
if ($raw !== false && $raw !== '') { error_log('[google_chat_event] '.substr($raw, 0, 5000)); }
header('Content-Type: application/json; charset=utf-8');
echo json_encode(array('text'=>'CPMS 전자결재 알림봇이 준비되었습니다.'));
exit;