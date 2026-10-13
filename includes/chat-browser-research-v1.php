<?php
declare(strict_types=1);
require_once __DIR__.'/chat-research-continuity-r3.php';
require_once __DIR__.'/knowledge-refresh-guard-r7.php';

/** Durable Chat requests reference the existing Chrome research evidence ledger. */
function chat_research_schema_v1(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_browser_research_requests_v1 (
      id CHAR(36) NOT NULL PRIMARY KEY, owner_user_id INT UNSIGNED NOT NULL,
      request_id CHAR(36) NOT NULL, context_key VARCHAR(200) NOT NULL,
      agent_namespace VARCHAR(80) NOT NULL, agent_id INT UNSIGNED NULL,
      runtime_id CHAR(36) NOT NULL, conversation_id INT UNSIGNED NULL,
      question TEXT NOT NULL, urls_json TEXT NOT NULL, review_hash CHAR(64) NOT NULL,
      status VARCHAR(32) NOT NULL DEFAULT 'prepared', mission_id CHAR(36) NULL,
      device_key VARCHAR(190) NULL, lease_token CHAR(64) NULL, lease_until DATETIME NULL,
      next_source INT UNSIGNED NOT NULL DEFAULT 0, last_error VARCHAR(190) NOT NULL DEFAULT '',
      expires_at DATETIME NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_chat_research_request_v1 (owner_user_id,request_id),
      UNIQUE KEY uq_chat_research_mission_v1 (mission_id),
      INDEX idx_chat_research_queue_v1 (owner_user_id,status,created_at),
      CONSTRAINT fk_chat_research_owner_v1 FOREIGN KEY(owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function chat_research_uuid_v1(mixed $value): string
{
    if(!is_string($value)||!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',$value))
        throw new InvalidArgumentException('A canonical research request identifier is required.');
    return $value;
}

function chat_research_urls_v1(mixed $question): array
{
    if(!is_string($question)||mb_strlen(trim($question))<5||mb_strlen($question)>2000)
        throw new InvalidArgumentException('Describe the research request in 5 to 2,000 characters.');
    preg_match_all('~https://[^\s<>"\x00-\x1f]+~u',$question,$matches);
    $urls=[];$domains=[];
    foreach($matches[0] as $raw){
        $raw=rtrim($raw,').,;');$parts=parse_url($raw);
        $host=strtolower((string)($parts['host']??''));$path=(string)($parts['path']??'/');
        if(!$parts||($parts['scheme']??'')!=='https'||isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment'])
          ||isset($parts['port'])&&$parts['port']!==443||strlen($raw)>1400
          ||!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D',$host)
          ||str_ends_with($host,'.local')||str_ends_with($host,'.internal')||str_ends_with($host,'.localhost')
          ||preg_match('~(?:[\\\\\x00-\x1f]|%2f|%5c|%00|%0[ad])~i',$raw)
          ||preg_match('~(?:^|/)(?:login|logout|signin|signout|admin|account|checkout|purchase|payment|delete|unsubscribe)(?:[/.]|$)~i',rawurldecode($path)))
            throw new InvalidArgumentException('Use public HTTPS read URLs without credentials, query strings, or account/action pages.');
        $raw='https://'.$host.($path===''?'/':$path);
        $domain=vp3_browser_delegation_domain_v2190($host);
        if(isset($domains[$domain]))throw new InvalidArgumentException('Use one source URL per website in this reviewed plan.');
        $domains[$domain]=true;$urls[]=['url'=>$raw,'domain'=>$domain];
    }
    if(!$urls||count($urls)>5)throw new InvalidArgumentException('Include one to five public HTTPS source URLs in your request.');
    return $urls;
}

function chat_research_row_v1(PDO $pdo,int $uid,string $id,bool $lock=false): array
{
    $stmt=$pdo->prepare('SELECT * FROM chat_browser_research_requests_v1 WHERE id=? AND owner_user_id=?'.($lock?' FOR UPDATE':''));
    $stmt->execute([chat_research_uuid_v1($id),$uid]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new RuntimeException('Research request was not found.',404);
    return $row;
}

function chat_research_authority_v1(PDO $pdo,array $user,array $row,bool $history=false): array
{
    knowledge_r7_research_guard($pdo,$user,$row);
    if($history){
        $runtime=vp3_browser_runtime_row_v2200($pdo,(int)$user['id'],(string)$row['agent_namespace'],(string)$row['runtime_id']);
        if(!$runtime||in_array($runtime['status'],['cancelled'],true)||($runtime['delegation_status']??'')==='cancelled'
           ||!in_array('browser_research',vp3_browser_delegation_json_array_v2190($runtime['allowed_actions_json']??''),true))throw new RuntimeException('Chrome research authority was revoked.',403);
        $multi=vp3_browser_multisite_row_v2220($pdo,$runtime);
        if(!$multi)throw new RuntimeException('Chrome research scope is unavailable.',403);
        $runtime['_multisite']=$multi;
    }else $runtime=vp3_browser_research_runtime_v2230($pdo,$user,(string)$row['agent_namespace'],(string)$row['runtime_id']);
    $allowed=vp3_browser_research_allowed_domains_v2230($pdo,(array)$runtime['_multisite']);
    foreach(json_decode((string)$row['urls_json'],true,16,JSON_THROW_ON_ERROR) as $url)
        if(!in_array($url['domain'],$allowed,true))throw new RuntimeException('A reviewed website grant changed. Prepare a new research plan.',409);
    return $runtime;
}

function chat_research_view_v1(PDO $pdo,array $user,array $row,bool $withAnswer=true): array
{
    // A lost current grant must not expose retained source findings.
    if(!in_array($row['status'],['cancelled'],true))chat_research_authority_v1($pdo,$user,$row,($row['status']??'')==='complete');
    $urls=json_decode((string)$row['urls_json'],true,16,JSON_THROW_ON_ERROR);
    $mission=!empty($row['mission_id'])&&$row['status']!=='cancelled'?vp3_browser_research_public_v2230($pdo,$user,(string)$row['mission_id']):null;
    if($mission&&$withAnswer){$mission['answer']=chat_research_answer_r3($mission,$urls);$mission['answer']['partial']=$row['status']!=='complete';}
    return ['id'=>$row['id'],'request_id'=>$row['request_id'],'context_key'=>$row['context_key'],
        'question'=>$row['question'],'runtime_id'=>$row['runtime_id'],'agent_id'=>(int)$row['agent_id'],'review_hash'=>$row['review_hash'],
        'status'=>$row['status']==='running'&&strtotime((string)$row['lease_until'])<time()?'attention':$row['status'],'expires_at'=>$row['expires_at'],'next_source'=>(int)$row['next_source'],
        'last_error'=>$row['last_error'],'sources'=>$urls,'budgets'=>['sources'=>count($urls),'pages'=>count($urls),'claims'=>60,'duration_minutes'=>60],
        'mission'=>$mission];
}

function chat_research_prepare_v1(PDO $pdo,array $user,string $namespace,?array $agent,array $input,?array $plannedSources=null): array
{
    $rid=chat_research_uuid_v1($input['request_id']??null);$question=trim((string)($input['question']??''));
    $urls=$plannedSources??chat_research_urls_v1($question);$runtimeId=chat_research_uuid_v1($input['runtime_id']??null);
    $context=(string)($input['context_key']??'');
    if(!preg_match('/^(?:cloud:[0-9]{1,10}|home:[a-zA-Z0-9:._-]{1,180})$/D',$context))throw new InvalidArgumentException('Research conversation context is invalid.');
    $conversation=(int)($input['conversation_id']??0);
    if($conversation>0){
        if(!vp3_agent_chat_conversation_v380($pdo,$conversation,(int)$user['id'],$agent))throw new RuntimeException('The Chat conversation is not available for this lead.',403);
    }
    $scope=['question'=>$question,'urls'=>$urls,'runtime_id'=>$runtimeId,'context_key'=>$context,
        'namespace'=>$namespace,'conversation_id'=>$conversation,'agent_id'=>(int)($agent['id']??0)];
    $hash=hash('sha256',json_encode($scope,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    $q=$pdo->prepare('SELECT * FROM chat_browser_research_requests_v1 WHERE owner_user_id=? AND request_id=?');$q->execute([(int)$user['id'],$rid]);
    if($prior=$q->fetch(PDO::FETCH_ASSOC)){
        if(!hash_equals($prior['review_hash'],$hash))throw new RuntimeException('This request belongs to a different research scope.',409);
        return chat_research_view_v1($pdo,$user,$prior);
    }
    $row=['agent_namespace'=>$namespace,'runtime_id'=>$runtimeId,'urls_json'=>json_encode($urls,JSON_THROW_ON_ERROR)];
    chat_research_authority_v1($pdo,$user,$row);
    $id=vp3_extension_uuid_v2000();$expires=gmdate('Y-m-d H:i:s',time()+900);
    try{
        $pdo->prepare('INSERT INTO chat_browser_research_requests_v1 (id,owner_user_id,request_id,context_key,agent_namespace,agent_id,runtime_id,conversation_id,question,urls_json,review_hash,expires_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$id,(int)$user['id'],$rid,$context,$namespace,(int)($agent['id']??0)?:null,$runtimeId,$conversation?:null,$question,$row['urls_json'],$hash,$expires]);
    }catch(PDOException $e){
        $q->execute([(int)$user['id'],$rid]);$prior=$q->fetch(PDO::FETCH_ASSOC);
        if(!$prior||!hash_equals($prior['review_hash'],$hash))throw $e;
        return chat_research_view_v1($pdo,$user,$prior);
    }
    return chat_research_view_v1($pdo,$user,chat_research_row_v1($pdo,(int)$user['id'],$id));
}

function chat_research_start_v1(PDO $pdo,array $user,array $input): array
{
    if(($input['confirmed']??null)!==true)throw new InvalidArgumentException('Review and confirm the exact research plan.');
    $pdo->beginTransaction();
    try{
        $row=chat_research_row_v1($pdo,(int)$user['id'],(string)($input['id']??''),true);
        if(!is_string($input['expected_hash']??null)||!hash_equals($row['review_hash'],$input['expected_hash']))throw new RuntimeException('Research scope changed. Review it again.',409);
        // Serialize different requests for the same canonical runtime before checking active missions.
        if(!vp3_browser_runtime_row_v2200($pdo,(int)$user['id'],$row['agent_namespace'],$row['runtime_id'],true))throw new RuntimeException('Chrome research runtime is unavailable.',403);
        chat_research_authority_v1($pdo,$user,$row);
        if($row['status']==='prepared'){
            if(strtotime($row['expires_at'])<time())throw new RuntimeException('Research review expired. Prepare a fresh plan.',409);
            $urls=json_decode($row['urls_json'],true,16,JSON_THROW_ON_ERROR);
            $mission=vp3_browser_research_start_v2230($pdo,$user,$row['agent_namespace'],['id'=>(int)$row['agent_id']],$row['runtime_id'],[
                'question'=>$row['question'],'conversation_id'=>(int)$row['conversation_id'],
                'max_sources'=>count($urls),'max_pages'=>count($urls),'max_claims'=>60,'duration_minutes'=>60,
                'source_plan'=>array_map(static fn(array $s):array=>['domain'=>$s['domain'],'reason'=>'Reviewed source for this Chat research request.'],$urls)]);
            $pdo->prepare("UPDATE chat_browser_research_requests_v1 SET status='waiting_chrome',mission_id=? WHERE id=? AND owner_user_id=?")
                ->execute([$mission['mission_id'],$row['id'],(int)$user['id']]);
        }elseif(!in_array($row['status'],['waiting_chrome','running','attention','complete'],true))throw new RuntimeException('This research plan cannot be started.',409);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return chat_research_view_v1($pdo,$user,chat_research_row_v1($pdo,(int)$user['id'],$row['id']));
}

function chat_research_claim_v1(PDO $pdo,array $user,string $device,array $input): array
{
    if(!preg_match('/^[a-zA-Z0-9:._-]{1,190}$/D',$device))throw new InvalidArgumentException('Chrome connection identity is invalid.');
    $pdo->beginTransaction();
    try{
        $row=chat_research_row_v1($pdo,(int)$user['id'],(string)($input['id']??''),true);
        chat_research_authority_v1($pdo,$user,$row);
        if(!in_array($row['status'],['waiting_chrome','running','attention'],true))throw new RuntimeException('Research is not waiting for Chrome.',409);
        if($row['status']==='attention'&&($input['resume_confirmed']??null)!==true)throw new RuntimeException('Review interrupted research before resuming.',409);
        if($row['status']==='running'&&strtotime((string)$row['lease_until'])>time())throw new RuntimeException('A Chrome reader already holds this research run.',409);
        if($row['status']==='running'&&($input['resume_confirmed']??null)!==true)throw new RuntimeException('The Chrome reader was interrupted. Review before resuming.',409);
        $token=bin2hex(random_bytes(32));
        $pdo->prepare("UPDATE chat_browser_research_requests_v1 SET status='running',device_key=?,lease_token=?,lease_until=?,last_error='' WHERE id=?")
            ->execute([$device,$token,gmdate('Y-m-d H:i:s',time()+180),$row['id']]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return ['request'=>chat_research_view_v1($pdo,$user,chat_research_row_v1($pdo,(int)$user['id'],$row['id']),false),'lease_token'=>$token];
}

function chat_research_step_v1(PDO $pdo,array $user,string $device,array $input): array
{
    $pdo->beginTransaction();
    try{
        $row=chat_research_row_v1($pdo,(int)$user['id'],(string)($input['id']??''),true);
        chat_research_authority_v1($pdo,$user,$row);
        if($row['status']!=='running'||$row['device_key']!==$device||!is_string($input['lease_token']??null)
           ||!hash_equals((string)$row['lease_token'],$input['lease_token'])||strtotime((string)$row['lease_until'])<time())
            throw new RuntimeException('This Chrome reader no longer owns the run.',409);
        $urls=json_decode($row['urls_json'],true,16,JSON_THROW_ON_ERROR);$next=(int)$row['next_source'];
        $mission=vp3_browser_research_public_v2230($pdo,$user,$row['mission_id']);
        if($mission['status']!=='active')throw new RuntimeException('The research mission stopped.',409);
        // Restart/lost-ack recovery uses the existing canonical source receipt, never rereads an analyzed URL.
        $fingerprints=array_column($mission['pages']??[],'fingerprint');
        while($next<count($urls)&&in_array(hash('sha256',$urls[$next]['url']),$fingerprints,true))$next++;
        if(($input['failed']??false)===true){
            $pdo->prepare("UPDATE chat_browser_research_requests_v1 SET status='attention',lease_token=NULL,lease_until=NULL,last_error='Chrome could not finish the reviewed source. Review before retrying.' WHERE id=?")->execute([$row['id']]);
        }else{
            $state=$next>=count($urls)?'complete':'running';
            $pdo->prepare('UPDATE chat_browser_research_requests_v1 SET status=?,next_source=?,lease_until=? WHERE id=?')
                ->execute([$state,$next,gmdate('Y-m-d H:i:s',time()+180),$row['id']]);
        }
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    $fresh=chat_research_view_v1($pdo,$user,chat_research_row_v1($pdo,(int)$user['id'],$row['id']),false);
    return ['request'=>$fresh,'next'=>$fresh['status']==='running'?($urls[$next]??null):null];
}

function chat_research_analysis_guard_v1(PDO $pdo,array $user,string $mission,string $device,string $token,string $fingerprint,bool $lock=false): bool
{
    $q=$pdo->prepare('SELECT * FROM chat_browser_research_requests_v1 WHERE mission_id=? AND owner_user_id=?'.($lock?' FOR UPDATE':''));
    $q->execute([$mission,(int)$user['id']]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row)return false;
    chat_research_authority_v1($pdo,$user,$row);
    if($row['status']!=='running'||$row['device_key']!==$device||!$token||!hash_equals((string)$row['lease_token'],$token)||strtotime((string)$row['lease_until'])<time())throw new RuntimeException('Chrome research ownership changed before evidence was saved.',409);
    $urls=json_decode($row['urls_json'],true,16,JSON_THROW_ON_ERROR);$source=$urls[(int)$row['next_source']]??null;
    if(!$source||!hash_equals(hash('sha256',$source['url']),$fingerprint))throw new RuntimeException('This source is outside the exact reviewed read step.',409);
    return true;
}
