<?php
declare(strict_types=1);

/** Seed the exact action/receipt/Chat association used before shared routing. */
function initiative_fixture_legacy_report(PDO $pdo,array $user,int $id,array $goal,array $result,?array $meetingPrep=null): array
{
    if (($result['initiative_delivery'] ?? null) !== 'private') {
        throw new LogicException('The legacy fixture requires a verified private result.');
    }
    $binding=$goal['binding'];$owner=(int)$user['id'];$agent=vp3_initiative_binding_agent_v1($binding);
    return vp3_initiative_in_scope_v1($user,$agent,static function()use($pdo,$user,$goal,$result,$binding,$owner,$id,$meetingPrep):array{
        return agent_commerce_atomic_pa2($pdo,static function()use($pdo,$user,$goal,$result,$binding,$owner,$id,$meetingPrep):array{
            $evidence=vp3_initiative_work_sources_v1($pdo,$user,$binding,$goal);
            $q=$pdo->prepare("SELECT a.id AS action_id,p.id AS receipt_id,a.result_json,p.result_json AS receipt_json
                FROM agent_workflow_actions a JOIN agent_workflow_receipts p
                    ON p.run_id=a.run_id AND p.action_id=a.id AND p.owner_user_id=a.owner_user_id
                    AND p.attempt_no=a.attempt_count AND p.status='completed' AND p.executor='cloud'
                WHERE a.run_id=? AND a.owner_user_id=? AND a.status='completed'
                    AND a.sequence_no=1 AND a.action_key='initiative.prepare'
                    AND a.capability_key='initiative.prepare' AND a.execution_target='cloud'");
            $q->execute([$id,$owner]);
            $proofs=$q->fetchAll(PDO::FETCH_ASSOC);
            $proof=$proofs[0]??null;
            if (count($proofs)!==1 || $proof['result_json']!==$proof['receipt_json']
                || json_decode((string)$proof['result_json'],true,32,JSON_THROW_ON_ERROR)!==$result) {
                throw new LogicException('The legacy fixture requires a matching completed action receipt.');
            }
            $result['initiative_message_id']=vp3_initiative_report_v1($pdo,$user,(int)$binding['conversation_id'],
                $result['initiative_draft'],$evidence,$id,$result['initiative_tool_result'],$meetingPrep);
            $result['initiative_delivery']='delivered';
            vp3_initiative_delivery_result_v1($pdo,$owner,['run_id'=>$id,
                'action_id'=>(int)$proof['action_id'],'receipt_id'=>(int)$proof['receipt_id']],$result);
            return $result;
        });
    });
}
