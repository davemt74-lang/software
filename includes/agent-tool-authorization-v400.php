<?php
declare(strict_types=1);

/**
 * VP3 v4.00 canonical Agent tool authorization and execution boundary.
 *
 * The authenticated VP3 user is the principal. Named Agents, conversation ids,
 * legacy professional account roles, package rows and plugin state never grant
 * resource authority by themselves. Professional data resolves through the
 * canonical Music Workspace/resource helpers, and browser actions are derived
 * and sanitized server-side before they are returned to Chat.
 */
const VP3_AGENT_TOOL_AUTHORIZATION_V400='vp3-agent-tool-authorization-v400-20260910';

function vp3_agent_tool_empty_v400(): array
{
    return ['handled'=>false,'answer'=>'','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[]];
}

function vp3_agent_tool_track_manage_v400(PDO $pdo,array $track,array $user): bool
{
    $uid=(int)($user['id']??0);
    if($uid<1)return false;
    if(user_has_role('admin',$user))return true;

    if(function_exists('music_workspace_resources_v330_schema_ready')
        && music_workspace_resources_v330_schema_ready($pdo)
        && function_exists('music_workspace_resources_v330_can_manage_track')){
        return music_workspace_resources_v330_can_manage_track($pdo,$track,$user);
    }

    // Upgrade-safe compatibility is deliberately resource-specific. Never fall
    // back to global Manager/Producer/Supervisor role or role-permission rows.
    $ownerId=(int)($track['owner_user_id']??0);
    $producerId=(int)($track['producer_user_id']??0);
    return ($ownerId>0&&$ownerId===$uid)||($producerId>0&&$producerId===$uid);
}

function vp3_agent_tool_production_available_v400(PDO $pdo,array $user): bool
{
    if((int)($user['id']??0)<1)return false;
    if(user_has_role('admin',$user))return true;
    if(!function_exists('music_workspace_resources_v330_schema_ready')||!music_workspace_resources_v330_schema_ready($pdo))return false;
    foreach(music_workspace_resources_v330_accessible_workspaces($pdo,$user) as $workspace){
        $workspaceId=(int)($workspace['id']??0);
        $role=(string)($workspace['access_role']??music_workspace_resources_v330_member_role($pdo,$workspaceId,(int)$user['id']));
        if(in_array($role,['owner','manager','producer'],true))return true;
    }
    return false;
}

function vp3_agent_tool_find_track_v400(string $query,array $user): ?array
{
    $pdo=db();
    if(!$pdo)return null;
    $terms=agent_tool_query_terms($query);
    try{$rows=$pdo->query('SELECT * FROM tracks ORDER BY updated_at DESC,id DESC LIMIT 300')->fetchAll()?:[];}catch(Throwable $e){return null;}
    $best=null;$bestScore=0;$normalized=mb_strtolower($query);
    foreach($rows as $track){
        if(!vp3_agent_tool_track_manage_v400($pdo,$track,$user))continue;
        $title=mb_strtolower((string)($track['title']??''));
        $haystack=mb_strtolower(trim(implode(' ',[
            (string)($track['title']??''),(string)($track['album']??''),(string)($track['genre']??''),(string)($track['mood']??''),(string)($track['keywords']??'')
        ])));
        $score=0;
        if($title!==''&&str_contains($normalized,$title))$score+=50;
        foreach($terms as $term){
            if(str_contains($title,$term))$score+=12;
            elseif(str_contains($haystack,$term))$score+=3;
        }
        if($score>$bestScore){$best=$track;$bestScore=$score;}
    }
    return $bestScore>0?$best:null;
}

function vp3_agent_tool_search_stems_v400(string $query,array $user,int $limit=8): array
{
    $pdo=db();
    if(!$pdo||!table_exists('track_stems'))return [];
    $roles=agent_tool_detect_stem_roles($query);
    $roleLookup=array_fill_keys(array_map('mb_strtolower',$roles),true);
    $terms=agent_tool_query_terms($query);
    try{
        $rows=$pdo->query(
            "SELECT s.id AS stem_id,s.track_id,s.stem_name,s.stem_role,s.duration_seconds,s.rpp_fx_summary,
                    t.*,p.project_name,p.tempo_bpm AS project_tempo,p.time_signature
             FROM track_stems s
             INNER JOIN tracks t ON t.id=s.track_id
             LEFT JOIN track_projects p ON p.track_id=t.id
             WHERE s.is_active=1
             ORDER BY t.updated_at DESC,s.sort_order,s.id
             LIMIT 700"
        )->fetchAll()?:[];
    }catch(Throwable $e){return [];}

    $scored=[];
    foreach($rows as $row){
        if(!vp3_agent_tool_track_manage_v400($pdo,$row,$user))continue;
        $stemRole=trim((string)($row['stem_role']??''));
        if($roles&&!isset($roleLookup[mb_strtolower($stemRole)]))continue;
        $haystack=mb_strtolower(trim(implode(' ',[
            (string)($row['stem_name']??''),$stemRole,(string)($row['title']??''),(string)($row['album']??''),
            (string)($row['genre']??''),(string)($row['mood']??''),(string)($row['energy']??''),
            (string)($row['keywords']??''),(string)($row['description']??''),(string)($row['rpp_fx_summary']??'')
        ])));
        $score=$roles?25:1;
        foreach($terms as $term){
            if(str_contains(mb_strtolower((string)($row['stem_name']??'')),$term))$score+=12;
            elseif(str_contains(mb_strtolower((string)($row['genre']??'')),$term))$score+=10;
            elseif(str_contains(mb_strtolower((string)($row['keywords']??'')),$term))$score+=8;
            elseif(str_contains($haystack,$term))$score+=3;
        }
        if($terms&&$score<=($roles?25:1))continue;
        $row['_score']=$score;$scored[]=$row;
    }
    usort($scored,static fn(array $a,array $b):int=>(int)$b['_score']<=>(int)$a['_score']);

    $out=[];
    foreach(array_slice($scored,0,max(1,min(12,$limit))) as $row){
        $trackId=(int)$row['track_id'];
        $out[]=[
            'kind'=>'stem','stem_id'=>(int)$row['stem_id'],'stem_name'=>(string)$row['stem_name'],
            'role'=>(string)$row['stem_role'],'track_id'=>$trackId,'song'=>(string)$row['title'],
            'album'=>(string)($row['album']?:'Stonefellow'),'genre'=>(string)$row['genre'],
            'mood'=>(string)$row['mood'],'tempo_bpm'=>(float)($row['project_tempo']?:$row['tempo_bpm']?:0),
            'duration'=>(float)$row['duration_seconds'],'fx'=>(string)$row['rpp_fx_summary'],
            'audio'=>url('/stem-media-v34.php?id='.(int)$row['stem_id']),
            'cover'=>url('/media.php?track='.$trackId.'&type=cover'),
            'song_audio'=>url('/media.php?track='.$trackId.'&type=audio'),
            'song_detail'=>url('/track.php?id='.$trackId),
            'studio'=>url('/admin/stems.php?track='.$trackId),
        ];
    }
    return $out;
}

function vp3_agent_tool_booking_workspace_ids_v400(PDO $pdo,array $user): array
{
    if((int)($user['id']??0)<1)return [];
    if(!function_exists('music_workspace_resources_v330_schema_ready')||!music_workspace_resources_v330_schema_ready($pdo))return [];
    $ids=[];
    foreach(music_workspace_resources_v330_accessible_workspaces($pdo,$user) as $workspace){
        $workspaceId=(int)($workspace['id']??0);
        if($workspaceId>0&&music_workspace_resources_v330_can_manage($pdo,$workspaceId,'shows',$user))$ids[$workspaceId]=true;
    }
    return array_map('intval',array_keys($ids));
}

function vp3_agent_tool_booking_markets_v400(array $user,int $limit=12): array
{
    $pdo=db();
    if(!$pdo||!table_exists('track_play_sessions')||!column_exists('track_play_sessions','listener_city'))return [];
    $workspaceIds=vp3_agent_tool_booking_workspace_ids_v400($pdo,$user);
    if(!$workspaceIds)return [];
    $in=implode(',',array_map('intval',$workspaceIds));
    try{
        return $pdo->query(
            "SELECT CASE WHEN TRIM(COALESCE(s.listener_city,''))<>'' THEN s.listener_city ELSE CONCAT('Area ',ROUND(s.listener_latitude,1),', ',ROUND(s.listener_longitude,1)) END AS city,
                    CASE WHEN TRIM(COALESCE(s.listener_city,''))<>'' THEN s.listener_region ELSE '' END AS region,
                    s.listener_country AS country,COUNT(*) AS starts,
                    SUM(CASE WHEN s.qualified_play=1 THEN 1 ELSE 0 END) AS qualified_plays,
                    COUNT(DISTINCT s.listener_hash) AS listeners,SUM(s.listened_seconds) AS listened_seconds
             FROM track_play_sessions s INNER JOIN tracks t ON t.id=s.track_id
             WHERE s.started_at>=DATE_SUB(NOW(),INTERVAL 90 DAY)
               AND (TRIM(COALESCE(s.listener_city,''))<>'' OR (s.listener_latitude IS NOT NULL AND s.listener_longitude IS NOT NULL))
               AND t.workspace_id IN ({$in})
             GROUP BY city,region,s.listener_country
             ORDER BY listeners DESC,qualified_plays DESC,listened_seconds DESC
             LIMIT ".max(1,min(30,$limit))
        )->fetchAll()?:[];
    }catch(Throwable $e){return [];}
}

function vp3_agent_tool_booking_shows_v400(array $user,int $limit=20): array
{
    $pdo=db();
    if(!$pdo||!table_exists('shows')||!column_exists('shows','workspace_id'))return [];
    $workspaceIds=vp3_agent_tool_booking_workspace_ids_v400($pdo,$user);
    if(!$workspaceIds)return [];
    $in=implode(',',array_map('intval',$workspaceIds));
    try{return $pdo->query('SELECT * FROM shows WHERE show_date>=NOW() AND workspace_id IN ('.$in.') ORDER BY show_date ASC LIMIT '.max(1,min(50,$limit)))->fetchAll()?:[];}catch(Throwable $e){return [];}
}

function vp3_agent_tool_booking_suggestions_v400(array $user,int $limit=8): array
{
    $markets=vp3_agent_tool_booking_markets_v400($user,20);
    $shows=vp3_agent_tool_booking_shows_v400($user,50);
    $showMarkets=[];
    foreach($shows as $show){
        $key=mb_strtolower(trim((string)($show['city']??'').'|'.(string)($show['region']??'')));
        if($key!=='|')$showMarkets[$key]=($showMarkets[$key]??0)+1;
    }
    $out=[];
    foreach($markets as $market){
        $key=mb_strtolower(trim((string)$market['city'].'|'.(string)$market['region']));
        $listeners=(int)$market['listeners'];$qualified=(int)$market['qualified_plays'];$recent=(int)($showMarkets[$key]??0);
        $market['score']=($listeners*5)+($qualified*2)-($recent*8);$market['recent_shows']=$recent;
        $market['reason']=$recent>0?'Strong listener density; already represented in the current show calendar.':'Strong listener density with no upcoming show currently listed in this market.';
        $out[]=$market;
    }
    usort($out,static fn(array $a,array $b):int=>(int)$b['score']<=>(int)$a['score']);
    return array_slice($out,0,max(1,min(20,$limit)));
}

function vp3_agent_tool_media_intent_v400(string $query,string $mode): bool
{
    if(!preg_match('/\b(?:open|show|start|use|enable|record|capture|shoot|take|snap|make)\b/i',$query))return false;
    return match($mode){
        'photo'=>(bool)preg_match('/\b(?:photo|picture|image)\b/i',$query),
        'video'=>(bool)preg_match('/\bvideo\b/i',$query),
        'audio'=>(bool)preg_match('/\b(?:voice|audio|memo|recording|microphone|mic)\b/i',$query),
        'camera'=>(bool)preg_match('/\b(?:camera|cameras|webcam|capture device)\b/i',$query),
        default=>false,
    };
}

function vp3_agent_tool_internal_action_v400(array $action,array $user,string $query): ?array
{
    $type=(string)($action['type']??'');
    if($type==='media_capture'){
        $mode=(string)($action['mode']??'camera');
        if(!in_array($mode,['camera','photo','video','audio'],true))return null;
        return [
            'type'=>'media_capture','mode'=>$mode,'camera_index'=>max(0,min(4,(int)($action['camera_index']??0))),
            'label'=>mb_substr(trim((string)($action['label']??'Open Camera')),0,120),
            'auto'=>vp3_agent_tool_media_intent_v400($query,$mode),
            'policy'=>['version'=>'v4.00','domain'=>'browser_local','risk'=>'low','requires_approval'=>false,'authorized'=>true],
        ];
    }
    if($type!=='open_url')return null;
    $raw=trim((string)($action['url']??''));
    if($raw===''||str_starts_with($raw,'//')||preg_match('#^[a-z][a-z0-9+.-]*:#i',$raw))return null;
    $parts=parse_url($raw);if($parts===false)return null;
    $path=(string)($parts['path']??'');
    if($path===''||$path[0]!=='/')return null;
    parse_str((string)($parts['query']??''),$params);
    $pdo=db();if(!$pdo)return null;

    $autoAllowed=false;
    if(str_ends_with($path,'/admin/stems.php')||$path==='/admin/stems.php'){
        $trackId=max(0,(int)($params['track']??0));
        if($trackId<1)return null;
        $stmt=$pdo->prepare('SELECT * FROM tracks WHERE id=? LIMIT 1');$stmt->execute([$trackId]);$track=$stmt->fetch();
        if(!$track||!vp3_agent_tool_track_manage_v400($pdo,$track,$user))return null;
        $autoAllowed=true;
    }elseif(str_ends_with($path,'/music-releases.php')||$path==='/music-releases.php'){
        $workspaceId=max(0,(int)($params['workspace']??0));
        if($workspaceId<1||!music_workspace_resources_v330_can_manage($pdo,$workspaceId,'releases',$user))return null;
        $autoAllowed=true;
    }elseif(str_ends_with($path,'/video-editor.php')||$path==='/video-editor.php'){
        if(!has_permission('chat.access',$user))return null;
        $autoAllowed=true;
    }else{
        // Other same-origin links may be presented as explicit navigation but are
        // never auto-run. GET navigation is not generalized into execution authority.
        $autoAllowed=false;
    }

    $explicit=(bool)preg_match('/\b(?:open|show|start|launch|go to)\b/i',$query);
    return [
        'type'=>'open_url','label'=>mb_substr(trim((string)($action['label']??'Open')),0,120),'url'=>$raw,
        'auto'=>$autoAllowed&&$explicit,
        'policy'=>['version'=>'v4.00','domain'=>'navigation','risk'=>'low','requires_approval'=>false,'authorized'=>true],
    ];
}

function vp3_agent_tool_authorize_result_v400(array $result,array $user,string $query=''): array
{
    $pdo=db();
    $stems=[];
    foreach(array_values(array_filter((array)($result['stem_media']??[]),'is_array')) as $stem){
        $trackId=max(0,(int)($stem['track_id']??0));if(!$pdo||$trackId<1)continue;
        $stmt=$pdo->prepare('SELECT * FROM tracks WHERE id=? LIMIT 1');$stmt->execute([$trackId]);$track=$stmt->fetch();
        if($track&&vp3_agent_tool_track_manage_v400($pdo,$track,$user))$stems[]=$stem;
    }
    $result['stem_media']=$stems;

    $actions=[];
    foreach(array_values(array_filter((array)($result['actions']??[]),'is_array')) as $action){
        $clean=vp3_agent_tool_internal_action_v400($action,$user,$query);
        if($clean)$actions[]=$clean;
    }
    $result['actions']=$actions;
    $result['authorization']=['version'=>'v4.00','principal_user_id'=>(int)($user['id']??0),'server_derived'=>true];
    return $result;
}

function vp3_agent_tool_execute_query_v400(string $query,array $user,int $conversationId=0): array
{
    $empty=vp3_agent_tool_empty_v400();$pdo=db();
    if(!$pdo)return $empty;

    // Team scheduling is more specific than personal scheduling and must route
    // first so "book a team meeting" cannot fall through to music Booking Agent.
    if(function_exists('agent_team_scheduling_tools_query_v610')){
        $teamScheduling=agent_team_scheduling_tools_query_v610($query,$user,$conversationId);
        if(!empty($teamScheduling['handled']))return vp3_agent_tool_authorize_result_v400($teamScheduling,$user,$query);
    }

    // Native calendar/scheduling intents run through the same principal and
    // result-sanitization boundary before the legacy music Booking Agent so
    // "book an appointment" can never be mistaken for venue research.
    if(function_exists('agent_scheduling_tools_query_v460')){
        $scheduling=agent_scheduling_tools_query_v460($query,$user,$conversationId);
        if(!empty($scheduling['handled']))return vp3_agent_tool_authorize_result_v400($scheduling,$user,$query);
    }

    $roles=agent_tool_detect_stem_roles($query);
    $productionIntent=(bool)preg_match('/\b(?:stem|stems|part|parts|instrument|instruments|instrumental|multitrack|multitracks|bass|bassline|drum|drums|beat|beats|percussion|guitar|guitars|riff|riffs|vocal|vocals|keys|piano|keyboard|synth|synths|synthesizer|stem studio|mixer)\b/i',$query);
    $productionSearchVerb=(bool)preg_match('/\b(?:show|find|search|give|list|play|return|browse)\b/i',$query);
    $openStudio=(bool)preg_match('/\bopen\b.*\b(?:stem|studio|project|editor|mixer)\b|\b(?:stem|studio|project|editor|mixer)\b.*\bopen\b/i',$query);

    if($openStudio){
        $track=vp3_agent_tool_find_track_v400($query,$user);$result=$empty;$result['handled']=true;
        if($track){
            $result['answer']='Opening the Stem Studio project for '.(string)$track['title'].'.';
            $result['actions'][]=['type'=>'open_url','label'=>'Open Stem Studio','url'=>url('/admin/stems.php?track='.(int)$track['id'])];
            agent_tool_log($user,'stem_studio.open',$query,'success',['track_id'=>(int)$track['id']],$conversationId);
        }else{
            $result['answer']='I could not find a matching production track that your current Music Workspace role is authorized to open.';
            agent_tool_log($user,'stem_studio.open',$query,'denied_or_empty',[],$conversationId);
        }
        return vp3_agent_tool_authorize_result_v400($result,$user,$query);
    }

    if($productionIntent&&($productionSearchVerb||$roles)){
        $stems=vp3_agent_tool_search_stems_v400($query,$user,10);$result=$empty;$result['handled']=true;$result['stem_media']=$stems;
        $roleLabel=$roles?implode(' + ',array_map('mb_strtolower',$roles)):'production';
        $result['answer']=$stems
            ?'I found '.count($stems).' matching '.$roleLabel.' stem'.(count($stems)===1?'':'s').' in Music Workspaces and tracks your current account is authorized to use.'
            :'I could not find matching '.$roleLabel.' stems in tracks authorized for your current Music Workspace relationships.';
        agent_tool_log($user,'stem_library.search',$query,$stems?'success':'empty',['count'=>count($stems),'roles'=>$roles],$conversationId);
        return vp3_agent_tool_authorize_result_v400($result,$user,$query);
    }

    $bookingIntent=(bool)preg_match('/\b(?:book|booking|venue|venues|gig|gigs|tour|touring|listener density|market|markets|booking opportunit|live opportunit)\b/i',$query);
    if($bookingIntent){
        $workspaceIds=vp3_agent_tool_booking_workspace_ids_v400($pdo,$user);$result=$empty;$result['handled']=true;
        if(!$workspaceIds){
            $result['answer']='Booking and listener-market data is not available to your current Music Workspace role.';
            agent_tool_log($user,'booking_agent.research',$query,'denied',[],$conversationId);
            return vp3_agent_tool_authorize_result_v400($result,$user,$query);
        }
        $suggestions=vp3_agent_tool_booking_suggestions_v400($user,8);$shows=vp3_agent_tool_booking_shows_v400($user,12);
        $location=booking_agent_search_location($query);$wantsWeb=(bool)preg_match('/\b(?:search|find|research|opportunit|venue|venues|book)\b/i',$query);$web=[];
        if($wantsWeb){
            $market=$location;
            if($market===''&&$suggestions){$candidate=trim((string)$suggestions[0]['city'].', '.(string)$suggestions[0]['region'],', ');if(!str_starts_with($candidate,'Area '))$market=$candidate;}
            $webQuery=trim(($market!==''?$market.' ':'').'live music venues booking opportunities independent artists');
            if($webQuery!==''){$web=booking_agent_web_search($webQuery,8);booking_agent_store_research($user,$webQuery,$market,$web);}
        }
        $lines=[];foreach(array_slice($suggestions,0,5) as $market)$lines[]='• '.trim((string)$market['city'].', '.(string)$market['region'],', ').' — '.(int)$market['listeners'].' listeners · '.(int)$market['qualified_plays'].' qualified plays. '.(string)$market['reason'];
        $answer='Booking Agent is active for '.count($workspaceIds).' authorized Music Workspace'.(count($workspaceIds)===1?'':'s').'. ';
        $answer.=$lines?"Best listener-density markets from the last 90 days:\n".implode("\n",$lines):'There is not enough authorized city-level listener data yet to rank markets.';
        if($shows)$answer.="\n\nI am also tracking ".count($shows).' upcoming show'.(count($shows)===1?'':'s').' in those workspaces.';
        if($wantsWeb)$answer.=$web?"\n\nI also found ".count($web).' current public web leads.':'\n\nThe live web search returned no accessible results for this request.';
        $result['answer']=$answer;foreach($web as $item)$result['sources'][]=['source'=>'web:booking','title'=>$item['title'],'url'=>$item['url']];
        agent_tool_log($user,'booking_agent.research',$query,'success',['workspace_ids'=>$workspaceIds,'markets'=>$suggestions,'web_count'=>count($web)],$conversationId);
        return vp3_agent_tool_authorize_result_v400($result,$user,$query);
    }

    $result=agent_tool_execute_query($query,$user,$conversationId);
    return vp3_agent_tool_authorize_result_v400($result,$user,$query);
}
