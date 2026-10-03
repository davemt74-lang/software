<?php
declare(strict_types=1);
/** Durable diagnostic export contains no scene labels, identity or device details. */
function tracky_eyes_acceptance_report_v1g5(array $statuses): array {
    $checks=[];
    foreach(array_slice($statuses,0,20) as $s){
        $checks[]=['connection'=>(string)($s['connection']??'unknown'),'scene_state'=>(string)($s['state']??'unavailable'),
          'scene_reason'=>(string)($s['reason']??'status_unavailable'),'last_observed_at'=>$s['observed_at']??null,
          'last_received_consent'=>(string)($s['consent']??'not_reported'),
          'current_local_consent_known'=>false,'installed_device_acceptance'=>'not_verifiable_from_cloud'];
    }
    return ['contract'=>'tracky.agent-eyes.cloud-acceptance-report.v1g5','checked_at'=>gmdate('c'),
      'checks'=>$checks,'scene_meaning_retained'=>false,'media_retained'=>false,'hardware_certified'=>false,
      'capture_authority'=>false,'automatic_recovery'=>false,
      'next_action'=>'Complete the installed acceptance checklist in HomeServer Tracky. Compare its redacted report with this Cloud report; inspect original 60-second expiry and acknowledged revocation.'];
}
