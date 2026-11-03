# S9B — Private media and OpenAI images on the Chat canvas

Owner Chat can now search private Cloud photos, videos and audio with `media.assets.read`, including generated-only or uploaded-only views. These are the existing `user_media_assets`, separate from the paired HomeServer Photo Library and from recognition samples in Agent Eyes. SQL applies owner, type, source and literal title filters before the bounded twelve-result limit. Metadata retrieval never claims to have visually analyzed a photo.

An explicit new-image request uses `images.generate`. The shared model-selected dispatcher handles typed, streaming voice and legacy Chat. The strict plan accepts only prompt, title, three standard aspect ratios, low/medium/high quality and opaque/transparent background. Capability questions, hypothetical requests, building discussions and ambiguous confirmations do not generate images. Image editing is outside this section.

## Existing provider and accounting

Admin → AI adds an image enable switch and a separate image-model selector under OpenAI. Both use the already encrypted admin OpenAI key. Image generation remains usable when the main conversational provider is Claude. The default is GPT Image 2.5 Flare; the selector also supports Sunburst, ChatGPT Image latest, GPT Image 1.5 and mini. No API key reaches the browser or Chat context.

The adapter uses the fixed OpenAI `POST /v1/images/generations` endpoint with one PNG, an explicit model and no retired `response_format` parameter. Transparency is preserved through `background=transparent` and PNG output. Standard sizes are 1024×1024, 1536×1024 and 1024×1536. It checks TLS, disables redirects, proxies and netrc credentials, bounds the received body and makes exactly one provider attempt. There is no automatic HTTP retry for a paid generation. Returned bytes must be a PNG with valid chunk lengths/checksums, image data and end marker, and the requested dimensions; actual input/output/total usage must agree.

AI entitlement and the existing token quota are checked at execution. The adapter requires a conservative 16,000-output-token reservation plus estimated prompt input, then commits actual provider usage through `subscription_ai_commit_usage`. A preflight failure sends no image request. Failed or unknown provider responses release the temporary reservation without fabricating usage. Actual paid usage is recorded even if cancellation arrives during the provider request. Host/API/model access and available provider credits still require installation acceptance; fixtures do not certify a live key.

Official API references, checked for this implementation:

- https://developers.openai.com/api/docs/guides/image-generation
- https://developers.openai.com/api/reference/resources/images/methods/generate

## Canonical jobs and private storage

The existing workflow/job engine remains the queue, lease owner, cancellation mechanism and completion ledger. One metadata table binds the exact owner, Agent, conversation, saved user-message ID, frozen prompt/options/model and eventual asset ID. Preparing the same user turn cannot create a second generation. Owner admission is serialized; only one image request can remain active per owner. A consumed provider attempt cannot be replayed by reloading, polling, manually retrying the canonical job or recovering an expired worker.

Chat returns a job reference immediately. A separate browser-assisted worker request or the canonical Cloud worker runs generation without holding the ordinary Chat request open. The long worker request releases the PHP session lock. When Chat is closed and no background worker is configured, queued work waits; it does not pretend to be unattended processing. An abandoned single-attempt lease fails without resubmitting the paid POST.

After the response, account/Agent/conversation and lease authority are checked again. A locked canonical run, the private asset insert, generation binding and execution receipt commit in one transaction. The canonical receipt helper now joins a caller transaction when present; ordinary standalone callers retain their prior commit behavior. Rollback removes the newly written file and cannot leave a completed job without its asset. The private PNG uses the existing authenticated media route, never a public image URL. The original prompt, model, options, dimensions, hash and actual usage remain private asset metadata. Existing media events and permitted Agent-scoped file memory receive the saved result after commit.

## Canvas behavior

The production Chat renderer persists `canvas_media` references in the assistant message for both ordinary and streaming responses. Initial history and incremental history loads restore the same references. The media API reauthorizes every asset batch and image-job operation; foreign owner/Agent/conversation selectors cannot retrieve a card or its pixels.

Cards show queued, generating, completed, cancelled, failed or missing-file state. Confirmed images have full-size, download and existing editor links. Titles and prompt previews use DOM text nodes. Canvas media URLs must be same-origin authenticated media URLs. The composer remains usable during generation. A cancelled, revoked or detached conversation cannot publish late content into its old card. Status refresh reads the existing job; it does not generate a replacement image. Users request another image explicitly in Chat.

The metadata table initializes on first generation. Existing Media Studio and durable-job upgrades remain prerequisites; this section does not introduce a separate numbered upgrade. Image files remain under the existing private media path and are excluded from deployment ZIPs.

## Ten acceptance gates

The scoped automated target is **10/10**: (1) shared typed/voice/legacy plans, (2) read-only discovery, (3) existing encrypted admin key and explicit model controls, (4) canonical durable jobs and atomic asset/receipt persistence, (5) single-attempt provider dispatch, (6) current owner/Agent/conversation and late-cancellation checks, (7) validated private bytes and authenticated retrieval, (8) quota and real usage handoff, (9) actual mobile Chat rendering/history/composer behavior, and (10) green PR workflows and exact merged-package verification. Physical microphones and live provider access are deployment acceptance, separate from this score.

Tests exercise the real canonical engine and private media store under SQLite and CI MySQL, production Chat and media APIs, the actual HTTP adapter with controlled cURL responses, and the real Chat renderer in Chromium. No paid live image request is made by the test suite.

Local evidence before PR gates: 63 domain cases on SQLite, 8 production private-media API cases, 404 production Chat endpoint cases, and the controlled production cURL wire suite. CI additionally runs the domain suite on real MySQL, including simultaneous owner admission, and eight mobile browser cases using the actual Chat renderer.
