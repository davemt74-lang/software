<?php
declare(strict_types=1);

/** Data-only helpers: reuse an existing pipeline PDO and its real authorization functions. */
function profile_specialist_fixture_column_v1(PDO $pdo,string $table,string $column,string $definition): void
{
    try{$pdo->query('SELECT '.$column.' FROM '.$table.' WHERE 1=0');}
    catch(PDOException){$pdo->exec('ALTER TABLE '.$table.' ADD COLUMN '.$column.' '.$definition);}
}

function profile_specialist_fixture_schema_v1(PDO $pdo): void
{
    $auto=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
    foreach(['display_name'=>"VARCHAR(190) NOT NULL DEFAULT 'Fixture owner'",'role'=>"VARCHAR(40) NOT NULL DEFAULT 'member'",'password_hash'=>"VARCHAR(255) NOT NULL DEFAULT 'fixture-password'"] as $column=>$definition)
        profile_specialist_fixture_column_v1($pdo,'users',$column,$definition);
    $tables=[
        'user_profiles'=>"user_id INTEGER PRIMARY KEY,username VARCHAR(80),bio TEXT,is_public INTEGER,profile_agent_enabled INTEGER,profile_agent_id INTEGER,profile_agent_greeting TEXT,profile_agent_instructions TEXT,share_visit_identity INTEGER",
        'profile_visit_sessions'=>"id $auto,owner_user_id INTEGER,visitor_user_id INTEGER,session_key VARCHAR(64),identity_disclosed INTEGER DEFAULT 0,view_count INTEGER DEFAULT 0,first_seen_at DATETIME DEFAULT CURRENT_TIMESTAMP,last_seen_at DATETIME DEFAULT CURRENT_TIMESTAMP,last_message_at DATETIME,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,UNIQUE(owner_user_id,session_key)",
        'profile_agent_conversations'=>"id $auto,owner_user_id INTEGER,profile_agent_id INTEGER,profile_session_id INTEGER,visitor_user_id INTEGER,status VARCHAR(30) DEFAULT 'open',last_summary VARCHAR(1000) DEFAULT '',started_at DATETIME DEFAULT CURRENT_TIMESTAMP,last_message_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP",
        'profile_agent_messages'=>"id $auto,conversation_id INTEGER,sender_type VARCHAR(20),sender_user_id INTEGER,message TEXT,context_json TEXT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP",
        'profile_events'=>"id $auto,owner_user_id INTEGER,profile_session_id INTEGER,visitor_user_id INTEGER,profile_agent_id INTEGER,event_type VARCHAR(50),priority INTEGER,dedupe_key VARCHAR(64) UNIQUE,metadata_json TEXT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP",
        'agent_attention_items'=>"id $auto,owner_user_id INTEGER,source_event_id INTEGER UNIQUE,source_conversation_id INTEGER,profile_agent_id INTEGER,visitor_user_id INTEGER,attention_type VARCHAR(50),priority INTEGER,headline VARCHAR(190),summary TEXT,assessment TEXT,recommended_response TEXT,actions_json TEXT,context_json TEXT,status VARCHAR(20) DEFAULT 'pending',snoozed_until DATETIME,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP",
        'notifications'=>"id $auto,user_id INTEGER,type VARCHAR(80),title VARCHAR(190),body TEXT,target_url VARCHAR(500),source_type VARCHAR(80),source_id INTEGER,is_read INTEGER DEFAULT 0,read_at DATETIME NULL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP",
        'user_blocks'=>'blocker_user_id INTEGER,blocked_user_id INTEGER,PRIMARY KEY(blocker_user_id,blocked_user_id)',
        'artist_transcript_sessions_v172'=>'id INTEGER PRIMARY KEY,created_by_user_id INTEGER,status VARCHAR(20),conversation_id INTEGER,discarded_at DATETIME NULL,metadata_json TEXT',
    ];
    foreach($tables as $table=>$columns)$pdo->exec('CREATE TABLE IF NOT EXISTS '.$table.'('.$columns.')');
    profile_specialist_fixture_column_v1($pdo,'notifications','read_at','DATETIME NULL');
    require_once dirname(__DIR__,2).'/includes/profile-attention-pa4b.php';
    vp3_profile_attention_dependencies_pa4b();vp3_profile_handoff_ensure_schema_pa3($pdo);vp3_profile_attention_ensure_schema_pa4b($pdo);
    require_once dirname(__DIR__,2).'/includes/profile-calls-pa3c.php';vp3_profile_call_ensure_schema_pa3c($pdo);
    require_once dirname(__DIR__,2).'/includes/profile-call-activity-pa3c.php';vp3_profile_call_activity_ensure_schema_pa3c($pdo);
}

/** A saved native guest statement with an exact current owner reply and no pending presenter. */
function profile_specialist_fixture_settled_v1(PDO $pdo,int $owner=1,int $publicAgent=7,int $base=72000): array
{
    profile_specialist_fixture_schema_v1($pdo);
    $q=$pdo->prepare('SELECT user_id FROM user_profiles WHERE user_id=?');$q->execute([$owner]);
    if(!$q->fetchColumn())$pdo->prepare("INSERT INTO user_profiles(user_id,username,bio,is_public,profile_agent_enabled,profile_agent_id,profile_agent_greeting,profile_agent_instructions,share_visit_identity) VALUES(?,?,'',1,1,?,'','',0)")->execute([$owner,'profile-fixture-'.$owner,$publicAgent]);
    $q=$pdo->prepare('SELECT * FROM user_profiles WHERE user_id=?');$q->execute([$owner]);$profile=$q->fetch(PDO::FETCH_ASSOC);
    $session=$base+1;$conversation=$base+2;$visitorMessage=$base+3;$ownerMessage=$base+4;$event=$base+5;
    $key=hash('sha256','profile-fixture-session-'.$base);$credential=hash('sha256','profile-fixture-credential-'.$base);
    $pdo->prepare('INSERT INTO profile_visit_sessions(id,owner_user_id,session_key) VALUES(?,?,?)')->execute([$session,$owner,$key]);
    $pdo->prepare("INSERT INTO profile_agent_conversations(id,owner_user_id,profile_agent_id,profile_session_id,visitor_user_id,status) VALUES(?,?,?,?,NULL,'open')")->execute([$conversation,$owner,$publicAgent,$session]);
    $binding=['contract'=>'vp3.profile-conversation-authority.v1','transport'=>'native','owner_user_id'=>$owner,'visitor_user_id'=>0,'credential_hash'=>$credential,'profile_agent_id'=>$publicAgent,'profile_session_id'=>$session];
    $pdo->prepare("INSERT INTO profile_agent_messages(conversation_id,sender_type,message,context_json) VALUES(?,'system','',?)")->execute([$conversation,json_encode($binding,JSON_THROW_ON_ERROR)]);
    $statement='Could you confirm the public opening hours?';
    $pdo->prepare("INSERT INTO profile_agent_messages(id,conversation_id,sender_type,message) VALUES(?,?,'visitor',?)")->execute([$visitorMessage,$conversation,$statement]);
    $pdo->prepare("INSERT INTO profile_agent_messages(id,conversation_id,sender_type,sender_user_id,message) VALUES(?,?,'owner',?,'The public opening hours are confirmed.')")->execute([$ownerMessage,$conversation,$owner]);
    $proof=['contract'=>'vp3.profile-event-authority.v1','owner_user_id'=>$owner,'profile_agent_id'=>$publicAgent,'profile_session_id'=>$session,'visitor_user_id'=>0,
        'credential_hash'=>$credential,'session_hash'=>hash('sha256',$key),'profile_username'=>$profile['username'],'visitor_auth_revision'=>'',
        'public_conversation_id'=>$conversation,'accepted_message_id'=>$visitorMessage,'accepted_message_sha256'=>hash('sha256',$statement)];
    $metadata=['conversation_id'=>$conversation,'message_id'=>$ownerMessage,'sender_type'=>'owner','sender_user_id'=>$owner,'source_authority'=>$proof];
    $pdo->prepare("INSERT INTO profile_events(id,owner_user_id,profile_session_id,profile_agent_id,event_type,priority,metadata_json) VALUES(?,?,?,?,'message_sent',10,?)")->execute([$event,$owner,$session,$publicAgent,json_encode($metadata,JSON_THROW_ON_ERROR)]);
    return ['owner'=>$owner,'public_agent'=>$publicAgent,'session'=>$session,'conversation'=>$conversation,'visitor_message'=>$visitorMessage,'owner_message'=>$ownerMessage,'event'=>$event,'credential'=>$credential];
}
