<?php
declare(strict_types=1);

require_once __DIR__.'/phone-transactions-v1.php';

require_once __DIR__.'/phone-tracking-v1.php';
require_once __DIR__.'/phone-camera-chat-v1.php';
require_once __DIR__.'/paired-phone-agent-v1.php';
require_once __DIR__.'/paired-phone-chat-owner-v1.php';
require_once __DIR__.'/phone-tracking-arrival-chat-v1.php';
require_once __DIR__.'/phone-tracking-arrival-delivery-v1.php';
require_once __DIR__.'/phone-tracking-local-approval-v1.php';

/** Only content-free markers go into ordinary model history or shared Chat indexes. */
function vp3_phone_chat_schema_v1(PDO $pdo): void
{
    if (vp3_phone_transaction_active_v1($pdo)) throw new LogicException('Install Phone Tracking outside a transaction.');
    $options = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
        ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_phone_chat_turns_v1 (
        assistant_message_id BIGINT UNSIGNED NOT NULL PRIMARY KEY, user_message_id BIGINT UNSIGNED NULL UNIQUE,
        owner_user_id INT UNSIGNED NOT NULL, agent_id INT UNSIGNED NOT NULL, conversation_id BIGINT UNSIGNED NOT NULL,
        request_hash CHAR(64) NULL UNIQUE, query_hash CHAR(64) NULL, prompt VARCHAR(300) NOT NULL,
        answer TEXT NOT NULL, map_link VARCHAR(12) NOT NULL, map_device_id CHAR(32) NULL,
        FOREIGN KEY (assistant_message_id) REFERENCES chat_messages(id) ON DELETE CASCADE,
        FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE
    )".$options);
    if (!schema_metadata_column_exists($pdo,'vp3_phone_chat_turns_v1','map_device_id')) {
        try {
            $pdo->exec('ALTER TABLE vp3_phone_chat_turns_v1 ADD COLUMN map_device_id CHAR(32) NULL');
        } catch (PDOException $error) {
            if (!schema_metadata_column_exists($pdo,'vp3_phone_chat_turns_v1','map_device_id')) throw $error;
        }
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_phone_chat_grants_v1 (
        owner_user_id INT UNSIGNED NOT NULL, device_id CHAR(32) NOT NULL, generation BIGINT NOT NULL,
        agent_id INT UNSIGNED NOT NULL, conversation_id BIGINT UNSIGNED NOT NULL,
        pending SMALLINT NOT NULL, PRIMARY KEY (owner_user_id, device_id, generation),
        FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE
    )".$options);
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_phone_chat_place_choices_v1 (
        owner_user_id INT UNSIGNED NOT NULL, agent_id INT UNSIGNED NOT NULL, conversation_id BIGINT UNSIGNED NOT NULL,
        assistant_message_id BIGINT UNSIGNED NOT NULL, place_name VARCHAR(80) NOT NULL,
        candidates_json TEXT NOT NULL, expires_at BIGINT NOT NULL, PRIMARY KEY (owner_user_id,agent_id,conversation_id),
        FOREIGN KEY (assistant_message_id) REFERENCES chat_messages(id) ON DELETE CASCADE,
        FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE
    )".$options);
    vp3_paired_phone_schema_v1($pdo,$options);
    vp3_phone_arrival_receipts_schema_v1($pdo);
    vp3_phone_local_approval_schema_v1($pdo);
}

function vp3_phone_chat_schema_ready_v1(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT t.assistant_message_id,t.user_message_id,t.owner_user_id,t.agent_id,t.conversation_id,
            t.request_hash,t.query_hash,t.prompt,t.answer,t.map_link,t.map_device_id,g.pending,g.device_id,g.generation,
            p.place_name,p.candidates_json,p.expires_at,z.task_ids_json,r.task_query,e.visit_number,
            o.rule_version,o.generation,o.created_at,a.public_json,a.state,a.expires_at,a.preview_token,a.result_message_id,a.sources_json
            FROM vp3_phone_chat_turns_v1 t JOIN vp3_phone_chat_grants_v1 g ON g.owner_user_id=t.owner_user_id
            JOIN vp3_phone_chat_place_choices_v1 p ON p.assistant_message_id=t.assistant_message_id
            LEFT JOIN vp3_phone_arrival_briefings_v1 z ON z.assistant_message_id=t.assistant_message_id
            LEFT JOIN vp3_phone_arrival_rules_v1 r ON r.owner_user_id=t.owner_user_id
            LEFT JOIN vp3_phone_arrival_events_v1 e ON e.rule_id=r.id
            LEFT JOIN vp3_phone_local_offers_v1 o ON o.assistant_message_id=z.assistant_message_id
            LEFT JOIN vp3_phone_local_approvals_v1 a ON a.offer_id=o.assistant_message_id
            WHERE 1=0');
        return vp3_phone_local_research_ready_v1($pdo);
    } catch (PDOException) {
        return false;
    }
}

/** These are complete foreground commands. Embedded instructions in prose do not execute. */
function vp3_phone_chat_plan_v1(mixed $text): ?array
{
    if (!is_string($text) || mb_strlen($text) > 300) return null;
    $hasControls=(bool)preg_match('/[\p{Cc}\p{Cf}]/u',$text);
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '', " \t\r\n.!?");
    $text = preg_replace('/^(?:(?:can|could|would) you |please )+/iu', '', $text);
    $text = preg_replace('/,? please$/iu', '', $text);
    $camera=vp3_phone_camera_chat_plan_v1($text);
    if ($camera!==null) return $camera;
    $local=vp3_phone_local_approval_plan_v1($text);
    if ($local!==null) {
        if ($hasControls && $local['action']==='local_area') $local['area']='';
        return $local;
    }
    $arrival=vp3_phone_arrival_chat_plan_v1($text);
    if ($arrival!==null) return $arrival;
    if (preg_match('/^save (?:this|my|the current) (?:GPS )?location(?: (?:from|of|on) (.+?))? as (.+)$/iu',$text,$m)
        || preg_match('/^save (.+?)(?:’s|\x27s) (?:current )?location as (.+)$/iu',$text,$m)) {
        $name=preg_replace('/^(?:"(.*)"|“(.*)”)$/u','$1$2',trim($m[2]));
        return ['action'=>'save_place','target'=>trim($m[1]),'name'=>$name];
    }
    if (preg_match('/^(?:show|open|list)(?: me)? (?:my |the )?saved places$/iu',$text)) return ['action'=>'places','target'=>''];
    if (preg_match('/^(?:start|enable|keep|request|turn on)(?: saving)? (?:phone |GPS |location |trail )?history(?: (?:on|for|from) (.+?))? for (\d+) days?$/iu',$text,$m)
        && preg_match('/\b(?:phone|GPS|location|trail)\b/iu',$text)) {
        return ['action'=>'request_history','target'=>trim($m[1]),'days'=>(int)$m[2]];
    }
    if (preg_match('/^(show|open|check|start|enable|stop|disable|delete|clear)(?: me)?(?: saving)? (?:my |the )?(?:phone|GPS|location|trail) history(?: (?:on|for|from) (.+))?$/iu',$text,$m)) {
        return ['action'=>match(mb_strtolower($m[1])) {
            'start','enable'=>'request_history','stop','disable'=>'stop_history','delete','clear'=>'delete_history',default=>'history'
        },'target'=>trim($m[2]??'')];
    }
    if (preg_match('/^(?:connect|pair|link|add) (?:a |an |my |this |another |new |guest |the )*(?:phone|iphone|android|device)(?: to (?:my |this |the )?(?:account|agent|chat))?$/iu', $text)
        || preg_match('/^(?:show|open) (?:me )?(?:the |a )?(?:phone (?:pairing |connection )?QR(?: code)?|QR code (?:for|to connect) (?:my |a )?phone)$/iu', $text)) {
        return ['action'=>'connect','target'=>''];
    }
    if (preg_match('/^(?:(?:list|show)(?: me)? (?:my |the |all |connected |pending )*phones|which phones are connected)$/iu', $text)) {
        return ['action'=>'list','target'=>''];
    }
    if (preg_match('/^(?:(?:check|show)(?: me)? )?(?:(?:my |the )?(?:phone|gps|location sharing|phone tracking) status|tracking status)(?:(?: for| on| of)? (.+))?$/iu', $text, $m)
        || preg_match('/^(?:where is|where’s|where\x27s) ((?:my |the )?(?:phone|iphone|android)(?: .+)?)$/iu', $text, $m)) {
        return ['action'=>'status','target'=>trim($m[1] ?? '')];
    }
    if (preg_match('/^is (.+?) (?:sharing (?:its |my )?location|tracking|connected)$/iu', $text, $m)) {
        $target=trim($m[1]);
        return ['action'=>'status','target'=>$target,'implicit'=>!preg_match('/\b(?:phone|iphone|android|device)\b/iu',$target)];
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
    $name = trim(preg_replace('/[\p{C}<>\s]+/u',' ',(string)$device['name']) ?? '');
    // A user-controlled device label must never turn the chat summary into a coordinate channel.
    if (!$name || preg_match('/[-+]?\d{1,3}\.\d+\s*[,; ]\s*[-+]?\d{1,3}\.\d+/u',$name)) return 'Phone';
    return mb_substr($name,0,48);
}

function vp3_phone_chat_name_key_v1(string $name): string
{
    return mb_strtolower(preg_replace('/^[\s"\x27“”]+|[\s"\x27“”]+$/u','',str_replace('’', "'", $name)));
}

function vp3_phone_chat_matches_v1(array $devices, string $target): array
{
    $raw = vp3_phone_chat_name_key_v1($target);
    if (preg_match('/^(?:(?:the|a|my|this|that) )?((owner|guest)(?:\x27s)? (?:phone|device|pairing request|connection request))$/u',$raw,$role)) {
        // A phone can also be named "Guest phone". Neither interpretation may silently win.
        return array_values(array_filter($devices,static fn(array $d): bool => $d['role']===$role[2]
            || in_array(vp3_phone_chat_name_key_v1((string)$d['name']),[$raw,$role[1]],true)));
    }
    if ($raw === '' || preg_match('/^(?:(?:the|a|my|this|that|pending|new) )*(?:phone|device|pairing request|connection request)$/u',$raw)) return $devices;
    $keys = [$raw,preg_replace('/^(?:the|my) /u','',$raw)];
    $clean = preg_replace('/^(?:(?:the|my|this|that|pending|guest|new) )*(?:(?:phone|device|pairing request|connection request)(?: (?:from|for|named|called))? )/u','',$raw);
    $keys[] = vp3_phone_chat_name_key_v1($clean);
    $keys[] = preg_replace('/(?:\x27s)? (?:phone|device)$/u','',$clean);
    return array_values(array_filter($devices, static fn(array $d): bool =>
        in_array(vp3_phone_chat_name_key_v1((string)$d['name']),$keys,true) || $raw === '#'.$d['id']));
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

function vp3_phone_chat_actions_v1(string $mapLink,int $aid,int $cid,?string $deviceId=null,?string $sourceDevice=null): array
{
    if ($mapLink==='' || $mapLink==='localresult') return [];
    if ($mapLink==='camera_clip') {
        $asset=filter_var($deviceId,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        return $asset===false?[]:[['type'=>'open_url','label'=>'Open submitted recording','url'=>media_studio_asset_url($asset)]];
    }
    if ($mapLink==='camera_live') {
        if ($cid<1 || !preg_match('/^[a-f0-9]{32}$/D',$deviceId??'') || !preg_match('/^[a-f0-9]{32}$/D',$sourceDevice??'')) return [];
        return [['type'=>'phone_live_stream','label'=>'Show live stream','session_id'=>$deviceId,'device_id'=>$sourceDevice,
            'agent_id'=>$aid,'conversation_id'=>$cid]];
    }
    if ($mapLink==='camera') {
        if ($cid<1 || !is_string($deviceId) || !preg_match('/^[a-f0-9]{32}$/D',$deviceId)) return [];
        $path='/video-editor.php?'.http_build_query(['camera_session'=>$deviceId,'agent'=>$aid ?: 'system','conversation_id'=>$cid]);
        return [['type'=>'open_url','label'=>'Open private camera preview','url'=>url($path)]];
    }
    $path='/phone-tracking.php?'.http_build_query(array_filter(['agent'=>$aid,'conversation_id'=>$cid,
        'connect'=>$mapLink==='connect'?1:null,'panel'=>in_array($mapLink,['history','places'],true)?$mapLink:null,
        'device_id'=>$deviceId,'profile'=>$mapLink==='information'?'information':null],static fn($v)=>$v!==null));
    $label=match($mapLink) {'connect'=>'Show phone QR code','history'=>'Open location history','places'=>'Open saved places','information'=>'Open phone information exchange',default=>'Open tracking map'};
    return [['type'=>'open_url','label'=>$label,'url'=>url($path)]];
}

function vp3_phone_chat_result_v1(array $row,string $mode): array
{
    $aid=(int)$row['agent_id']; $cid=(int)$row['conversation_id'];
    return ['ok'=>true,'conversation_id'=>$cid,'agent_id'=>$aid,'user_message_id'=>(int)$row['user_message_id'],
        'assistant_message_id'=>(int)$row['assistant_message_id'],'answer'=>$row['answer'],
        'phone_tracking_context'=>['owner_user_id'=>(int)$row['owner_user_id'],'agent_id'=>$aid,'conversation_id'=>$cid],
        'sources'=>[],'actions'=>vp3_phone_chat_actions_v1($row['map_link'],$aid,$cid,$row['map_device_id']),'media'=>[],
        'input_mode'=>$mode,'execution'=>['mode'=>'cloud','status'=>'completed']];
}

function vp3_phone_chat_announcement_v1(array $turn): array
{
    return ['message_id'=>$turn['assistant_message_id'],'owner_user_id'=>$turn['owner_user_id'],
        'agent_id'=>$turn['agent_id'],'conversation_id'=>$turn['conversation_id'],'speech'=>$turn['answer']];
}

function vp3_phone_chat_store_v1(PDO $pdo,int $uid,int $aid,int $cid,string $prompt,string $answer,string $link,
    ?string $requestHash=null,?string $queryHash=null,?string $deviceId=null): array
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
        agent_id,conversation_id,request_hash,query_hash,prompt,answer,map_link,map_device_id) VALUES(?,?,?,?,?,?,?,?,?,?,?)',
        [$assistantId,$userMessageId,$uid,$aid,$cid,$requestHash,$queryHash,$prompt,$answer,$link,$deviceId]);
    vp3_phone_sql_v1($pdo,'UPDATE chat_conversations SET updated_at=CURRENT_TIMESTAMP WHERE id=? AND user_id=?',[$cid,$uid]);
    return ['owner_user_id'=>$uid,'agent_id'=>$aid,'conversation_id'=>$cid,'user_message_id'=>$userMessageId,'assistant_message_id'=>$assistantId,
        'answer'=>$answer,'map_link'=>$link,'map_device_id'=>$deviceId];
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
    return vp3_phone_chat_map_command_v1($pdo,$user,'request_start',$input);
}

function vp3_phone_chat_map_command_v1(PDO $pdo,array $user,string $action,array $input): array
{
    if (!in_array($action,['approve','request_start'],true)) throw new RuntimeException('Unknown phone action.',422);
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
        $result=vp3_phone_owner_v1($pdo,$uid,$action,$input);
        $device=$result['device'];
        if ($action==='approve' && !$result['duplicate'] && vp3_paired_phone_ready_v1($pdo)) {
            vp3_paired_phone_bind_v1($pdo,$device['id'],$uid,$aid,$cid);
        }
        if ($action==='request_start' && !$result['duplicate'] && in_array($device['location_state'],['requested','waiting'],true)) {
            vp3_phone_chat_watch_v1($pdo,$uid,$aid,$cid,$device);
        }
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** A later user turn supersedes a pending choice, while automatic Agent announcements do not. */
function vp3_phone_chat_place_choice_v1(PDO $pdo,int $uid,int $aid,int $cid,?string $requestHash=null,?string $queryHash=null): ?array
{
    $sql="SELECT p.assistant_message_id,p.place_name,p.candidates_json,0 AS replay FROM vp3_phone_chat_place_choices_v1 p
        WHERE p.owner_user_id=? AND p.agent_id=? AND p.conversation_id=? AND p.expires_at>?
        AND NOT EXISTS (SELECT 1 FROM chat_messages m WHERE m.conversation_id=p.conversation_id
            AND m.role='user' AND m.id>p.assistant_message_id)";
    $params=[$uid,$aid,$cid,time()];
    if ($requestHash!==null) {
        // A completed choice no longer has a pending row. Its exact private retry still resolves via its receipt.
        $sql.=' UNION ALL SELECT assistant_message_id,NULL,NULL,1 AS replay FROM vp3_phone_chat_turns_v1
            WHERE request_hash=? AND query_hash=? AND owner_user_id=? AND agent_id=? AND conversation_id=?
            ORDER BY replay DESC LIMIT 1';
        array_push($params,$requestHash,$queryHash,$uid,$aid,$cid);
    }
    $choice=vp3_phone_sql_v1($pdo,$sql,$params)->fetch(PDO::FETCH_ASSOC);
    if (!$choice) return null;
    if (!(int)$choice['replay']) {
        $payload=json_decode($choice['candidates_json'],true,512,JSON_THROW_ON_ERROR);
        if (($payload['type']??null)==='arrival') $choice['arrival']=$payload;
        elseif (($payload['type']??null)==='camera') $choice['camera']=$payload;
        else $choice['phones']=$payload;
    }
    return $choice;
}

function vp3_phone_chat_choice_matches_v1(array $choice,string $reply): array
{
    $name=vp3_phone_chat_name_key_v1($reply);
    $match=static fn(string $key): array => array_values(array_filter($choice['phones'],static fn($phone): bool =>
        vp3_phone_chat_name_key_v1($phone['name'])===$key || vp3_phone_chat_name_key_v1(vp3_phone_chat_label_v1($phone))===$key));
    return $match($name) ?: $match(rtrim($name,'.!?'));
}

function vp3_phone_chat_save_place_v1(PDO $pdo,int $uid,int $aid,int $cid,array $devices,array $plan,string $key): array
{
    $approved=array_values(array_filter($devices,static fn($d)=>$d['status']==='approved' && $d['allow_location']));
    if ($plan['action']==='place_choice') {
        $choice=vp3_phone_chat_place_choice_v1($pdo,$uid,$aid,$cid);
        $matches=isset($choice['phones'])?vp3_phone_chat_choice_matches_v1($choice,$plan['target']):[];
        if (!$choice || (int)$choice['assistant_message_id']!==$plan['choice_id'] || count($matches)!==1) {
            return ['That phone choice has changed. Ask me to save the location again.','places'];
        }
        $selected=$matches[0];
        $device=array_column($approved,null,'id')[$selected['id']]??null;
        if (!$device || $device['generation']!==$selected['generation']
            || $device['place_permission_version']!==$selected['place_permission_version']) {
            return ['That phone’s sharing permissions or session changed. Ask me to save the location again.','places'];
        }
        $name=$choice['place_name'];
    } else {
        try { $name=vp3_phone_place_name_v1($plan['name']); }
        catch (RuntimeException $e) {
            if ($e->getCode()!==422) throw $e;
            return [$e->getMessage(),'places'];
        }
        $matches=$plan['target']===''
            ?array_values(array_filter($approved,static fn($d)=>in_array($d['location_state'],['live','stale','waiting'],true)))
            :vp3_phone_chat_matches_v1($approved,$plan['target']);
        if (!$matches) return ['I need a fresh location from a sharing phone. Open the tracking map and check the phone.','map'];
        if (count($matches)>1) {
            $labels=array_map('vp3_phone_chat_label_v1',$matches);
            if (count(array_unique(array_map('vp3_phone_chat_name_key_v1',$labels)))!==count($labels)) {
                return ['Several phones have the same name. Select the exact phone on the tracking map to save its location.','map'];
            }
            $fields=array_flip(['id','name','generation','place_permission_version']);
            return ['Which phone? Reply with its name: '.implode(', ',$labels).'.','map',
                'choose_place'=>['name'=>$name,'phones'=>array_map(static fn($d)=>array_intersect_key($d,$fields),$matches)]];
        }
        $device=$matches[0];
    }
    $label=vp3_phone_chat_label_v1($device);
    if (empty($device['allow_saved_places'])) return ['Turn on Allow saved places on '.$label.' while sharing, then ask me to save the location again.','map'];
    if ($device['location_state']!=='live') return ['Waiting for a fresh location from '.$label.'. Ask me again when the tracking map shows it live.','map'];
    try {
        $result=vp3_phone_places_owner_v1($pdo,$uid,'save_place',['device_id'=>$device['id'],'generation'=>$device['generation'],
            'name'=>$name,'request_id'=>$key]);
    } catch (RuntimeException $e) {
        if ($e->getCode()!==409 || $e->getMessage()!=='A saved place already uses that name.') throw $e;
        return ['A saved place already uses that name. Choose another name or open Saved places.','places'];
    }
    $saved=$result['place']['name'];
    $describe=preg_match('/[-+]?\d{1,3}\.\d+\s*[,; ]\s*[-+]?\d{1,3}\.\d+/u',$saved)?'the place':'“'.$saved.'”';
    return ['Saved '.$describe.' from '.$label.'’s current location.','places','announce'=>true];
}

function vp3_phone_chat_execute_v1(PDO $pdo,int $uid,int $aid,int $cid,array $plan,string $key): array
{
    $action=$plan['action'];
    if (str_starts_with($action,'camera_')) return vp3_phone_camera_chat_turn_v1($pdo,$uid,$aid,$cid,$plan);
    if (str_starts_with($action,'arrival_')) return vp3_phone_arrival_chat_execute_v1($pdo,$uid,$aid,$cid,$plan);
    if ($action==='connect') return ['Open the phone QR code and scan it with the phone you want to connect. Then ask me to approve its name.','connect'];
    if ($action==='places') return ['Open Saved places on your tracking map to see your private places.','places'];
    $devices=vp3_phone_owner_v1($pdo,$uid,'state',[])['devices'];
    if ($action==='save_place' || $action==='place_choice') return vp3_phone_chat_save_place_v1($pdo,$uid,$aid,$cid,$devices,$plan,$key);
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
        return [count(array_unique(array_map('vp3_phone_chat_name_key_v1',$names)))===count($names)?'Which phone? Repeat your command with “phone named” followed by one of these names: '.implode(', ',$names).'.'
            :'Several phones have the same name. Choose the exact device on the tracking map.','map'];
    }
    $device=$matches[0]; $name=vp3_phone_chat_label_v1($device);
    if ($action==='status') return [$name.' is '.vp3_phone_chat_status_v1($device).'.','map'];
    if ($action==='history') {
        $days=(int)$device['history_retention_days'];
        $duration=$days.' '.($days===1?'day':'days');
        $state=match($device['history_state']) {
            'active'=>'is on for prior locations within '.$duration,
            'requested'=>'is waiting for approval on the phone for '.$duration,
            default=>'is off'
        };
        return ['Location history for '.$name.' '.$state.'. Open History to review any retained points.','history','device_id'=>$device['id']];
    }
    if (in_array($action,['request_history','stop_history','delete_history'],true)) {
        $input=['device_id'=>$device['id'],'generation'=>$device['generation'],'history_version'=>$device['history_version'],'request_id'=>$key];
        if ($action==='request_history') {
            if (!in_array($plan['days']??0,[1,7,30],true)) return ['Choose a retention period of 1, 7 or 30 days.','history'];
            if (!in_array($device['location_state'],['waiting','live','stale'],true)) return ['Start sharing location on '.$name.' before requesting history.','map'];
            $input['retention_days']=$plan['days'];
        }
        $updated=vp3_phone_owner_v1($pdo,$uid,$action,$input)['device'];
        $answer=match($action) {
            'request_history'=>$updated['history_state']==='active'?'Location history is active for '.$name.'.'
                :'Location history requested for '.$name.'. Approve it on the phone before VP3 stores any new trail points.',
            'stop_history'=>'Location history stopped for '.$name.'. Existing points expire on schedule.',
            'delete_history'=>'Deleted the retained location history for '.$name.' and stopped saving new points.'
        };
        return [$answer,'history','device_id'=>$device['id'],'announce'=>true];
    }
    $result=vp3_phone_owner_v1($pdo,$uid,$action,['device_id'=>$device['id'],'generation'=>(int)$device['generation'],'request_id'=>$key]);
    $device=$result['device'];
    if ($action==='approve' && !$result['duplicate'] && vp3_paired_phone_ready_v1($pdo)) {
        vp3_paired_phone_bind_v1($pdo,$device['id'],$uid,$aid,$cid);
    }
    $answer=match ($action) {
        'approve'=>$name.' is connected. GPS sharing is off.',
        'deny'=>'Connection request denied for '.$name.'.',
        'stop'=>$device['connected']?'GPS sharing stopped for '.$name.'.':'VP3 stopped accepting location from '.$name.'. The phone is offline.',
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
    return [$answer,'map','announce'=>$action==='stop'];
}

/** Short commands like "approve Pixel" are only phone intents for an exact device in this owner's account. */
function vp3_phone_chat_route_v1(PDO $pdo,array $user,array $principal,array $input): ?array
{
    $text=$input['message']??null;
    $plan=vp3_phone_chat_plan_v1($text);
    $cid=(int)($input['conversation_id']??0);
    $uid=(int)$user['id']; $aid=(int)($principal['agent_id']??0);
    $key=$input['idempotency_key']??$input['reminder_request_id']??null;
    $requestHash=is_string($key)?hash('sha256',json_encode([$uid,$aid,$key],JSON_THROW_ON_ERROR)):null;
    $ownerPage=empty($input['initiative_reply']) && vp3_phone_chat_page_v1($user);
    try {
        if (!empty($plan['implicit_local'])) {
            if (!$ownerPage || $cid<1 || !vp3_phone_chat_schema_ready_v1($pdo) || !vp3_phone_local_has_offer_v1($pdo,$uid,$aid,$cid)) {
                // Preserve an exact private replay after its offer has been consumed.
                $saved=$requestHash && $ownerPage && $cid>0 && vp3_phone_chat_schema_ready_v1($pdo)
                    ? vp3_phone_sql_v1($pdo,'SELECT 1 FROM vp3_phone_chat_turns_v1 WHERE request_hash=? AND query_hash=?
                        AND owner_user_id=? AND agent_id=? AND conversation_id=?',[$requestHash,hash('sha256',trim($text)),$uid,$aid,$cid])->fetchColumn()
                    : false;
                if (!$saved) return null;
            }
            vp3_phone_chat_authorize_v1($pdo,$user,$principal,$input,$cid);
            return $plan;
        }
        if ($plan && str_starts_with($plan['action'],'local_')) return $plan;
        if ($ownerPage && $cid>0 && is_string($text) && trim($text)!=='' && mb_strlen($text)<=52) {
            // Check the pending question before commands: a holder can even name their phone "Stop sharing".
            // The normal short-message path only looks up the scoped question and exact receipt by index.
            try { $choice=vp3_phone_chat_place_choice_v1($pdo,$uid,$aid,$cid,$requestHash,hash('sha256',trim($text))); }
            catch (PDOException $e) {
                if (vp3_phone_chat_schema_ready_v1($pdo)) throw $e;
                $choice=null;
            }
            if (isset($choice['camera']) && vp3_phone_camera_chat_reply_v1($choice['camera'],$text)) {
                vp3_phone_chat_authorize_v1($pdo,$user,$principal,$input,$cid);
                return ['action'=>'camera_choice','target'=>$text,'choice_id'=>(int)$choice['assistant_message_id']];
            }
            if (isset($choice['arrival']) && vp3_phone_arrival_chat_reply_v1($choice['arrival'],$text)) {
                vp3_phone_chat_authorize_v1($pdo,$user,$principal,$input,$cid);
                return ['action'=>'arrival_reply','target'=>$text,'choice_id'=>(int)$choice['assistant_message_id']];
            }
            if ($choice && ((int)$choice['replay'] || (isset($choice['phones']) && vp3_phone_chat_choice_matches_v1($choice,$text)))) {
                vp3_phone_chat_authorize_v1($pdo,$user,$principal,$input,$cid);
                return ['action'=>'place_choice','target'=>$text,'choice_id'=>(int)$choice['assistant_message_id']];
            }
        }
        if ($plan===null || empty($plan['implicit'])) return $plan;
        if (!$ownerPage || !vp3_phone_schema_ready_v1($pdo) || !vp3_phone_chat_schema_ready_v1($pdo)) return null;
        vp3_phone_chat_authorize_v1($pdo,$user,$principal,$input,$cid);
        // An implicit denial or disconnect can be retried after the phone has disappeared from the roster.
        if ($requestHash!==null) {
            $saved=vp3_phone_sql_v1($pdo,'SELECT 1 FROM vp3_phone_chat_turns_v1 WHERE request_hash=? AND owner_user_id=? AND agent_id=?',
                [$requestHash,$uid,$aid]);
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
    if (str_starts_with($plan['action'],'camera_') && !vp3_phone_camera_ready_v1($pdo)) {
        throw new RuntimeException('Phone camera sharing is unavailable until the database upgrade is installed.',503);
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
        $turn=str_starts_with($plan['action'],'local_')
            ? vp3_phone_local_approval_turn_v1($pdo,$user,$principal,$cid,$plan,$input)
            : vp3_phone_chat_execute_v1($pdo,$uid,$aid,$cid,$plan,$requestHash);
        [$answer,$mapLink]=$turn;
        // A private phone label or a quoted coordinate must never become shared Chat history.
        $prompt=preg_match('/[-+]?\d{1,3}\.\d+\s*[,; ]\s*[-+]?\d{1,3}\.\d+/u',$query)?'Phone tracking request.':$query;
        $saved=vp3_phone_chat_store_v1($pdo,$uid,$aid,$cid,$prompt,$answer,$mapLink,$requestHash,$queryHash,$turn['device_id']??null);
        vp3_phone_sql_v1($pdo,'DELETE FROM vp3_phone_chat_place_choices_v1 WHERE owner_user_id=? AND agent_id=? AND conversation_id=?',[$uid,$aid,$cid]);
        if (isset($turn['choose_place']) || isset($turn['arrival_draft']) || isset($turn['camera_draft'])) {
            $choice=$turn['choose_place']??null;
            $draft=$turn['arrival_draft']??$turn['camera_draft']??null;
            $placeName=$draft['place_name']??$choice['name']??'';
            $payload=$draft??$choice['phones'];
            vp3_phone_sql_v1($pdo,'INSERT INTO vp3_phone_chat_place_choices_v1
                (owner_user_id,agent_id,conversation_id,assistant_message_id,place_name,candidates_json,expires_at)
                VALUES(?,?,?,?,?,?,?)',[$uid,$aid,$cid,$saved['assistant_message_id'],$placeName,
                    json_encode($payload,JSON_THROW_ON_ERROR),time()+600]);
        }
        vp3_phone_chat_authorize_v1($pdo,$user,$principal,$input,$cid,$authority);
        $pdo->commit();
        $result=vp3_phone_chat_result_v1($saved,$mode);
        // Voice already reads its direct reply. Only a newly completed typed action queues an announcement.
        if ($mode==='text' && !empty($turn['announce'])) {
            $result['phone_updates']=[vp3_phone_chat_announcement_v1($saved)];
        }
        return $result;
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
    $localPending=vp3_phone_local_research_pending_v1($pdo,$uid,$aid,$cid);
    $cameraPending=vp3_phone_camera_pending_v1($pdo,$uid,$aid,$cid);
    $pairedPending=vp3_paired_phone_pending_v1($pdo,$uid,$aid,$cid);
    $recordPending=vp3_phone_record_pending_v1($pdo,$uid,$aid,$cid);
    $informationPending=(bool)vp3_phone_information_announcements_v1($pdo,$uid,$aid,$cid,false);
    if (!$informationPending && !$recordPending && !$pairedPending && !$localPending && !$cameraPending && !vp3_phone_sql_v1($pdo,$sql,[$uid,$aid,$cid])->fetchColumn() && !vp3_phone_arrival_pending_v1($pdo,$uid,$aid,$cid)) return [];
    vp3_phone_chat_authorize_v1($pdo,$user,$principal,$input,$cid);
    $eligible=static function(int $offerId) use($pdo,$user,$principal,$input,$cid): bool {
        try {
            vp3_phone_chat_authorize_v1($pdo,$user,$principal,$input,$cid);
            return vp3_phone_local_authorized_offer_v1($pdo,$user,$principal,$cid,$offerId);
        } catch (RuntimeException $error) {
            if (in_array($error->getCode(),[403,404],true)) return false;
            throw $error;
        }
    };
    if ($localPending) vp3_phone_local_research_work_v1($pdo,$user,$principal,$cid,$eligible);
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
                $updates[]=vp3_phone_chat_announcement_v1($row);
            }
        }
        if ($informationPending) $updates=array_merge($updates,vp3_phone_information_announcements_v1($pdo,$uid,$aid,$cid,true));
        if ($pairedPending) $updates=array_merge($updates,vp3_paired_phone_owner_updates_v1($pdo,$uid,$aid,$cid));
        if ($cameraPending) $updates=array_merge($updates,vp3_phone_camera_chat_updates_v1($pdo,$uid,$aid,$cid));
        if ($recordPending) $updates=array_merge($updates,vp3_phone_record_announcements_v1($pdo,$uid,$aid,$cid));
        $updates=array_merge($updates,vp3_phone_arrival_deliver_v1($pdo,$user,$principal,$input,$cid));
        if ($localPending) $updates=array_merge($updates,vp3_phone_local_emit_v1($pdo,$user,$principal,$cid,$eligible));
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
    $paired=[];
    foreach ($rows as $i=>$row) {
        if (($row['context_json']??null)==='{"source":"paired_phone_agent"}') $paired[(int)$row['id']]=$i;
        if (($row['context_json']??null)==='{"source":"private_phone_tracking"}') $markers[(int)$row['id']]=$i;
    }
    if ((!$markers && !$paired) || !$cid || !vp3_phone_chat_page_v1($user)) return $rows;
    try {
        $agent=vp3_agent_chat_resolve_agent_v380($pdo,$user,$aid);
        vp3_phone_chat_authorize_v1($pdo,$user,vp3_agent_chat_principal_v380($user,$agent),$input,$cid);
    } catch (RuntimeException) {
        return $rows;
    }
    if ($paired) $rows=vp3_paired_phone_owner_history_v1($pdo,(int)$user['id'],$aid,$cid,$rows,$paired);
    if (!$markers) return $rows;
    $ids=array_keys($markers); $marks=implode(',',array_fill(0,count($ids),'?'));
    // Old installs can still hydrate private phone history during a rolling upgrade.
    $cameraJoin='';
    $cameraField='NULL AS live_device';
    $params=[];
    if (vp3_phone_camera_ready_v1($pdo)) {
        $now=time();
        $cutoff=$now-VP3_PHONE_CAMERA_LEASE_SECONDS_V1;
        $cameraJoin=" LEFT JOIN vp3_phone_camera_sessions_v1 s ON t.map_link='camera_live' AND s.id=t.map_device_id
            AND s.owner_user_id=t.owner_user_id AND s.agent_id=t.agent_id AND s.conversation_id=t.conversation_id
            AND s.state IN ('sharing','answered') AND s.expires_at>? AND s.holder_seen>? AND (s.state<>'answered' OR s.owner_seen>?)
            LEFT JOIN vp3_phone_devices_v1 d ON d.owner_user_id=s.owner_user_id AND d.id=s.device_id AND d.device_status='approved' AND d.expires_at>?";
        $cameraField='d.id AS live_device';
        $params=[$now,$cutoff,$cutoff,$now];
    }
    $records=vp3_phone_sql_v1($pdo,"SELECT t.*,$cameraField FROM vp3_phone_chat_turns_v1 t $cameraJoin
        WHERE t.owner_user_id=? AND t.agent_id=? AND t.conversation_id=?
        AND (t.assistant_message_id IN ($marks) OR t.user_message_id IN ($marks))",[...$params,(int)$user['id'],$aid,$cid,...$ids,...$ids]);
    foreach ($records->fetchAll(PDO::FETCH_ASSOC) as $record) {
        $assistant=(int)$record['assistant_message_id']; $author=(int)$record['user_message_id'];
        if (isset($markers[$author])) $rows[$markers[$author]]['message']=$record['prompt'];
        if (isset($markers[$assistant])) {
            $i=$markers[$assistant];
            $context=['source'=>'private_phone_tracking','actions'=>vp3_phone_chat_actions_v1($record['map_link'],$aid,$cid,$record['map_device_id'],$record['live_device'])];
            if ($record['map_link']==='localresult') {
                [$rows[$i]['message'],$context['sources']]=vp3_phone_local_history_v1($pdo,$record);
            } else {
                $rows[$i]['message']=vp3_phone_arrival_history_answer_v1($pdo,$user,$aid,$cid,$record);
            }
            $rows[$i]['context_json']=json_encode($context,JSON_THROW_ON_ERROR);
        }
    }
    return $rows;
}
