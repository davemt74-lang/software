# HomeServer pairing request size hotfix

Cloud's HTTPS pairing endpoint previously rejected requests over 16,384 bytes.
HomeServer sends its full public capability manifest with the one-time pairing
token. The Section 16 HomeServer, with a fresh temporary data directory, produces
a 35,235-byte compact JSON request. The token itself is only 75 characters.
The request was rejected before token redemption or connection creation.

The Cloud endpoint now accepts the same bounded 1,048,576-byte budget as the
existing HTTPS relay heartbeat. It reads at most the budget plus one byte and
rejects excessive actual bytes even when Content-Length is absent. Existing
token validation, device binding, authorization, transaction, and retry/recovery
logic remain in the canonical pairing service.

The HTTP regression uses the shipped PHP endpoint with only downstream storage
stubbed. It covers a capability payload above the previous limit, forwarding all
pairing/recovery fields, the exact new limit, declared and chunked oversized
requests rejected before redemption, malformed JSON, method enforcement, and
invalid-token rejection. Retained pairing recovery tests run in the same CI gate.

Deploy the Cloud package first. No HomeServer binary change or database migration
is required. Retry the same token if it is still valid; if its 15-minute lifetime
has elapsed, reset the unfinished local pairing and generate a fresh Cloud token.
An existing completed Cloud connection must be disconnected before replacement.
