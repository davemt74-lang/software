<footer>
  <nav class="socials" aria-label="Social links">
    <a href="<?= e(social_link('spotify')) ?>" target="_blank" rel="noopener noreferrer" aria-label="Spotify">
      <svg viewBox="0 0 48 48" fill="none" aria-hidden="true"><circle cx="24" cy="24" r="20" fill="currentColor"/><path d="M14 19.2c7.4-2.1 16.3-1.4 22.5 1.6M15.5 25c6.4-1.7 14.1-1.1 19.4 1.4M17 30.4c5.3-1.3 11.6-.9 16 1.1" stroke="#080705" stroke-width="2.7" stroke-linecap="round"/></svg>
    </a>
    <a href="<?= e(social_link('youtube')) ?>" target="_blank" rel="noopener noreferrer" aria-label="YouTube">
      <svg viewBox="0 0 48 48" fill="none" aria-hidden="true"><rect x="5" y="12" width="38" height="24" rx="6" fill="currentColor"/><path d="m21 18 10 6-10 6V18Z" fill="#080705"/></svg>
    </a>
    <a href="<?= e(social_link('instagram')) ?>" target="_blank" rel="noopener noreferrer" aria-label="Instagram">
      <svg viewBox="0 0 48 48" fill="none" aria-hidden="true"><rect x="8" y="8" width="32" height="32" rx="9" stroke="currentColor" stroke-width="3.5"/><circle cx="24" cy="24" r="7" stroke="currentColor" stroke-width="3.5"/><circle cx="34" cy="14" r="2.2" fill="currentColor"/></svg>
    </a>
    <a href="<?= e(social_link('facebook')) ?>" target="_blank" rel="noopener noreferrer" aria-label="Facebook">
      <svg viewBox="0 0 48 48" fill="currentColor" aria-hidden="true"><path d="M28.5 42V26.2h5.3l.8-6.2h-6.1v-4c0-1.8.5-3 3.1-3h3.3V7.5c-.6-.1-2.6-.3-4.9-.3-4.9 0-8.2 3-8.2 8.4V20h-5.5v6.2h5.5V42h6.7Z"/></svg>
    </a>
    <a href="mailto:<?= e(setting('contact_email', (string)site_config('email', 'stonefellow74@gmail.com'))) ?>" aria-label="Email Stonefellow">
      <svg viewBox="0 0 48 48" fill="none" aria-hidden="true"><rect x="6.5" y="10.5" width="35" height="27" rx="2.5" stroke="currentColor" stroke-width="3"/><path d="m9 14 15 12 15-12" stroke="currentColor" stroke-width="3"/></svg>
    </a>
  </nav>
  <p class="copyright">© <span id="year"></span> Stonefellow. All rights reserved.</p>
</footer>
<script src="<?= e(url('/site.js?v=13')) ?>"></script>
<?php if (($activePage ?? '') === 'artist-profile'): ?><script src="<?= e(url('/artist-profile-v186.js')) ?>"></script><?php endif; ?>
</body>
</html>
