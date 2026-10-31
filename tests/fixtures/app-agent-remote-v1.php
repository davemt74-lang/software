<?php
/** Network fixture only; all App, Chat, principal and receipt services are production code. */
function app_agent_remote_fixture_v1(int $uid,string $op,array $payload=[]): array
{
    $GLOBALS['app_read_calls'][]=[$uid,$op,$payload];$mode=$GLOBALS['app_read_mode']??($_SERVER['HTTP_X_FIXTURE_APP_MODE']??'success');
    if($mode==='offline')throw new RuntimeException('SECRET remote host timeout');
    if($mode==='denied')throw new RuntimeException('SECRET remote permission denied',403);
    if($mode==='revoke')db()->exec('UPDATE users SET is_active=0 WHERE id=1');
    if($mode==='swap')db()->exec('UPDATE chat_conversations SET user_agent_id=8 WHERE id=1');
    $row=['app_key'=>$uid===1?'user.notes':'foreign.notes','name'=>$uid===1?'Fixture Notes':'SECRET foreign app','lifecycle_state'=>'running','installed'=>true,'available'=>true,'update_available'=>false,'hosted'=>false,'shared'=>false,'source'=>['path'=>'SECRET native path','content'=>'SECRET source contents']];
    if(in_array($op,['apps.user.list','apps.manager.status'],true)){
        $items=$mode==='empty'?[]:[$row];
        if($mode==='ambiguous')$items[]=[...$row,'app_key'=>'user.notes2'];
        if($mode==='malformed')$items=null;
        if($mode==='bad_item')$items=[['name'=>'Unverified app']];
        $counts=['installed'=>count($items??[]),'available'=>count($items??[]),'running'=>count($items??[]),'updates'=>0,'hosted'=>0];
        if($mode==='negative')$counts['installed']=-1;
        return ['ok'=>true,'items'=>$items,'counts'=>$counts,'source'=>'SECRET root path'];
    }
    if($op==='apps.user.workspace.status'){
        $data=['ok'=>true,'app_key'=>$mode==='identity'?'foreign.notes':$payload['app_key'],'project'=>['count'=>3,'files'=>['SECRET absolute source path']],'validation'=>['valid'=>$mode!=='invalid'],'releases'=>['count'=>2],'validation_error'=>'SECRET diagnostic file path','source'=>['content'=>'SECRET source code']];
        if($mode==='unknown')$data['validation']=null;
        if($mode==='workspace_shape')unset($data['project']);
        if($mode==='workspace_validation')$data['validation']=['valid'=>'yes'];
        return $data;
    }
    throw new RuntimeException('Unexpected app read fixture operation.');
}
