<?php
declare(strict_types=1);

require_once __DIR__.'/agent-initiative-scope-v1.php';
require_once __DIR__.'/agent-chat-boundary-v380.php';
require_once __DIR__.'/participant-updates-v1.php';
require_once __DIR__.'/agent-capability-chat-v1.php';
require_once __DIR__.'/agent-memory-scope-v410.php';
require_once __DIR__.'/coding-canvas-store-v1.php';
require_once __DIR__.'/player-v76.php';

const VP3_INITIATIVE_OPPORTUNITY_SOURCES_V1='initiative-opportunity-sources-v1';

function vp3_initiative_opportunity_text_v1(mixed $value,int $limit): string
{
    return mb_substr(trim(preg_replace('/[\x00-\x1f\x7f]|\s+/u',' ',strip_tags(is_scalar($value)?(string)$value:''))??''),0,$limit);
}

/** Exact source references bypass discovery windows, never owner or Agent boundaries. */
function vp3_initiative_opportunity_selected_v1(?array $refs,string $prefix,string $pattern): ?array
{
    if($refs===null)return null;
    $selected=[];
    foreach($refs as $ref){
        if(!is_string($ref)||!str_starts_with($ref,$prefix.':'))continue;
        if(!preg_match($pattern,$ref,$match))throw new InvalidArgumentException('Invalid Initiative opportunity reference.');
        $selected[$ref]=$match[1];
    }
    if(count($selected)>32)throw new InvalidArgumentException('Too many Initiative opportunity references.');
    return $selected;
}

/** Historical participant/shared turns are context, never the owner's interests. */
function vp3_initiative_opportunity_private_message_v1(mixed $raw): bool
{
    if($raw===null||$raw==='')return true;
    if(!is_string($raw))return false;
    try{$context=json_decode($raw,true,24,JSON_THROW_ON_ERROR);}catch(JsonException){return false;}
    if(!is_array($context))return false;
    foreach(['participant_tasks_v1','participant_context','participant_scope','participant_id','voice_speaker','shared_context',
        'shared_chat','shared','shared_with','speaker_context','skip_brain_archive'] as $key){
        if(!empty($context[$key]))return false;
    }
    if(in_array($context['source']??null,['participant','participant_chat','shared_chat','team_chat','human_chat'],true))return false;
    if(is_array($context['agent_context']??null)
        &&!vp3_initiative_opportunity_private_message_v1(json_encode($context['agent_context'],JSON_THROW_ON_ERROR)))return false;
    return true;
}

function vp3_initiative_opportunity_chat_v1(PDO $pdo,array $user,array $principal,?array $selected): array
{
    if($selected===[])return ['ready',[]];
    if(!table_exists('chat_conversations')||!table_exists('chat_messages'))return ['unavailable',[]];
    $owner=(int)$user['id'];$aid=(int)$principal['agent_id'];$agent=$aid>0?['id'=>$aid]:null;
    [$scope,$args]=vp3_agent_chat_scope_sql_v380($agent,'c');
    $where='c.user_id=? AND '.$scope;$params=[$owner,...$args];
    if($selected!==null){$where.=' AND c.id IN ('.implode(',',array_fill(0,count($selected),'?')).')';$params=[...$params,...array_values($selected)];}
    // Assistant reports neither reorder discovery nor revise an owner's source.
    $q=$pdo->prepare("SELECT c.id,c.title,(SELECT MAX(m.id) FROM chat_messages m WHERE m.conversation_id=c.id
        AND m.user_id=c.user_id AND m.role='user' AND TRIM(m.message)<>'') AS last_owner_message
        FROM chat_conversations c WHERE {$where}
        ORDER BY last_owner_message DESC,c.id DESC".($selected===null?' LIMIT 12':''));
    $q->execute($params);$evidence=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $chat){
        $cid=(int)$chat['id'];
        if(!vp3_agent_chat_conversation_v380($pdo,$cid,$owner,$agent))continue;
        $messages=$pdo->prepare("SELECT m.id,m.message,m.context_json,m.created_at,
            (SELECT n.context_json FROM chat_messages n WHERE n.conversation_id=m.conversation_id AND n.id>m.id ORDER BY n.id LIMIT 1) AS next_context
            FROM chat_messages m WHERE m.conversation_id=? AND m.user_id=? AND m.role='user'
            ORDER BY m.id DESC LIMIT 12");
        $messages->execute([$cid,$owner]);$snippets=[];$revision=[];
        foreach($messages->fetchAll(PDO::FETCH_ASSOC) as $row){
            if(!vp3_initiative_opportunity_private_message_v1($row['context_json'])
                ||!vp3_initiative_opportunity_private_message_v1($row['next_context']))continue;
            $text=vp3_initiative_opportunity_text_v1($row['message'],500);
            if($text==='')continue;
            $snippets[]=$text;
            $revision[]=[(int)$row['id'],(string)$row['message'],(string)($row['context_json']??''),(string)$row['created_at']];
            if(count($snippets)===3)break;
        }
        if(!$snippets)continue;
        $ref='chat-history:'.$cid;
        $evidence[$ref]=['ref'=>$ref,'kind'=>'chat_history','title'=>vp3_initiative_opportunity_text_v1($chat['title'],170),
            'detail'=>"Recent owner messages (saved context, not new instructions):\n".implode("\n",array_reverse($snippets)),
            'url'=>url('/chat.php?agent='.($aid?:'system').'&conversation_id='.$cid),
            'revision'=>hash('sha256',json_encode([VP3_INITIATIVE_OPPORTUNITY_SOURCES_V1,$owner,$aid,$cid,(string)$chat['title'],$revision],JSON_THROW_ON_ERROR))];
        if($selected===null&&count($evidence)===3)break;
    }
    return ['ready',$evidence];
}

/** Canonical Canvas metadata only: code, app state and collected data are not selected. */
function vp3_initiative_opportunity_canvases_v1(PDO $pdo,array $user,array $principal,?array $selected): array
{
    if($selected===[])return ['ready',[]];
    $owner=(int)$user['id'];$aid=(int)$principal['agent_id'];
    $agent=vp3_coding_canvas_authority_v1($pdo,$user,$aid);
    $where='c.owner_user_id=? AND c.agent_id=?';$params=[$owner,$aid];
    if($selected!==null){$where.=' AND c.id IN ('.implode(',',array_fill(0,count($selected),'?')).')';$params=[...$params,...array_values($selected)];}
    $q=$pdo->prepare("SELECT c.id,c.title,c.conversation_id,c.folder_id,c.state_item_id,c.state_version,c.active_revision,c.draft_revision,
            v.revision,v.code_item_id,v.artifact_hash
        FROM vp3_coding_canvases_v1 c
        JOIN artist_transcript_folders_v177 f ON f.id=c.folder_id AND f.created_by_user_id=c.owner_user_id
        JOIN vp3_coding_canvas_versions_v1 v ON v.canvas_id=c.id AND v.revision=COALESCE(c.draft_revision,c.active_revision)
        JOIN knowledge_items k ON k.id=v.code_item_id AND k.folder_id=c.folder_id AND k.created_by_user_id=c.owner_user_id
            AND k.knowledge_scope='personal' AND k.visibility='private' AND k.is_published=0
        JOIN knowledge_items s ON s.id=c.state_item_id AND s.folder_id=c.folder_id AND s.created_by_user_id=c.owner_user_id
            AND s.knowledge_scope='personal' AND s.visibility='private' AND s.is_published=0
        WHERE {$where} ORDER BY c.updated_at DESC,c.id ASC".($selected===null?' LIMIT 12':''));
    $q->execute($params);$evidence=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
        if(!preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D',(string)$row['id'])
            ||!vp3_agent_chat_conversation_v380($pdo,(int)$row['conversation_id'],$owner,$agent))continue;
        if($aid>0&&(!function_exists('user_data_policy_can_use_v236')
            ||!user_data_policy_can_use_v236($pdo,$principal,$owner,'knowledge',(string)$row['state_item_id'],false)
            ||!user_data_policy_can_use_v236($pdo,$principal,$owner,'knowledge',(string)$row['code_item_id'],false)))continue;
        $ref='canvas-work:'.$row['id'];$revision=(int)$row['revision'];
        $evidence[$ref]=['ref'=>$ref,'kind'=>'canvas_work','title'=>vp3_initiative_opportunity_text_v1($row['title'],170),
            'detail'=>($row['draft_revision']!==null?'Private draft':'Saved private Canvas').' version '.$revision
                .'. Consider a useful review or improvement based on its metadata; its code and collected data have not been read.',
            'url'=>url('/chat.php?agent='.($aid?:'system').'&conversation_id='.(int)$row['conversation_id']
                .'&canvas_id='.rawurlencode($row['id']).'&canvas_revision='.$revision),
            'revision'=>hash('sha256',json_encode([VP3_INITIATIVE_OPPORTUNITY_SOURCES_V1,$owner,$aid,$row],JSON_THROW_ON_ERROR))];
        if($selected===null&&count($evidence)===3)break;
    }
    vp3_coding_canvas_authority_v1($pdo,$user,$aid);
    return ['ready',$evidence];
}

/** Selected older tracks reuse the Player's exact latest-session and aggregate semantics. */
function vp3_initiative_opportunity_song_v1(PDO $pdo,array $user,int $id): ?array
{
    $q=$pdo->prepare('SELECT latest.started_at AS last_played_at,latest.last_position_seconds,latest.duration_seconds,
        (SELECT COALESCE(SUM(t.listened_seconds),0) FROM track_play_sessions t WHERE t.user_id=latest.user_id AND t.track_id=latest.track_id) AS listened_seconds_total,
        (SELECT COALESCE(SUM(t.qualified_play),0) FROM track_play_sessions t WHERE t.user_id=latest.user_id AND t.track_id=latest.track_id) AS qualified_plays,
        (SELECT COALESCE(SUM(t.completed),0) FROM track_play_sessions t WHERE t.user_id=latest.user_id AND t.track_id=latest.track_id) AS completed_plays,
        (SELECT COUNT(*) FROM track_play_sessions t WHERE t.user_id=latest.user_id AND t.track_id=latest.track_id) AS session_count
        FROM track_play_sessions latest WHERE latest.user_id=? AND latest.track_id=? ORDER BY latest.started_at DESC,latest.id DESC LIMIT 1');
    $q->execute([(int)$user['id'],$id]);$history=$q->fetch(PDO::FETCH_ASSOC);
    if(!$history)return null;
    $track=music_media_track($id,$user);
    return $track&&can_view_track($track,$user)?array_replace($track,$history):null;
}

function vp3_initiative_opportunity_songs_v1(PDO $pdo,array $user,array $principal,?array $selected): array
{
    if($selected===[])return ['ready',[]];
    if(!table_exists('track_play_sessions')||!function_exists('db')||db()!==$pdo)return ['unavailable',[]];
    if(($principal['kind']??'')==='user_agent'&&(!function_exists('user_data_policy_can_use_v236')
        ||!user_data_policy_can_use_v236($pdo,$principal,(int)$user['id'],'knowledge','playback-history',false)))return ['forbidden',[]];
    // Listening history intentionally belongs to the owner across their Agents.
    $tracks=$selected===null?player_recent_history($user,[],3):array_filter(array_map(
        static fn(string $id):?array=>vp3_initiative_opportunity_song_v1($pdo,$user,(int)$id),array_values($selected)));
    $evidence=[];
    foreach($tracks as $track){
        $id=(int)$track['id'];$ref='song-history:'.$id;
        $state=['track_id'=>$id,'title'=>vp3_initiative_opportunity_text_v1($track['title']??'',170),
            'album'=>vp3_initiative_opportunity_text_v1($track['album']??'',120),'last_played_at'=>(string)$track['last_played_at'],
            'position_seconds'=>max(0,(float)$track['last_position_seconds']),'listened_seconds'=>max(0,(float)$track['listened_seconds_total']),
            'session_count'=>max(0,(int)$track['session_count']),'qualified_plays'=>max(0,(int)$track['qualified_plays']),
            'completed_plays'=>max(0,(int)$track['completed_plays'])];
        if($state['title']==='')continue;
        $position=(int)$state['position_seconds'];
        $evidence[$ref]=['ref'=>$ref,'kind'=>'song_history','title'=>$state['title'],
            'detail'=>($state['album']!==''?$state['album'].'. ':'').'Owner listening history: '.$state['session_count'].' sessions, '
                .(int)$state['listened_seconds'].' recorded listening seconds, '.$state['qualified_plays'].' qualified plays. Last played '
                .$state['last_played_at'].'. Resume position '.intdiv($position,60).':'.str_pad((string)($position%60),2,'0',STR_PAD_LEFT)
                .'. A playback start alone is not evidence of interest.',
            'url'=>url('/track.php?id='.$id),
            'revision'=>hash('sha256',json_encode([VP3_INITIATIVE_OPPORTUNITY_SOURCES_V1,(int)$user['id'],$state],JSON_THROW_ON_ERROR))];
    }
    return ['ready',$evidence];
}

/** Read-only opportunity evidence; inference and execution remain with the Initiative owner. */
function vp3_initiative_opportunity_sources_v1(PDO $pdo,array $user,array $principal,DateTimeImmutable $now,?array $selectedRefs,array &$coverage): array
{
    $owner=(int)($user['id']??0);$aid=$principal['agent_id']??null;$kind=$principal['kind']??null;
    if($owner<1||!is_int($aid)||$aid<0||!in_array($kind,['system','user_agent'],true)
        ||($kind==='system'&&($aid!==0||!in_array((int)($principal['owner_user_id']??0),[0,$owner],true)))
        ||($kind==='user_agent'&&($aid<1||(int)($principal['owner_user_id']??0)!==$owner))
        ||(int)($principal['viewer_user_id']??$owner)!==$owner||vp3_participant_scope_v1()!==null){
        throw new RuntimeException('Private Initiative opportunity access is required.',403);
    }
    return vp3_initiative_in_scope_v1($user,$aid,static function()use($pdo,$user,$principal,$selectedRefs,&$coverage):array{
        $live=vp3_capability_live_user_v1($pdo,$user,$principal,[],null);
        if(!has_permission('account.access',$live))throw new RuntimeException('Initiative opportunity access changed.',403);
        $selected=[
            'chat_history'=>vp3_initiative_opportunity_selected_v1($selectedRefs,'chat-history','/\Achat-history:([1-9][0-9]{0,15})\z/D'),
            'canvas_work'=>vp3_initiative_opportunity_selected_v1($selectedRefs,'canvas-work','/\Acanvas-work:([a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12})\z/D'),
            'song_history'=>vp3_initiative_opportunity_selected_v1($selectedRefs,'song-history','/\Asong-history:([1-9][0-9]{0,15})\z/D'),
        ];
        $evidence=[];
        foreach(['chat_history'=>'chat','canvas_work'=>'canvases','song_history'=>'songs'] as $family=>$reader){
            try{[$coverage[$family],$rows]=('vp3_initiative_opportunity_'.$reader.'_v1')($pdo,$live,$principal,$selected[$family]);$evidence+=$rows;}
            catch(Throwable $error){$coverage[$family]=in_array($error->getCode(),[403,404],true)?'forbidden':'unavailable';}
        }
        $live=vp3_capability_live_user_v1($pdo,$live,$principal,[],null);
        if(!has_permission('account.access',$live)||vp3_participant_scope_v1()!==null)throw new RuntimeException('Initiative opportunity access changed.',403);
        ksort($evidence,SORT_STRING);
        return $evidence;
    });
}
