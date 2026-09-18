<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';

$pdo=vp3_live_room_require_ready_v2070(db());
$user=current_user();
$userId=(int)($user['id']??0);
$publicId=trim((string)($_GET['room']??''));
try{
    $roomRow=vp3_live_room_require_v2070($pdo,$publicId,$userId,false);
    $room=vp3_live_room_public_v2070($pdo,$roomRow,$userId,true);
    $initial=vp3_live_room_messages_v2070($pdo,$roomRow,$userId,0,100);
}catch(Throwable $e){
    http_response_code(404);$roomRow=null;$room=null;$initial=['items'=>[],'cursor'=>0];
}
function live_room_e_v2070(string $v): string{return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
vp3_public_header($room?((string)$room['title'].' — VP3 Live'):'Live Room not found','Real-time VP3 Live Room.',['compact'=>true]);
?>
<style>
.live-room-shell{max-width:1040px;margin:0 auto;padding:24px 18px 70px}.live-room-head{background:#fff;border:1px solid #e2e2dc;border-radius:18px;padding:20px}.live-room-headline{display:flex;align-items:flex-start;justify-content:space-between;gap:14px}.live-room-head h1{margin:5px 0;font-size:28px;letter-spacing:-.035em}.live-room-kicker{font-size:10px;font-weight:800;letter-spacing:.12em;text-transform:uppercase;color:#73736d}.live-room-muted{color:#72726b;font-size:12px}.live-room-pills{display:flex;gap:6px;flex-wrap:wrap;margin-top:12px}.live-room-pill{padding:5px 8px;border-radius:999px;background:#f0f0eb;font-size:10px;font-weight:750}.live-room-pill.cloak{background:#ece8ff;color:#51447a}.live-room-layout{display:grid;grid-template-columns:minmax(0,1fr) 250px;gap:12px;margin-top:12px}.live-room-main,.live-room-side{background:#fff;border:1px solid #e2e2dc;border-radius:18px}.live-room-main{padding:14px}.live-room-side{padding:14px;height:max-content}.live-room-messages{display:grid;gap:8px;min-height:260px;max-height:62vh;overflow:auto;padding:4px}.live-msg{padding:10px 11px;border-radius:12px;background:#f7f7f4}.live-msg.self{background:#edf4ff}.live-msg-head{display:flex;gap:7px;align-items:center;font-size:10px;color:#74746d}.live-msg-name{font-weight:800;color:#333}.live-msg-body{margin-top:4px;white-space:pre-wrap;overflow-wrap:anywhere}.live-msg-annotation{margin-top:8px;padding:8px;border-left:3px solid #ccc;background:#fff;border-radius:0 8px 8px 0;font-size:11px}.live-room-compose{display:grid;grid-template-columns:1fr auto;gap:8px;margin-top:10px}.live-room-compose input{padding:10px;border:1px solid #ddd;border-radius:10px}.live-room-participants{display:grid;gap:7px;margin-top:8px}.live-person{display:flex;justify-content:space-between;gap:8px;padding:8px;border-radius:10px;background:#f7f7f4;font-size:11px}.live-room-actions{display:flex;gap:7px;flex-wrap:wrap;margin-top:12px}.live-room-actions button{padding:7px 9px;border:1px solid #ddd;border-radius:9px;background:#fff;cursor:pointer}.live-room-actions button.primary{background:#171717;color:#fff;border-color:#171717}.cloak-note{margin-top:10px;padding:9px;border-radius:10px;background:#f7f5ff;color:#625b7c;font-size:11px}.live-room-empty{padding:45px;text-align:center;color:#777}@media(max-width:760px){.live-room-layout{grid-template-columns:1fr}.live-room-side{order:-1}.live-room-messages{max-height:55vh}}
</style>
<main class="live-room-shell">
<?php if(!$room): ?><div class="live-room-empty">This Live Room is not available.</div>
<?php else: ?>
<section class="live-room-head">
  <div class="live-room-headline">
    <div><div class="live-room-kicker"><?=live_room_e_v2070(ucfirst((string)$room['scope']))?> Live Room</div><h1><?=live_room_e_v2070((string)$room['title'])?></h1>
      <?php if(!empty($room['source']['canonical_url'])): ?><a class="live-room-muted" target="_blank" rel="noopener noreferrer" href="<?=live_room_e_v2070((string)$room['source']['canonical_url'])?>"><?=live_room_e_v2070((string)($room['source']['title']?:$room['source']['domain']))?></a><?php endif; ?>
    </div>
    <a href="<?=live_room_e_v2070(url('/live.php'))?>">All Live Rooms</a>
  </div>
  <div class="live-room-pills"><span class="live-room-pill" id="roomStatus"><?=live_room_e_v2070(ucfirst((string)$room['status']))?></span><span class="live-room-pill" id="presenceCount"><?=(int)$room['participant_count']?> present</span><?php if($room['allow_cloak']): ?><span class="live-room-pill cloak">Cloak available</span><?php endif; ?></div>
</section>
<div class="live-room-layout">
  <section class="live-room-main">
    <div id="liveMessages" class="live-room-messages"></div>
    <?php if($userId>0): ?><form id="liveCompose" class="live-room-compose"><input id="liveMessageInput" maxlength="4000" autocomplete="off" placeholder="Message the room…"><button class="primary" type="submit">Send</button></form><?php else: ?><div class="cloak-note">Sign in to join the conversation. Public rooms remain readable without an account session.</div><?php endif; ?>
  </section>
  <aside class="live-room-side">
    <div class="live-room-kicker">Participants</div><div id="liveParticipants" class="live-room-participants"></div>
    <?php if($userId>0): ?>
    <div class="live-room-actions">
      <button id="joinRoomBtn" class="primary" type="button" <?=$room['joined']?'hidden':''?>>Join room</button>
      <button id="cloakRoomBtn" type="button" <?=!$room['joined']||!$room['allow_cloak']?'hidden':''?>><?=$room['cloak_mode']?'Leave Cloak':'Cloak Mode'?></button>
      <button id="leaveRoomBtn" type="button" <?=$room['joined']?'':'hidden'?>>Leave</button>
      <?php if($room['is_owner']): ?><button id="endRoomBtn" type="button">End room</button><?php endif; ?>
    </div>
    <?php if($room['allow_cloak']): ?><div class="cloak-note">Cloak Mode shows a room-specific pseudonym to participants. VP3 still retains your account identity server-side for access control and abuse handling. Leave Cloak before attaching identity-bearing evidence.</div><?php endif; ?>
    <?php endif; ?>
  </aside>
</div>
<?php $liveConfig=json_encode([
    'room'=>$room,
    'messages'=>$initial['items'],
    'cursor'=>$initial['cursor'],
    'api'=>url('/api/live-rooms-v2070.php'),
    'csrf'=>$userId>0?csrf_token():'',
    'signed_in'=>$userId>0,
],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?:'{}'; ?>
<div id="liveRoomConfig" hidden data-config="<?=live_room_e_v2070($liveConfig)?>"></div>
<script src="<?=live_room_e_v2070(url('/live-room-v2070.js'))?>"></script>
<?php endif; ?>
</main>
<?php vp3_public_footer(); ?>