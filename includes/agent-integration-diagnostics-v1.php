<?php
declare(strict_types=1);
/** Installed evidence, read-only. Transport connection never implies current data. */
function vp3_agent_integration_diagnostics_v1(array $user): array
{
    $uid=(int)($user['id']??0);
    if($uid<1)throw new RuntimeException('Sign in to run integration diagnostics.',401);
    $checks=[];
    $check=static function(string $key,string $status,string $detail,string $next='') use (&$checks): void {
        $checks[]=['key'=>$key,'status'=>$status,'detail'=>$detail,'next_action'=>$next];
    };
    $manifest=[];$path=dirname(__DIR__).'/VP3_RELEASE.json';
    if(is_file($path)&&filesize($path)<=65536){$decoded=json_decode((string)file_get_contents($path),true);if(is_array($decoded))$manifest=$decoded;}
    $cloud=['section'=>(string)($manifest['mission_lifecycle_section']??''),'source_commit'=>(string)($manifest['commit']??''),'minimum_schema'=>(int)($manifest['minimum_mission_schema']??0)];
    $current=$cloud['section']==='A5C11'&&$cloud['minimum_schema']>=79&&preg_match('/^[a-f0-9]{40}$/',$cloud['source_commit']);
    $check('cloud_release',$current?'ready':'not_verified','Installed Cloud release provenance '.($current?'identifies A5C11.':'is missing or does not identify A5C11.'),$current?'':'Deploy the matching Cloud archive and retain its release manifest.');
    $status=homeserver_https_v1300_status($uid);
    $connected=is_array($status)&&!empty($status['connected']);
    $check('transport',$connected?'ready':'blocked',$connected?'The paired HomeServer heartbeat is current.':'HomeServer is unpaired or its heartbeat is stale.',$connected?'':'Open HomeServer and review pairing and HTTPS relay status.');
    $home=null;
    if($connected){
        try{
            $nonce=bin2hex(random_bytes(16));
            $ping=homeserver_https_v1300_remote_operation($uid,'system.ping',['nonce'=>$nonce]);
            $pong=!empty($ping['pong'])&&hash_equals($nonce,(string)($ping['echo']??''));
            $check('round_trip',$pong?'ready':'blocked',$pong?'Authenticated relay round trip echoed the fresh diagnostic nonce.':'Relay response did not match this diagnostic.',$pong?'':'Check account pairing and stale relay sessions.');
            $remote=homeserver_https_v1300_remote_operation($uid,'agent.missions.diagnostics.get',[]);
            $raw=$remote['diagnostics']??null;
            if(!is_array($raw)||($raw['contract']??'')!=='vp3.agent-integration.diagnostics.v1')throw new RuntimeException('Unsupported diagnostic contract.');
            $home=array_intersect_key($raw,array_flip(['contract','section','schema','build_id','checked_at','read_only','token_spend','write_actions_executed','browser_pages_opened','source','checks','ready','sync','connection','inference','tools','skills','plugins','browser_tasks']));
            $match=($home['section']??'')==='A5C11'&&(int)($home['schema']??0)>=79;
            $check('matching_contract',$match?'ready':'blocked',$match?'Installed HomeServer supports the matching A5C11/schema 79 contract.':'Cloud and HomeServer do not advertise the matching mission contract.',$match?'':'Install the matching HomeServer deployment ZIP.');
            $check('home_runtime',!empty($home['ready'])?'ready':'blocked',!empty($home['ready'])?'HomeServer runtime checks are ready.':'One or more installed HomeServer checks need attention.',!empty($home['ready'])?'':'Review the HomeServer checks below; no repairs were executed.');
        }catch(Throwable $e){
            $check('home_diagnostic','blocked','The authenticated HomeServer diagnostic could not complete.','Check the paired app agent.chat permission, HomeServer version, relay worker and database upgrade.');
        }
    }
    return ['contract'=>'vp3.agent-integration.diagnostics.v1','section'=>'A5C11','checked_at'=>gmdate('c'),
            'read_only'=>true,'token_spend'=>0,'write_actions_executed'=>0,'browser_pages_opened'=>0,
            'ready'=>!array_filter($checks,static fn(array $c):bool=>$c['status']!=='ready'),
            'cloud'=>$cloud,'checks'=>$checks,'homeserver'=>$home,
            'research'=>['chrome_companion'=>'v22.30 existing domain-approved research runtime',
                         'cloud_projects'=>'v20.60 findings, evidence and reports',
                         'homeserver'=>'local.research and separately approved browser.read specialists',
                         'private_context_export'=>'current permissions and conversation privacy remain required']];
}
