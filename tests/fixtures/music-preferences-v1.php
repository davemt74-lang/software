<?php
declare(strict_types=1);

require_once __DIR__.'/music-history-v1.php';
require_once dirname(__DIR__,2).'/includes/music-history-chat-v1.php';
require_once dirname(__DIR__,2).'/includes/music-preferences-v1.php';

/** Existing production storage/binding paths, with only the inherited auth/provider adapters. */
function music_preferences_fixture_bindings_v1(PDO $pdo,array $history,int $cid=42): array
{
    $user=current_user();
    $page=vp3_music_history_chat_query_v1($pdo,$user,7,$cid,['limit'=>50])['music_history_v1'];
    $selections=[];
    foreach($history['refs'] as $name=>$ref)$selections[$name]=[
        'kind'=>'history','source_message_id'=>$page['source_message_id'],'revision'=>$page['revision'],'entry_ref'=>$ref,
    ];
    $key='preferences-fixture-'.bin2hex(random_bytes(12));
    $items=[
        ['source'=>'vp3','source_id'=>'71'],music_chat_fixture_video_v1(6),['source'=>'vp3','source_id'=>'42'],
    ];
    $result=vp3_music_chat_result_store_v1($pdo,$user,7,$cid,$key,hash('sha256',$key),$items,
        ['intent'=>'show','source'=>'both','requested_count'=>3,'create_playlist'=>false]);
    $message=music_chat_fixture_message_v1($pdo,$cid);
    $result=vp3_music_chat_result_bind_v1($pdo,$user,7,$cid,$result['result_id'],$message);
    $pdo->prepare('UPDATE chat_messages SET context_json=? WHERE id=?')->execute([
        json_encode(['music_chat_v1'=>vp3_music_chat_result_reference_v1($result),'skip_brain_archive'=>true],JSON_THROW_ON_ERROR),$message,
    ]);
    $listKey=$key.'-list';
    $saved=vp3_music_chat_result_store_v1($pdo,$user,7,$cid,$listKey,hash('sha256',$listKey),$items,
        ['intent'=>'playlist','source'=>'both','requested_count'=>3,'create_playlist'=>true,'title'=>'Preference fixture list']);
    $savedMessage=music_chat_fixture_message_v1($pdo,$cid);
    $saved=vp3_music_chat_result_bind_v1($pdo,$user,7,$cid,$saved['result_id'],$savedMessage);
    $pdo->prepare('UPDATE chat_messages SET context_json=? WHERE id=?')->execute([
        json_encode(['music_chat_v1'=>vp3_music_chat_result_reference_v1($saved),'skip_brain_archive'=>true],JSON_THROW_ON_ERROR),$savedMessage,
    ]);
    foreach(['native'=>0,'youtube'=>1,'other'=>2] as $name=>$index){
        $selections['result_'.$name]=['kind'=>'result','result_id'=>$result['result_id'],'revision'=>$result['revision'],'index'=>$index];
        $selections['saved_'.$name]=['kind'=>'playlist','playlist_id'=>$saved['playlist']['id'],'revision'=>$saved['playlist']['revision'],'index'=>$index];
    }
    return compact('page','result','saved','selections');
}

function music_preferences_fixture_uuid_v1(): string
{
    $hex=bin2hex(random_bytes(16));
    return substr($hex,0,8).'-'.substr($hex,8,4).'-4'.substr($hex,13,3).'-8'.substr($hex,17,3).'-'.substr($hex,20,12);
}

/** Full database digest catches writes by supposedly read-only preference/context paths. */
function music_preferences_fixture_digest_v1(PDO $pdo,array $except=[]): string
{
    $tables=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'
        ?$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)
        :$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
    sort($tables);$data=[];
    foreach($tables as $table){
        if(in_array($table,$except,true))continue;
        if(!preg_match('/\A[a-zA-Z0-9_]+\z/D',$table))throw new RuntimeException('Invalid fixture table.');
        $rows=$pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);
        $encoded=array_map(static fn(array $row):string=>json_encode($row,JSON_THROW_ON_ERROR),$rows);
        sort($encoded);$data[$table]=$encoded;
    }
    return hash('sha256',json_encode($data,JSON_THROW_ON_ERROR));
}
