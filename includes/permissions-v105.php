<?php
declare(strict_types=1);

/**
 * v105+ permission extension.
 *
 * The legacy permission engine rejects unknown catalog keys before querying
 * role_permissions. Keep extension permissions isolated here so newer Agent
 * Operations, listener-library and Studio features can honor database-managed
 * multi-role assignments without rewriting the mature legacy permission core.
 */
function permission_v105_catalog(): array
{
    return [
        'playlists.manage'=>[
            'label'=>'Manage Playlists',
            'description'=>'Create, edit, duplicate and delete personal playlists in Agent Chat.',
            'category'=>'Content',
            'sort_order'=>47,
        ],
        'release.manage'=>[
            'label'=>'Release Operations',
            'description'=>'Plan releases, deadlines, resources and Agent work actions.',
            'category'=>'Content',
            'sort_order'=>48,
        ],
        'credits.manage'=>[
            'label'=>'Track Credits',
            'description'=>'Manage structured track credits and contribution details.',
            'category'=>'Content',
            'sort_order'=>49,
        ],
        'midi.access'=>[
            'label'=>'MIDI Studio',
            'description'=>'Use MIDI tracks, piano roll, instruments and MIDI recording in Stem Studio when the MIDI feature is enabled.',
            'category'=>'Studio',
            'sort_order'=>70,
        ],
        'midi.manage'=>[
            'label'=>'Manage MIDI',
            'description'=>'Enable or disable the MIDI feature and manage MIDI Studio availability.',
            'category'=>'Studio',
            'sort_order'=>71,
        ],
    ];
}

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

    $roles=user_roles_for_user($user);
    if (!$roles) return false;
    $pdo=db();
    if ($pdo && permissions_schema_ready()) {
        try{
            $placeholders=implode(',',array_fill(0,count($roles),'?'));
            $stmt=$pdo->prepare(
                "SELECT 1 FROM role_permissions
                 WHERE permission_key=? AND role IN ($placeholders)
                 LIMIT 1"
            );
            $stmt->execute([$permission,...$roles]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {}
    }

    foreach($roles as $role){
        if(in_array($role,permission_v105_default_roles()[$permission]??[],true)) return true;
    }
    return false;
}

/**
 * One-time v187 rollout. After this marker is written, Admin > Permissions is
 * the source of truth and this function will never re-add a permission that an
 * administrator later removes.
 */
function permission_v105_seed_playlist_permission(): void
{
    static $attempted=false;
    if($attempted) return;
    $attempted=true;

    if((string)setting('playlists_manage_permission_seed_v187','')==='1') return;
    $pdo=db();
    if(!$pdo || !permissions_schema_ready()) return;

    $permission=permission_v105_catalog()['playlists.manage'];
    try{
        $pdo->beginTransaction();
        $upsert=$pdo->prepare(
            'INSERT INTO permissions (permission_key,label,description,category,sort_order)
             VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
               label=VALUES(label),description=VALUES(description),category=VALUES(category),sort_order=VALUES(sort_order)'
        );
        $upsert->execute([
            'playlists.manage',
            $permission['label'],
            $permission['description'],
            $permission['category'],
            $permission['sort_order'],
        ]);

        $insert=$pdo->prepare('INSERT IGNORE INTO role_permissions (role,permission_key) VALUES (?,?)');
        foreach(permission_v105_default_roles()['playlists.manage'] as $role){
            $insert->execute([$role,'playlists.manage']);
        }
        $pdo->commit();
        save_setting('playlists_manage_permission_seed_v187','1');
    }catch(Throwable $e){
        if($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function permission_v105_playlist_permission_ready(): bool
{
    return (string)setting('playlists_manage_permission_seed_v187','')==='1';
}

function permission_v105_require(string $permission): void
{
    if (!is_logged_in()) {
        flash('error','Please sign in to continue.');
        redirect(url('/login.php'));
    }
    if (!permission_v105_has($permission)) {
        http_response_code(403);
        exit('Access denied.');
    }
}

function permission_v105_catalog_for_admin(): array
{
    return permission_catalog() + permission_v105_catalog();
}

function permission_v105_json_denied(string $message): never
{
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['ok'=>false,'error'=>$message,'message'=>$message],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Enforce extension permissions on legacy endpoints as well as the visible UI.
 * This closes direct-URL/API bypasses without duplicating playlist persistence.
 */
function permission_v105_enforce_request_gates(): void
{
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST' || !is_logged_in()) return;
    $script=basename((string)($_SERVER['SCRIPT_NAME']??''));

    if($script==='chat-create-v76.php'){
        $type=trim((string)($_POST['type']??''));
        if($type==='playlist' && !permission_v105_has('playlists.manage')){
            permission_v105_json_denied('You do not have permission to create playlists.');
        }
        return;
    }

    if($script!=='player-library-v76.php') return;

    $input=$_POST;
    if(!$input){
        $raw=(string)file_get_contents('php://input');
        $decoded=json_decode($raw,true);
        if(is_array($decoded)){
            $input=$decoded;
            foreach($decoded as $key=>$value){
                if(is_string($key) && !array_key_exists($key,$_POST)) $_POST[$key]=$value;
            }
        }
    }

    $action=trim((string)($input['action']??''));
    if(in_array($action,['playlist_update','playlist_add_track','playlist_delete','playlist_duplicate'],true)
        && !permission_v105_has('playlists.manage')){
        permission_v105_json_denied('You do not have permission to manage playlists.');
    }
}

/** Resolve the Artist workspace that owns a non-Admin account. */
function permission_v105_artist_owner_id(?array $user = null): int
{
    $user ??= current_user();
    $userId=(int)($user['id']??0);
    if($userId<1)return 0;
    if(user_has_role('admin',$user)||user_has_role('artist',$user))return $userId;

    $pdo=db();
    if($pdo&&table_exists('artist_team_members')){
        try{
            $stmt=$pdo->prepare('SELECT artist_user_id FROM artist_team_members WHERE member_user_id=? LIMIT 1');
            $stmt->execute([$userId]);
            $owner=(int)$stmt->fetchColumn();
            if($owner>0)return $owner;
        }catch(Throwable $e){}
    }
    return $userId;
}

/**
 * Workspace boundary for Credits Graph and Agent credit lookups.
 * Admin is global. A Producer-only account sees only tracks assigned directly
 * to that Producer. Artist/Manager accounts may use tracks owned by the Artist
 * or assigned to another member of that Artist's team.
 */
function permission_v105_track_allowed(array $track, ?array $user = null): bool
{
    $user ??= current_user();
    if(!$user)return false;
    if(user_has_role('admin',$user))return true;

    $userId=(int)($user['id']??0);
    $producerId=(int)($track['producer_user_id']??0);
    $ownerId=(int)($track['owner_user_id']??0);
    if($userId<1)return false;

    $producerOnly=user_has_role('producer',$user)
        && !user_has_role('artist',$user)
        && !user_has_role('manager',$user)
        && !user_has_role('supervisor',$user);
    if($producerOnly)return $producerId>0&&$producerId===$userId;

    $workspaceOwner=permission_v105_artist_owner_id($user);
    if($workspaceOwner<1)return false;
    if($ownerId===$workspaceOwner||$producerId===$workspaceOwner)return true;

    $pdo=db();
    if($pdo&&$producerId>0&&table_exists('artist_team_members')){
        try{
            $stmt=$pdo->prepare('SELECT 1 FROM artist_team_members WHERE artist_user_id=? AND member_user_id=? LIMIT 1');
            $stmt->execute([$workspaceOwner,$producerId]);
            return (bool)$stmt->fetchColumn();
        }catch(Throwable $e){}
    }
    return false;
}

/** Limit selectable account contributors to the current Artist workspace. */
function permission_v105_workspace_user_allowed(int $candidateUserId, ?array $user = null): bool
{
    $user ??= current_user();
    if(!$user||$candidateUserId<1)return false;
    if(user_has_role('admin',$user))return true;

    $owner=permission_v105_artist_owner_id($user);
    if($owner<1)return false;
    if($candidateUserId===$owner)return true;

    $pdo=db();
    if($pdo&&table_exists('artist_team_members')){
        try{
            $stmt=$pdo->prepare('SELECT 1 FROM artist_team_members WHERE artist_user_id=? AND member_user_id=? LIMIT 1');
            $stmt->execute([$owner,$candidateUserId]);
            return (bool)$stmt->fetchColumn();
        }catch(Throwable $e){}
    }
    return false;
}
