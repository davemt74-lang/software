<?php
declare(strict_types=1);

require_once __DIR__.'/agent-initiative-scope-v1.php';
require_once __DIR__.'/agent-coordination-sources-v1.php';
require_once __DIR__.'/agent-coordination-work-v1.php';
require_once __DIR__.'/agent-coordination-rules-v1.php';
require_once __DIR__.'/agent-coordination-scan-details-v1.php';

/** Rechecking under the owner lock keeps simultaneous Chat and service sweeps cheap. */
function vp3_coordination_scan_due_v1(array $user,array $binding): bool
{
    if(($binding['enabled']??false)!==true||!is_int($binding['conversation_id']??null)
        ||$binding['conversation_id']<1||!is_string($binding['revision']??null))return false;
    return vp3_initiative_in_scope_v1($user,vp3_initiative_binding_agent_v1($binding),static function()use($user,$binding):bool{
        $state=agent_brain_v122_memory($user,'initiative_sweep','coordination-scan')['metadata']??[];
        $scanned=$state['scanned_at']??null;
        $now=time();
        return ($state['conversation_id']??null)!==$binding['conversation_id']
            ||($state['revision']??null)!==$binding['revision']
            ||!is_int($scanned)||$scanned>$now||$now-$scanned>=30;
    });
}

/** Only a completed discovery for this Agent, Chat and policy can explain a scan. */
function vp3_coordination_scan_checkpoint_v1(array $user,array $binding): ?array
{
    if(($binding['enabled']??false)!==true||!is_int($binding['conversation_id']??null)
        ||$binding['conversation_id']<1||!is_string($binding['revision']??null))return null;
    return vp3_initiative_in_scope_v1($user,vp3_initiative_binding_agent_v1($binding),static function()use($user,$binding):?array{
        $state=agent_brain_v122_memory($user,'initiative_sweep','coordination-scan')['metadata']??[];
        $scanned=$state['scanned_at']??null;
        $outcome=$state['outcome']??null;
        if(($state['conversation_id']??null)!==$binding['conversation_id']||($state['revision']??null)!==$binding['revision']
            ||!is_int($scanned)||$scanned<1||$scanned>time()
            ||!in_array($outcome,['sources_checked','queued_work','queued_chat_handoff','queued_private_work','queued_media_offer','queued_checkin',
                'morning_brief_posted','morning_brief_waiting','reminder_posted','no_candidate','handoff_already_handled',
                'handoff_failed','handoff_cancelled','handoff_paused'],true))return null;
        $specialists=[];
        foreach(VP3_COORDINATION_SPECIALISTS_V1 as $family){
            $entry=$state['specialists'][$family]??null;
            $coverage=$entry['coverage']??null;
            if(!is_array($entry)||!in_array($coverage,['ready','limited','unavailable','unconfigured'],true))continue;
            $summary=['coverage'=>$coverage];
            foreach(['found','chat_matches','legacy_handled','candidate_count','queued','already_handled','dedupe_conflicts'] as $count){
                if(is_int($entry[$count]??null)&&$entry[$count]>=0&&$entry[$count]<=120)$summary[$count]=$entry[$count];
            }
            $dispositions=vp3_coordination_scan_disposition_rows_v1($family,$entry['dispositions']??null);
            if($dispositions)$summary['dispositions']=$dispositions;
            $failure=$entry['failure']??null;
            if(in_array($failure,['storage_error','storage_unavailable','provider_unavailable','invalid_internal_type',
                'invalid_data','access_changed','code_error','verification_unavailable'],true))$summary['failure']=$failure;
            $offerGate=$entry['offer_gate']??null;
            $gates=match($family){
                'media'=>['ready','handoff_pending','quiet_chat_required','playing','cooldown','report_only','new_activity_required',
                    'already_offered','no_playable_sources'],
                'curiosity'=>['candidate','ready','handoff_pending','quiet_chat_required','playing','owner_turn_pending','recent_proactive_post',
                    'awaiting_reply','no_question','higher_priority','report_only','already_offered'],
                default=>[],
            };
            if(in_array($offerGate,$gates,true))$summary['offer_gate']=$offerGate;
            $handoffGate=$entry['handoff_gate']??null;
            if($family==='media'&&in_array($handoffGate,['ready','disabled','quiet_chat_required','playing','idle_spacing',
                'new_activity_required','owner_turn_pending','recent_proactive_post','verification_unavailable',
                'no_playable_sources','handoff_pending','handoff_stopped'],true))$summary['handoff_gate']=$handoffGate;
            $specialists[$family]=$summary;
        }
        return ['at'=>gmdate(DATE_ATOM,$scanned),'outcome'=>$outcome,'specialists'=>$specialists];
    });
}

/** The job engine still claims under a row lock. Screen out jobs that are
 * waiting for a retry or a future action before they consume the queue window. */
function vp3_initiative_job_due_sql_v1(PDO $pdo): string
{
    require_once __DIR__.'/agent-job-engine-v1900.php';
    $pause=agent_job_control_ready_v1900($pdo)?" AND TRIM(COALESCE(r.pause_requested_at,''))=''":'';
    return "(r.status IN ('approved','executing') AND COALESCE(r.current_action_id,0)=0
        AND (r.next_attempt_at IS NULL OR r.next_attempt_at<=UTC_TIMESTAMP())
        AND (r.lease_expires_at IS NULL OR r.lease_expires_at<UTC_TIMESTAMP())$pause
        AND EXISTS (
            SELECT 1 FROM agent_workflow_actions a
            WHERE a.id=(SELECT first_action.id FROM agent_workflow_actions first_action
                WHERE first_action.run_id=r.id AND first_action.owner_user_id=r.owner_user_id
                AND first_action.status='queued' ORDER BY first_action.sequence_no,first_action.id LIMIT 1)
            AND a.execution_target='cloud' AND (a.available_at IS NULL OR a.available_at<=UTC_TIMESTAMP())
        ))";
}

function vp3_coordination_pending_v1(PDO $pdo,array $user,array $binding,bool $savedOnly=false,bool $chatOnly=false,?string $specialistOnly=null): bool
{
    $agent=vp3_initiative_binding_agent_v1($binding);
    $agentSql=$agent>0?'r.agent_id=?':'r.agent_id IS NULL';
    $json=static function(string $path)use($pdo):string{
        $value="JSON_EXTRACT(CASE WHEN JSON_VALID(r.goal) THEN r.goal ELSE '{}' END,'$path')";
        return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?$value:"JSON_UNQUOTE($value)";
    };
    $cid=$json('$.binding.conversation_id');
    $revision=$json('$.binding.revision');
    $specialist=$json('$.coordination_route.specialist');
    $action=$json('$.coordination_route.action');
    $saved=$savedOnly?' AND CAST('.$json('$.coordination_saved').' AS CHAR) IN (\'true\',\'1\')':'';
    $handoff=$chatOnly?" AND $specialist IN ('gmail','planning','tasks','canvas','connected-sites','transcripts','marketing','profile') AND $action IN ('ask','report')
        AND ".$json('$.coordination_work')." IS NULL AND ".$json('$.followthrough')." IS NULL":'';
    $familyFilter=$specialistOnly===null?" AND COALESCE($specialist,'') NOT IN ('casual','curiosity')":" AND $specialist=?";
    $due=vp3_initiative_job_due_sql_v1($pdo);
    $query=$pdo->prepare("SELECT 1 FROM agent_workflow_runs r WHERE r.owner_user_id=? AND $agentSql
        AND r.source_kind='agent_initiative' AND r.source_key LIKE 'coordination:%'
        AND r.workflow_type='initiative' AND r.capability_key='initiative.prepare' AND r.execution_target='cloud'
        AND ($due OR (r.status='executing' AND r.lease_expires_at>=UTC_TIMESTAMP()))
        AND CAST($cid AS CHAR)=? AND $revision=?$saved$handoff$familyFilter LIMIT 1");
    $query->execute([(int)$user['id'],...($agent>0?[$agent]:[]),(string)$binding['conversation_id'],$binding['revision'],
        ...($specialistOnly===null?[]:[$specialistOnly])]);
    return $query->fetchColumn()!==false;
}

function vp3_coordination_scan_items_v1(array $snapshot): array
{
    $items=[];
    foreach($snapshot['items']??[] as $ref=>$item){
        if(!is_string($ref)||!vp3_coordination_source_ref_valid_v1($ref)||!is_array($item)||($item['ref']??null)!==$ref
            ||!in_array($item['specialist']??null,VP3_COORDINATION_SPECIALISTS_V1,true)
            ||($item['history_only']??false)===true
            ||!is_string($item['revision']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$item['revision']))continue;
        $items[$ref]=$item;
    }
    // Stable adapter order breaks ties within each specialist.
    uasort($items,static function(array $left,array $right):int{
        $priority=['high'=>0,'normal'=>1,'low'=>2];
        return ($priority[$left['priority']??'normal']??1)<=>($priority[$right['priority']??'normal']??1)
            ?:array_search($left['specialist'],VP3_COORDINATION_SPECIALISTS_V1,true)<=>array_search($right['specialist'],VP3_COORDINATION_SPECIALISTS_V1,true);
    });
    return $items;
}

function vp3_coordination_scan_coverage_v1(mixed $coverage): array
{
    $out=[];
    foreach(['gmail','planning','tasks'] as $specialist){
        $state=is_array($coverage)?($coverage[$specialist]??null):null;
        $out[$specialist]=in_array($state,['ready','limited','unavailable','unconfigured'],true)?$state:'unavailable';
    }
    return $out;
}

/** A receipt, rather than a recent scan or an unverified model draft, marks evidence assessed. */
function vp3_coordination_scan_baseline_v1(PDO $pdo,array $user,array $run): ?array
{
    if(($run['status']??null)!=='completed')return null;
    $goal=json_decode((string)($run['goal']??''),true,32);
    $id=$goal['coordination_id']??null;
    $primary=$goal['primary']??null;
    if(($goal['tool']??null)!=='coordination.review'||!is_string($id)||!preg_match('/\A[a-f0-9]{64}\z/D',$id)
        ||!is_string($primary)||!is_array($goal['sources']??null)||!isset($goal['sources'][$primary]))return null;
    $result=vp3_coordination_verified_run_v1($pdo,$user,$run);
    $output=$result['initiative_tool_result']['output']??null;
    $revisions=$output['evidence_revisions']??null;
    if(($output['type']??null)!=='initiative_coordination'||($output['situation_id']??null)!==$id
        ||!is_array($revisions)||!isset($revisions[$primary])||count($revisions)>5
        ||array_diff_assoc($revisions,$goal['sources']))return null;
    ksort($revisions);
    foreach(array_keys($revisions) as $ref){
        if(!vp3_coordination_source_ref_valid_v1($ref))return null;
    }
    return ['id'=>$id,'primary'=>$primary,'revisions'=>$revisions,'offered'=>$goal['sources'],
        'coverage'=>vp3_coordination_scan_coverage_v1($goal['coordination_coverage']??null),
        'route'=>$goal['coordination_route']??null,'routes'=>is_array($goal['coordination_routes']??null)?$goal['coordination_routes']:[],
        'material'=>isset($goal['followthrough'])||isset($goal['coordination_work'])?null:($goal['coordination_material']??null),
        'policy_revision'=>$goal['binding']['revision']??null,'delivery'=>$result['initiative_delivery']??null,
        'requires_owner'=>($output['state']??null)==='needs_owner'&&in_array($result['initiative_delivery']??null,['pending','delivered'],true),
        'unposted_handoff'=>!isset($goal['followthrough'])&&!isset($goal['coordination_work'])
            &&in_array($result['initiative_delivery']??null,['pending','withheld'],true),
        'transcript_history'=>($result['initiative_delivery_reason']??null)==='transcript_history',
        'receipt'=>[$run,$result],
        'run_id'=>(int)$run['id']];
}

/** A prior policy cannot deliver its pending Chat handoff after Pause or reconfiguration. */
function vp3_coordination_scan_needs_delivery_v1(PDO $pdo,array $user,array $prior,array $binding,array $route,array $items): bool
{
    if(!in_array($route['action'],['ask','report'],true))return false;
    if(!empty($prior['transcript_history'])||($prior['unposted_handoff']&&$prior['policy_revision']!==$binding['revision']))return true;
    [$run,$result]=$prior['receipt'];
    return vp3_coordination_handoff_repairable_v1($pdo,$user,$run,$result,$items)===true;
}

function vp3_coordination_scan_sources_v1(array $items,string $primary,array $linked): array
{
    $sources=[$primary=>$items[$primary]['revision']];
    $related=$items[$primary]['related_ref']??null;
    if(is_string($related)&&isset($items[$related]))$sources[$related]=$items[$related]['revision'];
    foreach($linked as $ref=>$revision){
        if(count($sources)>=5)break;
        if(isset($items[$ref]))$sources[$ref]=$items[$ref]['revision'];
    }
    // Favor representation from every available specialist, then add nearby context.
    $families=[];
    foreach(array_keys($sources) as $ref)$families[$items[$ref]['specialist']]=true;
    foreach($items as $ref=>$item){
        if(count($sources)>=5)break;
        if(isset($families[$item['specialist']]))continue;
        $sources[$ref]=$item['revision'];
        $families[$item['specialist']]=true;
    }
    foreach($items as $ref=>$item){
        if(count($sources)>=5)break;
        $sources[$ref]=$item['revision'];
    }
    ksort($sources);
    return $sources;
}

/** Reserve the small evidence budget for reports that actually overlap the Gmail finding. */
function vp3_coordination_scan_gmail_ask_sources_v1(array $items,string $primary,array $related): array
{
    $chosen=[$primary=>true];
    $families=[];
    foreach($related as $ref=>$terms){
        $family=$items[$ref]['specialist'];
        if(!$terms||isset($families[$family]))continue;
        $chosen[$ref]=true;
        $families[$family]=true;
    }
    foreach(array_keys($chosen) as $ref){
        $counterpart=$items[$ref]['related_ref']??null;
        if(is_string($counterpart)&&isset($related[$counterpart]))$chosen[$counterpart]=true;
    }
    $chosen+=array_slice($related,0,4,true);
    $sources=array_column(array_intersect_key($items,array_slice($chosen,0,5,true)),'revision','ref');
    ksort($sources);
    return $sources;
}

/** Compare the bounded, verified Gmail context with the evidence offered before. */
function vp3_coordination_scan_gmail_context_changed_v1(array $prior,array $route,array $items,string $primary): bool
{
    if(!$prior['requires_owner']||!vp3_coordination_gmail_ask_v1($route))return false;
    $related=vp3_coordination_gmail_related_v1($items[$primary],$items);
    $sources=vp3_coordination_scan_gmail_ask_sources_v1($items,$primary,$related);
    unset($sources[$primary]);
    return array_diff_assoc($sources,$prior['offered'])!==[];
}

/** Bounded discovery can omit an older source that still belongs to an open finding. */
function vp3_coordination_scan_recover_refs_v1(PDO $pdo,array $user,array $binding,array $items,array $baselines): array
{
    $missing=[];
    foreach($baselines as $prior){
        foreach($prior['revisions'] as $ref=>$revision)if(!isset($items[$ref]))$missing[$ref]=true;
        if(count($missing)>=10)break;
    }
    $checked=array_slice(array_keys($missing),0,10);
    foreach(array_chunk($checked,5) as $refs){
        $exact=vp3_coordination_sources_v1($pdo,$user,$binding,$refs);
        $items+=array_intersect_key(vp3_coordination_scan_items_v1($exact),array_fill_keys($refs,true));
    }
    return [$items,array_fill_keys($checked,true)];
}

/** Run inside the existing Initiative owner transaction, with one admission per pass. */
function vp3_coordination_schedule_v1(PDO $pdo,array $user,array $binding,bool $executionReady=true,?string &$phase=null,
    bool $observeOnly=false,bool $allowDelivery=true): string
{
    if(!$pdo->inTransaction()||!vp3_coordination_scan_due_v1($user,$binding))return 'not_due';
    return vp3_initiative_in_scope_v1($user,vp3_initiative_binding_agent_v1($binding),static function()use($pdo,$user,$binding,$executionReady,&$phase,$observeOnly,$allowDelivery):string{
        $live=vp3_initiative_access_v1($pdo,$user,$binding);
        // Gmail invalidates the shared scan checkpoint when a finding changes. Keep the
        // older Tasks page separate so frequent mail cannot restart its progress.
        $officeState=agent_brain_v122_memory($live,'initiative_sweep','coordination-office-page')['metadata']??[];
        $before=$officeState['before']??0;
        if(($officeState['conversation_id']??null)!==$binding['conversation_id']||($officeState['revision']??null)!==$binding['revision']
            ||!is_int($before)||$before<0)$before=0;
        $next=$before;
        $matches=[];
        if($observeOnly){
            $phase='specialist_scan';
            $snapshot=vp3_coordination_sources_v1($pdo,$live,$binding,officeBefore:$before);
            $discovery=vp3_coordination_discovery_summary_v1($snapshot);
            $settings=vp3_coordination_rules_v1($pdo,$live,vp3_initiative_binding_agent_v1($binding));
            $routes=array_map(static fn(array $item):array=>vp3_coordination_item_route_v1($settings,$item),
                vp3_coordination_scan_items_v1($snapshot));
            $matches=vp3_coordination_scan_matches_v1($routes,'observe_only');
            $outcome='sources_checked';
        }else{
            $phase='queue_check';
            $pending=vp3_coordination_pending_v1($pdo,$live,$binding,!$executionReady);
            $phase='candidate_selection';
            $discovery=[];
            $outcome=vp3_coordination_admit_v1($pdo,$live,$binding,$executionReady,$pending,$discovery,$before,$next,$matches,$allowDelivery);
            if($pending&&$outcome==='no_candidate')$outcome='queued_work';
        }
        foreach(vp3_coordination_scan_dispositions_v1($matches) as $family=>$rows){
            if(isset($discovery[$family]))$discovery[$family]['dispositions']=[
                ...$rows,...($discovery[$family]['dispositions']??[]),
            ];
        }
        $phase='checkpoint';
        if($next!==$before&&agent_brain_v122_upsert_system_memory($live,'initiative_sweep','coordination-office-page','Tasks report scan position.',[
            'conversation_id'=>$binding['conversation_id'],'revision'=>$binding['revision'],'before'=>$next,
        ])<1)throw new RuntimeException('Tasks report scan position could not be saved.',503);
        if(agent_brain_v122_upsert_system_memory($live,'initiative_sweep','coordination-scan','Top of Mind source scan.',[
            'conversation_id'=>$binding['conversation_id'],'revision'=>$binding['revision'],'scanned_at'=>time(),
            'outcome'=>$outcome,'specialists'=>$discovery,
        ])<1)throw new RuntimeException('Top of Mind scan checkpoint could not be saved.',503);
        return $outcome;
    });
}

function vp3_coordination_admit_v1(PDO $pdo,array $user,array $binding,bool $executionReady,bool $pending,array &$discovery,
    int $officeBefore,int &$nextOfficeBefore,array &$matches,bool $allowDelivery): string
{
    $snapshot=vp3_coordination_sources_v1($pdo,$user,$binding,officeBefore:$officeBefore);
    $officePage=$snapshot['office_page']??null;
    if($officePage!==null)$nextOfficeBefore=$officePage['next']??0;
    $discovery=vp3_coordination_discovery_summary_v1($snapshot);
    foreach($discovery as &$family){
        $family+=['chat_matches'=>0,'legacy_handled'=>0,'candidate_count'=>0,'queued'=>0,'already_handled'=>0,'dedupe_conflicts'=>0];
    }
    unset($family);
    $items=vp3_coordination_scan_items_v1($snapshot);
    $coverage=vp3_coordination_scan_coverage_v1($snapshot['coverage']??null);
    $baselines=[];
    foreach(vp3_coordination_runs_v1($pdo,$user,$binding) as $run){
        $key=$run['source_key']??'';
        if(is_string($key)&&isset($baselines[substr($key,strlen('coordination:'))]))continue;
        $prior=vp3_coordination_scan_baseline_v1($pdo,$user,$run);
        if($prior!==null&&!in_array($prior['route']['specialist']??null,['casual','curiosity'],true))$baselines[$prior['id']]=$prior;
    }
    [$items,$checked]=vp3_coordination_scan_recover_refs_v1($pdo,$user,$binding,$items,$baselines);
    if(!$items){
        if($allowDelivery&&($recurring=vp3_coordination_admit_recurring_v1($pdo,$user,$binding,$snapshot))!==null)return $recurring;
        return vp3_coordination_admit_casual_v1($pdo,$user,$binding,$snapshot['items'],$coverage,$discovery,$pending,
            allowStartersWithPrivateWork:true)??'no_candidate';
    }
    $settings=vp3_coordination_rules_v1($pdo,$user,vp3_initiative_binding_agent_v1($binding));
    $sharedContext=array_filter($items,static fn(array $item):bool=>!in_array($item['specialist'],['media','canvas','curiosity','connected-sites','transcripts','marketing'],true)
        &&$item['ref']!==VP3_COORDINATION_PLANNING_START_V1);
    $routes=array_map(static fn(array $item):array=>vp3_coordination_item_route_v1($settings,$item),$items);
    $matches=vp3_coordination_scan_matches_v1($routes);
    if(($discovery['curiosity']['offer_gate']??null)==='candidate'){
        $discovery['curiosity']['offer_gate']='higher_priority';
    }
    [$materials,$covered]=vp3_coordination_material_discovery_v1($items,$routes);
    foreach($routes as $ref=>$route){
        if(!vp3_coordination_chat_route_v1($route)
            &&!(in_array($route['specialist'],['media','curiosity'],true)&&in_array($route['action'],['ask','report'],true)))continue;
        ++$discovery[$route['specialist']]['chat_matches'];
        if(!empty($items[$ref]['legacy_delivered'])){
            ++$discovery[$route['specialist']]['legacy_handled'];
            vp3_coordination_scan_mark_v1($matches,$ref,'already_handled');
        }
    }
    $recorded=[];
    $record=static function(string $code,string $family,string $ref)use(&$discovery,&$recorded,&$matches):void{
        if(isset($recorded[$code][$ref]))return;
        if($code==='candidate_count'&&isset($recorded['already_handled'][$ref]))return;
        if($code==='already_handled'&&isset($recorded['candidate_count'][$ref])){
            unset($recorded['candidate_count'][$ref]);
            --$discovery[$family]['candidate_count'];
        }
        $recorded[$code][$ref]=true;
        ++$discovery[$family][$code];
        if(in_array($code,['already_handled','dedupe_conflicts'],true))vp3_coordination_scan_mark_v1($matches,$ref,$code);
    };
    $mediaChecked=[];
    $mediaHandled=static function(string $ref)use($pdo,$user,$binding,$items,&$mediaChecked,$record):bool{
        $handled=$mediaChecked[$ref]??=vp3_media_offer_previously_delivered_v1($pdo,$user,['binding'=>$binding,'primary'=>$ref,
            'sources'=>[$ref=>$items[$ref]['revision']]]);
        if($handled)$record('already_handled','media',$ref);
        return $handled;
    };
    $candidates=[];$linked=[];$skipped='no_candidate';
    foreach($covered as $ref=>$_){
        $record('already_handled',$routes[$ref]['specialist'],$ref);
        $skipped='handoff_already_handled';
    }
    foreach($baselines as $prior){
        $unchecked=array_diff_key($prior['revisions'],$items,$checked)!==[];
        foreach($prior['revisions'] as $ref=>$revision){
            // Supporting evidence does not complete its own separate Ask or
            // Report rule, even if another specialist already posted it.
            if($ref!==$prior['primary']&&vp3_coordination_chat_route_v1($routes[$ref]??[]))continue;
            $linked[$ref]=true;
        }
        if($unchecked){
            $primary=$prior['primary'];
            $route=$routes[$primary]??[];
            if(!vp3_coordination_chat_route_v1($route))continue;
            // The primary can advance on exact evidence even when the bounded
            // history lookup stopped before its optional supporting records.
            if($items[$primary]['revision']===$prior['revisions'][$primary]&&$route===$prior['route']
                &&!vp3_coordination_scan_gmail_context_changed_v1($prior,$route,$items,$primary)
                &&!vp3_coordination_scan_needs_delivery_v1($pdo,$user,$prior,$binding,$route,$items)){
                $record('already_handled',$route['specialist'],$primary);
                if($prior['delivery']==='pending')vp3_coordination_scan_mark_v1($matches,$primary,'existing_handoff');
                if($skipped==='no_candidate')$skipped='handoff_already_handled';
                continue;
            }
            $candidates[]=['id'=>$prior['id'],'primary'=>$primary,'linked'=>$prior['revisions'],
                'priority'=>$items[$primary]['priority'],'previous'=>$prior['run_id']];
            continue;
        }
        $available=array_intersect_key($items,$prior['revisions']);
        if(!$available)continue;
        $versions=array_column($available,'revision','ref');
        ksort($versions);
        $primary=isset($available[$prior['primary']])?$prior['primary']:array_key_first($available);
        foreach($available as $ref=>$item){
            if($ref!==$prior['primary']&&vp3_coordination_chat_route_v1($routes[$ref])
                &&in_array($prior['delivery'],['pending','delivered'],true))continue;
            if($routes[$ref]['action']!=='watch'&&($item['revision']!==$prior['revisions'][$ref]
                ||$routes[$ref]!==($prior['routes'][$ref]??null))){$primary=$ref;break;}
        }
        $currentRoutes=array_intersect_key($routes,$versions);ksort($currentRoutes);
        $previousRoutes=array_intersect_key($prior['routes'],$versions);ksort($previousRoutes);
        $newRelated=vp3_coordination_scan_gmail_context_changed_v1($prior,$routes[$primary],$items,$primary);
        // An exact prepared handoff already waiting for Chat owns this result.
        // A coverage change by itself gives no new evidence to report.
        $sameCoverage=$primary===VP3_COORDINATION_PLANNING_START_V1||$prior['delivery']==='pending'||$coverage===$prior['coverage'];
        if($versions===$prior['revisions']&&$sameCoverage&&$currentRoutes===$previousRoutes
            &&$routes[$primary]===$prior['route']&&!$newRelated&&!vp3_coordination_scan_needs_delivery_v1($pdo,$user,$prior,$binding,$routes[$primary],$items)){
            if($routes[$primary]['specialist']==='media')$mediaHandled($primary);
            if(vp3_coordination_chat_route_v1($routes[$primary])){
                $record('already_handled',$routes[$primary]['specialist'],$primary);
                if($prior['delivery']==='pending')vp3_coordination_scan_mark_v1($matches,$primary,'existing_handoff');
                if($skipped==='no_candidate')$skipped='handoff_already_handled';
            }
            continue;
        }
        $priority=in_array('high',array_column($available,'priority'),true)?'high':'normal';
        $candidates[]=['id'=>$prior['id'],'primary'=>$primary,'linked'=>$prior['revisions'],'priority'=>$priority,'previous'=>$prior['run_id']];
        $linked[$primary]=true;
    }
    foreach($items as $ref=>$item){
        if(isset($linked[$ref])||$routes[$ref]['action']==='watch')continue;
        $id=vp3_coordination_scan_id_v1($user,$binding,$materials[$ref]['key']??$ref);
        $candidates[]=['id'=>$id,'primary'=>$ref,'linked'=>[],
            'priority'=>($item['priority']??null)==='high'?'high':'normal','previous'=>0];
    }
    $mediaHeld=null;$mediaPending=null;
    $mediaMatched=array_filter($routes,static fn(array $route):bool=>$route['specialist']==='media'&&in_array($route['action'],['ask','report'],true));
    $transcriptHolds=[];$transcriptPaced=null;
    $olderOfficeCandidates=[];
    $olderOfficeRefs=array_fill_keys($officePage['refs']??[],true);
    foreach($candidates as $candidate){
        $primary=$candidate['primary'];
        if(isset($covered[$primary]))continue;
        if(vp3_coordination_profile_presentation_owned_v1(['primary'=>$primary],$items)){
            vp3_coordination_scan_mark_v1($matches,$primary,'native_presentation');
            continue;
        }
        if($routes[$primary]['specialist']==='transcripts'&&vp3_coordination_chat_route_v1($routes[$primary])){
            $hold=vp3_coordination_transcript_handoff_hold_v1($pdo,$user,$binding,$items[$primary],$transcriptPaced);
            if($hold!==null){
                $transcriptHolds[$primary]=true;
                vp3_coordination_scan_mark_v1($matches,$primary,$hold);
                continue;
            }
        }
        $curiosityAsk=$routes[$primary]['specialist']==='curiosity'&&$routes[$primary]['action']==='ask';
        if((vp3_coordination_chat_route_v1($routes[$primary])||isset($mediaMatched[$primary])||$curiosityAsk)
            &&empty($items[$primary]['legacy_delivered'])){
            $record('candidate_count',$routes[$primary]['specialist'],$primary);
        }
        if(isset($olderOfficeRefs[$primary])&&empty($items[$primary]['legacy_delivered'])
            &&(vp3_coordination_chat_route_v1($routes[$primary])||($executionReady&&$routes[$primary]['action']==='prepare'))){
            $olderOfficeCandidates[$primary]=true;
        }
    }
    usort($candidates,static function(array $left,array $right)use($items,$routes):int{
        $rank=static fn(array $candidate):int=>match(true){
            $items[$candidate['primary']]['specialist']==='curiosity'=>8,
            $candidate['primary']===VP3_COORDINATION_PLANNING_START_V1=>7,
            $items[$candidate['primary']]['specialist']==='transcripts'&&vp3_coordination_chat_route_v1($routes[$candidate['primary']])=>6,
            vp3_coordination_chat_route_v1($routes[$candidate['primary']])=>$candidate['priority']==='high'?0:1,
            ($items[$candidate['primary']]['trigger']??null)==='resume'=>4,
            ($items[$candidate['primary']]['trigger']??null)==='recent_search'=>5,
            ($items[$candidate['primary']]['trigger']??null)==='catalog_pick'=>6,
            default=>$items[$candidate['primary']]['specialist']==='media'?3:($candidate['priority']==='high'?2:9),
        };
        return $rank($left)<=>$rank($right);
    });
    $briefPriorityChecked=false;$recurringChecked=false;$idleMediaChecked=false;$historyChecked=false;$pendingSpecialists=[];
    foreach($candidates as $candidate){
        $primary=$candidate['primary'];$route=$routes[$primary];
        if(isset($covered[$primary])||isset($transcriptHolds[$primary]))continue;
        if(vp3_coordination_profile_presentation_owned_v1(['primary'=>$primary],$items)){
            vp3_coordination_scan_mark_v1($matches,$primary,'native_presentation');
            continue;
        }
        $media=$items[$primary]['specialist']==='media';
        $curiosity=$items[$primary]['specialist']==='curiosity';
        $planningStart=$primary===VP3_COORDINATION_PLANNING_START_V1;
        if($planningStart&&!in_array($route['action'],['ask','report'],true))continue;
        $handoff=vp3_coordination_chat_route_v1($route)&&empty($items[$primary]['legacy_delivered']);
        if(!empty($items[$primary]['legacy_delivered'])&&vp3_coordination_chat_route_v1($route)){
            vp3_coordination_scan_mark_v1($matches,$primary,'already_handled');
            if($skipped==='no_candidate')$skipped='handoff_already_handled';
            continue;
        }
        // Delivery is still serialized in Chat. Only the owning specialist's
        // queued work can hold its next preparation.
        if($pending&&!$media&&!$curiosity&&($pendingSpecialists[(int)$handoff][$route['specialist']]
            ??=vp3_coordination_pending_v1($pdo,$user,$binding,!$executionReady,$handoff,$route['specialist']))){
            vp3_coordination_scan_mark_v1($matches,$primary,'handoff_pending');
            if(isset($olderOfficeCandidates[$primary]))$nextOfficeBefore=$officePage['before'];
            if($skipped==='no_candidate')$skipped='queued_work';
            continue;
        }
        if($allowDelivery&&!$briefPriorityChecked&&(!$handoff||$candidate['priority']!=='high')){
            $briefPriorityChecked=true;
            $brief=vp3_coordination_try_morning_brief_v1($pdo,$user,$binding,$snapshot,true);
            if($brief==='morning_brief_posted'){
                if($olderOfficeCandidates)$nextOfficeBefore=$officePage['before'];
                return $brief;
            }
        }
        if($handoff&&vp3_coordination_other_chat_delivered_v1($pdo,$user,
            ['binding'=>$binding,'primary'=>$primary,'coordination_route'=>$route],
            ['evidence_revisions'=>[$primary=>$items[$primary]['revision']],'state'=>$route['action']==='ask'?'needs_owner':'watching'])){
            $record('already_handled',$route['specialist'],$primary);
            unset($olderOfficeCandidates[$primary]);
            if($skipped==='no_candidate')$skipped='handoff_already_handled';
            continue;
        }
        if($media){
            // A report-only media source may still appear in discovery. Its old
            // receipt must not queue work after the owner disables the offer.
            if($route['action']==='watch')continue;
            if($mediaHandled($primary))continue;
            if(!$historyChecked&&($route['trigger']??null)==='catalog_pick'&&$route['action']==='ask'){
                $historyChecked=true;
                $history=vp3_coordination_admit_casual_v1($pdo,$user,$binding,$snapshot['items'],$coverage,$discovery,
                    mediaOnly:true,historyOnly:true);
                if($history!==null){
                    if($olderOfficeCandidates)$nextOfficeBefore=$officePage['before'];
                    return $history;
                }
            }
            $mediaPending??=vp3_coordination_casual_media_pending_v1($pdo,$user,$binding);
            if($mediaPending){
                $discovery['media']['offer_gate']=$mediaHeld='handoff_pending';
                vp3_coordination_scan_mark_v1($matches,$primary,'handoff_pending');
                continue;
            }
            if(!vp3_media_chat_ready_v1($pdo,$user,['coordination_route'=>$route,'binding'=>$binding],$hold)){
                $mediaHeld??=$hold;
                $discovery['media']['offer_gate']=$mediaHeld;
                vp3_coordination_scan_mark_v1($matches,$primary,$hold);
                continue;
            }
        }
        $routinePreparation=$route['action']==='prepare'&&$candidate['priority']!=='high';
        if($allowDelivery&&!$recurringChecked&&($curiosity||$routinePreparation)){
            $recurringChecked=true;
            $recurring=vp3_coordination_admit_recurring_v1($pdo,$user,$binding,$snapshot);
            if($recurring!==null){
                if($olderOfficeCandidates)$nextOfficeBefore=$officePage['before'];
                return $recurring;
            }
        }
        if(!$idleMediaChecked&&$routinePreparation){
            $idleMediaChecked=true;
            // Low-priority background drafts may be continuously available. Leave an eligible quiet slot for Media.
            $idle=vp3_coordination_admit_casual_v1($pdo,$user,$binding,$snapshot['items'],$coverage,$discovery,true);
            if($idle!==null){
                if($olderOfficeCandidates)$nextOfficeBefore=$officePage['before'];
                return $idle;
            }
        }
        if($curiosity){
            if(vp3_coordination_pending_v1($pdo,$user,$binding,specialistOnly:'curiosity')){
                $discovery['curiosity']['offer_gate']='handoff_pending';
                vp3_coordination_scan_mark_v1($matches,$primary,'handoff_pending');
                continue;
            }
            if(!$idleMediaChecked){
                $idleMediaChecked=true;
                $suggestion=vp3_coordination_admit_casual_v1($pdo,$user,$binding,$snapshot['items'],$coverage,$discovery,mediaOnly:true);
                if($suggestion!==null){
                    $discovery['curiosity']['offer_gate']='higher_priority';
                    return $suggestion;
                }
            }
            $gate=vp3_coordination_curiosity_gate_v1($pdo,$user,$binding);
            $discovery['curiosity']['offer_gate']=$gate;
            if($gate!=='ready'){
                vp3_coordination_scan_mark_v1($matches,$primary,$gate);
                continue;
            }
        }
        if(!$executionReady&&!$media&&!in_array($route['action'],['ask','report'],true)){
            vp3_coordination_scan_mark_v1($matches,$primary,'route_unavailable');
            continue;
        }
        // Keep the one-situation guarantee beyond the recent window by checking the indexed anchor.
        if($candidate['previous']===0){
            foreach(vp3_coordination_runs_v1($pdo,$user,$binding,$candidate['id'],4) as $run){
                $prior=vp3_coordination_scan_baseline_v1($pdo,$user,$run);
                if($prior===null)continue;
                [$items]=vp3_coordination_scan_recover_refs_v1($pdo,$user,$binding,$items,[$prior]);
                $available=array_intersect_key($items,$prior['revisions']);
                $versions=array_column($available,'revision','ref');
                ksort($versions);
                // The original anchor may return while the other evidence and coverage stay the same.
                $sameCoverage=$planningStart||$prior['delivery']==='pending'||$coverage===$prior['coverage'];
                if($versions===$prior['revisions']&&$sameCoverage&&$route===$prior['route']
                    &&($prior['offered'][$candidate['primary']]??null)===$items[$candidate['primary']]['revision']
                    &&!vp3_coordination_scan_gmail_context_changed_v1($prior,$route,$items,$candidate['primary'])
                    &&!vp3_coordination_scan_needs_delivery_v1($pdo,$user,$prior,$binding,$route,$items)){
                    if($handoff){
                        $record('already_handled',$route['specialist'],$primary);
                        if($prior['delivery']==='pending')vp3_coordination_scan_mark_v1($matches,$primary,'existing_handoff');
                        unset($olderOfficeCandidates[$primary]);
                        if($skipped==='no_candidate')$skipped='handoff_already_handled';
                    }
                    continue 2;
                }
                $candidate['linked']=$prior['revisions'];
                $candidate['previous']=$prior['run_id'];
                break;
            }
        }
        $canUseFeedback=vp3_coordination_gmail_ask_v1($route)||isset($items[$primary]['response_revision'])
            ||($route['specialist']==='planning'&&isset($materials[$primary]));
        if($candidate['previous']!==0&&$route['action']==='ask'&&$canUseFeedback){
            require_once __DIR__.'/agent-coordination-finding-feedback-v1.php';
            if(vp3_coordination_finding_feedback_v1($pdo,$user,$binding,$items[$primary],answeredOnly:true)!==null){
                $record('already_handled',$route['specialist'],$primary);
                unset($olderOfficeCandidates[$primary]);
                if($skipped==='no_candidate')$skipped='handoff_already_handled';
                continue;
            }
        }
        $gmailAsk=vp3_coordination_gmail_ask_v1($route);
        $material=$materials[$primary]??null;
        $materialRefs=$material['planning_refs']??[];
        $savedTaskQuestion=$route['specialist']==='tasks'&&$route['action']==='ask'&&$items[$primary]['question']!=='';
        $taskContext=$savedTaskQuestion&&$executionReady?vp3_coordination_task_list_context_v1($items,$routes,$primary):null;
        $standalone=$media||$curiosity||($savedTaskQuestion&&$taskContext===null)||$planningStart
            ||in_array($route['specialist'],['canvas','connected-sites','transcripts','marketing','profile'],true);
        $sources=$standalone?[$primary=>$items[$primary]['revision']]
            :match(true){
                $taskContext!==null=>[$primary=>$items[$primary]['revision'],$taskContext=>$items[$taskContext]['revision']],
                $gmailAsk=>vp3_coordination_scan_gmail_ask_sources_v1($items,$primary,vp3_coordination_gmail_related_v1($items[$primary],$items)),
                $route['specialist']==='planning'&&$route['trigger']==='conflict'&&$materialRefs!==[]=>[$primary=>$items[$primary]['revision']],
                default=>vp3_coordination_scan_sources_v1($sharedContext,$primary,$candidate['linked']),
            };
        if($taskContext!==null)ksort($sources);
        if($materialRefs){
            $required=array_column(array_intersect_key($items,array_fill_keys([$primary,...$materialRefs],true)),'revision','ref');
            $sources=array_slice($required+$sources,0,5,true);
            ksort($sources);
        }
        $priorMaterial=$baselines[$candidate['id']]['material']??null;
        $sameMaterial=$material!==null&&($priorMaterial['revision']??null)===$material['revision']
            &&($baselines[$candidate['id']]['route']??null)===$route;
        $saved=$standalone||(in_array($route['action'],['ask','report'],true)&&
            ($sameMaterial||!$executionReady||count($sources)===1));
        if($saved&&!$gmailAsk&&!$materialRefs){
            $related=$items[$primary]['related_ref']??null;
            $sources=array_intersect_key($sources,array_fill_keys(array_filter([$primary,$related]),true));
        }
        $work=['binding'=>$binding,'tool'=>'coordination.review','coordination_id'=>$candidate['id'],
            'primary'=>$primary,'sources'=>$sources,'focus'=>'Synthesize the current specialist reports.',
            'report'=>true,'coordination_coverage'=>$coverage,'coordination_priority'=>$curiosity?'low':$candidate['priority'],
            'coordination_route'=>$route,'coordination_routes'=>array_intersect_key($routes,$sources),'coordination_saved'=>$saved];
        if($taskContext!==null)$work['coordination_task_context']=$taskContext;
        if($material!==null){
            $work['coordination_id']=vp3_coordination_scan_id_v1($user,$binding,$material['key']);
            $work['coordination_material']=$material;
        }
        if($route['action']==='ask'&&isset($items[$primary]['response_revision'])){
            $work['coordination_response_revision']=$items[$primary]['response_revision'];
        }
        $dedupeParts=['coordination.route.v1',$work['coordination_id'],$primary,$sources,$coverage,$route,$saved,
            $binding['revision'],$candidate['previous']];
        if($material!==null)$dedupeParts[]=$material;
        $dedupe=hash('sha256',json_encode($dedupeParts,JSON_THROW_ON_ERROR));
        if(vp3_initiative_enqueue_v1($pdo,$user,'initiative.prepare',$work,$dedupe)){
            if(($handoff||$curiosity||isset($mediaMatched[$primary]))&&!isset($recorded['candidate_count'][$primary])){
                ++$discovery[$route['specialist']]['candidate_count'];
            }
            ++$discovery[$route['specialist']]['queued'];
            vp3_coordination_scan_mark_v1($matches,$primary,'queued');
            if($media)$discovery['media']['offer_gate']='ready';
            unset($olderOfficeCandidates[$primary]);
            // A different assignment won the slot. Revisit the older page before advancing.
            if($olderOfficeCandidates)$nextOfficeBefore=$officePage['before'];
            return $handoff||$curiosity?'queued_chat_handoff':($media?'queued_media_offer':'queued_private_work');
        }
        unset($olderOfficeCandidates[$primary]);
        if($handoff){
            $record('dedupe_conflicts',$route['specialist'],$primary);
            $prior=$pdo->prepare('SELECT status FROM agent_workflow_runs WHERE owner_user_id=? AND dedupe_key=?');
            $prior->execute([(int)$user['id'],hash('sha256','initiative.prepare|'.$dedupe)]);
            $handoffResult=match($prior->fetchColumn()){
                'failed'=>'handoff_failed','cancelled'=>'handoff_cancelled',
                'paused'=>'handoff_paused','approved','queued','executing'=>'queued_work',default=>null,
            };
            if($handoffResult!==null){
                $skipped=$handoffResult;
                vp3_coordination_scan_mark_v1($matches,$primary,$handoffResult==='queued_work'?'handoff_pending':$handoffResult);
            }
        }else{
            vp3_coordination_scan_mark_v1($matches,$primary,'dedupe_conflicts');
        }
    }
    if($mediaMatched&&count($mediaMatched)===($discovery['media']['already_handled']??0)
        &&$mediaHeld===null&&($discovery['media']['offer_gate']??null)==='ready'){
        $discovery['media']['offer_gate']='already_offered';
    }elseif(!$mediaMatched&&($discovery['media']['offer_gate']??null)==='ready'){
        $discovery['media']['offer_gate']='report_only';
    }
    if($allowDelivery&&!$recurringChecked&&($recurring=vp3_coordination_admit_recurring_v1($pdo,$user,$binding,$snapshot))!==null)return $recurring;
    if(($casual=vp3_coordination_admit_casual_v1($pdo,$user,$binding,$snapshot['items'],$coverage,$discovery,$pending,
        allowStartersWithPrivateWork:true))!==null)return $casual;
    return $skipped;
}

/** Recurring delivery shares the ordinary specialist pass and yields to fresh findings. */
function vp3_coordination_admit_recurring_v1(PDO $pdo,array $user,array $binding,array $snapshot): ?string
{
    $brief=vp3_coordination_try_morning_brief_v1($pdo,$user,$binding,$snapshot);
    if($brief!==null)return $brief;
    require_once __DIR__.'/agent-coordination-reminders-v1.php';
    return vp3_coordination_post_reminder_v1($pdo,$user,$binding)?'reminder_posted':null;
}

/** A brief failure must leave the existing worker cycle and its transaction usable. */
function vp3_coordination_try_morning_brief_v1(PDO $pdo,array $user,array $binding,array $snapshot,bool $prioritySlot=false): ?string
{
    if(!$pdo->inTransaction())return null;
    try{
        require_once __DIR__.'/agent-morning-brief-v1.php';
        $posted=agent_commerce_atomic_pa2($pdo,static function()use($pdo,$user,$binding,$snapshot,$prioritySlot):bool{
            return vp3_morning_brief_v1($pdo,$user,$binding,$snapshot,prioritySlot:$prioritySlot);
        });
        return $posted?'morning_brief_posted':null;
    }catch(Throwable $error){
        if(!$pdo->inTransaction()||($error instanceof PDOException&&((string)$error->getCode()==='40001'
            ||in_array((int)($error->errorInfo[1]??0),[1020,1205,1213],true))))throw $error;
        vp3_initiative_health_diagnostic_v1($error,'morning_brief',(int)$user['id'],vp3_initiative_binding_agent_v1($binding),
            $binding['conversation_id']);
        return null;
    }
}

/** Leave an already assigned Media invitation to the canonical worker and delivery pass. */
function vp3_coordination_casual_media_pending_v1(PDO $pdo,array $user,array $binding): bool
{
    if(vp3_coordination_pending_v1($pdo,$user,$binding,specialistOnly:'media'))return true;
    require_once __DIR__.'/agent-casual-checkin-v1.php';
    foreach(vp3_coordination_runs_v1($pdo,$user,$binding,null,36,'casual') as $run){
        $goal=json_decode((string)$run['goal'],true);
        if(($goal['binding']??null)!==$binding||!in_array($goal['coordination_route']['trigger']??null,VP3_CASUAL_MEDIA_KINDS_V1,true))continue;
        if(in_array($run['status'],['approved','queued','executing'],true))return true;
        if($run['status']==='completed'){
            $receipt=vp3_coordination_verified_run_v1($pdo,$user,$run);
            if(($receipt['initiative_delivery']??null)==='pending'&&($receipt['initiative_delivery_reason']??null)!=='result_unavailable')return true;
        }
    }
    return false;
}

/** A casual invitation is considered only after the higher-priority specialist pass. */
function vp3_coordination_admit_casual_v1(PDO $pdo,array $user,array $binding,array $items,array $coverage,array &$discovery,
    bool $mediaOnly=false,bool $allowStartersWithPrivateWork=false,bool $historyOnly=false): ?string
{
    require_once __DIR__.'/agent-casual-checkin-v1.php';
    $cid=$binding['conversation_id'];$now=time();$owner=(int)$user['id'];$activityAt=0;
    $mediaEnabled=vp3_casual_checkin_preference_v1($pdo,$owner)!==false;
    $agent=vp3_initiative_binding_agent_v1($binding);
    $hold=vp3_initiative_report_gate_v1($pdo,$user,$cid,$agent);
    if($hold!=='ready'){
        if($mediaEnabled&&!$historyOnly)$discovery['media']['handoff_gate']=$hold;
        return null;
    }
    $historyGate=$mediaEnabled?vp3_casual_checkin_history_gate_v1($pdo,$user,$agent):'disabled';
    $allowHistory=$historyGate==='ready';
    $quiet=!$historyOnly&&vp3_media_quiet_chat_v1($pdo,$user,$cid,$hold);
    $allowMedia=$quiet&&$mediaEnabled
        && vp3_casual_checkin_media_idle_v1($pdo,$user,$cid,vp3_casual_checkin_delivery_v1($pdo,$owner),$now,$hold,$activityAt);
    if(!$historyOnly)$discovery['media']['handoff_gate']=!$mediaEnabled?'disabled'
        :($allowMedia||$allowHistory?'no_playable_sources':($historyGate==='cooldown'?$historyGate:$hold));
    $mediaPending=null;
    if(($allowMedia||$allowHistory)&&($mediaPending=vp3_coordination_casual_media_pending_v1($pdo,$user,$binding))){
        $discovery['media']['handoff_gate']='handoff_pending';
        $allowMedia=$allowHistory=false;
    }
    if(!$allowMedia&&!$allowHistory&&(!$quiet||($mediaOnly&&!$allowStartersWithPrivateWork)))return null;
    $scope=['verification_only'=>true]+vp3_agent_activity_scope_v1($pdo,$user,$agent,$cid);
    $snapshot=vp3_casual_checkin_sources_v1($pdo,$scope,$binding,null,new DateTimeImmutable('@'.$now),$items,
        allCandidates:true,mediaOnly:$mediaOnly,allowStartersWithPrivateWork:$allowStartersWithPrivateWork,historyOnly:$historyOnly);
    if(($allowMedia||$allowHistory)&&($snapshot['media_enabled']??false)!==true)$discovery['media']['handoff_gate']='disabled';
    elseif(($allowMedia||$allowHistory)&&in_array($snapshot['coverage'],['limited','unavailable'],true))$discovery['media']['handoff_gate']='verification_unavailable';
    $pendingStarters=[];
    foreach($snapshot['items'] as $ref=>$item){
        if(($item['specialist']??null)!=='casual'||!vp3_coordination_source_ref_valid_v1($ref))continue;
        $media=in_array($item['trigger'],VP3_CASUAL_MEDIA_KINDS_V1,true);
        $history=$item['trigger']==='youtube_history';
        $starterFamily=vp3_casual_starter_specialist_v1($ref);
        $starter=$starterFamily!==null;
        if($media){
            if($history?!$allowHistory:!$allowMedia)continue;
        }elseif(!$quiet||($mediaOnly&&!($starter&&$allowStartersWithPrivateWork))
            ||!vp3_casual_checkin_allowed_v1($pdo,$user,$cid,$item['trigger']))continue;
        if($starterFamily==='media'){
            $mediaPending??=vp3_coordination_casual_media_pending_v1($pdo,$user,$binding);
            if($mediaPending)continue;
        }elseif(in_array($starterFamily,VP3_COORDINATION_SPECIALISTS_V1,true)
            &&($pendingStarters[$starterFamily]??=vp3_coordination_pending_v1($pdo,$user,$binding,chatOnly:true,specialistOnly:$starterFamily))){
            continue;
        }
        $route=vp3_coordination_item_route_v1([],$item);
        $id=vp3_coordination_scan_id_v1($user,$binding,$ref.':'.$item['revision']);
        $work=['binding'=>$binding,'tool'=>'coordination.review','coordination_id'=>$id,'primary'=>$ref,'sources'=>[$ref=>$item['revision']],
            'focus'=>'Offer a short casual check-in.','report'=>true,'coordination_coverage'=>$coverage,'coordination_priority'=>'low',
            'coordination_route'=>$route,'coordination_routes'=>[$ref=>$route],'coordination_saved'=>true];
        $dedupe=hash('sha256','coordination.casual.v1|'.$id.'|'.$binding['revision']);
        if(!vp3_initiative_enqueue_v1($pdo,$user,'initiative.prepare',$work,$dedupe)){
            if(!$media){
                if(!$starter)continue;
                // A failed system attempt never spent the one-time offer. Retry once
                // after its source becomes eligible again, while honoring cancellations.
                $failed=$pdo->prepare("SELECT updated_at FROM agent_workflow_runs
                    WHERE owner_user_id=? AND dedupe_key=? AND status='failed'");
                $failed->execute([$owner,hash('sha256','initiative.prepare|'.$dedupe)]);
                $failedTimestamp=$failed->fetchColumn();
                $failedAt=agent_job_utc_timestamp_v1900($failedTimestamp===false?null:$failedTimestamp);
                if($failedAt<1||$now-$failedAt<120)continue;
                if(vp3_initiative_enqueue_v1($pdo,$user,'initiative.prepare',$work,hash('sha256',$dedupe.'|recover-failed-starter'))){
                    return 'queued_checkin';
                }
                continue;
            }
            $discovery['media']['handoff_gate']='handoff_stopped';
            $latest=vp3_coordination_runs_v1($pdo,$user,$binding,$id,1)[0]??null;
            $stoppedAt=agent_job_utc_timestamp_v1900($latest['updated_at']??null);
            $status=$latest['status']??null;
            $receipt=$status==='completed'?vp3_coordination_verified_run_v1($pdo,$user,$latest):null;
            if(($receipt['initiative_delivery_reason']??null)==='result_unavailable')$discovery['media']['handoff_gate']='verification_unavailable';
            $retryable=in_array($status,['cancelled','failed'],true)
                ||($status==='completed'&&in_array($receipt['initiative_delivery']??null,['private','withheld'],true));
            $attempt=$retryable&&$stoppedAt>0&&$activityAt>$stoppedAt?'owner-activity|'.$activityAt:null;
            if($attempt===null&&$status==='failed'&&$stoppedAt>0&&$now-$stoppedAt>=120)$attempt='recover-stopped-job';
            if($attempt===null||!vp3_initiative_enqueue_v1($pdo,$user,'initiative.prepare',$work,hash('sha256',$dedupe.'|'.$attempt))){
                // A user-cancelled offer also closes alternative Media for this quiet stretch.
                if($status==='cancelled')$allowMedia=$allowHistory=false;
                continue;
            }
        }
        if(!$media)return 'queued_checkin';
        foreach(['found','chat_matches','candidate_count','queued'] as $field){
            $discovery['media'][$field]=($discovery['media'][$field]??0)+1;
        }
        $discovery['media']['dispositions'][]=['trigger'=>$history?'recent_search':'idle_invitation','action'=>'ask','reason'=>'queued'];
        $discovery['media']['offer_gate']=$discovery['media']['handoff_gate']='ready';
        return 'queued_media_offer';
    }
    return null;
}
