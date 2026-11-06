<?php
declare(strict_types=1);

require_once __DIR__.'/agent-initiative-followthrough-v1.php';

function vp3_initiative_followup_hash_v1(mixed $value): string
{
    return hash('sha256',json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
}

/** Historical origin is verified separately from current schedule/source state.
 * A creating action's old effect snapshot is never used as today's reminder. */
function vp3_initiative_followup_origin_v1(PDO $pdo,array $user,array $principal,int $reminderId): array
{
    if($reminderId<1||vp3_participant_scope_v1()!==null)throw new RuntimeException('Follow-up access changed.',403);
    $q=$pdo->prepare('SELECT * FROM agent_reminders_v1 WHERE id=? AND owner_user_id=?');$q->execute([$reminderId,(int)$user['id']]);$reminder=$q->fetch(PDO::FETCH_ASSOC);
    $actionId=(int)($reminder['followup_action_id']??0);
    if(!$reminder||$actionId<1)throw new RuntimeException('Linked follow-up is unavailable.',409);
    $q=$pdo->prepare('SELECT run_id FROM agent_workflow_actions WHERE id=? AND owner_user_id=? AND capability_key=?');
    $q->execute([$actionId,(int)$user['id'],'initiative.action']);$runId=(int)$q->fetchColumn();
    $run=$runId?agent_workflow_row_v1400($pdo,(int)$user['id'],$runId,$pdo->inTransaction()):null;
    $goal=$run?json_decode((string)$run['goal'],true,64,JSON_THROW_ON_ERROR):null;$binding=$goal['binding']??null;
    if(!is_array($binding)||($binding['owner_user_id']??null)!==(int)$user['id']
        ||($binding['agent_id']??null)!==(int)($principal['agent_id']??0)
        ||($goal['plan']['adapter']??null)!=='proposal.revisit.create'
        ||(int)$reminder['conversation_id']!==($binding['conversation_id']??null))throw new RuntimeException('Follow-up origin changed.',403);
    $origin=vp3_initiative_followthrough_origin_load_v1($pdo,$user,$principal,$binding['conversation_id'],$binding['proposal_message_id'],$pdo->inTransaction());
    $copy=$origin;$copy['state']['action_run_id']=$runId;
    $creating=vp3_initiative_action_load_v1($pdo,$copy);
    $saved=$creating['result']['domain_result']??null;
    if(!$creating||$creating['run']['status']!=='completed'||(int)$creating['action']['id']!==$actionId
        ||!is_array($saved)||($saved['record_id']??null)!==$reminderId||($saved['action_id']??null)!=='initiative-action:'.$actionId
        ||($saved['followup_origin']??null)!==($creating['goal']['plan']['origin']??null)
        ||($creating['goal']['proposal_goal_hash']??null)!==$origin['state']['goal_hash'])throw new RuntimeException('Follow-up creation receipt changed.',409);
    $condition=$creating['goal']['plan']['condition']??null;
    $stored=$reminder['followup_condition_json']??null;
    if(($condition===null)!==($stored===null)||($condition!==null&&json_decode((string)$stored,true,16,JSON_THROW_ON_ERROR)!==$condition))throw new RuntimeException('Follow-up condition commitment changed.',409);
    $sourceOrigin=$creating['goal']['plan']['source_origin']??null;
    if(!is_array($sourceOrigin)||($sourceOrigin['owner_user_id']??null)!==(int)$user['id']||($sourceOrigin['agent_id']??null)!==$origin['agent_id']
        ||!is_int($sourceOrigin['conversation_id']??null)||!is_int($sourceOrigin['message_id']??null)||!is_string($sourceOrigin['goal_hash']??null))throw new RuntimeException('Follow-up canonical source origin changed.',409);
    $sourceLoaded=vp3_initiative_followthrough_origin_load_v1($pdo,$user,$principal,$sourceOrigin['conversation_id'],$sourceOrigin['message_id'],$pdo->inTransaction());
    if(!hash_equals($sourceLoaded['state']['goal_hash'],$sourceOrigin['goal_hash']))throw new RuntimeException('Follow-up canonical source goal changed.',409);
    $hash=vp3_initiative_followup_hash_v1(['followup-origin-v1',$actionId,$reminderId,$creating['goal']['plan_hash'],$creating['result']['approval_message_id'],$origin['state']['goal_hash']]);
    return ['user'=>$origin['user'],'principal'=>$origin['principal'],'agent_id'=>$origin['agent_id'],
        'origin'=>$origin,'source_origin'=>$sourceOrigin,'source_origin_loaded'=>$sourceLoaded,'creating_action'=>$creating,'reminder'=>$reminder,'origin_hash'=>$hash];
}

/** Reassess exactly the original canonical IDs using current grants and values.
 * Original prose/revisions and a past approval never authorize new work. */
function vp3_initiative_followup_refresh_v1(PDO $pdo,array $linked): array
{
    $user=$linked['user'];$aid=$linked['agent_id'];
    return vp3_initiative_in_scope_v1($user,$aid,static function()use($pdo,$linked,$user,$aid):array{
        $policy=vp3_initiative_policy_v1($user,$aid);$live=vp3_initiative_access_v1($pdo,$user,$policy);
        $principal=vp3_initiative_principal_v1($pdo,$live,$aid);$old=$linked['source_origin_loaded']['goal'];$refs=array_keys($old['sources']);
        $scope=['user'=>$live,'principal'=>$principal,'agent_id'=>$aid,'conversation_id'=>$policy['conversation_id'],'verification_only'=>true];
        $map=vp3_initiative_capability_saved_source_map_v1($old);$records=[];$revisions=[];
        vp3_initiative_followup_action_source_authority_v1($live,['proposal_source_refs'=>$refs]);
        foreach($refs as $ref){
            if(str_starts_with($ref,'live:')||str_starts_with($ref,'live-context:'))throw new RuntimeException('Live observations require a fresh owner session.',403);
            if(preg_match('/\A(?:task|open-task):([1-9][0-9]*)\z/D',$ref,$match)){
                // The ordinary action reader includes closed tasks and rechecks
                // namespace, current personal permissions and exact data policy.
                $task=vp3_initiative_office_action_task_v1($pdo,$live,$principal,(int)$match[1]);
                $q=$pdo->prepare('SELECT memory_text FROM agent_memory_items WHERE id=? AND user_id=?');$q->execute([$task['id'],(int)$live['id']]);$text=(string)$q->fetchColumn();
                $records[]=['ref'=>$ref,'kind'=>'task','title'=>$task['title'],'status'=>$task['status'],'text'=>mb_strcut($text,0,3000,'UTF-8'),'content_truncated'=>strlen($text)>3000];$revisions[$ref]=$task['revision'];continue;
            }
            if(preg_match('/\Atask-list:([1-9][0-9]*)\z/D',$ref,$match)){
                require_once __DIR__.'/agent-initiative-task-lists-v1.php';
                $listScope=vp3_initiative_task_list_scope_v1($pdo,$scope,$policy);$state=vp3_initiative_task_list_state_v1($pdo,$listScope,(int)$match[1]);
                if(!$state)throw new RuntimeException('Follow-up list is no longer available.',409);
                $entry=vp3_initiative_task_list_entry_v1($ref,$state);$revisions[$ref]=$entry['revision'];
                $records[]=['ref'=>$ref,'kind'=>'task_list','title'=>$state['title'],'progress'=>$state['progress'],'members'=>$state['members']];continue;
            }
            $coverage=[];
            if(isset($map[$ref])){
                $evidence=vp3_initiative_capabilities_evidence_v1($pdo,$scope,$policy,new DateTimeImmutable('now',new DateTimeZone('UTC')),[$ref],$coverage);
                if(!isset($evidence[$ref]))throw new RuntimeException('A follow-up source is no longer available.',409);
                $tool=$map[$ref];$read=vp3_initiative_capability_snapshot_v1($pdo,$scope,$policy,$tool,
                    ['tool'=>$tool,'primary'=>$ref,'refs'=>[$ref],'focus'=>$linked['reminder']['title'],'report'=>true],$evidence);
                $revisions[$ref]=vp3_initiative_followup_hash_v1([$evidence[$ref]['revision'],$read]);
                $records[]=['ref'=>$ref,'kind'=>'canonical_read','records'=>$read['records'],'coverage'=>$read['coverage']];continue;
            }
            $evidence=vp3_initiative_evidence_v1($pdo,$live,new DateTimeImmutable('now',new DateTimeZone('UTC')),
                !empty($old['binding']['screen_enabled'])&&!empty($policy['screen_enabled']),[],$coverage,[$ref],$aid);
            if(!isset($evidence[$ref]))throw new RuntimeException('A follow-up source is no longer available.',409);
            $row=$evidence[$ref];$revisions[$ref]=$row['revision'];$records[]=['ref'=>$ref,'kind'=>$row['kind'],'title'=>$row['title'],'detail'=>$row['detail']??''];
        }
        $condition=$linked['creating_action']['goal']['plan']['condition']??null;$completion=null;
        if($condition!==null){
            $completion=vp3_initiative_followup_condition_check_v1($pdo,$scope,$condition);
            if($completion===null)throw new RuntimeException('Follow-up is still waiting for its reviewed condition.',409);
        }
        $context=['condition_completion'=>$completion,'records'=>$records,'source_revisions'=>$revisions,'coverage'=>'exact_original_sources','permission'=>'Fresh private reassessment only. No previous action approval is reused.'];
        if(strlen(json_encode($context,JSON_THROW_ON_ERROR))>65536)throw new RuntimeException('Follow-up context exceeds its verified read bound.',503);
        return ['user'=>$live,'principal'=>$principal,'binding'=>$policy,'current_context'=>$context,
            'context_hash'=>vp3_initiative_followup_hash_v1($context),'origin_hash'=>$linked['origin_hash'],
            'focus'=>$linked['reminder']['title']];
    });
}

/** Follow-up scheduling changes no original proposal resource. The ordinary
 * loader proves its current readonly/native outcome before and after the write. */
function vp3_initiative_followup_sources_prepare_v1(PDO $pdo,array $loaded): array
{
    if(!$pdo->inTransaction())throw new RuntimeException('Follow-up source fence requires the owner transaction.',409);
    $fresh=vp3_initiative_followthrough_load_v1($pdo,$loaded['user'],$loaded['principal'],$loaded['conversation_id'],(int)$loaded['message']['id'],true);
    return ['version'=>1,'goal_hash'=>$fresh['state']['goal_hash'],'reply_ref'=>$fresh['state']['reply_ref'],
        'evidence_hash'=>vp3_initiative_followup_hash_v1($fresh['evidence'])];
}
function vp3_initiative_followup_sources_capture_v1(PDO $pdo,array $loaded,array $plan,array $result,array $before): array
{
    $after=vp3_initiative_followup_sources_prepare_v1($pdo,$loaded);
    if($after!==$before)throw new RuntimeException('Proposal sources changed while scheduling its revisit.',409);
    vp3_initiative_followup_action_inspect_v1($pdo,$loaded['user'],$loaded['principal'],$plan['origin'],$plan,$result);
    return $before+['domain_hash'=>vp3_initiative_followup_hash_v1($result),'plan_hash'=>vp3_initiative_followup_hash_v1($plan)];
}
function vp3_initiative_followup_sources_current_v1(PDO $pdo,array $loaded,array $plan,array $result,array $proof): void
{
    if(($proof['version']??null)!==1||($proof['goal_hash']??null)!==$loaded['state']['goal_hash']||($proof['reply_ref']??null)!==$loaded['state']['reply_ref']
        ||($proof['evidence_hash']??null)!==vp3_initiative_followup_hash_v1($loaded['evidence'])
        ||($proof['domain_hash']??null)!==vp3_initiative_followup_hash_v1($result)||($proof['plan_hash']??null)!==vp3_initiative_followup_hash_v1($plan))
        throw new RuntimeException('Follow-up source receipt changed.',409);
    vp3_initiative_followup_action_inspect_v1($pdo,$loaded['user'],$loaded['principal'],$plan['origin'],$plan,$result);
}
