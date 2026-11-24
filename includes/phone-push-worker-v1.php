<?php
declare(strict_types=1);

require_once __DIR__.'/phone-push-vapid-cache-v1.php';
require_once __DIR__.'/phone-push-transport-v1.php';

/** Recheck authority after any cache/signing wait, immediately before HTTP.
 * No owner/device lock is held while resolving VAPID or performing transport.
 * The opaque event capability also binds the prepared payload to this queue row.
 */
function vp3_phone_push_worker_fence_v1(PDO $pdo,array $subscription,array $vapid,array $payload,array $options,int $now):?array
{
    if($pdo->inTransaction())throw new LogicException('Phone push dispatch requires released source locks.');
    $hint=vp3_phone_push_delivery_event_v1($pdo,$payload['event_ref']);
    if(!$hint)return null;
    return vp3_phone_transaction_v1($pdo,static function()use($pdo,$subscription,$vapid,$payload,$options,$now,$hint):array{
        $ctx=vp3_phone_push_delivery_context_v1($pdo,(int)$hint['owner_user_id'],$hint['device_id'],$now);
        $event=vp3_phone_push_delivery_event_v1($pdo,$payload['event_ref'],true);
        if(!$event||$event['state']!=='sending'||!is_string($event['lease_token'])
            ||preg_match('/^[a-f0-9]{32}$/D',$event['lease_token'])!==1||(int)$event['lease_expires_epoch']<=$now
            ||(int)$event['send_started_epoch']<1)return [];
        if(!hash_equals($event['receipt_hash'],hash('sha256',$payload['receipt_token']))
            ||!hash_equals($event['payload_hash'],hash('sha256',json_encode($subscription,JSON_THROW_ON_ERROR)))
            ||(int)$event['config_revision']!==$vapid['config_revision'])return [];
        if(!vp3_phone_push_delivery_bound_v1($event,$ctx)){
            vp3_phone_push_delivery_terminal_v1($pdo,$event,'cancelled','permission_changed',$now);return [];
        }
        if($ctx['push']['public_key']!==$vapid['public_key']||$ctx['config']['subject']!==$vapid['subject'])return [];
        if((int)$event['expires_epoch']<=$now){
            vp3_phone_push_delivery_terminal_v1($pdo,$event,'expired','event_expired',$now);return [];
        }
        if((int)$event['client_observed_epoch']>0||(int)$event['opened_epoch']>0){
            vp3_phone_push_delivery_terminal_v1($pdo,$event,'uncertain','client_observed',$now);return [];
        }
        if(!vp3_phone_push_source_current_v1($pdo,$ctx['device'],vp3_phone_push_delivery_event_source_v1($event),$now)){
            vp3_phone_push_delivery_terminal_v1($pdo,$event,'cancelled','source_unavailable',$now);return [];
        }
        // Time spent waiting for the cache must not extend a provider's TTL
        // beyond the source event's remaining lifetime.
        return array_replace($options,['ttl'=>min($options['ttl'],(int)$event['expires_epoch']-$now)]);
    })?:null;
}

/** One production worker attempt. Optional exchange is an internal no-network
 * test seam; HTTP entrypoints never expose it or any route/header override.
 */
function vp3_phone_push_worker_send_v1(PDO $pdo,#[\SensitiveParameter] array $subscription,#[\SensitiveParameter] array $vapid,
    #[\SensitiveParameter] string $payload,array $options=[],?callable $exchange=null):array
{
    if($pdo->inTransaction())throw new LogicException('Phone push transport cannot hold source locks.');
    $ready=vp3_phone_push_transport_ready_v1();
    if(!$ready['available'])return vp3_phone_push_transport_result_v1('failed',$ready['code']);
    try{
        $subscription=vp3_phone_push_parse_v1($subscription,time());
        $data=json_decode($payload,true,8,JSON_THROW_ON_ERROR);
        if(!is_array($data)||array_diff(array_keys($data),['v','event_ref','receipt_token'])||($data['v']??null)!==1
            ||!is_string($data['event_ref']??null)||preg_match('/^[a-f0-9]{32}$/D',$data['event_ref'])!==1
            ||vp3_phone_push_decode_v1($data['receipt_token']??null,32)===null
            ||!is_int($vapid['config_revision']??null)||$vapid['config_revision']<1
            ||array_diff(array_keys($options),['ttl','topic','urgency'])||!is_int($options['ttl']??null)
            ||$options['ttl']<1||$options['ttl']>3600||($options['topic']??null)!==$data['event_ref']
            ||!in_array($options['urgency']??null,['very-low','low','normal','high'],true))
            return vp3_phone_push_transport_result_v1('failed','invalid_prepared_dispatch');
        $origin='https://'.parse_url($subscription['endpoint'],PHP_URL_HOST);
        $header=vp3_phone_push_vapid_cache_get_v1($pdo,$vapid,$origin,time(),'vp3_phone_push_transport_vapid_header_v1');
        $fenced=vp3_phone_push_worker_fence_v1($pdo,$subscription,$vapid,$data,$options,time());
        if($fenced===null)return vp3_phone_push_transport_result_v1('failed','dispatch_authority_changed');
    }catch(PhonePushVapidCacheUnavailableV1 $error){
        return vp3_phone_push_transport_result_v1($error->retry_after>0?'retry':'failed',$error->reason,0,$error->retry_after,false);
    }catch(Throwable){
        // No network has started. Preserve a safe retry when a local database
        // or cache check was interrupted; never leak its exception details.
        return vp3_phone_push_transport_result_v1('retry','dispatch_preflight_unavailable',0,60,false);
    }
    unset($vapid['config_revision']);
    $vapid['authorization']=$header['authorization'];
    if($pdo->inTransaction())throw new LogicException('Phone push transport cannot hold source locks.');
    return $exchange===null?vp3_phone_push_transport_send_v1($subscription,$vapid,$payload,$fenced)
        :vp3_phone_push_transport_attempt_v1($subscription,$vapid,$payload,$fenced,$exchange);
}
