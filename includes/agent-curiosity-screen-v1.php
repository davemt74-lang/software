<?php
declare(strict_types=1);

require_once __DIR__.'/agent-memory-scope-v410.php';

/** Fresh screen descriptions follow the existing Supply scene context grant and transient routing fence. */
function vp3_curiosity_live_screen_v1(PDO $pdo,array $user,array $binding): ?array
{
    if(!table_exists('agent_activity_state'))return null;
    require_once __DIR__.'/agent-initiative-tools-v1.php';
    require_once __DIR__.'/agent-initiative-live-context-v1.php';
    $scope=vp3_initiative_tools_scope_v1($pdo,$user,$binding);$coverage=[];
    $sources=vp3_initiative_live_capabilities_evidence_v1($pdo,$scope,$binding,
        new DateTimeImmutable('now',new DateTimeZone('UTC')),null,$coverage);
    $source=$sources?reset($sources):null;$screens=[];
    foreach($source['live_context']['screens']??[] as $screen){
        $summary=mb_substr((string)($screen['summary']??''),0,1000);
        if(trim($summary)==='')continue;
        $screens[]=['source_id'=>$screen['source_id'],'surface'=>$screen['surface'],'summary'=>$summary,
            'page_title'=>mb_substr((string)($screen['page_title']??''),0,250),
            'site_hint'=>mb_substr((string)($screen['site_hint']??''),0,180),
            'observations'=>array_slice((array)($screen['observations']??[]),0,4)];
    }
    if(!$screens)return null;
    return ['ref'=>$source['ref'],'revision'=>hash('sha256',json_encode([$source['ref'],
        $source['processing_policy_revision'],$screens],JSON_THROW_ON_ERROR)),
        'screens'=>$screens,'state'=>'fresh_shared_observation','ephemeral'=>true,'preferences_confirmed'=>false];
}

/** Reuse analyzed captures explicitly retained in this Agent's Brain; capture itself grants no new recall access. */
function vp3_curiosity_screen_briefs_v1(PDO $pdo,array $user,int $agent): array
{
    if(!has_permission('chat.access',$user)||!personal_capability_has_v242('agent_brain.access',$user)
        ||!function_exists('personal_knowledge_available')||!personal_knowledge_available($user)
        ||(function_exists('vp3_participant_scope_v1')&&vp3_participant_scope_v1()!==null))return [];
    foreach(['agent_screen_sessions_v1','agent_screen_captures_v1','agent_memory_items'] as $table)if(!table_exists($table))return [];
    [$where,$args]=vp3_agent_memory_scope_sql_v410($agent);
    $reviewed=table_exists('agent_screen_reviews_v1');
    $join=$reviewed?'LEFT JOIN agent_screen_reviews_v1 review ON review.owner_user_id=c.owner_user_id AND review.capture_id=c.capture_id':'';
    $filter=$reviewed?" AND (review.state IS NULL OR review.state='active')":'';
    $concat=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?"'screen-capture:'||c.capture_id":"CONCAT('screen-capture:',c.capture_id)";
    $q=$pdo->prepare("SELECT c.session_id,c.capture_id,c.revision,c.captured_at,c.body_json,m.metadata_json
        FROM agent_screen_captures_v1 c JOIN agent_screen_sessions_v1 s
            ON s.owner_user_id=c.owner_user_id AND s.session_id=c.session_id
        JOIN agent_memory_items m ON m.id=c.memory_id AND m.user_id=c.owner_user_id AND m.is_active=1
            AND m.memory_type='screen_observation' AND m.subject=$concat
        $join WHERE c.owner_user_id=? AND s.agent_id=? AND s.status<>'deleted' AND c.kind='screen'
            AND $where AND c.captured_at>=?$filter ORDER BY c.captured_at DESC,c.capture_id DESC LIMIT 6");
    $q->execute([(int)$user['id'],$agent,...$args,gmdate('Y-m-d\TH:i:s\Z',time()-7*86400)]);
    $briefs=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
        $body=json_decode((string)$row['body_json'],true);$memory=json_decode((string)$row['metadata_json'],true);
        $observation=$body['observation']??null;$at=strtotime((string)$row['captured_at']);
        if(!is_array($observation)||($memory['source']??null)!=='agent_screen_session_v1'
            ||($memory['session_id']??null)!==$row['session_id']||($memory['capture_id']??null)!==$row['capture_id']
            ||$at===false||$at>time()||$at<time()-7*86400||!is_string($observation['summary']??null))continue;
        $summary=mb_substr($observation['summary'],0,1000);$facts=[];
        foreach(array_slice((array)($observation['observations']??[]),0,8) as $fact){
            if(!is_array($fact)||!in_array($fact['confidence']??null,['medium','high'],true)
                ||!is_string($fact['label']??null)||!is_string($fact['detail']??null))continue;
            $facts[]=['kind'=>(string)($fact['kind']??'other'),'label'=>mb_substr($fact['label'],0,200),
                'detail'=>mb_substr($fact['detail'],0,400),'confidence'=>$fact['confidence']];
        }
        if(trim($summary)===''&&!$facts)continue;
        $briefs[]=['ref'=>'eyes-capture:'.$row['capture_id'],'capture_id'=>$row['capture_id'],'session_id'=>$row['session_id'],
            'captured_at'=>$row['captured_at'],'revision'=>(int)$row['revision'],'summary'=>$summary,'observations'=>$facts,
            'source_revision'=>hash('sha256',json_encode([$row['capture_id'],(int)$row['revision'],$summary,$facts],JSON_THROW_ON_ERROR)),
            'authority'=>'shared_visual_observation','preferences_confirmed'=>false];
        if(count($briefs)>=3)break;
    }
    return $briefs;
}
