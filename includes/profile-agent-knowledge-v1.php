<?php
declare(strict_types=1);

require_once __DIR__.'/profile-agent-responses-v1.php';
require_once __DIR__.'/knowledge.php';
require_once __DIR__.'/user-agent-system-v236.php';
require_once __DIR__.'/shared-knowledge-index-v236.php';
require_once __DIR__.'/agent-observation-authority-v1.php';

/** Reviewed owner answers become ordinary personal Knowledge, with an exact source receipt. */
const VP3_PROFILE_KNOWLEDGE_V1='vp3.profile-knowledge.v1';

final class VP3ProfileKnowledgeException extends RuntimeException
{
    public function __construct(public readonly string $errorCode,string $message,public readonly int $status=409)
    {parent::__construct($message);}
}

function vp3_profile_knowledge_ready_v1(PDO $pdo): bool
{
    try{
        $pdo->query('SELECT owner_user_id,owner_request_id,conversation_id,visitor_message_id,reply_message_id,source_revision,intent_hash,knowledge_id,content_hash,folder_id,created_at_ms FROM profile_agent_knowledge_saves_v1 WHERE 1=0');
        $pdo->query('SELECT id,created_by_user_id,knowledge_scope,folder_id,title,description,file_name,file_path,file_type,mime_type,file_size,content_text,visibility,is_published,updated_at FROM knowledge_items WHERE 1=0');
        $pdo->query('SELECT id,knowledge_id,chunk_index,chunk_text FROM knowledge_chunks WHERE 1=0');
        $pdo->query('SELECT id,created_by_user_id,folder_name,sort_order,created_at,updated_at FROM artist_transcript_folders_v177 WHERE 1=0');
        $pdo->query('SELECT owner_user_id,resource_type,resource_id,audience_scope,owner_agents_allowed,profile_agent_allowed,stonefellow_shared FROM user_data_policies WHERE 1=0');
        $pdo->query('SELECT knowledge_id,owner_user_id,source_version_hash,title,topic_tags,embedding_ref,share_scope,is_indexed,last_indexed_at,revoked_at,created_at,updated_at FROM shared_knowledge_index WHERE 1=0');
        return vp3_profile_responses_ready_v1($pdo);
    }catch(PDOException){return false;}
}

/** Setup/upgrade owns DDL; the canonical Knowledge and folder tables remain unchanged. */
function vp3_profile_knowledge_install_v1(PDO $pdo): void
{
    if($pdo->inTransaction())throw new RuntimeException('Install Profile Knowledge filing outside transactions.');
    $suffix=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
    $pdo->exec("CREATE TABLE IF NOT EXISTS profile_agent_knowledge_saves_v1 (
        owner_user_id BIGINT NOT NULL,owner_request_id BIGINT NOT NULL,conversation_id BIGINT NOT NULL,
        visitor_message_id BIGINT NOT NULL,reply_message_id BIGINT NOT NULL,
        source_revision CHAR(64) NOT NULL,intent_hash CHAR(64) NOT NULL,knowledge_id BIGINT NOT NULL,
        content_hash CHAR(64) NOT NULL,folder_id BIGINT NOT NULL DEFAULT 0,
        created_at_ms BIGINT NOT NULL,PRIMARY KEY(owner_user_id,owner_request_id),UNIQUE(knowledge_id)
    )".$suffix);
}

function vp3_profile_knowledge_require_v1(PDO $pdo): void
{
    if(!vp3_profile_knowledge_ready_v1($pdo))throw new VP3ProfileKnowledgeException('PROFILE_KNOWLEDGE_UPGRADE_REQUIRED','Save to Knowledge needs Database Upgrade, including the existing Knowledge folders and index.',503);
}

/** Current account and current SQL permissions, never a posted owner or cached role. */
function vp3_profile_knowledge_owner_v1(PDO $pdo,array $user,bool $lock=false): array
{
    if(function_exists('reset_current_user_cache'))reset_current_user_cache();
    $owner=(int)($user['id']??0);$session=function_exists('current_user')?current_user():null;
    if($owner<1||!$session||(int)$session['id']!==$owner
        ||(function_exists('vp3_participant_scope_v1')&&vp3_participant_scope_v1()!==null))throw new VP3ProfileKnowledgeException('PROFILE_KNOWLEDGE_FORBIDDEN','Save Profile answers from your private owner workspace.',403);
    if($lock){
        if(!$pdo->inTransaction())throw new LogicException('Profile Knowledge requires the owner transaction.');
        if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$pdo->prepare('UPDATE users SET id=id WHERE id=?')->execute([$owner]);
    }
    $q=$pdo->prepare('SELECT * FROM users WHERE id=?'.($lock?vp3_profile_responses_lock_v1($pdo):''));$q->execute([$owner]);$live=$q->fetch(PDO::FETCH_ASSOC);
    if(!$live||empty($live['is_active'])||!function_exists('has_permission')||!has_permission('account.access',$live))throw new VP3ProfileKnowledgeException('PROFILE_KNOWLEDGE_FORBIDDEN','Your account cannot save this Profile answer.',403);
    foreach(['profile_agent.access','profile_chat.access','personal_knowledge.access','personal_knowledge.manage'] as $permission){
        if(!vp3_observation_personal_permission_v1($pdo,$live,$permission))throw new VP3ProfileKnowledgeException('PROFILE_KNOWLEDGE_FORBIDDEN','Profile chat and Personal Knowledge management access are required.',403);
    }
    return $live;
}

/** An answered request points to one saved visitor question and one actual owner message. */
function vp3_profile_knowledge_source_v1(PDO $pdo,int $owner,int $cid,int $requestId,bool $lock=false): array
{
    if($owner<1||$cid<1||$requestId<1)throw new VP3ProfileKnowledgeException('PROFILE_KNOWLEDGE_SOURCE_UNAVAILABLE','Choose an answered request from this Profile conversation.',404);
    $q=$pdo->prepare('SELECT r.*,c.profile_agent_id AS conversation_agent_id,c.visitor_user_id AS conversation_visitor_id,
        q.sender_type AS question_role,q.sender_user_id AS question_user_id,q.message AS question,
        a.sender_type AS answer_role,a.sender_user_id AS answer_user_id,a.message AS reply
        FROM profile_agent_owner_requests_v1 r
        INNER JOIN profile_agent_conversations c ON c.id=r.conversation_id AND c.owner_user_id=r.owner_user_id
        INNER JOIN profile_agent_messages q ON q.id=r.visitor_message_id AND q.conversation_id=r.conversation_id
        INNER JOIN profile_agent_messages a ON a.id=r.reply_message_id AND a.conversation_id=r.conversation_id
        WHERE r.owner_user_id=? AND r.conversation_id=? AND r.id=?'.($lock?vp3_profile_responses_lock_v1($pdo):''));
    $q->execute([$owner,$cid,$requestId]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row||$row['status']!=='answered'||(int)$row['profile_agent_id']!==(int)$row['conversation_agent_id']
        ||$row['question_role']!=='visitor'||(int)($row['question_user_id']??0)!==(int)($row['conversation_visitor_id']??0)
        ||$row['answer_role']!=='owner'||(int)$row['answer_user_id']!==$owner||(int)$row['reply_message_id']<=(int)$row['visitor_message_id']
        ||!is_string($row['question'])||!is_string($row['reply'])||trim($row['question'])===''||trim($row['reply'])===''
        ||strlen($row['question'])>65536||strlen($row['reply'])>65536)throw new VP3ProfileKnowledgeException('PROFILE_KNOWLEDGE_SOURCE_UNAVAILABLE','Choose an answered request with an owner reply from this conversation.',404);
    $row['source_revision']=hash('sha256',json_encode([VP3_PROFILE_KNOWLEDGE_V1,$owner,$requestId,$cid,(int)$row['profile_agent_id'],(int)$row['revision'],
        (int)$row['visitor_message_id'],(int)$row['reply_message_id'],(int)($row['question_user_id']??0),(int)$row['answer_user_id'],$row['question'],$row['reply']],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    return $row;
}

function vp3_profile_knowledge_source_projection_v1(array $source): array
{
    return ['contract'=>VP3_PROFILE_KNOWLEDGE_V1,'conversation_id'=>(int)$source['conversation_id'],'owner_request_id'=>(int)$source['id'],
        'visitor_message_id'=>(int)$source['visitor_message_id'],'reply_message_id'=>(int)$source['reply_message_id'],'source_revision'=>$source['source_revision']];
}

/** Optional owner history metadata. Never call from a public visitor projection. */
function vp3_profile_knowledge_messages_v1(PDO $pdo,int $owner,int $cid,array $messages): array
{
    $replyIds=[];
    foreach($messages as &$message){
        if(!is_array($message))continue;
        unset($message['knowledge_requests']);
        if(($message['sender_type']??null)!=='owner')continue;
        $message['knowledge_requests']=[];$id=$message['id']??null;
        if(is_int($id)&&$id>0)$replyIds[$id]=$id;
        elseif(is_string($id)&&preg_match('/\A[1-9][0-9]{0,17}\z/D',$id))$replyIds[(int)$id]=(int)$id;
    }unset($message);
    if($owner<1||$cid<1||!$replyIds)return $messages;
    try{
        vp3_profile_knowledge_owner_v1($pdo,['id'=>$owner]);
        if(!vp3_profile_knowledge_ready_v1($pdo))return $messages;
        $requests=[];
        // Canonical owner histories are bounded. Chunking IDs also keeps a
        // larger internal caller within database parameter limits without
        // dropping earlier answered requests from a rendered owner reply.
        foreach(array_chunk(array_values($replyIds),200) as $ids){
            $marks=implode(',',array_fill(0,count($ids),'?'));
            $q=$pdo->prepare("SELECT r.id,r.conversation_id,r.visitor_message_id,r.reply_message_id,r.revision,SUBSTR(q.message,1,400) AS question_excerpt
                FROM profile_agent_owner_requests_v1 r
                INNER JOIN profile_agent_conversations c ON c.id=r.conversation_id AND c.owner_user_id=r.owner_user_id AND c.profile_agent_id=r.profile_agent_id
                INNER JOIN profile_agent_messages q ON q.id=r.visitor_message_id AND q.conversation_id=r.conversation_id
                  AND q.sender_type='visitor' AND COALESCE(q.sender_user_id,0)=COALESCE(c.visitor_user_id,0)
                INNER JOIN profile_agent_messages a ON a.id=r.reply_message_id AND a.conversation_id=r.conversation_id
                  AND a.sender_type='owner' AND a.sender_user_id=r.owner_user_id
                WHERE r.owner_user_id=? AND r.conversation_id=? AND r.status='answered'
                  AND r.visitor_message_id<r.reply_message_id AND r.reply_message_id IN ($marks)
                ORDER BY r.visitor_message_id,r.id");
            $q->execute([$owner,$cid,...$ids]);
            foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
                $excerpt=trim((string)preg_replace('/\s+/u',' ',(string)$row['question_excerpt']));
                if($excerpt==='')continue;
                $requests[(int)$row['reply_message_id']][]=['id'=>(int)$row['id'],'conversation_id'=>(int)$row['conversation_id'],
                    'visitor_message_id'=>(int)$row['visitor_message_id'],'reply_message_id'=>(int)$row['reply_message_id'],'revision'=>(int)$row['revision'],
                    'question_excerpt'=>mb_strimwidth($excerpt,0,240,'…')];
            }
        }
        vp3_profile_knowledge_owner_v1($pdo,['id'=>$owner]);
        foreach($messages as &$message){
            if(is_array($message)&&($message['sender_type']??null)==='owner')$message['knowledge_requests']=$requests[(int)($message['id']??0)]??[];
        }unset($message);
    }catch(VP3ProfileKnowledgeException|PDOException){
        // Missing optional access/schema must not hide the underlying chat.
        // Preview/save still enforce their normal explicit authorization.
    }
    return $messages;
}

function vp3_profile_knowledge_key_v1(int $owner,int $requestId): string
{
    return 'profile-owner-response-v1:'.$owner.':'.$requestId;
}

function vp3_profile_knowledge_saved_v1(PDO $pdo,array $user,array $link): array
{
    $owner=(int)$user['id'];$id=(int)$link['knowledge_id'];
    $q=$pdo->prepare("SELECT id,title,folder_id,file_name FROM knowledge_items WHERE id=? AND created_by_user_id=? AND knowledge_scope='personal' AND file_type='personal_note'");$q->execute([$id,$owner]);$item=$q->fetch(PDO::FETCH_ASSOC);
    $marker=personal_knowledge_note_marker(vp3_profile_knowledge_key_v1($owner,(int)$link['owner_request_id']));
    if(!$item||!hash_equals($marker,(string)$item['file_name']))throw new VP3ProfileKnowledgeException('PROFILE_KNOWLEDGE_SAVED_UNAVAILABLE','The linked Knowledge note changed or was removed. Review it in Knowledge.',409);
    $folderId=(int)($item['folder_id']??0);$folder=$folderId>0?personal_knowledge_folder($pdo,$user,$folderId):null;
    if($folderId>0&&!$folder)throw new VP3ProfileKnowledgeException('PROFILE_KNOWLEDGE_FOLDER_UNAVAILABLE','The saved Knowledge folder is no longer available.',409);
    $policy=user_data_policy_get_v236($pdo,$owner,'knowledge',(string)$id);
    return ['knowledge_id'=>$id,'title'=>(string)$item['title'],'folder_id'=>$folderId,'folder_name'=>(string)($folder['folder_name']??'Unfiled'),
        'view_url'=>url('/knowledge.php?'.http_build_query(['folder'=>$folderId>0?$folderId:'unfiled','edit'=>$id]).'#knowledge-form'),
        'profile_agent_allowed'=>(bool)$policy['profile_agent_allowed'],'audience_scope'=>(string)$policy['audience_scope']];
}

function vp3_profile_knowledge_preview_v1(PDO $pdo,array $user,int $cid,int $ownerRequestId): array
{
    $live=vp3_profile_knowledge_owner_v1($pdo,$user);vp3_profile_knowledge_require_v1($pdo);
    $source=vp3_profile_knowledge_source_v1($pdo,(int)$live['id'],$cid,$ownerRequestId);
    $q=$pdo->prepare('SELECT * FROM profile_agent_knowledge_saves_v1 WHERE owner_user_id=? AND owner_request_id=?');$q->execute([(int)$live['id'],$ownerRequestId]);$link=$q->fetch(PDO::FETCH_ASSOC);
    $saved=$link?vp3_profile_knowledge_saved_v1($pdo,$live,$link):null;
    $title=mb_strimwidth(trim((string)preg_replace('/\s+/u',' ',$source['question'])),0,190,'…');
    $folders=array_map(static fn(array $folder): array=>['id'=>(int)$folder['id'],'name'=>(string)$folder['folder_name']],personal_knowledge_folders($pdo,$live));
    vp3_profile_knowledge_owner_v1($pdo,$live);
    return vp3_profile_knowledge_source_projection_v1($source)+['question'=>$source['question'],'reply'=>$source['reply'],'title'=>$title,
        'content'=>"Question: ".$source['question']."\n\nAnswer: ".$source['reply'],'folders'=>$folders,'saved'=>$saved];
}

/** Review saves an ordinary personal note; existing Knowledge Access governs all reuse. */
function vp3_profile_knowledge_save_v1(PDO $pdo,array $user,array $input): array
{
    $allowed=['action','csrf_token','conversation_id','owner_request_id','source_revision','title','content','folder_id','approve_content'];
    foreach(array_keys($input) as $key)if(!in_array($key,$allowed,true))throw new VP3ProfileKnowledgeException('PROFILE_KNOWLEDGE_INVALID','Choose the reviewed Profile answer and Knowledge destination.',422);
    foreach(['conversation_id','owner_request_id'] as $field)if(!is_int($input[$field]??null)||$input[$field]<1)throw new VP3ProfileKnowledgeException('PROFILE_KNOWLEDGE_INVALID','Choose an answered Profile request.',422);
    if(!is_int($input['folder_id']??null)||$input['folder_id']<0||!is_string($input['source_revision']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$input['source_revision'])
        ||($input['approve_content']??null)!==true)throw new VP3ProfileKnowledgeException('PROFILE_KNOWLEDGE_REVIEW_REQUIRED','Review the content and choose a folder from your Knowledge library.',422);
    foreach(['title'=>190,'content'=>32768] as $field=>$limit){
        if(!is_string($input[$field]??null)||!mb_check_encoding($input[$field],'UTF-8')||trim($input[$field])===''||mb_strlen($input[$field])>$limit||str_contains($input[$field],"\0"))throw new VP3ProfileKnowledgeException('PROFILE_KNOWLEDGE_INVALID','Enter a title of up to 190 characters and reviewed content of up to 32768 characters.',422);
    }
    if(!function_exists('db')||db()!==$pdo)throw new LogicException('Profile Knowledge filing requires the canonical database connection.');
    $live=vp3_profile_knowledge_owner_v1($pdo,$user);vp3_profile_knowledge_require_v1($pdo);
    return vp3_profile_responses_atomic_v1($pdo,function()use($pdo,$live,$input){
        $live=vp3_profile_knowledge_owner_v1($pdo,$live,true);$owner=(int)$live['id'];
        // The explicit readiness check precedes the canonical store, whose
        // legacy index helper could otherwise attempt an implicit schema install.
        vp3_profile_knowledge_require_v1($pdo);
        $source=vp3_profile_knowledge_source_v1($pdo,$owner,$input['conversation_id'],$input['owner_request_id'],true);
        if(!hash_equals($source['source_revision'],$input['source_revision']))throw new VP3ProfileKnowledgeException('PROFILE_KNOWLEDGE_SOURCE_CHANGED','The question or owner reply changed. Open Save to Knowledge again and review it.',409);
        $folderId=$input['folder_id'];$folder=$folderId>0?personal_knowledge_folder($pdo,$live,$folderId,true):null;
        if($folderId>0&&!$folder)throw new VP3ProfileKnowledgeException('PROFILE_KNOWLEDGE_FOLDER_UNAVAILABLE','Choose a folder from your own Knowledge library.',403);
        $title=trim((string)preg_replace('/\s+/u',' ',$input['title']));$content=trim($input['content']);
        $intent=hash('sha256',json_encode([$source['source_revision'],$title,$content,$folderId],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        $q=$pdo->prepare('SELECT * FROM profile_agent_knowledge_saves_v1 WHERE owner_user_id=? AND owner_request_id=?'.vp3_profile_responses_lock_v1($pdo));$q->execute([$owner,$input['owner_request_id']]);$prior=$q->fetch(PDO::FETCH_ASSOC);
        if($prior){
            if(!hash_equals((string)$prior['intent_hash'],$intent))throw new VP3ProfileKnowledgeException('PROFILE_KNOWLEDGE_ALREADY_SAVED','This answer is already saved. Open its Knowledge note to make further changes.',409);
            $saved=vp3_profile_knowledge_saved_v1($pdo,$live,$prior);vp3_profile_knowledge_owner_v1($pdo,$live);
            return vp3_profile_knowledge_source_projection_v1($source)+['saved'=>$saved,'created'=>false];
        }
        $key=vp3_profile_knowledge_key_v1($owner,$input['owner_request_id']);
        if(personal_knowledge_note_by_key($pdo,$live,$key))throw new VP3ProfileKnowledgeException('PROFILE_KNOWLEDGE_ALREADY_SAVED','This answer already has a Knowledge note. Review it in Knowledge before saving again.',409);
        $knowledgeId=personal_knowledge_store($live,$key,$title,$content,'Source: reviewed Profile question and owner response.',$folderId);
        // Saving creates no grants, denials or alternate audience state. The
        // normal note visibility, existing Knowledge Access and Agent rules
        // remain the sole authority, including future changes or revocation.
        $now=(int)floor(microtime(true)*1000);
        $pdo->prepare('INSERT INTO profile_agent_knowledge_saves_v1(owner_user_id,owner_request_id,conversation_id,visitor_message_id,reply_message_id,source_revision,intent_hash,knowledge_id,content_hash,folder_id,created_at_ms) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$owner,$input['owner_request_id'],(int)$source['conversation_id'],(int)$source['visitor_message_id'],(int)$source['reply_message_id'],$source['source_revision'],$intent,$knowledgeId,hash('sha256',$content),$folderId,$now]);
        $link=['owner_request_id'=>$input['owner_request_id'],'knowledge_id'=>$knowledgeId];$saved=vp3_profile_knowledge_saved_v1($pdo,$live,$link);
        vp3_profile_knowledge_owner_v1($pdo,$live);
        return vp3_profile_knowledge_source_projection_v1($source)+['saved'=>$saved,'created'=>true];
    });
}
