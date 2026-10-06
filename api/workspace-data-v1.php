<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/homeserver-workspace-sync-v1.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
$user=current_user();
if(!$user||empty($user['is_active'])||!has_permission('account.access',$user)){http_response_code(401);echo json_encode(['ok'=>false,'error'=>'Sign in to read your workspace.']);exit;}
try{
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')throw new RuntimeException('GET required.',405);
    $pdo=db();workspace_sync_schema_v1($pdo);$uid=(int)$user['id'];
    $dataset=workspace_sync_dataset_v1($_GET['dataset']??'contacts');
    $snapshot=workspace_sync_read_v1($pdo,$uid,'homeserver',$dataset);
    $records=$snapshot?json_decode($snapshot['body_json'],true,512,JSON_THROW_ON_ERROR)['records']:[];
    $key=trim((string)($_GET['key']??''));$query=mb_strtolower(mb_substr(trim((string)($_GET['q']??'')),0,240));
    if($key!=='')$records=array_values(array_filter($records,static fn($row)=>$row['table'].':'.$row['source_id']===$key));
    if($query!=='')$records=array_values(array_filter($records,static fn($row)=>str_contains(mb_strtolower(json_encode($row['data'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)),$query)));
    $count=count($records);$offset=max(0,(int)($_GET['offset']??0));$page=array_slice($records,$offset,50);
    if($key==='')$page=array_map(static function(array $row):array{
        $data=$row['data'];$title=$data['title']??$data['name']??$data['display_name']??$data['memory_key']??$data['collection_key']??$row['source_id'];
        $preview=$data['description']??$data['content']??$data['text']??$data['summary']??$data['notes']??'';
        return ['table'=>$row['table'],'source_id'=>$row['source_id'],'title'=>mb_substr((string)$title,0,240),'preview'=>mb_substr((string)$preview,0,300),'updated_at'=>$data['updated_at']??$data['created_at']??null];
    },$page);
    $q=$pdo->prepare("SELECT dataset,record_count,synced_at FROM homeserver_workspace_snapshots_v1 WHERE user_id=? AND source='homeserver' ORDER BY dataset");$q->execute([$uid]);
    echo json_encode(['ok'=>true,'items'=>$page,'count'=>$count,'datasets'=>array_keys(workspace_sync_registry_v1()),'synced_at'=>$snapshot['synced_at']??null,'coverage'=>$q->fetchAll(PDO::FETCH_ASSOC),'read_only'=>true],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){http_response_code($e instanceof InvalidArgumentException?422:503);echo json_encode(['ok'=>false,'error'=>'Workspace data is temporarily unavailable.']);}
