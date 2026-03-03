<?php
// Include this file inside <head> on every page:
// <?php include 'config/pwa_head.php'; ?>
?>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#0f76d8">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="Cargo System">
<link rel="apple-touch-icon" href="assets/icons/icon-192.png">
<script defer src="assets/pwa.js"></script>
<style>
  .site-credit-footer {
    margin-top: 26px;
    border-top: 1px solid var(--border, #2a2d35);
    background:
      linear-gradient(180deg, rgba(255, 255, 255, 0.03), rgba(0, 0, 0, 0.28));
  }
  .site-credit-footer::before {
    content: '';
    display: block;
    height: 2px;
    background: linear-gradient(90deg, transparent, var(--accent, #f0c040), transparent);
    opacity: 0.65;
  }
  .site-credit-inner {
    max-width: 1200px;
    margin: 0 auto;
    padding: 14px 16px;
    display: flex;
    justify-content: center;
  }
  .site-credit-pill {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 7px 12px;
    border: 1px solid var(--border, #2a2d35);
    background: var(--surface2, #1e2128);
    color: var(--muted, #7c8091);
    font: 600 11px/1.4 var(--font, 'Syne', Arial, sans-serif);
    letter-spacing: 0.25px;
  }
  .site-credit-dot {
    width: 7px;
    height: 7px;
    border-radius: 999px;
    background: var(--accent, #f0c040);
    box-shadow: 0 0 0 3px rgba(240, 192, 64, 0.18);
    flex: 0 0 auto;
  }
  .site-credit-link {
    color: var(--accent, #f0c040);
    text-decoration: none;
    font-weight: 700;
  }
  .site-credit-link:hover {
    text-decoration: underline;
  }
  @media (max-width: 640px) {
    .site-credit-inner { padding: 12px; }
    .site-credit-pill { width: 100%; justify-content: center; }
  }
</style>
<script>
  (function () {
    function injectFooterCredit() {
      if (!document.body) return;
      if (document.getElementById('site_credit_footer_jawadahmadcs')) return;
      var footer = document.createElement('footer');
      footer.id = 'site_credit_footer_jawadahmadcs';
      footer.className = 'site-credit-footer';
      footer.innerHTML =
        '<div class="site-credit-inner">' +
          '<div class="site-credit-pill">' +
            '<span class="site-credit-dot" aria-hidden="true"></span>' +
            '<span>Developed by</span>' +
            '<a class="site-credit-link" href="https://jawadahmadcs.com/" target="_blank" rel="noopener">JawadAhmadCS</a>' +
          '</div>' +
        '</div>';
      document.body.appendChild(footer);
    }
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', injectFooterCredit);
    } else {
      injectFooterCredit();
    }
  })();
</script>
