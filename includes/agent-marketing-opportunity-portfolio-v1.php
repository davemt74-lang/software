<?php
declare(strict_types=1);

/**
 * Marketing phase 3A: deterministic, read-only opportunity portfolio derived
 * entirely from the current owner/Agent/Chat marketing source snapshot.
 *
 * No contact records, pricing guesses, audience inference, automated publishing,
 * new DB tables, parallel workers or provider calls. The coordination loop
 * remains the sole Chat notifier and follow-through owner.
 */
const VP3_MARKETING_OPPORTUNITY_PORTFOLIO_V1='vp3.marketing.opportunity-portfolio.v1';

function vp3_marketing_portfolio_text_v1(mixed $value,int $limit): string
{
    if(!is_string($value)||$value===''||!mb_check_encoding($value,'UTF-8'))return '';
    $text=strip_tags($value);
    $text=preg_replace('~(?:https?://|\bwww\.)[^\s<>]+~iu','[source link]',$text)??'';
    $text=preg_replace('/[<>\p{Cc}\p{Cf}]+/u',' ',$text)??'';
    $text=preg_replace('/\s+/u',' ',trim($text))??'';
    return mb_substr($text,0,$limit,'UTF-8');
}

function vp3_marketing_portfolio_href_v1(mixed $url,string $kind,int $id): ?string
{
    if(!is_string($url)||strlen($url)>800||str_contains($url,'\\')||str_starts_with($url,'//')
        ||preg_match('/[\x00-\x1f\x7f]/',$url)||str_contains($url,'%'))return null;
    $base='(?:[a-zA-Z0-9_-]+/)*';
    if($kind==='product-plan'){
        return preg_match('~\A/'.$base.'chat\.php\?agent=(?:system|[1-9][0-9]*)'
            .'&conversation_id=[1-9][0-9]*&marketing_plan='.$id.'\z~D',$url)===1?$url:null;
    }
    if(!str_starts_with($kind,'campaign-'))return null;
    return preg_match('~\A/'.$base.'campaigns\.php\?merchant=[1-9][0-9]*'
        .'&edit_campaign=[1-9][0-9]*'
        .'(?:#campaign-optimization-v126|#campaign-editor|#marketing-offer-draft)?\z~D',$url)===1?$url:null;
}

/** Strictly bound, ranked recommendations. A limited snapshot stays limited. */
function vp3_marketing_opportunity_portfolio_v1(array $snapshot,int $maximum=6): array
{
    $coverage=$snapshot['coverage']??null;
    $out=['contract'=>VP3_MARKETING_OPPORTUNITY_PORTFOLIO_V1,
        'coverage'=>in_array($coverage,['ready','limited','unavailable','unconfigured'],true)?$coverage:'unavailable',
        'opportunities'=>[],'review_scope'=>'current_authorized_sources_only'];
    if(!in_array($coverage,['ready','limited'],true)
        ||!is_array($snapshot['items']??null)||$maximum<1||$maximum>10)return $out;
    $types=[
        'campaign-readiness'=>['rank'=>100,'group'=>'campaign','label'=>'Campaign readiness'],
        'campaign-performance'=>['rank'=>92,'group'=>'campaign','label'=>'Performance review'],
        'campaign-optimization'=>['rank'=>86,'group'=>'campaign','label'=>'Campaign optimization'],
        'campaign-work'=>['rank'=>80,'group'=>'campaign','label'=>'Creative follow-through'],
        'product-plan'=>['rank'=>72,'group'=>'product','label'=>'Product marketing'],
        'campaign-outcome'=>['rank'=>62,'group'=>'campaign','label'=>'Outcome review'],
    ];
    $candidates=[];
    foreach(array_slice($snapshot['items'],0,36,true) as $key=>$source){
        if(!is_array($source)||!is_string($key)||($source['ref']??null)!==$key
            ||($source['specialist']??null)!=='marketing')continue;
        if(!preg_match('/\Amarketing:(campaign-(?:readiness|performance|optimization|work|outcome)|product-plan):([1-9][0-9]{0,15})\z/D',$key,$matches))continue;
        $kind=$matches[1];$id=(int)$matches[2];
        $data=$types[$kind]??null;
        $revision=$source['revision']??null;
        if($data===null||!is_string($revision)
            ||preg_match('/\A[a-f0-9]{64}\z/D',$revision)!==1
            ||!in_array($source['priority']??null,['high','normal','low'],true))continue;
        $title=vp3_marketing_portfolio_text_v1($source['title']??null,160);
        $evidence=vp3_marketing_portfolio_text_v1($source['summary']??null,420);
        $next=vp3_marketing_portfolio_text_v1($source['next_step']??null,260);
        $question=vp3_marketing_portfolio_text_v1($source['question']??null,260);
        $href=vp3_marketing_portfolio_href_v1($source['url']??null,$kind,$id);
        if($title===''||$evidence===''||$next===''||$href===null)continue;
        // A native product-launch specialist already owns its Chat notices.
        $native=($source['marketing_presentation_owned']??false)===true
            &&($source['presentation_owner']??null)==='agent_product_launch';
        if($kind==='product-plan'&&!$native)continue;
        $state=vp3_marketing_portfolio_text_v1($source['status']??'',36);
        if($state===''||in_array($state,['unavailable','cancelled','failed','published','archived'],true))continue;
        $score=$data['rank']+match($source['priority']){'high'=>6,'normal'=>3,'low'=>0};
        $candidates[]=[
            'score'=>$score,
            'source_ref'=>$key,'source_revision'=>$revision,'kind'=>$kind,'source_id'=>$id,
            'group'=>$data['group'],'label'=>$data['label'],'title'=>$title,
            'finding'=>$evidence,'next_step'=>$next,'question'=>$question,
            'source_href'=>$href,'source_status'=>$state,
            'priority'=>$score>=94?'high':($score>=79?'normal':'low'),
            'notification_owner'=>$native?'product_launch':'coordination',
            'coverage'=>$coverage,
        ];
    }
    usort($candidates,static fn(array $a,array $b):int=>
        ($b['score']<=>$a['score'])?:strcmp($a['source_ref'],$b['source_ref']));
    $perCampaign=[];$chosen=[];
    foreach($candidates as $candidate){
        $campaign=$candidate['group']==='campaign'?$candidate['source_id']:null;
        if($campaign!==null){
            if(($perCampaign[$campaign]??0)>=2)continue;
            $perCampaign[$campaign]=($perCampaign[$campaign]??0)+1;
        }
        unset($candidate['score'],$candidate['group']);
        $chosen[]=$candidate;
        if(count($chosen)>=$maximum)break;
    }
    $out['opportunities']=$chosen;
    return $out;
}
