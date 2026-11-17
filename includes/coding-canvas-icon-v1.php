<?php
declare(strict_types=1);

require_once __DIR__.'/coding-canvas-jobs-v1.php';
require_once __DIR__.'/agent-image-generation-v1.php';

function vp3_coding_canvas_icon_context_v1(PDO $pdo,array $user,int $agent,int $chat,string $canvasId,int $revision): array
{
    if ($chat < 1 || $revision < 1) throw new InvalidArgumentException('Choose the current Canvas preview.');
    $id = vp3_coding_canvas_id_v1($canvasId);
    $user = vp3_coding_canvas_scope_v1($pdo,$user,$agent,$chat);
    $agentRow = vp3_coding_canvas_authority_v1($pdo,$user,$agent,true);
    $canvas = vp3_coding_canvas_read_v1($pdo,$user,$agent,$id,$revision);
    $version = $canvas['revision'];
    if ($version['test_passed'] !== true || !in_array($version['status'],['active','draft'],true)) {
        throw new DomainException('Test the current Canvas version before generating an icon.',409);
    }
    $origin = $canvas['canvas']['conversation_id'];
    if (!vp3_agent_chat_conversation_v380($pdo,$origin,(int)$user['id'],$agentRow)) {
        throw new RuntimeException('The Canvas conversation is unavailable.',404);
    }
    return ['user'=>$user,'canvas'=>$canvas['canvas'],'revision'=>$revision,'origin'=>$origin];
}

function vp3_coding_canvas_icon_request_v1(int $owner,int $agent,string $id,?string $replace): string
{
    return hash('sha256',json_encode(['canvas-icon-v1',$owner,$agent,$id,$replace],JSON_THROW_ON_ERROR));
}

function vp3_coding_canvas_icon_asset_verified_v1(PDO $pdo,array $row,array $asset): bool
{
    $query = $pdo->prepare("SELECT a.result_json AS action_result,p.result_json AS receipt_result
        FROM agent_workflow_actions a JOIN agent_workflow_receipts p ON p.run_id=a.run_id AND p.action_id=a.id
            AND p.owner_user_id=a.owner_user_id AND p.attempt_no=a.attempt_count AND p.executor='cloud' AND p.status='completed'
        WHERE a.owner_user_id=? AND a.run_id=? AND a.sequence_no=1 AND a.capability_key='images.generate'
            AND a.status='completed' ORDER BY p.id DESC LIMIT 1");
    $query->execute([(int)$row['owner_user_id'],(int)$row['run_id']]);
    $proof = $query->fetch(PDO::FETCH_ASSOC);
    if (!$proof || !is_string($proof['action_result']) || $proof['action_result']!==$proof['receipt_result']) return false;
    $result = json_decode($proof['action_result'],true,16);
    return ($result['effect_state']??null)==='image_saved' && ($result['generation_id']??null)===$row['generation_id']
        && (int)($result['asset_id']??0)===$asset['id'];
}

/** Authorize on both sides of the image provider call using the frozen, tested source. */
function vp3_coding_canvas_icon_job_source_v1(PDO $pdo,array $user,array $row,array $config): void
{
    $source = $config['canvas_icon'] ?? null;
    if (($config['mode'] ?? null) !== 'canvas_icon' || !is_array($source)
        || !is_string($source['id'] ?? null) || !is_int($source['revision'] ?? null)
        || (isset($source['replace_hash']) && (!is_string($source['replace_hash']) || !preg_match('/\A[a-f0-9]{64}\z/D',$source['replace_hash'])))) {
        throw new RuntimeException('canvas_icon_source_changed',409);
    }
    $owner = (int)$user['id'];
    $agent = (int)$row['agent_id'];
    $request = vp3_coding_canvas_icon_request_v1($owner,$agent,$source['id'],$source['replace_hash']??null);
    $hash = vp3_image_workbench_key_v1($owner,$request);
    $generation = substr($hash,0,8).'-'.substr($hash,8,4).'-'.substr($hash,12,4).'-'.substr($hash,16,4).'-'.substr($hash,20,12);
    if (!hash_equals($generation,$row['generation_id'])) throw new RuntimeException('canvas_icon_source_changed',409);
    try {
        $context = vp3_coding_canvas_icon_context_v1($pdo,$user,$agent,(int)$row['conversation_id'],$source['id'],$source['revision']);
        $canvas = $context['canvas'];
        if ($context['origin'] !== (int)$row['conversation_id']
            || ($canvas['icon_hash'] ?? null) !== ($source['replace_hash'] ?? null)) {
            throw new RuntimeException('The Canvas artwork changed.',409);
        }
    } catch (RuntimeException|DomainException $error) {
        if ($error instanceof PDOException) throw $error;
        throw new RuntimeException('canvas_icon_source_changed',409,$error);
    }
}

/** One image job per Canvas and prior icon. Its output can be picked up after reloading Chat. */
function vp3_coding_canvas_icon_step_v1(PDO $pdo,array $user,int $agent,int $chat,string $id,int $revision,?string $replace,bool $advance,
    ?callable $transport=null): array
{
    $context = vp3_coding_canvas_icon_context_v1($pdo,$user,$agent,$chat,$id,$revision);
    $user = $context['user'];
    $canvas = $context['canvas'];
    if (($canvas['icon_hash']??null) !== $replace) {
        if ($replace === null && !empty($canvas['icon_url'])) return ['status'=>'saved'];
        throw new DomainException('The app icon changed. Open the Canvas again before replacing it.',409);
    }
    vp3_image_queue_ready_v1($pdo,$user,false);
    $owner = (int)$user['id'];
    $request = vp3_coding_canvas_icon_request_v1($owner,$agent,$id,$replace);
    $hash = vp3_image_workbench_key_v1($owner,$request);
    $generation = substr($hash,0,8).'-'.substr($hash,8,4).'-'.substr($hash,12,4).'-'.substr($hash,16,4).'-'.substr($hash,20,12);
    $row = null;
    if (table_exists('agent_image_generations_v1')) {
        try { $row = vp3_image_row_v1($pdo,$user,$generation); }
        catch (RuntimeException $error) { if ($error->getCode() !== 404) throw $error; }
    }
    if ($row === null && !$advance) return ['status'=>'unavailable'];
    if ($row === null) {
        $origin = $context['origin'];
        $source = ['id'=>$id,'revision'=>$revision,'replace_hash'=>$replace];
        $title = mb_substr(preg_replace('/[\x00-\x1f\x7f]+/u',' ',trim($canvas['title']))??'App',0,140);
        $config = vp3_image_text_plan_v1([
            'title'=>$title.' app icon','size'=>'1024x1024','quality'=>'low','background'=>'opaque',
            'prompt'=>'Design one square, high-contrast app icon. Use the app name as a label describing the app purpose: '
                .json_encode($title,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)
                .'. Draw one simple centered shape that reads at 15 pixels. Fill most of the square. No lettering, numbers, interface mockups, background scenes, people, or logos.',
        ])+['mode'=>'canvas_icon','canvas_icon'=>$source];
        $principal = ['kind'=>$agent>0?'user_agent':'system','owner_user_id'=>$owner,'agent_id'=>$agent];
        vp3_image_queue_work_v1($pdo,$user,$principal,['conversation_id'=>$origin],$request,
            static fn(string $model): array => $config+['model'=>$model]);
        $row = vp3_image_row_v1($pdo,$user,$generation);
    }
    $config = json_decode($row['config_json'],true,16,JSON_THROW_ON_ERROR);
    if (($config['mode']??null)!=='canvas_icon' || ($config['canvas_icon']['id']??null)!==$id
        || ($config['canvas_icon']['replace_hash']??null)!==$replace || (int)$row['conversation_id']!==$context['origin']
        || (int)$row['agent_id']!==$agent) throw new RuntimeException('The Canvas icon request is unavailable.',409);
    $view = vp3_image_view_v1($pdo,$user,$row);
    if ($advance && $view['status']==='queued') $view = vp3_image_tick_v1($pdo,$user,$row,$transport);
    $asset = $view['asset']??null;
    if ($view['status']==='completed' && is_array($asset) && $asset['mime_type']==='image/png'
        && $asset['source']==='openai_image' && vp3_coding_canvas_icon_asset_verified_v1($pdo,$row,$asset)) {
        return ['status'=>'completed','asset_id'=>$asset['id'],'image_url'=>$asset['url']];
    }
    return ['status'=>in_array($view['status'],['queued','generating'],true)?$view['status']:'unavailable'];
}
