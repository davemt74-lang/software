<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';require_permission('account.access');
$user=current_user();if(!$user)redirect(url('/login.php'));
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>HomeServer data · VP3</title><link rel="stylesheet" href="<?=e(url('/chat-v82.css'))?>"><link rel="stylesheet" href="<?=e(url('/workspace-data-v1.css'))?>"></head><body><div class="chat-app">
<?php $workspaceSidebarUser=$user;$workspaceSidebarActive='workspace_data';require __DIR__.'/includes/workspace-sidebar-v82.php';?>
<div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div><main class="chat-main"><section class="workspace-data-canvas"><h1>HomeServer data</h1><p>Read your automatically synchronized HomeServer records here. Source records remain authoritative; schedules run only on their source.</p><p id="workspaceDataStatus" role="status" aria-live="polite">Loading synchronization status…</p><label>Workspace <select id="workspaceDataDataset"></select></label><label>Search <input type="search" id="workspaceDataSearch" maxlength="240" placeholder="Search synchronized records"></label><div id="workspaceDataRecords"></div><div class="workspace-data-pages"><button id="workspaceDataPrevious" type="button">Previous</button><span id="workspaceDataPage"></span><button id="workspaceDataNext" type="button">Next</button></div><dialog id="workspaceDataDetail"><form method="dialog"><button>Close</button></form><h2 id="workspaceDataTitle"></h2><dl id="workspaceDataText"></dl></dialog></section></main></div>
<script src="<?=e(url('/workspace-data-v1.js'))?>" defer data-endpoint="<?=e(url('/api/workspace-data-v1.php'))?>"></script></body></html>
