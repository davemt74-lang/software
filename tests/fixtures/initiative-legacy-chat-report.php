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
            $q=$pdo->prepare("SELECT id FROM agent_workflow_actions WHERE run_id=? AND owner_user_id=? AND status='completed'");
            $q->execute([$id,$owner]);
            $actionId=(int)$q->fetchColumn();
            if ($actionId < 1) throw new LogicException('The legacy fixture requires a completed action.');
            $result['initiative_message_id']=vp3_initiative_report_v1($pdo,$user,(int)$binding['conversation_id'],
                $result['initiative_draft'],$evidence,$id,$result['initiative_tool_result'],$meetingPrep);
            $result['initiative_delivery']='delivered';
            vp3_initiative_delivery_result_v1($pdo,$owner,['run_id'=>$id,'action_id'=>$actionId],$result);
            return $result;
        });
    });
}
