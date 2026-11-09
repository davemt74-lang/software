<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/agent-tools-v90.php';
require_once __DIR__.'/../includes/artist-listening-transcript.php';

function db(): PDO {return $GLOBALS['fixtureDb'];}
function ai_active_provider(): string {return 'openai';}
function ai_provider_ready(string $provider): bool {return $provider==='openai';}
function ai_provider_model(string $provider): string {return 'gpt-5.6-terra';}
function ai_provider_api_key(string $provider): string {return 'installation-key';}
function ai_valid_model(string $provider,string $model): bool {return in_array($model,['gpt-5.6-terra','gpt-5.6-sol','claude-haiku-5-5'],true);}
function ai_decrypt_secret(string $value): string {return $value;}
function ai_v100_rate_limit(string $scope,array $user): void {}
function ai_v100_telemetry(array $event): void {}
function ai_v100_usage(string $provider,array $body): array {return $body['usage']??[];}
function ai_v100_safe_exception(Throwable $error,string $fallback='Unavailable'): string {return $error->getMessage();}
function subscription_has_entitlement(array $user,string $entitlement): bool {return true;}
function subscription_estimate_tokens_from_chars(int $chars): int {return (int)ceil($chars/3.5);}
function subscription_ai_preflight(array $user,string $scope,int $input,int $output,?PDO $pdo=null): array
{
    if(!empty($GLOBALS['blockQuota']))throw new RuntimeException('Token balance exhausted.');
    check($pdo?->inTransaction()===true,'Quota reserve must share the owner funding transaction.');
    $GLOBALS['reservations'][]=[$user['id'],$scope,$input,$output];
    return ['reservation_id'=>count($GLOBALS['reservations']),'max_output_tokens'=>128];
}
function subscription_ai_commit_usage(int $id,array $user,string $scope,string $provider,string $model,array $usage,string $key): void
{
    $GLOBALS['charges'][]=[$id,$user['id'],$scope,$provider,$model,$usage['total_tokens'],$key];
}
function subscription_ai_release_reservation(int $id): void {$GLOBALS['released'][]=$id;}
function ai_curl_json(string $url,array $headers,array $payload,int $timeout): array
{
    $GLOBALS['calls'][]=[$url,$headers,$payload,$timeout];
    if(!empty($GLOBALS['providerFailure']))return ['ok'=>false,'error'=>'Provider rejected.'];
    return ['ok'=>true,'data'=>[
        'output_text'=>'{"answer":"Fixture","commands":[]}',
        'output'=>[['content'=>[['annotations'=>[['type'=>'url_citation','url'=>'https://example.test/result','title'=>'Example']]]]]],
        'content'=>[['type'=>'text','text'=>'{"answer":"Fixture","commands":[]}',
            'citations'=>[['url'=>'https://example.test/result','title'=>'Example']]]],
    ]+(empty($GLOBALS['omitUsage'])?['usage'=>['input_tokens'=>8,'output_tokens'=>2,'total_tokens'=>10]]:[])];
}
function check(bool $condition,string $message): void
{
    if(!$condition)throw new RuntimeException($message);
}

$pdo=$GLOBALS['fixtureDb']=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY);
    INSERT INTO users VALUES (1),(2);
    CREATE TABLE user_llm_preferences(user_id INTEGER PRIMARY KEY,route TEXT,provider TEXT);
    CREATE TABLE user_llm_credentials(user_id INTEGER,provider TEXT,encrypted_key TEXT,model TEXT);
    INSERT INTO user_llm_preferences VALUES (2,'own_key','anthropic');
    INSERT INTO user_llm_credentials VALUES (2,'anthropic','owner-key','claude-haiku-5-5');");
$GLOBALS['calls']=$GLOBALS['reservations']=$GLOBALS['charges']=$GLOBALS['released']=[];

$default=['id'=>1];$personal=['id'=>2];
$plan=agent_v90_llm_json('Edit the clip','Plan edits','deep',$default,'video_editor');
check($plan['ok']&&$plan['provider']==='openai'&&$plan['model']==='gpt-5.6-sol','Default editor plan must preserve the installation model behavior.');
[$url,$headers,$payload]=$GLOBALS['calls'][0];
check(str_contains($url,'api.openai.com')&&$headers[0]==='Authorization: Bearer installation-key'
    &&$payload['max_output_tokens']===128,'Default editor must send system key only after quota caps output.');
check(array_slice($GLOBALS['charges'][0],0,6)===[1,1,'video_editor','openai','gpt-5.6-sol',10],'Charge must use reported provider usage and true scope.');

$plan=agent_v90_llm_json('Review the track','Plan edits','deep',$personal,'stem_editor');
check($plan['ok']&&$plan['provider']==='anthropic'&&$plan['model']==='claude-haiku-5-5','Personal editor must use its selected model.');
[$url,$headers,$payload]=$GLOBALS['calls'][1];
check(str_contains($url,'api.anthropic.com')&&$headers[0]==='x-api-key: owner-key'&&$payload['max_tokens']===3600
    &&count($GLOBALS['reservations'])===1&&count($GLOBALS['charges'])===1,'Personal editor must avoid installation keys and quota.');

$analysis=artist_listening_v237_ai('Summarize this',$personal,1800);
check($analysis['provider']==='anthropic'&&$GLOBALS['calls'][2][2]['max_tokens']===1800,'Transcript analysis must use the same personal source.');
$research=artist_listening_v237_research(['Verify this'],$personal);
check($research['sources'][0]['url']==='https://example.test/result'
    &&$GLOBALS['calls'][3][2]['tools'][0]['name']==='web_search','Personal transcript research must preserve tools and citations.');
$research=artist_listening_v237_research(['Public query'],$personal,publicProfile:true);
check($research['sources'][0]['url']==='https://example.test/result'
    &&$GLOBALS['calls'][4][1][0]==='Authorization: Bearer installation-key'
    &&count($GLOBALS['reservations'])===1,'Public Profile research must keep its independently authorized installation route.');

$GLOBALS['omitUsage']=true;
artist_listening_v237_ai('Another summary',$default,1800);
check(count($GLOBALS['charges'])===1&&$GLOBALS['released']===[2],'Unreported usage must release the package reserve without inventing a charge.');
$GLOBALS['blockQuota']=true;
$plan=agent_v90_llm_json('Edit the clip','Plan edits','deep',$default,'video_editor');
check(!$plan['ok']&&count($GLOBALS['calls'])===6,'Exhausted system quota must stop the transport.');
$GLOBALS['blockQuota']=false;
$GLOBALS['providerFailure']=true;
$plan=agent_v90_llm_json('Review the track','Plan edits','deep',$personal,'stem_editor');
check(!$plan['ok']&&count($GLOBALS['calls'])===7&&$GLOBALS['calls'][6][1][0]==='x-api-key: owner-key',
    'A rejected personal call must never fall back to installation credentials.');

$route=vp3_ai_direct_account_route_v1($personal);
$pdo->exec("UPDATE user_llm_preferences SET route='system' WHERE user_id=2");
try{
    vp3_ai_direct_request_v1($personal,$route,'transcription',['max_tokens'=>300],30);
    throw new LogicException('Stale source accepted.');
}catch(RuntimeException $error){check($error->getCode()===403,'A source switch must invalidate the pending private request.');}
$pdo->exec("UPDATE user_llm_preferences SET route='own_key' WHERE user_id=2; DELETE FROM user_llm_credentials WHERE user_id=2");
$plan=agent_v90_llm_json('Review the track','Plan edits','deep',$personal,'stem_editor');
check(!$plan['ok']&&count($GLOBALS['calls'])===7,'Missing personal credentials must never fall back to the installation.');
echo "Direct editor and transcript account funding and package usage passed.\n";
