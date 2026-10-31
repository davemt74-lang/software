# VP3 Agent integration work list

This is the managed build order for the legacy handlers called by the shared VP3 Chat dispatcher. It is not a count of every tool in VP3: reminders, transcripts, phone and newer record adapters also expose capabilities through the shared broker. Handler count and capability count are separate measures. “Pending” means unassessed, not necessarily broken or disconnected.

Before S6: 11 of 29 legacy handlers certified; 18 remaining. S6 implements 3 more, leaving 15 pending. Its release requires the PR checks and exact merged-package verification. The source inventory has 34 individual capability IDs after S6. Use `python3 tools/check_agent_capability_inventory.py` for current executable bindings, remaining handlers and source locations; that check also rejects a missing or duplicated handler in this list.

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
| `vp3_system_apps_agent_query_v150` | S6 | Implemented in this change; read-only canonical catalog and owner observations, no schema/seed side effects. |
| `vp3_system_apps_health_query_v170` | S6 | Implemented in this change; stored health and age versus live verification, no implicit hosting binding. |
| `vp3_system_apps_permission_query_v210` | S6 | Implemented in this change; exact owned/installed app, verified live permission read, no grants/revocations. |
| `vp3_system_apps_agent_action_query_v160` | S7 | Pending: existing approval, action execution and verified outcomes. |
| `vp3_app_control_agent_query_v290` | S7 | Pending: actual app manifest/control scopes, settings redaction and existing confirmations. |
| `vp3_system_apps_release_query_v200` | S7 | Pending: release/update/rollback authority and actual outcome versus request. |
| `vp3_user_app_share_agent_query_v250` | S8 | Pending: private-share identity, provenance, access and lifecycle boundaries. |
| `vp3_vrss_agent_query_v2b` | S8 | Pending: existing feed operations and owned-resource receipts. |
| `vp3_photo_library_agent_query_v310` | S9 | Pending: existing photo discovery and selection/privacy boundaries. |
| `vp3_download_manager_agent_query_v320` | S9 | Pending: download jobs, approvals and status versus completion. |
| `vp3_media_processor_agent_query_v330` | S9 | Pending: processing jobs, source/destination scope and verified results. |
| `vp3_video_editor_agent_query_v280` | S9 | Pending: existing project actions and approval/execution boundaries. |
| `tracky_agent_tools_query_v271` | S10 | Pending: existing observations/continuity, inference limits and current evidence. |
| `vp3_radar_chat_tool` | S10 | Pending: current owner Radar records and reviewed next actions. |
| `vp3_analytics_chat_tool` | S10 | Pending: actual data permissions, query scope and evidence. |
| `release_v105_chat_tool` | S11 | Pending: existing VP3 release module and its workspace permissions. |
| `vp3_agent_profile_webmcp_plan_v193` | S11 | Pending: public Profile Agent, owner-approved tools and external action plans. |
| `vp3_profile_webmcp_return_followthrough_v195` | S11 | Pending: bound return results, stale plans and follow-through. |

The order may be adjusted when a dependency or deployed regression is found; every handler stays on the list. Each section starts with its actual existing service and authority, records concrete gaps, implements only necessary adapters/fixes, runs meaningful storage/permission/transport cases, and merges only after required CI passes. Delivery is the verified merged Cloud ZIP. Live external-provider and paired HomeServer acceptance is recorded separately from controlled-model/network tests.

This work stays in VP3's `software` repository. Stonefellow playback features and source are outside this work list. No separate dashboard, second app registry, new scheduler, silent install, permission grant or HomeServer source publication is introduced by the audit.
