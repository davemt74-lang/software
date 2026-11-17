<?php
declare(strict_types=1);

require_once __DIR__.'/user-llm-v1.php';
require_once __DIR__.'/ai-service-policy-v1.php';
require_once __DIR__.'/agent-commerce-recovery-pa2.php';
require_once __DIR__.'/notifications.php';

function vp3_token_balance_notice_owner_v1(array $user): int
{
    if(function_exists('vp3_participant_scope_v1')&&vp3_participant_scope_v1()!==null)return 0;
    return max(0,(int)($user['id']??0));
}

/** Balance includes unused credits but ignores temporary reservations. */
function vp3_token_balance_notice_snapshot_v1(PDO $pdo,array $user): ?array
{
    $owner=vp3_token_balance_notice_owner_v1($user);
    if($owner<1||!subscription_schema_ready($pdo)
        ||vp3_user_llm_v1_funding($pdo,$user)!=='system'
        ||!subscription_has_entitlement($user,'main_ai.access'))return null;
    $balance=subscription_ai_balance($user,$pdo);
    $subscription=$balance['subscription']??null;
    if(!is_array($subscription)||!empty($balance['unlimited'])||!empty($balance['compatibility']))return null;
    $period=$balance['period']??[];
    $start=($period['start']??null)?:null;
    $end=($period['end']??null)?:null;
    $creditUse=$pdo->prepare('SELECT COALESCE(SUM(credit_tokens_used),0) FROM ai_usage_ledger
        WHERE user_id=? AND subscription_id=?'.($start!==null?' AND created_at>=?':'').($end!==null?' AND created_at<?':''));
    $creditUse->execute([$owner,(int)$subscription['id'],...($start!==null?[$start]:[]),...($end!==null?[$end]:[])]);
    $remaining=(int)$balance['available'];
    $baseline=(float)$balance['package_allowance']+(float)$balance['credits_remaining']+(float)$creditUse->fetchColumn();
    $tier=match(true){
        $remaining===0=>3,
        $remaining<=$baseline*0.05=>2,
        $remaining<=$baseline*0.20=>1,
        default=>0,
    };
    return ['period'=>hash('sha256',json_encode([(int)$subscription['id'],$start,$end],JSON_THROW_ON_ERROR)),
        'tier'=>$tier,'remaining'=>$remaining];
}

function vp3_token_balance_notice_view_v1(array $state): ?array
{
    $noticeId=$state['notice_id']??null;
    if(!is_int($noticeId)||$noticeId<1||!is_int($state['remaining']??null))return null;
    [$severity,$title,$message]=match($state['tier']??0){
        1=>['low','VP3 AI tokens are running low','You have 20 percent or less of your VP3 AI tokens left. You can check your balance or add tokens in Plan and Usage.'],
        2=>['critical','VP3 AI tokens are nearly gone','You have 5 percent or less of your VP3 AI tokens left. You can add tokens in Plan and Usage to keep AI work going.'],
        3=>['exhausted','VP3 AI tokens are exhausted','You are out of VP3 AI tokens. Add tokens in Plan and Usage to resume AI work that uses this balance.'],
        default=>['','',''],
    };
    if($severity==='')return null;
    return ['notification_id'=>$noticeId,'severity'=>$severity,'remaining'=>$state['remaining'],
        'title'=>$title,'message'=>$message,'target_url'=>url('/subscription.php'),'voice_pending'=>empty($state['voice_claimed'])];
}

function vp3_token_balance_notice_current_v1(PDO $pdo,array $user): ?array
{
    $owner=vp3_token_balance_notice_owner_v1($user);
    if($owner<1)return null;
    return vp3_token_balance_notice_view_v1(vp3_ai_service_read_setting_v1($pdo,'agent_token_balance_notice_owner_'.$owner)??[]);
}

/** The owner lock serializes quota settlement, credit top-ups and warning changes. */
function vp3_token_balance_notice_sync_v1(PDO $pdo,array $user): ?array
{
    $owner=vp3_token_balance_notice_owner_v1($user);
    if($owner<1||!subscription_schema_ready($pdo)||!table_exists('notifications')||subscription_is_internal_admin($user))return null;
    return agent_commerce_atomic_pa2($pdo,static function()use($pdo,$user,$owner):?array{
        vp3_ai_service_usage_lock_owner_v1($pdo,$owner);
        return vp3_token_balance_notice_sync_locked_v1($pdo,$user,$owner);
    });
}

function vp3_token_balance_notice_sync_locked_v1(PDO $pdo,array $user,int $owner): ?array
{
    $account=$pdo->prepare('SELECT is_active FROM users WHERE id=?');
    $account->execute([$owner]);
    if((int)$account->fetchColumn()!==1)return null;
    $key='agent_token_balance_notice_owner_'.$owner;
    $stored=vp3_ai_service_read_setting_v1($pdo,$key,true)??[];
    $current=vp3_token_balance_notice_snapshot_v1($pdo,$user);
    $samePeriod=$current!==null&&($stored['period']??null)===$current['period'];
    $previousTier=$samePeriod?(int)($stored['tier']??0):0;
    $tier=$current['tier']??0;
    if($samePeriod&&$previousTier===$tier)return vp3_token_balance_notice_view_v1($stored);
    if(!empty($stored['notice_id'])){
        $pdo->prepare("UPDATE notifications SET is_read=1,read_at=COALESCE(read_at,CURRENT_TIMESTAMP)
            WHERE id=? AND user_id=? AND source_type='agent_token_balance' AND is_read=0")
            ->execute([(int)$stored['notice_id'],$owner]);
    }
    if($current===null||$tier===0){
        if($stored)$pdo->prepare('DELETE FROM settings WHERE setting_key=?')->execute([$key]);
        return null;
    }
    if($tier<$previousTier){
        // A top-up may restore just enough capacity for one lower tier.
        // Record it so the next downward crossing can warn again.
        vp3_ai_service_write_setting_v1($pdo,$key,$current);
        return null;
    }
    $notice=vp3_token_balance_notice_view_v1($current+['notice_id'=>1]);
    notification_create_locked($owner,'agent_token_balance',$notice['title'],$notice['message'],
        $notice['target_url'],'agent_token_balance');
    $lookup=$pdo->prepare("SELECT id FROM notifications WHERE user_id=? AND source_type='agent_token_balance'
        AND type='agent_token_balance' ORDER BY id DESC LIMIT 1");
    $lookup->execute([$owner]);
    $noticeId=(int)$lookup->fetchColumn();
    if($noticeId<1)throw new RuntimeException('The VP3 token balance notice could not be recorded.');
    $current['notice_id']=$noticeId;
    vp3_ai_service_write_setting_v1($pdo,$key,$current);
    return vp3_token_balance_notice_view_v1($current);
}

/** An exact notice may be spoken once across all of the owner's Chat tabs. */
function vp3_token_balance_notice_claim_v1(PDO $pdo,array $user,int $notificationId): ?array
{
    $owner=vp3_token_balance_notice_owner_v1($user);
    if($owner<1||$notificationId<1||!function_exists('chat_settings_agent_voice_enabled_v237')
        ||!subscription_schema_ready($pdo)||!table_exists('notifications'))return null;
    return agent_commerce_atomic_pa2($pdo,static function()use($pdo,$user,$owner,$notificationId):?array{
        vp3_ai_service_usage_lock_owner_v1($pdo,$owner);
        if(!chat_settings_agent_voice_enabled_v237($pdo,$user))return null;
        $notice=vp3_token_balance_notice_sync_locked_v1($pdo,$user,$owner);
        if(($notice['notification_id']??0)!==$notificationId||empty($notice['voice_pending']))return null;
        $key='agent_token_balance_notice_owner_'.$owner;
        $stored=vp3_ai_service_read_setting_v1($pdo,$key,true);
        $stored['voice_claimed']=true;
        vp3_ai_service_write_setting_v1($pdo,$key,$stored);
        $notice['voice_pending']=false;
        return $notice;
    });
}
