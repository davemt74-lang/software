# MC1 — Music Chat and saved playlists

## Scope and source

The user approved this build on 2026-11-27 after the read-only audit of main
`201738f46cb1bfb6c0b0365dff8fb453630f08e6`. Implementation begins from refreshed
main `bd8a62229164f03d13966a3fef0496ab7395fa40`, preserving the intervening phone
and Canvas releases. The implementation PR owns exact final candidate, test,
merge and package qualification.

The Media/Entertainment specialist has exactly two audio discovery sources:
**VP3 media/audio** and **YouTube**. Stoneward distributes the VP3 catalog and is
not a third source in this contract. Its existing integration remains compatible.

This release implements precise music requests, private saved playlists and
coordinated playback. Full searchable personal history and improved preference
learning are separate following releases. Existing personal history, native
listening receipts, YouTube play/watch receipts, artist totals and financial
states retain their distinct meanings.

## User contract

| Request | Required outcome |
| --- | --- |
| Find/show one named song | One verified result, no audio start |
| Suggest one song with a mood | One result; mood affects selection, not count |
| Play one song | That exact result and a current playback action |
| Show a stated number of songs | That many eligible results or an explicit shortfall |
| Specify VP3 or YouTube | Restrict both search and playback to that source |
| Omit the source | Consider both sources, then apply one global count |
| Create a playlist | Save a named private playlist with stable identity |
| Create and play a playlist | Save once, then start its ordered selection |
| Add this/the second song to my driving playlist | Add the exact displayed selection to that existing private list, without starting audio |
| Play the second one / that one | Resolve the prior stored selection, not a fresh search |
| Answer “the second one” after a music clarification | Continue from the same displayed selection |
| Add this to the queue | Append the exact selection without interrupting the current song |
| Play this next | Insert the selection after the active song; an idle queue stays ready until Play |
| Reopen Chat | Restore cards without repeating playback or creating another playlist |

General discussion, questions about how playlists work and negated instructions
must not mutate playlists or start audio. An unavailable exact title is not
replaced silently with an unrelated recommendation. Ambiguous titles or versions
are resolved explicitly. A playlist is different from an unsaved queue.

## Shared request and result

The production protocol is `vp3_music_chat_v1`. The server chooses intent,
requested count, source restriction, selection and playback action. A single
result count is enforced after source selection and again in the returned card.
Legacy answer extraction must not append four or seven additional songs to a
handled music request.

Results retain owner, Agent and conversation scope; source-tagged stable item
identities; current display metadata; revision; and coverage. They use canonical
native catalog access rather than the old tracks-only recommendation query.
YouTube search and detail checks reuse current connection, consent, quota,
playability and metadata validation. Provider text remains untrusted data.

The bounded selection contains at most 25 items. A provider returning fewer
eligible matches produces an honest shortfall; neither a bounded search nor a
provider error proves that no other music exists. An explicitly requested source
does not silently fall back to the other source.

An ambiguous “that one” does not select the first of several displayed songs.
The clarification keeps an opaque reference to those results, allowing the next
numbered answer to resolve the same selection. The clarification emits neither
a replacement music card nor a playback action. An unrelated assistant reply
still ends that selection continuity; the reference cannot cross account,
Agent or conversation boundaries.

Typed and voice routes use the shared request and binding service. A repeated
voice transport response rehydrates the stored selection under current access
before displaying or speaking it. Reuse of a transport identity for a different
music request or conversation is rejected. A replay contains no automatic
playback or queue action and cannot repeat a playlist mutation. The voice
transport cache retains an opaque music reference rather than stale song
metadata or an executable action.

## Durable private playlists

Additive owner-private result, playlist and request-receipt storage supplements
the existing native and YouTube lists. It does not change old public/members
playlist visibility or rewrite their identities. Ordered items keep their
source and source identity; YouTube video IDs never become native track IDs.

Playlist creation is idempotent for the same owner request and payload. A
conflicting replay fails. Stable item identities and revision checks protect
rename, reorder, removal, addition and deletion from stale overwrites. API
mutations accept stored result/playlist references, not arbitrary client-sent
track metadata or playback URLs.

The creation receipt retains an immutable hash of the selected result and
original title. An exact creation retry returns the same list with its current
edits, including a later rename. Reusing that key with a different creation
payload fails; an already-saved selection cannot silently become a second
creation request.

Conversational addition accepts one exact prior selection and the expected
revision of an existing target list. Saving the assistant reply and source
receipt, adding through the existing list writer, and linking the result to the
same playlist commit atomically. A stale target, missing target or another
account's target is rejected. A same-request retry cannot add twice. An item
already in the list leaves its revision unchanged; the persisted change receipt
allows the Agent to confirm its presence without claiming a new mutation.

Saved-name matching is case-normalized consistently across database engines.
The spoken suffix “playlist” may be omitted when the name resolves uniquely.
If both “Driving” and “Driving playlist” exist, the Agent requires a saved-list
choice rather than silently selecting one. Personal lists are account-owned and
may be reopened from another authorized private Chat; a transient result remains
bound to its original Agent and conversation.

YouTube metadata and playback provenance reuse the existing result cache and
private saved-list adapter. Result binding to the actual saved assistant message
must succeed before the response claims a playlist is ready. Provider metadata
keeps its existing expiry/refresh behavior; durable playlist identity does not
grant permanent access to stale metadata or an unavailable video.

All API operations recheck active account, owner, Agent, conversation and current
source access. Missing schema reports an upgrade requirement without installing
tables during a normal read or chat request.

Remote provider refresh finishes before the response projects metadata from
other sources. A native song revoked during that refresh becomes unavailable
and its metadata is hidden. Read and playback paths recheck the saved list's
revision after provider work so an edit or deletion cannot return an obsolete
selection for playback.

The explicit additive upgrade owns `music_chat_results_v1`,
`music_chat_playlists_v1` and `music_chat_play_actions_v1`. The result's nullable
`playlist_changed` receipt and the playlist's immutable `request_hash` preserve
change and retry evidence without changing the selection revision. A prerelease
table missing these fields is upgraded deliberately; an unknown original
creation hash is left blank instead of inferred from a possibly renamed title.

## Playback

Reuse the current native audio and visible YouTube iframe players. A shared
controller coordinates source ownership; starting one source pauses the other.
The selected source's existing tracker owns actual playback evidence.

The controller supports exact-item play, ordered playlist playback, source
switching and normal transport controls. Mixed queues advance once per real
completion and revalidate the next item. Queue changes, account/Agent/conversation
changes and stale asynchronous responses cannot revive a discarded playback
generation.

The temporary queue supports up to 100 source-tagged entries from displayed
selections or saved playlists. Add to queue appends; Play next inserts after the
current entry. Neither action replaces or restarts the active song. With no
active song, these actions prepare a quiet queue and expose Play. Each entry is
resolved again before it plays. Queue state ends when stopped, when its Chat
context changes or when the page closes; queueing does not save or edit a
playlist. Request claims and client generations prevent a repeated live result
from inserting the same automatic queue operation again.

Only a live accepted play action may start automatically. Restored cards,
response replay, saved-list reads and playlist creation alone remain quiet.
Automatic action claims and current client generation prevent duplicate starts;
manual Play remains available for a deliberate retry. Browser denial shows a
usable Play control. The UI reports ready/starting/playing/blocked/error from
actual player outcomes, not from the Agent's wording.

YouTube keeps its ordinary visible player and controls. This release does not
extract audio, download YouTube media or create an independent streaming source.
No YouTube playback is added to the VP3 native/provider artist-listen ledger.

## Interface

Single-song requests render one compact source-labeled card. Multiple results
and playlists use ordered items, clear counts and a saved identity when present.
Playlist controls act on the actual stored list. Normal document scrolling,
readable desktop/390px layouts and usable touch targets are required.

Native audio tracking, YouTube player receipts, current favorites, existing
playlists, resume and explicit DJ controls remain reusable. MC1 does not replace
the existing players with a second media engine.

## Acceptance score

Each criterion earns one point only when its required behavioral evidence passes.
Implementation alone is not a passed release gate. The PR and release handoff
record the exact final score and candidate; this document defines what is scored.

| ID | Criterion | Required evidence |
| --- | --- | --- |
| MC1-01 | Explicit intent, global result count and source fidelity | Actual parser, negative/ambiguous cases and real response assembly |
| MC1-02 | Canonical catalog and verified YouTube availability | Current access, private/unpublished rows, exact IDs, provider errors and changed metadata |
| MC1-03 | Durable private creation and exact replay | Real SQL writes, rollback, retry/conflict and separate request races |
| MC1-04 | Playlist lifecycle and mixed-source order | Reopen, rename, reorder, conversational add/remove/delete, duplicate change receipts, revisions and provider refresh |
| MC1-05 | Current owner/Agent/conversation authority | Actual HTTP, CSRF, cross-account/Agent isolation and revocation |
| MC1-06 | One active player and correct advancement | VP3→YouTube→VP3, append/play-next, quiet idle queue, duplicate ended events, pause/stop and stale generation |
| MC1-07 | Actual playback outcomes and source receipts | Successful/blocked/failed starts; no search/create counted as listening |
| MC1-08 | Typed/voice parity and safe restoration | Shared runtime, clarification continuity, source selection follow-ups, current-access voice replay and history reload |
| MC1-09 | Database/browser acceptance and mobile usability | SQLite, native MySQL, real PHP HTTP, Chromium/WebKit, desktop/phone screenshots |
| MC1-10 | Exact release qualification | All applicable final PR/main workflows, tested merge tree, full archive and manifest verification |

Tests may explicitly replace external Google responses and the iframe transport
while running production application handlers, storage and browser code. Such
fixtures prove application behavior, not a live Google account, host deployment
or physical iPhone/Android playback. Those observations remain separately stated.

## Recovery review and local evidence

The initial build was recovered as 33 staged files before an implementation
branch or PR reached GitHub. That recovered work was reviewed against current
main and continued in an isolated checkout. Its earlier local passes were not
treated as a completed release.

The storage review reproduced and corrected three issues: conflicting manual
creation retries were accepted, manual playback could return an obsolete list
revision after provider refresh, and a native title could remain visible after
access was revoked during a YouTube refresh. It also completed conversational
addition, explicit clarification continuation and consistent saved-name
resolution. The accompanying tests exercise these cases through production
storage and request handlers.

As of the recovery review on 2026-11-27, the library suite passed 119 checks on
SQLite and 119 checks through native PDO prepared statements on MariaDB
10.11.19. The actual PHP/SQLite HTTP suite passed 405 assertions, including Chat
creation/addition and replay. Separate processes exercise concurrent creation,
competing edits, automatic claims and repeated additions. The MariaDB result
does not substitute for the MySQL 8.0 CI gate. These are local review results;
final Chromium/WebKit, typed/voice, repository CI, merged-source and package
qualification remain owned by the implementation PR and release handoff.

## Rollout and following work

Use [the deployment note](../MUSIC-CHAT-DEPLOY.txt). The upgrade is additive and
must be run deliberately through the normal upgrade path or documented CLI.
Back up code, database, configuration, private keys and uploaded media together.
Do not claim deployment or a production migration from automated fixture results.

The next personal-history release will provide filtered/paged two-source history
and shared Agent retrieval with explicit coverage. A later preferences release
will distinguish explicit taste, deliberate skips, failures and deferred offers.
Neither is silently bundled into MC1 or claimed completed by this score.
