<?php
declare(strict_types=1);

/**
 * Phase 3B. A private, draft-only creative/marketing assignment based on an
 * authorized Campaign or product Marketing opportunity. No LLM, publishing,
 * delivery, audience inference, spend, attribution or hidden storage.
 */
const VP3_MARKETING_PRIVATE_BRIEF_V1='vp3.marketing.private-brief.v1';

function vp3_marketing_private_brief_v1(array $opportunity): ?array
{
    $ref=$opportunity['source_ref']??null;$revision=$opportunity['source_revision']??null;
    $kind=$opportunity['kind']??null;$id=$opportunity['source_id']??null;
    $data=[
        'campaign-readiness'=>[
            'Resolve the verified setup issue before making any Campaign public.',
            ['Review saved Campaign readiness and reward eligibility',
             'Draft one compliant offer message after the issue is resolved'],
            'Require a ready Campaign and explicit owner activation review.'],
        'campaign-performance'=>[
            'Investigate the recorded conversion-path gap before changing the live creative.',
            ['Verify destination and tracked checkout events',
             'Prepare one alternative headline and visual for private comparison'],
            'Compare source-specific tracked events in equivalent future observation windows; no causal lift is established.'],
        'campaign-optimization'=>[
            'Review the saved optimization finding and choose one controlled improvement.',
            ['Verify the original recommendation against current Campaign details',
             'Prepare one privately reviewed creative or eligibility alternative'],
            'Only independently recorded Campaign events can establish subsequent performance.'],
        'campaign-work'=>[
            'Review the existing private campaign creative association and proposed use.',
            ['Confirm the actual saved creative version and its provenance',
             'Verify whether implementation has a separate execution receipt'],
            'Approval of a private association does not establish publication or delivery.'],
        'product-plan'=>[
            'Advance the existing owner-private product marketing plan to its next review.',
            ['Review the product listing, permitted image and saved creative stage',
             'Prepare one owner-reviewable visual and caption suggestion'],
            'Product Launch retains its existing Chat notifications and publication approval.'],
        'campaign-outcome'=>[
            'Review the recorded Campaign outcome before defining the next offer.',
            ['Check the observation window and attribution scope',
             'Outline one private follow-up creative concept without making performance claims'],
            'Describe observed outcomes only; do not infer attributed revenue or causal improvement.'],
    ];
    if(!is_string($ref)||!is_string($kind)||!isset($data[$kind])
        ||!is_int($id)||$id<1||!is_string($revision)
        ||preg_match('/\A[a-f0-9]{64}\z/D',$revision)!==1
        ||!preg_match('/\Amarketing:'.preg_quote($kind,'/').':'.(string)$id.'\z/D',$ref)
        ||!in_array($opportunity['coverage']??null,['ready','limited'],true)
        ||($opportunity['notification_owner']??null)!==($kind==='product-plan'?'product_launch':'coordination')
        ||!function_exists('vp3_marketing_portfolio_href_v1')
        ||vp3_marketing_portfolio_href_v1($opportunity['source_href']??null,$kind,$id)===null)return null;
    foreach(['title'=>160,'finding'=>420,'next_step'=>260] as $key=>$max){
        $value=$opportunity[$key]??null;
        if(!is_string($value)||trim($value)===''||mb_strlen($value,'UTF-8')>$max
            ||str_contains($value,'<')||str_contains($value,'>'))return null;
    }
    [$objective,$deliverables,$measurement]=$data[$kind];
    $audience='Confirm the intended audience, eligibility and geographic scope with the Merchant owner.';
    $budget='Obtain an explicit budget and spending approval; no amount is assumed or authorized.';
    $channel='Choose the appropriate promotion channel and schedule with the owner before activation.';
    $approval='Owner review required. Create only a private draft; do not publish, message, charge, launch, or alter live Campaigns automatically.';
    $status=$opportunity['coverage']==='limited'
        ?'Some source records are unavailable; confirm missing coverage before acting.'
        :'This is a verified source-led planning proposal, not an execution receipt.';
    $chatPrompt="Help me develop a PRIVATE Marketing campaign/creative brief for this verified opportunity.\n"
        ."Source: ".$ref."\nSource revision: ".$revision."\n"
        ."Finding: ".$opportunity['finding']."\nNext step: ".$opportunity['next_step']."\n"
        ."Objective: ".$objective."\n"
        ."Draft deliverables: ".implode('; ',$deliverables)."\n"
        ."Decisions needed: ".$audience.' '.$budget.' '.$channel."\n"
        ."Measurement guard: ".$measurement."\n"
        ."Do not send, publish, launch, claim completed work, or spend money. Ask for my approval first.";
    return [
        'contract'=>VP3_MARKETING_PRIVATE_BRIEF_V1,'state'=>'private_proposal_only',
        'source_ref'=>$ref,'source_revision'=>$revision,
        'source_coverage'=>$opportunity['coverage'],'objective'=>$objective,
        'deliverables'=>$deliverables,'audience_decision'=>$audience,
        'budget_decision'=>$budget,'channel_decision'=>$channel,
        'measurement'=>$measurement,'approval_gate'=>$approval,
        'evidence_note'=>$status,'next_step'=>$opportunity['next_step'],
        'chat_prompt'=>$chatPrompt,'creative_generated'=>false,
        'campaign_created'=>false,'activated'=>false,'sent'=>false,
    ];
}
