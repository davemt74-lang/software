<?php
declare(strict_types=1);

require_once __DIR__.'/agent-commerce-recovery-pa2.php';
require_once __DIR__.'/ai-service-policy-v1.php';
require_once __DIR__.'/ai-service-usage-v1.php';

const VP3_ANTHROPIC_CREDIT_NOTICE_TYPE_V1='agent_anthropic_credit_balance';

function vp3_anthropic_credit_state_key_v1(int $owner): string
{
    return 'vp3_anthropic_credit_notice_owner_'.$owner;
}

/** Compare against the configured key without exposing a key or hash in any client response. */
function vp3_anthropic_credit_fingerprint_v1(PDO $pdo,array $user,string $funding): ?string
{
    if(vp3_user_llm_v1_funding($pdo,$user)!==$funding)return null;
    $key=$funding==='own_key'?vp3_user_llm_v1_saved_key($pdo,(int)$user['id'],'anthropic')
        :(ai_provider_enabled('anthropic')?ai_provider_api_key('anthropic'):'');
    return $key!==''?hash('sha256',$key):null;
}

function vp3_anthropic_credit_notice_content_v1(array $user,string $funding): array
{
    $ownerManaged=$funding==='own_key'||subscription_is_internal_admin($user);
    return $ownerManaged
        ? ['title'=>'Anthropic API credits are too low',
            'message'=>'Anthropic rejected an AI request because the API credit balance was too low. Check Billing in the Claude Console for the key VP3 uses.',
            'target_url'=>'https://platform.claude.com/settings/billing']
        : ['title'=>'VP3 AI service interruption',
            'message'=>'VP3’s Anthropic connection rejected an AI request because its API credits were too low. VP3 manages this connection.',
            'target_url'=>url('/settings-ai-providers.php')];
}

/** Called only for a verified balance rejection or successful request from the actual Anthropic transport. */
function vp3_anthropic_credit_observe_v1(PDO $pdo,array $user,string $funding,string $credentialFingerprint,bool $exhausted,?int $startedAtMicros=null): void
{
    $owner=(int)($user['id']??0);
    if($owner<1||!in_array($funding,['own_key','system'],true)||!preg_match('/\A[a-f0-9]{64}\z/D',$credentialFingerprint)
        ||(function_exists('vp3_participant_scope_v1')&&vp3_participant_scope_v1()!==null))return;
    $key=vp3_anthropic_credit_state_key_v1($owner);
    $startedAtMicros??=(int)floor(microtime(true)*1000000);
    agent_commerce_atomic_pa2($pdo,static function()use($pdo,$user,$funding,$credentialFingerprint,$exhausted,$startedAtMicros,$owner,$key):void{
        vp3_ai_service_usage_lock_owner_v1($pdo,$owner);
        $active=$pdo->prepare('SELECT is_active FROM users WHERE id=?');
        $active->execute([$owner]);
        if((int)$active->fetchColumn()!==1)return;
        $fingerprint=vp3_anthropic_credit_fingerprint_v1($pdo,$user,$funding);
        if($fingerprint===null||!hash_equals($fingerprint,$credentialFingerprint))return;
        $old=vp3_ai_service_read_setting_v1($pdo,$key,true)??[];
        $same=($old['fingerprint']??null)===$fingerprint&&($old['funding']??null)===$funding;
        if($same&&($old['observed']??0)>=$startedAtMicros)return;
        $notice=$same&&$exhausted?(int)($old['notice_id']??0):0;
        $claimed=$notice>0&&!empty($old['claimed']);
        if($exhausted&&$notice===0){
            if(!table_exists('notifications'))return;
            $content=vp3_anthropic_credit_notice_content_v1($user,$funding);
            notification_create_locked($owner,VP3_ANTHROPIC_CREDIT_NOTICE_TYPE_V1,$content['title'],$content['message'],
                $content['target_url'],'agent_anthropic_credit');
            $lookup=$pdo->prepare("SELECT id FROM notifications WHERE user_id=? AND type=?
                AND source_type='agent_anthropic_credit' ORDER BY id DESC LIMIT 1");
            $lookup->execute([$owner,VP3_ANTHROPIC_CREDIT_NOTICE_TYPE_V1]);
            $notice=(int)$lookup->fetchColumn();
            if($notice<1)throw new RuntimeException('Anthropic credit notice could not be saved.');
        }
        $previous=(int)($old['notice_id']??0);
        if($previous>0&&$previous!==$notice){
            $pdo->prepare("UPDATE notifications SET is_read=1,read_at=COALESCE(read_at,UTC_TIMESTAMP())
                WHERE user_id=? AND id=? AND source_type='agent_anthropic_credit'")->execute([$owner,$previous]);
        }
        vp3_ai_service_write_setting_v1($pdo,$key,['funding'=>$funding,'fingerprint'=>$fingerprint,
            'observed'=>$startedAtMicros,'notice_id'=>$notice,'claimed'=>$claimed]);
    });
}

function vp3_anthropic_credit_notice_current_v1(PDO $pdo,array $user): ?array
{
    $owner=(int)($user['id']??0);
    if($owner<1)return null;
    $state=vp3_ai_service_read_setting_v1($pdo,vp3_anthropic_credit_state_key_v1($owner));
    $funding=$state['funding']??null;
    $id=$state['notice_id']??null;
    if(!is_int($id)||$id<1||!in_array($funding,['own_key','system'],true))return null;
    $fingerprint=vp3_anthropic_credit_fingerprint_v1($pdo,$user,$funding);
    if($fingerprint===null||$fingerprint!==($state['fingerprint']??null))return null;
    return ['notification_id'=>$id,'voice_pending'=>empty($state['claimed'])]
        +vp3_anthropic_credit_notice_content_v1($user,$funding);
}

function vp3_anthropic_credit_notice_claim_v1(PDO $pdo,array $user,int $notificationId): ?array
{
    $owner=(int)($user['id']??0);
    if($owner<1||$notificationId<1)return null;
    return agent_commerce_atomic_pa2($pdo,static function()use($pdo,$user,$owner,$notificationId):?array{
        vp3_ai_service_usage_lock_owner_v1($pdo,$owner);
        if(!chat_settings_agent_voice_enabled_v237($pdo,$user))return null;
        $notice=vp3_anthropic_credit_notice_current_v1($pdo,$user);
        if(($notice['notification_id']??null)!==$notificationId||empty($notice['voice_pending']))return null;
        $key=vp3_anthropic_credit_state_key_v1($owner);
        $state=vp3_ai_service_read_setting_v1($pdo,$key,true);
        $state['claimed']=true;
        vp3_ai_service_write_setting_v1($pdo,$key,$state);
        return $notice;
    });
}
