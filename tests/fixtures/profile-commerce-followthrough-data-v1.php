<?php
declare(strict_types=1);

/** Additive SQL-only data. External I/O and runtime globals belong to the caller fixture. */
require_once __DIR__.'/profile-analytics-period-data-v1.php';
require_once dirname(__DIR__,2).'/includes/profile-commerce-followthrough-ref-v1.php';
require_once dirname(__DIR__,2).'/includes/profile-promotion-capture-v1.php';

function profile_commerce_followthrough_fixture_v1(PDO $pdo,int $owner=1,int $privateAgent=7,int $privateChat=11): array
{
    $base=profile_analytics_period_fixture_v1($pdo,$owner,$privateAgent,$privateChat);
    $publicAgent=$privateAgent>0?$privateAgent:99007;
    $integer='BIGINT NOT NULL DEFAULT 0';$text="VARCHAR(255) NOT NULL DEFAULT ''";$json='TEXT NULL';
    $tables=[
        'user_media_assets'=>['id'=>'BIGINT PRIMARY KEY','user_id'=>$integer,'media_type'=>$text,'mime_type'=>$text,'source'=>$text,'title'=>$text,'file_path'=>$text,
            'metadata_json'=>$json,'created_at'=>'DATETIME NULL','updated_at'=>'DATETIME NULL'],
        'agent_commerce_marketing_drafts_v1'=>['product_id'=>'BIGINT NOT NULL','owner_user_id'=>$integer,'request_key'=>'CHAR(64) NOT NULL','draft_json'=>'TEXT NOT NULL','created_at'=>'DATETIME NULL','PRIMARY KEY'=>'(product_id,request_key)'],
        'agent_commerce_promotions_v1'=>['id'=>'CHAR(32) PRIMARY KEY','owner_user_id'=>$integer,'product_id'=>$integer,'agent_id'=>$integer,'conversation_id'=>$integer,
            'source_asset_id'=>$integer,'source_sha256'=>'CHAR(64) NOT NULL','draft_key'=>'CHAR(64) NOT NULL','settings_json'=>'TEXT NOT NULL','generation_id'=>'VARCHAR(36) NULL',
            'asset_id'=>'BIGINT NULL','asset_sha256'=>'CHAR(64) NULL','public_key'=>'CHAR(32) NULL','public_product_url'=>$text,'public_cta'=>$text,'publication_revision'=>$integer,'status'=>$text,'created_at'=>'DATETIME NULL','updated_at'=>'DATETIME NULL'],
        'agent_promotion_publications_v1'=>['publication_id'=>'CHAR(32) PRIMARY KEY','promotion_id'=>'CHAR(32) NOT NULL','owner_user_id'=>$integer,'product_id'=>$integer,
            'agent_id'=>$integer,'conversation_id'=>$integer,'source_asset_id'=>$integer,'draft_key'=>'CHAR(64) NOT NULL','campaign_id'=>'BIGINT NULL','review_request'=>'CHAR(64) NULL','created_at'=>'DATETIME NULL','next_review_at'=>'DATETIME NULL','last_review_fingerprint'=>"CHAR(64) NOT NULL DEFAULT ''"],
        'agent_promotion_clicks_v1'=>['click_id'=>'CHAR(32) PRIMARY KEY','publication_id'=>'CHAR(32) NOT NULL','placement'=>'VARCHAR(32) NOT NULL','created_at'=>'DATETIME NOT NULL'],
        'agent_promotion_orders_v1'=>['order_id'=>'BIGINT PRIMARY KEY','click_id'=>'CHAR(32) NOT NULL'],
        'agent_product_launches_v1'=>['product_id'=>'BIGINT PRIMARY KEY','owner_user_id'=>$integer,'agent_id'=>$integer,'conversation_id'=>$integer,'draft_key'=>'CHAR(64) NULL','creative_id'=>'CHAR(32) NULL','last_stage'=>"VARCHAR(24) NOT NULL DEFAULT ''",'last_notice_key'=>"CHAR(64) NOT NULL DEFAULT ''",'next_check_at'=>'DATETIME NULL'],
    ];
    foreach($tables as $table=>$columns)profile_analytics_period_fixture_table_v1($pdo,$table,$columns);
    profile_promotion_capture_install_v1($pdo);
    $date=static fn(int $stamp):string=>gmdate('Y-m-d H:i:s',intdiv($stamp,1000));
    $jsonEncode=static fn(array $value):string=>json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $cutoff=$base['cutoff'];$current=($cutoff-86400+36000)*1000;$previous=($cutoff-172800+36000)*1000;
    $pub=str_repeat('1',32);$newPub=str_repeat('2',32);$creative=substr(hash('sha256','followthrough-original-creative'),0,32);$newCreative=substr(hash('sha256','followthrough-current-creative'),0,32);
    $draft=hash('sha256','followthrough-original-draft');$newDraft=hash('sha256','followthrough-current-draft');$product=992101;
    foreach([[996001,'old product photo','product_source','old source'],[996002,'old reviewed creative','promotion_design','old output'],
        [996003,'current product photo','product_source','new source'],[996004,'current reviewed creative','promotion_design','new output']] as [$id,$title,$source,$seed]){
        profile_analytics_period_fixture_row_v1($pdo,'user_media_assets',['id'=>$id,'user_id'=>$owner,'media_type'=>'photo','mime_type'=>'image/png','source'=>$source,
            'title'=>$title,'file_path'=>'private/media-studio/'.$owner.'/followthrough-fixture-'.$id.'.png',
            'metadata_json'=>$jsonEncode($source==='promotion_design'?['creative_id'=>$id===996002?$creative:$newCreative]:[]),
            'created_at'=>$date($previous-86400000),'updated_at'=>$date($previous-86400000)]);
    }
    foreach([[$draft,996001,'old source','Original T-shirt promotion'],[$newDraft,996003,'new source','Current T-shirt promotion']] as [$key,$asset,$seed,$title]){
        $data=['kind'=>'product_marketing_draft','draft_id'=>$key,'product_id'=>$product,'title'=>$title,'description'=>'Reviewed product wording.',
            'campaign_idea'=>'Review the existing product plan.','asset_id'=>$asset,'asset_sha256'=>hash('sha256',$seed),'asset_source'=>'saved_photo'];
        profile_analytics_period_fixture_row_v1($pdo,'agent_commerce_marketing_drafts_v1',['product_id'=>$product,'owner_user_id'=>$owner,'request_key'=>$key,'draft_json'=>$jsonEncode($data),'created_at'=>$date($previous-86400000)],'request_key');
    }
    // Exact historical reads remain valid beyond the recent twelve-draft list.
    for($i=0;$i<16;$i++){
        $key=hash('sha256','followthrough-recent-draft-'.$i);$data=['kind'=>'product_marketing_draft','draft_id'=>$key,'product_id'=>$product,'title'=>'Other saved draft '.$i,
            'description'=>'Another reviewed version.','campaign_idea'=>'Review version.','asset_id'=>996003,'asset_sha256'=>hash('sha256','new source'),'asset_source'=>'saved_photo'];
        profile_analytics_period_fixture_row_v1($pdo,'agent_commerce_marketing_drafts_v1',['product_id'=>$product,'owner_user_id'=>$owner,'request_key'=>$key,'draft_json'=>$jsonEncode($data),'created_at'=>$date($current+$i*1000)],'request_key');
    }
    foreach([[$pub,$creative,$draft,996001,996002,'old source','old output','paused'],[$newPub,$newCreative,$newDraft,996003,996004,'new source','new output','published']] as [$publicationId,$creativeId,$draftKey,$sourceId,$assetId,$sourceSeed,$outputSeed,$status]){
        profile_analytics_period_fixture_row_v1($pdo,'agent_commerce_promotions_v1',['id'=>$creativeId,'owner_user_id'=>$owner,'product_id'=>$product,'agent_id'=>$privateAgent,
            'conversation_id'=>$privateChat,'source_asset_id'=>$sourceId,'source_sha256'=>hash('sha256',$sourceSeed),'draft_key'=>$draftKey,
            'settings_json'=>$jsonEncode(['level'=>1,'style'=>'clean','headline'=>'Reviewed T-shirt','copy'=>'Reviewed copy','prompt'=>'','remove_background'=>false,'tolerance'=>24]),
            'asset_id'=>$assetId,'asset_sha256'=>hash('sha256',$outputSeed),'public_key'=>$publicationId,'public_product_url'=>'/profile-product.php?profile=analytics-owner&product=fixture-product',
            'public_cta'=>'Buy now','publication_revision'=>1,'status'=>$status,'created_at'=>$date($previous-86400000),'updated_at'=>$date($previous-86400000)]);
        profile_analytics_period_fixture_row_v1($pdo,'agent_promotion_publications_v1',['publication_id'=>$publicationId,'promotion_id'=>$creativeId,'owner_user_id'=>$owner,'product_id'=>$product,
            'agent_id'=>$privateAgent,'conversation_id'=>$privateChat,'source_asset_id'=>$sourceId,'draft_key'=>$draftKey,'campaign_id'=>null,'review_request'=>hash('sha256','review-'.$creativeId),'created_at'=>$date($previous-86400000)],'publication_id');
    }
    profile_analytics_period_fixture_row_v1($pdo,'agent_product_launches_v1',['product_id'=>$product,'owner_user_id'=>$owner,'agent_id'=>$privateAgent,'conversation_id'=>$privateChat,'draft_key'=>$newDraft,'creative_id'=>$newCreative,'last_stage'=>'published'],'product_id');

    // Native session keys are canonical browser hashes, never mutable CRM identity.
    $session=['id'=>990011,'owner_user_id'=>$owner,'session_key'=>hash('sha256','followthrough-native-session'),'visitor_user_id'=>98002,'identity_disclosed'=>1];
    profile_analytics_period_fixture_row_v1($pdo,'profile_visit_sessions',$session);
    $cid=991011;$tid=995011;$visitorMessage=991111;$answerMessage=991112;$runId=996011;$actionId=997011;
    $binding=['contract'=>'vp3.profile-conversation-authority.v1','transport'=>'native','owner_user_id'=>$owner,'visitor_user_id'=>98002,
        'credential_hash'=>hash('sha256','followthrough-native-browser'),'profile_agent_id'=>$publicAgent,'profile_session_id'=>$session['id'],'response_token'=>str_repeat('d',32)];
    $conversation=['id'=>$cid,'owner_user_id'=>$owner,'profile_agent_id'=>$publicAgent,'profile_session_id'=>$session['id'],'visitor_user_id'=>98002,
        'status'=>'resolved','started_at'=>$date($current),'last_message_at'=>$date($current+20000),'last_summary'=>'Saved promotion-linked exchange.'];
    profile_analytics_period_fixture_row_v1($pdo,'profile_agent_conversations',$conversation);
    profile_analytics_period_fixture_row_v1($pdo,'profile_agent_messages',['id'=>991110,'conversation_id'=>$cid,'sender_type'=>'system','sender_user_id'=>null,'message'=>'','context_json'=>$jsonEncode($binding),'created_at'=>$date($current)]);
    $question='Can I get this T-shirt in blue?';$answer='Here is the reviewed T-shirt promotion and its saved product details.';
    profile_analytics_period_fixture_row_v1($pdo,'profile_agent_messages',['id'=>$visitorMessage,'conversation_id'=>$cid,'sender_type'=>'visitor','sender_user_id'=>98002,'message'=>$question,'context_json'=>'{}','created_at'=>$date($current)]);
    $q=$pdo->prepare('SELECT p.*,u.display_name,u.is_active FROM user_profiles p JOIN users u ON u.id=p.user_id WHERE p.user_id=?');$q->execute([$owner]);$profile=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
    $q=$pdo->prepare('SELECT * FROM user_agents WHERE id=? AND owner_user_id=?');$q->execute([$publicAgent,$owner]);$agent=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
    $accepted=vp3_profile_agent_accepted_snapshot_pa3(['conversation_authority'=>['transport'=>'native'],'profile'=>$profile,'agent'=>$agent,'session'=>$session,'visitor'=>['id'=>98002],
        'accepted_visitor_auth_revision'=>hash('sha256','98002|fixture-visitor-password'),'accepted_message_sha256'=>hash('sha256',$question)]);
    profile_analytics_period_fixture_row_v1($pdo,'agent_workflow_runs',['id'=>$runId,'owner_user_id'=>$owner,'agent_id'=>$publicAgent,'workflow_type'=>'profile_visitor_reply','origin'=>'profile','source_kind'=>'profile_visitor_turn','source_key'=>'profile-turn:'.$tid,
        'source_hash'=>sha1('profile-turn:'.$tid),'dedupe_key'=>hash('sha256','profile-turn:'.$tid),'title'=>'Saved promotion-linked reply','goal'=>'Reply to exact visitor statement','decision_summary'=>'Saved exact source','status'=>'completed','risk_level'=>'low']);
    profile_analytics_period_fixture_row_v1($pdo,'agent_workflow_actions',['id'=>$actionId,'run_id'=>$runId,'owner_user_id'=>$owner,'sequence_no'=>1,'action_key'=>'profile-turn:'.$tid,'action_type'=>'execute','label'=>'Reply','summary'=>'Exact reply','status'=>'completed','capability_key'=>'profile.visitor.reply']);
    profile_analytics_period_fixture_row_v1($pdo,'profile_agent_handoff_turns_pa3',['id'=>$tid,'owner_user_id'=>$owner,'profile_agent_id'=>$publicAgent,'profile_session_id'=>$session['id'],'conversation_id'=>$cid,
        'visitor_message_id'=>$visitorMessage,'visitor_user_id'=>98002,'transport'=>'native','credential_hash'=>$binding['credential_hash'],'client_request_id'=>str_repeat('f',32),'request_hash'=>hash('sha256',$question),'chat_mode'=>'agent','accepted_json'=>$jsonEncode($accepted),
        'state'=>'replied','version'=>2,'conversation_epoch'=>1,'run_id'=>$runId,'action_id'=>$actionId,'answer_message_id'=>$answerMessage,'created_at'=>$date($current),'updated_at'=>$date($current+20000)]);
    profile_analytics_period_fixture_row_v1($pdo,'profile_agent_messages',['id'=>$answerMessage,'conversation_id'=>$cid,'sender_type'=>'agent','sender_user_id'=>null,'message'=>$answer,
        'context_json'=>$jsonEncode(['product_promotion_key'=>$pub,'profile_handoff'=>['contract'=>'vp3.profile-handoff.v1','turn_id'=>$tid,'visitor_message_id'=>$visitorMessage]]),'created_at'=>$date($current+20000)]);
    $result=agent_workflow_public_json_v1400($jsonEncode(['turn_id'=>$tid,'conversation_id'=>$cid,'visitor_message_id'=>$visitorMessage,'answer_message_id'=>$answerMessage]));
    profile_analytics_period_fixture_row_v1($pdo,'agent_workflow_receipts',['id'=>998011,'run_id'=>$runId,'action_id'=>$actionId,'owner_user_id'=>$owner,
        'receipt_key'=>hash('sha256',$owner.'|'.$runId.'|'.$actionId.'|profile-turn:'.$tid.':reply'),'executor'=>'cloud','worker_id'=>'fixture','attempt_no'=>1,'status'=>'completed','summary'=>'Saved exact reply','result_json'=>$jsonEncode($result),'error_class'=>'','created_at'=>$date($current+20000)]);
    $previousClick=str_repeat('3',32);$nativeClick=str_repeat('4',32);$plainClick=str_repeat('5',32);
    foreach([[$previousClick,'profile',$previous-60000,null],[$nativeClick,'profile_agent',$current+30000,$cid],[$plainClick,'profile',$current+40000,null]] as [$clickId,$placement,$at,$publicCid]){
        $click=['click_id'=>$clickId,'publication_id'=>$pub,'placement'=>$placement,'created_at'=>$date($at),'public_conversation_id'=>$publicCid,'public_conversation_binding_sha256_v1'=>null];
        if($publicCid!==null)$click['public_conversation_binding_sha256_v1']=profile_promotion_public_binding_digest_v1($click+['owner_user_id'=>$owner],$conversation,$binding,$session);
        profile_analytics_period_fixture_row_v1($pdo,'agent_promotion_clicks_v1',$click,'click_id');
    }
    $orderAt=$previous;$orderUtc=$date($orderAt);
    foreach([['agent_commerce_orders_v800','id',992201],['agent_commerce_order_items_v800','order_id',992201]] as [$table,$key,$id]){
        $q=$pdo->prepare('SELECT metadata_json FROM '.$table.' WHERE '.$key.'=?');$q->execute([$id]);$meta=json_decode((string)$q->fetchColumn(),true,64,JSON_THROW_ON_ERROR);$q->closeCursor();
        $meta['product_promotion_click_v1']=$previousClick;$meta['product_promotion_order_utc_v1']=$orderUtc;
        $pdo->prepare('UPDATE '.$table.' SET metadata_json=? WHERE '.$key.'=?')->execute([$jsonEncode($meta),$id]);
    }
    profile_analytics_period_fixture_row_v1($pdo,'agent_promotion_orders_v1',['order_id'=>992201,'click_id'=>$previousClick],'order_id');
    // Isolated provenance seed, never a production backfill: these attempts
    // were opened before their exact recorded payment in the fixed period.
    $pdo->prepare('UPDATE agent_commerce_checkout_attempts_v800 SET opened_recorded_at_utc_ms=? WHERE id=993401')->execute([$previous+60000]);
    $pdo->prepare('UPDATE agent_commerce_checkout_attempts_v800 SET opened_recorded_at_utc_ms=? WHERE id=993402')->execute([$current+60000]);
    return ['scope'=>$base['scope'],'ref'=>vp3_profile_commerce_followthrough_ref_v1($pub,$base['ref']),'period_ref'=>$base['ref'],'publication_id'=>$pub,'cutoff'=>$cutoff,
        'ids'=>['product'=>$product,'original_creative'=>$creative,'current_creative'=>$newCreative,'current_publication'=>$newPub,'draft'=>$draft,'current_draft'=>$newDraft,
            'source_asset'=>996001,'output_asset'=>996002,'public_conversation'=>$cid,'turn'=>$tid,'visitor_message'=>$visitorMessage,'answer_message'=>$answerMessage,
            'session'=>$session['id'],'receipt'=>998011,'native_click'=>$nativeClick,'previous_click'=>$previousClick,'plain_click'=>$plainClick,'order'=>992201]];
}
