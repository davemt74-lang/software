# Profile Agent, notifications, Radar and offsite tracking review

Scope: `davemt74-lang/software`, based on Cloud main `2a87ef9a98621751ab0a1dacc851b368d74c2942`. This is a source and executable-fixture review of the Profile Agent and its integration paths. Live hosting configuration, customer credentials and installed third-party-site snippets were not accessed.

## Regressions corrected

1. **Admin notifications were a page-load snapshot.** `admin/admin.js` now refreshes the existing authenticated notification endpoint every 15 seconds, on visibility return and when opening the bell. It updates the existing dropdown/badge, supports subdirectory installations, renders visitor text as text, and retains owner scoping.
2. **Later visitor messages had no new-message event.** The shared public service now emits the existing `message_received` domain event after a visitor turn is saved, inside its transaction. It creates an owner notification/attention item keyed to that stored message, including during owner takeover. The empty conversation-start prompt is superseded by the actual message. Native profile chat and connected-site WebMCP use this same service.
3. **Presentation ownership removed the durable Chat handoff.** Cognitive Presentation had disabled the old attention writer/poller, while its active-user path spoke notifications and its digest required an idle period. It did not persist these profile alerts as ordinary assistant turns. The current presentation owner now schedules a profile/Radar-only handoff through the existing canonical notification-to-Chat writer. Other attention stays with Cognitive Presentation.
4. **The public service could skip the LLM entirely.** A missing lexical knowledge match previously selected a fixed owner-escalation reply. Public conversation now reaches the existing stateless HomeServer inference route or the existing Cloud provider engine even when only public identity/rules are available. An owner-configured greeting remains supported. Actual provider/quota failures are explicit handoffs, rather than retrieval prose presented as a model answer.
5. **Cloud public inference inherited a private-chat prompt.** Public Profile Agent turns now carry a server-derived owner/Agent identity and a dedicated public prompt. They do not read the owner's private Brain style or private tool catalog. Ordinary Chat prompting and credential resolution are unchanged.
6. **Connected-site accounting depended on the HTTP route name.** Public inference now passes the explicit `profile_agent` usage scope, so native and offsite requests share owner accounting. Both provider request builders honor the output cap returned by the owner's quota reservation for this public route.

## Delivery and recovery behavior

- Visits and new visitor messages become persisted, actionable priority assistant turns in the active main Agent Chat canvas, with a link to the exact visitor conversation or visitor view. They use the existing privacy-aware visitor descriptor.
- New-message alerts rank above routine profile activity. Routine crawlers remain Brain activity; actionable AI visits and security notifications can use the priority handoff.
- The browser acknowledges a notification only after confirming that its canonical Chat turn is visible. Failed rendering/network requests retry. The writer serializes by owner and reuses an existing turn after a lost acknowledgement; two browser tabs cannot create duplicate turns for the same owner/Agent namespace.
- A scoped scan cursor advances only over examined rows, avoiding loss when a backlog exceeds one response page. Existing pending/seen attention entries with missing notification projections are repaired in bounded batches, retaining their original timestamps. Priority recovery is limited to recent activity (seven days).
- Handled and ignored attention does not reappear as a new priority. Active snoozes remain deferred; an expired snooze can wake even after newer notification IDs have been scanned.
- An unsent draft or initial conversation restore defers presentation. The same active conversation is synchronized without reopening it. Spoken delivery uses the existing voice transport and canonical attention policy; private/sensitive content, focus/interruptibility and voice preference remain subject to that policy. The digest does not speak the same profile item a second time.
- Notifications remain owner-scoped. An administrator's bell does not grant access to every other member's profile conversations. Self-visits are still excluded; undisclosed visitors remain anonymous/pseudonymous.

## Existing inference and knowledge systems retained

| Capability | Existing implementation and authority |
| --- | --- |
| Public identity, knowledge and catalog | `profile_agent_context()` retrieves owner-controlled records using the Profile Agent principal, audience policies and per-Agent rules. Publicly published items still require the applicable Profile Agent sharing policy. Explicit denial and visitor relationship restrictions are enforced. |
| Public HomeServer knowledge | The same context builder includes only separately enabled datasets allowed for the visitor. Contacts, files and memory are not automatically public. |
| HomeServer model compute | `homeserver-profile-agent-v235.php` requires the paired owner's advertised `agent.infer.local` capability to be stateless, local-only, caller-context-only and tool-disabled. It sends the already approved context, not the private HomeServer Agent Chat context. |
| Cloud model/key selection | `ai_generate_chat_response()` selects the owner's existing configured provider/BYOK route. The visitor's account/key never becomes the billing principal. |
| Token allowance | System-funded Cloud inference uses the existing subscription reservation, usage commit and release functions against the profile owner. Exhaustion blocks the provider call. BYOK and local HomeServer compute retain their existing billing rules; local inference does not spend platform API tokens. |
| Visitor session boundary | Owner + Profile Agent + visitor session authorize public conversation access. Owner takeover is checked again after model generation. Public visitors do not gain owner management tools. |

## Agent Radar and connected-site findings

| Surface | What is already built | Practical limits |
| --- | --- | --- |
| Native profile Radar | Automated requests are classified into owner-scoped Agent CRM contacts, coarse 30-minute sessions, risk/activity events, Brain memory and notifications. Human profile visits stay in the separate human visitor system. | User-Agent signatures are a classification signal, not cryptographic identity verification. Unknown automation is kept separate from human CRM. |
| Browser Radar snippet | Connected-site UI generates the install snippet, registered property key and collector URLs. Origin/domain, active-property, payload and per-session limits apply. | It only sees requests that execute JavaScript and match maintained signatures. It cannot discover every crawler or agent. |
| Server-side Radar/Gateway | A generated PHP integration sends likely automation to the token-authenticated collector. Tokens can rotate/revoke, remain server-side and are stored hashed. The existing gateway returns allow/monitor/block decisions; its generated integration fails open on collector failure. | It requires installation on each connected site. Installing only the browser snippet does not provide non-JavaScript crawler coverage. |
| Human website analytics | `vp3-analytics.js` records page views, SPA route changes, referrer hosts and optional custom/conversion events. Browser-session identifiers are property-bound and time-bounded when stored. Automated traffic is excluded from the human writer. | Sessions and page views are not unique people. Browser blocking, missing snippets and disabled JavaScript can reduce coverage. Public browser telemetry is not authenticated proof of identity or a billing-grade event. |
| Main Chat analytics/Radar | `api/chat.php` invokes the existing Radar tool, which delegates analytics intents to `vp3_analytics_chat_tool()` and answers from owner-scoped dashboard state. Radar access/policy operations also already exist. | These tools can summarize collected data; they cannot infer visits to an unconnected website. |
| Connected-site WebMCP | The existing manifest/tool router supplies public Profile Agent chat, scheduling and commerce through origin/session-bound grants and server policy checks. | These are public capabilities, not unrestricted access to private owner data or tools. |

The application collectors normalize paths and strip query strings/fragments; default analytics fields do not persist raw IP addresses or raw User-Agent strings. This does not describe the host's separate HTTP access logs or arbitrary labels a connected site elects to send.

`verified_at` can be populated by successful collection. It should be read as collection readiness, not independent proof that a registrant controls a domain. Likewise, a registered public install key and an Origin header are not cryptographic visitor authentication.

## Validation

- Real SQLite persistence with production Profile Agent/notification/attention services: visit creation, successive messages, owner takeover, priority Chat persistence, exact conversation actions, owner/session isolation, rollback, retry de-duplication, ledger repair, privacy and a 65-item backlog.
- Executed production browser functions: admin polling/rendering, subdirectory URLs, malicious display text, duplicate suppression, draft/restore deferral, visibility cleanup and failed canvas acknowledgement.
- Real policy/retrieval service with mocked inference transports: public/private/other-owner knowledge, per-Agent denial, HomeServer sharing revocation, local routing, Cloud owner scope and quota handoff.
- Real Cloud provider request builders with HTTP fixtures: Claude/OpenAI key and model preservation, public prompt isolation, explicit owner scope, output cap, quota rejection before HTTP, reservation release and existing BYOK billing rules.
- Executed Radar/offsite boundary functions: domain/origin checks, query stripping, token rotation/revocation validation, owner/property scoping and automated/human separation. Existing Radar, Gateway, analytics, public-profile and HomeServer parity checks remain in the validation suite.

The remaining deployment check is to exercise a real visitor session against the installed release and each connected site's actual snippet/server integration. No live provider or real visitor delivery is claimed from the fixtures above.
