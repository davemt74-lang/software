<?php
declare(strict_types=1);

// Native MySQL stores, encryption, authority and review projection. No provider/model I/O.
require_once dirname(__DIR__).'/includes/agent-specialist-invitations-v1.php';
require_once dirname(__DIR__).'/includes/agent-email-review-queue-v1.php';
require_once dirname(__DIR__).'/includes/agent-coordination-work-v1.php';
require_once dirname(__DIR__).'/includes/agent-casual-checkin-reply-v1.php';
require_once dirname(__DIR__).'/includes/agent-chat-boundary-v380.php';
require_once dirname(__DIR__).'/includes/user-agent-system-v236.php';
function db(): PDO{return $GLOBALS['pdo'];}
function current_user(): array{return ['id'=>1,'is_active'=>1];}
function setting(string $name,mixed $fallback=''): mixed{return $fallback;}
function url(string $path): string{return $path;}
function has_permission(string $permission,array $user): bool{return !empty($user['is_active'])&&empty($GLOBALS['deny_invitation']);}
function table_exists(string $name): bool{
    $q=db()->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$q->execute([$name]);return(bool)$q->fetchColumn();
}
function invitation_check(bool $ok,string $label): void{
    if(!$ok)throw new RuntimeException($label);++$GLOBALS['invitation_checks'];echo 'PASS '.$label.PHP_EOL;
}
function invitation_reject(callable $read,string $label): void{
    try{$read();}catch(RuntimeException|DomainException $error){if($error instanceof PDOException)throw $error;invitation_check(true,$label);return;}throw new RuntimeException($label);
}
function invitation_seed(array $fields=[]): array{
    $owner=$fields['owner']??1;$agent=$fields['agent']??7;$chat=$fields['chat']??11;
    db()->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES(?,?,'user','Prepare a private draft.')")->execute([$chat,$owner]);
    $mid=(int)db()->lastInsertId();$id=bin2hex(random_bytes(16));$created=time()-100;
    $payload=['subject'=>$fields['subject']??'Contract renewal','body'=>'PRIVATE_BODY_NEVER_INVITATION','composer'=>['version'=>1,'saved_at'=>$created]];
    if(isset($fields['priority'])||isset($fields['deadline'])){
        $payload['reply']=['message_id'=>'source_'.$id,'thread_id'=>'thread_'.$id,'in_reply_to'=>'<'.$id.'@example.test>','references'=>['<'.$id.'@example.test>']];
        $payload['composer']=['version'=>2,'saved_at'=>$created,'created_at'=>$created,'origin'=>'organic_reply',
            'source'=>['reason'=>'Saved specialist review.','priority'=>$fields['priority']??'normal','deadline'=>$fields['deadline']??null]];
    }
    if(!empty($fields['automatic'])){$payload['auto_revision']=str_repeat('e',64);}
    db()->prepare('INSERT INTO agent_connection_email_drafts_v1(id,owner_user_id,agent_id,conversation_id,user_message_id,connection_id,authorization_key,account_email,recipient,message_enc,status,expires_at)
        VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$id,$owner,$agent,$chat,$mid,$owner===1?7:9,$fields['grant']??str_repeat($owner===1?'a':'b',64),
            $owner===1?'owner@example.test':'other@example.test','recipient@example.test',$fields['encrypted']??ai_encrypt_secret(json_encode($payload,JSON_THROW_ON_ERROR)),
            $fields['status']??'prepared',$fields['expires']??0]);
    return agent_connection_email_chat_draft_v1(db(),$owner,$agent,$chat,$id);
}
function invitation_state(): string{
    $tables=['agent_connection_email_drafts_v1','chat_messages','agent_connection_sends_v1','agent_connection_email_followups_v1','settings'];$out=[];
    foreach($tables as $name)$out[$name]=db()->query('SELECT * FROM '.$name.' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
    return json_encode($out,JSON_THROW_ON_ERROR);
}
class InvitationRaceStatement extends PDOStatement{
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array{
        $rows=parent::fetchAll($mode,...$args);
        if(str_contains($this->queryString,'SELECT d.*')&&is_callable($GLOBALS['invitation_race']??null)){
            $race=$GLOBALS['invitation_race'];unset($GLOBALS['invitation_race']);$race();
        }
        return $rows;
    }
}
$dsn=(string)getenv('VP3_TEST_MYSQL_DSN');
if(!str_starts_with($dsn,'mysql:'))throw new RuntimeException('Native MySQL is required for specialist invitation acceptance.');
$login=getenv('VP3_TEST_MYSQL_USER')?:'root';$password=(string)getenv('VP3_TEST_MYSQL_PASS');
$opts=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
$server=new PDO($dsn,$login,$password,$opts);$database='vp3_invitations_'.bin2hex(random_bytes(6));
$server->exec('CREATE DATABASE '.$database.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$root=sys_get_temp_dir().'/vp3-invitations-'.bin2hex(random_bytes(6));mkdir($root,0700);define('STONEFELLOW_ROOT',$root);
$config=['connections'=>['gmail'=>['client_id'=>'invitation-fixture.apps.googleusercontent.com','client_secret'=>str_repeat('x',24),
    'redirect_uri'=>'https://invitation-fixture.example.test/connection-oauth.php']]];
$invitation_checks=0;
try{
    $pdo=new PDO(preg_replace('/;dbname=[^;]+/','',$dsn).';dbname='.$database,$login,$password,$opts);
    $pdo->exec("CREATE TABLE users(id INT UNSIGNED PRIMARY KEY,is_active INT);INSERT INTO users VALUES(1,1),(2,1);
        CREATE TABLE settings(setting_key VARCHAR(190) PRIMARY KEY,setting_value TEXT);
        CREATE TABLE user_agents(id BIGINT UNSIGNED PRIMARY KEY,owner_user_id INT UNSIGNED,display_name VARCHAR(100),is_active INT);
        INSERT INTO user_agents VALUES(7,1,'Main Agent',1),(8,1,'Other Agent',1),(9,2,'Other owner',1);
        CREATE TABLE chat_conversations(id BIGINT UNSIGNED PRIMARY KEY,user_id INT UNSIGNED,user_agent_id BIGINT UNSIGNED NULL,title VARCHAR(190));
        INSERT INTO chat_conversations VALUES(11,1,7,'Private'),(12,1,7,'Other private'),(13,1,8,'Other Agent'),(14,1,NULL,'System'),(21,2,9,'Other owner');
        CREATE TABLE chat_messages(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,conversation_id BIGINT UNSIGNED,user_id INT UNSIGNED NULL,role VARCHAR(20),message TEXT,context_json TEXT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    agent_connections_install_v1($pdo);agent_connection_email_draft_schema_v1($pdo);agent_connection_email_followup_install_v1($pdo);
    $q=$pdo->prepare("INSERT INTO agent_connections_v1(id,owner_user_id,provider,status,read_enabled,send_enabled,followups_enabled,authorization_key,client_fingerprint,account_email,access_token_enc,refresh_token_enc)
        VALUES(?,?,'gmail','connected',1,1,1,?,?,?,'','')");
    foreach([[7,1,'a','owner'],[9,2,'b','other']] as [$id,$owner,$letter,$mail])$q->execute([$id,$owner,str_repeat($letter,64),agent_connections_client_fingerprint_v1(),$mail.'@example.test']);
    $user=current_user();$principal=vp3_agent_chat_principal_v380($user,vp3_agent_chat_resolve_agent_v380($pdo,$user,7));
    $scope=['user'=>$user,'principal'=>$principal,'agent_id'=>7,'conversation_id'=>11];
    $binding=['enabled'=>true,'conversation_id'=>11,'agent_id'=>7,'revision'=>str_repeat('f',64)];
    $read=static fn(?array $pin=null):array=>vp3_specialist_invitation_email_v1($pdo,$scope,$binding,$pin);
    invitation_check($read()['state']==='empty','verified empty queue permits the existing generic compose fallback');
    $normal=invitation_seed(['subject'=>'Contract renewal']);
    $due=invitation_seed(['subject'=>'Workshop planning','deadline'=>gmdate('Y-m-d',time()+86400)]);
    $urgent=invitation_seed(['chat'=>12,'subject'=>'Supplier confirmation','priority'=>'high']);
    invitation_seed(['subject'=>'Newer ordinary draft']);
    foreach(['sending','sent','discarded','answered','stale','source_check','write_queued','writing','write_failed','failed'] as $status)invitation_seed(['status'=>$status,'subject'=>'BLOCKED_'.$status]);
    invitation_seed(['encrypted'=>'corrupt','subject'=>'CORRUPT']);
    invitation_seed(['grant'=>str_repeat('b',64),'subject'=>'REVOKED_GRANT']);
    invitation_seed(['priority'=>'high','automatic'=>true,'subject'=>'AUTOMATIC']);
    invitation_seed(['priority'=>'high','subject'=>'EXPIRED','expires'=>time()-1]);
    invitation_seed(['agent'=>8,'chat'=>13,'subject'=>'OTHER_AGENT']);
    invitation_seed(['agent'=>0,'chat'=>14,'subject'=>'SYSTEM_AGENT']);
    invitation_seed(['owner'=>2,'agent'=>9,'chat'=>21,'subject'=>'OTHER_OWNER']);
    $before=invitation_state();$choice=$read();$e=$choice['choice']['evidence'];$pin=$e['invitation_pin'];$text=json_encode($choice,JSON_THROW_ON_ERROR);
    invitation_check($choice['state']==='ready'&&count($e['email_draft_targets'])===3,'bounded invitation lists three actually reviewable saved drafts');
    invitation_check($pin['drafts'][0]['id']===$urgent['id']&&$pin['drafts'][1]['id']===$due['id'],'high priority and saved deadline outrank newer generic drafts');
    invitation_check(str_contains($text,'high priority')&&str_contains($text,'saved deadline'),'each ranking reason states the saved evidence');
    foreach(['BLOCKED_','CORRUPT','REVOKED_GRANT','AUTOMATIC','EXPIRED','OTHER_AGENT','SYSTEM_AGENT','OTHER_OWNER','PRIVATE_BODY_NEVER_INVITATION','review_token','authorization_key','access_token'] as $sentinel)
        invitation_check(!str_contains($text,$sentinel),'private invitation excludes '.$sentinel);
    invitation_check(invitation_state()===$before,'building invitations writes no draft, approval, Chat, send, watch or preference');
    invitation_check($read($pin)===$choice,'exact pinned reread returns the same choices');
    $other=['id'=>2,'is_active'=>1];$otherPrincipal=vp3_agent_chat_principal_v380($other,vp3_agent_chat_resolve_agent_v380($pdo,$other,9));
    invitation_check(vp3_specialist_invitation_email_v1($pdo,['user'=>$other,'principal'=>$otherPrincipal,'agent_id'=>9,'conversation_id'=>21],$binding,$pin)['state']==='blocked',
        'copied exact draft pins cannot expose another owner');
    $otherPrincipal=vp3_agent_chat_principal_v380($user,vp3_agent_chat_resolve_agent_v380($pdo,$user,8));
    invitation_check(vp3_specialist_invitation_email_v1($pdo,['user'=>$user,'principal'=>$otherPrincipal,'agent_id'=>8,'conversation_id'=>13],$binding,$pin)['state']==='blocked',
        'copied exact draft pins cannot cross the selected Agent');
    $GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']=['owner'=>1,'agent'=>7,'participant'=>9,'revision'=>1];
    try{invitation_reject(static fn()=>$read(),'participant scope cannot read private owner invitations');}
    finally{unset($GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']);}
    invitation_seed(['subject'=>'New high priority','priority'=>'high','deadline'=>gmdate('Y-m-d',time()+3600)]);
    invitation_check($read($pin)===$choice,'newer drafts cannot retarget an already offered list');
    $item=$e+['ref'=>'casual:email','specialist'=>'casual','revision'=>str_repeat('a',64),'title'=>'Saved drafts','summary'=>'Ready to review.','next_step'=>'','question'=>$choice['choice']['question']];
    $goal=['tool'=>'coordination.review','coordination_id'=>str_repeat('b',64),'primary'=>'casual:email','sources'=>['casual:email'=>str_repeat('a',64)]];
    $result=vp3_coordination_saved_result_v1($goal,['casual:email'=>$item],['specialist'=>'casual','trigger'=>'email','action'=>'ask']);
    invitation_check(vp3_coordination_result_normalize_v1($result)===$result,'structured list survives the native coordination result contract');
    invitation_check(str_contains($result['draft'],'1. Supplier confirmation')&&str_contains($result['draft'],'Which of these drafts'),'canonical Chat post contains a readable list and a concrete question');
    $altered=$result;$altered['output']['invitation']['items'][0]['title']='Invented draft';
    invitation_check(vp3_coordination_result_normalize_v1($altered)===null,'an invented presentation cannot keep the original canonical Chat text');
    $bad=$e['invitation'];$bad['items'][0]['title']='<script>alert(1)</script>';
    invitation_check(vp3_specialist_invitation_normalize_v1($bad)===null,'unsafe presentation is rejected');
    $escaped=vp3_specialist_invitation_draft_v1(['kind'=>'music','items'=>[['title'=>'[Play](javascript:alert(1))','detail'=>'**not a command**']],'context'=>''],'Try this?');
    invitation_check(str_contains($escaped,'1. [Play](javascript:alert(1))')&&str_contains($escaped,'**not a command**'),'source punctuation remains inert plain text in the canonical invitation');
    invitation_check(vp3_specialist_invitation_connection_v1('Workshop planning',['family'=>'ignored'])===null,'malformed analysis does not invent an invitation connection');
    $reports=[['family'=>'brainstorm','key'=>str_repeat('c',64),'text'=>'Prepare workshop planning materials.']];
    invitation_check(str_starts_with(vp3_specialist_invitation_connection_v1('Workshop planning',$reports)['text'],'Possible connection'),'analysis overlap is labelled as a possible connection');
    invitation_check(vp3_specialist_invitation_connection_v1('Supplier renewal',$reports)===null,'unrelated analysis is not attached');
    foreach(['first'=>'draft_1','Review second draft'=>'draft_2','3'=>'draft_3','yes'=>'accept','no thanks'=>'decline'] as $reply=>$intent)
        invitation_check(vp3_casual_checkin_intent_v1((string)$reply)===$intent,'explicit reply keeps its intended decision: '.$reply);
    $pdo->prepare('UPDATE agent_connection_email_drafts_v1 SET recipient=? WHERE id=?')->execute(['changed@example.test',$urgent['id']]);
    invitation_check($read($pin)['state']==='blocked','changed exact draft revision invalidates the whole offered selection');
    $newPin=$read()['choice']['evidence']['invitation_pin'];$removed=$newPin['drafts'][0]['id'];
    $pdo->prepare("UPDATE agent_connection_email_drafts_v1 SET status='discarded' WHERE id=?")->execute([$removed]);
    invitation_check($read($newPin)['state']==='blocked','discarded selection never substitutes another saved draft');
    $livePin=$read()['choice']['evidence']['invitation_pin'];
    $pdo->exec('UPDATE agent_connections_v1 SET send_enabled=0 WHERE id=7');
    invitation_check($read($livePin)['state']==='blocked','revoked send access blocks the saved invitation');
    $pdo->exec('UPDATE agent_connections_v1 SET send_enabled=1,read_enabled=0 WHERE id=7');
    $new=$read();invitation_check($new['state']==='ready'&&!str_contains(json_encode($new),'Workshop planning'),'revoked read access hides original-message reply metadata while retaining owned new drafts');
    $pdo->exec('UPDATE agent_connections_v1 SET read_enabled=1 WHERE id=7');
    $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[InvitationRaceStatement::class]);
    $GLOBALS['invitation_race']=static fn()=>$pdo->exec("UPDATE agent_connections_v1 SET authorization_key=REPEAT('b',64) WHERE id=7");
    $raceBlocked=false;
    try{$raceBlocked=$read()['state']==='blocked';}
    catch(RuntimeException $error){if($error instanceof PDOException||$error->getCode()!==409)throw $error;$raceBlocked=true;}
    invitation_check($raceBlocked,'a grant revoked during the actual projection cannot release old draft metadata');
    foreach([
        ['music','music','Play that one.','accept'],['youtube','youtube','Play that one.','accept'],
        ['music','music','Watch that one.','clarify'],['media','media','Yes.','clarify'],['youtube',null,'Play that one.','clarify'],
    ] as [$kind,$presentation,$reply,$expected]){
        $output=['type'=>'initiative_coordination','state'=>'needs_owner','evidence_revisions'=>['casual:'.$kind=>str_repeat('a',64)]];
        if($presentation!==null)$output['invitation']=['kind'=>$presentation];
        $context=['source'=>'agent_initiative','initiative_output'=>$output,
            'initiative_followthrough_state'=>['state'=>'open','revision'=>1,'reply_ref'=>str_repeat('f',32)]];
        $pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json) VALUES(11,NULL,'assistant','A saved invitation.',?)")
            ->execute([json_encode($context,JSON_THROW_ON_ERROR)]);$mid=(int)$pdo->lastInsertId();
        $target=vp3_casual_checkin_reply_target_v1($pdo,$user,7,11,$reply,['message_id'=>$mid,'reply_ref'=>str_repeat('f',32),'revision'=>1]);
        invitation_check(($target['intent']??null)===$expected,'exact named playback and ambiguous legacy choices retain their own intent: '.$kind.' '.$reply);
    }
    $pdo->exec('UPDATE users SET is_active=0 WHERE id=1');
    invitation_reject(static fn()=>$read(),'inactive owner cannot project private invitation data');
    echo 'PASS specialist invitation native acceptance ('.$invitation_checks.' checks)'.PHP_EOL;
}finally{
    if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();$server->exec('DROP DATABASE '.$database);
    foreach(glob($root.'/private/*')?:[] as $file)unlink($file);if(is_dir($root.'/private'))rmdir($root.'/private');rmdir($root);
}
