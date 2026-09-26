<?php
declare(strict_types=1);

/**
 * Tracky V2.72 — Cross-Surface Physical Awareness & Proactive Agent Integration.
 *
 * Governed Tracky events are projected into the existing VP3 Cognitive Feed,
 * attention, notification and voice authorities. This layer publishes automation
 * trigger metadata but never executes physical actions.
 */
const VP3_TRACKY_PROACTIVE_V272='vp3-tracky-proactive-v272-20260926';
const VP3_TRACKY_PROACTIVE_CONTRACT_V272='tracky-cross-surface-v1';
const VP3_TRACKY_PROACTIVE_MAX_SYNC_EVENTS_V272=12;
const VP3_TRACKY_PROACTIVE_EVENT_WINDOW_HOURS_V272=24;

function tracky_v272_event_classes(): array
{
    return ['presence','object','environment','routine','health','safety'];
}

function tracky_v272_settings_defaults(): array
{
    return [
        'now_enabled'=>true,
        'chat_enabled'=>true,
        'voice_enabled'=>false,
        'automation_enabled'=>true,
        'cross_plugin_access'=>false,
        'cross_plugin_plugins'=>[],
        'now_classes'=>['object','environment','routine','health','safety'],
        'chat_classes'=>['health','safety'],
        'voice_classes'=>['health','safety'],
        'automation_classes'=>['presence','object','environment','routine','health','safety'],
    ];
}

function tracky_v272_settings_normalize(array $input): array
{
    $defaults=tracky_v272_settings_defaults();$out=$defaults;
    foreach(['now_enabled','chat_enabled','voice_enabled','automation_enabled','cross_plugin_access'] as $key){
        if(array_key_exists($key,$input))$out[$key]=(bool)$input[$key];
    }
    $allowed=tracky_v272_event_classes();
    foreach(['now_classes','chat_classes','voice_classes','automation_classes'] as $key){
        if(!array_key_exists($key,$input))continue;
        $values=is_array($input[$key])?$input[$key]:[];
        $out[$key]=array_values(array_unique(array_intersect($allowed,array_map(
            static fn($v)=>strtolower(trim((string)$v)),$values
        ))));
    }
    if(array_key_exists('cross_plugin_plugins',$input)){
        $plugins=is_array($input['cross_plugin_plugins'])?$input['cross_plugin_plugins']:[];
        $out['cross_plugin_plugins']=array_values(array_unique(array_filter(array_map(
            static fn($v)=>strtolower(trim((string)$v)),$plugins
        ),static fn($v)=>$v!=='tracky'&&(bool)preg_match('/^[a-z0-9_]{2,80}$/',$v))));
    }
    return $out;
}

function tracky_v272_settings(PDO $pdo,int $userId): array
{
    if($userId<1||!function_exists('vp3_plugin_installation_v320'))return tracky_v272_settings_defaults();
    $row=vp3_plugin_installation_v320($pdo,$userId,'tracky');
    $stored=json_decode((string)($row['settings_json']??''),true);
    return tracky_v272_settings_normalize(is_array($stored)?$stored:[]);
}

function tracky_v272_settings_save(PDO $pdo,array $user,array $input): array
{
    $uid=(int)($user['id']??0);
    if($uid<1)throw new RuntimeException('Sign in to manage Tracky.');
    if(!function_exists('vp3_plugin_effective_enabled_v360')||!vp3_plugin_effective_enabled_v360($pdo,$user,'tracky')){
        throw new RuntimeException('Enable Tracky before changing physical-awareness surfaces.');
    }
    if(!vp3_plugin_schema_ready_v320($pdo))vp3_plugin_ensure_schema_v320($pdo);
    if(!vp3_plugin_installation_v320($pdo,$uid,'tracky'))vp3_plugin_set_enabled_v320($pdo,$uid,'tracky',true);
    $settings=tracky_v272_settings_normalize($input);
    $settings['cross_plugin_plugins']=array_values(array_filter((array)$settings['cross_plugin_plugins'],static function($plugin) use($pdo,$user): bool {
        return vp3_plugin_valid_v320((string)$plugin)
            &&function_exists('vp3_plugin_effective_enabled_v360')
            &&vp3_plugin_effective_enabled_v360($pdo,$user,(string)$plugin);
    }));
    $json=json_encode($settings,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if(!is_string($json))throw new RuntimeException('Tracky surface settings could not be encoded.');
    $stmt=$pdo->prepare("UPDATE user_plugin_installations SET settings_json=?,updated_at=UTC_TIMESTAMP() WHERE user_id=? AND plugin_key='tracky'");
    $stmt->execute([$json,$uid]);
    return $settings;
}

function tracky_v272_event_class(string $eventType): string
{
    $type=strtolower(trim($eventType));
    if(str_starts_with($type,'safety.'))return 'safety';
    if(str_starts_with($type,'camera.')||str_starts_with($type,'sensor.')||str_starts_with($type,'tracky.')
        ||str_contains($type,'health')||str_contains($type,'disconnect')||str_contains($type,'degraded'))return 'health';
    if(str_starts_with($type,'object.'))return 'object';
    if(str_starts_with($type,'environment.'))return 'environment';
    if(str_starts_with($type,'routine.')||str_starts_with($type,'gesture.'))return 'routine';
    return 'presence';
}

function tracky_v272_surface_allowed(array $settings,string $surface,string $eventClass): bool
{
    $eventClass=strtolower(trim($eventClass));
    if(!in_array($eventClass,tracky_v272_event_classes(),true))return false;
    $enabledKey=match($surface){
        'now'=>'now_enabled','chat'=>'chat_enabled','voice'=>'voice_enabled','automation'=>'automation_enabled',
        default=>'',
    };
    $classesKey=match($surface){
        'now'=>'now_classes','chat'=>'chat_classes','voice'=>'voice_classes','automation'=>'automation_classes',
        default=>'',
    };
    return $enabledKey!==''&&!empty($settings[$enabledKey])&&in_array($eventClass,(array)($settings[$classesKey]??[]),true);
}

function tracky_v272_event_row(PDO $pdo,int $userId,string $siteId,string $eventId): ?array
{
    if($userId<1||$eventId===''||!tracky_cloud_v270_schema_ready($pdo))return null;
    $stmt=$pdo->prepare("SELECT id,user_id,site_id,event_id,sequence_no,event_type,severity,confidence,privacy_class,occurred_at,is_fresh,projected,event_json
      FROM tracky_cloud_events WHERE user_id=? AND site_id=? AND event_id=? LIMIT 1");
    $stmt->execute([$userId,$siteId,$eventId]);$row=$stmt->fetch();if(!$row)return null;
    $event=json_decode((string)$row['event_json'],true);$row['event']=is_array($event)?$event:[];
    unset($row['event_json']);return $row;
}

function tracky_v272_event_row_by_numeric_id(PDO $pdo,int $userId,int $id): ?array
{
    if($userId<1||$id<1||!tracky_cloud_v270_schema_ready($pdo))return null;
    $stmt=$pdo->prepare("SELECT id,user_id,site_id,event_id,sequence_no,event_type,severity,confidence,privacy_class,occurred_at,is_fresh,projected,event_json
      FROM tracky_cloud_events WHERE user_id=? AND id=? LIMIT 1");
    $stmt->execute([$userId,$id]);$row=$stmt->fetch();if(!$row)return null;
    $event=json_decode((string)$row['event_json'],true);$row['event']=is_array($event)?$event:[];
    unset($row['event_json']);return $row;
}

function tracky_v272_site_label(PDO $pdo,int $userId,string $siteId): string
{
    $site=tracky_cloud_v270_site_status($pdo,$userId,$siteId);
    $label=trim((string)($site['label']??''));
    return $label!==''?$label:tracky_agent_entity_label_v271($siteId);
}

function tracky_v272_safe_summary(array $row): string
{
    $event=is_array($row['event']??null)?$row['event']:[];
    $type=(string)($row['event_type']??$event['event_type']??'physical_context.changed');
    $class=tracky_v272_event_class($type);
    $summary=trim((string)($event['summary']??''));
    $room=trim((string)($event['room_id']??''));
    $roomLabel=$room!==''?tracky_agent_entity_label_v271($room):'';
    if($class==='safety'){
        $safe=$summary!==''?$summary:str_replace(['.','_'],' ',$type);
        return mb_strimwidth('Tracky reported a possible safety condition'.($roomLabel!==''?' in '.$roomLabel:'').': '.$safe,0,420,'…');
    }
    if($summary!=='')return mb_strimwidth($summary,0,420,'…');
    $subject=is_array($event['subject']??null)?tracky_agent_entity_label_v271((string)($event['subject']['entity_id']??'')):'';
    $label=trim(str_replace(['.','_'],' ',$type));
    $text=ucfirst($label);
    if($subject!==''&&$subject!=='Unknown')$text.=' · '.$subject;
    if($roomLabel!=='')$text.=' · '.$roomLabel;
    return mb_strimwidth($text,0,420,'…');
}

function tracky_v272_event_title(array $row): string
{
    $class=tracky_v272_event_class((string)($row['event_type']??''));
    $severity=(string)($row['severity']??'informational');
    return match($class){
        'safety'=>'Possible physical safety condition',
        'health'=>in_array($severity,['urgent','actionable','system-health'],true)?'Tracky needs attention':'Tracky health update',
        'object'=>'Physical object update',
        'environment'=>'Environment changed',
        'routine'=>'Routine or behavior change',
        default=>'Physical presence update',
    };
}

function tracky_v272_event_score(array $row): array
{
    $class=tracky_v272_event_class((string)($row['event_type']??''));
    $severity=strtolower((string)($row['severity']??'informational'));
    $confidence=max(0.0,min(1.0,(float)($row['confidence']??0)));
    $base=match($severity){
        'urgent'=>98,'system-health'=>91,'actionable'=>89,'notable'=>72,default=>52,
    };
    if($class==='safety')$base=max($base,96);
    if($class==='health')$base=max($base,$severity==='informational'?65:88);
    $attention=$class==='safety'||in_array($severity,['urgent','actionable','system-health'],true);
    return [
        'score'=>min(100,$base+($confidence>=.90?2:0)),
        'attention'=>$attention,
        'urgency'=>$class==='safety'?0.98:match($severity){'urgent'=>.96,'system-health'=>.90,'actionable'=>.86,'notable'=>.58,default=>.25},
        'impact'=>$class==='safety'?0.90:($class==='health'?0.76:($attention?.68:.42)),
        'novelty'=>in_array($severity,['urgent','notable','actionable','system-health'],true)?.78:.45,
        'goal_relevance'=>$class==='health'?.75:.42,
    ];
}

function tracky_v272_trigger_catalog(): array
{
    return [
        'tracky.room.entered'=>['event_types'=>['room.entered'],'class'=>'presence'],
        'tracky.room.exited'=>['event_types'=>['room.exited'],'class'=>'presence'],
        'tracky.room.occupancy_changed'=>['event_types'=>['room.occupancy_changed'],'class'=>'presence'],
        'tracky.person.arrived'=>['event_types'=>['person.detected','person.identified'],'class'=>'presence'],
        'tracky.person.left'=>['event_types'=>['person.left'],'class'=>'presence'],
        'tracky.object.moved'=>['event_types'=>['object.moved'],'class'=>'object'],
        'tracky.object.state_changed'=>['event_types'=>['object.state_changed'],'class'=>'object'],
        'tracky.environment.changed'=>['event_types'=>['environment.changed','environment.drift_detected'],'class'=>'environment'],
        'tracky.routine.deviation'=>['event_types'=>['routine.deviation'],'class'=>'routine'],
        'tracky.health.changed'=>['event_prefixes'=>['camera.','sensor.','tracky.'],'class'=>'health'],
        'tracky.safety.possible_hazard'=>['event_prefixes'=>['safety.'],'class'=>'safety'],
    ];
}

function tracky_v272_trigger_for_event(array $row): ?array
{
    $type=strtolower((string)($row['event_type']??''));
    foreach(tracky_v272_trigger_catalog() as $trigger=>$meta){
        if(in_array($type,(array)($meta['event_types']??[]),true))return ['trigger'=>$trigger]+$meta;
        foreach((array)($meta['event_prefixes']??[]) as $prefix){
            if(str_starts_with($type,(string)$prefix))return ['trigger'=>$trigger]+$meta;
        }
    }
    return null;
}

function tracky_v272_trigger_payload(array $row): ?array
{
    $mapping=tracky_v272_trigger_for_event($row);if(!$mapping)return null;
    $event=is_array($row['event']??null)?$row['event']:[];
    $payload=[
        'contract'=>VP3_TRACKY_PROACTIVE_CONTRACT_V272,
        'trigger'=>(string)$mapping['trigger'],
        'event_id'=>(string)($row['event_id']??''),
        'event_type'=>(string)($row['event_type']??''),
        'event_class'=>tracky_v272_event_class((string)($row['event_type']??'')),
        'site_id'=>(string)($row['site_id']??''),
        'room_id'=>(string)($event['room_id']??''),
        'confidence'=>(float)($row['confidence']??0),
        'occurred_at'=>(string)($row['occurred_at']??''),
    ];
    foreach(['subject','object'] as $key){
        $ref=is_array($event[$key]??null)?$event[$key]:[];
        $id=trim((string)($ref['entity_id']??''));$type=trim((string)($ref['type']??''));
        if($id!==''&&$type!=='')$payload[$key]=['entity_id'=>$id,'type'=>$type];
    }
    return $payload;
}

function tracky_v272_automation_triggers(PDO $pdo,array $user,int $limit=30): array
{
    $uid=(int)($user['id']??0);if($uid<1||!tracky_agent_enabled_v271($pdo,$user))return [];
    $settings=tracky_v272_settings($pdo,$uid);if(empty($settings['automation_enabled']))return [];
    $rows=tracky_cloud_v270_recent_events($pdo,$uid,null,max(1,min(100,$limit)));$out=[];
    foreach($rows as $row){
        $class=tracky_v272_event_class((string)$row['event_type']);
        if(!tracky_v272_surface_allowed($settings,'automation',$class))continue;
        $payload=tracky_v272_trigger_payload($row);if($payload)$out[]=$payload;
    }
    return array_slice($out,0,$limit);
}

function tracky_v272_observation_event_row(PDO $pdo,array $user,array $observation): ?array
{
    if((string)($observation['source']??'')!=='tracky')return null;
    foreach((array)($observation['evidence_refs']??[]) as $evidence){
        $ref=is_array($evidence['object_ref']??null)?$evidence['object_ref']:[];
        if((string)($ref['type']??'')!=='physical_event')continue;
        $id=(string)($ref['id']??'');
        if(ctype_digit($id))return tracky_v272_event_row_by_numeric_id($pdo,(int)($user['id']??0),(int)$id);
    }
    return null;
}

function tracky_v272_observation_now_allowed(PDO $pdo,array $user,array $observation): bool
{
    if((string)($observation['source']??'')!=='tracky')return true;
    $row=tracky_v272_observation_event_row($pdo,$user,$observation);if(!$row)return false;
    $settings=tracky_v272_settings($pdo,(int)$user['id']);
    return tracky_v272_surface_allowed($settings,'now',tracky_v272_event_class((string)$row['event_type']));
}

function tracky_v272_observation_group_key(PDO $pdo,array $user,array $observation): string
{
    $row=tracky_v272_observation_event_row($pdo,$user,$observation);if(!$row)return '';
    return 'tracky:'.tracky_v272_event_class((string)$row['event_type']).':'.substr(hash('sha256',tracky_v272_alert_group($row)),0,20);
}

function tracky_v272_observation_id(string $siteId,string $eventId): string
{
    return 'tracky.'.substr(hash('sha256',$siteId."\0".$eventId),0,48);
}

function tracky_v272_observation_category(array $row): string
{
    $class=tracky_v272_event_class((string)($row['event_type']??''));
    if($class==='safety'||$class==='health')return 'risk';
    if($class==='environment'||$class==='routine')return 'anomaly';
    return 'fact_summary';
}

function tracky_v272_observation_surface(array $settings,array $row): string
{
    $class=tracky_v272_event_class((string)$row['event_type']);$metric=tracky_v272_event_score($row);
    if($metric['attention']&&tracky_v272_surface_allowed($settings,'voice',$class))return 'voice_announce';
    if($metric['attention']&&tracky_v272_surface_allowed($settings,'chat',$class))return 'notification';
    if(tracky_v272_surface_allowed($settings,'now',$class))return 'brief';
    return 'memory';
}

function tracky_v272_observation_input(array $settings,array $row): array
{
    $event=is_array($row['event']??null)?$row['event']:[];
    $class=tracky_v272_event_class((string)$row['event_type']);$metric=tracky_v272_event_score($row);
    $siteId=(string)$row['site_id'];$eventId=(string)$row['event_id'];
    $validSeconds=$metric['attention']?7200:86400;
    return [
        'observation_id'=>tracky_v272_observation_id($siteId,$eventId),
        'category'=>tracky_v272_observation_category($row),
        'title'=>tracky_v272_event_title($row),
        'reason'=>tracky_v272_safe_summary($row),
        'evidence_refs'=>[[
            'truth_type'=>$class==='health'?'external_verified':'model_inference',
            'object_ref'=>vp3_cognitive_object_ref_v500('physical_event',(string)$row['id'],'personal'),
            'statement'=>tracky_v272_safe_summary($row),
            'occurred_at'=>(string)$row['occurred_at'],
        ]],
        'confidence'=>(float)$row['confidence'],
        'novelty'=>(float)$metric['novelty'],
        'urgency'=>(float)$metric['urgency'],
        'impact'=>(float)$metric['impact'],
        'goal_relevance'=>(float)$metric['goal_relevance'],
        'valid_until'=>gmdate(DATE_ATOM,time()+$validSeconds),
        'proposed_action_ids'=>[],
        'proposed_cards'=>[[
            'card_type'=>'physical_event',
            'object_ref'=>vp3_cognitive_object_ref_v500('physical_event',(string)$row['id'],'personal'),
            'display_mode'=>'compact',
        ]],
        'presentation_recommendation'=>tracky_v272_observation_surface($settings,$row),
        'voice_safe_summary'=>tracky_v272_surface_allowed($settings,'voice',$class)?tracky_v272_safe_summary($row):'',
        'source'=>'tracky',
        'source_event_uuid'=>substr(hash('sha256',$siteId.'|'.$eventId),0,64),
    ];
}

function tracky_v272_notification_type(array $row): string
{
    return tracky_v272_event_score($row)['attention']?'tracky_needs_attention':'tracky_update';
}

function tracky_v272_create_attention_notification(PDO $pdo,array $user,string $namespace,array $row,array $settings,array $decision): void
{
    $class=tracky_v272_event_class((string)$row['event_type']);
    $selected=(string)($decision['surface']??'');
    if(!in_array($selected,['notification','voice_announce','ask_user'],true)
        &&(string)($decision['paired_surface']??'')!=='notification')return;
    if(!tracky_v272_surface_allowed($settings,'chat',$class)&&!tracky_v272_surface_allowed($settings,'voice',$class))return;
    if(!function_exists('create_notification'))return;
    $eventDbId=(int)($row['id']??0);if($eventDbId<1)return;
    $title=tracky_v272_event_title($row);
    $body=tracky_v272_safe_summary($row).' · '.tracky_v272_site_label($pdo,(int)$user['id'],(string)$row['site_id']);
    $url='/tracky.php?site='.rawurlencode((string)$row['site_id']).'&event='.rawurlencode((string)$row['event_id']);
    create_notification((int)$user['id'],tracky_v272_notification_type($row),$title,$body,$url,'tracky_event',$eventDbId,(string)$row['occurred_at']);
    if(table_exists('notifications')&&function_exists('vp3_cognitive_attention_mark_delivered_v2410')){
        $check=$pdo->prepare("SELECT id FROM notifications WHERE user_id=? AND source_type='tracky_event' AND source_id=? ORDER BY id DESC LIMIT 1");
        $check->execute([(int)$user['id'],$eventDbId]);
        if((int)($check->fetchColumn()?:0)>0){
            vp3_cognitive_attention_mark_delivered_v2410($pdo,$user,$namespace,'tracky:event:'.(string)$row['event_id']);
        }
    }
}

function tracky_v272_on_sync(PDO $pdo,int $userId,string $siteId,array $events): void
{
    if($userId<1||$siteId===''||!function_exists('vp3_cognitive_observation_store_v500'))return;
    $stmt=$pdo->prepare('SELECT * FROM users WHERE id=? LIMIT 1');$stmt->execute([$userId]);$user=$stmt->fetch();
    if(!$user||!tracky_agent_enabled_v271($pdo,$user)||!vp3_cognitive_schema_ready_v500($pdo))return;
    $settings=tracky_v272_settings($pdo,$userId);
    if(empty($settings['now_enabled'])&&empty($settings['chat_enabled'])&&empty($settings['voice_enabled']))return;
    $namespace=function_exists('vp3_cognitive_agent_namespace_v500')?vp3_cognitive_agent_namespace_v500($pdo,$user,0):'system';
    foreach(array_slice($events,0,VP3_TRACKY_PROACTIVE_MAX_SYNC_EVENTS_V272) as $event){
        if(!is_array($event))continue;
        $row=tracky_v272_event_row($pdo,$userId,$siteId,(string)($event['event_id']??''));if(!$row)continue;
        $class=tracky_v272_event_class((string)$row['event_type']);
        if(!tracky_v272_surface_allowed($settings,'now',$class)
            &&!tracky_v272_surface_allowed($settings,'chat',$class)
            &&!tracky_v272_surface_allowed($settings,'voice',$class))continue;
        try{
            tracky_v272_reconcile_prior_alerts($pdo,$user,$namespace,$row);
            $input=tracky_v272_observation_input($settings,$row);
            $observation=vp3_cognitive_observation_store_v500($pdo,$user,$namespace,$input);
            $context=vp3_cognitive_presentation_context_v500($pdo,$user,[
                'voice_candidate_allowed'=>tracky_v272_surface_allowed($settings,'voice',$class),
            ]);
            $signal=$input+['key'=>'tracky:event:'.(string)$row['event_id'],'updated_at'=>(string)$row['occurred_at']];
            $decision=function_exists('vp3_cognitive_attention_arbitrate_v2410')
                ?vp3_cognitive_attention_arbitrate_v2410($pdo,$user,$namespace,$signal,$context)
                :vp3_cognitive_presentation_decide_v500($observation,$context);
            if(function_exists('vp3_cognitive_presentation_record_v500'))vp3_cognitive_presentation_record_v500($pdo,$user,$namespace,$observation,$decision);
            tracky_v272_create_attention_notification($pdo,$user,$namespace,$row,$settings,$decision);
        }catch(Throwable $e){
            error_log('Tracky V2.72 proactive projection failed: '.$e->getMessage());
        }
    }
}

function tracky_v272_alert_group(array $row): string
{
    $event=is_array($row['event']??null)?$row['event']:[];
    $type=(string)($row['event_type']??'');$class=tracky_v272_event_class($type);
    $subject=is_array($event['subject']??null)?(string)($event['subject']['entity_id']??''):'';
    $object=is_array($event['object']??null)?(string)($event['object']['entity_id']??''):'';
    $room=(string)($event['room_id']??'');
    $prefix=str_contains($type,'.')?strstr($type,'.',true):$type;
    return (string)$row['site_id'].'|'.$class.'|'.$prefix.'|'.$subject.'|'.$object.'|'.$room;
}

function tracky_v272_event_is_resolution(array $row): bool
{
    $type=strtolower((string)($row['event_type']??''));$event=is_array($row['event']??null)?$row['event']:[];
    $state=strtolower(trim((string)($event['state']??'')));
    return (bool)preg_match('/(?:healthy|connected|recovered|restored|resolved|available)$/',$type)
        ||in_array($state,['healthy','connected','recovered','restored','resolved','online','available'],true);
}

function tracky_v272_reconcile_prior_alerts(PDO $pdo,array $user,string $namespace,array $current): void
{
    $uid=(int)($user['id']??0);$currentId=(int)($current['id']??0);
    if($uid<1||$currentId<1)return;
    $group=tracky_v272_alert_group($current);$resolution=tracky_v272_event_is_resolution($current);
    $stmt=$pdo->prepare("SELECT id,site_id,event_id,event_type,severity,confidence,occurred_at,event_json
      FROM tracky_cloud_events WHERE user_id=? AND id<? ORDER BY id DESC LIMIT 80");
    $stmt->execute([$uid,$currentId]);
    foreach($stmt->fetchAll()?:[] as $prior){
        $event=json_decode((string)$prior['event_json'],true);$prior['event']=is_array($event)?$event:[];
        if(!hash_equals($group,tracky_v272_alert_group($prior)))continue;
        if(!tracky_v272_event_score($prior)['attention'])continue;

        if(table_exists('notifications')&&function_exists('mark_notification_read')){
            $n=$pdo->prepare("SELECT id FROM notifications WHERE user_id=? AND source_type='tracky_event' AND source_id=? AND is_read=0");
            $n->execute([$uid,(int)$prior['id']]);
            foreach($n->fetchAll()?:[] as $notification)mark_notification_read((int)$notification['id'],$uid);
        }
        if(vp3_cognitive_schema_ready_v500($pdo)){
            $state=$resolution?'resolved':'superseded';
            $observationKey=tracky_v272_observation_id((string)$prior['site_id'],(string)$prior['event_id']);
            $update=$pdo->prepare("UPDATE cognitive_observations_v500 SET state=?,updated_at=UTC_TIMESTAMP()
              WHERE owner_user_id=? AND agent_namespace=? AND observation_key=? AND state='active'");
            $update->execute([$state,$uid,$namespace,$observationKey]);
        }
        if(function_exists('vp3_cognitive_attention_mark_released_v2410')){
            vp3_cognitive_attention_mark_released_v2410($pdo,$user,$namespace,'tracky:event:'.(string)$prior['event_id']);
        }
        break;
    }
}

function tracky_v272_alert_lifecycle(PDO $pdo,int $userId,array $row): string
{
    if(!tracky_v272_event_score($row)['attention'])return 'informational';
    $id=(int)($row['id']??0);if($id<1)return 'new';
    $group=tracky_v272_alert_group($row);
    $stmt=$pdo->prepare("SELECT id,site_id,event_id,event_type,severity,confidence,occurred_at,event_json
      FROM tracky_cloud_events WHERE user_id=? AND id>? ORDER BY id ASC LIMIT 80");
    $stmt->execute([$userId,$id]);
    foreach($stmt->fetchAll()?:[] as $later){
        $event=json_decode((string)$later['event_json'],true);$later['event']=is_array($event)?$event:[];
        if(!hash_equals($group,tracky_v272_alert_group($later)))continue;
        return tracky_v272_event_is_resolution($later)?'resolved':'superseded';
    }
    if(table_exists('notifications')){
        $n=$pdo->prepare("SELECT is_read FROM notifications WHERE user_id=? AND source_type='tracky_event' AND source_id=? ORDER BY id DESC LIMIT 1");
        $n->execute([$userId,$id]);$read=$n->fetchColumn();
        if($read!==false&&(int)$read===1)return 'acknowledged';
    }
    return 'new';
}

function tracky_v272_card(PDO $pdo,array $user,string $namespace,array $ref,string $mode): array
{
    $uid=(int)($user['id']??0);$eventRowId=(int)($ref['id']??0);
    $row=$eventRowId>0?tracky_v272_event_row_by_numeric_id($pdo,$uid,$eventRowId):null;
    if(!$row)throw new RuntimeException('Physical event is unavailable.');
    $class=tracky_v272_event_class((string)$row['event_type']);$lifecycle=tracky_v272_alert_lifecycle($pdo,$uid,$row);
    return [
        'title'=>tracky_v272_event_title($row),
        'subtitle'=>tracky_v272_site_label($pdo,$uid,(string)$row['site_id']).' · '.ucfirst($class),
        'status'=>$lifecycle,
        'summary'=>tracky_v272_safe_summary($row),
        'timestamp'=>(string)$row['occurred_at'],
        'badges'=>[(string)$row['severity'],number_format((float)$row['confidence']*100,1).'% confidence'],
        'facts'=>[
            ['label'=>'Event','value'=>(string)$row['event_type']],
            ['label'=>'Site','value'=>tracky_v272_site_label($pdo,$uid,(string)$row['site_id'])],
            ['label'=>'Lifecycle','value'=>$lifecycle],
        ],
        'sections'=>[],
        'media'=>[],
        'metadata'=>[
            'event_id'=>(string)$row['event_id'],'site_id'=>(string)$row['site_id'],
            'event_class'=>$class,'privacy_class'=>(string)$row['privacy_class'],
            'raw_perception_exposed'=>false,
        ],
        'actions'=>[
            ['type'=>'open_url','label'=>'Open Tracky','url'=>'/tracky.php?site='.rawurlencode((string)$row['site_id']).'&event='.rawurlencode((string)$row['event_id'])],
        ],
    ];
}

function tracky_v272_notification_voice_allowed(array $notification): bool
{
    if(strtolower(trim((string)($notification['source_type']??'')))!=='tracky_event')return false;
    $uid=(int)($notification['user_id']??0);$sourceId=(int)($notification['source_id']??0);
    $pdo=db();if(!$pdo||$uid<1||$sourceId<1)return false;
    $settings=tracky_v272_settings($pdo,$uid);
    $row=tracky_v272_event_row_by_numeric_id($pdo,$uid,$sourceId);if(!$row)return false;
    return tracky_v272_surface_allowed($settings,'voice',tracky_v272_event_class((string)$row['event_type']));
}

function tracky_v272_simulator_scenarios(string $siteId='sim-home'): array
{
    $baseTime='2026-09-26T12:00:00+00:00';
    $event=static function(
        string $id,int $sequence,string $type,string $severity,string $summary,int $offsetSeconds=0,array $extra=[]
    ) use($siteId,$baseTime): array {
        $time=(new DateTimeImmutable($baseTime))->modify(($offsetSeconds>=0?'+':'').$offsetSeconds.' seconds')->format(DATE_ATOM);
        return array_replace([
            'id'=>$sequence,
            'site_id'=>$siteId,
            'event_id'=>$id,
            'sequence_no'=>$sequence,
            'event_type'=>$type,
            'severity'=>$severity,
            'confidence'=>0.95,
            'privacy_class'=>'cloud_derived',
            'occurred_at'=>$time,
            'is_fresh'=>1,
            'projected'=>1,
            'event'=>[
                'event_id'=>$id,'sequence'=>$sequence,'event_type'=>$type,'severity'=>$severity,
                'confidence'=>0.95,'privacy_class'=>'cloud_derived','occurred_at'=>$time,'summary'=>$summary,
            ],
        ],$extra);
    };

    return [
        'arrival'=>[
            $event('sim-arrive-1',1,'person.detected','notable','Dave arrived in the Office',0,[
                'event'=>[
                    'event_id'=>'sim-arrive-1','sequence'=>1,'event_type'=>'person.detected','severity'=>'notable',
                    'confidence'=>0.95,'privacy_class'=>'cloud_derived','occurred_at'=>$baseTime,
                    'summary'=>'Dave arrived in the Office','room_id'=>'room:office',
                    'subject'=>['entity_id'=>'person:dave','type'=>'person'],
                ],
            ]),
        ],
        'departure'=>[
            $event('sim-left-2',2,'person.left','notable','Dave left the Office',30),
        ],
        'object_moved'=>[
            $event('sim-object-3',3,'object.moved','notable','Keys moved to the desk',60,[
                'event'=>[
                    'event_id'=>'sim-object-3','sequence'=>3,'event_type'=>'object.moved','severity'=>'notable',
                    'confidence'=>0.95,'privacy_class'=>'cloud_derived','occurred_at'=>(new DateTimeImmutable($baseTime))->modify('+60 seconds')->format(DATE_ATOM),
                    'summary'=>'Keys moved to the desk','room_id'=>'room:office',
                    'subject'=>['entity_id'=>'object:keys','type'=>'object'],
                ],
            ]),
        ],
        'camera_failure_and_recovery'=>[
            $event('sim-camera-down-4',4,'camera.disconnected','system-health','Office camera disconnected',90,[
                'event'=>[
                    'event_id'=>'sim-camera-down-4','sequence'=>4,'event_type'=>'camera.disconnected','severity'=>'system-health',
                    'confidence'=>1.0,'privacy_class'=>'system_health','occurred_at'=>(new DateTimeImmutable($baseTime))->modify('+90 seconds')->format(DATE_ATOM),
                    'summary'=>'Office camera disconnected','state'=>'disconnected',
                ],
            ]),
            $event('sim-camera-up-5',5,'camera.connected','notable','Office camera reconnected',120,[
                'event'=>[
                    'event_id'=>'sim-camera-up-5','sequence'=>5,'event_type'=>'camera.connected','severity'=>'notable',
                    'confidence'=>1.0,'privacy_class'=>'system_health','occurred_at'=>(new DateTimeImmutable($baseTime))->modify('+120 seconds')->format(DATE_ATOM),
                    'summary'=>'Office camera reconnected','state'=>'connected',
                ],
            ]),
        ],
        'possible_safety_condition'=>[
            $event('sim-safety-6',6,'safety.possible_hazard','urgent','Possible smoke-like condition detected',150),
        ],
        'duplicate_event'=>[
            $event('sim-duplicate-7',7,'environment.changed','notable','Lighting changed',180),
            $event('sim-duplicate-7',7,'environment.changed','notable','Lighting changed',180),
        ],
        'outage_reconnect'=>[
            $event('sim-outage-8',8,'tracky.disconnected','system-health','Tracky Cloud connection interrupted',210),
            $event('sim-reconnect-9',9,'tracky.connected','notable','Tracky Cloud connection restored',3600),
        ],
    ];
}

function tracky_v272_plugin_can_read(PDO $pdo,array $user,string $consumerPlugin): bool
{
    $uid=(int)($user['id']??0);$consumerPlugin=trim($consumerPlugin);
    if($uid<1||$consumerPlugin===''||$consumerPlugin==='tracky'||!vp3_plugin_valid_v320($consumerPlugin))return false;
    if(!tracky_agent_enabled_v271($pdo,$user))return false;
    $settings=tracky_v272_settings($pdo,$uid);
    if(empty($settings['cross_plugin_access'])||!in_array($consumerPlugin,(array)($settings['cross_plugin_plugins']??[]),true))return false;
    return function_exists('vp3_plugin_effective_enabled_v360')&&vp3_plugin_effective_enabled_v360($pdo,$user,$consumerPlugin);
}

function tracky_v272_plugin_context(PDO $pdo,array $user,string $consumerPlugin): array
{
    if(!tracky_v272_plugin_can_read($pdo,$user,$consumerPlugin))throw new RuntimeException('Cross-plugin physical context access denied.');
    $uid=(int)$user['id'];$sites=tracky_cloud_v270_sites($pdo,$uid);$out=[];
    foreach(array_slice($sites,0,12) as $site){
        $siteId=(string)$site['site_id'];
        $out[]=[
            'site_id'=>$siteId,'label'=>(string)($site['label']?:$siteId),'status'=>(string)$site['status'],
            'current_context'=>tracky_cloud_v270_current_context($pdo,$uid,$siteId),
            'health'=>is_array($site['health']??null)?$site['health']:[],
        ];
    }
    return [
        'contract'=>VP3_TRACKY_PROTOCOL_V270,
        'sdk'=>'physical_context.v1',
        'consumer_plugin'=>$consumerPlugin,
        'sites'=>$out,
        'raw_perception_exposed'=>false,
        'event_history_exposed'=>false,
        'read_only'=>true,
    ];
}
