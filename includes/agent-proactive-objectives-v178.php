<?php
declare(strict_types=1);

/**
 * VP3 Phase 17.8 — Proactive Objective Intelligence.
 *
 * This layer converts already-ranked, evidence-first proactive signals into
 * explicit multi-workflow objective proposals. It never executes a proposal.
 * Acceptance always creates fresh Phase 17.5 workflows (or reuses a safe
 * Phase 17.7 learned plan through fresh Phase 17.5 creation), so current
 * approval, dependency, execution-target, lease and receipt rules remain
 * authoritative.
 *
 * Proposal persistence/audit reuses agent_proactive_events. No second
 * scheduler, worker, queue, objective ledger or dashboard is introduced.
 */
const VP3_AGENT_PROACTIVE_OBJECTIVES_V178='agent-proactive-objectives-v178-20260915';
const VP3_AGENT_PROACTIVE_OBJECTIVE_LIMIT_V178=2;
const VP3_AGENT_PROACTIVE_OBJECTIVE_MIN_SCORE_V178=0.72;
const VP3_AGENT_PROACTIVE_OBJECTIVE_SHOWN_TTL_HOURS_V178=24;

require_once __DIR__.'/agent-objective-memory-v177.php';

function agent_proactive_objective_ready_v178(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)($pdo
        && agent_objective_ready_v175($pdo)
        && function_exists('agent_proactive_v123_suggestions')
        && function_exists('agent_proactive_v93_event')
        && function_exists('agent_proactive_v93_schema_ready')
        && agent_proactive_v93_schema_ready());
}

function agent_proactive_objective_require_v178(PDO $pdo,array $user): int
{
    $uid=(int)($user['id']??0);
    if($uid<1||!has_permission('account.access',$user))throw new RuntimeException('Proactive objectives are not available for this account.');
    if(!agent_proactive_objective_ready_v178($pdo))throw new RuntimeException('Proactive objective intelligence is not ready yet.');
    return $uid;
}

function agent_proactive_objective_text_v178(mixed $value,int $limit=500): string
{
    $text=agent_objective_text_v175((string)$value,$limit);
    // Cloud proposal memory must never preserve native/local filesystem paths.
    $text=preg_replace('/\b[A-Za-z]:\\\\[^\s]+/u','[private path]',$text)??$text;
    $text=preg_replace('#/(?:Users|home|mnt|Volumes)/[^\s]+#iu','[private path]',$text)??$text;
    return agent_objective_text_v175($text,$limit);
}

function agent_proactive_objective_token_v178(string $hash): string
{
    return strtoupper(substr(preg_replace('/[^a-f0-9]/i','',$hash)??'',0,8));
}

function agent_proactive_objective_candidate_score_v178(array $candidate): float
{
    if(isset($candidate['score']))return max(0.0,min(1.0,(float)$candidate['score']));
    $priority=max(0,(int)($candidate['priority']??0));
    return max(0.0,min(1.0,$priority>0?$priority/200:0.0));
}

function agent_proactive_objective_source_eligible_v178(string $source,float $score): bool
{
    $source=mb_strtolower(trim($source));
    if($source==='')return false;
    if(in_array($source,['fallback','starter','activity_working','activity_resume','studio_session','video_project','recent_media','current_project'],true))return false;
    if(str_starts_with($source,'calendar_'))return $score>=0.66;
    if(in_array($source,['operations_homeserver','operations_ai_routing','task_lifecycle','booking','pattern','edit_pattern','tool_pattern','workflow_recovery','notifications_attention','crm_followup'],true))return $score>=0.68;
    return $score>=VP3_AGENT_PROACTIVE_OBJECTIVE_MIN_SCORE_V178;
}

function agent_proactive_objective_goal_v178(array $candidate): string
{
    $title=agent_proactive_objective_text_v178($candidate['title']??'Proactive objective',190);
    $reason=agent_proactive_objective_text_v178($candidate['reason']??'',300);
    $source=mb_strtolower((string)($candidate['source']??''));
    if($title==='')$title='Resolve the detected VP3 opportunity';
    if(str_starts_with($source,'calendar_'))$goal='Prepare for and successfully handle '.$title;
    elseif(in_array($source,['workflow_recovery','operations_homeserver','operations_ai_routing'],true))$goal='Resolve '.$title;
    elseif($source==='crm_followup')$goal='Complete '.$title;
    else $goal=$title;
    if($reason!=='')$goal.=' — '.$reason;
    return agent_proactive_objective_text_v178($goal,700);
}

function agent_proactive_objective_direct_candidates_v178(PDO $pdo,array $user,array $context=[]): array
{
    $uid=(int)($user['id']??0);if($uid<1)return [];$out=[];

    $unread=max(0,(int)($context['unread_notifications']??0));
    if($unread>=5){
        $out[]=[
            'hash'=>sha1('v178|notifications|'.$uid.'|'.$unread),
            'key'=>'v178:notifications-attention',
            'title'=>'Clear the current attention backlog',
            'prompt'=>'Review my unread VP3 notifications, group them by urgency and source, resolve the highest-value items, and verify that nothing important remains unattended.',
            'reason'=>$unread.' unread notifications currently need review.',
            'source'=>'notifications_attention','score'=>min(0.94,0.74+min(0.20,$unread*0.015)),
        ];
    }

    if(table_exists('agent_workflow_runs')){
        try{
            $stmt=$pdo->prepare("SELECT COUNT(*) FROM agent_workflow_runs WHERE owner_user_id=? AND status='failed' AND (source_kind IS NULL OR source_kind NOT IN ('objective_step','objective_plan'))");
            $stmt->execute([$uid]);$failed=max(0,(int)$stmt->fetchColumn());
            if($failed>=2){
                $out[]=[
                    'hash'=>sha1('v178|workflow-recovery|'.$uid.'|'.$failed),
                    'key'=>'v178:workflow-recovery',
                    'title'=>'Stabilize failed Agent work',
                    'prompt'=>'Inspect my failed non-objective Agent workflows, identify shared causes, repair the affected work without rewriting completed receipts, then verify the repaired workflows can advance normally.',
                    'reason'=>$failed.' failed Agent workflows need coordinated recovery.',
                    'source'=>'workflow_recovery','score'=>min(0.98,0.82+min(0.16,$failed*0.025)),
                ];
            }
        }catch(Throwable $e){}
    }

    if(function_exists('crm_v180_schema_ready')&&crm_v180_schema_ready($pdo)&&function_exists('crm_v180_can_manage')&&crm_v180_can_manage($user)){
        try{
            $stmt=$pdo->prepare("SELECT COUNT(*) total,SUM(CASE WHEN due_at IS NOT NULL AND due_at<UTC_TIMESTAMP() THEN 1 ELSE 0 END) overdue FROM crm_tasks WHERE assigned_user_id=? AND status='open' AND due_at IS NOT NULL AND due_at<=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 2 DAY)");
            $stmt->execute([$uid]);$row=$stmt->fetch()?:[];$total=max(0,(int)($row['total']??0));$overdue=max(0,(int)($row['overdue']??0));
            if($total>=2){
                $out[]=[
                    'hash'=>sha1('v178|crm-followup|'.$uid.'|'.$total.'|'.$overdue),
                    'key'=>'v178:crm-followup',
                    'title'=>'Clear the due CRM follow-up queue',
                    'prompt'=>'Review only the CRM tasks assigned to me that are due or overdue, prioritize the follow-ups, prepare the next safe actions, and verify the queue is current.',
                    'reason'=>$total.' assigned CRM follow-up tasks are due within two days'.($overdue>0?' · '.$overdue.' overdue':'').'.',
                    'source'=>'crm_followup','score'=>min(0.96,0.76+min(0.16,$overdue*0.04)+min(0.08,$total*0.01)),
                ];
            }
        }catch(Throwable $e){}
    }

    return $out;
}

function agent_proactive_objective_plan_v178(PDO $pdo,array $user,array $candidate,array $context=[]): ?array
{
    $uid=(int)($user['id']??0);if($uid<1)return null;
    $source=agent_proactive_objective_text_v178($candidate['source']??'proactive',60);
    $score=agent_proactive_objective_candidate_score_v178($candidate);
    if(!agent_proactive_objective_source_eligible_v178($source,$score))return null;

    $goal=agent_proactive_objective_goal_v178($candidate);if($goal==='')return null;
    $memory=null;$memorySimilarity=0.0;$negativeMemory=null;
    if(function_exists('agent_objective_memory_schema_ready_v177')&&agent_objective_memory_schema_ready_v177($pdo)){
        try{
            $matches=agent_objective_memory_similar_v177($pdo,$user,$goal,1,true);
            if($matches){
                $match=$matches[0];$memorySimilarity=(float)($match['similarity_score']??0.0);
                if(in_array((string)($match['outcome_status']??''),['achieved','remediated'],true)&&$memorySimilarity>=0.30)$memory=$match;
                elseif((string)($match['outcome_status']??'')==='failed'&&$memorySimilarity>=0.18)$negativeMemory=$match;
            }
        }catch(Throwable $e){}
    }

    $stages=[];$planSource='heuristic';
    if(is_array($memory)){
        try{$stages=agent_objective_memory_replay_stages_v177($memory,$goal);$planSource=$stages?'learned':'heuristic';}catch(Throwable $e){$stages=[];}
    }
    if(!$stages){
        $stages=agent_objective_heuristic_stages_v175($goal);
        $prompt=agent_proactive_objective_text_v178($candidate['prompt']??'',850);
        if($prompt!==''&&$stages){
            $index=max(0,min(count($stages)-1,count($stages)>=4?2:1));
            if(isset($stages[$index][0])&&is_array($stages[$index][0])){
                $existing=agent_proactive_objective_text_v178($stages[$index][0]['instruction']??$stages[$index][0]['title']??'',700);
                if(str_contains(mb_strtolower($source),'homeserver'))$prompt='Use only currently authorized HomeServer capabilities and preserve every local approval/privacy boundary while completing the core work.';
                $stages[$index][0]['instruction']=agent_proactive_objective_text_v178(trim($existing.' '.$prompt),1500);
            }
        }
    }

    $stepCount=0;foreach($stages as $stage)$stepCount+=count(array_filter((array)$stage,'is_array'));
    if($stepCount<2)return null;

    $knowledge=max(0,(int)($context['knowledge_count']??0));
    if(is_array($memory))$score=min(1.0,$score+min(0.10,$memorySimilarity*0.12));
    if(is_array($negativeMemory))$score=max(0.0,$score-0.07);
    if($knowledge>0)$score=min(1.0,$score+0.015);

    $candidateHash=(string)($candidate['hash']??sha1($source.'|'.$goal));
    $hash=sha1('objective-proposal|'.$uid.'|'.$candidateHash.'|'.$goal);
    $token=agent_proactive_objective_token_v178($hash);
    $title=agent_proactive_objective_text_v178($candidate['title']??$goal,190);
    $reason=agent_proactive_objective_text_v178($candidate['reason']??'VP3 detected evidence that this may be worth coordinating as an objective.',360);
    if(is_array($memory))$reason.=' A similar '.((string)$memory['outcome_status']==='achieved'?'successful':'remediated').' objective can seed the plan.';
    elseif(is_array($negativeMemory))$reason.=' A similar past objective failed, so that outcome is being used only as negative evidence.';

    return [
        'build'=>VP3_AGENT_PROACTIVE_OBJECTIVES_V178,
        'hash'=>$hash,'token'=>$token,'title'=>$title,'goal'=>$goal,'reason'=>$reason,
        'source'=>$source,'source_hash'=>$candidateHash,'score'=>round($score,4),
        'stages'=>$stages,'stage_count'=>count($stages),'step_count'=>$stepCount,
        'plan_source'=>$planSource,
        'memory_id'=>is_array($memory)?(int)($memory['id']??0):0,
        'memory_outcome'=>is_array($memory)?(string)($memory['outcome_status']??''):'',
        'memory_similarity'=>is_array($memory)?round($memorySimilarity,4):0.0,
        'negative_memory_id'=>is_array($negativeMemory)?(int)($negativeMemory['id']??0):0,
        'knowledge_available'=>$knowledge>0,
        'accept_prompt'=>'accept proposed objective '.$token,
        'inspect_prompt'=>'show proposed objective '.$token,
        'dismiss_prompt'=>'dismiss proposed objective '.$token,
    ];
}

function agent_proactive_objective_payload_v178(array $proposal): array
{
    return [
        'build'=>VP3_AGENT_PROACTIVE_OBJECTIVES_V178,
        'hash'=>(string)($proposal['hash']??''),'token'=>(string)($proposal['token']??''),
        'title'=>agent_proactive_objective_text_v178($proposal['title']??'',190),
        'goal'=>agent_proactive_objective_text_v178($proposal['goal']??'',700),
        'reason'=>agent_proactive_objective_text_v178($proposal['reason']??'',400),
        'source'=>agent_proactive_objective_text_v178($proposal['source']??'',60),
        'score'=>max(0.0,min(1.0,(float)($proposal['score']??0))),
        'stages'=>array_slice((array)($proposal['stages']??[]),0,VP3_AGENT_OBJECTIVE_MAX_STAGES_V175),
        'stage_count'=>(int)($proposal['stage_count']??0),'step_count'=>(int)($proposal['step_count']??0),
        'plan_source'=>(string)($proposal['plan_source']??'heuristic'),
        'memory_id'=>(int)($proposal['memory_id']??0),'memory_outcome'=>(string)($proposal['memory_outcome']??''),
        'memory_similarity'=>(float)($proposal['memory_similarity']??0.0),'negative_memory_id'=>(int)($proposal['negative_memory_id']??0),
        'knowledge_available'=>!empty($proposal['knowledge_available']),
    ];
}

function agent_proactive_objective_record_shown_v178(PDO $pdo,array $user,array $proposal): void
{
    $uid=(int)($user['id']??0);$hash=(string)($proposal['hash']??'');if($uid<1||$hash==='')return;
    try{
        $stmt=$pdo->prepare("SELECT COUNT(*) FROM agent_proactive_events WHERE user_id=? AND suggestion_hash=? AND source_kind='objective_proposal' AND event_type='shown' AND created_at>=DATE_SUB(NOW(),INTERVAL ".VP3_AGENT_PROACTIVE_OBJECTIVE_SHOWN_TTL_HOURS_V178." HOUR)");
        $stmt->execute([$uid,$hash]);if((int)$stmt->fetchColumn()>0)return;
        agent_proactive_v93_event($user,$hash,'shown','objective_proposal',[
            'title'=>(string)($proposal['title']??''),'prompt'=>(string)($proposal['accept_prompt']??''),'source'=>'objective_proposal',
            'context'=>['proposal'=>agent_proactive_objective_payload_v178($proposal)],
        ]);
    }catch(Throwable $e){}
}

function agent_proactive_objectives_v178(PDO $pdo,array $user,array $bundle=[],array $context=[],bool $recordShown=true): array
{
    $uid=agent_proactive_objective_require_v178($pdo,$user);
    if(!$bundle){$bundle=agent_proactive_v123_suggestions($user,'chat',['surface'=>'chat']);}
    $candidates=[];
    foreach((array)($bundle['suggestions']??[]) as $candidate)if(is_array($candidate))$candidates[]=$candidate;
    foreach(agent_proactive_objective_direct_candidates_v178($pdo,$user,$context) as $candidate)$candidates[]=$candidate;

    $suppressed=function_exists('agent_proactive_v93_suppressed')?agent_proactive_v93_suppressed($uid):[];$proposals=[];$seen=[];
    foreach($candidates as $candidate){
        $proposal=agent_proactive_objective_plan_v178($pdo,$user,$candidate,$context);if(!$proposal)continue;
        $hash=(string)$proposal['hash'];if(isset($seen[$hash])||!empty($suppressed[$hash]))continue;$seen[$hash]=true;$proposals[]=$proposal;
    }
    usort($proposals,static fn(array $a,array $b): int=>((float)$b['score']<=>(float)$a['score'])?:strcmp((string)$a['title'],(string)$b['title']));
    $proposals=array_slice($proposals,0,VP3_AGENT_PROACTIVE_OBJECTIVE_LIMIT_V178);
    if($recordShown)foreach($proposals as $proposal)agent_proactive_objective_record_shown_v178($pdo,$user,$proposal);
    return $proposals;
}

function agent_proactive_objective_event_v178(PDO $pdo,int $uid,string $token): ?array
{
    $token=strtolower(preg_replace('/[^a-f0-9]/i','',$token)??'');if($uid<1||strlen($token)<6)return null;
    $stmt=$pdo->prepare("SELECT * FROM agent_proactive_events WHERE user_id=? AND source_kind='objective_proposal' AND suggestion_hash LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$uid,$token.'%']);$row=$stmt->fetch();if(!is_array($row))return null;
    $context=json_decode((string)($row['context_json']??''),true);$proposal=is_array($context)&&is_array($context['proposal']??null)?$context['proposal']:[];
    if(!$proposal||!hash_equals(strtolower((string)($row['suggestion_hash']??'')),strtolower((string)($proposal['hash']??''))))return null;
    $row['_proposal']=$proposal;return $row;
}

function agent_proactive_objective_accept_v178(PDO $pdo,array $user,string $token,int $conversationId=0): array
{
    $uid=agent_proactive_objective_require_v178($pdo,$user);$event=agent_proactive_objective_event_v178($pdo,$uid,$token);if(!$event)throw new RuntimeException('That proposed objective is no longer available.');
    $proposal=(array)$event['_proposal'];$eventType=(string)($event['event_type']??'');
    if($eventType==='dismissed')throw new RuntimeException('That proposed objective was dismissed. Ask me to show proactive objectives again for a fresh proposal.');
    if($eventType==='acted'){
        $accepted=(int)((json_decode((string)($event['context_json']??''),true)['accepted_objective_id']??0));
        if($accepted>0)return agent_objective_state_v175($pdo,$user,$accepted,true);
        throw new RuntimeException('That proposed objective was already accepted.');
    }

    $goal=agent_proactive_objective_text_v178($proposal['goal']??'',700);$stages=(array)($proposal['stages']??[]);if($goal===''||count($stages)<1)throw new RuntimeException('That proposal does not contain a reusable objective plan.');
    $state=[];$memoryId=(int)($proposal['memory_id']??0);
    if($memoryId>0&&function_exists('agent_objective_memory_schema_ready_v177')&&agent_objective_memory_schema_ready_v177($pdo)){
        $memory=agent_objective_memory_row_v177($pdo,$uid,$memoryId);
        if($memory&&in_array((string)($memory['outcome_status']??''),['achieved','remediated'],true))$state=agent_objective_memory_reuse_v177($pdo,$user,$memory,$goal,$conversationId,(int)($user['agent_id']??0)?:null);
    }
    if(!$state)$state=agent_objective_create_v175($pdo,$user,$goal,$stages,$conversationId,(int)($user['agent_id']??0)?:null);
    $parentId=(int)($state['objective']['id']??0);if($parentId<1)throw new RuntimeException('The objective could not be created.');

    agent_proactive_v93_event($user,(string)$proposal['hash'],'acted','objective_proposal',[
        'title'=>(string)($proposal['title']??''),'prompt'=>'Objective #'.$parentId.' created','source'=>'objective_proposal',
        'context'=>['proposal'=>$proposal,'accepted_objective_id'=>$parentId],
    ]);
    $parent=agent_workflow_row_v1400($pdo,$uid,$parentId);$status=is_array($parent)?(string)($parent['status']??'approved'):'approved';
    agent_workflow_event_v1400($pdo,$uid,$parentId,'proactive_objective_accepted',$status,$status,'user','A proactive objective proposal was explicitly accepted in Agent Chat.',['proposal_hash'=>(string)$proposal['hash'],'proposal_token'=>(string)$proposal['token'],'proposal_source'=>(string)$proposal['source'],'memory_id'=>$memoryId]);
    $state['proactive_proposal']=['hash'=>(string)$proposal['hash'],'token'=>(string)$proposal['token'],'source'=>(string)$proposal['source']];
    return $state;
}

function agent_proactive_objective_dismiss_v178(PDO $pdo,array $user,string $token): array
{
    $uid=agent_proactive_objective_require_v178($pdo,$user);$event=agent_proactive_objective_event_v178($pdo,$uid,$token);if(!$event)throw new RuntimeException('That proposed objective is no longer available.');$proposal=(array)$event['_proposal'];
    if((string)($event['event_type']??'')==='acted')throw new RuntimeException('That proposal was already accepted and cannot be dismissed retroactively.');
    if((string)($event['event_type']??'')!=='dismissed')agent_proactive_v93_event($user,(string)$proposal['hash'],'dismissed','objective_proposal',['title'=>(string)($proposal['title']??''),'prompt'=>'','source'=>'objective_proposal','context'=>['proposal'=>$proposal]]);
    return $proposal;
}

function agent_proactive_objective_answer_v178(array $proposal,bool $detail=false): string
{
    $token=(string)($proposal['token']??'');$title=(string)($proposal['title']??'Proposed objective');$reason=(string)($proposal['reason']??'');$steps=(int)($proposal['step_count']??0);$stages=(int)($proposal['stage_count']??0);$score=(int)round(((float)($proposal['score']??0))*100);$source=(string)($proposal['source']??'proactive');
    $answer='Proposal '.$token.' — '.$title.'. '.$reason.' Confidence '.$score.'%. '.$stages.' stage'.($stages===1?'':'s').' / '.$steps.' workflow step'.($steps===1?'':'s').'. Source: '.$source.'.';
    if((int)($proposal['memory_id']??0)>0)$answer.=' Learned objective memory #'.(int)$proposal['memory_id'].' can seed the plan, but every current approval and execution boundary will be recalculated.';
    if((int)($proposal['negative_memory_id']??0)>0)$answer.=' A similar failed outcome is retained only as negative evidence and will not be replayed.';
    if($detail){$labels=[];foreach((array)($proposal['stages']??[]) as $index=>$stage){$titles=[];foreach((array)$stage as $step)if(is_array($step)){$label=agent_proactive_objective_text_v178($step['title']??'',90);if($label!=='')$titles[]=$label;}if($titles)$labels[]='Stage '.($index+1).': '.implode(' + ',$titles);}if($labels)$answer.=' '.implode(' | ',$labels).'.';}
    return $answer.' Say “accept proposed objective '.$token.'” to create fresh durable workflows, or “dismiss proposed objective '.$token.'”. Nothing runs until you accept.';
}

function agent_proactive_objective_chat_v178(string $query,array $user,int $conversationId=0): array
{
    $empty=agent_objective_empty_tool_v175();$q=trim($query);if($q==='')return $empty;
    $intent=(bool)preg_match('/\b(?:proactive objectives?|proposed objectives?|objective proposals?|suggest(?:ed)? objectives?|accept proposed objective|start proposed objective|dismiss proposed objective|show proposed objective)\b/i',$q);
    if(!$intent)return $empty;$pdo=db();if(!$pdo)return $empty;
    try{
        $uid=agent_proactive_objective_require_v178($pdo,$user);$tool='objective.proactive';$answer='';$state=[];
        if(preg_match('/\b(?:accept|start|create)\s+(?:the\s+)?(?:proposed\s+)?objective(?:\s+proposal)?\s*#?\s*([a-f0-9]{6,40})\b/i',$q,$m)){
            $state=agent_proactive_objective_accept_v178($pdo,$user,(string)$m[1],$conversationId);$parentId=(int)($state['objective']['id']??0);$answer='Accepted the proactive proposal and created fresh objective #'.$parentId.'. Current risk, approvals, dependencies, Cloud/HomeServer routing, leases and receipts remain authoritative.';$tool='objective.proactive.accept';
        }elseif(preg_match('/\bdismiss\s+(?:the\s+)?(?:proposed\s+)?objective(?:\s+proposal)?\s*#?\s*([a-f0-9]{6,40})\b/i',$q,$m)){
            $proposal=agent_proactive_objective_dismiss_v178($pdo,$user,(string)$m[1]);$answer='Dismissed proposal '.(string)$proposal['token'].'. The existing proactive suppression window will keep it out of the Agent Brief unless materially new evidence produces a new proposal.';$tool='objective.proactive.dismiss';
        }elseif(preg_match('/\b(?:show|inspect|explain|why)\s+(?:the\s+)?(?:proposed\s+)?objective(?:\s+proposal)?\s*#?\s*([a-f0-9]{6,40})\b/i',$q,$m)){
            $event=agent_proactive_objective_event_v178($pdo,$uid,(string)$m[1]);if(!$event)throw new RuntimeException('That proposed objective is no longer available.');$answer=agent_proactive_objective_answer_v178((array)$event['_proposal'],true);$tool='objective.proactive.inspect';
        }else{
            $context=['unread_notifications'=>function_exists('notification_unread_count')?max(0,(int)notification_unread_count($user)):0,'knowledge_count'=>0];
            if(table_exists('knowledge_items')){try{$stmt=$pdo->prepare("SELECT COUNT(*) FROM knowledge_items WHERE created_by_user_id=? AND knowledge_scope='personal'");$stmt->execute([$uid]);$context['knowledge_count']=max(0,(int)$stmt->fetchColumn());}catch(Throwable $e){}}
            $proposals=agent_proactive_objectives_v178($pdo,$user,[],$context,true);
            if(!$proposals)$answer='I do not have a high-confidence multi-step objective to propose right now. I will keep using the normal evidence-first next-action ranking without creating work automatically.';
            else{$parts=[];foreach($proposals as $proposal)$parts[]=agent_proactive_objective_answer_v178($proposal,false);$answer=implode("\n\n",$parts);}$tool='objective.proactive.list';
        }
        $out=$empty;$out['handled']=true;$out['answer']=$answer;if(function_exists('agent_tool_log'))agent_tool_log($user,$tool,$query,'success',['build'=>VP3_AGENT_PROACTIVE_OBJECTIVES_V178,'objective_run_id'=>(int)($state['objective']['id']??0)],$conversationId);return $out;
    }catch(Throwable $e){$out=$empty;$out['handled']=true;$out['answer']='I could not use proactive objective intelligence: '.$e->getMessage();if(function_exists('agent_tool_log'))agent_tool_log($user,'objective.proactive',$query,'error',['error'=>get_class($e)],$conversationId);return $out;}
}

function agent_proactive_objective_render_v178(array $proposals): string
{
    if(!$proposals)return '';$proposals=array_slice(array_values(array_filter($proposals,'is_array')),0,VP3_AGENT_PROACTIVE_OBJECTIVE_LIMIT_V178);if(!$proposals)return '';
    ob_start();
    ?>
    <style data-agent-proactive-objectives-v178>
      .chat-agent-objective-proposals{display:grid;gap:8px;padding:11px;border:1px solid #e2e5e9;border-radius:12px;background:#fff}.chat-agent-objective-proposals>header{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}.chat-agent-objective-proposals>header div{display:grid;gap:2px}.chat-agent-objective-proposals>header small{color:#8a94a3;font-size:9px;font-weight:850;letter-spacing:.11em;text-transform:uppercase}.chat-agent-objective-proposals>header strong{font-size:12px}.chat-agent-objective-proposals>header span{color:#667085;font-size:8.8px}.chat-agent-objective-proposal-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:7px}.chat-agent-objective-proposal{display:grid;gap:7px;padding:10px;border:1px solid #eaecf0;border-radius:10px;background:#fbfcfd}.chat-agent-objective-proposal h3{margin:0;font-size:10.5px}.chat-agent-objective-proposal p{margin:0;color:#667085;font-size:8.8px;line-height:1.45}.chat-agent-objective-proposal-meta{display:flex;gap:5px;flex-wrap:wrap}.chat-agent-objective-proposal-meta span{padding:3px 6px;border-radius:999px;background:#f1f3f5;color:#667085;font-size:8px;font-weight:800}.chat-agent-objective-proposal-actions{display:flex;gap:5px;flex-wrap:wrap}.chat-agent-objective-proposal-actions button{appearance:none;padding:5px 7px;border:1px solid #dfe3e8;border-radius:7px;background:#fff;color:#344054;font-size:8.4px;font-weight:800;cursor:pointer}.chat-agent-objective-proposal-actions button:first-child{background:#111827;color:#fff;border-color:#111827}@media(max-width:760px){.chat-agent-objective-proposal-grid{grid-template-columns:1fr}}
    </style>
    <section class="chat-agent-objective-proposals" data-agent-proactive-objectives="<?= e(VP3_AGENT_PROACTIVE_OBJECTIVES_V178) ?>" aria-label="Proposed objectives">
      <header><div><small>Proactive objectives</small><strong>Multi-step work your Agent thinks may be worth coordinating</strong></div><span>Nothing runs until you accept.</span></header>
      <div class="chat-agent-objective-proposal-grid">
        <?php foreach($proposals as $proposal): $token=(string)($proposal['token']??''); ?>
          <article class="chat-agent-objective-proposal">
            <h3><?= e((string)($proposal['title']??'Proposed objective')) ?></h3>
            <p><?= e(agent_proactive_objective_text_v178($proposal['reason']??'',220)) ?></p>
            <div class="chat-agent-objective-proposal-meta"><span><?= e($token) ?></span><span><?= (int)($proposal['stage_count']??0) ?> stages</span><span><?= (int)($proposal['step_count']??0) ?> steps</span><span><?= (int)round(((float)($proposal['score']??0))*100) ?>% confidence</span><?php if((int)($proposal['memory_id']??0)>0): ?><span>learned plan</span><?php endif; ?></div>
            <div class="chat-agent-objective-proposal-actions"><button type="button" data-agent-intelligence-prompt="<?= e('accept proposed objective '.$token) ?>">Start objective</button><button type="button" data-agent-intelligence-prompt="<?= e('show proposed objective '.$token) ?>">Review plan</button><button type="button" data-agent-intelligence-prompt="<?= e('dismiss proposed objective '.$token) ?>">Dismiss</button></div>
          </article>
        <?php endforeach; ?>
      </div>
    </section>
    <?php
    return (string)ob_get_clean();
}
