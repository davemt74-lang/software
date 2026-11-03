# S8A — Private app share history in Chat

The existing private-share Chat handler called the full lifecycle service for a read. That service initialized schema, synchronized HomeServer installation observations and swallowed synchronization failures. Its app-key-only association could then describe an older installation observation, or a different sender's package with the same key, as currently installed. A database failure could fall through to general Chat without an explicit failed-read result.

S8A routes explicit private-share reads through `apps.shares.read` and the shared model-selected dispatcher used by typed, streaming voice and legacy Chat. The strict `private_share_read` plan accepts `view` (`all`, `received` or `sent`) and an optional literal app title/key filter of at most 160 characters. Discovery explains readiness without retrieving share records. Share creation, redemption, reissue and revocation retain their existing services and approval surfaces.

## Evidence and authority

The adapter reads existing Cloud share tables without creating schema, synchronizing observations or contacting HomeServer. It applies owner and requested-view filters in SQL before a bounded result limit. Missing storage produces an upgrade-required receipt; verified empty results and failed reads remain separate outcomes.

Current account access, Agent ownership and conversation assignment are checked before retrieval and again before returning any record content. Received and sent share histories never permit selecting another owner. The returned projection omits grant codes, recipient email addresses, raw packages, file paths and lifecycle payloads.

Installation observations are associated only with the exact share, sender, recipient, app key, package and publisher provenance. Their timestamps are shown as saved history. An observation does not establish current installation, and a share's accepted, expired or revoked state does not establish installation or removal. Update offers likewise remain recorded offers requiring the existing review flow.

## Scoped acceptance gates

The automated target is **10/10**, completed only after all of these pass:

1. Strict shared plans and executable capability inventory.
2. Read-only discovery and no implicit schema, sync or remote operations.
3. Current owner, account, Agent and conversation authority.
4. Exact share and package provenance for historical observations.
5. Pending, accepted, expired and revoked share-state accuracy.
6. Literal filtering before the bounded list limit.
7. Private-field minimization and safe failed-read receipts.
8. Storage and authority regressions, including changes during retrieval.
9. Actual typed, streaming voice and legacy Chat endpoint integration, with existing private-sharing lifecycle regressions retained.
10. Green PR workflows and verification of the production ZIP from the merged commit.

Live HomeServer installation and distribution acceptance remain separate. S8A intentionally certifies the Chat read path and saved evidence, not every private-sharing lifecycle mutation. It adds one assessed legacy handler (22 of 29) and one capability (50 total). No new database migration is required for installations with the existing private-share schema.

Local evidence: 93 domain cases on real SQLite, 428 cases through production Chat endpoints, and the retained private distribution and trusted-share lifecycle contracts. The domain suite also runs against an isolated MySQL database with the production case-insensitive collation in the existing Cloud Hosting workflow. Tests cover exact provenance after SQL matching, account/Agent/conversation changes during retrieval, read-only SQL tripwires and capability/tutorial/build questions that must not retrieve records.

## S8B — VRSS feeds and recorded action outcomes

The prior feed handler defaulted omitted lists/counts to empty/zero, returned the raw provider payload and exception text, and performed mutation-preview reads without rechecking the current Agent or account afterward. Its generic System App completion check treated any VRSS action envelope containing a `result` as success. Queued refreshes and pending social requests could therefore be presented as completed. Only generic booleans survived confirmation replay, and the Chat card repeated the completion claim.

S8B adds `feeds.vrss.read` and `feeds.vrss.manage` to the existing inventory. The strict shared `vrss_read` operation accepts subscriptions, posts, connections, likes, reposts, comments, conversations and thread views. The four post-specific views require a canonical HTTPS post URL; only comments and thread accept a bounded returned cursor. The manage capability describes the existing System App preparation and explicit confirmation service; it adds no model-selected arbitrary write executor.

### Read evidence and authority

Every allowlisted read checks current owner/account/Agent authority before and after the HomeServer call, including the current conversation assignment when a conversation is present. Ordinary reads preserve the existing bundled Feed access model and do not add a Cloud acquisition requirement. Mutation preflights additionally require the existing owned Cloud app, exact catalog identity and durable owner/Agent/conversation scope.

Each view validates its own returned fields. Missing data fails; only an actual empty list becomes an empty-page receipt. Author counts can be explicitly unavailable without becoming zero. A paged delivery list is not used as the total pending count. Source page limits and safe Chat display limits apply separately. Remote text is rendered as bounded literal evidence; raw payloads, diagnostics, credentials and native paths do not enter replies or saved read receipts. Read paths perform no SQL writes, schema changes, refreshes or social mutations.

Moderation previews bind the exact requested comment, its full bounded body, strict permission flag and current revision. Conversation changes bind exact post, watch flags and revision. Only a verified complete watch list can establish a new watch at revision zero. Missing/malformed state cannot manufacture a default state. The existing S7 confirmation code, ownership checks, execution lease and consumed-outcome protections remain authoritative.

### Action outcomes and Chat cards

Wire contracts were checked against HomeServer source at `davemt74-lang/otro` commit `e15305a0bf947a85ae9eadfecc23d634ead18426`, especially `app/services/vrss.py`, `vrss_social.py`, `vrss_interactions.py`, `vrss_reposts.py`, `vrss_comments.py` and `vrss_conversations.py`. HomeServer source was read but not changed or published.

| Existing action | Evidence Chat reports |
|---|---|
| Subscribe | Subscription saved; imported content is not assumed. |
| Refresh feeds | Number of feed checks queued; refresh results remain pending. |
| Follow profile | Pending acceptance or an explicitly accepted relationship. |
| Unfollow, block, unblock, remove follower | Confirmed local relationship change; remote delivery is not inferred. |
| Like / unlike | Local preference, pending delivery or the returned delivered state. |
| Repost / undo repost | Local preference and pending delivery; publication requires the explicit verified publication state. |
| Conversation settings | Exact local watch/mute settings and incremented revision. |
| Comment / reply | Locally approved own-post comment or pending remote delivery. |
| Retract comment | Local retraction recorded; the shared response cannot prove remote removal. |
| Moderate comment | Exact approved/hidden decision and incremented revision. |

Only a versioned allowlist of action/phase, booleans and bounded counts/revisions is persisted. Returned object identities, requested flags and revisions are compared with the saved request where the wire contract supplies them. URLs, comment bodies, actor/viewer identities and provider errors stay out of the saved outcome projection. The acknowledged phase is separate from final evidence.

Chat replies, action cards and next-turn diagnosis use the same validated projection. Cards show Queued, Pending, Saved locally or Verified, with no new confirmation button after consumption. The normalized tool receipt retains the safe phase so asking whether an action finished cannot turn a queued/pending response into a generic completion claim. Repeating a confirmation reads the saved result and reports that it did not execute again. Older completed rows containing only the generic success flag are displayed as unverified and remain consumed, including when their historical Chat receipt is inspected. Malformed or lost outcomes never authorize a second execution of the same confirmation.

### S8B scoped acceptance gates

The automated target is **10/10**, completed only after these gates pass:

1. Shared capability bindings and strict read-plan allowlists.
2. Eight operation-specific projections, page bounds and empty/unavailable/error distinctions.
3. Current owner/account/Agent/conversation authority before and after remote retrieval.
4. No read writes, DDL, implicit refresh or unintended action preparation.
5. Exact moderation and conversation preflight identity, permission, flags and revision.
6. Source-grounded projection of all 13 existing mutation actions.
7. Safe durable outcome replay, legacy-result downgrade and consumed uncertain outcomes.
8. Typed, streaming voice and legacy Chat replies/cards, including history reload.
9. Retained System App approval regressions and real MySQL execution on supported PHP versions.
10. Green PR workflows and byte-for-byte verification of the merged production ZIP.

Evidence lives in `tests/agent-vrss-read-v1.php`, `tests/agent-vrss-outcomes-v1.php`, the expanded production `tests/agent-capability-chat-api.mjs`, and the strengthened canonical `tests/system-apps-v160-mysql.php`. The isolated read suite also runs against real MySQL in the existing PHP 8.1/8.3 Cloud Hosting workflow. Live paired HomeServer/network acceptance remains a separate deployment check. No new database migration is required. Mission change alerts remain on hold.

Local evidence: **226** feed read/authority/preflight cases, **260** mutation outcome cases, **78** shared receipt/diagnosis cases, **587** production Chat endpoint cases, **70** retained System App operation cases and **46** participant endpoint regressions. Outcome/card replay is exercised through actual confirmation persistence and history loading. CI additionally verifies the canonical MySQL transaction/lease behavior and the same isolated read suite on PHP 8.1 and 8.3.
