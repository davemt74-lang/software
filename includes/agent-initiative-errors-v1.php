<?php
declare(strict_types=1);

/** Closed model diagnostics contain neither provider text nor private input. */
class Vp3InitiativeModelDiagnosticV1 extends RuntimeException
{
    public readonly string $diagnostic;
    public readonly ?int $httpStatus;

    public function __construct(string $diagnostic,?int $httpStatus=null,?Throwable $previous=null)
    {
        $this->diagnostic=vp3_initiative_model_diagnostic_text_v1($diagnostic)===null?'unknown':$diagnostic;
        $this->httpStatus=$httpStatus!==null&&$httpStatus>=100&&$httpStatus<=599?$httpStatus:null;
        parent::__construct('model_unavailable',503,$previous);
    }
}

/** A server-only marker: permission, source and configuration errors never use it. */
final class Vp3InitiativeTransientInferenceV1 extends Vp3InitiativeModelDiagnosticV1
{
    public function __construct(?Throwable $previous=null,string $diagnostic='unknown',?int $httpStatus=null)
    {
        parent::__construct($diagnostic,$httpStatus,$previous);
    }
}

/** The public search transport produced no verifiable search response. */
final class Vp3InitiativePublicSearchUnavailableV1 extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Public search is temporarily unavailable.',503);
    }
}

final class Vp3InitiativeOfficeReportFormatV1 extends Vp3InitiativeModelDiagnosticV1
{
    public const CODES=['invalid_json','object_required','unexpected_fields','invalid_disposition','missing_draft','invalid_draft','output_too_large','response_empty'];

    public function __construct(public readonly string $format,?Throwable $previous=null)
    {
        parent::__construct(match($format){
            'output_too_large'=>'response_too_large',
            'response_empty'=>'response_empty',
            default=>'invalid_response',
        },null,$previous);
    }
}

function vp3_initiative_model_diagnostic_text_v1(mixed $code): ?string
{
    return match($code){
        'response_empty'=>'The AI model returned no text for this review.',
        'response_too_large'=>'The AI model response exceeded the size limit for this review.',
        'invalid_response'=>'The AI model response could not be read as the required structured result.',
        'attempt_unconfirmed'=>'VP3 could not confirm the provider attempt.',
        'inference_timeout'=>'The AI request exceeded its time limit.',
        'provider_connection'=>'VP3 could not connect to the AI provider.',
        'request_rate_limit'=>'VP3 reached the account’s AI request limit before contacting the provider.',
        'request_admission_unavailable'=>'VP3 could not check the account’s AI request limit.',
        'provider_authentication'=>'The AI provider rejected the saved credential.',
        'provider_access'=>'The AI provider denied access to this request.',
        'provider_billing'=>'The AI provider required payment for this request.',
        'anthropic_credit_balance_low'=>'Anthropic reported that the API credit balance for the key used by this review is too low.',
        'provider_target'=>'The AI provider could not find the requested model or endpoint.',
        'provider_rate_limit'=>'The AI provider returned a rate-limit response.',
        'provider_interruption'=>'The AI provider could not complete the request.',
        'provider_rejected'=>'The AI provider rejected the request.',
        'provider_configuration'=>'The selected AI provider or credential is unavailable.',
        'service_configuration'=>'No permitted provider and model were available for this AI service.',
        'service_usage_unavailable'=>'VP3 could not verify AI service usage for this request.',
        'model_preflight_unavailable'=>'VP3 could not verify the configured AI route before starting this request.',
        'homeserver_unavailable'=>'HomeServer did not complete the AI request.',
        default=>null,
    };
}

/** The saved snapshot is no longer the current source of truth. */
final class Vp3InitiativeSourcesChangedV1 extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Initiative sources changed.',409);
    }
}

/** A live verifier could not confirm the saved source snapshot. */
final class Vp3InitiativeSourceProofUnavailableV1 extends RuntimeException
{
    public const FAMILIES_BY_REF=[
        'calendar'=>'calendar','calendar-conflict'=>'calendar','reminder'=>'reminders','task'=>'tasks','mission'=>'missions',
        'profile-call'=>'profile_calls','meeting-prep'=>'meeting_prep','order'=>'orders','campaign'=>'campaigns','campaign-journey'=>'campaigns',
        'reward'=>'rewards','transcript'=>'transcripts','screen-followup'=>'screen_followup',
        'account-readiness'=>'account_readiness','business-readiness'=>'business_readiness',
        'product-readiness'=>'product_readiness','scheduling-readiness'=>'scheduling_readiness','calendar-readiness'=>'calendar_readiness',
        'workbench-product'=>'workbench_product','workbench-marketing'=>'workbench_marketing',
        'workbench-screen'=>'workbench_screen','workbench-mission'=>'workbench_mission',
        'open-task'=>'open_tasks','activity-situation'=>'activity_situations','chat-history'=>'chat_history',
        'canvas-work'=>'canvas_work','canvas-feedback'=>'canvas_feedback','song-history'=>'song_history',
        'mission-evidence'=>'mission_evidence','product-context'=>'product_context',
        'task-list'=>'task_lists','live-context'=>'live_context','eyes-visit'=>'eyes_visit','artist-interest'=>'artist_research','topic-interest'=>'topic_research',
        'native-artist-interest'=>'native_artist_research','task-research'=>'task_research','transcript-research'=>'transcript_research',
        'email-canvas'=>'email_canvas','curiosity-finding'=>'curiosity','specialist'=>'specialist_reports',
        'specialist-result'=>'specialist_results','youtube-play-analysis'=>'media','media-image'=>'media_images',
    ];
    private const ADDITIONAL_FAMILIES=['office_tasks','office_reminders','office_calendar','activity_context','gmail','planning','media','canvas','casual','connected-sites','marketing'];

    public function __construct(public readonly string $family,public readonly string $coverage,?Throwable $previous=null)
    {
        parent::__construct('Initiative source verification is unavailable.',503,$previous);
    }

    /** Only closed source categories can leave the verifier or its matching receipt. */
    public static function diagnostic(mixed $family,mixed $coverage): array
    {
        if(!in_array($coverage,['unavailable','limited'],true)
            ||(!in_array($family,self::FAMILIES_BY_REF,true)&&!in_array($family,self::ADDITIONAL_FAMILIES,true)))return [];
        return ['source_family'=>$family,'source_coverage'=>$coverage];
    }
}

/** Stable diagnostic codes carry no model output or source data. */
final class Vp3InitiativePlanValidationV1 extends InvalidArgumentException
{
    public const CODES=['output_too_large','invalid_json','invalid_envelope','invalid_job','invalid_job_sources','invalid_opportunities'];

    public function __construct(public readonly string $diagnostic,string $message,?Throwable $previous=null)
    {
        parent::__construct($message,0,$previous);
    }
}

/** The assessment output failed the closed planner contract. */
final class Vp3InitiativeInvalidAssessmentV1 extends RuntimeException
{
    public readonly string $diagnostic;

    public function __construct(Throwable $previous,public readonly int $outputBytes)
    {
        $this->diagnostic=$previous instanceof Vp3InitiativePlanValidationV1?$previous->diagnostic
            :($previous instanceof JsonException?'invalid_json':'invalid_job');
        parent::__construct('Initiative assessment was not verified.',0,$previous);
    }
}

/** Preserve the public reason while admitting only factual transient transport failures. */
function vp3_initiative_provider_failure_v1(array $result): RuntimeException
{
    if(!empty($result['quota_exhausted']))return new RuntimeException('quota_exhausted',503);
    $status=filter_var($result['status']??null,FILTER_VALIDATE_INT,FILTER_NULL_ON_FAILURE);
    $code=$result['failure_code']??$result['error_code']??null;
    if($code==='provider_attempt_unconfirmed')return new Vp3InitiativeModelDiagnosticV1('attempt_unconfirmed',$status);
    if($code==='anthropic_credit_balance_low'&&($result['provider']??null)==='anthropic'&&$status===400){
        return new Vp3InitiativeModelDiagnosticV1($code,$status);
    }
    $diagnostic=match($code){
        'provider_response_empty'=>'response_empty',
        'provider_response_too_large'=>'response_too_large',
        'provider_response_invalid'=>'invalid_response',
        'provider_disabled','credential_empty','credential_missing_key','credential_unreadable_key','credential_invalid_key',
        'credential_crypto_unavailable','credential_unsupported_format','credential_key_mismatch',
        'transport_unavailable','personal_route_unavailable'=>'provider_configuration',
        'service_disabled','service_route_unavailable'=>'service_configuration',
        'service_usage_unavailable'=>'service_usage_unavailable',
        'request_rate_limit','request_admission_unavailable'=>$code,
        'inference_budget_exhausted'=>'inference_timeout',
        default=>match(true){
            $status===0=>'provider_connection',
            $status===401=>'provider_authentication',
            $status===402=>'provider_billing',
            $status===403=>'provider_access',
            $status===404=>'provider_target',
            $status===429=>'provider_rate_limit',
            in_array($status,[408,409,425],true)||($status>=500&&$status<=599)=>'provider_interruption',
            $status>=400&&$status<=499=>'provider_rejected',
            default=>'unknown',
        },
    };
    if(in_array($status,[0,408,409,425,429],true)||($status>=500&&$status<=599)
        ||$diagnostic==='request_rate_limit'||$code==='inference_budget_exhausted'){
        return new Vp3InitiativeTransientInferenceV1(null,$diagnostic,$status);
    }
    return new Vp3InitiativeModelDiagnosticV1($diagnostic,$status);
}
