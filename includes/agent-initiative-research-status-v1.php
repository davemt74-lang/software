<?php
declare(strict_types=1);

require_once __DIR__.'/agent-top-of-mind-v1.php';
require_once __DIR__.'/agent-initiative-artist-v1.php';

/** Read only the reportable research lane of the exact saved policy. */
function vp3_initiative_research_status_candidates_v1(PDO $pdo,int $owner,int $agentId,array $policy,int $now): array
{
    $sqlite=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
    $json=static function(string $column,string $path)use($sqlite):string{
        $value="JSON_EXTRACT(CASE WHEN JSON_VALID($column) THEN $column ELSE '{}' END,'$path')";
        return $sqlite?$value:"JSON_UNQUOTE($value)";
    };
    $conversation=$json('r.goal','$.binding.conversation_id');
    $revision=$json('r.goal','$.binding.revision');
    $tool=$json('r.goal','$.tool');
    $report=$json('r.goal','$.report');
    $true=$sqlite?'1':"'true'";
    $stmt=$pdo->prepare("SELECT r.id AS run_id,r.goal,r.status AS run_status,r.lease_expires_at,
        a.id AS action_id,a.result_json,a.completed_at AS action_completed_at
        FROM agent_workflow_runs r JOIN agent_workflow_actions a
            ON a.run_id=r.id AND a.owner_user_id=r.owner_user_id AND a.sequence_no=1
            AND a.action_key='initiative.prepare' AND a.capability_key='initiative.prepare' AND a.execution_target='cloud'
        WHERE r.owner_user_id=? AND CAST(COALESCE(r.agent_id,0) AS CHAR)=?
            AND r.source_kind='agent_initiative' AND r.capability_key='initiative.prepare' AND r.execution_target='cloud'
            AND CAST($conversation AS CHAR)=? AND $revision=?
            AND $tool IN ('topic.research','artist.research') AND $report=$true
            AND ((r.status='executing' AND a.status='executing' AND r.current_action_id=a.id AND r.lease_expires_at>?)
                OR (r.status IN ('queued','approved') AND a.status='queued')
                OR (r.status='failed' AND a.status='failed' AND a.completed_at>=?))
        ORDER BY CASE r.status WHEN 'executing' THEN 0 WHEN 'failed' THEN 2 ELSE 1 END,r.id DESC LIMIT 8");
    $stmt->execute([$owner,(string)$agentId,(string)$policy['conversation_id'],$policy['revision'],
        gmdate('Y-m-d H:i:s',$now),gmdate('Y-m-d H:i:s',$now-120)]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function vp3_initiative_research_status_failed_v1(PDO $pdo,int $owner,array $row): bool
{
    $stmt=$pdo->prepare('SELECT status,result_json FROM agent_workflow_receipts
        WHERE owner_user_id=? AND run_id=? AND action_id=? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$owner,(int)$row['run_id'],(int)$row['action_id']]);
    $receipt=$stmt->fetch(PDO::FETCH_ASSOC);
    return ($receipt['status']??null)==='failed'&&($receipt['result_json']??null)===$row['result_json'];
}

/** Current private Chat status only. Polling neither starts work nor delivers it. */
function vp3_initiative_research_status_v1(PDO $pdo,array $user,int $agentId,int $cid): array
{
    return vp3_initiative_in_scope_v1($user,$agentId,static function()use($pdo,$user,$agentId,$cid):array{
        $context=vp3_initiative_runtime_context_v1($pdo,$user,$agentId,$cid);
        $live=$context['user'];$policy=$context['policy'];$owner=(int)$live['id'];
        $status=['owner_user_id'=>$owner,'agent_id'=>$agentId,'conversation_id'=>$cid,'specialist'=>null];
        if(($policy['enabled']??false)!==true||($policy['conversation_id']??null)!==$cid)return $status;
        $live=vp3_initiative_access_v1($pdo,$live,$policy);
        $now=time();$candidates=[];$refs=[];
        foreach(vp3_initiative_research_status_candidates_v1($pdo,$owner,$agentId,$policy,$now) as $row){
            $goal=json_decode((string)$row['goal'],true);
            $kind=match($goal['tool']??null){'topic.research'=>'topic','artist.research'=>'artist',default=>''};
            $primary=$goal['primary']??null;$sources=$goal['sources']??null;
            if($kind===''||($goal['binding']??null)!==$policy||($goal['report']??null)!==true||!is_string($primary)
                ||!preg_match('/\A'.$kind.'-interest:[a-f0-9]{64}\z/D',$primary)
                ||!is_array($sources)||array_keys($sources)!==[$primary]
                ||!is_string($sources[$primary])||!preg_match('/\A[a-f0-9]{64}\z/D',$sources[$primary]))continue;
            $item=['run_id'=>(int)$row['run_id'],'kind'=>$kind,'state'=>'queued'];
            $expires=null;
            if($row['run_status']==='executing'){
                $expires=vp3_top_of_mind_lease_time_v1($row['lease_expires_at']);
                if($expires<=$now)continue;
                $item['state']='running';$item['lease_expires_at']=gmdate(DATE_ATOM,$expires);
            }elseif($row['run_status']==='failed'){
                $ended=vp3_top_of_mind_lease_time_v1($row['action_completed_at']);
                if($ended<=$now-120||$ended>$now||!vp3_initiative_research_status_failed_v1($pdo,$owner,$row))continue;
                $expires=$ended+120;$item['state']='failed';
            }
            $candidates[]=['primary'=>$primary,'revision'=>$sources[$primary],'expires'=>$expires,'item'=>$item];
            $refs[$primary]=true;
        }
        if(!$candidates)return $status;
        $coverage=[];
        $sources=vp3_initiative_research_evidence_v1($pdo,['user'=>$live,'agent_id'=>$agentId],
            new DateTimeImmutable('now',new DateTimeZone('UTC')),array_keys($refs),$coverage);
        vp3_initiative_access_v1($pdo,$live,$policy);
        if(!agent_connection_youtube_enabled_v1()||!agent_connection_youtube_consent_v1($pdo,$owner))return $status;
        foreach($candidates as $candidate){
            $source=$sources[$candidate['primary']]??null;
            $item=$candidate['item'];
            if(($source['revision']??null)!==$candidate['revision']||($source['kind']??null)!==$item['kind'].'_interest'
                ||($candidate['expires']!==null&&$candidate['expires']<=time())
                ||!vp3_youtube_research_enabled_v1($pdo,$owner,$item['kind']))continue;
            $subject=vp3_top_of_mind_text_v1($source['title']??null,120);
            if($subject==='')continue;
            $status['specialist']=$item+['subject'=>$subject];
            break;
        }
        return $status;
    });
}
