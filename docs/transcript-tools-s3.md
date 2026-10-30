# Transcript tools S3: highlights, Profile knowledge and reviewed follow-through

The existing transcript Add to Agent Brain button now saves independent, idempotent source-linked highlights instead of returning a single old memory for every later selection. Selections must match saved transcript content. Up to 20,000 characters are retained in metadata, with a bounded Brain preview, source fingerprint and segment/page/speaker/time references. Without selection it saves an explicit transcript reference; it does not claim the entire recording was copied into the prompt. Saves resolve the linked conversation Agent (or configured default for unlinked recordings) and restore request scope afterward.

Private Chat retrieves relevant retained content beyond the ordinary 360-character memory excerpt. Source changes are labeled. Saved analysis reports retain their content and review labels and resolve the same Agent scope. Profile Agent retrieval now includes explicitly saved transcript notes, highlights and reviewed intelligence as well as reports. Every item still passes live Knowledge Access and audience/per-Agent policy. No sharing switch is changed by saving a highlight. Whole-report Knowledge deduplication does not suppress distinct highlights.

Conversation commands can explicitly highlight source text, accept/reject a finding, then run its existing canonical Brain/Knowledge/task/project/CRM/Main Chat action. Unreviewed or stale results cannot execute. Reviewed follow-ups can create scheduled reminders through the existing idempotent reminder ledger. Canonical action receipts are preserved when stored analysis loads: the old depth-limited result sanitizer previously emptied nested receipt fields and could lose already-completed state. Only structurally valid stored receipts are restored; the AI-result sanitizer remains bounded.

Validation includes 21 SQLite cases using actual Brain persistence, canonical Profile sharing policy, review/action services and reminder execution; production typed/streaming/legacy highlight endpoints; the actual transcript panel/context browser test; MySQL four-client highlight and action concurrency. Browser certification caught a queued animation reopening a closed panel: logical open state now clears context immediately, fences late loads and prevents the queued frame from undoing Close. Existing regression/permission/browser/phone checks remain required gates.

## Scoped acceptance scorecard

Each section is ready only when all ten criteria below pass its automated gates. Live provider, real microphone and deployment acceptance remain separate.

| Criterion | S1 source reads | S2 intelligence | S3 retention/actions |
|---|---|---|---|
| Existing service reuse | Canonical page map | Existing analysis engine | Existing Brain, policy and action stores |
| Owner boundary | Foreign IDs denied | Foreign results/runs denied | Foreign promotion denied |
| Agent boundary | Conversation scope | Same source scope | Correct memory namespace and Recording/Knowledge rules |
| Typed/stream/legacy parity | Real endpoint tests | Saved analysis endpoints | Highlight endpoints |
| Selection accuracy | Active panel ID | Exact source ID | Selected quote validated against source |
| Bounded long content | Page/character continuation | Plugin/character continuation | Full retained selection plus bounded excerpts |
| Provenance | Segments/times/pages/hash | Analysis/research/review labels | Source links and hash-change labels |
| Failure behavior | Clarify, retry interpretation once | No hidden refresh/replay | No implicit acceptance/replay |
| Persistence/integrity | Actual SQLite reads | Actual stats engine writes | Actual Brain/action/reminder persistence and concurrency |
| Regression/release | Required CI and exact package | Required CI and exact package | Required CI, browser, MySQL and exact package |
