<?php
declare(strict_types=1);

function chat_policy_terms_v236(string $query): array
{
    $parts=preg_split('/[^\pL\pN]+/u',mb_strtolower(trim($query)))?:[];
    return array_slice(array_values(array_unique(array_filter($parts,static fn(string $t):bool=>mb_strlen($t)>=2))),0,10);
}

function chat_policy_matches_v236(string $text,array $terms): bool
{
    if(!$terms)return true;
    $hay=mb_strtolower($text);
    foreach($terms as $term)if(str_contains($hay,$term))return true;
    return false;
}

function chat_policy_can_use_v236(PDO $pdo,array $principal,int $owner,string $type,string $rid,bool $legacyAllowed): bool
{
    if($owner<1)return true;
    $kind=(string)($principal['kind']??'system');
    $viewer=(int)($principal['viewer_user_id']??0);
    $principalOwner=(int)($principal['owner_user_id']??0);
    // Owner-session access does not publish data. Cross-user/Profile Agent use
    // still flows through the explicit user-data policy below.
    if($kind==='user_agent'&&$viewer>0&&$viewer===$owner&&$principalOwner===$owner)return true;
    if($kind==='system'&&$viewer>0&&$viewer===$owner)return true;
    return user_data_policy_can_use_v236($pdo,$principal,$owner,$type,$rid,$legacyAllowed);
}

function chat_policy_add_v236(array &$context,PDO $pdo,array $principal,int $owner,string $type,string $rid,bool $legacyAllowed,string $source,string $title,string $text,array $terms,bool $force=false,int $conversationId=0): void
{
    if(!$force&&!chat_policy_matches_v236($title.' '.$text,$terms))return;
    if(!chat_policy_can_use_v236($pdo,$principal,$owner,$type,$rid,$legacyAllowed))return;
    $context[]=['source'=>$source,'title'=>$title,'text'=>$text];
    if($owner>0&&function_exists('user_data_usage_log_v236'))user_data_usage_log_v236($pdo,$principal,$owner,$type,$rid,$title,$source,$conversationId);
}

function chat_policy_workspace_context_v236(PDO $pdo,array $principal,array $user,string $query,array $terms,int $conversationId=0): array
{
    $context=[];$queryLower=mb_strtolower($query);
    if(!artist_workspace_v181_schema_ready($pdo))return $context;
    $tables=[
      'track'=>['artist_catalog_tracks_v181','title,album,visibility,is_published,workspace_id,id','updated_at DESC,id DESC'],
      'album'=>['artist_catalog_albums_v181','title,release_date,description,visibility,is_published,workspace_id,id','updated_at DESC,id DESC'],
      'show'=>['artist_catalog_shows_v181','show_date,venue,city,region,notes,ticket_url,is_published,workspace_id,id','show_date ASC,id ASC'],
      'photo'=>['artist_catalog_photos_v181','title,visibility,is_published,workspace_id,id','updated_at DESC,id DESC'],
      'post'=>['artist_posts_v181','title,body,post_type,visibility,is_published,workspace_id,id','updated_at DESC,id DESC'],
      'merch'=>['artist_catalog_merch_v181','title,description,price_cents,product_url,visibility,is_published,workspace_id,id','updated_at DESC,id DESC'],
    ];
    foreach($tables as $type=>[$table,$columns,$order]){
      try{$rows=$pdo->query("SELECT {$columns} FROM {$table} WHERE is_published=1 ORDER BY {$order} LIMIT 180")->fetchAll()?:[];}catch(Throwable $e){continue;}
      foreach($rows as $row){
        $owner=user_data_owner_workspace_v236($pdo,(int)$row['workspace_id']);
        $legacy=$type==='show'?true:can_view_visibility((string)($row['visibility']??'public'),$user);
        $rid=(string)$row['id'];$title='';$text='';$force=false;
        if($type==='track'){$title=(string)$row['title'];$text='Song: '.$title.((string)$row['album']!==''?' · Album: '.$row['album']:'');$force=track_is_music_request($query);}
        elseif($type==='album'){$title=(string)$row['title'];$text='Album: '.$title.'. Release date: '.(string)($row['release_date']??'').'. '.(string)($row['description']??'');$force=str_contains($queryLower,'album');}
        elseif($type==='show'){$title=(string)$row['venue'];$text='Show: '.(string)$row['show_date'].' at '.(string)$row['venue'].', '.(string)$row['city'].', '.(string)$row['region'].'. '.(string)$row['notes'].'. Ticket URL: '.(string)$row['ticket_url'];$force=(bool)preg_match('/\b(show|concert|live|gig|tour)\b/i',$query);}
        elseif($type==='photo'){$title=(string)$row['title'];$text='Photo: '.$title;$force=str_contains($queryLower,'photo');}
        elseif($type==='post'){$title=(string)$row['title'];$text='Post: '.$title.'. '.(string)$row['body'];$force=(bool)preg_match('/\b(post|update|news)\b/i',$query);}
        else{$title=(string)$row['title'];$text='Merch: '.$title.'. '.(string)$row['description'].'. Price: $'.number_format(((int)$row['price_cents'])/100,2).'. '.(string)$row['product_url'];$force=(bool)preg_match('/\b(merch|shirt|store|buy)\b/i',$query);}
        chat_policy_add_v236($context,$pdo,$principal,$owner,$type,$rid,$legacy,'artist:'.$type.':'.$rid,$title,$text,$terms,$force,$conversationId);
        if(count($context)>=18)break 2;
      }
    }
    return $context;
}

function chat_policy_legacy_context_v236(PDO $pdo,array $principal,array $user,string $query,array $terms,int $conversationId=0): array
{
    $context=[];$queryLower=mb_strtolower($query);
    if(table_exists('tracks')){
      try{$rows=$pdo->query('SELECT id,title,album,duration,lyrics,description,genre,mood,energy,tempo_bpm,keywords,visibility,is_published,owner_user_id FROM tracks WHERE is_published=1 ORDER BY updated_at DESC,id DESC LIMIT 220')->fetchAll()?:[];}catch(Throwable $e){$rows=[];}
      foreach($rows as $row){
        $owner=(int)($row['owner_user_id']??0);$legacy=can_view_track($row,$user);$text=chat_track_summary_text($row,$query);
        chat_policy_add_v236($context,$pdo,$principal,$owner,'track',(string)$row['id'],$legacy,'database:track:'.(int)$row['id'],(string)$row['title'],$text,$terms,track_is_music_request($query),$conversationId);
        if(count($context)>=12)break;
      }
    }
    $defs=[
      'album'=>['albums','id,title,release_date,description,visibility,is_published,created_by_user_id','title','created_by_user_id'],
      'show'=>['shows','id,show_date,venue,city,region,notes,ticket_url,is_published,owner_user_id','venue','owner_user_id'],
      'photo'=>['photos','id,title,caption,visibility,is_published,created_by_user_id','title','created_by_user_id'],
      'post'=>['artist_posts','id,title,body,visibility,is_published,created_by_user_id','title','created_by_user_id'],
      'merch'=>['merch_items','id,title,description,price_cents,product_url,visibility,is_published,created_by_user_id','title','created_by_user_id'],
    ];
    foreach($defs as $type=>[$table,$cols,$titleCol,$ownerCol]){
      if(!table_exists($table))continue;
      try{$rows=$pdo->query("SELECT {$cols} FROM {$table} WHERE is_published=1 ORDER BY id DESC LIMIT 120")->fetchAll()?:[];}catch(Throwable $e){continue;}
      foreach($rows as $r){
        $owner=(int)($r[$ownerCol]??0);$legacy=$type==='show'?true:can_view_visibility((string)($r['visibility']??'public'),$user);$title=(string)($r[$titleCol]??ucfirst($type));
        if($type==='album')$text='Album: '.$title.'. Release date: '.(string)$r['release_date'].'. '.(string)$r['description'];
        elseif($type==='show')$text='Show: '.(string)$r['show_date'].' at '.$title.', '.(string)$r['city'].', '.(string)$r['region'].'. '.(string)$r['notes'].'. Ticket URL: '.(string)$r['ticket_url'];
        elseif($type==='photo')$text='Photo: '.$title.'. '.(string)($r['caption']??'');
        elseif($type==='post')$text='Post: '.$title.'. '.(string)$r['body'];
        else$text='Merch: '.$title.'. '.(string)$r['description'].'. Price: $'.number_format(((int)$r['price_cents'])/100,2).'. '.(string)$r['product_url'];
        $force=match($type){'album'=>str_contains($queryLower,'album'),'show'=>(bool)preg_match('/\b(show|concert|live|gig|tour)\b/i',$query),'photo'=>str_contains($queryLower,'photo'),'post'=>(bool)preg_match('/\b(post|update|news)\b/i',$query),'merch'=>(bool)preg_match('/\b(merch|store|buy)\b/i',$query),default=>false};
        chat_policy_add_v236($context,$pdo,$principal,$owner,$type,(string)$r['id'],$legacy,'database:'.$type.':'.(int)$r['id'],$title,$text,$terms,$force,$conversationId);
        if(count($context)>=20)break 2;
      }
    }
    return $context;
}

function chat_policy_knowledge_text_v242(array $item,array $terms): string
{
    foreach((array)($item['chunk_texts']??[]) as $chunk){
        if(chat_policy_matches_v236((string)$item['title'].' '.(string)$chunk,$terms))return trim((string)$chunk);
    }
    $text=trim((string)($item['content_text']??''));
    if($text==='')$text=trim((string)($item['description']??''));
    if($text===''||!chat_policy_matches_v236((string)$item['title'].' '.$text,$terms))return '';
    return $text;
}

function chat_policy_append_personal_knowledge_v242(array &$context,PDO $pdo,array $principal,array $user,array $item,array $terms,int $conversationId,array &$seenIds): void
{
    $id=(int)($item['id']??0);if($id<1||isset($seenIds[$id])||count($context)>=8)return;
    if((string)($item['knowledge_scope']??'system')!=='personal')return;
    $owner=(int)($item['created_by_user_id']??0);if($owner<1)return;
    $legacy=false;
    if(!chat_policy_can_use_v236($pdo,$principal,$owner,'knowledge',(string)$id,$legacy))return;
    $text=chat_policy_knowledge_text_v242($item,$terms);if($text==='')return;
    $source='knowledge:'.$id;$context[]=['source'=>$source,'title'=>(string)$item['title'],'text'=>mb_strimwidth($text,0,7000,'…')];$seenIds[$id]=true;
    if(function_exists('user_data_usage_log_v236'))user_data_usage_log_v236($pdo,$principal,$owner,'knowledge',(string)$id,(string)$item['title'],$source,$conversationId);
}

function chat_policy_personal_knowledge_v242(PDO $pdo,array $principal,array $user,array $terms,int $conversationId=0): array
{
    if(!table_exists('knowledge_items')||!column_exists('knowledge_items','knowledge_scope'))return [];
    $viewer=(int)($user['id']??0);$kind=(string)($principal['kind']??'system');$owner=0;
    if($kind==='system')$owner=$viewer;
    elseif(in_array($kind,['user_agent','profile_agent'],true))$owner=(int)($principal['owner_user_id']??0);
    if($owner<1)return [];

    $ownerUser=profile_user_row($pdo,$owner);
    if(!$ownerUser||!personal_capability_has_v242('personal_knowledge.access',$ownerUser))return [];

    $context=[];$seen=[];
    $stmt=$pdo->prepare("SELECT id FROM knowledge_items WHERE created_by_user_id=? AND knowledge_scope='personal' ORDER BY updated_at DESC,id DESC LIMIT 160");
    $stmt->execute([$owner]);
    foreach($stmt->fetchAll(PDO::FETCH_COLUMN)?:[] as $knowledgeId){
        $item=shared_knowledge_index_item_v236($pdo,(int)$knowledgeId);
        if($item)chat_policy_append_personal_knowledge_v242($context,$pdo,$principal,$user,$item,$terms,$conversationId,$seen);
        if(count($context)>=8)break;
    }
    return $context;
}

function chat_policy_shared_personal_knowledge_v242(PDO $pdo,array $principal,array $user,string $query,array $terms,int $conversationId=0,array $already=[]): array
{
    if(!has_permission('knowledge.access',$user)||!shared_knowledge_index_schema_ready_v236($pdo))return [];
    $context=[];$seen=array_fill_keys(array_map('intval',$already),true);$directOwner=(int)($principal['owner_user_id']??0);
    if(($principal['kind']??'system')==='system')$directOwner=(int)($user['id']??0);
    foreach(shared_knowledge_index_candidates_v236($pdo,$query,60) as $candidate){
        $id=(int)$candidate['knowledge_id'];$owner=(int)$candidate['owner_user_id'];
        if(isset($seen[$id])||($directOwner>0&&$owner===$directOwner))continue;
        $item=shared_knowledge_index_item_v236($pdo,$id);if(!$item)continue;
        $currentHash=shared_knowledge_index_hash_v236($item);
        if(!hash_equals((string)$candidate['source_version_hash'],$currentHash)){
            shared_knowledge_index_sync_item_v236($pdo,$id);
            $policy=user_data_policy_get_v236($pdo,$owner,'knowledge',(string)$id);
            if(empty($policy['stonefellow_shared']))continue;
        }
        chat_policy_append_personal_knowledge_v242($context,$pdo,$principal,$user,$item,$terms,$conversationId,$seen);
        if(count($context)>=8)break;
    }
    return $context;
}

function chat_policy_system_knowledge_v242(PDO $pdo,array $user,array $terms): array
{
    if(!has_permission('knowledge.access',$user)||!table_exists('knowledge_items')||!column_exists('knowledge_items','knowledge_scope'))return [];
    $context=[];$stmt=$pdo->query("SELECT id FROM knowledge_items WHERE knowledge_scope='system' AND is_published=1 ORDER BY updated_at DESC,id DESC LIMIT 180");
    foreach($stmt->fetchAll(PDO::FETCH_COLUMN)?:[] as $knowledgeId){
        $item=shared_knowledge_index_item_v236($pdo,(int)$knowledgeId);if(!$item)continue;
        if(!can_view_visibility((string)($item['visibility']??'members'),$user))continue;
        $text=chat_policy_knowledge_text_v242($item,$terms);if($text==='')continue;
        $context[]=['source'=>'knowledge:'.(int)$item['id'],'title'=>(string)$item['title'],'text'=>mb_strimwidth($text,0,7000,'…')];
        if(count($context)>=8)break;
    }
    return $context;
}

// Backward-compatible function name used by the current Chat policy pipeline.
function chat_policy_knowledge_v236(PDO $pdo,array $principal,array $user,string $query,array $terms,int $conversationId=0): array
{
    $context=chat_policy_personal_knowledge_v242($pdo,$principal,$user,$terms,$conversationId);
    foreach(chat_policy_system_knowledge_v242($pdo,$user,$terms) as $item){if(count($context)>=12)break;$context[]=$item;}
    $ids=[];foreach($context as $item){$source=(string)($item['source']??'');if(str_starts_with($source,'knowledge:'))$ids[]=(int)substr($source,10);}
    foreach(chat_policy_shared_personal_knowledge_v242($pdo,$principal,$user,$query,$terms,$conversationId,$ids) as $item){if(count($context)>=12)break;$context[]=$item;}
    return $context;
}

function chat_policy_profile_activity_v236(PDO $pdo,array $user,string $query): array
{
    if(!personal_capability_has_v242('profile_agent.access',$user))return [];
    if(!function_exists('profile_agent_schema_ready')||!profile_agent_schema_ready($pdo)||!function_exists('profile_runtime_visitor_descriptor'))return [];
    if(!preg_match('/\b(profile(?:\s+agent)?|visitor|visitors|visited|visiting|profile\s+activity|on\s+my\s+profile|who(?:\x27s|\s+is)\s+on|asked\s+my\s+agent|profile\s+conversation)\b/i',$query))return [];
    $uid=(int)($user['id']??0);if($uid<1)return [];
    $lines=[];
    $visits=$pdo->prepare('SELECT id,visitor_user_id,identity_disclosed,view_count,first_seen_at,last_seen_at,last_message_at FROM profile_visit_sessions WHERE owner_user_id=? AND view_count>0 ORDER BY last_seen_at DESC,id DESC LIMIT 12');
    $visits->execute([$uid]);
    foreach($visits->fetchAll()?:[] as $row){
        $d=profile_runtime_visitor_descriptor($pdo,$uid,$row);$active=!empty($row['last_seen_at'])&&strtotime((string)$row['last_seen_at'])>=time()-300;
        $identity=(string)$d['visitor_label'];if(!empty($d['username']))$identity.=' @'.(string)$d['username'];if(($d['relationship_scope']??'none')!=='none')$identity.=' · '.(string)$d['relationship_scope'];
        $lines[]=$identity.' · '.((bool)$d['signed_in']?'signed in':'guest').' · '.($active?'active now':'last seen '.(string)$row['last_seen_at']).' · '.(int)$row['view_count'].' profile view'.((int)$row['view_count']===1?'':'s').(!empty($row['last_message_at'])?' · has chatted':'');
    }
    $conversations=$pdo->prepare('SELECT c.id,c.status,c.last_summary,c.last_message_at,s.identity_disclosed,s.visitor_user_id FROM profile_agent_conversations c INNER JOIN profile_visit_sessions s ON s.id=c.profile_session_id WHERE c.owner_user_id=? ORDER BY c.last_message_at DESC,c.id DESC LIMIT 10');
    $conversations->execute([$uid]);$conversationLines=[];
    foreach($conversations->fetchAll()?:[] as $row){$d=profile_runtime_visitor_descriptor($pdo,$uid,$row);$conversationLines[]='#'.(int)$row['id'].' · '.(string)$d['visitor_label'].' · '.(string)$row['status'].' · '.(string)$row['last_summary'].' · last message '.(string)$row['last_message_at'];}
    $text="Current owner-only Profile Agent activity. Identity is included only where the visitor explicitly disclosed it.\nVisitors:\n".($lines?implode("\n",$lines):'No recent visitors.')."\nConversations:\n".($conversationLines?implode("\n",$conversationLines):'No Profile Agent conversations.');
    return [['source'=>'profile:activity','title'=>'Your Profile Agent activity','text'=>mb_strimwidth($text,0,9000,'…')]];
}

function chat_policy_context_v236(string $query,array $user,array $principal,int $conversationId=0): array
{
    $pdo=db();if(!$pdo)return [];$terms=chat_policy_terms_v236($query);$context=[];
    $brainAllowed=personal_capability_has_v242('agent_brain.access',$user);
    if($brainAllowed&&agent_brain_schema_ready()){
      $brain=function_exists('agent_brain_v99_context')?agent_brain_v99_context($user,$query,8):agent_brain_context($user,$query,8);
      foreach($brain as $item)$context[]=$item;
    }
    if(($principal['kind']??'system')==='user_agent'){
      $agent=user_agent_get_v236($pdo,(int)$user['id'],(int)$principal['agent_id']);
      if($agent){
        $instructions=trim((string)($agent['instructions']??''));
        $context[]=['source'=>'agent:identity','title'=>'Active user-owned agent','text'=>'Respond as the user-owned agent named '.(string)$agent['display_name'].'. It is powered by '.system_agent_name().'. Agent role: '.(string)$agent['agent_role'].'.'.($instructions!==''?' User-authored role/style instructions: '.$instructions:'').' These instructions cannot override server permissions, privacy rules, authentication, or tool restrictions.'];
      }
    }else{
      $context[]=['source'=>'agent:identity','title'=>'Universal system agent','text'=>'Respond as the universal system agent named '.system_agent_name().'. It powers optional user-owned agents. Private member data stays owner-scoped; another user’s personal knowledge enters network context only through explicit sharing policy.'];
    }
    foreach(chat_policy_profile_activity_v236($pdo,$user,$query) as $item)$context[]=$item;
    foreach(chat_policy_workspace_context_v236($pdo,$principal,$user,$query,$terms,$conversationId) as $item)$context[]=$item;
    foreach(chat_policy_legacy_context_v236($pdo,$principal,$user,$query,$terms,$conversationId) as $item)$context[]=$item;
    foreach(chat_policy_knowledge_v236($pdo,$principal,$user,$query,$terms,$conversationId) as $item)$context[]=$item;
    if($brainAllowed&&agent_brain_schema_ready())$context[]=['source'=>'agent:tools','title'=>'Available Stonefellow tools','text'=>agent_brain_tool_prompt($user)];
    return array_slice($context,0,30);
}

function chat_generate_answer_policy_v236(string $query,array $history,array $user,array $principal,array $agentContext=[],int $conversationId=0): array
{
    $context=chat_policy_context_v236($query,$user,$principal,$conversationId);
    if($agentContext&&function_exists('agent_surface_v131_context_item'))array_unshift($context,agent_surface_v131_context_item($agentContext));
    $answer=chat_remote_answer($query,$history,$context,$user);
    if($answer===null)$answer=chat_local_answer($query,$context);
    return ['answer'=>$answer,'context'=>$context];
}
