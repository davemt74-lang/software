<?php
declare(strict_types=1);

/**
 * Owner-scoped read model for the VP3 Agent Radar portal.
 *
 * This file intentionally adds no storage and performs no collection. It only
 * projects the canonical Agent Radar / Agent CRM tables into UI-safe records.
 */
function vp3_radar_portal_state(PDO $pdo,int $ownerUserId): array
{
    $empty=[
        'ready'=>false,
        'stats'=>[
            'agent_contacts'=>0,
            'sessions'=>0,
            'page_views'=>0,
            'events_24h'=>0,
            'high_risk_24h'=>0,
            'known_agents'=>0,
            'unknown_agents'=>0,
        ],
        'contacts'=>[],
        'events'=>[],
        'sessions'=>[],
    ];
    if($ownerUserId<1||!vp3_radar_schema_ready($pdo))return $empty;

    try{
        $contacts=$pdo->prepare("SELECT
            c.id,c.display_name,c.operator_name,c.contact_type,c.visitor_class,
            c.verification_status,c.confidence_score,c.trust_score,c.risk_score,
            c.engagement_score,c.value_score,c.cost_score,c.relationship_status,
            c.inferred_intent,c.intent_confidence,c.session_count,c.request_count,
            c.page_view_count,c.referral_count,c.conversion_count,c.first_seen_at,
            c.last_seen_at,r.slug AS registry_slug,r.purpose AS registry_purpose
          FROM vp3_agent_contacts c
          LEFT JOIN vp3_agent_registry r ON r.id=c.agent_registry_id
          WHERE c.owner_user_id=?
          ORDER BY c.last_seen_at DESC,c.id DESC
          LIMIT 120");
        $contacts->execute([$ownerUserId]);
        $contactRows=$contacts->fetchAll()?:[];

        $events=$pdo->prepare("SELECT
            e.id,e.agent_contact_id,e.session_id,e.event_type,e.severity,e.path,
            e.method,e.status_code,e.significance_score,e.risk_score,e.summary,
            e.occurred_at,c.display_name,c.operator_name,c.visitor_class,
            c.verification_status,p.label AS property_label,p.property_type
          FROM vp3_radar_events e
          INNER JOIN vp3_agent_contacts c ON c.id=e.agent_contact_id
          INNER JOIN vp3_radar_properties p ON p.id=e.property_id
          WHERE e.owner_user_id=?
          ORDER BY e.occurred_at DESC,e.id DESC
          LIMIT 160");
        $events->execute([$ownerUserId]);
        $eventRows=$events->fetchAll()?:[];

        $sessions=$pdo->prepare("SELECT
            s.id,s.agent_contact_id,s.visitor_type,s.entry_path,s.exit_path,
            s.referrer_host,s.request_count,s.page_view_count,s.event_count,
            s.started_at,s.last_seen_at,s.ended_at,c.display_name,c.operator_name,
            c.visitor_class,c.verification_status,p.label AS property_label,
            p.property_type
          FROM vp3_radar_sessions s
          INNER JOIN vp3_agent_contacts c ON c.id=s.agent_contact_id
          INNER JOIN vp3_radar_properties p ON p.id=s.property_id
          WHERE s.owner_user_id=?
          ORDER BY s.last_seen_at DESC,s.id DESC
          LIMIT 100");
        $sessions->execute([$ownerUserId]);
        $sessionRows=$sessions->fetchAll()?:[];

        $stats=$pdo->prepare("SELECT
            COUNT(*) AS agent_contacts,
            COALESCE(SUM(session_count),0) AS sessions,
            COALESCE(SUM(page_view_count),0) AS page_views,
            COALESCE(SUM(verification_status='known'),0) AS known_agents,
            COALESCE(SUM(verification_status<>'known'),0) AS unknown_agents
          FROM vp3_agent_contacts WHERE owner_user_id=?");
        $stats->execute([$ownerUserId]);
        $statRow=$stats->fetch()?:[];
        $recent=vp3_radar_owner_stats($pdo,$ownerUserId);

        return [
            'ready'=>true,
            'stats'=>[
                'agent_contacts'=>(int)($statRow['agent_contacts']??0),
                'sessions'=>(int)($statRow['sessions']??0),
                'page_views'=>(int)($statRow['page_views']??0),
                'events_24h'=>(int)($recent['events_24h']??0),
                'high_risk_24h'=>(int)($recent['high_risk_24h']??0),
                'known_agents'=>(int)($statRow['known_agents']??0),
                'unknown_agents'=>(int)($statRow['unknown_agents']??0),
            ],
            'contacts'=>$contactRows,
            'events'=>$eventRows,
            'sessions'=>$sessionRows,
        ];
    }catch(Throwable $e){
        error_log('Agent Radar portal state failed: '.$e->getMessage());
        return $empty;
    }
}
