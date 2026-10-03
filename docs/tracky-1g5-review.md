# Agent Eyes 1G5 — Cloud acceptance report

The existing authenticated, plugin-scoped Agent Eyes status endpoint now offers a redacted JSON download. The shared Tracky/device/Agent card links to it and directs owners to the companion HomeServer installed acceptance checklist. Agent replies carry the same guidance.

The report uses the canonical account/site-filtered status read and preserves its coarse connection, scene state, original observation time and last received consent. It retains no scene meaning, identity, camera media, device identifiers or owner exercise receipts. Cloud explicitly cannot verify installed camera exercises or know unsynchronized local consent. Downloads never capture, renew consent or enable sharing.

Existing PHP/JavaScript Recovery, InnoDB account/site isolation and production packaging gates remain authoritative. No schema migration or scene protocol change is required; use the normal upgrade.php deployment path. Final software acceptance requires green exact-head CI, merge and inspection of the merged-main deployment archive.

On Windows HomeServer, complete current camera/model review, existing Stop/privacy/presence exercises, local Agent Chat, a restart checkpoint/reset and optional Cloud delivery/original expiry/revocation/disconnect/reconnect exercises. Download both reports and investigate pending checks. Owner reports and synthetic CI do not independently certify hardware. Publisher Authenticode signing is not added by this section.
