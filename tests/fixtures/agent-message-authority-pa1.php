<?php
declare(strict_types=1);

function agent_message_fixture_put_pa1(PDO $pdo,string $table,array $row): void
{
    $q=$pdo->prepare('INSERT INTO '.$table.' ('.implode(',',array_keys($row)).') VALUES ('.implode(',',array_fill(0,count($row),'?')).')');$q->execute(array_values($row));
}

/** Isolated fixture storage; production messaging never creates these tables. */
function agent_message_fixture_pa1(PDO $pdo): array
{
    $id=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
    $schemas=[
        'users'=>'id INTEGER PRIMARY KEY,display_name VARCHAR(190),email VARCHAR(190),role VARCHAR(40),is_active INTEGER,avatar_path VARCHAR(500)',
        'user_profiles'=>'user_id INTEGER PRIMARY KEY,username VARCHAR(80),bio TEXT,is_public INTEGER,profile_agent_id INTEGER,profile_agent_enabled INTEGER,profile_agent_greeting VARCHAR(500),profile_agent_instructions TEXT',
        'user_agents'=>'id INTEGER PRIMARY KEY,owner_user_id INTEGER,display_name VARCHAR(190),is_active INTEGER,retired_at DATETIME NULL,instructions TEXT',
        'role_permissions'=>'role VARCHAR(40),permission_key VARCHAR(100)',
        'fixture_entitlements'=>'user_id INTEGER,capability VARCHAR(80),is_enabled INTEGER',
        'vp3_radar_properties'=>'id INTEGER PRIMARY KEY,owner_user_id INTEGER,property_type VARCHAR(20),is_active INTEGER,domain VARCHAR(190),label VARCHAR(190)',
        'vp3_agent_registry'=>'id INTEGER PRIMARY KEY,operator_name VARCHAR(120),agent_name VARCHAR(120),visitor_class VARCHAR(40),is_active INTEGER,user_agent_pattern VARCHAR(500)',
        'vp3_agent_contacts'=>'id INTEGER PRIMARY KEY,owner_user_id INTEGER,agent_registry_id INTEGER,display_name VARCHAR(190),operator_name VARCHAR(120),visitor_class VARCHAR(40),risk_score INTEGER,trust_score INTEGER',
        'vp3_agent_access_requests'=>'id INTEGER PRIMARY KEY,owner_user_id INTEGER,agent_contact_id INTEGER,property_id INTEGER,capability VARCHAR(80),status VARCHAR(24),request_token_hash CHAR(64),purpose VARCHAR(500),approved_until DATETIME NULL,last_used_at DATETIME NULL,use_count INTEGER,created_at DATETIME,updated_at DATETIME,UNIQUE(request_token_hash)',
        'vp3_agent_policies'=>'id INTEGER PRIMARY KEY,owner_user_id INTEGER,property_id INTEGER NULL,agent_contact_id INTEGER NULL,operator_name VARCHAR(120),visitor_class VARCHAR(40),path_pattern VARCHAR(500),action VARCHAR(20),priority INTEGER,is_active INTEGER,expires_at DATETIME NULL,metadata_json LONGTEXT',
        'vp3_radar_sessions'=>'id INTEGER PRIMARY KEY',
        'vp3_radar_events'=>'id '.$id.',owner_user_id INTEGER,property_id INTEGER,session_id INTEGER NULL,agent_contact_id INTEGER,event_type VARCHAR(80),severity VARCHAR(20),path VARCHAR(500),method VARCHAR(12),status_code INTEGER,significance_score INTEGER,risk_score INTEGER,summary VARCHAR(500),details_json LONGTEXT,occurred_at DATETIME',
        'knowledge_items'=>'id INTEGER PRIMARY KEY,title VARCHAR(190),description TEXT,content_text TEXT,visibility VARCHAR(24),is_published INTEGER,created_by_user_id INTEGER,updated_at DATETIME,knowledge_scope VARCHAR(24)',
        'knowledge_chunks'=>'id INTEGER PRIMARY KEY,knowledge_id INTEGER,chunk_index INTEGER,chunk_text TEXT',
        'user_data_policies'=>'owner_user_id INTEGER,resource_type VARCHAR(50),resource_id VARCHAR(100),audience_scope VARCHAR(24),owner_agents_allowed INTEGER,profile_agent_allowed INTEGER,stonefellow_shared INTEGER',
        'user_agent_data_rules'=>'agent_id INTEGER,resource_type VARCHAR(50),resource_id VARCHAR(100),access_mode VARCHAR(24)',
    ];
    foreach($schemas as $table=>$columns)$pdo->exec('CREATE TABLE '.$table.' ('.$columns.')');
    foreach([1,2] as $owner){
        agent_message_fixture_put_pa1($pdo,'users',['id'=>$owner,'display_name'=>'Owner '.$owner,'email'=>'private-owner-'.$owner.'@example.invalid','role'=>'member','is_active'=>1,'avatar_path'=>'']);
        agent_message_fixture_put_pa1($pdo,'user_profiles',['user_id'=>$owner,'username'=>$owner===1?'fixture':'other','bio'=>'Public fixture profile.','is_public'=>1,'profile_agent_id'=>$owner===1?7:9,'profile_agent_enabled'=>1,'profile_agent_greeting'=>'Hello from the public representative.','profile_agent_instructions'=>'Use approved public facts.']);
        agent_message_fixture_put_pa1($pdo,'fixture_entitlements',['user_id'=>$owner,'capability'=>'agent.messaging','is_enabled'=>1]);
        agent_message_fixture_put_pa1($pdo,'vp3_radar_properties',['id'=>10+$owner,'owner_user_id'=>$owner,'property_type'=>'native','is_active'=>1,'domain'=>'fixture.invalid','label'=>'VP3 Profile']);
    }
    foreach([7=>1,8=>1,9=>2] as $agent=>$owner)agent_message_fixture_put_pa1($pdo,'user_agents',['id'=>$agent,'owner_user_id'=>$owner,'display_name'=>'Profile Agent '.$agent,'is_active'=>1,'retired_at'=>null,'instructions'=>'PUBLIC Agent instructions.']);
    foreach(['profile_agent.access','profile_chat.access'] as $permission)agent_message_fixture_put_pa1($pdo,'role_permissions',['role'=>'member','permission_key'=>$permission]);
    foreach([31=>'ChatGPT-User',32=>'Claude-User'] as $registry=>$name)agent_message_fixture_put_pa1($pdo,'vp3_agent_registry',['id'=>$registry,'operator_name'=>'Fixture operator','agent_name'=>$name,'visitor_class'=>'ai_user_agent','is_active'=>1,'user_agent_pattern'=>$name]);
    foreach([41=>[1,31],42=>[2,31],43=>[1,32]] as $contact=>[$owner,$registry])agent_message_fixture_put_pa1($pdo,'vp3_agent_contacts',['id'=>$contact,'owner_user_id'=>$owner,'agent_registry_id'=>$registry,'display_name'=>'Aggregate Agent '.$registry,'operator_name'=>'Fixture operator','visitor_class'=>'ai_user_agent','risk_score'=>0,'trust_score'=>55]);
    $tokens=[51=>str_repeat('a',64),52=>str_repeat('b',64),53=>str_repeat('c',64),54=>str_repeat('d',64)];
    foreach($tokens as $id=>$token){$owner=$id===54?2:1;agent_message_fixture_put_pa1($pdo,'vp3_agent_access_requests',['id'=>$id,'owner_user_id'=>$owner,'agent_contact_id'=>$owner===1?41:42,'property_id'=>10+$owner,'capability'=>'agent.message','status'=>$id===53?'approved_once':'approved','request_token_hash'=>hash('sha256',$token),'purpose'=>'Fixture approved request '.$id,'approved_until'=>$id===53?gmdate('Y-m-d H:i:s',time()+1800):null,'last_used_at'=>null,'use_count'=>0,'created_at'=>gmdate('Y-m-d H:i:s',time()-3600),'updated_at'=>gmdate('Y-m-d H:i:s',time()-60)]);}
    foreach([101=>[1,'public',1,'Approved public brochure.'],102=>[1,'private',1,'SECRET private brochure.'],103=>[2,'public',1,'SECRET foreign brochure.']] as $id=>[$owner,$visibility,$published,$body])agent_message_fixture_put_pa1($pdo,'knowledge_items',['id'=>$id,'title'=>'Brochure '.$id,'description'=>'Fixture knowledge','content_text'=>$body,'visibility'=>$visibility,'is_published'=>$published,'created_by_user_id'=>$owner,'updated_at'=>gmdate('Y-m-d H:i:s'),'knowledge_scope'=>'personal']);
    agent_message_fixture_put_pa1($pdo,'user_data_policies',['owner_user_id'=>1,'resource_type'=>'knowledge','resource_id'=>'*','audience_scope'=>'inherit','owner_agents_allowed'=>1,'profile_agent_allowed'=>1,'stonefellow_shared'=>0]);
    return $tokens;
}
