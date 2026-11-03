# S9A — Photo and media reads in Chat

The existing Photo Library, Download Manager, Media Processor and Video Editor had legacy Chat handlers, but malformed responses could become zero counts, failures could fall through to a general answer, and inspecting a Video Editor manifest could imply that an edit had been executed. These four handlers now use one bounded read adapter and the shared model-selected capability dispatcher.

The four capabilities are `photos.library.read`, `downloads.jobs.read`, `media.processing.read` and `video.projects.read`. Photo reads support status and search. Other reads support status and a bounded list of returned jobs or projects. Optional query filtering never silently disappears from a status request. Capability questions perform no network or record read. General conversation and actual edit/download/render requests keep their separate existing action paths.

Before and after each remote read, the adapter verifies the active account, owner Agent and conversation, including reassignment during a slow request. Raw read projections are validated before legacy default values can hide missing fields. App-control reads require the canonical app/action receipt, and Video Editor identity is checked when supplied. Only fixed read operations are accepted. Returned titles are evidence, never tool instructions. Raw settings, paths, URLs and remote error text are excluded from Chat. Reported queue status and output readiness remain separate; a queued job is never reported as completed.

No camera, rescan, download, conversion, render, installation, permission change or HomeServer code publication is introduced. The existing paired HomeServer services remain the authority for those libraries and jobs. Cloud media assets and image generation are the next section and will use the existing private Cloud media store.

## Acceptance and score

Scoped automated acceptance target: **10/10**, requiring all ten gates: (1) shared model selection, (2) read-only discovery, (3) strict raw response validation, (4) owner isolation, (5) active Agent/conversation checks, (6) late-revocation fencing, (7) bounded output without raw paths/settings, (8) honest job/failure states, (9) all typed/streaming/legacy endpoint regressions, and (10) green PR checks with an exactly verified merged production package. Local tests alone do not complete gate ten or certify live HomeServer availability.

The new domain suite covers 81 cases. The production endpoint journey adds all four capabilities across typed, streaming voice and legacy entry points, including explicit remote failures. Existing media, capability, app-control and full runtime suites remain release gates. No database upgrade is required for this section.
