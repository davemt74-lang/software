<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function stem_mix_cut(
    string $value,
    int $length
): string {
    $length = max(
        1,
        $length
    );

    if (function_exists('mb_substr')) {
        return mb_substr(
            $value,
            0,
            $length,
            'UTF-8'
        );
    }

    return substr(
        $value,
        0,
        $length
    );
}

function stem_mix_reply(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    exit;
}

function stem_mix_validate_state(array $state, array $validStemIds): array
{
    $valid = array_fill_keys(array_map('intval', $validStemIds), true);
    $cleanStems = [];

    $cleanAutomationPoints = static function (
        mixed $points,
        float $minValue,
        float $maxValue
    ): array {
        if (!is_array($points)) {
            return [];
        }

        $clean = [];

        foreach (array_slice($points, 0, 64) as $point) {
            if (!is_array($point)) {
                continue;
            }

            $time = max(
                0.0,
                min(86400.0, (float)($point['t'] ?? 0))
            );
            $value = max(
                $minValue,
                min($maxValue, (float)($point['v'] ?? 0))
            );

            $clean[] = [
                't'=>round($time, 4),
                'v'=>round($value, 4),
            ];
        }

        usort(
            $clean,
            static fn(array $a, array $b): int =>
                $a['t'] <=> $b['t']
        );

        return $clean;
    };

    $cleanPluginList = static function (mixed $plugins): array {
        if (!is_array($plugins)) {
            return [];
        }

        $cleanPlugins = [];

        foreach (array_slice($plugins, 0, 6) as $plugin) {
            if (!is_array($plugin)) {
                continue;
            }

            $type = (string)($plugin['type'] ?? '');

            if (!in_array(
                $type,
                ['eq5','delay','compressor','reverb'],
                true
            )) {
                continue;
            }

            $params = is_array($plugin['params'] ?? null)
                ? $plugin['params']
                : [];

            if ($type === 'eq5') {
                $cleanParams = [
                    'f1'=>max(40.0, min(180.0, (float)($params['f1'] ?? 80))),
                    'f2'=>max(120.0, min(700.0, (float)($params['f2'] ?? 250))),
                    'f3'=>max(500.0, min(2500.0, (float)($params['f3'] ?? 1000))),
                    'f4'=>max(1800.0, min(8000.0, (float)($params['f4'] ?? 4000))),
                    'f5'=>max(6000.0, min(18000.0, (float)($params['f5'] ?? 12000))),
                    'b1'=>max(-18.0, min(18.0, (float)($params['b1'] ?? 0))),
                    'b2'=>max(-18.0, min(18.0, (float)($params['b2'] ?? 0))),
                    'b3'=>max(-18.0, min(18.0, (float)($params['b3'] ?? 0))),
                    'b4'=>max(-18.0, min(18.0, (float)($params['b4'] ?? 0))),
                    'b5'=>max(-18.0, min(18.0, (float)($params['b5'] ?? 0))),
                ];
            } elseif ($type === 'delay') {
                $cleanParams = [
                    'time'=>max(0.02, min(1.5, (float)($params['time'] ?? 0.28))),
                    'feedback'=>max(0.0, min(0.92, (float)($params['feedback'] ?? 0.32))),
                    'mix'=>max(0.0, min(1.0, (float)($params['mix'] ?? 0.20))),
                ];
            } elseif ($type === 'compressor') {
                $cleanParams = [
                    'threshold'=>max(-60.0, min(0.0, (float)($params['threshold'] ?? -18))),
                    'ratio'=>max(1.0, min(20.0, (float)($params['ratio'] ?? 4))),
                    'knee'=>max(0.0, min(40.0, (float)($params['knee'] ?? 12))),
                    'attack'=>max(0.001, min(1.0, (float)($params['attack'] ?? 0.012))),
                    'release'=>max(0.01, min(3.0, (float)($params['release'] ?? 0.24))),
                    'makeup'=>max(-6.0, min(18.0, (float)($params['makeup'] ?? 0))),
                ];
            } else {
                $cleanParams = [
                    'decay'=>max(0.2, min(8.0, (float)($params['decay'] ?? 1.8))),
                    'size'=>max(0.25, min(2.5, (float)($params['size'] ?? 1.0))),
                    'damping'=>max(800.0, min(20000.0, (float)($params['damping'] ?? 9000))),
                    'mix'=>max(0.0, min(1.0, (float)($params['mix'] ?? 0.18))),
                ];
            }

            $cleanPlugins[] = [
                'type'=>$type,
                'enabled'=>!array_key_exists('enabled', $plugin) || !empty($plugin['enabled']),
                'params'=>$cleanParams,
            ];
        }

        return $cleanPlugins;
    };

    $cleanCustomBuses = [];
    $customBusIds = [];

    foreach (array_slice(
        is_array($state['customBuses'] ?? null)
            ? $state['customBuses']
            : [],
        0,
        12
    ) as $index=>$bus) {
        if (!is_array($bus)) {
            continue;
        }

        $rawId = preg_replace(
            '/[^a-zA-Z0-9_-]/',
            '',
            (string)($bus['id'] ?? '')
        );

        $id = substr(
            $rawId !== ''
                ? $rawId
                : 'bus-' . ($index + 1),
            0,
            48
        );

        if (
            $id === '' ||
            isset($customBusIds[$id])
        ) {
            continue;
        }

        $name = trim(
            (string)($bus['name'] ?? '')
        );

        if ($name === '') {
            $name = 'BUS ' . ($index + 1);
        }

        $customBusIds[$id] = true;

        $cleanCustomBuses[] = [
            'id'=>$id,
            'name'=>stem_mix_cut($name, 32),
            'volume'=>max(
                0.0,
                min(1.5, (float)($bus['volume'] ?? 1))
            ),
            'muted'=>!empty($bus['muted']),
            'plugins'=>$cleanPluginList(
                $bus['plugins'] ?? []
            ),
        ];
    }

    foreach (($state['stems'] ?? []) as $stemId=>$mix) {
        $id = (int)$stemId;
        if ($id < 1 || !isset($valid[$id]) || !is_array($mix)) {
            continue;
        }

        $cleanPlugins = $cleanPluginList(
            $mix['plugins'] ?? []
        );

        $sends = is_array($mix['sends'] ?? null)
            ? $mix['sends']
            : [];
        $automation = is_array($mix['automation'] ?? null)
            ? $mix['automation']
            : [];

        $cleanClips = [];

        foreach (array_slice(
            is_array($mix['clips'] ?? null)
                ? $mix['clips']
                : [],
            0,
            4096
        ) as $clipIndex=>$clip) {
            if (!is_array($clip)) {
                continue;
            }

            $clipId = substr(
                preg_replace(
                    '/[^a-zA-Z0-9_-]/',
                    '',
                    (string)($clip['id'] ?? '')
                ),
                0,
                64
            );

            if ($clipId === '') {
                $clipId = 'stem-' . $id . '-clip-' . ($clipIndex + 1);
            }

            $sourceStart = max(
                0.0,
                min(
                    86400.0,
                    (float)($clip['sourceStart'] ?? 0)
                )
            );

            $sourceEnd = max(
                $sourceStart + 0.01,
                min(
                    86400.0,
                    (float)($clip['sourceEnd'] ?? ($sourceStart + 0.05))
                )
            );

            $cleanClips[] = [
                'id'=>$clipId,
                'timelineStart'=>max(
                    0.0,
                    min(
                        86400.0,
                        (float)($clip['timelineStart'] ?? 0)
                    )
                ),
                'timelineLength'=>max(
                    0.01,
                    min(
                        86400.0,
                        (float)($clip['timelineLength'] ?? 0.05)
                    )
                ),
                'sourceStart'=>$sourceStart,
                'sourceEnd'=>$sourceEnd,
                'gainDb'=>max(
                    -24.0,
                    min(
                        12.0,
                        (float)($clip['gainDb'] ?? 0)
                    )
                ),
                'muted'=>!empty($clip['muted']),
                'fadeIn'=>max(
                    0.0,
                    min(
                        86400.0,
                        (float)($clip['fadeIn'] ?? 0)
                    )
                ),
                'fadeOut'=>max(
                    0.0,
                    min(
                        86400.0,
                        (float)($clip['fadeOut'] ?? 0)
                    )
                ),
                'generatedLoop'=>!empty($clip['generatedLoop']),
            ];
        }

        $cleanClearedRanges = [];
        foreach (array_slice(
            is_array($mix['clearedRanges'] ?? null)
                ? $mix['clearedRanges']
                : [],
            0,
            128
        ) as $range) {
            if (!is_array($range)) {
                continue;
            }
            $start = max(0.0,min(86400.0,(float)($range['start'] ?? 0)));
            $end = max($start,min(86400.0,(float)($range['end'] ?? $start)));
            if ($end > $start) {
                $cleanClearedRanges[] = ['start'=>$start,'end'=>$end];
            }
        }

        $group = (string)($mix['group'] ?? 'direct');

        if (
            !in_array(
                $group,
                ['direct','vocals','rhythm','music'],
                true
            ) &&
            !isset($customBusIds[$group])
        ) {
            $group = 'direct';
        }

        $cleanStems[(string)$id] = [
            'volume'=>max(0.0, min(1.5, (float)($mix['volume'] ?? 1))),
            'pan'=>max(-1.0, min(1.0, (float)($mix['pan'] ?? 0))),
            'trim'=>max(-12.0, min(12.0, (float)($mix['trim'] ?? 0))),
            'inputDeviceId'=>substr(
                preg_replace(
                    '/[\x00-\x1F\x7F]/',
                    '',
                    (string)($mix['inputDeviceId'] ?? '')
                ),
                0,
                512
            ),
            'inputLabel'=>stem_mix_cut(
                trim((string)($mix['inputLabel'] ?? '')),
                190
            ),
            'inputChannel'=>max(1,min(64,(int)($mix['inputChannel'] ?? 1))),
            'group'=>$group,
            'muted'=>!empty($mix['muted']),
            'solo'=>!empty($mix['solo']),
            'sends'=>[
                'a'=>max(0.0, min(1.0, (float)($sends['a'] ?? 0))),
                'b'=>max(0.0, min(1.0, (float)($sends['b'] ?? 0))),
            ],
            'clipsDefined'=>array_key_exists('clips', $mix),
            'clips'=>$cleanClips,
            'clearedRanges'=>$cleanClearedRanges,
            'automation'=>[
                'volume'=>$cleanAutomationPoints(
                    $automation['volume'] ?? [],
                    0.0,
                    1.5
                ),
                'pan'=>$cleanAutomationPoints(
                    $automation['pan'] ?? [],
                    -1.0,
                    1.0
                ),
                'auxA'=>$cleanAutomationPoints(
                    $automation['auxA'] ?? [],
                    0.0,
                    1.0
                ),
                'auxB'=>$cleanAutomationPoints(
                    $automation['auxB'] ?? [],
                    0.0,
                    1.0
                ),
            ],
            'plugins'=>$cleanPlugins,
        ];
    }

    $order = [];
    foreach (($state['order'] ?? []) as $stemId) {
        $id = (int)$stemId;
        if ($id > 0 && isset($valid[$id]) && !in_array($id, $order, true)) {
            $order[] = $id;
        }
    }
    foreach (array_keys($valid) as $id) {
        if (!in_array((int)$id, $order, true)) {
            $order[] = (int)$id;
        }
    }

    $plugins = $state['plugins'] ?? [];

    $loop = $state['loop'] ?? [];
    $loopStart = max(0.0, (float)($loop['start'] ?? 0));
    $loopEnd = max($loopStart, (float)($loop['end'] ?? 0));

    $returns = is_array($state['returns'] ?? null)
        ? $state['returns']
        : [];

    $groups = is_array($state['groups'] ?? null)
        ? $state['groups']
        : [];

    $cleanGroups = [];
    foreach (['vocals','rhythm','music'] as $groupKey) {
        $groupState = is_array($groups[$groupKey] ?? null)
            ? $groups[$groupKey]
            : [];

        $cleanGroups[$groupKey] = [
            'volume'=>max(
                0.0,
                min(1.5, (float)($groupState['volume'] ?? 1))
            ),
            'muted'=>!empty($groupState['muted']),
        ];
    }

    $channelPluginsDefined =
        array_key_exists('channelPlugins', $state);

    $channelPlugins = is_array(
        $state['channelPlugins'] ?? null
    )
        ? $state['channelPlugins']
        : [];

    $cleanChannelPlugins = [];

    foreach ([
        'master',
        'aux-a',
        'aux-b',
        'group-vocals',
        'group-rhythm',
        'group-music',
    ] as $targetKey) {
        $cleanChannelPlugins[$targetKey] =
            $cleanPluginList(
                $channelPlugins[$targetKey] ?? []
            );
    }

    $cleanLibraryClips = [];

    foreach (array_slice(
        is_array($state['libraryClips'] ?? null)
            ? $state['libraryClips']
            : [],
        0,
        64
    ) as $index=>$clip) {
        if (!is_array($clip)) {
            continue;
        }

        $stemId = (int)($clip['stemId'] ?? 0);

        if ($stemId < 1) {
            continue;
        }

        $clipId = substr(
            preg_replace(
                '/[^a-zA-Z0-9_-]/',
                '',
                (string)($clip['id'] ?? '')
            ),
            0,
            48
        );

        if ($clipId === '') {
            $clipId = 'clip-' . ($index + 1);
        }

        $sourceDuration = max(
            0.05,
            min(
                86400.0,
                (float)($clip['sourceDuration'] ?? 0.05)
            )
        );

        $sourceStart = max(
            0.0,
            min(
                $sourceDuration,
                (float)($clip['sourceStart'] ?? 0)
            )
        );

        $sourceEnd = max(
            $sourceStart,
            min(
                $sourceDuration,
                (float)($clip['sourceEnd'] ?? $sourceDuration)
            )
        );

        $cleanLibraryClips[] = [
            'id'=>$clipId,
            'stemId'=>$stemId,
            'name'=>stem_mix_cut(
                trim((string)($clip['name'] ?? 'Library Stem')),
                190
            ),
            'role'=>stem_mix_cut(
                trim((string)($clip['role'] ?? 'Other')),
                80
            ),
            'song'=>stem_mix_cut(
                trim((string)($clip['song'] ?? 'Stonefellow')),
                190
            ),
            'sourceTempo'=>max(
                40.0,
                min(
                    300.0,
                    (float)($clip['sourceTempo'] ?? 120)
                )
            ),
            'sourceSignature'=>stem_mix_cut(
                trim((string)($clip['sourceSignature'] ?? '4/4')),
                20
            ),
            'sourceDuration'=>$sourceDuration,
            'sourceStart'=>$sourceStart,
            'sourceEnd'=>$sourceEnd,
            'timelineStart'=>max(
                0.0,
                min(
                    86400.0,
                    (float)($clip['timelineStart'] ?? 0)
                )
            ),
            'timelineLength'=>max(
                0.05,
                min(
                    86400.0,
                    (float)($clip['timelineLength'] ?? 0.05)
                )
            ),
        ];
    }

    $cleanMarkers = [];
    foreach (array_slice(
        is_array($state['markers'] ?? null) ? $state['markers'] : [],
        0,
        100
    ) as $marker) {
        if (!is_array($marker)) {
            continue;
        }

        $cleanMarkers[] = [
            'id'=>substr(
                preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($marker['id'] ?? '')),
                0,
                48
            ),
            'time'=>max(0.0, min(86400.0, (float)($marker['time'] ?? 0))),
            'label'=>stem_mix_cut(
                trim((string)($marker['label'] ?? 'Marker')),
                80
            ),
        ];
    }

    $cleanRegions = [];
    foreach (array_slice(
        is_array($state['regions'] ?? null) ? $state['regions'] : [],
        0,
        50
    ) as $region) {
        if (!is_array($region)) {
            continue;
        }

        $start = max(
            0.0,
            min(86400.0, (float)($region['start'] ?? 0))
        );
        $end = max(
            $start,
            min(86400.0, (float)($region['end'] ?? $start))
        );

        if ($end <= $start) {
            continue;
        }

        $cleanRegions[] = [
            'id'=>substr(
                preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($region['id'] ?? '')),
                0,
                48
            ),
            'start'=>$start,
            'end'=>$end,
            'label'=>stem_mix_cut(
                trim((string)($region['label'] ?? 'Region')),
                80
            ),
        ];
    }

    $recording = is_array(
        $state['recording'] ?? null
    )
        ? $state['recording']
        : [];

    $countInBars =
        (int)($recording['countInBars'] ?? 0);

    if (
        !in_array(
            $countInBars,
            [0,1,2,4],
            true
        )
    ) {
        $countInBars = 0;
    }

    $punchStart = max(
        0.0,
        min(
            86400.0,
            (float)(
                $recording['punchStart'] ??
                0
            )
        )
    );

    $punchEnd = max(
        $punchStart,
        min(
            86400.0,
            (float)(
                $recording['punchEnd'] ??
                $punchStart
            )
        )
    );

    return [
        'sessionTempoDefined'=>array_key_exists(
            'sessionTempo',
            $state
        ),
        'durationMeasuresDefined'=>array_key_exists(
            'durationMeasures',
            $state
        ),
        'sessionTempo'=>max(
            40.0,
            min(
                300.0,
                (float)($state['sessionTempo'] ?? 120)
            )
        ),
        'durationMeasures'=>max(0,min(4096,(int)($state['durationMeasures'] ?? 0))),
        'libraryClips'=>$cleanLibraryClips,
        'masterVolume'=>max(0.0, min(1.5, (float)($state['masterVolume'] ?? 1))),
        'returns'=>[
            'a'=>max(0.0, min(1.5, (float)($returns['a'] ?? 0.8))),
            'b'=>max(0.0, min(1.5, (float)($returns['b'] ?? 0.7))),
        ],
        'groups'=>$cleanGroups,
        'channelPluginsDefined'=>$channelPluginsDefined,
        'channelPlugins'=>$cleanChannelPlugins,
        'customBuses'=>$cleanCustomBuses,
        'markers'=>$cleanMarkers,
        'regions'=>$cleanRegions,
        'plugins'=>[
            'eq'=>!array_key_exists('eq', $plugins) || !empty($plugins['eq']),
            'compressor'=>!array_key_exists('compressor', $plugins) || !empty($plugins['compressor']),
            'reverb'=>!empty($plugins['reverb']),
        ],
        'loop'=>[
            'start'=>$loopStart,
            'end'=>$loopEnd,
            'active'=>!empty($loop['active']) && $loopEnd > $loopStart,
        ],
        'recording'=>[
            'countInBars'=>$countInBars,
            'metronome'=>!empty(
                $recording['metronome']
            ),
            'punch'=>!empty(
                $recording['punch']
            ) && $punchEnd > $punchStart,
            'punchStart'=>$punchStart,
            'punchEnd'=>$punchEnd,
        ],
        'order'=>$order,
        'stems'=>$cleanStems,
    ];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    stem_mix_reply(['ok'=>false,'error'=>'POST required.'], 405);
}

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$csrf = (string)($input['csrf_token'] ?? '');
if ($csrf === '' || !hash_equals(csrf_token(), $csrf)) {
    stem_mix_reply(['ok'=>false,'error'=>'Session expired.'], 419);
}

$pdo = db();
$user = current_user();
$userId = (int)($user['id'] ?? 0);
$trackId = (int)($input['track_id'] ?? 0);
$action = trim((string)($input['action'] ?? 'list'));

if (!$pdo || $userId < 1 || $trackId < 1 || !table_exists('stem_mix_saves')) {
    stem_mix_reply(['ok'=>false,'error'=>'Saved mix storage is not ready. Run the site upgrade.'], 503);
}

$trackStmt = $pdo->prepare('SELECT * FROM tracks WHERE id=? LIMIT 1');
$trackStmt->execute([$trackId]);
$track = $trackStmt->fetch();
if (!$track) {
    stem_mix_reply(['ok'=>false,'error'=>'Track not found.'], 404);
}

$canSaveMix =
    user_has_role('fan', $user) && can_view_track($track, $user)
    ||
    has_permission('track_notes.manage') ||
    can_manage_track_production($track) ||
    (int)($track['owner_user_id'] ?? 0) === $userId;

if (!$canSaveMix) {
    stem_mix_reply(
        ['ok'=>false,'error'=>'You do not have permission to save this project.'],
        403
    );
}

$stemStmt = $pdo->prepare(
    'SELECT id FROM track_stems
     WHERE track_id=? AND is_active=1
     ORDER BY sort_order,id'
);
$stemStmt->execute([$trackId]);
$validStemIds = array_map(
    static fn(array $row): int => (int)$row['id'],
    $stemStmt->fetchAll()
);

try {
    if ($action === 'list') {
        $stmt = $pdo->prepare(
            'SELECT id,mix_name,created_at,updated_at
             FROM stem_mix_saves
             WHERE user_id=? AND track_id=?
             ORDER BY updated_at DESC,id DESC
             LIMIT 50'
        );
        $stmt->execute([$userId,$trackId]);
        stem_mix_reply(['ok'=>true,'mixes'=>$stmt->fetchAll()]);
    }

    if ($action === 'load') {
        $mixId = (int)($input['mix_id'] ?? 0);
        $stmt = $pdo->prepare(
            'SELECT id,mix_name,mix_json,created_at,updated_at
             FROM stem_mix_saves
             WHERE id=? AND user_id=? AND track_id=?
             LIMIT 1'
        );
        $stmt->execute([$mixId,$userId,$trackId]);
        $mix = $stmt->fetch();
        if (!$mix) {
            throw new RuntimeException('Saved mix not found.');
        }

        $state = json_decode((string)$mix['mix_json'], true);
        if (!is_array($state)) {
            throw new RuntimeException('Saved mix data is damaged.');
        }

        $mix['state'] = stem_mix_validate_state($state, $validStemIds);
        unset($mix['mix_json']);

        stem_mix_reply(['ok'=>true,'mix'=>$mix]);
    }

    if ($action === 'save') {
        $name = trim((string)($input['mix_name'] ?? 'My Mix'));
        $name = $name !== '' ? stem_mix_cut($name, 120) : 'My Mix';

        $state = $input['state'] ?? null;
        if (!is_array($state)) {
            throw new RuntimeException('Mix state is required.');
        }

        $clean = stem_mix_validate_state($state, $validStemIds);
        $json = json_encode(
            $clean,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if (!is_string($json) || strlen($json) > 16777216) {
            throw new RuntimeException('Mix state is too large.');
        }

        $mixId = (int)($input['mix_id'] ?? 0);

        if ($mixId > 0) {
            $stmt = $pdo->prepare(
                'UPDATE stem_mix_saves
                 SET mix_name=?,mix_json=?,updated_at=NOW()
                 WHERE id=? AND user_id=? AND track_id=?'
            );
            $stmt->execute([$name,$json,$mixId,$userId,$trackId]);

            if ($stmt->rowCount() < 1) {
                $check = $pdo->prepare(
                    'SELECT id FROM stem_mix_saves
                     WHERE id=? AND user_id=? AND track_id=? LIMIT 1'
                );
                $check->execute([$mixId,$userId,$trackId]);
                if (!$check->fetchColumn()) {
                    throw new RuntimeException('Saved mix not found.');
                }
            }
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO stem_mix_saves
                 (user_id,track_id,mix_name,mix_json)
                 VALUES (?,?,?,?)'
            );
            $stmt->execute([$userId,$trackId,$name,$json]);
            $mixId = (int)$pdo->lastInsertId();
        }

        stem_mix_reply([
            'ok'=>true,
            'mix_id'=>$mixId,
            'mix_name'=>$name,
        ]);
    }

    if ($action === 'delete') {
        $mixId = (int)($input['mix_id'] ?? 0);
        $stmt = $pdo->prepare(
            'DELETE FROM stem_mix_saves
             WHERE id=? AND user_id=? AND track_id=?'
        );
        $stmt->execute([$mixId,$userId,$trackId]);
        stem_mix_reply(['ok'=>true]);
    }

    throw new RuntimeException('Unknown saved-mix action.');
} catch (Throwable $e) {
    stem_mix_reply(['ok'=>false,'error'=>$e->getMessage()], 400);
}
