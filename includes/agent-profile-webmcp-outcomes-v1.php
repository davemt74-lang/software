<?php
declare(strict_types=1);

/** Server-only bindings and bounded observations of the existing Profile action ledger. */
function vp3_profile_webmcp_outcome_operations_v1(): array
{
    return [
        'booking.create'=>['vp3.booking.prepare','vp3.booking.confirm','booking','booking_created'],
        'booking.reschedule'=>['vp3.booking.reschedule.prepare','vp3.booking.reschedule.confirm','booking','booking_rescheduled'],
        'booking.cancel'=>['vp3.booking.cancel.prepare','vp3.booking.cancel.confirm','booking','booking_cancelled'],
        'commerce.checkout'=>['vp3.commerce.checkout.prepare','vp3.commerce.checkout.confirm','order','checkout_started'],
        'commerce.refund_request'=>['vp3.commerce.refund.prepare','vp3.commerce.refund.confirm','order','refund_requested'],
        'campaign.participate'=>['vp3.campaign.participation.prepare','vp3.campaign.participation.confirm','campaign_enrollment','campaign_enrolled'],
        'reward.claim_handoff'=>['vp3.rewards.claim.prepare','vp3.rewards.claim.confirm','reward_claim_handoff','reward_claim_handoff'],
        'reward.transfer'=>['vp3.rewards.transfer.prepare','vp3.rewards.transfer.confirm','reward_transfer','reward_transferred'],
    ];
}

function vp3_profile_webmcp_outcome_time_v1(mixed $value): ?int
{
    if(!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:Z)?$/D',$value))return null;
    try{$time=new DateTimeImmutable($value,new DateTimeZone('UTC'));$errors=DateTimeImmutable::getLastErrors();}catch(Throwable){return null;}
    return $errors!==false&&($errors['warning_count']||$errors['error_count'])?null:$time->getTimestamp();
}

function vp3_profile_webmcp_outcome_json_v1(mixed $value): array
{
    if(!is_string($value)||strlen($value)>65536)throw new RuntimeException('Profile receipt unavailable.');
    try{$out=json_decode($value,true,12,JSON_THROW_ON_ERROR);}catch(Throwable){throw new RuntimeException('Profile receipt unavailable.');}
    if(!is_array($out)||($out!==[]&&array_is_list($out)))throw new RuntimeException('Profile receipt unavailable.');
    return $out;
}

function vp3_profile_webmcp_outcome_id_v1(mixed $value): int
{
    if(!(is_int($value)||(is_string($value)&&preg_match('/^[1-9][0-9]*$/D',$value)))||(int)$value<1)throw new RuntimeException('Profile receipt identity unavailable.');
    return (int)$value;
}

function vp3_profile_webmcp_outcome_string_v1(mixed $value,int $max): string
{
    if(!is_string($value)||!mb_check_encoding($value,'UTF-8')||mb_strlen($value)>$max||preg_match('/[\p{Cc}\p{Cf}]/u',$value))throw new RuntimeException('Profile receipt text unavailable.');
    return $value;
}

/** No arbitrary text, URLs, record identifiers or credentials can pass this public contract. */
function vp3_profile_webmcp_outcome_projection_v1(array $outcome): array
{
    $keys=['contract','verification','operation','state','result','committed_at','replayed'];
    if(array_diff(array_keys($outcome),$keys)||array_diff($keys,array_keys($outcome))||($outcome['contract']??'')!=='vp3.webmcp.outcome.v1'||!is_bool($outcome['replayed']))throw new InvalidArgumentException('Invalid Profile outcome.');
    $op=$outcome['operation'];$state=$outcome['state'];$operations=vp3_profile_webmcp_outcome_operations_v1();
    if(!is_string($op)||($op!==''&&!isset($operations[$op])))throw new InvalidArgumentException('Invalid Profile outcome operation.');
    if($outcome['verification']==='unverified'){
        if($state!=='unverified'||$outcome['result']!=='unverified'||$outcome['committed_at']!==null||$outcome['replayed'])throw new InvalidArgumentException('Invalid unverified Profile outcome.');
    }elseif($outcome['verification']==='verified'&&isset($operations[$op])){
        $expected=match($state){'prepared'=>'awaiting_confirmation','executing'=>'execution_pending','recorded'=>$operations[$op][3],default=>''};
        if($expected===''||$outcome['result']!==$expected)throw new InvalidArgumentException('Invalid Profile result.');
        if($state==='recorded'){
            $time=vp3_profile_webmcp_outcome_time_v1($outcome['committed_at']);
            if($time===null||$time>time()||gmdate('Y-m-d\TH:i:s\Z',$time)!==$outcome['committed_at'])throw new InvalidArgumentException('Invalid Profile receipt time.');
        }elseif($outcome['committed_at']!==null||$outcome['replayed'])throw new InvalidArgumentException('Invalid pending Profile result.');
    }else throw new InvalidArgumentException('Invalid Profile outcome verification.');
    return array_intersect_key($outcome,array_flip($keys));
}

function vp3_profile_webmcp_outcome_unverified_v1(string $operation=''): array
{
    return ['contract'=>'vp3.webmcp.outcome.v1','verification'=>'unverified','operation'=>isset(vp3_profile_webmcp_outcome_operations_v1()[$operation])?$operation:'','state'=>'unverified','result'=>'unverified','committed_at'=>null,'replayed'=>false];
}

function vp3_profile_webmcp_outcome_text_v1(array $outcome): string
{
    try{$outcome=vp3_profile_webmcp_outcome_projection_v1($outcome);}catch(Throwable){$outcome=vp3_profile_webmcp_outcome_unverified_v1();}
    return match($outcome['result']){
        'awaiting_confirmation'=>'The Profile action is prepared and still awaits confirmation on the signed Profile surface.',
        'execution_pending'=>'The Profile action ledger records work in progress. A completed result has not been verified.',
        'booking_created'=>'The booking action was recorded. Any required payment has a separate status; this receipt does not establish payment.',
        'booking_rescheduled'=>'The booking reschedule was recorded. This is the recorded action, not a fresh availability or payment check.',
        'booking_cancelled'=>'The booking cancellation was recorded. This receipt does not establish a refund.',
        'checkout_started'=>'Checkout was started and an order was recorded. This receipt does not establish that payment completed.',
        'refund_requested'=>'The refund request was submitted. This receipt does not establish that money was refunded.',
        'campaign_enrolled'=>'Campaign participation was recorded. Verification, eligibility fulfillment and reward delivery may still require further steps.',
        'reward_claim_handoff'=>'The Reward claim handoff was recorded. The Reward was not redeemed by this action; redemption requires the merchant operator.',
        'reward_transferred'=>'The exact Reward transfer was recorded as sent. This receipt does not establish that the recipient viewed or redeemed it.',
        default=>'The Profile action result could not be verified from a matching server receipt. No action has been repeated.',
    };
}

/** Private, server-created only. The public projection never includes this binding. */
function vp3_profile_webmcp_action_binding_v1(array $binding): array
{
    $required=['contract','owner_user_id','profile_username','surface','property_id','session_hash','operation','prepare_tool','confirm_tool','prepared_intent_id','payload_hash','bound_at'];
    $optional=['committed_intent_id','idempotency_hash','replayed'];
    if(array_diff(array_keys($binding),[...$required,...$optional])||array_diff($required,array_keys($binding)))throw new InvalidArgumentException('Invalid server action binding.');
    $operations=vp3_profile_webmcp_outcome_operations_v1();$op=$binding['operation'];
    if($binding['contract']!=='vp3.webmcp.server-action.v1'||!is_int($binding['owner_user_id'])||$binding['owner_user_id']<1||$binding['surface']!=='native_profile'||$binding['property_id']!==0||!is_string($op)||!isset($operations[$op])||$binding['prepare_tool']!==$operations[$op][0]||$binding['confirm_tool']!==$operations[$op][1])throw new InvalidArgumentException('Invalid server action authority.');
    if(vp3_profile_webmcp_outcome_string_v1($binding['profile_username'],64)===''||!is_int($binding['bound_at'])||$binding['bound_at']<1||$binding['bound_at']>time())throw new InvalidArgumentException('Invalid server action binding time.');
    foreach(['session_hash'=>64,'prepared_intent_id'=>32,'payload_hash'=>64] as $key=>$length)if(!is_string($binding[$key])||!preg_match('/^[a-f0-9]{'.$length.'}$/D',$binding[$key]))throw new InvalidArgumentException('Invalid server action identity.');
    if(array_intersect($optional,array_keys($binding))){
        if(array_diff($optional,array_keys($binding))||!is_bool($binding['replayed'])||!is_string($binding['committed_intent_id'])||!preg_match('/^[a-f0-9]{32}$/D',$binding['committed_intent_id'])||!is_string($binding['idempotency_hash'])||!preg_match('/^[a-f0-9]{64}$/D',$binding['idempotency_hash']))throw new InvalidArgumentException('Invalid committed server action identity.');
    }
    return $binding;
}

function vp3_profile_webmcp_outcome_row_v1(PDO $pdo,array $binding,string $intent): array
{
    $q=$pdo->prepare('SELECT owner_user_id,profile_username,surface,property_id,session_hash,intent_id,operation,payload_hash,idempotency_hash,state,result_type,result_id,result_json,expires_at,committed_at,created_at FROM profile_webmcp_actions WHERE owner_user_id=? AND intent_id=? LIMIT 2');
    $q->execute([$binding['owner_user_id'],$intent]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
    if(count($rows)!==1)throw new RuntimeException('Profile receipt unavailable.');$row=$rows[0];
    if(vp3_profile_webmcp_outcome_id_v1($row['owner_user_id'])!==$binding['owner_user_id']||(int)($row['property_id']??0)!==0||($row['intent_id']??null)!==$intent)throw new RuntimeException('Profile receipt identity changed.');
    foreach(['profile_username','surface','session_hash','operation','payload_hash'] as $key)if(!is_string($row[$key])||!hash_equals($binding[$key],$row[$key]))throw new RuntimeException('Profile receipt authority changed.');
    return $row;
}

/** Additional continuity binding only; the canonical operation still verifies the signature. */
function vp3_profile_webmcp_request_binding_v1(PDO $pdo,array $profile,array $continuity,array $transport,string $tool,array $args): void
{
    if(!function_exists('vp3_profile_webmcp_payload_hash_v150'))require_once __DIR__.'/profile-webmcp-actions-v150.php';
    $operation='';$confirm=false;
    foreach(vp3_profile_webmcp_outcome_operations_v1() as $op=>$spec)if(in_array($tool,[$spec[0],$spec[1]],true)){$operation=$op;$confirm=$tool===$spec[1];break;}
    if($operation==='')return;
    if(!in_array($tool,$continuity['allowed_tools']??[],true)||(int)($profile['user_id']??0)!==($continuity['profile_user_id']??null)||($profile['username']??null)!==($continuity['profile_username']??null))throw new RuntimeException('Profile action context changed.',403);
    foreach(['owner_user_id'=>$continuity['profile_user_id'],'profile_username'=>$continuity['profile_username'],'surface'=>'native_profile','property_id'=>0] as $key=>$value)if(($transport[$key]??null)!==$value)throw new RuntimeException('Profile action transport changed.',403);
    if(!is_string($transport['session_hash']??null)||!preg_match('/^[a-f0-9]{64}$/D',$transport['session_hash']))throw new RuntimeException('Profile action transport unavailable.',403);
    if(!$confirm)return;
    $binding=vp3_profile_webmcp_action_binding_v1($continuity['server_action']??[]);
    if($binding['owner_user_id']!==$transport['owner_user_id']||$binding['profile_username']!==$transport['profile_username']||$binding['operation']!==$operation||$binding['session_hash']!==$transport['session_hash']||!is_array($args['intent']??null)||vp3_profile_webmcp_payload_hash_v150($args['intent'])!==$binding['payload_hash'])throw new RuntimeException('Profile confirmation binding changed.',403);
    $token=$args['confirmation_token']??null;
    if(!is_string($token)||strlen($token)>8192||!preg_match('/^([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]{43})$/D',$token,$parts))throw new RuntimeException('Profile confirmation identity unavailable.',403);
    $json=base64_decode(strtr($parts[1],'-_','+/'),true);$claims=vp3_profile_webmcp_outcome_json_v1($json);
    foreach(['owner_user_id','profile_username','surface','property_id','session_hash','operation','payload_hash'] as $key)if(($claims[$key]??null)!==$binding[$key])throw new RuntimeException('Profile confirmation identity changed.',403);
    if(($claims['v']??null)!==1||($claims['intent_id']??null)!==$binding['prepared_intent_id']||($claims['origin_hash']??null)!==''||!is_int($claims['exp']??null)||$claims['exp']<time())throw new RuntimeException('Profile confirmation identity changed.',403);
    vp3_profile_webmcp_outcome_row_v1($pdo,$binding,$binding['prepared_intent_id']);
}

/** Preserve operation-specific replay facts without copying private contacts or credentials. */
function vp3_profile_webmcp_replay_projection_v1(string $type,int $resultId,array $result): array
{
    if($resultId<1)throw new RuntimeException('Profile result identity unavailable.');
    if($type==='booking'){
        $row=$result['booking']??null;if(!is_array($row)||vp3_profile_webmcp_outcome_id_v1($row['booking_id']??null)!==$resultId||!is_bool($result['payment_required']??null))throw new RuntimeException('Booking receipt unavailable.');
        $booking=[];foreach(['booking_id','event_type_id'] as $key)$booking[$key]=vp3_profile_webmcp_outcome_id_v1($row[$key]??null);
        foreach(['event_title'=>300,'start_at_utc'=>30,'end_at_utc'=>30,'organizer_timezone'=>80,'guest_timezone'=>80,'status'=>24,'location_type'=>40] as $key=>$max)$booking[$key]=vp3_profile_webmcp_outcome_string_v1($row[$key]??null,$max);
        if(!in_array($booking['status'],['pending','confirmed','cancelled'],true)||vp3_profile_webmcp_outcome_time_v1($booking['start_at_utc'])===null||vp3_profile_webmcp_outcome_time_v1($booking['end_at_utc'])===null)throw new RuntimeException('Booking receipt unavailable.');
        return ['booking'=>$booking,'payment_required'=>$result['payment_required'],'payment_status'=>vp3_profile_webmcp_outcome_string_v1($result['payment_status']??null,40)];
    }
    if($type==='campaign_enrollment'){
        $campaign=$result['campaign']??null;$participation=$result['participation']??null;$lifecycle=$result['lifecycle']??null;
        if(!is_array($campaign)||!is_array($participation)||!is_array($lifecycle))throw new RuntimeException('Campaign receipt unavailable.');
        $out=['campaign'=>[],'participation'=>[],'lifecycle'=>[]];
        foreach(['public_id'=>36,'slug'=>120,'title'=>300,'campaign_type'=>80] as $key=>$max)$out['campaign'][$key]=vp3_profile_webmcp_outcome_string_v1($campaign[$key]??null,$max);
        foreach(['status'=>30,'created_at'=>30,'updated_at'=>30] as $key=>$max)$out['participation'][$key]=vp3_profile_webmcp_outcome_string_v1($participation[$key]??null,$max);
        $out['participation']['completed_at']=$participation['completed_at']??null;
        if($out['participation']['completed_at']!==null&&vp3_profile_webmcp_outcome_time_v1($out['participation']['completed_at'])===null)throw new RuntimeException('Campaign receipt unavailable.');
        foreach(['state'=>40,'public_action'=>80,'reward_timing'=>40] as $key=>$max)$out['lifecycle'][$key]=vp3_profile_webmcp_outcome_string_v1($lifecycle[$key]??null,$max);
        foreach(['verification_pending','trigger_pending'] as $key){if(!is_bool($lifecycle[$key]??null))throw new RuntimeException('Campaign receipt unavailable.');$out['lifecycle'][$key]=$lifecycle[$key];}
        $out['participation_reference']=vp3_profile_webmcp_outcome_string_v1($result['participation_reference']??null,32);
        return $out;
    }
    if(in_array($type,['reward_claim_handoff','reward_transfer'],true)){
        $public=vp3_profile_webmcp_outcome_string_v1($result['reward_public_id']??null,36);
        if(!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$public))throw new RuntimeException('Reward receipt unavailable.');
        if($type==='reward_claim_handoff'){
            if(($result['redemption_requires_merchant_operator']??null)!==true||($result['credential_exposed']??null)!==false||($result['reward_redeemed']??null)!==false)throw new RuntimeException('Reward handoff receipt unavailable.');
            $path='/reward-inbox.php?claim='.rawurlencode($public);$expected=function_exists('url')?url($path):$path;
            if(($result['claim_handoff_url']??null)!==$expected)throw new RuntimeException('Reward handoff receipt unavailable.');
            return ['reward_public_id'=>$public,'claim_handoff_url'=>$expected,'redemption_requires_merchant_operator'=>true,'credential_exposed'=>false,'reward_redeemed'=>false];
        }
        $transfer=vp3_profile_webmcp_outcome_string_v1($result['transfer_public_id']??null,36);
        if(!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$transfer)||!in_array($result['delivery_mode']??null,['crm','vp3_inbox'],true)||($result['status']??null)!=='sent')throw new RuntimeException('Reward transfer receipt unavailable.');
        return ['reward_public_id'=>$public,'transfer_public_id'=>$transfer,'delivery_mode'=>$result['delivery_mode'],'status'=>'sent'];
    }
    throw new RuntimeException('Unsupported Profile result type.');
}

function vp3_profile_webmcp_outcome_result_v1(PDO $pdo,array $binding,array $row): void
{
    $op=$binding['operation'];$type=vp3_profile_webmcp_outcome_operations_v1()[$op][2];
    if(($row['result_type']??null)!==$type)throw new RuntimeException('Profile result type changed.');
    $id=vp3_profile_webmcp_outcome_id_v1($row['result_id']??null);$result=vp3_profile_webmcp_outcome_json_v1($row['result_json']??null);
    if($type==='booking'){
        $safe=vp3_profile_webmcp_replay_projection_v1($type,$id,$result);
        if(($op==='booking.cancel')!==($safe['booking']['status']==='cancelled'))throw new RuntimeException('Booking result changed.');
        $q=$pdo->prepare('SELECT id FROM agent_scheduling_bookings WHERE id=? AND owner_user_id=?');$q->execute([$id,$binding['owner_user_id']]);if((int)$q->fetchColumn()!==$id)throw new RuntimeException('Booking result unavailable.');
    }elseif($type==='order'){
        $number=vp3_profile_webmcp_outcome_string_v1($result['order_number']??null,80);if($number==='')throw new RuntimeException('Order receipt unavailable.');
        $q=$pdo->prepare('SELECT order_number,metadata_json FROM agent_commerce_orders_v800 WHERE id=? AND owner_user_id=?');$q->execute([$id,$binding['owner_user_id']]);$order=$q->fetch(PDO::FETCH_ASSOC);
        if(!$order||($order['order_number']??null)!==$number)throw new RuntimeException('Order receipt unavailable.');
        if($op==='commerce.refund_request'){$meta=vp3_profile_webmcp_outcome_json_v1($order['metadata_json']);$request=$meta['customer_refund_request']??null;if(!is_array($request)||!in_array($request['status']??null,['pending','seller_refund_submitted','declined'],true))throw new RuntimeException('Refund request receipt unavailable.');}
    }elseif($type==='campaign_enrollment'){
        $safe=vp3_profile_webmcp_replay_projection_v1($type,$id,$result);
        if(($safe['participation_reference']??'')!==$row['intent_id'])throw new RuntimeException('Campaign result identity changed.');
        $q=$pdo->prepare('SELECT c.public_id FROM campaign_enrollments e JOIN campaigns c ON c.id=e.campaign_id JOIN merchant_accounts m ON m.id=c.merchant_id WHERE e.id=? AND m.owner_user_id=?');$q->execute([$id,$binding['owner_user_id']]);if($q->fetchColumn()!==$safe['campaign']['public_id'])throw new RuntimeException('Campaign result unavailable.');
    }elseif($type==='reward_claim_handoff'){
        $safe=vp3_profile_webmcp_replay_projection_v1($type,$id,$result);
        $q=$pdo->prepare('SELECT ri.public_id FROM reward_issuances ri JOIN users u ON u.id=ri.recipient_user_id AND u.is_active=1 WHERE ri.id=? AND ri.recipient_user_id=?');$q->execute([$id,$binding['owner_user_id']]);if($q->fetchColumn()!==$safe['reward_public_id'])throw new RuntimeException('Reward handoff result unavailable.');
    }else{
        $safe=vp3_profile_webmcp_replay_projection_v1($type,$id,$result);
        $q=$pdo->prepare('SELECT t.public_id,t.delivery_mode,t.status,ri.public_id AS reward_public_id FROM reward_transfers t JOIN reward_issuances ri ON ri.id=t.reward_issuance_id WHERE t.id=? AND t.sent_by_user_id=?');$q->execute([$id,$binding['owner_user_id']]);$transfer=$q->fetch(PDO::FETCH_ASSOC);
        if(!$transfer||$transfer['public_id']!==$safe['transfer_public_id']||$transfer['reward_public_id']!==$safe['reward_public_id']||$transfer['delivery_mode']!==$safe['delivery_mode']||$transfer['status']!==$safe['status'])throw new RuntimeException('Reward transfer result unavailable.');
    }
}

function vp3_profile_webmcp_outcome_read_v1(PDO $pdo,array $boundContext): array
{
    $operation='';
    try{
        $binding=vp3_profile_webmcp_action_binding_v1($boundContext['server_action']??[]);$operation=$binding['operation'];
        if(($boundContext['profile_user_id']??null)!==$binding['owner_user_id']||($boundContext['profile_username']??null)!==$binding['profile_username']||($boundContext['binding']['contract']??'')!=='vp3.webmcp.owner-context.v1'||($boundContext['binding']['user_id']??null)!==$binding['owner_user_id'])throw new RuntimeException('Profile context changed.');
        $prepared=vp3_profile_webmcp_outcome_row_v1($pdo,$binding,$binding['prepared_intent_id']);
        $created=vp3_profile_webmcp_outcome_time_v1($prepared['created_at']);
        if($created===null||$created>time()||$created<(int)($boundContext['created_at']??PHP_INT_MAX)-1||$created>$binding['bound_at'])throw new RuntimeException('Profile action is not bound to this handoff.');
        $intent=$binding['committed_intent_id']??$binding['prepared_intent_id'];
        $row=$intent===$binding['prepared_intent_id']?$prepared:vp3_profile_webmcp_outcome_row_v1($pdo,$binding,$intent);
        $state=$row['state'];$committed=null;$replayed=false;
        if(isset($binding['idempotency_hash'])&&($row['idempotency_hash']??null)!==$binding['idempotency_hash'])throw new RuntimeException('Profile action replay changed.');
        if($state==='committed'){
            if(!is_string($row['idempotency_hash'])||!preg_match('/^[a-f0-9]{64}$/D',$row['idempotency_hash']))throw new RuntimeException('Profile receipt is incomplete.');
            $stamp=vp3_profile_webmcp_outcome_time_v1($row['committed_at']);$started=vp3_profile_webmcp_outcome_time_v1($row['created_at']);
            if($stamp===null||$started===null||$stamp<$started||$stamp>time())throw new RuntimeException('Profile receipt time unavailable.');
            vp3_profile_webmcp_outcome_result_v1($pdo,$binding,$row);
            if(vp3_profile_webmcp_outcome_row_v1($pdo,$binding,$intent)!==$row||($intent!==$binding['prepared_intent_id']&&vp3_profile_webmcp_outcome_row_v1($pdo,$binding,$binding['prepared_intent_id'])!==$prepared))throw new RuntimeException('Profile receipt changed during verification.');
            $state='recorded';$committed=gmdate('Y-m-d\TH:i:s\Z',$stamp);$replayed=$binding['replayed']??false;
        }elseif(in_array($state,['prepared','executing'],true)&&!isset($binding['committed_intent_id'])){
            $expires=vp3_profile_webmcp_outcome_time_v1($row['expires_at']);if($expires===null||$expires<time())throw new RuntimeException('Profile preparation expired.');
        }else throw new RuntimeException('Profile receipt state unavailable.');
        $out=['contract'=>'vp3.webmcp.outcome.v1','verification'=>'verified','operation'=>$operation,'state'=>$state,'result'=>$state==='recorded'?vp3_profile_webmcp_outcome_operations_v1()[$operation][3]:($state==='prepared'?'awaiting_confirmation':'execution_pending'),'committed_at'=>$committed,'replayed'=>$replayed];
        return vp3_profile_webmcp_outcome_projection_v1($out);
    }catch(Throwable){return vp3_profile_webmcp_outcome_unverified_v1($operation);}
}

/** Called only with the actual successful native server response, never with a browser note. */
function vp3_profile_webmcp_server_evidence_v1(PDO $pdo,array $profile,array $continuity,array $transport,string $tool,array $args,array $result): array
{
    if(!function_exists('vp3_profile_webmcp_payload_hash_v150'))require_once __DIR__.'/profile-webmcp-actions-v150.php';
    $operation='';$isPrepare=false;
    foreach(vp3_profile_webmcp_outcome_operations_v1() as $op=>$spec){if($tool===$spec[0]||$tool===$spec[1]){$operation=$op;$isPrepare=$tool===$spec[0];break;}}
    if($operation==='')return [];
    vp3_profile_webmcp_request_binding_v1($pdo,$profile,$continuity,$transport,$tool,$args);
    if(!in_array($tool,$continuity['allowed_tools']??[],true)||(int)($profile['user_id']??0)!==($continuity['profile_user_id']??null)||($profile['username']??null)!==($continuity['profile_username']??null))throw new RuntimeException('Profile action context changed.',403);
    foreach(['owner_user_id'=>$continuity['profile_user_id'],'profile_username'=>$continuity['profile_username'],'surface'=>'native_profile','property_id'=>0] as $key=>$value)if(($transport[$key]??null)!==$value)throw new RuntimeException('Profile action transport changed.',403);
    if(!is_string($transport['session_hash']??null)||!preg_match('/^[a-f0-9]{64}$/D',$transport['session_hash']))throw new RuntimeException('Profile action transport unavailable.');
    if($isPrepare){
        // Successful provider-selection and other no-intent responses establish no action proof.
        if(!isset($result['intent_id'])||$result['intent_id']==='')return [];
        if(($result['confirmation_required']??null)!==true||!is_array($result['intent']??null))throw new RuntimeException('Profile preparation evidence unavailable.');
        if(isset($continuity['server_action'])){
            $prior=vp3_profile_webmcp_outcome_read_v1($pdo,$continuity);
            if($prior['verification']==='verified'&&$prior['state']==='recorded')return [];
        }
        $spec=vp3_profile_webmcp_outcome_operations_v1()[$operation];
        $binding=vp3_profile_webmcp_action_binding_v1(['contract'=>'vp3.webmcp.server-action.v1','owner_user_id'=>$transport['owner_user_id'],'profile_username'=>$transport['profile_username'],'surface'=>'native_profile','property_id'=>0,'session_hash'=>$transport['session_hash'],'operation'=>$operation,'prepare_tool'=>$spec[0],'confirm_tool'=>$spec[1],'prepared_intent_id'=>$result['intent_id'],'payload_hash'=>vp3_profile_webmcp_payload_hash_v150($result['intent']),'bound_at'=>time()]);
    }else{
        $binding=vp3_profile_webmcp_action_binding_v1($continuity['server_action']??[]);
        if($binding['operation']!==$operation||$binding['session_hash']!==$transport['session_hash']||!is_array($args['intent']??null)||vp3_profile_webmcp_payload_hash_v150($args['intent'])!==$binding['payload_hash'])throw new RuntimeException('Profile confirmation binding changed.');
        $key=$args['idempotency_key']??null;if(!is_string($key)||!preg_match('/^[A-Za-z0-9_-]{8,96}$/D',$key))throw new RuntimeException('Profile replay identity unavailable.');
        $idem=hash('sha256',$binding['owner_user_id'].'|'.$operation.'|'.$key);
        $anchor=vp3_profile_webmcp_outcome_row_v1($pdo,$binding,$binding['prepared_intent_id']);
        if($anchor['state']==='committed'&&($anchor['idempotency_hash']??null)===$idem)$receipt=$anchor;
        else{
            $q=$pdo->prepare('SELECT intent_id FROM profile_webmcp_actions WHERE owner_user_id=? AND operation=? AND idempotency_hash=? LIMIT 2');$q->execute([$binding['owner_user_id'],$operation,$idem]);$rows=$q->fetchAll(PDO::FETCH_COLUMN);
            if(count($rows)!==1||!is_string($rows[0])||!preg_match('/^[a-f0-9]{32}$/D',$rows[0]))throw new RuntimeException('Profile replay receipt unavailable.');
            $receipt=vp3_profile_webmcp_outcome_row_v1($pdo,$binding,$rows[0]);
        }
        if($receipt['state']!=='committed'||($receipt['idempotency_hash']??null)!==$idem)throw new RuntimeException('Profile confirmation receipt unavailable.');
        $binding['committed_intent_id']=$receipt['intent_id'];$binding['idempotency_hash']=$idem;$binding['replayed']=($result['idempotent_replay']??false)===true||$receipt['intent_id']!==$binding['prepared_intent_id'];
        $binding=vp3_profile_webmcp_action_binding_v1($binding);
    }
    $outcome=vp3_profile_webmcp_outcome_read_v1($pdo,array_replace($continuity,['server_action'=>$binding]));
    if($outcome['verification']!=='verified')throw new RuntimeException('Profile action receipt could not be verified.');
    return ['server_action'=>$binding,'server_outcome'=>$outcome];
}
