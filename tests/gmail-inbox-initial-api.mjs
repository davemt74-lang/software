import assert from 'node:assert/strict';
import {spawn} from 'node:child_process';
import {copyFileSync, mkdirSync, mkdtempSync, rmSync, writeFileSync} from 'node:fs';
import {createServer} from 'node:net';
import {tmpdir} from 'node:os';
import {join} from 'node:path';

const root = mkdtempSync(join(tmpdir(), 'vp3-initial-inbox-api-'));
let server;
let logs = '';

try {
  mkdirSync(join(root, 'api'));
  mkdirSync(join(root, 'includes'));
  writeFileSync(join(root, 'includes', 'agent-connection-gmail-link-engagement-v1.php'), `<?php
function vp3_gmail_chat_link_tokens_v1(array $item,array $scope,int $agent,int $cid,int $mid,string $ref): array { return $item; }
function vp3_gmail_curiosity_record_link_v1(PDO $pdo,array $user,int $agent,int $cid,int $mid,?string $ref,string $url,?string $proof,string $label=''): bool {
    if($user['id']!==1)throw new RuntimeException('Foreign mailbox.',403);
    if($agent!==0||$cid!==0||$mid!==0||$ref!==str_repeat('c',64)||$proof!==str_repeat('b',64))throw new RuntimeException('Bad mailbox source.',404);
    header('X-Fixture-Mailbox-Link: verified');return true;
}
`);
  writeFileSync(join(root, 'includes', 'agent-curiosity-email-activity-v1.php'), `<?php
function vp3_curiosity_email_open_observe_v1(PDO $pdo,array $user,string $ref,array $opened,int $agent=0,int $cid=0): void {
    if ($ref!==str_repeat('c',64) || ($opened['subject']??null)!=='Fixture subject') throw new LogicException('Wrong owner open.');
    header('X-Fixture-Curiosity-Open: '.$user['id'].'-'.$agent.'-'.$cid);
}
`);
  copyFileSync(new URL('../api/gmail-inbox-initial-v1.php', import.meta.url), join(root, 'api', 'gmail-inbox-initial-v1.php'));
  writeFileSync(join(root, 'includes', 'bootstrap.php'), `<?php
session_start();
$_SESSION['csrf_token'] = 'fixture-csrf';
function csrf_token(): string { return $_SESSION['csrf_token']; }
function current_user(): ?array {
    return isset($_SERVER['HTTP_X_FIXTURE_ANONYMOUS']) ? null : ['id'=>(int)($_SERVER['HTTP_X_FIXTURE_OWNER'] ?? 1)];
}
function has_permission(string $capability, array $user): bool {
    return $capability !== ($_SERVER['HTTP_X_FIXTURE_DENY'] ?? '');
}
function vp3_participant_scope_v1(): ?array {
    return isset($_SERVER['HTTP_X_FIXTURE_PARTICIPANT']) ? ['participant_id'=>3] : null;
}
function vp3_catch_up_page_binding_v1(array $user): string { return str_repeat($user['id'] === 1 ? 'd' : 'e',64); }
function db(): PDO { return new PDO('sqlite::memory:'); }
`);
  writeFileSync(join(root, 'includes', 'agent-connection-gmail-inbox-status-v1.php'), `<?php
function agent_connection_gmail_inbox_check_overdue_v1(PDO $pdo, array $user, string $checkRef, ?array $chatTarget=null): void {
    fixture_record('check_overdue', $user, $chatTarget === null ? $checkRef : [$checkRef,$chatTarget]);
}
`);
  writeFileSync(join(root, 'includes', 'agent-email-matters-v1.php'), `<?php
function agent_email_matters_update_v1(PDO $pdo,array $user,string $id,int $revision,string $decision,?string $wakeAt): ?array {
    fixture_record('matter_update',$user,[$id,$revision,$decision]);
    if ($user['id'] !== 1 || $id !== str_repeat('a',64) || $revision !== 2) return null;
    if ($decision === 'snooze' && strtotime($wakeAt.' UTC') < time()+3500) throw new LogicException('Missing snooze time.');
    return ['id'=>$id,'revision'=>3,'stage'=>'snoozed'];
}
`);
  writeFileSync(join(root, 'includes', 'agent-chat-boundary-v380.php'), `<?php
function vp3_agent_chat_resolve_agent_v380(PDO $pdo,array $user,int $agent): ?array {
    if ($agent===0) return null;
    if ($user['id']===1 && $agent===7) return ['id'=>7];
    throw new RuntimeException('Foreign Agent');
}
function vp3_agent_chat_conversation_v380(PDO $pdo,int $conversation,int $owner,?array $agent): ?array {
    return $conversation===11 && $owner===1 && ($agent['id'] ?? 0)===7 ? ['id'=>11] : null;
}
`);
  writeFileSync(join(root, 'includes', 'agent-email-triage-v1.php'), `<?php
function agent_email_triage_ready_v1(PDO $pdo): bool {
    return !isset($_SERVER['HTTP_X_FIXTURE_NO_TRIAGE']);
}
function agent_email_triage_groups_v1(): array { return ['promotion'=>'Promotions','active'=>'Needs attention']; }
function agent_email_triage_attention_choices_v1(): array { return ['act_soon','review','read_later','informational']; }
function agent_email_triage_source_v1(PDO $pdo,array $scope,string $id): ?array {
    return $scope['owner'] === 1 && $id === str_repeat('c',64) ? ['message_id'=>'private-gmail-id'] : null;
}
function agent_email_triage_group_v1(PDO $pdo,array $scope,string $group,?string $cursor): array {
    fixture_record('triage_group',['id'=>$scope['owner']],[$group,$cursor]);
    return ['items'=>[['id'=>str_repeat('a',64),'revision'=>2,'subject'=>$cursor===null?'First offer':'Second offer','group'=>$group]],
        'next'=>$cursor===null?str_repeat('b',64):null];
}
function agent_email_triage_correct_v1(PDO $pdo,array $user,string $id,int $revision,string $attention,bool $remember): ?array {
    fixture_record('triage_correct',$user,[$id,$revision,$attention,$remember]);
    if ($user['id']!==1 || $id!==str_repeat('a',64) || $revision!==2) return null;
    return ['id'=>$id,'revision'=>3,'attention'=>$attention,'remembered'=>$remember];
}
`);
  writeFileSync(join(root, 'includes', 'agent-connection-gmail-inbox-initial-v1.php'), `<?php
$calls = [];
function fixture_record(string $action, array $user, mixed $input): void {
    global $calls;
    if (session_status() === PHP_SESSION_ACTIVE) throw new RuntimeException('Session remained locked.');
    header('X-Fixture-Dispatch: '.$action);
    $calls[] = [$action, $user['id'], $input];
}
function agent_connection_gmail_inbox_analyze_recent_v1(PDO $pdo,array $user,int $agent,int $conversation): void {
    if ($user['id']!==1 || $agent!==7 || $conversation!==11) throw new RuntimeException('Private Chat changed.',403);
    fixture_record('analyze_recent',$user,[$agent,$conversation]);
}
function agent_connection_gmail_inbox_bound_scope_v1(PDO $pdo,array $user): array {
    static $scopeReads=0;
    $scopeReads++;
    if (isset($_SERVER['HTTP_X_FIXTURE_REVOKED_GMAIL'])
        || ($scopeReads>1 && isset($_SERVER['HTTP_X_FIXTURE_REVOKE_AFTER_TRIAGE']))) return ['state'=>'disabled'];
    return ['state'=>'ready','owner'=>$user['id'],'generation'=>$user['id']===1?123:456,'provider_ready'=>true];
}
function agent_connection_gmail_inbox_initial_view_v1(PDO $pdo, array $user, bool $includeReport = false, ?string $expectedRevision = null, bool $includeChatResult = false, bool $includeLegacyMatches = false): array {
    global $calls;
    if (!$includeLegacyMatches) throw new RuntimeException('Private match actions are unavailable.');
    fixture_record('status', $user, $includeReport);
    return ['calls'=>$calls] + ($includeReport ? ['start_token'=>str_repeat('b', 64)] : [])
        + ($includeChatResult ? ['chat_result'=>['summary'=>'Fixture saved summary.']] : []);
}
function agent_connection_gmail_inbox_initial_start_v1(PDO $pdo, array $user, int $days, string $startToken): void {
    fixture_record('start', $user, [$days, $startToken]);
}
function agent_connection_gmail_inbox_initial_advance_v1(PDO $pdo, array $user, string $runId): bool {
    fixture_record('advance', $user, $runId);
    if (isset($_SERVER['HTTP_X_FIXTURE_PROVIDER_FAILURE'])) throw new RuntimeException('Private provider detail.', 502);
    return true;
}
function agent_connection_gmail_inbox_open_item_v1(PDO $pdo, array $user, string $ref, ?callable $http=null, bool $includeLinks=false): array {
    if (!$includeLinks) throw new LogicException('The endpoint must request the current protected link projection.');
    fixture_record('open_item', $user, $ref);
    if ($ref !== str_repeat('c',64)) throw new DomainException('Private source detail.',404);
    return ['subject'=>'Fixture subject','from'=>'sender@example.test','body'=>'Exact fixture body.','auto_reply'=>false];
}
function agent_connection_gmail_inbox_find_matches_v1(PDO $pdo, array $user, string $ref): array {
    fixture_record('find_matches', $user, $ref);
    if ($ref !== str_repeat('e',64)) throw new DomainException('Private source detail.',404);
    return ['matches'=>[['message_id'=>'fixture-mail','match_token'=>str_repeat('f',64)]],
        'unique_match'=>!isset($_SERVER['HTTP_X_FIXTURE_PARTIAL_MATCHES'])];
}
function agent_connection_gmail_inbox_open_match_v1(PDO $pdo, array $user, string $ref, string $id, string $token): array {
    fixture_record('open_match', $user, [$ref,$id,$token]);
    if ($ref !== str_repeat('e',64) || $id !== 'fixture-mail' || $token !== str_repeat('f',64)) {
        throw new DomainException('Private source detail.',404);
    }
    return ['subject'=>'Fixture subject','from'=>'sender@example.test','body'=>'Exact fixture body.','auto_reply'=>false];
}
`);

  const socket = createServer();
  await new Promise(resolve => socket.listen(0, '127.0.0.1', resolve));
  const {port} = socket.address();
  await new Promise((resolve, reject) => socket.close(error => error ? reject(error) : resolve()));
  const address = `127.0.0.1:${port}`;
  server = spawn(process.env.VP3_PHP_BINARY || 'php', ['-d', `session.save_path=${root}`, '-S', address, '-t', root], {stdio:['ignore', 'ignore', 'pipe']});
  server.stderr.on('data', data => { logs += data; });
  const ready = await new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(Error('PHP server did not start.\n' + logs)), 5000);
    server.on('error', error => { clearTimeout(timer); reject(error); });
    server.stderr.on('data', () => {
      if (logs.includes('started')) { clearTimeout(timer); resolve(true); }
    });
    server.on('exit', code => { clearTimeout(timer); reject(Error(`PHP server exited (${code}).\n` + logs)); });
  });
  assert.equal(ready, true);

  const runId = 'a'.repeat(32);
  const request = async ({body = {}, headers = {}, method = 'POST', raw} = {}) => {
    const response = await fetch(`http://${address}/api/gmail-inbox-initial-v1.php`, {
      method,
      headers:{'Content-Type':'application/json', ...headers},
      ...(method === 'POST' ? {body:raw ?? JSON.stringify({action:'status', owner_key:'1', csrf_token:'fixture-csrf', ...body})} : {}),
    });
    const text = await response.text();
    try { return {status:response.status, headers:response.headers, data:JSON.parse(text)}; }
    catch { throw Error(text + '\n' + logs); }
  };

  const status = await request();
  assert.equal(status.status, 200);
  assert.equal(status.headers.get('cache-control'), 'private, no-store');
  assert.deepEqual(status.data, {ok:true, review:{calls:[['status', 1, false]]}});
  const chatStatus = await request({body:{include_chat_result:true}});
  assert.deepEqual(chatStatus.data, {ok:true, review:{calls:[['status', 1, false]],chat_result:{summary:'Fixture saved summary.'}}});
  const checkRef = 'a'.repeat(64);
  const overdueCheck = {action:'check_overdue',check_ref:checkRef,working_attention_binding:'d'.repeat(64),include_chat_result:true};
  const catchUp = await request({body:overdueCheck});
  assert.equal(catchUp.status, 200);
  assert.deepEqual(catchUp.data.review, {calls:[['check_overdue',1,checkRef],['status',1,false]],chat_result:{summary:'Fixture saved summary.'}});
  const reportCheck = await request({body:{...overdueCheck,agent_id:7,conversation_id:11}});
  assert.equal(reportCheck.status,200);
  assert.deepEqual(reportCheck.data.review.calls[0],['check_overdue',1,[checkRef,{agent_id:7,conversation_id:11}]]);
  const analysis = {action:'analyze_recent',agent_id:7,conversation_id:11,working_attention_binding:'d'.repeat(64)};
  const analyzed = await request({body:analysis});
  assert.equal(analyzed.status,200);
  assert.equal(analyzed.headers.get('x-fixture-dispatch'),'analyze_recent');
  assert.deepEqual(analyzed.data,{ok:true});
  for (const [override,status] of [[{conversation_id:0},422],[{conversation_id:12},403],
    [{working_attention_binding:'e'.repeat(64)},403],[{csrf_token:'invalid'},419]]) {
    const denied = await request({body:{...analysis,...override}});
    assert.equal(denied.status,status);
    assert.equal(denied.headers.get('x-fixture-dispatch'),null);
  }
  assert.equal((await request({body:analysis,headers:{'X-Fixture-Participant':'1'}})).status,403);
  const matterUpdate = {action:'matter_update',matter_id:'a'.repeat(64),revision:2,decision:'snooze',
    working_attention_binding:'d'.repeat(64)};
  const snoozed = await request({body:matterUpdate});
  assert.equal(snoozed.headers.get('x-fixture-dispatch'),'matter_update');
  assert.deepEqual(snoozed.data,{ok:true,matter:{id:matterUpdate.matter_id,revision:3,stage:'snoozed'}});
  assert.equal((await request({body:{...matterUpdate,revision:1}})).status,409);
  assert.equal((await request({body:{...matterUpdate,revision:'2'}})).status,422);
  assert.equal((await request({body:{...matterUpdate,working_attention_binding:'e'.repeat(64)}})).status,403);
  assert.equal((await request({headers:{'X-Fixture-Owner':'2'},
    body:{...matterUpdate,owner_key:'2',working_attention_binding:'e'.repeat(64)}})).status,409);
  const triageScope = {agent_id:7,conversation_id:11,generation:123,working_attention_binding:'d'.repeat(64)};
  const triageGroup = {action:'triage_group',group:'promotion',cursor:null,...triageScope};
  const promotion = await request({body:triageGroup});
  assert.equal(promotion.status,200);
  assert.equal(promotion.headers.get('cache-control'),'private, no-store');
  assert.deepEqual(promotion.data,{ok:true,group:{items:[{id:'a'.repeat(64),revision:2,subject:'First offer',group:'promotion'}],next:'b'.repeat(64)}});
  const nextPage = await request({body:{...triageGroup,cursor:promotion.data.group.next}});
  assert.equal(nextPage.status,200);
  assert.equal(nextPage.data.group.items[0].subject,'Second offer');
  assert.equal(nextPage.data.group.next,null);
  const triageCorrection = {action:'triage_correct',triage_id:promotion.data.group.items[0].id,revision:2,attention:'review',...triageScope};
  const oneOff = await request({body:triageCorrection});
  assert.deepEqual(oneOff.data,{ok:true,item:{id:'a'.repeat(64),revision:3,attention:'review',remembered:false}});
  const senderRule = await request({body:{...triageCorrection,remember_sender:true}});
  assert.equal(senderRule.status,200);
  assert.equal(senderRule.data.item.remembered,true);
  assert.equal((await request({body:{...triageCorrection,revision:1}})).status,409);
  const revokedAfterRead = await request({headers:{'X-Fixture-Revoke-After-Triage':'1'},body:triageGroup});
  assert.equal(revokedAfterRead.status,403);
  assert.equal(Object.hasOwn(revokedAfterRead.data,'group'),false);
  const privateStatus = await request({body:{include_report:true}});
  const startToken = privateStatus.data.review.start_token;
  assert.match(startToken, /^[a-f0-9]{64}$/);
  const start = await request({body:{action:'start', days:7, start_token:startToken, include_report:true}});
  assert.equal(start.status, 200);
  assert.deepEqual(start.data.review.calls, [['start', 1, [7, startToken]], ['status', 1, true]]);
  const advance = await request({body:{action:'advance', run_id:runId}});
  assert.equal(advance.status, 200);
  assert.deepEqual(advance.data.review.calls, [['advance', 1, runId], ['status', 1, false]]);
  const itemRef = 'c'.repeat(64);
  const open = await request({body:{action:'open_item',item_ref:itemRef}});
  assert.equal(open.headers.get('x-fixture-curiosity-open'),'1-0-0');
  const clicked = await request({body:{action:'record_mailbox_link_open',item_ref:itemRef,url:'https://example.com/article',link_token:'b'.repeat(64),link_label:'Article'}});
  assert.equal(clicked.status,200);
  assert.equal(clicked.headers.get('x-fixture-mailbox-link'),'verified');
  assert.deepEqual(clicked.data,{ok:true,recorded:true});
  const unsignedClick = await request({body:{action:'record_mailbox_link_open',item_ref:itemRef,url:'https://example.com/article'}});
  assert.equal(unsignedClick.status,422);
  assert.equal(open.headers.get('x-fixture-dispatch'),'open_item');
  assert.deepEqual(open.data,{ok:true,item:{subject:'Fixture subject',from:'sender@example.test',
    body:'Exact fixture body.',auto_reply:false}});
  const triageOpen = {action:'triage_open',item_ref:itemRef,...triageScope};
  const exactMessage = await request({body:triageOpen});
  assert.equal(exactMessage.status,200);
  assert.equal(exactMessage.headers.get('cache-control'),'private, no-store');
  assert.equal(exactMessage.headers.get('x-fixture-dispatch'),'open_item');
  assert.deepEqual(exactMessage.data,{ok:true,item:{subject:'Fixture subject',from:'sender@example.test',body:'Exact fixture body.'}});
  const revokedDuringOpen = await request({headers:{'X-Fixture-Revoke-After-Triage':'1'},body:triageOpen});
  assert.equal(revokedDuringOpen.status,403);
  assert.equal(Object.hasOwn(revokedDuringOpen.data,'item'),false);
  const unrelatedMessage = await request({body:{...triageOpen,item_ref:'d'.repeat(64)}});
  assert.equal(unrelatedMessage.status,404);
  assert.equal(unrelatedMessage.headers.has('x-fixture-dispatch'),false);
  const staleItem = await request({body:{action:'open_item',item_ref:'d'.repeat(64)}});
  assert.equal(staleItem.status,404);
  assert.doesNotMatch(staleItem.data.error,/Private source detail/);

  const legacyRef = 'e'.repeat(64);
  const matchToken = 'f'.repeat(64);
  const find = await request({body:{action:'find_matches',legacy_ref:legacyRef}});
  assert.equal(find.headers.get('x-fixture-dispatch'),'find_matches');
  assert.deepEqual(find.data,{ok:true,matches:[{message_id:'fixture-mail',match_token:matchToken}],unique_match:true});
  const partialFind = await request({headers:{'X-Fixture-Partial-Matches':'1'},body:{action:'find_matches',legacy_ref:legacyRef}});
  assert.deepEqual(partialFind.data,{...find.data,unique_match:false},'One visible result can still require selection.');
  const openMatchBody = {action:'open_match',legacy_ref:legacyRef,message_id:'fixture-mail',match_token:matchToken};
  const match = await request({body:openMatchBody});
  assert.equal(match.headers.get('x-fixture-dispatch'),'open_match');
  assert.deepEqual(match.data,open.data);
  const wrongMatch = await request({body:{...openMatchBody,message_id:'another-mail'}});
  assert.equal(wrongMatch.status,404);
  assert.doesNotMatch(wrongMatch.data.error,/Private source detail/);

  for (const [code, options] of [
    [405, {method:'GET'}],
    [401, {headers:{'X-Fixture-Anonymous':'1'}}],
    [403, {headers:{'X-Fixture-Participant':'1'}}],
    [403, {headers:{'X-Fixture-Participant':'1'},body:{include_chat_result:true}}],
    [403, {headers:{'X-Fixture-Participant':'1'},body:{action:'open_item',item_ref:itemRef}}],
    [403, {headers:{'X-Fixture-Participant':'1'},body:overdueCheck}],
    [403, {headers:{'X-Fixture-Participant':'1'},body:triageGroup}],
    [403, {headers:{'X-Fixture-Participant':'1'},body:triageCorrection}],
    [403, {headers:{'X-Fixture-Participant':'1'},body:triageOpen}],
    [403, {body:{...triageOpen,conversation_id:15}}],
    [422, {body:{...triageOpen,item_ref:'raw-provider-id'}}],
    [403, {headers:{'X-Fixture-Revoked-Gmail':'1'},body:triageGroup}],
    [403, {body:{...triageGroup,agent_id:99}}],
    [403, {body:{...triageGroup,conversation_id:15}}],
    [403, {body:{...triageGroup,working_attention_binding:'e'.repeat(64)}}],
    [403, {headers:{'X-Fixture-Owner':'2'},body:{...triageGroup,owner_key:'2',working_attention_binding:'e'.repeat(64)}}],
    [409, {body:{...triageGroup,generation:456}}],
    [419, {body:{...triageCorrection,csrf_token:'wrong'}}],
    [422, {body:{...triageGroup,generation:'123'}}],
    [422, {body:{...triageGroup,group:'unknown'}}],
    [422, {body:{...triageGroup,cursor:'raw-provider-id'}}],
    [422, {body:{...triageCorrection,remember_sender:'true'}}],
    [422, {body:{...triageCorrection,remember_sender:null}}],
    [422, {body:{...triageCorrection,attention:'unknown'}}],
    [422, {body:{...triageCorrection,triage_id:'raw-provider-id'}}],
    [503, {headers:{'X-Fixture-No-Triage':'1'},body:triageGroup}],
    [403, {headers:{'X-Fixture-Owner':'2'},body:{...overdueCheck,owner_key:'2'}}],
    [403, {body:{...overdueCheck,working_attention_binding:'e'.repeat(64)}}],
    [419, {body:{...overdueCheck,csrf_token:'wrong'}}],
    [422, {body:{...overdueCheck,check_ref:'raw-provider-id'}}],
    [422, {body:{...overdueCheck,working_attention_binding:'wrong'}}],
    [422, {body:{...overdueCheck,include_chat_result:false}}],
    [422, {body:{...overdueCheck,include_report:true}}],
    [422, {body:{...overdueCheck,owner_id:2}}],
    [422, {body:{action:'status',check_ref:checkRef}}],
    [422, {body:{action:'open_item',item_ref:'raw-provider-id'}}],
    [422, {body:{action:'open_item',item_ref:itemRef,include_chat_result:true}}],
    [403, {headers:{'X-Fixture-Participant':'1'},body:{action:'find_matches',legacy_ref:legacyRef}}],
    [422, {body:{action:'find_matches',legacy_ref:'raw-provider-id'}}],
    [422, {body:{action:'find_matches',legacy_ref:legacyRef,query:'arbitrary query'}}],
    [422, {body:{...openMatchBody,match_token:'wrong'}}],
    [422, {body:{...openMatchBody,message_id:'invalid/path'}}],
    [403, {headers:{'X-Fixture-Deny':'account.access'}}],
    [403, {headers:{'X-Fixture-Deny':'chat.access'}}],
    [409, {headers:{'X-Fixture-Owner':'2'}}],
    [419, {body:{csrf_token:'wrong'}}],
    [419, {body:{csrf_token:['fixture-csrf']}}],
    [415, {headers:{'Content-Type':'text/plain'}}],
    [413, {body:{padding:'x'.repeat(2049)}}],
    [413, {raw:' '.repeat(4097)}],
    [422, {raw:' '.repeat(2049)}],
    [422, {raw:'{'}],
    [422, {raw:'[]'}],
    [422, {body:{owner_key:1}}],
    [422, {body:{include_report:null}}],
    [422, {body:{include_chat_result:1}}],
    [422, {body:{include_report:true,include_chat_result:true}}],
    [422, {body:{action:'start', days:'7', start_token:startToken}}],
    [422, {body:{action:'start', days:2, start_token:startToken}}],
    [422, {body:{action:'start', days:7}}],
    [422, {body:{action:'start', days:7, start_token:'b'.repeat(63)}}],
    [422, {body:{action:'start', days:7, start_token:'B'.repeat(64)}}],
    [422, {body:{action:'start', days:7, start_token:[startToken]}}],
    [422, {body:{action:'start', days:7, start_token:startToken, run_id:runId}}],
    [422, {body:{action:'advance', run_id:'wrong'}}],
    [422, {body:{action:'advance', run_id:runId, start_token:startToken}}],
    [422, {body:{action:'status', run_id:runId}}],
    [422, {body:{action:'status', start_token:startToken}}],
    [422, {body:{action:'send'}}],
  ]) {
    const denied = await request(options);
    assert.equal(denied.status, code, JSON.stringify(options));
    assert.equal(denied.data.ok, false);
    assert.equal(denied.headers.has('x-fixture-dispatch'), false);
    assert.equal(Object.hasOwn(denied.data, 'review'), false);
  }

  const failure = await request({body:{action:'advance', run_id:runId}, headers:{'X-Fixture-Provider-Failure':'1'}});
  assert.equal(failure.status, 503);
  assert.deepEqual(failure.data, {ok:false, error:'Inbox Review is temporarily unavailable.'});
  console.log('Gmail initial inbox HTTP boundary passed');
} finally {
  if (server?.pid && server.exitCode === null && server.signalCode === null) {
    server.kill();
    await new Promise(resolve => server.once('exit', resolve));
  }
  rmSync(root, {recursive:true, force:true});
}
