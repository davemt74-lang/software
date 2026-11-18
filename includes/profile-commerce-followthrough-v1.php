<?php
declare(strict_types=1);

require_once __DIR__.'/profile-commerce-followthrough-ref-v1.php';
require_once __DIR__.'/profile-analytics-period-v1.php';
require_once __DIR__.'/profile-promotion-capture-v1.php';

const VP3_PROFILE_COMMERCE_PUBLICATIONS_PAGE_V1=20;
const VP3_PROFILE_COMMERCE_EXCHANGES_PAGE_V1=20;

function vp3_profile_commerce_text_v1(mixed $value,int $limit): string
{
    if(!is_string($value)||!mb_check_encoding($value,'UTF-8'))return '';
    $value=strip_tags($value);
    $value=preg_replace('~(?:https?://|\bwww\.)[^\s<>]+~iu','[link in source]',$value)??'';
    $value=preg_replace('/[<>\p{Cc}\p{Cf}]|\s+/u',' ',$value)??'';
    return mb_substr(trim($value),0,$limit);
}
function vp3_profile_commerce_id_v1(mixed $value,bool $zero=false): ?int
{
    if((!is_string($value)&&!is_int($value))||!preg_match($zero?'/\A(?:0|[1-9][0-9]{0,15})\z/D':'/\A[1-9][0-9]{0,15}\z/D',(string)$value))return null;
    $id=(int)$value;return $id<=9007199254740991?$id:null;
}
function vp3_profile_commerce_begin_v1(PDO $pdo): void
{
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->beginTransaction();
}

/** Own only our snapshot; caller transactions and their current locks remain theirs. */
function vp3_profile_commerce_read_v1(PDO $pdo,array $scope,bool $requireProfile,callable $read): array
{
    $owns=!$pdo->inTransaction();
    try{
        if($owns)vp3_profile_commerce_begin_v1($pdo);
        $fresh=vp3_profile_analytics_authority_current_v1($pdo,$scope,$requireProfile);
        [$result,$verify]=$read($fresh);
        vp3_profile_analytics_authority_current_v1($pdo,$fresh,$requireProfile);
        if($owns){$pdo->commit();vp3_profile_commerce_begin_v1($pdo);}
        $fresh=vp3_profile_analytics_authority_current_v1($pdo,$scope,$requireProfile);
        $currentActions=$verify($fresh);
        if(is_array($currentActions))$result=array_replace($result,$currentActions);
        if($owns)$pdo->commit();
        return $result;
    }catch(Throwable $error){
        if($owns&&$pdo->inTransaction())$pdo->rollBack();
        if(vp3_profile_analytics_retryable_v1($error))throw $error;
        if($error instanceof PDOException)throw new RuntimeException('Promotion reporting storage is unavailable.',503,$error);
        throw $error;
    }
}

/** Current immutable publication identity, independent of its present public visibility. */
function vp3_profile_commerce_publication_v1(PDO $pdo,array $scope,string $id): array
{
    if(!preg_match('/\A[a-f0-9]{32}\z/D',$id))throw new RuntimeException('Choose an exact promotion publication.',422);
    $tables=['agent_promotion_publications_v1','agent_commerce_promotions_v1','agent_commerce_products_v800',
        'agent_commerce_marketing_drafts_v1','user_media_assets'];
    if(!vp3_profile_analytics_tables_v1($pdo,$tables))throw new RuntimeException('Promotion publication storage is unavailable.',503);
    $owner=(int)$scope['user']['id'];
    $pub=vp3_profile_analytics_rows_v1($pdo,'SELECT * FROM agent_promotion_publications_v1 WHERE publication_id=? AND owner_user_id=?',[$id,$owner],true)[0]??null;
    if(!$pub||(int)$pub['agent_id']!==$scope['agent_id']||(int)$pub['conversation_id']!==$scope['conversation_id'])
        throw new RuntimeException('Open the original private Chat for this publication.',404);
    foreach(['product_id','conversation_id','source_asset_id'] as $field)if(vp3_profile_commerce_id_v1($pub[$field]??null)===null)
        throw new RuntimeException('This publication binding could not be verified.',409);
    $recordedAt=vp3_profile_analytics_utc_v1($pub['created_at']??null);
    if(vp3_profile_commerce_id_v1($pub['agent_id']??null,true)===null||$recordedAt===null)
        throw new RuntimeException('This publication binding could not be verified.',409);
    if(!preg_match('/\A[a-f0-9]{32}\z/D',(string)($pub['promotion_id']??''))
        ||!preg_match('/\A[a-f0-9]{64}\z/D',(string)($pub['draft_key']??'')))throw new RuntimeException('This publication binding could not be verified.',409);
    $promotion=vp3_profile_analytics_rows_v1($pdo,'SELECT * FROM agent_commerce_promotions_v1 WHERE id=? AND owner_user_id=?',[$pub['promotion_id'],$owner],true)[0]??null;
    $product=vp3_profile_analytics_rows_v1($pdo,'SELECT * FROM agent_commerce_products_v800 WHERE id=? AND owner_user_id=?',[(int)$pub['product_id'],$owner],true)[0]??null;
    if(!$promotion||!$product)throw new RuntimeException('The publication source is unavailable.',404);
    foreach(['product_id','agent_id','conversation_id','source_asset_id','draft_key'] as $key)
        if((string)$promotion[$key]!== (string)$pub[$key])throw new RuntimeException('The publication source changed.',409);
    if(!in_array($promotion['status'],['saved','published','paused'],true)
        ||!preg_match('/\A[a-f0-9]{64}\z/D',(string)$promotion['source_sha256']))throw new RuntimeException('The saved creative source could not be verified.',409);
    $draftRows=vp3_profile_analytics_rows_v1($pdo,'SELECT draft_json FROM agent_commerce_marketing_drafts_v1 WHERE owner_user_id=? AND product_id=? AND request_key=?',[$owner,(int)$pub['product_id'],$pub['draft_key']],true);
    $draft=count($draftRows)===1?vp3_profile_analytics_json_v1($draftRows[0]['draft_json']):null;
    $source=vp3_profile_analytics_rows_v1($pdo,'SELECT id,user_id,media_type,mime_type FROM user_media_assets WHERE id=?',[(int)$pub['source_asset_id']],true)[0]??null;
    if(!$draft||($draft['product_id']??null)!==(int)$pub['product_id']||($draft['asset_id']??null)!==(int)$pub['source_asset_id']
        ||($draft['asset_sha256']??null)!==$promotion['source_sha256']||!$source||(int)$source['user_id']!==$owner||$source['media_type']!=='photo')
        throw new RuntimeException('The exact draft and original image are unavailable.',409);
    $title=vp3_profile_commerce_text_v1($draft['title']??'',190);
    $safe=['publication_id'=>$id,'promotion_id'=>$pub['promotion_id'],'product_id'=>(int)$pub['product_id'],
        'chat_agent_id'=>(int)$pub['agent_id'],'chat_conversation_id'=>(int)$pub['conversation_id'],
        'source_asset_id'=>(int)$pub['source_asset_id'],'draft_key'=>$pub['draft_key'],'title'=>$title,
        'recorded_at_utc_ms'=>$recordedAt];
    // Campaign metadata is deliberately not projected without its own plugin
    // and merchant authority. The immutable internal ID still binds history.
    $proof=vp3_profile_analytics_hash_v1([$safe,$owner,$pub['campaign_id']??null,$pub['review_request']??null,
        $promotion['source_sha256'],(int)$source['user_id'],(int)$product['owner_user_id']]);
    return ['safe'=>$safe,'proof'=>$proof,'row'=>$pub,'promotion'=>$promotion,'draft'=>$draft];
}

function vp3_profile_commerce_period_v1(PDO $pdo,array $scope,array $selection,DateTimeImmutable $now): array
{
    if(isset($selection['ref'])){
        if(count($selection)!==1||!is_string($selection['ref']))throw new RuntimeException('Choose one exact promotion reporting period.',422);
        return vp3_profile_commerce_followthrough_decode_v1($selection['ref'],$now);
    }
    if(array_diff(array_keys($selection),['publication_id','period'])||!is_string($selection['publication_id']??null)
        ||!preg_match('/\A[a-f0-9]{32}\z/D',$selection['publication_id']))throw new RuntimeException('Choose an exact promotion publication.',422);
    $days=$selection['period']??1;
    if((!is_int($days)&&!is_string($days))||!in_array((string)$days,['1','7','30'],true))throw new RuntimeException('Choose a completed reporting period.',422);
    [$zone,$source]=vp3_profile_analytics_timezone_v1($pdo,$scope);$timezone=new DateTimeZone($zone);
    $cutoff=vp3_profile_analytics_day_start_v1($now->setTimezone($timezone)->format('Y-m-d'),$timezone)->getTimestamp();
    $code=match($source){'explicit'=>'e','schedule'=>'s',default=>'u'};
    $ref='marketing:ft:v1:'.$selection['publication_id'].':'.$days.':'.$cutoff.':'.$code.':'.rtrim(strtr(base64_encode($zone),'+/','-_'),'=');
    return vp3_profile_commerce_followthrough_decode_v1($ref,$now);
}

/** Current publishing state is a separate observation, never a fixed-period fact. */
function vp3_profile_commerce_publication_state_v1(array $publication): array
{
    $variant=$publication['promotion'];
    $same=($variant['public_key']??null)===$publication['safe']['publication_id'];
    $state=$same&&$variant['status']==='published'?'active':($same&&$variant['status']==='paused'?'paused':'historical');
    return ['availability'=>'ready','state'=>$state];
}

/** Choices expose only authorized private plan/publication facts, not visitor outcomes. */
function vp3_profile_commerce_publications_v1(PDO $pdo,array $scope,int $productId,string $cursor='',?DateTimeImmutable $now=null): array
{
    if(vp3_profile_commerce_id_v1($productId)===null)throw new RuntimeException('Choose an exact product.',422);
    $now??=new DateTimeImmutable('now',new DateTimeZone('UTC'));
    return vp3_profile_commerce_read_v1($pdo,$scope,false,static function(array $fresh)use($pdo,$productId,$cursor,$now):array{
        $owner=(int)$fresh['user']['id'];
        if(!vp3_profile_analytics_table_v1($pdo,'agent_commerce_products_v800'))throw new RuntimeException('Product storage is unavailable.',503);
        $product=vp3_profile_analytics_rows_v1($pdo,'SELECT id FROM agent_commerce_products_v800 WHERE id=? AND owner_user_id=?',[$productId,$owner],true)[0]??null;
        if(!$product)throw new RuntimeException('This product is unavailable.',404);
        $result=['coverage'=>'ready','reason'=>'complete','product_id'=>$productId,'publications'=>[],'has_more'=>false,'next_cursor'=>null];$proofs=[];
        if(!vp3_profile_analytics_table_v1($pdo,'agent_promotion_publications_v1')){$result['coverage']='unconfigured';$result['reason']='storage_unconfigured';}
        else{
            $query='SELECT publication_id FROM agent_promotion_publications_v1 WHERE owner_user_id=? AND agent_id=? AND conversation_id=? AND product_id=?';
            $params=[$owner,$fresh['agent_id'],$fresh['conversation_id'],$productId];
            if($cursor!==''){
                if(strlen($cursor)>400||!preg_match('/\A[A-Za-z0-9_-]+\z/D',$cursor))throw new RuntimeException('Choose a valid publication page.',422);
                $raw=base64_decode(strtr($cursor,'-_','+/'),true);$page=$raw!==false?vp3_profile_analytics_json_v1($raw):null;
                if(!$page||rtrim(strtr(base64_encode($raw),'+/','-_'),'=')!==$cursor
                    ||array_keys($page)!==['owner','agent','chat','product','before']||$page['owner']!==$owner
                    ||$page['agent']!==$fresh['agent_id']||$page['chat']!==$fresh['conversation_id']||$page['product']!==$productId
                    ||!is_string($page['before'])||!preg_match('/\A[a-f0-9]{32}\z/D',$page['before']))throw new RuntimeException('This publication page belongs to another selection.',422);
                $query.=' AND publication_id<?';$params[]=$page['before'];
            }
            $rows=vp3_profile_analytics_rows_v1($pdo,$query.' ORDER BY publication_id DESC LIMIT '.(VP3_PROFILE_COMMERCE_PUBLICATIONS_PAGE_V1+1),$params,true);
            $result['has_more']=count($rows)>VP3_PROFILE_COMMERCE_PUBLICATIONS_PAGE_V1;$rows=array_slice($rows,0,VP3_PROFILE_COMMERCE_PUBLICATIONS_PAGE_V1);
            foreach($rows as $row){
                try{$publication=vp3_profile_commerce_publication_v1($pdo,$fresh,$row['publication_id']);
                    $period=vp3_profile_commerce_period_v1($pdo,$fresh,['publication_id'=>$row['publication_id']],$now);
                    $result['publications'][]=$publication['safe']+['ref'=>$period['ref'],'current_publication'=>vp3_profile_commerce_publication_state_v1($publication),
                        'url'=>vp3_profile_commerce_followthrough_href_v1($fresh['agent_id'],$fresh['conversation_id'],$period['ref'])];
                    $proofs[$row['publication_id']]=$publication['proof'];
                }catch(RuntimeException $error){if($error instanceof PDOException||vp3_profile_analytics_retryable_v1($error)||!in_array($error->getCode(),[404,409,503],true))throw $error;
                    $result['coverage']='limited';$result['reason']='source_proof_incomplete';}
            }
            if($result['has_more'])$result['next_cursor']=rtrim(strtr(base64_encode(json_encode(['owner'=>$owner,'agent'=>$fresh['agent_id'],'chat'=>$fresh['conversation_id'],
                'product'=>$productId,'before'=>$rows[count($rows)-1]['publication_id']],JSON_THROW_ON_ERROR)),'+/','-_'),'=');
        }
        return [$result,static function(array $current)use($pdo,$productId,$owner,$proofs,$result):array{
            if(!vp3_profile_analytics_rows_v1($pdo,'SELECT id FROM agent_commerce_products_v800 WHERE id=? AND owner_user_id=?',[$productId,$owner],true))throw new RuntimeException('This product is no longer available.',404);
            $states=[];
            foreach($proofs as $id=>$proof){$latest=vp3_profile_commerce_publication_v1($pdo,$current,$id);
                if(!hash_equals($proof,$latest['proof']))throw new RuntimeException('The publication changed. Read it again.',409);
                $states[$id]=vp3_profile_commerce_publication_state_v1($latest);
            }
            $rows=$result['publications'];foreach($rows as &$row)$row['current_publication']=$states[$row['publication_id']];unset($row);
            return ['publications'=>$rows];
        }];
    });
}

function vp3_profile_commerce_exchange_item_v1(string $publicationId,array $proof,array $turn,array $visitor,array $answer): array
{
    $revision=vp3_profile_analytics_hash_v1([$publicationId,$proof,$turn['_at'],hash('sha256',(string)$visitor['message']),hash('sha256',(string)$answer['message']),
        vp3_profile_analytics_hash_v1(vp3_profile_analytics_json_v1($turn['accepted_json']))]);
    return ['public_conversation_id'=>$proof['public_conversation_id'],'turn_id'=>$proof['turn_id'],
        'visitor_message_id'=>$proof['visitor_message_id'],'answer_message_id'=>$proof['answer_message_id'],
        'visitor_at_utc_ms'=>$turn['_at'],'linked_at_utc_ms'=>$proof['at'],'question'=>vp3_profile_commerce_text_v1($visitor['message'],600),
        'answer_excerpt'=>vp3_profile_commerce_text_v1($answer['message'],240),'revision'=>$revision,
        'open_selection'=>['tab'=>'conversations','publicConversationId'=>$proof['public_conversation_id']]];
}

/** Bounded current proof for one displayed historical exchange. No live public gate. */
function vp3_profile_commerce_exchange_current_v1(PDO $pdo,int $owner,array $publication,int $conversationId,int $answerId): ?array
{
    $publicationId=$publication['publication_id'];
    $conversation=vp3_profile_analytics_rows_v1($pdo,'SELECT * FROM profile_agent_conversations WHERE id=? AND owner_user_id=?',[$conversationId,$owner],true)[0]??null;
    $answer=vp3_profile_analytics_rows_v1($pdo,"SELECT * FROM profile_agent_messages WHERE id=? AND conversation_id=? AND sender_type='agent'",[$answerId,$conversationId],true)[0]??null;
    if(!$conversation||!$answer)return null;
    $answeredAt=vp3_profile_analytics_utc_v1($answer['created_at']??null);
    if($answeredAt===null||$answeredAt<$publication['recorded_at_utc_ms'])return null;
    $context=vp3_profile_analytics_json_v1($answer['context_json']??null);
    if(($context['product_promotion_key']??null)!==$publicationId)return null;
    $turnId=$context['profile_handoff']['turn_id']??null;
    if(!is_int($turnId)||$turnId<1)return null;
    $turn=vp3_profile_analytics_rows_v1($pdo,'SELECT * FROM profile_agent_handoff_turns_pa3 WHERE id=? AND conversation_id=? AND owner_user_id=?',[$turnId,$conversationId,$owner],true)[0]??null;
    if(!$turn)return null;
    $bindings=vp3_profile_analytics_rows_v1($pdo,"SELECT context_json FROM profile_agent_messages WHERE conversation_id=? AND sender_type='system' ORDER BY id LIMIT 2",[$conversationId],true);
    $binding=count($bindings)===1?vp3_profile_analytics_json_v1($bindings[0]['context_json']):null;
    $aid=(int)$conversation['profile_agent_id'];$sid=(int)$conversation['profile_session_id'];$visitorId=(int)($conversation['visitor_user_id']??0);
    $agent=vp3_profile_analytics_rows_v1($pdo,'SELECT id,owner_user_id FROM user_agents WHERE id=? AND owner_user_id=?',[$aid,$owner],true)[0]??null;
    $session=vp3_profile_analytics_rows_v1($pdo,'SELECT id,owner_user_id,session_key FROM profile_visit_sessions WHERE id=? AND owner_user_id=?',[$sid,$owner],true)[0]??null;
    if(!$agent||!$session||!vp3_profile_agent_conversation_authority_valid_v1($binding,$owner)
        ||$binding['transport']!=='native'||(int)($binding['profile_agent_id']??0)!==$aid
        ||(int)($binding['profile_session_id']??0)!==$sid||$binding['visitor_user_id']!==$visitorId||$visitorId===$owner)return null;
    $visitor=vp3_profile_analytics_rows_v1($pdo,'SELECT * FROM profile_agent_messages WHERE id=? AND conversation_id=?',[(int)$turn['visitor_message_id'],$conversationId],true)[0]??null;
    if(!$visitor)return null;
    $history=['conversations'=>[$conversationId=>$conversation],'valid'=>[$conversationId=>$binding],
        'messages'=>[(int)$visitor['id']=>$visitor],'sessions'=>[$sid=>$session]];
    $stamp=vp3_profile_analytics_utc_v1($turn['created_at']??null);
    if($stamp===null||!vp3_profile_analytics_turn_valid_v1($turn,$history,$owner))return null;
    $turn['_at']=$stamp;
    $run=vp3_profile_analytics_rows_v1($pdo,"SELECT id,agent_id,source_key,source_kind,workflow_type FROM agent_workflow_runs WHERE id=? AND owner_user_id=? AND source_kind='profile_visitor_turn'",[(int)$turn['run_id'],$owner],true)[0]??null;
    $action=vp3_profile_analytics_rows_v1($pdo,"SELECT id,run_id,action_key,capability_key FROM agent_workflow_actions WHERE id=? AND owner_user_id=? AND capability_key='profile.visitor.reply'",[(int)$turn['action_id'],$owner],true)[0]??null;
    $key=hash('sha256',$owner.'|'.$turn['run_id'].'|'.$turn['action_id'].'|profile-turn:'.$turn['id'].':reply');
    $receipts=vp3_profile_analytics_rows_v1($pdo,'SELECT * FROM agent_workflow_receipts WHERE owner_user_id=? AND receipt_key=? ORDER BY id LIMIT 2',[$owner,$key],true);
    $proof=vp3_profile_analytics_agent_reply_proof_v1($owner,$answer,$turn,$run?[(int)$run['id']=>$run]:[],$action?[(int)$action['id']=>$action]:[],[$key=>$receipts]);
    return $proof?vp3_profile_commerce_exchange_item_v1($publicationId,$proof,$turn,$visitor,$answer):null;
}

function vp3_profile_commerce_catalog_v1(): array
{
    return [
        'recorded_cta_clicks'=>['clicks','Recorded CTA clicks','Distinct saved tokenized CTA link events for this publication; not people or page views.'],
        'conversation_attributed_clicks'=>['clicks','Clicks with verified conversation attribution','Profile Agent CTA clicks with an exact saved native public conversation binding.'],
        'promotion_linked_exchanges'=>['exchanges','Promotion-linked exchanges','Completed native Agent answers that saved this exact publication key, with the corresponding accepted visitor statement; not inferred purchase intent.'],
        'recorded_attributed_orders'=>['orders','Orders with verified promotion attribution','Orders whose exact saved click and frozen order/item attribution identify this publication.'],
        'verified_checkout_openings'=>['checkouts','Checkout links made available','Distinct committed native checkout attempts whose locally usable checkout link became available in this period; not proof that a buyer opened the provider or completed a sale.'],
        'verified_paid_orders'=>['commerce','Orders reaching verified payment completion','Promotion-attributed orders whose verified cumulative receipts first reached the frozen total in this period.'],
        'verified_refunded_orders'=>['commerce','Orders with verified refunds','Distinct promotion-attributed orders with a verified completed refund in this period.'],
    ];
}
function vp3_profile_commerce_group_v1(array $keys,string $reason='storage_unconfigured',string $coverage='unconfigured'): array
{
    return ['coverage'=>$coverage,'reason'=>$reason,'points'=>vp3_profile_analytics_empty_points_v1($keys,$reason),'evidence'=>[]];
}
function vp3_profile_commerce_error_v1(Throwable $error,array $keys): array
{
    if(vp3_profile_analytics_retryable_v1($error))throw $error;
    return vp3_profile_commerce_group_v1($keys,$error instanceof RuntimeException&&$error->getCode()===424?'storage_unconfigured':'storage_unavailable',
        $error instanceof RuntimeException&&$error->getCode()===424?'unconfigured':'unavailable');
}
function vp3_profile_commerce_counts_v1(array $keys): array
{
    return ['current'=>array_fill_keys($keys,0),'previous'=>array_fill_keys($keys,0)];
}

/** Raw click clocks remain useful even where a legacy row lacks conversation capture. */
function vp3_profile_commerce_clicks_v1(PDO $pdo,int $owner,array $publication,array $window): array
{
    $keys=['recorded_cta_clicks','conversation_attributed_clicks'];$out=vp3_profile_commerce_group_v1($keys);
    $out+=['rows'=>[],'breakdowns'=>['current'=>['profile'=>null,'profile_agent'=>null,'coverage'=>'unavailable','reason'=>'storage_unconfigured'],
        'previous'=>['profile'=>null,'profile_agent'=>null,'coverage'=>'unavailable','reason'=>'storage_unconfigured']]];
    if(!vp3_profile_analytics_table_v1($pdo,'agent_promotion_clicks_v1'))return $out;
    $rows=vp3_profile_analytics_rows_v1($pdo,'SELECT * FROM agent_promotion_clicks_v1 WHERE publication_id=? ORDER BY click_id',[$publication['publication_id']],true);
    $capture=profile_promotion_capture_click_columns_v1($pdo);$counts=vp3_profile_commerce_counts_v1($keys);
    $breakdowns=['current'=>['profile'=>0,'profile_agent'=>0],'previous'=>['profile'=>0,'profile_agent'=>0]];$gap=false;$associationGap=false;
    foreach($rows as $row){
        $id=$row['click_id'];$stamp=vp3_profile_analytics_utc_v1($row['created_at']??null);
        if(!is_string($id)||!preg_match('/\A[a-f0-9]{32}\z/D',$id)||!in_array($row['placement'],['profile','profile_agent'],true)||$stamp===null){$gap=true;continue;}
        $side=vp3_profile_analytics_window_key_v1($stamp,$window);
        if($stamp<$publication['recorded_at_utc_ms']){if($side!==null)$gap=true;continue;}
        $out['rows'][$id]=$row+['_at'=>$stamp];if($side===null)continue;
        $counts[$side]['recorded_cta_clicks']++;$breakdowns[$side][$row['placement']]++;
        $out['evidence'][]=['click',$id,$row['placement'],$stamp];
        if($row['placement']==='profile_agent'&&$capture){
            $proof=profile_promotion_public_click_evidence_v1($pdo,$owner,$publication['publication_id'],$id,true);
            if($proof['availability']==='ready'&&vp3_profile_commerce_id_v1($proof['public_conversation_id']??null)!==null){
                $counts[$side]['conversation_attributed_clicks']++;$out['evidence'][]=['click_conversation',$id,$proof['source_revision'],$proof['public_conversation_id']];
            }else $associationGap=true;
        }
    }
    foreach(['current','previous'] as $side){
        $out['points']['recorded_cta_clicks'][$side]=vp3_profile_analytics_point_v1($counts[$side]['recorded_cta_clicks'],$gap?'partial':'complete',$gap?'source_proof_incomplete':'complete');
        $out['points']['conversation_attributed_clicks'][$side]=$capture
            ?vp3_profile_analytics_point_v1($counts[$side]['conversation_attributed_clicks'],$gap||$associationGap?'partial':'complete',$gap?'source_proof_incomplete':($associationGap?'legacy_conversation_attribution':'complete'))
            :vp3_profile_analytics_point_v1(null,'unavailable','conversation_attribution_unavailable');
        $out['breakdowns'][$side]=$breakdowns[$side]+['coverage'=>$gap?'partial':'complete','reason'=>$gap?'source_proof_incomplete':'complete'];
    }
    $out['coverage']=$gap||$associationGap||!$capture?'limited':'ready';$out['reason']=$gap?'source_proof_incomplete':(!$capture?'conversation_attribution_unavailable':($associationGap?'legacy_conversation_attribution':'complete'));
    return $out;
}

/** Exact saved promotion associations; lexical product matches never become attribution. */
function vp3_profile_commerce_exchanges_v1(PDO $pdo,int $owner,array $publication,array $window,array $history): array
{
    $out=vp3_profile_commerce_group_v1(['promotion_linked_exchanges']);$out['view']=['coverage'=>'unconfigured','reason'=>'storage_unconfigured','total'=>null,'observed_total'=>null,'items'=>[],'has_more'=>false];
    if(!vp3_profile_analytics_tables_v1($pdo,['profile_agent_handoff_turns_pa3','agent_workflow_runs','agent_workflow_actions','agent_workflow_receipts']))return $out;
    $turns=vp3_profile_analytics_rows_v1($pdo,'SELECT * FROM profile_agent_handoff_turns_pa3 WHERE owner_user_id=? ORDER BY id',[$owner],true);
    $runs=vp3_profile_analytics_rows_v1($pdo,"SELECT id,agent_id,source_key,source_kind,workflow_type FROM agent_workflow_runs WHERE owner_user_id=? AND source_kind='profile_visitor_turn' ORDER BY id",[$owner],true);
    $actions=vp3_profile_analytics_rows_v1($pdo,"SELECT id,run_id,action_key,capability_key FROM agent_workflow_actions WHERE owner_user_id=? AND capability_key='profile.visitor.reply' ORDER BY id",[$owner],true);
    $receipts=vp3_profile_analytics_rows_v1($pdo,'SELECT * FROM agent_workflow_receipts WHERE owner_user_id=? ORDER BY id',[$owner],true);
    $turnMap=[];$runMap=[];$actionMap=[];$receiptMap=[];
    foreach($turns as $row){$at=vp3_profile_analytics_utc_v1($row['created_at']??null);if($at!==null&&vp3_profile_analytics_turn_valid_v1($row,$history,$owner))$turnMap[(int)$row['id']]=$row+['_at'=>$at];}
    foreach($runs as $row)$runMap[(int)$row['id']]=$row;foreach($actions as $row)$actionMap[(int)$row['id']]=$row;
    foreach($receipts as $row)$receiptMap[$row['receipt_key']][]=$row;
    $count=['current'=>0,'previous'=>0];$gap=false;$items=[];
    foreach($history['messages'] as $message){
        if($message['sender_type']!=='agent')continue;
        $context=vp3_profile_analytics_json_v1($message['context_json']??null);
        if(($context['product_promotion_key']??null)!==$publication['publication_id'])continue;
        $stamp=vp3_profile_analytics_utc_v1($message['created_at']??null);$side=vp3_profile_analytics_window_key_v1($stamp,$window);
        if($stamp!==null&&$side===null)continue;
        $turn=$turnMap[(int)($context['profile_handoff']['turn_id']??0)]??null;
        $proof=vp3_profile_analytics_agent_reply_proof_v1($owner,$message,$turn,$runMap,$actionMap,$receiptMap);
        if(!$proof||$side===null||$proof['at']<$publication['recorded_at_utc_ms']){$gap=true;continue;}
        $visitor=$history['messages'][$proof['visitor_message_id']];$count[$side]++;
        $item=vp3_profile_commerce_exchange_item_v1($publication['publication_id'],$proof,$turn,$visitor,$message);
        $out['evidence'][]=['exchange',$proof['public_conversation_id'],$proof['turn_id'],$proof['visitor_message_id'],$proof['answer_message_id'],$item['revision']];
        if($side==='current')$items[]=$item;
    }
    foreach(['current','previous'] as $side)$out['points']['promotion_linked_exchanges'][$side]=vp3_profile_analytics_point_v1($count[$side],$gap?'partial':'complete',$gap?'source_proof_incomplete':'complete');
    usort($items,static fn(array $a,array $b):int=>[$b['linked_at_utc_ms'],$b['answer_message_id']]<=>[$a['linked_at_utc_ms'],$a['answer_message_id']]);
    $out['coverage']=$gap?'limited':'ready';$out['reason']=$gap?'source_proof_incomplete':'complete';
    $out['view']=['coverage'=>$out['coverage'],'reason'=>$out['reason'],'total'=>$gap?null:$count['current'],'observed_total'=>$gap?$count['current']:null,
        'items'=>array_slice($items,0,VP3_PROFILE_COMMERCE_EXCHANGES_PAGE_V1),'has_more'=>count($items)>VP3_PROFILE_COMMERCE_EXCHANGES_PAGE_V1];
    return $out;
}

/** Discover both receipt bindings and frozen metadata so a missing binding is not a zero. */
function vp3_profile_commerce_orders_v1(PDO $pdo,int $owner,array $publication,array $window,array $clicks,array $history): array
{
    $out=vp3_profile_commerce_group_v1(['recorded_attributed_orders']);$out+=['order_ids'=>[],'orders'=>[],'gap'=>false];
    if(($clicks['coverage']??null)==='unavailable')return array_replace($out,vp3_profile_commerce_group_v1(['recorded_attributed_orders'],'storage_unavailable','unavailable'));
    if(!isset($clicks['rows'])||in_array($clicks['coverage'],['unavailable','unconfigured'],true)
        ||!vp3_profile_analytics_tables_v1($pdo,['agent_promotion_orders_v1','agent_commerce_orders_v800','agent_commerce_order_items_v800']))return $out;
    $links=vp3_profile_analytics_rows_v1($pdo,'SELECT b.order_id,b.click_id FROM agent_promotion_orders_v1 b JOIN agent_promotion_clicks_v1 c ON c.click_id=b.click_id WHERE c.publication_id=? ORDER BY b.order_id',[$publication['publication_id']],true);
    $orders=vp3_profile_analytics_rows_v1($pdo,'SELECT * FROM agent_commerce_orders_v800 WHERE owner_user_id=? ORDER BY id',[$owner],true);
    $items=vp3_profile_analytics_rows_v1($pdo,'SELECT i.* FROM agent_commerce_order_items_v800 i JOIN agent_commerce_orders_v800 o ON o.id=i.order_id WHERE o.owner_user_id=? ORDER BY i.id',[$owner],true);
    $products=vp3_profile_analytics_rows_v1($pdo,'SELECT id,owner_user_id FROM agent_commerce_products_v800 WHERE owner_user_id=? ORDER BY id',[$owner],true);
    $orderMap=[];$itemMap=[];$productMap=[];$linkMap=[];$candidates=[];
    foreach($products as $row)$productMap[(int)$row['id']]=$row;
    foreach($links as $row){$linkMap[(int)$row['order_id']][]=$row['click_id'];$candidates[(int)$row['order_id']]=true;}
    foreach($items as $row)$itemMap[(int)$row['order_id']][]=$row;
    foreach($orders as $row){$oid=(int)$row['id'];$orderMap[$oid]=$row;$meta=vp3_profile_analytics_json_v1($row['metadata_json']??null);
        if(is_string($meta['product_promotion_click_v1']??null)&&isset($clicks['rows'][$meta['product_promotion_click_v1']]))$candidates[$oid]=true;}
    ksort($candidates,SORT_NUMERIC);$counts=['current'=>0,'previous'=>0];$gap=false;
    foreach(array_keys($candidates) as $oid){
        $order=$orderMap[$oid]??null;$meta=$order?vp3_profile_analytics_json_v1($order['metadata_json']??null):null;
        $linked=$linkMap[$oid]??[];$click=count($linked)===1?($clicks['rows'][$linked[0]]??null):null;
        $line=$itemMap[$oid]??[];$item=count($line)===1?$line[0]:null;$itemMeta=$item?vp3_profile_analytics_json_v1($item['metadata_json']??null):null;
        $stamp=vp3_profile_analytics_utc_v1($meta['product_promotion_order_utc_v1']??null);
        $valid=$order&&$click&&$item&&$stamp!==null&&(int)$item['product_id']===$publication['product_id']
            &&($meta['product_promotion_click_v1']??null)===$click['click_id']&&($itemMeta['product_promotion_click_v1']??null)===$click['click_id']
            &&($itemMeta['product_promotion_order_utc_v1']??null)===($meta['product_promotion_order_utc_v1']??null)
            &&$stamp>=$click['_at']&&$stamp<=$click['_at']+86400000
            &&vp3_profile_analytics_order_v1($pdo,$order,$line,$productMap,$history);
        if(!$valid){$gap=true;continue;}
        // A future order cannot contribute an earlier payment or opening.
        if($stamp>=$window['to_utc_ms'])continue;
        $out['order_ids'][]=$oid;$out['orders'][$oid]=$order+['_at'=>$stamp];
        $side=vp3_profile_analytics_window_key_v1($stamp,$window);
        if($side!==null){$counts[$side]++;$out['evidence'][]=['order',$oid,$click['click_id'],$stamp,
            vp3_profile_analytics_hash_v1([$meta,$itemMeta,vp3_profile_analytics_json_v1($order['terms_snapshot_json'])])];}
    }
    foreach(['current','previous'] as $side)$out['points']['recorded_attributed_orders'][$side]=vp3_profile_analytics_point_v1($counts[$side],$gap?'partial':'complete',$gap?'source_proof_incomplete':'complete');
    $out['gap']=$gap;$out['coverage']=$gap?'limited':'ready';$out['reason']=$gap?'source_proof_incomplete':'complete';return $out;
}

function vp3_profile_commerce_checkouts_v1(PDO $pdo,int $owner,array $window,array $orders): array
{
    $out=vp3_profile_commerce_group_v1(['verified_checkout_openings']);
    if(($orders['coverage']??null)==='unavailable')return vp3_profile_commerce_group_v1(['verified_checkout_openings'],'storage_unavailable','unavailable');
    if(!isset($orders['orders'])||in_array($orders['coverage'],['unconfigured','unavailable'],true)
        ||!vp3_profile_analytics_table_v1($pdo,'agent_commerce_checkout_attempts_v800'))return $out;
    if(!profile_promotion_capture_checkout_clock_v1($pdo))return vp3_profile_commerce_group_v1(['verified_checkout_openings'],'checkout_clock_unconfigured');
    $rows=vp3_profile_analytics_rows_v1($pdo,'SELECT a.* FROM agent_commerce_checkout_attempts_v800 a JOIN agent_commerce_orders_v800 o ON o.id=a.order_id WHERE o.owner_user_id=? ORDER BY a.id',[$owner],true);
    $counts=['current'=>0,'previous'=>0];$gap=($orders['gap']??false)===true;$clockGap=false;
    foreach($rows as $attempt){
        $order=$orders['orders'][(int)$attempt['order_id']]??null;if(!$order)continue;
        $clock=$attempt['opened_at_utc_ms']??null;$stamp=vp3_profile_analytics_epoch_v1($clock);
        $external=(string)($attempt['external_session_id']??'');
        $mayHaveOpened=$external!==''&&!str_starts_with($external,'pending:');
        if($clock===null){if($mayHaveOpened){$gap=true;$clockGap=true;}continue;}
        if($stamp!==null&&$stamp>=$window['to_utc_ms'])continue;
        $amount=vp3_profile_analytics_uint_v1($attempt['amount_cents']??null);$currency=strtoupper((string)$order['currency']);
        $connection=vp3_profile_analytics_rows_v1($pdo,'SELECT id,owner_user_id,provider,external_account_id FROM agent_commerce_provider_connections_v800 WHERE id=?',[(int)$order['connection_id']],true)[0]??null;
        $merchant=(int)($order['workspace_owner_user_id']??0)>0?(int)$order['workspace_owner_user_id']:$owner;
        $valid=$stamp!==null&&$stamp>=$order['_at']&&$amount!==null&&$amount!=='0'&&$connection
            &&(int)$connection['owner_user_id']===$merchant&&$connection['provider']===$order['provider_snapshot']&&$connection['external_account_id']===$order['external_account_snapshot']
            &&(int)$attempt['connection_id']===(int)$order['connection_id']&&$attempt['provider']===$order['provider_snapshot']
            &&strtoupper((string)$attempt['currency'])===$currency&&in_array($attempt['status'],['open','completed','expired','cancelled'],true)
            &&($attempt['last_error_class']??'')!=='checkout_terms_changed'&&vp3_profile_analytics_attempt_v1($attempt,$order,$amount,$currency);
        if(!$valid){$gap=true;continue;}
        $side=vp3_profile_analytics_window_key_v1($stamp,$window);if($side!==null){$counts[$side]++;$out['evidence'][]=['checkout',(int)$attempt['id'],(int)$order['id'],$stamp,
            vp3_profile_analytics_hash_v1(vp3_profile_analytics_json_v1($attempt['creation_request_json']))];}
    }
    $reason=$clockGap?'checkout_clock_missing':($gap?'source_proof_incomplete':'complete');
    foreach(['current','previous'] as $side)$out['points']['verified_checkout_openings'][$side]=vp3_profile_analytics_point_v1($counts[$side],$gap?'partial':'complete',$reason);
    $out['coverage']=$gap?'limited':'ready';$out['reason']=$reason;return $out;
}

/** Downgrade only the selected observed subset when attribution itself has gaps. */
function vp3_profile_commerce_partial_money_v1(array $money): array
{
    foreach($money['points'] as &$sides)foreach($sides as &$point)if($point['coverage']==='complete')
        $point=vp3_profile_analytics_point_v1($point['value'],'partial','source_proof_incomplete');
    unset($point,$sides);
    foreach($money['money'] as &$row){foreach(['current','previous'] as $side)if($row[$side]['coverage']==='complete')
        $row[$side]=vp3_profile_analytics_money_point_v1($row[$side]['value_minor'],'partial');$row['comparison']=['delta_minor'=>null];}
    unset($row);$money['coverage']='limited';$money['reason']='source_proof_incomplete';return $money;
}

function vp3_profile_commerce_actions_v1(PDO $pdo,array $scope,array $publication): array
{
    $owner=(int)$scope['user']['id'];$safe=$publication['safe'];$variant=$publication['promotion'];
    $current=['availability'=>'unconfigured','reason'=>'storage_unconfigured','revision'=>null,'url'=>null];
    if(vp3_profile_analytics_table_v1($pdo,'agent_product_launches_v1')){
        $row=vp3_profile_analytics_rows_v1($pdo,'SELECT * FROM agent_product_launches_v1 WHERE product_id=? AND owner_user_id=?',[$safe['product_id'],$owner],true)[0]??null;
        $current=['availability'=>'unavailable','reason'=>'source_unavailable','revision'=>null,'url'=>null];
        if($row&&(int)$row['agent_id']===$scope['agent_id']&&(int)$row['conversation_id']===$scope['conversation_id']){
            $path='/chat.php?agent='.($scope['agent_id']>0?$scope['agent_id']:'system').'&conversation_id='.$scope['conversation_id'].'&marketing_plan='.$safe['product_id'];
            $current=['availability'=>'ready','reason'=>'complete','revision'=>vp3_profile_analytics_hash_v1([$owner,$scope['agent_id'],$scope['conversation_id'],$safe['product_id'],$row['draft_key'],$row['creative_id'],$row['last_stage']]),
                'url'=>function_exists('url')?url($path):$path];
        }
    }
    $creative=['availability'=>'unavailable','reason'=>'source_unavailable','revision'=>null,'marker'=>null];
    $assetId=vp3_profile_commerce_id_v1($variant['asset_id']??null);
    $asset=$assetId!==null?vp3_profile_analytics_rows_v1($pdo,'SELECT id,user_id,source,media_type,mime_type,metadata_json FROM user_media_assets WHERE id=?',[$assetId],true)[0]??null:null;
    $meta=$asset?vp3_profile_analytics_json_v1($asset['metadata_json']??null):null;
    if($asset&&(int)$asset['user_id']===$owner&&$asset['media_type']==='photo'&&$asset['mime_type']==='image/png'
        &&$asset['source']==='promotion_design'&&($meta['creative_id']??null)===$safe['promotion_id']
        &&preg_match('/\A[a-f0-9]{64}\z/D',(string)($variant['asset_sha256']??''))){
        $marker=['owner_user_id'=>$owner,'agent_id'=>$scope['agent_id'],'conversation_id'=>$scope['conversation_id'],
            'product_id'=>$safe['product_id'],'source_id'=>(string)$safe['source_asset_id'],'draft_id'=>$safe['draft_key'],
            'creative_id'=>$safe['promotion_id'],'stage'=>'promotion_outcomes'];
        $creative=['availability'=>'ready','reason'=>'complete','revision'=>vp3_profile_analytics_hash_v1([$publication['proof'],$assetId,$variant['asset_sha256']]),'marker'=>$marker];
    }
    return ['current_plan'=>$current,'exact_creative'=>$creative,'current_publication'=>vp3_profile_commerce_publication_state_v1($publication)];
}

function vp3_profile_commerce_actions_safe_v1(PDO $pdo,array $scope,array $publication): array
{
    try{return vp3_profile_commerce_actions_v1($pdo,$scope,$publication);}
    catch(Throwable $error){
        if(vp3_profile_analytics_retryable_v1($error))throw $error;
        return ['current_plan'=>['availability'=>'unavailable','reason'=>'source_unavailable','revision'=>null,'url'=>null],
            'exact_creative'=>['availability'=>'unavailable','reason'=>'source_unavailable','revision'=>null,'marker'=>null],
            'current_publication'=>vp3_profile_commerce_publication_state_v1($publication)];
    }
}

/** Read-only first layer: no publication, visit hydration, wake, ACK, inference or provider. */
function vp3_profile_commerce_followthrough_v1(PDO $pdo,array $scope,array $selection,?DateTimeImmutable $now=null): array
{
    $now??=new DateTimeImmutable('now',new DateTimeZone('UTC'));
    return vp3_profile_commerce_read_v1($pdo,$scope,true,static function(array $fresh)use($pdo,$selection,$now):array{
        $owner=(int)$fresh['user']['id'];$period=vp3_profile_commerce_period_v1($pdo,$fresh,$selection,$now);$window=$period['window'];
        $publication=vp3_profile_commerce_publication_v1($pdo,$fresh,$period['publication_id']);$safe=$publication['safe'];
        $history=null;$historyError=null;
        try{$history=vp3_profile_analytics_native_history_v1($pdo,$owner);}catch(Throwable $error){if(vp3_profile_analytics_retryable_v1($error))throw $error;$historyError=$error;}
        try{$clicks=vp3_profile_commerce_clicks_v1($pdo,$owner,$safe,$window);}catch(Throwable $error){$clicks=vp3_profile_commerce_error_v1($error,['recorded_cta_clicks','conversation_attributed_clicks']);}
        try{if($historyError)throw $historyError;$exchanges=vp3_profile_commerce_exchanges_v1($pdo,$owner,$safe,$window,$history);}
        catch(Throwable $error){$exchanges=vp3_profile_commerce_error_v1($error,['promotion_linked_exchanges']);}
        try{$orders=vp3_profile_commerce_orders_v1($pdo,$owner,$safe,$window,$clicks,$history??[]);}catch(Throwable $error){$orders=vp3_profile_commerce_error_v1($error,['recorded_attributed_orders']);}
        try{$checkouts=vp3_profile_commerce_checkouts_v1($pdo,$owner,$window,$orders);}catch(Throwable $error){$checkouts=vp3_profile_commerce_error_v1($error,['verified_checkout_openings']);}
        try{
            if(($orders['coverage']??null)==='unavailable')throw new RuntimeException('Publication order proof is unavailable.',503);
            if(!isset($orders['order_ids'])||in_array($orders['coverage'],['unconfigured','unavailable'],true))throw new RuntimeException('Publication order proof is unavailable.',424);
            $orderNotBefore=[];foreach($orders['orders'] as $id=>$order)$orderNotBefore[$id]=$order['_at'];
            $commerce=vp3_profile_analytics_commerce_v1($pdo,$owner,$window,$history??[],$orders['order_ids'],$orderNotBefore);
            if(($orders['gap']??false)&&in_array($commerce['coverage'],['ready','limited'],true))$commerce=vp3_profile_commerce_partial_money_v1($commerce);
        }catch(Throwable $error){$commerce=vp3_profile_commerce_error_v1($error,['verified_paid_orders','verified_refunded_orders']);$commerce['money']=[];}
        $sources=compact('clicks','exchanges','orders','checkouts','commerce');$metrics=[];$groups=[];$evidence=[];
        foreach(vp3_profile_commerce_catalog_v1() as $key=>[$group,$label,$definition]){
            $points=$sources[$group]['points'][$key];$metrics[]=vp3_profile_analytics_metric_v1($key,$group,$label,'count',$definition,$points['current'],$points['previous']);
        }
        foreach($sources as $key=>$source){$groups[$key]=['coverage'=>$source['coverage'],'reason'=>$source['reason']];$evidence[$key]=$source['evidence'];}
        $states=array_column($groups,'coverage');$usable=count(array_filter($states,static fn(string $state):bool=>in_array($state,['ready','limited'],true)));
        $coverage=count(array_filter($states,static fn(string $state):bool=>$state==='ready'))===count($states)?'ready':($usable?'limited':(in_array('unavailable',$states,true)?'unavailable':'unconfigured'));
        $emptyBreakdown=['profile'=>null,'profile_agent'=>null,'coverage'=>'unavailable','reason'=>$clicks['reason']];
        $breakdowns=$clicks['breakdowns']??['current'=>$emptyBreakdown,'previous'=>$emptyBreakdown];
        $exchangeView=$exchanges['view']??['coverage'=>$exchanges['coverage'],'reason'=>$exchanges['reason'],'total'=>null,'observed_total'=>null,'items'=>[],'has_more'=>false];
        $money=$commerce['money']??[];
        $revision=vp3_profile_analytics_hash_v1([VP3_PROFILE_COMMERCE_FOLLOWTHROUGH_V1,$owner,$fresh['agent_id'],$fresh['conversation_id'],
            $period['ref'],$publication['proof'],$coverage,$metrics,$groups,$breakdowns,$money,$evidence]);
        $actions=vp3_profile_commerce_actions_safe_v1($pdo,$fresh,$publication);
        $result=['contract'=>VP3_PROFILE_COMMERCE_FOLLOWTHROUGH_V1,'ref'=>$period['ref'],'revision'=>$revision,'coverage'=>$coverage,
            'observed_at_utc_ms'=>$now->getTimestamp()*1000+(int)$now->format('v'),'window'=>$window,'period_options'=>$period['period_options'],
            'publication'=>$safe,'metrics'=>$metrics,'groups'=>$groups,'click_breakdowns'=>$breakdowns,'money'=>$money,'exchanges'=>$exchangeView]+$actions;
        return [$result,static function(array $current)use($pdo,$period,$publication,$exchangeView):array{
            $latest=vp3_profile_commerce_publication_v1($pdo,$current,$period['publication_id']);
            if(!hash_equals($publication['proof'],$latest['proof']))
                throw new RuntimeException('The publication source changed. Read it again.',409);
            foreach($exchangeView['items'] as $item){
                $verified=vp3_profile_commerce_exchange_current_v1($pdo,(int)$current['user']['id'],$latest['safe'],$item['public_conversation_id'],$item['answer_message_id']);
                if(!$verified||!hash_equals($item['revision'],$verified['revision']))
                    throw new RuntimeException('The saved promotion conversation changed. Read it again.',409);
            }
            return vp3_profile_commerce_actions_safe_v1($pdo,$current,$latest);
        }];
    });
}

/** Exact saved replies can link an existing native finding to its original plan. */
function vp3_profile_commerce_conversation_associations_v1(PDO $pdo,array $scope,int $conversationId,DateTimeImmutable $now,int $limit=4): array
{
    if($conversationId<1||$limit<1||$limit>4)throw new InvalidArgumentException('Invalid promotion conversation selection.');
    $empty=['coverage'=>'unconfigured','items'=>[]];
    $tables=['agent_promotion_publications_v1','profile_agent_conversations','profile_agent_messages','profile_visit_sessions','user_agents',
        'profile_agent_handoff_turns_pa3','agent_workflow_runs','agent_workflow_actions','agent_workflow_receipts'];
    if(!vp3_profile_analytics_tables_v1($pdo,$tables))return $empty;
    require_once __DIR__.'/agent-coordination-product-marketing-v1.php';
    return vp3_profile_commerce_read_v1($pdo,$scope,true,static function(array $fresh)use($pdo,$conversationId,$now,$limit):array{
        $owner=(int)$fresh['user']['id'];
        if(!vp3_profile_analytics_rows_v1($pdo,'SELECT id FROM profile_agent_conversations WHERE id=? AND owner_user_id=?',[$conversationId,$owner],true))
            throw new RuntimeException('This Profile conversation is no longer available.',404);
        $candidates=vp3_profile_analytics_rows_v1($pdo,"SELECT id,context_json FROM profile_agent_messages WHERE conversation_id=? AND sender_type='agent'
            AND CASE WHEN JSON_VALID(context_json) THEN JSON_EXTRACT(context_json,'$.product_promotion_key') ELSE NULL END IS NOT NULL
            ORDER BY id DESC LIMIT 25",[$conversationId],true);
        $coverage=count($candidates)>24?'limited':'ready';$items=[];$proofs=[];$seen=[];
        foreach(array_slice($candidates,0,24) as $candidate){
            $context=vp3_profile_analytics_json_v1($candidate['context_json']??null);$id=$context['product_promotion_key']??null;
            if(!is_string($id)||!preg_match('/\A[a-f0-9]{32}\z/D',$id)){$coverage='limited';continue;}
            if(isset($seen[$id]))continue;
            // A public conversation ID is never joined to a private Chat ID.
            $own=vp3_profile_analytics_rows_v1($pdo,'SELECT publication_id FROM agent_promotion_publications_v1 WHERE publication_id=? AND owner_user_id=? AND agent_id=? AND conversation_id=?',
                [$id,$owner,$fresh['agent_id'],$fresh['conversation_id']],true);
            if(!$own)continue;
            if(count($items)>=$limit){$coverage='limited';break;}
            try{
                $publication=vp3_profile_commerce_publication_v1($pdo,$fresh,$id);
                $exchange=vp3_profile_commerce_exchange_current_v1($pdo,$owner,$publication['safe'],$conversationId,(int)$candidate['id']);
                if(!$exchange){$coverage='limited';continue;}
                if($exchange['linked_at_utc_ms']>$now->getTimestamp()*1000+(int)$now->format('v')){$coverage='limited';continue;}
                $linked=(new DateTimeImmutable('@'.intdiv($exchange['linked_at_utc_ms'],1000)))->setTimezone(new DateTimeZone('UTC'));
                $cutoff=$linked->setTime(0,0)->modify('+1 day')->getTimestamp();
                $ref=$cutoff<=$now->getTimestamp()
                    ?'marketing:ft:v1:'.$id.':1:'.$cutoff.':u:VVRD'
                    :'marketing:product-plan:'.$publication['safe']['product_id'];
                $snapshot=vp3_coordination_product_marketing_sources_v1($pdo,$fresh,[substr($ref,10)],$now);
                $source=$snapshot['items'][$ref]??null;
                if(!$source){$coverage='limited';continue;}
                if(($snapshot['coverage']??null)!=='ready')$coverage='limited';
                $items[]=['ref'=>$ref,'revision'=>$source['revision'],'title'=>$source['title'],'url'=>$source['url'],
                    'publication_id'=>$id,'product_id'=>$publication['safe']['product_id'],'public_conversation_id'=>$conversationId,
                    'turn_id'=>$exchange['turn_id'],'visitor_message_id'=>$exchange['visitor_message_id'],'answer_message_id'=>$exchange['answer_message_id'],
                    'question'=>$exchange['question'],'linked_at_utc_ms'=>$exchange['linked_at_utc_ms'],'relation'=>'saved_promotion_reply'];
                $proofs[]=['publication_id'=>$id,'publication_proof'=>$publication['proof'],'exchange'=>$exchange,'ref'=>$ref,'source_revision'=>$source['revision']];
                $seen[$id]=true;
            }catch(PDOException $error){throw $error;}
            catch(RuntimeException|DomainException $error){
                if($error->getCode()===403)throw $error;
                if(!in_array($error->getCode(),[404,409,422,503],true))throw $error;
                $coverage='limited';
            }
        }
        return [['coverage'=>$coverage,'items'=>$items],static function(array $current)use($pdo,$conversationId,$proofs,$now):void{
            $owner=(int)$current['user']['id'];
            if(!vp3_profile_analytics_rows_v1($pdo,'SELECT id FROM profile_agent_conversations WHERE id=? AND owner_user_id=?',[$conversationId,$owner],true))
                throw new RuntimeException('This Profile conversation is no longer available.',404);
            foreach($proofs as $saved){
                $publication=vp3_profile_commerce_publication_v1($pdo,$current,$saved['publication_id']);
                $exchange=vp3_profile_commerce_exchange_current_v1($pdo,$owner,$publication['safe'],$conversationId,$saved['exchange']['answer_message_id']);
                if(!hash_equals($publication['proof'],$saved['publication_proof'])||!$exchange||!hash_equals($exchange['revision'],$saved['exchange']['revision']))
                    throw new RuntimeException('The saved promotion association changed. Read it again.',409);
                if(str_starts_with($saved['ref'],'marketing:product-plan:')){
                    $snapshot=vp3_coordination_product_marketing_sources_v1($pdo,$current,[substr($saved['ref'],10)],$now);
                    if(!isset($snapshot['items'][$saved['ref']])||!hash_equals($saved['source_revision'],$snapshot['items'][$saved['ref']]['revision']))
                        throw new RuntimeException('The related current plan changed. Read it again.',409);
                }
            }
        }];
    });
}
