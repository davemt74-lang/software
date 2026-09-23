<?php
declare(strict_types=1);

const VP3_CONNECTED_SITES_V100='connected-sites-v100-20260923';

function vp3_connected_sites_ensure_schema_v100(?PDO $pdo=null): void
{
    $pdo??=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_connected_sites (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      user_id INT UNSIGNED NOT NULL,
      app_key VARCHAR(80) NOT NULL,
      app_label VARCHAR(120) NOT NULL,
      client_id VARCHAR(120) NOT NULL,
      scopes_json JSON NOT NULL,
      status ENUM('active','revoked') NOT NULL DEFAULT 'active',
      connected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      last_used_at DATETIME NULL,
      revoked_at DATETIME NULL,
      metadata_json JSON NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_connected_site_user_app(user_id,app_key,client_id),
      INDEX idx_connected_site_user_status(user_id,status,updated_at),
      CONSTRAINT fk_connected_site_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_connected_site_codes (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      connection_id BIGINT UNSIGNED NOT NULL,
      code_hash CHAR(64) NOT NULL UNIQUE,
      redirect_uri VARCHAR(500) NOT NULL,
      expires_at DATETIME NOT NULL,
      used_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_connected_site_code_expiry(expires_at,used_at),
      CONSTRAINT fk_connected_site_code_connection FOREIGN KEY(connection_id) REFERENCES user_connected_sites(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_connected_site_tokens (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      connection_id BIGINT UNSIGNED NOT NULL,
      access_token_hash CHAR(64) NOT NULL UNIQUE,
      refresh_token_hash CHAR(64) NOT NULL UNIQUE,
      access_expires_at DATETIME NOT NULL,
      refresh_expires_at DATETIME NOT NULL,
      last_used_at DATETIME NULL,
      revoked_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX idx_connected_site_token_connection(connection_id,revoked_at,access_expires_at),
      CONSTRAINT fk_connected_site_token_connection FOREIGN KEY(connection_id) REFERENCES user_connected_sites(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function vp3_connected_sites_schema_ready_v100(?PDO $pdo=null): bool
{
    $pdo??=db();if(!$pdo)return false;
    return table_exists('user_connected_sites')&&table_exists('user_connected_site_codes')&&table_exists('user_connected_site_tokens');
}
function vp3_connected_site_registered_apps_v100(): array
{
    $base=rtrim(trim((string)(getenv('VP3_ANNOTATED_BASE_URL')?:site_config('annotated_connector_base_url',''))),'/');
    $redirect=trim((string)(getenv('VP3_ANNOTATED_REDIRECT_URI')?:site_config('annotated_connector_redirect_uri',$base!==''?$base.'/vp3/callback.php':'')));
    $secret=trim((string)(getenv('VP3_ANNOTATED_CLIENT_SECRET')?:site_config('annotated_connector_client_secret','')));
    return ['annotated'=>[
        'app_key'=>'annotated','label'=>'Annotated','client_id'=>'annotated','client_secret'=>$secret,'base_url'=>$base,'redirect_uri'=>$redirect,
        'scopes'=>[
            'account.identity.read'=>'Read your VP3 account identity',
            'transcriptions.read'=>'Read VP3 transcriptions and meeting transcripts you can access',
            'transcriptions.intelligence.read'=>'Read VP3 Transcription / Meeting Intelligence and AI summaries you can access',
        ],
    ]];
}
function vp3_connected_site_app_v100(string $clientId): ?array
{
    $apps=vp3_connected_site_registered_apps_v100();$clientId=strtolower(trim($clientId));return $apps[$clientId]??null;
}
function vp3_connected_site_scopes_v100(array $app,string|array $requested): array
{
    $parts=is_array($requested)?array_values(array_filter(array_map('trim',$requested))):(trim($requested)===''?[]:(preg_split('/[\\s,]+/',trim($requested))?:[]));
    $allowed=array_keys((array)$app['scopes']);if(!$parts)return $allowed;$out=[];foreach($parts as $scope){$scope=trim((string)$scope);if($scope!==''&&in_array($scope,$allowed,true))$out[$scope]=true;}return array_keys($out);
}
function vp3_connected_site_validate_redirect_v100(array $app,string $redirect): string
{
    $redirect=trim($redirect);if($redirect===''||!hash_equals((string)$app['redirect_uri'],$redirect))throw new RuntimeException('Connected-site callback is not registered.');
    return $redirect;
}
function vp3_connected_site_connection_v100(PDO $pdo,int $userId,string $appKey,string $clientId): ?array
{
    $q=$pdo->prepare('SELECT * FROM user_connected_sites WHERE user_id=? AND app_key=? AND client_id=? LIMIT 1');$q->execute([$userId,$appKey,$clientId]);return $q->fetch()?:null;
}
function vp3_connected_site_authorize_v100(PDO $pdo,array $user,array $app,array $scopes,string $redirect): array
{
    vp3_connected_sites_ensure_schema_v100($pdo);$uid=(int)$user['id'];if($uid<1)throw new RuntimeException('Sign in to connect this site.');
    $json=json_encode(array_values($scopes),JSON_UNESCAPED_SLASHES);$existing=vp3_connected_site_connection_v100($pdo,$uid,(string)$app['app_key'],(string)$app['client_id']);
    if($existing){$pdo->prepare("UPDATE user_connected_sites SET app_label=?,scopes_json=?,status='active',revoked_at=NULL,updated_at=NOW() WHERE id=?")->execute([(string)$app['label'],$json,(int)$existing['id']]);$connectionId=(int)$existing['id'];}
    else{$pdo->prepare("INSERT INTO user_connected_sites(user_id,app_key,app_label,client_id,scopes_json,status) VALUES(?,?,?,?,?,'active')")->execute([$uid,(string)$app['app_key'],(string)$app['label'],(string)$app['client_id'],$json]);$connectionId=(int)$pdo->lastInsertId();}
    $code=bin2hex(random_bytes(32));$pdo->prepare('INSERT INTO user_connected_site_codes(connection_id,code_hash,redirect_uri,expires_at) VALUES(?,?,?,DATE_ADD(NOW(),INTERVAL 5 MINUTE))')->execute([$connectionId,hash('sha256',$code),$redirect]);
    return ['code'=>$code,'connection_id'=>$connectionId];
}
function vp3_connected_site_token_pair_v100(PDO $pdo,int $connectionId): array
{
    $access=bin2hex(random_bytes(32));$refresh=bin2hex(random_bytes(40));$pdo->prepare('UPDATE user_connected_site_tokens SET revoked_at=COALESCE(revoked_at,NOW()),updated_at=NOW() WHERE connection_id=? AND revoked_at IS NULL')->execute([$connectionId]);
    $pdo->prepare('INSERT INTO user_connected_site_tokens(connection_id,access_token_hash,refresh_token_hash,access_expires_at,refresh_expires_at) VALUES(?,?,?,DATE_ADD(NOW(),INTERVAL 1 HOUR),DATE_ADD(NOW(),INTERVAL 90 DAY))')->execute([$connectionId,hash('sha256',$access),hash('sha256',$refresh)]);
    return ['access_token'=>$access,'refresh_token'=>$refresh,'token_type'=>'Bearer','expires_in'=>3600,'refresh_expires_in'=>7776000];
}
function vp3_connected_site_exchange_code_v100(PDO $pdo,array $app,string $code,string $redirect): array
{
    vp3_connected_sites_ensure_schema_v100($pdo);$redirect=vp3_connected_site_validate_redirect_v100($app,$redirect);$hash=hash('sha256',trim($code));
    $pdo->beginTransaction();try{$q=$pdo->prepare("SELECT c.*,s.user_id,s.app_key,s.client_id,s.scopes_json,s.status FROM user_connected_site_codes c JOIN user_connected_sites s ON s.id=c.connection_id WHERE c.code_hash=? AND c.redirect_uri=? AND c.used_at IS NULL AND c.expires_at>NOW() LIMIT 1 FOR UPDATE");$q->execute([$hash,$redirect]);$row=$q->fetch();if(!$row||$row['status']!=='active'||$row['client_id']!==$app['client_id'])throw new RuntimeException('Authorization code is invalid or expired.');$pdo->prepare('UPDATE user_connected_site_codes SET used_at=NOW() WHERE id=?')->execute([(int)$row['id']]);$pair=vp3_connected_site_token_pair_v100($pdo,(int)$row['connection_id']);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return $pair+['scope'=>implode(' ',json_decode((string)$row['scopes_json'],true)?:[])];
}
function vp3_connected_site_refresh_v100(PDO $pdo,array $app,string $refresh): array
{
    $hash=hash('sha256',trim($refresh));$q=$pdo->prepare("SELECT t.*,s.client_id,s.scopes_json,s.status FROM user_connected_site_tokens t JOIN user_connected_sites s ON s.id=t.connection_id WHERE t.refresh_token_hash=? AND t.revoked_at IS NULL AND t.refresh_expires_at>NOW() LIMIT 1");$q->execute([$hash]);$row=$q->fetch();if(!$row||$row['status']!=='active'||$row['client_id']!==$app['client_id'])throw new RuntimeException('Refresh token is invalid or expired.');$pair=vp3_connected_site_token_pair_v100($pdo,(int)$row['connection_id']);return $pair+['scope'=>implode(' ',json_decode((string)$row['scopes_json'],true)?:[])];
}
function vp3_connected_site_auth_v100(PDO $pdo,string $requiredScope=''): array
{
    vp3_connected_sites_ensure_schema_v100($pdo);$header=(string)($_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'');if(!preg_match('/^Bearer\\s+([A-Fa-f0-9]{64})$/',$header,$m))throw new RuntimeException('Connected-site bearer token is required.');
    $q=$pdo->prepare("SELECT t.id token_id,t.connection_id,s.*,u.display_name,u.email,u.is_active FROM user_connected_site_tokens t JOIN user_connected_sites s ON s.id=t.connection_id JOIN users u ON u.id=s.user_id WHERE t.access_token_hash=? AND t.revoked_at IS NULL AND t.access_expires_at>NOW() AND s.status='active' LIMIT 1");$q->execute([hash('sha256',strtolower($m[1]))]);$row=$q->fetch();if(!$row||empty($row['is_active']))throw new RuntimeException('Connected-site token is invalid or expired.');$scopes=json_decode((string)$row['scopes_json'],true)?:[];if($requiredScope!==''&&!in_array($requiredScope,$scopes,true))throw new RuntimeException('Connected-site scope is not authorized.');$pdo->prepare('UPDATE user_connected_site_tokens SET last_used_at=NOW() WHERE id=?')->execute([(int)$row['token_id']]);$pdo->prepare('UPDATE user_connected_sites SET last_used_at=NOW() WHERE id=?')->execute([(int)$row['connection_id']]);$row['scopes']=$scopes;return $row;
}
function vp3_connected_site_revoke_v100(PDO $pdo,int $userId,int $connectionId): void
{
    $q=$pdo->prepare('SELECT id FROM user_connected_sites WHERE id=? AND user_id=? LIMIT 1');$q->execute([$connectionId,$userId]);if(!$q->fetchColumn())throw new RuntimeException('Connected site was not found.');
    $pdo->beginTransaction();try{$pdo->prepare("UPDATE user_connected_sites SET status='revoked',revoked_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$connectionId]);$pdo->prepare('UPDATE user_connected_site_tokens SET revoked_at=COALESCE(revoked_at,NOW()),updated_at=NOW() WHERE connection_id=?')->execute([$connectionId]);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function vp3_connected_sites_for_user_v100(PDO $pdo,int $userId): array
{
    if(!vp3_connected_sites_schema_ready_v100($pdo))return [];$q=$pdo->prepare("SELECT id,app_key,app_label,client_id,scopes_json,status,connected_at,last_used_at,revoked_at,updated_at FROM user_connected_sites WHERE user_id=? ORDER BY status='active' DESC,updated_at DESC,id DESC");$q->execute([$userId]);$rows=$q->fetchAll()?:[];foreach($rows as &$row)$row['scopes']=json_decode((string)$row['scopes_json'],true)?:[];unset($row);return $rows;
}
function vp3_connected_site_meeting_access_v100(PDO $pdo,int $userId,string $publicId): ?array
{
    $m=video_meeting_by_public_id_v1800($pdo,$publicId);if(!$m)return null;if((int)$m['owner_user_id']===$userId)return $m;$q=$pdo->prepare('SELECT 1 FROM video_meeting_participants WHERE meeting_id=? AND user_id=? LIMIT 1');$q->execute([(int)$m['id'],$userId]);return $q->fetchColumn()?$m:null;
}
function vp3_connected_site_meeting_transcript_v100(PDO $pdo,array $meeting): array
{
    $q=$pdo->prepare("SELECT speaker_name,start_ms,end_ms,transcript_text,confidence,created_at FROM video_meeting_transcript_segments WHERE meeting_id=? AND is_final=1 AND TRIM(transcript_text)<>'' ORDER BY start_ms,id");$q->execute([(int)$meeting['id']]);$segments=$q->fetchAll()?:[];$lines=[];foreach($segments as $s){$speaker=trim((string)$s['speaker_name'])?:'Speaker';$lines[]=$speaker.': '.trim((string)$s['transcript_text']);}$text=implode("\n",$lines);return ['text'=>$text,'segments'=>$segments,'segment_count'=>count($segments),'source_hash'=>hash('sha256',$text)];
}
function vp3_connected_site_meeting_summary_v100(PDO $pdo,array $meeting,int $userId): ?array
{
    if((int)$meeting['owner_user_id']!==$userId||!function_exists('video_meeting_intelligence_source_v1820'))return null;try{$source=video_meeting_intelligence_source_v1820($pdo,$meeting);$snapshot=video_meeting_intelligence_snapshot_v1820($pdo,$source);if(trim((string)($snapshot['summary']??''))===''&&!$snapshot['key_points'])return null;return ['summary'=>(string)($snapshot['summary']??''),'key_points'=>array_slice((array)($snapshot['key_points']??[]),0,20),'decisions'=>array_slice((array)($snapshot['decisions']??[]),0,20),'actions'=>array_slice((array)($snapshot['actions']??[]),0,20),'questions'=>array_slice((array)($snapshot['questions']??[]),0,20),'risks'=>array_slice((array)($snapshot['risks']??[]),0,20),'topics'=>array_slice((array)($snapshot['topics']??[]),0,20),'source_hash'=>(string)($source['source_hash']??''),'generated_at'=>(string)($snapshot['generated_at']??'')];}catch(Throwable $e){return null;}
}
function vp3_connected_site_meeting_descriptor_v100(PDO $pdo,array $meeting,int $userId,array $scopes): array
{
    $t=vp3_connected_site_meeting_transcript_v100($pdo,$meeting);$summary=in_array('transcriptions.intelligence.read',$scopes,true)?vp3_connected_site_meeting_summary_v100($pdo,$meeting,$userId):null;$version=hash('sha256',$t['source_hash'].'|'.($summary['source_hash']??'').'|'.(string)$meeting['updated_at']);
    return ['id'=>(string)$meeting['public_id'],'type'=>'meeting','title'=>(string)$meeting['title'],'status'=>(string)$meeting['status'],'start_at_utc'=>(string)$meeting['start_at_utc'],'ended_at'=>$meeting['ended_at']??null,'updated_at'=>(string)$meeting['updated_at'],'original_url'=>url('/meeting.php?meeting='.(string)$meeting['public_id'].'&review=1'),'transcript_available'=>$t['segment_count']>0,'summary_available'=>$summary!==null,'segment_count'=>$t['segment_count'],'version_hash'=>$version];
}

function vp3_connected_site_transcription_rows_v100(PDO $pdo,int $userId,int $limit=80): array
{
    if(!table_exists('artist_transcript_sessions_v172'))return [];$limit=max(1,min(100,$limit));$q=$pdo->prepare("SELECT * FROM artist_transcript_sessions_v172 WHERE created_by_user_id=? AND status<>'discarded' ORDER BY last_activity_at DESC,id DESC LIMIT ".$limit);$q->execute([$userId]);return $q->fetchAll()?:[];
}
function vp3_connected_site_transcription_access_v100(PDO $pdo,int $userId,string $artifactId): ?array
{
    if(!str_starts_with($artifactId,'transcription-'))return null;$key=substr($artifactId,14);if(!preg_match('/^[a-z0-9-]{16,64}$/',$key))return null;$q=$pdo->prepare("SELECT * FROM artist_transcript_sessions_v172 WHERE created_by_user_id=? AND client_session_key=? AND status<>'discarded' LIMIT 1");$q->execute([$userId,$key]);return $q->fetch()?:null;
}
function vp3_connected_site_transcription_text_v100(PDO $pdo,array $session): array
{
    $segments=function_exists('artist_listening_v172_segments')?artist_listening_v172_segments($pdo,(int)$session['id']):[];$rows=[];$lines=[];foreach($segments as $s){if((string)($s['segment_type']??'transcript')!=='transcript')continue;$text=trim((string)($s['transcript_text']??''));if($text==='')continue;$speaker=trim((string)($s['speaker_label']??''))?:'Speaker';$rows[]=$s;$lines[]=$speaker.': '.$text;}$text=implode("\n",$lines);return ['text'=>$text,'segments'=>$rows,'segment_count'=>count($rows),'source_hash'=>hash('sha256',$text)];
}
function vp3_connected_site_summary_rows_v100(array $rows,string $primary): array
{
    $out=[];foreach(array_slice($rows,0,20) as $row){if(!is_array($row))continue;$text=trim((string)($row[$primary]??$row['text']??''));if($text==='')continue;$out[]=$row+['text'=>$text];}return $out;
}
function vp3_connected_site_transcription_summary_v100(PDO $pdo,array $session): ?array
{
    if(!function_exists('artist_listening_v172_segments')||!function_exists('artist_listening_transcript_page_map')||!function_exists('artist_listening_v237_analysis_status'))return null;
    try{$segments=artist_listening_v172_segments($pdo,(int)$session['id']);$map=artist_listening_transcript_page_map($segments);$status=artist_listening_v237_analysis_status($pdo,(int)$session['id'],$map);$master=is_array($status['master']??null)?$status['master']:null;if(!$master)return null;$analysis=is_array($master['analysis']??null)?$master['analysis']:[];
        $module=null;if(function_exists('transcription_app_modules_v306')){$modules=transcription_app_modules_v306($analysis,$master);$module=is_array($modules['summary_output']??null)?$modules['summary_output']:null;}
        $result=is_array($module['result']??null)?$module['result']:[];
        $overview=vp3_connected_site_summary_rows_v100((array)($result['overview']??[]),'summary');$summary=implode(' ',array_map(static fn($r)=>(string)$r['text'],$overview));if($summary==='')$summary=trim((string)($analysis['summary']??$master['summary']??''));if($summary===''&&!$result)return null;
        return ['summary'=>$summary,'key_points'=>vp3_connected_site_summary_rows_v100((array)($result['key_points']??[]),'point'),'decisions'=>vp3_connected_site_summary_rows_v100((array)($result['decisions']??[]),'decision'),'actions'=>vp3_connected_site_summary_rows_v100((array)($result['next_steps']??[]),'next_step'),'questions'=>vp3_connected_site_summary_rows_v100((array)($result['open_questions']??[]),'question'),'risks'=>vp3_connected_site_summary_rows_v100((array)($result['risks']??[]),'risk'),'topics'=>[],'source_hash'=>(string)($map['source_hash']??$module['source_hash']??''),'generated_at'=>(string)($module['generated_at']??$master['generated_at']??'')];
    }catch(Throwable $e){return null;}
}
function vp3_connected_site_transcription_descriptor_v100(PDO $pdo,array $session,array $scopes): array
{
    $t=vp3_connected_site_transcription_text_v100($pdo,$session);$summary=in_array('transcriptions.intelligence.read',$scopes,true)?vp3_connected_site_transcription_summary_v100($pdo,$session):null;$version=hash('sha256',$t['source_hash'].'|'.($summary['source_hash']??'').'|'.(string)$session['updated_at']);
    return ['id'=>'transcription-'.(string)$session['client_session_key'],'type'=>'transcription','title'=>(string)$session['title'],'status'=>(string)$session['status'],'start_at_utc'=>(string)$session['started_at'],'ended_at'=>$session['stopped_at']??null,'updated_at'=>(string)$session['updated_at'],'original_url'=>url('/artist-listening.php?session_id='.(int)$session['id']),'transcript_available'=>$t['segment_count']>0,'summary_available'=>$summary!==null,'segment_count'=>$t['segment_count'],'version_hash'=>$version];
}
