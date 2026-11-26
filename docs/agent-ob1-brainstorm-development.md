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

## Remaining build sections

1. Give Brainstorming its own canonical assignment, source-bound result and
   specialist briefs. Every registered specialist can contribute permitted context
   or explicit unavailable/empty status. Preserve dedicated Email and Operator
   privacy boundaries and avoid recursive self-generated evidence.
2. Add opportunities that need little history: image concepts, playable playlist
   preparation and functional Canvas proposals. Reuse actual executors, grants and
   configured generation budgets. Treat generic starters as generic; use current
   conversation when available and retain the exact context behind each proposal.
3. Extend OB1's durable executive loop to review objectives and returned ideas,
   prioritize, assign, verify outcomes, advance or escalate, and schedule the next
   review. Use meaningful-change wakeups and bounded periodic catch-up. The server
   or HomeServer worker owns execution when the browser is closed.

Each section needs its own concrete acceptance evidence and current-main conflict
review. Repository tests do not prove a particular live installation's scheduler,
provider credentials or physical device behavior.
