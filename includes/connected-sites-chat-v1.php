<?php
declare(strict_types=1);

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

function vp3_connected_sites_chat_previous_v1(PDO $pdo, int $conversationId, int $messageId): array
{
    $stmt=$pdo->prepare(
        "SELECT role,context_json,created_at,CURRENT_TIMESTAMP AS current_at FROM chat_messages WHERE conversation_id=? AND id<?
          AND role IN ('user','assistant')
          ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$conversationId,$messageId]);
    $row=$stmt->fetch(PDO::FETCH_ASSOC);
    if(($row['role']??'')!=='assistant')return [];
    // Both timestamps come from the same database session, including its timezone.
    $created=strtotime((string)$row['created_at'].' UTC');
    $now=strtotime((string)$row['current_at'].' UTC');
    if($created===false||$now===false||$created>$now||$now-$created>900)return [];
    $context=json_decode((string)$row['context_json'],true);
    return is_array($context['connected_site_context_v1']??null)?$context['connected_site_context_v1']:[];
}

function vp3_connected_sites_chat_plan_v1(string $query, array $connections, array $previous=[]): ?array
{
    $query=trim($query);
    $connection=null;
    $question=$query;
    $explicit=preg_match('/^(?:(?:please|(?:can|could|would) you)\s+)?ask\s+/iu',$query,$prefix);
    if($explicit){
        $request=substr($query,strlen($prefix[0]));
        $candidates=[];
        foreach($connections as $row){
            $rest=vp3_connected_sites_chat_name_match_v1($request,(string)($row['agentName']??''));
            if($rest!==null)$candidates[]=['connection'=>$row,'question'=>$rest];
        }
        if(!$candidates)return null;
        // If two agent names overlap, the longest exact prefix wins. Duplicate names require a label.
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
                $rest=vp3_connected_sites_chat_name_match_v1($request,$host,true)
                    ??vp3_connected_sites_chat_name_match_v1($request,(string)($row['connection']['label']??''));
                if($rest!==null)$named[]=['connection'=>$row['connection'],'question'=>$rest];
            }
            $candidates=$named;
        }
        if(count($candidates)!==1)return ['answer'=>'Choose one connected installation by saying “Ask [agent name] via [hostname] …”.'
            .($hosts?' Connected hosts are '.implode(', ',$hosts).'.':'')];
        $connection=$candidates[0]['connection'];
        $question=$candidates[0]['question'];
        if(preg_match('/^(?:search|find|look up)\s+(?:(?:the|a)\s+)?(menu|recipes?)\s+(?:(?:for|about)\s+)?(.+?)\s*[?.!]*$/iu',$question,$search)){
            return ['connection'=>$connection,'search'=>[
                'type'=>strtolower($search[1])==='menu'?'menu':'recipe',
                'query'=>mb_substr(trim($search[2]),0,160),
            ]];
        }
    }elseif(!empty($previous['connectionId'])){
        $followup=preg_match('/^(?:which\s+(?:ones|tickets|checks|orders)\b|what\s+about\b|and\s+(?:today|yesterday)\b|how\s+many\s+(?:are|were)\b)/iu',$query);
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
    if(count($selected)>1)return ['answer'=>'More than one connected location has that name. Choose one with a distinct location name in Gelato.'];
    if(!$explicit&&!empty($previous['needsLocation'])&&!$selected&&!$followup)return null;
    if($selected)$question=$questionWithoutLocation;
    if(!$selected&&preg_match('/\bat\s+[^,?.!]+[?.!]?$/iu',$question))return ['answer'=>'I could not match that location to this connection. Use a location name from Connected Sites.'];
    if(!$selected&&count($locations)===1)$selected=$locations;
    if(!$selected&&!$explicit&&!empty($previous['locationId'])){
        $selected=array_values(array_filter($locations,static fn(array $row): bool=>$row['id']===$previous['locationId']));
    }

    $actions=vp3_connected_sites_chat_actions_v1($question);
    $oldestFirst=(bool)preg_match('/\b(?:longest|oldest)\b/iu',$question);
    if($oldestFirst&&(!in_array('orders.read',$actions,true)||preg_match('/\b(?:ones|tickets|checks)\b/iu',$question))){
        $actions=['tickets.open.read'];
    }
    if(preg_match('/\b(?:tomorrow|since|between|last week|last month|last year|last quarter|this week|this month|this year|this quarter|next week|next month|next year|next quarter|day before yesterday)\b/iu',$question))
        return ['answer'=>'Daily sales support one business date. Ask for today, yesterday, or a date in YYYY-MM-DD format.'];
    $namedDates=preg_match_all('/\b(today|yesterday)\b/iu',$question,$relative);
    $exactDates=preg_match_all('/\b(\d{4}-\d{2}-\d{2})\b/',$question,$exact);
    if($namedDates+$exactDates>1)return ['answer'=>'Ask for one business date at a time.'];
    $date=$namedDates?strtolower($relative[1][0]):'today';
    if($exactDates){
        $parts=explode('-',$exact[1][0]);
        if(!checkdate((int)$parts[1],(int)$parts[2],(int)$parts[0]))return ['answer'=>'Use a valid business date in YYYY-MM-DD format.'];
        $date=$exact[1][0];
    }
    if(!$explicit&&!empty($previous['needsLocation'])&&$question===''){
        $actions=$previous['actions']??[];
        $date=$previous['date']??'today';
        $oldestFirst=!empty($previous['oldestFirst']);
    }elseif(!$explicit&&!$actions&&($namedDates||$exactDates)){
        if(!in_array('sales.daily.read',(array)($previous['actions']??[]),true))
            return ['answer'=>'This connection provides current active orders and open tickets. Ask for daily sales if you need a historical business date.'];
        $actions=['sales.daily.read'];
    }
    $actions=array_values(array_intersect(['sales.daily.read','orders.read','tickets.open.read'],(array)$actions));
    if(!$actions)return $explicit?['answer'=>'Ask this connected agent for daily sales, active orders, open tickets, or a menu or recipe search.']:null;
    if($date!=='today'&&!in_array('sales.daily.read',$actions,true))
        return ['answer'=>'This connection provides current active orders and open tickets. Historical dates are available for daily sales.'];
    $location=$selected[0]??null;
    return [
        'connection'=>$connection,
        'location'=>$location,
        'context'=>[
            'connectionId'=>$connection['id'],'locationId'=>$location['id']??0,'needsLocation'=>$location===null,
            'actions'=>$actions,'date'=>$date,'oldestFirst'=>$oldestFirst,
        ],
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
    $rows=$data[$action==='orders.read'?'orders':'tickets']??null;
    return is_array($rows)&&array_is_list($rows)&&is_bool($data['truncated']??null)
        &&count(array_filter($rows,'is_array'))===count($rows);
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
            $lines[]='Sales provider · '.($provider==='gelato_pos'?'Gelato POS':str_replace('_',' ',$provider)).'.';
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

function vp3_connected_sites_chat_v1(PDO $pdo, array $user, array $principal, string $query, int $conversationId, int $messageId): ?array
{
    if($conversationId<1||$messageId<1||!knowledge_retrieval_v162_owner_session($user,$principal)
        ||!has_permission('account.access',$user)||!has_permission('chat.access',$user))return null;
    $userId=(int)$user['id'];
    try{
        $connections=vp3_gelato_connections_v1($pdo,$userId);
        if(!$connections)return null;
        if(!preg_match('/^(?:(?:please|(?:can|could|would) you)\s+)?ask\s+/iu',$query)){
            $previous=vp3_connected_sites_chat_previous_v1($pdo,$conversationId,$messageId);
            if(!$previous)return null;
        }
    }catch(Throwable $e){return null;}
    $plan=vp3_connected_sites_chat_plan_v1($query,$connections,$previous??[]);
    if($plan===null)return null;
    $result=['handled'=>true,'answer'=>$plan['answer']??'','sources'=>[],'connected_site_context_v1'=>$plan['context']??[]];
    if(isset($plan['answer']))return $result;
    $connection=$plan['connection'];
    $name=vp3_connected_sites_chat_text_v1($connection['agentName']??'')?:'Connected agent';
    if(isset($plan['search'])){
        $type=$plan['search']['type'];
        if(!in_array($type,(array)$connection['scopes'],true)){
            $result['answer']=ucfirst($type).' access is outside this connection’s approved scopes.';
            return $result;
        }
        try{
            $search=vp3_gelato_search_v1($pdo,$userId,$connection['id'],$plan['search']['query'],$type,5);
        }catch(Throwable $e){
            $result['answer']=ucfirst($type).' results could not be read from '.$name.' right now.';
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
    $asOf=[];
    $labels=['sales.daily.read'=>'Daily sales','orders.read'=>'Active pickup orders','tickets.open.read'=>'Open POS tickets'];
    foreach($plan['context']['actions'] as $action){
        if(!in_array($action,(array)$connection['scopes'],true)){
            $sections[$action]=$labels[$action].' access is outside this connection’s approved scopes.';
            continue;
        }
        try{
            $day=null;
            if($action==='sales.daily.read'&&$date!=='today'){
                $day=$date==='yesterday'?(new DateTimeImmutable('yesterday',new DateTimeZone((string)$location['timezone'])))->format('Y-m-d'):$date;
            }
            $read=vp3_gelato_operation_v1($pdo,$userId,$connection['id'],$action,(int)$location['id'],$day);
            if(!vp3_connected_sites_chat_data_v1($action,$read['data']??null,$day))
                throw new RuntimeException('Unexpected connected-site result.');
        }catch(Throwable $e){
            $sections[$action]=$labels[$action].' could not be read from '.$name.' right now.';
            continue;
        }
        $sections[$action]=$read['data'];
        if(is_string($read['asOf']??null)&&preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|\+00:00)$/D',$read['asOf']))
            $asOf[]=substr($read['asOf'],0,19).'Z';
        $path=$read['source']['path']??null;
        if(is_string($path)&&preg_match('#^/[a-zA-Z0-9_-]+\.php$#D',$path)){
            $result['sources'][]=['source'=>'connected-site:'.$connection['id'].':'.$action,'title'=>$name.' · '.$labels[$action],'url'=>$connection['origin'].$path];
        }
    }
    $lines=[];
    $openChecks=is_array($sections['tickets.open.read']??null)?array_column($sections['tickets.open.read']['tickets'],'checkId'):[];
    foreach($sections as $action=>$section){
        if(is_string($section))$lines[]=$section;
        else array_push($lines,...vp3_connected_sites_chat_render_v1($action,$section,$plan['context']['oldestFirst'],$openChecks));
    }
    if($asOf)$lines[]='Read at '.min($asOf).' (UTC).';
    $result['answer']=$name.' · '.vp3_connected_sites_chat_text_v1($location['name']??'')."\n\n".implode("\n",$lines);
    return $result;
}
