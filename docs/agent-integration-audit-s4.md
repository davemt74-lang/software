# Integration audit S4: TV history and HomeServer media reads

## Actual gaps and resulting behavior

The TV history dispatcher, Media Server dispatcher and Music Server dispatcher were outside the verified inventory. The media handlers caught remote failures and returned unhandled, allowing an explicit local request to drift into another answer. A failed media search could also produce library totals rather than a failed-search result. Missing response fields defaulted to zero or empty arrays. Broad music/movie words could probe HomeServer during ordinary conversation, and play requests only searched without acknowledging playback.

The existing shared capability model now selects strict `media_read` plans for explicit saved TV history/preferences and HomeServer Media/Music library reads. There is no additional classifier call. Known capability IDs, bounded title queries, whitelisted TV periods, library status/search and media types are validated on the server. Status requests cannot silently discard search filters. Ability questions inspect without reading records or contacting HomeServer. General music/TV discussion and public catalog requests continue normally.

TV recall reuses canonical current account records, time filters, evidence links, corrections/deletions and explicit preferences. It works with Eyes/camera off and never starts capture or writes history. Account-owned observations are not per-Agent memories: every authorized owner Agent may recall the owner's TV records, while Profile visitors cannot use these owner tools. An observed screen is not proof of personal viewing; repeated confirmed viewing is not an explicit preference. Capture remains on the existing Eyes/browser approval surface.

Media reads reuse the existing paired System Apps transport and app-control service. Music responses retain canonical app/action identity validation. Missing/malformed result arrays, invalid counts and remote failures produce failed receipts rather than empty libraries. Valid empty searches, successful zero-count status and denied operations remain distinct. Results contain only bounded library projections, never raw remote status, paths, credentials or source media bytes. Owner/account, current Agent and conversation authority are rechecked before and after a slow read, so late revocation or conversation replacement discards returned records.

Legacy entry points use the same authorized media read adapter and no longer claim a search means playback started. The TV legacy Chat dispatcher also rechecks authority and emits shared execution evidence. These read receipts persist through the existing typed, streaming and legacy Chat paths and support the existing “Did that work?” diagnosis.

## Playback boundary and next phase

This section does not add a browser player command or claim that read access can start, pause, skip or change volume. The existing media handlers previously offered only a library search and an Apps navigation link. Browser playback needs an explicit command tied to the active player, followed by actual client acknowledgement/failure and durable diagnosis. That is S5; it must reuse the existing player and authorization instead of treating a server search or queued action as successful playback.

## Acceptance and remaining scope

Local evidence: 44 actual SQLite/TV, canonical app-control, strict-plan and live owner/Agent/conversation tests; 154 production Chat endpoint cases across typed, streaming and legacy paths using a controlled routing model; original 54 TV lifecycle/security cases and the recovery baseline. Required PR gates and exact merged production package verification complete certification. Live paired HomeServer/media behavior remains deployment acceptance.

The source inventory now covers 28 capability IDs and 8 of 29 legacy dispatchers; 21 dispatchers remain outside this assessed inventory. The next playback section does not certify those unrelated integrations. No HomeServer source is changed or published and no schema migration, capture permission, app installation or folder grant is introduced.
