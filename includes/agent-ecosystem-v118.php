<?php
declare(strict_types=1);

/**
 * Stonefellow v118 — proactive Agent Brain ecosystem scan.
 *
 * This layer looks across the working Stonefellow ecosystem and emits only
 * actionable opportunities. A surface being scanned does not mean it must
 * produce a suggestion; silence is preferred to noise when there is no useful
 * next action.
 */

function agent_ecosystem_v118_item(
    string $key,
    string $title,
    string $body,
    string $url,
    int $priority,
    string $source
): array {
    return [
        'id'=>'opportunity-'.sha1($key),
        'type'=>'opportunity',
        'title'=>mb_strimwidth(trim($title),0,140,'…'),
        'body'=>mb_strimwidth(trim($body),0,320,'…'),
        'target_url'=>$url,
        'created_at'=>date('Y-m-d H:i:s'),
        'priority'=>$priority,
        'source'=>$source,
        'key'=>$key,
    ];
}

function agent_ecosystem_v118_scan(array $user, string $since=''): array
{
    $pdo=db();
    $uid=(int)($user['id']??0);
    if(!$pdo||$uid<1)return [];

    $items=[];
    $since=$since!==''?$since:date('Y-m-d H:i:s',time()-86400);

    // Existing Agent Brain suggestions are the first source of opportunities.
    if(function_exists('agent_proactive_v93_suggestions')){
        try{
            $context=[];
            if(function_exists('agent_chat_v101_latest_conversation_id')){
                $context['conversation_id']=agent_chat_v101_latest_conversation_id($pdo,$uid);
            }
            $proactive=agent_proactive_v93_suggestions($user,'chat',$context);
            if(function_exists('release_v105_merge_proactive')){
                $proactive=release_v105_merge_proactive($proactive,$user);
            }
            foreach((array)($proactive['suggestions']??[]) as $suggestion){
                $source=(string)($suggestion['source']??'agent_brain');
                if(in_array($source,['starter','fallback','activity_working'],true))continue;
                $items[]=agent_ecosystem_v118_item(
                    'brain:'.(string)($suggestion['hash']??sha1(json_encode($suggestion))),
                    (string)($suggestion['title']??'Next action'),
                    (string)($suggestion['reason']??'Stonefellow found a useful next action.'),
                    (string)($suggestion['url']??''),
                    (int)($suggestion['priority']??100),
                    $source
                );
            }
        }catch(Throwable $e){}
    }

    // Tracks + Shared Tracks: unfinished production and recently changed
    // collaborations are prime candidates for Stonefellow to help move forward.
    if(table_exists('tracks')){
        try{
            $where=has_permission('tracks.manage',$user)
                ? '1=1'
                : '(owner_user_id=? OR producer_user_id=?)';
            $params=has_permission('tracks.manage',$user)?[]:[$uid,$uid];
            $stmt=$pdo->prepare("SELECT id,title,is_published,audio_path,owner_user_id,producer_user_id,updated_at FROM tracks WHERE $where ORDER BY updated_at DESC,id DESC LIMIT 20");
            $stmt->execute($params);
            $rows=$stmt->fetchAll()?:[];
            foreach($rows as $track){
                $tid=(int)$track['id'];
                $title=trim((string)$track['title'])?:'Untitled track';
                if((int)$track['is_published']!==1){
                    $items[]=agent_ecosystem_v118_item(
                        'track-draft:'.$tid,
                        'Keep '.$title.' moving',
                        trim((string)$track['audio_path'])===''?'This track is still a draft and does not have a finished audio file yet.':'This track is still in draft status. Stonefellow can review what remains and help choose the next production step.',
                        url('/admin/stems.php?track='.$tid),
                        118,
                        'tracks'
                    );
                    break;
                }
            }
            foreach($rows as $track){
                if((int)($track['producer_user_id']??0)===$uid&&(int)($track['owner_user_id']??0)!==$uid){
                    $updated=strtotime((string)($track['updated_at']??''))?:0;
                    if($updated>=time()-7*86400){
                        $items[]=agent_ecosystem_v118_item(
                            'shared-track:'.(int)$track['id'].':'.date('Y-m-d',$updated),
                            'Review shared track '.(string)$track['title'],
                            'A shared production track has recent activity. Stonefellow can review where it stands and prepare the next useful action.',
                            url('/admin/producer-tracks.php'),
                            126,
                            'shared_tracks'
                        );
                        break;
                    }
                }
            }
        }catch(Throwable $e){}
    }

    // Albums: upcoming release dates that are still unpublished need attention.
    if(table_exists('albums')&&has_permission('albums.manage',$user)){
        try{
            $stmt=$pdo->query("SELECT id,title,release_date,is_published FROM albums WHERE release_date IS NOT NULL AND release_date<>'' AND release_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 45 DAY) ORDER BY release_date ASC,id ASC LIMIT 4");
            foreach($stmt->fetchAll()?:[] as $album){
                if((int)$album['is_published']===1)continue;
                $items[]=agent_ecosystem_v118_item(
                    'album-release:'.(int)$album['id'].':'.(string)$album['release_date'],
                    'Prepare '.$album['title'].' for release',
                    'This album has an approaching release date but is still unpublished. Stonefellow can review tracks, release tasks, credits, content, and launch readiness.',
                    url('/admin/albums.php?edit='.(int)$album['id']),
                    145,
                    'albums'
                );
            }
        }catch(Throwable $e){}
    }

    // Credits Graph: recently updated tracks with no explicit structured credit
    // rows are worth a quick review before release work advances.
    if(table_exists('track_credits')&&table_exists('tracks')&&(user_has_role('admin',$user)||permission_v105_has('credits.manage',$user))){
        try{
            $stmt=$pdo->query("SELECT t.id,t.title,t.updated_at FROM tracks t LEFT JOIN track_credits tc ON tc.track_id=t.id WHERE t.is_published=0 GROUP BY t.id,t.title,t.updated_at HAVING COUNT(tc.id)=0 ORDER BY t.updated_at DESC,t.id DESC LIMIT 1");
            if($row=$stmt->fetch()){
                $items[]=agent_ecosystem_v118_item(
                    'credits-review:'.(int)$row['id'],
                    'Review credits for '.$row['title'],
                    'This active track does not yet have structured Credits Graph entries. Stonefellow can help verify contributors before release.',
                    url('/admin/credits.php?track='.(int)$row['id']),
                    108,
                    'credits'
                );
            }
        }catch(Throwable $e){}
    }

    // Listening Analytics + Music Analytics: detect genuine recent momentum rather
    // than reporting raw numbers every time the user returns.
    if(table_exists('track_play_sessions')&&table_exists('tracks')&&has_permission('listening.view',$user)){
        try{
            $sql="SELECT t.id,t.title,
                    SUM(CASE WHEN s.started_at>=DATE_SUB(NOW(),INTERVAL 7 DAY) AND s.qualified_play=1 THEN 1 ELSE 0 END) recent_qualified,
                    SUM(CASE WHEN s.started_at<DATE_SUB(NOW(),INTERVAL 7 DAY) AND s.started_at>=DATE_SUB(NOW(),INTERVAL 14 DAY) AND s.qualified_play=1 THEN 1 ELSE 0 END) prior_qualified,
                    AVG(CASE WHEN s.started_at>=DATE_SUB(NOW(),INTERVAL 7 DAY) THEN s.completion_percent ELSE NULL END) recent_completion
                  FROM tracks t JOIN track_play_sessions s ON s.track_id=t.id
                  WHERE s.started_at>=DATE_SUB(NOW(),INTERVAL 14 DAY)
                  GROUP BY t.id,t.title ORDER BY recent_qualified DESC LIMIT 8";
            foreach($pdo->query($sql)->fetchAll()?:[] as $row){
                $recent=(int)$row['recent_qualified'];$prior=(int)$row['prior_qualified'];
                if($recent>=3&&$recent>=max(3,(int)ceil($prior*1.5))){
                    $items[]=agent_ecosystem_v118_item(
                        'listening-momentum:'.(int)$row['id'].':'.date('o-W'),
                        $row['title'].' is gaining listening momentum',
                        'Qualified listens are running ahead of the previous week'.((float)$row['recent_completion']>0?' with about '.round((float)$row['recent_completion']).'% average completion':'').'. Stonefellow can look for a promotion, content, or release opportunity around it.',
                        url('/admin/listening.php?range=7'),
                        132,
                        'listening_analytics'
                    );
                    break;
                }
            }
        }catch(Throwable $e){}
    }

    // Shows: approaching dates that are still draft or missing ticket links.
    if(table_exists('shows')&&has_permission('shows.manage',$user)){
        try{
            $stmt=$pdo->query("SELECT id,show_date,venue,city,region,ticket_url,is_published FROM shows WHERE show_date>=NOW() AND show_date<=DATE_ADD(NOW(),INTERVAL 21 DAY) ORDER BY show_date ASC LIMIT 5");
            foreach($stmt->fetchAll()?:[] as $show){
                if((int)$show['is_published']===1&&trim((string)$show['ticket_url'])!=='')continue;
                $items[]=agent_ecosystem_v118_item(
                    'show-readiness:'.(int)$show['id'],
                    'Finish show setup for '.$show['venue'],
                    'This upcoming show still needs '.((int)$show['is_published']!==1?'publishing': 'a ticket link').'. Stonefellow can help finish the listing and prepare supporting promotion.',
                    url('/admin/shows.php?edit='.(int)$show['id']),
                    138,
                    'shows'
                );
                break;
            }
        }catch(Throwable $e){}
    }

    // Photos + Posts: a recent photo without a newer published post is a simple
    // content opportunity Stonefellow can proactively offer to turn into output.
    if(table_exists('photos')&&table_exists('artist_posts')&&has_permission('posts.manage',$user)){
        try{
            $photo=$pdo->query("SELECT id,title,created_at FROM photos WHERE is_published=1 AND created_at>=DATE_SUB(NOW(),INTERVAL 10 DAY) ORDER BY created_at DESC,id DESC LIMIT 1")->fetch()?:null;
            if($photo){
                $stmt=$pdo->prepare("SELECT COUNT(*) FROM artist_posts WHERE is_published=1 AND published_at>=?");$stmt->execute([(string)$photo['created_at']]);
                if((int)$stmt->fetchColumn()===0){
                    $items[]=agent_ecosystem_v118_item(
                        'photo-post:'.(int)$photo['id'],
                        'Turn '.$photo['title'].' into a post',
                        'There is a recent published photo with no newer published post. Stonefellow can draft a useful update around it.',
                        url('/admin/posts.php?new=1'),
                        96,
                        'photos_posts'
                    );
                }
            }
        }catch(Throwable $e){}
    }

    // Merch: unfinished product records are actionable commerce work.
    if(table_exists('merch_items')&&has_permission('merch.manage',$user)){
        try{
            $row=$pdo->query("SELECT id,title,is_published,product_url FROM merch_items WHERE is_published=0 OR product_url IS NULL OR product_url='' ORDER BY id DESC LIMIT 1")->fetch()?:null;
            if($row){
                $items[]=agent_ecosystem_v118_item(
                    'merch-finish:'.(int)$row['id'],
                    'Finish merch item '.$row['title'],
                    (int)$row['is_published']!==1?'This merch item is still a draft.':'This merch item does not have a purchase URL yet.',
                    url('/admin/merch.php?edit='.(int)$row['id']),
                    91,
                    'merch'
                );
            }
        }catch(Throwable $e){}
    }

    // Knowledge: drafts are potential missing context for the Agent Brain itself.
    if(table_exists('knowledge_items')&&has_permission('knowledge.manage',$user)){
        try{
            $row=$pdo->query("SELECT id,title FROM knowledge_items WHERE is_published=0 ORDER BY id DESC LIMIT 1")->fetch()?:null;
            if($row){
                $items[]=agent_ecosystem_v118_item(
                    'knowledge-draft:'.(int)$row['id'],
                    'Review knowledge draft '.$row['title'],
                    'This knowledge item is not published to the Stonefellow knowledge layer yet. Finishing it could give the Agent Brain better project context.',
                    url('/admin/knowledge.php?edit='.(int)$row['id']),
                    82,
                    'knowledge'
                );
            }
        }catch(Throwable $e){}
    }

    // CRM: only Admin account types receive demo-lead data or CRM opportunities.
    // New leads are also appended directly into Agent Chat when they are created;
    // this scan handles ongoing follow-up, demo, assignment and stalled-lead work.
    if(function_exists('crm_v180_agent_opportunities')&&function_exists('crm_v180_can_manage')&&crm_v180_can_manage($user)){
        try{
            foreach(crm_v180_agent_opportunities($user,$since) as $crmItem){
                $items[]=$crmItem;
            }
        }catch(Throwable $e){}
    }

    // Messages: unanswered inbound contact is a high-value follow-up signal.
    // Book-a-Demo submissions are skipped once CRM is ready so admins do not
    // receive duplicate CRM + generic inbox opportunities for the same lead.
    if(table_exists('contact_messages')&&has_permission('messages.manage',$user)){
        try{
            $stmt=$pdo->prepare("SELECT id,name,topic,status,created_at FROM contact_messages WHERE status IN ('new','open') AND created_at>? ORDER BY created_at ASC,id ASC LIMIT 6");
            $stmt->execute([$since]);
            $messageCount=0;
            foreach($stmt->fetchAll()?:[] as $message){
                if((string)$message['topic']==='Book a Demo'&&function_exists('crm_v180_schema_ready')&&crm_v180_schema_ready($pdo))continue;
                $items[]=agent_ecosystem_v118_item(
                    'contact-message:'.(int)$message['id'],
                    'Follow up with '.$message['name'],
                    'An inbound message about '.$message['topic'].' is still '.strtolower((string)$message['status']).'. Stonefellow can help review it and prepare the next response.',
                    url('/admin/messages.php?view='.(int)$message['id']),
                    150,
                    'messages'
                );
                $messageCount++;
                if($messageCount>=3)break;
            }
        }catch(Throwable $e){}
    }

    // De-duplicate opportunities, then let priority determine what reaches the
    // user. The scanner can look everywhere without turning the greeting noisy.
    $dedup=[];
    foreach($items as $item){
        $key=(string)($item['key']??$item['id']);
        if(!isset($dedup[$key])||(int)$item['priority']>(int)$dedup[$key]['priority'])$dedup[$key]=$item;
    }
    $items=array_values($dedup);
    usort($items,static fn(array $a,array $b):int=>(int)$b['priority']<=>(int)$a['priority']);
    return $items;
}
