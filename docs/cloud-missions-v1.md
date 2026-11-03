# Cloud Mission execution and the ongoing research direction

A Mission describes a goal. A specialist is a worker role with an assignment and a bounded selection of tools; it does not require its own model. A tool performs an operation, while a skill describes a reusable workflow combining tools. The execution engine records individual runs, worker results and receipts.

## Delivered execution layer

Agent Teams can prepare Cloud Missions while HomeServer is offline. Cloud uses the configured AI provider, with an evidence analyst followed by a synthesis reviewer. Assignment review enables optional Knowledge search, Agent Brain recall, and [public web collection with saved sources](cloud-mission-web-v1.md). The existing workflow ledger remains the sole job queue, including leases, pause/resume, cancellation, explicit retry and result receipts. HomeServer execution remains selectable for existing local and browser capabilities.

The Chat page submits one bounded Cloud worker tick at a time while visible. The existing Cloud CLI worker can claim the same jobs. A browser tick is not a background scheduler: continuous unattended execution requires a deployed worker. Model usage follows normal provider, quota and accounting rules. Source access is checked before inference, before saving and when displaying results. Ordinary Chat remains ordinary Chat.

Cloud assignments currently allow at most three read calls per worker across attempts, one worker at a time, and a fifteen-minute one-shot reviewed execution window. They create analysis text, source summaries or document drafts; selecting document output does not publish or create a separate product. [Recurring Cloud Missions](cloud-mission-schedules-v1.md) add explicit durable read/save grants, daily/weekly execution and private Knowledge reports. Interactive browser and external-action operations remain HomeServer dependencies.

The three Cloud metadata tables initialize on first use. The existing job engine and job-control database migrations must already be applied; otherwise Cloud preparation reports that an upgrade is needed.

## Ongoing research module: next layers

The user's target is a persistent research and data collection module. Its parent Mission definition should outlive individual executions and retain its objective, permitted sources, schedule/triggers, budget, assigned specialist roles, skills, destination folder and notification rules. Each execution should be a separate canonical job linked to that parent, preserving run history and allowing pause, edit and deletion of the ongoing Mission without fabricating worker completion.

1. Delivered in recurring Cloud Missions: persistent reviewed definitions, daily/weekly scheduling, per-run jobs, overlap protection and private Knowledge reports. Subsequent sections can expand source filters and event triggers.
2. Connect external collection adapters, starting with web search and approved email reads. Preserve URLs/message references, retrieval times, content provenance and collection failures. Compare results across runs, deduplicate and report meaningful changes.
3. Store Mission source collections, reports and artifacts under the user's existing Knowledge folders. Link shared-screen sessions, existing Research jobs and Brain memories by source reference. Retain important derived findings through the existing reviewed Brain flow.
4. Add configurable specialists and skill workflows: collector, verifier, analyst and report/product creator. Expose each worker's permitted tools and actual results in Chat.
5. Connect email sending, phone operations and product creation through explicit action adapters with receipts and the user's configured approval rules. Add image creation when available. Tool names must not imply that these capabilities are already connected.

Example: a Mission monitors music licensing opportunities daily. A collector gathers source-backed opportunities, a verifier checks eligibility and deadlines, an analyst compares them with earlier runs, and a report worker saves a briefing in Knowledge. Longfellow surfaces new matches and can prepare an outreach draft. Sending that outreach is a separately authorized tool action.

## Validation

`tests/cloud-missions-v1.php` exercises canonical job transitions, receipts, access changes, cancellation during inference, output retention and approved source text. `tests/cloud-missions-api.mjs` exercises the production endpoint, normal Chat routing, offline history and actual worker execution with a test provider. `tests/cloud-missions-browser.mjs` exercises mobile review, start, worker ticks and safe output rendering. Test providers demonstrate orchestration; deployment provider credentials and unattended worker configuration still require site verification.
