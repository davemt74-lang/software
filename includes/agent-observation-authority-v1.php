<?php
declare(strict_types=1);

/** Current owner/Agent/conversation authority for observation adapters; no schema or sensing side effects. */
function vp3_observation_authority_v1(PDO $pdo,array $user,array $principal,array $context=[],?callable $permitted=null): array
{
    if(!function_exists('knowledge_retrieval_v162_owner_session'))require_once __DIR__.'/knowledge-retrieval-v162.php';
    if(!function_exists('vp3_capability_live_user_v1'))require_once __DIR__.'/agent-capability-chat-v1.php';
    if(!knowledge_retrieval_v162_owner_session($user,$principal))throw new RuntimeException('Owner scope is required.',403);
    if((int)($principal['owner_user_id']??0)>0&&(int)$principal['owner_user_id']!==(int)($user['id']??0))throw new RuntimeException('The principal belongs to another owner.',403);
    if(($principal['kind']??'system')==='user_agent'){
        $id=(int)($principal['agent_id']??0);
        if($id<1)throw new RuntimeException('An active owner Agent is required.',403);
        $q=$pdo->prepare('SELECT owner_user_id,is_active FROM user_agents WHERE id=?');$q->execute([$id]);$agent=$q->fetch(PDO::FETCH_ASSOC);
        if(!$agent||(int)$agent['owner_user_id']!==(int)($user['id']??0)||empty($agent['is_active']))throw new RuntimeException('Agent access changed.',403);
    }
    $live=vp3_capability_live_user_v1($pdo,$user,$principal,$context,null);
    if(!knowledge_retrieval_v162_owner_session($live,$principal)||($permitted&&!$permitted($live)))throw new RuntimeException('Observation access changed.',403);
    return $live;
}

/** Preserve canonical defaults, but a failed seeded permission lookup is not permission. */
function vp3_observation_personal_permission_v1(PDO $pdo,array $user,string $permission): bool
{
    if(!function_exists('personal_capability_has_v242'))return false;
    if(function_exists('user_has_role')&&user_has_role('admin',$user))return personal_capability_has_v242($permission,$user);
    if(function_exists('permissions_schema_ready')&&function_exists('personal_capability_seeded_v242')&&function_exists('user_roles_for_user')&&permissions_schema_ready()&&personal_capability_seeded_v242()){
        $roles=user_roles_for_user($user);
        if(!is_array($roles)||count($roles)>64)throw new RuntimeException('Current capability roles could not be verified.');
        if(!$roles)return false;
        foreach($roles as $role)if(!is_string($role)||$role===''||strlen($role)>100)throw new RuntimeException('Current capability roles could not be verified.');
        $marks=implode(',',array_fill(0,count($roles),'?'));
        $q=$pdo->prepare("SELECT 1 FROM role_permissions WHERE permission_key=? AND role IN ($marks) LIMIT 1");
        $q->execute([$permission,...$roles]);return(bool)$q->fetchColumn();
    }
    return personal_capability_has_v242($permission,$user);
}
