<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';

$pdo=vp3_live_room_require_ready_v2070(db());
$user=current_user();
$userId=(int)($user['id']??0);
$error='';

if($_SERVER['REQUEST_METHOD']==='POST'&&$userId>0){
    if(!verify_csrf())$error='Session expired. Please try again.';
    else{
        try{
            $scope=(string)($_POST['scope']??'public');
            $teamId=$scope==='team'?(int)($_POST['team_id']??0):0;
            $room=vp3_live_room_create_v2070($pdo,$userId,[
                'title'=>(string)($_POST['title']??''),
                'scope'=>$scope,
                'team_id'=>$teamId,
                'allow_cloak'=>!empty($_POST['allow_cloak']),
                'cloak_mode'=>!empty($_POST['cloak_mode']),
            ]);
            redirect((string)$room['url']);
        }catch(Throwable $e){$error=$e->getMessage();}
    }
}

$rooms=vp3_live_room_list_v2070($pdo,$userId,80);
$teams=[];
if($userId>0){
    try{
        $dest=vp3_browser_share_destinations_v2010($pdo,$userId);
        foreach((array)($dest['teams']??[]) as $row){
            if((string)($row['kind']??'')==='team_general'&&(int)($row['id']??0)>0)$teams[]=$row;
        }
    }catch(Throwable $e){}
}

function live_page_e_v2070(string $v): string{return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
vp3_public_header('Live Rooms — VP3','Join real-time conversations around sources and research.',['compact'=>true]);
?>
<style>
.vp3-live-shell{max-width:1080px;margin:0 auto;padding:32px 18px 72px}.vp3-live-hero{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(280px,.65fr);gap:20px;align-items:start}.vp3-live-card{background:#fff;border:1px solid var(--vp3-border,#e3e3de);border-radius:18px;padding:20px}.vp3-live-kicker{font-size:11px;font-weight:800;letter-spacing:.13em;text-transform:uppercase;color:#72726c}.vp3-live-title{font-size:38px;line-height:1.05;letter-spacing:-.045em;margin:8px 0 12px}.vp3-live-sub{max-width:700px;color:#6d6d66}.vp3-live-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:18px}.vp3-live-room{display:block;text-decoration:none;color:inherit;background:#fff;border:1px solid var(--vp3-border,#e3e3de);border-radius:16px;padding:17px}.vp3-live-room:hover{border-color:#b9b9b1}.vp3-live-room h3{margin:7px 0 5px;font-size:18px}.vp3-live-meta{display:flex;gap:7px;flex-wrap:wrap;margin-top:10px}.vp3-live-pill{display:inline-flex;border-radius:999px;background:#f1f1ed;padding:5px 8px;font-size:10px;font-weight:750;color:#5f5f59}.vp3-live-form{display:grid;gap:10px}.vp3-live-form label span{display:block;font-size:11px;font-weight:750;margin-bottom:5px}.vp3-live-form input,.vp3-live-form select{width:100%;padding:10px;border:1px solid #ddd;border-radius:10px}.vp3-live-check{display:flex;gap:8px;align-items:center;font-size:12px}.vp3-live-check input{width:auto}.vp3-live-empty{padding:36px;text-align:center;border:1px dashed #d2d2cc;border-radius:16px;color:#777;margin-top:18px}.vp3-live-error{margin-bottom:10px;padding:10px;border-radius:10px;background:#fff0f0;color:#8b2929}@media(max-width:760px){.vp3-live-hero{grid-template-columns:1fr}.vp3-live-grid{grid-template-columns:1fr}.vp3-live-title{font-size:31px}}
</style>
<main class="vp3-live-shell">
<section class="vp3-live-hero">
  <div class="vp3-live-card">
    <div class="vp3-live-kicker">VP3 Live</div>
    <h1 class="vp3-live-title">Live conversation around the work.</h1>
    <p class="vp3-live-sub">Start or join a Public or Team room around a source, annotation, or research project. Cloak Mode gives participants a room-specific pseudonym without changing server-side authorization.</p>
  </div>
  <div class="vp3-live-card">
    <div class="vp3-live-kicker">Start a room</div>
    <?php if($userId<1): ?>
      <p class="vp3-live-sub">Sign in to create or participate in Live Rooms. Public rooms remain readable without signing in.</p>
      <a class="vp3-btn primary" href="<?=live_page_e_v2070(url('/login.php'))?>">Sign in →</a>
    <?php else: ?>
      <?php if($error!==''): ?><div class="vp3-live-error"><?=live_page_e_v2070($error)?></div><?php endif; ?>
      <form class="vp3-live-form" method="post">
        <?=csrf_field()?>
        <label><span>Room title</span><input name="title" maxlength="180" placeholder="Live discussion"></label>
        <label><span>Access</span><select name="scope" id="liveScope"><option value="public">Public</option><option value="team">Team</option></select></label>
        <label id="liveTeamField" hidden><span>Team</span><select name="team_id"><option value="">Choose team…</option><?php foreach($teams as $team): ?><option value="<?=(int)$team['id']?>"><?=live_page_e_v2070((string)$team['name'])?></option><?php endforeach; ?></select></label>
        <label class="vp3-live-check"><input type="checkbox" name="allow_cloak" value="1" checked> Allow Cloak Mode</label>
        <label class="vp3-live-check"><input type="checkbox" name="cloak_mode" value="1"> Enter cloaked</label>
        <button class="vp3-btn primary" type="submit">Start Live Room →</button>
      </form>
      <script>document.getElementById('liveScope')?.addEventListener('change',e=>{document.getElementById('liveTeamField').hidden=e.target.value!=='team';});</script>
    <?php endif; ?>
  </div>
</section>

<section style="margin-top:28px">
  <div class="vp3-live-kicker">Active rooms</div>
  <?php if(!$rooms): ?><div class="vp3-live-empty">No Live Rooms are available to you yet.</div><?php else: ?>
  <div class="vp3-live-grid">
    <?php foreach($rooms as $room): ?>
      <a class="vp3-live-room" href="<?=live_page_e_v2070((string)$room['url'])?>">
        <div class="vp3-live-kicker"><?=live_page_e_v2070(ucfirst((string)$room['scope']))?> room</div>
        <h3><?=live_page_e_v2070((string)$room['title'])?></h3>
        <?php if(!empty($room['source']['domain'])): ?><div class="vp3-live-sub"><?=live_page_e_v2070((string)$room['source']['domain'])?></div><?php endif; ?>
        <div class="vp3-live-meta">
          <span class="vp3-live-pill"><?=(int)$room['participant_count']?> present</span>
          <?php if(!empty($room['allow_cloak'])): ?><span class="vp3-live-pill">Cloak available</span><?php endif; ?>
          <?php if(!empty($room['research_project']['title'])): ?><span class="vp3-live-pill">Research</span><?php endif; ?>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>
</main>
<?php vp3_public_footer(); ?>