<?php
declare(strict_types=1);

require_once __DIR__.'/agent-capability-contract-v1.php';

/** Canonical identity is always checked; pausing work does not revoke a readonly saved-post review. */
function vp3_initiative_capability_scope_v1(PDO $pdo,array $scope,array $binding): array
{
    $user=$scope['user']??null;$aid=$scope['agent_id']??null;$cid=$scope['conversation_id']??null;
    if(!is_array($user)||!is_int($aid)||$aid<0||!is_int($cid)||$cid<1
        ||($binding['agent_id']??0)!==$aid||($binding['conversation_id']??null)!==$cid
        ||(isset($binding['owner_user_id'])&&$binding['owner_user_id']!==($user['id']??null))
        ||(function_exists('vp3_participant_scope_v1')&&vp3_participant_scope_v1()!==null)){
        throw new RuntimeException('Capability source scope is unavailable.',403);
    }
    if(!function_exists('vp3_initiative_tools_scope_v1'))require_once __DIR__.'/agent-initiative-tools-v1.php';
    if(($scope['verification_only']??false)!==true){
        $current=vp3_initiative_tools_scope_v1($pdo,$user,$binding);
    }else{
        // This path can only read current sources. It cannot schedule work, run a
        // provider, or grant the old policy's Knowledge, sensing or domain access.
        $principal=vp3_initiative_principal_v1($pdo,$user,$aid);
        $live=vp3_capability_live_user_v1($pdo,$user,$principal,['conversation_id'=>$cid],null);
        if(!has_permission('account.access',$live)||!has_permission('chat.access',$live)
            ||!vp3_agent_chat_conversation_v380($pdo,$cid,(int)$live['id'],$aid>0?['id'=>$aid]:null)){
            throw new RuntimeException('Capability source access changed.',403);
        }
        $current=['user'=>$live,'principal'=>$principal,'agent_id'=>$aid,'conversation_id'=>$cid,'verification_only'=>true];
    }
    $expected=$scope['principal']??null;
    if(!is_array($expected)||($expected['kind']??null)!==($current['principal']['kind']??null)
        ||(int)($expected['agent_id']??0)!==(int)($current['principal']['agent_id']??0)
        ||($expected['owner_user_id']??null)!==($current['principal']['owner_user_id']??null)
        ||($expected['viewer_user_id']??null)!==($current['principal']['viewer_user_id']??null)
        ||(int)($current['user']['id']??0)!==(int)($user['id']??0)){
        throw new RuntimeException('Capability principal changed.',403);
    }
    return $current;
}

/** Fixed local modules implement declared read adapters; model text never selects a PHP function. */
function vp3_initiative_capability_domains_v1(): array
{
    return [
        'transcript'=>['file'=>'agent-initiative-transcripts-v1.php','family'=>'transcripts','tables'=>['artist_transcript_sessions_v172','artist_transcript_segments_v172']],
        'mission'=>['file'=>'agent-initiative-mission-evidence-v1.php','family'=>'mission_evidence','tables'=>['agent_cloud_missions_v1','agent_workflow_runs','agent_workflow_actions','agent_workflow_receipts']],
        'product'=>['file'=>'agent-initiative-product-context-v1.php','family'=>'product_context','tables'=>['agent_commerce_products_v800','agent_commerce_product_bindings_v800']],
        'office'=>['file'=>'agent-initiative-office-records-v1.php','family'=>'office_records','tables'=>[]],
        'task_list'=>['file'=>'agent-initiative-task-lists-v1.php','family'=>'task_lists','tables'=>[]],
        'live'=>['file'=>'agent-initiative-live-context-v1.php','family'=>'live_context','tables'=>[]],
    ];
}

function vp3_initiative_capability_definition_v1(string $tool): ?array
{
    $entry=vp3_capability_contract_adapters_v1()[$tool]??null;
    if(!is_array($entry)||!isset(vp3_initiative_capability_domains_v1()[$entry['domain']??'']))return null;
    return $entry;
}

function vp3_initiative_capability_load_v1(string $domain): void
{
    $module=vp3_initiative_capability_domains_v1()[$domain]??null;
    if(!$module)throw new InvalidArgumentException('Unknown capability domain.');
    require_once __DIR__.'/'.$module['file'];
}

function vp3_initiative_capability_source_matches_v1(string $ref,array $definition): bool
{
    return is_string($definition['source_pattern']??null)&&preg_match($definition['source_pattern'],$ref)===1;
}

/** Domain source reads are bounded, live-authorized and have no inference or domain effects. */
function vp3_initiative_capabilities_evidence_v1(PDO $pdo,array $scope,array $binding,DateTimeImmutable $now,?array $selectedRefs,array &$coverage): array
{
    $scope=vp3_initiative_capability_scope_v1($pdo,$scope,$binding);$all=[];
    foreach(vp3_initiative_capability_domains_v1() as $domain=>$module){
        if($selectedRefs!==null){
            $needed=false;
            foreach(vp3_capability_contract_adapters_v1() as $entry){
                if($entry['domain']!==$domain)continue;
                foreach($selectedRefs as $ref)if(is_string($ref)&&vp3_initiative_capability_source_matches_v1($ref,$entry)){$needed=true;break 2;}
            }
            if(!$needed)continue;
        }
        try{
            foreach($module['tables'] as $table)if(!table_exists($table))throw new RuntimeException('Capability source storage is unavailable.',503);
            vp3_initiative_capability_load_v1($domain);
            $read='vp3_initiative_'.$domain.'_capabilities_evidence_v1';
            $selected=[];
            foreach($selectedRefs??[] as $ref)foreach(vp3_capability_contract_adapters_v1() as $entry){
                if($entry['domain']===$domain&&is_string($ref)&&vp3_initiative_capability_source_matches_v1($ref,$entry)){$selected[]=$ref;break;}
            }
            if(count($selected)>160)throw new RuntimeException('Capability source bounds changed.',503);
            $rows=[];$availability=[];$rank=['ready'=>0,'limited'=>1,'forbidden'=>2,'unavailable'=>3];
            foreach($selectedRefs===null?[null]:array_chunk($selected,5) as $batch){
                $batchCoverage=[];$rows+=$read($pdo,$scope,$binding,$now,$batch,$batchCoverage);
                foreach($batchCoverage as $family=>$state){
                    if(!isset($rank[$state]))throw new RuntimeException('Capability coverage changed.',503);
                    if(!isset($availability[$family])||$rank[$state]>$rank[$availability[$family]])$availability[$family]=$state;
                }
            }
            foreach($availability as $family=>$state)$coverage[$family]=$state;
            if(count($rows)>($selectedRefs===null?20:160))throw new RuntimeException('Capability source bounds changed.',503);
            foreach($rows as $ref=>$row){
                $tool=$row['capability_tool']??$row['office_tool']??match($domain){
                    'transcript'=>'transcripts.read','mission'=>'missions.evidence.read',
                    'product'=>'products.content.prepare',default=>'',
                };
                $definition=vp3_initiative_capability_definition_v1((string)$tool);
                if(!$definition||$definition['domain']!==$domain||!is_string($ref)||($row['ref']??null)!==$ref
                    ||!is_string($row['revision']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$row['revision'])
                    ||!vp3_initiative_capability_source_matches_v1($ref,$definition)
                    ||($selectedRefs!==null&&!in_array($ref,$selectedRefs,true))){
                    throw new RuntimeException('Capability source declaration changed.',503);
                }
                $family=$domain==='office'?(['tasks.read'=>'office_tasks','calendar.read'=>'office_calendar','reminders.read'=>'office_reminders'][$tool]):$module['family'];
                if(($availability[$family]??'')==='unavailable')continue;
                $row['capability_tool']=$tool;$all[$ref]=$row;
            }
        }catch(Throwable $error){
            $coverage[$module['family']]=in_array($error->getCode(),[403,404],true)?'forbidden':'unavailable';
            if($domain==='office')foreach(['office_tasks','office_calendar','office_reminders'] as $family)$coverage[$family]=$coverage[$module['family']];
            // A partial domain cannot turn a read failure into a smaller, apparently complete snapshot.
            foreach($all as $ref=>$row)if((vp3_initiative_capability_definition_v1($row['capability_tool'])['domain']??null)===$domain)unset($all[$ref]);
        }
    }
    return $all;
}

/** Parent inventory is descriptive; both its live permission and actual domain admission are required. */
function vp3_initiative_capabilities_catalog_v1(PDO $pdo,array $scope,array $binding,array $evidence): array
{
    if(($scope['verification_only']??false)===true)throw new RuntimeException('Readonly verification cannot select work.',403);
    $scope=vp3_initiative_capability_scope_v1($pdo,$scope,$binding);
    $snapshot=vp3_capability_snapshot_v1($pdo,$scope['user'],$scope['principal'],['conversation_id'=>$scope['conversation_id']],vp3_initiative_tools_knowledge_scope_v1($binding));
    $parents=array_column($snapshot['capabilities']??[],null,'id');$catalog=[];
    foreach(vp3_capability_contract_adapters_v1() as $tool=>$entry){
        if(!vp3_initiative_capability_definition_v1($tool))continue;
        $parent=$parents[$entry['foreground_capability']]??null;
        if(!$parent||empty($parent['permitted'])||empty($parent['registered'])
            ||(empty($parent['ready'])&&($parent['reason']??'')!=='not_checked'))continue;
        $refs=[];
        foreach($evidence as $ref=>$row)if(($row['capability_tool']??null)===$tool)$refs[]=$ref;
        if(!$refs)continue;
        $catalog[$tool]=['id'=>$tool,'capability'=>$entry['foreground_capability'],'mode'=>$entry['mode'],
            'ready'=>true,'reason'=>'ready','source_refs'=>$refs,'description'=>$entry['description'],
            'tool_contract'=>$entry+['authority'=>false]];
    }
    return $catalog;
}

function vp3_initiative_capability_snapshot_v1(PDO $pdo,array $scope,array $binding,string $tool,array $job,array $evidence): array
{
    $scope=vp3_initiative_capability_scope_v1($pdo,$scope,$binding);
    $definition=vp3_initiative_capability_definition_v1($tool);
    $primary=$job['primary']??null;$refs=$job['refs']??null;
    if(!$definition||!is_string($primary)||!is_array($refs)||!array_is_list($refs)||!$refs||count($refs)>5
        ||count($refs)!==count(array_unique($refs))||!in_array($primary,$refs,true)
        ||!isset($evidence[$primary])||($evidence[$primary]['capability_tool']??null)!==$tool){
        throw new RuntimeException('Capability assignment changed.',409);
    }
    foreach($refs as $ref)if(!is_string($ref)||!isset($evidence[$ref]))throw new RuntimeException('Capability context changed.',409);
    vp3_initiative_capability_load_v1($definition['domain']);
    $reader='vp3_initiative_'.$definition['domain'].'_capabilities_read_v1';
    $result=$reader($pdo,$scope,$binding,$tool,$job,$evidence);
    if(!is_array($result)||!is_array($result['records']??null)||!array_is_list($result['records'])||count($result['records'])>20
        ||!is_array($result['sources']??null)||!array_is_list($result['sources'])||count($result['sources'])>20
        ||!is_array($result['coverage']??null))throw new RuntimeException('Capability read could not be verified.',503);
    foreach($result['records'] as $record){
        if(!is_array($record)||!is_string($record['source']??null)||$record['source']===''||strlen($record['source'])>300
            ||!is_string($record['title']??null)||mb_strlen($record['title'])>300
            ||!is_string($record['text']??null)||mb_strlen($record['text'])>8000){
            throw new RuntimeException('Capability record exceeded its contract.',503);
        }
    }
    foreach($result['sources'] as $source){
        if(!is_array($source)||!is_string($source['source']??null)||$source['source']===''||strlen($source['source'])>300
            ||!is_string($source['title']??null)||mb_strlen($source['title'])>300){
            throw new RuntimeException('Capability citation changed.',503);
        }
        if(isset($source['url'])&&(!is_string($source['url'])||strlen($source['url'])>1900
            ||!((str_starts_with($source['url'],'/')&&!str_starts_with($source['url'],'//')&&!str_contains($source['url'],'\\'))
                ||(filter_var($source['url'],FILTER_VALIDATE_URL)&&str_starts_with($source['url'],'https://'))))){
            throw new RuntimeException('Capability citation URL changed.',503);
        }
    }
    if(strlen(json_encode($result,JSON_THROW_ON_ERROR))>65536)throw new RuntimeException('Capability read exceeded its budget.',503);
    return $result;
}

function vp3_initiative_capability_fingerprint_v1(array $snapshot): string
{
    $normalize=static function(mixed $value)use(&$normalize):mixed{
        if(!is_array($value))return $value;
        if(!array_is_list($value))ksort($value);
        foreach($value as &$child)$child=$normalize($child);
        unset($child);return $value;
    };
    return hash('sha256',json_encode($normalize($snapshot),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
}

/** The existing Initiative action/receipt stores the useful draft and exact read proof. */
function vp3_initiative_capability_execute_v1(PDO $pdo,array $scope,array $binding,array $job,array $evidence,?callable $model): array
{
    if(($scope['verification_only']??false)===true)throw new RuntimeException('Readonly verification cannot prepare work.',403);
    if($pdo->inTransaction())throw new RuntimeException('Capability preparation cannot hold a database transaction.',503);
    $tool=$job['tool'];$catalog=vp3_initiative_capabilities_catalog_v1($pdo,$scope,$binding,$evidence);
    if(empty($catalog[$tool]['ready']))throw new RuntimeException('Capability permission or readiness changed.',403);
    $read=vp3_initiative_capability_snapshot_v1($pdo,$scope,$binding,$tool,$job,$evidence);
    $fingerprint=vp3_initiative_capability_fingerprint_v1($read);
    $draft=vp3_initiative_tools_report_v1($pdo,$scope['user'],$job,$evidence,[
        'tool'=>$tool,'status'=>$read['records']?'read_complete':'no_matches','records'=>$read['records'],'coverage'=>$read['coverage'],
        'effect'=>'Authorized saved records were read. Prepare useful private analysis, a draft, or one focused question. Saved AI analysis is interpretation, never a transcript quotation or independently verified fact. An absent association does not prove missing promotion. No research, new transcript analysis, image generation, publication, communication, task/calendar change or purchase was performed.',
    ],$model);
    $scope=vp3_initiative_capability_scope_v1($pdo,$scope,$binding);
    $fresh=vp3_initiative_capability_snapshot_v1($pdo,$scope,$binding,$tool,$job,$evidence);
    if(!hash_equals($fingerprint,vp3_initiative_capability_fingerprint_v1($fresh)))throw new RuntimeException('Capability records changed during preparation.',409);
    $definition=vp3_initiative_capability_definition_v1($tool);
    $operation=match($tool){'transcripts.read','products.content.prepare','live.context.read'=>'read',
        'missions.evidence.read'=>'inspect','tasks.lists.read'=>'show',default=>'list'};
    $state=$read['records']?'read_complete':'no_matches';
    $revisions=[];foreach($job['refs'] as $ref)$revisions[$ref]=$evidence[$ref]['revision'];ksort($revisions);
    $result=['draft'=>$draft,'sources'=>$read['sources'],
        'tool_receipt'=>vp3_tool_receipt_v1($definition['foreground_capability'],$operation,$state,'',0,count($read['records'])),
        'output'=>['type'=>'initiative_capability_read','version'=>1,'tool'=>$tool,'status'=>$state,
            'owner_user_id'=>(int)$scope['user']['id'],'agent_id'=>$scope['agent_id'],'conversation_id'=>$scope['conversation_id'],
            'primary'=>$job['primary'],'source_revisions'=>$revisions,'read_fingerprint'=>$fingerprint,
            'record_count'=>count($read['records']),
            'coverage_fingerprint'=>vp3_initiative_capability_fingerprint_v1($read['coverage']),
            'coverage_state'=>in_array($read['coverage']['state']??'', ['ready','limited'],true)?$read['coverage']['state']:'bounded']];
    if(!function_exists('agent_workflow_initiative_result_v1400')
        ||(agent_workflow_initiative_result_v1400($result)['output']??null)!==$result['output']){
        throw new RuntimeException('The canonical receipt cannot retain this capability proof.',503);
    }
    return $result;
}

/** Used at commit, delayed delivery and exact saved-post replies; never model inference or another write. */
function vp3_initiative_capability_result_current_v1(PDO $pdo,array $scope,array $binding,array $goal,array $toolResult): void
{
    $scope=vp3_initiative_capability_scope_v1($pdo,$scope,$binding);$tool=$goal['tool']??'';$out=$toolResult['output']??null;
    $refs=array_keys($goal['sources']??[]);$primary=$goal['primary']??null;
    if(!vp3_initiative_capability_definition_v1($tool)||!is_array($out)||($out['type']??null)!=='initiative_capability_read'
        ||($out['version']??null)!==1||($out['tool']??null)!==$tool||($out['owner_user_id']??null)!==(int)$scope['user']['id']
        ||($out['agent_id']??null)!==$scope['agent_id']||($out['conversation_id']??null)!==$scope['conversation_id']
        ||($out['primary']??null)!==$primary||!is_array($out['source_revisions']??null)
        ||!is_string($out['read_fingerprint']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$out['read_fingerprint'])
        ||!is_string($out['coverage_fingerprint']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$out['coverage_fingerprint'])){
        throw new RuntimeException('Saved capability proof changed.',409);
    }
    $expected=$goal['sources'];ksort($expected);$saved=$out['source_revisions'];ksort($saved);
    if($saved!==$expected)throw new RuntimeException('Saved capability source binding changed.',409);
    $coverage=[];$evidence=vp3_initiative_capabilities_evidence_v1($pdo,$scope,$binding,new DateTimeImmutable('now',new DateTimeZone('UTC')),$refs,$coverage);
    foreach($saved as $ref=>$revision)if(isset($evidence[$ref])&&!hash_equals($revision,$evidence[$ref]['revision']))throw new RuntimeException('Capability source revision changed.',409);
    // Context from another domain remains independently bound by the caller's
    // canonical current_sources check. Only matching records are read here.
    $missing=array_diff($refs,array_keys($evidence));
    foreach($missing as $ref)$evidence[$ref]=['ref'=>$ref,'revision'=>$saved[$ref]];
    $read=vp3_initiative_capability_snapshot_v1($pdo,$scope,$binding,$tool,
        ['tool'=>$tool,'primary'=>$primary,'refs'=>$refs,'focus'=>$goal['focus']??'','report'=>$goal['report']??false],$evidence);
    if(!hash_equals($out['read_fingerprint'],vp3_initiative_capability_fingerprint_v1($read))
        ||($out['status']??null)!==($read['records']?'read_complete':'no_matches')
        ||($out['record_count']??null)!==count($read['records'])
        ||!hash_equals($out['coverage_fingerprint'],vp3_initiative_capability_fingerprint_v1($read['coverage']))
        ||($out['coverage_state']??null)!==(in_array($read['coverage']['state']??'', ['ready','limited'],true)?$read['coverage']['state']:'bounded')){
        throw new RuntimeException('Saved capability records changed.',409);
    }
}

/** Declared sources require a short serializable final transaction, including ordinary preparation. */
function vp3_initiative_capability_sources_present_v1(array $sources): bool
{
    foreach(array_keys($sources) as $ref)foreach(vp3_capability_contract_adapters_v1() as $entry){
        if(is_string($ref)&&vp3_initiative_capability_source_matches_v1($ref,$entry))return true;
    }
    return false;
}

/** Record actual admission, rather than inferring a legacy source's adapter from its prefix. */
function vp3_initiative_capability_source_map_v1(array $evidence,array $refs): array
{
    $map=[];
    foreach($refs as $ref){
        $tool=$evidence[$ref]['capability_tool']??null;
        $entry=is_string($tool)?vp3_initiative_capability_definition_v1($tool):null;
        if($entry&&vp3_initiative_capability_source_matches_v1($ref,$entry))$map[$ref]=$tool;
    }
    ksort($map);return $map;
}

function vp3_initiative_capability_saved_source_map_v1(array $goal): array
{
    $map=$goal['capability_sources']??[];
    if(!is_array($map)||count($map)>5)throw new RuntimeException('Capability source declarations changed.',409);
    // A declared leaf's primary is sufficient for old server-produced fixtures
    // without this additive marker. Generic legacy calendar/task proposals keep
    // their original canonical read path.
    if(!$map&&vp3_initiative_capability_definition_v1((string)($goal['tool']??''))){
        $tool=$goal['tool'];$entry=vp3_initiative_capability_definition_v1($tool);
        foreach(array_keys($goal['sources']??[]) as $ref)if(vp3_initiative_capability_source_matches_v1($ref,$entry))$map[$ref]=$tool;
    }
    foreach($map as $ref=>$tool){
        $entry=is_string($tool)?vp3_initiative_capability_definition_v1($tool):null;
        if(!$entry||!isset($goal['sources'][$ref])||!vp3_initiative_capability_source_matches_v1($ref,$entry)){
            throw new RuntimeException('Capability source declaration changed.',409);
        }
    }
    return $map;
}
