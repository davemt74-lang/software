<?php
declare(strict_types=1);

/**
 * Browser Companion v21.30 — ephemeral page context + relationship discovery.
 *
 * This layer is intentionally read-only. Viewing a page must not create a Source,
 * Browser Share, Research item, Knowledge item, task, contact, memory, or other
 * durable VP3 object. Persistence happens only through an explicit user action.
 */
const VP3_BROWSER_CONTEXT_V2130='browser-context-v2130-20260919';
const VP3_BROWSER_CONTEXT_SELECTION_MAX_V2130=12000;

require_once __DIR__.'/browser-source-feed-v2050.php';
require_once __DIR__.'/research-projects-v2060.php';
require_once __DIR__.'/knowledge.php';
require_once __DIR__.'/user-calendar-v1300.php';
require_once __DIR__.'/crm-v180.php';

function vp3_browser_context_text_v2130(mixed $value,int $max): string
{
    if(!is_scalar($value))return '';
    $text=trim((string)$value);
    if(str_contains($text,"\0"))throw new InvalidArgumentException('Browser page context contains unsupported characters.');
    return mb_strimwidth($text,0,max,'');
}

function vp3_browser_context_bytes_v2130(mixed $value,int $maxBytes): string
{
    if(!is_scalar($value))return '';
    $text=trim((string)$value);
    if(str_contains($text,"\0"))throw new InvalidArgumentException('Browser page context contains unsupported characters.');
    if(strlen($text)<=$maxBytes)return $text;
    return mb_strcut($text,0,$maxBytes,'UTF-8');
}

function vp3_browser_context_validate_v2130(array $input): array
{
    $url=trim((string)($input['source_url']??$input['url']??''));
    if($url==='')throw new InvalidArgumentException('A current web page is required for contextual Agent actions.');
    $canonical=trim((string)($input['canonical_url']??''));
    $title=vp3_browser_context_text_v2130($input['title']??'',512);
    $identity=vp3_browser_source_identity_v2050($url,$canonical,$title);

    $hash=strtolower(trim((string)($input['page_text_sha256']??'')));
    if(!preg_match('/^[a-f0-9]{64}$/',$hash))$hash='';

    $meta=is_array($input['metadata']??null)?$input['metadata']:[];
    $media=is_array($input['media']??null)?$input['media']:null;
    $cleanMedia=null;
    if($media){
        $kind=vp3_browser_context_text_v2130($media['kind']??'',40);
        $mediaUrl=trim((string)($media['source_media_url']??''));
        if($kind!==''&&$mediaUrl!==''){
            try{$mediaInfo=vp3_browser_share_validate_url_v2010($mediaUrl);$mediaUrl=(string)$mediaInfo['url'];}
            catch(Throwable $e){$mediaUrl='';}
        }
        if($kind!==''&&$mediaUrl!==''){
            $cleanMedia=[
                'kind'=>$kind,
                'url'=>$mediaUrl,
                'title'=>vp3_browser_context_text_v2130($media['source_media_title']??'',300),
                'current_time'=>max(0,(float)($media['current_time']??0)),
            ];
        }
    }

    return [
        'ephemeral'=>true,
        'source_url'=>(string)$identity['normalized_url'],
        'canonical_url'=>(string)$identity['canonical_url'],
        'domain'=>(string)$identity['domain'],
        'title'=>(string)$identity['title'],
        'selected_text'=>vp3_browser_context_bytes_v2130($input['selected_text']??'',VP3_BROWSER_CONTEXT_SELECTION_MAX_V2130),
        'page_text_sha256'=>$hash,
        'metadata'=>[
            'description'=>vp3_browser_context_text_v2130($meta['description']??'',1000),
            'author'=>vp3_browser_context_text_v2130($meta['author']??'',240),
            'site_name'=>vp3_browser_context_text_v2130($meta['site_name']??'',240),
            'language'=>vp3_browser_context_text_v2130($meta['language']??'',32),
        ],
        'media'=>$cleanMedia,
        'captured_at'=>gmdate(DATE_ATOM),
    ];
}

function vp3_browser_context_terms_v2130(array $context): array
{
    $stop=array_fill_keys([
        'about','after','again','also','and','are','because','been','before','being','between','but','can','could',
        'does','from','have','into','its','more','most','not','only','other','our','out','over','page','site','some',
        'than','that','the','their','them','then','there','these','they','this','through','under','very','was','were',
        'what','when','where','which','while','who','with','would','www','you','your','https','http','com','org','net'
    ],true);
    $domain=preg_replace('/^www\./i','',(string)($context['domain']??''))??'';
    $domainWords=preg_replace('/\.[a-z0-9-]{2,24}$/i','',$domain)??$domain;
    $text=implode(' ',[
        (string)($context['title']??''),
        str_replace(['.','-','_'],' ',$domainWords),
        mb_substr((string)($context['selected_text']??''),0,2200),
        (string)($context['metadata']['description']??''),
        (string)($context['metadata']['site_name']??''),
        (string)($context['metadata']['author']??''),
    ]);
    $words=preg_split('/[^\pL\pN@.-]+/u',mb_strtolower($text))?:[];
    $terms=[];
    foreach($words as $word){
        $word=trim($word," .-@");
        if($word===''||mb_strlen($word)<3||isset($stop[$word])||ctype_digit($word))continue;
        $terms[$word]=true;
        if(count($terms)>=18)break;
    }
    return array_keys($terms);
}

function vp3_browser_context_score_v2130(string $haystack,array $terms): int
{
    $haystack=mb_strtolower($haystack);
    $score=0;
    foreach($terms as $term){
        if($term!==''&&str_contains($haystack,$term))$score+=mb_strlen($term)>=7?3:2;
    }
    return $score;
}

function vp3_browser_context_source_v2130(PDO $pdo,array $user,array $context): array
{
    if(!vp3_browser_source_feed_schema_ready_v2050($pdo))return ['source'=>null,'annotations'=>[],'conversations'=>[]];
    try{
        $page=vp3_browser_source_this_page_v2050(
            $pdo,(int)$user['id'],(string)$context['source_url'],(string)$context['canonical_url'],
            (string)$context['title'],12,'',(string)$context['page_text_sha256']
        );
    }catch(Throwable $e){
        error_log('VP3 Browser Context source lookup unavailable: '.$e->getMessage());
        return ['source'=>null,'annotations'=>[],'conversations'=>[]];
    }
    $source=is_array($page['source']??null)?$page['source']:null;
    $items=array_values(array_filter((array)($page['items']??[]),'is_array'));
    $conversationMap=[];
    foreach($items as $item){
        $conversationId=max(0,(int)($item['conversation_id']??0));
        if($conversationId<1)continue;
        $sender=is_array($item['sender']??null)?$item['sender']:[];
        if(!isset($conversationMap[$conversationId]))$conversationMap[$conversationId]=[
            'type'=>'team_conversation',
            'id'=>$conversationId,
            'title'=>'Conversation about this source',
            'detail'=>'Authorized VP3 messages reference this page.',
            'count'=>0,
            'people'=>[],
            'url'=>'/messages.php?conversation_id='.$conversationId,
        ];
        $conversationMap[$conversationId]['count']++;
        $name=trim((string)($sender['name']??''));
        if($name!=='')$conversationMap[$conversationId]['people'][$name]=true;
    }
    foreach($conversationMap as &$row)$row['people']=array_keys($row['people']);
    unset($row);
    return ['source'=>$source,'annotations'=>$items,'conversations'=>array_values($conversationMap)];
}

function vp3_browser_context_research_v2130(PDO $pdo,array $user,?array $source): array
{
    $sourcePublic=trim((string)($source['id']??''));
    if($sourcePublic===''||!vp3_research_schema_ready_v2060($pdo))return [];
    $sourceRow=vp3_browser_source_row_by_public_id_v2050($pdo,$sourcePublic);
    if(!$sourceRow)return [];
    $stmt=$pdo->prepare("SELECT DISTINCT p.id
      FROM research_project_items_v2060 i
      INNER JOIN research_projects_v2060 p ON p.id=i.project_id
      WHERE i.source_id=? AND i.item_status='active' AND p.deleted_at IS NULL AND p.project_status='active'
      ORDER BY p.updated_at DESC LIMIT 20");
    $stmt->execute([(int)$sourceRow['id']]);
    $out=[];
    foreach($stmt->fetchAll(PDO::FETCH_COLUMN)?:[] as $projectId){
        $row=vp3_research_project_row_by_id_v2060($pdo,(int)$projectId);
        if(!$row||vp3_research_project_role_v2060($pdo,$row,(int)$user['id'])==='')continue;
        $public=vp3_research_project_public_v2060($pdo,$row,(int)$user['id']);
        $out[]=[
            'type'=>'research',
            'id'=>(string)$public['id'],
            'title'=>(string)$public['title'],
            'detail'=>'This source is already in this Research project.',
            'role'=>(string)$public['role'],
            'url'=>(string)$public['url'],
        ];
        if(count($out)>=5)break;
    }
    return $out;
}

function vp3_browser_context_knowledge_v2130(array $user,array $context,array $terms): array
{
    if(!$terms)return [];
    $query=implode(' ',array_slice($terms,0,8));
    $rows=search_knowledge($query,$user,5);
    $out=[];
    foreach($rows as $row){
        $out[]=[
            'type'=>'knowledge',
            'id'=>(int)($row['id']??0),
            'title'=>vp3_browser_context_text_v2130($row['title']??'Knowledge',190),
            'detail'=>vp3_browser_context_text_v2130($row['description']??'Related VP3 Knowledge',360),
            'scope'=>(string)($row['knowledge_scope']??'system'),
            'score'=>(int)($row['score']??0),
            'url'=>'/knowledge.php',
        ];
    }
    return $out;
}

function vp3_browser_context_calendar_v2130(PDO $pdo,array $user,array $terms): array
{
    if(!$terms||!user_calendar_schema_ready_v1300($pdo))return [];
    try{
        $from=gmdate('Y-m-d H:i:s',time()-86400);
        $to=gmdate('Y-m-d H:i:s',time()+45*86400);
        $events=user_calendar_events_v1300($pdo,$user,$from,$to);
    }catch(Throwable $e){return [];}
    $scored=[];
    foreach($events as $event){
        $hay=implode(' ',[
            (string)($event['title']??''),(string)($event['description']??''),(string)($event['location']??''),
            (string)($event['guest_name']??'')
        ]);
        $score=vp3_browser_context_score_v2130($hay,$terms);
        if($score<2)continue;
        $event['_context_score']=$score;
        $scored[]=$event;
    }
    usort($scored,static fn(array $a,array $b):int=>(int)$b['_context_score']<=>(int)$a['_context_score']);
    $out=[];
    foreach(array_slice($scored,0,4) as $event){
        $kind=(string)($event['kind']??'event');
        $out[]=[
            'type'=>$kind==='booking'?'meeting':'calendar',
            'id'=>(int)($event['id']??0),
            'title'=>(string)($event['title']??'Upcoming calendar item'),
            'detail'=>($kind==='booking'&&trim((string)($event['guest_name']??''))!==''
                ?'Meeting with '.trim((string)$event['guest_name']).' · ':'').
                trim((string)($event['start_at_utc']??'')),
            'score'=>(int)$event['_context_score'],
            'url'=>'/calendar.php',
        ];
    }
    return $out;
}

function vp3_browser_context_profiles_v2130(PDO $pdo,array $context,array $terms): array
{
    if(!table_exists('user_profiles'))return [];
    $domain=strtolower(preg_replace('/^www\./i','',(string)($context['domain']??''))??'');
    if($domain===''&&!$terms)return [];
    try{
        $rows=$pdo->query("SELECT p.user_id,p.username,p.bio,p.website_url,u.display_name,u.role
          FROM user_profiles p INNER JOIN users u ON u.id=p.user_id
          WHERE p.is_public=1 AND u.is_active=1 AND p.username<>''
          ORDER BY p.updated_at DESC LIMIT 250")->fetchAll()?:[];
    }catch(Throwable $e){return [];}
    $scored=[];
    foreach($rows as $row){
        $websiteHost='';
        $website=trim((string)($row['website_url']??''));
        if($website!==''){
            $websiteHost=strtolower((string)(parse_url($website,PHP_URL_HOST)??''));
            $websiteHost=preg_replace('/^www\./i','',$websiteHost)??$websiteHost;
        }
        $hay=implode(' ',[(string)$row['display_name'],(string)$row['username'],(string)$row['bio'],$websiteHost]);
        $score=vp3_browser_context_score_v2130($hay,$terms);
        if($domain!==''&&$websiteHost!==''&&($websiteHost===$domain||str_ends_with($domain,'.'.$websiteHost)||str_ends_with($websiteHost,'.'.$domain)))$score+=10;
        if($score<4)continue;
        $row['_context_score']=$score;$scored[]=$row;
    }
    usort($scored,static fn(array $a,array $b):int=>(int)$b['_context_score']<=>(int)$a['_context_score']);
    $out=[];
    foreach(array_slice($scored,0,4) as $row){
        $out[]=[
            'type'=>'profile',
            'id'=>(int)$row['user_id'],
            'title'=>trim((string)$row['display_name'])?:('@'.(string)$row['username']),
            'detail'=>'Public VP3 profile · @'.(string)$row['username'],
            'score'=>(int)$row['_context_score'],
            'url'=>'/profile.php?u='.rawurlencode((string)$row['username']),
        ];
    }
    return $out;
}

function vp3_browser_context_crm_v2130(PDO $pdo,array $user,array $context,array $terms): array
{
    if(!crm_v180_can_manage($user)||!crm_v180_schema_ready($pdo))return [];
    $domain=preg_replace('/^www\./i','',(string)$context['domain'])??'';
    try{
        $rows=$pdo->query("SELECT c.id contact_id,c.name,c.email,c.company,l.id lead_id,l.stage,l.priority,l.next_follow_up_at,l.demo_scheduled_at
          FROM crm_contacts c LEFT JOIN crm_leads l ON l.contact_id=c.id
          ORDER BY COALESCE(l.updated_at,c.updated_at) DESC LIMIT 200")->fetchAll()?:[];
    }catch(Throwable $e){return [];}
    $scored=[];
    foreach($rows as $row){
        $emailDomain=strtolower((string)substr(strrchr((string)($row['email']??''),'@')?:'',1));
        $hay=implode(' ',[(string)$row['name'],(string)$row['email'],(string)$row['company']]);
        $score=vp3_browser_context_score_v2130($hay,$terms);
        if($domain!==''&&$emailDomain!==''&&($emailDomain===$domain||str_ends_with($domain,'.'.$emailDomain)||str_ends_with($emailDomain,'.'.$domain)))$score+=8;
        if($score<3)continue;
        $row['_context_score']=$score;$scored[]=$row;
    }
    usort($scored,static fn(array $a,array $b):int=>(int)$b['_context_score']<=>(int)$a['_context_score']);
    $out=[];$seen=[];
    foreach($scored as $row){
        $contactId=(int)$row['contact_id'];if(isset($seen[$contactId]))continue;$seen[$contactId]=true;
        $leadId=(int)($row['lead_id']??0);
        $out[]=[
            'type'=>'contact',
            'id'=>$contactId,
            'title'=>trim((string)$row['name']).(trim((string)$row['company'])!==''?' · '.trim((string)$row['company']):''),
            'detail'=>$leadId>0?'CRM lead · '.str_replace('_',' ',(string)($row['stage']??'new')):'CRM contact',
            'score'=>(int)$row['_context_score'],
            'url'=>$leadId>0?'/admin/crm-lead.php?id='.$leadId:'/admin/crm.php',
        ];
        if(count($out)>=4)break;
    }
    return $out;
}

function vp3_browser_context_relationships_v2130(PDO $pdo,array $user,array $context,?array $extensionCapabilities=null): array
{
    $terms=vp3_browser_context_terms_v2130($context);
    $allowSourceActivity=$extensionCapabilities===null||in_array('team.chat.read',$extensionCapabilities,true);
    $sourceBundle=$allowSourceActivity
        ?vp3_browser_context_source_v2130($pdo,$user,$context)
        :['source'=>null,'annotations'=>[],'conversations'=>[]];
    $source=is_array($sourceBundle['source']??null)?$sourceBundle['source']:null;
    return [
        'source'=>$source,
        'annotations'=>$allowSourceActivity?array_slice((array)$sourceBundle['annotations'],0,8):[],
        'team_conversations'=>$allowSourceActivity?array_slice((array)$sourceBundle['conversations'],0,5):[],
        'research'=>vp3_browser_context_research_v2130($pdo,$user,$source),
        'knowledge'=>vp3_browser_context_knowledge_v2130($user,$context,$terms),
        'calendar'=>vp3_browser_context_calendar_v2130($pdo,$user,$terms),
        'profiles'=>vp3_browser_context_profiles_v2130($pdo,$context,$terms),
        'contacts'=>vp3_browser_context_crm_v2130($pdo,$user,$context,$terms),
        'terms'=>$terms,
    ];
}

function vp3_browser_context_insights_v2130(array $relations): array
{
    $out=[];
    $annotations=count((array)($relations['annotations']??[]));
    $conversations=count((array)($relations['team_conversations']??[]));
    if($annotations>0)$out[]='VP3 already has '.$annotations.' authorized annotation'.($annotations===1?'':'s').' on this source.';
    if($conversations>0)$out[]=$conversations.' authorized Team conversation'.($conversations===1?' references':'s reference').' this source.';
    if(!empty($relations['research'][0]['title']))$out[]='This source is already part of Research: '.vp3_browser_context_text_v2130($relations['research'][0]['title'],140).'.';
    if(!empty($relations['calendar'][0]['title']))$out[]='This page appears related to an upcoming calendar item: '.vp3_browser_context_text_v2130($relations['calendar'][0]['title'],140).'.';
    if(!empty($relations['profiles'][0]['title']))$out[]='This page matches the public VP3 profile for '.vp3_browser_context_text_v2130($relations['profiles'][0]['title'],140).'.';
    if(!empty($relations['contacts'][0]['title']))$out[]='This page matches a CRM relationship: '.vp3_browser_context_text_v2130($relations['contacts'][0]['title'],140).'.';
    if(!empty($relations['knowledge'][0]['title']))$out[]='Related VP3 Knowledge is available: '.vp3_browser_context_text_v2130($relations['knowledge'][0]['title'],140).'.';
    return array_slice($out,0,4);
}

function vp3_browser_context_suggestions_v2130(array $session,array $context,array $relations): array
{
    $caps=array_fill_keys((array)($session['capabilities']??[]),true);
    $suggestions=[
        ['id'=>'ask_page','kind'=>'agent_prompt','label'=>'Ask Agent about this page','prompt'=>'Review this page with me. Start with what is most relevant to my current VP3 work.'],
        ['id'=>'summarize','kind'=>'agent_prompt','label'=>'Summarize page','prompt'=>'Summarize this page and identify the parts that matter most to my current work.'],
    ];
    if(!empty($relations['knowledge']))$suggestions[]=['id'=>'compare_knowledge','kind'=>'agent_prompt','label'=>'Compare with Knowledge','prompt'=>'Compare this page with my authorized VP3 Knowledge. Call out agreements, conflicts, and useful connections.'];
    if(!empty($relations['calendar']))$suggestions[]=['id'=>'prepare_meeting','kind'=>'agent_prompt','label'=>'Prepare for related meeting','prompt'=>'Use this page as context for the related upcoming meeting or calendar item. Give me a concise preparation brief.'];
    if(!empty($relations['research']))$suggestions[]=['id'=>'review_research','kind'=>'open','label'=>'Open related Research','url'=>(string)$relations['research'][0]['url']];
    if(isset($caps['knowledge.write']))$suggestions[]=['id'=>'add_research','kind'=>'agent_prompt','label'=>'Add to Research','prompt'=>'I want to add useful context from this page to a VP3 Research project. Show me the appropriate project or choices and ask for confirmation before creating or saving anything.'];
    if(!empty($relations['team_conversations']))$suggestions[]=['id'=>'review_team','kind'=>'open','label'=>'Review Team discussion','url'=>(string)$relations['team_conversations'][0]['url']];
    if(!empty($relations['profiles']))$suggestions[]=['id'=>'review_profile','kind'=>'open','label'=>'Open related profile','url'=>(string)$relations['profiles'][0]['url']];
    if(!empty($relations['contacts']))$suggestions[]=['id'=>'review_contact','kind'=>'open','label'=>'Review related contact','url'=>(string)$relations['contacts'][0]['url']];
    if(isset($caps['team.share.create']))$suggestions[]=['id'=>'share_team','kind'=>'manual_flow','label'=>'Share with Team','target'=>'this_page'];
    if(isset($caps['knowledge.write']))$suggestions[]=['id'=>'save_knowledge','kind'=>'agent_prompt','label'=>'Save to Knowledge','prompt'=>'I want to save useful context from this page to my VP3 Knowledge. Show me what would be saved and ask for confirmation before creating anything.'];
    if(isset($caps['task.propose']))$suggestions[]=['id'=>'create_task','kind'=>'agent_prompt','label'=>'Create task','prompt'=>'Propose a task based on this page. Show me the task title and details before creating anything.'];
    if(isset($caps['team.chat.read'])&&is_array($relations['source']??null)&&!empty($relations['source']['id']))$suggestions[]=['id'=>'follow_source','kind'=>'manual_follow','label'=>!empty($relations['source']['following'])?'Unfollow source':'Follow source'];
    return array_slice($suggestions,0,10);
}

function vp3_browser_context_card_text_v2130(array $item): string
{
    $card=is_array($item['card']??null)?$item['card']:[];
    $parts=[(string)($item['reason']??''),(string)($card['title']??''),(string)($card['subtitle']??''),(string)($card['summary']??'')];
    foreach((array)($card['facts']??[]) as $fact)if(is_array($fact))$parts[]=(string)($fact['label']??'').' '.(string)($fact['value']??'');
    foreach((array)($card['sections']??[]) as $section)if(is_array($section)){
        $parts[]=(string)($section['label']??'').' '.(string)($section['text']??'').' '.implode(' ',(array)($section['items']??[]));
    }
    return implode(' ',$parts);
}

function vp3_browser_contextualize_feed_v2130(array $feed,array $context,array $relations): array
{
    $terms=(array)($relations['terms']??[]);
    $related=0;
    foreach($feed['sections']??[] as &$section){
        if(!is_array($section))continue;
        foreach($section['items']??[] as $index=>&$item){
            if(!is_array($item))continue;
            $score=vp3_browser_context_score_v2130(vp3_browser_context_card_text_v2130($item),$terms);
            $item['_original_order']=$index;
            $item['context_score']=$score;
            if($score>0){
                $related++;
                $item['context_reason']='Related to the current page context';
            }
        }unset($item);
        usort($section['items'],static function(array $a,array $b): int {
            $score=(int)($b['context_score']??0)<=>(int)($a['context_score']??0);
            return $score!==0?$score:(int)($a['_original_order']??0)<=>(int)($b['_original_order']??0);
        });
        foreach($section['items'] as &$item)unset($item['_original_order']);
        unset($item);
    }unset($section);

    $feed['context']=[
        'ephemeral'=>true,
        'title'=>(string)$context['title'],
        'domain'=>(string)$context['domain'],
        'url'=>(string)$context['source_url'],
        'selected'=>trim((string)$context['selected_text'])!=='',
        'ignored'=>false,
    ];
    $feed['contextual_item_count']=$related;
    $feed['relationships']=[
        'source'=>$relations['source']??null,
        'annotation_count'=>count((array)($relations['annotations']??[])),
        'team_conversations'=>array_values((array)($relations['team_conversations']??[])),
        'research'=>array_values((array)($relations['research']??[])),
        'knowledge'=>array_values((array)($relations['knowledge']??[])),
        'calendar'=>array_values((array)($relations['calendar']??[])),
        'profiles'=>array_values((array)($relations['profiles']??[])),
        'contacts'=>array_values((array)($relations['contacts']??[])),
        'insights'=>vp3_browser_context_insights_v2130($relations),
    ];
    return $feed;
}

function vp3_browser_context_agent_payload_v2130(array $context,array $relations,string $prompt=''): array
{
    $relationshipSummary=[];
    foreach(['research','knowledge','calendar','profiles','contacts','team_conversations'] as $key){
        foreach(array_slice((array)($relations[$key]??[]),0,3) as $row){
            if(!is_array($row))continue;
            $relationshipSummary[]=[
                'type'=>(string)($row['type']??$key),
                'title'=>vp3_browser_context_text_v2130($row['title']??'',190),
                'detail'=>vp3_browser_context_text_v2130($row['detail']??'',420),
            ];
        }
    }
    return [
        'contract'=>'browser-context-v2130',
        'ephemeral'=>true,
        'page'=>[
            'url'=>(string)$context['source_url'],
            'canonical_url'=>(string)$context['canonical_url'],
            'title'=>(string)$context['title'],
            'domain'=>(string)$context['domain'],
            'selected_text'=>(string)$context['selected_text'],
            'metadata'=>$context['metadata'],
            'media'=>$context['media'],
        ],
        'relationships'=>$relationshipSummary,
        'prompt'=>vp3_browser_context_text_v2130($prompt,1200),
    ];
}
