<?php
declare(strict_types=1);

/**
 * VP3 v3.60 canonical plugin lifecycle.
 *
 * Product entitlements answer whether a plugin may run. The installation row
 * records the member's explicit opt-in/out preference. Effective state composes
 * those two facts without mutating identity, workspace membership or plugin data.
 */
const VP3_PLUGIN_LIFECYCLE_V360 = 'vp3-plugin-lifecycle-v360-20260910';

function vp3_plugin_requested_state_v360(PDO $pdo,int $userId,string $pluginKey): string
{
    $row=vp3_plugin_installation_v320($pdo,$userId,$pluginKey);
    if(!$row)return 'unset';
    return (string)($row['status']??'')==='enabled'?'enabled':'disabled';
}

function vp3_plugin_entitled_v360(?array $user,string $pluginKey): bool
{
    $user??=current_user();
    if(!$user||!vp3_plugin_valid_v320($pluginKey))return false;
    if(user_has_role('admin',$user))return true;

    // Preserve the explicit compatibility rule already owned by Music Workspace.
    if($pluginKey==='music_workspace'&&function_exists('music_workspace_entitled_v320')){
        return music_workspace_entitled_v320($user);
    }

    $catalog=vp3_plugin_catalog_v320();
    $entitlement=trim((string)($catalog[$pluginKey]['entitlement']??''));
    if($entitlement==='')return true;
    if(!function_exists('subscription_schema_ready')||!subscription_schema_ready())return false;
    return subscription_has_entitlement($user,$entitlement);
}

function vp3_plugin_legacy_default_v360(?array $user,string $pluginKey): bool
{
    $user??=current_user();
    if(!$user)return false;
    return $pluginKey==='music_workspace'&&function_exists('music_workspace_legacy_user_v320')&&music_workspace_legacy_user_v320($user);
}

/**
 * Resolve requested, commercial and effective state without changing persistence.
 * Explicit disable always wins. An enabled installation whose entitlement lapses
 * is paused, not deleted, so access can resume when commercial eligibility returns.
 */
function vp3_plugin_effective_state_v360(PDO $pdo,?array $user,string $pluginKey): array
{
    $user??=current_user();
    $uid=(int)($user['id']??0);
    if(!$user||$uid<1||!vp3_plugin_valid_v320($pluginKey)){
        return ['plugin_key'=>$pluginKey,'requested'=>'unset','entitled'=>false,'legacy_default'=>false,'enabled'=>false,'reason'=>'unavailable'];
    }

    $requested=vp3_plugin_requested_state_v360($pdo,$uid,$pluginKey);
    $entitled=vp3_plugin_entitled_v360($user,$pluginKey);
    $legacyDefault=vp3_plugin_legacy_default_v360($user,$pluginKey);

    if($requested==='disabled')$reason='disabled';
    elseif($requested==='enabled'&&!$entitled)$reason='paused_entitlement';
    elseif($requested==='enabled')$reason='enabled';
    elseif($legacyDefault&&$entitled)$reason='legacy_enabled';
    elseif($entitled)$reason='available';
    else $reason='entitlement_required';

    return [
        'plugin_key'=>$pluginKey,
        'requested'=>$requested,
        'entitled'=>$entitled,
        'legacy_default'=>$legacyDefault,
        'enabled'=>in_array($reason,['enabled','legacy_enabled'],true),
        'reason'=>$reason,
        'preserves_data'=>true,
    ];
}

function vp3_plugin_effective_enabled_v360(PDO $pdo,?array $user,string $pluginKey): bool
{
    return !empty(vp3_plugin_effective_state_v360($pdo,$user,$pluginKey)['enabled']);
}

function vp3_plugin_set_enabled_v360(PDO $pdo,array $user,string $pluginKey,bool $enabled): array
{
    $uid=(int)($user['id']??0);
    if($uid<1)throw new RuntimeException('Sign in to manage plugins.');
    if(!vp3_plugin_valid_v320($pluginKey))throw new RuntimeException('Unknown VP3 plugin.');
    if($enabled&&!vp3_plugin_entitled_v360($user,$pluginKey))throw new RuntimeException('This plugin is not included in your current VP3 product access.');
    if(!vp3_plugin_schema_ready_v320($pdo))vp3_plugin_ensure_schema_v320($pdo);
    vp3_plugin_set_enabled_v320($pdo,$uid,$pluginKey,$enabled);
    return vp3_plugin_effective_state_v360($pdo,$user,$pluginKey);
}

/**
 * Materialize historical Music workspace owners as explicit enabled installs.
 * INSERT IGNORE is intentional: a member's explicit disabled row is never
 * overwritten by migration or grandfathering.
 */
function vp3_plugin_migrate_legacy_v360(?PDO $pdo=null): void
{
    $pdo??=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(!vp3_plugin_schema_ready_v320($pdo))vp3_plugin_ensure_schema_v320($pdo);
    if(table_exists('artist_workspaces_v181')){
        $pdo->exec("INSERT IGNORE INTO user_plugin_installations (user_id,plugin_key,status,enabled_at,created_at,updated_at)
            SELECT DISTINCT artist_user_id,'music_workspace','enabled',NOW(),NOW(),NOW()
            FROM artist_workspaces_v181 WHERE artist_user_id>0");
    }
    if(function_exists('save_setting'))save_setting('plugin_lifecycle_v360_migrated',VP3_PLUGIN_LIFECYCLE_V360);
}

function vp3_plugin_lifecycle_v360_ready(?PDO $pdo=null): bool
{
    $pdo??=db();
    if(!$pdo||!vp3_plugin_schema_ready_v320($pdo))return false;
    return !function_exists('setting')||(string)setting('plugin_lifecycle_v360_migrated','')===VP3_PLUGIN_LIFECYCLE_V360;
}

/** Agent-facing capability manifest: only effectively enabled owner plugins appear. */
function vp3_plugin_agent_capabilities_v360(PDO $pdo,array $user): array
{
    $out=[];
    foreach(vp3_plugin_catalog_v320() as $key=>$plugin){
        $state=vp3_plugin_effective_state_v360($pdo,$user,(string)$key);
        if(empty($state['enabled']))continue;
        $out[]=[
            'plugin_key'=>(string)$key,
            'label'=>(string)($plugin['label']??$key),
            'entitlement'=>(string)($plugin['entitlement']??''),
            'state'=>(string)$state['reason'],
        ];
    }
    return $out;
}
