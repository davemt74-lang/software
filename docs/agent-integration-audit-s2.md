# Integration audit S2: work-control and scheduling execution evidence

Work-control, personal scheduling and Team scheduling already had canonical services and Chat routing, but lacked shared receipts/readiness. Personal scheduling also used an OR permission policy where both account and Chat access were required. Some appointment-query failures appeared as empty calendars. This section connects these existing services rather than introducing another task store or calendar.

## Changes

- Add owner workflow read/control and personal/Team appointment read/change entries to the same runtime inventory. It now has 20 assessed capability IDs; the source audit still identifies 29 legacy dispatchers, with 25 outside inventory. Workflow controls operate the owner's shared work queue by explicit run ID; this does not claim management of every Brain task or only the current named Agent's jobs.
- Preserve canonical workflow, job/event, scheduling, Team membership, payment and approval services. Structured receipts are issued only from their actual result branches. An executing workflow gets `pause_request`; queue approval/retry/control receipts cannot certify underlying job completion.
- Require both account and Chat permission for Chat scheduling and controls. Reuse the live owner/conversation/Agent recheck, including current user activity/roles, before controls and confirmations. Canonical same-conversation/Agent/pool approval remains authoritative. No new model call or new action phrase router.
- Distinguish read results, no matches, required setup/upgrade/subscription, clarification, pending confirmation, confirmed mutations and unverified outcomes. SQL errors are not empty appointments. A potentially partial write failure is not evidence that nothing changed; diagnosis requires checking the current record before retrying.
- Preserve the paid appointment hold as `hold_for_payment`; diagnosis explicitly says payment is unverified and the appointment is not confirmed. It never treats a saved hold as payment success.

## Evidence and score

Ten scoped criteria: canonical reuse; owner isolation; live authority; bound approvals; truthful readiness; read/error distinction; control versus job completion; paid hold versus confirmation; transport/receipt parity; required CI plus exact merged package. Score is 10/10 only after all gates pass. This certifies the integration changes, not every scheduling engine/provider or live microphone.

Local tests include 13 actual SQLite workflow-control/event cases (only MySQL lock syntax adapted), 16 actual scheduling reads/canonical personal and Team cancellation cases (MySQL advisory locks adapted), and 85 production typed/stream/legacy Chat endpoint cases with a controlled routing model. Existing scheduling/workflow source contracts, phone/transcript regressions and recovery remain gates. A stale contract that only searched the old per-endpoint code was updated to include the real shared dispatcher; new endpoint tests verify actual execution.

## Remaining five-domain audit

| Domain | Current disposition |
|---|---|
| Calendar, scheduling, tasks/reminders | Reminder and personal-calendar receipts already certified. This section covers workflow controls and personal/Team scheduling. Brain task lifecycle remains a distinct integration to assess, not an implied capability of workflow controls. |
| Knowledge and Brain | Existing scoped retrieval and Profile policy remain. Next verify explicit search/recall evidence and error-versus-empty behavior, including retained transcript notes. |
| Phone/SMS | S1 read bridge and existing approval-screen send boundary retained; no carrier sending added. |
| HomeServer | Existing authenticated local-read execution remains. Next connect verified read outcomes to the same diagnosis boundary and assess advertised operations without assuming connectivity. No HomeServer publication. |
