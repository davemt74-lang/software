# VP3 work-chat recovery — November 17, 2026

## Recovery provenance

The previous work chat prepared newer production files without publishing them to GitHub. Recovery preserves those files before continuing the unfinished screen/audio and Media work.

| Evidence | Observed value |
| --- | --- |
| Repository | `davemt74-lang/software` |
| Remote main at recovery | `c80a2d9c2345de11962d724e00e423373aedb809`, merged through PR #783 on November 11 |
| Remote release branch at recovery | `release/vp3-specialist-baseline-20261116`, pointing to the same main commit |
| Recovered package | `VP3-Cloud-Screen-Share-Chat-b196620830ee-Deploy.zip` |
| Package SHA-256 | `a2ffda76fe90f7f5197bc414842b035e3421f8b92bdc207dc82ddf0e71143960` |
| Package overlay ID | `b196620830ee` |
| Commit declared by the old package | `29852d7869217002fe8f317f3f291bd00505dcc3`; GitHub reported no such commit during recovery |
| Local recovery checkpoint | `a66a7ebddaf72713029aad3a9abe135d5d5824b3`, based on the retained `vp3-open-chat` checkout at `f89bc93` |

The recovery checkpoint contains exactly the package's **2,114 production files**. A byte comparison found no missing, different or extra production files under the production workflow's exclusion rules. This verifies preservation of the prepared package; it does not establish which version is installed on a live site.

The checkout also retains 1,302 test/fixture files, seven tools and 19 workflow files from the earlier source snapshot. The recovered newer production source did not contain tests, tools or workflows. These retained tests therefore require current execution and, where behavior changed, meaningful expectation updates.

## Continuation scope

- **Shared-screen reports:** present the completed report as an ordinary assistant response in the existing Chat canvas, retaining its exact session/conversation authority, actions, reload behavior and duplicate protection.
- **Agent Ears:** permit selection of the current Chat tab containing a YouTube player, distinguish audio capture from video sharing, and expose the actual selected track/capture state. Browser permission and an available audio track remain required.
- **Media:** include source-backed similar-artist results in the existing media report and Chat playback flow, with evidence retained through playlist creation and replay.
- **Release:** preserve the recovered specialist, coordination, Gmail, task/list/reminder, Calendar and Canvas work while validating and publishing the combined source.

## Verification boundaries and gates

The successful GitHub runs for PR #783 and the November 16 Recovery Baseline refer to the older `c80a2d9c` tree. The latter has no artifacts. Neither certifies this recovered package or the continuation changes. The old package manifest also has no workflow run ID.

The existing HomeServer Runtime Journey workflow now includes the new screen-report and Media source paths in its scope detection. Its new acceptance invocations cover the Ears picker, durable screen report, Chat report client, similar-artist service and YouTube browser presentation. The existing screen-insights browser gate remains and must exercise the current Chat presentation rather than the removed standalone layout.

Before release, record results from the final source revision, including the new acceptance suites, affected Chat/coordination regressions, load and upgrade checks, and applicable GitHub workflows. A registered test invocation is not a passing result. Live microphone/tab capture, provider behavior, installed worker execution and mobile account behavior require installed-runtime verification where controlled fixtures cannot prove them.

Publication must include the complete recovered diff, tests, workflow changes and the binary icon. Compare the published Git tree with the local committed tree before advancing the release branch. After merge, verify the production archive against the merged revision; the packaging workflow regenerates `VP3_RELEASE.json` with that actual commit. Keep `config.php`, `.env`, `private/` and `uploads/` out of the archive.

## Local continuation verification

The November 17 continuation completed the three scoped changes above. Independent review found no blocking issue in that delta; it did not certify every line of the much larger recovered baseline.

| Check | Observed result |
| --- | --- |
| Changed PHP/JavaScript syntax against remote main | 416 files passed |
| Agent Ears picker and recording | 13 scenarios passed |
| Saved screen-report service | 38 report cases plus 34 existing capture cases passed with real SQLite PDO |
| Screen sessions and report HTTP API | 12 cases passed |
| Shared audio events | 21 event cases plus 34 existing capture cases passed |
| Chat report rendering and refresh | Production-JavaScript DOM/VM suite passed |
| Similar-artist service | 22 checks passed with both SQLite and native MariaDB |
| Similar-artist rendering and playback IDs | Production-JavaScript DOM suite passed |
| Core setup/upgrade parity | 30 cases passed with real SQLite foreign keys |
| Core startup and same-session heartbeat | Real two-worker PHP HTTP suite passed |
| Chat activity timeout, retry and lazy media | Runtime suite passed |
| Existing YouTube core, consent, quota, playlists and Chat | Targeted suites passed |
| Initiative scope and research status | Repaired current-behavior fixtures passed; scope suite has 90 cases |
| Workflow syntax/scope | YAML and embedded Python parsed; required new invocations present |

The portable PHP runtime lacks DOM, so the broader Initiative tools suite stops at its existing web-collection readiness assertion after 31 passing cases. Its duplicate legacy stub collision was repaired; the assertion was not removed. Chromium cannot launch in this executor because socket creation is denied. Browser tests remain enabled for GitHub CI, and no real YouTube/ElevenLabs or live hosting result is claimed.

### Additional preflight fixture checkpoint

After the owner's publication approval, three broader recovered-baseline suites exposed obsolete setup and delivery fixtures. The bounded repairs load the canonical AI settings and service-readiness policy, keep real worker execution behind isolated transport fixtures, and align saved text delivery with the current owner/Agent Chat binding. The new shared fixture is included in workflow scope detection. Independent review confirmed that owner/scope, source revision, no-model, no-duplicate, exact active-job projection, and pending-delivery rule-revalidation assertions remain covered. No production code changed in this checkpoint.

These suites are **still failing** and are not a release signoff:

| Suite | Last local result | Remaining assertion |
| --- | --- | --- |
| `tests/agent-initiative-runtime-v1.php` (also loaded by Top of Mind) | 69 passing assertions before failure | A changed source withdraws the stale plan before a second model call |
| `tests/agent-coordination-routing-pipeline-v1.php` | 77 passing assertions before failure | A previously posted Tasks question with identical evidence does not consume Gmail's separate Ask rule or situation |
| `tests/agent-initiative-office-entry-pipeline-v1.php` | 81 passing assertions before failure | The fixture has a saved coordination handoff ready to claim; the observed scheduler outcome was `queued_work` |

The later runtime outage section still contains obsolete busy-text expectations beyond its first failure and remains unvalidated. These fixtures mark a prior daily brief assessment as completed to isolate their queues; no dedicated Morning Brief coverage was found in the retained tests. The remaining failures and coverage gaps require investigation before any green-check merge. The previously prepared review ZIP retains the same production files; these additional changes affect tests, workflow scope and this handoff only.

## Publishing checkpoint

Automatic approval review initially blocked transfer of recovered source into the public GitHub repository. Repository ownership, the matching original remote/package repository, prior user handoff instructions and excluded runtime-secret paths were checked. The owner then replied "i approve" to the explicit request to publish the recovered November 12–17 work plus the fixes to public `davemt74-lang/software`, open a PR, and merge only after required checks pass.

The approval review accepted another partial source tree, but rejected the next batch, stating that its retained context did not preserve a self-contained user approval naming the public payload and destination. Further publishing attempts stopped. No branch was advanced, no PR was opened, and nothing was merged or deployed. The owner's approval exists; the remaining publication block is the automatic review's authorization requirement.

The accepted recovery upload checkpoint is batch **8 of 53**, tree `9bcc62ea06af66850f1c6ea3fd5aea3b07ed9431`. The planned recovery tree remains `565c50f529fae02464a48107d15ea0849fb4d707`; do not advance a branch to a partial tree. The working release branch still points to `c80a2d9c2345de11962d724e00e423373aedb809`.

The local candidate is available for review. After resolving the automatic review block, finish the complete recovery tree, apply the completed continuation and fixture fixes, compare the final tree with the committed local tree, create the PR, resolve required checks, merge after green, and verify the resulting production package. A local candidate archive is not the final merged release.
