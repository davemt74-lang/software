<?php
declare(strict_types=1);

require_once __DIR__.'/agent-top-of-mind-v1.php';

const VP3_MORNING_BRIEF_SOURCE_V1='agent_initiative_morning_brief';

function vp3_morning_brief_payload_hash_v1(string $message,array $links): string
{
    return hash('sha256',json_encode([$message,$links],JSON_THROW_ON_ERROR));
}

function vp3_morning_brief_local_v1(PDO $pdo,array $user,DateTimeImmutable $now): DateTimeImmutable
{
    require_once __DIR__.'/agent-initiative-office-records-v1.php';
    $name=$user['timezone']??null;
    if(is_string($name)&&in_array($name,DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC),true)){
        return $now->setTimezone(new DateTimeZone($name));
    }
    return vp3_initiative_task_local_time_v1($pdo,['user'=>$user],$now->setTimezone(new DateTimeZone('UTC')));
}

function vp3_morning_brief_work_v1(PDO $pdo,array $user,int $agent,array $policy,int $now): array
{
    $cid=$policy['conversation_id'];
    $owner=(int)$user['id'];
    $work=vp3_top_of_mind_coordination_specialists_v1($pdo,$user,$agent,$policy,$now);
    $action=$pdo->prepare('SELECT id,status FROM agent_workflow_actions WHERE run_id=? AND owner_user_id=? AND sequence_no=1 LIMIT 1');
    foreach(vp3_initiative_runtime_runs_v1($pdo,$owner,$agent,$policy,true) as $run){
        $goal=json_decode((string)$run['goal'],true);
        // Coordination reports have their own verified rows above. A queued
        // introductory question must not count as a specialist execution.
        if(($goal['tool']??null)==='coordination.review')continue;
        $id=is_array($goal)?vp3_top_of_mind_tool_specialist_v1($goal):'';
        if($id===''||isset($work[$id]))continue;
        $action->execute([(int)$run['id'],$owner]);
        $row=$action->fetch(PDO::FETCH_ASSOC);
        if(!$row)continue;
        $state=vp3_top_of_mind_state_v1($run,$row,null,[],$now);
        if(!in_array($state,['working','queued'],true)||($state==='queued'&&$row['status']!=='queued'))continue;
        $work[$id]=['id'=>$id,'state'=>$state,'run_id'=>(int)$run['id'],'conversation_id'=>$cid];
    }
    foreach(vp3_morning_brief_direct_work_v1($pdo,$user,$agent,$cid,$now) as $id=>$state){
        $work[$id]=vp3_top_of_mind_prefer_work_v1($work[$id]??null,['id'=>$id,'state'=>$state,'conversation_id'=>$cid,
            'verified_activity'=>true]);
    }
    return $work;
}

/** Some specialists admit their own jobs. Consult their scoped state without opening results or running a job. */
function vp3_morning_brief_direct_work_v1(PDO $pdo,array $user,int $agent,int $cid,int $now): array
{
    $owner=(int)$user['id'];$work=[];
    if(table_exists('agent_image_generations_v1')){
        $query=$pdo->prepare("SELECT r.status FROM agent_image_generations_v1 g JOIN agent_workflow_runs r
            ON r.id=g.run_id AND r.owner_user_id=g.owner_user_id WHERE g.owner_user_id=? AND g.agent_id=? AND g.conversation_id=?
            AND r.source_kind='image_generation' AND (r.status IN ('approved','queued') OR (r.status='executing' AND r.lease_expires_at>?))
            ORDER BY r.id DESC LIMIT 1");
        $query->execute([$owner,$agent,$cid,gmdate('Y-m-d H:i:s',$now)]);
        $status=$query->fetchColumn();
        if(is_string($status))$work['image-generator']=$status==='executing'?'working':'queued';
    }
    if(!function_exists('personal_knowledge_available'))require_once __DIR__.'/knowledge.php';
    if(personal_knowledge_available($user)){
        require_once __DIR__.'/coding-canvas-jobs-v1.php';
        try{
            $coding=vp3_coding_canvas_resume_v1($pdo,$user,$agent,$cid);
            if(($coding['state']??null)==='queued')$work['coding']='queued';
            if(($coding['state']??null)==='running'){
                $run=vp3_coding_canvas_job_row_v1($pdo,$user,$coding['run_id']);
                if(vp3_top_of_mind_lease_time_v1($run['lease_expires_at']??null)>$now)$work['coding']='working';
            }
        }catch(RuntimeException|JsonException $error){
            if($error instanceof PDOException)throw $error;
        }
    }
    if(table_exists('agent_development_revisions_v1')&&table_exists('agent_development_controls_v1')){
        require_once __DIR__.'/agent-development-v1.php';
        if(function_exists('user_has_role')&&vp3_dev_is_admin_v1($user)){
            $query=$pdo->prepare("SELECT r.status FROM agent_development_revisions_v1 r JOIN agent_development_controls_v1 c
                ON c.owner_user_id=r.owner_user_id AND c.epoch=r.epoch WHERE r.owner_user_id=? AND r.conversation_id=? AND c.mode<>'off'
                AND (r.status='queued' OR (r.status IN ('building','outlining') AND r.lease_expires_at>?)) ORDER BY r.created_at DESC LIMIT 1");
            $query->execute([$owner,$cid,$now]);
            $status=$query->fetchColumn();
            if(is_string($status))$work['development']=$status==='queued'?'queued':'working';
        }
    }
    return $work;
}

/** Curiosity questions are answered at their original Chat message. A brief only links to that turn. */
function vp3_morning_brief_curiosity_item_v1(PDO $pdo,array $user,int $agent,int $cid,?string $ref=null,?int $sourceChat=null,
    ?int $reviewedAt=null): ?array
{
    require_once __DIR__.'/agent-curiosity-v1.php';
    foreach(['curiosity-finding:'=>'goal','curiosity-taste:'=>'taste'] as $prefix=>$kind){
        if($ref!==null&&!str_starts_with($ref,$prefix))continue;
        $threadId=$ref===null?null:substr($ref,strlen($prefix));
        if($threadId!==null&&!preg_match('/\A[a-f0-9]{32}\z/D',$threadId))return null;
        $confirmed=vp3_curiosity_confirmed_v1($pdo,$user,$agent,$cid,$threadId===null?null:[$threadId],$kind);
        foreach($confirmed as $finding){
            $item=['ref'=>$prefix.$finding['id'],'revision'=>$finding['revision'],'specialist'=>'curiosity',
                'title'=>$finding['title'],'summary'=>$finding['summary'],'next_step'=>'','question'=>'','url'=>$finding['href'],
                'trigger'=>'open_question'];
            if($reviewedAt===null||!vp3_morning_brief_curiosity_seen_v1($pdo,$user,$agent,$item))return $item;
        }
        if($ref!==null)return null;
    }
    if($ref!==null){
        if(!preg_match('/\Acuriosity:([a-f0-9]{32}):([a-f0-9]{32})\z/D',$ref,$match))return null;
        if($sourceChat===null||$sourceChat<1)return null;
        $question=vp3_curiosity_question_v1($pdo,$user,$agent,$sourceChat,$match[1],$match[2]);
        return $question===null?null:vp3_morning_brief_curiosity_question_v1($ref,$agent,$question);
    }
    $report=vp3_curiosity_report_v1($pdo,$user,$agent,$cid,$reviewedAt,includeEarlierTopics:true);
    $question=$report['pending_question']??null;
    if(!is_array($question))return null;
    $ref=vp3_curiosity_ref_v1($question['thread_id'],$question['id']);
    $item=vp3_morning_brief_curiosity_question_v1($ref,$agent,$question);
    return $item!==null&&($reviewedAt===null||!vp3_morning_brief_curiosity_seen_v1($pdo,$user,$agent,$item))?$item:null;
}

function vp3_morning_brief_curiosity_seen_v1(PDO $pdo,array $user,int $agent,array $item): bool
{
    [$scope,$params]=vp3_agent_memory_scope_sql_v410($agent);
    $value="JSON_EXTRACT(CASE WHEN JSON_VALID(metadata_json) THEN metadata_json ELSE '{}' END,?)";
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite')$value="JSON_UNQUOTE($value)";
    $query=$pdo->prepare("SELECT 1 FROM agent_memory_items WHERE user_id=? AND $scope AND memory_type='initiative_sweep'
        AND subject LIKE 'morning-brief:%' AND is_active=1 AND $value=? LIMIT 1");
    $path='$.selected.'.json_encode($item['ref'],JSON_THROW_ON_ERROR).'.revision';
    $query->execute([(int)$user['id'],...$params,$path,$item['revision']]);
    return $query->fetchColumn()!==false;
}

function vp3_morning_brief_curiosity_question_v1(string $ref,int $agent,array $question): ?array
{
    $message=$question['message_id']??null;
    $chat=$question['conversation_id']??null;
    if(!is_int($message)||$message<1||!is_int($chat)||$chat<1)return null;
    return vp3_coordination_source_item_v1($ref,'curiosity','Original Chat question','','','',
        url('/chat.php?agent='.($agent?:'system').'&conversation_id='.$chat.'&message_id='.$message),
        'open','low',[$chat,$message])+['trigger'=>'open_question','source_conversation_id'=>$chat];
}

/** Group only proven related findings and include only content permitted for Chat. */
function vp3_morning_brief_assess_v1(array $items,array $coverage,array $settings,array $specialists,?array $curiosity=null): ?array
{
    $labels=[];
    foreach(vp3_coordination_rule_definitions_v1() as $id=>$definition)$labels[$id]=$definition['label'];
    $labels+=['canvas'=>'Canvas Apps','research'=>'Research','image-generator'=>'Image Generator','coding'=>'Coding Agent',
        'development'=>'Agent Development','mission'=>'Missions'];
    $checked=[];
    foreach($coverage as $id=>$state){
        if(in_array($state,['ready','limited'],true)&&isset($labels[$id]))$checked[$id]=['coverage'=>$state,'considered'=>0,'chat_matches'=>0];
    }
    $eligible=[];$routes=[];
    foreach($items as $ref=>$item){
        $id=$item['specialist'];
        $routes[$ref]=['action'=>'watch'];
        if(!isset($checked[$id])||$id==='curiosity'||$ref===VP3_COORDINATION_PLANNING_START_V1)continue;
        ++$checked[$id]['considered'];
        $route=$routes[$ref]=vp3_coordination_item_route_v1($settings,$item);
        if(!in_array($route['action'],['ask','report'],true))continue;
        ++$checked[$id]['chat_matches'];
        $eligible[$ref]=$item;
        unset($items[$ref]['legacy_delivered']);
    }
    [$material,$covered]=vp3_coordination_material_discovery_v1($items,$routes);
    $seen=[];$candidates=[];$firstBySpecialist=[];$duplicates=0;
    foreach($eligible as $ref=>$item){
        $id=$item['specialist'];
        if(isset($covered[$ref]))continue;
        // Legacy delivery stays intact, but copies of the same unchanged result only need one line in a brief.
        if(trim((string)($item['title']??''))==='')continue;
        $digest=hash('sha256',json_encode([$id,$item['title']??null,$item['summary']??null,
            $item['next_step']??null,$item['question']??null],JSON_THROW_ON_ERROR));
        if(isset($seen[$digest])){++$duplicates;continue;}
        $seen[$digest]=true;
        $candidates[$ref]=$item;
        $firstBySpecialist[$id]??=$ref;
    }
    // Give each contributing specialist space, then fill any remaining slots in verified priority order.
    $primary=array_intersect_key($candidates,array_slice(array_fill_keys(array_values($firstBySpecialist),true)+$candidates,0,6,true));
    $curiosityEligible=$curiosity!==null&&isset($checked['curiosity'])
        &&vp3_coordination_item_route_v1($settings,$curiosity)['action']==='ask';
    $profilePeriod=null;
    foreach($candidates as $candidate)if(($candidate['specialist']??null)==='profile'
        &&($candidate['profile_source_kind']??null)==='analytics'&&($candidate['trigger']??null)==='analytics_period'){
        $profilePeriod=$candidate;break;
    }
    $hasProfile=(bool)array_filter($primary,static fn(array $item):bool=>$item['specialist']==='profile');
    $attachPeriod=$hasProfile&&$profilePeriod!==null&&!isset($primary[$profilePeriod['ref']]);
    // Reserve exact proof slots before adding optional prose. History verifies
    // every selected source and retains its existing twelve-source bound.
    $relatedBudget=max(0,12-count($primary)-($curiosityEligible?1:0)-($attachPeriod?1:0));
    $selected=[];$links=[];$lines=[];$periodAttached=false;
    foreach($primary as $ref=>$item){
        $id=$item['specialist'];
        $summary=vp3_top_of_mind_text_v1($item['summary']??'',220);
        $title=vp3_top_of_mind_text_v1($item['title']??'',100);
        $line='• '.$labels[$id].' — '.$title;
        if($summary!==''&&$title!==$summary)$line.=' — '.$summary;
        $next=vp3_top_of_mind_text_v1($item['next_step']??'',120);
        if($next!=='')$line.=' Next step: '.$next;
        vp3_morning_brief_add_source_v1($selected,$links,$item,$title);
        foreach($material[$ref]['planning_refs']??[] as $relatedRef){
            if($relatedBudget<1||!isset($covered[$relatedRef],$eligible[$relatedRef])||isset($selected[$relatedRef]))continue;
            $related=$eligible[$relatedRef];
            $relatedTitle=vp3_top_of_mind_text_v1($related['title']??'',100);
            if($relatedTitle==='')continue;
            $line.=' Related '.$labels[$related['specialist']].' item — '.$relatedTitle.'.';
            vp3_morning_brief_add_source_v1($selected,$links,$related,$relatedTitle);
            --$relatedBudget;
            break;
        }
        if($id==='profile'&&$attachPeriod&&!$periodAttached){
            $periodTitle=vp3_top_of_mind_text_v1($profilePeriod['title'],100);
            $periodSummary=vp3_top_of_mind_text_v1($profilePeriod['summary'],220);
            $line.=' '.$periodTitle.' — '.$periodSummary;
            vp3_morning_brief_add_source_v1($selected,$links,$profilePeriod,$periodTitle);
            $periodAttached=true;
        }
        $lines[]=$line;
    }
    if($curiosityEligible){
        ++$checked['curiosity']['considered'];
        ++$checked['curiosity']['chat_matches'];
        $title=vp3_top_of_mind_text_v1($curiosity['title'],100);
        $summary=vp3_top_of_mind_text_v1($curiosity['summary'],220);
        $lines[]='• Curiosity — '.$title.($summary!==''&&$title!==$summary?' — '.$summary:'');
        vp3_morning_brief_add_source_v1($selected,$links,$curiosity,$title);
    }
    $work=[];
    foreach($specialists as $row){
        $id=$row['id']??null;
        $state=$row['state']??null;
        if($id==='curiosity'||!isset($labels[$id])||!in_array($state,['working','queued','ready','ready_to_report'],true))continue;
        // A saved scan may be ready without an actual current assignment.
        if(($row['run_id']??0)<1&&($row['verified_activity']??false)!==true)continue;
        $work[$id]=$state;
    }
    if(!$lines&&!$work&&!isset($checked['gmail']))return null;
    $parts=['Morning brief'];
    if($lines)$parts[]=implode("\n",$lines);
    if(isset($checked['gmail'])&&$checked['gmail']['chat_matches']===0){
        $parts[]=$checked['gmail']['coverage']==='ready'?'Gmail had no verified findings marked for Chat.'
            :'No verified Gmail finding is ready for this brief. Inbox analysis coverage is partial.';
    }
    if($work){
        $pending=[];
        foreach($work as $id=>$state)$pending[]=$labels[$id].' ('.match($state){
            'working'=>'working','queued'=>'queued','ready','ready_to_report'=>'result ready',
        }.')';
        $parts[]='Specialist work: '.implode(', ',$pending).'.';
    }
    if($checked){
        $sources=[];
        foreach($checked as $id=>$row)$sources[]=$labels[$id].($row['coverage']==='limited'?' (partial)':'');
        $parts[]='Reviewed: '.implode(', ',$sources).'.';
    }
    return ['message'=>implode("\n\n",$parts),'links'=>$links,'checked'=>$checked,'selected'=>$selected,'work'=>$work,
        'grouped'=>count($covered)+$duplicates];
}

function vp3_morning_brief_add_source_v1(array &$selected,array &$links,array $item,string $title): void
{
    $ref=$item['ref'];
    $selected[$ref]=['specialist'=>$item['specialist'],'revision'=>$item['revision']];
    if(isset($item['source_conversation_id']))$selected[$ref]['source_conversation_id']=$item['source_conversation_id'];
    $url=$item['url']??null;
    if(is_string($url)&&strlen($url)<=1900&&str_starts_with($url,'/')&&!str_starts_with($url,'//')&&!str_contains($url,'\\')){
        $links[]=['source'=>$ref,'title'=>$title,'url'=>$url];
    }
}

/** Called by the existing owner-locked coordinator. Proof and Chat projection commit together. */
function vp3_morning_brief_v1(PDO $pdo,array $user,array $binding,array $snapshot,?DateTimeImmutable $now=null,
    bool $prioritySlot=false): bool
{
    $owner=(int)($user['id']??0);$cid=(int)($binding['conversation_id']??0);
    if(!$pdo->inTransaction()||$owner<1||$cid<1||($binding['enabled']??false)!==true
        ||vp3_participant_scope_v1()!==null||($binding['owner_user_id']??$owner)!==$owner||!agent_brain_schema_ready())return false;
    $agent=vp3_initiative_binding_agent_v1($binding);
    $now??=new DateTimeImmutable('now',new DateTimeZone('UTC'));
    $local=vp3_morning_brief_local_v1($pdo,$user,$now);
    if((int)$local->format('G')<($prioritySlot?10:8))return false;
    return vp3_initiative_in_scope_v1($user,$agent,static function()use($pdo,$user,$binding,$snapshot,$now,$local,$owner,$agent,$cid,$prioritySlot):bool{
        $day=$local->format('Y-m-d');
        $subject='morning-brief:'.$day;
        $policy=vp3_initiative_policy_v1($user,$agent);
        if(($policy['enabled']??false)!==true||($policy['conversation_id']??null)!==$cid
            ||($policy['revision']??null)!==($binding['revision']??null)
            ||!vp3_agent_chat_conversation_v380($pdo,$cid,$owner,$agent>0?['id'=>$agent]:null,true))return false;

        // Read the marker strictly so a storage error cannot look like an undelivered brief.
        [$scope,$params]=vp3_agent_memory_scope_sql_v410($agent);
        $hash=vp3_agent_memory_scope_hash_v410($agent,agent_brain_v122_memory_hash('initiative_sweep',$subject));
        $query=$pdo->prepare('SELECT 1 FROM agent_memory_items WHERE user_id=? AND '.$scope.' AND memory_hash=? LIMIT 1');
        $query->execute([$owner,...$params,$hash]);
        if($query->fetchColumn()!==false)return false;

        $settings=vp3_coordination_rules_v1($pdo,$user,$agent);
        $work=vp3_morning_brief_work_v1($pdo,$user,$agent,$policy,$now->getTimestamp());
        $coverage=$snapshot['coverage']??[];
        $curiosity=in_array($coverage['curiosity']??null,['ready','limited'],true)
            &&vp3_coordination_route_v1($settings,'curiosity',['open_question'])['action']==='ask'
            ?vp3_morning_brief_curiosity_item_v1($pdo,$user,$agent,$cid,reviewedAt:$now->getTimestamp()):null;
        if($curiosity!==null)$coverage['curiosity']='ready';
        $assessment=vp3_morning_brief_assess_v1(vp3_coordination_scan_items_v1($snapshot),$coverage,$settings,$work,$curiosity);
        if($assessment===null)return false;
        if($prioritySlot&&!array_filter($assessment['selected'],static fn(array $item):bool=>!in_array($item['specialist'],['media','curiosity'],true)))return false;

        $proof=['local_day'=>$day,'timezone'=>$local->getTimezone()->getName(),'assessed_at'=>$now->getTimestamp(),
            'conversation_id'=>$cid,'binding_revision'=>$binding['revision'],'routing_revision'=>$settings['revision'],
            'checked'=>$assessment['checked'],'selected'=>$assessment['selected'],'work'=>$assessment['work'],'grouped'=>$assessment['grouped'],
            'payload_hash'=>vp3_morning_brief_payload_hash_v1($assessment['message'],$assessment['links'])];
        $id=agent_brain_v122_upsert_system_memory($user,'initiative_sweep',$subject,'Morning brief assessment',$proof);
        if($id<1)throw new RuntimeException('Morning brief assessment could not be saved.');
        $context=['source'=>VP3_MORNING_BRIEF_SOURCE_V1,'skip_brain_archive'=>true,'sources'=>$assessment['links'],
            'morning_brief'=>['local_day'=>$day,'assessment_id'=>$id]];
        $pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json,created_at)
            VALUES (?,NULL,'assistant',?,?,NOW())")->execute([$cid,$assessment['message'],json_encode($context,JSON_THROW_ON_ERROR)]);
        $pdo->prepare('UPDATE chat_conversations SET updated_at=NOW() WHERE id=? AND user_id=?')->execute([$cid,$owner]);
        return true;
    });
}

/** An earlier brief keeps its assessment, but Chat can show only evidence the owner can still read. */
function vp3_morning_brief_history_v1(PDO $pdo,array $user,int $agent,int $conversation,array $row,array $context): array
{
    try{
        $owner=(int)($user['id']??0);
        $marker=$context['morning_brief']??[];
        $day=$marker['local_day']??null;
        $id=$marker['assessment_id']??null;
        if($owner<1||$agent<0||$conversation<1||!is_int($id)||$id<1||!is_string($day)||!preg_match('/\A\d{4}-\d{2}-\d{2}\z/D',$day)
            ||vp3_participant_scope_v1()!==null||!vp3_agent_chat_conversation_v380($pdo,$conversation,$owner,$agent>0?['id'=>$agent]:null,true)){
            throw new RuntimeException('Morning brief access changed.');
        }
        [$scope,$params]=vp3_agent_memory_scope_sql_v410($agent);
        $query=$pdo->prepare("SELECT metadata_json FROM agent_memory_items WHERE user_id=? AND $scope AND id=?
            AND memory_type='initiative_sweep' AND subject=? AND is_active=1 LIMIT 1");
        $query->execute([$owner,...$params,$id,'morning-brief:'.$day]);
        $saved=json_decode((string)$query->fetchColumn(),true,32,JSON_THROW_ON_ERROR);
        $links=$context['sources']??null;
        $selected=$saved['selected']??null;
        $bindingRevision=$saved['binding_revision']??null;
        if(($saved['conversation_id']??null)!==$conversation||($saved['local_day']??null)!==$day
            ||!is_array($links)||!is_array($selected)||count($selected)>12||!is_string($row['message']??null)
            ||!is_string($bindingRevision)||!preg_match('/\A[a-f0-9]{32}\z/D',$bindingRevision)
            ||($saved['payload_hash']??null)!==vp3_morning_brief_payload_hash_v1($row['message'],$links)){
            throw new RuntimeException('Morning brief proof changed.');
        }
        if($selected){
            $binding=['owner_user_id'=>$owner,'agent_id'=>$agent,'conversation_id'=>$conversation,'enabled'=>true,'revision'=>$bindingRevision];
            $ordinary=array_filter($selected,static fn(array $item):bool=>$item['specialist']!=='curiosity');
            $current=$ordinary?vp3_coordination_sources_v1($pdo,$user,$binding,array_keys($ordinary))['items']:[];
            $rules=vp3_coordination_rules_v1($pdo,$user,$agent);
            foreach($selected as $ref=>$expected){
                $item=$expected['specialist']==='curiosity'
                    ?vp3_morning_brief_curiosity_item_v1($pdo,$user,$agent,$conversation,$ref,$expected['source_conversation_id']??null)
                    :($current[$ref]??null);
                if($item===null||$item['revision']!==$expected['revision']||$item['specialist']!==$expected['specialist']
                    ||!in_array(vp3_coordination_item_route_v1($rules,$item)['action'],['ask','report'],true)){
                    throw new RuntimeException('Morning brief source changed.');
                }
            }
        }
        return $row;
    }catch(Throwable $error){
        $row['message']='The sources behind this morning brief have changed or are no longer available.';
        $row['context_json']=json_encode(['source'=>VP3_MORNING_BRIEF_SOURCE_V1,'skip_brain_archive'=>true],JSON_THROW_ON_ERROR);
        return $row;
    }
}
