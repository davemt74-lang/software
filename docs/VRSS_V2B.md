# VRSS V2B — Reader and Agent integration

Feed 1.3.0 continues to publish through existing Hosting, subdomain and TLS bindings. HomeServer owns discovery, subscriptions, cached posts, refresh schedules and notification receipts.

Cloud Agent Chat recognizes “follow https://example.com/”, “refresh my feed”, “show new VRSS posts” and “show VRSS subscription health”. Follow and refresh use existing conversation-bound System App confirmation codes. Reads use the universal app-control bridge; writes execute only on HomeServer after confirmation. HomeServer checks current pairing and Cloud-export scope before execution and before Feed database transactions finish.

Existing enabled workspace synchronization carries eligible HomeServer notification rows. After an account-authenticated, session-locked notification snapshot commits, VRSS new-post events project into existing Cloud Notifications and the Agent Chat informational pipeline. Full source event digests provide stable dedupe identity. HomeServer read/dismiss/archive receipts mark the corresponding Cloud event read. Replayed packets and snapshots cannot duplicate a native notification. Unsupported follower events are ignored. Previously delivered notifications remain part of their owner's history.

This requires existing workspace sync and HomeServer notification export permissions; there is no new background Cloud service, public inbox or remote identity claim. The first subscription import establishes a quiet baseline. Later new posts create informational updates; they do not force a decision prompt. Existing cognitive presentation and voice preferences control Cloud delivery.

New-follower notifications remain unavailable until verified identities and authenticated remote follows exist. RSS fetches do not prove who follows a publisher.

Validation: existing MySQL System Apps tests cover confirmed follow preparation, execution, exact confirmation replay, read-only status/posts, refresh intent and continued Hosting routing. Existing SQLite/MySQL workspace tests cover actual committed notification snapshots, dedupe, read receipts, ignored follower payloads and revoked-session rejection. HomeServer service/API/browser and Windows executable/installer certification gate coordinated deployment.

Deploy the Cloud ZIP and run existing upgrade.php, then install the matching HomeServer build. The included catalog upgrades its Feed entry to 1.3.0; HomeServer upgrades known bundled Feed versions while preserving deliberately stopped state and local app data.

V2C follow support supersedes this release's authenticated-follower limitation. See [VRSS_V2C.md](VRSS_V2C.md) for Feed 1.4.0, signed follows, new-follower notice delivery and acceptance boundaries.
