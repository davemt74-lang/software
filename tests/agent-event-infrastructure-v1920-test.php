<?php
declare(strict_types=1);
require __DIR__.'/../includes/agent-event-infrastructure-v1920.php';

function v1920_assert(bool $condition,string $message): void{if(!$condition)throw new RuntimeException($message);}

$payload=[
    'order_id'=>'ord_123',
    'token'=>'must disappear',
    'nested'=>['filesystem_path'=>'C:\\private\\file.txt','ok'=>'visible'],
    'items'=>[['name'=>'Pizza','secret'=>'hidden']],
];
$safe=agent_event_sanitize_payload_v1920($payload);
v1920_assert(($safe['order_id']??'')==='ord_123','safe scalar lost');
v1920_assert(!array_key_exists('token',$safe),'token key leaked');
v1920_assert(!array_key_exists('filesystem_path',$safe['nested']??[]),'filesystem path leaked');
v1920_assert(($safe['nested']['ok']??'')==='visible','safe nested value lost');
v1920_assert(!array_key_exists('secret',$safe['items'][0]??[]),'nested secret leaked');

$canonicalA=agent_event_canonicalize_v1920(['b'=>2,'a'=>['d'=>4,'c'=>3]]);
$canonicalB=agent_event_canonicalize_v1920(['a'=>['c'=>3,'d'=>4],'b'=>2]);
v1920_assert(agent_event_json_v1920($canonicalA)===agent_event_json_v1920($canonicalB),'canonical event ordering is unstable');

$key='unit-test-key';$body='{"id":"evt_1"}';$timestamp=(string)time();$signature=hash_hmac('sha256',$timestamp.'.'.$body,$key);
v1920_assert(agent_event_verify_hmac_v1920($body,$signature,$key,$timestamp),'valid HMAC rejected');
v1920_assert(!agent_event_verify_hmac_v1920($body,str_repeat('0',64),$key,$timestamp),'invalid HMAC accepted');
v1920_assert(!agent_event_verify_hmac_v1920($body,$signature,$key,(string)(time()-3600)),'stale HMAC accepted');

$row=['event_uuid'=>'123e4567-e89b-12d3-a456-426614174000','source'=>'provider','event_type'=>'order.created'];
$priority=agent_event_priority_v1920($row,['title'=>'Review order','prompt'=>'Review this verified order event.']);
v1920_assert($priority['key']==='event-123e4567-e89b-12d3-a456-426614174000','event priority key is not deterministic');
v1920_assert(strlen((string)$priority['suggestion_hash'])===40,'event priority hash is invalid');

$hashA=agent_event_dedupe_hash_v1920(7,'provider','order.created','evt_1',['x'=>1]);
$hashB=agent_event_dedupe_hash_v1920(7,'provider','order.created','evt_1',['x'=>999]);
v1920_assert(hash_equals($hashA,$hashB),'external event id must be the dedupe authority when supplied');

agent_event_register_handler_v1920('provider','order.created',static fn(array $event,array $user): array=>['summary'=>'Observed']);
$handlers=&agent_event_handlers_v1920();v1920_assert(isset($handlers['provider|order.created']),'handler registry failed');

agent_event_register_webhook_source_v1920('provider',static fn(string $raw,array $headers): array=>['verified'=>true,'user'=>['id'=>7],'event_type'=>'order.created','payload'=>[]],['order.created']);
$sources=&agent_event_webhook_sources_v1920();v1920_assert(isset($sources['provider']['types']['order.created']),'webhook source allowlist failed');

echo "Webhook + Event Infrastructure v19.2 runtime safety: OK\n";
