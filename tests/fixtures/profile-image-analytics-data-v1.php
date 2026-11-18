<?php
declare(strict_types=1);

/** Opt-in canonical free image history; the base analytics fixture stays empty. */
function profile_image_analytics_fixture_v1(PDO $pdo,array $fixture,?DateTimeImmutable $now=null): array
{
    require_once dirname(__DIR__,2).'/includes/profile-agent-accepted-turn-pa3.php';
    $owner=(int)$fixture['scope']['user']['id'];$agent=(int)$fixture['scope']['agent_id'];if($agent<1)$agent=99007;
    $visitor=(int)$fixture['ids']['visitor'];$sid=(int)$fixture['ids']['session'];$cutoff=(int)$fixture['cutoff'];
    $now??=new DateTimeImmutable('@'.($cutoff+86400+43200));
    $json=static fn(array $value):string=>json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $date=static fn(int $seconds):string=>gmdate('Y-m-d H:i:s',$seconds);
    $fetch=static function(string $sql,array $values)use($pdo):array{$q=$pdo->prepare($sql);$q->execute($values);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();return $row;};
    $profile=$fetch('SELECT p.*,u.display_name,u.is_active FROM user_profiles p JOIN users u ON u.id=p.user_id WHERE p.user_id=?',[$owner]);
    $agentRow=$fetch('SELECT * FROM user_agents WHERE id=?',[$agent]);$session=$fetch('SELECT * FROM profile_visit_sessions WHERE id=?',[$sid]);
    $credential=hash('sha256','analytics-native-browser');
    profile_analytics_period_fixture_row_v1($pdo,'profile_image_settings_v1',['owner_user_id'=>$owner,'enabled'=>1,'daily_limit'=>5,'visitor_daily_limit'=>3,'revision'=>1],'owner_user_id');
    $catalog=['snapshot_version'=>1,'owner_user_id'=>$owner,'collection_id'=>1080901,'catalog_name'=>'Soft watercolor','catalog_revision'=>1,'selection_asset_id'=>1080951,
        'instructions'=>'PRIVATE fixture style instruction','price_cents'=>0,
        'references'=>[['asset_id'=>1080951,'title'=>'Watercolor portrait','mime_type'=>'image/png','byte_size'=>1200,'sha256'=>hash('sha256','fixture-reference'),
            'revision'=>hash('sha256','fixture-reference-revision'),'width'=>512,'height'=>512]]];
    $second=$catalog;$second['collection_id']=1080902;$second['catalog_name']='Ink drawings';$second['selection_asset_id']=1080952;
    $second['references'][0]['asset_id']=1080952;$second['references'][0]['title']='Ink landscape';
    $today=(new DateTimeImmutable('@'.$now->getTimestamp()))->setTime(0,0)->getTimestamp()+60;
    $cases=[
        ['initial',1080001,1080101,1081001,$cutoff-86400+36000,'completed',0,$catalog],
        ['revision',1080001,1080103,1081002,$cutoff-86400+36120,'completed',1080102,$catalog],
        ['previous',1080002,1080201,1081003,$cutoff-172800+39600,'completed',0,$second],
        ['failure',1080003,1080301,1081004,$cutoff-86400+36240,'failed',0,$catalog],
        ['queued',1080004,1080401,1081005,$today,'queued',0,$catalog],
        ['cancelled',1080005,1080501,1081006,$today+1,'cancelled',0,$catalog],
    ];
    $out=['catalog_id'=>$catalog['collection_id'],'asset_id'=>$catalog['selection_asset_id'],'requests'=>[]];$conversations=[];
    foreach($cases as [$name,$cid,$mid,$tid,$at,$state,$base,$source]){
        $request=substr(hash('sha256','profile-image-analytics-'.$owner.'-'.$name),0,32);$run=$tid+10000;$action=$tid+20000;
        $prompt='PRIVATE exact visitor image request '.$name;$inputHash=hash('sha256',$base>0?'fixture-output-initial':'fixture-upload-'.$name);
        $outputHash=hash('sha256','fixture-output-'.$name);$root=$base>0?1080101:$mid;
        $intent=hash('sha256',json_encode([$source['collection_id'],$source['selection_asset_id'],1,$base,$base>0?null:$inputHash,$base>0?null:'visitor-photo.png',$base>0?null:'image/png'],JSON_THROW_ON_ERROR));
        $turnHash=hash('sha256',json_encode([$owner,$agent,$sid,'native',$credential,$prompt,'agent',['profile_image_edit'=>$intent]],JSON_THROW_ON_ERROR));
        $binding=['contract'=>'vp3.profile-conversation-authority.v1','transport'=>'native','owner_user_id'=>$owner,'visitor_user_id'=>$visitor,'credential_hash'=>$credential,
            'profile_agent_id'=>$agent,'profile_session_id'=>$sid,'response_token'=>str_repeat('d',32)];
        if(!isset($conversations[$cid])){
            profile_analytics_period_fixture_row_v1($pdo,'profile_agent_conversations',['id'=>$cid,'owner_user_id'=>$owner,'profile_agent_id'=>$agent,'profile_session_id'=>$sid,'visitor_user_id'=>$visitor,
                'status'=>'resolved','started_at'=>$date($at),'last_message_at'=>$date($at+30),'last_summary'=>'Image analytics fixture']);
            profile_analytics_period_fixture_row_v1($pdo,'profile_agent_messages',['id'=>$mid-1,'conversation_id'=>$cid,'sender_type'=>'system','sender_user_id'=>null,'message'=>'','context_json'=>$json($binding),'created_at'=>$date($at)]);
            $conversations[$cid]=true;
        }
        profile_analytics_period_fixture_row_v1($pdo,'profile_agent_messages',['id'=>$mid,'conversation_id'=>$cid,'sender_type'=>'visitor','sender_user_id'=>$visitor,'message'=>$prompt,'context_json'=>'{}','created_at'=>$date($at)]);
        $accepted=vp3_profile_agent_accepted_snapshot_pa3(['conversation_authority'=>['transport'=>'native'],'profile'=>$profile,'agent'=>$agentRow,'session'=>$session,
            'visitor'=>['id'=>$visitor],'accepted_visitor_auth_revision'=>hash('sha256',$visitor.'|fixture-visitor-password'),'accepted_message_sha256'=>hash('sha256',$prompt)]);
        profile_analytics_period_fixture_row_v1($pdo,'agent_workflow_runs',['id'=>$run,'owner_user_id'=>$owner,'agent_id'=>$agent,'workflow_type'=>'profile_visitor_reply','origin'=>'profile',
            'source_kind'=>'profile_visitor_turn','source_key'=>'profile-turn:'.$tid,'source_hash'=>sha1('profile-turn:'.$tid),'dedupe_key'=>hash('sha256','profile-turn:'.$tid),
            'title'=>'Image fixture reply','goal'=>'Reply to image request','decision_summary'=>'Exact request','status'=>$state==='queued'?'paused':'completed','risk_level'=>'low']);
        profile_analytics_period_fixture_row_v1($pdo,'agent_workflow_actions',['id'=>$action,'run_id'=>$run,'owner_user_id'=>$owner,'sequence_no'=>1,'action_key'=>'profile-turn:'.$tid,'action_type'=>'execute',
            'label'=>'Image fixture reply','summary'=>'Exact request','status'=>'completed','capability_key'=>'profile.visitor.reply']);
        profile_analytics_period_fixture_row_v1($pdo,'profile_agent_handoff_turns_pa3',['id'=>$tid,'owner_user_id'=>$owner,'profile_agent_id'=>$agent,'profile_session_id'=>$sid,'conversation_id'=>$cid,
            'visitor_message_id'=>$mid,'visitor_user_id'=>$visitor,'transport'=>'native','credential_hash'=>$credential,'client_request_id'=>substr(hash('sha256','image-fixture-'.$tid),0,32),
            'request_hash'=>$turnHash,'chat_mode'=>'agent','accepted_json'=>$json($accepted),'state'=>in_array($state,['completed','failed'],true)?'replied':($state==='queued'?'agent_ready':'cancelled'),
            'version'=>2,'conversation_epoch'=>1,'run_id'=>$run,'action_id'=>$action,'created_at'=>$date($at),'updated_at'=>$date($at+30)]);
        $config=['mode'=>'profile_style_reference','reference'=>['mime_type'=>'image/png','sha256'=>$inputHash,'byte_size'=>1200]];
        if($base>0)$config['reference']['generated_output']=['request_id'=>$out['initial']['request_id'],'output_sha256'=>$inputHash];
        profile_analytics_period_fixture_row_v1($pdo,'profile_image_edits_v1',['request_id'=>$request,'owner_user_id'=>$owner,'profile_agent_id'=>$agent,'conversation_id'=>$cid,'profile_session_id'=>$sid,
            'visitor_user_id'=>$visitor,'visitor_key'=>hash('sha256','member:'.$visitor),'visitor_message_id'=>$mid,'turn_id'=>$tid,'conversation_epoch'=>1,'root_message_id'=>$root,'base_message_id'=>$base,
            'request_hash'=>$intent,'prompt'=>$prompt,'input_name'=>$base>0?'edited-photo.png':'visitor-photo.png','input_mime'=>'image/png','input_bytes'=>1200,'input_sha256'=>$inputHash,'input_width'=>512,'input_height'=>512,
            'catalog_json'=>$json($source),'config_json'=>$json($config),'state'=>$state,'output_sha256'=>$state==='completed'?$outputHash:'','output_bytes'=>$state==='completed'?1600:0,
            'output_width'=>$state==='completed'?1024:0,'output_height'=>$state==='completed'?1024:0,'result_message_id'=>$state==='completed'?$mid+1:null,
            'error_code'=>$state==='failed'?'provider_rate_limited':'','created_at'=>$date($at),'completed_at'=>$state==='completed'?$date($at+30):null],'request_id');
        $receipt=$tid+30000;
        if(in_array($state,['completed','failed'],true)){
            $context=['answer_mode'=>'profile_image_edit','profile_image_request'=>$request,'profile_image_ready'=>$state==='completed','visitor_message_id'=>$mid,
                'profile_handoff'=>['contract'=>'vp3.profile-handoff.v1','turn_id'=>$tid,'visitor_message_id'=>$mid]];
            profile_analytics_period_fixture_row_v1($pdo,'profile_agent_messages',['id'=>$mid+1,'conversation_id'=>$cid,'sender_type'=>'agent','sender_user_id'=>null,'message'=>'Fixture saved image reply','context_json'=>$json($context),'created_at'=>$date($at+30)]);
            $result=agent_workflow_public_json_v1400($json(['turn_id'=>$tid,'conversation_id'=>$cid,'visitor_message_id'=>$mid,'answer_message_id'=>$mid+1]));
            profile_analytics_period_fixture_row_v1($pdo,'agent_workflow_receipts',['id'=>$receipt,'run_id'=>$run,'action_id'=>$action,'owner_user_id'=>$owner,
                'receipt_key'=>hash('sha256',$owner.'|'.$run.'|'.$action.'|profile-turn:'.$tid.':reply'),'executor'=>'cloud','worker_id'=>'fixture','attempt_no'=>1,'status'=>'completed',
                'summary'=>'Saved exact image reply','result_json'=>$json($result),'error_class'=>'','created_at'=>$date($at+30)]);
        }
        $out[$name]=['request_id'=>$request,'conversation_id'=>$cid,'visitor_message_id'=>$mid,'result_message_id'=>$mid+1,'turn_id'=>$tid,'receipt_id'=>$receipt,'created_at'=>$date($at)];
        $out['requests'][]=$request;
    }
    return $out;
}
