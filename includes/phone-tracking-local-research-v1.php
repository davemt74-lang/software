<?php
declare(strict_types=1);

require_once __DIR__.'/phone-transactions-v1.php';

/** One private offer can authorize one bounded search. Never replay an uncertain external dispatch. */
function vp3_phone_local_research_schema_v1(PDO $pdo): void
{
    $options = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'
        ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_phone_local_research_v1 (
        offer_id BIGINT UNSIGNED NOT NULL PRIMARY KEY, owner_user_id INT UNSIGNED NOT NULL,
        agent_id INT UNSIGNED NOT NULL, conversation_id BIGINT UNSIGNED NOT NULL, state VARCHAR(16) NOT NULL,
        public_json TEXT NOT NULL, queued_at BIGINT NOT NULL, expires_at BIGINT NOT NULL, claimed_at BIGINT NULL,
        result_json TEXT NULL, delivered_at BIGINT NULL,
        FOREIGN KEY (offer_id) REFERENCES vp3_phone_local_offers_v1(assistant_message_id) ON DELETE CASCADE
    )".$options);
    if ($options === '') {
        $pdo->exec('CREATE INDEX IF NOT EXISTS vp3_local_search_chat ON vp3_phone_local_research_v1(owner_user_id,agent_id,conversation_id,state)');
    } elseif (!($pdo->query("SHOW INDEX FROM vp3_phone_local_research_v1 WHERE Key_name='vp3_local_search_chat'")->fetchColumn())) {
        try {
            $pdo->exec('CREATE INDEX vp3_local_search_chat ON vp3_phone_local_research_v1(owner_user_id,agent_id,conversation_id,state)');
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1]??0)!==1061) throw $e;
        }
    }
}

function vp3_phone_local_research_ready_v1(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT offer_id,owner_user_id,agent_id,conversation_id,state,public_json,queued_at,expires_at,claimed_at,result_json,delivered_at
            FROM vp3_phone_local_research_v1 WHERE 1=0');
        return true;
    } catch (PDOException) {
        return false;
    }
}

/** This exact query is displayed for approval, stored, and sent to the native search transport. */
function vp3_phone_local_public_query_v1(array $public): string
{
    $keys = ['area','category','item','open_today','date'];
    if (count($public)!==count($keys) || array_diff($keys,array_keys($public))) {
        throw new RuntimeException('Review the public area, store category, item and date in this Chat.',422);
    }
    foreach (['category','item'] as $field) {
        if (!is_string($public[$field]) || $public[$field]!==trim($public[$field])
            || !preg_match("~^[\\p{L}\\p{N}][\\p{L}\\p{M}\\p{N} &’'.+\\-]{0,63}$~uD",$public[$field])
            || preg_match('~(?:https?\\b|www\\.|\\b\\d{1,3}\\.\\d+\\b)~iu',$public[$field])) {
            throw new RuntimeException('Use a short public item and store category.',422);
        }
    }
    if (!is_string($public['area']) || $public['area']!==trim($public['area'])
        || !preg_match("~^[\\p{L}][\\p{L}\\p{M} ,.'’\\-]{0,95}$~uD",$public['area'])
        || preg_match('~(?:\\b(?:https?|www)\\b|\\b(?:street|road|avenue|boulevard|drive|lane|suite|unit|apartment)\\s*$)~iu',$public['area'])) {
        throw new RuntimeException('Choose a public city or neighborhood for this search.',422);
    }
    $date = $public['date'];
    if (!is_bool($public['open_today']) || !is_string($date) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D',$date,$m)
        || !checkdate((int)$m[2],(int)$m[3],(int)$m[1])) throw new RuntimeException('Review the search date again.',422);
    return $public['category'].' in '.$public['area'].' selling '.$public['item']
        .($public['open_today'] ? ' open on '.$date : '');
}

function vp3_phone_local_launch_v1(PDO $pdo,array $user,array $principal,int $cid,int $offerId,array $public,int $expiresAt=0): void
{
    if (!vp3_phone_transaction_active_v1($pdo)) throw new LogicException('Local research must be queued in the private approval transaction.');
    vp3_phone_local_public_query_v1($public);
    $public = array_replace(array_fill_keys(['area','item','category','open_today','date'],null),$public);
    $uid = (int)$user['id'];
    $aid = (int)($principal['agent_id']??0);
    $scope = [$offerId,$uid,$aid,$cid];
    $exists = vp3_phone_sql_v1($pdo,'SELECT 1 FROM vp3_phone_local_offers_v1 o JOIN vp3_phone_chat_turns_v1 t
        ON t.assistant_message_id=o.assistant_message_id WHERE o.assistant_message_id=? AND t.owner_user_id=? AND t.agent_id=? AND t.conversation_id=?',$scope);
    if (!$exists->fetchColumn()) throw new RuntimeException('Choose the local research offer in this private Chat.',403);
    $json = json_encode($public,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    $existing = vp3_phone_sql_v1($pdo,'SELECT public_json FROM vp3_phone_local_research_v1 WHERE offer_id=?',[$offerId])->fetchColumn();
    if ($existing!==false) {
        if ($existing!==$json) throw new RuntimeException('This approved search has changed.',409);
        return;
    }
    vp3_phone_sql_v1($pdo,"INSERT INTO vp3_phone_local_research_v1 (offer_id,owner_user_id,agent_id,conversation_id,state,public_json,queued_at,expires_at)
        VALUES(?,?,?,?,'queued',?,?,?)",[...$scope,$json,time(),$expiresAt===0 ? time()+900 : min(time()+900,$expiresAt)]);
}

function vp3_phone_local_research_pending_v1(PDO $pdo,int $uid,int $aid,int $cid): bool
{
    if ($cid<1) return false;
    return (bool)vp3_phone_sql_v1($pdo,"SELECT 1 FROM vp3_phone_local_research_v1 WHERE owner_user_id=? AND agent_id=? AND conversation_id=?
        AND (state IN ('queued','complete') OR (state='running' AND claimed_at<=?)) LIMIT 1",[$uid,$aid,$cid,time()-90])->fetchColumn();
}

final class VP3PhoneLocalResearchWithdrawn extends RuntimeException {}
final class VP3PhoneLocalResearchExpired extends RuntimeException {}

/** Called by the private activity poll, before it begins its delivery transaction. */
function vp3_phone_local_research_work_v1(PDO $pdo,array $user,array $principal,int $cid,callable $authority,?array $transport=null): void
{
    if ($cid<1) return;
    if (vp3_phone_transaction_active_v1($pdo)) throw new LogicException('Local research provider reads must run outside a transaction.');
    $scope = [(int)$user['id'],(int)($principal['agent_id']??0),$cid];
    $bind = 'owner_user_id=? AND agent_id=? AND conversation_id=?';
    $row = vp3_phone_sql_v1($pdo,"SELECT offer_id,state,public_json,queued_at,expires_at FROM vp3_phone_local_research_v1 WHERE $bind
        AND (state='queued' OR (state='running' AND claimed_at<=?)) ORDER BY queued_at,offer_id LIMIT 1",[...$scope,time()-90])->fetch(PDO::FETCH_ASSOC);
    if (!$row) return;
    $offer = (int)$row['offer_id'];
    $guard = static function () use ($authority,$offer): void {
        if (!$authority($offer)) throw new VP3PhoneLocalResearchWithdrawn();
    };
    $where = "offer_id=? AND $bind";
    $ids = [$offer,...$scope];
    $empty = static fn(string $answer): array => ['answer'=>$answer,'sources'=>[]];
    try {
        $guard();
        if ($row['state']==='running') {
            $report = $empty('This public search was interrupted. Its external request could not be confirmed.');
        } else {
            $claimed = vp3_phone_sql_v1($pdo,"UPDATE vp3_phone_local_research_v1 SET state='running',claimed_at=? WHERE $where AND state='queued'",[time(),...$ids]);
            if ($claimed->rowCount()!==1) return;
            $dispatch = static function() use ($guard,$row): void {
                $guard();
                if (time()>=(int)$row['expires_at']) throw new VP3PhoneLocalResearchExpired();
            };
            $dispatch();
            $report = vp3_phone_local_collect_v1(json_decode($row['public_json'],true,16,JSON_THROW_ON_ERROR),$dispatch,$transport);
        }
        $guard();
    } catch (VP3PhoneLocalResearchExpired $e) {
        $report = $empty('This search approval expired. Choose a new search date and area.');
    } catch (VP3PhoneLocalResearchWithdrawn $e) {
        vp3_phone_sql_v1($pdo,"UPDATE vp3_phone_local_research_v1 SET state='withdrawn',public_json='{}',result_json=NULL WHERE $where",$ids);
        return;
    } catch (Throwable $e) {
        if ($e instanceof PDOException || $e instanceof LogicException) throw $e;
        if ((int)$e->getCode()===403 || (int)$e->getCode()===404) {
            vp3_phone_sql_v1($pdo,"UPDATE vp3_phone_local_research_v1 SET state='withdrawn',public_json='{}',result_json=NULL WHERE $where",$ids);
            return;
        }
        $report = $empty('Public store research is unavailable. No store hours or stock were verified.');
    }
    vp3_phone_sql_v1($pdo,"UPDATE vp3_phone_local_research_v1 SET state='complete',result_json=? WHERE $where AND state='running'",
        [json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),...$ids]);
}

/** Runs inside the poll transaction. A rolled-back Chat insert makes the completed result eligible again. */
function vp3_phone_local_research_results_v1(PDO $pdo,array $user,array $principal,int $cid,?callable $authority=null): array
{
    if (!vp3_phone_transaction_active_v1($pdo)) throw new LogicException('Publish research in the private Chat delivery transaction.');
    $scope = [(int)$user['id'],(int)($principal['agent_id']??0),$cid];
    $rows = vp3_phone_sql_v1($pdo,"SELECT offer_id,result_json FROM vp3_phone_local_research_v1 WHERE owner_user_id=? AND agent_id=?
        AND conversation_id=? AND state='complete' ORDER BY offer_id LIMIT 4".vp3_phone_lock_v1($pdo),$scope)->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $row) {
        $offer = (int)$row['offer_id'];
        if (!$authority || !$authority($offer)) {
            vp3_phone_sql_v1($pdo,"UPDATE vp3_phone_local_research_v1 SET state='withdrawn',public_json='{}',result_json=NULL WHERE offer_id=?",[$offer]);
            continue;
        }
        $result=json_decode($row['result_json'],true,32,JSON_THROW_ON_ERROR);
        vp3_phone_sql_v1($pdo,"UPDATE vp3_phone_local_research_v1 SET state='delivered',delivered_at=? WHERE offer_id=?",[time(),$offer]);
        $out[] = ['offer_id'=>$offer,'answer'=>$result['answer'],'sources'=>$result['sources']];
    }
    return $out;
}

function vp3_phone_local_text_v1(mixed $value,int $limit=160): string
{
    if (!is_string($value) || mb_strlen($value)>$limit || preg_match('/[\p{C}<>\[\]]|https?:\/\//iu',$value)) return '';
    return trim(preg_replace('/\s+/u',' ',$value) ?? '');
}

function vp3_phone_local_key_v1(mixed $value): string
{
    return is_string($value)?trim(preg_replace('/[^\p{L}\p{N}]+/u',' ',mb_strtolower($value)) ?? ''):'';
}

function vp3_phone_local_values_v1(mixed $value): array
{
    if ($value===null || $value===[]) return [];
    return is_array($value) && array_is_list($value) ? $value : [$value];
}

function vp3_phone_local_type_v1(mixed $type): string
{
    return is_string($type) ? preg_replace('~^https?://schema\.org/~i','',$type) : '';
}

function vp3_phone_local_node_v1(mixed $value,array $index): array
{
    if (!is_array($value)) return is_string($value) ? ($index[$value]??[]) : [];
    $id=$value['@id']??null;
    return is_string($id) && isset($index[$id]) ? $value+$index[$id] : $value;
}

/** Resolve US postal abbreviations only in an address that can belong to the US. */
function vp3_phone_local_us_region_v1(string $value): string
{
    static $names=[
        'al'=>'alabama','ak'=>'alaska','az'=>'arizona','ar'=>'arkansas','ca'=>'california','co'=>'colorado','ct'=>'connecticut',
        'de'=>'delaware','dc'=>'district of columbia','fl'=>'florida','ga'=>'georgia','hi'=>'hawaii','id'=>'idaho',
        'il'=>'illinois','in'=>'indiana','ia'=>'iowa','ks'=>'kansas','ky'=>'kentucky','la'=>'louisiana','me'=>'maine',
        'md'=>'maryland','ma'=>'massachusetts','mi'=>'michigan','mn'=>'minnesota','ms'=>'mississippi','mo'=>'missouri',
        'mt'=>'montana','ne'=>'nebraska','nv'=>'nevada','nh'=>'new hampshire','nj'=>'new jersey','nm'=>'new mexico',
        'ny'=>'new york','nc'=>'north carolina','nd'=>'north dakota','oh'=>'ohio','ok'=>'oklahoma','or'=>'oregon',
        'pa'=>'pennsylvania','ri'=>'rhode island','sc'=>'south carolina','sd'=>'south dakota','tn'=>'tennessee',
        'tx'=>'texas','ut'=>'utah','vt'=>'vermont','va'=>'virginia','wa'=>'washington','wv'=>'west virginia',
        'wi'=>'wisconsin','wy'=>'wyoming','pr'=>'puerto rico'
    ];
    return isset($names[$value])?$value:(array_search($value,$names,true)?:'');
}

/** Match complete locality components. An address in West Phoenix is not evidence for Phoenix. */
function vp3_phone_local_area_matches_v1(string $area,array $node,array $index): bool
{
    $parts=array_map('vp3_phone_local_key_v1',explode(',',$area));
    $address=vp3_phone_local_node_v1($node['address']??null,$index);
    $city=vp3_phone_local_key_v1($address['addressLocality']??null);
    $region=vp3_phone_local_key_v1($address['addressRegion']??null);
    $country=$address['addressCountry']??null;
    if (is_array($country)) $country=$country['name']??null;
    $neighborhood=vp3_phone_local_key_v1($address['addressNeighborhood']??null);
    $district=vp3_phone_local_node_v1($node['containedInPlace']??null,$index);
    if (!$neighborhood && in_array(vp3_phone_local_type_v1($district['@type']??null),['Neighborhood','Place'],true)) {
        $neighborhood=vp3_phone_local_key_v1($district['name']??null);
    }
    $localities=array_filter([$city,$neighborhood,$city&&$neighborhood?$neighborhood.' '.$city:'']);
    if (!$localities || !in_array(array_shift($parts),$localities,true)) return false;
    $country=vp3_phone_local_key_v1($country);
    $us=['us','usa','united states','united states of america'];
    $isUs=in_array($country,$us,true);
    $state=$isUs||$country===''?vp3_phone_local_us_region_v1($region):'';
    $known=array_filter([$city,$region,$country]);
    foreach ($parts as $part) {
        if (!$isUs && $country!=='' && $part===$region && vp3_phone_local_us_region_v1($part)!=='' && !in_array($country,$parts,true)) return false;
        if (in_array($part,$known,true) || ($isUs && in_array($part,$us,true))) continue;
        if ($state!=='' && vp3_phone_local_us_region_v1($part)===$state) continue;
        return false;
    }
    return true;
}

function vp3_phone_local_store_kind_v1(array $node,array $public): bool
{
    $types=array_map('vp3_phone_local_type_v1',vp3_phone_local_values_v1($node['@type']??null));
    foreach (vp3_phone_local_values_v1($node['additionalType']??null) as $extra) {
        $type=vp3_phone_local_type_v1($extra);
        if (preg_match('/^[A-Za-z]+Store$/D',$type)) $types[]=$type;
    }
    $storeTypes=array_filter($types,static fn($type)=>str_ends_with($type,'Store'));
    if (!$storeTypes && !in_array('LocalBusiness',$types,true)) return false;
    $category=vp3_phone_local_key_v1($public['category']);
    $terms=array_filter(explode(' ',$category),static fn($w)=>!in_array($w,['store','stores','shop','shops','retail','retailers','local','nearby','supply','supplies','and'],true));
    $patterns=[];
    foreach ($terms as $term) {
        if (in_array($term,['music','musical','instrument','instruments'],true)) {
            $patterns['music']='(?:music|musical instruments?|instruments?)';
        } else {
            $patterns[$term]=preg_quote(rtrim($term,'s'),'/').'s?';
        }
    }
    $wanted=implode('\s+',$patterns);
    $name=vp3_phone_local_key_v1($node['name']??null);
    $descriptor=vp3_phone_local_key_v1(is_string($node['description']??null)?mb_substr($node['description'],0,600):'');
    $categories=[];
    foreach (array_slice(vp3_phone_local_values_v1($node['category']??null),0,8) as $entry) {
        $categories[]=vp3_phone_local_key_v1(is_array($entry)?($entry['name']??null):$entry);
    }
    if ($storeTypes) {
        $typeWords=array_map(static fn($type)=>vp3_phone_local_key_v1(preg_replace('/([a-z])([A-Z])/','$1 $2',$type)),$storeTypes);
        if ($wanted==='') return true;
        foreach ([...$typeWords,$name,...$categories] as $label) if (preg_match('/\b'.$wanted.'\b/u',$label)) return true;
    }
    // A pub may advertise live music. Match an actual merchant designation in the same field.
    $designation='(?:stores?|shops?|retailers?|dealers?)';
    $match=$wanted===''?$designation:$wanted.'\s+'.$designation;
    foreach ([$name,...$categories,$descriptor] as $field) {
        if (preg_match('/\b'.$match.'\b/u',$field)) return true;
    }
    return false;
}

function vp3_phone_local_day_matches_v1(mixed $days,string $weekday): bool
{
    foreach (vp3_phone_local_values_v1($days) as $day) {
        if (strtolower(vp3_phone_local_type_v1($day))===strtolower($weekday)) return true;
    }
    return false;
}

function vp3_phone_local_hours_v1(array $node,array $index,string $date): array
{
    $weekday=(new DateTimeImmutable($date,new DateTimeZone('UTC')))->format('l');
    $qualify=static function (array $spec) use ($date): bool {
        foreach (['validFrom','validThrough'] as $key) {
            if (!isset($spec[$key])) continue;
            if (!is_string($spec[$key]) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D',$spec[$key])) return false;
            if ($key==='validFrom' ? $date<$spec[$key] : $date>$spec[$key]) return false;
        }
        return true;
    };
    $matched=false;
    foreach (['specialOpeningHoursSpecification','openingHoursSpecification'] as $key) {
        $special=$key==='specialOpeningHoursSpecification';
        $matched=false;
        $closed=false;
        $periods=[];
        foreach (vp3_phone_local_values_v1($node[$key]??null) as $raw) {
            $spec=vp3_phone_local_node_v1($raw,$index);
            if (!$qualify($spec) || ($special && !isset($spec['validFrom'],$spec['validThrough']))) continue;
            if ((!$special || isset($spec['dayOfWeek'])) && !vp3_phone_local_day_matches_v1($spec['dayOfWeek']??null,$weekday)) continue;
            $matched=true;
            $times=[];
            foreach (['opens','closes'] as $field) {
                $time=$spec[$field]??null;
                if (is_string($time) && preg_match('/^((?:[01]\d|2[0-3]):[0-5]\d|24:00)(?::00)?$/D',$time,$m)) $times[]=$m[1];
            }
            if (count($times)!==2) continue;
            if ($times===['00:00','00:00']) $closed=true;
            elseif ($times[0]!==$times[1]) $periods[]=implode('–',$times);
        }
        if ($periods && !$closed) return ['closed'=>false,'detail'=>'Posted '.$weekday.' hours for '.$date.': '.implode(', ',array_unique($periods))
            .' local time.'.($special?'':' Date-specific exceptions unverified.')];
        if ($closed && !$periods) return ['closed'=>true,'detail'=>'Listed closed on '.$date.'.'];
        if ($matched) break;
    }
    if (!$matched) {
        // The compact schema.org form uses Mo,Tu or Mo-Fr followed by 24-hour times.
        $days=['Mo','Tu','We','Th','Fr','Sa','Su'];
        $today=(int)(new DateTimeImmutable($date,new DateTimeZone('UTC')))->format('N')-1;
        $periods=[];
        $closed=false;
        foreach (vp3_phone_local_values_v1($node['openingHours']??null) as $rule) {
            if (!is_string($rule) || !preg_match('/^((?:Mo|Tu|We|Th|Fr|Sa|Su)(?:[,-](?:Mo|Tu|We|Th|Fr|Sa|Su))*)\s+((?:[01]\d|2[0-3]):[0-5]\d)-((?:[01]\d|2[0-3]):[0-5]\d|24:00)$/D',$rule,$m)) continue;
            foreach (explode(',',$m[1]) as $range) {
                $edges=explode('-',$range);
                if (count($edges)>2) continue;
                $a=array_search($edges[0],$days,true);
                $b=array_search(end($edges),$days,true);
                if ((($today-$a+7)%7)>(($b-$a+7)%7)) continue;
                if ($m[2]==='00:00' && $m[3]==='00:00') $closed=true;
                elseif ($m[2]!==$m[3]) $periods[]=$m[2].'–'.$m[3];
            }
        }
        if ($closed && !$periods) return ['closed'=>true,'detail'=>'Listed closed on '.$date.'.'];
        if ($periods && !$closed) return ['closed'=>false,'detail'=>'Posted '.$weekday.' hours for '.$date.': '.implode(', ',array_unique($periods)).' local time. Date-specific exceptions unverified.'];
    }
    return ['closed'=>false,'detail'=>'Opening hours for '.$date.' unverified.'];
}

function vp3_phone_local_stock_v1(array $store,array $index,string $item,string $date): string
{
    $storeId=$store['@id']??null;
    $itemKey=vp3_phone_local_key_v1($item);
    $words=explode(' ',$itemKey);
    $last=array_pop($words);
    $singular=preg_match('/(?<![su])s$/u',$last)?mb_substr($last,0,-1):$last;
    $terms=preg_quote(implode(' ',[...$words,$singular]),'/');
    $productMatch='/\b'.$terms.'s?\b/u';
    $offers=[];
    foreach (vp3_phone_local_values_v1($store['makesOffer']??null) as $offer) $offers[]=$offer;
    foreach (vp3_phone_local_values_v1($store['hasOfferCatalog']??null) as $catalog) {
        $catalog=vp3_phone_local_node_v1($catalog,$index);
        foreach (vp3_phone_local_values_v1($catalog['itemListElement']??null) as $entry) {
            $entry=vp3_phone_local_node_v1($entry,$index);
            $offers[]=$entry['item']??$entry;
        }
    }
    $out=false;
    foreach (array_slice($offers,0,80) as $raw) {
        $offer=vp3_phone_local_node_v1($raw,$index);
        $product=vp3_phone_local_node_v1($offer['itemOffered']??null,$index);
        $productName=vp3_phone_local_key_v1($product['name']??null);
        if (!preg_match($productMatch,$productName,$m,PREG_OFFSET_CAPTURE)) continue;
        $prefix=trim(substr($productName,0,$m[0][1]));
        $suffix=trim(substr($productName,$m[0][1]+strlen($m[0][0])));
        if (preg_match('/\b(?:for|compatible with|case|bag|tester|organizer|holder|accessory|adapter|adaptor)\b/u',$prefix)) continue;
        if ($suffix!=='') {
            $attribute='(?:\d+(?:\s*(?:ft|feet|foot|in|inch|inches|m|metres?|meters?|cm|mm|pack|pk|pcs))?|pack|set|black|white|male to female)';
            if (!preg_match('/^'.$attribute.'(?:\s+'.$attribute.')*$/uD',$suffix)) continue;
        }
        $valid=true;
        foreach (['validFrom','validThrough','availabilityStarts','availabilityEnds'] as $field) {
            if (!isset($offer[$field])) continue;
            $stamp=$offer[$field];
            if (!is_string($stamp) || !preg_match('/^(\d{4}-\d{2}-\d{2})(?:T|$)/D',$stamp,$d)) { $valid=false; break; }
            $start=$field==='validFrom'||$field==='availabilityStarts';
            // At a same-day precise boundary, avoid claiming current stock without a verified local clock.
            if ($start?$d[1]>$date:$d[1]<$date) $valid=false;
            if ($d[1]===$date && strlen($stamp)>10) $valid=false;
        }
        if (!$valid) continue;
        $branches=vp3_phone_local_values_v1($offer['availableAtOrFrom']??null);
        $branchMatch=false;
        foreach ($branches as $branch) {
            $id=is_array($branch)?($branch['@id']??null):$branch;
            if (is_string($storeId) && $storeId!=='' && $id===$storeId) $branchMatch=true;
        }
        $pickup=array_map('vp3_phone_local_type_v1',vp3_phone_local_values_v1($offer['availableDeliveryMethod']??null));
        $localOnly=in_array(vp3_phone_local_type_v1($offer['availability']??null),['InStoreOnly'],true);
        if (!$branchMatch && ($branches || (!$localOnly && !in_array('OnSitePickup',$pickup,true)))) continue;
        $availability=vp3_phone_local_type_v1($offer['availability']??null);
        if ($availability==='InStock') return 'Reported in stock at this branch.';
        if ($availability==='OutOfStock'||$availability==='SoldOut') $out=true;
    }
    return $out?'Reported out of stock at this branch.':'Local stock unverified.';
}

/** Read public Store/LocalBusiness facts, including references within a JSON-LD graph. */
function vp3_phone_local_page_v1(array $response,array $public): array
{
    $mime=strtolower(trim(explode(';',(string)($response['content_type']??''))[0]));
    if (($response['status']??0)!==200 || !in_array($mime,['text/html','application/xhtml+xml'],true)
        || !is_string($response['body']??null) || strlen($response['body'])>512000 || $response['body']==='') return [];
    $dom=new DOMDocument();
    $old=libxml_use_internal_errors(true);
    try { $ok=$dom->loadHTML('<?xml encoding="utf-8" ?>'.$response['body'],LIBXML_NONET); }
    finally { libxml_clear_errors(); libxml_use_internal_errors($old); }
    if (!$ok) return [];
    $xp=new DOMXPath($dom);
    if (preg_match('/captcha|access denied|just a moment|verify you are human/i',(string)$xp->evaluate('string(//title)'))) return [];
    $nodes=[];
    $index=[];
    $append=static function(mixed $raw,int $depth=0) use (&$append,&$nodes,&$index): void {
        if (!is_array($raw) || $depth>5 || count($nodes)>=220) return;
        if (array_is_list($raw)) {
            foreach (array_slice($raw,0,220) as $child) $append($child,$depth+1);
            return;
        }
        if (isset($raw['@type']) || isset($raw['@id'])) {
            $nodes[]=$raw;
            if (is_string($raw['@id']??null)) $index[$raw['@id']]=isset($index[$raw['@id']])?$raw+$index[$raw['@id']]:$raw;
        }
        if (isset($raw['@graph'])) $append($raw['@graph'],$depth+1);
        if (isset($raw['mainEntity'])) $append($raw['mainEntity'],$depth+1);
    };
    foreach ($xp->query('//script[@type="application/ld+json"]') ?: [] as $script) {
        $append(json_decode((string)$script->textContent,true,48));
    }
    // Many merchant sites publish the same schema.org data via HTML microdata.
    foreach ($xp->query('//*[@itemscope and @itemtype]') ?: [] as $scope) {
        if (count($nodes)>=220) break;
        $type=vp3_phone_local_type_v1($scope->getAttribute('itemtype'));
        if (!str_ends_with($type,'Store') && $type!=='LocalBusiness') continue;
        $attr=static function(string $field) use($xp,$scope): string {
            foreach ($xp->query('.//*[@itemprop="'.$field.'"]',$scope) ?: [] as $tag) {
                $parent=$xp->query('ancestor::*[@itemscope][1]',$tag)->item(0);
                if (!$parent) continue;
                if (!$parent->isSameNode($scope)) {
                    if (!str_starts_with($field,'address') || $parent->getAttribute('itemprop')!=='address') continue;
                    $grand=$xp->query('ancestor::*[@itemscope][1]',$parent)->item(0);
                    if (!$grand || !$grand->isSameNode($scope)) continue;
                }
                return trim($tag->getAttribute('content')?:$tag->textContent);
            }
            return '';
        };
        $nodes[]=['@type'=>$type,'name'=>$attr('name'),'description'=>$attr('description'),
            'address'=>['addressLocality'=>$attr('addressLocality'),'addressRegion'=>$attr('addressRegion'),'addressCountry'=>$attr('addressCountry')]];
    }
    $stores=[];
    $closed=[];
    foreach ($nodes as $node) {
        $name=vp3_phone_local_text_v1($node['name']??null,140);
        if ($name==='' || !vp3_phone_local_store_kind_v1($node,$public) || !vp3_phone_local_area_matches_v1($public['area'],$node,$index)) continue;
        $hours=vp3_phone_local_hours_v1($node,$index,$public['date']);
        $key=vp3_phone_local_key_v1($name);
        if ($public['open_today'] && $hours['closed']) { $closed[$key]=true; continue; }
        $stores[$key]??=['name'=>$name,'hours'=>$hours['detail'],'stock'=>vp3_phone_local_stock_v1($node,$index,$public['item'],$public['date'])];
    }
    return array_slice(array_diff_key($stores,$closed),0,3,true);
}

/** Search and read through VP3's existing bounded public transports. Only the reviewed query leaves in discovery. */
function vp3_phone_local_collect_v1(array $public,callable $guard,?array $transport=null): array
{
    require_once __DIR__.'/cloud-mission-web-v1.php';
    $query=vp3_phone_local_public_query_v1($public);
    $guard();
    $report=static fn(string $answer):array=>['answer'=>$answer,'sources'=>[]];
    if (!class_exists('DOMDocument') || (!$transport && !cloud_mission_web_ready_v1())) {
        return $report('Public store research is unavailable. No hours or stock were verified.');
    }
    $until=microtime(true)+10;
    try {
        $search=$transport['search']??'booking_agent_web_search_response';
        $found=$search($query,6,3);
    } catch (Throwable $e) { $found=[]; }
    $guard();
    if (($found['ok']??false)!==true || !is_array($found['results']??null)) return $report('The public search provider was unavailable. No store hours or stock were verified.');
    $stores=[];
    $links=[];
    $visited=[];
    $reads=0;
    $fetch=$transport['request']??'cloud_mission_web_request_v1';
    foreach (array_slice($found['results'],0,6) as $hit) {
        if (count($stores)>=3 || $reads>=4 || microtime(true)>=$until-1) break;
        if (!is_array($hit) || !is_string($hit['url']??null)) continue;
        try {
            $url=cloud_mission_web_identity_v1(cloud_mission_web_url_v1($hit['url']));
            if (isset($visited[$url])) continue;
            $host=(string)parse_url($url,PHP_URL_HOST);
            for ($redirect=0;$redirect<2;++$redirect) {
                $guard();
                if ($reads>=4 || microtime(true)>=$until-1 || isset($visited[$url])) break;
                $visited[$url]=true;
                ++$reads;
                $seconds=min(3,max(1,(int)floor($until-microtime(true))));
                $response=$fetch($url,[$host],$guard,null,$seconds);
                $guard();
                if (in_array($response['status']??0,[301,302,303,307,308],true) && $redirect===0) {
                    $url=cloud_mission_web_redirect_v1($url,(string)($response['location']??''),[$host]);
                    continue;
                }
                foreach (vp3_phone_local_page_v1($response,$public) as $key=>$store) {
                    if (isset($stores[$key])) continue;
                    if (!isset($links[$url])) $links[$url]=['title'=>$store['name'].' — store location and published details','url'=>$url,'coverage'=>'page_excerpt'];
                    $ref=array_search($url,array_keys($links),true)+1;
                    $stores[$key]=$store+['ref'=>$ref];
                    if (count($stores)===3) break;
                }
                break;
            }
        } catch (VP3PhoneLocalResearchWithdrawn|VP3PhoneLocalResearchExpired $e) { throw $e; }
        catch (Throwable $e) { $guard(); }
    }
    $guard();
    if (!$stores) return $report('I could not verify a matching local store from the public pages I checked. Opening hours and branch stock remain unverified.');
    $answer='Local store research for '.$public['area'].' ('.$public['date'].').';
    foreach ($stores as $store) {
        $ref=' ['.$store['ref'].']';
        $answer.="\n\n".$store['name'].$ref."\n".$store['hours'].$ref."\n".$public['item'].': '.$store['stock'].$ref;
    }
    return ['answer'=>$answer,'sources'=>array_values($links)];
}
