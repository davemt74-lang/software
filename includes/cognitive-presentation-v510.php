<?php
declare(strict_types=1);

const VP3_COGNITIVE_PRESENTATION_V510='vp3-cognitive-presentation-v510-20260918';
const VP3_COGNITIVE_PRESENTATION_POLL_SECONDS_V510=30;
const VP3_COGNITIVE_DIGEST_NORMAL_IDLE_MINUTES_V510=60;
const VP3_COGNITIVE_DIGEST_ATTENTION_IDLE_MINUTES_V510=30;

function vp3_cognitive_presentation_owns_attention_v510(): bool{return true;}

function vp3_cognitive_presentation_schema_ready_v510(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)$pdo&&table_exists('cognitive_presentation_state_v510')&&table_exists('cognitive_return_digests_v510');
}

function vp3_cognitive_presentation_ensure_schema_v510(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(vp3_cognitive_presentation_schema_ready_v510($pdo))return;
    if(!table_exists('users'))throw new RuntimeException('VP3 users must exist before Cognitive Presentation.');

    $pdo->exec("CREATE TABLE IF NOT EXISTS cognitive_presentation_state_v510 (
      owner_user_id INT UNSIGNED NOT NULL,
      agent_namespace VARCHAR(80) NOT NULL DEFAULT 'system',
      last_meaningful_at DATETIME NOT NULL,
      baseline_notification_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      last_voice_notification_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (owner_user_id,agent_namespace),
      INDEX idx_cognitive_presentation_seen_v510 (owner_user_id,last_seen_at),
      CONSTRAINT fk_cognitive_presentation_state_owner_v510 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS cognitive_return_digests_v510 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      public_id CHAR(36) NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      agent_namespace VARCHAR(80) NOT NULL DEFAULT 'system',
      start_notification_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      end_notification_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      idle_minutes INT UNSIGNED NOT NULL DEFAULT 0,
      summary VARCHAR(600) NOT NULL,
      items_json MEDIUMTEXT NOT NULL,
      status VARCHAR(24) NOT NULL DEFAULT 'presented',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      acknowledged_at DATETIME NULL,
      dismissed_at DATETIME NULL,
      UNIQUE KEY uq_cognitive_return_digest_public_v510 (public_id),
      UNIQUE KEY uq_cognitive_return_digest_window_v510 (owner_user_id,agent_namespace,end_notification_id),
      INDEX idx_cognitive_return_digest_owner_v510 (owner_user_id,agent_namespace,status,created_at,id),
      CONSTRAINT fk_cognitive_return_digest_owner_v510 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function vp3_cognitive_presentation_latest_notification_id_v510(PDO $pdo,int $uid): int
{
    if($uid<1||!table_exists('notifications'))return 0;
    try{$s=$pdo->prepare('SELECT COALESCE(MAX(id),0) FROM notifications WHERE user_id=?');$s->execute([$uid]);return max(0,(int)$s->fetchColumn());}
    catch(Throwable $e){return 0;}
}

function vp3_cognitive_presentation_state_row_v510(PDO $pdo,array $user,string $namespace): array
{
    if(!vp3_cognitive_presentation_schema_ready_v510($pdo))throw new RuntimeException('Cognitive Presentation schema is not ready.');
    $uid=(int)($user['id']??0);if($uid<1)throw new RuntimeException('A signed-in VP3 user is required.');
    $namespace=vp3_cognitive_validate_namespace_v500($pdo,$user,$namespace);
    $s=$pdo->prepare('SELECT * FROM cognitive_presentation_state_v510 WHERE owner_user_id=? AND agent_namespace=? LIMIT 1');
    $s->execute([$uid,$namespace]);$row=$s->fetch();
    if(is_array($row))return $row;
    $latest=vp3_cognitive_presentation_latest_notification_id_v510($pdo,$uid);
    $pdo->prepare('INSERT INTO cognitive_presentation_state_v510 (owner_user_id,agent_namespace,last_meaningful_at,baseline_notification_id,last_voice_notification_id,last_seen_at) VALUES (?,?,UTC_TIMESTAMP(),?,?,UTC_TIMESTAMP())')
        ->execute([$uid,$namespace,$latest,$latest]);
    $s->execute([$uid,$namespace]);$row=$s->fetch();
    if(!is_array($row))throw new RuntimeException('Cognitive Presentation state could not be initialized.');
    return $row;
}

function vp3_cognitive_presentation_touch_v510(PDO $pdo,array $user,string $namespace): void
{
    vp3_cognitive_presentation_state_row_v510($pdo,$user,$namespace);
    $latest=vp3_cognitive_presentation_latest_notification_id_v510($pdo,(int)$user['id']);
    $pdo->prepare('UPDATE cognitive_presentation_state_v510 SET last_meaningful_at=UTC_TIMESTAMP(),baseline_notification_id=?,last_seen_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE owner_user_id=? AND agent_namespace=?')
        ->execute([$latest,(int)$user['id'],$namespace]);
}

function vp3_cognitive_presentation_idle_minutes_v510(array $row): int
{
    $last=strtotime((string)($row['last_meaningful_at']??''))?:time();
    return max(0,(int)floor((time()-$last)/60));
}

function vp3_cognitive_presentation_internal_url_v510(string $url): string
{
    $url=trim($url);
    return $url!==''&&str_starts_with($url,'/')&&!str_starts_with($url,'//')?mb_strimwidth($url,0,500,''):'';
}

function vp3_cognitive_presentation_notification_rows_v510(PDO $pdo,array $user,int $afterId,int $limit=100): array
{
    $uid=(int)($user['id']??0);$limit=max(1,min(150,$limit));
    if($uid<1||!table_exists('notifications'))return [];
    try{
        $predicate=function_exists('notification_system_sql_predicate')?notification_system_sql_predicate('n'):'1=1';
        $s=$pdo->prepare("SELECT n.* FROM notifications n WHERE n.user_id=? AND n.id>? AND n.is_read=0 AND {$predicate} ORDER BY n.id ASC LIMIT {$limit}");
        $s->execute([$uid,max(0,$afterId)]);
        return $s->fetchAll()?:[];
    }catch(Throwable $e){return [];}
}

function vp3_cognitive_presentation_digest_items_v510(array $rows,int $idleMinutes): array
{
    $groups=[];
    foreach($rows as $row){
        if(!is_array($row))continue;
        $attention=function_exists('notification_requires_attention')&&notification_requires_attention($row);
        if($idleMinutes<VP3_COGNITIVE_DIGEST_NORMAL_IDLE_MINUTES_V510&&!$attention)continue;
        $sourceType=strtolower(trim((string)($row['source_type']??'')));$sourceId=max(0,(int)($row['source_id']??0));
        $type=strtolower(trim((string)($row['type']??'')));$title=vp3_cognitive_text_v500($row['title']??'Update',190);
        $key=$sourceType!==''&&$sourceId>0?$sourceType.':'.$sourceId:$type.':'.sha1(strtolower($title));
        if(!isset($groups[$key])){
            $groups[$key]=[
                'id'=>(int)($row['id']??0),'type'=>$type,'title'=>$title,
                'body'=>vp3_cognitive_text_v500($row['body']??'',420),
                'target_url'=>vp3_cognitive_presentation_internal_url_v510((string)($row['target_url']??'')),
                'created_at'=>(string)($row['created_at']??''),'attention'=>$attention,'count'=>1,
                'source_type'=>$sourceType,'source_id'=>$sourceId,
                'card_request'=>function_exists('vp3_cognitive_cards_notification_request_v520')
                    ? vp3_cognitive_cards_notification_request_v520($row)
                    : null,
            ];
        }else{
            $groups[$key]['count']++;
            $groups[$key]['attention']=$groups[$key]['attention']||$attention;
            if((int)($row['id']??0)>$groups[$key]['id']){
                $groups[$key]['id']=(int)$row['id'];$groups[$key]['title']=$title;
                $groups[$key]['body']=vp3_cognitive_text_v500($row['body']??'',420);
                $groups[$key]['target_url']=vp3_cognitive_presentation_internal_url_v510((string)($row['target_url']??''));
                $groups[$key]['created_at']=(string)($row['created_at']??'');
                if(function_exists('vp3_cognitive_cards_notification_request_v520')){
                    $groups[$key]['card_request']=vp3_cognitive_cards_notification_request_v520($row);
                }
            }
        }
    }
    $items=array_values($groups);
    usort($items,static function($a,$b){
        $x=((int)!empty($b['attention']))<=>((int)!empty($a['attention']));
        return $x!==0?$x:((int)($b['id']??0)<=>((int)($a['id']??0)));
    });
    return array_slice($items,0,8);
}

function vp3_cognitive_presentation_digest_summary_v510(array $items): string
{
    $total=0;$attention=0;
    foreach($items as $item){$count=max(1,(int)($item['count']??1));$total+=$count;if(!empty($item['attention']))$attention+=$count;}
    if($total<1)return '';
    if($attention>0&&$attention<$total)return 'While you were away, '.$attention.' item'.($attention===1?' needs':'s need').' your attention and '.($total-$attention).' other update'.(($total-$attention)===1?' came':'s came').' in.';
    if($attention>0)return 'While you were away, '.$attention.' item'.($attention===1?' needs':'s need').' your attention.';
    return 'While you were away, '.$total.' update'.($total===1?' came':'s came').' in.';
}

function vp3_cognitive_presentation_digest_public_v510(array $row): array
{
    return [
        'id'=>(string)($row['public_id']??''),'idle_minutes'=>max(0,(int)($row['idle_minutes']??0)),
        'summary'=>(string)($row['summary']??''),'items'=>vp3_cognitive_decode_array_v500($row['items_json']??'[]'),
        'status'=>(string)($row['status']??'presented'),'created_at'=>(string)($row['created_at']??''),
    ];
}

function vp3_cognitive_presentation_open_digest_v510(PDO $pdo,array $user,string $namespace): ?array
{
    $s=$pdo->prepare("SELECT * FROM cognitive_return_digests_v510 WHERE owner_user_id=? AND agent_namespace=? AND status='presented' ORDER BY id DESC LIMIT 1");
    $s->execute([(int)$user['id'],$namespace]);$row=$s->fetch();
    return is_array($row)?vp3_cognitive_presentation_digest_public_v510($row):null;
}

function vp3_cognitive_presentation_digest_v510(PDO $pdo,array $user,string $namespace,array $state): ?array
{
    if($open=vp3_cognitive_presentation_open_digest_v510($pdo,$user,$namespace))return $open;
    $idle=vp3_cognitive_presentation_idle_minutes_v510($state);
    if($idle<VP3_COGNITIVE_DIGEST_ATTENTION_IDLE_MINUTES_V510)return null;
    $rows=vp3_cognitive_presentation_notification_rows_v510($pdo,$user,(int)($state['baseline_notification_id']??0),100);
    $eligible=array_values(array_filter($rows,static function($row) use($idle): bool {
        if($idle>=VP3_COGNITIVE_DIGEST_NORMAL_IDLE_MINUTES_V510)return true;
        return function_exists('notification_requires_attention')&&notification_requires_attention((array)$row);
    }));
    $items=vp3_cognitive_presentation_digest_items_v510($eligible,$idle);$summary=vp3_cognitive_presentation_digest_summary_v510($items);
    if($summary==='')return null;

    $ids=array_map(static fn($r)=>max(0,(int)($r['id']??0)),$eligible);
    if(!$ids)return null;
    $start=min($ids);$end=max($ids);$public=vp3_cognitive_uuid_v500();
    try{
        $pdo->prepare('INSERT INTO cognitive_return_digests_v510 (public_id,owner_user_id,agent_namespace,start_notification_id,end_notification_id,idle_minutes,summary,items_json) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$public,(int)$user['id'],$namespace,$start,$end,$idle,$summary,vp3_cognitive_json_v500($items)]);
    }catch(PDOException $e){if((string)$e->getCode()!=='23000')throw $e;}
    $pdo->prepare('UPDATE cognitive_presentation_state_v510 SET baseline_notification_id=GREATEST(baseline_notification_id,?),last_seen_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE owner_user_id=? AND agent_namespace=?')
        ->execute([$end,(int)$user['id'],$namespace]);
    return vp3_cognitive_presentation_open_digest_v510($pdo,$user,$namespace);
}

function vp3_cognitive_presentation_digest_ack_v510(PDO $pdo,array $user,string $namespace,string $publicId,string $status): void
{
    $dismiss=$status==='dismissed';
    $sql=$dismiss
        ?"UPDATE cognitive_return_digests_v510 SET status='dismissed',dismissed_at=UTC_TIMESTAMP() WHERE public_id=? AND owner_user_id=? AND agent_namespace=? AND status='presented'"
        :"UPDATE cognitive_return_digests_v510 SET status='acknowledged',acknowledged_at=UTC_TIMESTAMP() WHERE public_id=? AND owner_user_id=? AND agent_namespace=? AND status='presented'";
    $pdo->prepare($sql)->execute([$publicId,(int)$user['id'],$namespace]);
}

function vp3_cognitive_presentation_voice_allowed_type_v510(array $row): bool
{
    if(function_exists('notification_requires_attention')&&notification_requires_attention($row))return true;
    $type=strtolower(trim((string)($row['type']??'')));$source=strtolower(trim((string)($row['source_type']??'')));
    if($source==='profile_event'&&str_starts_with($type,'profile_'))return true;
    return (bool)preg_match('/(?:meeting|appointment|booking|calendar|profile|order|payment|message|approval|workflow|security|failed|failure)/',$type);
}

function vp3_cognitive_presentation_voice_text_v510(array $user,array $row,int $count=1): string
{
    $name=trim((string)($user['display_name']??''));$first=$name!==''?(preg_split('/\s+/',$name)[0]??''):'';
    $title=vp3_cognitive_text_v500($row['title']??'You have a new notification.',180);
    $body=vp3_cognitive_text_v500($row['body']??'',150);
    $message=($first!==''?$first.', ':'').$title.($body!==''?'. '.$body:'');
    if($count>1)$message.=' There are '.$count.' new items to review.';
    return mb_strimwidth($message,0,360,'…');
}

function vp3_cognitive_presentation_voice_candidate_v510(PDO $pdo,array $user,array $state,?array $digest=null): ?array
{
    $settings=['agent_voice_enabled'=>false];
    if(function_exists('chat_settings_get_v237')){
        try{
            $settings=chat_settings_get_v237($pdo,(int)$user['id']);
        }catch(Throwable $e){
            error_log('VP3 Cognitive Presentation voice settings unavailable: '.$e->getMessage());
            $settings=['agent_voice_enabled'=>false];
        }
    }
    if(array_key_exists('agent_voice_enabled',$settings)&&empty($settings['agent_voice_enabled']))return null;
    $rows=vp3_cognitive_presentation_notification_rows_v510($pdo,$user,max(0,(int)($state['last_voice_notification_id']??0)),50);
    if(!$rows)return null;
    $maxId=max(array_map(static fn($r)=>max(0,(int)($r['id']??0)),$rows));
    $eligible=array_values(array_filter($rows,'vp3_cognitive_presentation_voice_allowed_type_v510'));
    if(!$eligible)return ['skip_through_id'=>$maxId];
    if($digest&&(int)($digest['idle_minutes']??0)>=VP3_COGNITIVE_DIGEST_ATTENTION_IDLE_MINUTES_V510){
        return ['through_id'=>$maxId,'message'=>vp3_cognitive_text_v500($digest['summary']??'',360),'kind'=>'return_digest'];
    }
    usort($eligible,static function($a,$b){
        $aa=function_exists('notification_requires_attention')&&notification_requires_attention($a)?1:0;
        $bb=function_exists('notification_requires_attention')&&notification_requires_attention($b)?1:0;
        return $aa!==$bb?$bb<=>$aa:((int)($b['id']??0)<=>((int)($a['id']??0)));
    });
    return ['through_id'=>$maxId,'message'=>vp3_cognitive_presentation_voice_text_v510($user,$eligible[0],count($eligible)),'kind'=>'notification'];
}

function vp3_cognitive_presentation_voice_delivered_v510(PDO $pdo,array $user,string $namespace,int $throughId): void
{
    vp3_cognitive_presentation_state_row_v510($pdo,$user,$namespace);
    $pdo->prepare('UPDATE cognitive_presentation_state_v510 SET last_voice_notification_id=GREATEST(last_voice_notification_id,?),updated_at=UTC_TIMESTAMP() WHERE owner_user_id=? AND agent_namespace=?')
        ->execute([max(0,$throughId),(int)$user['id'],$namespace]);
}

function vp3_cognitive_presentation_brief_v510(PDO $pdo,array $user,int $agentId=0): array
{
    require_once __DIR__.'/agent-chat-intelligence-v171.php';
    $agent=$agentId>0&&function_exists('user_agent_get_v236')?user_agent_get_v236($pdo,(int)$user['id'],$agentId):null;
    $available=$agentId<1||($agent&&!empty($agent['is_active']));
    $name=$agent?vp3_cognitive_text_v500($agent['display_name']??'VP3 Agent',120):(function_exists('system_agent_name')?system_agent_name():'VP3 Agent');
    $model=vp3_agent_chat_intelligence_model_v171($pdo,$user,$agent,$name);
    $activity=is_array($model['activity']??null)?$model['activity']:[];
    $state=strtolower(trim((string)($activity['state']??'idle')))?:'idle';
    $working=in_array($state,['working','processing','executing','listening','speaking'],true);
    $queue=is_array($model['work_queue']??null)?$model['work_queue']:[];
    $lanes=is_array($queue['lanes']??null)?$queue['lanes']:[];
    $current=null;
    foreach(['active','approval','blocked','scheduled','failed_retry'] as $lane){
        if(is_array($lanes[$lane][0]??null)){$item=$lanes[$lane][0];$current=['lane'=>$lane,'title'=>vp3_cognitive_text_v500($item['title']??'Agent work',160),'detail'=>vp3_cognitive_text_v500($item['detail']??$item['status']??'',240)];break;}
    }
    $suggestion=is_array($model['suggestions'][0]??null)?$model['suggestions'][0]:null;
    if(function_exists('agent_cognitive_loop_v310_priority_items')){
        try{
            $priorities=agent_cognitive_loop_v310_priority_items($user,1);
            if(is_array($priorities[0]??null)){
                $priority=$priorities[0];
                $suggestion=[
                    'title'=>(string)($priority['title']??'Agent Brain priority'),
                    'reason'=>(string)($priority['reason']??''),
                    'prompt'=>(string)($priority['prompt']??''),
                ];
            }
        }catch(Throwable $e){}
    }
    $calendar=is_array($model['calendar'][0]??null)?$model['calendar'][0]:null;
    return [
        'agent_id'=>$agentId,'agent_name'=>$name,'active'=>$available,'working'=>$working,
        'status'=>$available?($working?'working':'active'):'inactive','status_label'=>$available?($working?'Working':'Active'):'Inactive',
        'activity_title'=>vp3_cognitive_text_v500($activity['task_title']??($working?'Agent working':'Ready'),180),
        'attention_count'=>max(0,(int)($model['attention_count']??0)),'current_work'=>$current,
        'top_suggestion'=>$suggestion?[
            'title'=>vp3_cognitive_text_v500($suggestion['title']??'Suggested next action',180),
            'reason'=>vp3_cognitive_text_v500($suggestion['reason']??'',380),
            'prompt'=>vp3_cognitive_text_v500($suggestion['prompt']??'',700),
        ]:null,
        'next_calendar'=>$calendar?['title'=>vp3_cognitive_text_v500($calendar['title']??'Upcoming calendar item',180),'start_at_utc'=>(string)($calendar['start_at_utc']??'')]:null,
        'counts'=>[
            'approvals'=>max(0,(int)($model['workflow_approvals']??0)),'blocked'=>max(0,(int)($model['workflow_blocked']??0)),
            'failed'=>max(0,(int)($model['workflow_failures']??0)),'unread'=>max(0,(int)($model['unread_notifications']??0)),
        ],
    ];
}

function vp3_cognitive_presentation_state_v510(PDO $pdo,array $user,string $namespace,int $agentId=0): array
{
    $row=vp3_cognitive_presentation_state_row_v510($pdo,$user,$namespace);
    $digest=vp3_cognitive_presentation_digest_v510($pdo,$user,$namespace,$row);
    $voice=vp3_cognitive_presentation_voice_candidate_v510($pdo,$user,$row,$digest);
    if(is_array($voice)&&isset($voice['skip_through_id'])){
        vp3_cognitive_presentation_voice_delivered_v510($pdo,$user,$namespace,(int)$voice['skip_through_id']);$voice=null;
    }
    $pdo->prepare('UPDATE cognitive_presentation_state_v510 SET last_seen_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE owner_user_id=? AND agent_namespace=?')
        ->execute([(int)$user['id'],$namespace]);
    return [
        'build'=>VP3_COGNITIVE_PRESENTATION_V510,'agent_namespace'=>$namespace,
        'idle_minutes'=>vp3_cognitive_presentation_idle_minutes_v510($row),
        'brief'=>vp3_cognitive_presentation_brief_v510($pdo,$user,$agentId),
        'digest'=>$digest,'voice_candidate'=>$voice,'poll_seconds'=>VP3_COGNITIVE_PRESENTATION_POLL_SECONDS_V510,
    ];
}
