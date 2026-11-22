<?php
declare(strict_types=1);

// Pure regression for the canonical image-job -> shared specialist projection.
// Live owner/Agent/Chat, MySQL, and browser journey checks run in hosted CI.
function url(string $path): string {return $path;}
require_once dirname(__DIR__).'/includes/agent-top-of-mind-image-v1.php';
function ensure(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
$now=strtotime('2026-11-22 05:00:00 UTC');
$row=['owner_user_id'=>31,'agent_id'=>7,'conversation_id'=>45,'run_id'=>811,
    'generation_id'=>'01234567-abcd-4ace-babe-0123456789ab',
    'user_message_id'=>600,'created_at'=>'2026-11-22 04:30:00','workflow_updated_at'=>'2026-11-22 04:50:00'];
$view=['id'=>$row['generation_id'],'job_id'=>811,'status'=>'completed','count'=>2,'completed_count'=>2,
    'prompt'=>'PRIVATE TEXT THAT MUST NEVER ENTER BRAIN','title'=>'Hidden title'];
$project=static fn(array $v,?array $r=null,int $owner=31,int $aid=7,int $cid=45,int $mid=600):?array=>
    vp3_top_of_mind_image_projection_v1($v,$r??$row,$owner,$aid,$cid,$now,$mid);
$p=$project($view);
ensure($p!==null&&$p['id']==='image-generator'&&$p['state']==='completed','Completed receipt must be shown accurately');
ensure($p['run_id']===811&&$p['conversation_id']===45&&$p['message_id']===600,'Original run and exact Chat turn are retained');
ensure($p['result_url']==='/chat.php?agent=7&conversation_id=45&message_id=600','Verified image links to exact Chat turn');
ensure(!str_contains(json_encode($p,JSON_THROW_ON_ERROR),'PRIVATE TEXT'),'Private prompt is excluded from Brain');
ensure(str_contains($p['detail'],'2 images generated and saved'),'Completion must include confirmed image count');
ensure(!isset($project($view,$row,31,7,45,0)['message_id']),'Unverified message id must not be attached');
ensure($project($view,$row,31,7,45,0)['result_url']==='/chat.php?agent=7&conversation_id=45',
    'Unverified message identifier must not be linked');
foreach([['owner_user_id'=>99],['agent_id'=>8],['conversation_id'=>46],['generation_id'=>'x'],['run_id'=>42]] as $change){
    ensure($project($view,array_replace($row,$change))===null,'Cross-owner/source mismatches must fail closed');
}
ensure($project(array_replace($view,['id'=>'aaa']))===null,'Invalid generation identifier must fail closed');
ensure($project(array_replace($view,['job_id'=>812]))===null,'Unbound job must fail closed');
ensure($project(array_replace($view,['status'=>'completed','completed_count'=>0]))['state']==='blocked',
    'Incomplete assets must never be called a completed generation');
ensure($project(array_replace($view,['status'=>'file_unavailable']))['state']==='blocked','Missing files are blocked');
ensure($project(array_replace($view,['status'=>'failed']))['state']==='failed','Failures remain failures');
ensure($project(array_replace($view,['status'=>'queued']))['state']==='queued','Queued work is not completed');
ensure($project(array_replace($view,['status'=>'paused']))['state']==='paused','Paused work is not running');
$lease=($now+90)*1000;
$running=$project(array_replace($view,['status'=>'generating','active_lease_expires_at'=>$lease]));
ensure($running['state']==='working'&&str_contains($running['lease_expires_at'],'2026'),'Live lease produces working status');
ensure($project(array_replace($view,['status'=>'generating','lease_expired'=>true]))['state']==='interrupted',
    'Expired worker leases must not be called active');
ensure($project(array_replace($view,['status'=>'generating']))['state']==='interrupted',
    'A worker without a verified live lease cannot report that it is working');
ensure($project(array_replace($view,['status'=>'cancelled']))['state']==='interrupted','Cancelled work is not completed');
ensure($project(array_replace($view,['status'=>'unknown']))===null,'Unknown status is not invented');
ensure($project(array_replace($view,['count'=>5]))===null,'Invalid count is rejected');
ensure($project($view,array_replace($row,['workflow_updated_at'=>'2026-11-01 04:00:00']))===null,
    'Stale completed jobs do not indefinitely dominate current activity');
ensure($project(array_replace($view,['status'=>'queued']),array_replace($row,['workflow_updated_at'=>'2026-11-01 04:00:00']))['state']==='queued',
    'Pending jobs stay visible until terminal');
$systemRow=array_replace($row,['agent_id'=>0,'user_message_id'=>null]);
$system=$project(array_replace($view,['status'=>'queued']),$systemRow,31,0,45,0);
ensure($system!==null&&str_contains($system['result_url'],'agent=system'),'System Agent scope is supported');
echo "PASS image generator canonical receipts, private scope, exact Chat links, leases and Brain status\n";
