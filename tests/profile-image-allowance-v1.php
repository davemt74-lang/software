<?php
declare(strict_types=1);

define('VP3_PROFILE_IMAGE_TEST_HELPERS_ONLY',true);
require __DIR__.'/profile-image-edit-v1.php';

function allowance_context_v1(int $visitor=0,string $browser='a'): array
{
    pa3_login($visitor);$_COOKIE=['sf_profile_visitor'=>str_repeat($browser,64),'sf_profile_conversation'=>str_repeat($browser,64)];
    return vp3_profile_agent_public_context_v110(db(),profile_by_username(db(),'fixture-owner'),current_user());
}
function allowance_read_v1(array $ctx): array{return vp3_profile_image_edit_allowance_v1(db(),1,$ctx);}
function allowance_retire_v1(array $receipt,string $state='failed'): void
{
    db()->prepare('UPDATE profile_image_edits_v1 SET state=? WHERE visitor_message_id=?')->execute([$state,(int)$receipt['visitor_message_id']]);
    pa3_age();
}
function allowance_send_v1(array $ctx,?string $request=null,int $cid=0): array
{
    return vp3_profile_agent_public_message_service_v110(db(),$ctx,'Use the approved photo style.',$cid,
        ['async'=>true,'chat_mode'=>'agent','client_request_id'=>$request??bin2hex(random_bytes(16)),'image_edit'=>edit_intent()]);
}

try{
    edit_setup();$ctx=allowance_context_v1();$fresh=allowance_read_v1($ctx);
    edit_check($fresh['ready']===true&&$fresh['remaining']===3,'an unused visitor sees the exact configured daily edit allowance');
    $nextUtc=(int)strtotime(gmdate('Y-m-d 00:00:00').' UTC')*1000+86400000;
    edit_check($fresh['resets_at_utc_ms']===$nextUtc,'allowance reset is the next UTC midnight in integer milliseconds');
    $available=vp3_profile_image_edit_availability_v1($pdo,1,'fixture-owner',$ctx);
    edit_check($available['allowance']===$fresh,'public image availability exposes the canonical allowance projection');

    $request=bin2hex(random_bytes(16));$first=allowance_send_v1($ctx,$request);
    edit_check(allowance_read_v1($ctx)['remaining']===2&&edit_row($first)['state']==='queued','admission immediately reserves one edit before any provider call');
    $duplicate=allowance_send_v1($ctx,$request);
    edit_check($duplicate['duplicate']&&allowance_read_v1($ctx)['remaining']===2,'retry of the exact admitted image does not reserve another allowance unit');
    edit_check($GLOBALS['editProviderCalls']===[]&&$GLOBALS['imageReservations']===[],'reading and reserving the public count invoke no image provider or billing preflight');

    // Public collection reads must recover the same existing anonymous session
    // key as admission without creating a visit or relying on old PHP tokens.
    unset($_SESSION['profile_visit_tokens']);$sessions=pa3_count('profile_visit_sessions');$beforeCookies=$_COOKIE;$beforeSession=$_SESSION;
    $collection=vp3_profile_image_collection_visitor_v1($pdo,1,null);
    edit_check($collection['session_id']===$ctx['session_id']&&allowance_read_v1($collection)['remaining']===2,'a returning visitor cookie recovers the same reserved anonymous allowance');
    edit_check(pa3_count('profile_visit_sessions')===$sessions&&$_COOKIE===$beforeCookies&&$_SESSION===$beforeSession,'collection allowance lookup does not mint identity or write a visit');
    $legacy=str_repeat('f',48);$pdo->prepare('UPDATE profile_visit_sessions SET session_key=? WHERE id=?')->execute([hash('sha256',$legacy.'|1'),$ctx['session_id']]);
    $_COOKIE=[];$_SESSION['profile_visit_tokens']['1']=$legacy;
    edit_check(vp3_profile_image_collection_visitor_v1($pdo,1,null)['session_id']===$ctx['session_id'],'existing legacy visitor tokens retain their stored session allowance');
    unset($_SESSION['profile_visit_tokens']);$_COOKIE=[];$beforeSession=$_SESSION;
    $newVisitor=vp3_profile_image_collection_visitor_v1($pdo,1,null);
    edit_check($newVisitor['session_id']===0&&$_COOKIE===[]&&$_SESSION===$beforeSession,'an unvisited collection does not create a tracking token or stored session');

    // The owner and visitor limits are independent; display their exact minimum.
    edit_setup();edit_settings(['daily_limit'=>2,'visitor_daily_limit'=>5]);$ctx=allowance_context_v1();
    edit_check(allowance_read_v1($ctx)['remaining']===2,'smaller owner limit bounds a new visitor allowance');
    $first=allowance_send_v1($ctx);allowance_retire_v1($first);$other=allowance_context_v1(2,'c');
    edit_check(allowance_read_v1($other)['remaining']===1,'another visitor sees the owner remaining balance rather than a fresh full limit');
    $second=allowance_send_v1($other);allowance_retire_v1($second);$ctx=allowance_context_v1();$empty=allowance_read_v1($ctx);
    edit_check($empty['ready']===true&&$empty['remaining']===0,'exhausted owner allowance is known zero, not unknown');
    edit_denied(fn()=>allowance_send_v1($ctx),'known zero owner allowance agrees with admission denial','PROFILE_IMAGE_LIMIT');

    edit_setup();edit_settings(['daily_limit'=>10,'visitor_daily_limit'=>1]);$ctx=allowance_context_v1();$first=allowance_send_v1($ctx);allowance_retire_v1($first);
    edit_check(allowance_read_v1($ctx)['remaining']===0,'smaller visitor limit bounds the remaining allowance');
    edit_denied(fn()=>allowance_send_v1($ctx),'known zero visitor allowance agrees with admission denial','PROFILE_IMAGE_LIMIT');
    $other=allowance_context_v1(2,'c');edit_check(allowance_read_v1($other)['remaining']===1,'one visitor cannot consume another visitor limit while owner balance remains');

    // CRM identity changes do not rewrite the admission principal. Members are
    // counted across sessions; anonymous requests remain scoped to their session.
    edit_setup();$guest=allowance_context_v1();$guestReceipt=allowance_send_v1($guest);allowance_retire_v1($guestReceipt);
    $member=allowance_context_v1(2);edit_check($member['session_id']===$guest['session_id']&&allowance_read_v1($member)['remaining']===3,'signing in on a known browser uses the member counter rather than the prior guest counter');
    $memberReceipt=allowance_send_v1($member);allowance_retire_v1($memberReceipt);
    $memberElsewhere=allowance_context_v1(2,'c');
    edit_check($memberElsewhere['session_id']!==$member['session_id']&&allowance_read_v1($memberElsewhere)['remaining']===2,'a member retains one allowance counter across different visitor sessions');
    $anotherMember=allowance_context_v1(3,'d');edit_check(allowance_read_v1($anotherMember)['remaining']===3,'another signed-in member has an independent visitor counter');
    $returningGuest=allowance_context_v1();
    edit_check(allowance_read_v1($returningGuest)['remaining']===2,'anonymous return uses its original session counter despite retained CRM member identity');
    $newGuest=allowance_context_v1(0,'e');edit_check(allowance_read_v1($newGuest)['remaining']===3,'another anonymous session has its own counter bounded by the same owner balance');

    foreach(['queued','editing','staged','completed','failed','cancelled'] as $state){
        edit_setup();edit_settings(['daily_limit'=>1,'visitor_daily_limit'=>1]);$ctx=allowance_context_v1();$receipt=allowance_send_v1($ctx);allowance_retire_v1($receipt,$state);
        edit_check(allowance_read_v1($ctx)['remaining']===0,$state.' reserved request remains counted in today’s displayed allowance');
        edit_denied(fn()=>allowance_send_v1($ctx),$state.' reservation is counted by admission before any busy/provider check','PROFILE_IMAGE_LIMIT');
    }

    edit_setup();edit_settings(['daily_limit'=>1,'visitor_daily_limit'=>1]);$ctx=allowance_context_v1();$receipt=allowance_send_v1($ctx);allowance_retire_v1($receipt);
    $midnight=strtotime(gmdate('Y-m-d 00:00:00').' UTC');
    $pdo->prepare('UPDATE profile_image_edits_v1 SET created_at=? WHERE visitor_message_id=?')->execute([gmdate('Y-m-d H:i:s',$midnight-1),$receipt['visitor_message_id']]);
    edit_check(allowance_read_v1($ctx)['remaining']===1,'a reservation immediately before UTC midnight does not consume today’s allowance');
    $pdo->prepare('UPDATE profile_image_edits_v1 SET created_at=? WHERE visitor_message_id=?')->execute([gmdate('Y-m-d H:i:s',$midnight),$receipt['visitor_message_id']]);
    edit_check(allowance_read_v1($ctx)['remaining']===0,'a reservation at exactly UTC midnight consumes today’s allowance');
    $timezone=date_default_timezone_get();date_default_timezone_set('Pacific/Auckland');
    try{edit_check(allowance_read_v1($ctx)['remaining']===0&&allowance_read_v1($ctx)['resets_at_utc_ms']===($midnight+86400)*1000,'display timezone cannot shift the UTC allowance cutoff or reset');}
    finally{date_default_timezone_set($timezone);}
    $pdo->prepare('UPDATE profile_image_edits_v1 SET created_at=? WHERE visitor_message_id=?')->execute([gmdate('Y-m-d H:i:s',$midnight-1),$receipt['visitor_message_id']]);
    $next=allowance_send_v1($ctx);
    edit_check((int)$next['visitor_message_id']!==(int)$receipt['visitor_message_id']&&allowance_read_v1($ctx)['remaining']===0,'admission and display both release the previous UTC day and reserve today’s new request');

    // A deliberately requested image is separate from an unanswered text turn.
    // Its completion must neither answer that question nor enable text inference.
    edit_setup();$pdo->beginTransaction();vp3_profile_response_settings_save_v1($pdo,1,'user_responses',0);$pdo->commit();$ctx=allowance_context_v1();
    $text=vp3_profile_agent_public_message_service_v110($pdo,$ctx,'Please confirm the private appointment time.',0,['async'=>true,'chat_mode'=>'agent','client_request_id'=>bin2hex(random_bytes(16))]);
    $waiting=$text['response']['request'];pa3_age();$image=allowance_send_v1($ctx,null,(int)$text['conversation_id']);edit_dispatch($image);
    $request=vp3_profile_response_projection_v1($pdo,1,(int)$text['conversation_id'])['request'];
    edit_check(edit_row($image)['state']==='completed'&&count($GLOBALS['editProviderCalls'])===1,'User responses still permits one deliberately submitted approved image edit');
    edit_check($request['id']===$waiting['id']&&$request['status']==='waiting'&&$request['reply_message_id']===0
        &&vp3_profile_handoff_row_pa3($pdo,(int)$text['handoff']['turn_id'])['state']==='owner_requested','an image result leaves the exact unanswered text request waiting for its owner');
    pa3_age();$follow=vp3_profile_agent_public_message_service_v110($pdo,$ctx,'Please also confirm the appointment address.',(int)$text['conversation_id'],['async'=>true,'chat_mode'=>'agent','client_request_id'=>bin2hex(random_bytes(16))]);
    edit_check($follow['handoff']['state']==='owner_requested'&&pa3_count('profile_agent_owner_requests_v1',"status='waiting'")===2
        &&$GLOBALS['pa3ModelCalls']===[]&&$GLOBALS['pa3HomeCalls']===[],'a completed image cannot switch later User responses text into automatic inference');

    edit_setup();$ctx=allowance_context_v1();
    foreach([null,['owner_user_id'=>4,'visitor'=>null,'session_id'=>$ctx['session_id']],['owner_user_id'=>1,'visitor'=>['id'=>1],'session_id'=>$ctx['session_id']]] as $invalid){
        $unknown=vp3_profile_image_edit_allowance_v1($pdo,1,$invalid);
        edit_check($unknown['ready']===false&&$unknown['remaining']===null&&$unknown['resets_at_utc_ms']===null,'missing or invalid visitor context stays unknown instead of inventing a zero balance');
    }
    edit_settings(['enabled'=>false]);$disabled=allowance_read_v1($ctx);
    edit_check($disabled['ready']===false&&$disabled['remaining']===null,'disabled image editing does not pretend to have a known allowance');
    edit_settings(['enabled'=>true]);$pdo->failSql='SELECT COUNT(*) FROM profile_image_edits_v1';$failed=allowance_read_v1($ctx);$pdo->failSql='';
    edit_check($failed['ready']===false&&$failed['remaining']===null&&$failed['resets_at_utc_ms']===null,'unavailable usage reads return unknown rather than an invented zero or full balance');
    $pdo->exec('ALTER TABLE profile_image_edits_v1 RENAME TO profile_image_edits_allowance_hidden');
    try{$missing=allowance_read_v1($ctx);edit_check($missing['ready']===false&&$missing['remaining']===null&&!table_exists('profile_image_edits_v1'),'pre-upgrade allowance remains unknown and performs no runtime DDL');}
    finally{$pdo->exec('ALTER TABLE profile_image_edits_allowance_hidden RENAME TO profile_image_edits_v1');}
    $writes=[];$pdo->beforeSql=static function(string $sql)use(&$writes):void{if(preg_match('/^\s*(INSERT|UPDATE|DELETE|CREATE|ALTER|DROP|REPLACE)\b/i',$sql))$writes[]=$sql;};
    $before=[pa3_count('profile_visit_sessions'),pa3_count('profile_image_edits_v1'),pa3_count('agent_workflow_runs')];
    allowance_read_v1($ctx);vp3_profile_image_edit_availability_v1($pdo,1,'fixture-owner',$ctx);vp3_profile_image_collection_visitor_v1($pdo,1,null);
    $after=[pa3_count('profile_visit_sessions'),pa3_count('profile_image_edits_v1'),pa3_count('agent_workflow_runs')];$pdo->beforeSql=null;
    edit_check($writes===[]&&$before===$after&&$GLOBALS['editProviderCalls']===[]&&$GLOBALS['imageReservations']===[],'allowance and collection reads are side-effect free and start no inference or billing');
    echo 'PROFILE_IMAGE_ALLOWANCE_V1=PASS ('.$checks.' cases; '.$pdo->getAttribute(PDO::ATTR_DRIVER_NAME).")\n";
}finally{
    $pdo->beforeSql=null;$pdo->failSql='';
    if(is_dir(STONEFELLOW_ROOT)){$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator(STONEFELLOW_ROOT,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $entry){$entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname());}rmdir(STONEFELLOW_ROOT);}
}
