<?php
declare(strict_types=1);

/**
 * HomeServer v2.3 Section 2 — explicit read-domain Agent execution.
 *
 * This bridge handles only user-intended local reads. It never performs writes,
 * device commands, file mutations, or implicit physical actions.
 */
const VP3_HOMESERVER_AGENT_READ_V230='vp3-homeserver-agent-read-v230-20260924';

function homeserver_agent_read_v230_local_marker(string $query): bool
{
    return (bool)preg_match('/\b(?:homeserver|home server|local(?:ly)?|my computer|this computer|my pc|this pc)\b/i',$query);
}

function homeserver_agent_read_v230_intent(string $query): ?array
{
    $q=trim($query);
    if($q==='')return null;
    $local=homeserver_agent_read_v230_local_marker($q);

    if(preg_match('/\b(hsf-\d+-[0-9a-f]{16})\b/i',$q,$m)
        && preg_match('/\b(?:read|open|show|display|contents?|text)\b/i',$q)){
        return ['kind'=>'file_read','operation'=>'files.read','payload'=>['ref'=>strtolower($m[1]),'max_chars'=>8000]];
    }
    if($local
        && preg_match('/\b(?:file|files|document|documents|doc|docs)\b/i',$q)
        && preg_match('/\b(?:find|search|list|show|look for|locate|which|what)\b/i',$q)){
        return ['kind'=>'files','operation'=>'files.list','payload'=>['query'=>mb_strimwidth($q,0,240,''),'limit'=>12]];
    }
    if($local
        && preg_match('/\b(?:knowledge|notes?|knowledge base|kb)\b/i',$q)
        && preg_match('/\b(?:find|search|show|look up|lookup|what|which|tell me)\b/i',$q)){
        return ['kind'=>'knowledge','operation'=>'knowledge.search','payload'=>['query'=>mb_strimwidth($q,0,240,'')]];
    }
    if($local
        && preg_match('/\b(?:tool|tools|plugin|plugins|capabilit(?:y|ies))\b/i',$q)
        && preg_match('/\b(?:list|show|what|which|available|have|use)\b/i',$q)){
        return ['kind'=>'tools','operation'=>'tools.list','payload'=>[]];
    }
    if($local
        && preg_match('/\b(?:device|devices|light|lights|outlet|outlets|fan|fans|thermostat|thermostats|sensor|sensors|camera|cameras)\b/i',$q)
        && preg_match('/\b(?:list|show|what|which|connected|available|status|state)\b/i',$q)){
        $args=['limit'=>40];
        foreach(['light','outlet','fan','thermostat','sensor','camera'] as $category){
            if(preg_match('/\b'.preg_quote($category,'/').'s?\b/i',$q)){$args['category']=$category;break;}
        }
        return ['kind'=>'devices','operation'=>'tool.execute','payload'=>['tool_key'=>'devices.list','arguments'=>$args]];
    }
    return null;
}

function homeserver_agent_read_v230_items(array $payload): array
{
    if(isset($payload['items'])&&is_array($payload['items']))return $payload['items'];
    if(isset($payload['result']['items'])&&is_array($payload['result']['items']))return $payload['result']['items'];
    return [];
}

function homeserver_agent_read_v230_line(mixed $value,int $max=600): string
{
    return mb_strimwidth(trim(preg_replace('/\s+/u',' ',(string)$value)??(string)$value),0,$max,'…');
}

function homeserver_agent_read_v230_answer(string $kind,array $payload): string
{
    $items=homeserver_agent_read_v230_items($payload);
    if($kind==='file_read'){
        $name=homeserver_agent_read_v230_line($payload['name']??$payload['title']??$payload['ref']??'HomeServer file',180);
        $content=trim((string)($payload['content']??$payload['text']??''));
        if($content==='')return 'I reached HomeServer, but that local file did not return readable text.';
        return "Here is the readable text from {$name}:\n\n".mb_strimwidth($content,0,8000,'…');
    }
    if(!$items){
        return match($kind){
            'files'=>'I searched the local HomeServer file index and found no matching files.',
            'knowledge'=>'I searched local HomeServer Knowledge and found no matching entries.',
            'tools'=>'HomeServer did not report any tools available to this VP3 connection.',
            'devices'=>'HomeServer did not report any matching connected devices.',
            default=>'HomeServer completed the local read but returned no matching items.',
        };
    }

    $lines=[];
    foreach(array_slice($items,0,10) as $item){
        if(!is_array($item))continue;
        if($kind==='files'){
            $name=homeserver_agent_read_v230_line($item['name']??$item['title']??$item['ref']??'File',180);
            $ref=homeserver_agent_read_v230_line($item['ref']??'',100);
            $snippet=homeserver_agent_read_v230_line($item['snippet']??$item['preview']??'',240);
            $lines[]='• '.$name.($ref!==''?' · '.$ref:'').($snippet!==''?' — '.$snippet:'');
        }elseif($kind==='knowledge'){
            $title=homeserver_agent_read_v230_line($item['title']??'Knowledge',180);
            $snippet=homeserver_agent_read_v230_line($item['snippet']??$item['content']??$item['text']??'',320);
            $lines[]='• '.$title.($snippet!==''?' — '.$snippet:'');
        }elseif($kind==='tools'){
            $key=homeserver_agent_read_v230_line($item['key']??$item['tool_key']??'',100);
            $label=homeserver_agent_read_v230_line($item['name']??$item['label']??$key?:'Tool',160);
            $mode=homeserver_agent_read_v230_line($item['mode']??'',60);
            $lines[]='• '.$label.($key!==''&&$key!==$label?' · '.$key:'').($mode!==''?' · '.$mode:'');
        }elseif($kind==='devices'){
            $name=homeserver_agent_read_v230_line($item['name']??$item['device_key']??'Device',160);
            $category=homeserver_agent_read_v230_line($item['category']??'',60);
            $room=homeserver_agent_read_v230_line($item['room_name']??$item['room_key']??'',120);
            $state=$item['state']??null;
            $stateText=is_array($state)?homeserver_agent_read_v230_line(json_encode($state,JSON_UNESCAPED_SLASHES),240):homeserver_agent_read_v230_line($state,240);
            $lines[]='• '.$name.($category!==''?' · '.$category:'').($room!==''?' · '.$room:'').($stateText!==''?' · '.$stateText:'');
        }
    }
    $lead=match($kind){
        'files'=>'I found these files on HomeServer:',
        'knowledge'=>'I found these entries in local HomeServer Knowledge:',
        'tools'=>'These HomeServer tools are available to VP3:',
        'devices'=>'These matching devices are visible to HomeServer:',
        default=>'HomeServer returned:',
    };
    return $lead."\n".implode("\n",$lines);
}

function homeserver_agent_read_v230_query(string $query,array $user,int $conversationId=0): array
{
    $empty=function_exists('vp3_agent_tool_empty_v400')
        ? vp3_agent_tool_empty_v400()
        : ['handled'=>false,'answer'=>'','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[]];
    $intent=homeserver_agent_read_v230_intent($query);
    if(!$intent)return $empty;
    $userId=(int)($user['id']??0);
    if($userId<1)return $empty;

    try{
        $execution=homeserver_execution_v230_execute(
            $userId,
            (string)$intent['operation'],
            is_array($intent['payload']??null)?$intent['payload']:[]
        );
        $payload=is_array($execution['result']??null)?$execution['result']:[];
        $answer=homeserver_agent_read_v230_answer((string)$intent['kind'],$payload);
        if(function_exists('agent_tool_log')){
            agent_tool_log($user,'homeserver.read.'.(string)$intent['kind'],$query,'success',[
              'domain'=>(string)($execution['execution']['domain']??''),
              'operation'=>(string)($execution['execution']['operation']??''),
              'request_id'=>(string)($execution['execution']['request_id']??''),
            ],$conversationId);
        }
        return [
          'handled'=>true,'answer'=>$answer,'stem_media'=>[],'media'=>[],'actions'=>[],
          'sources'=>[[
            'source'=>'homeserver:local-read',
            'title'=>'HomeServer · '.match((string)$intent['kind']){
              'files','file_read'=>'Local files','knowledge'=>'Knowledge','tools'=>'Tools','devices'=>'Devices',default=>'Local execution'
            },
          ]],
          'execution'=>is_array($execution['execution']??null)?$execution['execution']:[],
          'homeserver_read'=>['kind'=>(string)$intent['kind'],'version'=>'2.3'],
        ];
    }catch(Throwable $e){
        if(function_exists('agent_tool_log'))agent_tool_log($user,'homeserver.read.'.(string)$intent['kind'],$query,'failed',[],$conversationId);
        return [
          'handled'=>true,
          'answer'=>'HomeServer could not complete that local read right now. Your Cloud Agent is still available, but this request specifically requires the connected HomeServer.',
          'stem_media'=>[],'media'=>[],'actions'=>[],
          'sources'=>[['source'=>'homeserver:local-read','title'=>'HomeServer local execution unavailable']],
          'homeserver_read'=>['kind'=>(string)$intent['kind'],'version'=>'2.3','failed'=>true],
        ];
    }
}
