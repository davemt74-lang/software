<dialog id="phone-camera" class="phone-camera-app" aria-labelledby="phone-camera-title" hidden>
  <header class="camera-heading">
    <h2 id="phone-camera-title">Camera</h2>
    <span id="phone-camera-destination">Private Chat, editor and map</span>
    <button type="button" id="phone-camera-close" aria-label="Close camera" hidden>×</button>
  </header>
  <div class="camera-stage">
    <video id="phone-camera-preview" aria-label="This phone’s camera" muted autoplay playsinline hidden></video>
    <span id="phone-camera-mode" hidden></span>
    <p id="phone-camera-status" role="status" aria-live="polite"></p>
  </div>
  <div class="camera-toolbar">
    <button type="button" id="phone-camera-lens" hidden>Rear camera</button>
    <button type="button" id="phone-camera-local" hidden>Preview</button>
    <button type="button" id="phone-camera-start" hidden>Start Sharing</button>
    <button type="button" id="phone-camera-decline" hidden>Decline</button>
    <button type="button" id="phone-camera-stop" hidden>Stop Sharing</button>
  </div>
  <section id="phone-recording" aria-labelledby="phone-record-title" hidden>
    <h3 id="phone-record-title">Recording request</h3>
    <p id="phone-record-status" role="status" aria-live="polite"></p>
    <video id="phone-record-review" controls tabindex="0" playsinline preload="metadata" aria-label="Review your recording" hidden></video>
    <div class="phone-record-actions">
      <button type="button" id="phone-record-start" hidden>Start recording</button>
      <button type="button" id="phone-record-stop" hidden>Stop recording</button>
      <button type="button" id="phone-record-decline" hidden>Decline recording</button>
    </div>
    <div id="phone-record-local" hidden>
      <p>Would you like to save a copy to this phone?</p>
      <a id="phone-record-save" download>Save to device</a>
    </div>
    <div class="phone-record-actions">
      <button type="button" id="phone-record-submit" hidden>Submit to owner</button>
      <button type="button" id="phone-record-redo" hidden>Re-record</button>
    </div>
  </section>
</dialog>
