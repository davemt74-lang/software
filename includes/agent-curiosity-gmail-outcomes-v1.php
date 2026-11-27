<?php
declare(strict_types=1);

require_once __DIR__.'/agent-curiosity-gmail-draft-v1.php';

/** Local receipts only. A watch is never enabled by discovering an outcome. */
function vp3_curiosity_gmail_response_v1(PDO $pdo,array $user,array $binding,string $ref): ?array
{
    if(!preg_match('/\Acuriosity-finding:([a-f0-9]{32})\z/D',$ref,$m)
        ||!table_exists('agent_connection_email_followups_v1'))return null;
    $agent=vp3_initiative_binding_agent_v1($binding);$cid=(int)$binding['conversation_id'];
    $ctx=vp3_curiosity_gmail_request_v1($pdo,$user,$agent,$cid,$m[1]);
    if($ctx===null||!agent_connection_email_followup_ready_v1($pdo)||!agent_connection_email_sender_watch_ready_v1($pdo)
        ||(vp3_curiosity_gmail_progress_v1($pdo,$user,$binding,[$ref])[$ref]['draft_state']??null)!=='sent')return null;
    $draft=agent_connection_email_chat_draft_v1($pdo,(int)$user['id'],$agent,$cid,$ctx['draft_id']);
    if(!(int)$draft['watch_replies']||!(int)$draft['watch_registered'])return null;
    $payload=agent_connection_email_chat_payload_v1($draft);$s=$ctx['origin']['source'];
    if(($payload['curiosity_gmail_reply_v1']['request_key']??null)!==$ctx['request_key'])return null;
    $connection=agent_connections_require_grant_v1($pdo,(int)$user['id'],$s['connection'],'followups',$s['grant']);
    $q=$pdo->prepare("SELECT * FROM agent_connection_email_followups_v1 WHERE owner_user_id=? AND agent_id=? AND conversation_id=?
        AND draft_id=? AND connection_id=? AND authorization_key=? AND account_email=? AND recipient_email=?
        AND sent_message_id=? AND thread_id=? AND origin_user_message_id=? AND sender_after_ms IS NULL
        AND status='delivered' AND expires_at>? ORDER BY operation_key LIMIT 2");
    $q->execute([(int)$user['id'],$agent,$cid,$draft['id'],$s['connection'],$s['grant'],$s['account'],$draft['recipient'],
        $draft['sent_message_id'],$s['thread'],$ctx['confirmed']['message_id'],agent_commerce_time_pa2()]);
    $rows=$q->fetchAll(PDO::FETCH_ASSOC);if(count($rows)!==1)return null;$watch=$rows[0];
    $post=(int)$watch['reply_chat_message_id'];
    $notice=agent_connection_email_followup_delivered_v1($pdo,$connection,$agent,$cid,[$post])[$post]??null;
    if(($notice['message_id']??null)!==$watch['reply_message_id']||($notice['kind']??null)!=='thread'
        ||!agent_connection_email_followup_origin_v1($pdo,$watch))return null;
    // Composer timestamps and saved watch settings do not make the same reply new.
    $sentContent=['subject'=>$payload['subject'],'body'=>$payload['body'],
        'reply'=>agent_connection_gmail_reply_binding_v1($payload['reply']),'attachments'=>$payload['attachments']??[]];
    $revision=hash('sha256',json_encode([$ctx['request_key'],$draft['id'],$sentContent,$draft['sent_message_id'],
        $watch['operation_key'],$watch['reply_message_id']],JSON_THROW_ON_ERROR));
    return $ctx+['draft'=>$draft,'watch'=>$watch,'connection'=>$connection,
        'response'=>['source_ref'=>$ref,'revision'=>$revision,'original_question'=>$ctx['thread']['analysis_origin']['question'],
            'owner_request'=>$ctx['confirmed']['summary'],'interpretation'=>'A watched reply is available; the owner objective remains unverified.']];
}

function vp3_curiosity_gmail_responses_v1(PDO $pdo,array $user,array $binding,array $evidence): array
{
    $out=[];
    foreach($evidence as $ref=>$source){
        if(($source['kind']??null)!=='curiosity_finding')continue;
        try{$ctx=vp3_curiosity_gmail_response_v1($pdo,$user,$binding,$ref);if($ctx!==null)$out[$ref]=$ctx['response'];}
        catch(PDOException $error){throw $error;}
        catch(RuntimeException|DomainException|JsonException $error){}
        if(count($out)===2)break;
    }
    return $out;
}

/** Read the exact sent wording and watched response, with the complete native thread proof. */
function vp3_curiosity_gmail_response_read_v1(PDO $pdo,array $user,array $binding,array $expected,callable $authorize,?callable $http=null): array
{
    if($pdo->inTransaction())throw new LogicException('Read watched email outside workflow locks.');
    $authorize();$ctx=vp3_curiosity_gmail_response_v1($pdo,$user,$binding,$expected['source_ref']);
    if($ctx===null||$ctx['response']!==$expected)throw new Vp3InitiativeSourcesChangedV1();
    $w=$ctx['watch'];$owner=(int)$user['id'];
    $access=agent_connections_access_v1($owner,(int)$w['connection_id'],'followups',$http,$w['authorization_key']);
    $authorize();$sent=agent_connection_gmail_get_v1($access['access_token'],$w['sent_message_id'],true,$http,true);
    $authorize();$reply=agent_connection_gmail_get_v1($access['access_token'],$w['reply_message_id'],true,$http,true);
    $authorize();$thread=agent_connection_gmail_thread_v1($access['access_token'],$w['thread_id'],$http,true);
    require_once __DIR__.'/agent-email-followthrough-v1.php';
    $proof=agent_email_followthrough_evidence_v1($w,$thread);
    $s=agent_connection_gmail_message_v1($sent);$r=agent_connection_gmail_message_v1($reply);
    if(($proof['state']??null)!=='verified'||($proof['result']??null)!=='reply'
        ||($proof['reply']??null)!==$r||($proof['anchor']??null)!==$s)throw new Vp3InitiativeSourcesChangedV1();
    // The complete strict thread already proves chronological order and RFC linkage.
    $head='';
    foreach($thread as $message)if(!array_intersect(['DRAFT','SPAM','TRASH'],$message['labels']))$head=$message['message_id'];
    if($head!==$w['reply_message_id'])throw new Vp3InitiativeSourcesChangedV1();
    $authorize();$current=vp3_curiosity_gmail_response_v1($pdo,$user,$binding,$expected['source_ref']);
    if($current===null||$current['response']!==$expected)throw new Vp3InitiativeSourcesChangedV1();
    $sentText=agent_connection_gmail_read_message_v1($sent);$replyText=agent_connection_gmail_read_message_v1($reply);
    $digest=hash('sha256',json_encode([$sent,$reply,$thread],JSON_THROW_ON_ERROR));
    return ['content_digest'=>$digest,'source_ref'=>$expected['source_ref'],'original_question'=>$expected['original_question'],
        'owner_request'=>$ctx['owner_reply'],'sent_wording'=>mb_substr($sentText['body'],0,6000),
        'sender_response'=>mb_substr($replyText['body'],0,6000),'sent_wording_truncated'=>mb_strlen($sentText['body'])>6000,
        'sender_response_truncated'=>mb_strlen($replyText['body'])>6000,
        'sent_attachments_unreviewed'=>agent_gmail_document_parts_v1($sent)!==[],
        'reply_attachments_unreviewed'=>agent_gmail_document_parts_v1($reply)!==[],
        'observed_at'=>gmdate(DATE_ATOM,intdiv($r['received_at_ms'],1000))];
}

/** Model classifications are tentative interpretations, never completed business objectives. */
function vp3_curiosity_gmail_outcomes_shape_v1(mixed $value,bool $saved=false): ?array
{
    if(!is_array($value)||!array_is_list($value)||count($value)>2)return null;
    $seen=[];$normalized=[];
    foreach($value as $row){
        $keys=['source_ref','state','summary','question'];if($saved)$keys=[...$keys,'source_revision','content_digest','observed_at'];
        if(!is_array($row)||count($row)!==count($keys)||array_diff(array_keys($row),$keys)||!is_string($row['source_ref'])
            ||!preg_match('/\Acuriosity-finding:[a-f0-9]{32}\z/D',$row['source_ref'])||isset($seen[$row['source_ref']])
            ||!in_array($row['state'],['answered','partial','conflict','unclear'],true))return null;
        foreach(['summary'=>900,'question'=>500] as $key=>$limit){
            if(!is_string($row[$key])||mb_strlen($row[$key])>$limit||($key==='summary'&&trim($row[$key])==='')
                ||preg_match('/https?:\/\/|\bwww\.|[<>\x00-\x1f\x7f]|[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/iu',$row[$key]))return null;
        }
        if($saved&&(!is_string($row['source_revision'])||!preg_match('/\A[a-f0-9]{64}\z/D',$row['source_revision'])
            ||!is_string($row['content_digest'])||!preg_match('/\A[a-f0-9]{64}\z/D',$row['content_digest'])
            ||!is_string($row['observed_at'])||!preg_match('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00\z/D',$row['observed_at'])))return null;
        $seen[$row['source_ref']]=true;
        $normalized[]=array_replace(array_fill_keys($keys,null),$row);
    }
    return $normalized;
}

/** Per-source projection from matching canonical receipts; no recursive context/analysis reads. */
function vp3_curiosity_gmail_outcome_v1(PDO $pdo,array $user,array $binding,array $response): ?array
{
    require_once __DIR__.'/agent-initiative-curiosity-analysis-v1.php';
    $q=$pdo->prepare("SELECT r.id,r.goal,a.result_json,p.result_json AS receipt_json FROM agent_workflow_runs r
        JOIN agent_workflow_actions a ON a.run_id=r.id AND a.owner_user_id=r.owner_user_id AND a.sequence_no=1
            AND a.status='completed' AND a.action_key='initiative.prepare' AND a.capability_key='initiative.prepare' AND a.execution_target='cloud'
        JOIN agent_workflow_receipts p ON p.id=(SELECT MAX(x.id) FROM agent_workflow_receipts x WHERE x.run_id=r.id
            AND x.action_id=a.id AND x.owner_user_id=r.owner_user_id AND x.attempt_no=a.attempt_count AND x.executor='cloud')
        WHERE r.owner_user_id=? AND CAST(COALESCE(r.agent_id,0) AS CHAR)=? AND r.status='completed'
            AND r.workflow_type='initiative' AND r.source_kind='agent_initiative' AND r.execution_target='cloud'
            AND r.capability_key='initiative.prepare' AND p.status='completed' AND a.result_json LIKE ? ORDER BY r.id DESC LIMIT 16");
    $q->execute([(int)$user['id'],(string)vp3_initiative_binding_agent_v1($binding),'%"source_ref":"'.$response['source_ref'].'"%']);
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $proof){
        if($proof['result_json']!==$proof['receipt_json'])continue;
        $goal=json_decode($proof['goal'],true);$saved=json_decode($proof['result_json'],true);$result=$saved['initiative_tool_result']??null;
        if(($goal['binding']??null)!==$binding||($goal['tool']??null)!=='curiosity.analyze'||($goal['report']??null)!==false
            ||($saved['effect_state']??null)!=='private_draft_ready'||!is_array($result)
            ||vp3_curiosity_analysis_tool_result_v1($result)===null||($saved['initiative_draft']??null)!==$result['draft']
            ||($result['output']['context_ref']??null)!==vp3_curiosity_analysis_ref_v1($user,$binding)
            ||($goal['primary']??null)!==$result['output']['context_ref']
            ||($goal['sources']??null)!==[$result['output']['context_ref']=>$result['output']['context_revision']])continue;
        foreach($result['output']['email_outcomes']??[] as $outcome){
            if($outcome['source_ref']===$response['source_ref']&&$outcome['source_revision']===$response['revision']){
                return $outcome+['receipt_key'=>hash('sha256',json_encode([$proof['id'],$outcome],JSON_THROW_ON_ERROR)),
                    'owner_objective'=>'unverified','interpretation'=>'Historical model assessment of a verified watched reply.'];
            }
        }
    }
    return null;
}

function vp3_curiosity_gmail_checkin_marker_v1(mixed $value): ?array
{
    return is_array($value)&&array_keys($value)===['source_ref','response_revision','run_id']
        &&is_string($value['source_ref'])&&preg_match('/\Acuriosity-finding:[a-f0-9]{32}\z/D',$value['source_ref'])
        &&is_string($value['response_revision'])&&preg_match('/\A[a-f0-9]{64}\z/D',$value['response_revision'])
        &&is_int($value['run_id'])&&$value['run_id']>0?$value:null;
}

/** One delivered decision per exact watched response, even after later unrelated analysis passes. */
function vp3_curiosity_gmail_checkin_current_v1(PDO $pdo,array $user,array $binding,array $marker): void
{
    if(vp3_curiosity_gmail_checkin_marker_v1($marker)===null)throw new Vp3InitiativeSourcesChangedV1();
    $ctx=vp3_curiosity_gmail_response_v1($pdo,$user,$binding,$marker['source_ref']);
    if($ctx===null||$ctx['response']['revision']!==$marker['response_revision'])throw new Vp3InitiativeSourcesChangedV1();
    $run=agent_workflow_row_v1400($pdo,(int)$user['id'],$marker['run_id']);$goal=json_decode((string)($run['goal']??''),true);
    if(($goal['binding']??null)!==$binding||($goal['tool']??null)!=='chat.check_in'
        ||($goal['primary']??null)!==$marker['source_ref'])throw new Vp3InitiativeSourcesChangedV1();
    $field=static function(string $column,string $path)use($pdo):string{
        $value="JSON_EXTRACT(CASE WHEN JSON_VALID($column) THEN $column ELSE '{}' END,'$path')";
        return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?$value:"JSON_UNQUOTE($value)";
    };
    $tool=$field('r.goal','$.tool');$primary=$field('r.goal','$.primary');$delivery=$field('a.result_json','$.initiative_delivery');
    $revision=$field('a.result_json','$.initiative_tool_result.output.curiosity_email_reply.response_revision');
    $message=$field('a.result_json','$.initiative_message_id');$source=$field('m.context_json','$.source');
    $q=$pdo->prepare("SELECT a.result_json,p.result_json AS receipt_json FROM agent_workflow_runs r
        JOIN agent_workflow_actions a ON a.run_id=r.id AND a.owner_user_id=r.owner_user_id AND a.sequence_no=1 AND a.status='completed'
        JOIN agent_workflow_receipts p ON p.id=(SELECT MAX(x.id) FROM agent_workflow_receipts x WHERE x.run_id=r.id
            AND x.action_id=a.id AND x.owner_user_id=r.owner_user_id AND x.attempt_no=a.attempt_count AND x.executor='cloud')
        JOIN chat_messages m ON m.id=$message AND m.conversation_id=? AND m.role='assistant' AND m.user_id IS NULL
        WHERE r.owner_user_id=? AND CAST(COALESCE(r.agent_id,0) AS CHAR)=? AND r.id<>? AND r.status='completed'
            AND r.workflow_type='initiative' AND r.source_kind='agent_initiative' AND r.execution_target='cloud'
            AND r.capability_key='initiative.prepare' AND a.action_key='initiative.prepare' AND a.capability_key='initiative.prepare'
            AND a.execution_target='cloud' AND p.status='completed' AND $source='agent_initiative'
            AND $tool='chat.check_in' AND $primary=? AND $delivery='delivered' AND $revision=? LIMIT 16");
    $q->execute([(int)$binding['conversation_id'],(int)$user['id'],(string)vp3_initiative_binding_agent_v1($binding),
        $marker['run_id'],$marker['source_ref'],$marker['response_revision']]);
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)if($row['result_json']===$row['receipt_json'])throw new Vp3InitiativeSourcesChangedV1();
}
