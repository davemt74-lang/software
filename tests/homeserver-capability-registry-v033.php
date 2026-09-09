<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/homeserver-capability-registry-v033.php';

function v033_assert(bool $condition,string $message): void
{
    if(!$condition){
        fwrite(STDERR,"FAIL: {$message}\n");
        exit(1);
    }
}

$raw=[
    'registry_version'=>'v0.33',
    'service'=>'HomeServer',
    'version'=>'0.33.0',
    'app'=>[
        'key'=>'vp3',
        'name'=>'VP3',
        'permissions'=>['agent.chat','memory.read','knowledge.search','tools.execute'],
        'scope'=>[
            'cloud_allowed'=>false,
            'memory_key_prefixes'=>['vp3:'],
            'knowledge_kinds'=>['note'],
            'tool_names'=>['knowledge.search'],
            'plugin_keys'=>[],
            'bearer_token'=>'must-not-survive',
        ],
    ],
    'brain'=>['available'=>true,'primary'=>['id'=>4,'name'=>'Primary','model'=>'llama3.2','updated_at'=>'2026-09-09T12:00:00Z','instructions'=>'secret prompt']],
    'compute'=>[
        'available'=>true,'preferred_provider'=>'ollama','selected_provider'=>'ollama','model'=>'llama3.2','compute_source'=>'local',
        'cloud_fallback_required'=>false,
        'providers'=>[['key'=>'ollama','name'=>'Ollama','kind'=>'local','model'=>'llama3.2','enabled'=>true,'ready'=>true,'compute_source'=>'local','api_key'=>'secret']],
        'installed_local_models'=>['llama3.2','qwen2.5'],
    ],
    'memory'=>['available'=>true,'readable'=>true,'writable'=>false,'visible_items'=>7,'restricted'=>true],
    'knowledge'=>['available'=>true,'searchable'=>true,'writable'=>false,'visible_items'=>11,'visible_kinds'=>['note'],'restricted'=>true],
    'files'=>['available'=>true,'source_count'=>2,'enabled_sources'=>2,'tracked_files'=>45,'indexed_files'=>44,'supported_extensions'=>['.pdf','.txt'],'path'=>'C:\\secret'],
    'contacts'=>['available'=>true,'readable'=>true,'visible_contacts'=>12,'items'=>[['email'=>'private@example.com']]],
    'tools'=>[['key'=>'knowledge.search','name'=>'Knowledge search','mode'=>'read','enabled'=>true,'available'=>true,'required_permissions'=>['knowledge.search'],'command'=>'secret']],
    'skills'=>[['key'=>'local.research','name'=>'Local research','available'=>true,'tools'=>['knowledge.search']]],
    'plugins'=>[],
    'services'=>[['key'=>'homeserver','available'=>true,'status'=>'running','version'=>'0.33.0','base_url'=>'http://secret']],
    'operations'=>['capability.registry','agent.chat','knowledge.search'],
    'counts'=>['tools'=>1,'available_tools'=>1,'skills'=>1,'available_skills'=>1,'plugins'=>0,'local_models'=>2],
    'relay_token'=>'top-secret',
];

$registry=homeserver_capability_v033_normalize($raw);
v033_assert($registry['available']===true,'registry should normalize as available');
v033_assert($registry['registry_version']==='v0.33','registry version should be preserved');
v033_assert($registry['app']['scope']['cloud_allowed']===false,'cloud policy should be preserved');
v033_assert($registry['brain']['primary']['name']==='Primary','brain summary should be preserved');
v033_assert($registry['compute']['installed_local_models']===['llama3.2','qwen2.5'],'local model inventory should be preserved');
v033_assert($registry['files']['indexed_files']===44,'file index count should be preserved');
v033_assert($registry['contacts']['visible_contacts']===12,'contact count should be preserved without contact records');
v033_assert($registry['tools'][0]['key']==='knowledge.search','scoped tool should be preserved');

$facts=homeserver_capability_v033_gateway_facts($registry);
v033_assert($facts['home_supported']===true,'agent.chat operation should mark HomeServer supported');
v033_assert($facts['home_ready']===true,'available compute should mark HomeServer ready');
v033_assert($facts['homeserver_cloud_allowed']===false,'gateway facts must respect app cloud scope');
v033_assert($facts['home_provider']==='ollama','selected provider should feed gateway facts');
v033_assert($facts['home_allowed_models']===['llama3.2','qwen2.5'],'allowed local model list should feed gateway facts');

$encoded=strtolower((string)json_encode($registry,JSON_UNESCAPED_SLASHES));
foreach(['relay_token','bearer_token','api_key','instructions','private@example.com','c:\\secret','base_url','command'] as $forbidden){
    v033_assert(!str_contains($encoded,strtolower($forbidden)),"secret/private field leaked: {$forbidden}");
}

$empty=homeserver_capability_v033_empty('offline');
v033_assert($empty['available']===false&&$empty['reason']==='offline','offline registry should fail closed');
v033_assert(homeserver_capability_v033_gateway_facts($empty)['home_ready']===false,'offline registry must not advertise ready compute');

echo "VP3 v0.33 HomeServer capability registry regression passed\n";
