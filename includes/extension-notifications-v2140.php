<?php
declare(strict_types=1);

const VP3_EXTENSION_NOTIFICATIONS_V2140='extension-notifications-v2140-20260919';
const VP3_EXTENSION_NOTIFICATION_CLAIM_SECONDS_V2140=120;
const VP3_EXTENSION_NOTIFICATION_SNOOZE_MINUTES_V2140=15;
const VP3_EXTENSION_NOTIFICATION_RETENTION_DAYS_V2140=30;

require_once __DIR__.'/notifications.php';
require_once __DIR__.'/cognitive-presentation-v510.php';
require_once __DIR__.'/cognitive-feed-v530.php';
require_once __DIR__.'/cognitive-cards-v520.php';
require_once __DIR__.'/browser-context-v2130.php';

function vp3_extension_notifications_schema_ready_v2140(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)$pdo&&table_exists('extension_notification_delivery_v2140');
}

function vp3_extension_notifications_ensure_schema_v2140(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(vp3_extension_notifications_schema_ready_v2140($pdo))return;
    if(!table_exists('users'))throw new RuntimeException('VP3 users must exist before Browser Companion notification delivery.');

    $pdo->exec("CREATE TABLE IF NOT EXISTS extension_notification_delivery_v2140 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      public_id CHAR(36) NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      event_key VARCHAR(190) NOT NULL,
      source_kind VARCHAR(32) NOT NULL DEFAULT 'notification',
      source_ref VARCHAR(190) NOT NULL DEFAULT '',
      notification_id BIGINT UNSIGNED NULL,
      title VARCHAR(190) NOT NULL DEFAULT '',
      body VARCHAR(500) NOT NULL DEFAULT '',
      target_url VARCHAR(500) NOT NULL DEFAULT '',
      action_label VARCHAR(80) NOT NULL DEFAULT 'Open VP3',
      voice_text VARCHAR(360) NOT NULL DEFAULT '',
      `sensitive` TINYINT(1) NOT NULL DEFAULT 0,
      claimed_device_id CHAR(36) NULL,
      claim_token_hash CHAR(64) NULL,
      claimed_at DATETIME NULL,
      claim_expires_at DATETIME NULL,
      visual_delivered_at DATETIME NULL,
      voice_delivered_at DATETIME NULL,
      voice_retry_after DATETIME NULL,
      voice_through_notification_id BIGINT UNSIGNED NULL,
      opened_at DATETIME NULL,
      dismissed_at DATETIME NULL,
      snoozed_until DATETIME NULL,
      attempts INT UNSIGNED NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_extension_notification_public_v2140 (public_id),
      UNIQUE KEY uq_extension_notification_event_v2140 (owner_user_id,event_key),
      INDEX idx_extension_notification_pending_v2140 (owner_user_id,dismissed_at,snoozed_until,visual_delivered_at,updated_at),
      INDEX idx_extension_notification_device_v2140 (owner_user_id,claimed_device_id,visual_delivered_at,voice_delivered_at),
      CONSTRAINT fk_extension_notification_owner_v2140 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function vp3_extension_notification_internal_url_v2140(mixed $value): string
{
    $url=trim((string)$value);
    return $url!==''&&str_starts_with($url,'/')&&!str_starts_with($url,'//')
        ?mb_strimwidth($url,0,500,'')
        :'';
}

function vp3_extension_notification_sensitive_v2140(array $candidate): bool
{
    $text=strtolower(implode(' ',[
        (string)($candidate['type']??''),(string)($candidate['source_type']??''),
        (string)($candidate['title']??''),(string)($candidate['body']??''),
        (string)($candidate['card_type']??'')
    ]));
    return (bool)preg_match('/(?:security|password|credential|token|billing|payment|financial|medical|health|private|secret)/',$text);
}

function vp3_extension_notification_action_label_v2140(array $candidate): string
{
    $text=strtolower(implode(' ',[
        (string)($candidate['type']??''),(string)($candidate['card_type']??''),
        (string)($candidate['title']??'')
    ]));
    if(preg_match('/approval|review_request|needs_approval/',$text))return 'Review approval';
    if(preg_match('/meeting|appointment|booking|calendar/',$text))return 'Open meeting';
    if(preg_match('/message|conversation|chat/',$text))return 'View message';
    if(preg_match('/opportun|recommend/',$text))return 'Open opportunity';
    if(preg_match('/workflow|plan|task/',$text))return 'Review workflow';
    return 'Open VP3';
}

function vp3_extension_notification_voice_enabled_v2140(PDO $pdo,array $user): bool
{
    if(!function_exists('chat_settings_get_v237'))return false;
    try{
        if(function_exists('chat_settings_agent_voice_enabled_v237'))return chat_settings_agent_voice_enabled_v237($pdo,$user);
        $settings=chat_settings_get_v237($pdo,(int)$user['id']);
        return !empty($settings['agent_voice_enabled']);
    }catch(Throwable $e){
        error_log('VP3 Browser Companion Agent Voice settings unavailable: '.$e->getMessage());
        return false;
    }
}

function vp3_extension_notification_candidate_public_v2140(array $candidate): array
{
    $sensitive=!empty($candidate['sensitive']);
    return [
        'event_key'=>(string)($candidate['event_key']??''),
        'source_kind'=>(string)($candidate['source_kind']??''),
        'title'=>$sensitive?'VP3 needs your attention':mb_strimwidth(trim((string)($candidate['title']??'VP3 update')),0,190,'…'),
        'body'=>$sensitive?'Open VP3 to review this update.':mb_strimwidth(trim((string)($candidate['body']??'')),0,420,'…'),
        'target_url'=>vp3_extension_notification_internal_url_v2140($candidate['target_url']??'')?:'/chat.php',
        'action_label'=>mb_strimwidth(trim((string)($candidate['action_label']??'Open VP3')),0,80,''),
        'sensitive'=>$sensitive,
        'voice_allowed'=>!empty($candidate['voice_allowed'])&&!$sensitive,
        'voice_text'=>$sensitive?'':mb_strimwidth(trim((string)($candidate['voice_text']??'')),0,360,'…'),
        'context_related'=>!empty($candidate['context_related']),
        'priority'=>max(0,min(100,(int)($candidate['priority']??0))),
        'created_at'=>(string)($candidate['created_at']??''),
    ];
}

function vp3_extension_notification_rows_v2140(PDO $pdo,array $user): array
{
    if(!table_exists('notifications'))return [];
    $predicate=function_exists('notification_system_sql_predicate')?notification_system_sql_predicate('n'):'1=1';
    $stmt=$pdo->prepare("SELECT n.* FROM notifications n
      WHERE n.user_id=? AND n.is_read=0 AND {$predicate}
        AND n.created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 24 HOUR)
      ORDER BY n.id DESC LIMIT 60");
    $stmt->execute([(int)$user['id']]);
    return $stmt->fetchAll()?:[];
}

function vp3_extension_notification_from_row_v2140(array $user,array $row): ?array
{
    $attention=function_exists('notification_requires_attention')&&notification_requires_attention($row);
    $voiceEligible=function_exists('vp3_cognitive_presentation_voice_allowed_type_v510')
        &&vp3_cognitive_presentation_voice_allowed_type_v510($row);
    if(!$attention&&!$voiceEligible)return null;

    $id=max(0,(int)($row['id']??0));if($id<1)return null;
    $candidate=[
        'event_key'=>'notification:'.$id,
        'source_kind'=>'notification',
        'source_ref'=>(string)$id,
        'notification_id'=>$id,
        'type'=>(string)($row['type']??''),
        'source_type'=>(string)($row['source_type']??''),
        'title'=>vp3_cognitive_text_v500($row['title']??'VP3 update',190),
        'body'=>vp3_cognitive_text_v500($row['body']??'',420),
        'target_url'=>vp3_extension_notification_internal_url_v2140($row['target_url']??'')?:'/chat.php',
        'created_at'=>(string)($row['created_at']??''),
        'priority'=>$attention?100:70,
        'voice_allowed'=>$voiceEligible,
        'voice_text'=>$voiceEligible?vp3_cognitive_presentation_voice_text_v510($user,$row,1):'',
    ];
    $candidate['sensitive']=vp3_extension_notification_sensitive_v2140($candidate);
    $candidate['action_label']=vp3_extension_notification_action_label_v2140($candidate);
    return $candidate;
}

function vp3_extension_notification_cognitive_candidates_v2140(PDO $pdo,array $user,string $namespace): array
{
    if(!function_exists('vp3_cognitive_feed_compose_v530')||!vp3_cognitive_feed_schema_ready_v530($pdo))return [];
    try{$feed=vp3_cognitive_feed_compose_v530($pdo,$user,$namespace,false);}
    catch(Throwable $e){return [];}
    $out=[];
    foreach((array)($feed['sections']??[]) as $section){
        if(!is_array($section)||(string)($section['id']??'')!=='attention')continue;
        foreach(array_slice((array)($section['items']??[]),0,8) as $item){
            if(!is_array($item))continue;
            if((string)($item['source']??'')==='notification')continue;
            $fingerprint=strtolower(trim((string)($item['fingerprint']??'')));
            if(!preg_match('/^[a-f0-9]{64}$/',$fingerprint))continue;
            $request=is_array($item['card_request']??null)?$item['card_request']:[];
            try{$card=vp3_cognitive_render_card_v500($pdo,$user,$namespace,$request);}
            catch(Throwable $e){$card=null;}
            if(!is_array($card))continue;
            $target='/chat.php';
            foreach((array)($card['actions']??[]) as $action){
                if(is_array($action)&&(string)($action['type']??'')==='open_url'){
                    $url=vp3_extension_notification_internal_url_v2140($action['url']??'');
                    if($url!==''){$target=$url;break;}
                }
            }
            $type=(string)($card['card_type']??'cognitive_attention');
            $title=vp3_cognitive_text_v500($card['title']??'VP3 needs your attention',190);
            $summary=vp3_cognitive_text_v500($card['summary']??$item['reason']??'',420);
            $voiceAllowed=(bool)preg_match('/(?:meeting|appointment|booking|approval|workflow|failure|failed|risk|security)/i',$type.' '.$title);
            $candidate=[
                'event_key'=>'cognitive:'.$fingerprint,
                'source_kind'=>'cognitive',
                'source_ref'=>(string)($item['key']??$fingerprint),
                'notification_id'=>null,
                'card_type'=>$type,
                'type'=>$type,
                'source_type'=>(string)($item['source']??'cognitive'),
                'title'=>$title,'body'=>$summary,'target_url'=>$target,
                'created_at'=>(string)($item['updated_at']??gmdate('Y-m-d H:i:s')),
                'priority'=>90,'voice_allowed'=>$voiceAllowed,
                'voice_text'=>$voiceAllowed
                    ?vp3_cognitive_text_v500(trim((string)($user['display_name']??'')).', '.$title.($summary!==''?'. '.$summary:''),360)
                    :'',
            ];
            $candidate['sensitive']=vp3_extension_notification_sensitive_v2140($candidate);
            $candidate['action_label']=vp3_extension_notification_action_label_v2140($candidate);
            $out[]=$candidate;
        }
    }
    return $out;
}

function vp3_extension_notification_context_terms_v2140(array $contextInput): array
{
    if(!$contextInput)return [];
    try{
        $context=vp3_browser_context_validate_v2130([
            'source_url'=>$contextInput['source_url']??'',
            'canonical_url'=>$contextInput['canonical_url']??'',
            'title'=>$contextInput['title']??'',
            'selected_text'=>'',
            'metadata'=>[],
        ]);
        return vp3_browser_context_terms_v2130($context);
    }catch(Throwable $e){return [];}
}

function vp3_extension_notification_candidates_v2140(PDO $pdo,array $user,string $namespace,array $contextInput=[]): array
{
    if(function_exists('client_release_intelligence_reconcile_user_v100')){
        try{client_release_intelligence_reconcile_user_v100($pdo,(int)($user['id']??0));}catch(Throwable $e){}
    }
    $terms=vp3_extension_notification_context_terms_v2140($contextInput);
    $out=[];
    foreach(vp3_extension_notification_rows_v2140($pdo,$user) as $row){
        $candidate=vp3_extension_notification_from_row_v2140($user,$row);
        if($candidate)$out[]=$candidate;
    }
    foreach(vp3_extension_notification_cognitive_candidates_v2140($pdo,$user,$namespace) as $candidate)$out[]=$candidate;

    $dedup=[];
    foreach($out as $candidate){
        $key=(string)$candidate['event_key'];if(isset($dedup[$key]))continue;
        $score=$terms?vp3_browser_context_score_v2130(
            (string)$candidate['title'].' '.(string)$candidate['body'].' '.(string)$candidate['source_type'],$terms
        ):0;
        $candidate['context_related']=$score>0;
        $candidate['priority']=(int)$candidate['priority']+min(12,$score);
        $dedup[$key]=$candidate;
    }
    $out=array_values($dedup);
    // Allocate the bounded interruption budget in true priority order, not
    // discovery order. A lower-value notification must never reserve a slot
    // ahead of a more important approval/risk/meeting signal.
    usort($out,static function(array $a,array $b): int {
        $x=(int)($b['priority']??0)<=>(int)($a['priority']??0);
        if($x!==0)return $x;
        return strcmp((string)($b['created_at']??''),(string)($a['created_at']??''));
    });
    if(function_exists('vp3_cognitive_attention_extension_candidate_v2410')){
        $attentionContext=function_exists('vp3_cognitive_presentation_context_v500')
            ?vp3_cognitive_presentation_context_v500($pdo,$user)
            :['interruptible'=>true,'idle_minutes'=>0];
        $filtered=[];
        foreach($out as $candidate){
            $decision=vp3_cognitive_attention_extension_candidate_v2410(
                $pdo,$user,$namespace,$candidate,
                array_replace($attentionContext,[
                    'agent_voice_enabled'=>vp3_extension_notification_voice_enabled_v2140($pdo,$user),
                    'voice_candidate_allowed'=>!empty($candidate['voice_allowed']),
                    'sensitive_for_voice'=>!empty($candidate['sensitive']),
                ])
            );
            if($decision)$filtered[]=$decision;
        }
        $out=$filtered;
    }
    usort($out,static function(array $a,array $b): int {
        $x=(int)($b['priority']??0)<=>(int)($a['priority']??0);
        if($x!==0)return $x;
        return strcmp((string)($b['created_at']??''),(string)($a['created_at']??''));
    });
    return array_slice($out,0,20);
}

function vp3_extension_notification_prune_v2140(PDO $pdo,int $userId): void
{
    $stmt=$pdo->prepare("DELETE FROM extension_notification_delivery_v2140
      WHERE owner_user_id=? AND updated_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL ".VP3_EXTENSION_NOTIFICATION_RETENTION_DAYS_V2140." DAY)");
    $stmt->execute([$userId]);
}

function vp3_extension_notification_claim_v2140(PDO $pdo,array $session,array $candidate): ?array
{
    $uid=(int)$session['user_id'];$device=(string)($session['device_id']??'');
    if($uid<1||$device==='')return null;
    $eventKey=(string)($candidate['event_key']??'');if($eventKey==='')return null;
    $token=bin2hex(random_bytes(24));$hash=hash('sha256',$token);$public=vp3_extension_uuid_v2000();
    $safe=vp3_extension_notification_candidate_public_v2140($candidate);

    $pdo->beginTransaction();
    try{
        $insert=$pdo->prepare("INSERT IGNORE INTO extension_notification_delivery_v2140
          (public_id,owner_user_id,event_key,source_kind,source_ref,notification_id,title,body,target_url,action_label,voice_text,`sensitive`)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
        $insert->execute([
            $public,$uid,$eventKey,(string)$candidate['source_kind'],(string)$candidate['source_ref'],
            $candidate['notification_id'],(string)$safe['title'],(string)$safe['body'],
            (string)$safe['target_url'],(string)$safe['action_label'],(string)$safe['voice_text'],
            !empty($safe['sensitive'])?1:0,
        ]);

        $select=$pdo->prepare("SELECT * FROM extension_notification_delivery_v2140
          WHERE owner_user_id=? AND event_key=? LIMIT 1 FOR UPDATE");
        $select->execute([$uid,$eventKey]);$row=$select->fetch();
        if(!is_array($row)){$pdo->rollBack();return null;}
        if(!empty($row['dismissed_at'])||!empty($row['visual_delivered_at'])){$pdo->commit();return null;}
        if(!empty($row['snoozed_until'])&&strtotime((string)$row['snoozed_until'])>time()){$pdo->commit();return null;}
        $claimFresh=!empty($row['claim_expires_at'])&&strtotime((string)$row['claim_expires_at'])>time();
        // A fresh claim is exclusive even to the same browser. Reissuing it
        // would rotate the one-time claim token while an earlier Chrome
        // notification is still being displayed/acknowledged.
        if($claimFresh){$pdo->commit();return null;}

        $update=$pdo->prepare("UPDATE extension_notification_delivery_v2140
          SET source_kind=?,source_ref=?,notification_id=?,title=?,body=?,target_url=?,action_label=?,voice_text=?,`sensitive`=?,
              claimed_device_id=?,claim_token_hash=?,claimed_at=UTC_TIMESTAMP(),
              claim_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ".VP3_EXTENSION_NOTIFICATION_CLAIM_SECONDS_V2140." SECOND),
              attempts=attempts+1,updated_at=UTC_TIMESTAMP()
          WHERE id=?");
        $update->execute([
            (string)$candidate['source_kind'],(string)$candidate['source_ref'],$candidate['notification_id'],
            (string)$safe['title'],(string)$safe['body'],(string)$safe['target_url'],
            (string)$safe['action_label'],(string)$safe['voice_text'],!empty($safe['sensitive'])?1:0,
            $device,$hash,(int)$row['id'],
        ]);
        $pdo->commit();
        return ['claim_token'=>$token]+vp3_extension_notification_candidate_public_v2140($candidate);
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

function vp3_extension_notification_claim_next_v2140(PDO $pdo,array $session,array $user,string $namespace,array $contextInput=[]): ?array
{
    vp3_extension_notification_prune_v2140($pdo,(int)$user['id']);
    foreach(vp3_extension_notification_candidates_v2140($pdo,$user,$namespace,$contextInput) as $candidate){
        // Claim the existing delivery ledger first, then reserve the central
        // attention budget. If another surface consumed the budget between
        // preview and claim, release this lease and continue without surfacing.
        $claimed=vp3_extension_notification_claim_v2140($pdo,$session,$candidate);
        if(!$claimed)continue;
        if(function_exists('vp3_cognitive_attention_extension_candidate_v2410')){
            $attentionContext=function_exists('vp3_cognitive_presentation_context_v500')
                ?vp3_cognitive_presentation_context_v500($pdo,$user,[
                    'agent_voice_enabled'=>vp3_extension_notification_voice_enabled_v2140($pdo,$user),
                    'voice_candidate_allowed'=>!empty($candidate['voice_allowed']),
                    'sensitive_for_voice'=>!empty($candidate['sensitive']),
                ])
                :['interruptible'=>true,'agent_voice_enabled'=>false,'voice_candidate_allowed'=>false];
            $approved=vp3_cognitive_attention_extension_candidate_v2410(
                $pdo,$user,$namespace,$candidate,$attentionContext,true
            );
            if(!$approved){
                vp3_extension_notification_release_v2140($pdo,$session,(string)$candidate['event_key'],(string)$claimed['claim_token']);
                continue;
            }
            $claimed=['claim_token'=>(string)$claimed['claim_token']]
                +vp3_extension_notification_candidate_public_v2140($approved);
        }
        return $claimed;
    }
    return null;
}

function vp3_extension_notification_candidate_map_v2140(PDO $pdo,array $user,string $namespace,array $contextInput=[]): array
{
    $map=[];
    foreach(vp3_extension_notification_candidates_v2140($pdo,$user,$namespace,$contextInput) as $candidate){
        $map[(string)$candidate['event_key']]=$candidate;
    }
    return $map;
}

function vp3_extension_notification_voice_pending_v2140(PDO $pdo,array $session,array $user,string $namespace,array $contextInput=[]): ?array
{
    if(!vp3_extension_notification_voice_enabled_v2140($pdo,$user))return null;
    $device=(string)($session['device_id']??'');if($device==='')return null;

    // Keep Chrome voice on the exact same canonical cursor/text contract as
    // Agent Chat. Only use the canonical candidate when the entire covered
    // voice window is non-sensitive; otherwise Chrome leaves that window for
    // the web Agent Voice path rather than speaking private text.
    $state=vp3_cognitive_presentation_state_row_v510($pdo,$user,$namespace);
    $digest=function_exists('vp3_cognitive_presentation_open_digest_v510')
        ? vp3_cognitive_presentation_open_digest_v510($pdo,$user,$namespace)
        : null;
    $voice=vp3_cognitive_presentation_voice_candidate_v510($pdo,$user,$state,$digest);
    if(is_array($voice)&&isset($voice['skip_through_id'])){
        vp3_cognitive_presentation_voice_delivered_v510($pdo,$user,$namespace,(int)$voice['skip_through_id']);
        $state['last_voice_notification_id']=(int)$voice['skip_through_id'];
        $voice=null;
    }
    if(is_array($voice)&&isset($voice['through_id'],$voice['message'])){
        $from=max(0,(int)($state['last_voice_notification_id']??0));
        $through=max($from,(int)$voice['through_id']);
        $rows=vp3_extension_notification_rows_v2140($pdo,$user);
        $covered=array_values(array_filter($rows,static function(array $row) use($from,$through): bool {
            $id=max(0,(int)($row['id']??0));
            return $id>$from&&$id<=$through
                &&function_exists('vp3_cognitive_presentation_voice_allowed_type_v510')
                &&vp3_cognitive_presentation_voice_allowed_type_v510($row);
        }));
        $safe=$covered!==[];
        foreach($covered as $row){
            $probe=[
                'type'=>(string)($row['type']??''),
                'source_type'=>(string)($row['source_type']??''),
                'title'=>(string)($row['title']??''),
                'body'=>(string)($row['body']??''),
            ];
            if(vp3_extension_notification_sensitive_v2140($probe)){$safe=false;break;}
        }
        if($safe){
            $stmt=$pdo->prepare("SELECT * FROM extension_notification_delivery_v2140
              WHERE owner_user_id=? AND claimed_device_id=? AND source_kind='notification'
                AND notification_id>? AND notification_id<=?
                AND visual_delivered_at IS NOT NULL AND voice_delivered_at IS NULL
                AND dismissed_at IS NULL AND (voice_retry_after IS NULL OR voice_retry_after<=UTC_TIMESTAMP())
              ORDER BY notification_id DESC,id DESC LIMIT 1");
            $stmt->execute([(int)$user['id'],$device,$from,$through]);
            $row=$stmt->fetch();
            if(is_array($row)){
                $pdo->prepare("UPDATE extension_notification_delivery_v2140
                  SET voice_through_notification_id=?,updated_at=UTC_TIMESTAMP() WHERE id=?")
                  ->execute([$through,(int)$row['id']]);
                return [
                    'event_key'=>(string)$row['event_key'],
                    'text'=>vp3_cognitive_text_v500($voice['message']??'',360),
                    'kind'=>'notification',
                    'through_id'=>$through,
                    'context_related'=>false,
                ];
            }
        }
    }

    // Cognitive-only attention events have no canonical notification cursor.
    $stmt=$pdo->prepare("SELECT event_key FROM extension_notification_delivery_v2140
      WHERE owner_user_id=? AND claimed_device_id=? AND source_kind='cognitive'
        AND visual_delivered_at IS NOT NULL AND voice_delivered_at IS NULL AND dismissed_at IS NULL
        AND (voice_retry_after IS NULL OR voice_retry_after<=UTC_TIMESTAMP())
      ORDER BY visual_delivered_at ASC,id ASC LIMIT 8");
    $stmt->execute([(int)$user['id'],$device]);
    $keys=array_values(array_filter(array_map('strval',$stmt->fetchAll(PDO::FETCH_COLUMN)?:[])));
    if(!$keys)return null;
    $map=vp3_extension_notification_candidate_map_v2140($pdo,$user,$namespace,$contextInput);
    foreach($keys as $key){
        $candidate=$map[$key]??null;
        if(!$candidate||empty($candidate['voice_allowed'])||!empty($candidate['sensitive']))continue;
        $public=vp3_extension_notification_candidate_public_v2140($candidate);
        return [
            'event_key'=>$key,
            'text'=>(string)$public['voice_text'],
            'kind'=>'cognitive',
            'through_id'=>0,
            'context_related'=>!empty($candidate['context_related']),
        ];
    }
    return null;
}

function vp3_extension_notification_delivery_row_v2140(PDO $pdo,int $uid,string $eventKey): ?array
{
    $stmt=$pdo->prepare('SELECT * FROM extension_notification_delivery_v2140 WHERE owner_user_id=? AND event_key=? LIMIT 1');
    $stmt->execute([$uid,$eventKey]);$row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function vp3_extension_notification_visual_delivered_v2140(
    PDO $pdo,array $session,string $eventKey,string $claimToken,?array $user=null,string $namespace='system'
): bool {
    $uid=(int)$session['user_id'];$device=(string)($session['device_id']??'');
    if($uid<1||$device===''||!preg_match('/^[a-f0-9]{48}$/',$claimToken))return false;
    $stmt=$pdo->prepare("UPDATE extension_notification_delivery_v2140
      SET visual_delivered_at=COALESCE(visual_delivered_at,UTC_TIMESTAMP()),claim_token_hash=NULL,
          claim_expires_at=NULL,updated_at=UTC_TIMESTAMP()
      WHERE owner_user_id=? AND event_key=? AND claimed_device_id=?
        AND claim_token_hash=? AND visual_delivered_at IS NULL AND dismissed_at IS NULL");
    $stmt->execute([$uid,$eventKey,$device,hash('sha256',$claimToken)]);
    $changed=$stmt->rowCount()>0;
    if($changed&&$user&&function_exists('vp3_cognitive_attention_mark_delivered_v2410')){
        vp3_cognitive_attention_mark_delivered_v2410($pdo,$user,$namespace,$eventKey);
    }
    return $changed;
}

function vp3_extension_notification_release_v2140(PDO $pdo,array $session,string $eventKey,string $claimToken): void
{
    $uid=(int)$session['user_id'];$device=(string)($session['device_id']??'');
    if($uid<1||$device===''||!preg_match('/^[a-f0-9]{48}$/',$claimToken))return;
    $stmt=$pdo->prepare("UPDATE extension_notification_delivery_v2140
      SET claimed_device_id=NULL,claim_token_hash=NULL,claimed_at=NULL,claim_expires_at=NULL,updated_at=UTC_TIMESTAMP()
      WHERE owner_user_id=? AND event_key=? AND claimed_device_id=? AND claim_token_hash=? AND visual_delivered_at IS NULL");
    $stmt->execute([$uid,$eventKey,$device,hash('sha256',$claimToken)]);
}

function vp3_extension_notification_voice_result_v2140(PDO $pdo,array $session,array $user,string $namespace,string $eventKey,bool $delivered): void
{
    $uid=(int)$session['user_id'];$device=(string)($session['device_id']??'');
    if($uid<1||$device==='')return;
    $row=vp3_extension_notification_delivery_row_v2140($pdo,$uid,$eventKey);
    if(!$row||trim((string)$row['claimed_device_id'])!==$device||empty($row['visual_delivered_at']))return;
    if($delivered){
        $ownsTransaction=!$pdo->inTransaction();
        if($ownsTransaction)$pdo->beginTransaction();
        try{
            $through=max(0,(int)($row['voice_through_notification_id']??0));
            if((string)($row['source_kind']??'')==='notification'&&$through>0
                &&function_exists('vp3_cognitive_presentation_voice_delivered_v510')){
                // Advance the shared Agent Chat/Chrome voice cursor in the same
                // transaction as the Browser delivery ledger. Either both
                // surfaces agree speech completed or neither one advances.
                vp3_cognitive_presentation_voice_delivered_v510($pdo,$user,$namespace,$through);
                $pdo->prepare("UPDATE extension_notification_delivery_v2140
                  SET voice_delivered_at=COALESCE(voice_delivered_at,UTC_TIMESTAMP()),voice_retry_after=NULL,updated_at=UTC_TIMESTAMP()
                  WHERE owner_user_id=? AND source_kind='notification'
                    AND notification_id IS NOT NULL AND notification_id<=?
                    AND visual_delivered_at IS NOT NULL")
                  ->execute([$uid,$through]);
            }else{
                $pdo->prepare("UPDATE extension_notification_delivery_v2140
                  SET voice_delivered_at=COALESCE(voice_delivered_at,UTC_TIMESTAMP()),voice_retry_after=NULL,updated_at=UTC_TIMESTAMP()
                  WHERE id=? AND voice_delivered_at IS NULL")
                  ->execute([(int)$row['id']]);
            }
            if($ownsTransaction)$pdo->commit();
        }catch(Throwable $e){
            if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();
            throw $e;
        }
    }else{
        $pdo->prepare("UPDATE extension_notification_delivery_v2140
          SET voice_retry_after=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE),updated_at=UTC_TIMESTAMP()
          WHERE id=? AND voice_delivered_at IS NULL")->execute([(int)$row['id']]);
    }
}

function vp3_extension_notification_open_v2140(PDO $pdo,array $session,string $eventKey): ?string
{
    $uid=(int)$session['user_id'];$device=(string)($session['device_id']??'');
    $row=vp3_extension_notification_delivery_row_v2140($pdo,$uid,$eventKey);
    if(!$row||$device===''||trim((string)$row['claimed_device_id'])!==$device)return null;
    $pdo->prepare("UPDATE extension_notification_delivery_v2140
      SET opened_at=COALESCE(opened_at,UTC_TIMESTAMP()),dismissed_at=COALESCE(dismissed_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP()
      WHERE id=?")->execute([(int)$row['id']]);
    if(!empty($row['notification_id'])&&function_exists('mark_notification_read')){
        mark_notification_read((int)$row['notification_id'],$uid);
    }
    return vp3_extension_notification_internal_url_v2140($row['target_url']??'')?:'/chat.php';
}

function vp3_extension_notification_dismiss_v2140(
    PDO $pdo,array $session,string $eventKey,?array $user=null,string $namespace='system'
): void {
    $uid=(int)$session['user_id'];$device=(string)($session['device_id']??'');
    if($uid<1||$device==='')return;
    $stmt=$pdo->prepare("UPDATE extension_notification_delivery_v2140
      SET dismissed_at=COALESCE(dismissed_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP()
      WHERE owner_user_id=? AND event_key=? AND claimed_device_id=?");
    $stmt->execute([$uid,$eventKey,$device]);
    if($stmt->rowCount()>0&&$user&&function_exists('vp3_cognitive_attention_mark_dismissed_v2410')){
        vp3_cognitive_attention_mark_dismissed_v2410($pdo,$user,$namespace,$eventKey);
    }
}

function vp3_extension_notification_snooze_v2140(PDO $pdo,array $session,string $eventKey): void
{
    $uid=(int)$session['user_id'];$device=(string)($session['device_id']??'');
    if($uid<1||$device==='')return;
    $pdo->prepare("UPDATE extension_notification_delivery_v2140
      SET snoozed_until=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ".VP3_EXTENSION_NOTIFICATION_SNOOZE_MINUTES_V2140." MINUTE),
          visual_delivered_at=NULL,claimed_device_id=NULL,claim_token_hash=NULL,claimed_at=NULL,claim_expires_at=NULL,
          updated_at=UTC_TIMESTAMP()
      WHERE owner_user_id=? AND event_key=? AND claimed_device_id=? AND dismissed_at IS NULL")
      ->execute([$uid,$eventKey,$device]);
}
