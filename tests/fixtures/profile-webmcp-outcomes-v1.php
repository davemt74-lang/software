<?php
declare(strict_types=1);

function profile_webmcp_outcome_fixture_put_v1(PDO $pdo,string $table,array $row): void
{
    $q=$pdo->prepare('INSERT INTO '.$table.' ('.implode(',',array_keys($row)).') VALUES ('.implode(',',array_fill(0,count($row),'?')).')');$q->execute(array_values($row));
}

/** Isolated test schema only. Production outcome readers never create or initialize tables. */
function profile_webmcp_outcome_fixture_v1(PDO $pdo): array
{
    $schemas=[
        'profile_webmcp_actions'=>($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY':'id INTEGER PRIMARY KEY').',owner_user_id INTEGER,profile_username VARCHAR(64),surface VARCHAR(24),property_id INTEGER,session_hash CHAR(64),intent_id CHAR(32),operation VARCHAR(80),payload_hash CHAR(64),idempotency_hash CHAR(64),state VARCHAR(24),result_type VARCHAR(40),result_id INTEGER,result_json TEXT,last_error_code VARCHAR(80),expires_at DATETIME,committed_at DATETIME,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,UNIQUE(owner_user_id,intent_id),UNIQUE(owner_user_id,operation,idempotency_hash)',
        'agent_scheduling_bookings'=>'id INTEGER PRIMARY KEY,owner_user_id INTEGER',
        'agent_commerce_orders_v800'=>'id INTEGER PRIMARY KEY,owner_user_id INTEGER,order_number VARCHAR(80),metadata_json TEXT',
        'merchant_accounts'=>'id INTEGER PRIMARY KEY,owner_user_id INTEGER',
        'campaigns'=>'id INTEGER PRIMARY KEY,merchant_id INTEGER,public_id CHAR(36)',
        'campaign_enrollments'=>'id INTEGER PRIMARY KEY,campaign_id INTEGER',
        'crm_contacts'=>'id INTEGER PRIMARY KEY,vp3_user_id INTEGER',
        'reward_issuances'=>'id INTEGER PRIMARY KEY,public_id CHAR(36),recipient_contact_id INTEGER,recipient_user_id INTEGER',
        'reward_transfers'=>'id INTEGER PRIMARY KEY,public_id CHAR(36),reward_issuance_id INTEGER,sent_by_user_id INTEGER,delivery_mode VARCHAR(30),status VARCHAR(20)',
    ];
    foreach($schemas as $table=>$columns)$pdo->exec('CREATE TABLE IF NOT EXISTS '.$table.' ('.$columns.')');
    $cases=[];$i=0;$now=time();
    foreach(vp3_profile_webmcp_outcome_operations_v1() as $operation=>$spec){
        $i++;$resultId=100+$i;$intentId=substr(hash('sha256','fixture-'.$operation),0,32);$intent=['fixture_operation'=>$operation,'target'=>$resultId,'private_note'=>'SECRET not for Chat'];$idemKey='FixtureConfirm_'.$i;
        $profile=['user_id'=>1,'username'=>'fixture'];$transport=['owner_user_id'=>1,'profile_username'=>'fixture','surface'=>'native_profile','property_id'=>0,'session_hash'=>hash('sha256','FixtureNativeSession')];
        $context=['context_id'=>str_pad(dechex($i),32,'a',STR_PAD_LEFT),'profile_user_id'=>1,'profile_username'=>'fixture','created_at'=>$now-30,'updated_at'=>$now-30,'expires_at'=>$now+3570,'returned_at'=>0,'allowed_tools'=>[$spec[0],$spec[1]],'binding'=>['contract'=>'vp3.webmcp.owner-context.v1','user_id'=>1,'agent_id'=>7,'conversation_id'=>1]];
        $action=['id'=>$i,'owner_user_id'=>1,'profile_username'=>'fixture','surface'=>'native_profile','property_id'=>null,'session_hash'=>$transport['session_hash'],'intent_id'=>$intentId,'operation'=>$operation,'payload_hash'=>vp3_profile_webmcp_payload_hash_v150($intent),'idempotency_hash'=>null,'state'=>'prepared','result_type'=>'','result_id'=>null,'result_json'=>null,'last_error_code'=>'','expires_at'=>gmdate('Y-m-d H:i:s',$now+600),'committed_at'=>null,'created_at'=>gmdate('Y-m-d H:i:s',$now-5),'updated_at'=>gmdate('Y-m-d H:i:s',$now-5)];
        profile_webmcp_outcome_fixture_put_v1($pdo,'profile_webmcp_actions',$action);
        $uuid=sprintf('00000000-0000-4000-8000-%012d',$resultId);$result=[];
        if($spec[2]==='booking'){
            profile_webmcp_outcome_fixture_put_v1($pdo,'agent_scheduling_bookings',['id'=>$resultId,'owner_user_id'=>1]);
            $result=['booking'=>['booking_id'=>$resultId,'event_type_id'=>50,'event_title'=>'Fixture appointment','start_at_utc'=>'2030-01-01 10:00:00','end_at_utc'=>'2030-01-01 11:00:00','organizer_timezone'=>'UTC','guest_timezone'=>'UTC','status'=>$operation==='booking.cancel'?'cancelled':'confirmed','location_type'=>'virtual','guest_name'=>'SECRET Guest','guest_email'=>'SECRET@example.invalid','public_token'=>'SECRET_BOOKING_TOKEN'],'payment_required'=>true,'payment_status'=>'awaiting_payment','manage_url'=>'https://private.invalid/SECRET','manage_token'=>'SECRET_MANAGE_TOKEN'];
        }elseif($spec[2]==='order'){
            $result=['order_number'=>'FixtureOrder'.$resultId];
            profile_webmcp_outcome_fixture_put_v1($pdo,'agent_commerce_orders_v800',['id'=>$resultId,'owner_user_id'=>1,'order_number'=>$result['order_number'],'metadata_json'=>json_encode(['receipt_token_sha256'=>'SECRET','customer_refund_request'=>['status'=>'pending','reason'=>'SECRET private request']])]);
        }elseif($spec[2]==='campaign_enrollment'){
            profile_webmcp_outcome_fixture_put_v1($pdo,'merchant_accounts',['id'=>$resultId,'owner_user_id'=>1]);profile_webmcp_outcome_fixture_put_v1($pdo,'campaigns',['id'=>$resultId,'merchant_id'=>$resultId,'public_id'=>$uuid]);profile_webmcp_outcome_fixture_put_v1($pdo,'campaign_enrollments',['id'=>$resultId,'campaign_id'=>$resultId]);
            $result=['campaign'=>['public_id'=>$uuid,'slug'=>'fixture','title'=>'Fixture Campaign','campaign_type'=>'review'],'participation'=>['status'=>'enrolled','created_at'=>gmdate('Y-m-d H:i:s',$now-5),'updated_at'=>gmdate('Y-m-d H:i:s',$now-5),'completed_at'=>null],'lifecycle'=>['state'=>'awaiting_verification','public_action'=>'participate','reward_timing'=>'after_verification','verification_pending'=>true,'trigger_pending'=>false],'participation_reference'=>$intentId,'recipient_email'=>'SECRET@example.invalid'];
        }else{
            profile_webmcp_outcome_fixture_put_v1($pdo,'crm_contacts',['id'=>$resultId,'vp3_user_id'=>1]);profile_webmcp_outcome_fixture_put_v1($pdo,'reward_issuances',['id'=>$resultId,'public_id'=>$uuid,'recipient_contact_id'=>$resultId,'recipient_user_id'=>1]);
            if($spec[2]==='reward_claim_handoff')$result=['reward_public_id'=>$uuid,'claim_handoff_url'=>url('/reward-inbox.php?claim='.$uuid),'redemption_requires_merchant_operator'=>true,'credential_exposed'=>false,'reward_redeemed'=>false,'private_credential'=>'SECRET_REWARD_CREDENTIAL'];
            else{$transfer=sprintf('10000000-0000-4000-8000-%012d',$resultId);profile_webmcp_outcome_fixture_put_v1($pdo,'reward_transfers',['id'=>$resultId,'public_id'=>$transfer,'reward_issuance_id'=>$resultId,'sent_by_user_id'=>1,'delivery_mode'=>'vp3_inbox','status'=>'sent']);$result=['reward_public_id'=>$uuid,'transfer_public_id'=>$transfer,'recipient'=>['public_id'=>'SECRET_CONTACT','name'=>'SECRET Contact','email'=>'SECRET@example.invalid'],'delivery_mode'=>'vp3_inbox','status'=>'sent'];}
        }
        $claims=['v'=>1,'owner_user_id'=>1,'profile_username'=>'fixture','surface'=>'native_profile','property_id'=>0,'session_hash'=>$transport['session_hash'],'origin_hash'=>'','intent_id'=>$intentId,'operation'=>$operation,'payload_hash'=>$action['payload_hash'],'exp'=>$now+600];
        $encoded=rtrim(strtr(base64_encode(json_encode($claims)),'+/','-_'),'=');$token=$encoded.'.'.rtrim(strtr(base64_encode(hash_hmac('sha256',$encoded,'fixture-native-secret',true)),'+/','-_'),'=');
        $prepare=['intent_id'=>$intentId,'intent'=>$intent,'confirmation_required'=>true,'confirmation_token'=>$token,'expires_at_unix'=>$now+600];
        $args=['intent'=>$intent,'confirmation_token'=>$token,'idempotency_key'=>$idemKey];
        $cases[$operation]=compact('profile','transport','context','action','prepare','args','result','resultId','spec');
    }
    return $cases;
}
