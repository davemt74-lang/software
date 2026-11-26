<?php
declare(strict_types=1);
require_once __DIR__.'/agent-operator-objectives-v1.php';

/** Choose from the owner's current questions. Historical answers never enter the voice briefing. */
function vp3_operator_question_preparation_v1(PDO $pdo,array $scope,array $line,int $contact,string $to,int $now,?callable $reader=null):array
{
    $empty=['state'=>'unavailable','questions'=>[],'fingerprint'=>''];
    if($contact<1||!vp3_operator_objectives_ready_v1($pdo)||!vp3_phone_recording_schema_ready_v1($pdo))return $empty;
    if(function_exists('vp3_participant_scope_v1')&&vp3_participant_scope_v1()!==null)return $empty;
    try{
        $lookup="SELECT a.*,c.phone contact_phone FROM phone_operator_agendas_v1 a JOIN crm_contacts c ON c.id=a.contact_id AND c.owner_user_id=a.owner_user_id
            WHERE a.owner_user_id=? AND a.contact_id=? AND a.conversation_id=? AND a.chat_agent_id=? AND c.status<>'archived'";
        $q=$pdo->prepare($lookup);
        $key=[(int)$scope['owner_user_id'],$contact,(int)$scope['conversation_id'],(int)$scope['agent_id']];
        $q->execute($key);$agenda=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        if(!$agenda||(int)$agenda['number_id']!==(int)$line['id']||$agenda['destination_hash']!==hash('sha256',$to)
            ||vp3_phone_caller_contacts_e164_v1($agenda['contact_phone'])!==$to)return $empty;
        $questions=vp3_operator_objectives_questions_v1($agenda['questions']);if(!$questions)return $empty;
        $hash=vp3_operator_objectives_hash_v1($agenda);$call=['owner_user_id'=>$key[0],'peer_e164'=>$to];$ref=['contact_id'=>$contact];
        $memory=vp3_operator_objectives_memory_v1($pdo,$call,$ref,$now);
        if($memory==='')return array_replace($empty,['state'=>'permission_required']);
        $q=$pdo->prepare("SELECT j.* FROM phone_operator_call_agendas_v1 r JOIN phone_browser_calls_v1 c ON c.id=r.call_id AND c.public_id=r.call_public_id
            JOIN phone_recording_jobs_v1 j ON j.call_id=c.id AND j.call_public_id=c.public_id AND j.owner_user_id=c.owner_user_id AND j.origin_kind='browser'
            WHERE r.owner_user_id=? AND r.contact_id=? AND r.agenda_hash=? AND r.agent_id=? AND r.chat_agent_id=? AND r.conversation_id=?
              AND c.phone_number_id=? AND c.peer_e164=? AND c.state='completed' AND c.ended_epoch>0 AND c.ended_epoch<=?
              AND r.created_epoch<=? AND r.created_epoch>=? AND j.state='ready'
            ORDER BY r.created_epoch DESC,r.call_id DESC,j.id DESC LIMIT 6");
        $q->execute([$key[0],$contact,$hash,(int)$line['agent_id'],$key[3],$key[2],(int)$line['id'],$to,$now,$now,$now-90*86400]);
        $jobs=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();$findings=[];$calls=0;
        foreach($jobs as $job){
            if($calls>=3)break;
            try{$review=vp3_operator_objectives_for_job_v1($pdo,$scope,$job,$reader);}catch(Throwable){continue;}
            if($review['coverage']!=='ready')continue;$calls++;
            foreach($review['questions'] as $item){
                if($item['state']==='unverified'||isset($findings[$item['key']]))continue;
                $findings[$item['key']]=['state'=>$item['state'],'revision'=>$item['revision'],'source_url'=>$item['source_url']?:
                    ((string)parse_url(url('/artist-listening.php'),PHP_URL_PATH).'?session='.(int)$job['session_id']),
                    'call_id'=>$job['call_public_id']];
            }
        }
        // A fresh scope/agenda/grant read fences withdrawn authority without copying prior speech.
        $user=vp3_phone_recording_owner_v1($pdo,$key[0]);
        $fresh=vp3_phone_workspace_scope_v1($pdo,$user,['conversation_id'=>$key[2],'agent_id'=>$key[3]]);
        $q=$pdo->prepare($lookup);
        $q->execute($key);$again=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        $currentLine=vp3_phone_workspace_number_v1($pdo,$fresh,(int)$line['id']);
        if(!$again||vp3_phone_caller_contacts_e164_v1($again['contact_phone'])!==$to
            ||!hash_equals($hash,vp3_operator_objectives_hash_v1($again))||!hash_equals($memory,vp3_operator_objectives_memory_v1($pdo,$call,$ref,$now))
            ||(int)$currentLine['agent_id']!==(int)$line['agent_id']||$currentLine['phone_e164']!==$line['phone_e164']||$currentLine['provider_sid']!==$line['provider_sid'])
            return array_replace($empty,['state'=>'changed']);
        if(!$findings)return array_replace($empty,['state'=>'no_findings']);
        $pending=[];$reviewed=0;$reviewedSource='';
        $rank=['open'=>0,'conflicting'=>1,'answer_suggested'=>2,'unverified'=>3];
        foreach($questions as $index=>$question){
            $finding=$findings[$question['key']]??['state'=>'unverified','source_url'=>''];
            if($finding['state']==='answer_reviewed'){$reviewed++;$reviewedSource=$reviewedSource?:$finding['source_url'];continue;}
            $pending[]=$question+['state'=>$finding['state'],'source_url'=>$finding['source_url'],'order'=>$index];
        }
        usort($pending,static fn($a,$b):int=>[$rank[$a['state']],$a['order']]<=>[$rank[$b['state']],$b['order']]);
        foreach($pending as &$item)unset($item['order']);unset($item);
        $reason=match($pending[0]['state']??''){'open'=>'open_question','conflicting'=>'conflicting_question','answer_suggested'=>'answer_needs_review',
            default=>$pending?'reviewed_answers_omitted':'questions_reviewed'};
        ksort($findings);
        return ['state'=>'ready','questions'=>$pending,'topics'=>(string)$agenda['topics'],'reviewed_count'=>$reviewed,'pending_count'=>count($pending),'reason'=>$reason,
            'source_url'=>vp3_operator_question_source_url_v1(($pending[0]['source_url']??'')?:$reviewedSource),
            'fingerprint'=>hash('sha256',json_encode([$hash,$memory,(int)$line['id'],(int)$line['agent_id'],$findings],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))];
    }catch(Throwable $error){return $error instanceof VP3PhoneWorkspaceException||in_array((int)$error->getCode(),[403,404,409],true)?array_replace($empty,['state'=>'changed']):$empty;}
}

function vp3_operator_question_brief_v1(array $preparation,string $objective,bool $spontaneous,bool $missed):array
{
    $questions=array_column($preparation['questions'],'question');$parts=[];
    if(!$spontaneous&&trim($objective)!=='')$parts[]="Current owner objective\n".$objective;
    if($spontaneous&&$missed)$parts[]='Follow up on the missed scheduled call. Ask whether this is a good time or whether to arrange another time.';
    if($spontaneous&&$preparation['topics']!=='')$parts[]="Topics to discuss\n".$preparation['topics'];
    $parts[]=$questions?"Pending questions from the owner, in this order\n".implode("\n",$questions):'No saved questions remain pending in the current reviewed evidence.';
    $parts[]='Prefer the pending questions when relevant. Do not repeat other saved questions based only on earlier conversation. A current explicit owner objective or a participant request to revisit an answer can override that preference. A reviewed answer does not establish real-world completion. Do not mention private review labels or internal analysis to the recipient.';
    $result=['objectives'=>implode("\n\n",$parts),'question_preparation'=>$preparation];
    if($spontaneous||trim($objective)===''){
        $topic=trim(strtok($preparation['topics'],"\n")?:'');
        $result['question_opening']=$questions[0]??($topic!==''?'I’m calling about '.$topic.'. Is now a good time to discuss it?':
            ($missed?'Is now a good time to follow up on our missed call?':'Is there anything else you would like to add before we finish?'));
    }
    return $result;
}

function vp3_operator_question_source_url_v1(mixed $value):string
{
    if(!is_string($value)||strlen($value)>1600||!preg_match('~^/(?!/)~D',$value)||preg_match('/[\p{Cc}\p{Cf}]/u',$value))return '';
    $path=parse_url($value,PHP_URL_PATH);
    return in_array($path,[parse_url(url('/artist-listening-evidence.php'),PHP_URL_PATH),parse_url(url('/artist-listening.php'),PHP_URL_PATH)],true)?$value:'';
}

/** Fixed owner-only receipt; no question, answer, contact name or phone number is stored here. */
function vp3_operator_question_receipt_v1(array $preparation,int $now):array
{
    if(($preparation['state']??'')!=='ready')return [];
    return ['kind'=>'operator_question_preparation_v1','reason'=>$preparation['reason'],'pending_count'=>$preparation['pending_count'],
        'reviewed_count'=>$preparation['reviewed_count'],'fingerprint'=>$preparation['fingerprint'],
        'source_url'=>vp3_operator_question_source_url_v1($preparation['source_url']),'prepared_epoch'=>$now];
}
function vp3_operator_question_receipt_text_v1(array $receipt):string
{
    $text=match($receipt['reason']??''){
        'open_question'=>'An unanswered finding from an earlier call leads this question list.',
        'conflicting_question'=>'An earlier finding needs clarification before continuing.',
        'answer_needs_review'=>'An earlier answer still needs review.',
        'reviewed_answers_omitted'=>'Reviewed answers were left out of the new question list.',
        'questions_reviewed'=>'All saved questions already have reviewed answers.',default=>'',
    };
    return $text===''?'':$text.' Pending questions: '.(int)$receipt['pending_count'].'. Reviewed answers omitted: '.(int)$receipt['reviewed_count'].'.';
}

/** Historical explanation from the original private call delivery; never an execution source. */
function vp3_operator_question_receipts_v1(PDO $pdo,array $scope,int $now):array
{
    if((int)$scope['conversation_id']<1||(function_exists('vp3_participant_scope_v1')&&vp3_participant_scope_v1()!==null))return [];
    try{
        $q=$pdo->prepare("SELECT c.public_id,m.context_json,c.created_epoch,d.event_key FROM phone_browser_calls_v1 c
            JOIN phone_browser_deliveries_v1 d ON d.chat_message_id=c.chat_message_id AND d.owner_user_id=c.owner_user_id AND d.conversation_id=c.conversation_id AND d.chat_agent_id=c.chat_agent_id
            JOIN chat_messages m ON m.id=d.chat_message_id AND m.conversation_id=d.conversation_id AND m.role='assistant'
            JOIN chat_conversations v ON v.id=d.conversation_id AND v.user_id=d.owner_user_id AND COALESCE(v.user_agent_id,0)=c.chat_agent_id
            WHERE c.owner_user_id=? AND c.conversation_id=? AND c.chat_agent_id=? ORDER BY c.created_epoch DESC,c.id DESC LIMIT 60");
        $q->execute([(int)$scope['owner_user_id'],(int)$scope['conversation_id'],(int)$scope['agent_id']]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();$out=[];
        foreach($rows as $row){
            if(!preg_match('/^[a-f0-9]{32}$/D',$row['public_id'])||$row['event_key']!=='call:'.$row['public_id'])continue;
            $context=vp3_phone_recording_decode_v1($row['context_json'])['phone_v1']??[];$receipt=$context['question_preparation']??null;
            if(($context['call_id']??'')!==$row['public_id']||($context['kind']??'')!=='call'||!is_array($receipt)
                ||($receipt['kind']??'')!=='operator_question_preparation_v1'||!preg_match('/^[a-f0-9]{64}$/D',(string)($receipt['fingerprint']??''))
                ||!is_int($receipt['pending_count']??null)||!is_int($receipt['reviewed_count']??null)
                ||$receipt['pending_count']<0||$receipt['reviewed_count']<0||$receipt['pending_count']+$receipt['reviewed_count']<1||$receipt['pending_count']+$receipt['reviewed_count']>12
                ||!is_int($receipt['prepared_epoch']??null)||$receipt['prepared_epoch']<1||$receipt['prepared_epoch']>$now+300)continue;
            if(vp3_operator_question_receipt_text_v1($receipt)==='')continue;
            $out[$row['public_id']]=array_intersect_key($receipt,array_flip(['kind','reason','pending_count','reviewed_count','fingerprint','prepared_epoch']));
            $out[$row['public_id']]['source_url']=vp3_operator_question_source_url_v1($receipt['source_url']??'');
        }return $out;
    }catch(PDOException){return [];}
}
