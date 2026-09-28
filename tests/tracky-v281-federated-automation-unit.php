<?php
declare(strict_types=1);
require __DIR__.'/../includes/tracky-cloud-v270.php';
function fa281_fail(string $m): never{fwrite(STDERR,$m."\n");exit(1);}
function fa281_expect(bool $v,string $m): void{if(!$v)fa281_fail($m);}
$home='11111111-1111-4111-8111-111111111111';$office='22222222-2222-4222-8222-222222222222';
$n=tracky_v281_fa_normalize([
 'protocol'=>'physical_federated_automation.v1','generated_at'=>1759082400000,'local_site_id'=>$home,
 'cloud_read_only'=>true,'remote_action_execution'=>false,'authority_mutation'=>false,
 'definitions'=>[[
   'automation_id'=>'fa:test','revision'=>1,'name'=>'Test','state'=>'active','origin_site_id'=>$home,'trigger_kind'=>'world_state',
   'participating_site_ids'=>[$home,$office],'participating_device_ids'=>['node-office'],'step_count'=>2,'default_deadline_ms'=>60000
 ]],
 'runs'=>[[
   'run_id'=>'run-1','automation_id'=>'fa:test','automation_revision'=>1,'origin_site_id'=>$home,'state'=>'waiting','deadline_at_ms'=>1759082460000,
   'step_states'=>['home-check'=>'ready','office-light'=>'blocked']
 ]]
]);
fa281_expect($n['version']==='2.81','version');fa281_expect($n['counts']['definitions']===1,'definitions');fa281_expect($n['counts']['active_runs']===1,'active runs');
fa281_expect($n['cloud_read_only']===true,'read-only');fa281_expect($n['remote_action_execution']===false,'execution boundary');
try{tracky_v281_fa_normalize(['protocol'=>'physical_federated_automation.v1','local_site_id'=>$home,'cloud_read_only'=>false]);fa281_fail('writable projection accepted');}
catch(Throwable $e){fa281_expect(str_contains($e->getMessage(),'read-only'),'read-only error');}
try{tracky_v281_fa_normalize(['protocol'=>'physical_federated_automation.v1','local_site_id'=>$home,'cloud_read_only'=>true,'definitions'=>[['automation_id'=>'bad','origin_site_id'=>$office,'state'=>'active']]]);fa281_fail('foreign origin accepted');}
catch(Throwable $e){fa281_expect(str_contains($e->getMessage(),'origin-local'),'origin error');}
$cap=tracky_v281_fa_public_capability();fa281_expect($cap['cloud_execution_allowed']===false,'cloud execution');fa281_expect($cap['execution_enabled']===false,'section execution');fa281_expect($cap['schema_version']===54,'schema');
echo "TRACKY_V281_FEDERATED_AUTOMATION_UNIT=PASS\n";
