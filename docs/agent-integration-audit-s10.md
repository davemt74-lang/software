# S10 — Tracky, Agent Eyes, Radar and Analytics in Chat

Three existing legacy handlers now use shared, bounded adapters for stored observations. The executable inventory adds five capabilities: `tracky.context.read`, `tracky.perception.request`, `radar.read`, `radar.gateway.manage` and `analytics.read`. This brings the audit to 57 capabilities and 26 of 29 legacy handlers; S11 retains the remaining release and Profile WebMCP handlers.

## Problems addressed

The prior Tracky read path could refresh stale evidence by starting active perception, choose the newest site when a selection was missing, or return the sole unrelated object for an unmatched location query. Missing observation times fell back to synchronization time, and future times could be described as fresh. Automatic Chat context had similar freshness and stale-alert problems.

Radar could interpret tutorial or capability questions as policy changes. Fuzzy contact selection could pick one contact from an ambiguous operator name, and successful policy persistence was described as enforcement across connected sites. The old incoming-agent delegate could also intercept a Radar read with a broad operator summary.

Analytics called a dashboard helper that created or reactivated the native property during a read. Named-property scope could fall back to all properties, “today” was a rolling 24 hours, and some metrics came from a recent-event sample. Long-lived native browser-contact counters were reported as period visits or page views despite not storing that information.

## Shared authority and routing

All three adapters check the current owner account, Chat access, named Agent ownership/activity and conversation assignment. They recheck authority before returning evidence, including changes made during retrieval. Tracky keeps its actual plugin and subscription gate. Radar and Analytics keep `profile_agent.access`; a failed lookup of seeded role permissions cannot grant fallback access. These checks preserve each module's existing access model.

Model-selected plans contain only allowlisted views and literal selectors. They cannot select another owner, override actions, expand a limit, inject SQL or request an arbitrary write. Capability descriptions do not return record content. Existing legacy handlers select the same adapters; tutorial, hypothetical and conditional requests do not become actions. Public and participant principals do not inherit owner observation access.

Read receipts distinguish verified results, no matches, required selection, unavailable storage, denied access and failed verification. Read-only paths do not create tables or properties, refresh observations, ingest events or change policy. The production endpoint fixtures compare stored table and schema fingerprints to verify this behavior.

## Tracky and Agent Eyes

`tracky_read` supports current context, site health, location, presence, last seen, changes and evidence. A site is selected by an exact owned ID or normalized literal label. Multiple sites require a selection. Location and last-seen requests bind an exact entity; an unknown label cannot fall back to an unrelated object. Ambiguous or truncated selector lists require an exact selection. ID matches are checked after SQL retrieval to avoid case-insensitive database collation widening.

Observation time, synchronization/contact time and continuity are separate facts. Missing, invalid, future or expired observation times cannot establish current context. Inferred and occluded relations retain their status. Last-seen answers state the bounded stored-history coverage; missing records do not establish physical absence. Stored alerts have an actual age check rather than trusting a persistent freshness flag.

Agent Eyes uses the existing ordered scene-sharing and visual-consent projections, including the canonical scene expiry and owner corrections. Raw frames, private perception paths, diagnostic payloads and synchronization cursors are not returned. Scene consent, selected site and continuity are checked again before evidence is released. Automatically supplied Chat context follows the same freshness and privacy boundaries.

Explicit requests for a new observation retain the existing opted-in v273 HomeServer path. Ordinary stored reads never call it. A successful `tracky.perception.request` receipt establishes that the governed request was accepted; it does not establish that a new physical observation completed. Follow-up diagnosis preserves that distinction. Live paired-device sensing remains deployment acceptance.

## Radar and Gateway

`radar_read` supports recent contacts, high-risk contacts, contact policies, scoped rules and the saved Access Profile. Queries apply current-owner filters and bounded literal matching before the result limit. Contact and property joins bind both sides to the owner, and final record-ownership checks discard results changed during retrieval. Stored counts, risk scores and timestamps are labeled as recorded evidence; automated-agent classification is not proof of identity.

Explicit Gateway commands retain the existing canonical contact, operator/class/path and Access Profile writers. Contact selection requires an exact ID, exact full name or exact registry slug; an operator name cannot silently select one contact. Profile names and request limits are validated without defaulting or clamping. The canonical writer owns its transaction. The adapter checks authority before and after the write and reads back the exact saved policy.

An identical saved policy returns a read receipt without another write. A changed policy returns completion only after saved-state verification. A failure after a write may have started reports an unverified outcome and asks the user to inspect Radar before repeating it. Replies and persisted receipt diagnosis distinguish saved policy from actual enforcement, which depends on the site's existing Gateway integration. Incoming-agent messaging and access approvals retain their separate canonical workflow.

## Analytics

`analytics_read` requires explicit `all_owned` or `property` scope. One property uses an exact owned ID, label or canonical domain. Unknown, foreign or ambiguous property selection asks for clarification and never widens to all sites. Inactive properties remain readable as stored history; a read does not activate them.

Views cover summary, properties, top pages, referrers, conversions, devices and browsers. Supported periods are today and rolling 7, 30 or 90 days. A single database clock read establishes the reporting end, start and timezone. All aggregates bind the same window. Today starts at that database calendar midnight. Since existing collectors store `DATETIME` values using `NOW()`, Chat reports the verified database timezone and current UTC offset rather than assuming historical timestamps were UTC or the user's local timezone.

Complete SQL aggregates replace the recent-event sample. Stored sessions last active in the period are labeled as sessions, not unique people. Page views and conversions are counted from dated matching events. Device/browser distributions describe collected events. Native profile visits use dated `profile_view` events with their existing deduplication semantics. Native page-view totals cannot be reconstructed for a period from cumulative browser-contact counters, and the reply says so. Raw IPs, cookies, session identifiers, user agents, event payloads, URL queries and sensitive path segments are omitted. Invalid metadata or missing tables are failures, not zero traffic.

## Scoped acceptance gates

The automated target is **10/10**, completed only after these gates pass:

1. Five executable capability bindings and strict shared observation plans.
2. Current owner, account, Agent, conversation and actual module permission checks, including changes during retrieval.
3. Pure stored reads with no DDL, property activation, sensing, ingestion or policy mutation.
4. Exact site/entity/contact/property selection with no ambiguous, foreign or unmatched fallback.
5. Tracky observation freshness, continuity, inference limits and ordered scene consent in replies and automatic context.
6. Explicit governed perception requests and exact canonical Gateway commands with verified, scoped receipts.
7. Complete Analytics aggregates, coherent reporting windows and truthful native metric coverage.
8. Private-field minimization, bounded projections and honest unavailable/failed/uncertain results.
9. Actual typed, streaming voice and legacy Chat routes, retained module regressions, and real MySQL execution on PHP 8.1 and 8.3.
10. Green PR workflows and byte-for-byte verification of the production ZIP from the merged commit.

Evidence is in `tests/agent-tracky-read-v1.php`, `tests/agent-radar-read-v1.php`, `tests/agent-analytics-read-v1.php`, the shared receipt tests and `tests/agent-capability-chat-api.mjs`. The domain suites support isolated real MySQL databases in the existing Cloud Hosting workflow. No new database schema or migration is introduced; missing existing module storage is reported explicitly. Live camera/microphone behavior, external-site Gateway enforcement and real traffic collection remain separate deployment acceptance. Mission change alerts remain on hold.

Local domain evidence: **98 Tracky**, **158 Radar**, **118 Analytics**, **84 shared receipt/diagnosis** and **46 retained participant endpoint** checks. The retained Tracky agent-brain, v273 contract join, shared-scene and Agent Eyes experience gates pass, as do the Radar Chat/access-control and Analytics contracts and 56 response-relevance assertions. The production Chat endpoint suite covers all prior sections together with these new read, policy and diagnosis paths; the PR and merged release remain the final certification gates.
