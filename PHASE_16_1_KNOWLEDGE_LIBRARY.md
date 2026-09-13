# Phase 16.1 — Knowledge Library UX

This phase builds the VP3 Cloud Knowledge library on top of the shared-folder foundation from PR #168.

## Delivered

- Canonical folder cards with item counts and latest Knowledge activity.
- Breadcrumb navigation and type filters for documents, audio/media, transcription intelligence, music, and notes.
- Multi-select move and drag-to-folder organization.
- Safe folder rename/delete; deleting a folder moves Cloud Knowledge and transcription metadata to Unfiled.
- Reusable shared folder picker with inline `+ New folder…` creation.
- Shared picker enhancement for My Knowledge, transcription metadata organization, and AI Summary destination folders.
- Explicit Cloud Knowledge source badges and preserved HomeServer Local Knowledge privacy boundary.
- Owner-scoped shared-folder API/service with no parallel folder table.

## Architecture

`artist_transcript_folders_v177` remains the single folder authority. Phase 16.1 adds no folder schema and no HomeServer protocol change. Native HomeServer filesystem paths remain local and are never written to VP3 Cloud.
