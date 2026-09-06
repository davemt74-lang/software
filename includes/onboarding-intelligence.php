<?php
declare(strict_types=1);

/**
 * Persistent onboarding + trial-conversion intelligence.
 *
 * This extends the existing user_agent_preferences record. It does not create
 * a parallel onboarding identity or entitlement system.
 */
const VP3_ONBOARDING_INTELLIGENCE_BUILD='onboarding-intelligence-20260906-v2';

function onboarding_intelligence_schema_ready(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)$pdo
        && table_exists('user_agent_preferences')
        && column_exists('user_agent_preferences','voice_preference')
        && column_exists('user_agent_preferences','onboarding_step')
        && column_exists('user_agent_preferences','onboarding_draft_json')
        && column_exists('user_agent_preferences','feature_interest_json')
        && column_exists('user_agent_preferences','last_trial_notice_threshold')
        && column_exists('user_agent_preferences','last_trial_notice_at');
}

function onboarding_intelligence_ensure_schema(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(!table_exists('user_agent_preferences'))user_agent_system_ensure_schema_v236($pdo);
    $adds=[
        'voice_preference'=>"ALTER TABLE user_agent_preferences ADD COLUMN voice_preference VARCHAR(20) NULL AFTER onboarding_dismissed",
        'onboarding_step'=>"ALTER TABLE user_agent_preferences ADD COLUMN onboarding_step VARCHAR(40) NOT NULL DEFAULT 'voice' AFTER voice_preference",
        'onboarding_draft_json'=>"ALTER TABLE user_agent_preferences ADD COLUMN onboarding_draft_json LONGTEXT NULL AFTER onboarding_step",
        'feature_interest_json'=>"ALTER TABLE user_agent_preferences ADD COLUMN feature_interest_json LONGTEXT NULL AFTER onboarding_draft_json",
        'last_trial_notice_threshold'=>"ALTER TABLE user_agent_preferences ADD COLUMN last_trial_notice_threshold TINYINT UNSIGNED NULL AFTER feature_interest_json",
        'last_trial_notice_at'=>"ALTER TABLE user_agent_preferences ADD COLUMN last_trial_notice_at DATETIME NULL AFTER last_trial_notice_threshold",
    ];
    foreach($adds as $column=>$sql){if(!column_exists('user_agent_preferences',$column))$pdo->exec($sql);}
}

function onboarding_intelligence_default_preferences(int $userId): array
{
    return [
        'user_id'=>$userId,'onboarding_dismissed'=>0,'voice_preference'=>null,'onboarding_step'=>'voice',
        'onboarding_draft_json'=>null,'feature_interest_json'=>null,'last_trial_notice_threshold'=>null,'last_trial_notice_at'=>null,
        'draft'=>[],'feature_interests'=>[],
    ];
}

/** Read-only state lookup. Schema mutation is deliberately reserved for upgrade/write paths. */
function onboarding_intelligence_preferences(PDO $pdo,int $userId,bool $forUpdate=false): array
{
    if($userId<1)return onboarding_intelligence_default_preferences($userId);
    if(!onboarding_intelligence_schema_ready($pdo)){
        $defaults=onboarding_intelligence_default_preferences($userId);
        if(table_exists('user_agent_preferences')){
            try{$s=$pdo->prepare('SELECT onboarding_dismissed FROM user_agent_preferences WHERE user_id=? LIMIT 1');$s->execute([$userId]);$row=$s->fetch();if($row)$defaults['onboarding_dismissed']=(int)($row['onboarding_dismissed']??0);}catch(Throwable $e){}
        }
        return $defaults;
    }
    $stmt=$pdo->prepare('SELECT * FROM user_agent_preferences WHERE user_id=? LIMIT 1'.($forUpdate?' FOR UPDATE':''));
    $stmt->execute([$userId]);$row=$stmt->fetch();if(!$row)return onboarding_intelligence_default_preferences($userId);
    foreach(['onboarding_draft_json'=>'draft','feature_interest_json'=>'feature_interests'] as $column=>$key){
        $decoded=json_decode((string)($row[$column]??''),true);$row[$key]=is_array($decoded)?$decoded:[];
    }
    return $row;
}

function onboarding_intelligence_ensure_preference_row(PDO $pdo,int $userId): void
{
    onboarding_intelligence_ensure_schema($pdo);
    $pdo->prepare("INSERT IGNORE INTO user_agent_preferences (user_id,onboarding_dismissed,onboarding_step) VALUES (?,0,'voice')")->execute([$userId]);
}

function onboarding_intelligence_valid_step(string $step): string
{
    $allowed=['voice','agent','profile','profile_agent','chat','voice_clone','review','complete'];
    return in_array($step,$allowed,true)?$step:'voice';
}

function onboarding_intelligence_save_progress(PDO $pdo,array $user,string $step,array $draft=[],?string $voicePreference=null,array $featureInterests=[]): array
{
    $uid=(int)($user['id']??0);if($uid<1)throw new RuntimeException('A signed-in account is required.');
    onboarding_intelligence_ensure_preference_row($pdo,$uid);
    $step=onboarding_intelligence_valid_step($step);
    if($voicePreference!==null&&!in_array($voicePreference,['on','off'],true))throw new RuntimeException('Unknown voice preference.');
    $current=onboarding_intelligence_preferences($pdo,$uid);
    $mergedDraft=array_replace(is_array($current['draft']??null)?$current['draft']:[],$draft);
    $mergedInterests=array_replace(is_array($current['feature_interests']??null)?$current['feature_interests']:[],$featureInterests);
    $voice=$voicePreference??($current['voice_preference']??null);
    // Voice preference is authoritative. Turning Voice off must also clear the
    // upgrade-interest signal so plan recommendations do not remain stale.
    if($voicePreference==='on')$mergedInterests['voice.access']=true;
    elseif($voicePreference==='off')$mergedInterests['voice.access']=false;
    $stmt=$pdo->prepare('UPDATE user_agent_preferences SET voice_preference=?,onboarding_step=?,onboarding_draft_json=?,feature_interest_json=?,updated_at=NOW() WHERE user_id=?');
    $stmt->execute([
        $voice,$step,
        json_encode($mergedDraft,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
        json_encode($mergedInterests,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
        $uid,
    ]);
    return onboarding_intelligence_preferences($pdo,$uid);
}

function onboarding_intelligence_mark_complete(PDO $pdo,int $userId): void
{
    if($userId<1)return;
    onboarding_intelligence_ensure_preference_row($pdo,$userId);
    $pdo->prepare("UPDATE user_agent_preferences SET onboarding_dismissed=1,onboarding_step='complete',onboarding_draft_json=NULL,updated_at=NOW() WHERE user_id=?")->execute([$userId]);
}

function onboarding_intelligence_scope_entitlement(string $scope): ?string
{
    $scope=mb_strtolower(trim($scope));
    if($scope==='')return null;
    if(str_contains($scope,'video'))return 'video_editor.access';
    if(str_contains($scope,'stem')||str_contains($scope,'studio'))return 'stem_editor.access';
    if(str_contains($scope,'profile'))return 'profile_agent.access';
    if(str_contains($scope,'transcript')||str_contains($scope,'listening'))return 'transcription.access';
    if(str_contains($scope,'voice'))return 'voice.access';
    return 'main_ai.access';
}

function onboarding_intelligence_usage_signals(PDO $pdo,int $userId): array
{
    if($userId<1||!table_exists('ai_usage_ledger'))return [];
    $stmt=$pdo->prepare("SELECT scope,COUNT(*) requests,COALESCE(SUM(total_tokens),0) tokens FROM ai_usage_ledger WHERE user_id=? AND created_at>=DATE_SUB(NOW(),INTERVAL 90 DAY) GROUP BY scope ORDER BY tokens DESC");
    $stmt->execute([$userId]);$signals=[];
    foreach($stmt->fetchAll()?:[] as $row){
        $key=onboarding_intelligence_scope_entitlement((string)$row['scope']);if(!$key)continue;
        if(!isset($signals[$key]))$signals[$key]=['requests'=>0,'tokens'=>0,'scopes'=>[]];
        $signals[$key]['requests']+=(int)$row['requests'];$signals[$key]['tokens']+=(int)$row['tokens'];$signals[$key]['scopes'][]=(string)$row['scope'];
    }
    return $signals;
}

function onboarding_intelligence_team_seats_used(PDO $pdo,array $user): int
{
    $uid=(int)($user['id']??0);if($uid<1||!table_exists('artist_team_members'))return 0;
    $stmt=$pdo->prepare('SELECT COUNT(*) FROM artist_team_members WHERE artist_user_id=?');$stmt->execute([$uid]);return (int)$stmt->fetchColumn();
}

function onboarding_intelligence_package_supports(array $package,array $needs,int $teamSeats): bool
{
    $rows=[];foreach(($package['entitlements']??[]) as $row)$rows[(string)$row['capability_key']]=$row;
    foreach(array_keys($needs) as $key){$row=$rows[$key]??null;if(!$row||(int)($row['is_enabled']??0)!==1)return false;}
    if($teamSeats>0){$row=$rows['team_seats']??null;if(!$row||(int)($row['is_enabled']??0)!==1)return false;$limit=$row['limit_value'];if($limit!==null&&(int)$limit<$teamSeats)return false;}
    return true;
}

function onboarding_intelligence_package_recommendation(PDO $pdo,array $user,array $preferences=[]): ?array
{
    if(!function_exists('subscription_packages')||!subscription_schema_ready($pdo))return null;
    $usage=onboarding_intelligence_usage_signals($pdo,(int)$user['id']);$needs=$usage;$reasons=[];
    foreach($usage as $signal){$reasons[]='Recent '.implode(', ',array_slice(array_unique($signal['scopes']),0,2)).' usage';}
    $voice=(string)($preferences['voice_preference']??'');
    if($voice==='on'){$needs['voice.access']=$needs['voice.access']??['requests'=>0,'tokens'=>0,'scopes'=>['voice preference']];$reasons[]='Voice integration selected';}
    foreach((array)($preferences['feature_interests']??[]) as $key=>$enabled){if(!$enabled)continue;if(isset(subscription_capability_catalog()[$key])){$needs[$key]=$needs[$key]??['requests'=>0,'tokens'=>0,'scopes'=>['feature interest']];$reasons[]='Interest in '.(subscription_capability_catalog()[$key]['label']??$key);}}
    $teamSeats=onboarding_intelligence_team_seats_used($pdo,$user);if($teamSeats>0)$reasons[]=$teamSeats.' Team seat'.($teamSeats===1?'':'s').' currently used';
    if(!$needs&&!$teamSeats)return null;

    $candidates=[];
    foreach(subscription_packages(true) as $summary){
        $package=subscription_package((int)$summary['id'])?:$summary;
        if((int)($package['is_trial']??0)===1||!(int)($package['is_active']??1))continue;
        if(!onboarding_intelligence_package_supports($package,$needs,$teamSeats))continue;
        $monthly=(int)($package['monthly_price_cents']??0);$annual=(int)($package['annual_price_cents']??0);
        $effective=$monthly>0?$monthly:($annual>0?(int)ceil($annual/12):0);
        $candidates[]=['package'=>$package,'effective_monthly_cents'=>$effective];
    }
    if(!$candidates)return null;
    usort($candidates,fn($a,$b)=>$a['effective_monthly_cents']<=>$b['effective_monthly_cents'] ?: ((int)$a['package']['sort_order']<=>(int)$b['package']['sort_order']));
    $best=$candidates[0]['package'];
    $current=subscription_current($user);$currentId=(int)($current['package_id']??0);
    return [
        'package_id'=>(int)$best['id'],'package_name'=>(string)$best['name'],'current_package_id'=>$currentId,
        'is_current'=>$currentId===(int)$best['id'],'monthly_price_cents'=>(int)($best['monthly_price_cents']??0),'annual_price_cents'=>(int)($best['annual_price_cents']??0),
        'reasons'=>array_values(array_unique($reasons)),'required_capabilities'=>array_keys($needs),'team_seats_needed'=>$teamSeats,'manage_url'=>url('/subscription.php'),
    ];
}

function onboarding_intelligence_trial_notice(PDO $pdo,array $user,array $preferences=[]): ?array
{
    if(!function_exists('subscription_current'))return null;$sub=subscription_current($user);
    if(!$sub||empty($sub['is_trial']))return null;
    $end=(string)($sub['ends_at']??$sub['current_period_end']??'');if($end==='')return null;
    try{$endAt=new DateTimeImmutable($end);$now=new DateTimeImmutable('now');}catch(Throwable $e){return null;}
    $seconds=$endAt->getTimestamp()-$now->getTimestamp();$days=(int)max(0,ceil($seconds/86400));
    $threshold=$days<=0?0:($days<=1?1:($days<=3?3:($days<=7?7:null)));if($threshold===null)return null;
    $last=$preferences['last_trial_notice_threshold']??null;if($last!==null&&(int)$last===$threshold)return null;
    $balance=subscription_ai_balance($user,$pdo);$remaining=!empty($balance['unlimited'])?'unlimited':number_format((int)($balance['remaining']??0));
    $when=$days===0?'today':($days===1?'in 1 day':'in '.$days.' days');
    return ['threshold'=>$threshold,'days_remaining'=>$days,'ends_at'=>$end,'remaining_tokens'=>$balance['remaining']??0,'message'=>'Your trial ends '.$when.'. You have '.$remaining.' AI tokens remaining.','manage_url'=>url('/subscription.php')];
}

function onboarding_intelligence_ack_trial_notice(PDO $pdo,int $userId,int $threshold): void
{
    if($userId<1||!in_array($threshold,[0,1,3,7],true))return;
    onboarding_intelligence_ensure_preference_row($pdo,$userId);
    $pdo->prepare('UPDATE user_agent_preferences SET last_trial_notice_threshold=?,last_trial_notice_at=NOW(),updated_at=NOW() WHERE user_id=?')->execute([$threshold,$userId]);
}

function onboarding_intelligence_state(PDO $pdo,array $user): array
{
    if(!onboarding_intelligence_schema_ready($pdo)){
        return ['build'=>VP3_ONBOARDING_INTELLIGENCE_BUILD,'voice_preference'=>null,'current_step'=>'voice','draft'=>[],'feature_interests'=>[],'trial_notice'=>null,'package_recommendation'=>null,'schema_ready'=>false];
    }
    $prefs=onboarding_intelligence_preferences($pdo,(int)$user['id']);
    $dismissed=!empty($prefs['onboarding_dismissed']);$agents=user_agents_list_v236($pdo,(int)$user['id'],true);$draft=(array)($prefs['draft']??[]);
    $step=$dismissed?'complete':onboarding_intelligence_valid_step((string)($prefs['onboarding_step']??'voice'));
    if(!$dismissed){
        if(empty($prefs['voice_preference']))$step='voice';
        elseif(!$agents&&trim((string)($draft['agent_name']??''))==='')$step='agent';
    }
    return [
        'build'=>VP3_ONBOARDING_INTELLIGENCE_BUILD,'voice_preference'=>$prefs['voice_preference']??null,'current_step'=>$step,
        'draft'=>$draft,'feature_interests'=>(array)($prefs['feature_interests']??[]),'trial_notice'=>onboarding_intelligence_trial_notice($pdo,$user,$prefs),
        'package_recommendation'=>onboarding_intelligence_package_recommendation($pdo,$user,$prefs),'schema_ready'=>true,
    ];
}
