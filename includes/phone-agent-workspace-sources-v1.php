<?php
declare(strict_types=1);

require_once __DIR__.'/agent-activity-scope-v1.php';

/** Provider-free projections of native private work. Phone credentials are never accepted here.
 * The owner HTTP adapter authenticates the real session; the background adapter supplies its
 * enrolled owner from the database. Both use the same live account/Agent/Chat and source guards.
 * Only native identities and revision hashes belong in the workspace's durable index.
 */
function vp3_phone_agent_source_kinds_v1(): array
{
    return ['gmail_draft','gmail_attention','agent_result','image','canvas'];
}

function vp3_phone_agent_source_text_v1(mixed $value,int $limit): string
{
    return vp3_agent_activity_text_v1($value,$limit);
}

function vp3_phone_agent_source_epoch_v1(mixed $value,int $fallback): int
{
    if(is_int($value)&&$value>0)return $value;
    if(!is_string($value)||$value==='')return $fallback;
    try{$when=new DateTimeImmutable($value,new DateTimeZone('UTC'));}
    catch(Exception){return $fallback;}
    return $when->getTimestamp()>0?$when->getTimestamp():$fallback;
}

function vp3_phone_agent_source_scope_v1(PDO $pdo,array $user,int $agent,int $conversation=0): array
{
    $scope=vp3_agent_activity_scope_v1($pdo,$user,$agent,$conversation);
    if(!has_permission('chat.access',$scope['user']))throw new RuntimeException('Private Agent Chat access is unavailable.',403);
    return $scope;
}

function vp3_phone_agent_source_id_v1(string $kind,string $id): bool
{
    return match($kind){
        'gmail_draft'=>preg_match('/\A[a-f0-9]{32}\z/D',$id)===1,
        'gmail_attention'=>preg_match('/\A[a-f0-9]{64}\z/D',$id)===1,
        'agent_result'=>preg_match('/\A[1-9][0-9]{0,15}\z/D',$id)===1,
        'image','canvas'=>preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D',$id)===1,
        default=>false,
    };
}

function vp3_phone_agent_source_item_v1(array $scope,string $kind,string $id,array $revision,string $title,
    string $summary,string $state,bool $needsAction,string $priority,int $occurred,int $updated,array $target): array
{
    $owner=(int)$scope['user']['id'];$agent=$scope['agent_id'];$chat=$scope['conversation_id'];
    return ['source_kind'=>$kind,'source_id'=>$id,
        'source_revision'=>hash('sha256',json_encode(['phone-agent-source-v1',$owner,$agent,$chat,$kind,$id,$revision],JSON_THROW_ON_ERROR)),
        'conversation_id'=>$chat,'title'=>vp3_phone_agent_source_text_v1($title,240),
        'summary'=>vp3_phone_agent_source_text_v1($summary,1200),'state'=>$state,'needs_action'=>$needsAction,
        'priority'=>$priority,'occurred_epoch'=>$occurred,'updated_epoch'=>$updated,
        'target'=>['kind'=>$target['kind'],'agent_id'=>$agent,'conversation_id'=>$chat]+array_diff_key($target,['kind'=>true])];
}

/** Reuse the canonical saved-draft projection, and immediately discard its ephemeral approval tokens/body. */
function vp3_phone_agent_source_gmail_draft_v1(PDO $pdo,array $scope,string $id,int $now): ?array
{
    require_once __DIR__.'/agent-email-review-queue-v1.php';
    $query=$pdo->prepare("SELECT d.*,c.title AS conversation_title,m.id AS request_message_id,m.created_at AS request_created_at
        FROM agent_connection_email_drafts_v1 d
        JOIN chat_conversations c ON c.id=d.conversation_id AND c.user_id=d.owner_user_id AND COALESCE(c.user_agent_id,0)=d.agent_id
        JOIN chat_messages m ON m.id=d.user_message_id AND m.conversation_id=d.conversation_id AND m.user_id=d.owner_user_id AND m.role='user'
        WHERE d.owner_user_id=? AND d.agent_id=? AND d.id=?");
    $query->execute([(int)$scope['user']['id'],$scope['agent_id'],$id]);$draft=$query->fetch(PDO::FETCH_ASSOC);
    if(!$draft)return null;
    $scope=vp3_phone_agent_source_scope_v1($pdo,$scope['user'],$scope['agent_id'],(int)$draft['conversation_id']);
    agent_connection_email_chat_authority_v1($pdo,$scope['user'],$scope['principal'],$scope['conversation_id']);
    $payload=agent_connection_email_chat_payload_v1($draft);
    if(isset($payload['auto_revision']))return null;
    $row=agent_email_review_queue_row_v1($draft,random_bytes(32),$now);
    if(($row['blocked_reason']??null)==='automatic')return null;
    $available=!empty($row['available'])&&empty($row['connection_changed']);
    $status=$row['status'];
    $state=match(true){
        in_array($status,['sent','discarded','answered'],true)=>'completed',
        !$available=>'unavailable',
        in_array($status,['sending','drafting','write_queued','writing'],true)=>'waiting',
        in_array($status,['prepared','write_failed'],true)=>'needs_review',
        default=>'unavailable',
    };
    $title=$available&&$row['subject']!==''?$row['subject']:'Email draft';
    $summary=match($state){
        'completed'=>match($status){'sent'=>'This email was sent.','discarded'=>'This draft was discarded.',default=>'The original email was answered.'},
        'waiting'=>$status==='sending'?'The send outcome is being confirmed.':'The email draft is being prepared.',
        'unavailable'=>'Open Gmail to check this draft’s current availability.',
        default=>$row['source']['reason']?:'Review the saved email before sending.',
    };
    $created=vp3_phone_agent_source_epoch_v1($row['created_at'],$now);
    $updated=vp3_phone_agent_source_epoch_v1($row['updated_at'],$created);
    $priority=$available&&($row['source']['priority']??null)==='high'?'important':'normal';
    $revision=[$row['edit_revision'],$status,$available,!empty($row['can_send']),!empty($row['can_open_source'])];
    // Grant changes must not release fields obtained earlier in the same read.
    if($available&&($row['is_reply']??false)){
        $connection=agent_connections_require_grant_v1($pdo,(int)$scope['user']['id'],(int)$draft['connection_id'],'read',$draft['authorization_key']);
        if($connection['account_email']!==$draft['account_email']||!agent_connection_gmail_ready_v1())throw new RuntimeException('Gmail access changed.',409);
    }
    return vp3_phone_agent_source_item_v1($scope,'gmail_draft',$id,$revision,$title,$summary,$state,
        $state==='needs_review',$priority,$created,$updated,['kind'=>'gmail_draft','draft_id'=>$id]);
}

/** Mailbox ownership does not imply a historical Chat origin. Pick only an existing discussion target. */
function vp3_phone_agent_source_attention_context_v1(PDO $pdo,array $scope): ?array
{
    require_once __DIR__.'/agent-connection-gmail-inbox-v1.php';
    require_once __DIR__.'/agent-email-triage-v1.php';
    if(!agent_connection_gmail_inbox_ready_v1($pdo)||!agent_email_triage_ready_v1($pdo)||!agent_email_matters_ready_v1($pdo))
        throw new RuntimeException('Saved Gmail attention is unavailable.',503);
    $mailbox=agent_connection_gmail_inbox_bound_scope_v1($pdo,$scope['user']);
    $bound=agent_email_triage_scope_v1($mailbox);
    if($bound===null)return null;
    $query=$pdo->prepare('SELECT id FROM chat_conversations WHERE user_id=? AND COALESCE(user_agent_id,0)=? ORDER BY id LIMIT 1');
    $query->bindValue(1,(int)$scope['user']['id'],PDO::PARAM_INT);$query->bindValue(2,$scope['agent_id'],PDO::PARAM_INT);
    $query->execute();$chat=$query->fetchColumn();
    if($chat===false)return null;
    return ['mailbox'=>$mailbox,'bound'=>$bound,
        'scope'=>vp3_phone_agent_source_scope_v1($pdo,$scope['user'],$scope['agent_id'],(int)$chat)];
}

/** Saved important arrivals only. No provider request, synthetic Chat, or inferred reply outcome. */
function vp3_phone_agent_source_gmail_attention_v1(PDO $pdo,array $scope,string $id,int $now): ?array
{
    $context=vp3_phone_agent_source_attention_context_v1($pdo,$scope);
    if($context===null)return null;
    $mailbox=$context['mailbox'];$policy=$mailbox['policy'];$bound=$context['bound'];$scope=$context['scope'];
    $row=agent_email_triage_find_v1($pdo,$bound,$id);
    if($row===null||$row['attention_key']!=='act_soon'||$row['group_key']!=='active')return null;
    $source=agent_email_triage_source_v1($pdo,$mailbox,$id);
    $thread=agent_connection_gmail_id_v1(ai_decrypt_secret((string)($row['thread_id_enc']??'')));
    if($source===null||$thread===''||!is_string($row['matter_id'])
        ||!hash_equals($row['matter_id'],agent_email_matters_source_key_v1($policy,'thread',$thread)))return null;
    $matter=agent_email_matters_find_v1($pdo,$bound,$row['matter_id']);
    if($matter===null||!in_array($matter['stage'],['needs_review','reply_received'],true)
        ||!hash_equals((string)$matter['latest_message_key'],agent_email_matters_source_key_v1($policy,'inbox',$source['message_id']))
        ||ai_decrypt_secret((string)$matter['latest_message_id_enc'])!==$source['message_id']
        ||ai_decrypt_secret($matter['thread_id_enc'])!==$thread
        ||(int)$matter['latest_received_ms']!==(int)$row['received_ms']
        ||(int)$matter['last_sent_at']*1000>=(int)$row['received_ms'])return null;
    $view=agent_email_triage_view_v1($row);$received=intdiv((int)$row['received_ms'],1000);
    $observed=vp3_phone_agent_source_epoch_v1($row['observed_at'],0);
    if($received<1||$received>$now||$observed<1||$observed>$now)return null;
    $eligible=!$view['historical']&&$view['alert'];
    $summary=($view['from']!==''?'From '.$view['from'].'. ':'').$view['reason'];
    if($view['due_date']!==null)$summary.=' Saved deadline: '.$view['due_date'].'.';
    $fresh=agent_connection_gmail_inbox_bound_scope_v1($pdo,$scope['user']);
    if(agent_email_triage_scope_v1($fresh)!==$bound||($fresh['generation']??null)!==$mailbox['generation'])
        throw new RuntimeException('Gmail review access changed.',409);
    // Poll timestamps and the matter's bookkeeping revision do not create a new semantic notification.
    $revision=[$bound,$mailbox['generation'],$row['revision'],$row['attention_key'],$row['reason_key'],
        $row['subject'],$row['sender_address'],$row['due_date'],$row['due_at'],$matter['latest_message_key'],
        $matter['stage'],$matter['last_sent_at'],$eligible];
    return vp3_phone_agent_source_item_v1($scope,'gmail_attention',$id,$revision,$view['subject']?:'Important email',
        $summary,'needs_review',true,'important',$received,$observed,
        ['kind'=>'gmail_attention','item_ref'=>$id,'generation'=>$mailbox['generation'],
            'origin'=>'gmail_mailbox','conversation_role'=>'discussion_target'])+['notification_eligible'=>$eligible];
}

/** Completed assets are verified by the native image reader, including exact workflow and file ownership. */
function vp3_phone_agent_source_image_v1(PDO $pdo,array $scope,string $id,int $now): ?array
{
    require_once __DIR__.'/agent-image-generation-v1.php';
    $row=vp3_image_row_v1($pdo,$scope['user'],$id);
    if((int)$row['agent_id']!==$scope['agent_id']||(int)$row['conversation_id']<1)return null;
    $scope=vp3_phone_agent_source_scope_v1($pdo,$scope['user'],$scope['agent_id'],(int)$row['conversation_id']);
    $view=vp3_image_view_v1($pdo,$scope['user'],$row);
    if(!in_array($view['status'],['completed','file_unavailable'],true))return null;
    $run=vp3_image_run_v1($pdo,$scope['user'],$row);
    $assets=array_map(static fn(array $asset):array=>[(int)$asset['id'],!empty($asset['available'])],$view['assets']);
    $created=vp3_phone_agent_source_epoch_v1($row['created_at'],$now);
    $completed=vp3_phone_agent_source_epoch_v1($run['completed_at']??$run['updated_at']??null,$created);
    return vp3_phone_agent_source_item_v1($scope,'image',$id,[$row['request_hash'],hash('sha256',$row['config_json']),
        $view['status'],$assets],$view['title']?:'Generated image',
        $view['status']==='completed'?($view['count']===1?'Your generated image is ready.':$view['count'].' generated images are ready.'):'The saved image file is currently unavailable.',
        $view['status']==='completed'?'ready':'unavailable',false,'normal',$completed,$completed,
        ['kind'=>'image','generation_id'=>$id]);
}

/** Private code remains in its native Knowledge documents. No public publication is created or consulted. */
function vp3_phone_agent_source_canvas_v1(PDO $pdo,array $scope,string $id,int $now): ?array
{
    require_once __DIR__.'/coding-canvas-store-v1.php';
    vp3_coding_canvas_authority_v1($pdo,$scope['user'],$scope['agent_id']);
    $row=vp3_coding_canvas_require_row_v1($pdo,(int)$scope['user']['id'],$scope['agent_id'],$id);
    if(!empty($row['chat_hidden'])||(int)$row['conversation_id']<1)return null;
    $scope=vp3_phone_agent_source_scope_v1($pdo,$scope['user'],$scope['agent_id'],(int)$row['conversation_id']);
    $revision=(int)($row['draft_revision']??$row['active_revision']??0);
    if($revision<1)return null;
    $view=vp3_coding_canvas_read_v1($pdo,$scope['user'],$scope['agent_id'],$id,$revision);
    require_once __DIR__.'/coding-canvas-command-grants-v1.php';
    $settings=vp3_coding_canvas_command_settings_v1($pdo,$row);
    $native=$view['revision'];
    $created=vp3_phone_agent_source_epoch_v1($row['created_at'],$now);
    $updated=vp3_phone_agent_source_epoch_v1($native['created_at'],$created);
    $summary='Private canvas version '.$revision.' is ready to open.';
    if(!$native['test_passed'])$summary='Private canvas version '.$revision.' is saved. Preview checks are still pending.';
    return vp3_phone_agent_source_item_v1($scope,'canvas',$id,[$revision,hash('sha256',json_encode($native['artifact'],JSON_THROW_ON_ERROR)),
        $native['commands'],$settings['revision'],(int)($row['active_revision']??0),(int)($row['draft_revision']??0)],$row['title']?:'Private canvas',
        $summary,'ready',false,'normal',$updated,$updated,['kind'=>'canvas','canvas_id'=>$id,'revision'=>$revision]);
}

/** An announcement alone is not completion proof: load the exact native result, receipt and current sources. */
function vp3_phone_agent_source_result_load_v1(PDO $pdo,array $scope,string $id): ?array
{
    $query=$pdo->prepare("SELECT m.conversation_id FROM chat_messages m
        JOIN chat_conversations c ON c.id=m.conversation_id
        WHERE m.id=? AND c.user_id=? AND COALESCE(c.user_agent_id,0)=? AND m.role='assistant' AND m.user_id IS NULL");
    foreach([(int)$id,(int)$scope['user']['id'],$scope['agent_id']] as $index=>$value)$query->bindValue($index+1,$value,PDO::PARAM_INT);
    $query->execute();$chat=$query->fetchColumn();
    if($chat===false)return null;
    $scope=vp3_phone_agent_source_scope_v1($pdo,$scope['user'],$scope['agent_id'],(int)$chat);
    require_once __DIR__.'/agent-initiative-followthrough-v1.php';
    $loaded=vp3_initiative_followthrough_load_v1($pdo,$scope['user'],$scope['principal'],(int)$chat,(int)$id);
    if(($loaded['state']['coordination_source_changed']??false)||($loaded['state']['state']??null)==='stale')return null;
    // Native emails and artifacts get their own exact cards instead of a second approval/result copy.
    $output=$loaded['context']['initiative_output']??[];
    if(isset($output['gmail_reply_draft_id'])||in_array($output['type']??'',
        ['initiative_canvas','initiative_email_canvas','initiative_image_concept'],true))return null;
    return ['scope'=>$scope,'loaded'=>$loaded];
}

function vp3_phone_agent_source_result_v1(PDO $pdo,array $scope,string $id,int $now): ?array
{
    $read=vp3_phone_agent_source_result_load_v1($pdo,$scope,$id);
    if($read===null)return null;
    $scope=$read['scope'];$loaded=$read['loaded'];$message=$loaded['message'];$state=$loaded['state'];
    $when=vp3_phone_agent_source_epoch_v1($message['created_at']??null,$now);
    $closed=in_array($state['state'],['declined','owner_resolved','completed'],true);
    $tool=$loaded['goal']['tool']??'';
    $title=match($tool){'topic.research','artist.research'=>'Research result',
        'chat.check_in'=>'Agent check-in','coordination.review'=>'Agent update',default=>'Agent result'};
    return vp3_phone_agent_source_item_v1($scope,'agent_result',$id,
        [$state['reply_ref'],$state['revision'],$state['state'],hash('sha256',$message['message'])],
        $title,$message['message'],$closed?'completed':'ready',false,'normal',$when,$when,
        ['kind'=>'chat_message','message_id'=>(int)$id]);
}

/** Missing/reassigned sources have no card. Authority/storage failures are not successful empty reads. */
function vp3_phone_agent_source_resolve_v1(PDO $pdo,array $user,int $agent,string $kind,string $id,int $now): ?array
{
    if($now<1||!vp3_phone_agent_source_id_v1($kind,$id))throw new InvalidArgumentException('Choose a valid Agent item.',422);
    $scope=vp3_phone_agent_source_scope_v1($pdo,$user,$agent);
    try{
        $item=match($kind){
            'gmail_draft'=>vp3_phone_agent_source_gmail_draft_v1($pdo,$scope,$id,$now),
            'gmail_attention'=>vp3_phone_agent_source_gmail_attention_v1($pdo,$scope,$id,$now),
            'image'=>vp3_phone_agent_source_image_v1($pdo,$scope,$id,$now),
            'canvas'=>vp3_phone_agent_source_canvas_v1($pdo,$scope,$id,$now),
            'agent_result'=>vp3_phone_agent_source_result_v1($pdo,$scope,$id,$now),
        };
        if($item!==null)vp3_phone_agent_source_scope_v1($pdo,$scope['user'],$agent,$item['conversation_id']);
        return $item;
    }catch(PDOException $error){throw $error;}
    catch(RuntimeException|DomainException $error){
        if(in_array($error->getCode(),[404,409],true))return null;
        throw $error;
    }
}

/** Each family has a bounded native index query, and every row is reauthorized before ingestion.
 * Coverage describes these saved sources, never all mailbox mail or all historical Agent output.
 */
function vp3_phone_agent_sources_discover_v1(PDO $pdo,array $user,int $agent,int $now,int $limit=40): array
{
    if($limit<1||$limit>100||$now<1)throw new InvalidArgumentException('Choose a bounded Agent inbox page.',422);
    $scope=vp3_phone_agent_source_scope_v1($pdo,$user,$agent);$owner=(int)$scope['user']['id'];
    $cap=min(40,$limit);$count=$cap+1;
    $queries=[
        'gmail_draft'=>"SELECT d.id AS source_id FROM agent_connection_email_drafts_v1 d
            JOIN chat_conversations c ON c.id=d.conversation_id AND c.user_id=d.owner_user_id
            WHERE d.owner_user_id=? AND d.agent_id=? AND COALESCE(c.user_agent_id,0)=d.agent_id
                AND d.status NOT IN ('sent','discarded','answered') ORDER BY d.user_message_id DESC,d.id DESC LIMIT $count",
        'image'=>"SELECT g.generation_id AS source_id FROM agent_image_generations_v1 g
            JOIN chat_conversations c ON c.id=g.conversation_id AND c.user_id=g.owner_user_id AND COALESCE(c.user_agent_id,0)=g.agent_id
            JOIN agent_workflow_runs r ON r.id=g.run_id AND r.owner_user_id=g.owner_user_id
            WHERE g.owner_user_id=? AND g.agent_id=? AND g.conversation_id>0 AND r.status='completed' ORDER BY g.run_id DESC LIMIT $count",
        'canvas'=>"SELECT a.id AS source_id FROM vp3_coding_canvases_v1 a
            JOIN chat_conversations c ON c.id=a.conversation_id AND c.user_id=a.owner_user_id AND COALESCE(c.user_agent_id,0)=a.agent_id
            WHERE a.owner_user_id=? AND a.agent_id=? AND a.chat_hidden=0 AND a.conversation_id>0
                AND COALESCE(a.draft_revision,a.active_revision,0)>0 ORDER BY a.updated_at DESC,a.id DESC LIMIT $count",
        'agent_result'=>"SELECT m.id AS source_id FROM chat_messages m JOIN chat_conversations c ON c.id=m.conversation_id
            WHERE c.user_id=? AND COALESCE(c.user_agent_id,0)=? AND m.role='assistant' AND m.user_id IS NULL
                AND JSON_EXTRACT(CASE WHEN JSON_VALID(m.context_json) THEN m.context_json ELSE '{}' END,'$.source')='agent_initiative'
            ORDER BY m.id DESC LIMIT $count",
    ];
    $queries['gmail_attention']='';
    $items=[];$coverage=[];$more=false;
    foreach($queries as $kind=>$sql){
        try{
            if($kind==='gmail_attention'){
                $context=vp3_phone_agent_source_attention_context_v1($pdo,$scope);
                if($context===null){$coverage[$kind]='disabled';continue;}
                $query=$pdo->prepare("SELECT triage_id FROM agent_email_triage_v1
                    WHERE owner_user_id=? AND connection_id=? AND grant_key=? AND attention_key='act_soon' AND group_key='active'
                    ORDER BY received_ms DESC,triage_id DESC LIMIT $count");
                $query->execute($context['bound']);
            }else{
                $query=$pdo->prepare($sql);$query->bindValue(1,$owner,PDO::PARAM_INT);$query->bindValue(2,$agent,PDO::PARAM_INT);
                $query->execute();
            }
            $ids=$query->fetchAll(PDO::FETCH_COLUMN);
            $limited=count($ids)>$cap;$more=$more||$limited;
            foreach(array_slice($ids,0,$cap) as $id){
                try{$item=vp3_phone_agent_source_resolve_v1($pdo,$scope['user'],$agent,$kind,(string)$id,$now);}
                catch(RuntimeException|DomainException $error){
                    if(!$error instanceof PDOException&&$error->getCode()===403){$limited=true;continue;}
                    throw $error;
                }
                if($item!==null)$items[$kind.':'.$id]=$item;
            }
            $coverage[$kind]=$limited?'limited':'ready';
        }catch(PDOException|RuntimeException|DomainException|JsonException $error){
            $coverage[$kind]=$error->getCode()===403?'forbidden':'unavailable';
            // A failed family cannot leave a partially read private projection in this result.
            foreach($items as $key=>$item)if($item['source_kind']===$kind)unset($items[$key]);
        }
    }
    vp3_phone_agent_source_scope_v1($pdo,$scope['user'],$agent);
    $items=array_values($items);
    usort($items,static fn(array $a,array $b):int=>$b['updated_epoch']<=>$a['updated_epoch']
        ?:strcmp($a['source_kind'].':'.$a['source_id'],$b['source_kind'].':'.$b['source_id']));
    $more=$more||count($items)>$limit;
    return ['items'=>array_slice($items,0,$limit),'coverage'=>$coverage,'has_more'=>$more];
}

/** Detail reads do not acknowledge an item or dispatch work. Native hosts fetch email/media/code themselves. */
function vp3_phone_agent_source_detail_v1(PDO $pdo,array $user,int $agent,string $kind,string $id,int $now,string $expectedRevision): array
{
    $item=vp3_phone_agent_source_resolve_v1($pdo,$user,$agent,$kind,$id,$now);
    if($item===null||!preg_match('/\A[a-f0-9]{64}\z/D',$expectedRevision)
        ||!hash_equals($item['source_revision'],$expectedRevision))throw new RuntimeException('This Agent item changed. Refresh its latest version.',409);
    $detail=['kind'=>$kind,'target'=>$item['target']];
    if($kind==='agent_result'){
        $scope=vp3_phone_agent_source_scope_v1($pdo,$user,$agent,$item['conversation_id']);
        $read=vp3_phone_agent_source_result_load_v1($pdo,$scope,$id);
        if($read===null)throw new RuntimeException('This Agent result is unavailable.',409);
        $text=(string)$read['loaded']['message']['message'];
        $detail['text']=mb_substr($text,0,12000);$detail['truncated']=mb_strlen($text)>12000;
    }
    $fresh=vp3_phone_agent_source_resolve_v1($pdo,$user,$agent,$kind,$id,$now);
    if($fresh===null||!hash_equals($fresh['source_revision'],$expectedRevision))throw new RuntimeException('This Agent item changed. Refresh its latest version.',409);
    return $detail;
}
