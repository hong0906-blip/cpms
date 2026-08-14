<?php
/**
 * Common Google Chat card-button payload regression test.
 * PHP 5.6 compatible and DB-independent.
 */

$root = dirname(__DIR__);
require_once $root . '/app/views/approval/google_chat_helpers.php';

$failures = array();
$checks = 0;

function cpms_google_chat_card_guard($label, $condition)
{
    global $failures, $checks;
    $checks++;
    if (!$condition) $failures[] = $label;
}

$sourceUrl = 'https://cmbuild.kr/cpms/public/?r=dashboard_employee&task_id=15&_clt=test-token#task-15';
$message = "[CPMS 업무 요청]\n제목 : 카드 버튼 공통 적용\nURL : " . $sourceUrl;
$actionLink = approval_google_chat_extract_action_link($message);

cpms_google_chat_card_guard(
    'URL line is extracted from every common Google Chat message',
    isset($actionLink['url']) && $actionLink['url'] === $sourceUrl
);
cpms_google_chat_card_guard(
    'bracketed first line becomes the card title',
    isset($actionLink['title']) && $actionLink['title'] === 'CPMS 업무 요청'
);
cpms_google_chat_card_guard(
    'long URL is removed from visible card text',
    isset($actionLink['message_text'])
    && strpos($actionLink['message_text'], '카드 버튼 공통 적용') !== false
    && strpos($actionLink['message_text'], 'https://') === false
);

$payload = approval_google_chat_build_card_button_payload(
    $actionLink['title'],
    $actionLink['message_text'],
    '바로 이동하시려면 눌러주세요',
    $actionLink['url']
);
$button = $payload['cardsV2'][0]['card']['sections'][0]['widgets'][1]['buttonList']['buttons'][0];

cpms_google_chat_card_guard(
    'button uses the requested label and original tokenized URL',
    isset($button['text']) && $button['text'] === '바로 이동하시려면 눌러주세요'
    && isset($button['onClick']['openLink']['url'])
    && $button['onClick']['openLink']['url'] === $sourceUrl
);
cpms_google_chat_card_guard(
    'button is filled and blue',
    isset($button['type']) && $button['type'] === 'FILLED'
    && isset($button['color']['blue'])
    && (float)$button['color']['blue'] > (float)$button['color']['red']
    && (float)$button['color']['blue'] > (float)$button['color']['green']
);
$compactMessage = approval_google_chat_build_compact_link_message($actionLink, '바로 이동하시려면 눌러주세요');
cpms_google_chat_card_guard(
    'card failure hides the long URL behind a compact clickable label',
    strpos($compactMessage, '<' . $sourceUrl . '|[바로 이동하시려면 눌러주세요]>') !== false
    && strpos($compactMessage, 'URL : ') === false
);
cpms_google_chat_card_guard(
    'messages without an action URL stay as ordinary text messages',
    approval_google_chat_extract_action_link('현재 미출근 중입니다. 출근 바랍니다.')['url'] === ''
);

$appHelpers = file_get_contents($root . '/app/helpers.php');
$targetCostHelpers = file_get_contents($root . '/app/views/construction/partials/target_cost_rate_helper.php');
$googleChatHelpers = file_get_contents($root . '/app/views/approval/google_chat_helpers.php');
$googleChatSettings = file_get_contents($root . '/app/views/approval/google_chat_settings.php');
cpms_google_chat_card_guard(
    'all task-related alerts receive a task action URL when missing',
    strpos($appHelpers, "array('TASK', 'TASK_COMMENT', 'TASK_DELAYED')") !== false
    && strpos($appHelpers, 'cpms_app_dashboard_employee_url($pdo, (int)$sourceId, 0)') !== false
);
cpms_google_chat_card_guard(
    'target-cost approval requests include an action URL',
    strpos($targetCostHelpers, "cpms_app_route_url(\$pdo, '공사'") !== false
    && strpos($targetCostHelpers, "array_push(\$lines, 'URL : ' . \$requestUrl)") !== false
);
cpms_google_chat_card_guard(
    'cards use app authentication and can add the calling app to existing spaces',
    strpos($googleChatHelpers, "'messages.create card button', 'app'") !== false
    && strpos($googleChatHelpers, "'name' => 'users/app'") !== false
    && strpos($googleChatHelpers, "'type' => 'BOT'") !== false
    && strpos($googleChatHelpers, "'membership_app'") !== false
);
cpms_google_chat_card_guard(
    'settings document the Chat app membership scope',
    strpos($googleChatSettings, 'https://www.googleapis.com/auth/chat.memberships.app') !== false
);

if (count($failures) > 0) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . ' / ' . $checks . "\n");
    foreach ($failures as $failure) fwrite(STDERR, '- ' . $failure . "\n");
    exit(1);
}

echo 'PASS: ' . $checks . " common Google Chat card-button checks\n";
