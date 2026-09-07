<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_permission('account.access');

$pdo = db();
$user = current_user();
if (!$pdo || !$user) redirect(url('/login.php'));
if (!profile_agent_schema_ready($pdo)) redirect(url('/upgrade.php'));

$contacts = function_exists('profile_visitor_contact_list_v243')
    ? profile_visitor_contact_list_v243($pdo, (int)$user['id'], 250)
    : [];
$agentContacts = personal_capability_has_v242('profile_agent.access',$user) && vp3_radar_schema_ready($pdo) && function_exists('vp3_agent_crm_contacts')
    ? vp3_agent_crm_contacts($pdo,$user,250)
    : [];
$agentStats = function_exists('vp3_agent_crm_stats') ? vp3_agent_crm_stats($agentContacts) : ['total'=>count($agentContacts),'high_risk'=>0,'opportunities'=>0,'messaging'=>0,'watched'=>0,'referrals'=>0,'conversions'=>0];

$totalContacts = count($contacts);
$repeatContacts = 0;
$engagedContacts = 0;
$memberContacts = 0;
$activeContacts = 0;
$totalConversations = 0;
$now = time();
foreach ($contacts as $contact) {
    if (!empty($contact['repeat_visitor'])) $repeatContacts++;
    if ((int)($contact['conversation_count'] ?? 0) > 0) $engagedContacts++;
    if (!empty($contact['signed_in'])) $memberContacts++;
    $lastSeen = strtotime((string)($contact['last_seen_at'] ?? ''));
    if ($lastSeen !== false && $lastSeen >= $now - 300) $activeContacts++;
    $totalConversations += (int)($contact['conversation_count'] ?? 0);
}
$totalRelationships=$totalContacts+(int)$agentStats['total'];

function contacts_date_label(string $value): string
{
    if ($value === '') return '—';
    $ts = strtotime($value);
    if ($ts === false) return '—';
    $delta = time() - $ts;
    if ($delta < 60) return 'Just now';
    if ($delta < 3600) return max(1, (int)floor($delta / 60)) . 'm ago';
    if ($delta < 86400) return max(1, (int)floor($delta / 3600)) . 'h ago';
    if ($delta < 604800) return max(1, (int)floor($delta / 86400)) . 'd ago';
    return date('M j, Y', $ts);
}

function contacts_stage_label(string $stage): string
{
    return match ($stage) {
        'member_engaged' => 'Member engaged',
        'guest_engaged' => 'Guest engaged',
        'returning_visitor' => 'Returning',
        default => 'New visitor',
    };
}

function contacts_agent_class_label(string $value): string
{
    return match ($value) {
        'ai_user_agent'=>'AI Agent',
        'ai_search'=>'AI Search',
        'ai_crawler'=>'Crawler',
        'automated_unknown'=>'Unknown automation',
        default=>ucwords(str_replace('_',' ',trim($value))) ?: 'Automated agent',
    };
}

function contacts_agent_stage_label(string $value): string
{
    return match ($value) {
        'observed'=>'Observed','returning'=>'Returning','engaged'=>'Engaged','converted'=>'Converted','trusted'=>'Trusted','restricted'=>'Restricted',
        default=>'New',
    };
}

function contacts_agent_policy_label(array $contact): string
{
    $action=(string)($contact['gateway_action']??'profile_default');
    if($action==='profile_default')return 'Profile default';
    if($action==='limit')return 'Limit '.(int)($contact['gateway_limit_30m']??30).'/30m';
    return ucfirst($action);
}

function contacts_agent_messaging_label(string $status): string
{
    return match ($status) {
        'pending'=>'Messaging requested','approved_once'=>'Messaging · once','approved'=>'Messaging · allowed',
        default=>'Messaging · none',
    };
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#f6f7f8">
<title><?= e(system_agent_name()) ?> | My Contacts</title>
<link rel="stylesheet" href="<?= e(url('/chat.css?v=82')) ?>">
<link rel="stylesheet" href="<?= e(url('/contacts.css?v=agent-crm-watchlist-20260906')) ?>">
</head>
<body class="contacts-page">
<div class="chat-app">
  <?php
    $workspaceSidebarUser = $user;
    $workspaceSidebarActive = 'contacts';
    require __DIR__ . '/includes/workspace-sidebar-v82.php';
  ?>
  <div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div>

  <main class="chat-main contacts-main">
    <?php
      $memberHeaderUser = $user;
      $memberHeaderTitle = 'My Contacts';
      $memberHeaderSubtitle = 'People + AI agents + relationship history';
      $memberHeaderActions = '<a class="contacts-button" href="' . e(url('/chat.php')) . '">Ask Agent</a><a class="contacts-button" href="' . e(url('/profile-agent.php?tab=radar')) . '">Agent Radar</a>';
      require __DIR__ . '/includes/member-header.php';
    ?>

    <section class="contacts-canvas">
      <div class="contacts-inner">
        <section class="contacts-metrics" aria-label="Contact metrics">
          <article class="contacts-metric"><span>Total relationships</span><strong><?= $totalRelationships ?></strong><small>People + recurring automated agents</small></article>
          <article class="contacts-metric"><span>People</span><strong><?= $totalContacts ?></strong><small><?= $repeatContacts ?> returning · <?= $engagedContacts ?> engaged</small></article>
          <article class="contacts-metric"><span>AI / automated</span><strong><?= (int)$agentStats['total'] ?></strong><small><?= (int)$agentStats['watched'] ?> watched · Agent Radar contacts</small></article>
          <article class="contacts-metric<?= (int)$agentStats['high_risk']>0?' alert':'' ?>"><span>High risk agents</span><strong><?= (int)$agentStats['high_risk'] ?></strong><small>Risk score 70 or higher</small></article>
          <article class="contacts-metric"><span>Agent opportunities</span><strong><?= (int)$agentStats['opportunities'] ?></strong><small>High opportunity · low risk</small></article>
          <article class="contacts-metric"><span>AI conversions</span><strong><?= (int)$agentStats['conversions'] ?></strong><small><?= (int)$agentStats['referrals'] ?> explicitly attributed referrals</small></article>
        </section>

        <section class="contacts-toolbar" aria-label="Contact filters">
          <label class="contacts-search"><span aria-hidden="true">⌕</span><input id="contactsSearch" type="search" placeholder="Search people, agents, operators, stages, intent…" autocomplete="off"></label>
          <div class="contacts-filters" role="group" aria-label="Filter contacts">
            <button class="contacts-filter active" type="button" data-contact-filter="all">All</button>
            <button class="contacts-filter" type="button" data-contact-filter="human">People</button>
            <button class="contacts-filter" type="button" data-contact-filter="agent">Agents</button>
            <button class="contacts-filter" type="button" data-contact-filter="watched">Watched</button>
            <button class="contacts-filter" type="button" data-contact-filter="returning_visitor">Returning</button>
            <button class="contacts-filter" type="button" data-contact-filter="engaged">Engaged</button>
            <button class="contacts-filter" type="button" data-contact-filter="member">Members</button>
            <button class="contacts-filter" type="button" data-contact-filter="high_risk">High risk</button>
            <button class="contacts-filter" type="button" data-contact-filter="opportunity">Opportunities</button>
          </div>
        </section>
        <div class="contacts-action-notice" id="contactsActionNotice" role="status" aria-live="polite"></div>

        <section class="contacts-board" aria-label="Contacts">
          <div class="contacts-board-head" aria-hidden="true">
            <span>Contact</span><span>Type / stage</span><span>Activity</span><span>Relationship</span><span>Outcomes</span><span>First seen</span><span>Last activity</span>
          </div>
          <div id="contactsRows">
            <?php foreach ($contacts as $contact):
              $contactRef = trim((string)($contact['contact_ref'] ?? ''));
              $label = trim((string)($contact['visitor_label'] ?? ''));
              if ($label === '') $label = $contactRef !== '' ? 'Guest ' . substr($contactRef, 2) : 'Guest visitor';
              $stage = (string)($contact['stage'] ?? 'new_visitor');
              $relationship = trim((string)($contact['relationship_scope'] ?? 'none'));
              $searchText = strtolower(trim($label . ' ' . $contactRef . ' ' . $stage . ' ' . $relationship . ' human person'));
              $lastActivity = (string)($contact['conversation_last_at'] ?? '');
              $lastSeen = (string)($contact['last_seen_at'] ?? '');
              if ($lastActivity === '' || (strtotime($lastSeen) !== false && strtotime($lastActivity) < strtotime($lastSeen))) $lastActivity = $lastSeen;
            ?>
            <article class="contacts-row" data-contact-row data-kind="human" data-stage="<?= e($stage) ?>" data-member="<?= !empty($contact['signed_in']) ? '1' : '0' ?>" data-risk="0" data-opportunity="0" data-watch="0" data-search="<?= e($searchText) ?>">
              <div class="contacts-person">
                <span class="contacts-person-avatar">
                  <?php if (!empty($contact['avatar_url'])): ?><img src="<?= e((string)$contact['avatar_url']) ?>" alt=""><?php else: ?><?= e(mb_strtoupper(mb_substr($label,0,1))) ?><?php endif; ?>
                </span>
                <div class="contacts-person-copy">
                  <strong><?= e($label) ?></strong>
                  <small><?= e($contactRef !== '' ? $contactRef : (!empty($contact['signed_in']) ? 'Known member' : 'Guest')) ?><?php if ($relationship !== '' && $relationship !== 'none'): ?> · <?= e(str_replace('_',' ',$relationship)) ?><?php endif; ?></small>
                </div>
              </div>
              <div class="contacts-cell" data-label="Stage"><span class="contacts-stage <?= e($stage) ?>"><?= e(contacts_stage_label($stage)) ?></span><small>Human</small></div>
              <div class="contacts-cell" data-label="Visits"><strong><?= (int)($contact['visit_count'] ?? 0) ?> visits</strong><small><?= (int)($contact['page_view_count'] ?? 0) ?> profile views</small></div>
              <div class="contacts-cell" data-label="Chats"><strong><?= (int)($contact['conversation_count'] ?? 0) ?> chats</strong><small><?= (int)($contact['visitor_message_count'] ?? 0) ?> messages</small></div>
              <div class="contacts-cell" data-label="Messages"><strong><?= (int)($contact['visitor_message_count'] ?? 0) ?> messages</strong><small><?= !empty($contact['signed_in']) ? 'Known member' : (!empty($contact['repeat_visitor'])?'Returning guest':'Guest') ?></small></div>
              <div class="contacts-cell" data-label="First seen"><?= e(contacts_date_label((string)($contact['first_seen_at'] ?? ''))) ?></div>
              <div class="contacts-cell" data-label="Last activity"><?= e(contacts_date_label($lastActivity)) ?></div>
            </article>
            <?php endforeach; ?>

            <?php foreach ($agentContacts as $contact):
              $contactId=(int)$contact['id'];$risk=(int)$contact['risk_score'];$opp=(int)$contact['opportunity_score'];$value=(int)$contact['value_score'];$cost=(int)$contact['cost_score'];$trust=(int)$contact['trust_score'];$engagement=(int)$contact['engagement_score'];$watched=!empty($contact['watch_enabled']);
              $name=trim((string)$contact['display_name'])?:'Automated agent';$operator=trim((string)$contact['operator_name']);$class=(string)$contact['visitor_class'];$stage=(string)$contact['relationship_status'];$intent=trim((string)$contact['inferred_intent']);$recommendation=trim((string)$contact['recommendation']);
              $searchText=strtolower(trim($name.' '.$operator.' '.$class.' '.$stage.' '.$intent.' agent automated '.(string)$contact['verification_status'].($watched?' watched watchlist':'')));
              $messagingStatus=(string)$contact['messaging_access_status'];$requestId=(int)$contact['messaging_request_id'];
            ?>
            <article id="agent-contact-<?= $contactId ?>" class="contacts-row contacts-agent-row<?= $risk>=70?' high-risk':'' ?><?= $opp>=80&&$risk<40?' opportunity':'' ?><?= $watched?' watched':'' ?>" data-contact-row data-kind="agent" data-stage="<?= e($stage) ?>" data-member="0" data-risk="<?= $risk ?>" data-opportunity="<?= $opp ?>" data-watch="<?= $watched?'1':'0' ?>" data-search="<?= e($searchText) ?>" data-agent-contact-id="<?= $contactId ?>">
              <div class="contacts-person">
                <span class="contacts-person-avatar agent"><?= e(mb_strtoupper(mb_substr($name,0,1))) ?></span>
                <div class="contacts-person-copy">
                  <strong><?= e($name) ?><?php if($watched): ?> <span class="contacts-watch-badge">Watched</span><?php endif; ?></strong>
                  <small><?= e($operator!==''?$operator:'Unidentified operator') ?> · <?= e(contacts_agent_class_label($class)) ?></small>
                </div>
              </div>
              <div class="contacts-cell" data-label="Type / stage"><span class="contacts-stage agent <?= e($stage) ?>"><?= e(contacts_agent_stage_label($stage)) ?></span><small><?= e((string)$contact['verification_status']) ?> · <?= (int)$contact['confidence_score'] ?>%</small></div>
              <div class="contacts-cell" data-label="Activity"><strong><?= (int)$contact['session_count'] ?> sessions</strong><small><?= (int)$contact['page_view_count'] ?> views · <?= (int)$contact['request_count'] ?> requests</small></div>
              <div class="contacts-cell" data-label="Relationship"><strong>Value <?= $value ?> · Opp. <?= $opp ?></strong><small>Trust <?= $trust ?> · Risk <?= $risk ?> · Cost <?= $cost ?> · Engage <?= $engagement ?></small></div>
              <div class="contacts-cell" data-label="Outcomes"><strong><?= (int)$contact['conversion_count'] ?> conversions</strong><small><?= (int)$contact['referral_count'] ?> referrals · <?= e(contacts_agent_messaging_label($messagingStatus)) ?></small></div>
              <div class="contacts-cell" data-label="First seen"><?= e(contacts_date_label((string)$contact['first_seen_at'])) ?></div>
              <div class="contacts-cell" data-label="Last activity"><?= e(contacts_date_label((string)$contact['last_seen_at'])) ?></div>
              <details class="contacts-agent-manage">
                <summary><span>Manage contact</span><b><?= e(contacts_agent_policy_label($contact)) ?></b></summary>
                <div class="contacts-agent-manage-body">
                  <div class="contacts-agent-intelligence">
                    <div><span>Inferred intent</span><strong><?= e($intent!==''?str_replace('_',' ',$intent):'Not enough evidence') ?></strong><small><?= $intent!==''?(int)$contact['intent_confidence'].'% confidence':'Keep monitoring this relationship.' ?></small></div>
                    <div><span>Recommended next step</span><strong><?= e($recommendation!==''?$recommendation:'Continue monitoring this recurring agent contact.') ?></strong></div>
                  </div>
                  <div class="contacts-agent-actions attention" aria-label="Agent CRM attention controls">
                    <span>Attention</span>
                    <button type="button" data-agent-watch data-contact-id="<?= $contactId ?>" data-watch-enabled="<?= $watched?'1':'0' ?>"><?= $watched?'Stop watching':'Watch this agent' ?></button>
                    <small>Watched contacts surface their next new session in your notification/Main Feed flow.</small>
                  </div>
                  <div class="contacts-agent-actions" aria-label="Agent Gateway contact controls">
                    <span>Website access</span>
                    <button type="button" data-agent-policy="allow" data-contact-id="<?= $contactId ?>">Allow</button>
                    <button type="button" data-agent-policy="monitor" data-contact-id="<?= $contactId ?>">Monitor</button>
                    <label class="contacts-agent-limit"><button type="button" data-agent-policy="limit" data-contact-id="<?= $contactId ?>">Limit</button><input type="number" min="1" max="10000" value="<?= (int)($contact['gateway_limit_30m']??30) ?>" data-agent-limit="<?= $contactId ?>" aria-label="Requests per 30 minutes"><small>/ 30m</small></label>
                    <button type="button" data-agent-policy="block" data-contact-id="<?= $contactId ?>" class="danger">Block</button>
                  </div>
                  <?php if($requestId>0): ?>
                  <div class="contacts-agent-actions messaging" aria-label="Agent Messaging controls">
                    <span><?= e(contacts_agent_messaging_label($messagingStatus)) ?><?php if(trim((string)$contact['messaging_purpose'])!==''): ?> · <?= e((string)$contact['messaging_purpose']) ?><?php endif; ?></span>
                    <?php if($messagingStatus==='pending'): ?><button type="button" data-agent-message-decision="allow_once" data-request-id="<?= $requestId ?>">Allow once</button><button type="button" data-agent-message-decision="allow" data-request-id="<?= $requestId ?>">Always allow</button><?php endif; ?>
                    <button type="button" data-agent-message-decision="deny" data-request-id="<?= $requestId ?>" class="danger"><?= $messagingStatus==='pending'?'Deny':'Revoke' ?></button>
                  </div>
                  <?php endif; ?>
                  <?php if(!empty($contact['recent_activity'])): ?><div class="contacts-agent-recent"><span>Recent activity</span><?php foreach(array_slice($contact['recent_activity'],0,4) as $activity): ?><div><strong><?= e(str_replace('_',' ',(string)$activity['event_type'])) ?></strong><code><?= e((string)$activity['path']) ?></code><small><?= e(contacts_date_label((string)$activity['occurred_at'])) ?><?= !empty($activity['property_label'])?' · '.e((string)$activity['property_label']):'' ?></small></div><?php endforeach; ?></div><?php endif; ?>
                </div>
              </details>
            </article>
            <?php endforeach; ?>
          </div>
          <div class="contacts-empty<?= $totalRelationships ? ' contacts-hidden' : '' ?>" id="contactsEmpty">
            <strong><?= $totalRelationships ? 'No contacts match this filter.' : 'No contacts yet.' ?></strong>
            <span><?= $totalRelationships ? 'Try a different search or relationship filter.' : 'Profile visits, Profile Agent conversations and recurring automated visitors will build this list automatically.' ?></span>
          </div>
        </section>

        <section class="contacts-privacy">
          <span aria-hidden="true">◉</span>
          <div><strong>Privacy-first guest continuity</strong><p><b>One CRM, separate privacy boundaries.</b> Human contacts use the existing privacy-preserving visitor relationship system. Automated visitors use Agent Radar identities. VP3 does not merge an AI agent into a human contact, and explicit AI referral attribution uses first-party token hashes rather than IP addresses or cross-customer tracking.</p></div>
        </section>
      </div>
    </section>
  </main>
</div>
<script>window.VP3_CONTACTS=<?= json_encode(['policyEndpoint'=>url('/api/agent-radar-policy.php'),'csrf'=>csrf_token()],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;</script>
<script>
(() => {
  'use strict';
  const rows=[...document.querySelectorAll('[data-contact-row]')];
  const search=document.getElementById('contactsSearch');
  const filters=[...document.querySelectorAll('[data-contact-filter]')];
  const empty=document.getElementById('contactsEmpty');
  const notice=document.getElementById('contactsActionNotice');
  const cfg=window.VP3_CONTACTS||{};
  let active='all',busy=false;
  function apply(){
    const q=String(search?.value||'').trim().toLowerCase();
    let shown=0;
    for(const row of rows){
      const kind=String(row.dataset.kind||'human'),stage=String(row.dataset.stage||''),member=row.dataset.member==='1',risk=Number(row.dataset.risk||0),opp=Number(row.dataset.opportunity||0),watched=row.dataset.watch==='1';
      const matchesFilter=active==='all'||active===kind||(active==='watched'&&kind==='agent'&&watched)||(active==='returning_visitor'&&(stage==='returning_visitor'||stage==='returning'))||(active==='engaged'&&['guest_engaged','member_engaged','engaged','converted','trusted'].includes(stage))||(active==='member'&&kind==='human'&&member)||(active==='high_risk'&&kind==='agent'&&risk>=70)||(active==='opportunity'&&kind==='agent'&&opp>=80&&risk<40);
      const matchesSearch=!q||String(row.dataset.search||'').includes(q);
      row.classList.toggle('contacts-hidden',!(matchesFilter&&matchesSearch));
      if(matchesFilter&&matchesSearch)shown++;
    }
    empty?.classList.toggle('contacts-hidden',shown>0);
  }
  function setNotice(message='',error=false){if(!notice)return;notice.textContent=message;notice.className=`contacts-action-notice${error?' error':''}${message?' show':''}`;}
  async function policy(payload){
    if(!cfg.policyEndpoint||!cfg.csrf)throw new Error('Agent controls are unavailable.');
    const r=await fetch(cfg.policyEndpoint,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf_token:cfg.csrf,...payload})});
    const d=await r.json().catch(()=>null);if(!r.ok||!d?.ok)throw new Error(d?.error||'Agent contact update failed.');return d;
  }
  search?.addEventListener('input',apply);
  for(const filter of filters)filter.addEventListener('click',()=>{active=String(filter.dataset.contactFilter||'all');for(const button of filters)button.classList.toggle('active',button===filter);apply();});
  document.addEventListener('click',async e=>{
    const gateway=e.target.closest('[data-agent-policy]');const messaging=e.target.closest('[data-agent-message-decision]');const watch=e.target.closest('[data-agent-watch]');if((!gateway&&!messaging&&!watch)||busy)return;
    busy=true;const button=gateway||messaging||watch;button.disabled=true;
    try{
      if(watch){const enabled=watch.dataset.watchEnabled!=='1';await policy({action:'set_contact_watch',contact_id:Number(watch.dataset.contactId||0),watch_enabled:enabled});setNotice(enabled?'Agent added to your watchlist. Its next new session will be surfaced.':'Agent removed from your watchlist.');}
      else if(gateway){const contactId=Number(gateway.dataset.contactId||0),action=String(gateway.dataset.agentPolicy||'monitor'),limit=Math.max(1,Number(document.querySelector(`[data-agent-limit="${contactId}"]`)?.value||30));await policy({action:'set_contact_policy',contact_id:contactId,policy_action:action,limit_30m:limit});setNotice(`Agent Gateway contact policy updated to ${action}${action==='limit'?` ${limit}/30m`:''}.`);}
      else{const decision=String(messaging.dataset.agentMessageDecision||'deny');await policy({action:'access_request_decision',request_id:Number(messaging.dataset.requestId||0),decision});setNotice(decision==='allow_once'?'Agent Messaging allowed once for 30 minutes.':decision==='allow'?'Agent Messaging is now always allowed for this contact.':'Agent Messaging access denied or revoked.');}
      window.setTimeout(()=>window.location.reload(),350);
    }catch(err){setNotice(err.message,true);button.disabled=false;}finally{busy=false;}
  });
  apply();
})();
</script>
<script src="<?= e(url('/member-shell-v77.js?v=universal-member-header-20260905')) ?>"></script>
</body>
</html>