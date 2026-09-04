<?php
declare(strict_types=1);

function release_v105_post_guard(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script !== 'releases.php') return;

    $user = current_user();
    $pdo = db();
    if (!$user || !$pdo || !release_v105_schema_ready()) return;

    $owner = release_v105_workspace_owner_id($user);
    $action = (string)($_POST['action'] ?? '');
    $releaseId = max(0, (int)($_POST['release_id'] ?? $_POST['id'] ?? 0));

    $fail = static function(string $message) use ($releaseId): never {
        flash('error', $message);
        redirect(url('/admin/releases.php' . ($releaseId > 0 ? '?release=' . $releaseId : '')));
    };

    if ($releaseId > 0 && in_array($action, ['save_release','add_item','item_status','link_resource','queue_action'], true)) {
        if (!release_v105_plan($user, $releaseId)) $fail('That release plan is not part of your workspace.');
    }

    if ($action === 'add_item') {
        $assigned = max(0, (int)($_POST['assigned_user_id'] ?? 0));
        if ($assigned > 0) {
            $stmt = $pdo->prepare(
                'SELECT 1 FROM users u
                 WHERE u.id=? AND u.is_active=1 AND (
                   u.id=? OR EXISTS(
                     SELECT 1 FROM artist_team_members atm
                     WHERE atm.artist_user_id=? AND atm.member_user_id=u.id
                   )
                 ) LIMIT 1'
            );
            $stmt->execute([$assigned,$owner,$owner]);
            if (!$stmt->fetchColumn()) $fail('That assignee is not part of this Artist workspace.');
        }

        $trackId = max(0, (int)($_POST['track_id'] ?? 0));
        if ($trackId > 0) {
            $stmt = $pdo->prepare(
                'SELECT 1 FROM tracks t
                 WHERE t.id=? AND (
                   t.owner_user_id=? OR t.producer_user_id=? OR EXISTS(
                     SELECT 1 FROM artist_team_members atm
                     WHERE atm.artist_user_id=? AND atm.member_user_id=t.producer_user_id
                   )
                 ) LIMIT 1'
            );
            $stmt->execute([$trackId,$owner,$owner,$owner]);
            if (!$stmt->fetchColumn()) $fail('That track is not part of this Artist workspace.');
        }

        $showId = max(0, (int)($_POST['show_id'] ?? 0));
        if ($showId > 0) {
            $stmt = $pdo->prepare('SELECT 1 FROM shows WHERE id=? AND (owner_user_id=? OR owner_user_id IS NULL) LIMIT 1');
            $stmt->execute([$showId,$owner]);
            if (!$stmt->fetchColumn()) $fail('That show is not available to this Artist workspace.');
        }
    }

    if (in_array($action, ['item_status','link_resource','queue_action'], true)) {
        $itemId = max(0, (int)($_POST['item_id'] ?? 0));
        if ($itemId < 1) $fail('Release work item is required.');
        $stmt = $pdo->prepare('SELECT 1 FROM release_items WHERE id=? AND release_id=? LIMIT 1');
        $stmt->execute([$itemId,$releaseId]);
        if (!$stmt->fetchColumn()) $fail('That work item is not part of this release.');
    }

    if ($action === 'link_resource') {
        $resourceId = max(0, (int)($_POST['resource_id'] ?? 0));
        $stmt = $pdo->prepare('SELECT 1 FROM agent_resources WHERE id=? AND owner_user_id=? AND is_active=1 LIMIT 1');
        $stmt->execute([$resourceId,$owner]);
        if (!$stmt->fetchColumn()) $fail('That resource is not part of this Agent workspace.');
    }
}

release_v105_post_guard();
