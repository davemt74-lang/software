<?php
declare(strict_types=1);

require_once __DIR__.'/agent-gmail-documents-schema-v1.php';
require_once __DIR__.'/agent-email-sent-v1.php';

require_once __DIR__.'/agent-gmail-document-input-v1.php';
const AGENT_GMAIL_DOCUMENT_PROTOCOL_V1='vp3.gmail-document-review.v1';

function agent_gmail_document_hash_v1(mixed $value): string
{return hash('sha256',json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));}

/** Metadata only. Never interpret MIME filenames as paths, URLs or instructions. */
function agent_gmail_document_parts_v1(array $raw): array
{
    $out=[];$nodes=0;
    $walk=static function(array $part,string $path,int $depth) use (&$walk,&$out,&$nodes): void {
        if (++$nodes>256 || $depth>12) throw new DomainException('This email has too many MIME parts to review safely.',422);
        $name=$part['filename']??'';$mime=strtolower((string)($part['mimeType']??''));
        if (is_string($name) && $name!=='') {
            $name=mb_substr(preg_replace('/[\p{Cc}\p{Cf}\/\\\\]/u','_',mb_check_encoding($name,'UTF-8')?$name:'Attachment')??'Attachment',0,180);
            $size=$part['body']['size']??null;
            if (!is_int($size) || $size<0) throw new DomainException('Gmail returned incomplete attachment metadata.',502);
            $supported=in_array($mime,['application/pdf','image/jpeg','image/png','image/webp'],true)
                &&$size>0&&$size<=AGENT_GMAIL_DOCUMENT_MAX_BYTES_V1;
            $out[]=['part'=>$path,'name'=>$name,'mime'=>$mime,'size'=>$size,'supported'=>$supported,
                'reason'=>$supported?null:($size>AGENT_GMAIL_DOCUMENT_MAX_BYTES_V1?'over_limit':'unsupported')];
            if (count($out)>20) throw new DomainException('Review emails with at most 20 attachments.',422);
        }
        foreach ((array)($part['parts']??[]) as $i=>$child) {
            if (!is_int($i)||!is_array($child)) throw new DomainException('Gmail returned invalid MIME parts.',502);
            $walk($child,$path.'.'.$i,$depth+1);
        }
    };
    $walk((array)($raw['payload']??[]),'0',0);return $out;
}

/** Only called by existing exact private message readers, never by background scans. */
function agent_gmail_document_source_v1(PDO $pdo,array $user,int $connection,string $grant,array $raw): array
{
    try {$parts=agent_gmail_document_parts_v1($raw);}
    catch (DomainException $error) {return ['documents'=>['unavailable'=>$error->getMessage()]];}
    if (!$parts) return [];
    $record=agent_connections_require_grant_v1($pdo,(int)$user['id'],$connection,'read',$grant);
    $id=agent_connection_gmail_id_v1($raw['id']??'');$thread=agent_connection_gmail_id_v1($raw['threadId']??'');
    if ($id===''||$thread===''||array_intersect(['SPAM','TRASH','DRAFT'],(array)($raw['labelIds']??[]))) return [];
    $source=['contract'=>'gmail-document-source-v1','owner'=>(int)$user['id'],'connection'=>$connection,'grant'=>$grant,
        'fingerprint'=>$record['client_fingerprint'],'account'=>$record['account_email'],'message'=>$id,'thread'=>$thread,
        'revision'=>agent_gmail_document_hash_v1($raw['payload']??[]),'expires'=>time()+900];
    return ['documents'=>['source_ref'=>bin2hex(ai_encrypt_secret(json_encode($source,JSON_THROW_ON_ERROR))),
        'count'=>count($parts),'max_bytes'=>AGENT_GMAIL_DOCUMENT_MAX_BYTES_V1]];
}

function agent_gmail_document_source_open_v1(array $user,string $token): array
{
    if (strlen($token)>6000||strlen($token)%2||!preg_match('/\A[a-f0-9]+\z/D',$token)) throw new DomainException('Reopen the original email to review its attachments.',422);
    $source=json_decode(ai_decrypt_secret(hex2bin($token)),true,8);
    if (!is_array($source)||array_keys($source)!==['contract','owner','connection','grant','fingerprint','account','message','thread','revision','expires']
        ||$source['contract']!=='gmail-document-source-v1'||$source['owner']!==(int)$user['id']
        ||!is_int($source['connection'])||$source['connection']<1||!is_string($source['grant'])||!is_string($source['fingerprint'])
        ||!is_string($source['account'])||agent_connection_gmail_id_v1($source['message'])!==$source['message']||$source['message']===''
        ||agent_connection_gmail_id_v1($source['thread'])!==$source['thread']||$source['thread']===''
        ||!is_string($source['revision'])||!preg_match('/\A[a-f0-9]{64}\z/D',$source['revision'])
        ||!is_int($source['expires'])||$source['expires']<=time()||$source['expires']>time()+900) {
        throw new DomainException('This private attachment reference expired or belongs to another account. Reopen the email.',409);
    }
    return $source;
}

function agent_gmail_document_current_v1(PDO $pdo,array $user,array $principal,int $chat,array $source,Closure $current): array
{
    if ($current()===false) throw new DomainException('Private email access changed.',403);
    $live=agent_connection_email_chat_authority_v1($pdo,$user,$principal,$chat);
    $c=agent_connections_require_grant_v1($pdo,(int)$live['id'],$source['connection'],'read',$source['grant']);
    if ($c['client_fingerprint']!==$source['fingerprint']||$c['account_email']!==$source['account']||!agent_connection_gmail_ready_v1()) {
        throw new DomainException('The connected Gmail account changed. Reopen the message.',409);
    }
    $native=$GLOBALS['VP3_PHONE_AGENT_NATIVE_EXECUTION_CONTEXT_V1']??null;
    if (is_array($native)) {
        $context=$native['context'];$target=$context['resolved']['source']['target'];
        if ($context['resolved']['item']['source_kind']==='gmail_draft') {
            $draft=agent_connection_email_chat_draft_v1($pdo,(int)$user['id'],$target['agent_id'],$target['conversation_id'],$target['draft_id']);
            $payload=agent_connection_email_chat_payload_v1($draft);
            $message=$payload['reply']['message_id']??$payload['outbound_followup']['message_id']??null;
            if ($draft['status']==='discarded'||$message!==$source['message']||(int)$draft['connection_id']!==$source['connection']
                ||$draft['authorization_key']!==$source['grant']) throw new DomainException('This attachment belongs to another phone review.',403);
        } elseif ($context['resolved']['item']['source_kind']==='gmail_attention') {
            require_once __DIR__.'/agent-connection-gmail-inbox-initial-v1.php';
            $item=agent_connection_gmail_inbox_item_v1($pdo,$user,$target['item_ref']);
            if ($item['message_id']!==$source['message']||$item['connection_id']!==$source['connection']||$item['authorization_key']!==$source['grant']) {
                throw new DomainException('This attachment belongs to another phone item.',403);
            }
        } else throw new DomainException('Open the exact email on this phone.',403);
    }
    return $c;
}

function agent_gmail_document_read_v1(PDO $pdo,array $user,array $principal,int $chat,array $source,Closure $current,?callable $http=null): array
{
    agent_gmail_document_current_v1($pdo,$user,$principal,$chat,$source,$current);
    $access=agent_connections_access_v1((int)$user['id'],$source['connection'],'read',$http,$source['grant']);
    agent_gmail_document_current_v1($pdo,$user,$principal,$chat,$source,$current);
    $raw=agent_connection_gmail_get_v1($access['access_token'],$source['message'],true,$http,true);
    agent_gmail_document_current_v1($pdo,$user,$principal,$chat,$source,$current);
    if (($raw['threadId']??null)!==$source['thread']||!hash_equals($source['revision'],agent_gmail_document_hash_v1($raw['payload']??[]))
        ||array_intersect(['TRASH','SPAM','DRAFT'],(array)($raw['labelIds']??[]))) {
        throw new DomainException('The email or its attachments changed. Reopen the current message.',409);
    }
    return ['raw'=>$raw,'access'=>$access,'parts'=>agent_gmail_document_parts_v1($raw)];
}

function agent_gmail_document_part_v1(array $raw,string $path): array
{
    if (!preg_match('/\A0(?:\.[0-9]{1,3}){0,12}\z/D',$path)) throw new DomainException('Select one attachment from this email.',422);
    $node=$raw['payload']??[];foreach (array_slice(explode('.',$path),1) as $index) $node=$node['parts'][(int)$index]??[];
    return $node;
}

function agent_gmail_document_bytes_v1(array $read,array $part,?callable $http=null): string
{
    if (!$part['supported']) throw new DomainException('Choose a PDF, JPEG, PNG or WebP attachment up to 2 MiB.',422);
    $node=agent_gmail_document_part_v1($read['raw'],$part['part']);$body=$node['body']??[];
    if (isset($body['attachmentId'])) {
        $id=$body['attachmentId'];
        if (!is_string($id)||!preg_match('/\A[A-Za-z0-9_-]{1,2048}\z/D',$id)) throw new DomainException('Gmail returned an invalid attachment.',502);
        $body=agent_connection_gmail_api_v1($read['access']['access_token'],'/messages/'.$read['raw']['id'].'/attachments/'.rawurlencode($id),null,$http);
    }
    $data=$body['data']??null;
    if (!is_string($data)||strlen($data)>2796210||!preg_match('/\A[A-Za-z0-9_-]*={0,2}\z/D',$data)) throw new DomainException('Gmail returned invalid attachment bytes.',502);
    $bytes=base64_decode(strtr($data,'-_','+/'),true);
    if ($bytes===false||strlen($bytes)!==$part['size']||strlen($bytes)>AGENT_GMAIL_DOCUMENT_MAX_BYTES_V1
        ||(isset($body['size'])&&$body['size']!==strlen($bytes))) throw new DomainException('The attachment size changed. Reopen the email.',409);
    agent_gmail_document_input_v1(['mime'=>$part['mime'],'name'=>$part['name'],'bytes'=>$bytes]);
    return $bytes;
}

function agent_gmail_document_result_v1(string $answer,string $mime): array
{
    require_once __DIR__.'/agent-tool-json-v1.php';
    try {$v=vp3_tool_json_object_v1($answer,['coverage','summary','facts','questions','reply_body'],24000);}
    catch (InvalidArgumentException|JsonException $error) {throw new DomainException('The document result could not be verified.',502,$error);}
    $plain=static function(mixed $text,int $max): string {
        if (!is_string($text)||!mb_check_encoding($text,'UTF-8')||mb_strlen($text)>$max||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$text)) throw new DomainException('The document result could not be verified.',502);
        return trim($text);
    };
    if (!in_array($v['coverage']??null,['readable','partial','unreadable'],true)||!is_array($v['facts']??null)||!array_is_list($v['facts'])||count($v['facts'])>10
        ||!is_array($v['questions']??null)||!array_is_list($v['questions'])||count($v['questions'])>8) throw new DomainException('The document result could not be verified.',502);
    $out=['coverage'=>$v['coverage'],'summary'=>$plain($v['summary']??null,3000),'facts'=>[],'questions'=>[],'reply_body'=>$plain($v['reply_body']??null,6000)];
    if ($out['summary']==='') throw new DomainException('The attachment did not produce a readable summary.',502);
    foreach ($v['facts'] as $fact) {
        if (!is_array($fact)||count($fact)!==2||!array_key_exists('text',$fact)||!is_int($fact['page']??null)||$fact['page']<1||$fact['page']>1000
            ||($mime!=='application/pdf'&&$fact['page']!==1)) throw new DomainException('The document page references could not be verified.',502);
        $out['facts'][]=['text'=>$plain($fact['text'],500),'page'=>$fact['page']];
    }
    foreach ($v['questions'] as $q) $out['questions'][]=$plain($q,500);
    if ($out['coverage']==='unreadable') {$out['facts']=[];$out['reply_body']='';}
    return $out;
}

function agent_gmail_document_row_v1(PDO $pdo,int $owner,int $agent,int $chat,string $id): ?array
{
    $q=$pdo->prepare('SELECT * FROM agent_gmail_document_reviews_v1 WHERE id=? AND owner_user_id=? AND agent_id=? AND conversation_id=?');
    $q->execute([$id,$owner,$agent,$chat]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
}

function agent_gmail_document_public_v1(?array $row): ?array
{
    if ($row===null) return null;
    return ['id'=>$row['id'],'state'=>$row['state'],'reason'=>$row['reason'],
        'result'=>$row['state']==='ready'?json_decode(ai_decrypt_secret($row['result_enc']),true,16,JSON_THROW_ON_ERROR)['review']:null];
}

function agent_gmail_document_saved_v1(array $row,array $read,array $part,?callable $http): array
{
    $saved=json_decode(ai_decrypt_secret($row['result_enc']),true,16,JSON_THROW_ON_ERROR);
    if (!hash_equals($saved['bytes_sha256'],hash('sha256',agent_gmail_document_bytes_v1($read,$part,$http)))) throw new DomainException('The original attachment changed. Reopen this email.',409);
    return $saved;
}

/** A foreground owner action. A persisted claim is consumed once at the metered provider boundary. */
function agent_gmail_document_action_v1(PDO $pdo,array $user,array $principal,int $chat,array $input,Closure $current,?callable $http=null,?callable $model=null): array
{
    if ($pdo->inTransaction()) throw new LogicException('Review documents outside a caller transaction.');
    $source=agent_gmail_document_source_open_v1($user,$input['source_ref']??'');
    $read=agent_gmail_document_read_v1($pdo,$user,$principal,$chat,$source,$current,$http);
    $action=$input['action'];$agent=(int)($principal['agent_id']??0);$owner=(int)$user['id'];
    $installed=agent_gmail_documents_ready_v1($pdo);
    if ($action==='list') return ['parts'=>$read['parts'],'installed'=>$installed];
    $path=$input['part']??null;$part=null;foreach ($read['parts'] as $p) if ($p['part']===$path) $part=$p;
    if ($part===null) throw new DomainException('Select a current attachment.',404);
    $hash=agent_gmail_document_hash_v1([$owner,$agent,$chat,$source['connection'],$source['grant'],$source['fingerprint'],$source['account'],$source['message'],$source['revision'],$part]);
    if ($action==='open') {
        $bytes=agent_gmail_document_bytes_v1($read,$part,$http);
        agent_gmail_document_read_v1($pdo,$user,$principal,$chat,$source,$current,$http);
        return ['file'=>['name'=>$part['name'],'mime'=>$part['mime'],'data'=>base64_encode($bytes),'sha256'=>hash('sha256',$bytes)]];
    }
    if (!$installed) throw new DomainException('Run Database Upgrade to analyze attachments.',503);
    $latest=$pdo->prepare('SELECT * FROM agent_gmail_document_reviews_v1 WHERE owner_user_id=? AND agent_id=? AND conversation_id=? AND source_hash=? ORDER BY created_epoch DESC,id DESC LIMIT 1');
    $latest->execute([$owner,$agent,$chat,$hash]);$row=$latest->fetch(PDO::FETCH_ASSOC)?:null;
    if ($row!==null&&in_array($row['state'],['preparing','dispatched'],true)&&(int)$row['lease_epoch']<=time()) {
        $pdo->prepare("UPDATE agent_gmail_document_reviews_v1 SET state='failed',reason='interrupted',updated_epoch=? WHERE id=? AND state IN ('preparing','dispatched') AND lease_epoch<=?")
            ->execute([time(),$row['id'],time()]);$row=agent_gmail_document_row_v1($pdo,$owner,$agent,$chat,$row['id']);
    }
    if ($action==='get') {
        if ($row!==null&&$row['state']==='ready') {
            agent_gmail_document_saved_v1($row,$read,$part,$http);
            agent_gmail_document_read_v1($pdo,$user,$principal,$chat,$source,$current,$http);
        }
        return ['review'=>agent_gmail_document_public_v1($row)];
    }
    if ($action==='reply') {
        $row=agent_gmail_document_row_v1($pdo,$owner,$agent,$chat,(string)($input['review_id']??''));
        if (!$row||!hash_equals($hash,$row['source_hash'])||$row['state']!=='ready') throw new DomainException('Review the current attachment summary first.',409);
        $result=agent_gmail_document_saved_v1($row,$read,$part,$http);
        if ($result['review']['reply_body']==='') throw new DomainException('This result has no reply wording.',409);
        $reply=agent_connection_gmail_reply_from_message_v1($read['raw'],$source['account']);
        agent_connections_require_grant_v1($pdo,$owner,$source['connection'],'reply',$source['grant']);
        $native=$GLOBALS['VP3_PHONE_AGENT_NATIVE_EXECUTION_CONTEXT_V1']??null;
        if (is_array($native)) throw new DomainException('Use the current phone composer or open Agent Chat to prepare this reply.',409);
        require_once __DIR__.'/agent-email-composer-v1.php';
        agent_gmail_document_read_v1($pdo,$user,$principal,$chat,$source,$current,$http);
        $draft=agent_email_composer_create_v1($pdo,$user,$principal,$chat,['request_id'=>$row['id'],'to'=>$reply['to'],
            'subject'=>$reply['subject'],'body'=>$result['review']['reply_body']],(string)$input['_key'],null,'',
            ['reply'=>$reply['reply'],'connection_id'=>$source['connection'],'grant'=>$source['grant'],'account'=>$source['account'],
                'soul_version'=>$result['soul']['version'],'soul_revision'=>$result['soul']['revision'],'generation_key'=>'gmail-document:'.$row['id']]);
        agent_gmail_document_current_v1($pdo,$user,$principal,$chat,$source,$current);
        return ['draft'=>$draft];
    }
    if ($action!=='analyze') throw new DomainException('Choose an attachment action.',422);
    $request=$input['request_id']??null;$instruction=$input['instruction']??'';
    if (!is_string($request)||!preg_match('/\A[a-f0-9]{32}\z/D',$request)||!is_string($instruction)||!mb_check_encoding($instruction,'UTF-8')||mb_strlen($instruction)>600||preg_match('/[\p{Cc}]/u',$instruction)) throw new DomainException('Enter a short question about this attachment.',422);
    if (!$part['supported']) throw new DomainException('Choose a PDF or supported image up to 2 MiB.',422);
    $id=substr(agent_gmail_document_hash_v1([$owner,$agent,$chat,$request]),0,32);$instructionHash=hash('sha256',$instruction);
    $claim=agent_commerce_atomic_pa2($pdo,static function()use($pdo,$owner,$agent,$chat,$id,$hash,$request,$instructionHash,$user,$principal,$source,$current): bool {
        agent_gmail_document_current_v1($pdo,$user,$principal,$chat,$source,$current);
        $pdo->prepare('SELECT id FROM users WHERE id=?'.agent_commerce_lock_pa2($pdo))->execute([$owner]);
        $old=agent_gmail_document_row_v1($pdo,$owner,$agent,$chat,$id);
        if ($old) {
            if (!hash_equals($hash,$old['source_hash'])||!hash_equals($instructionHash,$old['instruction_hash'])) throw new DomainException('This attachment request changed. Start a new review.',409);
            return false;
        }
        $pdo->prepare("INSERT INTO agent_gmail_document_reviews_v1 (id,owner_user_id,agent_id,conversation_id,request_key,source_hash,instruction_hash,state,lease_epoch,created_epoch,updated_epoch) VALUES (?,?,?,?,?,?,?,'preparing',?,?,?)")
            ->execute([$id,$owner,$agent,$chat,$request,$hash,$instructionHash,time()+150,(int)floor(microtime(true)*1000000),time()]);return true;
    });
    if (!$claim) {
        $old=agent_gmail_document_row_v1($pdo,$owner,$agent,$chat,$id);
        if ($old['state']==='ready') agent_gmail_document_saved_v1($old,$read,$part,$http);
        agent_gmail_document_current_v1($pdo,$user,$principal,$chat,$source,$current);
        return ['review'=>agent_gmail_document_public_v1($old)];
    }
    try {
        $bytes=agent_gmail_document_bytes_v1($read,$part,$http);
        require_once __DIR__.'/agent-brain-soul-v1.php';$soul=agent_brain_soul_profile_v1($user,'email');
        $mail=agent_connection_gmail_read_message_v1($read['raw']);
        $prompt=json_encode(['owner_instruction'=>$instruction?:'Summarize this attachment, identify important missing details, and suggest a short reply asking about those gaps.',
            'email_soul'=>$soul['content'],'message'=>['subject'=>$mail['subject'],'from'=>$mail['from'],'body'=>mb_substr($mail['body'],0,4000)],
            'attachment'=>['name'=>$part['name'],'mime'=>$part['mime']]],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        $byteHash=hash('sha256',$bytes);$dispatched=false;
        $before=static function()use($pdo,$user,$principal,$chat,$source,$current,$http,$owner,$agent,$id,$part,$byteHash,&$dispatched): bool {
            if ($dispatched) throw new DomainException('This attachment review was already dispatched.',409);
            $liveRead=agent_gmail_document_read_v1($pdo,$user,$principal,$chat,$source,$current,$http);
            if (!hash_equals($byteHash,hash('sha256',agent_gmail_document_bytes_v1($liveRead,$part,$http)))) throw new DomainException('The attachment bytes changed before analysis.',409);
            agent_gmail_document_current_v1($pdo,$user,$principal,$chat,$source,$current);
            $q=$pdo->prepare("UPDATE agent_gmail_document_reviews_v1 SET state='dispatched',updated_epoch=? WHERE id=? AND owner_user_id=? AND agent_id=? AND conversation_id=? AND state='preparing' AND lease_epoch>?");
            $q->execute([time(),$id,$owner,$agent,$chat,time()]);
            if ($q->rowCount()!==1) throw new DomainException('This attachment review is no longer current.',409);
            $dispatched=true;return true;
        };
        $control=['surface'=>'tool_json','protocol'=>AGENT_GMAIL_DOCUMENT_PROTOCOL_V1,'gmail_document_review'=>true,
            'before_gmail_document_dispatch'=>$before,'gmail_document'=>['mime'=>$part['mime'],'name'=>$part['name'],'bytes'=>$bytes]];
        $service=['service'=>'conversation','stage'=>AGENT_GMAIL_DOCUMENT_PROTOCOL_V1,'agent_id'=>$agent,'conversation_id'=>$chat,'job_key'=>'gmail-document:'.$id,'background'=>false];
        if ($model) $answer=$model($prompt,$service,$control);
        else {
            require_once __DIR__.'/ai-runtime-v100.php';
            $answer=ai_v100_with_service_context($service,static fn()=>ai_generate_chat_response($prompt,[],[],$user,'main_ai',$control));
        }
        if (!$dispatched||empty($answer['ok'])) throw new DomainException(!empty($answer['quota_exhausted'])?'The account has reached its AI allowance. No attachment summary was saved.':'The selected model could not review this attachment. No summary was saved.',503);
        $review=agent_gmail_document_result_v1((string)($answer['answer']??''),$part['mime']);
        $liveRead=agent_gmail_document_read_v1($pdo,$user,$principal,$chat,$source,$current,$http);
        if (!hash_equals($byteHash,hash('sha256',agent_gmail_document_bytes_v1($liveRead,$part,$http)))) throw new DomainException('The attachment changed during analysis.',409);
        $result=['review'=>$review,'bytes_sha256'=>$byteHash,'soul'=>['version'=>$soul['version'],'revision'=>$soul['revision']]];
        $pdo->prepare("UPDATE agent_gmail_document_reviews_v1 SET state='ready',result_enc=?,lease_epoch=0,updated_epoch=? WHERE id=? AND state='dispatched'")
            ->execute([ai_encrypt_secret(json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)),time(),$id]);
    } catch (Throwable $error) {
        $pdo->prepare("UPDATE agent_gmail_document_reviews_v1 SET state='failed',reason=?,lease_epoch=0,updated_epoch=? WHERE id=? AND state IN ('preparing','dispatched')")
            ->execute([in_array((int)$error->getCode(),[401,403,409,419],true)?'source_changed':'review_failed',time(),$id]);
        throw $error;
    }
    agent_gmail_document_read_v1($pdo,$user,$principal,$chat,$source,$current,$http);
    return ['review'=>agent_gmail_document_public_v1(agent_gmail_document_row_v1($pdo,$owner,$agent,$chat,$id))];
}
