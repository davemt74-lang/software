<?php
declare(strict_types=1);

/** Run inside the existing SQLite/native-MySQL period gate, using canonical receipts. */
function profile_image_analytics_cases_v1(PDO $pdo,array $fixture,DateTimeImmutable $now,callable $ok): void
{
    $pdo->beginTransaction();
    try{
        $image=profile_image_analytics_fixture_v1($pdo,$fixture,$now);
        $read=static fn(?array $selection=null,?DateTimeImmutable $at=null):array=>vp3_profile_analytics_period_v1($pdo,$fixture['scope'],$selection??['ref'=>$fixture['ref']],$at??$now);
        $metric=static function(array $data,string $key,string $side='current'):array{
            foreach($data['metrics'] as $row)if($row['key']===$key)return $row[$side];throw new RuntimeException('Missing image metric.');
        };
        $get=static function(string $request)use($pdo):array{$q=$pdo->prepare('SELECT * FROM profile_image_edits_v1 WHERE request_id=?');$q->execute([$request]);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();return $row;};
        $json=static function(string $request,string $column,callable $edit)use($pdo,$get):void{
            $row=$get($request);$value=json_decode($row[$column],true,64,JSON_THROW_ON_ERROR);
            $pdo->prepare('UPDATE profile_image_edits_v1 SET '.$column.'=? WHERE request_id=?')->execute([json_encode($edit($value),JSON_THROW_ON_ERROR),$request]);
        };
        $rebind=static function(string $request)use($pdo,$get):void{
            $row=$get($request);$catalog=json_decode($row['catalog_json'],true,64,JSON_THROW_ON_ERROR);$base=(int)$row['base_message_id'];
            $intent=hash('sha256',json_encode([$catalog['collection_id'],$catalog['selection_asset_id'],1,$base,$base>0?null:$row['input_sha256'],
                $base>0?null:$row['input_name'],$base>0?null:$row['input_mime']],JSON_THROW_ON_ERROR));
            $q=$pdo->prepare('SELECT credential_hash FROM profile_agent_handoff_turns_pa3 WHERE id=?');$q->execute([$row['turn_id']]);$credential=$q->fetchColumn();$q->closeCursor();
            $turnHash=hash('sha256',json_encode([(int)$row['owner_user_id'],(int)$row['profile_agent_id'],(int)$row['profile_session_id'],'native',$credential,
                $row['prompt'],'agent',['profile_image_edit'=>$intent]],JSON_THROW_ON_ERROR));
            $pdo->prepare('UPDATE profile_image_edits_v1 SET request_hash=? WHERE request_id=?')->execute([$intent,$request]);
            $pdo->prepare('UPDATE profile_agent_handoff_turns_pa3 SET request_hash=? WHERE id=?')->execute([$turnHash,$row['turn_id']]);
        };
        $case=static function(callable $run)use($pdo):void{
            $pdo->exec('SAVEPOINT image_analytics_case');
            try{$run();}finally{$pdo->failQuery='';$pdo->readOnlyGuard=false;$pdo->exec('ROLLBACK TO SAVEPOINT image_analytics_case');$pdo->exec('RELEASE SAVEPOINT image_analytics_case');}
        };
        $before=p5_snapshot($pdo);$pdo->readOnlyGuard=true;$baseline=$read();$pdo->readOnlyGuard=false;
        $ok(p5_snapshot($pdo)===$before&&$pdo->inTransaction(),'image analytics performs only reads and preserves its caller transaction');
        $ok($metric($baseline,'image_creations_completed')['value']===2&&$metric($baseline,'image_initial_creations')['value']===1
            &&$metric($baseline,'image_revisions_completed')['value']===1&&$metric($baseline,'image_requests_failed')['value']===1
            &&$metric($baseline,'image_creations_completed','previous')['value']===1,'canonical image receipts separate completed uploads, revisions and dated failures across periods');
        $usage=$baseline['image_usage']['current'];
        $ok($usage['rankings_coverage']==='complete'&&$usage['top_catalogs']===[['collection_id'=>1080901,'name'=>'Soft watercolor','completed_count'=>2,'initial_count'=>1,'revision_count'=>1]]
            &&$usage['top_images']===[['asset_id'=>1080951,'title'=>'Watercolor portrait','completed_count'=>2]],'top catalogs and images use frozen owned attribution and distinct completed requests');
        $allowance=$baseline['backlog']['image_allowance'];
        $ok($allowance['coverage']==='complete'&&$allowance['used']===2&&$allowance['daily_limit']===5&&$allowance['remaining']===3
            &&$allowance['day_start_utc_ms']===(new DateTimeImmutable('2026-11-18T00:00:00Z'))->getTimestamp()*1000
            &&$allowance['resets_at_utc_ms']-$allowance['day_start_utc_ms']===86400000,'UTC allowance counts queued and cancelled admissions using the same all-state predicate as admission');
        $ok($baseline['backlog']['image_failures']['count']===1&&$usage['failures'][0]['code']==='provider_busy'
            &&!preg_match('/PRIVATE|credential_hash|input_sha256|provider_rate_limited/',json_encode($baseline,JSON_THROW_ON_ERROR)),
            'owner summaries expose safe reason groups without visitor prompts, pixels, credentials or raw provider failures');
        profile_image_analytics_fixture_v1($pdo,$fixture,$now);
        $ok($read()['revision']===$baseline['revision'],'re-reading or replaying the same persisted image requests cannot inflate creations');
        $case(static function()use($pdo,$read,$baseline,$ok):void{
            $pdo->exec('UPDATE user_profiles SET is_public=0,profile_agent_enabled=0 WHERE user_id=1');
            $pdo->exec('UPDATE profile_image_settings_v1 SET enabled=0 WHERE owner_user_id=1');$r=$read();
            $ok($r['revision']===$baseline['revision']&&$r['backlog']['image_allowance']['enabled']===false,
                'public access and image-setting revocation preserve authorized private historical image totals');
        });
        $case(static function()use($pdo,$image,$read,$metric,$ok):void{
            $pdo->prepare("UPDATE agent_workflow_receipts SET result_json='{}' WHERE id=?")->execute([$image['revision']['receipt_id']]);$r=$read();$point=$metric($r,'image_creations_completed');
            $ok($point['coverage']==='partial'&&$point['value']===null&&$point['observed_value']===1
                &&$metric($r,'image_creations_completed','previous')['coverage']==='complete','a broken exact completion receipt limits only the affected completed period');
        });
        $case(static function()use($pdo,$image,$read,$metric,$ok):void{
            $pdo->prepare("UPDATE agent_workflow_receipts SET status='failed' WHERE id=?")->execute([$image['initial']['receipt_id']]);$r=$read();
            $ok($metric($r,'image_creations_completed')['observed_value']===0&&$metric($r,'image_revisions_completed')['coverage']==='partial',
                'a revision cannot certify a result whose original completion receipt is invalid');
        });
        $case(static function()use($pdo,$image,$read,$metric,$ok):void{
            $pdo->prepare('UPDATE profile_agent_handoff_turns_pa3 SET request_hash=? WHERE id=?')->execute([str_repeat('a',64),$image['revision']['turn_id']]);
            $ok($metric($read(),'image_revisions_completed')['coverage']==='partial','image intent must remain bound to its exact accepted native request fingerprint');
        });
        foreach(['owner_user_id'=>2,'price_cents'=>1,'selection_asset_id'=>999] as $field=>$value)$case(static function()use($field,$value,$json,$image,$read,$metric,$ok):void{
            $json($image['revision']['request_id'],'catalog_json',static function(array $s)use($field,$value):array{$s[$field]=$value;return $s;});$r=$read();
            $ok($metric($r,'image_creations_completed')['observed_value']===1&&$r['image_usage']['current']['rankings_coverage']==='partial',
                'frozen image attribution rejects invalid '.$field.' without inventing a zero');
        });
        foreach(['root_message_id'=>1080201,'base_message_id'=>1080202,'input_sha256'=>str_repeat('f',64)] as $field=>$value)$case(static function()use($pdo,$field,$value,$image,$read,$metric,$ok):void{
            $pdo->prepare('UPDATE profile_image_edits_v1 SET '.$field.'=? WHERE request_id=?')->execute([$value,$image['revision']['request_id']]);
            $ok($metric($read(),'image_revisions_completed')['coverage']==='partial','revision lineage rejects changed '.$field);
        });
        $case(static function()use($pdo,$image,$read,$metric,$ok,$fixture):void{
            $pdo->prepare('UPDATE profile_image_edits_v1 SET completed_at=? WHERE request_id=?')->execute([gmdate('Y-m-d H:i:s',$fixture['cutoff']),$image['revision']['request_id']]);$r=$read();
            $ok($metric($r,'image_creations_completed')['coverage']==='complete'&&$metric($r,'image_creations_completed')['value']===1,
                'an image completed exactly at the exclusive cutoff belongs to the following period');
        });
        $case(static function()use($pdo,$image,$read,$metric,$ok):void{
            $pdo->prepare('DELETE FROM agent_workflow_receipts WHERE id=?')->execute([$image['failure']['receipt_id']]);$r=$read();
            $ok($metric($r,'image_requests_failed')['coverage']==='partial'&&$metric($r,'image_requests_failed')['observed_value']===0
                &&$metric($r,'image_requests_failed','previous')['value']===0&&$r['backlog']['image_failures']['count']===1
                &&$r['image_usage']['current']['rankings_coverage']==='complete','undated failures stay live while historical failure timing is incomplete and completion rankings remain proved');
        });
        $case(static function()use($pdo,$image,$read,$baseline,$ok):void{
            $pdo->prepare("UPDATE profile_image_edits_v1 SET state='failed',error_code='provider_access_denied' WHERE request_id=?")->execute([$image['cancelled']['request_id']]);$r=$read();
            $ok($r['revision']===$baseline['revision']&&$r['backlog']['revision']!==$baseline['backlog']['revision']
                &&$r['backlog']['image_failures']['count']===2&&$r['backlog']['image_allowance']['used']===2,
                'a later undated failure changes live failures without rewriting an earlier completed period or charging another allowance unit');
        });
        $case(static function()use($pdo,$image,$read,$ok):void{
            $pdo->prepare('UPDATE profile_image_edits_v1 SET error_code=? WHERE request_id=?')->execute(['SECRET provider token and arbitrary HTML',$image['failure']['request_id']]);$r=$read();
            $ok($r['image_usage']['current']['failures'][0]['code']==='other'&&!str_contains(json_encode($r,JSON_THROW_ON_ERROR),'SECRET'),
                'unknown provider errors collapse to a fixed safe reason instead of exposing their content');
        });
        $case(static function()use($pdo,$image,$read,$baseline,$ok,$now):void{
            $pdo->prepare('UPDATE profile_image_edits_v1 SET created_at=? WHERE request_id=?')->execute([$now->modify('-1 day')->format('Y-m-d H:i:s'),$image['queued']['request_id']]);$r=$read();
            $ok($r['revision']===$baseline['revision']&&$r['backlog']['image_allowance']['used']===1&&$r['backlog']['image_allowance']['remaining']===4,
                'live allowance updates do not change completed-period source identity');
            $pdo->prepare('UPDATE profile_image_edits_v1 SET created_at=? WHERE request_id=?')->execute([$now->modify('+1 day')->format('Y-m-d H:i:s'),$image['queued']['request_id']]);
            $ok($read()['backlog']['image_allowance']['used']===2,'allowance preserves admission\'s exact lower-bound predicate, including already saved future-dated rows');
        });
        $case(static function()use($pdo,$read,$ok):void{
            $pdo->exec('UPDATE profile_image_settings_v1 SET daily_limit=1 WHERE owner_user_id=1');$r=$read();
            $ok($r['backlog']['image_allowance']['used']===2&&$r['backlog']['image_allowance']['remaining']===0,'a lowered allowance shows actual admitted usage and clamps remaining at zero');
            $pdo->exec("UPDATE users SET timezone='America/New_York' WHERE id=1");$r=$read(['period'=>1]);
            $ok($r['window']['timezone']==='America/New_York'&&str_ends_with($r['window']['to_local'],'-05:00')
                &&$r['backlog']['image_allowance']['timezone']==='UTC'&&$r['backlog']['image_allowance']['day_start_utc_ms']%86400000===0,
                'owner-local reporting boundaries stay separate from the live UTC allowance day');
        });
        $case(static function()use($pdo,$image,$get,$read,$metric,$ok):void{
            $row=$get($image['initial']['request_id']);$row['request_id']=str_repeat('e',32);profile_analytics_period_fixture_row_v1($pdo,'profile_image_edits_v1',$row,'request_id');$r=$read();
            $ok($metric($r,'image_creations_completed')['coverage']==='partial'&&$metric($r,'image_creations_completed')['observed_value']===0,
                'duplicate canonical request, turn or result identities are rejected instead of double-counted');
        });
        $case(static function()use($json,$rebind,$image,$read,$metric,$ok):void{
            foreach(['initial','revision'] as $name){
                $json($image[$name]['request_id'],'catalog_json',static function(array $s):array{
                    $s['selection_asset_id']=0;$b=$s['references'][0];$b['asset_id']+=1;$b['title']='Second approved image';$s['references'][]=$b;
                    $b['asset_id']+=1;$b['title']='Third approved image';$s['references'][]=$b;return $s;
                });$rebind($image[$name]['request_id']);
            }
            $r=$read();$top=$r['image_usage']['current']['top_images'];
            $ok($metric($r,'image_creations_completed')['value']===2&&count($top)===3&&array_column($top,'asset_id')===[1080951,1080952,1080953]
                &&array_sum(array_column($top,'completed_count'))===6,'a multi-image catalog credits every frozen reference once per creation with deterministic tied ranking');
        });
        $case(static function()use($json,$image,$read,$baseline,$ok):void{
            $json($image['revision']['request_id'],'catalog_json',static function(array $s):array{
                $s['catalog_name']='<b>'.str_repeat('Watercolor ',20).'</b>'."\u{200B}";$s['references'][0]['title']=str_repeat('Artwork ',100);return $s;
            });$r=$read();$usage=$r['image_usage']['current'];
            $ok($r['revision']!==$baseline['revision']&&mb_strlen($usage['top_catalogs'][0]['name'])===100
                &&mb_strlen($usage['top_images'][0]['title'])===500&&!str_contains($usage['top_catalogs'][0]['name'],'<'),
                'frozen display-name corrections revise the source and produce bounded sanitized ranking labels');
        });
        $case(static function()use($json,$image,$read,$ok):void{
            $json($image['revision']['request_id'],'catalog_json',static function(array $s):array{
                $s['catalog_name']="\u{00A0}Watercolor\u{2028}portrait\u{202F}";$s['references'][0]['title']="\u{2007}\u{2029}\u{FEFF}";return $s;
            });$r=$read();$usage=$r['image_usage']['current'];
            $ok($usage['top_catalogs'][0]['name']==='Watercolor portrait'&&$usage['top_images'][0]['title']==='Image 1080951'
                &&vp3_profile_image_analytics_label_v1(str_repeat('a',99).' trailing',100,'fallback')===str_repeat('a',99),
                'Unicode separators and format controls normalize to trimmed single-line labels, including truncation boundaries and empty-label fallbacks');
        });
        $case(static function()use($pdo,$read,$metric,$ok):void{
            $pdo->failQuery='FROM profile_image_edits_v1 WHERE';$r=$read();$pdo->failQuery='';
            $ok($metric($r,'image_creations_completed')['coverage']==='unavailable'&&$metric($r,'image_creations_completed')['value']===null
                &&$r['image_usage']['current']['top_catalogs']===[]&&$r['backlog']['image_allowance']['used']===null
                &&$r['backlog']['image_failures']['count']===null&&!str_contains(json_encode($r,JSON_THROW_ON_ERROR),'SECRET'),
                'image storage failure returns explicit unavailable fields instead of zero activity');
        });
        $case(static function()use($pdo,$read,$baseline,$ok):void{
            $pdo->failQuery='FROM profile_image_settings_v1 WHERE';$r=$read();$pdo->failQuery='';
            $ok($r['revision']===$baseline['revision']&&$r['backlog']['image_allowance']['coverage']==='unavailable'
                &&$r['backlog']['image_allowance']['remaining']===null,'unavailable live settings do not erase verified historical image usage');
        });
        $case(static function()use($pdo,$image,$read,$metric,$ok):void{
            $pdo->prepare('DELETE FROM agent_workflow_receipts WHERE id=?')->execute([$image['failure']['receipt_id']]);
            $pdo->prepare('UPDATE profile_image_edits_v1 SET created_at=NULL WHERE request_id=?')->execute([$image['failure']['request_id']]);$r=$read();
            $ok($metric($r,'image_requests_failed')['coverage']==='partial'&&$metric($r,'image_requests_failed','previous')['coverage']==='partial'
                &&$r['backlog']['image_failures']['coverage']==='partial'&&$r['backlog']['image_failures']['count']===null,
                'missing admission and failure clocks cannot fabricate either dated failures or a complete current count');
        });
        $case(static function()use($pdo,$image,$read,$metric,$ok):void{
            $pdo->prepare("UPDATE profile_image_edits_v1 SET state='unknown' WHERE request_id=?")->execute([$image['revision']['request_id']]);$r=$read();
            $ok($metric($r,'image_creations_completed')['coverage']==='partial'&&$metric($r,'image_requests_failed')['coverage']==='partial'
                &&$metric($r,'image_creations_completed','previous')['coverage']==='complete'&&$r['backlog']['image_failures']['coverage']==='partial',
                'an unrecognized persisted image state produces incomplete evidence rather than a completed or failed zero');
        });
        $ok($read()['revision']===$baseline['revision'],'restoring canonical image evidence restores the exact fixed-period revision');
    }finally{$pdo->readOnlyGuard=false;$pdo->failQuery='';if($pdo->inTransaction())$pdo->rollBack();}
}
