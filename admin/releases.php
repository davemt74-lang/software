<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_permission('admin.access');
permission_v105_require('release.manage');
artist_workspace_v181_guard_legacy_admin('releases');

$user = current_user();
$pdo = db();
if (!$pdo) {
    flash('error', 'Database unavailable.');
    redirect(url('/admin/index.php'));
}

if (!release_v105_schema_ready()) {
    $adminTitle = 'Release Calendar';
    $adminActive = 'releases';
    require __DIR__ . '/_header.php';
    ?>
    <div class="panel">
      <span class="status">Stonefellow v105</span>
      <h2>Release Operations needs the v105 database upgrade.</h2>
      <p class="muted">Import <code>upgrade-stonefellow-v105.sql</code>, then reload this page.</p>
    </div>
    <?php require __DIR__ . '/_footer.php'; exit;
}

$ownerId = release_v105_workspace_owner_id($user);
$releaseTypes = release_v105_release_types();
$releaseStatuses = release_v105_statuses();
$itemTypes = release_v105_item_types();
$itemStatuses = release_v105_item_statuses();
$resourceTypes = release_v105_resource_types();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash('error', 'Session expired. Try again.');
        redirect(url('/admin/releases.php'));
    }

    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'save_release') {
            $id = max(0, (int)($_POST['id'] ?? 0));
            $title = trim((string)($_POST['title'] ?? ''));
            if ($title === '') throw new RuntimeException('Release title is required.');
            $type = release_v105_clean_enum((string)($_POST['release_type'] ?? ''), $releaseTypes, 'single');
            $status = release_v105_clean_enum((string)($_POST['status'] ?? ''), $releaseStatuses, 'planning');
            $priority = in_array((string)($_POST['priority'] ?? ''), ['low','normal','high','critical'], true)
                ? (string)$_POST['priority'] : 'normal';
            $targetDate = release_v105_datetime_or_null((string)($_POST['target_date'] ?? ''));
            $goal = trim((string)($_POST['agent_goal'] ?? ''));
            $notes = trim((string)($_POST['notes'] ?? ''));

            if ($id > 0) {
                if (!release_v105_plan($user, $id)) throw new RuntimeException('Release plan not found.');
                $stmt = $pdo->prepare(
                    'UPDATE release_plans SET title=?,release_type=?,status=?,priority=?,target_date=?,agent_goal=?,notes=? WHERE id=? AND owner_user_id=?'
                );
                $stmt->execute([$title,$type,$status,$priority,$targetDate,$goal,$notes,$id,$ownerId]);
                agent_tool_log($user, 'release_calendar.update', $title, 'success', ['release_id'=>$id]);
                flash('notice', 'Release plan updated.');
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO release_plans (owner_user_id,created_by_user_id,title,release_type,status,priority,target_date,agent_goal,notes) VALUES (?,?,?,?,?,?,?,?,?)'
                );
                $stmt->execute([$ownerId,(int)$user['id'],$title,$type,$status,$priority,$targetDate,$goal,$notes]);
                $id = (int)$pdo->lastInsertId();
                agent_tool_log($user, 'release_calendar.create', $title, 'success', ['release_id'=>$id]);
                flash('notice', 'Release plan created.');
            }
            redirect(url('/admin/releases.php?release=' . $id));
        }

        if ($action === 'add_item') {
            $releaseId = max(0, (int)($_POST['release_id'] ?? 0));
            if (!release_v105_plan($user, $releaseId)) throw new RuntimeException('Release plan not found.');
            $title = trim((string)($_POST['title'] ?? ''));
            if ($title === '') throw new RuntimeException('Work item title is required.');
            $type = release_v105_clean_enum((string)($_POST['item_type'] ?? ''), $itemTypes, 'task');
            $status = release_v105_clean_enum((string)($_POST['status'] ?? ''), $itemStatuses, 'todo');
            $due = release_v105_datetime_or_null((string)($_POST['due_at'] ?? ''));
            $assigned = max(0, (int)($_POST['assigned_user_id'] ?? 0));
            $trackId = max(0, (int)($_POST['track_id'] ?? 0));
            $showId = max(0, (int)($_POST['show_id'] ?? 0));
            $instructions = trim((string)($_POST['instructions'] ?? ''));
            $stmt = $pdo->prepare(
                'INSERT INTO release_items (release_id,item_type,title,status,due_at,assigned_user_id,track_id,show_id,instructions) VALUES (?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([$releaseId,$type,$title,$status,$due,$assigned ?: null,$trackId ?: null,$showId ?: null,$instructions]);
            agent_tool_log($user, 'release_calendar.item_create', $title, 'success', ['release_id'=>$releaseId,'item_id'=>(int)$pdo->lastInsertId()]);
            flash('notice', 'Release work item added.');
            redirect(url('/admin/releases.php?release=' . $releaseId));
        }

        if ($action === 'item_status') {
            $releaseId = max(0, (int)($_POST['release_id'] ?? 0));
            $itemId = max(0, (int)($_POST['item_id'] ?? 0));
            if (!release_v105_plan($user, $releaseId)) throw new RuntimeException('Release plan not found.');
            $status = release_v105_clean_enum((string)($_POST['status'] ?? ''), $itemStatuses, 'todo');
            $stmt = $pdo->prepare(
                'UPDATE release_items SET status=?,completed_at=IF(?="complete",COALESCE(completed_at,NOW()),NULL) WHERE id=? AND release_id=?'
            );
            $stmt->execute([$status,$status,$itemId,$releaseId]);
            agent_tool_log($user, 'release_calendar.item_status', $status, 'success', ['release_id'=>$releaseId,'item_id'=>$itemId]);
            redirect(url('/admin/releases.php?release=' . $releaseId));
        }

        if ($action === 'add_resource') {
            $title = trim((string)($_POST['title'] ?? ''));
            if ($title === '') throw new RuntimeException('Resource title is required.');
            $type = release_v105_clean_enum((string)($_POST['resource_type'] ?? ''), $resourceTypes, 'document');
            $uri = trim((string)($_POST['resource_uri'] ?? ''));
            $provider = mb_substr(trim((string)($_POST['provider_key'] ?? '')), 0, 80);
            $externalId = mb_substr(trim((string)($_POST['external_id'] ?? '')), 0, 255);
            $stmt = $pdo->prepare(
                'INSERT INTO agent_resources (owner_user_id,resource_type,title,resource_uri,provider_key,external_id) VALUES (?,?,?,?,?,?)'
            );
            $stmt->execute([$ownerId,$type,$title,mb_substr($uri,0,1000),$provider,$externalId]);
            agent_tool_log($user, 'release_resources.create', $title, 'success', ['resource_id'=>(int)$pdo->lastInsertId(),'type'=>$type]);
            flash('notice', 'Agent resource added.');
            redirect(url('/admin/releases.php?release=' . max(0,(int)($_POST['release_id'] ?? 0)) . '#resources'));
        }

        if ($action === 'link_resource') {
            $releaseId = max(0, (int)($_POST['release_id'] ?? 0));
            $itemId = max(0, (int)($_POST['item_id'] ?? 0));
            $resourceId = max(0, (int)($_POST['resource_id'] ?? 0));
            if (!release_v105_plan($user, $releaseId)) throw new RuntimeException('Release plan not found.');
            $check = $pdo->prepare('SELECT id FROM release_items WHERE id=? AND release_id=?');
            $check->execute([$itemId,$releaseId]);
            if (!$check->fetchColumn()) throw new RuntimeException('Release item not found.');
            $resource = $pdo->prepare('SELECT id FROM agent_resources WHERE id=? AND owner_user_id=? AND is_active=1');
            $resource->execute([$resourceId,$ownerId]);
            if (!$resource->fetchColumn()) throw new RuntimeException('Resource not found.');
            $stmt = $pdo->prepare('INSERT IGNORE INTO release_item_resources (release_item_id,resource_id) VALUES (?,?)');
            $stmt->execute([$itemId,$resourceId]);
            flash('notice', 'Resource linked to work item.');
            redirect(url('/admin/releases.php?release=' . $releaseId));
        }

        if ($action === 'queue_action') {
            $releaseId = max(0, (int)($_POST['release_id'] ?? 0));
            $itemId = max(0, (int)($_POST['item_id'] ?? 0));
            $provider = mb_substr(trim((string)($_POST['provider_key'] ?? '')), 0, 80);
            $actionType = mb_substr(trim((string)($_POST['action_type'] ?? '')), 0, 80);
            if ($actionType === '') throw new RuntimeException('Action type is required.');
            $scheduled = release_v105_datetime_or_null((string)($_POST['scheduled_for'] ?? ''));
            $instructions = trim((string)($_POST['action_instructions'] ?? ''));
            $actionId = release_v105_enqueue_action(
                $user,$provider,$actionType,['instructions'=>$instructions],$releaseId,$itemId,true,$scheduled,'manual'
            );
            agent_tool_log($user, 'release_actions.draft', $instructions ?: $actionType, 'success', ['action_id'=>$actionId,'release_id'=>$releaseId,'item_id'=>$itemId]);
            flash('notice', 'Agent action drafted. It requires approval before an external side effect.');
            redirect(url('/admin/releases.php?release=' . $releaseId . '#actions'));
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect(url('/admin/releases.php?release=' . max(0,(int)($_POST['release_id'] ?? 0))));
    }
}

$plans = release_v105_plans($user, 150);
$selectedId = max(0, (int)($_GET['release'] ?? 0));
if ($selectedId < 1 && $plans) $selectedId = (int)$plans[0]['id'];
$selected = $selectedId > 0 ? release_v105_plan($user, $selectedId) : null;
$items = $selected ? release_v105_items($user, $selectedId) : [];
$resources = release_v105_resources($user, 200);
$integrations = release_v105_integrations($user);

$teamStmt = $pdo->prepare(
    'SELECT u.id,u.display_name FROM users u
     WHERE u.id=? OR u.id IN (SELECT member_user_id FROM artist_team_members WHERE artist_user_id=?)
     ORDER BY u.display_name'
);
$teamStmt->execute([$ownerId,$ownerId]);
$team = $teamStmt->fetchAll() ?: [];
$tracksStmt = $pdo->prepare('SELECT id,title FROM tracks WHERE owner_user_id=? OR producer_user_id=? ORDER BY updated_at DESC,title LIMIT 200');
$tracksStmt->execute([$ownerId,$ownerId]);
$tracks = $tracksStmt->fetchAll() ?: [];
$showsStmt = $pdo->prepare('SELECT id,show_date,venue,city FROM shows WHERE owner_user_id=? OR owner_user_id IS NULL ORDER BY show_date DESC LIMIT 150');
$showsStmt->execute([$ownerId]);
$shows = $showsStmt->fetchAll() ?: [];

$monthRaw = (string)($_GET['month'] ?? date('Y-m'));
$monthTs = strtotime($monthRaw . '-01') ?: strtotime(date('Y-m-01'));
$monthStart = date('Y-m-01', $monthTs);
$monthEnd = date('Y-m-t', $monthTs);
$firstWeekday = (int)date('N', $monthTs);
$daysInMonth = (int)date('t', $monthTs);
$calendarEvents = [];
foreach ($plans as $plan) {
    if (!empty($plan['target_date'])) {
        $day = date('Y-m-d', strtotime((string)$plan['target_date']));
        if ($day >= $monthStart && $day <= $monthEnd) $calendarEvents[$day][] = ['kind'=>'release','title'=>(string)$plan['title'],'id'=>(int)$plan['id']];
    }
}
if ($plans) {
    $releaseIds = array_map(static fn(array $r): int => (int)$r['id'], $plans);
    $placeholders = implode(',', array_fill(0, count($releaseIds), '?'));
    $stmt = $pdo->prepare("SELECT id,release_id,title,due_at,status FROM release_items WHERE release_id IN ($placeholders) AND due_at BETWEEN ? AND ? ORDER BY due_at");
    $stmt->execute([...$releaseIds,$monthStart . ' 00:00:00',$monthEnd . ' 23:59:59']);
    foreach ($stmt->fetchAll() as $row) {
        $day = date('Y-m-d', strtotime((string)$row['due_at']));
        $calendarEvents[$day][] = ['kind'=>'item','title'=>(string)$row['title'],'id'=>(int)$row['release_id'],'status'=>(string)$row['status']];
    }
}

$actions = [];
if ($selected) {
    $stmt = $pdo->prepare('SELECT * FROM agent_work_actions WHERE owner_user_id=? AND release_id=? ORDER BY id DESC LIMIT 80');
    $stmt->execute([$ownerId,$selectedId]);
    $actions = $stmt->fetchAll() ?: [];
}

$adminTitle = 'Release Calendar';
$adminActive = 'releases';
require __DIR__ . '/_header.php';
?>
<style>
.release-v105-grid{display:grid;grid-template-columns:minmax(0,1.55fr) minmax(320px,.85fr);gap:18px}.release-v105-calendar{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:7px}.release-v105-day{min-height:112px;border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:8px;background:rgba(255,255,255,.02)}.release-v105-day.muted{opacity:.35}.release-v105-day>strong{font-size:12px}.release-v105-event{display:block;margin-top:6px;padding:6px 7px;border-radius:8px;background:rgba(255,255,255,.06);font-size:11px;line-height:1.25;text-decoration:none}.release-v105-event.item{border-left:2px solid rgba(255,255,255,.24)}.release-v105-list{display:grid;gap:10px}.release-v105-item{border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:12px}.release-v105-item-head{display:flex;gap:12px;justify-content:space-between;align-items:flex-start}.release-v105-meta{display:flex;gap:7px;flex-wrap:wrap;margin-top:7px}.release-v105-meta span{font-size:11px;padding:4px 7px;border-radius:999px;background:rgba(255,255,255,.055)}.release-v105-two{display:grid;grid-template-columns:1fr 1fr;gap:12px}.release-v105-three{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}.release-v105-side-list{display:grid;gap:7px}.release-v105-side-list a{display:block;padding:10px;border-radius:10px;border:1px solid rgba(255,255,255,.07);text-decoration:none}.release-v105-side-list a.active{background:rgba(255,255,255,.08)}.release-v105-resource{padding:9px 0;border-bottom:1px solid rgba(255,255,255,.06)}.release-v105-resource:last-child{border-bottom:0}@media(max-width:980px){.release-v105-grid,.release-v105-two,.release-v105-three{grid-template-columns:1fr}.release-v105-calendar{grid-template-columns:repeat(2,1fr)}}
</style>

<div class="release-v105-grid">
  <div>
    <div class="panel">
      <div class="content-library-heading">
        <div><span class="status">Agent Operations</span><h2><?= e(date('F Y',$monthTs)) ?></h2><p class="muted">Songs, assets, outreach, shows and deadlines share one Agent Brain planning surface.</p></div>
        <div class="actions"><a class="btn" href="<?= e(url('/admin/releases.php?month='.date('Y-m',strtotime('-1 month',$monthTs)).($selected?'&release='.$selectedId:''))) ?>">←</a><a class="btn" href="<?= e(url('/admin/releases.php?month='.date('Y-m').($selected?'&release='.$selectedId:''))) ?>">Today</a><a class="btn" href="<?= e(url('/admin/releases.php?month='.date('Y-m',strtotime('+1 month',$monthTs)).($selected?'&release='.$selectedId:''))) ?>">→</a></div>
      </div>
      <div class="release-v105-calendar">
        <?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $label): ?><div class="muted" style="padding:0 8px 4px;font-size:11px"><?= e($label) ?></div><?php endforeach; ?>
        <?php for($blank=1;$blank<$firstWeekday;$blank++): ?><div class="release-v105-day muted"></div><?php endfor; ?>
        <?php for($day=1;$day<=$daysInMonth;$day++): $dateKey=date('Y-m-', $monthTs).str_pad((string)$day,2,'0',STR_PAD_LEFT); ?>
          <div class="release-v105-day"><strong><?= $day ?></strong>
            <?php foreach($calendarEvents[$dateKey]??[] as $event): ?><a class="release-v105-event <?= e($event['kind']) ?>" href="<?= e(url('/admin/releases.php?release='.$event['id'].'&month='.date('Y-m',$monthTs))) ?>"><?= e($event['kind']==='release'?'RELEASE · '.$event['title']:$event['title']) ?></a><?php endforeach; ?>
          </div>
        <?php endfor; ?>
      </div>
    </div>

    <?php if ($selected): ?>
    <div class="panel">
      <div class="content-library-heading"><div><span class="status"><?= e($releaseStatuses[(string)$selected['status']]??(string)$selected['status']) ?></span><h2><?= e((string)$selected['title']) ?></h2><p class="muted"><?= e((string)($selected['agent_goal'] ?: 'Give Agent Brain a goal so it can reason about priorities and next actions.')) ?></p></div><div class="actions"><span class="status"><?= !empty($selected['target_date'])?e(date('M j, Y',strtotime((string)$selected['target_date']))):'No date' ?></span></div></div>
      <div class="release-v105-list">
        <?php foreach($items as $item): ?>
          <article class="release-v105-item">
            <div class="release-v105-item-head"><div><strong><?= e((string)$item['title']) ?></strong><div class="release-v105-meta"><span><?= e($itemTypes[(string)$item['item_type']]??(string)$item['item_type']) ?></span><?php if($item['due_at']): ?><span><?= e(date('M j · g:i A',strtotime((string)$item['due_at']))) ?></span><?php endif; ?><?php if($item['assignee_name']): ?><span><?= e((string)$item['assignee_name']) ?></span><?php endif; ?><span><?= (int)$item['resource_count'] ?> resources</span><span><?= (int)$item['pending_action_count'] ?> agent actions</span></div></div>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="item_status"><input type="hidden" name="release_id" value="<?= $selectedId ?>"><input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>"><select name="status" onchange="this.form.submit()"><?php foreach($itemStatuses as $value=>$label): ?><option value="<?= e($value) ?>" <?= $item['status']===$value?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></form>
            </div>
            <?php if(trim((string)$item['instructions'])!==''): ?><p class="muted"><?= e((string)$item['instructions']) ?></p><?php endif; ?>
            <?php if($resources): ?><form method="post" class="actions" style="margin-top:10px"><?= csrf_field() ?><input type="hidden" name="action" value="link_resource"><input type="hidden" name="release_id" value="<?= $selectedId ?>"><input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>"><select name="resource_id" required><option value="">Link resource…</option><?php foreach($resources as $resource): ?><option value="<?= (int)$resource['id'] ?>"><?= e((string)$resource['title']) ?> · <?= e((string)$resource['resource_type']) ?></option><?php endforeach; ?></select><button class="btn" type="submit">Link</button></form><?php endif; ?>
            <details style="margin-top:10px"><summary>Draft Agent action</summary><form method="post" class="grid-form" style="margin-top:10px"><?= csrf_field() ?><input type="hidden" name="action" value="queue_action"><input type="hidden" name="release_id" value="<?= $selectedId ?>"><input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>"><div class="field"><label>Provider</label><input name="provider_key" placeholder="gmail, sms, social…"></div><div class="field"><label>Action</label><input name="action_type" required placeholder="send_email, send_sms, publish…"></div><div class="field"><label>Schedule</label><input name="scheduled_for" type="datetime-local"></div><div class="field full"><label>Instructions</label><textarea name="action_instructions" maxlength="4000"></textarea></div><div class="field full"><button class="btn primary" type="submit">Create approval-required action</button></div></form></details>
          </article>
        <?php endforeach; ?>
        <?php if(!$items): ?><p class="muted">No work items yet. Add the concrete assets, deadlines and outreach work the Agent should coordinate.</p><?php endif; ?>
      </div>
    </div>

    <div class="panel">
      <h2>Add Release Work</h2>
      <form method="post" class="grid-form"><?= csrf_field() ?><input type="hidden" name="action" value="add_item"><input type="hidden" name="release_id" value="<?= $selectedId ?>">
        <div class="field"><label>Title</label><input name="title" required maxlength="190"></div><div class="field"><label>Type</label><select name="item_type"><?php foreach($itemTypes as $v=>$l): ?><option value="<?= e($v) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>Due</label><input name="due_at" type="datetime-local"></div><div class="field"><label>Assigned to</label><select name="assigned_user_id"><option value="">Unassigned</option><?php foreach($team as $member): ?><option value="<?= (int)$member['id'] ?>"><?= e((string)$member['display_name']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>Track</label><select name="track_id"><option value="">None</option><?php foreach($tracks as $track): ?><option value="<?= (int)$track['id'] ?>"><?= e((string)$track['title']) ?></option><?php endforeach; ?></select></div><div class="field"><label>Show</label><select name="show_id"><option value="">None</option><?php foreach($shows as $show): ?><option value="<?= (int)$show['id'] ?>"><?= e(date('M j',strtotime((string)$show['show_date'])).' · '.$show['venue'].' · '.$show['city']) ?></option><?php endforeach; ?></select></div>
        <div class="field full"><label>Agent instructions / definition of done</label><textarea name="instructions" maxlength="5000"></textarea></div><div class="field full"><button class="btn primary" type="submit">Add Work Item</button></div>
      </form>
    </div>
    <?php endif; ?>
  </div>

  <aside>
    <div class="panel"><div class="content-library-heading"><div><span class="status">Releases</span><h2>Plans</h2></div><a class="btn primary" href="#release-form">+ Release</a></div><div class="release-v105-side-list"><?php foreach($plans as $plan): ?><a class="<?= (int)$plan['id']===$selectedId?'active':'' ?>" href="<?= e(url('/admin/releases.php?release='.(int)$plan['id'])) ?>"><strong><?= e((string)$plan['title']) ?></strong><br><small class="muted"><?= (int)$plan['complete_count'] ?>/<?= (int)$plan['item_count'] ?> complete<?= $plan['target_date']?' · '.e(date('M j',strtotime((string)$plan['target_date']))):'' ?></small></a><?php endforeach; ?><?php if(!$plans): ?><p class="muted">No release plans yet.</p><?php endif; ?></div></div>

    <div class="panel" id="release-form"><h2><?= $selected?'Edit Release':'New Release' ?></h2><form method="post" class="grid-form"><?= csrf_field() ?><input type="hidden" name="action" value="save_release"><input type="hidden" name="id" value="<?= (int)($selected['id']??0) ?>"><div class="field full"><label>Title</label><input name="title" required maxlength="190" value="<?= e((string)($selected['title']??'')) ?>"></div><div class="field"><label>Type</label><select name="release_type"><?php foreach($releaseTypes as $v=>$l): ?><option value="<?= e($v) ?>" <?= ($selected['release_type']??'single')===$v?'selected':'' ?>><?= e($l) ?></option><?php endforeach; ?></select></div><div class="field"><label>Status</label><select name="status"><?php foreach($releaseStatuses as $v=>$l): ?><option value="<?= e($v) ?>" <?= ($selected['status']??'planning')===$v?'selected':'' ?>><?= e($l) ?></option><?php endforeach; ?></select></div><div class="field"><label>Target</label><input name="target_date" type="datetime-local" value="<?= !empty($selected['target_date'])?e(date('Y-m-d\TH:i',strtotime((string)$selected['target_date']))):'' ?>"></div><div class="field"><label>Priority</label><select name="priority"><?php foreach(['low'=>'Low','normal'=>'Normal','high'=>'High','critical'=>'Critical'] as $v=>$l): ?><option value="<?= e($v) ?>" <?= ($selected['priority']??'normal')===$v?'selected':'' ?>><?= e($l) ?></option><?php endforeach; ?></select></div><div class="field full"><label>Agent Goal</label><textarea name="agent_goal" maxlength="5000" placeholder="What outcome should Agent Brain coordinate toward?"><?= e((string)($selected['agent_goal']??'')) ?></textarea></div><div class="field full"><label>Notes</label><textarea name="notes" maxlength="5000"><?= e((string)($selected['notes']??'')) ?></textarea></div><div class="field full"><button class="btn primary" type="submit">Save Release Plan</button></div></form></div>

    <div class="panel" id="resources"><span class="status">Agent Brain Resources</span><h2>Resources</h2><p class="muted">Lists, documents, websites, media and future connector objects can be attached to release work.</p><div><?php foreach(array_slice($resources,0,12) as $resource): ?><div class="release-v105-resource"><strong><?= e((string)$resource['title']) ?></strong><br><small class="muted"><?= e((string)$resource['resource_type']) ?><?= $resource['provider_key']?' · '.e((string)$resource['provider_key']):'' ?></small></div><?php endforeach; ?></div><details style="margin-top:12px"><summary>Add resource</summary><form method="post" class="grid-form" style="margin-top:10px"><?= csrf_field() ?><input type="hidden" name="action" value="add_resource"><input type="hidden" name="release_id" value="<?= $selectedId ?>"><div class="field full"><label>Title</label><input name="title" required></div><div class="field"><label>Type</label><select name="resource_type"><?php foreach($resourceTypes as $v=>$l): ?><option value="<?= e($v) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div><div class="field"><label>Provider</label><input name="provider_key" placeholder="gmail, drive, website…"></div><div class="field full"><label>URL / object reference</label><input name="resource_uri" maxlength="1000"></div><div class="field full"><label>External ID (optional)</label><input name="external_id"></div><div class="field full"><button class="btn" type="submit">Add Resource</button></div></form></details></div>

    <div class="panel" id="actions"><span class="status">Tool Layer</span><h2>Integrations + Actions</h2><?php if($integrations): foreach($integrations as $integration): ?><div class="release-v105-resource"><strong><?= e((string)($integration['label']?:$integration['provider_key'])) ?></strong><br><small class="muted"><?= e((string)$integration['status']) ?> · <?= e((string)$integration['provider_key']) ?></small></div><?php endforeach; else: ?><p class="muted">No external providers connected yet. The v105 action queue is ready for Gmail, SMS, social publishing, document and other adapters.</p><?php endif; ?><?php if($actions): ?><h3>Draft / Recent Actions</h3><?php foreach(array_slice($actions,0,12) as $row): ?><div class="release-v105-resource"><strong><?= e((string)$row['action_type']) ?></strong><br><small class="muted"><?= e((string)($row['provider_key']?:'internal')) ?> · <?= e((string)$row['status']) ?><?= (int)$row['requires_approval']?' · approval required':'' ?></small></div><?php endforeach; ?><?php endif; ?></div>
  </aside>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
