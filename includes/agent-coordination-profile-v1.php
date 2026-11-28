<?php
declare(strict_types=1);

require_once __DIR__.'/agent-profile-specialist-v1.php';

/** Private Chat authority is independent of the public Profile responder's Agent ID. */
function vp3_coordination_profile_authority_v1(PDO $pdo,array $scope,array $binding): array
{
    require_once __DIR__.'/agent-activity-scope-v1.php';
    require_once __DIR__.'/agent-initiative-scope-v1.php';
    $user=$scope['user']??null;$agent=$scope['agent_id']??null;$chat=$scope['conversation_id']??null;
    if(!is_array($user)||(int)($user['id']??0)<1||!is_int($agent)||$agent<0||!is_int($chat)||$chat<1
        ||vp3_initiative_binding_agent_v1($binding)!==$agent||($binding['conversation_id']??null)!==$chat
        ||(int)($binding['owner_user_id']??$user['id'])!==(int)$user['id'])throw new RuntimeException('Profile report access changed.',403);
    $fresh=vp3_agent_activity_scope_v1($pdo,$user,$agent,$chat);
    require_once __DIR__.'/profile-attention-pa4b.php';
    vp3_profile_attention_dependencies_pa4b();
    $fresh['user']=vp3_profile_handoff_owner_pa3($pdo,$fresh['user'],$chat,$agent);
    return $fresh;
}

function vp3_coordination_profile_query_v1(PDO $pdo,string $sql,array $args=[]): array
{
    $q=$pdo->prepare($sql);$q->execute($args);return $q->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Private fixed-period facts remain available independently of public responder
 * configuration. The existing native adapters retain their own availability.
 */
function vp3_coordination_profile_sources_v1(PDO $pdo,array $scope,array $binding,?array $refs,DateTimeImmutable $now): array
{
    if($refs!==null){
        if(!array_is_list($refs)||count($refs)>24)throw new InvalidArgumentException('Invalid Profile source selection.');
        foreach($refs as $ref)if(!is_string($ref)||vp3_profile_specialist_ref_kind_v1('profile:'.$ref)===null)
            throw new InvalidArgumentException('Invalid Profile source reference.');
    }
    $scope=vp3_coordination_profile_authority_v1($pdo,$scope,$binding);
    $native=$refs===null?null:array_values(array_filter($refs,static fn(string $ref):bool=>!str_starts_with($ref,'analytics:')&&!str_starts_with($ref,'ft:')));
    $periods=$refs===null?[null]:array_values(array_filter($refs,static fn(string $ref):bool=>str_starts_with($ref,'analytics:')));
    $periodItems=[];$periodCoverage='ready';
    if($periods){
        require_once __DIR__.'/profile-analytics-period-v1.php';
        require_once __DIR__.'/agent-profile-analytics-summary-v1.php';
        foreach($periods as $ref){
            try{
                $data=vp3_profile_analytics_period_v1($pdo,$scope,$ref===null?['period'=>1]:['ref'=>'profile:'.$ref],$now);
                $state=$data['coverage']??'unavailable';
                if(!in_array($state,['ready','limited','unconfigured','unavailable'],true))$state='unavailable';
                if($state!=='ready')$periodCoverage=$state==='unavailable'?'unavailable':($periodCoverage==='ready'?$state:$periodCoverage);
                if($ref===null&&!vp3_profile_analytics_discoverable_v1($data))continue;
                $item=vp3_profile_analytics_source_item_v1($data,$scope['agent_id'],$scope['conversation_id']);
                if($item!==null)$periodItems[$item['ref']]=$item;
            }catch(PDOException $error){throw $error;}
            catch(RuntimeException $error){
                if($error->getCode()===403)throw $error;
                if(!in_array($error->getCode(),[404,409,422,503],true))throw $error;
                $periodCoverage='unavailable';
            }
        }
    }
    $commerceRefs=$refs===null?null:array_values(array_filter($refs,static fn(string $ref):bool=>str_starts_with($ref,'ft:')));
    $commerce=['items'=>[],'coverage'=>'unconfigured'];
    if($commerceRefs!==[]){
        require_once __DIR__.'/agent-coordination-product-marketing-v1.php';
        $commerce=vp3_coordination_product_marketing_sources_v1($pdo,$scope,$commerceRefs,$now,true);
        $periodItems+=$commerce['items'];
        // Keep optional empty follow-through separate from period availability.
        if($commerce['items']||($commerceRefs!==null&&$commerceRefs!==[]))
            $periodCoverage=$periods?vp3_product_marketing_coverage_v1([$periodCoverage,$commerce['coverage']]):$commerce['coverage'];
    }
    // An explicit analytics selection never invokes public Profile discovery.
    if($native===[]){
        vp3_coordination_profile_authority_v1($pdo,$scope,$binding);
        return ['items'=>$periodItems,'coverage'=>$periodItems&&$periodCoverage==='unconfigured'?'limited':$periodCoverage];
    }
    $out=vp3_coordination_profile_native_sources_v1($pdo,$scope,$binding,$native,$now);
    if($periodItems){
        $out['items']+=$periodItems;
        $out['coverage']=in_array($out['coverage'],['ready','unconfigured'],true)&&$periodCoverage==='ready'?'ready':'limited';
    }elseif($refs!==null&&($periods||$commerceRefs)&&$periodCoverage!=='ready'){
        $out['coverage']=$out['items']?'limited':$periodCoverage;
    }
    // Unconfigured or empty optional period facts do not manufacture automatic
    // chatter. Their full per-metric availability remains in the explicit view.
    $rank=['high'=>0,'normal'=>1,'low'=>2];
    uasort($out['items'],static fn(array $a,array $b):int=>[($rank[$a['priority']]??2),$a['ref']]<=>[($rank[$b['priority']]??2),$b['ref']]);
    if(count($out['items'])>24){$out['items']=array_slice($out['items'],0,24,true);$out['coverage']='limited';}
    vp3_coordination_profile_authority_v1($pdo,$scope,$binding);
    return $out;
}

/** Discovery is bounded; selected references are exact and never turn into a scan. */
function vp3_coordination_profile_native_sources_v1(PDO $pdo,array $scope,array $binding,?array $refs,DateTimeImmutable $now): array
{
    $exact=$refs!==null;
    if($refs!==null){
        if(!array_is_list($refs)||count($refs)>24)throw new InvalidArgumentException('Invalid Profile source selection.');
        foreach($refs as $ref)if(!is_string($ref)||!preg_match('/\A(?:conversation|event|call|followup):[1-9][0-9]{0,17}\z/D',$ref))
            throw new InvalidArgumentException('Invalid Profile source reference.');
    }
    $scope=vp3_coordination_profile_authority_v1($pdo,$scope,$binding);
    $owner=(int)$scope['user']['id'];$out=['items'=>[],'coverage'=>'ready'];
    if(!vp3_profile_attention_schema_ready_pa4b($pdo))return ['items'=>[],'coverage'=>'unavailable'];
    $profile=vp3_coordination_profile_query_v1($pdo,'SELECT is_public,profile_agent_enabled,profile_agent_id FROM user_profiles WHERE user_id=?',[$owner]);
    if(!$profile||empty($profile[0]['is_public'])||empty($profile[0]['profile_agent_enabled']))return ['items'=>[],'coverage'=>'unconfigured'];
    require_once __DIR__.'/profile-calls-pa3c.php';
    $callReady=vp3_profile_call_schema_ready_pa3c($pdo);
    $kinds=$exact?array_map(static fn(string $ref):string=>explode(':',$ref,2)[0],$refs):['conversation','event','call','followup'];
    $needsCalls=(bool)array_intersect($kinds,['conversation','call','followup']);
    $needsFollowups=in_array('followup',$kinds,true);
    $followupReady=$needsFollowups?vp3_coordination_profile_followups_ready_v1($pdo,$scope):'ready';
    // Discovery can show unrelated verified sources. An exact saved request
    // must remain retryable when any necessary source store cannot be read.
    if($exact&&((!$callReady&&$needsCalls)||$followupReady==='unavailable'))return ['items'=>[],'coverage'=>'unavailable'];
    if($exact&&$followupReady==='unconfigured')return ['items'=>[],'coverage'=>'unconfigured'];
    if((!$callReady&&$needsCalls)||$followupReady!=='ready')$out['coverage']='limited';
    if($refs===null){
        // Native conversation timestamps use the database's wall clock. Keep
        // that discovery window on the same clock instead of comparing to UTC.
        $recentConversation=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'
            ?"datetime(CURRENT_TIMESTAMP,'-7 days')":'DATE_SUB(CURRENT_TIMESTAMP,INTERVAL 7 DAY)';
        $groups=[
            'conversation'=>vp3_coordination_profile_query_v1($pdo,"SELECT id FROM profile_agent_conversations WHERE owner_user_id=? AND (status IN ('open','owner_joined') OR (status='resolved' AND last_message_at>=".$recentConversation.")) ORDER BY last_message_at DESC,id DESC LIMIT 25",[$owner]),
            'event'=>vp3_coordination_profile_query_v1($pdo,"SELECT source_event_id id FROM agent_attention_items WHERE owner_user_id=? AND COALESCE(source_conversation_id,0)=0 AND status IN ('pending','seen','snoozed') ORDER BY id DESC LIMIT 25",[$owner]),
            'call'=>$callReady?vp3_coordination_profile_query_v1($pdo,"SELECT id FROM profile_calls_pa3c WHERE owner_user_id=? AND (state IN ('offered','preparing','ready','active','ending') OR updated_at_ms>=?) ORDER BY updated_at_ms DESC,id DESC LIMIT 25",[$owner,(int)$now->format('Uv')-7*86400000]):[],
            'followup'=>$followupReady==='ready'?vp3_coordination_profile_followup_candidates_v1($pdo,$scope):[],
        ];
        $refs=[];
        foreach($groups as $kind=>$rows){
            if(count($rows)>24)$out['coverage']='limited';
            foreach(array_slice($rows,0,24) as $row)$refs[]=$kind.':'.(int)$row['id'];
        }
    }
    $sourceUnavailable=false;
    foreach(array_unique($refs) as $ref){
        [$kind,$id]=explode(':',$ref,2);
        if(($kind==='call'&&!$callReady)||($kind==='followup'&&$followupReady!=='ready')){$out['coverage']='limited';continue;}
        try{
            $read=match($kind){
                'conversation'=>'vp3_coordination_profile_conversation_v1','event'=>'vp3_coordination_profile_event_v1',
                'call'=>'vp3_coordination_profile_call_v1','followup'=>'vp3_coordination_profile_followup_v1',
            };
            $item=$read($pdo,$scope,(int)$id,$now);
            if($item===null)continue;
            // A second exact read catches source changes during projection. No
            // stale statement, disposition or call state survives a failed check.
            $fresh=$read($pdo,$scope,(int)$id,$now);
            if($fresh===null||!hash_equals($item['revision'],$fresh['revision'])){$out['coverage']='limited';continue;}
            $out['items'][$fresh['ref']]=$fresh;
        }catch(PDOException $error){throw $error;}
        catch(RuntimeException $error){
            if(!in_array($error->getCode(),[403,404,409,422,503],true))throw $error;
            if($error->getCode()===503)$sourceUnavailable=true;
            $out['coverage']='limited';
        }
    }
    vp3_coordination_profile_authority_v1($pdo,$scope,$binding);
    if($exact&&$sourceUnavailable)return ['items'=>[],'coverage'=>'unavailable'];
    uasort($out['items'],static function(array $a,array $b):int{
        $rank=['high'=>0,'normal'=>1,'low'=>2];
        return [($rank[$a['priority']]??2),$a['ref']]<=>[($rank[$b['priority']]??2),$b['ref']];
    });
    if(count($out['items'])>24){$out['items']=array_slice($out['items'],0,24,true);$out['coverage']='limited';}
    return $out;
}

function vp3_coordination_profile_item_v1(string $kind,int $id,string $title,string $summary,string $status,string $trigger,
    string $priority,string $url,array $semantic,array $report,string $disposition='pending',string $presentationOwner='profile_attention_pa4b',bool $delivered=false): array
{
    $ref='profile:'.$kind.':'.$id;
    $title=vp3_profile_specialist_text_v1($title,190);$summary=vp3_profile_specialist_text_v1($summary,1200);
    return ['ref'=>$ref,'revision'=>hash('sha256',json_encode([$ref,$semantic,$title,$summary,$report,$presentationOwner],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE)),
        'specialist'=>'profile','title'=>$title,'summary'=>$summary,'next_step'=>$report['next_step'],
        'question'=>$report['questions_for_you'][0]??'','url'=>$url,'status'=>$status,'priority'=>$priority,'trigger'=>$trigger,
        'profile_source_kind'=>$kind,'disposition'=>$disposition,'presentation_owner'=>$presentationOwner,
        'profile_presentation_owned'=>in_array($presentationOwner,['profile_attention_pa4b','profile_handoff_pa3','profile_speech_pa4a','profile_calls_pa3c','office_tasks'],true),'legacy_delivered'=>$delivered,
        'private_summary_only'=>true,'public_evidence_untrusted'=>true,'profile_report'=>$report];
}

function vp3_coordination_profile_changed_v1(): never{throw new RuntimeException('Profile source is unavailable for current reporting.',409);}

/** Semantic attention deliberately excludes presentation counters and receipts. */
function vp3_coordination_profile_attention_v1(PDO $pdo,array $scope,int $eventId,DateTimeImmutable $now): ?array
{
    $owner=(int)$scope['user']['id'];$context=['conversation_id'=>$scope['conversation_id'],'agent_id'=>$scope['agent_id']];
    $notes=vp3_coordination_profile_query_v1($pdo,"SELECT id FROM notifications WHERE user_id=? AND source_type='profile_event' AND source_id=? ORDER BY id LIMIT 2",[$owner,$eventId]);
    if(!$notes)return null;
    if(count($notes)!==1)vp3_coordination_profile_changed_v1();
    $source=vp3_profile_attention_source_pa4b($pdo,$scope['user'],(int)$notes[0]['id'],$context);
    if($source['status']!=='verified')vp3_coordination_profile_changed_v1();
    $row=$source['attention'];
    if(!in_array($row['status'],['pending','seen','snoozed','handled','ignored'],true)
        ||(int)$row['wake_generation']<1||(int)$row['read_generation']<0)vp3_coordination_profile_changed_v1();
    $state=vp3_profile_attention_disposition_pa4b($row);$until=vp3_profile_attention_timestamp_pa4b($row['snoozed_until']??null);
    if($state==='snoozed'&&$until===null)vp3_coordination_profile_changed_v1();
    $due=$state==='snoozed'&&$until<=(int)$now->format('Uv');
    $delivery=vp3_profile_attention_delivery_row_pa4b($pdo,$owner,(int)$row['id'],(int)$row['wake_generation']+($due?1:0));
    $delivered=$delivery!==null&&vp3_profile_attention_delivery_valid_pa4b($pdo,$delivery,$source);
    if($delivery!==null&&!$delivered)vp3_coordination_profile_changed_v1();
    return ['source'=>$source,'disposition'=>$state,'suppressed'=>in_array($state,['handled','ignored'],true)||($state==='snoozed'&&!$due),
        'read'=>!$due&&(int)$row['read_generation']>=(int)$row['wake_generation'],'wake_due'=>$due,
        'semantic'=>[$source['source_hash'],$due?'pending':$state,(int)$row['wake_generation']+($due?1:0),$state==='snoozed'&&!$due?$until:null],
        'delivered'=>$delivered,'presentation_owner'=>$source['presentation_owner']];
}

function vp3_coordination_profile_event_v1(PDO $pdo,array $scope,int $id,DateTimeImmutable $now): ?array
{
    $context=['conversation_id'=>$scope['conversation_id'],'agent_id'=>$scope['agent_id']];
    $source=vp3_profile_event_source_pa4b($pdo,$scope['user'],$id,$context);
    if($source['status']!=='verified')vp3_coordination_profile_changed_v1();
    if($source['public_conversation_id']>0)return null; // One conversation finding owns threaded attention.
    $attention=vp3_coordination_profile_attention_v1($pdo,$scope,$id,$now);
    if($attention===null)vp3_coordination_profile_changed_v1();
    if($attention['suppressed'])return null;
    $body=vp3_profile_specialist_text_v1($source['projection']['body']);
    $title=match($source['event_type']){'profile_view'=>'Profile visit','return_visitor'=>'Returning Profile visit',default=>'Profile activity'};
    $report=vp3_profile_specialist_sections_v1([$body]);
    return vp3_coordination_profile_item_v1('event',$id,$title,$body,$attention['read']?'current':'new','activity','low',
        vp3_profile_specialist_href_v1('/profile-agent.php?tab=visitors&session='.$source['profile_session_id']),
        [$attention['semantic'],$attention['read'],$source['projection']['speaker_label']],$report,$attention['disposition'],$attention['presentation_owner'],$attention['delivered'])
        +['profile_event_type'=>$source['event_type']];
}

/** Exactly the newest statement must have current saved proof; never fall back to older text. */
function vp3_coordination_profile_conversation_v1(PDO $pdo,array $scope,int $id,DateTimeImmutable $now): ?array
{
    $owner=(int)$scope['user']['id'];$conversation=vp3_profile_handoff_conversation_pa3($pdo,$owner,$id);
    if(!in_array($conversation['status'],['open','owner_joined','resolved'],true))vp3_coordination_profile_changed_v1();
    $messages=vp3_coordination_profile_query_v1($pdo,"SELECT id,sender_type,sender_user_id,message,context_json FROM profile_agent_messages WHERE conversation_id=? AND sender_type IN ('visitor','owner','agent') ORDER BY id DESC LIMIT 1",[$id]);
    $message=$messages[0]??null;
    $eventSql="SELECT id FROM profile_events WHERE owner_user_id=? AND profile_session_id=?
        AND CASE WHEN JSON_VALID(metadata_json) THEN JSON_EXTRACT(metadata_json,'$.conversation_id') ELSE NULL END=CAST(? AS SIGNED)";
    $args=[$owner,(int)$conversation['profile_session_id'],$id];
    if($message){$eventSql.=" AND CASE WHEN JSON_VALID(metadata_json) THEN JSON_EXTRACT(metadata_json,'$.message_id') ELSE NULL END=CAST(? AS SIGNED) AND event_type IN ('message_received','message_sent')";$args[]=(int)$message['id'];}
    else $eventSql.=" AND event_type='conversation_started'";
    $events=vp3_coordination_profile_query_v1($pdo,$eventSql.' ORDER BY id DESC LIMIT 2',$args);
    if(count($events)!==1)vp3_coordination_profile_changed_v1();
    $eventId=(int)$events[0]['id'];$context=['conversation_id'=>$scope['conversation_id'],'agent_id'=>$scope['agent_id']];
    $source=vp3_profile_event_source_pa4b($pdo,$scope['user'],$eventId,$context);
    if($source['status']!=='verified'||$source['public_conversation_id']!==$id)vp3_coordination_profile_changed_v1();
    $rows=vp3_coordination_profile_query_v1($pdo,'SELECT source_event_id FROM agent_attention_items WHERE owner_user_id=? AND source_conversation_id=? ORDER BY source_event_id DESC,id DESC LIMIT 1',[$owner,$id]);
    $attention=$rows?vp3_coordination_profile_attention_v1($pdo,$scope,(int)$rows[0]['source_event_id'],$now):null;
    if($rows&&$attention===null)vp3_coordination_profile_changed_v1();
    $thread=vp3_profile_handoff_thread_pa3($pdo,$owner,$id);
    $turns=vp3_coordination_profile_query_v1($pdo,'SELECT * FROM profile_agent_handoff_turns_pa3 WHERE owner_user_id=? AND conversation_id=? ORDER BY visitor_message_id DESC,id DESC LIMIT 1',[$owner,$id]);
    $turn=$turns[0]??null;
    if($turn){
        $current=vp3_profile_call_context_pa3c($pdo,$owner,$id);
        if((int)$turn['visitor_message_id']>$source['public_message_id']
            ||vp3_profile_attention_accepted_source_pa4b($pdo,$source['event'],$current['profile'],$current['agent'],$current['session'],$current['binding'],$id,$source['public_message_id'])!==true)
            vp3_coordination_profile_changed_v1();
    }
    $state=(string)($turn['state']??'none');
    if(!in_array($state,['none','awaiting_delivery','awaiting_owner','owner_requested','owner_claimed','agent_ready','agent_running','replied','blocked','cancelled'],true))vp3_coordination_profile_changed_v1();
    $semanticState=in_array($state,['awaiting_delivery','awaiting_owner','owner_requested'],true)?'waiting':$state;
    $activeTurn=in_array($semanticState,['waiting','owner_claimed','agent_ready','agent_running'],true);
    $canonicalNeedsOwner=$attention!==null&&!$attention['suppressed']&&($attention['source']['event_type']??'')==='needs_owner';
    $settled=$conversation['status']==='resolved'||($message&&in_array($source['sender_type'],['owner','agent'],true)&&!$activeTurn&&!$canonicalNeedsOwner);
    // A disposition is not a new report. Without an independently ordered newer
    // source, handled/ignored/snoozed work stays out of automatic findings.
    if($attention!==null&&$attention['suppressed'])return null;
    $needsOwner=($attention['source']['event_type']??'')==='needs_owner'
        ||($source['sender_type']==='visitor'&&in_array($semanticState,['waiting','owner_claimed'],true));
    if($settled)$needsOwner=false;
    $label=vp3_profile_specialist_text_v1($source['projection']['speaker_label'],100)?:'A visitor';
    $body=vp3_profile_specialist_text_v1($source['projection']['body'],210);
    $statement=$message?(['visitor'=>'Visitor message: ','owner'=>'You: ','agent'=>'Profile Agent: '][$source['sender_type']]).'“'.$body.'”':$body;
    if($message&&defined('VP3_PROFILE_CHAT_ATTACHMENT_CONTEXT_V1')&&$message['context_json']===VP3_PROFILE_CHAT_ATTACHMENT_CONTEXT_V1)$statement='A visitor attachment is available in the authorized conversation.';
    $title=$settled?($conversation['status']==='resolved'?'Resolved Profile conversation':'Profile conversation update'):($needsOwner?'Profile conversation needs your attention':'Open Profile conversation');
    $next=$settled?'':($needsOwner?'Review the current visitor thread and decide whether to reply, delegate, or resolve it.':'Review the current Profile conversation when useful.');
    $historyFact=$conversation['status']==='resolved'?'The Profile conversation is marked resolved.':'The latest saved Profile message is from '.($source['sender_type']==='owner'?'you.':'the Profile Agent.');
    $report=vp3_profile_specialist_sections_v1([$settled?$historyFact:$label.' has an open Profile conversation.',$statement],
        $needsOwner?['The current visitor request still needs owner attention.']:[],
        $needsOwner?['The owner response to this visitor request is still unresolved.']:[],
        $next,$needsOwner?['Would you like to review this visitor request?']:[]);
    $semantic=[$source['source_hash'],$conversation['status'],$thread?[$thread['mode'],$thread['requested_mode'],$thread['live_status'],$thread['epoch']]:null,
        $turn?[(int)$turn['id'],(int)$turn['visitor_message_id'],$semanticState,(int)($turn['answer_message_id']??0),(int)($turn['last_owner_message_id']??0)]:null,
        $attention['semantic']??null,$needsOwner?null:($attention['read']??null)];
    $delivered=($attention['delivered']??false)&&($attention['source']['profile_event_id']??0)===$eventId;
    if($turn){
        $deliveries=vp3_coordination_profile_query_v1($pdo,'SELECT d.*,m.role,m.user_id,m.message,m.context_json,c.user_id chat_owner,c.user_agent_id FROM profile_agent_handoff_deliveries_pa3 d JOIN chat_messages m ON m.id=d.chat_message_id AND m.conversation_id=d.chat_conversation_id JOIN chat_conversations c ON c.id=d.chat_conversation_id WHERE d.owner_user_id=? AND d.turn_id=? ORDER BY d.created_at_ms DESC LIMIT 24',[$owner,(int)$turn['id']]);
        foreach($deliveries as $delivery){
            $marker=vp3_profile_attention_json_pa4b($delivery['context_json']??null);$marker=$marker['profile_handoff']??null;
            if($delivery['role']==='assistant'&&$delivery['user_id']===null&&(int)$delivery['chat_owner']===$owner
                &&(int)($delivery['user_agent_id']??0)===(int)$delivery['chat_agent_id']&&is_array($marker)
                &&($marker['contract']??null)===VP3_PROFILE_HANDOFF_PA3&&($marker['turn_id']??null)===(int)$turn['id']
                &&($marker['conversation_id']??null)===$id){
                vp3_profile_handoff_card_identity_pa3($pdo,$owner,(int)$delivery['chat_conversation_id'],(int)$delivery['chat_message_id'],(int)$turn['id'],$id);
                $delivered=true;break;
            }
        }
    }
    $callsAvailable=vp3_profile_call_schema_ready_pa3c($pdo);
    $liveCall=$callsAvailable?vp3_coordination_profile_query_v1($pdo,"SELECT id FROM profile_calls_pa3c WHERE owner_user_id=? AND conversation_id=? AND state IN ('offered','preparing','ready','active','ending') LIMIT 1",[$owner,$id]):[['unverified'=>true]];
    $activeAttention=$attention!==null&&!$attention['suppressed']&&(!$attention['read']||$attention['wake_due']||$needsOwner);
    $presentationOwner=$activeTurn?'profile_handoff_pa3':($liveCall?'profile_calls_pa3c':($activeAttention?($attention['presentation_owner']??'profile_attention_pa4b'):''));
    if(!$settled&&$presentationOwner==='')$presentationOwner=$attention['presentation_owner']??'profile_attention_pa4b';
    $historicalDelivery=$delivered;
    if($settled&&$presentationOwner==='')$delivered=false;
    require_once __DIR__.'/profile-commerce-followthrough-v1.php';
    try{$related=vp3_profile_commerce_conversation_associations_v1($pdo,$scope,$id,$now);}
    catch(Throwable $error){
        if(vp3_profile_analytics_retryable_v1($error)||$error->getCode()===403)throw $error;
        $related=['coverage'=>'unavailable','items'=>[]];
    }
    if($related['items']||$related['coverage']!=='unconfigured')$semantic[]=['related_product_marketing',$related];
    foreach($related['items'] as $link){
        if(count($report['key_findings'])>=6)break;
        $report['key_findings'][]=vp3_profile_specialist_text_v1('A saved Agent reply showed the promotion. Visitor question: “'.$link['question'].'”');
    }
    return vp3_coordination_profile_item_v1('conversation',$id,$title,$statement,$settled?($conversation['status']==='resolved'?'resolved':'updated'):($needsOwner?'needs_attention':'open'),
        $settled?'activity':($needsOwner?'needs_owner':'open_conversation'),$needsOwner?'high':($settled?'low':'normal'),vp3_profile_specialist_href_v1('/profile-agent.php?tab=inbox&conversation='.$id),
        $semantic,$report,$attention['disposition']??'pending',$presentationOwner,$delivered)+['profile_historical_delivery'=>$historicalDelivery,
            'related_product_marketing'=>$related['items'],'related_product_marketing_coverage'=>$related['coverage']];
}

function vp3_coordination_profile_call_v1(PDO $pdo,array $scope,int $id,DateTimeImmutable $now): ?array
{
    $owner=(int)$scope['user']['id'];$row=vp3_profile_call_row_pa3c($pdo,$id);
    if(!$row||(int)$row['owner_user_id']!==$owner)throw new RuntimeException('Profile call not found.',404);
    vp3_profile_call_integrity_pa3c($row);
    $context=vp3_profile_call_context_pa3c($pdo,$owner,(int)$row['conversation_id'],false,$row);
    $state=(string)$row['state'];
    if(!in_array($state,['offered','preparing','ready','active','ending','ended','declined','cancelled','expired','failed'],true)
        ||!in_array($row['mode'],['audio','video'],true)||!in_array($row['offered_by'],['visitor','owner'],true)||(int)$row['revision']<1)vp3_coordination_profile_changed_v1();
    $at=(int)$now->format('Uv');$missed=$row['offered_by']==='visitor'&&$row['accepted_at_ms']===null
        &&(int)$row['offer_expires_at_ms']<=$at&&($state==='offered'||($state==='expired'&&$row['last_reason']==='offer_expired'));
    if($missed){
        $newer=vp3_coordination_profile_query_v1($pdo,'SELECT id FROM profile_calls_pa3c WHERE owner_user_id=? AND conversation_id=? AND id>? LIMIT 1',[$owner,(int)$row['conversation_id'],$id]);
        if($newer||$context['conversation']['status']==='resolved')return null;
    }
    $mode=$row['mode']==='video'?'video':'voice';$title=$missed?'Missed Profile call':'Profile '.$mode.' call';
    $status=$missed?'missed':$state;
    $summary=$missed?'An incoming '.$mode.' call expired before it was answered.':match($state){
        'offered'=>'A '.$mode.' call offer is waiting on its existing call card.',
        'preparing'=>'A '.$mode.' call is preparing its connection.',
        'ready'=>'The '.$mode.' call connection has been prepared. Open its call card for current availability.',
        'active'=>'The saved call state reports an active '.$mode.' call.',
        'ending'=>'The '.$mode.' call is closing; verified cleanup is still pending.',
        'ended'=>'The '.$mode.' call has ended.',
        'declined'=>'The '.$mode.' call offer was declined.',
        'cancelled'=>'The '.$mode.' call was cancelled.',
        'expired'=>'The '.$mode.' call offer expired.',
        default=>'The '.$mode.' call needs review because it failed.',
    };
    $next=$missed?'Review the missed call in its original visitor conversation.':'';
    $report=vp3_profile_specialist_sections_v1([$summary],[],
        $missed?['Whether an owner follow-up is still needed is not established by this call record.']:[],
        $next,$missed?['Would you like to review the missed call?']:[]);
    $delivered=false;
    $receipts=vp3_coordination_profile_query_v1($pdo,'SELECT * FROM profile_call_deliveries_pa3c WHERE owner_user_id=? AND call_id=? ORDER BY created_at_ms DESC LIMIT 24',[$owner,$id]);
    foreach($receipts as $receipt){
        try{
            vp3_profile_handoff_owner_pa3($pdo,$scope['user'],(int)$receipt['chat_conversation_id'],(int)$receipt['chat_agent_id']);
            vp3_profile_call_delivery_check_pa3c($pdo,$row,$receipt);$delivered=true;break;
        }catch(VP3ProfileCallException|VP3ProfileHandoffException $error){if(!in_array($error->getCode(),[403,404,409],true))throw $error;}
    }
    return vp3_coordination_profile_item_v1('call',$id,$title,$summary,$status,$missed?'missed_call':'activity',$missed?'normal':'low',
        vp3_profile_specialist_href_v1('/profile-agent.php?tab=inbox&conversation='.(int)$row['conversation_id']),
        [$row['binding_hash'],(int)$row['revision'],$status,$row['accepted_at_ms'],$row['ended_at_ms'],$row['last_reason']],
        $report,'pending','profile_calls_pa3c',$delivered);
}

function vp3_coordination_profile_followups_ready_v1(PDO $pdo,array $scope): string
{
    if(!personal_capability_has_v242('agent_brain.access',$scope['user'])||!has_permission('artist_listening.access',$scope['user']))return 'unconfigured';
    require_once __DIR__.'/profile-call-activity-pa3c.php';
    require_once __DIR__.'/agent-memory-scope-v410.php';
    if(!vp3_profile_call_activity_schema_ready_pa3c($pdo)||!vp3_agent_memory_scope_schema_ready_v410($pdo)||!table_exists('artist_transcript_sessions_v172'))return 'unavailable';
    require_once __DIR__.'/agent-task-lists-v1.php';
    vp3_task_list_authorize_v1($pdo,$scope['user'],$scope['principal'],$scope['conversation_id']);
    return 'ready';
}

function vp3_coordination_profile_followup_candidates_v1(PDO $pdo,array $scope): array
{
    [$where,$args]=vp3_agent_memory_scope_sql_v410($scope['agent_id']);
    return vp3_coordination_profile_query_v1($pdo,"SELECT id FROM agent_memory_items WHERE user_id=? AND ".$where.
        " AND is_active=1 AND memory_type IN ('task','commitment') AND metadata_json LIKE ?
        AND EXISTS (SELECT 1 FROM profile_call_assistance_pa3c a WHERE a.transcript_session_id=
            CASE WHEN JSON_VALID(agent_memory_items.metadata_json) THEN JSON_EXTRACT(agent_memory_items.metadata_json,'$.source_session_id') ELSE NULL END)
        ORDER BY id DESC LIMIT 25",
        [(int)$scope['user']['id'],...$args,'%transcription_intelligence%']);
}

function vp3_coordination_profile_followup_v1(PDO $pdo,array $scope,int $id,DateTimeImmutable $now): ?array
{
    require_once __DIR__.'/transcript-agent-v1.php';
    $owner=(int)$scope['user']['id'];[$where,$args]=vp3_agent_memory_scope_sql_v410($scope['agent_id']);
    $rows=vp3_coordination_profile_query_v1($pdo,"SELECT * FROM agent_memory_items WHERE id=? AND user_id=? AND ".$where.
        " AND is_active=1 AND memory_type IN ('task','commitment')",[$id,$owner,...$args]);
    if(!$rows)throw new RuntimeException('Reviewed Profile follow-up not found.',404);
    $row=$rows[0];$meta=vp3_profile_attention_json_pa4b($row['metadata_json']??null);
    if(!is_array($meta)||($meta['source_kind']??null)!=='transcription_intelligence'||($meta['created_from_reviewed_item']??null)!==true
        ||!is_int($meta['source_session_id']??null)||$meta['source_session_id']<1||!is_string($meta['item_id']??null)||$meta['item_id']==='')vp3_coordination_profile_changed_v1();
    if(!in_array($meta['task_status']??null,['open','in_progress','waiting'],true))return null;
    if(!in_array((int)($meta['assigned_user_id']??0),[0,$owner],true)||!vp3_task_list_task_allowed_v1($pdo,$scope['user'],$scope['principal'],$id))throw new RuntimeException('Reviewed Profile follow-up access changed.',403);
    $sessionId=$meta['source_session_id'];$transcript=vp3_transcript_session_v1($pdo,$scope['user'],$scope['principal'],$sessionId);
    $origin=vp3_profile_attention_json_pa4b($transcript['metadata_json']??null);
    if(!empty($transcript['discarded_at'])||($origin['source']??null)!=='profile_call_pa3c'||!is_string($origin['profile_call_id']??null))vp3_coordination_profile_changed_v1();
    $calls=vp3_coordination_profile_query_v1($pdo,'SELECT c.* FROM profile_call_assistance_pa3c a JOIN profile_calls_pa3c c ON c.public_id=a.call_id WHERE a.transcript_session_id=? AND c.owner_user_id=? AND c.assisted=1 AND c.accepted_at_ms IS NOT NULL LIMIT 2',[$sessionId,$owner]);
    if(count($calls)!==1||!hash_equals((string)$calls[0]['public_id'],$origin['profile_call_id']))vp3_coordination_profile_changed_v1();
    $call=$calls[0];vp3_profile_call_integrity_pa3c($call);vp3_profile_call_context_pa3c($pdo,$owner,(int)$call['conversation_id'],false,$call);
    $title=vp3_profile_specialist_text_v1((string)$row['subject'],160)?:'Reviewed Profile call follow-up';
    $summary='A reviewed follow-up from an assisted Profile call is still '.$meta['task_status'].': '.$title;
    $next='Review the saved call follow-up and its transcript evidence.';
    $report=vp3_profile_specialist_sections_v1([$summary],[$title],[],$next,['Would you like to review this saved call follow-up?']);
    $stableRow=array_diff_key($row,array_fill_keys(['last_seen_at','use_count'],true));
    return vp3_coordination_profile_item_v1('followup',$id,$title,$summary,$meta['task_status'],'reviewed_followup','normal',
        vp3_profile_specialist_href_v1('/artist-listening.php?session='.$sessionId),[$stableRow,$transcript['metadata_json'],$transcript['conversation_id']??null,$call['binding_hash'],(int)$call['revision']],
        $report,'pending','office_tasks',false)+['profile_canonical_ref'=>'office:task:'.$id];
}
