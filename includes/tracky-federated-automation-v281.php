<?php
declare(strict_types=1);

const VP3_TRACKY_FEDERATED_AUTOMATION_PROTOCOL_V281='physical_federated_automation.v1';

function tracky_v281_fa_text(mixed $v,int $n=240): string { return mb_strimwidth(trim((string)($v??'')),0,max(1,$n),''); }
function tracky_v281_fa_uuid(mixed $v,string $label): string {
    $v=strtolower(tracky_v281_fa_text($v,64));
    if(!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',$v))throw new RuntimeException('Tracky federated automation '.$label.' must be a UUID.');
    return $v;
}
function tracky_v281_fa_ensure_schema(?PDO $pdo=null): void {
    $pdo??=db(); if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS tracky_cloud_federated_automation (
      user_id INT UNSIGNED NOT NULL, reporting_site_id VARCHAR(100) NOT NULL, local_site_uuid CHAR(36) NOT NULL,
      generated_at_ms BIGINT UNSIGNED NOT NULL DEFAULT 0, semantic_hash CHAR(64) NOT NULL, snapshot_json LONGTEXT NOT NULL,
      received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY(user_id,reporting_site_id), INDEX idx_tracky_fa_user_updated(user_id,updated_at),
      CONSTRAINT fk_tracky_fa_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function tracky_v281_fa_definition(array $row,string $local): array {
    $origin=tracky_v281_fa_uuid($row['origin_site_id']??'','definition origin site');
    if($origin!==$local)throw new RuntimeException('Tracky federated automation mirror accepts only origin-local definitions.');
    $sites=[];foreach(array_slice(is_array($row['participating_site_ids']??null)?$row['participating_site_ids']:[],0,64) as $site)$sites[]=tracky_v281_fa_uuid($site,'participating site');
    $devices=[];foreach(array_slice(is_array($row['participating_device_ids']??null)?$row['participating_device_ids']:[],0,128) as $device){$v=tracky_v281_fa_text($device,100);if($v!=='')$devices[]=$v;}
    $state=strtolower(tracky_v281_fa_text($row['state']??'',30));if(!in_array($state,['draft','active','paused','retired'],true))throw new RuntimeException('Tracky federated automation definition state is invalid.');
    return [
      'automation_id'=>tracky_v281_fa_text($row['automation_id']??'',128),
      'revision'=>max(1,(int)($row['revision']??1)),
      'name'=>tracky_v281_fa_text($row['name']??'',160),
      'state'=>$state,'origin_site_id'=>$origin,
      'trigger_kind'=>tracky_v281_fa_text($row['trigger_kind']??'manual',40),
      'participating_site_ids'=>array_values(array_unique($sites)),
      'participating_device_ids'=>array_values(array_unique($devices)),
      'step_count'=>max(0,min(64,(int)($row['step_count']??0))),
      'default_deadline_ms'=>max(0,(int)($row['default_deadline_ms']??0)),
    ];
}
function tracky_v281_fa_run(array $row,string $local): array {
    $origin=tracky_v281_fa_uuid($row['origin_site_id']??'','run origin site');
    if($origin!==$local)throw new RuntimeException('Tracky federated automation mirror accepts only origin-local runs.');
    $state=strtolower(tracky_v281_fa_text($row['state']??'',30));
    if(!in_array($state,['planned','waiting','ready','running','blocked','recovering','completed','failed','cancelled','expired'],true))throw new RuntimeException('Tracky federated automation run state is invalid.');
    $stepStates=[];
    foreach(array_slice(is_array($row['step_states']??null)?$row['step_states']:[],0,64,true) as $step=>$stepState){
        $step=tracky_v281_fa_text($step,80);$stepState=strtolower(tracky_v281_fa_text($stepState,30));
        if($step!==''&&in_array($stepState,['pending','blocked','ready','running','completed','failed','cancelled','expired'],true))$stepStates[$step]=$stepState;
    }
    return [
      'run_id'=>tracky_v281_fa_text($row['run_id']??'',160),
      'automation_id'=>tracky_v281_fa_text($row['automation_id']??'',128),
      'automation_revision'=>max(1,(int)($row['automation_revision']??1)),
      'origin_site_id'=>$origin,'state'=>$state,'deadline_at_ms'=>max(0,(int)($row['deadline_at_ms']??0)),'step_states'=>$stepStates,
    ];
}

function tracky_v281_fa_trigger_receipt(array $row,string $local): array {
    $source=tracky_v281_fa_uuid($row['source_site_id']??$local,'trigger source site');
    if($source!==$local)throw new RuntimeException('Tracky physical trigger mirror accepts only origin-local trigger evidence.');
    $decision=strtolower(tracky_v281_fa_text($row['decision']??'',30));
    if(!in_array($decision,['accepted','rejected','duplicate','debounced'],true))throw new RuntimeException('Tracky physical trigger decision is invalid.');
    return [
      'receipt_id'=>tracky_v281_fa_text($row['receipt_id']??'',160),
      'automation_id'=>tracky_v281_fa_text($row['automation_id']??'',128),
      'automation_revision'=>max(1,(int)($row['automation_revision']??1)),
      'event_id'=>tracky_v281_fa_text($row['event_id']??'',160),
      'event_key'=>strtolower(tracky_v281_fa_text($row['event_key']??'',120)),
      'source_site_id'=>$source,'occurred_at_ms'=>max(0,(int)($row['occurred_at_ms']??0)),
      'confidence'=>max(0.0,min(1.0,(float)($row['confidence']??0))),
      'decision'=>$decision,'reason'=>tracky_v281_fa_text($row['reason']??'',120),
      'run_id'=>tracky_v281_fa_text($row['run_id']??'',160) ?: null,
      'created_at'=>tracky_v281_fa_text($row['created_at']??'',64),
    ];
}

function tracky_v281_fa_execution_receipt(array $row,string $local): array {
    $authority=tracky_v281_fa_uuid($row['authority_site_id']??$local,'execution authority site');
    $status=strtolower(tracky_v281_fa_text($row['status']??'',30));
    if(!in_array($status,['completed','failed'],true))throw new RuntimeException('Tracky federated execution receipt state is invalid.');
    return [
      'receipt_id'=>tracky_v281_fa_text($row['receipt_id']??'',160),
      'dispatch_id'=>tracky_v281_fa_text($row['dispatch_id']??'',160),
      'run_id'=>tracky_v281_fa_text($row['run_id']??'',160),
      'step_id'=>tracky_v281_fa_text($row['step_id']??'',80),
      'authority_site_id'=>$authority,'authority_epoch'=>max(1,(int)($row['authority_epoch']??1)),
      'status'=>$status,'completed_at_ms'=>max(0,(int)($row['completed_at_ms']??0)),
    ];
}
function tracky_v281_fa_normalize(array $input): array {
    if((string)($input['protocol']??'')!==VP3_TRACKY_FEDERATED_AUTOMATION_PROTOCOL_V281)throw new RuntimeException('Tracky federated automation protocol is unsupported.');
    if(isset($input['cloud_read_only'])&&!$input['cloud_read_only'])throw new RuntimeException('Cloud federated automation projection must remain read-only.');
    if(!empty($input['remote_action_execution'])||!empty($input['authority_mutation']))throw new RuntimeException('Cloud cannot execute federated automation actions or mutate authority.');
    $local=tracky_v281_fa_uuid($input['local_site_id']??'','local site id');
    $definitions=[];$seen=[];
    foreach(array_slice(is_array($input['definitions']??null)?$input['definitions']:[],0,250) as $row){
      if(!is_array($row))continue;$n=tracky_v281_fa_definition($row,$local);if($n['automation_id']===''||isset($seen[$n['automation_id']]))continue;$seen[$n['automation_id']]=true;$definitions[]=$n;
    }
    $runs=[];$seenRuns=[];
    foreach(array_slice(is_array($input['runs']??null)?$input['runs']:[],0,500) as $row){
      if(!is_array($row))continue;$n=tracky_v281_fa_run($row,$local);if($n['run_id']===''||isset($seenRuns[$n['run_id']]))continue;$seenRuns[$n['run_id']]=true;$runs[]=$n;
    }
    $triggerReceipts=[];$seenReceipts=[];
    foreach(array_slice(is_array($input['trigger_receipts']??null)?$input['trigger_receipts']:[],0,500) as $row){
      if(!is_array($row))continue;$n=tracky_v281_fa_trigger_receipt($row,$local);
      if($n['receipt_id']===''||isset($seenReceipts[$n['receipt_id']]))continue;
      $seenReceipts[$n['receipt_id']]=true;$triggerReceipts[]=$n;
    }
    $executionReceipts=[];$seenExecutionReceipts=[];
    foreach(array_slice(is_array($input['execution_receipts']??null)?$input['execution_receipts']:[],0,500) as $row){
      if(!is_array($row))continue;$n=tracky_v281_fa_execution_receipt($row,$local);
      if($n['receipt_id']===''||isset($seenExecutionReceipts[$n['receipt_id']]))continue;
      $seenExecutionReceipts[$n['receipt_id']]=true;$executionReceipts[]=$n;
    }
    return [
      'protocol'=>VP3_TRACKY_FEDERATED_AUTOMATION_PROTOCOL_V281,'version'=>'2.81','schema_version'=>1,
      'generated_at'=>max(0,(int)($input['generated_at']??round(microtime(true)*1000))),'local_site_id'=>$local,
      'definitions'=>$definitions,'runs'=>$runs,'trigger_receipts'=>$triggerReceipts,'execution_receipts'=>$executionReceipts,
      'counts'=>['definitions'=>count($definitions),'runs'=>count($runs),'active_runs'=>count(array_filter($runs,static fn($x)=>!in_array($x['state'],['completed','failed','cancelled','expired'],true))),'trigger_receipts'=>count($triggerReceipts),'execution_receipts'=>count($executionReceipts)],
      'cloud_read_only'=>true,'remote_action_execution'=>false,'authority_mutation'=>false,
      'safety'=>['execution_enabled'=>false,'cloud_execution_allowed'=>false,'agent_execution_allowed'=>false,'origin_homeserver_authoritative'=>true,'federation_v280_invariants_required'=>true],
    ];
}
function tracky_v281_fa_ingest(PDO $pdo,int $userId,string $reportingSiteId,array $input): array {
    tracky_v281_fa_ensure_schema($pdo);$snapshot=tracky_v281_fa_normalize($input);
    $json=tracky_cloud_v270_json($snapshot);$hash=hash('sha256',$json);
    $q=$pdo->prepare('SELECT generated_at_ms,semantic_hash FROM tracky_cloud_federated_automation WHERE user_id=? AND reporting_site_id=? LIMIT 1');
    $q->execute([$userId,$reportingSiteId]);$prior=$q->fetch();
    if($prior&&(int)$snapshot['generated_at']<(int)$prior['generated_at_ms'])return ['accepted'=>true,'changed'=>0,'stale'=>1,'idempotent'=>0];
    if($prior&&(int)$snapshot['generated_at']===(int)$prior['generated_at_ms']){
        if(hash_equals((string)$prior['semantic_hash'],$hash))return ['accepted'=>true,'changed'=>0,'stale'=>0,'idempotent'=>1];
        throw new RuntimeException('Tracky federated automation timestamp conflicts with the Cloud mirror.');
    }
    $s=$pdo->prepare("INSERT INTO tracky_cloud_federated_automation(user_id,reporting_site_id,local_site_uuid,generated_at_ms,semantic_hash,snapshot_json)
      VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE local_site_uuid=VALUES(local_site_uuid),generated_at_ms=VALUES(generated_at_ms),semantic_hash=VALUES(semantic_hash),snapshot_json=VALUES(snapshot_json),received_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP");
    $s->execute([$userId,$reportingSiteId,$snapshot['local_site_id'],$snapshot['generated_at'],$hash,$json]);
    return ['accepted'=>true,'changed'=>1,'stale'=>0,'idempotent'=>0];
}
function tracky_v281_fa_report(PDO $pdo,int $userId,string $originSiteId='',string $reportingSiteId=''): array {
    tracky_v281_fa_ensure_schema($pdo);$origin=trim($originSiteId)!==''?tracky_v281_fa_uuid($originSiteId,'origin site id'):'';
    $reporting=trim($reportingSiteId)!==''?tracky_cloud_v270_site_id($reportingSiteId):'';
    if($reporting!==''){$q=$pdo->prepare('SELECT reporting_site_id,snapshot_json,updated_at FROM tracky_cloud_federated_automation WHERE user_id=? AND reporting_site_id=? ORDER BY updated_at DESC');$q->execute([$userId,$reporting]);}
    elseif($origin!==''){$q=$pdo->prepare('SELECT reporting_site_id,snapshot_json,updated_at FROM tracky_cloud_federated_automation WHERE user_id=? AND local_site_uuid=? ORDER BY updated_at DESC');$q->execute([$userId,$origin]);}
    else{$q=$pdo->prepare('SELECT reporting_site_id,snapshot_json,updated_at FROM tracky_cloud_federated_automation WHERE user_id=? ORDER BY updated_at DESC');$q->execute([$userId]);}
    $snapshots=[];foreach($q->fetchAll()?:[] as $r){$x=json_decode((string)$r['snapshot_json'],true);if(is_array($x))$snapshots[]=['reporting_site_id'=>(string)$r['reporting_site_id'],'updated_at'=>(string)$r['updated_at'],'automation'=>$x];}
    return ['available'=>!empty($snapshots),'protocol'=>VP3_TRACKY_FEDERATED_AUTOMATION_PROTOCOL_V281,'version'=>'2.81','origin_site_id'=>$origin,'reporting_site_id'=>$reporting,'snapshots'=>$snapshots,'preferred_automation'=>$snapshots[0]['automation']??null,'cloud_role'=>'read_only_mirror','cloud_execution_allowed'=>false,'cloud_definition_authoring'=>false,'authority_mutation'=>false];
}
function tracky_v281_fa_public_capability(): array {
    return ['version'=>'2.81','protocol'=>VP3_TRACKY_FEDERATED_AUTOMATION_PROTOCOL_V281,'section'=>3,'schema_version'=>56,'cloud_role'=>'read_only_mirror','cloud_definition_authoring'=>false,'cloud_execution_allowed'=>false,'agent_execution_allowed'=>false,'origin_homeserver_authoritative'=>true,'durable_action_ledger'=>true,'physical_world_trigger_runtime'=>true,'trigger_receipts_immutable'=>true,'trigger_execution_enabled'=>false,'distributed_action_execution'=>true,'execution_receipts_immutable'=>true,'execution_enabled'=>false];
}
