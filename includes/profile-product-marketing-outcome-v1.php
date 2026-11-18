<?php
declare(strict_types=1);

require_once __DIR__.'/profile-product-marketing-ref-v1.php';
require_once __DIR__.'/profile-analytics-period-v1.php';
require_once __DIR__.'/initiative-marketing-plans-v1.php';
require_once __DIR__.'/initiative-work-marketing-promotion-v1.php';

function vp3_product_marketing_text_v1(mixed $value,int $limit=240): string
{
    if(!is_string($value))return '';
    $text=preg_replace('~(?:https?://|\bwww\.)[^\s<>]+~iu','[link in source]',strip_tags($value))??'';
    $text=preg_replace('/[<>\p{Cc}\p{Cf}]|\s+/u',' ',$text)??'';
    return mb_substr(trim(preg_replace('/\s+/u',' ',$text)??''),0,$limit);
}

/** One current-authority read, preserving caller-owned transactions. */
function vp3_product_marketing_snapshot_v1(PDO $pdo,array $scope,callable $read): array
{
    $owns=!$pdo->inTransaction();$fences=[];
    try{
        if($owns){if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$pdo->beginTransaction();}
        $scope=vp3_private_analytics_authority_current_v1($pdo,$scope,false);
        $result=$read($scope,$fences);
        vp3_private_analytics_authority_current_v1($pdo,$scope,false);
        foreach($fences as $fence)$fence();
        if($owns){$pdo->commit();
            if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->beginTransaction();vp3_private_analytics_authority_current_v1($pdo,$scope,false);
            foreach($fences as $fence)$fence();$pdo->commit();}
        return $result;
    }catch(Throwable $error){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $error;}
}
function vp3_product_marketing_original_scope_v1(array $scope,int $agent,int $chat): array
{
    if($scope['agent_id']!==$agent)throw new RuntimeException('This product belongs to another private Agent.',404);
    $scope['conversation_id']=$chat;return $scope;
}
function vp3_product_marketing_period_v1(PDO $pdo,array $scope,array $selection,DateTimeImmutable $now): array
{
    if(array_diff(array_keys($selection),['period','ref'])||isset($selection['period'],$selection['ref']))throw new RuntimeException('Choose one reporting period.',422);
    if(array_key_exists('ref',$selection)){
        if(!is_string($selection['ref']))throw new RuntimeException('Choose a canonical reporting period.',422);
        return vp3_profile_analytics_period_decode_v1($selection['ref'],$now);
    }
    $days=$selection['period']??7;if(!is_int($days)||!in_array($days,[1,7,30],true))throw new RuntimeException('Choose a supported reporting period.',422);
    [$zone,$source]=vp3_profile_analytics_timezone_v1($pdo,$scope);
    $cutoff=vp3_profile_analytics_local_day_start_v1($now->setTimezone(new DateTimeZone($zone)))->getTimestamp();
    return vp3_profile_analytics_period_decode_v1(vp3_profile_analytics_period_ref_v1($days,$cutoff,$zone,$source),$now);
}
function vp3_product_marketing_url_v1(array $identity,?string $period=null): string
{
    $scope=$identity['original_scope'];$params=['agent'=>$scope['agent_id']?:'system','conversation_id'=>$scope['conversation_id'],'marketing_plan'=>$identity['product_id']];
    if($period!==null){$params['marketing_publication']=$identity['publication_id'];$params['marketing_period']=$period;}
    return url('/chat.php?'.http_build_query($params,'','&',PHP_QUERY_RFC3986));
}

/** Historical publication source proof does not require today's public visibility. */
function vp3_product_marketing_publication_v1(PDO $pdo,array $scope,string $publication): array
{
    if(!preg_match('/\A[a-f0-9]{32}\z/D',$publication))throw new RuntimeException('Choose an exact publication.',422);
    $owner=(int)$scope['user']['id'];
    $p=vp3_profile_analytics_rows_v1($pdo,'SELECT * FROM agent_promotion_publications_v1 WHERE publication_id=? AND owner_user_id=?',[$publication,$owner],true)[0]??null;
    if(!$p)throw new RuntimeException('This publication is unavailable.',404);
    $original=vp3_product_marketing_original_scope_v1($scope,(int)$p['agent_id'],(int)$p['conversation_id']);
    vp3_private_analytics_authority_current_v1($pdo,$original,false);
    $creative=vp3_profile_analytics_rows_v1($pdo,'SELECT * FROM agent_commerce_promotions_v1 WHERE id=? AND owner_user_id=?',[$p['promotion_id'],$owner],true)[0]??null;
    $product=vp3_profile_analytics_rows_v1($pdo,'SELECT * FROM agent_commerce_products_v800 WHERE id=? AND owner_user_id=?',[(int)$p['product_id'],$owner],true)[0]??null;
    $launch=vp3_profile_analytics_rows_v1($pdo,'SELECT * FROM agent_product_launches_v1 WHERE product_id=? AND owner_user_id=?',[(int)$p['product_id'],$owner],true)[0]??null;
    if(!$creative||!$product||!$launch||!in_array($creative['status'],['published','paused'],true))throw new RuntimeException('The original publication source is unavailable.',404);
    foreach(['product_id','agent_id','conversation_id','source_asset_id','draft_key'] as $key)
        if((string)$creative[$key]!== (string)$p[$key])throw new RuntimeException('The publication source changed.',409);
    foreach(['agent_id','conversation_id'] as $key)if((int)$launch[$key]!== (int)$p[$key])throw new RuntimeException('The original product plan changed.',409);
    $draft=vp3_profile_analytics_rows_v1($pdo,'SELECT draft_json FROM agent_commerce_marketing_drafts_v1 WHERE owner_user_id=? AND product_id=? AND request_key=?',[$owner,(int)$p['product_id'],$p['draft_key']],true)[0]??null;
    $data=$draft?vp3_profile_analytics_json_v1($draft['draft_json']):null;
    if(!$data||($data['product_id']??null)!==(int)$p['product_id']||($data['asset_id']??null)!==(int)$p['source_asset_id']
        ||($data['asset_sha256']??null)!==$creative['source_sha256'])throw new RuntimeException('The original saved draft is unavailable.',409);
    $sourceSql='SELECT * FROM user_media_assets WHERE id=? AND user_id=?';$sourceArgs=[(int)$p['source_asset_id'],$owner];
    $currentSource=vp3_profile_analytics_rows_v1($pdo,$sourceSql,$sourceArgs,true);
    if(count($currentSource)!==1||($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'&&$currentSource!==vp3_profile_analytics_rows_v1($pdo,$sourceSql,$sourceArgs)))
        throw new RuntimeException('The original source image is unavailable in the current snapshot.',409);
    $source=initiative_work_product_source_v1($pdo,$owner,$original,['source_id'=>(string)$p['source_asset_id']]);
    if(!hash_equals((string)$creative['source_sha256'],$source['digest']))throw new RuntimeException('The original source image changed.',409);
    $asset=vp3_profile_analytics_rows_v1($pdo,'SELECT * FROM user_media_assets WHERE id=? AND user_id=?',[(int)$creative['asset_id'],$owner],true)[0]??null;
    $digest=$asset?initiative_work_marketing_promotion_digest_v1($asset):null;
    if(!$digest||!hash_equals((string)$creative['asset_sha256'],$digest))throw new RuntimeException('The original reviewed creative changed.',409);
    $stamp=vp3_profile_analytics_utc_v1($p['created_at']);if($stamp===null)throw new RuntimeException('The publication time is unavailable.',409);
    $identity=['publication_id'=>$publication,'product_id'=>(int)$p['product_id'],'product_title'=>vp3_product_marketing_text_v1($product['title']),'promotion_id'=>$p['promotion_id'],
        'original_scope'=>['agent_id'=>(int)$p['agent_id'],'conversation_id'=>(int)$p['conversation_id']],
        'source_id'=>(string)$p['source_asset_id'],'draft_id'=>$p['draft_key'],'creative_id'=>$p['promotion_id'],
        'plan_ref'=>vp3_product_marketing_plan_ref_v1((int)$p['product_id']),'published_at_utc_ms'=>$stamp,
        'publication_status'=>$creative['public_key']===$publication?($creative['status']==='paused'?'paused':'current'):'historical'];
    $identity['source_revision']=vp3_profile_analytics_hash_v1([$owner,$identity,$source['digest'],$digest,$data]);
    $identity['resume']=['owner_user_id'=>$owner]+$identity['original_scope']+array_intersect_key($identity,array_flip(['product_id','source_id','draft_id','creative_id','publication_id']))+['stage'=>'promotion_outcomes'];
    return $identity+['_publication'=>$p];
}

/** Existing native card is related presentation, never the fixed-window destination. */
function vp3_product_marketing_native_message_v1(PDO $pdo,array $identity,int $owner): ?array
{
    if(!vp3_profile_analytics_table_v1($pdo,'chat_messages'))return null;
    $rows=vp3_profile_analytics_rows_v1($pdo,"SELECT id,context_json FROM chat_messages WHERE conversation_id=? AND role='assistant' AND user_id IS NULL ORDER BY id",[$identity['original_scope']['conversation_id']],true);
    foreach($rows as $row){$ctx=vp3_profile_analytics_json_v1($row['context_json']);$marker=$ctx['product_launch']??null;
        if(($ctx['source']??null)!=='agent_promotion_outcome'||($ctx['promotion_outcome']['publication_id']??null)!==$identity['publication_id']||!is_array($marker))continue;
        $expected=$identity['resume'];unset($expected['publication_id']);
        foreach($expected as $key=>$value)if(($marker[$key]??null)!==$value)continue 2;
        $s=$identity['original_scope'];$mid=(int)$row['id'];
        return ['kind'=>'chat','label'=>'View recorded outcome','href'=>url('/chat.php?'.http_build_query(['agent'=>$s['agent_id']?:'system','conversation_id'=>$s['conversation_id'],'message_id'=>$mid],'','&',PHP_QUERY_RFC3986)),
            'agent_id'=>$s['agent_id'],'conversation_id'=>$s['conversation_id'],'message_id'=>$mid];
    }return null;
}

/** Current native principal fence for literal visitor text, independent of
 * whether the owner currently publishes their Profile or promotion. */
function vp3_product_marketing_exchange_current_v1(PDO $pdo,int $owner,array $turn): bool
{
    $cid=(int)$turn['conversation_id'];$visitor=(int)$turn['visitor_user_id'];
    $c=vp3_profile_analytics_rows_v1($pdo,'SELECT owner_user_id,profile_agent_id,profile_session_id,visitor_user_id,status FROM profile_agent_conversations WHERE id=?',[$cid],true)[0]??null;
    $sessions=vp3_profile_analytics_rows_v1($pdo,'SELECT id,owner_user_id,session_key FROM profile_visit_sessions WHERE id=?',[(int)$turn['profile_session_id']],true);
    $agent=vp3_profile_analytics_rows_v1($pdo,'SELECT owner_user_id,is_active FROM user_agents WHERE id=?',[(int)$turn['profile_agent_id']],true)[0]??null;
    $bindings=vp3_profile_analytics_rows_v1($pdo,"SELECT context_json FROM profile_agent_messages WHERE conversation_id=? AND sender_type='system' ORDER BY id",[$cid],true);
    $binding=count($bindings)===1?vp3_profile_analytics_json_v1($bindings[0]['context_json']):null;$snapshot=vp3_profile_analytics_json_v1($turn['accepted_json']);
    if(!$c||!$agent||(int)$agent['owner_user_id']!==$owner||empty($agent['is_active'])||count($sessions)!==1||(int)$sessions[0]['owner_user_id']!==$owner
        ||(int)$c['owner_user_id']!==$owner||(int)$c['profile_agent_id']!==(int)$turn['profile_agent_id']||(int)$c['profile_session_id']!==(int)$turn['profile_session_id']
        ||(int)($c['visitor_user_id']??0)!==$visitor||!in_array($c['status'],['open','owner_joined','resolved'],true)
        ||!vp3_profile_agent_conversation_authority_valid_v1($binding,$owner)||$binding['transport']!=='native'||$binding['visitor_user_id']!==$visitor
        ||(int)($binding['profile_agent_id']??0)!==(int)$turn['profile_agent_id']||(int)($binding['profile_session_id']??0)!==(int)$turn['profile_session_id']
        ||!hash_equals($binding['credential_hash'],(string)$turn['credential_hash']))return false;
    $session=[];foreach(['id','owner_user_id','session_key'] as $key)$session[$key]=(string)$sessions[0][$key];
    if(!is_string($snapshot['session_fingerprint']??null)||!hash_equals($snapshot['session_fingerprint'],vp3_profile_analytics_hash_v1($session)))return false;
    if($visitor===0)return ($snapshot['visitor_auth_revision']??null)==='';
    $user=vp3_profile_analytics_rows_v1($pdo,'SELECT is_active,password_hash FROM users WHERE id=?',[$visitor],true)[0]??null;
    $blocks=vp3_profile_analytics_rows_v1($pdo,'SELECT blocker_user_id,blocked_user_id FROM user_blocks WHERE (blocker_user_id=? AND blocked_user_id=?) OR (blocker_user_id=? AND blocked_user_id=?)',[$owner,$visitor,$visitor,$owner],true);
    return $user&&!empty($user['is_active'])&&!$blocks&&is_string($snapshot['visitor_auth_revision']??null)
        &&hash_equals($snapshot['visitor_auth_revision'],hash('sha256',$visitor.'|'.(string)$user['password_hash']));
}

/** Literal accepted question and its exact receipt-bound promotion reply. */
function vp3_product_marketing_exchanges_v1(PDO $pdo,int $owner,string $publication,array $history,?int $conversation=null,?bool &$incomplete=null): array
{
    $out=[];$candidates=0;
    $publicationRow=vp3_profile_analytics_rows_v1($pdo,'SELECT created_at FROM agent_promotion_publications_v1 WHERE publication_id=? AND owner_user_id=?',[$publication,$owner],true)[0]??null;
    $publicationAt=vp3_profile_analytics_utc_v1($publicationRow['created_at']??null);
    if($publicationAt===null){$incomplete=true;return [];}
    foreach($history['messages'] as $message){
        if($message['sender_type']!=='agent'||($conversation!==null&&(int)$message['conversation_id']!==$conversation))continue;
        $ctx=vp3_profile_analytics_json_v1($message['context_json']??null);if(($ctx['product_promotion_key']??null)!==$publication)continue;$candidates++;
        $marker=$ctx['profile_handoff']??null;
        if(!is_array($marker)||($marker['contract']??null)!=='vp3.profile-handoff.v1'||!is_int($marker['turn_id']??null))continue;
        $turn=vp3_profile_analytics_rows_v1($pdo,'SELECT * FROM profile_agent_handoff_turns_pa3 WHERE id=? AND owner_user_id=?',[$marker['turn_id'],$owner],true)[0]??null;
        if(!$turn||!vp3_profile_analytics_turn_valid_v1($turn,$history,$owner)||!vp3_product_marketing_exchange_current_v1($pdo,$owner,$turn)||($marker['visitor_message_id']??null)!==(int)$turn['visitor_message_id']
            ||(int)$message['conversation_id']!==(int)$turn['conversation_id']||$message['sender_user_id']!==null||(int)$message['id']<=(int)$turn['visitor_message_id'])continue;
        $at=vp3_profile_analytics_utc_v1($turn['created_at']);$replyAt=vp3_profile_analytics_utc_v1($message['created_at']);if($at===null||$replyAt===null||$replyAt<$at||$replyAt<$publicationAt)continue;
        $run=vp3_profile_analytics_rows_v1($pdo,'SELECT * FROM agent_workflow_runs WHERE id=? AND owner_user_id=?',[(int)$turn['run_id'],$owner],true)[0]??null;
        $action=vp3_profile_analytics_rows_v1($pdo,'SELECT * FROM agent_workflow_actions WHERE id=? AND owner_user_id=?',[(int)$turn['action_id'],$owner],true)[0]??null;
        $key=hash('sha256',$owner.'|'.$turn['run_id'].'|'.$turn['action_id'].'|profile-turn:'.$turn['id'].':reply');
        $receipts=vp3_profile_analytics_rows_v1($pdo,'SELECT * FROM agent_workflow_receipts WHERE owner_user_id=? AND receipt_key=?',[$owner,$key],true);
        $receipt=count($receipts)===1?$receipts[0]:null;$result=$receipt?vp3_profile_analytics_json_v1($receipt['result_json']):null;
        if(!$run||!$action||!$receipt||$receipt['status']!=='completed'||$run['source_kind']!=='profile_visitor_turn'||$run['workflow_type']!=='profile_visitor_reply'
            ||$run['source_key']!=='profile-turn:'.$turn['id']||(int)$run['agent_id']!==(int)$turn['profile_agent_id']
            ||(int)$action['run_id']!==(int)$run['id']||$action['capability_key']!=='profile.visitor.reply'||$action['action_key']!==$run['source_key']
            ||(int)$receipt['run_id']!==(int)$run['id']||(int)$receipt['action_id']!==(int)$action['id']
            ||vp3_profile_analytics_id_v1($result['turn_id']??null)!==(int)$turn['id']||vp3_profile_analytics_id_v1($result['conversation_id']??null)!==(int)$turn['conversation_id']
            ||vp3_profile_analytics_id_v1($result['visitor_message_id']??null)!==(int)$turn['visitor_message_id']||vp3_profile_analytics_id_v1($result['answer_message_id']??null)!==(int)$message['id'])continue;
        $visitor=$history['messages'][(int)$turn['visitor_message_id']];
        $out[]=['public_conversation_id'=>(int)$turn['conversation_id'],'visitor_message_id'=>(int)$visitor['id'],'answer_message_id'=>(int)$message['id'],
            'at_utc_ms'=>$at,'reply_at_utc_ms'=>$replyAt,'excerpt'=>vp3_product_marketing_text_v1($visitor['message']),
            'evidence_revision'=>vp3_profile_analytics_hash_v1([$turn['accepted_json'],$message['context_json'],(int)$receipt['id'],$result,$at,$replyAt])];
    }
    $incomplete=count($out)<$candidates;return $out;
}

function vp3_product_marketing_exchange_fence_v1(PDO $pdo,int $owner,string $publication,array $expected): void
{
    if(!$expected)return;
    $fresh=vp3_product_marketing_exchanges_v1($pdo,$owner,$publication,vp3_profile_analytics_native_history_v1($pdo,$owner));
    $map=[];foreach($fresh as $item)$map[$item['visitor_message_id'].'|'.$item['answer_message_id']]=$item['evidence_revision'];
    foreach($expected as $item)if(($map[$item['visitor_message_id'].'|'.$item['answer_message_id']]??null)!==$item['evidence_revision'])
        throw new RuntimeException('The native visitor source changed during reporting.',409);
}

function vp3_product_marketing_outcome_read_v1(PDO $pdo,array $scope,string $publication,array $period,DateTimeImmutable $now,array &$fences): array
{
    $identity=vp3_product_marketing_publication_v1($pdo,$scope,$publication);$owner=(int)$scope['user']['id'];$window=$period['window'];
    $fences[]=static function()use($pdo,$scope,$publication,$identity):void{
        $fresh=vp3_product_marketing_publication_v1($pdo,$scope,$publication);
        if(!hash_equals($identity['source_revision'],$fresh['source_revision']))throw new RuntimeException('The publication source changed during reporting.',409);
    };
    $historyUnavailable=false;
    try{$history=vp3_profile_analytics_native_history_v1($pdo,$owner);}
    catch(RuntimeException $error){if($error->getCode()!==424)throw $error;$historyUnavailable=true;$history=['valid'=>[],'conversations'=>[],'messages'=>[]];}
    $clicks=vp3_profile_analytics_rows_v1($pdo,'SELECT * FROM agent_promotion_clicks_v1 WHERE publication_id=? ORDER BY click_id',[$publication],true);
    $clickMap=[];$counts=['current'=>['accepted_promotion_clicks'=>0,'native_promotion_conversations'=>0,'checkout_started_orders'=>0,'promotion_associated_exchanges'=>0],
        'previous'=>['accepted_promotion_clicks'=>0,'native_promotion_conversations'=>0,'checkout_started_orders'=>0,'promotion_associated_exchanges'=>0]];
    $gaps=array_fill_keys(array_keys($counts['current']),false);$placements=['profile'=>['current'=>0,'previous'=>0],'profile_agent'=>['current'=>0,'previous'=>0]];$native=[];$evidence=[];
    if(is_file(__DIR__.'/profile-promotion-native-click-v1.php'))require_once __DIR__.'/profile-promotion-native-click-v1.php';
    foreach($clicks as $click){
        $at=vp3_profile_analytics_utc_v1($click['created_at']);$placement=$click['placement'];
        if($at===null||$at<$identity['published_at_utc_ms']||!isset($placements[$placement])||!preg_match('/\A[a-f0-9]{32}\z/D',$click['click_id'])){$gaps['accepted_promotion_clicks']=$gaps['native_promotion_conversations']=true;continue;}
        $clickMap[$click['click_id']]=$click+['_at'=>$at];$side=vp3_profile_analytics_window_key_v1($at,$window);if($side===null)continue;
        $counts[$side]['accepted_promotion_clicks']++;$placements[$placement][$side]++;
        $evidence[]=['click',$click['click_id'],$placement,$at];
        if($placement==='profile_agent'){
            $proof=function_exists('vp3_profile_promotion_native_click_proof_v1')?vp3_profile_promotion_native_click_proof_v1($pdo,$click,$identity['_publication'],true):null;
            if(!$proof)$gaps['native_promotion_conversations']=true;
            else{$native[$side][(int)$proof['public_conversation_id']]=true;$evidence[]=['native_click',$click['click_id'],vp3_profile_analytics_hash_v1($proof)];}
        }
    }
    $bindings=vp3_profile_analytics_rows_v1($pdo,'SELECT b.order_id,b.click_id FROM agent_promotion_orders_v1 b JOIN agent_promotion_clicks_v1 c ON c.click_id=b.click_id WHERE c.publication_id=? ORDER BY b.order_id',[$publication],true);
    $bound=[];foreach($bindings as $row)$bound[(int)$row['order_id']]=$row['click_id'];
    $orders=vp3_profile_analytics_rows_v1($pdo,'SELECT * FROM agent_commerce_orders_v800 WHERE owner_user_id=? ORDER BY id',[$owner],true);
    $subset=[];$attributionGap=false;$seen=[];
    foreach($orders as $order){
        $oid=(int)$order['id'];$meta=vp3_profile_analytics_json_v1($order['metadata_json']);$cid=$meta['product_promotion_click_v1']??null;
        if(!isset($bound[$oid])&&(!is_string($cid)||!isset($clickMap[$cid])))continue;
        $seen[$oid]=true;$click=is_string($cid)?($clickMap[$cid]??null):null;
        $items=vp3_profile_analytics_rows_v1($pdo,'SELECT * FROM agent_commerce_order_items_v800 WHERE order_id=? ORDER BY id',[$oid],true);
        $item=count($items)===1?$items[0]:null;$im=$item?vp3_profile_analytics_json_v1($item['metadata_json']):null;$at=vp3_profile_analytics_utc_v1($meta['product_promotion_order_utc_v1']??null);
        if(!$click||($bound[$oid]??null)!==$cid||!$item||(int)$item['product_id']!==$identity['product_id']
            ||($im['product_promotion_click_v1']??null)!==$cid||($im['product_promotion_order_utc_v1']??null)!==($meta['product_promotion_order_utc_v1']??null)
            ||$at===null||$at<$click['_at']||$at>$click['_at']+86400000){$attributionGap=true;continue;}
        $subset[]=$oid;
        $product=vp3_profile_analytics_rows_v1($pdo,'SELECT id,owner_user_id FROM agent_commerce_products_v800 WHERE id=?',[$identity['product_id']],true)[0]??null;
        $connection=vp3_profile_analytics_rows_v1($pdo,'SELECT id,owner_user_id,provider,external_account_id FROM agent_commerce_provider_connections_v800 WHERE id=?',[(int)$order['connection_id']],true)[0]??null;
        $merchant=(int)($order['workspace_owner_user_id']??0)>0?(int)$order['workspace_owner_user_id']:$owner;
        if(!$product||!vp3_profile_analytics_order_v1($pdo,$order,$items,[$identity['product_id']=>$product],$history)
            ||!$connection||(int)$connection['owner_user_id']!==$merchant||$connection['provider']!==$order['provider_snapshot']
            ||$connection['external_account_id']!==$order['external_account_snapshot']){$gaps['checkout_started_orders']=true;continue;}
        $attempts=vp3_profile_analytics_rows_v1($pdo,'SELECT * FROM agent_commerce_checkout_attempts_v800 WHERE order_id=? ORDER BY id',[$oid],true);
        $opened=[];$undated=false;
        foreach($attempts as $attempt){
            $open=vp3_profile_analytics_epoch_v1($attempt['opened_at_utc_ms']??null);
            $established=(string)($attempt['external_session_id']??'')!==''&&!str_starts_with((string)$attempt['external_session_id'],'pending:')&&(string)($attempt['checkout_url']??'')!=='';
            if(!$open){if($established)$undated=true;continue;}
            $amount=vp3_profile_analytics_uint_v1($attempt['amount_cents']);$currency=strtoupper((string)$order['currency']);
            if(!$established||$open<$at||$amount===null||$amount==='0'||(int)$attempt['connection_id']!==(int)$order['connection_id']
                ||$attempt['provider']!==$order['provider_snapshot']||!vp3_profile_analytics_attempt_v1($attempt,$order,$amount,$currency)){$undated=true;continue;}
            $opened[]=['at'=>$open,'id'=>(int)$attempt['id']];
        }
        if($undated){$gaps['checkout_started_orders']=true;continue;}
        usort($opened,static fn(array $a,array $b):int=>[$a['at'],$a['id']]<=>[$b['at'],$b['id']]);$first=$opened[0]??null;
        if($first&&($side=vp3_profile_analytics_window_key_v1($first['at'],$window))!==null){$counts[$side]['checkout_started_orders']++;$evidence[]=['checkout',$oid,$first['id'],$first['at']];}
    }
    if(array_diff_key($bound,$seen))$attributionGap=true;
    $commerce=vp3_profile_analytics_commerce_v1($pdo,$owner,$window,$history,$subset);
    $exchanges=[];$exchangeUnavailable=$historyUnavailable;$exchangeReason=$historyUnavailable?'storage_unavailable':'profile_permission_required';
    $profileAuthority=true;
    try{vp3_private_analytics_authority_current_v1($pdo,$scope,true);}
    catch(RuntimeException $error){
        if(!in_array($error->getCode(),[403,503],true)||vp3_profile_analytics_retryable_v1($error))throw $error;
        $profileAuthority=false;$exchangeUnavailable=true;
        $exchangeReason=$error->getCode()===403?'profile_permission_required':'storage_unavailable';
    }
    if($profileAuthority){
        if(!$historyUnavailable){$exchanges=vp3_product_marketing_exchanges_v1($pdo,$owner,$publication,$history,null,$incomplete);$gaps['promotion_associated_exchanges']=$incomplete;}
        $fences[]=static fn()=>vp3_private_analytics_authority_current_v1($pdo,$scope,true);
    }
    $visibleExchanges=[];
    foreach($exchanges as $exchange)if($exchange['reply_at_utc_ms']<$window['to_utc_ms']&&($side=vp3_profile_analytics_window_key_v1($exchange['at_utc_ms'],$window))!==null){
        // Both admission and saved promotion reply must exist by that period's cutoff.
        $cutoff=$side==='current'?$window['to_utc_ms']:$window['previous']['to_utc_ms'];if($exchange['reply_at_utc_ms']>=$cutoff)continue;
        if($side==='current')$visibleExchanges[]=$exchange+['url'=>url('/profile-agent.php?tab=inbox&conversation='.$exchange['public_conversation_id'])];
        $counts[$side]['promotion_associated_exchanges']++;$evidence[]=['exchange',$exchange['visitor_message_id'],$exchange['answer_message_id'],$exchange['evidence_revision']];}
    $shown=array_slice($visibleExchanges,-4);
    if($shown)$fences[]=static fn()=>vp3_product_marketing_exchange_fence_v1($pdo,$owner,$publication,$shown);
    $catalog=[
        'accepted_promotion_clicks'=>['Accepted promotion clicks','Saved first-party CTA click records for this exact publication; retries of one click identifier count once, not people.'],
        'native_promotion_conversations'=>['Conversations with verified promotion clicks','Distinct native public conversations with a proven accepted click on this publication during this period; not new conversations or people.'],
        'checkout_started_orders'=>['Orders with checkout links ready','Exactly attributed orders whose first provider-confirmed usable checkout link became available in this period. This records link availability, not a buyer opening the provider; reservations and retries do not create another first availability.'],
        'promotion_associated_exchanges'=>['Accepted exchanges showing this promotion','Receipt-bound replies showing this exact publication, linked to an accepted native visitor turn in the period and answered before its cutoff; not inferred purchase intent.'],
    ];
    $metrics=[];$coverage='ready';
    foreach($catalog as $key=>[$label,$definition]){
        $points=[];foreach(['current','previous'] as $side){if($key==='native_promotion_conversations')$counts[$side][$key]=count($native[$side]??[]);
            $partial=$gaps[$key]||($key==='checkout_started_orders'&&$attributionGap);
            $points[$side]=$key==='promotion_associated_exchanges'&&$exchangeUnavailable?vp3_profile_analytics_point_v1(null,'unavailable',$exchangeReason):
                vp3_profile_analytics_point_v1($counts[$side][$key],$partial?'partial':'complete',$partial?'source_proof_incomplete':'complete');
            if($points[$side]['coverage']!=='complete')$coverage='limited';}
        $metrics[]=vp3_profile_analytics_metric_v1($key,'commerce',$label,'count',$definition,$points['current'],$points['previous']);
    }
    foreach(['verified_paid_orders','verified_refunded_orders'] as $key){$points=$commerce['points'][$key];
        if($attributionGap)foreach($points as &$point){if($point['coverage']==='complete'){$point['observed_value']=$point['value'];$point['value']=null;$point['coverage']='partial';}$point['reason']='source_proof_incomplete';}unset($point);
        $c=vp3_profile_analytics_catalog_v1()[$key];$metrics[]=vp3_profile_analytics_metric_v1($key,'commerce',$c[1],$c[2],$c[3].' Restricted to this exact publication.',$points['current'],$points['previous']);
        if($points['current']['coverage']!=='complete'||$points['previous']['coverage']!=='complete')$coverage='limited';}
    $money=$commerce['money'];if($attributionGap)foreach($money as &$row){foreach(['current','previous'] as $side){$p=&$row[$side];if($p['coverage']==='complete'){$p['observed_value_minor']=$p['value_minor'];$p['value_minor']=null;$p['coverage']='partial';}$p['reason']='source_proof_incomplete';unset($p);}$row['comparison']['delta_minor']=null;}unset($row);
    $placementPoints=[];foreach($placements as $placement=>$values)$placementPoints[]=['placement'=>$placement,
        'current'=>vp3_profile_analytics_point_v1($values['current'],$gaps['accepted_promotion_clicks']?'partial':'complete',$gaps['accepted_promotion_clicks']?'source_proof_incomplete':'complete'),
        'previous'=>vp3_profile_analytics_point_v1($values['previous'],$gaps['accepted_promotion_clicks']?'partial':'complete',$gaps['accepted_promotion_clicks']?'source_proof_incomplete':'complete')];
    $ref=vp3_product_marketing_outcome_ref_v1($publication,$period['ref'],$now);
    $evidence=array_merge($evidence,$commerce['evidence']);usort($evidence,static fn(array $a,array $b):int=>strcmp(json_encode($a),json_encode($b)));
    $revision=vp3_profile_analytics_hash_v1([$ref,$identity['source_revision'],$metrics,$money,$placementPoints,$coverage,$evidence]);
    $actions=[['kind'=>'url','label'=>'Review this publication','href'=>vp3_product_marketing_url_v1($identity,$period['ref'])],
        ['kind'=>'url','label'=>'Open original marketing plan','href'=>vp3_product_marketing_url_v1($identity)],
        ['kind'=>'url','label'=>'Open product listing','href'=>url('/profile-commerce-products.php?workspace_product='.$identity['product_id'])]];
    $nativeMessage=vp3_product_marketing_native_message_v1($pdo,$identity,$owner);if($nativeMessage)$actions[]=$nativeMessage;
    unset($identity['_publication']);
    return ['contract'=>'vp3.product-marketing-outcome.v1','ref'=>$ref,'revision'=>$revision,'period_ref'=>$period['ref'],
        'window'=>$window,'period_options'=>$period['period_options'],'observed_at_utc_ms'=>$now->getTimestamp()*1000,'coverage'=>$coverage,
        'metrics'=>$metrics,'money'=>$money,'placements'=>$placementPoints,'actions'=>$actions,'associations'=>['coverage'=>$exchangeUnavailable?'unavailable':($gaps['promotion_associated_exchanges']?'partial':'complete'),'items'=>$shown],
        'presentation_owner'=>'initiative_promotion_outcome_v1','product_marketing_presentation_owned'=>true,'legacy_delivered'=>$nativeMessage!==null]+$identity;
}
function vp3_product_marketing_outcome_v1(PDO $pdo,array $scope,string $publication,array $selection=[],?DateTimeImmutable $now=null): array
{
    $now??=new DateTimeImmutable('now',new DateTimeZone('UTC'));
    return vp3_product_marketing_snapshot_v1($pdo,$scope,static function(array $scope,array &$fences)use($pdo,$publication,$selection,$now):array{
        return vp3_product_marketing_outcome_read_v1($pdo,$scope,$publication,vp3_product_marketing_period_v1($pdo,$scope,$selection,$now),$now,$fences);
    });
}
