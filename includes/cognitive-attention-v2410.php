<?php
declare(strict_types=1);

/**
 * VP3 Cognitive Runtime v24.10 — Unified Attention Engine.
 *
 * One policy for interruption ranking, cooldowns and budget across existing
 * web/voice/Browser Companion delivery mechanisms. This file does not create a
 * second notification queue or presentation transport.
 */
const VP3_COGNITIVE_ATTENTION_V2410='vp3-cognitive-attention-v2410-20260922';
const VP3_COGNITIVE_ATTENTION_WINDOW_MINUTES_V2410=30;
const VP3_COGNITIVE_ATTENTION_MAX_INTERRUPTS_V2410=3;
const VP3_COGNITIVE_ATTENTION_REPEAT_COOLDOWN_MINUTES_V2410=30;
const VP3_COGNITIVE_ATTENTION_CRITICAL_SCORE_V2410=90;
const VP3_COGNITIVE_ATTENTION_URGENT_SCORE_V2410=80;
const VP3_COGNITIVE_ATTENTION_ELEVATED_SCORE_V2410=65;
const VP3_COGNITIVE_ATTENTION_AMBIENT_SCORE_V2410=45;

function vp3_cognitive_attention_owns_policy_v2410(): bool{return true;}

function vp3_cognitive_attention_schema_ready_v2410(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)$pdo
        && table_exists('cognitive_attention_receipts_v2410')
        && column_exists('cognitive_attention_receipts_v2410','signal_fingerprint')
        && column_exists('cognitive_attention_receipts_v2410','selected_surface')
        && column_exists('cognitive_attention_receipts_v2410','status');
}

function vp3_cognitive_attention_ensure_schema_v2410(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(!table_exists('users'))throw new RuntimeException('VP3 users must exist before Cognitive Attention.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS cognitive_attention_receipts_v2410 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      public_id CHAR(36) NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      agent_namespace VARCHAR(80) NOT NULL DEFAULT 'system',
      signal_key VARCHAR(190) NOT NULL DEFAULT '',
      signal_fingerprint CHAR(64) NOT NULL,
      source_kind VARCHAR(80) NOT NULL DEFAULT '',
      category VARCHAR(40) NOT NULL DEFAULT '',
      attention_score DECIMAL(6,3) NOT NULL DEFAULT 0,
      attention_band VARCHAR(24) NOT NULL DEFAULT 'quiet',
      requested_surface VARCHAR(32) NOT NULL DEFAULT '',
      selected_surface VARCHAR(32) NOT NULL DEFAULT 'memory',
      paired_surface VARCHAR(32) NOT NULL DEFAULT '',
      reason_code VARCHAR(80) NOT NULL DEFAULT '',
      voice_allowed TINYINT(1) NOT NULL DEFAULT 0,
      sensitive TINYINT(1) NOT NULL DEFAULT 0,
      interruptive TINYINT(1) NOT NULL DEFAULT 0,
      status VARCHAR(24) NOT NULL DEFAULT 'planned',
      delivered_at DATETIME NULL,
      dismissed_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uq_cognitive_attention_public_v2410 (public_id),
      UNIQUE KEY uq_cognitive_attention_signal_v2410 (owner_user_id,agent_namespace,signal_fingerprint),
      INDEX idx_cognitive_attention_budget_v2410 (owner_user_id,agent_namespace,interruptive,status,created_at,id),
      INDEX idx_cognitive_attention_key_v2410 (owner_user_id,agent_namespace,signal_key,created_at,id),
      CONSTRAINT fk_cognitive_attention_owner_v2410 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function vp3_cognitive_attention_score_value_v2410(mixed $value): float
{
    $v=is_numeric($value)?(float)$value:0.0;
    if($v>1.0)$v=$v/100.0;
    return max(0.0,min(1.0,$v));
}

function vp3_cognitive_attention_signal_v2410(array $signal): array
{
    $explicitPriority=max(0,min(100,(int)($signal['priority']??0)));
    $confidence=vp3_cognitive_attention_score_value_v2410($signal['confidence']??($explicitPriority>0?.85:0));
    $novelty=vp3_cognitive_attention_score_value_v2410($signal['novelty']??.5);
    $urgency=vp3_cognitive_attention_score_value_v2410($signal['urgency']??($explicitPriority/100));
    $impact=vp3_cognitive_attention_score_value_v2410($signal['impact']??($explicitPriority/100));
    $goal=vp3_cognitive_attention_score_value_v2410($signal['goal_relevance']??.5);
    $requested=(string)($signal['presentation_recommendation']??$signal['requested_surface']??'memory');
    if(!in_array($requested,vp3_cognitive_surfaces_v500(),true))$requested='memory';
    $category=vp3_cognitive_id_v500($signal['category']??'fact_summary',40)?:'fact_summary';
    $score=($urgency*32)+($impact*25)+($goal*18)+($confidence*15)+($novelty*10);
    if($explicitPriority>0)$score=max($score,(float)$explicitPriority);
    if(!empty($signal['requires_user_response']))$score=max($score,92);
    if($requested==='voice_announce')$score+=3;
    elseif($requested==='notification')$score+=2;
    $score=max(0,min(100,$score));
    $band=$score>=VP3_COGNITIVE_ATTENTION_CRITICAL_SCORE_V2410?'critical'
        :($score>=VP3_COGNITIVE_ATTENTION_URGENT_SCORE_V2410?'urgent'
        :($score>=VP3_COGNITIVE_ATTENTION_ELEVATED_SCORE_V2410?'elevated'
        :($score>=VP3_COGNITIVE_ATTENTION_AMBIENT_SCORE_V2410?'ambient':'quiet')));
    return [
        'key'=>mb_strimwidth(trim((string)($signal['key']??$signal['event_key']??$signal['observation_id']??'')),0,190,''),
        'fingerprint'=>strtolower(trim((string)($signal['fingerprint']??''))),
        'source'=>vp3_cognitive_id_v500($signal['source']??$signal['source_kind']??'',80),
        'category'=>$category,
        'title'=>vp3_cognitive_text_v500($signal['title']??'',190),
        'reason'=>vp3_cognitive_text_v500($signal['reason']??$signal['body']??'',500),
        'confidence'=>$confidence,'novelty'=>$novelty,'urgency'=>$urgency,'impact'=>$impact,'goal_relevance'=>$goal,
        'score'=>round($score,3),'band'=>$band,'requested_surface'=>$requested,
        'voice_safe_summary'=>vp3_cognitive_text_v500($signal['voice_safe_summary']??$signal['voice_text']??'',500),
        'valid_until'=>(string)($signal['valid_until']??''),
        'direct_user_request'=>!empty($signal['direct_user_request']),
        'requires_user_response'=>!empty($signal['requires_user_response']),
        'sensitive'=>!empty($signal['sensitive'])||!empty($signal['sensitive_for_voice']),
        'updated_at'=>(string)($signal['updated_at']??$signal['created_at']??''),
    ];
}

function vp3_cognitive_attention_context_v2410(array $context=[]): array
{
    return array_replace([
        'direct_user_request'=>false,
        'requires_user_response'=>false,
        'agent_voice_enabled'=>false,
        'interruptible'=>true,
        'quiet_hours'=>false,
        'focus_mode'=>false,
        'sensitive_for_voice'=>false,
        'idle_minutes'=>0,
        'already_presented'=>false,
        'attention_budget_remaining'=>true,
        'voice_candidate_allowed'=>false,
    ],$context);
}

function vp3_cognitive_attention_decide_v2410(array $signal,array $context=[]): array
{
    $s=vp3_cognitive_attention_signal_v2410($signal);
    $ctx=vp3_cognitive_attention_context_v2410($context);
    if(!empty($s['direct_user_request']))$ctx['direct_user_request']=true;
    if(!empty($s['requires_user_response']))$ctx['requires_user_response']=true;
    if(!empty($s['sensitive']))$ctx['sensitive_for_voice']=true;

    $base=[
        'attention_score'=>(float)$s['score'],'attention_band'=>(string)$s['band'],
        'requested_surface'=>(string)$s['requested_surface'],
        'interruptive'=>false,'budget_bypass'=>false,
    ];
    if($s['valid_until']!==''&&strtotime($s['valid_until'])!==false&&strtotime($s['valid_until'])<time()){
        return $base+['surface'=>'none','paired_surface'=>'','voice'=>false,'reason_code'=>'expired'];
    }
    if(!empty($ctx['direct_user_request'])){
        return $base+['surface'=>'chat_response','paired_surface'=>'','voice'=>false,'reason_code'=>'direct_user_request'];
    }

    $score=(float)$s['score'];$critical=$score>=VP3_COGNITIVE_ATTENTION_CRITICAL_SCORE_V2410;
    $budget=!empty($ctx['attention_budget_remaining']);
    $interruptible=!empty($ctx['interruptible']);
    $quiet=!empty($ctx['quiet_hours'])||!empty($ctx['focus_mode']);
    if(!empty($ctx['already_presented'])&&!$critical){
        return $base+['surface'=>'memory','paired_surface'=>'','voice'=>false,'reason_code'=>'already_presented'];
    }

    if(!empty($ctx['requires_user_response'])){
        if($quiet&&!$critical){
            return $base+['surface'=>'brief','paired_surface'=>'','voice'=>false,'reason_code'=>'response_deferred_by_focus'];
        }
        if(!$budget&&!$critical){
            return $base+['surface'=>'brief','paired_surface'=>'','voice'=>false,'reason_code'=>'attention_budget_exhausted'];
        }
        return array_replace($base,[
            'surface'=>'ask_user','paired_surface'=>$interruptible?'notification':'',
            'voice'=>false,'reason_code'=>$critical?'critical_user_response_required':'user_response_required',
            'interruptive'=>true,'budget_bypass'=>$critical,
        ]);
    }

    $voiceEligible=!empty($ctx['voice_candidate_allowed'])&&!empty($ctx['agent_voice_enabled'])
        &&$interruptible&&!$quiet&&empty($ctx['sensitive_for_voice'])
        &&$s['voice_safe_summary']!==''&&($budget||$critical);

    if($quiet){
        if($critical&&$interruptible){
            return array_replace($base,['surface'=>'notification','paired_surface'=>'','voice'=>false,'reason_code'=>'critical_focus_bypass','interruptive'=>true,'budget_bypass'=>true]);
        }
        return $base+['surface'=>$score>=VP3_COGNITIVE_ATTENTION_ELEVATED_SCORE_V2410?'brief':'memory','paired_surface'=>'','voice'=>false,'reason_code'=>'focus_or_quiet_hours'];
    }

    if(!$interruptible){
        return $base+['surface'=>$critical?'brief':'memory','paired_surface'=>'','voice'=>false,'reason_code'=>'not_interruptible'];
    }

    if(!$budget&&!$critical){
        return $base+['surface'=>$score>=VP3_COGNITIVE_ATTENTION_ELEVATED_SCORE_V2410?'brief':'memory','paired_surface'=>'','voice'=>false,'reason_code'=>'attention_budget_exhausted'];
    }

    if($critical){
        if($voiceEligible&&((string)$s['requested_surface']==='voice_announce'||in_array($s['category'],['risk','commitment','unanswered_question'],true))){
            return array_replace($base,['surface'=>'voice_announce','paired_surface'=>'notification','voice'=>true,'reason_code'=>'critical_voice','interruptive'=>true,'budget_bypass'=>!$budget]);
        }
        return array_replace($base,['surface'=>'notification','paired_surface'=>'','voice'=>false,'reason_code'=>'critical_attention','interruptive'=>true,'budget_bypass'=>!$budget]);
    }
    if($score>=VP3_COGNITIVE_ATTENTION_URGENT_SCORE_V2410){
        if($voiceEligible&&(string)$s['requested_surface']==='voice_announce'){
            return array_replace($base,['surface'=>'voice_announce','paired_surface'=>'notification','voice'=>true,'reason_code'=>'urgent_voice','interruptive'=>true]);
        }
        return array_replace($base,['surface'=>'notification','paired_surface'=>'','voice'=>false,'reason_code'=>'urgent_attention','interruptive'=>true]);
    }

    $idle=max(0,(int)$ctx['idle_minutes']);
    if($idle>=60&&$score>=55){
        return $base+['surface'=>'away_digest','paired_surface'=>'','voice'=>false,'reason_code'=>'return_digest'];
    }
    if($idle>=30&&$score>=VP3_COGNITIVE_ATTENTION_ELEVATED_SCORE_V2410){
        return $base+['surface'=>'away_digest','paired_surface'=>'','voice'=>false,'reason_code'=>'attention_return_digest'];
    }
    if($score>=VP3_COGNITIVE_ATTENTION_ELEVATED_SCORE_V2410){
        return $base+['surface'=>'brief','paired_surface'=>'','voice'=>false,'reason_code'=>'elevated_brief'];
    }
    if($score>=VP3_COGNITIVE_ATTENTION_AMBIENT_SCORE_V2410){
        return $base+['surface'=>'memory','paired_surface'=>'','voice'=>false,'reason_code'=>'ambient_memory'];
    }
    if((float)$s['confidence']>=.60){
        return $base+['surface'=>'memory','paired_surface'=>'','voice'=>false,'reason_code'=>'confident_memory'];
    }
    return $base+['surface'=>'none','paired_surface'=>'','voice'=>false,'reason_code'=>'below_noise_floor'];
}

function vp3_cognitive_attention_fingerprint_v2410(array $signal): string
{
    $s=vp3_cognitive_attention_signal_v2410($signal);
    if(preg_match('/^[a-f0-9]{64}$/',(string)$s['fingerprint']))return (string)$s['fingerprint'];
    return hash('sha256',vp3_cognitive_json_v500([
        $s['key'],$s['source'],$s['category'],$s['title'],$s['reason'],
        round((float)$s['score'],1),$s['requested_surface'],$s['updated_at'],
    ]));
}

function vp3_cognitive_attention_budget_v2410(PDO $pdo,array $user,string $namespace): array
{
    $uid=(int)($user['id']??0);if($uid<1||!vp3_cognitive_attention_schema_ready_v2410($pdo))return ['used'=>0,'limit'=>VP3_COGNITIVE_ATTENTION_MAX_INTERRUPTS_V2410,'remaining'=>true];
    $namespace=vp3_cognitive_validate_namespace_v500($pdo,$user,$namespace);
    $stmt=$pdo->prepare("SELECT COUNT(*) FROM cognitive_attention_receipts_v2410
      WHERE owner_user_id=? AND agent_namespace=? AND interruptive=1
        AND status IN ('planned','delivered')
        AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL ".VP3_COGNITIVE_ATTENTION_WINDOW_MINUTES_V2410." MINUTE)");
    $stmt->execute([$uid,$namespace]);$used=(int)$stmt->fetchColumn();
    return ['used'=>$used,'limit'=>VP3_COGNITIVE_ATTENTION_MAX_INTERRUPTS_V2410,'remaining'=>$used<VP3_COGNITIVE_ATTENTION_MAX_INTERRUPTS_V2410];
}

function vp3_cognitive_attention_recent_key_v2410(PDO $pdo,int $uid,string $namespace,string $key): ?array
{
    if($key==='')return null;
    $stmt=$pdo->prepare("SELECT * FROM cognitive_attention_receipts_v2410
      WHERE owner_user_id=? AND agent_namespace=? AND signal_key=?
        AND interruptive=1 AND status IN ('planned','delivered')
        AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL ".VP3_COGNITIVE_ATTENTION_REPEAT_COOLDOWN_MINUTES_V2410." MINUTE)
      ORDER BY id DESC LIMIT 1");
    $stmt->execute([$uid,$namespace,$key]);$row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function vp3_cognitive_attention_receipt_public_v2410(array $row): array
{
    return [
        'id'=>(string)($row['public_id']??''),'signal_key'=>(string)($row['signal_key']??''),
        'score'=>(float)($row['attention_score']??0),'band'=>(string)($row['attention_band']??''),
        'surface'=>(string)($row['selected_surface']??'memory'),'paired_surface'=>(string)($row['paired_surface']??''),
        'voice'=>!empty($row['voice_allowed']),'interruptive'=>!empty($row['interruptive']),
        'reason_code'=>(string)($row['reason_code']??''),'status'=>(string)($row['status']??'planned'),
        'created_at'=>(string)($row['created_at']??''),'delivered_at'=>(string)($row['delivered_at']??''),
    ];
}

function vp3_cognitive_attention_arbitrate_v2410(
    PDO $pdo,array $user,string $namespace,array $signal,array $context=[]
): array {
    $uid=(int)($user['id']??0);if($uid<1)throw new RuntimeException('A signed-in VP3 user is required.');
    if(!vp3_cognitive_attention_schema_ready_v2410($pdo))return vp3_cognitive_attention_decide_v2410($signal,$context);
    $namespace=vp3_cognitive_validate_namespace_v500($pdo,$user,$namespace);
    $s=vp3_cognitive_attention_signal_v2410($signal);
    $fingerprint=vp3_cognitive_attention_fingerprint_v2410($signal);
    $existing=$pdo->prepare('SELECT * FROM cognitive_attention_receipts_v2410 WHERE owner_user_id=? AND agent_namespace=? AND signal_fingerprint=? LIMIT 1');
    $existing->execute([$uid,$namespace,$fingerprint]);$row=$existing->fetch();
    if(is_array($row))return vp3_cognitive_attention_receipt_public_v2410($row);

    $budget=vp3_cognitive_attention_budget_v2410($pdo,$user,$namespace);
    $ctx=vp3_cognitive_attention_context_v2410($context);
    $ctx['attention_budget_remaining']=!empty($budget['remaining']);
    $recent=vp3_cognitive_attention_recent_key_v2410($pdo,$uid,$namespace,(string)$s['key']);
    if($recent)$ctx['already_presented']=true;
    $decision=vp3_cognitive_attention_decide_v2410($signal,$ctx);
    $interruptive=!empty($decision['interruptive']);
    $status=$interruptive?'planned':(in_array((string)$decision['surface'],['none','memory'],true)?'suppressed':'deferred');
    $public=vp3_cognitive_uuid_v500();
    $stmt=$pdo->prepare("INSERT IGNORE INTO cognitive_attention_receipts_v2410
      (public_id,owner_user_id,agent_namespace,signal_key,signal_fingerprint,source_kind,category,attention_score,attention_band,requested_surface,selected_surface,paired_surface,reason_code,voice_allowed,sensitive,interruptive,status)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $public,$uid,$namespace,(string)$s['key'],$fingerprint,(string)$s['source'],(string)$s['category'],
        (float)$decision['attention_score'],(string)$decision['attention_band'],(string)$decision['requested_surface'],
        (string)$decision['surface'],(string)$decision['paired_surface'],(string)$decision['reason_code'],
        !empty($decision['voice'])?1:0,!empty($s['sensitive'])?1:0,$interruptive?1:0,$status,
    ]);
    if($stmt->rowCount()<1){
        $existing->execute([$uid,$namespace,$fingerprint]);$row=$existing->fetch();
        if(is_array($row))return vp3_cognitive_attention_receipt_public_v2410($row);
    }
    $get=$pdo->prepare('SELECT * FROM cognitive_attention_receipts_v2410 WHERE public_id=? AND owner_user_id=? LIMIT 1');
    $get->execute([$public,$uid]);$row=$get->fetch();
    return is_array($row)?vp3_cognitive_attention_receipt_public_v2410($row):$decision;
}

function vp3_cognitive_attention_mark_delivered_v2410(PDO $pdo,array $user,string $namespace,string $signalKey): void
{
    $uid=(int)($user['id']??0);if($uid<1||$signalKey===''||!vp3_cognitive_attention_schema_ready_v2410($pdo))return;
    $namespace=vp3_cognitive_validate_namespace_v500($pdo,$user,$namespace);
    $pdo->prepare("UPDATE cognitive_attention_receipts_v2410 SET status='delivered',delivered_at=COALESCE(delivered_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP()
      WHERE owner_user_id=? AND agent_namespace=? AND signal_key=? AND interruptive=1 AND status='planned'
      ORDER BY id DESC LIMIT 1")->execute([$uid,$namespace,$signalKey]);
}

function vp3_cognitive_attention_mark_dismissed_v2410(PDO $pdo,array $user,string $namespace,string $signalKey): void
{
    $uid=(int)($user['id']??0);if($uid<1||$signalKey===''||!vp3_cognitive_attention_schema_ready_v2410($pdo))return;
    $namespace=vp3_cognitive_validate_namespace_v500($pdo,$user,$namespace);
    $pdo->prepare("UPDATE cognitive_attention_receipts_v2410 SET status='dismissed',dismissed_at=COALESCE(dismissed_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP()
      WHERE owner_user_id=? AND agent_namespace=? AND signal_key=? AND status IN ('planned','delivered')
      ORDER BY id DESC LIMIT 1")->execute([$uid,$namespace,$signalKey]);
}

function vp3_cognitive_attention_notification_signal_v2410(array $candidate): array
{
    $rawPriority=max(0,min(100,(int)($candidate['priority']??0)));
    $type=(string)($candidate['type']??$candidate['card_type']??'');
    $title=(string)($candidate['title']??'');
    $text=strtolower($type.' '.$title.' '.(string)($candidate['source_type']??''));
    $requires=(bool)preg_match('/(?:approval|confirm|permission|response|required)/i',$text);
    if(preg_match('/(?:security|failed|failure|error|critical|risk)/i',$text))$priority=92;
    elseif($requires)$priority=87;
    elseif(preg_match('/(?:meeting|appointment|booking|calendar)/i',$text))$priority=82;
    else $priority=max(65,min(85,(int)round($rawPriority*.85)));
    return [
        'key'=>(string)($candidate['event_key']??''),
        'source'=>(string)($candidate['source_kind']??$candidate['source_type']??'notification'),
        'category'=>(bool)preg_match('/(?:risk|security|failed|failure|error|critical)/i',$text)?'risk':'recommendation',
        'title'=>$title,
        'reason'=>(string)($candidate['body']??''),
        'priority'=>$priority,
        'confidence'=>.90,'novelty'=>.70,'urgency'=>$priority/100,'impact'=>$priority/100,'goal_relevance'=>.60,
        'presentation_recommendation'=>!empty($candidate['voice_allowed'])?'voice_announce':'notification',
        'voice_safe_summary'=>(string)($candidate['voice_text']??''),
        'requires_user_response'=>$requires,
        'sensitive'=>!empty($candidate['sensitive']),
        'updated_at'=>(string)($candidate['created_at']??''),
    ];
}

function vp3_cognitive_attention_notification_row_signal_v2410(array $user,array $row,int $count=1): array
{
    $attention=function_exists('notification_requires_attention')&&notification_requires_attention($row);
    $voiceAllowed=function_exists('vp3_cognitive_presentation_voice_allowed_type_v510')
        &&vp3_cognitive_presentation_voice_allowed_type_v510($row);
    $candidate=[
        'event_key'=>'notification:'.max(0,(int)($row['id']??0)),
        'source_kind'=>'notification',
        'source_type'=>(string)($row['source_type']??''),
        'type'=>(string)($row['type']??''),
        'title'=>(string)($row['title']??'VP3 update'),
        'body'=>(string)($row['body']??''),
        'created_at'=>(string)($row['created_at']??''),
        'priority'=>$attention?100:70,
        'voice_allowed'=>$voiceAllowed,
        'voice_text'=>$voiceAllowed&&function_exists('vp3_cognitive_presentation_voice_text_v510')
            ?vp3_cognitive_presentation_voice_text_v510($user,$row,$count)
            :'',
        'sensitive'=>function_exists('vp3_cognitive_presentation_voice_sensitive_v510')
            ?vp3_cognitive_presentation_voice_sensitive_v510($row)
            :false,
    ];
    return vp3_cognitive_attention_notification_signal_v2410($candidate);
}

function vp3_cognitive_attention_extension_candidate_v2410(
    PDO $pdo,array $user,string $namespace,array $candidate,array $context=[]
): ?array {
    $signal=vp3_cognitive_attention_notification_signal_v2410($candidate);
    $context=array_replace([
        'agent_voice_enabled'=>!empty($candidate['voice_allowed']),
        'voice_candidate_allowed'=>!empty($candidate['voice_allowed']),
        'sensitive_for_voice'=>!empty($candidate['sensitive']),
    ],$context);
    $decision=vp3_cognitive_attention_arbitrate_v2410($pdo,$user,$namespace,$signal,$context);
    if(!in_array((string)($decision['surface']??''),['notification','voice_announce','ask_user'],true))return null;
    $candidate['attention_score']=(float)($decision['score']??$decision['attention_score']??0);
    $candidate['attention_band']=(string)($decision['band']??$decision['attention_band']??'');
    $candidate['attention_surface']=(string)($decision['surface']??'notification');
    $candidate['attention_reason']=(string)($decision['reason_code']??'');
    $candidate['priority']=max((int)($candidate['priority']??0),(int)round((float)$candidate['attention_score']));
    $candidate['voice_allowed']=!empty($decision['voice'])&&!empty($candidate['voice_allowed'])&&!empty($candidate['voice_text']);
    return $candidate;
}

function vp3_cognitive_attention_status_v2410(PDO $pdo,array $user,string $namespace='system'): array
{
    $uid=(int)($user['id']??0);if($uid<1||!vp3_cognitive_attention_schema_ready_v2410($pdo))return ['ready'=>false];
    $namespace=vp3_cognitive_validate_namespace_v500($pdo,$user,$namespace);
    $budget=vp3_cognitive_attention_budget_v2410($pdo,$user,$namespace);
    $stmt=$pdo->prepare("SELECT selected_surface,status,COUNT(*) c FROM cognitive_attention_receipts_v2410
      WHERE owner_user_id=? AND agent_namespace=? AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 24 HOUR)
      GROUP BY selected_surface,status");
    $stmt->execute([$uid,$namespace]);$counts=[];
    foreach($stmt->fetchAll()?:[] as $row)$counts[(string)$row['selected_surface'].':'.(string)$row['status']]=(int)$row['c'];
    return ['ready'=>true,'build'=>VP3_COGNITIVE_ATTENTION_V2410,'namespace'=>$namespace,'budget'=>$budget,'counts_24h'=>$counts];
}
