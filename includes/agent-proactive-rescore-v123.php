<?php
declare(strict_types=1);

function agent_proactive_v123_rescore_result(array $result,array $user,string $surface='chat',array $context=[]): array
{
    $uid=(int)($user['id']??0);$activity=is_array($result['activity']??null)?$result['activity']:(function_exists('agent_activity_v94_snapshot')?agent_activity_v94_snapshot($user,$surface,$context):['state'=>'idle','task_title'=>'','interruptible'=>true]);
    $events=[];foreach((array)($result['events']??[]) as $event)if(is_array($event)&&!empty($event['id']))$events[(string)$event['id']]=$event;
    $actions=[];
    foreach((array)($result['suggestions']??[]) as $candidate){
        if(!is_array($candidate))continue;$candidate['_user_id']=$uid;$candidate['_surface']=$surface;
        $eventId=(string)($candidate['event_id']??'');
        if($eventId!==''&&isset($events[$eventId]))$event=$events[$eventId];else{$event=agent_proactive_v123_event($candidate);$events[(string)$event['id']]=$event;}
        $rank=agent_proactive_v123_score($candidate,$activity,null);$action=agent_proactive_v123_action($candidate,$event,$rank);
        $actions[]=$action;
    }
    usort($actions,static fn(array $a,array $b):int=>($b['score']<=>$a['score'])?:strcmp((string)$a['title'],(string)$b['title']));
    $limit=max(1,(int)($result['profile']['limit']??4));$actions=array_slice($actions,0,$limit);
    $used=array_flip(array_map(static fn(array $a):string=>(string)$a['event_id'],$actions));
    $result['events']=array_values(array_filter($events,static fn(array $e):bool=>isset($used[(string)$e['id']])));
    $result['suggestions']=$actions;$result['scoring']='dynamic-v123';$result['event_action_separation']=true;
    return $result;
}
