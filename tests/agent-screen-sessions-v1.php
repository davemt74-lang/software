<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/knowledge.php';
require dirname(__DIR__).'/includes/agent-screen-sessions-v1.php';
require dirname(__DIR__).'/includes/agent-screen-audio-v1.php';
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$pdo->sqliteCreateFunction('NOW',fn()=>gmdate('Y-m-d H:i:s'));
function db(): PDO{return $GLOBALS['pdo'];}
function current_user(): array{return ['id'=>1];}
function table_exists($name): bool{$q=db()->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$name]);return (bool)$q->fetchColumn();}
function column_exists(...$args): bool{return true;}
function personal_capability_has_v242($cap,$user): bool{return empty($user['denied']);}
function has_permission($cap,$user): bool{return empty($user['denied']);}
function url($path): string{return $path;}
function vp3_agent_memory_scope_from_conversation_v410($user,$id,$set=true): int{if($id!==7||$user['id']!==1)throw new RuntimeException('Wrong conversation.',403);return 4;}
function vp3_agent_memory_scope_set_current_v410($uid,$aid): void{$GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']=['user_id'=>$uid,'user_agent_id'=>$aid];}
function agent_brain_schema_ready(): bool{return true;}
function agent_brain_v122_upsert_system_memory($user,$type,$subject,$text,$meta,$confidence): int{$q=db()->prepare('SELECT id FROM agent_memory_items WHERE user_id=? AND subject=?');$q->execute([$user['id'],$subject]);$id=(int)$q->fetchColumn();if($id)return $id;db()->prepare('INSERT INTO agent_memory_items(user_id,memory_type,subject,memory_text,agent_id) VALUES(?,?,?,?,?)')->execute([$user['id'],$type,$subject,$text,$GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']['user_agent_id']]);return (int)db()->lastInsertId();}
function ai_elevenlabs_credential(): array{return [$GLOBALS['noKey']??false?'':'fixture','fixture'];}
function subscription_has_entitlement($user,$scope): bool{return empty($user['denied']);}
$pdo->exec("CREATE TABLE artist_transcript_folders_v177(id INTEGER PRIMARY KEY AUTOINCREMENT,created_by_user_id INTEGER,folder_name TEXT,sort_order INTEGER DEFAULT 0,created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP,UNIQUE(created_by_user_id,folder_name));CREATE TABLE knowledge_items(id INTEGER PRIMARY KEY AUTOINCREMENT,track_id INTEGER,folder_id INTEGER,title TEXT,description TEXT,file_name TEXT,file_path TEXT,file_type TEXT,mime_type TEXT,file_size INTEGER,content_text TEXT,visibility TEXT,is_published INTEGER,created_by_user_id INTEGER,knowledge_scope TEXT,updated_at TEXT DEFAULT CURRENT_TIMESTAMP);CREATE TABLE knowledge_chunks(id INTEGER PRIMARY KEY AUTOINCREMENT,knowledge_id INTEGER,chunk_index INTEGER,chunk_text TEXT);CREATE TABLE agent_memory_items(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,memory_type TEXT,subject TEXT,memory_text TEXT,agent_id INTEGER)");
screen_sessions_schema_v1($pdo);$user=['id'=>1];$other=['id'=>2];$sid='00000000-0000-4000-8000-000000000001';$cid='00000000-0000-4000-8000-000000000002';$checks=0;
function check(bool $v,string $label): void{if(!$v)throw new RuntimeException($label);echo "PASS $label\n";$GLOBALS['checks']++;}
function rejects(callable $f,string $label,int $code=0): void{try{$f();}catch(Throwable $e){check(!$code||$e->getCode()===$code,$label);return;}throw new RuntimeException('Expected rejection: '.$label);}
$s=screen_session_start_v1($pdo,$user,['session_id'=>$sid,'title'=>'Music research','conversation_id'=>7]);$fid=$s['folder_id'];
check(count(personal_knowledge_folders($pdo,$user))===1&&$fid>0,'session creates a real owner folder in the existing Knowledge library');
check(str_starts_with($s['knowledge_url'],'/knowledge.php?folder='.$fid),'session opens the existing My Knowledge folder');
check(count(personal_knowledge_folders($pdo,$other))===0,'another account cannot see this folder');
check(screen_session_start_v1($pdo,$user,['session_id'=>$sid])['folder_id']===$fid&&count(personal_knowledge_folders($pdo,$user))===1,'start retry reuses its session folder');
rejects(fn()=>screen_sessions_permission_v1(['id'=>1,'denied'=>true],true),'management permissions enforced',403);
rejects(fn()=>screen_session_v1($pdo,$other,$sid),'another account cannot read a session',404);
$frame=screen_frame_v1(['image'=>'data:image/jpeg;base64,'.trim(file_get_contents(__DIR__.'/fixtures/tv-frame.b64')),'captured_at'=>gmdate('Y-m-d\TH:i:s\Z'),'surface'=>'browser']);$frame['note']='Compare this song';
$r=screen_capture_store_v1($pdo,$user,$sid,$cid,$frame);check($r['knowledge_id']>0&&get_knowledge_item((int)$r['knowledge_id'])['folder_id']===$fid,'capture note is stored and indexed in its existing session folder');
check(!str_contains(get_knowledge_item((int)$r['knowledge_id'])['content_text'],'base64'),'raw screenshot bytes stay out of Knowledge search and Brain');
check(!isset(screen_capture_public_v1($r)['body']['image'])&&isset(screen_capture_public_v1($r,true)['body']['image']),'history lists omit pixels; authorized detail includes the screenshot');
check(screen_capture_store_v1($pdo,$user,$sid,$cid,$frame)['knowledge_id']===$r['knowledge_id'],'capture retry does not duplicate Knowledge notes');
rejects(fn()=>screen_capture_v1($pdo,$other,$cid),'another account cannot read capture pixels',404);
rejects(fn()=>screen_capture_brain_v1($pdo,$user,$cid),'unanalyzed pixels are not promoted as Brain facts',409);
$vision=fn()=>['ok'=>true,'answer'=>json_encode(['summary'=>'A song is at 1:20','visible_text'=>'Artist · Song · 1:20 / 4:00','site_hint'=>'Player','page_title'=>'Song','observations'=>[['kind'=>'music','label'=>'Playback position','detail'=>'1:20 of 4:00 displayed','confidence'=>'high']]])];
$r=screen_capture_analyze_v1($pdo,$user,$cid,$vision);check($r['revision']===2&&str_contains(get_knowledge_item((int)$r['knowledge_id'])['content_text'],'1:20 of 4:00'),'structured observations update the canonical Knowledge note');
check($r['body']['observation']['captured_at']===$frame['captured_at'],'reanalyzing preserves original observation time');
$GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']=['user_id'=>1,'user_agent_id'=>9];$r=screen_capture_brain_v1($pdo,$user,$cid);check($r['memory_id']>0&&(int)$pdo->query('SELECT agent_id FROM agent_memory_items')->fetchColumn()===4,'Brain promotion uses the session’s server-resolved Agent scope');
check($GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']['user_agent_id']===9,'Brain promotion restores the previous scope');
check(screen_capture_brain_v1($pdo,$user,$cid)['memory_id']===$r['memory_id'],'repeated Brain saves are idempotent');
$unrelated=personal_knowledge_store($user,'unrelated','Keep me','Unrelated user note','',$fid);
$mid=(int)$r['memory_id'];screen_session_delete_v1($pdo,$user,$sid,true);
check(get_knowledge_item($unrelated)!==null&&personal_knowledge_folder($pdo,$user,$fid)!==null,'session deletion preserves unrelated notes and the shared folder');
check((int)$pdo->query('SELECT COUNT(*) FROM agent_screen_captures_v1')->fetchColumn()===0&&(int)$pdo->query('SELECT COUNT(*) FROM agent_memory_items')->fetchColumn()===0,'session deletion removes pixels, transcript records and selected Brain memories');
check((int)$pdo->query('SELECT COUNT(*) FROM knowledge_items')->fetchColumn()===1,'session-generated Knowledge notes are deleted');
rejects(fn()=>screen_capture_store_v1($pdo,$user,$sid,$cid,$frame),'late capture cannot resurrect a deleted session',404);
rejects(fn()=>screen_session_start_v1($pdo,$user,['session_id'=>$sid]),'a deleted session nonce cannot recreate its data',409);
$sid2='00000000-0000-4000-8000-000000000003';$cid2='00000000-0000-4000-8000-000000000004';screen_session_start_v1($pdo,$user,['session_id'=>$sid2]);screen_capture_store_v1($pdo,$user,$sid2,$cid2,$frame);
rejects(fn()=>screen_capture_analyze_v1($pdo,$user,$cid2,function()use($pdo,$user,$sid2,$vision){screen_session_delete_v1($pdo,$user,$sid2,true);return $vision();}),'deletion during model execution fences late analysis',404);
$sid3='00000000-0000-4000-8000-000000000005';$cid3='00000000-0000-4000-8000-000000000006';screen_session_start_v1($pdo,$user,['session_id'=>$sid3]);screen_capture_store_v1($pdo,$user,$sid3,$cid3,['captured_at'=>gmdate('Y-m-d\TH:i:s\Z'),'transcript'=>'A useful spoken idea','surface'=>'browser'],'tab_audio');$r=screen_capture_brain_v1($pdo,$user,$cid3);screen_session_delete_v1($pdo,$user,$sid3,false);check((int)$pdo->query('SELECT COUNT(*) FROM agent_memory_items')->fetchColumn()===1,'user can explicitly retain a Brain memory when deleting source captures');
$file=tempnam(sys_get_temp_dir(),'screen-audio-');file_put_contents($file,'RIFF'.pack('V',100).'WAVE'.str_repeat("\0",100));$upload=['tmp_name'=>$file,'error'=>UPLOAD_ERR_OK];
check(screen_audio_transcribe_v1($upload,$user,fn($file,$model)=>['text'=>'Tab speech'])==='Tab speech','valid audio uses the configured speech service and returns text');
file_put_contents($file,'<script>'.str_repeat('x',100));rejects(fn()=>screen_audio_file_v1($upload),'non-audio uploads rejected');unlink($file);
check((int)$pdo->query('SELECT COUNT(*) FROM agent_screen_captures_v1')->fetchColumn()===0,'raw audio bytes are not retained as capture files');
echo "AGENT_SCREEN_SESSIONS_V1=PASS ($checks cases)\n";
