<?php
declare(strict_types=1);

// Runs the same queued-search worker as private Chat, with only the network responses replaced.
if (PHP_SAPI !== 'cli' || $argc !== 4) exit(1);
[$script,$root,$sid,$binding]=$argv;
session_id($sid);
$_SERVER['HTTP_X_VP3_WORKING_ATTENTION']=$binding;
$_SERVER['REQUEST_METHOD']='POST';
$_SERVER['REQUEST_URI']='/api/chat.php';
$_SERVER['SCRIPT_NAME']='/api/chat.php';
$_SERVER['HTTP_HOST']='127.0.0.1';
require $root.'/includes/bootstrap.php';
require_once $root.'/includes/agent-chat-runtime-v2160.php';

$pdo=db();
$user=current_user();
if (!$pdo || !$user || (int)$user['id'] !== 1) throw new RuntimeException('Isolated owner session unavailable.');
$principal=vp3_agent_chat_principal_v380($user,null);
$cid=8;
$input=['conversation_id'=>$cid,'agent_context'=>['user_agent_id'=>0]];
vp3_phone_chat_authorize_v1($pdo,$user,$principal,$input,$cid);
$authority=static fn(int $offerId):bool => vp3_phone_local_authorized_offer_v1($pdo,$user,$principal,$cid,$offerId);
$queries=[];
$pages=[];
$knownUrl='https://west-sound.example.org/phoenix';
$unknownUrl='https://desert-strings.example.org/phoenix';
$search=static function(string $query,int $limit,int $seconds) use (&$queries,$knownUrl,$unknownUrl): array {
    $queries[]=$query;
    return ['ok'=>true,'results'=>[
        ['url'=>$knownUrl,'title'=>'West Sound Music in Phoenix','snippet'=>'Phoenix branch'],
        // The discovery title deliberately makes a stock claim that the actual page cannot support.
        ['url'=>$unknownUrl,'title'=>'Desert Strings | XLR cables always in stock','snippet'=>'Open 24 hours, plenty of cables'],
    ]];
};
$request=static function(string $url,array $domains,callable $guard,?callable $resolver=null,int $seconds=8) use (&$pages,$knownUrl,$unknownUrl): array {
    if (!in_array($url,[$knownUrl,$unknownUrl],true)) throw new RuntimeException('Unexpected source URL.');
    $pages[]=$url;
    $first=$url===$knownUrl;
    $label=$first?'West Sound Music':'Desert Strings';
    $address=['@type'=>'PostalAddress','streetAddress'=>$first?'14 East Test Lane':'99 West Test Lane',
        'addressLocality'=>'Phoenix','addressRegion'=>'Arizona','addressCountry'=>'US'];
    $merchant=['@context'=>'https://schema.org','@type'=>'MusicStore','@id'=>$url.'#branch','url'=>$url,
        'name'=>$label,'address'=>$address];
    if ($first) {
        $merchant['openingHoursSpecification']=[
            ['@type'=>'OpeningHoursSpecification','opens'=>'10:00','closes'=>'18:00',
                'dayOfWeek'=>['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday']],
        ];
        $merchant['makesOffer']=[['@type'=>'Offer','itemOffered'=>['@type'=>'Product','name'=>'XLR cables'],
            'availability'=>'https://schema.org/InStock','availableDeliveryMethod'=>'https://schema.org/OnSitePickup',
            'availableAtOrFrom'=>['@type'=>'MusicStore','@id'=>$merchant['@id'],'name'=>$label,'address'=>$address]]];
    }
    $html='<html><head><title>'.$label.' | Phoenix</title><script type="application/ld+json">'
        .json_encode($merchant,JSON_THROW_ON_ERROR).'</script></head><body><main><h1>'.$label
        .'</h1><address>'.$address['streetAddress'].', Phoenix, Arizona</address>'
        .($first?'<p>Open daily 10:00–18:00. XLR cables in stock for pickup at this branch.</p>':'<p>Contact the Phoenix branch for hours and inventory.</p>')
        .'</main></body></html>';
    return ['status'=>200,'content_type'=>'text/html; charset=UTF-8','location'=>'','body'=>$html];
};
vp3_phone_local_research_work_v1($pdo,$user,$principal,$cid,$authority,['search'=>$search,'request'=>$request]);
echo json_encode(['queries'=>$queries,'pages'=>$pages],JSON_THROW_ON_ERROR);
