<?php
declare(strict_types=1);

require_once __DIR__.'/phone-tracking-v1.php';

/** Only content-free markers go into ordinary model history or shared Chat indexes. */
function vp3_phone_chat_schema_v1(PDO $pdo): void
{
    if ($pdo->inTransaction()) throw new LogicException('Install Phone Tracking outside a transaction.');
    $options = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
        ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_phone_chat_turns_v1 (
        assistant_message_id BIGINT UNSIGNED NOT NULL PRIMARY KEY, user_message_id BIGINT UNSIGNED NULL UNIQUE,
        owner_user_id INT UNSIGNED NOT NULL, agent_id INT UNSIGNED NOT NULL, conversation_id BIGINT UNSIGNED NOT NULL,
        request_hash CHAR(64) NULL UNIQUE, query_hash CHAR(64) NULL, prompt VARCHAR(300) NOT NULL,
        answer TEXT NOT NULL, map_link VARCHAR(12) NOT NULL,
        FOREIGN KEY (assistant_message_id) REFERENCES chat_messages(id) ON DELETE CASCADE,
        FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE
    )".$options);
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_phone_chat_grants_v1 (
        owner_user_id INT UNSIGNED NOT NULL, device_id CHAR(32) NOT NULL, generation BIGINT NOT NULL,
        agent_id INT UNSIGNED NOT NULL, conversation_id BIGINT UNSIGNED NOT NULL,
        pending SMALLINT NOT NULL, PRIMARY KEY (owner_user_id, device_id, generation),
        FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE
    )".$options);
}

function vp3_phone_chat_schema_ready_v1(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT t.assistant_message_id,t.user_message_id,t.owner_user_id,t.agent_id,t.conversation_id,
            t.request_hash,t.query_hash,t.prompt,t.answer,t.map_link,g.pending,g.device_id,g.generation
            FROM vp3_phone_chat_turns_v1 t JOIN vp3_phone_chat_grants_v1 g ON g.owner_user_id=t.owner_user_id WHERE 1=0');
        return true;
    } catch (PDOException) {
        return false;
    }
}

/** These are complete foreground commands. Embedded instructions in prose do not execute. */
function vp3_phone_chat_plan_v1(mixed $text): ?array
{
    if (!is_string($text) || mb_strlen($text) > 300) return null;
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '', " \t\r\n.!?");
    $text = preg_replace('/^(?:(?:can|could|would) you |please )+/iu', '', $text);
    $text = preg_replace('/,? please$/iu', '', $text);
    if (preg_match('/^(?:connect|pair|link|add) (?:a |an |my |this |another |new |guest |the )*(?:phone|iphone|android|device)(?: to (?:my |this |the )?(?:account|agent|chat))?$/iu', $text)
        || preg_match('/^(?:show|open) (?:me )?(?:the |a )?(?:phone (?:pairing |connection )?QR(?: code)?|QR code (?:for|to connect) (?:my |a )?phone)$/iu', $text)) {
        return ['action'=>'connect','target'=>''];
    }
    if (preg_match('/^(?:(?:list|show)(?: me)? (?:my |the |all |connected |pending )*phones|which phones are connected)$/iu', $text)) {
        return ['action'=>'list','target'=>''];
    }
    if (preg_match('/^(?:(?:check|show)(?: me)? )?(?:(?:my |the )?(?:phone|gps|location sharing|phone tracking) status|tracking status)(?:(?: for| on| of)? (.+))?$/iu', $text, $m)
        || preg_match('/^(?:where is|where’s|where\x27s) ((?:my |the )?(?:phone|iphone|android)(?: .+)?)$/iu', $text, $m)
        || preg_match('/^(?:is (.+?) (?:sharing (?:its |my )?location|tracking|connected))$/iu', $text, $m)) {
        return ['action'=>'status','target'=>trim($m[1] ?? '')];
    }
    if (preg_match('/^(start|stop) sharing (.+?)(?:’s|\x27s) (?:gps|location)$/iu',$text,$m)) {
        return ['action'=>mb_strtolower($m[1])==='start'?'request_start':'stop','target'=>$m[2]];
    }
    if (preg_match('/^(start|stop|pause|end|enable|disable|turn on|turn off)(?: (?:my|phone))? (?:gps(?: (?:location |phone )?(?:sharing|tracking))?|location(?: sharing| tracking)?|sharing(?: (?:my |the |phone )?(?:gps|location))?|tracking)(?: (?:on |for |from |with )?(.+))?$/iu', $text, $m)) {
        $target=trim($m[2] ?? '');
        $implicit=$target!=='' && !preg_match('/\b(?:phone|iphone|android|device|gps|location)\b/iu',$text);
        return ['action'=>in_array(mb_strtolower($m[1]),['start','enable','turn on'],true)?'request_start':'stop','target'=>$target,'implicit'=>$implicit];
    }
    if (preg_match('/^(approve|allow|deny|reject|disconnect|unpair|revoke)(?: the| my| this| that| a)? (.+)$/iu', $text, $m)) {
        $target = trim($m[2]);
        $explicit = (bool)preg_match('/\b(?:phone|device|iphone|android|pairing|connection request)\b/iu',$target);
        if (!$explicit && !preg_match('/^[\p{L}\p{N}][\p{L}\p{N} _’\x27-]{0,47}$/u',$target)) return null;
        $action = match(mb_strtolower($m[1])) {'approve','allow'=>'approve','deny','reject'=>'deny',default=>'revoke'};
        return ['action'=>$action,'target'=>$target,'implicit'=>!$explicit];
    }
    return null;
}

function vp3_phone_chat_label_v1(array $device): string
{
    $name = trim(preg_replace('/[\p{C}<>]/u',' ',(string)$device['name']) ?? '');
    // A user-controlled device label must never turn the chat summary into a coordinate channel.
    if (!$name || preg_match('/[-+]?\d{1,3}\.\d+\s*[,; ]\s*[-+]?\d{1,3}\.\d+/u',$name)) return 'Phone';
    return mb_substr($name,0,48);
}

function vp3_phone_chat_matches_v1(array $devices, string $target): array
{
    $normalize = static fn(string $s): string => mb_strtolower(trim(str_replace('’', "'", $s), " \"'“”"));
    $raw = $normalize($target);
    if ($raw === '' || preg_match('/^(?:(?:the|a|my|this|that|pending|guest|new) )*(?:phone|device|pairing request|connection request)$/u',$raw)) return $devices;
    $keys = [$raw,preg_replace('/^(?:the|my) /u','',$raw)];
    $clean = preg_replace('/^(?:(?:the|my|this|that|pending|guest|new) )*(?:(?:phone|device|pairing request|connection request)(?: (?:from|for|named|called))? )/u','',$raw);
    $keys[] = $normalize($clean);
    $keys[] = preg_replace('/(?:\x27s)? (?:phone|device)$/u','',$clean);
    return array_values(array_filter($devices, static fn(array $d): bool =>
        in_array($normalize((string)$d['name']),$keys,true) || $raw === '#'.$d['id']));
}

function vp3_phone_chat_status_v1(array $device): string
{
    if ($device['status']==='pending') return 'awaiting connection approval';
    return match ($device['location_state']) {
        'live'=>'sharing a current GPS fix',
        'stale'=>'last fix is stale',
        'waiting'=>'waiting for the first GPS fix',
        'requested'=>'waiting for sharing to start on the phone',
        'denied'=>'location permission denied on the phone',
        default=>empty($device['connected'])?'offline, location sharing off':'connected, location sharing off',
    };
}

function vp3_phone_chat_page_v1(array $user): bool
{
    $binding = $_SERVER['HTTP_X_VP3_WORKING_ATTENTION'] ?? '';
    return is_string($binding) && $binding!=='' && hash_equals(vp3_catch_up_page_binding_v1($user),$binding);
}

function vp3_phone_chat_authorize_v1(PDO $pdo,array $user,array $principal,array $input,int $cid,?callable $authority=null): void
{
    if ($authority) $authority();
    $aid = (int)($principal['agent_id'] ?? 0);
    if ((int)(current_user()['id']??0)!==(int)$user['id'] || !vp3_phone_chat_page_v1($user) || !empty($input['ephemeral_protocol'])
        || !knowledge_retrieval_v162_owner_session($user,$principal)
        || (isset($input['agent_context']['user_agent_id']) && (int)$input['agent_context']['user_agent_id'] !== $aid)) {
        throw new RuntimeException('Open your private Agent Chat to manage phones.',403);
    }
    if (vp3_participant_scope_v1()!==null || vp3_participant_chat_actor_v1($pdo,$user,$input)!==null) {
        throw new RuntimeException('Open your private Agent Chat to manage phones.',403);
    }
    $live = vp3_capability_live_user_v1($pdo,$user,$principal,['conversation_id'=>$cid],$authority);
    if (!has_permission('account.access',$live)) throw new RuntimeException('Phone tracking access changed.',403);
}

function vp3_phone_chat_actions_v1(string $mapLink,int $aid,int $cid): array
{
    if ($mapLink==='') return [];
    $path='/phone-tracking.php?'.http_build_query(array_filter(['agent'=>$aid,'conversation_id'=>$cid,
        'connect'=>$mapLink==='connect'?1:null],static fn($v)=>$v!==null));
    return [['type'=>'open_url','label'=>$mapLink==='connect'?'Show phone QR code':'Open tracking map','url'=>url($path)]];
}

function vp3_phone_chat_result_v1(array $row,string $mode): array
{
    $aid=(int)$row['agent_id']; $cid=(int)$row['conversation_id'];
    return ['ok'=>true,'conversation_id'=>$cid,'agent_id'=>$aid,'user_message_id'=>(int)$row['user_message_id'],
        'assistant_message_id'=>(int)$row['assistant_message_id'],'answer'=>$row['answer'],
        'phone_tracking_context'=>['owner_user_id'=>(int)$row['owner_user_id'],'agent_id'=>$aid,'conversation_id'=>$cid],
        'sources'=>[],'actions'=>vp3_phone_chat_actions_v1($row['map_link'],$aid,$cid),'media'=>[],
        'input_mode'=>$mode,'execution'=>['mode'=>'cloud','status'=>'completed']];
}

function vp3_phone_chat_store_v1(PDO $pdo,int $uid,int $aid,int $cid,string $prompt,string $answer,string $link,
    ?string $requestHash=null,?string $queryHash=null): array
{
    $message=$pdo->prepare('INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json) VALUES(?,?,?,?,?)');
    $context='{"source":"private_phone_tracking"}';
    $userMessageId=null;
    if ($prompt!=='') {
        $message->execute([$cid,$uid,'user','Private phone tracking request.',$context]);
        $userMessageId=(int)$pdo->lastInsertId();
    }
    $message->execute([$cid,$uid,'assistant','Private phone tracking update.',$context]);
    $assistantId=(int)$pdo->lastInsertId();
    vp3_phone_sql_v1($pdo,'INSERT INTO vp3_phone_chat_turns_v1 (assistant_message_id,user_message_id,owner_user_id,
        agent_id,conversation_id,request_hash,query_hash,prompt,answer,map_link) VALUES(?,?,?,?,?,?,?,?,?,?)',
        [$assistantId,$userMessageId,$uid,$aid,$cid,$requestHash,$queryHash,$prompt,$answer,$link]);
    vp3_phone_sql_v1($pdo,'UPDATE chat_conversations SET updated_at=CURRENT_TIMESTAMP WHERE id=? AND user_id=?',[$cid,$uid]);
    return ['owner_user_id'=>$uid,'agent_id'=>$aid,'conversation_id'=>$cid,'user_message_id'=>$userMessageId,'assistant_message_id'=>$assistantId,
        'answer'=>$answer,'map_link'=>$link];
}

/** Only one originating private Chat can claim the first fix for a given grant. */
function vp3_phone_chat_watch_v1(PDO $pdo,int $uid,int $aid,int $cid,array $device): void
{
    $params=[$uid,$device['id'],$device['generation']];
    $old=vp3_phone_sql_v1($pdo,'SELECT 1 FROM vp3_phone_chat_grants_v1 WHERE owner_user_id=? AND device_id=? AND generation=?',$params);
    if (!$old->fetchColumn()) {
        vp3_phone_sql_v1($pdo,'INSERT INTO vp3_phone_chat_grants_v1 (owner_user_id,device_id,generation,agent_id,conversation_id,pending)
            VALUES(?,?,?,?,?,1)',[...$params,$aid,$cid]);
    }
}

function vp3_phone_chat_map_start_v1(PDO $pdo,array $user,array $input): array
{
    require_once __DIR__.'/agent-activity-scope-v1.php';
    require_once __DIR__.'/participant-chat-v1.php';
    $origin=$input['chat_origin']??null;
    if (!is_array($origin) || !is_int($origin['conversation_id']??null) || $origin['conversation_id']<1
        || !is_int($origin['agent_id']??null) || $origin['agent_id']<0) {
        throw new RuntimeException('Choose a private Chat for phone tracking.',422);
    }
    $aid=$origin['agent_id']; $cid=$origin['conversation_id']; $uid=(int)$user['id'];
    $agent=vp3_agent_chat_resolve_agent_v380($pdo,$user,$aid);
    $principal=vp3_agent_chat_principal_v380($user,$agent);
    vp3_phone_chat_authorize_v1($pdo,$user,$principal,$input,$cid);
    $pdo->beginTransaction();
    try {
        vp3_phone_account_v1($pdo,$uid,true);
        vp3_phone_chat_authorize_v1($pdo,$user,$principal,$input,$cid);
        $result=vp3_phone_owner_v1($pdo,$uid,'request_start',$input);
        $device=$result['device'];
        if (!$result['duplicate'] && in_array($device['location_state'],['requested','waiting'],true)) {
            vp3_phone_chat_watch_v1($pdo,$uid,$aid,$cid,$device);
        }
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function vp3_phone_chat_execute_v1(PDO $pdo,int $uid,int $aid,int $cid,array $plan,string $key): array
{
    $action=$plan['action'];
    if ($action==='connect') return ['Open the phone QR code and scan it with the phone you want to connect. Then ask me to approve its name.','connect'];
    $devices=vp3_phone_owner_v1($pdo,$uid,'state',[])['devices'];
    if ($action==='list') {
        $lines=array_map(static fn($d)=>vp3_phone_chat_label_v1($d).' — '.vp3_phone_chat_status_v1($d).'.',$devices);
        return [$lines?implode("\n",$lines):'There are no connected or pending phones.','map'];
    }
    $eligible=array_values(array_filter($devices,static fn($d)=>match($action) {
        'approve','deny'=>$d['status']==='pending',
        'status'=>true,
        default=>$d['status']==='approved'
    }));
    $matches=vp3_phone_chat_matches_v1($eligible,$plan['target']);
    if (count($matches)!==1) {
        if (!$eligible) return [in_array($action,['approve','deny'],true)?'There are no pending phone requests.':'There are no phones available for that action.','map'];
        if (!$matches) return ['I could not find that phone. Ask me to list your phones or open the tracking map.','map'];
        $names=array_map('vp3_phone_chat_label_v1',$matches);
        return [count(array_unique($names))===count($names)?'Which phone? Use its name: '.implode(', ',$names).'.'
            :'Several phones have the same name. Choose the exact device on the tracking map.','map'];
    }
    $device=$matches[0]; $name=vp3_phone_chat_label_v1($device);
    if ($action==='status') return [$name.' is '.vp3_phone_chat_status_v1($device).'.','map'];
    $result=vp3_phone_owner_v1($pdo,$uid,$action,['device_id'=>$device['id'],'generation'=>(int)$device['generation'],'request_id'=>$key]);
    $device=$result['device'];
    $answer=match ($action) {
        'approve'=>$name.' is connected. GPS sharing is off.',
        'deny'=>'Connection request denied for '.$name.'.',
        'stop'=>'GPS sharing stopped for '.$name.'.',
        'revoke'=>$name.' was disconnected.',
        'request_start'=>match ($device['location_state']) {
            'live','stale'=>$name.' is already in a sharing session. Open the tracking map to see its current status.',
            'waiting'=>'Waiting for the first GPS fix from '.$name.'.',
            default=>'GPS sharing requested for '.$name.'. Start sharing on the phone and allow location access.',
        },
    };
    if ($action==='request_start' && in_array($device['location_state'],['requested','waiting'],true)) {
        vp3_phone_chat_watch_v1($pdo,$uid,$aid,$cid,$device);
    }
    return [$answer,'map'];
}

/** Short commands like "approve Pixel" are only phone intents for an exact device in this owner's account. */
function vp3_phone_chat_route_v1(PDO $pdo,array $user,array $principal,array $input): ?array
{
    $plan=vp3_phone_chat_plan_v1($input['message']??null);
    if ($plan===null || empty($plan['implicit'])) return $plan;
    if (!empty($input['initiative_reply']) || !vp3_phone_chat_page_v1($user) || !vp3_phone_schema_ready_v1($pdo)) return null;
    try {
        vp3_phone_chat_authorize_v1($pdo,$user,$principal,$input,(int)($input['conversation_id']??0));
        // A successful denial or disconnect removes the device from the roster. Its replay is still a phone request.
        $key=$input['idempotency_key']??$input['reminder_request_id']??null;
        if (is_string($key) && vp3_phone_chat_schema_ready_v1($pdo)) {
            $uid=(int)$user['id']; $aid=(int)$principal['agent_id'];
            $saved=vp3_phone_sql_v1($pdo,'SELECT 1 FROM vp3_phone_chat_turns_v1 WHERE request_hash=? AND owner_user_id=? AND agent_id=?',
                [hash('sha256',json_encode([$uid,$aid,$key],JSON_THROW_ON_ERROR)),$uid,$aid]);
            if ($saved->fetchColumn()) return $plan;
        }
        $devices=vp3_phone_owner_v1($pdo,(int)$user['id'],'state',[])['devices'];
        return vp3_phone_chat_matches_v1($devices,$plan['target'])?$plan:null;
    } catch (RuntimeException $e) {
        if (in_array($e->getCode(),[403,404],true)) return null;
        throw $e;
    }
}

function vp3_phone_chat_send_v1(PDO $pdo,array $user,?array $agent,array $principal,array $input,array $plan,?callable $authority): array
{
    $uid=(int)$user['id']; $aid=vp3_agent_chat_agent_id_v380($agent); $cid=(int)($input['conversation_id']??0);
    if ($aid!==(int)($principal['agent_id']??0) || isset($input['initiative_reply']) || !empty($input['image_reference_asset_id'])) {
        throw new RuntimeException('Send a new request in your private Agent Chat to manage phones.',403);
    }
    vp3_phone_chat_authorize_v1($pdo,$user,$principal,$input,$cid,$authority);
    if (!vp3_phone_chat_schema_ready_v1($pdo) || !vp3_phone_schema_ready_v1($pdo)) {
        throw new RuntimeException('Phone tracking is unavailable until the database upgrade is installed.',503);
    }
    $query=trim($input['message']);
    $queryHash=hash('sha256',$query);
    $request=$input['idempotency_key']??$input['reminder_request_id']??null;
    if (!is_string($request) || !preg_match('/^[a-zA-Z0-9_.:-]{12,160}$/D',$request)) {
        throw new RuntimeException('The phone request expired. Send a new Chat message.',422);
    }
    $requestHash=hash('sha256',json_encode([$uid,$aid,$request],JSON_THROW_ON_ERROR));
    $mode=($input['input_mode']??'text')==='voice'?'voice':'text';
    $pdo->beginTransaction();
    try {
        vp3_phone_account_v1($pdo,$uid,true);
        vp3_phone_chat_authorize_v1($pdo,$user,$principal,$input,$cid,$authority);
        $saved=vp3_phone_sql_v1($pdo,'SELECT * FROM vp3_phone_chat_turns_v1 WHERE owner_user_id=? AND agent_id=?
            AND request_hash=?',[$uid,$aid,$requestHash])->fetch(PDO::FETCH_ASSOC);
        if ($saved) {
            if ($queryHash!==$saved['query_hash'] || ($cid>0 && $cid!==(int)$saved['conversation_id'])) {
                throw new RuntimeException('This phone request changed. Send a new message.',409);
            }
            vp3_phone_chat_authorize_v1($pdo,$user,$principal,$input,(int)$saved['conversation_id'],$authority);
            $pdo->commit();
            return vp3_phone_chat_result_v1($saved,$mode);
        }
        if (!$cid) {
            $conversation=vp3_agent_chat_create_conversation_v380($pdo,$user,$agent,'Phone tracking',artist_workspace_v181_scope_id($user));
            $cid=(int)$conversation['id'];
        }
        vp3_phone_chat_authorize_v1($pdo,$user,$principal,$input,$cid,$authority);
        [$answer,$mapLink]=vp3_phone_chat_execute_v1($pdo,$uid,$aid,$cid,$plan,$requestHash);
        // A private phone label or a quoted coordinate must never become shared Chat history.
        $prompt=preg_match('/[-+]?\d{1,3}\.\d+\s*[,; ]\s*[-+]?\d{1,3}\.\d+/u',$query)?'Phone tracking request.':$query;
        $saved=vp3_phone_chat_store_v1($pdo,$uid,$aid,$cid,$prompt,$answer,$mapLink,$requestHash,$queryHash);
        vp3_phone_chat_authorize_v1($pdo,$user,$principal,$input,$cid,$authority);
        $pdo->commit();
        return vp3_phone_chat_result_v1($saved,$mode);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Called by foreground private activity polling before it reads the latest Chat message ID. */
function vp3_phone_chat_updates_v1(PDO $pdo,array $user,array $principal,array $input): array
{
    $cid=(int)($input['conversation_id']??0); $aid=(int)($principal['agent_id']??0); $uid=(int)$user['id'];
    if (($input['phone_updates']??false)!==true || $cid<1 || !vp3_phone_chat_page_v1($user)
        || !isset($input['agent_context']['user_agent_id']) || (int)$input['agent_context']['user_agent_id']!==$aid
        || !vp3_phone_chat_schema_ready_v1($pdo)) return [];
    $sql='SELECT device_id,generation FROM vp3_phone_chat_grants_v1 WHERE owner_user_id=? AND agent_id=? AND conversation_id=? AND pending=1';
    if (!vp3_phone_sql_v1($pdo,$sql,[$uid,$aid,$cid])->fetchColumn()) return [];
    vp3_phone_chat_authorize_v1($pdo,$user,$principal,$input,$cid);
    $pdo->beginTransaction();
    try {
        vp3_phone_account_v1($pdo,$uid,true);
        vp3_phone_chat_authorize_v1($pdo,$user,$principal,$input,$cid);
        $grants=vp3_phone_sql_v1($pdo,$sql,[$uid,$aid,$cid])->fetchAll(PDO::FETCH_ASSOC);
        $devices=array_column(vp3_phone_owner_v1($pdo,$uid,'state',[])['devices'],null,'id');
        $updates=[];
        foreach ($grants as $grant) {
            $device=$devices[$grant['device_id']]??null;
            $current=$device && $device['status']==='approved' && $device['allow_location']
                && (int)$device['generation']===(int)$grant['generation'];
            if ($current && in_array($device['location_state'],['requested','waiting','stale'],true)) continue;
            vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_chat_grants_v1 SET pending=0 WHERE owner_user_id=? AND device_id=? AND generation=?',
                [$uid,$grant['device_id'],$grant['generation']]);
            if ($current && $device['location_state']==='live' && !empty($device['first_fix_at']) && !empty($device['fix_at'])) {
                $answer=vp3_phone_chat_label_v1($device).' is now sharing GPS. A current location is available on the tracking map.';
                $row=vp3_phone_chat_store_v1($pdo,$uid,$aid,$cid,'',$answer,'map');
                $updates[]=['message_id'=>$row['assistant_message_id'],'owner_user_id'=>$uid,'agent_id'=>$aid,'conversation_id'=>$cid,'speech'=>$answer];
            }
        }
        vp3_phone_chat_authorize_v1($pdo,$user,$principal,$input,$cid);
        $pdo->commit();
        return $updates;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Hydrate only exact, explicitly requested owner history. Other readers see content-free markers. */
function vp3_phone_chat_history_v1(PDO $pdo,array $user,int $aid,int $cid,array $rows,array $input): array
{
    $markers=[];
    foreach ($rows as $i=>$row) {
        if (($row['context_json']??null)==='{"source":"private_phone_tracking"}') $markers[(int)$row['id']]=$i;
    }
    if (!$markers || !$cid || !vp3_phone_chat_page_v1($user)) return $rows;
    try {
        $agent=vp3_agent_chat_resolve_agent_v380($pdo,$user,$aid);
        vp3_phone_chat_authorize_v1($pdo,$user,vp3_agent_chat_principal_v380($user,$agent),$input,$cid);
    } catch (RuntimeException) {
        return $rows;
    }
    $ids=array_keys($markers); $marks=implode(',',array_fill(0,count($ids),'?'));
    $records=vp3_phone_sql_v1($pdo,"SELECT * FROM vp3_phone_chat_turns_v1 WHERE owner_user_id=? AND agent_id=? AND conversation_id=?
        AND (assistant_message_id IN ($marks) OR user_message_id IN ($marks))",[(int)$user['id'],$aid,$cid,...$ids,...$ids]);
    foreach ($records->fetchAll(PDO::FETCH_ASSOC) as $record) {
        $assistant=(int)$record['assistant_message_id']; $author=(int)$record['user_message_id'];
        if (isset($markers[$author])) $rows[$markers[$author]]['message']=$record['prompt'];
        if (isset($markers[$assistant])) {
            $i=$markers[$assistant];
            $rows[$i]['message']=$record['answer'];
            $rows[$i]['context_json']=json_encode(['source'=>'private_phone_tracking',
                'actions'=>vp3_phone_chat_actions_v1($record['map_link'],$aid,$cid)],JSON_THROW_ON_ERROR);
        }
    }
    return $rows;
}
