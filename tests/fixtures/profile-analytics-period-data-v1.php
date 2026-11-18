<?php
declare(strict_types=1);

/**
 * Additive isolated test data. No provider, session reset or production permission
 * override. Legacy integration bootstraps stub transport permission functions;
 * these pure catalog seams supply the real closed roles/defaults, while the new
 * authority reader still proves every user/assignment/seed/grant with real SQL.
 */
if(!function_exists('user_roles')){
    function user_roles():array{return ['fan'=>'Fan','artist'=>'Artist','manager'=>'Manager','producer'=>'Producer','supervisor'=>'Supervisor','investor'=>'Investor','admin'=>'Admin'];}
}
if(!function_exists('valid_role')){
    function valid_role(string $role):bool{return array_key_exists($role,user_roles());}
}
if(!function_exists('permission_catalog')){
    function permission_catalog():array{return ['account.access'=>[],'chat.access'=>[],'profile_agent.access'=>[],'profile_chat.access'=>[]];}
}
if(!function_exists('personal_capability_catalog_v242')&&function_exists('personal_capability_has_v242')){
    function personal_capability_catalog_v242():array{return ['agent_brain.access'=>[],'personal_knowledge.access'=>[],'personal_knowledge.manage'=>[],'profile_agent.access'=>[],'profile_chat.access'=>[],'voice_profile.access'=>[]];}
    function personal_capability_default_roles_v242():array{$all=array_keys(user_roles());return ['agent_brain.access'=>$all,'personal_knowledge.access'=>$all,'personal_knowledge.manage'=>$all,'profile_agent.access'=>$all,'profile_chat.access'=>$all,'voice_profile.access'=>$all];}
    if(!defined('STONEFELLOW_PERSONAL_CAPABILITIES_SEED'))define('STONEFELLOW_PERSONAL_CAPABILITIES_SEED','personal_capabilities_seed_v242');
}

function profile_analytics_period_fixture_columns_v1(PDO $pdo,string $table): array
{
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')return array_column($pdo->query('SHOW COLUMNS FROM '.$table)->fetchAll(PDO::FETCH_ASSOC),'Field');
    return array_column($pdo->query('PRAGMA table_info('.$table.')')->fetchAll(PDO::FETCH_ASSOC),'name');
}
function profile_analytics_period_fixture_table_v1(PDO $pdo,string $table,array $columns): void
{
    $parts=[];foreach($columns as $name=>$type)$parts[]=$name.' '.$type;
    $pdo->exec('CREATE TABLE IF NOT EXISTS '.$table.' ('.implode(',',$parts).')'.($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' ENGINE=InnoDB':''));
    $existing=profile_analytics_period_fixture_columns_v1($pdo,$table);
    foreach($columns as $name=>$type)if($name!=='PRIMARY KEY'&&!in_array($name,$existing,true))$pdo->exec('ALTER TABLE '.$table.' ADD COLUMN '.$name.' '.$type);
}
function profile_analytics_period_fixture_row_v1(PDO $pdo,string $table,array $row,string $key='id'): void
{
    $columns=profile_analytics_period_fixture_columns_v1($pdo,$table);$row=array_intersect_key($row,array_flip($columns));
    $q=$pdo->prepare('SELECT '.$key.' FROM '.$table.' WHERE '.$key.'=?');$q->execute([$row[$key]]);$exists=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
    if($exists){$values=$row;unset($values[$key]);$set=implode(',',array_map(static fn(string $column):string=>$column.'=?',array_keys($values)));
        if($values)$pdo->prepare('UPDATE '.$table.' SET '.$set.' WHERE '.$key.'=?')->execute([...array_values($values),$row[$key]]);
    // Keep this explicit-column fixture insert outside the inherited PA3
    // adapter's exact legacy visit-upsert prefix. That adapter intentionally
    // translates the production five-value upsert, not arbitrary seeded IDs.
    }else{$pdo->prepare('INSERT INTO '.$table.'('.implode(',',array_keys($row)).') VALUES ('.implode(',',array_fill(0,count($row),'?')).')')->execute(array_values($row));}
}
function profile_analytics_period_fixture_hash_v1(array $row,array $keys):string
{
    $values=[];foreach($keys as $key)$values[$key]=(string)($row[$key]??'');
    return hash('sha256',json_encode($values,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
}
function profile_analytics_period_fixture_v1(PDO $pdo,int $owner=1,int $privateAgent=7,int $privateChat=11): array
{
    if($pdo->inTransaction())throw new RuntimeException('Analytics test data needs an isolated fixture transaction boundary.');
    $pk='BIGINT PRIMARY KEY';$text="VARCHAR(255) NOT NULL DEFAULT ''";$integer='BIGINT NOT NULL DEFAULT 0';$json='TEXT NULL';
    $schemas=[
        'users'=>['id'=>$pk,'display_name'=>$text,'email'=>$text,'role'=>"VARCHAR(40) NOT NULL DEFAULT 'fan'",'is_active'=>'INTEGER NOT NULL DEFAULT 1','password_hash'=>$text,'timezone'=>$text],
        'user_account_types'=>['user_id'=>'BIGINT NOT NULL','role'=>'VARCHAR(40) NOT NULL','assigned_explicitly_at'=>'DATETIME NULL','PRIMARY KEY'=>'(user_id,role)'],
        'permissions'=>['permission_key'=>'VARCHAR(190) PRIMARY KEY'],
        'role_permissions'=>['role'=>'VARCHAR(40) NOT NULL','permission_key'=>'VARCHAR(190) NOT NULL','PRIMARY KEY'=>'(role,permission_key)'],
        'settings'=>['setting_key'=>'VARCHAR(190) PRIMARY KEY','setting_value'=>'TEXT NULL'],
        'user_agents'=>['id'=>$pk,'owner_user_id'=>$integer,'is_active'=>'INTEGER NOT NULL DEFAULT 1','display_name'=>$text,'engine_key'=>$text],
        'chat_conversations'=>['id'=>$pk,'user_id'=>$integer,'user_agent_id'=>'BIGINT NULL','title'=>$text],
        'user_profiles'=>['user_id'=>$pk,'username'=>$text,'is_public'=>'INTEGER NOT NULL DEFAULT 1','profile_agent_enabled'=>'INTEGER NOT NULL DEFAULT 1','profile_agent_id'=>$integer,'share_visit_identity'=>'INTEGER NOT NULL DEFAULT 1'],
        'user_blocks'=>['blocker_user_id'=>'BIGINT NOT NULL','blocked_user_id'=>'BIGINT NOT NULL','PRIMARY KEY'=>'(blocker_user_id,blocked_user_id)'],
        'profile_visit_sessions'=>['id'=>$pk,'owner_user_id'=>$integer,'session_key'=>$text,'visitor_user_id'=>'BIGINT NULL','identity_disclosed'=>$integer],
        'profile_events'=>['id'=>$pk,'owner_user_id'=>$integer,'profile_session_id'=>'BIGINT NULL','visitor_user_id'=>'BIGINT NULL','profile_agent_id'=>'BIGINT NULL','event_type'=>"VARCHAR(64) NOT NULL DEFAULT ''",'metadata_json'=>$json,'created_at'=>'DATETIME NULL','priority'=>$integer,'dedupe_key'=>"VARCHAR(64) NULL"],
        'profile_agent_conversations'=>['id'=>$pk,'owner_user_id'=>$integer,'profile_agent_id'=>$integer,'profile_session_id'=>$integer,'visitor_user_id'=>'BIGINT NULL','status'=>"VARCHAR(24) NOT NULL DEFAULT 'open'",'started_at'=>'DATETIME NULL','last_message_at'=>'DATETIME NULL','last_summary'=>$text],
        'profile_agent_messages'=>['id'=>$pk,'conversation_id'=>$integer,'sender_type'=>"VARCHAR(12) NOT NULL DEFAULT 'visitor'",'sender_user_id'=>'BIGINT NULL','message'=>'TEXT NULL','context_json'=>$json,'created_at'=>'DATETIME NULL'],
        'profile_agent_handoff_turns_pa3'=>['id'=>$pk,'owner_user_id'=>$integer,'profile_agent_id'=>$integer,'profile_session_id'=>$integer,'conversation_id'=>$integer,'visitor_message_id'=>$integer,'visitor_user_id'=>$integer,'transport'=>"VARCHAR(20) NOT NULL DEFAULT 'native'",'credential_hash'=>$text,'client_request_id'=>$text,'request_hash'=>$text,'accepted_json'=>$json,'state'=>$text,'version'=>'INTEGER NOT NULL DEFAULT 1','conversation_epoch'=>'INTEGER NOT NULL DEFAULT 1','run_id'=>'BIGINT NULL','action_id'=>'BIGINT NULL','created_at'=>'DATETIME NULL'],
        'profile_agent_handoff_operations_pa3'=>['owner_user_id'=>$integer,'request_id'=>"VARCHAR(32) NOT NULL",'turn_id'=>$integer,'operation'=>$text,'request_hash'=>$text,'message_id'=>'BIGINT NULL','created_at_ms'=>$integer,'PRIMARY KEY'=>'(owner_user_id,request_id)'],
        'agent_workflow_runs'=>['id'=>$pk,'owner_user_id'=>$integer,'agent_id'=>$integer,'source_key'=>$text,'source_kind'=>$text,'workflow_type'=>$text],
        'agent_workflow_actions'=>['id'=>$pk,'owner_user_id'=>$integer,'run_id'=>$integer,'action_key'=>$text,'capability_key'=>$text],
        'agent_workflow_receipts'=>['id'=>$pk,'owner_user_id'=>$integer,'run_id'=>$integer,'action_id'=>$integer,'receipt_key'=>$text,'status'=>$text,'result_json'=>$json],
        'profile_calls_pa3c'=>['id'=>$pk,'owner_user_id'=>$integer,'profile_agent_id'=>$integer,'profile_session_id'=>$integer,'conversation_id'=>$integer,'visitor_user_id'=>$integer,
            'credential_hash'=>$text,'authority_json'=>$json,'binding_hash'=>$text,'public_id'=>$text,'room_name'=>$text,'owner_identity'=>$text,'visitor_identity'=>$text,
            'provider_snapshot'=>$json,'provider_config_fingerprint'=>$text,'mode'=>$text,'offered_by'=>$text,'created_at_ms'=>$integer,'accepted_at_ms'=>'BIGINT NULL','ended_at_ms'=>'BIGINT NULL',
            'state'=>$text,'assisted'=>$integer,'agent_name'=>$text,'agent_worker_name'=>$text],
        'agent_commerce_products_v800'=>['id'=>$pk,'owner_user_id'=>$integer,'product_key'=>$text,'title'=>$text,'price_cents'=>$integer,'currency'=>"VARCHAR(3) NOT NULL DEFAULT 'usd'"],
        'agent_commerce_provider_connections_v800'=>['id'=>$pk,'owner_user_id'=>$integer,'provider'=>$text,'external_account_id'=>$text],
        'agent_commerce_orders_v800'=>['id'=>$pk,'order_number'=>$text,'owner_user_id'=>$integer,'workspace_owner_user_id'=>'BIGINT NULL','connection_id'=>'BIGINT NULL','provider_snapshot'=>$text,'external_account_snapshot'=>$text,
            'currency'=>"VARCHAR(3) NOT NULL DEFAULT 'usd'",'total_cents'=>$integer,'amount_paid_cents'=>$integer,'amount_refunded_cents'=>$integer,'terms_snapshot_json'=>$json,'metadata_json'=>$json],
        'agent_commerce_order_items_v800'=>['id'=>$pk,'order_id'=>$integer,'product_id'=>'BIGINT NULL','quantity'=>'INTEGER NOT NULL DEFAULT 1','unit_price_cents'=>$integer,'total_cents'=>$integer,'terms_snapshot_json'=>$json,'metadata_json'=>$json],
        'agent_commerce_checkout_attempts_v800'=>['creation_request_json'=>$json,'idempotency_key'=>$text,'provider_account_id'=>$text,'provider_location_id'=>$text,'external_session_id'=>$text,'provider_order_id'=>$text,'id'=>$pk,'order_id'=>$integer,'connection_id'=>$integer,'provider'=>$text,'external_payment_id'=>$text,'status'=>$text,'amount_cents'=>$integer,'currency'=>"VARCHAR(3) NOT NULL DEFAULT 'usd'"],
        'agent_commerce_payments_v800'=>['id'=>$pk,'order_id'=>$integer,'checkout_attempt_id'=>'BIGINT NULL','provider'=>$text,'external_payment_id'=>$text,'status'=>$text,'amount_cents'=>$integer,'currency'=>"VARCHAR(3) NOT NULL DEFAULT 'usd'",'received_at'=>'DATETIME NULL'],
        'agent_commerce_refunds_v800'=>['id'=>$pk,'order_id'=>$integer,'payment_id'=>'BIGINT NULL','checkout_attempt_id'=>'BIGINT NULL','provider'=>$text,'external_refund_id'=>$text,'status'=>$text,'amount_cents'=>$integer,'completed_at'=>'DATETIME NULL'],
    ];
    foreach($schemas as $table=>$columns)profile_analytics_period_fixture_table_v1($pdo,$table,$columns);
    $q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$owner]);$existing=$q->fetch(PDO::FETCH_ASSOC);
    if(!$existing)profile_analytics_period_fixture_row_v1($pdo,'users',['id'=>$owner,'role'=>'fan','is_active'=>1,'display_name'=>'Analytics owner','email'=>'analytics-owner@example.invalid','password_hash'=>'fixture-owner-password','timezone'=>'UTC']);
    else $pdo->prepare("UPDATE users SET role='fan',timezone='UTC' WHERE id=?")->execute([$owner]);
    $visitor=98002;profile_analytics_period_fixture_row_v1($pdo,'users',['id'=>$visitor,'role'=>'fan','is_active'=>1,'display_name'=>'Analytics visitor','email'=>'analytics-visitor@example.invalid','password_hash'=>'fixture-visitor-password','timezone'=>'UTC']);
    // Preserve all capabilities needed by an inherited fixture's real native
    // flows while moving this owner to an actual canonical role.
    $copy=$pdo->query("SELECT permission_key FROM role_permissions WHERE role='member'")->fetchAll(PDO::FETCH_COLUMN);
    $grants=array_values(array_unique([...$copy,'account.access','chat.access','profile_agent.access','profile_chat.access']));
    foreach($grants as $permission){
        profile_analytics_period_fixture_row_v1($pdo,'permissions',['permission_key'=>$permission,'label'=>'Fixture','description'=>'Fixture permission','category'=>'Fixture','sort_order'=>1],'permission_key');
        $q=$pdo->prepare("SELECT 1 FROM role_permissions WHERE role='fan' AND permission_key=?");$q->execute([$permission]);
        if(!$q->fetchColumn())$pdo->prepare("INSERT INTO role_permissions(role,permission_key) VALUES('fan',?)")->execute([$permission]);
    }
    profile_analytics_period_fixture_row_v1($pdo,'settings',['setting_key'=>'personal_capabilities_seed_v242','setting_value'=>'1'],'setting_key');
    $publicAgent=$privateAgent>0?$privateAgent:99007;
    $q=$pdo->prepare('SELECT id FROM user_agents WHERE id=?');$q->execute([$publicAgent]);
    if(!$q->fetchColumn())profile_analytics_period_fixture_row_v1($pdo,'user_agents',['id'=>$publicAgent,'owner_user_id'=>$owner,'is_active'=>1,'display_name'=>'Analytics Agent','engine_key'=>'default','instructions'=>'','agent_role'=>'general']);
    $q=$pdo->prepare('SELECT id FROM chat_conversations WHERE id=?');$q->execute([$privateChat]);
    if(!$q->fetchColumn())profile_analytics_period_fixture_row_v1($pdo,'chat_conversations',['id'=>$privateChat,'user_id'=>$owner,'user_agent_id'=>$privateAgent?:null,'title'=>'Analytics private Chat']);
    $q=$pdo->prepare('SELECT username FROM user_profiles WHERE user_id=?');$q->execute([$owner]);$username=$q->fetchColumn();
    if(!is_string($username)||$username===''){$username='analytics-owner';profile_analytics_period_fixture_row_v1($pdo,'user_profiles',['user_id'=>$owner,'username'=>$username,'is_public'=>1,'profile_agent_enabled'=>1,'profile_agent_id'=>$publicAgent,'share_visit_identity'=>1],'user_id');}
    profile_analytics_period_fixture_row_v1($pdo,'user_profiles',['user_id'=>$visitor,'username'=>'analytics-visitor','share_visit_identity'=>1],'user_id');
    $cutoff=(new DateTimeImmutable('2026-11-17 00:00:00',new DateTimeZone('UTC')))->getTimestamp();
    $current=($cutoff-86400+36000)*1000;$previous=($cutoff-172800+39600)*1000;
    $jsonEncode=static fn(array $value):string=>json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $date=static fn(int $ms):string=>gmdate('Y-m-d H:i:s',intdiv($ms,1000));$cred=hash('sha256','analytics-native-browser');
    $session=['id'=>990001,'owner_user_id'=>$owner,'session_key'=>'analytics-native-session','visitor_user_id'=>$visitor,'identity_disclosed'=>1];
    profile_analytics_period_fixture_row_v1($pdo,'profile_visit_sessions',$session);
    require_once dirname(__DIR__,2).'/includes/profile-agent-accepted-turn-pa3.php';
    $q=$pdo->prepare('SELECT p.*,u.display_name,u.is_active FROM user_profiles p JOIN users u ON u.id=p.user_id WHERE p.user_id=?');
    $q->execute([$owner]);$acceptedProfile=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
    $q=$pdo->prepare('SELECT * FROM user_agents WHERE id=? AND owner_user_id=?');$q->execute([$publicAgent,$owner]);
    $acceptedAgent=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
    $ids=['visitor'=>$visitor,'session'=>990001,'current_conversation'=>991001,'previous_conversation'=>991002,'unanswered_conversation'=>991003,'current_turn'=>995001,'previous_turn'=>995002,'unanswered_turn'=>995003];
    foreach([[991001,995001,991101,$current,'owner'],[991002,995002,991201,$previous,'agent'],[991003,995003,991301,$current+18000000,'none']] as [$cid,$tid,$mid,$at,$reply]){
        $binding=['contract'=>'vp3.profile-conversation-authority.v1','transport'=>'native','owner_user_id'=>$owner,'visitor_user_id'=>$visitor,'credential_hash'=>$cred,'profile_agent_id'=>$publicAgent,'profile_session_id'=>990001,'response_token'=>str_repeat('c',32)];
        profile_analytics_period_fixture_row_v1($pdo,'profile_agent_conversations',['id'=>$cid,'owner_user_id'=>$owner,'profile_agent_id'=>$publicAgent,'profile_session_id'=>990001,'visitor_user_id'=>$visitor,'status'=>$reply==='none'?'open':'resolved','started_at'=>$date($at),'last_message_at'=>$date($at+30000),'last_summary'=>'Historical fixture conversation']);
        profile_analytics_period_fixture_row_v1($pdo,'profile_agent_messages',['id'=>$mid-1,'conversation_id'=>$cid,'sender_type'=>'system','sender_user_id'=>null,'message'=>'','context_json'=>$jsonEncode($binding),'created_at'=>$date($at)]);
        $statement='Exact historical visitor statement '.$cid;
        profile_analytics_period_fixture_row_v1($pdo,'profile_agent_messages',['id'=>$mid,'conversation_id'=>$cid,'sender_type'=>'visitor','sender_user_id'=>$visitor,'message'=>$statement,'context_json'=>'{}','created_at'=>$date($at)]);
        $run=$tid+1000;$action=$tid+2000;
        $snapshot=vp3_profile_agent_accepted_snapshot_pa3(['conversation_authority'=>['transport'=>'native'],
            'profile'=>$acceptedProfile,'agent'=>$acceptedAgent,'session'=>$session,'visitor'=>['id'=>$visitor],
            'accepted_visitor_auth_revision'=>hash('sha256',$visitor.'|fixture-visitor-password'),
            'accepted_message_sha256'=>hash('sha256',$statement)]);
        profile_analytics_period_fixture_row_v1($pdo,'agent_workflow_runs',['id'=>$run,'owner_user_id'=>$owner,'agent_id'=>$publicAgent,'workflow_type'=>'profile_visitor_reply','origin'=>'profile','source_kind'=>'profile_visitor_turn','source_key'=>'profile-turn:'.$tid,'source_hash'=>sha1('profile-turn:'.$tid),'dedupe_key'=>hash('sha256','profile-turn:'.$tid),'title'=>'Historical reply','goal'=>'Reply to exact fixture turn','decision_summary'=>'Exact source','status'=>'completed','risk_level'=>'low']);
        profile_analytics_period_fixture_row_v1($pdo,'agent_workflow_actions',['id'=>$action,'run_id'=>$run,'owner_user_id'=>$owner,'sequence_no'=>1,'action_key'=>'profile-turn:'.$tid,'action_type'=>'execute','label'=>'Historical reply','summary'=>'Exact source','status'=>'completed','capability_key'=>'profile.visitor.reply']);
        profile_analytics_period_fixture_row_v1($pdo,'profile_agent_handoff_turns_pa3',['id'=>$tid,'owner_user_id'=>$owner,'profile_agent_id'=>$publicAgent,'profile_session_id'=>990001,'conversation_id'=>$cid,'visitor_message_id'=>$mid,'visitor_user_id'=>$visitor,'transport'=>'native','credential_hash'=>$cred,'client_request_id'=>substr(hash('sha256','fixture-request-'.$tid),0,32),'request_hash'=>hash('sha256',$statement),'chat_mode'=>'agent','accepted_json'=>$jsonEncode($snapshot),'state'=>$reply==='none'?'awaiting_owner':'replied','version'=>2,'conversation_epoch'=>1,'run_id'=>$run,'action_id'=>$action,'created_at'=>$date($at),'updated_at'=>$date($at+30000)]);
        if($reply==='owner'){
            foreach([['claim',null,$at+5000],['reply',$mid+1,$at+15000]] as [$operation,$messageId,$operationAt]){
                $request=substr(hash('sha256',$tid.'|'.$operation),0,32);
                $pdo->prepare('DELETE FROM profile_agent_handoff_operations_pa3 WHERE owner_user_id=? AND request_id=?')->execute([$owner,$request]);
                $pdo->prepare('INSERT INTO profile_agent_handoff_operations_pa3(owner_user_id,request_id,turn_id,operation,request_hash,message_id,created_at_ms) VALUES(?,?,?,?,?,?,?)')->execute([$owner,$request,$tid,$operation,hash('sha256',$request),$messageId,$operationAt]);
                if($messageId)profile_analytics_period_fixture_row_v1($pdo,'profile_agent_messages',['id'=>$messageId,'conversation_id'=>$cid,'sender_type'=>'owner','sender_user_id'=>$owner,'message'=>'Reviewed historical owner reply','context_json'=>$jsonEncode(['profile_handoff'=>['contract'=>'vp3.profile-handoff.v1','turn_id'=>$tid,'owner_request_id'=>$request]]),'created_at'=>$date($operationAt)]);
            }
        }elseif($reply==='agent'){
            profile_analytics_period_fixture_row_v1($pdo,'profile_agent_messages',['id'=>$mid+1,'conversation_id'=>$cid,'sender_type'=>'agent','sender_user_id'=>null,'message'=>'Historical Agent reply','context_json'=>$jsonEncode(['profile_handoff'=>['contract'=>'vp3.profile-handoff.v1','turn_id'=>$tid,'visitor_message_id'=>$mid]]),'created_at'=>$date($at+30000)]);
            $receiptResult=agent_workflow_public_json_v1400($jsonEncode(['turn_id'=>$tid,'conversation_id'=>$cid,'visitor_message_id'=>$mid,'answer_message_id'=>$mid+1]));
            profile_analytics_period_fixture_row_v1($pdo,'agent_workflow_receipts',['id'=>998001,'run_id'=>$run,'action_id'=>$action,'owner_user_id'=>$owner,'receipt_key'=>hash('sha256',$owner.'|'.$run.'|'.$action.'|profile-turn:'.$tid.':reply'),'executor'=>'cloud','worker_id'=>'fixture','attempt_no'=>1,'status'=>'completed','summary'=>'Saved exact reply','result_json'=>$jsonEncode($receiptResult),'error_class'=>'','created_at'=>$date($at+30000)]);
        }
    }

    require_once dirname(__DIR__,2).'/includes/profile-calls-pa3c.php';
    foreach([[994001,991001,$current+7200000,12000,120000,1,'ended'],[994002,991002,$previous+7200000,20000,90000,0,'ended'],[994003,991003,$current+21600000,null,35000,0,'declined']] as [$id,$cid,$at,$delay,$end,$assisted,$state]){
        $public=substr(hash('sha256','analytics-call-'.$id),0,32);
        $authority=['contract'=>'vp3.profile-call-authority.v1','profile_fingerprint'=>hash('sha256','fixture-profile-call'),
            'agent_fingerprint'=>profile_analytics_period_fixture_hash_v1(['id'=>$publicAgent,'owner_user_id'=>$owner],['id','owner_user_id']),
            'session_fingerprint'=>profile_analytics_period_fixture_hash_v1($session,['id','owner_user_id','session_key']),
            'visitor_auth_revision'=>hash('sha256',$visitor.'|fixture-visitor-password')];
        $row=['id'=>$id,'owner_user_id'=>$owner,'profile_agent_id'=>$publicAgent,'profile_session_id'=>990001,'conversation_id'=>$cid,'visitor_user_id'=>$visitor,
            'credential_hash'=>$cred,'authority_json'=>$jsonEncode($authority),'public_id'=>$public,'room_name'=>'vp3-profile-'.$public,'owner_identity'=>'owner-'.$public,'visitor_identity'=>'visitor-'.$public,
            'provider_snapshot'=>'{"fixture":"no-provider-dispatch"}','provider_config_fingerprint'=>hash('sha256','fixture-provider'),'mode'=>'audio','offered_by'=>'owner',
            'assisted'=>$assisted,'agent_name'=>$assisted?'Historical assistant':'','agent_worker_name'=>$assisted?'fixture_worker':'',
            'created_at_ms'=>$at,'updated_at_ms'=>$at+$end,'offer_expires_at_ms'=>$at+60000,'accepted_at_ms'=>$delay===null?null:$at+$delay,'ended_at_ms'=>$at+$end,'state'=>$state];
        $row['binding_hash']=vp3_profile_call_binding_hash_pa3c($row);profile_analytics_period_fixture_row_v1($pdo,'profile_calls_pa3c',$row);
    }
    $ids['accepted_call']=994001;$ids['previous_call']=994002;$ids['declined_call']=994003;
    require_once dirname(__DIR__,2).'/includes/profile-event-clock-v1.php';
    vp3_profile_event_clock_install_v1($pdo);
    // Explicit isolated test provenance: place the completed fixture activation
    // before its fixed historical samples. The production installer never
    // rewrites activation; its own suite verifies the real application clock.
    $pdo->prepare('UPDATE profile_event_clock_activation_v1 SET activated_at_utc_ms=? WHERE id=1')->execute([($cutoff-4*86400)*1000]);
    profile_analytics_period_fixture_row_v1($pdo,'profile_visit_sessions',['id'=>990002,'owner_user_id'=>$owner,'session_key'=>'analytics-guest-session','visitor_user_id'=>null,'identity_disclosed'=>0]);
    foreach([[993001,'profile_view',$current,$visitor,990001],[993002,'profile_view',$current+1000,$visitor,990001],[993003,'profile_view',$current+2000,0,990002],
        [993004,'profile_view',$previous,$visitor,990001],[993005,'product_converted',$current+3000,0,990002],[993006,'booking_converted',$current+4000,0,990002],[993007,'product_converted',$previous+3000,0,990002]] as [$id,$type,$at,$who,$sid]){
        $proof=['contract'=>'vp3.profile-event-authority.v1','owner_user_id'=>$owner,'profile_agent_id'=>$publicAgent,'profile_session_id'=>$sid,'visitor_user_id'=>$who,
            'credential_hash'=>$who?$cred:hash('sha256','analytics-guest-browser'),'session_hash'=>hash('sha256',$sid===990001?'analytics-native-session':'analytics-guest-session'),
            'profile_username'=>$username,'visitor_auth_revision'=>$who?hash('sha256',$visitor.'|fixture-visitor-password'):'',
            'public_conversation_id'=>0,'accepted_message_id'=>0,'accepted_message_sha256'=>''];
        profile_analytics_period_fixture_row_v1($pdo,'profile_events',['id'=>$id,'owner_user_id'=>$owner,'profile_agent_id'=>$publicAgent,'profile_session_id'=>$sid,'visitor_user_id'=>$who?:null,
            'event_type'=>$type,'metadata_json'=>$jsonEncode(['source_authority'=>$proof]),'created_at'=>'2001-01-01 00:00:00',
            'priority'=>1,'dedupe_key'=>hash('sha256','analytics-event-'.$id),'recorded_at_utc_ms'=>$at,'clock_legacy_token_v1'=>null]);
    }
    $ids['event']=993001;$ids['guest_event']=993003;
    profile_analytics_period_fixture_row_v1($pdo,'agent_commerce_provider_connections_v800',['id'=>992001,'owner_user_id'=>$owner,'provider'=>'stripe','external_account_id'=>'fixture_analytics_merchant','status'=>'connected']);
    foreach([[992101,992201,3000,'usd'],[992102,992202,1500,'eur']] as [$pid,$oid,$price,$currency]){
        $terms=['product_id'=>$pid,'payment_mode'=>'full','price_cents'=>$price,'deposit_cents'=>0,'currency'=>$currency,'provider_mode'=>'fixed','fixed_connection_id'=>992001,
            'hold_minutes'=>30,'refund_before_hours'=>24,'cancellation_fee_cents'=>0,'cancellation_policy'=>'Fixture seller terms'];
        $digest=hash('sha256',$jsonEncode($terms));$terms+=['product_key'=>'profile:analytics-'.$pid,'product_type'=>'digital','fulfillment_type'=>'digital'];
        $metadata=['source'=>'profile_commerce_v900','profile_username'=>$username,'profile_product_slug'=>'analytics-'.$pid,
            'receipt_token_sha256'=>hash('sha256','receipt-'.$oid),'terms_accepted_at'=>gmdate('c',intdiv($previous-3600000,1000)),'terms_snapshot_sha256'=>$digest];
        profile_analytics_period_fixture_row_v1($pdo,'agent_commerce_products_v800',['id'=>$pid,'owner_user_id'=>$owner,'product_key'=>'profile:analytics-'.$pid,'title'=>'Fixture product '.$pid,
            'description'=>'Fixture listing','product_type'=>'digital','fulfillment_type'=>'digital','payment_mode'=>'full','price_cents'=>$price,'currency'=>$currency]);
        profile_analytics_period_fixture_row_v1($pdo,'agent_commerce_orders_v800',['id'=>$oid,'order_number'=>'ANALYTICS-'.$oid,'owner_user_id'=>$owner,'workspace_owner_user_id'=>null,'connection_id'=>992001,
            'provider_snapshot'=>'stripe','external_account_snapshot'=>'fixture_analytics_merchant','payment_mode'=>'full','order_status'=>'confirmed','payment_status'=>$oid===992201?'partially_refunded':'paid',
            'currency'=>$currency,'subtotal_cents'=>$price,'total_cents'=>$price,'amount_due_cents'=>0,'amount_paid_cents'=>$price,'amount_refunded_cents'=>$oid===992201?500:0,
            'terms_snapshot_json'=>$jsonEncode($terms),'metadata_json'=>$jsonEncode($metadata),'paid_at'=>$date($current+14400000),'fulfillment_type'=>'digital']);
        profile_analytics_period_fixture_row_v1($pdo,'agent_commerce_order_items_v800',['id'=>$oid+1000,'order_id'=>$oid,'product_id'=>$pid,'title_snapshot'=>'Fixture product '.$pid,'product_type_snapshot'=>'digital',
            'fulfillment_type'=>'digital','quantity'=>1,'unit_price_cents'=>$price,'total_cents'=>$price,'terms_snapshot_json'=>$jsonEncode($terms),'metadata_json'=>$jsonEncode($metadata)]);
    }
    foreach([[992401,992201,1000,'usd',$previous+3600000],[992402,992201,2000,'usd',$current+14400000],[992403,992202,1500,'eur',$current+14500000]] as [$pid,$oid,$amount,$currency,$at]){
        $aid=$pid+1000;$external='fixture_payment_'.$pid;
        profile_analytics_period_fixture_row_v1($pdo,'agent_commerce_checkout_attempts_v800',['id'=>$aid,'order_id'=>$oid,'connection_id'=>992001,'provider'=>'stripe','external_session_id'=>'fixture_session_'.$aid,
            'external_payment_id'=>$external,'provider_order_id'=>'fixture_session_'.$aid,'provider_account_id'=>'fixture_analytics_merchant','provider_location_id'=>'',
            'creation_request_json'=>$jsonEncode(['path'=>'/v1/checkout/sessions','form'=>true,'body'=>['mode'=>'payment','line_items'=>[['quantity'=>1,'price_data'=>['currency'=>$currency,'unit_amount'=>$amount,'product_data'=>['name'=>'Fixture frozen order']]]],
                'metadata'=>['vp3_commerce_order_id'=>(string)$oid],'payment_intent_data'=>['metadata'=>['vp3_commerce_order_id'=>(string)$oid]]]]),
            'status'=>'completed','amount_cents'=>$amount,'currency'=>$currency,'idempotency_key'=>hash('sha256','attempt-'.$aid),'completed_at'=>$date($at)]);
        profile_analytics_period_fixture_row_v1($pdo,'agent_commerce_payments_v800',['id'=>$pid,'order_id'=>$oid,'checkout_attempt_id'=>$aid,'provider'=>'stripe','external_payment_id'=>$external,
            'status'=>'completed','amount_cents'=>$amount,'currency'=>$currency,'received_at'=>$date($at)]);
    }
    profile_analytics_period_fixture_row_v1($pdo,'agent_commerce_refunds_v800',['id'=>992601,'order_id'=>992201,'payment_id'=>992402,'checkout_attempt_id'=>993402,'provider'=>'stripe',
        'external_refund_id'=>'fixture_refund_992601','status'=>'completed','amount_cents'=>500,'completed_at'=>$date($current+21600000)]);
    $ids['order']=992201;$ids['payment']=992402;$ids['refund']=992601;
    require_once dirname(__DIR__,2).'/includes/profile-analytics-period-ref-v1.php';
    $ref=vp3_profile_analytics_period_ref_v1(1,$cutoff,'UTC','explicit');
    $q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$owner]);$user=$q->fetch(PDO::FETCH_ASSOC);
    $scope=['user'=>$user,'agent_id'=>$privateAgent,'conversation_id'=>$privateChat,
        'principal'=>$privateAgent>0?['kind'=>'user_agent','agent_id'=>$privateAgent,'owner_user_id'=>$owner,'viewer_user_id'=>$owner]:['kind'=>'system','agent_id'=>0,'owner_user_id'=>0,'viewer_user_id'=>$owner]];
    if($privateAgent>0){$q=$pdo->prepare('SELECT * FROM user_agents WHERE id=?');$q->execute([$privateAgent]);$scope['agent']=$q->fetch(PDO::FETCH_ASSOC);}
    else $scope['agent']=null;
    return ['ref'=>$ref,'period_options'=>vp3_profile_analytics_period_decode_v1($ref)['period_options'],'scope'=>$scope,'cutoff'=>$cutoff,'timezone'=>'UTC','ids'=>$ids];
}
