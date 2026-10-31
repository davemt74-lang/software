# Integration audit S3: Knowledge, Brain tasks and HomeServer read evidence

This section completes the next source/runtime assessment across the five requested priority domains. It fixes concrete retrieval, scope and outcome gaps, not the entire application's unrelated legacy tool catalog. Live carrier, microphone, external calendar/provider, HomeServer connection and HostGator acceptance remain deployment checks.

## Knowledge and Brain

Explicit Knowledge/Brain/task reads and exact saved-task status commands use the same shared model-selected discovery request as the earlier sections; no additional classifier call or action phrase router. Plans strictly allow known capability IDs, bounded queries, task views/statuses and exact task IDs. Ability questions still inspect only. Source text cannot execute actions or widen scope.

Cloud Personal Knowledge search reuses the canonical owner/folder SQL and per-Agent Knowledge policy. Strict reads expose query failure rather than returning no matches. Ordinary Chat also receives a failed-read status/context item on a Cloud search error. Knowledge Off skips Cloud/HomeServer retrieval and removes old personal context; a selected Cloud folder cannot trigger unrestricted HomeServer search. Failure to identify legacy personal items cannot preserve unverified personal context.

The canonical Brain read function is isolated in `agent-brain-owner-context-v1.php`, still loaded by the original Brain module and retaining its old three-argument behavior. Explicit searches add strict matching, so no match does not silently become unrelated recent memory. The new adapter also retrieves retained transcript content through its existing source-linked helper and honors per-Agent policy. It restores the previous per-request memory namespace afterward. Profile visitors cannot invoke owner record tools.

Saved Brain tasks remain distinct from reminders and workflow jobs. Listing uses the existing lifecycle reader with reconciliation disabled, supports open/closed/all views and bounded query filtering, and does not infer state changes from archived conversation. The old updater selected by owner only and could report success even when its scoped write did not happen. It now selects within the current Agent namespace and verifies persisted status before returning success. Explicit status changes reuse canonical history/confidence writes; no new task store, scheduler or publication is introduced. Reads state their 100-record inspection / 20-result bound.

## HomeServer

The existing local file/Knowledge/tool/device read bridge now rechecks live Cloud owner/conversation authority, then calls the existing authenticated local execution service. Its Chat receipt is derived from the canonical server execution receipt, not remote text or model output. The bridge requires successful HomeServer provenance matching the requested operation and an appropriate result shape. Valid empty reads, malformed replies, offline failures and authorization denials remain distinct. A failed local read is never rewritten as a Cloud fallback success.

The inventory exposes the local-read adapter with connection/operation permission unchecked until an explicit request. A registry inspection can verify connection metadata, but does not certify every read's permission. No local filesystem path, credential, local action or HomeServer source publication is added by discovery. Existing task/calendar synchronization remains on its existing v2.43 services and tests; this section does not claim a new Chat adapter for every local operation.

## Five-domain disposition

| Domain | Built/verified integration in this audit | Remaining acceptance |
|---|---|---|
| Calendar and scheduling | Existing personal Calendar receipts/recurrence retained; S2 adds live scheduling permission checks, precise personal/Team outcomes, error-versus-empty handling and bound confirmations. | Real provider/paid appointment and external-calendar behavior. |
| Tasks/reminders | Existing reminder lifecycle/concurrency retained; S2 distinguishes queue controls from job completion; S3 adds verified scoped Brain task reads/status history. | Real scheduling/delivery and any other legacy task source remain separately assessed. |
| Knowledge/Brain | Strict explicit retrieval, owner/folder/Agent policy, read-only task recall, retained transcript recall and failed-read evidence. | Configured-model interpretation and real content queries after deployment. |
| Phone/SMS | S1 merged read adapter; existing exact-approval outbound screen preserved. | Carrier/admin/number/package setup and live sending under its existing consent flow. |
| HomeServer | Existing authenticated read bridge, truthful discovery and shared execution diagnosis; no Cloud fallback for an explicit local read. | A paired live HomeServer and operation scopes; no HomeServer source published. |

## Scoped acceptance

Ten criteria: canonical service reuse; owner isolation; Agent namespace/policy; live authority; strict action plans; scope preservation/no silent expansion; failure versus empty distinction; verified state persistence and source provenance; transport parity/read-only behavior; required CI and exact merged deployment package. Each section reaches 10/10 only after its own PR gates and package verification pass. Local-only sections remain uncertified for release.

Local evidence: 24 record/policy/namespace/lifecycle cases using actual SQLite and the shared broker; 11 local-read/provenance cases using the real local execution service with a fixture relay and adapted MySQL DDL; 115 production typed/streaming/legacy endpoint cases with a controlled routing model. Existing scope, Knowledge security, transcript/Profile, phone, scheduling/work-control and recovery regressions remain gates.
