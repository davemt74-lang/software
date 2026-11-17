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

### Completed broader preflight repairs

The three earlier preflight failures came from obsolete or cumulative fixtures. Repairs load canonical AI settings and service-readiness policy, retain real worker execution behind isolated transport fixtures, and align saved text delivery with the current owner/Agent Chat binding. Independent review confirmed that owner/scope, source revision, no-model, no-duplicate, exact active-job projection, and pending-delivery rule-revalidation assertions remain covered. No production code changed in these repairs.

| Suite | Latest local result |
| --- | --- |
| Initiative runtime | 104 cases passed |
| Coordination routing pipeline | 124 assertions passed with SQLite |
| Office entry pipeline | 83 assertions passed with SQLite |
| Top of Mind | 45 cases passed, in addition to its runtime prerequisite |
| Top of Mind session HTTP | All three authenticated/session blocks passed |
| Worker health | 24 cases passed, in addition to its runtime prerequisite |
| New Morning Brief coverage | 47 assertions passed with SQLite |

The Morning Brief suite exercises real source grants, encrypted findings, saved owner Chat policy, local-day timing, atomic proof/message publication and rollback, once-per-Agent/day delivery, Chat switches, history and voice revalidation, tampering, changed/revoked/deleted sources, and owner/Agent/participant boundaries. Both SQLite and native MySQL invocations are registered in CI. A MySQL or hosted-browser pass is recorded only after execution.

## Publishing checkpoint

The owner explicitly authorized publication of the recovered November 12–17 source and completed fixes to public `davemt74-lang/software`, opening the PR, resolving failing checks, merging only after all required checks pass, and producing the full deployment ZIP. Automatic approval review accepted that self-contained authorization.

The complete recovered Git tree `565c50f529fae02464a48107d15ea0849fb4d707` was uploaded and verified against the local recovery checkpoint. Remote recovery commit `7a1231851001302d8e06abbf43ea6e65621b45f8` follows main `c80a2d9c2345de11962d724e00e423373aedb809`. Initial continuation commit `6ca5f779733dc628041ed52362b48b71a9669b62` has tree `6d96c85187579d33169950a712bb749aecaa0624`, exactly matching local checkpoint `a097c302566747b360e5ba6bfe63e619fc49ea35`.

[PR #784](https://github.com/davemt74-lang/software/pull/784) is open from `release/vp3-specialist-baseline-20261116` into main. Hosted checks are running and broader fixture repairs follow in the same PR. No merge or deployment has occurred at this checkpoint. Complete all required checks on the final head, merge only after green, and verify the resulting production archive against that merged revision. The earlier local candidate archive remains a review candidate, not the final merged release.
