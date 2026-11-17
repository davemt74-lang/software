<?php
declare(strict_types=1);

require_once __DIR__.'/connected-pos-ticket-v1.php';
require_once __DIR__.'/connected-stoneward-chat-v1.php';

/** Private owner Chat invokes a named site as a deterministic read tool. */
function vp3_connected_sites_chat_text_v1(mixed $value, int $limit=100): string
{
    if(!is_string($value))return '';
    $value=preg_replace('/[<>\x60\[\]\\\\\p{C}]/u',' ',$value)??'';
    return mb_substr(trim(preg_replace('/\s+/u',' ',$value)??''),0,$limit);
}

const VP3_CONNECTED_SITES_CHAT_RECEIPT_V1='A private connected-site result was displayed for this turn.';

function vp3_connected_sites_chat_storage_v1(array $tool, string $answer, array $context): array
{
    if(!isset($tool['connected_site_context_v1']))return [$answer,$context];
    $context['connected_site_context_v1']=$tool['connected_site_context_v1'];
    // A routing context can exist after a failed or unapproved read. Only the
    // connector's verified source results supply the provider attribution.
    foreach($tool['sources']??[] as $source){
        if(is_string($source['source']??null)&&str_starts_with($source['source'],'connected-site:')){
            $context['connected_site_provider_v1']=($tool['connected_site_context_v1']['provider']??null)==='stoneward'
                ?'stoneward':'foodmonster';
            break;
        }
    }
    $context['sources']=[];
    return [VP3_CONNECTED_SITES_CHAT_RECEIPT_V1,$context];
}

function vp3_connected_sites_chat_name_match_v1(string $query, string $name, bool $host=false): ?string
{
    if($name==='')return null;
    $boundary=$host?'(?=[\s,?!:;]|$)':'(?![\p{L}\p{N}_-])';
    $pattern='/^["“]?'.preg_quote($name,'/').'["”]?'.$boundary.'\s*[,.:\-–—]?\s*/iu';
    return preg_match($pattern,$query,$match)?ltrim(substr($query,strlen($match[0]))):null;
}

function vp3_connected_sites_chat_address_v1(string $query): ?string
{
    $query=trim($query);
    return preg_match('/^(?:\/ask|(?:(?:please|(?:can|could|would) you)\s+)?ask)\s+/iu',$query,$prefix)
        ? substr($query,strlen($prefix[0]))
        : vp3_stoneward_chat_play_address_v1($query);
}

function vp3_connected_sites_chat_allowed_v1(array $user, array $principal): bool
{
    return knowledge_retrieval_v162_owner_session($user,$principal)
        && vp3_participant_scope_v1()===null
        && has_permission('account.access',$user)
        && has_permission('chat.access',$user);
}

function vp3_connected_sites_chat_bound_v1(PDO $pdo, array $user, array $principal, int $conversationId): bool
{
    $agentId=(int)($principal['agent_id']??0);
    return vp3_agent_chat_conversation_v380($pdo,$conversationId,(int)$user['id'],$agentId>0?['id'=>$agentId]:null)!==null;
}

function vp3_connected_sites_chat_alias_v1(string $address): ?string
{
    return vp3_connected_sites_chat_name_match_v1($address,'FoodMonster')
        ?? vp3_connected_sites_chat_name_match_v1($address,'Food Monster')
        ?? vp3_connected_sites_chat_name_match_v1($address,'Gelato');
}

function vp3_connected_sites_chat_ticket_number_v1(string $text): ?string
{
    if(!preg_match('/\b(?:tickets?|checks?)(?:\s+(?:number|no\.?))?\s+(?:#\s*)?([a-z0-9-]+)(?![a-z0-9-])/iu',$text,$matches)
        &&!preg_match('/\b(?:items?\s+(?:(?:are|were|is)\s+)?(?:on|in|for)|what(?:[’\x27]s|\s+(?:is|was))\s+on)\s+(?:#\s*)?([a-z0-9-]+)(?![a-z0-9-])/iu',$text,$matches))return null;
    return preg_match('/\d/',$matches[1])?vp3_gelato_ticket_number_v1($matches[1]):null;
}

function vp3_connected_sites_chat_ticket_followup_v1(string $query): bool
{
    return (bool)preg_match('/^(?:what\s+(?:line\s+)?items?\b|what(?:[’\x27]s|\s+(?:is|was))\s+(?:on|in)\b|(?:list|show)\s+(?:me\s+)?(?:the\s+)?(?:line\s+)?items?\b)/iu',$query)
        || vp3_connected_sites_chat_ticket_number_v1($query)!==null;
}

function vp3_connected_sites_chat_connections_v1(PDO $pdo,int $owner,bool $requireFoodMonster=false): array
{
    if(!function_exists('vp3_stoneward_connections_v1'))require_once __DIR__.'/connected-stoneward-v1.php';
    return array_merge(vp3_gelato_connections_v1($pdo,$owner,$requireFoodMonster),vp3_stoneward_connections_v1($pdo,$owner));
}

/** Resolve an explicit command or an exact private ticket continuation before general Chat tools. */
function vp3_connected_sites_chat_request_v1(PDO $pdo, array $user, array $principal, string $query, int $conversationId=0): ?array
{
    $address=vp3_connected_sites_chat_address_v1($query);
    if($address===null&&($conversationId<1||(!vp3_connected_sites_chat_ticket_followup_v1($query)
        &&!vp3_stoneward_chat_followup_v1($query))))return null;
    $gelato=$address!==null&&vp3_connected_sites_chat_alias_v1($address)!==null;
    $stoneward=$address!==null&&vp3_stoneward_chat_alias_v1($address)!==null;
    if(!vp3_connected_sites_chat_allowed_v1($user,$principal)){
        return $gelato||$stoneward?['answer'=>'Use your private Agent Chat with account access to ask this connected site.']:null;
    }
    try{
        $owner=(int)$user['id'];
        $previous=[];
        if($address===null){
            if(!vp3_connected_sites_chat_bound_v1($pdo,$user,$principal,$conversationId))return null;
            $previous=vp3_connected_sites_chat_previous_v1($pdo,$conversationId);
            if(!$previous)return null;
        }
        return vp3_connected_sites_chat_plan_v1($query,vp3_connected_sites_chat_connections_v1($pdo,$owner,$gelato),$previous);
    }catch(Throwable $error){
        if($stoneward)return ['answer'=>'The Stonefellow connections could not be checked. Open Chat Tools → Connected Sites to check the connection.'];
        if(!$gelato)return null;
        return ['answer'=>(int)$error->getCode()===503
            ? 'FoodMonster connections need the VP3 Database Upgrade before they can be used in Chat.'
            : 'The FoodMonster connection could not be checked. Try again or open Chat Tools → Connected Sites.'];
    }
}

function vp3_connected_sites_chat_actions_v1(string $question): array
{
    $actions=[];
    if(preg_match('/\b(?:sales|revenues?|how (?:did we do|are we doing|we did|we are doing)|what did we (?:do|sell|make)|gross|net|takings|refunds?)\b/iu',$question))
        $actions[]='sales.daily.read';
    if(preg_match('/\b(?:orders?|(?:what(?:[’\x27]s| is)?|anything)\s+(?:still\s+)?open|what\s+still\s+open)\b/iu',$question))
        $actions[]='orders.read';
    if(preg_match('/\b(?:tickets?|checks?|(?:still\s+)?open|kitchen|kds)\b/iu',$question))
        $actions[]='tickets.open.read';
    return $actions;
}

function vp3_connected_sites_chat_previous_v1(PDO $pdo, int $conversationId, ?int $messageId=null): array
{
    $before=$messageId===null?'':' AND id<?';
    $stmt=$pdo->prepare(
        "SELECT role,context_json,created_at,CURRENT_TIMESTAMP AS current_at FROM chat_messages WHERE conversation_id=?".$before."
          AND role IN ('user','assistant')
          ORDER BY id DESC LIMIT 12"
    );
    $stmt->execute($messageId===null?[$conversationId]:[$conversationId,$messageId]);
    while($row=$stmt->fetch(PDO::FETCH_ASSOC)){
        if($row['role']!=='assistant')return [];
        $context=json_decode((string)$row['context_json'],true);
        // A proactive status post did not replace the last conversational turn.
        if(($context['source']??null)==='agent_initiative')continue;
        // Both timestamps come from the same database session, including its timezone.
        $created=strtotime((string)$row['created_at'].' UTC');
        $now=strtotime((string)$row['current_at'].' UTC');
        if($created===false||$now===false||$created>$now||$now-$created>900)return [];
        return is_array($context['connected_site_context_v1']??null)?$context['connected_site_context_v1']:[];
    }
    return [];
}

function vp3_connected_sites_chat_plan_v1(string $query, array $connections, array $previous=[]): ?array
{
    $query=trim($query);
    $connection=null;
    $question=$query;
    $request=vp3_connected_sites_chat_address_v1($query);
    $explicit=$request!==null;
    $previousNumber=vp3_gelato_ticket_number_v1($previous['ticketNumber']??null);
    $ticketFollowup=false;
    if($explicit){
        $alias=vp3_connected_sites_chat_alias_v1($request);
        $stonewardAlias=vp3_stoneward_chat_alias_v1($request);
        $stonewardHostAddress=$stonewardAlias!==null&&preg_match('/^via\s+/iu',$stonewardAlias);
        $candidates=[];
        foreach($connections as $row){
            $isStoneward=($row['provider']??null)==='stoneward';
            if($stonewardHostAddress){
                if($isStoneward)$candidates[]=['connection'=>$row,'question'=>$stonewardAlias];
                continue;
            }
            $platformAlias=$isStoneward?$stonewardAlias:$alias;
            $otherAlias=$isStoneward?$alias:$stonewardAlias;
            $rest=vp3_connected_sites_chat_name_match_v1($request,(string)($row['agentName']??''));
            if($otherAlias!==null&&$rest===$otherAlias)$rest=null;
            if($platformAlias!==null&&($rest===null||strlen($platformAlias)<strlen($rest)))$rest=$platformAlias;
            if($rest!==null)$candidates[]=['connection'=>$row,'question'=>$rest];
        }
        if(!$candidates&&$stonewardAlias!==null)return ['answer'=>'Stonefellow is not paired with this VP3 account. Open Chat Tools → Connected Sites and use a pairing code from the artist site.'];
        if(!$candidates)return $alias===null?null:['answer'=>'FoodMonster is not paired with this VP3 account. Open Chat Tools → Connected Sites and use a pairing code from your FoodMonster installation.'];
        // The platform name and the Gelato alias work with every verified installation.
        $remaining=min(array_map(static fn(array $row): int=>strlen($row['question']),$candidates));
        $candidates=array_values(array_filter($candidates,static fn(array $row): bool=>strlen($row['question'])===$remaining));
        $hosts=array_values(array_unique(array_filter(array_map(
            static fn(array $row): string=>(string)parse_url((string)($row['connection']['origin']??''),PHP_URL_HOST),
            $candidates
        ))));
        if(preg_match('/^via\s+/iu',$candidates[0]['question'],$via)){
            $named=[];
            foreach($candidates as $row){
                $request=substr($row['question'],strlen($via[0]));
                $host=(string)parse_url((string)($row['connection']['origin']??''),PHP_URL_HOST);
                $rest=vp3_connected_sites_chat_name_match_v1($request,$host,true);
                if($rest===null&&!preg_match('/^["“]?(?:[a-z0-9-]+\.)+[a-z0-9-]+/i',$request)){
                    $rest=vp3_connected_sites_chat_name_match_v1($request,(string)($row['connection']['label']??''));
                }
                if($rest!==null)$named[]=['connection'=>$row['connection'],'question'=>$rest];
            }
            $candidates=$named;
        }
        if(count($candidates)!==1)return ['answer'=>'Choose one connected site by saying “Ask [agent name] via [hostname] …”.'
            .($hosts?' Connected hosts are '.implode(', ',$hosts).'.':'')];
        $connection=$candidates[0]['connection'];
        $question=$candidates[0]['question'];
        if(($connection['provider']??null)==='stoneward')return vp3_stoneward_chat_plan_v1($connection,$question);
        if(trim($question," \t.,?!:;")===''){
            $name=vp3_connected_sites_chat_text_v1($connection['agentName']??'')?:'FoodMonster';
            return ['answer'=>'What would you like to ask '.$name.'? Try “Ask FoodMonster for ticket #12345” or “Ask FoodMonster how we did today and what’s still open”.'];
        }
        if(preg_match('/^(?:search|find|look up)\s+(?:(?:the|a)\s+)?(menu|recipes?)\s+(?:(?:for|about)\s+)?(.+?)\s*[?.!]*$/iu',$question,$search)){
            return ['connection'=>$connection,'search'=>[
                'type'=>strtolower($search[1])==='menu'?'menu':'recipe',
                'query'=>mb_substr(trim($search[2]),0,160),
            ]];
        }
    }elseif(($previous['provider']??null)==='stoneward'){
        if(!vp3_stoneward_chat_followup_v1($query)||!in_array($previous['days']??null,[1,7,30],true))return null;
        foreach($connections as $row){
            if(($row['provider']??null)==='stoneward'&&($row['id']??null)===($previous['connectionId']??null)){
                return vp3_stoneward_chat_plan_v1($row,$query,$previous['days']);
            }
        }
        return ['answer'=>'That artist site is no longer connected. Open Connected Sites to pair it again.'];
    }elseif(!empty($previous['connectionId'])){
        $ticketFollowup=vp3_connected_sites_chat_ticket_followup_v1($query)
            && ($previousNumber!==null || (in_array('tickets.open.read',(array)($previous['actions']??[]),true)
                && vp3_connected_sites_chat_ticket_number_v1($query)!==null));
        $followup=$ticketFollowup||preg_match('/^(?:which\s+(?:ones|tickets|checks|orders)\b|what\s+about\b|and\s+(?:today|yesterday)\b|how\s+many\s+(?:are|were)\b)/iu',$query);
        if(!$followup&&empty($previous['needsLocation']))return null;
        foreach($connections as $row){
            if(($row['id']??null)===$previous['connectionId']){$connection=$row;break;}
        }
        if(!$connection)return $followup?['answer'=>'That connection is no longer available. Reconnect the site to ask again.']:null;
    }else return null;

    $locations=array_values(array_filter((array)($connection['locations']??[]),static fn($row): bool=>is_array($row)&&($row['id']??0)>0));
    $selected=[];
    foreach($locations as $row){
        $name=(string)($row['name']??'');
        if($name==='')continue;
        $pattern='/\b(?:at|for|in)\s+["“]?'.preg_quote($name,'/').'["”]?(?=\s*[,?.!]?\s*$)/iu';
        if(preg_match($pattern,$question,$match,PREG_OFFSET_CAPTURE)){
            $selected[]=$row;
            if(count($selected)===1)$questionWithoutLocation=rtrim(substr($question,0,$match[0][1]));
        }elseif(!$explicit&&!empty($previous['needsLocation'])&&mb_strtolower(trim($query," \t.?!"))===mb_strtolower($name)){
            $selected[]=$row;
            $questionWithoutLocation='';
        }
    }
    if(count($selected)>1)return ['answer'=>'More than one connected location has that name. Choose one with a distinct location name in FoodMonster.'];
    if(!$explicit&&!empty($previous['needsLocation'])&&!$selected&&!$followup)return null;
    if($selected)$question=$questionWithoutLocation;
    if(!$selected&&preg_match('/\bat\s+[^,?.!]+[?.!]?$/iu',$question))return ['answer'=>'I could not match that location to this connection. Use a location name from Connected Sites.'];
    if($ticketFollowup&&!empty($previous['locationId'])&&$selected&&(int)$selected[0]['id']!==(int)$previous['locationId']){
        return ['answer'=>'Ask the connected site with the new location and ticket number to change locations.'];
    }
    if(!$selected&&!$explicit&&!empty($previous['locationId'])){
        $selected=array_values(array_filter($locations,static fn(array $row): bool=>$row['id']===$previous['locationId']));
        if($ticketFollowup&&!$selected)return ['answer'=>'Your connection no longer permits POS ticket access at that location.'];
    }
    if(!$selected&&count($locations)===1)$selected=$locations;

    $ticketNumber=$explicit||$ticketFollowup?vp3_connected_sites_chat_ticket_number_v1($question):null;
    if($ticketFollowup&&$previousNumber!==null){
        if($ticketNumber!==null&&!vp3_gelato_ticket_number_matches_v1($ticketNumber,$previousNumber)){
            return ['answer'=>'That is a different POS ticket. Ask the connected site directly for its ticket number.'];
        }
        $ticketNumber=$previousNumber;
    }
    $actions=$ticketNumber!==null?['tickets.detail.read']:vp3_connected_sites_chat_actions_v1($question);
    $oldestFirst=(bool)preg_match('/\b(?:longest|oldest)\b/iu',$question);
    if($ticketNumber===null&&$oldestFirst&&(!in_array('orders.read',$actions,true)||preg_match('/\b(?:ones|tickets|checks)\b/iu',$question))){
        $actions=['tickets.open.read'];
    }
    if(preg_match('/\b(?:tomorrow|since|between|last week|last month|last year|last quarter|this week|this month|this year|this quarter|next week|next month|next year|next quarter|day before yesterday)\b/iu',$question))
        return ['answer'=>'Ask for one business date using today, yesterday, or YYYY-MM-DD.'];
    $namedDates=preg_match_all('/\b(today|yesterday)\b/iu',$question,$relative);
    $exactDates=preg_match_all('/\b(\d{4}-\d{2}-\d{2})\b/',$question,$exact);
    if($namedDates+$exactDates>1)return ['answer'=>'Ask for one business date at a time.'];
    $date=$namedDates?strtolower($relative[1][0]):'today';
    if($exactDates){
        $parts=explode('-',$exact[1][0]);
        if(!checkdate((int)$parts[1],(int)$parts[2],(int)$parts[0]))return ['answer'=>'Use a valid business date in YYYY-MM-DD format.'];
        $date=$exact[1][0];
    }
    if($ticketFollowup&&$previousNumber!==null&&($namedDates||$exactDates)
        &&isset($previous['date'])&&$date!==$previous['date']){
        return ['answer'=>'Ask the connected site directly with the ticket number and business date to change dates.'];
    }
    if(!$explicit&&!empty($previous['needsLocation'])&&$question===''){
        $actions=$previous['actions']??[];
        $date=$previous['date']??'today';
        $oldestFirst=!empty($previous['oldestFirst']);
        if(in_array('tickets.detail.read',$actions,true)){
            $ticketNumber=$previousNumber;
            $date=$previous['date']??null;
        }
    }elseif(!$explicit&&!$actions&&($namedDates||$exactDates)){
        if(!in_array('sales.daily.read',(array)($previous['actions']??[]),true))
            return ['answer'=>'This connection provides current active orders and open tickets. Ask for daily sales if you need a historical business date.'];
        $actions=['sales.daily.read'];
    }
    $actions=array_values(array_intersect(['sales.daily.read','orders.read','tickets.open.read','tickets.detail.read'],(array)$actions));
    if(!$actions)return $explicit?['answer'=>'Ask this connected agent for daily sales, a POS ticket number, active orders, open tickets, or a menu or recipe search.']:null;
    if(in_array('tickets.detail.read',$actions,true)&&$ticketNumber===null)return null;
    if($ticketNumber!==null&&!$namedDates&&!$exactDates)$date=(!$explicit&&$previousNumber!==null)?($previous['date']??null):null;
    if($ticketNumber===null&&$date!=='today'&&!in_array('sales.daily.read',$actions,true))
        return ['answer'=>'This connection provides current active orders and open tickets. Historical dates are available for daily sales.'];
    $location=$selected[0]??null;
    return [
        'connection'=>$connection,
        'location'=>$location,
        'context'=>[
            'connectionId'=>$connection['id'],'locationId'=>$location['id']??0,'needsLocation'=>$location===null,
            'actions'=>$actions,'date'=>$date,'oldestFirst'=>$oldestFirst,
        ]+($ticketNumber!==null?['ticketNumber'=>$ticketNumber]:[]),
    ];
}

function vp3_connected_sites_chat_money_v1(mixed $value, mixed $currency): ?string
{
    if(!is_string($value)||!preg_match('/^-?\d{1,15}(?:\.\d{1,4})?$/D',$value))return null;
    return is_string($currency)&&preg_match('/^[A-Z]{3}$/D',$currency)?$value.' '.$currency:$value.' (currency unrecorded)';
}

function vp3_connected_sites_chat_data_v1(string $action, mixed $data, ?string $day): bool
{
    if(!is_array($data))return false;
    if($action==='sales.daily.read'){
        $date=$data['businessDate']??null;
        return is_string($date)&&preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)
            &&($day===null||$day===$date)&&is_bool($data['available']??null)
            &&(!$data['available']||is_array($data['totals']??null));
    }
    if($action==='tickets.detail.read'){
        return array_key_exists('ticket',$data)&&is_bool($data['ambiguous']??null)
            &&($data['ticket']===null||is_array($data['ticket']));
    }
    $rows=$data[$action==='orders.read'?'orders':'tickets']??null;
    return is_array($rows)&&array_is_list($rows)&&is_bool($data['truncated']??null)
        &&count(array_filter($rows,'is_array'))===count($rows);
}

function vp3_connected_sites_chat_ticket_lines_v1(array $data, string $ticketNumber): array
{
    $ticket=$data['ticket'];
    if($ticket===null){
        return [$data['ambiguous']
            ? 'More than one POS ticket matches #'.$ticketNumber.'. Ask for the full printed ticket number or include its business date.'
            : 'No POS ticket matched #'.$ticketNumber.' at this location.'];
    }
    $title='Ticket #'.$ticket['checkNumber'].' · '.$ticket['businessDate'].' · '.vp3_connected_sites_chat_text_v1($ticket['status'],40);
    $lines=[$title];
    foreach($ticket['items'] as $item){
        $quantity=(string)$item['quantity'];
        if(str_contains($quantity,'.'))$quantity=rtrim(rtrim($quantity,'0'),'.');
        $lines[]='• '.$quantity.' × '.vp3_connected_sites_chat_text_v1($item['name'],160);
    }
    if(count($lines)===1)$lines[]='No item lines are recorded for this ticket.';
    if($ticket['truncated'])$lines[]='Open the POS source for the remaining item lines.';
    return $lines;
}

function vp3_connected_sites_chat_render_v1(string $action, array $data, bool $oldestFirst, array $openCheckIds=[]): array
{
    if($action==='sales.daily.read'){
        $date=preg_match('/^\d{4}-\d{2}-\d{2}$/D',(string)($data['businessDate']??''))?$data['businessDate']:'the requested day';
        $totals=$data['totals']??null;
        if(empty($data['available'])||!is_array($totals))return ['Daily sales for '.$date.' are unavailable in the reporting source.'];
        $fields=[];
        foreach(['netSales'=>'Net','grossSales'=>'gross','refunds'=>'refunds'] as $key=>$label){
            $money=vp3_connected_sites_chat_money_v1($totals[$key]??null,$data['currency']??null);
            if($money!==null)$fields[]=$label.' '.$money;
        }
        if(is_int($totals['tickets']??null)&&$totals['tickets']>=0)$fields[]=$totals['tickets'].' closed tickets';
        if(!$fields)return ['Daily sales for '.$date.' are unavailable in the reporting source.'];
        $lines=['Daily sales · '.$date.' · '.implode(', ',$fields).'.'];
        $provider=$data['provider']??null;
        if(is_string($provider)&&preg_match('/^[a-z0-9_-]{1,40}$/D',$provider))
            $lines[]='Sales provider · '.($provider==='gelato_pos'?'FoodMonster POS':str_replace('_',' ',$provider)).'.';
        $updated=$data['dataUpdatedAt']??null;
        if(is_string($updated)&&preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:Z)?$/D',$updated))$lines[]='Sales source updated '.$updated.'.';
        return $lines;
    }

    $orders=$action==='orders.read';
    $rows=array_values(array_filter((array)($data[$orders?'orders':'tickets']??[]),'is_array'));
    if(!$orders&&$oldestFirst){
        usort($rows,static fn(array $a,array $b): int=>(int)($b['ageSeconds']??0)<=>(int)($a['ageSeconds']??0));
    }
    $truncated=!empty($data['truncated']);
    $lines=[($orders?'Active pickup orders':'Open POS tickets').' · '.($truncated?'at least ':'').count($rows)];
    if($oldestFirst&&!$orders&&$rows)$lines[]=$truncated?'Longest open among the returned tickets.':'Longest open first.';
    foreach(array_slice($rows,0,5) as $row){
        $number=vp3_connected_sites_chat_text_v1($row['checkNumber']??'',35);
        $status='';
        if($orders){
            $status=str_replace('_',' ',vp3_connected_sites_chat_text_v1($row['status']??'',40));
            if(!empty($row['pastPromise']))$status.=' · late';
            if(in_array($row['checkId']??null,$openCheckIds,true))$status.=' · also an open POS ticket';
        }else{
            $status=vp3_connected_sites_chat_text_v1($row['tableName']??'',45);
            if($status==='')$status=vp3_connected_sites_chat_text_v1($row['serviceMode']??'',35);
            $kitchen=is_array($row['kitchen']??null)?$row['kitchen']:[];
            $state=vp3_connected_sites_chat_text_v1($kitchen['status']??'',40);
            if($state!=='')$status.=' · '.str_replace('_',' ',$state);
            if(!empty($kitchen['late']))$status.=' · late';
            if(is_int($row['ageSeconds']??null)&&$row['ageSeconds']>=0)$status.=' · open '.intdiv($row['ageSeconds'],60).' min';
        }
        $lines[]='• '.($number!==''?'#'.$number:($orders?'Order':'Ticket')).($status!==''?' · '.$status:'');
    }
    if(count($rows)>5||$truncated)$lines[]='Open the source for more records.';
    return $lines;
}

function vp3_connected_sites_chat_error_v1(string $label, string $name, Throwable $error): string
{
    return (int)$error->getCode()===403
        ? $name.' declined authorization for '.mb_strtolower($label).'. Check the paired permissions in FoodMonster.'
        : $label.' could not be read from '.$name.' right now.';
}

function vp3_connected_sites_chat_v1(PDO $pdo, array $user, array $principal, string $query, int $conversationId, int $messageId, ?array $explicitPlan=null): ?array
{
    if($conversationId<1||$messageId<1)return null;
    if(vp3_connected_sites_chat_address_v1($query)!==null){
        $plan=$explicitPlan??vp3_connected_sites_chat_request_v1($pdo,$user,$principal,$query);
    }else{
        if(!vp3_connected_sites_chat_allowed_v1($user,$principal))return null;
        try{
            $previous=vp3_connected_sites_chat_previous_v1($pdo,$conversationId,$messageId);
            $plan=$previous?vp3_connected_sites_chat_plan_v1($query,vp3_connected_sites_chat_connections_v1($pdo,(int)$user['id']),$previous):null;
        }catch(Throwable $error){return null;}
        if($explicitPlan!==null&&$plan!==$explicitPlan){
            $plan=['answer'=>'The previous connected-site context changed. Ask the site directly to start a new lookup.'];
        }
    }
    if($plan===null)return null;
    $result=['handled'=>true,'answer'=>$plan['answer']??'','sources'=>[],'connected_site_context_v1'=>$plan['context']??[]];
    if(isset($plan['answer']))return $result;
    if(!vp3_connected_sites_chat_allowed_v1($user,$principal))return null;
    $userId=(int)$user['id'];
    $connection=$plan['connection'];
    $name=vp3_connected_sites_chat_text_v1($connection['agentName']??'')?:'Connected agent';
    if(($connection['provider']??null)==='stoneward'){
        if(!vp3_connected_sites_chat_bound_v1($pdo,$user,$principal,$conversationId))return null;
        if(!function_exists('vp3_stoneward_history_v1'))require_once __DIR__.'/connected-stoneward-v1.php';
        if(isset($plan['play'])||isset($plan['resume'])||isset($plan['queue'])){
            try{
                $resume=null;
                if(isset($plan['resume'])){
                    require_once __DIR__.'/agent-media-stoneward-playback-v1.php';
                    $resume=vp3_stoneward_playback_resume_v1($pdo,$user,$principal,$connection['id']);
                    if($resume===null){
                        $result['answer']='I couldn’t find a recent unfinished track from this artist site in your VP3 listening.';
                        return $result;
                    }
                }
                if(isset($plan['queue'])){
                    $queue=vp3_stoneward_catalog_queue_v1($pdo,$userId,$connection['id']);
                    $result['answer']=$queue?'Here’s a short queue from '.$name.'.':'This artist site has no eligible public tracks for a queue.';
                    if($queue)$result['connected_site_queue_v1']=$queue;
                }else{
                    $playback=$resume===null
                        ?vp3_stoneward_playable_v1($pdo,$userId,$connection['id'],$plan['play'])
                        :vp3_stoneward_chat_resume_track_v1($pdo,$user,$principal,$resume);
                    $title=vp3_connected_sites_chat_text_v1($playback['title'],180);
                    $result['answer']=$resume===null?'Here’s “'.$title.'” from '.$name.'.':'“'.$title.'” is ready at your saved position.';
                    $result['connected_site_playback_v1']=$playback;
                }
                if(!vp3_connected_sites_chat_bound_v1($pdo,$user,$principal,$conversationId))return null;
                $result['sources'][]=['source'=>'connected-site:'.$connection['id'].':'.$plan['context']['actions'][0],
                    'title'=>$name.' · Stonefellow','url'=>$connection['origin'].'/'];
            }catch(Throwable $error){
                $result['connected_site_context_v1']=[];
                $result['answer']=vp3_stoneward_chat_play_error_v1($error);
            }
            return $result;
        }
        try{
            $read=vp3_stoneward_history_v1($pdo,$userId,$connection['id'],$plan['context']['days']);
            if(!vp3_connected_sites_chat_bound_v1($pdo,$user,$principal,$conversationId))return null;
            $result['answer']=vp3_stoneward_chat_answer_v1($plan['context'],$read,$name);
        }catch(Throwable $error){
            $result['connected_site_context_v1']=[];
            $result['answer']=in_array((int)$error->getCode(),[403,404],true)
                ?'The listening history grant changed. Reconnect this artist site in Connected Sites.'
                :'Listening history could not be read from '.$name.' right now.';
            return $result;
        }
        $result['sources'][]=['source'=>'connected-site:'.$connection['id'].':listening.history.read',
            'title'=>$name.' · Listening history','url'=>$connection['origin'].'/?view=history'];
        return $result;
    }
    $disconnected=['handled'=>true,'answer'=>'That FoodMonster connection is no longer available. Open Chat Tools → Connected Sites to reconnect it.',
        'sources'=>[],'connected_site_context_v1'=>[]];
    if(isset($plan['search'])){
        $type=$plan['search']['type'];
        if(!in_array($type,(array)$connection['scopes'],true)){
            $result['answer']=ucfirst($type).' access is outside this connection’s approved scopes.';
            return $result;
        }
        try{
            $search=vp3_gelato_search_v1($pdo,$userId,$connection['id'],$plan['search']['query'],$type,5);
        }catch(Throwable $error){
            if((int)$error->getCode()===404)return $disconnected;
            $result['answer']=vp3_connected_sites_chat_error_v1(ucfirst($type).' results',$name,$error);
            return $result;
        }
        $titles=[];
        foreach(array_slice((array)($search['results']??[]),0,5) as $item){
            if(!is_array($item)||($item['type']??null)!==$type)continue;
            $title=vp3_connected_sites_chat_text_v1($item['title']??'');
            if($title!=='')$titles[]='• '.$title;
        }
        $result['answer']=$name.' · '.ucfirst($type).' search'."\n\n".($titles?implode("\n",$titles):'No matching records.');
        $result['sources'][]=['source'=>'connected-site:'.$connection['id'].':'.$type,'title'=>$name.' · '.ucfirst($type),'url'=>$connection['origin'].($type==='menu'?'/menu.php':'/recipes.php')];
        return $result;
    }
    $location=$plan['location'];
    if(!$location){
        $names=array_filter(array_map(static fn(array $row): string=>vp3_connected_sites_chat_text_v1($row['name']??''),$connection['locations']));
        $result['answer']=$names?'Which location should I ask '.$name.' about? '.implode(', ',$names).'.'
            :'No location is approved for this connection. Review its permissions in Connected Sites.';
        return $result;
    }

    $sections=[];
    $date=$plan['context']['date'];
    $ticketNumber=$plan['context']['ticketNumber']??null;
    if($ticketNumber!==null&&!vp3_connected_sites_chat_bound_v1($pdo,$user,$principal,$conversationId))return null;
    $asOf=[];
    $labels=['sales.daily.read'=>'Daily sales','orders.read'=>'Active pickup orders','tickets.open.read'=>'Open POS tickets',
        'tickets.detail.read'=>'POS ticket details'];
    foreach($plan['context']['actions'] as $action){
        if(!in_array($action,(array)$connection['scopes'],true)){
            $sections[$action]=$labels[$action].' access is outside this connection’s approved scopes.';
            if($action==='tickets.detail.read')$sections[$action].=' Enable POS ticket details in FoodMonster and reconnect with an updated pairing code.';
            continue;
        }
        try{
            $day=null;
            if($action==='sales.daily.read'&&$date!=='today'){
                $day=$date==='yesterday'?(new DateTimeImmutable('yesterday',new DateTimeZone((string)$location['timezone'])))->format('Y-m-d'):$date;
            }
            if($action==='tickets.detail.read'){
                $day=match($date){
                    'today','yesterday'=>(new DateTimeImmutable($date,new DateTimeZone((string)$location['timezone'])))->format('Y-m-d'),
                    default=>$date,
                };
            }
            $read=vp3_gelato_operation_v1($pdo,$userId,$connection['id'],$action,(int)$location['id'],$day,ticketNumber:$ticketNumber);
            if(!vp3_connected_sites_chat_data_v1($action,$read['data']??null,$day))
                throw new RuntimeException('Unexpected connected-site result.');
        }catch(Throwable $error){
            if((int)$error->getCode()===404)return $disconnected;
            $sections[$action]=vp3_connected_sites_chat_error_v1($labels[$action],$name,$error);
            continue;
        }
        $sections[$action]=$read['data'];
        if($action==='tickets.detail.read'&&$read['data']['ticket']!==null){
            $result['connected_site_context_v1']['ticketNumber']=$read['data']['ticket']['checkNumber'];
            $result['connected_site_context_v1']['date']=$read['data']['ticket']['businessDate'];
        }
        if(is_string($read['asOf']??null)&&preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|\+00:00)$/D',$read['asOf']))
            $asOf[]=substr($read['asOf'],0,19).'Z';
        $path=$read['source']['path']??null;
        if(is_string($path)&&preg_match('#^/[a-zA-Z0-9_-]+\.php$#D',$path)){
            $result['sources'][]=['source'=>'connected-site:'.$connection['id'].':'.$action,'title'=>$name.' · '.$labels[$action],'url'=>$connection['origin'].$path];
        }
    }
    if($ticketNumber!==null&&!vp3_connected_sites_chat_bound_v1($pdo,$user,$principal,$conversationId))return null;
    $lines=[];
    $openChecks=is_array($sections['tickets.open.read']??null)?array_column($sections['tickets.open.read']['tickets'],'checkId'):[];
    foreach($sections as $action=>$section){
        if(is_string($section))$lines[]=$section;
        elseif($action==='tickets.detail.read')array_push($lines,...vp3_connected_sites_chat_ticket_lines_v1($section,$ticketNumber));
        else array_push($lines,...vp3_connected_sites_chat_render_v1($action,$section,$plan['context']['oldestFirst'],$openChecks));
    }
    if($asOf)$lines[]='Read at '.min($asOf).' (UTC).';
    $result['answer']=$name.' · '.vp3_connected_sites_chat_text_v1($location['name']??'')."\n\n".implode("\n",$lines);
    return $result;
}
