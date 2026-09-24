<?php
declare(strict_types=1);

/**
 * Campaigns & Rewards V1.23 — Journey Publishing, Versioning & Release Management.
 */
const VP3_CAMPAIGNS_REWARDS_V123='vp3-campaigns-rewards-v123-20260923';

function campaigns_rewards_journey_v123(PDO $pdo,int $journeyId): ?array
{
    if($journeyId<1)return null;
    $q=$pdo->prepare("SELECT j.*,c.merchant_id,c.public_id campaign_public_id,c.name campaign_name,c.status campaign_status,c.environment,c.current_version_no campaign_version_no,
      m.public_id merchant_public_id,m.status merchant_status
      FROM campaign_journeys j
      INNER JOIN campaigns c ON c.id=j.campaign_id
      INNER JOIN merchant_accounts m ON m.id=c.merchant_id
      WHERE j.id=? LIMIT 1");
    $q->execute([$journeyId]);$row=$q->fetch();return $row?:null;
}

function campaigns_rewards_journey_by_key_v123(PDO $pdo,int $campaignId,string $journeyKey): ?array
{
    $q=$pdo->prepare("SELECT id FROM campaign_journeys WHERE campaign_id=? AND journey_key=? LIMIT 1");
    $q->execute([$campaignId,$journeyKey]);$id=(int)$q->fetchColumn();
    return $id>0?campaigns_rewards_journey_v123($pdo,$id):null;
}

function campaigns_rewards_journey_version_v123(PDO $pdo,int $versionId): ?array
{
    if($versionId<1)return null;
    $q=$pdo->prepare("SELECT v.*,j.campaign_id,j.journey_key,j.name journey_name,j.status journey_status,j.enrollment_status,j.current_draft_version_id,j.current_published_version_id,
      c.merchant_id,c.public_id campaign_public_id,c.name campaign_name,c.status campaign_status,c.environment,c.current_version_no campaign_version_no,
      m.public_id merchant_public_id,m.status merchant_status
      FROM campaign_journey_versions v
      INNER JOIN campaign_journeys j ON j.id=v.journey_id
      INNER JOIN campaigns c ON c.id=j.campaign_id
      INNER JOIN merchant_accounts m ON m.id=c.merchant_id
      WHERE v.id=? LIMIT 1");
    $q->execute([$versionId]);$row=$q->fetch();if(!$row)return null;
    $row['graph']=json_decode((string)$row['graph_json'],true)?:['schema'=>'campaign-journey-graph-v1','journey_key'=>$row['journey_key'],'nodes'=>[]];
    $row['validation']=json_decode((string)($row['validation_json']??''),true)?:[];
    return $row;
}

function campaigns_rewards_journey_versions_v123(PDO $pdo,int $journeyId): array
{
    $q=$pdo->prepare("SELECT * FROM campaign_journey_versions WHERE journey_id=? ORDER BY version_no DESC,id DESC");$q->execute([$journeyId]);
    $rows=$q->fetchAll()?:[];
    foreach($rows as &$row){$row['graph']=json_decode((string)$row['graph_json'],true)?:[];$row['validation']=json_decode((string)($row['validation_json']??''),true)?:[];}unset($row);
    return $rows;
}

function campaigns_rewards_journeys_v123(PDO $pdo,int $merchantId,int $campaignId=0): array
{
    $sql="SELECT j.*,c.name campaign_name,c.status campaign_status,c.environment
      FROM campaign_journeys j INNER JOIN campaigns c ON c.id=j.campaign_id WHERE c.merchant_id=?";
    $params=[$merchantId];if($campaignId>0){$sql.=" AND j.campaign_id=?";$params[]=$campaignId;}
    $sql.=" ORDER BY j.campaign_id,j.status='archived',j.name,j.id";
    $q=$pdo->prepare($sql);$q->execute($params);return $q->fetchAll()?:[];
}

function campaigns_rewards_graph_nodes_v123(array $graph): array
{
    return array_values(array_filter((array)($graph['nodes']??[]),static fn($node)=>is_array($node)&&!empty($node['message_id'])&&is_array($node['template']??null)));
}

function campaigns_rewards_graph_groups_v123(array $graph): array
{
    $out=[];
    foreach(campaigns_rewards_graph_nodes_v123($graph) as $node){
        $step=(string)($node['template']['step_key']??'step');$out[$step][]=$node;
    }
    foreach($out as &$variants)usort($variants,static fn(array $a,array $b):int=>strcmp((string)($a['template']['variant_key']??'default'),(string)($b['template']['variant_key']??'default')));unset($variants);
    return $out;
}

function campaigns_rewards_graph_node_from_message_v123(array $row): array
{
    $template=is_array($row['template']??null)?$row['template']:(json_decode((string)($row['template_json']??''),true)?:[]);
    return [
        'message_id'=>(int)$row['id'],'message_key'=>(string)$row['message_key'],'message_version_no'=>(int)$row['version_no'],
        'channel'=>(string)$row['channel'],'subject'=>(string)($row['subject']??''),'template'=>$template,
    ];
}

function campaigns_rewards_graph_hydrate_node_v123(array $node): array
{
    return [
        'id'=>(int)$node['message_id'],'message_key'=>(string)$node['message_key'],'version_no'=>(int)($node['message_version_no']??1),
        'channel'=>(string)($node['channel']??'orchestration'),'subject'=>(string)($node['subject']??''),'template'=>(array)($node['template']??[]),
    ];
}

function campaigns_rewards_ensure_journey_v123(PDO $pdo,int $campaignId,string $journeyKey,int $actorUserId,string $name=''): array
{
    $journeyKey=campaigns_rewards_slug_v100($journeyKey,80)?:'default';
    $existing=campaigns_rewards_journey_by_key_v123($pdo,$campaignId,$journeyKey);if($existing)return $existing;
    $name=campaigns_rewards_text_v100($name!==''?$name:ucwords(str_replace('-',' ',$journeyKey)),190);
    $pdo->prepare("INSERT INTO campaign_journeys
      (public_id,campaign_id,journey_key,name,status,enrollment_status,created_by_user_id,created_at,updated_at)
      VALUES (?,?,?,?,'draft','open',?,UTC_TIMESTAMP(),UTC_TIMESTAMP())")
      ->execute([campaigns_rewards_uuid_v100(),$campaignId,$journeyKey,$name,$actorUserId?:null]);
    return campaigns_rewards_journey_v123($pdo,(int)$pdo->lastInsertId())?:throw new RuntimeException('Journey could not be created.');
}

function campaigns_rewards_ensure_draft_version_v123(PDO $pdo,array $journey,int $actorUserId): array
{
    $draftId=max(0,(int)($journey['current_draft_version_id']??0));
    if($draftId>0){
        $draft=campaigns_rewards_journey_version_v123($pdo,$draftId);
        if($draft&&$draft['status']==='draft')return $draft;
    }
    $published=null;$publishedId=max(0,(int)($journey['current_published_version_id']??0));
    if($publishedId>0)$published=campaigns_rewards_journey_version_v123($pdo,$publishedId);
    $q=$pdo->prepare("SELECT COALESCE(MAX(version_no),0) FROM campaign_journey_versions WHERE journey_id=?");$q->execute([(int)$journey['id']]);
    $versionNo=(int)$q->fetchColumn()+1;
    $graph=$published['graph']??['schema'=>'campaign-journey-graph-v1','journey_key'=>$journey['journey_key'],'nodes'=>[]];
    $pdo->prepare("INSERT INTO campaign_journey_versions
      (public_id,journey_id,version_no,status,graph_json,validation_json,release_notes,based_on_version_id,created_by_user_id,created_at,updated_at)
      VALUES (?,?,?,'draft',?,'{}','',?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())")
      ->execute([campaigns_rewards_uuid_v100(),(int)$journey['id'],$versionNo,campaigns_rewards_json_v100($graph),$publishedId?:null,$actorUserId?:null]);
    $newId=(int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE campaign_journeys SET current_draft_version_id=?,updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([$newId,(int)$journey['id']]);
    return campaigns_rewards_journey_version_v123($pdo,$newId)?:throw new RuntimeException('Journey draft could not be created.');
}

function campaigns_rewards_update_draft_graph_v123(PDO $pdo,int $versionId,array $graph): array
{
    $version=campaigns_rewards_journey_version_v123($pdo,$versionId)?:throw new RuntimeException('Journey version not found.');
    if((string)$version['status']!=='draft')throw new RuntimeException('Only a draft journey version can be edited.');
    $validation=campaigns_rewards_validate_graph_v123($pdo,(int)$version['journey_id'],$graph,false);
    $pdo->prepare("UPDATE campaign_journey_versions SET graph_json=?,validation_json=?,updated_at=UTC_TIMESTAMP() WHERE id=?")
      ->execute([campaigns_rewards_json_v100($graph),campaigns_rewards_json_v100($validation),$versionId]);
    return campaigns_rewards_journey_version_v123($pdo,$versionId)?:throw new RuntimeException('Journey draft unavailable.');
}

function campaigns_rewards_node_save_v123(PDO $pdo,int $merchantId,int $campaignId,int $actorUserId,array $input,int $messageId=0): array
{
    campaigns_rewards_platform_assert_can_v100($pdo,$merchantId,$actorUserId,'campaigns.edit');
    $campaign=campaigns_rewards_campaign_platform_v100($pdo,$campaignId)?:throw new RuntimeException('Campaign not found.');
    if((int)$campaign['merchant_id']!==$merchantId)throw new RuntimeException('Campaign not found.');

    $previous=null;$previousTemplate=[];
    if($messageId>0){
        $previous=campaigns_rewards_message_v120($pdo,$messageId)?:throw new RuntimeException('Journey node not found.');
        if((int)$previous['merchant_id']!==$merchantId||(int)$previous['campaign_id']!==$campaignId)throw new RuntimeException('Journey node not found.');
        $previousTemplate=(array)($previous['template']??[]);
    }
    $template=campaigns_rewards_journey_node_template_v121($input,$previousTemplate);
    $template=array_merge($template,campaigns_rewards_journey_optimization_settings_v122($input,$previousTemplate));
    if($previous){
        foreach(['journey_key','step_key','variant_key'] as $locked)if(!empty($previousTemplate[$locked]))$template[$locked]=(string)$previousTemplate[$locked];
    }

    $journeyKey=(string)$template['journey_key'];$journey=campaigns_rewards_ensure_journey_v123($pdo,$campaignId,$journeyKey,$actorUserId,(string)($input['journey_name']??''));
    if((string)$journey['status']==='archived')throw new RuntimeException('Archived journeys cannot be edited.');
    $draft=campaigns_rewards_ensure_draft_version_v123($pdo,$journey,$actorUserId);

    $nodeType=(string)$template['node_type'];$channel=$nodeType==='message'?(string)($input['channel']??$previous['channel']??'email'):'orchestration';
    if($nodeType==='message'&&!isset(campaigns_rewards_message_channels_v120()[$channel]))throw new RuntimeException('Choose a supported message channel.');
    $subject=campaigns_rewards_text_v100($input['subject']??$previous['subject']??'',255);
    $body=trim((string)($input['body']??$previous['body']??''));
    if($nodeType==='message'&&$body==='')throw new RuntimeException('Message body is required.');
    if(mb_strlen($body)>20000)throw new RuntimeException('Journey node body is too long.');
    if($nodeType==='decision'&&$template['true_next_step_key']===''&&$template['false_next_step_key']==='')throw new RuntimeException('Decision nodes require branch targets.');
    if($nodeType==='wait_until'&&$template['next_step_key']==='')throw new RuntimeException('Wait-until nodes require a next step.');

    $messageKey=$previous?(string)$previous['message_key']:campaigns_rewards_message_key_v121($journeyKey,(string)$template['step_key'],(string)$template['variant_key']);
    if(!$previous){
        foreach(campaigns_rewards_graph_nodes_v123($draft['graph']) as $node)if((string)$node['message_key']===$messageKey)throw new RuntimeException('That journey step and variant already exists in this draft.');
    }
    $q=$pdo->prepare("SELECT COALESCE(MAX(version_no),0) FROM campaign_messages WHERE campaign_id=? AND message_key=?");$q->execute([$campaignId,$messageKey]);$messageVersion=(int)$q->fetchColumn()+1;

    $pdo->prepare("INSERT INTO campaign_messages
      (campaign_id,message_key,channel,subject,body,template_json,status,version_no,created_at,updated_at)
      VALUES (?,?,?,?,?,?,'draft',?,UTC_TIMESTAMP(),UTC_TIMESTAMP())")
      ->execute([$campaignId,$messageKey,$channel,$subject,$body,campaigns_rewards_json_v100($template),$messageVersion]);
    $newId=(int)$pdo->lastInsertId();
    if($previous&&(string)$previous['status']==='draft')$pdo->prepare("UPDATE campaign_messages SET status='superseded',updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([(int)$previous['id']]);
    $newRow=campaigns_rewards_message_v120($pdo,$newId)?:throw new RuntimeException('Journey node unavailable after save.');

    $graph=$draft['graph'];$nodes=[];
    foreach(campaigns_rewards_graph_nodes_v123($graph) as $node)if((string)$node['message_key']!==$messageKey)$nodes[]=$node;
    $nodes[]=campaigns_rewards_graph_node_from_message_v123($newRow);
    usort($nodes,static fn(array $a,array $b):int=>[
        (int)($a['template']['step_order']??999),(string)($a['template']['step_key']??''),(string)($a['template']['variant_key']??'default')
    ]<=>[
        (int)($b['template']['step_order']??999),(string)($b['template']['step_key']??''),(string)($b['template']['variant_key']??'default')
    ]);
    $graph['schema']='campaign-journey-graph-v1';$graph['journey_key']=$journeyKey;$graph['nodes']=$nodes;$graph['updated_at']=gmdate('c');
    campaigns_rewards_update_draft_graph_v123($pdo,(int)$draft['id'],$graph);

    campaigns_rewards_activity_event_v100($pdo,$merchantId,'campaign.journey_draft_changed',['campaign_id'=>$campaignId],[
        'summary'=>'Campaign journey draft node saved','campaign_public_id'=>$campaign['public_id'],'journey_id'=>(int)$journey['id'],
        'journey_version_id'=>(int)$draft['id'],'message_id'=>$newId,'journey_key'=>$journeyKey,'step_key'=>$template['step_key'],'variant_key'=>$template['variant_key'],
    ],(string)$campaign['environment'],$actorUserId);
    return $newRow+['journey_id'=>(int)$journey['id'],'journey_version_id'=>(int)$draft['id']];
}

function campaigns_rewards_remove_draft_node_v123(PDO $pdo,int $journeyId,int $messageId,int $actorUserId): array
{
    $journey=campaigns_rewards_journey_v123($pdo,$journeyId)?:throw new RuntimeException('Journey not found.');
    campaigns_rewards_platform_assert_can_v100($pdo,(int)$journey['merchant_id'],$actorUserId,'campaigns.edit');
    $draft=campaigns_rewards_journey_version_v123($pdo,(int)$journey['current_draft_version_id'])?:throw new RuntimeException('Create a draft before removing a node.');
    if((string)$draft['status']!=='draft')throw new RuntimeException('The scheduled/published version is immutable.');
    $nodes=[];$found=false;
    foreach(campaigns_rewards_graph_nodes_v123($draft['graph']) as $node){
        if((int)$node['message_id']===$messageId){$found=true;continue;}$nodes[]=$node;
    }
    if(!$found)throw new RuntimeException('That node is not in the current draft.');
    $graph=$draft['graph'];$graph['nodes']=$nodes;$graph['updated_at']=gmdate('c');
    $pdo->prepare("UPDATE campaign_messages SET status='superseded',updated_at=UTC_TIMESTAMP() WHERE id=? AND status='draft'")->execute([$messageId]);
    return campaigns_rewards_update_draft_graph_v123($pdo,(int)$draft['id'],$graph);
}

function campaigns_rewards_provider_readiness_v123(array $graph): array
{
    $channels=[];foreach(campaigns_rewards_graph_nodes_v123($graph) as $node)if(($node['template']['node_type']??'message')==='message')$channels[(string)$node['channel']]=true;
    $checks=[];$ready=true;
    if(isset($channels['email'])){
        $provider=strtolower((string)site_config('campaign_email_provider','php_mail'));
        if($provider==='sendgrid')$ok=trim((string)site_config('campaign_sendgrid_api_key',''))!==''&&filter_var((string)site_config('campaign_sendgrid_from_email',''),FILTER_VALIDATE_EMAIL);
        else $ok=(bool)site_config('send_campaign_email',false)&&filter_var((string)site_config('campaign_email_from',''),FILTER_VALIDATE_EMAIL);
        $checks['email']=['provider'=>$provider,'ready'=>(bool)$ok];$ready=$ready&&(bool)$ok;
    }
    if(isset($channels['sms'])){
        $provider=strtolower((string)site_config('campaign_sms_provider',''));$ok=$provider==='twilio'
          &&trim((string)site_config('campaign_twilio_account_sid',''))!==''&&trim((string)site_config('campaign_twilio_auth_token',''))!==''
          &&(trim((string)site_config('campaign_twilio_from_number',''))!==''||trim((string)site_config('campaign_twilio_messaging_service_sid',''))!=='');
        $checks['sms']=['provider'=>$provider,'ready'=>$ok];$ready=$ready&&$ok;
    }
    if(isset($channels['agent']))$checks['agent']=['provider'=>'vp3_notification','ready'=>true];
    return ['ready'=>$ready,'channels'=>$checks];
}

function campaigns_rewards_validate_graph_v123(PDO $pdo,int $journeyId,array $graph,bool $providerChecks=true): array
{
    $errors=[];$warnings=[];$nodes=campaigns_rewards_graph_nodes_v123($graph);$groups=campaigns_rewards_graph_groups_v123($graph);
    if(!$nodes)$errors[]=['code'=>'empty_graph','message'=>'Journey has no nodes.'];
    $entryByTrigger=[];$adj=[];$exitSteps=[];$messageKeys=[];
    foreach($nodes as $node){
        $t=(array)$node['template'];$step=(string)($t['step_key']??'');$variant=(string)($t['variant_key']??'default');$type=(string)($t['node_type']??'message');$trigger=(string)($t['trigger_event']??'manual');
        if($step==='')$errors[]=['code'=>'missing_step_key','message'=>'A node is missing its step key.'];
        $mk=(string)$node['message_key'];if(isset($messageKeys[$mk]))$errors[]=['code'=>'duplicate_message_key','message'=>"Duplicate logical variant {$mk}."];$messageKeys[$mk]=true;
        if(!isset(campaigns_rewards_journey_node_types_v121()[$type]))$errors[]=['code'=>'invalid_node_type','message'=>"{$step} has an invalid node type."];
        if(!empty($t['entry_node']))$entryByTrigger[$trigger][$step]=true;
        if($type==='exit')$exitSteps[$step]=true;
        $targets=[];
        if($type==='decision'){
            $true=(string)($t['true_next_step_key']??'');$false=(string)($t['false_next_step_key']??'');
            if($true===''||$false==='')$errors[]=['code'=>'decision_branch_missing','message'=>"Decision {$step} needs both true and false targets."];
            if($true!=='')$targets[]=$true;if($false!=='')$targets[]=$false;
        }elseif($type!=='exit'){
            $next=(string)($t['next_step_key']??'');
            if($type==='wait_until'&&$next==='')$errors[]=['code'=>'wait_target_missing','message'=>"Wait node {$step} needs a next step."];
            if($next!=='')$targets[]=$next;
        }
        $adj[$step]=array_values(array_unique(array_merge($adj[$step]??[],$targets)));
    }
    foreach($groups as $step=>$variants){
        $types=array_values(array_unique(array_map(static fn($v)=>(string)($v['template']['node_type']??'message'),$variants)));
        if(count($types)>1)$errors[]=['code'=>'variant_type_mismatch','message'=>"Variants at {$step} must use the same node type."];
        if(count($variants)>1){
            $weight=array_sum(array_map(static fn($v)=>max(1,(int)($v['template']['variant_weight']??100)),$variants));
            if($weight!==100)$errors[]=['code'=>'variant_weight_total','message'=>"A/B weights at {$step} total {$weight}; published variants must total 100."];
            $routes=[];
            foreach($variants as $v){$t=$v['template'];$routes[]=implode('|',[(string)($t['next_step_key']??''),(string)($t['true_next_step_key']??''),(string)($t['false_next_step_key']??'')]);}
            if(count(array_unique($routes))>1)$errors[]=['code'=>'variant_route_mismatch','message'=>"A/B variants at {$step} must converge on the same route."];
        }elseif((int)($variants[0]['template']['variant_weight']??100)!==100)$warnings[]=['code'=>'single_variant_weight','message'=>"Single variant {$step} is not weighted 100."];
    }
    foreach($adj as $from=>$targets)foreach($targets as $target)if(!isset($groups[$target]))$errors[]=['code'=>'missing_target','message'=>"{$from} points to missing step {$target}."];

    $triggers=[];
    foreach($nodes as $node)$triggers[(string)($node['template']['trigger_event']??'manual')]=true;
    foreach(array_keys($triggers) as $trigger){
        $entries=array_keys($entryByTrigger[$trigger]??[]);
        if(count($entries)!==1)$errors[]=['code'=>'entry_count','message'=>"Trigger {$trigger} must have exactly one logical entry step; found ".count($entries).'.'];
    }
    if(!$exitSteps)$errors[]=['code'=>'no_explicit_exit','message'=>'Journey requires at least one explicit Exit node before publishing.'];

    $state=[];$cycle=false;
    $visit=function(string $step)use(&$visit,&$state,&$cycle,$adj):void{
        if(($state[$step]??0)===1){$cycle=true;return;}if(($state[$step]??0)===2)return;$state[$step]=1;
        foreach($adj[$step]??[] as $next)$visit($next);$state[$step]=2;
    };
    foreach(array_keys($groups) as $step)$visit($step);
    if($cycle)$errors[]=['code'=>'cycle_detected','message'=>'Journey contains a cycle. Add an explicit terminating path instead of an accidental loop.'];

    $reachable=[];$queue=[];
    foreach($entryByTrigger as $entries)foreach(array_keys($entries) as $step)$queue[]=$step;
    while($queue){$step=array_shift($queue);if(isset($reachable[$step]))continue;$reachable[$step]=true;foreach($adj[$step]??[] as $next)$queue[]=$next;}
    foreach(array_keys($groups) as $step)if(!isset($reachable[$step]))$errors[]=['code'=>'unreachable_node','message'=>"Step {$step} is unreachable from every entry."];

    if($exitSteps){
        $reverse=[];foreach($adj as $from=>$targets)foreach($targets as $to)$reverse[$to][]=$from;
        $canExit=[];$queue=array_keys($exitSteps);
        while($queue){$step=array_shift($queue);if(isset($canExit[$step]))continue;$canExit[$step]=true;foreach($reverse[$step]??[] as $prev)$queue[]=$prev;}
        foreach(array_keys($groups) as $step)if(!isset($canExit[$step]))$errors[]=['code'=>'no_exit_path','message'=>"Step {$step} cannot reach an Exit node."];
    }
    $provider=$providerChecks?campaigns_rewards_provider_readiness_v123($graph):['ready'=>true,'channels'=>[]];
    if($providerChecks&&!$provider['ready'])$warnings[]=['code'=>'provider_not_ready','message'=>'One or more delivery providers are not configured for this journey.'];
    return ['valid'=>!$errors,'errors'=>$errors,'warnings'=>$warnings,'provider'=>$provider,'node_count'=>count($nodes),'step_count'=>count($groups),'checked_at'=>gmdate('c')];
}

function campaigns_rewards_journey_diff_v123(?array $published,?array $draft): array
{
    $live=[];$next=[];
    foreach(campaigns_rewards_graph_nodes_v123($published['graph']??[]) as $node)$live[(string)$node['message_key']]=$node;
    foreach(campaigns_rewards_graph_nodes_v123($draft['graph']??[]) as $node)$next[(string)$node['message_key']]=$node;
    $added=[];$removed=[];$changed=[];$unchanged=[];
    foreach($next as $key=>$node){
        if(!isset($live[$key]))$added[]=$key;
        elseif((int)$live[$key]['message_id']!==(int)$node['message_id'])$changed[]=$key;else $unchanged[]=$key;
    }
    foreach($live as $key=>$node)if(!isset($next[$key]))$removed[]=$key;
    return ['added'=>$added,'removed'=>$removed,'changed'=>$changed,'unchanged'=>$unchanged,'has_changes'=>boolval($added||$removed||$changed)];
}

function campaigns_rewards_journey_release_suite_v123(PDO $pdo,int $journeyId,int $actorUserId,array $sampleContactIds=[]): array
{
    $journey=campaigns_rewards_journey_v123($pdo,$journeyId)?:throw new RuntimeException('Journey not found.');
    campaigns_rewards_platform_assert_can_v100($pdo,(int)$journey['merchant_id'],$actorUserId,'campaigns.view');
    $draft=campaigns_rewards_journey_version_v123($pdo,(int)$journey['current_draft_version_id'])?:throw new RuntimeException('Journey has no draft release.');
    $validation=campaigns_rewards_validate_graph_v123($pdo,$journeyId,$draft['graph'],true);
    $samples=[];$sampleContactIds=array_values(array_unique(array_filter(array_map('intval',$sampleContactIds))));
    foreach(array_slice($sampleContactIds,0,5) as $contactId){
        try{$samples[]=campaigns_rewards_simulate_version_v123($pdo,$draft,$contactId,[],false);}
        catch(Throwable $e){$samples[]=['contact_id'=>$contactId,'ok'=>false,'error'=>$e->getMessage()];}
    }
    $result=['version_id'=>(int)$draft['id'],'version_no'=>(int)$draft['version_no'],'validation'=>$validation,'sample_simulations'=>$samples,'passed'=>$validation['valid'],'ran_at'=>gmdate('c')];
    $pdo->prepare("UPDATE campaign_journey_versions SET validation_json=?,updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([campaigns_rewards_json_v100($result),(int)$draft['id']]);
    campaigns_rewards_activity_event_v100($pdo,(int)$journey['merchant_id'],'campaign.journey_release_validated',['campaign_id'=>(int)$journey['campaign_id']],[
        'summary'=>'Campaign journey release validation suite completed','campaign_public_id'=>$journey['campaign_public_id'],'journey_id'=>$journeyId,'journey_version_id'=>(int)$draft['id'],
        'passed'=>$validation['valid'],'errors'=>count($validation['errors']),'warnings'=>count($validation['warnings']),
    ],(string)$journey['environment'],$actorUserId);
    return $result;
}

function campaigns_rewards_inflight_plan_v123(PDO $pdo,array $journey,?array $previous,array $target,string $policy): array
{
    if(!in_array($policy,['continue','migrate_pending','exit_remaining'],true))throw new RuntimeException('Choose a valid in-flight policy.');
    if($policy==='continue'||!$previous)return ['policy'=>$policy,'actions'=>[],'affected'=>0];
    $q=$pdo->prepare("SELECT d.id,d.contact_id,d.message_id,d.channel,d.metadata_json FROM campaign_deliveries d
      WHERE d.campaign_id=? AND d.status IN ('pending','retry_wait') ORDER BY d.id");
    $q->execute([(int)$journey['campaign_id']]);$actions=[];$groups=campaigns_rewards_graph_groups_v123($target['graph']);
    foreach($q->fetchAll()?:[] as $row){
        $meta=json_decode((string)($row['metadata_json']??''),true);if(!is_array($meta)||($meta['runtime']??'')!=='v1.23'||(int)($meta['journey_id']??0)!==(int)$journey['id']||(int)($meta['journey_version_id']??0)!==(int)$previous['id'])continue;
        if($policy==='exit_remaining'){$actions[]=['kind'=>'exit','delivery_id'=>(int)$row['id'],'metadata'=>$meta];continue;}
        $step=(string)($meta['step_key']??'');$variants=$groups[$step]??[];
        if(!$variants)throw new RuntimeException("Cannot migrate in-flight step {$step}; it does not exist in the target release.");
        $instance=(string)($meta['journey_instance_key']??'');$node=campaigns_rewards_select_variant_v121(array_map('campaigns_rewards_graph_hydrate_node_v123',$variants),(int)$journey['campaign_id'],(int)$row['contact_id'],$instance,$step);
        if(!$node)throw new RuntimeException("Cannot select a target variant for in-flight step {$step}.");
        $meta['journey_version_id']=(int)$target['id'];$meta['journey_version_no']=(int)$target['version_no'];$meta['variant_key']=(string)($node['template']['variant_key']??'default');
        $meta['migrated_from_journey_version_id']=(int)$previous['id'];$meta['migrated_at']=gmdate('Y-m-d H:i:s');
        $actions[]=['kind'=>'migrate','delivery_id'=>(int)$row['id'],'message_id'=>(int)$node['id'],'channel'=>(string)$node['channel'],'metadata'=>$meta];
    }
    return ['policy'=>$policy,'actions'=>$actions,'affected'=>count($actions)];
}

function campaigns_rewards_apply_inflight_plan_v123(PDO $pdo,array $plan): void
{
    foreach($plan['actions']??[] as $action){
        if($action['kind']==='exit'){
            $meta=$action['metadata'];$meta['release_exit_reason']='publisher_exit_remaining';$meta['completed_at']=gmdate('Y-m-d H:i:s');
            $pdo->prepare("UPDATE campaign_deliveries SET status='suppressed',metadata_json=? WHERE id=? AND status IN ('pending','retry_wait')")
              ->execute([campaigns_rewards_json_v100($meta),(int)$action['delivery_id']]);
        }elseif($action['kind']==='migrate'){
            $pdo->prepare("UPDATE campaign_deliveries SET message_id=?,channel=?,metadata_json=? WHERE id=? AND status IN ('pending','retry_wait')")
              ->execute([(int)$action['message_id'],(string)$action['channel'],campaigns_rewards_json_v100($action['metadata']),(int)$action['delivery_id']]);
        }
    }
}

function campaigns_rewards_perform_publish_v123(PDO $pdo,int $versionId,int $actorUserId,string $inflightPolicy='continue',string $action='publish',string $releaseNotes=''): array
{
    $version=campaigns_rewards_journey_version_v123($pdo,$versionId)?:throw new RuntimeException('Journey release not found.');
    $journey=campaigns_rewards_journey_v123($pdo,(int)$version['journey_id'])?:throw new RuntimeException('Journey not found.');
    campaigns_rewards_platform_assert_can_v100($pdo,(int)$journey['merchant_id'],$actorUserId,'campaigns.publish');
    if((string)$journey['status']==='archived')throw new RuntimeException('Archived journeys cannot publish releases.');
    if((string)$journey['merchant_status']!=='active'||(string)$journey['campaign_status']!=='active'||(int)$journey['campaign_version_no']<1)throw new RuntimeException('Campaign and Merchant must be active and the Campaign published before a journey release can go live.');
    if(!in_array((string)$version['status'],['draft','scheduled'],true))throw new RuntimeException('Only draft or scheduled releases can be published.');

    $validation=campaigns_rewards_validate_graph_v123($pdo,(int)$journey['id'],$version['graph'],true);
    if(!$validation['valid'])throw new RuntimeException('Journey release validation failed: '.implode(' ',array_slice(array_column($validation['errors'],'message'),0,4)));
    $previous=null;$previousId=max(0,(int)$journey['current_published_version_id']);if($previousId>0)$previous=campaigns_rewards_journey_version_v123($pdo,$previousId);
    $plan=campaigns_rewards_inflight_plan_v123($pdo,$journey,$previous,$version,$inflightPolicy);
    $releaseNotes=trim($releaseNotes)!==''?trim($releaseNotes):(string)($version['release_notes']??'');

    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        if($previous&&$previousId!==$versionId)$pdo->prepare("UPDATE campaign_journey_versions SET status='superseded',updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([$previousId]);
        $pdo->prepare("UPDATE campaign_journey_versions SET status='published',validation_json=?,release_notes=?,scheduled_publish_at=NULL,published_by_user_id=?,published_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=?")
          ->execute([campaigns_rewards_json_v100($validation),$releaseNotes,$actorUserId,$versionId]);
        $ids=array_values(array_unique(array_map(static fn($node)=>(int)$node['message_id'],campaigns_rewards_graph_nodes_v123($version['graph']))));
        if($ids){
            $placeholders=implode(',',array_fill(0,count($ids),'?'));
            $pdo->prepare("UPDATE campaign_messages SET status='active',updated_at=UTC_TIMESTAMP() WHERE id IN ({$placeholders})")->execute($ids);
        }
        $pdo->prepare("UPDATE campaign_journeys SET status='active',current_published_version_id=?,current_draft_version_id=IF(current_draft_version_id=?,NULL,current_draft_version_id),updated_at=UTC_TIMESTAMP() WHERE id=?")
          ->execute([$versionId,$versionId,(int)$journey['id']]);
        campaigns_rewards_apply_inflight_plan_v123($pdo,$plan);
        $pdo->prepare("INSERT INTO campaign_journey_publications
          (public_id,journey_id,journey_version_id,previous_journey_version_id,action,inflight_policy,release_notes,metadata_json,actor_user_id,published_at)
          VALUES (?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())")
          ->execute([campaigns_rewards_uuid_v100(),(int)$journey['id'],$versionId,$previousId?:null,$action,$inflightPolicy,$releaseNotes,campaigns_rewards_json_v100(['validation'=>$validation,'inflight_affected'=>$plan['affected']]),$actorUserId]);
        if($owns)$pdo->commit();
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}

    campaigns_rewards_activity_event_v100($pdo,(int)$journey['merchant_id'],'campaign.journey_published',['campaign_id'=>(int)$journey['campaign_id']],[
        'summary'=>'Campaign journey release published atomically','campaign_public_id'=>$journey['campaign_public_id'],'journey_id'=>(int)$journey['id'],
        'journey_version_id'=>$versionId,'version_no'=>(int)$version['version_no'],'action'=>$action,'inflight_policy'=>$inflightPolicy,'inflight_affected'=>$plan['affected'],
        'validation_warnings'=>count($validation['warnings']),
    ],(string)$journey['environment'],$actorUserId);
    return campaigns_rewards_journey_version_v123($pdo,$versionId)?:$version;
}

function campaigns_rewards_schedule_datetime_v123(PDO $pdo,int $merchantId,string $value): ?string
{
    $value=trim($value);if($value==='')return null;
    $q=$pdo->prepare("SELECT timezone FROM merchant_accounts WHERE id=? LIMIT 1");$q->execute([$merchantId]);
    $timezone=campaigns_rewards_valid_timezone_v121((string)($q->fetchColumn()?:'UTC'));
    $tz=new DateTimeZone($timezone);
    $dt=DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i',$value,$tz);
    $errors=DateTimeImmutable::getLastErrors();
    if(!$dt||($errors!==false&&(($errors['warning_count']??0)>0||($errors['error_count']??0)>0)))throw new RuntimeException('Enter a valid scheduled publish date and time.');
    return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function campaigns_rewards_publish_journey_v123(PDO $pdo,int $journeyId,int $actorUserId,array $input=[]): array
{
    $journey=campaigns_rewards_journey_v123($pdo,$journeyId)?:throw new RuntimeException('Journey not found.');
    campaigns_rewards_platform_assert_can_v100($pdo,(int)$journey['merchant_id'],$actorUserId,'campaigns.publish');
    $draft=campaigns_rewards_journey_version_v123($pdo,(int)$journey['current_draft_version_id'])?:throw new RuntimeException('Journey has no draft to publish.');
    if((string)$draft['status']!=='draft')throw new RuntimeException('Only a draft can be published.');
    $suite=campaigns_rewards_journey_release_suite_v123($pdo,$journeyId,$actorUserId,[]);
    if(empty($suite['passed']))throw new RuntimeException('Journey release validation failed. Resolve the release errors before publishing.');
    $policy=(string)($input['inflight_policy']??'continue');if(!in_array($policy,['continue','migrate_pending','exit_remaining'],true))$policy='continue';
    $notes=trim((string)($input['release_notes']??''));$scheduled=campaigns_rewards_schedule_datetime_v123($pdo,(int)$journey['merchant_id'],(string)($input['scheduled_publish_at']??''));
    if($scheduled!==null&&strtotime($scheduled)>time()+60){
        $pdo->prepare("UPDATE campaign_journey_versions SET status='cancelled',updated_at=UTC_TIMESTAMP() WHERE journey_id=? AND status='scheduled'")->execute([$journeyId]);
        $pdo->prepare("UPDATE campaign_journey_versions SET status='scheduled',validation_json=?,release_notes=?,scheduled_publish_at=?,published_by_user_id=?,updated_at=UTC_TIMESTAMP() WHERE id=?")
          ->execute([campaigns_rewards_json_v100($suite),$notes,$scheduled,$actorUserId,(int)$draft['id']]);
        $pdo->prepare("UPDATE campaign_journeys SET current_draft_version_id=NULL,updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([$journeyId]);
        $pdo->prepare("INSERT INTO campaign_journey_publications
          (public_id,journey_id,journey_version_id,previous_journey_version_id,action,inflight_policy,release_notes,metadata_json,actor_user_id,published_at)
          VALUES (?,?,?,?, 'schedule',?,?,?, ?,UTC_TIMESTAMP())")
          ->execute([campaigns_rewards_uuid_v100(),$journeyId,(int)$draft['id'],max(0,(int)$journey['current_published_version_id'])?:null,$policy,$notes,campaigns_rewards_json_v100(['scheduled_publish_at'=>$scheduled]),$actorUserId]);
        campaigns_rewards_activity_event_v100($pdo,(int)$journey['merchant_id'],'campaign.journey_publish_scheduled',['campaign_id'=>(int)$journey['campaign_id']],[
            'summary'=>'Campaign journey publication scheduled','campaign_public_id'=>$journey['campaign_public_id'],'journey_id'=>$journeyId,
            'journey_version_id'=>(int)$draft['id'],'version_no'=>(int)$draft['version_no'],'scheduled_publish_at'=>$scheduled,'inflight_policy'=>$policy,
        ],(string)$journey['environment'],$actorUserId);
        return campaigns_rewards_journey_version_v123($pdo,(int)$draft['id'])?:$draft;
    }
    return campaigns_rewards_perform_publish_v123($pdo,(int)$draft['id'],$actorUserId,$policy,'publish',$notes);
}

function campaigns_rewards_publish_due_v123(PDO $pdo,int $merchantId=0,int $limit=50): array
{
    $limit=max(1,min(200,$limit));$sql="SELECT v.id,v.published_by_user_id FROM campaign_journey_versions v
      INNER JOIN campaign_journeys j ON j.id=v.journey_id INNER JOIN campaigns c ON c.id=j.campaign_id
      WHERE v.status='scheduled' AND v.scheduled_publish_at IS NOT NULL AND v.scheduled_publish_at<=UTC_TIMESTAMP()";
    $params=[];if($merchantId>0){$sql.=" AND c.merchant_id=?";$params[]=$merchantId;}$sql.=" ORDER BY v.scheduled_publish_at,v.id LIMIT {$limit}";
    $q=$pdo->prepare($sql);$q->execute($params);$summary=['due'=>0,'published'=>0,'stale_cancelled'=>0,'failed'=>0];
    foreach($q->fetchAll()?:[] as $row){
        $summary['due']++;$version=campaigns_rewards_journey_version_v123($pdo,(int)$row['id']);if(!$version){$summary['failed']++;continue;}
        $pub=$pdo->prepare("SELECT previous_journey_version_id,inflight_policy,release_notes FROM campaign_journey_publications WHERE journey_version_id=? AND action='schedule' ORDER BY id DESC LIMIT 1");$pub->execute([(int)$row['id']]);$scheduled=$pub->fetch()?:[];
        $journey=campaigns_rewards_journey_v123($pdo,(int)$version['journey_id']);
        $expectedPrevious=max(0,(int)($scheduled['previous_journey_version_id']??0));$currentPublished=max(0,(int)($journey['current_published_version_id']??0));
        if(!$journey||$expectedPrevious!==$currentPublished){
            $pdo->prepare("UPDATE campaign_journey_versions SET status='cancelled',updated_at=UTC_TIMESTAMP() WHERE id=? AND status='scheduled'")->execute([(int)$row['id']]);
            if($journey)campaigns_rewards_activity_event_v100($pdo,(int)$journey['merchant_id'],'campaign.journey_publish_cancelled',['campaign_id'=>(int)$journey['campaign_id']],[
                'summary'=>'Stale scheduled journey release cancelled because the live version changed','campaign_public_id'=>$journey['campaign_public_id'],
                'journey_id'=>(int)$journey['id'],'journey_version_id'=>(int)$row['id'],'expected_previous_version_id'=>$expectedPrevious,'current_published_version_id'=>$currentPublished,
            ],(string)$journey['environment'],null,'system');
            $summary['stale_cancelled']++;continue;
        }
        $actor=max(0,(int)($row['published_by_user_id']??0));
        if($actor<1){$summary['failed']++;error_log('Campaign journey scheduled publish missing authorizing user.');continue;}
        try{
            campaigns_rewards_perform_publish_v123($pdo,(int)$row['id'],$actor,(string)($scheduled['inflight_policy']??'continue'),'scheduled_publish',(string)($scheduled['release_notes']??''));
            $summary['published']++;
        }catch(Throwable $e){$summary['failed']++;error_log('Campaign journey scheduled publish failed: '.$e->getMessage());}
    }
    return $summary;
}

function campaigns_rewards_rollback_journey_v123(PDO $pdo,int $journeyId,int $targetVersionId,int $actorUserId,string $inflightPolicy='continue',string $notes=''): array
{
    $journey=campaigns_rewards_journey_v123($pdo,$journeyId)?:throw new RuntimeException('Journey not found.');
    campaigns_rewards_platform_assert_can_v100($pdo,(int)$journey['merchant_id'],$actorUserId,'campaigns.publish');
    $target=campaigns_rewards_journey_version_v123($pdo,$targetVersionId)?:throw new RuntimeException('Rollback version not found.');
    if((int)$target['journey_id']!==$journeyId||!in_array((string)$target['status'],['published','superseded'],true))throw new RuntimeException('Choose a previously published version of this journey.');
    $q=$pdo->prepare("SELECT COALESCE(MAX(version_no),0) FROM campaign_journey_versions WHERE journey_id=?");$q->execute([$journeyId]);$newNo=(int)$q->fetchColumn()+1;
    $releaseNotes=trim($notes)!==''?trim($notes):'Rollback to v'.(int)$target['version_no'];
    $pdo->prepare("INSERT INTO campaign_journey_versions
      (public_id,journey_id,version_no,status,graph_json,validation_json,release_notes,based_on_version_id,created_by_user_id,created_at,updated_at)
      VALUES (?,?,?,'draft',?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())")
      ->execute([campaigns_rewards_uuid_v100(),$journeyId,$newNo,campaigns_rewards_json_v100($target['graph']),'{}',$releaseNotes,$targetVersionId,$actorUserId]);
    $newId=(int)$pdo->lastInsertId();$pdo->prepare("UPDATE campaign_journeys SET current_draft_version_id=? WHERE id=?")->execute([$newId,$journeyId]);
    return campaigns_rewards_perform_publish_v123($pdo,$newId,$actorUserId,$inflightPolicy,'rollback',$releaseNotes);
}

function campaigns_rewards_set_journey_enrollment_v123(PDO $pdo,int $journeyId,int $actorUserId,string $status): array
{
    if(!in_array($status,['open','paused'],true))throw new RuntimeException('Choose open or paused enrollment.');
    $journey=campaigns_rewards_journey_v123($pdo,$journeyId)?:throw new RuntimeException('Journey not found.');
    campaigns_rewards_platform_assert_can_v100($pdo,(int)$journey['merchant_id'],$actorUserId,'campaigns.publish');
    $pdo->prepare("UPDATE campaign_journeys SET enrollment_status=?,updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([$status,$journeyId]);
    campaigns_rewards_activity_event_v100($pdo,(int)$journey['merchant_id'],'campaign.journey_enrollment_changed',['campaign_id'=>(int)$journey['campaign_id']],[
        'summary'=>'Campaign journey enrollment state changed','campaign_public_id'=>$journey['campaign_public_id'],'journey_id'=>$journeyId,'enrollment_status'=>$status
    ],(string)$journey['environment'],$actorUserId);
    return campaigns_rewards_journey_v123($pdo,$journeyId)?:$journey;
}

function campaigns_rewards_archive_journey_v123(PDO $pdo,int $journeyId,int $actorUserId,string $inflightPolicy='continue'): array
{
    $journey=campaigns_rewards_journey_v123($pdo,$journeyId)?:throw new RuntimeException('Journey not found.');
    campaigns_rewards_platform_assert_can_v100($pdo,(int)$journey['merchant_id'],$actorUserId,'campaigns.archive');
    $published=max(0,(int)$journey['current_published_version_id'])?campaigns_rewards_journey_version_v123($pdo,(int)$journey['current_published_version_id']):null;
    if($published&&$inflightPolicy==='exit_remaining'){
        $plan=campaigns_rewards_inflight_plan_v123($pdo,$journey,$published,$published,'exit_remaining');campaigns_rewards_apply_inflight_plan_v123($pdo,$plan);
    }
    $pdo->prepare("UPDATE campaign_journey_versions SET status='cancelled',updated_at=UTC_TIMESTAMP() WHERE journey_id=? AND status='scheduled'")->execute([$journeyId]);
    $pdo->prepare("UPDATE campaign_journeys SET status='archived',enrollment_status='paused',archived_by_user_id=?,archived_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([$actorUserId,$journeyId]);
    campaigns_rewards_activity_event_v100($pdo,(int)$journey['merchant_id'],'campaign.journey_archived',['campaign_id'=>(int)$journey['campaign_id']],[
        'summary'=>'Campaign journey archived with history preserved','campaign_public_id'=>$journey['campaign_public_id'],'journey_id'=>$journeyId,'inflight_policy'=>$inflightPolicy
    ],(string)$journey['environment'],$actorUserId);
    return campaigns_rewards_journey_v123($pdo,$journeyId)?:$journey;
}

function campaigns_rewards_clone_journey_v123(PDO $pdo,int $journeyId,int $sourceVersionId,int $actorUserId,string $requestedKey=''): array
{
    $sourceJourney=campaigns_rewards_journey_v123($pdo,$journeyId)?:throw new RuntimeException('Journey not found.');
    campaigns_rewards_platform_assert_can_v100($pdo,(int)$sourceJourney['merchant_id'],$actorUserId,'campaigns.edit');
    $version=$sourceVersionId>0?campaigns_rewards_journey_version_v123($pdo,$sourceVersionId):null;
    if(!$version)$version=max(0,(int)$sourceJourney['current_published_version_id'])?campaigns_rewards_journey_version_v123($pdo,(int)$sourceJourney['current_published_version_id']):campaigns_rewards_journey_version_v123($pdo,(int)$sourceJourney['current_draft_version_id']);
    if(!$version|| (int)$version['journey_id']!==$journeyId)throw new RuntimeException('Choose a source journey version.');

    $base=campaigns_rewards_slug_v100($requestedKey!==''?$requestedKey:((string)$sourceJourney['journey_key'].'-copy'),70)?:'journey-copy';$key=$base;$n=1;
    while(campaigns_rewards_journey_by_key_v123($pdo,(int)$sourceJourney['campaign_id'],$key)){$n++;$key=substr($base,0,70-strlen((string)$n)-1).'-'.$n;}
    $newJourney=campaigns_rewards_ensure_journey_v123($pdo,(int)$sourceJourney['campaign_id'],$key,$actorUserId,(string)$sourceJourney['name'].' Copy');
    $newNodes=[];
    foreach(campaigns_rewards_graph_nodes_v123($version['graph']) as $node){
        $message=campaigns_rewards_message_v120($pdo,(int)$node['message_id']);if(!$message)continue;
        $t=(array)$node['template'];$t['journey_key']=$key;$messageKey=campaigns_rewards_message_key_v121($key,(string)$t['step_key'],(string)($t['variant_key']??'default'));
        $pdo->prepare("INSERT INTO campaign_messages
          (campaign_id,message_key,channel,subject,body,template_json,status,version_no,created_at,updated_at)
          VALUES (?,?,?,?,?,?,'draft',1,UTC_TIMESTAMP(),UTC_TIMESTAMP())")
          ->execute([(int)$sourceJourney['campaign_id'],$messageKey,(string)$message['channel'],(string)$message['subject'],(string)$message['body'],campaigns_rewards_json_v100($t)]);
        $newId=(int)$pdo->lastInsertId();$copy=campaigns_rewards_message_v120($pdo,$newId);if($copy)$newNodes[]=campaigns_rewards_graph_node_from_message_v123($copy);
    }
    $graph=['schema'=>'campaign-journey-graph-v1','journey_key'=>$key,'nodes'=>$newNodes,'cloned_from_version_id'=>(int)$version['id']];
    $pdo->prepare("INSERT INTO campaign_journey_versions
      (public_id,journey_id,version_no,status,graph_json,validation_json,release_notes,based_on_version_id,created_by_user_id,created_at,updated_at)
      VALUES (?,?,1,'draft',?,'{}','Cloned journey',NULL,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())")
      ->execute([campaigns_rewards_uuid_v100(),(int)$newJourney['id'],campaigns_rewards_json_v100($graph),$actorUserId]);
    $draftId=(int)$pdo->lastInsertId();$pdo->prepare("UPDATE campaign_journeys SET current_draft_version_id=? WHERE id=?")->execute([$draftId,(int)$newJourney['id']]);
    campaigns_rewards_activity_event_v100($pdo,(int)$sourceJourney['merchant_id'],'campaign.journey_cloned',['campaign_id'=>(int)$sourceJourney['campaign_id']],[
        'summary'=>'Campaign journey release cloned to a new draft','campaign_public_id'=>$sourceJourney['campaign_public_id'],'source_journey_id'=>$journeyId,
        'source_version_id'=>(int)$version['id'],'cloned_journey_id'=>(int)$newJourney['id'],'cloned_draft_version_id'=>$draftId
    ],(string)$sourceJourney['environment'],$actorUserId);
    return campaigns_rewards_journey_v123($pdo,(int)$newJourney['id'])?:$newJourney;
}

function campaigns_rewards_apply_journey_template_v123(PDO $pdo,int $merchantId,int $campaignId,int $actorUserId,string $templateKey,string $journeyKey=''): array
{
    campaigns_rewards_platform_assert_can_v100($pdo,$merchantId,$actorUserId,'campaigns.edit');
    $catalog=campaigns_rewards_journey_template_catalog_v122();$definition=$catalog[$templateKey]??null;if(!$definition)throw new RuntimeException('Choose a valid journey template.');
    $base=campaigns_rewards_slug_v100($journeyKey!==''?$journeyKey:$templateKey,70)?:$templateKey;$key=$base;$n=1;
    while(campaigns_rewards_journey_by_key_v123($pdo,$campaignId,$key)){$n++;$key=substr($base,0,70-strlen((string)$n)-1).'-'.$n;}
    $created=[];
    foreach((array)$definition['nodes'] as $node){
        $input=$node+['journey_key'=>$key,'journey_name'=>(string)$definition['name'],'trigger_event'=>(string)$definition['trigger'],
          'variant_key'=>'default','variant_weight'=>100,'respect_quiet_hours'=>1,'retry_max_attempts'=>3,'retry_backoff_minutes'=>5];
        $created[]=campaigns_rewards_node_save_v123($pdo,$merchantId,$campaignId,$actorUserId,$input,0);
    }
    $journey=campaigns_rewards_journey_by_key_v123($pdo,$campaignId,$key);
    return ['template'=>$templateKey,'journey_key'=>$key,'journey'=>$journey,'nodes'=>$created];
}

function campaigns_rewards_version_graph_entry_v123(array $version,string $trigger): array
{
    $groups=campaigns_rewards_graph_groups_v123($version['graph']);$entries=[];
    foreach($groups as $step=>$variants){
        foreach($variants as $node){
            $t=(array)$node['template'];if(!empty($t['entry_node'])&&(string)($t['trigger_event']??'manual')===$trigger){$entries[$step]=$variants;break;}
        }
    }
    return $entries;
}

function campaigns_rewards_enqueue_node_v123(PDO $pdo,array $campaign,array $journey,array $version,array $node,int $contactId,string $instanceKey,string $triggerEventId,array $context=[]): array
{
    $contact=campaigns_rewards_message_contact_v120($pdo,(int)$campaign['merchant_id'],$contactId);if(!$contact)return ['suppressed'=>true,'reason'=>'contact_unavailable'];
    $t=(array)$node['template'];$nodeType=(string)($t['node_type']??'message');
    if($nodeType==='message'){
        $consent=campaigns_rewards_message_consent_v120($contact,(string)$node['channel'],(string)($t['purpose']??'marketing'));
        if(empty($consent['allowed']))return ['suppressed'=>true,'reason'=>(string)$consent['reason']];
    }
    $safeContext=campaigns_rewards_journey_context_v121($context);
    $scheduled=gmdate('Y-m-d H:i:s',campaigns_rewards_node_schedule_v121($pdo,$campaign,$node,$contact,$safeContext));
    $idempotency='v123:'.hash('sha256',implode('|',[(int)$campaign['id'],$contactId,(int)$journey['id'],(int)$version['id'],$instanceKey,(string)$t['step_key'],(string)($t['variant_key']??'default'),(int)$node['id']]));
    $idem=campaigns_rewards_idempotency_begin_v100($pdo,(int)$campaign['merchant_id'],'journey.v123.node.enqueue',$idempotency,['journey_version_id'=>(int)$version['id'],'message_id'=>(int)$node['id'],'contact_id'=>$contactId]);
    if(empty($idem['new'])&&($idem['status']??'')==='completed'&&($idem['result_ref_type']??'')==='campaign_delivery'){
        $existing=campaigns_rewards_delivery_v120($pdo,(int)$idem['result_ref_id']);if($existing)return ['duplicate'=>true,'delivery'=>$existing];
    }
    $meta=[
        'runtime'=>'v1.23','journey_id'=>(int)$journey['id'],'journey_version_id'=>(int)$version['id'],'journey_version_no'=>(int)$version['version_no'],
        'journey_instance_key'=>$instanceKey,'journey_key'=>(string)$journey['journey_key'],'step_key'=>(string)$t['step_key'],
        'variant_key'=>(string)($t['variant_key']??'default'),'variant_weight'=>(int)($t['variant_weight']??100),'node_type'=>$nodeType,
        'trigger_event'=>(string)($t['trigger_event']??'manual'),'trigger_event_id'=>$triggerEventId,'scheduled_for'=>$scheduled,
        'purpose'=>(string)($t['purpose']??'marketing'),'respect_quiet_hours'=>!empty($t['respect_quiet_hours']),
        'exit_on_conversion'=>!empty($t['exit_on_conversion']),'stop_on_claim'=>!empty($t['stop_on_claim']),'stop_on_expiration'=>!empty($t['stop_on_expiration']),
        'retry_max_attempts'=>(int)($t['retry_max_attempts']??3),'retry_backoff_minutes'=>(int)($t['retry_backoff_minutes']??5),
        'context'=>$safeContext,'attempts'=>0,'idempotency_key'=>$idempotency,
    ];
    $pdo->prepare("INSERT INTO campaign_deliveries
      (campaign_id,enrollment_id,contact_id,reward_issuance_id,message_id,channel,status,metadata_json,created_at)
      VALUES (?,?,?,?,?,?,'pending',?,UTC_TIMESTAMP())")->execute([
        (int)$campaign['id'],max(0,(int)($safeContext['enrollment_id']??0))?:null,$contactId,max(0,(int)($safeContext['reward_issuance_id']??0))?:null,
        (int)$node['id'],(string)$node['channel'],campaigns_rewards_json_v100($meta),
    ]);
    $deliveryId=(int)$pdo->lastInsertId();campaigns_rewards_idempotency_complete_v100($pdo,(int)$idem['id'],'campaign_delivery',$deliveryId);
    return ['duplicate'=>false,'delivery'=>campaigns_rewards_delivery_v120($pdo,$deliveryId)];
}

function campaigns_rewards_journey_enqueue_v123(PDO $pdo,int $campaignId,int $contactId,string $trigger,array $context=[],string $triggerEventId=''): array
{
    if(!isset(campaigns_rewards_journey_triggers_v120()[$trigger]))throw new RuntimeException('Unknown Campaign journey trigger.');
    $campaign=campaigns_rewards_campaign_platform_v100($pdo,$campaignId)?:throw new RuntimeException('Campaign not found.');
    if((string)$campaign['merchant_status']!=='active'||(string)$campaign['status']!=='active'||(string)$campaign['environment']!=='production'||(int)$campaign['current_version_no']<1)
        return ['trigger'=>$trigger,'journeys'=>0,'queued'=>0,'duplicate'=>0,'suppressed'=>1,'reason'=>'campaign_inactive'];

    $q=$pdo->prepare("SELECT * FROM campaign_journeys WHERE campaign_id=? AND status='active' AND enrollment_status='open' AND current_published_version_id IS NOT NULL ORDER BY id");
    $q->execute([$campaignId]);$journeys=$q->fetchAll()?:[];$matched=false;$summary=['trigger'=>$trigger,'journeys'=>0,'queued'=>0,'duplicate'=>0,'suppressed'=>0];
    $eventId=$triggerEventId!==''?$triggerEventId:$trigger.':'.$campaignId.':'.$contactId.':'.gmdate('YmdHi');
    foreach($journeys as $journey){
        $version=campaigns_rewards_journey_version_v123($pdo,(int)$journey['current_published_version_id']);if(!$version)continue;
        $entries=campaigns_rewards_version_graph_entry_v123($version,$trigger);if(!$entries)continue;$matched=true;$summary['journeys']++;
        $instance='v123:'.hash('sha256',$campaignId.'|'.$contactId.'|'.(int)$journey['id'].'|'.(int)$version['id'].'|'.$eventId);
        foreach($entries as $step=>$descriptors){
            $variants=array_map('campaigns_rewards_graph_hydrate_node_v123',$descriptors);
            $node=campaigns_rewards_select_variant_v121($variants,$campaignId,$contactId,$instance,$step);if(!$node)continue;
            $r=campaigns_rewards_enqueue_node_v123($pdo,$campaign,$journey,$version,$node,$contactId,$instance,$eventId,$context);
            if(!empty($r['suppressed']))$summary['suppressed']++;elseif(!empty($r['duplicate']))$summary['duplicate']++;else $summary['queued']++;
        }
        campaigns_rewards_activity_event_v100($pdo,(int)$campaign['merchant_id'],'campaign.journey_version_started',['campaign_id'=>$campaignId,'contact_id'=>$contactId],[
            'summary'=>'Contact entered pinned Campaign journey release','campaign_public_id'=>$campaign['public_id'],'journey_id'=>(int)$journey['id'],
            'journey_version_id'=>(int)$version['id'],'version_no'=>(int)$version['version_no'],'journey_instance_key'=>$instance,'trigger'=>$trigger,
        ],(string)$campaign['environment'],null,'automation');
    }
    if(!$matched)return campaigns_rewards_journey_enqueue_v121($pdo,$campaignId,$contactId,$trigger,$context,$triggerEventId);
    return $summary;
}

function campaigns_rewards_enqueue_next_step_v123(PDO $pdo,array $delivery,string $nextStep): ?array
{
    $nextStep=campaigns_rewards_slug_v100($nextStep,50);if($nextStep==='')return null;
    $version=campaigns_rewards_journey_version_v123($pdo,max(0,(int)($delivery['metadata']['journey_version_id']??0)));if(!$version)return null;
    $journey=campaigns_rewards_journey_v123($pdo,(int)$version['journey_id']);if(!$journey)return null;
    $groups=campaigns_rewards_graph_groups_v123($version['graph']);$descriptors=$groups[$nextStep]??[];if(!$descriptors)return null;
    $variants=array_map('campaigns_rewards_graph_hydrate_node_v123',$descriptors);$instance=(string)($delivery['metadata']['journey_instance_key']??'');
    $node=campaigns_rewards_select_variant_v121($variants,(int)$delivery['campaign_id'],(int)$delivery['contact_id'],$instance,$nextStep);if(!$node)return null;
    $campaign=campaigns_rewards_campaign_platform_v100($pdo,(int)$delivery['campaign_id']);if(!$campaign)return null;
    return campaigns_rewards_enqueue_node_v123($pdo,$campaign,$journey,$version,$node,(int)$delivery['contact_id'],$instance,(string)($delivery['metadata']['trigger_event_id']??''),(array)($delivery['metadata']['context']??[]));
}

function campaigns_rewards_dispatch_delivery_v123(PDO $pdo,int $deliveryId): array
{
    $delivery=campaigns_rewards_delivery_v120($pdo,$deliveryId)?:throw new RuntimeException('Campaign delivery not found.');
    if(($delivery['metadata']['runtime']??'')!=='v1.23')return campaigns_rewards_dispatch_delivery_v122($pdo,$deliveryId);
    if(!in_array((string)$delivery['status'],['pending','retry_wait'],true))return ['skipped'=>true,'reason'=>'not_dispatchable','delivery'=>$delivery];
    $scheduled=(string)($delivery['metadata']['scheduled_for']??$delivery['created_at']);
    if($scheduled!==''&&strtotime($scheduled)!==false&&strtotime($scheduled)>time())return ['skipped'=>true,'reason'=>'not_due','delivery'=>$delivery];
    $contact=campaigns_rewards_message_contact_v120($pdo,(int)$delivery['merchant_id'],(int)$delivery['contact_id']);
    if(!$contact)return ['skipped'=>false,'delivery'=>campaigns_rewards_mark_delivery_v120($pdo,$deliveryId,'suppressed',['reason'=>'contact_unavailable'])];

    $exit=campaigns_rewards_journey_exit_reason_v121($pdo,$delivery);
    if($exit!==null){
        $meta=$delivery['metadata'];$meta['exit_reason']=$exit;$meta['completed_at']=gmdate('Y-m-d H:i:s');
        $pdo->prepare("UPDATE campaign_deliveries SET status='suppressed',metadata_json=? WHERE id=?")->execute([campaigns_rewards_json_v100($meta),$deliveryId]);
        return ['skipped'=>false,'delivery'=>campaigns_rewards_delivery_v120($pdo,$deliveryId),'exit_reason'=>$exit];
    }

    $template=(array)$delivery['template'];$nodeType=(string)($delivery['metadata']['node_type']??$template['node_type']??'message');
    if($nodeType==='decision'){
        $actual=campaigns_rewards_condition_source_v121($pdo,$delivery,$contact,(string)($template['condition_field']??'contact.marketing_status'));
        $matched=campaigns_rewards_condition_compare_v121($actual,(string)($template['condition_operator']??'equals'),(string)($template['condition_value']??''));
        $next=$matched?(string)($template['true_next_step_key']??''):(string)($template['false_next_step_key']??'');
        $d=campaigns_rewards_complete_orchestration_node_v121($pdo,$delivery,$matched?'true':'false',['condition_actual'=>is_scalar($actual)?$actual:null,'selected_next_step'=>$next]);
        campaigns_rewards_enqueue_next_step_v123($pdo,$d,$next);return ['skipped'=>false,'delivery'=>$d,'branch'=>$matched];
    }
    if($nodeType==='wait_until'){
        $d=campaigns_rewards_complete_orchestration_node_v121($pdo,$delivery,'wait_complete');campaigns_rewards_enqueue_next_step_v123($pdo,$d,(string)($template['next_step_key']??''));return ['skipped'=>false,'delivery'=>$d];
    }
    if($nodeType==='exit'){
        $d=campaigns_rewards_complete_orchestration_node_v121($pdo,$delivery,'explicit_exit');return ['skipped'=>false,'delivery'=>$d];
    }

    $suppress=campaigns_rewards_delivery_suppression_v120($pdo,$delivery,$contact);
    if($suppress!==null)return ['skipped'=>false,'delivery'=>campaigns_rewards_mark_delivery_v120($pdo,$deliveryId,'suppressed',['reason'=>$suppress])];
    $gate=campaigns_rewards_frequency_gate_v122($pdo,$delivery,$template);
    if(empty($gate['allowed'])){
        $meta=$delivery['metadata'];$meta['scheduled_for']=(string)$gate['next_allowed_at'];$meta['frequency_deferred']=true;$meta['frequency_reason']=$gate['reason'];$meta['frequency_counts']=$gate['counts'];
        $pdo->prepare("UPDATE campaign_deliveries SET status='pending',metadata_json=? WHERE id=?")->execute([campaigns_rewards_json_v100($meta),$deliveryId]);
        return ['skipped'=>true,'reason'=>'frequency_control','delivery'=>campaigns_rewards_delivery_v120($pdo,$deliveryId)];
    }
    $optimized=campaigns_rewards_apply_send_time_optimization_v122($pdo,$delivery,$contact,$template);
    if($optimized&&!empty($optimized['deferred']))return ['skipped'=>true,'reason'=>'send_time_optimization','delivery'=>campaigns_rewards_delivery_v120($pdo,$deliveryId)];
    if(!empty($delivery['metadata']['respect_quiet_hours'])){
        $quiet=json_decode((string)($contact['quiet_hours_json']??''),true);if(!is_array($quiet))$quiet=[];
        $timezone=campaigns_rewards_contact_timezone_v121($pdo,(int)$delivery['merchant_id'],$contact);$allowed=campaigns_rewards_next_allowed_send_v121(time(),$timezone,$quiet);
        if($allowed>time()+30){
            $meta=$delivery['metadata'];$meta['scheduled_for']=gmdate('Y-m-d H:i:s',$allowed);$meta['quiet_hours_deferred']=true;
            $pdo->prepare("UPDATE campaign_deliveries SET status='pending',metadata_json=? WHERE id=?")->execute([campaigns_rewards_json_v100($meta),$deliveryId]);
            return ['skipped'=>true,'reason'=>'quiet_hours','delivery'=>campaigns_rewards_delivery_v120($pdo,$deliveryId)];
        }
    }
    $message=['id'=>(int)$delivery['message_id'],'message_key'=>$delivery['message_key'],'subject'=>$delivery['subject'],'body'=>$delivery['body'],'template'=>$template];
    $ctx=campaigns_rewards_message_context_v120($pdo,$delivery,$contact,$message);$subject=campaigns_rewards_render_message_v120((string)$delivery['subject'],$ctx['tokens']);$body=campaigns_rewards_render_message_v120((string)$delivery['body'],$ctx['tokens']);
    try{$result=campaigns_rewards_send_v121((string)$delivery['channel'],$ctx+['subject'=>$subject,'body'=>$body,'delivery_id'=>$deliveryId]);}
    catch(Throwable $e){$result=['status'=>'failed','reason'=>$e->getMessage(),'source_type'=>'adapter','retryable'=>true];}
    $status=(string)($result['status']??'failed');
    if(in_array($status,['sent','delivered','viewed'],true)){
        $d=campaigns_rewards_mark_delivery_v120($pdo,$deliveryId,$status,$result);campaigns_rewards_enqueue_next_step_v123($pdo,$d,(string)($template['next_step_key']??''));return ['skipped'=>false,'result'=>$result,'delivery'=>$d];
    }
    return ['skipped'=>false,'result'=>$result,'delivery'=>campaigns_rewards_retry_delivery_v121($pdo,$delivery,$result)];
}

function campaigns_rewards_dispatch_due_v123(PDO $pdo,int $merchantId=0,int $limit=200): array
{
    $limit=max(1,min(1000,$limit));$sql="SELECT d.id,d.metadata_json FROM campaign_deliveries d INNER JOIN campaigns c ON c.id=d.campaign_id WHERE d.status IN ('pending','retry_wait')";$params=[];
    if($merchantId>0){$sql.=" AND c.merchant_id=?";$params[]=$merchantId;}$sql.=" ORDER BY d.created_at,d.id LIMIT ".($limit*8);
    $q=$pdo->prepare($sql);$q->execute($params);$summary=['checked'=>0,'due'=>0,'sent'=>0,'delivered'=>0,'viewed'=>0,'retry_wait'=>0,'dead_letter'=>0,'suppressed'=>0,'failed'=>0,'frequency_deferred'=>0,'optimized_deferred'=>0,'skipped'=>0];
    foreach($q->fetchAll()?:[] as $row){
        if($summary['due']>=$limit)break;$summary['checked']++;$meta=json_decode((string)($row['metadata_json']??''),true)?:[];$scheduled=(string)($meta['scheduled_for']??'');
        if($scheduled!==''&&strtotime($scheduled)!==false&&strtotime($scheduled)>time()){$summary['skipped']++;continue;}$summary['due']++;
        try{$r=campaigns_rewards_dispatch_delivery_v123($pdo,(int)$row['id']);}catch(Throwable $e){$summary['failed']++;error_log('Campaign messaging V1.23 dispatch failed: '.$e->getMessage());continue;}
        if(!empty($r['skipped'])){if(($r['reason']??'')==='frequency_control')$summary['frequency_deferred']++;elseif(($r['reason']??'')==='send_time_optimization')$summary['optimized_deferred']++;else $summary['skipped']++;continue;}
        $status=(string)($r['delivery']['status']??'failed');if(isset($summary[$status]))$summary[$status]++;else $summary['failed']++;
    }
    return $summary;
}

function campaigns_rewards_queue_expiration_reminders_v123(PDO $pdo,int $merchantId=0,int $lookAheadDays=30,int $limit=500): array
{
    $lookAheadDays=max(1,min(365,$lookAheadDays));$limit=max(1,min(2000,$limit));
    $sql="SELECT ri.id reward_issuance_id,ri.campaign_id,ri.recipient_contact_id contact_id,ri.expires_at,ri.campaign_enrollment_id enrollment_id
      FROM reward_issuances ri INNER JOIN campaigns c ON c.id=ri.campaign_id INNER JOIN merchant_accounts m ON m.id=c.merchant_id
      WHERE ri.environment='production' AND ri.status IN ('issued','sent','viewed') AND ri.remaining_quantity>0
      AND ri.expires_at IS NOT NULL AND ri.expires_at>UTC_TIMESTAMP()
      AND ri.expires_at<=DATE_ADD(UTC_TIMESTAMP(),INTERVAL {$lookAheadDays} DAY) AND c.status='active' AND m.status='active'";
    $params=[];if($merchantId>0){$sql.=" AND c.merchant_id=?";$params[]=$merchantId;}$sql.=" ORDER BY ri.expires_at,ri.id LIMIT {$limit}";
    $q=$pdo->prepare($sql);$q->execute($params);$summary=['issuances'=>0,'queued'=>0,'duplicate'=>0,'suppressed'=>0];
    foreach($q->fetchAll()?:[] as $row){
        $summary['issuances']++;$r=campaigns_rewards_journey_enqueue_v123($pdo,(int)$row['campaign_id'],(int)$row['contact_id'],'reward_expiring',[
          'reward_issuance_id'=>(int)$row['reward_issuance_id'],'enrollment_id'=>(int)($row['enrollment_id']??0),'expires_at'=>(string)$row['expires_at']
        ],'reward-expiring:'.(int)$row['reward_issuance_id']);
        foreach(['queued','duplicate','suppressed'] as $k)$summary[$k]+=(int)($r[$k]??0);
    }
    return $summary;
}

function campaigns_rewards_simulate_version_v123(PDO $pdo,array $version,int $contactId,array $context=[],bool $emitEvent=true): array
{
    $journey=campaigns_rewards_journey_v123($pdo,(int)$version['journey_id'])?:throw new RuntimeException('Journey not found.');
    $contact=campaigns_rewards_message_contact_v120($pdo,(int)$journey['merchant_id'],$contactId)?:throw new RuntimeException('Choose a CRM contact available to this Merchant.');
    $groups=campaigns_rewards_graph_groups_v123($version['graph']);$entries=[];
    foreach($groups as $step=>$variants)foreach($variants as $node)if(!empty($node['template']['entry_node'])){$entries[$step]=$variants;break;}
    $results=[];$instance='simulation:'.hash('sha256',(int)$journey['campaign_id'].'|'.$contactId.'|'.(int)$version['id']);
    foreach($entries as $entry=>$entryVariants){
        $current=$entry;$visited=[];$path=[];
        for($i=0;$i<50&&$current!=='';$i++){
            if(isset($visited[$current])){$path[]=['step_key'=>$current,'outcome'=>'loop_detected'];break;}$visited[$current]=true;
            $descriptors=$groups[$current]??[];if(!$descriptors){$path[]=['step_key'=>$current,'outcome'=>'missing_target'];break;}
            $variants=array_map('campaigns_rewards_graph_hydrate_node_v123',$descriptors);$node=campaigns_rewards_select_variant_v121($variants,(int)$journey['campaign_id'],$contactId,$instance,$current);if(!$node)break;
            $t=$node['template'];$type=(string)($t['node_type']??'message');$record=['step_key'=>$current,'node_type'=>$type,'variant_key'=>(string)($t['variant_key']??'default')];
            if($type==='decision'){
                $fake=['metadata'=>['context'=>campaigns_rewards_journey_context_v121($context),'journey_instance_key'=>$instance],'merchant_id'=>(int)$journey['merchant_id'],'contact_id'=>$contactId,'campaign_id'=>(int)$journey['campaign_id'],'campaign_status'=>$journey['campaign_status'],'reward_issuance_id'=>max(0,(int)($context['reward_issuance_id']??0))];
                $actual=campaigns_rewards_condition_source_v121($pdo,$fake,$contact,(string)($t['condition_field']??'contact.marketing_status'));$matched=campaigns_rewards_condition_compare_v121($actual,(string)($t['condition_operator']??'equals'),(string)($t['condition_value']??''));
                $record['condition_result']=$matched;$record['condition_actual']=is_scalar($actual)?$actual:null;$current=$matched?(string)($t['true_next_step_key']??''):(string)($t['false_next_step_key']??'');
            }elseif($type==='exit'){$record['outcome']='exit';$current='';}
            else{
                if($type==='message'){$record['consent']=campaigns_rewards_message_consent_v120($contact,(string)$node['channel'],(string)($t['purpose']??'marketing'));$record['frequency_gate']=campaigns_rewards_frequency_gate_v122($pdo,['merchant_id'=>(int)$journey['merchant_id'],'contact_id'=>$contactId,'channel'=>$node['channel']],$t);}
                $campaign=campaigns_rewards_campaign_platform_v100($pdo,(int)$journey['campaign_id']);$record['scheduled_for']=$campaign?gmdate('c',campaigns_rewards_node_schedule_v121($pdo,$campaign,$node,$contact,$context)):null;$current=(string)($t['next_step_key']??'');
            }
            $path[]=$record;
        }
        $results[]=['entry_step'=>$entry,'path'=>$path];
    }
    if($emitEvent)campaigns_rewards_activity_event_v100($pdo,(int)$journey['merchant_id'],'campaign.journey_release_simulated',['campaign_id'=>(int)$journey['campaign_id'],'contact_id'=>$contactId],[
        'summary'=>'Pinned Campaign journey release simulated without delivery','campaign_public_id'=>$journey['campaign_public_id'],'journey_id'=>(int)$journey['id'],'journey_version_id'=>(int)$version['id'],'version_no'=>(int)$version['version_no'],
    ],(string)$journey['environment'],null,'user');
    return ['ok'=>true,'dry_run'=>true,'journey_id'=>(int)$journey['id'],'version_id'=>(int)$version['id'],'version_no'=>(int)$version['version_no'],'contact_id'=>$contactId,'paths'=>$results];
}

function campaigns_rewards_journey_release_health_v123(PDO $pdo,int $journeyId,int $actorUserId): array
{
    $journey=campaigns_rewards_journey_v123($pdo,$journeyId)?:throw new RuntimeException('Journey not found.');
    campaigns_rewards_platform_assert_can_v100($pdo,(int)$journey['merchant_id'],$actorUserId,'analytics.view');
    $versions=campaigns_rewards_journey_versions_v123($pdo,$journeyId);$stats=[];
    $q=$pdo->prepare("SELECT status,metadata_json FROM campaign_deliveries WHERE campaign_id=? ORDER BY id DESC LIMIT 5000");$q->execute([(int)$journey['campaign_id']]);
    foreach($q->fetchAll()?:[] as $row){
        $m=json_decode((string)($row['metadata_json']??''),true);if(!is_array($m)||(int)($m['journey_id']??0)!==$journeyId)continue;$vid=(int)($m['journey_version_id']??0);if($vid<1)continue;
        $stats[$vid]['total']=($stats[$vid]['total']??0)+1;$status=(string)$row['status'];$stats[$vid][$status]=($stats[$vid][$status]??0)+1;
        if(!empty($m['attributed_claim_id']))$stats[$vid]['converted']=($stats[$vid]['converted']??0)+1;
    }
    $scheduled=0;foreach($versions as $version)if($version['status']==='scheduled')$scheduled++;
    return ['journey'=>$journey,'versions'=>$versions,'delivery_stats'=>$stats,'scheduled_releases'=>$scheduled];
}

function campaigns_rewards_message_release_managed_v123(PDO $pdo,int $messageId): bool
{
    $message=campaigns_rewards_message_v120($pdo,$messageId);if(!$message)return false;
    $t=(array)($message['template']??[]);if(($t['kind']??'')!=='journey_node')return false;
    return (bool)campaigns_rewards_journey_by_key_v123($pdo,(int)$message['campaign_id'],(string)($t['journey_key']??''));
}

function campaigns_rewards_journey_publications_v123(PDO $pdo,int $journeyId): array
{
    $q=$pdo->prepare("SELECT p.*,v.version_no,u.display_name actor_name
      FROM campaign_journey_publications p
      INNER JOIN campaign_journey_versions v ON v.id=p.journey_version_id
      LEFT JOIN users u ON u.id=p.actor_user_id
      WHERE p.journey_id=? ORDER BY p.id DESC LIMIT 100");
    $q->execute([$journeyId]);$rows=$q->fetchAll()?:[];
    foreach($rows as &$row)$row['metadata']=json_decode((string)($row['metadata_json']??''),true)?:[];unset($row);
    return $rows;
}

function campaigns_rewards_simulate_journey_release_v123(PDO $pdo,int $journeyId,int $contactId,int $actorUserId,array $context=[]): array
{
    $journey=campaigns_rewards_journey_v123($pdo,$journeyId)?:throw new RuntimeException('Journey not found.');
    campaigns_rewards_platform_assert_can_v100($pdo,(int)$journey['merchant_id'],$actorUserId,'campaigns.view');
    $version=null;
    if((int)$journey['current_draft_version_id']>0)$version=campaigns_rewards_journey_version_v123($pdo,(int)$journey['current_draft_version_id']);
    if(!$version&&(int)$journey['current_published_version_id']>0)$version=campaigns_rewards_journey_version_v123($pdo,(int)$journey['current_published_version_id']);
    if(!$version)throw new RuntimeException('Journey has no draft or published release to simulate.');
    return campaigns_rewards_simulate_version_v123($pdo,$version,$contactId,$context,true);
}

function campaigns_rewards_journey_editor_snapshot_v123(PDO $pdo,int $journeyId): array
{
    $journey=campaigns_rewards_journey_v123($pdo,$journeyId)?:throw new RuntimeException('Journey not found.');
    $draft=(int)$journey['current_draft_version_id']>0?campaigns_rewards_journey_version_v123($pdo,(int)$journey['current_draft_version_id']):null;
    $published=(int)$journey['current_published_version_id']>0?campaigns_rewards_journey_version_v123($pdo,(int)$journey['current_published_version_id']):null;
    $display=$draft?:$published;
    return [
        'journey'=>$journey,'draft'=>$draft,'published'=>$published,'display'=>$display,
        'diff'=>campaigns_rewards_journey_diff_v123($published,$draft),
        'versions'=>campaigns_rewards_journey_versions_v123($pdo,$journeyId),
        'publications'=>campaigns_rewards_journey_publications_v123($pdo,$journeyId),
    ];
}

function campaigns_rewards_run_due_v123(PDO $pdo,int $merchantId=0): array
{
    $publishing=campaigns_rewards_publish_due_v123($pdo,$merchantId);
    $automation=function_exists('campaigns_rewards_automation_run_due_v119')?campaigns_rewards_automation_run_due_v119($pdo,$merchantId):[];
    $expiration=campaigns_rewards_queue_expiration_reminders_v123($pdo,$merchantId);
    $delivery=campaigns_rewards_dispatch_due_v123($pdo,$merchantId);
    $recommendations=campaigns_rewards_refresh_optimization_recommendations_v122($pdo,$merchantId);
    return ['scheduled_publishing'=>$publishing,'automation'=>$automation,'expiration_reminders'=>$expiration,'deliveries'=>$delivery,'optimization_recommendations'=>$recommendations];
}
