<?php
declare(strict_types=1);

// This narrow reader uses the existing role catalog and personal defaults. It
// deliberately does not call ordinary SELECT-based permission helpers after a
// locking read: those helpers may still see the caller's older RR snapshot.
if(!function_exists('user_roles')&&!function_exists('has_permission'))require_once __DIR__.'/permissions.php';
if(!function_exists('personal_capability_has_v242'))require_once __DIR__.'/personal-capabilities-v242.php';

function vp3_profile_analytics_rows_v1(PDO $pdo,string $sql,array $parameters=[],bool $current=false): array
{
    if($current&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
        if(!$pdo->inTransaction())throw new RuntimeException('Profile analytics needs an active read transaction.',503);
        $sql.=' LOCK IN SHARE MODE';
    }
    $q=$pdo->prepare($sql);
    if(!$q instanceof PDOStatement||!$q->execute($parameters))throw new RuntimeException('Profile analytics storage is unavailable.',503);
    $rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();return $rows;
}

function vp3_profile_analytics_retryable_v1(Throwable $error): bool
{
    return $error instanceof PDOException&&(in_array((string)$error->getCode(),['40001','40P01'],true)
        ||in_array((int)($error->errorInfo[1]??0),[1205,1213],true));
}

/** Current canonical authority, without ending or changing a caller transaction. */
function vp3_profile_analytics_authority_current_v1(PDO $pdo,array $scope,bool $requireProfile=true,array $extraPermissions=[]): array
{
    $denied=static function():never{throw new RuntimeException('Current private Profile access is required.',403);};
    if(!$pdo->inTransaction())throw new RuntimeException('Profile analytics needs an active read transaction.',503);
    if(!function_exists('valid_role')||!function_exists('permission_catalog')||!function_exists('personal_capability_catalog_v242')
        ||!function_exists('personal_capability_default_roles_v242')||!defined('STONEFELLOW_PERSONAL_CAPABILITIES_SEED'))
        throw new RuntimeException('Profile analytics permissions are unavailable.',503);
    if(function_exists('vp3_participant_scope_v1')&&vp3_participant_scope_v1()!==null)$denied();
    $owner=(int)($scope['user']['id']??0);$aid=$scope['agent_id']??null;$cid=$scope['conversation_id']??null;
    if($owner<1||!is_int($aid)||$aid<0||!is_int($cid)||$cid<1)$denied();
    $principal=$scope['principal']??[];
    if((int)($principal['viewer_user_id']??0)!==$owner
        ||($principal['kind']??null)!==($aid>0?'user_agent':'system')
        ||(int)($principal['agent_id']??0)!==$aid
        ||($aid>0&&(int)($principal['owner_user_id']??0)!==$owner))$denied();
    try{
        if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
            $required=['users','user_account_types','user_agents','chat_conversations','settings','permissions','role_permissions'];
            $marks=implode(',',array_fill(0,count($required),'?'));
            $engines=vp3_profile_analytics_rows_v1($pdo,"SELECT TABLE_NAME,ENGINE FROM information_schema.tables WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ($marks)",$required);
            if(count($engines)!==count($required))throw new RuntimeException('Profile analytics permission storage is incomplete.',503);
            foreach($engines as $engine)if(strtoupper((string)$engine['ENGINE'])!=='INNODB')throw new RuntimeException('Profile analytics permissions need transactional storage.',503);
        }
        $user=vp3_profile_analytics_rows_v1($pdo,'SELECT * FROM users WHERE id=?',[$owner],true)[0]??null;
        if(!$user||(int)($user['is_active']??0)!==1)$denied();
        $primary=(string)($user['role']??'');$roles=valid_role($primary)?[$primary]:[];
        // Lock every current assignment actually used. New grants cannot
        // revoke an already-proven grant; removal of a used row is blocked.
        $assignments=vp3_profile_analytics_rows_v1($pdo,'SELECT role,assigned_explicitly_at FROM user_account_types WHERE user_id=? ORDER BY role',[$owner],true);
        foreach($assignments as $row)if(valid_role((string)$row['role'])
            &&($row['role']===$primary||$row['assigned_explicitly_at']!==null))$roles[]=(string)$row['role'];
        $roles=array_values(array_unique($roles));sort($roles,SORT_STRING);if(!$roles)$denied();
        $agent=null;
        if($aid>0){
            $agent=vp3_profile_analytics_rows_v1($pdo,'SELECT * FROM user_agents WHERE id=?',[$aid],true)[0]??null;
            if(!$agent||(int)$agent['owner_user_id']!==$owner||(int)$agent['is_active']!==1)$denied();
        }
        $chat=vp3_profile_analytics_rows_v1($pdo,'SELECT id,user_id,user_agent_id FROM chat_conversations WHERE id=?',[$cid],true)[0]??null;
        if(!$chat||(int)$chat['user_id']!==$owner||($aid===0?$chat['user_agent_id']!==null:(int)($chat['user_agent_id']??0)!==$aid))$denied();
        // Existing analytics callers retain every Profile grant. Private plan
        // inventory may use only account/Chat authority; it cannot expose any
        // public visitor, conversation, call or commerce outcome through this mode.
        if(array_diff($extraPermissions,['personal_knowledge.access']))throw new RuntimeException('Unsupported Profile reporting permission.',503);
        $permissions=['account.access','chat.access',...$extraPermissions];
        if($requireProfile)array_push($permissions,'profile_agent.access','profile_chat.access');
        if(!in_array('admin',$roles,true)){
            $catalog=permission_catalog();$personal=personal_capability_catalog_v242();
            foreach($permissions as $key)if(!isset($catalog[$key])&&!isset($personal[$key]))
                throw new RuntimeException('Profile analytics permission definitions are unavailable.',503);
            $seedValue='1';
            if(array_intersect($permissions,array_keys($personal))){
                $seed=vp3_profile_analytics_rows_v1($pdo,'SELECT setting_value FROM settings WHERE setting_key=?',[STONEFELLOW_PERSONAL_CAPABILITIES_SEED],true)[0]??null;
                // Personal defaults need a real locked marker. Account/Chat
                // inventory uses explicit grants and does not depend on it.
                if(!$seed)throw new RuntimeException('Profile analytics permission state is unavailable.',503);
                $seedValue=(string)($seed['setting_value']??'');
                if(!in_array($seedValue,['','0','1'],true))throw new RuntimeException('Profile analytics permission state is invalid.',503);
            }
            $marks=implode(',',array_fill(0,count($permissions),'?'));
            // Parent keys precede grant rows, matching the permission FK.
            vp3_profile_analytics_rows_v1($pdo,"SELECT permission_key FROM permissions WHERE permission_key IN ($marks) ORDER BY permission_key",$permissions,true);
            $roleMarks=implode(',',array_fill(0,count($roles),'?'));
            $grants=vp3_profile_analytics_rows_v1($pdo,"SELECT role,permission_key FROM role_permissions WHERE role IN ($roleMarks) AND permission_key IN ($marks) ORDER BY role,permission_key",[...$roles,...$permissions],true);
            $granted=array_fill_keys(array_column($grants,'permission_key'),true);
            $defaults=personal_capability_default_roles_v242();
            foreach($permissions as $key){
                if(isset($personal[$key])&&$seedValue!=='1'){
                    if(!array_intersect($roles,$defaults[$key]??[]))$denied();
                }elseif(empty($granted[$key]))$denied();
            }
        }
        unset($user['password_hash'],$user['password_reset_token'],$user['remember_token']);
        $scope['user']=$user;$scope['agent']=$agent;
        $scope['principal']=$aid>0
            ?['kind'=>'user_agent','agent_id'=>$aid,'owner_user_id'=>$owner,'viewer_user_id'=>$owner,
                'display_name'=>(string)($agent['display_name']??''),'engine_key'=>(string)($agent['engine_key']??'')]
            :['kind'=>'system','agent_id'=>0,'owner_user_id'=>0,'viewer_user_id'=>$owner];
        return $scope;
    }catch(Throwable $error){
        if(vp3_profile_analytics_retryable_v1($error))throw $error;
        if($error instanceof RuntimeException&&in_array($error->getCode(),[403,503],true))throw $error;
        throw new RuntimeException('Profile analytics permissions are temporarily unavailable.',503,$error);
    }
}
