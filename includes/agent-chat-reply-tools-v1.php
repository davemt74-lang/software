<?php
declare(strict_types=1);

require_once __DIR__.'/agent-chat-reply-tool-plan-v1.php';
require_once __DIR__.'/agent-connection-youtube-chat-v1.php';
require_once __DIR__.'/agent-initiative-engagement-feedback-v1.php';

/** A separate tool request must be explicit in the owner's own message. */
function vp3_chat_reply_tool_plan_v1(string $text,?array $previousAssistant=null): ?array
{
    if(($topic=agent_connection_youtube_chat_plan_v1($text))!==null){
        return ['tool'=>'youtube','topic'=>$topic,'suggestion'=>vp3_youtube_suggestion_plan_v1($text)['kind']??null];
    }
    $image=vp3_agent_chat_reply_image_plan_v1($text,$previousAssistant);
    if($image!==null||vp3_agent_chat_reply_image_reference_v1($text))return ['tool'=>'image','plan'=>$image];
    return null;
}

function vp3_chat_reply_tool_resolve_v1(PDO $pdo,int $cid,int $mid,string $text,?array $proposal=null): ?array
{
    $plan=vp3_chat_reply_tool_plan_v1($text);
    if(($plan['tool']??null)!=='image'||$plan['plan']!==null)return $plan;
    if(($proposal['goal']['tool']??null)==='image.prepare'){
        require_once __DIR__.'/agent-initiative-image-v1.php';
        return vp3_initiative_image_reply_plan_v1($proposal,$text);
    }
    return vp3_chat_reply_tool_plan_v1($text,vp3_agent_chat_previous_image_turn_v1($pdo,$cid,$mid));
}

/** Save the exact tool plan and associate the reply without answering the specialist's question. */
function vp3_chat_reply_tool_associate_v1(PDO $pdo,array $loaded,array $principal,array $anchor,array $plan,?callable $authority): array
{
    $user=$loaded['user'];$cid=$loaded['conversation_id'];$mid=$anchor['user_message_id'];$ref=$anchor['initiative_reply'];
    return vp3_initiative_followthrough_transaction_v1($pdo,static function()use($pdo,$user,$principal,$cid,$mid,$ref,$anchor,$plan,$authority):array{
        vp3_initiative_owner_lock_v1($pdo,$user);
        if($authority)$authority();
        $fresh=vp3_initiative_followthrough_selected_v1($pdo,$user,$principal,$cid,$ref,null,true);
        $row=vp3_initiative_followthrough_latest_turn_v1($pdo,(int)$user['id'],$cid,$mid);
        $context=json_decode((string)($row['context_json']??''),true);
        if(($context['initiative_reply_anchor_v1']??null)!==$anchor
            ||!hash_equals($anchor['query_hash'],hash('sha256',(string)($row['message']??'')))
            ||isset($context['initiative_reply_tool_v1'])){
            throw new RuntimeException('The saved tool reply changed.',409);
        }
        if(($plan['image_concept']??false)===true){
            require_once __DIR__.'/agent-initiative-image-v1.php';
            if(vp3_initiative_image_reply_plan_v1($fresh,(string)$row['message'])!==$plan){
                throw new RuntimeException('The confirmed image concept changed.',409);
            }
        }
        $context['initiative_reply_tool_v1']=$plan;
        $pdo->prepare('UPDATE chat_messages SET context_json=? WHERE id=? AND conversation_id=?')
            ->execute([json_encode($context,JSON_THROW_ON_ERROR),$mid,$cid]);
        $state=$fresh['state'];
        $state['specialist_decision_v1']=vp3_initiative_decision_reply_v1($state)+['linked_reply_message_id'=>$mid];
        ++$state['revision'];
        $state['last_reply_message_id']=$mid;
        $state['last_reply_hash']=$anchor['query_hash'];
        $state['last_reply_explicit']=true;
        $state['updated_at']=gmdate(DATE_ATOM);
        $outcome=vp3_initiative_followthrough_store_v1($pdo,$fresh,$state);
        return ['handled'=>true,'initiative_followthrough'=>$outcome,'sources'=>[]];
    });
}

/** Return the exact saved owner's tool plan, optionally reserving its one YouTube call. */
function vp3_chat_reply_tool_read_v1(PDO $pdo,int $owner,array $outcome,bool $reserveYoutube=false): ?array
{
    $cid=$outcome['conversation_id'];$mid=$outcome['reply_message_id'];
    $lock=$reserveYoutube&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite'?' FOR UPDATE':'';
    $q=$pdo->prepare("SELECT message,context_json FROM chat_messages WHERE id=? AND conversation_id=? AND user_id=? AND role='user'".$lock);
    $q->execute([$mid,$cid,$owner]);$row=$q->fetch(PDO::FETCH_ASSOC);
    $context=json_decode((string)($row['context_json']??''),true);
    $plan=$context['initiative_reply_tool_v1']??null;
    if($plan===null)return null;
    $anchor=$context['initiative_reply_anchor_v1']??[];
    $ref=['message_id'=>$outcome['message_id'],'reply_ref'=>$outcome['reply_ref'],'revision'=>$outcome['revision']-1];
    if(($anchor['initiative_reply']??null)!==$ref||($anchor['user_message_id']??null)!==$mid
        ||($anchor['owner_user_id']??null)!==$owner||($anchor['agent_id']??null)!==$outcome['agent_id']
        ||($anchor['conversation_id']??null)!==$cid||($anchor['explicit']??null)!==true
        ||($anchor['query_hash']??null)!==hash('sha256',(string)$row['message'])
        ||!in_array($plan['tool']??null,['youtube','image'],true)){
        throw new RuntimeException('The saved tool reply could not be verified.',409);
    }
    if($reserveYoutube&&$plan['tool']==='youtube'&&empty($plan['started'])){
        $context['initiative_reply_tool_v1']['started']=true;
        $pdo->prepare('UPDATE chat_messages SET context_json=? WHERE id=? AND conversation_id=?')
            ->execute([json_encode($context,JSON_THROW_ON_ERROR),$mid,$cid]);
    }
    return $plan;
}

function vp3_chat_reply_tool_execute_v1(PDO $pdo,array $live,array $principal,array $outcome,?callable $authority): ?array
{
    $owner=(int)$live['id'];$cid=$outcome['conversation_id'];
    $plan=vp3_chat_reply_tool_read_v1($pdo,$owner,$outcome);
    if($plan===null)return null;
    if($plan['tool']==='youtube'){
        $plan=vp3_initiative_followthrough_transaction_v1($pdo,static function()use($pdo,$live,$principal,$outcome,$authority,$owner,$cid):array{
            vp3_initiative_owner_lock_v1($pdo,$live);
            vp3_initiative_followthrough_current_v1($pdo,$live,$principal,$cid,$outcome,$authority);
            return vp3_chat_reply_tool_read_v1($pdo,$owner,$outcome,true)
                ??throw new RuntimeException('The saved YouTube reply changed.',409);
        });
        if(empty($plan['started'])){
            $result=agent_connection_youtube_chat_v1($pdo,$live,$principal,$plan['topic'],$cid,$authority,null,$plan['suggestion']);
        }else{
            $result=['answer'=>'I couldn’t verify the earlier YouTube search. Send a new search to try again.',
                'tool_receipt'=>vp3_tool_receipt_v1('youtube.search','search','failed','outcome_unverified')];
        }
    }elseif($plan['plan']===null){
        $result=['answer'=>'What would you like me to put in the image? Describe the scene and I can queue it here.',
            'tool_receipt'=>vp3_tool_receipt_v1('images.generate','queue','clarification_required','invalid_plan')];
    }else{
        if(($plan['image_concept']??false)===true){
            require_once __DIR__.'/agent-initiative-image-v1.php';
            $proposal=vp3_initiative_followthrough_load_v1($pdo,$live,$principal,$cid,$outcome['message_id']);
            if(vp3_initiative_followthrough_outcome_v1($proposal)!==$outcome||vp3_initiative_image_proposal_plan_v1($proposal)!==$plan){
                throw new RuntimeException('The confirmed image concept changed.',409);
            }
        }
        $result=vp3_capability_image_generate_v1($pdo,$live,$principal,$plan['plan'],[
            'conversation_id'=>$cid,'chat_user_message_id'=>$outcome['reply_message_id'],
        ],$authority);
    }
    return ['linked_reply_tool_v1'=>$plan['tool'],'execution'=>chat_execution_v019_base('vp3_tool',
        $plan['tool']==='youtube'?'YouTube search':'Image Generator')]+$result;
}

/** Rebuild linked cards from the exact persisted reply and current tool authority. */
function vp3_chat_reply_tool_project_v1(PDO $pdo,array $user,array $principal,array $response,array $context,?callable $authority): array
{
    $tool=$context['linked_reply_tool_v1']??null;
    if(!in_array($tool,['youtube','image'],true))return $response;
    $response['linked_reply_tool_v1']=$tool;
    $response['tool_receipt']=vp3_tool_receipt_normalize_v1($context['tool_receipt']??null);
    $response['actions']=is_array($context['actions']??null)?$context['actions']:[];
    unset($response['youtube_results']);
    $cid=$response['conversation_id'];$mid=$response['assistant_message_id'];
    if($tool==='youtube'&&($context['youtube_search_results_v1']??null)===true){
        agent_connection_youtube_chat_authority_v1($pdo,$user,$principal,$cid,$authority);
        $data=agent_connection_youtube_results_read_v1($pdo,(int)$user['id'],(int)$principal['agent_id'],$cid,[$mid])[$mid]??null;
        if($data!==null)$response['youtube_results']=$data;
        else{
            $response['answer']='The saved YouTube results are unavailable. You can search again from Chat.';
            $response['tool_receipt']=vp3_tool_receipt_v1('youtube.search','search','failed','scope_restricted');
        }
    }elseif($tool==='image'){
        vp3_image_scope_v1($pdo,$user,$principal,['conversation_id'=>$cid]);
        $response['canvas_media']=is_array($context['canvas_media']??null)?$context['canvas_media']:null;
    }
    return $response;
}
