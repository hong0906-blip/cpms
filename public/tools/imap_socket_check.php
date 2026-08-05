<?php
/**
 * 파일 경로: /public/tools/imap_socket_check.php
 *
 * 목적:
 * - PHP IMAP 확장 기능이 없어도 네이버 IMAP 서버에 직접 SSL 연결합니다.
 * - 네이버 애플리케이션 비밀번호로 로그인 가능한지 확인합니다.
 * - 받은메일함 선택, 전체 메일 수, 최근 메일 제목 최대 5개를 확인합니다.
 *
 * PHP 기준:
 * - PHP 5.6 호환
 *
 * 보안:
 * - 아이디와 비밀번호는 화면에서만 입력합니다.
 * - 비밀번호를 파일, 세션, 쿠키에 저장하지 않습니다.
 * - HTTPS가 아니면 검사를 실행하지 않습니다.
 * - 점검이 끝나면 서버에서 이 파일을 바로 삭제하세요.
 */

header('Content-Type: text/html; charset=UTF-8');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

@ini_set('display_errors', '0');
@ini_set('log_errors', '0');
@set_time_limit(40);

/**
 * HTML 특수문자를 안전하게 표시합니다.
 */
function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * 현재 접속이 HTTPS인지 확인합니다.
 */
function isHttpsRequest()
{
    if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    if (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
        return true;
    }

    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO']);
        $proto = strtolower(trim($parts[0]));

        if ($proto === 'https') {
            return true;
        }
    }

    return false;
}

/**
 * IMAP LOGIN 명령에 사용할 값을 안전하게 따옴표 처리합니다.
 */
function imapQuote($value)
{
    $value = str_replace('\\', '\\\\', $value);
    $value = str_replace('"', '\\"', $value);

    return '"' . $value . '"';
}

/**
 * IMAP 응답 상태를 읽습니다.
 *
 * 반환값:
 * - ok: 명령 성공 여부
 * - status: OK, NO, BAD 등
 * - final: 마지막 완료 응답
 * - lines: 일반 응답 줄
 * - literals: 서버가 길이를 지정해 보낸 실제 데이터
 * - error: 연결 읽기 오류
 */
function readTaggedResponse($socket, $tag)
{
    $result = array(
        'ok' => false,
        'status' => '',
        'final' => '',
        'lines' => array(),
        'literals' => array(),
        'error' => ''
    );

    while (!feof($socket)) {
        $line = @fgets($socket, 16384);

        if ($line === false) {
            $meta = @stream_get_meta_data($socket);

            if (is_array($meta) && !empty($meta['timed_out'])) {
                $result['error'] = '서버 응답 시간이 초과되었습니다.';
            } else {
                $result['error'] = '서버 응답을 읽지 못했습니다.';
            }

            break;
        }

        $result['lines'][] = $line;

        /*
         * IMAP은 메일 제목이나 본문처럼 길이가 정해진 데이터를
         * {숫자} 형태로 알린 후 정확한 바이트 수만큼 전송합니다.
         */
        if (preg_match('/\{([0-9]+)\}\r?\n$/', $line, $matches)) {
            $remaining = (int)$matches[1];
            $literal = '';

            while ($remaining > 0 && !feof($socket)) {
                $readLength = ($remaining > 8192) ? 8192 : $remaining;
                $chunk = @fread($socket, $readLength);

                if ($chunk === false || $chunk === '') {
                    $meta = @stream_get_meta_data($socket);

                    if (is_array($meta) && !empty($meta['timed_out'])) {
                        $result['error'] = '메일 데이터를 읽는 중 시간이 초과되었습니다.';
                    } else {
                        $result['error'] = '메일 데이터를 끝까지 읽지 못했습니다.';
                    }

                    break 2;
                }

                $literal .= $chunk;
                $remaining -= strlen($chunk);
            }

            $result['literals'][] = $literal;
        }

        if (strpos($line, $tag . ' ') === 0) {
            $result['final'] = trim($line);

            if (preg_match('/^' . preg_quote($tag, '/') . '\s+(OK|NO|BAD)\b/i', $line, $statusMatch)) {
                $result['status'] = strtoupper($statusMatch[1]);
                $result['ok'] = ($result['status'] === 'OK');
            }

            break;
        }
    }

    return $result;
}

/**
 * IMAP 명령을 서버에 보내고 완료 응답까지 읽습니다.
 */
function sendImapCommand($socket, $tag, $command)
{
    $written = @fwrite($socket, $tag . ' ' . $command . "\r\n");

    if ($written === false) {
        return array(
            'ok' => false,
            'status' => '',
            'final' => '',
            'lines' => array(),
            'literals' => array(),
            'error' => 'IMAP 명령을 서버에 보내지 못했습니다.'
        );
    }

    return readTaggedResponse($socket, $tag);
}

/**
 * MIME 형식으로 인코딩된 한글 제목을 UTF-8로 변환합니다.
 */
function decodeMimeHeaderValue($value)
{
    $value = trim((string)$value);

    if ($value === '') {
        return '';
    }

    if (function_exists('iconv_mime_decode')) {
        $decoded = @iconv_mime_decode(
            $value,
            ICONV_MIME_DECODE_CONTINUE_ON_ERROR,
            'UTF-8'
        );

        if ($decoded !== false && $decoded !== '') {
            return $decoded;
        }
    }

    if (function_exists('mb_decode_mimeheader')) {
        $decoded = @mb_decode_mimeheader($value);

        if ($decoded !== false && $decoded !== '') {
            return $decoded;
        }
    }

    return $value;
}

/**
 * 메일 헤더에서 원하는 항목을 찾습니다.
 */
function getHeaderValue($headerText, $headerName)
{
    /*
     * 여러 줄로 접힌 메일 헤더를 한 줄로 합칩니다.
     */
    $unfolded = preg_replace("/\r?\n[ \t]+/", ' ', (string)$headerText);
    $pattern = '/^' . preg_quote($headerName, '/') . ':\s*(.*)$/mi';

    if (preg_match($pattern, $unfolded, $matches)) {
        return trim($matches[1]);
    }

    return '';
}

/**
 * 화면 검사 결과 한 줄을 추가합니다.
 */
function addResult(&$results, $name, $status, $message)
{
    $results[] = array(
        'name' => $name,
        'status' => $status,
        'message' => $message
    );
}

/**
 * 로그인 실패 메시지를 이해하기 쉽게 정리합니다.
 */
function friendlyLoginError($response)
{
    $text = strtolower((string)$response);

    if (
        strpos($text, 'authentication') !== false ||
        strpos($text, 'login failed') !== false ||
        strpos($text, 'invalid') !== false ||
        strpos($text, 'auth') !== false
    ) {
        return '로그인 인증에 실패했습니다. 네이버 IMAP 사용 설정, 2단계 인증, 애플리케이션 비밀번호를 확인하세요.';
    }

    if (trim($response) !== '') {
        return '네이버 로그인에 실패했습니다. 서버 응답: ' . trim($response);
    }

    return '네이버 로그인에 실패했습니다.';
}

/**
 * 결과 상태 한글 표시입니다.
 */
function statusLabel($status)
{
    if ($status === 'success') {
        return '정상';
    }

    if ($status === 'warning') {
        return '확인 필요';
    }

    return '실패';
}

$isHttps = isHttpsRequest();
$isPost = isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST';

$results = array();
$recentMails = array();
$submittedUsername = '';

if ($isPost) {
    $submittedUsername = isset($_POST['username'])
        ? trim((string)$_POST['username'])
        : '';

    $appPassword = isset($_POST['app_password'])
        ? trim((string)$_POST['app_password'])
        : '';

    if (!$isHttps) {
        addResult(
            $results,
            'HTTPS 보안 접속',
            'fail',
            '현재 페이지가 HTTPS가 아니므로 비밀번호 보호를 위해 검사를 중단했습니다.'
        );
    } elseif ($submittedUsername === '' || $appPassword === '') {
        addResult(
            $results,
            '입력값 확인',
            'fail',
            '네이버 아이디와 애플리케이션 비밀번호를 모두 입력하세요.'
        );
    } elseif (
        strpos($submittedUsername, "\r") !== false ||
        strpos($submittedUsername, "\n") !== false ||
        strpos($appPassword, "\r") !== false ||
        strpos($appPassword, "\n") !== false
    ) {
        addResult(
            $results,
            '입력값 확인',
            'fail',
            '아이디 또는 비밀번호에 사용할 수 없는 줄바꿈 문자가 포함되어 있습니다.'
        );
    } else {
        addResult(
            $results,
            'HTTPS 보안 접속',
            'success',
            '현재 페이지가 HTTPS로 열렸습니다.'
        );

        addResult(
            $results,
            'PHP 버전',
            version_compare(PHP_VERSION, '5.6.0', '>=') ? 'success' : 'warning',
            '현재 PHP 버전: ' . PHP_VERSION
        );

        if (!extension_loaded('openssl')) {
            addResult(
                $results,
                'PHP OpenSSL 기능',
                'fail',
                'PHP OpenSSL 기능이 없어 SSL 연결을 실행할 수 없습니다.'
            );
        } else {
            addResult(
                $results,
                'PHP OpenSSL 기능',
                'success',
                'SSL 보안 연결 기능을 사용할 수 있습니다.'
            );

            $host = 'imap.naver.com';
            $port = 993;
            $timeout = 15;

            $context = stream_context_create(array(
                'ssl' => array(
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'allow_self_signed' => false,
                    'peer_name' => $host,
                    'SNI_enabled' => true
                )
            ));

            $errorNumber = 0;
            $errorMessage = '';

            $socket = @stream_socket_client(
                'ssl://' . $host . ':' . $port,
                $errorNumber,
                $errorMessage,
                $timeout,
                STREAM_CLIENT_CONNECT,
                $context
            );

            if (!is_resource($socket)) {
                addResult(
                    $results,
                    '네이버 SSL 연결',
                    'fail',
                    'imap.naver.com:993 연결에 실패했습니다. 오류번호: '
                    . $errorNumber . ' / ' . $errorMessage
                );
            } else {
                @stream_set_timeout($socket, 15);

                addResult(
                    $results,
                    '네이버 SSL 연결',
                    'success',
                    'imap.naver.com:993에 SSL 방식으로 연결했습니다.'
                );

                /*
                 * 서버가 처음 보내는 환영 메시지를 확인합니다.
                 */
                $greeting = @fgets($socket, 16384);

                if ($greeting === false || stripos($greeting, '* OK') !== 0) {
                    addResult(
                        $results,
                        'IMAP 서버 응답',
                        'fail',
                        '네이버 IMAP 서버의 시작 응답을 정상적으로 받지 못했습니다.'
                    );
                } else {
                    addResult(
                        $results,
                        'IMAP 서버 응답',
                        'success',
                        '네이버 IMAP 서버가 정상적으로 응답했습니다.'
                    );

                    /*
                     * 1. 로그인 검사
                     */
                    $loginResponse = sendImapCommand(
                        $socket,
                        'A001',
                        'LOGIN ' . imapQuote($submittedUsername) . ' ' . imapQuote($appPassword)
                    );

                    if (!$loginResponse['ok']) {
                        $loginError = $loginResponse['error'] !== ''
                            ? $loginResponse['error']
                            : friendlyLoginError($loginResponse['final']);

                        addResult(
                            $results,
                            '네이버 계정 로그인',
                            'fail',
                            $loginError
                        );
                    } else {
                        addResult(
                            $results,
                            '네이버 계정 로그인',
                            'success',
                            '애플리케이션 비밀번호로 로그인했습니다.'
                        );

                        /*
                         * 2. 받은메일함 선택
                         */
                        $selectResponse = sendImapCommand(
                            $socket,
                            'A002',
                            'SELECT "INBOX"'
                        );

                        if (!$selectResponse['ok']) {
                            addResult(
                                $results,
                                '받은메일함 접근',
                                'fail',
                                $selectResponse['error'] !== ''
                                    ? $selectResponse['error']
                                    : '받은메일함을 열지 못했습니다. 서버 응답: ' . $selectResponse['final']
                            );
                        } else {
                            $messageCount = 0;
                            $unseenSequence = null;

                            foreach ($selectResponse['lines'] as $line) {
                                if (preg_match('/^\*\s+([0-9]+)\s+EXISTS\b/i', $line, $existsMatch)) {
                                    $messageCount = (int)$existsMatch[1];
                                }

                                if (preg_match('/\[UNSEEN\s+([0-9]+)\]/i', $line, $unseenMatch)) {
                                    $unseenSequence = (int)$unseenMatch[1];
                                }
                            }

                            $mailboxMessage = '받은메일함을 정상적으로 열었습니다. 전체 메일 수: '
                                . $messageCount;

                            if ($unseenSequence !== null) {
                                $mailboxMessage .= ' / 첫 번째 읽지 않은 메일 순번: ' . $unseenSequence;
                            }

                            addResult(
                                $results,
                                '받은메일함 접근',
                                'success',
                                $mailboxMessage
                            );

                            /*
                             * 3. 최근 메일 최대 5개의 제목, 발신자, 날짜를 확인합니다.
                             * BODY.PEEK을 사용하므로 읽음 상태를 변경하지 않습니다.
                             */
                            if ($messageCount > 0) {
                                $firstSequence = $messageCount - 4;

                                if ($firstSequence < 1) {
                                    $firstSequence = 1;
                                }

                                $fetchTagNumber = 3;

                                for ($sequence = $messageCount; $sequence >= $firstSequence; $sequence--) {
                                    $tag = 'A' . str_pad(
                                        (string)$fetchTagNumber,
                                        3,
                                        '0',
                                        STR_PAD_LEFT
                                    );

                                    $fetchResponse = sendImapCommand(
                                        $socket,
                                        $tag,
                                        'FETCH ' . $sequence
                                        . ' BODY.PEEK[HEADER.FIELDS (SUBJECT FROM DATE MESSAGE-ID)]'
                                    );

                                    if ($fetchResponse['ok'] && !empty($fetchResponse['literals'])) {
                                        $headerText = $fetchResponse['literals'][0];

                                        $subject = decodeMimeHeaderValue(
                                            getHeaderValue($headerText, 'Subject')
                                        );

                                        $from = decodeMimeHeaderValue(
                                            getHeaderValue($headerText, 'From')
                                        );

                                        $date = getHeaderValue($headerText, 'Date');

                                        if ($subject === '') {
                                            $subject = '(제목 없음)';
                                        }

                                        if ($from === '') {
                                            $from = '(발신자 확인 불가)';
                                        }

                                        $recentMails[] = array(
                                            'sequence' => $sequence,
                                            'subject' => $subject,
                                            'from' => $from,
                                            'date' => $date
                                        );
                                    }

                                    $fetchTagNumber++;
                                }

                                if (!empty($recentMails)) {
                                    addResult(
                                        $results,
                                        '최근 메일 제목 확인',
                                        'success',
                                        '최근 메일 ' . count($recentMails)
                                        . '개의 제목을 읽었습니다. 메일 읽음 상태는 변경하지 않았습니다.'
                                    );
                                } else {
                                    addResult(
                                        $results,
                                        '최근 메일 제목 확인',
                                        'warning',
                                        '받은메일함은 열렸지만 최근 메일 제목을 표시하지 못했습니다.'
                                    );
                                }
                            } else {
                                addResult(
                                    $results,
                                    '최근 메일 제목 확인',
                                    'warning',
                                    '받은메일함에 메일이 없어 제목 검사를 생략했습니다.'
                                );
                            }
                        }

                        /*
                         * 정상 로그인 상태에서는 로그아웃 명령을 보냅니다.
                         */
                        @sendImapCommand($socket, 'A999', 'LOGOUT');
                    }
                }

                @fclose($socket);
            }
        }
    }

    /*
     * 메모리 안에 남아 있는 비밀번호 변수를 바로 비웁니다.
     */
    $appPassword = '';
    unset($appPassword);
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>네이버 IMAP 직접 연결 점검</title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 30px 16px;
            background: #f4f6f8;
            color: #222;
            font-family: Arial, "Malgun Gothic", "맑은 고딕", sans-serif;
            line-height: 1.6;
        }

        .container {
            width: 100%;
            max-width: 960px;
            margin: 0 auto;
        }

        .card {
            background: #fff;
            border: 1px solid #dfe3e8;
            border-radius: 12px;
            padding: 28px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.05);
        }

        h1 {
            margin: 0 0 10px;
            font-size: 28px;
        }

        h2 {
            margin: 30px 0 14px;
            font-size: 22px;
        }

        .subtitle {
            margin: 0 0 24px;
            color: #5f6368;
        }

        .notice {
            margin-bottom: 20px;
            padding: 14px 16px;
            border-radius: 8px;
            background: #fff8e1;
            border: 1px solid #f0d783;
        }

        .notice.danger {
            background: #fff1f0;
            border-color: #e7a7a3;
            color: #8a1f17;
        }

        .form-row {
            margin-bottom: 18px;
        }

        label {
            display: block;
            margin-bottom: 7px;
            font-weight: bold;
        }

        input[type="text"],
        input[type="password"] {
            width: 100%;
            height: 46px;
            padding: 0 12px;
            border: 1px solid #c9ced6;
            border-radius: 6px;
            font-size: 16px;
        }

        input:focus {
            border-color: #03c75a;
            outline: none;
            box-shadow: 0 0 0 3px rgba(3, 199, 90, 0.12);
        }

        button {
            width: 100%;
            height: 48px;
            border: 0;
            border-radius: 7px;
            background: #03c75a;
            color: #fff;
            font-size: 17px;
            font-weight: bold;
            cursor: pointer;
        }

        button:hover {
            background: #02b351;
        }

        button:disabled {
            background: #aeb6bf;
            cursor: not-allowed;
        }

        .server-info {
            margin-top: 20px;
            padding: 14px 16px;
            background: #f7f9fb;
            border-radius: 8px;
            font-size: 14px;
        }

        .result-item {
            display: table;
            width: 100%;
            margin-bottom: 10px;
            padding: 14px;
            border-radius: 8px;
            border: 1px solid #dfe3e8;
            background: #fff;
        }

        .result-name,
        .result-status,
        .result-message {
            display: table-cell;
            vertical-align: middle;
        }

        .result-name {
            width: 200px;
            font-weight: bold;
        }

        .result-status {
            width: 100px;
        }

        .badge {
            display: inline-block;
            min-width: 76px;
            padding: 4px 9px;
            border-radius: 20px;
            text-align: center;
            font-size: 13px;
            font-weight: bold;
        }

        .badge.success {
            background: #e7f8ee;
            color: #14743a;
        }

        .badge.warning {
            background: #fff4d6;
            color: #8a6400;
        }

        .badge.fail {
            background: #fdebea;
            color: #a12922;
        }

        .result-message {
            color: #4d5156;
        }

        .mail-list {
            margin: 0;
            padding: 0;
            list-style: none;
            border: 1px solid #dfe3e8;
            border-radius: 8px;
            overflow: hidden;
        }

        .mail-item {
            padding: 15px 16px;
            border-bottom: 1px solid #e8ebef;
            background: #fff;
        }

        .mail-item:last-child {
            border-bottom: 0;
        }

        .mail-subject {
            margin-bottom: 5px;
            font-weight: bold;
            color: #202124;
            word-break: break-all;
        }

        .mail-meta {
            color: #6b7280;
            font-size: 14px;
            word-break: break-all;
        }

        .footer-warning {
            margin-top: 24px;
            padding-top: 18px;
            border-top: 1px solid #e4e7eb;
            color: #b3261e;
            font-weight: bold;
        }

        @media (max-width: 640px) {
            body {
                padding: 14px 10px;
            }

            .card {
                padding: 20px 16px;
            }

            h1 {
                font-size: 23px;
            }

            .result-item,
            .result-name,
            .result-status,
            .result-message {
                display: block;
                width: 100%;
            }

            .result-status {
                margin: 8px 0;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <h1>네이버 IMAP 직접 연결 점검</h1>

        <p class="subtitle">
            PHP IMAP 확장 기능 없이 네이버 메일 서버에 직접 연결해 로그인과 메일 제목을 확인합니다.
        </p>

        <div class="notice">
            네이버 메일의 IMAP/SMTP 사용 설정, 2단계 인증,
            애플리케이션 비밀번호가 켜져 있어야 합니다.
            최근 메일은 제목·발신자·날짜만 확인하며 읽음 상태를 변경하지 않습니다.
        </div>

        <?php if (!$isHttps): ?>
            <div class="notice danger">
                현재 페이지가 HTTPS가 아닙니다.
                비밀번호 보호를 위해 이 상태에서는 검사를 실행할 수 없습니다.
            </div>
        <?php endif; ?>

        <form method="post" action="" autocomplete="off">
            <div class="form-row">
                <label for="username">네이버 아이디</label>
                <input
                    type="text"
                    id="username"
                    name="username"
                    value="<?php echo h($submittedUsername); ?>"
                    placeholder="예: cmbuild"
                    maxlength="100"
                    required
                >
            </div>

            <div class="form-row">
                <label for="app_password">애플리케이션 비밀번호</label>
                <input
                    type="password"
                    id="app_password"
                    name="app_password"
                    value=""
                    placeholder="네이버에서 생성한 12자리 비밀번호"
                    maxlength="100"
                    autocomplete="new-password"
                    required
                >
            </div>

            <button type="submit" <?php echo !$isHttps ? 'disabled' : ''; ?>>
                직접 연결 검사
            </button>
        </form>

        <div class="server-info">
            <strong>검사 대상</strong><br>
            IMAP 서버: imap.naver.com<br>
            IMAP 포트: 993<br>
            보안 방식: SSL<br>
            PHP IMAP 확장 기능: 사용하지 않음
        </div>

        <?php if (!empty($results)): ?>
            <h2>검사 결과</h2>

            <?php foreach ($results as $result): ?>
                <div class="result-item">
                    <div class="result-name">
                        <?php echo h($result['name']); ?>
                    </div>

                    <div class="result-status">
                        <span class="badge <?php echo h($result['status']); ?>">
                            <?php echo h(statusLabel($result['status'])); ?>
                        </span>
                    </div>

                    <div class="result-message">
                        <?php echo h($result['message']); ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if (!empty($recentMails)): ?>
            <h2>최근 메일 확인</h2>

            <ul class="mail-list">
                <?php foreach ($recentMails as $mail): ?>
                    <li class="mail-item">
                        <div class="mail-subject">
                            <?php echo h($mail['subject']); ?>
                        </div>

                        <div class="mail-meta">
                            발신자: <?php echo h($mail['from']); ?><br>
                            날짜: <?php echo h($mail['date']); ?><br>
                            메일 순번: <?php echo h($mail['sequence']); ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <div class="footer-warning">
            검사가 끝나면 서버에서 imap_socket_check.php 파일을 바로 삭제하세요.
        </div>
    </div>
</div>
</body>
</html>
