# VP3 Transcription Apps

## Architecture

Transcription intelligence is organized as registry-defined apps. The canonical registry is `includes/transcription-app-registry.php`. The browser reads public app metadata from `/api/artist-listening-intelligence-v300.php` instead of maintaining a second hard-coded app list.

Each persisted app module owns:

- app ID
- transcript source hash
- source word count
- generated time
- provider
- model
- structured result
- independent freshness state

Running one app updates only that app's module. Existing results for other apps are preserved. Legacy flat master-analysis keys are projected from modules for compatibility with existing Agent Brain, Knowledge, Profile Agent and activity integrations.

`Stats` remains deterministic and does not consume AI tokens. AI apps use transcript page analyses as primary evidence and may compare authorized Agent Brain / Knowledge context without attributing that private context to the live transcript.

## Current apps and report upgrades

### Basic Analysis
Upgrade from a general summary into an evidence-aware report:

- executive summary
- interpretation
- key findings
- agreements
- conflicts
- changes from prior context
- open questions
- context gaps
- evidence/page references
- confidence

### Stats
Expand deterministic reporting beyond word count:

- duration
- transcript turns
- speaker count
- question count
- questions per 1,000 words
- words per minute
- average words per turn
- longest turn
- speaker word share
- speaker turn share
- questions by speaker
- notes / markers / other events

Future deterministic extensions can add interruption/overlap metrics when reliable timing data is available.

### Suggested Actions
Make actions operational rather than generic:

- action
- owner
- timing
- priority
- rationale
- evidence
- confidence

Unknown owner/timing stays unknown rather than being inferred.

### Suggested Responses
Turn response suggestions into usable follow-up:

- response
- intended audience
- purpose
- tone
- evidence
- confidence

### Decisions & Commitments
Separate concluded choices from promises/obligations:

- decision or commitment
- owner
- timing
- evidence
- confidence

Ideas and suggestions should not be labeled decisions.

### Key Moments
Explain significance rather than producing a highlight list:

- moment
- why it matters
- transcript page
- participants
- confidence

### Studio Notes
Structure production intelligence:

- note
- category
- target song/section/track
- priority
- evidence
- confidence

Categories include song, lyrics, arrangement, performance, recording, mix, gear, tempo and production.

### Knowledge Extractor
Extract only durable memory candidates:

- type
- key
- value
- evidence
- confidence
- conflict with prior context

External research must never become a private-user fact merely because it appeared in the same report.

## Added apps

### Topics & Themes
Build a normalized subject map:

- topic
- concise summary
- importance
- evidence
- confidence

Useful for search, transcript navigation, project grouping and longitudinal trend analysis.

### Entities & Data
Extract explicit structured entities:

- people
- organizations
- locations
- products/projects
- dates/times
- amounts/quantities
- URLs/emails
- other named identifiers

Every item carries context, evidence and confidence. Identity must not be inferred when the transcript did not establish it.

### Risks & Blockers
Identify supported operational risk:

- risk/blocker/dependency
- impact
- likelihood
- owner
- mitigation
- evidence
- confidence

The app should return an empty report rather than inventing risks.

### Timeline & Milestones
Create an evidence-grounded chronology:

- event/milestone
- timing
- status
- owner
- evidence
- confidence

Relative timing such as “next week” remains relative unless a reliable absolute date is available.

## Recommended next apps

These are not enabled in the first registry migration but are strong candidates.

### Questions & Answers
Extract asked questions, whether they were answered, the answer, respondent, evidence and unresolved follow-up. This is especially useful for interviews, sales calls and meetings.

### Sentiment & Stance
Track stance toward named topics rather than generic mood. Report participant/topic, stance, change, evidence and confidence. Avoid pseudo-psychological inference.

### Requirements & Constraints
Extract requirements, acceptance criteria, constraints, dependencies and non-negotiables for product/project conversations.

### Opportunity Finder
Identify explicitly supported commercial/product/content opportunities, why they matter, evidence, next validation step and confidence. It should be conservative and never invent demand.

### Follow-up Tracker
Convert transcript commitments and questions into a follow-up ledger with owner, due timing, dependency and completion state. This can later connect to VP3 tasks/workflows.

### Contact / CRM Intelligence
When a transcript is explicitly associated with known contacts, produce relationship-safe CRM updates such as interests, requests, objections, follow-up commitments and relationship changes. Identity must come from existing authorized CRM context, not transcript guessing.

### Comparison / Change Report
Compare the current transcript with prior related transcripts or authorized project state and report what is new, changed, contradicted, resolved or still open.

### Research Brief
Keep web research separate from private transcript facts and turn verified public findings into a citation-oriented research report with source provenance, verification state and conflicts with transcript claims.

## Execution principles

1. **Evidence first.** Important findings should point back to transcript pages or deterministic source data.
2. **Independent persistence.** Running one app must never erase another app's result.
3. **Independent freshness.** Each app knows whether its source hash still matches the transcript.
4. **No forced output.** Empty is better than fabricated findings.
5. **Provenance boundaries.** Transcript evidence, Agent Brain, Knowledge and web research remain distinguishable.
6. **Deterministic where possible.** Measurements should not consume AI tokens when they can be computed reliably.
7. **Structured output.** Reports should be machine-readable enough to power CRM, tasks, search, Agent Brain and future workflows.
8. **Backward compatibility.** Existing Agent Brain / Knowledge / Profile Agent integrations remain functional during migration.
9. **Permission ownership.** Apps inherit existing owner-scoped transcript and personal-data permissions; no new sharing bypasses.
10. **Bounded live analysis.** Live updates should rerun only when enough transcript change justifies the cost.
