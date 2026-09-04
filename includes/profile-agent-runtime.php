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
    $hash=profile_session_hash($ownerUserId);
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
    $bucket=(int)floor(time()/STONEFELLOW_PROFILE_VIEW_DEDUPE_SECONDS);
    $dedupe=hash('sha256',$session['session_key'].'|profile_view|'.$bucket);
    $agent=profile_active_agent($pdo,$profile);
    $event=profile_event_create($pdo,$owner,$session,'profile_view',10,$agent?(int)$agent['id']:null,[],$dedupe);
    if($event)profile_attention_from_event($pdo,$event,$session,$agent);
    return $session;
}

function profile_runtime_attention_list(PDO $pdo,int $ownerUserId,int $limit=20): array
{
    $limit=max(1,min(50,$limit));
    $stmt=$pdo->prepare("SELECT a.*,s.identity_disclosed,s.id AS profile_session_id,c.status AS conversation_status,c.last_message_at AS conversation_last_message FROM agent_attention_items a LEFT JOIN profile_agent_conversations c ON c.id=a.source_conversation_id LEFT JOIN profile_events e ON e.id=a.source_event_id LEFT JOIN profile_visit_sessions s ON s.id=e.profile_session_id WHERE a.owner_user_id=? AND a.status IN ('pending','seen','snoozed') AND (a.snoozed_until IS NULL OR a.snoozed_until<=NOW()) ORDER BY a.priority DESC,a.created_at DESC,a.id DESC LIMIT ".$limit);
    $stmt->execute([$ownerUserId]);
    $rows=$stmt->fetchAll()?:[];
    foreach($rows as &$row){
        $row['visitor_label']='Someone';
        if(!empty($row['identity_disclosed'])&&(int)($row['visitor_user_id']??0)>0){
            $u=profile_user_row($pdo,(int)$row['visitor_user_id']);
            if($u)$row['visitor_label']=(string)$u['display_name'];
        }
        $row['actions']=json_decode((string)($row['actions_json']??'[]'),true)?:[];
        unset($row['actions_json'],$row['context_json']);
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

    $visits=$pdo->prepare('SELECT id,visitor_user_id,identity_disclosed,view_count,first_seen_at,last_seen_at FROM profile_visit_sessions WHERE owner_user_id=? AND view_count>0 ORDER BY last_seen_at DESC,id DESC LIMIT 20');
    $visits->execute([$uid]);$visitRows=$visits->fetchAll()?:[];
    foreach($visitRows as &$v){$v['visitor_label']=profile_visitor_label($pdo,$v);unset($v['visitor_user_id']);}unset($v);

    $convos=$pdo->prepare('SELECT c.id,c.profile_agent_id,c.status,c.last_summary,c.started_at,c.last_message_at,s.identity_disclosed,s.visitor_user_id FROM profile_agent_conversations c INNER JOIN profile_visit_sessions s ON s.id=c.profile_session_id WHERE c.owner_user_id=? ORDER BY c.last_message_at DESC,c.id DESC LIMIT 30');
    $convos->execute([$uid]);$conversationRows=$convos->fetchAll()?:[];
    foreach($conversationRows as &$c){$c['visitor_label']=profile_visitor_label($pdo,$c);unset($c['visitor_user_id']);}unset($c);

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

    return [
        'build'=>STONEFELLOW_PROFILE_AGENT_BUILD,
        'namespace'=>STONEFELLOW_PROFILE_NAMESPACE,
        'system_agent_name'=>system_agent_name(),
        'profile'=>$profile,
        'profile_url'=>!empty($profile['username'])?profile_public_url((string)$profile['username']):'',
        'agents'=>$agents,
        'visits'=>$visitRows,
        'conversations'=>$conversationRows,
        'attention'=>profile_runtime_attention_list($pdo,$uid,20),
        'policies'=>$policies,
    ];
}

function profile_runtime_conversation_owner(PDO $pdo,int $conversationId,int $ownerUserId): ?array
{
    $stmt=$pdo->prepare('SELECT c.*,s.identity_disclosed,s.visitor_user_id FROM profile_agent_conversations c INNER JOIN profile_visit_sessions s ON s.id=c.profile_session_id WHERE c.id=? AND c.owner_user_id=? LIMIT 1');
    $stmt->execute([$conversationId,$ownerUserId]);
    $row=$stmt->fetch();
    if(!$row)return null;
    $row['visitor_label']=profile_visitor_label($pdo,$row);
    unset($row['visitor_user_id']);
    return $row;
}

function profile_runtime_profile_preview_url(array $profile): string
{
    return !empty($profile['username'])?profile_public_url((string)$profile['username']).'?preview=1':'';
}
