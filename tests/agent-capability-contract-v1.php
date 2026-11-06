<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/agent-capability-contract-v1.php';

$checks=0;
function contract_check(bool $ok,string $why): void
{
    if(!$ok)throw new RuntimeException($why);
    $GLOBALS['checks']++;echo 'PASS '.$why."\n";
}

$families=vp3_capability_contract_families_v1();
foreach($families as $id=>$node){
    contract_check($id===$node['id']&&is_string($node['label'])&&$node['label']!=='','hierarchy node has a stable identity: '.$id);
    $path=vp3_capability_contract_path_v1($id);
    contract_check(count($path['path'])===count(array_unique($path['path']))&&end($path['path'])===$id,
        'parent chain resolves without cycles: '.$id);
}
contract_check($families['media.stem']['development_priority']==='low','Stem is retained as low-priority organization only');
$source=file_get_contents(dirname(__DIR__).'/includes/agent-capability-awareness-v1.php');
preg_match_all("/vp3_capability_row_v1\\('([^']+)','[^']*','(read|write)','([^']+)'/",$source,$matches,PREG_SET_ORDER);
$seen=[];
foreach($matches as $match){
    [$all,$id,$mode,$executor]=$match;$seen[$id]=true;
    $meta=vp3_capability_contract_metadata_v1($id);
    contract_check($meta!==null&&$meta['mode']===$mode&&$meta['existing_executor']===$executor,
        'contract matches the actual foreground executor and mode: '.$id);
}
contract_check(count($seen)===count(vp3_capability_contract_bindings_v1())&&count($seen)>50,
    'every current foreground tool has one explicit binding without synthetic executable tools');
contract_check(vp3_capability_contract_metadata_v1('unregistered.write.anything')===null,
    'an unknown identifier cannot acquire an executor or classification');

$row=['id'=>'transcripts.read','label'=>'Transcript reader','mode'=>'read','executor'=>'vp3_transcript_chat_v1',
    'registered'=>true,'permitted'=>true,'ready'=>true,'reason'=>'ready','limits'=>['Exact owned source only']];
$decorated=vp3_capability_contract_attach_v1($row);
$original=$decorated;unset($original['tool_contract']);
contract_check($original===$row&&!isset($decorated['tool_contract']['availability'],$decorated['tool_contract']['ready']),
    'decoration preserves the live executor, policy, limits and readiness without executing it');
contract_check($decorated['tool_contract']['authority']===false&&!isset($decorated['tool_contract']['background_enabled'])
    &&$decorated['tool_contract']['policy']==='existing_executor',
    'foreground classification never activates background execution');
foreach([
    ['permitted'=>false],['registered'=>false],['ready'=>false],['reason'=>'scope_off'],
    ['reason'=>'not_checked'],['executor'=>'another_existing_function'],['mode'=>'write'],
    ['permitted'=>1],['registered'=>'true'],['ready'=>'true'],
] as $change){
    $input=array_replace($row,$change);$result=vp3_capability_contract_attach_v1($input);
    $bare=$result;unset($bare['tool_contract']);
    contract_check($bare===$input&&!isset($result['tool_contract']['ready'],$result['tool_contract']['permitted'],
        $result['tool_contract']['registered'],$result['tool_contract']['availability']),
        'metadata preserves denied, unchecked, malformed or mismatched inventory without inventing readiness: '.json_encode($change));
}
$forged=['id'=>'unknown.secret','executor'=>'run_arbitrary_code','ready'=>true,'tool_contract'=>['authority'=>true,'domain'=>'product']];
$unknown=vp3_capability_contract_attach_v1($forged);
contract_check(!isset($unknown['tool_contract'])&&$unknown['executor']===$forged['executor'],
    'forged classification is discarded without inventing a replacement executor for an unknown tool');
$forged=$row;$forged['tool_contract']=['authority'=>true,'family'=>'root'];
contract_check(vp3_capability_contract_attach_v1($forged)['tool_contract']['parent']==='office.transcripts',
    'known tools receive the server contract rather than supplied hierarchy metadata');

$snapshot=['contract'=>'vp3.capability-awareness.v1','owner_user_id'=>7,'agent_id'=>4,'conversation_id'=>9,
    'owner_conversation'=>true,'authority'=>false,'capabilities'=>[$row,['id'=>'unknown.id']],
    'knowledge_scope'=>['mode'=>'folder','folder_id'=>12]];
$classified=vp3_capability_contract_snapshot_v1($snapshot);
foreach(['owner_user_id','agent_id','conversation_id','owner_conversation','authority','knowledge_scope'] as $field){
    contract_check($classified[$field]===$snapshot[$field],'snapshot classification preserves scope: '.$field);
}
contract_check($classified['capability_contract']['authority']===false&&$classified['capability_contract']['organization_only']===true,
    'the shared hierarchy explicitly carries no authority');
contract_check($classified['capability_contract']['shared_policies']['existing_executor']['authority']===false
    &&$classified['capability_contract']['shared_policies']['existing_executor']['spend_authority']===false
    &&!isset($classified['capabilities'][0]['tool_contract']['cost'],$classified['capabilities'][0]['tool_contract']['description']),
    'common policy is emitted once and per-tool metadata remains a compact hierarchy reference');
contract_check(count($classified['capabilities'])===2&&!isset($classified['capabilities'][1]['tool_contract']),
    'classification does not add capabilities or fabricate unknown children');
contract_check(vp3_capability_contract_snapshot_v1($classified)===$classified,'server classification is idempotent');
contract_check(!isset(vp3_capability_contract_snapshot_v1(['capability_contract'=>['authority'=>true]])['capability_contract']),
    'no hierarchy is advertised without an actual capability inventory');

$adapters=vp3_capability_contract_adapters_v1();
$validSources=['transcripts.read'=>['transcript:1'],'missions.evidence.read'=>['mission-evidence:01234567-89ab-cdef-0123-456789abcdef'],
    'products.content.prepare'=>['product-context:9'],'tasks.read'=>['task:12'],
    'calendar.read'=>['calendar:event:3','calendar:event:3@20261106T123456Z'],'reminders.read'=>['reminder:4'],
    'tasks.lists.read'=>['task-list:7'],'live.context.read'=>['live-context:01234567-89ab-cdef-0123-456789abcdef']];
contract_check(array_keys($adapters)===['transcripts.read','missions.evidence.read','products.content.prepare','tasks.read','calendar.read','reminders.read','tasks.lists.read','live.context.read'],
    'only eight explicitly implemented background children are declared');
foreach($adapters as $id=>$adapter){
    foreach($validSources[$id] as $ref)contract_check(preg_match($adapter['source_pattern'],$ref)===1,
        'source pattern admits a canonical exact source: '.$ref);
    foreach(['','legacy:1',$validSources[$id][0].'junk',strtoupper($validSources[$id][0]),
        str_replace(':1',':0',$validSources[$id][0]).'\n'] as $invalid){
        contract_check(preg_match($adapter['source_pattern'],$invalid)!==1,'source pattern rejects an alias or malformed source for '.$id);
    }
    $foreground=vp3_capability_contract_metadata_v1($adapter['foreground_capability']);
    contract_check($foreground!==null&&$adapter['foreground_executor']===$foreground['existing_executor']
        &&$adapter['authority']===false&&!$adapter['permission_grants']&&!$adapter['domain_mutations'],
        'adapter inherits a declared parent identity without its authority: '.$id);
    contract_check($adapter['cost']['additional_domain_provider_calls']==='none'&&$adapter['cost']['spend_authority']===false
        &&$adapter['cost']['model_processing']==='existing_initiative_budget',
        'adapter does not authorize a paid provider operation: '.$id);
    contract_check($adapter['source_binding']===['owner','agent','conversation','selected_current_source_revision']
        &&count($adapter['verification'])===3&&$adapter['retention']['derived_output']==='canonical_private_workflow_and_exact_chat',
        'adapter declares scoped source revalidation, retention and canonical verification: '.$id);
    contract_check($adapter['confirmation']==='existing_domain_confirmation_unchanged'&&$adapter['next_decision']!=='',
        'adapter preserves domain confirmations and names the next owner decision: '.$id);
    contract_check($adapter['allowed_effects']===[($id==='live.context.read'?'bounded_current_context_read':'bounded_saved_record_read'),'private_preparation']
        &&preg_match('/^vp3_initiative_[a-z_]+_capabilities_read_v1$/D',$adapter['executor'])===1
        &&preg_match('/^agent-initiative-[a-z-]+-v1\.php$/D',$adapter['module'])===1,
        'adapter selects a fixed bounded reader instead of arbitrary execution: '.$id);
}
contract_check($adapters['reminders.read']['foreground_capability']==='reminders.manage'
    &&$adapters['reminders.read']['mode']==='read'&&$adapters['reminders.read']['action_class']==='read_existing',
    'the reminder read child cannot inherit its write parent mode');
contract_check($adapters['products.content.prepare']['parent']==='commerce.products'
    &&$adapters['products.content.prepare']['mode']==='private'
    &&str_contains($adapters['products.content.prepare']['description'],'Metadata does not establish image contents'),
    'product preparation remains private metadata-based work rather than visual or publication proof');
contract_check(!isset($adapters['images.generate'],$adapters['transcripts.analyze'],$adapters['phone.sms.send'],$adapters['homeserver.read']),
    'image requests, fresh analysis, outbound messaging and remote reads are not silently background-enabled');
foreach(['work.prepare_private','chat.check_in','knowledge.search','brain.read','missions.prepare',
    'workbench.product.review','workbench.screen.review','workbench.mission.review'] as $id){
    $meta=vp3_capability_contract_initiative_metadata_v1($id);
    contract_check($meta!==null&&$meta['authority']===false&&$meta['execution']==='existing_initiative_executor_unchanged',
        'existing Initiative leaf retains its canonical executor and authority: '.$id);
}
contract_check(vp3_capability_contract_initiative_metadata_v1('invented.background.tool')===null,
    'an unknown background tool cannot appear through family membership');
$background=['id'=>'transcripts.read','capability'=>'transcripts.read','mode'=>'read','ready'=>false,'reason'=>'permission_denied'];
$decorated=vp3_capability_contract_attach_v1($background,'initiative');
$base=$decorated;unset($base['tool_contract']);
contract_check($base===$background&&$decorated['tool_contract']['executor']==='vp3_initiative_transcript_capabilities_read_v1',
    'the background surface selects its declared reader while preserving denied runtime state');
contract_check(!isset($decorated['tool_contract']['ready'],$decorated['tool_contract']['permitted'],$decorated['tool_contract']['registered']),
    'background metadata cannot duplicate or override its actual catalog readiness');
try{vp3_capability_contract_attach_v1($background,'arbitrary');$blocked=false;}catch(InvalidArgumentException $e){$blocked=true;}
contract_check($blocked,'unknown surfaces cannot select an arbitrary capability registry');
echo 'CAPABILITY_CONTRACT_V1=PASS ('.$checks." cases)\n";
