import fs from 'node:fs';

const read=path=>fs.readFileSync(path,'utf8');
const must=(value,message)=>{if(!value)throw new Error(message);};
const mustNot=(value,message)=>{if(value)throw new Error(message);};

const manifest=JSON.parse(read('browser-companion/manifest.json'));
const background=read('browser-companion/background.js');
const panel=read('browser-companion/sidepanel.html');
const panelJs=read('browser-companion/sidepanel.js');
const service=read('includes/live-rooms-v2070.php');
const api=read('api/live-rooms-v2070.php');
const bootstrap=read('includes/bootstrap.php');
const upgrade=read('upgrade.php');
const livePage=read('live.php');
const roomPage=read('live-room.php');
const roomJs=read('live-room-v2070.js');
const sourcePage=read('source.php');

must(manifest.manifest_version===3,'Phase 8 must remain Manifest V3');
must(manifest.version==='20.70.0','Phase 8 Browser Companion version must be v20.70.0');

for(const table of ['live_rooms_v2070','live_room_members_v2070','live_room_messages_v2070']){
  must(service.includes(table),'Phase 8 schema missing '+table);
}
must(service.includes("require_once __DIR__.'/browser-source-feed-v2050.php'"),'Live Rooms must reuse canonical Source Feed');
must(service.includes("require_once __DIR__.'/research-projects-v2060.php'"),'Live Rooms must integrate with canonical Research');
must(service.includes("['public','team']"),'Live Room scope must be Public or Team');
must(service.includes('vp3_human_team_authorized_v370'),'Team Live Rooms must use live Team authorization');
must(service.includes('VP3_LIVE_ROOM_PRESENCE_SECONDS_V2070=45'),'presence expiry boundary missing');

const identityStart=service.indexOf('function vp3_live_room_member_identity_v2070');
const identityEnd=service.indexOf('function vp3_live_room_participants_v2070',identityStart);
const identityFn=service.slice(identityStart,identityEnd);
must(identityFn.includes("'id'=>(string)($cloaked?($member['cloak_public_id']"),'Cloak identity must use a separate room pseudonym ID');
must(identityFn.includes("'name'=>$cloaked?(string)($member['cloak_alias']"),'Cloak identity must use pseudonym label');
mustNot(identityFn.includes("'user_id'=>"),'participant identity payload must not expose VP3 user IDs');

must(service.includes('cloak_public_id CHAR(36) NOT NULL'),'Cloak identity needs a separate durable room token');
must(service.includes('sender_cloaked TINYINT(1) NOT NULL'),'messages must snapshot sender Cloak state');
must(service.includes('sender_public_id CHAR(36) NOT NULL'),'messages must snapshot sender public identity');
must(service.includes('sender_label VARCHAR(190) NOT NULL'),'messages must snapshot sender label');
must(service.includes("$senderPublic=$cloaked?(string)$member['cloak_public_id']:(string)$member['public_id']"),'message identity snapshot must choose cloaked token at send time');
must(service.includes("$senderLabel=$cloaked?(string)$member['cloak_alias']:(string)$member['display_name']"),'message label snapshot must choose pseudonym at send time');
must(service.includes("'id'=>(string)$row['sender_public_id']"),'message serialization must use immutable sender snapshot');
must(service.includes("'name'=>(string)$row['sender_label']"),'message serialization must not re-resolve later member identity');

must(service.includes('function vp3_live_room_share_compatible_v2070'),'annotation-to-Live compatibility gate missing');
must(service.includes("if((string)$room['room_scope']==='public')return $visibility==='public';"),'Public rooms must accept only already-Public annotations');
must(service.includes("$visibility==='team'&&(int)($share['team_owner_user_id']??0)===(int)($room['team_owner_user_id']??0)"),'Team rooms must require same-Team annotation visibility');
mustNot(service.includes('vp3_browser_source_publish_v2050('),'Live Rooms must never republish annotations');
must(service.includes("throw new RuntimeException('That annotation is not visible to everyone who can enter this Live Room.')"),'incompatible annotation shares must fail closed');
must(service.includes("Leave Cloak Mode before attaching an annotation."),'Cloak Mode must reject annotation attachments that can deanonymize the sender');
must(service.includes("$scope=(string)($room['room_scope']??'');\n    if($scope==='public')"),'room access must evaluate scope before privileges');
mustNot(service.includes("if((int)($room['owner_user_id']??0)===$userId && $userId>0)return true;"),'Team-room creators must not bypass live Team authorization');
must(service.includes("vp3_live_room_require_v2070($pdo,$roomPublicId,$userId,true);"),'heartbeats must fail closed after a room ends');
must(service.includes("WHERE room_id=? AND user_id=? AND left_at IS NULL"),'heartbeat must not undo an explicit Live Room leave');
must(service.includes("Join the Live Room before refreshing presence."),'heartbeat must require explicit rejoin after leave');
must(service.includes("vp3_live_room_presence_count_v2070"),'lightweight room cards must still return accurate presence counts');
must(service.includes("$scope==='public'?$sourceTitleInput:''"),'Team Live Rooms must not promote personalized page titles into canonical Source metadata');
must(service.includes("'title'=>''"),'Live Room payloads must not expose mutable canonical Source titles');

must(api.includes("action==='source_rooms'"),'current-source Live discovery API missing');
must(api.includes("action==='poll'"),'Live message polling API missing');
must(api.includes("action==='heartbeat'"),'presence heartbeat API missing');
must(api.includes("action==='cloak'"),'Cloak Mode API missing');
must(api.includes("action==='send'"),'Live message send API missing');
must(api.includes("action==='end'"),'Live Room end API missing');
must(api.includes('vp3_extension_session_authenticate_v2001'),'Live extension API must use bearer authentication');
must(api.includes("vp3_live_room_poll_v2070($pdo,$userId,trim((string)($_GET['room']??'')),max(0,(int)($_GET['after']??0)),false)"),'GET polling must not silently refresh presence');
mustNot(api.includes('ensure_schema_v2070('),'public Live API must never execute schema DDL');

must(background.includes("const VP3_EXTENSION_VERSION = '20.70.0'"),'extension runtime version missing');
must(background.includes("'/api/live-rooms-v2070.php'"),'Browser Companion Live transport missing');
must(background.includes("case 'live_rooms'"),'Browser Companion source-room transport missing');
must(background.includes("case 'live_poll'"),'Browser Companion Live poll transport missing');
must(background.includes("case 'live_action'"),'Browser Companion Live action transport missing');

for(const id of ['liveTab','liveView','liveRoomTitle','liveScope','liveTeamSelect','liveAllowCloak','liveEnterCloaked','startLiveRoomBtn','liveRoomsList','liveRoomPanel','liveCloakBtn','liveParticipants','liveMessages','liveMessageInput']){
  must(panel.includes('id="'+id+'"'),'Browser Companion Live UI missing '+id);
}
must(panelJs.includes("setView('live')"),'Live tab navigation missing');
must(panelJs.includes("liveAction('create'"),'Start Live Room flow missing');
must(panelJs.includes("liveAction('join'"),'Join Live Room flow missing');
must(panelJs.includes("liveAction('cloak'"),'Cloak toggle missing');
must(panelJs.includes("liveAction('heartbeat'"),'sidebar presence heartbeat missing');
must(panelJs.includes("msg('live_poll'"),'sidebar Live polling missing');
must(panelJs.includes("browser_share_id:id"),'annotation-to-Live action missing');
must(panelJs.includes("Join or start a Live Room on this source first."),'annotation Live action must require an active joined room');

must(livePage.includes('vp3_live_room_list_v2070'),'Live directory must use canonical Live service');
must(livePage.includes('vp3_live_room_create_v2070'),'website room creation must use canonical Live service');
must(roomPage.includes('vp3_live_room_require_v2070'),'website room view must use canonical Live authorization');
must(roomPage.includes('data-config='),'Live Room bootstrap config must be CSP-safe');
mustNot(livePage.includes('<script>'),'Live directory must not require inline JavaScript');
must(roomJs.includes("setInterval(poll,2000)"),'website Live client polling interval missing');
must(roomJs.includes("setInterval(heartbeat,15000)"),'website presence heartbeat interval missing');
must(roomPage.includes('VP3 still retains your account identity server-side'),'Cloak limitation disclosure missing');

must(sourcePage.includes('vp3_live_room_rooms_for_source_v2070'),'canonical Source pages must surface authorized Live Rooms');
must(sourcePage.includes("empty($publishedResearch) && empty($liveRooms)"),'public Source existence must include authorized Live Rooms without leaking private follows');
must(sourcePage.includes("$pageTitle=(string)$source['source_domain'];"),'Source pages must not use mutable global Source titles before viewer-authorized evidence resolves');

must(bootstrap.includes("live-rooms-v2070.php"),'canonical bootstrap must load Live Rooms');
must(upgrade.includes("vp3_live_room_schema_ready_v2070()"),'upgrade readiness must include Live Rooms');
must(upgrade.includes("vp3_live_room_ensure_schema_v2070();"),'upgrade must install Live Rooms');

console.log('VP3 Phase 8 Live Rooms + Cloak Mode v20.70 contract passed.');
