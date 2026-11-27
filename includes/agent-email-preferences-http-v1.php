<?php
declare(strict_types=1);

require_once __DIR__.'/agent-connection-email-chat-v1.php';
require_once __DIR__.'/agent-brain-soul-v1.php';

/** Capture the authenticated browser request, never a synthesized owner session.
 * Reopen the same session before slow-work boundaries. Inside short native SQL
 * transactions, keep session -> database lock order and use current row locks.
 */
function agent_email_preferences_http_current_v1(PDO $pdo,array $user,array $principal,int $conversation): Closure
{
    $delegated=$GLOBALS['VP3_PHONE_AGENT_NATIVE_EXECUTION_CONTEXT_V1']['context']['scope']??null;
    if(defined('VP3_PHONE_AGENT_NATIVE_V1')&&is_array($delegated)&&!empty($delegated['paired_access'])){
        $csrf=$_SESSION['csrf_token']??null;$review=$_SESSION['agent_connection_email_review_v1']??null;$session=session_id();
        if((int)$delegated['user']['id']!==(int)$user['id']||!is_string($csrf)||$csrf===''||!is_string($review)||$review===''||$session==='')throw new DomainException('Phone access changed.',401);
        return static function()use($pdo,$user,$principal,$conversation,$csrf,$review,$session):bool{
            $transaction=$pdo->inTransaction();
            try{
                if(session_id()!==$session||(!$transaction&&session_status()!==PHP_SESSION_ACTIVE&&!session_start()))throw new DomainException('Phone session changed.',401);
                if(!hash_equals($csrf,(string)($_SESSION['csrf_token']??''))||!hash_equals($review,(string)($_SESSION['agent_connection_email_review_v1']??'')))throw new DomainException('Phone review changed.',419);
                vp3_phone_agent_native_execution_current_v1($pdo);
                agent_connection_email_chat_authority_v1($pdo,$user,$principal,$conversation);return true;
            }finally{if(!$transaction&&session_status()===PHP_SESSION_ACTIVE)session_write_close();}
        };
    }
    $owner=(int)($user['id']??0);$session=session_id();
    $fingerprint=$_SESSION['auth_password_fingerprint']??null;
    $csrf=$_SESSION['csrf_token']??null;
    $review=$_SESSION['agent_connection_email_review_v1']??null;
    if ($owner<1 || $session==='' || session_status()!==PHP_SESSION_ACTIVE
        || (int)($_SESSION['user_id']??0)!==$owner
        || !is_string($fingerprint) || preg_match('/^[a-f0-9]{64}$/D',$fingerprint)!==1
        || !is_string($csrf) || $csrf==='' || !is_string($review) || $review==='') {
        throw new DomainException('Sign in again before changing email writing preferences.',401);
    }
    $phone=isset($GLOBALS['VP3_PHONE_AGENT_NATIVE_EXECUTION_CONTEXT_V1']);
    return static function() use($pdo,$user,$principal,$conversation,$owner,$session,$fingerprint,$csrf,$review,$phone): bool {
        $transaction=$pdo->inTransaction();
        try {
            if (session_id()!==$session || (!$transaction && session_status()!==PHP_SESSION_ACTIVE && !session_start())) {
                throw new DomainException('Your Chat session changed. Reopen Gmail.',401);
            }
            if ((int)($_SESSION['user_id']??0)!==$owner
                || !hash_equals($fingerprint,(string)($_SESSION['auth_password_fingerprint']??''))
                || !hash_equals($csrf,(string)($_SESSION['csrf_token']??''))
                || !hash_equals($review,(string)($_SESSION['agent_connection_email_review_v1']??''))) {
                throw new DomainException('Your Chat session changed. Reopen Gmail.',401);
            }
            $q=$pdo->prepare('SELECT id,password_hash,is_active FROM users WHERE id=?'.($transaction?agent_commerce_lock_pa2($pdo):''));
            $q->execute([$owner]);$saved=$q->fetch(PDO::FETCH_ASSOC);
            if (!$saved || (int)$saved['is_active']!==1
                || !hash_equals($fingerprint,hash('sha256',$owner.':'.(string)$saved['password_hash']))) {
                throw new DomainException('Your account access changed. Sign in again.',401);
            }
            if ($transaction) {
                $agent=($principal['kind']??'system')==='user_agent'?(int)($principal['agent_id']??0):0;
                if ($agent>0) {
                    $q=$pdo->prepare('SELECT owner_user_id,is_active FROM user_agents WHERE id=?'.agent_commerce_lock_pa2($pdo));
                    $q->execute([$agent]);$selected=$q->fetch(PDO::FETCH_ASSOC);
                    if (!$selected || (int)$selected['owner_user_id']!==$owner || (int)$selected['is_active']!==1) {
                        throw new DomainException('The selected Agent changed. Reopen your private Chat.',403);
                    }
                }
                $q=$pdo->prepare('SELECT user_id,user_agent_id FROM chat_conversations WHERE id=?'.agent_commerce_lock_pa2($pdo));
                $q->execute([$conversation]);$chat=$q->fetch(PDO::FETCH_ASSOC);
                if (!$chat || (int)$chat['user_id']!==$owner
                    || ($agent>0?(int)$chat['user_agent_id']!==$agent:$chat['user_agent_id']!==null)) {
                    throw new DomainException('The private Chat changed. Reopen Gmail.',403);
                }
            }
            if (function_exists('reset_current_user_cache')) reset_current_user_cache();
            $live=current_user();
            if (!is_array($live) || (int)($live['id']??0)!==$owner) {
                throw new DomainException('Sign in again before changing email writing preferences.',401);
            }
            agent_connection_email_chat_authority_v1($pdo,$live,$principal,$conversation);
            if ($phone) {
                require_once __DIR__.'/phone-agent-workspace-native-v1.php';
                vp3_phone_agent_native_execution_current_v1($pdo);
            }
            return true;
        } catch (PDOException $error) {
            if ($transaction && (int)($error->errorInfo[1]??0)===1020) {
                throw new DomainException('Your Gmail access changed. Refresh before continuing.',409,$error);
            }
            throw $error;
        } finally {
            if (!$transaction && session_status()===PHP_SESSION_ACTIVE) session_write_close();
        }
    };
}

/** A bounded view of the existing account-wide Email Soul, not another profile. */
function agent_email_preferences_settings_get_v1(array $user,array $input,Closure $current): array
{
    $current();
    $profile=agent_brain_soul_profile_v1($user,'email');
    $cursor=$input['cursor']??null;
    if ($cursor!==null && (!is_int($cursor) || $cursor<0 || $cursor>9999999999)) {
        throw new DomainException('Choose a valid writing preference history page.',422);
    }
    $versions=agent_brain_soul_history_v1($user,'email');
    if ($cursor!==null) $versions=array_values(array_filter($versions,static fn(int $version): bool=>$version<$cursor));
    $page=array_slice($versions,0,20);
    $result=['text'=>$profile['content'],'version'=>$profile['version'],'revision'=>$profile['revision'],
        'history'=>array_map(static fn(int $version): array=>['version'=>$version,'current'=>$version===$profile['version']],$page),
        'next_cursor'=>count($versions)>20?$page[19]:null];
    if (array_key_exists('version',$input)) {
        $version=$input['version'];
        if (!is_int($version) || $version<0 || $version>9999999999) {
            throw new DomainException('Choose a saved writing preference version.',422);
        }
        try {$text=agent_brain_soul_saved_version_v1($user,'email',$version);}
        catch (RuntimeException $error) {throw new DomainException('This saved preference version is unavailable. Refresh history.',409,$error);}
        $result+=['selected_version'=>$version,'selected_text'=>$text];
    }
    $current();
    if (!hash_equals($profile['revision'],agent_brain_soul_profile_v1($user,'email')['revision'])) {
        throw new DomainException('Writing preferences changed. Refresh the current version.',409);
    }
    return $result;
}

/** Explicit owner edits use the same locked revision and immutable Soul history. */
function agent_email_preferences_settings_update_v1(PDO $pdo,array $user,string $action,array $input,Closure $current): array
{
    if ($pdo->inTransaction()) throw new LogicException('Publish writing preferences outside a caller transaction.');
    $current();
    $revision=$input['soul_revision']??null;
    if (!is_string($revision) || preg_match('/^[a-f0-9]{64}$/D',$revision)!==1) {
        throw new DomainException('Open current writing preferences before saving.',422);
    }
    [$operation,$value]=match($action) {
        'preferences_save'=>['save',$input['text']??null],
        'preferences_restore'=>['restore',$input['version']??null],
        'preferences_reset'=>['reset',null],
        default=>throw new DomainException('Choose a valid writing preference action.',422),
    };
    if (($operation==='save' && !is_string($value)) || ($operation==='restore' && (!is_int($value) || $value<0))) {
        throw new DomainException('Choose valid writing preference text or a saved version.',422);
    }
    $publicationLock=false;
    try {
        try {
            agent_brain_soul_update_v1($user,'email',$revision,$operation,$value,
                static function(array $profile,int $version,string $content) use($pdo,$current,&$publicationLock): void {
                    $current();
                    $pdo->beginTransaction();$publicationLock=true;
                    // Hold current owner and native Phone authority only over
                    // this local immutable-file publication, never provider I/O.
                    $current();
                });
            if ($publicationLock) {$pdo->commit();$publicationLock=false;}
        } finally {
            if ($publicationLock && $pdo->inTransaction()) $pdo->rollBack();
        }
    } catch (DomainException $error) {throw $error;}
    catch (InvalidArgumentException $error) {throw new DomainException($error->getMessage(),422,$error);}
    catch (RuntimeException $error) {
        if (!hash_equals($revision,agent_brain_soul_profile_v1($user,'email')['revision'])) {
            throw new DomainException('Writing preferences changed. Refresh before saving again.',409,$error);
        }
        throw new DomainException('The saved preference outcome could not be verified. Refresh its history before trying again.',503,$error);
    }
    return agent_email_preferences_settings_get_v1($user,[],$current);
}
