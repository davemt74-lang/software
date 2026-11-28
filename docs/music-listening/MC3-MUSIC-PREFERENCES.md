# MC3 — Music preferences, suggestions and shared Agent context

## Scope and release record

The user requested the next build after MC2 was delivered. MC2 merged in
[PR #962](https://github.com/davemt74-lang/software/pull/962) at
`d8dae0a9024c93f431f3366b96bbd81608cd5e1b`. MC3 starts from main
`8e9f23fe4ed1006191fe0c61b2e671e8e60aa804`, preserving the subsequent Gmail
capture release. The implementation PR owns the exact final candidate, CI,
merge, score and original official full Cloud ZIP verification. A local pass
does not complete release qualification.

MC3 adds explicit private choices and evidence-based music suggestions for
exactly two sources: native VP3 media and YouTube observed or explicitly
searched inside VP3. Stoneward distributes the VP3 catalog. It is not a third
preference source. Artist analytics, anonymous visitor listening and financial
reporting remain separate from an owner's personal taste.

## Owner experience

- Like, Dislike and Clear appear beside current MC1 results, saved private
  playlist items and MC2 history entries. The exact displayed source identity
  is resolved again before a choice is accepted.
- Music preferences in the existing Player opens a normal-scrolling list of
  saved choices, including safe unavailable placeholders that can still be
  cleared. Pagination is through current choices, not a claim of chronological
  order. Refresh includes choices added before an existing page cursor.
- Standalone typed and voice instructions such as “I like the second song,”
  “I don't like this song,” and “Clear my preference for the first song” use
  the same writer. An ambiguous “this” asks for the displayed number, retaining
  its original music/history context for the next answer.
- “Show my music preferences” displays a fresh private view. Reopening Chat or
  repeating a voice response reads current state and does not repeat a write.
- A Like boosts a matching recommendation; a Dislike excludes that item from
  recommendations. A new exact named request or explicit selected playlist
  continues to express the owner's current choice, including a disliked item.

These are item-level choices. A dislike of one recording does not create an
artist, genre, mood, sensitive trait or whole-source dislike. Clear removes the
explicit MC3 choice; it does not erase independently retained listening history
or change an existing Favorite action.

## Distinct evidence

| Signal | Meaning and use |
| --- | --- |
| Explicit Like / Dislike | A stated owner choice, stronger than soft listening evidence |
| Clear | Neutralizes the previous explicit choice; an opaque revision marker prevents a delayed request from restoring it |
| Recorded start | A factual start, not a demonstrated preference or proof of audibility |
| Measured listening | Existing accepted native or enabled YouTube watch receipts; a measured play requires at least 10 seconds for this ranking signal |
| Repeated measured plays | A bounded positive ranking signal, described as repeated listening rather than an explicit Like |
| Deliberate Next | A weak contextual signal from a user-triggered coordinated-player Next with an acknowledged canonical play; never an explicit Dislike |
| Declined/deferred suggestion | Existing offer/cooldown state, not a dislike of its item or artist |
| Failed, blocked, unplayed, paused, stopped or automatically ended playback | Does not create a deliberate-skip or dislike signal |

The former Player heuristic that treated every session shorter than ten seconds
as a skip is removed. This prevents failed starts from acquiring a negative
taste score.

The shared snapshot examines at most the latest 200 retained candidate receipts
from the last 30 days and declares truncation. It uses the existing MC2 projection
for current metadata, source Agent policy and consent. Native legacy reported
time remains history, but is not relabeled verified measurement. Repeated rows
remain separate evidence. Main Agent context selects at most 12 current items
and separately declares that smaller context limit.

History expiry or disabled watch measurement reduces available evidence. No
missing data is converted into a zero-duration dislike. This is not a lifetime
YouTube account history or a production-size recommendation benchmark.

## Shared recommendation and Agent integration

`vp3_music_preferences_snapshot_v1` is the common read model. New descriptive
MC1 requests retain query relevance, explicit source restrictions and global
song counts, then apply the current preference and bounded listening signals.
Exact named/numbered requests and explicit private playlist order are preserved.

Media/Entertainment reports, its existing coordination cards, Top of Mind,
Brainstorming/opportunity context and the Main Agent use the same current
evidence. A suggested question can mention the exact saved Like or repeated
measured listening that justified it. Disliked items do not initiate a new
music/related-artist suggestion. Existing facts in history remain facts.

Preference revisions participate in an offered item's revision so an old offer
cannot silently ignore a changed choice. The original native playback revision
is retained separately for the canonical player resolver. Existing timing,
cooldown, budget, source consent and owner-reply requirements continue to govern
the loop. MC3 introduces no competing worker and no autonomous playback grant.

The ordinary snapshot reads current existing caches only. MC1's existing server
provider boundary can supply a separate envelope of freshly verified YouTube
items before those new results are bound to Chat. Exact source IDs, video shape,
expiry and current source settings are checked. No preference HTTP endpoint
accepts provider items, titles, ranking scores or this internal envelope.

## Private storage and authority

The explicit setup/Database Upgrade path installs three additive tables:

- `music_preferences_v1`: owner, source, hashed identity, optional native track
  ID, choice, revision, opaque origin references and consent epoch.
- `music_preference_requests_v1`: frozen request hashes and exact Chat query
  hashes, owner/Agent/conversation scope, and the resulting preference reference.
- `music_deliberate_skips_v1`: one weak Next signal per canonical owner/receipt,
  with hashed item identity and current consent epoch.

No provider title, thumbnail, description, audio URL, watch duration or raw
YouTube video ID is copied into these tables. Source metadata is projected
freshly. A consent renewal does not revive an older YouTube choice. Cleared or
prior-consent choices alone do not seed the Agent's spontaneous context.

The existing private owner/Agent boundary and `knowledge/playback-history`
policy apply. A positive conversation ID must match the authenticated account;
authorized background projections use the existing private owner scope with
conversation ID zero. Participant, shared and public-profile contexts cannot
access the private preference reader or writer.

An exact item action is bound to a current result/revision/index, private
playlist/revision/index, or history message/revision/entry. A saved opaque
preference reference permits its owner to clear a choice even when its source
metadata is unavailable; it does not authorize a new Like or Dislike for an
unavailable item. No read installs schema or refreshes a provider.

Mutations use a shared owner lock, optimistic revision comparison and frozen
request identity. Replaying an old successful Like after a later Dislike or
Clear returns current state and cannot restore the old choice. Reusing a request
identity for different input conflicts. Multirow reads reproject the full page
and recheck scope/source settings before returning it.

## Deliberate Next boundary

Native and YouTube controllers expose only their acknowledged receipt locator,
never their bearer/session token. MC1 freezes the exact selection and locator
before an explicit Next tears down the old source. Automatic advancement,
Previous, Stop, Pause, failures and blocked starts do not use this path.

The server resolves the receipt through MC2 and verifies that it matches the
selected source/item and current owner authority. Native receipts require a
fresh accepted event within 90 seconds and no natural end; YouTube requires a
retained play within six hours and enabled capture/current consent. An
unacknowledged, expired or ineligible play produces no new evidence. Other
player controls without this exact acknowledged path are not retroactively
classified as skips. A duplicate receipt cannot add another skip.

## Acceptance criteria

Each criterion earns one point only after its evidence passes.

| ID | Criterion | Required evidence |
| --- | --- | --- |
| MC3-01 | Exact explicit two-source choices | Current result/history/private playlist bindings; Like, Dislike, Clear and unavailable-choice removal |
| MC3-02 | Private current authority | Owner/Agent/Chat and source-policy isolation, CSRF, consent renewal, expiry and mid-read revocation |
| MC3-03 | Honest bounded learning evidence | Starts versus measured/reported time, repeats, 30-day/200-receipt coverage and context truncation |
| MC3-04 | Deliberate Next only | Canonical native/YouTube acknowledgements, exact source, deduplication, freshness, capture pause and all non-skip exclusions |
| MC3-05 | Relevant controllable recommendations | Explicit choices outrank weak evidence; current source/query/count fidelity; exact requests and playlist order preserved |
| MC3-06 | Shared specialist/Agent context | Same reader in reports, coordination, Brainstorming and Chat; reasons, changed-choice invalidation and canonical playback handoff |
| MC3-07 | Retry and concurrent mutation safety | Lost-response retry, changed input, current-state replay after Clear, real SQL races and rollback |
| MC3-08 | Typed/voice and quiet restoration | Exact numbered feedback, ambiguity follow-through, opaque persisted/cache references and no replayed mutation/audio |
| MC3-09 | Runtime and responsive interface | PHP 8.1/8.3, SQLite/native MySQL, actual HTTP, Chromium/WebKit and desktop/320px/390px visual review |
| MC3-10 | Exact release qualification | All applicable PR/main checks, actual tested merge tree, original official archive and complete generated manifest |

Automated fixtures replace external Google/iframe transport and speech hardware.
They exercise production PHP, SQL, HTTP and browser controllers. Live Google
account behavior, physical phones, deployed hosting and production upgrades
remain separately observed installation work.

## Deployment

Follow [the deployment note](../MUSIC-PREFERENCES-DEPLOY.txt). Install the full
package, preserve configuration/private data/uploads, and run Database Upgrade
once. That explicit upgrade also retains the included Phone, Canvas and Gmail
release requirements. MC3 does not deploy a live host or run a production
migration as part of building its ZIP.
