<?php
declare(strict_types=1);

require_once __DIR__.'/agent-initiative-scope-v1.php';
require_once __DIR__.'/agent-memory-scope-v410.php';
require_once __DIR__.'/agent-brain-v122.php';
require_once __DIR__.'/agent-coordination-source-change-v1.php';

const VP3_COORDINATION_RULE_ACTIONS_V1 = [
    'watch'=>'Keep in Top of Mind',
    'prepare'=>'Prepare privately',
    'ask'=>'Ask in Chat',
    'report'=>'Report in Chat',
];
const VP3_COORDINATION_PLANNING_START_V1='planning:readiness:start';

/** A specialist declares each filter label and its default routing action. */
function vp3_coordination_rule_definitions_v1(): array
{
    return [
        'gmail'=>['label'=>'Gmail','triggers'=>[
            'needs_attention'=>['label'=>'Needs your input','default'=>'ask'],
            'opportunity'=>['label'=>'Opportunity to advance','default'=>'prepare'],
            'important'=>['label'=>'Important change','default'=>'report'],
        ]],
        'tasks'=>['label'=>'Tasks & Lists','triggers'=>[
            'waiting_owner'=>['label'=>'Waiting on you','default'=>'ask'],
            'overdue'=>['label'=>'Overdue','default'=>'ask'],
            'blocked'=>['label'=>'Blocked','default'=>'ask'],
            'due_soon'=>['label'=>'Due soon','default'=>'prepare'],
            'completed'=>['label'=>'Completed with a result','default'=>'report'],
        ]],
        'planning'=>['label'=>'Planning & Scheduling','triggers'=>[
            'pending_confirmation'=>['label'=>'Pending confirmation','default'=>'ask'],
            'conflict'=>['label'=>'Schedule conflict','default'=>'ask'],
            'changed'=>['label'=>'Schedule changed','default'=>'report'],
            'followup'=>['label'=>'Meeting follow-up','default'=>'prepare'],
            'start_plan'=>['label'=>'Start a plan when the calendar is empty','default'=>'ask'],
        ]],
        'media'=>['label'=>'Media & Entertainment','triggers'=>[
            'resume'=>['label'=>'Continue listening','default'=>'ask'],
            'catalog_pick'=>['label'=>'VP3 Music discovery','default'=>'ask'],
            'recent_search'=>['label'=>'Relevant YouTube pick','default'=>'ask'],
            'stoneward_repeat'=>['label'=>'A track you return to on Stonefellow','default'=>'ask','actions'=>['watch','ask']],
            'watched_finding'=>['label'=>'A useful follow-up from a watched video','default'=>'ask','actions'=>['watch','ask','report']],
            'listened_finding'=>['label'=>'A music discovery from repeated listening','default'=>'ask','actions'=>['watch','ask','report']],
            'image_concept'=>['label'=>'A visual idea inspired by listening','default'=>'ask','actions'=>['watch','ask']],
        ]],
        'canvas'=>['label'=>'Canvas Apps','triggers'=>[
            'waiting_owner'=>['label'=>'Waiting on you','default'=>'ask'],
            'overdue'=>['label'=>'Overdue','default'=>'ask'],
            'blocked'=>['label'=>'Blocked','default'=>'ask'],
            'high_priority'=>['label'=>'High priority','default'=>'ask'],
            'due_soon'=>['label'=>'Due soon','default'=>'watch'],
        ]],
        'curiosity'=>['label'=>'Curiosity','triggers'=>[
            'open_question'=>['label'=>'A question that would help the Agent understand you','default'=>'ask',
                'actions'=>['watch','ask']],
        ]],
        'connected-sites'=>['label'=>'Connected Sites','triggers'=>[
            'pos_attention'=>['label'=>'A POS ticket needs attention','default'=>'ask','actions'=>['watch','ask','report']],
            'daily_sales'=>['label'=>'Daily sales totals are available','default'=>'report','actions'=>['watch','report']],
        ]],
        'marketing'=>['label'=>'Marketing','triggers'=>[
            'product_plan'=>['label'=>'Product marketing plan status','default'=>'watch','actions'=>['watch','report']],
            'promotion_period'=>['label'=>'Completed promotion reporting period','default'=>'report','actions'=>['watch','report']],
            'optimization_ready'=>['label'=>'Campaign recommendation ready','default'=>'report','actions'=>['watch','ask','report']],
            'performance_gap'=>['label'=>'Verified promotion performance opportunity','default'=>'ask','actions'=>['watch','ask','report']],
            'creative_review_ready'=>['label'=>'Linked creative is ready to review','default'=>'ask','actions'=>['watch','ask','report']],
            'creative_approved'=>['label'=>'Linked creative approved for private use','default'=>'report','actions'=>['watch','report']],
            'creative_results'=>['label'=>'Audited post-change Campaign results available','default'=>'report','actions'=>['watch','report']],
            'setup_blocker'=>['label'=>'Campaign setup needs attention','default'=>'ask','actions'=>['watch','ask','report']],
            'campaign_outcome'=>['label'=>'Verified campaign outcome available','default'=>'report','actions'=>['watch','report']],
        ]],
        'transcripts'=>['label'=>'Transcriptions','triggers'=>[
            'open_question'=>['label'=>'An open question in a saved analysis','default'=>'ask','actions'=>['watch','ask','report']],
            'open_followup'=>['label'=>'An open follow-up in a saved analysis','default'=>'report','actions'=>['watch','report']],
        ]],
        'profile'=>['label'=>'Profile','triggers'=>[
            'commerce_followthrough'=>['label'=>'Verified product marketing follow-through','default'=>'report','actions'=>['watch','report']],
            'analytics_period'=>['label'=>'Completed Profile reporting period','default'=>'report','actions'=>['watch','report']],
            'needs_owner'=>['label'=>'A visitor needs your input','default'=>'ask','actions'=>['watch','ask','report']],
            'open_conversation'=>['label'=>'An open visitor conversation','default'=>'watch','actions'=>['watch','ask','report']],
            'missed_call'=>['label'=>'A missed visitor call','default'=>'report','actions'=>['watch','ask','report']],
            'reviewed_followup'=>['label'=>'A reviewed visitor follow-up','default'=>'report','actions'=>['watch','ask','report']],
            'activity'=>['label'=>'Other verified profile activity','default'=>'watch','actions'=>['watch','report']],
        ]],
    ];
}

function vp3_coordination_rule_catalog_v1(): array
{
    $catalog = ['actions'=>VP3_COORDINATION_RULE_ACTIONS_V1,'specialists'=>[]];
    foreach (vp3_coordination_rule_definitions_v1() as $id=>$specialist) {
        $catalog['specialists'][$id] = ['label'=>$specialist['label'],
            'triggers'=>array_map(static fn(array $rule): string=>$rule['label'],$specialist['triggers'])];
        foreach ($specialist['triggers'] as $trigger=>$rule) {
            if (isset($rule['actions'])) $catalog['specialists'][$id]['allowed_actions'][$trigger] = $rule['actions'];
        }
    }
    return $catalog;
}

function vp3_coordination_rule_defaults_v1(): array
{
    return array_map(static fn(array $specialist): array=>array_map(static fn(array $rule): string=>$rule['default'],
        $specialist['triggers']),vp3_coordination_rule_definitions_v1());
}

/** Apply only known trigger/action choices while preserving canonical key order. */
function vp3_coordination_rule_patch_v1(array $rules, mixed $patch): array
{
    if (!is_array($patch) || $patch === []) throw new InvalidArgumentException('Choose a Top of Mind rule.');
    $definitions = vp3_coordination_rule_definitions_v1();
    foreach ($patch as $specialist=>$choices) {
        // Preserve the released Product Marketing control record while the
        // report's metric/ref contract is consolidated. Reads never rewrite it.
        if($specialist==='marketing'&&is_array($choices)&&array_key_exists('product_outcome',$choices)){
            if(array_key_exists('promotion_period',$choices)&&$choices['promotion_period']!==$choices['product_outcome'])
                throw new InvalidArgumentException('Conflicting product marketing reporting rules.');
            $choices['promotion_period']=$choices['product_outcome'];unset($choices['product_outcome']);
        }
        $triggers = $definitions[$specialist]['triggers'] ?? null;
        if ($triggers === null || !is_array($choices) || $choices === []) {
            throw new InvalidArgumentException('Choose a known specialist and filter.');
        }
        foreach ($choices as $trigger=>$action) {
            if (!isset($triggers[$trigger]) || !is_string($action) || !isset(VP3_COORDINATION_RULE_ACTIONS_V1[$action])
                || (isset($triggers[$trigger]['actions']) && !in_array($action,$triggers[$trigger]['actions'],true))) {
                throw new InvalidArgumentException('Choose a valid action for the selected filter.');
            }
            $rules[$specialist][$trigger] = $action;
        }
    }
    return $rules;
}

/** Read the control record strictly so a storage failure cannot reopen delivery. */
function vp3_coordination_rules_v1(PDO $pdo, array $live, int $agentId): array
{
    $owner = (int)($live['id'] ?? 0);
    if ($owner < 1 || $agentId < 0) throw new RuntimeException('Agent access changed.', 403);
    if (!agent_brain_schema_ready()) throw new RuntimeException('Top of Mind rules are unavailable.', 503);
    [$scope,$params] = vp3_agent_memory_scope_sql_v410($agentId);
    $key = vp3_agent_memory_scope_hash_v410($agentId, agent_brain_v122_memory_hash('initiative_policy','top-of-mind-routing'));
    $stmt = $pdo->prepare('SELECT metadata_json,is_active FROM agent_memory_items WHERE user_id=? AND '.$scope.' AND memory_hash=? LIMIT 1');
    $stmt->execute([$owner,...$params,$key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $defaults = vp3_coordination_rule_defaults_v1();
    if (!$row) return ['revision'=>hash('sha256',json_encode($defaults, JSON_THROW_ON_ERROR)), 'rules'=>$defaults];

    $meta = json_decode((string)($row['metadata_json'] ?? ''), true);
    $revision = $meta['revision'] ?? null;
    if ((int)$row['is_active'] !== 1 || !is_string($revision) || !preg_match('/\A[a-f0-9]{64}\z/D', $revision)
        || ($meta['owner_user_id'] ?? null) !== $owner || ($meta['agent_id'] ?? null) !== $agentId) {
        throw new RuntimeException('Top of Mind rules are unavailable.', 503);
    }
    try {
        $rules = vp3_coordination_rule_patch_v1($defaults, $meta['rules'] ?? null);
    } catch (InvalidArgumentException $error) {
        throw new RuntimeException('Top of Mind rules are unavailable.', 503, $error);
    }
    return ['revision'=>$revision,'rules'=>$rules];
}

/** The first recognized trigger in the source's priority order owns the route. */
function vp3_coordination_route_v1(array $settings, string $specialist, array $triggerIds): array
{
    $triggers = vp3_coordination_rule_definitions_v1()[$specialist]['triggers'] ?? [];
    $choices = $settings['rules'][$specialist] ?? [];
    foreach ($triggerIds as $trigger) {
        if (!is_string($trigger) || !isset($triggers[$trigger])) continue;
        $action = $choices[$trigger] ?? null;
        return ['specialist'=>$specialist,'trigger'=>$trigger,
            'action'=>is_string($action)&&isset(VP3_COORDINATION_RULE_ACTIONS_V1[$action])?$action:'watch'];
    }
    return ['specialist'=>$specialist,'trigger'=>'routine','action'=>'watch'];
}

/** A work specialist's explicit delivery rule takes priority over private work and suggestions. */
function vp3_coordination_chat_route_v1(array $route): bool
{
    return in_array($route['specialist']??null,['gmail','planning','tasks','canvas','connected-sites','transcripts','marketing','profile'],true)
        && in_array($route['action']??null,['ask','report'],true);
}

/** Settings are shared by this owner's Agent and editable from any of its saved Chats. */
function vp3_coordination_rule_settings_v1(PDO $pdo, array $user, int $agentId, int $conversationId,
    ?string $expectedRevision = null, mixed $patch = null): array
{
    require_once __DIR__.'/agent-activity-scope-v1.php';
    require_once __DIR__.'/agent-commerce-recovery-pa2.php';
    if ($conversationId < 1 || $agentId < 0 || (int)($user['id'] ?? 0) < 1 || vp3_participant_scope_v1() !== null) {
        throw new RuntimeException('Open your private Agent Chat to manage Top of Mind rules.', 403);
    }
    $write = $expectedRevision !== null;
    $readOrSave = static function () use ($pdo,$user,$agentId,$conversationId,$expectedRevision,$patch,$write): array {
        if ($write) {
            require_once __DIR__.'/agent-initiative-v1.php';
            vp3_initiative_owner_lock_v1($pdo,$user);
        }
        $scope = vp3_agent_activity_scope_v1($pdo,$user,$agentId,$conversationId);
        $live = $scope['user'];
        if (!vp3_agent_chat_conversation_v380($pdo,$conversationId,(int)$live['id'],$scope['agent'],
            $write && !agent_commerce_sqlite_pa2($pdo))) {
            throw new RuntimeException('Agent Chat access changed.', 403);
        }
        $settings = vp3_coordination_rules_v1($pdo,$live,$agentId);
        if ($write) {
            if (!hash_equals($settings['revision'],$expectedRevision)) {
                throw new RuntimeException('Top of Mind rules changed. Refresh and try again.', 409);
            }
            $rules = vp3_coordination_rule_patch_v1($settings['rules'],$patch);
            if ($rules !== $settings['rules']) {
                $settings = ['revision'=>bin2hex(random_bytes(32)),'rules'=>$rules];
                $metadata = $settings + ['owner_user_id'=>(int)$live['id'],'agent_id'=>$agentId];
                $id = vp3_initiative_in_scope_v1($live,$agentId,static fn()=>agent_brain_v122_upsert_system_memory(
                    $live,'initiative_policy','top-of-mind-routing','Owner Top of Mind routing preferences.',$metadata));
                if ($id < 1) throw new RuntimeException('Top of Mind rules could not be saved.', 503);
                vp3_coordination_source_changed_v1($pdo,(int)$live['id'],$agentId);
            }
        }
        return ['owner_user_id'=>(int)$live['id'],'agent_id'=>$agentId,'conversation_id'=>$conversationId]
            + $settings + ['catalog'=>vp3_coordination_rule_catalog_v1()];
    };
    return $write ? agent_commerce_atomic_pa2($pdo,$readOrSave) : $readOrSave();
}
