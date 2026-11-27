<?php
declare(strict_types=1);

require_once __DIR__.'/agent-curiosity-v1.php';

/** Called only after an explicit owner open succeeds; background reads never record an open. */
function vp3_curiosity_email_open_v1(PDO $pdo,array $user,string $itemRef,array $opened,int $agent=0,int $cid=0): bool
{
    if(!table_exists('agent_connection_gmail_inbox_v1'))return false;
    require_once __DIR__.'/agent-curiosity-gmail-v1.php';
    $scope=agent_connection_gmail_inbox_bound_scope_v1($pdo,$user);
    if(($scope['state']??null)!=='ready'||empty($scope['provider_ready'])||!agent_brain_schema_ready())return false;
    vp3_curiosity_private_scope_v1($pdo,$user,$agent,$cid);
    if($cid===0)$agent=0;
    // The opaque item resolves against the current mailbox grant, not client-supplied mail content.
    $item=agent_connection_gmail_inbox_item_v1($pdo,$user,$itemRef);
    if(($item['connection_id']??null)!==(int)$scope['policy']['connection_id'])return false;
    $grant=agent_connection_gmail_inbox_update_scope_ref_v1($scope);
    $subject='gmail-open:'.hash('sha256',json_encode([$grant,$agent,$cid,$itemRef],JSON_THROW_ON_ERROR));
    return vp3_initiative_in_scope_v1($scope['user'],$agent,static function()use($pdo,$scope,$user,$itemRef,$opened,$agent,$cid,$grant,$subject):bool{
        return agent_commerce_atomic_pa2($pdo,static function()use($pdo,$scope,$user,$itemRef,$opened,$agent,$cid,$grant,$subject):bool{
            $live=agent_connection_gmail_inbox_bound_scope_v1($pdo,$user,true);
            if(($live['state']??null)!=='ready'||!hash_equals($grant,agent_connection_gmail_inbox_update_scope_ref_v1($live)))return false;
            agent_connection_gmail_inbox_item_v1($pdo,$user,$itemRef);
            $previous=agent_brain_v122_memory($scope['user'],'initiative_sweep',$subject)['metadata']??[];
            $now=time();
            if(($previous['opened_at']??0)>$now-300)return false;
            [$where,$args]=vp3_agent_memory_scope_sql_v410($agent);
            $epoch=vp3_initiative_db_wall_epoch_sql_v1($pdo,'last_seen_at');
            $q=$pdo->prepare("DELETE FROM agent_memory_items WHERE user_id=? AND $where AND memory_type='initiative_sweep'
                AND subject LIKE 'gmail-open:%' AND $epoch<?");
            $q->execute([(int)$user['id'],...$args,$now-30*86400]);
            $q=$pdo->prepare("SELECT id FROM agent_memory_items WHERE user_id=? AND $where AND memory_type='initiative_sweep'
                AND subject LIKE 'gmail-open:%' ORDER BY last_seen_at DESC,id DESC LIMIT 256 OFFSET 255");
            $q->execute([(int)$user['id'],...$args]);
            foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id)$pdo->prepare('DELETE FROM agent_memory_items WHERE id=? AND user_id=?')->execute([$id,(int)$user['id']]);
            return agent_brain_v122_upsert_system_memory($scope['user'],'initiative_sweep',$subject,'Gmail message opened.',[
                'version'=>1,'scope_ref'=>$grant,'conversation_id'=>$cid,'item_ref'=>$itemRef,
                'opened_at'=>$now,'opens'=>min(9999,1+(int)($previous['opens']??0)),
                'title'=>vp3_curiosity_safe_text_v1((string)($opened['subject']??''),180),
            ],0.5)>0;
        });
    });
}

/** Optional activity memory cannot prevent an otherwise successful mailbox open. */
function vp3_curiosity_email_open_observe_v1(PDO $pdo,array $user,string $itemRef,array $opened,int $agent=0,int $cid=0): void
{
    try{vp3_curiosity_email_open_v1($pdo,$user,$itemRef,$opened,$agent,$cid);}
    catch(RuntimeException|JsonException $error){
        if(function_exists('agent_runtime_v125_trace'))agent_runtime_v125_trace('curiosity.email_open_unavailable',[
            'user_id'=>(int)$user['id'],'error_class'=>get_class($error)]);
    }
}

/** Owner-global mailbox opens and this Chat's clicks; never another private Chat's activity. */
function vp3_curiosity_email_activity_v1(PDO $pdo,array $user,array $binding): array
{
    if(!agent_brain_schema_ready()||!table_exists('agent_connection_gmail_inbox_v1'))return [];
    require_once __DIR__.'/agent-curiosity-gmail-v1.php';
    $agent=vp3_initiative_binding_agent_v1($binding);$cid=(int)($binding['conversation_id']??0);
    vp3_curiosity_private_scope_v1($pdo,$user,$agent,$cid);
    $scope=agent_connection_gmail_inbox_bound_scope_v1($pdo,$user);
    if(($scope['state']??null)!=='ready'||empty($scope['provider_ready']))return [];
    $grant=agent_connection_gmail_inbox_update_scope_ref_v1($scope);
    $items=[];
    foreach(array_unique([$agent,0]) as $memoryAgent){
        [$where,$args]=vp3_agent_memory_scope_sql_v410($memoryAgent);
        $q=$pdo->prepare("SELECT subject,metadata_json FROM agent_memory_items WHERE user_id=? AND $where
            AND memory_type='initiative_sweep' AND is_active=1 AND subject LIKE 'gmail-open:%' ORDER BY last_seen_at DESC,id DESC LIMIT 24");
        $q->execute([(int)$user['id'],...$args]);
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
            $saved=json_decode((string)$row['metadata_json'],true);
            if(($saved['version']??null)!==1||($saved['scope_ref']??null)!==$grant
                ||!in_array($saved['conversation_id']??null,$memoryAgent===0?[0,$cid]:[$cid],true)
                ||!is_int($saved['opened_at']??null)||$saved['opened_at']<time()-30*86400||$saved['opened_at']>time()
                ||!is_int($saved['opens']??null)||$saved['opens']<1||!is_string($saved['item_ref']??null))continue;
            try{agent_connection_gmail_inbox_item_v1($pdo,$user,$saved['item_ref']);}
            catch(RuntimeException $error){if($error instanceof PDOException)throw $error;continue;}
            $key=hash('sha256',$row['subject']);
            $items[$key]=['ref'=>'email-activity:'.$key,'action'=>'message_open','at'=>$saved['opened_at'],
                'count'=>$saved['opens'],'item_ref'=>$saved['item_ref'],'title'=>vp3_curiosity_safe_text_v1((string)($saved['title']??''),180)];
        }
    }
    foreach(vp3_gmail_curiosity_link_observations_v1($pdo,$user,$agent,$cid,time()-30*86400,24,true) as $link){
        $key=$link['target_hash'];
        $items[$key]=['ref'=>'email-activity:'.$key,'action'=>'link_open','at'=>$link['clicked_at'],'count'=>1,
            'item_ref'=>$link['item_ref']??null,'title'=>$link['title'],'excerpt'=>$link['excerpt']];
    }
    [$globalWhere,$globalArgs]=vp3_agent_memory_scope_sql_v410(0);
    $q=$pdo->prepare("SELECT metadata_json FROM agent_memory_items WHERE user_id=? AND $globalWhere
        AND memory_type='initiative_sweep' AND is_active=1 AND subject LIKE 'gmail-link:%' ORDER BY last_seen_at DESC,id DESC LIMIT 24");
    $q->execute([(int)$user['id'],...$globalArgs]);
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $raw){
        $saved=json_decode((string)$raw,true);
        if(($saved['conversation_id']??null)!==0||($saved['message_id']??null)!==0||($saved['kind']??null)!=='mailbox'
            ||($saved['scope_ref']??null)!==$grant||!is_int($saved['clicked_at']??null)||$saved['clicked_at']<time()-30*86400
            ||$saved['clicked_at']>time()||!is_string($saved['item_ref']??null)||!is_string($saved['target_hash']??null)
            ||!preg_match('/\A[a-f0-9]{64}\z/D',$saved['target_hash']))continue;
        try{agent_connection_gmail_inbox_item_v1($pdo,$user,$saved['item_ref']);}
        catch(RuntimeException $error){if($error instanceof PDOException)throw $error;continue;}
        $key=$saved['target_hash'];
        $items[$key]=['ref'=>'email-activity:'.$key,'action'=>'link_open','at'=>$saved['clicked_at'],'count'=>1,
            'item_ref'=>$saved['item_ref'],'title'=>vp3_curiosity_safe_text_v1((string)($saved['title']??''),180)];
    }
    uasort($items,static fn(array $a,array $b):int=>$b['at']<=>$a['at']?:strcmp($a['ref'],$b['ref']));
    return array_values(array_slice($items,0,12,true));
}

/** Recent native inbox reports supply unread mail too; a candidate is never recorded as an owner open. */
function vp3_curiosity_email_candidates_v1(PDO $pdo,array $user,array $binding): array
{
    if(!table_exists('agent_connection_gmail_inbox_v1'))return [];
    require_once __DIR__.'/agent-curiosity-gmail-v1.php';
    $scope=agent_connection_gmail_inbox_bound_scope_v1($pdo,$user);
    if(($scope['state']??null)!=='ready'||empty($scope['provider_ready']))return [];
    $agent=vp3_initiative_binding_agent_v1($binding);$cid=(int)$binding['conversation_id'];
    if(!agent_connection_gmail_inbox_chat_target_v1($pdo,$scope,$agent,$cid))return [];
    $q=$pdo->prepare("SELECT id,context_json FROM chat_messages WHERE conversation_id=? AND user_id IS NULL
        AND role='assistant' ORDER BY id DESC LIMIT 24");
    $q->execute([$cid]);$candidates=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
        $context=json_decode((string)$row['context_json'],true);
        if(($context['source']??null)!=='agent_connection_gmail_inbox_update')continue;
        try{$view=agent_connection_gmail_inbox_update_history_v1($pdo,$scope,$agent,$cid,$context,(int)$row['id']);}
        catch(RuntimeException $error){if($error instanceof PDOException)throw $error;continue;}
        foreach($view['review']['items']??[] as $item){
            $ref=$item['item_ref']??null;
            if(!is_string($ref)||isset($candidates[$ref])||agent_connection_gmail_inbox_ignored_v1($item,$scope['policy']['rules']))continue;
            try{agent_connection_gmail_inbox_item_v1($pdo,$user,$ref);}
            catch(RuntimeException $error){if($error instanceof PDOException)throw $error;continue;}
            $candidates[$ref]=['ref'=>'email-review:'.$ref,'item_ref'=>$ref,'source'=>'inbox_review'];
            if(count($candidates)>=3)break 2;
        }
    }
    return array_values($candidates);
}
