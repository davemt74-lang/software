<?php
declare(strict_types=1);

/** Existing canonical Profile/scheduling tables only; no production helper replacements. */
function profile_webmcp_agent_fixture_v1(PDO $pdo,string $mode=''): void
{
    $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$ignore=$mysql?'INSERT IGNORE':'INSERT OR IGNORE';
    $ensureColumns=static function(string $table,array $definitions)use($pdo,$mysql):void{
        $existing=$mysql?$pdo->query('SHOW COLUMNS FROM '.$table)->fetchAll(PDO::FETCH_COLUMN):array_column($pdo->query('PRAGMA table_info('.$table.')')->fetchAll(PDO::FETCH_ASSOC),'name');
        foreach($definitions as $name=>$definition)if(!in_array($name,$existing,true))$pdo->exec('ALTER TABLE '.$table.' ADD COLUMN '.$name.' '.$definition);
    };
    $ensureColumns('users',['display_name'=>"VARCHAR(190) NOT NULL DEFAULT 'Fixture owner'",'email'=>"VARCHAR(190) NOT NULL DEFAULT 'fixture@example.invalid'",'role'=>"VARCHAR(30) NOT NULL DEFAULT 'member'",'avatar_path'=>"VARCHAR(500) NOT NULL DEFAULT ''"]);
    $ensureColumns('user_agents',['display_name'=>"VARCHAR(190) NOT NULL DEFAULT 'Fixture Agent'"]);
    $pdo->exec('CREATE TABLE IF NOT EXISTS user_profiles(user_id INTEGER PRIMARY KEY,username VARCHAR(80),is_public INTEGER,share_visit_identity INTEGER,profile_agent_id INTEGER,profile_agent_enabled INTEGER,profile_agent_greeting VARCHAR(500),profile_agent_instructions TEXT)');
    $q=$pdo->prepare("$ignore INTO user_profiles(user_id,username,is_public,share_visit_identity,profile_agent_id,profile_agent_enabled,profile_agent_greeting,profile_agent_instructions) VALUES(?,?,1,0,NULL,0,?,?)");
    foreach([[1,'fixture'],[2,'foreign-profile']] as [$uid,$name])$q->execute([$uid,$name,'SECRET private greeting','SECRET private instructions']);
    $pdo->exec('CREATE TABLE IF NOT EXISTS agent_scheduling_schedules(id INTEGER PRIMARY KEY,owner_user_id INTEGER,agent_id INTEGER,is_active INTEGER,public_enabled INTEGER,is_default INTEGER,timezone VARCHAR(80))');
    $pdo->exec('CREATE TABLE IF NOT EXISTS agent_scheduling_event_types(id INTEGER PRIMARY KEY,schedule_id INTEGER,is_active INTEGER,sort_order INTEGER,title VARCHAR(190))');
    $pdo->exec("$ignore INTO agent_scheduling_schedules(id,owner_user_id,agent_id,is_active,public_enabled,is_default,timezone) VALUES(501,1,7,1,1,1,'UTC'),(502,2,9,1,1,1,'UTC')");
    $pdo->exec("$ignore INTO agent_scheduling_event_types(id,schedule_id,is_active,sort_order,title) VALUES(511,501,1,0,'SECRET private event title'),(512,502,1,0,'SECRET foreign event title')");
    $pdo->exec('CREATE TABLE IF NOT EXISTS profile_webmcp_actions(id INTEGER PRIMARY KEY,owner_user_id INTEGER,profile_username VARCHAR(64),surface VARCHAR(24),property_id INTEGER,session_hash CHAR(64),intent_id CHAR(32),operation VARCHAR(80),payload_hash CHAR(64),idempotency_hash CHAR(64),state VARCHAR(24),result_type VARCHAR(40),result_id INTEGER,result_json TEXT,last_error_code VARCHAR(80),expires_at DATETIME,committed_at DATETIME,created_at DATETIME,updated_at DATETIME,UNIQUE(owner_user_id,intent_id),UNIQUE(owner_user_id,operation,idempotency_hash))');
    $pdo->exec('CREATE TABLE IF NOT EXISTS agent_scheduling_bookings(id INTEGER PRIMARY KEY,owner_user_id INTEGER)');
    $mode=$mode!==''?$mode:($_SERVER['HTTP_X_FIXTURE_PROFILE_MODE']??'');
    if($mode==='profile_private')$pdo->exec('UPDATE user_profiles SET is_public=0 WHERE user_id=1');
    if($mode==='profile_missing')$pdo->exec('DELETE FROM user_profiles WHERE user_id=1');
    if($mode==='profile_renamed')$pdo->exec("UPDATE user_profiles SET username='changed-profile' WHERE user_id=1");
    if($mode==='missing_schema')$pdo->exec('DROP TABLE user_profiles');
}

/** A test-only native prepare/commit receipt, correlated through the real server evidence path. */
function profile_webmcp_agent_result_fixture_v1(PDO $pdo,array $user,int $agentId,int $conversationId,string $mode='recorded'): array
{
    require_once dirname(__DIR__,2).'/includes/profile-webmcp-actions-v150.php';
    require_once dirname(__DIR__,2).'/includes/agent-profile-webmcp-read-v1.php';
    require_once dirname(__DIR__,2).'/includes/agent-profile-webmcp-outcomes-v1.php';
    $principal=['kind'=>$agentId>0?'user_agent':'system','owner_user_id'=>(int)$user['id'],'agent_id'=>$agentId];$query='Book an appointment through my profile';
    $handoff=vp3_profile_webmcp_handoff_v1($pdo,$user,$principal,['operation'=>'profile_webmcp_handoff','ids'=>['profile.webmcp.handoff'],'query'=>$query],['conversation_id'=>$conversationId],$query);
    if(($handoff['tool_receipt']['reason']??null)!=='confirmation_required')throw new RuntimeException('Profile fixture handoff unavailable.');
    $token=$handoff['profile_webmcp_plan']['handoff']['resume_token'];$resume=$_SESSION['vp3_profile_webmcp_resume_v194'][$token];$wire=['context_id'=>$resume['action_context_id'],'return_token'=>$resume['return_token']];
    $profile=profile_for_user($pdo,(int)$user['id'],false);$context=vp3_profile_webmcp_context_for_native_v1($pdo,$profile,$user,$wire);$now=time();
    if($mode==='unverified')return compact('handoff','token','wire','profile','context');
    $id=(int)$pdo->query('SELECT COALESCE(MAX(id),0)+1 FROM profile_webmcp_actions')->fetchColumn();$resultId=9000+$id;$intentId=bin2hex(random_bytes(16));
    $transport=['owner_user_id'=>(int)$user['id'],'profile_username'=>$profile['username'],'surface'=>'native_profile','property_id'=>0,'session_hash'=>hash('sha256','FixtureSource'.$wire['context_id'])];
    $intent=['event_type_id'=>511,'start_at_utc'=>'2030-01-01 10:00:00','guest_email'=>'SECRET@example.invalid','guest_notes'=>'SECRET private request'];$payloadHash=vp3_profile_webmcp_payload_hash_v150($intent);
    $q=$pdo->prepare('INSERT INTO profile_webmcp_actions(id,owner_user_id,profile_username,surface,property_id,session_hash,intent_id,operation,payload_hash,state,result_type,last_error_code,expires_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $q->execute([$id,(int)$user['id'],$profile['username'],'native_profile',null,$transport['session_hash'],$intentId,'booking.create',$payloadHash,'prepared','','',gmdate('Y-m-d H:i:s',$now+600),gmdate('Y-m-d H:i:s',$now),gmdate('Y-m-d H:i:s',$now)]);
    $prepare=['intent_id'=>$intentId,'intent'=>$intent,'confirmation_required'=>true,'expires_at_unix'=>$now+600];
    $patch=vp3_profile_webmcp_server_evidence_v1($pdo,$profile,$context,$transport,'vp3.booking.prepare',[],$prepare);$context=vp3_profile_webmcp_context_store_server_v1($pdo,$profile,$user,$context,$patch);
    if($mode!=='prepared'){
        $pdo->prepare('INSERT INTO agent_scheduling_bookings(id,owner_user_id) VALUES(?,?)')->execute([$resultId,(int)$user['id']]);
        $result=['booking'=>['booking_id'=>$resultId,'event_type_id'=>511,'event_title'=>'SECRET appointment label','start_at_utc'=>'2030-01-01 10:00:00','end_at_utc'=>'2030-01-01 11:00:00','organizer_timezone'=>'UTC','guest_timezone'=>'UTC','status'=>'confirmed','location_type'=>'virtual','guest_email'=>'SECRET@example.invalid'],'payment_required'=>false,'payment_status'=>'not_required'];
        $key='FixtureConfirm_'.$intentId;$idem=hash('sha256',(int)$user['id'].'|booking.create|'.$key);
        vp3_profile_webmcp_action_commit_v150($pdo,$id,$idem,'booking',$resultId,$result);
        if($mode!=='lost_reply'){
            $claims=['v'=>1]+$transport+['origin_hash'=>'','intent_id'=>$intentId,'operation'=>'booking.create','payload_hash'=>$payloadHash,'exp'=>$now+600];
            $encoded=rtrim(strtr(base64_encode(json_encode($claims)),'+/','-_'),'=');$confirmToken=$encoded.'.'.str_repeat('a',43);
            $patch=vp3_profile_webmcp_server_evidence_v1($pdo,$profile,$context,$transport,'vp3.booking.confirm',['intent'=>$intent,'confirmation_token'=>$confirmToken,'idempotency_key'=>$key],$result);
            $context=vp3_profile_webmcp_context_store_server_v1($pdo,$profile,$user,$context,$patch);
        }
    }
    return compact('handoff','token','wire','profile','context','id','resultId');
}

function profile_webmcp_agent_return_fixture_v1(PDO $pdo,array $user,int $agentId,int $conversationId,string $mode='recorded'): array
{
    $fixture=profile_webmcp_agent_result_fixture_v1($pdo,$user,$agentId,$conversationId,$mode);
    $public=vp3_profile_webmcp_return_consume_v195($fixture['profile'],$user,$fixture['wire']['return_token'],$pdo);
    if(!$public)throw new RuntimeException('Profile fixture return unavailable.');
    return $fixture+['public'=>$public];
}
