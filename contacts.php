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

function contacts_agent_client_record(array $contact): array
{
    $name=trim((string)($contact['display_name']??''))?:'Automated agent';
    $class=(string)($contact['visitor_class']??'automated_unknown');
    $stage=(string)($contact['relationship_status']??'observed');
    $analytics=is_array($contact['analytics']??null)?$contact['analytics']:[];
    $recent=[];
    foreach(array_slice((array)($contact['recent_activity']??[]),0,8) as $event){
        if(!is_array($event))continue;
        $recent[]=[
            'id'=>(int)($event['id']??0),'event_type'=>(string)($event['event_type']??''),'severity'=>(string)($event['severity']??''),
            'path'=>(string)($event['path']??''),'summary'=>(string)($event['summary']??''),'occurred_at'=>(string)($event['occurred_at']??''),
            'property_label'=>(string)($event['property_label']??''),
        ];
    }
    return [
        'id'=>(int)($contact['id']??0),'name'=>$name,'operator'=>trim((string)($contact['operator_name']??'')),
        'class'=>$class,'class_label'=>contacts_agent_class_label($class),'stage'=>$stage,'stage_label'=>contacts_agent_stage_label($stage),
        'verification_status'=>(string)($contact['verification_status']??'unverified'),'confidence_score'=>(int)($contact['confidence_score']??0),
        'first_seen_at'=>(string)($contact['first_seen_at']??''),'last_seen_at'=>(string)($contact['last_seen_at']??''),
        'session_count'=>(int)($contact['session_count']??0),'page_view_count'=>(int)($contact['page_view_count']??0),'request_count'=>(int)($contact['request_count']??0),
        'conversion_count'=>(int)($contact['conversion_count']??0),'referral_count'=>(int)($contact['referral_count']??0),
        'value_score'=>(int)($contact['value_score']??0),'opportunity_score'=>(int)($contact['opportunity_score']??0),'trust_score'=>(int)($contact['trust_score']??0),
        'risk_score'=>(int)($contact['risk_score']??0),'cost_score'=>(int)($contact['cost_score']??0),'engagement_score'=>(int)($contact['engagement_score']??0),
        'intent'=>trim((string)($contact['inferred_intent']??'')),'intent_confidence'=>(int)($contact['intent_confidence']??0),
        'recommendation'=>trim((string)($contact['recommendation']??'')),'registry_purpose'=>trim((string)($contact['registry_purpose']??'')),
        'registry_slug'=>(string)($contact['registry_slug']??''),'verification_method'=>(string)($contact['verification_method']??''),
        'watch_enabled'=>!empty($contact['watch_enabled']),'gateway_action'=>(string)($contact['gateway_action']??'profile_default'),
        'gateway_limit_30m'=>(int)($contact['gateway_limit_30m']??30),'policy_label'=>contacts_agent_policy_label($contact),
        'messaging_status'=>(string)($contact['messaging_access_status']??'none'),'messaging_label'=>contacts_agent_messaging_label((string)($contact['messaging_access_status']??'none')),
        'messaging_request_id'=>(int)($contact['messaging_request_id']??0),'messaging_purpose'=>(string)($contact['messaging_purpose']??''),
        'analytics'=>$analytics,'recent_activity'=>$recent,
    ];
}

$agentClientContacts=[];
foreach($agentContacts as $agentContact){
    $record=contacts_agent_client_record($agentContact);
    if((int)$record['id']>0)$agentClientContacts[(string)$record['id']]=$record;
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
<link rel="stylesheet" href="<?= e(url('/contacts.css?v=agent-crm-detail-v312-20260907')) ?>">
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
              $name=trim((string)$contact['display_name'])?:'Automated agent';$operator=trim((string)$contact['operator_name']);$class=(string)$contact['visitor_class'];$stage=(string)$contact['relationship_status'];$intent=trim((string)$contact['inferred_intent']);
              $searchText=strtolower(trim($name.' '.$operator.' '.$class.' '.$stage.' '.$intent.' agent automated '.(string)$contact['verification_status'].($watched?' watched watchlist':'')));
              $messagingStatus=(string)$contact['messaging_access_status'];
            ?>
            <article id="agent-contact-<?= $contactId ?>" class="contacts-row contacts-agent-row<?= $risk>=70?' high-risk':'' ?><?= $opp>=80&&$risk<40?' opportunity':'' ?><?= $watched?' watched':'' ?>" data-contact-row data-kind="agent" data-stage="<?= e($stage) ?>" data-member="0" data-risk="<?= $risk ?>" data-opportunity="<?= $opp ?>" data-watch="<?= $watched?'1':'0' ?>" data-search="<?= e($searchText) ?>" data-agent-contact-id="<?= $contactId ?>">
              <div class="contacts-person">
                <span class="contacts-person-avatar agent"><?= e(mb_strtoupper(mb_substr($name,0,1))) ?></span>
                <div class="contacts-person-copy">
                  <strong><?= e($name) ?><?php if($watched): ?> <span class="contacts-watch-badge">Watched</span><?php endif; ?></strong>
                  <small><?= e($operator!==''?$operator:'Unidentified operator') ?> · <?= e(contacts_agent_class_label($class)) ?></small>
                  <button class="contacts-agent-open" type="button" data-agent-detail-open="<?= $contactId ?>">Open relationship</button>
                </div>
              </div>
              <div class="contacts-cell" data-label="Type / stage"><span class="contacts-stage agent <?= e($stage) ?>"><?= e(contacts_agent_stage_label($stage)) ?></span><small><?= e((string)$contact['verification_status']) ?> · <?= (int)$contact['confidence_score'] ?>%</small></div>
              <div class="contacts-cell" data-label="Activity"><strong><?= (int)$contact['session_count'] ?> sessions</strong><small><?= (int)$contact['page_view_count'] ?> views · <?= (int)$contact['request_count'] ?> requests</small></div>
              <div class="contacts-cell" data-label="Relationship"><strong>Value <?= $value ?> · Opp. <?= $opp ?></strong><small>Trust <?= $trust ?> · Risk <?= $risk ?> · Cost <?= $cost ?> · Engage <?= $engagement ?></small></div>
              <div class="contacts-cell" data-label="Outcomes"><strong><?= (int)$contact['conversion_count'] ?> conversions</strong><small><?= (int)$contact['referral_count'] ?> referrals · <?= e(contacts_agent_messaging_label($messagingStatus)) ?></small></div>
              <div class="contacts-cell" data-label="First seen"><?= e(contacts_date_label((string)$contact['first_seen_at'])) ?></div>
              <div class="contacts-cell" data-label="Last activity"><?= e(contacts_date_label((string)$contact['last_seen_at'])) ?></div>
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

<div class="contacts-agent-modal" id="agentRelationshipModal" hidden aria-hidden="true">
  <div class="contacts-agent-modal-backdrop" data-agent-detail-close></div>
  <section class="contacts-agent-modal-panel" role="dialog" aria-modal="true" aria-labelledby="agentRelationshipTitle" tabindex="-1">
    <header class="contacts-agent-modal-head">
      <div class="contacts-agent-modal-identity">
        <span class="contacts-person-avatar agent" id="agentRelationshipAvatar">A</span>
        <div><small id="agentRelationshipKicker">Agent CRM relationship</small><h2 id="agentRelationshipTitle">Automated agent</h2><p id="agentRelationshipMeta"></p></div>
      </div>
      <button class="contacts-agent-modal-close" type="button" data-agent-detail-close aria-label="Close relationship detail">×</button>
    </header>
    <nav class="contacts-agent-tabs" id="agentRelationshipTabs" aria-label="Agent relationship detail tabs">
      <button type="button" data-agent-detail-tab="overview" class="active">Overview</button>
      <button type="button" data-agent-detail-tab="analytics">Analytics</button>
      <button type="button" data-agent-detail-tab="activity">Activity</button>
      <button type="button" data-agent-detail-tab="permissions">Permissions</button>
      <button type="button" data-agent-detail-tab="intelligence">Intelligence</button>
    </nav>
    <div class="contacts-agent-modal-body" id="agentRelationshipBody"></div>
  </section>
</div>

<script>window.VP3_CONTACTS=<?= json_encode([
    'policyEndpoint'=>url('/api/agent-radar-policy.php'),
    'csrf'=>csrf_token(),
    'agents'=>$agentClientContacts,
],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;</script>
<script>
(() => {
  'use strict';
  const rows=[...document.querySelectorAll('[data-contact-row]')];
  const search=document.getElementById('contactsSearch');
  const filters=[...document.querySelectorAll('[data-contact-filter]')];
  const empty=document.getElementById('contactsEmpty');
  const notice=document.getElementById('contactsActionNotice');
  const cfg=window.VP3_CONTACTS||{};
  const agents=cfg.agents&&typeof cfg.agents==='object'?cfg.agents:{};
  const modal=document.getElementById('agentRelationshipModal');
  const modalPanel=modal?.querySelector('.contacts-agent-modal-panel');
  const modalTitle=document.getElementById('agentRelationshipTitle');
  const modalKicker=document.getElementById('agentRelationshipKicker');
  const modalMeta=document.getElementById('agentRelationshipMeta');
  const modalAvatar=document.getElementById('agentRelationshipAvatar');
  const modalBody=document.getElementById('agentRelationshipBody');
  const modalTabs=[...document.querySelectorAll('[data-agent-detail-tab]')];
  let active='all',busy=false,activeAgent=null,activeTab='overview',modalOpener=null;

  const esc=value=>String(value??'').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;');
  const number=value=>new Intl.NumberFormat().format(Math.max(0,Number(value||0)));
  const words=value=>String(value||'').replaceAll('_',' ').replace(/\b\w/g,m=>m.toUpperCase());
  function dateLabel(value){
    const raw=String(value||'').trim();if(!raw)return '—';
    const date=new Date(raw.includes('T')?raw:raw.replace(' ','T'));if(Number.isNaN(date.getTime()))return raw;
    return new Intl.DateTimeFormat(undefined,{month:'short',day:'numeric',year:'numeric',hour:'numeric',minute:'2-digit'}).format(date);
  }
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
  function metric(label,value,detail=''){
    return `<article class="contacts-detail-metric"><span>${esc(label)}</span><strong>${esc(value)}</strong>${detail?`<small>${esc(detail)}</small>`:''}</article>`;
  }
  function scoreGrid(agent){
    return `<div class="contacts-detail-metrics scores">${[
      ['Opportunity',agent.opportunity_score],['Value',agent.value_score],['Trust',agent.trust_score],['Risk',agent.risk_score],['Engagement',agent.engagement_score],['Cost',agent.cost_score]
    ].map(([label,value])=>metric(label,`${number(value)}/100`)).join('')}</div>`;
  }
  function renderOverview(agent){
    const analytics=agent.analytics||{};
    return `<div class="contacts-detail-stack">
      <div class="contacts-detail-metrics">${metric('All sessions',number(agent.session_count),'Lifetime Agent Radar relationship')}${metric('Page views',number(agent.page_view_count),'Lifetime')}${metric('Requests',number(agent.request_count),'Lifetime')}${metric('Conversions',number(agent.conversion_count),`${number(agent.referral_count)} attributed referrals`)}</div>
      <section class="contacts-detail-section"><div class="contacts-detail-section-head"><div><span>Relationship</span><strong>${esc(agent.stage_label||'Observed')}</strong></div><span class="contacts-detail-pill">${esc(agent.policy_label||'Profile default')}</span></div>
        <div class="contacts-detail-facts"><div><span>Operator</span><strong>${esc(agent.operator||'Unidentified operator')}</strong></div><div><span>Agent type</span><strong>${esc(agent.class_label||'Automated agent')}</strong></div><div><span>Verification</span><strong>${esc(agent.verification_status||'unverified')} · ${number(agent.confidence_score)}%</strong></div><div><span>First seen</span><strong>${esc(dateLabel(agent.first_seen_at))}</strong></div><div><span>Last seen</span><strong>${esc(dateLabel(agent.last_seen_at))}</strong></div><div><span>30-day properties</span><strong>${number(analytics.property_count||0)}</strong></div></div>
      </section>
      ${scoreGrid(agent)}
    </div>`;
  }
  function trendLabel(current,previous,pct){
    current=Number(current||0);previous=Number(previous||0);
    if(previous===0)return current>0?'New activity this week':'No activity in either week';
    const delta=current-previous;const signed=delta>0?`+${number(delta)}`:String(delta);
    return `${signed} vs prior 7d${pct===null||pct===undefined?'':` · ${Number(pct)>0?'+':''}${Number(pct)}%`}`;
  }
  function analyticsTable(title,rowsHtml,emptyText){
    return `<section class="contacts-detail-section"><div class="contacts-detail-section-head"><div><span>Analytics</span><strong>${esc(title)}</strong></div></div>${rowsHtml||`<div class="contacts-detail-empty">${esc(emptyText)}</div>`}</section>`;
  }
  function renderAnalytics(agent){
    const a=agent.analytics||{};
    const properties=Array.isArray(a.properties)?a.properties:[];
    const paths=Array.isArray(a.top_paths)?a.top_paths:[];
    const timeline=Array.isArray(a.timeline)?a.timeline:[];
    const maxViews=Math.max(1,...timeline.map(row=>Number(row.views||0)));
    const propertyRows=properties.map(row=>`<div class="contacts-detail-table-row"><div><strong>${esc(row.label||row.domain||(row.property_type==='native'?'VP3 Profile':'Property'))}</strong><small>${esc(row.property_type==='native'?'VP3 profile':row.domain||'Connected website')} · last ${esc(dateLabel(row.last_seen_at))}</small></div><b>${number(row.sessions)} sessions</b><b>${number(row.views)} views</b><b>${number(row.requests)} requests</b></div>`).join('');
    const pathRows=paths.map(row=>`<div class="contacts-detail-table-row paths"><code>${esc(row.path||'/')}</code><b>${number(row.events)} events</b><small>${esc(dateLabel(row.last_seen_at))}</small></div>`).join('');
    const timelineRows=timeline.map(row=>`<div class="contacts-timeline-row"><span>${esc(String(row.day||'').slice(5))}</span><div class="contacts-timeline-track"><i style="width:${Math.max(3,Math.round(Number(row.views||0)/maxViews*100))}%"></i></div><strong>${number(row.views)} views</strong><small>${number(row.sessions)} sessions</small></div>`).join('');
    return `<div class="contacts-detail-stack">
      <div class="contacts-detail-metrics">${metric('30d sessions',number(a.sessions_30d||0),`${number(a.active_days_30d||0)} active days`)}${metric('30d views',number(a.views_30d||0),`${number(a.requests_30d||0)} requests`)}${metric('7d sessions',number(a.sessions_7d||0),trendLabel(a.sessions_7d,a.sessions_previous_7d,a.session_change_pct))}${metric('7d views',number(a.views_7d||0),trendLabel(a.views_7d,a.views_previous_7d,a.view_change_pct))}</div>
      ${analyticsTable('Properties / sites',propertyRows,'No property activity in the last 30 days.')}
      ${analyticsTable('Top paths',pathRows,'No path-level activity in the last 30 days.')}
      <section class="contacts-detail-section"><div class="contacts-detail-section-head"><div><span>Last 14 days</span><strong>Relationship activity timeline</strong></div></div><div class="contacts-timeline">${timelineRows||'<div class="contacts-detail-empty">No sessions in the last 14 days.</div>'}</div></section>
    </div>`;
  }
  function renderActivity(agent){
    const activity=Array.isArray(agent.recent_activity)?agent.recent_activity:[];
    const rowsHtml=activity.map(item=>`<article class="contacts-activity-card"><div><strong>${esc(words(item.event_type||'activity'))}</strong><span class="contacts-detail-pill">${esc(item.severity||'low')}</span></div><p>${esc(item.summary||'Agent Radar activity')}</p><footer><code>${esc(item.path||'/')}</code><span>${esc(item.property_label||'VP3 property')}</span><time>${esc(dateLabel(item.occurred_at))}</time></footer></article>`).join('');
    return `<div class="contacts-detail-stack"><section class="contacts-detail-section"><div class="contacts-detail-section-head"><div><span>Agent Radar ledger</span><strong>Recent activity</strong></div></div><div class="contacts-activity-list">${rowsHtml||'<div class="contacts-detail-empty">No recent Agent Radar activity.</div>'}</div></section></div>`;
  }
  function renderPermissions(agent){
    const requestId=Number(agent.messaging_request_id||0),status=String(agent.messaging_status||'none');
    const messaging=requestId>0?`<section class="contacts-detail-section"><div class="contacts-detail-section-head"><div><span>Agent Messaging</span><strong>${esc(agent.messaging_label||'Messaging')}</strong>${agent.messaging_purpose?`<small>${esc(agent.messaging_purpose)}</small>`:''}</div></div><div class="contacts-agent-actions messaging">${status==='pending'?`<button type="button" data-agent-message-decision="allow_once" data-request-id="${requestId}">Allow once</button><button type="button" data-agent-message-decision="allow" data-request-id="${requestId}">Always allow</button>`:''}<button type="button" data-agent-message-decision="deny" data-request-id="${requestId}" class="danger">${status==='pending'?'Deny':'Revoke'}</button></div></section>`:'';
    return `<div class="contacts-detail-stack">
      <section class="contacts-detail-section"><div class="contacts-detail-section-head"><div><span>Attention</span><strong>${agent.watch_enabled?'Watched relationship':'Standard monitoring'}</strong></div><span class="contacts-detail-pill">${agent.watch_enabled?'Watchlist on':'Watchlist off'}</span></div><p class="contacts-detail-copy">Watched Agent contacts surface their next new session through the existing notification and Main Feed flow.</p><div class="contacts-agent-actions attention"><button type="button" data-agent-watch data-contact-id="${Number(agent.id)}" data-watch-enabled="${agent.watch_enabled?'1':'0'}">${agent.watch_enabled?'Stop watching':'Watch this agent'}</button></div></section>
      <section class="contacts-detail-section"><div class="contacts-detail-section-head"><div><span>Agent Gateway</span><strong>Website access</strong><small>Current: ${esc(agent.policy_label||'Profile default')}</small></div></div><p class="contacts-detail-copy">These controls use the canonical Agent Gateway contact policy; they do not create a separate CRM permission system.</p><div class="contacts-agent-actions"><button type="button" data-agent-policy="allow" data-contact-id="${Number(agent.id)}">Allow</button><button type="button" data-agent-policy="monitor" data-contact-id="${Number(agent.id)}">Monitor</button><label class="contacts-agent-limit"><button type="button" data-agent-policy="limit" data-contact-id="${Number(agent.id)}">Limit</button><input type="number" min="1" max="10000" value="${Math.max(1,Number(agent.gateway_limit_30m||30))}" data-agent-limit="${Number(agent.id)}" aria-label="Requests per 30 minutes"><small>/ 30m</small></label><button type="button" data-agent-policy="block" data-contact-id="${Number(agent.id)}" class="danger">Block</button></div></section>
      ${messaging}
    </div>`;
  }
  function renderIntelligence(agent){
    const intent=agent.intent?words(agent.intent):'Not enough evidence';
    let brain='Continue monitoring this recurring Agent relationship.';
    if(Number(agent.risk_score||0)>=70)brain='Risk is elevated. Keep the relationship restricted or monitored until the underlying evidence improves.';
    else if(Number(agent.opportunity_score||0)>=80)brain='This relationship currently qualifies as a high-opportunity, lower-risk Agent Brain signal.';
    return `<div class="contacts-detail-stack">
      <section class="contacts-detail-section"><div class="contacts-detail-section-head"><div><span>Relationship intelligence</span><strong>${esc(intent)}</strong><small>${agent.intent?`${number(agent.intent_confidence)}% confidence`:'No unsupported intent is inferred.'}</small></div></div><p class="contacts-detail-copy">${esc(agent.recommendation||'Continue monitoring this recurring agent contact.')}</p></section>
      ${scoreGrid(agent)}
      <section class="contacts-detail-section"><div class="contacts-detail-section-head"><div><span>Agent Brain</span><strong>Current relationship interpretation</strong></div></div><p class="contacts-detail-copy">${esc(brain)}</p><div class="contacts-detail-facts"><div><span>Registry purpose</span><strong>${esc(agent.registry_purpose||'Not declared')}</strong></div><div><span>Registry identity</span><strong>${esc(agent.registry_slug||'Unknown')}</strong></div><div><span>Verification method</span><strong>${esc(agent.verification_method||'Not verified')}</strong></div><div><span>Messaging</span><strong>${esc(agent.messaging_label||'Messaging · none')}</strong></div></div></section>
    </div>`;
  }
  function renderTab(tab){
    if(!activeAgent||!modalBody)return;
    activeTab=tab;
    for(const button of modalTabs){const on=button.dataset.agentDetailTab===tab;button.classList.toggle('active',on);button.setAttribute('aria-selected',on?'true':'false');}
    modalBody.innerHTML=tab==='analytics'?renderAnalytics(activeAgent):tab==='activity'?renderActivity(activeAgent):tab==='permissions'?renderPermissions(activeAgent):tab==='intelligence'?renderIntelligence(activeAgent):renderOverview(activeAgent);
  }
  function openAgentDetail(id,opener=null){
    const agent=agents[String(Number(id||0))];if(!agent||!modal)return;
    activeAgent=agent;modalOpener=opener;
    if(modalTitle)modalTitle.textContent=agent.name||'Automated agent';
    if(modalKicker)modalKicker.textContent=`Agent CRM · ${agent.stage_label||'Observed'}`;
    if(modalMeta)modalMeta.textContent=`${agent.operator||'Unidentified operator'} · ${agent.class_label||'Automated agent'} · ${agent.verification_status||'unverified'} ${number(agent.confidence_score)}%`;
    if(modalAvatar)modalAvatar.textContent=String(agent.name||'A').slice(0,1).toUpperCase();
    renderTab('overview');
    modal.hidden=false;modal.setAttribute('aria-hidden','false');document.body.classList.add('contacts-agent-modal-open');
    requestAnimationFrame(()=>modalPanel?.focus());
  }
  function closeAgentDetail(){
    if(!modal||modal.hidden)return;modal.hidden=true;modal.setAttribute('aria-hidden','true');document.body.classList.remove('contacts-agent-modal-open');activeAgent=null;modalOpener?.focus?.();modalOpener=null;
  }

  search?.addEventListener('input',apply);
  for(const filter of filters)filter.addEventListener('click',()=>{active=String(filter.dataset.contactFilter||'all');for(const button of filters)button.classList.toggle('active',button===filter);apply();});
  document.addEventListener('click',async e=>{
    const open=e.target.closest('[data-agent-detail-open]');if(open){openAgentDetail(open.dataset.agentDetailOpen,open);return;}
    if(e.target.closest('[data-agent-detail-close]')){closeAgentDetail();return;}
    const tab=e.target.closest('[data-agent-detail-tab]');if(tab&&activeAgent){renderTab(String(tab.dataset.agentDetailTab||'overview'));return;}
    const gateway=e.target.closest('[data-agent-policy]');const messaging=e.target.closest('[data-agent-message-decision]');const watch=e.target.closest('[data-agent-watch]');if((!gateway&&!messaging&&!watch)||busy)return;
    busy=true;const button=gateway||messaging||watch;button.disabled=true;
    try{
      if(watch){const enabled=watch.dataset.watchEnabled!=='1';await policy({action:'set_contact_watch',contact_id:Number(watch.dataset.contactId||0),watch_enabled:enabled});setNotice(enabled?'Agent added to your watchlist. Its next new session will be surfaced.':'Agent removed from your watchlist.');}
      else if(gateway){const contactId=Number(gateway.dataset.contactId||0),action=String(gateway.dataset.agentPolicy||'monitor'),limit=Math.max(1,Number(document.querySelector(`[data-agent-limit="${contactId}"]`)?.value||30));await policy({action:'set_contact_policy',contact_id:contactId,policy_action:action,limit_30m:limit});setNotice(`Agent Gateway contact policy updated to ${action}${action==='limit'?` ${limit}/30m`:''}.`);}
      else{const decision=String(messaging.dataset.agentMessageDecision||'deny');await policy({action:'access_request_decision',request_id:Number(messaging.dataset.requestId||0),decision});setNotice(decision==='allow_once'?'Agent Messaging allowed once for 30 minutes.':decision==='allow'?'Agent Messaging is now always allowed for this contact.':'Agent Messaging access denied or revoked.');}
      window.setTimeout(()=>window.location.reload(),350);
    }catch(err){setNotice(err.message,true);button.disabled=false;}finally{busy=false;}
  });
  document.addEventListener('keydown',event=>{if(event.key==='Escape'&&modal&&!modal.hidden)closeAgentDetail();});
  apply();
})();
</script>
<script src="<?= e(url('/member-shell-v77.js?v=universal-member-header-20260905')) ?>"></script>
</body>
</html>