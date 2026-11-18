<?php
declare(strict_types=1);

/** Read-only image facts. Loaded by the private fixed-period analytics reader. */
function vp3_profile_image_analytics_keys_v1(): array
{
    return ['image_creations_completed','image_initial_creations','image_revisions_completed','image_requests_failed'];
}
function vp3_profile_image_analytics_empty_v1(string $reason='storage_unconfigured'): array
{
    $side=['coverage'=>'unavailable','reason'=>$reason,'rankings_coverage'=>'unavailable','top_catalogs'=>[],'top_images'=>[],
        'catalogs_truncated'=>false,'images_truncated'=>false,'failures'=>[],'failure_coverage'=>'unavailable'];
    return ['points'=>vp3_profile_analytics_empty_points_v1(vp3_profile_image_analytics_keys_v1(),$reason),
        'coverage'=>$reason==='storage_unconfigured'?'unconfigured':'unavailable','reason'=>$reason,'evidence'=>[],
        'usage'=>['contract'=>'vp3.profile-image-analytics.v1','current'=>$side,'previous'=>$side],
        'failures'=>['coverage'=>'unavailable','reason'=>$reason,'count'=>null,'observed_count'=>null,'reasons'=>[]]];
}
function vp3_profile_image_analytics_label_v1(mixed $value,int $limit,string $fallback): string
{
    if(!is_string($value)||!mb_check_encoding($value,'UTF-8'))return $fallback;
    $value=trim((string)preg_replace('/[\p{Cc}\p{Cf}\p{Z}]+/u',' ',strip_tags($value)));
    $value=trim(mb_substr($value,0,$limit,'UTF-8'));
    return $value===''?$fallback:$value;
}
/** Frozen, free, explicitly owned references; current catalog visibility is irrelevant. */
function vp3_profile_image_analytics_catalog_v1(mixed $json,int $owner): ?array
{
    $s=vp3_profile_analytics_json_v1($json);
    if(!$s||($s['snapshot_version']??null)!==1||($s['owner_user_id']??null)!==$owner||($s['price_cents']??null)!==0
        ||!is_int($s['collection_id']??null)||$s['collection_id']<1||!is_int($s['catalog_revision']??null)||$s['catalog_revision']<1
        ||!is_int($s['selection_asset_id']??null)||$s['selection_asset_id']<0||!is_string($s['catalog_name']??null)
        ||!is_array($s['references']??null)||!array_is_list($s['references'])||count($s['references'])<1||count($s['references'])>3)return null;
    $seen=[];$bytes=0;$refs=[];
    foreach($s['references'] as $ref){
        if(!is_array($ref)||!is_int($ref['asset_id']??null)||$ref['asset_id']<1||isset($seen[$ref['asset_id']])
            ||!is_string($ref['title']??null)||!in_array($ref['mime_type']??null,['image/jpeg','image/png','image/webp'],true)
            ||!is_int($ref['byte_size']??null)||$ref['byte_size']<1||!is_int($ref['width']??null)||!is_int($ref['height']??null)
            ||$ref['width']<1||$ref['width']>4096||$ref['height']<1||$ref['height']>4096)return null;
        foreach(['sha256','revision'] as $key)if(!is_string($ref[$key]??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$ref[$key]))return null;
        $seen[$ref['asset_id']]=true;$bytes+=$ref['byte_size'];
        $refs[]=['asset_id'=>$ref['asset_id'],'title'=>vp3_profile_image_analytics_label_v1($ref['title'],500,'Image '.$ref['asset_id']),
            'sha256'=>$ref['sha256'],'revision'=>$ref['revision']];
    }
    if($bytes>4194304||($s['selection_asset_id']>0&&(count($refs)!==1||$refs[0]['asset_id']!==$s['selection_asset_id'])))return null;
    return ['collection_id'=>$s['collection_id'],'name'=>vp3_profile_image_analytics_label_v1($s['catalog_name'],100,'Catalog '.$s['collection_id']),
        'revision'=>$s['catalog_revision'],'selection_asset_id'=>$s['selection_asset_id'],'references'=>$refs];
}
function vp3_profile_image_analytics_failure_v1(string $raw): array
{
    [$code,$label]=match($raw){
        'provider_access_denied'=>['provider_access','Image provider access unavailable'],
        'provider_rate_limited'=>['provider_busy','Image provider temporarily busy'],
        'provider_request_rejected'=>['request_rejected','Image request rejected by the provider'],
        'provider_outcome_unconfirmed','provider_image_unconfirmed'=>['result_unconfirmed','Image result could not be confirmed'],
        'provider_usage_unconfirmed'=>['usage_unconfirmed','Image usage could not be confirmed'],
        'image_too_large'=>['image_too_large','Image exceeded the supported size'],
        'image_storage_unavailable'=>['storage_unavailable','Saved image storage unavailable'],
        'subscription_required','service_policy_denied','service_budget_exceeded','service_usage_unavailable','quota_preflight_failed'=>['allowance_unavailable','Owner AI allowance unavailable'],
        'provider_unavailable','provider_route_changed'=>['provider_unavailable','Image provider unavailable'],
        'image_reference_changed','source_authority_changed'=>['source_changed','Approved image source or access changed'],
        default=>['other','Image edit could not be completed'],
    };
    return ['code'=>$code,'label'=>$label];
}
function vp3_profile_image_analytics_reason_counts_v1(array $counts): array
{
    $rows=array_values($counts);usort($rows,static fn(array $a,array $b):int=>($b['count']<=>$a['count'])?:strcmp($a['code'],$b['code']));return $rows;
}
function vp3_profile_image_analytics_rank_v1(array $rows,string $id): array
{
    $rows=array_values($rows);
    usort($rows,static fn(array $a,array $b):int=>($b['completed_count']<=>$a['completed_count'])?:($a[$id]<=>$b[$id]));
    return array_map(static function(array $row):array{unset($row['_at']);return $row;},$rows);
}

/** The request fingerprint is bound into the exact native accepted turn. */
function vp3_profile_image_analytics_admission_v1(array $row,array $turn,array $history,int $owner,?array $catalog): bool
{
    if(!$catalog||!vp3_profile_analytics_turn_valid_v1($turn,$history,$owner)||!preg_match('/\A[a-f0-9]{32}\z/D',(string)$row['request_id'])
        ||!preg_match('/\A[a-f0-9]{64}\z/D',(string)$row['request_hash'])||!preg_match('/\A[a-f0-9]{64}\z/D',(string)$row['input_sha256']))return false;
    foreach(['owner_user_id','profile_agent_id','conversation_id','profile_session_id','visitor_user_id','visitor_message_id','conversation_epoch'] as $field)
        if((int)$row[$field]!== (int)$turn[$field])return false;
    $message=$history['messages'][(int)$row['visitor_message_id']]??null;
    $at=vp3_profile_analytics_utc_v1($row['created_at']);$turnAt=vp3_profile_analytics_utc_v1($turn['created_at']);
    if((int)$row['turn_id']!==(int)$turn['id']||!$message||!is_string($row['prompt'])||$row['prompt']!==$message['message']
        ||$at===null||$turnAt===null||$at>$turnAt||(int)$row['base_message_id']<0||($turn['chat_mode']??'')!=='agent')return false;
    $base=(int)$row['base_message_id'];$matched=false;
    foreach([null,$catalog['revision'],(string)$catalog['revision']] as $revision){
        $intent=hash('sha256',json_encode([$catalog['collection_id'],$catalog['selection_asset_id'],$revision,$base,
            $base>0?null:$row['input_sha256'],$base>0?null:$row['input_name'],$base>0?null:$row['input_mime']],JSON_THROW_ON_ERROR));
        if(hash_equals($row['request_hash'],$intent)){$matched=true;break;}
    }
    $hash=hash('sha256',json_encode([$owner,(int)$row['profile_agent_id'],(int)$row['profile_session_id'],'native',
        $turn['credential_hash'],$row['prompt'],'agent',['profile_image_edit'=>$row['request_hash']]],JSON_THROW_ON_ERROR));
    if(!$matched||!hash_equals((string)$turn['request_hash'],$hash))return false;
    $visitor=(int)$row['visitor_user_id'];
    return hash_equals(hash('sha256',$visitor>0?'member:'.$visitor:'session:'.(int)$row['profile_session_id']),(string)$row['visitor_key']);
}

function vp3_profile_image_analytics_v1(PDO $pdo,int $owner,array $window,array $history): array
{
    $out=vp3_profile_image_analytics_empty_v1();
    if(!vp3_profile_analytics_tables_v1($pdo,['profile_image_edits_v1','profile_agent_handoff_turns_pa3','agent_workflow_runs','agent_workflow_actions','agent_workflow_receipts']))return $out;
    // Never load uploaded bytes, generated pixels or current public media rows.
    $rows=vp3_profile_analytics_rows_v1($pdo,'SELECT request_id,owner_user_id,profile_agent_id,conversation_id,profile_session_id,visitor_user_id,visitor_key,visitor_message_id,turn_id,conversation_epoch,root_message_id,base_message_id,request_hash,prompt,input_name,input_mime,input_sha256,config_json,catalog_json,state,output_sha256,output_bytes,output_width,output_height,result_message_id,error_code,created_at,completed_at FROM profile_image_edits_v1 WHERE owner_user_id=? ORDER BY visitor_message_id,request_id',[$owner],true);
    $turns=vp3_profile_analytics_rows_v1($pdo,'SELECT * FROM profile_agent_handoff_turns_pa3 WHERE owner_user_id=? ORDER BY id',[$owner],true);
    $runs=vp3_profile_analytics_rows_v1($pdo,"SELECT id,agent_id,source_key,source_kind,workflow_type FROM agent_workflow_runs WHERE owner_user_id=? AND source_kind='profile_visitor_turn' ORDER BY id",[$owner],true);
    $actions=vp3_profile_analytics_rows_v1($pdo,"SELECT id,run_id,action_key,capability_key FROM agent_workflow_actions WHERE owner_user_id=? AND capability_key='profile.visitor.reply' ORDER BY id",[$owner],true);
    $receipts=vp3_profile_analytics_rows_v1($pdo,'SELECT * FROM agent_workflow_receipts WHERE owner_user_id=? ORDER BY id',[$owner],true);
    $turnMap=[];$runMap=[];$actionMap=[];$receiptMap=[];$resultRows=[];$duplicate=[];$seen=[];
    foreach($turns as $row){$row['_at']=vp3_profile_analytics_utc_v1($row['created_at']);$turnMap[(int)$row['id']]=$row;}
    foreach($runs as $row)$runMap[(int)$row['id']]=$row;
    foreach($actions as $row)$actionMap[(int)$row['id']]=$row;
    foreach($receipts as $row)$receiptMap[(string)$row['receipt_key']][]=$row;
    foreach($rows as $index=>$row){
        foreach(['request_id','visitor_message_id','turn_id','result_message_id'] as $field){
            if($row[$field]===null)continue;$key=$field.':'.$row[$field];
            if(isset($seen[$key])){$duplicate[$index]=true;$duplicate[$seen[$key]]=true;}else $seen[$key]=$index;
        }
        if($row['result_message_id']!==null)$resultRows[(int)$row['result_message_id']]=$index;
    }
    $imageReplies=[];
    foreach($history['messages'] as $message){
        if(($message['sender_type']??null)!=='agent')continue;$context=vp3_profile_analytics_json_v1($message['context_json']??null);
        $request=$context['profile_image_request']??null;
        if(is_string($request)&&preg_match('/\A[a-f0-9]{32}\z/D',$request))$imageReplies[$request][]=[$message,$context];
    }
    $replyProof=static function(array $row,array $turn,bool $ready)use($imageReplies,$owner,$runMap,$actionMap,$receiptMap):?array{
        $found=[];
        foreach($imageReplies[$row['request_id']]??[] as [$message,$context]){
            if($ready&&(int)$message['id']!==(int)$row['result_message_id'])continue;
            if(($context['answer_mode']??null)!=='profile_image_edit'||($context['profile_image_request']??null)!==$row['request_id']
                ||($context['profile_image_ready']??null)!==$ready||($context['visitor_message_id']??null)!==(int)$row['visitor_message_id'])continue;
            $proof=vp3_profile_analytics_agent_reply_proof_v1($owner,$message,$turn,$runMap,$actionMap,$receiptMap);
            if($proof)$found[]=$proof;
        }
        return count($found)===1?$found[0]:null;
    };
    $proofs=[];$prove=function(int $index)use(&$prove,&$proofs,$rows,$duplicate,$turnMap,$history,$owner,$resultRows,$replyProof):?array{
        if(array_key_exists($index,$proofs))return $proofs[$index];$proofs[$index]=null;
        $row=$rows[$index];$turn=$turnMap[(int)$row['turn_id']]??null;$catalog=vp3_profile_image_analytics_catalog_v1($row['catalog_json'],$owner);
        if(isset($duplicate[$index])||!$turn||!vp3_profile_image_analytics_admission_v1($row,$turn,$history,$owner,$catalog))return null;
        $base=(int)$row['base_message_id'];$root=(int)$row['root_message_id'];
        if($base===0){if($root!==(int)$row['visitor_message_id'])return null;}
        else{
            if($base>=(int)$row['visitor_message_id']||!isset($resultRows[$base]))return null;
            $parent=$rows[$resultRows[$base]];$parentProof=$prove($resultRows[$base]);
            $config=vp3_profile_analytics_json_v1($row['config_json']);$input=$config['reference']['generated_output']??null;
            if(!$parentProof||$parent['state']!=='completed'||($parentProof['at']??PHP_INT_MAX)>vp3_profile_analytics_utc_v1($row['created_at'])
                ||(int)$parent['conversation_id']!==(int)$row['conversation_id']
                ||$root!==(int)$parent['root_message_id']||!hash_equals((string)$parent['output_sha256'],(string)$row['input_sha256'])
                ||!is_array($input)||($input['request_id']??null)!==$parent['request_id']||($input['output_sha256']??null)!==$parent['output_sha256'])return null;
        }
        $proof=['catalog'=>$catalog,'admitted_at'=>vp3_profile_analytics_utc_v1($row['created_at']),'initial'=>$base===0,'reply'=>null];
        if($row['state']==='completed'){
            $reply=$replyProof($row,$turn,true);$at=vp3_profile_analytics_utc_v1($row['completed_at']);
            if(!$reply||$at===null||$at<$reply['at']||(int)$row['output_bytes']<1||(int)$row['output_width']<1||(int)$row['output_height']<1
                ||!preg_match('/\A[a-f0-9]{64}\z/D',(string)$row['output_sha256']))return null;
            $proof['reply']=$reply;$proof['at']=$at;
        }elseif($row['state']==='failed'){$proof['reply']=$replyProof($row,$turn,false);$proof['at']=$proof['reply']['at']??null;}
        return $proofs[$index]=$proof;
    };
    $keys=vp3_profile_image_analytics_keys_v1();$values=['current'=>array_fill_keys($keys,0),'previous'=>array_fill_keys($keys,0)];
    $gaps=['current'=>['completed'=>false,'failed'=>false],'previous'=>['completed'=>false,'failed'=>false]];
    $catalogs=['current'=>[],'previous'=>[]];$images=$catalogs;$failures=$catalogs;$live=[];$liveCount=0;$liveGap=false;
    foreach($rows as $index=>$row){
        if(!in_array($row['state'],['queued','editing','staged','completed','failed','cancelled'],true)){
            $created=vp3_profile_analytics_utc_v1($row['created_at']);$liveGap=true;
            foreach(['current','previous'] as $candidate){$to=$candidate==='current'?$window['to_utc_ms']:$window['previous']['to_utc_ms'];
                if($created===null||$created<$to)$gaps[$candidate]=['completed'=>true,'failed'=>true];}
            continue;
        }
        if(!in_array($row['state'],['completed','failed'],true))continue;
        $proof=$prove($index);$kind=$row['state']==='completed'?'completed':'failed';$created=vp3_profile_analytics_utc_v1($row['created_at']);
        $at=$proof['at']??($kind==='completed'?vp3_profile_analytics_utc_v1($row['completed_at']):null);
        $side=vp3_profile_analytics_window_key_v1($at,$window);
        if($kind==='failed'){
            if($proof){$reason=vp3_profile_image_analytics_failure_v1((string)$row['error_code']);$live[$reason['code']]??=$reason+['count'=>0];$live[$reason['code']]['count']++;$liveCount++;}
            else $liveGap=true;
        }
        if(!$proof||$at===null){
            foreach(['current','previous'] as $candidate){$to=$candidate==='current'?$window['to_utc_ms']:$window['previous']['to_utc_ms'];
                if($side===$candidate||($at===null&&($created===null||$created<$to)))$gaps[$candidate][$kind]=true;}
            continue;
        }
        if($side===null)continue;
        if($kind==='failed'){
            $values[$side]['image_requests_failed']++;$failures[$side][$reason['code']]??=$reason+['count'=>0];$failures[$side][$reason['code']]['count']++;
            $out['evidence'][]=['image_failure',$row['request_id'],$at,$reason['code'],$proof['reply']];continue;
        }
        $values[$side]['image_creations_completed']++;$values[$side][$proof['initial']?'image_initial_creations':'image_revisions_completed']++;
        $catalog=$proof['catalog'];$id=$catalog['collection_id'];
        // For a renamed source, the most recent verified completion supplies its frozen name.
        $catalogs[$side][$id]??=['collection_id'=>$id,'name'=>$catalog['name'],'completed_count'=>0,'initial_count'=>0,'revision_count'=>0,'_at'=>0];
        if($at>=$catalogs[$side][$id]['_at']){$catalogs[$side][$id]['name']=$catalog['name'];$catalogs[$side][$id]['_at']=$at;}
        $catalogs[$side][$id]['completed_count']++;$catalogs[$side][$id][$proof['initial']?'initial_count':'revision_count']++;
        foreach($catalog['references'] as $ref){$id=$ref['asset_id'];$images[$side][$id]??=['asset_id'=>$id,'title'=>$ref['title'],'completed_count'=>0,'_at'=>0];
            if($at>=$images[$side][$id]['_at']){$images[$side][$id]['title']=$ref['title'];$images[$side][$id]['_at']=$at;}$images[$side][$id]['completed_count']++;}
        $out['evidence'][]=['image_completion',$row['request_id'],$at,(int)$row['root_message_id'],(int)$row['base_message_id'],
            (int)$row['visitor_message_id'],(int)$row['result_message_id'],$row['output_sha256'],$catalog,$proof['reply']];
    }
    $limited=false;
    foreach(['current','previous'] as $side){
        $completionCoverage=$gaps[$side]['completed']?'partial':'complete';$failureCoverage=$gaps[$side]['failed']?'partial':'complete';
        foreach($keys as $key){$partial=$key==='image_requests_failed'?$gaps[$side]['failed']:$gaps[$side]['completed'];
            $out['points'][$key][$side]=vp3_profile_analytics_point_v1($values[$side][$key],$partial?'partial':'complete',$partial?'source_proof_incomplete':'complete');}
        $rankedCatalogs=vp3_profile_image_analytics_rank_v1($catalogs[$side],'collection_id');
        $rankedImages=vp3_profile_image_analytics_rank_v1($images[$side],'asset_id');
        $partial=$completionCoverage!=='complete'||$failureCoverage!=='complete';$limited=$limited||$partial;
        $out['usage'][$side]=['coverage'=>$partial?'partial':'complete','reason'=>$partial?'source_proof_incomplete':'complete',
            'rankings_coverage'=>$completionCoverage,'top_catalogs'=>array_slice($rankedCatalogs,0,10),'top_images'=>array_slice($rankedImages,0,10),
            'catalogs_truncated'=>count($catalogs[$side])>10,'images_truncated'=>count($images[$side])>10,
            'failures'=>vp3_profile_image_analytics_reason_counts_v1($failures[$side]),'failure_coverage'=>$failureCoverage];
    }
    $out['coverage']=$limited?'limited':'ready';$out['reason']=$limited?'source_proof_incomplete':'complete';
    $out['failures']=['coverage'=>$liveGap?'partial':'complete','reason'=>$liveGap?'source_proof_incomplete':'complete',
        'count'=>$liveGap?null:$liveCount,'observed_count'=>$liveGap?$liveCount:null,'reasons'=>vp3_profile_image_analytics_reason_counts_v1($live)];
    return $out;
}

/** The UTC allowance uses exactly admission's all-state created_at>=day predicate. */
function vp3_profile_image_analytics_allowance_v1(PDO $pdo,int $owner,int $now): array
{
    $start=(new DateTimeImmutable('@'.intdiv($now,1000)))->setTimezone(new DateTimeZone('UTC'))->setTime(0,0);
    $out=['coverage'=>'unavailable','reason'=>'storage_unconfigured','enabled'=>null,'timezone'=>'UTC',
        'day_start_utc_ms'=>$start->getTimestamp()*1000,'resets_at_utc_ms'=>($start->getTimestamp()+86400)*1000,
        'daily_limit'=>null,'visitor_daily_limit'=>null,'used'=>null,'remaining'=>null];
    try{
        if(!vp3_profile_analytics_tables_v1($pdo,['profile_image_settings_v1','profile_image_edits_v1']))return $out;
        $settings=vp3_profile_analytics_rows_v1($pdo,'SELECT enabled,daily_limit,visitor_daily_limit FROM profile_image_settings_v1 WHERE owner_user_id=?',[$owner],true)[0]??['enabled'=>0,'daily_limit'=>10,'visitor_daily_limit'=>3];
        if(!in_array($settings['enabled'],[0,1,'0','1'],true)||(int)$settings['daily_limit']<1||(int)$settings['daily_limit']>100
            ||(int)$settings['visitor_daily_limit']<1||(int)$settings['visitor_daily_limit']>20){$out['reason']='source_proof_incomplete';return $out;}
        $count=vp3_profile_analytics_rows_v1($pdo,'SELECT COUNT(*) AS total FROM profile_image_edits_v1 WHERE owner_user_id=? AND created_at>=?',[$owner,$start->format('Y-m-d H:i:s')],true);
        $used=(int)$count[0]['total'];$limit=(int)$settings['daily_limit'];
        return array_replace($out,['coverage'=>'complete','reason'=>'complete','enabled'=>(bool)$settings['enabled'],
            'daily_limit'=>$limit,'visitor_daily_limit'=>(int)$settings['visitor_daily_limit'],'used'=>$used,'remaining'=>max(0,$limit-$used)]);
    }catch(Throwable $error){if(vp3_profile_analytics_retryable_v1($error))throw $error;$out['reason']='storage_unavailable';return $out;}
}
