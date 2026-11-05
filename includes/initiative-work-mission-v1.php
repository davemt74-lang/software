<?php
declare(strict_types=1);

require_once __DIR__.'/cloud-missions-v1.php';
require_once __DIR__.'/agent-chat-boundary-v380.php';

function initiative_work_mission_user_v1(int $owner,array $scope): array
{
    $user=$scope['user']??(function_exists('current_user')?current_user():null);
    if(!is_array($user)||(int)($user['id']??0)!==$owner||$owner<1
        ||!has_permission('chat.access',$user)||!has_permission('account.access',$user)
        ||(function_exists('vp3_participant_scope_v1')&&vp3_participant_scope_v1()!==null)) {
        throw new DomainException('This Mission is unavailable in this Chat.',404);
    }
    return $user;
}

function initiative_work_mission_evidence_v1(PDO $pdo,int $owner,array $scope,string $sourceId): array
{
    $user=initiative_work_mission_user_v1($owner,$scope);
    $agent=$scope['agent_id']??null;
    $conversation=$scope['conversation_id']??null;
    if(!is_int($agent)||$agent<0||!is_int($conversation)||$conversation<1
        ||!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$sourceId)
        ||!table_exists('agent_cloud_missions_v1')) {
        throw new DomainException('This Mission is unavailable in this Chat.',404);
    }
    try {
        if(!vp3_agent_chat_conversation_v380($pdo,$conversation,$owner,$agent===0?null:['id'=>$agent]))
            throw new DomainException('This Mission is unavailable in this Chat.',404);
        $mission=cloud_mission_row_v1($pdo,$user,$sourceId);
        $run=cloud_mission_run_v1($pdo,$user,$mission);
        $config=json_decode($mission['config_json'],true,16,JSON_THROW_ON_ERROR);
        if((int)$mission['agent_id']!==$agent||($agent===0?$run['agent_id']!==null:(int)$run['agent_id']!==$agent)
            ||($config['thread_id']??null)!==$conversation) {
            throw new DomainException('This Mission is unavailable in this Chat.',404);
        }
        cloud_mission_authority_v1($pdo,$user,$mission);
        cloud_mission_web_check_catalog_v1($pdo,$user,$config);
        if($run['status']!=='completed')throw new DomainException('Choose a completed Cloud Mission.',409);

        $actions=agent_workflow_actions_v1400($pdo,$owner,(int)$run['id']);
        if(!$actions||count($actions)>8)throw new DomainException('Completed Mission evidence is unavailable.',409);
        $receipt=$pdo->prepare("SELECT id,result_json FROM agent_workflow_receipts WHERE run_id=? AND action_id=? AND owner_user_id=? AND status='completed' AND executor='cloud' ORDER BY id DESC LIMIT 1");
        $revisionParts=[$sourceId,$owner,$agent,$conversation,(int)$mission['revision'],(int)$run['id'],(string)$run['goal']];
        $sources=[];
        $captured=0;
        foreach($actions as $action){
            $receipt->execute([(int)$run['id'],(int)$action['id'],$owner]);
            $saved=$receipt->fetch(PDO::FETCH_ASSOC);
            if($action['status']!=='completed'||!$saved||!is_string($saved['result_json'])||$saved['result_json']!==$action['result_json'])
                throw new DomainException('Completed Mission evidence is unavailable.',409);
            $result=json_decode($saved['result_json'],true,24,JSON_THROW_ON_ERROR);
            cloud_mission_result_authority_v1($pdo,$user,$mission,$result);
            $revisionParts[]=[(int)$action['id'],(int)$saved['id'],hash('sha256',$saved['result_json'])];
            $collectionId=(string)($result['web_collection']??'');
            if($collectionId==='')continue;

            $collection=cloud_mission_web_row_v1($pdo,$user,$collectionId);
            if(!$collection||$collection['action_key']!==$action['action_key'])
                throw new DomainException('Completed Mission evidence is unavailable.',409);
            $snapshot=cloud_mission_web_snapshot_v1($pdo,$user,$mission,$collectionId);
            $revisionParts[]=[$collectionId,$snapshot['evidence_hash'],$snapshot['collected_at'],hash('sha256',$collection['sources_json'])];
            foreach($snapshot['sources'] as $source){
                $captured++;
                if(($source['status']??null)!=='read'||!is_string($source['excerpt']??null)||trim($source['excerpt'])==='')continue;
                $id=$source['source_id']??'';
                if(!is_string($id)||!preg_match('/^web:[a-f0-9]{20}$/D',$id))continue;
                $url=cloud_mission_web_url_v1((string)($source['url']??''));
                $id.='@'.$collectionId;
                $sources[$id]=['id'=>$id,'title'=>agent_workflow_text_v1400($source['title']??'Public source',160),
                    'url'=>$url,'collected_at'=>$snapshot['collected_at'],
                    'excerpt'=>mb_substr($source['excerpt'],0,1400)];
            }
        }
        if(!$sources)throw new DomainException('This Mission has no saved page excerpts for a decision brief.',422);
        $sources=array_slice($sources,0,4,true);
        return [
            'source_id'=>$sourceId,
            'source_title'=>agent_workflow_text_v1400($run['goal'],160),
            'revision'=>hash('sha256',agent_workflow_json_v1400($revisionParts)),
            'coverage'=>['captured_sources'=>$captured,'read_sources'=>count($sources)],
            'sources'=>$sources,
        ];
    }catch(DomainException $e){
        throw $e;
    }catch(PDOException $e){
        throw $e;
    }catch(RuntimeException|InvalidArgumentException|JsonException $e){
        $code=$e->getCode()===404?404:409;
        throw new DomainException('Mission evidence is unavailable or source access changed.',$code,$e);
    }
}

function initiative_work_mission_preview_v1(PDO $pdo,int $owner,array $scope,array $input): array
{
    $source=$input['source_id']??null;
    if(!is_string($source))throw new DomainException('Choose a completed Cloud Mission.',422);
    $evidence=initiative_work_mission_evidence_v1($pdo,$owner,$scope,$source);
    return ['source_id'=>$evidence['source_id'],'source_title'=>$evidence['source_title'],'revision'=>$evidence['revision'],
        'details'=>['coverage'=>$evidence['coverage']]];
}

function initiative_work_mission_sources_v1(PDO $pdo,int $owner,array $scope): array
{
    initiative_work_mission_user_v1($owner,$scope);
    if(!table_exists('agent_cloud_missions_v1'))return [];
    $q=$pdo->prepare("SELECT m.mission_id FROM agent_cloud_missions_v1 m
        INNER JOIN agent_workflow_runs r ON r.id=m.run_id AND r.owner_user_id=m.owner_user_id
        WHERE m.owner_user_id=? AND m.agent_id=? AND r.status='completed' ORDER BY m.created_at DESC,m.mission_id DESC LIMIT 20");
    $q->execute([$owner,$scope['agent_id']??-1]);
    $sources=[];
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id){
        try{
            $preview=initiative_work_mission_preview_v1($pdo,$owner,$scope,['source_id'=>$id]);
            $sources[]=['source_id'=>$preview['source_id'],'source_title'=>$preview['source_title']];
            if(count($sources)===6)break;
        }catch(DomainException $e){
            if(!in_array($e->getCode(),[404,409,422],true))throw $e;
        }
    }
    return $sources;
}

function initiative_work_mission_brief_v1(array $user,array $evidence,?callable $model): array
{
    $instruction='Prepare a private decision brief for the Mission objective provided as data. '
        .'Use only the saved evidence provided. Return a single JSON object with findings, recommendation, uncertainty and next_step. '
        .'Findings must be a list of one to three objects, each with only source_id and quote. '
        .'Each quote must be an exact, contiguous excerpt of 20 to 220 characters from the source text. '
        .'The other fields must each be one short plain-text sentence. Base the recommendation on those findings, acknowledge limits of partial excerpts, '
        .'and give one next step for the owner to consider. Use no links, markup, tool calls, or claims that a step was performed.'
        ."\nMission objective data: ".agent_workflow_json_v1400($evidence['source_title']);
    $context=[];
    foreach($evidence['sources'] as $source){
        $context[]=['source'=>$source['id'],'title'=>$source['title'],'text'=>'Captured '.$source['collected_at']
            .' from '.$source['url']."\nSaved excerpt:\n".$source['excerpt']];
    }
    $result=$model?$model($instruction,$context,$user)
        :ai_generate_chat_response($instruction,[],$context,$user,'main_ai',['surface'=>'cloud_mission']);
    if(empty($result['ok'])||!is_string($result['answer']??null)||strlen($result['answer'])>6500)
        throw new DomainException('The decision brief could not be prepared. Try again.',503);
    $answer=trim($result['answer']);
    if(preg_match('/^\x60{3}(?:json)?\s*(.*?)\s*\x60{3}$/isD',$answer,$match))$answer=$match[1];
    $brief=json_decode($answer,true,12);
    $findings=$brief['findings']??null;
    if(!is_array($brief)||count($brief)!==4||!is_array($findings)||!array_is_list($findings)||count($findings)<1||count($findings)>3)
        throw new DomainException('The decision brief could not be verified against saved evidence.',503);
    $verified=[];
    foreach($findings as $finding){
        $id=$finding['source_id']??null;
        $quote=$finding['quote']??null;
        if(!is_array($finding)||count($finding)!==2||!is_string($id)||!isset($evidence['sources'][$id])||!is_string($quote)
            ||mb_strlen($quote)<20||mb_strlen($quote)>220||!str_contains($evidence['sources'][$id]['excerpt'],$quote))
            throw new DomainException('The decision brief could not be verified against saved evidence.',503);
        $verified[]=['text'=>$quote,'source_id'=>$id,'quote'=>$quote];
    }
    $suggestions=[];
    foreach(['recommendation','uncertainty','next_step'] as $field){
        $value=$brief[$field]??null;
        if(!is_string($value)||trim($value)===''||mb_strlen($value)>400||preg_match('/[<>\x00-\x1f\x7f]/',$value))
            throw new DomainException('The decision brief could not be verified against saved evidence.',503);
        $suggestions[$field]=trim($value);
    }
    $sources=array_map(static function(array $source): array {unset($source['excerpt']);return $source;},array_values($evidence['sources']));
    return ['type'=>'mission_decision_brief','source_id'=>$evidence['source_id'],'source_title'=>$evidence['source_title'],
        'source_revision'=>$evidence['revision'],'title'=>'Mission decision brief','findings'=>$verified,
        'sources'=>$sources,'coverage'=>$evidence['coverage']]+$suggestions;
}

function initiative_work_mission_read_v1(PDO $pdo,int $owner,array $scope,array $output): array
{
    $preview=initiative_work_mission_preview_v1($pdo,$owner,$scope,['source_id'=>$output['source_id']??null]);
    if(($output['type']??null)!=='mission_decision_brief'||($output['source_revision']??null)!==$preview['revision'])
        throw new DomainException('Mission evidence changed. Review the Mission before creating another brief.',409);
    return $output;
}

function initiative_work_mission_receipt_v1(PDO $pdo,int $owner,array $scope,string $request): array
{
    initiative_work_mission_user_v1($owner,$scope);
    if(!preg_match('/^[a-f0-9]{64}$/D',$request)||!table_exists('agent_initiative_mission_briefs_v1'))
        throw new DomainException('Mission brief not found.',404);
    $q=$pdo->prepare('SELECT output_json FROM agent_initiative_mission_briefs_v1 WHERE owner_user_id=? AND request_id=?');
    $q->execute([$owner,$request]);
    $output=$q->fetchColumn();
    if(!is_string($output))throw new DomainException('Mission brief not found.',404);
    return initiative_work_mission_read_v1($pdo,$owner,$scope,json_decode($output,true,24,JSON_THROW_ON_ERROR));
}

function initiative_work_mission_create_v1(PDO $pdo,int $owner,array $scope,array $input,array $preview,?callable $model=null): array
{
    $request=$input['request_id']??null;
    $source=$input['source_id']??null;
    if(!is_string($request)||!preg_match('/^[a-f0-9]{64}$/D',$request)||!is_string($source)||$source!==($preview['source_id']??null))
        throw new DomainException('Review the exact Mission brief request.',422);
    $evidence=initiative_work_mission_evidence_v1($pdo,$owner,$scope,$source);
    if(($preview['revision']??null)!==$evidence['revision'])
        throw new DomainException('Mission evidence changed. Review it before creating the brief.',409);

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_initiative_mission_briefs_v1 (
        owner_user_id BIGINT NOT NULL,request_id CHAR(64) NOT NULL,mission_id CHAR(36) NOT NULL,
        source_revision CHAR(64) NOT NULL,lease_token CHAR(32) NOT NULL,lease_until BIGINT NOT NULL,
        output_json MEDIUMTEXT NULL,PRIMARY KEY(owner_user_id,request_id))");
    $lease=bin2hex(random_bytes(16));
    $until=time()+180;
    $pdo->prepare('INSERT IGNORE INTO agent_initiative_mission_briefs_v1
        (owner_user_id,request_id,mission_id,source_revision,lease_token,lease_until) VALUES(?,?,?,?,?,?)')
        ->execute([$owner,$request,$source,$evidence['revision'],$lease,$until]);
    $find=$pdo->prepare('SELECT * FROM agent_initiative_mission_briefs_v1 WHERE owner_user_id=? AND request_id=?');
    $find->execute([$owner,$request]);
    $receipt=$find->fetch(PDO::FETCH_ASSOC);
    if(!$receipt||$receipt['mission_id']!==$source||$receipt['source_revision']!==$evidence['revision'])
        throw new DomainException('This request belongs to a different Mission brief.',409);
    if($receipt['output_json']!==null){
        return initiative_work_mission_read_v1($pdo,$owner,$scope,json_decode($receipt['output_json'],true,24,JSON_THROW_ON_ERROR));
    }
    if($receipt['lease_token']!==$lease){
        $claim=$pdo->prepare('UPDATE agent_initiative_mission_briefs_v1 SET lease_token=?,lease_until=?
            WHERE owner_user_id=? AND request_id=? AND output_json IS NULL AND lease_until<?');
        $claim->execute([$lease,$until,$owner,$request,time()]);
        if($claim->rowCount()!==1)throw new DomainException('This Mission brief is already being prepared. Try again shortly.',409);
    }

    try{
        $output=initiative_work_mission_brief_v1(initiative_work_mission_user_v1($owner,$scope),$evidence,$model)+['request_id'=>$request];
        initiative_work_mission_read_v1($pdo,$owner,$scope,$output);
        $save=$pdo->prepare('UPDATE agent_initiative_mission_briefs_v1 SET output_json=?,lease_until=0
            WHERE owner_user_id=? AND request_id=? AND lease_token=? AND output_json IS NULL');
        $save->execute([agent_workflow_json_v1400($output),$owner,$request,$lease]);
        if($save->rowCount()!==1)throw new DomainException('This Mission brief request expired. Try again.',409);
        return $output;
    }catch(Throwable $e){
        $pdo->prepare('DELETE FROM agent_initiative_mission_briefs_v1 WHERE owner_user_id=? AND request_id=?
            AND lease_token=? AND output_json IS NULL')->execute([$owner,$request,$lease]);
        throw $e;
    }
}
