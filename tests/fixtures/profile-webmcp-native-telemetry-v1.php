<?php
declare(strict_types=1);
function vp3_profile_webmcp_transport_id_v130(string $value): string{return preg_match('/^[A-Za-z0-9_-]{8,96}$/D',$value)?$value:'';}
function vp3_profile_webmcp_telemetry_v130(array $input): array{return is_array($input['telemetry']??null)?array_intersect_key($input['telemetry'],array_flip(['webmcp_session_id','interaction_id','correlation_id'])):[];}
function vp3_profile_webmcp_context_v130(...$args): ?array{return null;}
function vp3_profile_webmcp_record_v130(PDO $pdo,?array $ctx,string $event,string $tool='',string $status='',int $duration=0,array $links=[]): void{file_put_contents(dirname(__DIR__).'/telemetry.jsonl',json_encode(compact('ctx','event','tool','status','links'))."\n",FILE_APPEND);}
