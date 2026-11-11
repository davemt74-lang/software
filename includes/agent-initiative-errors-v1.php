<?php
declare(strict_types=1);

/** A server-only marker: permission, source and configuration errors never use it. */
final class Vp3InitiativeTransientInferenceV1 extends RuntimeException
{
    public function __construct(?Throwable $previous=null)
    {
        parent::__construct('model_unavailable',503,$previous);
    }
}

/** The saved snapshot is no longer the current source of truth. */
final class Vp3InitiativeSourcesChangedV1 extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Initiative sources changed.',409);
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
    if(($result['failure_code']??'')==='provider_attempt_unconfirmed')return new RuntimeException('model_unavailable',503);
    $status=array_key_exists('status',$result)?filter_var($result['status'],FILTER_VALIDATE_INT):false;
    if(($status!==false&&(in_array($status,[0,408,409,425,429],true)||($status>=500&&$status<=599)))
        ||($result['error_code']??'')==='inference_budget_exhausted'){
        return new Vp3InitiativeTransientInferenceV1();
    }
    return new RuntimeException('model_unavailable',503);
}
