# VP3 Cognitive Runtime Specification v5.00

Status: **architecture contract for Phase 11B**

Wire contract: `cognitive-runtime-v1`

## 1. Purpose

VP3 Cognitive Runtime is the shared reasoning layer between VP3 product systems and Agent presentation/execution surfaces.

It exists so VP3 does **not** hardcode every combination of:

- meetings
- transcriptions and recordings
- annotations and Sources
- Team activity
- human messages
- Profile Agent activity
- Commerce/orders/customers
- calendar bookings
- Research
- Claims
- workflows
- Knowledge
- CRM/relationships
- Goals and commitments
- HomeServer
- Browser Companion
- future VP3 products

The runtime continuously analyzes authorized VP3 state, correlates related objects, identifies opportunities/risks/commitments/questions, proposes useful next actions, and decides whether anything deserves presentation.

The design principle is:

> **Analyze broadly. Surface selectively. Act only through deterministic authority.**

The language model is a reasoning engine. It is **not** the permission system, database authority, scheduler, payment authority, card renderer, or side-effect executor.

## 2. Relationship to existing VP3 architecture

v5.00 extends existing systems rather than replacing them.

Canonical systems retained:

- `users` remains VP3 identity authority.
- Agent Chat principal remains user + exact Agent namespace under v3.80.
- Agent tools remain authorized under v4.00.
- Agent Brain memory remains Agent-scoped under v4.10.
- model/HomeServer routing remains governed by v4.20.
- `agent_event_inbox` remains durable event ingress from v19.2.
- Agent action/risk/approval plans remain deterministic server policy.
- `agent_workflow_runs` remains the durable execution queue.
- `ai_execution_ledger` remains compute provenance/billing truth.
- notifications remain a presentation/attention ledger, not Agent authority.
- Activity Center / Agent Brain / History remain the deep explainability and audit surface.

### 2.1 Migration from the current cognitive loop

The current v3.10 cognitive loop may rank hardcoded candidate sources and can append an automatic **“Agent Brain priority update”** message into Agent Chat.

When Cognitive Runtime v5.00 is active:

1. the existing candidate sources become inputs to the Runtime;
2. source-specific hardcoded ranking becomes a hint, not final cognition;
3. `agent_cognitive_loop_v310_surface()` automatic main-feed injection is disabled;
4. presentation is owned by the v5.00 Presentation Arbiter;
5. Brain priority changes update Agent Brief / Brain / digest state instead of automatically creating individual Chat turns.

This removes the present “event happened → put it in Chat” behavior.

## 3. Non-negotiable invariants

1. The authenticated VP3 user and exact Agent namespace define the cognitive principal.
2. No LLM output grants data, resource, workspace, Team, plugin, Profile Agent, Human Conversation, or HomeServer authority.
3. Data must be authorized **before** it enters a model context packet.
4. Authorization is rechecked before card rendering and again before every side effect.
5. Models return structured proposals/reasoning summaries, never executable SQL/HTML/JavaScript.
6. Models may reference only server-provided object/tool/capability IDs.
7. Unknown tools, cards, actions, resource IDs, or capability names are rejected.
8. User-authored content is never copied into a generic telemetry ledger merely to support cognition.
9. VP3 stores evidence-backed reason summaries; it does not persist private model chain-of-thought.
10. A cognitive observation is not automatically a fact. Provenance and confidence must be explicit.
11. Automated cognition does not create an Agent Chat message for each event.
12. Voice is a presentation mode; it never expands authority.
13. Specialist cognition can contribute observations, but one primary Agent/Presentation Arbiter decides what reaches the user.
14. Every surfaced object must have an authorized card renderer or fall back to a non-actionable generic object card.
15. User preferences may suppress presentation, but must not silently widen analysis authority.

## 4. Runtime architecture

```
VP3 modules / HomeServer / Browser Companion / external verified events
                              │
                              ▼
                    Durable Event Intake
                     agent_event_inbox
                              │
                              ▼
                     Event Normalizer
                              │
                ┌─────────────┴─────────────┐
                ▼                           ▼
        Capability Registry          Object/Context Graph
                │                           │
                └─────────────┬─────────────┘
                              ▼
                      Context Builder
                 authorization + freshness
                              │
                              ▼
                      Cognitive Triage
                  deterministic / low cost
                              │
                              ▼
                      Reasoning Runtime
               model-assisted when warranted
                              │
                              ▼
                       Verification
              evidence + policy + confidence
                              │
                              ▼
                    Priority / Goal Engine
                              │
                              ▼
                    Presentation Arbiter
         none | memory | brief | digest | notify |
             voice | ask | user-requested chat
                              │
                ┌─────────────┴──────────────┐
                ▼                            ▼
          Universal Cards              Action Planner
                │                            │
                ▼                            ▼
 Chat / Agent Brief / Brain /         deterministic policy
 Notifications / Voice                 approval / execution
                                             │
                                             ▼
                                     Durable Job Engine
                                             │
                                             ▼
                                       Outcome Learning
```

## 5. Universal event envelope

Every participating module emits or adapts events into the v1 envelope.

Required fields:

- `event_id`: opaque stable event identity.
- `owner_user_id`: canonical VP3 owner/principal.
- `agent_namespace`: exact user Agent ID or system-Agent namespace when cognition is Agent-specific.
- `source`: registered module/provider.
- `event_type`: registered semantic event type.
- `occurred_at`: event time.
- `received_at`: VP3 ingestion time.
- `verification`: trusted/verified status.
- `object_refs[]`: authorized object references affected by the event.
- `correlation_id`: groups related operations.
- `causation_id`: event/action that caused this event when known.
- `schema_version`.

Optional fields:

- actor reference
- changed field names
- relationship hints
- urgency hint
- sensitivity classification
- external event ID
- compact sanitized metadata

Event payloads should identify **what changed**, not duplicate full database objects.

## 6. Object reference contract

Cognition passes object references rather than copied domain records.

An object reference contains:

- `type`
- opaque `id`
- `scope`: personal / team / public / profile_agent / homeserver / other registered scope
- optional workspace/team ID
- optional version/revision
- freshness timestamp
- sensitivity
- provenance source

A reference is not authority. Every context provider and card renderer re-resolves it under the current principal.

## 7. Capability Registry

Every cognitive-aware VP3 module registers itself. Central Agent Chat must not contain module-specific business rules.

A module registration declares:

- module ID and version
- object types it owns
- event types it emits
- context providers
- relationship providers
- card renderers
- read tools
- action tools
- permission resolver
- freshness policy
- sensitivity policy
- evidence/provenance types
- whether an object may be surfaced in Chat/Brief/Digest/Notifications
- whether voice-safe summaries are supported
- risk/approval class for actions

Example conceptual registration:

```json
{
  "module": "meetings",
  "objects": ["meeting", "meeting_brief", "meeting_summary"],
  "events": ["meeting.created", "meeting.reminder_due", "meeting.ended"],
  "cards": ["meeting", "meeting_brief", "meeting_summary"],
  "tools": ["meeting.context", "meeting.prepare_brief", "meeting.draft_followup"],
  "permission_resolver": "meeting_authorization",
  "context_provider": "meeting_context",
  "relationship_provider": "meeting_relationships"
}
```

Adding a new VP3 product should primarily mean **registering capabilities**, not editing the main Chat controller.

## 8. Context Graph

The Runtime needs relationships, not only isolated events.

Graph nodes are authorized object references. Edges are typed relationships such as:

- belongs_to
- attendee_of
- customer_of
- member_of
- relates_to
- created_from
- transcript_of
- recording_of
- followup_to
- commitment_from
- assigned_to
- blocks
- depends_on
- mentions
- purchased
- booked
- visited
- annotated
- researched
- discussed_in

Each edge records:

- source/provenance
- confidence when inferred
- created/updated time
- optional expiration
- confirmation state: deterministic / user_confirmed / model_inferred

Model-inferred edges never bypass the underlying objects' access rules.

## 9. Cognitive cycle

The canonical cycle is:

**Observe → Normalize → Authorize → Correlate → Retrieve → Reason → Verify → Prioritize → Propose → Present/Act → Learn**

### 9.1 Observe

Read incremental events since the last successful cognitive cursor. Do not rescan the complete account on every event.

### 9.2 Normalize

Deduplicate, group related events, resolve canonical object references, and collapse redundant low-level activity.

### 9.3 Authorize

Resolve each potential object against the authenticated principal and exact Agent namespace before context assembly.

### 9.4 Correlate

Build a bounded neighborhood of relevant objects from the graph.

### 9.5 Retrieve

Retrieve only context relevant to the event, active cognitive thread, current conversation, goals, commitments, or scheduled reflection.

### 9.6 Reason

Use an LLM only when deterministic triage says semantic reasoning can add value.

### 9.7 Verify

Validate every model-proposed reference/action/card against the server registry and current evidence.

### 9.8 Prioritize

Combine semantic assessment with deterministic signals:

- urgency
- time sensitivity
- goal relevance
- commitment relevance
- impact
- novelty
- user presentation preferences
- learned outcome factor
- risk
- confidence
- freshness
- interruption budget
- current user context

### 9.9 Propose

Produce opportunities, risks, commitments, questions, decisions, plans, or presentation candidates.

### 9.10 Present/Act

Presentation and action are separate decisions.

### 9.11 Learn

Record user acknowledgement/action/outcome against the canonical observation/opportunity—not against arbitrary wording.

## 10. Context packet supplied to an LLM

Models should never receive “the whole VP3 database.”

A context packet includes:

- cognitive principal identity (opaque IDs, not credentials)
- trigger event summary
- active goals
- open commitments
- active cognitive thread
- authorized related object summaries
- bounded recent relevant activity
- current conversation topic when applicable
- current user interruptibility/focus state
- presentation preferences
- registered tools available for this cycle
- registered card/object types
- prior surfaced observation outcomes
- data freshness/provenance

Context providers produce compact summaries with canonical object references.

Raw secrets, authorization headers, cookies, provider credentials, private filesystem paths, and unbounded conversation/message archives must never enter the packet.

## 11. Model output contract

LLM cognition returns strict structured output.

Allowed output categories:

- fact_summary
- opportunity
- risk
- commitment
- unanswered_question
- pattern
- anomaly
- forecast
- recommendation
- decision_support
- action_plan
- memory_candidate

Each observation contains:

- observation ID
- category
- concise title
- concise reason
- evidence references
- confidence 0–1
- novelty 0–1
- urgency 0–1
- estimated impact 0–1
- goal relevance
- expiry/valid-until when applicable
- proposed action IDs from the supplied registry
- proposed object/card references
- presentation recommendation
- optional voice-safe summary

The server validates and may alter/reject the presentation recommendation.

A model response never directly executes a tool.

## 12. Evidence and truth model

Every cognitive statement is typed as one of:

- `database_fact`
- `user_confirmed`
- `external_verified`
- `source_document`
- `model_inference`
- `hypothesis`
- `forecast`
- `recommendation`

The Runtime must distinguish:

**what VP3 knows** from **what the Agent infers** from **what the Agent recommends**.

For important suggestions, Brain can display:

### Why I'm suggesting this

- meeting starts in 42 minutes — calendar fact
- two prior commitments remain open — commitment ledger
- attendee sent a message yesterday — messaging fact
- active proposal exists — CRM/Commerce fact

No hidden chain-of-thought is stored or displayed.

## 13. First-class cognitive objects

### 13.1 Goal

Tracks desired outcomes.

Fields include:

- title
- target state
- status
- owner
- deadline
- importance
- related objects
- measurable progress
- blockers
- dependencies

### 13.2 Commitment

Tracks promises and obligations.

Types:

- user_to_person
- person_to_user
- agent_to_user
- team
- workflow
- meeting_action_item

States:

- proposed
- open
- in_progress
- waiting
- completed
- cancelled
- overdue

Every commitment should preserve its origin: meeting, chat, message, transcript, task, workflow, etc.

### 13.3 Opportunity

Persistent Agent-discovered value proposition.

States:

- new
- presented
- accepted
- dismissed
- acted
- resolved
- expired

Includes:

- why it was detected
- expected benefit
- confidence
- relevant goals
- related objects
- action candidates
- expiration window
- outcome

### 13.4 Risk

Tracks conditions that could negatively affect goals, commitments, customers, money, meetings, operations, or security.

### 13.5 Decision

A persistent decision object contains:

- question
- options
- evidence
- affected goals
- risks
- dependencies
- deadline
- recommendation if requested
- user's eventual decision
- observed outcome

### 13.6 Unanswered Question

Questions discovered in meetings, messages, Profile Agent conversations, Claims, Team discussions, etc. remain unresolved until answered/dismissed.

### 13.7 Cognitive Thread

Long-running subject context such as:

- Annotated launch
- Acme relationship
- Restaurant expansion
- Hiring
- Album release

A thread can contain meetings, messages, recordings, Research, tasks, orders, people, commitments, decisions, opportunities, and goals without becoming one giant Chat conversation.

## 14. Opportunity-first behavior

The Agent is expected to continuously seek useful actions and opportunities.

However, opportunity discovery and user interruption are separate.

The Runtime should routinely ask:

- What can the user do next?
- What can the Agent prepare?
- What can the Agent safely execute?
- What is likely to be forgotten?
- What is waiting on another person?
- What is another person waiting on?
- What relationship deserves follow-up?
- What business opportunity is emerging?
- What risk can be prevented?
- What repeated behavior should become a workflow?

An opportunity may stay silently in Brain/Brief until presentation thresholds are met.

## 15. Presentation Arbiter

Every cognitive observation is assigned one presentation outcome:

- `none` — no user presentation.
- `memory` — retain as authorized cognitive memory only.
- `brief` — update Agent Brief.
- `away_digest` — include in the next return summary.
- `notification` — surface as notification/card without writing a new Chat turn.
- `voice_announce` — spoken announcement paired with visual state/card.
- `ask_user` — needs clarification/approval.
- `chat_response` — only when directly answering a user turn or presenting one explicit return digest.

### 15.1 Chat pollution rule

Background events must **not** become separate assistant messages merely because they occurred.

Automatic individual “Agent Brain priority update” turns are prohibited when v5.00 is enabled.

## 16. Idle and return digest

The Runtime maintains a meaningful-interaction cursor.

Default policy:

- normal return-summary threshold: 60 minutes idle
- attention-level material changes may qualify after 30 minutes
- urgent events can notify immediately without creating a permanent Chat turn
- no material change = no summary

On return:

1. compare state at departure with current state;
2. correlate related events;
3. deduplicate by subject/object/thread;
4. rank by impact;
5. produce one **While you were away** digest;
6. render relevant display cards;
7. mark included observations as presented.

Five related customer events should become one customer update, not five assistant messages.

## 17. Universal Display Card contract

Models do not generate card HTML.

They request a registered object/card:

```json
{
  "card_type": "meeting",
  "object_ref": {"type":"meeting","id":"482"},
  "display_mode": "standard"
}
```

The server-side Card Registry:

1. re-authorizes the object;
2. fetches canonical current state;
3. computes permitted actions;
4. renders the card.

Display modes:

- `compact`
- `standard`
- `expanded`

Every cognitive-aware feature should register cards for its primary objects.

Initial required card families:

- transcription
- recording
- annotation
- source
- research
- claim
- team_activity
- human_message
- profile_agent_update
- contact
- commerce_order
- commerce_customer
- product
- calendar_booking
- meeting
- meeting_brief
- meeting_summary
- meeting_followup
- workflow
- goal
- commitment
- opportunity
- risk
- decision
- knowledge
- live_room
- homeserver
- browser_companion

Card actions are server-derived from permissions and risk policy.

## 18. Agent Brief

The new Agent Brief is a **summary/control projection** of Cognitive Runtime, not an independent intelligence engine.

It should expose:

- Agent active/inactive state
- currently working task(s)
- highest-priority observation
- approvals/blocked work
- next scheduled item
- important recent change
- top suggested action/opportunity
- attention count

The planned footer Agent icon:

- green dot: Agent active/available/working
- red dot: Agent inactive/disabled/offline
- attention count is a separate badge
- speaking/listening can use a distinct animation/state

Clicking opens the Brief popup. Detailed reasoning/history stays in Agent Brain sidebar.

## 19. Voice Presentation

Agent Voice is controlled by presentation policy and the user's Agent Voice setting.

A voice announcement requires:

1. Agent Voice enabled;
2. observation meets voice threshold;
3. user is interruptible under current policy;
4. no quiet-hours/focus suppression;
5. announcement is not a duplicate;
6. sensitivity is allowed for speech;
7. visual card/notification is also available.

Examples:

- “David, you have a new profile visit.”
- “David, just reminding you about your meeting at 10:30.”
- “Your 10:30 meeting starts in 15 minutes. Your pre-meeting brief is ready.”
- “Your transcription is finished. I found three follow-up items.”

Voice urgency levels:

- passive
- normal
- attention
- urgent

Voice wording may be model-generated from an approved bounded fact set, but the **decision to speak** is deterministic Runtime policy.

## 20. Meeting Intelligence lifecycle

Meetings are first-class cognitive threads, not isolated calendar entries.

### 20.1 Canonical related objects

- calendar event
- meeting
- attendees
- contacts/CRM
- messages
- Profile Agent interactions
- prior meetings
- recording
- transcription
- notes
- meeting brief
- meeting summary
- commitments/action items
- follow-ups
- Research/Knowledge
- relevant orders/proposals/projects

### 20.2 Lifecycle

**scheduled → preparation window → reminder due → imminent → active → ended → follow-up → continuity**

### 20.3 Before meeting

Cognition can retrieve:

- attendees and relationship history
- prior meeting summaries/transcripts
- unresolved commitments
- unanswered questions
- relevant messages
- CRM/customer state
- orders/proposals
- Research/Knowledge
- project/workflow status
- recent meaningful changes

The Runtime produces a **Pre-Meeting Brief card** and suggests useful preparation actions.

### 20.4 Reminders

Reminder timing is deterministic and configurable.

Typical checkpoints:

- 60 minutes
- 15 minutes
- 5 minutes

Cognition enriches the reminder; it does not decide whether calendar time is real.

A reminder can produce a meeting card and voice announcement when Agent Voice policy permits.

### 20.5 During meeting

When authorized recording/transcription exists, associate it directly with the meeting.

The Runtime may identify:

- decisions
- commitments
- action items
- questions
- risks
- opportunities
- follow-up requests

### 20.6 After meeting

Create a **Meeting Summary card** containing:

- concise notes
- decisions
- action items
- commitments
- unresolved questions
- recording/transcription links
- suggested next actions

The Agent should propose follow-ups such as:

- draft message/email
- create/assign tasks
- update CRM
- save durable Knowledge
- start Research
- schedule next meeting
- update project/workflow

### 20.7 Meeting continuity

The next meeting retrieves the previous meeting's decisions, commitments, unresolved questions, follow-up outcomes, and material changes since then.

Recurring meetings therefore develop durable continuity without copying every transcript into every prompt.

## 21. Interruptibility and attention budget

The Runtime models whether the user is:

- active in Chat
- typing
- in a meeting
- in Focus mode
- idle
- away
- listening/speaking
- otherwise non-interruptible

A user can also have an **attention budget**.

High-value information displaces low-value information. Lower-priority observations wait for Brief/digest.

Presentation preferences may include:

- always announce meeting reminders
- never verbally announce Commerce
- batch Team activity
- only interrupt for urgent workflow failures
- announce high-value bookings
- suppress Profile visits during Focus mode

## 22. Model routing and cognitive budgets

Not every event should invoke a large model.

Recommended tiers:

### Tier 0 — deterministic

Deduplication, authorization, known reminder times, state comparisons, hard policy, risk class, card rendering.

### Tier 1 — lightweight semantic triage

Cheap/local/fast model for classification, entity hints, grouping, novelty, and likely relevance.

### Tier 2 — cognitive reasoning

Stronger model for cross-object correlation, opportunity/risk reasoning, meeting briefs, decision support, complex planning.

### Tier 3 — reflection

Scheduled or high-impact deeper analysis across a bounded period/thread.

Routing must reuse the v4.20 runtime decision: Auto / HomeServer-only / VP3 Cloud.

HomeServer private context may remain local. Cloud receives only data permitted by the paired scope and current user policy.

## 23. Specialist cognition

Modules may provide specialist cognition:

- Meeting Intelligence
- Relationship Intelligence
- Commerce Intelligence
- Research Intelligence
- Team Intelligence
- Workflow Intelligence
- Profile Agent Intelligence
- Annotated Intelligence

Specialists produce **observations**, never independent user interruptions.

The primary Agent owns:

- cross-domain ranking
- conflict resolution
- presentation
- voice
- final suggested actions

The user experiences one Agent, not a swarm.

## 24. Action planning and execution

Cognition proposes registered action IDs.

The deterministic Action layer then resolves:

- exact target object
- current user authority
- required capability
- risk
- approval
- reversibility
- idempotency
- execution target
- audit requirements

Autonomy levels may be configured per action class:

- suggest_only
- draft
- prepare_and_ask
- execute_low_risk
- autonomous_within_policy

The LLM cannot upgrade an autonomy level.

## 25. Workflow learning

The Runtime can detect repeated user behavior and propose reusable workflows.

Example:

> “You performed the same six follow-up steps after the last four customer meetings. Create a Meeting Follow-Up workflow?”

The LLM can compile user intent into a proposed trigger/condition/action graph.

VP3 validates the graph against the Capability Registry before it can be saved or activated.

## 26. Memory model

Cognitive memory is layered:

- working context
- event/episode history
- Agent Brain semantic memory
- goals
- commitments
- preferences
- cognitive threads
- outcomes
- relationship summaries

Memory rules:

- retain provenance
- confidence may decay
- inferred preferences decay unless reinforced
- user-confirmed facts outrank inference
- stale context is marked stale
- permissions are always rechecked at retrieval
- no raw chain-of-thought storage
- no secret/credential retention

## 27. Outcome learning

The Runtime learns from actual outcomes, not only clicks.

Examples:

- customer replied
- meeting booked
- order completed
- workflow succeeded
- commitment completed
- user dismissed suggestion repeatedly
- recommendation produced no result
- proposed follow-up was accepted but later cancelled

Outcome learning tunes ranking and presentation while remaining bounded by hard policy.

## 28. Observability and cognitive replay

Every cognitive cycle should have a trace ID and record bounded metadata:

- trigger event IDs
- object reference IDs
- context provider names
- model/runtime route
- contract version
- prompt/template version
- output validation result
- observation IDs
- presentation decision
- action plan IDs
- latency/token/cost facts
- outcome linkage

Do not store raw secrets or unbounded private message/transcript bodies in telemetry.

Authorized operators need **cognitive replay** that reconstructs the sanitized evidence packet and structured result so a bad recommendation can be debugged without exposing hidden chain-of-thought.

## 29. Evaluation harness

The implementation must include scenario tests.

Minimum scenarios:

1. User returns after 60+ minutes and nothing meaningful changed → no digest.
2. User returns after multiple related Team events → one correlated digest item, not multiple Chat turns.
3. Meeting starts in 15 minutes + Voice enabled → meeting card + one voice reminder.
4. Voice disabled → visual presentation only.
5. Meeting transcript completes → summary + extracted action opportunities.
6. Prior meeting commitment is overdue before next meeting → Pre-Meeting Brief includes it.
7. Profile visit while user is busy → Brief/digest unless policy says immediate.
8. Failed payment + unread customer message → one correlated customer risk/opportunity.
9. LLM proposes unregistered action → reject.
10. LLM proposes inaccessible object → reject before presentation.
11. Team permission is revoked after context creation → reauthorization blocks card/action.
12. Repeated duplicate event → no duplicate opportunity/notification.
13. User asks “show my bookings” → direct card results in Chat.
14. Focus mode → delay non-urgent voice/notifications.
15. High-impact urgent failure → immediate notification without automatic Chat pollution.
16. Specialist models disagree → primary Agent resolves; only one presentation reaches user.
17. HomeServer-only cognition → no Cloud reasoning route.
18. User repeatedly dismisses low-value opportunity class → lower presentation priority.
19. User-authored meeting content remains outside generic telemetry.
20. Card action permission changes → action disappears/fails safely on recheck.

## 30. Proposed persistence domains

Implementation may evolve existing tables, but the logical domains are:

- cognitive registry
- object/relationship edges
- cognitive cursor/state
- observations
- opportunities
- goals
- commitments
- decisions
- unanswered questions
- cognitive threads
- presentation ledger
- acknowledgement/outcome ledger
- user cognitive preferences

The existing event, Brain memory, workflow/job, notification and AI execution ledgers should be reused rather than duplicated.

## 31. Implementation sequence

### 11B.1 — Runtime contracts

- Capability Registry
- object references
- context providers
- observation schema
- Presentation Decision
- Card Registry interface
- meeting module contract

### 11B.2 — Presentation correction

- disable automatic cognitive priority Chat injection
- add idle/return digest
- move Agent Brief to footer popup
- use Brain sidebar for detailed evidence/history
- add Agent status dot and attention badge

### 11B.3 — Universal Cards

Implement initial card families and actions.

### 11B.4 — Model-assisted cognition

- structured context packets
- strict model output validation
- cross-object correlation
- opportunities/risks/questions
- model routing/cognitive budgets

### 11B.5 — Meeting Intelligence

End-to-end meeting lifecycle, voice reminders, pre/post meeting cards, commitments and continuity.

### 11B.6 — Goals / Commitments / Opportunities / Decisions

Persistent cognitive objects with outcome learning.

### 11B.7 — Cross-domain cognition

Relationship, Commerce, Team, Profile Agent, Annotated, Research, workflow and HomeServer correlation.

### 11B.8 — Evaluation + hardening

Scenario harness, privacy/performance review, replay tooling, model failure modes, fallback behavior.

## 32. Completion criteria

Cognitive Runtime v5.00 is considered implemented when:

1. new product modules can register events/objects/cards/tools without editing the central Chat renderer;
2. the primary Agent can reason across at least five registered VP3 domains;
3. no background event automatically creates a separate Chat message;
4. the idle return digest is correlated and deduplicated;
5. Agent Brief and Brain consume the same ranked state;
6. cards reauthorize objects/actions at render time;
7. voice announcements follow the same Presentation Decision as visual notifications;
8. meeting lifecycle works from pre-meeting through follow-up/next-meeting continuity;
9. model output is schema validated and cannot invent executable authority;
10. goals, commitments and opportunities influence ranking;
11. outcome learning changes future prioritization without bypassing hard policy;
12. HomeServer/Cloud model routing preserves v4.20 boundaries;
13. cognitive replay can explain a recommendation using evidence/provenance without exposing private chain-of-thought;
14. scenario evaluation passes on both supported PHP runtimes and the supported JavaScript runtime.
