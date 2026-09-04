# Stonefellow v71 Code Review — Agent Chat Create Popups

## Scope

Review covers the six Agent Chat create popup forms, multipart create API,
permission enforcement, CSRF, file uploads, knowledge indexing, new Merch
sidebar/canvas integration, and regression protection from v70.

## Final score

**10 / 10 for the requested v71 scope.**

## Create modal architecture

The `+` header control remains a small permission-aware menu.

Selecting a type now opens one shared modal shell with one dedicated form for:

- Track
- Event
- Knowledge Base
- User
- Merch
- Photo

Only forms authorized for the signed-in account are rendered.

The modal includes:

- semantic dialog markup
- Escape close
- backdrop close
- Cancel controls
- focus placement
- responsive mobile layout
- scrollable form body
- visible working/success/error state
- Full Editor fallback

## Create API

`api/chat-create-v71.php` re-checks authorization server-side.

The client cannot grant itself a create type because the endpoint maps each
content type to its required permission:

- Track → `tracks.manage`
- Event → `shows.manage`
- Knowledge → `knowledge.manage`
- User → `users.manage`
- Merch → `merch.manage`
- Photo → `photos.manage`

The endpoint also checks:

- signed-in session
- current schema readiness
- POST
- CSRF
- required fields
- visibility
- role validity
- duplicate user email
- password length
- URLs
- price
- file extensions / upload limits

## File handling

Track, user, merch and photo uploads use the existing protected upload helper.

Knowledge files use the protected Knowledge upload directory and existing
extraction/indexing functions.

Failed creates clean up files uploaded during the failed request.

## Merch canvas

Merch is now a first-class Explore item in Agent Chat.

Only published items that pass the current user's visibility check are rendered.

Merch images continue through the protected `content-image.php` endpoint.

## Existing v70 audio work preserved

The review protects:

- custom dark inline audio player
- no native white audio controls
- persistent Now Playing
- queue navigation
- seek/time/mute/volume
- playback analytics
- auto-next
- cleaner non-production music answers

## Stem Studio regression protection

Preserved:

- Exit Studio → Agent Chat
- v34 coordinated safe seeking
- live recording
- armed Space-bar recording
- mute/solo playback repair
- Save / Save As
- Open Project / Load Song
- Producer sharing/export

## Database

No new v71 schema migration is required.

The v70 Photos/Merch schema remains the current database baseline.
