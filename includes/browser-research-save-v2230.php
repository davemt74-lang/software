<?php
declare(strict_types=1);

require_once __DIR__.'/browser-research-runtime-v2230.php';

function vp3_browser_research_memo_v2230(PDO $pdo,array $mission): string
{
    return vp3_browser_research_memo_preview_v2230($pdo,$mission);
}

function vp3_browser_research_save_v2230(PDO $pdo,array $user,string $missionPublicId,string $projectPublicId=''): array
{
    $uid=(int)$user['id'];$owns=!$pdo->inTransaction();
    if($owns)$pdo->beginTransaction();
    try{
        $mission=vp3_browser_research_mission_row_v2230($pdo,$uid,$missionPublicId,true);
        if(!$mission)throw new RuntimeException('Browser Research mission was not found.');
        if(in_array((string)$mission['status'],['cancelled','expired'],true))throw new RuntimeException('This Research mission can no longer be saved.');
        if((string)($mission['report_public_id']??'')!==''){
            if($owns)$pdo->commit();
            return vp3_browser_research_public_v2230($pdo,$user,$missionPublicId);
        }

        $project=vp3_browser_research_project_v2230($pdo,$uid,$projectPublicId);
        if(!$project&&(int)($mission['project_id']??0)>0){
            $candidate=vp3_research_project_row_by_id_v2060($pdo,(int)$mission['project_id']);
            if($candidate&&vp3_research_role_at_least_v2060(vp3_research_project_role_v2060($pdo,$candidate,$uid),'researcher'))$project=$candidate;
        }
        if(!$project)throw new RuntimeException('Choose a Research project before saving this mission.');
        $claims=vp3_browser_research_claims_v2230($pdo,$mission);
        if(!$claims)throw new RuntimeException('Analyze at least one page with supported claims before saving.');

        $pageItems=[];
        foreach(vp3_browser_research_pages_v2230($pdo,$mission) as $page){
            $sourcePublic=(string)$page['source_public_id'];if($sourcePublic==='')continue;
            $source=vp3_browser_source_row_by_public_id_v2050($pdo,$sourcePublic);if(!$source)continue;
            $versionPublic=trim((string)($page['source_version_public_id']??''));$versionId=0;
            if($versionPublic!==''){
                $versionStmt=$pdo->prepare('SELECT id FROM browser_source_versions_v2050 WHERE public_id=? AND source_id=? LIMIT 1');
                $versionStmt->execute([$versionPublic,(int)$source['id']]);$versionId=(int)$versionStmt->fetchColumn();
            }
            if($versionId<1)continue;
            $item=vp3_research_insert_item_v2060($pdo,$project,$uid,'source',null,(int)$source['id'],$versionId,'Browser Research Agent evidence source.',['browser-research-v2230']);
            $pageItems[(int)$page['id']]=(string)$item['public_id'];
        }

        $groups=[];
        foreach($claims as $claim){
            $claim['evidence']=vp3_browser_research_evidence_v2230($pdo,(int)$claim['id']);
            $groups[(string)$claim['claim_key']][]=$claim;
        }

        $reportItems=[];
        foreach($groups as $key=>$variants){
            foreach($variants as $claim){
                $title=ucwords(str_replace(['.','_','-'],' ',$key));
                if(count($variants)>1)$title.=' — '.$claim['value_text'];
                $domains=[];foreach($claim['evidence'] as $e)$domains[(string)$e['domain']]=true;
                $body=$claim['statement_text']."\n\nObserved value: ".$claim['value_text'].
                    "\nEvidence state: ".str_replace('_',' ',(string)$claim['evidence_state']).
                    "\nIndependent support groups: ".(int)$claim['support_sources'].
                    "\nPrimary-source groups: ".(int)$claim['primary_sources'].
                    "\nDirect evidence groups: ".(int)$claim['direct_sources'].
                    ((string)($claim['freshest_at']??'')!==''?"\nFreshest dated evidence: ".$claim['freshest_at']:'').
                    "\nSource domains: ".implode(', ',array_keys($domains));
                $finding=vp3_research_create_finding_v2060($pdo,$uid,(string)$project['public_id'],mb_strimwidth($title,0,190,''),$body);
                $findingRow=vp3_research_finding_row_v2060($pdo,(string)$finding['id']);
                if($findingRow){
                    foreach($claim['evidence'] as $e){
                        $itemId=$pageItems[(int)$e['page_id']]??'';
                        if($itemId!=='')vp3_research_link_evidence_by_ids_v2060(
                            $pdo,$project,(int)$findingRow['id'],$uid,$itemId,'support',
                            vp3_browser_research_text_v2230($e['evidence_excerpt'],700)
                        );
                    }
                    if(count($variants)>1)foreach($variants as $other){
                        if((int)$other['id']===(int)$claim['id'])continue;
                        foreach($other['evidence'] as $e){
                            $itemId=$pageItems[(int)$e['page_id']]??'';
                            if($itemId!=='')vp3_research_link_evidence_by_ids_v2060(
                                $pdo,$project,(int)$findingRow['id'],$uid,$itemId,'conflict',
                                'Different observed value for the same Browser Research claim key.'
                            );
                        }
                    }
                }
                $pdo->prepare("UPDATE browser_research_claims_v2230 SET research_finding_public_id=?,updated_at=UTC_TIMESTAMP() WHERE id=?")
                    ->execute([(string)$finding['id'],(int)$claim['id']]);
                $reportItems[]=['type'=>'finding','id'=>(string)$finding['id']];
            }
        }

        $memo=vp3_browser_research_memo_v2230($pdo,$mission);
        $report=vp3_research_create_report_v2060(
            $pdo,$uid,(string)$project['public_id'],
            mb_strimwidth('Browser Research · '.(string)$mission['question'],0,190,''),
            mb_strimwidth($memo,0,15000,'')
        );
        $report=vp3_research_set_report_items_v2060($pdo,$uid,(string)$project['public_id'],(string)$report['id'],$reportItems);
        $pdo->prepare("UPDATE browser_research_missions_v2230 SET project_id=?,memo_text=?,report_public_id=?,status='completed',saved_at=UTC_TIMESTAMP(),completed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=?")
            ->execute([(int)$project['id'],$memo,(string)$report['id'],(int)$mission['id']]);
        if($owns)$pdo->commit();
    }catch(Throwable $e){
        if($owns&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }

    if(function_exists('create_notification'))try{
        create_notification(
            $uid,'browser_research_saved','Browser Research draft is ready',
            'The mission was saved as draft Findings and a draft Research Report.',
            (string)($report['url']??'/research.php'),'browser_research',(int)$mission['id']
        );
    }catch(Throwable $e){
        error_log('Browser Research v22.30 save notification failed: '.$e->getMessage());
    }
    return vp3_browser_research_public_v2230($pdo,$user,$missionPublicId);
}

function vp3_browser_research_cancel_v2230(PDO $pdo,array $user,string $missionPublicId): array
{
    $mission=vp3_browser_research_mission_row_v2230($pdo,(int)$user['id'],$missionPublicId,true);
    if(!$mission)throw new RuntimeException('Browser Research mission was not found.');
    if(!in_array((string)$mission['status'],['completed','cancelled','expired'],true)){
        $pdo->prepare("UPDATE browser_research_missions_v2230 SET status='cancelled',cancelled_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=?")
            ->execute([(int)$mission['id']]);
    }
    return vp3_browser_research_public_v2230($pdo,$user,$missionPublicId);
}

function vp3_browser_research_for_workflow_v2230(PDO $pdo,int $uid,int $workflowRunId): array
{
    if($uid<1||$workflowRunId<1||!vp3_browser_research_schema_ready_v2230($pdo))return [];
    $stmt=$pdo->prepare("SELECT public_id FROM browser_research_missions_v2230 WHERE owner_user_id=? AND workflow_run_id=? ORDER BY id DESC LIMIT 5");
    $stmt->execute([$uid,$workflowRunId]);$out=[];$user=['id'=>$uid];
    foreach($stmt->fetchAll(PDO::FETCH_COLUMN)?:[] as $id){
        try{$out[]=vp3_browser_research_public_v2230($pdo,$user,(string)$id);}catch(Throwable $e){}
    }
    return $out;
}
