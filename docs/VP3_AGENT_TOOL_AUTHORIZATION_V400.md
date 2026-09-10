# VP3 Agent Tool Authorization & Execution Boundary v4.00

Section 8 makes Agent tool authority a property of the authenticated VP3 principal and the exact resource being touched, not a property of an Agent name, conversation, legacy professional role, package, or plugin.

## Canonical authority chain

For VP3-local Agent tools, authorization resolves in this order:

1. authenticated VP3 user
2. exact target resource
3. canonical Music Workspace ownership/membership when the resource is professional music data
4. contextual workspace capability for that member role
5. resource-specific assignment where required, such as an assigned Producer track
6. server-derived execution policy for any browser action returned to Chat

Global `manager`, `producer`, or `supervisor` account markers and their legacy role-permission rows are not sufficient to authorize another workspace's tracks, stems, listener analytics, shows, releases, or execution surfaces.

## Production tools

Stem Studio discovery and stem search use canonical `workspace_id` ownership and `music_workspace_resources_v330_can_manage_track()`. Owners and Managers operate within their authorized workspace. Producers are limited to tracks assigned to them. Public track visibility is not production authority.

Legacy pre-workspace fallback is deliberately resource-specific and does not restore global professional-role authority.

## Booking and listener intelligence

Booking Agent analytics resolve the set of Music Workspaces in which the current principal may manage shows. Listener-market aggregation and upcoming-show queries are restricted to those workspace ids before aggregation. A global `tracks.manage` permission can no longer turn booking analytics into a cross-workspace query.

Public web venue research remains a read-only external lookup after the workspace gate has succeeded. Research history is user-owned telemetry; it is not authority over a Music Workspace.

## Chat action execution

Agent Chat treats tool action metadata as untrusted until v4.00 rebuilds it server-side.

Only recognized low-risk action shapes are returned as executable Chat actions:

- internal navigation (`open_url`) after URL and resource authorization checks
- browser-local media capture (`media_capture`) with a fixed mode allowlist

External URL schemes and unknown action types are dropped. Incoming `auto` flags are ignored. Auto behavior is derived from the user's explicit current message and the server-approved action class. Opening Stem Studio or Release Calendar is re-authorized against the exact track/workspace before an action is returned.

Browser media capture remains subject to browser/device permission controls. Opening a browser surface is not treated as permission to perform a later server or external side effect.

## Server and external side effects

Low-risk internal VP3 writes that are directly and explicitly requested still require their normal resource/workspace authorization at the server endpoint or tool helper.

External provider work remains owner-scoped and approval-first through the Release Workspace action queue. High-risk/external proactive actions retain `requires_approval` policy.

HomeServer actions are a separate trust domain. VP3 can list and review only approval requests exposed by its paired HomeServer. An approve/deny decision is sent back as `action.approve` or `action.deny`; HomeServer remains execution authority and VP3 does not reproduce those side effects locally.

## Invariants

1. Agent identity never adds user authority.
2. Conversation identity never adds resource authority.
3. Package entitlement and plugin installation do not substitute for workspace authority.
4. Legacy professional roles do not grant cross-workspace Agent tool access.
5. Professional reads and actions are scoped before sensitive data is returned.
6. Producers cannot use Agent tooling on unassigned tracks.
7. Browser action `auto` state is server-derived, not accepted from model/tool payloads.
8. External action URLs and unknown executable action types are rejected from Chat actions.
9. External provider work remains approval governed.
10. HomeServer remains the execution authority for HomeServer approval requests.
