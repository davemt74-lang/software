<?php
declare(strict_types=1);

require_once __DIR__.'/cloud-mission-web-v1.php';
require_once __DIR__.'/agent-initiative-model-v1.php';
require_once __DIR__.'/agent-tool-receipts-v1.php';

require_once __DIR__.'/agent-public-research-plan-v1.php';

function vp3_artist_research_query_v1(array $plan,string $focus): string
{
    $artist='"'.str_replace('"','',$plan['artist']).'"';
    $local=$plan['location']!==''?' "'.str_replace('"','',$plan['location']).'"':'';
    return $artist.match($focus){
        'news'=>' latest music release news interview',
        'shows'=>' upcoming concerts tour dates'.$local,
        'merch'=>' official merchandise vinyl store',
        'videos'=>' official live performances music videos',
    };
}

/** Bounded public retrieval. Callers provide an exact live owner/Agent/Chat authorization check. */
function vp3_public_research_collect_v1(PDO $pdo,array $user,int $agentId,array $plan,callable $authority,?array $transport=null): array
{
    if(!vp3_public_research_plan_valid_v1($plan)||$plan['mode']!=='direct')throw new InvalidArgumentException('Use a public research subject.');
    if($pdo->inTransaction())throw new RuntimeException('Public research cannot hold a database transaction.',503);
    $general=array_key_exists('topic',$plan);
    $subject=$general?$plan['topic']:$plan['artist'];
    $sourceKind=$general?'topic':'artist';
    $protocol=$general?'vp3.topic-research.v1':'vp3.artist-research.v1';
    $checkedAt=gmdate('Y-m-d\TH:i:s\Z');
    $result=static fn(string $status,string $reason,string $answer,array $sources=[]): array=>[
        'status'=>$status,'reason'=>$reason,'answer'=>$answer,'sources'=>$sources,'checked_at'=>$checkedAt,
    ];
    $overview=!$general&&$plan['focus']==='overview';
    $topics=$general?['topic']:($overview?['news','shows','merch','videos']:[$plan['focus']]);
    $labels=['news'=>'news and releases','shows'=>'upcoming shows','merch'=>'merchandise','videos'=>'performance videos','topic'=>'the topic'];
    $checked=[];$unavailable=[];$groups=[];
    foreach($topics as $focus){
        $authority();
        $query=$general?$subject:vp3_artist_research_query_v1($plan,$focus);
        try{$discovery=($transport['search']??'booking_agent_web_search_response')($query,6,$overview?3:6);}
        catch(Throwable $e){$discovery=[];}
        $authority();
        if(($discovery['ok']??false)!==true||!is_array($discovery['results']??null)){
            $unavailable[]=$labels[$focus];continue;
        }
        $checked[]=$labels[$focus];$group=[];
        foreach(array_slice($discovery['results'],0,6) as $hit){
            if(!is_array($hit)||!is_string($hit['url']??null))continue;
            try{$url=cloud_mission_web_identity_v1(cloud_mission_web_url_v1($hit['url']));}
            catch(InvalidArgumentException $e){continue;}
            $title=vp3_artist_research_text_v1($hit['title']??null,190);
            if($title===''||preg_match('~https?://~iu',$title))continue;
            $id=$sourceKind.'-web:'.hash('sha256',$url);
            $group[$id]=['source'=>$id,'title'=>$title,'url'=>$url,'coverage'=>'search_match',
                'checked_at'=>$checkedAt,'evidence_hash'=>hash('sha256',$title)];
        }
        if($group)$groups[]=$group;
    }
    if(!$checked){
        return $result('failed','provider_unavailable','Public web search is unavailable right now. I couldn’t verify any new findings.');
    }
    $coverage=$overview?'Checked '.implode(', ',$checked).'.':'';
    if($unavailable)$coverage.=' Search unavailable for '.implode(', ',$unavailable).'.';
    $sources=[];
    // Keep the first useful lead for each area before filling the remaining slots.
    foreach($groups as $group)$sources+=array_slice($group,0,1,true);
    foreach($groups as $group)$sources+=$group;
    $sources=array_slice($sources,0,6,true);
    if(!$sources)return $result('no_matches','',trim('The public search returned no eligible sources for '.$subject.'. '.$coverage));

    $evidence=[];
    foreach(array_slice($sources,0,2,true) as $id=>$source){
        if(!isset($transport['request'])&&!cloud_mission_web_ready_v1())break;
        $authority();
        try{
            $domain=(string)parse_url($source['url'],PHP_URL_HOST);
            $response=($transport['request']??'cloud_mission_web_request_v1')($source['url'],[$domain],static function():void{},null,$overview?3:5);
            $page=cloud_mission_web_extract_v1($response);
        }catch(Throwable $e){$page=null;}
        $authority();
        if($page===null)continue;
        $sources[$id]['coverage']='page_excerpt';
        $sources[$id]['evidence_hash']=hash('sha256',$page['excerpt']);
        $evidence[$id]=['source'=>$id,'title'=>$source['title'],'excerpt'=>$page['excerpt']];
    }

    $findings=[];
    if($evidence){
        $request=$general?['topic'=>$subject]:['artist'=>$subject,'location'=>$plan['location'],'focus'=>$plan['focus']];
        $prompt=json_encode($request+['checked_at'=>$checkedAt,'sources'=>array_values($evidence)],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        try{
            $authority();
            $raw=isset($transport['model'])?($transport['model'])($prompt,$protocol):vp3_ai_with_budget_v1(10,
                static fn()=>vp3_initiative_infer_v1($pdo,$user,$agentId,$prompt,$protocol,false,$authority));
            $output=vp3_tool_json_object_v1($raw,['findings'],12000);
            if(is_array($output['findings']??null)&&array_is_list($output['findings'])){
                foreach(array_slice($output['findings'],0,4) as $finding){
                    if(!is_array($finding)||!is_string($finding['source']??null)||!isset($evidence[$finding['source']]))continue;
                    $quote=$finding['quote']??null;
                    if(!is_string($quote)||mb_strlen($quote)<12||mb_strlen($quote)>220
                        ||vp3_artist_research_text_v1($quote,220)!==$quote||preg_match('~https?://|[<>]~iu',$quote)
                        ||!str_contains($evidence[$finding['source']]['excerpt'],$quote))continue;
                    $findings[$quote]='“'.$quote.'” — '.$sources[$finding['source']]['title'];
                }
            }
        }catch(Throwable $e){/* Retain confirmed public sources if synthesis is unavailable. */}
        $authority();
    }
    $answer=$findings?'Public source excerpts for '.$subject."\n\n".implode("\n",array_values($findings))
        :($evidence?'I opened public sources for '.$subject.'. The links below show which pages were read.'
            :'I found public search leads for '.$subject.'. Open the source links to verify current details.');
    if($coverage!=='')$answer.="\n\n".trim($coverage);
    $authority();
    return $result('read_complete','',$answer,array_values($sources));
}

function vp3_artist_research_collect_v1(PDO $pdo,array $user,int $agentId,array $plan,callable $authority,?array $transport=null): array
{
    if(!vp3_artist_research_plan_valid_v1($plan))throw new InvalidArgumentException('Use a public artist name and an optional city.');
    return vp3_public_research_collect_v1($pdo,$user,$agentId,$plan,$authority,$transport);
}

/** Validate the canonical worker result projection without making another provider request. */
function vp3_public_research_result_valid_v1(array $result,string $sourceKind): bool
{
    $status=$result['status']??null;$reason=$result['reason']??null;$sources=$result['sources']??null;
    if(!in_array($status,['read_complete','no_matches','failed'],true)
        ||$reason!==($status==='failed'?'provider_unavailable':'')
        ||!is_string($result['answer']??null)||$result['answer']===''||mb_strlen($result['answer'])>2600
        ||preg_match('~https?://|[<>]~iu',$result['answer'])
        ||!is_string($result['checked_at']??null)||!preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D',$result['checked_at'])
        ||!is_array($sources)||!array_is_list($sources)||count($sources)>6||($status==='read_complete')!==($sources!==[]))return false;
    $seen=[];
    foreach($sources as $source){
        if(!is_array($source)||!is_string($source['url']??null)||!is_string($source['title']??null)||$source['title']===''
            ||vp3_artist_research_text_v1($source['title'],190)!==$source['title']||preg_match('~https?://~iu',$source['title'])
            ||!in_array($source['coverage']??null,['page_excerpt','search_match'],true)
            ||($source['checked_at']??null)!==$result['checked_at']
            ||!is_string($source['evidence_hash']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$source['evidence_hash']))return false;
        try{$url=cloud_mission_web_identity_v1(cloud_mission_web_url_v1($source['url']));}
        catch(InvalidArgumentException $e){return false;}
        $id=$sourceKind.'-web:'.hash('sha256',$url);
        if($source['url']!==$url||($source['source']??null)!==$id||isset($seen[$id])
            ||($source['coverage']==='search_match'&&!hash_equals(hash('sha256',$source['title']),$source['evidence_hash'])))return false;
        $seen[$id]=true;
    }
    return true;
}

function vp3_artist_research_result_valid_v1(array $result): bool
{
    return vp3_public_research_result_valid_v1($result,'artist');
}

/** Preserve verified research evidence in private workflow storage for later delivery. */
function vp3_initiative_research_tool_result_v1(array $value): ?array
{
    $output=$value['output']??null;
    $kind=match($output['type']??null){
        'initiative_artist_research'=>'artist',
        'initiative_topic_research'=>'topic',
        default=>null,
    };
    if($kind===null||!in_array($output['status']??null,['read_complete','no_matches'],true)
        ||!is_string($output['source_ref']??null)||!preg_match('/\A'.$kind.'-interest:[a-f0-9]{64}\z/D',$output['source_ref'])
        ||!is_string($output['source_revision']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$output['source_revision']))return null;
    $receipt=vp3_tool_receipt_normalize_v1($value['tool_receipt']??null);
    $sources=$value['sources']??null;
    $draft=$value['draft']??null;
    if($receipt===null||$receipt['capability']!==$kind.'.research'||$receipt['operation']!=='search'
        ||$receipt['status']!==$output['status']||$receipt['record_id']!==0||!is_array($sources)||$receipt['count']!==count($sources)
        ||!vp3_public_research_result_valid_v1([
            'status'=>$output['status'],'reason'=>$output['reason']??null,'answer'=>$draft,
            'checked_at'=>$output['checked_at']??null,'sources'=>$sources,
        ],$kind))return null;
    return [
        'draft'=>$draft,
        'sources'=>array_map(static fn(array $source): array=>array_intersect_key($source,array_flip([
            'source','title','url','coverage','checked_at','evidence_hash',
        ])),$sources),
        'tool_receipt'=>$receipt,
        'output'=>array_intersect_key($output,array_flip(['type','status','reason','checked_at','source_ref','source_revision'])),
    ];
}

/** Run a direct private Chat research turn or prepare an existing specialist review. */
function vp3_public_research_chat_v1(PDO $pdo,array $user,array $principal,array $plan,int $conversationId,int $userMessageId,?callable $authority=null,?array $transport=null): array
{
    $agentId=(int)($principal['agent_id']??0);$owner=(int)($user['id']??0);
    $general=array_key_exists('topic',$plan);
    $kind=$general?'topic':'artist';
    $capability=$kind.'.research';
    $contextKey=$kind.'_research_v1';
    $check=static function()use($pdo,$user,$principal,$conversationId,$userMessageId,$owner,$agentId,$authority):array{
        if($conversationId<1||$userMessageId<1||!knowledge_retrieval_v162_owner_session($user,$principal)||vp3_participant_scope_v1()!==null){
            throw new RuntimeException('Use your private Agent Chat for public research.',403);
        }
        $live=vp3_capability_live_user_v1($pdo,$user,$principal,['conversation_id'=>$conversationId],$authority);
        if((int)$live['id']!==$owner||!has_permission('account.access',$live)
            ||!vp3_agent_chat_conversation_v380($pdo,$conversationId,$owner,$agentId>0?['id'=>$agentId]:null)){
            throw new RuntimeException('Research access changed.',403);
        }
        return $live;
    };
    $live=$check();
    $operation=($plan['mode']??null)==='delegate'?'prepare':'search';
    if(!vp3_public_research_plan_valid_v1($plan))return ['handled'=>true,
        'answer'=>$general?'Which public topic should I research?':'Which public artist should I research? You can add a city for upcoming shows.',
        'actions'=>[],'sources'=>[],'tool_receipt'=>vp3_tool_receipt_v1($capability,$operation,'clarification_required','invalid_plan')];
    $subject=$plan[$kind];$mode=$plan['mode'];
    $context=$plan+['checked_at'=>gmdate('Y-m-d\TH:i:s\Z')];
    if($mode==='direct'){
        $read=vp3_public_research_collect_v1($pdo,$live,$agentId,$plan,$check,$transport);
        return ['handled'=>true,'answer'=>$read['answer'],'actions'=>[],'sources'=>$read['sources'],
            $contextKey=>array_replace($context,['status'=>$read['status'],'checked_at'=>$read['checked_at']]),
            'tool_receipt'=>vp3_tool_receipt_v1($capability,'search',$read['status'],$read['reason'],0,count($read['sources']))];
    }

    require_once __DIR__.'/cloud-missions-v1.php';
    if($general){
        $objective='Research '.$subject.'. Use reviewed public sources. Link each finding to its source and distinguish confirmed details from open questions. Research and draft only.';
        $requestParts=[$owner,$agentId,$conversationId,$subject,gmdate('Y-m-d')];
    }else{
        $location=$plan['location'];$focus=$plan['focus'];
        $topic=match($focus){'news'=>'current news and releases','shows'=>'upcoming shows','merch'=>'official merchandise',
            'videos'=>'related official and live videos',default=>'current news and releases, upcoming shows, official merchandise, and related live videos'};
        $objective='Research '.$topic.' for '.$subject.($location!==''?' and focus local shows near '.$location:'').'. '
            .'Use reviewed public sources, link each finding to its source and report what was confirmed as of the check. Flag unavailable dates, prices, or stock. Research and draft only.';
        $requestParts=[$owner,$agentId,$conversationId,$subject,$location,$focus,gmdate('Y-m-d')];
    }
    $requestId=$kind.'-research:'.hash('sha256',json_encode($requestParts,JSON_THROW_ON_ERROR));
    $check();
    $mission=cloud_mission_prepare_v1($pdo,$live,['objective'=>$objective,'request_id'=>$requestId,'thread_id'=>$conversationId,'agent_id'=>$agentId]);
    $check();
    $id=(string)($mission['id']??'');
    if(!preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D',$id)||($mission['authority_current']??false)!==true){
        throw new RuntimeException('The specialist Mission could not be verified.',503);
    }
    $pending=($mission['status']??null)==='planned';
    $status=$pending?'clarification_required':'read_complete';
    return ['handled'=>true,'answer'=>$pending?'I prepared a specialist Mission for '.$subject.'. Review the sources and assignments before starting research.':'The existing specialist Mission for '.$subject.' is available below.',
        'actions'=>[['type'=>'open_url','label'=>$pending?'Review specialist Mission':'Open specialist Mission',
            'url'=>url('/chat.php?agent='.($agentId?:'system').'&conversation_id='.$conversationId.'&mission='.$id)]],
        'sources'=>[],$contextKey=>$context+['status'=>$status,'mission_id'=>$id],
        'tool_receipt'=>vp3_tool_receipt_v1($capability,'prepare',$status,$pending?'confirmation_required':'',(int)($mission['job_id']??0))];
}

function vp3_artist_research_chat_v1(PDO $pdo,array $user,array $principal,array $plan,int $conversationId,int $userMessageId,?callable $authority=null,?array $transport=null): array
{
    return vp3_public_research_chat_v1($pdo,$user,$principal,$plan,$conversationId,$userMessageId,$authority,$transport);
}
