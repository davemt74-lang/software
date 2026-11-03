<?php
declare(strict_types=1);

function vrss_fixture_post_v1(): string{return 'https://social.example/vrss/v1/posts/11111111-1111-4111-8111-111111111111';}
function vrss_fixture_comment_v1(): string{return 'https://writer.example/vrss/v1/comments/22222222-2222-4222-8222-222222222222';}

/** Existing canonical Feed and approval storage; safe to add to another endpoint fixture. */
function vrss_agent_fixture_v1(PDO $pdo): void
{
    $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$auto=$mysql?'INTEGER PRIMARY KEY AUTO_INCREMENT':'INTEGER PRIMARY KEY AUTOINCREMENT';$ignore=$mysql?'INSERT IGNORE':'INSERT OR IGNORE';
    $pdo->exec('CREATE TABLE IF NOT EXISTS vp3_system_app_catalog(id INTEGER PRIMARY KEY,app_key VARCHAR(80),name VARCHAR(160),category VARCHAR(80),description TEXT,current_version VARCHAR(80),acquisition_mode VARCHAR(30),required_entitlement VARCHAR(80),homeserver_catalog_key VARCHAR(80),is_active INTEGER,sort_order INTEGER,metadata_json TEXT)');
    $pdo->exec("$ignore INTO vp3_system_app_catalog VALUES(2,'vp3.feed','VP3 Feed','Tools','','1.0.0','included',NULL,'feed',1,2,'{}')");
    $pdo->exec('CREATE TABLE IF NOT EXISTS vp3_system_app_ownership(id INTEGER PRIMARY KEY,user_id INTEGER,app_id INTEGER,status VARCHAR(24),source_kind VARCHAR(40),acquired_at DATETIME,revoked_at DATETIME)');
    $pdo->exec("$ignore INTO vp3_system_app_ownership VALUES(2,1,2,'active','fixture','2020-01-01 00:00:00',NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_system_app_events(id $auto,user_id INTEGER,app_id INTEGER,event_type VARCHAR(80),actor_type VARCHAR(80),actor_key VARCHAR(190),metadata_json TEXT)");
    $pdo->exec('CREATE TABLE IF NOT EXISTS vp3_system_app_installations(id INTEGER PRIMARY KEY,user_id INTEGER,app_id INTEGER,observed_state VARCHAR(40),is_installed INTEGER,is_current INTEGER,update_available INTEGER,installed_version VARCHAR(80),homeserver_catalog_version VARCHAR(80),package_sha256 VARCHAR(64),last_remote_sync_at DATETIME,last_install_at DATETIME,last_error_message TEXT,last_error_code VARCHAR(80),remote_json TEXT,updated_at DATETIME)');
    $pdo->exec("$ignore INTO vp3_system_app_installations(id,user_id,app_id,observed_state,is_installed,is_current,update_available,installed_version,homeserver_catalog_version,last_remote_sync_at,last_error_message) VALUES(2,1,2,'running',1,1,0,'1.0.0','1.0.0','2020-01-01 00:00:00','SECRET stored diagnostic')");
    $pdo->exec('CREATE TABLE IF NOT EXISTS cloud_hosting_sites(id INTEGER PRIMARY KEY,user_id INTEGER,site_key VARCHAR(80),display_name VARCHAR(160),requested_hostname VARCHAR(190),canonical_hostname VARCHAR(190),desired_state VARCHAR(30),observed_state VARCHAR(30),desired_revision INTEGER,route_state VARCHAR(30),tls_state VARCHAR(30))');
    $pdo->exec('CREATE TABLE IF NOT EXISTS vp3_system_app_hosting_bindings(id INTEGER PRIMARY KEY,user_id INTEGER,app_id INTEGER,site_id INTEGER,status VARCHAR(24),bound_at DATETIME)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS homeserver_connections(user_id INTEGER PRIMARY KEY,status VARCHAR(30),last_seen_at DATETIME,relay_token_enc TEXT,homeserver_token_enc TEXT)');
    $pdo->exec("$ignore INTO homeserver_connections VALUES(1,'connected','2020-01-01 00:00:00','SECRET token','SECRET token')");
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_system_app_agent_actions(id $auto,public_id VARCHAR(64),user_id INTEGER,conversation_id INTEGER,app_id INTEGER,site_id INTEGER,action_type VARCHAR(64),payload_json TEXT,preview_json TEXT,confirmation_token_hash VARCHAR(64),idempotency_key VARCHAR(190),status VARCHAR(24),execution_token VARCHAR(64) DEFAULT '',execution_expires_at DATETIME,expires_at DATETIME,confirmed_at DATETIME,completed_at DATETIME,result_json TEXT,error_message VARCHAR(500) DEFAULT '',created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
}

/** Only the HomeServer transport is replaced; plans, authority, storage and receipts are real. */
function vrss_agent_remote_v1(int $uid,string $operation,array $payload=[]): array
{
    $GLOBALS['vrss_calls'][]=[$uid,$operation,$payload];
    $mode=$GLOBALS['vrss_mode']??($_SERVER['HTTP_X_FIXTURE_VRSS_MODE']??'success');
    if($operation!=='apps.control.invoke'||($payload['app_key']??'')!=='vp3.feed')throw new RuntimeException('Unexpected VRSS fixture target.');
    if($mode==='offline')throw new RuntimeException('SECRET remote failure /private/path?token=SECRET');
    if($mode==='denied')throw new RuntimeException('SECRET denied',403);
    if(isset($GLOBALS['vrss_during_remote']))($GLOBALS['vrss_during_remote'])();
    if($mode==='revoke')db()->exec('UPDATE users SET is_active=0 WHERE id=1');
    if($mode==='swap')db()->exec('UPDATE chat_conversations SET user_agent_id=8 WHERE id=1');
    $action=$payload['action']??'';$args=$payload['arguments']??[];$post=vrss_fixture_post_v1();$comment=vrss_fixture_comment_v1();
    $reads=['vrss.status','vrss.feed','vrss.social.status','vrss.social.reactions','vrss.social.reposts','vrss.social.comments','vrss.social.conversations','vrss.social.thread','vrss.social.comment_review'];
    $risk=in_array($action,$reads,true)?'read':($action==='vrss.refresh_all'?'background':'write');
    // Real HomeServer action shapes; a queued refresh is not a generic mutation acknowledgement.
    $mutation=['ok'=>true,'contract'=>'vp3.vrss.v1','viewer_id'=>'urn:uuid:aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa','replayed'=>false];
    $result=match($action){
        'vrss.status'=>['subscriptions'=>[['title'=>'Fixture Kitchen Journal','error'=>'','last_success_at'=>'2020-01-02T10:00:00Z'],['title'=>'Fixture Retry Source','error'=>'SECRET source failure','last_success_at'=>null]]],
        'vrss.feed'=>['feed'=>['items'=>[['title'=>'Fixture Desert News','body'=>'A real bounded post','private_path'=>'SECRET source file']]]],
        'vrss.social.status'=>['social'=>['followers'=>2,'following'=>1,'pending_deliveries'=>3,'deliveries'=>[['error'=>'SECRET delivery error']],'relations'=>[['name'=>'Fixture Publisher','state'=>'accepted','direction'=>'in','blocked'=>0,'actor'=>'https://writer.example/vrss/v1/actor']]]],
        'vrss.social.reactions'=>['reaction'=>['object'=>$args['url']??$post,'likes'=>2,'liked'=>true,'pending'=>false]],
        'vrss.social.reposts'=>['repost'=>['object'=>$args['url']??$post,'reposts'=>2,'reposted'=>true,'published'=>true,'pending'=>false]],
        'vrss.social.comments'=>['thread'=>['object'=>$args['url']??$post,'owner'=>true,'items'=>[['id'=>$comment,'name'=>'Fixture Writer','body'=>'Fixture public comment','state'=>'pending']],'next_cursor'=>'']],
        'vrss.social.conversations'=>['conversations'=>['items'=>[['object'=>$post,'enabled'=>1,'muted'=>0,'revision'=>7]]]],
        'vrss.social.thread'=>['thread'=>['object'=>$args['url']??$post,'owner'=>false,'items'=>[['id'=>$comment,'name'=>'Fixture Writer','body'=>'Fixture conversation reply','state'=>'published']],'next_cursor'=>'']],
        'vrss.social.comment_review'=>['comment'=>['id'=>$args['comment_id']??$comment,'name'=>'Fixture Writer','body'=>'Exact comment body for review','state'=>'pending','revision'=>3,'can_moderate'=>true]],
        'vrss.refresh_all'=>['queued'=>1],
        'vrss.social.like'=>$mutation+['reaction'=>['object'=>$args['url']??$post,'liked'=>$args['enabled']??true,'can_like'=>true,'pending'=>true,'likes'=>null,'state'=>'pending','checked_at'=>0]],
        'vrss.social.repost'=>$mutation+['repost'=>['object'=>$args['url']??$post,'reposted'=>$args['enabled']??true,'can_repost'=>true,'can_undo'=>true,'pending'=>true,'published'=>false,'reposts'=>null,'state'=>'pending','checked_at'=>0,'url'=>'']],
        'vrss.social.remove'=>$mutation+['state'=>'removed'],
        default=>throw new RuntimeException('Unexpected VRSS fixture action.'),
    };
    if($risk==='read'&&$action!=='vrss.status')$result+=['ok'=>true,'contract'=>'vp3.vrss.v1','viewer_id'=>'urn:uuid:aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'];
    if($mode==='empty'){
        if($action==='vrss.status')$result['subscriptions']=[];
        if($action==='vrss.feed')$result['feed']['items']=[];
        if($action==='vrss.social.conversations')$result['conversations']['items']=[];
        if(in_array($action,['vrss.social.comments','vrss.social.thread'],true))$result['thread']['items']=[];
        if($action==='vrss.social.status')$result['social']=['followers'=>0,'following'=>0,'pending_deliveries'=>0,'relations'=>[],'deliveries'=>[]];
        if($action==='vrss.social.reactions')$result['reaction']['likes']=0;
        if($action==='vrss.social.reposts')$result['repost']['reposts']=0;
    }
    if(in_array($mode,['missing_count','unavailable_count'],true)){
        if($action==='vrss.social.reactions'){if($mode==='missing_count')unset($result['reaction']['likes']);else $result['reaction']['likes']=null;}
        if($action==='vrss.social.reposts'){if($mode==='missing_count')unset($result['repost']['reposts']);else $result['repost']['reposts']=null;}
        if($action==='vrss.social.status')unset($result['social']['followers']);
    }
    if($mode==='missing_pending'&&$action==='vrss.social.status')unset($result['social']['pending_deliveries']);
    if($mode==='bad_row'){
        if($action==='vrss.status')$result['subscriptions'][0]['error']=true;
        if($action==='vrss.feed')$result['feed']['items'][0]['title']=[];
        if($action==='vrss.social.status')$result['social']['relations'][0]['blocked']='false';
        if($action==='vrss.social.reactions')$result['reaction']['liked']='true';
        if($action==='vrss.social.reposts')$result['repost']['published']=1;
        if(in_array($action,['vrss.social.comments','vrss.social.thread'],true))$result['thread']['items'][0]['body']=[];
        if($action==='vrss.social.conversations')$result['conversations']['items'][0]['revision']='7';
        if($action==='vrss.social.comment_review')$result['comment']['revision']='3';
    }
    if($mode==='identity')$result['object']='https://foreign.example/vrss/v1/posts/99999999-9999-4999-8999-999999999999';
    if($mode==='comment_identity')$result['comment']['id']='https://other.example/vrss/v1/comments/33333333-3333-4333-8333-333333333333';
    if($mode==='review_denied')$result['comment']['can_moderate']=false;
    if($mode==='conversation_duplicate')$result['conversations']['items'][]=$result['conversations']['items'][0];
    if($mode==='conversation_partial')$result['conversations']=['items'=>[],'has_more'=>true,'next_cursor'=>'8'];
    if($mode==='bad_cursor')$result['thread']['next_cursor']='SECRET<script>';
    if($mode==='pagination')$result['thread']['next_cursor']='17';
    if($mode==='public_private_state'){$result['thread']['owner']=false;$result['thread']['items'][0]['state']='hidden';}
    if($mode==='negative_count'){
        if($action==='vrss.social.status')$result['social']['followers']=-1;
        if($action==='vrss.social.reactions')$result['reaction']['likes']=-1;
        if($action==='vrss.social.reposts')$result['repost']['reposts']=-1;
    }
    if($mode==='missing_projection')$result=[];
    // The request has reached the transport; neither lost nor malformed evidence proves completion.
    if($mode==='lost_reply'&&!in_array($action,$reads,true))throw new RuntimeException('SECRET lost mutation response /private/path?token=SECRET');
    if($mode==='unverified'&&!in_array($action,$reads,true))$result=['ok'=>true,'unverified'=>true];
    if(isset($GLOBALS['vrss_result_transform']))$result=($GLOBALS['vrss_result_transform'])($result,$action,$args);
    $response=['app_key'=>'vp3.feed','contract'=>'vp3.app.agent-action-result.v1','action'=>$action,'risk'=>$risk,'result'=>$result,'private_path'=>'SECRET native path','token'=>'SECRET transport token'];
    if($mode==='wrong_app')$response['app_key']='foreign.app';
    if($mode==='wrong_action')$response['action']='vrss.delete';
    if($mode==='missing_result')unset($response['result']);
    if($mode==='bad_result')$response['result']='SECRET invalid result';
    return $response;
}
