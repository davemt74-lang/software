# VP3 Agent capability integration matrix

The [foreground inventory] is the source for the 69 capability IDs and their declared modes. The [shared contract] maps those IDs to exact executors. One app can expose several IDs, and a native app can have operations outside ordinary Chat. This baseline records implementation and controlled evidence from `754ab38d`. Every installed-account check is pending here.

## How to read and maintain the matrix

- **Mode** matches the inventory. A write can be a private draft, a request for review, a queued job, or a confirmed mutation. The scope and result column says which effect the check should prove.
- **Controlled checks** name repository evidence. [Chat], [Release and Profile], and [Action HTTP] exercise production endpoint code with controlled dependencies. A stream case verifies that route. A browser fixture can additionally exercise simulated microphone or player events. A service fixture exercises its named operation without establishing Chat transport parity.
- **Initiative** names the eligible adapter, related evidence, separate worker, or explicit boundary. An evidence source can inform a proposal without adding a tool that executes the app operation. The [Initiative catalog] and current source permissions decide what work can actually run.
- **Installed** is a separate gate. Start at Pending, use Partial when some applicable routes are still untested, and name any blocker. Mark a row Verified with a sanitized dated trace for the deployed version, account and scope, the route exercised, the source or provider, the actual outcome or receipt, and any remaining limits. For writes, capture approval and independent readback. For background work, capture the scheduled worker, deduplicated delivery, and the right Chat with the browser closed where applicable.

## Agent, office, and work queue

| Capability | Mode | Scope and expected result | Controlled checks | Initiative | Installed |
| --- | --- | --- | --- | --- | --- |
| `capabilities.inspect` | read | Explain registered, ready, denied, and unverified capabilities for the current Chat. | [Chat], [Contract] | Shared readiness inventory. No assessment or mutation from inspection. | Pending |
| `voice.profiles.enroll` | read | Open guided local setup. Enrollment and save require browser participation and consent. | [Chat] review action, [Voice setup] browser | Foreground only. | Pending |
| `navigation.open` | read | Offer or open an authorized page or exact Stem Studio track. A link requests navigation. | [Chat] typed and stream route | Foreground only. | Pending |
| `brief.attention.read` | read | Read bounded current office and commerce priorities with source links and coverage. | [Chat], [Catch-up] | Shares evidence with Initiative, including [Meeting prep] and [Profile call follow-up]. | Pending |
| `tasks.overview` | read | Summarize authorized open tasks and due reminders without marking them delivered. | [Participants] service | Related task, list, and reminder evidence. | Pending |
| `tasks.lists` | write | Create or append to an exact owned list. A missing list requires confirmation. Create a task from one exact meeting finding after reviewing its source and title; retain origin and verify the canonical Brain record. | [Lists] service, [Office actions] reviewed bridge, [Meeting action source], [Meeting actions HTTP], [Meeting review UI] | Exact list reads and reviewed writes through the action bridge. A delivered meeting prep can supply the source for an owner-requested task even when Initiative is off. | Pending |
| `tasks.read` | read | Read scoped saved Brain tasks. | [Chat], [Records] service | Direct task read adapter. | Pending |
| `tasks.manage` | write | Update the exact saved task and verify its persisted status. | [Chat], [Records], [Action HTTP] | Reviewed action bridge for supported task writes. | Pending |
| `reminders.manage` | write | Read or change owned reminders. Review a reminder from an exact meeting finding with an explicit local time and timezone. Verify recurrence, status, source provenance, and actual delivery separately. | [Chat] creation and receipt replay, [Reminder API], [Schedule actions], [Meeting action source], [Meeting actions HTTP], [Meeting review UI] | Direct reminder read adapter, reviewed writes, and durable reminder wakeups. A delivered meeting prep can supply the source for an owner-requested reminder even when Initiative is off. | Pending |
| `calendar.read` | read | Read owned events and canonical occurrences. Next-meeting prep combines the exact owned VP3 meeting, agenda, open commitments, and authorized prior transcript excerpts. | [Chat] typed, spoken, legacy, and replay, [Calendar], [Next meeting prep] | Direct calendar read and [proactive meeting prep] with verified open commitments in the 30 minutes before start. | Pending |
| `calendar.manage` | write | Prepare and confirm exact personal-event changes. Chat reviews agenda additions and one-time linked VP3 meeting rescheduling or cancellation through the native lifecycle, exact source revision, and canonical readback. Invitations and outbound change notices remain in the native meeting editor. | [Calendar] service, [Schedule actions] reviewed bridge, [Meeting actions HTTP], [Meeting management], [Meeting workflow boundary], [Meeting review UI] | Reviewed personal-calendar action bridge. Native meeting mutations require a direct owner request and review; a background finding grants no mutation approval. | Pending |
| `scheduling.read` | read | Read personal appointments, event types, and availability in the owned schedule. | [Scheduling] service, [Office audit] | Booking-readiness evidence. No direct Initiative appointment adapter mapped. | Pending |
| `scheduling.manage` | write | Approve exact booking changes. A paid hold is distinct from confirmed payment and booking. | [Scheduling] service and approval, [Office audit] | Foreground or native appointment review. | Pending |
| `team_scheduling.read` | read | Read authorized Team pools, bookings, and availability. | [Scheduling] service, [Office audit] | Foreground read. No direct Initiative Team booking adapter mapped. | Pending |
| `team_scheduling.manage` | write | Confirm changes to the exact Team pool booking and verify the canonical participant record. | [Scheduling] service and approval, [Office audit] | Foreground or native Team review. | Pending |
| `workflows.read` | read | Inspect an exact owned workflow run. | [Workflow] service, [Office audit] | Related Initiative workflow state and completed Mission evidence. | Pending |
| `workflows.manage` | write | Approve, pause, resume, retry, cancel, schedule, or prioritize supported workflows. A control receipt is separate from the job outcome. | [Workflow] service, [Office audit] | Initiative manages its own work. General workflow controls use the owner action. | Pending |

## Knowledge, recordings, and participants

| Capability | Mode | Scope and expected result | Controlled checks | Initiative | Installed |
| --- | --- | --- | --- | --- | --- |
| `knowledge.search` | read | Search the authorized Knowledge scope and cite exact records. | [Chat], [Knowledge] contract | Direct scoped Cloud Knowledge search. | Pending |
| `brain.read` | read | Retrieve memory and decisions for the authorized Agent. | [Chat], [Initiative pipeline] | Direct Agent Brain read, excluding its own background bookkeeping where required. | Pending |
| `transcripts.read` | read | Discover, select, quote, or summarize authorized recordings. Meeting prep attaches its verified prior recording for continued source-aware Chat. Separate exact transcript from interpretation. | [Chat] includes meeting-to-transcript follow-up across typed, spoken, and legacy routes, [Transcript], [Transcript Initiative] | Direct read adapter for saved passages and existing analysis under current processing policy. | Pending |
| `transcripts.highlight` | write | Save exact highlights or apply reviewed findings to approved destinations. | [Chat] quote save and [Transcript actions] service | Saved highlights can inform follow-up. New destination writes use the domain review. | Pending |
| `transcripts.analyze` | write | Request fresh analysis or opted-in research with the transcript's processing policy. | [Transcript analysis] service | The read adapter uses saved analysis and can surface missing or stale analysis. Fresh analysis is a separate request. | Pending |
| `participants.setup` | write | Prepare profile linking or list sharing for signed-in owner review. | [Participants], [Participant setup] browser | Foreground review only. | Pending |
| `participants.read` | read | Read bounded recent seen/heard observations. | [Participants] service | Related provisional observations through live context. Identity and sharing remain governed separately. | Pending |

## Gmail, phone, and Profile

| Capability | Mode | Scope and expected result | Controlled checks | Initiative | Installed |
| --- | --- | --- | --- | --- | --- |
| `email.read` | read | Search and open exact authorized Gmail messages and recall saved reviews in private Chat. | [Mail writing] typed/voice service, [Chat] saved review, [Mail review] browser | Separate consented Gmail inbox worker and saved Chat delivery. | Pending |
| `email.send` | write | Compose, review and verify a send, or use the enabled allowlisted Auto Send route. | [Gmail] native fixture, [Mail writing], [Mail specialist] browser | Explicit review or opted-in allowlisted send policy. Active reply work remains private. | Pending |
| `email.followups` | write | Create or stop scoped watches and deliver the next exact message to the originating Chat once. | [Gmail], [Mail follow-up] native fixture | Separate Gmail watch worker with current grant and expiry. | Pending |
| `phone.numbers.read` | read | Read only current number assignments for the owner or exact Agent. | [Chat], [Phone read] service | Foreground only. | Pending |
| `phone.sms.read` | read | Read authorized SMS threads without changing them. | [Chat], [Phone read] service | Foreground only. | Pending |
| `phone.sms.send` | write | Open native approval and verify the reviewed send through the configured phone path. | [SMS] native service fixture. Chat's boundary is the approval handoff. | Native approval and provider policy. No Initiative sender mapped. | Pending |
| `profile.webmcp.read` | read | Inspect exact owned Profile tools without creating a handoff. | [Release and Profile] HTTP | Profile visitor and call evidence are separate. | Pending |
| `profile.webmcp.handoff` | write | Prepare a signed Profile review link for the exact conversation. | [Release and Profile] HTTP | Explicit handoff with the native approval surface. | Pending |
| `profile.webmcp.return.read` | read | Verify the exact returned action against the current server ledger. | [Release and Profile] HTTP | Related Profile follow-through evidence. No replay of the transaction. | Pending |

## Music, media, and viewing

| Capability | Mode | Scope and expected result | Controlled checks | Initiative | Installed |
| --- | --- | --- | --- | --- | --- |
| `tv.history.read` | read | Read stored TV observations with capture off. | [Chat], [TV] service | Foreground stored read. | Pending |
| `tv.preferences.read` | read | Separate confirmed viewing preferences from observed content. | [Chat], [TV] service | Foreground stored read. | Pending |
| `tv.capture.manage` | write | Start or stop capture in the Eyes surface with browser approval. | [TV browser] capture fixture | Native capture surface. | Pending |
| `media.library.read` | read | Read authorized HomeServer Media Server status and search results. | [Chat], [Media reads] service | Foreground only. Playback is separate. | Pending |
| `music.library.read` | read | Read authorized HomeServer Music Server status and search results. | [Chat], [Media reads] service | Native VP3 song history is separate. With owner opt-in, [qualified VP3 listening] can trigger public artist research through the shared specialist. | Pending |
| `media.assets.read` | read | Return scoped private Cloud asset metadata and references. | [Chat], [Images] service | Product and Workbench evidence use explicit media references. Image contents need their own permission. | Pending |
| `images.generate` | write | Start an authorized, metered job and verify the saved private canvas output. | [Chat] job and canvas contract, [Images] controlled provider | Reviewed Workbench can prepare image work. Provider calls retain existing approval and quota. | Pending |
| `photos.library.read` | read | Read authorized HomeServer photo and album metadata. | [Chat], [Media reads] service | Foreground read. | Pending |
| `downloads.jobs.read` | read | Read bounded HomeServer download jobs and distinguish queued from complete. | [Chat], [Media reads] service | Foreground read. | Pending |
| `media.processing.read` | read | Read HomeServer processing job status and recorded output readiness. | [Chat], [Media reads] service | Foreground read. | Pending |
| `video.projects.read` | read | Read HomeServer video project metadata. Editing and rendering use the native editor. | [Chat], [Media reads] service | Foreground read. | Pending |
| `youtube.search` | read | Search permitted embeddable results and select exact videos or playlists in Chat. | [YouTube Chat] service, [Chat] cross-Chat selection, [YouTube browser] | Qualified activity can lead to sourced artist or topic research and verified playlist preparation. | Pending |
| `youtube.history` | read | Recall consented plays recorded in VP3 and distinguish them from searches. | [YouTube Chat], [YouTube activity], [YouTube browser] | Scoped activity can inform Initiative. Watch-time capture has its own setting. | Pending |
| `music.releases.read` | read | Read owned Music Workspace releases, items and deadlines. | [Release and Profile] HTTP | Foreground read. | Pending |
| `music.releases.create` | write | Save an exact internal release plan and verify readback. | [Release and Profile] HTTP | Explicit workspace write. Distribution and public release remain separate. | Pending |

## Observations and analytics

| Capability | Mode | Scope and expected result | Controlled checks | Initiative | Installed |
| --- | --- | --- | --- | --- |
| `tracky.context.read` | read | Read the exact authorized site or entity and distinguish current, stale, and missing observations. | [Chat], [Tracky] service | Related provisional Eyes evidence may enter the live-context adapter. | Pending |
| `tracky.perception.request` | write | Request active sensing explicitly through the approved HomeServer route. Verify observation separately from request acceptance. | [Tracky] governed transport fixture | Explicit user action and sensing consent. | Pending |
| `radar.read` | read | Read bounded recent or high-risk records and saved Gateway policy. | [Chat], [Radar] service | Related Profile attention is separate. No general background Radar reader mapped. | Pending |
| `radar.gateway.manage` | write | Save the exact requested scoped policy and verify persistence. External enforcement requires its own evidence. | [Chat], [Radar audit] | Explicit governed policy write. | Pending |
| `analytics.read` | read | Read owned property metrics with time window, timezone, and coverage. | [Chat], [Analytics] service | Direct background Analytics reader is outside this binding. Exact promotion attribution has a separate workflow. | Pending |
| `screen.context` | read | Discuss a current observation from an explicitly shared screen. | [Screen API], [Screen browser] | Screen context can feed the live-context adapter with opt-in. | Pending |
| `live.context.read` | read | Read current screen, Eyes and Ears observations supplied to this exact conversation. | [Live context], [Live pipeline] | Direct bounded adapter. Observations supply context, while permissions and identity remain separate. | Pending |

## Apps, HomeServer, and feeds

| Capability | Mode | Scope and expected result | Controlled checks | Initiative | Installed |
| --- | --- | --- | --- | --- | --- |
| `system_apps.manage` | write | Prepare and confirm a supported action for the exact app. Separate queued, committed, and unverified outcomes. | [App actions] service, [Chat] confirmation and replay | Explicit owner review. | Pending |
| `system_apps.release.read` | read | Read catalog release metadata and verify current installed release when requested. | [Chat], [App actions] service | Foreground read. | Pending |
| `system_apps.catalog.read` | read | Read canonical Cloud catalog and timestamped install projections. | [Chat], [System Apps] service | Foreground read. | Pending |
| `system_apps.health.read` | read | Read stored health and age. Live hosting and TLS diagnostics are separate. | [Chat], [System Apps] service | Foreground read. | Pending |
| `system_apps.permissions.read` | read | Verify current permissions for the exact owned installed app. | [Chat], [System Apps] service | Foreground read. | Pending |
| `apps.control.read` | read | Inspect the exact HomeServer app's declared controls. | [Chat], [App actions] service | Foreground read. | Pending |
| `apps.shares.read` | read | Read current owner share history and exact installation provenance. | [Chat], [App shares] service | Foreground read. | Pending |
| `apps.user.read` | read | Read the authorized HomeServer user-app registry. | [Chat], [Home apps] service | Foreground read. | Pending |
| `apps.manager.read` | read | Read App Manager status and bounded counts. | [Chat], [Home apps] service | Foreground read. | Pending |
| `apps.workspace.read` | read | Read diagnostics for an exact owned app workspace. | [Chat], [Home apps] service | Foreground read. Canvas build is a separate capability and job path. | Pending |
| `homeserver.read` | read | Read only an allowed paired operation, with source provenance and accurate offline outcomes. | [Chat], [Home reads] service | Direct HomeServer operations are excluded from background Knowledge search. Worker transports have separate allowlists. | Pending |
| `homeserver.status` | read | Verify the paired registry and currently allowed operations. | [Home registry] service | Worker checks its own execution scope. User-facing read remains foreground. | Pending |
| `feeds.vrss.read` | read | Read bounded feed or social state through authorized paired HomeServer calls. | [Chat], [Feed read] service | Foreground read. | Pending |
| `feeds.vrss.manage` | write | Confirm supported feed or social actions and verify the exact returned phase. | [Chat], [Feed outcomes] service | Explicit owner approval. A queued refresh or pending delivery needs separate outcome evidence. | Pending |

## Related app flows tracked separately

These are part of the service outline. Their acceptance should stay visible even when they have no separate ID among the 69 rows.

| Area | Required proof |
| --- | --- |
| Meetings and appointments | Ask about the next meeting in typed and spoken Chat and verify the same exact calendar occurrence, agenda, open commitments and current access to the prior linked transcript. Verify the proactive assessment in the 30 minutes before start and privacy behavior when a prior transcript is unavailable. Request a task or timed reminder from an exact finding, review, confirm, and verify one canonical result with source provenance across reload and retry. Repeat with Initiative off and with a prior Chat under the same Agent. Review linked meeting rescheduling, cancellation and agenda additions; check source edits, permission revocation and ambiguity before approval. Confirm that invitation or outbound-notice requests stay in the native meeting editor and never silently become local-only edits. After the meeting, use authorized transcripts and reviewed follow-ups. See [meeting scope]. |
| Commerce, campaigns and rewards | Trace an exact listing, related media, private promotion, owner review, published Profile projection, and attributable outcomes. Track order and refund follow-up, campaign attention and reward expiry separately from merchant management and payment. See [product launch], [promotion], and [Catch-up]. |
| Profile Agent | Trace a live visitor session, owner handoff, confirmed call readiness, and the authorized continuation. Native visitor attention and transactional Profile handoffs have separate contracts. See [Profile call follow-up] and [Profile plan]. |
| Canvas and editors | Trace an exact request or approved suggestion to its private versioned Canvas folder, job, output, and safe reopen. Keep media edits and public activation in their existing review surfaces. See [Canvas jobs] and [stem editor scope]. |
| Connected Sites | Verify the current paired-site grant and declared operation for every provider. The [Chat] suite exercises scoped Gelato typed and stream reads and their retention limit. Background remote reads or writes need their own permission, retention, and delivery evidence. |
| Native app surfaces | Keep hosting, plugins, admin, CRM, Team workspaces, and billing in the [workspace navigation] inventory. Opening a native page is distinct from proving an Agent tool for each operation. |

Run `python3 tools/check_agent_capability_inventory.py` to check that every foreground binding has exactly one tracking row. The existing Recovery Baseline workflow runs this checker.

[foreground inventory]: ../includes/agent-capability-awareness-v1.php
[shared contract]: ../includes/agent-capability-contract-v1.php
[Initiative catalog]: ../includes/agent-initiative-tools-v1.php
[Contract]: ../tests/agent-capability-contract-v1.php
[Chat]: ../tests/agent-capability-chat-api.mjs
[Release and Profile]: ../tests/agent-release-profile-chat-api.mjs
[Action HTTP]: ../tests/initiative-actions-e2e.mjs
[Voice setup]: ../tests/chat-voice-enrollment-browser.mjs
[Catch-up]: ../tests/agent-catch-up-v1.php
[Meeting prep]: ../tests/agent-catch-up-meeting-prep-v1.php
[Next meeting prep]: ../tests/agent-calendar-meeting-prep-v1.php
[proactive meeting prep]: ../tests/agent-initiative-meeting-prep-pipeline-v1.php
[Meeting action source]: ../tests/agent-meeting-action-sources-v1.php
[Meeting actions HTTP]: ../tests/meeting-chat-actions-api.mjs
[Meeting management]: ../tests/agent-meeting-management-mysql.php
[Meeting workflow boundary]: ../tests/agent-meeting-workflow-boundary-mysql.php
[Meeting review UI]: ../tests/meeting-chat-action-review-browser.mjs
[Profile call follow-up]: ../tests/agent-catch-up-profile-calls-v1.php
[Lists]: ../tests/task-lists-v1.php
[Office actions]: ../tests/agent-initiative-office-actions-v1.php
[Records]: ../tests/agent-record-tools-v1.php
[Reminder API]: ../tests/agent-reminders-api.mjs
[Calendar]: ../tests/calendar-recurrence-v1340.php
[Schedule actions]: ../tests/agent-initiative-schedule-actions-v1.php
[Scheduling]: ../tests/scheduling-receipts-v1.php
[Office audit]: agent-integration-audit-s2.md
[Workflow]: ../tests/workflow-receipts-v1.php
[Knowledge]: ../tests/knowledge-agent-context-v162-contract.php
[Initiative pipeline]: ../tests/agent-initiative-tools-pipeline-v1.php
[qualified VP3 listening]: ../tests/agent-initiative-native-music-v1.php
[Transcript]: ../tests/transcript-agent-v1.php
[Transcript Initiative]: ../tests/agent-initiative-transcripts-v1.php
[Transcript actions]: ../tests/transcript-brain-v1.php
[Transcript analysis]: ../tests/transcript-agent-intelligence-v1.php
[Participants]: ../tests/participant-conversation-v2.php
[Participant setup]: ../tests/chat-participant-setup-v2-browser.mjs
[Mail writing]: ../tests/agent-connection-email-writing-v1.php
[Mail review]: ../tests/gmail-inbox-initial-ui-browser.mjs
[Gmail]: ../tests/agent-connections-gmail-v1.php
[Mail specialist]: ../tests/chat-email-specialist-browser.mjs
[Mail follow-up]: ../tests/agent-connection-email-followup-v1.php
[Phone read]: ../tests/phone-agent-read-v1.php
[SMS]: ../tests/phone-sms-ph3b-runtime.php
[TV]: ../tests/tv-watching-v1.php
[TV browser]: ../tests/tv-watching-browser.mjs
[Media reads]: ../tests/agent-media-read-v1.php
[opportunity source]: ../includes/agent-initiative-opportunity-sources-v1.php
[Images]: ../tests/agent-image-generation-v1.php
[YouTube Chat]: ../tests/agent-connection-youtube-chat-v1.php
[YouTube activity]: ../tests/agent-connection-youtube-activity-v1.php
[YouTube browser]: ../tests/chat-youtube-playback-browser.mjs
[Tracky]: ../tests/agent-tracky-read-v1.php
[Radar]: ../tests/agent-radar-read-v1.php
[Radar audit]: agent-integration-audit-s10.md
[Analytics]: ../tests/agent-analytics-read-v1.php
[Screen API]: ../tests/agent-screen-share-api.mjs
[Screen browser]: ../tests/agent-screen-share-browser.mjs
[Live context]: ../tests/agent-live-context-v1.php
[Live pipeline]: ../tests/agent-initiative-live-context-pipeline-v1.php
[App actions]: ../tests/agent-app-operations-v1.php
[System Apps]: ../tests/agent-system-app-read-v1.php
[App shares]: ../tests/agent-private-share-read-v1.php
[Home apps]: ../tests/agent-app-read-v1.php
[Home reads]: ../tests/homeserver-read-receipts-v1.php
[Home registry]: ../tests/homeserver-capability-registry-v033.php
[Feed read]: ../tests/agent-vrss-read-v1.php
[Feed outcomes]: ../tests/agent-vrss-outcomes-v1.php
[meeting scope]: calendar-meeting-agent-review-v1340.md
[product launch]: ../tests/initiative-product-launch-v1.php
[promotion]: ../tests/initiative-marketing-promotion-v1.php
[Profile plan]: PROFILE_AGENT_CHAT_BUILD_PLAN.md
[Canvas jobs]: ../tests/coding-canvas-jobs-v1.php
[stem editor scope]: STEM_AGENT_TOOL_MATRIX_V128.md
[workspace navigation]: ../includes/member-navigation.php
