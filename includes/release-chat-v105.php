<?php
declare(strict_types=1);

function release_v105_chat_intent(string $query): bool
{
    return (bool)preg_match('/\b(release|launch|release calendar|deadline|campaign plan|rollout|distribution date|drop date)\b/i', $query);
}

function release_v105_chat_tool(string $query, array $user, int $conversationId = 0): array
{
    $empty=['handled'=>false,'answer'=>'','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[]];
    if (!release_v105_schema_ready() || !permission_v105_has('release.manage',$user) || !release_v105_chat_intent($query)) return $empty;

    if (preg_match('/\b(open|show me|go to)\b.*\b(release|calendar)\b/i',$query)) {
        $result=$empty;$result['handled']=true;$result['answer']='Opening your Agent Operations Release Calendar.';
        $result['actions'][]=['type'=>'open_url','label'=>'Open Release Calendar','url'=>url('/admin/releases.php'),'auto'=>true];
        agent_tool_log($user,'release_calendar.open',$query,'success',[],$conversationId);
        return $result;
    }

    if (preg_match('/\b(list|show|what(?:\'s| is)|upcoming|next)\b.*\b(release|deadline|calendar|launch)\b/i',$query)) {
        $plans=release_v105_plans($user,12);$lines=[];
        foreach($plans as $plan){
            $target=trim((string)$plan['target_date']);
            $lines[]='• '.(string)$plan['title'].' — '.(string)$plan['status'].($target!==''?' · '.date('M j, Y',strtotime($target)):' · no target date').' · '.(int)$plan['complete_count'].'/'.(int)$plan['item_count'].' complete';
        }
        $result=$empty;$result['handled']=true;
        $result['answer']=$lines?"Here is the current Release Calendar:\n\n".implode("\n",$lines):'Your Release Calendar does not have any release plans yet.';
        $result['actions'][]=['type'=>'open_url','label'=>'Release Calendar','url'=>url('/admin/releases.php'),'auto'=>false];
        agent_tool_log($user,'release_calendar.list',$query,'success',['count'=>count($plans)],$conversationId);
        return $result;
    }

    if (preg_match('/\b(create|add|plan|schedule)\b\s+(?:a\s+)?(?:new\s+)?(?:release|launch)\s+(?:for\s+)?(.+?)\s+(?:on|for)\s+(.+)$/i',$query,$m)) {
        $title=trim($m[1]);$dateText=trim($m[2]);$ts=strtotime($dateText);
        if($title!==''&&$ts!==false){
            $pdo=db();$owner=release_v105_workspace_owner_id($user);
            $stmt=$pdo->prepare('INSERT INTO release_plans (owner_user_id,created_by_user_id,title,release_type,status,priority,target_date,agent_goal) VALUES (?,?,?,?,?,?,?,?)');
            $stmt->execute([$owner,(int)$user['id'],mb_substr($title,0,190),'single','planning','normal',date('Y-m-d H:i:s',$ts),'Coordinate the release plan, assets, deadlines, resources and approved outreach work.']);
            $id=(int)$pdo->lastInsertId();
            agent_tool_log($user,'release_calendar.create',$query,'success',['release_id'=>$id,'target_date'=>date('c',$ts)],$conversationId);
            $result=$empty;$result['handled']=true;$result['answer']='Created the release plan “'.$title.'” for '.date('F j, Y',$ts).'. I can now use it as an Agent Brain planning object and attach work, resources and approved external actions.';
            $result['actions'][]=['type'=>'open_url','label'=>'Open Release Plan','url'=>url('/admin/releases.php?release='.$id),'auto'=>false];
            return $result;
        }
    }

    return $empty;
}

function chat_generate_answer_v105(string $query, array $history, array $user, array $agentContext=[]): array
{
    $context=chat_context($query,$user);

    if($agentContext&&function_exists('agent_surface_v131_context_item')){
        array_unshift($context,agent_surface_v131_context_item($agentContext));
    }

    if (release_v105_schema_ready() && permission_v105_has('release.manage',$user)) {
        $releaseContext=release_v105_agent_context($user, release_v105_chat_intent($query)?10:4);
        if($releaseContext){
            $context=array_merge($releaseContext,$context);
        }else{
            $resourceLines=[];
            foreach(array_slice(release_v105_resources($user,20),0,12) as $resource){
                $resourceLines[]='Resource #'.(int)$resource['id'].' · '.(string)$resource['resource_type'].' · '.(string)$resource['title'].((string)$resource['provider_key']!==''?' · provider '.(string)$resource['provider_key']:'');
            }
            foreach(release_v105_integrations($user) as $integration){
                $resourceLines[]='Integration '.(string)$integration['provider_key'].' · '.(string)$integration['status'].' · '.((string)$integration['label']?: (string)$integration['connection_key']);
            }
            if($resourceLines)$context[]=['source'=>'agent-brain:operations-resources','title'=>'Agent Operations resources and connected capabilities','text'=>implode("\n",$resourceLines)];
        }
        $context[]=[
            'source'=>'agent:release-operations-tool',
            'title'=>'Agent Operations tool contract',
            'text'=>'Release Calendar is a first-class Agent Brain tool even before the first release is created. It coordinates release plans, due work, tracks, shows, resources, contact lists, documents, websites and audited external actions. External actions such as Gmail, SMS or publishing are represented as provider actions and may require approval before side effects. Never claim an external action was sent unless its action record reports completion.',
        ];
    }

    if (release_v105_schema_ready() && preg_match('/\b(credit|credits|credited|contributor|producer|songwriter|engineer)\b/i',$query)) {
        $track=agent_tool_find_track($query,$user);
        if($track&&permission_v105_track_allowed($track,$user)){
            $rows=credits_v105_rows($user,(int)$track['id']);$lines=[];
            foreach($rows as $row){$lines[]=(string)$row['display_name'].' · '.(string)$row['contribution_role'].(trim((string)$row['contribution_detail'])!==''?' · '.(string)$row['contribution_detail']:'');}
            if($lines)$context[]=['source'=>'agent-brain:credits:'.(int)$track['id'],'title'=>'Credits graph · '.(string)$track['title'],'text'=>implode("\n",$lines)];
        }
    }

    $context=array_slice($context,0,32);
    $answer=chat_remote_answer($query,$history,$context,$user);
    if($answer===null)$answer=chat_local_answer($query,$context);
    return ['answer'=>$answer,'context'=>$context];
}
