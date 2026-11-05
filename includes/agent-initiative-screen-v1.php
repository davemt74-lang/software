<?php
declare(strict_types=1);

require_once __DIR__.'/agent-screen-insights-v1.php';

/** Read the current saved-note suggestion for each eligible System Agent session. */
function vp3_initiative_screen_evidence_v1(PDO $pdo,array $user,DateTimeImmutable $now,array $sessions=[]): array
{
    screen_sessions_permission_v1($user);
    foreach(['agent_screen_sessions_v1','agent_screen_captures_v1','artist_transcript_folders_v177'] as $table){
        if(!table_exists($table))return [];
    }
    if(vp3_agent_memory_scope_current_v410($user)!==0)return [];
    $uid=(int)$user['id'];$at=$now->getTimestamp();
    $sqlite=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
    $locked=$sessions&&$pdo->inTransaction()&&!$sqlite;
    $lock=$locked?' FOR UPDATE':'';
    if($sessions){
        sort($sessions,SORT_STRING);
        // Dismissal, review, handoff preparation and deletion lock the same session.
        foreach($sessions as $session){
            try{screen_session_v1($pdo,$user,$session,$locked);}
            catch(RuntimeException $e){if($e->getCode()!==404)throw $e;return [];}
        }
    }
    $noteJson=static fn(string $alias):string=>"CASE WHEN JSON_VALID($alias.body_json) THEN $alias.body_json ELSE '{}' END";
    $note=static fn(string $alias):string=>$sqlite
        ?"JSON_EXTRACT(".$noteJson($alias).",'$.note')"
        :"JSON_UNQUOTE(JSON_EXTRACT(".$noteJson($alias).",'$.note'))";
    $noteType=$sqlite?"JSON_TYPE(".$noteJson('recent').",'$.note')"
        :"JSON_TYPE(JSON_EXTRACT(".$noteJson('recent').",'$.note'))";
    $type=$sqlite?'text':'STRING';
    $cutoff=gmdate('Y-m-d\TH:i:s',$at-7*86400);
    $through=gmdate('Y-m-d\TH:i:s',$at).'Z';
    $scope=$sessions?' AND s.session_id IN ('.implode(',',array_fill(0,count($sessions),'?')).')':'';
    $reviewed=table_exists('agent_screen_reviews_v1');
    $reviewJoin=$reviewed?'LEFT JOIN agent_screen_reviews_v1 review ON review.owner_user_id=recent.owner_user_id AND review.capture_id=recent.capture_id':'';
    $reviewScope=$reviewed?" AND (review.state IS NULL OR review.state='active')":'';

    // Only the explicit owner note leaves storage. The latest eligible note per
    // session collapses repeated automatic captures carrying the same note.
    $sql="SELECT s.session_id,s.folder_id,s.title,c.capture_id,c.captured_at,c.revision,k.file_name,"
        .$note('c')." AS note
        FROM agent_screen_sessions_v1 s
        JOIN artist_transcript_folders_v177 f ON f.id=s.folder_id AND f.created_by_user_id=s.owner_user_id
        JOIN agent_screen_captures_v1 c ON c.owner_user_id=s.owner_user_id AND c.session_id=s.session_id
            AND c.capture_id=(
                SELECT recent.capture_id FROM agent_screen_captures_v1 recent
                $reviewJoin
                WHERE recent.owner_user_id=s.owner_user_id AND recent.session_id=s.session_id
                    AND recent.kind='screen' AND recent.captured_at>=? AND recent.captured_at<=?
                    $reviewScope
                    AND $noteType='$type' AND TRIM(".$note('recent').")<>''
                ORDER BY recent.captured_at DESC,recent.capture_id DESC LIMIT 1)
        JOIN knowledge_items k ON k.id=c.knowledge_id AND k.created_by_user_id=s.owner_user_id
            AND k.knowledge_scope='personal' AND k.file_type='personal_note'
        WHERE s.owner_user_id=? AND s.agent_id=0 AND s.status='ended'$scope
        ORDER BY c.captured_at DESC,c.capture_id DESC".$lock;
    $stmt=$pdo->prepare($sql);
    $stmt->execute([$cutoff,$through,$uid,...$sessions]);
    $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
    $state=table_exists('agent_screen_insight_state_v1')?$pdo->prepare("SELECT decision,fingerprint,until_at FROM agent_screen_insight_state_v1
        WHERE owner_user_id=? AND agent_id=0 AND insight_key=?".$lock):null;
    $handoff=table_exists('agent_screen_handoffs_v1')?$pdo->prepare("SELECT handoff_id FROM agent_screen_handoffs_v1
        WHERE owner_user_id=? AND agent_id=0 AND source_hash=? AND status IN ('preparing','prepared') LIMIT 1".$lock):null;
    $out=[];
    foreach($rows as $row){
        $savedAt=strtotime((string)$row['captured_at']);
        if($savedAt===false||$savedAt<$at-7*86400||$savedAt>$at)continue;
        $sid=screen_session_id_v1($row['session_id']);$capture=screen_session_id_v1($row['capture_id']);
        if($row['file_name']!=='personal-'.sha1('screen-capture:'.$capture).'.txt')continue;
        $note=(string)$row['note'];
        $detail=trim(preg_replace('/\s+/u',' ',$note)??'');
        if($detail==='')continue;
        $card=screen_insights_intent_card_v1($row,$note);
        if($state){
            $state->execute([$uid,$card['key']]);
            $decision=$state->fetch(PDO::FETCH_ASSOC)?:null;$state->closeCursor();
            if(!screen_insights_pending_v1($card,$decision,$at))continue;
        }
        if($handoff){
            $handoff->execute([$uid,$card['review_hash']]);$alreadyOwned=$handoff->fetchColumn()!==false;$handoff->closeCursor();
            if($alreadyOwned)continue;
        }
        $ref='screen-followup:'.$sid;
        $out[$ref]=['ref'=>$ref,'kind'=>'screen_followup','title'=>(string)$row['title'],'detail'=>mb_substr($detail,0,400),
            'saved_at'=>gmdate(DATE_ATOM,$savedAt),'url'=>$card['sources'][0]['url'],
            'revision'=>screen_insights_hash_v1([$card['review_hash'],$card['fingerprint'],$row['title'],(int)$row['folder_id'],$savedAt])];
        if(count($out)>=4)break;
    }
    return $out;
}
