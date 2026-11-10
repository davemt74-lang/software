<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/connected-sites-chat-v1.php';

function connected_site_chat_expect(bool $condition, string $message): void
{
    if(!$condition)throw new RuntimeException($message);
}

function knowledge_retrieval_v162_owner_session(array $user, array $principal): bool
{
    return ($principal['kind']??null)==='user_agent'
        && $principal['owner_user_id']===$user['id']&&$principal['viewer_user_id']===$user['id'];
}

function has_permission(string $permission, array $user): bool
{
    return in_array($permission,$user['permissions'],true);
}

function vp3_participant_scope_v1(): ?array
{
    return $GLOBALS['fixtureParticipantScope']??null;
}

function vp3_gelato_connections_v1(PDO $pdo, int $userId): array
{
    return $GLOBALS['fixtureConnections'][$userId]??[];
}

function vp3_gelato_operation_v1(PDO $pdo, int $userId, string $connectionId, string $action, int $locationId, ?string $businessDate=null): array
{
    $GLOBALS['fixtureRequests'][]=[$userId,$connectionId,$action,$locationId,$businessDate];
    if($action===($GLOBALS['fixtureFail']??null))throw new RuntimeException('PRIVATE_PROVIDER_DEBUG',$GLOBALS['fixtureErrorCode']??0);
    return [
        'asOf'=>'2026-11-03T23:24:00Z',
        'source'=>['path'=>'/pos.php'],
        'data'=>$GLOBALS['fixtureOperations'][$action]??[],
    ];
}

function vp3_gelato_search_v1(PDO $pdo, int $userId, string $connectionId, string $query, string $type='all', int $limit=10): array
{
    $GLOBALS['fixtureRequests'][]=[$userId,$connectionId,$query,$type,$limit];
    return ['results'=>[
        ['type'=>'menu','title'=>'Pepperoni Pizza','content'=>'UNEXPECTED_UNTRUSTED_BODY'],
        ['type'=>'recipe','title'=>'Recipe outside allowed scope'],
    ]];
}

final class ConnectedSiteChatFixtureStatement extends PDOStatement
{
    private bool $loaded=false;
    public function execute(?array $params=null): bool
    {
        $this->loaded=$params===[$GLOBALS['fixtureConversationId'],32];
        return true;
    }
    public function fetch(int $mode=PDO::FETCH_DEFAULT, int $cursorOrientation=PDO::FETCH_ORI_NEXT, int $cursorOffset=0): mixed
    {
        return $this->loaded?($GLOBALS['fixturePrevious']??false):false;
    }
}
final class ConnectedSiteChatFixturePdo extends PDO
{
    public function __construct() {}
    public function prepare(string $query, array $options=[]): PDOStatement|false
    {
        connected_site_chat_expect(str_contains($query,'conversation_id=? AND id<?'),'Continuation must bind the same conversation and current turn.');
        connected_site_chat_expect(str_contains($query,'CURRENT_TIMESTAMP AS current_at'),'Continuation freshness must use the database clock.');
        return new ConnectedSiteChatFixtureStatement();
    }
}

$location=['id'=>21,'name'=>'East Kitchen','timezone'=>'America/Phoenix'];
$west=['id'=>27,'name'=>'West Kitchen','timezone'=>'America/Phoenix'];
$connection=[
    'id'=>'food-21','agentName'=>'Luna’s Kitchen','label'=>'Location group',
    'origin'=>'https://pizza.example.test','scopes'=>['sales.daily.read','orders.read','tickets.open.read','menu'],
    'locations'=>[$location],
];
$question='Ask Luna’s Kitchen how we did today and what’s still open?';
$plan=vp3_connected_sites_chat_plan_v1($question,[$connection]);
connected_site_chat_expect($plan['context']['actions']===['sales.daily.read','orders.read','tickets.open.read'],'The installed name resolves the exact combined question.');
foreach(['Ask Gelato about sales','/ask gelato about sales','Can you ask Gelato about sales?','/ask Luna’s Kitchen about sales'] as $alias){
    $aliasPlan=vp3_connected_sites_chat_plan_v1($alias,[$connection]);
    connected_site_chat_expect($aliasPlan['connection']['id']==='food-21'&&$aliasPlan['context']['actions']===['sales.daily.read'],'Each supported name and command form selects the verified Gelato installation.');
}
connected_site_chat_expect(str_contains(vp3_connected_sites_chat_plan_v1('Ask Gelato.',[$connection])['answer'],'What would you like to ask Gelato?'),'Bare spoken command offers examples.');
connected_site_chat_expect(str_contains(vp3_connected_sites_chat_plan_v1('/ask gelato',[])['answer'],'not paired'),'Unpaired Gelato requests give setup guidance.');
connected_site_chat_expect(vp3_connected_sites_chat_plan_v1('Ask Gelatology about sales',[$connection])===null,'Alias matching preserves a name boundary.');
connected_site_chat_expect(vp3_connected_sites_chat_plan_v1('Ask Luna’s Kitchenette about sales',[$connection])===null,'A longer unconnected name must not match.');
connected_site_chat_expect(isset(vp3_connected_sites_chat_plan_v1('Ask Luna’s Kitchen for sales last week',[$connection])['answer']),'Unsupported sales dates must be explained.');
connected_site_chat_expect(vp3_connected_sites_chat_plan_v1('Ask Luna’s Kitchen for sales on 2026-11-03',[$connection])['context']['date']==='2026-11-03','Exact requested date must be preserved.');
connected_site_chat_expect(isset(vp3_connected_sites_chat_plan_v1('And yesterday?',[$connection],['connectionId'=>'food-21','locationId'=>21,'actions'=>['tickets.open.read']])['answer']),'A ticket follow-up must not switch silently to sales.');

$other=array_replace($connection,['id'=>'food-27','origin'=>'https://other.example.test']);
$ambiguous=vp3_connected_sites_chat_plan_v1($question,[$connection,$other]);
connected_site_chat_expect(str_contains($ambiguous['answer'],'other.example.test'),'Duplicate installed names require a host choice.');
$via=vp3_connected_sites_chat_plan_v1('Ask Luna’s Kitchen via other.example.test what’s open?',[$connection,$other]);
connected_site_chat_expect($via['connection']['id']==='food-27','Exact stored hostname disambiguates a named connector.');
connected_site_chat_expect(isset(vp3_connected_sites_chat_plan_v1('Ask Luna’s Kitchen via other.example.test.evil what’s open?',[$connection,$other])['answer']),'Lookalike hostnames must never select a connection.');
$namedGelato=array_replace($other,['agentName'=>'Gelato']);
$generic=vp3_connected_sites_chat_plan_v1('/ask gelato about sales',[$connection,$namedGelato]);
connected_site_chat_expect(str_contains($generic['answer'],'other.example.test')&&str_contains($generic['answer'],'pizza.example.test'),'The Gelato alias requires a choice across installations even when one installed name is Gelato.');
$viaAlias=vp3_connected_sites_chat_plan_v1('/ask gelato via other.example.test what’s open?',[$connection,$other]);
connected_site_chat_expect($viaAlias['connection']['id']==='food-27','A slash alias can disambiguate by exact hostname.');
$longerName=array_replace($other,['agentName'=>'Gelato HQ']);
$exactName=vp3_connected_sites_chat_plan_v1('Ask Gelato HQ about sales',[$connection,$longerName]);
connected_site_chat_expect($exactName['connection']['id']==='food-27','A longer exact installed name takes priority over the alias.');

$multi=array_replace($connection,['locations'=>[$location,$west]]);
$pending=vp3_connected_sites_chat_plan_v1($question,[$multi]);
connected_site_chat_expect($pending['context']['needsLocation']&&$pending['location']===null,'Several locations require an explicit selection.');
$selected=vp3_connected_sites_chat_plan_v1('West Kitchen',[$multi],$pending['context']);
connected_site_chat_expect($selected['location']['id']===27&&$selected['context']['actions']===$plan['context']['actions'],'A direct location reply completes only the pending plan.');
connected_site_chat_expect(vp3_connected_sites_chat_plan_v1('West Kitchen',[$multi])===null,'A location name without pending chat context does not run a query.');

$pdo=new ConnectedSiteChatFixturePdo();
$user=['id'=>9,'permissions'=>['account.access','chat.access']];
$principal=['kind'=>'user_agent','owner_user_id'=>9,'viewer_user_id'=>9];
$GLOBALS['fixtureConversationId']=80;
$GLOBALS['fixtureConnections']=[9=>[$connection]];
$GLOBALS['fixtureOperations']=[
    'sales.daily.read'=>['businessDate'=>'2026-11-03','available'=>true,'currency'=>'USD','totals'=>['grossSales'=>'300.00','netSales'=>'270.00','refunds'=>'12.00','tickets'=>6]],
    'orders.read'=>['orders'=>[['checkId'=>'check-14','checkNumber'=>'14','status'=>'Ready','pastPromise'=>true]],'truncated'=>false],
    'tickets.open.read'=>['tickets'=>[
        ['checkNumber'=>'16','ageSeconds'=>60,'tableName'=>'Table 16','kitchen'=>['status'=>'not_sent']],
        ['checkId'=>'check-14','checkNumber'=>'14','ageSeconds'=>1200,'tableName'=>'Table 14','kitchen'=>['status'=>'in_progress','late'=>true]],
    ],'truncated'=>false],
];
$GLOBALS['fixtureRequests']=[];
$result=vp3_connected_sites_chat_v1($pdo,$user,$principal,$question,80,32);
connected_site_chat_expect(count($GLOBALS['fixtureRequests'])===3&&$GLOBALS['fixtureRequests'][0]===[9,'food-21','sales.daily.read',21,null],'All three reads are direct and use one owned connection and location.');
connected_site_chat_expect(str_contains($result['answer'],'270.00 USD')&&str_contains($result['answer'],'not sent')&&str_contains($result['answer'],'Active pickup orders')&&str_contains($result['answer'],'also an open POS ticket'),'Live data includes sales and both operational scopes.');
connected_site_chat_expect($result['sources'][0]['url']==='https://pizza.example.test/pos.php','Source links stay at the paired installation.');
[$stored,$storedContext]=vp3_connected_sites_chat_storage_v1($result,$result['answer'],['sources'=>$result['sources']]);
connected_site_chat_expect($stored===VP3_CONNECTED_SITES_CHAT_RECEIPT_V1&&$storedContext['sources']===[]&&!str_contains(json_encode($storedContext),'270.00'),'Persist a content-free receipt and routing marker only.');
connected_site_chat_expect(vp3_connected_sites_chat_storage_v1(['handled'=>true],'normal',['sources'=>['one']])===['normal',['sources'=>['one']]],'Ordinary chat history is unchanged.');
$GLOBALS['fixturePrevious']=['role'=>'assistant','context_json'=>json_encode($storedContext),'created_at'=>'2026-11-03 16:24:00','current_at'=>'2026-11-03 16:29:00'];
$GLOBALS['fixtureRequests']=[];
$followup=vp3_connected_sites_chat_v1($pdo,$user,$principal,'Which ones have been open the longest?',80,32);
connected_site_chat_expect($GLOBALS['fixtureRequests']===[[9,'food-21','tickets.open.read',21,null]],'Immediate follow-up fetches current ticket data only.');
connected_site_chat_expect(strpos($followup['answer'],'#14')<strpos($followup['answer'],'#16'),'Longest-open follow-up orders tickets by current age.');
connected_site_chat_expect(vp3_connected_sites_chat_v1($pdo,$user,$principal,'Which ones have been open the longest?',81,32)===null,'A different conversation cannot use the marker.');
$GLOBALS['fixturePrevious']['current_at']='2026-11-03 16:39:01';
connected_site_chat_expect(vp3_connected_sites_chat_v1($pdo,$user,$principal,'Which ones have been open the longest?',80,32)===null,'An expired continuation marker cannot trigger a read.');
connected_site_chat_expect(vp3_connected_sites_chat_v1($pdo,$user,['kind'=>'profile_agent','owner_user_id'=>9,'viewer_user_id'=>9],$question,80,32)===null,'A public profile agent cannot access connected records.');
connected_site_chat_expect(vp3_connected_sites_chat_v1($pdo,['id'=>9,'permissions'=>['chat.access']],$principal,$question,80,32)===null,'Cloud connection access is rechecked for each chat turn.');
connected_site_chat_expect(vp3_connected_sites_chat_v1($pdo,['id'=>10,'permissions'=>['chat.access','account.access']],['kind'=>'user_agent','owner_user_id'=>10,'viewer_user_id'=>10],$question,80,32)===null,'A different owner cannot invoke this connection by name.');
$GLOBALS['fixtureRequests']=[];
$slash=vp3_connected_sites_chat_v1($pdo,$user,$principal,'/ask gelato how we did today and what’s still open?',80,32);
connected_site_chat_expect(count($GLOBALS['fixtureRequests'])===3&&$slash['answer']===$result['answer'],'Slash command executes the same verified reads and returns the same answer as the installed name.');
$GLOBALS['fixtureRequests']=[];
$bare=vp3_connected_sites_chat_v1($pdo,$user,$principal,'/ask gelato',80,32);
connected_site_chat_expect(str_contains($bare['answer'],'What would you like to ask Gelato?')&&!$GLOBALS['fixtureRequests'],'A bare command explains available reads without contacting Gelato.');
$GLOBALS['fixtureParticipantScope']=['participant_id'=>17];
$restricted=vp3_connected_sites_chat_v1($pdo,$user,$principal,'/ask gelato about sales',80,32);
connected_site_chat_expect(str_contains($restricted['answer'],'private Agent Chat')&&!$GLOBALS['fixtureRequests'],'A participant cannot use owner Gelato permissions.');
unset($GLOBALS['fixtureParticipantScope']);

$GLOBALS['fixtureOperations']['orders.read']=['truncated'=>false];
$GLOBALS['fixtureFail']='sales.daily.read';
$partial=vp3_connected_sites_chat_v1($pdo,$user,$principal,$question,80,32);
connected_site_chat_expect(str_contains($partial['answer'],'Active pickup orders could not be read')&&str_contains($partial['answer'],'Open POS tickets · 2'),'Malformed and unavailable sources are distinct from empty records.');
connected_site_chat_expect(!str_contains($partial['answer'],'PRIVATE_PROVIDER_DEBUG'),'Provider diagnostics are never rendered.');
$GLOBALS['fixtureErrorCode']=403;
$denied=vp3_connected_sites_chat_v1($pdo,$user,$principal,'Ask Gelato for daily sales',80,32);
connected_site_chat_expect(str_contains($denied['answer'],'declined authorization')&&!str_contains($denied['answer'],'PRIVATE_PROVIDER_DEBUG'),'A live authorization denial gives distinct guidance without exposing provider diagnostics.');
$GLOBALS['fixtureFail']='tickets.open.read';
$GLOBALS['fixtureErrorCode']=404;
$revoked=vp3_connected_sites_chat_v1($pdo,$user,$principal,$question,80,32);
connected_site_chat_expect(str_contains($revoked['answer'],'no longer available')&&!str_contains($revoked['answer'],'270.00')&&$revoked['sources']===[],'A connection removed mid-read suppresses earlier data from the combined response.');
unset($GLOBALS['fixtureFail'],$GLOBALS['fixtureErrorCode']);

$menu=vp3_connected_sites_chat_v1($pdo,$user,$principal,'Ask Luna’s Kitchen search menu for pizza.',80,32);
connected_site_chat_expect(str_contains($menu['answer'],'Pepperoni Pizza')&&!str_contains($menu['answer'],'UNEXPECTED_UNTRUSTED_BODY')&&!str_contains($menu['answer'],'outside allowed scope'),'Menu search renders only bounded approved titles.');
echo "Connected Sites Chat contracts passed.\n";
