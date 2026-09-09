<?php
declare(strict_types=1);

$root=dirname(__DIR__);
require_once $root.'/includes/homeserver-policy-v035.php';

function expect_v035(bool $condition,string $message): void
{
    if(!$condition){
        fwrite(STDERR,"FAIL: {$message}\n");
        exit(1);
    }
}

$secret='SYNTHETIC_PRIVATE_ARGUMENT_73519';
$raw=[
    'app'=>'vp3',
    'items'=>[
        [
            'key'=>'knowledge.search','name'=>'Knowledge Search','mode'=>'read','enabled'=>true,'available'=>true,
            'execution_policy'=>['policy_mode'=>'read_only','inherited'=>true,'allowed_modes'=>['read_only','sensitive_high_impact'],'updated_at'=>'2026-09-09T12:00:00Z'],
            'arguments'=>['query'=>$secret],
        ],
        [
            'key'=>'memory.write','name'=>'Memory Write','mode'=>'write','enabled'=>true,'available'=>true,
            'execution_policy'=>['policy_mode'=>'safe_automatic','inherited'=>false,'allowed_modes'=>['safe_automatic','approval_required','sensitive_high_impact']],
            'private_payload'=>$secret,
        ],
        [
            'key'=>'tasks.create','name'=>'Create Task','mode'=>'write','enabled'=>true,'available'=>true,
            'execution_policy'=>['policy_mode'=>'approval_required','inherited'=>true,'allowed_modes'=>['safe_automatic','approval_required','sensitive_high_impact']],
        ],
        [
            'key'=>'contacts.search','name'=>'Contacts Search','mode'=>'read','enabled'=>true,'available'=>true,
            'execution_policy'=>['policy_mode'=>'sensitive_high_impact','inherited'=>false,'allowed_modes'=>['read_only','sensitive_high_impact']],
        ],
    ],
];

$normalized=homeserver_policy_v035_normalize($raw);
expect_v035($normalized['available']===true,'policy should normalize as available');
expect_v035($normalized['owner_managed']===true,'VP3 must preserve HomeServer owner authority');
expect_v035($normalized['app']==='vp3','app identity should remain scoped to VP3');
expect_v035($normalized['counts']['total']===4,'all synthetic tools should be counted');
expect_v035($normalized['counts']['read_only']===1,'read-only count should be correct');
expect_v035($normalized['counts']['safe_automatic']===1,'safe automatic count should be correct');
expect_v035($normalized['counts']['approval_required']===1,'approval-required count should be correct');
expect_v035($normalized['counts']['sensitive_high_impact']===1,'sensitive count should be correct');
expect_v035($normalized['counts']['inherited']===2,'inherited/default policy count should be correct');

$encoded=json_encode($normalized,JSON_UNESCAPED_SLASHES);
expect_v035(is_string($encoded),'normalized policy should encode');
expect_v035(!str_contains($encoded,$secret),'raw private tool arguments must never survive normalization');
expect_v035(!str_contains($encoded,'private_payload'),'unknown private fields must never survive normalization');
expect_v035(!str_contains($encoded,'arguments'),'raw arguments must never be exposed to VP3 policy UI');

$statusApi=(string)file_get_contents($root.'/api/homeserver-status.php');
$approvalsApi=(string)file_get_contents($root.'/api/homeserver-approvals-v028.php');
$sidebar=(string)file_get_contents($root.'/includes/main-sidebar.php');
$approvalsPage=(string)file_get_contents($root.'/approvals.php');
$homeJs=(string)file_get_contents($root.'/homeserver-vp3.js');
$approvalsJs=(string)file_get_contents($root.'/approvals-v028.js');
$policySource=(string)file_get_contents($root.'/includes/homeserver-policy-v035.php');

expect_v035(str_contains($statusApi,"'policy'"),'HomeServer status API must expose policy only when requested');
expect_v035(str_contains($statusApi,'homeserver_policy_v035_snapshot'),'HomeServer status API must use the sanitized policy bridge');
expect_v035(str_contains($approvalsApi,'homeserver_policy_v035_snapshot'),'Approvals API must attach effective policy');
expect_v035(str_contains($sidebar,'vp3HomeServerPolicySummary')&&str_contains($sidebar,'vp3HomeServerPolicies'),'canonical HomeServer modal must contain Agent permission UI');
expect_v035(str_contains($approvalsPage,'approvalsPolicySummary')&&str_contains($approvalsPage,'approvalsPolicyList'),'Approvals must contain effective-policy UI');
expect_v035(str_contains($homeJs,"params.set('policy','1')"),'modal refresh must explicitly request policy');
expect_v035(str_contains($homeJs,'load(false,false)'),'background HomeServer polling must remain status-only');
expect_v035(str_contains($policySource,"'tools.list'"),'VP3 policy visibility must use HomeServer tools.list as the canonical transport');
expect_v035(!str_contains($policySource,'action-policies'),'VP3 policy bridge must not expose HomeServer policy mutation routes');
expect_v035(!str_contains($homeJs,'.innerHTML')&&!str_contains($approvalsJs,'.innerHTML'),'remote policy data must be rendered with safe DOM text APIs');
expect_v035(str_contains($approvalsJs,"approval_required"),'approval cards must understand approval-required policy');

fwrite(STDOUT,"VP3 HomeServer Agent permissions v0.35 contract passed\n");
