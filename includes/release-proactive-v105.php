<?php
declare(strict_types=1);

function release_v105_proactive_items(array $user): array
{
    if (!release_v105_schema_ready() || !permission_v105_has('release.manage', $user)) return [];
    $pdo = db();
    $owner = release_v105_workspace_owner_id($user);
    if (!$pdo || $owner < 1) return [];

    try {
        $stmt = $pdo->prepare(
            "SELECT ri.id,ri.release_id,ri.title,ri.item_type,ri.status,ri.due_at,rp.title AS release_title,rp.target_date,rp.priority
             FROM release_items ri
             JOIN release_plans rp ON rp.id=ri.release_id
             WHERE rp.owner_user_id=?
               AND rp.status NOT IN ('released','cancelled')
               AND ri.status NOT IN ('complete','cancelled')
               AND (ri.due_at IS NOT NULL OR rp.target_date IS NOT NULL)
             ORDER BY COALESCE(ri.due_at,rp.target_date) ASC,ri.id ASC
             LIMIT 20"
        );
        $stmt->execute([$owner]);
        $rows = $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $items=[];$now=time();
    foreach($rows as $row){
        $date=(string)($row['due_at'] ?: $row['target_date']);
        $ts=strtotime($date);if($ts===false)continue;
        $days=(int)floor(($ts-$now)/86400);
        if($days>14)continue;
        $overdue=$ts<$now;
        $priority=$overdue?190:($days<=2?170:($days<=7?145:115));
        if((string)$row['status']==='blocked')$priority+=15;
        if((string)$row['priority']==='critical')$priority+=15;
        elseif((string)$row['priority']==='high')$priority+=8;
        $when=$overdue?'overdue':($days<=0?'due today':($days===1?'due tomorrow':'due in '.$days.' days'));
        $title=($overdue?'Resolve ':'Work on ').(string)$row['title'];
        $prompt='Review release plan "'.(string)$row['release_title'].'" and help me complete "'.(string)$row['title'].'". Use the linked Release Calendar resources and Agent tools, identify blockers, and prepare the next useful action. Do not perform an external side effect without the required approval.';
        $items[]=agent_proactive_v93_item(
            'release-item:'.(int)$row['id'].':'.date('Y-m-d',$ts),
            $title,
            $prompt,
            (string)$row['release_title'].' · '.$when.((string)$row['status']==='blocked'?' · blocked':''),
            $priority,
            'release_calendar',
            url('/admin/releases.php?release='.(int)$row['release_id'])
        );
    }
    return $items;
}

function release_v105_merge_proactive(array $base, array $user): array
{
    $extra=release_v105_proactive_items($user);
    if(!$extra)return $base;
    $profile=is_array($base['profile']??null)?$base['profile']:['limit'=>4];
    $limit=max(1,(int)($profile['limit']??4));
    $all=array_merge(is_array($base['suggestions']??null)?$base['suggestions']:[],$extra);
    $dedup=[];
    foreach($all as $item){
        if(!is_array($item)||empty($item['hash']))continue;
        $hash=(string)$item['hash'];
        if(!isset($dedup[$hash])||(int)($item['priority']??0)>(int)($dedup[$hash]['priority']??0))$dedup[$hash]=$item;
    }
    $suppressed=function_exists('agent_proactive_v93_suppressed')?agent_proactive_v93_suppressed((int)($user['id']??0)):[];
    $visible=array_values(array_filter($dedup,static fn(array $item):bool=>empty($suppressed[(string)$item['hash']])));
    usort($visible,static fn(array $a,array $b):int=>(int)($b['priority']??0)<=>(int)($a['priority']??0));
    $base['suggestions']=array_slice($visible,0,$limit);
    return $base;
}
