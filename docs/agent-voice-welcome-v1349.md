# Shared Agent Voice and response windows v1349

The upper account-menu Agent Voice switch is the single master control on member pages. It uses the existing account Chat setting and broadcasts changes to the active voice runtime. Chat settings saves preserve the master choice.

The account menu saves the default active personal Agent through the existing owner-bound Agent API. A persistent setup reminder remains until an active default exists. The ElevenLabs system voice selector reads the provider default-voice catalogue on the server, validates selection against that catalogue and saves only an account-owned voice ID/name. An Agent clone retains priority when selected. Provider keys and other accounts’ clones are never returned.

With Agent Voice on and microphone permission already granted, the shared Agent greets once per browser/account in a 30-minute session window. Microphone access is not requested automatically. A visible button handles permission or playback interaction when the browser requires it.

Starting Eyes opens a temporary listening opportunity when voice is enabled and microphone permission is granted. A successfully completed spoken question also opens a 15-second response window. The canonical voice engine captures the answer and releases microphone capture on timeout, accepted answer, Eyes off, Voice off or Stop. Existing continuous listening remains continuous. Temporary listening never changes the saved continuous-listening preference. A user answer and its spoken response complete through ordinary Chat before temporary mode returns off.

The orb retains Eyes movement tracking and adds idle floating with a Float control. Motion pauses near the pointer, on interaction, while minimized or during a popup; reduced-motion disables it. Orb controls appear on hover, keyboard focus or touch without changing its tracking bounds.

Validation includes the real server-rendered member menu, real Chat/settings/orb browser runtimes, canonical voice engine with mocked recognition, PDO persistence and production TTS settings resolution. Live microphone recognition and provider sound quality still require the deployment smoke test.

HomeServer changes are staged separately. Do not open a HomeServer PR, merge, release or trigger its workflows until Cloud testing is accepted.
