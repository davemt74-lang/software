<?php
declare(strict_types=1);

/**
 * VP3 Cognitive Runtime v24.20 — Unified Context Assembly & Working Memory.
 *
 * Working memory is an ephemeral, bounded projection over existing VP3
 * authorities. It creates no second Brain, memory store, event ledger,
 * notification queue, or execution authority.
 */
const VP3_COGNITIVE_CONTEXT_V2420='vp3-cognitive-context-v2420-20260922';
const VP3_COGNITIVE_CONTEXT_CONTRACT_V2420='cognitive-context-v2';
const VP3_COGNITIVE_CONTEXT_MAX_ITEMS_V2420=28;
const VP3_COGNITIVE_CONTEXT_MAX_ITEM_CHARS_V2420=1800;
const VP3_COGNITIVE_CONTEXT_MAX_TEXT_BYTES_V2420=48000;
const VP3_COGNITIVE_CONTEXT_MAX_PACKET_BYTES_V2420=65536;

function vp3_cognitive_context_section_order_v2420(): array
{
    return [
        'live_session','conversation','current_priorities','active_objects',
        'attention','episodic_memory','durable_memory','domain','knowledge',
        'capabilities','external',
    ];
}

function vp3_cognitive_context_section_limit_v2420(string $section): int
{
    return match($section){
        'live_session'=>1,
        'conversation'=>3,
        'current_priorities'=>5,
        'active_objects'=>5,
        'attention'=>2,
        'episodic_memory'=>5,
        'durable_memory'=>8,
        'domain'=>10,
        'knowledge'=>8,
        'capabilities'=>2,
        default=>6,
    };
}

function vp3_cognitive_context_section_weight_v2420(string $section,bool $historyIntent=false): float
{
    if($historyIntent){
        return match($section){
            'conversation'=>98.0,'durable_memory'=>96.0,'episodic_memory'=>92.0,
            'live_session'=>88.0,'current_priorities'=>86.0,'active_objects'=>84.0,
            'attention'=>80.0,'knowledge'=>74.0,'domain'=>72.0,'capabilities'=>50.0,
            default=>48.0,
        };
    }
    return match($section){
        'live_session'=>96.0,'current_priorities'=>92.0,'conversation'=>90.0,
        'active_objects'=>88.0,'attention'=>84.0,'episodic_memory'=>82.0,
        'durable_memory'=>80.0,'domain'=>76.0,'knowledge'=>74.0,
        'capabilities'=>54.0,default=>50.0,
    };
}

function vp3_cognitive_context_terms_v2420(string $query): array
{
    $stop=array_flip([
        'the','and','for','with','from','that','this','what','when','where','which',
        'who','why','how','have','has','had','been','being','was','were','are','is',
        'am','did','does','do','my','me','i','you','your','our','we','a','an','to',
        'of','in','on','at','it','its','about','show','tell','give','please',
    ]);
    $parts=preg_split('/[^\pL\pN._-]+/u',mb_strtolower($query))?:[];
    return array_slice(array_values(array_unique(array_filter(
        $parts,
        static fn(string $x): bool => mb_strlen($x)>=3&&!isset($stop[$x])
    ))),0,16);
}

function vp3_cognitive_context_namespace_v2420(PDO $pdo,array $user,string $requested=''): string
{
    if(trim($requested)!=='')return vp3_cognitive_validate_namespace_v500($pdo,$user,$requested);
    $agentId=0;
    if(function_exists('vp3_agent_memory_scope_current_v410')){
        try{$agentId=max(0,(int)vp3_agent_memory_scope_current_v410($user));}catch(Throwable $e){$agentId=0;}
    }
    return vp3_cognitive_agent_namespace_v500($pdo,$user,$agentId);
}

function vp3_cognitive_context_legacy_section_v2420(string $source): string
{
    $source=strtolower(trim($source));
    if(str_starts_with($source,'agent-brain:conversation-')||str_starts_with($source,'agent-brain:rolling-'))return 'conversation';
    if(str_starts_with($source,'agent-brain:'))return 'durable_memory';
    if(str_starts_with($source,'knowledge:')||$source==='knowledge-v162')return 'knowledge';
    if(str_starts_with($source,'database:')||str_starts_with($source,'artist-profile:')||str_starts_with($source,'profile:'))return 'domain';
    if($source==='agent:tools'||str_starts_with($source,'plugin:')||str_starts_with($source,'capability:'))return 'capabilities';
    if(str_starts_with($source,'agent-context:')||str_starts_with($source,'runtime:'))return 'live_session';
    return 'external';
}

function vp3_cognitive_context_authority_v2420(string $section,string $source): string
{
    return match($section){
        'live_session'=>'agent_live_sessions_v2370',
        'current_priorities'=>'agent_cognitive_loop_v310',
        'active_objects'=>'cognitive_runtime_v500_authorized_refs',
        'attention'=>'cognitive_attention_v2410',
        'episodic_memory'=>'cognitive_memory_v570_reference_layer',
        'durable_memory'=>'agent_memory_items',
        'conversation'=>'agent_chat_archive_and_conversation_state',
        'knowledge'=>'authorized_knowledge_search',
        'capabilities'=>'server_authorized_tool_catalog',
        'domain'=>$source!==''?$source:'canonical_domain_record',
        default=>$source!==''?$source:'retrieved_context',
    };
}

function vp3_cognitive_context_item_v2420(
    string $section,string $source,string $title,string $text,float $score,array $meta=[]
): ?array {
    $source=vp3_cognitive_text_v500($source,160);
    $title=vp3_cognitive_text_v500($title,240);
    $text=vp3_cognitive_text_v500($text,VP3_COGNITIVE_CONTEXT_MAX_ITEM_CHARS_V2420);
    if($text==='')return null;
    return [
        'source'=>$source!==''?$source:'cognitive-context:v2420',
        'title'=>$title!==''?$title:'Working context',
        'text'=>$text,
        'section'=>$section,
        'authority'=>vp3_cognitive_context_authority_v2420($section,$source),
        'trust'=>'data_only',
        'instruction_authority'=>false,
        'score'=>round(max(0.0,min(120.0,$score)),3),
        'meta'=>vp3_cognitive_sanitize_value_v500($meta),
    ];
}

function vp3_cognitive_context_score_v2420(array $item,array $terms,bool $historyIntent=false): float
{
    $section=(string)($item['section']??'external');
    $score=vp3_cognitive_context_section_weight_v2420($section,$historyIntent);
    $haystack=mb_strtolower(implode(' ',[
        (string)($item['source']??''),(string)($item['title']??''),(string)($item['text']??'')
    ]));
    foreach($terms as $term)if($term!==''&&str_contains($haystack,$term))$score+=4.5;
    if(!empty($item['meta']['direct']))$score+=8.0;
    if(!empty($item['meta']['attention']))$score+=6.0;
    return min(120.0,$score);
}

function vp3_cognitive_context_live_items_v2420(PDO $pdo,array $user,string $namespace): array
{
    if(!function_exists('vp3_live_session_snapshot_v2370'))return [];
    try{$snapshot=vp3_live_session_snapshot_v2370($pdo,$user,false);}catch(Throwable $e){return [];}
    $session=is_array($snapshot['session']??null)?$snapshot['session']:null;
    if(!$session)return [];
    $sessionNamespace=trim((string)($session['agent_namespace']??'system'))?:'system';
    if(!hash_equals($namespace,$sessionNamespace))return [];

    $safe=[
        'status'=>(string)($session['status']??''),
        'surface'=>(string)($session['current_surface']??''),
        'context_key'=>(string)($session['current_context_key']??''),
        'conversation_id'=>max(0,(int)($session['current_conversation_id']??0)),
        'project_ref'=>(string)($session['current_project_ref']??''),
        'task_ref'=>(string)($session['current_task_ref']??''),
        'goal_ref'=>(string)($session['current_goal_ref']??''),
        'last_user_action'=>vp3_cognitive_text_v500($session['last_user_action']??'',240),
        'last_agent_action'=>vp3_cognitive_text_v500($session['last_agent_action']??'',240),
        'last_tool_action'=>vp3_cognitive_text_v500($session['last_tool_action']??'',240),
        'last_browser_action'=>vp3_cognitive_text_v500($session['last_browser_action']??'',240),
        'last_external_event'=>vp3_cognitive_text_v500($session['last_external_event']??'',240),
        'last_activity_at'=>(string)($session['last_activity_at']??''),
    ];
    $json=json_encode($safe,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    $item=vp3_cognitive_context_item_v2420(
        'live_session','cognitive-context:v2420:live-session','Current live session',
        'Current authorized live-session projection: '.(is_string($json)?$json:'{}'),96.0,
        ['ephemeral'=>true,'raw_event_payloads_exposed'=>false]
    );
    return $item?[$item]:[];
}

function vp3_cognitive_context_priority_items_v2420(array $user,string $namespace): array
{
    if(!function_exists('agent_cognitive_loop_v310_state'))return [];
    // v3.10 priorities are currently owner/system Brain priorities. Do not widen
    // them into a named Agent scope until that priority producer carries an
    // explicit Agent namespace.
    if($namespace!=='system')return [];
    try{$state=agent_cognitive_loop_v310_state($user);}catch(Throwable $e){return [];}
    if(function_exists('agent_cognitive_loop_v310_state_fresh')&&!agent_cognitive_loop_v310_state_fresh($state))return [];
    $out=[];
    foreach(array_slice((array)($state['priorities']??[]),0,6) as $row){
        if(!is_array($row))continue;
        $title=vp3_cognitive_text_v500($row['title']??'',180);if($title==='')continue;
        $reason=vp3_cognitive_text_v500($row['reason']??'',500);
        $text=$title.($reason!==''?' — '.$reason:'');
        $item=vp3_cognitive_context_item_v2420(
            'current_priorities','cognitive-context:v2420:priority',$title,$text,
            92.0+max(0.0,min(1.0,(float)($row['score']??0)))*8.0,
            ['key'=>vp3_cognitive_text_v500($row['key']??'',160),'attention'=>!empty($row['requires_approval'])]
        );
        if($item)$out[]=$item;
    }
    return $out;
}

function vp3_cognitive_context_attention_items_v2420(PDO $pdo,array $user,string $namespace): array
{
    if(!function_exists('vp3_cognitive_attention_status_v2410'))return [];
    try{$status=vp3_cognitive_attention_status_v2410($pdo,$user,$namespace);}catch(Throwable $e){return [];}
    if(empty($status['ready']))return [];
    $safe=[
        'budget'=>$status['budget']??[],
        'counts_24h'=>$status['counts_24h']??[],
    ];
    $json=json_encode(vp3_cognitive_sanitize_value_v500($safe),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    $item=vp3_cognitive_context_item_v2420(
        'attention','cognitive-context:v2420:attention','Current attention state',
        'Current attention-policy metadata: '.(is_string($json)?$json:'{}'),84.0,
        ['content_copied'=>false]
    );
    return $item?[$item]:[];
}

function vp3_cognitive_context_episodic_items_v2420(PDO $pdo,array $user,string $namespace): array
{
    if(!function_exists('vp3_cognitive_memory_schema_ready_v570')||!vp3_cognitive_memory_schema_ready_v570($pdo))return [];
    $uid=(int)($user['id']??0);if($uid<1)return [];
    try{
        $stmt=$pdo->prepare("SELECT * FROM cognitive_memory_threads_v570
          WHERE owner_user_id=? AND agent_namespace=? AND status='active'
          ORDER BY last_seen_at DESC,id DESC LIMIT 12");
        $stmt->execute([$uid,$namespace]);$rows=$stmt->fetchAll()?:[];
    }catch(Throwable $e){return [];}

    $out=[];
    foreach($rows as $thread){
        try{
            $occurrences=function_exists('vp3_cognitive_memory_authorized_occurrences_v570')
                ?vp3_cognitive_memory_authorized_occurrences_v570($pdo,$user,$namespace,$thread,8)
                :[];
            if(!$occurrences)continue;
            $refs=[];
            foreach(array_slice($occurrences,0,5) as $occ){
                $ref=is_array($occ['_object_ref']??null)?$occ['_object_ref']:null;
                if($ref)$refs[]=$ref;
            }
            $safe=[
                'thread_id'=>(string)($thread['public_id']??''),
                'kind'=>(string)($thread['thread_kind']??''),
                'occurrence_count'=>(int)($thread['occurrence_count']??0),
                'distinct_object_count'=>(int)($thread['distinct_object_count']??0),
                'reopened_count'=>(int)($thread['reopened_count']??0),
                'last_seen_at'=>(string)($thread['last_seen_at']??''),
                'authorized_object_refs'=>$refs,
            ];
            $json=json_encode(vp3_cognitive_sanitize_value_v500($safe),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            $item=vp3_cognitive_context_item_v2420(
                'episodic_memory','cognitive-context:v2420:episode','Relevant episodic continuity',
                is_string($json)?$json:'{}',82.0,
                ['reference_only'=>true,'authorized_occurrences'=>count($occurrences)]
            );
            if($item)$out[]=$item;
        }catch(Throwable $e){}
        if(count($out)>=5)break;
    }
    return $out;
}

function vp3_cognitive_context_authorized_object_items_v2420(
    PDO $pdo,array $user,string $namespace,array $refs,array $options=[]
): array {
    $out=[];$seen=[];
    foreach(array_slice($refs,0,12) as $raw){
        if(!is_array($raw))continue;
        try{
            $ref=vp3_cognitive_object_ref_v500(
                (string)($raw['type']??''),(string)($raw['id']??''),(string)($raw['scope']??'personal')
            );
            $key=implode(':',[$ref['type'],$ref['scope'],$ref['id']]);if(isset($seen[$key]))continue;
            if(!vp3_cognitive_authorize_ref_v500($pdo,$user,$namespace,$ref,'read'))continue;
            $ctx=vp3_cognitive_context_for_ref_v500($pdo,$user,$namespace,$ref,$options);
            $safe=[
                'object_ref'=>$ref,
                'module'=>(string)($ctx['module']??''),
                'context'=>$ctx['context']??[],
                'fresh_at'=>(string)($ctx['fresh_at']??''),
            ];
            $json=json_encode(vp3_cognitive_sanitize_value_v500($safe),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            $item=vp3_cognitive_context_item_v2420(
                'active_objects','cognitive-context:v2420:object',
                'Authorized '.(string)$ref['type'].' context',is_string($json)?$json:'{}',88.0,
                ['object_ref'=>$ref,'reauthorized'=>true]
            );
            if($item){$out[]=$item;$seen[$key]=true;}
        }catch(Throwable $e){}
        if(count($out)>=5)break;
    }
    return $out;
}

function vp3_cognitive_context_legacy_items_v2420(array $legacy,array $terms,bool $historyIntent): array
{
    $out=[];$ordinal=0;
    foreach($legacy as $row){
        if(!is_array($row))continue;
        $source=vp3_cognitive_text_v500($row['source']??'retrieved',160);
        $section=vp3_cognitive_context_legacy_section_v2420($source);
        $base=vp3_cognitive_context_section_weight_v2420($section,$historyIntent);
        $item=vp3_cognitive_context_item_v2420(
            $section,$source,(string)($row['title']??'Retrieved context'),(string)($row['text']??''),
            $base,['legacy_projection'=>true,'ordinal'=>$ordinal++]
        );
        if(!$item)continue;
        $item['score']=round(vp3_cognitive_context_score_v2420($item,$terms,$historyIntent),3);
        $out[]=$item;
    }
    return $out;
}

function vp3_cognitive_context_select_v2420(array $items): array
{
    $sectionOrder=array_flip(vp3_cognitive_context_section_order_v2420());
    $seen=[];$counts=[];$ranked=[];
    foreach($items as $ordinal=>$item){
        if(!is_array($item))continue;
        $section=(string)($item['section']??'external');
        $fingerprint=hash('sha256',implode("\n",[
            (string)($item['source']??''),(string)($item['title']??''),(string)($item['text']??'')
        ]));
        if(isset($seen[$fingerprint]))continue;
        $seen[$fingerprint]=true;
        $item['_ordinal']=$ordinal;$item['_fingerprint']=$fingerprint;
        $ranked[]=$item;
    }
    usort($ranked,static function(array $a,array $b) use($sectionOrder): int {
        $score=((float)($b['score']??0))<=>((float)($a['score']??0));if($score!==0)return $score;
        $section=($sectionOrder[$a['section']??'external']??99)<=>($sectionOrder[$b['section']??'external']??99);if($section!==0)return $section;
        return ((int)($a['_ordinal']??0))<=>((int)($b['_ordinal']??0));
    });

    $selected=[];$bytes=0;
    foreach($ranked as $item){
        $section=(string)($item['section']??'external');
        $limit=vp3_cognitive_context_section_limit_v2420($section);
        if(($counts[$section]??0)>=$limit)continue;
        $itemBytes=strlen((string)($item['source']??''))+strlen((string)($item['title']??''))+strlen((string)($item['text']??''))+96;
        if($bytes+$itemBytes>VP3_COGNITIVE_CONTEXT_MAX_TEXT_BYTES_V2420)continue;
        unset($item['_ordinal'],$item['_fingerprint']);
        $selected[]=$item;$bytes+=$itemBytes;$counts[$section]=($counts[$section]??0)+1;
        if(count($selected)>=VP3_COGNITIVE_CONTEXT_MAX_ITEMS_V2420)break;
    }

    usort($selected,static function(array $a,array $b) use($sectionOrder): int {
        $section=($sectionOrder[$a['section']??'external']??99)<=>($sectionOrder[$b['section']??'external']??99);
        if($section!==0)return $section;
        $score=((float)($b['score']??0))<=>((float)($a['score']??0));
        return $score!==0?$score:strcmp((string)($a['title']??''),(string)($b['title']??''));
    });
    return ['items'=>$selected,'section_counts'=>$counts,'text_bytes'=>$bytes];
}

function vp3_cognitive_context_assemble_v2420(
    PDO $pdo,array $user,string $namespace,string $query,array $legacy=[],array $options=[]
): array {
    $uid=(int)($user['id']??0);if($uid<1)throw new RuntimeException('A signed-in VP3 user is required.');
    $namespace=vp3_cognitive_context_namespace_v2420($pdo,$user,$namespace);
    $query=vp3_cognitive_text_v500($query,4000);
    $historyIntent=!empty($options['history_intent'])||(function_exists('agent_brain_v99_history_intent')&&agent_brain_v99_history_intent($query));
    $terms=vp3_cognitive_context_terms_v2420($query);
    $items=[];

    foreach(vp3_cognitive_context_live_items_v2420($pdo,$user,$namespace) as $item)$items[]=$item;
    foreach(vp3_cognitive_context_priority_items_v2420($user,$namespace) as $item)$items[]=$item;
    foreach(vp3_cognitive_context_authorized_object_items_v2420(
        $pdo,$user,$namespace,is_array($options['object_refs']??null)?$options['object_refs']:[],$options
    ) as $item)$items[]=$item;
    foreach(vp3_cognitive_context_attention_items_v2420($pdo,$user,$namespace) as $item)$items[]=$item;
    foreach(vp3_cognitive_context_episodic_items_v2420($pdo,$user,$namespace) as $item)$items[]=$item;
    foreach(vp3_cognitive_context_legacy_items_v2420($legacy,$terms,$historyIntent) as $item)$items[]=$item;

    $selection=vp3_cognitive_context_select_v2420($items);
    $packet=[
        'contract'=>VP3_COGNITIVE_CONTEXT_CONTRACT_V2420,
        'build'=>VP3_COGNITIVE_CONTEXT_V2420,
        'purpose'=>vp3_cognitive_text_v500($options['purpose']??'chat_response',80),
        'principal'=>['user_id'=>$uid,'agent_namespace'=>$namespace],
        'query'=>['present'=>trim($query)!=='','history_intent'=>$historyIntent,'term_count'=>count($terms)],
        'items'=>$selection['items'],
        'section_counts'=>$selection['section_counts'],
        'limits'=>[
            'max_items'=>VP3_COGNITIVE_CONTEXT_MAX_ITEMS_V2420,
            'max_item_chars'=>VP3_COGNITIVE_CONTEXT_MAX_ITEM_CHARS_V2420,
            'max_text_bytes'=>VP3_COGNITIVE_CONTEXT_MAX_TEXT_BYTES_V2420,
            'max_packet_bytes'=>VP3_COGNITIVE_CONTEXT_MAX_PACKET_BYTES_V2420,
        ],
        'authority'=>[
            'working_memory'=>'ephemeral_projection_only',
            'durable_memory'=>'agent_memory_items',
            'episodic_memory'=>'cognitive_memory_v570_reference_layer',
            'live_session'=>'agent_live_sessions_v2370',
            'attention'=>'cognitive_attention_v2410',
            'object_authorization'=>'cognitive_runtime_v500',
            'execution_authority'=>false,
            'voice_is_authentication_authority'=>false,
        ],
        'privacy'=>[
            'raw_event_payloads_copied'=>false,
            'model_reasoning_persisted'=>false,
            'working_context_persisted'=>false,
        ],
        'generated_at'=>gmdate('c'),
    ];
    $safe=vp3_cognitive_sanitize_value_v500($packet);
    $json=json_encode($safe,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if(!is_string($json)||strlen($json)>VP3_COGNITIVE_CONTEXT_MAX_PACKET_BYTES_V2420){
        throw new RuntimeException('Unified working context exceeded its bounded packet limit.');
    }
    return $safe;
}

function vp3_cognitive_context_chat_items_v2420(
    PDO $pdo,array $user,string $query,array $legacy,array $options=[]
): array {
    $namespace=(string)($options['agent_namespace']??'');
    $packet=vp3_cognitive_context_assemble_v2420(
        $pdo,$user,$namespace,$query,$legacy,['purpose'=>'chat_response']+$options
    );
    return is_array($packet['items']??null)?$packet['items']:[];
}

function vp3_cognitive_context_durable_count_v2420(PDO $pdo,array $user,string $namespace): int
{
    $uid=(int)($user['id']??0);if($uid<1||!table_exists('agent_memory_items'))return 0;
    $agentId=vp3_cognitive_agent_id_v500($namespace);
    if(function_exists('vp3_agent_memory_scope_sql_v410')){
        [$scope,$params]=vp3_agent_memory_scope_sql_v410($agentId,'m');
        try{
            $stmt=$pdo->prepare('SELECT COUNT(*) FROM agent_memory_items m WHERE m.user_id=? AND '.$scope.' AND m.is_active=1');
            $stmt->execute(array_merge([$uid],$params));
            return max(0,(int)$stmt->fetchColumn());
        }catch(Throwable $e){return 0;}
    }
    return 0;
}

function vp3_cognitive_context_episode_count_v2420(PDO $pdo,array $user,string $namespace): int
{
    if(!function_exists('vp3_cognitive_memory_schema_ready_v570')||!vp3_cognitive_memory_schema_ready_v570($pdo))return 0;
    try{
        $stmt=$pdo->prepare("SELECT COUNT(*) FROM cognitive_memory_threads_v570 WHERE owner_user_id=? AND agent_namespace=? AND status='active'");
        $stmt->execute([(int)$user['id'],$namespace]);return max(0,(int)$stmt->fetchColumn());
    }catch(Throwable $e){return 0;}
}

function vp3_cognitive_context_activity_projection_v2420(
    PDO $pdo,array $user,string $namespace='system'
): array {
    $namespace=vp3_cognitive_context_namespace_v2420($pdo,$user,$namespace);
    $live=null;
    if(function_exists('vp3_live_session_snapshot_v2370')){
        try{
            $snapshot=vp3_live_session_snapshot_v2370($pdo,$user,false);
            $candidate=is_array($snapshot['session']??null)?$snapshot['session']:null;
            if($candidate&&hash_equals($namespace,trim((string)($candidate['agent_namespace']??'system'))?:'system')){
                $live=[
                    'status'=>(string)($candidate['status']??''),
                    'surface'=>(string)($candidate['current_surface']??''),
                    'context_key'=>(string)($candidate['current_context_key']??''),
                    'conversation_id'=>max(0,(int)($candidate['current_conversation_id']??0)),
                    'last_activity_at'=>(string)($candidate['last_activity_at']??''),
                ];
            }
        }catch(Throwable $e){}
    }
    $priorityCount=0;
    if($namespace==='system'&&function_exists('agent_cognitive_loop_v310_state')){
        try{
            $state=agent_cognitive_loop_v310_state($user);
            if(!function_exists('agent_cognitive_loop_v310_state_fresh')||agent_cognitive_loop_v310_state_fresh($state)){
                $priorityCount=count(array_filter((array)($state['priorities']??[]),'is_array'));
            }
        }catch(Throwable $e){}
    }
    $attention=function_exists('vp3_cognitive_attention_status_v2410')
        ?vp3_cognitive_attention_status_v2410($pdo,$user,$namespace):['ready'=>false];

    return [
        'build'=>VP3_COGNITIVE_CONTEXT_V2420,
        'contract'=>VP3_COGNITIVE_CONTEXT_CONTRACT_V2420,
        'agent_namespace'=>$namespace,
        'mode'=>'ephemeral_bounded_working_memory',
        'live_session'=>$live,
        'counts'=>[
            'priorities'=>$priorityCount,
            'episodic_threads'=>vp3_cognitive_context_episode_count_v2420($pdo,$user,$namespace),
            'durable_memories'=>vp3_cognitive_context_durable_count_v2420($pdo,$user,$namespace),
        ],
        'attention'=>$attention,
        'limits'=>[
            'max_items'=>VP3_COGNITIVE_CONTEXT_MAX_ITEMS_V2420,
            'max_text_bytes'=>VP3_COGNITIVE_CONTEXT_MAX_TEXT_BYTES_V2420,
        ],
        'working_context_persisted'=>false,
        'instruction_authority'=>false,
    ];
}

function vp3_cognitive_context_history_rows_v2420(
    PDO $pdo,array $user,string $namespace,int $limit=60
): array {
    $uid=(int)($user['id']??0);if($uid<1||!table_exists('agent_chat_archive')||!table_exists('chat_conversations'))return [];
    $namespace=vp3_cognitive_context_namespace_v2420($pdo,$user,$namespace);
    $agentId=vp3_cognitive_agent_id_v500($namespace);
    $limit=max(1,min(100,$limit));
    $scope=$agentId>0?'c.user_agent_id=?':'c.user_agent_id IS NULL';
    $params=$agentId>0?[$uid,$agentId]:[$uid];
    try{
        $stmt=$pdo->prepare(
            "SELECT a.id,a.conversation_id,a.source_message_id,a.role,a.input_mode,a.message_text,a.created_at,a.archived_at
             FROM agent_chat_archive a
             INNER JOIN chat_conversations c ON c.id=a.conversation_id AND c.user_id=a.user_id
             WHERE a.user_id=? AND {$scope}
             ORDER BY a.id DESC LIMIT {$limit}"
        );
        $stmt->execute($params);
        return array_map(static fn(array $row): array => [
            'id'=>(int)$row['id'],'conversation_id'=>(int)$row['conversation_id'],
            'source_message_id'=>(int)$row['source_message_id'],'role'=>(string)$row['role'],
            'input_mode'=>(string)$row['input_mode'],'message'=>(string)$row['message_text'],
            'created_at'=>(string)$row['created_at'],'archived_at'=>(string)$row['archived_at'],
        ],$stmt->fetchAll()?:[]);
    }catch(Throwable $e){return [];}
}
