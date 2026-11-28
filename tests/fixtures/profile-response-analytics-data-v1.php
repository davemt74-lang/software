<?php
declare(strict_types=1);

require_once dirname(__DIR__,2).'/includes/profile-agent-responses-v1.php';

/** Additive canonical native rows; no provider or permission stubs. */
function profile_response_analytics_request_fixture_v1(PDO $pdo,array $fixture,int $n,string $question,string $reason,int $created,
    string $status='waiting',?int $replied=null): array
{
    $base=1280000+$n*20;$cid=$base;$mid=$base+2;$tid=$base+10;$id=$base+15;$receipt=$base+3;
    $fetch=static function(string $table,int $id)use($pdo):array{$q=$pdo->prepare('SELECT * FROM '.$table.' WHERE id=?');$q->execute([$id]);return $q->fetch(PDO::FETCH_ASSOC);};
    $conversation=$fetch('profile_agent_conversations',$fixture['ids']['current_conversation']);$conversation['id']=$cid;
    $conversation['status']=$status==='resolved'?'resolved':'open';$conversation['started_at']=$conversation['last_message_at']=gmdate('Y-m-d H:i:s',intdiv($created,1000));
    profile_analytics_period_fixture_row_v1($pdo,'profile_agent_conversations',$conversation);
    $binding=$pdo->prepare("SELECT context_json FROM profile_agent_messages WHERE conversation_id=? AND sender_type='system'");$binding->execute([$fixture['ids']['current_conversation']]);$authority=$binding->fetchColumn();
    profile_analytics_period_fixture_row_v1($pdo,'profile_agent_messages',['id'=>$base+1,'conversation_id'=>$cid,'sender_type'=>'system','sender_user_id'=>null,'message'=>'','context_json'=>$authority,'created_at'=>gmdate('Y-m-d H:i:s',intdiv($created,1000))]);
    profile_analytics_period_fixture_row_v1($pdo,'profile_agent_messages',['id'=>$mid,'conversation_id'=>$cid,'sender_type'=>'visitor','sender_user_id'=>$conversation['visitor_user_id'],'message'=>$question,'context_json'=>'{}','created_at'=>gmdate('Y-m-d H:i:s',intdiv($created,1000))]);
    $turn=$fetch('profile_agent_handoff_turns_pa3',$fixture['ids']['current_turn']);$turn['id']=$tid;$turn['conversation_id']=$cid;$turn['visitor_message_id']=$mid;
    $turn['client_request_id']=substr(hash('sha256','response-test-'.$n),0,32);$turn['created_at']=gmdate('Y-m-d H:i:s',intdiv($created,1000));
    $turn['state']=$status==='answered'?'replied':($status==='waiting'?'owner_requested':'agent_ready');
    $accepted=json_decode($turn['accepted_json'],true,64,JSON_THROW_ON_ERROR);$accepted['message_sha256']=hash('sha256',$question);$turn['accepted_json']=json_encode($accepted,JSON_THROW_ON_ERROR);
    profile_analytics_period_fixture_row_v1($pdo,'profile_agent_handoff_turns_pa3',$turn);
    $replyId=$status==='answered'?$base+4:null;$settled=$status==='waiting'?null:($replied??$created+30000);
    $meta=['contract'=>VP3_PROFILE_RESPONSE_V1,'request_id'=>$id,'visitor_message_id'=>$mid,'revision'=>1,'reason'=>$reason,'submitted_at_ms'=>$created];
    profile_analytics_period_fixture_row_v1($pdo,'profile_agent_messages',['id'=>$receipt,'conversation_id'=>$cid,'sender_type'=>'receipt','sender_user_id'=>null,
        'message'=>'Submitted to owner. Waiting for a response.','context_json'=>json_encode($meta,JSON_THROW_ON_ERROR),'created_at'=>gmdate('Y-m-d H:i:s',intdiv($created,1000))]);
    profile_analytics_period_fixture_row_v1($pdo,'profile_agent_owner_requests_v1',['id'=>$id,'owner_user_id'=>1,'profile_agent_id'=>$conversation['profile_agent_id'],
        'conversation_id'=>$cid,'visitor_message_id'=>$mid,'reason'=>$reason,'status'=>$status,'receipt_message_id'=>$receipt,'reply_message_id'=>$replyId,
        'revision'=>$status==='waiting'?1:2,'created_at_ms'=>$created,'updated_at_ms'=>$settled??$created,'settled_at_ms'=>$settled]);
    if($replyId){
        $requestId=substr(hash('sha256','response-test-reply-'.$n),0,32);
        $pdo->prepare('DELETE FROM profile_agent_handoff_operations_pa3 WHERE owner_user_id=? AND request_id=?')->execute([1,$requestId]);
        $pdo->prepare('INSERT INTO profile_agent_handoff_operations_pa3(owner_user_id,request_id,turn_id,operation,request_hash,message_id,created_at_ms) VALUES(?,?,?,?,?,?,?)')
            ->execute([1,$requestId,$tid,'reply',hash('sha256',$requestId),$replyId,$replied]);
        profile_analytics_period_fixture_row_v1($pdo,'profile_agent_messages',['id'=>$replyId,'conversation_id'=>$cid,'sender_type'=>'owner','sender_user_id'=>1,
            'message'=>'The owner has answered this exact question.','context_json'=>json_encode(['profile_handoff'=>['contract'=>'vp3.profile-handoff.v1','turn_id'=>$tid,'owner_request_id'=>$requestId]],JSON_THROW_ON_ERROR),
            'created_at'=>gmdate('Y-m-d H:i:s',intdiv($replied,1000))]);
    }
    return ['id'=>$id,'conversation_id'=>$cid,'visitor_message_id'=>$mid,'receipt_message_id'=>$receipt,'reply_message_id'=>$replyId,'turn_id'=>$tid,'created_at_ms'=>$created];
}

function profile_response_analytics_fixture_v1(PDO $pdo,array $fixture,DateTimeImmutable $now): array
{
    vp3_profile_responses_install_v1($pdo);
    $at=($fixture['cutoff']-86400+3600)*1000;$previous=$at-86400000;
    $rows=[];
    $rows['gap_a']=profile_response_analytics_request_fixture_v1($pdo,$fixture,1,'What are the Aurora booking prices?','knowledge_gap',$at);
    $rows['gap_b']=profile_response_analytics_request_fixture_v1($pdo,$fixture,2,'WHAT ARE THE AURORA BOOKING PRICES!','knowledge_gap',$at+60000);
    $rows['answered']=profile_response_analytics_request_fixture_v1($pdo,$fixture,3,'How do I book an Aurora consultation?','knowledge_gap',$at+120000,'answered',$at+420000);
    $rows['human']=profile_response_analytics_request_fixture_v1($pdo,$fixture,4,'Can I book an Aurora consultation?','user_responses',$at+180000,'answered',$at+780000);
    $rows['service']=profile_response_analytics_request_fixture_v1($pdo,$fixture,5,'What are the Aurora booking prices?','service_unavailable',$at+240000);
    $rows['quota']=profile_response_analytics_request_fixture_v1($pdo,$fixture,6,'What are the Aurora booking prices?','quota_exhausted',$at+300000);
    $rows['delegated']=profile_response_analytics_request_fixture_v1($pdo,$fixture,7,'Is the Aurora consultation refundable?','knowledge_gap',$at+360000,'delegated');
    $rows['previous']=profile_response_analytics_request_fixture_v1($pdo,$fixture,8,'When is Aurora open?','user_responses',$previous,'answered',$previous+120000);
    $rows['carry']=profile_response_analytics_request_fixture_v1($pdo,$fixture,9,'Does Aurora offer weekend appointments?','user_responses',$previous+60000,'answered',$at+60000);
    $rows['current']=profile_response_analytics_request_fixture_v1($pdo,$fixture,10,'What are the Aurora booking prices?','knowledge_gap',$now->getTimestamp()*1000-600000);
    profile_analytics_period_fixture_table_v1($pdo,'knowledge_items',['knowledge_scope'=>"VARCHAR(20) NOT NULL DEFAULT 'personal'",'folder_id'=>'BIGINT NULL','file_type'=>"VARCHAR(40) NOT NULL DEFAULT 'personal_note'"]);
    profile_analytics_period_fixture_table_v1($pdo,'artist_transcript_folders_v177',['id'=>'BIGINT PRIMARY KEY','created_by_user_id'=>'BIGINT NOT NULL','folder_name'=>"VARCHAR(80) NOT NULL DEFAULT ''"]);
    profile_analytics_period_fixture_row_v1($pdo,'artist_transcript_folders_v177',['id'=>1289001,'created_by_user_id'=>1,'folder_name'=>'Booking information']);
    profile_analytics_period_fixture_row_v1($pdo,'knowledge_items',['id'=>1289101,'title'=>'Aurora booking prices','content_text'=>'Review the Aurora booking prices and consultation packages.','created_by_user_id'=>1,'knowledge_scope'=>'personal','file_type'=>'personal_note','folder_id'=>1289001,'visibility'=>'private','is_published'=>0]);
    profile_analytics_period_fixture_row_v1($pdo,'knowledge_items',['id'=>1289102,'title'=>'FOREIGN PRIVATE Aurora booking prices','content_text'=>'FOREIGN_PRIVATE_BODY','created_by_user_id'=>4,'knowledge_scope'=>'personal','file_type'=>'personal_note','folder_id'=>null,'visibility'=>'private','is_published'=>0]);
    profile_analytics_period_fixture_row_v1($pdo,'permissions',['permission_key'=>'personal_knowledge.access'],'permission_key');
    $pdo->exec("DELETE FROM role_permissions WHERE role='fan' AND permission_key='personal_knowledge.access'");
    $pdo->exec("INSERT INTO role_permissions(role,permission_key) VALUES('fan','personal_knowledge.access')");
    return $rows;
}
