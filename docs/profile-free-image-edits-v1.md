# Free photo edits in Profile chat

## Agreed first release

Visitors can upload a photo, choose an approved free reference catalog or image, and ask the Profile Agent to create, integrate, or remix using that reference. The visitor's photo remains the primary subject. Catalog images can guide style, composition, or requested visual elements, while the prompt explains what to keep, change, or combine. The result and subsequent revisions belong to the same Profile conversation.

The first release processes only references with an effective price of **$0.00**. A catalog has a default price; a selected image can have an optional override. A missing override inherits the catalog price, while an explicit zero means free. Nonzero prices can be saved for future licensing, but those images cannot be used by this release. Catalog selection includes only approved free references, never a priced image hidden among free examples.

There is no checkout, licensing charge, royalty balance, or payout workflow. Existing configured AI service usage and owner allowances cover processing. Generation remains subject to that service's availability and quotas. The new per-owner and per-visitor limits bound introductory use; they are counts of admitted edits, not a money balance.

## Owner setup

Profile Agent settings provide free photo editing controls. The owner explicitly enables the service, chooses existing Media Library collections, approves one to three example images per collection, and supplies optional reference instructions. Approved, effective-zero-price examples appear as public collection previews in Profile chat. Adding an image to an ordinary collection does not approve it for public display or visitor processing.

The initial limits are ten edits per owner per UTC day and three per visitor per UTC day, with owner-configurable bounds. The service starts disabled. Only the Profile owner's own approved catalog is available in this first release.

## Conversation behavior

The chat popup includes an **Image collection** section with approved free reference previews and a short explanation: upload a photo, choose a collection image, then describe what to keep, change, or combine. Opening the section is a read-only browse action. The owner can inspect the same public collection from their own profile without creating a visitor conversation.

The visitor chooses **Edit with AI**, attaches their photo, selects a reference, writes the edit instruction, and sends. Uploading or choosing a reference alone does not start generation. AI editing works without the human owner joining. Human-only private image/file sharing keeps its existing permissions and is not an AI source.

One request creates one result. Saved queued, editing, completed, and failed states drive the UI. A completed image can be viewed, downloaded, or used as the subject for a new edit in the same conversation. Each intentional revision has its own request and allowance event; reloading, polling, downloading, and retrying delivery do not create another generation.

If the first upload's acknowledgment is lost, a read-only receipt lookup recovers the saved edit even before the browser knows its conversation ID. The browser retains the exact request and subject hash across reload. If the upload never arrived, the visitor can reattach the same photo to resume that request. A different image cannot silently reuse the saved request.

The owner can inspect tracked uses and the linked original, prompt, reference catalog, and output in the authorized Profile conversation. Visitor originals and generated results are private conversation records; publication to a public catalog remains a separate action.

## Creation insights and source credit

In the owner's main Chat, open the Profile specialist and choose **Analytics → Completed periods**. The first section, **Free image creations**, compares completed creations, initial creations, completed revisions, and verified failed requests with the previous matching period. The existing 1, 7, and 30 day choices retain their saved timezone and fixed cutoff. A completed revision is a separate creation; recovering, polling, or downloading the same request does not add a creation.

The source lists show the most used catalogs and reference images in each period. Counts describe completed creations, not unique people or inferred preferences. A creation can use multiple reference images, so image counts need not add up to the creation total. Names come from the approved snapshots saved with each request. If a source was renamed during a period, its most recent verified use supplies the displayed name. Later changes to a public catalog do not rewrite those saved names or erase authorized private history. **Review saved creations** opens the existing owner history, where each original, prompt, reference, result, and conversation can be inspected.

**Today’s image allowance** is separate from the completed-period comparison. It shows admitted requests used, requests remaining, the current owner and visitor limits, and the UTC reset. Failed and cancelled admitted requests still use the daily allowance, matching generation admission. Current saved failures show safe categories across the creation history. A failure is assigned to a completed period only when its exact native failure reply and receipt prove its time; a saved failure without that proof leaves period coverage incomplete. Missing storage is shown as unavailable, never as zero activity or a fresh allowance.

Profile reports, catch-up, morning Chat, and the saved Brain source use the same existing completed-period evidence and source link. When verified image activity exists, the report leads with creation and revision counts, useful catalog usage, and safe failure information. It can offer to review creations or discuss another collection. Reading a report does not start generation or create a separate scanner. Changes to today's allowance do not invalidate a completed-period finding; corrections to its underlying saved evidence do.

Each finished image displays **Reference images used** in its result card, viewer, and authorized owner conversation. The credit includes the Profile owner's public display name, catalog, exact selected reference titles, and **$0.00**. The owner name is frozen when the request is admitted, and source titles are frozen in the approved catalog snapshot. Older verified results without a saved owner name use **Profile owner**. If saved source proof is invalid or missing, the result explains that source credit is unavailable rather than substituting current catalog data. This label describes the references used for that edit; it does not claim that the images trained a model or establish legal ownership.

This addition uses the existing image and analytics records and requires no new database migration. Free-only eligibility, owner allowances, and the absence of a payment workflow remain the same.

## Authority, persistence, and provider behavior

### Public collection and reference reads

`GET /api/profile-agent.php?action=image_collection&username=...` returns `{ok:true,image_editing:...}` using the same availability contract as chat state and polling. Each approved free reference includes `preview_url`. Disabled service, missing image schema, or an empty approved free catalog returns `enabled:false`, an empty catalog list, and an explanation. The page can therefore keep the collection section visible. An unavailable public Profile or Agent returns a generic 404.

The preview URL addresses the same API with `action=reference_image`, `username`, `catalog_id`, `image_id`, and `catalog_revision`. It grants no visitor or conversation authority. Both endpoints accept only GET and those explicit selectors. They do not create a Profile visit, conversation, generation job, allowance event, usage entry, or schema; thumbnails do not spend the owner's AI allowance.

Each reference read checks the current active owner, public Profile, enabled owned active Agent, and both Profile Agent and Profile chat capabilities. It then verifies the exact owned collection membership, current approval revision, effective zero price, approved source metadata and byte hash. It repeats current authority and grant checks after reading the source. Private Media Library endpoints, visitor originals, and generated conversation results never become public collection previews. Removing approval or membership, changing the price or source, disabling the service, or withdrawing Profile authority stops subsequent image delivery.

Reference responses contain only the validated approved JPEG, PNG, or WebP bytes, bounded by the existing 4 MiB and 4096-pixel limits. They use `private, no-store`, `nosniff`, same-origin resource policy, and a sandbox content policy. No GD extension or image-provider call is required to browse. The server releases its PHP session lock before collection and reference reads so thumbnail loading does not hold up other requests.

### Image generation

Image edits reuse the saved native Profile visitor turn and its canonical job. The visitor is never treated as a private owner Chat session. An image request binds the owner, Profile Agent, visitor identity, session, conversation, original message, request ID, prompt, subject hash, and exact approved style snapshot.

The server rechecks free eligibility, catalog approval, reference revisions and hashes, and public conversation authority before provider dispatch and result publication. Owner takeover prevents a late Agent result from publishing into a human conversation.

The provider receives the subject first and one to three ordered style images afterward. Inputs are validated raster data, not arbitrary browser-provided URLs. Normal uploaded subjects are JPEG, PNG, or WebP up to 4 MiB and 4096 pixels per side. The approved style examples together must fit within 4 MiB. A generated result can be reused only with verified same-conversation provenance.

A persisted dispatch marker permits at most one provider attempt per request, including worker recovery. Verified provider usage is accounted for even if a result cannot be published. Completed output, source attribution, and its conversation message are linked once. Failed or cancelled work does not become a completed catalog use.

## Verification and release

Focused checks must cover real storage and authorization, free/paid eligibility and explicit-zero overrides, owner and visitor caps, exact-request replay, source revocation, takeover during processing, protected downloads, ordered provider inputs, and the existing human attachment boundary. Real HTTP tests also browse anonymously and as the owner with the database in read-only mode, compare exact approved reference bytes, deny foreign/unapproved/changed/revoked sources, and preserve thumbnail URLs through normal chat state and polling. Browser acceptance covers collection discovery, staging, reload, completion, revisions, owner setup, and mobile layout.

Creation insights additionally verify exact request/turn/receipt lineage, revision parentage, duplicate suppression, frozen zero-price source snapshots, partial or unavailable coverage, owner-local period boundaries, and the distinct UTC allowance. Existing SQLite and native MySQL analytics gates exercise the same cases. Shared report tests verify catch-up and morning Chat reuse, source invalidation after evidence corrections, no invalidation from live allowance changes, and read-only behavior. Desktop and narrow-screen browser acceptance checks populated image rankings, unavailable storage, source credit, history navigation, and the absence of nested scrolling.

This document describes the feature under development. It does not assert a deployment or live-provider result. Deployment uses the normal reviewed pull request, required checks, database upgrade, and full production ZIP workflow.

Direct visitor uploads require PHP's effective `upload_max_filesize` to allow the 4 MiB input and `post_max_size` to allow the multipart request. The controlled HTTP acceptance server uses `upload_max_filesize=5M` and `post_max_size=8M`. Installed hosting values must be checked during deployment.
