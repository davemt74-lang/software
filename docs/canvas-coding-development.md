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

## Section 2: a reviewable editor workspace

The editor has Preview, Changes and Source tabs. Instructions, the saved build
stage, recovery actions and a bounded preview-check result share one panel. A
desktop places that panel beside the selected app; narrow screens stack the
workspace and collapse instructions when opening a saved app. Unfinished personal
instructions keep the form expanded. The owner can collapse it at any width.
The Chat thread owns source/comparison scrolling; source has no nested scroller.
Returning to Chat restores its composer through the existing view lifecycle.

Each selected version identifies the working version separately from the version
being reviewed. Changes defaults to the working version when available, and can
compare with another saved version. It reads the existing native `open` operation;
comparison does not start generation, record a test, activate code, adopt returned
app state or change grants. Use and Restore retain the native activation checks.
View working version only navigates to that saved revision.

The comparison displays literal HTML, CSS and JavaScript as added/removed text,
with line numbers and context. It never inserts saved source as DOM markup.
Source shows the complete selected language and directs edits through the existing
Coding Agent instructions. Direct manual source editing is a later contract, not
an unsaved textarea that might accidentally replace a tested revision.

The pure comparison algorithm strips common ends and caps its LCS table at
400,000 cells. A larger rewrite groups removed and added blocks in linear time.
The browser renders at most 800 source rows, explicitly identifies omitted rows,
and retains the complete selected source in Source. Inputs retain the existing
60,000-character per-language artifact limit.

Comparison captures the selected Canvas, revision object, owner/Agent/Chat epoch,
open request counter and active/draft pointers. Leaving Changes, closing the view,
selecting a different version or changing identity discards late results. Native
responses must identify the requested Canvas, Agent, original Chat, revision and
unchanged active/draft pointers. Stale/unavailable reads clear the result and offer
Reload selected version instead of silently comparing a different saved state.

Tab switches retain the same isolated iframe and its temporary draft interaction
state. Starting Test deliberately returns to Preview and runs the existing check.
The result distinguishes loading, a startup check, sample-email checks and an
actual runtime failure; it asks the owner to try the controls. It does not claim
functional correctness for arbitrary generated apps. Runtime failures leave a
useful message in the preview area and continue to disable Use.

### Section 2 acceptance and scoring

Target: 10/10 against these workspace requirements, with release dependent on
the published PR's checks. Live generated-app quality is outside this score.

1. Preview, Changes and Source are reachable with labeled keyboard-accessible tabs.
2. Switching tabs preserves the preview element and temporary draft interactions.
3. Comparison is read-only, defaults to the working version and supports history.
4. Added/removed source is exact literal text, with separate language inspection.
5. Large rewrites have bounded calculation/rendering with explicit truncation.
6. Stale pointers and failed reads produce a recoverable, empty comparison result.
7. Late results cannot cross a version, Canvas, tab, owner/Agent/Chat or participant.
8. Instructions/checks are together, collapsible and preserve unfinished text.
9. Desktop, 390px and 320px layouts fit without horizontal or nested source scrolling.
10. Test/Use/Restore stay explicit; app records, runtime, email, Profile and phone
    integrations remain intact.

The dedicated diff fixture verifies exact reconstruction, repeated/empty/Unicode
lines and large rewrites. The workspace browser uses the production modules and
CSS to exercise these requirements, including rejected identity/pointers, delayed
JSON bodies, first-version history and retained preview state. Existing Canvas,
recovery and email browser fixtures load the shipped comparison dependency. The
Runtime Journey runs both new fixtures, and the deployment workflow requires the
new production script. No schema change or new backend endpoint is introduced.

## Section 3: editable source and targeted Agent requests

The owner requested the next product build around an editable code editor and
precise changes to an existing app. This section implements that workflow before
the broader interaction-testing/automatic-repair work.

Source provides an editable HTML/CSS/JavaScript surface with bounded syntax
colors, line/column information and JavaScript syntax diagnostics. The bundled
Acorn parser only inspects code. The existing opaque iframe/worker remains the
execution boundary. Source markup becomes text nodes, and the Chat thread owns
the source editor's scrolling. Phone text is at least 16px; source controls retain
the existing touch-target sizing.

Unsaved edits compare against their saved base in Changes. Preview edits uses a
disposable data copy, sample connected-source rows and no live source targets.
It cannot become a native saved-version test or enable Use. A failed scratch
preview does not invalidate a previously tested saved revision.

Save source draft uses the canonical version/Knowledge store and no model call.
Four nullable columns in the version table retain exact manual base/origin and
request identity. The explicit Database Upgrade adds those columns and their
unique request index. Readiness reporting does not install schema during polling.
Older installations retain app execution and source inspection.

The native save locks the owner and exact Canvas, validates its immutable source
proof and active/draft pointers, and inherits its command declarations. It creates
a separate untested private code item without updating records, grants, active
code or publication. The same request ID recovers its exact previously saved
revision, including after another draft appeared; a changed payload is rejected.
Unchanged code creates no empty revision. A worker changing the same Canvas fences
manual saves; unrelated Canvases remain manually editable.

The browser retains a stable pending save across uncertain headers/bodies and
preserves typing while that save is being confirmed. Additional typing is carried
onto the confirmed revision and remains unsaved. Its expected active pointer and
draft fence remain tied to the accepted save, including when another writer
advanced the server. Eight bounded in-memory buffers preserve unsaved work across
Canvas/version and Chat navigation without displaying it in another scope.
Identity changes discard those buffers. No private source is put in localStorage.
Closing/reloading still requires an explicit save.

Saved-code selection is optional. Native admission checks an 8,000-byte UTF-8
range and source hash against the exact saved revision. The canonical private job
goal retains that selection, including through explicit Retry and Edit saved
request. Progress feeds omit it. The worker rechecks source/pointers before
inference and supplies the verified selection to the existing code_repair provider
path. The provider receives the excerpt from native source, not a client-supplied
copy, and is instructed to make the smallest coordinated change. Its result is
still a complete untested artifact for Changes/Test/Use review. Selection is
context, not a guarantee that only those literal bytes can change.

Manual source must preserve special connected-source ancestry. The native email
resolver follows manual origin metadata alongside completed model-run receipts.
Repeated manual edits flatten their origin while retaining the immediate base,
so they do not silently lose email access or become writable generic apps.
Malformed ancestry fails closed, and the original Chat and sample-email test
requirements remain. Paired-phone viewers retain their native read-only editor
boundary.

### Section 3 acceptance and scoring

Target: 10/10 against these concrete source-editor requirements:

1. HTML/CSS/JavaScript remain editable, highlighted literal text with useful
   source locations and bounded validation.
2. Scratch previews use the existing isolation and cannot save records, publish,
   activate or record a saved-version test.
3. Manual edits create immutable untested drafts with the exact original commands
   and separate current app records.
4. Manual saves use no inference and require native owner/Agent/Chat/write access.
5. Lost responses recover the exact original save without duplicate revisions.
6. Stale source/pointers and competing same-Canvas work cannot replace newer work.
7. Typing during saves and navigation survives without crossing private scopes.
8. Selected-code requests retain their immutable hash and UTF-8 range through
   admission, worker execution and explicit retry.
9. Email-linked versions retain source, read-only behavior and original-Chat/test
   restrictions through repeated manual edits.
10. Existing Canvas/recovery/email/phone/Profile flows and desktop/390px/320px
    layouts continue to pass with all production dependencies loaded.

The source store, worker/context, real HTTP controller, parser and production
browser fixtures cover these requirements. They run in Runtime Journey alongside
the extended native email pipeline. The package gate requires the editor module,
source helper, local parser, license/notice and deployment guide. Live installation
and arbitrary generated-app functional correctness are outside this score.

## Following sections

1. Improve interaction-level validation and bounded repair so a broken generated
   control can be diagnosed and repaired without losing the working version.
2. Extend the native editor command registry only where an existing surface can
   verify its changes; preserve the generated app runtime's isolation.

## Section 4: live generated source and retained provider responses

A rebuild can consume provider tokens without yielding a saved Canvas. The owner
reported that exact symptom with Claude. The old Coding transport waited for one
complete JSON response and used an 80-second timeout. A timeout or unreadable
response became `coding_provider_unconfirmed`; the repository cannot prove which
transport failure occurred in the owner's historical run. No production inference
or paid retry was used to investigate this change.

Coding now uses a single bounded SSE request for the exact selected Claude
Messages or OpenAI Responses model. Native model authorization, personal-key
checks, package reservation, service policy and the execution ledger remain in
force. There is no provider retry, model substitution or automatic paid repair.
The transport allows up to 300 seconds, respects a smaller enclosing AI budget,
and refreshes the native worker lease while receiving output or waiting on the
connection. Browser navigation does not cancel server execution. Host process
termination can still stop a run; polling recovers its existing status.

Only answer text is accumulated. Claude thinking/signature blocks and OpenAI
reasoning events are excluded. SSE framing, event order, text size, total response
size and final response identity are bounded and checked. Claude cumulative usage
is merged rather than summed. OpenAI answer deltas must match the completed
response. A missing completion receipt never becomes confirmed zero usage.

The canonical private job has a nullable `coding_provider_result_json` column.
An explicit Database Upgrade adds it to existing installations; fresh job schema
includes it. Live reads and admissions do not perform DDL. Each checkpoint carries
the exact run, request, lease attempt, source proof and received-text hash. Updates
require the current native lease and executing run. The final checkpoint precedes
usage settlement and source validation, preserving received code if either later
step fails. Generic workflow history and ordinary build progress exclude that
source. No parallel app store or second queue is introduced.

Opening the owner editor automatically shows **Live generated code** for the
current run. HTML, CSS, JavaScript and the received JSON response are selectable.
A bounded prefix reader displays incomplete JSON strings as literal source; it
does not evaluate them or inject markup. Code refreshes about once per second
while the view is open and the run is active. The existing selected/working app
stays below it. Chat, Agent or account changes, hiding the page and leaving the
editor clear the view and discard late responses. Source wraps in the existing
Chat scroller instead of adding an inner source scroller.

Run details now show native provider status, elapsed time when known, confirmed
usage or an explicit unknown-usage message, and a direct AI usage link. Confirmed
rejection, timeout, lost connection, service error and incomplete response have
separate bounded explanations. Retry explicitly says it starts a new provider
attempt that may use more API tokens.

For failed/stopped **rebuilds**, complete structurally valid received source can
be explicitly saved as an untested draft without inference. Recovery retains the
original source revision, active/draft compare-and-swap fences, commands, native
email ancestry, permissions and current app records. It never activates or
publishes code. The exact response derives one stable native save identity, so a
lost import response or reload returns the same revision. A newer app draft blocks
an old import. Partial/invalid source remains inspectable. New-app runs can show
received source but do not offer this rebuild-only import path. Older runs cannot
recover source that was never retained by the old implementation.

Section acceptance is 10/10 for the following bounded gates after release CI:
streamed Claude and OpenAI parsing; accurate known/unknown native metering; one
physical attempt; current-lease checkpoints; private scoped code reads; loss-safe
rebuild import; preserved active version/data; unexecuted live display; typed inline
run diagnostics; and responsive/context-fenced browser behavior. Local evidence
covers the real native meter with offline transport, native storage on SQLite and
MySQL, the shipped HTTP controller, Chromium desktop/390px views, and existing
source/editor, email, Profile and paired-phone regression paths. Actual provider
latency, code quality, HostGator request limits and physical-device suspension
remain deployment acceptance rather than claims made by synthetic tests.

Provider event contracts:
- https://platform.claude.com/docs/en/build-with-claude/streaming
- https://developers.openai.com/api/reference/resources/responses/streaming-events

## Section 5: saved workflow checks and one-pass repair

A preview starting successfully does not prove that a button, form or save works.
The owner editor can now record a workflow using the real isolated app controls,
name its expected visible result, and optionally require that result after a
reload. Recording uses empty disposable app data and sample connected sources.
Password input values are not recorded. Generated new apps may also propose
checks; the owner can inspect and remove them. Existing apps without saved checks
keep the legacy startup test and can add a recorded workflow when useful.

A saved source revision may carry at most three declarative cases, twelve actions
per case and twenty-four total actions. Actions are click, fill and check; expected
results are visible text, a control value or a checkbox state. Text, selector and
JSON sizes are bounded. Definitions contain no executable assertion scripts,
network targets, secrets supplied by VP3 or authority to change grants. They are
private owner-authored/sample test data, stored with the immutable source and
included in its native source hash. Saving/removing checks creates a new untested
draft through the existing idempotent manual-save path. Manual source edits and
Coding repairs inherit the same acceptance checks; a repair cannot remove or
weaken them to manufacture success.

The trusted iframe harness invokes the existing worker callback bridge. Each
step waits for its callbacks, acknowledged sample saves and a short quiet render
interval. It checks a visible result, then starts a fresh runtime with only the
acknowledged disposable state when persistence is required. Test mode has no
openable email/source targets, no live data refresh and no live app-data writes.
Worker faults, missing/ambiguous controls, missing click handlers, failed expected
results and timeouts produce bounded reason/step codes. Untrusted synthetic
browser events cannot add recording steps.

Test preview runs saved workflows without inference. Test & repair explicitly
allows one existing Coding Agent job if a check fails. The native failed report
stores that exact run identity, so replay after a lost admission cannot create a
second repair. The owner job still pins its model/funding, uses the native meter,
and saves a separate draft. The current editor retests that new draft with repair
disabled. A failed retest remains a failed draft and stops the cycle. Stop repair
cancels the native run; Stop check cancels the local test. Changed source, app
state, Chat/Agent/account context or unsaved manual edits fence delayed results.
A recovery import of received provider code also inherits the original checks.

Reports live in nullable `workflow_result_json` on the canonical version table.
Database Upgrade adds it explicitly; live operations do not migrate storage. Begin
and finish share an exact request identity and are idempotent. Reports bind the
source hash, check hash, app-state revision, active/draft pointers, image token,
Agent and original Chat. Finishing an obsolete check cannot test a newer source or
newer app records. A version with saved checks needs a current passed report before
it can be marked tested. Email-connected apps additionally retain the existing
native sample-email proof and original Chat. Use/Restore and publishing continue
to use the existing activation and publication controls; tests do not activate or
publish code. Publication strips test plans and reports from its source projection.

Section acceptance targets 10/10 for these bounded gates: declarative validation;
immutable versioned plans; real recorded actions; sample-only data and no external
requests; acknowledged save/reload verification; exact native test proofs; one
explicit repair admission; retest without another paid loop; preserved active
code/data/email/publication boundaries; and desktop/phone/context-loss behavior.
Local coverage includes SQLite and MySQL, actual controller POSTs and real Chromium
workers. Runtime Journey runs the new native, HTTP, runtime and editor browser
checks. This establishes the check system's behavior, not that an arbitrary model
has written a complete specification or that passing selected examples proves all
possible app behavior. Live provider quality and installation process limits still
need the owner's normal deployment test.
