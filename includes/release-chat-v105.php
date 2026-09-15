<?php
declare(strict_types=1);
require_once __DIR__.'/agent-work-control-v173.php';
require_once __DIR__.'/agent-work-dependencies-v174.php';
require_once __DIR__.'/agent-objective-plans-v175.php';
require_once __DIR__.'/agent-objective-memory-v177.php';
require_once __DIR__.'/agent-proactive-objectives-v178.php';
require_once __DIR__.'/agent-objective-portfolio-v179.php';
require_once __DIR__.'/agent-goal-strategy-v1710.php';
require_once __DIR__.'/agent-goal-planning-v1711.php';
require_once __DIR__.'/agent-goal-execution-v1712.php';

function release_v105_chat_intent(string $query): bool
{
    return (bool)preg_match('/\b(release|launch|release calendar|deadline|campaign plan|rollout|distribution date|drop date)\b/i',$query);
}

function chat_account_state_intent_v241(string $query): bool
{
    $q=mb_strtolower(trim($query));if($q==='')return false;
    if(preg_match('/\b(?:onboarding|setup status|set up status|what(?:\s+do\s+i|\s+am\s+i)?\s+(?:still\s+)?need(?:\s+to)?\s+set\s*up|what\s+setup\s+(?:am\s+i\s+)?missing|what(?:\'s| is)\s+missing|finish\s+(?:my\s+)?setup)\b/u',$q))return true;
    if(preg_match('/\b(?:my|current)\s+(?:plan|package|subscription|trial)\b/u',$q))return true;
    if(preg_match('/\b(?:what|which)\s+(?:plan|package)\s+(?:should|would|do)\b|\b(?:recommend|suggest)\s+(?:a\s+|the\s+)?(?:plan|package)\b|\bbest\s+(?:plan|package)\b/u',$q))return true;
    if(preg_match('/\bwhat\s+(?:plan|package)\s+am\s+i\s+on\b/u',$q))return true;

    $subject='(?:profile agent|voice clone|social chat|direct chat|user[- ]to[- ]user chat|online presence|chat presence|public profile|profile visibility|profile\s+(?:public|private|visible)|incoming chat sound|message sound|notification sound)';
    if(!preg_match('/\b'.$subject.'\b/u',$q))return false;
    if(preg_match('/\b(?:my|mine|am i|do i|have i|status|setup|set up|enabled|disabled|turned on|turned off|configured|ready|active|live|online|offline)\b/u',$q))return true;
    return (bool)preg_match('/\b(?:is|are)\s+(?:my\s+)?(?:the\s+)?'.$subject.'\s+(?:on|off)\b/u',$q);
}

function release_v105_chat_tool(string $query,array $user,int $conversationId=0): array
{
    $empty=['handled'=>false,'answer'=>'','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[]];

    // Phase 17.12 owns explicit goal execution/orchestration language. It may
    // convert an advisory milestone into a fresh Phase 17.5 objective only when
    // the user explicitly says to work on/advance the goal; Phase 19 remains
    // the only worker and all approvals/dependencies remain authoritative.
    $goalExecution=agent_goal_execution_chat_v1712($query,$user,$conversationId);
    if(!empty($goalExecution['handled']))return $goalExecution;

    // Phase 17.11 owns goal-roadmap and milestone language. Roadmaps are
    // advisory planning metadata only; executable work still becomes fresh
    // Phase 17.5 objectives under the existing approval/execution boundary.
    $goalPlanning=agent_goal_plan_chat_v1711($query,$user,$conversationId);
    if(!empty($goalPlanning['handled']))return $goalPlanning;

    // Phase 17.10 owns durable Goal → Objective strategy language. Goal state is
    // planning metadata only; verified progress always comes from Phase 17.6
    // objective outcomes and no goal command bypasses normal execution safety.
    $goalStrategy=agent_goal_chat_v1710($query,$user,$conversationId);
    if(!empty($goalStrategy['handled']))return $goalStrategy;

    // Phase 17.8 owns explicit proactive-objective proposal language. A proposal
    // remains advisory until the user explicitly accepts it, at which point a
    // fresh Phase 17.5 objective is created under current safety boundaries.
    $proactiveObjective=agent_proactive_objective_chat_v178($query,$user,$conversationId);
    if(!empty($proactiveObjective['handled']))return $proactiveObjective;

    // Phase 17.9 arbitrates across objectives. Ranking is advisory; priority or
    // cross-objective dependency changes require an explicit user command.
    $objectivePortfolio=agent_objective_portfolio_chat_v179($query,$user,$conversationId);
    if(!empty($objectivePortfolio['handled']))return $objectivePortfolio;

    // Phase 17.7 owns explicit learned-objective lookup/reuse language. Reuse
    // always creates fresh Phase 17.5 workflows; it never replays receipts,
    // approvals, leases, or historical terminal state.
    $objectiveMemory=agent_objective_memory_chat_v177($query,$user,$conversationId);
    if(!empty($objectiveMemory['handled']))return $objectiveMemory;

    // Phase 17.5 owns explicit objective / multi-workflow planning language and
    // composes normal Phase 14/19 workflows plus Phase 17.4 dependencies. It
    // must run before single-workflow controls or release-specific routing.
    $objectivePlan=agent_objective_chat_v175($query,$user,$conversationId);
    if(!empty($objectivePlan['handled'])){
        $suggestion=agent_objective_memory_suggestion_v177($query,$user);
        if($suggestion!=='')$objectivePlan['answer']=trim((string)($objectivePlan['answer']??'')).' '.$suggestion;
        return $objectivePlan;
    }

    // Phase 17.4 runs before generic release/tool routing. It owns only explicit
    // workflow dependency/delegation language and leaves all execution to the
    // existing Phase 19 durable claimant.
    $workDependencies=agent_work_dependencies_chat_v174($query,$user,$conversationId);
    if(!empty($workDependencies['handled']))return $workDependencies;

    // Phase 17.3: both canonical Agent Chat and fallback Chat already pass
    // through this shared boundary before their generic tool executors. Work
    // controls remain owner-scoped and mutate only the durable workflow ledger.
    $workControl=agent_work_control_chat_v173($query,$user,$conversationId);
    if(!empty($workControl['handled']))return $workControl;

    if(chat_account_state_intent_v241($query)&&function_exists('chat_onboarding_v241_tool')){
        $accountState = chat_onboarding_v241_tool($query, $user);
        if (!empty($accountState['handled'])) return $accountState;
    }

    if(!release_v105_schema_ready()||!function_exists('release_workspace_v332_can_manage')||!release_workspace_v332_can_manage($user)||!release_v105_chat_intent($query))return $empty;
    $workspace=release_workspace_v332_context($user);if(!$workspace)return $empty;$workspaceId=(int)$workspace['id'];

    if(preg_match('/\b(open|show me|go to)\b.*\b(release|calendar)\b/i',$query)){
        $result=$empty;$result['handled']=true;$result['answer']='Opening the active Music Workspace Release Calendar.';
        $result['actions'][]=['type'=>'open_url','label'=>'Open Release Calendar','url'=>url('/music-releases.php?workspace='.$workspaceId),'auto'=>true];
        agent_tool_log($user,'release_calendar.open',$query,'success',['workspace_id'=>$workspaceId],$conversationId);return $result;
    }

    if(preg_match('/\b(list|show|what(?:\'s| is)|upcoming|next)\b.*\b(release|deadline|calendar|launch)\b/i',$query)){
        $plans=release_workspace_v332_plans($user,12);$lines=[];
        foreach($plans as $plan){$target=trim((string)$plan['target_date']);$lines[]='• '.(string)$plan['title'].' — '.(string)$plan['status'].($target!==''?' · '.date('M j, Y',strtotime($target)):' · no target date').' · '.(int)$plan['complete_count'].'/'.(int)$plan['item_count'].' complete';}
        $result=$empty;$result['handled']=true;$result['answer']=$lines?"Here is the current Music Workspace Release Calendar:\n\n".implode("\n",$lines):'This Music Workspace does not have any release plans yet.';
        $result['actions'][]=['type'=>'open_url','label'=>'Release Calendar','url'=>url('/music-releases.php?workspace='.$workspaceId),'auto'=>false];
        agent_tool_log($user,'release_calendar.list',$query,'success',['workspace_id'=>$workspaceId,'count'=>count($plans)],$conversationId);return $result;
    }

    if(preg_match('/\b(create|add|plan|schedule)\b\s+(?:a\s+)?(?:new\s+)?(?:release|launch)\s+(?:for\s+)?(.+?)\s+(?:on|for)\s+(.+)$/i',$query,$m)){
        $title=trim($m[1]);$dateText=trim($m[2]);$ts=strtotime($dateText);
        if($title!==''&&$ts!==false){
            $pdo=db();if(!$pdo)return $empty;
            $id=music_workspace_resources_v331_save_release($pdo,$workspaceId,$user,[
                'title'=>mb_substr($title,0,190),
                'release_type'=>'single',
                'status'=>'planning',
                'priority'=>'normal',
                'target_date'=>date('Y-m-d',$ts),
                'agent_goal'=>'Coordinate the release plan, assets, deadlines, resources and approved outreach work.',
            ]);
            agent_tool_log($user,'release_calendar.create',$query,'success',['workspace_id'=>$workspaceId,'release_id'=>$id,'target_date'=>date('c',$ts)],$conversationId);
            $result=$empty;$result['handled']=true;$result['answer']='Created the release plan “'.$title.'” for '.date('F j, Y',$ts).' in the active Music Workspace. I can now use it as an Agent planning object and help coordinate its work.';
            $result['actions'][]=['type'=>'open_url','label'=>'Open Release Plan','url'=>url('/music-releases.php?workspace='.$workspaceId.'&release='.$id),'auto'=>false];return $result;
        }
    }

    return $empty;
}

function chat_generate_answer_v105(string $query,array $history,array $user,array $agentContext=[]): array
{
    $context=chat_context($query,$user);
    if($agentContext&&function_exists('agent_surface_v131_context_item'))array_unshift($context,agent_surface_v131_context_item($agentContext));

    if(release_v105_schema_ready()&&function_exists('release_workspace_v332_can_manage')&&release_workspace_v332_can_manage($user)){
        $releaseContext=release_workspace_v332_agent_context($user,release_v105_chat_intent($query)?10:4);
        if($releaseContext){$context=array_merge($releaseContext,$context);}else{
            $resourceLines=[];
            foreach(array_slice(release_workspace_v332_owner_resources($user,20),0,12) as $resource)$resourceLines[]='Resource #'.(int)$resource['id'].' · '.(string)$resource['resource_type'].' · '.(string)$resource['title'].((string)$resource['provider_key']!==''?' · provider '.(string)$resource['provider_key']:'');
            foreach(release_workspace_v332_owner_integrations($user) as $integration)$resourceLines[]='Integration '.(string)$integration['provider_key'].' · '.(string)$integration['status'].' · '.((string)$integration['label']?: (string)$integration['connection_key']);
            if($resourceLines)$context[]=['source'=>'agent-brain:operations-resources','title'=>'Agent Operations resources and connected capabilities','text'=>implode("\n",$resourceLines)];
        }
        $context[]=[
            'source'=>'agent:release-operations-tool',
            'title'=>'Music Workspace Release Operations tool contract',
            'text'=>'Release Calendar is scoped to the active Music Workspace. It coordinates release plans, due work, tracks, shows and approved Agent work. Never use another workspace implicitly. Owner-scoped external integrations are not exposed to collaborators, and no external side effect may be claimed unless its action record reports completion.',
        ];
    }

    if(release_v105_schema_ready()&&preg_match('/\b(credit|credits|credited|contributor|producer|songwriter|engineer)\b/i',$query)){
        $track=agent_tool_find_track($query,$user);
        if($track&&permission_v105_track_allowed($track,$user)){
            $rows=credits_v105_rows($user,(int)$track['id']);$lines=[];
            foreach($rows as $row)$lines[]=(string)$row['display_name'].' · '.(string)$row['contribution_role'].(trim((string)$row['contribution_detail'])!==''?' · '.(string)$row['contribution_detail']:'');
            if($lines)$context[]=['source'=>'agent-brain:credits:'.(int)$track['id'],'title'=>'Credits graph · '.(string)$track['title'],'text'=>implode("\n",$lines)];
        }
    }

    // Phase 17.8 can proactively surface one high-confidence objective without
    // creating it. The nudge is rate-limited through the existing proactive
    // event ledger and remains advisory until explicit Agent Chat acceptance.
    $proactiveObjectiveNudge='';
    try{
        $pdo=db();$uid=(int)($user['id']??0);
        if($pdo instanceof PDO&&$uid>0&&agent_proactive_objective_ready_v178($pdo)){
            $objectiveContext=['unread_notifications'=>function_exists('notification_unread_count')?max(0,(int)notification_unread_count($user)):0,'knowledge_count'=>0];
            if(table_exists('knowledge_items')){try{$stmt=$pdo->prepare("SELECT COUNT(*) FROM knowledge_items WHERE created_by_user_id=? AND knowledge_scope='personal'");$stmt->execute([$uid]);$objectiveContext['knowledge_count']=max(0,(int)$stmt->fetchColumn());}catch(Throwable $e){}}
            $proposals=agent_proactive_objectives_v178($pdo,$user,[],$objectiveContext,false);
            if($proposals){
                $proposal=$proposals[0];
                $context[]=['source'=>'agent:proactive-objective:'.(string)$proposal['token'],'title'=>'Proactive objective proposal','text'=>agent_proactive_objective_answer_v178($proposal,false)];
                $latest=agent_proactive_objective_event_v178($pdo,$uid,(string)$proposal['token']);
                $shownRecently=is_array($latest)&&(string)($latest['event_type']??'')==='shown'&&(strtotime((string)($latest['created_at']??''))?:0)>=time()-(VP3_AGENT_PROACTIVE_OBJECTIVE_SHOWN_TTL_HOURS_V178*3600);
                $controlQuery=(bool)preg_match('/\b(?:objective|proposal|workflow|approve|approval|cancel|dismiss|pause|resume|retry)\b/i',$query);
                if(!$shownRecently&&!$controlQuery&&(float)($proposal['score']??0)>=0.88){
                    agent_proactive_objective_record_shown_v178($pdo,$user,$proposal);
                    $proactiveObjectiveNudge='I also see a high-confidence multi-step objective worth considering: “'.agent_proactive_objective_text_v178((string)$proposal['title'],140).'”. Say “show proposed objective '.(string)$proposal['token'].'” to review the plan. Nothing will run unless you accept it.';
                }
            }
        }
    }catch(Throwable $e){}

    $context=array_slice($context,0,32);$answer=chat_remote_answer($query,$history,$context,$user);if($answer===null)$answer=chat_local_answer($query,$context);
    if($proactiveObjectiveNudge!=='')$answer=rtrim((string)$answer)."\n\n".$proactiveObjectiveNudge;
    return ['answer'=>$answer,'context'=>$context];
}