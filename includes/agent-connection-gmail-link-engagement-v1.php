<?php
declare(strict_types=1);

require_once __DIR__.'/agent-connection-gmail-link-source-v1.php';
require_once __DIR__.'/agent-curiosity-v1.php';
require_once __DIR__.'/cloud-mission-web-v1.php';

/** The provider response can authorize visible full-message links without a second Gmail read. */
function vp3_gmail_chat_link_token_v1(array $scope,int $agentId,int $conversationId,int $messageId,string $itemRef,string $url,string $label): string
{
    return agent_connection_gmail_inbox_key_v1($scope['policy'],json_encode([
        'chat-link-v1',agent_connection_gmail_inbox_update_scope_ref_v1($scope),
        $agentId,$conversationId,$messageId,$itemRef,$url,$label,
    ],JSON_THROW_ON_ERROR));
}

function vp3_gmail_chat_link_tokens_v1(array $item,array $scope,int $agentId,int $cid,int $mid,string $itemRef): array
{
    foreach($item['links']??[] as $index=>$link){
        $item['links'][$index]['token']=vp3_gmail_chat_link_token_v1($scope,$agentId,$cid,$mid,$itemRef,$link['url'],$link['label']);
    }
    return $item;
}

/** A mailbox panel uses the native item grant; Chat links retain their exact saved post. */
function vp3_gmail_curiosity_link_origin_v1(PDO $pdo,array $user,int $agent,int $cid,int $mid,?string $ref): array
{
    if($agent!==0||$cid!==0||$mid!==0)return vp3_gmail_chat_link_source_v1($pdo,$user,$agent,$cid,$mid,$ref);
    if($ref===null)throw new RuntimeException('Choose an exact mailbox item.',422);
    $scope=agent_connection_gmail_inbox_bound_scope_v1($pdo,$user);
    if(($scope['state']??null)!=='ready'||empty($scope['provider_ready']))throw new RuntimeException('Gmail access changed.',403);
    agent_connection_gmail_inbox_item_v1($pdo,$user,$ref);
    return ['scope'=>$scope,'item_ref'=>$ref,'kind'=>'mailbox','fields'=>[]];
}

/** Private and action links remain browser navigation only. */
function vp3_gmail_curiosity_link_identity_v1(string $url): ?array
{
    if(!agent_connection_gmail_link_url_v1($url))return null;
    $parts=parse_url($url);
    $host=strtolower($parts['host']);
    if(isset($parts['port']))return null;
    try{cloud_mission_web_host_v1($host);}
    catch(InvalidArgumentException $error){return null;}
    $path=$parts['path']??'/';
    $decoded=rawurldecode($path);
    $wiki=preg_match('/\A[a-z]{2,12}(?:-[a-z0-9]{2,12})?\.wikipedia\.org\z/D',$host)
        &&preg_match('~\A/wiki/[^/\\\\:?#%]+\z~uD',$decoded);
    if(preg_match('~(?:^|/)(?:r|c|click|clickthrough|redirect|track(?:ing)?|verify|confirm|reset|auth|oauth|invite|manage|profile|preferences|(?:un)?subscribe|sign[-_]?(?:in|out)|log[-_]?(?:in|out)|account|cart|checkout|purchase|payment)(?:[/.]|$)~i',$decoded)
        ||preg_match('~https?:|[?%@&\\\\]~i',$decoded)
        ||(!$wiki&&preg_match('~/(?:[a-z0-9_+=]{24,}|[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12})(?:/|$)~i',$decoded)))return null;
    $fragment=rawurldecode($parts['fragment']??'');
    if(preg_match('/\b(?:token|secret|session|credentials?|auth|password|code)\s*=/i',$fragment))return null;
    $query=[];
    foreach(explode('&',$parts['query']??'') as $parameter){
        if($parameter==='')continue;
        $key=rawurldecode(explode('=',$parameter,2)[0]);
        if(preg_match('/token|password|secret|credential|session|api.?key|authorization|signature|^code$|^key$|^action$/i',$key))return null;
        if(!preg_match('/\A(?:utm_[a-z_]+|gclid|fbclid|msclkid)\z/iD',$key))$query[]=$parameter;
    }
    sort($query);
    $identity=$host.$path.($query?'?'.implode('&',$query):'');

    if(strtolower($parts['scheme'])!=='https')return ['id'=>$identity];
    if($wiki)return ['id'=>$host.$path,'article'=>'https://'.$host.$path];
    $video=null;
    if($host==='youtu.be'&&preg_match('~\A/([a-zA-Z0-9_-]{11})\z~D',$path,$match))$video=$match[1];
    if(in_array($host,['youtube.com','www.youtube.com','m.youtube.com'],true)&&$path==='/watch'){
        parse_str(implode('&',$query),$params);
        if(is_string($params['v']??null)&&preg_match('/\A[a-zA-Z0-9_-]{11}\z/D',$params['v']))$video=$params['v'];
    }
    return $video===null?['id'=>$identity]:['id'=>'youtube:'.$video,'video'=>$video];
}

/** Read only a canonical encyclopedia article or verified public video metadata. */
function vp3_gmail_curiosity_read_page_v1(array $target,?callable $transport=null): ?array
{
    try{
        $article=$target['article']??null;$video=$target['video']??null;
        if($article!==null&&($transport!==null||cloud_mission_web_ready_v1())){
            $domains=[(string)parse_url($article,PHP_URL_HOST)];
            $request=$transport??static fn(string $url,array $scope):array=>
                cloud_mission_web_request_v1($url,$scope,static function():void{},timeout:4);
            $page=cloud_mission_web_extract_v1($request($article,$domains));
        }elseif($video!==null){
            require_once __DIR__.'/agent-connection-youtube-v1.php';
            if(!agent_connection_youtube_ready_v1())return null;
            $result=agent_connection_youtube_video_details_v1([$video],null);
            $detail=$result['items'][0]??null;
            $context=is_array($detail)&&($detail['id']??null)===$video?agent_connection_youtube_public_context_v1($detail):null;
            if($context===null)return null;
            $page=['title'=>$context['title'],'excerpt'=>trim($context['channel'].'. '.$context['description'])];
        }else return null;
        $title=vp3_curiosity_safe_text_v1($page['title'],150);
        $excerpt=vp3_curiosity_safe_text_v1(mb_substr($page['excerpt'],0,650,'UTF-8'),650);
        if($title===''||$title==='Public page')$title='';
        return $title!==''||$excerpt!==''?['title'=>$title,'excerpt'=>$excerpt]:null;
    }catch(RuntimeException|InvalidArgumentException $error){return null;}
}

function vp3_gmail_curiosity_record_link_v1(PDO $pdo,array $user,int $agentId,int $cid,int $mid,
    ?string $itemRef,string $url,?string $token,string $label='',?callable $transport=null): bool
{
    $target=vp3_gmail_curiosity_link_identity_v1($url);
    // Remember a verified navigation without retaining its URL or fetching private/action targets.
    if($target===null){
        if(!agent_connection_gmail_link_url_v1($url))return false;
        $target=['id'=>'navigation:'.hash('sha256',$url)];
    }
    $owner=vp3_curiosity_private_scope_v1($pdo,$user,$agentId,$cid)['user'];
    if($cid===0&&$token===null)throw new RuntimeException('A signed mailbox link is required.',403);
    $source=$token===null
        ?agent_connection_gmail_link_source_v1($pdo,$owner,$agentId,$cid,$mid,$itemRef,$url)
        :vp3_gmail_curiosity_link_origin_v1($pdo,$owner,$agentId,$cid,$mid,$itemRef);
    $scope=$source['scope'];
    $signed=$token!==null&&$itemRef!==null
        &&hash_equals(vp3_gmail_chat_link_token_v1($scope,$agentId,$cid,$mid,$itemRef,$url,$label),$token);
    if($token!==null&&!$signed)throw new RuntimeException('This link could not be verified in the Gmail post.',404);

    $scopeRef=agent_connection_gmail_inbox_update_scope_ref_v1($scope);
    $targetHash=agent_connection_gmail_inbox_key_v1($scope['policy'],'curiosity-page:'.$target['id']);
    $reference=['kind'=>$source['kind'],'item_ref'=>$source['item_ref']];
    $context=vp3_gmail_curiosity_email_context_v1($source['fields'],$signed?$label:'');
    $subject='gmail-link:'.hash('sha256',$cid.'|'.$targetHash);
    return vp3_initiative_in_scope_v1($owner,$agentId,static function()use(
        $pdo,$owner,$agentId,$cid,$mid,$itemRef,$target,$scopeRef,$targetHash,$subject,$reference,$context,$transport):bool{
        $now=time();
        $observation=['conversation_id'=>$cid,'message_id'=>$mid,'scope_ref'=>$scopeRef,
            'clicked_at'=>$now,'target_hash'=>$targetHash]+$reference;
        $claim=agent_commerce_atomic_pa2($pdo,static function()use($pdo,$owner,$agentId,$scopeRef,$subject,$observation,$context,$now):bool{
            // Lock the same owner/grant used by normal Gmail actions. Never hold locks during page retrieval.
            $live=agent_connection_gmail_inbox_bound_scope_v1($pdo,$owner,true);
            if(($live['state']??'')!=='ready'||!hash_equals($scopeRef,agent_connection_gmail_inbox_update_scope_ref_v1($live))){
                throw new RuntimeException('Gmail access changed.',403);
            }
            $previous=agent_brain_v122_memory($owner,'initiative_sweep',$subject)['metadata']??[];
            if(($previous['clicked_at']??0)>$now-3600)return false;
            [$where,$args]=vp3_agent_memory_scope_sql_v410($agentId);
            $scopeSql="user_id=? AND $where AND memory_type='initiative_sweep' AND subject LIKE 'gmail-link:%'";
            $integer=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'INTEGER':'UNSIGNED';
            $epoch=vp3_initiative_db_wall_epoch_sql_v1($pdo,'last_seen_at');
            $recent=$pdo->prepare("SELECT COUNT(*) FROM agent_memory_items WHERE $scopeSql AND CAST($epoch AS $integer)>=CAST(? AS $integer)");
            $recent->execute([(int)$owner['id'],...$args,$now-600]);
            if((int)$recent->fetchColumn()>=12)return false;
            $old=$pdo->prepare("DELETE FROM agent_memory_items WHERE $scopeSql AND CAST($epoch AS $integer)<CAST(? AS $integer)");
            $old->execute([(int)$owner['id'],...$args,$now-30*86400]);
            return agent_brain_v122_upsert_system_memory($owner,'initiative_sweep',$subject,
                'Gmail link opened.',$observation+$context,0.5)>0;
        });
        if(!$claim)return false;
        $page=vp3_gmail_curiosity_read_page_v1($target,$transport);
        if($page===null)return true;
        return agent_commerce_atomic_pa2($pdo,static function()use($pdo,$owner,$agentId,$cid,$mid,$itemRef,$scopeRef,
            $subject,$observation,$page):bool{
            $live=agent_connection_gmail_inbox_bound_scope_v1($pdo,$owner,true);
            if(($live['state']??'')!=='ready'||!hash_equals($scopeRef,agent_connection_gmail_inbox_update_scope_ref_v1($live)))return false;
            vp3_curiosity_private_scope_v1($pdo,$owner,$agentId,$cid);
            $source=vp3_gmail_curiosity_link_origin_v1($pdo,$owner,$agentId,$cid,$mid,$itemRef);
            if(!hash_equals($scopeRef,agent_connection_gmail_inbox_update_scope_ref_v1($source['scope'])))return false;
            $saved=agent_brain_v122_memory($owner,'initiative_sweep',$subject)['metadata']??[];
            if(($saved['clicked_at']??null)!==$observation['clicked_at'])return false;
            return agent_brain_v122_upsert_system_memory($owner,'initiative_sweep',$subject,
                'Gmail link opened.',$observation+$page,0.5)>0;
        });
    });
}

/** Preserve only the click label and a bounded visible description from the post. */
function vp3_gmail_curiosity_email_context_v1(array $fields,string $label): array
{
    $plain=static fn(string $text,int $limit):string=>vp3_curiosity_safe_text_v1(
        preg_replace('~https?://[^\s]+~iu',' ',$text)??'',$limit);
    $title=$plain($label,150);
    $texts=[];
    foreach($fields as $field){
        $text=$plain($field,280);
        if($text!=='')$texts[]=$text;
    }
    if($title==='')$title=array_shift($texts)??'';
    return ['title'=>$title,'excerpt'=>mb_substr(implode(' ',array_unique($texts)),0,550,'UTF-8')];
}
