# Stem Editor audit and recovery release

Baseline: `fe2b05342a9b9bfb28dd4ce6d682790917e637e3`.

The owner reported that a successful REAPER/MP3 import opens with some
instrumental and most vocal stems unavailable, slow loading and glitchy
playback. The screenshot shows a 19-track project, a floating Agent orb,
a separate bottom composer, and an AI button in the header.

## Scope

Audit the shipped `admin/stems.php` composition root, its legacy PHP template,
canonical `stem-editor.js`, media delivery and waveform endpoints, transport,
buffer scheduler, plugin/mixer/editing graph, recording/session boundaries,
REAPER import and save transaction, Agent conversation/tool bridges and the
Media & Entertainment specialist. Advanced v211–v219 features remain subject
to the existing explicit runtime gate; this audit does not enable them.

## Confirmed findings

| Priority | Finding | Evidence and consequence |
| --- | --- | --- |
| High | Valid MP3s get zero source duration without ffprobe | The old import metadata path depended on shell ffprobe, exact RPP path matching or browser duration. ZIP MP3 exports with different names supply none of those on a shared host. The editor then creates a 0.05-second clip. Native MPEG frame/Xing metadata now handles this without shell access and resolves existing zero-duration rows for page rendering. |
| High | Seek recovery permanently disables media | `quarantineSeek` emits the canonical offline event after a 2.4-second seek timeout; the offline bridge sets `mediaUnavailable` and replaces `play` permanently. A temporary decoder/network problem can silence a valid saved stem for the session. |
| High | Network errors become decode errors | The footer diagnoses every native media error with HEAD; any successful HEAD becomes `decode_error`. There is no bounded recovery or online transition. |
| High | Startup and waveform work share a hard gate | The project loader waits for HAVE_ENOUGH_DATA on all media elements and then every waveform. MP3 waveform fallback downloads/decodes source audio; previews delay editing/playback. |
| High | Duplicate media requests | Every primary audio element preloads automatically; `ensureAudioGraph` also creates a crossfade element with metadata preload for every stem. Large projects compete for browser and PHP connections. |
| Medium | Compressed media is not shared with waveform/buffer consumers | Separate native playback, crossfade, waveform and decoded scheduler requests can fetch the same private source repeatedly. |
| High | Two Agent interfaces | The Studio Agent panel and canonical orb's separate iframe/composer can represent different conversations. The permanent footer composer covers editor space. |
| Medium | Production is absent from the Media specialist | Existing native Media sources cover discovery, listening, YouTube and Stoneward. Saved private REAPER projects and editor health are not first-class production findings. |
| Medium | Upload acceptance coverage is incomplete | Earlier tests verify the overlay and mock upload responses. A controlled real PHP import of a compressed RPP plus playable MP3 passed; this does not reproduce or clear the owner's multi-track playback failure. |

The exact live cause of each reported unavailable track remains unverified;
the mechanisms above are confirmed in source. A missing physical file or an
unsupported/corrupt source must remain distinguishable from a retryable load.

## Implementation and acceptance

1. Share bounded compressed-source loading between the media elements and
   existing decoder consumers; retain private authenticated source URLs as
   canonical identity and bound memory. Crossfade audio remains lazy.
2. Keep every required audio track in the readiness gate; generate waveforms
   separately and defer expensive waveform work during playback/recording.
3. Retry temporary network/seek failures with explicit bounds. Restore the
   original playback method and state on verified recovery. Retain named
   failures and an owner retry action for unresolved or genuinely missing files.
4. Keep the orb and header AI control, remove the always-visible bottom chat
   bar on this editor, and use one conversation and existing verified Studio
   tool registry for both entry points.
5. Add private production-project findings to the existing Media specialist,
   shared report/Chat/Brain loop and source revalidation. Recheck account,
   workspace/production authority and named-Agent data policy. Opening or
   reporting a project must not modify, publish or regenerate its audio.
6. Test a 19-stem compressed import through real PHP storage/media reads,
   transient recovery and terminal failures, byte ranges, bounded loading and
   memory, seek/pause/retry lifecycle, and desktop/mobile Agent UI. Run existing
   editing, mixer, transport, recording, export, session and Agent contracts.

The final software score requires completed focused tests, browser review,
green PR and merged-main gates, and a byte-verified Cloud deployment archive.
Real-device audio quality and the owner's exact project remain live-host
acceptance checks; test fixtures do not establish that those have passed.

## Authority and integration findings

The Studio Agent API also used a public-catalog lookup that rejected unpublished
private drafts even when the editor authorized their producer. It now resolves
the exact private track through the same current production authority as the
editor; real HTTP acceptance covers owner success, foreign-account rejection
and CSRF rejection. Reopening the orb preserves its in-progress conversation,
and native history stores the same verified reply reconciled to main Chat.

The Studio conversation bridge previously accepted any owned conversation and
could fall back across Agent namespaces. Client results could name an unrelated
assistant row in an owned conversation. The repair resolves only the exact live
Agent, rejects explicit foreign conversations, scopes displayed history, and binds
each result to its issued session/history/conversation/message/Agent proposal.
Named Agents need the current private music-production knowledge grant before
owner-wide Brain context or production findings are read.

Production evidence enters the existing `media:studio:<track-id>` source registry
and five-section report pipeline. It uses current owner/account, Agent, Chat,
workspace and production authority, stable source revisions and existing delivery
receipts. Healthy work defaults to Watch; missing/invalid saved bytes request owner
attention. These reports do not consume playback-offer cooldown or start playback.
Browser-only failures are explicitly marked as observations in Studio planner
state; server header checks do not claim verified browser decoding or mix quality.

## Preserved boundaries and limits

The audited canonical transport, mixer/plugins, editing, recording, export and
session modules retain their tool and data contracts. The existing decoded PCM
scheduler has a 384 MiB budget and atomic native-media fallback; compressed source
sharing adds a separate 128 MiB cap with two requests at a time. Waveform errors
remain separate from media availability. Meter painting is limited to 20 Hz;
automation and playhead timing retain the existing cadence.

Import still validates staged source bytes before transactional activation, and
media serving retains owner checks, session-lock release and exact byte ranges.
No schema migration is introduced. Metadata correction does not rewrite files,
source timing or saved mixes. An intentional or previously saved short clip is
preserved. When recovered zero-duration metadata accompanies a single 0.05-second
clip, an explicit Restore source length action extends it in its own Undo step
without resetting levels, plugins, fades or other mix state.
The old archived JavaScript/PHP copies are not promoted to the canonical runtime.

Synthetic audio fixtures and a 19-file compressed ZIP exercise real PHP import,
storage and media transport. Local Chrome cannot launch in this execution host
because of a ProcessSingleton socket restriction. Hosted browser acceptance must
therefore pass before merge, and screenshots must be reviewed. The owner's
original project and actual soundcard/device quality still require live testing.
