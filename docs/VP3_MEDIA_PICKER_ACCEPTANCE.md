# Shared images and product marketing plans acceptance

## Scope

Build the first layer of the Marketing Promotion specialist around existing Product drafts and Product promotions. Use one embedded image picker in both workbench lanes and in the `/images` Chat popup. Reuse the owner's existing Media Library assets and collections. Preserve the existing source preview, private draft, design review, and publication boundaries.

`/images` offers explicit **Edit in Chat** and **Use in Marketing** actions. Editing attaches the exact existing image as a base and preserves the typed Chat draft. Marketing carries the chosen image into the existing product selection and source review. An empty product catalog offers **Create product draft from this image**, retaining the chosen source.

`/marketing` lists unfinished, **Active**, and **Paused** product marketing plans from the existing canonical product-launch records. It reads their current state and rechecks a selected plan before reopening its original private Chat and existing workflow. Listing and resolving plans are read-only. Explicit resume may continue an already-approved queued image job through the existing Canvas behavior; it does not create a new approval or publish a promotion.

Image selection does not upload a copy, create a product or marketing draft, request image generation, or publish a promotion. Product and Marketing keep their JPEG, PNG, and WebP source policy and 4 MiB byte limit; their production preview and creation handlers remain authoritative. Chat editing retains its existing ordinary-image 4 MiB limit and supports canonical generated PNGs up to 25 MiB only after the existing server verifies their provenance and actual bytes.

This release adds no separate marketing-plan schema or full marketing-planning specialist. Product listing creation and promotional graphic design remain linked steps in the existing product marketing flow.

## Scorecard

Each area earns 0 points when missing, 1 when implemented but incomplete or unverified, and 2 when all listed acceptance checks pass. The release target is 10/10 across these five areas. Record the final evidence and any limitations in the pull request.

| Area | Requirements for 2 points |
| --- | --- |
| Image browsing | Thumbnail grid, image preview, literal name search, uploaded/generated/edited source filters, collections, pagination, and clear loading, empty, incompatible, missing-image, and retry states. Older images remain discoverable. |
| Product and Marketing integration | Both workbench lanes and `/images` use the same picker; exact source and selected product reach the existing review flow. Typed descriptions and design settings survive opening, cancellation, failed selection, and staged replacement. Existing promotion drafts retain their original source until the owner explicitly reviews and creates or opens the replacement draft. `/marketing` includes unfinished, Active, and Paused plans and resumes fresh canonical state in the original Chat. |
| Reliable selection | Owner, Agent, Chat, participant, destination, image ID, and operation identity are rechecked around asynchronous work. Cancellation or changed context prevents late attachment. Exact-source server validation rejects unavailable, changed, oversized, invalid, or unauthorized images without replacing the prior selection. Browsing, selecting, listing plans, and resolving a plan cause no media duplication, provider dispatch, draft creation, or publication. Commands preserve text typed during initialization and never send the slash command as an Agent message. |
| Accessibility and layout | Labeled keyboard-operable controls, meaningful focus and status announcements, mobile and desktop layouts within viewport, reachable preview/selection/cancel controls, and no focus stolen after the destination changes. |
| Verification and delivery | Meaningful component browser, integrated Product/Marketing browser, command browser, real Chat composer, and real HTTP regressions pass. Canonical product-launch lifecycle coverage includes Active and Paused plans with SQLite and native MySQL. Existing application gates pass on the final source. Review desktop/mobile evidence and verify the official deployment archive against the merged Git tree before delivery. |

## Required journeys

1. Find an uploaded or generated image by name and collection, including an asset beyond the initial pages and the old latest-50 source list.
2. Preview it and explicitly choose it for a Product draft; preserve an existing description and use the exact image in source review.
3. Choose it for Marketing while retaining the selected product; continue through the existing private draft and graphic review flow.
4. Stage a different image in an open Product promotion. Cancel at the picker or review stage and retain the original image, draft, and entered design settings. Complete the explicit handoff and verify the new draft uses the selected source and intended settings.
5. Exercise slow responses, superseded searches, selection cancellation, destination changes, account/Agent/Chat/participant changes, deleted assets, and incompatible files. No late or wrong image may be attached.
6. Reuse the same image in multiple destinations and collections while preserving its original asset ID and bytes.
7. Enter `/images` in an existing or new private Chat. Edit an exact image without sending a message, replacing a typed prompt, duplicating media, or creating a job. Double-submit while a new Chat is initializing and type a new draft before completion; initialize once and preserve the new draft without opening a stale command.
8. Send an image to Marketing with an existing product, and with an empty product catalog. In both cases keep the exact source through the existing explicit review and draft creation flow.
9. Enter `/marketing` and browse unfinished, Active, and Paused plans beyond the first page. Resume fresh state in the same Chat or the original accessible Chat; reject foreign owners, Agents, participants, malformed links, missing sources, and stale plan bindings. Retire private rows after an account/session denial while retaining an inline retry for a transient network failure.

## Validation boundaries

Controlled provider responses are used in automated tests. Browser evidence from hosted CI is authoritative when the local browser is unavailable. Deployment validation checks package integrity, the complete file list, Git blob identities, regenerated release metadata, and preserved runtime paths.
