<?php
declare(strict_types=1);

if(!function_exists('personal_capability_has_v242'))require_once __DIR__.'/personal-capabilities-v242.php';

/**
 * Current personal permissions for governed actions, without an error-to-default
 * fallback. Call inside the action transaction so the permission reads share its
 * final authorization fence. This reader neither starts a transaction nor writes.
 */
function personal_capability_has_strict_v1(string $permission,?array $user=null): bool
{
    if(!function_exists('personal_capability_catalog_v242')||!function_exists('personal_capability_default_roles_v242')
        ||!defined('STONEFELLOW_PERSONAL_CAPABILITIES_SEED'))
        throw new RuntimeException('Personal capability permissions are temporarily unavailable.',503);
    $user??=current_user();
    if(!$user||!isset(personal_capability_catalog_v242()[$permission]))return false;
    $uid=(int)($user['id']??0);if($uid<1)return false;

    try{
        $pdo=db();
        if(!$pdo instanceof PDO)throw new RuntimeException('Permission storage is unavailable.');
        $read=static function(string $sql,array $parameters=[])use($pdo):PDOStatement{
            $statement=$pdo->prepare($sql);
            if(!$statement instanceof PDOStatement||!$statement->execute($parameters))throw new RuntimeException('Permission storage could not be read.');
            return $statement;
        };
        // Never authorize a copied role, roles array, or inactive account.
        $identity=$read('SELECT role,is_active FROM users WHERE id=? LIMIT 1',[$uid])->fetch(PDO::FETCH_ASSOC);
        if(!$identity||(int)($identity['is_active']??0)!==1)return false;
        $primary=(string)($identity['role']??'');
        $roles=valid_role($primary)?[$primary]:[];
        if($primary==='admin')return true;
        // These are the same explicit assignments recognized by the canonical
        // role reader. A missing or unreadable upgrade cannot grant defaults.
        $assigned=$read('SELECT role FROM user_account_types WHERE user_id=? AND (role=? OR assigned_explicitly_at IS NOT NULL) ORDER BY role ASC',[$uid,$primary]);
        foreach($assigned->fetchAll(PDO::FETCH_ASSOC) as $assignment){
            $role=(string)($assignment['role']??'');
            if(valid_role($role))$roles[]=$role;
        }
        $roles=array_values(array_unique($roles));
        if(in_array('admin',$roles,true))return true;
        if(!$roles)return false;

        // setting() and the legacy personal helper deliberately tolerate older
        // installs. Actions must distinguish a verified pre-seed default from a
        // failed settings/schema/grant read instead of silently restoring access.
        $read('SELECT 1 FROM permissions LIMIT 1');
        $seed=$read('SELECT setting_value FROM settings WHERE setting_key=? LIMIT 1',[STONEFELLOW_PERSONAL_CAPABILITIES_SEED])->fetch(PDO::FETCH_ASSOC);
        $seedValue=(string)($seed['setting_value']??'');
        if(!in_array($seedValue,['','0','1'],true))throw new RuntimeException('Permission seed state is invalid.');
        $marks=implode(',',array_fill(0,count($roles),'?'));
        $grant=$read("SELECT 1 FROM role_permissions WHERE permission_key=? AND role IN ($marks) LIMIT 1",[$permission,...$roles])->fetchColumn();
        if($seedValue==='1')return (bool)$grant;

        $defaults=personal_capability_default_roles_v242();
        foreach($roles as $role)if(in_array($role,$defaults[$permission]??[],true))return true;
        return false;
    }catch(Throwable $error){
        throw new RuntimeException('Personal capability permissions are temporarily unavailable.',503,$error);
    }
}
