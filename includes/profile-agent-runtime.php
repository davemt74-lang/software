<?php
declare(strict_types=1);

/**
 * Canonical runtime helpers layered on the profile/attention storage domain.
 * A chat/poll request updates session activity but never increments profile views.
 */
function profile_runtime_session(PDO $pdo,int $ownerUserId,?array $visitor,bool $countView=false): array
{
    $visitorId=(int)($visitor['id']??0);
    $identity=$visitorId>0&&profile_visitor_discloses_identity($pdo,$visitor);
    $hash=function_exists('profile_visitor_session_hash_v243')
        ? profile_visitor_session_hash_v243($ownerUserId)
        : profile_session_hash($ownerUserId);
    $increment=$countView?1:0;
    $stmt=$pdo->prepare('INSERT INTO profile_visit_sessions (owner_user_id,visitor_user_id,session_key,identity_disclosed,view_count) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE visitor_user_id=VALUES(visitor_user_id),identity_disclosed=VALUES(identity_disclosed),view_count=view_count+VALUES(view_count),last_seen_at=NOW()');
    $stmt->execute([$ownerUserId,$visitorId?:null,$hash,$identity?1:0,$increment]);
    $get=$pdo->prepare('SELECT * FROM profile_visit_sessions WHERE owner_user_id=? AND session_key=? LIMIT 1');
    $get->execute([$ownerUserId,$hash]);
    return $get->fetch()?:throw new RuntimeException('Profile visit session could not be created.');
}

function profile_runtime_record_view(PDO $pdo,array $profile,?array $visitor): ?array
{
    $owner=(int)$profile['user_id'];
    if((int)($visitor['id']??0)===$owner)return null;
    $session=profile_runtime_session($pdo,$owner,$visitor,true);
    $window=function_exists('profile_visitor_session_hash_v243')
        ? STONEFELLOW_PROFILE_VISITOR_REENTRY_SECONDS_V243
        : STONEFELLOW_PROFILE_VIEW_DEDUPE_SECONDS;
    $bucket=(int)floor(time()/max(60,$window));
    $dedupe=hash('sha256',$session['session_key'].'|profile_view|'.$bucket);
    $agent=profile_active_agent($pdo,$profile);
    $metadata=function_exists('profile_visitor_request_context_v243')
        ? profile_visitor_request_context_v243($session)
        : [];
    $event=profile_event_create($pdo,$owner,$session,'profile_view',10,$agent?(int)$agent['id']:null,$metadata,$dedupe);
    if($event)profile_attention_from_event($pdo,$event,$session,$agent);
    return $session;
}

/**
 * Owner-safe visitor description. Signed-in status can be shown without exposing
 * identity. Name/avatar/profile/relationship are emitted only when the visitor
 * explicitly opted into visit identity sharing. contact_ref is an owner-scoped
 * pseudonymous identifier and never exposes the browser token itself.
 */
function profile_runtime_visitor_descriptor(PDO $pdo,int $ownerUserId,array $row): array
{
    $visitorId=(int)($row['visitor_user_id']??0);
    $signedIn=$visitorId>0;
    $disclosed=$signedIn&&!empty($row['identity_disclosed']);
    $contactRef=function_exists('profile_visitor_contact_ref_v243')?profile_visitor_contact_ref_v243($row):'';
    $out=[
        'signed_in'=>$signedIn,
        'identity_disclosed'=>$disclosed,
        'visitor_label'=>$signedIn?'Signed-in member':($contactRef!==''?'Guest '.substr($contactRef,2):'Guest visitor'),
        'contact_ref'=>$contactRef,
        'username'=>'',
        'profile_url'=>'',
        'avatar_url'=>'',
        'role_label'=>'',
        'relationship_scope'=>'none',
    ];
    if(!$disclosed)return $out;

    $visitor=profile_user_row($pdo,$visitorId);
    if(!$visitor)return $out;
    $out['visitor_label']=trim((string)($visitor['display_name']??''))?:'Signed-in member';
    $out['role_label']=function_exists('role_label')?role_label((string)($visitor['role']??'')):ucfirst((string)($visitor['role']??''));
    $avatarPath=(string)($visitor['avatar_path']??'');
    if($avatarPath!==''&&str_starts_with($avatarPath,'/uploads/'))$out['avatar_url']=url($avatarPath);
    $visitorProfile=profile_for_user($pdo,$visitorId,false);
    if($visitorProfile&&!empty($visitorProfile['is_public'])&&!empty($visitorProfile['username'])){
        $out['username']=(string)$visitorProfile['username'];
        $out['profile_url']=profile_public_url((string)$visitorProfile['username']);
    }
    if(table_exists('user_relationships')&&function_exists('user_relationship_scope_v236')){
        $out['relationship_scope']=user_relationship_scope_v236($pdo,$ownerUserId,$visitorId);
    }
    return $out;
}

function profile_runtime_attention_list(PDO $pdo,int $ownerUserId,int $limit=20): array
{
    $limit=max(1,min(50,$limit));
    $stmt=$pdo->prepare("SELECT a.*,s.identity_disclosed,s.session_key,s.id AS profile_session_id,c.status AS conversation_status,c.last_message_at AS conversation_last_message FROM agent_attention_items a LEFT JOIN profile_agent_conversations c ON c.id=a.source_conversation_id LEFT JOIN profile_events e ON e.id=a.source_event_id LEFT JOIN profile_visit_sessions s ON s.id=e.profile_session_id WHERE a.owner_user_id=? AND a.status IN ('pending','seen','snoozed') AND (a.snoozed_until IS NULL OR a.snoozed_until<=NOW()) ORDER BY a.priority DESC,a.created_at DESC,a.id DESC LIMIT ".$limit);
    $stmt->execute([$ownerUserId]);
    $rows=$stmt->fetchAll()?:[];
    foreach($rows as &$row){
        $row=array_merge($row,profile_runtime_visitor_descriptor($pdo,$ownerUserId,$row));
        $row['actions']=json_decode((string)($row['actions_json']??'[]'),true)?:[];
        unset($row['visitor_user_id'],$row['session_key'],$row['actions_json'],$row['context_json']);
    }
    unset($row);
    return $rows;
}

function profile_runtime_owner_state(PDO $pdo,array $user): array
{
    $uid=(int)$user['id'];
    $profile=profile_for_user($pdo,$uid,true)?:[];
    if(empty($profile['username']))$profile=profile_migrate_artist_identity($pdo,$user);
    $agents=user_agents_list_v236($pdo,$uid,true);

    $selectedAgent=null;
    $selectedAgentId=(int)($profile['profile_agent_id']??0);
    if($selectedAgentId>0)$selectedAgent=user_agent_get_v236($pdo,$uid,$selectedAgentId);
    $suggestedAgentId=0;
    foreach($agents as $agent){
        if(!empty($agent['is_profile_agent'])){$suggestedAgentId=(int)$agent['id'];break;}
    }
    $profilePublished=!empty($profile['is_public']);
    $publicEnabled=!empty($profile['profile_agent_enabled']);
    $selectedActive=(bool)($selectedAgent&&!empty($selectedAgent['is_active']));
    $publicLive=$profilePublished&&$publicEnabled&&$selectedActive;
    $publicReason='live';
    if(!$profilePublished)$publicReason='profile_private';
    elseif(!$publicEnabled)$publicReason='public_disabled';
    elseif($selectedAgentId<1)$publicReason='no_agent_selected';
    elseif(!$selectedAgent)$publicReason='agent_missing';
    elseif(!$selectedActive)$publicReason='agent_inactive';

    $visits=$pdo->prepare('SELECT id,session_key,visitor_user_id,identity_disclosed,view_count,first_seen_at,last_seen_at,last_message_at FROM profile_visit_sessions WHERE owner_user_id=? AND view_count>0 ORDER BY last_seen_at DESC,id DESC LIMIT 50');
    $visits->execute([$uid]);$visitRows=$visits->fetchAll()?:[];
    foreach($visitRows as &$v){
        $v=array_merge($v,profile_runtime_visitor_descriptor($pdo,$uid,$v));
        $v['profile_session_id']=(int)$v['id'];
        $v['repeat_visitor']=(int)($v['view_count']??0)>1;
        $v['active_now']=!empty($v['last_seen_at'])&&strtotime((string)$v['last_seen_at'])>=time()-300;
        unset($v['visitor_user_id'],$v['session_key']);
    }unset($v);

    $convos=$pdo->prepare('SELECT c.id,c.profile_agent_id,c.profile_session_id,c.status,c.last_summary,c.started_at,c.last_message_at,s.session_key,s.identity_disclosed,s.visitor_user_id FROM profile_agent_conversations c INNER JOIN profile_visit_sessions s ON s.id=c.profile_session_id WHERE c.owner_user_id=? ORDER BY c.last_message_at DESC,c.id DESC LIMIT 100');
    $convos->execute([$uid]);$conversationRows=$convos->fetchAll()?:[];
    foreach($conversationRows as &$c){$c=array_merge($c,profile_runtime_visitor_descriptor($pdo,$uid,$c));unset($c['visitor_user_id'],$c['session_key']);}unset($c);

    $events=$pdo->prepare('SELECT e.id,e.profile_session_id,e.event_type,e.priority,e.metadata_json,e.created_at,s.session_key,s.identity_disclosed,s.visitor_user_id FROM profile_events e LEFT JOIN profile_visit_sessions s ON s.id=e.profile_session_id WHERE e.owner_user_id=? ORDER BY e.created_at DESC,e.id DESC LIMIT 100');
    $events->execute([$uid]);$activityRows=$events->fetchAll()?:[];
    foreach($activityRows as &$event){
        $event=array_merge($event,profile_runtime_visitor_descriptor($pdo,$uid,$event));
        $metadata=json_decode((string)($event['metadata_json']??''),true);if(!is_array($metadata))$metadata=[];
        $event['conversation_id']=(int)($metadata['conversation_id']??0);
        $event['visit_number']=(int)($metadata['visit_number']??0);
        $event['referrer_host']=(string)($metadata['referrer_host']??'');
        $event['utm_source']=(string)($metadata['utm_source']??'');
        $event['utm_medium']=(string)($metadata['utm_medium']??'');
        $event['utm_campaign']=(string)($metadata['utm_campaign']??'');
        unset($event['visitor_user_id'],$event['session_key'],$event['metadata_json']);
    }unset($event);

    $policies=[];
    foreach(user_agent_resource_catalog_v236() as $type=>$meta){
        $p=user_data_policy_get_v236($pdo,$uid,$type);
        $policies[$type]=[
            'label'=>$meta['label'],
            'description'=>$meta['description'],
            'profile_agent_allowed'=>(bool)$p['profile_agent_allowed'],
            'audience_scope'=>(string)$p['audience_scope'],
        ];
    }

    $profileAvatarUrl=!empty($profile['avatar_path'])&&str_starts_with((string)$profile['avatar_path'],'/uploads/')?url((string)$profile['avatar_path']):'';
    $profileCoverUrl=!empty($profile['cover_path'])&&str_starts_with((string)$profile['cover_path'],'/uploads/')?url((string)$profile['cover_path']):'';

    $attention=profile_runtime_attention_list($pdo,$uid,50);
    $contacts=function_exists('profile_visitor_contact_list_v243')?profile_visitor_contact_list_v243($pdo,$uid,100):[];
    $visitStats=$pdo->prepare('SELECT COALESCE(SUM(view_count),0) AS total_views,COUNT(*) AS visitor_sessions,COALESCE(SUM(last_seen_at>=DATE_SUB(NOW(),INTERVAL 5 MINUTE)),0) AS active_visitors,COALESCE(SUM(visitor_user_id IS NOT NULL),0) AS signed_in_sessions FROM profile_visit_sessions WHERE owner_user_id=?');
    $visitStats->execute([$uid]);$visitStatRow=$visitStats->fetch()?:[];
    $conversationStats=$pdo->prepare("SELECT COUNT(*) AS total_conversations,COALESCE(SUM(status<>'resolved'),0) AS open_conversations,COALESCE(SUM(status='owner_joined'),0) AS owner_joined FROM profile_agent_conversations WHERE owner_user_id=?");
    $conversationStats->execute([$uid]);$conversationStatRow=$conversationStats->fetch()?:[];

    return [
        'build'=>STONEFELLOW_PROFILE_AGENT_BUILD,
        'namespace'=>'',
        'system_agent_name'=>system_agent_name(),
        'profile'=>$profile,
        'profile_url'=>!empty($profile['username'])?profile_public_url((string)$profile['username']):'',
        'profile_url_example'=>url('/username'),
        'profile_media'=>['avatar_url'=>$profileAvatarUrl,'cover_url'=>$profileCoverUrl],
        'agents'=>$agents,
        'public_agent_status'=>[
            'profile_published'=>$profilePublished,
            'enabled'=>$publicEnabled,
            'agent_id'=>$selectedAgentId,
            'agent_name'=>(string)($selectedAgent['display_name']??''),
            'agent_active'=>$selectedActive,
            'live'=>$publicLive,
            'reason'=>$publicReason,
            'suggested_agent_id'=>$suggestedAgentId,
        ],
        'visits'=>$visitRows,
        'contacts'=>$contacts,
        'conversations'=>$conversationRows,
        'activity'=>$activityRows,
        'attention'=>$attention,
        'notifications'=>['unread'=>notification_unread_count($user)],
        'policies'=>$policies,
        'analytics'=>[
            'total_views'=>(int)($visitStatRow['total_views']??0),
            'visitor_sessions'=>(int)($visitStatRow['visitor_sessions']??0),
            'active_visitors'=>(int)($visitStatRow['active_visitors']??0),
            'signed_in_sessions'=>(int)($visitStatRow['signed_in_sessions']??0),
            'total_conversations'=>(int)($conversationStatRow['total_conversations']??0),
            'open_conversations'=>(int)($conversationStatRow['open_conversations']??0),
            'owner_joined'=>(int)($conversationStatRow['owner_joined']??0),
            'needs_attention'=>count($attention),
            'contacts'=>count($contacts),
        ],
    ];
}

function profile_runtime_conversation_owner(PDO $pdo,int $conversationId,int $ownerUserId): ?array
{
    $stmt=$pdo->prepare('SELECT c.*,s.session_key,s.identity_disclosed,s.visitor_user_id FROM profile_agent_conversations c INNER JOIN profile_visit_sessions s ON s.id=c.profile_session_id WHERE c.id=? AND c.owner_user_id=? LIMIT 1');
    $stmt->execute([$conversationId,$ownerUserId]);
    $row=$stmt->fetch();
    if(!$row)return null;
    $row=array_merge($row,profile_runtime_visitor_descriptor($pdo,$ownerUserId,$row));
    unset($row['visitor_user_id'],$row['session_key']);
    return $row;
}

function profile_runtime_profile_preview_url(array $profile): string
{
    return !empty($profile['username'])?profile_public_url((string)$profile['username']).'?preview=1':'';
}