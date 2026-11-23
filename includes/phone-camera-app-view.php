<dialog id="phone-camera" class="phone-camera-app" aria-labelledby="phone-camera-title" hidden>
  <header class="camera-heading">
    <h2 id="phone-camera-title">Camera</h2>
    <span id="phone-camera-destination">Private editor and map</span>
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
</dialog>
