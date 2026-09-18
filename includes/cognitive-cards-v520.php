<?php
declare(strict_types=1);

/**
 * VP3 Universal Display Cards v5.20 — Phase 11B.3.
 *
 * Card HTML is never model-generated. Every card request is reauthorized against
 * the canonical subsystem and rendered as normalized structured data.
 */
const VP3_COGNITIVE_CARDS_V520='vp3-cognitive-cards-v520-20260918';
const VP3_COGNITIVE_CARDS_BATCH_MAX_V520=20;

function vp3_cognitive_cards_types_v520(): array
{
    return [
        'transcription','recording','annotation','source','research','claim',
        'team_activity','human_message','profile_agent_update','contact',
        'commerce_order','commerce_customer','product','calendar_booking',
        'workflow','goal','commitment','opportunity','risk','decision',
        'knowledge','live_room','homeserver','browser_companion',
    ];
}

function vp3_cognitive_cards_ref_v520(string $type,string|int $id,string $scope='personal',array $extra=[]): array
{
    return vp3_cognitive_object_ref_v500($type,(string)$id,$scope,$extra);
}

function vp3_cognitive_cards_notification_v520(PDO $pdo,array $user,int $id): ?array
{
    if($id<1||!table_exists('notifications'))return null;
    $stmt=$pdo->prepare('SELECT * FROM notifications WHERE id=? AND user_id=? LIMIT 1');
    $stmt->execute([$id,(int)($user['id']??0)]);
    return $stmt->fetch()?:null;
}

function vp3_cognitive_cards_message_v520(PDO $pdo,array $user,int $id): ?array
{
    if($id<1||!table_exists('human_messages')||!function_exists('vp3_human_conversation_v370')||!function_exists('vp3_human_can_access_v370'))return null;
    $stmt=$pdo->prepare('SELECT m.*,u.display_name sender_name FROM human_messages m LEFT JOIN users u ON u.id=m.sender_user_id WHERE m.id=? LIMIT 1');
    $stmt->execute([$id]);$row=$stmt->fetch();
    if(!$row)return null;
    $conversation=vp3_human_conversation_v370($pdo,(int)$row['conversation_id']);
    if(!$conversation||!vp3_human_can_access_v370($pdo,$conversation,(int)$user['id']))return null;
    $row['_conversation']=$conversation;
    return $row;
}

function vp3_cognitive_cards_contact_v520(PDO $pdo,int $ownerUserId,int $contactId): ?array
{
    if($ownerUserId<1||$contactId<1||!table_exists('profile_visit_sessions'))return null;
    $stmt=$pdo->prepare(
        "SELECT s.*,
          COUNT(DISTINCT c.id) AS conversation_count,
          COUNT(DISTINCT CASE WHEN e.event_type='profile_view' THEN e.id END) AS visit_count,
          COUNT(DISTINCT CASE WHEN m.sender_type='visitor' THEN m.id END) AS visitor_message_count,
          MAX(c.last_message_at) AS conversation_last_at
         FROM profile_visit_sessions s
         LEFT JOIN profile_events e ON e.owner_user_id=s.owner_user_id AND e.profile_session_id=s.id
         LEFT JOIN profile_agent_conversations c ON c.owner_user_id=s.owner_user_id AND c.profile_session_id=s.id
         LEFT JOIN profile_agent_messages m ON m.conversation_id=c.id
         WHERE s.owner_user_id=? AND s.id=? AND (s.view_count>0 OR c.id IS NOT NULL)
         GROUP BY s.id LIMIT 1"
    );
    $stmt->execute([$ownerUserId,$contactId]);$row=$stmt->fetch();
    if(!$row)return null;
    $descriptor=function_exists('profile_runtime_visitor_descriptor')
        ? profile_runtime_visitor_descriptor($pdo,$ownerUserId,$row)
        : [];
    $row=array_merge($row,is_array($descriptor)?$descriptor:[]);
    $row['contact_id']=(int)$row['id'];
    $row['visit_count']=(int)($row['visit_count']??0);
    $row['page_view_count']=(int)($row['view_count']??0);
    $row['repeat_visitor']=$row['visit_count']>1;
    $row['conversation_count']=(int)($row['conversation_count']??0);
    $row['visitor_message_count']=(int)($row['visitor_message_count']??0);
    $row['stage']=$row['conversation_count']>0
        ? (!empty($row['signed_in'])?'member_engaged':'guest_engaged')
        : ($row['visit_count']>1?'returning_visitor':'new_visitor');
    unset($row['session_key'],$row['visitor_user_id'],$row['view_count']);
    return $row;
}

function vp3_cognitive_cards_observation_v520(PDO $pdo,array $user,string $namespace,string $type,string $id): ?array
{
    if(!vp3_cognitive_schema_ready_v500($pdo))return null;
    $categories=[
        'commitment'=>['commitment'],
        'opportunity'=>['opportunity','recommendation','action_plan'],
        'risk'=>['risk','anomaly','forecast'],
        'decision'=>['decision_support'],
    ];
    if(!isset($categories[$type]))return null;
    $sql=ctype_digit($id)
        ? 'SELECT * FROM cognitive_observations_v500 WHERE id=? AND owner_user_id=? AND agent_namespace=? AND state=\'active\' LIMIT 1'
        : 'SELECT * FROM cognitive_observations_v500 WHERE public_id=? AND owner_user_id=? AND agent_namespace=? AND state=\'active\' LIMIT 1';
    $stmt=$pdo->prepare($sql);$stmt->execute([$id,(int)$user['id'],$namespace]);$row=$stmt->fetch();
    if(!$row||!in_array((string)$row['category'],$categories[$type],true))return null;
    return $row;
}

function vp3_cognitive_cards_object_v520(PDO $pdo,array $user,string $namespace,array $ref): ?array
{
    $type=(string)($ref['type']??'');$id=trim((string)($ref['id']??''));$uid=(int)($user['id']??0);
    if($uid<1||$id==='')return null;
    try{
        if($type==='transcription'){
            if(!ctype_digit($id)||!function_exists('artist_listening_v172_session'))return null;
            $session=artist_listening_v172_session($pdo,$user,(int)$id);
            return ['type'=>$type,'row'=>$session];
        }
        if($type==='recording'){
            if(!function_exists('artist_listening_v172_session'))return null;
            $parts=explode(':',$id,2);$sessionId=(int)$parts[0];$key=trim((string)($parts[1]??''));
            if($sessionId<1)return null;
            $session=artist_listening_v172_session($pdo,$user,$sessionId);
            $recording=null;
            if(function_exists('artist_listening_v197_recordings')){
                foreach((array)artist_listening_v197_recordings($session) as $candidate){
                    if(!is_array($candidate))continue;
                    if($key===''||hash_equals((string)($candidate['key']??''),$key)){$recording=$candidate;break;}
                }
            }
            if($key!==''&&!$recording)return null;
            return ['type'=>$type,'row'=>$session,'recording'=>$recording,'recording_key'=>$key];
        }
        if($type==='annotation'){
            if(!function_exists('vp3_browser_share_resolve_v2020'))return null;
            $share=vp3_browser_share_resolve_v2020($pdo,$id,$uid);
            return $share?['type'=>$type,'row'=>$share]:null;
        }
        if($type==='source'){
            if(!function_exists('vp3_browser_source_row_by_public_id_v2050'))return null;
            $row=vp3_browser_source_row_by_public_id_v2050($pdo,$id);
            if(!$row||!function_exists('vp3_browser_trust_source_access_v2080')||!vp3_browser_trust_source_access_v2080($pdo,(int)$row['id'],$uid))return null;
            return ['type'=>$type,'row'=>$row];
        }
        if($type==='research'){
            if(!function_exists('vp3_research_project_require_v2060'))return null;
            $row=vp3_research_project_require_v2060($pdo,$id,$uid,'viewer',true);
            return $row?['type'=>$type,'row'=>$row]:null;
        }
        if($type==='claim'){
            if(!function_exists('vp3_browser_trust_claim_row_v2080')||!function_exists('vp3_browser_trust_claim_access_v2080'))return null;
            $row=vp3_browser_trust_claim_row_v2080($pdo,$id);
            if(!$row||!vp3_browser_trust_claim_access_v2080($pdo,$row,$uid))return null;
            return ['type'=>$type,'row'=>$row];
        }
        if($type==='team_activity'){
            if(ctype_digit($id)){
                $note=vp3_cognitive_cards_notification_v520($pdo,$user,(int)$id);
                if($note){
                    $kind=strtolower((string)($note['type']??''));
                    $source=strtolower((string)($note['source_type']??''));
                    if(str_contains($kind,'team')||str_contains($kind,'message')||str_contains($source,'team'))return ['type'=>$type,'row'=>$note,'kind'=>'notification'];
                }
                $message=vp3_cognitive_cards_message_v520($pdo,$user,(int)$id);
                if($message&&((string)($message['_conversation']['conversation_type']??'')==='team_general'))return ['type'=>$type,'row'=>$message,'kind'=>'message'];
            }
            return null;
        }
        if($type==='human_message'){
            if(!ctype_digit($id))return null;
            $row=vp3_cognitive_cards_message_v520($pdo,$user,(int)$id);
            return $row?['type'=>$type,'row'=>$row]:null;
        }
        if($type==='profile_agent_update'){
            if(!ctype_digit($id))return null;
            $row=vp3_cognitive_cards_notification_v520($pdo,$user,(int)$id);
            if(!$row)return null;
            $kind=strtolower((string)($row['type']??''));$source=strtolower((string)($row['source_type']??''));
            if($source!=='profile_event'&&!str_starts_with($kind,'profile_'))return null;
            return ['type'=>$type,'row'=>$row];
        }
        if($type==='contact'){
            if(!ctype_digit($id))return null;
            $row=vp3_cognitive_cards_contact_v520($pdo,$uid,(int)$id);
            return $row?['type'=>$type,'row'=>$row]:null;
        }
        if($type==='commerce_order'){
            if(!ctype_digit($id)||!function_exists('profile_commerce_order_for_owner_v900'))return null;
            $row=profile_commerce_order_for_owner_v900($pdo,$uid,(int)$id);
            return $row?['type'=>$type,'row'=>$row]:null;
        }
        if($type==='commerce_customer'){
            if(!function_exists('profile_commerce_orders_for_owner_v900'))return null;
            $needle=mb_strtolower($id);$matched=[];
            foreach((array)profile_commerce_orders_for_owner_v900($pdo,$uid,100) as $row){
                $values=[(string)($row['customer_email']??''),(string)($row['customer_name']??''),(string)($row['customer_id']??'')];
                if(in_array($needle,array_map('mb_strtolower',$values),true)||((string)($row['id']??'')===$id))$matched[]=$row;
            }
            return $matched?['type'=>$type,'row'=>$matched[0],'orders'=>$matched]:null;
        }
        if($type==='product'){
            if(!ctype_digit($id)||!function_exists('profile_commerce_owner_product_v900'))return null;
            $row=profile_commerce_owner_product_v900($pdo,$uid,(int)$id);
            return $row?['type'=>$type,'row'=>$row]:null;
        }
        if($type==='calendar_booking'){
            $kind='';$numeric=$id;
            if(str_contains($id,':'))[$kind,$numeric]=array_pad(explode(':',$id,2),2,'');
            if(!ctype_digit($numeric))return null;
            $numericId=(int)$numeric;
            if($kind!=='booking'){
                $event=function_exists('user_calendar_event_v1300')?user_calendar_event_v1300($pdo,$uid,$numericId):null;
                if($event)return ['type'=>$type,'row'=>$event,'kind'=>'calendar'];
                if($kind==='event')return null;
            }
            if($kind!=='event'&&table_exists('agent_scheduling_bookings')){
                $stmt=$pdo->prepare('SELECT * FROM agent_scheduling_bookings WHERE id=? AND owner_user_id=? LIMIT 1');
                $stmt->execute([$numericId,$uid]);$booking=$stmt->fetch();
                if($booking)return ['type'=>$type,'row'=>$booking,'kind'=>'booking'];
            }
            return null;
        }
        if($type==='workflow'){
            if(!ctype_digit($id)||!function_exists('agent_workflow_row_v1400'))return null;
            $row=agent_workflow_row_v1400($pdo,$uid,(int)$id);
            return $row?['type'=>$type,'row'=>$row]:null;
        }
        if($type==='goal'){
            if(!ctype_digit($id)||!function_exists('agent_goal_state_v1710'))return null;
            $state=agent_goal_state_v1710($pdo,$user,(int)$id);
            return $state?['type'=>$type,'row'=>$state]:null;
        }
        if(in_array($type,['commitment','opportunity','risk','decision'],true)){
            $row=vp3_cognitive_cards_observation_v520($pdo,$user,$namespace,$type,$id);
            return $row?['type'=>$type,'row'=>$row]:null;
        }
        if($type==='knowledge'){
            if(!ctype_digit($id)||!table_exists('knowledge_items'))return null;
            if(function_exists('personal_capability_has_v242')&&!personal_capability_has_v242('personal_knowledge.access',$user))return null;
            $stmt=$pdo->prepare("SELECT * FROM knowledge_items WHERE id=? AND created_by_user_id=? AND knowledge_scope='personal' LIMIT 1");
            $stmt->execute([(int)$id,$uid]);$row=$stmt->fetch();
            return $row?['type'=>$type,'row'=>$row]:null;
        }
        if($type==='live_room'){
            if(!function_exists('vp3_live_room_require_v2070'))return null;
            $row=vp3_live_room_require_v2070($pdo,$id,$uid,false);
            return $row?['type'=>$type,'row'=>$row]:null;
        }
        if($type==='homeserver'){
            if(!function_exists('homeserver_vp3_connection'))return null;
            if(!in_array($id,['self',(string)$uid],true))return null;
            $row=homeserver_vp3_connection($uid);
            return $row?['type'=>$type,'row'=>$row]:null;
        }
        if($type==='browser_companion'){
            if(!function_exists('vp3_extension_devices_for_user_v2000'))return null;
            $devices=vp3_extension_devices_for_user_v2000($pdo,$uid);
            if($id==='self'&&count($devices)===1)return ['type'=>$type,'row'=>$devices[0]];
            foreach($devices as $row)if(hash_equals((string)($row['public_id']??''),$id))return ['type'=>$type,'row'=>$row];
            return null;
        }
    }catch(Throwable $e){return null;}
    return null;
}

function vp3_cognitive_cards_permission_v520(PDO $pdo,array $user,string $namespace,array $ref,string $operation='read'): bool
{
    if($operation!=='read')return false;
    return vp3_cognitive_cards_object_v520($pdo,$user,$namespace,$ref)!==null;
}

function vp3_cognitive_cards_money_v520(mixed $minor,string $currency='USD'): string
{
    if(!is_numeric($minor))return '';
    $currency=strtoupper(trim($currency))?:'USD';
    return $currency.' '.number_format(((int)$minor)/100,2);
}

function vp3_cognitive_cards_fact_v520(string $label,mixed $value): array
{
    return ['label'=>vp3_cognitive_text_v500($label,80),'value'=>vp3_cognitive_text_v500($value,240)];
}

function vp3_cognitive_cards_action_v520(string $label,string $url): array
{
    return ['type'=>'open_url','label'=>$label,'url'=>$url];
}

function vp3_cognitive_cards_prompt_v520(string $label,string $prompt): array
{
    return ['type'=>'prompt','label'=>$label,'prompt'=>$prompt];
}

function vp3_cognitive_cards_card_v520(PDO $pdo,array $user,string $namespace,array $ref,string $mode): array
{
    $resolved=vp3_cognitive_cards_object_v520($pdo,$user,$namespace,$ref);
    if(!$resolved)throw new RuntimeException('Card object is unavailable.');
    $type=(string)$resolved['type'];$row=(array)$resolved['row'];
    $out=['title'=>'VP3 item','subtitle'=>'','status'=>'','summary'=>'','timestamp'=>'','badges'=>[],'facts'=>[],'sections'=>[],'media'=>[],'metadata'=>[],'actions'=>[]];

    if($type==='transcription'){
        $sid=(int)($row['id']??0);$out['title']=(string)($row['title']??'Transcription');$out['status']=(string)($row['status']??'');
        $out['subtitle']='Transcription';$out['timestamp']=(string)($row['created_at']??'');
        if(function_exists('artist_listening_v172_text'))$out['summary']=mb_strimwidth((string)artist_listening_v172_text($pdo,$sid),0,$mode==='expanded'?1200:520,'…');
        $out['facts'][]=vp3_cognitive_cards_fact_v520('Duration',isset($row['duration_ms'])?round(((int)$row['duration_ms'])/60000,1).' min':'');
        $out['actions'][]=vp3_cognitive_cards_action_v520('Open transcription','/artist-listening.php?session='.$sid);
    }elseif($type==='recording'){
        $rec=(array)($resolved['recording']??[]);$sid=(int)($row['id']??0);
        $out['title']=(string)($rec['name']??$row['title']??'Recording');$out['subtitle']='Recording';$out['status']=(string)($row['status']??'ready');$out['timestamp']=(string)($row['created_at']??'');
        $url=trim((string)($rec['url']??''));if($url!==''&&str_starts_with($url,'/')&&!str_starts_with($url,'//'))$out['media'][]=['kind'=>'audio','url'=>$url,'label'=>'Recording'];
        $out['actions'][]=vp3_cognitive_cards_action_v520('Open transcription','/artist-listening.php?session='.$sid);
    }elseif($type==='annotation'){
        $source=(array)($row['source']??[]);$out['title']=(string)($source['title']??$source['domain']??'Annotation');$out['subtitle']=(string)($source['domain']??'Web annotation');
        $selection=(string)($row['selection']??$row['selected_text']??'');$note=(string)($row['note']??'');
        $out['summary']=mb_strimwidth($note!==''?$note:$selection,0,$mode==='expanded'?1200:650,'…');$out['timestamp']=(string)($row['created_at']??'');
        $out['badges'][]=(string)($row['visibility']??'');
        $out['actions'][]=vp3_cognitive_cards_action_v520('Open annotation','/annotation.php?id='.rawurlencode((string)($row['id']??$ref['id'])));
    }elseif($type==='source'){
        // Source titles are intentionally not read from mutable global Source metadata.
        // That field may originate from a different viewer's personalized capture.
        $out['title']=(string)($row['source_domain']??'Source');$out['subtitle']='Source';
        $out['summary']=(string)($row['canonical_url']??$row['normalized_url']??'');$out['timestamp']=(string)($row['updated_at']??$row['created_at']??'');
        $out['actions'][]=vp3_cognitive_cards_action_v520('Open Source','/source.php?source='.rawurlencode((string)$row['public_id']));
    }elseif($type==='research'){
        $out['title']=(string)($row['title']??'Research');$out['subtitle']='Research project';$out['status']=(string)($row['project_status']??'active');$out['summary']=(string)($row['description']??'');$out['timestamp']=(string)($row['updated_at']??'');
        if(function_exists('vp3_research_project_items_v2060'))try{$items=vp3_research_project_items_v2060($pdo,$row,(int)$user['id']);$out['facts'][]=vp3_cognitive_cards_fact_v520('Sources',count($items));}catch(Throwable $e){}
        $out['actions'][]=vp3_cognitive_cards_action_v520('Open Research','/research-project.php?project='.rawurlencode((string)$row['public_id']));
    }elseif($type==='claim'){
        $public=function_exists('vp3_browser_trust_claim_public_v2080')?vp3_browser_trust_claim_public_v2080($pdo,$row,(int)$user['id'],false):$row;
        $out['title']=(string)($public['statement']??'Claim');$out['subtitle']='Claim';$out['status']=(string)($public['status']??$public['claim_status']??'');$out['summary']=(string)($public['rationale']??'');$out['timestamp']=(string)($public['updated_at']??$public['created_at']??'');
        $out['actions'][]=vp3_cognitive_cards_action_v520('Open claim','/claim.php?id='.rawurlencode((string)($public['id']??$row['public_id'])));
    }elseif($type==='team_activity'||$type==='human_message'){
        $isMessage=isset($row['conversation_id']);
        $out['title']=$isMessage?((string)($row['sender_name']??'Message')):(string)($row['title']??'Team activity');
        $out['subtitle']=$type==='team_activity'?'Team activity':'Message';$out['summary']=(string)($isMessage?($row['body']??''):($row['body']??''));$out['timestamp']=(string)($row['created_at']??'');
        $out['actions'][]=vp3_cognitive_cards_action_v520('Open Messages','/messages.php');
    }elseif($type==='profile_agent_update'){
        $out['title']=(string)($row['title']??'Profile Agent update');$out['subtitle']='Profile Agent';$out['summary']=(string)($row['body']??'');$out['timestamp']=(string)($row['created_at']??'');
        $target=trim((string)($row['target_url']??''));if($target!==''&&str_starts_with($target,'/')&&!str_starts_with($target,'//'))$out['actions'][]=vp3_cognitive_cards_action_v520('Open',$target);
        $out['actions'][]=vp3_cognitive_cards_action_v520('Open Profile Agent','/profile-agent.php');
    }elseif($type==='contact'){
        $out['title']=(string)($row['display_name']??$row['name']??'Contact');$out['subtitle']='Contact';$out['status']=(string)($row['relationship_stage']??$row['stage']??'');
        $out['summary']=(string)($row['company']??$row['email']??'');$out['timestamp']=(string)($row['last_seen_at']??$row['updated_at']??'');
        $out['facts'][]=vp3_cognitive_cards_fact_v520('Conversations',(int)($row['conversation_count']??0));$out['actions'][]=vp3_cognitive_cards_action_v520('Open Contacts','/contacts.php');
    }elseif($type==='commerce_order'){
        $out['title']='Order '.((string)($row['order_number']??('#'.(int)$row['id'])));$out['subtitle']='Commerce order';$out['status']=(string)($row['status']??$row['payment_status']??'');
        $out['summary']=(string)($row['customer_name']??$row['customer_email']??'');$out['timestamp']=(string)($row['created_at']??'');
        $total=$row['total_minor']??$row['amount_minor']??null;if($total!==null)$out['facts'][]=vp3_cognitive_cards_fact_v520('Total',vp3_cognitive_cards_money_v520($total,(string)($row['currency']??'USD')));
        $out['actions'][]=vp3_cognitive_cards_action_v520('Open Commerce','/commerce.php');
    }elseif($type==='commerce_customer'){
        $orders=(array)($resolved['orders']??[]);$out['title']=(string)($row['customer_name']??$row['customer_email']??'Customer');$out['subtitle']='Commerce customer';$out['summary']=(string)($row['customer_email']??'');
        $out['facts'][]=vp3_cognitive_cards_fact_v520('Orders',count($orders));$out['timestamp']=(string)($row['created_at']??'');$out['actions'][]=vp3_cognitive_cards_action_v520('Open Commerce','/commerce.php');
    }elseif($type==='product'){
        $out['title']=(string)($row['title']??$row['name']??'Product');$out['subtitle']='Product';$out['status']=(string)($row['status']??'');
        $price=$row['price_minor']??$row['unit_amount_minor']??null;if($price!==null)$out['facts'][]=vp3_cognitive_cards_fact_v520('Price',vp3_cognitive_cards_money_v520($price,(string)($row['currency']??'USD')));
        $out['summary']=(string)($row['description']??'');$out['actions'][]=vp3_cognitive_cards_action_v520('Open Commerce','/ecommerce.php');
    }elseif($type==='calendar_booking'){
        $out['title']=(string)($row['title']??$row['service_name']??'Calendar booking');$out['subtitle']=($resolved['kind']??'')==='booking'?'Booking':'Calendar';$out['status']=(string)($row['status']??'active');
        $out['summary']=(string)($row['description']??$row['location']??'');$out['timestamp']=(string)($row['start_at_utc']??'');
        $out['facts'][]=vp3_cognitive_cards_fact_v520('Starts',(string)($row['start_at_utc']??''));$out['actions'][]=vp3_cognitive_cards_action_v520('Open Calendar','/calendar.php');
    }elseif($type==='workflow'){
        $public=function_exists('agent_workflow_public_run_v1400')?agent_workflow_public_run_v1400($pdo,$row,false):$row;
        $out['title']=(string)($public['title']??'Workflow');$out['subtitle']='Agent workflow';$out['status']=(string)($public['status']??'');$out['summary']=(string)($public['goal']??$public['progress_message']??'');$out['timestamp']=(string)($public['updated_at']??'');
        $out['actions'][]=vp3_cognitive_cards_action_v520('Open workflow','/agent-workflows.php?run='.(int)$row['id']);
        $out['actions'][]=vp3_cognitive_cards_prompt_v520('Ask Agent','Review workflow #'.(int)$row['id'].' and tell me the safest next action.');
    }elseif($type==='goal'){
        $goal=(array)($row['goal']??[]);$out['title']=(string)($goal['title']??'Goal');$out['subtitle']='Goal';$out['status']=(string)($goal['derived_status']??$goal['status']??'');
        $out['summary']=(string)($goal['goal']??'');$out['facts'][]=vp3_cognitive_cards_fact_v520('Progress',((int)($goal['progress_percent']??0)).'%');$out['facts'][]=vp3_cognitive_cards_fact_v520('Priority',(int)($goal['priority']??0));
        $out['actions'][]=vp3_cognitive_cards_prompt_v520('Ask Agent','Show me the next safe step for goal #'.(int)($goal['id']??0).'.');
    }elseif(in_array($type,['commitment','opportunity','risk','decision'],true)){
        $out['title']=(string)($row['title']??ucfirst($type));$out['subtitle']=ucfirst($type);$out['status']=(string)($row['state']??'active');$out['summary']=(string)($row['reason']??'');$out['timestamp']=(string)($row['updated_at']??'');
        $out['facts'][]=vp3_cognitive_cards_fact_v520('Confidence',round(((float)($row['confidence']??0))*100).'%');$out['facts'][]=vp3_cognitive_cards_fact_v520('Impact',round(((float)($row['impact']??0))*100).'%');
        $out['actions'][]=vp3_cognitive_cards_prompt_v520('Discuss','Help me evaluate this '.$type.': '.(string)($row['title']??''));
    }elseif($type==='knowledge'){
        $out['title']=(string)($row['title']??'Knowledge');$out['subtitle']='Knowledge';$out['status']=(string)($row['content_type']??'');
        $out['summary']=mb_strimwidth((string)($row['description']??$row['content_text']??''),0,$mode==='expanded'?1200:600,'…');$out['timestamp']=(string)($row['updated_at']??$row['created_at']??'');
        $out['actions'][]=vp3_cognitive_cards_action_v520('Open Knowledge','/knowledge.php?edit='.(int)$row['id']);
    }elseif($type==='live_room'){
        $public=function_exists('vp3_live_room_public_v2070')?vp3_live_room_public_v2070($pdo,$row,(int)$user['id'],true):$row;
        $out['title']=(string)($public['title']??'Live Room');$out['subtitle']='Live Room';$out['status']=(string)($public['status']??'');$out['summary']=(string)($public['description']??'');$out['timestamp']=(string)($public['updated_at']??$public['created_at']??'');
        $out['facts'][]=vp3_cognitive_cards_fact_v520('Participants',count((array)($public['participants']??[])));$out['actions'][]=vp3_cognitive_cards_action_v520('Open Live Room','/live-room.php?room='.rawurlencode((string)$row['public_id']));
    }elseif($type==='homeserver'){
        $out['title']=(string)($row['device_name']??$row['name']??'VP3 HomeServer');$out['subtitle']='HomeServer';$out['status']=(string)($row['status']??$row['connection_status']??'connected');
        $out['summary']='Private/local Agent compute and knowledge connection.';$out['timestamp']=(string)($row['last_seen_at']??$row['updated_at']??'');$out['actions'][]=vp3_cognitive_cards_action_v520('Manage HomeServer','/account.php#homeserver');
    }elseif($type==='browser_companion'){
        $out['title']=(string)($row['device_name']??'Browser Companion');$out['subtitle']=(string)($row['browser_family']??'Browser Companion');$out['status']=(string)($row['device_status']??'');
        $out['summary']='VP3 Browser Companion · version '.(string)($row['extension_version']??'unknown');$out['timestamp']=(string)($row['last_used_at']??$row['approved_at']??'');
        $out['actions'][]=vp3_cognitive_cards_action_v520('Manage Browser Companion','/account.php#browser-companion');
    }

    $out['metadata']['renderer_build']=VP3_COGNITIVE_CARDS_V520;
    return $out;
}

function vp3_cognitive_cards_context_v520(PDO $pdo,array $user,string $namespace,array $ref,array $options=[]): array
{
    $card=vp3_cognitive_cards_card_v520($pdo,$user,$namespace,$ref,'compact');
    unset($card['actions'],$card['media']);
    return ['display_card'=>$card];
}

function vp3_cognitive_cards_notification_request_v520(array $row): ?array
{
    $id=max(0,(int)($row['id']??0));$sourceId=max(0,(int)($row['source_id']??0));
    $type=strtolower(trim((string)($row['type']??'')));$source=strtolower(trim((string)($row['source_type']??'')));
    $cardType='';$objectId='';
    if($source==='profile_event'||str_starts_with($type,'profile_')){$cardType='profile_agent_update';$objectId=(string)$id;}
    elseif(str_contains($type,'team')||in_array($type,['direct_message','team_chat_message'],true)){$cardType='team_activity';$objectId=(string)$id;}
    elseif($sourceId>0&&preg_match('/meeting/',$type.' '.$source)){$cardType='meeting';$objectId=(string)$sourceId;}
    elseif($sourceId>0&&preg_match('/booking|appointment|calendar/',$type.' '.$source)){$cardType='calendar_booking';$objectId=(string)$sourceId;}
    elseif($sourceId>0&&preg_match('/workflow|approval/',$type.' '.$source)){$cardType='workflow';$objectId=(string)$sourceId;}
    elseif($sourceId>0&&preg_match('/order|payment|refund|commerce/',$type.' '.$source)){$cardType='commerce_order';$objectId=(string)$sourceId;}
    if($cardType===''||$objectId==='')return null;
    return ['card_type'=>$cardType,'object_ref'=>vp3_cognitive_cards_ref_v520($cardType,$objectId),'display_mode'=>'compact'];
}


function vp3_cognitive_cards_chat_intent_v520(string $query): string
{
    $q=mb_strtolower(trim($query));
    if($q===''||!preg_match('/\b(?:show|list|find|open|display|what|which|latest|recent|upcoming|my)\b/u',$q))return '';
    $map=[
        'recording'=>'/\b(?:recording|recordings|audio recordings?)\b/u',
        'transcription'=>'/\b(?:transcript|transcripts|transcription|transcriptions)\b/u',
        'calendar_booking'=>'/\b(?:booking|bookings|appointment|appointments|calendar|schedule|scheduled)\b/u',
        'workflow'=>'/\b(?:workflow|workflows|agent work|work queue)\b/u',
        'goal'=>'/\b(?:goal|goals|objectives?)\b/u',
        'research'=>'/\b(?:research|research projects?)\b/u',
        'commerce_order'=>'/\b(?:order|orders|purchases?|commerce)\b/u',
        'claim'=>'/\b(?:claim|claims)\b/u',
        'annotation'=>'/\b(?:annotation|annotations|web captures?|browser shares?)\b/u',
        'live_room'=>'/\b(?:live room|live rooms)\b/u',
        'knowledge'=>'/\b(?:knowledge|knowledge items?|saved knowledge)\b/u',
        'contact'=>'/\b(?:contact|contacts|relationships?)\b/u',
        'browser_companion'=>'/\b(?:browser companion|browser extension|connected browsers?|extension devices?)\b/u',
        'homeserver'=>'/\b(?:homeserver|home server|local agent)\b/u',
        'product'=>'/\b(?:product|products|store items?)\b/u',
    ];
    foreach($map as $type=>$pattern)if(preg_match($pattern,$q))return $type;
    return '';
}

function vp3_cognitive_cards_chat_requests_v520(PDO $pdo,array $user,string $namespace,string $query,int $limit=8): array
{
    $type=vp3_cognitive_cards_chat_intent_v520($query);
    if($type==='')return [];
    $uid=(int)($user['id']??0);if($uid<1)return [];
    $limit=max(1,min(12,$limit));$refs=[];
    $add=static function(string $cardType,string|int $id,string $scope='personal') use(&$refs,$limit): void {
        if(count($refs)>=$limit)return;
        $key=$cardType.':'.(string)$id;
        if(isset($refs[$key]))return;
        $refs[$key]=[
            'card_type'=>$cardType,
            'object_ref'=>vp3_cognitive_cards_ref_v520($cardType,$id,$scope),
            'display_mode'=>'standard',
        ];
    };

    try{
        if($type==='transcription'||$type==='recording'){
            foreach((array)(function_exists('artist_listening_v172_list')?artist_listening_v172_list($user,$limit):[]) as $session){
                $sid=(int)($session['id']??0);if($sid<1)continue;
                if($type==='transcription'){$add('transcription',$sid);continue;}
                foreach((array)($session['recordings']??[]) as $recording){
                    $key=trim((string)($recording['key']??''));if($key!=='')$add('recording',$sid.':'.$key);
                }
            }
        }elseif($type==='calendar_booking'){
            $from=gmdate('Y-m-d H:i:s',time()-86400);
            $to=gmdate('Y-m-d H:i:s',time()+180*86400);
            foreach((array)(function_exists('user_calendar_events_v1300')?user_calendar_events_v1300($pdo,$user,$from,$to):[]) as $event){
                $eid=(int)($event['id']??0);if($eid<1)continue;
                $kind=(string)($event['kind']??'calendar');
                $add('calendar_booking',($kind==='booking'?'booking:':'event:').$eid);
            }
        }elseif($type==='workflow'&&table_exists('agent_workflow_runs')){
            $stmt=$pdo->prepare("SELECT id FROM agent_workflow_runs WHERE owner_user_id=? AND status<>'cancelled' ORDER BY updated_at DESC,id DESC LIMIT {$limit}");
            $stmt->execute([$uid]);foreach($stmt->fetchAll()?:[] as $row)$add('workflow',(int)$row['id']);
        }elseif($type==='goal'&&function_exists('agent_goal_list_v1710')){
            foreach(array_slice(agent_goal_list_v1710($pdo,$user,false),0,$limit) as $state){
                $gid=(int)($state['goal']['id']??0);if($gid>0)$add('goal',$gid);
            }
        }elseif($type==='research'&&function_exists('vp3_research_projects_for_user_v2060')){
            foreach(array_slice(vp3_research_projects_for_user_v2060($pdo,$uid,false),0,$limit) as $project){
                $id=trim((string)($project['id']??''));if($id!=='')$add('research',$id,(int)($project['team_id']??0)>0?'team':'personal');
            }
        }elseif($type==='commerce_order'&&function_exists('profile_commerce_orders_for_owner_v900')){
            foreach(array_slice(profile_commerce_orders_for_owner_v900($pdo,$uid,$limit),0,$limit) as $order){
                if((int)($order['id']??0)>0)$add('commerce_order',(int)$order['id']);
            }
        }elseif($type==='claim'&&function_exists('vp3_browser_trust_user_claims_v2080')){
            foreach(array_slice(vp3_browser_trust_user_claims_v2080($pdo,$uid,$limit),0,$limit) as $claim){
                $id=trim((string)($claim['id']??$claim['public_id']??''));if($id!=='')$add('claim',$id);
            }
        }elseif($type==='annotation'&&table_exists('browser_shares_v2010')){
            $stmt=$pdo->prepare("SELECT public_id FROM browser_shares_v2010 WHERE sender_user_id=? AND deleted_at IS NULL ORDER BY created_at DESC,id DESC LIMIT {$limit}");
            $stmt->execute([$uid]);foreach($stmt->fetchAll()?:[] as $row)$add('annotation',(string)$row['public_id']);
        }elseif($type==='live_room'&&function_exists('vp3_live_room_list_v2070')){
            $payload=vp3_live_room_list_v2070($pdo,$uid,$limit);
            foreach(array_slice((array)($payload['rooms']??$payload),0,$limit) as $room){
                $id=trim((string)($room['id']??$room['public_id']??''));if($id!=='')$add('live_room',$id);
            }
        }elseif($type==='knowledge'&&table_exists('knowledge_items')){
            if(!function_exists('personal_capability_has_v242')||personal_capability_has_v242('personal_knowledge.access',$user)){
                $stmt=$pdo->prepare("SELECT id FROM knowledge_items WHERE created_by_user_id=? AND knowledge_scope='personal' ORDER BY updated_at DESC,id DESC LIMIT {$limit}");
                $stmt->execute([$uid]);foreach($stmt->fetchAll()?:[] as $row)$add('knowledge',(int)$row['id']);
            }
        }elseif($type==='contact'&&function_exists('profile_visitor_contact_list_v243')){
            foreach(array_slice(profile_visitor_contact_list_v243($pdo,$uid,$limit),0,$limit) as $contact){
                if((int)($contact['contact_id']??0)>0)$add('contact',(int)$contact['contact_id']);
            }
        }elseif($type==='browser_companion'&&function_exists('vp3_extension_devices_for_user_v2000')){
            foreach(array_slice(vp3_extension_devices_for_user_v2000($pdo,$uid),0,$limit) as $device){
                $id=trim((string)($device['public_id']??''));if($id!=='')$add('browser_companion',$id);
            }
        }elseif($type==='homeserver'){
            if(function_exists('homeserver_vp3_connection')&&homeserver_vp3_connection($uid))$add('homeserver','self');
        }elseif($type==='product'&&table_exists('agent_commerce_products_v800')){
            $stmt=$pdo->prepare("SELECT id FROM agent_commerce_products_v800 WHERE owner_user_id=? ORDER BY updated_at DESC,id DESC LIMIT {$limit}");
            $stmt->execute([$uid]);foreach($stmt->fetchAll()?:[] as $row)$add('product',(int)$row['id']);
        }
    }catch(Throwable $e){return [];}

    // A direct Chat request still never trusts the list query as final authority:
    // each returned card will be reauthorized again by vp3_cognitive_render_card_v500.
    return array_values($refs);
}

function vp3_cognitive_register_cards_v520(): void
{
    $registry=vp3_cognitive_registry_storage_v500();
    if(isset($registry['modules']['universal_cards']))return;
    $cards=[];foreach(vp3_cognitive_cards_types_v520() as $type)$cards[$type]='vp3_cognitive_cards_card_v520';
    vp3_cognitive_register_module_v500([
        'module'=>'universal_cards',
        'version'=>'universal-display-cards-v520',
        'objects'=>vp3_cognitive_cards_types_v520(),
        'events'=>[],
        'permission_resolver'=>'vp3_cognitive_cards_permission_v520',
        'context_provider'=>'vp3_cognitive_cards_context_v520',
        'relationship_provider'=>null,
        'cards'=>$cards,
        'tools'=>[],
        'freshness_policy'=>['default_seconds'=>60],
        'sensitivity_policy'=>['reauthorize_each_render'=>true],
        'surfaces'=>['brief','away_digest','notification','ask_user','chat_response'],
        'voice_safe'=>false,
    ]);
}

vp3_cognitive_register_cards_v520();
