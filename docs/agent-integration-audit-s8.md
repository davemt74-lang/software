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

## Next: S8B — VRSS feeds

Feed operations already exist and use the canonical System App prepare/confirm path for changes. S8B will verify operation-specific read projections and current authority, distinguish missing data from empty feeds, remove raw provider errors and unbounded payloads from Chat, and retain bound confirmations and uncertain-outcome protection. Mission change alerts remain on hold.
