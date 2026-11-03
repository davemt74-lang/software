<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/agent-vrss-outcomes-v1.php';

// Fixtures follow otro e15305a0 app/services/vrss{,_social,_interactions,_reposts,_comments,_conversations}.py.
// No database or network stubs are needed: this module is a pure projection of the canonical receipt.
$checks=0;
function outcomeCheck(bool $ok,string $why): void {if(!$ok)throw new RuntimeException($why);$GLOBALS['checks']++;echo "PASS $why\n";}
function outcomeReceipt(string $action,array $result): array
{
    if($action!=='vrss.refresh_all')$result=['ok'=>true,'contract'=>'vp3.vrss.v1','viewer_id'=>'urn:uuid:aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']+$result;
    return ['contract'=>'vp3.app.agent-action-result.v1','app_key'=>'vp3.feed','action'=>$action,'risk'=>$action==='vrss.refresh_all'?'background':'write','result'=>$result];
}
function outcomeFixture(string $action,array $args,array $result,string $phase): array
{
    return ['receipt'=>outcomeReceipt($action,$result),'request'=>['action'=>$action,'arguments'=>$args],'phase'=>$phase];
}
function outcomeRun(array $fixture): array {return vp3_vrss_action_outcome_v1($fixture['receipt'],$fixture['request']);}
function outcomeSet(array &$data,array $path,mixed $value): void
{
    $ref=&$data;foreach($path as $key)$ref=&$ref[$key];$ref=$value;
}
function outcomeReject(array $fixture,array $path,mixed $value,string $why): void
{
    outcomeSet($fixture,$path,$value);$safe=outcomeRun($fixture);
    outcomeCheck($safe===['ok'=>false,'verified'=>false,'reconcile_pending'=>false,'rolled_back'=>false],$why);
}
$post='https://peer.example/vrss/v1/posts/11111111-1111-4111-8111-111111111111';
$actor='https://peer.example/vrss/v1/actor';
$ownPost='https://owner.example/vrss/v1/posts/11111111-1111-4111-8111-111111111111';
$comment='https://owner.example/vrss/v1/comments/22222222-2222-4222-8222-222222222222';
$incoming='https://peer.example/vrss/v1/comments/33333333-3333-4333-8333-333333333333';
$nonce='frozen-request-0001';
$reaction=['object'=>$post,'likes'=>3,'liked'=>true,'can_like'=>true,'pending'=>true,'state'=>'pending','checked_at'=>100.5];
$repost=['object'=>$post,'reposts'=>2,'reposted'=>true,'can_repost'=>true,'can_undo'=>true,'pending'=>true,'published'=>false,'state'=>'pending','checked_at'=>100.5,'url'=>'https://owner.example/vrss/v1/reposts/44444444-4444-4444-8444-444444444444'];
$watch=['object'=>$post,'actor'=>$actor,'enabled'=>1,'muted'=>0,'revision'=>8,'cursor'=>'0','priming'=>1,'check_revision'=>0,'due'=>120.0,'checked_at'=>0,'error'=>''];
$valid=[
 'subscribe'=>outcomeFixture('vrss.follow',['url'=>'https://news.example/feed.xml'],['subscription_id'=>'55555555-5555-4555-8555-555555555555','replayed'=>false],'subscription_saved'),
 'refresh'=>outcomeFixture('vrss.refresh_all',[],['queued'=>2],'refresh_queued'),
 'refresh-empty'=>outcomeFixture('vrss.refresh_all',[],['queued'=>0],'refresh_queued'),
 'follow-pending'=>outcomeFixture('vrss.social.follow',['url'=>'https://peer.example/vrss/v1/profile'],['state'=>'pending','actor'=>$actor],'follow_pending'),
 'follow-accepted'=>outcomeFixture('vrss.social.follow',['url'=>'https://peer.example/'],['state'=>'accepted','actor'=>$actor,'replayed'=>true],'follow_accepted'),
 'unfollow'=>outcomeFixture('vrss.social.unfollow',['actor'=>$actor],['state'=>'removed'],'local_relationship_saved'),
 'block'=>outcomeFixture('vrss.social.block',['actor'=>$actor],['state'=>'blocked'],'local_relationship_saved'),
 'unblock'=>outcomeFixture('vrss.social.unblock',['actor'=>$actor],['unblocked'=>true],'local_relationship_saved'),
 'remove-follower'=>outcomeFixture('vrss.social.remove',['actor'=>$actor],['state'=>'removed'],'local_relationship_saved'),
 'like-pending'=>outcomeFixture('vrss.social.like',['url'=>$post,'enabled'=>true,'request_id'=>$nonce],['reaction'=>$reaction,'replayed'=>false],'reaction_pending'),
 'unlike-pending'=>outcomeFixture('vrss.social.like',['url'=>$post,'enabled'=>false,'request_id'=>$nonce],['reaction'=>array_replace($reaction,['liked'=>false]),'replayed'=>false],'reaction_pending'),
 'like-delivered'=>outcomeFixture('vrss.social.like',['url'=>$post,'enabled'=>true,'request_id'=>$nonce],['reaction'=>array_replace($reaction,['pending'=>false,'state'=>'delivered']),'replayed'=>true],'reaction_delivered'),
 'unlike-idle'=>outcomeFixture('vrss.social.like',['url'=>$post,'enabled'=>false,'request_id'=>$nonce],['reaction'=>array_replace($reaction,['liked'=>false,'can_like'=>false,'pending'=>false,'state'=>'idle','likes'=>null,'checked_at'=>0]),'replayed'=>false],'reaction_local_saved'),
 'repost-pending'=>outcomeFixture('vrss.social.repost',['url'=>$post,'enabled'=>true,'request_id'=>$nonce],['repost'=>$repost,'replayed'=>false],'repost_pending'),
 'undo-pending'=>outcomeFixture('vrss.social.repost',['url'=>$post,'enabled'=>false,'request_id'=>$nonce],['repost'=>array_replace($repost,['reposted'=>false,'can_undo'=>false]),'replayed'=>false],'repost_pending'),
 'repost-published'=>outcomeFixture('vrss.social.repost',['url'=>$post,'enabled'=>true,'request_id'=>$nonce],['repost'=>array_replace($repost,['pending'=>false,'published'=>true,'state'=>'delivered']),'replayed'=>true],'repost_published'),
 'repost-stale'=>outcomeFixture('vrss.social.repost',['url'=>$post,'enabled'=>true,'request_id'=>$nonce],['repost'=>array_replace($repost,['pending'=>false,'state'=>'delivered']),'replayed'=>true],'repost_local_saved'),
 'undo-idle'=>outcomeFixture('vrss.social.repost',['url'=>$post,'enabled'=>false,'request_id'=>$nonce],['repost'=>array_replace($repost,['reposted'=>false,'can_repost'=>false,'can_undo'=>false,'pending'=>false,'state'=>'idle','reposts'=>null,'url'=>'','checked_at'=>0]),'replayed'=>false],'repost_local_saved'),
 'conversation'=>outcomeFixture('vrss.social.conversation',['url'=>$post,'enabled'=>true,'muted'=>false,'expected_revision'=>7,'request_id'=>$nonce],['conversation'=>$watch,'replayed'=>false],'conversation_saved'),
 'conversation-muted'=>outcomeFixture('vrss.social.conversation',['url'=>$post,'enabled'=>false,'muted'=>true,'expected_revision'=>7,'request_id'=>$nonce],['conversation'=>array_replace($watch,['enabled'=>0,'muted'=>1]),'replayed'=>true],'conversation_saved'),
 'comment-pending'=>outcomeFixture('vrss.social.comment',['url'=>$post,'body'=>'SECRET exact authored text','parent'=>'','request_id'=>$nonce],['comment'=>['id'=>$comment,'object'=>$post,'state'=>'pending_delivery'],'replayed'=>false],'comment_pending_delivery'),
 'comment-approved'=>outcomeFixture('vrss.social.comment',['url'=>$ownPost,'body'=>'SECRET exact authored text','parent'=>'','request_id'=>$nonce],['comment'=>['id'=>$comment,'object'=>$ownPost,'state'=>'approved'],'replayed'=>false],'comment_approved'),
 'comment-retracted'=>outcomeFixture('vrss.social.comment_delete',['url'=>$post,'comment_id'=>$comment,'request_id'=>$nonce],['comment'=>['id'=>$comment,'object'=>$post,'state'=>'deleted'],'replayed'=>false],'comment_retraction_saved'),
 'comment-approved-by-owner'=>outcomeFixture('vrss.social.moderate',['url'=>$ownPost,'comment_id'=>$incoming,'decision'=>'approve','expected_revision'=>4,'request_id'=>$nonce],['comment'=>['id'=>$incoming,'object'=>$ownPost,'state'=>'approved','revision'=>5],'replayed'=>false],'moderation_saved'),
 'comment-hidden-by-owner'=>outcomeFixture('vrss.social.moderate',['url'=>$ownPost,'comment_id'=>$incoming,'decision'=>'hide','expected_revision'=>4,'request_id'=>$nonce],['comment'=>['id'=>$incoming,'object'=>$ownPost,'state'=>'hidden','revision'=>5],'replayed'=>true],'moderation_saved'),
];
foreach($valid as $name=>$f){
    $safe=outcomeRun($f);$summary=$safe['vrss_outcome']??null;
    outcomeCheck($safe['verified']===true&&($summary['phase']??null)===$f['phase'],'source-grounded '.$name.' has exact recorded phase');
    outcomeCheck(vp3_vrss_saved_outcome_v1($safe,$f['request']['action'])===$safe,'saved '.$name.' round-trips without raw evidence');
    $text=vp3_vrss_action_text_v1($safe);$replay=vp3_vrss_action_text_v1($safe,true);$json=json_encode($safe);
    outcomeCheck($text!==''&&str_contains($replay,'not executed again')&&str_contains($replay,'not a fresh remote check')&&!str_contains($text,'completed with a verified result'),$name.' renders truthful phase and historical replay wording');
    outcomeCheck(!str_contains($json,'https://')&&!str_contains($json,'SECRET')&&!str_contains($json,'urn:uuid:')&&!str_contains($json,$nonce),$name.' persists no URLs, authored content, viewer or nonce');
}

// Every action rejects a valid envelope from another action or app, and unknown evidence.
foreach($valid as $name=>$f){
    outcomeReject($f,['receipt','app_key'],'vp3.notes',$name.' cannot accept another app result');
    outcomeReject($f,['receipt','action'],'vrss.feed',$name.' cannot accept another action result');
    if($f['request']['action']!=='vrss.refresh_all')outcomeReject($f,['receipt','result'],['queued'=>2],$name.' cannot replace action evidence with a generic queued payload');
}

foreach([
 [['receipt','contract'],'other.contract','foreign envelope contract'],
 [['receipt','risk'],'read','wrong action risk'],
 [['receipt','ok'],false,'explicit failed outer acknowledgement'],
 [['receipt','error'],'SECRET provider failure','outer provider failure'],
 [['receipt','result','ok'],false,'explicit failed inner acknowledgement'],
 [['receipt','result','ok'],1,'integer success is not boolean success'],
 [['receipt','result','contract'],'vp3.vrss.follow.v1','wrong inner contract'],
 [['receipt','result','viewer_id'],'SECRET invalid identity','invalid inner viewer identity'],
 [['receipt','result','replayed'],1,'integer replay flag'],
 [['receipt','result','subscription_id'],'SECRET','invalid subscription identity'],
 [['request','action'],'vrss.feed','read action cannot project a write receipt'],
 [['request','arguments','confirmed'],true,'extra executable request selector'],
] as [$path,$value,$why])outcomeReject($valid['subscribe'],$path,$value,$why);
foreach([-1,101,'2',2.0,true,null,[]] as $value)outcomeReject($valid['refresh'],['receipt','result','queued'],$value,'queue count requires bounded integer '.json_encode($value));

outcomeReject($valid['follow-pending'],['receipt','result','actor'],'https://foreign.example/vrss/v1/actor','follow result binds the requested profile actor');
outcomeReject($valid['follow-pending'],['receipt','result','state'],'delivered','follow delivery cannot masquerade as signed acceptance');
outcomeReject($valid['follow-accepted'],['receipt','result','replayed'],false,'accepted follow is the existing accepted relation response');
outcomeReject($valid['block'],['receipt','result','state'],'removed','block needs actual blocked state');
outcomeReject($valid['unblock'],['receipt','result','unblocked'],1,'unblock needs an actual boolean result');
$normalized=$valid['follow-pending'];$normalized['request']['arguments']['url']='https://PEER.EXAMPLE:443/vrss/v1/profile/';
outcomeCheck(outcomeRun($normalized)['verified']===true,'actor normalization matches actual HomeServer host/default-port/profile behavior');

foreach([
 [['request','arguments','enabled'],1,'request booleans are not integer-coerced'],
 [['request','arguments','request_id'],'short','missing durable request nonce'],
 [['receipt','result','reaction','object'],$ownPost,'reaction belongs to another post'],
 [['receipt','result','reaction','liked'],false,'reaction does not match requested choice'],
 [['receipt','result','reaction','liked'],1,'reaction returned integer choice'],
 [['receipt','result','reaction','pending'],0,'reaction returned integer pending flag'],
 [['receipt','result','reaction','pending'],false,'pending delivery cannot be downgraded to local saved'],
 [['receipt','result','reaction','state'],'delivered','pending flag contradicts delivered state'],
 [['receipt','result','reaction','state'],'success','unknown delivery state'],
 [['receipt','result','reaction','can_like'],false,'liked reaction requires current relationship'],
 [['receipt','result','reaction','likes'],1001,'unbounded reaction count'],
 [['receipt','result','reaction','checked_at'],INF,'nonfinite reaction observation time'],
] as [$path,$value,$why])outcomeReject($valid['like-pending'],$path,$value,$why);
foreach([
 [['receipt','result','repost','published'],true,'pending repost is not published'],
 [['receipt','result','repost','pending'],false,'pending queue state requires pending flag'],
 [['receipt','result','repost','reposted'],false,'repost choice binds requested enabled state'],
 [['receipt','result','repost','url'],'https://owner.example/unrelated','repost identity requires canonical URL'],
 [['receipt','result','repost','reposts'],'2','repost count is not coerced'],
 [['receipt','result','repost','can_undo'],false,'current repost requires its local undo state'],
] as [$path,$value,$why])outcomeReject($valid['repost-pending'],$path,$value,$why);
outcomeReject($valid['repost-published'],['receipt','result','repost','can_repost'],false,'published repost requires current publication capability');

foreach([
 [['receipt','result','conversation','object'],$ownPost,'watch binds exact post'],
 [['receipt','result','conversation','actor'],'https://other.example/vrss/v1/actor','watch binds post author'],
 [['receipt','result','conversation','enabled'],true,'raw watch flag must preserve actual SQLite integer contract'],
 [['receipt','result','conversation','enabled'],'1','watch does not accept string integer'],
 [['receipt','result','conversation','muted'],2,'watch does not coerce arbitrary integer'],
 [['receipt','result','conversation','muted'],1,'watch mute must match confirmation'],
 [['receipt','result','conversation','revision'],7,'watch revision must advance confirmed revision'],
 [['receipt','result','conversation','revision'],9,'watch cannot accept a later unrelated revision'],
 [['receipt','result','conversation','error'],'SECRET stale source','failed watch projection is not successful evidence'],
 [['request','arguments','expected_revision'],PHP_INT_MAX,'revision addition cannot overflow'],
] as [$path,$value,$why])outcomeReject($valid['conversation'],$path,$value,$why);

foreach([
 [['receipt','result','comment','object'],$ownPost,'new comment binds exact post'],
 [['receipt','result','comment','id'],'SECRET comment id','new comment requires canonical identity'],
 [['receipt','result','comment','state'],'approved','remote post cannot claim the local own-post approval branch'],
 [['receipt','result','comment','state'],'pending_review','create receipt cannot invent a later moderation state'],
 [['request','arguments','body'],str_repeat('x',2001),'comment request respects source byte limit'],
 [['request','arguments','parent'],'https://bad.example/anywhere','reply parent identity is canonical'],
] as [$path,$value,$why])outcomeReject($valid['comment-pending'],$path,$value,$why);
outcomeReject($valid['comment-retracted'],['receipt','result','comment','id'],$incoming,'retraction binds confirmed comment identity');
outcomeReject($valid['comment-retracted'],['receipt','result','comment','state'],'pending_delivery','retraction requires recorded deleted state');
outcomeReject($valid['comment-approved-by-owner'],['receipt','result','comment','state'],'hidden','moderation result matches approved decision');
outcomeReject($valid['comment-hidden-by-owner'],['receipt','result','comment','revision'],6,'moderation cannot claim a different revision');

$queued=outcomeRun($valid['refresh']);$pending=outcomeRun($valid['follow-pending']);$local=outcomeRun($valid['block']);$published=outcomeRun($valid['repost-published']);
outcomeCheck($queued['verified']&&!$queued['vrss_outcome']['final_verified']&&str_contains(vp3_vrss_action_text_v1($queued),'queued 2')&&str_contains(vp3_vrss_action_text_v1($queued),'results are still pending'),'verified queue acknowledgement never becomes completed refresh');
outcomeCheck(str_contains(vp3_vrss_action_text_v1($pending),'pending remote acceptance'),'pending follow never becomes accepted relationship');
outcomeCheck(str_contains(vp3_vrss_action_text_v1(outcomeRun($valid['comment-pending'])),'post owner’s review'),'remote comment delivery never becomes public approval');
outcomeCheck(!outcomeRun($valid['comment-retracted'])['vrss_outcome']['final_verified']&&str_contains(vp3_vrss_action_text_v1(outcomeRun($valid['comment-retracted'])),'does not confirm removal'),'local deleted state never proves remote comment removal');
outcomeCheck(vp3_vrss_outcome_label_v1($queued['vrss_outcome'])==='Queued'&&vp3_vrss_outcome_label_v1($pending['vrss_outcome'])==='Pending'&&vp3_vrss_outcome_label_v1($local['vrss_outcome'])==='Saved locally'&&vp3_vrss_outcome_label_v1($published['vrss_outcome'])==='Verified'&&vp3_vrss_outcome_label_v1(null)==='Unverified','card labels distinguish the five safe outcome classes');

// Stored results are untrusted on read too. Nothing below may become an executable retry.
foreach([
 null,[],['verified'=>true],['ok'=>true,'verified'=>true,'reconcile_pending'=>false,'rolled_back'=>false],
 ['ok'=>true,'verified'=>true,'reconcile_pending'=>false,'rolled_back'=>false,'vrss_outcome'=>['schema'=>'old','phase'=>'completed']],
] as $old){
    $safe=vp3_vrss_saved_outcome_v1($old,'vrss.refresh_all');
    outcomeCheck(!$safe['verified']&&!isset($safe['vrss_outcome'])&&str_contains(vp3_vrss_action_text_v1($old,true),'will not execute again'),'legacy or missing detail remains unverified and consumed');
}
foreach([
 ['schema','vp3.vrss.action-outcome.v2'],['action','vrss.follow'],['phase','completed'],['final_verified',true],['queued_count','2'],['raw_payload','SECRET'],
] as [$key,$value]){
    $bad=$queued;$bad['vrss_outcome'][$key]=$value;
    outcomeCheck(vp3_vrss_outcome_summary_v1($bad['vrss_outcome'])===null&&!vp3_vrss_saved_outcome_v1($bad)['verified']&&vp3_vrss_outcome_label_v1($bad['vrss_outcome'])==='Unverified','persisted summary rejects changed or extra field '.$key);
}
outcomeCheck(!vp3_vrss_saved_outcome_v1($queued,'vrss.social.follow')['verified'],'saved result is bound to the original action on replay');
$extra=$queued;$extra['raw_remote']=['SECRET'=>'https://private.example/token'];
outcomeCheck(vp3_vrss_saved_outcome_v1($extra)===$queued,'saved result normalization strips all unrelated raw fields');
foreach(['ok','verified','reconcile_pending','rolled_back'] as $key){$bad=$queued;$bad[$key]=!$bad[$key];outcomeCheck(!vp3_vrss_saved_outcome_v1($bad)['verified'],'persisted '.$key.' contradictions fail closed');}
$private=$valid['like-pending'];$private['receipt']['result']['private_notes']='SECRET private transcript';$private['receipt']['remote_path']='/SECRET/path';
outcomeCheck(!str_contains(json_encode(outcomeRun($private)),'SECRET'),'unexpected remote fields never enter persisted summary');
echo "VRSS_ACTION_OUTCOMES_V1=PASS ($checks cases; actual HomeServer contracts, bound intent, phase semantics and safe replay)\n";
