# MC2 — Personal listening history and Agent recall

## Scope and source

The user approved this build on 2026-11-27 after MC1 was released in PR #957
at ab313e01beae3433fcc9e27dd1f239df33801335. MC2 starts from refreshed main
8f39d8e2c31620567b8fe68c0a0a97157c92eb60, including the cross-page Phone,
connected-phone Inbox, Canvas editor, and MC1 fixture follow-ups.

This is an implementation contract. [Implementation PR #962](https://github.com/davemt74-lang/software/pull/962)
owns the exact candidate, final score, workflow results, merged source, and official Cloud ZIP.
Code written or local fixtures passing alone do not complete the release.

There are exactly two sources: VP3 native media and YouTube playback observed
inside VP3. Stoneward distributes the VP3 catalog and is not a third personal
history source. Anonymous artist-site sessions, artist/workspace analytics,
qualified-listen reporting, and financial states remain distinct from the
signed-in owner's personal history.

## Existing foundations and the gap

Native listening already records owner sessions and source-specific measurement.
The existing recent UI collapses to the latest session per track, while the
existing Agent context is bounded to thirty recent sessions. YouTube has a
separate Played in VP3 reader, optional watch/position data, and provider expiry.

MC2 supplies one authorized reader for the UI and explicit Agent history queries.
It reads the existing ledgers; it does not create another listening ledger,
reconstruct missing receipts, convert a search into a listen, or silently extend
retention. Repeated plays remain individual entries.

## User contract

| Request or action | Outcome |
| --- | --- |
| Show my listening history | A quiet private view of retained VP3 and YouTube entries with source/evidence labels |
| What did I listen to yesterday? | The same reader, scoped to the displayed local date range |
| What did I listen to last night? | An explicit local overnight time window, not an unlabeled whole-day approximation |
| Show my YouTube plays from last week | Explicit source and date constraints with honest retained coverage |
| Find a song or artist in my history | Filter authorized current metadata and retain individual matching plays |
| Older results | Continue a stable bounded page using the same query and snapshot boundary |
| Play the third one | Select the exact third receipt in the bound displayed page, then use current MC1 playback resolution |
| Resume that item | Use the exact verified historical position only when its current source/asset remains valid |
| Add that song to Driving | Add one exact selected source item to the existing private list through MC1 |
| Reopen Chat or repeat a voice response | Rehydrate authorized history without replaying audio or repeating a mutation |

A history response must not imply that every recorded start proves measured
listening. Missing metadata, revoked access, source optout, partial measurement,
and expired records have explicit meanings. A bounded page does not establish
that the owner has no older retained history.

## Shared reader

The shared reader accepts a source restriction, text query, date range,
validated IANA timezone, page size and opaque continuation cursor. Date filters
are inclusive local calendar dates converted to explicit UTC query boundaries.
Exact time windows used by natural-language queries are validated separately
and cannot conflict with date-only filters. Their `from_at` and `until_at`
bounds use whole-second RFC3339 timestamps with an explicit offset; the start
is inclusive and the end is exclusive. The displayed range and timezone must
match the actual query.

Default last-night interpretation is the previous local day's 18:00 through
the current local day's 06:00, limited by the query's observed snapshot when the
night is still in progress. Relative dates and daylight-saving boundaries must
be tested with a deterministic clock; the implementation must show the resolved
window instead of claiming that an ambiguous phrase has a universal definition.

The result contains individual source-tagged entry references, current display
metadata where authorized, UTC and local timestamps, measured time when known,
an evidence label, play/resume availability, normalized filters, an as-of boundary,
coverage, and continuation information. Text matching must not expose metadata
from currently unauthorized tracks or source contexts.

Read authority and Play availability are separate. A currently authorized owner
preview can retain its title in personal history while Play remains unavailable
under MC1's published-media rules. A revoked view grant removes that metadata.
Long filtered scans revalidate their final page against current account, Agent
and source settings before returning it.

Native queries freeze a session-ID watermark. YouTube pagination uses a strict
server-time boundary so new same-second arrivals cannot shift later pages.
Current measurement and metadata may change after that boundary; the response
must describe this distinction. Pagination must not silently stop at an arbitrary
materialization cap or report no more results because an internal scan budget
was exhausted.

Entry references are opaque locators, not bearer permissions. Every query and
resolution rechecks the current account, owner, Agent, private conversation,
history policy and source authority. Continuation tokens are scoped to the
normalized query and current session and expire deliberately; a stale token asks
for a refreshed query. A changed source/date/query cannot reuse an old cursor.

## Source evidence and retention

Native versioned measurements and older unversioned sessions keep their existing
meaning. A start with no measured time is labeled accordingly. The native session
receipt lifetime is not a history-retention limit.

Older native start timestamps have no stored timezone. The reader treats those
database timestamps as UTC, consistently with the existing analytics path, and
then formats them in the chosen local timezone. It does not infer a historical
timezone that the original receipt did not record.

YouTube history represents observations inside VP3. Ordinary playback history
depends on valid source-result records; saved-playlist receipts have the existing
seven-day maintenance window. An expired provider cache must not be copied into
permanent history or revived from a transcript.

Pause-history preserves the existing retained-start behavior while stopping
capture; the interface reports capture disabled and does not offer an
unauthorized resume position. A full source/consent optout hides YouTube metadata.
Cross-Agent source records retain current history-policy checks for both the
source and reading Agent. MC1's YouTube child-playlist receipts must be covered
without requiring obsolete legacy transcript markers.

YouTube entries must also fall within the current consent period. Accepting a
new policy does not revive retained plays from before that acceptance. The
current consent timestamp is checked again on exact restoration and selection.

There is no implicit lifetime YouTube-history promise and no personal history
claim for anonymous Stoneward visitors. Retention changes are separate explicit
work, not a side effect of this read interface.

## Exact Chat binding and actions

A complete history page remains a history-specific ordered projection. MC1's
song normalization deduplicates song identities, so it cannot represent repeated
history entries or their ordinals directly.

Explicit history/filter/page actions bind ordered opaque receipt references and
the normalized query to an actual private assistant turn. This binding stores no
provider titles, thumbnails or watch metadata. A page revision protects against
stale browser responses; a page change cannot silently rebind an unrelated newer
assistant selection.

Read-only restoration rehydrates those exact references under current authority.
Expired or unavailable entries do not shift the identities of the remaining
ordinal selections. Filters and pagination that change the visible page update
the binding deliberately; autoloading or polling must not create Chat turns.

Only an explicit selection is converted into one MC1 result. The resulting item
uses MC1's existing request identity, assistant binding, current source checks,
private-list mutation path, coordinated player and canonical receipts. Repeat
requests cannot duplicate Chat actions, playlist additions or automatic starts.

Historical Resume uses an authoritative stored reference and verified position.
Native resume additionally requires the recorded audio asset to match the current
asset. An old position must not be applied to replacement audio. A legacy record
may support Play while lacking sufficient evidence for Resume. YouTube resume
uses the selected receipt's authorized position rather than a generic latest
position cache. Normal MC1 Play continues to start at the beginning.

Typed and voice routes share query and selection behavior. Voice transport
replays retain opaque references and rehydrate current access; they remain quiet.
Provider text is untrusted data and is never interpreted as instructions.

## Interface

Extend the existing media experience and ordinary Chat. Source, date and text
filters, current range/timezone, measurement labels, coverage and Older/refresh
controls must be understandable without exposing internal ledger names.
History remains owner-private and follows the selected Agent's data permissions.

Use normal page scrolling, readable 320px/390px and desktop layouts, usable touch
controls, and the current Chat composer. A tall history page must remain reachable
above the fixed composer. History views do not install a new media player.

## Acceptance score

Each criterion earns one point only after its required evidence passes.

| ID | Criterion | Required evidence |
| --- | --- | --- |
| MC2-01 | One two-source history contract | Existing native and YouTube receipts, MC1 child paths, ordered repeated plays |
| MC2-02 | Complete bounded retrieval | Stable pagination beyond former recent caps, equal timestamps and concurrent new plays |
| MC2-03 | Exact filters and local time | Source/text/date/time filters, timezone and daylight-saving boundaries, invalid input |
| MC2-04 | Honest evidence and retained coverage | Starts versus measured time, legacy data, expiry, optout and unavailable metadata |
| MC2-05 | Current private authority | Real HTTP, CSRF, owner/Agent/Chat isolation, source policy and current revocation |
| MC2-06 | Shared Agent and UI answers | Both surfaces use the same reader, counts/filters/coverage and bound result identities |
| MC2-07 | Exact selected actions | Historical Play/Resume/Add, current asset and position checks, replay/idempotency and canonical receipts |
| MC2-08 | Typed/voice and safe restoration | Exact context, stale-page rejection, current-access rehydration and quiet repeated/restored responses |
| MC2-09 | Database/browser/mobile acceptance | SQLite, native MySQL, PHP compatibility, actual HTTP, Chromium/WebKit and visual review |
| MC2-10 | Exact release qualification | All applicable PR/main workflows, tested merge tree, full official ZIP and generated manifest |

External Google responses and iframe transport may be explicit fixtures while
running production handlers, SQL, HTTP and browser code. Those fixtures do not
certify a live account, physical phone, deployed host or production migration.

## Rollout and following work

Use docs/MUSIC-HISTORY-DEPLOY.txt. MC2 reuses the established MC1 schema and Chat
context; normal history reads perform no DDL. Current MC1/YouTube prerequisites
must already be installed through the explicit setup/upgrade path.

MC3 will build preference learning and better suggestions from distinct explicit
taste, actual listening, deliberate skips, failures and deferred offers. MC2
supplies factual history retrieval without claiming those later behaviors.
