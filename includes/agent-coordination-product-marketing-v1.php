<?php
declare(strict_types=1);

require_once __DIR__.'/profile-product-marketing-outcome-v1.php';

function vp3_product_marketing_source_v1(array $data,string $kind): array
{
    $plan=$kind==='plan';$title=($plan?'Marketing plan: ':'Promotion results: ').$data['product_title'];
    if($plan){$summary=$data['stage_label'].'. This is the existing plan in its original private Chat.';$url=$data['chat_url'];$next='Open the saved plan and review its next supported step.';}
    else{
        $w=$data['window'];$summary=($data['coverage']!=='ready'?'Partial coverage. ':'').'Completed local period '.$w['from_local'].' to '.$w['to_local'].' (end excluded), '.$w['timezone'].'. ';
        $exchange=$data['associations']['items'][0]??null;
        if(is_array($exchange)&&is_string($exchange['excerpt']??null)&&$exchange['excerpt']!=='')
            $summary.='Visitor message: “'.vp3_product_marketing_text_v1($exchange['excerpt'],160).'”; saved reply showed this promotion. ';
        foreach($data['metrics'] as $metric){$p=$metric['current'];$value=$p['coverage']==='complete'?$p['value']:$p['observed_value'];
            if($value!==null)$summary.=$metric['label'].': '.$value.($p['coverage']==='partial'?' observed (partial)':'').'. ';}
        foreach($data['money'] as $row){$p=$row['current'];$minor=$p['coverage']==='complete'?$p['value_minor']:$p['observed_value_minor'];
            if(is_string($minor)){$digits=str_pad($minor,3,'0',STR_PAD_LEFT);$summary.=$row['currency'].' '.substr($digits,0,-2).'.'.substr($digits,-2).' '.str_replace('_',' ',$row['key']).($p['coverage']==='partial'?' observed (partial)':'').'. ';}}
        if($data['coverage']!=='ready')$summary.='Some measures are partial or unavailable.';
        $url=$data['actions'][0]['href'];$next='Review this exact publication and its fixed reporting period.';
    }
    return ['ref'=>$data['ref'],'revision'=>$data['revision'],'specialist'=>'marketing','title'=>vp3_product_marketing_text_v1($title,190),'summary'=>vp3_product_marketing_text_v1($summary,1200),
        'next_step'=>$next,'question'=>'Would you like to review this saved product marketing work?','url'=>$url,
        'status'=>'open','priority'=>$plan&&!empty($data['needs_attention'])?'normal':'low','trigger'=>$plan?'product_plan':'product_outcome',
        'presentation_owner'=>$data['presentation_owner'],'product_marketing_presentation_owned'=>true,'legacy_delivered'=>$data['legacy_delivered'],
        'private_summary_only'=>true,'public_evidence_untrusted'=>true,'product_marketing_source_kind'=>$kind,
        'product_id'=>$data['product_id'],'publication_id'=>$data['publication_id']??null,'period_ref'=>$data['period_ref']??null];
}
/** Native plan helpers predate current locking reads. Refuse an older RR view
 * when any of their relevant source rows differs from current locked storage. */
function vp3_product_marketing_plan_inputs_v1(PDO $pdo,int $owner,array $row): array
{
    $same=static function(string $sql,array $params)use($pdo):array{
        $current=vp3_profile_analytics_rows_v1($pdo,$sql,$params,true);
        if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'&&$current!==vp3_profile_analytics_rows_v1($pdo,$sql,$params))
            throw new RuntimeException('The caller snapshot predates the current marketing source.',409);
        return $current;
    };
    $products=$same('SELECT * FROM agent_commerce_products_v800 WHERE owner_user_id=? ORDER BY id',[$owner]);
    $same('SELECT b.* FROM agent_commerce_product_bindings_v800 b JOIN agent_commerce_products_v800 p ON p.id=b.product_id WHERE p.owner_user_id=? ORDER BY b.product_id',[$owner]);
    $drafts=$same('SELECT * FROM agent_commerce_marketing_drafts_v1 WHERE owner_user_id=? AND product_id=? ORDER BY request_key',[$owner,(int)$row['product_id']]);
    $creatives=$same('SELECT * FROM agent_commerce_promotions_v1 WHERE owner_user_id=? ORDER BY id',[$owner]);
    $same('SELECT * FROM user_media_assets WHERE user_id=? ORDER BY id',[$owner]);
    $same('SELECT * FROM user_profiles WHERE user_id=?',[$owner]);
    $product=null;$creative=null;$draft=null;
    foreach($products as $value)if((int)$value['id']===(int)$row['product_id'])$product=$value;
    foreach($creatives as $value)if($value['id']===$row['creative_id'])$creative=$value;
    foreach($drafts as $value)if($value['request_key']===$row['draft_key'])$draft=$value['draft_json'];
    if($creative&&is_string($creative['generation_id']??null)&&$creative['generation_id']!==''){
        $g=$same('SELECT * FROM agent_image_generations_v1 WHERE owner_user_id=? AND generation_id=?',[$owner,$creative['generation_id']])[0]??null;
        if($g)foreach(['agent_workflow_runs','agent_workflow_actions','agent_workflow_receipts'] as $table)
            $same('SELECT * FROM '.$table.' WHERE owner_user_id=? AND '.($table==='agent_workflow_runs'?'id':'run_id').'=? ORDER BY id',[$owner,(int)$g['run_id']]);
    }
    return [array_intersect_key($product??[],array_flip(['id','owner_user_id','title','description','price_cents','deposit_cents','currency','payment_mode','provider_mode','fixed_connection_id','metadata_json','is_active'])),
        $draft,array_intersect_key($creative??[],array_flip(['id','source_sha256','settings_json','asset_sha256','status','public_key','generation_id','publication_revision']))];
}

function vp3_product_marketing_plan_v1(PDO $pdo,array $scope,int $id,array &$fences): ?array
{
    $owner=(int)$scope['user']['id'];
    $row=vp3_profile_analytics_rows_v1($pdo,initiative_marketing_plans_query_v1().' AND l.product_id=?',[$owner,$scope['agent_id'],$id],true)[0]??null;
    if(!$row)return null;
    $original=vp3_product_marketing_original_scope_v1($scope,(int)$row['agent_id'],(int)$row['conversation_id']);
    vp3_private_analytics_authority_current_v1($pdo,$original,false);
    $inputs=vp3_product_marketing_plan_inputs_v1($pdo,$owner,$row);
    $view=initiative_marketing_plan_view_v1($pdo,$owner,$row);$ref=vp3_product_marketing_plan_ref_v1($id);
    $revision=vp3_profile_analytics_hash_v1([$ref,$owner,$row,$view,$inputs]);
    $fences[]=static function()use($pdo,$original,$row,$owner,$view,$inputs):void{
        vp3_private_analytics_authority_current_v1($pdo,$original,false);
        $binding=vp3_profile_analytics_rows_v1($pdo,initiative_marketing_plans_query_v1().' AND l.product_id=?',[$owner,$original['agent_id'],(int)$row['product_id']],true)[0]??null;
        if($binding!==$row)throw new RuntimeException('The original marketing plan binding changed.',409);
        if(vp3_product_marketing_plan_inputs_v1($pdo,$owner,$row)!==$inputs)throw new RuntimeException('The saved marketing inputs changed during reporting.',409);
        $fresh=initiative_marketing_plan_view_v1($pdo,$owner,$row);
        if($fresh!==$view)throw new RuntimeException('The saved marketing plan changed during reporting.',409);
    };
    $delivered=false;
    if($view['resume']!==null&&vp3_profile_analytics_table_v1($pdo,'chat_messages')){
        $rows=vp3_profile_analytics_rows_v1($pdo,"SELECT context_json FROM chat_messages WHERE conversation_id=? AND role='assistant' AND user_id IS NULL ORDER BY id",[$original['conversation_id']],true);
        foreach($rows as $message){$ctx=vp3_profile_analytics_json_v1($message['context_json']);if(($ctx['source']??null)==='agent_product_launch'&&($ctx['product_launch']??null)===$view['resume']){$delivered=true;break;}}
    }
    $view['product_title']=vp3_product_marketing_text_v1($view['product_title']);
    return $view+['ref'=>$ref,'revision'=>$revision,'coverage'=>$view['needs_attention']?'limited':'ready',
        'actions'=>[['kind'=>'url','label'=>'Open original marketing plan','href'=>$view['chat_url']],
            ['kind'=>'url','label'=>'Open product listing','href'=>url('/profile-commerce-products.php?workspace_product='.$id)]],
        'presentation_owner'=>'initiative_product_launch_v1','product_marketing_presentation_owned'=>true,'legacy_delivered'=>$delivered];
}

/** Full read DTO for the existing specialist reports and query-only selectors. */
function vp3_product_marketing_review_v1(PDO $pdo,array $scope,DateTimeImmutable $now,?array $refs=null): array
{
    return vp3_product_marketing_snapshot_v1($pdo,$scope,static function(array $scope,array &$fences)use($pdo,$now,$refs):array{
        $out=['contract'=>'vp3.product-marketing-review.v1','coverage'=>'ready','observed_at_utc_ms'=>$now->getTimestamp()*1000,'plans'=>[],'publications'=>[],'sources'=>[]];
        if(!vp3_profile_analytics_tables_v1($pdo,['agent_product_launches_v1','agent_commerce_products_v800','agent_commerce_marketing_drafts_v1','agent_commerce_promotions_v1','user_media_assets']))return array_replace($out,['coverage'=>'unconfigured']);
        $selected=[];
        if($refs===null){
            $rows=vp3_profile_analytics_rows_v1($pdo,initiative_marketing_plans_query_v1().' ORDER BY l.product_id DESC LIMIT 13',[(int)$scope['user']['id'],$scope['agent_id']],true);
            if(count($rows)>12)$out['coverage']='limited';
            foreach(array_slice($rows,0,12) as $row)$selected[]=['kind'=>'plan','product_id'=>(int)$row['product_id']];
            if(vp3_profile_analytics_tables_v1($pdo,['agent_promotion_publications_v1','agent_promotion_clicks_v1','agent_promotion_orders_v1'])){
                $rows=vp3_profile_analytics_rows_v1($pdo,'SELECT publication_id FROM agent_promotion_publications_v1 WHERE owner_user_id=? AND agent_id=? ORDER BY created_at DESC,publication_id LIMIT 13',[(int)$scope['user']['id'],$scope['agent_id']],true);
                if(count($rows)>12)$out['coverage']='limited';$period=vp3_product_marketing_period_v1($pdo,$scope,[],$now);
                foreach(array_slice($rows,0,12) as $row)$selected[]=['kind'=>'outcome','publication_id'=>$row['publication_id'],'period'=>$period];
            }else $out['coverage']=$selected?'limited':'unconfigured';
        }else{
            if(count($refs)>24)throw new RuntimeException('Choose at most 24 exact product marketing sources.',422);
            foreach(array_values(array_unique($refs)) as $ref){if(!is_string($ref)||!($parsed=vp3_product_marketing_ref_parse_v1($ref,$now)))throw new RuntimeException('Choose canonical product marketing sources.',422);$selected[]=$parsed;}
        }
        foreach($selected as $pick){
            $start=count($fences);
            try{
                $data=$pick['kind']==='plan'?vp3_product_marketing_plan_v1($pdo,$scope,$pick['product_id'],$fences):
                    vp3_product_marketing_outcome_read_v1($pdo,$scope,$pick['publication_id'],$pick['period'],$now,$fences);
                if(!$data)continue;
                $out[$pick['kind']==='plan'?'plans':'publications'][]=$data;$item=vp3_product_marketing_source_v1($data,$pick['kind']);$out['sources'][$item['ref']]=$item;
                if($data['coverage']!=='ready')$out['coverage']='limited';
            }catch(Throwable $error){
                // A missing individual source is withheld; infrastructure failures
                // remain unavailable and retryable transaction failures propagate.
                if(vp3_profile_analytics_retryable_v1($error))throw $error;
                if(!$error instanceof RuntimeException&&!$error instanceof DomainException)throw $error;
                if(!in_array($error->getCode(),[403,404,409,422,424,503],true)&&!$error instanceof PDOException)throw $error;
                $fences=array_slice($fences,0,$start);$out['coverage']='limited';
                if($error instanceof PDOException||$error->getCode()===503)$out['_unavailable']=true;
            }
        }
        if(!$out['sources']&&!empty($out['_unavailable']))$out['coverage']='unavailable';unset($out['_unavailable']);return $out;
    });
}
function vp3_coordination_product_marketing_sources_v1(PDO $pdo,array $scope,?array $nativeRefs,DateTimeImmutable $now): array
{
    $refs=$nativeRefs===null?null:array_map(static fn(string $ref):string=>str_starts_with($ref,'marketing:')?$ref:'marketing:'.$ref,$nativeRefs);
    $review=vp3_product_marketing_review_v1($pdo,$scope,$now,$refs);return ['items'=>$review['sources'],'coverage'=>$review['coverage']];
}

/** Existing Profile finding keeps its presenter; these are bounded related facts. */
function vp3_product_marketing_conversation_associations_v1(PDO $pdo,array $scope,int $publicConversationId,DateTimeImmutable $now,int $limit=4): array
{
    if($publicConversationId<1)throw new RuntimeException('Choose an exact public conversation.',422);
    return vp3_product_marketing_snapshot_v1($pdo,$scope,static function(array $scope,array &$fences)use($pdo,$publicConversationId,$now,$limit):array{
        vp3_private_analytics_authority_current_v1($pdo,$scope,true);$fences[]=static fn()=>vp3_private_analytics_authority_current_v1($pdo,$scope,true);
        $history=vp3_profile_analytics_native_history_v1($pdo,(int)$scope['user']['id']);if(!isset($history['valid'][$publicConversationId]))return ['coverage'=>'unavailable','items'=>[]];
        $keys=[];foreach($history['threads'][$publicConversationId]??[] as $message){$ctx=vp3_profile_analytics_json_v1($message['context_json']??null);$key=$ctx['product_promotion_key']??null;if(is_string($key)&&preg_match('/\A[a-f0-9]{32}\z/D',$key))$keys[$key]=true;}
        // Native accepted replies did not record an owner timezone. Preserve
        // their known UTC calendar rather than inventing historical settings.
        $out=['coverage'=>'ready','items'=>[]];$zone='UTC';$timezoneSource='utc_fallback';$cached=[];
        foreach(array_keys($keys) as $publication){
            $start=count($fences);
            try{
                $identity=vp3_product_marketing_publication_v1($pdo,$scope,$publication);
                $items=vp3_product_marketing_exchanges_v1($pdo,(int)$scope['user']['id'],$publication,$history,$publicConversationId,$incomplete);
                if($incomplete)$out['coverage']='limited';
                if(!$items)continue;
                $fences[]=static fn()=>vp3_product_marketing_exchange_fence_v1($pdo,(int)$scope['user']['id'],$publication,$items);
                $fences[]=static function()use($pdo,$scope,$publication,$identity):void{if(vp3_product_marketing_publication_v1($pdo,$scope,$publication)['source_revision']!==$identity['source_revision'])throw new RuntimeException('Related publication changed.',409);};
                foreach($items as $item){
                    // Anchor a saved exchange to its own completed UTC day,
                    // never to today's moving seven-day discovery window.
                    $local=(new DateTimeImmutable('@'.intdiv($item['reply_at_utc_ms'],1000)))->setTimezone(new DateTimeZone($zone));
                    $cutoff=vp3_profile_analytics_local_day_start_v1($local->modify('+1 day'))->getTimestamp();$ref=null;
                    if($cutoff<=$now->getTimestamp()){
                        $period=vp3_profile_analytics_period_decode_v1(vp3_profile_analytics_period_ref_v1(7,$cutoff,$zone,$timezoneSource),$now);
                        $ref=vp3_product_marketing_outcome_ref_v1($publication,$period['ref'],$now);
                        if(!isset($cached[$ref]))$cached[$ref]=vp3_product_marketing_source_v1(vp3_product_marketing_outcome_read_v1($pdo,$scope,$publication,$period,$now,$fences),'outcome');
                        $source=$cached[$ref];
                    }else{
                        $plan=vp3_product_marketing_plan_v1($pdo,$scope,$identity['product_id'],$fences);
                        if(!$plan){$out['coverage']='limited';continue;}$source=vp3_product_marketing_source_v1($plan,'plan');
                    }
                    $out['items'][]=$item+['product_id'=>$identity['product_id'],'product_title'=>$identity['product_title'],'publication_id'=>$publication,'plan_ref'=>$identity['plan_ref'],'outcome_ref'=>$ref,'period_basis'=>'saved_reply_utc_day',
                        'revision'=>vp3_profile_analytics_hash_v1([$identity['source_revision'],$item['evidence_revision'],$source['ref']]),
                        'source'=>array_intersect_key($source,array_flip(['ref','revision','title','url'])),
                        'actions'=>[['kind'=>'url','label'=>$ref?'Review related publication':'Open original marketing plan','href'=>$source['url']]]];}
            }catch(RuntimeException|DomainException $error){if($error instanceof PDOException||!in_array($error->getCode(),[403,404,409],true))throw $error;$fences=array_slice($fences,0,$start);$out['coverage']='limited';}
        }
        usort($out['items'],static fn(array $a,array $b):int=>[$b['at_utc_ms'],$b['visitor_message_id']]<=>[$a['at_utc_ms'],$a['visitor_message_id']]);
        $limit=max(1,min(4,$limit));if(count($out['items'])>$limit){$out['items']=array_slice($out['items'],0,$limit);$out['coverage']='limited';}return $out;
    });
}
