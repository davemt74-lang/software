# VP3 ↔ Annotated Connector v1.0

This connector lets standalone Annotated authenticate against a VP3 account and explicitly import VP3 meeting transcripts / AI summaries into Annotated Research Agents.

It is **not** a second Annotated plugin inside VP3.

## Connected Sites

VP3 owns the authorization and exposes **My Account → Connected Sites**.

A connection records:
- site/app label
- exact scopes
- connected date
- last-used date
- active/revoked state.

Users can revoke a site at any time. Revocation immediately invalidates its current access/refresh tokens.

The initial registered first-party site is `annotated`.

## Scopes

- `account.identity.read`
- `transcriptions.read`
- `transcriptions.intelligence.read`

Authorization codes are single-use and expire after five minutes. Access tokens expire after one hour. Refresh tokens expire after 90 days and rotate whenever used. VP3 stores token hashes, never raw issued tokens.

## VP3 artifact authority

The connector does not create transcript or summary tables.

Meeting transcripts are projected from `video_meeting_transcript_segments`. General VP3 transcriptions are projected from `artist_transcript_sessions_v172` + `artist_transcript_segments_v172`.

Meeting AI summaries and general transcription summaries are projected from the existing canonical Meeting Intelligence / Transcription Intelligence stack.

Meeting access is checked against the existing meeting owner/participant boundary on every API request.

## Configuration

Configure the same client secret in both systems.

VP3:
- `VP3_ANNOTATED_BASE_URL`
- `VP3_ANNOTATED_REDIRECT_URI`
- `VP3_ANNOTATED_CLIENT_SECRET`

Equivalent site settings are supported:
- `annotated_connector_base_url`
- `annotated_connector_redirect_uri`
- `annotated_connector_client_secret`

The registered redirect URI must exactly match the callback supplied by Annotated.

## API

- `/connected-site-authorize.php`
- `/api/connected-site-token.php`
- `/api/connected-site-me.php`
- `/api/connected-site-artifacts.php`
- `/api/connected-site-revoke.php`

The external site can only retrieve artifacts permitted to the connected VP3 user and allowed by the approved scopes.
