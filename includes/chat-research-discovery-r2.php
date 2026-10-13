<?php
declare(strict_types=1);
require_once __DIR__.'/agent-tools-v84.php';

/** Search returns suggestions only; canonical R1 owns approval, reads and evidence. */
function chat_research_discovery_domains_r2(PDO $pdo,array $user,string $namespace,string $runtimeId): array
{
    $runtime=vp3_browser_research_runtime_v2230($pdo,$user,$namespace,$runtimeId);
    $domains=array_values(array_unique(vp3_browser_research_allowed_domains_v2230($pdo,$runtime['_multisite'])));
    sort($domains,SORT_STRING);
    $domains=array_slice($domains,0,5);
    if(!$domains)throw new RuntimeException('Approve research websites in the selected Chrome runtime first.',409);
    return $domains;
}

function chat_research_discovery_candidates_r2(array $results,array $domains): array
{
    $out=[];$seen=[];
    foreach(array_slice($results,0,12) as $result){
        if(!is_array($result)||!is_string($result['url']??null))continue;
        try{$source=chat_research_urls_v1('Candidate source '.$result['url'])[0];}catch(InvalidArgumentException $e){continue;}
        if(!in_array($source['domain'],$domains,true)||isset($seen[$source['domain']]))continue;
        $seen[$source['domain']]=true;
        $source['title']=mb_substr(trim(strip_tags((string)($result['title']??''))),0,200);
        $source['origin']='web_search';$source['provider']='DuckDuckGo';$source['discovery_domains']=$domains;
        $source['reason']='Search-ranked candidate within your approved websites; not yet read or verified.';
        $out[]=$source;
        if(count($out)===5)break;
    }
    return $out;
}

function chat_research_discovery_principal_r2(PDO $pdo,array $user,?array $agent): void
{
    $current=vp3_extension_user_for_permission_v2001($pdo,(int)$user['id']);
    if(!$current||!has_permission('chat.access',$current))throw new RuntimeException('Agent Chat permission is unavailable for discovery.',403);
    if((int)($agent['id']??0)>0){
        $current['roles']=user_account_types_for_user_id((int)$current['id'],(string)($current['role']??''));
        vp3_agent_chat_resolve_agent_v380($pdo,$current,(int)$agent['id']);
    }
}

function chat_research_existing_plan_r2(PDO $pdo,array $user,string $namespace,?array $agent,array $input,array $prior): array
{
    if($prior['question']!==trim((string)$input['question'])||$prior['runtime_id']!==$input['runtime_id']||$prior['context_key']!==$input['context_key']
      ||$prior['agent_namespace']!==$namespace||(int)$prior['agent_id']!==(int)($agent['id']??0)||(int)$prior['conversation_id']!==(int)($input['conversation_id']??0))
        throw new RuntimeException('This request belongs to a different research scope.',409);
    $sources=json_decode((string)$prior['urls_json'],true,16,JSON_THROW_ON_ERROR);
    if(($sources[0]['discovery_domains']??null)!==($input['discovery_domains']??null))throw new RuntimeException('This request belongs to a different search scope.',409);
    return chat_research_view_v1($pdo,$user,$prior);
}

function chat_research_plan_r2(PDO $pdo,array $user,string $namespace,?array $agent,array $input,?callable $search=null): array
{
    if(!is_string($input['question']??null))throw new InvalidArgumentException('The research question must be text.');
    $question=trim($input['question']);
    if(preg_match('~https://~i',$question))return chat_research_prepare_v1($pdo,$user,$namespace,$agent,$input);
    if(mb_strlen($question)<5||mb_strlen($question)>2000)throw new InvalidArgumentException('Describe the research question in 5 to 2,000 characters.');
    $rid=chat_research_uuid_v1($input['request_id']??null);
    $runtimeId=chat_research_uuid_v1($input['runtime_id']??null);
    $context=(string)($input['context_key']??'');$conversation=(int)($input['conversation_id']??0);
    if(!preg_match('/^(?:cloud:[0-9]{1,10}|home:[a-zA-Z0-9:._-]{1,180})$/D',$context))throw new InvalidArgumentException('Research conversation context is invalid.');
    if($conversation>0&&!vp3_agent_chat_conversation_v380($pdo,$conversation,(int)$user['id'],$agent))throw new RuntimeException('The Chat conversation is not available for this lead.',403);
    chat_research_discovery_principal_r2($pdo,$user,$agent);
    $domains=chat_research_discovery_domains_r2($pdo,$user,$namespace,$runtimeId);
    if(($input['discovery_confirmed']??null)!==true||($input['discovery_domains']??null)!==$domains)
        throw new RuntimeException('Review the exact external search query and current website scope on the Chat canvas.',409);
    // A lost acknowledgement reuses the frozen source plan without another external search.
    $q=$pdo->prepare('SELECT * FROM chat_browser_research_requests_v1 WHERE owner_user_id=? AND request_id=?');
    $q->execute([(int)$user['id'],$rid]);
    if($prior=$q->fetch(PDO::FETCH_ASSOC))return chat_research_existing_plan_r2($pdo,$user,$namespace,$agent,$input,$prior);
    $query=$question.' ('.implode(' OR ',array_map(static fn(string $d):string=>'site:'.$d,$domains)).')';
    $sources=chat_research_discovery_candidates_r2(($search??'booking_agent_web_search')($query,12),$domains);
    chat_research_discovery_principal_r2($pdo,$user,$agent);
    // Recheck authority after transport; results from a revoked scope never become a plan.
    if($domains!==chat_research_discovery_domains_r2($pdo,$user,$namespace,$runtimeId))throw new RuntimeException('Research websites changed during discovery. Review a fresh search.',409);
    if($conversation>0&&!vp3_agent_chat_conversation_v380($pdo,$conversation,(int)$user['id'],$agent))throw new RuntimeException('The research conversation or lead changed.',403);
    if(!$sources)throw new RuntimeException('No eligible public sources were found. Try a clearer question, approve additional research websites, or provide public HTTPS source URLs.',422);
    try{return chat_research_prepare_v1($pdo,$user,$namespace,$agent,$input,$sources);}
    catch(RuntimeException|PDOException $e){
        // Concurrent discoveries can rank differently; reuse the first frozen plan for this identity.
        $q->execute([(int)$user['id'],$rid]);
        if($prior=$q->fetch(PDO::FETCH_ASSOC))return chat_research_existing_plan_r2($pdo,$user,$namespace,$agent,$input,$prior);
        throw $e;
    }
}
