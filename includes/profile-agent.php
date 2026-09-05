<?php
declare(strict_types=1);

const STONEFELLOW_PROFILE_AGENT_BUILD = 'profile-agent-attention-20260903';
const STONEFELLOW_PROFILE_VIEW_DEDUPE_SECONDS = 1800;

function profile_agent_schema_ready(?PDO $pdo = null): bool
{
    $pdo ??= db();
    return (bool)$pdo
        && table_exists('user_profiles')
        && table_exists('profile_visit_sessions')
        && table_exists('profile_events')
        && table_exists('profile_agent_conversations')
        && table_exists('profile_agent_messages')
        && table_exists('agent_attention_items');
}

function profile_agent_ensure_schema(?PDO $pdo = null): void
{
    $pdo ??= db();
    if (!$pdo) throw new RuntimeException('Database connection is unavailable.');

    $pdo->exec("CREATE TABLE IF NOT EXISTS user_profiles (
      user_id INT UNSIGNED NOT NULL PRIMARY KEY,
      username VARCHAR(80) NULL,
      bio TEXT NULL,
      cover_path VARCHAR(500) NOT NULL DEFAULT '',
      website_url VARCHAR(500) NOT NULL DEFAULT '',
      instagram_url VARCHAR(500) NOT NULL DEFAULT '',
      tiktok_url VARCHAR(500) NOT NULL DEFAULT '',
      youtube_url VARCHAR(500) NOT NULL DEFAULT '',
      spotify_url VARCHAR(500) NOT NULL DEFAULT '',
      apple_music_url VARCHAR(500) NOT NULL DEFAULT '',
      is_public TINYINT(1) NOT NULL DEFAULT 0,
      share_visit_identity TINYINT(1) NOT NULL DEFAULT 0,
      profile_agent_id BIGINT UNSIGNED NULL,
      profile_agent_enabled TINYINT(1) NOT NULL DEFAULT 0,
      profile_agent_greeting VARCHAR(500) NOT NULL DEFAULT '',
      profile_agent_instructions TEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_user_profiles_username (username),
      INDEX idx_user_profiles_public (is_public,username,user_id),
      INDEX idx_user_profiles_agent (profile_agent_id,profile_agent_enabled),
      CONSTRAINT fk_user_profiles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_user_profiles_agent FOREIGN KEY (profile_agent_id) REFERENCES user_agents(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS profile_visit_sessions (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      owner_user_id INT UNSIGNED NOT NULL,
      visitor_user_id INT UNSIGNED NULL,
      session_key CHAR(64) NOT NULL,
      identity_disclosed TINYINT(1) NOT NULL DEFAULT 0,
      view_count INT UNSIGNED NOT NULL DEFAULT 0,
      first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      last_message_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_profile_visit_session (owner_user_id,session_key),
      INDEX idx_profile_visit_owner (owner_user_id,last_seen_at,id),
      INDEX idx_profile_visit_visitor (visitor_user_id,last_seen_at),
      CONSTRAINT fk_profile_visit_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_profile_visit_visitor FOREIGN KEY (visitor_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS profile_events (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      owner_user_id INT UNSIGNED NOT NULL,
      profile_session_id BIGINT UNSIGNED NULL,
      visitor_user_id INT UNSIGNED NULL,
      profile_agent_id BIGINT UNSIGNED NULL,
      event_type VARCHAR(50) NOT NULL,
      priority SMALLINT UNSIGNED NOT NULL DEFAULT 10,
      dedupe_key CHAR(64) NULL,
      metadata_json LONGTEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_profile_event_dedupe (dedupe_key),
      INDEX idx_profile_event_owner (owner_user_id,created_at,id),
      INDEX idx_profile_event_concern (owner_user_id,event_type,priority,created_at),
      CONSTRAINT fk_profile_event_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_profile_event_session FOREIGN KEY (profile_session_id) REFERENCES profile_visit_sessions(id) ON DELETE SET NULL,
      CONSTRAINT fk_profile_event_visitor FOREIGN KEY (visitor_user_id) REFERENCES users(id) ON DELETE SET NULL,
      CONSTRAINT fk_profile_event_agent FOREIGN KEY (profile_agent_id) REFERENCES user_agents(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS profile_agent_conversations (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      owner_user_id INT UNSIGNED NOT NULL,
      profile_agent_id BIGINT UNSIGNED NOT NULL,
      profile_session_id BIGINT UNSIGNED NOT NULL,
      visitor_user_id INT UNSIGNED NULL,
      status VARCHAR(30) NOT NULL DEFAULT 'open',
      last_summary VARCHAR(1000) NOT NULL DEFAULT '',
      started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      last_message_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX idx_profile_conversation_owner (owner_user_id,status,last_message_at,id),
      INDEX idx_profile_conversation_session (profile_session_id,last_message_at,id),
      CONSTRAINT fk_profile_conversation_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_profile_conversation_agent FOREIGN KEY (profile_agent_id) REFERENCES user_agents(id) ON DELETE CASCADE,
      CONSTRAINT fk_profile_conversation_session FOREIGN KEY (profile_session_id) REFERENCES profile_visit_sessions(id) ON DELETE CASCADE,
      CONSTRAINT fk_profile_conversation_visitor FOREIGN KEY (visitor_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS profile_agent_messages (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      conversation_id BIGINT UNSIGNED NOT NULL,
      sender_type VARCHAR(20) NOT NULL,
      sender_user_id INT UNSIGNED NULL,
      message TEXT NOT NULL,
      context_json LONGTEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_profile_messages_conversation (conversation_id,id),
      CONSTRAINT fk_profile_message_conversation FOREIGN KEY (conversation_id) REFERENCES profile_agent_conversations(id) ON DELETE CASCADE,
      CONSTRAINT fk_profile_message_user FOREIGN KEY (sender_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_attention_items (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      owner_user_id INT UNSIGNED NOT NULL,
      source_event_id BIGINT UNSIGNED NULL,
      source_conversation_id BIGINT UNSIGNED NULL,
      profile_agent_id BIGINT UNSIGNED NULL,
      visitor_user_id INT UNSIGNED NULL,
      attention_type VARCHAR(50) NOT NULL,
      priority SMALLINT UNSIGNED NOT NULL DEFAULT 10,
      status VARCHAR(20) NOT NULL DEFAULT 'pending',
      headline VARCHAR(255) NOT NULL,
      summary TEXT NOT NULL,
      assessment VARCHAR(1000) NOT NULL DEFAULT '',
      recommended_response VARCHAR(1000) NOT NULL DEFAULT '',
      actions_json LONGTEXT NULL,
      context_json LONGTEXT NULL,
      snoozed_until DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_attention_event (source_event_id),
      INDEX idx_attention_owner (owner_user_id,status,priority,created_at,id),
      INDEX idx_attention_conversation (source_conversation_id,status,id),
      CONSTRAINT fk_attention_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_attention_event FOREIGN KEY (source_event_id) REFERENCES profile_events(id) ON DELETE CASCADE,
      CONSTRAINT fk_attention_conversation FOREIGN KEY (source_conversation_id) REFERENCES profile_agent_conversations(id) ON DELETE CASCADE,
      CONSTRAINT fk_attention_agent FOREIGN KEY (profile_agent_id) REFERENCES user_agents(id) ON DELETE SET NULL,
      CONSTRAINT fk_attention_visitor FOREIGN KEY (visitor_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function profile_username_normalize(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9._-]+/', '-', $value) ?? '';
    $value = trim($value, '.-_');
    return substr($value, 0, 60);
}

function profile_username_valid(string $value): bool
{
    return (bool)preg_match('/^[a-z0-9](?:[a-z0-9._-]{1,58}[a-z0-9])?$/', $value)
        && !in_array($value, ['admin','api','account','artist-listening','assets','audio','chat','contact','images','index','knowledge','login','logout','notifications','private','profile','register','settings','signup','stonefellow','support','system','tests','tools','upgrade','uploads','video-editor','voice-profile'], true);
}

function profile_public_url(string $username): string
{
    return url('/' . rawurlencode($username));
}

function profile_user_row(PDO $pdo, int $userId): ?array
{
    $stmt=$pdo->prepare('SELECT id,display_name,email,role,is_active,avatar_path FROM users WHERE id=? LIMIT 1');
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: null;
}

function profile_by_username(PDO $pdo, string $username): ?array
{
    $username=profile_username_normalize($username);
    if($username==='')return null;
    $stmt=$pdo->prepare('SELECT p.*,u.display_name,u.role,u.is_active,u.avatar_path FROM user_profiles p INNER JOIN users u ON u.id=p.user_id WHERE p.username=? LIMIT 1');
    $stmt->execute([$username]);
    return $stmt->fetch() ?: null;
}

function profile_for_user(PDO $pdo, int $userId, bool $create=false): ?array
{
    $stmt=$pdo->prepare('SELECT p.*,u.display_name,u.role,u.is_active,u.avatar_path FROM user_profiles p INNER JOIN users u ON u.id=p.user_id WHERE p.user_id=? LIMIT 1');
    $stmt->execute([$userId]);
    $row=$stmt->fetch();
    if($row||!$create)return $row?:null;
    $pdo->prepare('INSERT IGNORE INTO user_profiles (user_id) VALUES (?)')->execute([$userId]);
    return profile_for_user($pdo,$userId,false);
}

function profile_safe_external_url(string $value): string
{
    $value=trim($value);
    if($value==='')return '';
    if(!filter_var($value,FILTER_VALIDATE_URL))throw new RuntimeException('Profile links must be valid URLs.');
    if(!in_array(strtolower((string)parse_url($value,PHP_URL_SCHEME)),['http','https'],true))throw new RuntimeException('Profile links must use http or https.');
    return mb_strimwidth($value,0,500,'');
}

function profile_save(PDO $pdo, array $user, array $input): array
{
    $uid=(int)($user['id']??0);
    if($uid<1)throw new RuntimeException('Sign in to edit your profile.');
    profile_for_user($pdo,$uid,true);
    $username=profile_username_normalize((string)($input['username']??''));
    if($username===''||!profile_username_valid($username))throw new RuntimeException('Choose a username using 3–60 letters, numbers, dots, dashes or underscores.');
    $dup=$pdo->prepare('SELECT user_id FROM user_profiles WHERE username=? AND user_id<>? LIMIT 1');$dup->execute([$username,$uid]);
    if($dup->fetchColumn())throw new RuntimeException('That username is already in use.');
    $bio=mb_strimwidth(trim((string)($input['bio']??'')),0,4000,'…');
    $links=[];foreach(['website_url','instagram_url','tiktok_url','youtube_url','spotify_url','apple_music_url'] as $field)$links[$field]=profile_safe_external_url((string)($input[$field]??''));
    $stmt=$pdo->prepare('UPDATE user_profiles SET username=?,bio=?,website_url=?,instagram_url=?,tiktok_url=?,youtube_url=?,spotify_url=?,apple_music_url=?,is_public=?,share_visit_identity=? WHERE user_id=?');
    $stmt->execute([$username,$bio,$links['website_url'],$links['instagram_url'],$links['tiktok_url'],$links['youtube_url'],$links['spotify_url'],$links['apple_music_url'],!empty($input['is_public'])?1:0,!empty($input['share_visit_identity'])?1:0,$uid]);
    return profile_for_user($pdo,$uid,false)?:throw new RuntimeException('Profile could not be saved.');
}

function profile_migrate_artist_identity(PDO $pdo, array $user): array
{
    $uid=(int)$user['id'];$profile=profile_for_user($pdo,$uid,true)?:[];
    if(!empty($profile['username']))return $profile;
    if(function_exists('artist_workspace_v181_lookup_public')&&artist_workspace_v181_schema_ready($pdo)){
        $workspace=artist_workspace_v181_lookup_public($pdo,'',$uid);
        if($workspace){
            $candidate=profile_username_normalize((string)($workspace['profile_slug']??''));
            if($candidate!==''&&profile_username_valid($candidate)){
                $dup=$pdo->prepare('SELECT 1 FROM user_profiles WHERE username=? AND user_id<>? LIMIT 1');$dup->execute([$candidate,$uid]);
                if(!$dup->fetchColumn()){
                    $pdo->prepare('UPDATE user_profiles SET username=?,bio=CASE WHEN bio IS NULL OR bio="" THEN ? ELSE bio END,is_public=1 WHERE user_id=?')->execute([$candidate,(string)($workspace['bio']??''),$uid]);
                }
            }
        }
    }
    return profile_for_user($pdo,$uid,false)?:$profile;
}

function profile_active_agent(PDO $pdo, array $profile): ?array
{
    if(empty($profile['profile_agent_enabled']))return null;
    $agentId=(int)($profile['profile_agent_id']??0);if($agentId<1)return null;
    $agent=user_agent_get_v236($pdo,(int)$profile['user_id'],$agentId);
    if(!$agent||empty($agent['is_active']))return null;
    return $agent;
}

function profile_configure_agent(PDO $pdo, array $user, array $input): array
{
    $uid=(int)$user['id'];$profile=profile_for_user($pdo,$uid,true)?:throw new RuntimeException('Profile could not be loaded.');
    $agentId=max(0,(int)($input['profile_agent_id']??0));$enabled=!empty($input['profile_agent_enabled']);
    if($enabled&&$agentId<1)throw new RuntimeException('Choose one of your agents before enabling the Profile Agent.');
    if($agentId>0){$agent=user_agent_get_v236($pdo,$uid,$agentId);if(!$agent||empty($agent['is_active']))throw new RuntimeException('That agent is not available.');}
    $pdo->prepare('UPDATE user_agents SET is_profile_agent=0 WHERE owner_user_id=?')->execute([$uid]);
    if($agentId>0)$pdo->prepare('UPDATE user_agents SET is_profile_agent=1 WHERE id=? AND owner_user_id=?')->execute([$agentId,$uid]);
    $greeting=mb_strimwidth(trim((string)($input['profile_agent_greeting']??'')),0,500,'…');
    $instructions=mb_strimwidth(trim((string)($input['profile_agent_instructions']??'')),0,4000,'…');
    $pdo->prepare('UPDATE user_profiles SET profile_agent_id=?,profile_agent_enabled=?,profile_agent_greeting=?,profile_agent_instructions=? WHERE user_id=?')->execute([$agentId?:null,$enabled?1:0,$greeting,$instructions,$uid]);
    return profile_for_user($pdo,$uid,false)?:throw new RuntimeException('Profile Agent settings could not be saved.');
}

function profile_session_token(int $ownerUserId): string
{
    if(!isset($_SESSION['profile_visit_tokens'])||!is_array($_SESSION['profile_visit_tokens']))$_SESSION['profile_visit_tokens']=[];
    $key=(string)$ownerUserId;
    if(empty($_SESSION['profile_visit_tokens'][$key]))$_SESSION['profile_visit_tokens'][$key]=bin2hex(random_bytes(24));
    return (string)$_SESSION['profile_visit_tokens'][$key];
}

function profile_session_hash(int $ownerUserId): string
{
    return hash('sha256',profile_session_token($ownerUserId).'|'.$ownerUserId);
}

function profile_chat_token(int $ownerUserId): string
{
    if(!isset($_SESSION['profile_chat_tokens'])||!is_array($_SESSION['profile_chat_tokens']))$_SESSION['profile_chat_tokens']=[];
    $key=(string)$ownerUserId;
    if(empty($_SESSION['profile_chat_tokens'][$key]))$_SESSION['profile_chat_tokens'][$key]=bin2hex(random_bytes(24));
    return (string)$_SESSION['profile_chat_tokens'][$key];
}

function profile_chat_token_valid(int $ownerUserId,string $token): bool
{
    return $token!==''&&hash_equals(profile_chat_token($ownerUserId),$token);
}

function profile_visitor_discloses_identity(PDO $pdo, ?array $visitor): bool
{
    if(!$visitor)return false;$profile=profile_for_user($pdo,(int)$visitor['id'],false);return $profile&&!empty($profile['share_visit_identity']);
}

function profile_visit_session(PDO $pdo,int $ownerUserId,?array $visitor): array
{
    $visitorId=(int)($visitor['id']??0);$identity=$visitorId>0&&profile_visitor_discloses_identity($pdo,$visitor);
    $hash=profile_session_hash($ownerUserId);
    $stmt=$pdo->prepare('INSERT INTO profile_visit_sessions (owner_user_id,visitor_user_id,session_key,identity_disclosed,view_count) VALUES (?,?,?,?,1) ON DUPLICATE KEY UPDATE visitor_user_id=VALUES(visitor_user_id),identity_disclosed=VALUES(identity_disclosed),view_count=view_count+1,last_seen_at=NOW()');
    $stmt->execute([$ownerUserId,$visitorId?:null,$hash,$identity?1:0]);
    $get=$pdo->prepare('SELECT * FROM profile_visit_sessions WHERE owner_user_id=? AND session_key=? LIMIT 1');$get->execute([$ownerUserId,$hash]);
    return $get->fetch()?:throw new RuntimeException('Profile visit session could not be created.');
}

function profile_visitor_label(PDO $pdo, array $session): string
{
    if(empty($session['identity_disclosed'])||(int)($session['visitor_user_id']??0)<1)return 'Someone';
    $u=profile_user_row($pdo,(int)$session['visitor_user_id']);return trim((string)($u['display_name']??''))?:'A Stonefellow member';
}

function profile_event_create(PDO $pdo,int $ownerUserId,array $session,string $type,int $priority,?int $agentId=null,array $metadata=[],?string $dedupeKey=null): ?array
{
    if((int)($session['visitor_user_id']??0)===$ownerUserId)return null;
    try{
        $stmt=$pdo->prepare('INSERT INTO profile_events (owner_user_id,profile_session_id,visitor_user_id,profile_agent_id,event_type,priority,dedupe_key,metadata_json) VALUES (?,?,?,?,?,?,?,?)');
        $stmt->execute([$ownerUserId,(int)$session['id'],(int)($session['visitor_user_id']??0)?:null,$agentId?:null,$type,$priority,$dedupeKey,json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
        $id=(int)$pdo->lastInsertId();$get=$pdo->prepare('SELECT * FROM profile_events WHERE id=?');$get->execute([$id]);return $get->fetch()?:null;
    }catch(PDOException $e){if((string)$e->getCode()==='23000')return null;throw $e;}
}

function profile_attention_from_event(PDO $pdo,array $event,array $session,?array $agent=null,?int $conversationId=null,string $summary=''): ?array
{
    $owner=(int)$event['owner_user_id'];$label=profile_visitor_label($pdo,$session);$type=(string)$event['event_type'];$agentName=trim((string)($agent['display_name']??''))?:system_agent_name();
    if($type==='profile_view'){$headline=$label.' viewed your profile';$summary=$summary?:'A visitor landed on your public Stonefellow profile.';$assessment='Profile interest detected.';$recommended='No response is required unless their activity becomes more meaningful.';$actions=['open_profile','ignore'];}
    elseif($type==='conversation_started'){$headline=$label.' started a conversation with '.$agentName;$summary=$summary?:'A visitor started talking with your Profile Agent.';$assessment='Direct profile-agent engagement.';$recommended='Review the conversation if you want to participate.';$actions=['open_conversation','ignore'];}
    else{$headline=$agentName.' needs your input';$summary=$summary?:'Your Profile Agent reached a question it cannot answer from approved information.';$assessment='Owner knowledge or approval is required.';$recommended='Answer the question so the visitor can receive an accurate response.';$actions=['reply','snooze','ignore'];}
    try{
        $stmt=$pdo->prepare('INSERT INTO agent_attention_items (owner_user_id,source_event_id,source_conversation_id,profile_agent_id,visitor_user_id,attention_type,priority,headline,summary,assessment,recommended_response,actions_json,context_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$owner,(int)$event['id'],$conversationId,$agent?(int)$agent['id']:null,(int)($event['visitor_user_id']??0)?:null,$type,(int)$event['priority'],$headline,$summary,$assessment,$recommended,json_encode($actions),json_encode(['profile_session_id'=>(int)$session['id'],'identity_disclosed'=>(bool)$session['identity_disclosed']],JSON_UNESCAPED_SLASHES)]);
        $id=(int)$pdo->lastInsertId();
        create_notification($owner,'profile_'.$type,$headline,$summary,url('/account.php#profile-agent'),'profile_event',(int)$event['id']);
        $get=$pdo->prepare('SELECT * FROM agent_attention_items WHERE id=?');$get->execute([$id]);return $get->fetch()?:null;
    }catch(PDOException $e){if((string)$e->getCode()==='23000')return null;throw $e;}
}

function profile_record_view(PDO $pdo,array $profile,?array $visitor): ?array
{
    $owner=(int)$profile['user_id'];if((int)($visitor['id']??0)===$owner)return null;
    $session=profile_visit_session($pdo,$owner,$visitor);
    $bucket=(int)floor(time()/STONEFELLOW_PROFILE_VIEW_DEDUPE_SECONDS);
    $dedupe=hash('sha256',$session['session_key'].'|profile_view|'.$bucket);
    $agent=profile_active_agent($pdo,$profile);
    $event=profile_event_create($pdo,$owner,$session,'profile_view',10,$agent?(int)$agent['id']:null,[],$dedupe);
    if($event)profile_attention_from_event($pdo,$event,$session,$agent);
    return $session;
}

function profile_public_artist_workspace(PDO $pdo,int $userId): ?array
{
    if(!function_exists('artist_workspace_v181_lookup_public')||!artist_workspace_v181_schema_ready($pdo))return null;
    return artist_workspace_v181_lookup_public($pdo,'',$userId);
}

function profile_public_catalog(PDO $pdo,array $profile,?array $viewer): array
{
    $workspace=profile_public_artist_workspace($pdo,(int)$profile['user_id']);
    if(!$workspace)return ['workspace'=>null,'tracks'=>[],'albums'=>[],'shows'=>[],'photos'=>[],'posts'=>[],'merch'=>[]];
    $wid=(int)$workspace['id'];$out=['workspace'=>$workspace];
    foreach(['tracks'=>80,'albums'=>40,'shows'=>80,'photos'=>60,'posts'=>60,'merch'=>60] as $kind=>$limit){
        try{$out[$kind]=artist_workspace_v181_public_records($kind,$viewer,$limit,$wid);}catch(Throwable $e){$out[$kind]=[];}
    }
    return $out;
}

function profile_context_match(string $query,string $text): bool
{
    $terms=chat_policy_terms_v236($query);if(!$terms)return true;$hay=mb_strtolower($text);foreach($terms as $term)if(str_contains($hay,$term))return true;return false;
}

function profile_agent_context(PDO $pdo,array $profile,array $agent,?array $viewer,string $query): array
{
    $owner=(int)$profile['user_id'];$principal=user_agent_principal_v236($viewer,$agent,true);$context=[];
    $identity=trim((string)$profile['display_name']);$bio=trim((string)($profile['bio']??''));
    $context[]=['source'=>'profile:identity','title'=>$identity,'text'=>'This is the public profile for '.$identity.'. '.($bio!==''?'Bio: '.$bio:'').' You are '.(string)$agent['display_name'].', an AI representative powered by '.system_agent_name().'. Clearly identify yourself as an AI representative when relevant. Never imply the profile owner is live.'];
    $rules='Use only information in this approved profile context. Never invent private facts, pricing, availability, contact details, unreleased work, or commitments. If approved context is insufficient, say you do not have that information and request owner input.';
    $instructions=trim((string)($profile['profile_agent_instructions']??''));if($instructions!=='')$rules.=' Owner-authored profile instructions: '.$instructions;
    $context[]=['source'=>'profile:rules','title'=>'Profile Agent boundaries','text'=>$rules];

    $catalog=profile_public_catalog($pdo,$profile,$viewer);$workspace=$catalog['workspace'];
    $defs=['tracks'=>'track','albums'=>'album','shows'=>'show','photos'=>'photo','posts'=>'post','merch'=>'merch'];
    foreach($defs as $kind=>$type){
        foreach($catalog[$kind] as $row){
            $rid=(string)(int)$row['id'];$legacy=$type==='show'?true:((string)($row['visibility']??'public')==='public');
            if(!user_data_policy_can_use_v236($pdo,$principal,$owner,$type,$rid,$legacy))continue;
            if($type==='track'){$title=(string)$row['title'];$text='Song: '.$title.((string)($row['album']??'')!==''?' · Album: '.(string)$row['album']:'');}
            elseif($type==='album'){$title=(string)$row['title'];$text='Album: '.$title.'. Release date: '.(string)($row['release_date']??'').'. '.(string)($row['description']??'');}
            elseif($type==='show'){$title=(string)($row['venue']??'Show');$text='Show: '.(string)($row['show_date']??'').' at '.$title.', '.(string)($row['city']??'').', '.(string)($row['region']??'').'. '.(string)($row['notes']??'').'. Ticket URL: '.(string)($row['ticket_url']??'');}
            elseif($type==='post'){$title=(string)$row['title'];$text='Post: '.$title.'. '.(string)$row['body'];}
            elseif($type==='merch'){$title=(string)$row['title'];$text='Merch: '.$title.'. '.(string)$row['description'].'. Price: $'.number_format(((int)$row['price_cents'])/100,2).'. '.(string)$row['product_url'];}
            else{$title=(string)$row['title'];$text='Photo: '.$title;}
            $forced=(bool)preg_match(match($type){'track'=>'/\b(song|track|music|listen|album)\b/i','album'=>'/\balbum\b/i','show'=>'/\b(show|concert|live|gig|tour)\b/i','post'=>'/\b(post|news|update)\b/i','photo'=>'/\b(photo|picture|image)\b/i','merch'=>'/\b(merch|store|shirt|buy)\b/i',default=>'/$^/'},$query);
            if(!$forced&&!profile_context_match($query,$title.' '.$text))continue;
            $source='profile:'.$type.':'.$rid;$context[]=['source'=>$source,'title'=>$title,'text'=>mb_strimwidth($text,0,4000,'…')];
            if(function_exists('user_data_usage_log_v236'))user_data_usage_log_v236($pdo,$principal,$owner,$type,$rid,$title,$source,0);
            if(count($context)>=16)break 2;
        }
    }

    if(table_exists('knowledge_items')&&table_exists('knowledge_chunks')&&count($context)<18){
        $stmt=$pdo->prepare('SELECT id FROM knowledge_items WHERE created_by_user_id=? AND is_published=1 ORDER BY updated_at DESC,id DESC LIMIT 120');$stmt->execute([$owner]);
        foreach($stmt->fetchAll(PDO::FETCH_COLUMN)?:[] as $kid){
            $item=shared_knowledge_index_item_v236($pdo,(int)$kid);if(!$item)continue;$rid=(string)(int)$kid;$legacy=((string)($item['visibility']??'members')==='public');
            if(!user_data_policy_can_use_v236($pdo,$principal,$owner,'knowledge',$rid,$legacy))continue;
            $text='';foreach((array)($item['chunk_texts']??[]) as $chunk){if(profile_context_match($query,(string)$item['title'].' '.(string)$chunk)){$text=trim((string)$chunk);break;}}
            if($text==='')$text=trim((string)($item['content_text']??''));if($text===''||!profile_context_match($query,(string)$item['title'].' '.$text))continue;
            $source='knowledge:'.(int)$kid;$context[]=['source'=>$source,'title'=>(string)$item['title'],'text'=>mb_strimwidth($text,0,6000,'…')];
            if(function_exists('user_data_usage_log_v236'))user_data_usage_log_v236($pdo,$principal,$owner,'knowledge',$rid,(string)$item['title'],$source,0);
            if(count($context)>=18)break;
        }
    }
    return $context;
}

function profile_agent_conversation_get(PDO $pdo,int $conversationId,int $ownerUserId,int $sessionId): ?array
{
    $stmt=$pdo->prepare('SELECT * FROM profile_agent_conversations WHERE id=? AND owner_user_id=? AND profile_session_id=? LIMIT 1');$stmt->execute([$conversationId,$ownerUserId,$sessionId]);return $stmt->fetch()?:null;
}

function profile_agent_conversation_create(PDO $pdo,array $profile,array $agent,array $session): array
{
    $stmt=$pdo->prepare('INSERT INTO profile_agent_conversations (owner_user_id,profile_agent_id,profile_session_id,visitor_user_id) VALUES (?,?,?,?)');
    $stmt->execute([(int)$profile['user_id'],(int)$agent['id'],(int)$session['id'],(int)($session['visitor_user_id']??0)?:null]);
    $id=(int)$pdo->lastInsertId();
    $event=profile_event_create($pdo,(int)$profile['user_id'],$session,'conversation_started',60,(int)$agent['id'],['conversation_id'=>$id],hash('sha256','conversation_started|'.$id));
    if($event)profile_attention_from_event($pdo,$event,$session,$agent,$id);
    return profile_agent_conversation_get($pdo,$id,(int)$profile['user_id'],(int)$session['id'])?:throw new RuntimeException('Conversation could not be started.');
}

function profile_agent_messages(PDO $pdo,int $conversationId,int $limit=120): array
{
    $limit=max(1,min(200,$limit));$stmt=$pdo->prepare('SELECT id,sender_type,message,created_at FROM profile_agent_messages WHERE conversation_id=? ORDER BY id DESC LIMIT '.$limit);$stmt->execute([$conversationId]);return array_reverse($stmt->fetchAll()?:[]);
}

function profile_agent_rate_check(PDO $pdo,int $conversationId): void
{
    $stmt=$pdo->prepare("SELECT COUNT(*) total,MAX(created_at) last_at FROM profile_agent_messages WHERE conversation_id=? AND sender_type='visitor' AND created_at>=DATE_SUB(NOW(),INTERVAL 1 MINUTE)");$stmt->execute([$conversationId]);$row=$stmt->fetch()?:[];
    if((int)($row['total']??0)>=8)throw new RuntimeException('Please wait a moment before sending more messages.');
    if(!empty($row['last_at'])&&time()-strtotime((string)$row['last_at'])<2)throw new RuntimeException('Please wait a moment before sending another message.');
}

function profile_agent_needs_owner(PDO $pdo,array $profile,array $agent,array $session,array $conversation,string $question): void
{
    $summary='Visitor asked: “'.mb_strimwidth(trim($question),0,420,'…').'”';
    $event=profile_event_create($pdo,(int)$profile['user_id'],$session,'needs_owner',90,(int)$agent['id'],['conversation_id'=>(int)$conversation['id']],hash('sha256','needs_owner|'.(int)$conversation['id'].'|'.sha1(mb_strtolower(trim($question)))));
    if($event)profile_attention_from_event($pdo,$event,$session,$agent,(int)$conversation['id'],$summary);
}

function profile_attention_list(PDO $pdo,int $ownerUserId,int $limit=20): array
{
    $limit=max(1,min(50,$limit));
    $stmt=$pdo->prepare("SELECT a.*,s.identity_disclosed,s.profile_session_id,c.status conversation_status,c.last_message_at conversation_last_message FROM agent_attention_items a LEFT JOIN profile_agent_conversations c ON c.id=a.source_conversation_id LEFT JOIN profile_events e ON e.id=a.source_event_id LEFT JOIN profile_visit_sessions s ON s.id=e.profile_session_id WHERE a.owner_user_id=? AND a.status IN ('pending','seen','snoozed') AND (a.snoozed_until IS NULL OR a.snoozed_until<=NOW()) ORDER BY a.priority DESC,a.created_at DESC,a.id DESC LIMIT ".$limit);
    $stmt->execute([$ownerUserId]);$rows=$stmt->fetchAll()?:[];
    foreach($rows as &$row){$row['visitor_label']='Someone';if(!empty($row['identity_disclosed'])&&(int)($row['visitor_user_id']??0)>0){$u=profile_user_row($pdo,(int)$row['visitor_user_id']);if($u)$row['visitor_label']=(string)$u['display_name'];}$row['actions']=json_decode((string)($row['actions_json']??'[]'),true)?:[];unset($row['actions_json'],$row['context_json']);}unset($row);
    return $rows;
}

function profile_attention_update(PDO $pdo,int $ownerUserId,int $attentionId,string $action): void
{
    $allowed=['seen','handled','ignored','snooze'];if(!in_array($action,$allowed,true))throw new RuntimeException('Unknown attention action.');
    if($action==='snooze'){$stmt=$pdo->prepare("UPDATE agent_attention_items SET status='snoozed',snoozed_until=DATE_ADD(NOW(),INTERVAL 1 DAY) WHERE id=? AND owner_user_id=?");$stmt->execute([$attentionId,$ownerUserId]);return;}
    $status=$action==='seen'?'seen':($action==='handled'?'handled':'ignored');$stmt=$pdo->prepare('UPDATE agent_attention_items SET status=?,snoozed_until=NULL WHERE id=? AND owner_user_id=?');$stmt->execute([$status,$attentionId,$ownerUserId]);
}

function profile_owner_dashboard_state(PDO $pdo,array $user): array
{
    $uid=(int)$user['id'];$profile=profile_for_user($pdo,$uid,true)?:[];$agents=user_agents_list_v236($pdo,$uid,true);
    $visits=$pdo->prepare('SELECT s.id,s.visitor_user_id,s.identity_disclosed,s.view_count,s.first_seen_at,s.last_seen_at FROM profile_visit_sessions s WHERE s.owner_user_id=? ORDER BY s.last_seen_at DESC,s.id DESC LIMIT 20');$visits->execute([$uid]);$visitRows=$visits->fetchAll()?:[];
    foreach($visitRows as &$v){$v['visitor_label']=profile_visitor_label($pdo,$v);unset($v['visitor_user_id']);}unset($v);
    $convos=$pdo->prepare('SELECT c.id,c.profile_agent_id,c.status,c.last_summary,c.started_at,c.last_message_at,s.identity_disclosed,s.visitor_user_id FROM profile_agent_conversations c INNER JOIN profile_visit_sessions s ON s.id=c.profile_session_id WHERE c.owner_user_id=? ORDER BY c.last_message_at DESC,c.id DESC LIMIT 30');$convos->execute([$uid]);$conversationRows=$convos->fetchAll()?:[];
    foreach($conversationRows as &$c){$c['visitor_label']=profile_visitor_label($pdo,$c);unset($c['visitor_user_id']);}unset($c);
    $policies=[];foreach(user_agent_resource_catalog_v236() as $type=>$meta){$p=user_data_policy_get_v236($pdo,$uid,$type);$policies[$type]=['label'=>$meta['label'],'profile_agent_allowed'=>(bool)$p['profile_agent_allowed'],'audience_scope'=>(string)$p['audience_scope']];}
    return ['build'=>STONEFELLOW_PROFILE_AGENT_BUILD,'namespace'=>STONEFELLOW_PROFILE_NAMESPACE,'system_agent_name'=>system_agent_name(),'profile'=>$profile,'profile_url'=>!empty($profile['username'])?profile_public_url((string)$profile['username']):'','agents'=>$agents,'visits'=>$visitRows,'conversations'=>$conversationRows,'attention'=>profile_attention_list($pdo,$uid,20),'policies'=>$policies];
}
