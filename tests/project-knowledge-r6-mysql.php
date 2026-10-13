<?php
declare(strict_types=1);
/** Real InnoDB scope state, canonical evidence, source revisions and paired packet export. */
$captureR5Acceptance=static function(PDO $pdo,array $user,array $session,array $first):void{
    require_once __DIR__.'/../includes/project-knowledge-r6.php';
    knowledge_r6_ensure_schema($pdo);knowledge_r6_ensure_schema($pdo);
    $namespace='system';$key='cloud:'.(int)$first['conversation_id'];
    $state=knowledge_r6_state($pdo,$user,$key,$namespace);
    check($state['scope']['mode']==='all','Default context changed legacy retrieval.');
    $all=knowledge_r6_resolve($pdo,$user,$key,$namespace,['query'=>'flour']);check(!$all['active']&&$all['sources']===[],'Default context executed new retrieval.');
    $scope=['mode'=>'project','project_id'=>'66666666-6666-4666-8666-666666666666','folder_id'=>0];
    // The R4 fixture's actual capture chooses its canonical active project.
    $item=capture_r5_list($pdo,$user,['id'=>$first['browser_share_id']])['items'][0];$scope['project_id']=$item['project_id'];
    $set=['scope'=>$scope,'expected_revision'=>$state['revision']];$project=knowledge_r6_set($pdo,$user,$key,$namespace,$set);
    check($project['scope']===$scope&&$project['revision']!==$state['revision'],'Project context was not saved.');
    check(knowledge_r6_set($pdo,$user,$key,$namespace,$set)['revision']===$project['revision'],'Exact settings retry changed revision.');
    $refs=[['kind'=>'annotation','id'=>$item['id'],'revision'=>$item['revision']]];
    $packet=knowledge_r6_resolve($pdo,$user,$key,$namespace,['query'=>'flour','attachments'=>$refs,'state_revision'=>$project['revision']]);
    check($packet['active']&&$packet['sources'][0]['id']===$item['id']&&$packet['sources'][0]['url']===$item['source_url'],'Attached canonical evidence was not resolved.');
    check(str_contains($packet['context_text'],'UNTRUSTED SOURCE EVIDENCE')&&str_contains($packet['context_text'],'[P1]')&&mb_strlen($packet['context_text'])<=6000,'Source context is unbounded or not cited.');
    denied(fn()=>knowledge_r6_resolve($pdo,$user,$key,$namespace,['query'=>'flour','attachments'=>array_merge($refs,$refs)]));
    denied(fn()=>knowledge_r6_resolve($pdo,$user,$key,$namespace,['query'=>'flour','attachments'=>[['kind'=>'annotation','id'=>$item['id'],'revision'=>str_repeat('0',64)]]]));
    denied(fn()=>knowledge_r6_state($pdo,$user,$key,'different-lead'));
    denied(fn()=>knowledge_r6_set($pdo,$user,$key,$namespace,['scope'=>['mode'=>'folder','folder_id'=>2],'expected_revision'=>$project['revision']]));
    $folder=knowledge_r6_set($pdo,$user,$key,$namespace,['scope'=>['mode'=>'folder','folder_id'=>1],'expected_revision'=>$project['revision']]);
    $folderPacket=knowledge_r6_resolve($pdo,$user,$key,$namespace,['query'=>'flour','attachments'=>$refs,'state_revision'=>$folder['revision']]);
    check($folderPacket['sources']&&array_reduce($folderPacket['sources'],static fn($ok,$s)=>$ok&&($s['folder_id']??0)===1,true),'Folder context broadened outside its canonical IDs.');
    denied(fn()=>knowledge_r6_resolve($pdo,$user,$key,$namespace,['query'=>'flour','state_revision'=>$project['revision']]));
    $saved=knowledge_r6_state($pdo,$user,$key,$namespace);check($saved['revision']===$folder['revision'],'Context did not survive a fresh read.');
    $edit=['confirmed'=>true,'id'=>$item['id'],'expected_revision'=>$item['revision'],'note'=>'Updated source revision','tags'=>$item['tags'],'folder_id'=>$item['folder_id'],'project_id'=>$item['project_id']];
    capture_r5_edit($pdo,$user,$edit);denied(fn()=>knowledge_r6_recheck($pdo,$user,$namespace,$folderPacket,'flour'));
    $current=capture_r5_list($pdo,$user,['id'=>$item['id']])['items'][0];
    $freshRefs=[['kind'=>'annotation','id'=>$current['id'],'revision'=>$current['revision']]];
    $fresh=knowledge_r6_resolve($pdo,$user,$key,$namespace,['query'=>'flour','attachments'=>$freshRefs,'state_revision'=>$folder['revision']]);
    $GLOBALS['grant']=false;denied(fn()=>knowledge_r6_resolve($pdo,$user,$key,$namespace,['query'=>'flour']));$GLOBALS['grant']=true;
    denied(fn()=>knowledge_r6_set($pdo,$user,$key,$namespace,['scope'=>['mode'=>'off'],'expected_revision'=>$folder['revision']],static function(){throw new RuntimeException('Permission revoked.',403);}));
    check(knowledge_r6_state($pdo,$user,$key,$namespace)['revision']===$folder['revision'],'Revocation left a partial settings write.');
    if($path=getenv('VP3_KNOWLEDGE_R6_EXPORT'))file_put_contents($path,json_encode(['packet'=>$fresh,'item'=>$current,'scope_input'=>['attachments'=>$freshRefs,'state_revision'=>$folder['revision']]],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    $off=knowledge_r6_set($pdo,$user,$key,$namespace,['scope'=>['mode'=>'off'],'expected_revision'=>$folder['revision']]);
    $empty=knowledge_r6_resolve($pdo,$user,$key,$namespace,['query'=>'flour']);check($empty['active']&&$empty['sources']===[],'Knowledge off performed retrieval.');
    denied(fn()=>knowledge_r6_resolve($pdo,$user,$key,$namespace,['query'=>'flour','attachments'=>$freshRefs]));
    denied(fn()=>knowledge_r6_recheck($pdo,$user,$namespace,$fresh,'flour'));
    echo "PASS: R6 canonical InnoDB scope persistence, project/folder isolation, multiple-source revision fences, opt-out, stale selection and post-inference source/authority changes, exact settings retries and rollback.\n";
};
require __DIR__.'/browser-capture-routing-r4-mysql.php';
