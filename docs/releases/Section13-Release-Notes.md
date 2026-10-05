# VP3 Section 13 — Events, Notifications and Automation

Section 13 is built and merged. Installed-device and provider behavior remains owner testing.

| Build | Review | Merged source | Download |
|---|---|---|---|
| Cloud | [#533](https://github.com/davemt74-lang/software/pull/533) | `d0a75a21e6afb1809e8f891d2e7b90d09f8eda08` | [Production package workflow](https://github.com/davemt74-lang/software/actions/runs/37336548644) — artifact `software-production-deploy` |
| HomeServer Windows | [#273](https://github.com/davemt74-lang/otro/pull/273) | `b4740bdff6fb7c827a48296d43708d2c05ae11b6` | [Windows package workflow](https://github.com/davemt74-lang/otro/actions/runs/37337024534) — artifact `HomeServer-Windows` |

## Checksums

Cloud artifact ZIP: 11,739,916 bytes. SHA-256 `2cd7f223d6b7f40432bad24984f333fd50aea94e6b9ffcd35fcb6cbc9fa02850`.

Windows artifact ZIP: 241,533,971 bytes. SHA-256 `7b7a3ec68336b6ec8b45a844b489776b939471b389b3bb0de5b8146001d6de9a`.

The adjacent SHA256SUMS file uses the default filenames from GitHub artifact downloads. A renamed file has the same digest.

## Evidence

Cloud: 21 successful final-commit checks and 11 successful merged-main workflows. HomeServer: 6 successful final-commit checks and 6 successful merged-main workflows. Skipped scope checks are not counted as passed.

The Cloud ZIP was downloaded and independently checked for SHA-256, ZIP CRC, safe paths, exact file set, release commit and all 1,541 production Git blobs.

Windows CI completed its native automation regressions, executable build, packaged startup, upgrade takeover, single-instance enforcement, restart/shutdown, bridge continuity, recovery, restore and silent installer upgrade with private-data preservation. Its distribution step hashes both executables and writes RELEASE.json and SHA256SUMS.txt, which are included in the published ZIP. The artifact metadata ties the ZIP digest to the exact merged commit above.

The local execution workspace went offline after the Cloud ZIP validation. Direct Chat attachments, independent Windows ZIP CRC inspection and compiled-source fingerprint comparison could not be completed. Do not treat those extra archive checks as passed. The completed Windows CI evidence and published artifact remain available on GitHub.

## Install

Download the named artifact from each workflow page. Deploy Cloud into the existing application root while preserving config.php, .env, private/ and uploads/. Those runtime paths are excluded from the Cloud package. Extract the Windows ZIP and use HomeServerSetup.exe for the existing installation.

# VP3 Section 13: Events, Notifications and Automation

Scope: canonical Cloud event inbox, notification carriers and drawer; HomeServer local rules, workflow scheduling/recovery, and integrated federated event/run receipts. Agent Chat and Agent Brain retain the existing native ownership and approval boundaries. Standalone Tracky2 is outside this section.

## Repairs

- Cloud dispatch completion is fenced by the durable replay generation. A stale worker cannot overwrite a recovered event result or its Brain projection. Oversized external event identities are rejected instead of silently colliding.
- Cloud notification creation serializes canonical owner writes and normalizes carrier identities before deduplication. Carrier writes survive optional projection failure. Brain SQL selection agrees with the PHP classifier for HomeServer attention types.
- Drawer state requests discard obsolete responses and serialize state mutations.
- HomeServer local rule evaluation, trigger advancement, approval creation and execution receipts share a transaction. Failed multi-step routines leave no partial requests or consumed trigger. SQLite timestamp normalization restores rate limiting.
- Workflow checkpoints and completion honor the current lease. Completion, activity and notification commit together. Owner disable invalidates unfinished claims. Older runs cannot overwrite the latest automation summary.
- Stopping a busy scheduler retains its worker reference until it actually exits.
- Federated definitions, trigger decisions, runs, dispatch ledgers, state transitions and applied receipts commit atomically. Identical applied receipts replay without duplicate events; changed terminal receipts and receipts without a matching dispatch attempt are rejected. Cancellation cannot be undone by a late receipt. Execution claims commit before calling an external driver; concurrent or interrupted uncertain dispatches are not automatically repeated. Execution receipts and their audit event commit together. An interrupted physical action requires owner review, because software cannot infer whether an external device acted.

## Verification

Production-service tests force approval, trigger receipt, execution event and notification failures; exercise concurrent trigger and suggestion decisions; simulate worker takeover and owner disable; and verify terminal receipt immutability. Retained local automation, workflow recovery and federated contract suites remain mandatory. Cloud tests exercise recovery generation, notification transactions and drawer response ordering.

The software review rubric has ten criteria: ownership, approval retention, durable identity, transaction rollback, concurrency, lease recovery, cancellation, notification consistency, UI ordering and release provenance. Each criterion requires passing behavioral or existing integration checks before this section is considered complete; a numerical target is not a substitute for evidence.

## Owner testing

On the deployed builds, exercise one daily rule, an owner-approved routine, pause/resume, a workflow continuation, notification/read state from two tabs, restart during queued work, and a fresh federated event. Confirm a cancelled run stays cancelled. Test real provider or device playback/actions using the existing approval flow. Automated checks do not certify every external provider or physical device.

No schema migration or new hardware permission is introduced by this section. Existing authentication, webhook signatures, authority epoch, reconciliation and device approval gates remain required.


## Merged-main workflows

### cloud

- [Production Deploy Package](https://github.com/davemt74-lang/software/actions/runs/37336548644): success
- [HomeServer Runtime Journey](https://github.com/davemt74-lang/software/actions/runs/37336548607): success
- [Webhook + Event Infrastructure v19.2](https://github.com/davemt74-lang/software/actions/runs/37336548684): success
- [Profile Commerce Secure File v11.30](https://github.com/davemt74-lang/software/actions/runs/37336548737): success
- [Browser Companion Transaction Trust, Control & Production Hardening v22.80](https://github.com/davemt74-lang/software/actions/runs/37336548606): success
- [Team Workspaces v3.50](https://github.com/davemt74-lang/software/actions/runs/37336548699): success
- [Video Meetings v18.23 Meeting Commitment Command](https://github.com/davemt74-lang/software/actions/runs/37336548604): success
- [Public Funnel + Onboarding Continuity](https://github.com/davemt74-lang/software/actions/runs/37336548738): success
- [Package Entitlements v3.40](https://github.com/davemt74-lang/software/actions/runs/37336548665): success
- [Cloud Hosting V1](https://github.com/davemt74-lang/software/actions/runs/37336548610): success
- [Recovery Baseline](https://github.com/davemt74-lang/software/actions/runs/37336548627): success

### homeserver

- [HomeServer v2.4 Release Acceptance](https://github.com/davemt74-lang/otro/actions/runs/37337024546): success
- [HomeServer CI](https://github.com/davemt74-lang/otro/actions/runs/37337024534): success
- [HomeServer 2.3 Release Gate](https://github.com/davemt74-lang/otro/actions/runs/37337024448): success
- [HomeServer Managed FFmpeg](https://github.com/davemt74-lang/otro/actions/runs/37337024286): success
- [HomeServer v2.4 Data Continuity](https://github.com/davemt74-lang/otro/actions/runs/37337024290): success
- [VP3 OS Hardware Experience v1.3](https://github.com/davemt74-lang/otro/actions/runs/37337024194): success
