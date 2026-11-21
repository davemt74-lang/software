<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/agent-marketing-followthrough-v1.php';
function schema_metadata_table_exists(PDO $pdo,string $table):bool {$q=$pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=? LIMIT 1");$q->execute([$table]);return(bool)$q->fetchColumn();}
function campaigns_rewards_owner_plugin_enabled_v100(PDO $pdo,int $owner):bool {return $owner>0&&($GLOBALS['permission']??true);}
function campaigns_rewards_platform_can_v100(PDO $pdo,int $merchant,int $owner,string $cap):bool {return ($GLOBALS['permission']??true)&&$owner>0&&$merchant>0&&in_array($cap,['campaigns.view','analytics.view'],true);}
function vp3_marketing_campaign_time_v1(mixed $raw):?DateTimeImmutable {if(!is_string($raw))return null;$date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$raw,new DateTimeZone('UTC'));return $date!==false&&$date->format('Y-m-d H:i:s')===$raw?$date:null;}
function vp3_marketing_campaign_json_v1(PDO $pdo,string $column,string $path,bool $text=false):string {return "JSON_EXTRACT(CASE WHEN JSON_VALID($column) THEN $column ELSE '{}' END,'$path')";}
function ft_check(bool $ok,string $message):void {if(!$ok)throw new RuntimeException('FAIL '.$message);$GLOBALS['checks']++;echo 'PASS '.$message.PHP_EOL;}
$checks=0;$permission=true;
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE campaign_agent_recommendations (id INTEGER PRIMARY KEY,merchant_id INTEGER,campaign_id INTEGER,created_for_user_id INTEGER,contact_id INTEGER,status TEXT,resolved_at TEXT,recommendation_type TEXT,evidence_refs_json TEXT)');
$pdo->exec('CREATE TABLE campaign_optimization_snapshots (id INTEGER PRIMARY KEY,merchant_id INTEGER,campaign_id INTEGER,status TEXT,window_days INTEGER,window_start TEXT,window_end TEXT,sample_count INTEGER,metrics_json TEXT,evidence_hash TEXT,created_at TEXT)');
$pdo->exec('CREATE TABLE campaign_activity_events (id INTEGER PRIMARY KEY,merchant_id INTEGER,campaign_id INTEGER,event_type TEXT,environment TEXT,actor_type TEXT,actor_user_id INTEGER,occurred_at TEXT,details_json TEXT)');
$metrics=static fn(int $entries,int $converted,int $claims):string=>json_encode(['campaign'=>['treatment_entries'=>$entries,'outcomes'=>['converted'=>$converted,'reward_claimed'=>$claims]],'PRIVATE_CUSTOMER'=>'do not project'],JSON_THROW_ON_ERROR);
$insert=$pdo->prepare('INSERT INTO campaign_optimization_snapshots VALUES (?,?,?,?,?,?,?,?,?,?,?)');
$beforeHash=str_repeat('a',64);$afterHash=str_repeat('b',64);
$insert->execute([10,17,77,'observed',7,'2026-10-01 00:00:00','2026-10-08 00:00:00',55,$metrics(45,2,1),$beforeHash,'2026-10-08 01:00:00']);
$refs=json_encode(['source'=>'v126_optimization_snapshot','snapshot_id'=>10,'evidence_hash'=>$beforeHash,'evidence'=>['treatment_entries'=>45]],JSON_THROW_ON_ERROR);
$pdo->prepare('INSERT INTO campaign_agent_recommendations VALUES (?,?,?,?,?,?,?,?,?)')->execute([501,17,77,11,null,'accepted','2026-10-09 12:00:00','optimization.low-observed-conversion',$refs]);
$event=$pdo->prepare('INSERT INTO campaign_activity_events VALUES (?,?,?,?,?,?,?,?,?)');
$event->execute([1,17,77,'campaign.optimization_snapshot_recorded','production','system',null,'2026-10-08 01:01:00',json_encode(['snapshot_id'=>10,'sample_count'=>55,'evidence_hash'=>$beforeHash])]);
$event->execute([2,17,77,'campaign.optimization_recommendation_reviewed','production','user',11,'2026-10-09 12:00:01',json_encode(['recommendation_id'=>501,'decision'=>'accepted'])]);
$now=new DateTimeImmutable('2026-11-20T22:00:00+00:00');
$decision=[['id'=>501,'status'=>'accepted','type'=>'low-observed-conversion']];
$read=static fn(int $owner=11,int $merchant=17):array=>vp3_marketing_campaign_followthrough_v1($pdo,$owner,$merchant,77,$now,$decision);
$baseline=$pdo->query('SELECT s.* FROM campaign_optimization_snapshots s WHERE s.id=10')->fetch(PDO::FETCH_ASSOC);
ft_check(vp3_marketing_followthrough_snapshot_v1($baseline)!==null,'baseline snapshot metrics and window are valid');
ft_check(vp3_marketing_followthrough_event_v1($pdo,17,77,'campaign.optimization_snapshot_recorded',null,
    '2026-10-08 01:00:00','2026-10-09 12:00:00',10,$beforeHash,55)!==null,
    'native baseline snapshot activity proof is valid');
ft_check(schema_metadata_table_exists($pdo,'campaign_activity_events'),'fixture event ledger is present');
ft_check($read()[0]['state']==='awaiting_change','review without a recorded Campaign edit is awaiting_change');
ft_check(!str_contains(json_encode($read(),JSON_THROW_ON_ERROR),'PRIVATE_CUSTOMER'),'private snapshot metrics are stripped');
$event->execute([3,17,77,'campaign.updated','production','user',12,'2026-10-10 08:00:00','{}']);
$event->execute([30,17,77,'campaign.updated','production','user',11,'2026-10-10 09:00:00',
    json_encode(['summary'=>'Campaign status changed to scheduled'])]);
ft_check($read()[0]['state']==='awaiting_change',
    'owner lifecycle status change is not a verified Campaign creative/content edit');
ft_check($read()[0]['state']==='awaiting_change','foreign user change does not establish owner evidence');
$event->execute([4,17,77,'campaign.updated','production','user',11,'2026-10-10 12:00:00',json_encode(['summary'=>'Campaign updated'])]);
ft_check($read()[0]['state']==='awaiting_window','a reviewed Campaign edit alone does not invent results');
$insert->execute([11,17,77,'observed',7,'2026-10-11 12:00:00','2026-10-18 12:00:00',80,$metrics(60,5,3),$afterHash,'2026-10-18 12:01:00']);
ft_check($read()[0]['state']==='unavailable','an unverified post window is not accepted');
$event->execute([5,17,77,'campaign.optimization_snapshot_recorded','production','system',null,'2026-10-18 12:02:00',json_encode(['snapshot_id'=>11,'sample_count'=>80,'evidence_hash'=>$afterHash])]);
$result=$read()[0];
ft_check($result['state']==='observed'&&$result['before']['metrics']['converted']===2&&$result['after']['metrics']['converted']===5&&$result['scope']==='observational_not_causal','independent audited windows are observed without causal claims');
ft_check($result['learning']['state']==='descriptive_only'
    &&$result['learning']['prior_rate_bps']===444&&$result['learning']['later_rate_bps']===833,
    'audited Campaign follow-through carries only a bounded descriptive learning result');
ft_check($read(12)===[]&&$read(11,99)===[],'foreign owner and merchant cannot access verified recommendation');
$permission=false;ft_check($read()===[],'revoked analytics permissions fail closed');$permission=true;
$pdo->exec('DELETE FROM campaign_activity_events WHERE id=2');ft_check($read()===[],'owner review event is required');
$raw=['window_days'=>7,'window_start'=>'2026-10-11 12:00:00','window_end'=>'2026-10-18 12:00:00','sample_count'=>20,'metrics_json'=>$metrics(20,0,0)];
$parsed=vp3_marketing_followthrough_snapshot_v1($raw);
ft_check($parsed!==null&&array_keys($parsed['metrics'])===['treatment_entries','converted','reward_claimed'],'snapshot projection only exposes bounded aggregates');
$bad=$raw;$bad['window_end']=$bad['window_start'];ft_check(vp3_marketing_followthrough_snapshot_v1($bad)===null,'invalid observation windows are rejected');
$bad=$raw;$bad['metrics_json']='{"campaign":{"treatment_entries":null,"outcomes":{"converted":0,"reward_claimed":0}}}';ft_check(vp3_marketing_followthrough_snapshot_v1($bad)===null,'missing counts cannot become zero');
$low=$parsed;$low['metrics']['treatment_entries']=2;
$low['window_start']='2026-10-19T12:00:00+00:00';$low['window_end']='2026-10-26T12:00:00+00:00';
ft_check(vp3_marketing_followthrough_compare_v1($parsed,$low,'2026-10-19 00:00:00')==='insufficient_sample','low samples are flagged, not shown as evidence of improvement');
$overlap=$parsed;$overlap['window_start']='2026-10-09T00:00:00+00:00';
ft_check(vp3_marketing_followthrough_compare_v1($parsed,$overlap,'2026-10-10 12:00:00')==='unavailable','overlapping or pre-change periods cannot be compared');
echo 'MARKETING_APPROVED_FOLLOWTHROUGH=PASS ('.$checks.' assertions)'.PHP_EOL;
