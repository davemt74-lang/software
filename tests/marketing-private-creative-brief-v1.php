<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/agent-marketing-opportunity-portfolio-v1.php';
require_once dirname(__DIR__).'/includes/agent-marketing-private-brief-v1.php';
function brief_check(bool $value,string $reason):void{if(!$value)throw new RuntimeException($reason);}
$revision=str_repeat('e',64);
foreach(['campaign-readiness','campaign-performance','campaign-optimization',
    'campaign-work','product-plan','campaign-outcome'] as $kind){
    $id=91;$product=$kind==='product-plan';
    $ref='marketing:'.$kind.':'.$id;
    $url=$product?'/vp3/chat.php?agent=7&conversation_id=40&marketing_plan=91':
        '/vp3/campaigns.php?merchant=12&edit_campaign=91';
    $item=['source_ref'=>$ref,'source_revision'=>$revision,'kind'=>$kind,'source_id'=>$id,
      'title'=>'Verified marketing work','finding'=>'A canonical saved source requires review.',
      'next_step'=>'Review the saved source before preparing a draft.',
      'coverage'=>'ready','source_href'=>$url,
      'notification_owner'=>$product?'product_launch':'coordination'];
    $draft=vp3_marketing_private_brief_v1($item);
    brief_check(($draft['contract']??null)===VP3_MARKETING_PRIVATE_BRIEF_V1
      &&$draft['state']==='private_proposal_only'&&$draft['source_ref']===$ref
      &&$draft['source_revision']===$revision,'Brief must bind to exact authorized source');
    brief_check(count($draft['deliverables'])===2
      &&str_contains($draft['chat_prompt'],$revision)
      &&str_contains($draft['chat_prompt'],$ref)
      &&str_contains($draft['chat_prompt'],'PRIVATE'),'Owner-reviewable campaign and creative assignment');
    brief_check(str_contains($draft['budget_decision'],'approval')
      &&str_contains($draft['audience_decision'],'Confirm')
      &&str_contains($draft['approval_gate'],'Owner review required')
      &&$draft['measurement']!=='' ,
      'Budget, audience and measurement must not be invented');
    foreach(['creative_generated','campaign_created','activated','sent'] as $key)
      brief_check($draft[$key]===false,'Proposed work cannot masquerade as generated, sent or published');
    $tamper=$item;$tamper['source_revision']='invalid';
    brief_check(vp3_marketing_private_brief_v1($tamper)===null,'Invalid evidence revision rejected');
    $tamper=$item;$tamper['notification_owner']='wrong';
    brief_check(vp3_marketing_private_brief_v1($tamper)===null,'Incorrect native announcement owner rejected');
    $tamper=$item;$tamper['source_href']='https://outside.invalid';
    brief_check(vp3_marketing_private_brief_v1($tamper)===null,'External navigation rejected');
    $tamper=$item;$tamper['source_ref']='marketing:'.$kind.':92';
    brief_check(vp3_marketing_private_brief_v1($tamper)===null,'Changed source reference rejected');
    $tamper=$item;$tamper['coverage']='unavailable';
    brief_check(vp3_marketing_private_brief_v1($tamper)===null,'Unverified source cannot generate a brief');
    $tamper=$item;$tamper['finding']='<script>alert(1)</script>';
    brief_check(vp3_marketing_private_brief_v1($tamper)===null,'HTML or prompt injection is rejected before drafting');
    $portfolio=vp3_marketing_opportunity_portfolio_v1(['coverage'=>'ready','items'=>[
      $ref=>[
       'ref'=>$ref,'revision'=>$revision,'specialist'=>'marketing','title'=>$item['title'],
       'summary'=>$item['finding'],'next_step'=>$item['next_step'],'question'=>'Owner choice?',
       'url'=>$url,'priority'=>'normal','status'=>$product?'photo_needed':'open',
       'marketing_presentation_owned'=>$product,'presentation_owner'=>$product?'agent_product_launch':'coordination',
      ]
    ]]);
    brief_check(count($portfolio['opportunities'])===1
      &&$portfolio['opportunities'][0]['private_brief']['source_ref']===$ref,
      'Marketing opportunity carries exactly its own private draft-only brief');
}
echo "MARKETING_PRIVATE_BRIEF=PASS source-bound objective, creative, measurement, rights and no-send guarantees\n";
