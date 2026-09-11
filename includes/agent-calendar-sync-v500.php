<?php
declare(strict_types=1);

/**
 * VP3 Agent Calendar Sync v5.00
 *
 * Provider-neutral calendar connection, busy-time and booking synchronization
 * for native Agent Scheduling. OAuth secrets remain in config/environment and
 * provider tokens are encrypted before persistence.
 */
const VP3_AGENT_CALENDAR_SYNC_V500='agent-calendar-sync-v500-20260911';

function agent_calendar_sync_schema_ready_v500(?PDO $pdo=null): bool
{
    $pdo??=db();if(!$pdo)return false;
    foreach(['agent_calendar_connections','agent_calendar_schedule_connections','agent_calendar_busy_blocks','agent_calendar_booking_links'] as $table)if(!table_exists($table))return false;
    return column_exists('agent_calendar_connections','access_token_ciphertext')
        && column_exists('agent_calendar_schedule_connections','blocks_availability')
        && column_exists('agent_calendar_busy_blocks','start_at_utc')
        && column_exists('agent_calendar_booking_links','external_event_id');
}

function agent_calendar_sync_ensure_schema_v500(?PDO $pdo=null): void
{
    $pdo??=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_calendar_connections (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      owner_user_id INT UNSIGNED NOT NULL,
      provider VARCHAR(24) NOT NULL,
      external_account_id VARCHAR(190) NOT NULL DEFAULT '',
      account_email VARCHAR(190) NOT NULL DEFAULT '',
      account_name VARCHAR(190) NOT NULL DEFAULT '',
      calendar_id VARCHAR(500) NOT NULL DEFAULT 'primary',
      calendar_name VARCHAR(190) NOT NULL DEFAULT '',
      access_token_ciphertext MEDIUMTEXT NOT NULL,
      refresh_token_ciphertext MEDIUMTEXT NULL,
      token_expires_at DATETIME NULL,
      scopes TEXT NULL,
      status VARCHAR(24) NOT NULL DEFAULT 'connected',
      sync_enabled TINYINT(1) NOT NULL DEFAULT 1,
      write_enabled TINYINT(1) NOT NULL DEFAULT 1,
      last_synced_at DATETIME NULL,
      last_error VARCHAR(1000) NOT NULL DEFAULT '',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_agent_calendar_account (owner_user_id,provider,external_account_id),
      INDEX idx_agent_calendar_owner (owner_user_id,status,provider,id),
      CONSTRAINT fk_agent_calendar_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_calendar_schedule_connections (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      schedule_id BIGINT UNSIGNED NOT NULL,
      connection_id BIGINT UNSIGNED NOT NULL,
      blocks_availability TINYINT(1) NOT NULL DEFAULT 1,
      writes_bookings TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_agent_calendar_schedule_connection (schedule_id,connection_id),
      INDEX idx_agent_calendar_schedule_lookup (schedule_id,blocks_availability,writes_bookings,connection_id),
      CONSTRAINT fk_agent_calendar_schedule FOREIGN KEY (schedule_id) REFERENCES agent_scheduling_schedules(id) ON DELETE CASCADE,
      CONSTRAINT fk_agent_calendar_schedule_connection FOREIGN KEY (connection_id) REFERENCES agent_calendar_connections(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_calendar_busy_blocks (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      connection_id BIGINT UNSIGNED NOT NULL,
      external_event_key CHAR(64) NOT NULL,
      start_at_utc DATETIME NOT NULL,
      end_at_utc DATETIME NOT NULL,
      fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_agent_calendar_busy (connection_id,external_event_key,start_at_utc,end_at_utc),
      INDEX idx_agent_calendar_busy_lookup (connection_id,start_at_utc,end_at_utc,id),
      CONSTRAINT fk_agent_calendar_busy_connection FOREIGN KEY (connection_id) REFERENCES agent_calendar_connections(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_calendar_booking_links (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      booking_id BIGINT UNSIGNED NOT NULL,
      connection_id BIGINT UNSIGNED NOT NULL,
      external_event_id VARCHAR(500) NOT NULL,
      external_calendar_id VARCHAR(500) NOT NULL DEFAULT '',
      web_url VARCHAR(1000) NOT NULL DEFAULT '',
      sync_status VARCHAR(24) NOT NULL DEFAULT 'synced',
      last_synced_at DATETIME NULL,
      last_error VARCHAR(1000) NOT NULL DEFAULT '',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_agent_calendar_booking_connection (booking_id,connection_id),
      INDEX idx_agent_calendar_booking_external (connection_id,external_event_id(190)),
      CONSTRAINT fk_agent_calendar_booking FOREIGN KEY (booking_id) REFERENCES agent_scheduling_bookings(id) ON DELETE CASCADE,
      CONSTRAINT fk_agent_calendar_booking_connection FOREIGN KEY (connection_id) REFERENCES agent_calendar_connections(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function agent_calendar_sync_config_v500(): array
{
    global $config;$calendar=is_array($config['calendar']??null)?$config['calendar']:[];$providers=is_array($calendar['providers']??null)?$calendar['providers']:[];
    $google=is_array($providers['google']??null)?$providers['google']:[];$microsoft=is_array($providers['microsoft']??null)?$providers['microsoft']:[];
    return [
        'encryption_key'=>trim((string)(getenv('VP3_CALENDAR_ENCRYPTION_KEY')?:($calendar['encryption_key']??''))),
        'google'=>['client_id'=>trim((string)(getenv('VP3_GOOGLE_CALENDAR_CLIENT_ID')?:($google['client_id']??''))),'client_secret'=>trim((string)(getenv('VP3_GOOGLE_CALENDAR_CLIENT_SECRET')?:($google['client_secret']??'')))],
        'microsoft'=>['client_id'=>trim((string)(getenv('VP3_MICROSOFT_CALENDAR_CLIENT_ID')?:($microsoft['client_id']??''))),'client_secret'=>trim((string)(getenv('VP3_MICROSOFT_CALENDAR_CLIENT_SECRET')?:($microsoft['client_secret']??''))),'tenant'=>trim((string)(getenv('VP3_MICROSOFT_CALENDAR_TENANT')?:($microsoft['tenant']??'common')))?:'common'],
    ];
}

function agent_calendar_sync_provider_ready_v500(string $provider): bool
{
    $cfg=agent_calendar_sync_config_v500();$provider=strtolower(trim($provider));
    return $cfg['encryption_key']!==''&&isset($cfg[$provider])&&trim((string)($cfg[$provider]['client_id']??''))!==''&&trim((string)($cfg[$provider]['client_secret']??''))!=='';
}
function agent_calendar_sync_provider_label_v500(string $provider): string{return match(strtolower(trim($provider))){'google'=>'Google Calendar','microsoft'=>'Microsoft Outlook',default=>'Calendar'};}

function agent_calendar_sync_encrypt_v500(string $plain): string
{
    if($plain==='')return '';$secret=(string)agent_calendar_sync_config_v500()['encryption_key'];
    if($secret==='')throw new RuntimeException('Calendar token encryption is not configured.');
    if(!function_exists('openssl_encrypt'))throw new RuntimeException('OpenSSL is required for calendar token encryption.');
    $iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($plain,'aes-256-gcm',hash('sha256',$secret,true),OPENSSL_RAW_DATA,$iv,$tag,'vp3-calendar-v500',16);
    if($cipher===false||strlen($tag)!==16)throw new RuntimeException('Calendar token encryption failed.');
    return 'v1:'.base64_encode($iv.$tag.$cipher);
}
function agent_calendar_sync_decrypt_v500(string $ciphertext): string
{
    if($ciphertext==='')return '';$secret=(string)agent_calendar_sync_config_v500()['encryption_key'];
    if($secret==='')throw new RuntimeException('Calendar token encryption is not configured.');
    if(!str_starts_with($ciphertext,'v1:'))throw new RuntimeException('Unsupported calendar token format.');
    $raw=base64_decode(substr($ciphertext,3),true);if($raw===false||strlen($raw)<29)throw new RuntimeException('Calendar token data is invalid.');
    $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',hash('sha256',$secret,true),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),'vp3-calendar-v500');
    if($plain===false)throw new RuntimeException('Calendar token decryption failed.');return $plain;
}

function agent_calendar_sync_absolute_url_v500(string $path): string
{
    global $config;$base=trim((string)($config['site']['base_url']??''));
    if($base===''){$host=preg_replace('/[^A-Za-z0-9.\-:\[\]]/','',(string)($_SERVER['HTTP_HOST']??''))??'';if($host==='')throw new RuntimeException('Configure site.base_url before connecting a calendar.');$base=((!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http').'://'.$host;}
    return rtrim($base,'/').url($path);
}

function agent_calendar_sync_http_v500(string $method,string $url,array $headers=[],mixed $body=null,bool $form=false): array
{
    if(!function_exists('curl_init'))throw new RuntimeException('cURL is required for calendar synchronization.');
    $ch=curl_init($url);$opts=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>20,CURLOPT_CUSTOMREQUEST=>strtoupper($method),CURLOPT_HTTPHEADER=>$headers,CURLOPT_USERAGENT=>'VP3-Agent-Calendar/'.VP3_AGENT_CALENDAR_SYNC_V500];
    if($body!==null){$payload=$form?http_build_query((array)$body,'','&',PHP_QUERY_RFC3986):(is_string($body)?$body:json_encode($body,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));$opts[CURLOPT_POSTFIELDS]=$payload;$opts[CURLOPT_HTTPHEADER]=array_merge($headers,[$form?'Content-Type: application/x-www-form-urlencoded':'Content-Type: application/json']);}
    curl_setopt_array($ch,$opts);$raw=curl_exec($ch);$errno=curl_errno($ch);$error=curl_error($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
    if($errno!==0)throw new RuntimeException('Calendar provider request failed: '.$error);
    $decoded=$raw!==false&&$raw!==''?json_decode((string)$raw,true):[];if(!is_array($decoded))$decoded=[];
    if($status<200||$status>=300){$message=(string)($decoded['error_description']??$decoded['error']['message']??$decoded['error']['code']??'Calendar provider returned HTTP '.$status.'.');throw new RuntimeException(mb_strimwidth($message,0,700,'…'));}
    return ['status'=>$status,'json'=>$decoded,'raw'=>(string)$raw];
}

function agent_calendar_sync_oauth_callback_v500(): string{return agent_calendar_sync_absolute_url_v500('/calendar-oauth.php');}
function agent_calendar_sync_oauth_state_v500(array $user,string $provider,int $scheduleId): string
{
    $provider=strtolower(trim($provider));if(!in_array($provider,['google','microsoft'],true))throw new RuntimeException('Unknown calendar provider.');
    $token=bin2hex(random_bytes(24));$_SESSION['vp3_calendar_oauth'][$token]=['user_id'=>(int)$user['id'],'provider'=>$provider,'schedule_id'=>$scheduleId,'expires_at'=>time()+900];return $token;
}
function agent_calendar_sync_oauth_state_take_v500(array $user,string $token): array
{
    $state=$_SESSION['vp3_calendar_oauth'][$token]??null;unset($_SESSION['vp3_calendar_oauth'][$token]);
    if(!is_array($state)||(int)($state['user_id']??0)!==(int)$user['id']||(int)($state['expires_at']??0)<time())throw new RuntimeException('Calendar connection session expired. Please try again.');return $state;
}
function agent_calendar_sync_oauth_url_v500(array $user,string $provider,int $scheduleId): string
{
    if(!agent_calendar_sync_provider_ready_v500($provider))throw new RuntimeException(agent_calendar_sync_provider_label_v500($provider).' is not configured on this VP3 deployment.');
    $cfg=agent_calendar_sync_config_v500();$state=agent_calendar_sync_oauth_state_v500($user,$provider,$scheduleId);$redirect=agent_calendar_sync_oauth_callback_v500();
    if($provider==='google')return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query(['client_id'=>$cfg['google']['client_id'],'redirect_uri'=>$redirect,'response_type'=>'code','access_type'=>'offline','prompt'=>'consent','scope'=>'openid email profile https://www.googleapis.com/auth/calendar.readonly https://www.googleapis.com/auth/calendar.events','state'=>$state,'include_granted_scopes'=>'true'],'','&',PHP_QUERY_RFC3986);
    $tenant=preg_replace('/[^A-Za-z0-9._-]/','',(string)$cfg['microsoft']['tenant'])?:'common';
    return 'https://login.microsoftonline.com/'.$tenant.'/oauth2/v2.0/authorize?'.http_build_query(['client_id'=>$cfg['microsoft']['client_id'],'redirect_uri'=>$redirect,'response_type'=>'code','response_mode'=>'query','scope'=>'openid profile email offline_access User.Read Calendars.ReadWrite','state'=>$state],'','&',PHP_QUERY_RFC3986);
}
function agent_calendar_sync_exchange_code_v500(string $provider,string $code): array
{
    $cfg=agent_calendar_sync_config_v500();$redirect=agent_calendar_sync_oauth_callback_v500();
    if($provider==='google')return agent_calendar_sync_http_v500('POST','https://oauth2.googleapis.com/token',[],['code'=>$code,'client_id'=>$cfg['google']['client_id'],'client_secret'=>$cfg['google']['client_secret'],'redirect_uri'=>$redirect,'grant_type'=>'authorization_code'],true)['json'];
    $tenant=preg_replace('/[^A-Za-z0-9._-]/','',(string)$cfg['microsoft']['tenant'])?:'common';
    return agent_calendar_sync_http_v500('POST','https://login.microsoftonline.com/'.$tenant.'/oauth2/v2.0/token',[],['code'=>$code,'client_id'=>$cfg['microsoft']['client_id'],'client_secret'=>$cfg['microsoft']['client_secret'],'redirect_uri'=>$redirect,'grant_type'=>'authorization_code','scope'=>'openid profile email offline_access User.Read Calendars.ReadWrite'],true)['json'];
}

function agent_calendar_sync_account_v500(PDO $pdo,string $provider,array $token): array
{
    $access=trim((string)($token['access_token']??''));if($access==='')throw new RuntimeException('Calendar provider did not return an access token.');$headers=['Authorization: Bearer '.$access,'Accept: application/json'];
    if($provider==='google'){$p=agent_calendar_sync_http_v500('GET','https://openidconnect.googleapis.com/v1/userinfo',$headers)['json'];return ['id'=>(string)($p['sub']??''),'email'=>(string)($p['email']??''),'name'=>(string)($p['name']??$p['email']??'Google Calendar')];}
    $p=agent_calendar_sync_http_v500('GET','https://graph.microsoft.com/v1.0/me?$select=id,displayName,mail,userPrincipalName',$headers)['json'];return ['id'=>(string)($p['id']??''),'email'=>(string)($p['mail']??$p['userPrincipalName']??''),'name'=>(string)($p['displayName']??$p['mail']??'Microsoft Outlook')];
}

function agent_calendar_sync_store_connection_v500(PDO $pdo,array $user,string $provider,array $token,array $account,int $scheduleId): array
{
    $userId=(int)$user['id'];$externalId=mb_strimwidth(trim((string)($account['id']??'')),0,190,'');if($userId<1||$externalId==='')throw new RuntimeException('Calendar account identity could not be verified.');
    $access=trim((string)($token['access_token']??''));if($access==='')throw new RuntimeException('Calendar provider did not return an access token.');
    $refresh=trim((string)($token['refresh_token']??''));$find=$pdo->prepare('SELECT * FROM agent_calendar_connections WHERE owner_user_id=? AND provider=? AND external_account_id=? LIMIT 1');$find->execute([$userId,$provider,$externalId]);$row=$find->fetch()?:null;
    if($refresh===''&&$row)$refresh=agent_calendar_sync_decrypt_v500((string)($row['refresh_token_ciphertext']??''));$expiresAt=date('Y-m-d H:i:s',time()+max(300,(int)($token['expires_in']??3600)));$scopes=trim((string)($token['scope']??''));
    if($row){$pdo->prepare("UPDATE agent_calendar_connections SET account_email=?,account_name=?,access_token_ciphertext=?,refresh_token_ciphertext=?,token_expires_at=?,scopes=?,status='connected',sync_enabled=1,write_enabled=1,last_error='' WHERE id=? AND owner_user_id=?")->execute([mb_strimwidth((string)($account['email']??''),0,190,''),mb_strimwidth((string)($account['name']??''),0,190,''),agent_calendar_sync_encrypt_v500($access),$refresh!==''?agent_calendar_sync_encrypt_v500($refresh):null,$expiresAt,$scopes,(int)$row['id'],$userId]);$connectionId=(int)$row['id'];}
    else{$pdo->prepare("INSERT INTO agent_calendar_connections (owner_user_id,provider,external_account_id,account_email,account_name,access_token_ciphertext,refresh_token_ciphertext,token_expires_at,scopes,status,sync_enabled,write_enabled) VALUES (?,?,?,?,?,?,?,?,?,'connected',1,1)")->execute([$userId,$provider,$externalId,mb_strimwidth((string)($account['email']??''),0,190,''),mb_strimwidth((string)($account['name']??''),0,190,''),agent_calendar_sync_encrypt_v500($access),$refresh!==''?agent_calendar_sync_encrypt_v500($refresh):null,$expiresAt,$scopes]);$connectionId=(int)$pdo->lastInsertId();}
    if($scheduleId>0&&agent_scheduling_schedule_v430($pdo,$userId,$scheduleId))$pdo->prepare('INSERT INTO agent_calendar_schedule_connections (schedule_id,connection_id,blocks_availability,writes_bookings) VALUES (?,?,1,1) ON DUPLICATE KEY UPDATE blocks_availability=1,writes_bookings=1')->execute([$scheduleId,$connectionId]);
    $stmt=$pdo->prepare('SELECT * FROM agent_calendar_connections WHERE id=? AND owner_user_id=? LIMIT 1');$stmt->execute([$connectionId,$userId]);return $stmt->fetch()?:throw new RuntimeException('Calendar connection could not be saved.');
}

function agent_calendar_sync_connections_v500(PDO $pdo,int $ownerUserId,?int $scheduleId=null): array
{
    if($ownerUserId<1||!agent_calendar_sync_schema_ready_v500($pdo))return [];
    if($scheduleId&&$scheduleId>0){$stmt=$pdo->prepare("SELECT c.*,sc.blocks_availability,sc.writes_bookings FROM agent_calendar_connections c INNER JOIN agent_calendar_schedule_connections sc ON sc.connection_id=c.id WHERE c.owner_user_id=? AND sc.schedule_id=? ORDER BY c.status='connected' DESC,c.provider,c.account_email,c.id");$stmt->execute([$ownerUserId,$scheduleId]);}
    else{$stmt=$pdo->prepare("SELECT c.* FROM agent_calendar_connections c WHERE c.owner_user_id=? ORDER BY c.status='connected' DESC,c.provider,c.account_email,c.id");$stmt->execute([$ownerUserId]);}
    return $stmt->fetchAll()?:[];
}
function agent_calendar_sync_connection_v500(PDO $pdo,int $ownerUserId,int $connectionId): ?array{$stmt=$pdo->prepare('SELECT * FROM agent_calendar_connections WHERE id=? AND owner_user_id=? LIMIT 1');$stmt->execute([$connectionId,$ownerUserId]);return $stmt->fetch()?:null;}
function agent_calendar_sync_link_schedule_v500(PDO $pdo,array $user,int $scheduleId,int $connectionId,bool $blocks,bool $writes): void
{
    $userId=(int)$user['id'];if(!agent_scheduling_schedule_v430($pdo,$userId,$scheduleId))throw new RuntimeException('Schedule not found.');if(!agent_calendar_sync_connection_v500($pdo,$userId,$connectionId))throw new RuntimeException('Calendar connection not found.');
    $pdo->prepare('INSERT INTO agent_calendar_schedule_connections (schedule_id,connection_id,blocks_availability,writes_bookings) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE blocks_availability=VALUES(blocks_availability),writes_bookings=VALUES(writes_bookings)')->execute([$scheduleId,$connectionId,$blocks?1:0,$writes?1:0]);
}
function agent_calendar_sync_disconnect_v500(PDO $pdo,array $user,int $connectionId): bool
{
    $stmt=$pdo->prepare("UPDATE agent_calendar_connections SET status='disconnected',sync_enabled=0,write_enabled=0,access_token_ciphertext='',refresh_token_ciphertext=NULL,token_expires_at=NULL,last_error='' WHERE id=? AND owner_user_id=?");$stmt->execute([$connectionId,(int)$user['id']]);return $stmt->rowCount()>0;
}

function agent_calendar_sync_refresh_v500(PDO $pdo,array $connection): array
{
    $expires=strtotime((string)($connection['token_expires_at']??''))?:0;if($expires>time()+120)return $connection;
    $refresh=agent_calendar_sync_decrypt_v500((string)($connection['refresh_token_ciphertext']??''));if($refresh==='')throw new RuntimeException('Calendar access expired and no refresh token is available. Reconnect the calendar.');
    $provider=(string)$connection['provider'];$cfg=agent_calendar_sync_config_v500();
    if($provider==='google')$token=agent_calendar_sync_http_v500('POST','https://oauth2.googleapis.com/token',[],['client_id'=>$cfg['google']['client_id'],'client_secret'=>$cfg['google']['client_secret'],'refresh_token'=>$refresh,'grant_type'=>'refresh_token'],true)['json'];
    else{$tenant=preg_replace('/[^A-Za-z0-9._-]/','',(string)$cfg['microsoft']['tenant'])?:'common';$token=agent_calendar_sync_http_v500('POST','https://login.microsoftonline.com/'.$tenant.'/oauth2/v2.0/token',[],['client_id'=>$cfg['microsoft']['client_id'],'client_secret'=>$cfg['microsoft']['client_secret'],'refresh_token'=>$refresh,'grant_type'=>'refresh_token','scope'=>'openid profile email offline_access User.Read Calendars.ReadWrite'],true)['json'];}
    $access=trim((string)($token['access_token']??''));if($access==='')throw new RuntimeException('Calendar provider did not return a refreshed access token.');$nextRefresh=trim((string)($token['refresh_token']??''))?:$refresh;$expiresAt=date('Y-m-d H:i:s',time()+max(300,(int)($token['expires_in']??3600)));
    $accessCipher=agent_calendar_sync_encrypt_v500($access);$refreshCipher=agent_calendar_sync_encrypt_v500($nextRefresh);
    $pdo->prepare("UPDATE agent_calendar_connections SET access_token_ciphertext=?,refresh_token_ciphertext=?,token_expires_at=?,status='connected',last_error='' WHERE id=? AND owner_user_id=?")->execute([$accessCipher,$refreshCipher,$expiresAt,(int)$connection['id'],(int)$connection['owner_user_id']]);
    $connection['access_token_ciphertext']=$accessCipher;$connection['refresh_token_ciphertext']=$refreshCipher;$connection['token_expires_at']=$expiresAt;$connection['status']='connected';return $connection;
}
function agent_calendar_sync_api_v500(PDO $pdo,array $connection,string $method,string $url,mixed $body=null,array $headers=[]): array
{
    $connection=agent_calendar_sync_refresh_v500($pdo,$connection);$token=agent_calendar_sync_decrypt_v500((string)$connection['access_token_ciphertext']);if($token==='')throw new RuntimeException('Calendar access token is unavailable.');
    return agent_calendar_sync_http_v500($method,$url,array_merge(['Authorization: Bearer '.$token,'Accept: application/json'],$headers),$body,false);
}

function agent_calendar_sync_replace_busy_v500(PDO $pdo,int $connectionId,array $blocks): void
{
    $started=!$pdo->inTransaction();if($started)$pdo->beginTransaction();
    try{$pdo->prepare('DELETE FROM agent_calendar_busy_blocks WHERE connection_id=?')->execute([$connectionId]);$insert=$pdo->prepare('INSERT IGNORE INTO agent_calendar_busy_blocks (connection_id,external_event_key,start_at_utc,end_at_utc) VALUES (?,?,?,?)');foreach($blocks as $block){$start=(string)($block['start_at_utc']??'');$end=(string)($block['end_at_utc']??'');if($start===''||$end===''||$end<=$start)continue;$insert->execute([$connectionId,hash('sha256',(string)($block['key']??$connectionId.'|'.$start.'|'.$end)),$start,$end]);}if($started)$pdo->commit();}
    catch(Throwable $e){if($started&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function agent_calendar_sync_busy_google_v500(PDO $pdo,array $connection,string $timeMin,string $timeMax): array
{
    $calendarId=trim((string)($connection['calendar_id']??'primary'))?:'primary';$json=agent_calendar_sync_api_v500($pdo,$connection,'POST','https://www.googleapis.com/calendar/v3/freeBusy',['timeMin'=>$timeMin,'timeMax'=>$timeMax,'timeZone'=>'UTC','items'=>[['id'=>$calendarId]]])['json'];$blocks=[];
    foreach((array)($json['calendars'][$calendarId]['busy']??[]) as $row){try{$s=(new DateTimeImmutable((string)$row['start']))->setTimezone(new DateTimeZone('UTC'));$e=(new DateTimeImmutable((string)$row['end']))->setTimezone(new DateTimeZone('UTC'));}catch(Throwable $ignored){continue;}$blocks[]=['key'=>'google|'.$s->format(DATE_ATOM).'|'.$e->format(DATE_ATOM),'start_at_utc'=>$s->format('Y-m-d H:i:s'),'end_at_utc'=>$e->format('Y-m-d H:i:s')];}return $blocks;
}
function agent_calendar_sync_busy_microsoft_v500(PDO $pdo,array $connection,string $timeMin,string $timeMax): array
{
    $start=rawurlencode((new DateTimeImmutable($timeMin))->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM));$end=rawurlencode((new DateTimeImmutable($timeMax))->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM));$url='https://graph.microsoft.com/v1.0/me/calendarView?startDateTime='.$start.'&endDateTime='.$end.'&$select=id,start,end,showAs,isCancelled&$top=500';$blocks=[];$pages=0;
    while($url!==''&&$pages<10){$json=agent_calendar_sync_api_v500($pdo,$connection,'GET',$url,null,['Prefer: outlook.timezone="UTC"'])['json'];$pages++;foreach((array)($json['value']??[]) as $row){if(!empty($row['isCancelled'])||strtolower((string)($row['showAs']??'busy'))==='free')continue;try{$s=new DateTimeImmutable((string)($row['start']['dateTime']??''),new DateTimeZone((string)($row['start']['timeZone']??'UTC')));$e=new DateTimeImmutable((string)($row['end']['dateTime']??''),new DateTimeZone((string)($row['end']['timeZone']??'UTC')));}catch(Throwable $ignored){continue;}$s=$s->setTimezone(new DateTimeZone('UTC'));$e=$e->setTimezone(new DateTimeZone('UTC'));$blocks[]=['key'=>'microsoft|'.(string)($row['id']??'').'|'.$s->format(DATE_ATOM),'start_at_utc'=>$s->format('Y-m-d H:i:s'),'end_at_utc'=>$e->format('Y-m-d H:i:s')];}$next=(string)($json['@odata.nextLink']??'');$url=str_starts_with($next,'https://graph.microsoft.com/')?$next:'';}return $blocks;
}
function agent_calendar_sync_busy_v500(PDO $pdo,array $connection): array
{
    if((string)($connection['status']??'')==='disconnected'||empty($connection['sync_enabled']))return [];$timeMin=(new DateTimeImmutable('-1 day',new DateTimeZone('UTC')))->format(DATE_ATOM);$timeMax=(new DateTimeImmutable('+400 days',new DateTimeZone('UTC')))->format(DATE_ATOM);
    $blocks=(string)$connection['provider']==='google'?agent_calendar_sync_busy_google_v500($pdo,$connection,$timeMin,$timeMax):agent_calendar_sync_busy_microsoft_v500($pdo,$connection,$timeMin,$timeMax);agent_calendar_sync_replace_busy_v500($pdo,(int)$connection['id'],$blocks);
    $pdo->prepare("UPDATE agent_calendar_connections SET last_synced_at=NOW(),status='connected',last_error='' WHERE id=? AND owner_user_id=?")->execute([(int)$connection['id'],(int)$connection['owner_user_id']]);return $blocks;
}
function agent_calendar_sync_error_v500(PDO $pdo,array $connection,Throwable $e): void{$pdo->prepare("UPDATE agent_calendar_connections SET status=CASE WHEN status='disconnected' THEN status ELSE 'error' END,last_error=? WHERE id=? AND owner_user_id=?")->execute([mb_strimwidth($e->getMessage(),0,1000,'…'),(int)$connection['id'],(int)$connection['owner_user_id']]);}

function agent_calendar_sync_booking_payload_v500(array $booking): array
{
    $description='Scheduled through VP3.';if(trim((string)($booking['guest_notes']??''))!=='')$description.="\n\nGuest notes:\n".trim((string)$booking['guest_notes']);
    return ['summary'=>mb_strimwidth((string)$booking['event_title'].' — '.(string)$booking['guest_name'],0,190,''),'description'=>$description,'start_at_utc'=>(string)$booking['start_at_utc'],'end_at_utc'=>(string)$booking['end_at_utc'],'guest_email'=>(string)$booking['guest_email'],'location_type'=>(string)$booking['location_type'],'location_value'=>(string)$booking['location_value']];
}
function agent_calendar_sync_google_event_v500(PDO $pdo,array $connection,array $booking,?string $externalId=null): array
{
    $calendarId=trim((string)($connection['calendar_id']??'primary'))?:'primary';$p=agent_calendar_sync_booking_payload_v500($booking);$body=['summary'=>$p['summary'],'description'=>$p['description'],'start'=>['dateTime'=>(new DateTimeImmutable($p['start_at_utc'],new DateTimeZone('UTC')))->format(DATE_ATOM),'timeZone'=>'UTC'],'end'=>['dateTime'=>(new DateTimeImmutable($p['end_at_utc'],new DateTimeZone('UTC')))->format(DATE_ATOM),'timeZone'=>'UTC'],'reminders'=>['useDefault'=>true]];
    if($p['location_value']!=='')$body['location']=$p['location_value'];if($p['guest_email']!=='')$body['attendees']=[['email'=>$p['guest_email']]];$wantMeet=$p['location_type']==='virtual'&&$p['location_value']==='';if($wantMeet)$body['conferenceData']=['createRequest'=>['requestId'=>'vp3-'.(int)$booking['id'].'-'.bin2hex(random_bytes(5)),'conferenceSolutionKey'=>['type'=>'hangoutsMeet']]];
    $base='https://www.googleapis.com/calendar/v3/calendars/'.rawurlencode($calendarId).'/events';$url=$externalId?$base.'/'.rawurlencode($externalId):$base;$url.='?sendUpdates=all'.($wantMeet?'&conferenceDataVersion=1':'');
    try{$json=agent_calendar_sync_api_v500($pdo,$connection,$externalId?'PUT':'POST',$url,$body)['json'];}catch(Throwable $e){if(!$wantMeet)throw $e;unset($body['conferenceData']);$url=($externalId?$base.'/'.rawurlencode($externalId):$base).'?sendUpdates=all';$json=agent_calendar_sync_api_v500($pdo,$connection,$externalId?'PUT':'POST',$url,$body)['json'];}
    $join=trim((string)($json['hangoutLink']??''));if($join===''&&is_array($json['conferenceData']['entryPoints']??null))foreach($json['conferenceData']['entryPoints'] as $entry)if(($entry['entryPointType']??'')==='video'){$join=(string)($entry['uri']??'');break;}
    return ['id'=>(string)($json['id']??$externalId??''),'web_url'=>(string)($json['htmlLink']??''),'meeting_url'=>$join];
}
function agent_calendar_sync_microsoft_event_v500(PDO $pdo,array $connection,array $booking,?string $externalId=null): array
{
    $p=agent_calendar_sync_booking_payload_v500($booking);$body=['subject'=>$p['summary'],'body'=>['contentType'=>'text','content'=>$p['description']],'start'=>['dateTime'=>str_replace(' ','T',$p['start_at_utc']),'timeZone'=>'UTC'],'end'=>['dateTime'=>str_replace(' ','T',$p['end_at_utc']),'timeZone'=>'UTC'],'isReminderOn'=>true];
    if($p['location_value']!=='')$body['location']=['displayName'=>$p['location_value']];if($p['guest_email']!=='')$body['attendees']=[['emailAddress'=>['address'=>$p['guest_email'],'name'=>(string)$booking['guest_name']],'type'=>'required']];$wantTeams=$p['location_type']==='virtual'&&$p['location_value']==='';if($wantTeams){$body['isOnlineMeeting']=true;$body['onlineMeetingProvider']='teamsForBusiness';}
    $url='https://graph.microsoft.com/v1.0/me/events'.($externalId?'/'.rawurlencode($externalId):'');try{$json=agent_calendar_sync_api_v500($pdo,$connection,$externalId?'PATCH':'POST',$url,$body)['json'];}catch(Throwable $e){if(!$wantTeams)throw $e;unset($body['isOnlineMeeting'],$body['onlineMeetingProvider']);$json=agent_calendar_sync_api_v500($pdo,$connection,$externalId?'PATCH':'POST',$url,$body)['json'];}
    return ['id'=>(string)($json['id']??$externalId??''),'web_url'=>(string)($json['webLink']??''),'meeting_url'=>(string)($json['onlineMeeting']['joinUrl']??'')];
}

function agent_calendar_sync_booking_to_connection_v500(PDO $pdo,array $connection,array $booking): void
{
    if((string)$connection['status']==='disconnected'||empty($connection['write_enabled']))return;$linkStmt=$pdo->prepare('SELECT * FROM agent_calendar_booking_links WHERE booking_id=? AND connection_id=? LIMIT 1');$linkStmt->execute([(int)$booking['id'],(int)$connection['id']]);$link=$linkStmt->fetch()?:null;$externalId=$link?(string)$link['external_event_id']:null;
    try{$result=(string)$connection['provider']==='google'?agent_calendar_sync_google_event_v500($pdo,$connection,$booking,$externalId):agent_calendar_sync_microsoft_event_v500($pdo,$connection,$booking,$externalId);if(trim((string)$result['id'])==='')throw new RuntimeException('Calendar provider did not return an event id.');
        $pdo->prepare("INSERT INTO agent_calendar_booking_links (booking_id,connection_id,external_event_id,external_calendar_id,web_url,sync_status,last_synced_at,last_error) VALUES (?,?,?,?,?,'synced',NOW(),'') ON DUPLICATE KEY UPDATE external_event_id=VALUES(external_event_id),external_calendar_id=VALUES(external_calendar_id),web_url=VALUES(web_url),sync_status='synced',last_synced_at=NOW(),last_error='' ")->execute([(int)$booking['id'],(int)$connection['id'],mb_strimwidth((string)$result['id'],0,500,''),mb_strimwidth((string)$connection['calendar_id'],0,500,''),mb_strimwidth((string)$result['web_url'],0,1000,'')]);
        if((string)$booking['location_type']==='virtual'&&trim((string)$booking['location_value'])===''&&trim((string)$result['meeting_url'])!=='')$pdo->prepare("UPDATE agent_scheduling_bookings SET location_value=? WHERE id=? AND owner_user_id=? AND location_type='virtual' AND location_value='' ")->execute([mb_strimwidth((string)$result['meeting_url'],0,500,''),(int)$booking['id'],(int)$booking['owner_user_id']]);
    }catch(Throwable $e){if($link)$pdo->prepare("UPDATE agent_calendar_booking_links SET sync_status='error',last_error=? WHERE id=?")->execute([mb_strimwidth($e->getMessage(),0,1000,'…'),(int)$link['id']]);throw $e;}
}
function agent_calendar_sync_cancel_booking_connection_v500(PDO $pdo,array $connection,array $booking,bool $refreshBusy=true): void
{
    $stmt=$pdo->prepare('SELECT * FROM agent_calendar_booking_links WHERE booking_id=? AND connection_id=? LIMIT 1');$stmt->execute([(int)$booking['id'],(int)$connection['id']]);$link=$stmt->fetch();if(!$link)return;
    if((string)$link['sync_status']==='cancelled')return;
    try{$eventId=(string)$link['external_event_id'];if($eventId!==''){if((string)$connection['provider']==='google'){$calendarId=trim((string)$connection['calendar_id'])?:'primary';agent_calendar_sync_api_v500($pdo,$connection,'DELETE','https://www.googleapis.com/calendar/v3/calendars/'.rawurlencode($calendarId).'/events/'.rawurlencode($eventId).'?sendUpdates=all');}else agent_calendar_sync_api_v500($pdo,$connection,'DELETE','https://graph.microsoft.com/v1.0/me/events/'.rawurlencode($eventId));}
        $pdo->prepare("UPDATE agent_calendar_booking_links SET sync_status='cancelled',last_synced_at=NOW(),last_error='' WHERE id=?")->execute([(int)$link['id']]);if($refreshBusy)agent_calendar_sync_busy_v500($pdo,$connection);
    }catch(Throwable $e){$pdo->prepare("UPDATE agent_calendar_booking_links SET sync_status='error',last_error=? WHERE id=?")->execute([mb_strimwidth($e->getMessage(),0,1000,'…'),(int)$link['id']]);throw $e;}
}

function agent_calendar_sync_reconcile_connection_v500(PDO $pdo,array $connection): void
{
    $cancel=$pdo->prepare("SELECT b.* FROM agent_calendar_booking_links l INNER JOIN agent_scheduling_bookings b ON b.id=l.booking_id WHERE l.connection_id=? AND b.owner_user_id=? AND b.status='cancelled' AND l.sync_status<>'cancelled' ORDER BY b.updated_at ASC LIMIT 250");$cancel->execute([(int)$connection['id'],(int)$connection['owner_user_id']]);
    foreach($cancel->fetchAll()?:[] as $booking)try{agent_calendar_sync_cancel_booking_connection_v500($pdo,$connection,$booking,false);}catch(Throwable $ignored){}
    $active=$pdo->prepare("SELECT b.* FROM agent_scheduling_bookings b INNER JOIN agent_calendar_schedule_connections sc ON sc.schedule_id=b.schedule_id WHERE sc.connection_id=? AND sc.writes_bookings=1 AND b.owner_user_id=? AND b.status IN ('pending','confirmed') AND b.end_at_utc>=UTC_TIMESTAMP() ORDER BY b.start_at_utc LIMIT 250");$active->execute([(int)$connection['id'],(int)$connection['owner_user_id']]);
    foreach($active->fetchAll()?:[] as $booking)try{agent_calendar_sync_booking_to_connection_v500($pdo,$connection,$booking);}catch(Throwable $ignored){}
}
function agent_calendar_sync_now_v500(PDO $pdo,array $connection,bool $reconcile=true): array
{
    try{if($reconcile&&empty($connection['write_enabled'])===false)agent_calendar_sync_reconcile_connection_v500($pdo,$connection);$blocks=agent_calendar_sync_busy_v500($pdo,$connection);return ['ok'=>true,'busy_count'=>count($blocks)];}
    catch(Throwable $e){agent_calendar_sync_error_v500($pdo,$connection,$e);return ['ok'=>false,'error'=>$e->getMessage()];}
}

function agent_calendar_sync_defer_schedule_v500(int $scheduleId,int $ownerUserId): void
{
    static $queued=[];$key=$ownerUserId.':'.$scheduleId;if($scheduleId<1||$ownerUserId<1||isset($queued[$key]))return;$queued[$key]=true;
    register_shutdown_function(static function()use($scheduleId,$ownerUserId):void{
        try{$pdo=db();if(!$pdo||$pdo->inTransaction()||!agent_calendar_sync_schema_ready_v500($pdo))return;$stmt=$pdo->prepare("SELECT c.* FROM agent_calendar_connections c INNER JOIN agent_calendar_schedule_connections sc ON sc.connection_id=c.id WHERE sc.schedule_id=? AND c.owner_user_id=? AND c.status IN ('connected','error') AND c.sync_enabled=1");$stmt->execute([$scheduleId,$ownerUserId]);foreach($stmt->fetchAll()?:[] as $connection)agent_calendar_sync_now_v500($pdo,$connection,true);}catch(Throwable $ignored){}
    });
}
function agent_calendar_sync_booking_v500(PDO $pdo,array $booking): void
{
    if(!agent_calendar_sync_schema_ready_v500($pdo))return;if($pdo->inTransaction()){agent_calendar_sync_defer_schedule_v500((int)$booking['schedule_id'],(int)$booking['owner_user_id']);return;}
    $stmt=$pdo->prepare("SELECT c.* FROM agent_calendar_connections c INNER JOIN agent_calendar_schedule_connections sc ON sc.connection_id=c.id WHERE sc.schedule_id=? AND sc.writes_bookings=1 AND c.owner_user_id=? AND c.status IN ('connected','error') AND c.write_enabled=1");$stmt->execute([(int)$booking['schedule_id'],(int)$booking['owner_user_id']]);foreach($stmt->fetchAll()?:[] as $connection)try{agent_calendar_sync_booking_to_connection_v500($pdo,$connection,$booking);}catch(Throwable $e){agent_calendar_sync_error_v500($pdo,$connection,$e);}
}
function agent_calendar_sync_cancel_booking_v500(PDO $pdo,array $booking): void
{
    if(!agent_calendar_sync_schema_ready_v500($pdo))return;if($pdo->inTransaction()){agent_calendar_sync_defer_schedule_v500((int)$booking['schedule_id'],(int)$booking['owner_user_id']);return;}
    $stmt=$pdo->prepare("SELECT c.* FROM agent_calendar_connections c INNER JOIN agent_calendar_schedule_connections sc ON sc.connection_id=c.id WHERE sc.schedule_id=? AND sc.writes_bookings=1 AND c.owner_user_id=? AND c.status IN ('connected','error')");$stmt->execute([(int)$booking['schedule_id'],(int)$booking['owner_user_id']]);foreach($stmt->fetchAll()?:[] as $connection)try{agent_calendar_sync_cancel_booking_connection_v500($pdo,$connection,$booking,true);}catch(Throwable $e){agent_calendar_sync_error_v500($pdo,$connection,$e);}
}
function agent_calendar_sync_maybe_schedule_v500(PDO $pdo,int $scheduleId): void
{
    static $seen=[];if($scheduleId<1||isset($seen[$scheduleId])||!agent_calendar_sync_schema_ready_v500($pdo))return;$seen[$scheduleId]=true;
    $stmt=$pdo->prepare("SELECT c.* FROM agent_calendar_connections c INNER JOIN agent_calendar_schedule_connections sc ON sc.connection_id=c.id WHERE sc.schedule_id=? AND sc.blocks_availability=1 AND c.status IN ('connected','error') AND c.sync_enabled=1");$stmt->execute([$scheduleId]);foreach($stmt->fetchAll()?:[] as $connection){$last=strtotime((string)($connection['last_synced_at']??''))?:0;if($last>time()-300)continue;agent_calendar_sync_now_v500($pdo,$connection,true);}
}
function agent_calendar_sync_conflict_v500(PDO $pdo,int $scheduleId,string $startUtc,string $endUtc): ?array
{
    if($scheduleId<1||!agent_calendar_sync_schema_ready_v500($pdo))return null;$stmt=$pdo->prepare("SELECT bb.id,bb.connection_id,bb.start_at_utc,bb.end_at_utc,c.provider,c.account_email FROM agent_calendar_busy_blocks bb INNER JOIN agent_calendar_connections c ON c.id=bb.connection_id INNER JOIN agent_calendar_schedule_connections sc ON sc.connection_id=c.id WHERE sc.schedule_id=? AND sc.blocks_availability=1 AND c.status IN ('connected','error') AND c.sync_enabled=1 AND bb.start_at_utc<? AND bb.end_at_utc>? ORDER BY bb.start_at_utc LIMIT 1");$stmt->execute([$scheduleId,$endUtc,$startUtc]);return $stmt->fetch()?:null;
}
