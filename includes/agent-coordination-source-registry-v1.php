<?php
declare(strict_types=1);

require_once __DIR__.'/agent-specialist-starters-v1.php';

/**
 * Declare the specialist owning each source family, its native reference format,
 * and whether it participates in discovery or only exact saved-source reads.
 * Adapters keep ownership of data access and return the shared items/coverage result.
 */
function vp3_coordination_source_registry_v1(): array
{
    static $registry = null;
    return $registry ??= [
        'gmail'=>[
            'specialist'=>'gmail',
            'sweep'=>'always',
            'native_ref'=>'[a-f0-9]{64}',
            'read'=>static fn(PDO $pdo, array $scope, array $binding, ?array $refs): array =>
                vp3_coordination_gmail_sources_v1($pdo,$scope,$binding,$refs),
        ],
        'office'=>[
            'specialist'=>'tasks',
            'sweep'=>'always',
            'native_ref'=>'(?:task|task-list|reminder):[1-9][0-9]{0,17}',
            'read'=>static fn(PDO $pdo, array $scope, array $binding, ?array $refs, DateTimeImmutable $now,
                ?int $officeBefore): array => vp3_coordination_office_sources_v1($pdo,$scope,$binding,$refs,$now,$officeBefore),
        ],
        'planning'=>[
            'specialist'=>'planning',
            'sweep'=>'always',
            'native_ref'=>'(?:calendar:event:[1-9][0-9]{0,17}(?:@[0-9]{8}T[0-9]{6}Z)?|scheduling:booking:[1-9][0-9]{0,17}|readiness:start)',
            'read'=>static fn(PDO $pdo, array $scope, array $binding, ?array $refs, DateTimeImmutable $now): array =>
                vp3_coordination_planning_sources_v1($pdo,$scope,$refs,$now,($binding['enabled']??false)===true),
        ],
        'media'=>[
            'specialist'=>'media',
            'sweep'=>'always',
            'native_ref'=>'(?:(?:resume|track|youtube|research|listen|studio):[1-9][0-9]{0,15}|youtube-play:[A-Za-z0-9_-]{11}|stoneward:[a-f0-9]{32}:(?:resume-)?[a-f0-9]{64})',
            'read'=>static fn(PDO $pdo, array $scope, array $binding, ?array $refs, DateTimeImmutable $now): array =>
                vp3_coordination_media_sources_v1($pdo,$scope,$binding,$refs,$now),
        ],
        'canvas'=>[
            'specialist'=>'canvas',
            'sweep'=>'always',
            'native_ref'=>'[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}:[a-f0-9]{64}',
            'read'=>static fn(PDO $pdo, array $scope, array $binding, ?array $refs, DateTimeImmutable $now): array =>
                vp3_coordination_read_canvas_sources_v1($pdo,$scope,$refs,$now),
        ],
        'connected-sites'=>[
            'specialist'=>'connected-sites',
            'sweep'=>'always',
            'native_ref'=>'[a-f0-9]{64}',
            'read'=>static function(PDO $pdo,array $scope,array $binding,?array $refs,DateTimeImmutable $now): array {
                if(!table_exists('vp3_gelato_connections_v1'))return ['items'=>[],'coverage'=>'unconfigured'];
                require_once __DIR__.'/connected-sites-reports-v1.php';
                return vp3_coordination_connected_sites_sources_v1($pdo,$scope,$refs,$now);
            },
        ],
        'transcript'=>[
            'specialist'=>'transcripts',
            'sweep'=>'always',
            'native_ref'=>'[1-9][0-9]{0,17}',
            'read'=>static function(PDO $pdo,array $scope,array $binding,?array $refs,DateTimeImmutable $now): array {
                require_once __DIR__.'/agent-coordination-transcripts-v1.php';
                return vp3_coordination_transcripts_sources_v1($pdo,$scope,$binding,$refs,$now);
            },
        ],
        'profile'=>[
            'specialist'=>'profile',
            'sweep'=>'always',
            'native_ref'=>'(?:(?:conversation|event|call|followup):[1-9][0-9]{0,17}|analytics:v1:(?:1|7|30):[1-9][0-9]{8,9}:[esu]:[A-Za-z0-9_-]{2,100}|ft:v1:[a-f0-9]{32}:(?:1|7|30):[1-9][0-9]{8,9}:[esu]:[A-Za-z0-9_-]{2,100})',
            'read'=>static function(PDO $pdo,array $scope,array $binding,?array $refs,DateTimeImmutable $now): array {
                require_once __DIR__.'/agent-coordination-profile-v1.php';
                return vp3_coordination_profile_sources_v1($pdo,$scope,$binding,$refs,$now);
            },
        ],
        'marketing'=>[
            'specialist'=>'marketing',
            'sweep'=>'enabled',
            'native_ref'=>'(?:campaign-(?:optimization|readiness|outcome|performance|work|brief):[1-9][0-9]{0,17}|product-plan:[1-9][0-9]{0,15}|ft:v1:[a-f0-9]{32}:(?:1|7|30):[1-9][0-9]{8,9}:[esu]:[A-Za-z0-9_-]{2,100})',
            'read'=>static function(PDO $pdo,array $scope,array $binding,?array $refs,DateTimeImmutable $now): array {
                require_once __DIR__.'/agent-coordination-marketing-v1.php';
                return vp3_coordination_marketing_sources_v1($pdo,$scope,$refs,$now);
            },
        ],
        'curiosity'=>[
            'specialist'=>'curiosity',
            'sweep'=>'enabled',
            'native_ref'=>'[a-f0-9]{32}:[a-f0-9]{32}',
            'read'=>static fn(PDO $pdo, array $scope, array $binding, ?array $refs, DateTimeImmutable $now): array =>
                vp3_coordination_curiosity_sources_v1($pdo,$scope,$refs,$now),
        ],
        'casual'=>[
            'specialist'=>'casual',
            'sweep'=>'never',
            'native_ref'=>'(?:media|music|youtube|youtube_history|email|today|resume|'
                .implode('|',array_map('preg_quote',array_keys(VP3_SPECIALIST_STARTERS_V1))).')',
            'read'=>static fn(PDO $pdo, array $scope, array $binding, array $refs, DateTimeImmutable $now,
                ?int $officeBefore, bool $usePostedCasual): array =>
                vp3_coordination_casual_sources_v1($pdo,$scope,$binding,$refs,$now,$usePostedCasual),
        ],
    ];
}
