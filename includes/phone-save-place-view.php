<dialog id="phone-save-place-dialog" aria-labelledby="phone-save-place-title">
  <form id="phone-save-place-form">
    <h2 id="phone-save-place-title">Save location</h2>
    <p id="phone-save-place-recipient"></p>
    <label for="phone-save-place-name">Location name</label>
    <input id="phone-save-place-name" name="name" type="text" maxlength="80" placeholder="Studio" required autocomplete="off">
    <p id="phone-save-place-status" role="status" aria-live="polite"></p>
    <button type="submit" id="phone-save-place-submit">Save current location</button>
    <button type="button" id="phone-save-place-close" class="secondary">Cancel</button>
  </form>
</dialog>
