# Canvas and Coding Editor development

## Scope and product direction

Keep VP3's existing concepts: describe an app or change in Chat, let the Coding
Agent save a private draft, test its isolated preview, explicitly use a version,
and optionally share or publish through the existing controls. The current owner
priority is an upgrade to the whole experience, especially builds that stop or
appear to stop. This audit covers the repository implementation and synthetic
acceptance paths. It does not claim observation of a failed build on vp3.me.

Audit base: `201738f46cb1bfb6c0b0365dff8fb453630f08e6`. Open PRs were checked
before implementation; the recent phone/Canvas and music changes are merged.
The remaining open PRs were older work, with no active Canvas editor change.

## Audit of the existing system

| Area | Existing behavior and evidence | Gap or consequence |
| --- | --- | --- |
| Entry and discovery | `canvases.php`, `coding-canvas-sidebar-v1.js`, Chat shortcuts and native Chat cards open the same saved Canvas, Agent and revision. | There are several entry points, but they share a canonical app store. Keep that store and routing. |
| Build and repair | `coding-canvas-jobs-v1.php` admits one active Coding job per owner, pins its model, uses one execution lease and commits a draft with its receipt. | A failed run has one attempt. Generic workflow Retry cannot replay this metered request. The editor had no dedicated recovery action using the saved instruction. |
| Provider output | `coding-canvas-provider-v1.php` requests a compact complete HTML/CSS/JS artifact, 12,000 output tokens, one physical provider attempt and an 80-second transport budget. | Truncated output, invalid source and uncertain provider responses were flattened into a generic failed-build reason. Raising limits alone would not fix lost browser requests or incomplete code. |
| Browser requests | `chat-coding-canvas-v1.js` posted and read JSON with no request deadline. Queued-job dispatch waited for its tick promise to settle before another attempt was possible. | A lost response or body could leave controls locked or a queued build without recovery indefinitely. A status check must never be interpreted as permission for a second build. |
| Reload and navigation | The browser can rediscover an active run, fences old Agent/Chat responses, and does not replace an app with a separately observed background result. | Native resume selected only active runs. A build finishing before reload, or failing while away, disappeared from this recovery path. A regression reproduced the completed-run loss before the fix. |
| Editor controls | Build new, Repair, Stop, saved apps, versions, test, apply, source view, permissions and publishing already exist. | The lifecycle is presented as a collection of controls. The editor needs a clear current stage, recovery actions and a way to restore a saved brief without overwriting personal text. |
| Preview and test | `coding-canvas-runtime-v1.js` runs generated JS in a restricted worker within an opaque iframe, detects startup/runtime/health failures and restricts resource and data access. | A loaded preview is not a complete functional test of an arbitrary generated app. Interaction testing and a bounded repair workflow remain a later section. |
| Versions and app data | `coding-canvas-store-v1.php` keeps immutable code revisions, private Knowledge documents, a separate data revision and activation/rollback checks. | Preserve these contracts. Retrying an old repair after the Canvas changes must not replace the newer draft or pretend the original source is still current. |
| Data commands and sources | Command grants and native source modules mediate app data, Gmail, music, calendar, products and explicitly public projections. | Keep these integrations behind the current bridges; an editor upgrade must not put main-app credentials or unrestricted network tools into generated apps. |
| Public and Profile Canvases | Publication, exact revision proofs, Profile listing and the standalone runtime are implemented in the existing publication/Profile modules. | Recovery must not activate or publish a saved result automatically. |
| Connected phone | `coding-canvas-phone-v1.php` now finds approved phones even from another owned/new Chat and preserves the original Canvas context. | Preserve the merged phone fix and native no-login delivery contract. |
| Other editors | `editor-agent.js` is the existing command registry/broker for registered editor surfaces. Stem, video and marketing editors have their own native adapters. | These are separate surfaces. Do not replace them or move a native command broker into the restricted Canvas worker as part of build recovery. |

The highest-priority gap is **recoverable build execution**, before a wider editor
layout redesign. Direct source editing, visual change comparison and functional
test/repair improvements are useful subsequent sections; they do not replace the
need to know whether an existing run actually succeeded or failed.

## Section 1: recoverable builds and clear editor stages

All Canvas API requests now have a browser deadline covering both headers and
JSON bodies. Ordinary operations use 15 seconds. The generation tick uses 110
seconds, beyond the existing provider transport budget. Timing out a browser
request does not assert that server execution failed or that no usage occurred.

An uncertain build or Retry admission is looked up by its original native request
ID. It is not replaced with the latest unrelated job. The owner can explicitly
check saved status. Queued dispatch remains protected by the native execution
lease and metering; a status lookup cannot invoke generation. Network polling
retries are bounded per request, and rejected identity/session/context reads stop
automatic polling. A changed Agent, Chat or participant scope fences late results.

Resume retrieves the latest run in the exact Chat, preferring active work. It can
recover a completed, failed or stopped run as well as a running job. A completed
result must still identify the exact revision saved by that run in its owner's
private Canvas folder. A missing result is unavailable, not permission to replay
the completed provider attempt. Paused work is displayed as paused and can be
explicitly stopped rather than being mislabeled as an ordinary failure.

The editor exposes **Retry saved request** only for a failed or stopped run.
This is an explicit new Coding Agent run, using the saved instruction and current
model/access policy. Its native goal records `retry_of`; the new admission ID is
stable across network recovery and replay. A repair retry must still match the
original draft/active pointers. A newer draft or activation requires a new change
request from the current version. No automatic model retry, activation, publication
or change to app data is added.

Retries of specialist-proposed Canvases retain the original canonical Initiative
parent and source/owner-decision proof. The worker checks that proof at admission,
before inference, after inference and under the save lock, including a chain of
explicit replacement runs. Revoked sources or policy stop the work. Loading the
saved source-derived brief also rechecks this authority. The pipeline regression
reproduced source-proof loss through Retry before this additional check was added.

**Edit saved request** restores the private original brief only on explicit
request. Progress feeds do not contain it. Existing unsent text wins, including text
typed while admission is being confirmed. Build, Test and Use stages summarize
the existing lifecycle, alongside saved status and recovery actions. They do not
invent a percentage or claim semantic correctness merely because a preview loads.

Safe failure categories distinguish incomplete output, unusable source, an
unconfirmed provider attempt, unavailable transport/provider, insufficient provider
credits and unconfirmed usage. Provider text, source fragments and credentials do
not become status messages. Existing quota, source/access and expired-lease
failures retain their own guidance.

## Acceptance and scoring

The Section 1 score is assessed against these concrete requirements, separately
from overall generated-app quality. Target: 10/10 after the relevant native,
HTTP, browser and integration checks pass.

1. A lost admission response or JSON body recovers the exact original run.
2. Checking status and repeating the same admission do not dispatch duplicate work.
3. Reload recovers a completed/failed/stopped result in the exact owner/Agent/Chat.
4. A confirmed completed run cannot be retried as a new paid run through Retry.
5. An explicit failed/stopped retry is idempotent and retains its saved instruction.
6. A stale repair retry preserves newer source, active versions and saved app data.
7. A saved brief or delayed response cannot overwrite newly typed instructions.
8. Model cutoff and other known failures have useful bounded status messages.
9. Stalled reads and rejected sessions do not leave indefinite locks or endless polling.
10. Desktop/phone controls fit, remain usable, and keep the existing test/apply,
    isolated runtime, background job, phone and email integrations working.

Verification uses synthetic provider responses and isolated databases. The tests
exercise the native job/store/metering paths, the shipped HTTP controller and the
production browser modules. Live model writing quality, actual HostGator process
termination and real-device network suspension remain installation acceptance.

No schema upgrade, additional job queue, parallel app store or new workspace is
introduced by this section.

Local acceptance evidence:

- Native jobs: 51 assertions on SQLite and MySQL, including exact request
  recovery, completed and failed reloads, explicit retry/replay, source changes,
  paused work, deleted results and account/Agent/Chat separation.
- Native Canvas storage passes on MySQL; the existing full Canvas pipeline passes
  121 assertions and the email Canvas pipeline passes 37 additional assertions
  on MySQL.
- The shipped HTTP controller passes recovery, explicit saved-brief access,
  idempotent retries, input/CSRF checks and owner/Agent/Chat boundaries.
- Production browser modules pass lost-admission and response-body deadlines,
  uncertain retry recovery, unaccepted-request resubmission with the same ID,
  private brief recovery, preserved typing, terminal reloads, stopped session
  polling and late-response fencing. Desktop and 390px phone layouts are inspected.
- The specialist pipeline covers a nested explicit replacement, source revocation
  before retry admission and during retry inference, and private-brief access
  after withdrawal. The full provider/usage preservation suite passes 158 checks.
- Existing Canvas browser/runtime and email-source browser gates, model selection
  and Activity Hub (232 assertions) pass.

These checks are added to the existing Runtime Journey CI. Final release requires
green checks at the published PR head and a source-verified deployment archive.

## Following sections

1. Consolidate the editor workspace around preview, requested changes, version
   comparison and source inspection, preserving app data and the current app view.
2. Improve interaction-level validation and bounded repair so a broken generated
   control can be diagnosed and repaired without losing the working version.
3. Extend the native editor command registry only where an existing surface can
   verify its changes; preserve the generated app runtime's isolation.
