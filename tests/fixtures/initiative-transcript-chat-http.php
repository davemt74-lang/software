<?php
declare(strict_types=1);

// Public provider substitutes for this disposable server only. The production
// worker still validates the URL, address, page contents and exact citation.
const INITIATIVE_HTTP_PUBLIC_TOPIC='commercial oven ventilation';
const INITIATIVE_HTTP_PUBLIC_URL='https://fixture.invalid/oven-ventilation';
const INITIATIVE_HTTP_PUBLIC_QUOTE='Ventilation design depends on the appliance and installation conditions.';

if(!function_exists('dns_get_record')&&!function_exists('curl_init')){
    function dns_get_record(string $host,int $types): array
    {
        if($host!=='fixture.invalid')throw new LogicException('Unexpected public provider host.');
        return [['ip'=>'93.184.216.34']];
    }
    function curl_init(?string $url=null): object
    {
        $search='https://html.duckduckgo.com/html/?q='.rawurlencode(INITIATIVE_HTTP_PUBLIC_TOPIC);
        if($url!==$search&&$url!==INITIATIVE_HTTP_PUBLIC_URL){
            throw new LogicException('Only the derived public query and expected citation may reach public transport.');
        }
        return (object)['url'=>$url];
    }
    function curl_setopt_array(object $handle,array $options): bool{$handle->options=$options;return true;}
    function curl_exec(object $handle): bool
    {
        $write=$handle->options[CURLOPT_WRITEFUNCTION];
        if($handle->url!==INITIATIVE_HTTP_PUBLIC_URL){
            $write($handle,'<a class="result__a" href="'.INITIATIVE_HTTP_PUBLIC_URL.'">Commercial oven ventilation</a>');
            return true;
        }
        ($handle->options[CURLOPT_HEADERFUNCTION])($handle,"Content-Type: text/plain\r\n");
        $write($handle,INITIATIVE_HTTP_PUBLIC_QUOTE.' Confirm applicable requirements with the local authority.');
        return true;
    }
    function curl_getinfo(object $handle,int $option): int{return 200;}
    function curl_close(object $handle): void{}
}

function initiative_http_transcript_model_v1(string $protocol,array $input,string $prompt): array
{
    if(str_contains($prompt,'RAW PRIVATE RECORDING DETAILS'))throw new RuntimeException('Raw private transcript reached inference.');
    if($protocol==='vp3.initiative-plan.v1'){
        $refs=$input['tools']['topic.research']['source_refs']??[];
        if(count($refs)!==1||!str_starts_with($refs[0],'transcript-research:940201:'))throw new RuntimeException('Missing exact saved question.');
        // Exercise the same canonical bounded fallback used for an otherwise quiet assessment.
        return ['jobs'=>[],'opportunities'=>[]];
    }
    if($protocol==='vp3.transcript-research-plan.v1'){
        if(!str_contains($input['question']??'','private Supplier Delta quote'))throw new RuntimeException('Missing source report question.');
        return ['decision'=>'research','topic'=>INITIATIVE_HTTP_PUBLIC_TOPIC];
    }
    if($protocol==='vp3.topic-research.v1'){
        $citation='topic-web:'.hash('sha256',INITIATIVE_HTTP_PUBLIC_URL);
        if(str_contains($prompt,'Supplier Delta')||array_column($input['sources']??[],'source')!==[$citation]){
            throw new RuntimeException('The research model must use only the verified public citation.');
        }
        return ['findings'=>[['source'=>$citation,'quote'=>INITIATIVE_HTTP_PUBLIC_QUOTE]]];
    }
    throw new RuntimeException('Unexpected transcript model protocol: '.$protocol);
}

function initiative_http_seed_transcript_v1(PDO $pdo): void
{
    // The saved transcript question independently anchors this journey; the
    // generic Brain fixture task would schedule an unrelated Office review.
    $pdo->exec('DELETE FROM agent_memory_items WHERE id=100 AND user_id=1 AND user_agent_id=7');
    $pdo->exec("CREATE TABLE settings(setting_key TEXT PRIMARY KEY,setting_value TEXT);
        CREATE TABLE artist_transcript_sessions_v172(id INTEGER PRIMARY KEY,created_by_user_id INTEGER,conversation_id INTEGER,
            client_session_key TEXT,knowledge_id INTEGER,agent_memory_id INTEGER,project_note_id INTEGER,
            title TEXT,status TEXT,started_at TEXT,last_activity_at TEXT,metadata_json TEXT);
        CREATE TABLE artist_transcript_segments_v172(id INTEGER PRIMARY KEY,session_id INTEGER,client_segment_key TEXT,
            segment_index INTEGER,segment_type TEXT,speaker_label TEXT,transcript_text TEXT,started_ms INTEGER,ended_ms INTEGER,
            confidence REAL,created_at TEXT,updated_at TEXT);
        CREATE TABLE artist_transcript_page_analysis_v237(session_id INTEGER,page_number INTEGER,source_hash TEXT,
            source_word_count INTEGER,start_segment_index INTEGER,end_segment_index INTEGER,analysis_json TEXT,provider TEXT,
            model TEXT,generated_at TEXT,PRIMARY KEY(session_id,page_number));
        CREATE TABLE artist_transcript_master_analysis_v237(session_id INTEGER PRIMARY KEY,source_hash TEXT,source_word_count INTEGER,
            page_count INTEGER,analyzed_page_count INTEGER,analysis_json TEXT,research_json TEXT,provider TEXT,model TEXT,generated_at TEXT);
        INSERT INTO artist_transcript_sessions_v172(id,created_by_user_id,conversation_id,client_session_key,title,status,started_at,last_activity_at,metadata_json)
            VALUES(940201,1,11,'fixture-recording','Supplier discussion','draft','2020-01-01 00:00:00','2020-01-01 00:05:00','{}');
        INSERT INTO artist_transcript_segments_v172 VALUES(940202,940201,'fixture-segment',0,'transcript','Owner',
            'RAW PRIVATE RECORDING DETAILS. We discussed oven ventilation.',1000,5000,.9,'2020-01-01','2020-01-01');");
    require_once dirname(__DIR__,2).'/includes/agent-initiative-transcripts-v1.php';
    require_once dirname(__DIR__,2).'/includes/agent-transcript-research-policy-v1.php';
    $map=artist_listening_transcript_page_map(artist_listening_v172_segments($pdo,940201));
    $master=['modules'=>['qa'=>[
        'source_hash'=>$map['source_hash'],'generated_at'=>gmdate('c'),'result'=>['unanswered'=>[[
            'item_id'=>'public-guidance','question'=>'How should we evaluate oven ventilation for the private Supplier Delta quote?',
            'follow_up'=>'Find published ventilation guidance.','review_state'=>'unreviewed',
            'evidence_refs'=>[['page'=>1,'label'=>'Page 1']],
        ]]],
    ]]];
    $pdo->prepare("INSERT INTO artist_transcript_master_analysis_v237 VALUES(940201,?,?,?,?,?,'{}','fixture','fixture',?)")
        ->execute([$map['source_hash'],$map['total_words'],$map['page_count'],0,json_encode($master,JSON_THROW_ON_ERROR),gmdate('c')]);
    vp3_transcript_research_save_v1($pdo,1,true);
}
