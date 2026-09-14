<?php
declare(strict_types=1);

/**
 * VP3 Profile Revenue & Conversion Intelligence v1.80
 *
 * Read-only intelligence over the existing Profile event ledger. This layer
 * does not create a second analytics store or expose visitor/session identity.
 * Revenue is always kept in per-currency buckets so unlike currencies are never
 * added together.
 */
const VP3_PROFILE_REVENUE_INTELLIGENCE_V180 = 'profile-revenue-intelligence-v180-20260914';

function profile_revenue_meta_v180(string $json): array
{
    $decoded=json_decode($json,true);
    return is_array($decoded)?$decoded:[];
}

function profile_revenue_text_v180(mixed $value,int $max=160): string
{
    return mb_strimwidth(trim((string)$value),0,max(1,$max),'…');
}

function profile_revenue_currency_v180(mixed $value): string
{
    $currency=strtolower(trim((string)$value));
    return preg_match('/^[a-z]{3}$/',$currency)?$currency:'';
}

function profile_revenue_safe_url_v180(mixed $value): string
{
    $url=trim((string)$value);
    if($url===''||!filter_var($url,FILTER_VALIDATE_URL))return '';
    $scheme=strtolower((string)parse_url($url,PHP_URL_SCHEME));
    return in_array($scheme,['http','https'],true)?mb_strimwidth($url,0,500,''):'';
}

function profile_revenue_money_add_v180(array &$buckets,string $currency,int $cents): void
{
    if($currency===''||$cents<=0)return;
    if(!isset($buckets[$currency]))$buckets[$currency]=0;
    $buckets[$currency]+=$cents;
}

function profile_revenue_money_rows_v180(array $buckets): array
{
    arsort($buckets,SORT_NUMERIC);
    $rows=[];
    foreach($buckets as $currency=>$cents){
        if(!preg_match('/^[a-z]{3}$/',(string)$currency)||$cents<=0)continue;
        $rows[]=['currency'=>(string)$currency,'value_cents'=>(int)$cents];
    }
    return $rows;
}

function profile_revenue_source_v180(array $metadata): array
{
    $campaign=profile_revenue_text_v180($metadata['utm_campaign']??'',120);
    $source=profile_revenue_text_v180($metadata['utm_source']??'',100);
    $medium=profile_revenue_text_v180($metadata['utm_medium']??'',80);
    $referrer=strtolower(profile_revenue_text_v180($metadata['referrer_host']??'',190));
    if($campaign!==''){
        $label='Campaign · '.$campaign.($source!==''?' · '.$source:'');
        return ['key'=>'campaign:'.strtolower($campaign.'|'.$source),'type'=>'campaign','label'=>$label];
    }
    if($source!==''){
        $label=$source.($medium!==''?' · '.$medium:'');
        return ['key'=>'utm:'.strtolower($source.'|'.$medium),'type'=>'utm','label'=>$label];
    }
    if($referrer!=='')return ['key'=>'referrer:'.$referrer,'type'=>'referrer','label'=>$referrer];
    return ['key'=>'direct','type'=>'direct','label'=>'Direct'];
}

function profile_revenue_rate_v180(int $conversions,int $intents): ?float
{
    return $intents>0?round(($conversions/$intents)*100,1):null;
}

function profile_revenue_change_v180(float|int $current,float|int $previous): array
{
    $delta=$current-$previous;
    $pct=null;
    if((float)$previous!==0.0)$pct=round(($delta/abs((float)$previous))*100,1);
    elseif((float)$current===0.0)$pct=0.0;
    return ['current'=>$current,'previous'=>$previous,'delta'=>$delta,'percent'=>$pct];
}

function profile_revenue_period_snapshot_v180(array $rows,DateTimeImmutable $start,DateTimeImmutable $end,array $sessionSources): array
{
    $counts=['views'=>0,'booking_intents'=>0,'product_intents'=>0,'booking_conversions'=>0,'product_conversions'=>0];
    $revenue=[];
    foreach($rows as $row){
        $at=strtotime((string)($row['created_at']??''));
        if($at===false||$at<$start->getTimestamp()||$at>=$end->getTimestamp())continue;
        $type=(string)($row['event_type']??'');
        if($type==='profile_view')$counts['views']++;
        elseif($type==='booking_intent')$counts['booking_intents']++;
        elseif($type==='product_intent')$counts['product_intents']++;
        elseif($type==='booking_converted')$counts['booking_conversions']++;
        elseif($type==='product_converted')$counts['product_conversions']++;
        if(in_array($type,['booking_converted','product_converted'],true)){
            $metadata=$row['_meta']??profile_revenue_meta_v180((string)($row['metadata_json']??''));
            profile_revenue_money_add_v180($revenue,profile_revenue_currency_v180($metadata['currency']??''),max(0,(int)($metadata['value_cents']??0)));
        }
    }
    $intents=$counts['booking_intents']+$counts['product_intents'];
    $conversions=$counts['booking_conversions']+$counts['product_conversions'];
    return $counts+[
        'intents'=>$intents,
        'conversions'=>$conversions,
        'conversion_rate'=>profile_revenue_rate_v180($conversions,$intents),
        'booking_conversion_rate'=>profile_revenue_rate_v180($counts['booking_conversions'],$counts['booking_intents']),
        'product_conversion_rate'=>profile_revenue_rate_v180($counts['product_conversions'],$counts['product_intents']),
        'revenue'=>profile_revenue_money_rows_v180($revenue),
    ];
}

function profile_revenue_period_comparison_v180(array $current,array $previous): array
{
    return [
        'views'=>profile_revenue_change_v180((int)$current['views'],(int)$previous['views']),
        'intents'=>profile_revenue_change_v180((int)$current['intents'],(int)$previous['intents']),
        'conversions'=>profile_revenue_change_v180((int)$current['conversions'],(int)$previous['conversions']),
        'conversion_rate_points'=>round((float)($current['conversion_rate']??0)-(float)($previous['conversion_rate']??0),1),
        'booking_rate_points'=>round((float)($current['booking_conversion_rate']??0)-(float)($previous['booking_conversion_rate']??0),1),
        'product_rate_points'=>round((float)($current['product_conversion_rate']??0)-(float)($previous['product_conversion_rate']??0),1),
    ];
}

function profile_revenue_target_key_v180(string $kind,array $metadata): string
{
    $id=max(0,(int)($metadata['target_id']??0));
    if($id>0)return $kind.':id:'.$id;
    $slug=strtolower(profile_revenue_text_v180($metadata['target_slug']??'',120));
    if($slug!=='')return $kind.':slug:'.$slug;
    return $kind.':title:'.strtolower(profile_revenue_text_v180($metadata['target_title']??'unknown',190));
}

function profile_revenue_top_targets_v180(array $rows,DateTimeImmutable $start,DateTimeImmutable $end,int $limit=10): array
{
    $targets=[];
    foreach($rows as $row){
        $at=strtotime((string)($row['created_at']??''));
        if($at===false||$at<$start->getTimestamp()||$at>=$end->getTimestamp())continue;
        $type=(string)($row['event_type']??'');
        if(!in_array($type,['booking_intent','product_intent','booking_converted','product_converted'],true))continue;
        $kind=str_starts_with($type,'booking_')?'booking':'product';
        $metadata=$row['_meta']??profile_revenue_meta_v180((string)($row['metadata_json']??''));
        $key=profile_revenue_target_key_v180($kind,$metadata);
        if(!isset($targets[$key])){
            $targets[$key]=[
                'kind'=>$kind,
                'target_id'=>max(0,(int)($metadata['target_id']??0)),
                'target_slug'=>profile_revenue_text_v180($metadata['target_slug']??'',120),
                'target_title'=>profile_revenue_text_v180($metadata['target_title']??($kind==='booking'?'Booking':'Product'),190),
                'target_url'=>profile_revenue_safe_url_v180($metadata['target_url']??''),
                'intents'=>0,'conversions'=>0,'revenue'=>[],'last_conversion_at'=>'',
            ];
        }
        if($targets[$key]['target_title']===''&&isset($metadata['target_title']))$targets[$key]['target_title']=profile_revenue_text_v180($metadata['target_title'],190);
        if($targets[$key]['target_url']===''&&isset($metadata['target_url']))$targets[$key]['target_url']=profile_revenue_safe_url_v180($metadata['target_url']);
        if(str_ends_with($type,'_intent'))$targets[$key]['intents']++;
        else{
            $targets[$key]['conversions']++;
            $targets[$key]['last_conversion_at']=(string)($row['created_at']??'');
            $currency=profile_revenue_currency_v180($metadata['currency']??'');
            $cents=max(0,(int)($metadata['value_cents']??0));
            profile_revenue_money_add_v180($targets[$key]['revenue'],$currency,$cents);
        }
    }
    foreach($targets as &$target){
        $target['conversion_rate']=profile_revenue_rate_v180((int)$target['conversions'],(int)$target['intents']);
        $target['revenue']=profile_revenue_money_rows_v180($target['revenue']);
    }
    unset($target);
    $rowsOut=array_values($targets);
    usort($rowsOut,static function(array $a,array $b): int{
        $scoreA=((int)$a['conversions']*100000)+((int)$a['intents']*100)+(int)round((float)($a['conversion_rate']??0));
        $scoreB=((int)$b['conversions']*100000)+((int)$b['intents']*100)+(int)round((float)($b['conversion_rate']??0));
        return $scoreB<=>$scoreA;
    });
    return array_slice($rowsOut,0,max(1,min(25,$limit)));
}

function profile_revenue_sources_v180(array $rows,DateTimeImmutable $start,DateTimeImmutable $end,array $sessionSources,int $limit=10): array
{
    $sources=[];
    foreach($rows as $row){
        $at=strtotime((string)($row['created_at']??''));
        if($at===false||$at<$start->getTimestamp()||$at>=$end->getTimestamp())continue;
        $type=(string)($row['event_type']??'');
        if(!in_array($type,['booking_intent','product_intent','booking_converted','product_converted'],true))continue;
        $metadata=$row['_meta']??profile_revenue_meta_v180((string)($row['metadata_json']??''));
        $candidate=profile_revenue_source_v180($metadata);
        $sessionId=max(0,(int)($row['profile_session_id']??0));
        if($candidate['type']==='direct'&&$sessionId>0&&isset($sessionSources[$sessionId]))$candidate=$sessionSources[$sessionId];
        if($sessionId<1&&$candidate['type']==='direct')$candidate=['key'=>'unattributed','type'=>'unattributed','label'=>'Unattributed'];
        $key=(string)$candidate['key'];
        if(!isset($sources[$key]))$sources[$key]=['type'=>$candidate['type'],'label'=>$candidate['label'],'intents'=>0,'conversions'=>0,'revenue'=>[]];
        if(str_ends_with($type,'_intent'))$sources[$key]['intents']++;
        else{
            $sources[$key]['conversions']++;
            profile_revenue_money_add_v180($sources[$key]['revenue'],profile_revenue_currency_v180($metadata['currency']??''),max(0,(int)($metadata['value_cents']??0)));
        }
    }
    foreach($sources as &$source){
        $source['conversion_rate']=profile_revenue_rate_v180((int)$source['conversions'],(int)$source['intents']);
        $source['revenue']=profile_revenue_money_rows_v180($source['revenue']);
    }
    unset($source);
    $out=array_values($sources);
    usort($out,static fn(array $a,array $b): int=>(($b['conversions']*1000)+$b['intents'])<=>(($a['conversions']*1000)+$a['intents']));
    return array_slice($out,0,max(1,min(25,$limit)));
}

function profile_revenue_opportunities_v180(array $targets,array $period30): array
{
    $opportunities=[];
    $overall=[
        'booking'=>(float)($period30['booking_conversion_rate']??0),
        'product'=>(float)($period30['product_conversion_rate']??0),
    ];
    foreach($targets as $target){
        $intents=(int)($target['intents']??0);$conversions=(int)($target['conversions']??0);
        $rate=$target['conversion_rate'];$kind=(string)($target['kind']??'product');
        $title=(string)($target['target_title']??($kind==='booking'?'Booking':'Product'));
        if($intents>=3&&$conversions===0){
            $opportunities[]=[
                'priority'=>'high','type'=>'conversion_gap','kind'=>$kind,'target_title'=>$title,'target_url'=>$target['target_url']??'',
                'headline'=>$title.' has intent but no conversions',
                'summary'=>$intents.' tracked intent'.($intents===1?'':'s').' in the last 30 days with no matching conversion.',
                'action_key'=>'review_offer','action_label'=>$kind==='booking'?'Review booking offer and friction':'Review product offer and checkout friction',
            ];
            continue;
        }
        if($intents>=5&&$rate!==null&&$overall[$kind]>0&&$rate<($overall[$kind]*0.6)){
            $opportunities[]=[
                'priority'=>'medium','type'=>'underperformer','kind'=>$kind,'target_title'=>$title,'target_url'=>$target['target_url']??'',
                'headline'=>$title.' is converting below your '.$kind.' average',
                'summary'=>number_format((float)$rate,1).'% vs '.number_format($overall[$kind],1).'% overall for '.$kind.' intent in the last 30 days.',
                'action_key'=>'review_offer','action_label'=>'Review positioning, price, and conversion friction',
            ];
            continue;
        }
        if($conversions>=2&&$intents>=3&&$rate!==null&&$rate>=max(10.0,$overall[$kind]*1.25)){
            $opportunities[]=[
                'priority'=>'positive','type'=>'top_performer','kind'=>$kind,'target_title'=>$title,'target_url'=>$target['target_url']??'',
                'headline'=>$title.' is a strong converter',
                'summary'=>$conversions.' conversion'.($conversions===1?'':'s').' from '.$intents.' tracked intents ('.number_format((float)$rate,1).'%).',
                'action_key'=>'promote_winner','action_label'=>'Consider featuring this offer more prominently',
            ];
        }
    }
    $rank=['high'=>0,'medium'=>1,'positive'=>2];
    usort($opportunities,static fn(array $a,array $b): int=>($rank[$a['priority']]??9)<=>($rank[$b['priority']]??9));
    return array_slice($opportunities,0,8);
}

function profile_revenue_insights_v180(array $periods,array $targets,array $sources,array $opportunities): array
{
    $insights=[];
    $p30=$periods['30d']['current']??[];$prev=$periods['30d']['previous']??[];
    $revenue=$p30['revenue']??[];
    if($revenue){
        foreach(array_slice($revenue,0,2) as $money)$insights[]=['type'=>'revenue','message'=>'Tracked Profile revenue in the last 30 days: '.strtoupper((string)$money['currency']).' '.number_format(((int)$money['value_cents'])/100,2).'.'];
    }
    $currentConversions=(int)($p30['conversions']??0);$previousConversions=(int)($prev['conversions']??0);
    if($currentConversions>0||$previousConversions>0){
        $change=profile_revenue_change_v180($currentConversions,$previousConversions);
        $direction=$change['delta']>0?'up':($change['delta']<0?'down':'flat');
        $insights[]=['type'=>'trend','message'=>'30-day conversions are '.$direction.' at '.$currentConversions.' vs '.$previousConversions.' in the previous 30-day period.'];
    }
    if($targets){
        $top=$targets[0];
        if((int)($top['conversions']??0)>0)$insights[]=['type'=>'winner','message'=>(string)$top['target_title'].' leads tracked conversions with '.(int)$top['conversions'].' in the last 30 days.'];
    }
    if($sources){
        $best=$sources[0];
        if((int)($best['conversions']??0)>0)$insights[]=['type'=>'source','message'=>(string)$best['label'].' is the leading attributed source with '.(int)$best['conversions'].' conversion'.((int)$best['conversions']===1?'':'s').' in the last 30 days.'];
    }
    $gaps=array_values(array_filter($opportunities,static fn(array $o): bool=>in_array($o['priority'],['high','medium'],true)));
    if($gaps)$insights[]=['type'=>'opportunity','message'=>count($gaps).' conversion opportunit'.(count($gaps)===1?'y needs':'ies need').' attention based on current intent and outcome patterns.'];
    if(!$insights)$insights[]=['type'=>'learning','message'=>'Conversion intelligence is active. More Profile traffic and outcomes are needed before VP3 can identify reliable patterns.'];
    return array_slice($insights,0,6);
}

function profile_revenue_intelligence_v180(PDO $pdo,int $ownerUserId): array
{
    $empty=[
        'version'=>VP3_PROFILE_REVENUE_INTELLIGENCE_V180,
        'attribution_model'=>'session_first_touch','periods'=>[],'revenue_all_time'=>[],
        'top_targets'=>[],'sources'=>[],'opportunities'=>[],'insights'=>[],
    ];
    if($ownerUserId<1)return $empty;
    try{
        $nowRow=$pdo->query('SELECT NOW() AS db_now')->fetch();
        $now=new DateTimeImmutable((string)($nowRow['db_now']??'now'));
        $since=$now->modify('-60 days')->format('Y-m-d H:i:s');
        $stmt=$pdo->prepare("SELECT id,profile_session_id,event_type,metadata_json,created_at FROM profile_events WHERE owner_user_id=? AND event_type IN ('profile_view','booking_intent','product_intent','booking_converted','product_converted') AND created_at>=? ORDER BY created_at ASC,id ASC");
        $stmt->execute([$ownerUserId,$since]);
        $rows=$stmt->fetchAll()?:[];
        $sessionSources=[];
        foreach($rows as &$row){
            $metadata=profile_revenue_meta_v180((string)($row['metadata_json']??''));
            $row['_meta']=$metadata;
            $sessionId=max(0,(int)($row['profile_session_id']??0));
            if($sessionId<1)continue;
            $candidate=profile_revenue_source_v180($metadata);
            if(!isset($sessionSources[$sessionId])||($sessionSources[$sessionId]['type']==='direct'&&$candidate['type']!=='direct'))$sessionSources[$sessionId]=$candidate;
        }
        unset($row);

        $todayStart=$now->setTime(0,0,0);$tomorrow=$todayStart->modify('+1 day');
        $periodSpecs=[
            'today'=>[$todayStart,$tomorrow,$todayStart->modify('-1 day'),$todayStart],
            '7d'=>[$now->modify('-7 days'),$now,$now->modify('-14 days'),$now->modify('-7 days')],
            '30d'=>[$now->modify('-30 days'),$now,$now->modify('-60 days'),$now->modify('-30 days')],
        ];
        $periods=[];
        foreach($periodSpecs as $key=>[$currentStart,$currentEnd,$previousStart,$previousEnd]){
            $current=profile_revenue_period_snapshot_v180($rows,$currentStart,$currentEnd,$sessionSources);
            $previous=profile_revenue_period_snapshot_v180($rows,$previousStart,$previousEnd,$sessionSources);
            $periods[$key]=['current'=>$current,'previous'=>$previous,'change'=>profile_revenue_period_comparison_v180($current,$previous)];
        }

        $allRevenue=[];
        $revenueStmt=$pdo->prepare("SELECT metadata_json FROM profile_events WHERE owner_user_id=? AND event_type IN ('booking_converted','product_converted')");
        $revenueStmt->execute([$ownerUserId]);
        while($row=$revenueStmt->fetch()){
            $metadata=profile_revenue_meta_v180((string)($row['metadata_json']??''));
            profile_revenue_money_add_v180($allRevenue,profile_revenue_currency_v180($metadata['currency']??''),max(0,(int)($metadata['value_cents']??0)));
        }

        $start30=$now->modify('-30 days');
        $topTargets=profile_revenue_top_targets_v180($rows,$start30,$now,12);
        $sources=profile_revenue_sources_v180($rows,$start30,$now,$sessionSources,10);
        $opportunities=profile_revenue_opportunities_v180($topTargets,$periods['30d']['current']);
        $insights=profile_revenue_insights_v180($periods,$topTargets,$sources,$opportunities);

        return [
            'version'=>VP3_PROFILE_REVENUE_INTELLIGENCE_V180,
            'attribution_model'=>'session_first_touch',
            'periods'=>$periods,
            'revenue_all_time'=>profile_revenue_money_rows_v180($allRevenue),
            'top_targets'=>$topTargets,
            'sources'=>$sources,
            'opportunities'=>$opportunities,
            'insights'=>$insights,
        ];
    }catch(Throwable $e){
        return $empty;
    }
}
