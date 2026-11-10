<?php
declare(strict_types=1);

require_once __DIR__.'/agent-initiative-runtime-v1.php';
require_once __DIR__.'/agent-initiative-brainstorm-v1.php';

/** A private read model of the latest verified assessment, never an action grant. */
function vp3_initiative_opportunities_v1(PDO $pdo,array $user,int $agentId): array
{
    return vp3_initiative_in_scope_v1($user,$agentId,static function()use($pdo,$user,$agentId):array{
        if(vp3_participant_scope_v1()!==null)throw new RuntimeException('Private Agent access is required.',403);
        $policy=vp3_initiative_policy_v1($user,$agentId);
        $cid=$policy['conversation_id']??0;
        if(($policy['enabled']??false)!==true||!is_int($cid)||$cid<1)return [];
        $context=vp3_initiative_runtime_context_v1($pdo,$user,$agentId,$cid);
        if($context['policy']!==$policy)throw new RuntimeException('Agent work scope changed.',409);
        $live=vp3_initiative_access_v1($pdo,$context['user'],$policy);
        $rows=[];$goal=[];$runId=0;
        foreach(vp3_initiative_runtime_runs_v1($pdo,(int)$live['id'],$agentId,$policy) as $run){
            if(($run['capability_key']??'')!=='initiative.plan'||($run['status']??'')!=='completed')continue;
            $q=$pdo->prepare('SELECT id,status,capability_key,result_json FROM agent_workflow_actions WHERE run_id=? AND owner_user_id=? ORDER BY sequence_no,id LIMIT 1');
            $q->execute([(int)$run['id'],(int)$live['id']]);$action=$q->fetch(PDO::FETCH_ASSOC);
            if(!$action||$action['status']!=='completed'||($action['capability_key']??null)!=='initiative.plan')continue;
            $q=$pdo->prepare('SELECT status,result_json FROM agent_workflow_receipts WHERE run_id=? AND action_id=? AND owner_user_id=? ORDER BY id DESC LIMIT 1');
            $q->execute([(int)$run['id'],(int)$action['id'],(int)$live['id']]);$receipt=$q->fetch(PDO::FETCH_ASSOC);
            if(!$receipt||$receipt['status']!=='completed'||$receipt['result_json']!==$action['result_json'])continue;
            $result=json_decode((string)$receipt['result_json'],true);
            if(!is_array($result)||!in_array($result['effect_state']??null,['assignments_queued','existing_assignments','idle'],true))continue;
            $typed=vp3_initiative_brainstorm_tool_result_v1(is_array($result['initiative_tool_result']??null)?$result['initiative_tool_result']:[]);
            // A legacy or empty latest assessment supersedes older ideas too.
            $rows=$typed['output']['opportunities']??[];
            $goal=json_decode((string)$run['goal'],true);$runId=(int)$run['id'];
            break;
        }
        if(!$rows||!is_array($goal['sources']??null))return [];
        $selected=$goal['sources'];
        if(!$selected||count($selected)>512)throw new RuntimeException('Assessment source binding changed.',409);
        foreach($selected as $ref=>$revision){
            if(!is_string($ref)||$ref===''||strlen($ref)>190||!is_string($revision)||!preg_match('/\A[a-f0-9]{64}\z/D',$revision)){
                throw new RuntimeException('Assessment source binding changed.',409);
            }
        }
        foreach($rows as $row)foreach($row['source_revisions'] as $ref=>$revision){
            if(($goal['sources'][$ref]??null)!==$revision)throw new RuntimeException('Assessment source binding changed.',409);
        }
        $read=static function(array $live)use($pdo,$policy,$selected,$goal,$agentId):array{
            // The model saw the entire assessment snapshot. Revalidate all of
            // it, not just model-declared refs, before releasing private text.
            return vp3_initiative_current_sources_v1($pdo,$live,$selected,!empty($policy['screen_enabled']),
                $goal['canonical_sources']??[],$agentId,$goal['capability_sources']??[]);
        };
        $current=$read($live);
        require_once __DIR__.'/agent-initiative-tools-v1.php';
        $tools=vp3_initiative_tools_catalog_v1($pdo,$live,$policy,$current);
        $filter=static function(array $rows,array $sources,array $tools):array{
            return array_values(array_filter($rows,static function(array $row)use($sources,$tools):bool{
                $tool=$tools[$row['tool']]??[];
                if(empty($tool['ready'])||isset($tool['source_refs'])&&!in_array($row['primary'],$tool['source_refs'],true))return false;
                foreach($row['source_revisions'] as $ref=>$revision)if(($sources[$ref]['revision']??null)!==$revision)return false;
                return true;
            }));
        };
        $rows=$filter($rows,$current,$tools);
        $fresh=vp3_initiative_runtime_context_v1($pdo,$live,$agentId,$cid);
        if($fresh['policy']!==$policy)throw new RuntimeException('Agent work scope changed.',409);
        $live=vp3_initiative_access_v1($pdo,$fresh['user'],$policy);
        $final=$read($live);
        $rows=$filter($rows,$final,vp3_initiative_tools_catalog_v1($pdo,$live,$policy,$final));
        return array_map(static fn(array $row):array=>[
            'id'=>$row['id'],'run_id'=>$runId,'objective'=>$row['objective'],'rationale'=>$row['rationale'],
            'state'=>$row['status'],'conversation_id'=>$cid,
        ],$rows);
    });
}
