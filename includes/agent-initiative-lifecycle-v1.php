<?php
declare(strict_types=1);

require_once __DIR__.'/agent-initiative-v1.php';
require_once __DIR__.'/agent-activity-v94.php';

/**
 * Read the control record strictly before automatic adoption. A failed or
 * malformed read must never be mistaken for a missing preference and erase a
 * genuine Pause. General Brain read entitlements do not govern this record.
 */
function vp3_initiative_lifecycle_policy_v1(PDO $pdo,array $user,int $agentId): ?array
{
    [$scope,$params]=vp3_agent_memory_scope_sql_v410($agentId);
    $hash=vp3_agent_memory_scope_hash_v410($agentId,agent_brain_v122_memory_hash('initiative_policy','owner-opt-in'));
    $stmt=$pdo->prepare('SELECT metadata_json,is_active FROM agent_memory_items WHERE user_id=? AND '.$scope.' AND memory_hash=? LIMIT 1');
    $stmt->execute(array_merge([(int)$user['id']],$params,[$hash]));
    $row=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$row)return null;
    $metadata=json_decode((string)$row['metadata_json'],true,16,JSON_THROW_ON_ERROR);
    if((int)$row['is_active']!==1||!is_array($metadata))throw new RuntimeException('Initiative preference is unavailable.',503);
    $policy=vp3_initiative_policy_metadata_v1($metadata);
    if(!is_bool($policy['enabled']??null)||!is_int($policy['conversation_id']??null)||$policy['conversation_id']<1
        ||!is_string($policy['revision']??null)||!preg_match('/\A[a-f0-9]{32}\z/D',$policy['revision'])
        ||vp3_initiative_binding_agent_v1($policy)!==$agentId
        ||($agentId>0&&($policy['owner_user_id']??null)!==(int)$user['id'])
        ||(isset($policy['owner_user_id'])&&$policy['owner_user_id']!==(int)$user['id'])){
        throw new RuntimeException('Initiative preference is unavailable.',503);
    }
    return $policy;
}

/**
 * Connect normal owner Chat activity to its existing canonical Agent loop.
 * Only the exact currently accepted focused heartbeat can create/rebind policy;
 * observation, inference, source grants and explicit Pause remain separate.
 */
function vp3_initiative_adopt_activity_v1(PDO $pdo,array $user,array $context): array
{
    $cid=$context['conversation_id']??0;
    $result=['accepted'=>false,'changed'=>false,'agent_id'=>0,'conversation_id'=>is_int($cid)?max(0,$cid):0,
        'policy_revision'=>'','paused'=>false];
    $observed=$context['activity_observed_at']??null;$sequence=$context['activity_sequence']??null;
    $tab=$context['activity_tab']??null;
    if((int)($user['id']??0)<1||vp3_participant_scope_v1()!==null
        ||($context['surface']??null)!=='chat'||!is_int($cid)||$cid<1
        ||($context['visible']??null)!==true||($context['focused']??null)!==true
        ||($context['chat_focused']??null)!==true||!is_bool($context['chat_busy']??null)
        ||!is_string($tab)||!preg_match('/\A[a-f0-9]{32}\z/D',$tab)
        ||!is_int($observed)||abs($observed-(int)floor(microtime(true)*1000))>90000
        ||!is_int($sequence)||$sequence<1||$sequence>2147483647){
        return $result+['reason'=>'context_unavailable'];
    }
    if(!agent_activity_v94_schema_ready()||!agent_brain_schema_ready()||!agent_job_engine_schema_ready_v1900($pdo)){
        return $result+['reason'=>'storage_unavailable'];
    }
    $ownsTransaction=!$pdo->inTransaction();
    try{
        if($ownsTransaction)$pdo->beginTransaction();
        vp3_initiative_owner_lock_v1($pdo,$user);
        // The conversation is authoritative; a posted Agent ID has no effect.
        $q=$pdo->prepare('SELECT user_agent_id FROM chat_conversations WHERE id=? AND user_id=? LIMIT 1');
        $q->execute([$cid,(int)$user['id']]);$conversation=$q->fetch(PDO::FETCH_ASSOC);
        if(!$conversation)throw new RuntimeException('Initiative context is unavailable.',403);
        $agentId=max(0,(int)($conversation['user_agent_id']??0));
        $agent=vp3_agent_chat_resolve_agent_v380($pdo,$user,$agentId);
        $principal=vp3_agent_chat_principal_v380($user,$agent);
        $live=vp3_capability_live_user_v1($pdo,$user,$principal,['conversation_id'=>$cid],null);
        if(!has_permission('account.access',$live)||!vp3_agent_chat_conversation_v380($pdo,$cid,(int)$live['id'],$agent)){
            throw new RuntimeException('Initiative context is unavailable.',403);
        }
        $q=$pdo->prepare('SELECT surface,context_key,activity_state,last_heartbeat_at,details_json FROM agent_activity_state WHERE user_id=? LIMIT 1');
        $q->execute([(int)$live['id']]);$activity=$q->fetch(PDO::FETCH_ASSOC);
        $details=json_decode((string)($activity['details_json']??''),true);
        $heartbeat=strtotime((string)($activity['last_heartbeat_at']??''))?:0;
        if(!$activity||!is_array($details)||($activity['surface']??'')!=='chat'
            ||($activity['context_key']??'')!=='chat:'.$cid||!in_array($activity['activity_state']??'', ['working','paused','idle'],true)
            ||$heartbeat<time()-90||$heartbeat>time()+5){
            throw new RuntimeException('Initiative observation is not current.',409);
        }
        foreach(['conversation_id','visible','focused','chat_focused','chat_busy','activity_tab','activity_observed_at','activity_sequence'] as $field){
            if(!array_key_exists($field,$details)||$details[$field]!==$context[$field]){
                throw new RuntimeException('Initiative observation is not current.',409);
            }
        }
        $adoption=vp3_initiative_in_scope_v1($live,$agentId,static function()use($pdo,$live,$agentId,$cid,$result):array{
            $policy=vp3_initiative_lifecycle_policy_v1($pdo,$live,$agentId);
            $changed=false;
            if($policy===null){
                $policy=['enabled'=>true,'owner_user_id'=>(int)$live['id'],'agent_id'=>$agentId,
                    'conversation_id'=>$cid,'revision'=>bin2hex(random_bytes(16)),
                    'knowledge_scope'=>['mode'=>'off','folder_id'=>0],'screen_enabled'=>false];
                $changed=true;
            }elseif($policy['enabled']===true&&$policy['conversation_id']!==$cid){
                // Preserve this Agent's existing explicit source choices. The
                // new revision fences all work prepared for its previous Chat.
                $policy['conversation_id']=$cid;
                $policy['revision']=bin2hex(random_bytes(16));
                $changed=true;
            }
            if($changed&&agent_brain_v122_upsert_system_memory($live,'initiative_policy','owner-opt-in','Owner initiative preference.',$policy)<1){
                throw new RuntimeException('Initiative preference could not be saved.',503);
            }
            return array_replace($result,['accepted'=>true,'changed'=>$changed,'agent_id'=>$agentId,
                'conversation_id'=>$cid,'policy_revision'=>$policy['revision'],'paused'=>$policy['enabled']===false]);
        });
        if($ownsTransaction)$pdo->commit();
        return $adoption;
    }catch(Throwable $error){
        if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();
        return $result+['reason'=>match($error->getCode()){
            403=>'context_unavailable',409=>'observation_not_current',default=>'storage_unavailable'}];
    }
}
