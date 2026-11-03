<?php
declare(strict_types=1);

/** Real native identity, PA3/PA3C ownership and SQL. Only the speech/provider boundary is simulated. */
require_once __DIR__.'/profile-calls-pa3c.php';

function vp3_profile_speech_config_pa4a(): array
{
    return ['ready'=>$GLOBALS['psReady']??true,'reason'=>'','credential_state'=>'configured','config_fingerprint'=>$GLOBALS['psFingerprint']??hash('sha256','speech-config'),'model_id'=>'eleven_multilingual_v2','output_format'=>'mp3_44100_128'];
}
function ps_voices(): array
{
    $out=[];foreach(['female','male','neutral','unclassified'] as $n=>$style){$id='fixtureVoice'.($n+1);$out[]=['preset_key'=>hash('sha256','preset'.$id),'voice_id'=>$id,'name'=>'Fixture '.$style,'style'=>$style,'provenance'=>'provider_default','origin_hash'=>hash('sha256','origin'.$id),'public_owner_id'=>'','original_voice_id'=>''];}return $out;
}
function vp3_profile_speech_catalog_pa4a(?callable $transport=null): array
{
    if(db()->inTransaction())throw new RuntimeException('Catalog metadata requested while SQL locks were held.');
    $GLOBALS['psCatalogCalls']=($GLOBALS['psCatalogCalls']??0)+1;if($hook=$GLOBALS['psCatalogHook']??null)$hook();
    return ['ready'=>$GLOBALS['psReady']??true,'reason'=>'','config_fingerprint'=>vp3_profile_speech_config_pa4a()['config_fingerprint'],'catalog_revision'=>hash('sha256','catalog'),'voices'=>$GLOBALS['psVoices']??ps_voices()];
}
function vp3_profile_speech_owner_voice_pa4a(PDO $pdo,array $user,int $chatAid,int $profileAid): array
{
    return ['ready'=>true,'reason'=>'','narrator'=>['voice_id'=>$GLOBALS['psOwnerVoice']??'fixtureOwnerVoice','name'=>'Owner Agent','provenance'=>'owner_default','origin_hash'=>hash('sha256','owner-origin'.($GLOBALS['psOwnerVoice']??'fixtureOwnerVoice'))],'excluded_voice_ids'=>[$GLOBALS['psOwnerVoice']??'fixtureOwnerVoice']];
}
function vp3_profile_speech_synthesize_pa4a(array $request,?callable $transport=null,?callable $beforeSend=null): array
{
    if(db()->inTransaction())throw new RuntimeException('Paid synthesis occurred while SQL locks were held.');if($beforeSend){try{$beforeSend();}catch(Throwable $e){return ps_result($request,'blocked');}}
    $GLOBALS['psProviderCalls'][]=$request;if($hook=$GLOBALS['psProviderHook']??null)$hook($request);
    return ps_result($request,$GLOBALS['psProviderMode']??'ready');
}
function ps_audio(): string{return str_repeat("\xff\xfb\x90\x00".str_repeat("\0",413),4);}
function ps_result(array $request,string $status='ready'): array
{
    return ['status'=>$status,'reason'=>$status==='ready'?'ready':($status==='blocked'?'voice_unavailable':($status==='rejected'?'provider_request_rejected':'provider_outcome_unverified')),'provider_attempted'=>$status!=='blocked','attempt_id'=>(int)$request['attempt_id'],'config_fingerprint'=>$request['config_fingerprint'],'input_characters'=>(int)$request['input_characters'],'provider_character_cost'=>$status==='ready'?(string)$request['input_characters']:null,'provider_request_id'=>$status==='ready'?'fixture-provider-request':null,'provider_trace_id'=>null,'audio_key'=>$status==='ready'?hash('sha256','audio'.$request['attempt_id']):null,'audio_bytes'=>$status==='ready'?strlen(ps_audio()):0,'audio_sha256'=>$status==='ready'?hash('sha256',ps_audio()):null,'audio_expires_at_ms'=>$status==='ready'?vp3_profile_handoff_now_pa3()+600000:null,'content_type'=>'audio/mpeg'];
}
require_once dirname(__DIR__,2).'/includes/profile-visitor-speech-pa4a.php';

function ps_reset(): void
{
    $p=db();if($p->inTransaction())$p->rollBack();$p->afterSql=null;$p->failSql='';foreach(['profile_speech_attempts_pa4a','profile_speech_items_pa4a','profile_visitor_voices_pa4a'] as $table)if(table_exists($table))$p->exec('DELETE FROM '.$table);
    pa3c_reset();vp3_profile_speech_ensure_schema_pa4a($p);
    if(!column_exists('team_user_presence','agent_voice_enabled'))$p->exec('ALTER TABLE team_user_presence ADD COLUMN agent_voice_enabled INTEGER NOT NULL DEFAULT 1');
    $p->exec("INSERT INTO role_permissions(role,permission_key) VALUES('member','voice_profile.access'),('member','voice.access')");
    $GLOBALS['psReady']=true;$GLOBALS['psFingerprint']=hash('sha256','speech-config');$GLOBALS['psVoices']=ps_voices();$GLOBALS['psOwnerVoice']='fixtureOwnerVoice';$GLOBALS['psCatalogCalls']=0;$GLOBALS['psCatalogHook']=null;$GLOBALS['psProviderCalls']=[];$GLOBALS['psProviderHook']=null;$GLOBALS['psProviderMode']='ready';
}
function ps_context(int $visitor=0,string $browser='b'): array{return pa3c_context($visitor,$browser);}
function ps_owner(): array{return pa3c_owner();}
function ps_scope(array $extra=[]): array{return $extra+['chat_conversation_id'=>11,'chat_agent_id'=>7,'tab_id'=>str_repeat('a',32),'client_instance_id'=>str_repeat('c',32)];}
function ps_page(?array $ctx=null): array{return vp3_profile_speech_arrival_pa4a(db(),$ctx??ps_context())??throw new RuntimeException('Arrival was not saved.');}
function ps_message(string $text='Visitor speech fixture.',string $mode='agent',?array $ctx=null,int $cid=0): array
{
    $ctx??=ps_context();$out=vp3_profile_agent_public_message_service_v110(db(),$ctx,$text,$cid,['async'=>true,'chat_mode'=>$mode,'client_request_id'=>bin2hex(random_bytes(16))]);
    $q=db()->prepare("SELECT * FROM profile_speech_items_pa4a WHERE kind='visitor_message' ORDER BY id DESC LIMIT 1");$q->execute();return ['response'=>$out,'context'=>$ctx,'item'=>$q->fetch(PDO::FETCH_ASSOC)?:null];
}
function ps_state(array $extra=[]): array{return vp3_profile_speech_state_pa4a(db(),ps_owner(),ps_scope($extra));}
function ps_delivery(array $item,array $extra=[]): array{return vp3_profile_speech_delivery_pa4a(db(),ps_owner(),ps_scope($extra+['item_id'=>(int)$item['id'],'revision'=>(int)$item['revision']]));}
function ps_input(array $delivery,array $extra=[]): array
{
    $item=$delivery['item']??$delivery;return ps_scope($extra+['item_id'=>(int)$item['id'],'revision'=>(int)$item['revision'],'delivery_id'=>$item['delivery_id'],'chat_message_id'=>(int)$item['chat_message_id'],'request_id'=>bin2hex(random_bytes(16)),'mode'=>'manual']);
}
function ps_claim(array $item,array $extra=[]): array
{
    $delivery=ps_delivery($item);$input=ps_input($delivery,$extra);$out=vp3_profile_speech_claim_pa4a(db(),ps_owner(),$input);$input['revision']=$out['item']['revision'];return $out+['input'=>$input+['attempt_id'=>$out['attempt_id'],'ticket'=>$out['ticket']]];
}
function ps_ready(array $claim): array
{
    $request=vp3_profile_speech_attempt_begin_pa4a(db(),ps_owner(),$claim['input']);if($request['provider_required'])vp3_profile_speech_attempt_finish_pa4a(db(),$request,vp3_profile_speech_synthesize_pa4a($request));return $request;
}

function ps_tick(int $ms,bool $refreshPresence=true): void
{
    $GLOBALS['pa3Now']+=$ms;
    if($refreshPresence){$q=db()->query("SELECT presence_sequence FROM profile_agent_handoff_presence_pa3 WHERE owner_user_id=1 AND tab_id='".str_repeat('a',32)."'");$prior=(int)$q->fetchColumn();vp3_profile_handoff_presence_pa3(db(),ps_owner(),['tab_id'=>str_repeat('a',32),'chat_conversation_id'=>11,'chat_agent_id'=>7,'visible'=>true,'presence_sequence'=>$prior+1]);}
}
