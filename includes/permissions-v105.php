<?php
declare(strict_types=1);

/** v105+ permission extensions. Customer access is package-driven. */
function permission_v105_catalog(): array
{
    return [
        'playlists.manage'=>[
            'label'=>'Manage Playlists',
            'description'=>'Create, edit, duplicate and delete personal playlists in Agent Chat.',
            'category'=>'Content','sort_order'=>47,
        ],
        'release.manage'=>[
            'label'=>'Release Operations',
            'description'=>'Plan releases, deadlines, resources and Agent work actions.',
            'category'=>'Content','sort_order'=>48,
        ],
        'credits.manage'=>[
            'label'=>'Track Credits',
            'description'=>'Manage structured track credits and contribution details.',
            'category'=>'Content','sort_order'=>49,
        ],
        'midi.access'=>[
            'label'=>'MIDI Studio',
            'description'=>'Use MIDI tracks, piano roll, instruments and MIDI recording in Stem Studio when the MIDI feature is enabled.',
            'category'=>'Studio','sort_order'=>70,
        ],
        'midi.manage'=>[
            'label'=>'Manage MIDI',
            'description'=>'Enable or disable the MIDI feature and manage MIDI Studio availability.',
            'category'=>'Studio','sort_order'=>71,
        ],
    ];
}

/** Legacy Access fallback only. New customer permissions come from packages. */
function permission_v105_default_roles(): array
{
    return [
        'playlists.manage'=>['fan','artist','manager','producer','supervisor','investor','admin'],
        'release.manage'=>['artist','manager','supervisor','admin'],
        'credits.manage'=>['artist','manager','supervisor','admin'],
        'midi.access'=>['artist','manager','producer','supervisor','admin'],
        'midi.manage'=>['admin'],
    ];
}

function permission_v105_has(string $permission, ?array $user = null): bool
{
    $user ??= current_user();
    if (!$user || !isset(permission_v105_catalog()[$permission])) return false;
    if (user_has_role('admin', $user)) return true;
    if($permission==='midi.manage')return false;

    if(function_exists('subscription_schema_ready')&&subscription_schema_ready()){
        $sub=subscription_current($user);
        if($sub&&!subscription_has_entitlement($user,'legacy.permissions')){
            return subscription_package_grants_permission($user,$permission);
        }
    }

    // Legacy Access compatibility only.
    $roles=user_roles_for_user($user);
    if (!$roles) return false;
    $pdo=db();
    if ($pdo && permissions_schema_ready()) {
        try{
            $placeholders=implode(',',array_fill(0,count($roles),'?'));
            $stmt=$pdo->prepare("SELECT 1 FROM role_permissions WHERE permission_key=? AND role IN ($placeholders) LIMIT 1");
            $stmt->execute([$permission,...$roles]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {}
    }
    foreach($roles as $role)if(in_array($role,permission_v105_default_roles()[$permission]??[],true))return true;
    return false;
}

/**
 * One-time legacy rollout. Retained for old installs; package entitlements are
 * the current Customer permission source.
 */
function permission_v105_seed_playlist_permission(): void
{
    static $attempted=false;if($attempted)return;$attempted=true;
    if((string)setting('playlists_manage_permission_seed_v187','')==='1')return;
    $pdo=db();if(!$pdo||!permissions_schema_ready())return;
    $permission=permission_v105_catalog()['playlists.manage'];
    try{
        $pdo->beginTransaction();
        $upsert=$pdo->prepare('INSERT INTO permissions (permission_key,label,description,category,sort_order) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE label=VALUES(label),description=VALUES(description),category=VALUES(category),sort_order=VALUES(sort_order)');
        $upsert->execute(['playlists.manage',$permission['label'],$permission['description'],$permission['category'],$permission['sort_order']]);
        $insert=$pdo->prepare('INSERT IGNORE INTO role_permissions (role,permission_key) VALUES (?,?)');
        foreach(permission_v105_default_roles()['playlists.manage'] as $role)$insert->execute([$role,'playlists.manage']);
        $pdo->commit();save_setting('playlists_manage_permission_seed_v187','1');
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function permission_v105_playlist_permission_ready(): bool
{
    return (string)setting('playlists_manage_permission_seed_v187','')==='1';
}

function permission_v105_require(string $permission): void
{
    if (!is_logged_in()) {flash('error','Please sign in to continue.');redirect(url('/login.php'));}
    if (!permission_v105_has($permission)) {http_response_code(403);exit('Access denied.');}
}

function permission_v105_catalog_for_admin(): array
{
    return permission_catalog() + permission_v105_catalog();
}

function permission_v105_json_denied(string $message): never
{
    http_response_code(403);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
    echo json_encode(['ok'=>false,'error'=>$message,'message'=>$message],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
}

function permission_v105_enforce_request_gates(): void
{
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST' || !is_logged_in()) return;
    $script=basename((string)($_SERVER['SCRIPT_NAME']??''));
    if($script==='chat-create-v76.php'){
        $type=trim((string)($_POST['type']??''));
        if($type==='playlist'&&!permission_v105_has('playlists.manage'))permission_v105_json_denied('You do not have permission to create playlists.');
        return;
    }
    if($script!=='player-library-v76.php')return;
    $input=$_POST;
    if(!$input){
        $raw=(string)file_get_contents('php://input');$decoded=json_decode($raw,true);
        if(is_array($decoded)){$input=$decoded;foreach($decoded as $key=>$value)if(is_string($key)&&!array_key_exists($key,$_POST))$_POST[$key]=$value;}
    }
    $action=trim((string)($input['action']??''));
    if(in_array($action,['playlist_update','playlist_add_track','playlist_delete','playlist_duplicate'],true)&&!permission_v105_has('playlists.manage'))permission_v105_json_denied('You do not have permission to manage playlists.');
}

/** Resolve the Artist workspace that owns the current relationship context. */
function permission_v105_artist_owner_id(?array $user = null): int
{
    $user ??= current_user();$userId=(int)($user['id']??0);if($userId<1)return 0;
    if(user_has_role('admin',$user))return $userId;
    if(function_exists('artist_workspace_v104_is_artist')&&artist_workspace_v104_is_artist($user))return $userId;
    $pdo=db();
    if($pdo&&table_exists('artist_team_members')){
        try{$stmt=$pdo->prepare('SELECT artist_user_id FROM artist_team_members WHERE member_user_id=? ORDER BY artist_user_id LIMIT 1');$stmt->execute([$userId]);$owner=(int)$stmt->fetchColumn();if($owner>0)return $owner;}catch(Throwable $e){}
    }
    return 0;
}

/** Workspace boundary for Credits Graph and Agent credit lookups. */
function permission_v105_track_allowed(array $track, ?array $user = null): bool
{
    $user ??= current_user();if(!$user)return false;if(user_has_role('admin',$user))return true;
    $userId=(int)($user['id']??0);$producerId=(int)($track['producer_user_id']??0);$ownerId=(int)($track['owner_user_id']??0);if($userId<1)return false;
    if($ownerId===$userId&&function_exists('artist_workspace_v104_is_artist')&&artist_workspace_v104_is_artist($user))return true;

    $pdo=db();if(!$pdo||$ownerId<1||!table_exists('artist_team_members'))return false;
    try{
        $stmt=$pdo->prepare('SELECT team_role FROM artist_team_members WHERE artist_user_id=? AND member_user_id=? LIMIT 1');
        $stmt->execute([$ownerId,$userId]);$role=(string)$stmt->fetchColumn();
        if($role==='manager')return true;
        if($role==='producer')return $producerId===$userId;
    }catch(Throwable $e){}
    return false;
}

/** Limit selectable account contributors to the current Artist workspace. */
function permission_v105_workspace_user_allowed(int $candidateUserId, ?array $user = null): bool
{
    $user ??= current_user();if(!$user||$candidateUserId<1)return false;if(user_has_role('admin',$user))return true;
    $owner=permission_v105_artist_owner_id($user);if($owner<1)return false;if($candidateUserId===$owner)return true;
    $pdo=db();if($pdo&&table_exists('artist_team_members')){
        try{$stmt=$pdo->prepare('SELECT 1 FROM artist_team_members WHERE artist_user_id=? AND member_user_id=? LIMIT 1');$stmt->execute([$owner,$candidateUserId]);return (bool)$stmt->fetchColumn();}catch(Throwable $e){}
    }
    return false;
}