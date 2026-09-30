<?php
declare(strict_types=1);

require_once __DIR__.'/system-apps-v150.php';

const VP3_SYSTEM_APPS_V160='system-app-agent-actions-v160-20260930';
const VP3_SYSTEM_APPS_AGENT_ACTION_TTL_SECONDS=600;

function vp3_system_apps_agent_actions_schema_ready_v160(?PDO $pdo=null): bool
{
    $pdo??=db();
    return $pdo&&table_exists('vp3_system_app_agent_actions');
}

function vp3_system_apps_agent_actions_ensure_schema_v160(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_system_app_agent_actions (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      public_id VARCHAR(64) NOT NULL,
      user_id INT UNSIGNED NOT NULL,
      conversation_id BIGINT UNSIGNED NULL,
      app_id INT UNSIGNED NULL,
      site_id BIGINT UNSIGNED NULL,
      action_type VARCHAR(64) NOT NULL,
      payload_json LONGTEXT NULL,
      preview_json LONGTEXT NULL,
      confirmation_token_hash CHAR(64) NOT NULL,
      idempotency_key VARCHAR(190) NOT NULL,
      status VARCHAR(24) NOT NULL DEFAULT 'prepared',
      execution_token VARCHAR(64) NOT NULL DEFAULT '',
      execution_expires_at DATETIME NULL,
      expires_at DATETIME NOT NULL,
      confirmed_at DATETIME NULL,
      completed_at DATETIME NULL,
      result_json LONGTEXT NULL,
      error_message VARCHAR(500) NOT NULL DEFAULT '',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_vp3_system_app_agent_public (public_id),
      UNIQUE KEY uq_vp3_system_app_agent_idem (user_id,idempotency_key),
      INDEX idx_vp3_system_app_agent_pending (user_id,status,expires_at),
      CONSTRAINT fk_vp3_system_app_agent_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_vp3_system_app_agent_app FOREIGN KEY (app_id) REFERENCES vp3_system_app_catalog(id) ON DELETE SET NULL,
      CONSTRAINT fk_vp3_system_app_agent_site FOREIGN KEY (site_id) REFERENCES cloud_hosting_sites(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function vp3_system_apps_agent_code_v160(): string
{
    $alphabet='ABCDEFGHJKLMNPQRSTUVWXYZ23456789';$value='';
    for($i=0;$i<8;$i++)$value.=$alphabet[random_int(0,strlen($alphabet)-1)];
    return $value;
}

function vp3_system_apps_agent_catalog_row_v160(int $userId,string $appKey,?PDO $pdo=null): array
{
    $pdo??=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $stmt=$pdo->prepare("SELECT c.*,o.status ownership_status
      FROM vp3_system_app_catalog c
      LEFT JOIN vp3_system_app_ownership o ON o.app_id=c.id AND o.user_id=?
      WHERE c.app_key=? AND c.is_active=1 LIMIT 1");
    $stmt->execute([$userId,strtolower(trim($appKey))]);$row=$stmt->fetch();
    if(!$row)throw new RuntimeException('System App was not found.');
    return $row;
}

function vp3_system_apps_agent_find_app_v160(string $query,array $user,?PDO $pdo=null): ?array
{
    $snapshot=vp3_system_apps_agent_snapshot_v150($user,$pdo);
    $q=mb_strtolower($query);$best=null;$bestScore=0;
    foreach((array)$snapshot['apps'] as $app){
        $key=mb_strtolower((string)$app['app_key']);$name=mb_strtolower((string)$app['name']);
        $short=preg_replace('/^vp3\s+/','',$name)??$name;$score=0;
        if($key!==''&&str_contains($q,$key))$score=100;
        elseif($name!==''&&str_contains($q,$name))$score=90;
        elseif($short!==''&&mb_strlen($short)>=4&&preg_match('/\b'.preg_quote($short,'/').'\b/u',$q))$score=70;
        if($score>$bestScore){$best=$app;$bestScore=$score;}
    }
    return $bestScore>0?$best:null;
}

function vp3_system_apps_agent_find_site_v160(string $query,int $userId,?PDO $pdo=null): ?array
{
    $pdo??=db();if(!$pdo)return null;
    $q=mb_strtolower($query);$best=null;$score=0;
    foreach(vp3_cloud_hosting_sites_v100($userId,$pdo) as $site){
        $candidates=[
          mb_strtolower(trim((string)($site['canonical_hostname']??''))),
          mb_strtolower(trim((string)($site['requested_hostname']??''))),
          mb_strtolower(trim((string)($site['display_name']??''))),
          mb_strtolower(trim((string)($site['site_key']??''))),
        ];
        foreach($candidates as $i=>$candidate){
            if($candidate!==''&&str_contains($q,$candidate)){
                $s=80-$i;if($s>$score){$best=$site;$score=$s;}
            }
        }
    }
    return $best;
}

function vp3_system_apps_agent_prepare_v160(
    array $user,string $actionType,string $appKey,array $payload,array $preview,
    int $conversationId=0,?int $siteId=null,?PDO $pdo=null
): array {
    $pdo??=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    vp3_system_apps_agent_actions_ensure_schema_v160($pdo);
    $uid=(int)($user['id']??0);if($uid<1)throw new RuntimeException('A signed-in user is required.');
    $allowed=['ownership.acquire','install','update.verify','release.rollback','hosting.bind','hosting.unbind','reconcile'];
    if(!in_array($actionType,$allowed,true))throw new RuntimeException('Unsupported System App Agent action.');
    $app=null;$appId=null;
    if($actionType!=='reconcile'){
        $app=vp3_system_apps_agent_catalog_row_v160($uid,$appKey,$pdo);
        $appId=(int)$app['id'];
        $owned=(string)($app['ownership_status']??'')==='active';
        if($actionType!=='ownership.acquire'&&!$owned)throw new RuntimeException('This System App must be owned before that action can be prepared.');
        if($actionType==='ownership.acquire'&&$owned)throw new RuntimeException('This System App is already owned.');
        if($actionType==='ownership.acquire'&&!vp3_system_apps_eligible_v100($app,$user)){
            throw new RuntimeException('This System App is not available for this account.');
        }
    }

    $code=vp3_system_apps_agent_code_v160();
    $publicId='appact_'.bin2hex(random_bytes(12));
    $idem='agent-system-app-'.hash('sha256',$uid.'|'.$publicId.'|'.$actionType);
    $stmt=$pdo->prepare("INSERT INTO vp3_system_app_agent_actions
      (public_id,user_id,conversation_id,app_id,site_id,action_type,payload_json,preview_json,confirmation_token_hash,idempotency_key,status,expires_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,'prepared',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 MINUTE))");
    $stmt->execute([
      $publicId,$uid,$conversationId>0?$conversationId:null,$appId,$siteId,
      $actionType,json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
      json_encode($preview,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
      hash('sha256',$code),$idem
    ]);
    return [
      'contract'=>'vp3.system-app-agent-action.v1','action_id'=>$publicId,'action_type'=>$actionType,
      'app_key'=>$app?(string)$app['app_key']:'','preview'=>$preview,'requires_confirmation'=>true,
      'confirmation_code'=>$code,'expires_in_seconds'=>VP3_SYSTEM_APPS_AGENT_ACTION_TTL_SECONDS,
    ];
}

function vp3_system_apps_agent_execute_v160(
    array $row,array $user,?callable $remote=null,?PDO $pdo=null
): array {
    $pdo??=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $uid=(int)($user['id']??0);if($uid<1||(int)$row['user_id']!==$uid)throw new RuntimeException('System App action does not belong to this account.');
    $type=(string)$row['action_type'];$payload=json_decode((string)($row['payload_json']??''),true);if(!is_array($payload))$payload=[];
    if($type==='reconcile'){
        return ['reconciliation'=>vp3_system_apps_reconcile_all_v130($uid,$remote,$pdo)];
    }
    $appId=(int)($row['app_id']??0);
    $stmt=$pdo->prepare('SELECT app_key FROM vp3_system_app_catalog WHERE id=? LIMIT 1');$stmt->execute([$appId]);
    $appKey=(string)$stmt->fetchColumn();if($appKey==='')throw new RuntimeException('System App no longer exists.');

    if($type==='ownership.acquire'){
        return ['ownership'=>vp3_system_apps_acquire_v100($uid,$appKey,'agent_chat',(string)$row['public_id'],$pdo)];
    }
    if($type==='install'){
        return ['installation'=>vp3_system_apps_install_v110($uid,$appKey,$remote,$pdo)];
    }
    if($type==='update.verify'){
        if(function_exists('vp3_system_apps_release_update_v200')){
            return ['release'=>vp3_system_apps_release_update_v200($uid,$appKey,$remote,$pdo)];
        }
        return ['installation'=>vp3_system_apps_install_v110($uid,$appKey,$remote,$pdo)];
    }
    if($type==='release.rollback'){
        return ['release'=>vp3_system_apps_release_rollback_v200($uid,$appKey,'agent_confirmed',$remote,$pdo)];
    }
    if($type==='hosting.bind'){
        $siteId=(int)($row['site_id']??$payload['site_id']??0);
        if($siteId<1)throw new RuntimeException('Hosting site is required.');
        try{
            return ['hosting'=>vp3_system_apps_hosting_bind_v120($uid,$appKey,$siteId,$remote,$pdo)];
        }catch(Throwable $e){
            $app=vp3_system_apps_owned_row_v110($uid,$appKey,$pdo);
            $projection=vp3_system_apps_hosting_projection_v120($uid,(int)$app['id'],$pdo);
            if(!empty($projection['bound'])&&(int)($projection['site_id']??0)===$siteId){
                return ['hosting'=>['hosting'=>$projection,'reconcile_pending'=>true,'warning'=>mb_substr($e->getMessage(),0,300)]];
            }
            throw $e;
        }
    }
    if($type==='hosting.unbind'){
        try{
            return ['hosting'=>vp3_system_apps_hosting_unbind_v120($uid,$appKey,$remote,$pdo)];
        }catch(Throwable $e){
            $app=vp3_system_apps_owned_row_v110($uid,$appKey,$pdo);
            $projection=vp3_system_apps_hosting_projection_v120($uid,(int)$app['id'],$pdo);
            if(empty($projection['bound'])){
                return ['hosting'=>['hosting'=>$projection,'reconcile_pending'=>true,'warning'=>mb_substr($e->getMessage(),0,300)]];
            }
            throw $e;
        }
    }
    throw new RuntimeException('Unsupported System App Agent action.');
}

function vp3_system_apps_agent_safe_result_v160(array $result,array $user,?PDO $pdo=null): array
{
    $snapshot=vp3_system_apps_agent_snapshot_v150($user,$pdo);
    return [
      'ok'=>true,
      'system_apps'=>$snapshot,
      'reconcile_pending'=>str_contains(json_encode($result,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?:'','reconcile_pending'),
    ];
}

function vp3_system_apps_agent_confirm_v160(
    array $user,string $code,int $conversationId=0,?callable $remote=null,?PDO $pdo=null
): array {
    $pdo??=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    vp3_system_apps_agent_actions_ensure_schema_v160($pdo);
    $uid=(int)($user['id']??0);if($uid<1)throw new RuntimeException('A signed-in user is required.');
    $code=strtoupper(trim($code));if(!preg_match('/^[A-Z2-9]{8}$/',$code))throw new RuntimeException('Enter the 8-character System App confirmation code.');
    $hash=hash('sha256',$code);$lease=bin2hex(random_bytes(16));

    $pdo->beginTransaction();
    try{
        $sql="SELECT * FROM vp3_system_app_agent_actions
          WHERE user_id=? AND status IN ('prepared','executing','completed') AND confirmation_token_hash=?";
        $params=[$uid,$hash];if($conversationId>0){$sql.=' AND conversation_id=?';$params[]=$conversationId;}
        $sql.=' ORDER BY id DESC LIMIT 1 FOR UPDATE';
        $stmt=$pdo->prepare($sql);$stmt->execute($params);$row=$stmt->fetch();
        if(!is_array($row))throw new RuntimeException('System App confirmation code is invalid.');
        $confirmedAppKey='';
        if((int)($row['app_id']??0)>0){
            $appStmt=$pdo->prepare('SELECT app_key FROM vp3_system_app_catalog WHERE id=? LIMIT 1');
            $appStmt->execute([(int)$row['app_id']]);$confirmedAppKey=(string)$appStmt->fetchColumn();
        }
        $confirmedPreview=json_decode((string)($row['preview_json']??''),true);if(!is_array($confirmedPreview))$confirmedPreview=[];
        if((string)$row['status']==='completed'){
            $result=json_decode((string)($row['result_json']??''),true);if(!is_array($result))$result=[];
            $pdo->commit();
            return ['action_id'=>(string)$row['public_id'],'action_type'=>(string)$row['action_type'],'app_key'=>$confirmedAppKey,'preview'=>$confirmedPreview,'completed'=>true,'idempotent_replay'=>true,'result'=>$result];
        }
        $expires=strtotime((string)$row['expires_at'].' UTC');
        if($expires!==false&&$expires<=time())throw new RuntimeException('System App confirmation code expired. Prepare the action again.');
        if((string)$row['status']==='executing'){
            $until=!empty($row['execution_expires_at'])?strtotime((string)$row['execution_expires_at'].' UTC'):false;
            if($until!==false&&$until>time())throw new RuntimeException('This System App action is already in progress.');
        }
        $stmt=$pdo->prepare("UPDATE vp3_system_app_agent_actions
          SET status='executing',execution_token=?,execution_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE),
              confirmed_at=COALESCE(confirmed_at,UTC_TIMESTAMP()),error_message=''
          WHERE id=?");
        $stmt->execute([$lease,(int)$row['id']]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}

    try{
        $result=vp3_system_apps_agent_execute_v160($row,$user,$remote,$pdo);
        $safe=vp3_system_apps_agent_safe_result_v160($result,$user,$pdo);
        $stmt=$pdo->prepare("UPDATE vp3_system_app_agent_actions
          SET status='completed',result_json=?,error_message='',execution_token='',execution_expires_at=NULL,completed_at=UTC_TIMESTAMP()
          WHERE id=? AND execution_token=?");
        $stmt->execute([json_encode($safe,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),(int)$row['id'],$lease]);
        if($stmt->rowCount()!==1)throw new RuntimeException('System App action execution lease was lost before completion.');
        return ['action_id'=>(string)$row['public_id'],'action_type'=>(string)$row['action_type'],'app_key'=>$confirmedAppKey,'preview'=>$confirmedPreview,'completed'=>true,'idempotent_replay'=>false,'result'=>$safe];
    }catch(Throwable $e){
        $pdo->prepare("UPDATE vp3_system_app_agent_actions
          SET status='prepared',error_message=?,execution_token='',execution_expires_at=NULL
          WHERE id=? AND execution_token=?")->execute([mb_substr($e->getMessage(),0,500),(int)$row['id'],$lease]);
        throw $e;
    }
}

function vp3_system_apps_agent_action_intent_v160(string $query): bool
{
    if(preg_match('/\bconfirm\s+app\s+[A-Z2-9]{8}\b/i',$query))return true;
    if(!preg_match('/\b(?:app|apps|vp3 notes|vp3 inventory|vp3 checklists|notes|inventory|checklists)\b/i',$query))return false;
    if(preg_match('/^\s*(?:what|which|show|list|tell\s+me|do\s+i|are\s+there|is\s+there|status|how\s+many)\b/i',$query)
        && !preg_match('/\b(?:please|go\s+ahead|can\s+you|could\s+you|would\s+you)\b/i',$query))return false;
    return (bool)preg_match('/\b(?:install|update|upgrade|verify|rollback|roll back|revert|add|acquire|host|hosting|unhost|remove hosting|detach hosting|refresh|reconcile|resync|sync|open|launch)\b/i',$query);
}

function vp3_system_apps_agent_prepare_result_v160(array $plan,string $intro): array
{
    return [
      'handled'=>true,
      'answer'=>$intro."\n\nConfirmation code: **".(string)$plan['confirmation_code']."**. Reply with “confirm app ".(string)$plan['confirmation_code']."” to execute.",
      'stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[['source'=>'system-apps:canonical','title'=>'VP3 System Apps']],
      'system_app_plan'=>$plan,
    ];
}

function vp3_system_apps_agent_action_query_v160(
    string $query,array $user,int $conversationId=0,?callable $remote=null,?PDO $pdo=null
): array {
    $empty=['handled'=>false,'answer'=>'','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[]];
    if(!vp3_system_apps_agent_action_intent_v160($query))return $empty;
    $pdo??=db();if(!$pdo)return $empty;
    $uid=(int)($user['id']??0);if($uid<1)return $empty;
    vp3_system_apps_agent_actions_ensure_schema_v160($pdo);

    if(preg_match('/\bconfirm\s+app\s+([A-Z2-9]{8})\b/i',$query,$m)){
        try{
            $done=vp3_system_apps_agent_confirm_v160($user,(string)$m[1],$conversationId,$remote,$pdo);
            if(function_exists('agent_tool_log'))agent_tool_log($user,'system_apps.confirm',$query,'success',['action_type'=>$done['action_type']??'','idempotent_replay'=>!empty($done['idempotent_replay'])],$conversationId);
            return ['handled'=>true,'answer'=>'The System App action completed successfully.','stem_media'=>[],'media'=>[],
              'actions'=>[['type'=>'open_url','label'=>'Open Apps','url'=>url('/apps.php')]],
              'sources'=>[['source'=>'system-apps:canonical','title'=>'VP3 System Apps']],'system_app_plan'=>$done];
        }catch(Throwable $e){
            return ['handled'=>true,'answer'=>'I could not complete that System App action: '.$e->getMessage(),'stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[],
              'system_app_plan'=>['status'=>'failed','error'=>mb_substr($e->getMessage(),0,500),'confirmation_code'=>(string)$m[1]]];
        }
    }

    if(preg_match('/\b(?:refresh|reconcile|resync|sync)\b/i',$query)&&preg_match('/\b(?:apps|applications)\b/i',$query)){
        try{
            $plan=vp3_system_apps_agent_prepare_v160($user,'reconcile','',[],['scope'=>'all_owned_apps','operation'=>'cloud_homeserver_reconcile'],$conversationId,null,$pdo);
            if(function_exists('agent_tool_log'))agent_tool_log($user,'system_apps.prepare',$query,'success',['action_type'=>'reconcile','scope'=>'all_owned_apps'],$conversationId);
            return vp3_system_apps_agent_prepare_result_v160($plan,'I prepared Cloud ↔ HomeServer reconciliation for your System Apps.');
        }catch(Throwable $e){
            return ['handled'=>true,'answer'=>'I could not prepare System Apps reconciliation: '.$e->getMessage(),'stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[],'system_app_plan'=>null];
        }
    }

    $app=vp3_system_apps_agent_find_app_v160($query,$user,$pdo);
    if(!$app){
        return ['handled'=>true,'answer'=>'I need the System App name for that action.','stem_media'=>[],'media'=>[],'actions'=>[['type'=>'open_url','label'=>'Open Apps','url'=>url('/apps.php')]],'sources'=>[]];
    }
    $appKey=(string)$app['app_key'];$name=(string)$app['name'];$q=mb_strtolower($query);

    if(preg_match('/\b(?:open|launch)\b/i',$query)){
        $target=!empty($app['public_url'])?(string)$app['public_url']:url('/apps.php');
        return ['handled'=>true,'answer'=>!empty($app['public_url'])?'Opening the hosted '.$name.'.':'Opening Apps so you can manage '.$name.'.',
          'stem_media'=>[],'media'=>[],'actions'=>[['type'=>'open_url','label'=>'Open '.$name,'url'=>$target,'system_app_key'=>$appKey]],'sources'=>[['source'=>'system-apps:canonical','title'=>'VP3 System Apps']]];
    }

    $actionType='';$payload=[];$preview=[];$siteId=null;$intro='';
    if(preg_match('/\b(?:add|acquire)\b/i',$query)){
        $actionType='ownership.acquire';$preview=['app'=>$name,'ownership'=>'active'];$intro='I prepared adding '.$name.' to your account.';
    }elseif(preg_match('/\b(?:install)\b/i',$query)){
        $actionType='install';$preview=['app'=>$name,'target'=>'HomeServer'];$intro='I prepared installation of '.$name.' on HomeServer.';
    }elseif(preg_match('/\b(?:rollback|roll back|revert)\b/i',$query)){
        $actionType='release.rollback';
        if(!function_exists('vp3_system_apps_release_metadata_v200'))throw new RuntimeException('System App release lifecycle is unavailable.');
        $meta=vp3_system_apps_release_metadata_v200(vp3_system_apps_agent_catalog_row_v160($uid,$appKey,$pdo));
        $preview=['app'=>$name,'target'=>'HomeServer','operation'=>'rollback_previous_release','release_channel'=>$meta['release_channel']];
        $intro='I prepared a rollback of '.$name.' to its previous verified HomeServer release. Any bound Hosting/subdomain route will be reconciled afterward.';
    }elseif(preg_match('/\b(?:update|upgrade|verify)\b/i',$query)){
        $appRow=vp3_system_apps_agent_catalog_row_v160($uid,$appKey,$pdo);
        $meta=function_exists('vp3_system_apps_release_metadata_v200')?vp3_system_apps_release_metadata_v200($appRow):['release_channel'=>'stable','release_notes'=>[]];
        $actionType='update.verify';
        $preview=['app'=>$name,'target'=>'HomeServer','operation'=>'verified_update','to_version'=>(string)$appRow['current_version'],'release_channel'=>$meta['release_channel'],'release_notes'=>$meta['release_notes']];
        $intro='I prepared a verified update of '.$name.' on HomeServer. Its Hosting/subdomain binding will be reconciled after activation.';
    }elseif(preg_match('/\b(?:unhost|remove\s+hosting|detach\s+hosting)\b/i',$query)){
        $actionType='hosting.unbind';$preview=['app'=>$name,'hosting'=>'remove'];$intro='I prepared removal of Hosting from '.$name.'.';
    }elseif(preg_match('/\b(?:host|hosting)\b/i',$query)){
        $site=vp3_system_apps_agent_find_site_v160($query,$uid,$pdo);
        if(!$site)return ['handled'=>true,'answer'=>'I need the existing Hosting site name or hostname to assign to '.$name.'.','stem_media'=>[],'media'=>[],'actions'=>[['type'=>'open_url','label'=>'Manage Hosting','url'=>url('/hosting.php')]],'sources'=>[]];
        $siteId=(int)$site['id'];$payload=['site_id'=>$siteId];$actionType='hosting.bind';
        $preview=['app'=>$name,'hosting_site'=>(string)$site['display_name'],'hostname'=>$site['canonical_hostname']??$site['requested_hostname']??null];
        $intro='I prepared Hosting assignment for '.$name.' to “'.(string)$site['display_name'].'”.';
    }elseif(preg_match('/\b(?:refresh|reconcile|resync|sync)\b/i',$query)){
        $actionType='reconcile';$preview=['app'=>$name,'operation'=>'cloud_homeserver_reconcile'];$intro='I prepared Cloud ↔ HomeServer reconciliation for '.$name.'.';
    }
    if($actionType==='')return $empty;

    try{
        $plan=vp3_system_apps_agent_prepare_v160($user,$actionType,$appKey,$payload,$preview,$conversationId,$siteId,$pdo);
        if(function_exists('agent_tool_log'))agent_tool_log($user,'system_apps.prepare',$query,'success',['action_type'=>$actionType,'app_key'=>$appKey,'site_id'=>$siteId],$conversationId);
        return vp3_system_apps_agent_prepare_result_v160($plan,$intro);
    }catch(Throwable $e){
        return ['handled'=>true,'answer'=>'I could not prepare that System App action: '.$e->getMessage(),'stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[],'system_app_plan'=>null];
    }
}

function vp3_system_apps_capability_v160(): array
{
    return array_replace(vp3_system_apps_capability_v150(),[
      'agent_action_contract'=>'vp3.system-app-agent-action.v1',
      'consequential_agent_actions'=>true,
      'explicit_confirmation'=>true,
      'action_execution_lease'=>true,
      'idempotent_confirmation_replay'=>true,
      'agent_install_update'=>true,
      'agent_release_rollback'=>true,
      'agent_hosting_bind_unbind'=>true,
      'agent_reconcile'=>true,
      'agent_open_navigation'=>true,
      'ownership_revoke_via_agent'=>false,
    ]);
}
