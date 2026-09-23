<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/cognitive-economics-v2550.php';

function v2550_assert(bool $condition,string $message): void
{
    if(!$condition)throw new RuntimeException($message);
    echo "PASS: {$message}\n";
}

v2550_assert(abs(vp3_cognitive_economics_attribution_share_v2550(1)-1.0)<0.00001,'single-goal run receives full attribution');
v2550_assert(abs(vp3_cognitive_economics_attribution_share_v2550(2)-0.5)<0.00001,'two-goal shared run splits attribution evenly');
v2550_assert(abs(vp3_cognitive_economics_attribution_share_v2550(4)-0.25)<0.00001,'four-goal shared run splits attribution evenly');

v2550_assert(vp3_cognitive_economics_efficiency_v2550(null,1000)===0.5,'unknown goal cost stays neutral');
v2550_assert(vp3_cognitive_economics_efficiency_v2550(1000,null)===0.5,'unknown account baseline stays neutral');
v2550_assert(abs(vp3_cognitive_economics_efficiency_v2550(1000,1000)-0.5)<0.0001,'equal cloud cost is neutral');
v2550_assert(vp3_cognitive_economics_efficiency_v2550(500,1000)>0.5,'lower-than-cloud-average cost is more efficient');
v2550_assert(vp3_cognitive_economics_efficiency_v2550(2000,1000)<0.5,'higher-than-cloud-average cost is less efficient');

$base=[
    'execution_mode'=>'autonomous',
    'executor'=>'cloud',
    'commitment_protection_score'=>0.0,
];

v2550_assert(vp3_cognitive_economics_adjustment_v2550($base+['commitment_protection_score'=>0.9],1.0,0.1,true)===0.0,'protected commitment is economics-exempt');
v2550_assert(vp3_cognitive_economics_adjustment_v2550($base+['execution_mode'=>'manual'],1.0,0.1,true)===0.0,'manual work is not economically reordered');
v2550_assert(vp3_cognitive_economics_adjustment_v2550($base+['execution_mode'=>'supervised'],1.0,0.1,true)===0.0,'supervised work is not economically reordered');
v2550_assert(vp3_cognitive_economics_adjustment_v2550($base+['executor'=>'homeserver'],1.0,0.1,true)===0.0,'HomeServer work is not penalized by cloud economics');
v2550_assert(vp3_cognitive_economics_adjustment_v2550($base,1.0,0.1,false)===0.0,'unknown pricing creates no planning penalty');
v2550_assert(vp3_cognitive_economics_adjustment_v2550($base,0.20,0.1,true)===0.0,'low token pressure creates no planning penalty');

$expensive=vp3_cognitive_economics_adjustment_v2550($base,1.0,0.2,true);
$efficient=vp3_cognitive_economics_adjustment_v2550($base,1.0,0.8,true);
v2550_assert($expensive<0.0,'expensive autonomous cloud work may be deferred when quota pressure is high');
v2550_assert($efficient>0.0,'efficient autonomous cloud work may be preferred when quota pressure is high');
v2550_assert($expensive>=-0.10&&$efficient<=0.08,'economic adjustment remains bounded');

echo "Cognitive Economics v25.50 deterministic runtime: PASS\n";
