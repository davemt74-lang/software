<?php
declare(strict_types=1);

// Pure contract can also run in the small local PHP CLI without mbstring.
if (!function_exists('mb_strtolower')) {
    function mb_strtolower(string $text, string $encoding='UTF-8'): string { return strtolower($text); }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $text, string $encoding='UTF-8'): int { return strlen($text); }
}
require_once dirname(__DIR__).'/includes/agent-media-entertainment-v1.php';

$accepted = [
    'Yeah play a vacation brain',
    'Yeah, play Vacation Brain!',
    'play Vacation Brain',
    'Please play the Vacation Brain',
    'yes play "Vacation Brain"',
    'resume Vacation Brain',
    'play me a vacation brain',
];
foreach ($accepted as $text) {
    if (!vp3_media_offer_named_reply_v1($text,'Vacation Brain')) {
        throw new RuntimeException('Named Media offer acceptance failed: '.$text);
    }
}
$rejected = [
    'yeah do not play Vacation Brain',
    "don't play Vacation Brain",
    'yeah play First Light',
    'tell me about Vacation Brain',
    'play a different song',
    'play it',
    'yeah play a vacation brain and send an email',
    'play Vacation Brains',
    'play Vacation Brain in another Chat',
    str_repeat('play Vacation Brain ',40),
];
foreach ($rejected as $text) {
    if (vp3_media_offer_named_reply_v1($text,'Vacation Brain')) {
        throw new RuntimeException('Unrelated Media request was misinterpreted as an offer: '.$text);
    }
}
if (!vp3_media_offer_named_reply_v1('play A Vacation Brain','A Vacation Brain')) {
    throw new RuntimeException('An article belonging to the title must be preserved.');
}
if (vp3_media_offer_named_reply_v1('play A Vacation Brain','Vacation Brain Again')) {
    throw new RuntimeException('Named acceptance must not select a different track.');
}
echo "AGENT_MEDIA_OFFER_NAMED_REPLY_V1=PASS\n";
