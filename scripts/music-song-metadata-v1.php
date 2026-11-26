#!/usr/bin/env php
<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'){
    http_response_code(404);
    exit(1);
}

$mode='report';$modeChosen=false;$configPath=dirname(__DIR__).'/config.php';
foreach(array_slice($argv,1) as $argument){
    if($argument==='--help'||$argument==='-h'){
        fwrite(STDOUT,<<<'HELP'
Usage: php scripts/music-song-metadata-v1.php [--report | --migrate] [--config=/absolute/config.php]

--report   Read-only schema, identity and mapping review (the default).
--migrate  Install ML1 metadata tables/indexes and import unambiguous catalog rows.
--config   Use an existing application configuration file; defaults to config.php.

Output is JSON containing counts and catalog/Player IDs requiring review.
Ambiguous mappings are reported; no uploader-based ownership is inferred.
Exit codes: 0 completed, 1 unavailable/failed, 2 completed with mappings to review.

HELP);
        exit(0);
    }
    if($argument==='--report'||$argument==='--migrate'){
        if($modeChosen){fwrite(STDERR,"Choose one mode: --report or --migrate.\n");exit(1);}
        $mode=substr($argument,2);$modeChosen=true;
    }elseif(str_starts_with($argument,'--config=')){
        $configPath=substr($argument,9);
    }else{
        fwrite(STDERR,"Unknown option. Use --help for supported options.\n");exit(1);
    }
}

if($configPath===''||!is_file($configPath)||!is_readable($configPath)){
    fwrite(STDERR,"Application configuration is unavailable. Use --config=/absolute/config.php.\n");exit(1);
}

define('STONEFELLOW_ROOT',dirname(__DIR__));
// Avoid the web bootstrap: report mode must not start sessions or run request hooks.
require_once STONEFELLOW_ROOT.'/includes/music-song-metadata-v1.php';
require_once STONEFELLOW_ROOT.'/includes/artist-music-v185.php';

$stage='reading application configuration';
try{
    $config=require $configPath;
    if(!is_array($config)||!is_array($config['db']??null))throw new RuntimeException('Missing database configuration.');
    $dbConfig=$config['db'];$dsn=trim((string)($dbConfig['dsn']??''));
    if($dsn==='')throw new RuntimeException('Missing database configuration.');
    if($mode==='report'&&str_starts_with($dsn,'sqlite:')){
        // Opening a missing SQLite path would create a file even without SQL writes.
        $databasePath=substr($dsn,7);
        if(!is_file($databasePath)){
            fwrite(STDERR,"Read-only report requires an existing SQLite database file.\n");exit(1);
        }
    }
    $stage='connecting to the configured database';
    $pdo=new PDO($dsn,(string)($dbConfig['user']??''),(string)($dbConfig['pass']??''),[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
    $driver=(string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if(!in_array($driver,['mysql','sqlite'],true))throw new RuntimeException('Unsupported database driver.');
    if($mode==='report'){
        $stage='reviewing metadata and legacy mappings';
        if($driver==='sqlite')$pdo->exec('PRAGMA query_only=ON');
        else $pdo->exec('SET SESSION TRANSACTION READ ONLY');
        $pdo->beginTransaction();
        $report=music_song_metadata_v1_report($pdo);
        $pdo->commit();
    }else{
        $stage='installing metadata and importing catalog mappings';
        $report=music_song_metadata_v1_install($pdo);
    }
    fwrite(STDOUT,json_encode(['mode'=>$mode]+$report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL);
    if($mode==='report'&&empty($report['catalog_ready']))exit(1);
    exit((int)($report['staged']??0)>0?2:0);
}catch(Throwable $e){
    if(isset($pdo)&&$pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();
    fwrite(STDERR,'Music metadata command failed while '.$stage.'. Check database access and catalog prerequisites, then rerun --report.'.PHP_EOL);
    // Class/code are useful diagnostics without exposing a DSN, SQL data or secrets.
    error_log('Music metadata CLI failure: '.get_class($e).' (code '.preg_replace('/[^A-Za-z0-9_-]/','',(string)$e->getCode()).').');
    exit(1);
}
