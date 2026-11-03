# S11 — Music release plans and verified Profile handoffs

This section assesses the final three handlers in the managed Chat integration audit: `release_v105_chat_tool`, `vp3_agent_profile_webmcp_plan_v193` and `vp3_profile_webmcp_return_followthrough_v195`. The inventory now has **62 executable capabilities and all 29 legacy handlers assessed**. These are coverage measures for the declared audit, not a count of every feature in VP3.

The five added capabilities are `music.releases.read`, `music.releases.create`, `profile.webmcp.read`, `profile.webmcp.handoff` and `profile.webmcp.return.read`. Typed, streaming voice and legacy Chat use the same validated plans, current authority and server receipts.

## Problems addressed

The previous release parser selected the wrong title/date capture groups, ignored explicit workspace selection and could create a record for an ability or deferred request. Selection helpers could change the active session workspace or use the first available workspace. Lists did not expose their coverage, missing counts could become zero, and “next” could include past or inactive releases. Reads and creation lacked current Agent/conversation checks and a durable receipt tied to the saved user request. Automatic release context used the same broad selection and exposed unnecessary planning notes.

Profile capability inspection previously issued continuation tokens and planned telemetry, which could initialize tracking records. Browser-reported completion and replay flags could become a success statement without a matching server action. Contexts did not bind the exact named Agent; a zero conversation could bypass a positive binding. Reauthorizing only the structured plan left its old answer or links intact. Generic follow-ups could reuse an unrelated return, and expired links could retain a ready state. Some canonical capability lookup failures were converted into unavailable/empty observations without disclosing that verification failed.

## Release reads and internal planning

`release_read` supports list, detail, items and deadlines. Selection accepts one exact manageable Music Workspace ID or literal name, and one exact release ID or title when the view requires it. Ambiguous workspaces or releases require a choice. An explicit unknown or foreign selector never falls back to a different workspace. A selected current workspace is honored only while its actual owner/manager relationship and owner plugin remain valid. Reading does not change that selection.

Lists have bounded projections and complete matching counts. Task counts use exact release/workspace joins. Upcoming and next release filters use active planning states and the verified database date; deadlines distinguish all, upcoming and overdue records. Replies describe stored planning and task status. Historical completed tasks without a completion timestamp say that the timestamp was not reported. Schema, query or projection failures cannot become empty successful lists. Raw notes, private provider details and unrelated records are excluded from automatic context and Chat projections.

`release_create` requires a literal title and exact valid `YYYY-MM-DD` target date in the current saved user turn. Model plans may not supply owner IDs, status, arbitrary provider fields, publication commands or a turn identity. The runtime passes the actual server-created user-message ID separately, overwriting any client-supplied value. The adapter checks the saved text against the planned title, date and workspace before calling `music_workspace_resources_v331_save_release`.

The canonical release insert, exact saved-field read-back and namespaced receipt on the locked user-message row share one transaction. A receipt failure rolls the new record back. Reprocessing the same saved turn rechecks its original binding and the current release, then returns a read receipt without a second insert. Missing, malformed or changed replay targets do not trigger recreation. A deliberate new user message is a separate request. The receipt states that an internal planning record was created; no external publication or distribution is inferred.

Release numbers are also disambiguated before workflow control. “Cancel release #101” cannot select workflow #101. Explicit workflow commands retain their original routing, including a workflow number followed by a release reference.

## Profile inspection and handoff

`profile_webmcp_read` performs a pure read of the current owned Profile and canonical capability/catalog/scheduling state. It creates no Profile, tracking property, telemetry, ledger entry or continuation token. Strict observation mode propagates canonical lookup failures rather than advertising a successful empty read. A private or unconfigured Profile is reported explicitly. The canonical public-schedule lookup uses the actual `user_agents.owner_user_id` ownership column; its old nonexistent `user_id` join is corrected and tested without a fixture alias. Account, Agent, conversation and actual `profile_agent.access` are checked around the scoped reads.

`profile_webmcp_handoff` requires an explicit current request to open or continue on the public Profile. The broker supplies the original user text separately from the model's bounded goal. Capability questions, tutorials, feature-building discussion and deferred requests cannot issue a handoff. The current public Profile, owner, named Agent (including system Agent zero), conversation (including zero), recommended tools and token expiry are bound in the existing session continuation store.

The handoff is a review link with a `clarification_required / confirmation_required` receipt. All transactional actions retain the signed native Profile surface and its existing confirmation. The return URL carries the original Agent and conversation selection. The full shared result authorizer rebuilds the answer, plan, actions and receipt from current authority before action sanitization; invalid, consumed, expired or reassigned plans cannot leave old ready prose or working links behind. Responses and saved history retain the same safe plan/return projection across transports.

## Native server proof and returned results

The native browser transport carries optional continuation proof separately from tool arguments. A supplied proof must match the existing session context, current owner/Profile, Agent, exact conversation and recommended tools before native dispatch. Ordinary unbound public Profile operations keep their existing authority. Continuation data is not copied into canonical argument hashes, provider payloads or telemetry.

After a successful native response, the server records an exact binding to the prepared intent and the existing `profile_webmcp_actions` ledger. The binding checks owner, Profile, native surface, property, native session, operation/tool pair, payload and confirmation identity. A canonical idempotent replay must resolve to the same scoped operation, session and payload. A preparation response that still needs input creates no action proof. Browser notes remain UI observations and cannot set server result, tool, replay or ledger fields.

Returned status is reread from the exact ledger and current canonical result record. Missing, damaged, mismatched, expired or changing evidence becomes unverified. The fixed public outcome contains only contract, verification, operation, state, result, committed time and replay boolean. It excludes tokens, IDs, URLs, guest identities, order payloads and Reward credentials. The shared receipt normalizer rejects malformed or mismatched outcome fields instead of reducing them to generic success.

| Recorded operation | What Chat can establish |
|---|---|
| Booking create | The booking action was recorded; payment has its own status. |
| Booking reschedule | The reschedule was recorded; this is not a fresh availability or payment check. |
| Booking cancel | Cancellation was recorded; a refund is separate. |
| Commerce checkout | Checkout started and an order was recorded; payment completion is unverified. |
| Commerce refund request | The request was submitted; funds being refunded is a separate outcome. |
| Campaign participation | Enrollment was recorded; verification and reward delivery may remain pending. |
| Reward claim handoff | The handoff was recorded; merchant redemption is still required. |
| Reward transfer | The exact transfer was recorded as sent; viewing and redemption are separate. |

Prepared and executing states remain pending. Follow-up diagnosis and saved Chat history preserve those distinctions. Generic unrelated follow-ups cannot select an old Profile return. A lost browser response can be reconciled from the existing server ledger without executing the action again.

The canonical action replay serializer now retains the allowlisted operation-specific Campaign and Reward fields that its old booking-only projection lost. Newly prepared action timestamps use explicit UTC to match ledger expiry/commit semantics even when the MySQL session timezone differs. Existing transactional authority and public confirmations remain in their original services.

## Scoped acceptance score

The automated acceptance target is **10/10**. All ten gates must pass; local checks alone cannot complete the release gate.

1. Five real capability bindings and strict broker plans, bringing the managed legacy inventory to 29/29.
2. Current owner, account, Agent, exact conversation and canonical workspace/Profile permissions, including changes during reads.
3. Pure capability and record inspection with no schema, tracking, session-selection or native action side effects.
4. Exact release/workspace selection, bounded output, complete counts, meaningful dates and workflow-number isolation.
5. Canonical release creation matching the saved user request, atomic receipt/read-back and safe same-turn replay.
6. Explicit-only Profile handoffs with source binding, expiry, single-use continuation and full-response reauthorization.
7. Actual native API validation before dispatch and exact prepared/committed ledger proof after the response.
8. Honest pending, recorded and unverified business outcomes, safe operation-specific replay and no private proof leakage.
9. Real typed, streaming voice and legacy Chat journeys, native HTTP/browser transport, retained module contracts and MySQL on PHP 8.1/8.3.
10. Green PR checks, green postmerge workflows and byte-for-byte verification of the production ZIP from the merge commit.

Evidence is in the focused release/read/outcome PHP suites, `tests/agent-release-profile-chat-api.mjs`, `tests/profile-webmcp-native-outcomes-v1.mjs`, shared receipt tests and the retained 833-case capability endpoint journey. The Cloud Hosting workflow runs the three new storage suites in isolated MySQL databases. Recovery Baseline runs the SQLite, production endpoint, browser runtime and retained compatibility checks.

No new database table or migration is introduced. This section uses existing release, Chat, Profile and native-action storage. Missing storage is reported rather than repaired during a read. Live customer payment, external publication, physical microphones and the actual paid OpenAI image-provider call remain separate deployment acceptance. Photos/media and image generation are retained from S9; Mission change alerts remain on hold. HomeServer source and Stonefellow playback source are outside this change.
