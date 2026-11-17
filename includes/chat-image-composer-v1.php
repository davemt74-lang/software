<?php
declare(strict_types=1);

require_once __DIR__.'/agent-image-reference-v1.php';

/** Only an explicit attachment on this request can select an image source. */
function vp3_chat_image_reference_input_v1(array $input): int
{
    if(!array_key_exists('image_reference_asset_id',$input))return 0;
    $id=$input['image_reference_asset_id'];
    if(!is_int($id)||$id<1||$id>9007199254740991)throw new InvalidArgumentException('Choose a valid base image.',422);
    return $id;
}

function vp3_chat_image_reference_context_v1(PDO $pdo,array $user,array $principal,array $input,array $context,?array &$reference=null): array
{
    $reference=null;
    unset($context['image_reference_asset_id'],$context['image_reference']);
    $id=vp3_chat_image_reference_input_v1($input);
    if(!$id)return $context;
    if(!empty($input['ephemeral_protocol'])||array_key_exists('initiative_reply',$input)
        ||vp3_participant_scope_v1()!==null||vp3_participant_chat_actor_v1($pdo,$user,$input)!==null){
        throw new RuntimeException('Use a new private owner Chat prompt with this base image.',403);
    }
    $reference=vp3_image_reference_validate_v1($pdo,$user,$principal,$context,$id);
    $context['image_reference_asset_id']=$id;
    $context['image_reference']=['attached'=>true,'title'=>$reference['title']];
    return $context;
}

/** Private request-cache binding; never included in model context or browser output. */
function vp3_chat_image_reference_request_v1(PDO $pdo,array $user,array $principal,array $input,array $context): ?array
{
    vp3_chat_image_reference_context_v1($pdo,$user,$principal,$input,$context,$reference);
    if($reference===null)return null;
    return ['asset_id'=>$reference['asset_id'],'sha256'=>$reference['sha256'],'revision'=>$reference['revision'],
        'query_hash'=>hash('sha256',trim((string)($input['message']??''))),
        'conversation_id'=>(int)($context['conversation_id']??0),'agent_id'=>(int)($principal['agent_id']??0)];
}

/** A reference attachment does not turn planning or deferred instructions into paid work. */
function vp3_chat_image_reference_intent_allowed_v1(string $text): bool
{
    $edit='(?:generate|create|render|draw|make|edit|modify|transform|restyle|remix|rework|change|turn|add|put|place|move|position|reposition|arrange|remove|erase|replace|recolor|blur|brighten|darken|resize|rotate|crop|flip|mirror|center|centre|align)';
    return preg_match('~https?://|www\.|[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]~iu',$text)===0
        &&preg_match('~\b(?:(?:do not|don[’\x27]t|never)\s+'.$edit.'\b|(?:only|just)\s+(?:brainstorm|discuss|describe|plan)\b|(?:hypothetically|not yet|not now|maybe later)\b|'.$edit.'\b[^.!?\r\n]{0,160}\b(?:later|tomorrow|next week|when I|after I)\b)~iu',$text)===0
        &&preg_match('~\bimage\s+(?:generation|editing)\s+(?:support|feature|tool|system|code|api|workflow)\b~iu',$text)===0
        &&preg_match('~(?:\b(?:and(?:\s+then)?|then)\s+|[.,;]\s*)(?:send|publish|post|upload|approve|share)\b~iu',$text)===0;
}

/** A selected base plus a concrete editing instruction is an image request. */
function vp3_chat_image_reference_plan_v1(string $query): ?array
{
    $text=trim($query);
    if(mb_strlen($text)<3||mb_strlen($text)>4000||!vp3_chat_image_reference_intent_allowed_v1($text))return null;
    $command='(?:please[\s,]+|(?:can|could|would) you\s+)*';
    $reference='(?:it|(?:this|that|the|my|attached|uploaded|base|reference)\s+(?:(?:attached|uploaded|base|reference)\s+)?(?:image|picture|photo(?:graph)?))';
    $edit=preg_match('~\A'.$command.'(?:edit|modify|transform|restyle|remix|rework|change|turn|make)\s+'.$reference.'\s+.{3,}\z~isu',$text)===1;
    $visualPart='(?:background|foreground|text|watermark|border|shadow|colou?r|palette|lighting|contrast|brightness|saturation|texture|style|font|typeface|typography)s?';
    $visualEdit=preg_match('~\A'.$command.'(?:remove|erase)\s+(?:(?:the|its)\s+)?'.$visualPart.'\s*[.!]?\z~iu',$text)===1
        ||preg_match('~\A'.$command.'(?:replace|change|blur|brighten|darken|recolor)\s+(?:(?:the|its)\s+)?'.$visualPart.'\s+.{3,}\z~isu',$text)===1;
    // With an explicit base, "remove the hat" and "add headphones on both sides"
    // are complete edit instructions. Keep ordinary record/file commands and
    // removal of the attachment itself with their existing Chat tools.
    $objectAction='(?:add|put|place|move|position|reposition|arrange|remove|erase|replace|change|recolor|blur|brighten|darken|resize|rotate|crop|flip|mirror|center|centre|align)';
    $otherTarget='(?:lists?|tasks?|reminders?|events?|calendars?|schedules?|files?|folders?|library|libraries|collections?|gallery|galleries|accounts?|websites?|sites?|homepages?|profiles?|projects?|boards?|meetings?|emails?|messages?|chats?|documents?|apps?|posts?|drafts?|passwords?|permissions?|settings?|subscriptions?|connections?|API keys?)';
    $objectEdit=preg_match('~\A'.$command.$objectAction.'\s+.{3,}\z~isu',$text)===1
        &&preg_match('~\A'.$command.$objectAction.'\s+(?:(?:my|the|a|an|this|that|our|your)\s+)?(?:(?!(?:of|with|to|from|in|on|at|as|for)\b)[\p{L}-]+\s+){0,3}'.$otherTarget.'\b~iu',$text)===0
        &&preg_match('~\A'.$command.'(?:remove|erase)\s+(?:it|(?:(?:the|this|that|my)\s+)?(?:(?:attached|uploaded|base|reference)\s+)?(?:image|picture|photo(?:graph)?|attachment))\s*[.!]?\z~iu',$text)===0
        &&preg_match('~\b(?:to|from|into|in|on)\s+(?:(?:my|the|a|an|this|that|our|your)\s+)?(?:[\p{L}-]+\s+){0,3}'.$otherTarget.'\b~iu',$text)===0;
    $variation=preg_match('~\A'.$command.'(?:generate|create|make|draw|render)\s+(?:me\s+)?(?:(?:an?|one|two|three|four|[1-9][0-9]?)\s+)?(?:(?:new|different|distinct)\s+)?(?:image|picture|photo(?:graph)?|illustration|render|version|variation)s?(?:\s+(?:of|with|showing|depicting|from|using|based on|for)\s+|\s*:\s*)[\p{L}\p{N}].{2,}\z~isu',$text)===1;
    $clearBackground=preg_match('~\A'.$command.'(?:(?:remove|erase)\s+(?:(?:the|its)\s+)?background|(?:change|make)\s+(?:(?:the|its)\s+)?background\s+(?:to\s+)?transparent)\s*[.!]?\z~iu',$text)===1;
    if(!$edit&&!$visualEdit&&!$objectEdit&&!$variation&&!$clearBackground)return null;
    $count=vp3_agent_chat_image_count_v1($text);
    $transparent=$clearBackground||(preg_match('~\btransparent background\b~iu',$text)===1
        &&preg_match('~\b(?:without|no|not)\s+(?:an?\s+)?transparent background\b~iu',$text)===0);
    return vp3_image_text_plan_v1(['prompt'=>$text,'count'=>$count,'background'=>$transparent?'transparent':'opaque']);
}

/** Even a misclassified discussion cannot spend image credits merely because a base is attached. */
function vp3_chat_image_reference_generation_requested_v1(string $query): bool
{
    return vp3_chat_image_reference_intent_allowed_v1($query)
        &&(vp3_agent_chat_reply_image_plan_v1($query)!==null||vp3_chat_image_reference_plan_v1($query)!==null);
}
