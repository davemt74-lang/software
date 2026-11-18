<?php
declare(strict_types=1);

/** Real SQL and canonical Profile actions; only inherited external I/O is simulated. */
require_once __DIR__.'/profile-attention-pa4b.php';
require_once __DIR__.'/profile-specialist-data-v1.php';
require_once dirname(__DIR__,2).'/includes/agent-coordination-sources-v1.php';
require_once dirname(__DIR__,2).'/includes/agent-coordination-profile-v1.php';

function pfs_clock(): void
{
    $pdo=db();$stamp=static fn():string=>gmdate('Y-m-d H:i:s',intdiv(vp3_profile_attention_now_pa4b(),1000));
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$pdo->exec('SET timestamp='.intdiv(vp3_profile_attention_now_pa4b(),1000));
    else foreach(['NOW','UTC_TIMESTAMP','CURRENT_TIMESTAMP'] as $name)$pdo->sqliteCreateFunction($name,$stamp);
    $row=$pdo->query('SELECT NOW() event_clock,CURRENT_TIMESTAMP default_clock')->fetch(PDO::FETCH_ASSOC);
    if($row['event_clock']!==$stamp()||$row['default_clock']!==$stamp())throw new RuntimeException('Profile source fixture clocks disagree.');
}
function pfs_now(): DateTimeImmutable{return new DateTimeImmutable('@'.intdiv(vp3_profile_attention_now_pa4b(),1000));}
function pfs_advance(int $milliseconds): void{$GLOBALS['pa3Now']+=$milliseconds;pfs_clock();}
function pfs_scope(int $agent=7,int $chat=11): array{return vp3_agent_activity_scope_v1(db(),pa4b_owner(),$agent,$chat);}
function pfs_binding(int $agent=7,int $chat=11): array{return ['owner_user_id'=>1,'agent_id'=>$agent,'conversation_id'=>$chat,'enabled'=>false];}

function pfs_reset(): void
{
    pa4b_reset();pfs_clock();$pdo=db();
    foreach(['profile_call_assistance_pa3c','profile_call_research_pa3c','artist_transcript_sessions_v172'] as $table)if(table_exists($table))$pdo->exec('DELETE FROM '.$table);
    profile_specialist_fixture_schema_v1($pdo);
    $pdo->exec("INSERT INTO role_permissions(role,permission_key) VALUES('member','artist_listening.access')");
    profile_specialist_fixture_column_v1($pdo,'agent_memory_items','user_agent_id','INTEGER NULL');
    profile_specialist_fixture_column_v1($pdo,'agent_memory_items','memory_scope_version','INTEGER NOT NULL DEFAULT 410');
    foreach([['agent_memory_items','idx_agent_memory_agent_type_v410','memory_type'],['agent_memory_items','idx_agent_memory_agent_occurrence_v410','last_seen_at'],['agent_chat_archive','idx_agent_archive_agent_created_v410','created_at']] as [$table,$index,$column]){
        $q=$pdo->prepare($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?"SELECT 1 FROM sqlite_master WHERE type='index' AND name=?":'SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND index_name=?');$q->execute([$index]);
        $exists=(bool)$q->fetchColumn();$q->closeCursor();
        if(!$exists)$pdo->exec('CREATE INDEX '.$index.' ON '.$table.'(user_agent_id,'.$column.')');
    }
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
        $pdo->sqliteCreateFunction('DATABASE',static fn()=>'main');
        $databases=$pdo->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC);
        if(!in_array('information_schema',array_column($databases,'name'),true))$pdo->exec("ATTACH DATABASE ':memory:' AS information_schema");
        $pdo->exec('DROP TABLE IF EXISTS information_schema.statistics');
        $pdo->exec("CREATE TABLE information_schema.statistics AS SELECT 'main' table_schema,tbl_name table_name,name index_name FROM main.sqlite_master WHERE type='index'");
    }
    if(!vp3_agent_memory_scope_schema_ready_v410($pdo))throw new RuntimeException('Profile follow-up fixture memory scope is not ready.');
}

function pfs_need_owner(string $statement='Can you help with a product question?'): array
{
    $message=pa4b_message($statement);$ctx=$message['context'];$cid=$message['public_conversation_id'];
    profile_agent_needs_owner(db(),$ctx['profile'],$ctx['agent'],vp3_profile_agent_public_event_session_v1($ctx),vp3_profile_handoff_conversation_pa3(db(),1,$cid),$statement);
    $q=db()->prepare("SELECT n.id FROM notifications n JOIN profile_events e ON e.id=n.source_id AND e.owner_user_id=n.user_id WHERE n.user_id=1 AND n.source_type='profile_event' AND e.event_type='needs_owner' ORDER BY n.id DESC LIMIT 1");$q->execute();
    $source=vp3_profile_attention_source_pa4b(db(),pa4b_owner(),(int)$q->fetchColumn(),pa4b_scope());
    return ['source'=>$source,'notification'=>$source['notification'],'event'=>$source['event'],'conversation'=>$cid,
        'item'=>vp3_profile_attention_projection_pa4b(db(),$source,pa4b_scope())];
}

function pfs_read(?array $refs=null,int $agent=7,int $chat=11): array
{
    return pa4b_readonly(fn()=>vp3_coordination_profile_sources_v1(db(),pfs_scope($agent,$chat),pfs_binding($agent,$chat),$refs,pfs_now()));
}

function pfs_followup(int $id=74001,int $agent=7): array
{
    $offer=pa3c_offer('visitor',pa3c_context(2));$call=vp3_profile_call_row_pa3c(db(),(int)$offer['call_id']);
    db()->prepare("UPDATE profile_calls_pa3c SET state='ended',accepted_at_ms=?,ended_at_ms=?,assisted=1,agent_name='Fixture guide',agent_worker_name='fixture-profile-worker',revision=revision+1 WHERE id=?")
        ->execute([$GLOBALS['pa3Now'],$GLOBALS['pa3Now'],$call['id']]);
    $call=vp3_profile_call_row_pa3c(db(),(int)$call['id']);
    db()->prepare('UPDATE profile_calls_pa3c SET binding_hash=? WHERE id=?')->execute([vp3_profile_call_binding_hash_pa3c($call),$call['id']]);
    $session=$id+1;
    db()->prepare('INSERT INTO profile_call_assistance_pa3c(call_id,transcript_session_id) VALUES(?,?)')->execute([$call['public_id'],$session]);
    db()->prepare("INSERT INTO artist_transcript_sessions_v172(id,created_by_user_id,status,conversation_id,metadata_json) VALUES(?,1,'draft',NULL,?)")
        ->execute([$session,json_encode(['source'=>'profile_call_pa3c','profile_call_id'=>$call['public_id']],JSON_THROW_ON_ERROR)]);
    $meta=['source_kind'=>'transcription_intelligence','source_session_id'=>$session,'created_from_reviewed_item'=>true,
        'item_id'=>'reviewed-action-'.$id,'task_status'=>'open'];
    db()->prepare("INSERT INTO agent_memory_items(id,user_id,user_agent_id,is_active,memory_type,subject,memory_text,metadata_json) VALUES(?,1,?,1,'task','Send the reviewed product details','Reviewed task evidence',?)")
        ->execute([$id,$agent?:null,json_encode($meta,JSON_THROW_ON_ERROR)]);
    return ['task'=>$id,'session'=>$session,'call'=>(int)$call['id'],'public_id'=>$call['public_id']];
}
