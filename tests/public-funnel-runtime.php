<?php
declare(strict_types=1);

$_SESSION = [];
$_SERVER['SCRIPT_NAME'] = '/product.php';
$_SERVER['REQUEST_URI'] = '/product.php';

function url(string $path): string { return $path; }
function subscription_capability_catalog(): array
{
    return [
        'main_ai.access'=>[],
        'knowledge.access'=>[],
        'profile_agent.access'=>[],
        'transcription.access'=>[],
        'team_seats'=>[],
    ];
}

require __DIR__.'/../includes/vp3-funnel.php';

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

expect(vp3_funnel_safe_return('/pricing.php?plan=2') === '/pricing.php?plan=2', 'local return target should be accepted');
expect(vp3_funnel_safe_return('/chat.php#onboarding') === '/chat.php#onboarding', 'local fragment target should be accepted');

foreach ([
    'https://evil.example/',
    '//evil.example/path',
    '/%2Fevil.example/path',
    '/%252Fevil.example/path',
    '/\\evil.example/path',
    '/%5Cevil.example/path',
    '/%255Cevil.example/path',
] as $target) {
    expect(vp3_funnel_safe_return($target) === null, "unsafe return target must be rejected: {$target}");
}

vp3_funnel_capture_public_source();
$intent = vp3_funnel_intent();
expect(($intent['source'] ?? '') === 'product', 'public product page should become the funnel source');
expect(($intent['origin'] ?? '') === 'product', 'first public product page should become the funnel origin');
expect((vp3_funnel_feature_interests($intent)['main_ai.access'] ?? false) === true, 'product source should map to main AI interest');

$_SERVER['SCRIPT_NAME'] = '/services.php';
vp3_funnel_capture_public_source();
expect((vp3_funnel_intent()['source'] ?? '') === 'services', 'later public page should become current source');
expect((vp3_funnel_intent()['origin'] ?? '') === 'product', 'later public page must not overwrite first-touch origin');

$_SERVER['SCRIPT_NAME'] = '/signup.php';
vp3_funnel_capture_public_source();
expect((vp3_funnel_intent()['source'] ?? '') === 'services', 'auth pages must not overwrite the current marketing source');
expect((vp3_funnel_intent()['origin'] ?? '') === 'product', 'auth pages must preserve first-touch origin');

vp3_funnel_capture(['plan'=>'42','billing'=>'yearly','return_to'=>'/chat.php?from=pricing']);
$intent = vp3_funnel_intent();
expect(($intent['plan'] ?? '') === '42', 'plan should persist');
expect(($intent['billing'] ?? '') === 'annual', 'yearly should normalize to annual');
expect(($intent['return_to'] ?? '') === '/chat.php?from=pricing', 'safe return target should persist');

$query = vp3_funnel_query($intent);
expect(str_contains($query, 'source=services'), 'query should preserve current source');
expect(str_contains($query, 'origin=product'), 'query should preserve first-touch origin');
expect(str_contains($query, 'plan=42'), 'query should preserve plan');
expect(str_contains($query, 'billing=annual'), 'query should preserve billing');

$draft = vp3_funnel_onboarding_draft($intent);
expect(($draft['public_funnel']['source'] ?? '') === 'services', 'onboarding draft should preserve current source');
expect(($draft['public_funnel']['origin'] ?? '') === 'product', 'onboarding draft should preserve first-touch origin');
expect(($draft['public_funnel']['plan'] ?? '') === '42', 'onboarding draft should preserve plan');
expect(($draft['public_funnel']['billing'] ?? '') === 'annual', 'onboarding draft should preserve billing');
expect(!array_key_exists('return_to', $draft['public_funnel']), 'return target must not become durable onboarding data');

$_SESSION = [];
vp3_funnel_capture(['source'=>'local-knowledge-overview']);
expect((vp3_funnel_feature_interests(vp3_funnel_intent())['knowledge.access'] ?? false) === true, 'local knowledge should map to knowledge interest');

$_SESSION = [];
vp3_funnel_capture(['source'=>'profile-agent-overview']);
expect((vp3_funnel_feature_interests(vp3_funnel_intent())['profile_agent.access'] ?? false) === true, 'profile agent should map to profile-agent interest');

$_SESSION = [];
vp3_funnel_capture(['source'=>'transcriptions']);
expect((vp3_funnel_feature_interests(vp3_funnel_intent())['transcription.access'] ?? false) === true, 'transcriptions should map to transcription interest');

$_SESSION = [];
vp3_funnel_capture(['source'=>'teams']);
expect((vp3_funnel_feature_interests(vp3_funnel_intent())['team_seats'] ?? false) === true, 'teams should map to team-seat interest');

$_SESSION = [];
vp3_funnel_capture(['source'=>'annotations','origin'=>'index']);
$annotationInterests=vp3_funnel_feature_interests(vp3_funnel_intent());
expect(($annotationInterests['main_ai.access']??false)===true, 'annotations should map to main AI interest');
expect(($annotationInterests['knowledge.access']??false)===true, 'annotations should map to knowledge interest');
expect(($annotationInterests['workflow.browser']??false)===true, 'annotations should seed Browser workflow onboarding interest');

$_SESSION = [];
vp3_funnel_capture(['source'=>'video-meetings','origin'=>'index']);
$meetingInterests=vp3_funnel_feature_interests(vp3_funnel_intent());
expect(($meetingInterests['main_ai.access']??false)===true, 'meetings should map to main AI interest');
expect(($meetingInterests['transcription.access']??false)===true, 'meetings should map to transcription interest');
expect(($meetingInterests['workflow.meetings']??false)===true, 'meetings should seed Meetings workflow onboarding interest');

fwrite(STDOUT, "public funnel runtime: PASS\n");
