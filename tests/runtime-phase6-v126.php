<?php
declare(strict_types=1);

$root=sys_get_temp_dir().'/stonefellow-v126-'.bin2hex(random_bytes(6));
if(!mkdir($root,0700,true)&&!is_dir($root))throw new RuntimeException('Could not create test root.');
define('STONEFELLOW_ROOT',$root);
require dirname(__DIR__).'/includes/agent-runtime-v125.php';
require dirname(__DIR__).'/includes/agent-ops-v126.php';

function phase6_assert(bool $condition,string $label): void
{
    echo ($condition?'PASS ':'FAIL ').$label."\n";
    if(!$condition)throw new RuntimeException('Failed: '.$label);
}
function phase6_remove_tree(string $dir): void
{
    if(!is_dir($dir))return;
    $items=scandir($dir)?:[];
    foreach($items as $item){if($item==='.'||$item==='..')continue;$path=$dir.'/'.$item;if(is_dir($path))phase6_remove_tree($path);else @unlink($path);}
    @rmdir($dir);
}

try{
    $self=agent_runtime_v126_self_test();
    phase6_assert(!empty($self['passed']),'deterministic v126 self-test passes');
    phase6_assert(count($self['checks']??[])>=6,'self-test covers resilience primitives');

    $safe=agent_runtime_v126_safe_row('test',['authorization'=>'Bearer secret-token-value','note'=>'person@example.com called 480-555-1212','status'=>'ok']);
    phase6_assert(($safe['authorization']??'')==='[redacted]','sensitive-key redaction');
    phase6_assert(!str_contains((string)($safe['note']??''),'person@example.com'),'email redaction');
    phase6_assert(!str_contains((string)($safe['note']??''),'480-555-1212'),'phone redaction');

    $oldTrace=agent_runtime_v125_dir('traces').'/2000-01-01.jsonl';file_put_contents($oldTrace,"{}\n");touch($oldTrace,time()-20*86400);
    $oldVoice=agent_runtime_v125_dir('voice-sessions').'/old.json';file_put_contents($oldVoice,'{}');touch($oldVoice,time()-40*86400);
    $cleanup=agent_runtime_v126_cleanup();
    phase6_assert(!is_file($oldTrace),'trace retention removes expired trace');
    phase6_assert(!is_file($oldVoice),'voice retention removes expired session');
    phase6_assert((int)($cleanup['removed']??0)>=2,'cleanup reports removals');

    $health=agent_runtime_v126_health_snapshot();
    phase6_assert(($health['build']??'')===STONEFELLOW_PRODUCTION_V126,'health snapshot reports production build');
    phase6_assert(isset($health['jobs'],$health['breakers'],$health['voice'],$health['retention_seconds']),'health snapshot covers runtime subsystems');
    echo "PHASE6_RUNTIME_SELFTEST=PASS\n";
}finally{
    phase6_remove_tree($root);
}
