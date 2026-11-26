# OB1 supervision and Brainstorming

## Product contract

OB1 supervises objectives, priorities, assignments, verification and follow-through.
Brainstorming is a separate specialist that receives permitted briefs from every
specialist and returns useful ideas. Top of Mind projects the current agenda and
verified work; opening a status view never runs a model or starts a worker.

Images, playable playlists and small functional Canvas apps are useful outputs
even when the account has little history. Available context for a particular idea,
rather than account age, determines how specific the idea can be. A missing
connection or empty specialist must not become an invented personal preference.
OB1 and Brainstorming use the existing job engine, source checks, model budgets,
delivery controls and user permissions. A high-priority proposal may reach Agent
Chat through its canonical delivery path; routine progress remains in Top of Mind.

## Section 1: distinct roles

Implemented on the existing Initiative runtime. Saved general assessments and
generic coordination work are attributed to `ob1`; `brainstorm` means the creative
specialist. Existing rows and private receipt fields remain compatible. Canvas,
Research and Missions assessments retain their own work identity. No second queue
or storage schema is introduced.

The orb, specialist rail, Activity Hub and debugger use separate names. OB1's work
cannot remove a live Brainstorming entry. An explicitly attributed specialist
assessment cannot manufacture OB1 activity. Initiative controls belong to OB1.

Acceptance covers distinct roles, legacy saved rows, lane attribution, actual
leases, source verification, read-only status, interruption and resume, independent
specialist presence, desktop/mobile behavior, and existing workflow coverage.
Local checks: role regression (12), runtime (106), Top of Mind (45), existing
assessment schema (59), full assessment pipeline (57 additional assertions), working
UI (18) and compact Activity Hub browser suites. The live-context regression (279)
also passes with network functions disabled. GitHub checks remain the release gate.

## Section 2: independent creative work and specialist inputs

Brainstorming now runs as `brainstorm.explore` on the canonical Initiative queue.
The bounded opportunity scheduler gives it an independent low-priority lane. One
pending assignment per owner/Agent and a stable context hash prevent duplicate
model work. All registered specialists have a brief with permitted current source
references, verified reports, ready capabilities, or an explicit context gap.
Image and Coding executor results can contribute after their original sources and
matching action/receipt are verified. Empty or unavailable context is not a claim
that a specialist worked or that the owner has a preference.

The private aggregate reference is accepted only by Brainstorming. Its job stores
hashes, and its model receives the bounded current briefs. It may return up to
three ideas and zero assignments. Dedicated mailbox and call-content boundaries
remain intact; transient sensing and recursive creative reports are excluded.
OB1 receives the verified ideas under their original source references, and makes
its own governed assignment decision. Existing source-change wakeups prompt a
bounded reassessment. Top of Mind and the debugger distinguish creative work from
OB1 supervision and can show input contributors beyond the original four domains.

Section 2 acceptance: autonomous canonical scheduling; distinct worker identity;
complete brief coverage; private domain boundaries; source checks around inference;
byte-matched canonical receipts; unchanged-context suppression; actual OB1 model
handoff and admission; stale/malformed/cross-owner rejection; SQLite/MySQL and
existing runtime/UI regression coverage. The new end-to-end gate has 22 additional
checks after the 107-check Canvas fixture and runs in SQLite and native MySQL.
Cold status requests explicitly load the specialist catalog. A domain-only
installation can omit workflow storage without failing its own report reader.
Fresh creative exploration yields to ready Media invitations; aged creative work
receives a bounded promotion without outranking urgent specialist follow-through.
The existing supervisor, tools, source, Top of Mind and live-context gates remain
part of the consolidated CI. Release certification still requires GitHub checks.

## Section 3: useful creations with little context

A general creative starter is discoverable when the account has only setup or
conversation context. Capability grants determine available options, and each
executor still enforces its configured model, budget, queue capacity and review
rules. Starter, light-context and contextual modes guide creative exploration
without claiming a personal preference. Current owner conversation can also ground
an image concept directly.

Brainstorming can propose image concepts, playable playlists and small functional
Canvas workflows from that context. OB1 independently admits the selected work.
Image concepts retain the existing generation authorization. Canvas proposals use
normal owner review or the existing Auto Build preference. `playlist.prepare`
reuses consented YouTube search, quota accounting, video verification and the
existing Chat player. Its generic public query is fixed by the server; no private
account context becomes a provider query. Unplayable candidates are discarded.

Typed private results remain source-bound. Pending playlists move to the existing
Chat result ledger on delivery and remain verifiable after newer messages arrive.
Delivery uses normal controls and Media spacing, and neither reload nor retry
regenerates the results. Withdrawing YouTube consent removes the playlist while
unrelated generic image/Canvas source context remains valid. Status reads do not
start inference, generation, a provider search or a Chat post.

The end-to-end regression creates a separate empty owner/Agent namespace, runs
Brainstorming and the actual OB1 assessment, prepares all three outputs, delivers
them once, restores the playable playlist from Chat history, advances an exact
Canvas proposal with an owner reply, and runs the metered Coding worker to save a
real private app draft with interaction and state code. The canonical 107-check
Canvas fixture and existing source, runtime, tools, Top of Mind and live-context
regressions remain in coverage. Native MySQL and SQLite gates are included in the
existing workflow. Production provider/device configuration still needs live
installation acceptance; no real paid model or provider calls were used in tests.

## Remaining build sections

1. Extend OB1's durable executive loop to review objectives and returned ideas,
   prioritize, assign, verify outcomes, advance or escalate, and schedule the next
   review. Use meaningful-change wakeups and bounded periodic catch-up. The server
   or HomeServer worker owns execution when the browser is closed.

Each section needs its own concrete acceptance evidence and current-main conflict
review. Repository tests do not prove a particular live installation's scheduler,
provider credentials or physical device behavior.
