<?php
declare(strict_types=1);
require __DIR__.'/fixtures/profile-specialist-workspace-v1.php';

$checks=0;$physical=0;
function pod_check(bool $ok,string $message): void{global $checks;if(!$ok)throw new RuntimeException('FAIL: '.$message);$checks++;echo "ok $checks - $message\n";}
function pod_denied(callable $run,int|array $status,string $message): void
{try{$run();}catch(Throwable $e){$code=property_exists($e,'httpStatus')?$e->httpStatus:$e->getCode();pod_check(in_array($code,(array)$status,true),$message.' ('.$code.')');return;}throw new RuntimeException('FAIL: '.$message.' accepted');}
function pod_input(int $cid,?string $id=null): array
{
    $view=pws_request('conversation',['public_conversation_id'=>$cid])['data'];
    return ['public_conversation_id'=>$cid,'turn_id'=>$view['current_turn']['turn_id'],'revision'=>$view['current_turn']['revision'],
        'source_revision'=>$view['source']['source_revision'],'prepare_request_id'=>$id??bin2hex(random_bytes(16)),
        'instruction'=>'Ask which size they normally wear.'];
}
function pod_seed(): array{global $physical;$physical=0;$ids=pws_reset();pws_claim($ids['primary']);return $ids;}
function pod_model(string $prompt,Closure $dispatch): array
{
    global $physical;if(!$dispatch())return ['ok'=>false];$physical++;
    if(db()->inTransaction())throw new RuntimeException('Physical inference must not hold SQL locks.');
    return ['ok'=>true,'answer'=>'{"body":"Which size do you usually wear?"}'];
}
function pod_prepare(array $input,?callable $model=null,?callable $guard=null): array
{return pws_request('draft_prepare',$input,7,11,$guard,$model??'pod_model')['data']['draft'];}
function pod_send(array $input,string $text='Which size do you normally wear?'): array
{return ['public_conversation_id'=>$input['public_conversation_id'],'turn_id'=>$input['turn_id'],'revision'=>$input['revision'],
    'operation'=>'reply','request_id'=>bin2hex(random_bytes(16)),'message'=>$text,'prepare_request_id'=>$input['prepare_request_id']];}

$ids=pod_seed();$input=pod_input($ids['primary'],str_repeat('a',32));$before=pa4b_snapshot();$seenPrompt='';
$draft=pod_prepare($input,static function($prompt,$dispatch)use(&$seenPrompt){$seenPrompt=$prompt;return pod_model($prompt,$dispatch);});
pod_check($draft['state']==='ready'&&$draft['current']&&$draft['text']==='Which size do you usually wear?'&&$physical===1,'explicit preparation creates one current private draft through one dispatch');
pod_check(pa4b_snapshot()===$before&&pa3_count('profile_agent_messages',"sender_type='owner' AND conversation_id=".$ids['primary'])===0,'preparation changes no visitor message, attention, delivery or responder state');
pod_check(str_contains($seenPrompt,'Could you help me choose a T-shirt size?')&&!str_contains($seenPrompt,'PRIVATE_CHAT_SECRET')&&!str_contains($seenPrompt,'credential_hash'),'provider receives bounded exact visitor evidence without private Chat or credentials');
pod_check($draft['prepare']['instruction']===$input['instruction']&&$draft['prepare']['source_revision']===$input['source_revision'],'reopened draft retains immutable prepare input for explicit recovery');
$replay=pod_prepare($input,static function(){throw new RuntimeException('Duplicate provider call');});
pod_check($replay===$draft&&$physical===1,'same-ID preparation retry returns saved draft without inference');
$latest=pws_request('draft_status',['public_conversation_id'=>$ids['primary'],'latest'=>1])['data']['draft'];
pod_check($latest===$draft,'read-only latest recovery returns the saved draft in this exact scope');
pod_denied(fn()=>pod_prepare(array_replace($input,['instruction'=>'Changed instruction'])),409,'prepare ID cannot be reused with different instruction');
pod_denied(fn()=>pod_prepare(array_replace($input,['public_conversation_id'=>$ids['secondary']])),409,'prepare ID cannot move into another owned thread');
db()->exec('INSERT INTO chat_conversations(id,user_id,user_agent_id,title) VALUES(14,1,7,\'Other private scope\')');
pod_denied(fn()=>pws_request('draft_status',['public_conversation_id'=>$ids['primary'],'prepare_request_id'=>$input['prepare_request_id']],7,14),409,'saved draft cannot cross private Chat scope');
pod_denied(fn()=>pws_request('draft_prepare',$input,7,14,null,'pod_model'),409,'prepare ID cannot be rebound to another private Chat');
pod_check(pws_request('draft_status',['public_conversation_id'=>$ids['primary'],'latest'=>1],7,14)['data']['draft']===null,'latest lookup does not discover another private Chat draft');
$send=pod_send($input);$sent=pws_request('conversation_action',$send)['data'];$after=pa4b_snapshot();
$again=pws_request('conversation_action',$send)['data'];$status=pws_request('draft_status',['public_conversation_id'=>$ids['primary'],'prepare_request_id'=>$input['prepare_request_id']])['data']['draft'];
pod_check(!$sent['duplicate']&&$again['duplicate']&&$sent['message_id']===$again['message_id']&&pa4b_snapshot()===$after,'reviewed owner send recovers exact receipt after committed response loss');
pod_check($status['state']==='sent'&&$status['owner_edited']&&$status['receipt']['message_id']===$sent['message_id'],'saved send records actual edited body and canonical message receipt');
pod_check($physical===1&&vp3_profile_handoff_thread_pa3(db(),1,$ids['primary'])['mode']==='owner','reviewed send performs no second inference or competing Agent reply');
pod_denied(fn()=>pws_request('conversation_action',array_replace($send,['message'=>'Different retry text'])),409,'same send ID cannot change reviewed body');
$oldPrepare=pod_prepare($input,static function(){throw new RuntimeException('Old request was redispatched');});
pod_check($oldPrepare['state']==='sent'&&$oldPrepare['receipt']===$status['receipt'],'late prepare retry recovers sent result before current-source freshness');
$nextInput=pod_input($ids['primary']);$nextDraft=pod_prepare($nextInput);
pod_check($nextDraft['state']==='ready'&&$nextDraft['prepare_request_id']!==$input['prepare_request_id']&&$physical===2,'a completed owner reply permits a new explicit preparation without rewriting its sent receipt');
pod_check(pws_request('draft_status',['public_conversation_id'=>$ids['primary'],'prepare_request_id'=>$input['prepare_request_id']])['data']['draft']['receipt']===$status['receipt'],'new preparation preserves earlier exact send receipt');

$ids=pod_seed();$input=pod_input($ids['primary']);$queued=pod_prepare($input,static fn()=>['ok'=>false]);
pod_check($queued['state']==='queued'&&!$queued['attempted']&&$queued['can_resume']&&$physical===0,'no physical attempt leaves an explicitly resumable saved reservation');
pod_denied(fn()=>pod_prepare(array_replace($input,['prepare_request_id'=>bin2hex(random_bytes(16)),'supersedes_request_id'=>$input['prepare_request_id']])),409,'same-source queued reservation cannot be regenerated behind disabled UI control');
$resumed=pod_prepare($queued['prepare']);
pod_check($resumed['state']==='ready'&&$physical===1&&pa3_count('profile_owner_drafts_v1')===1,'explicit same-ID resume uses exact saved input and only one ledger row');

// Two requests read reserved. The outer invocation wins the physical CAS while
// the nested loser is already inside its model function. The loser must not mark
// the winner uncertain or erase its eventual result. This uses actual SQL/CAS.
$ids=pod_seed();$input=pod_input($ids['primary']);$loser=null;$during=null;
$winner=pod_prepare($input,static function($prompt,$winnerDispatch)use($input,&$loser,&$during){
    $loser=pod_prepare($input,static function($unused,$loserDispatch)use($winnerDispatch){
        pod_check($winnerDispatch()===true,'concurrent winning invocation owns durable dispatch token');
        $GLOBALS['physical']++;
        pod_check($loserDispatch()===false,'concurrent losing invocation cannot dispatch a second attempt');
        return ['ok'=>false];
    });
    $during=vp3_profile_owner_draft_row_v1(db(),1,$input['prepare_request_id']);
    return ['ok'=>true,'answer'=>'{"body":"Winning result remains recoverable."}'];
});
pod_check($loser['state']==='running'&&$during['status']==='dispatching','concurrent loser leaves the pending winner unchanged');
pod_check($winner['state']==='ready'&&$winner['text']==='Winning result remains recoverable.'&&$physical===1,'winning provider result survives concurrent same-ID loser completion');

$ids=pod_seed();$input=pod_input($ids['primary']);
$uncertain=pod_prepare($input,static function($p,$dispatch){if($dispatch())$GLOBALS['physical']++;throw new RuntimeException('SECRET physical connection lost');});
pod_check($uncertain['state']==='uncertain'&&$uncertain['attempted']&&$uncertain['text']===''&&$physical===1,'unknown physical result is durable and exposes no provider diagnostic');
$same=pod_prepare($input,static function(){throw new RuntimeException('must not redispatch');});
pod_check($same['state']==='uncertain'&&$physical===1,'uncertain same-ID retry never starts another provider attempt');
$regen=array_replace(pod_input($ids['primary']),['supersedes_request_id'=>$input['prepare_request_id']]);$new=pod_prepare($regen);
pod_check($new['state']==='ready'&&$new['prepare_request_id']!==$input['prepare_request_id']&&$physical===2,'explicit regeneration uses a new request and one additional authorized attempt');
$old=pws_request('draft_status',['public_conversation_id'=>$ids['primary'],'prepare_request_id'=>$input['prepare_request_id']])['data']['draft'];
pod_check($old['state']==='stale'&&!$old['current'],'superseded uncertain attempt remains separate from current draft');

$ids=pod_seed();$input=pod_input($ids['primary']);
$noFence=pod_prepare($input,static fn()=>['ok'=>true,'answer'=>'{"body":"Unverified physical result"}']);
pod_check($noFence['state']==='failed'&&!$noFence['attempted']&&$noFence['text']==='','provider result cannot be saved without durable dispatch proof');
$ids=pod_seed();$input=pod_input($ids['primary']);
$invalid=pod_prepare($input,static function($p,$d){$d();$GLOBALS['physical']++;return ['ok'=>true,'answer'=>'{"body":"Hello","tool":"send"}'];});
pod_check($invalid['text']===''&&$invalid['state']==='uncertain'&&$physical===1,'unexpected tool or output fields are withheld without retry');

foreach(['before','after'] as $when){
    foreach(['profile_off','public_agent','session_key','visitor_text','hidden_credential','private_agent_instructions','delegate','resolve','later_visitor'] as $kind){
        $ids=pod_seed();$input=pod_input($ids['primary']);
        $change=static function()use($kind,$ids){
            $p=db();switch($kind){
                case 'profile_off':$p->exec('UPDATE user_profiles SET is_public=0 WHERE user_id=1');break;
                case 'public_agent':$p->exec('UPDATE user_profiles SET profile_agent_id=8 WHERE user_id=1');break;
                case 'session_key':$p->prepare("UPDATE profile_visit_sessions SET session_key='changed-session' WHERE id=(SELECT profile_session_id FROM profile_agent_conversations WHERE id=?)")->execute([$ids['primary']]);break;
                case 'visitor_text':$p->prepare("UPDATE profile_agent_messages SET message='Changed visitor statement' WHERE conversation_id=? AND sender_type='visitor'")->execute([$ids['primary']]);break;
                case 'hidden_credential':
                    $q=$p->prepare("SELECT id,context_json FROM profile_agent_messages WHERE conversation_id=? AND sender_type='system'");$q->execute([$ids['primary']]);$row=$q->fetch();$binding=json_decode($row['context_json'],true);$binding['credential_hash']=str_repeat('f',64);$p->prepare('UPDATE profile_agent_messages SET context_json=? WHERE id=?')->execute([json_encode($binding,JSON_THROW_ON_ERROR),$row['id']]);break;
                case 'private_agent_instructions':$p->exec("UPDATE user_agents SET instructions='Changed private style' WHERE id=7");break;
                case 'delegate':case 'resolve':
                    $view=pws_request('conversation',['public_conversation_id'=>$ids['primary']])['data'];pws_request('conversation_action',['public_conversation_id'=>$ids['primary'],'turn_id'=>$view['current_turn']['turn_id'],'revision'=>$view['current_turn']['revision'],'operation'=>$kind,'request_id'=>bin2hex(random_bytes(16))]);break;
                case 'later_visitor':pa3_age();pa3_admit('A new visitor question arrived.',$ids['primary'],bin2hex(random_bytes(16)),0,'b');pa3_login(1);break;
            }
        };
        $result=pod_prepare($input,static function($prompt,$dispatch)use($when,$change){
            if($when==='before')$change();
            if(!$dispatch())return ['ok'=>false];$GLOBALS['physical']++;
            if($when==='after')$change();return ['ok'=>true,'answer'=>'{"body":"This result must be withheld."}'];
        });
        pod_check($result['state']==='stale'&&$result['text']===''&&$physical===($when==='before'?0:1),$kind.' change '.$when.' dispatch withholds result and prevents unauthorized repeat');
    }
}

foreach(['before','after'] as $when){foreach(['profile_permission','private_chat','session_owner'] as $kind){
    $ids=pod_seed();$input=pod_input($ids['primary']);$switched=false;
    $guard=static fn()=>$switched?pa3_owner(4):pa3_owner();
    // Capture by reference: an actual endpoint uses strict session reopen instead.
    $guard=static function()use(&$switched){return $switched?pa3_owner(4):pa3_owner();};
    $change=static function()use($kind,&$switched){if($kind==='profile_permission')db()->exec("DELETE FROM role_permissions WHERE permission_key='profile_chat.access'");elseif($kind==='private_chat')db()->exec('UPDATE chat_conversations SET user_id=4 WHERE id=11');else $switched=true;};
    pod_denied(fn()=>pod_prepare($input,static function($p,$d)use($when,$change){if($when==='before')$change();if(!$d())return ['ok'=>false];$GLOBALS['physical']++;if($when==='after')$change();return ['ok'=>true,'answer'=>'{"body":"Private result must not leak."}'];},$guard),403,$kind.' revocation '.$when.' physical attempt rejects the response');
    pod_check($physical===($when==='before'?0:1)&&db()->query('SELECT body FROM profile_owner_drafts_v1')->fetchColumn()==='', $kind.' revocation leaves no generated private text stored');
}}

foreach(['password','disabled','block'] as $kind){foreach(['before','after'] as $when){
    $ids=pod_seed();pa3_age();$admitted=pa3_admit('A signed-in visitor asks a current question.',0,bin2hex(random_bytes(16)),2,'d');$cid=(int)$admitted['conversation_id'];pws_claim($cid);$input=pod_input($cid);
    $change=static function()use($kind){if($kind==='password')db()->exec("UPDATE users SET password_hash='changed-visitor-credential' WHERE id=2");elseif($kind==='disabled')db()->exec('UPDATE users SET is_active=0 WHERE id=2');else db()->exec('INSERT INTO user_blocks(blocker_user_id,blocked_user_id) VALUES(1,2)');};
    $result=pod_prepare($input,static function($p,$d)use($when,$change){if($when==='before')$change();if(!$d())return ['ok'=>false];$GLOBALS['physical']++;if($when==='after')$change();return ['ok'=>true,'answer'=>'{"body":"Withheld member result."}'];});
    pod_check($result['state']==='stale'&&$result['text']===''&&$physical===($when==='before'?0:1),'current visitor '.$kind.' change '.$when.' inference invalidates accepted source');
}}

$ids=pod_seed();$input=pod_input($ids['primary']);$newDraft=null;
$late=pod_prepare($input,static function($p,$dispatch)use($input,$ids,&$newDraft){
    $dispatch();$GLOBALS['physical']++;
    pod_denied(fn()=>pod_prepare(array_replace($input,['prepare_request_id'=>bin2hex(random_bytes(16)),'supersedes_request_id'=>$input['prepare_request_id']])),409,'same-source active physical request cannot be regenerated');
    pa3_age();pa3_admit('Now I need a different item.',$ids['primary'],bin2hex(random_bytes(16)),0,'b');pa3_login(1);
    $newInput=pod_input($ids['primary']);$newInput['supersedes_request_id']=$input['prepare_request_id'];
    $newDraft=pod_prepare($newInput);
    return ['ok'=>true,'answer'=>'{"body":"Late old result must not replace the new one."}'];
});
pod_check($late['state']==='stale'&&$late['text']===''&&$newDraft['state']==='ready'&&$physical===2,'explicit stale-source regeneration prevents late original completion from overwriting new draft');
pod_check(pws_request('draft_status',['public_conversation_id'=>$ids['primary'],'latest'=>1])['data']['draft']['prepare_request_id']===$newDraft['prepare_request_id'],'latest recovery selects current regeneration rather than late original request');

$ids=pod_seed();$input=pod_input($ids['primary']);$draft=pod_prepare($input);$send=pod_send($input);
pa3_age();pa3_admit('A later public message.',$ids['primary'],bin2hex(random_bytes(16)),0,'b');pa3_login(1);
$saved=pws_request('draft_status',['public_conversation_id'=>$ids['primary'],'prepare_request_id'=>$input['prepare_request_id']])['data']['draft'];
pod_check($saved['state']==='stale'&&$saved['text']===$draft['text']&&!$saved['current'],'stale saved draft stays reviewable without pretending to match new visitor source');
pod_denied(fn()=>pws_request('conversation_action',$send),409,'stale reviewed draft cannot send to a later visitor turn');
pod_check(pa3_count('profile_agent_messages',"sender_type='owner' AND conversation_id=".$ids['primary'])===0,'stale draft send rejection writes no owner message');

$ids=pod_seed();$input=pod_input($ids['primary']);pod_prepare($input);$manual=pod_send($input);unset($manual['prepare_request_id']);
$manualSent=pws_request('conversation_action',$manual)['data'];$attach=$manual+['prepare_request_id'=>$input['prepare_request_id']];
pod_denied(fn()=>pws_request('conversation_action',$attach),409,'an unrelated prior manual send receipt cannot be retroactively attached to a draft');
pod_check(pa3_count('profile_agent_messages',"sender_type='owner' AND conversation_id=".$ids['primary'])===1,'failed receipt relinking creates no duplicate message');
echo "PASS Profile owner drafts: $checks checks (".db()->getAttribute(PDO::ATTR_DRIVER_NAME).")\n";
