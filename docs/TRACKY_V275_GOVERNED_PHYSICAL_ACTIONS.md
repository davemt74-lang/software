# Tracky V2.75 — Governed Physical Actions & Automation Execution

## Authority model

V2.75 does not give Tracky a new device executor. Physical writes remain owned by OTRO's existing `devices.command` action path.

Canonical flow:

`Tracky event / explicit Agent request → Tracky proposal → existing OTRO suggestion or action request → local HomeServer owner approval → existing device driver → audited outcome`

Cloud never approves a physical action and never calls a device driver.

## Default permissions

VP3's standard HomeServer pairing remains least privilege and does not automatically include `devices.control`.

If Cloud asks for `request_approval` without that separately granted permission, OTRO downgrades the request to `suggest_only` and reports `permission_upgrade_required=true`. No permission is widened automatically.

## Controllable categories

V2.75 inherits OTRO's existing safe-control allowlist: lights, outlets, fans and thermostats.

Cameras, locks, garage doors, security devices, appliances and scenes remain blocked from Tracky control.

## Local event automations

HomeServer owners may create Tracky-event rules that point to existing OTRO local-automation routines. Rules are disabled by default, local-only configuration, replay-safe, confidence-gated, freshness-gated and cooldown-aware.

Existing routine modes remain authoritative:

- `suggest_only` creates a local suggestion.
- `ask_every_time` creates a durable approval request.

Neither mode directly executes a device command.

Safety-like Tracky events are restricted to suggestion-only behavior even if a linked routine normally asks for approval.

## Reconciliation and privacy

HomeServer v2.4 reconciliation remains a hard gate. Perception-derived action proposals are blocked while the privacy switch is engaged. Raw frames/video/audio are never part of the action contract.

## Cloud UX

Governed physical-action proposals are off by default. Enabling the Cloud setting permits proposal creation only. Local HomeServer approval and execution authority are unchanged.
