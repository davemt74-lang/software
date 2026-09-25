<?php
declare(strict_types=1);

/**
 * HomeServer v2.3 Section 2 — explicit local read execution for Cloud Agent turns.
 *
 * This layer only performs bounded read operations when the user's message
 * explicitly asks for HomeServer/local data. It never turns a generic Cloud
 * compute turn into passive local surveillance.
 */
const VP3_HOMESERVER_LOCAL_READS_V231='vp3-homeserver-local-reads-v231-20260925';

function homeserver_reads_v231_intent(string $query): array
{
    $q=mb_strtolower(trim($query));
    if($q==='')return ['domain'=>'','operation'=>'','payload'=>[]];

    $explicitLocal=(bool)preg_match('/\b(?:homeserver|home server|local|on my computer|on this computer|on my pc|on the pc)\b/i',$query);
    $fileRef='';
    if(preg_match('/\b(hsf-[0-9]+-[0-9a-f]{16})\b/i',$query,$m))$fileRef=strtolower($m[1]);

    if($fileRef!==''&&(bool)preg_match('/\b(?:read|open|show|summarize|inspect|contents?)\b/i',$query)){
        return ['domain'=>'files','operation'=>'files.read','payload'=>['ref'=>$fileRef,'offset'=>0,'max_chars'=>12000]];
    }
    if($explicitLocal&&(bool)preg_match('/\b(?:file|files|document|documents|folder|folders)\b/i',$query)){
        $search=preg_replace('/\b(?:homeserver|home server|local|on my computer|on this computer|on my pc|on the pc|find|search|show|list|my|file|files|document|documents|folder|folders|for|about)\b/iu',' ',$query)??$query;
        return ['domain'=>'files','operation'=>'files.list','payload'=>['query'=>trim(preg_replace('/\s+/u',' ',$search)??$search),'limit'=>12]];
    }
    if($explicitLocal&&(bool)preg_match('/\b(?:knowledge|notes?|memory|reference|research)\b/i',$query)){
        $search=preg_replace('/\b(?:homeserver|home server|local|search|find|show|my|knowledge|notes?|memory|reference|research|for|about)\b/iu',' ',$query)??$query;
        return ['domain'=>'knowledge','operation'=>'knowledge.search','payload'=>['query'=>trim(preg_replace('/\s+/u',' ',$search)??$search)]];
    }
    if($explicitLocal&&(bool)preg_match('/\b(?:tool|tools|capabilities|skills|what can .* do)\b/i',$query)){
        return ['domain'=>'tools','operation'=>'tools.list','payload'=>[]];
    }
    if((bool)preg_match('/\b(?:homeserver|home server|local|my)\b/i',$query)
       &&(bool)preg_match('/\b(?:device|devices|light|lights|thermostat|thermostats|fan|fans|outlet|outlets|room|rooms)\b/i',$query)
       &&(bool)preg_match('/\b(?:show|list|status|state|what|which|find)\b/i',$query)){
        return ['domain'=>'devices','operation'=>'tool.execute','payload'=>[
          'tool_key'=>'devices.list',
          'arguments'=>['limit'=>50],
        ]];
    }
    return ['domain'=>'','operation'=>'','payload'=>[]];
}

function homeserver_reads_v231_rows(mixed $value): array
{
    if(!is_array($value))return [];
    if(isset($value['result'])&&is_array($value['result']))return homeserver_reads_v231_rows($value['result']);
    if(isset($value['items'])&&is_array($value['items']))return array_values(array_filter($value['items'],'is_array'));
    if(array_is_list($value))return array_values(array_filter($value,'is_array'));
    return [$value];
}

function homeserver_reads_v231_text(mixed $value,int $limit=3500): string
{
    if(is_string($value))return mb_strimwidth(trim(preg_replace('/\s+/u',' ',$value)??$value),0,$limit,'…');
    if(is_scalar($value))return mb_strimwidth((string)$value,0,$limit,'…');
    return '';
}

function homeserver_reads_v231_context_item(string $domain,array $row,int $index): ?array
{
    $source='homeserver-local:'.$domain.':'.($index+1);
    if($domain==='files'){
        $ref=homeserver_reads_v231_text($row['ref']??$row['file_ref']??'',120);
        $title=homeserver_reads_v231_text($row['name']??$row['title']??$ref??'Local file',240)?:'Local file';
        $text=homeserver_reads_v231_text($row['content']??$row['text']??$row['snippet']??'',6000);
        if($text===''){
            $parts=array_filter([
              $ref!==''?'ref: '.$ref:'',
              homeserver_reads_v231_text($row['relative_path']??$row['path']??'',500),
              isset($row['size_bytes'])?'size: '.(int)$row['size_bytes'].' bytes':'',
            ]);
            $text=implode(' · ',$parts);
        }
    }elseif($domain==='knowledge'){
        $title=homeserver_reads_v231_text($row['title']??$row['name']??'Local Knowledge',240)?:'Local Knowledge';
        $text=homeserver_reads_v231_text($row['snippet']??$row['content']??$row['text']??'',6000);
    }elseif($domain==='tools'){
        $title=homeserver_reads_v231_text($row['name']??$row['title']??$row['key']??'HomeServer tool',240)?:'HomeServer tool';
        $text=homeserver_reads_v231_text($row['description']??'',3000);
        $key=homeserver_reads_v231_text($row['key']??'',120);
        if($key!=='')$text=trim($key.($text!==''?' · '.$text:''));
    }elseif($domain==='devices'){
        $title=homeserver_reads_v231_text($row['name']??$row['device_key']??'HomeServer device',240)?:'HomeServer device';
        $bits=[];
        foreach(['device_key','category','room_name','room_key'] as $key){
            $v=homeserver_reads_v231_text($row[$key]??'',200);
            if($v!=='')$bits[]=$key.': '.$v;
        }
        if(isset($row['state'])){
            $encoded=json_encode($row['state'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            if(is_string($encoded))$bits[]='state: '.mb_strimwidth($encoded,0,1600,'…');
        }
        $text=implode(' · ',$bits);
    }else return null;

    if($text==='')return null;
    return ['source'=>$source,'title'=>$title,'text'=>$text];
}

function homeserver_reads_v231_set_last(array $state): array
{
    $GLOBALS['vp3_homeserver_local_read_v231_last']=$state;
    return $state;
}

function homeserver_reads_v231_last(): array
{
    $state=$GLOBALS['vp3_homeserver_local_read_v231_last']??null;
    return is_array($state)?$state:['attempted'=>false,'context'=>[],'execution'=>null,'domain'=>''];
}

function homeserver_reads_v231_context(array $user,string $query): array
{
    $userId=(int)($user['id']??0);
    if($userId<1||!function_exists('homeserver_execution_v230_execute'))return homeserver_reads_v231_set_last(['attempted'=>false,'context'=>[],'execution'=>null,'domain'=>'']);
    $intent=homeserver_reads_v231_intent($query);
    if(($intent['operation']??'')==='')return homeserver_reads_v231_set_last(['attempted'=>false,'context'=>[],'execution'=>null,'domain'=>'']);

    try{
        $run=homeserver_execution_v230_execute($userId,(string)$intent['operation'],(array)$intent['payload']);
    }catch(Throwable $e){
        return homeserver_reads_v231_set_last(['attempted'=>true,'context'=>[],'execution'=>[
          'status'=>'failed','failure_class'=>homeserver_execution_v230_failure_class($e),
          'domain'=>(string)$intent['domain'],'operation'=>(string)$intent['operation'],
        ],'domain'=>(string)$intent['domain']]);
    }
    $rows=homeserver_reads_v231_rows($run['result']??[]);
    $context=[];
    foreach(array_slice($rows,0,12) as $index=>$row){
        $item=homeserver_reads_v231_context_item((string)$intent['domain'],$row,$index);
        if($item)$context[]=$item;
    }
    return homeserver_reads_v231_set_last([
      'attempted'=>true,
      'context'=>$context,
      'execution'=>is_array($run['execution']??null)?$run['execution']:null,
      'domain'=>(string)$intent['domain'],
    ]);
}

function homeserver_reads_v231_enrich_context(array $context,array $user,string $query): array
{
    $local=homeserver_reads_v231_context($user,$query);
    if(empty($local['context']))return ['context'=>$context,'local'=>$local];
    $merged=array_merge((array)$local['context'],$context);
    return ['context'=>array_slice($merged,0,32),'local'=>$local];
}
