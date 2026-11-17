<?php
declare(strict_types=1);

// The caller supplies the canonical Initiative scope schema. These helpers use
// the actual policy, claimant, completion receipts, Chat post and reply service.
require_once dirname(__DIR__,2).'/includes/agent-initiative-followthrough-v1.php';

$followthroughChecks=0;
function followthrough_check(bool $ok,string $label):void
{
    if(!$ok)throw new RuntimeException($label);
    ++$GLOBALS['followthroughChecks'];echo 'PASS '.$label.PHP_EOL;
}
function followthrough_reject(callable $work,int $status,string $label):void
{
    try{$work();}catch(Throwable $error){followthrough_check($error->getCode()===$status,$label.' ('.$error->getMessage().')');return;}
    throw new RuntimeException('Expected denial: '.$label);
}
function followthrough_owner_turn_v1(string $query,int $cid=170,int $owner=1):int
{
    db()->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES(?,?,'user',?)")->execute([$cid,$owner,$query]);
    return (int)db()->lastInsertId();
}
function followthrough_seed_v1(string $title='Private next step',int $agent=17,int $cid=170,?string $marketingRef=null):array
{
    static $serial=0;++$serial;$user=['id'=>1,'is_active'=>1];
    scope_command($user,$agent,$cid,'on');
    followthrough_owner_turn_v1('Begin a private source review '.$serial,$cid);
    db()->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES(?,NULL,'assistant','The source review is ready to assess.')")->execute([$cid]);
    db()->prepare("UPDATE agent_activity_state SET surface='chat',context_key=?,activity_state='paused',last_heartbeat_at=NOW(),details_json=? WHERE user_id=1")
        ->execute(['chat:'.$cid,json_encode(['visible'=>true,'conversation_id'=>$cid,'chat_focused'=>true,'chat_busy'=>false],JSON_THROW_ON_ERROR)]);
    $binding=vp3_initiative_policy_v1($user,$agent);$coverage=[];
    $evidence=vp3_initiative_evidence_v1(db(),$user,new DateTimeImmutable('now',new DateTimeZone('UTC')),false,[],$coverage,null,$agent);
    $refs=array_values(array_filter(array_keys($evidence),static fn(string $ref):bool=>str_starts_with($ref,'calendar:')));
    $ref=$marketingRef??($refs[0]??null);
    if($ref===null||!isset($evidence[$ref]))throw new RuntimeException('Canonical fixture source is required.');
    $goal=['binding'=>$binding,'sources'=>[$ref=>$evidence[$ref]['revision']],
        'canonical_sources'=>[$ref=>$evidence[$ref]['canonical_source_revision']??$evidence[$ref]['revision']],
        'tool'=>$marketingRef===null?'work.prepare_private':'workbench.marketing.review','primary'=>$ref,'focus'=>$title,'report'=>true];
    vp3_initiative_enqueue_v1(db(),$user,'initiative.prepare',$goal,hash('sha256','followthrough-fixture-'.$serial));
    $run=(int)db()->lastInsertId();
    $q=db()->prepare("SELECT id FROM agent_workflow_runs WHERE owner_user_id=1 AND dedupe_key=?");$q->execute([hash('sha256','initiative.prepare|'.hash('sha256','followthrough-fixture-'.$serial))]);$run=(int)$q->fetchColumn();
    $result=vp3_initiative_execute_v1(db(),$user,scope_claim($user,$run),static function(string $prompt,string $protocol)use($title):string{
        if(db()->inTransaction())throw new LogicException('Provider cannot own a transaction.');
        return json_encode(['draft'=>$title.' Would a private next-step analysis help?'],JSON_THROW_ON_ERROR);
    });
    if(empty($result['ok']))throw new RuntimeException('Canonical preparation failed: '.json_encode($result));
    $q=db()->prepare('SELECT result_json FROM agent_workflow_actions WHERE run_id=? AND owner_user_id=1');$q->execute([$run]);
    $stored=json_decode((string)$q->fetchColumn(),true);$message=vp3_initiative_followthrough_id_v1($stored['initiative_message_id']??null);
    if($message<1)throw new RuntimeException('Canonical preparation was not delivered.');
    return vp3_initiative_followthrough_load_v1(db(),$user,scope_principal($user,$agent),$cid,$message);
}
function followthrough_ref_v1(array $root):array
{
    return ['message_id'=>(int)$root['message']['id'],'reply_ref'=>$root['state']['reply_ref'],'revision'=>$root['state']['revision']];
}
function followthrough_decide_v1(array $root,string $intent,string $query='Yes, please',?callable $during=null):array
{
    $cid=$root['conversation_id'];$id=followthrough_owner_turn_v1($query,$cid);
    return vp3_initiative_followthrough_reply_v1(db(),$root['user'],$root['principal'],$cid,$id,followthrough_ref_v1($root),$query,null,
        static function(string $prompt,string $protocol)use($intent,$during):string{
            followthrough_check(!db()->inTransaction()&&$protocol==='vp3.initiative-reply.v1','intent inference uses the governed reply protocol outside a transaction');
            if($during)$during();return json_encode(['intent'=>$intent,'focus'=>'Develop the next private analysis from the verified source.'],JSON_THROW_ON_ERROR);
        });
}
