# VP3 Agent integration work list

This is the managed build order for the legacy handlers called by the shared VP3 Chat dispatcher. It is not a count of every tool in VP3: reminders, transcripts, phone and newer record adapters also expose capabilities through the shared broker. Handler count and capability count are separate measures. “Pending” means unassessed, not necessarily broken or disconnected.

Through S7: 17 of 29 legacy handlers assessed. S9A adds the four photo/media handlers and S9B adds private Cloud media search and OpenAI image generation with canvas delivery. S9 was prioritized before S8 for the requested photo/media work; both are merged in PRs #688 and #689. S8A private app sharing is merged in PR #690. S8B assesses feed reads, mutation preflights and recorded outcomes, bringing coverage to 23 of 29 legacy handlers, with 6 remaining. The executable inventory has 52 capabilities; capability count is separate from legacy-handler count. S10 and S11 remain next. Each section requires green PR checks and exact merged-package verification. Use `python3 tools/check_agent_capability_inventory.py` for current executable bindings, remaining handlers and source locations; that check also rejects a missing or duplicated handler in this list.

| Handler | Section | State / evidence |
|---|---|---|
| `user_calendar_agent_query_v1300` | Calendar | Existing canonical reads/mutations and shared receipt/transport checks. |
| `agent_work_control_chat_v173` | S2 | Certified PR #649; canonical owner work queue and precise control receipts. |
| `agent_scheduling_tools_query_v460` | S2 | Certified PR #649; personal scheduling, live authority and bound confirmations. |
| `agent_team_scheduling_tools_query_v610` | S2 | Certified PR #649; Team scheduling, plan/pool scope and bound confirmations. |
| `homeserver_agent_read_v230_query` | S3 | Certified PR #650; authenticated local read provenance and failure diagnosis. |
| `tv_chat_query_v1` | S4 | Certified PR #651; current TV records and preferences, camera-off reads. |
| `vp3_media_server_agent_query_v270` | S4 | Certified PR #651; strict Media Server library reads, no playback claim. |
| `vp3_music_server_agent_query_v300` | S4 | Certified PR #651; strict Music Server library reads, no playback claim. |
| `vp3_user_apps_agent_query_v220` | S5 | Certified PR #652; canonical user-app registry and strict read receipts. |
| `vp3_app_manager_query_v260` | S5 | Certified PR #652; strict manager projections and live authority. |
| `vp3_user_app_workspace_query_v230` | S5 | Certified PR #652; exact app selection and reported diagnostics. |
| `vp3_system_apps_agent_query_v150` | S6 | Certified in PR #653; read-only canonical catalog and owner observations, no schema/seed side effects. |
| `vp3_system_apps_health_query_v170` | S6 | Certified in PR #653; stored health and age versus live verification, no implicit hosting binding. |
| `vp3_system_apps_permission_query_v210` | S6 | Certified in PR #653; exact owned/installed app, verified live permission read, no grants/revocations. |
| `vp3_system_apps_agent_action_query_v160` | S7 | Implemented in S7: durable owner/Agent/conversation/app-selection confirmation, strict remote steps and consumed uncertain outcomes. |
| `vp3_app_control_agent_query_v290` | S7 | Implemented in S7: exact installed app, strict live control declaration and safe bounded evidence. |
| `vp3_system_apps_release_query_v200` | S7 | Implemented in S7: read-only catalog metadata, explicit live-release verification/failure and no raw remote export. |
| `vp3_user_app_share_agent_query_v250` | S8A | Shared model-selected, owner-scoped Cloud share reads; exact historical installation provenance and current account/Agent/conversation checks. See agent-integration-audit-s8.md. |
| `vp3_vrss_agent_query_v2b` | S8B | Shared bounded feed/social reads, current authority, exact moderation/conversation preflights and source-verified action phases retained through confirmation replay and Chat cards. See agent-integration-audit-s8.md. |
| `vp3_photo_library_agent_query_v310` | S9A | Shared model-selected bounded reads, strict raw projections, current owner/Agent/conversation fencing and honest job/failure receipts. See agent-integration-audit-s9.md. |
| `vp3_download_manager_agent_query_v320` | S9A | Shared model-selected bounded reads, strict raw projections, current owner/Agent/conversation fencing and honest job/failure receipts. See agent-integration-audit-s9.md. |
| `vp3_media_processor_agent_query_v330` | S9A | Shared model-selected bounded reads, strict raw projections, current owner/Agent/conversation fencing and honest job/failure receipts. See agent-integration-audit-s9.md. |
| `vp3_video_editor_agent_query_v280` | S9A | Shared model-selected bounded reads, strict raw projections, current owner/Agent/conversation fencing and honest job/failure receipts. See agent-integration-audit-s9.md. |
| `tracky_agent_tools_query_v271` | S10 | Pending: existing observations/continuity, inference limits and current evidence. |
| `vp3_radar_chat_tool` | S10 | Pending: current owner Radar records and reviewed next actions. |
| `vp3_analytics_chat_tool` | S10 | Pending: actual data permissions, query scope and evidence. |
| `release_v105_chat_tool` | S11 | Pending: existing VP3 release module and its workspace permissions. |
| `vp3_agent_profile_webmcp_plan_v193` | S11 | Pending: public Profile Agent, owner-approved tools and external action plans. |
| `vp3_profile_webmcp_return_followthrough_v195` | S11 | Pending: bound return results, stale plans and follow-through. |

The order may be adjusted when a dependency or deployed regression is found; every handler stays on the list. Each section starts with its actual existing service and authority, records concrete gaps, implements only necessary adapters/fixes, runs meaningful storage/permission/transport cases, and merges only after required CI passes. Delivery is the verified merged Cloud ZIP. Live external-provider and paired HomeServer acceptance is recorded separately from controlled-model/network tests.

This work stays in VP3's `software` repository. Stonefellow playback features and source are outside this work list. No separate dashboard, second app registry, new scheduler, silent install, permission grant or HomeServer source publication is introduced by the audit.
