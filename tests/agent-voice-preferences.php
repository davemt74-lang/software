<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/agent-voice-preferences.php';
function check(bool $ok,string $message): void { if(!$ok)throw new RuntimeException($message); }
function table_exists(string $name): bool { return true; }
class VoicePreferencePDO extends PDO {
    public function __construct(){parent::__construct('sqlite::memory:');$this->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$this->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);}
    public function exec(string $statement): int|false {
        if(str_starts_with($statement,'CREATE TABLE IF NOT EXISTS user_agent_voice_preferences'))$statement="CREATE TABLE IF NOT EXISTS user_agent_voice_preferences (user_id INTEGER PRIMARY KEY,system_voice_id TEXT,system_voice_name TEXT,updated_at TEXT)";
        return parent::exec($statement);
    }
    public function prepare(string $query,array $options=[]): PDOStatement|false {
        $query=str_replace('ON DUPLICATE KEY UPDATE system_voice_id=VALUES(system_voice_id),system_voice_name=VALUES(system_voice_name),updated_at=NOW()',"ON CONFLICT(user_id) DO UPDATE SET system_voice_id=excluded.system_voice_id,system_voice_name=excluded.system_voice_name,updated_at=CURRENT_TIMESTAMP",$query);
        return parent::prepare($query,$options);
    }
}
$pdo=new VoicePreferencePDO();agent_voice_preferences_schema($pdo);
$catalog=[['voice_id'=>'systemVoiceA','name'=>'Voice A'],['voice_id'=>'systemVoiceB','name'=>'Voice B']];
check(agent_voice_preferences_get($pdo,1)['voice_id']==='','New account must inherit configured default');
agent_voice_preferences_save($pdo,1,'systemVoiceA',$catalog);
agent_voice_preferences_save($pdo,2,'systemVoiceB',$catalog);
check(agent_voice_preferences_get($pdo,1)===['voice_id'=>'systemVoiceA','voice_name'=>'Voice A'],'Owner choice did not persist');
check(agent_voice_preferences_get($pdo,2)['voice_id']==='systemVoiceB','Owner voices must remain isolated');
try{agent_voice_preferences_save($pdo,1,'arbitraryClone',$catalog);throw new LogicException('Unlisted voice accepted');}catch(RuntimeException $e){check($e->getMessage()==='Choose an available ElevenLabs system voice.','Unexpected validation error');}
check(agent_voice_preferences_get($pdo,1)['voice_id']==='systemVoiceA','Rejected voice replaced saved choice');
agent_voice_preferences_save($pdo,1,'',[]);
check(agent_voice_preferences_get($pdo,1)['voice_id']===''&&agent_voice_preferences_get($pdo,2)['voice_id']==='systemVoiceB','Reset changed another owner');
// Exercise the actual TTS settings resolver, retaining clone priority.
function ai_elevenlabs_credential(): array {return ['test-key','saved'];}
function setting(string $key,$default){return $default;}
function db(): PDO {return $GLOBALS['pdo'];}
$source=file_get_contents(dirname(__DIR__).'/api/agent-voice-v117.php');
$start=strpos($source,'function stonefellow_voice_v117_models()');
$end=strpos($source,'function stonefellow_voice_v117_json(');
eval(substr($source,$start,$end-$start));
agent_voice_preferences_save($pdo,1,'systemVoiceA',$catalog);
check(stonefellow_voice_v117_settings(['id'=>1])[1]==='systemVoiceA','TTS ignored saved owner voice');
check(stonefellow_voice_v117_settings(['id'=>2])[1]==='systemVoiceB','TTS used another owner voice');
echo "AGENT_VOICE_PREFERENCES=PASS (persistence, isolation, validation, reset, production TTS resolver)\n";
