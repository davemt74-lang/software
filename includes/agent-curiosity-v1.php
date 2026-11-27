<?php
declare(strict_types=1);

require_once __DIR__.'/agent-activity-scope-v1.php';
require_once __DIR__.'/agent-initiative-runtime-v1.php';
require_once __DIR__.'/agent-commerce-recovery-pa2.php';

const VP3_CURIOSITY_THREAD_PREFIX_V1='curiosity-thread:';
const VP3_CURIOSITY_DAILY_SLOTS_V1=['morning','evening','general'];
const VP3_CURIOSITY_OFFER_SPACING_SECONDS_V1=120;
const VP3_CURIOSITY_STARTER_QUESTIONS_V1=[
    'media'=>'What kind of music have you been enjoying lately?',
    'food'=>'What’s a dish you like to cook or want to learn to make?',
    'hobbies'=>'Is there a hobby or personal project you’d like to spend more time on?',
    'film'=>'What kind of movies or shows do you usually enjoy?',
];

/** Curiosity uses labeled owner activity; browser content and private source text stay in their origin. */
function vp3_curiosity_theme_definitions_v1(): array
{
    return [
        'canvas'=>['Canvas and coding','\b(?:canvas(?: app)?|code|coding|feature|software|bug|deploy(?:ment)?)\b'],
        'planning'=>['Planning and scheduling','\b(?:calendar|schedule|appointment|meeting|itinerary|planning)\b'],
        'tasks'=>['Tasks and routines','\b(?:tasks?|reminders?|to[ -]do|checklists?|task lists?)\b'],
        'food'=>['Food and cooking','\b(?:recipes?|cooking|baking|cookbooks?|cuisine|meal prep)\b'],
        'film'=>['Movies and shows','\b(?:movies?|films?|tv shows?|television|documentar(?:y|ies)|cinema)\b'],
        'media'=>['Music and video','\b(?:music|playlists?|youtube|tracks?|videos?|songs?|albums?|artists?|jazz)\b'],
        'hobbies'=>['Hobbies and projects','\b(?:hobb(?:y|ies)|gardening|knitting|woodworking|painting|photography|crafts?|chess|birdwatching)\b'],
        'local'=>['Local recommendations','(?i:\b(?:restaurants?|weekend events?|activit(?:y|ies)|recommendations?)\b.{0,80}\b(?:in|around)) [\p{Lu}][\p{L}-]+(?: [\p{Lu}][\p{L}-]+){0,4}\s+(?i:city|county|region|province)\b'],
        'research'=>['Research and learning','\b(?:research|study|tutorials?|learn(?:ing)?|compare|investigate)\b'],
        'communication'=>['Writing and communication','\b(?:emails?|draft|letter|newsletter|announcement)\b'],
    ];
}

function vp3_curiosity_private_scope_v1(PDO $pdo,array $user,int $agentId,int $cid): array
{
    $scope=vp3_agent_activity_scope_v1($pdo,$user,$agentId,$cid);
    if(!has_permission('chat.access',$scope['user'])||!personal_capability_has_v242('agent_brain.access',$scope['user'])
        ||($cid>0&&!vp3_agent_chat_conversation_v380($pdo,$cid,(int)$scope['user']['id'],$scope['agent']))){
        throw new RuntimeException('Curiosity is unavailable in this Chat.',403);
    }
    return $scope;
}

function vp3_curiosity_id_v1(string $key): string
{
    return substr(hash('sha256','vp3-curiosity-v1|'.$key),0,32);
}

function vp3_curiosity_thread_id_v1(array $scope,string $theme): string
{
    return vp3_curiosity_id_v1($scope['user']['id'].'|'.$scope['agent_id'].'|'.$theme);
}

function vp3_curiosity_ref_v1(string $threadId,string $questionId): string
{
    if(!preg_match('/\A[a-f0-9]{32}\z/D',$threadId)||!preg_match('/\A[a-f0-9]{32}\z/D',$questionId)){
        throw new InvalidArgumentException('Choose an exact Curiosity question.');
    }
    return 'curiosity:'.$threadId.':'.$questionId;
}

function vp3_curiosity_href_v1(array $scope,int $cid,int $mid): string
{
    return vp3_agent_activity_chat_href_v1($scope['agent_id'],$cid).'&message_id='.$mid;
}

/** Foreground acknowledgments and synthetic or connected-provider replies cannot create a recurrence. */
function vp3_curiosity_observed_v1(PDO $pdo,array $scope,int $now): array
{
    [$agentWhere,$agentArgs]=vp3_agent_chat_scope_sql_v380($scope['agent']);
    $epoch=vp3_initiative_db_wall_epoch_sql_v1($pdo,'m.created_at');
    $integer=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'INTEGER':'UNSIGNED';
    $q=$pdo->prepare("SELECT c.id AS conversation_id,m.id,m.message,m.context_json,$epoch AS happened_at
        FROM chat_messages m JOIN chat_conversations c ON c.id=m.conversation_id AND c.user_id=m.user_id
        WHERE c.user_id=? AND $agentWhere AND m.role='user' AND LENGTH(m.message)<=1200
        AND CAST($epoch AS $integer) BETWEEN CAST(? AS $integer) AND CAST(? AS $integer)
        ORDER BY m.created_at DESC,m.id DESC LIMIT 120");
    $q->execute([(int)$scope['user']['id'],...$agentArgs,$now-30*86400,$now]);
    $found=[];$seen=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
        $context=$row['context_json']===null||$row['context_json']===''?[]:json_decode((string)$row['context_json'],true);
        if(!is_array($context)||!empty($context['skip_brain_archive'])||isset($context['curiosity_reply_v1'])
            ||isset($context['initiative_reply_anchor_v1'])||isset($context['casual_checkin_reply_v1'])
            ||isset($context['initiative_status_read_v1'])||in_array($context['source']??null,['agent_initiative','agent_coding_canvas','automation','system'],true))continue;
        $text=vp3_curiosity_safe_text_v1((string)$row['message'],280);
        if($text===''||!preg_match('/\b(?:help|make|build|fix|create|start|plan|review|learn|compare|research|listen|watch|play|search|find|draft|show|open|work|want|need)\b/iu',$text))continue;
        $normalized=mb_strtolower($text,'UTF-8');
        foreach(vp3_curiosity_theme_definitions_v1() as $theme=>[$label,$pattern]){
            if(!preg_match('~'.$pattern.'~'.($theme==='local'?'u':'iu'),$text))continue;
            if(isset($seen[$theme][$normalized]))break;
            $seen[$theme][$normalized]=true;
            $found[$theme]??=['id'=>$theme,'title'=>$label,'state'=>'observed','occurrences'=>0,'sources'=>[],'last_seen_at'=>(int)$row['happened_at']];
            ++$found[$theme]['occurrences'];
            if(count($found[$theme]['sources'])<3){
                $cid=(int)$row['conversation_id'];$mid=(int)$row['id'];
                $found[$theme]['sources'][]=['label'=>'Owner Chat message','conversation_id'=>$cid,'message_id'=>$mid,'href'=>vp3_curiosity_href_v1($scope,$cid,$mid)];
            }
            break;
        }
    }
    vp3_curiosity_observe_gmail_links_v1($pdo,$scope,$now,$found);
    $found=array_filter($found,static fn(array $theme):bool=>$theme['occurrences']>=2);
    foreach($found as &$theme){
        $topic=mb_strtolower($theme['title'],'UTF-8');
        $links=$theme['gmail_link_occurrences']??0;
        unset($theme['gmail_link_occurrences']);
        if($links===0){$theme['summary']=$theme['occurrences'].' recent owner messages about '.$topic.'.';continue;}
        $messages=$theme['occurrences']-$links;
        $activity=$links===1?'1 link opened from Gmail':$links.' links opened from Gmail';
        if($messages>0)$activity.=$messages===1?' and 1 recent owner message':' and '.$messages.' recent owner messages';
        $theme['summary']=$activity.' about '.$topic.'.';
        $theme['starter_question']='I noticed some recent activity about '.$topic.'. Is there something there you’d like to explore?';
    }
    unset($theme);
    uasort($found,static fn(array $a,array $b):int=>$b['occurrences']<=>$a['occurrences']?:$b['last_seen_at']<=>$a['last_seen_at']);
    return $found;
}

/** A click is an observation. It contributes to a question only when another observation supports its topic. */
function vp3_curiosity_observe_gmail_links_v1(PDO $pdo,array $scope,int $now,array &$found): void
{
    $cid=$scope['conversation_id'];
    if($cid<1||!table_exists('agent_connection_gmail_inbox_v1'))return;
    try{
        require_once __DIR__.'/agent-curiosity-gmail-v1.php';
        $links=vp3_gmail_curiosity_link_observations_v1($pdo,$scope['user'],$scope['agent_id'],$cid,$now-30*86400);
    }catch(RuntimeException|JsonException $error){
        return;
    }
    foreach($links as $row){
        if($row['clicked_at']>$now)continue;
        $topic=vp3_curiosity_gmail_topic_v1($row['title'],$row['excerpt']);
        if($topic===null)continue;
        [$theme,$label]=$topic;
        $found[$theme]??=['id'=>$theme,'title'=>$label,'state'=>'observed','occurrences'=>0,'sources'=>[],'last_seen_at'=>0];
        ++$found[$theme]['occurrences'];
        $found[$theme]['gmail_link_occurrences']=($found[$theme]['gmail_link_occurrences']??0)+1;
        $found[$theme]['last_seen_at']=max($found[$theme]['last_seen_at'],$row['clicked_at']);
        $mid=$row['message_id'];
        if(count($found[$theme]['sources'])<3&&!in_array($mid,array_column($found[$theme]['sources'],'message_id'),true)){
            $found[$theme]['sources'][]=['label'=>'Gmail link opened from Chat','conversation_id'=>$cid,'message_id'=>$mid,
                'href'=>vp3_curiosity_href_v1($scope,$cid,$mid)];
        }
    }
}

function vp3_curiosity_gmail_topic_v1(string $title,string $excerpt): ?array
{
    // Once classified, source wording stays out of the Curiosity report and thread.
    foreach([$title,$excerpt] as $value){
        $text=vp3_curiosity_safe_text_v1($value,280);
        if($text==='')continue;
        foreach(vp3_curiosity_theme_definitions_v1() as $theme=>[$label,$pattern]){
            if(preg_match('~'.$pattern.'~'.($theme==='local'?'u':'iu'),$text)===1)return [$theme,$label];
        }
    }
    return null;
}

/** Deliberately exclude identifying details, secrets, and sensitive subject matter from passive summaries. */
function vp3_curiosity_safe_text_v1(string $value,int $limit): string
{
    if(!mb_check_encoding($value,'UTF-8')||preg_match('~[<>]|https?://|\b[^\s@]+@[^\s@]+\.\w{2,}\b|\d{2,}\s+[^\r\n]{2,60}\b(?:street|avenue|road|drive|lane|boulevard|court)\b|[-+]?\d{1,3}\.\d{4,}\s*[,/]\s*[-+]?\d{1,3}\.\d{4,}|(?:\d[ .()+-]*){8,}|\b(?:password|api key|social security|diagnosis|treatment|medical history|health condition|religion|sexual orientation|political affiliation|home address|bank account)\b~iu',$value))return '';
    $text=trim(preg_replace('/[\p{Cc}\p{Cf}]|\s+/u',' ',$value)??'');
    return mb_substr($text,0,$limit,'UTF-8');
}

function vp3_curiosity_read_threads_v1(PDO $pdo,array $scope,?array $ids=null): array
{
    if(!agent_brain_schema_ready())throw new RuntimeException('Curiosity storage is unavailable.',503);
    [$where,$args]=vp3_agent_memory_scope_sql_v410($scope['agent_id']);
    $parameters=[(int)$scope['user']['id'],...$args];
    if($ids===null){
        $filter='subject LIKE ?';$parameters[]=VP3_CURIOSITY_THREAD_PREFIX_V1.'%';
    }else{
        if(!array_is_list($ids)||count($ids)>32)throw new InvalidArgumentException('Choose exact Curiosity threads.');
        if(!$ids)return [];
        foreach($ids as $id){
            if(!is_string($id)||!preg_match('/\A[a-f0-9]{32}\z/D',$id))throw new InvalidArgumentException('Choose an exact Curiosity thread.');
            $parameters[]=VP3_CURIOSITY_THREAD_PREFIX_V1.$id;
        }
        $filter='subject IN ('.implode(',',array_fill(0,count($ids),'?')).')';
    }
    $q=$pdo->prepare("SELECT subject,metadata_json FROM agent_memory_items WHERE user_id=? AND $where
        AND memory_type='initiative_sweep' AND is_active=1 AND $filter ORDER BY last_seen_at DESC,id DESC LIMIT 48");
    $q->execute($parameters);$threads=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
        $data=json_decode((string)$row['metadata_json'],true,32,JSON_THROW_ON_ERROR);
        if(($data['version']??null)!==1||($data['owner_id']??null)!==(int)$scope['user']['id']
            ||($data['agent_id']??null)!==$scope['agent_id']||!is_string($data['id']??null)
            ||$row['subject']!==VP3_CURIOSITY_THREAD_PREFIX_V1.$data['id']||!is_array($data['questions']??null)
            ||!in_array($data['state']??null,['open','answered','skipped','deferred'],true)){
            throw new RuntimeException('A Curiosity thread could not be verified.',409);
        }
        $threads[$data['id']]=$data;
    }
    return $threads;
}

function vp3_curiosity_write_thread_v1(array $scope,array $thread): void
{
    $written=vp3_initiative_in_scope_v1($scope['user'],$scope['agent_id'],static fn()=>agent_brain_v122_upsert_system_memory(
        $scope['user'],'initiative_sweep',VP3_CURIOSITY_THREAD_PREFIX_V1.$thread['id'],'Private Curiosity thread.', $thread));
    if($written<1)throw new RuntimeException('Curiosity progress could not be saved.',503);
}

function vp3_curiosity_new_thread_v1(array $scope,array $theme,int $now,?string $day=null,?string $slot=null): array
{
    $id=vp3_curiosity_thread_id_v1($scope,$day===null?$theme['id']:'daily-checkin:'.$day.':'.$slot);
    $title=$theme['title'];
    $prompt=$day===null?($theme['starter_question']??'You’ve returned to '.mb_strtolower($title,'UTF-8').' a few times. Is there something you’d like to focus on there?')
        :match($slot){
            'morning'=>'How’s your morning going?',
            'evening'=>'How was your day?',
            default=>'Is there anything you’d like a hand with today?',
        };
    $thread=['version'=>1,'owner_id'=>(int)$scope['user']['id'],'agent_id'=>$scope['agent_id'],'id'=>$id,
        'theme_id'=>$theme['id'],'title'=>$title,'state'=>'open','created_at'=>$now,'updated_at'=>$now,'conversation_id'=>0,
        'focused'=>false,'sources'=>$theme['sources']??[],'questions'=>[]];
    if($day!==null)$thread+=['local_day'=>$day,'slot'=>$slot];
    $thread['questions'][]=vp3_curiosity_question_shape_v1($id,1,$prompt);
    return $thread;
}

function vp3_curiosity_question_shape_v1(string $threadId,int $position,string $prompt): array
{
    return ['id'=>vp3_curiosity_id_v1($threadId.'|'.$position),'question'=>$prompt,'state'=>'open',
        'conversation_id'=>0,'message_id'=>0,'reply_message_id'=>0,'posted_at'=>0];
}

function vp3_curiosity_question_view_v1(array $scope,array $thread,array $question,int $cid=0,?array $answerSource=null): array
{
    $linked=$question['conversation_id']??0;$mid=$question['message_id']??0;
    $row=$question+['thread_id'=>$thread['id'],'title'=>$thread['title'],'sources'=>$thread['sources']??[]];
    $row['conversation_id']=$linked>0?$linked:$cid;
    if(isset($thread['analysis_origin']['revision']))$row['analysis_revision']=$thread['analysis_origin']['revision'];
    unset($row['reply_hash'],$row['parent_question_id'],$row['reply_message_id']);
    if($answerSource!==null)$row['sources']=[$answerSource];
    if($linked>0&&$mid>0)$row['href']=vp3_curiosity_href_v1($scope,$linked,$mid);
    return $row;
}

function vp3_curiosity_live_question_post_v1(PDO $pdo,array $scope,array $question): bool
{
    $cid=$question['conversation_id']??0;$mid=$question['message_id']??0;
    if($cid<1||$mid<1||!vp3_agent_chat_conversation_v380($pdo,$cid,(int)$scope['user']['id'],$scope['agent']))return false;
    $read=$pdo->prepare("SELECT 1 FROM chat_messages WHERE id=? AND conversation_id=? AND role='assistant' AND user_id IS NULL");
    $read->execute([$mid,$cid]);
    return $read->fetchColumn()!==false;
}

/** Apply Chat spacing to saved offers without delaying source review or depending on replies. */
function vp3_curiosity_offer_spacing_v1(PDO $pdo,array $user,int $agentId,int $conversationId): string
{
    $scope=vp3_curiosity_private_scope_v1($pdo,$user,$agentId,$conversationId);
    $now=time();
    foreach(vp3_curiosity_read_threads_v1($pdo,$scope) as $thread){
        foreach($thread['questions'] as $question){
            $posted=$question['posted_at']??0;
            if(is_int($posted)&&$posted<=$now&&$posted>$now-VP3_CURIOSITY_OFFER_SPACING_SECONDS_V1
                &&vp3_curiosity_live_question_post_v1($pdo,$scope,$question))return 'recent_proactive_post';
        }
    }
    return 'ready';
}

/** Follow-ups can cross Chats owned by the same Agent, while answer evidence stays at its origin. */
function vp3_curiosity_parent_source_v1(PDO $pdo,array $scope,array $thread,array $question): ?array
{
    $index=array_search($question['id'],array_column($thread['questions'],'id'),true);
    $parent=$index!==false&&$index>0?$thread['questions'][$index-1]:null;
    if(($parent['id']??null)!==($question['parent_question_id']??null)
        ||($parent['state']??null)!=='answered'||!isset($parent['reply_hash'])
        ||!vp3_curiosity_live_question_post_v1($pdo,$scope,$parent))return null;
    $cid=$parent['conversation_id'];$mid=$parent['reply_message_id'];
    $reply=vp3_curiosity_reply_source_v1($pdo,$scope,$cid,$mid,$parent['reply_hash'],$parent['message_id']);
    if($reply===null||vp3_curiosity_safe_text_v1($reply['message'],400)==='')return null;
    return ['label'=>'View previous answer','conversation_id'=>$cid,'message_id'=>$mid,'href'=>vp3_curiosity_href_v1($scope,$cid,$mid)];
}

function vp3_curiosity_thread_view_v1(PDO $pdo,array $scope,array $thread): array
{
    $questions=[];
    foreach($thread['questions'] as $question){
        $source=isset($question['parent_question_id'])?vp3_curiosity_parent_source_v1($pdo,$scope,$thread,$question):null;
        if(isset($question['parent_question_id'])&&$source===null)continue;
        $view=vp3_curiosity_question_view_v1($scope,$thread,$question,0,$source);
        if($view['message_id']>0&&!vp3_curiosity_live_question_post_v1($pdo,$scope,$view)){
            unset($view['href']);$view['message_id']=0;$view['conversation_id']=0;
        }elseif(isset($question['reply_hash'])){
            $reply=vp3_curiosity_reply_source_v1($pdo,$scope,$question['conversation_id'],$question['reply_message_id'],
                $question['reply_hash'],$question['message_id']);
            if($reply!==null){
                $view['reply_message_id']=$question['reply_message_id'];
                $view['reply_href']=vp3_curiosity_href_v1($scope,$question['conversation_id'],$question['reply_message_id']);
                if($view['state']==='answered'&&($answer=vp3_curiosity_safe_text_v1($reply['message'],400))!=='')$view['answer']=$answer;
            }
        }
        $questions[]=$view;
    }
    $last=end($questions);
    $state=$thread['state']==='open'?($last['state']??'open'):$thread['state'];
    return ['id'=>$thread['id'],'theme_id'=>$thread['theme_id'],'title'=>$thread['title'],'state'=>$state,
        'progress'=>['answered'=>count(array_filter($questions,static fn(array $q):bool=>$q['state']==='answered')),
            'total'=>!isset($thread['analysis_origin'])&&($thread['focused']??false)&&$state==='open'?3:count($questions)],'questions'=>$questions];
}

function vp3_curiosity_thread_source_current_v1(PDO $pdo,array $scope,array $thread,?array $documents=null): bool
{
    if(!isset($thread['analysis_origin']))return true;
    require_once __DIR__.'/agent-initiative-curiosity-analysis-v1.php';
    return vp3_curiosity_analysis_thread_current_v1($pdo,$scope,$thread,$documents);
}

/** Share one current document projection inside a read; never cache it across authority checks. */
function vp3_curiosity_thread_documents_v1(PDO $pdo,array $scope,array $threads): array
{
    if(!array_filter($threads,static fn(array $thread):bool=>isset($thread['analysis_origin'])))return [];
    require_once __DIR__.'/agent-initiative-curiosity-analysis-v1.php';
    return vp3_curiosity_document_reports_v1($pdo,$scope['user'],vp3_initiative_policy_v1($scope['user'],$scope['agent_id']));
}

/** Keep earlier questions replyable without letting them block later check-ins. */
function vp3_curiosity_pending_today_v1(array $thread,array $question,DateTimeImmutable $local): bool
{
    $posted=$question['posted_at']??0;
    $day=$local->format('Y-m-d');
    if(is_int($posted)&&$posted>0)return $local->setTimestamp($posted)->format('Y-m-d')===$day;
    return !isset($thread['local_day'])||$thread['local_day']===$day;
}

function vp3_curiosity_select_v1(PDO $pdo,array $scope,array $themes,array $threads,int $now,bool $includeEarlierTopics=false,?array $documents=null): array
{
    $cid=$scope['conversation_id'];
    if($cid<1)return [null,null,null,null];
    require_once __DIR__.'/agent-morning-brief-v1.php';
    $local=vp3_morning_brief_local_v1($pdo,$scope['user'],new DateTimeImmutable('@'.$now));
    $day=$local->format('Y-m-d');
    $continuation=null;$pending=null;
    foreach($threads as $thread){
        if($thread['state']!=='open')continue;
        if(!vp3_curiosity_thread_source_current_v1($pdo,$scope,$thread,$documents))continue;
        $last=end($thread['questions']);
        if(($last['state']??null)!=='open')continue;
        $source=isset($last['parent_question_id'])?vp3_curiosity_parent_source_v1($pdo,$scope,$thread,$last):null;
        if(isset($last['parent_question_id'])&&$source===null)continue;
        if(($last['message_id']??0)>0){
            // A briefing can link an earlier topic even before the owner has focused it.
            $today=vp3_curiosity_pending_today_v1($thread,$last,$local);
            if(!($today||($includeEarlierTopics&&(!isset($thread['local_day'])||($thread['focused']??false))))
                ||!vp3_curiosity_live_question_post_v1($pdo,$scope,$last))continue;
            $pending??=vp3_curiosity_question_view_v1($scope,$thread,$last,0,$source);
            continue;
        }
        if($continuation===null&&($thread['focused']??false))$continuation=[$thread,$source];
    }
    $attention=$pending===null?null:'awaiting_reply';
    if($continuation!==null)return [$continuation[0],$attention,$pending,$continuation[1]];
    foreach($themes as $theme){
        $id=vp3_curiosity_thread_id_v1($scope,$theme['id']);
        if(isset($threads[$id]))continue;
        return [vp3_curiosity_new_thread_v1($scope,$theme,$now),$attention,$pending,null];
    }
    $definitions=vp3_curiosity_theme_definitions_v1();
    foreach(VP3_CURIOSITY_STARTER_QUESTIONS_V1 as $id=>$prompt){
        if(isset($threads[vp3_curiosity_thread_id_v1($scope,$id)]))continue;
        $starter=['id'=>$id,'title'=>$definitions[$id][0],'starter_question'=>$prompt];
        return [vp3_curiosity_new_thread_v1($scope,$starter,$now),$attention,$pending,null];
    }
    $hour=(int)$local->format('G');
    $slot=($hour>=8&&$hour<12)?'morning':(($hour>=17&&$hour<21)?'evening':'general');
    $daily=['id'=>'daily-checkin','title'=>'Daily check-in'];
    $dailyIds=array_map(static fn(string $slot):string=>vp3_curiosity_thread_id_v1($scope,'daily-checkin:'.$day.':'.$slot),VP3_CURIOSITY_DAILY_SLOTS_V1);
    if(array_intersect_key($threads,array_fill_keys($dailyIds,true)))return [null,$attention,$pending,null];
    return [vp3_curiosity_new_thread_v1($scope,$daily,$now,$day,$slot),$attention,$pending,null];
}

function vp3_curiosity_report_v1(PDO $pdo,array $user,int $agentId,int $conversationId,?int $now=null,
    bool $includeEarlierTopics=false): array
{
    $now??=time();$scope=vp3_curiosity_private_scope_v1($pdo,$user,$agentId,$conversationId);
    $empty=['themes'=>[],'threads'=>[],'next_question'=>null,'pending_question'=>null,'source_coverage'=>'unavailable','attention'=>null];
    try{
        $threads=vp3_curiosity_read_threads_v1($pdo,$scope);
        $documents=vp3_curiosity_thread_documents_v1($pdo,$scope,$threads);
        $themes=vp3_curiosity_observed_v1($pdo,$scope,$now);
        [$proposed,$attention,$pending,$source]=vp3_curiosity_select_v1($pdo,$scope,$themes,$threads,$now,$includeEarlierTopics,$documents);
        foreach($threads as $thread){
            foreach(['goal','taste'] as $kind){
                $confirmed=vp3_curiosity_confirmed_row_v1($pdo,$scope,$thread,$kind,false,$documents);
                if($confirmed===null)continue;
                $key=$thread['theme_id'];
                $themes[$key]??=['id'=>$key,'title'=>$thread['title'],'occurrences'=>0,'sources'=>[]];
                if(($themes[$key]['state']??null)!=='confirmed')$themes[$key]['summary']=$confirmed['summary'];
                $themes[$key]['state']='confirmed';
                $themes[$key]['confirmations'][]=['kind'=>$kind,'summary'=>$confirmed['summary'],'href'=>$confirmed['href']];
                $themes[$key]['sources'][]=['label'=>$kind==='goal'?'Owner confirmed goal':'Owner stated preference',
                    'conversation_id'=>$confirmed['conversation_id'],'message_id'=>$confirmed['message_id'],'href'=>$confirmed['href']];
            }
        }
        $visible=[];
        foreach($threads as $thread){
            if(!vp3_curiosity_thread_source_current_v1($pdo,$scope,$thread,$documents))continue;
            $visible[]=vp3_curiosity_thread_view_v1($pdo,$scope,$thread);
            if(count($visible)>=12)break;
        }
        return ['themes'=>array_values($themes),'threads'=>$visible,'source_coverage'=>'ready','attention'=>$attention,'pending_question'=>$pending,
            'next_question'=>$proposed===null?null:vp3_curiosity_question_view_v1($scope,$proposed,end($proposed['questions']),$conversationId,$source)];
    }catch(RuntimeException|JsonException $error){
        return $empty;
    }
}

function vp3_curiosity_status_v1(PDO $pdo,array $user,int $agentId,int $conversationId): array
{
    try{
        $scope=vp3_curiosity_private_scope_v1($pdo,$user,$agentId,$conversationId);
        require_once __DIR__.'/agent-morning-brief-v1.php';
        $local=vp3_morning_brief_local_v1($pdo,$scope['user'],new DateTimeImmutable());
        $threads=vp3_curiosity_read_threads_v1($pdo,$scope);$documents=vp3_curiosity_thread_documents_v1($pdo,$scope,$threads);
        foreach($threads as $thread){
            if(!vp3_curiosity_thread_source_current_v1($pdo,$scope,$thread,$documents))continue;
            $last=end($thread['questions']);
            if($thread['state']==='open'&&($last['state']??null)==='open'&&vp3_curiosity_pending_today_v1($thread,$last,$local)
                &&vp3_curiosity_live_question_post_v1($pdo,$scope,$last)
                &&(!isset($last['parent_question_id'])||vp3_curiosity_parent_source_v1($pdo,$scope,$thread,$last)!==null)){
                $detail=($last['conversation_id']??null)===$conversationId
                    ?'An earlier question is open in this Chat. Curiosity continues to explore other topics.'
                    :'An earlier question is open in another saved Chat. Curiosity continues to explore other topics.';
                return ['state'=>'checked','detail'=>$detail];
            }
        }
        return ['state'=>'idle','detail'=>'Curiosity reviews recurring owner activity for useful questions.'];
    }catch(RuntimeException|JsonException $error){return ['state'=>'unavailable','detail'=>'Curiosity could not be checked.'];}
}

function vp3_curiosity_question_v1(PDO $pdo,array $user,int $agentId,int $conversationId,string $threadId,string $questionId,?int $now=null): ?array
{
    vp3_curiosity_ref_v1($threadId,$questionId);
    $now??=time();$scope=vp3_curiosity_private_scope_v1($pdo,$user,$agentId,$conversationId);
    $stored=vp3_curiosity_read_threads_v1($pdo,$scope,[$threadId])[$threadId]??null;
    if($stored!==null){
        if(!vp3_curiosity_thread_source_current_v1($pdo,$scope,$stored))return null;
        foreach($stored['questions'] as $question){
            if($question['id']!==$questionId)continue;
            $linked=$question['conversation_id'];
            if($linked!==0&&$linked!==$conversationId)return null;
            if($linked!==0&&!vp3_curiosity_live_question_post_v1($pdo,$scope,$question))return null;
            $source=isset($question['parent_question_id'])?vp3_curiosity_parent_source_v1($pdo,$scope,$stored,$question):null;
            if(isset($question['parent_question_id'])&&$source===null)return null;
            return vp3_curiosity_question_view_v1($scope,$stored,$question,$conversationId,$source);
        }
    }
    $candidate=vp3_curiosity_report_v1($pdo,$user,$agentId,$conversationId,$now)['next_question'];
    return ($candidate['thread_id']??null)===$threadId&&($candidate['id']??null)===$questionId?$candidate:null;
}

/** Called by canonical Initiative delivery in the same owner-locked transaction. */
function vp3_curiosity_record_offer_v1(PDO $pdo,array $user,int $agentId,int $conversationId,string $threadId,string $questionId,int $assistantMessageId,?int $now=null): void
{
    if(!$pdo->inTransaction()||$assistantMessageId<1)throw new RuntimeException('The Curiosity post needs a saved Chat message.',409);
    $now??=time();$ref=vp3_curiosity_ref_v1($threadId,$questionId);
    $scope=vp3_curiosity_private_scope_v1($pdo,$user,$agentId,$conversationId);
    $threads=vp3_curiosity_read_threads_v1($pdo,$scope);
    if(isset($threads[$threadId])){
        foreach($threads[$threadId]['questions'] as $saved){
            if($saved['id']===$questionId&&$saved['message_id']===$assistantMessageId&&$saved['conversation_id']===$conversationId)return;
        }
    }
    [$thread]=vp3_curiosity_select_v1($pdo,$scope,vp3_curiosity_observed_v1($pdo,$scope,$now),$threads,$now);
    if(($thread['id']??null)!==$threadId)throw new RuntimeException('The current Curiosity topic changed.',409);
    $index=count($thread['questions'])-1;$question=$thread['questions'][$index];
    if($question['id']!==$questionId)throw new RuntimeException('The current Curiosity question changed.',409);
    $read=$pdo->prepare("SELECT context_json FROM chat_messages WHERE id=? AND conversation_id=? AND role='assistant' AND user_id IS NULL");
    $read->execute([$assistantMessageId,$conversationId]);
    $context=json_decode((string)$read->fetchColumn(),true,32);
    $output=$context['initiative_output']??null;
    if(($context['source']??null)!=='agent_initiative'||($output['question']??null)!==$question['question']
        ||!is_array($output['evidence_revisions']??null)||!isset($output['evidence_revisions'][$ref])){
        throw new RuntimeException('The Curiosity question does not match its Chat post.',409);
    }
    $question['conversation_id']=$conversationId;$question['message_id']=$assistantMessageId;$question['posted_at']=$now;
    $thread['questions'][$index]=$question;$thread['conversation_id']=$conversationId;$thread['updated_at']=$now;
    vp3_curiosity_write_thread_v1($scope,$thread);
}

function vp3_curiosity_answer_is_actionable_v1(string $text): bool
{
    return mb_strlen($text,'UTF-8')>=12&&preg_match('~\A(?:i (?:want|need|would like|plan|intend) to |my goal is |please (?:help|prepare|draft|make|research|compare|build|review) |(?:finish|build|compare|plan|research|prepare|draft|organize|review) )\S~iu',$text)===1;
}

function vp3_curiosity_taste_v1(string $text): bool
{
    return mb_strlen($text,'UTF-8')>=12&&preg_match('/\AI (?:like|love|enjoy|prefer) \S/iu',$text)===1
        &&!preg_match('/\b(?:today|tonight|this week|gift|for someone|for somebody|my friend|my child)\b/iu',$text);
}

function vp3_curiosity_followup_prompt_v1(array $thread,int $position,string $answer,bool $actionable): ?string
{
    if(isset($thread['analysis_origin']))return null;
    if($position>=3)return null;
    if($position===2){
        if(!$actionable&&!preg_match('/\b(?:checklist|example|option|plan|first step|next step|ideas)\b/iu',$answer))return null;
        if(preg_match('/\bchecklist\b/iu',$answer))return 'What should the first item on that checklist be?';
        if(preg_match('/\b(?:example|ideas|options)\b/iu',$answer))return 'What would make an example or option useful to you?';
        return 'What would make that first step a good fit for you?';
    }
    $taste=vp3_curiosity_taste_v1($answer);
    if($actionable||$taste){
        if(preg_match('/\b(?:music|songs?|songwriting|albums?)\b/iu',$answer)
            &&preg_match('/\b(?:mak(?:e|ing)|writ(?:e|ing)|record(?:ing)?|produc(?:e|ing)|compos(?:e|ing)|songwriting)\b/iu',$answer)){
            return 'What would you most like to work on in your music next, such as songwriting or recording?';
        }
        if(preg_match('/\bjazz\b/iu',$answer))return 'Is there a jazz artist or style you’d like to explore next?';
        if(preg_match('/\b(?:deadlines?|due dates?)\b/iu',$answer))return 'Which deadline or follow-up would you like to plan around first?';
        return match($thread['theme_id']){
            'food'=>'What dish, ingredient, or cooking technique would you like to start with?',
            'film'=>'Which kinds of films or shows would you most like to explore?',
            'media'=>'What kind of music or video would you like to start with?',
            'hobbies'=>'What would you enjoy trying next in that hobby?',
            'local'=>'What city or region would you like to use for recommendations?',
            default=>$actionable?'Would a checklist, an example, or help choosing a first step be most useful?'
                :'What part of that interest would you like to explore next?',
        };
    }
    return $thread['theme_id']!=='daily-checkin'&&preg_match('/\A(?:yes|sure|okay|ok|let[’\x27]s focus)[.!]?\z/iu',$answer)
        ?'What is one specific outcome you’d like to work toward?':null;
}

function vp3_curiosity_apply_reply_v1(PDO $pdo,array $user,int $agentId,int $conversationId,string $threadId,string $questionId,
    int $replyMessageId,string $disposition,?int $now=null): array
{
    if(!in_array($disposition,['answered','skipped','deferred'],true)||$replyMessageId<1)throw new InvalidArgumentException('Choose a Curiosity response.');
    $now??=time();$ref=vp3_curiosity_ref_v1($threadId,$questionId);
    require_once __DIR__.'/agent-initiative-followthrough-v1.php';
    return agent_commerce_atomic_pa2($pdo,static function()use($pdo,$user,$agentId,$conversationId,$threadId,$questionId,$replyMessageId,$disposition,$now,$ref):array{
        vp3_initiative_owner_lock_v1($pdo,$user);
        $scope=vp3_curiosity_private_scope_v1($pdo,$user,$agentId,$conversationId);
        $thread=vp3_curiosity_read_threads_v1($pdo,$scope,[$threadId])[$threadId]??null;
        if($thread===null)throw new RuntimeException('The Curiosity thread is unavailable.',404);
        if(!vp3_curiosity_thread_source_current_v1($pdo,$scope,$thread))throw new RuntimeException('The source for this Curiosity question changed.',409);
        $index=array_search($questionId,array_column($thread['questions'],'id'),true);
        if($index===false)throw new RuntimeException('The Curiosity question is unavailable.',404);
        $question=$thread['questions'][$index];
        if($question['conversation_id']!==$conversationId||$question['message_id']<1)throw new RuntimeException('The Curiosity question belongs to another Chat.',409);
        $proof=vp3_initiative_followthrough_anchor_identity_v1($pdo,$scope['user'],$scope['principal'],$conversationId,$replyMessageId);
        if(($proof['anchor']['initiative_reply']['message_id']??null)!==$question['message_id']
            ||($proof['loaded']['goal']['primary']??null)!==$ref){
            throw new RuntimeException('The reply is not linked to this Curiosity question.',409);
        }
        if($question['reply_message_id']===$replyMessageId)return vp3_curiosity_thread_view_v1($pdo,$scope,$thread);
        if($thread['state']!=='open'||$question['state']!=='open'||$index!==count($thread['questions'])-1){
            throw new RuntimeException('This Curiosity question is already closed.',409);
        }
        $read=$pdo->prepare("SELECT message,context_json FROM chat_messages WHERE id=? AND conversation_id=? AND user_id=? AND role='user'");
        $read->execute([$replyMessageId,$conversationId,(int)$scope['user']['id']]);
        $reply=$read->fetch(PDO::FETCH_ASSOC);
        if(!$reply||!is_string($reply['message']))throw new RuntimeException('The Curiosity answer is unavailable.',404);
        $raw=$reply['message'];
        $answer=vp3_curiosity_safe_text_v1($raw,400);
        $question['state']=$disposition;$question['reply_message_id']=$replyMessageId;$question['reply_hash']=hash('sha256',$raw);
        $thread['questions'][$index]=$question;$thread['updated_at']=$now;$thread['state']=$disposition;
        $actionable=$disposition==='answered'&&vp3_curiosity_answer_is_actionable_v1($answer);
        // An attachment answer describes this file/order, never a standing personal preference.
        if($actionable||(!isset($thread['analysis_origin'])&&$disposition==='answered'&&vp3_curiosity_taste_v1($answer))){
            $thread['confirmed'][$actionable?'goal':'taste']=['conversation_id'=>$conversationId,'message_id'=>$replyMessageId,
                'question_id'=>$questionId,'answer_hash'=>$question['reply_hash']];
        }
        $followup=$disposition==='answered'?vp3_curiosity_followup_prompt_v1($thread,$index+1,$answer,$actionable):null;
        if($followup!==null){
            $thread['focused']=true;$thread['state']='open';
            $thread['questions'][]=vp3_curiosity_question_shape_v1($threadId,$index+2,$followup)+['parent_question_id'=>$questionId];
        }
        $context=json_decode((string)$reply['context_json'],true,32,JSON_THROW_ON_ERROR);
        $context['skip_brain_archive']=true;
        $context['curiosity_reply_v1']=['thread_id'=>$threadId,'question_id'=>$questionId,'state'=>$disposition];
        $pdo->prepare('UPDATE chat_messages SET context_json=? WHERE id=? AND conversation_id=? AND user_id=?')
            ->execute([json_encode($context,JSON_THROW_ON_ERROR),$replyMessageId,$conversationId,(int)$scope['user']['id']]);
        vp3_curiosity_write_thread_v1($scope,$thread);
        require_once __DIR__.'/agent-coordination-source-change-v1.php';
        vp3_coordination_source_changed_v1($pdo,(int)$scope['user']['id'],$agentId);
        return vp3_curiosity_thread_view_v1($pdo,$scope,$thread);
    });
}

function vp3_curiosity_reply_source_v1(PDO $pdo,array $scope,int $cid,int $mid,string $answerHash,int $assistantId): ?array
{
    $owner=(int)$scope['user']['id'];
    if($mid<=$assistantId||!vp3_agent_chat_conversation_v380($pdo,$cid,$owner,$scope['agent']))return null;
    $read=$pdo->prepare("SELECT message,context_json FROM chat_messages WHERE id=? AND conversation_id=? AND user_id=? AND role='user'");
    $read->execute([$mid,$cid,$owner]);$row=$read->fetch(PDO::FETCH_ASSOC);
    if(!$row||!hash_equals($answerHash,hash('sha256',$row['message'])))return null;
    $context=json_decode((string)$row['context_json'],true,32);
    $anchor=$context['initiative_reply_anchor_v1']??[];
    if(($anchor['version']??null)!==1||($anchor['owner_user_id']??null)!==$owner||($anchor['agent_id']??null)!==$scope['agent_id']
        ||($anchor['conversation_id']??null)!==$cid||($anchor['user_message_id']??null)!==$mid
        ||($anchor['initiative_reply']['message_id']??null)!==$assistantId||($anchor['query_hash']??null)!==$answerHash)return null;
    return $row;
}

function vp3_curiosity_confirmed_row_v1(PDO $pdo,array $scope,array $thread,string $kind='goal',bool $honorStop=true,?array $documents=null): ?array
{
    if($kind==='taste'&&isset($thread['analysis_origin']))return null;
    if(!vp3_curiosity_thread_source_current_v1($pdo,$scope,$thread,$documents))return null;
    $proof=$thread['confirmed'][$kind]??null;
    if(!is_array($proof)||($honorStop&&in_array($thread['state'],['skipped','deferred'],true)))return null;
    $cid=$proof['conversation_id'];$mid=$proof['message_id'];
    $matched=null;
    foreach($thread['questions'] as $question){
        if($question['id']===$proof['question_id']&&$question['reply_message_id']===$mid&&$question['state']==='answered'){
            $matched=$question;break;
        }
    }
    if($matched===null||!vp3_curiosity_live_question_post_v1($pdo,$scope,$matched))return null;
    $row=vp3_curiosity_reply_source_v1($pdo,$scope,$cid,$mid,$proof['answer_hash'],$matched['message_id']);
    if($row===null)return null;
    $summary=vp3_curiosity_safe_text_v1($row['message'],400);
    if(!($kind==='goal'?vp3_curiosity_answer_is_actionable_v1($summary):vp3_curiosity_taste_v1($summary)))return null;
    return ['id'=>$thread['id'],'title'=>$thread['title'],'summary'=>$summary,'conversation_id'=>$cid,'message_id'=>$mid,
        'href'=>vp3_curiosity_href_v1($scope,$cid,$mid),'revision'=>hash('sha256',$thread['id'].'|'.$matched['id'].'|'.$mid.'|'.$proof['answer_hash'])];
}

/** Downstream readers only receive source-backed owner-stated goals, never passive observations. */
function vp3_curiosity_confirmed_v1(PDO $pdo,array $user,int $agentId,int $conversationId,?array $threadIds=null,string $kind='goal'): array
{
    if(!in_array($kind,['goal','taste'],true))throw new InvalidArgumentException('Choose a Curiosity finding type.');
    $scope=vp3_curiosity_private_scope_v1($pdo,$user,$agentId,$conversationId);$out=[];
    $threads=vp3_curiosity_read_threads_v1($pdo,$scope,$threadIds);$documents=vp3_curiosity_thread_documents_v1($pdo,$scope,$threads);
    foreach($threads as $id=>$thread){
        $row=vp3_curiosity_confirmed_row_v1($pdo,$scope,$thread,$kind,true,$documents);
        if($row!==null)$out[$id]=$row;
        if($threadIds===null&&count($out)>=8)break;
    }
    return $out;
}
