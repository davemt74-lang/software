# Private images in Chat

Image Generator uses the existing owner Chat, private Media Library, governed AI service and canonical job engine. Typed Chat, streaming voice and the legacy Chat endpoint share the same generation path.

## Chat canvas

Generated-image posts have a clean, transparent outer surface. Their old model/options display and full-size, download, listing, marketing and editor button row are removed. Existing searched-photo workflows remain available.

Selecting an image opens a viewer inside the Chat canvas, with previous/next navigation, thumbnails, keyboard navigation and touch swipes. Back or Escape restores the conversation and keyboard focus. The composer remains available. Chat, Agent, account or participant changes withdraw old content and reject late responses.

Queued, creating, ready, cancelled and failed states come from the saved job. Animation requires a verified active provider lease. Elapsed time uses the actual provider-start timestamp; no fabricated percentage is shown. Reduced motion is respected. Reloading restores the same job and images.

### Multiple images

An explicit prompt can request one to four images:

> Generate three different images of a desert observatory at sunset.

The output count is bound to the owner's words and defaults to one. The classifier cannot silently increase the paid count. A scene quantity such as “an image of three cats” does not request three outputs. One prompt creates one job, one provider request and an ordered gallery. Older single-image configurations without count remain compatible.

### Uploaded base image

The Chat bar's **+** menu includes **Upload base image**, using the existing authenticated chunked upload. Supported bases are JPEG, PNG and WebP, up to 4 MiB and 4096 pixels per side.

> Make this image look like a vintage album cover.

> Create three variations of this image with warmer lighting.

With a base attached, direct visual instructions such as “Add a pirate hat to the character” or “Change the colors to purple and gold” also start an edit. Ordinary conversation, planning and deferred instructions keep the attachment available for the next prompt.

The attachment chip shows the selected source and allows removal. Upload progress reflects acknowledged bytes. Uploading or selecting a source does not itself generate anything. The original remains unchanged; outputs are new private assets.

Source ownership, private path, type, dimensions, size and actual pixels are validated. PNG structure and checksums are checked. The job freezes the exact source hash and revision and rechecks them before provider dispatch and output publication. The current request selects the source; models and old Chat text cannot choose source IDs.

Failed sends, ordinary answers and unverified queue replies retain the attachment. A verified image-queue receipt for the current Chat and Agent consumes it. Per-Chat restoration reauthorizes the source; participant changes clear it. Late uploads cannot attach to another Chat. Streaming and automatic-voice replay bind the exact source revision, prompt, Chat and Agent.

## Image specialist

The existing Image Generator panel contains:

- **Images:** saved generations, available outputs and originating Chat.
- **Prompts:** saved creative briefs, output counts and base-image associations.
- **Brainstorming:** image ideas informed by permitted prompt history and current reports from other specialists.

History belongs to the current owner and Agent, with access rechecked for each originating Chat and job. Opening the panel in a new Chat initializes the conversation while preserving the draft. Image history uses the same in-canvas viewer.

Reusing a prompt or selecting an idea stages text in the composer for review. It never sends automatically or overwrites another draft. Reusing an edit revalidates its original base.

Brainstorming runs only on explicit request through the shared governed text-inference and usage service. It saves the exact request, ideas and source references. Source records are untrusted data. Revised or withdrawn evidence is rechecked before saving results, exposing saved ideas and staging an idea. History reads and brainstorming do not invoke the image provider.

Background history refreshes preserve the direction being typed in Brainstorming, including its caret and scroll position.

## Provider, accounting and storage

Existing encrypted OpenAI configuration and admin image-model controls remain in use. New images use the fixed generations endpoint; selected bases use the edits endpoint. No key reaches the browser or Chat context. The adapter validates TLS, disables redirects/proxies/netrc credentials, and makes one paid attempt without automatic retries.

The conservative output-token reservation is 16,000 times the requested count, plus estimated input. Actual aggregate token usage is settled once; image units reflect confirmed outputs. Entitlement, quota, current Cloud route and account authority are checked before dispatch. Shared-host limits stay at 36 MiB of HTTP response and 25 MiB of decoded output in total.

All outputs must be valid PNGs with requested dimensions. Missing, extra, malformed or partial outputs fail the entire batch. Verified actual usage is retained if storage fails or cancellation arrives after dispatch.

The canonical workflow remains the only queue, lease owner, cancellation mechanism and completion ledger. Admission permits one active image request per owner. Polling, reload and expired-worker recovery cannot replay a consumed provider attempt. When Chat is closed without an available background worker, queued work waits.

Ordered outputs use agent_image_generation_assets_v1; the legacy asset_id points to the first image. Job completion, all assets and one execution receipt commit atomically. Rollback removes new files and prevents partial completed jobs. A bounded image_asset_ids receipt list preserves verified order.

Private pixels use the existing authenticated media route. Prompts, source identity, model/options, dimensions, hashes and actual usage remain in private records. Tables initialize through existing schema paths; Media Studio and durable-job upgrades remain prerequisites.

## Verification and deployment

Tests cover the canonical engine on SQLite and CI MySQL, controlled provider transport, actual private files, production typed/voice/legacy endpoints, batch rollback/accounting, source revision/replay, specialist evidence reauthorization and desktop/mobile flows.

Focused suites:

- tests/agent-image-generation-v1.php
- tests/agent-image-transport-v1.php
- tests/chat-image-composer-v1.php
- tests/chat-canvas-media-api.mjs
- tests/chat-canvas-media-browser.mjs
- tests/chat-image-composer-browser.mjs
- tests/chat-image-studio-v1.php
- tests/chat-image-studio-api.mjs
- tests/chat-image-studio-browser.mjs
- Image cases in tests/agent-capability-chat-api.mjs

Tests make no paid live image calls. Live installations supply their configured provider key, supported model access and funding.

The deployment package includes the runtime modules and APIs, while excluding credentials, local configuration, private media, uploads, tests and tools. Build the release archive from the merged commit after required PR checks pass.

Provider references:

- https://developers.openai.com/api/docs/guides/image-generation
- https://developers.openai.com/api/reference/resources/images/methods/generate
- https://developers.openai.com/api/reference/resources/images/methods/edit
