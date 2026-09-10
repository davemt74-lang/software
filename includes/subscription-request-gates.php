<?php
declare(strict_types=1);

/** Central HTTP feature gates for commercial product entitlements. */
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

function subscription_admin_only_permissions(): array
{
    return ['admin.access','users.manage','permissions.manage','ai.manage'];
}

/**
 * Team authority is contextual. These guards only prevent a Customer whose
 * Manager/Producer relationship is recognized from wandering into unrelated
 * global Admin surfaces; the allowed Team/production routes perform their own
 * workspace or track ownership checks.
 */
function subscription_request_guard_legacy_team_role(string $path,array $user): void
{
    if(subscription_is_internal_admin($user))return;
    $pdo=db();$userId=(int)($user['id']??0);if(!$pdo||$userId<1||!function_exists('artist_workspace_v104_memberships_for_user'))return;
    try{$memberships=artist_workspace_v104_memberships_for_user($pdo,$userId);}catch(Throwable $e){return;}
    if(!$memberships)return;

    $hasManager=false;$hasProducer=false;
    foreach($memberships as $membership){
        $role=(string)($membership['team_role']??'');
        if($role==='manager')$hasManager=true;
        if($role==='producer')$hasProducer=true;
    }
    if(!$hasManager&&!$hasProducer)return;

    $managerSafe=['/admin/team-workspaces.php','/admin/team-workspace.php'];
    $producerSafe=['/admin/producer-tracks.php','/admin/stems.php','/admin/stems-legacy-v108.php'];
    if($hasManager){foreach($managerSafe as $allowed)if($path===$allowed)return;}
    if($hasProducer){foreach($producerSafe as $allowed)if($path===$allowed)return;}

    // Normal Customer pages are unaffected. Only unrelated Admin surfaces are blocked.
    if(str_starts_with($path,'/admin/')){
        http_response_code(403);
        exit('Workspace authority is relationship-scoped. Use the applicable Artist Team or production workspace.');
    }
}

/**
 * Compatibility permission decision for callers that still use this helper.
 *
 * Commercial packages and add-on entitlements never grant security authority.
 * Platform roles/direct role-permission assignments are canonical here, while
 * workspace-specific authorization remains in the applicable workspace guards.
 */
function subscription_effective_permission(string $permission,?array $user=null): bool
{
    $user??=current_user();
    if(!$user)return false;
    if(subscription_is_internal_admin($user))return true;
    if(in_array($permission,subscription_admin_only_permissions(),true))return false;
    if($permission==='account.access')return true;
    return has_permission($permission,$user);
}

function subscription_request_gate(): void
{
    if(PHP_SAPI==='cli'||!function_exists('current_user')||!function_exists('subscription_has_entitlement'))return;
    $path=(string)(parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH)??'');
    if($path==='')return;
    $user=current_user();if(!$user)return;

    subscription_request_guard_legacy_team_role($path,$user);
    if(subscription_is_internal_admin($user))return;

    foreach(subscription_request_feature_map() as $capability=>$patterns){
        $matched=false;foreach($patterns as $pattern){if(subscription_request_matches($path,$pattern)){$matched=true;break;}}
        if(!$matched)continue;

        // This is a product-availability check only. The destination endpoint
        // remains responsible for role/workspace/resource authorization.
        if(subscription_has_entitlement($user,$capability))return;

        $isApi=str_starts_with($path,'/api/');
        if($isApi)subscription_request_json_error(
            'package_entitlement_required',
            'This feature is not included in your current plan or add-ons.',
            ['capability'=>$capability]
        );

        http_response_code(403);
        $sub=subscription_current($user);
        $subName=(string)($sub['package_name']??'current access');
        $label=(string)(subscription_capability_catalog()[$capability]['label']??'This feature');
        $accountUrl=e(url('/subscription.php'));
        echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Upgrade Required | VP3</title><style>body{margin:0;font-family:Inter,system-ui,sans-serif;background:#f6f7f8;color:#111827}.gate{min-height:100vh;display:grid;place-items:center;padding:24px}.card{max-width:620px;background:#fff;border:1px solid #e5e7eb;border-radius:18px;padding:32px;box-shadow:0 18px 60px rgba(15,23,42,.08)}.tag{font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.1em;color:#667085}h1{margin:8px 0 10px;font-size:32px}p{line-height:1.65;color:#475467}.btn{display:inline-block;margin-top:12px;padding:11px 16px;border-radius:9px;background:#111827;color:#fff;text-decoration:none;font-weight:700}</style></head><body><main class="gate"><section class="card"><div class="tag">Product feature</div><h1>'.e($label).' is locked.</h1><p>Your <strong>'.e($subName).'</strong> plan and active add-ons do not include this feature. Your account and other entitled capabilities remain available.</p><a class="btn" href="'.$accountUrl.'">View Plan &amp; Access</a></section></main></body></html>';
        exit;
    }
}
