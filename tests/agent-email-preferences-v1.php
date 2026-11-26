<?php
declare(strict_types=1);

// Native provenance, session authority and immutable Soul publication. All remote
// inference and Gmail reads cross explicit local doubles; sending is forbidden.
require_once __DIR__.'/fixtures/phone-agent-email-call-native-v1.php';
require_once dirname(__DIR__).'/includes/agent-email-preferences-v1.php';
require_once dirname(__DIR__).'/includes/agent-email-preferences-http-v1.php';
require_once dirname(__DIR__).'/includes/agent-coordination-gmail-draft-v1.php';
require_once dirname(__DIR__).'/includes/agent-coordination-work-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-errors-v1.php';

function ep_check(bool $ok,string $label): void
{
    if (!$ok) throw new RuntimeException($label);
    ++$GLOBALS['epChecks'];echo 'PASS '.$label.PHP_EOL;
}
function ep_reject(array $codes,callable $work,string $label): void
{
    try {$work();} catch (Throwable $error) {
        if ($error instanceof PDOException || !in_array((int)$error->getCode(),$codes,true)) throw $error;
        ep_check(true,$label);return;
    }
    throw new RuntimeException('Expected rejection: '.$label);
}
function ep_session(int $owner=1): void
{
    pec_session_v1($owner);$_SESSION['agent_connection_email_review_v1']='preference-native-review-key';
}
function ep_current(?array $user=null,?array $principal=null,int $chat=11): Closure
{
    if (session_status()!==PHP_SESSION_ACTIVE) session_start();
    $callback=agent_email_preferences_http_current_v1(db(),$user??pec_user_v1(),$principal??$GLOBALS['epPrincipal'],$chat);
    session_write_close();return $callback;
}
function ep_action(array $input,?callable $model=null): array
{
    return agent_connection_email_chat_action_v1(db(),pec_user_v1(),$GLOBALS['epPrincipal'],11,$input,
        'preference-native-review-key','pec_http_v1',$model);
}
function ep_review(string $id): array {return ep_action(['action'=>'review','draft_id'=>$id]);}
function ep_row(string $id): array {return agent_connection_email_chat_draft_v1(db(),1,7,11,$id);}
function ep_save(array $draft,string $body,?string $subject=null,?string $to=null): array
{
    return ep_action(['action'=>'compose_save','draft_id'=>$draft['id'],'review_token'=>$draft['review_token'],
        'to'=>$to??$draft['to'],'subject'=>$subject??$draft['subject'],'body'=>$body,'attachment_ids'=>[]]);
}
function ep_generate(?array $draft=null,string $body='Please let me know which time suits you.'): array
{
    if ($draft===null) {
        $draft=ep_action(['action'=>'compose_create','request_id'=>bin2hex(random_bytes(16))]);
        $draft=ep_save($draft,'A saved owner instruction.','Scheduling request','recipient@example.test');
    }
    ep_action(['action'=>'compose_generate','draft_id'=>$draft['id'],'review_token'=>$draft['review_token'],
        'instruction'=>'Write a short, friendly scheduling request.']);
    return ep_action(['action'=>'advance_write','draft_id'=>$draft['id']],static function(string $json,array $service,array $control)use($body):array{
        if (db()->inTransaction() || !$control['before_gmail_reply_dispatch']()) throw new LogicException('Native writer dispatch was not current.');
        ++$GLOBALS['epWriterCalls'];$GLOBALS['epWriterPrompts'][]=json_decode($json,true,16,JSON_THROW_ON_ERROR);
        return ['ok'=>true,'answer'=>json_encode(['subject'=>'Scheduling request','body'=>$body],JSON_THROW_ON_ERROR)];
    });
}
function ep_edited(string $body='Hi, could you share a convenient time? Thanks.'): array
{return ep_save(ep_generate(),$body);}
function ep_status(string $id,?Closure $current=null): array
{return agent_email_preferences_status_v1(db(),pec_user_v1(),$GLOBALS['epPrincipal'],11,$id,$current??ep_current());}
function ep_prepare_input(array $status): array
{return ['draft_id'=>$status['draft_id'],'capture_id'=>$status['capture_id'],'edit_revision'=>$status['edit_revision']];}
function ep_infer(string $prompt,string $protocol,Closure $before,array $job,bool $transient): string
{
    if (db()->inTransaction()) throw new LogicException('Preference inference ran in a SQL transaction.');
    if ($protocol!=='vp3.email-preference-suggestion.v1' || !$transient || !empty($job['background'])
        || empty($job['email_preference_suggestion']) || ($job['conversation_id']??null)!==11) {
        throw new LogicException('Preference inference lost the explicit isolated foreground contract.');
    }
    $before();++$GLOBALS['epLearningCalls'];$GLOBALS['epLearningPrompts'][]=json_decode($prompt,true,16,JSON_THROW_ON_ERROR);
    return '{"category":"tone","preference":"Use a warm, direct tone and a brief thank-you."}';
}
function ep_prepare(array $draft,?callable $inference=null,?Closure $current=null): array
{
    $current??=ep_current();$status=ep_status($draft['id'],$current);
    return agent_email_preferences_prepare_v1(db(),pec_user_v1(),$GLOBALS['epPrincipal'],11,
        ep_prepare_input($status),$current,$inference??'ep_infer');
}
function ep_accept_input(array $status,?string $preference=null): array
{
    return ['draft_id'=>$status['draft_id'],'proposal_id'=>$status['proposal']['id'],
        'proposal_revision'=>$status['proposal']['revision'],'soul_revision'=>$status['soul_revision'],
        'preference'=>$preference??$status['proposal']['preference']];
}
function ep_accept(array $status,?Closure $current=null,?string $preference=null): array
{
    return agent_email_preferences_accept_v1(db(),pec_user_v1(),$GLOBALS['epPrincipal'],11,
        ep_accept_input($status,$preference),$current??ep_current());
}
function ep_soul(): array {return agent_brain_soul_profile_v1(pec_user_v1(),'email');}
function ep_authority_rows(): array
{
    $tables=['agent_connection_email_drafts_v1','agent_connection_sends_v1','agent_connection_email_followups_v1',
        'vp3_phone_agent_email_call_reviews_v1'];$result=[];
    foreach ($tables as $table) if (table_exists($table)) $result[$table]=db()->query('SELECT * FROM '.$table.' ORDER BY 1')->fetchAll();
    return $result;
}

$GLOBALS['epChecks']=0;$GLOBALS['epWriterCalls']=0;$GLOBALS['epLearningCalls']=0;
$GLOBALS['pecRelativeUrls']=true;
$GLOBALS['epWriterPrompts']=[];$GLOBALS['epLearningPrompts']=[];
$f=pec_fixture_v1();$pdo=$f['pdo'];ep_session();
$GLOBALS['epPrincipal']=vp3_agent_chat_principal_v380(pec_user_v1(),vp3_agent_chat_resolve_agent_v380($pdo,pec_user_v1(),7));
agent_email_preferences_install_v1($pdo);
ep_check(agent_email_preferences_ready_v1($pdo),'native preference schema is ready');
$initial=ep_soul();$callback=ep_current();
ep_check($callback()===true && session_status()!==PHP_SESSION_ACTIVE,'request guard reopens the actual owner session and releases its lock');

$manual=ep_action(['action'=>'compose_create','request_id'=>bin2hex(random_bytes(16))]);
$manual=ep_save($manual,'An owner-written message.','Manual message','recipient@example.test');
$manualStatus=ep_status($manual['id']);
ep_check(!$manualStatus['eligible'] && $manualStatus['reason']==='generated_baseline_unavailable'
    && $manualStatus['capture_id']===null,'manual text is never presented as generated provenance');
$generated=ep_generate();$capture=agent_email_preferences_capture_row_v1($pdo,$generated['id']);
$baseline=json_decode(ai_decrypt_secret($capture['generated_enc']),true,8,JSON_THROW_ON_ERROR);
ep_check($baseline===['subject'=>$generated['subject'],'body'=>$generated['body']]
    && !str_contains($capture['generated_enc'],$generated['body']) && $capture['edited_hash']===null,
    'successful native generation atomically captures only its encrypted subject and body');
ep_check((int)$capture['generated_soul_version']===$initial['version']
    && $capture['generated_soul_revision']===$initial['revision']
    && ep_status($generated['id'])['reason']==='owner_edit_required','generated baseline records the exact Soul and requires a later owner edit');
$saved=ep_save($generated,'Hi, could you share a convenient time? Thanks.');$edited=ep_status($saved['id']);
ep_check($edited['eligible'] && $edited['proposal']===null && $GLOBALS['epLearningCalls']===0
    && ep_soul()['revision']===$initial['revision'],'ordinary Save records an eligible edit without inference or learned preferences');
$capture=agent_email_preferences_capture_row_v1($pdo,$saved['id']);
ep_check(!array_key_exists('edited_enc',$capture) && $capture['edited_hash']!==$capture['generated_hash']
    && $capture['current_revision']===$saved['edit_revision'],'owner edits use the canonical saved draft and a semantic hash, without a second body copy');

$ready=ep_prepare($saved);$calls=$GLOBALS['epLearningCalls'];$repeated=ep_prepare($saved);
ep_check($ready['proposal']['state']==='ready' && $repeated['proposal']===$ready['proposal'] && $calls===1
    && $GLOBALS['epLearningCalls']===$calls,'repeating one exact before-and-after pair reuses its suggestion without another dispatch');
$saved=ep_save(ep_review($saved['id']),$saved['body']);$resaved=ep_prepare($saved);
ep_check($resaved['proposal']===$ready['proposal'] && $resaved['capture_id']===$ready['capture_id']
    && $GLOBALS['epLearningCalls']===$calls,'a no-op Save changes native approval ciphertext without creating a second learning pair or dispatch');
$prompt=$GLOBALS['epLearningPrompts'][0];$serialized=json_encode($prompt,JSON_THROW_ON_ERROR);
ep_check(array_keys($prompt)===['email_soul','generated_text','owner_edited_text']
    && $prompt['generated_text']===$baseline && $prompt['owner_edited_text']['body']===$saved['body']
    && !str_contains($serialized,'recipient@example.test') && !str_contains($serialized,'BODY_SENTINEL_PRIVATE_MAIL')
    && !str_contains($serialized,str_repeat('a',64)),'suggestion receives the proven pair and Email Soul, with no recipient, mailbox, credential or Chat history');
$public=json_encode($ready,JSON_THROW_ON_ERROR);
ep_check(!str_contains($public,$saved['body']) && !str_contains($public,$generated['body'])
    && !str_contains($public,'generated_enc'),'status returns no captured email text');
$before=ep_authority_rows();$approved=ep_accept($ready,null,'Use a warm, direct tone. Keep the thank-you brief.');
ep_check($approved['proposal']['state']==='accepted' && ep_soul()['version']===$initial['version']+1
    && str_contains(ep_soul()['content'],'Use a warm, direct tone. Keep the thank-you brief.'),
    'explicit acceptance saves the owner-reviewed wording as one new Email Soul version');
ep_check(ep_authority_rows()===$before && ep_review($saved['id'])['edit_revision']===$saved['edit_revision'],
    'remembering a preference leaves the exact draft, send approvals, watches and provider ledger unchanged');
$acceptedRevision=ep_soul()['revision'];$retry=ep_accept($ready);
ep_check($retry['proposal']['state']==='accepted' && ep_soul()['revision']===$acceptedRevision
    && $GLOBALS['epLearningCalls']===$calls,'an acceptance replay cannot append a second rule or spend another model call');

$dismissedDraft=ep_edited();$dismissReady=ep_prepare($dismissedDraft);$beforeSoul=ep_soul();
$dismissInput=['draft_id'=>$dismissedDraft['id'],'proposal_id'=>$dismissReady['proposal']['id'],'proposal_revision'=>$dismissReady['proposal']['revision']];
$dismissed=agent_email_preferences_dismiss_v1($pdo,pec_user_v1(),$GLOBALS['epPrincipal'],11,$dismissInput,ep_current());
$calls=$GLOBALS['epLearningCalls'];$dismissReplay=ep_prepare($dismissedDraft);
ep_check($dismissed['proposal']['state']==='dismissed' && $dismissReplay['proposal']['state']==='dismissed'
    && $GLOBALS['epLearningCalls']===$calls && ep_soul()['revision']===$beforeSoul['revision'],
    'dismissing a suggestion leaves the Soul unchanged and does not redispatch the same pair');
ep_reject([409],static fn()=>ep_accept($dismissed),'a dismissed suggestion cannot be published');

$factDraft=ep_edited('Please use the updated total of $425 and meet on Friday.');$beforeSoul=ep_soul();
$factStatus=ep_prepare($factDraft,static function(string $prompt,string $protocol,Closure $before,array $job,bool $transient):string{
    ep_infer($prompt,$protocol,$before,$job,$transient);return '{"category":null,"preference":""}';
});
ep_check($factStatus['proposal']['state']==='no_style_change' && ep_soul()['revision']===$beforeSoul['revision'],
    'a one-off factual edit can return no writing preference and never changes the Soul');
$unsafeDraft=ep_edited('Please send the total to billing@example.test.');
$unsafe=ep_prepare($unsafeDraft,static function(string $prompt,string $protocol,Closure $before,array $job,bool $transient):string{
    ep_infer($prompt,$protocol,$before,$job,$transient);
    return '{"category":"word_choice","preference":"Always send totals to billing@example.test."}';
});
ep_check($unsafe['proposal']['state']==='failed' && $unsafe['proposal']['reason']==='unsafe_suggestion'
    && ep_soul()['revision']===$beforeSoul['revision'],'model suggestions cannot silently turn a concrete recipient into a writing rule');

$noChange=ep_generate();$noChange=ep_save($noChange,$noChange['body']);
ep_check(ep_status($noChange['id'])['reason']==='owner_edit_required','saving unchanged generated text is not a style edit');
$regeneratedDraft=ep_edited();$old=ep_prepare($regeneratedDraft);$oldCapture=$old['capture_id'];
$regeneratedDraft=ep_generate(ep_review($regeneratedDraft['id']));
$regeneratedDraft=ep_save($regeneratedDraft,'Hi, could you share a convenient time? Thanks.');$new=ep_status($regeneratedDraft['id']);
ep_check($new['capture_id']!==$oldCapture && $new['eligible'] && $new['proposal']===null,
    'a new successful generation has new provenance even when its generated and edited text repeat');
ep_reject([409],static fn()=>ep_accept($old),'an old generation suggestion cannot be applied to the new generation');

$concurrentDraft=ep_edited();$concurrentInput=ep_prepare_input(ep_status($concurrentDraft['id']));$current=ep_current();$nested=null;
$concurrent=agent_email_preferences_prepare_v1($pdo,pec_user_v1(),$GLOBALS['epPrincipal'],11,$concurrentInput,$current,
    static function(string $prompt,string $protocol,Closure $before,array $job,bool $transient)use($concurrentInput,$current,&$nested):string{
        $nested=agent_email_preferences_prepare_v1(db(),pec_user_v1(),$GLOBALS['epPrincipal'],11,$concurrentInput,$current,
            static fn()=>throw new LogicException('A concurrent prepare dispatched twice.'));
        return ep_infer($prompt,$protocol,$before,$job,$transient);
    });
ep_check($nested['proposal']['state']==='preparing' && $nested['proposal']['id']===$concurrent['proposal']['id']
    && $concurrent['proposal']['state']==='ready','a concurrent prepare observes the durable claim instead of issuing a second request');
$doubleDraft=ep_edited();$double=ep_prepare($doubleDraft,
    static function(string $prompt,string $protocol,Closure $before,array $job,bool $transient):string{
        ep_infer($prompt,$protocol,$before,$job,$transient);$before();return '{}';
    });
ep_check($double['proposal']['state']==='failed',
    'a second model boundary invocation is denied and cannot produce a ready suggestion');
$calls=$GLOBALS['epLearningCalls'];ep_prepare($doubleDraft);
ep_check($GLOBALS['epLearningCalls']===$calls,'a failed dispatched attempt is never automatically repeated');
$lostDraft=ep_edited();$lost=ep_prepare($lostDraft,
    static function(string $prompt,string $protocol,Closure $before,array $job,bool $transient):string{
        ep_infer($prompt,$protocol,$before,$job,$transient);throw new RuntimeException('Fixture transport response lost.');
    });
$calls=$GLOBALS['epLearningCalls'];ep_prepare($lostDraft);
ep_check($lost['proposal']['state']==='failed' && $lost['proposal']['reason']==='dispatch_uncertain'
    && $GLOBALS['epLearningCalls']===$calls,'an uncertain model dispatch has a durable failure and cannot automatically spend another request');

// Capture real session state, then mutate it after the request was authenticated.
foreach (['user_id'=>2,'csrf_token'=>'rotated-csrf','agent_connection_email_review_v1'=>'rotated-review',
    'auth_password_fingerprint'=>str_repeat('f',64)] as $field=>$changed) {
    ep_session();$current=ep_current();session_start();$_SESSION[$field]=$changed;session_write_close();
    ep_reject([401],static fn()=>$current(),'request guard rejects changed session '.$field);
}
ep_session();$current=ep_current();session_start();$_SESSION=[];session_write_close();
ep_reject([401],static fn()=>$current(),'logout removes private preference authority even while fixture current_user has a default');
ep_session();$current=ep_current();$pdo->exec("UPDATE users SET password_hash='changed-password' WHERE id=1");
ep_reject([401],static fn()=>$current(),'password rotation invalidates a previously authenticated preference request');
$pdo->exec("UPDATE users SET password_hash='owner-fixture-password' WHERE id=1");ep_session();
$current=ep_current();$pdo->exec('UPDATE users SET is_active=0 WHERE id=1');
ep_reject([401],static fn()=>$current(),'disabled owner account cannot reuse an authenticated request');
$pdo->exec('UPDATE users SET is_active=1 WHERE id=1');ep_session();

$scopeDraft=ep_edited();$scopeInput=ep_prepare_input(ep_status($scopeDraft['id']));$calls=$GLOBALS['epLearningCalls'];
ep_reject([404],static fn()=>agent_email_preferences_status_v1($pdo,pec_user_v1(),$GLOBALS['epPrincipal'],12,$scopeDraft['id'],ep_current(null,null,12)),
    'a different private Chat cannot inspect the first Chat capture');
$otherPrincipal=vp3_agent_chat_principal_v380(pec_user_v1(),vp3_agent_chat_resolve_agent_v380($pdo,pec_user_v1(),8));
ep_reject([404],static fn()=>agent_email_preferences_status_v1($pdo,pec_user_v1(),$otherPrincipal,13,$scopeDraft['id'],ep_current(null,$otherPrincipal,13)),
    'a different selected Agent cannot inspect the first Agent capture');
ep_session(2);$foreign=vp3_agent_chat_principal_v380(pec_user_v1(2),vp3_agent_chat_resolve_agent_v380($pdo,pec_user_v1(2),9));
ep_reject([404],static fn()=>agent_email_preferences_status_v1($pdo,pec_user_v1(2),$foreign,21,$scopeDraft['id'],ep_current(pec_user_v1(2),$foreign,21)),
    'a second authenticated owner cannot inspect the first owner capture');ep_session();
foreach (['send_enabled'=>0,'authorization_key'=>str_repeat('b',64),'client_fingerprint'=>str_repeat('e',64),
    'account_email'=>'other@example.test'] as $column=>$value) {
    $original=$pdo->query('SELECT '.$column.' FROM agent_connections_v1 WHERE id=7')->fetchColumn();
    $pdo->prepare('UPDATE agent_connections_v1 SET '.$column.'=? WHERE id=7')->execute([$value]);
    ep_reject([401,403,409],static fn()=>agent_email_preferences_prepare_v1($pdo,pec_user_v1(),$GLOBALS['epPrincipal'],11,$scopeInput,ep_current(),'ep_infer'),
        'changed Gmail '.$column.' blocks preference inference');
    $pdo->prepare('UPDATE agent_connections_v1 SET '.$column.'=? WHERE id=7')->execute([$original]);
}
ep_check($GLOBALS['epLearningCalls']===$calls,'scope and Gmail authority failures spend no inference');
$current=ep_current();$pdo->exec('UPDATE chat_conversations SET user_agent_id=8 WHERE id=11');
ep_reject([403,409],static fn()=>$current(),'moving the private Chat to another Agent invalidates the captured request');
$pdo->exec('UPDATE chat_conversations SET user_agent_id=7 WHERE id=11');

$staleEdit=ep_edited();$beforeSoul=ep_soul();
$editedDuring=ep_prepare($staleEdit,static function(string $prompt,string $protocol,Closure $before,array $job,bool $transient)use($staleEdit):string{
    $answer=ep_infer($prompt,$protocol,$before,$job,$transient);
    ep_save(ep_review($staleEdit['id']),'The owner replaced this text while the suggestion was being prepared.');return $answer;
});
$staleProposal=$pdo->query("SELECT * FROM agent_email_preference_proposals_v1 WHERE draft_id='".$staleEdit['id']."'")->fetch();
ep_check($editedDuring['proposal']===null && $staleProposal['state']==='failed' && $staleProposal['reason']==='source_changed'
    && ep_soul()['revision']===$beforeSoul['revision'],'an owner edit during inference prevents a stale suggestion from being exposed or learned');
$soulRace=ep_edited();$soulDuring=ep_prepare($soulRace,
    static function(string $prompt,string $protocol,Closure $before,array $job,bool $transient):string{
        $answer=ep_infer($prompt,$protocol,$before,$job,$transient);$profile=ep_soul();
        agent_brain_soul_update_v1(pec_user_v1(),'email',$profile['revision'],'save',$profile['content']."\nOwner changed this profile concurrently.\n");return $answer;
    });
ep_check($soulDuring['proposal']['state']==='failed' && $soulDuring['proposal']['reason']==='soul_changed',
    'a concurrent Soul change makes an in-flight suggestion stale');
$staleSoul=ep_prepare(ep_edited());$profile=ep_soul();
agent_brain_soul_update_v1(pec_user_v1(),'email',$profile['revision'],'restore',0);
ep_reject([409],static fn()=>ep_accept($staleSoul),'accept cannot overwrite a concurrent owner restore');
$restored=ep_soul();$replayedAccepted=ep_accept($ready);
ep_check($replayedAccepted['proposal']['state']==='accepted' && ep_soul()['revision']===$restored['revision'],
    'replaying an earlier accepted preference after restore acknowledges its receipt without re-appending it');

// A real edit after the durable publication intent must be fenced before any file write.
$publicationDraft=ep_edited();$publicationReady=ep_prepare($publicationDraft);$profile=ep_soul();$live=ep_current();$changed=false;
$raceCurrent=static function()use($live,$pdo,$publicationDraft,$publicationReady,&$changed):bool{
    $live();
    if (!$changed && !$pdo->inTransaction()) {
        $row=agent_email_preferences_proposal_row_v1($pdo,1,$publicationReady['proposal']['id']);
        if (($row['state']??null)==='publishing') {
            $changed=true;ep_save(ep_review($publicationDraft['id']),'A different owner edit made after the durable publication intent.');
        }
    }
    return true;
};
$raced=ep_accept($publicationReady,$raceCurrent);
ep_check($changed && !$pdo->inTransaction() && ep_soul()['revision']===$profile['revision']
    && $raced['proposal']['state']==='publishing' && $raced['proposal']['reason']==='source_changed',
    'final publication locks fence an actual post-intent save without leaking a transaction or changing the Soul');
ep_reject([409],static fn()=>ep_accept($raced),'a durable intent cannot retry after its actual edited source changed');

// Make file publication succeed and the SQL acknowledgment fail, then recover
// from the immutable version instead of repeating the append.
$uncertainReady=ep_prepare(ep_edited());$profile=ep_soul();
$pdo->exec("CREATE TRIGGER ep_block_accepted BEFORE UPDATE ON agent_email_preference_proposals_v1 FOR EACH ROW
    BEGIN IF NEW.state='accepted' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture publication receipt fault'; END IF; END");
$failed=false;try {ep_accept($uncertainReady);} catch (PDOException $error) {
    if (($error->errorInfo[0]??null)!=='45000') throw $error;$failed=true;
} finally {$pdo->exec('DROP TRIGGER ep_block_accepted');}
$pending=agent_email_preferences_proposal_row_v1($pdo,1,$uncertainReady['proposal']['id']);
ep_check($failed && !$pdo->inTransaction() && $pending['state']==='publishing' && ep_soul()['version']===$profile['version']+1,
    'a failed SQL acknowledgment preserves a durable publishing intent after the actual immutable file was written');
$published=ep_soul();$recovered=ep_status($uncertainReady['draft_id']);
ep_check($recovered['proposal']['state']==='accepted' && $recovered['proposal']['accepted_version']===$published['version']
    && ep_accept($uncertainReady)['proposal']['state']==='accepted' && ep_soul()['revision']===$published['revision'],
    'status and explicit retry reconcile an ambiguous commit from exact immutable content without another append');

$settings=agent_email_preferences_settings_get_v1(pec_user_v1(),[],ep_current());
ep_check($settings['revision']===ep_soul()['revision'] && $settings['version']===ep_soul()['version']
    && $settings['history']!==[],'settings expose the canonical versioned Email Soul history');
$changedSettings=agent_email_preferences_settings_update_v1($pdo,pec_user_v1(),'preferences_save',
    ['soul_revision'=>$settings['revision'],'text'=>"Use short paragraphs and a friendly greeting.\n"],ep_current());
ep_check($changedSettings['version']===$settings['version']+1 && str_contains($changedSettings['text'],'short paragraphs'),
    'explicit settings save changes the canonical profile with the owner revision');
ep_reject([409],static fn()=>agent_email_preferences_settings_update_v1($pdo,pec_user_v1(),'preferences_reset',
    ['soul_revision'=>$settings['revision']],ep_current()),'stale settings reset cannot erase a newer owner profile');
$profile=ep_soul();$live=ep_current();$checks=0;
$settingsRace=static function()use($live,$pdo,&$checks):bool{
    $live();++$checks;
    if ($checks===2 && !$pdo->inTransaction()) $pdo->exec('UPDATE users SET is_active=0 WHERE id=1');
    return true;
};
ep_reject([401],static fn()=>agent_email_preferences_settings_update_v1($pdo,pec_user_v1(),'preferences_save',
    ['soul_revision'=>$profile['revision'],'text'=>'This revoked edit must never become a version.'],$settingsRace),
    'manual preference management rechecks live authority under final local publication locks');
$pdo->exec('UPDATE users SET is_active=1 WHERE id=1');
ep_check(!$pdo->inTransaction() && ep_soul()['revision']===$profile['revision'],
    'a revoked manual settings publication writes no Soul version and leaks no transaction');

// All four native writers must observe the same explicitly accepted preference
// and capture their own successful output, not a test-only prompt projection.
$sharedText='Use a relaxed greeting and short paragraphs.';
$sharedReady=ep_prepare(ep_edited());ep_accept($sharedReady,null,$sharedText);$sharedSoul=ep_soul();
$writerDraft=ep_generate();$last=end($GLOBALS['epWriterPrompts']);$writerCapture=agent_email_preferences_capture_row_v1($pdo,$writerDraft['id']);
ep_check(str_contains($last['email_soul'],$sharedText) && $writerCapture['generated_soul_revision']===$sharedSoul['revision'],
    'the actual new-mail writer reads an accepted preference and captures the same Soul provenance');

$replyMessage=pec_message_v1('preference_reply','preference_reply_thread',time()-120);
$GLOBALS['pecMessages']['preference_reply']=$replyMessage;$GLOBALS['pecThreads']['preference_reply_thread']=[$replyMessage];
$pdo->exec("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json) VALUES(11,1,'user','Draft a short reply.','{}')");
$replyMarker=(int)$pdo->lastInsertId();$replyPrompt=null;
$replyResult=agent_connection_email_chat_reply_v1($pdo,pec_user_v1(),$GLOBALS['epPrincipal'],11,$replyMarker,
    ['mode'=>'draft','message_id'=>'preference_reply','instruction'=>'Acknowledge and ask for a convenient time.'],
    agent_connections_find_v1($pdo,1,7),null,'pec_http_v1',
    static function(string $json,array $service,array $control)use(&$replyPrompt):array{
        if (db()->inTransaction() || !$control['before_gmail_reply_dispatch']()) throw new LogicException('Reply dispatch was not current.');
        $replyPrompt=json_decode($json,true,16,JSON_THROW_ON_ERROR);
        return ['ok'=>true,'answer'=>'{"body":"Hi, thanks for the note. What time suits you?"}'];
    });
$replyId=$replyResult['actions'][0]['id'];$replyCapture=agent_email_preferences_capture_row_v1($pdo,$replyId);
ep_check(str_contains($replyPrompt['email_soul'],$sharedText) && $replyCapture['generated_soul_revision']===$sharedSoul['revision']
    && json_decode(ai_decrypt_secret($replyCapture['generated_enc']),true)['body']==='Hi, thanks for the note. What time suits you?',
    'the actual Gmail reply writer uses the same preference and captures its successful native prepared result');

$pdo->prepare('UPDATE agent_connection_gmail_inbox_v1 SET rules_json=? WHERE owner_user_id=1')->execute([
    json_encode(agent_connection_gmail_inbox_rules_v1(['triage_enabled'=>'1','content_analysis'=>'1']),JSON_THROW_ON_ERROR)]);
$triage=pec_attention_v1($f,'preference_organic',time()-90);$policy=agent_connection_gmail_inbox_policy_v1($pdo,1);
$primary='gmail:'.agent_email_matters_source_key_v1($policy,'thread','thread_preference_organic');
$pdo->exec("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json) VALUES(11,1,'user','Yes, offer Friday.','{}')");
$organicMarker=(int)$pdo->lastInsertId();
$evidence=[$primary=>['specialist'=>'gmail','summary'=>'The owner requested a scheduling reply.','priority'=>'normal','due_at'=>null,
    'source_action'=>['type'=>'gmail_triage','item_ref'=>$triage,'generation'=>agent_connection_gmail_inbox_notice_generation_v1($policy)]]];
$goal=['tool'=>'coordination.review','gmail_reply_draft'=>true,'primary'=>$primary,'coordination_id'=>str_repeat('e',64),
    'sources'=>[$primary=>str_repeat('f',64)],'binding'=>['agent_id'=>7,'conversation_id'=>11],
    'followthrough'=>['reply_message_id'=>$organicMarker],'gmail_reply_question'=>'Should I offer Friday?'];
$continuation=['goal'=>['primary'=>$primary,'coordination_route'=>['action'=>'ask']],'continuation_owner_reply'=>'Yes, offer Friday.'];
$organicPrompt=null;
$prepared=vp3_coordination_gmail_draft_prepare_v1($pdo,pec_user_v1(),$goal,$evidence,$continuation,
    static function(string $json,string $protocol)use(&$organicPrompt):string{
        if (db()->inTransaction() || $protocol!=='vp3.initiative-draft.v1') throw new LogicException('Organic writer boundary changed.');
        $organicPrompt=json_decode($json,true,16,JSON_THROW_ON_ERROR);return '{"draft":"Hi, Friday works for me. What time suits you?"}';
    },'pec_http_v1');
ep_check(agent_email_preferences_capture_row_v1($pdo,$prepared['draft']['id'])===null,
    'an organic model response alone is not durable generated provenance');
agent_commerce_atomic_pa2($pdo,static fn()=>vp3_coordination_gmail_draft_store_v1($pdo,pec_user_v1(),$goal,$evidence,$prepared['draft']));
$organicId=$prepared['draft']['id'];$organicCapture=agent_email_preferences_capture_row_v1($pdo,$organicId);
ep_check(str_contains($organicPrompt['email_soul'],$sharedText) && $organicCapture['generated_soul_revision']===$sharedSoul['revision']
    && agent_connection_email_chat_payload_v1(ep_row($organicId))['composer']['origin']==='organic_reply',
    'the actual organic writer stores its exact baseline with the shared accepted Email Soul');

// Native Initiative memory and the verified original-send/watch/deadline chain.
require_once dirname(__DIR__).'/includes/agent-email-followthrough-draft-v1.php';
require_once dirname(__DIR__).'/includes/agent-brain-v122.php';
require_once dirname(__DIR__).'/includes/agent-memory-scope-v410.php';
$pdo->exec("CREATE TABLE agent_chat_archive(id BIGINT UNSIGNED PRIMARY KEY,user_agent_id BIGINT NULL,created_at DATETIME,
        INDEX idx_agent_archive_agent_created_v410(user_agent_id,created_at));
    CREATE TABLE agent_memory_items(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT UNSIGNED,user_agent_id BIGINT NULL,
        memory_type VARCHAR(40),subject VARCHAR(190),memory_text TEXT,memory_hash VARCHAR(64),memory_scope_version INT DEFAULT 410,
        source_archive_id BIGINT NULL,confidence DOUBLE,occurrence_count INT,first_seen_at DATETIME,last_seen_at DATETIME,is_active INT,metadata_json TEXT,
        UNIQUE(user_id,memory_hash),INDEX idx_agent_memory_agent_type_v410(user_agent_id,memory_type),
        INDEX idx_agent_memory_agent_occurrence_v410(user_agent_id,occurrence_count))");
$initiative=['enabled'=>true,'owner_user_id'=>1,'agent_id'=>7,'conversation_id'=>11,'revision'=>bin2hex(random_bytes(16)),
    'screen_enabled'=>false,'knowledge_scope'=>['mode'=>'off','folder_id'=>0],'allow_auto_drafts'=>false];
$policyId=vp3_initiative_in_scope_v1(pec_user_v1(),7,static fn()=>agent_brain_v122_upsert_system_memory(pec_user_v1(),
    'initiative_policy','owner-opt-in','Owner selected Initiative policy.',$initiative));
if ($policyId<1) throw new RuntimeException('Native Initiative policy did not persist.');
agent_email_followthrough_install_v1($pdo);$sentAt=time()-600;
$pdo->exec("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json) VALUES(11,1,'user','Send my original request.','{}')");
$originMarker=(int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json)
    VALUES(11,1,'user','Set a response deadline and prepare a short follow-up.','{\"source\":\"agent_email_followthrough_deadline\",\"skip_brain_archive\":true}')");
$deadlineMarker=(int)$pdo->lastInsertId();$origin=bin2hex(random_bytes(16));$operation=agent_connection_email_chat_operation_key_v1($origin);
$sentRaw=pec_message_v1('preference_sent','preference_sent_thread',$sentAt,'owner@example.test',['SENT'],'Follow-up request');
$sentRaw['payload']['headers'][1]['value']='recipient@example.test';
$GLOBALS['pecMessages']['preference_sent']=$sentRaw;$GLOBALS['pecThreads']['preference_sent_thread']=[$sentRaw];
$original=['subject'=>'Follow-up request','body'=>'An earlier request.','composer'=>['version'=>1,'saved_at'=>$sentAt]];
$pdo->prepare("INSERT INTO agent_connection_email_drafts_v1(id,owner_user_id,agent_id,conversation_id,user_message_id,
    connection_id,authorization_key,account_email,recipient,message_enc,status,expires_at,sent_message_id)
    VALUES(?,1,7,11,?,7,?,'owner@example.test','recipient@example.test',?,'sent',0,'preference_sent')")
    ->execute([$origin,$originMarker,str_repeat('a',64),ai_encrypt_secret(json_encode($original,JSON_THROW_ON_ERROR))]);
$receipt=['operation_key'=>$operation,'authorization_key'=>str_repeat('a',64),'connection_id'=>7,'from'=>'owner@example.test',
    'to'=>'recipient@example.test','message_id'=>'preference_sent','thread_id'=>'preference_sent_thread','sent_at'=>$sentAt];
$pdo->prepare("INSERT INTO agent_connection_sends_v1(owner_user_id,connection_id,authorization_key,operation_key,payload_digest,
    status,provider_message_id,provider_thread_id,sent_at) VALUES(1,7,?,?,?,'sent','preference_sent','preference_sent_thread',?)")
    ->execute([str_repeat('a',64),$operation,hash('sha256','preference original receipt'),$sentAt]);
if (!agent_connection_email_followup_register_v1($pdo,1,7,11,$receipt,['draft_id'=>$origin,'user_message_id'=>$originMarker]))
    throw new RuntimeException('Native follow-up watch did not register.');
$deadlineSettings=['enabled'=>true,'deadline_at'=>gmdate('Y-m-d\TH:i:s\Z',time()-120),'time_zone'=>'UTC','prepare_if_overdue'=>true,
    'priority'=>'normal','user_message_id'=>$deadlineMarker];
$followthrough=agent_commerce_atomic_pa2($pdo,static fn()=>agent_email_followthrough_register_v1($pdo,ep_row($origin),$receipt,$deadlineSettings));
agent_email_followthrough_check_v1($pdo,pec_user_v1(),$GLOBALS['epPrincipal'],11,
    ['followthrough_ref'=>$followthrough['id'],'expected_revision'=>(int)$followthrough['revision']],str_repeat('k',32),'pec_http_v1');
$claim=bin2hex(random_bytes(16));
$pdo->prepare("UPDATE agent_connection_email_followups_v1 SET status='waiting',claim_key=?,next_check_at=? WHERE owner_user_id=1 AND operation_key=?")
    ->execute([$claim,gmdate('Y-m-d H:i:s',time()+120),$operation]);
$followthrough=vp3_phone_sql_v1($pdo,'SELECT * FROM agent_email_followthrough_v1 WHERE id=?',[$followthrough['id']])->fetch();
$watch=vp3_phone_sql_v1($pdo,'SELECT * FROM agent_connection_email_followups_v1 WHERE owner_user_id=1 AND operation_key=?',[$operation])->fetch();
$followPrompt=null;
agent_email_followthrough_prepare_v1($pdo,$followthrough,$watch,[],$claim,'pec_http_v1',
    static function(string $json,string $protocol,callable $before,array $job,bool $transient)use(&$followPrompt):string{
        if (db()->inTransaction() || $protocol!=='vp3.initiative-draft.v1' || empty($job['background']) || !$transient)
            throw new LogicException('Follow-up writer boundary changed.');
        $before();$followPrompt=json_decode($json,true,16,JSON_THROW_ON_ERROR);
        return '{"draft":"Hi, following up on my earlier request. Could you share an update?"}';
    });
$followId=agent_email_followthrough_draft_id_v1($followthrough);$followCapture=agent_email_preferences_capture_row_v1($pdo,$followId);
ep_check(is_array($followPrompt) && str_contains($followPrompt['email_soul'],$sharedText) && $followCapture!==null
    && $followCapture['generated_soul_revision']===$sharedSoul['revision']
    && agent_connection_email_chat_payload_v1(ep_row($followId))['composer']['origin']==='outbound_followup',
    'the actual deadline follow-up writer uses the shared preference and atomically captures its reviewed draft');
foreach (['reply'=>$replyId,'organic reply'=>$organicId,'follow-up'=>$followId] as $kind=>$id) {
    $draft=ep_review($id);$draft=ep_save($draft,$draft['body']."\nThank you for your help.");
    ep_check(ep_status($id)['eligible'],'native owner edits remain learnable for a generated '.$kind);
}

require_once dirname(__DIR__).'/includes/agent-gmail-workspace-context-v1.php';
$proposalCount=(int)$pdo->query('SELECT COUNT(*) FROM agent_email_preference_proposals_v1')->fetchColumn();
$calls=$GLOBALS['epLearningCalls'];$httpCalls=$GLOBALS['pecHttpCalls'];$profile=ep_soul();
$context=agent_gmail_workspace_context_v1($pdo,pec_user_v1(),$GLOBALS['epPrincipal'],11);
ep_check($context['capabilities']['writing_preferences']['available']
    && !str_contains(json_encode($context['capabilities']['writing_preferences'],JSON_THROW_ON_ERROR),$sharedText)
    && (int)$pdo->query('SELECT COUNT(*) FROM agent_email_preference_proposals_v1')->fetchColumn()===$proposalCount
    && $GLOBALS['epLearningCalls']===$calls && $GLOBALS['pecHttpCalls']===$httpCalls && ep_soul()['revision']===$profile['revision'],
    'automatic specialist context advertises explicit preference controls without reading bodies, generating suggestions or changing Soul');

// The Phone host remains bound to one actual native item and live enrollment;
// the preference flow never acquires a durable holder-only authority.
require_once dirname(__DIR__).'/includes/phone-agent-workspace-native-v1.php';
$phoneDraft=ep_edited();$phoneReady=ep_prepare($phoneDraft);ep_session();
$indexed=vp3_phone_agent_workspace_ingest_v1($pdo,1,7,[['source_kind'=>'gmail_draft','source_id'=>$phoneDraft['id']]],time());
$_COOKIE['vp3_phone_device_v1']=$f['token'];
$scope=vp3_phone_agent_workspace_scope_v1($pdo,pec_user_v1(),$f['token'],$f['device_id']);
$native=vp3_phone_agent_native_context_v1($pdo,pec_user_v1(),[
    'device_id'=>$f['device_id'],'item_ref'=>$indexed[0]['item_ref'],'scope_revision'=>$scope['scope_revision']]);
foreach (['preference_status','preference_prepare','preference_accept','preference_dismiss',
    'preferences_get','preferences_save','preferences_restore','preferences_reset'] as $action) {
    $input=['action'=>$action,'agent_id'=>7,'conversation_id'=>11,'draft_id'=>$phoneDraft['id']];
    vp3_phone_agent_native_action_v1($pdo,$native,'email',$input);
    $input['draft_id']=$writerDraft['id'];
    ep_reject([403],static fn()=>vp3_phone_agent_native_action_v1($pdo,$native,'email',$input),
        'Phone '.$action.' cannot escape its exact displayed draft');
}
$GLOBALS['VP3_PHONE_AGENT_NATIVE_EXECUTION_CONTEXT_V1']=['kind'=>'email','context'=>$native];$current=ep_current();
ep_check($current()===true,'the preference guard accepts the real owner session and exact enrolled Phone item');
$profile=ep_soul();
$pdo->prepare('UPDATE vp3_phone_agent_enrollments_v1 SET enabled=0 WHERE owner_user_id=1 AND device_id=?')->execute([$f['device_id']]);
ep_reject([403,409],static fn()=>ep_accept($phoneReady,$current),'revoking Phone enrollment blocks preference publication');
ep_check(ep_soul()['revision']===$profile['revision'] && !$pdo->inTransaction(),
    'revoked Phone publication changes neither Soul nor native transaction state');
$pdo->prepare('UPDATE vp3_phone_agent_enrollments_v1 SET enabled=1 WHERE owner_user_id=1 AND device_id=?')->execute([$f['device_id']]);
$current=ep_current();$pdo->prepare("UPDATE vp3_phone_devices_v1 SET device_status='revoked' WHERE id=?")->execute([$f['device_id']]);
ep_reject([403,409],static fn()=>$current(),'revoking the paired credential invalidates an existing preference request');
$pdo->prepare("UPDATE vp3_phone_devices_v1 SET device_status='approved' WHERE id=?")->execute([$f['device_id']]);
unset($GLOBALS['VP3_PHONE_AGENT_NATIVE_EXECUTION_CONTEXT_V1']);

// A desktop session still being valid must not substitute for a revoked Gmail
// grant between the initial source read and the final private response check.
$live=ep_current();$checks=0;
$finalGrant=static function()use($live,$pdo,&$checks):bool{
    ++$checks;
    if ($checks===2) $pdo->exec('UPDATE agent_connections_v1 SET send_enabled=0 WHERE id=7');
    return $live();
};
try {
    ep_reject([403,409],static fn()=>ep_status($phoneDraft['id'],$finalGrant),
        'final private status revalidates the actual Gmail grant after its owner-session fence');
} finally {$pdo->exec('UPDATE agent_connections_v1 SET send_enabled=1 WHERE id=7');}

ep_check(!$pdo->inTransaction(),'all preference operations finish without an open transaction');

echo 'PASS native email preference acceptance '.$GLOBALS['epChecks'].' checks'.PHP_EOL;
