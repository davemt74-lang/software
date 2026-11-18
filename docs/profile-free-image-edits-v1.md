# Free photo edits in Profile chat

## Agreed first release

Visitors can upload a photo, choose an approved free reference catalog or image, and ask the Profile Agent to edit their photo in that style. The visitor's photo is the subject. Catalog images supply visual style references. The result and subsequent revisions belong to the same Profile conversation.

The first release processes only references with an effective price of **$0.00**. A catalog has a default price; a selected image can have an optional override. A missing override inherits the catalog price, while an explicit zero means free. Nonzero prices can be saved for future licensing, but those images cannot be used by this release. Catalog selection includes only approved free references, never a priced image hidden among free examples.

There is no checkout, licensing charge, royalty balance, or payout workflow. Existing configured AI service usage and owner allowances cover processing. Generation remains subject to that service's availability and quotas. The new per-owner and per-visitor limits bound introductory use; they are counts of admitted edits, not a money balance.

## Owner setup

Profile Agent settings provide free photo editing controls. The owner explicitly enables the service, chooses existing Media Library collections, approves one to three example images per collection, and supplies optional style instructions. Adding an image to an ordinary collection does not approve it for visitor processing.

The initial limits are ten edits per owner per UTC day and three per visitor per UTC day, with owner-configurable bounds. The service starts disabled. Only the Profile owner's own approved catalog is available in this first release.

## Conversation behavior

The visitor chooses **Edit with AI**, selects the style, attaches their photo, writes the edit instruction, and sends. Uploading or choosing a style alone does not start generation. AI editing works without the human owner joining. Human-only private image/file sharing keeps its existing permissions and is not an AI source.

One request creates one result. Saved queued, editing, completed, and failed states drive the UI. A completed image can be viewed, downloaded, or used as the subject for a new edit in the same conversation. Each intentional revision has its own request and allowance event; reloading, polling, downloading, and retrying delivery do not create another generation.

If the first upload's acknowledgment is lost, a read-only receipt lookup recovers the saved edit even before the browser knows its conversation ID. The browser retains the exact request and subject hash across reload. If the upload never arrived, the visitor can reattach the same photo to resume that request. A different image cannot silently reuse the saved request.

The owner can inspect tracked uses and the linked original, prompt, reference catalog, and output in the authorized Profile conversation. Visitor originals and generated results are private conversation records; publication to a public catalog remains a separate action.

## Authority, persistence, and provider behavior

Image edits reuse the saved native Profile visitor turn and its canonical job. The visitor is never treated as a private owner Chat session. An image request binds the owner, Profile Agent, visitor identity, session, conversation, original message, request ID, prompt, subject hash, and exact approved style snapshot.

The server rechecks free eligibility, catalog approval, reference revisions and hashes, and public conversation authority before provider dispatch and result publication. Owner takeover prevents a late Agent result from publishing into a human conversation.

The provider receives the subject first and one to three ordered style images afterward. Inputs are validated raster data, not arbitrary browser-provided URLs. Normal uploaded subjects are JPEG, PNG, or WebP up to 4 MiB and 4096 pixels per side. The approved style examples together must fit within 4 MiB. A generated result can be reused only with verified same-conversation provenance.

A persisted dispatch marker permits at most one provider attempt per request, including worker recovery. Verified provider usage is accounted for even if a result cannot be published. Completed output, source attribution, and its conversation message are linked once. Failed or cancelled work does not become a completed catalog use.

## Verification and release

Focused checks must cover real storage and authorization, free/paid eligibility and explicit-zero overrides, owner and visitor caps, exact-request replay, source revocation, takeover during processing, protected downloads, ordered provider inputs, and the existing human attachment boundary. Browser acceptance covers staging, reload, completion, revisions, owner setup, and mobile layout.

This document describes the feature under development. It does not assert a deployment or live-provider result. Deployment uses the normal reviewed pull request, required checks, database upgrade, and full production ZIP workflow.

Direct visitor uploads require PHP's effective `upload_max_filesize` to allow the 4 MiB input and `post_max_size` to allow the multipart request. The controlled HTTP acceptance server uses `upload_max_filesize=5M` and `post_max_size=8M`. Installed hosting values must be checked during deployment.
