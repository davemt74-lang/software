# Capability awareness — Section 2

The LLM classifies owner requests as capability inspection, an unassessed feature, or continuation to the existing tool/Chat paths. There is no phrase-based capability router. The returned operation and IDs are strictly validated. Discovery is read-only: it cannot submit SQL, run code, invoke a mutation, expand scope or change permissions. Mixed capability/action questions inspect first.

The same broker runs before reminder and legacy routing in canonical typed/spoken Chat, streaming voice and legacy Chat. Ability questions do not enter a reminder creation flow. A provider failure or malformed plan returns a no-action result. Actual action requests continue to existing executors and their confirmation checks. A fresh user/agent/conversation check runs after inference, and again after an explicit HomeServer query.

Explicit HomeServer inspection reuses the authenticated registry with forced refresh and reports only known operations intersected with the current app permissions. General inventories leave HomeServer unchecked, avoiding an unsolicited network call. Raw remote strings, filesystem paths, credentials and unknown operations are excluded.

Compute privacy: the discovery inference runs only when the existing runtime selects ready VP3 Cloud inference. HomeServer-routed and blocked turns retain their existing routes, with no extra Cloud model call. Thus this build adds a normal metered model classification to Cloud-routed Chat turns. HomeServer conversational behavior remains on its existing implementation until a separate local adapter is released. No HomeServer publication.

Readiness now checks personal Knowledge permission separately from database schema: a missing schema is not reported as revoked permission. Project and folder scopes remain narrow.

Validation: 26 snapshot cases, 28 discovery/validation cases, and 12 HTTP cases through production typed, spoken, streaming and legacy endpoints. The HTTP test uses production discovery, permission boundaries, ownership checks and reminder saving with controlled infrastructure/provider fixtures; it verifies capability questions save nothing and a concrete reminder request saves exactly once. Live configured-model interpretation and microphone behavior remain deployment tests. No migration.
