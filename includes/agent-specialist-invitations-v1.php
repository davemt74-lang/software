<?php
declare(strict_types=1);

/** Invitations project existing private sources; they never create a draft or authorize a tool. */
function vp3_specialist_invitation_text_v1(mixed $value,int $limit): string
{
    if(!is_string($value)||!mb_check_encoding($value,'UTF-8'))return '';
    return trim(mb_substr(preg_replace('~(?:https?://|\bwww\.)\S+|[<>\p{Cc}\p{Cf}]|\s+~u',' ',strip_tags($value))??'',0,$limit));
}

function vp3_specialist_invitation_hash_v1(array $value): string
{
    return hash('sha256',json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
}

/** Presentation contains no draft body, grant, send approval or playback action. */
function vp3_specialist_invitation_normalize_v1(mixed $value): ?array
{
    if(!is_array($value)||array_keys($value)!==['kind','items','context']
        ||!in_array($value['kind'],['email_drafts','music','youtube','media'],true)
        ||!is_array($value['items'])||!array_is_list($value['items'])||count($value['items'])<1||count($value['items'])>3
        ||!is_string($value['context'])||vp3_specialist_invitation_text_v1($value['context'],180)!==$value['context'])return null;
    foreach($value['items'] as $item){
        if(!is_array($item)||array_keys($item)!==['title','detail']||!is_string($item['title'])||$item['title']===''
            ||vp3_specialist_invitation_text_v1($item['title'],100)!==$item['title']||!is_string($item['detail'])
            ||vp3_specialist_invitation_text_v1($item['detail'],180)!==$item['detail'])return null;
    }
    return $value;
}

function vp3_specialist_invitation_draft_v1(array $invitation,string $question): string
{
    $invitation=vp3_specialist_invitation_normalize_v1($invitation)??throw new InvalidArgumentException('Invalid invitation.');
    $intro=$invitation['kind']==='email_drafts'?'Here are a few drafts we could work on:':'Here’s what we could try next:';
    if(count($invitation['items'])===1&&$invitation['kind']==='email_drafts')$intro='This saved draft is ready to review:';
    // Native Chat renders message-text with textContent. Keep the numbered list readable there and in speech.
    $lines=[];
    foreach($invitation['items'] as $index=>$item)$lines[]=($index+1).'. '.$item['title'].($item['detail']===''?'':' — '.$item['detail']);
    return $intro."\n\n".implode("\n",$lines).($invitation['context']===''?'':"\n\n".$invitation['context'])
        ."\n\n".$question;
}

/** Reuse only native receipt-verified, currently authorized analysis, without another inference. */
function vp3_specialist_invitation_reports_v1(PDO $pdo,array $scope,array $binding): array
{
    static $reading=false;
    if($reading||!function_exists('agent_job_engine_schema_ready_v1900')||!agent_job_engine_schema_ready_v1900($pdo))return [];
    $reading=true;
    try{
        require_once __DIR__.'/agent-initiative-brainstorm-specialist-v1.php';
        require_once __DIR__.'/agent-initiative-curiosity-analysis-v1.php';
        $reports=[];
        foreach(['brainstorm'=>'vp3_brainstorm_report_v1','curiosity'=>'vp3_curiosity_analysis_report_v1'] as $family=>$read){
            $result=$read($pdo,$scope['user'],$binding);
            if($result===null)continue;
            $ideas=$family==='brainstorm'?array_column($result['report']['ideas']??[],'objective'):[$result['report']['summary']??''];
            foreach(array_slice($ideas,0,3) as $idea){
                $text=vp3_specialist_invitation_text_v1($idea,180);
                if($text!=='')$reports[]=['family'=>$family,'key'=>$result['key'],'text'=>$text];
            }
        }
        return $reports;
    }finally{$reading=false;}
}

/** A topical overlap is explicitly a possible connection, never a verified fact or taste. */
function vp3_specialist_invitation_connection_v1(string $text,array $reports): ?array
{
    $words=static function(string $text):array{
        $all=preg_split('/[^\p{L}\p{N}]+/u',mb_strtolower($text),-1,PREG_SPLIT_NO_EMPTY)?:[];
        return array_values(array_unique(array_filter($all,static fn(string $word):bool=>mb_strlen($word)>=4
            &&!in_array($word,['about','after','before','could','draft','drafts','email','from','have','here','music','review','saved','that','their','these','this','video','want','with','would','your'],true))));
    };
    $terms=$words($text);
    foreach($reports as $report){
        if(!is_array($report)||!in_array($report['family']??null,['brainstorm','curiosity'],true)
            ||!is_string($report['key']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$report['key'])||!is_string($report['text']??null))continue;
        if(count(array_intersect($terms,$words($report['text'])))<2)continue;
        return ['key'=>$report['key'],'text'=>vp3_specialist_invitation_text_v1('Possible connection to '
            .($report['family']==='brainstorm'?'a Brainstorming idea':'a Curiosity finding').': '.$report['text'],180)];
    }
    return null;
}

/** Up to three actionable drafts from the latest fifty pending rows in this exact Agent. */
function vp3_specialist_invitation_email_v1(PDO $pdo,array $scope,array $binding,?array $pin=null): array
{
    require_once __DIR__.'/agent-email-review-queue-v1.php';
    require_once __DIR__.'/agent-gmail-workspace-context-v1.php';
    $user=agent_email_composer_invitation_authority_v1($pdo,$scope);
    $owner=(int)$user['id'];$agent=$scope['agent_id'];$now=time();
    $connection=agent_gmail_workspace_connection_v1($pdo,$owner);
    if(!$connection['send'])return ['state'=>'blocked'];
    if(!table_exists('agent_connection_email_drafts_v1'))return ['state'=>'blocked'];
    $pinned=$pin!==null;
    if($pinned&&(!isset($pin['drafts'])||!is_array($pin['drafts'])||!array_is_list($pin['drafts'])
        ||count($pin['drafts'])<1||count($pin['drafts'])>3))return ['state'=>'blocked'];
    $ids=[];
    foreach($pin['drafts']??[] as $target){
        if(!is_array($target)||array_keys($target)!==['id','conversation_id','revision']
            ||!is_string($target['id'])||!preg_match('/\A[a-f0-9]{32}\z/D',$target['id'])
            ||!is_int($target['conversation_id'])||$target['conversation_id']<1||!is_string($target['revision'])
            ||!preg_match('/\A[a-f0-9]{64}\z/D',$target['revision']))return ['state'=>'blocked'];
        $ids[]=$target['id'];
    }
    $agentWhere=$agent>0?'c.user_agent_id=?':'c.user_agent_id IS NULL';
    $exact=$pinned?' AND d.id IN ('.implode(',',array_fill(0,count($ids),'?')).')':'';
    $q=$pdo->prepare('SELECT d.*,c.title AS conversation_title,m.id AS request_message_id,m.created_at AS request_created_at
        FROM agent_connection_email_drafts_v1 d JOIN chat_conversations c ON c.id=d.conversation_id AND c.user_id=d.owner_user_id
        LEFT JOIN chat_messages m ON m.id=d.user_message_id AND m.conversation_id=d.conversation_id AND m.user_id=d.owner_user_id AND m.role=\'user\'
        WHERE d.owner_user_id=? AND d.agent_id=? AND '.$agentWhere.$exact.
        " AND d.status NOT IN ('sent','discarded','answered') ORDER BY d.user_message_id DESC,d.id DESC LIMIT 50");
    $q->execute([$owner,$agent,...($agent>0?[$agent]:[]),...$ids]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
    if(!$rows)return ['state'=>$pinned?'blocked':'empty'];
    $reports=vp3_specialist_invitation_reports_v1($pdo,$scope,$binding);$candidates=[];
    foreach($rows as $row){
        agent_connection_email_chat_authority_v1($pdo,$user,$scope['principal'],(int)$row['conversation_id']);
        $item=agent_email_review_queue_row_v1($row,'private-invitation-metadata-only',$now);
        if(!$item['available']||$item['status']!=='prepared'||!empty($item['connection_changed'])||empty($item['can_edit']))continue;
        $subject=vp3_specialist_invitation_text_v1($item['subject'],100);
        $recipient=vp3_specialist_invitation_text_v1($item['to'],90);
        if($subject===''&&$recipient==='')continue;
        if($subject==='')$subject='Untitled draft';
        $deadline=$item['source']['deadline']??null;$priority=$item['source']['priority']??null;
        $sourceReason=vp3_specialist_invitation_text_v1($item['source']['reason']??'',100);
        $connectionHint=vp3_specialist_invitation_connection_v1($subject.' '.$sourceReason,$reports);
        $detail=$recipient===''?'Recipient needed':'To '.$recipient;
        if($priority==='high')$detail.=' · high priority';
        if($deadline!==null)$detail.=' · saved deadline '.substr($deadline,0,10);
        if($priority!=='high'&&$deadline===null&&$sourceReason!==''&&!in_array($sourceReason,['Saved in this Chat.','Drafted from the original email.'],true))$detail.=' · '.$sourceReason;
        $target=['id'=>$row['id'],'conversation_id'=>(int)$row['conversation_id'],'revision'=>$item['edit_revision']];
        if($pinned&&!in_array($target,$pin['drafts'],true))continue;
        $due=$deadline===null?PHP_INT_MAX:(strtotime($deadline.' UTC')?:PHP_INT_MAX);
        $candidates[]=['target'=>$target,'title'=>$subject,'detail'=>vp3_specialist_invitation_text_v1($detail,180),
            'is_reply'=>(bool)$item['is_reply'],'hint'=>$connectionHint,
            'rank'=>[$priority==='high'?0:1,$due,$connectionHint===null?1:0,-(int)$row['user_message_id'],$row['id']]];
    }
    usort($candidates,static fn(array $a,array $b):int=>$a['rank']<=>$b['rank']);
    if($pinned){
        $ordered=[];foreach($pin['drafts'] as $target){
            $found=array_values(array_filter($candidates,static fn(array $item):bool=>$item['target']===$target));
            if(count($found)!==1)return ['state'=>'blocked'];$ordered[]=$found[0];
        }$candidates=$ordered;
    }else $candidates=array_slice($candidates,0,3);
    if(!$candidates)return ['state'=>'blocked'];
    $hints=array_values(array_filter(array_column($candidates,'hint')));
    $context=$hints[0]['text']??'';$contextKey=$hints[0]['key']??null;
    $targets=array_column($candidates,'target');
    $nextPin=['drafts'=>$targets,'context_key'=>$contextKey];
    if($pinned&&$nextPin!==$pin)return ['state'=>'blocked'];
    $presentation=['kind'=>'email_drafts','items'=>array_map(static fn(array $item):array=>array_intersect_key($item,array_flip(['title','detail'])),$candidates),'context'=>$context];
    // Recheck mailbox identity after projecting every row, including a grant revoked during the read.
    if(agent_gmail_workspace_connection_v1($pdo,$owner)['signature']!==$connection['signature'])throw new RuntimeException('Gmail access changed.',409);
    agent_email_composer_invitation_authority_v1($pdo,$scope);
    return ['state'=>'ready','choice'=>['title'=>'Saved email drafts','summary'=>'Reviewable saved drafts, ordered by priority, saved deadline and current context.',
        'question'=>count($candidates)===1?'Would you like to finish this draft together?':'Which of these drafts would you like to work on?',
        'identity'=>[$connection['revision'],$nextPin,$presentation],
        'evidence'=>['invitation'=>$presentation,'invitation_pin'=>$nextPin,'email_draft_targets'=>array_map(static fn(array $item):array=>$item['target']+['is_reply'=>$item['is_reply']],$candidates)]]];
}

function agent_email_composer_invitation_authority_v1(PDO $pdo,array $scope): array
{
    return agent_connection_email_chat_authority_v1($pdo,$scope['user'],$scope['principal'],(int)$scope['conversation_id']);
}
