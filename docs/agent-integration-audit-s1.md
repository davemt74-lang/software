# Integration audit S1: truthful discovery and phone reads

This section checks Cloud Chat integrations against their actual services. The runtime inventory now covers 14 capability IDs, including bounded phone-number/SMS reads and an explicit SMS approval-screen requirement. A registered service is not a Chat send adapter, permission grant, live carrier check or evidence that records have been fetched.

## Priority-domain findings

| Domain | Existing integration | Finding / disposition |
|---|---|---|
| Calendar and reminders | Canonical owner Chat reads/writes, confirmations and persisted receipts | Existing endpoint, recurrence, schema and MySQL gates retained. External calendars are not inferred from personal Calendar readiness. |
| Transcripts and knowledge | Source reads, analysis/research, retained highlights and policy-controlled Profile retrieval | Corrected readiness: analysis storage alone cannot establish source readiness; raw highlights do not require analysis tables, but do require transcript/Brain availability and permission. Knowledge/Brain retrieval is not proof every legacy action has structured receipts. |
| Phone/SMS | PH2 number registry, PH3 inbox and explicitly approved outbound UI | Added explicit owner/Agent-scoped reads through the existing model-selected shared broker. No extra routing model call. Existing SMS reader reused. Chat cannot purchase/register/send or mark read. SMS sending remains on the existing exact-approval surface with admin/quota/opt-out gates. |
| Work/tasks and scheduling | `agent_work_control_chat_v173`, personal/team scheduling dispatchers | Existing integrations were missed by the source audit or are outside runtime discovery. Next assessment: readiness, Agent scope and precise receipts for queued/paused/uncertain versus completed actions. Personal reminders do not imply control of every task/workflow. |
| HomeServer | Authenticated registry inspection and `homeserver_agent_read_v230_query` | Source audit now includes the actual read adapter. Live connection remains unchecked until an explicit authenticated request. No HomeServer source/publication or local compute/privacy policy changes. |

`tools/check_agent_capability_inventory.py` now identifies 29 actual legacy dispatcher entry points and reports all 28 outside runtime inventory with source file/line evidence. The two internal routing wrappers are excluded. Nonstandard work-control, HomeServer and Profile/WebMCP helpers are included. This is source coverage, not certification of their readiness, transport parity or mutation behavior. Missing executor definitions fail the audit. It also validates every assessed inventory binding.

## Phone read behavior

Capability questions inspect metadata only. Explicit number/inbox/thread requests use a strict `phone_read` plan within the existing discovery call. The server rechecks live account/conversation authority, subscription, current number assignment and active owned Agent before reading. Named Agents cannot read each other's threads; historical ownership cannot override reassignment. Profile visitors never receive owner inventory or this adapter.

Thread reads reuse canonical PH3 message storage, return IDs/timestamps/direction/status and bounded source-text excerpts, and leave unread counts/outbox untouched. Text from SMS is untrusted data. No inbound webhook begins model analysis; the adapter runs only for an explicitly requested owner Chat read. Carrier credentials and message bodies do not enter discovery metadata or its routing prompt. Reads are reflected in normal Chat history as requested by the user.

## Scoped acceptance (10 criteria)

1. Existing canonical storage/services reused.
2. Owner/account boundary enforced.
3. Named-Agent/current-assignment boundary enforced before list limits.
4. Live permission/subscription changes rechecked after interpretation.
5. Ability questions fetch no phone messages and perform no mutation.
6. Actual typed/voice-streaming/legacy endpoints share the broker.
7. Only server execution issues bounded, read-only receipts and diagnosis.
8. Long content has explicit limits and source labels.
9. Inventory distinguishes source/storage, approval surfaces and unassessed dispatchers.
10. Required local/PR regression gates and exact merged production package pass.

The score reaches 10/10 for this section only after all gates and package verification pass. Local validation includes 24 phone/readiness cases using actual SQLite records and the production broker; 73 production Chat API cases with a controlled model fixture; PH2/PH3 and existing capability/receipt/transcript checks. Real carrier, configured-model interpretation, microphone, HostGator and HomeServer checks remain deployment acceptance.
