<?php
declare(strict_types=1);

require_once __DIR__.'/agent-connection-email-chat-v1.php';
require_once __DIR__.'/agent-connection-gmail-inbox-initial-v1.php';
require_once __DIR__.'/agent-email-triage-v1.php';
require_once __DIR__.'/agent-brain-soul-v1.php';

function vp3_coordination_gmail_draft_source_v1(PDO $pdo,array $user,array $goal,array $evidence,bool $lock=false): array
{
    $action=$evidence[$goal['primary']]['source_action']??null;
    $ref=$action['item_ref']??null;
    $scope=agent_connection_gmail_inbox_bound_scope_v1($pdo,$user,$lock);
    if(($action['type']??null)!=='gmail_triage'||!is_string($ref)
        ||($scope['state']??null)!=='ready'||!$scope['provider_ready']
        ||$scope['generation']===null||$scope['generation']!==($action['generation']??null)
        ||!agent_email_running_report_enabled_v1($scope['policy'])){
        throw new Vp3InitiativeSourcesChangedV1();
    }
    $source=agent_email_triage_source_v1($pdo,$scope,$ref);
    $bound=agent_email_matters_policy_scope_v1($scope['policy']);
    if($source===null||$bound===null)throw new Vp3InitiativeSourcesChangedV1();
    $triage=agent_email_triage_find_v1($pdo,$bound,$ref,$lock);
    $thread=agent_connection_gmail_id_v1(ai_decrypt_secret((string)($triage['thread_id_enc']??'')));
    if($thread===''||!hash_equals($goal['primary'],'gmail:'.agent_email_matters_source_key_v1($scope['policy'],'thread',$thread))
        ||isset(agent_connection_gmail_inbox_answered_messages_v1($pdo,$scope,[$source['message_id']])[$source['message_id']])){
        throw new Vp3InitiativeSourcesChangedV1();
    }
    return $source+['thread_id'=>$thread];
}

/** Read the exact message and write only the body. Recipient and thread come from Gmail. */
function vp3_coordination_gmail_draft_prepare_v1(PDO $pdo,array $user,array $goal,array $evidence,array $continuation,
    callable $infer,?callable $http=null): array
{
    if($pdo->inTransaction()||($goal['tool']??null)!=='coordination.review'||($goal['gmail_reply_draft']??null)!==true
        ||($continuation['goal']['primary']??null)!==($goal['primary']??null)
        ||($continuation['goal']['coordination_route']['action']??null)!=='ask'
        ||($evidence[$goal['primary']]['specialist']??null)!=='gmail'){
        throw new RuntimeException('Gmail reply preparation changed.',409);
    }
    if(!table_exists('agent_connection_email_drafts_v1'))throw new RuntimeException('Gmail draft storage is unavailable.',503);
    $source=vp3_coordination_gmail_draft_source_v1($pdo,$user,$goal,$evidence);
    $owner=(int)$user['id'];
    $access=agent_connections_access_v1($owner,$source['connection_id'],'read',$http,$source['authorization_key']);
    $original=agent_connection_gmail_reply_source_v1($access['access_token'],$source['message_id'],$access['account_email'],true,$http);
    if($original['reply']['thread_id']!==$source['thread_id'])throw new Vp3InitiativeSourcesChangedV1();
    $id=substr(hash('sha256', 'vp3-gmail-reply-v1:'.json_encode(['review',$owner,$goal['followthrough']['reply_message_id']],JSON_THROW_ON_ERROR)),0,32);
    $reply=$continuation['continuation_owner_reply'];
    $question=$goal['gmail_reply_question']??null;
    if(!is_string($question)||trim($question)===''||mb_strlen($question)>500)throw new RuntimeException('Gmail question changed.',409);
    $soul=agent_brain_soul_profile_v1($user,'email');
    $prompt=['response_kind'=>'coordination_gmail_reply_draft','owner_question'=>$question,
        'owner_reply'=>mb_substr($reply,0,2500),'email_soul'=>$soul['content'],
        'original_message'=>['subject'=>$original['subject'],'excerpt'=>$original['body']]];
    $answer=$infer(json_encode($prompt,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),'vp3.initiative-draft.v1');
    $value=vp3_tool_json_object_v1($answer,['draft'],30000);
    $body=agent_connection_email_chat_text_v1($value['draft']??null,6000,true);
    $item=$evidence[$goal['primary']];
    $reason=is_string($item['summary']??null)&&trim($item['summary'])!==''
        ? $item['summary'] : 'Prepared after your answer to: '.$question;
    $metadata=agent_email_review_queue_source_metadata_v1([
        'reason'=>agent_email_composer_text_v1(mb_substr($reason,0,1200),1200,true),
        'priority'=>$item['priority']??'normal','deadline'=>$item['due_at']??null,
    ]);
    $created=time();
    $payload=['subject'=>$original['subject'],'body'=>$body,'reply'=>$original['reply'],
        'soul_version'=>$soul['version'],'soul_revision'=>$soul['revision'],
        'composer'=>['version'=>2,'saved_at'=>$created,'created_at'=>$created,'origin'=>'organic_reply','source'=>$metadata]];
    $work=['type'=>'initiative_coordination','situation_id'=>$goal['coordination_id'],'state'=>'resolved',
        'title'=>'Gmail reply ready for review','summary'=>'I drafted a reply in the original Gmail thread.',
        'next_step'=>'Review the reply before sending.','question'=>'','evidence_revisions'=>$goal['sources'],'gmail_reply_draft_id'=>$id];
    return ['result'=>['draft'=>vp3_coordination_draft_v1($work),'output'=>$work],
        'draft'=>['id'=>$id,'source'=>$source,'from'=>$access['account_email'],'to'=>$original['to'],'payload'=>$payload]];
}

/** Store the canonical owner-reviewed draft in the same transaction as the workflow receipt. */
function vp3_coordination_gmail_draft_store_v1(PDO $pdo,array $user,array $goal,array $evidence,array $draft): void
{
    $source=vp3_coordination_gmail_draft_source_v1($pdo,$user,$goal,$evidence,true);
    $owner=(int)$user['id'];$binding=$goal['binding'];$agent=vp3_initiative_binding_agent_v1($binding);
    $connection=agent_connections_require_grant_v1($pdo,$owner,$source['connection_id'],'read',$source['authorization_key']);
    if($source!==$draft['source']||$connection['account_email']!==$draft['from']){
        throw new Vp3InitiativeSourcesChangedV1();
    }
    vp3_gmail_private_reply_store_v1($pdo,$user,$binding,$goal['followthrough']['reply_message_id'],$draft,$source);
}

/** Shared canonical draft storage; callers verify the exact source before entering this boundary. */
function vp3_gmail_private_reply_store_v1(PDO $pdo,array $user,array $binding,int $ownerMessageId,array $draft,array $source): void
{
    if(!$pdo->inTransaction())throw new LogicException('Store a private reply with its workflow receipt.');
    $owner=(int)$user['id'];$agent=vp3_initiative_binding_agent_v1($binding);
    $payload=$draft['payload'];
    agent_email_composer_validate_v1(['expires_at'=>0],$payload);
    if(($payload['composer']['version']??null)!==2)throw new Vp3InitiativeSourcesChangedV1();
    $insert=$pdo->prepare('INSERT INTO agent_connection_email_drafts_v1
        (id,owner_user_id,agent_id,conversation_id,user_message_id,connection_id,authorization_key,account_email,recipient,message_enc,status,expires_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,\'prepared\',0)');
    $insert->execute([$draft['id'],$owner,$agent,$binding['conversation_id'],$ownerMessageId,
        $source['connection_id'],$source['authorization_key'],$draft['from'],$draft['to'],
        ai_encrypt_secret(json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))]);
    require_once __DIR__.'/agent-email-preferences-schema-v1.php';
    if(agent_email_preferences_ready_v1($pdo)){
        require_once __DIR__.'/agent-email-preferences-v1.php';
        agent_email_preferences_capture_generated_v1($pdo,
            agent_connection_email_chat_draft_v1($pdo,$owner,$agent,(int)$binding['conversation_id'],$draft['id']),
            'organic:'.$draft['id']);
    }
}

function vp3_coordination_gmail_draft_action_v1(PDO $pdo,array $user,int $agent,int $conversation,string $id): array
{
    try{
        $draft=agent_connection_email_chat_draft_v1($pdo,(int)$user['id'],$agent,$conversation,$id);
        $payload=agent_connection_email_chat_payload_v1($draft);
    }catch(DomainException $error){
        throw new RuntimeException('Gmail reply draft changed.',409,$error);
    }
    if(!isset($payload['reply'])||isset($payload['auto_revision']))throw new RuntimeException('Gmail reply draft changed.',409);
    return ['type'=>'email_review','id'=>$id,'conversation_id'=>$conversation,'label'=>'Review reply','is_reply'=>true,'auto'=>false,
        'policy'=>['version'=>'v4.00','domain'=>'connections_email','risk'=>'high','requires_approval'=>true,'authorized'=>true]];
}
