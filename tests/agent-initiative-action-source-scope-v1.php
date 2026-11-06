<?php
declare(strict_types=1);

// The actual governed broker fixture supplies real policy, permission, Brain,
// domain and workflow ledgers. Brain/Knowledge readers are not replaced.
require __DIR__.'/agent-initiative-actions-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-action-sources-v1.php';
require_once dirname(__DIR__).'/includes/agent-brain-owner-context-v1.php';
$actionFixtureAgent=7;$actionFixtureCid=10;$principal=vp3_initiative_principal_v1($pdo,$user,7);$scopeChecks=0;
function source_scope_check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);$GLOBALS['scopeChecks']++;echo 'PASS '.$label.PHP_EOL;}
function source_scope_reject(callable $work,int $status,string $label):void
{
    try{$work();}catch(Throwable $e){source_scope_check($e->getCode()===$status,$label.' ('.$e->getMessage().')');return;}
    throw new RuntimeException('Expected rejection: '.$label);
}
$pdo->exec("INSERT INTO permissions VALUES('personal_knowledge.access');INSERT INTO role_permissions VALUES('artist','personal_knowledge.access');
CREATE TABLE artist_transcript_folders_v177(id INTEGER PRIMARY KEY,created_by_user_id INTEGER,folder_name TEXT);
CREATE TABLE knowledge_items(id INTEGER PRIMARY KEY,created_by_user_id INTEGER,knowledge_scope TEXT,folder_id INTEGER,title TEXT,updated_at TEXT);
CREATE TABLE knowledge_chunks(id INTEGER PRIMARY KEY,knowledge_id INTEGER,chunk_index INTEGER,chunk_text TEXT);
INSERT INTO knowledge_items VALUES(7101,1,'personal',NULL,'Scopeprobe knowledge','2026-11-06'),(7102,2,'personal',NULL,'Scopeprobe foreign knowledge','2026-11-06');
INSERT INTO knowledge_chunks VALUES(7101,7101,0,'Scopeprobe owner knowledge used for the exact reviewed follow-up.'),(7102,7102,0,'Scopeprobe OTHER OWNER SECRET must stay private.');");
vp3_initiative_chat_v1($pdo,$user,$principal,['conversation_id'=>10,'knowledge_scope'=>['mode'=>'all','folder_id'=>0]],'on',null);
vp3_initiative_in_scope_v1($user,7,static fn()=>agent_brain_v122_upsert_system_memory($GLOBALS['user'],'note','Scopeprobe launch','Scopeprobe named Agent launch context.'));
vp3_initiative_in_scope_v1($user,8,static fn()=>agent_brain_v122_upsert_system_memory($GLOBALS['user'],'note','Scopeprobe FOREIGN AGENT SECRET','Scopeprobe other Agent private context.'));
vp3_initiative_in_scope_v1($user,0,static fn()=>agent_brain_v122_upsert_system_memory($GLOBALS['user'],'note','Scopeprobe SYSTEM SECRET','Scopeprobe System private context.'));
$source=initiative_action_task('Exact source for scoped follow-through');
function source_scope_loaded(string $tool,int $source):array
{
    $u=$GLOBALS['user'];$p=$GLOBALS['principal'];$pdo=db();$binding=vp3_initiative_policy_v1($u,7);
    $scope=['user'=>$u,'principal'=>$p,'agent_id'=>7,'conversation_id'=>10,'verification_only'=>true];$coverage=[];$ref='task:'.$source;
    $evidence=vp3_initiative_capabilities_evidence_v1($pdo,$scope,$binding,new DateTimeImmutable('now',new DateTimeZone('UTC')),[$ref],$coverage);
    $goal=['binding'=>$binding,'sources'=>[$ref=>$evidence[$ref]['revision']],'capability_sources'=>[$ref=>'tasks.read'],
        'tool'=>$tool,'primary'=>$ref,'focus'=>'Scopeprobe','report'=>true];
    // Produce the original read receipt through the real Initiative executor.
    $result=vp3_initiative_tool_execute_v1($pdo,$u,$binding,['tool'=>$tool,'primary'=>$ref,'refs'=>[$ref],'focus'=>'Scopeprobe','report'=>true],
        $evidence,hash('sha256','scope-original-'.$tool),static function(string $prompt):string{
            source_scope_check(!str_contains($prompt,'FOREIGN AGENT SECRET')&&!str_contains($prompt,'SYSTEM SECRET')&&!str_contains($prompt,'OTHER OWNER SECRET'),
                'actual original '.$GLOBALS['sourceScopeTool'].' read excludes other principals');
            return json_encode(['draft'=>'The permitted Scopeprobe evidence supports a reviewed private follow-up.'],JSON_THROW_ON_ERROR);
        });
    source_scope_check(!empty($result['output']['source_revisions']),'actual '.$tool.' original result contains real retrieved record revisions');
    $json=json_encode($goal,JSON_THROW_ON_ERROR);
    return ['user'=>$u,'principal'=>$p,'agent_id'=>7,'conversation_id'=>10,'message'=>['id'=>7200],'run'=>['id'=>7300,'goal'=>$json],
        'goal'=>$goal,'state'=>['goal_hash'=>hash('sha256',$json)],'result'=>['initiative_tool_result'=>$result],'evidence'=>$evidence];
}

foreach(['brain.read','knowledge.search'] as $tool){
    $sourceScopeTool=$tool;$loaded=source_scope_loaded($tool,$source);
    $binding=['owner_user_id'=>1,'agent_id'=>7,'conversation_id'=>10];
    $plan=vp3_initiative_office_action_normalize_v1($pdo,$user,$principal,$binding,
        ['adapter'=>'task.create','arguments'=>['title'=>'Private follow-up for '.$tool]],[]);
    $foreign=['user_id'=>1,'user_agent_id'=>8];$GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']=$foreign;
    $pdo->beginTransaction();
    try{
        $before=vp3_initiative_action_sources_prepare_v1($pdo,$loaded,$plan);
        source_scope_check($GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']===$foreign,$tool.' prepare selects exact Agent and restores foreign caller scope');
        $result=vp3_initiative_office_action_apply_v1($pdo,$user,$principal,$binding,$plan,'scope-transition-'.str_replace('.','-',$tool));
        $proof=vp3_initiative_action_sources_capture_v1($pdo,$loaded,$plan,$result,$before);
        source_scope_check($GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']===$foreign,$tool.' capture verifies actual original records and restores caller scope');
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    vp3_initiative_action_sources_current_v1($pdo,$loaded,$plan,$result,$proof);
    source_scope_check($GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']===$foreign,$tool.' completed replay selects exact Agent and restores caller scope');
    if($tool==='brain.read'){
        $pdo->prepare("UPDATE agent_memory_items SET memory_text='Scopeprobe real named source changed' WHERE user_id=1 AND user_agent_id=7 AND subject='Scopeprobe launch'")->execute();
    }else{
        $pdo->prepare('UPDATE knowledge_chunks SET chunk_text=? WHERE id=7101')->execute(['Scopeprobe actual Knowledge excerpt changed']);
    }
    source_scope_reject(fn()=>vp3_initiative_action_sources_current_v1($pdo,$loaded,$plan,$result,$proof),409,$tool.' later real readonly source edit invalidates saved action proof');
    source_scope_check($GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']===$foreign,$tool.' failed replay also restores prior caller scope');
    unset($GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']);
}
echo 'ACTION_SOURCE_SCOPE=PASS ('.$scopeChecks.' scoped Brain/Knowledge checks)'.PHP_EOL;
