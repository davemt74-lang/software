<?php
declare(strict_types=1);

/** An explicit instruction to generate the preceding offered image, never bare assent. */
function vp3_agent_chat_reply_image_reference_v1(string $text): bool
{
    $details = '\s+with\s+(?:those|these|the) details';
    return preg_match('~\A\s*(?:please\s+)?(?:go ahead and\s+)?(?:generate|create|make|draw)\s+(?:it'.$details.'|(?:that|this|the) (?:image|picture|photo(?:graph)?)(?:'.$details.')?)(?:\s*,?\s+please)?[.!]?\s*\z~iu', $text) === 1;
}

/** A direct request with its scene omitted can explicitly open an image brief. */
function vp3_agent_chat_image_needs_scene_v1(string $text): bool
{
    return preg_match('~\A\s*(?:please[\s,]+)?(?:generate|create|draw|make|render)\s+(?:me\s+)?(?:(?:an?|one|two|three|four|[1-4])\s+)?(?:new\s+)?(?:image|picture|photo(?:graph)?|render|illustration)s?[.!]?\s*\z~iu',$text) === 1;
}

/** Read only an explicit quantity immediately after the image command. */
function vp3_agent_chat_image_count_v1(string $text): int
{
    $numbers=['zero'=>0,'one'=>1,'two'=>2,'three'=>3,'four'=>4,'five'=>5,'six'=>6,'seven'=>7,'eight'=>8,
        'nine'=>9,'ten'=>10,'eleven'=>11,'twelve'=>12,'thirteen'=>13,'fourteen'=>14,'fifteen'=>15,
        'sixteen'=>16,'seventeen'=>17,'eighteen'=>18,'nineteen'=>19,'twenty'=>20,'thirty'=>30,
        'forty'=>40,'fifty'=>50,'sixty'=>60,'seventy'=>70,'eighty'=>80,'ninety'=>90,'hundred'=>100,
        'thousand'=>1000,'million'=>1000000,'dozen'=>12];
    $command='\A\s*(?:please[\s,]+|(?:can|could|would|will) you\s+)*(?:generate|create|draw|make|render|design|produce)\s+(?:me\s+)?';
    $quantity='(?<count>[+-]?[0-9]+(?:\.[0-9]+)?|'.implode('|',array_keys($numbers)).')';
    // A quantity can describe the output noun ("three album cover images"), but
    // never a scene introduced by "of", "with", etc. ("an image of three cats").
    $boundary='(?:of|for|with|showing|depicting|in|on|at|from|to|using|based|and|or|but|if|when|before|after|a|an|the|my|this|that|these|those)';
    $descriptors='(?<descriptors>(?:(?!'.$boundary.'\b)[\p{L}][\p{L}\p{N}\x{2019}\x27-]*\s+){0,8})';
    $noun='(?:images?|pictures?|photos?|photographs?|renders?|illustrations?|versions?|variations?)\b';
    if(!preg_match('~'.$command.'(?:a\s+(?:set|batch|series|collection)\s+of\s+)?'.$quantity.'\s+'.$descriptors.$noun.'~iu',$text,$match))return 1;
    $value=strtolower($match['count']);
    if(str_contains($value,'.'))return 0; // Invalid explicit quantities fail normal plan validation.
    if(preg_match('~\A(?:hundred|thousand|million|dozen)\b~iu',$match['descriptors']))return 5;
    return $numbers[$value]??(int)$value;
}

/** Accept the owner's literal new-image brief or an exact adjacent image proposal. */
function vp3_agent_chat_reply_image_plan_v1(string $text, ?array $previousAssistant = null): ?array
{
    $command = '~\A\s*(?:please[\s,]+|(?:can|could|would) you\s+)*(?:generate|create|draw|make|render)\s+(?:me\s+)?';
    $formatPattern = '(?:(?<format>square|landscape|portrait)\s+)?';
    $image = '(?:image|picture|photo(?:graph)?|render|illustration)s?';
    $quantity='(?:an?|one|two|three|four|five|six|seven|eight|nine|ten|[1-9][0-9]?)';
    $literal = $command.'(?:'.$quantity.'\s+)?(?:(?:new|different|distinct)\s+)?'.$formatPattern.$image.'(?:\s+(?:of|showing|depicting|with|for)\s+|\s*:\s*)(?<prompt>.+?)\s*\z~isu';
    $subjectFirst = $command.'(?:an?|one)\s+(?:new\s+)?'.$formatPattern.'(?<subject>[\p{L}\p{N}][\p{L}\p{N}\x{2019}\x27 -]{1,100}?)\s+'.$image.'(?<details>\s+(?:of|showing|depicting|with|for)\s+.+?)?[.!?]?\s*\z~isu';
    $format = '';
    if (preg_match($literal, $text, $match) === 1) {
        $prompt = $match['prompt'];
        $format = strtolower($match['format']);
    } elseif (preg_match($subjectFirst, $text, $match) === 1) {
        if (preg_match('~\b(?:to|that|which|will|can|could|should|from|using|based on)\b~iu', $match['subject']) === 1) return null;
        $prompt = $match['subject'].($match['details'] ?? '');
        $format = strtolower($match['format']);
    } elseif (vp3_agent_chat_reply_image_reference_v1($text) && $previousAssistant !== null) {
        $prompt = vp3_agent_chat_reply_image_offer_v1($previousAssistant);
    } else {
        return null;
    }
    if (!is_string($prompt)) return null;
    $prompt = trim($prompt);
    if (!vp3_agent_chat_reply_image_prompt_valid_v1($prompt)) return null;

    $size = match ($format) {
        'landscape' => '1536x1024',
        'portrait' => '1024x1536',
        default => '1024x1024',
    };
    $transparent = preg_match('~\btransparent background\b~iu', $prompt) === 1
        && preg_match('~\b(?:without|no|not)\s+(?:an?\s+)?transparent background\b~iu', $prompt) === 0;
    require_once __DIR__.'/agent-image-generation-v1.php';
    $count=vp3_agent_chat_image_count_v1($text);
    return vp3_image_text_plan_v1(['prompt'=>$prompt, 'size'=>$size, 'background'=>$transparent?'transparent':'opaque',...($count!==1?['count'=>$count]:[])]);
}

function vp3_agent_chat_reply_image_prompt_valid_v1(string $prompt): bool
{
    return mb_strlen($prompt) >= 3 && mb_strlen($prompt) <= 4000
        && preg_match('~https?://|www\.|[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]~iu', $prompt) === 0
        && preg_match('~\A(?:(?:it|that|this|same|above|previous|the usual)[.!?]*|(?:those|these|the) details[.!?]*)\z~iu', $prompt) === 0
        && preg_match('~\b(?:this|that|the same|the existing|the original|(?:my|the|this|that) (?:uploaded|saved|attached|previous)|the above)\s+(?:image|picture|photo(?:graph)?|screenshot|attachment)\b~iu', $prompt) === 0
        && preg_match('~\b(?:use|using|based on|from)\s+(?:(?:the|my|that|this|an?)\s+)?(?:attached|uploaded|existing|original|previous|saved|reference)\s+(?:image|picture|photo(?:graph)?|screenshot|attachment)\b~iu', $prompt) === 0
        && preg_match('~\b(?:and then|then)\s+(?:send|publish|post|upload|approve|share)\b~iu', $prompt) === 0;
}

/** An unrelated proactive post does not replace an active owner request. */
function vp3_agent_chat_image_turns_v1(PDO $pdo, int $conversationId, int $userMessageId): array
{
    $stmt = $pdo->prepare('SELECT id,role,user_id,message,context_json FROM chat_messages WHERE conversation_id=? AND id<? ORDER BY id DESC LIMIT 8');
    $stmt->execute([$conversationId, $userMessageId]);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $context = json_decode((string)$row['context_json'], true, 32);
        if ($row['role'] === 'assistant' && $row['user_id'] === null && ($context['source'] ?? null) === 'agent_initiative'
            && is_int($context['initiative_run_id'] ?? null) && $context['initiative_run_id'] > 0
            && !isset($context['initiative_followthrough'])) continue;
        $rows[] = $row;
        if (count($rows) === 2) break;
    }
    return $rows;
}

function vp3_agent_chat_previous_image_turn_v1(PDO $pdo, int $conversationId, int $userMessageId): ?array
{
    return vp3_agent_chat_image_turns_v1($pdo,$conversationId,$userMessageId)[0] ?? null;
}

function vp3_agent_chat_image_scene_v1(string $text): ?string
{
    $detail = trim(preg_replace('~\A\s*(?:yes|sure|okay|ok)\b[\s,:-]*~iu', '', $text) ?? $text);
    if (mb_strlen($detail) > 650 || !vp3_agent_chat_reply_image_prompt_valid_v1($detail)
        || preg_match('~[?\r\n]|\A\s*(?:no|search|find|show|tell|play|schedule|remind|send|publish|post|share|upload|edit|delete|open|write|summarize|generate|make|create|draw|render|thanks|thank you|(?:i|we)\s+(?:like|enjoy|watch|prefer))\b~iu', $detail)) return null;
    return $detail;
}

/** Interpret a brief scene fragment only while an image exchange is active. */
function vp3_agent_chat_image_detail_v1(string $text, string $brief = ''): ?string
{
    $detail=vp3_agent_chat_image_scene_v1($text);
    if ($detail===null) return null;
    if (preg_match('~\b(?:background|landscape|portrait|lighting|golden hour|sunrise|sunset|photo(?:realistic)?|monochrome|black and white|color|snow(?:y|fall)?|mountains?|watercolou?r|painting|pixel art|illustration|cartoon|cinematic|wide angle|style)\b~iu', $detail)) return $detail;
    preg_match_all('~\b[\p{L}]{4,}\b~u', mb_strtolower($brief), $briefWords);
    preg_match_all('~\b[\p{L}]{4,}\b~u', mb_strtolower($detail), $detailWords);
    $shared = array_intersect($detailWords[0], $briefWords[0]);
    return array_diff($shared,['with','that','this','from','make','generate','create','photo','image','picture']) !== [] ? $detail : null;
}

function vp3_agent_chat_image_answer_scope_v1(string $text): bool
{
    return preg_match('~\b(?:generate|create|draw|make|render|illustrate|design)\b~iu',$text) === 1
        && preg_match('~\b(?:image|picture|photo(?:graph)?|render|illustration)\b~iu',$text) === 1;
}

function vp3_agent_chat_image_prose_claim_v1(string $text,bool $imageContext): bool
{
    $image='(?:image|picture|photo(?:graph)?|illustration)';
    if(preg_match('~\b'.$image.' (?:has been|is) (?:queued|generating|rendering|generated)\b~iu',$text))return true;
    $claim='~\bI(?:[’\']ll|[’\']ve|[’\']m| will| have| am)\s+(?:(?:now|go ahead and)\s+)?'
        .'(?<verb>queue(?:d|ing)?|generat(?:e|ed|ing)|render(?:ed|ing)?)\b\s*(?<target>[^.!?\r\n]*)~iu';
    if(preg_match($claim,$text,$match)!==1)return false;
    $target=$match['target'];
    if(preg_match('~\b'.$image.'\b~iu',$target))return true;
    if(!$imageContext)return false;
    if(preg_match('~\A(?:it|this|that)\b(?:\s+(?:here|now|in|on|with|next|and)\b|\s*\z)~iu',$target))return true;
    return !str_starts_with(mb_strtolower($match['verb']),'queue')&&vp3_agent_chat_image_detail_v1($target)!==null;
}

/** Release complete sentences while verifying that general prose makes no image-job claim. */
final class Vp3ChatImageAnswerGuardV1
{
    private string $pending='';
    private bool $unverified=false;

    public function __construct(private bool $imageContext=false) {}

    public function push(string $delta,bool $final=false): string
    {
        if($this->unverified)return '';
        $this->pending.=$delta;
        $out='';
        while($this->pending!==''){
            if(preg_match('~\A(?<sentence>.*?(?:[.!?](?=\s)|\n))(?<remaining>.*)\z~su',$this->pending,$parts)){
                $sentence=$parts['sentence'];
                $this->pending=$parts['remaining'];
            }elseif($final){
                $sentence=$this->pending;
                $this->pending='';
            }else break;
            $this->imageContext=$this->imageContext||vp3_agent_chat_image_answer_scope_v1($sentence);
            if(vp3_agent_chat_image_prose_claim_v1($sentence,$this->imageContext)){
                $this->unverified=true;
                $this->pending='';
                return $out.' '.VP3_CHAT_UNVERIFIED_IMAGE_ANSWER_V1;
            }
            $out.=$sentence;
        }
        return $out;
    }
}

const VP3_CHAT_UNVERIFIED_IMAGE_ANSWER_V1='I couldn’t verify an image job. To create one, ask for an image and include the scene and style you want.';

function vp3_agent_chat_verified_image_answer_v1(string $answer,bool $imageContext=false): string
{
    $guard=new Vp3ChatImageAnswerGuardV1($imageContext);
    return trim($guard->push($answer,true));
}

/** A regular assistant turn can clarify the owner's image brief. */
function vp3_agent_chat_image_assistant_v1(array $row): bool
{
    if (($row['role'] ?? null) !== 'assistant' || !array_key_exists('user_id', $row) || $row['user_id'] !== null
        || !is_string($row['message'] ?? null) || mb_strlen($row['message']) > 4500) return false;
    $context = $row['context_json'] ?? null;
    $context = is_string($context) ? json_decode($context, true, 32) : $context;
    if (!is_array($context) || !in_array($context['source'] ?? null, [null, '', 'chat'], true)) return false;
    foreach (['initiative_followthrough','initiative_work','tool_receipt','canvas_media','skip_brain_archive',
        'stream_partial','transcript_context','task_context','connected_site_context_v1'] as $field) {
        if (!empty($context[$field])) return false;
    }
    $sources = $context['sources'] ?? [];
    if (!is_array($sources)) return false;
    foreach ($sources as $source) {
        if (($source['source'] ?? null) !== 'compute-routing:v019') return false;
    }
    return true;
}

function vp3_agent_chat_image_asked_for_details_v1(array $row): bool
{
    if (!vp3_agent_chat_image_assistant_v1($row)) return false;
    if (vp3_agent_chat_reply_image_offer_v1($row) !== null) return true;
    if (preg_match('~(?:\A|\s)(?:Want me to go ahead with .+? or would you like (?:a different|another) .+?|Should I include .+? or .+?|What (?:style|setting|lighting|composition|background|details)\b[^?]+|Would you like .+? or .+?)\?\s*\z~isu', $row['message']) === 1) return true;
    return vp3_agent_chat_image_answer_scope_v1($row['message'])
        && preg_match('~(?:\A|\s)Should it be [^?]+? or (?:a different|another) (?:scene|setting|style|background|composition)[^?]*\?\s*\z~iu',$row['message']) === 1;
}

/** Caller reads a saved assistant turn within the owner's authorized Chat. */
function vp3_agent_chat_reply_image_offer_v1(array $row): ?string
{
    if (!vp3_agent_chat_image_assistant_v1($row)) return null;
    $pattern = <<<'REGEX'
~\A\s*(?:I can (?:make|create|generate) that[.!]\s+)?Here(?:['’]s| is) the (?:image|picture|render|illustration) I(?:['’]d| would) (?:generate|create|make|draw)\s*(?:[:,–—-]\s*)?(?<prompt>.+?)\s+(?:Should I|Shall I|Would you like me to|Do you want me to)\s+(?:go ahead and\s+)?(?:generate|create|make|draw)\s+(?:it|this image|that image|the image)(?:\s+with\s+(?:those|these|the) details)?\?(?:\s+If you (?:want|would like) [^?]{3,300}[.!])?\s*\z~isu
REGEX;
    return preg_match($pattern, $row['message'], $match) === 1 ? $match['prompt'] : null;
}
