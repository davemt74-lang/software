<?php
declare(strict_types=1);

require_once __DIR__.'/schema-metadata.php';
require_once __DIR__.'/profile-analytics-authority-v1.php';
require_once __DIR__.'/agent-commerce-recovery-pa2.php';
require_once __DIR__.'/agent-product-marketing-summary-v1.php';

/** Optional unconfigured sources do not hide another verified domain. */
function vp3_product_marketing_coverage_v1(array $states): string
{
    $states=array_values(array_unique(array_filter($states,static fn(string $state):bool=>$state!=='unconfigured')));
    if(!$states)return 'unconfigured';
    if(in_array('limited',$states,true)||count($states)>1)return 'limited';
    return $states[0];
}

/** An actual canonical native Chat row proves delivery; scan bookkeeping does not. */
function vp3_product_marketing_plan_delivered_v1(PDO $pdo,array $view): bool
{
    if(!is_array($view['resume']??null)||!schema_metadata_table_exists($pdo,'chat_messages'))return false;
    require_once __DIR__.'/initiative-product-launch-v1.php';
    $message=initiative_product_launch_notice_v1(['product_title'=>$view['product_title'],'stage'=>$view['stage']]);
    if($message===null)return false;
    $context=json_encode(['source'=>'agent_product_launch','skip_brain_archive'=>true,'product_launch'=>$view['resume']],JSON_THROW_ON_ERROR);
    return vp3_profile_analytics_rows_v1($pdo,"SELECT id FROM chat_messages WHERE conversation_id=? AND user_id IS NULL AND role='assistant'
        AND message=? AND context_json=? LIMIT 1",[$view['conversation_id'],$message,$context],true)!==[];
}

/**
 * Native plan views intentionally retain ordinary reads for their UI callers.
 * A report may run inside an older caller-owned RR snapshot. Verify each exact
 * dependency against a current shared lock before using that native view, and
 * retain the proof for its final check. Never replace or restart the caller TX.
 */
function vp3_product_marketing_plan_read_v1(PDO $pdo,string $sql,array $args,array &$proof): array
{
    $rows=vp3_profile_analytics_rows_v1($pdo,$sql,$args);
    $current=vp3_profile_analytics_rows_v1($pdo,$sql,$args,true);
    if($rows!==$current)throw new RuntimeException('The product plan changed during this read.',409);
    $encoded=json_encode($rows,JSON_THROW_ON_ERROR);
    foreach($rows as $row)foreach($row as $value)if(is_string($value)&&strlen($value)>65536)
        throw new RuntimeException('The product plan source exceeds the checked report limit.',503);
    $proof['bytes']+=strlen($encoded);
    if($proof['bytes']>2*1024*1024)throw new RuntimeException('The product plan source exceeds the checked report limit.',503);
    $proof['reads'][]=[$sql,$args,hash('sha256',$encoded)];
    return $rows;
}

/** Only selected files are read; absent or oversized bytes remain honest proof. */
function vp3_product_marketing_plan_file_v1(int $owner,array $asset): array
{
    $relative=(string)($asset['file_path']??'');
    clearstatcache(true,STONEFELLOW_ROOT.'/'.ltrim($relative,'/'));
    $path=initiative_work_product_file_v1($owner,$relative);
    $size=$path?@filesize($path):false;
    if($size===false)return ['available'=>false];
    if($size<1||$size>25*1024*1024)return ['available'=>false,'size'=>$size];
    $digest=@hash_file('sha256',$path);
    return ['available'=>is_string($digest),'size'=>$size,'sha256'=>is_string($digest)?$digest:null];
}

/** Bounded dependencies of the selected native plan, never a recent-draft scan. */
function vp3_product_marketing_plan_proof_v1(PDO $pdo,array $scope,array $row): array
{
    $owner=(int)$scope['user']['id'];$product=(int)$row['product_id'];
    $proof=['bytes'=>0,'reads'=>[],'files'=>[],'semantic'=>[]];
    $read=static function(string $sql,array $args)use($pdo,&$proof):array{
        return vp3_product_marketing_plan_read_v1($pdo,$sql,$args,$proof);
    };
    // The surrounding current authority fence remains authoritative. These
    // guards prevent the ordinary native view from manufacturing an unavailable
    // plan from an older user, Agent or private Chat row in that same caller TX.
    $read('SELECT id,role,is_active FROM users WHERE id=?',[$owner]);
    if($scope['agent_id']>0)$read('SELECT id,owner_user_id,is_active FROM user_agents WHERE id=?',[$scope['agent_id']]);
    $read('SELECT id,user_id,user_agent_id FROM chat_conversations WHERE id=?',[$scope['conversation_id']]);
    $productRow=$read('SELECT p.id,p.owner_user_id,p.product_key,p.title,SUBSTR(p.description,1,65537) AS description,
        p.product_type,p.fulfillment_type,p.payment_mode,p.price_cents,p.deposit_cents,p.currency,p.is_active,
        SUBSTR(p.metadata_json,1,65537) AS metadata_json,b.binding_type,b.binding_id
        FROM agent_commerce_products_v800 p LEFT JOIN agent_commerce_product_bindings_v800 b ON b.product_id=p.id
        WHERE p.id=? AND p.owner_user_id=? LIMIT 1',[$product,$owner])[0]??null;
    if(!$productRow)throw new RuntimeException('The product plan is unavailable.',404);
    $proof['semantic']['product']=$productRow;
    $launch=$read(initiative_marketing_plans_query_v1().' AND l.product_id=? AND l.conversation_id=?',
        [$owner,$scope['agent_id'],$product,$scope['conversation_id']])[0]??null;
    if($launch!==$row)throw new RuntimeException('The product plan changed during this read.',409);
    $proof['semantic']['launch']=$launch;
    $metadata=profile_commerce_metadata_v900($productRow);
    $assets=[];
    $assetId=static function(mixed $id)use(&$assets):void{
        if((is_int($id)||is_string($id))&&preg_match('/\A[1-9][0-9]{0,18}\z/D',(string)$id)
            &&(string)(int)$id===(string)$id)$assets[(int)$id]=true;
    };
    $assetId($metadata['source_asset_id']??null);
    $variant=null;$draftId=$row['draft_key'];
    if($row['creative_id']!==null){
        $variant=$read('SELECT id,owner_user_id,product_id,agent_id,conversation_id,draft_key,source_asset_id,source_sha256,
            SUBSTR(settings_json,1,65537) AS settings_json,generation_id,asset_id,asset_sha256,public_key,public_product_url,
            public_cta,publication_revision,status,created_at FROM agent_commerce_promotions_v1 WHERE owner_user_id=? AND id=?',
            [$owner,$row['creative_id']])[0]??null;
        $proof['semantic']['creative']=$variant;
        if($variant){
            $draftId=$variant['draft_key'];$assetId($variant['source_asset_id']);$assetId($variant['asset_id']);
            // Creation time matters only to the unstarted image timeout. Native
            // launch notices, review scans and worker leases are not new facts.
            if($variant['status']!=='designing'||!empty($variant['generation_id']))unset($proof['semantic']['creative']['created_at']);
        }
    }
    if($draftId!==null){
        $draftRows=$read('SELECT SUBSTR(d.draft_json,1,65537) AS draft_json FROM agent_commerce_marketing_drafts_v1 d
            INNER JOIN agent_commerce_products_v800 p ON p.id=d.product_id AND p.owner_user_id=d.owner_user_id
            WHERE d.owner_user_id=? AND d.product_id=? AND d.request_key=?',[$owner,$product,$draftId]);
        $proof['semantic']['draft']=$draftRows;
        $draft=json_decode((string)($draftRows[0]['draft_json']??''),true,16);
        $assetId(is_array($draft)?($draft['asset_id']??null):null);
    }
    if($variant&&$variant['status']==='designing'&&!empty($variant['generation_id'])){
        foreach(['agent_image_generations_v1','agent_workflow_runs','agent_workflow_actions','agent_workflow_receipts'] as $table)
            if(!schema_metadata_table_exists($pdo,$table))throw new RuntimeException('The product image proof is unavailable.',503);
        $generation=$read('SELECT generation_id,owner_user_id,agent_id,conversation_id,user_message_id,request_hash,run_id,
            SUBSTR(config_json,1,65537) AS config_json,asset_id FROM agent_image_generations_v1 WHERE generation_id=? AND owner_user_id=?',
            [$variant['generation_id'],$owner])[0]??null;
        $proof['semantic']['generation']=$generation;
        if($generation){
            $assetId($generation['asset_id']);
            $run=$read('SELECT id,owner_user_id,agent_id,origin,source_kind,source_key,execution_target,capability_key,status
                FROM agent_workflow_runs WHERE id=? AND owner_user_id=? LIMIT 1',[(int)$generation['run_id'],$owner])[0]??null;
            $proof['semantic']['image_run']=$run;
            if($run&&$run['status']==='completed'){
                // Match the native current-attempt proof, including its actual
                // receipt. A completed run alone never proves a saved image.
                $proof['semantic']['image_receipt']=$read("SELECT a.id,a.attempt_count,SUBSTR(a.result_json,1,65537) AS result_json,
                    r.receipt_key,SUBSTR(r.result_json,1,65537) AS receipt_json FROM agent_workflow_actions a
                    INNER JOIN agent_workflow_receipts r ON r.run_id=a.run_id AND r.action_id=a.id
                      AND r.owner_user_id=a.owner_user_id AND r.attempt_no=a.attempt_count
                    WHERE a.owner_user_id=? AND a.run_id=? AND a.action_key=? AND a.action_type='execute'
                      AND a.execution_target='cloud' AND a.capability_key='images.generate'
                      AND a.status='completed' AND r.status='completed' AND r.executor='cloud' ORDER BY a.id,r.id LIMIT 2",
                    [$owner,(int)$run['id'],$generation['generation_id']]);
            }
        }
    }
    if(!$variant||$variant['status']!=='designing'){
        if(!initiative_work_product_draft_v1($productRow)){
            if(!schema_metadata_table_exists($pdo,'user_profiles'))throw new RuntimeException('The product destination is unavailable.',503);
            $profile=$read('SELECT p.user_id,p.username,p.is_public,u.is_active FROM user_profiles p
                INNER JOIN users u ON u.id=p.user_id WHERE p.user_id=? LIMIT 1',[$owner]);
            $proof['semantic']['profile']=$profile;
            if(!empty($profile[0]['is_public'])&&!empty($profile[0]['is_active'])){
                // The canonical destination is the winner in this exact,
                // bounded catalog (including legacy duplicate-slug ordering).
                // Other catalog products are guards, not this plan's revision.
                $read('SELECT p.id,p.owner_user_id,p.product_key,p.title,p.is_active,p.updated_at,
                    SUBSTR(p.metadata_json,1,65537) AS metadata_json,b.binding_type,b.binding_id
                    FROM agent_commerce_products_v800 p LEFT JOIN agent_commerce_product_bindings_v800 b ON b.product_id=p.id
                    WHERE p.owner_user_id=? AND p.is_active=1 ORDER BY p.updated_at DESC,p.id DESC LIMIT 200',[$owner]);
            }
            if($variant&&$variant['status']==='published'&&is_string($variant['public_key'])){
                $published=$read("SELECT id,public_key,product_id,source_asset_id,asset_id,asset_sha256,
                    SUBSTR(settings_json,1,65537) AS settings_json,public_product_url,public_cta
                    FROM agent_commerce_promotions_v1 WHERE owner_user_id=? AND status='published' AND public_key=?
                    ORDER BY updated_at DESC,id DESC LIMIT 2",[$owner,$variant['public_key']]);
                if(count($published)>1)throw new RuntimeException('The product publication is ambiguous.',409);
                $proof['semantic']['publication']=$published;
                foreach($published as $publishedRow)$assetId($publishedRow['asset_id']);
            }
        }
    }
    ksort($assets,SORT_NUMERIC);
    foreach(array_keys($assets) as $id){
        $asset=$read('SELECT id,user_id,media_type,title,mime_type,file_path,file_size,source,
            SUBSTR(metadata_json,1,65537) AS metadata_json FROM user_media_assets WHERE id=? AND user_id=? LIMIT 1',[$id,$owner])[0]??null;
        $proof['semantic']['assets'][$id]=$asset;
        if($asset){
            $file=vp3_product_marketing_plan_file_v1($owner,$asset);
            $proof['files'][]=[$asset,$file];$proof['semantic']['files'][$id]=$file;
        }
    }
    $proof['revision']=hash('sha256',json_encode(['product-plan-dependencies-v1',$proof['semantic']],JSON_THROW_ON_ERROR));
    return $proof;
}

function vp3_product_marketing_plan_proof_current_v1(PDO $pdo,int $owner,array $proof): void
{
    foreach($proof['reads'] as [$sql,$args,$expected]){
        $actual=hash('sha256',json_encode(vp3_profile_analytics_rows_v1($pdo,$sql,$args,true),JSON_THROW_ON_ERROR));
        if(!hash_equals($expected,$actual))throw new RuntimeException('The product plan changed during this read.',409);
    }
    foreach($proof['files'] as [$asset,$expected])if(vp3_product_marketing_plan_file_v1($owner,$asset)!==$expected)
        throw new RuntimeException('The product image changed during this read.',409);
}

/** Private plan facts stay in their original Chat and keep native approval ownership. */
function vp3_product_marketing_plan_item_v1(PDO $pdo,array $scope,array $row): ?array
{
    require_once __DIR__.'/initiative-marketing-plans-v1.php';
    $owner=(int)$scope['user']['id'];$product=(int)$row['product_id'];
    if((int)$row['owner_user_id']!==$owner||(int)$row['agent_id']!==$scope['agent_id']
        ||(int)$row['conversation_id']!==$scope['conversation_id'])return null;
    $current=vp3_profile_analytics_rows_v1($pdo,'SELECT * FROM agent_commerce_products_v800 WHERE id=?',[$product],true)[0]??null;
    if(!$current||(int)$current['owner_user_id']!==$owner)return null;
    $proof=vp3_product_marketing_plan_proof_v1($pdo,$scope,$row);
    $view=initiative_marketing_plan_view_v1($pdo,$owner,$row);
    if($view['product_title']!==(string)$current['title'])throw new RuntimeException('The product plan changed during this read.',409);
    $fresh=vp3_profile_analytics_rows_v1($pdo,initiative_marketing_plans_query_v1().' AND l.product_id=? AND l.conversation_id=?',
        [$owner,$scope['agent_id'],$product,$scope['conversation_id']],true)[0]??null;
    if($fresh!==$row)throw new RuntimeException('The product plan changed during this read.',409);
    $latest=vp3_profile_analytics_rows_v1($pdo,'SELECT * FROM agent_commerce_products_v800 WHERE id=?',[$product],true)[0]??null;
    if($latest!==$current)throw new RuntimeException('The product listing changed during this read.',409);
    vp3_product_marketing_plan_proof_current_v1($pdo,$owner,$proof);
    $title=vp3_profile_specialist_text_v1($view['product_title'],140);
    if($title==='')$title='Product '.$product;
    $stage=$view['stage'];$label=initiative_marketing_plan_label_v1($stage);
    $summary=$title.' · '.$label.'.';
    $next=match($stage){
        'listing_review'=>'Review the existing private listing.',
        'marketing_review'=>'Review the existing promotion copy.',
        'photo_needed'=>'Choose an image for this existing plan.',
        'design_review'=>'Open the existing plan to review its graphic design.',
        'creative_ready','publication_review'=>'Review the exact saved creative in the existing plan.',
        'creative_failed','listing_unavailable','needs_attention'=>'Review the existing plan and its current source status.',
        default=>'',
    };
    $url=vp3_product_marketing_plan_href_v1($scope['agent_id'],$scope['conversation_id'],$product);
    $ref='marketing:product-plan:'.$product;
    $semantic=[$owner,$scope['agent_id'],$scope['conversation_id'],$product,$row['draft_key'],$row['creative_id'],
        $title,$stage,$view['needs_attention'],$view['resume'],$url,$proof['revision']];
    return ['ref'=>$ref,'revision'=>hash('sha256',json_encode(['product-plan-report-v1',$semantic],JSON_THROW_ON_ERROR)),
        'specialist'=>'marketing','marketing_source_kind'=>'plan','title'=>$title.' · product marketing plan',
        'summary'=>$summary,'next_step'=>$next,'question'=>'','url'=>$url,'status'=>$stage,
        'priority'=>$view['needs_attention']?'normal':'low','trigger'=>'product_plan','disposition'=>'current',
        'presentation_owner'=>'agent_product_launch','marketing_presentation_owned'=>true,
        'legacy_delivered'=>vp3_product_marketing_plan_delivered_v1($pdo,$view),
        'private_summary_only'=>true,'public_evidence_untrusted'=>true];
}

/** Exact sources have no discovery cap; discovery never follows another private Chat. */
function vp3_coordination_product_marketing_sources_v1(PDO $pdo,array $scope,?array $refs,DateTimeImmutable $now,bool $profile=false): array
{
    if(!$pdo->inTransaction())return agent_commerce_atomic_pa2($pdo,static fn():array=>
        vp3_coordination_product_marketing_sources_v1($pdo,$scope,$refs,$now,$profile));
    // An optional empty product domain must not turn an existing Campaign
    // report into an outage. No source fields are returned by this preflight.
    if($refs===null){
        $present=false;
        foreach($profile?['agent_promotion_publications_v1']:['agent_product_launches_v1','agent_promotion_publications_v1'] as $table){
            if(!schema_metadata_table_exists($pdo,$table))continue;
            $q=$pdo->prepare('SELECT 1 FROM '.$table.' WHERE owner_user_id=? AND agent_id=? AND conversation_id=? LIMIT 1');
            $q->execute([(int)($scope['user']['id']??0),$scope['agent_id']??-1,$scope['conversation_id']??0]);
            if($q->fetchColumn()!==false){$present=true;break;}
        }
        if(!$present)return ['items'=>[],'coverage'=>'unconfigured'];
    }
    $scope=vp3_profile_analytics_authority_current_v1($pdo,$scope,$profile);
    $owner=(int)$scope['user']['id'];$out=['items'=>[],'coverage'=>'unconfigured'];$states=[];
    if($refs!==null){
        if(!array_is_list($refs)||count($refs)>24)throw new InvalidArgumentException('Invalid product marketing source selection.');
        foreach($refs as $ref)if(!is_string($ref)||vp3_product_marketing_ref_kind_v1('marketing:'.$ref)===null
            ||($profile&&!str_starts_with($ref,'ft:')))throw new InvalidArgumentException('Invalid product marketing source.');
    }
    $planRefs=$profile?[]:($refs===null?null:array_values(array_filter($refs,static fn(string $ref):bool=>str_starts_with($ref,'product-plan:'))));
    if($planRefs!==[]){
        $ready=true;
        foreach(['agent_product_launches_v1','agent_commerce_products_v800','agent_commerce_marketing_drafts_v1','agent_commerce_promotions_v1','user_media_assets'] as $table)
            if(!schema_metadata_table_exists($pdo,$table))$ready=false;
        if(!$ready)$states[]='unavailable';
        else{
            require_once __DIR__.'/initiative-marketing-plans-v1.php';
            $args=[$owner,$scope['agent_id'],$scope['conversation_id']];
            $sql=initiative_marketing_plans_query_v1().' AND l.conversation_id=?';
            if($planRefs!==null){
                $ids=array_map(static fn(string $ref):int=>(int)substr($ref,13),$planRefs);
                $sql.=' AND l.product_id IN ('.implode(',',array_fill(0,count($ids),'?')).')';array_push($args,...$ids);
            }
            $rows=vp3_profile_analytics_rows_v1($pdo,$sql.' ORDER BY l.product_id DESC'.($planRefs===null?' LIMIT 13':''),$args,true);
            $state=$planRefs===null&&count($rows)>12?'limited':'ready';
            if($planRefs!==null&&count($rows)!==count(array_unique($planRefs)))$state='limited';
            foreach($planRefs===null?array_slice($rows,0,12):$rows as $row){
                try{$item=vp3_product_marketing_plan_item_v1($pdo,$scope,$row);if($item!==null)$out['items'][$item['ref']]=$item;else $state='limited';}
                catch(PDOException $error){throw $error;}
                catch(RuntimeException|DomainException $error){
                    if(!in_array($error->getCode(),[403,404,409,422,503],true))throw $error;
                    $state=$error->getCode()===503?'unavailable':'limited';
                }
            }
            $states[]=$state;
        }
    }
    $periodRefs=$refs===null?null:array_values(array_filter($refs,static fn(string $ref):bool=>str_starts_with($ref,'ft:')));
    if($periodRefs!==[]){
        if(!schema_metadata_table_exists($pdo,'agent_promotion_publications_v1'))$states[]='unavailable';
        else{
            require_once __DIR__.'/profile-commerce-followthrough-v1.php';
            $limited=false;
            if($periodRefs===null){
                $rows=vp3_profile_analytics_rows_v1($pdo,'SELECT publication_id FROM agent_promotion_publications_v1
                    WHERE owner_user_id=? AND agent_id=? AND conversation_id=? ORDER BY created_at DESC,publication_id DESC LIMIT 7',
                    [$owner,$scope['agent_id'],$scope['conversation_id']]);
                $limited=count($rows)>6;$selections=array_map(static fn(array $row):array=>['publication_id'=>$row['publication_id'],'period'=>1],array_slice($rows,0,6));
            }else $selections=array_map(static fn(string $ref):array=>['ref'=>'marketing:'.$ref],$periodRefs);
            $state=$limited?'limited':'ready';$periodItems=[];
            foreach($selections as $selection){
                try{
                    $data=vp3_profile_commerce_followthrough_v1($pdo,$scope,$selection,$now);
                    if($data['coverage']!=='ready')$state=$data['coverage'];
                    if($periodRefs===null&&!vp3_product_marketing_period_discoverable_v1($data))continue;
                    $item=vp3_product_marketing_period_item_v1($data,$scope['agent_id'],$scope['conversation_id'],$profile);
                    if($item!==null)$periodItems[$item['ref']]=$item;else $state='limited';
                }catch(PDOException $error){throw $error;}
                catch(RuntimeException|DomainException $error){
                    if(!in_array($error->getCode(),[403,404,409,422,503],true))throw $error;
                    if($profile&&$error->getCode()===403)throw $error;
                    $state=$error->getCode()===403?'unconfigured':'unavailable';
                    if($error->getCode()===403){$periodItems=[];break;}
                }
            }
            if($periodItems){
                try{vp3_profile_analytics_authority_current_v1($pdo,$scope);}
                catch(RuntimeException $error){
                    if($profile||!in_array($error->getCode(),[403,503],true))throw $error;
                    $periodItems=[];$state=$error->getCode()===403?'unconfigured':'unavailable';
                }
            }
            $out['items']+=$periodItems;
            if($out['items']&&in_array($state,['unavailable','unconfigured'],true))$state='limited';
            $states[]=$state;
        }
    }
    vp3_profile_analytics_authority_current_v1($pdo,$scope,$profile);
    $out['coverage']=vp3_product_marketing_coverage_v1($states);
    if($out['items']&&in_array($out['coverage'],['unavailable','unconfigured'],true))$out['coverage']='limited';
    return $out;
}
