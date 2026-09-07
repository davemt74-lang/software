<?php
declare(strict_types=1);

const VP3_TRANSCRIPTION_DEEPER_REPORT_V307 = 'transcription-deeper-report-v307-20260907';

function transcription_deeper_report_master_v307(array $master): array
{
    $analysis=is_array($master['analysis']??null)?$master['analysis']:[];
    $modules=transcription_app_modules_v306($analysis,$master);
    if (isset($modules['knowledge']['result']['items']) && is_array($modules['knowledge']['result']['items'])) {
        $modules['knowledge']['result']['items']=array_values(array_filter(
            $modules['knowledge']['result']['items'],
            static function(mixed $item): bool {
                if (!is_array($item)) return false;
                $state=(string)($item['knowledge_state']??'');
                return !in_array($state,['duplicate','temporary'],true);
            }
        ));
    }
    $master['analysis']=transcription_deeper_projection_v307($modules);
    return $master;
}

function transcription_deeper_report_text_v307(
    array $master,array $session,array $tags,string $currentHash,array $appStatus
): string {
    return transcription_intelligence_report_text_v302(
        transcription_deeper_report_master_v307($master),$session,$tags,$currentHash,$appStatus
    );
}
