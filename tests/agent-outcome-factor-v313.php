<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/agent-action-system-v124.php';

function outcome_expect(bool $condition,string $label): void
{
    if(!$condition){fwrite(STDERR,"FAIL {$label}\n");exit(1);}
    fwrite(STDOUT,"PASS {$label}\n");
}

$neutral=agent_action_v124_outcome_factor([]);
$acted=agent_action_v124_outcome_factor(['acted_unresolved'=>1]);
$successful=agent_action_v124_outcome_factor(['successful'=>1]);
$resolved=agent_action_v124_outcome_factor(['resolved'=>1]);
$ignored=agent_action_v124_outcome_factor(['ignored'=>1]);
$unsuccessful=agent_action_v124_outcome_factor(['unsuccessful'=>1]);
$repeatedFailure=agent_action_v124_outcome_factor(['unsuccessful'=>3]);
$mixedPositive=agent_action_v124_outcome_factor(['successful'=>2,'resolved'=>1,'unsuccessful'=>1]);
$mixedNegative=agent_action_v124_outcome_factor(['successful'=>1,'unsuccessful'=>2,'ignored'=>2]);

outcome_expect(abs($neutral-1.0)<0.000001,'neutral source has neutral factor');
outcome_expect($successful>$resolved&&$resolved>$acted&&$acted>1.0,'final positive outcomes outweigh unresolved action');
outcome_expect($unsuccessful<$ignored&&$ignored<1.0,'failure is stronger negative evidence than ignored');
outcome_expect($repeatedFailure<$unsuccessful,'repeated failures receive an additional penalty');
outcome_expect($mixedPositive>1.0,'net positive history raises source weight');
outcome_expect($mixedNegative<1.0,'net negative history lowers source weight');
foreach([$neutral,$acted,$successful,$resolved,$ignored,$unsuccessful,$repeatedFailure,$mixedPositive,$mixedNegative] as $factor){
    outcome_expect($factor>=0.55&&$factor<=1.25,'factor remains inside safety bounds');
}
outcome_expect(agent_action_v313_normalize_outcome('worked')==='successful','worked normalizes to successful');
outcome_expect(agent_action_v313_normalize_outcome('done')==='resolved','done normalizes to resolved');
outcome_expect(agent_action_v313_normalize_outcome('did not work')==='unsuccessful','did not work normalizes to unsuccessful');
outcome_expect(agent_action_v313_normalize_outcome('', 'dismissed')==='ignored','legacy dismissed normalizes to ignored');
outcome_expect(agent_action_v313_normalize_outcome('', 'acted')==='acted','legacy acted remains unresolved action');

fwrite(STDOUT,"AGENT_OUTCOME_FACTOR_V313=PASS\n");
