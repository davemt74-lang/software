# Tracky V2.78 Section 4 — Mobile Transition Runtime

Protocol: `physical_mobile_transition.v1`.

Section 4 models movement between authoritative physical sites without pretending that every gap is a known location.

## States

- `departing`
- `in_transit`
- `arriving`
- `arrived`
- `uncertain`
- `offline`
- `temporary_context`
- `canceled`

A temporary context (for example a hotel room, vehicle, or unknown temporary workspace) is semantic context only. It is **not** automatically promoted into a durable site and never receives site authority.

## Identity boundary

Section 4 gives stable continuity to known mobile hardware such as **Pocket** devices. It can also carry an explicitly user-confirmed continuity subject.

It does **not** infer or merge a person/object identity between two sites. Cross-site identity continuity remains V2.78 Section 5.

## Authority

The transition's **source site** owns the transition state. Its current site-authority device UUID and authority epoch are attached to every Cloud projection.

Cloud validates that authority before accepting a revision. Cloud then mirrors the transition read-only to the explicit destination site's HomeServer. The destination may use the transition for awareness and Agent context, but it cannot re-originate or rewrite the source transition.

## Failure behavior

- lower transition revision → stale, ignored;
- same revision + same fingerprint → idempotent;
- same revision + different fingerprint → conflict, rejected;
- source authority mismatch → rejected;
- mobile device offline → retain the last transition state as `resume_state`, then resume when it returns;
- prolonged ambiguous movement → `uncertain`, never invented arrival.

The Cloud surface is read-only: `GET /api/tracky-mobile-transitions-v278.php`.
