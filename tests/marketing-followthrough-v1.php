<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/profile-specialist-workspace-v1.php';
require_once __DIR__.'/fixtures/profile-commerce-followthrough-data-v1.php';
require_once dirname(__DIR__).'/includes/marketing-followthrough-v1.php';
require_once dirname(__DIR__).'/includes/marketing-followthrough-destination-v1.php';
$checks=0;
function mft_check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException('FAIL '.$label);$GLOBALS['checks']++;echo 'PASS '.$label.PHP_EOL;}
function mft_reject(callable $read,int $status,string $label):void{try{$read();}catch(Throwable $error){mft_check($error->getCode()===$status,$label.' ('.$error->getCode().')');return;}throw new RuntimeException('FAIL expected rejection '.$label);}
pws_reset();$pdo=db();$seed=profile_commerce_followthrough_fixture_v1($pdo);pa3_login(1);$user=current_user();$binding=vp3_catch_up_page_binding_v1($user);
$input=['action'=>'report','chat_agent_id'=>'7','chat_conversation_id'=>'11','ref'=>$seed['ref']];
$read=static fn(array $value=[],?callable $guard=null)=>vp3_marketing_followthrough_request_v1($pdo,$user,array_replace($input,$value),$binding,$guard);
$before=pa4b_snapshot()+pp_no_external_snapshot();
$value=pa4b_readonly(fn()=>$read());
mft_check($value['contract']===VP3_MARKETING_FOLLOWTHROUGH_V1&&$value['data']['followthrough']['ref']===$seed['ref'],'query-only request returns exact report and scope');
mft_check($before===pa4b_snapshot()+pp_no_external_snapshot(),'request neither delivers attention nor sends or dispatches inference');
mft_check(!$pdo->inTransaction(),'request-owned read transactions are closed');
$pdo->beginTransaction();$read();mft_check($pdo->inTransaction(),'caller transaction is preserved');$pdo->rollBack();
foreach([
    'action=report&chat_agent_id=7&chat_conversation_id=11&ref=x&ref=y',
    'action=report&chat_agent_id[]=7&chat_conversation_id=11&ref=x',
    'action=report&chat.agent.id=7&chat_conversation_id=11&ref=x',
    'action=report&chat_agent_id=%ZZ&chat_conversation_id=11&ref=x',
    'action=report&chat_agent_id=7&%63hat_agent_id=7&chat_conversation_id=11&ref=x'
] as $query)mft_reject(fn()=>vp3_marketing_followthrough_query_v1($query),422,'ambiguous raw endpoint query rejected');
mft_reject(fn()=>$read(['publication_id'=>$seed['publication_id']]),422,'ref cannot silently combine with a publication selector');
mft_reject(fn()=>$read(['period'=>'7']),422,'ref cannot silently combine with a new period');
mft_reject(fn()=>$read(['chat_agent_id'=>'07']),422,'noncanonical private Agent rejected');
mft_reject(fn()=>$read(['chat_conversation_id'=>'9007199254740992']),422,'unsafe private Chat integer rejected');
mft_reject(fn()=>vp3_marketing_followthrough_request_v1($pdo,$user,$input,'wrong'),403,'foreign page binding rejected');
mft_reject(fn()=>$read([],static fn()=>['id'=>2]),403,'late actual session principal change rejects populated report');
$role=(string)$pdo->query('SELECT role FROM users WHERE id=1')->fetchColumn();
mft_reject(fn()=>$read([],static function()use($pdo,$role,$user):array{$pdo->prepare("DELETE FROM role_permissions WHERE role=? AND permission_key='profile_chat.access'")->execute([$role]);return $user;}),403,'late Profile-only revocation fails final current authority fence');
$publications=['action'=>'publications','chat_agent_id'=>'7','chat_conversation_id'=>'11','product_id'=>(string)$seed['ids']['product']];
$list=pa4b_readonly(fn()=>vp3_marketing_followthrough_request_v1($pdo,$user,$publications,$binding));
mft_check(count($list['data']['publications']['publications'])===2,'private publication choices retain account and Chat authority without Profile permission');
$pdo->prepare("INSERT INTO role_permissions(role,permission_key) VALUES(?,'profile_chat.access')")->execute([$role]);
mft_reject(fn()=>$read([],static function()use($pdo,$user):array{$pdo->exec('UPDATE chat_conversations SET user_agent_id=8 WHERE id=11');return $user;}),403,'late private Chat reassignment rejects old report');
$pdo->exec('UPDATE chat_conversations SET user_agent_id=7 WHERE id=11');
mft_reject(fn()=>$read([],static function()use($pdo,$user):array{$pdo->exec('UPDATE user_agents SET is_active=0 WHERE id=7');return $user;}),403,'late Agent retirement rejects old report');
$pdo->exec('UPDATE user_agents SET is_active=1 WHERE id=7');
$now=new DateTimeImmutable('2026-11-20T00:00:00+00:00');
$query='agent=7&conversation_id=11&marketing_followthrough='.rawurlencode($seed['ref']);
$requested=vp3_marketing_followthrough_destination_request_v1($query,$now);
$bound=vp3_marketing_followthrough_destination_bind_v1($requested,1,7,11,$binding,'/nested/app/chat.php',true);
mft_check($bound['ref']===$seed['ref']&&str_starts_with($bound['href'],'/nested/app/chat.php?agent=7&conversation_id=11&'),'production link helper binds only the exact requested original tuple');
mft_check(!str_contains($bound['href'],$binding)&&!str_contains($bound['href'],'owner'),'fixed source URL contains no session or owner credential');
foreach([$query.'&agent=7',$query.'&marketing_plan=7',$query.'&profile_analytics='.rawurlencode($seed['period_ref']),str_replace('agent=7','agent[]=7',$query),str_replace('agent=7','agent=07',$query),str_replace('conversation_id=11','conversation_id=9007199254740992',$query),str_replace('marketing_followthrough=','marketing_followthrough=%ZZ',$query)] as $invalid)
    mft_check(vp3_marketing_followthrough_destination_request_v1($invalid,$now)===null,'malformed or competing destination has no auto-open hint');
mft_check(vp3_marketing_followthrough_destination_bind_v1($requested,1,7,12,$binding,'/chat.php',true)===null,'wrong-Chat fallback cannot inherit original destination');
mft_check(vp3_marketing_followthrough_destination_bind_v1($requested,1,0,11,$binding,'/chat.php',true)===null,'wrong-Agent fallback cannot inherit original destination');
mft_check(vp3_marketing_followthrough_destination_bind_v1($requested,1,7,11,$binding,'/chat.php',false)===null,'absent current authority withholds destination');
$system=vp3_marketing_followthrough_destination_request_v1(str_replace('agent=7','agent=system',$query),$now);
mft_check(vp3_marketing_followthrough_destination_bind_v1($system,1,0,11,$binding,'/chat.php',true)['chatAgentId']===0,'explicit system Agent route supported');
$legacyRef='marketing:product-outcome:'.$seed['publication_id'].':'.substr($seed['period_ref'],strlen('profile:analytics:'));
$legacyQuery=http_build_query(['agent'=>'7','conversation_id'=>'11','marketing_plan'=>(string)$seed['ids']['product'],
    'marketing_publication'=>$seed['publication_id'],'marketing_period'=>$seed['period_ref']],'','&',PHP_QUERY_RFC3986);
$legacyRequest=vp3_marketing_followthrough_destination_request_v1($legacyQuery,$now);
$legacy=vp3_marketing_followthrough_destination_bind_v1($legacyRequest,1,7,11,$binding,'/nested/app/chat.php',true,$pdo);
mft_check($legacy['ref']===$seed['ref']&&$legacy['href']===$bound['href']
    &&$legacy['legacyHref']==='/nested/app/chat.php?'.$legacyQuery,
    'released publication URL translates to the same exact canonical period and retains its verified old URL');
mft_check(vp3_marketing_followthrough_destination_bind_v1($legacyRequest,1,7,11,$binding,'/chat.php',true)===null,
    'old product selector requires a real publication binding lookup');
$wrongProduct=array_replace($legacyRequest,['legacy_product_id'=>$seed['ids']['product']+1]);
mft_check(vp3_marketing_followthrough_destination_bind_v1($wrongProduct,1,7,11,$binding,'/chat.php',true,$pdo)===null,
    'legacy product/publication mismatch cannot open another plan');
foreach([$legacyQuery.'&marketing_period='.rawurlencode($seed['period_ref']),$legacyQuery.'&marketing_followthrough='.rawurlencode($seed['ref']),
    str_replace('marketing_plan='.$seed['ids']['product'],'marketing_plan=0'.$seed['ids']['product'],$legacyQuery),
    str_replace('marketing_publication='.$seed['publication_id'],'marketing_publication=bad',$legacyQuery)] as $invalid)
    mft_check(vp3_marketing_followthrough_destination_request_v1($invalid,$now)===null,'ambiguous or malformed released publication URL is withheld');
$oldRefRequest=vp3_marketing_followthrough_destination_request_v1('agent=7&conversation_id=11&marketing_followthrough='.rawurlencode($legacyRef),$now);
mft_check(vp3_marketing_followthrough_destination_bind_v1($oldRefRequest,1,7,11,$binding,'/chat.php',true)['ref']===$seed['ref'],
    'old source reference maps only its original publication and frozen period');
mft_check($read(['ref'=>$legacyRef])['data']['followthrough']['ref']===$seed['ref'],
    'explicit API compatibility reads use the same current-authorized canonical collector');
mft_check(vp3_profile_commerce_followthrough_legacy_ref_v1($legacyRef.':extra',$now)===null
    &&vp3_profile_commerce_followthrough_legacy_ref_v1(strtoupper($legacyRef),$now)===null,
    'legacy compatibility does not weaken canonical period validation');
echo 'MARKETING_FOLLOWTHROUGH_V1=PASS ('.$checks.' checks, '.$pdo->getAttribute(PDO::ATTR_DRIVER_NAME).')'.PHP_EOL;
