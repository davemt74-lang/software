# Agent Eyes capture and listening repair v1350

The browser microphone monitor now finishes acquisition before native speech recognition starts. Native recognizers retain their release barrier until onend, including ordinary-to-barge and speech-to-listening transitions. Aborted cancellation events no longer count as provider failures; permission and device failures still stop capture. The existing response timeout and stop fences remain in force.

Visual consent starts a bounded three-frame enrollment when one named participant is available. Authorized requests can wait for the camera and a clear face; the explicit capture button can start the camera. The same participant must remain in view. Three small JPEG portrait previews and embeddings stay in the browser's account-owned IndexedDB record, with consent checked again at transaction time. They never enter Cloud participant requests or Brain events. Capture progress and errors remain visible instead of being overwritten by the camera status.

Voice authorization and Learn this voice use one enrollment path, preserve consent across audio attachment, and keep temporary reply mode from ending training after the first phrase. Three training phrases update local features without becoming Chat messages. The prior listening state returns after completion, cancellation or the 60-second enrollment limit. Existing named profiles and voice-clone choices are preserved.

Regression coverage includes a deliberately delayed native abort/onend sequence and concurrent microphone acquisition; genuine permission denial; response timeout; automatic and button portrait capture; portrait persistence/account isolation; voice capture through consent and button; revoked enrollment and existing Chat/TV behavior. Speech, camera-model and provider fixtures are used; live hardware confirmation is still needed.

HomeServer source is staged locally only. No HomeServer workflows or publication are authorized in this phase.
