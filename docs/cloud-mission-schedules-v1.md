# Recurring Cloud Missions

An ongoing Cloud Mission retains an approved goal, read-tool assignments, daily/weekly timing and a destination in the owner's existing My Knowledge library. Each due execution creates a separate canonical Cloud Mission job. The recurring definition is not an execution queue: the existing workflow engine owns worker claims, leases, cancellation and receipts.

## Using it

In Chat, prepare a Cloud Mission and review its assignments. Select at least one Knowledge or Brain read and its budget. Under **Schedule this reviewed task**, choose daily or weekly timing, timezone and the report folder. The default destination creates a Mission folder in My Knowledge; existing owner folders are also available. Confirming **Create schedule** authorizes future executions of that exact read plan and private report storage. This grant persists beyond the original fifteen-minute one-shot review and never enables email, phone, browser, publishing or other action tools.

Chat and Brain show future execution time, recent runs, saved-report state and whether retrieved source evidence changed since the previous saved report. A comparison fingerprint represents the retrieved material, not an exhaustive finding that nothing changed anywhere. Model wording is not used to decide whether source evidence changed. Generated Mission reports are excluded from fresh Knowledge collection to prevent a feedback loop. Prior authorized findings are supplied separately for context.

Pause/resume/cancel control future collection. They preserve existing run history and current work; use the individual run's controls to stop that work. Missed slots coalesce into one run rather than a backlog. Unfinished runs or unsaved reports prevent overlap. Failed or cancelled runs pause future collection for inspection instead of repeatedly spending model usage. Resume selects the next future slot. Restoring source permissions never fabricates a previously failed result.

## Background execution

The existing CLI worker now discovers due schedules even when their owners have no queued jobs:

```sh
php /absolute/site/path/agent-worker-v1910.php --executor=cloud --loop --sleep=5
```

For shared hosting, a hosting cron can invoke the same runner without `--loop` once a minute. The runner retains its existing single-instance lock. Each cycle queues due work and processes a bounded worker step; a two-step Mission may span two cron invocations. The deployment package does not install a hosting cron or daemon automatically.

Chat supplies an additional authenticated, visible-page scheduler tick every thirty seconds and its existing bounded inference ticks. The interface reports **browser-assisted while Chat is open** unless a recent CLI heartbeat is present; this mode does not promise execution while all Chat pages are closed. Schedule/history reads do not create runs or call a model.

## Persistence and permissions

Four metadata tables initialize automatically on first use. The existing durable job and job-control schema upgrades remain prerequisites. Slot creation, assignment binding, recurring approval and next-run advancement commit together under an owner schedule lock, with a unique schedule/due key. Operation UUIDs and exact payload hashes recover lost create/control responses without duplicate folders, grants or control revisions. Canonical prepare and approve helpers can participate in this outer transaction without committing it prematurely.

Current account, Agent, source and folder access are checked again before reads, inference results and report writes. Shared-screen source corrections/deletions invalidate descendant runs through the original template's source guard. Budgets are immutable for scheduled runs; new capabilities require another reviewed plan. Reports are private, indexed canonical Knowledge notes, saved once per completed run. They remain model-generated drafts and are not automatically promoted into Brain memories or published. Removing a destination folder prevents new writes rather than recreating it.

Recurring Missions can collect existing Knowledge and Brain evidence, and [public web evidence](cloud-mission-web-v1.md) from reviewed queries, saved sources or exact page URLs. Email collection, phone operations, product creation and image generation remain separate adapters to build next. The current specialist pair remains evidence analyst and synthesis reviewer; configurable role/skill catalogs are a later section.

## Validation

The SQLite journey covers schedule timing, confirmation, ownership, inherited grants, durable results, real Knowledge storage/indexing, comparison, feedback prevention, pause/resume/cancel, missed slots, failures and scheduler health. The MySQL journey uses production engine migrations and four simultaneous scheduler processes for one slot. API tests exercise the production route and workers; browser tests cover mobile folder selection, lost-response recovery, reviewed controls and honest background status.
