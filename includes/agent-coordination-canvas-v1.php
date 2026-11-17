<?php
declare(strict_types=1);

require_once __DIR__.'/coding-canvas-command-grants-v1.php';
require_once __DIR__.'/agent-coordination-work-v1.php';

/** Review private records through the same saved finding and Chat path as other specialists. */
function vp3_coordination_canvas_sources_v1(PDO $pdo,array $scope,?array $refs,DateTimeImmutable $now): array
{
    $out=['items'=>[],'coverage'=>'unconfigured'];
    if($refs===[]||!vp3_coding_canvas_command_grants_ready_v1($pdo))return $out;
    $owner=(int)$scope['user']['id'];$agent=$scope['agent_id'];
    $selected=[];
    if($refs!==null){
        foreach($refs as $ref){
            [$app,$key]=explode(':',$ref,2);
            $selected[$app][$key]=true;
        }
    }
    $where=$selected?' AND c.id IN ('.implode(',',array_fill(0,count($selected),'?')).')':'';
    $query=$pdo->prepare("SELECT c.id,c.conversation_id,c.active_revision FROM vp3_coding_canvases_v1 c
        JOIN vp3_coding_canvas_command_grants_v1 g ON g.canvas_id=c.id AND g.owner_user_id=c.owner_user_id
            AND g.agent_id=c.agent_id AND g.conversation_id=c.conversation_id
        WHERE c.owner_user_id=? AND c.agent_id=? AND c.active_revision IS NOT NULL AND g.app_data_review=1$where
        ORDER BY c.updated_at DESC,c.id LIMIT 25");
    $query->execute([$owner,$agent,...array_keys($selected)]);
    $apps=$query->fetchAll(PDO::FETCH_ASSOC);
    if(!$apps)return $out;

    $out['coverage']=count($apps)>=25&&$refs===null?'limited':'ready';
    foreach($apps as $app){
        $id=$app['id'];$origin=(int)$app['conversation_id'];$revision=(int)$app['active_revision'];
        try{
            $grant=vp3_coding_canvas_command_grant_v1($pdo,$scope['user'],$agent,$origin,$id,$revision,'app-data','specialist_review');
            $row=vp3_coding_canvas_require_row_v1($pdo,$owner,$agent,$id);
            if((int)$row['active_revision']!==$revision||(int)$row['conversation_id']!==$origin)continue;
            $state=vp3_coding_canvas_state_v1($pdo,$row)['value'];
        }catch(RuntimeException $error){
            if($error instanceof PDOException)throw $error;
            if(in_array($error->getCode(),[403,404,409],true))continue;
            throw $error;
        }
        $items=vp3_coordination_canvas_items_v1($row,$state,(int)$grant['generation'],$now,$selected[$id]??null);
        $out['items']+=$items;
    }
    if($refs===null){
        uasort($out['items'],static function(array $left,array $right): int {
            $rank=['high'=>0,'normal'=>1,'low'=>2];
            $closed=['completed','cancelled'];
            return (($right['trigger']!==null)<=>($left['trigger']!==null))
                ?: (in_array($left['status'],$closed,true)<=>in_array($right['status'],$closed,true))
                ?: ($rank[$left['priority']]<=>$rank[$right['priority']])
                ?: (strcmp($left['due_at']?:'9999-12-31',$right['due_at']?:'9999-12-31'));
        });
        if(count($out['items'])>24)$out['coverage']='limited';
        $out['items']=array_slice($out['items'],0,24,true);
    }
    return $out;
}

/** Resolve a current report to its exact private app through the original Chat's review grant. */
function vp3_coordination_canvas_feedback_target_v1(PDO $pdo,array $user,int $agentId,string $ref,string $revision): ?array
{
    require_once __DIR__.'/agent-coordination-sources-v1.php';
    if(!str_starts_with($ref,'canvas:')||!vp3_coordination_source_ref_valid_v1($ref)
        ||!vp3_coordination_material_hash_v1($revision)||vp3_participant_scope_v1()!==null)return null;
    [,$id,$key]=explode(':',$ref,3);
    try{
        $app=vp3_coding_canvas_require_row_v1($pdo,(int)$user['id'],$agentId,$id);
        $origin=(int)$app['conversation_id'];$version=(int)$app['active_revision'];
        if($origin<1||$version<1)return null;
        $grant=vp3_coding_canvas_command_grant_v1($pdo,$user,$agentId,$origin,$id,$version,'app-data','specialist_review');
        $generation=(int)$grant['generation'];
        $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
        $items=vp3_coordination_canvas_items_v1($app,vp3_coding_canvas_state_v1($pdo,$app)['value'],$generation,$now,[$key=>true]);
        if(!isset($items[$ref])||!hash_equals($revision,$items[$ref]['revision']))return null;
        $artifact=vp3_coding_canvas_revision_artifact_v1($pdo,$app,$version);
        return ['canvas_id'=>$id,'conversation_id'=>$origin,'revision'=>$version,
            'draft_revision'=>$app['draft_revision']===null?null:(int)$app['draft_revision'],
            'commands'=>$artifact['commands'],'review_generation'=>$generation];
    }catch(RuntimeException $error){
        if($error instanceof PDOException)throw $error;
        if(in_array($error->getCode(),[403,404,409],true))return null;
        throw $error;
    }
}

/** The app chooses its interface and owns its records. Only the defined review fields leave storage. */
function vp3_coordination_canvas_items_v1(array $app,stdClass $state,int $generation,DateTimeImmutable $now,?array $selected): array
{
    $id=$app['id'];$agent=(int)$app['agent_id'];$origin=(int)$app['conversation_id'];$version=(int)$app['active_revision'];
    $appTitle=vp3_coordination_saved_text_v1(vp3_agent_activity_text_v1($app['title'],80),80);
    if($appTitle==='')$appTitle='Canvas app';
    $link=vp3_agent_activity_chat_href_v1($agent,$origin).'&canvas_id='.rawurlencode($id);
    $today=$now->format('Y-m-d');$tomorrow=$now->modify('+1 day')->format('Y-m-d');
    $items=[];$seen=[];
    foreach(['tasks','records'] as $collection){
        $records=$state->{$collection}??null;
        if(!is_array($records)||!array_is_list($records))continue;
        foreach($records as $record){
            if(!$record instanceof stdClass||(!is_int($record->id??null)&&!is_string($record->id??null)))continue;
            $recordId=(string)$record->id;
            if(!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,95}\z/D',$recordId))continue;
            $key=hash('sha256',$collection.':'.$recordId);$ref='canvas:'.$id.':'.$key;
            if($selected!==null&&!isset($selected[$key]))continue;
            if(isset($seen[$ref])){unset($items[$ref]);continue;}
            $seen[$ref]=true;
            $candidate=vp3_coordination_canvas_item_v1($ref,$record,$appTitle,$link,$generation,$version,$today,$tomorrow);
            if($candidate!==null)$items[$ref]=$candidate;
        }
    }
    return $items;
}

function vp3_coordination_canvas_item_v1(string $ref,stdClass $record,string $appTitle,string $link,int $generation,
    int $appVersion,string $today,string $tomorrow): ?array
{
    $title=vp3_coordination_saved_text_v1(vp3_agent_activity_text_v1($record->title??null,110),110);
    $status=is_string($record->status??null)?strtolower(trim($record->status)):'';
    $status=match($status){
        'open','todo'=>'open',
        'in_progress','working'=>'in_progress',
        'waiting_owner'=>'waiting_owner',
        'blocked'=>'blocked',
        'completed','complete','done'=>'completed',
        'cancelled','canceled'=>'cancelled',
        default=>null,
    };
    if($title===''||$status===null)return null;
    $due=$record->due_date??null;
    if(!is_string($due)||!preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/D',$due,$parts)
        ||!checkdate((int)$parts[2],(int)$parts[3],(int)$parts[1]))$due='';
    $priority=$record->priority??null;
    $priority=in_array($priority,['high','normal','low'],true)?$priority:'normal';
    $notes=vp3_coordination_saved_text_v1(vp3_agent_activity_text_v1($record->notes??null,160),160);
    $trigger=match(true){
        in_array($status,['completed','cancelled'],true)=>null,
        $status==='blocked'=>'blocked',
        $status==='waiting_owner'=>'waiting_owner',
        $due!==''&&$due<$today=>'overdue',
        $priority==='high'=>'high_priority',
        $due!==''&&$due<=$tomorrow=>'due_soon',
        default=>null,
    };
    [$next,$question]=match($trigger){
        'blocked'=>['Identify what is blocking this record.','What would unblock “'.$title.'” in '.$appTitle.'?'],
        'waiting_owner'=>['Confirm the decision this record needs from you.','What should happen next for “'.$title.'” in '.$appTitle.'?'],
        'overdue'=>['Check whether its due date still applies.','Is “'.$title.'” in '.$appTitle.' still due, or has its timing changed?'],
        'high_priority'=>['Check what would help move this record forward.','What is the next useful step for “'.$title.'” in '.$appTitle.'?'],
        'due_soon'=>['Check the due date and current progress.','Is “'.$title.'” in '.$appTitle.' on track for '.$due.'?'],
        default=>['',''],
    };
    $summary='In '.$appTitle.', “'.$title.'” is '.str_replace('_',' ',$status).'.';
    if($due!=='')$summary.=' Due '.$due.'.';
    if($notes!=='')$summary.=' The app notes “'.$notes.'”.';
    $item=vp3_coordination_source_item_v1($ref,'canvas',$title,$summary,$next,$question,$link,$status,
        in_array($trigger,['blocked','overdue','waiting_owner'],true)?'high':$priority,
        [$generation,$appVersion,$appTitle,$title,$status,$due,$priority,$notes,$trigger]);
    return $item+['trigger'=>$trigger,'due_at'=>$due];
}
