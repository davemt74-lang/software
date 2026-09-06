<?php
declare(strict_types=1);

/** Central HTTP feature gates for commercial package entitlements. */
function subscription_request_feature_map(): array
{
    return [
        'stem_editor.access'=>[
            '/admin/stems.php','/admin/stems-legacy-v108.php','/api/stem-agent-v105.php',
            '/api/stem-agent-v91.php','/api/stem-project','/api/stem-',
        ],
        'video_editor.access'=>[
            '/admin/video-editor.php','/video-editor.php','/api/video-editor','/api/video-',
        ],
        'transcription.access'=>[
            '/artist-listening.php','/api/transcription','/api/artist-listening',
        ],
        'profile_agent.access'=>[
            '/profile-agent.php','/api/profile-agent',
        ],
        'voice.access'=>[
            '/voice-profile.php','/api/agent-voice',
        ],
    ];
}

function subscription_request_matches(string $path,string $needle): bool
{
    if($needle==='')return false;
    if(str_ends_with($needle,'.php'))return $path===$needle;
    return str_starts_with($path,$needle);
}

function subscription_request_json_error(string $code,string $message,array $extra=[]): never
{
    http_response_code(403);
    if(!headers_sent())header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'error'=>$code,'message'=>$message]+$extra,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Manager/Producer compatibility roles are derived from Artist Team
 * relationships and deliberately carry only minimal permissions. Admin routes
 * still fail closed except for the relationship-scoped workspaces that perform
 * their own Artist/track ownership checks. A stronger Artist/Supervisor/Admin
 * identity is never reduced merely because the same person also collaborates
 * on another Artist's Team.
 */
function subscription_request_guard_legacy_team_role(string $path,array $user): void
{
    if(user_has_role('admin',$user)||user_has_role('artist',$user)||user_has_role('supervisor',$user))return;
    $legacyManager=user_has_role('manager',$user);
    $legacyProducer=user_has_role('producer',$user);
    if(!$legacyManager&&!$legacyProducer)return;

    $managerSafe=['/admin/team-workspaces.php','/admin/team-workspace.php'];
    $producerSafe=['/admin/producer-tracks.php','/admin/stems.php','/admin/stems-legacy-v108.php'];

    if($legacyManager){
        foreach($managerSafe as $allowed)if($path===$allowed)return;
    }
    if($legacyProducer){
        foreach($producerSafe as $allowed)if($path===$allowed)return;
    }

    if($legacyManager&&str_starts_with($path,'/admin/')){
        http_response_code(403);
        exit('Legacy Manager authority has been retired. Use the Artist Team workspace for relationship-scoped management.');
    }

    if($legacyProducer&&str_starts_with($path,'/admin/')){
        http_response_code(403);
        exit('Producer authority is limited to explicitly assigned production tracks.');
    }
}

/** A package can only remove an already-authorized permission, never create it. */
function subscription_effective_permission(string $permission,?array $user=null): bool
{
    $user??=current_user();
    if(!$user||!has_permission($permission,$user))return false;
    if(subscription_is_internal_admin($user))return true;
    if(!subscription_schema_ready())return true;
    $sub=subscription_current($user);
    if(!$sub||subscription_has_entitlement($user,'legacy.permissions'))return true;
    if($permission==='account.access')return true;
    return subscription_package_grants_permission($user,$permission);
}

function subscription_request_gate(): void
{
    if(PHP_SAPI==='cli'||!function_exists('current_user')||!function_exists('subscription_has_entitlement'))return;
    $path=(string)(parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH)??'');
    if($path==='')return;
    $user=current_user();if(!$user)return;

    subscription_request_guard_legacy_team_role($path,$user);
    if(subscription_is_internal_admin($user))return;
    $sub=subscription_current($user);
    // Before package migration or on Legacy Access, preserve existing behavior.
    if(!$sub||subscription_has_entitlement($user,'legacy.permissions'))return;

    foreach(subscription_request_feature_map() as $capability=>$patterns){
        $matched=false;foreach($patterns as $pattern){if(subscription_request_matches($path,$pattern)){$matched=true;break;}}
        if(!$matched)continue;
        if(subscription_has_entitlement($user,$capability))return;
        $isApi=str_starts_with($path,'/api/');
        if($isApi)subscription_request_json_error('package_entitlement_required','This feature is not included in your current package.',['capability'=>$capability]);
        http_response_code(403);
        $subName=(string)($sub['package_name']??'current package');
        $label=(string)(subscription_capability_catalog()[$capability]['label']??'This feature');
        $accountUrl=e(url('/subscription.php'));
        echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Upgrade Required | VP3</title><style>body{margin:0;font-family:Inter,system-ui,sans-serif;background:#f6f7f8;color:#111827}.gate{min-height:100vh;display:grid;place-items:center;padding:24px}.card{max-width:620px;background:#fff;border:1px solid #e5e7eb;border-radius:18px;padding:32px;box-shadow:0 18px 60px rgba(15,23,42,.08)}.tag{font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.1em;color:#667085}h1{margin:8px 0 10px;font-size:32px}p{line-height:1.65;color:#475467}.btn{display:inline-block;margin-top:12px;padding:11px 16px;border-radius:9px;background:#111827;color:#fff;text-decoration:none;font-weight:700}</style></head><body><main class="gate"><section class="card"><div class="tag">Package feature</div><h1>'.e($label).' is locked.</h1><p>Your <strong>'.e($subName).'</strong> package does not include this feature. Your account and non-AI features remain available.</p><a class="btn" href="'.$accountUrl.'">View Plan &amp; Access</a></section></main></body></html>';
        exit;
    }
}
