<?php
declare(strict_types=1);

function gmail_email_capture_hash_v1(mixed $value): string
{return hash('sha256',json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));}

function gmail_email_capture_mail_hash_v1(array $mail): string
{return gmail_email_capture_hash_v1([$mail['message_id'],$mail['thread_id'],$mail['subject'],$mail['from'],$mail['body']]);}

/** Issued only by an exact private reader; never a mailbox scan or model proposal. */
function gmail_email_capture_source_v1(PDO $pdo,array $user,int $connection,string $grant,array $mail): ?string
{
    if (array_intersect(['SPAM','TRASH','DRAFT'],(array)($mail['labels']??[]))) return null;
    foreach (['message_id','thread_id','subject','from','body'] as $field) if (!is_string($mail[$field]??null)) return null;
    foreach (['message_id','thread_id'] as $field) if ($mail[$field]===''||agent_connection_gmail_id_v1($mail[$field])!==$mail[$field]) return null;
    try {$record=agent_connections_require_grant_v1($pdo,(int)$user['id'],$connection,'read',$grant);}
    catch (AgentConnectionExceptionV1 $error) {
        if (!in_array((int)$error->getCode(),[401,403,404,409],true)) throw $error;
        // Exact readers report a source change when access is revoked during their GET.
        throw new DomainException('Gmail access changed. Reopen the email.',409,$error);
    }
    if (!is_string($record['client_fingerprint']??null)||!is_string($record['account_email']??null)) return null;
    $source=['contract'=>'gmail-capture-source-v1','owner'=>(int)$user['id'],'connection'=>$connection,'grant'=>$grant,
        'fingerprint'=>$record['client_fingerprint'],'account'=>$record['account_email'],
        'message'=>$mail['message_id'],'thread'=>$mail['thread_id'],'revision'=>gmail_email_capture_mail_hash_v1($mail),'expires'=>time()+900];
    return bin2hex(ai_encrypt_secret(json_encode($source,JSON_THROW_ON_ERROR)));
}

function gmail_email_capture_source_open_v1(array $user,string $token): array
{
    if (strlen($token)>6000||strlen($token)%2||!preg_match('/\A[a-f0-9]+\z/D',$token)) throw new DomainException('Reopen the email to select an area.',422);
    $s=json_decode(ai_decrypt_secret(hex2bin($token)),true,8);
    if (!is_array($s)||array_keys($s)!==['contract','owner','connection','grant','fingerprint','account','message','thread','revision','expires']
        ||$s['contract']!=='gmail-capture-source-v1'||$s['owner']!==(int)$user['id']
        ||!is_int($s['connection'])||$s['connection']<1||!is_string($s['grant'])||$s['grant']===''
        ||!is_string($s['fingerprint'])||!is_string($s['account'])
        ||!is_string($s['message'])||$s['message']===''||agent_connection_gmail_id_v1($s['message'])!==$s['message']
        ||!is_string($s['thread'])||$s['thread']===''||agent_connection_gmail_id_v1($s['thread'])!==$s['thread']
        ||!is_string($s['revision'])||!preg_match('/\A[a-f0-9]{64}\z/D',$s['revision'])
        ||!is_int($s['expires'])||$s['expires']<=time()||$s['expires']>time()+900) {
        throw new DomainException('This email selection expired or belongs to another account. Reopen the email.',409);
    }
    return $s;
}

/** Only the native Gmail message endpoint is read, never a URL in the email. */
function gmail_email_capture_read_v1(PDO $pdo,array $user,array $principal,int $chat,array $source,Closure $current,?callable $http=null): array
{
    $check=static function()use($pdo,$user,$principal,$chat,$source,$current):void{
        if ($current()===false) throw new DomainException('Private email access changed.',403);
        agent_connection_email_chat_authority_v1($pdo,$user,$principal,$chat);
        $c=agent_connections_require_grant_v1($pdo,(int)$user['id'],$source['connection'],'read',$source['grant']);
        if ($c['client_fingerprint']!==$source['fingerprint']||$c['account_email']!==$source['account']||!agent_connection_gmail_ready_v1()) {
            throw new DomainException('The connected Gmail account changed. Reopen the email.',409);
        }
    };
    $check();
    $access=agent_connections_access_v1((int)$user['id'],$source['connection'],'read',$http,$source['grant']);
    $raw=agent_connection_gmail_get_v1($access['access_token'],$source['message'],true,$http);
    $mail=agent_connection_gmail_read_message_v1($raw);
    $check();
    if ($mail['thread_id']!==$source['thread']||array_intersect(['SPAM','TRASH','DRAFT'],$mail['labels'])
        ||!hash_equals($source['revision'],gmail_email_capture_mail_hash_v1($mail))) throw new DomainException('The original email changed. Reopen it before using this selection.',409);
    return $mail;
}

function gmail_email_capture_source_hash_v1(array $source,int $agent,int $chat): string
{unset($source['expires']);return gmail_email_capture_hash_v1([$agent,$chat,$source]);}
