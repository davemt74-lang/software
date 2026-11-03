<?php
declare(strict_types=1);
require_once __DIR__.'/agent-tools-v84.php';
require_once __DIR__.'/cloud-mission-sources-v1.php';

/** Web evidence is attached to canonical Mission actions; it is not another execution queue. */
function cloud_mission_web_schema_v1(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_cloud_mission_web_v1 (
        collection_id CHAR(36) NOT NULL PRIMARY KEY,owner_user_id BIGINT NOT NULL,mission_id CHAR(36) NOT NULL,
        action_key VARCHAR(120) NOT NULL,status VARCHAR(16) NOT NULL,requests_used INT NOT NULL DEFAULT 0,
        max_requests INT NOT NULL,query_text TEXT NOT NULL,scope_json TEXT NOT NULL,sources_json LONGTEXT NOT NULL,
        knowledge_id BIGINT NOT NULL DEFAULT 0,evidence_hash CHAR(64) NOT NULL DEFAULT '',last_error VARCHAR(120) NOT NULL DEFAULT '',
        collected_at VARCHAR(32) NOT NULL DEFAULT '',UNIQUE(owner_user_id,mission_id,action_key))");
}
function cloud_mission_web_ready_v1(): bool {return function_exists('curl_init')&&class_exists('DOMDocument');}
function cloud_mission_web_host_v1(string $host): string
{
    $host=strtolower(rtrim(trim($host),'.'));
    if(strlen($host)>253||!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D',$host)||preg_match('/(?:^|\.)(?:localhost|local|internal|lan|onion|home|localdomain)$/D',$host)||str_ends_with($host,'.home.arpa'))throw new InvalidArgumentException('Use a public website domain.');
    return $host;
}
function cloud_mission_web_url_v1(string $raw,array $domains=[]): string
{
    if(strlen($raw)>1800||preg_match('/[\x00-\x20\x7f\\\\]|%(?:00|0a|0d|5c)/i',$raw))throw new InvalidArgumentException('Public page URL is invalid.');
    $p=parse_url($raw);
    if(!$p||strtolower($p['scheme']??'')!=='https'||isset($p['user'])||isset($p['pass'])||isset($p['port'])&&$p['port']!==443)throw new InvalidArgumentException('Use public HTTPS pages without credentials or alternate ports.');
    $host=cloud_mission_web_host_v1((string)($p['host']??''));
    if($domains&&!array_filter($domains,fn($d)=>$host===$d||str_ends_with($host,'.'.$d)))throw new InvalidArgumentException('This page is outside the reviewed websites.');
    $path=$p['path']??'/';
    if(preg_match('~(?:^|/)(?:login|logout|signin|signout|admin|wp-admin|account|checkout|cart|purchase|payment|delete|unsubscribe)(?:[/.]|$)~i',rawurldecode($path)))throw new InvalidArgumentException('Account and action pages are outside public collection.');
    $queryKeys=array_map(fn($part)=>rawurldecode(explode('=',$part,2)[0]),explode('&',$p['query']??''));
    foreach($queryKeys as $key)if(preg_match('/(?:token|password|secret|credential|session|api.?key|authorization)|^(?:action|delete|remove|unsubscribe|logout)$/i',$key))throw new InvalidArgumentException('Private or action URL parameters are outside public collection.');
    return 'https://'.$host.($path===''?'/':$path).(isset($p['query'])&&$p['query']!==''?'?'.$p['query']:'');
}
function cloud_mission_web_identity_v1(string $url): string
{
    $p=parse_url($url);$pairs=[];
    foreach(explode('&',$p['query']??'') as $pair){if($pair==='')continue;$key=rawurldecode(explode('=',$pair,2)[0]);if(!preg_match('/^utm_|^(?:gclid|fbclid|msclkid)$/i',$key))$pairs[]=$pair;}
    $query=implode('&',$pairs);
    return 'https://'.strtolower($p['host']).($p['path']??'/').($query!==''?'?'.$query:'');
}
function cloud_mission_web_config_v1(array $entry,?PDO $pdo=null,?array $user=null): array
{
    $tools=$entry['tools']??[];$hasSearch=in_array('web.search',$tools,true);$hasRead=in_array('web.read',$tools,true);
    if(!$hasSearch&&!$hasRead)return [];
    $web=$entry['web']??null;
    if(!is_array($web)||!is_string($web['query']??'')||($hasSearch&&mb_strlen(trim($web['query']??''))<5)||mb_strlen($web['query']??'')>400||preg_match('/[\x00-\x1f\x7f]/',$web['query']??''))throw new InvalidArgumentException('Review an external search query of 5 to 400 characters.');
    $domains=$web['domains']??null;$pages=$web['max_pages']??null;
    if(!is_array($domains)||!array_is_list($domains)||count($domains)>5||!is_int($pages)||$pages<1||$pages>2)throw new InvalidArgumentException('Choose up to five website domains and one or two pages.');
    foreach($domains as &$domain){if(!is_string($domain))throw new InvalidArgumentException('Website domains must be text.');$domain=cloud_mission_web_host_v1($domain);}unset($domain);
    $domains=array_values(array_unique($domains));sort($domains,SORT_STRING);
    $urls=$web['urls']??[];if(!is_array($urls)||!array_is_list($urls)||count($urls)>2)throw new InvalidArgumentException('Choose at most two known public page URLs.');
    $refs=$web['sources']??[];if(!is_array($refs)||!array_is_list($refs))throw new InvalidArgumentException('Choose saved source references from the current catalog.');if($refs&&(!$pdo||!$user))throw new InvalidArgumentException('Saved sources need the current owner context.');$bindings=$refs?cloud_mission_source_bindings_v1($pdo,$user,$refs):[];
    $settings=['query'=>trim($web['query']??''),'domains'=>$domains,'max_pages'=>$hasRead?$pages:1,'urls'=>$urls,'sources'=>$bindings];$targets=cloud_mission_web_targets_v1($settings);
    if($hasRead&&!$hasSearch&&!$targets['urls'])throw new InvalidArgumentException('Review known public page URLs, or enable web.search to discover pages.');
    $nonWeb=count(array_diff($tools,['web.search','web.read']));$required=$nonWeb+($hasSearch?1:0)+($hasRead?min($pages,$hasSearch?2:count($targets['urls'])):0);
    if($required>($entry['max_calls']??0))throw new InvalidArgumentException('Increase the read budget to cover search, selected page reads and other tools (maximum three).');
    foreach($urls as &$url){if(!is_string($url))throw new InvalidArgumentException('Public page URLs must be text.');$url=cloud_mission_web_url_v1($url,$targets['domains']);}unset($url);$settings['urls']=array_values(array_unique($urls));return $settings;
}
function cloud_mission_web_targets_v1(array $web): array
{
    $domains=$web['domains'];$urls=$web['urls'];
    foreach($web['sources']??[] as $source){$domains[]=preg_replace('/^www\./','',(string)parse_url($source['url'],PHP_URL_HOST));if($source['kind']==='page')$urls[]=$source['url'];}
    $domains=array_values(array_unique($domains));sort($domains,SORT_STRING);$urls=array_values(array_unique($urls));
    if(count($domains)>5||count($urls)>2)throw new InvalidArgumentException('Limit this assignment to five websites and two known public pages.');
    foreach($urls as $url){if(!is_string($url))throw new InvalidArgumentException('Public page URLs must be text.');cloud_mission_web_url_v1($url,$domains);}
    return ['domains'=>$domains,'urls'=>$urls];
}
function cloud_mission_web_public_ip_v1(string $ip): bool
{
    if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))return false;
    if(str_contains($ip,':'))return (ord((string)inet_pton($ip)[0])&0xe0)===0x20&&!preg_match('/^(?:2001:(?:db8|0):|2002:)/i',$ip);
    $n=unpack('N',inet_pton($ip))[1];
    foreach([['100.64.0.0',10],['192.0.0.0',24],['192.0.2.0',24],['198.18.0.0',15],['198.51.100.0',24],['203.0.113.0',24],['224.0.0.0',4]] as [$network,$bits]){
        $mask=(0xffffffff<<(32-$bits))&0xffffffff;if(($n&$mask)===(unpack('N',inet_pton($network))[1]&$mask))return false;
    }
    return true;
}
/** One cookie-free HTTPS GET, pinned to a checked public DNS address. Redirects are handled explicitly by the caller. */
function cloud_mission_web_request_v1(string $url,array $domains,callable $charge,?callable $resolver=null): array
{
    $url=cloud_mission_web_url_v1($url,$domains);$host=parse_url($url,PHP_URL_HOST);
    $addresses=$resolver?$resolver($host):array_values(array_filter(array_map(fn($r)=>$r['ip']??$r['ipv6']??'',@dns_get_record($host,DNS_A|DNS_AAAA)?:[])));
    if(!$addresses||array_filter($addresses,fn($ip)=>!is_string($ip)||!cloud_mission_web_public_ip_v1($ip)))throw new RuntimeException('public_dns_unavailable');
    $charge();$body='';$headers=[];$tooLarge=false;$ch=curl_init($url);$ip=$addresses[0];
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
        CURLOPT_PROXY=>'',CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_NETRC=>CURL_NETRC_IGNORED,
        CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>8,CURLOPT_RESOLVE=>[$host.':443:'.(str_contains($ip,':')?'['.$ip.']':$ip)],
        CURLOPT_USERAGENT=>'VP3MissionResearch/1.0',CURLOPT_HTTPHEADER=>['Accept: text/html,application/xhtml+xml,text/plain'],CURLOPT_ENCODING=>'',
        CURLOPT_WRITEFUNCTION=>static function($c,string $chunk)use(&$body,&$tooLarge):int{if(strlen($body)+strlen($chunk)>512000){$tooLarge=true;return 0;}$body.=$chunk;return strlen($chunk);},
        CURLOPT_HEADERFUNCTION=>static function($c,string $line)use(&$headers):int{if(str_contains($line,':')){[$key,$value]=explode(':',$line,2);$key=strtolower(trim($key));if(in_array($key,['location','content-type'],true))$headers[$key]=substr(trim($value),0,2000);}return strlen($line);},
    ]);
    $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    if($ok===false||$tooLarge)throw new RuntimeException($tooLarge?'page_too_large':'page_request_failed');
    return ['status'=>$status,'content_type'=>$headers['content-type']??'','location'=>$headers['location']??'','body'=>$body];
}
function cloud_mission_web_redirect_v1(string $from,string $location,array $domains): string
{
    if($location===''||preg_match('/[\x00-\x20\\\\]/',$location))throw new RuntimeException('redirect_unavailable');
    if(str_starts_with($location,'//'))$target='https:'.$location;
    elseif(preg_match('/^[a-z][a-z0-9+.-]*:/i',$location))$target=$location;
    else{
        $p=parse_url($from);$origin='https://'.$p['host'];
        if(str_starts_with($location,'/'))$target=$origin.$location;
        elseif(str_starts_with($location,'?'))$target=$origin.($p['path']??'/').$location;
        else $target=$origin.substr($p['path']??'/',0,strrpos($p['path']??'/','/')+1).$location;
    }
    return cloud_mission_web_url_v1($target,$domains);
}
function cloud_mission_web_extract_v1(array $response): array
{
    $mime=strtolower(trim(explode(';',$response['content_type'])[0]));$body=$response['body'];$title='Public page';
    if($response['status']!==200)throw new RuntimeException('page_http_'.$response['status']);
    if($mime==='text/plain')$text=$body;
    elseif(in_array($mime,['text/html','application/xhtml+xml'],true)){
        $dom=new DOMDocument();$old=libxml_use_internal_errors(true);$dom->loadHTML('<?xml encoding="utf-8" ?>'.$body,LIBXML_NONET);libxml_clear_errors();libxml_use_internal_errors($old);$xp=new DOMXPath($dom);
        foreach($xp->query('//script|//style|//template|//noscript|//svg|//nav|//footer|//header|//form')?:[] as $node)$node->parentNode?->removeChild($node);
        $title=trim((string)$xp->evaluate('string(//title)'))?:$title;
        $node=$xp->query('//main|//article')->item(0)??$xp->query('//body')->item(0);$text=$node?->textContent??'';
        if(preg_match('/captcha|access denied|just a moment|verify you are human/i',$title))throw new RuntimeException('page_access_blocked');
    }else throw new RuntimeException('page_type_unsupported');
    $text=trim(preg_replace('/\s+/u',' ',mb_convert_encoding($text,'UTF-8','UTF-8'))??'');
    if(mb_strlen($text)<2500&&preg_match('/not a robot|verify (?:that )?you (?:are|re) human|robot or human|enable javascript.{0,50}continue|checking your browser/i',$text))throw new RuntimeException('page_access_blocked');
    if(mb_strlen($text)<80)throw new RuntimeException('page_text_unavailable');
    return ['title'=>mb_substr(preg_replace('/\s+/u',' ',$title)??$title,0,200),'excerpt'=>mb_substr($text,0,6000),'truncated'=>mb_strlen($text)>6000];
}
function cloud_mission_web_row_v1(PDO $pdo,array $user,string $id): ?array
{
    $q=$pdo->prepare('SELECT * FROM agent_cloud_mission_web_v1 WHERE collection_id=? AND owner_user_id=?');$q->execute([$id,(int)$user['id']]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
}
function cloud_mission_web_snapshot_v1(PDO $pdo,array $user,array $m,string $id): array
{
    $row=cloud_mission_web_row_v1($pdo,$user,$id);
    if(!$row||$row['status']!=='completed'||$row['mission_id']!==$m['mission_id'])throw new RuntimeException('Confirmed web evidence is unavailable.',403);
    return ['collection_id'=>$id,'query'=>$row['query_text']!==''?$row['query_text']:'Reviewed public page URLs','requests_used'=>(int)$row['requests_used'],'collected_at'=>$row['collected_at'],'evidence_hash'=>$row['evidence_hash'],'sources'=>json_decode($row['sources_json'],true,24,JSON_THROW_ON_ERROR)];
}
function cloud_mission_web_context_v1(array $snapshot): array
{
    $rows=[];
    foreach($snapshot['sources'] as $s)$rows[]=['source'=>$s['source_id'],'title'=>$s['title'],'text'=>'URL: '.$s['url']."\nCollected: ".$snapshot['collected_at']."\nCoverage: ".$s['status'].($s['error']!==''?' · '.$s['error']:'')."\n".($s['status']==='read'?'Fetched page excerpt (may be incomplete): '.$s['excerpt']:(($s['origin']??'')==='reviewed_url'?'Reviewed public URL only. No page text was retrieved.':'Search discovery only. No page text was retrieved.'))];
    if(!$rows)$rows[]=['source'=>'web-search:empty','title'=>'Search returned no eligible sources','text'=>'Collected: '.$snapshot['collected_at'].'. No eligible public sources were returned by this search. This does not establish that no matching information exists.'];
    return $rows;
}
/** Optional PHP callbacks are for server-side testing, never accepted from API input. */
function cloud_mission_web_collect_v1(PDO $pdo,array $user,array $m,array $claim,array $entry,int $budget,?array $transport=null): array
{
    $web=cloud_mission_web_config_v1($entry,$pdo,$user);$targets=cloud_mission_web_targets_v1($web);$web['domains']=$targets['domains'];$web['urls']=$targets['urls'];$id=cloud_mission_uuid_v1('web:'.$user['id'].':'.$m['mission_id'].':'.$claim['action']['action_key']);
    $check=static function()use($pdo,$user,$m,$claim):void{if(!agent_worker_cloud_active_row_v1910($pdo,$user,$claim))throw new RuntimeException('Web collection lease is no longer active.',409);cloud_mission_authority_v1($pdo,$user,cloud_mission_row_v1($pdo,$user,$m['mission_id']),true);};$check();
    $existing=cloud_mission_web_row_v1($pdo,$user,$id);
    if($existing){
        if((int)$existing['requests_used']>$budget)throw new RuntimeException('The cumulative read budget is exhausted.',409);
        if($existing['status']==='completed')return cloud_mission_web_snapshot_v1($pdo,$user,$m,$id);
        throw new RuntimeException('The earlier web collection is incomplete or failed. Prepare a fresh Mission before collecting again.',409);
    }
    $query=$web['query'];if(in_array('web.search',$entry['tools'],true)&&$web['domains'])$query.=' ('.implode(' OR ',array_map(fn($d)=>'site:'.$d,$web['domains'])).')';
    $pdo->prepare("INSERT INTO agent_cloud_mission_web_v1(collection_id,owner_user_id,mission_id,action_key,status,max_requests,query_text,scope_json,sources_json) VALUES(?,?,?,?,'collecting',?,?,?,'[]')")->execute([$id,(int)$user['id'],$m['mission_id'],$claim['action']['action_key'],$budget,$query,json_encode($web,JSON_THROW_ON_ERROR)]);
    $charge=static function()use($pdo,$user,$id,$budget,$check):void{$check();$q=$pdo->prepare("UPDATE agent_cloud_mission_web_v1 SET requests_used=requests_used+1 WHERE collection_id=? AND owner_user_id=? AND status='collecting' AND requests_used<?");$q->execute([$id,(int)$user['id'],$budget]);if($q->rowCount()!==1)throw new RuntimeException('request_budget_exhausted',409);};
    try{
        $hits=array_map(fn($url)=>['url'=>$url,'title'=>'Reviewed public page · '.parse_url($url,PHP_URL_HOST),'origin'=>'reviewed_url'],$web['urls']);
        if(in_array('web.search',$entry['tools'],true)){$charge();$search=($transport['search']??'booking_agent_web_search_response')($query,8);$check();if(empty($search['ok']))throw new RuntimeException('search_provider_unavailable',503);$hits=array_merge($hits,array_slice($search['results']??[],0,8));}
        $sources=[];$seen=[];
        foreach(array_slice($hits,0,10) as $hit){
            try{$url=cloud_mission_web_url_v1((string)($hit['url']??''),$web['domains']);}catch(InvalidArgumentException $e){continue;}
            $identity=cloud_mission_web_identity_v1($url);if(isset($seen[$identity]))continue;$seen[$identity]=true;
            $sources[]=['source_id'=>'web:'.substr(hash('sha256',$identity),0,20),'url'=>$identity,'request_url'=>$url,'title'=>mb_substr(trim(strip_tags((string)($hit['title']??'Public source'))),0,200),'status'=>'discovered','origin'=>$hit['origin']??'search_result','error'=>'','excerpt'=>'','http_status'=>0,'truncated'=>false];
            if(count($sources)>=4)break;
        }
        if(in_array('web.read',$entry['tools'],true)){
            $readByUrl=[];
            foreach($sources as $i=>&$source){
                if($i>=$web['max_pages'])break;$url=$source['request_url'];$hops=[];if(isset($readByUrl[cloud_mission_web_identity_v1($url)])){$source=$readByUrl[cloud_mission_web_identity_v1($url)];continue;}
                try{
                    for($hop=0;$hop<3;$hop++){
                        if(isset($hops[$url]))throw new RuntimeException('redirect_loop');$hops[$url]=true;
                        if(isset($transport['get'])){$charge();$response=$transport['get']($url,$web['domains']);}else $response=cloud_mission_web_request_v1($url,$web['domains'],$charge);
                        $check();$source['http_status']=(int)$response['status'];
                        if(in_array($response['status'],[301,302,303,307,308],true)){$url=cloud_mission_web_redirect_v1($url,$response['location']??'',$web['domains']);continue;}
                        $page=cloud_mission_web_extract_v1($response);$source=array_replace($source,$page,['status'=>'read','url'=>cloud_mission_web_identity_v1($url)]);$readByUrl[$source['url']]=$source;break;
                    }
                    if($source['status']!=='read')throw new RuntimeException('redirect_limit');
                }catch(Throwable $e){$check();$source['status']='unavailable';$source['error']=$e instanceof InvalidArgumentException?'page_outside_public_scope':(preg_match('/^[a-z0-9_]+$/D',$e->getMessage())?$e->getMessage():'page_read_unavailable');}
            }unset($source);
        }
        $unique=[];$rank=['discovered'=>0,'unavailable'=>1,'read'=>2];foreach($sources as $source){unset($source['request_url']);$source['source_id']='web:'.substr(hash('sha256',$source['url']),0,20);$old=$unique[$source['url']]??null;if(!$old||$rank[$source['status']]>$rank[$old['status']])$unique[$source['url']]=$source;}$sources=array_values($unique);
        $semantic=array_map(fn($s)=>[$s['url'],$s['title'],$s['status'],$s['error'],hash('sha256',$s['excerpt'])],$sources);sort($semantic,SORT_REGULAR);$hash=hash('sha256',json_encode([$web['query'],$semantic],JSON_THROW_ON_ERROR));$at=gmdate('Y-m-d\TH:i:s\Z');
        $pdo->beginTransaction();agent_workflow_row_v1400($pdo,(int)$user['id'],(int)$claim['run_id'],true);$check();
        $pdo->prepare("UPDATE agent_cloud_mission_web_v1 SET status='completed',sources_json=?,evidence_hash=?,collected_at=? WHERE collection_id=? AND owner_user_id=? AND status='collecting'")->execute([json_encode($sources,JSON_THROW_ON_ERROR),$hash,$at,$id,(int)$user['id']]);$pdo->commit();
        return cloud_mission_web_snapshot_v1($pdo,$user,$m,$id);
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        if(agent_worker_cloud_active_row_v1910($pdo,$user,$claim))$pdo->prepare("UPDATE agent_cloud_mission_web_v1 SET status='failed',last_error=? WHERE collection_id=? AND owner_user_id=? AND status='collecting'")->execute(['web_collection_unavailable',$id,(int)$user['id']]);
        throw $e;
    }
}
function cloud_mission_web_evidence_v1(PDO $pdo,array $user,array $m,bool $includeText=false): array
{
    if(!table_exists('agent_cloud_mission_web_v1'))return [];$run=cloud_mission_run_v1($pdo,$user,$m);$out=[];
    foreach(agent_workflow_actions_v1400($pdo,(int)$user['id'],(int)$run['id']) as $a){$r=json_decode((string)($a['result_json']??''),true)?:[];if(empty($r['web_collection']))continue;$snapshot=cloud_mission_web_snapshot_v1($pdo,$user,$m,$r['web_collection']);if(!$includeText)foreach($snapshot['sources'] as &$source)unset($source['excerpt']);unset($source);$out[]=$snapshot;}
    return $out;
}
