<?php
declare(strict_types=1);

require_once __DIR__.'/phone-transactions-v1.php';

require_once __DIR__.'/phone-tracking-local-research-v1.php';

/** One approved public query belongs to one visitor event in one owner's private Chat. */
function vp3_phone_local_approval_schema_v1(PDO $pdo): void
{
    $options=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'
        ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_phone_local_approvals_v1 (
        offer_id BIGINT UNSIGNED NOT NULL PRIMARY KEY, preview_token CHAR(12) NOT NULL UNIQUE,
        state VARCHAR(16) NOT NULL, public_json TEXT NOT NULL, expires_at BIGINT NOT NULL,
        result_message_id BIGINT UNSIGNED NULL UNIQUE, sources_json TEXT NULL,
        FOREIGN KEY (offer_id) REFERENCES vp3_phone_local_offers_v1(assistant_message_id) ON DELETE CASCADE,
        FOREIGN KEY (result_message_id) REFERENCES vp3_phone_chat_turns_v1(assistant_message_id) ON DELETE SET NULL
    )".$options);
    vp3_phone_local_research_schema_v1($pdo);
}

function vp3_phone_local_approval_plan_v1(string $text): ?array
{
    if (preg_match('/^search area for offer\s+#([1-9]\d{0,17})\s*:\s*(.*)$/iuD',$text,$m)) {
        return ['action'=>'local_area','offer'=>(int)$m[1],'area'=>trim($m[2])];
    }
    if (preg_match('/^search area\s*:\s*(.*)$/iuD',$text,$m)) {
        return ['action'=>'local_area','offer'=>null,'area'=>trim($m[1])];
    }
    if (preg_match('/^search in (.+)$/iuD',$text,$m)) {
        return ['action'=>'local_area','offer'=>null,'area'=>trim($m[1]),'implicit_local'=>true];
    }
    if (preg_match('/^(approve|cancel) (?:the )?local search(?:\s+#?([a-z0-9-]+))?$/iuD',$text,$m)) {
        return ['action'=>strtolower($m[1])==='approve'?'local_approve':'local_cancel','token'=>strtoupper($m[2]??'')];
    }
    return null;
}

/** Broad geography is typed independently. Numbers, street addresses, URLs and multiline free text are inadmissible. */
function vp3_phone_local_area_v1(string $value): ?string
{
    if (!mb_check_encoding($value,'UTF-8') || mb_strlen($value)>96 || preg_match('/[\p{C}]/u',$value)) return null;
    $value=trim(preg_replace('/\h+/u',' ',$value)??'');
    $word="[\p{L}][\p{L}\p{M}’'.-]*";
    if (!preg_match('/^'.$word.'(?: '.$word.'){0,5}(?:, ?'.$word.'(?: '.$word.'){0,4}){0,2}$/uD',$value)) return null;
    if (preg_match('/\b(?:street|avenue|boulevard|road|lane|drive|court|circle|highway|parkway|apartment|suite|unit|floor|intersection|corner|near|behind|between|address|latitude|longitude|gps|http|https|www)\b/iu',$value)
        || preg_match('/(?:^| )\p{L}[\p{L}\p{M}’.-]*(?: \p{L}[\p{L}\p{M}’.-]*)* (?:st|ave|blvd|rd|ln|dr|ct|pkwy|hwy|cir|way|ter)\.?(?:,|$)/iu',$value)
        || preg_match('/\p{L}\.\p{L}{2,}/u',$value)) return null;
    return $value;
}

/** A public field must not reuse private phone/place labels. Geography must also be independent of the task. */
function vp3_phone_local_private_field_v1(PDO $pdo,int $uid,int $aid,int $cid,int $offerId,array $public): ?string
{
    $row=vp3_phone_sql_v1($pdo,'SELECT d.device_name,p.name AS place_name,b.task_ids_json
        FROM vp3_phone_local_offers_v1 o JOIN vp3_phone_arrival_briefings_v1 b ON b.assistant_message_id=o.assistant_message_id
        JOIN vp3_phone_arrival_rules_v1 r ON r.id=o.rule_id
        JOIN vp3_phone_devices_v1 d ON d.owner_user_id=r.owner_user_id AND d.id=r.device_id
        JOIN vp3_phone_places_v1 p ON p.owner_user_id=r.owner_user_id AND p.id=r.place_id
        WHERE o.assistant_message_id=? AND r.owner_user_id=? AND r.agent_id=? AND r.conversation_id=?',
        [$offerId,$uid,$aid,$cid])->fetch(PDO::FETCH_ASSOC);
    if (!$row) return 'offer';
    $words=static fn($text)=>trim(preg_replace('/[^\p{L}\p{N}]+/u',' ',mb_strtolower((string)$text))??'');
    foreach (['area','item','category'] as $field) {
        $candidate=' '.$words($public[$field]).' ';
        foreach ([$row['device_name'],$row['place_name']] as $label) {
            $private=$words($label);
            if ($private!=='' && str_contains($candidate,' '.$private.' ')) return $field;
        }
    }
    $area=' '.$words($public['area']).' ';
    $ids=array_filter(json_decode($row['task_ids_json']??'[]',true,32,JSON_THROW_ON_ERROR)??[],static fn($id)=>is_int($id)&&$id>0);
    if ($ids) {
        $marks=implode(',',array_fill(0,count($ids),'?'));
        $tasks=vp3_phone_sql_v1($pdo,"SELECT subject,memory_text FROM agent_memory_items WHERE user_id=? AND id IN ($marks)",[$uid,...$ids]);
        foreach ($tasks->fetchAll(PDO::FETCH_ASSOC) as $task) {
            foreach ([$task['subject'],$task['memory_text']] as $label) {
                $private=$words($label);
                if ($private!=='' && str_contains($area,' '.$private.' ')) return 'area';
            }
        }
    }
    return null;
}

function vp3_phone_local_offer_consumed_v1(PDO $pdo,int $offerId): bool
{
    return (bool)vp3_phone_sql_v1($pdo,"SELECT 1 FROM vp3_phone_local_approvals_v1
        WHERE offer_id=? AND state IN ('approved','delivered','cancelled')",[$offerId])->fetchColumn();
}

/** Check the original Chat, active phone grant, visit and current native task before any public work. */
function vp3_phone_local_authorized_offer_v1(PDO $pdo,array $user,array $principal,int $cid,int $offerId): bool
{
    $json=vp3_phone_sql_v1($pdo,"SELECT a.public_json FROM vp3_phone_local_approvals_v1 a
        JOIN vp3_phone_chat_turns_v1 t ON t.assistant_message_id=a.offer_id
        WHERE a.offer_id=? AND a.state='approved' AND t.owner_user_id=? AND t.agent_id=? AND t.conversation_id=?",
        [$offerId,(int)$user['id'],(int)($principal['agent_id']??0),$cid])->fetchColumn();
    if ($json===false) return false;
    $offer=vp3_phone_local_offer_v1($pdo,$user,$principal,$cid,$offerId);
    if (!$offer) return false;
    $public=json_decode($json,true,16,JSON_THROW_ON_ERROR);
    return $public['item']===$offer['item_term'] && $public['category']===$offer['merchant_category']
        && $public['open_today']===(bool)$offer['open_today']
        && vp3_phone_local_private_field_v1($pdo,(int)$user['id'],(int)$principal['agent_id'],$cid,$offerId,$public)===null;
}

/** Gate the generic “Search in…” shortcut; ordinary research commands continue with the normal dispatcher. */
function vp3_phone_local_has_offer_v1(PDO $pdo,int $uid,int $aid,int $cid): bool
{
    return (bool)vp3_phone_sql_v1($pdo,"SELECT 1 FROM vp3_phone_chat_turns_v1 t
        JOIN vp3_phone_local_offers_v1 o ON o.assistant_message_id=t.assistant_message_id
        LEFT JOIN vp3_phone_local_approvals_v1 a ON a.offer_id=o.assistant_message_id
        WHERE t.owner_user_id=? AND t.agent_id=? AND t.conversation_id=? AND o.created_at>?
        AND (a.offer_id IS NULL OR a.state='preview') LIMIT 1",[$uid,$aid,$cid,time()-86400])->fetchColumn();
}

function vp3_phone_local_date_v1(array $input): DateTimeImmutable
{
    $name=$input['reminder_timezone']??null;
    $zone=new DateTimeZone(date_default_timezone_get());
    if (is_string($name) && strlen($name)<=64 && in_array($name,DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC),true)) {
        $zone=new DateTimeZone($name);
    }
    return new DateTimeImmutable('now',$zone);
}

function vp3_phone_local_approval_turn_v1(PDO $pdo,array $user,array $principal,int $cid,array $plan,array $input): array
{
    if (!vp3_phone_transaction_active_v1($pdo)) throw new LogicException('Local search approval requires the private Chat transaction.');
    $uid=(int)$user['id'];
    $aid=(int)($principal['agent_id']??0);
    if ($plan['action']==='local_area') {
        $area=vp3_phone_local_area_v1($plan['area']);
        if ($area===null) return ['Use a city or neighborhood, such as Phoenix, Arizona. Leave out street addresses and coordinates.',''];
        $offer=vp3_phone_local_offer_v1($pdo,$user,$principal,$cid,$plan['offer']);
        if (!$offer) return [$plan['offer']===null
            ? 'Choose a current arrival offer in this private Chat. Reply “Search area for offer #” with its number, a colon, and your city or neighborhood.'
            : 'That offer is no longer available in this private Chat. A current visit and an open matching task are required.',''];
        $id=(int)$offer['id'];
        if (vp3_phone_local_offer_consumed_v1($pdo,$id)) return ['This visit’s local search was already approved or cancelled.',''];
        $date=vp3_phone_local_date_v1($input);
        $public=['area'=>$area,'item'=>$offer['item_term'],'category'=>$offer['merchant_category'],
            'open_today'=>(bool)$offer['open_today'],'date'=>$date->format('Y-m-d')];
        $private=vp3_phone_local_private_field_v1($pdo,$uid,$aid,$cid,$id,$public);
        if ($private!==null) return [$private==='area'
            ? 'Enter a public city or neighborhood separately from your saved place, phone name and private task.'
            : 'Update the rule with a public item and store category that omit private phone and place names.',''];
        try { $query=vp3_phone_local_public_query_v1($public); }
        catch (RuntimeException $e) {
            if ($e->getCode()!==422) throw $e;
            return ['Use a public city or neighborhood and a short product and store category.',''];
        }
        $token=strtoupper(bin2hex(random_bytes(6)));
        $until=time()+900;
        if ($public['open_today']) $until=min($until,$date->modify('tomorrow')->setTime(0,0)->getTimestamp());
        $json=json_encode($public,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $old=vp3_phone_sql_v1($pdo,'SELECT offer_id FROM vp3_phone_local_approvals_v1 WHERE offer_id=?',[$id])->fetchColumn();
        if ($old!==false) {
            vp3_phone_sql_v1($pdo,"UPDATE vp3_phone_local_approvals_v1 SET preview_token=?,public_json=?,expires_at=? WHERE offer_id=? AND state='preview'",
                [$token,$json,$until,$id]);
        } else {
            vp3_phone_sql_v1($pdo,"INSERT INTO vp3_phone_local_approvals_v1 (offer_id,preview_token,state,public_json,expires_at)
                VALUES(?,?,'preview',?,?)",[$id,$token,$json,$until]);
        }
        return ["Public search for offer #$id\n$query\n\nReply “Approve local search {$token}” to send this query, or “Cancel local search {$token}”. To change the area, reply “Search area for offer #$id: [city or neighborhood]”.",''];
    }
    $token=$plan['token'];
    $row=preg_match('/^[A-F0-9]{12}$/D',$token) ? vp3_phone_sql_v1($pdo,'SELECT a.offer_id,a.public_json,a.expires_at,a.state
        FROM vp3_phone_local_approvals_v1 a JOIN vp3_phone_chat_turns_v1 t ON t.assistant_message_id=a.offer_id
        WHERE a.preview_token=? AND t.owner_user_id=? AND t.agent_id=? AND t.conversation_id=?',[$token,$uid,$aid,$cid])->fetch(PDO::FETCH_ASSOC) : false;
    if (!$row) return ['Use the approval or cancellation command from the latest preview in this private Chat.',''];
    if ($row['state']!=='preview') return [$row['state']==='cancelled'?'This local search was cancelled.':'This local search was already approved.',''];
    $id=(int)$row['offer_id'];
    if ($plan['action']==='local_cancel') {
        vp3_phone_sql_v1($pdo,"UPDATE vp3_phone_local_approvals_v1 SET state='cancelled' WHERE offer_id=?",[$id]);
        return ['Local search cancelled.',''];
    }
    if ((int)$row['expires_at']<=time()) return ["The approval expired. Reply “Search area for offer #$id: [city or neighborhood]” for a new preview.",''];
    $offer=vp3_phone_local_offer_v1($pdo,$user,$principal,$cid,$id);
    if (!$offer) return ['This visit or its matching task is no longer current. The search was not sent.',''];
    $public=json_decode($row['public_json'],true,16,JSON_THROW_ON_ERROR);
    if ($public['item']!==$offer['item_term'] || $public['category']!==$offer['merchant_category']
        || $public['open_today']!==(bool)$offer['open_today']
        || vp3_phone_local_private_field_v1($pdo,$uid,$aid,$cid,$id,$public)!==null) {
        return ['The search request changed. Choose the area again to review a new preview.',''];
    }
    vp3_phone_sql_v1($pdo,"UPDATE vp3_phone_local_approvals_v1 SET state='approved' WHERE offer_id=? AND state='preview'",[$id]);
    vp3_phone_local_launch_v1($pdo,$user,$principal,$cid,$id,$public,(int)$row['expires_at']);
    return ['Local search approved. Results will appear in this private Chat.',''];
}

/** Only evidence with an explicit coverage label can become a clickable private result. */
function vp3_phone_local_sources_v1(array $sources): array
{
    $result=[];
    foreach ($sources as $source) {
        $url=$source['url']??null;
        $coverage=$source['coverage']??null;
        if (!is_string($url) || strlen($url)>2048 || preg_match('/[\x00-\x20\x7f]/',$url)
            || !filter_var($url,FILTER_VALIDATE_URL) || strtolower((string)parse_url($url,PHP_URL_SCHEME))!=='https'
            || parse_url($url,PHP_URL_USER)!==null || parse_url($url,PHP_URL_PASS)!==null
            || !in_array($coverage,['page_excerpt','search_match'],true)) continue;
        $result[$url]=['title'=>vp3_phone_arrival_display_text_v1((string)($source['title']??''),'Open source'),
            'url'=>$url,'source'=>'location-web:'.hash('sha256',$url),'coverage'=>$coverage];
        if (count($result)>=6) break;
    }
    return array_values($result);
}

function vp3_phone_local_emit_v1(PDO $pdo,array $user,array $principal,int $cid,callable $authority): array
{
    $uid=(int)$user['id'];
    $aid=(int)($principal['agent_id']??0);
    $updates=[];
    foreach (vp3_phone_local_research_results_v1($pdo,$user,$principal,$cid,$authority) as $result) {
        $offerId=(int)$result['offer_id'];
        if (!$authority($offerId)) continue;
        $saved=vp3_phone_chat_store_v1($pdo,$uid,$aid,$cid,'',$result['answer'],'localresult');
        $sources=vp3_phone_local_sources_v1($result['sources']);
        $changed=vp3_phone_sql_v1($pdo,"UPDATE vp3_phone_local_approvals_v1 SET state='delivered',result_message_id=?,sources_json=?
            WHERE offer_id=? AND state='approved'",[$saved['assistant_message_id'],json_encode($sources,JSON_THROW_ON_ERROR),$offerId]);
        if ($changed->rowCount()!==1) throw new RuntimeException('Local search delivery changed.',409);
        $updates[]=vp3_phone_chat_announcement_v1($saved);
    }
    return $updates;
}

function vp3_phone_local_history_v1(PDO $pdo,array $turn): array
{
    $row=vp3_phone_sql_v1($pdo,"SELECT a.sources_json FROM vp3_phone_local_approvals_v1 a
        JOIN vp3_phone_chat_turns_v1 t ON t.assistant_message_id=a.offer_id
        WHERE a.result_message_id=? AND a.state='delivered' AND t.owner_user_id=? AND t.agent_id=? AND t.conversation_id=?",
        [$turn['assistant_message_id'],$turn['owner_user_id'],$turn['agent_id'],$turn['conversation_id']])->fetch(PDO::FETCH_ASSOC);
    return $row ? [$turn['answer'],json_decode($row['sources_json'],true,32,JSON_THROW_ON_ERROR)]
        : ['This local research result is no longer available.',[]];
}
