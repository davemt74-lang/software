# Cloud Mission web collection

Reviewed Cloud assignments now support `web.search` and `web.read` alongside Knowledge search and Brain recall. Search uses the existing DuckDuckGo HTML discovery adapter, while direct public page reads run on the Cloud host. No shared Chrome tab, extension, HomeServer, or additional service key is required for these public reads. Search availability still depends on the provider accepting the host's requests; blocked or ambiguous responses fail explicitly.

## Saved-source tool and master store list

**Saved web sources** on the Chat canvas is the reusable source catalog for Mission research. The admin dashboard links directly to it. Users can save, edit, disable and restore their own named public URLs. Admins with the existing Admin/AI settings permissions can also manage the shared master list. Personal URLs are not listed or selectable by other accounts, including admins. Changes use exact revisions and idempotent operation IDs.

The initial master list contains 20 website entries in six categories: Amazon, Walmart, Target, Costco, Sam's Club; Best Buy, B&H Photo Video, Newegg; Home Depot, Lowe's, Harbor Freight, Ace Hardware; WebstaurantStore, Restaurant Depot, CHEF'STORE, Smart & Final; Staples and Office Depot; Fry's Food Stores and Safeway. Initializing defaults preserves later admin edits and disabled entries. These are reusable targets, not a claim that all their pages are readable by automation. Adding an entry never fetches the website.

A **website** source contributes its domain to search scope. A **public page** source contributes an exact product/category/page URL that can be read without search. Mission assignments can choose up to five saved sources alongside manual URLs, within the same five-domain, two-page and three-request bounds. The `saved_source_research` skill describes this source-selection workflow over `web.search` and `web.read`.

Selected IDs resolve to owner-authorized URL/type snapshots during assignment review. Target changes or disabling a referenced entry stops future reads until a new assignment is reviewed. A label-only rename preserves the exact approved target. Completed evidence stays in history. Manual URLs remain separate from catalog-derived targets, so deselecting a catalog source does not leave its old URL hidden in manual fields.

## Review and execution

Select `web.search` to discover sources using an explicit external query. The Mission objective, transcript text and private Knowledge context are not automatically used as a search query. An optional list of up to five website domains constrains results and subsequent redirects, including subdomains. An empty domain list permits eligible public HTTPS websites. Add `web.read` for excerpts from discovered pages. Alternatively, select `web.read` alone and supply one or two known public page URLs to monitor without a search provider.

The reviewed page cap is one or two pages per worker. Search, page GETs and redirects share that worker's maximum of three read calls with its Knowledge/Brain tools. Search matches are not page evidence. The source cards distinguish unread matches, fetched excerpts, truncation and failed requests. Excerpts are limited to 6,000 characters from a bounded HTML/plain-text response; JavaScript-only pages, PDFs, paywalls and login screens are not rendered or bypassed.

Each canonical worker action has one durable collection with an atomic request counter. A confirmed collection can be reused after an explicitly requested model retry without repeating external reads. An incomplete or failed collection is not automatically replayed. Worker leases, account permissions, Agent scope and the exact reviewed or recurring grant are revalidated before external requests and before evidence is committed. Cancellation fences late source data and model output.

## Public HTTP boundary

Only credential-free HTTPS GETs on the standard port are allowed. Known account/action paths and credential/action query parameters are rejected. All resolved DNS addresses must be public, and cURL is pinned to an approved address with TLS peer/hostname verification. Environment proxies and netrc credentials are disabled. Each redirect is checked again and consumes request budget. Responses are bounded after decompression; scripts, forms and other non-content markup are removed before model context is built. Source text and previous worker output remain untrusted evidence, not instructions or authority.

The existing search API remains compatible with its legacy array-returning callers. A structured search response allows Missions to distinguish confirmed empty results from network errors or bot challenges. Confirmed empty results are recorded as limited search observations, not proof that no matching information exists. If page reading was requested and every eligible page failed, the worker fails without model inference while retaining the observed failures.

## History, comparison and Knowledge

Progress responses carry compact URLs, titles, collection times, request counts and coverage. **Read collected evidence** retrieves the full stored excerpts through an owner-scoped route. Synthesis receives the actual source excerpts and provenance, not just a prior worker's claim that a search happened.

Recurring Missions inherit the exact query, URLs, domains, page cap and request budget. Each completed run saves its private report and source-excerpt note in the existing selected My Knowledge folder. Evidence notes retain collection timestamps and source URLs; raw HTML, cookies and browser sessions are not saved. These generated notes are excluded from new Knowledge collection to prevent feedback loops. Comparison fingerprints use the retrieved source content and coverage, ignoring collection IDs, times and model wording. Existing Knowledge-only comparison hashes remain compatible.

The evidence and catalog metadata tables initialize automatically. The normal durable job, receipt, recurring schedule and Knowledge systems remain authoritative. This does not add email, phone, publishing, product or image-generation actions. Native browser-controlled Research remains available separately for work that requires an authenticated or rendered page.

## Validation

The domain journey exercises real canonical jobs, source extraction, model-context assembly, request accounting, cache reuse, cancellation, recurring reports and Knowledge indexing with controlled source transports. A separate no-extension wire fixture runs the actual cURL adapter against controlled responses to check pinning, TLS, proxy/credential settings, private/mixed DNS, redirects and body bounds. API and mobile browser journeys cover assignment review, source ownership, compact versus full evidence, safe rendering and the existing execution receipts. These controlled tests do not certify external provider availability on a deployed host.
