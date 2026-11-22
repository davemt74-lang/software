<?php
declare(strict_types=1);

/** Private provenance and creative continuity; Campaigns remains the mutation authority. */
const VP3_MARKETING_BRIEF_ROLE_V1='private_marketing_brief_v1';
const VP3_MARKETING_BRIEF_CONTRACT_V1='vp3.marketing.campaign-brief.v1';

function vp3_marketing_brief_ready_v1(PDO $pdo): bool
{
    if(!function_exists('schema_metadata_table_exists'))require_once __DIR__.'/schema-metadata.php';
    return schema_metadata_table_exists($pdo,'campaign_creative_assets');
}

function vp3_marketing_brief_binding_v1(mixed $raw): ?array
{
    if($raw===null)return null;
    if(!is_array($raw)||array_is_list($raw)||count($raw)!==2
        ||!is_string($raw['source_ref']??null)
        ||!preg_match('/\Amarketing:(?:product-plan|campaign-outcome):[1-9][0-9]{0,15}\z/D',$raw['source_ref'])
        ||!is_string($raw['source_revision']??null)
        ||!preg_match('/\A[a-f0-9]{64}\z/D',$raw['source_revision']))
        throw new DomainException('Choose a verified product or completed Campaign brief.',422);
    return ['source_ref'=>$raw['source_ref'],'source_revision'=>$raw['source_revision']];
}

/** Exact re-read bypasses discovery limits and accepts no browser-written brief text. */
function vp3_marketing_brief_source_v1(PDO $pdo,int $owner,array $scope,array $binding): array
{
    vp3_marketing_editor_scope_v1($pdo,$owner,$scope);
    if(!vp3_marketing_brief_ready_v1($pdo))throw new DomainException('Private Campaign brief storage is unavailable.',503);
    if(!function_exists('vp3_coordination_marketing_sources_v1'))require_once __DIR__.'/agent-coordination-marketing-v1.php';
    require_once __DIR__.'/agent-marketing-opportunity-portfolio-v1.php';
    $snapshot=vp3_coordination_marketing_sources_v1($pdo,$scope,[substr($binding['source_ref'],10)],
        new DateTimeImmutable('now',new DateTimeZone('UTC')));
    $portfolio=vp3_marketing_opportunity_portfolio_v1($snapshot);
    foreach($portfolio['opportunities'] as $item){
        if($item['source_ref']===$binding['source_ref']
            &&hash_equals($item['source_revision'],$binding['source_revision'])
            &&is_array($item['private_brief']??null))return $item;
    }
    throw new DomainException('This opportunity changed. Refresh Marketing and review its current brief.',409);
}

function vp3_marketing_brief_row_v1(PDO $pdo,int $owner,array $scope,int $campaign): ?array
{
    if(!vp3_marketing_brief_ready_v1($pdo))return null;
    $q=$pdo->prepare('SELECT id,campaign_id,created_by_user_id,media_ref,content_json FROM campaign_creative_assets
        WHERE campaign_id=? AND created_by_user_id=? AND asset_role=? AND is_active=1 ORDER BY id DESC LIMIT 12');
    $q->execute([$campaign,$owner,VP3_MARKETING_BRIEF_ROLE_V1]);
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
        if(!is_string($row['content_json'])||strlen($row['content_json'])>16000)continue;
        try{$record=json_decode($row['content_json'],true,16,JSON_THROW_ON_ERROR);}
        catch(JsonException){continue;}
        if(!is_array($record)||($record['contract']??null)!==VP3_MARKETING_BRIEF_CONTRACT_V1
            ||($record['agent_id']??null)!==$scope['agent_id']
            ||($record['conversation_id']??null)!==$scope['conversation_id']
            ||($record['owner_user_id']??null)!==$owner||!is_array($record['brief']??null)
            ||!is_string($record['source_title']??null)||strlen($record['source_title'])>640)continue;
        try{$binding=vp3_marketing_brief_binding_v1($record['source']??false);}
        catch(DomainException){continue;}
        if($binding===null||$row['media_ref']!=='brief:'.$binding['source_ref'])continue;
        $brief=$record['brief'];
        if(($brief['contract']??null)!=='vp3.marketing.private-brief.v1'
            ||($brief['state']??null)!=='private_proposal_only'
            ||($brief['source_ref']??null)!==$binding['source_ref']
            ||($brief['source_revision']??null)!==$binding['source_revision']
            ||!is_string($brief['objective']??null)||strlen($brief['objective'])>2800
            ||array_filter(['creative_generated','campaign_created','activated','sent'],
                static fn(string $flag):bool=>($brief[$flag]??null)!==false))continue;
        return ['id'=>(int)$row['id'],'record'=>$record];
    }
    return null;
}

/** Called inside the exact reviewed Campaign-save transaction, once per receipt. */
function vp3_marketing_brief_save_v1(PDO $pdo,int $owner,array $scope,int $campaign,array $source): void
{
    if(!vp3_marketing_brief_ready_v1($pdo))throw new DomainException('Private Campaign brief storage is unavailable.',503);
    if(vp3_marketing_brief_row_v1($pdo,$owner,$scope,$campaign)!==null)
        throw new DomainException('This Campaign already has a saved source brief.',409);
    $record=['contract'=>VP3_MARKETING_BRIEF_CONTRACT_V1,'owner_user_id'=>$owner,
        'agent_id'=>$scope['agent_id'],'conversation_id'=>$scope['conversation_id'],
        'source'=>['source_ref'=>$source['source_ref'],'source_revision'=>$source['source_revision']],
        'source_title'=>$source['title'],'brief'=>$source['private_brief'],'work'=>null];
    $pdo->prepare('INSERT INTO campaign_creative_assets
        (campaign_id,asset_role,media_ref,content_json,created_by_user_id) VALUES(?,?,?,?,?)')
        ->execute([$campaign,VP3_MARKETING_BRIEF_ROLE_V1,'brief:'.$source['source_ref'],
            json_encode($record,JSON_THROW_ON_ERROR),$owner]);
}

function vp3_marketing_brief_campaign_v1(PDO $pdo,int $owner,array $scope,int $campaign): array
{
    if(!function_exists('vp3_marketing_editor_scope_v1'))require_once __DIR__.'/initiative-marketing-editor-v1.php';
    vp3_marketing_editor_scope_v1($pdo,$owner,$scope);
    $q=$pdo->prepare('SELECT merchant_id FROM campaigns WHERE id=?');$q->execute([$campaign]);
    $merchant=(int)$q->fetchColumn();
    if($merchant<1)throw new DomainException('This Campaign is unavailable.',404);
    $draft=vp3_marketing_editor_draft_v1($pdo,$owner,$merchant,$campaign,$pdo->inTransaction());
    $link=vp3_marketing_brief_row_v1($pdo,$owner,$scope,$campaign);
    if($link===null)throw new DomainException('This Chat has no saved brief for this private Campaign.',404);
    return ['campaign'=>$draft,'link'=>$link];
}

function vp3_marketing_brief_context_binding_v1(mixed $raw): array
{
    if(!is_array($raw)||array_is_list($raw)||count($raw)!==3
        ||!is_int($raw['campaign_id']??null)||$raw['campaign_id']<1||$raw['campaign_id']>9007199254740991
        ||!is_int($raw['brief_id']??null)||$raw['brief_id']<1||$raw['brief_id']>9007199254740991
        ||!is_string($raw['campaign_revision']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$raw['campaign_revision']))
        throw new DomainException('Reopen the saved Campaign brief before preparing creative.',422);
    return ['campaign_id'=>$raw['campaign_id'],'brief_id'=>$raw['brief_id'],'campaign_revision'=>$raw['campaign_revision']];
}

/** Current Campaign, exact Chat and selected product are rechecked before every provider admission. */
function vp3_marketing_brief_context_v1(PDO $pdo,int $owner,array $scope,mixed $raw,int $product): array
{
    $binding=vp3_marketing_brief_context_binding_v1($raw);
    $checked=vp3_marketing_brief_campaign_v1($pdo,$owner,$scope,$binding['campaign_id']);
    if($checked['link']['id']!==$binding['brief_id']
        ||!hash_equals($checked['campaign']['revision'],$binding['campaign_revision']))
        throw new DomainException('The Campaign changed. Review its current brief before creating more creative.',409);
    $ref=$checked['link']['record']['source']['source_ref'];
    if(str_starts_with($ref,'marketing:product-plan:')&&(int)substr($ref,strrpos($ref,':')+1)!==$product)
        throw new DomainException('Choose the product named in this saved brief.',409);
    return $checked+['binding'=>$binding];
}

/** Saved copy is linked as private work, never as a public landing asset or approval. */
function vp3_marketing_brief_attach_work_v1(PDO $pdo,int $owner,array $scope,array $binding,array $draft): void
{
    $checked=vp3_marketing_brief_context_v1($pdo,$owner,$scope,$binding,$draft['product_id']);
    $record=$checked['link']['record'];
    $record['work']=['draft_id'=>$draft['draft_id'],'product_id'=>$draft['product_id'],
        'source_id'=>(string)$draft['asset_id'],'campaign_revision'=>$binding['campaign_revision']];
    $pdo->prepare('UPDATE campaign_creative_assets SET content_json=? WHERE id=? AND campaign_id=? AND created_by_user_id=? AND is_active=1')
        ->execute([json_encode($record,JSON_THROW_ON_ERROR),$binding['brief_id'],$binding['campaign_id'],$owner]);
}

function vp3_marketing_brief_view_v1(PDO $pdo,int $owner,array $scope,int $campaign): array
{
    $checked=vp3_marketing_brief_campaign_v1($pdo,$owner,$scope,$campaign);
    $draft=$checked['campaign'];$record=$checked['link']['record'];
    $binding=['campaign_id'=>$campaign,'brief_id'=>$checked['link']['id'],'campaign_revision'=>$draft['revision']];
    $work=$record['work']??null;$state='brief_saved';$resume=null;
    if(is_array($work)){
        $state='needs_review';
        if(($work['campaign_revision']??null)===$draft['revision']
            &&is_int($work['product_id']??null)&&$work['product_id']>0
            &&is_string($work['draft_id']??null)&&preg_match('/\A[a-f0-9]{64}\z/D',$work['draft_id'])
            &&is_string($work['source_id']??null)&&preg_match('/\A[1-9][0-9]{0,18}\z/D',$work['source_id'])){
            try{
                if(!function_exists('initiative_work_marketing_draft_v1'))require_once __DIR__.'/initiative-work-marketing-v1.php';
                $copy=initiative_work_marketing_draft_v1($pdo,$owner,$work['product_id'],$work['draft_id']);
                if(($copy['campaign_brief']??null)!==$binding||(string)($copy['asset_id']??'')!==$work['source_id'])
                    throw new DomainException('The saved creative binding changed.',409);
                $resume=['product_id'=>$work['product_id'],'draft_id'=>$work['draft_id'],'source_id'=>$work['source_id'],'creative_id'=>''];
                $state='copy_saved';
                if(!function_exists('initiative_marketing_plan_view_v1'))require_once __DIR__.'/initiative-marketing-plans-v1.php';
                $q=$pdo->prepare(initiative_marketing_plans_query_v1().' AND l.product_id=? AND l.conversation_id=? AND l.draft_key=? LIMIT 1');
                $q->execute([$owner,$scope['agent_id'],$work['product_id'],$scope['conversation_id'],$work['draft_id']]);
                $row=$q->fetch(PDO::FETCH_ASSOC);
                if($row){
                    $plan=initiative_marketing_plan_view_v1($pdo,$owner,$row);
                    if(is_array($plan['resume']??null)){
                        $creative=$plan['resume']['creative_id']??'';
                        if(is_string($creative)&&preg_match('/\A[a-f0-9]{32}\z/D',$creative))$resume['creative_id']=$creative;
                        $stage=$plan['stage']??'';
                        if(in_array($stage,['creative_ready','publication_review'],true))$state='creative_ready';
                        elseif(in_array($stage,['image_starting','image_pending'],true))$state='creating_image';
                        elseif(in_array($stage,['creative_failed','needs_attention'],true)){$state='needs_review';$resume=null;}
                    }
                }
            }catch(DomainException|RuntimeException $error){
                if($error instanceof PDOException||!in_array((int)$error->getCode(),[403,404,409,503],true))throw $error;
                $state='needs_review';$resume=null;
            }
        }
    }
    $fresh=vp3_marketing_brief_campaign_v1($pdo,$owner,$scope,$campaign);
    if($fresh['campaign']['revision']!==$draft['revision']||$fresh['link']!==$checked['link'])
        throw new DomainException('Campaign creative changed. Refresh its saved brief.',409);
    return ['contract'=>VP3_MARKETING_BRIEF_CONTRACT_V1,'binding'=>$binding,
        'campaign_id'=>$campaign,'title'=>$draft['fields']['title'],'objective'=>$draft['fields']['objective'],
        'source_ref'=>$record['source']['source_ref'],'source_revision'=>$record['source']['source_revision'],
        'source_title'=>$record['source_title'],'brief_objective'=>(string)($record['brief']['objective']??''),
        'state'=>$state,'resume'=>$resume,'href'=>$draft['manage_url'],'private_only'=>true];
}

/** Feed semantic milestones into the existing Marketing loop and shared Chat/Brain delivery. */
function vp3_marketing_brief_sources_v1(PDO $pdo,array $scope,?array $refs,DateTimeImmutable $now): array
{
    $out=['items'=>[],'coverage'=>'unconfigured'];
    $owner=vp3_coordination_marketing_owner_v1($pdo,$scope);
    if($owner===null||!vp3_marketing_brief_ready_v1($pdo))return $out;
    $ids=[];
    foreach($refs??[] as $ref)if(preg_match('/\Acampaign-brief:([1-9][0-9]{0,15})\z/D',$ref,$m))$ids[]=(int)$m[1];
    if($refs!==null&&!$ids)return $out;
    $params=[$owner,VP3_MARKETING_BRIEF_ROLE_V1];
    $filter=$ids?' AND campaign_id IN ('.implode(',',array_fill(0,count($ids),'?')).')':'';
    array_push($params,...$ids);
    $q=$pdo->prepare('SELECT campaign_id FROM campaign_creative_assets WHERE created_by_user_id=? AND asset_role=? AND is_active=1'
        .$filter.' ORDER BY id DESC LIMIT 25');$q->execute($params);
    $rows=$q->fetchAll(PDO::FETCH_COLUMN);$out['coverage']=count($rows)>24?'limited':'ready';
    foreach(array_unique(array_map('intval',array_slice($rows,0,24))) as $id){
        try{$view=vp3_marketing_brief_view_v1($pdo,$owner,$scope,$id);}
        catch(DomainException $error){if(!in_array($error->getCode(),[403,404,409,503],true))throw $error;continue;}
        $messages=match($view['state']){
            'brief_saved'=>['A private Campaign draft has its verified source brief saved.','Choose its product and photo to prepare private creative.'],
            'copy_saved'=>['Private Campaign copy is saved and linked to its source brief.','Continue the saved creative and review a private graphic.'],
            'creating_image'=>['The Campaign-linked image job is still in progress.','Check its existing image job; no duplicate job is needed.'],
            'creative_ready'=>['Saved Campaign-linked creative is ready for owner review.','Review the private creative and Campaign details before approving any public use.'],
            default=>['The saved Campaign creative needs a fresh review.','Open the current Campaign brief and verify its product, photo and Campaign details.'],
        };
        $trigger=match($view['state']){'creative_ready'=>'creative_review_ready','needs_review'=>'setup_blocker',
            default=>'campaign_creative_progress'};
        $question=match($view['state']){'creative_ready'=>'Would you like to review the private creative for this Campaign?',
            'needs_review'=>'Would you like to review the changed Campaign and its saved creative?',default=>''};
        $ref='marketing:campaign-brief:'.$id;
        $out['items'][$ref]=vp3_coordination_source_item_v1($ref,'marketing',$view['title'].' · Campaign creative',
            $messages[0],$messages[1],$question,$view['href'],'open','normal',
            [$view['binding'],$view['state'],$view['resume']])+['trigger'=>$trigger];
    }
    return $out;
}
