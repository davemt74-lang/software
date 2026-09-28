<?php
declare(strict_types=1);

function fcc_fail(string $m): never { fwrite(STDERR,$m."\n"); exit(1); }
function fcc_expect(bool $v,string $m): void { if(!$v)fcc_fail($m); }

$root=dirname(__DIR__);
$page=file_get_contents($root.'/tracky.php');
$script=file_get_contents($root.'/tracky-federation-control-center-v280.js');
$style=file_get_contents($root.'/tracky-federation-control-center-v280.css');

fcc_expect(str_contains($page,'id="federationControlCenter"'),'Tracky page must render the federation control center');
fcc_expect(str_contains($page,'tracky-federation-control-center-v280.css'),'Tracky page must load federation control center CSS');
fcc_expect(str_contains($page,'tracky-federation-control-center-v280.js'),'Tracky page must load federation control center JS');
fcc_expect(str_contains($page,'tracky-federation-operations-v280.php'),'Tracky page must target V2.80 operations endpoint');
fcc_expect(str_contains($script,'credentials:\'same-origin\''),'Cloud control center must use same-origin session');
fcc_expect(str_contains($script,'local freshness is never inferred here'),'Cloud freshness boundary must be visible');
foreach(["method:'POST'","method:'PUT'","method:'PATCH'","method:'DELETE'"] as $mutation){
    fcc_expect(!str_contains($script,$mutation),'Section 2 Cloud control center must remain observational');
}
fcc_expect(str_contains($style,'.fed280-site.critical'),'Critical site styling must be present');
echo "TRACKY_V280_FEDERATION_CONTROL_CENTER_UNIT=PASS\n";
