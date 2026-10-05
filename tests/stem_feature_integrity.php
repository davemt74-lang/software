<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/stem-mix-storage.php';
function check(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
function rejects(callable $run,string $message): void {try{$run();}catch(Throwable $e){return;}throw new RuntimeException($message);}
class AuditPDO extends PDO {
    public function __construct() {parent::__construct('sqlite::memory:');$this->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$this->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$this->sqliteCreateFunction('NOW',static fn():string=>'2026-10-05 06:00:00');}
    public function prepare(string $query,array $options=[]): PDOStatement|false {return parent::prepare(str_replace(' FOR UPDATE','',$query),$options);}
}
$pdo=new AuditPDO();
$pdo->exec('CREATE TABLE stem_mix_saves (id INTEGER PRIMARY KEY,user_id INTEGER,track_id INTEGER,mix_name TEXT,mix_json TEXT,created_at TEXT,updated_at TEXT)');
$insert=$pdo->prepare('INSERT INTO stem_mix_saves VALUES (1,7,9,?,?,?,?)');$insert->execute(['Mix','{"volume":0.4,"midiV217":{"notes":[60]}}','now','now']);
stem_mix_update_scoped($pdo,7,9,1,static fn(array $mix):array=>$mix+['engineV215'=>['delay'=>10]]);
stem_mix_update_scoped($pdo,7,9,1,static function(array $mix):array{$mix['sessionV216']=['signature'=>'new'];return $mix;});
$mix=json_decode((string)$pdo->query('SELECT mix_json FROM stem_mix_saves WHERE id=1')->fetchColumn(),true);
check(isset($mix['midiV217'],$mix['engineV215'],$mix['sessionV216']) && $mix['volume']===0.4,'independent attachments preserve current stored fields');
$before=(string)$pdo->query('SELECT mix_json FROM stem_mix_saves WHERE id=1')->fetchColumn();
rejects(static fn()=>stem_mix_update_scoped($pdo,8,9,1,static fn(array $x):array=>[]),'foreign user write');
rejects(static fn()=>stem_mix_update_scoped($pdo,7,10,1,static fn(array $x):array=>[]),'foreign project write');
check(!$pdo->inTransaction(),'denied writes roll back');
rejects(static fn()=>stem_mix_update_scoped($pdo,7,9,1,static function(array $x):array{throw new RuntimeException('failed mutation');}),'callback rollback');
rejects(static fn()=>stem_mix_update_scoped($pdo,7,9,1,static fn(array $x):array=>['huge'=>str_repeat('x',16777217)]),'size rejection');
check(!$pdo->inTransaction() && $before===$pdo->query('SELECT mix_json FROM stem_mix_saves WHERE id=1')->fetchColumn(),'failed changes preserve saved session');
$pdo->exec("UPDATE stem_mix_saves SET mix_json='bad' WHERE id=1");
rejects(static fn()=>stem_mix_update_scoped($pdo,7,9,1,static fn(array $x):array=>[]),'damaged JSON is not silently replaced');
check(!$pdo->inTransaction(),'damaged data rolls back');
define('STONEFELLOW_STEM_MEDIA_LIBRARY_ONLY',true);
require dirname(__DIR__).'/stem-media-v34.php';
$etag=stem_media_cache_validator(['id'=>4,'file_path'=>'/a','updated_at'=>'now'],['mtime'=>1,'ctime'=>1,'size'=>100]);
check(stem_media_cache_matches($etag,$etag),'unchanged authorized media is revalidated');
check(!stem_media_cache_matches('"other"',$etag),'changed media requires bytes');
check($etag!==stem_media_cache_validator(['id'=>4,'file_path'=>'/b','updated_at'=>'now'],['mtime'=>1,'ctime'=>1,'size'=>100]),'replacement path invalidates cached media');
// Run the actual production WAV readers/peak code without bootstrapping a site.
$source=file_get_contents(dirname(__DIR__).'/api/stem-waveform-v49.php');
$start=strpos($source,'function waveform_read_wav_layout');$end=strpos($source,'$stemId =');
eval(substr($source,$start,$end-$start));
$pcm='';for($i=0;$i<48000;$i++)$pcm.=pack('v',($i%97)*500).pack('v',65535-(($i%83)*500));
$wav='RIFF'.pack('V',36+strlen($pcm)).'WAVEfmt '.pack('VvvVVvv',16,1,2,48000,192000,4,16).'data'.pack('V',strlen($pcm)).$pcm;
$file=tempnam(sys_get_temp_dir(),'stem-audit-');file_put_contents($file,$wav);
try{
 $peaks=waveform_wav_peaks($file,64);check(count($peaks['mins'])===64 && abs($peaks['duration']-1)<0.00001,'real stereo PCM waveform');
 // Reference sampling uses the same published bucket selection, independent reads.
 for($bucket=0;$bucket<64;$bucket++){
  $from=(int)floor($bucket/64*48000);$to=max($from+1,min(48000,(int)floor(($bucket+1)/64*48000)));$count=$to-$from;$samples=min(192,$count);$step=max(1.0,$count/$samples);$lo=0.0;$hi=0.0;
  for($j=0;$j<$samples;$j++){
   $frame=min($to-1,$from+(int)floor($j*$step));
   for($channel=0;$channel<2;$channel++){$v=waveform_sample_value(substr($pcm,$frame*4+$channel*2,2),1,16);$lo=min($lo,$v);$hi=max($hi,$v);}
  }
  check($peaks['mins'][$bucket]===round($lo,4)&&$peaks['maxs'][$bucket]===round($hi,4),'bounded reads preserve sampled waveform');
 }
 file_put_contents($file,substr($wav,0,-4));
 rejects(static fn()=>waveform_wav_peaks($file,64),'truncated WAV chunks must fail');
}finally{unlink($file);}
// Exercise actual direct-import transaction statements against real SQLite.
$pdo->exec('CREATE TABLE tracks (id INTEGER PRIMARY KEY)');
$pdo->exec('CREATE TABLE track_stems (id INTEGER PRIMARY KEY,track_id INTEGER,project_id INTEGER,is_active INTEGER,rpp_fx_summary TEXT,file_path TEXT,updated_at TEXT)');
$pdo->exec("INSERT INTO tracks VALUES (9);INSERT INTO track_stems VALUES (1,9,2,1,'old','old.wav','old'),(2,9,2,0,'mine','mine.wav','new'),(3,9,2,0,'other upload','other.wav','new')");
$direct=file_get_contents(dirname(__DIR__).'/api/stem-direct-v79.php');
$start=strpos($direct,'function direct_stem_active_revision');$end=strpos($direct,'function direct_stem_save_state',$start);eval(substr($direct,$start,$end-$start));
$a=strpos($direct,"            \$lock=\$pdo->prepare");$b=strpos($direct,'            if (!empty($save[',$a);$guard=substr($direct,$a,$b-$a);
$a=strpos($direct,'                $activate=$pdo->prepare');$b=strpos($direct,"\n            }",$a);$activate=substr($direct,$a,$b-$a);
$run=static function(PDO $pdo,array $save,int $total) use ($guard,$activate):void{$trackId=9;$projectId=2;$pdo->beginTransaction();try{eval($guard);$pdo->exec('DELETE FROM track_stems WHERE track_id=9 AND is_active=1');eval($activate);$pdo->commit();}catch(Throwable $e){$pdo->rollBack();throw $e;}};
$run($pdo,['active_stem_ids'=>[1],'active_stem_revision'=>direct_stem_active_revision([['id'=>1,'file_path'=>'old.wav','updated_at'=>'old']]),'new_stem_ids'=>[2]],1);
check((int)$pdo->query('SELECT is_active FROM track_stems WHERE id=2')->fetchColumn()===1,'own row activated');
check((int)$pdo->query('SELECT is_active FROM track_stems WHERE id=3')->fetchColumn()===0,'other upload remains inactive');
rejects(static fn()=>$run($pdo,['active_stem_ids'=>[1],'active_stem_revision'=>'stale','new_stem_ids'=>[3]],1),'stale source set must fail');
check((int)$pdo->query('SELECT COUNT(*) FROM track_stems WHERE id=2 AND is_active=1')->fetchColumn()===1,'stale import preserves newer song');
// A cleanup prepared from the archive marker cannot delete a subsequently committed take.
$recording=file_get_contents(dirname(__DIR__).'/api/stem-recording-v213.php');
preg_match('~(DELETE FROM track_stems WHERE id=\? AND track_id=\? AND rpp_fx_summary=\?)~',$recording,$m);
check(isset($m[1]),'cleanup conditional production SQL found');
$delete=$pdo->prepare($m[1]);$pdo->exec("UPDATE track_stems SET rpp_fx_summary='committed' WHERE id=2");$delete->execute([2,9,'mine']);
check($delete->rowCount()===0,'committed take survives late cleanup');
echo "STEM_FEATURE_INTEGRITY=PASS\n";
