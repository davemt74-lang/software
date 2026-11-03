<?php
/* Embedded into My Knowledge; no second folder library. */
$screenKnowledgeSession=null;
if(table_exists('agent_screen_sessions_v1')){
    require_once __DIR__.'/agent-screen-sessions-v1.php';
    try{
        if(!empty($_GET['screen_session']))$screenKnowledgeSession=screen_session_v1($pdo,$user,screen_session_id_v1($_GET['screen_session']));
        elseif(ctype_digit($folderFilter)){$sq=$pdo->prepare("SELECT * FROM agent_screen_sessions_v1 WHERE owner_user_id=? AND folder_id=? AND status<>'deleted' ORDER BY created_at DESC LIMIT 1");$sq->execute([$uid,(int)$folderFilter]);$screenKnowledgeSession=$sq->fetch(PDO::FETCH_ASSOC)?:null;}
    }catch(Throwable $ignored){}
}
if($screenKnowledgeSession):
?>
<section class="personal-knowledge-screen-session" data-screen-knowledge>
<h2>Sharing session · <?= e($screenKnowledgeSession['title']) ?></h2>
<p>Timestamped screenshots, transcripts and analyses from Agent Eyes. Notes below use your existing Knowledge folders and access settings.</p>
<div class="screen-session-actions"><button type="button" data-ks-refresh>Refresh captures</button><?php if($canManage):?><label><input type="checkbox" data-ks-remove-brain checked> Also remove this session’s Brain memories when deleting</label><button type="button" data-ks-delete>Delete session data</button><?php endif;?></div>
<p data-ks-status role="status"></p><div data-ks-captures></div>
</section>
<script>window.VP3_SCREEN_KNOWLEDGE=<?= json_encode(['endpoint'=>url('/api/agent-screen-sessions-v1.php'),'sessionId'=>$screenKnowledgeSession['session_id'],'ownerKey'=>(string)$uid,'csrf'=>csrf_token(),'canManage'=>$canManage],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="<?= e(url('/agent-screen-knowledge-v1.js?v=1')) ?>" defer></script>
<?php endif; ?>
