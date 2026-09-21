<?php
declare(strict_types=1);

require_once __DIR__.'/browser-research-schema-v2230.php';

function vp3_browser_research_runtime_v2230(PDO $pdo,array $user,string $namespace,string $runtimePublicId): array
{
    $runtime=vp3_browser_multisite_runtime_v2220($pdo,$user,$namespace,$runtimePublicId);
    if(in_array((string)$runtime['status'],['completed','cancelled','expired'],true))throw new RuntimeException('This Browser Runtime is no longer active.');
    if((string)$runtime['status']==='paused')throw new RuntimeException('Resume the Browser Runtime before using Browser Research.');
    if(strtotime((string)$runtime['expires_at'])<time())throw new RuntimeException('This Browser Runtime authority expired.');
    $actions=vp3_browser_delegation_json_array_v2190($runtime['allowed_actions_json']??'');
    if(!in_array('browser_research',$actions,true))throw new RuntimeException('Browser Research was not approved in this delegation.');
    $multi=vp3_browser_multisite_row_v2220($pdo,$runtime);
    if(!$multi||(string)$multi['status']==='closed')throw new RuntimeException('Attach the v22.20 Multi-Site Runtime before Browser Research.');
    $runtime['_multisite']=$multi;
    return $runtime;
}

function vp3_browser_research_allowed_domains_v2230(PDO $pdo,array $multi): array
{
    $out=[];
    foreach(vp3_browser_multisite_policies_v2220($pdo,$multi) as $policy){
        if((string)($policy['policy_mode']??'')==='blocked')continue;
        $domain=vp3_browser_delegation_domain_v2190($policy['domain']??'');
        if($domain!=='')$out[$domain]=true;
    }
    return array_slice(array_keys($out),0,VP3_BROWSER_RESEARCH_MAX_SOURCES_V2230);
}

function vp3_browser_research_mission_row_v2230(PDO $pdo,int $uid,string $publicId,bool $lock=false): ?array
{
    if(!preg_match('/^[a-f0-9-]{36}$/i',$publicId))return null;
    $sql="SELECT m.*,r.public_id runtime_public_id,r.agent_namespace,r.status runtime_status,r.expires_at runtime_expires_at
      FROM browser_research_missions_v2230 m
      INNER JOIN browser_agent_runtime_sessions_v2200 r ON r.id=m.runtime_session_id
      WHERE m.public_id=? AND m.owner_user_id=? LIMIT 1".($lock?' FOR UPDATE':'');
    $stmt=$pdo->prepare($sql);$stmt->execute([$publicId,$uid]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row)?$row:null;
}

function vp3_browser_research_project_v2230(PDO $pdo,int $uid,string $publicId): ?array
{
    $publicId=trim($publicId);
    if($publicId==='')return null;
    $project=vp3_research_project_row_v2060($pdo,$publicId);
    if(!$project||!vp3_research_role_at_least_v2060(vp3_research_project_role_v2060($pdo,$project,$uid),'researcher')){
        throw new RuntimeException('Choose a Research project where you can add findings.');
    }
    return $project;
}

function vp3_browser_research_start_v2230(PDO $pdo,array $user,string $namespace,?array $agent,string $runtimePublicId,array $input): array
{
    $uid=(int)$user['id'];
    $runtime=vp3_browser_research_runtime_v2230($pdo,$user,$namespace,$runtimePublicId);
    $multi=(array)$runtime['_multisite'];
    $domains=vp3_browser_research_allowed_domains_v2230($pdo,$multi);
    if(!$domains)throw new RuntimeException('This delegation has no active approved domains for research.');
    $question=vp3_browser_research_text_v2230($input['question']??'',2000);
    if(mb_strlen($question)<5)throw new InvalidArgumentException('Describe the research question or goal.');
    $project=vp3_browser_research_project_v2230($pdo,$uid,(string)($input['project_id']??''));

    $existing=$pdo->prepare("SELECT 1 FROM browser_research_missions_v2230 WHERE owner_user_id=? AND runtime_session_id=? AND status='active' LIMIT 1");
    $existing->execute([$uid,(int)$runtime['id']]);
    if($existing->fetchColumn())throw new RuntimeException('This Browser Runtime already has an active Research mission.');

    $maxSources=max(1,min(VP3_BROWSER_RESEARCH_MAX_SOURCES_V2230,(int)($input['max_sources']??count($domains)),count($domains)));
    $maxPages=max(1,min(VP3_BROWSER_RESEARCH_MAX_PAGES_V2230,(int)($input['max_pages']??10)));
    $maxClaims=max(5,min(VP3_BROWSER_RESEARCH_MAX_CLAIMS_V2230,(int)($input['max_claims']??50)));
    $duration=max(15,min(VP3_BROWSER_RESEARCH_MAX_DURATION_V2230,(int)($input['duration_minutes']??60)));

    $allowedSet=array_fill_keys($domains,true);$sourcePlan=[];$seen=[];
    foreach(is_array($input['source_plan']??null)?$input['source_plan']:[] as $raw){
        if(!is_array($raw))continue;
        $domain=vp3_browser_delegation_domain_v2190($raw['domain']??'');
        if($domain===''||!isset($allowedSet[$domain])||isset($seen[$domain]))continue;
        $seen[$domain]=true;$sourcePlan[]=['domain'=>$domain,'reason'=>vp3_browser_research_text_v2230($raw['reason']??'',300)];
        if(count($sourcePlan)>=$maxSources)break;
    }
    foreach($domains as $domain){
        if(count($sourcePlan)>=$maxSources)break;
        if(isset($seen[$domain]))continue;
        $seen[$domain]=true;$sourcePlan[]=['domain'=>$domain,'reason'=>'Approved source available to this Browser Research mission.'];
    }
    $domains=array_column($sourcePlan,'domain');
    $expires=min(strtotime((string)$runtime['expires_at']),time()+$duration*60);
    $public=vp3_browser_research_uuid_v2230();

    $pdo->prepare("INSERT INTO browser_research_missions_v2230
      (public_id,runtime_session_id,multisite_session_id,owner_user_id,agent_id,conversation_id,workflow_run_id,project_id,question,approved_domains_json,source_plan_json,max_sources,max_pages,max_claims,duration_minutes,status,expires_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'active',?)")
      ->execute([
          $public,(int)$runtime['id'],(int)$multi['id'],$uid,(int)($agent['id']??0)?:null,max(0,(int)($input['conversation_id']??0))?:null,(int)$runtime['workflow_run_id'],
          $project?(int)$project['id']:null,$question,json_encode($domains,JSON_UNESCAPED_SLASHES),
          json_encode($sourcePlan,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
          $maxSources,$maxPages,$maxClaims,$duration,gmdate('Y-m-d H:i:s',$expires)
      ]);
    vp3_browser_runtime_event_v2200($pdo,$runtime,'research_mission_started','Browser Research mission started inside the approved domain envelope.','browser_research',null,'active');
    return vp3_browser_research_public_v2230($pdo,$user,$public);
}

function vp3_browser_research_expire_v2230(PDO $pdo,array $mission): void
{
    if((string)$mission['status']!=='active')return;
    $runtimeTerminal=in_array((string)($mission['runtime_status']??''),['completed','cancelled','expired'],true);
    $authorityExpired=strtotime((string)$mission['expires_at'])<time()||strtotime((string)$mission['runtime_expires_at'])<time();
    if(!$runtimeTerminal&&!$authorityExpired)return;
    $pdo->prepare("UPDATE browser_research_missions_v2230 SET status='ready',updated_at=UTC_TIMESTAMP() WHERE id=?")
        ->execute([(int)$mission['id']]);
}

function vp3_browser_research_pages_v2230(PDO $pdo,array $mission): array
{
    $stmt=$pdo->prepare("SELECT * FROM browser_research_pages_v2230 WHERE mission_id=? ORDER BY collected_at DESC,id DESC");
    $stmt->execute([(int)$mission['id']]);return $stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
}

function vp3_browser_research_claims_v2230(PDO $pdo,array $mission): array
{
    $stmt=$pdo->prepare("SELECT * FROM browser_research_claims_v2230 WHERE mission_id=? ORDER BY claim_key,id");
    $stmt->execute([(int)$mission['id']]);return $stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
}

function vp3_browser_research_evidence_v2230(PDO $pdo,int $claimId): array
{
    $stmt=$pdo->prepare("SELECT e.*,p.domain,p.page_fingerprint,p.duplicate_group_hash,p.source_public_id,p.source_version_public_id,p.source_kind,p.freshness_date page_freshness
      FROM browser_research_evidence_v2230 e
      INNER JOIN browser_research_pages_v2230 p ON p.id=e.page_id
      WHERE e.claim_id=? ORDER BY e.id");
    $stmt->execute([$claimId]);return $stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
}

function vp3_browser_research_recalculate_v2230(PDO $pdo,array $mission,string $key): void
{
    $stmt=$pdo->prepare("SELECT * FROM browser_research_claims_v2230 WHERE mission_id=? AND claim_key=? ORDER BY id");
    $stmt->execute([(int)$mission['id'],$key]);$claims=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
    $conflicted=count($claims)>1;
    foreach($claims as $claim){
        $groups=[];$primary=[];$direct=[];$fresh='';
        foreach(vp3_browser_research_evidence_v2230($pdo,(int)$claim['id']) as $e){
            $group=(string)$e['duplicate_group_hash'];if($group==='')$group='domain:'.(string)$e['domain'];
            $groups[$group]=true;
            if((string)$e['source_kind']==='primary')$primary[$group]=true;
            if((string)$e['directness']==='direct')$direct[$group]=true;
            $d=vp3_browser_research_date_v2230($e['as_of_date']??$e['page_freshness']??'');
            if($d!==''&&($fresh===''||$d>$fresh))$fresh=$d;
        }
        $state=$conflicted?'conflicted':(count($groups)>=2?'corroborated':'single_source');
        $pdo->prepare("UPDATE browser_research_claims_v2230 SET evidence_state=?,support_sources=?,primary_sources=?,direct_sources=?,freshest_at=?,updated_at=UTC_TIMESTAMP() WHERE id=?")
            ->execute([$state,count($groups),count($primary),count($direct),$fresh!==''?$fresh:null,(int)$claim['id']]);
    }
}

function vp3_browser_research_add_page_v2230(PDO $pdo,array $mission,array $input,array $claims,array $gaps=[]): void
{
    $uid=(int)$mission['owner_user_id'];
    $pageFp=strtolower(trim((string)($input['page_fingerprint']??'')));
    $contentHash=strtolower(trim((string)($input['content_hash']??'')));
    if(!preg_match('/^[a-f0-9]{64}$/',$pageFp)||!preg_match('/^[a-f0-9]{64}$/',$contentHash))throw new InvalidArgumentException('Research page fingerprints are invalid.');
    $domain=vp3_browser_delegation_domain_v2190($input['domain']??'');
    $allowed=array_map('strval',vp3_browser_research_json_v2230($mission['approved_domains_json']??''));
    if($domain===''||!in_array($domain,$allowed,true))throw new RuntimeException('This page is outside the mission approved-source plan.');
    if(count(vp3_browser_research_pages_v2230($pdo,$mission))>=(int)$mission['max_pages'])throw new RuntimeException('This Research mission reached its bounded page limit.');
    if(count(vp3_browser_research_claims_v2230($pdo,$mission))>=(int)$mission['max_claims'])throw new RuntimeException('This Research mission reached its bounded claim limit.');

    $exists=$pdo->prepare("SELECT 1 FROM browser_research_pages_v2230 WHERE mission_id=? AND page_fingerprint=? LIMIT 1");
    $exists->execute([(int)$mission['id'],$pageFp]);
    if($exists->fetchColumn())throw new RuntimeException('This exact page is already in the Research mission.');

    $sourceKind=in_array((string)($input['source_kind']??''),['primary','secondary','unknown'],true)?(string)$input['source_kind']:'unknown';
    $fresh=vp3_browser_research_date_v2230($input['freshness_date']??'');
    $sourcePublic=trim((string)($input['source_public_id']??''));
    $versionPublic=trim((string)($input['source_version_public_id']??''));
    $pagePublic=vp3_browser_research_uuid_v2230();
    $pdo->prepare("INSERT INTO browser_research_pages_v2230
      (public_id,mission_id,owner_user_id,domain,page_fingerprint,content_hash,duplicate_group_hash,source_public_id,source_version_public_id,source_kind,freshness_date,extraction_status)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,'analyzed')")
      ->execute([$pagePublic,(int)$mission['id'],$uid,$domain,$pageFp,$contentHash,$contentHash,$sourcePublic,$versionPublic,$sourceKind,$fresh!==''?$fresh:null]);
    $pageId=(int)$pdo->lastInsertId();

    $existingCount=count(vp3_browser_research_claims_v2230($pdo,$mission));$touched=[];
    foreach(array_slice($claims,0,max(0,(int)$mission['max_claims']-$existingCount)) as $raw){
        if(!is_array($raw))continue;
        $statement=vp3_browser_research_text_v2230($raw['statement']??'',1200);
        $value=vp3_browser_research_text_v2230($raw['value']??'',1000);
        $excerpt=vp3_browser_research_text_v2230($raw['evidence']??'',VP3_BROWSER_RESEARCH_MAX_EVIDENCE_V2230);
        if($statement===''||$value===''||$excerpt==='')continue;
        $key=vp3_browser_research_key_v2230($raw['key']??'',$statement);$valueHash=hash('sha256',mb_strtolower($value));
        $find=$pdo->prepare("SELECT id FROM browser_research_claims_v2230 WHERE mission_id=? AND claim_key=? AND value_hash=? LIMIT 1");
        $find->execute([(int)$mission['id'],$key,$valueHash]);$claimId=(int)$find->fetchColumn();
        if($claimId<1){
            $pdo->prepare("INSERT INTO browser_research_claims_v2230
              (public_id,mission_id,owner_user_id,claim_key,statement_text,value_text,value_hash,evidence_state)
              VALUES (?,?,?,?,?,?,?,'single_source')")
              ->execute([vp3_browser_research_uuid_v2230(),(int)$mission['id'],$uid,$key,$statement,$value,$valueHash]);
            $claimId=(int)$pdo->lastInsertId();
        }
        $direct=in_array((string)($raw['directness']??''),['direct','inferred'],true)?(string)$raw['directness']:'direct';
        $asOf=vp3_browser_research_date_v2230($raw['as_of']??'');
        $pdo->prepare("INSERT IGNORE INTO browser_research_evidence_v2230
          (public_id,mission_id,claim_id,page_id,owner_user_id,evidence_excerpt,evidence_hash,directness,as_of_date)
          VALUES (?,?,?,?,?,?,?,?,?)")
          ->execute([vp3_browser_research_uuid_v2230(),(int)$mission['id'],$claimId,$pageId,$uid,$excerpt,hash('sha256',mb_strtolower($excerpt)),$direct,$asOf!==''?$asOf:null]);
        $touched[$key]=true;
    }
    foreach(array_keys($touched) as $key)vp3_browser_research_recalculate_v2230($pdo,$mission,$key);
    $pdo->prepare("UPDATE browser_research_pages_v2230 SET claim_count=(SELECT COUNT(DISTINCT claim_id) FROM browser_research_evidence_v2230 WHERE page_id=?) WHERE id=?")
        ->execute([$pageId,$pageId]);

    $allGaps=vp3_browser_research_json_v2230($mission['gaps_json']??'');$set=[];
    foreach($allGaps as $gap)$set[vp3_browser_research_text_v2230($gap,500)]=true;
    foreach(array_slice($gaps,0,8) as $gap){$gap=vp3_browser_research_text_v2230($gap,500);if($gap!=='')$set[$gap]=true;}
    unset($set['']);
    $pdo->prepare("UPDATE browser_research_missions_v2230 SET gaps_json=?,updated_at=UTC_TIMESTAMP() WHERE id=?")
        ->execute([json_encode(array_slice(array_keys($set),0,20),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),(int)$mission['id']]);
}

function vp3_browser_research_memo_preview_v2230(PDO $pdo,array $mission): string
{
    $groups=[];
    foreach(vp3_browser_research_claims_v2230($pdo,$mission) as $claim){
        $groups[(string)$claim['claim_key']][]=$claim;
    }
    $lines=['Browser Research Memo','',trim((string)$mission['question']),''];
    if(!$groups)$lines[]='No supported claims have been extracted yet.';
    foreach($groups as $key=>$claims){
        $lines[]=ucwords(str_replace(['.','_','-'],' ',$key));
        if(count($claims)>1)$lines[]='Conflict: approved sources report different observed values.';
        foreach($claims as $claim){
            $domains=[];
            foreach(vp3_browser_research_evidence_v2230($pdo,(int)$claim['id']) as $e)$domains[(string)$e['domain']]=true;
            $lines[]='- '.$claim['value_text'].' — '.$claim['statement_text'].' ['.str_replace('_',' ',(string)$claim['evidence_state']).'; sources: '.implode(', ',array_keys($domains)).']';
        }
        $lines[]='';
    }
    $gaps=vp3_browser_research_json_v2230($mission['gaps_json']??'');
    if($gaps){
        $lines[]='Research gaps';
        foreach($gaps as $gap)$lines[]='- '.vp3_browser_research_text_v2230($gap,500);
        $lines[]='';
    }
    $lines[]='Evidence states describe the collected record; they are not truth scores. Freshness does not determine correctness.';
    return mb_strimwidth(implode("\n",$lines),0,30000,'');
}

function vp3_browser_research_public_claim_v2230(PDO $pdo,array $claim): array
{
    $evidence=vp3_browser_research_evidence_v2230($pdo,(int)$claim['id']);
    return [
        'id'=>(string)$claim['public_id'],'key'=>(string)$claim['claim_key'],'statement'=>(string)$claim['statement_text'],'value'=>(string)$claim['value_text'],
        'state'=>(string)$claim['evidence_state'],'support_sources'=>(int)$claim['support_sources'],'primary_sources'=>(int)$claim['primary_sources'],
        'direct_sources'=>(int)$claim['direct_sources'],'freshest_at'=>(string)($claim['freshest_at']??''),
        'research_finding_id'=>(string)($claim['research_finding_public_id']??''),
        'evidence'=>array_map(static fn(array $e): array=>[
            'domain'=>(string)$e['domain'],'excerpt'=>(string)$e['evidence_excerpt'],'directness'=>(string)$e['directness'],
            'as_of'=>(string)($e['as_of_date']??''),'source_kind'=>(string)$e['source_kind'],'source_id'=>(string)$e['source_public_id'],
            'source_version_id'=>(string)$e['source_version_public_id'],'fingerprint'=>(string)$e['page_fingerprint'],
        ],$evidence),
    ];
}

function vp3_browser_research_public_v2230(PDO $pdo,array $user,string $missionPublicId): array
{
    $uid=(int)$user['id'];$mission=vp3_browser_research_mission_row_v2230($pdo,$uid,$missionPublicId);
    if(!$mission)throw new RuntimeException('Browser Research mission was not found.');
    vp3_browser_research_expire_v2230($pdo,$mission);
    $mission=vp3_browser_research_mission_row_v2230($pdo,$uid,$missionPublicId)?:$mission;
    $pages=vp3_browser_research_pages_v2230($pdo,$mission);$claims=vp3_browser_research_claims_v2230($pdo,$mission);
    $domains=[];foreach(vp3_browser_research_json_v2230($mission['approved_domains_json']??'') as $d)$domains[(string)$d]=0;
    foreach($pages as $p)$domains[(string)$p['domain']]=($domains[(string)$p['domain']]??0)+1;
    $planRows=vp3_browser_research_json_v2230($mission['source_plan_json']??'');$sourcePlan=[];
    foreach($planRows as $plan){
        if(!is_array($plan))continue;$domain=(string)($plan['domain']??'');if($domain===''||!array_key_exists($domain,$domains))continue;
        $sourcePlan[]=['domain'=>$domain,'reason'=>(string)($plan['reason']??''),'pages'=>$domains[$domain],'checked'=>$domains[$domain]>0];
    }
    $counts=['corroborated'=>0,'conflicted'=>0,'single_source'=>0];
    foreach($claims as $claim)$counts[(string)$claim['evidence_state']]=($counts[(string)$claim['evidence_state']]??0)+1;
    $project=null;if((int)($mission['project_id']??0)>0){$row=vp3_research_project_row_by_id_v2060($pdo,(int)$mission['project_id']);if($row)$project=vp3_research_project_public_v2060($pdo,$row,$uid);}
    $report=null;if((string)($mission['report_public_id']??'')!==''){$row=vp3_research_report_row_v2060($pdo,(string)$mission['report_public_id']);if($row)$report=vp3_research_report_public_v2060($pdo,$row,$uid);}
    return [
        'mission_id'=>(string)$mission['public_id'],'runtime_id'=>(string)$mission['runtime_public_id'],'question'=>(string)$mission['question'],'status'=>(string)$mission['status'],
        'conversation_id'=>(int)($mission['conversation_id']??0),'expires_at'=>(string)$mission['expires_at'],
        'budgets'=>['sources'=>(int)$mission['max_sources'],'pages'=>(int)$mission['max_pages'],'claims'=>(int)$mission['max_claims'],'duration_minutes'=>(int)$mission['duration_minutes']],
        'progress'=>['pages'=>count($pages),'claims'=>count($claims),'corroborated'=>$counts['corroborated'],'conflicted'=>$counts['conflicted'],'single_source'=>$counts['single_source'],'domains_checked'=>count(array_filter($domains,static fn(int $n): bool=>$n>0))],
        'source_plan'=>$sourcePlan?:array_map(static fn(string $d,int $n): array=>['domain'=>$d,'reason'=>'','pages'=>$n,'checked'=>$n>0],array_keys($domains),array_values($domains)),
        'pages'=>array_map(static fn(array $p): array=>['id'=>(string)$p['public_id'],'domain'=>(string)$p['domain'],'fingerprint'=>(string)$p['page_fingerprint'],'source_kind'=>(string)$p['source_kind'],'freshness_date'=>(string)($p['freshness_date']??''),'status'=>(string)$p['extraction_status'],'claims'=>(int)$p['claim_count'],'canonical_source_id'=>(string)$p['source_public_id'],'source_version_id'=>(string)$p['source_version_public_id']],$pages),
        'claims'=>array_map(fn(array $c): array=>vp3_browser_research_public_claim_v2230($pdo,$c),$claims),
        'gaps'=>array_values(vp3_browser_research_json_v2230($mission['gaps_json']??'')),
        'memo_preview'=>vp3_browser_research_memo_preview_v2230($pdo,$mission),'memo'=>(string)($mission['memo_text']??''),'project'=>$project,'report'=>$report,
        'handoffs'=>[
            'knowledge'=>'Review this Browser Research memo and prepare a Knowledge draft. Do not save anything until I approve it.',
            'crm'=>'Review these Browser Research findings and prepare proposed CRM enrichment changes. Do not modify CRM until I approve them.',
            'task'=>'Review the Browser Research gaps and conflicts and propose the highest-value follow-up task. Do not create it until I approve it.',
        ],
        'privacy'=>['raw_page_text_persisted'=>false,'raw_urls_persisted'=>false,'browser_history_persisted'=>false,'claims_and_bounded_evidence_persisted'=>true,'draft_research_only_until_explicit_save'=>true],
    ];
}

function vp3_browser_research_list_v2230(PDO $pdo,array $user,int $limit=20): array
{
    $limit=max(1,min(30,$limit));$stmt=$pdo->prepare("SELECT public_id FROM browser_research_missions_v2230 WHERE owner_user_id=? ORDER BY id DESC LIMIT ".$limit);
    $stmt->execute([(int)$user['id']]);$out=[];
    foreach($stmt->fetchAll(PDO::FETCH_COLUMN)?:[] as $id)try{$out[]=vp3_browser_research_public_v2230($pdo,$user,(string)$id);}catch(Throwable $e){}
    return $out;
}
