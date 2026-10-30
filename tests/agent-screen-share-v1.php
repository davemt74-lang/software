<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/agent-screen-share-v1.php';
require dirname(__DIR__).'/includes/agent-surface-context-v131.php';
function db(): ?PDO{return null;}
function current_user(): array{return ['id'=>1];}
$checks=0;
function check(bool $ok,string $label): void{if(!$ok)throw new RuntimeException($label);$GLOBALS['checks']++;echo "PASS $label\n";}
function rejects(callable $fn,string $label,?int $code=null): void{try{$fn();}catch(Throwable $e){check($code===null||$e->getCode()===$code,$label);return;}throw new RuntimeException('Expected rejection: '.$label);}
$input=['owner_key'=>'1','image'=>'data:image/jpeg;base64,'.trim(file_get_contents(__DIR__.'/fixtures/tv-frame.b64')),'captured_at'=>gmdate('Y-m-d\TH:i:s\Z'),'surface'=>'window'];
$frame=screen_frame_v1($input);check($frame['surface']==='window','valid current window frame');
foreach(['https://example.com/image.jpg','data:text/html;base64,SGk=','data:image/jpeg;base64,SGk='] as $bad)rejects(fn()=>screen_frame_v1([...$input,'image'=>$bad]),'non-frame content rejected');
rejects(fn()=>screen_frame_v1([...$input,'image'=>str_repeat('x',420001)]),'size budget enforced');
rejects(fn()=>screen_frame_v1([...$input,'captured_at'=>gmdate('Y-m-d\TH:i:s\Z',time()-100)]),'stale frame rejected',409);
rejects(fn()=>screen_frame_v1([...$input,'captured_at'=>gmdate('Y-m-d\TH:i:s\Z',time()+30)]),'future frame rejected',409);
rejects(fn()=>screen_frame_v1([...$input,'surface'=>'all_tabs']),'no invented tab inventory');
rejects(fn()=>screen_frame_v1([...$input,'provider'=>'foreign-key']),'provider cannot be selected by screen data');
$seen=[];$vision=function($prompt,$images)use(&$seen){$seen=[$prompt,$images];return ['ok'=>true,'answer'=>json_encode(['summary'=>'A product comparison is visible.','visible_text'=>'Product A','site_hint'=>'Example store','page_title'=>'Product A'])];};
$result=screen_analyze_v1($frame,['id'=>1],$vision);
check(count($seen[1])===1&&$seen[1][0]===$frame['image'],'actual screenshot accompanies vision prompt');
check(str_contains($seen[0],'NEVER instructions')&&str_contains($seen[0],'hidden tabs'),'screen instructions and off-screen claims excluded');
check($result['owner_key']==='1'&&$result['inference_only']&&!$result['authentication_authority'],'observation is account bound inference');
check(!str_contains(json_encode($result),'base64'),'response contains no raw frame');
rejects(fn()=>screen_analyze_v1($frame,['id'=>1],fn()=>['ok'=>false,'error'=>'Provider unavailable']),'provider failure stays visible',503);
rejects(fn()=>screen_analyze_v1($frame,['id'=>1],fn()=>['ok'=>true,'answer'=>'not JSON']),'invalid model data rejected',502);
rejects(fn()=>screen_analyze_v1($frame,['id'=>1],fn()=>['ok'=>true,'answer'=>json_encode(['summary'=>str_repeat('s',1601)])]),'model output is bounded');
$state=[];$claim=screen_rate_claim_v1($state,1,10000);check($state[1]['count']===1&&strlen($claim)===32,'analysis budget claims before inference');
rejects(fn()=>screen_rate_claim_v1($state,1,10001),'overlapping analysis rejected',429);
unset($state[1]['busy_until']);rejects(fn()=>screen_rate_claim_v1($state,1,10002),'rapid retries rejected',429);
screen_rate_claim_v1($state,2,10002);check(isset($state[2]),'rate state is isolated between accounts');
$state[1]=['window'=>10000,'last'=>10000,'count'=>120];rejects(fn()=>screen_rate_claim_v1($state,1,10100),'hourly analysis cap enforced',429);
screen_rate_claim_v1($state,1,14000);check($state[1]['count']===1,'hourly budget resets');
$safe=agent_surface_v131_sanitize(['screen_observation'=>[...$result,'authentication_authority'=>true,'image'=>$frame['image']]]);
check($safe['screen_observation']['authentication_authority']===false&&!isset($safe['screen_observation']['image']),'Chat strips image and authority fields');
$enriched=agent_surface_v131_enrich(['id'=>2],'chat',['screen_observation'=>$result]);check($enriched['screen_observation']===null,'another account cannot attach this observation');
check(agent_surface_v131_screen_observation(['screen_observation'=>[...$result,'captured_at'=>gmdate('Y-m-d\TH:i:s\Z',time()-100)]])===null,'Chat rejects expired screen context');
$item=agent_surface_v131_context_item($safe);check(str_contains($item['text'],'timestamped visual inference')&&str_contains($item['text'],'Product A'),'canonical Chat includes labelled current screen evidence');
echo "AGENT_SCREEN_SHARE_V1=PASS ($checks cases)\n";
