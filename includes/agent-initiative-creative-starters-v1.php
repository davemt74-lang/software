<?php
declare(strict_types=1);

const VP3_CREATIVE_STARTER_PLAYLIST_SUBJECT_V1='instrumental focus music';

/** Current capabilities, not age or invented preferences, determine which starters are usable. */
function vp3_creative_starter_tools_v1(PDO $pdo,array $user,array $binding): array
{
    $tools=[];
    try{
        require_once __DIR__.'/agent-image-generation-v1.php';
        vp3_image_queue_ready_v1($pdo,$user,false);
        $tools[]='image.prepare';
    }catch(Throwable $error){}
    try{
        require_once __DIR__.'/agent-initiative-canvas-v1.php';
        require_once __DIR__.'/coding-canvas-store-v1.php';
        $live=vp3_coding_canvas_scope_v1($pdo,$user,vp3_initiative_binding_agent_v1($binding),(int)$binding['conversation_id']);
        vp3_coding_canvas_authority_v1($pdo,$live,vp3_initiative_binding_agent_v1($binding),true);
        $tools[]='canvas.prepare';
    }catch(Throwable $error){}
    try{
        require_once __DIR__.'/agent-initiative-youtube-playlist-v1.php';
        require_once __DIR__.'/agent-connection-youtube-chat-v1.php';
        require_once __DIR__.'/agent-media-entertainment-v1.php';
        if(vp3_media_offers_enabled_v1($pdo,(int)$user['id'])&&agent_connection_youtube_ready_v1()
            &&agent_connection_youtube_consent_v1($pdo,(int)$user['id'])
            &&vp3_initiative_youtube_playlist_ready_v1($pdo)&&agent_connection_youtube_quota_ready_v1($pdo)
            &&agent_connection_youtube_results_ready_v1($pdo)){
            $tools[]='playlist.prepare';
        }
    }catch(Throwable $error){}
    return $tools;
}

/** Exact rereads remain valid after the owner starts using other features. */
function vp3_creative_starter_evidence_v1(PDO $pdo,array $user,array $binding,?array $selectedRefs,array $base): array
{
    if(empty($binding['enabled'])||(int)($binding['conversation_id']??0)<1)return [];
    $user=vp3_initiative_access_v1($pdo,$user,$binding);
    $owner=(int)$user['id'];$agent=vp3_initiative_binding_agent_v1($binding);
    $ref='creative-starter:'.$owner.':'.$agent;
    if($selectedRefs!==null){
        if(!in_array($ref,$selectedRefs,true))return [];
    }else{
        $setup=['account_readiness','scheduling_readiness','calendar_readiness','product_readiness','business_readiness','chat_history'];
        if(array_filter($base,static fn(array $source):bool=>!in_array($source['kind']??null,$setup,true)))return [];
    }
    $tools=vp3_creative_starter_tools_v1($pdo,$user,$binding);
    if(!$tools)return [];
    $detail='Optional general starter creations are available without personal history. '
        .'Consider an original abstract wallpaper, a short instrumental focus playlist, or a small functional focus timer or calculator. '
        .'These are generic possibilities, not established owner preferences. Use only the supplied ready tools. '
        .'A Canvas follows the existing review or Auto Build settings; an image concept still needs normal generation authorization.';
    return [$ref=>['ref'=>$ref,'kind'=>'creative_starter','title'=>'Optional starter creations','detail'=>$detail,
        'url'=>url('/chat.php?agent='.$agent.'&conversation_id='.$binding['conversation_id']),
        'revision'=>hash('sha256',json_encode(['creative-starter-v1',$owner,$agent,$binding['revision']],JSON_THROW_ON_ERROR)),
        'starter_tools'=>$tools]];
}

function vp3_starter_playlist_catalog_v1(array $evidence): array
{
    $refs=array_keys(array_filter($evidence,static fn(array $source):bool=>($source['kind']??null)==='creative_starter'
        &&in_array('playlist.prepare',$source['starter_tools']??[],true)));
    return $refs?['playlist.prepare'=>['id'=>'playlist.prepare','capability'=>'media.youtube.search','mode'=>'read',
        'ready'=>true,'reason'=>'ready','source_refs'=>$refs,'context_refs'=>$refs,
        'description'=>'Prepare a small playable generic instrumental-focus playlist using the existing consented, quota-metered YouTube search and video verification. No history or inferred taste is required. The public query is fixed by the server. Set report to true.']]:[];
}

function vp3_starter_playlist_draft_v1(): string
{
    return 'Here’s a starter playlist of instrumental focus music. Tell me a mood or genre to tailor the next one.';
}

function vp3_starter_playlist_tool_result_v1(array $value): ?array
{
    $out=$value['output']??null;
    if(array_keys($value)!==['draft','output']||$value['draft']!==vp3_starter_playlist_draft_v1()||!is_array($out)
        ||!in_array(array_keys($out),[['type','status','parent_run_id','source_ref','source_revision'],
            ['type','status','parent_run_id','source_ref','source_revision','playlist_run_id']],true)
        ||$out['type']!=='initiative_starter_playlist'||$out['status']!=='playlist_ready'
        ||!is_int($out['parent_run_id'])||$out['parent_run_id']<1
        ||!is_string($out['source_ref'])||!preg_match('/\Acreative-starter:[1-9][0-9]{0,15}:[0-9]{1,16}\z/D',$out['source_ref'])
        ||!is_string($out['source_revision'])||!preg_match('/\A[a-f0-9]{64}\z/D',$out['source_revision'])
        ||(isset($out['playlist_run_id'])&&$out['playlist_run_id']!==$out['parent_run_id']))return null;
    return $value;
}

function vp3_starter_playlist_prepare_v1(PDO $pdo,array $scope,array $binding,array $job,array $evidence,
    callable $authorize,?callable $http,array $workflow): array
{
    $primary=$job['primary'];$source=$evidence[$primary]??null;
    if(($source['kind']??null)!=='creative_starter'||!in_array('playlist.prepare',$source['starter_tools']??[],true)
        ||($job['report']??null)!==true||$job['refs']!==[$primary]
        ||!is_int($workflow['run_id']??null)||$workflow['run_id']<1){
        throw new InvalidArgumentException('A starter playlist requires its exact private assignment.');
    }
    $authorize();
    $playlist=vp3_initiative_youtube_playlist_prepare_v1($pdo,(int)$scope['user']['id'],
        VP3_CREATIVE_STARTER_PLAYLIST_SUBJECT_V1,'topic','playlist',[],$authorize,$http);
    $authorize();
    if($playlist===null)throw new Vp3InitiativePublicSearchUnavailableV1();
    $playlist['suggestion_reason']='A general starter playlist.';$playlist['music_suggestion']=true;
    $result=['draft'=>vp3_starter_playlist_draft_v1(),'output'=>['type'=>'initiative_starter_playlist','status'=>'playlist_ready',
        'parent_run_id'=>$workflow['run_id'],'source_ref'=>$primary,'source_revision'=>$source['revision']]];
    if(vp3_starter_playlist_tool_result_v1($result)===null)throw new InvalidArgumentException('Invalid starter playlist.');
    return $result+['youtube_playlist'=>$playlist];
}

function vp3_starter_playlist_current_v1(PDO $pdo,array $user,array $binding,array $goal,array $result): void
{
    $normalized=vp3_starter_playlist_tool_result_v1($result);$out=$normalized['output']??null;
    if($normalized===null||($goal['report']??null)!==true||($goal['primary']??null)!==$out['source_ref']
        ||($goal['sources']??null)!==[$out['source_ref']=>$out['source_revision']])throw new Vp3InitiativeSourcesChangedV1();
    $q=$pdo->prepare("SELECT goal FROM agent_workflow_runs WHERE id=? AND owner_user_id=?
        AND CAST(COALESCE(agent_id,0) AS CHAR)=? AND source_kind='agent_initiative' AND capability_key='initiative.prepare'");
    $q->execute([$out['parent_run_id'],(int)$user['id'],(string)vp3_initiative_binding_agent_v1($binding)]);
    $stored=json_decode((string)$q->fetchColumn(),true,16);
    foreach(['binding','tool','primary','sources'] as $key){
        if(($stored[$key]??null)!==($goal[$key]??null))throw new Vp3InitiativeSourcesChangedV1();
    }
    if(isset($out['playlist_run_id'])&&vp3_initiative_youtube_playlist_load_v1($pdo,(int)$user['id'],$out['playlist_run_id'])===null){
        // Delivery moves provider metadata from the pending cache into the existing Chat result ledger.
        $q=$pdo->prepare("SELECT id,context_json FROM chat_messages WHERE conversation_id=? AND role='assistant'
            AND CAST(JSON_EXTRACT(CASE WHEN JSON_VALID(context_json) THEN context_json ELSE '{}' END,
                '$.initiative_run_id') AS CHAR)=? ORDER BY id DESC LIMIT 1");
        $q->execute([(int)$binding['conversation_id'],(string)$out['parent_run_id']]);$found=false;
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $message){
            $context=json_decode((string)$message['context_json'],true,16);
            if(($context['initiative_run_id']??null)!==$out['parent_run_id'])continue;
            $saved=agent_connection_youtube_results_read_v1($pdo,(int)$user['id'],vp3_initiative_binding_agent_v1($binding),
                (int)$binding['conversation_id'],[(int)$message['id']]);
            if(count($saved[(int)$message['id']]['videos']??[])>=2){$found=true;break;}
        }
        if(!$found)throw new Vp3InitiativeSourcesChangedV1();
    }
}
