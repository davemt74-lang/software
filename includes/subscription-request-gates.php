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

function subscription_request_gate(): void
{
    if(PHP_SAPI==='cli'||!function_exists('current_user')||!function_exists('subscription_has_entitlement'))return;
    $path=(string)(parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH)??'');
    if($path==='')return;
    $user=current_user();if(!$user)return;
    if(subscription_is_internal_admin($user))return;
    $sub=subscription_current($user);
    // Before the package migration is installed, preserve existing behavior.
    if(!$sub||subscription_has_entitlement($user,'legacy.permissions'))return;
    foreach(subscription_request_feature_map() as $capability=>$patterns){
        $matched=false;foreach($patterns as $pattern){if(subscription_request_matches($path,$pattern)){$matched=true;break;}}
        if(!$matched)continue;
        if(subscription_has_entitlement($user,$capability))return;
        $isApi=str_starts_with($path,'/api/');
        http_response_code(403);
        if($isApi){
            if(!headers_sent())header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok'=>false,'error'=>'package_entitlement_required','capability'=>$capability,'message'=>'This feature is not included in your current package.'],JSON_UNESCAPED_SLASHES);
        }else{
            $subName=(string)($sub['package_name']??'current package');
            $label=(string)(subscription_capability_catalog()[$capability]['label']??'This feature');
            $accountUrl=e(url('/subscription.php'));
            echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Upgrade Required | VP3</title><style>body{margin:0;font-family:Inter,system-ui,sans-serif;background:#f6f7f8;color:#111827}.gate{min-height:100vh;display:grid;place-items:center;padding:24px}.card{max-width:620px;background:#fff;border:1px solid #e5e7eb;border-radius:18px;padding:32px;box-shadow:0 18px 60px rgba(15,23,42,.08)}.tag{font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.1em;color:#667085}h1{margin:8px 0 10px;font-size:32px}p{line-height:1.65;color:#475467}.btn{display:inline-block;margin-top:12px;padding:11px 16px;border-radius:9px;background:#111827;color:#fff;text-decoration:none;font-weight:700}</style></head><body><main class="gate"><section class="card"><div class="tag">Package feature</div><h1>'.e($label).' is locked.</h1><p>Your <strong>'.e($subName).'</strong> package does not include this feature. Your account and non-AI features remain available.</p><a class="btn" href="'.$accountUrl.'">View Plan &amp; Access</a></section></main></body></html>';
        }
        exit;
    }
}
