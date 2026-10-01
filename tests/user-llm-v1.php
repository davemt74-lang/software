<?php
declare(strict_types=1);
const STONEFELLOW_ROOT=__DIR__.'/fixtures/cloud-user-llm-runtime';
function setting(string $name,string $fallback=''): string {return $fallback;}
require dirname(__DIR__).'/includes/ai-settings.php';
require dirname(__DIR__).'/includes/user-llm-v1.php';

$pdo=new PDO((string)(getenv('LLM_TEST_DSN')?:'mysql:host=127.0.0.1;port=3306;dbname=llm_test;charset=utf8mb4'),(string)(getenv('LLM_TEST_USER')?:'llm'),(string)(getenv('LLM_TEST_PASS')?:'llm'),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE IF NOT EXISTS users(id INT UNSIGNED NOT NULL PRIMARY KEY)');
$pdo->exec('INSERT INTO users(id) VALUES (1001),(1002)');
vp3_user_llm_v1_schema($pdo);
$a=['id'=>1001];$b=['id'=>1002];
$initial=vp3_user_llm_v1_read($pdo,1001);
assert($initial['route']==='system'&&$initial['providers']===[]);
$key1='sk-user-one-test-only';
$key2='sk-user-two-test-only';
$save=vp3_user_llm_v1_save($pdo,$a,'openai',$key1,'gpt-5.4-mini');
assert($save['providers']['openai']['configured']===true);
assert($save['providers']['openai']['suffix']==='only');
assert(strpos(json_encode($save),$key1)===false);
$encr=$pdo->query('SELECT encrypted_key FROM user_llm_credentials WHERE user_id=1001')->fetchColumn();
assert($encr!==$key1&&strpos($encr,$key1)===false);
assert(ai_decrypt_secret($encr)===$key1);
vp3_user_llm_v1_save($pdo,$b,'openai',$key2,'gpt-5.4-mini');
assert(vp3_user_llm_v1_effective($pdo,$a)['route']==='system','Default stays system-funded');
try{vp3_user_llm_v1_select($pdo,$a,'own_key','anthropic');throw new RuntimeException('Missing-key selection unexpectedly accepted');}catch(RuntimeException $e){
    assert(str_contains($e->getMessage(),'Save your own provider key'));
}
vp3_user_llm_v1_select($pdo,$a,'own_key','openai');
$effective=vp3_user_llm_v1_effective($pdo,$a);
assert($effective['route']==='own_key'&&$effective['provider']==='openai'&&$effective['api_key']===$key1);
assert(vp3_user_llm_v1_effective($pdo,$b)['route']==='system');
$pdo->exec("UPDATE user_llm_credentials SET encrypted_key='gcm1:broken' WHERE user_id=1001 AND provider='openai'");
assert(vp3_user_llm_v1_effective($pdo,$a)['route']==='unavailable','Missing or corrupt own key must fail closed');
vp3_user_llm_v1_save($pdo,$a,'openai',$key1,'gpt-5.4-mini');
$removed=vp3_user_llm_v1_remove($pdo,$a,'openai');
assert($removed['route']==='system');
assert(vp3_user_llm_v1_effective($pdo,$b)['route']==='system');
vp3_user_llm_v1_select($pdo,$b,'own_key','openai');
assert(vp3_user_llm_v1_effective($pdo,$b)['api_key']===$key2);
$pdo->exec('DROP TABLE user_llm_preferences');
$pdo->exec('DROP TABLE user_llm_credentials');
$pdo->exec('DROP TABLE users');
echo "Cloud user BYOK encryption, account isolation, fail-closed routing, remove PASS\n";
